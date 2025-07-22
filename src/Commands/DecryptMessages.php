<?php

namespace Mmedia\LeChat\Commands;

use Illuminate\Console\Command;
use Mmedia\LeChat\Models\ChatMessage;

class DecryptMessages extends Command
{
    public $signature = 'le-chat:decrypt-messages';

    public $description = 'Decrypt all chat messages';

    public function handle(): int
    {
        ChatMessage::query()
            ->chunkById(100, function ($messages) {
                foreach ($messages as $message) {
                    // First, try to decrypt the message. If it does not fail, we need to encrypt it.
                    try {
                        $newMessage = decrypt($message->getRawOriginal('message'));
                        $message->message = $newMessage; // Update the message with the decrypted content
                        $message->saveQuietly();
                        $this->info("Decrypted message ID: {$message->id}");
                    } catch (\Exception $e) {

                        $this->info("Already decrypted message ID: {$message->id}");
                    }
                }
            });

        return self::SUCCESS;
    }
}
