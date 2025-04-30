<?php

namespace MMedia\LaravelChat\Commands;

use Illuminate\Console\Command;

class LaravelChatCommand extends Command
{
    public $signature = 'laravel-chat';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
