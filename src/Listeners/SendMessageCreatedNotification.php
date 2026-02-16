<?php

namespace Mmedia\LeChat\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Mmedia\LeChat\Events\MessageCreated;
use Mmedia\LeChat\Notifications\NewMessage;

class SendMessageCreatedNotification implements ShouldQueue
{
    /**
     * Create the event listener.
     */
    public function __construct() {}

    /**
     * Handle the event.
     */
    public function handle(MessageCreated $event): void
    {
        // For each participant in the chatroom, send a notification
        $chatroom = $event->message->chatroom;
        $participants = $chatroom->getNotifiableParticipants();
        foreach ($participants as $participant) {
            // If the participant is the sender, skip sending notification
            if ($participant->id === $event->message->sender_id) {
                continue;
            }
            $actualParticipant = $participant->participatingModel;
            if (! $actualParticipant) {
                continue;
            }
            $actualParticipant->notify(new NewMessage($event->message, $participant));
        }
    }
}
