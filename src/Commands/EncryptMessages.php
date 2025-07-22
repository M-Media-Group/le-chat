<?php

namespace Mmedia\LeChat\Commands;

use Illuminate\Console\Command;
use Mmedia\LeChat\Models\ChatMessage;

class EncryptMessages extends Command
{
    public $signature = 'le-chat:encrypt-messages';

    public $description = 'Encrypt all chat messages';

    public function handle(): int
    {
        ChatMessage::query()
            ->chunkById(100, function ($messages) {
                foreach ($messages as $message) {
                    // First, try to decrypt the message. If it does not fail, we need to encrypt it.
                    try {
                        decrypt($message->getRawOriginal('message'));
                        // If decryption is successful, it means the message is already encrypted.
                        $this->info("Message ID {$message->id} is already encrypted.");
                    } catch (\Exception $e) {
                        // If decryption fails, it means the message is likely NOT encrypted so we can encrypt it.
                        $message->message = encrypt($message->getRawOriginal('message'));
                        $message->saveQuietly();
                        $this->info("Encrypted message ID: {$message->id}");
                    }
                }
            });

        return self::SUCCESS;
    }
}
