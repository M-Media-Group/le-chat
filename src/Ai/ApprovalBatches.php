<?php

namespace Mmedia\LeChat\Ai;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\AgentResponse;
use Mmedia\LeChat\Contracts\ApprovalPolicy;
use Mmedia\LeChat\Contracts\ChatParticipantInterface;
use Mmedia\LeChat\Contracts\RespondsToChatMessages;
use Mmedia\LeChat\Events\MessageUpdated;
use Mmedia\LeChat\Models\ChatApprovalBatch;
use Mmedia\LeChat\Models\ChatMessage;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** @internal Approval persistence and resume orchestration for the chat API. */
class ApprovalBatches
{
    public function create(ChatMessage $requester, ChatMessage $assistant): ChatApprovalBatch
    {
        if ($requester->chatroom_id !== $assistant->chatroom_id || $requester->sender_id === null || $requester->deleted_at !== null || $assistant->deleted_at !== null || ($assistant->metadata['ai']['status'] ?? null) !== 'paused') {
            throw new \LogicException('The approval must refer to a paused turn and its requester.');
        }
        $store = app(ChatroomConversationStore::class);
        $conversationId = $store->conversationIdOf($assistant->chatroom);
        $approvals = $store->pendingApprovalsFor($conversationId);
        $latestPaused = $assistant->chatroom->messages()->where('metadata->ai->status', 'paused')->latest('id')->first();
        if ($approvals === [] || $latestPaused?->id !== $assistant->id) {
            throw new \LogicException('The paused turn has no pending approvals.');
        }

        return $assistant->getConnection()->transaction(function () use ($assistant, $requester, $conversationId, $store): ChatApprovalBatch {
            ChatMessage::query()->whereKey($assistant->id)->lockForUpdate()->firstOrFail();
            $approvals = $store->pendingApprovalsFor($conversationId);
            $previous = ChatApprovalBatch::query()->where('assistant_message_id', $assistant->id)->orderByDesc('generation')->first();
            if ($previous !== null) {
                if ($previous->requester_message_id !== $requester->id) {
                    throw new \LogicException('The approval batch belongs to another request.');
                }
                if ($this->fingerprints($previous, $approvals) === $previous->fingerprints) {
                    return $previous;
                }
                if ($previous->status !== 'resumed') {
                    throw new \LogicException('The previous approval batch has not finished.');
                }
            }
            if ($approvals === []) {
                throw new \LogicException('The paused turn has no pending approvals.');
            }
            $batch = ChatApprovalBatch::create([
                'assistant_message_id' => $assistant->id,
                'generation' => ($previous->generation ?? 0) + 1,
                'requester_message_id' => $requester->id,
                'fingerprints' => [],
                'status' => 'pending',
                'expires_at' => now()->addMinutes(config('chat.approvals.ttl_minutes', 15)),
            ]);
            $batch->update(['fingerprints' => $this->fingerprints($batch, $approvals)]);
            $assistant->refresh();
            $blocks = $assistant->blocks ?? [];
            if ($previous !== null) {
                $blocks = array_values(array_filter($blocks, fn (array $block): bool => ! in_array($block['block_id'], ['approval-'.$previous->id, 'approval-'.$previous->id.'-actions'], true)));
            }
            $blocks[] = ['schema_version' => 1, 'type' => 'section', 'block_id' => 'approval-'.$batch->id, 'text' => 'Tool approval required'];
            $blocks[] = ['schema_version' => 1, 'type' => 'actions', 'block_id' => 'approval-'.$batch->id.'-actions', 'elements' => [
                ['type' => 'button', 'action_id' => 'approve', 'text' => 'Approve'],
                ['type' => 'button', 'action_id' => 'reject', 'text' => 'Reject'],
            ]];
            $assistant->load('approvalBatch');
            if (! $assistant->updateContent($assistant->version, ['message' => $assistant->message, 'blocks' => $blocks])) {
                throw new \RuntimeException('The paused message changed while creating its approval.');
            }

            return $batch;
        });
    }

    public function decide(ChatApprovalBatch $batch, ChatParticipantInterface $actor, array $decisions, string $idempotencyKey): ChatApprovalBatch
    {
        $requester = $batch->requesterMessage;
        $assistant = $batch->assistantMessage;
        $this->authorizeMessageAccess($actor, $requester, $assistant);
        $store = app(ChatroomConversationStore::class);
        $conversationId = $store->conversationIdOf($assistant->chatroom);

        $canonical = $this->canonicalize($decisions);
        $digest = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $batch->refresh();
        if ($batch->status !== 'pending') {
            if ($this->matchesDecision($batch, $actor, $idempotencyKey, $digest)) {
                return $batch;
            }
            throw new ConflictHttpException('This approval batch has already been decided.');
        }
        if ($batch->expires_at->isPast()) {
            ChatApprovalBatch::query()->whereKey($batch->id)->where('status', 'pending')->where('expires_at', '<=', now())->update(['status' => 'expired']);
            throw ValidationException::withMessages(['decisions' => 'This approval batch has expired.']);
        }

        $approvals = $store->pendingApprovalsFor($conversationId);
        if ($this->fingerprints($batch, $approvals) !== $batch->fingerprints) {
            throw ValidationException::withMessages(['decisions' => 'The pending tool calls have changed.']);
        }
        $expected = array_keys($batch->fingerprints);
        $actual = array_keys($decisions);
        sort($expected);
        sort($actual);
        if ($actual !== $expected || $idempotencyKey === '') {
            throw ValidationException::withMessages(['decisions' => 'A decision is required for every pending tool call.']);
        }
        $sdkDecisions = [];
        foreach ($decisions as $id => $decision) {
            if (! is_array($decision) || ! in_array($decision['action'] ?? null, ['approve', 'reject'], true) || (isset($decision['result']) && ! is_string($decision['result']))) {
                throw ValidationException::withMessages(['decisions' => 'Each decision must approve or reject a tool call.']);
            }
            $sdkDecisions[$id] = $decision['action'] === 'approve'
                ? Decision::approve()
                : Decision::reject($decision['result'] ?? 'The approver rejected this tool call.');
        }
        $policy = app(ApprovalPolicy::class);
        if (! $policy->mayApprove($actor, $requester, $approvals)) {
            throw new AuthorizationException;
        }

        $reserved = ChatApprovalBatch::query()->whereKey($batch->id)->where('status', 'pending')
            ->where('expires_at', '>', now())->whereNull('idempotency_key')->update([
                'approver_type' => $actor->getMorphClass(), 'approver_id' => $actor->getKey(),
                'idempotency_key' => $idempotencyKey, 'decision_hash' => $digest,
            ]);
        if ($reserved === 0) {
            $batch->refresh();
            if (! $this->matchesDecision($batch, $actor, $idempotencyKey, $digest)) {
                throw new ConflictHttpException('The approval batch has already been decided or reserved.');
            }
            if ($batch->status !== 'pending') {
                return $batch;
            }
        }

        $lock = Cache::lock('laravel-queue-overlap:le-chat-ai:'.$requester->chatroom_id, 600);
        if (! $lock->get()) {
            return $batch->refresh();
        }
        $claimed = 0;
        try {
            if ($actor instanceof Model) {
                $freshActor = $actor->fresh();
                if (! $freshActor instanceof ChatParticipantInterface) {
                    throw new AuthorizationException;
                }
                $actor = $freshActor;
            }
            $requester->refresh();
            $assistant->refresh();
            $this->authorizeMessageAccess($actor, $requester, $assistant);
            $currentApprovals = $store->pendingApprovalsFor($conversationId);
            if (! $policy->mayApprove($actor, $requester, $currentApprovals)) {
                throw new AuthorizationException;
            }
            if ($this->fingerprints($batch, $currentApprovals) !== $batch->fingerprints) {
                throw ValidationException::withMessages(['decisions' => 'The pending tool calls have changed.']);
            }
            $claimed = ChatApprovalBatch::query()->whereKey($batch->id)->where('status', 'pending')->where('expires_at', '>', now())
                ->where('idempotency_key', $idempotencyKey)->where('decision_hash', $digest)
                ->where('approver_type', $actor->getMorphClass())->where('approver_id', $actor->getKey())
                ->update(['status' => 'decided']);
            if ($claimed === 0) {
                $batch->refresh();
                if ($this->matchesDecision($batch, $actor, $idempotencyKey, $digest)) {
                    return $batch;
                }
                throw new ConflictHttpException('The approval batch has already been decided or expired.');
            }
            $batch->refresh();
            $requester->refresh();
            $assistant->refresh();
            $requesterModel = $requester->sender?->participatingModel;
            $currentApprovals = $store->pendingApprovalsFor($conversationId);
            $failureReason = 'Requester authorization or approval payload is no longer valid.';
            try {
                $authorization = $policy->mayResume($requester, $currentApprovals);
                $authorized = $authorization === true;
                if (is_string($authorization) && $authorization !== '') {
                    $failureReason = $authorization;
                }
            } catch (\Throwable $exception) {
                report($exception);
                $authorized = false;
            }
            if ($requester->deleted_at !== null || $assistant->deleted_at !== null || $requesterModel === null || ! $requester->chatroom->hasParticipant($requester->sender) || ! $authorized || $this->fingerprints($batch, $currentApprovals) !== $batch->fingerprints) {
                $batch->update(['status' => 'cancelled', 'failure_reason' => $failureReason]);

                return $batch->refresh();
            }
            try {
                $agentClass = $assistant->metadata['ai']['agent'] ?? null;
                if (! is_string($agentClass) || ! class_exists($agentClass)) {
                    throw new \LogicException('The paused agent is unavailable.');
                }
                $agent = is_subclass_of($agentClass, RespondsToChatMessages::class) ? $agentClass::forChatMessage($requester) : app($agentClass);
                if (! method_exists($agent, 'continue') || ! method_exists($agent, 'prompt')) {
                    throw new \LogicException('The paused agent cannot resume conversations.');
                }
                $agent->continue($conversationId, as: $requesterModel);
                $response = $agent->prompt(Decisions::from($sdkDecisions));
                $batch->update(['status' => 'resumed']);
                if ($response instanceof AgentResponse && $response->hasPendingApprovals()) {
                    $nextMessage = ChatMessage::findOrFail($response->assistantMessageId ?? $assistant->id);
                    $this->create($requester, $nextMessage);
                }
            } catch (\Throwable $exception) {
                report($exception);
                $batch->update(['failure_reason' => 'The resume outcome is uncertain and requires reconciliation.']);
            }

            return $batch->refresh();
        } finally {
            if ($claimed === 0) {
                ChatApprovalBatch::query()->whereKey($batch->id)->where('status', 'pending')
                    ->where('idempotency_key', $idempotencyKey)->where('decision_hash', $digest)
                    ->where('approver_type', $actor->getMorphClass())->where('approver_id', $actor->getKey())
                    ->update(['approver_type' => null, 'approver_id' => null, 'idempotency_key' => null, 'decision_hash' => null]);
            }
            $lock->release();
            if ($claimed !== 0 && ($message = ChatMessage::find($assistant->id)) !== null) {
                $message->load('approvalBatch');
                $message->getConnection()->afterCommit(fn () => MessageUpdated::dispatch($message));
            }
        }
    }

    public function details(ChatApprovalBatch $batch, ChatParticipantInterface $actor): array
    {
        $requester = $batch->requesterMessage;
        $assistant = $batch->assistantMessage;
        $this->authorizeMessageAccess($actor, $requester, $assistant);
        $store = app(ChatroomConversationStore::class);
        $conversationId = $store->conversationIdOf($assistant->chatroom);
        $approvals = $store->pendingApprovalsFor($conversationId);
        if ($batch->status !== 'pending' || $batch->expires_at->isPast() || $this->fingerprints($batch, $approvals) !== $batch->fingerprints) {
            throw ValidationException::withMessages(['approval' => 'The approval batch is no longer pending.']);
        }
        if (! app(ApprovalPolicy::class)->mayApprove($actor, $requester, $approvals)) {
            throw new AuthorizationException;
        }

        return array_map(fn (PendingApproval $approval): array => $approval->toArray(), $approvals);
    }

    /** @param list<PendingApproval> $approvals */
    public function fingerprints(ChatApprovalBatch $batch, array $approvals): array
    {
        $fingerprints = [];
        $requester = $batch->requesterMessage;
        $sender = $requester->sender;
        $conversationId = app(ChatroomConversationStore::class)->conversationIdOf($requester->chatroom);
        foreach ($approvals as $approval) {
            if ($approval->id === '' || array_key_exists($approval->id, $fingerprints)) {
                throw new \LogicException('Pending tool call identifiers must be unique and non-empty.');
            }
            $payload = $this->canonicalize([
                'batch_id' => $batch->id, 'conversation_id' => $conversationId,
                'requester_message_id' => $batch->requester_message_id,
                'requester_sender_id' => $requester->sender_id,
                'requester_type' => $sender?->participant_type, 'requester_id' => $sender?->participant_id,
                'tool_call_id' => $approval->id, 'tool' => $approval->tool, 'arguments' => $approval->arguments,
            ]);
            $fingerprints[$approval->id] = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        }
        ksort($fingerprints);

        return $fingerprints;
    }

    private function authorizeMessageAccess(ChatParticipantInterface $actor, ChatMessage $requester, ChatMessage $assistant): void
    {
        if ($requester->deleted_at !== null || $assistant->deleted_at !== null || ! $requester->chatroom->hasParticipant($actor) || ! ChatMessage::query()->visibleTo($actor)->whereKey($requester->id)->exists() || ! ChatMessage::query()->visibleTo($actor)->whereKey($assistant->id)->exists()) {
            throw new AuthorizationException;
        }
    }

    private function matchesDecision(ChatApprovalBatch $batch, ChatParticipantInterface $actor, string $idempotencyKey, string $digest): bool
    {
        return $batch->idempotency_key === $idempotencyKey
            && $batch->decision_hash === $digest
            && $batch->approver_type === $actor->getMorphClass()
            && (string) $batch->approver_id === (string) $actor->getKey();
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->canonicalize($item), $value);
    }
}
