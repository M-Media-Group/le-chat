<?php

namespace Mmedia\LeChat\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
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
                    $updates = [];
                    $text = $message->getRawOriginal('message');
                    if ($text !== null) {
                        try {
                            $updates['message'] = decrypt($text);
                        } catch (DecryptException $exception) {
                        }
                    }
                    $blocks = $message->getRawOriginal('blocks');
                    if ($blocks !== null) {
                        $decoded = json_decode($blocks, true, flags: JSON_THROW_ON_ERROR);
                        if (is_string($decoded)) {
                            $updates['blocks'] = json_encode(decrypt($decoded), JSON_THROW_ON_ERROR);
                        }
                    }
                    if ($updates !== []) {
                        $message->getConnection()->table($message->getTable())->where('id', $message->getKey())->update($updates);
                    }
                }
            });

        return self::SUCCESS;
    }
}
