<?php

namespace Mmedia\LaravelChat\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Broadcast;
use Mmedia\LaravelChat\Http\Resources\ChatParticipantResource;
use Mmedia\LaravelChat\Models\ChatParticipant;

class ParticipantCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The participant that was created.
     *
     * @var ChatParticipant
     */
    public $participant;

    /**
     * Create a new event instance.
     */
    public function __construct(ChatParticipant $participant)
    {
        $this->participant = $participant;
        if (Broadcast::socket()) {
            $this->dontBroadcastToCurrentUser();
        }
    }

    /**
     * Get what to broadcast
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {

        return ChatParticipantResource::make($this->participant)->resolve();
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel($this->participant->chatroom->broadcastChannel()),
        ];
    }
}
