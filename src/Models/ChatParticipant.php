<?php

namespace Mmedia\LaravelChat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute as CastsAttribute;
use Illuminate\Database\Eloquent\SoftDeletes;
use Mmedia\LaravelChat\Contracts\ChatParticipantInterface;
use Mmedia\LaravelChat\Traits\ConnectsToBroadcast;
use Mmedia\LaravelChat\Traits\IsChatParticipant;

class ChatParticipant extends \Illuminate\Database\Eloquent\Model implements ChatParticipantInterface
{
    use ConnectsToBroadcast, IsChatParticipant, SoftDeletes;

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
        'created' => \Mmedia\LaravelChat\Events\ParticipantCreated::class,
        'deleted' => \Mmedia\LaravelChat\Events\ParticipantDeleted::class,
    ];

    /**
     * The chatroom this participant is in
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Chatroom, $this>
     */
    public function chatroom(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Chatroom::class, 'chatroom_id');
    }

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
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<\Illuminate\Database\Eloquent\Model, $this>
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

    public function scopeInRoom(Builder $query, Chatroom $chatroom): Builder
    {
        return $query->where('chatroom_id', $chatroom->getKey());
    }

    protected function isConnected(): CastsAttribute
    {
        return CastsAttribute::make(
            get: fn () => $this->getIsConnectedViaSockets(
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

    protected function displayName(): CastsAttribute
    {
        return CastsAttribute::make(
            get: fn () => $this->getRawOriginal('display_name') ?? $this->getDisplayName()
        )->shouldCache();
    }

    protected function avatarUrl(): CastsAttribute
    {
        return CastsAttribute::make(
            get: fn () => $this->getRawOriginal('avatar_url') ?? $this->getAvatarUrl()
        )->shouldCache();
    }

    protected function canManageParticipants(): CastsAttribute
    {
        return CastsAttribute::make(
            get: fn () => $this->role === 'admin'
        )->shouldCache();
    }

    /**
     * A participant is notifiable if the participant_type class uses the Notifiable trait.
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
