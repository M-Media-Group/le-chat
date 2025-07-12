<?php

namespace Mmedia\LaravelChat\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Mmedia\LaravelChat\Models\ChatMessage;

class MessageCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The message that was created.
     *
     * @var ChatMessage
     */
    public $message;

    /**
     * Create a new event instance.
     */
    public function __construct(ChatMessage $message)
    {
        $this->message = $message;
    }

    public function broadcastAs(): string
    {
        return 'message.created';
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel($this->message->chatroom),
        ];
    }
}
