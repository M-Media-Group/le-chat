<?php

namespace Mmedia\LeChat\Listeners;

use Mmedia\LeChat\Events\ParticipantDeleted;
use Mmedia\LeChat\Support\JoinAndLeaveMessages;

/**
 * Posts "Ana has left the chat.", grouped with others who leave close together.
 *
 * Runs straight away rather than queued, and dates the message at the moment they left, so the person
 * who left can still see it.
 */
class SendParticipantDeletedNotification
{
    public function handle(ParticipantDeleted $event): void
    {
        if (config('chat.create_system_messages', true)) {
            JoinAndLeaveMessages::left($event->participant);
        }
    }
}
