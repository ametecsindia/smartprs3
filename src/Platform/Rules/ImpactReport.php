<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\RoundingMode;

/**
 * What an impact preview found — the thing §7.9 says is "stored with the
 * version and shown to the approver".
 *
 * Every number here is exact and says what it counted over: `subjectCount` is
 * the population the preview actually evaluated, so an approver reading "37 of
 * 1,240 employees" can tell it from "37 of a sample of 50". A report that
 * carried only the changed count would be indistinguishable from an estimate.
 */
final readonly class ImpactReport
{
    /**
     * @param list<string>          $changedSubjectIds every affected subject, not a sample
     * @param array<string, string> $erroredSubjects   subject id => the error code it failed with
     * @param array<string, array{before: string, after: string}> $outcomes
     *        every subject's outcome under BOTH versions. Kept rather than reduced to a boolean,
     *        because "37 employees change" is the headline and "and here is what changes for each"
     *        is what an approver reads — and a caller that recomputed it would be a second
     *        implementation of the comparison, which is the one that eventually disagrees.
     * @param list<string> $annotationOnlySubjectIds subjects whose EXPLANATION changed and whose
     *        arithmetic did not — a reworded `reason`. Reported, never hidden, and never counted
     *        as an arithmetic change: a preview that cries wolf on a typo fix is one people learn
     *        to click past.
     */
    public function __construct(
        public string $decisionPoint,
        public \DateTimeImmutable $asOf,
        public int $subjectCount,
        public array $changedSubjectIds,
        public array $erroredSubjects = [],
        public bool $moneyAffecting = false,
        public bool $selfAffecting = false,
        public array $outcomes = [],
        public array $annotationOnlySubjectIds = [],
    ) {
    }

    public function changedCount(): int
    {
        return count($this->changedSubjectIds);
    }

    /**
     * The affected share of the population, as an exact ratio.
     *
     * A Rational rather than a float, and not only because the no-float-money
     * gate says so: 37/1240 as a float is 0.029838709677419356, and the number
     * an approver is shown — "2.98%" — should be a rounding of the real ratio
     * at the moment it is PRINTED, not a rounding that happened three steps
     * earlier and got compared against a threshold on the way.
     */
    public function share(): Rational
    {
        return $this->subjectCount === 0
            ? Rational::of(0)
            : Rational::of($this->changedCount(), $this->subjectCount);
    }

    /**
     * §7.9, lines 1533 and 1831: a rule affecting more than a fifth of the
     * tenant, or emitting a money action at all, cannot be published without an
     * approver seeing this.
     */
    public function requiresApproval(): bool
    {
        return $this->moneyAffecting
            || $this->selfAffecting
            || $this->erroredSubjects !== []
            || $this->share()->compareTo(ImpactPreview::approvalShare()) > 0;
    }

    /** The share as a percentage to one place, rounded HERE and nowhere earlier. */
    public function sharePercent(): string
    {
        $tenths = $this->share()
            ->multipliedBy(Rational::of(1000))
            ->toInt(RoundingMode::HalfUp);

        return number_format($tenths / 10, 1);
    }

    /** What one subject's outcome was, and would become. */
    public function outcomeOf(string $subjectId): ?array
    {
        return $this->outcomes[$subjectId] ?? null;
    }

    /** @return list<string> an illustrative handful — the full list is `changedSubjectIds` */
    public function sample(): array
    {
        return array_slice($this->changedSubjectIds, 0, ImpactPreview::SAMPLE_SIZE);
    }

    /**
     * What the approver reads.
     *
     * The errored count is stated FIRST when there is one, because a preview
     * that leads with "37 employees affected" while four hundred evaluations
     * fail has told the approver the least important true thing it knows.
     */
    public function render(): string
    {
        $lines = [];

        if ($this->erroredSubjects !== []) {
            $lines[] = count($this->erroredSubjects) . ' of ' . $this->subjectCount
                . ' subjects could NOT be evaluated under one of the two versions ('
                . implode(', ', array_unique(array_values($this->erroredSubjects)))
                . '). They are counted as changed, because an evaluation that fails is not an outcome '
                . 'that stayed the same.';
        }

        $lines[] = sprintf(
            '%d of %d subjects change at %s, as at %s (%s%%).',
            $this->changedCount(),
            $this->subjectCount,
            $this->decisionPoint,
            $this->asOf->format('j M Y'),
            $this->sharePercent(),
        );

        if ($this->changedSubjectIds !== []) {
            $lines[] = 'For example: ' . implode(', ', $this->sample())
                . ($this->changedCount() > ImpactPreview::SAMPLE_SIZE
                    ? ' … and ' . ($this->changedCount() - ImpactPreview::SAMPLE_SIZE) . ' more'
                    : '');
        }

        if ($this->annotationOnlySubjectIds !== []) {
            $lines[] = 'A further ' . count($this->annotationOnlySubjectIds)
                . ' subject(s) get the same outcome with different wording — the explanation changed, '
                . 'the arithmetic did not.';
        }

        if ($this->moneyAffecting) {
            $lines[] = 'This version emits a money-affecting action, so approval is required whatever the count.';
        }

        if ($this->selfAffecting) {
            $lines[] = 'SELF-AFFECTING: the publisher\'s own record is among those that change.';
        }

        return implode("\n", $lines);
    }
}
