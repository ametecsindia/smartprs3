<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Calendar;

/**
 * THE ANSWER TO "IS THIS A WEEKLY OFF?" — Part XII §XII.2.1, R-XII-001.
 *
 * A boolean plus WHICH pattern decided, at which version, for which reason, and
 * which of the entitled offs this is. The extra fields are not diagnostics: a
 * muster roll printed today for last March has to reproduce last March, which
 * means the answer has to carry the version that produced it (R-XII-007).
 *
 * `Unresolved` is the fourth state and the important one. R-XII-003: the
 * evaluator is pure and TOTAL, and "a resolution failure returns a typed error
 * verdict that the caller must handle; it never defaults to working or to off."
 * SmartPRS 2's absence sweep marks the entire workforce absent when the holiday
 * query throws — a whole company's attendance destroyed by one exception, because
 * the failure had nowhere to go but into the answer.
 */
final readonly class WeeklyOffVerdict
{
    public const ROSTER_CELL = 'roster_cell';
    public const PATTERN_FIXED = 'pattern_fixed';
    public const PATTERN_NTH = 'pattern_nth';
    public const PATTERN_ROTATION = 'pattern_rotation';
    public const PATTERN_ELECTED = 'pattern_elected';
    public const PATTERN_COUNT_ALLOCATED = 'pattern_count_allocated';
    public const NOT_OFF = 'not_off';
    public const UNRESOLVED = 'unresolved';

    private function __construct(
        public bool $isWeeklyOff,
        public string $reason,
        public ?string $patternCode = null,
        public ?string $patternVersionId = null,
        public ?int $slotIndex = null,
        public ?string $error = null,
        public string $trace = '',
    ) {
    }

    public static function off(
        string $reason,
        string $patternCode,
        string $patternVersionId,
        ?int $slotIndex = null,
        string $trace = '',
    ): self {
        return new self(true, $reason, $patternCode, $patternVersionId, $slotIndex, null, $trace);
    }

    public static function working(string $patternCode, string $patternVersionId, string $trace = ''): self
    {
        return new self(false, self::NOT_OFF, $patternCode, $patternVersionId, null, null, $trace);
    }

    /**
     * The typed error verdict.
     *
     * `isWeeklyOff` is false here, and that is NOT the answer — `isUnresolved()`
     * is what a caller must check. Making the boolean false rather than true is
     * the conservative half of a bad situation: a caller that ignores the error
     * treats the day as working, which produces a visible absence somebody
     * queries, rather than a silent day off that nobody ever notices.
     */
    public static function unresolved(string $error, string $trace = ''): self
    {
        return new self(false, self::UNRESOLVED, null, null, null, $error, $trace);
    }

    public function isUnresolved(): bool
    {
        return $this->error !== null;
    }

    public function describe(): string
    {
        if ($this->isUnresolved()) {
            return 'UNRESOLVED: ' . $this->error;
        }

        return ($this->isWeeklyOff ? 'weekly off' : 'a working day')
            . ' by ' . $this->reason
            . ($this->patternCode === null ? '' : " ({$this->patternCode} v{$this->patternVersionId}")
            . ($this->slotIndex === null ? '' : ", off #{$this->slotIndex}")
            . ($this->patternCode === null ? '' : ')');
    }
}
