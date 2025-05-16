<?php

namespace Mmedia\LaravelChat\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Mmedia\LaravelChat\Contracts\ChatParticipantInterface;

class ChatMessage extends \Illuminate\Database\Eloquent\Model
{
    use SoftDeletes;

    protected $fillable = [
        'chatroom_id',
        'sender_id',
        'message',
        'reply_to_id',
    ];

    public function chatroom()
    {
        return $this->belongsTo(Chatroom::class);
    }

    /**
     * The sender of this message
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<ChatParticipant, $this>
     */
    public function sender(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ChatParticipant::class, 'sender_id');
    }

    public function scopeSentByParticipant($query, ChatParticipantInterface|ChatParticipant $participant)
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
    public function scopeCanBeReadByParticipant(
        $query,
        ChatParticipantInterface|ChatParticipant $participant,
        bool $includeMessagesBeforeParticipantJoined = false
    ) {
        return $query->whereHas(
            'chatroom',
            function ($query) use ($participant) {
                return $query->havingParticipants([$participant]);
            }
        )
            ->when(
                ! $includeMessagesBeforeParticipantJoined,
                function ($query) use ($participant) {
                    return $query->afterParticipantJoined($participant);
                }
            );
    }

    public function scopeAfterParticipantJoined(
        $query,
        ChatParticipantInterface|ChatParticipant $participant
    ) {
        return $query->when(($participant instanceof ChatParticipant),
            function ($query) use ($participant) {
                $query->where(self::CREATED_AT, '>=', $participant->created_at);
            },
            // If we have a ChatParticipantInterface, we need to do this dynamically - because we need to join/match on the chatroom_id column in the ChatMessage
            function ($query) use ($participant) {
                $query->where(self::CREATED_AT, '>=', function ($query) use ($participant) {
                    $query->select(self::CREATED_AT)
                        ->from('chat_participants')
                        ->whereColumn('chatroom_id', 'chat_messages.chatroom_id')
                        ->where('participant_id', $participant->getKey())
                        ->where('participant_type', $participant->getMorphClass());
                });
            }
        );
    }
}
