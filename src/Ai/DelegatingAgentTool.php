<?php

namespace Mmedia\LeChat\Ai;

use Generator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\Request;
use LogicException;
use Mmedia\LeChat\Contracts\IncludesDelegatedToolResults;
use Stringable;

/** Delegate with isolated history and approvals owned by the parent chat turn. */
class DelegatingAgentTool extends AgentTool
{
    public function __construct(Agent $agent, private string $scope = 'root')
    {
        parent::__construct($agent);
    }

    public function description(): Stringable|string
    {
        $description = (string) parent::description();
        if ($this->agent instanceof CanActAsTool) {
            $description = rtrim($description).' This sub-agent runs in isolation and cannot access the parent conversation history. Pass a clear, self-contained task.';
        }
        if ($this->agent instanceof HasStructuredOutput) {
            $description = rtrim($description).' Its structured result is returned as JSON using the declared schema.';
        }
        if ($this->agent instanceof IncludesDelegatedToolResults) {
            $description = rtrim($description).' Its JSON result includes the summary, structured output when available, and actual child tool results.';
        }

        return $description;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()
                ->min(1)
                ->description('A clear, self-contained task. The child cannot access the parent conversation history.')
                ->required(),
        ];
    }

    public function shouldRequestApproval(Request $request): ?Approval
    {
        $runs = app(DelegatedRuns::class);
        $key = $this->key($request);
        $run = $runs->prepare($this->agent, $request, $key);

        return $run['status'] === 'complete' ? null : Approval::required($runs->reason($key));
    }

    public function advance(Request $request): bool
    {
        return app(DelegatedRuns::class)->advance($this->agent, $request, $this->key($request));
    }

    public function handle(Request $request): string
    {
        return app(DelegatedRuns::class)->result($this->key($request));
    }

    /**
     * Return buffered child output to the SDK, which emits the final tool-result event.
     *
     * @return Generator<int, never, mixed, string>
     */
    public function stream(Request $request): Generator
    {
        yield from [];

        return $this->handle($request);
    }

    private function key(Request $request): string
    {
        $id = $request->toolCallId();
        if ($id === null || $id === '' || str_contains($id, '/')) {
            throw new LogicException('A delegated run requires a stable tool call identifier.');
        }

        return $this->scope.'/'.$id;
    }
}
