<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Access;

/**
 * A data scope — the second of the two orthogonal questions in spine S13.
 * The permission answers *may this actor perform this verb?*; the scope answers
 * *on which records?* (Part XXII §XXII.3.)
 *
 * SmartPRS 2 has exactly one scope mechanism: `selfScope()` narrows to the
 * caller's own record **if and only if** the caller holds the literal role
 * string `employee`, and returns `null` — no narrowing at all — for every other
 * non-privileged role. `accountant`, `field_agent`, `manager`, `hr` and any
 * role-less user therefore receive the whole workforce including `ctc`, `pan`,
 * `uan` and bank details. Every default in this class runs the other way: an
 * unrecognised type throws, an unplaceable subject is not covered, a null answer
 * does not exist.
 *
 * A scope is a value here. In the database it is a named, versioned, reusable
 * tenant-owned object (`access_scope`, R-XXII-049) shared by every assignment
 * that grants the same reach, which is why `key` and `version` are carried —
 * R-XXII-271 requires the decision trace to name the artefact version it used.
 */
final readonly class DataScope
{
    /**
     * `access_scope_types` — the platform constant table of R-XXII-040, seeded
     * by migration and NOT tenant-editable, because a customer inventing a scope
     * type would invent a resolution algorithm no code implements.
     *
     * `dimension_filter` is declared here and deliberately has **no resolver**
     * in this build: it needs the S3.1 dimension values on an employment row,
     * which do not exist yet. covers() therefore throws for it rather than
     * guessing, and the decider denies. That is the correct shape of "the table
     * declares a type this release cannot resolve".
     */
    public const TYPES = [
        'self', 'direct_reports', 'reporting_tree',
        'team', 'department', 'branch', 'location', 'company', 'legal_entity', 'unit_list',
        'dimension_filter',
        'tenant', 'platform',
    ];

    /** Types resolved against org units rather than the reporting graph. */
    private const UNIT_TYPES = ['team', 'department', 'branch', 'location', 'company', 'legal_entity', 'unit_list'];

    /**
     * `access.reporting_tree.max_depth` (R-XXII-044). A platform maximum, not a
     * business value: it bounds a graph walk so an unbounded tree and a cyclic
     * one both terminate. An unbounded scope (`depth = NULL`) is evaluated at
     * this depth, so "unbounded" can never mean "unbounded work".
     *
     * ponytail: a constant until platform constants are a table. Move it there
     * with the rest of `access.*` — do not make it tenant-editable.
     */
    public const MAX_REPORTING_DEPTH = 12;

    /** @param list<string> $units org_unit ids — never names (R-XXII-045) */
    private function __construct(
        public string $type,
        public array $units,
        public ?int $depth,
        public bool $includeSelf,
        public bool $includeDescendants,
        public string $key,
        public int $version,
    ) {
    }

    /**
     * @param  list<mixed>  $units  declared wide: this is the boundary that decodes a
     *         tenant's `access_scope` row (R-XXII-049), so PHP gives no static guarantee
     *         the elements are strings — the loop below is what actually enforces it.
     * @param  ?bool  $includeDescendants  NULL takes the R-XXII-046 default:
     *         true for `department` and `unit_list`, false for `team`. Never
     *         inferred from anything else.
     */
    public static function of(
        string $type,
        array $units = [],
        ?int $depth = null,
        bool $includeSelf = false,
        ?bool $includeDescendants = null,
        string $key = '',
        int $version = 1,
    ): self {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(
                "Unknown data scope type '{$type}'. The scope-type vocabulary is a "
                . 'platform constant table (R-XXII-040): ' . implode(', ', self::TYPES) . '.'
            );
        }

        $isUnitType = in_array($type, self::UNIT_TYPES, true);

        if ($isUnitType && $units === []) {
            throw new \InvalidArgumentException("A '{$type}' scope must name at least one org unit.");
        }

        if (!$isUnitType && $units !== []) {
            throw new \InvalidArgumentException("A '{$type}' scope resolves without org units; none may be named.");
        }

        foreach ($units as $unit) {
            if (!is_string($unit) || $unit === '') {
                throw new \InvalidArgumentException('Org units are non-empty ids, never names or blanks (R-XXII-045).');
            }
        }

        if ($depth !== null && $type !== 'reporting_tree') {
            throw new \InvalidArgumentException("Only a 'reporting_tree' scope carries a depth.");
        }

        if ($depth !== null && ($depth < 1 || $depth > self::MAX_REPORTING_DEPTH)) {
            throw new \InvalidArgumentException(
                "reporting_tree depth {$depth} is outside 1.." . self::MAX_REPORTING_DEPTH
                . ' (access.reporting_tree.max_depth, R-XXII-044).'
            );
        }

        return new self(
            $type,
            array_values($units),
            $depth,
            $includeSelf,
            $includeDescendants ?? $type !== 'team',
            $key === '' ? strtoupper($type) : $key,
            $version,
        );
    }

    /** The three immutable system scopes of R-XXII-048. */
    public static function selfOnly(): self
    {
        return self::of('self', key: 'SELF');
    }

    public static function tenantWide(): self
    {
        return self::of('tenant', key: 'TENANT');
    }

    /** Platform tenant only. R-XXII-048 forbids it existing inside a customer tenant. */
    public static function platformWide(): self
    {
        return self::of('platform', key: 'PLATFORM');
    }

    /**
     * Does this scope reach this subject?
     *
     * @throws ScopeResolutionFailed for a declared type with no resolver, or a
     *         reporting cycle. Both are denials at the decider (R-XXII-094);
     *         neither is ever swallowed into an allow (R-XXII-095).
     */
    public function covers(SubjectContext $subject): bool
    {
        return match ($this->type) {
            // R-XXII-042: resolves from the session's employee binding, and is
            // the EMPTY set when there is no binding — not the tenant.
            'self' => $subject->actorEmployeeId !== null
                && $subject->employeeId === $subject->actorEmployeeId,

            'direct_reports' => $this->reaches($subject, 1),
            'reporting_tree' => $this->reaches($subject, $this->depth ?? self::MAX_REPORTING_DEPTH),

            'team', 'department', 'branch', 'location', 'company', 'legal_entity', 'unit_list'
                => $this->inUnits($subject),

            'tenant' => $subject->sameTenant(),
            'platform' => $subject->actorInPlatformTenant,

            default => throw new ScopeResolutionFailed(
                "Scope type '{$this->type}' is declared in access_scope_types but has no "
                . 'resolver in this build. R-XXII-094: a scope that cannot be resolved denies.'
            ),
        };
    }

    /** Does evaluating this scope need a placeable subject at all? */
    public function requiresSubject(): bool
    {
        return !in_array($this->type, ['tenant', 'platform'], true);
    }

    /**
     * The scope as a predicate for the persistence layer — the `SetExpression`
     * of R-XXII-041, which §XXII.4.4 injects into the query and R-XXII-052
     * disjoins across assignments.
     *
     * Deliberately a portable descriptor and never a materialised id list: a
     * tenant-scoped payroll officer reaches 40,000 employees and an `IN (…)` of
     * that size is not a plan the optimiser can use.
     *
     * ponytail: a descriptor, not SQL. The query-scoping layer compiles it once
     * the Organisation module's tables exist — writing SQL here would hard-code
     * table names this repository has not defined yet, and would put SQL in a
     * layer the gates keep framework-free.
     *
     * @return array<string, mixed>
     */
    public function predicate(SubjectContext $subject): array
    {
        $base = ['scope' => $this->type, 'scope_key' => $this->key, 'scope_version' => $this->version];

        return $base + match ($this->type) {
            'self' => ['employee_id' => $subject->actorEmployeeId],
            'direct_reports' => [
                'manager_id' => $subject->actorEmployeeId,
                'depth' => 1,
                'include_self' => $this->includeSelf,
            ],
            'reporting_tree' => [
                'manager_id' => $subject->actorEmployeeId,
                'depth' => $this->depth ?? self::MAX_REPORTING_DEPTH,
                'include_self' => $this->includeSelf,
            ],
            'team', 'department', 'branch', 'location', 'company', 'legal_entity', 'unit_list'
                => ['org_units' => $this->units, 'include_descendants' => $this->includeDescendants],
            'tenant' => ['tenant_id' => $subject->actorTenantId],
            'platform' => ['cross_tenant' => true],
            default => throw new ScopeResolutionFailed(
                "Scope type '{$this->type}' has no set expression in this build; the query "
                . 'would be unscoped, so it is refused.'
            ),
        };
    }

    public function __toString(): string
    {
        return $this->key . ' v' . $this->version . ' (' . $this->type . ')';
    }

    private function reaches(SubjectContext $subject, int $depth): bool
    {
        if ($subject->actorEmployeeId === null || $subject->employeeId === null) {
            return false;
        }

        if ($subject->employeeId === $subject->actorEmployeeId) {
            return $this->includeSelf;
        }

        $chain = array_slice($subject->managerChain, 0, $depth);

        // R-XXII-044: a reporting cycle terminates the walk and denies. A cycle
        // is a data defect, never a licence to widen scope, so it raises rather
        // than quietly truncating.
        if (count(array_unique($chain)) !== count($chain)) {
            throw new ScopeResolutionFailed(
                'access.reporting_cycle_detected: the reporting chain of employee '
                . $subject->employeeId . ' repeats within ' . $depth . ' levels.'
            );
        }

        return in_array($subject->actorEmployeeId, $chain, true);
    }

    private function inUnits(SubjectContext $subject): bool
    {
        if ($this->includeDescendants) {
            // The subject's path is root → leaf, so a scope unit anywhere on it
            // means the subject sits at or below that unit.
            return array_intersect($this->units, $subject->orgUnitPath) !== [];
        }

        // Exact membership: `unit_list` mixes types, so it checks every declared
        // unit of the subject rather than one type's.
        $exact = $this->type === 'unit_list'
            ? array_values($subject->unitsByType)
            : [$subject->unitsByType[$this->type] ?? null];

        return array_intersect($this->units, array_filter($exact, is_string(...))) !== [];
    }
}
