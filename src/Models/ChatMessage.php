<?php

namespace Mmedia\LaravelChat\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

class ChatMessage extends \Illuminate\Database\Eloquent\Model
{
    use HasUlids;

    protected $fillable = [
        'chatroom_id',
        'sender_id',
        'message',
    ];

    public function chat()
    {
        return $this->belongsTo(Chatroom::class);
    }

    public function sender()
    {
        return $this->belongsTo(ChatParticipant::class, 'sender_id');
    }
}
