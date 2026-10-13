<?php

namespace Mmedia\LeChat\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Broadcast;
use Mmedia\LeChat\Http\Resources\MessageResource;
use Mmedia\LeChat\Models\ChatMessage;

abstract class BaseMessageEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The message associated with this event.
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
        if (Broadcast::socket()) {
            $this->dontBroadcastToCurrentUser();
        }
    }

    /**
     * Get the data to broadcast for this event.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return MessageResource::make($this->message)->resolve();
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel($this->message->chatroom->broadcastChannel()),
        ];
    }
}
