<?php

namespace Mmedia\LeChat\Commands;

use Illuminate\Console\Command;
use Mmedia\LeChat\Models\ChatMessage;

class DeleteMessages extends Command
{
    public $signature = 'le-chat:delete-messages';

    public $description = 'Delete all chat messages';

    public function handle(): int
    {
        ChatMessage::query()
            ->whereNotNull('deleted_at') // Ensure we only target soft-deleted messages
            ->chunkById(100, function ($messages) {
                foreach ($messages as $message) {
                    // Soft delete the message
                    $message->delete();

                    // Optionally, you can log the deletion or perform other actions
                    $this->info("Deleted message ID: {$message->id}");
                }
            });

        return self::SUCCESS;
    }
}
