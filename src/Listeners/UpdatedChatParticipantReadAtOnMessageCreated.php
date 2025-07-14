<?php

namespace Mmedia\LaravelChat\Listeners;

use Mmedia\LaravelChat\Events\MessageCreated;

class UpdatedChatParticipantReadAtOnMessageCreated
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
        $sender = $event->message->sender;

        if (! $sender) {
            return; // No sender found, nothing to update
        }

        // Update the read_at timestamp for the sender in the chatroom
        $sender->read_at = $event->message->created_at;
        $sender->save();
    }
}
