<?php

namespace Mmedia\LaravelChat\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Mmedia\LaravelChat\Contracts\ChatParticipantInterface;

class Chatroom extends \Illuminate\Database\Eloquent\Model
{
    use SoftDeletes, HasUlids;

    protected $fillable = [
        'name',
        'description',
        'metadata',
    ];

    // Cast
    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * The messages exchanged in this chatroom
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<ChatMessage, $this>
     */
    public function messages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    // Each chatroom has one or more ChatParticipants. The ChatParticipant contains a morph to the user or other model
    public function participants(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ChatParticipant::class);
    }

    public function sendMessageAs(ChatParticipantInterface $participant, string $message): ChatMessage
    {
        $chatMessage = new ChatMessage([
            'sender_id' => $participant->getKey(),
            'chatroom_id' => $this->getKey(),
            'message' => $message,
        ]);

        $chatMessage->save();

        return $chatMessage;
    }
}
