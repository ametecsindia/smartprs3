<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Migration;

/**
 * THE ROLLBACK PLAN, AND THE PROOF IT WORKS — Part XXXII Phase 10.
 *
 * *"The rollback plan is in place AND TESTED."* Two clauses, and the second is
 * the whole requirement: a rollback plan that has never been executed is a
 * document, and the moment it is needed is the moment nobody can afford to
 * discover what it left out.
 *
 * So `isReady()` demands a rehearsal with a date and a duration, and the duration
 * matters — a rollback that takes nine hours is not a rollback for a payroll
 * cutover on the 28th, and knowing that in advance is the difference between a
 * decision and a discovery.
 *
 * SIGN-OFF IS A PERSON. `signOff()` takes a name, a role and a date, and there is
 * no method that marks a tenant cut over without one, because "the customer
 * signs off" is a fact about a person and not a status somebody sets.
 */
final class CutoverPlan
{
    private ?array $rehearsal = null;
    private ?array $signOff = null;

    /** @var list<string> */
    private array $steps = [];

    public function __construct(
        public readonly string $tenant,
        public readonly \DateTimeImmutable $plannedFor,
    ) {
    }

    public function step(string $description, string $owner, string $rollbackAction): void
    {
        if (trim($rollbackAction) === '') {
            throw new \InvalidArgumentException(
                "Cutover step '{$description}' declares no way back. A step with no rollback action is a "
                . 'point of no return that nobody labelled as one, and it is found at 2am.'
            );
        }

        $this->steps[] = "{$description} (owner {$owner}; back out by: {$rollbackAction})";
    }

    /** The rehearsal. Without it there is a document, not a plan. */
    public function rehearse(\DateTimeImmutable $on, int $durationMinutes, bool $succeeded, string $note): void
    {
        $this->rehearsal = ['on' => $on, 'minutes' => $durationMinutes, 'ok' => $succeeded, 'note' => $note];
    }

    /**
     * @return array{ready: bool, problems: list<string>}
     */
    public function readiness(int $cutoverWindowMinutes): array
    {
        $problems = [];

        if ($this->steps === []) {
            $problems[] = 'the plan has no steps, so there is nothing to roll back and nothing to follow';
        }

        if ($this->rehearsal === null) {
            $problems[] = 'the rollback has never been REHEARSED. A rollback plan that has never been '
                . 'executed is a document, and the moment it is needed is the moment nobody can afford to '
                . 'discover what it left out';
        } else {
            if (!$this->rehearsal['ok']) {
                $problems[] = 'the rollback rehearsal FAILED: ' . $this->rehearsal['note'];
            }

            if ($this->rehearsal['minutes'] > $cutoverWindowMinutes) {
                $problems[] = sprintf(
                    'the rehearsed rollback takes %d minutes and the cutover window is %d. A rollback that '
                    . 'does not fit the window is not a rollback for this cutover, and knowing that now is '
                    . 'the difference between a decision and a discovery',
                    $this->rehearsal['minutes'], $cutoverWindowMinutes,
                );
            }
        }

        return ['ready' => $problems === [], 'problems' => $problems];
    }

    /** @throws \InvalidArgumentException when nobody actually signed */
    public function signOff(string $name, string $role, \DateTimeImmutable $on, string $scope): void
    {
        if (trim($name) === '' || trim($role) === '' || trim($scope) === '') {
            throw new \InvalidArgumentException(
                'A cutover sign-off needs a named person, their role and what they signed off. "The '
                . 'customer signs off" is a fact about a person, not a status somebody sets.'
            );
        }

        $this->signOff = ['name' => $name, 'role' => $role, 'on' => $on, 'scope' => $scope];
    }

    public function isSignedOff(): bool
    {
        return $this->signOff !== null;
    }

    /** @return array{ok: bool, problems: list<string>} */
    public function canCutOver(ReconciliationReport $report, int $cutoverWindowMinutes): array
    {
        $problems = $this->readiness($cutoverWindowMinutes)['problems'];

        if (!$report->passes()) {
            $problems[] = 'the reconciliation report has ' . count($report->failures())
                . ' unexplained difference(s), each of which blocks sign-off (R-XXIX-102: a Class E '
                . 'variance may not be waived, accepted, rounded away or netted against another)';
        }

        if (!$this->isSignedOff()) {
            $problems[] = 'the customer has not signed off';
        }

        return ['ok' => $problems === [], 'problems' => $problems];
    }

    /** @return list<string> */
    public function steps(): array
    {
        return $this->steps;
    }

    public function signOffRecord(): ?array
    {
        return $this->signOff;
    }
}
