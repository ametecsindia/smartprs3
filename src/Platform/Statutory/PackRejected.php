<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Statutory;

/**
 * REJECTED OUTRIGHT — Part XVII R-XVII-009.
 *
 * "Not staged, not partially applied, not warned about." There is one outcome
 * for a pack that fails any check, and this is it. The reasons travel with the
 * exception because an operator alert that says "pack rejected" sends somebody
 * to a log file, and the log file is where this stops being actionable.
 *
 * Every reason is reported, never just the first. A pack fixed one refusal at a
 * time is a pack imported five times, and the fifth import is the one somebody
 * stops reading.
 */
final class PackRejected extends \RuntimeException
{
    /** @param list<string> $reasons */
    public function __construct(public readonly array $reasons, public readonly ?string $packKey = null)
    {
        parent::__construct(
            'Statutory pack ' . ($packKey ?? '(unidentified)') . ' REJECTED — ' . count($reasons)
            . " reason(s):\n  · " . implode("\n  · ", $reasons)
        );
    }

    public function mentions(string $needle): bool
    {
        foreach ($this->reasons as $reason) {
            if (str_contains($reason, $needle)) {
                return true;
            }
        }

        return false;
    }
}
