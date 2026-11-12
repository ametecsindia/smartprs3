<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules\Aggregation;

/**
 * THE ON-DEMAND AGGREGATION PATH — Part VII §5.9.1 and §5.9.4.
 *
 * Seven primitives over one subject's settled events. Pure: the events are
 * supplied, the window is already resolved, and nothing here reads a clock or a
 * connection.
 *
 * §5.9.4 declares two paths — a materialised projector and this one — and
 * R-VII-024 requires "a conformance test that the projector and the on-demand
 * computation agree on a fixture of at least 10,000 events". This is the half
 * that defines what agreement MEANS: when the projector lands, it is checked
 * against this, not the other way round.
 *
 * The bounds of §5.9.4 are enforced here rather than trusted: a hard row cap,
 * because an aggregation over an unbounded event set is how one employee's
 * evaluation takes a payroll run down.
 */
final class Aggregator
{
    /** §5.9.4's default row cap. An aggregation is bounded or it is a denial of service. */
    public const MAX_ROWS = 20_000;

    /** @var list<Event> */
    private readonly array $events;

    /**
     * @param list<mixed> $events one subject's settled events, any order; declared wide
     *        because this constructor is the boundary a caller hands events to, and the
     *        loop below is what actually proves each one is an Event before it is stored
     */
    public function __construct(array $events)
    {
        if (count($events) > self::MAX_ROWS) {
            throw new \InvalidArgumentException(
                'An aggregation was handed ' . count($events) . ' events, over the cap of ' . self::MAX_ROWS
                . ' (§5.9.4). The caller narrows the window or the metric moves to the materialised path; '
                . 'it does not get answered slowly.'
            );
        }

        foreach ($events as $event) {
            if (!$event instanceof Event) {
                throw new \InvalidArgumentException('An aggregator holds Event values only.');
            }
        }

        /** @var list<Event> $events */
        $this->events = $events;
    }

    /**
     * §5.9.1 `agg.count`. Events of one code in the window, satisfying the
     * predicate.
     *
     * @param ?callable(Event): bool $predicate evaluated in the EVENT scope (§5.9.3)
     */
    public function count(string $code, Window $window, ?callable $predicate = null): int
    {
        return count($this->matching($code, $window, $predicate));
    }

    /**
     * §5.9.1 `agg.sum`. Typed by the measure declaration, so the caller decides
     * what the integer means — minutes, days, paise. This returns the integer.
     *
     * @param ?callable(Event): bool $predicate
     */
    public function sum(string $code, Window $window, ?callable $predicate = null): int
    {
        $total = 0;

        foreach ($this->matching($code, $window, $predicate) as $event) {
            if (!is_int($event->value)) {
                throw new \InvalidArgumentException(
                    "agg.sum over '{$code}' met an event whose value is " . get_debug_type($event->value)
                    . '. A measure sums integers — minutes, days or paise — because Law 7 has no float in it.'
                );
            }

            $total += $event->value;
        }

        return $total;
    }

    /**
     * §5.9.1 `agg.nth`. The instant of the n-th event, n negative from the end.
     *
     * @param ?callable(Event): bool $predicate
     */
    public function nth(string $code, Window $window, int $n, ?callable $predicate = null): ?\DateTimeImmutable
    {
        if ($n === 0) {
            throw new \InvalidArgumentException('agg.nth is 1-based; there is no zeroth event.');
        }

        $matching = $this->matching($code, $window, $predicate);
        $index = $n > 0 ? $n - 1 : count($matching) + $n;

        return $matching[$index]->instant ?? null;
    }

    /**
     * §5.9.1 `agg.occurrence_index`. The 1-based index of THE CURRENT SUBJECT'S
     * event among the qualifying events in the window, or 0 when the current
     * subject does not itself qualify.
     *
     * The zero matters: "this is the third late" and "this is not a late at
     * all" are different answers, and a rule that treats a missing index as
     * zero-and-therefore-first penalises the wrong day.
     *
     * @param ?callable(Event): bool $predicate
     */
    public function occurrenceIndex(
        string $code,
        Window $window,
        \DateTimeImmutable $subjectDate,
        ?callable $predicate = null,
    ): int {
        $day = $subjectDate->setTime(0, 0);
        $index = 0;

        foreach ($this->matching($code, $window, $predicate) as $event) {
            $index++;

            if ($event->date() == $day) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * §5.9.1 `agg.collapse_repeats`. Events remaining after runs closer
     * together than `withinSeconds` are collapsed to one — the configurable
     * double-tap window.
     *
     * Each event is compared with the last KEPT one, not with its immediate
     * predecessor: a stream of taps one second apart is one event however long
     * it runs, which is what a jammed reader produces.
     */
    public function collapseRepeats(string $code, Window $window, int $withinSeconds): int
    {
        if ($withinSeconds < 0) {
            throw new \InvalidArgumentException('A collapse window is zero seconds or more.');
        }

        $kept = 0;
        $lastKept = null;

        foreach ($this->matching($code, $window, null) as $event) {
            if ($lastKept !== null
                && $event->instant->getTimestamp() - $lastKept->getTimestamp() < $withinSeconds) {
                continue;
            }

            $kept++;
            $lastKept = $event->instant;
        }

        return $kept;
    }

    /**
     * §5.9.1 `agg.streak`. The consecutive run of DAYS carrying this event,
     * counted from the subject's date.
     *
     * `backward` includes the current day and looks only at settled history,
     * which is the direction an attendance rule asks about: "how many days in a
     * row has this person been absent, up to and including today".
     *
     * @return array{length: int, terminated_by: ?string} the run, and the date that ended it
     */
    public function streak(string $code, \DateTimeImmutable $subjectDate, string $direction = 'backward'): array
    {
        if (!in_array($direction, ['backward', 'forward'], true)) {
            throw new \InvalidArgumentException(
                "agg.streak's direction is 'backward' or 'forward', not '{$direction}'. "
                . "'centred' is declared in §5.9.1 and is not implemented here, so it is refused rather "
                . 'than silently treated as one of the other two.'
            );
        }

        $byDay = [];

        foreach ($this->events as $event) {
            if ($event->code === $code) {
                $byDay[$event->date()->format('Y-m-d')] = true;
            }
        }

        $step = $direction === 'backward' ? '-1 day' : '+1 day';
        $cursor = $subjectDate->setTime(0, 0);
        $length = 0;

        while (isset($byDay[$cursor->format('Y-m-d')])) {
            $length++;
            $cursor = $cursor->modify($step);
        }

        return [
            'length' => $length,
            // The day that ended the run — the piece of information that makes
            // a trace explain itself rather than assert a number.
            'terminated_by' => $length === 0 ? null : $cursor->format('Y-m-d'),
        ];
    }

    /**
     * §5.9.1 `agg.balance`. A ledger balance AS AT a date.
     *
     * The ledger is read as at the date, never as the current row set: a leave
     * balance recomputed in November for a March decision must give March's
     * answer. A superseded entry is one whose `supersededOn` is on or before
     * the as-at date, and it does not count.
     *
     * @param list<array{on: \DateTimeImmutable, amount: int, superseded_on?: ?\DateTimeImmutable}> $ledger
     */
    public static function balance(array $ledger, \DateTimeImmutable $asOf): int
    {
        $day = $asOf->setTime(0, 0);
        $balance = 0;

        foreach ($ledger as $entry) {
            if ($entry['on']->setTime(0, 0) > $day) {
                continue;
            }

            $superseded = $entry['superseded_on'] ?? null;

            if ($superseded instanceof \DateTimeImmutable && $superseded->setTime(0, 0) <= $day) {
                continue;
            }

            $balance += $entry['amount'];
        }

        return $balance;
    }

    /**
     * Events of one code inside the window, satisfying the predicate, in
     * chronological order.
     *
     * Sorted here rather than trusting the caller's order, because every
     * ordinal primitive above — nth, occurrence_index, collapse_repeats —
     * depends on it, and "the third late" computed over an arbitrary order is a
     * different answer each time the store changes its mind about sorting.
     *
     * @param  ?callable(Event): bool  $predicate
     * @return list<Event>
     */
    private function matching(string $code, Window $window, ?callable $predicate): array
    {
        $matching = [];

        foreach ($this->events as $event) {
            if ($event->code !== $code || !$window->contains($event->date())) {
                continue;
            }

            if ($predicate !== null && !$predicate($event)) {
                continue;
            }

            $matching[] = $event;
        }

        usort(
            $matching,
            static fn (Event $a, Event $b): int => $a->instant <=> $b->instant,
        );

        return $matching;
    }
}
