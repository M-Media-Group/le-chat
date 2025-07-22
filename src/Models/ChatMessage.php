<?php

namespace Mmedia\LeChat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Mmedia\LeChat\Contracts\ChatParticipantInterface;
use Mmedia\LeChat\Traits\BelongsToChatroom;

final class ChatMessage extends \Illuminate\Database\Eloquent\Model
{
    use BelongsToChatroom, SoftDeletes;

    protected $fillable = [
        'chatroom_id',
        'sender_id',
        'message',
        'reply_to_id',
    ];

    // Events
    protected $dispatchesEvents = [
        'created' => \Mmedia\LeChat\Events\MessageCreated::class,
    ];

    /**
     * The sender of this message
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<ChatParticipant, $this>
     */
    public function sender(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ChatParticipant::class, 'sender_id');
    }

    /**
     * Mark the message as read by a participant.
     */
    public function markAsReadBy(ChatParticipantInterface|ChatParticipant $participant): bool
    {
        // Mark the message as read by the participant
        return $participant->markRead($this);
    }

    /**
     * Scope where can be read by a given participant - e.g. the message was posted after the participant joined the chatroom.
     *
     * @param  Builder<ChatMessage>  $query
     * @return Builder<ChatMessage>
     */
    public function scopeSentBy(Builder $query, ChatParticipantInterface|ChatParticipant $participant): Builder
    {
        return $query->whereHas(
            'sender',
            function ($query) use ($participant) {
                return $query->ofParticipant($participant);
            }
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
     * @param  \Illuminate\Database\Eloquent\Builder<ChatMessage>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ChatMessage>
     */
    public function scopeAfterParticipantJoined(
        Builder $query,
        ChatParticipantInterface|ChatParticipant $participant
    ): Builder {
        $instance = new ChatParticipant;
        $selfInstance = new self;

        return $query->when(($participant instanceof ChatParticipant),
            function ($query) use ($participant, $selfInstance) {
                $query->where($selfInstance->getQualifiedCreatedAtColumn(), '>=', $participant->getCreatedAtColumn());
            },
            // If we have a ChatParticipantInterface, we need to do this dynamically - because we need to join/match on the chatroom_id column in the ChatMessage
            function ($query) use ($participant, $selfInstance, $instance) {
                $query->where($selfInstance->getQualifiedCreatedAtColumn(), '>=', function ($query) use ($participant, $instance, $selfInstance) {
                    $query->select($instance->getQualifiedCreatedAtColumn())
                        ->from($instance->getTable())
                        ->whereColumn('chatroom_id', $selfInstance->qualifyColumn('chatroom_id'))
                        ->where('participant_id', $participant->getKey())
                        ->where('participant_type', $participant->getMorphClass());
                });
            }
        );
    }

    /**
     * Scope where messages are before the participant was deleted.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ChatMessage>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ChatMessage>
     */
    public function scopeBeforeParticipantDeleted(
        $query,
        ChatParticipantInterface|ChatParticipant $participant
    ) {
        $instance = new ChatParticipant;
        $selfInstance = new self;

        return $query
            ->when(($participant instanceof ChatParticipant),
                function ($query) use ($participant, $selfInstance) {

                    // Needs to be a where group to support the case where the participant is not deleted
                    $query->where(function ($query) use ($participant, $selfInstance) {
                        $query->where($selfInstance->getQualifiedCreatedAtColumn(), '<=', $participant->getDeletedAtColumn())
                            ->orWhereNull($participant->getDeletedAtColumn());
                    });
                },
                // If we have a ChatParticipantInterface, we need to do this dynamically - because we need to join/match on the chatroom_id column in the ChatMessage
                function ($query) use ($participant, $selfInstance, $instance) {
                    $query->where(function ($query) use ($participant, $selfInstance, $instance) {
                        $query->where($selfInstance->getQualifiedCreatedAtColumn(), '<=', function ($query) use ($participant, $instance, $selfInstance) {
                            $query->select($instance->getQualifiedDeletedAtColumn())
                                ->from($instance->getTable())
                                ->whereColumn('chatroom_id', $selfInstance->qualifyColumn('chatroom_id'))
                                ->where('participant_id', $participant->getKey())
                                ->where('participant_type', $participant->getMorphClass());
                        })->orWhereRaw('(
                            SELECT '.$instance->getQualifiedDeletedAtColumn().'
                            FROM '.$instance->getTable().'
                            WHERE '.$instance->getTable().'.chatroom_id = '.$selfInstance->qualifyColumn('chatroom_id').'
                              AND participant_id = ?
                              AND participant_type = ?
                        ) IS NULL', [$participant->getKey(), $participant->getMorphClass()]);
                    });
                }
            );
    }

    /**
     * Returns all messages created before the given participants read_at timestamp.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ChatMessage>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ChatMessage>
     */
    public function scopeUnreadBy(
        $query,
        ChatParticipantInterface|ChatParticipant $participant
    ) {
        $instance = new ChatParticipant;
        $selfInstance = new self;

        return $query
            ->when(($participant instanceof ChatParticipant),
                function ($query) use ($participant, $selfInstance) {

                    // Needs to be a where group to support the case where the participant is not deleted
                    $query->where(function ($query) use ($participant, $selfInstance) {
                        $query->where($selfInstance->getQualifiedCreatedAtColumn(), '<=', $participant->qualifyColumn('read_at'))
                            ->orWhereNull($participant->qualifyColumn('read_at'));
                    });
                },
                // If we have a ChatParticipantInterface, we need to do this dynamically - because we need to join/match on the chatroom_id column in the ChatMessage
                function ($query) use ($participant, $selfInstance, $instance) {
                    $query->where(function ($query) use ($participant, $selfInstance, $instance) {
                        $query->where($selfInstance->getQualifiedCreatedAtColumn(), '>', function ($query) use ($participant, $instance, $selfInstance) {
                            $query->select($instance->qualifyColumn('read_at'))
                                ->from($instance->getTable())
                                ->whereColumn('chatroom_id', $selfInstance->qualifyColumn('chatroom_id'))
                                ->where('participant_id', $participant->getKey())
                                ->where('participant_type', $participant->getMorphClass());
                        })->orWhereRaw('(
                            SELECT '.$instance->qualifyColumn('read_at').'
                            FROM '.$instance->getTable().'
                            WHERE '.$instance->getTable().'.chatroom_id = '.$selfInstance->qualifyColumn('chatroom_id').'
                              AND participant_id = ?
                              AND participant_type = ?
                        ) IS NULL', [$participant->getKey(), $participant->getMorphClass()]);
                    });
                }
            );
    }
}
