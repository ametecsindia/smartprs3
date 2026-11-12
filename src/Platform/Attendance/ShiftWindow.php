<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Attendance;

/**
 * WHICH ATTENDANCE DAY A PUNCH BELONGS TO — Part XI §XI.1.1 and §XI.8.4.
 *
 * The single highest-risk piece of Phase 4, and the one SmartPRS 2 gets
 * structurally wrong rather than accidentally.
 *
 * A shift starting at 22:00 on the 30th and ending at 06:00 on the 1st is ONE
 * night's work. SmartPRS 2 groups attendance by the calendar date of the punch,
 * so the two halves land in two different payroll months — a CRITICAL defect its
 * own analysis records, made worse by the fact that the intent to do better is
 * stranded in the code: a `night` flag is computed, a report advances the shift
 * end by a day, and `shifts.night_allowance` pays "per night actually worked"
 * against a grouping that cannot represent the night.
 *
 * So the attendance day is a WINDOW around the shift, expressed as absolute
 * instants, and a punch belongs to the window that contains it. Two rules make
 * that work:
 *
 *   THE WINDOW IS ANCHORED TO THE SHIFT START, not to midnight. Every late,
 *   early, worked, break and OT figure is arithmetic on instants relative to
 *   that anchor (R-XI-041, spine S10). Minutes-since-midnight is what makes a
 *   00:15 arrival on a 22:00 shift "on time" and eight hours early at once.
 *
 *   THE WINDOW EXTENDS BEYOND THE SHIFT by declared tolerances, because people
 *   arrive early and leave late, and a punch fifteen minutes before a 22:00
 *   shift belongs to that night rather than to the day that is ending.
 *
 * The attendance day is LABELLED by the date the shift STARTS. A night worked
 * from the 30th into the 1st is the 30th's attendance day, which is what makes
 * `night_allowance` per night actually worked a countable thing.
 */
final readonly class ShiftWindow
{
    public function __construct(
        public string $shiftCode,
        /** The date the attendance day is labelled by — the date the shift STARTS. */
        public \DateTimeImmutable $attendanceDate,
        public \DateTimeImmutable $shiftStart,
        public \DateTimeImmutable $shiftEnd,
        /** How early a punch may be and still belong to this shift. */
        public int $openMinutesBefore = 120,
        /** How late a punch may be and still belong to this shift. */
        public int $closeMinutesAfter = 240,
        public int $graceMinutes = 0,
        public int $breakMinutes = 0,
    ) {
        if ($shiftEnd <= $shiftStart) {
            throw new \InvalidArgumentException(
                "Shift '{$shiftCode}' ends at or before it begins. A cross-midnight shift is expressed by an "
                . 'END INSTANT ON THE NEXT DAY, not by an end time that is numerically smaller than the start '
                . '— which is exactly the representation that makes a night impossible to compute.'
            );
        }

        if ($openMinutesBefore < 0 || $closeMinutesAfter < 0) {
            throw new \InvalidArgumentException("Shift '{$shiftCode}' has a negative tolerance.");
        }
    }

    /** Is this a shift that crosses midnight? Answerable, because the instants say so. */
    public function crossesMidnight(): bool
    {
        return $this->shiftStart->format('Y-m-d') !== $this->shiftEnd->format('Y-m-d');
    }

    public function opensAt(): \DateTimeImmutable
    {
        return $this->shiftStart->modify("-{$this->openMinutesBefore} minutes");
    }

    public function closesAt(): \DateTimeImmutable
    {
        return $this->shiftEnd->modify("+{$this->closeMinutesAfter} minutes");
    }

    /** The scheduled length in minutes, breaks excluded. */
    public function scheduledMinutes(): int
    {
        return (int) (($this->shiftEnd->getTimestamp() - $this->shiftStart->getTimestamp()) / 60)
            - $this->breakMinutes;
    }

    public function contains(Punch $punch): bool
    {
        return $punch->at >= $this->opensAt() && $punch->at <= $this->closesAt();
    }

    /**
     * How late this punch is, in minutes, relative to the SHIFT START.
     *
     * Negative means early. This is the arithmetic XI.1.2 is about: on absolute
     * instants anchored to the resolved shift start, an arrival at 00:15 on a
     * 22:00 shift is 135 minutes late, which is the true answer and the one
     * minutes-since-midnight cannot produce.
     */
    public function latenessMinutes(Punch $punch): int
    {
        return (int) (($punch->at->getTimestamp() - $this->shiftStart->getTimestamp()) / 60);
    }

    /** Late beyond the declared grace, which is the figure a policy rule reads. */
    public function lateBeyondGrace(Punch $punch): int
    {
        return max(0, $this->latenessMinutes($punch) - $this->graceMinutes);
    }

    /** Minutes before the shift end, which is early exit. Negative means they stayed on. */
    public function earlyExitMinutes(Punch $punch): int
    {
        return max(0, (int) (($this->shiftEnd->getTimestamp() - $punch->at->getTimestamp()) / 60));
    }

    /**
     * Assign punches to this window.
     *
     * A punch outside the window is NOT dropped: it comes back in `outside`, so
     * the caller can attribute it to an adjacent day or raise it as unattributed.
     * Silently discarding a swipe is how somebody's overtime disappears.
     *
     * @param  list<Punch>  $punches
     * @return array{inside: list<Punch>, outside: list<Punch>}
     */
    public function assign(array $punches): array
    {
        $inside = [];
        $outside = [];

        foreach ($punches as $punch) {
            if ($this->contains($punch)) {
                $inside[] = $punch;
            } else {
                $outside[] = $punch;
            }
        }

        // Chronological by INSTANT. SmartPRS 2 orders by strcmp on a
        // concatenated date-time string, so an OUT at 06:00 sorts before an IN
        // at 22:00 and the night reads as a negative duration.
        usort($inside, static fn (Punch $a, Punch $b): int => $a->at <=> $b->at);

        return ['inside' => $inside, 'outside' => $outside];
    }

    public function describe(): string
    {
        return "{$this->shiftCode} on {$this->attendanceDate->format('D j M Y')}: "
            . $this->shiftStart->format('H:i') . ' → ' . $this->shiftEnd->format('H:i')
            . ($this->crossesMidnight() ? ' (+1 day)' : '')
            . ', window ' . $this->opensAt()->format('j H:i') . ' → ' . $this->closesAt()->format('j H:i');
    }
}
