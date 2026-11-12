<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

/**
 * §4.2: "A rule set is the set of published rules resolved for
 * (tenant, decision_point, subject, as_of_date). It is computed, not stored."
 *
 * This is the input side of that sentence — the candidate rules a repository
 * loaded for one decision point, before scope and effective dating narrow them.
 * `Resolver` computes the resolved set.
 *
 * ponytail: a list with three filters on it. No repository interface, no lazy
 * collection, no pack abstraction — a pack is a named list of these, and naming
 * a list does not need a class until something varies.
 */
final readonly class RuleSet
{
    /** @param list<Rule> $rules */
    private function __construct(
        public string $decisionPoint,
        public array $rules,
    ) {
    }

    public static function of(string $decisionPoint, Rule ...$rules): self
    {
        foreach ($rules as $rule) {
            if ($rule->decisionPoint !== $decisionPoint) {
                throw new \InvalidArgumentException(
                    "Rule {$rule->code} belongs to decision point '{$rule->decisionPoint}', "
                    . "not '{$decisionPoint}'. A decision point fixes the symbol table and the "
                    . 'action allow-list, so rules from two of them are not one set (§4.1).'
                );
            }
        }

        return new self($decisionPoint, array_values($rules));
    }

    /** Only `published` is evaluated (§4.1). */
    public function published(): self
    {
        return new self($this->decisionPoint, array_values(array_filter(
            $this->rules,
            static fn (Rule $r): bool => $r->isPublished(),
        )));
    }

    /**
     * The simulator evaluates drafts against production data without publishing
     * them (R-VII-059). That is the only place this widening is legitimate.
     */
    public function includingDrafts(): self
    {
        return new self($this->decisionPoint, array_values(array_filter(
            $this->rules,
            static fn (Rule $r): bool => in_array($r->status, ['published', 'draft', 'testing'], true),
        )));
    }

    public function with(Rule ...$rules): self
    {
        return self::of($this->decisionPoint, ...[...$this->rules, ...$rules]);
    }

    public function get(string $code): ?Rule
    {
        foreach ($this->rules as $rule) {
            if ($rule->code === $code) {
                return $rule;
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->rules);
    }
}
