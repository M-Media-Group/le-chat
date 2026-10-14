<?php

namespace Mmedia\LeChat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute as CastsAttribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Mmedia\LeChat\Contracts\ChatParticipantInterface;
use Mmedia\LeChat\Events\ParticipantCreated;
use Mmedia\LeChat\Events\ParticipantDeleted;
use Mmedia\LeChat\Events\ParticipantTyping;
use Mmedia\LeChat\Traits\BelongsToChatroom;
use Mmedia\LeChat\Traits\ConnectsToBroadcast;
use Mmedia\LeChat\Traits\IsChatParticipant;

/**
 * @phpstan-type ChatParticipantModel \Illuminate\Database\Eloquent\Model&\Mmedia\LeChat\Contracts\ChatParticipantInterface
 *
 * @implements ChatParticipantInterface<ChatParticipant>
 *
 * @property int $id
 * @property int $chatroom_id
 * @property string $participant_type
 * @property int $participant_id
 * @property string $role
 * @property string|null $reference_id
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $read_at
 * @property-read null|string $can_manage_participants
 * @property-read Collection<int, ChatMessage> $sentMessages
 * @property-read Collection<int, ChatMessage> $messages
 * @property-read Model|null $participatingModel
 * @property-read Chatroom|null $chatroom
 * @property-read Collection<int, ChatParticipant> $chatParticipants
 */
final class ChatParticipant extends Model implements ChatParticipantInterface
{
    use BelongsToChatroom, ConnectsToBroadcast, IsChatParticipant, SoftDeletes;

    /** @var list<string> */
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
    /** @var array<string, string> */
    protected $casts = [
        'read_at' => 'datetime',
    ];

    /** @var array<string, class-string> */
    protected $dispatchesEvents = [
        'created' => ParticipantCreated::class,
        'deleted' => ParticipantDeleted::class,
    ];

    // Always load the participating model
    /** @var list<string> */
    protected $with = ['participatingModel'];

    /**
     * The messages sent by this participant
     *
     * @return HasMany<ChatMessage, $this>
     */
    public function sentMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'sender_id');
    }

    /**
     * All the messages in the chatroom this participant is in
     *
     * @return HasMany<ChatMessage, $this>
     */
    public function messages(): HasMany
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
     * @return MorphTo<Model, $this>
     */
    public function participatingModel(): MorphTo
    {
        return $this->morphTo(
            null,
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
        $id = $participant->getKey();

        if ($participant instanceof ChatParticipant) {
            if ($id) {
                return $query->where('id', $id);
            }

            if ($participant->reference_id) {
                return $query->where('reference_id', $participant->reference_id);
            }

            if ($participant->participant_id !== null && $participant->participant_type !== null) {
                return $query->where('participant_id', $participant->participant_id)
                    ->where('participant_type', $participant->participant_type);
            }
        }

        return $query->where('participant_id', $id)
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

    /**
     * Broadcast that this participant is typing in the chatroom.
     */
    public function startTyping(): void
    {
        ParticipantTyping::dispatch($this, true);
    }

    /**
     * Broadcast that this participant stopped typing in the chatroom.
     */
    public function stopTyping(): void
    {
        ParticipantTyping::dispatch($this, false);
    }

    public function getDisplayName(): ?string
    {
        $model = $this->participatingModel;

        if ($model === null || ! method_exists($model, 'getDisplayName')) {
            return null;
        }

        $displayName = $model->getDisplayName();

        return is_string($displayName) ? $displayName : null;
    }

    public function getAvatarUrl(): ?string
    {
        $model = $this->participatingModel;

        if ($model === null || ! method_exists($model, 'getAvatarUrl')) {
            return null;
        }

        $avatarUrl = $model->getAvatarUrl();

        return is_string($avatarUrl) ? $avatarUrl : null;
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
                Notifiable::class,
                class_uses_recursive($this->participant_type),
                true,
            )
        )->shouldCache();
    }
}
