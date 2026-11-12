<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

use SmartPRS\Platform\Rules\Actions\AppliedAction;

/**
 * Spine Law 4 for rules, as Part VII §9 specifies it: machine-readable first,
 * rendered second.
 *
 * SmartPRS 2's only explanation mechanism is a free-text calculation note, and
 * the payslip recovers Paid Days and LOP from it BY REGEX — printing
 * `daysInMonth / 0` whenever the sentence is absent. So the structure is the
 * artefact and the prose is a projection of it, never the other way round.
 *
 * The trace names every rule that was considered and, for each one that lost,
 * why it lost. That is what makes a half-day defensible to the employee whose
 * half-day it is.
 */
final readonly class DecisionTrace
{
    public const MATCHED = 'matched';
    public const NO_MATCH = 'no_match';
    public const FAILED = 'failed';

    /**
     * @param list<CandidateTrace>  $candidates
     * @param list<AppliedAction>   $actions
     * @param array<string, int>    $counters
     * @param array<string, string>|null $error code, rule_code, node_path, message
     */
    public function __construct(
        public string $traceId,
        public string $tenantId,
        public string $decisionPoint,
        public array $subject,
        public \DateTimeImmutable $asOf,
        public string $engineVersion,
        public string $status,
        public string $inputDigest,
        public array $candidates,
        public array $actions,
        public array $counters,
        public ?array $error = null,
    ) {
    }

    /**
     * A trace id derived from what the trace explains, not from a random source.
     *
     * ponytail: §9.2 says uuid7. A uuid7 carries a clock and a random tail, and
     * §7.4 forbids both inside evaluation — the determinism test asserts the
     * serialised (actions, trace) is byte-identical across runs. A content hash
     * keeps that property and stays a stable key. Swap in an injected
     * IdGenerator at the persistence boundary if the storage layer wants uuid7.
     */
    public static function idFor(string $tenantId, string $decisionPoint, EvaluationContext $context): string
    {
        return substr(hash('sha256', implode('|', [
            $tenantId,
            $decisionPoint,
            $context->subjectType,
            $context->subjectId,
            $context->subjectDate->format('Y-m-d'),
            $context->digest(),
        ])), 0, 32);
    }

    public function failed(): bool
    {
        return $this->status === self::FAILED;
    }

    /** @return list<CandidateTrace> */
    public function matched(): array
    {
        return array_values(array_filter($this->candidates, static fn (CandidateTrace $c): bool => $c->matched()));
    }

    /** @return list<CandidateTrace> every rule that did not win, with its reason */
    public function losers(): array
    {
        return array_values(array_filter($this->candidates, static fn (CandidateTrace $c): bool => $c->lost()));
    }

    public function reasonFor(string $ruleCode): ?string
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->ruleCode === $ruleCode) {
                return $candidate->reason;
            }
        }

        return null;
    }

    public function outcomeFor(string $ruleCode): ?string
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->ruleCode === $ruleCode) {
                return $candidate->outcome;
            }
        }

        return null;
    }

    /** The §9.3 "How was this calculated?" view. */
    public function render(): string
    {
        $lines = [
            'How was this calculated?',
            '',
            '  Decision point      ' . $this->decisionPoint,
            '  Subject             ' . $this->subject['type'] . ':' . $this->subject['id'],
            '  Date                ' . $this->subject['date'],
            '  Resolved as at      ' . $this->asOf->format('Y-m-d'),
            '  Outcome             ' . strtoupper($this->status),
            '',
        ];

        foreach ($this->candidates as $candidate) {
            $lines[] = $candidate->render();
            $lines[] = '';
        }

        if ($this->actions !== []) {
            $lines[] = '  ACTIONS';

            foreach ($this->actions as $action) {
                $lines[] = sprintf(
                    '    %d. %s   from %s   [%s]  fp %s',
                    $action->sequence,
                    $action->render(),
                    $action->emittedBy['rule_code'],
                    $action->effectClass,
                    substr($action->fingerprint, 0, 12),
                );
            }

            $lines[] = '';
        }

        if ($this->error !== null) {
            // The same slot carries an evaluation failure and a §7.6 employment
            // guard. The status distinguishes them: a guard is a no_match with a
            // named reason, never a failure and never a default outcome.
            $lines[] = $this->failed()
                ? '  FAILED  ' . $this->error['code']
                    . ' in rule ' . ($this->error['rule_code'] ?? '-')
                    . ' at ' . ($this->error['node_path'] ?? '-')
                : '  GUARD   ' . $this->error['code'];
            $lines[] = '          ' . ($this->error['message'] ?? '');
            $lines[] = '';
        }

        $counters = [];

        foreach ($this->counters as $name => $value) {
            $counters[] = $value . ' ' . str_replace('_', ' ', $name);
        }

        $lines[] = '  Counters            ' . implode(' · ', $counters);
        $lines[] = '  Engine              ' . $this->engineVersion;
        $lines[] = '  Input digest        ' . substr($this->inputDigest, 0, 16);

        return implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'trace_id' => $this->traceId,
            'tenant_id' => $this->tenantId,
            'decision_point' => $this->decisionPoint,
            'subject' => $this->subject,
            'as_of' => $this->asOf->format('Y-m-d'),
            'engine_version' => $this->engineVersion,
            'status' => $this->status,
            'input_digest' => $this->inputDigest,
            'candidates' => array_map(static fn (CandidateTrace $c): array => $c->toArray(), $this->candidates),
            'actions' => array_map(static fn (AppliedAction $a): array => $a->toArray(), $this->actions),
            'counters' => $this->counters,
            'error' => $this->error,
        ], static fn (mixed $v): bool => $v !== null);
    }

    public function toJson(): string
    {
        return Values::json($this->toArray());
    }

    /**
     * R-VII-038 asserts determinism by hashing the serialised (actions, trace)
     * against a committed golden value. `micros` is wall time and is the one
     * counter that legitimately varies, so it is dropped here and only here.
     */
    public function deterministicJson(): string
    {
        $array = $this->toArray();
        unset($array['counters']['micros']);

        return Values::json($array);
    }

    public function __toString(): string
    {
        return $this->render();
    }
}
