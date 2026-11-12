<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Attendance;

/**
 * ONE ATTENDANCE DAY, SETTLED — Part XI §XI.8, layer 3.
 *
 * Punches plus a shift window in; worked minutes, late, early exit, sessions and
 * an unpaired-punch verdict out. Pure, so it can be re-run, and it MUST be
 * re-runnable: layer 3 is derived and supersedable, and the Phase 4 exit gate
 * requires recalculation to be idempotent and to produce a diff.
 *
 * Every figure is arithmetic on absolute instants relative to the shift start
 * (R-XI-041). None of it goes near minutes-since-midnight, which is what makes
 * the cross-midnight cases work rather than being special-cased.
 *
 * The unpaired punch is the case that matters most in practice and the one
 * SmartPRS 2 handles worst: it infers direction from position, so one missed
 * swipe inverts every pair after it and the day reads as eight hours of work
 * that never happened. Here an unpaired punch is NAMED, the day is settled
 * around it, and the day is flagged for regularisation rather than guessed at.
 */
final readonly class DaySettlement
{
    /**
     * @param list<array{in: Punch, out: Punch, minutes: int}> $sessions
     * @param list<Punch>                                      $unpaired
     * @param list<Punch>                                      $outsideWindow
     */
    public function __construct(
        public string $employmentId,
        public \DateTimeImmutable $attendanceDate,
        public string $shiftCode,
        public int $workedMinutes,
        public int $lateMinutes,
        public int $earlyExitMinutes,
        public int $shortfallMinutes,
        public int $overtimeMinutes,
        public array $sessions,
        public array $unpaired,
        public array $outsideWindow,
        public string $trace,
    ) {
    }

    /**
     * Settle one day.
     *
     * @param list<Punch> $punches every punch that might belong to this day,
     *        including ones that turn out not to
     */
    public static function settle(ShiftWindow $window, string $employmentId, array $punches): self
    {
        $assigned = $window->assign($punches);
        $inside = $assigned['inside'];

        $sessions = [];
        $unpaired = [];
        $open = null;

        foreach ($inside as $punch) {
            if ($punch->isIn()) {
                if ($open !== null) {
                    // Two INs with no OUT between them. The FIRST is unpaired —
                    // somebody swiped in twice — and the second stands. Choosing
                    // which one is unpaired is a decision, and recording it is
                    // what lets a regularisation fix the right one.
                    $unpaired[] = $open;
                }

                $open = $punch;

                continue;
            }

            if ($open === null) {
                // An OUT with no IN. Not silently paired with the start of the
                // shift: the employee may have been here since before the window
                // opened, or the IN may simply be missing, and those are
                // different corrections.
                $unpaired[] = $punch;

                continue;
            }

            $sessions[] = [
                'in' => $open,
                'out' => $punch,
                'minutes' => (int) (($punch->at->getTimestamp() - $open->at->getTimestamp()) / 60),
            ];
            $open = null;
        }

        if ($open !== null) {
            $unpaired[] = $open;
        }

        $worked = array_sum(array_column($sessions, 'minutes')) - $window->breakMinutes;
        $worked = max(0, $worked);

        $firstIn = $inside === [] ? null : self::firstOfDirection($inside, Punch::IN);
        $lastOut = $inside === [] ? null : self::lastOfDirection($inside, Punch::OUT);

        $late = $firstIn === null ? 0 : $window->lateBeyondGrace($firstIn);
        $early = $lastOut === null ? 0 : $window->earlyExitMinutes($lastOut);

        $scheduled = $window->scheduledMinutes();
        $shortfall = max(0, $scheduled - $worked);
        $overtime = max(0, $worked - $scheduled);

        return new self(
            employmentId: $employmentId,
            attendanceDate: $window->attendanceDate,
            shiftCode: $window->shiftCode,
            workedMinutes: $worked,
            lateMinutes: $late,
            earlyExitMinutes: $early,
            shortfallMinutes: $shortfall,
            overtimeMinutes: $overtime,
            sessions: $sessions,
            unpaired: $unpaired,
            outsideWindow: $assigned['outside'],
            trace: self::describeTrace($window, $inside, $sessions, $unpaired, $worked, $late, $early),
        );
    }

    /** A day with an unpaired punch is not wrong — it is INCOMPLETE, and says so. */
    public function needsRegularisation(): bool
    {
        return $this->unpaired !== [];
    }

    /**
     * The fingerprint a re-run compares against, for the idempotence the exit
     * gate requires. Derived from the OUTCOME, so a re-run that reaches the same
     * answer by a different route still matches.
     */
    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            $this->employmentId,
            $this->attendanceDate->format('Y-m-d'),
            $this->shiftCode,
            $this->workedMinutes,
            $this->lateMinutes,
            $this->earlyExitMinutes,
            $this->overtimeMinutes,
            count($this->sessions),
            count($this->unpaired),
        ]));
    }

    /**
     * What changed between two settlements of the same day.
     *
     * @return list<string> empty when the re-run agrees, which is idempotence
     */
    public function diff(self $other): array
    {
        $changes = [];

        foreach ([
            'worked' => [$this->workedMinutes, $other->workedMinutes],
            'late' => [$this->lateMinutes, $other->lateMinutes],
            'early exit' => [$this->earlyExitMinutes, $other->earlyExitMinutes],
            'shortfall' => [$this->shortfallMinutes, $other->shortfallMinutes],
            'overtime' => [$this->overtimeMinutes, $other->overtimeMinutes],
            'sessions' => [count($this->sessions), count($other->sessions)],
            'unpaired' => [count($this->unpaired), count($other->unpaired)],
        ] as $label => [$before, $after]) {
            if ($before !== $after) {
                $changes[] = "{$label}: {$before} → {$after}";
            }
        }

        return $changes;
    }

    /** @param list<Punch> $punches */
    private static function firstOfDirection(array $punches, string $direction): ?Punch
    {
        foreach ($punches as $punch) {
            if ($punch->direction === $direction) {
                return $punch;
            }
        }

        return null;
    }

    /** @param list<Punch> $punches */
    private static function lastOfDirection(array $punches, string $direction): ?Punch
    {
        $found = null;

        foreach ($punches as $punch) {
            if ($punch->direction === $direction) {
                $found = $punch;
            }
        }

        return $found;
    }

    /** @param list<Punch> $inside @param list<array{minutes:int}> $sessions @param list<Punch> $unpaired */
    private static function describeTrace(
        ShiftWindow $window,
        array $inside,
        array $sessions,
        array $unpaired,
        int $worked,
        int $late,
        int $early,
    ): string {
        $lines = [$window->describe()];
        $lines[] = count($inside) . ' punch(es) in the window, ' . count($sessions) . ' complete session(s)';

        foreach ($sessions as $index => $session) {
            $lines[] = '  session ' . ($index + 1) . ': ' . $session['in']->at->format('j H:i')
                . ' → ' . $session['out']->at->format('j H:i') . ' = ' . $session['minutes'] . 'm';
        }

        if ($window->breakMinutes > 0) {
            $lines[] = "  less {$window->breakMinutes}m of declared break";
        }

        $lines[] = "worked {$worked}m against a scheduled {$window->scheduledMinutes()}m; late {$late}m, "
            . "early exit {$early}m — all relative to the shift start, never to midnight";

        foreach ($unpaired as $punch) {
            $lines[] = 'UNPAIRED: ' . $punch->describe()
                . ' — the day is settled around it and flagged for regularisation, not guessed at';
        }

        return implode("\n", $lines);
    }
}
