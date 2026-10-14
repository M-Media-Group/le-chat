<?php

namespace Mmedia\LeChat\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\EncryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute as CastsAttribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Mmedia\LeChat\Contracts\ChatParticipantInterface;
use Mmedia\LeChat\Events\MessageCreated;
use Mmedia\LeChat\Events\MessageUpdated;
use Mmedia\LeChat\Features;
use Mmedia\LeChat\Traits\BelongsToChatroom;
use Mmedia\LeChat\Traits\OverwriteDeletes;

/**
 * @property int $id
 * @property int|null $sender_id
 * @property int $chatroom_id
 * @property int|null $reply_to_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ChatParticipant|null $sender
 * @property-read Collection<int, ChatMessage> $replies
 * @property-read ChatMessage|null $parentMessage
 * @property-read Chatroom|null $chatroom
 */
final class ChatMessage extends Model
{
    use BelongsToChatroom, OverwriteDeletes, SoftDeletes {
        OverwriteDeletes::runSoftDelete insteadof SoftDeletes;
        OverwriteDeletes::restore insteadof SoftDeletes;
    }

    /** @var list<string> */
    protected $fillable = [
        'chatroom_id',
        'sender_id',
        'message',
        'reply_to_id',
        'metadata',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * The attributes that will be set, and to what they will be set to, on soft delete.
     *
     * @var array<string, mixed>
     */
    protected $deletable = [
        'message' => null,
    ];

    // Events
    /** @var array<string, class-string> */
    protected $dispatchesEvents = [
        'created' => MessageCreated::class,
        'updated' => MessageUpdated::class,
    ];

    /**
     * Removes the global scope for soft deletes.
     *
     * @return void
     */
    public static function bootSoftDeletes() {}

    /**
     * The sender of this message
     *
     * @return BelongsTo<ChatParticipant, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(ChatParticipant::class, 'sender_id');
    }

    /**
     * The direct, 1-level deep replies to this message.
     *
     * @return HasMany<ChatMessage, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'reply_to_id');
    }

    /**
     * The message that the current message is replying to
     *
     * @return BelongsTo<ChatMessage, $this>
     */
    public function parentMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'reply_to_id');
    }

    /**
     * Mark the message as read by a participant.
     */
    public function markAsReadBy(ChatParticipantInterface|ChatParticipant $participant): bool
    {
        // Mark the message as read by the participant
        return $participant->markRead($this);
    }

    public function replyAs(ChatParticipantInterface|ChatParticipant $participant, string $content, array $messageConfiguration = []): ChatMessage
    {
        return $participant->replyTo($this, $content, $messageConfiguration);
    }

    /**
     * Get the message attribute, decrypting it if encryption is enabled.
     *
     * @return CastsAttribute<string, string|null>
     *
     * @throws DecryptException
     * @throws EncryptException
     */
    protected function message(): CastsAttribute
    {
        $usesEncryption = Features::enabled(Features::encryptMessagesAtRest());

        /** Try to encrypt but catch DecryptException */
        $decryptSoftly = function ($value) use ($usesEncryption) {
            if ($usesEncryption) {
                try {
                    return decrypt($value);
                } catch (DecryptException $e) {
                    $allowsSoftDecryption = Features::optionEnabled(Features::encryptMessagesAtRest(), 'return_failed_decrypt');

                    // If decryption fails, return the original value
                    return $allowsSoftDecryption ? $value : throw $e;
                }
            }

            return $value;
        };

        return CastsAttribute::make(
            get: fn (?string $value): string => $value === null ? '' : ($usesEncryption ? $decryptSoftly($value) : $value),
            set: fn (?string $value): ?string => $usesEncryption && $value !== null ? encrypt($value) : $value,
        )->shouldCache();
    }

    /**
     * Scope where can be read by a given participant - e.g. the message was posted after the participant joined the chatroom.
     *
     * @param  Builder<ChatMessage>  $query
     * @return Builder<ChatMessage>
     */
    public function scopeSentBy(Builder $query, ChatParticipantInterface|ChatParticipant $participant): Builder
    {
        return $query->whereIn(
            $query->getModel()->qualifyColumn('sender_id'),
            ChatParticipant::query()->ofParticipant($participant)->select('id')
        );
    }

    /**
     * Scope where can be read by a given participant - e.g. the message was posted after the participant joined the chatroom.
     */
    public function scopeVisibleTo(
        $query,
        ChatParticipantInterface|ChatParticipant $participant,
        bool $includeLeftChatrooms = true,
        bool $includeBeforeJoined = false
    ) {
        return $query->whereHas(
            'chatroom',
            function ($query) use ($participant, $includeLeftChatrooms) {
                return $query->havingParticipants([$participant], $includeLeftChatrooms);
            }
        )
            ->beforeParticipantDeleted($participant)
            ->when(
                ! $includeBeforeJoined,
                function ($query) use ($participant) {
                    return $query->afterParticipantJoined($participant);
                }
            );
    }

    /**
     * Scope where messages are after the participant joined the chatroom.
     *
     * @internal
     *
     * @param  Builder<ChatMessage>  $query
     * @return Builder<ChatMessage>
     */
    public function scopeAfterParticipantJoined(
        Builder $query,
        ChatParticipantInterface|ChatParticipant $participant
    ): Builder {
        $instance = new ChatParticipant;
        $selfInstance = new self;

        if ($participant instanceof ChatParticipant) {
            return $query->where(
                $selfInstance->getQualifiedCreatedAtColumn(),
                '>=',
                $participant->getAttribute($participant->getCreatedAtColumn())
            );
        }

        return $query->where($selfInstance->getQualifiedCreatedAtColumn(), '>=', function ($query) use ($participant, $instance) {
            $this->selectParticipantColumn($query, $instance->getQualifiedCreatedAtColumn(), $participant);
        });
    }

    /**
     * Scope where messages are before the participant was deleted.
     *
     * @param  Builder<ChatMessage>  $query
     * @return Builder<ChatMessage>
     */
    public function scopeBeforeParticipantDeleted(
        $query,
        ChatParticipantInterface|ChatParticipant $participant
    ) {
        $instance = new ChatParticipant;
        $selfInstance = new self;

        if ($participant instanceof ChatParticipant) {
            $deletedAt = $participant->getAttribute($participant->getDeletedAtColumn());

            return $query->when(
                $deletedAt !== null,
                fn ($query) => $query->where($selfInstance->getQualifiedCreatedAtColumn(), '<=', $deletedAt)
            );
        }

        return $query->where(function ($query) use ($participant, $selfInstance, $instance) {
            $query->where($selfInstance->getQualifiedCreatedAtColumn(), '<=', function ($query) use ($participant, $instance) {
                $this->selectParticipantColumn($query, $instance->getQualifiedDeletedAtColumn(), $participant);
            })->orWhereRaw(...$this->participantColumnIsNullSql($instance->getQualifiedDeletedAtColumn(), $participant));
        });
    }

    /**
     * Returns messages created after the given participant's read timestamp.
     *
     * @param  Builder<ChatMessage>  $query
     * @return Builder<ChatMessage>
     */
    public function scopeUnreadBy(
        Builder $query,
        ChatParticipantInterface|ChatParticipant $participant
    ): Builder {
        $instance = new ChatParticipant;
        $selfInstance = new self;

        if ($participant instanceof ChatParticipant) {
            return $participant->read_at === null
                ? $query
                : $query->where($selfInstance->getQualifiedCreatedAtColumn(), '>', $participant->read_at);
        }

        return $query->where(function ($query) use ($participant, $selfInstance, $instance) {
            $query->where($selfInstance->getQualifiedCreatedAtColumn(), '>', function ($query) use ($participant, $instance) {
                $this->selectParticipantColumn($query, $instance->qualifyColumn('read_at'), $participant);
            })->orWhereRaw(...$this->participantColumnIsNullSql($instance->qualifyColumn('read_at'), $participant));
        });
    }

    /**
     * Select a column of the participant row belonging to this message's chatroom.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    private function selectParticipantColumn($query, string $column, ChatParticipantInterface $participant): void
    {
        $instance = new ChatParticipant;

        $query->select($column)
            ->from($instance->getTable())
            ->whereColumn('chatroom_id', $this->qualifyColumn('chatroom_id'))
            ->where('participant_id', $participant->getKey())
            ->where('participant_type', $participant->getMorphClass());
    }

    /**
     * SQL and bindings checking that the participant's column is NULL.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function participantColumnIsNullSql(string $column, ChatParticipantInterface $participant): array
    {
        $instance = new ChatParticipant;

        return ['(
                SELECT '.$column.'
                FROM '.$instance->getTable().'
                WHERE '.$instance->getTable().'.chatroom_id = '.$this->qualifyColumn('chatroom_id').'
                  AND participant_id = ?
                  AND participant_type = ?
            ) IS NULL', [$participant->getKey(), $participant->getMorphClass()]];
    }
}
