<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Access;

/**
 * How several (role, scope) assignments compose into one answer — R-XXII-050.
 *
 *     Access is granted iff there EXISTS at least one assignment A, in force on
 *     the evaluation date, such that  p ∈ permissions(A.role)  AND  r ∈ resolve(A.scope).
 *
 * An existential over assignments. Not a cross-product of the two unions.
 *
 * That distinction is the whole class. The wrong implementation is one line
 * shorter and reads perfectly sensibly —
 *
 *     union(permissions).contains(p) && union(scopes).covers(r)     // WRONG
 *
 * — and it is the single most common way a role model of this shape leaks.
 * Priya holds *Branch HR* (`employee.record.update`) scoped to Hyderabad and
 * *Payroll Viewer* (`payroll.run.view`) scoped to Tenant. Her permission union
 * contains `employee.record.update`; her scope union is the whole tenant; the
 * wrong line therefore lets her update a Chennai employee record. The right one
 * asks each assignment on its own, and no single assignment carries both.
 *
 * The permission union of R-XXII-030 is real, but it is a *union over
 * permissions only* (R-XXII-031). Scopes never union. Each permission is
 * exercised only inside the scope of the assignment that granted it.
 */
final readonly class ScopeComposition
{
    /** @param list<Assignment> $assignments */
    public function __construct(private array $assignments)
    {
    }

    /** The assignments in force on the evaluation date. @return list<Assignment> */
    public function inForce(\DateTimeImmutable $asOf): array
    {
        return array_values(array_filter(
            $this->assignments,
            static fn (Assignment $a): bool => $a->isInForce($asOf),
        ));
    }

    /**
     * The assignments that carry the permission — the ones whose scopes matter.
     * An assignment with an unresolved role carries nothing (fail closed); the
     * decider reports that separately as a defect rather than silently skipping.
     *
     * @return list<Assignment>
     */
    public function carrying(Permission|string $permission, \DateTimeImmutable $asOf): array
    {
        return array_values(array_filter(
            $this->inForce($asOf),
            static fn (Assignment $a): bool => $a->role?->grants($permission) === true,
        ));
    }

    /**
     * R-XXII-050, evaluated. The existential, per assignment, on both axes.
     *
     * @throws ScopeResolutionFailed a scope that cannot be evaluated is a denial
     *         at the decider, never a skipped assignment that lets a later one
     *         answer for it.
     */
    public function grants(Permission|string $permission, SubjectContext $subject, \DateTimeImmutable $asOf): bool
    {
        foreach ($this->carrying($permission, $asOf) as $assignment) {
            if ($assignment->scope?->covers($subject) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * The effective permission set — the union over permissions, R-XXII-030.
     *
     * This is what a "what can this user do?" screen renders, and it is exactly
     * NOT an authorisation input: holding a permission somewhere says nothing
     * about reaching a given record. grants() is the authorisation input.
     *
     * @return list<string>
     */
    public function effectivePermissions(\DateTimeImmutable $asOf): array
    {
        $codes = [];

        foreach ($this->inForce($asOf) as $assignment) {
            foreach ($assignment->role?->permissionCodes() ?? [] as $code) {
                $codes[$code] = true;
            }
        }

        ksort($codes);

        return array_keys($codes);
    }

    /**
     * R-XXII-052: for a LIST query, the disjunction of the scopes of every
     * assignment that carries the required permission — and nothing else. The
     * same rule as grants(), expressed for the query-scoping layer instead of
     * for one record, so a list cannot leak what a single-record check denies.
     *
     * @return list<array<string, mixed>> OR these together, AND the tenant.
     */
    public function listPredicate(
        Permission|string $permission,
        SubjectContext $subject,
        \DateTimeImmutable $asOf,
    ): array {
        return array_map(
            static fn (Assignment $a): array => $a->scope?->predicate($subject)
                ?? throw new ScopeResolutionFailed(
                    "Assignment '{$a->scopeKey}' carries the permission but its scope did not "
                    . 'resolve; an unscoped list query is refused (R-XXII-094).'
                ),
            $this->carrying($permission, $asOf),
        );
    }
}
