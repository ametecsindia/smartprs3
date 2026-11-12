<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\RoundingMode;

/**
 * WHAT A DAY COSTS — attendance.day.penalise.
 *
 * `attendance.late_minutes` has been computed for every settled day since Phase 4
 * and, exactly like overtime, nothing ever read it: somebody could be forty
 * minutes late every morning of the month and the payslip would not know.
 * SmartPRS 2 does have this rule — `PayrollGenController::attendanceCut():449-455`
 * — as a loop with the numbers written into it, which is why "we give fifteen
 * minutes, not ten" is a release there.
 *
 * THE COUNTER IS PREPARED, NEVER QUERIED. The engine holds no repository by
 * design; a rule counting late arrivals over a payroll month reads a value the
 * CALLER assembled under a canonical key (§5.9). That is not ceremony: it is why
 * the same inputs always produce the same trace, and why an unprepared counter
 * is a named ERR_AGG_UNAVAILABLE instead of a silent zero — which on this
 * decision point would be the difference between a penalty and no penalty, with
 * nothing on any screen to say which happened.
 *
 * IT REPORTS, IT DOES NOT DEDUCT. `PayrollPeriod` decides what a month is worth;
 * this class says what the rules think each day costs and why. Two classes doing
 * the arithmetic is how they come to disagree about somebody's pay.
 */
final class PenaltyRules
{
    public const DECISION_POINT = 'attendance.day.penalise';

    private readonly PublishedRules $published;

    public function __construct(string $file, string $tenantId = 'T-DEMO')
    {
        $this->published = new PublishedRules($file, self::DECISION_POINT, 'employee_day', $tenantId);
    }

    /**
     * What one day was penalised, in HALVES of a day.
     *
     * Halves for the same reason payable days are: this number comes off
     * somebody's gross, and law 7 says money is exact. `0.5d` from the rule
     * becomes 1 half here and nothing anywhere holds 0.5.
     *
     * @param array<string, mixed> $values SPX symbols, plus the prepared counters
     * @return array{halves: int, penalties: list<array{days: string, reason: string, rule: string}>,
     *     why: ?string, trace: ?string}
     */
    public function penalisedOn(string $employmentId, string $date, array $values): array
    {
        $result = $this->published->evaluate($employmentId, $date, $values);

        if ($result === null) {
            // Reported, never read as "no penalty". Those are the same shape on
            // a screen and opposite facts, and the one that quietly pays a full
            // day is the one nobody raises.
            return ['halves' => 0, 'penalties' => [], 'why' => $this->published->refusal(),
                'trace' => null];
        }

        $halves = 0;
        $penalties = [];

        foreach ($result->actionsOfCode('APPLY_DAY_PENALTY') as $action) {
            // `0.5d` COMES BACK AS THE STRING "1/2", because the engine
            // canonicalises resolved parameters — the fingerprint has to hash a
            // stable form. The first version of this loop wrote
            // `(int) round((float) $fraction * 2)`, and `(float) "1/2"` is 1.0,
            // so half a day became a whole day on the number that comes off
            // somebody's gross, under a trace that read perfectly. Law 7 in one
            // line: a float anywhere near money is a defect, and a cast through
            // a string is a float wearing a disguise. `Rational::parse()` reads
            // the exact form back exactly, and refuses anything else.
            $fraction = Rational::parse((string) ($action->parameters['fraction'] ?? '0'));

            // ADDITIVE, because the action catalogue says so: two rules can each
            // penalise the same day and the day owes both. In HALVES, exactly —
            // 0.5 + 0.25 in floats is not a number you want deciding a payslip.
            $inHalves = $fraction->multipliedBy(Rational::of(2));

            if ($inHalves->toInt(RoundingMode::Down) !== $inHalves->toInt(RoundingMode::Up)) {
                throw new \RuntimeException(
                    "A day penalty of {$fraction} is not a whole number of half-days, and this "
                    . 'build settles pay in halves. Refused rather than rounded: rounding a '
                    . 'penalty is choosing, silently, which way somebody loses.'
                );
            }

            $halves += $inHalves->toInt(RoundingMode::Down);

            $penalties[] = [
                'days' => $fraction->toDecimalString(),
                'reason' => (string) ($action->parameters['reason'] ?? ''),
                'rule' => (string) ($action->emittedBy['rule_code'] ?? ''),
            ];
        }

        return ['halves' => $halves, 'penalties' => $penalties, 'why' => null,
            'trace' => $result->trace->render()];
    }

    /** The policy values this file declares, for the caller to supply as symbols. */
    public function policy(): array
    {
        $declared = $this->published->declared('policy');

        return is_array($declared) ? $declared : [];
    }

    /** @return list<string> the aggregation counters the caller must prepare */
    public function preparedKeys(): array
    {
        return $this->published->preparedKeys();
    }

    public function refusal(): ?string
    {
        return $this->published->refusal();
    }

    /** @return list<\SmartPRS\Platform\Rules\Rule> */
    public function rules(): array
    {
        return $this->published->rules();
    }
}
