<?php

namespace Mmedia\LeChat\Commands;

use Illuminate\Console\Command;
use Mmedia\LeChat\Notifications\DailyUnreadMessagesNotification;

class NotifyUsersOfRecentUnreadMessages extends Command
{
    public $signature = 'le-chat:notify-users-of-recent-unread-messages {--days=0 : The number of days to check for unread messages, defaults to 0 for today}';

    public $description = 'Send a message to a chatroom';

    public function handle(): int
    {
        // If the user class is not an instance of ChatParticipantInterface, throw an error
        if (! class_exists(\App\Models\User::class) || ! is_subclass_of(\App\Models\User::class, \Mmedia\LeChat\Contracts\ChatParticipantInterface::class)) {
            $this->error('The User model must implement the ChatParticipantInterface contract to use this command.');

            return self::FAILURE;
        }
        $days = $this->option('days');
        $users = \App\Models\User::whereHasUnreadMessages($days)->get();

        // Log how many users have unread messages today
        $this->info("Found {$users->count()} users with unread messages in the last {$days} days.");

        // Log all the user IDs
        $userIds = $users->pluck('id')->implode(', ');
        $this->info("User IDs: {$userIds}");

        // Notify each user
        foreach ($users as $user) {
            $this->info("Notifying user ID: {$user->id}");
            $user->notify(new DailyUnreadMessagesNotification);
        }

        return self::SUCCESS;
    }
}
