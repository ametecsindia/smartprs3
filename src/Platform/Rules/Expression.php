<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

use SmartPRS\Platform\Spx\Sandbox;

/**
 * The seam between the rule engine and SPX (spine S5, Part VII §5).
 *
 * The rule engine is everything *above* the expression language: precedence,
 * conflict policy, the action catalogue, the trace. It never parses, never
 * tokenises and never interprets — it asks an Expression three questions:
 *
 *   source()   what the author wrote, for the trace and the builder
 *   evaluate() the value for this subject, with the substituted inputs
 *   claims()   the decidable-fragment reading, for the conflict detector
 *
 * `SpxExpression` is the production implementation, written against the three
 * published SPX signatures. A test or a seeded fixture may supply its own —
 * that is the whole point of keeping this three methods wide.
 *
 * ponytail: no visitor, no compiler hook, no expression cache. SPX owns
 * compilation and caching; duplicating either here would give two answers to
 * "what does this rule mean", which is the SmartPRS 2 failure this replaces.
 */
interface Expression
{
    /** The authored SPX source. Retained for display and diffing (R-VII-009). */
    public function source(): string;

    /**
     * The node, depth and time budgets belong to SPX's evaluator (§7.7,
     * §10.1b), so the engine passes a FRESH per-evaluation Sandbox rather than
     * counting anything itself. A breach comes back as a named error value —
     * ERR_BUDGET_NODES or ERR_BUDGET_TIME — and the engine fails the rule.
     */
    public function evaluate(EvaluationContext $context, ?Sandbox $sandbox = null): Evaluated;

    /**
     * The conjunction of atomic constraints this expression asserts, for the
     * publish-time satisfiability check (§11.4).
     *
     * NULL means "outside the decidable fragment" — an `agg.*` call, a
     * `MATCHES`, an OR, arithmetic the solver does not model. NULL is the
     * fail-safe answer: the detector then treats the pair as conflicting
     * rather than assuming disjointness it cannot prove (R-VII-060).
     *
     * @return list<Claim>|null
     */
    public function claims(): ?array;
}
