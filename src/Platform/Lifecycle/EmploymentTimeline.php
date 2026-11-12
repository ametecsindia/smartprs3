<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Lifecycle;

/**
 * ONE TIMELINE, NOT A COLUMN PER EVENT — Part XX, Part XXXII Phase 7.
 *
 * Probation, confirmation, increment, promotion, transfer, resignation and exit
 * are DATED EVENTS on one timeline, not a scatter of columns on an employment
 * row. The difference shows up the first time somebody asks "what was their
 * grade in August?" — a column answers with today's grade, and a timeline answers
 * the question that was asked.
 *
 * It also makes the ordering enforceable. An employee cannot be promoted after
 * they exit, confirmed before they join, or resigned twice; those are not
 * validation niceties but the shapes that produce a payslip for somebody who
 * left in March, which SmartPRS 2 issues because leaving is a flag rather than an
 * event with a date.
 */
final class EmploymentTimeline
{
    public const JOINED = 'joined';
    public const PROBATION_STARTED = 'probation_started';
    public const CONFIRMED = 'confirmed';
    public const INCREMENT = 'increment';
    public const PROMOTED = 'promoted';
    public const TRANSFERRED = 'transferred';
    public const RESIGNED = 'resigned';
    public const EXITED = 'exited';

    public const TERMINAL = [self::EXITED];

    /** @var list<array{kind: string, on: \DateTimeImmutable, detail: array<string, string>, reason: string}> */
    private array $events = [];

    public function __construct(public readonly string $employmentId)
    {
    }

    /**
     * @param array<string, string> $detail
     * @throws \InvalidArgumentException on an ordering that cannot have happened
     */
    public function record(
        string $kind,
        \DateTimeImmutable $on,
        array $detail = [],
        string $reason = '',
    ): void {
        if ($this->events === [] && $kind !== self::JOINED) {
            throw new \InvalidArgumentException(
                "The first event on {$this->employmentId} is '{$kind}'. An employment begins by joining; "
                . 'anything else first is an event attached to nobody.'
            );
        }

        $last = $this->events[count($this->events) - 1] ?? null;

        if ($last !== null && $on < $last['on']) {
            throw new \InvalidArgumentException(
                "A '{$kind}' on {$on->format('Y-m-d')} is earlier than the previous event on "
                . $last['on']->format('Y-m-d') . ". Back-dating into the timeline rewrites what was true "
                . 'then; a correction is a correction event, recorded now, saying what it changes.'
            );
        }

        if ($last !== null && in_array($last['kind'], self::TERMINAL, true)) {
            throw new \InvalidArgumentException(
                "{$this->employmentId} has exited, and '{$kind}' cannot happen afterwards. This is the "
                . 'shape that produces a payslip for somebody who left in March — which happens when '
                . 'leaving is a flag rather than an event with a date.'
            );
        }

        if ($kind === self::RESIGNED && $this->has(self::RESIGNED)) {
            throw new \InvalidArgumentException("{$this->employmentId} has already resigned.");
        }

        if (in_array($kind, [self::RESIGNED, self::EXITED, self::PROMOTED, self::TRANSFERRED], true)
            && trim($reason) === ''
        ) {
            throw new \InvalidArgumentException(
                "A '{$kind}' with no recorded reason is a change to somebody's employment that nobody has "
                . 'to justify.'
            );
        }

        $this->events[] = ['kind' => $kind, 'on' => $on, 'detail' => $detail, 'reason' => $reason];
    }

    public function has(string $kind): bool
    {
        foreach ($this->events as $event) {
            if ($event['kind'] === $kind) {
                return true;
            }
        }

        return false;
    }

    /** What a detail field was ON A DATE — the question a column cannot answer. */
    public function valueOn(string $field, \DateTimeImmutable $asOf): ?string
    {
        $value = null;

        foreach ($this->events as $event) {
            if ($event['on'] > $asOf) {
                break;
            }

            if (array_key_exists($field, $event['detail'])) {
                $value = $event['detail'][$field];
            }
        }

        return $value;
    }

    public function isActiveOn(\DateTimeImmutable $asOf): bool
    {
        $active = false;

        foreach ($this->events as $event) {
            if ($event['on'] > $asOf) {
                break;
            }

            $active = match ($event['kind']) {
                self::JOINED => true,
                self::EXITED => false,
                default => $active,
            };
        }

        return $active;
    }

    /** @return list<array{kind: string, on: \DateTimeImmutable, detail: array<string, string>, reason: string}> */
    public function events(): array
    {
        return $this->events;
    }
}
