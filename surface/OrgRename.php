<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * A UNIT RENAMED IN ORGANIZATION SETUP IS RENAMED EVERYWHERE IT IS NAMED
 * (owner, 28 Sep: "each and everything should be wired properly").
 *
 * Salary structure rules, payslip formats, KRA/KPI targets, leave types, leave
 * grants, the holiday schedule, pay cycles and appraisal boxes are scoped by the
 * unit's NAME. Renaming "Collections" to "Recovery" left every one of them
 * pointing at a name nothing carries any more, so each silently stopped applying
 * to anybody. This carries the new name through:
 *
 *  - rows of the scoped tables, in their scope columns only (and inside the
 *    comma-joined designation list and a salary rule's department in `detail`);
 *  - every live customisation record, but only values held under a key that
 *    names the kind of unit that was renamed (`branch`, `scope_branch`,
 *    `branches`, …) — a leave type called the same as a branch is untouched.
 *
 * It is a reference being renamed, not a rule being changed: nothing about who
 * a rule covers moves. Each changed customisation record is saved as a new
 * version with the rename as its note, so it can be seen and undone.
 */
final class OrgRename
{
    /** Scope-key spellings per kind of unit. */
    private const KEYS = [
        'company' => ['company', 'scope_company', 'companies', 'company_name', 'from_company', 'to_company'],
        'branch' => ['branch', 'scope_branch', 'branches', 'location', 'from_branch', 'to_branch'],
        'department' => ['department', 'scope_department', 'departments'],
        'designation' => ['designation', 'scope_designation', 'designations'],
        'team' => ['team', 'scope_team', 'teams'],
        'role' => ['role', 'scope_role', 'roles'],
    ];

    /** Tables whose rows carry scope columns by name. */
    private const TABLES = ['salary_component_rules', 'payslip_formats', 'kra_kpi_targets'];

    public function __construct(
        private readonly RowStore $store,
        private readonly Customisation $customisation,
    ) {
    }

    /** The kind of unit a register holds, or null for a table that is not an org register. */
    public static function kindOf(string $table): ?string
    {
        return ['companies' => 'company', 'branches' => 'branch', 'departments' => 'department',
            'designations' => 'designation', 'teams' => 'team'][$table] ?? null;
    }

    /**
     * @return int how many rows and records were changed
     */
    public function rename(string $kind, string $from, string $to, string $byId, string $byLabel): int
    {
        $from = trim($from);
        $to = trim($to);

        if ($from === '' || $to === '' || $from === $to || !isset(self::KEYS[$kind])) {
            return 0;
        }

        $keys = self::KEYS[$kind];
        $changed = 0;

        foreach (self::TABLES as $table) {
            try {
                $rows = $this->store->all($table);
            } catch (\Throwable) {
                continue; // a table this installation does not have holds no names
            }

            foreach ($rows as $row) {
                $patch = [];

                foreach ($keys as $key) {
                    if (array_key_exists($key, $row) && is_string($row[$key]) && $row[$key] !== '') {
                        $new = self::renamedList($row[$key], $from, $to);
                        $new !== $row[$key] && $patch[$key] = $new;
                    }
                }

                if (isset($row['detail']) && is_string($row['detail']) && $row['detail'] !== '') {
                    $detail = json_decode($row['detail'], true);

                    if (is_array($detail)) {
                        $newDetail = $this->walk($detail, $keys, $from, $to, false);

                        if ($newDetail !== $detail) {
                            $patch['detail'] = json_encode($newDetail, JSON_THROW_ON_ERROR);
                        }
                    }
                }

                if ($patch !== []) {
                    $this->store->put($table, ['id' => $row['id']] + $patch);
                    $changed++;
                }
            }
        }

        foreach ($this->customisation->live() as $recordKey => $record) {
            if (!is_array($record)) {
                continue;
            }

            $new = $this->walk($record, $keys, $from, $to, false);

            if ($new !== $record) {
                $this->customisation->saveRaw((string) $recordKey, $new, $byId, $byLabel,
                    ucfirst($kind) . " renamed: {$from} → {$to}");
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Replace $from with $to in every string held under one of $keys (at any
     * depth), including inside lists under those keys and comma-joined lists.
     *
     * @param array<mixed> $data
     * @param list<string> $keys
     * @return array<mixed>
     */
    private function walk(array $data, array $keys, string $from, string $to, bool $under): array
    {
        foreach ($data as $k => $v) {
            $isKey = is_string($k) && in_array(strtolower($k), $keys, true);

            if (is_array($v)) {
                $data[$k] = $this->walk($v, $keys, $from, $to, $under || $isKey);
            } elseif (is_string($v) && ($isKey || ($under && is_int($k)))) {
                $data[$k] = self::renamedList($v, $from, $to);
            }
        }

        return $data;
    }

    /** The value with $from replaced by $to, as a whole value or as one item of a comma list. */
    private static function renamedList(string $value, string $from, string $to): string
    {
        if (strcasecmp(trim($value), $from) === 0) {
            return $to;
        }

        if (!str_contains($value, ',')) {
            return $value;
        }

        $parts = explode(',', $value);

        if (array_filter($parts, static fn (string $p): bool => strcasecmp(trim($p), $from) === 0) === []) {
            return $value;
        }

        return implode(',', array_map(static fn (string $p): string
            => strcasecmp(trim($p), $from) === 0 ? $to : trim($p), explode(',', $value)));
    }
}
