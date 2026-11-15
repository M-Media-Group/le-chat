<?php

namespace Mmedia\LeChat\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DailyUnreadMessagesNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $days = 1) {}

    // Send via email
    public function via($notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $message = match ($this->days) {
            0 => 'You have new unread messages from today.',
            1 => 'You have new unread messages that were sent in the last day.',
            default => "You have new unread messages that were sent in the last {$this->days} days.",
        };

        return (new MailMessage)
            ->subject('You have new unread messages')
            ->greeting('Hey!')
            ->line($message)
            ->action('View Messages', (config('app.spa_url') ?? config('app.url')).'/chat')
            ->line('Thank you for using '.config('app.name').'!');
    }
}
