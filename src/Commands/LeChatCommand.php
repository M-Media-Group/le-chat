<?php

namespace Mmedia\LeChat\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Broadcast;

class LeChatCommand extends Command
{
    public $signature = 'le-chat:send-message {fromUserId} {message} {otherUserId}';

    public $description = 'Send a message to a chatroom';

    public function handle(): int
    {
        $otherUserId = $this->argument('otherUserId');
        $message = $this->argument('message');
        $fromId = $this->argument('fromUserId');

        // For debugging, call the Reverb GET /apps/[app_id]/channels/[channel_name]/users and print
        // the response

        // $users = Broadcast::driver()->getPusher()
        //     ->get('/channels');

        // $this->info('Response from Reverb: ' . json_encode($users, JSON_PRETTY_PRINT));

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
