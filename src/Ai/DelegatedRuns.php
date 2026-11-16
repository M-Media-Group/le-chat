<?php

namespace Mmedia\LeChat\Ai;

use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Tools\Request;
use LogicException;
use Mmedia\LeChat\Models\ChatMessage;

/** @internal Child transcripts live on the owning assistant turn, never in separate chatrooms. */
class DelegatedRuns
{
    private array $runs = [];

    private ?ChatMessage $message = null;

    public function load(?ChatMessage $message = null): void
    {
        $this->message = $message;
        $this->runs = $message?->metadata['ai']['delegations'] ?? [];
    }

    public function snapshot(): array
    {
        return $this->runs;
    }

    public function prepare(Agent $agent, Request $request, string $key): array
    {
        if (isset($this->runs[$key])) {
            $this->assertMatches($agent, $request, $key);
            if (! in_array($this->runs[$key]['status'], ['paused', 'complete'], true)) {
                throw new LogicException('The delegated resume outcome is uncertain and requires reconciliation.');
            }

            return $this->runs[$key];
        }

        $task = $request['task'];
        if (! is_string($task) || $task === '') {
            throw new LogicException('A delegated task must be a non-empty string.');
        }
        $this->runs[$key] = [
            'agent' => $agent::class, 'task' => $task, 'status' => 'running',
            'messages' => [$this->encode(new UserMessage($task))], 'pending' => [],
            'revision' => 0, 'text' => '', 'provider' => null, 'model' => null,
        ];
        $response = DelegatedAgent::wrap($agent, [], $key)->prompt($task);
        $this->record($key, $response);

        return $this->runs[$key];
    }

    public function reason(string $key): string
    {
        return 'Approve only these delegated actions: '.json_encode(
            $this->runs[$key]['pending'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    public function result(string $key): string
    {
        if (($this->runs[$key]['status'] ?? null) !== 'complete') {
            throw new LogicException('The delegated run must finish its approvals before the parent can resume.');
        }

        return $this->runs[$key]['text'];
    }

    public function advance(Agent $agent, Request $request, string $key): bool
    {
        $this->assertMatches($agent, $request, $key);
        $run = $this->runs[$key];
        if ($run['status'] === 'complete') {
            return true;
        }
        if ($run['status'] !== 'paused') {
            throw new LogicException('The delegated resume outcome is uncertain and requires reconciliation.');
        }

        $child = DelegatedAgent::wrap($agent, array_map($this->decode(...), $run['messages']), $key);
        $ready = $this->advanceTools($child, $run['pending'], array_fill_keys(array_column($run['pending'], 'id'), true));
        if (! $ready) {
            foreach ($this->runs[$key]['pending'] as &$pending) {
                $nestedKey = $key.'/'.$pending['id'];
                if (isset($this->runs[$nestedKey]) && $this->runs[$nestedKey]['status'] === 'paused') {
                    $pending['reason'] = $this->reason($nestedKey);
                }
            }
            unset($pending);
            $this->runs[$key]['revision']++;
            $this->checkpoint();

            return false;
        }

        $this->runs[$key]['status'] = 'running';
        $this->checkpoint();
        $response = $child->prompt(
            Decisions::from(array_fill_keys(array_column($run['pending'], 'id'), true)),
            provider: $run['provider'], model: $run['model'],
        );
        $this->record($key, $response);

        return $this->runs[$key]['status'] === 'complete';
    }

    public function advanceTools(Agent $agent, array $pending, array $decisions): bool
    {
        $tools = collect(DelegationTools::delegates(DelegationTools::forAgent($agent)));
        $ready = true;
        foreach ($pending as $approval) {
            $approval = (array) $approval;
            $decision = $decisions[$approval['id']] ?? false;
            if ($decision !== true && ! (is_object($decision) && $decision->isApproved())) {
                continue;
            }
            $tool = $tools->first(fn (mixed $tool): bool => $tool instanceof DelegatingAgentTool && $tool->name() === ($approval['tool'] ?? $approval['name'] ?? null));
            if ($tool instanceof DelegatingAgentTool) {
                $ready = $tool->advance(new Request($approval['arguments'], $approval['id'])) && $ready;
            }
        }

        return $ready;
    }

    private function assertMatches(Agent $agent, Request $request, string $key): void
    {
        $run = $this->runs[$key] ?? null;
        if ($run === null || $run['agent'] !== $agent::class || $run['task'] !== $request['task']) {
            throw new LogicException('The delegated task no longer matches its saved execution.');
        }
    }

    private function record(string $key, AgentResponse $response): void
    {
        $run = &$this->runs[$key];
        $run['messages'] = [...$run['messages'], ...array_map($this->encode(...), $response->messages->all())];
        $run['pending'] = $response->pendingApprovals->map(fn ($approval): array => [
            'id' => $approval->id, 'tool' => $approval->tool, 'arguments' => $approval->arguments, 'reason' => $approval->reason,
        ])->all();
        $run['status'] = $response->hasPendingApprovals() ? 'paused' : 'complete';
        $run['text'] = $response->text;
        $run['provider'] = $response->meta->provider;
        $run['model'] = $response->meta->model;
        $run['revision']++;
        $this->checkpoint();
    }

    private function checkpoint(): void
    {
        if ($this->message === null) {
            return;
        }
        $this->message->refresh();
        $metadata = $this->message->metadata->getArrayCopy();
        $metadata['ai']['delegations'] = $this->runs;
        foreach ($metadata['ai']['steps'] ?? [] as $stepIndex => $step) {
            foreach ($step['tool_calls'] ?? [] as $callIndex => $call) {
                $key = 'root/'.$call['id'];
                if (($this->runs[$key]['status'] ?? null) === 'paused') {
                    $metadata['ai']['steps'][$stepIndex]['tool_calls'][$callIndex]['approval_reason'] = $this->reason($key);
                }
            }
        }
        $this->message->forceFill(['metadata' => $metadata])->saveQuietly();
    }

    private function encode(Message $message): array
    {
        return [
            'role' => $message->role->value, 'content' => $message->content,
            ...($message instanceof AssistantMessage ? [
                'tool_calls' => $message->toolCalls->map->toArray()->all(),
                'replay_blocks' => $message->replayBlocks, 'replay_provider' => $message->replayBlocksProvider,
            ] : []),
            ...($message instanceof ToolResultMessage ? ['tool_results' => $message->toolResults->map->toArray()->all()] : []),
        ];
    }

    private function decode(array $message): Message
    {
        return match ($message['role']) {
            'assistant' => new AssistantMessage($message['content'] ?? '', collect($message['tool_calls'])->map(ToolCall::fromArray(...)), $message['replay_blocks'], $message['replay_provider']),
            'tool_result' => new ToolResultMessage(collect($message['tool_results'])->map(ToolResult::fromArray(...))),
            default => new Message($message['role'], $message['content']),
        };
    }
}
