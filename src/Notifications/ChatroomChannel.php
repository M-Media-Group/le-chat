<?php

namespace Mmedia\LaravelChat\Notifications;

use Illuminate\Notifications\Notification;
use Mmedia\LaravelChat\Models\Chatroom;

class ChatroomChannel
{
    /**
     * Send the given notification.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        // Check if the notification has a toChatroom method
        if (method_exists($notification, 'toChatroom')) {

            /** @var ChatroomChannelMessage $data */
            $data = $notification->toChatroom($notifiable);
        } else {
            throw new \Exception('Notification does not have a toChatroom or toChatroomMessage method.');
        }

        // If no chatroom ID is provided, throw an exception
        if (
            ! isset($data->chatroom_id)

            // And the notifiable doesnt have the function getOrCreatePersonalChatroom
            && ! method_exists($notifiable, 'getOrCreatePersonalChatroom')
        ) {
            throw new \Exception('Chatroom ID is required to send a chatroom notification.');
        } elseif (! isset($data->chatroom_id)) {
            // If the notifiable has a getOrCreatePersonalChatroom method, use it to get the chatroom ID
            $data->chatroom_id = $notifiable->getOrCreatePersonalChatroom()->id;
        }

        $chatroom = Chatroom::findOrFail($data->chatroom_id);

        // Send the message to the chatroom
        $chatroom->sendMessage($data->message);
    }
}
