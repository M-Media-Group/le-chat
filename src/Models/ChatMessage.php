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
                return $query->whereHas(
                    'participants',
                    function ($query) use ($participant) {
                        return $query->ofParticipant($participant);
                    }
                );
            }
        );
    }
}
