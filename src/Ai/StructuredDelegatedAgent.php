<?php

namespace Mmedia\LeChat\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;
use LogicException;

/** @internal Retains the source child's structured response contract. */
class StructuredDelegatedAgent extends DelegatedAgent implements HasStructuredOutput
{
    public function schema(JsonSchema $schema): array
    {
        if (! $this->source instanceof HasStructuredOutput) {
            throw new LogicException('A structured delegate requires a structured source agent.');
        }

        return $this->source->schema($schema);
    }
}
