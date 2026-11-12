<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * DOES AN EMPLOYEE'S ORGANISATION HANG TOGETHER? company → branch → department
 * → designation, and company → team (owner, 28 Sep). The screens cascade these
 * in the browser; this is the server's own check, shared by the Directory form
 * and the bulk import so the two cannot disagree. A unit with no parent on
 * record fits anywhere; only a recorded parent that disagrees is refused.
 */
final class OrgConsistency
{
    /**
     * @param array<string, mixed> $values the employee's columns (after the save)
     * @return list<string>
     */
    public static function problems(RowStore $store, array $values): array
    {
        $get = static function (string $table, string $id) use ($store): ?array {
            try {
                return $id === '' ? null : $store->find($table, $id);
            } catch (\Throwable) {
                return null; // gate:allow-swallow a register this store does not have constrains nothing
            }
        };

        $company = trim((string) ($values['employer_company_id'] ?? ''));
        $branch = $get('branches', trim((string) ($values['branch_id'] ?? '')));
        $department = $get('departments', trim((string) ($values['department_id'] ?? '')));
        $designation = $get('designations', trim((string) ($values['designation_id'] ?? '')));
        $team = $get('teams', trim((string) ($values['team_id'] ?? '')));
        $differs = static fn (?array $row, string $column, string $want): bool
            => $row !== null && $want !== '' && trim((string) ($row[$column] ?? '')) !== '' && (string) $row[$column] !== $want;
        $name = static fn (?array $row): string => (string) ($row['name'] ?? '');
        $problems = [];

        $differs($branch, 'employer_company_id', $company) && $problems[] = 'Branch "' . $name($branch) . '" belongs to another company.';
        $differs($department, 'branch_id', (string) ($branch['id'] ?? '')) && $problems[] = 'Department "' . $name($department) . '" belongs to another branch.';
        $differs($department, 'employer_company_id', $company) && $problems[] = 'Department "' . $name($department) . '" belongs to another company.';
        $differs($designation, 'department_id', (string) ($department['id'] ?? '')) && $problems[] = 'Designation "' . $name($designation) . '" belongs to another department.';
        $differs($designation, 'employer_company_id', $company) && $problems[] = 'Designation "' . $name($designation) . '" belongs to another company.';
        $differs($team, 'employer_company_id', $company) && $problems[] = 'Team "' . $name($team) . '" belongs to another company.';

        return $problems;
    }
}
