<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Organisation;

use SmartPRS\Platform\Persistence\Repository;

/**
 * Reads of the org tree. Tenant-scoped like everything else, and deliberately
 * not effective-dated: `org_units` has no dated rows — a unit exists or it does
 * not, and what changes over time is who is ASSIGNED to it, which is
 * `employment_assignments`' problem (AssignmentHistory).
 */
final class OrgUnitRepository extends Repository
{
    protected function table(): string
    {
        return 'org_units';
    }

    public function find(string $id): ?OrgUnit
    {
        $row = $this->query()->where('id', '=', $id)->first();

        return $row === null ? null : OrgUnit::fromRow($row);
    }

    /**
     * Root first, this unit excluded. One query, from the path.
     *
     * @return list<OrgUnit>
     */
    public function ancestorsOf(string $id): array
    {
        $unit = $this->find($id);

        if ($unit === null || $unit->ancestorIds() === []) {
            return [];
        }

        return $this->hydrate($this->query()->whereIn('id', $unit->ancestorIds())->orderBy('depth')->select());
    }

    /**
     * The subtree below this unit, the unit itself excluded. A prefix LIKE on
     * the indexed path — no recursion, and no CTE the on-prem MySQL might not
     * have.
     *
     * @return list<OrgUnit>
     */
    public function descendantsOf(string $id): array
    {
        $unit = $this->find($id);

        if ($unit === null) {
            return [];
        }

        return $this->hydrate(
            $this->query()
                ->where('path', 'LIKE', $unit->subtreePrefix() . '%')
                ->where('id', '<>', $unit->id)
                ->orderBy('depth')
                ->orderBy('id')
                ->select()
        );
    }

    /**
     * Path by unit id, for the units a data scope names. The scope compiler
     * needs prefixes rather than ids so that "this unit and everything under it"
     * is one indexed comparison against the subject's unit.
     *
     * @param  list<string>  $ids
     * @return array<string, string>  id => path
     */
    public function pathsOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $paths = [];

        foreach ($this->query()->whereIn('id', $ids)->select(['id', 'path']) as $row) {
            $paths[(string) $row['id']] = (string) $row['path'];
        }

        return $paths;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<OrgUnit>
     */
    private function hydrate(array $rows): array
    {
        return array_map(OrgUnit::fromRow(...), $rows);
    }
}
