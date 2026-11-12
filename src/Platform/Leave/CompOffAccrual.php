<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Leave;

use SmartPRS\Platform\Attendance\DaySettlement;
use SmartPRS\Platform\Calendar\CalendarService;
use SmartPRS\Platform\Calendar\DayType;
use SmartPRS\Platform\Support\Rational;

/**
 * WORKING A DAY OFF EARNS A DAY BACK — Part XIII §XIII.9, R-XII-015.
 *
 * The join between the calendar, attendance and the leave ledger, and the point
 * at which Phase 4's pieces become one thing: `resolveDayType` says the day was
 * an off, `DaySettlement` says how long they worked it, and the ledger records
 * what they earned.
 *
 * R-XII-015: "a punch on a resolved weekly off does not silently become an
 * ordinary working day." SmartPRS 2 has no comp-off at all, so somebody called
 * in on a Sunday is recorded as having worked a Sunday and that is the end of
 * it — the obligation exists in the manager's head and in nobody's system.
 *
 * Three declarations shape the accrual, all configuration:
 *
 *   MINIMUM MINUTES before anything is earned. Twenty minutes at the office on
 *   a Sunday to collect a laptop is not a day owed.
 *   FULL-DAY MINUTES above which a whole day is earned, with a half day between
 *   the two — which is why the credit is a Rational.
 *   EXPIRY, without which comp-off is an unbounded liability that surfaces as a
 *   resignation settlement nobody budgeted for.
 *
 * An UNRESOLVED calendar verdict earns NOTHING and says so. Accruing on a day
 * the system could not classify would be inventing an entitlement out of a
 * failure.
 */
final class CompOffAccrual
{
    public function __construct(
        private readonly CalendarService $calendar,
        private readonly int $minimumMinutes,
        private readonly int $fullDayMinutes,
        private readonly int $expiryDays,
        /** Which day types earn comp-off. Configuration: some tenants credit holidays, some do not. */
        private readonly array $earningDayTypes = [DayType::WeeklyOff, DayType::Holiday],
    ) {
        if ($minimumMinutes < 1) {
            throw new \InvalidArgumentException(
                'A minimum of zero minutes credits a comp-off to anybody whose badge touched a reader on a '
                . 'Sunday. The threshold is what makes the credit mean something.'
            );
        }

        if ($fullDayMinutes < $minimumMinutes) {
            throw new \InvalidArgumentException(
                'The full-day threshold is below the minimum, so every qualifying day earns a whole day and '
                . 'the half-day band does not exist.'
            );
        }

        if ($expiryDays < 1) {
            throw new \InvalidArgumentException(
                'Comp-off that never expires is an unbounded liability. It surfaces as a resignation '
                . 'settlement nobody budgeted for, years after the Sunday that earned it.'
            );
        }
    }

    /**
     * What one settled attendance day earns.
     *
     * @return array{entry: ?LeaveLedgerEntry, days: Rational, why: string}
     */
    public function forDay(DaySettlement $day): array
    {
        $resolved = $this->calendar->resolveDayType($day->employmentId, $day->attendanceDate);

        if ($resolved['verdict']->isUnresolved()) {
            return [
                'entry' => null,
                'days' => Rational::of(0),
                'why' => 'Nothing is earned: the calendar could not classify '
                    . $day->attendanceDate->format('j M Y') . '. Accruing on a day the system could not '
                    . 'resolve would invent an entitlement out of a failure — ' . $resolved['verdict']->error,
            ];
        }

        if (!in_array($resolved['type'], $this->earningDayTypes, true)) {
            return [
                'entry' => null,
                'days' => Rational::of(0),
                'why' => $day->attendanceDate->format('j M Y') . ' was ' . $resolved['type']->label()
                    . ', which does not earn comp-off under this policy.',
            ];
        }

        if ($day->workedMinutes < $this->minimumMinutes) {
            return [
                'entry' => null,
                'days' => Rational::of(0),
                'why' => "{$day->workedMinutes} minutes worked on " . $resolved['type']->label()
                    . ", below the {$this->minimumMinutes}-minute minimum. Twenty minutes to collect a laptop "
                    . 'is not a day owed.',
            ];
        }

        $days = $day->workedMinutes >= $this->fullDayMinutes
            ? Rational::of(1)
            : Rational::of(1, 2);

        $entry = LeaveLedgerEntry::compOff(
            id: 'co:' . $day->employmentId . ':' . $day->attendanceDate->format('Ymd'),
            employmentId: $day->employmentId,
            days: $days,
            earnedOn: $day->attendanceDate,
            expiresOn: $day->attendanceDate->modify("+{$this->expiryDays} days"),
            reason: "Worked {$day->workedMinutes} minutes on " . $day->attendanceDate->format('j M Y')
                . ', which the calendar resolved as ' . $resolved['type']->label()
                . ' (' . $resolved['verdict']->describe() . ').',
        );

        return [
            'entry' => $entry,
            'days' => $days,
            'why' => "{$day->workedMinutes} minutes on " . $resolved['type']->label() . ' earns '
                . (string) $days . ' day, expiring ' . $entry->expiresOn->format('j M Y') . '.',
        ];
    }

    /**
     * Spend comp-off, oldest credit first, so the day nearest expiry goes first.
     *
     * FIFO is not an optimisation. Taking the newest credit first means the
     * oldest quietly lapses, and the employee loses a day they had while holding
     * one they had just earned — the sort of thing nobody notices until they
     * count.
     *
     * @return array{entries: list<LeaveLedgerEntry>, short: Rational, why: string}
     */
    public function consume(
        LeaveLedger $ledger,
        string $employmentId,
        Rational $days,
        \DateTimeImmutable $takenOn,
        string $reason,
    ): array {
        $credits = [];
        $spentByCredit = [];

        foreach ($ledger->applicable($employmentId, 'COMP-OFF', $takenOn) as $entry) {
            if ($entry->kind === LeaveLedger::ACCRUAL
                && ($entry->expiresOn === null || $entry->expiresOn >= $takenOn)
            ) {
                $credits[] = $entry;
            }

            if ($entry->consumesEntryId !== null) {
                $spentByCredit[$entry->consumesEntryId] = isset($spentByCredit[$entry->consumesEntryId])
                    ? $spentByCredit[$entry->consumesEntryId]->plus($entry->days)
                    : $entry->days;
            }
        }

        usort(
            $credits,
            static fn (LeaveLedgerEntry $a, LeaveLedgerEntry $b): int => $a->effectiveOn <=> $b->effectiveOn,
        );

        $remaining = $days;
        $entries = [];
        $sequence = 0;

        foreach ($credits as $credit) {
            if ($remaining->isZero() || $remaining->isNegative()) {
                break;
            }

            $available = $credit->days->minus($spentByCredit[$credit->id] ?? Rational::of(0));

            if ($available->isZero() || $available->isNegative()) {
                continue;
            }

            $take = $available->compareTo($remaining) <= 0 ? $available : $remaining;

            $entries[] = LeaveLedgerEntry::consume(
                id: 'co-use:' . $employmentId . ':' . $takenOn->format('Ymd') . ':' . $sequence++,
                credit: $credit,
                days: $take,
                takenOn: $takenOn,
                reason: $reason . ' (against the credit earned ' . $credit->effectiveOn->format('j M Y')
                    . ', oldest first so the one nearest expiry goes first)',
            );

            $remaining = $remaining->minus($take);
        }

        return [
            'entries' => $entries,
            'short' => $remaining,
            'why' => $remaining->isZero()
                ? 'Covered in full from ' . count($entries) . ' credit(s), oldest first.'
                : 'Short by ' . (string) $remaining . ' day(s): the balance does not cover this request, and '
                    . 'the shortfall is reported rather than the request being partly granted in silence.',
        ];
    }
}
