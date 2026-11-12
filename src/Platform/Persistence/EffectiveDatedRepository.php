<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Persistence;

/**
 * Spine Law 3 / S4 — the as-at query layer.
 *
 * `asOf($date)` returns a VIEW of this repository in which every read is already
 * narrowed to the rows in force on that day. It composes with TenantScopedQuery
 * rather than replacing it, so a read carries both predicates and a caller can
 * remove neither:
 *
 *     $history->asOf($fifteenthOfMay)->inForce($employment)
 *
 * THE POINT OF THE THROW. A repository that has not been given a date does not
 * read. It does not fall back to today, it does not return every version, it
 * raises AsAtDateRequired. SmartPRS 2's configuration model has no as-at concept
 * at all, so a recompute of a March payroll silently resolves the policy rows
 * that exist when the job runs — the single most dangerous property of that
 * system (spine S4). "Today" is a defensible default for a screen and a
 * catastrophic one for a recompute, and this layer cannot tell which it is
 * serving, so it refuses to guess.
 *
 * `asOf()` returns a clone rather than mutating, so one repository instance
 * serves two dates in the same request — a retro comparison reads "before" and
 * "after" from the same object without either leaking into the other.
 */
abstract class EffectiveDatedRepository extends Repository
{
    /** Overridden by a table that spells its range differently. */
    protected const EFFECTIVE_FROM = 'effective_from';

    protected const EFFECTIVE_TO = 'effective_to';

    /**
     * Not readonly, and the only mutable state in the persistence layer: `clone`
     * is what makes the view cheap, and a readonly property cannot be set on a
     * clone. It is private, set in exactly one place, and never set twice on the
     * same instance.
     */
    private ?\DateTimeImmutable $asOf = null;

    /** A view of this repository as at one calendar day. */
    public function asOf(\DateTimeImmutable $date): static
    {
        // Dates are calendar days, not instants (spine S16): take the day the
        // caller means, in their timezone, and rebuild it in UTC — the same
        // normalisation Support\EffectiveRange applies.
        $view = clone $this;
        $view->asOf = new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC'));

        return $view;
    }

    /** The date this view reads at. Throws rather than assuming one. */
    public function asAt(): \DateTimeImmutable
    {
        return $this->asOf ?? throw new AsAtDateRequired(
            'This repository reads an effective-dated table and has no as-at date. '
            . 'Call asOf($date) — there is no "today" default, because a historical '
            . 'recompute would silently take current rows (spine S4).'
        );
    }

    /** Every read, already narrowed to the rows in force. */
    protected function query(): TenantScopedQuery
    {
        return parent::query()->whereInForceOn(
            static::EFFECTIVE_FROM,
            static::EFFECTIVE_TO,
            $this->asAt()->format('Y-m-d'),
        );
    }

    /**
     * The full timeline, tenancy applied and the as-at predicate deliberately
     * not. Named so it cannot be reached by accident and so a reviewer can grep
     * for every audit-view read; a caller that wants one version calls query().
     */
    protected function allVersions(): TenantScopedQuery
    {
        return parent::query();
    }

    /**
     * The date at which a data scope resolves the actor's org placement and
     * reporting line. For an as-at view that is the view's own date — reading
     * March's rows through today's org chart is exactly the mismatch R-XXII-043
     * exists to prevent.
     */
    protected function scopeAsAt(): ?\DateTimeImmutable
    {
        return $this->asOf;
    }
}
