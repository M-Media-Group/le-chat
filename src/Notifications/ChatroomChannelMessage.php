<?php

namespace Mmedia\LaravelChat\Notifications;

use Illuminate\Contracts\Support\Arrayable;
use Mmedia\LaravelChat\Contracts\ChatParticipantInterface;
use Mmedia\LaravelChat\Models\ChatParticipant;

class ChatroomChannelMessage implements Arrayable
{
    /**
     * The text to be sent in the chatroom message.
     *
     * @var string
     */
    public $message;

    /**
     * The ID of the chatroom where the message will be sent.
     *
     * @var int
     */
    public $chatroom_id;

    /**
     * The ID of the sender of the message.
     *
     * @var int|null
     */
    public $sender_id;

    /**
     * Set the message text for the chatroom.
     */
    public function message(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    /**
     * Set the chatroom ID for the message.
     */
    public function chatroomId(int $chatroomId): self
    {
        $this->chatroom_id = $chatroomId;

        return $this;
    }

    public function sender(ChatParticipant|ChatParticipantInterface $participant): self
    {
        if ($participant instanceof ChatParticipantInterface) {
            $this->sender_id = $participant->getKey();
        } elseif ($participant instanceof ChatParticipant) {
            $this->sender_id = $participant->id;
        } else {
            throw new \InvalidArgumentException('Invalid participant type.');
        }

        return $this;
    }

    /**
     * Convert the instance to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'chatroom_id' => $this->chatroom_id,
            'message' => $this->message,
        ];
    }
}
