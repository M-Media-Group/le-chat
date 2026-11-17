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
use Laravel\Ai\Responses\Data\ToolResult;
use Mmedia\LeChat\Contracts\ApprovalPolicy;
use Mmedia\LeChat\Contracts\ChatParticipantInterface;
use Mmedia\LeChat\Contracts\RespondsToChatMessages;
use Mmedia\LeChat\Events\MessageUpdated;
use Mmedia\LeChat\Models\ChatApprovalBatch;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Support\MessageBlocks;
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
            $message = $assistant->message;
            if ($blocks !== [] && $message === MessageBlocks::fallback($blocks)) {
                $message = null;
            }
            if ($previous !== null) {
                $blocks = array_values(array_filter($blocks, fn (array $block): bool => ! in_array($block['block_id'], ['approval-'.$previous->id, 'approval-'.$previous->id.'-actions'], true)));
            }
            $approvalText = $previous === null
                ? 'Tool approval required'
                : ($this->hasFailedCall($assistant) ? 'A previous action failed. The assistant is requesting approval for another action or changed details.' : 'The assistant is requesting approval for another action.');
            $blocks[] = ['schema_version' => 1, 'type' => 'section', 'block_id' => 'approval-'.$batch->id, 'text' => $approvalText];
            $blocks[] = ['schema_version' => 1, 'type' => 'actions', 'block_id' => 'approval-'.$batch->id.'-actions', 'elements' => [
                ['type' => 'button', 'action_id' => 'approve', 'text' => 'Approve'],
                ['type' => 'button', 'action_id' => 'reject', 'text' => 'Reject'],
            ]];
            $assistant->load('approvalBatch');
            if (! $assistant->updateContent($assistant->version, ['message' => $message, 'blocks' => $blocks])) {
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
            if ($requester->chatroom->messages()->where('sender_id', $requester->sender_id)
                ->where('id', '>', $assistant->id)->whereNull('deleted_at')->whereNull('metadata->ai')->exists()) {
                throw new ConflictHttpException('A newer message from the requester must be processed before this approval.');
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
                $delegations = app(DelegatedRuns::class);
                $delegations->load($assistant);
                $ready = true;
                $response = DelegationTools::within($agent, function () use ($agent, $delegations, $currentApprovals, $sdkDecisions, &$ready) {
                    $ready = $delegations->advanceTools($agent, $currentApprovals, $sdkDecisions);

                    return $ready ? $agent->prompt(Decisions::from($sdkDecisions)) : null;
                });
                if (! $ready) {
                    $batch->update(['status' => 'resumed']);
                    $this->create($requester, $assistant->fresh());

                    return $batch->refresh();
                }
                $batch->update(['status' => 'resumed']);
                if ($response instanceof AgentResponse && $response->hasPendingApprovals()) {
                    $nextMessage = ChatMessage::findOrFail($response->assistantMessageId ?? $assistant->id);
                    $failureReason = $this->stopRepeatedFailures($store, $conversationId, $nextMessage);
                    if ($failureReason !== null) {
                        $batch->update(['failure_reason' => $failureReason]);
                    }
                    if ($store->pendingApprovalsFor($conversationId) !== []) {
                        $this->create($requester, $nextMessage->fresh());
                    }
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

    /** @return list<array{id: string, tool: string, arguments: array<string, mixed>, reason: string|null}> */
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

        return array_map(function (PendingApproval $approval) use ($assistant): array {
            $details = $approval->toArray();
            $previous = $this->previousCall($assistant, $approval, matchArguments: false);
            if ($previous !== null && $this->callFailed($previous)) {
                $error = $this->failureDetail($previous);
                $details['reason'] = trim(($approval->reason ?? '').' Previous attempt failed'.($error === null ? '.' : ': '.$error));
            }

            return $details;
        }, $approvals);
    }

    public function supersedeWith(ChatMessage $message): bool
    {
        $assistant = $message->chatroom->messages()->where('metadata->ai->status', 'paused')->latest('id')->first();
        $batch = $assistant?->approvalBatch;
        if ($assistant === null || $batch === null || ! in_array($batch->status, ['pending', 'expired'], true)
            || $message->id <= $assistant->id || $message->sender_id !== $batch->requesterMessage->sender_id
            || $message->sender?->participatingModel === null) {
            return false;
        }

        $actor = $message->sender->participatingModel;
        if (! $actor instanceof ChatParticipantInterface) {
            return false;
        }
        try {
            $this->authorizeMessageAccess($actor, $batch->requesterMessage, $assistant);
        } catch (AuthorizationException) {
            return false;
        }
        $store = app(ChatroomConversationStore::class);
        $conversationId = $store->conversationIdOf($message->chatroom);

        return $assistant->getConnection()->transaction(function () use ($batch, $assistant, $message, $actor, $store, $conversationId): bool {
            $batch = ChatApprovalBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $freshMessage = $message->fresh();
            if (! in_array($batch->status, ['pending', 'expired'], true) || $freshMessage === null || $freshMessage->deleted_at !== null) {
                return false;
            }
            $actor = $actor->fresh();
            if (! $actor instanceof ChatParticipantInterface) {
                return false;
            }
            try {
                $this->authorizeMessageAccess($actor, $batch->requesterMessage->fresh(), $assistant->fresh());
            } catch (AuthorizationException) {
                return false;
            }
            $approvals = $store->pendingApprovalsFor($conversationId);
            if ($approvals === [] || $this->fingerprints($batch, $approvals) !== $batch->fingerprints
                || ! app(ApprovalPolicy::class)->mayApprove($actor, $batch->requesterMessage, $approvals)) {
                return false;
            }
            $batch->update(['status' => 'cancelled', 'failure_reason' => 'Replaced by a newer message from the requester.']);
            $store->storeApprovalResults($conversationId, array_map(fn (PendingApproval $approval): ToolResult => new ToolResult(
                $approval->id, $approval->tool, $approval->arguments,
                'The requester sent a new message. This proposed action was cancelled without execution; follow the new message.', denied: true,
            ), $approvals));
            $assistant->refresh();
            $metadata = $assistant->metadata->getArrayCopy();
            $metadata['ai']['status'] = 'completed';
            foreach ($metadata['ai']['steps'] as &$step) {
                $step['replay_blocks'] = [];
            }
            unset($step);
            $assistant->forceFill(['metadata' => $metadata])->save();
            if (! $assistant->updateContent($assistant->version, [
                'message' => 'The previous approval was cancelled because the requester sent a new message. No action was performed.',
                'blocks' => null,
            ])) {
                throw new \RuntimeException('The paused message changed while cancelling its approval.');
            }

            return true;
        });
    }

    /** @param list<PendingApproval> $approvals */
    public function fingerprints(ChatApprovalBatch $batch, array $approvals): array
    {
        $fingerprints = [];
        $requester = $batch->requesterMessage;
        $sender = $requester->sender;
        $conversationId = app(ChatroomConversationStore::class)->conversationIdOf($requester->chatroom);
        $delegations = ChatMessage::query()->find($batch->assistant_message_id)?->metadata['ai']['delegations'] ?? [];
        foreach ($approvals as $approval) {
            if ($approval->id === '' || array_key_exists($approval->id, $fingerprints)) {
                throw new \LogicException('Pending tool call identifiers must be unique and non-empty.');
            }
            $delegation = array_filter($delegations, fn (string $key): bool => $key === 'root/'.$approval->id || str_starts_with($key, 'root/'.$approval->id.'/'), ARRAY_FILTER_USE_KEY);
            $payload = $this->canonicalize([
                'batch_id' => $batch->id, 'conversation_id' => $conversationId,
                'requester_message_id' => $batch->requester_message_id,
                'requester_sender_id' => $requester->sender_id,
                'requester_type' => $sender?->participant_type, 'requester_id' => $sender?->participant_id,
                'tool_call_id' => $approval->id, 'tool' => $approval->tool, 'arguments' => $approval->arguments,
                ...($delegation !== [] ? ['delegation' => $delegation] : []),
            ]);
            $fingerprints[$approval->id] = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        }
        ksort($fingerprints);

        return $fingerprints;
    }

    private function stopRepeatedFailures(ChatroomConversationStore $store, string $conversationId, ChatMessage $assistant): ?string
    {
        $repeated = [];
        $failureReason = null;
        foreach ($store->pendingApprovalsFor($conversationId) as $approval) {
            $previous = $this->previousCall($assistant, $approval, matchArguments: true);
            if ($previous !== null && $this->callFailed($previous)) {
                $repeated[] = new ToolResult($approval->id, $approval->tool, $approval->arguments, 'The same action already failed. The retry was stopped; ask the user to review the error.', denied: true);
                $failureReason ??= $this->failureDetail($previous) ?? 'The action failed without a detailed error.';
            }
        }

        if ($repeated === []) {
            return null;
        }

        $assistant->getConnection()->transaction(function () use ($store, $conversationId, $repeated, $assistant): void {
            $store->storeApprovalResults($conversationId, $repeated);
            if ($store->pendingApprovalsFor($conversationId) !== []) {
                return;
            }

            $assistant->refresh();
            $metadata = $assistant->metadata->getArrayCopy();
            $metadata['ai']['status'] = 'completed';
            foreach ($metadata['ai']['steps'] as &$step) {
                $step['replay_blocks'] = [];
            }
            unset($step);
            $metadata['ai']['steps'][] = ['content' => 'The previous action failed. An unchanged retry was stopped.', 'tool_calls' => [], 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []];
            $assistant->forceFill(['metadata' => $metadata])->save();
            if (! $assistant->updateContent($assistant->version, [
                'message' => 'The previous action failed. The assistant tried the same action again without changing its details, so the retry was stopped. Please review the request.',
                'blocks' => null,
            ])) {
                throw new \RuntimeException('The paused message changed while stopping a repeated action.');
            }
        });

        return $failureReason;
    }

    private function previousCall(ChatMessage $assistant, PendingApproval $approval, bool $matchArguments): ?array
    {
        return collect($assistant->metadata['ai']['steps'] ?? [])->flatMap(fn (array $step) => $step['tool_calls'] ?? [])->reverse()
            ->first(fn (array $call): bool => ($call['name'] ?? null) === $approval->tool
                && ($call['id'] ?? null) !== $approval->id
                && array_key_exists('result', $call)
                && ($matchArguments
                    ? $this->canonicalize($call['arguments'] ?? []) === $this->canonicalize($approval->arguments)
                    : ($call['arguments']['action'] ?? null) === ($approval->arguments['action'] ?? null)));
    }

    private function hasFailedCall(ChatMessage $assistant): bool
    {
        return collect($assistant->metadata['ai']['steps'] ?? [])->flatMap(fn (array $step) => $step['tool_calls'] ?? [])
            ->contains(fn (array $call): bool => $this->callFailed($call));
    }

    private function callFailed(array $call): bool
    {
        if (($call['denied'] ?? false) || ! array_key_exists('result', $call)) {
            return false;
        }
        $result = is_string($call['result']) ? json_decode($call['result'], true) : $call['result'];

        return ($call['failed'] ?? false) || (is_array($result) && isset($result['error']));
    }

    private function failureDetail(array $call): ?string
    {
        $result = $call['result'] ?? null;
        $decoded = is_string($result) ? json_decode($result, true) : $result;
        $detail = is_array($decoded) && is_string($decoded['error'] ?? null)
            ? $decoded['error']
            : (($call['failed'] ?? false) && is_string($result) ? $result : null);

        return filled($detail) ? mb_substr($detail, 0, 1000) : null;
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
