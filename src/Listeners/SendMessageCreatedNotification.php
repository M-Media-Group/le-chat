<?php

namespace Mmedia\LaravelChat\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Mmedia\LaravelChat\Events\MessageCreated;
use Mmedia\LaravelChat\Notifications\NewMessage;

class SendMessageCreatedNotification implements ShouldQueue
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        Log::info('SendMessageCreatedNotification listener initialized.');
    }

    /**
     * Handle the event.
     */
    public function handle(MessageCreated $event): void
    {
        // For each participant in the chatroom, send a notification
        $chatroom = $event->message->chatroom;
        $participants = $chatroom->participants;
        foreach ($participants as $participant) {
            // If the participant is the sender, skip sending notification
            if ($participant->id === $event->message->sender_id) {
                continue;
            }
            $actualParticipant = $participant->participant;
            // Determine if the participant model has the trait Notifiable
            if (! method_exists($actualParticipant, 'notify')) {
                Log::warning('Participant does not have the notify method, skipping notification.');

                continue;
            }
            $actualParticipant->notify(new NewMessage($event->message, $participant));
        }
    }
}
