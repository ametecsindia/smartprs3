<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Access;

/**
 * Everything a data scope needs in order to answer "does this assignment reach
 * this record?", resolved once per decision.
 *
 * It carries both the subject's facts and the actor's identity, because every
 * scope predicate is *relative to the actor*: `self` means the actor's own
 * record, `direct_reports` means reporting to the actor, `tenant` means the
 * actor's tenant. A "subject context" with no actor in it could not evaluate a
 * single scope type.
 *
 * The org facts are already resolved **as at the evaluation date** by the caller
 * — R-XXII-043 requires effective-dated reporting and org assignment, never a
 * current-state column, and that resolution belongs to the Organisation module's
 * repositories, not here. This class is the answer, not the query.
 *
 * ponytail: no dimension values yet, so DataScope has no `dimension_filter`
 * resolver (see DataScope::TYPES). Add both together, reusing Support\Scope,
 * when the S3.1 dimension set is available on an employment row.
 */
final readonly class SubjectContext
{
    /**
     * @param  string       $tenantId       tenant owning the subject record
     * @param  string       $actorTenantId  tenant the actor is acting in
     * @param  ?string      $actorEmployeeId  users.employee_id; NULL when the
     *                      actor has no employee binding — R-XXII-042 makes that
     *                      the empty set, never the tenant
     * @param  ?string      $employeeId     the subject employee; NULL for an
     *                      org-owned record (a roster, a holiday calendar)
     * @param  list<string> $orgUnitPath    the subject's org units, root → leaf,
     *                      inclusive of the leaf. Descendant containment is a
     *                      set intersection against this.
     * @param  array<string,string> $unitsByType  unit type => the subject's unit
     *                      of that type, for exact (non-descendant) matching
     * @param  list<string> $managerChain   reporting managers, immediate first,
     *                      as in force at the evaluation date
     * @param  bool         $actorInPlatformTenant  R-XXII-048: `platform` scope
     *                      exists only in the platform tenant
     */
    public function __construct(
        public string $tenantId,
        public string $actorTenantId,
        public ?string $actorEmployeeId = null,
        public ?string $employeeId = null,
        public array $orgUnitPath = [],
        public array $unitsByType = [],
        public array $managerChain = [],
        public bool $actorInPlatformTenant = false,
    ) {
        // A trust boundary, so it is validated here rather than trusted later.
        // An empty tenant id is SmartPRS 2's sentinel-tenant bug in embryo, and
        // R-XXII-094 denies on a tenant of `0` or any sentinel.
        if ($tenantId === '' || $actorTenantId === '') {
            throw new \InvalidArgumentException(
                'SubjectContext requires a resolved tenant on both sides; an empty or '
                . 'sentinel tenant id is a denial (R-XXII-094), never a wildcard.'
            );
        }
    }

    /**
     * Can this subject be placed at all? A scope that narrows records has
     * nothing to narrow against otherwise, and "cannot place" is a denial, not
     * a pass — this is precisely the branch SmartPRS 2's selfScope() returns
     * `null` from, meaning no narrowing, meaning the whole workforce.
     */
    public function isResolvable(): bool
    {
        return $this->employeeId !== null || $this->orgUnitPath !== [] || $this->unitsByType !== [];
    }

    public function sameTenant(): bool
    {
        return $this->tenantId === $this->actorTenantId;
    }
}
