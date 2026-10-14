<?php

namespace Mmedia\LeChat\Events;

use Mmedia\LeChat\Http\Resources\ChatParticipantResource;
use Mmedia\LeChat\Models\ChatParticipant;

/**
 * A participant started or stopped typing in a chatroom. Nothing is stored; it is only broadcast.
 */
class ParticipantTyping extends BaseParticipantEvent
{
    public function __construct(ChatParticipant $participant, public bool $isTyping = true)
    {
        parent::__construct($participant);
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            ...ChatParticipantResource::make($this->participant)->resolve(),
            'is_typing' => $this->isTyping,
        ];
    }
}
