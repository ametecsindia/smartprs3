<?php

declare(strict_types=1);

namespace SmartPRS\Platform\ModulePack;

/**
 * A module pack that will not be installed — Part XXXII Phase 7.
 *
 * Every reason, never the first, and nothing is installed before the reasons are
 * known. A pack applied halfway leaves a tenant holding item types whose
 * workflows never arrived, and the register then shows obligations nobody can
 * discharge.
 */
final class ModulePackRejected extends \RuntimeException
{
    /** @param list<string> $reasons */
    public function __construct(public readonly array $reasons, public readonly string $packKey = '')
    {
        parent::__construct(
            "Module pack {$packKey} REJECTED — " . count($reasons) . " reason(s):\n  · "
            . implode("\n  · ", $reasons)
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
