<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Config;

use SmartPRS\Platform\Support\AmbiguousConfiguration;
use SmartPRS\Platform\Support\UnresolvedConfiguration;

/**
 * Spine S4 A7: resolution is ALWAYS resolve(type, key, scope, as_of_date).
 * There is no "current" read anywhere. August payroll resolves with a date
 * inside August, so a policy published in October cannot reach back into it.
 *
 * Pure over an injected candidate list — no database access in the domain. The
 * repository loads the published versions for (tenant, type, key); this class
 * decides which one applies, and refuses to guess.
 */
final readonly class ArtefactResolver
{
    /** @param list<ConfigVersionCandidate> $candidates */
    public function __construct(private array $candidates)
    {
    }

    /**
     * @param  array<string, string|int|null>  $subjectDimensions  the employment's
     *         dimensions as at $asOf (never from a current-valued column — V.5.2)
     */
    public function resolve(
        string $type,
        string $key,
        array $subjectDimensions,
        \DateTimeImmutable $asOf,
    ): ConfigVersionCandidate {
        $applicable = array_values(array_filter(
            $this->candidates,
            static fn (ConfigVersionCandidate $c): bool => $c->artefactType === $type
                && $c->artefactKey === $key
                && $c->range->contains($asOf)
                && $c->scope->matches($subjectDimensions),
        ));

        if ($applicable === []) {
            throw new UnresolvedConfiguration(
                "No published version of {$type}:{$key} is effective on "
                . $asOf->format('Y-m-d') . ' for this subject.'
            );
        }

        $winner = $applicable[0];

        foreach (array_slice($applicable, 1) as $challenger) {
            // Throws AmbiguousConfiguration naming both when neither can win.
            if ($winner->scope->compareTo(
                $challenger->scope,
                $winner->priority,
                $challenger->priority,
                $winner->label(),
                $challenger->label(),
            ) < 0) {
                $winner = $challenger;
            }
        }

        return $winner;
    }

    /**
     * Publish-time check (spine S4: "Overlap within one artefact and scope is
     * rejected at publish time", V.8.2 A3). Two published versions of one
     * artefact whose scopes are identical and whose ranges overlap cannot
     * coexist, whatever their priorities.
     */
    public function assertNoOverlap(): void
    {
        // ponytail: O(n²) pair scan. n is the published version count of one
        // artefact — tens, not thousands. Sort by (scope, effective_from) and
        // scan adjacent pairs if an artefact ever carries a long version history.
        $count = count($this->candidates);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $this->candidates[$i];
                $b = $this->candidates[$j];

                if ($a->artefactType === $b->artefactType
                    && $a->artefactKey === $b->artefactKey
                    && $a->scope->predicates === $b->scope->predicates
                    && $a->range->overlaps($b->range)
                ) {
                    throw new AmbiguousConfiguration(
                        [$a->label(), $b->label()],
                        "Their effective ranges overlap ({$a->range} and {$b->range}) on an identical scope."
                    );
                }
            }
        }
    }
}
