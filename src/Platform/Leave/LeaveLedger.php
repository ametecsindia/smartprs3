<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Leave;

use SmartPRS\Platform\Support\Rational;

/**
 * THE LEAVE LEDGER — Part XIII §XIII.4.
 *
 * Append-only, and a balance is a SUM rather than a column. That is the whole
 * design, and it is the opposite of SmartPRS 2, which keeps a running balance
 * number that anything can write: its balance is adjusted in six places, never
 * reconciled against the transactions that produced it, and when the two
 * disagree there is no way to tell which is wrong because only one of them has
 * an audit trail.
 *
 * A balance computed from entries has a property a stored one cannot: it is
 * always explainable. "Why do I have 4.5 days?" is answered by listing the
 * entries, and "why does the system think I have 4.5 when I have 5" becomes a
 * question about one entry rather than about the number.
 *
 * Days are RATIONALS, not floats. Half-day leave is real, quarter-day leave
 * exists in some policies, and 0.1 + 0.2 is not 0.3 — a leave balance drifting
 * by a thousandth of a day is a balance that eventually shows 4.999999 and
 * refuses a five-day request.
 *
 * Nothing is ever deleted. A cancelled leave is a REVERSING entry pointing at
 * what it reverses, so the history says what happened rather than what is
 * currently true.
 */
final class LeaveLedger
{
    public const ACCRUAL = 'accrual';
    public const OPENING = 'opening';
    public const CONSUMED = 'consumed';
    public const LAPSED = 'lapsed';
    public const ENCASHED = 'encashed';
    public const REVERSAL = 'reversal';
    public const ADJUSTMENT = 'adjustment';

    /**
     * EARNED — a day the employee worked for that is owed back, not accrued and
     * not corrected. A comp-off for a full shift on a weekly off is the first
     * one. It is its own kind rather than an ADJUSTMENT because a statement is
     * read by the person it belongs to: "adjustment" says somebody changed
     * something, "earned" says why they have the day, and the difference is the
     * whole reason this ledger prints a reason at all.
     */
    public const EARNED = 'earned';

    /** Entry kinds that ADD to a balance. Everything else subtracts. */
    private const CREDITS = [self::ACCRUAL, self::OPENING, self::REVERSAL, self::ADJUSTMENT,
        self::EARNED];

    /** @var list<LeaveLedgerEntry> */
    private array $entries = [];

    /** @param list<LeaveLedgerEntry> $entries */
    public function __construct(array $entries = [])
    {
        foreach ($entries as $entry) {
            $this->append($entry);
        }
    }

    public function append(LeaveLedgerEntry $entry): self
    {
        if ($entry->kind === self::REVERSAL && $entry->reversesEntryId === null) {
            throw new \InvalidArgumentException(
                'A reversal names the entry it reverses. A credit that appears from nowhere and is called a '
                . 'reversal is an adjustment nobody can trace back to a decision.'
            );
        }

        $this->entries[] = $entry;

        return $this;
    }

    /**
     * The balance as at a date, for one leave type.
     *
     * AS AT, not "now": a leave request raised in March against a balance that
     * has since accrued twice must be judged on March's balance, or an approval
     * granted in March cannot be explained in June.
     */
    public function balanceOn(string $employmentId, string $leaveTypeCode, \DateTimeImmutable $asOf): Rational
    {
        $balance = Rational::of(0);

        foreach ($this->applicable($employmentId, $leaveTypeCode, $asOf) as $entry) {
            $balance = in_array($entry->kind, self::CREDITS, true)
                ? $balance->plus($entry->days)
                : $balance->minus($entry->days);
        }

        return $balance;
    }

    /**
     * Every entry behind a balance, so the number can always be explained.
     *
     * @return list<LeaveLedgerEntry>
     */
    public function applicable(string $employmentId, string $leaveTypeCode, \DateTimeImmutable $asOf): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (LeaveLedgerEntry $e): bool => $e->employmentId === $employmentId
                && $e->leaveTypeCode === $leaveTypeCode
                && $e->effectiveOn <= $asOf,
        ));
    }

    /**
     * What has expired unclaimed as at a date — the comp-off case, mostly.
     *
     * A credit with an expiry that has passed and has not been consumed is
     * LAPSED, and lapsing is an ENTRY rather than a filter. If expiry were a
     * `WHERE expires_on >= ?` on the balance query, an employee would watch days
     * disappear with nothing in the history saying when or why.
     *
     * @return list<LeaveLedgerEntry> the lapse entries that should be written
     */
    public function lapsesDue(string $employmentId, string $leaveTypeCode, \DateTimeImmutable $asOf): array
    {
        $lapses = [];
        $consumedIds = [];

        foreach ($this->entries as $entry) {
            if ($entry->consumesEntryId !== null) {
                $consumedIds[$entry->consumesEntryId] = true;
            }
        }

        foreach ($this->applicable($employmentId, $leaveTypeCode, $asOf) as $entry) {
            if (!in_array($entry->kind, self::CREDITS, true)
                || $entry->expiresOn === null
                || $entry->expiresOn >= $asOf
                || isset($consumedIds[$entry->id])
            ) {
                continue;
            }

            $alreadyLapsed = false;

            foreach ($this->entries as $other) {
                if ($other->kind === self::LAPSED && $other->consumesEntryId === $entry->id) {
                    $alreadyLapsed = true;
                }
            }

            if (!$alreadyLapsed) {
                $lapses[] = LeaveLedgerEntry::lapse($entry, $entry->expiresOn);
            }
        }

        return $lapses;
    }

    /** @return list<LeaveLedgerEntry> */
    public function all(): array
    {
        return $this->entries;
    }

    /** The statement an employee reads — every entry, and the running total. */
    public function statement(string $employmentId, string $leaveTypeCode, \DateTimeImmutable $asOf): string
    {
        $lines = ["{$leaveTypeCode} for {$employmentId} as at {$asOf->format('j M Y')}"];
        $running = Rational::of(0);

        foreach ($this->applicable($employmentId, $leaveTypeCode, $asOf) as $entry) {
            $running = in_array($entry->kind, self::CREDITS, true)
                ? $running->plus($entry->days)
                : $running->minus($entry->days);

            $lines[] = sprintf(
                '  %s  %-11s %+7s  → %s   %s',
                $entry->effectiveOn->format('d-m-Y'),
                $entry->kind,
                (in_array($entry->kind, self::CREDITS, true) ? '+' : '-') . $entry->days,
                (string) $running,
                $entry->reason,
            );
        }

        $lines[] = '  balance: ' . (string) $this->balanceOn($employmentId, $leaveTypeCode, $asOf) . ' day(s)';

        return implode("\n", $lines);
    }
}
