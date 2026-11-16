<?php

namespace Mmedia\LeChat\Ai;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\RepairToolCalls;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Attributes\UseSmartestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Promptable;
use Laravel\Ai\ToolChoice;
use ReflectionClass;

/** @internal A child conversation with explicit history and no automatic persistence. */
class DelegatedAgent implements Agent, Conversational, HasMiddleware, HasProviderOptions, HasTools
{
    use Promptable;

    public function __construct(
        protected Agent $source,
        private array $history,
        private string $scope,
    ) {}

    public static function wrap(Agent $source, array $history, string $scope): self
    {
        if ($source instanceof HasStructuredOutput) {
            return RepairToolCalls::isAppliedTo($source)
                ? new #[RepairToolCalls] class($source, $history, $scope) extends StructuredDelegatedAgent {}
            : new StructuredDelegatedAgent($source, $history, $scope);
        }

        return RepairToolCalls::isAppliedTo($source)
            ? new #[RepairToolCalls] class($source, $history, $scope) extends DelegatedAgent {}
        : new self($source, $history, $scope);
    }

    public function instructions(): string
    {
        return (string) $this->source->instructions();
    }

    public function messages(): iterable
    {
        return $this->history;
    }

    public function tools(): iterable
    {
        return DelegationTools::normalize(
            DelegationTools::forAgent($this->source),
            $this->scope,
        );
    }

    public function middleware(): array
    {
        return $this->source instanceof HasMiddleware ? $this->source->middleware() : [];
    }

    public function provider(): Lab|array|string|null
    {
        return $this->configuration('provider', Provider::class);
    }

    public function model(): ?string
    {
        return $this->configuration('model', Model::class);
    }

    public function timeout(): int
    {
        return $this->configuration('timeout', Timeout::class) ?? 60;
    }

    public function maxSteps(): ?int
    {
        return TextGenerationOptions::forAgent($this->source)->maxSteps;
    }

    public function maxTokens(): ?int
    {
        return TextGenerationOptions::forAgent($this->source)->maxTokens;
    }

    public function temperature(): ?float
    {
        return TextGenerationOptions::forAgent($this->source)->temperature;
    }

    public function topP(): ?float
    {
        return TextGenerationOptions::forAgent($this->source)->topP;
    }

    public function toolChoice(): ?ToolChoice
    {
        return TextGenerationOptions::forAgent($this->source)->toolChoice;
    }

    public function providerOptions(Lab|string $provider): array
    {
        return $this->source instanceof HasProviderOptions ? $this->source->providerOptions($provider) : [];
    }

    protected function getDefaultModelFor(TextProvider $provider): string
    {
        return match (true) {
            $this->attribute(UseSmartestModel::class) !== null => $provider->smartestTextModel(),
            $this->attribute(UseCheapestModel::class) !== null => $provider->cheapestTextModel(),
            default => $provider->defaultTextModel(),
        };
    }

    private function attribute(string $class): ?object
    {
        $attributes = (new ReflectionClass($this->source))->getAttributes($class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    private function configuration(string $method, string $attribute): mixed
    {
        return is_callable([$this->source, $method])
            ? $this->source->{$method}()
            : $this->attribute($attribute)?->value;
    }
}
