<?php

namespace Mmedia\LeChat\Listeners;

use Mmedia\LeChat\Events\ParticipantCreated;
use Mmedia\LeChat\Support\JoinAndLeaveMessages;

/**
 * Posts "Ana has joined the chat.", grouped with others who join close together.
 */
class SendParticipantCreatedNotification
{
    public function handle(ParticipantCreated $event): void
    {
        if (config('chat.create_system_messages', true)) {
            JoinAndLeaveMessages::joined($event->participant);
        }
    }
}
