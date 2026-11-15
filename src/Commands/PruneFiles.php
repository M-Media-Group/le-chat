<?php

namespace Mmedia\LeChat\Commands;

use Illuminate\Console\Command;
use Mmedia\LeChat\Features;
use Mmedia\LeChat\Models\ChatFile;

class PruneFiles extends Command
{
    protected $signature = 'le-chat:prune-files';

    protected $description = 'Reconcile deleted message shares and prune expired chat files';

    public function handle(): int
    {
        if (! Features::enabled(Features::files())) {
            $this->error('The files feature is disabled.');

            return self::FAILURE;
        }
        ChatFile::query()->where('status', 'ready')->whereNull('expires_at')
            ->where(fn ($query) => $query->whereDoesntHave('messages')
                ->orWhereHas('messages', fn ($messages) => $messages->whereNotNull('chat_messages.deleted_at')))
            ->chunkById(100, function ($files): void {
                foreach ($files as $candidate) {
                    $candidate->getConnection()->transaction(function () use ($candidate): void {
                        $file = ChatFile::query()->whereKey($candidate->getKey())->lockForUpdate()->first();
                        if ($file === null || $file->status !== 'ready' || $file->expires_at !== null) {
                            return;
                        }
                        $deletedMessages = $file->messages()->whereNotNull('chat_messages.deleted_at')->pluck('chat_messages.id');
                        if ($deletedMessages->isNotEmpty()) {
                            $file->messages()->detach($deletedMessages);
                        }
                        if (! $file->messages()->exists()) {
                            $file->update(['expires_at' => now()->addHours(config('chat-options.files.unshared_ttl_hours', 24))]);
                        }
                    });
                }
            });

        $this->call('model:prune', ['--model' => [ChatFile::class]]);

        $failed = ChatFile::query()->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->whereIn('status', ['pending', 'ready', 'deleting'])
            ->whereDoesntHave('messages')
            ->exists();

        if ($failed) {
            $this->error('Unable to prune one or more expired chat files.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
