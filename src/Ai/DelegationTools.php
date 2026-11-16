<?php

namespace Mmedia\LeChat\Ai;

use Closure;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Tools\AgentTool;
use Mmedia\LeChat\Features;
use ReflectionMethod;
use ReflectionProperty;

/** @internal Adapts SDK tools only while Le Chat owns an agent invocation. */
class DelegationTools
{
    public static function within(object $agent, Closure $callback): mixed
    {
        if (! Features::enabled(Features::aiSdkIntegration()) || ! self::usesPromptable($agent)) {
            return $callback();
        }

        $property = new ReflectionProperty($agent, 'runtimeTools');
        $previous = $property->getValue($agent);
        $resolved = null;
        $method = 'withTools';
        $agent->{$method}(static function (array $declared) use ($previous, &$resolved): array {
            return $resolved ??= self::normalize($previous === null ? $declared : $previous($declared));
        });

        try {
            return $callback();
        } finally {
            $property->setValue($agent, $previous);
        }
    }

    public static function normalize(iterable $tools, string $scope = 'root'): array
    {
        return array_map(
            fn (mixed $tool): mixed => match (true) {
                $tool instanceof AgentTool => new DelegatingAgentTool($tool->agent(), $scope),
                $tool instanceof Agent => new DelegatingAgentTool($tool, $scope),
                $tool instanceof ToolSearch => $tool->withTools(self::normalize($tool->tools, $scope)),
                default => $tool,
            },
            [...$tools],
        );
    }

    public static function forAgent(Agent $agent): array
    {
        $runtime = self::usesPromptable($agent)
            ? (new ReflectionMethod($agent, 'resolveAgentTools'))->invoke($agent)
            : null;

        return $runtime ?? ($agent instanceof HasTools ? [...$agent->tools()] : []);
    }

    public static function delegates(iterable $tools): array
    {
        $delegates = [];
        foreach ($tools as $tool) {
            if ($tool instanceof DelegatingAgentTool) {
                $delegates[] = $tool;
            } elseif ($tool instanceof ToolSearch) {
                $delegates = [...$delegates, ...self::delegates($tool->tools)];
            }
        }

        return $delegates;
    }

    private static function usesPromptable(object $agent): bool
    {
        return $agent instanceof Agent && in_array(Promptable::class, class_uses_recursive($agent), true);
    }
}
