<?php

namespace Mmedia\LaravelChat\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Mmedia\LaravelChat\Http\Resources\MessageResource;
use Mmedia\LaravelChat\Models\ChatMessage;
use Mmedia\LaravelChat\Models\ChatParticipant;

class NewMessage extends Notification implements ShouldQueue
{
    use Queueable;

    /** The message that was created */
    public ChatMessage $message;

    /** The participant that we are notifying */
    public ChatParticipant $participant;

    /**
     * Create a new notification instance.
     */
    public function __construct(ChatMessage $message, ChatParticipant $participant)
    {
        $this->message = $message;
        $this->participant = $participant;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $via = ['broadcast'];

        $connected = false;

        // Check if notifiable has ConnectsToBroadcast trait
        if (method_exists($notifiable, 'getIsConnectedViaSockets')) {
            $connected = $notifiable->is_connected;
        } else {
            $connected = $this->participant->is_connected;
        }

        if (! $connected) {
            // Check if the WebPush channel class exists before adding it.
            // Use the fully qualified class name as a string.
            if (class_exists(\NotificationChannels\WebPush\WebPushChannel::class)) {
                // You can also add a check to see if the notifiable model
                // has the necessary 'routeNotificationForWebPush' method.
                if (method_exists($notifiable, 'routeNotificationForWebPush')) {
                    $via[] = \NotificationChannels\WebPush\WebPushChannel::class;
                }
            }
        }

        return $via;
    }

    /**
     * Get the web push representation of the notification.
     *
     * This method will only be called by Laravel if the WebPushChannel
     * was added in the via() method.
     *
     * @param  mixed  $notifiable
     * @param  mixed  $notification
     * @return mixed
     */
    public function toWebPush($notifiable, $notification)
    {
        // Now, instantiate the WebPushMessage using its fully qualified name
        $webPushMessageClass = \NotificationChannels\WebPush\WebPushMessage::class;

        return (new $webPushMessageClass)
            // The title is the name of the sender
            ->title($this->message->sender->display_name.' in '.($this->message->chatroom->name ?? 'Chat'))
            // The icon can be a URL to an image or a path to an asset
            ->icon($this->message->sender->avatar_url ?? (config('app.url').'/icon.png'))
            // The body is the message content
            ->body($this->message->message)
            // The action is a button that the user can click
            ->action('Open Chat', 'view_chat')
            ->lang('en');
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $senderName = $this->message->sender->display_name ?? 'Unknown Sender';

        return (new MailMessage)
            ->subject($senderName.' Sent You a New Message')
            ->greeting($this->participant->display_name.', you got a new message!')
            ->line($senderName.': '.$this->message->message)
            ->action('Open Chat', url('/chatrooms/'.$this->message->chatroom_id))
            ->line('Thank you for using our application!');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => $this->message->message,
            'chatroom_id' => $this->message->chatroom_id,
            'sender_id' => $this->message->sender_id,
            'created_at' => $this->message->created_at,
            'display_name' => $this->message->sender->display_name,
        ];
    }

    /**
     * Get the type of the notification being broadcast.
     */
    public function broadcastType(): string
    {
        return $this->message->chatroom->broadcastChannel().'.message';
    }

    /**
     * Get the broadcastable representation of the notification.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        $messageResource = MessageResource::make($this->message)->resolve();

        return new BroadcastMessage($messageResource);
    }
}
