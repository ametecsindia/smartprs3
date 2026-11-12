<?php
declare(strict_types=1);

namespace SmartPRS\TimeAttendance\Domain;

use SmartPRS\TimeAttendance\Domain\Clock;

/** CLEAN FIXTURE — the instant and the id both arrive as inputs. */
final class LateMarkRule
{
    public function __construct(
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
    ) {
    }

    public function evaluate(BusinessDate $day, Instant $punch): LateMarkVerdict
    {
        $reference = $this->clock->now();
        $grace = $day->shift()->graceWindow();

        return new LateMarkVerdict(
            $this->ids->next(),
            $punch->isAfter($grace->endsAt()),
            $reference,
        );
    }
}
