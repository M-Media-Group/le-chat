<?php

namespace Mmedia\LeChat\Ai;

use Generator;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\Request;
use LogicException;

/** Delegate with isolated history and approvals owned by the parent chat turn. */
class DelegatingAgentTool extends AgentTool
{
    public function __construct(Agent $agent, private string $scope = 'root')
    {
        parent::__construct($agent);
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
