<?php

namespace Mmedia\LeChat\Ai;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Laravel\Ai\Storage\StoredMessage;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\ChatParticipant;
use Mmedia\LeChat\Models\Chatroom;
use Throwable;

/**
 * Stores AI SDK conversations as Le Chat chatrooms.
 *
 * Only the persistence layer differs from the SDK's store; turn building, step serialisation and
 * approval handling are inherited. Fields Le Chat has no column for live in `metadata.ai`.
 *
 * Lookups (room, agent sender ids, history participant) are memoised on the instance. Bind the store
 * with `scoped()` so long-lived workers (Octane, queues) start every request or job with empty caches.
 */
class ChatroomConversationStore extends DatabaseConversationStore
{
    /** Marks a model-less ChatParticipant as an AI agent; the rest of the reference is the agent class. */
    public const AGENT_REFERENCE_PREFIX = 'ai-agent:';

    /** A human message already in the chatroom that the agent is currently answering. */
    protected ?ChatMessage $pendingMessage = null;

    protected ?string $activeAgentClass = null;

    /** @var array<string, Chatroom> */
    protected array $rooms = [];

    /** @var array<int|string, Collection<int, int>> */
    protected array $agentIdsByRoom = [];

    /** @var array<int|string, array<string, ChatParticipant|null>> roomId => active agent class => participant */
    protected array $historyParticipants = [];

    /**
     * Run the callback while the given chatroom message is the one being answered.
     *
     * The SDK stores the prompt as a new user message; here it adopts the existing message instead, and
     * the message is left out of the replayed history so the model does not see it twice.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public function answering(ChatMessage $message, string $agentClass, \Closure $callback): mixed
    {
        $previousMessage = $this->pendingMessage;
        $previousAgentClass = $this->activeAgentClass;
        $this->pendingMessage = $message;
        $this->activeAgentClass = $agentClass;

        try {
            return $callback();
        } finally {
            $this->pendingMessage = $previousMessage;
            $this->activeAgentClass = $previousAgentClass;
            $this->flushCaches();
        }
    }

    public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string
    {
        $room = Chatroom::query()
            ->havingParticipants([
                new ChatParticipant(['participant_type' => $participantType, 'participant_id' => $participantId]),
                new ChatParticipant(['reference_id' => static::agentReference($agent)]),
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        return $room ? $this->conversationIdOf($room) : null;
    }

    public function conversationBelongsTo(string $conversationId, ?string $participantType, string|int|null $participantId): bool
    {
        $room = $this->room($conversationId);

        if ($room === null) {
            return false;
        }

        if ($participantType === null) {
            return ! $room->participants()->whereNotNull('participant_type')->exists();
        }

        return $room->participants()
            ->where('participant_type', $participantType)
            ->where('participant_id', $participantId)
            ->exists();
    }

    public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
    {
        $conversationId = $id ?? (string) Str::uuid7();

        $room = Chatroom::query()->create([
            'name' => $title,
            'metadata' => ['ai' => ['conversation_id' => $conversationId]],
        ]);

        if ($participantType !== null && $participantId !== null) {
            ChatParticipant::query()->create([
                'chatroom_id' => $room->getKey(),
                'participant_type' => $participantType,
                'participant_id' => $participantId,
                'role' => 'admin',
            ]);
        }

        return $conversationId;
    }

    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent, UserMessage $message): string
    {
        $room = $this->roomOrFail($conversationId);

        $this->agentParticipant($room, $agent); // Ensure the agent is a participant before it answers.

        if ($this->isAnswering($room)) {
            return $this->adoptPendingMessage($agent);
        }

        $metadata = ['ai' => [
            'role' => 'user',
            'agent' => $agent,
            'attachments' => $this->plain($message->attachments),
            'status' => MessageStatus::Completed->value,
        ]];

        // The prompt arrives wrapped in a requester envelope; the chatroom stores what the person wrote.
        $content = RequesterEnvelope::unwrap($message->content);
        $sender = $this->ownerParticipant($room, $participantType, $participantId);

        $chatMessage = $sender
            ? $room->sendMessageAs($sender, $content, ['metadata' => $metadata])
            : $room->sendMessage($content, ['metadata' => $metadata]);

        $room->touch();

        return (string) $chatMessage->getKey();
    }

    public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): ?string
    {
        $room = $this->roomOrFail($conversationId);

        if ($prompt->hasApprovalDecisions() && ($paused = $this->pausedRowFor($conversationId, $prompt)) !== null) {
            return $this->resumePausedRow($conversationId, $paused, $prompt, $response, $exception);
        }

        $steps = $this->stepsFor($prompt, $response);

        if ($prompt->hasApprovalDecisions() && blank($response->text) && $steps->every(fn (array $step) => $step['tool_calls'] === [])) {
            return null;
        }

        $chatMessage = $room->sendMessageAs(
            $this->agentParticipant($room, $prompt->agent::class),
            $response->text,
            ['metadata' => ['ai' => [
                'role' => 'assistant',
                'agent' => $prompt->agent::class,
                'attachments' => [],
                'steps' => $this->plain($steps),
                'usage' => $this->plain($response->usage),
                'meta' => $this->plain($this->metaFor($response, $exception)),
                'status' => $this->statusFor($response, $exception)->value,
            ]]]
        );

        $this->closeTurn($conversationId, $response);

        return (string) $chatMessage->getKey();
    }

    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        $room = $this->room($conversationId);

        if ($room === null) {
            return collect();
        }

        $agentIds = $this->agentSenderIds($room);

        $records = $this->messageQuery($room)
            ->when($this->isAnswering($room), fn ($query) => $query->whereKeyNot($this->pendingMessage->getKey()))
            ->with('sender')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (ChatMessage $message) => $this->recordFrom($message, $agentIds));

        // Follow-ups ("the second one", "why?") refer to the newest assistant turn, so it keeps its tool calls.
        $newestAssistantId = $records->last(fn (object $record) => $record->role !== 'user')?->id;

        return $records->flatMap(fn (object $record): array => match (true) {
            $record->role === 'user' => [$this->userMessageFrom($record)],
            $this->collapses($record, $newestAssistantId) => $this->textOnlyTurn($record),
            default => $this->assistantTurnFrom($record),
        });
    }

    public function paginateConversationMessages(string $conversationId, int $perPage = 15, string $cursorName = 'cursor', Cursor|string|null $cursor = null): CursorPaginator
    {
        $room = $this->roomOrFail($conversationId);

        $agentIds = $this->agentSenderIds($room);

        return $this->messageQuery($room)
            ->orderByDesc('id')
            ->cursorPaginate($perPage, ['*'], $cursorName, $cursor)
            ->through(fn (ChatMessage $message): StoredMessage => StoredMessage::fromArray(
                (array) $this->recordFrom($message, $agentIds)
            ));
    }

    public function pendingApprovalsFor(string $conversationId): array
    {
        $room = $this->roomOrFail($conversationId);
        $paused = $this->assistantRecords($room, MessageStatus::Paused)->first();

        return $paused === null ? [] : $this->pendingApprovalsIn($paused)->all();
    }

    public function storeApprovalResults(string $conversationId, array $toolResults): void
    {
        if ($toolResults === []) {
            return;
        }

        $room = $this->roomOrFail($conversationId);

        $resultIds = array_map(fn (ToolResult $result) => $result->id, $toolResults);

        $room->getConnection()->transaction(function () use ($room, $toolResults, $resultIds) {
            $paused = $this->assistantRecords($room, MessageStatus::Paused, lock: true);

            $row = $paused->first(fn (object $record) => array_intersect($this->pausedCallIds($record), $resultIds) !== []);

            if ($row === null) {
                throw new ApprovalMismatchException(
                    'The approval results do not match a paused conversation turn.',
                    $paused->first() === null ? collect() : $this->pendingApprovalsIn($paused->first()),
                );
            }

            $resolved = collect($toolResults)->keyBy(fn (ToolResult $result): string => $result->id);

            $steps = $this->decodedSteps($row)->map(function (array $step) use ($resolved): array {
                $step['tool_calls'] = array_map(function (array $toolCall) use ($resolved): array {
                    $result = $resolved->get($toolCall['id'] ?? '');

                    return $result === null || PendingApproval::isAnswered($toolCall)
                        ? $toolCall
                        // An edited approval runs the tool with different arguments than the call asked for.
                        : [...$toolCall, ...collect($result->toArray())->only(['arguments', 'result', 'denied', 'failed'])->all()];
                }, $step['tool_calls']);

                return $step;
            });

            $this->updateRecord($row, ['steps' => $this->plain($steps)]);
        });
    }

    public function conversationIdOf(Chatroom $room): string
    {
        return $room->metadata['ai']['conversation_id'] ?? (string) $room->getKey();
    }

    /**
     * Rebuild a stored user turn. Replayed messages are `earlier`, never the current requester.
     */
    protected function userMessageFrom(object $record): Message
    {
        $message = parent::userMessageFrom($record);

        if ($record->model->sender_id) {
            $message->content = RequesterEnvelope::earlier(
                $record->model->sender->display_name ?? '',
                $message->content,
            );
        }

        return $message;
    }

    /**
     * Preserve opaque provider replay data without interpreting its format.
     *
     * @return Collection<int, array{content: string, tool_calls: array, reasoning: string, replay_blocks: array, provider_tool_calls: array}>
     */
    protected function stepsFor(AgentPrompt $prompt, AgentResponse $response): Collection
    {
        $steps = parent::stepsFor($prompt, $response);

        if ($response->steps->isEmpty()) {
            return $steps;
        }

        return $steps->values()->map(fn (array $step, int $index): array => $this->stepWithReplayBlocks(
            $step,
            $response->steps->values()->get($index)->replayBlocks,
        ));
    }

    protected function pausedRowFor(string $conversationId, AgentPrompt $prompt): ?object
    {
        $room = $this->roomOrFail($conversationId);

        $decided = array_keys($prompt->approvalDecisions->all());

        $named = $this->assistantRecords($room, MessageStatus::Paused)
            ->first(fn (object $record): bool => array_intersect($this->gatedCallIds($record), $decided) !== []);

        if ($named !== null) {
            return $named;
        }

        $newest = $this->assistantRecords($room)->first();

        return $newest === null || MessageStatus::from($newest->status) !== MessageStatus::Paused ? null : $newest;
    }

    protected function resumePausedRow(string $conversationId, object $paused, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): string
    {
        $steps = $this->decodedSteps($paused);

        if (($this->decoded($paused->meta)['provider'] ?? null) !== $response->meta->provider) {
            $steps = $this->withoutReplayBlocksPreservingShape($steps);
        }

        if ($response->steps->isNotEmpty()) {
            $steps = $steps->concat($this->stepsFor($prompt, $response));
        }

        if (! $response->hasPendingApprovals()) {
            $steps = $this->withoutReplayBlocksPreservingShape($steps);
        }

        $this->updateRecord($paused, [
            'steps' => $this->plain($steps),
            'usage' => $this->plain(TextUsage::fromArray($this->decoded($paused->usage))->add($response->usage)),
            'meta' => $this->plain($this->mergedMeta($paused, $response, $exception)),
            'status' => $this->statusFor($response, $exception)->value,
        ], blank($response->text) ? $paused->content : $response->text);

        $this->closeTurn($conversationId, $response);

        return $paused->id;
    }

    /**
     * Housekeeping after a turn is written: drop stale replay data once nothing is pending, and refresh caches.
     */
    protected function closeTurn(string $conversationId, AgentResponse $response): void
    {
        $room = $this->roomOrFail($conversationId);

        if (! $response->hasPendingApprovals()) {
            $this->forgetReplayBlocks($conversationId);
        }

        $this->forgetRoom($room); // The newest agent message changed, which the history participant may depend on.
        $room->touch();
    }

    protected function forgetReplayBlocks(string $conversationId): void
    {
        $room = $this->roomOrFail($conversationId);

        $this->assistantRecords($room, MessageStatus::Paused)->each(function (object $record): void {
            $steps = $this->decodedSteps($record);

            if ($steps->every(fn (array $step): bool => $step['replay_blocks'] === [])) {
                return;
            }

            $this->updateRecord($record, ['steps' => $this->plain($this->withoutReplayBlocksPreservingShape($steps))]);
        });
    }

    /**
     * Find a chatroom by the AI SDK's conversation ID, falling back to a plain chatroom ID.
     */
    protected function room(string $conversationId): ?Chatroom
    {
        if (isset($this->rooms[$conversationId])) {
            return $this->rooms[$conversationId];
        }

        $room = Chatroom::query()->where('metadata->ai->conversation_id', $conversationId)->first()
            ?? (ctype_digit($conversationId) ? Chatroom::query()->find((int) $conversationId) : null);

        // Misses are not cached: storeConversation() may create the room later in the same request.
        return $room === null ? null : $this->rooms[$conversationId] = $room;
    }

    protected function roomOrFail(string $conversationId): Chatroom
    {
        return $this->room($conversationId)
            ?? throw (new ModelNotFoundException)->setModel(Chatroom::class, [$conversationId]);
    }

    /**
     * Whether the message currently being answered belongs to the given room.
     */
    protected function isAnswering(Chatroom $room): bool
    {
        return $this->pendingMessage?->chatroom_id === $room->getKey();
    }

    /**
     * Tag the message being answered as the turn's user message, once, so several agents can answer it.
     */
    protected function adoptPendingMessage(string $agent): string
    {
        $message = $this->pendingMessage;

        if (! isset($message->metadata['ai'])) {
            $message->forceFill(['metadata' => [
                ...($message->metadata ?? []),
                'ai' => ['role' => 'user', 'agent' => $agent, 'attachments' => [], 'status' => MessageStatus::Completed->value],
            ]])->saveQuietly();
        }

        return (string) $message->getKey();
    }

    protected function ownerParticipant(Chatroom $room, ?string $type, string|int|null $id): ?ChatParticipant
    {
        if ($type === null || $id === null) {
            return null;
        }

        return ChatParticipant::query()->firstOrCreate(
            ['chatroom_id' => $room->getKey(), 'participant_type' => $type, 'participant_id' => $id],
            ['role' => 'member'],
        );
    }

    protected function agentParticipant(Chatroom $room, string $agentClass): ChatParticipant
    {
        $participant = ChatParticipant::query()->firstOrCreate(
            ['chatroom_id' => $room->getKey(), 'reference_id' => static::agentReference($agentClass)],
            ['display_name' => class_basename($agentClass), 'role' => 'member'],
        );

        if ($participant->wasRecentlyCreated) {
            $this->forgetRoom($room);
        }

        return $participant;
    }

    protected static function agentReference(string $agentClass): string
    {
        return self::AGENT_REFERENCE_PREFIX.$agentClass;
    }

    /**
     * @return Collection<int, int>
     */
    protected function agentSenderIds(Chatroom $room): Collection
    {
        return $this->agentIdsByRoom[$room->getKey()] ??= $room->participantsWithTrashed()
            ->where('reference_id', 'like', self::AGENT_REFERENCE_PREFIX.'%')
            ->pluck('id');
    }

    /**
     * The chatroom messages visible to the AI participant, including system notices.
     *
     * @return Builder<ChatMessage>
     */
    protected function messageQuery(Chatroom $room): Builder
    {
        $participant = $this->historyParticipant($room);

        return $participant === null
            ? ChatMessage::inRoom($room)
            : ChatMessage::inRoom($room)->visibleTo($participant);
    }

    protected function historyParticipant(Chatroom $room): ?ChatParticipant
    {
        $roomId = $room->getKey();
        $agent = $this->activeAgentClass ?? '';

        if (! array_key_exists($agent, $this->historyParticipants[$roomId] ?? [])) {
            $this->historyParticipants[$roomId][$agent] = $this->resolveHistoryParticipant($room);
        }

        return $this->historyParticipants[$roomId][$agent];
    }

    private function resolveHistoryParticipant(Chatroom $room): ?ChatParticipant
    {
        if ($this->activeAgentClass !== null) {
            return $room->participant(new ChatParticipant([
                'reference_id' => static::agentReference($this->activeAgentClass),
            ]));
        }

        $lastAgentClass = ChatMessage::query()
            ->where('chatroom_id', $room->getKey())
            ->whereNotNull('metadata->ai->agent')
            ->orderByDesc('id')
            ->first()?->metadata['ai']['agent'] ?? null;

        return $room->participants()
            ->where('reference_id', 'like', self::AGENT_REFERENCE_PREFIX.'%')
            ->when($lastAgentClass !== null, fn ($query) => $query->where('reference_id', static::agentReference($lastAgentClass)))
            ->orderByDesc('created_at')
            ->first();
    }

    protected function forgetRoom(Chatroom $room): void
    {
        unset($this->agentIdsByRoom[$room->getKey()], $this->historyParticipants[$room->getKey()]);
    }

    protected function flushCaches(): void
    {
        $this->rooms = $this->agentIdsByRoom = $this->historyParticipants = [];
    }

    /**
     * Whether an older, completed assistant turn is replayed as plain text (opt-in per agent class via
     * `le-chat.ai.collapse_tool_history`). The newest turn and paused turns always keep their tool calls.
     */
    protected function collapses(object $record, ?string $newestAssistantId): bool
    {
        return $record->id !== $newestAssistantId
            && $record->status === MessageStatus::Completed->value
            && in_array($this->activeAgentClass, config('le-chat.ai.collapse_tool_history', []), true);
    }

    /**
     * @return list<AssistantMessage>
     */
    protected function textOnlyTurn(object $record): array
    {
        return blank($record->content) ? [] : [new AssistantMessage($record->content)];
    }

    /**
     * The room's SDK-written assistant turns, newest first.
     *
     * @return Collection<int, object>
     */
    protected function assistantRecords(Chatroom $room, ?MessageStatus $status = null, bool $lock = false): Collection
    {
        $agentIds = $this->agentSenderIds($room);

        return $this->messageQuery($room)
            ->where('metadata->ai->role', 'assistant')
            ->when($status, fn ($query) => $query->where('metadata->ai->status', $status->value))
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->orderByDesc('id')
            ->get()
            ->map(fn (ChatMessage $message) => $this->recordFrom($message, $agentIds));
    }

    /**
     * Shape a chat message like the SDK's stored row, which its inherited helpers read.
     *
     * @param  Collection<int, int>  $agentIds
     */
    protected function recordFrom(ChatMessage $message, Collection $agentIds): object
    {
        $ai = $message->metadata['ai'] ?? [];

        $role = $ai['role'] ?? ($agentIds->contains($message->sender_id) ? 'assistant' : 'user');

        // A reply that was not written by the SDK has no steps, so it is replayed as a single text step.
        $steps = $ai['steps'] ?? ($role === 'assistant'
            ? [['content' => $message->message, 'tool_calls' => [], 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []]]
            : []);

        return (object) [
            'id' => (string) $message->getKey(),
            'role' => $role,
            'content' => $message->message,
            'attachments' => json_encode($ai['attachments'] ?? []),
            'steps' => json_encode($steps),
            'usage' => json_encode($ai['usage'] ?? []),
            'meta' => json_encode($ai['meta'] ?? []),
            'status' => $ai['status'] ?? MessageStatus::Completed->value,
            'created_at' => $message->created_at,
            'model' => $message,
        ];
    }

    /**
     * @param  array<string, mixed>  $changes  `metadata.ai` keys to overwrite
     */
    protected function updateRecord(object $record, array $changes, ?string $content = null): void
    {
        /** @var ChatMessage $message */
        $message = $record->model;

        $metadata = $message->metadata ?? [];
        $metadata['ai'] = [...($metadata['ai'] ?? []), ...$changes];

        $message->forceFill(['metadata' => $metadata]);

        if ($content !== null) {
            $message->setAttribute('message', $content);
        }

        $message->save();

        // Later reads in this request see the stored values.
        foreach ($changes as $key => $value) {
            $record->{$key} = is_string($value) ? $value : json_encode($value);
        }
        $record->content = $content ?? $record->content;
    }

    /**
     * Turn collections and value objects into plain arrays that JSON-encode cleanly.
     *
     * @return array<mixed>
     */
    protected function plain(mixed $value): array
    {
        return json_decode(json_encode($value), true) ?? [];
    }

    /**
     * @param  Collection<int, array{content: string, tool_calls: array, reasoning: string, replay_blocks: array, provider_tool_calls: array}>  $steps
     * @return Collection<int, array{content: string, tool_calls: array, reasoning: string, replay_blocks: array, provider_tool_calls: array}>
     */
    protected function withoutReplayBlocksPreservingShape(Collection $steps): Collection
    {
        return $steps->map(fn (array $step): array => $this->stepWithoutReplayBlocks($step));
    }

    /**
     * @param  array{content: string, tool_calls: array, reasoning: string, replay_blocks: array, provider_tool_calls: array}  $step
     * @return array{content: string, tool_calls: array, reasoning: string, replay_blocks: array, provider_tool_calls: array}
     */
    protected function stepWithoutReplayBlocks(array $step): array
    {
        $step['replay_blocks'] = [];

        return $step;
    }

    /**
     * @param  array{content: string, tool_calls: array, reasoning: string, replay_blocks: array, provider_tool_calls: array}  $step
     * @return array{content: string, tool_calls: array, reasoning: string, replay_blocks: array, provider_tool_calls: array}
     */
    protected function stepWithReplayBlocks(array $step, array $replayBlocks): array
    {
        $step['replay_blocks'] = $replayBlocks;

        return $step;
    }
}
