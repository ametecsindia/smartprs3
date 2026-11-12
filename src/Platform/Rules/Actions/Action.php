<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules\Actions;

use SmartPRS\Platform\Rules\EvaluationContext;
use SmartPRS\Platform\Rules\Evaluated;
use SmartPRS\Platform\Rules\Expression;
use SmartPRS\Platform\Spx\Sandbox;

/**
 * One entry in a rule's ordered `then` list, as authored: an action code from
 * the closed catalogue, and its parameters.
 *
 * A parameter value is a literal, or an Expression to be resolved against the
 * subject at evaluation time (§6.2: "a parameter marked `expr` accepts an SPX
 * expression of that type, a literal, or a formula reference"). Both arrive here
 * the same way and both leave resolved, which is what §6.3 hashes.
 */
final readonly class Action
{
    /** @param array<string, mixed|Expression> $parameters */
    public function __construct(
        public string $code,
        public array $parameters = [],
    ) {
        ActionCatalogue::assertParameters($code, $parameters);
    }

    public function module(): string
    {
        return ActionCatalogue::module($this->code);
    }

    public function isMoneyAffecting(): bool
    {
        return ActionCatalogue::isMoneyAffecting($this->code);
    }

    /**
     * Resolve every parameter. Returns the resolved map, or the first Evaluated
     * carrying an error — §5.8 poisons the containing expression, so a bad
     * parameter fails the evaluation rather than emitting a half-built action.
     *
     * @return array{0: array<string, mixed>, 1: Evaluated|null}
     */
    public function resolve(EvaluationContext $context, ?Sandbox $sandbox = null): array
    {
        $resolved = [];

        foreach ($this->parameters as $name => $value) {
            if (!$value instanceof Expression) {
                $resolved[$name] = $value;

                continue;
            }

            $evaluated = $value->evaluate($context, $sandbox);

            if ($evaluated->isError()) {
                return [[], $evaluated];
            }

            $resolved[$name] = $evaluated->value;
        }

        ksort($resolved);

        return [$resolved, null];
    }

    /** The authored form, for the rendered trace: `APPLY_DAY_PENALTY(fraction = 0.5d, …)`. */
    public function sourceForm(): string
    {
        $parts = [];

        foreach ($this->parameters as $name => $value) {
            $parts[] = $name . ' = ' . ($value instanceof Expression
                ? $value->source()
                : \SmartPRS\Platform\Rules\Values::render($value));
        }

        return $this->code . '(' . implode(', ', $parts) . ')';
    }
}
