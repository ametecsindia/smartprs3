<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

use SmartPRS\Platform\Rules\Actions\AppliedAction;

/**
 * A stored test case attached to a rule — the `rule_test_cases` row of §11.6.
 *
 * This is how a customer's rule set gets a regression suite without anyone
 * writing PHP. Cases are captured from the simulator with one click ("save this
 * as a test"), which records the fixture and the observed outcome as the
 * expectation.
 *
 * PUBLISHING RUNS EVERY CASE at the affected decision point, not just the cases
 * of the rule being published — because a new rule changes precedence for its
 * siblings, which is exactly the failure mode a per-rule suite would miss. A
 * failing case BLOCKS publication and shows the diff plus the full trace (§8.3).
 *
 * R-VII-062: every seeded rule Ametecs ships carries at least three cases — one
 * matching, one not matching, one boundary.
 */
final readonly class RuleTestCase
{
    /**
     * @param list<array{0: string, 1: array<string, mixed>}> $expectActions
     *        ordered [action_code, resolved parameters]; parameters are compared
     *        canonically, so 1/2 days never quietly equals 0.5
     */
    public function __construct(
        public string $name,
        public string $ruleCode,
        public EvaluationContext $context,
        public bool $expectMatch,
        public array $expectActions = [],
        public ?\DateTimeImmutable $asOf = null,
        public ?string $expectErrorCode = null,
        public bool $isSeeded = false,
    ) {
    }

    /**
     * @return array{passed: bool, message: string, trace: DecisionTrace}
     */
    public function run(string $decisionPoint, RuleSet $set, ?Engine $engine = null): array
    {
        $result = (new Simulator($engine ?? new Engine()))
            ->run($decisionPoint, $set, $this->context, $this->asOf, includeDrafts: true);

        $failures = [];

        if ($this->expectErrorCode !== null) {
            $actual = $result->trace->error['code'] ?? null;

            if ($actual !== $this->expectErrorCode) {
                $failures[] = "expected error {$this->expectErrorCode}, got " . ($actual ?? 'no error');
            }

            return $this->verdict($failures, $result->trace);
        }

        if ($result->failed()) {
            $failures[] = 'evaluation failed: ' . ($result->trace->error['code'] ?? '')
                . ' — ' . ($result->trace->error['message'] ?? '');

            return $this->verdict($failures, $result->trace);
        }

        $emittedBy = array_values(array_filter(
            $result->actions,
            fn (AppliedAction $a): bool => $a->emittedBy['rule_code'] === $this->ruleCode,
        ));

        if ($this->expectMatch && $emittedBy === []) {
            $failures[] = "expected {$this->ruleCode} to match; it did not — "
                . ($result->trace->reasonFor($this->ruleCode) ?? 'it was not a candidate');
        }

        if (!$this->expectMatch && $emittedBy !== []) {
            $failures[] = "expected {$this->ruleCode} not to match; it emitted "
                . count($emittedBy) . ' action(s)';
        }

        $expected = Values::json($this->expectActions);
        $actual = Values::json(array_map(
            static fn (AppliedAction $a): array => [$a->actionCode, $a->parameters],
            $emittedBy,
        ));

        if ($this->expectActions !== [] && $expected !== $actual) {
            $failures[] = "actions differ\n      expected " . $expected . "\n      actual   " . $actual;
        }

        return $this->verdict($failures, $result->trace);
    }

    /**
     * The publish gate. Every case of every rule at the decision point, not just
     * the rule being published.
     *
     * @param  list<self>  $cases
     * @return list<array{case: self, passed: bool, message: string, trace: DecisionTrace}>
     *         the failures only — an empty list means publication may proceed
     */
    public static function runAll(string $decisionPoint, RuleSet $set, array $cases, ?Engine $engine = null): array
    {
        $engine ??= new Engine();
        $failures = [];

        foreach ($cases as $case) {
            $outcome = $case->run($decisionPoint, $set, $engine);

            if (!$outcome['passed']) {
                $failures[] = ['case' => $case, ...$outcome];
            }
        }

        return $failures;
    }

    /**
     * @param  list<string>  $failures
     * @return array{passed: bool, message: string, trace: DecisionTrace}
     */
    private function verdict(array $failures, DecisionTrace $trace): array
    {
        return [
            'passed' => $failures === [],
            'message' => $failures === []
                ? $this->name . ': ok'
                : $this->name . ': ' . implode('; ', $failures),
            'trace' => $trace,
        ];
    }
}
