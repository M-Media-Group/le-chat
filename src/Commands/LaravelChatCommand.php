<?php

namespace Mmedia\LaravelChat\Commands;

use Illuminate\Console\Command;

class LaravelChatCommand extends Command
{
    public $signature = 'laravel-chat:send-message {fromUserId} {message} {otherUserId}';

    public $description = 'Send a message to a chatroom';

    public function handle(): int
    {
        $otherUserId = $this->argument('otherUserId');
        $message = $this->argument('message');
        $fromId = $this->argument('fromUserId');

        // Get the user
        $fromUser = \App\Models\User::find($fromId);
        if (! $fromUser) {
            $this->error('User not found');

            return self::FAILURE;
        }

        // Load chatroom
        $otherUser = \App\Models\User::find($otherUserId);
        if (! $otherUser) {
            $this->error('Other user not found');

            return self::FAILURE;
        }

        $fromUser->sendMessageTo($otherUser, $message);

        return self::SUCCESS;
    }
}
