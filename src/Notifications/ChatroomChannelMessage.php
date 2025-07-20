<?php

namespace Mmedia\LaravelChat\Notifications;

use Illuminate\Contracts\Support\Arrayable;
use Mmedia\LaravelChat\Contracts\ChatParticipantInterface;
use Mmedia\LaravelChat\Models\ChatParticipant;
use Mmedia\LaravelChat\Models\Chatroom;

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
     * @var Chatroom|null
     */
    public $chatroom;

    /**
     * The ID of the sender of the message.
     *
     * @var ChatParticipant|null
     */
    public $sender;

    /**
     * Additional attributes that should be force-filled when creating the message.
     *
     * @var array<string, mixed>
     */
    public $attributes = [];

    /**
     * Set the message text for the chatroom.
     */
    public function message(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    /**
     * Set additional attributes for the message.
     */
    public function attributes(array $attributes): self
    {
        $this->attributes = $attributes;

        return $this;
    }

    public function chatroom(Chatroom $chatroom): self
    {
        $this->chatroom = $chatroom;

        return $this;
    }

    public function sender(ChatParticipant|ChatParticipantInterface $participant): self
    {
        if ($participant instanceof ChatParticipantInterface) {
            $this->sender = $participant->asParticipantIn($this->chatroom);
        } elseif ($participant instanceof ChatParticipant) {
            $this->sender = $participant;
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
            'sender_id' => $this->sender?->getKey(),
            'chatroom_id' => $this->chatroom?->getKey() ?? $this->sender?->chatroom->getKey(),
            'message' => $this->message,
            'attributes' => $this->attributes,
        ];
    }
}
