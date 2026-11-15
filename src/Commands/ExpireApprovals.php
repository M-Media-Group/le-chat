<?php

namespace Mmedia\LeChat\Commands;

use Illuminate\Console\Command;
use Mmedia\LeChat\Events\MessageUpdated;
use Mmedia\LeChat\Models\ChatApprovalBatch;
use Mmedia\LeChat\Models\ChatMessage;

class ExpireApprovals extends Command
{
    protected $signature = 'le-chat:expire-approvals';

    protected $description = 'Expire abandoned pending tool approvals';

    public function handle(): int
    {
        ChatApprovalBatch::query()->where('status', 'pending')->where('expires_at', '<=', now())
            ->chunkById(100, function ($batches): void {
                foreach ($batches as $batch) {
                    $updated = ChatApprovalBatch::query()->whereKey($batch->id)->where('status', 'pending')
                        ->where('expires_at', '<=', now())->update(['status' => 'expired']);
                    if ($updated !== 0 && ($message = ChatMessage::find($batch->assistant_message_id)) !== null) {
                        $message->load('approvalBatch');
                        $message->getConnection()->afterCommit(fn () => MessageUpdated::dispatch($message));
                    }
                }
            });

        return self::SUCCESS;
    }
}
