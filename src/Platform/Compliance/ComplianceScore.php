<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Compliance;

use SmartPRS\Platform\Support\Rational;

/**
 * A SCORE THAT SAYS WHAT IT COULD NOT ASSESS — Part XXI §XXI.3.9, R-XXI-054.
 *
 * "A score containing a `not_assessed` contribution is reported as *score 72 of a
 * possible 85 assessed; 15 points not assessed*, never as 72/100."
 *
 * That sentence is the whole class. A score out of 100 that quietly dropped the
 * checks it could not run is a number that goes UP when the system breaks, and
 * the direction of that error is what makes it dangerous: the worse the data,
 * the healthier the dashboard. SmartPRS 2 awards 20 points when the `complaints`
 * table does not exist, so a missing table scores better than an empty one.
 *
 * `not_applicable` is excluded from the denominator entirely — a subject the type
 * does not cover is not a subject who passed, and not one who failed either.
 */
final readonly class ComplianceScore
{
    private function __construct(
        public Rational $earned,
        public Rational $assessable,
        public Rational $notAssessed,
        public int $itemsScored,
        public int $itemsNotAssessed,
        public int $itemsNotApplicable,
    ) {
    }

    /**
     * @param list<ComplianceStatus> $statuses
     * @param array<string, ItemType> $types
     */
    public static function of(array $statuses, array $types): self
    {
        $earned = Rational::of(0);
        $assessable = Rational::of(0);
        $notAssessed = Rational::of(0);
        $scored = 0;
        $unassessed = 0;
        $notApplicable = 0;

        foreach ($statuses as $status) {
            $type = $types[$status->itemTypeCode] ?? throw new \InvalidArgumentException(
                "No item type '{$status->itemTypeCode}' to weight its score by."
            );

            $weight = Rational::of($type->scoreWeight);

            if (!$status->isApplicable()) {
                $notApplicable++;

                continue;
            }

            if ($status->isNotAssessed()) {
                // OUT of the denominator, and reported separately. Absorbing it
                // either way is a lie: into the numerator flatters, into the
                // denominator punishes a subject for a system failure.
                $notAssessed = $notAssessed->plus($weight);
                $unassessed++;

                continue;
            }

            $assessable = $assessable->plus($weight);
            $earned = $earned->plus(
                $weight->multipliedBy(Rational::fromDecimalString($type->creditFor($status->state))),
            );
            $scored++;
        }

        return new self($earned, $assessable, $notAssessed, $scored, $unassessed, $notApplicable);
    }

    public function isFullyAssessed(): bool
    {
        return $this->notAssessed->isZero();
    }

    /**
     * The sentence R-XXI-054 requires, and it never reads "n out of 100" when
     * anything went unassessed.
     */
    public function render(): string
    {
        $line = sprintf(
            'score %s of a possible %s assessed',
            $this->earned,
            $this->assessable,
        );

        if (!$this->isFullyAssessed()) {
            $line .= sprintf(
                '; %s points NOT ASSESSED across %d item(s) — a check that could not run has not passed, '
                . 'and a score that hid them would rise as the data got worse',
                $this->notAssessed,
                $this->itemsNotAssessed,
            );
        }

        if ($this->itemsNotApplicable > 0) {
            $line .= sprintf('; %d item(s) not applicable and excluded', $this->itemsNotApplicable);
        }

        return $line;
    }
}
