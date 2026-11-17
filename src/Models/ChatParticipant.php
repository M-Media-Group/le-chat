<?php

namespace Mmedia\LeChat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute as CastsAttribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Mmedia\LeChat\Contracts\ChatParticipantInterface;
use Mmedia\LeChat\Events\ParticipantCreated;
use Mmedia\LeChat\Events\ParticipantDeleted;
use Mmedia\LeChat\Events\ParticipantTyping;
use Mmedia\LeChat\Features;
use Mmedia\LeChat\Support\MembershipMessages;
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
 * @property string|null $participant_type
 * @property int|null $participant_id
 * @property string $role
 * @property string|null $reference_id
 * @property string|null $avatar_url
 * @property array<string, mixed>|null $metadata
 * @property bool $can_see_messages_before_joined
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

    private const AI_RESPONSE_MODES = ['all', 'mentions', 'off'];

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
        'metadata',
        'can_see_messages_before_joined',

        'read_at',
    ];

    // Casts
    /** @var array<string, string> */
    protected $casts = [
        'read_at' => 'datetime',
        'metadata' => 'array',
        'can_see_messages_before_joined' => 'boolean',
    ];

    /** @var array<string, class-string> */
    protected $dispatchesEvents = [
        'created' => ParticipantCreated::class,
        'restored' => ParticipantCreated::class,
        'deleted' => ParticipantDeleted::class,
    ];

    // Always load the participating model
    /** @var list<string> */
    protected $with = ['participatingModel'];

    protected static function booted(): void
    {
        self::creating(function (ChatParticipant $participant): void {
            $participant->can_see_messages_before_joined ??= (bool) Chatroom::on($participant->getConnectionName())->withTrashed()
                ->whereKey($participant->chatroom_id)->value('can_see_messages_before_joined');
        });

        self::deleting(function (ChatParticipant $participant): void {
            if ($participant->isForceDeleting() && Features::enabled(Features::files())) {
                $participant->sentMessages()->whereHas('files')->chunkById(100, function ($messages): void {
                    foreach ($messages as $message) {
                        $message->forceDelete();
                    }
                });
            }
        });
    }

    public function mentionToken(): string
    {
        return '<@'.$this->getKey().'>';
    }

    public function setCanSeeMessagesBeforeJoined(bool $value): void
    {
        $this->update(['can_see_messages_before_joined' => $value]);
    }

    public function isAgent(): bool
    {
        return str_starts_with((string) $this->reference_id, 'ai-agent:');
    }

    /** @return 'all'|'mentions'|'off' */
    public function aiResponseMode(): string
    {
        if (! $this->exists || $this->trashed() || ! $this->isAgent()) {
            return 'off';
        }

        $mode = $this->metadata['ai_response_mode'] ?? 'all';

        return in_array($mode, self::AI_RESPONSE_MODES, true) ? $mode : 'off';
    }

    public function setAiResponseMode(string $mode): void
    {
        if (! in_array($mode, self::AI_RESPONSE_MODES, true)) {
            throw ValidationException::withMessages(['ai_response_mode' => 'Choose all, mentions, or off.']);
        }
        if (! $this->isAgent() || ! self::query()->whereKey($this->getKey())->exists()) {
            throw ValidationException::withMessages(['participant' => 'The agent participant must be active.']);
        }

        $this->refresh();
        $this->update(['metadata' => array_replace($this->metadata ?? [], ['ai_response_mode' => $mode])]);
    }

    /** @return list<string> */
    public function mentionAliases(): array
    {
        $aliases = $this->metadata['mention_aliases'] ?? [];

        return is_array($aliases) ? array_values(array_unique(array_map('strtolower', array_filter(
            $aliases, fn (mixed $alias): bool => is_string($alias) && preg_match('/^[a-z][a-z0-9_-]{0,31}$/iD', $alias) === 1,
        )))) : [];
    }

    /** @param list<mixed> $aliases Values are validated before being stored as normalized aliases. */
    public function setMentionAliases(array $aliases): void
    {
        foreach ($aliases as $alias) {
            if (! is_string($alias) || preg_match('/^[a-z][a-z0-9_-]{0,31}$/iD', $alias) !== 1) {
                throw ValidationException::withMessages(['mention_aliases' => 'Mention aliases must start with a letter and contain at most 32 letters, digits, underscores, or hyphens.']);
            }
        }
        $aliases = array_values(array_unique(array_map('strtolower', $aliases)));

        $this->getConnection()->transaction(function () use ($aliases): void {
            $room = Chatroom::query()->lockForUpdate()->findOrFail($this->chatroom_id);
            if (! $room->hasParticipant($this)) {
                throw ValidationException::withMessages(['participant' => 'The participant must be active in this chatroom.']);
            }
            foreach ($room->participants()->where('id', '!=', $this->getKey())->get() as $other) {
                if (array_intersect($aliases, $other->mentionAliases()) !== []) {
                    throw ValidationException::withMessages(['mention_aliases' => 'Mention aliases must be unique within the chatroom.']);
                }
            }
            $this->refresh();
            $this->update(['metadata' => array_replace($this->metadata ?? [], ['mention_aliases' => $aliases])]);
        });
    }

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
     * @return HasManyThrough<ChatMessage, ChatParticipant, $this>
     */
    public function messages(): HasManyThrough
    {
        $chatMessageInstance = (new ChatMessage)->setConnection($this->getConnectionName());
        $throughParticipant = (new self)->setConnection($this->getConnectionName());
        $relation = new MembershipMessages($chatMessageInstance->newQuery(), $this, $throughParticipant, 'id', 'chatroom_id', 'id', 'chatroom_id');

        // Existence queries alias the through row and correlate its id to the outer participant.
        // Participant-qualified columns intentionally reference that outer row; this relies on
        // the through parent being the same membership, not another participant in the room.
        $relation->getQuery()
            ->where(function (Builder $query) use ($chatMessageInstance): void {
                $query->where($this->qualifyColumn('can_see_messages_before_joined'), true)
                    ->orWhereColumn($chatMessageInstance->getQualifiedCreatedAtColumn(), '>=', $this->getQualifiedCreatedAtColumn());
            })
            ->where(function (Builder $query) use ($chatMessageInstance): void {
                $query->whereNull($this->getQualifiedDeletedAtColumn())
                    ->orWhereColumn(
                        $chatMessageInstance->getQualifiedCreatedAtColumn(),
                        '<=',
                        $this->getQualifiedDeletedAtColumn()
                    );
            });

        return $relation;
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

    /**
     * Send a message as this participant in the chatroom.
     */
    public function sendMessage(string $message, array $messageConfiguration = []): ChatMessage
    {
        return $this->sendMessageTo($this->chatroom, $message, messageConfiguration: $messageConfiguration);
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
        ChatParticipantInterface $participant
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
            get: fn (?string $value): ?string => $value ?? $this->getDisplayName()
        );
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
        );
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
