<?php

namespace Mmedia\LeChat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute as CastsAttribute;
use Illuminate\Database\Eloquent\SoftDeletes;
use Mmedia\LeChat\Contracts\ChatParticipantInterface;
use Mmedia\LeChat\Traits\BelongsToChatroom;
use Mmedia\LeChat\Traits\ConnectsToBroadcast;
use Mmedia\LeChat\Traits\IsChatParticipant;

/**
 * @phpstan-type ChatParticipantModel \Illuminate\Database\Eloquent\Model&\Mmedia\LeChat\Contracts\ChatParticipantInterface
 */
final class ChatParticipant extends \Illuminate\Database\Eloquent\Model implements ChatParticipantInterface
{
    use BelongsToChatroom, ConnectsToBroadcast, IsChatParticipant, SoftDeletes;

    protected $fillable = [
        'chatroom_id',
        'participant_id',
        'participant_type',
        'role',

        // The participant may be non-related (e,g. a bot or external user), so we need to store a display name and a reference ID (could be a remote ID or a unique key)

        'display_name',
        'avatar_url',
        'reference_id',

        'read_at',
    ];

    // Casts
    protected $casts = [
        'read_at' => 'datetime',
    ];

    protected $dispatchesEvents = [
        'created' => \Mmedia\LeChat\Events\ParticipantCreated::class,
        'deleted' => \Mmedia\LeChat\Events\ParticipantDeleted::class,
    ];

    // Always load the participating model
    protected $with = ['participatingModel'];

    /**
     * The messages sent by this participant
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<ChatMessage, $this>
     */
    public function sentMessages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ChatMessage::class, 'sender_id');
    }

    /**
     * All the messages in the chatroom this participant is in
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<ChatMessage, $this>
     */
    public function messages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        $chatMessageInstance = new ChatMessage;

        return $this->hasMany(ChatMessage::class, 'chatroom_id', 'chatroom_id')
            // where created_at is after the time this participant was created at
            ->whereColumn(
                $chatMessageInstance->getQualifiedCreatedAtColumn(),
                '>=',
                'created_at'
            )
            // And where deleted_at is null or created_at is before deleted_at
            ->where(function ($query) use ($chatMessageInstance) {
                $query->whereNull('deleted_at')
                    ->orWhereColumn(
                        $chatMessageInstance->getQualifiedCreatedAtColumn(),
                        '<',
                        'deleted_at'
                    );
            });
    }

    /**
     * The participant model (the user or other model this participant represents)
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<ChatParticipantModel, $this>
     */
    public function participatingModel(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo(
            'participant',
            'participant_type',
            'participant_id',
        );
    }

    /**
     * Note this function may return unexpected results if you pass an instance of ChatParticipantInterface, because it will filter to the participant_id and participant_type regardless of the chatroom_id, so you can get multiple results.
     *
     * @internal
     *
     * @param  Builder<ChatParticipant>  $query
     * @return Builder<ChatParticipant>
     */
    public function scopeOfParticipant(
        Builder $query,
        ChatParticipantInterface|ChatParticipant $participant
    ): Builder {
        if ($participant instanceof ChatParticipant) {
            return $query->where('id', $participant->getKey());
        }

        return $query->where('participant_id', $participant->getKey())
            ->where('participant_type', $participant->getMorphClass());
    }

    /**
     * Check if the participant is connected to the chatroom via sockets.
     *
     * @return CastsAttribute<bool, never>
     */
    protected function isConnected(): CastsAttribute
    {
        return CastsAttribute::make(
            get: fn () => $this->isConnectedViaSockets(
                localId: 'participant_id',
                channelName: $this->chatroom->broadcastChannel(),
                type: 'presence'
            )
        )->shouldCache();
    }

    public function getDisplayName(): ?string
    {
        return $this->participatingModel?->getDisplayName();
    }

    public function getAvatarUrl(): ?string
    {
        return $this->participatingModel?->getAvatarUrl();
    }

    /**
     * Get the display name of the participant, falling back to the model's display name if not set.
     *
     * @return CastsAttribute<null|string, never>
     */
    protected function displayName(): CastsAttribute
    {
        return CastsAttribute::make(
            get: fn () => $this->getRawOriginal('display_name') ?? $this->getDisplayName()
        )->shouldCache();
    }

    /**
     * Get the avatar URL of the participant, falling back to the model's avatar URL if not set.
     *
     * @return CastsAttribute<null|string, never>
     */
    protected function avatarUrl(): CastsAttribute
    {
        return CastsAttribute::make(
            get: fn () => $this->getRawOriginal('avatar_url') ?? $this->getAvatarUrl()
        )->shouldCache();
    }

    /**
     * Get the reference ID of the participant, which can be used to identify the participant in external systems.
     *
     * @return CastsAttribute<null|string, never>
     */
    protected function canManageParticipants(): CastsAttribute
    {
        return CastsAttribute::make(
            get: fn () => $this->role === 'admin'
        )->shouldCache();
    }

    /**
     * A participant is notifiable if the participant_type class uses the Notifiable trait.
     *
     * @return CastsAttribute<bool, never>
     */
    protected function isNotifiable(): CastsAttribute
    {
        return CastsAttribute::make(
            get: fn () => $this->participant_type && in_array(
                \Illuminate\Notifications\Notifiable::class,
                class_uses_recursive($this->participant_type)
            )
        )->shouldCache();
    }
}
