<?php

namespace Mmedia\LaravelChat\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
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
            Log::info('Participant is not connected via sockets, not sending notification.');
        }

        return $via;
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
