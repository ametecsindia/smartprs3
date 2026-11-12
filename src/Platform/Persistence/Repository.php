<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Persistence;

use SmartPRS\Platform\Access\DataScope;
use SmartPRS\Platform\Access\ScopeResolutionFailed;
use SmartPRS\Platform\Access\SubjectContext;
use SmartPRS\Platform\Organisation\OrgUnitRepository;
use SmartPRS\Platform\Organisation\ReportingLine;
use SmartPRS\Platform\Tenancy\TenantContext;

/**
 * R-XXII-080 — the scoping decorator, in the order the blueprint states it:
 * tenancy first (unconditional, from the context), then the data scope of the
 * actor's assignments. Soft-state filters (step 3) belong to the concrete
 * repository, which knows whether its table has them.
 *
 * This is a base class and not an ORM. It has one method that matters. If it
 * ever grows relations, hydration, dirty tracking or a `Model::` facade, delete
 * it and use Eloquent, which already does all of that better.
 *
 * THE DATA-SCOPE SEAM. `Access\DataScope::predicate()` deliberately returns a
 * portable *descriptor* rather than SQL — it will not hard-code table names, and
 * the gates keep that layer framework- and SQL-free. Compiling the descriptor
 * into a predicate is therefore this layer's job, and it is done here.
 *
 * Every descriptor that has tables to narrow against is compiled here.
 * `direct_reports`, `reporting_tree` and the unit types resolve against
 * `employment_assignments` and `org_units` — the effective-dated placement of
 * V.6, not a current-state column (R-XXII-043). What remains uncompiled throws.
 * R-XXII-094: a scope that cannot be resolved denies, and it denies rather than
 * emitting nothing and returning an unfiltered result.
 *
 * THE AS-AT DATE IS PART OF THE SCOPE. "Which employees does this manager
 * reach?" has no answer without a date, because the answer changed when
 * somebody transferred. A repository that has no date and is handed a
 * placement-dependent scope therefore denies too — see scopeAsAt(), which an
 * EffectiveDatedRepository answers from its own view and any other repository
 * overrides with the date its records belong to (a payslip's period end, an
 * attendance day). Defaulting to today would silently re-resolve a March
 * approval through October's org chart.
 */
abstract class Repository
{
    /**
     * The column a `self` scope narrows on. Overridden by a repository whose
     * table names the employee differently; there is no configuration file for
     * this and there does not need to be.
     */
    protected const EMPLOYEE_COLUMN = 'employee_id';

    public function __construct(
        protected readonly \PDO $pdo,
        protected readonly TenantContext $tenant,
        protected readonly ?DataScope $scope = null,
        protected readonly ?SubjectContext $subject = null,
    ) {
        if ($scope !== null && $subject === null) {
            throw new \InvalidArgumentException(
                'A data scope is evaluated relative to the actor, so it needs a SubjectContext.'
            );
        }
    }

    /** The one table this repository owns. Modules do not read each other's. */
    abstract protected function table(): string;

    /**
     * Every read and write in a concrete repository starts here. There is no
     * second way to reach the database from a Repository subclass.
     */
    protected function query(): TenantScopedQuery
    {
        $query = TenantScopedQuery::on($this->pdo, $this->tenant, $this->table());

        if ($this->scope === null || $this->subject === null) {
            return $query;
        }

        $compiled = $this->compile($this->scope->predicate($this->subject));

        return $compiled === null ? $query : $query->whereScopePredicate($compiled[0], $compiled[1]);
    }

    /**
     * The date at which a placement-dependent scope resolves the actor's
     * reporting line and org units. NULL means "this repository has no opinion",
     * and a scope that needs one then denies rather than assuming today.
     */
    protected function scopeAsAt(): ?\DateTimeImmutable
    {
        return null;
    }

    /**
     * The `employment_assignments` column each unit scope type narrows against.
     * `unit_list` deliberately mixes types (R-XXII-046), so it matches any of
     * them.
     */
    private const UNIT_COLUMN = [
        'legal_entity' => 'legal_entity_org_unit_id',
        'company' => 'company_org_unit_id',
        'branch' => 'branch_org_unit_id',
        'department' => 'department_org_unit_id',
        'team' => 'team_org_unit_id',
        'location' => 'location_org_unit_id',
        // Not a DataScope type of its own, but `unit_list` mixes types and a
        // cost centre is a typed node that attaches at any level (spine S3).
        'cost_centre' => 'cost_centre_org_unit_id',
    ];

    /**
     * Descriptor => SQL fragment. NULL means "tenancy alone is the whole
     * scope"; it never means "no filter", because the tenant predicate is
     * always there.
     *
     * @param  array<string, mixed>  $descriptor  from DataScope::predicate()
     * @return array{0: string, 1: array<string, scalar|null>}|null
     *
     * @throws \SmartPRS\Platform\Organisation\ReportingCycleDetected  R-XXII-044.
     *         Deliberately not folded into ScopeResolutionFailed: both are
     *         DomainExceptions and AccessDecider's single catch denies on
     *         either, so nothing is lost by keeping the data defect legible.
     */
    private function compile(array $descriptor): ?array
    {
        $column = static::EMPLOYEE_COLUMN;

        return match ($descriptor['scope'] ?? '') {
            // The actor's own record. R-XXII-042: an actor with no employee
            // binding is the EMPTY set, never the tenant — which is exactly the
            // branch SmartPRS 2's selfScope() returns "no narrowing" from.
            'self' => $descriptor['employee_id'] === null
                ? ['1 = 0', []]
                : ["`{$column}` = :scope_employee", [':scope_employee' => $descriptor['employee_id']]],

            // R-XXII-043 — resolved from the dated reporting assignment, as at
            // the evaluation date. The walk is cycle-safe and depth-bounded;
            // both are ReportingLine's job, not this one's.
            'direct_reports', 'reporting_tree' => $this->reportingPredicate($column, $descriptor),

            // R-XXII-045/046 — org units by foreign key, with the descendant
            // flag honoured explicitly rather than inferred.
            'team', 'department', 'branch', 'location', 'company', 'legal_entity', 'unit_list'
                => $this->unitPredicate($column, $descriptor),

            // Tenant-wide: the tenant predicate already IS this scope. Asserted
            // rather than assumed, so an actor scoped to another tenant denies.
            'tenant' => $descriptor['tenant_id'] === $this->tenant->tenantId()
                ? null
                : throw new ScopeResolutionFailed(
                    'A tenant-wide scope for tenant ' . var_export($descriptor['tenant_id'], true)
                    . ' cannot be applied inside tenant ' . $this->tenant->tenantId() . '.'
                ),

            // R-XXII-119: cross-tenant reach is the escape hatch, not a scope
            // that a repository silently honours.
            'platform' => throw new CrossTenantRefused(
                'A platform scope does not widen a repository query. Cross-tenant reads go '
                . 'through CrossTenantEscape, which requires a reason and writes an audit row.'
            ),

            // `dimension_filter` lands here: DataScope declares it and this
            // build has no resolver, because the S3.1 dimension VALUES are not
            // yet on an employment row. Declared-but-unresolvable denies.
            default => throw new ScopeResolutionFailed(
                "Data scope '" . (string) ($descriptor['scope'] ?? '?') . "' has no compiler in this "
                . 'build — the tables it narrows against do not exist yet. The query '
                . 'would be unscoped, so it is refused (R-XXII-094).'
            ),
        };
    }

    /**
     * `IN (…)` over a walked reporting tree, and not a correlated subquery.
     *
     * A recursive CTE would keep the ids in the database, but it cannot deny on
     * a cycle — it either recurses until the server's limit or drops the
     * repeated edge and hands the manager their own superiors' reports. The walk
     * is bounded by DataScope::MAX_REPORTING_DEPTH, so the list is bounded too;
     * this is the one place where the "never a materialised id list" note on
     * DataScope::predicate() is deliberately traded for R-XXII-044.
     *
     * ponytail: revisit if a depth-12 tree under a group CEO makes the list
     * genuinely large — the replacement is a materialised closure table
     * maintained by the assignment write service, not a cleverer query.
     *
     * @param  array<string, mixed>  $descriptor
     * @return array{0: string, 1: array<string, scalar|null>}
     */
    private function reportingPredicate(string $column, array $descriptor): array
    {
        $managerId = $descriptor['manager_id'] ?? null;

        // R-XXII-042 again: no employee binding is the empty set, not the tenant.
        if ($managerId === null) {
            return ['1 = 0', []];
        }

        $asAt = $this->scopeAsAtOrDeny((string) $descriptor['scope']);

        $ids = (new ReportingLine($this->pdo, $this->tenant))
            ->reportingTreeOf((string) $managerId, (int) $descriptor['depth'], $asAt);

        if ($descriptor['include_self'] === true) {
            array_unshift($ids, (string) $managerId);
        }

        return $this->inList($column, $ids, 'scope_report');
    }

    /**
     * A subquery over the assignment in force on the scope's date. The join
     * condition names every dimension column the scope type covers, so
     * `unit_list` — which mixes types on purpose — is the same shape as
     * `branch`, with a longer IN.
     *
     * Containment uses the materialised path, so "this unit and everything
     * under it" is a prefix comparison on an indexed column rather than a
     * recursion. The scope's own units are resolved to paths once here, not per
     * row.
     *
     * @param  array<string, mixed>  $descriptor
     * @return array{0: string, 1: array<string, scalar|null>}
     */
    private function unitPredicate(string $column, array $descriptor): array
    {
        $type = (string) $descriptor['scope'];
        $asAt = $this->scopeAsAtOrDeny($type);

        /** @var list<string> $units */
        $units = $descriptor['org_units'];
        $paths = (new OrgUnitRepository($this->pdo, $this->tenant))->pathsOf($units);

        // A unit the tenant does not own resolves to nothing, and nothing is the
        // empty set — a scope naming another tenant's branch reaches no rows.
        if ($paths === []) {
            return ['1 = 0', []];
        }

        $dimensions = $type === 'unit_list'
            ? array_values(self::UNIT_COLUMN)
            : [self::UNIT_COLUMN[$type]];

        // The date is bound twice under two names: PDO with emulation off binds
        // a named parameter once only, and this layer must not depend on which.
        $bindings = [
            ':scope_tenant' => $this->tenant->tenantId(),
            ':scope_at_from' => $asAt->format('Y-m-d'),
            ':scope_at_to' => $asAt->format('Y-m-d'),
        ];
        $conditions = [];
        $i = 0;

        foreach ($paths as $unitId => $path) {
            if ($descriptor['include_descendants'] === true) {
                $name = ':scope_path' . $i++;
                $conditions[] = "u.`path` LIKE {$name}";
                $bindings[$name] = $path . '%';

                continue;
            }

            $name = ':scope_unit' . $i++;
            $conditions[] = "u.`id` = {$name}";
            $bindings[$name] = $unitId;
        }

        $columns = implode(', ', array_map(static fn (string $c): string => "a.`{$c}`", $dimensions));

        return [
            "`{$column}` IN (SELECT a.`employment_id` FROM `employment_assignments` a"
            . " JOIN `org_units` u ON u.`tenant_id` = a.`tenant_id` AND u.`id` IN ({$columns})"
            . ' WHERE a.`tenant_id` = :scope_tenant'
            . ' AND a.`effective_from` <= :scope_at_from'
            . ' AND (a.`effective_to` IS NULL OR a.`effective_to` >= :scope_at_to)'
            . ' AND (' . implode(' OR ', $conditions) . '))',
            $bindings,
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return array{0: string, 1: array<string, scalar|null>}
     */
    private function inList(string $column, array $ids, string $prefix): array
    {
        if ($ids === []) {
            return ['1 = 0', []];
        }

        $names = [];
        $bindings = [];

        foreach (array_values(array_unique($ids)) as $i => $id) {
            $names[] = $name = ':' . $prefix . $i;
            $bindings[$name] = $id;
        }

        return ["`{$column}` IN (" . implode(', ', $names) . ')', $bindings];
    }

    private function scopeAsAtOrDeny(string $type): \DateTimeImmutable
    {
        return $this->scopeAsAt() ?? throw new ScopeResolutionFailed(
            "A '{$type}' scope resolves against the effective-dated placement of V.6 and this "
            . 'repository has no as-at date. Extend EffectiveDatedRepository, or override '
            . 'scopeAsAt() with the date these records belong to. R-XXII-043 forbids resolving '
            . 'it from current state, and R-XXII-094 makes an unresolvable scope a denial.'
        );
    }
}
