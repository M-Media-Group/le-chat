<?php

namespace Mmedia\LeChat\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DailyUnreadMessagesNotification extends Notification implements ShouldQueue
{
    use Queueable;

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
        return (new MailMessage)
            ->subject('You have new unread messages')
            ->greeting('Hey!')
            ->line('You have new unread messages that were sent to you in the last day.')
            ->action('View Messages', (config('app.spa_url') ?? config('app.url')) . '/chat')
            ->line('Thank you for using ' . config('app.name') . '!');
    }
}
