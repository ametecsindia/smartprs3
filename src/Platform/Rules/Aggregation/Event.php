<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules\Aggregation;

/**
 * One settled event, as the aggregation primitives see it — Part VII §5.9.3.
 *
 * `event.*` is available inside a predicate and NOWHERE else, so this is the
 * whole of what a predicate may read. In particular `branch` is the org unit AS
 * AT THE EVENT DATE, not today: an employee who transferred in June must not
 * have their May lates counted against their new branch's rule.
 *
 * The event store is the caller's; this is the shape it hands over.
 */
final readonly class Event
{
    public function __construct(
        public string $code,
        public \DateTimeImmutable $instant,
        public int|string|null $value = null,
        public ?string $source = null,
        public ?string $status = null,
        public ?string $shift = null,
        public ?string $branch = null,
    ) {
        if (trim($code) === '') {
            throw new \InvalidArgumentException('An event carries its catalogue code. The catalogue is closed (§5.9.2).');
        }
    }

    public function date(): \DateTimeImmutable
    {
        return $this->instant->setTime(0, 0);
    }

    /** The `event.*` namespace, for a predicate to read. */
    public function scope(): array
    {
        return [
            'event.date' => $this->date(),
            'event.instant' => $this->instant,
            'event.value' => $this->value,
            'event.source' => $this->source,
            'event.status' => $this->status,
            'event.shift' => $this->shift,
            'event.branch' => $this->branch,
        ];
    }
}
