<?php

namespace Mmedia\LaravelChat\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Mmedia\LaravelChat\Events\ParticipantDeleted;

class SendParticipantDeletedNotification implements ShouldQueue
{
    /**
     * Create the event listener.
     */
    public function __construct() {}

    /**
     * Handle the event.
     */
    public function handle(ParticipantDeleted $event): void
    {

        // For each participant in the chatroom, send a notification
        $chatroom = $event->participant->chatroom;

        $chatroom->sendMessage("{$event->participant->display_name} has left the chat.");
    }
}
