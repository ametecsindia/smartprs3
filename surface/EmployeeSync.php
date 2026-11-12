<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * WHAT IS SAVED IN THE DIRECTORY IS WHAT EVERY OTHER SCREEN SHOWS (owner, 4 Nov:
 * "any data saved in the Directory — email ID, Emp ID, etc. — should automatically
 * update in User management and other places across the application").
 *
 * Most of the application reads the employee row itself, so it follows by itself.
 * The exceptions are the places that keep their own COPY of a Directory value:
 *
 *  - the login (`users.email`, `users.name`) — the address the person signs in with;
 *  - every table keyed by the employee CODE rather than the row id: punches,
 *    regularizations, late notices, attendance ratings and bulk rows, the leave
 *    ledger (including the code prefix on its idempotency reference, or accrual
 *    would post a second copy under the new code), settlements, employment events,
 *    extra field values, KRA/KPI achievements, LOP letters, mobile devices;
 *  - the employee NAME copied onto punches, regularizations and KRA/KPI rows.
 *
 * Generated payroll is NOT touched (owner, 4 Nov: "if payroll is already generated
 * and sealed, we should ignore that"): a run is sealed by its fingerprint under the
 * code it was paid on (Law 3). Months generated after the change use the new code.
 *
 * Administrator logins are left as they are (the Directory changing the address an
 * administrator signs in with would lock the owner out).
 */
final class EmployeeSync
{
    /** table => columns that hold the employee code */
    public const CODE_COLUMNS = [
        'attendance_logs' => ['emp_code'],
        'late_notifications' => ['emp_code'],
        'attendance_corrections' => ['emp_code'],
        'attendance_ratings' => ['emp_code'],
        'attendance_bulk_rows' => ['emp_code'],
        'leave_entries' => ['employment_id'],
        'settlements' => ['employment_id'],
        'employment_events' => ['employment_id'],
        'employee_field_values' => ['employment_code'],
        'kra_kpi_achievements' => ['employee_code', 'employment_id'],
        'letter_lop' => ['employee_code'],
        'mobile_devices' => ['emp_code'],
    ];

    /** table => columns that hold a copy of the employee name, next to a code column */
    public const NAME_COLUMNS = [
        'attendance_logs' => ['emp_name', 'emp_code'],
        'attendance_corrections' => ['emp_name', 'emp_code'],
        'kra_kpi_achievements' => ['employee_name', 'employee_code'],
    ];

    /** @param array<string, RowStore> $stores table => the store that holds it (default: $store) */
    public function __construct(
        private readonly RowStore $store,
        private readonly Users $users,
        private readonly array $stores = [],
    ) {
    }

    /**
     * Carry one saved employee's Directory values to every copy.
     *
     * @param array<string, mixed> $before the row as it was on file
     * @param array<string, mixed> $after  the row as it is now
     * @param ?array<string, array<string, mixed>> $logins employee id => login, when the caller already has them
     * @return array{moved: array<string, int>, login: string, problems: list<string>}
     */
    public function carry(array $before, array $after, ?array $logins = null): array
    {
        $out = ['moved' => [], 'login' => '', 'problems' => []];
        $oldCode = trim(Employment::code($before));
        $newCode = trim(Employment::code($after));
        $oldName = trim((string) ($before['name'] ?? ''));
        $newName = trim((string) ($after['name'] ?? ''));

        if ($oldCode !== '' && $newCode !== '' && $oldCode !== $newCode) {
            foreach (self::CODE_COLUMNS as $table => $columns) {
                $n = $this->rewrite($table, static function (array $row) use ($columns, $oldCode, $newCode, $table): array {
                    $patch = [];
                    foreach ($columns as $c) {
                        (string) ($row[$c] ?? '') === $oldCode && $patch[$c] = $newCode;
                    }
                    // The ledger's idempotency key is "CODE:what" — Leave::reference().
                    if ($table === 'leave_entries' && $patch !== [] && str_starts_with((string) ($row['source_reference'] ?? ''), $oldCode . ':')) {
                        $patch['source_reference'] = $newCode . substr((string) $row['source_reference'], strlen($oldCode));
                    }
                    return $patch;
                });
                $n > 0 && $out['moved'][$table] = $n;
            }
        }

        if ($newName !== '' && $oldName !== $newName && $newCode !== '') {
            foreach (self::NAME_COLUMNS as $table => [$nameCol, $codeCol]) {
                $n = $this->rewrite($table, static fn (array $row): array
                    => (string) ($row[$codeCol] ?? '') === $newCode && (string) ($row[$nameCol] ?? '') !== $newName ? [$nameCol => $newName] : []);
                $n > 0 && $out['moved'][$table . ' (name)'] = $n;
            }
        }

        $login = $logins === null ? $this->loginOf((string) ($after['id'] ?? '')) : ($logins[(string) ($after['id'] ?? '')] ?? null);

        if ($login !== null && (string) ($login['role'] ?? '') !== 'administrator') {
            $email = trim((string) ($after['email'] ?? ''));
            $wantEmail = $email === '' ? (string) $login['email'] : $email;
            $wantName = $newName !== '' ? $newName : (string) $login['name'];

            if (strcasecmp($wantEmail, (string) $login['email']) !== 0 || $wantName !== (string) $login['name']) {
                $r = $this->users->update((string) $login['id'], $wantName, $wantEmail);
                $r['ok'] ? $out['login'] = $wantEmail : $out['problems'][] = 'Login not updated: ' . $r['why'];
            }
        }

        return $out;
    }

    /**
     * Every login follows its employee's Directory email and name — for writers that
     * change many rows at once (Import employees) and on opening User Management.
     *
     * @return list<string> problems, one per login that could not follow
     */
    public function syncLogins(): array
    {
        $problems = [];
        $logins = [];

        foreach ($this->users->all()['users'] ?? [] as $u) {
            (string) ($u['employment_id'] ?? '') !== '' && $logins[(string) $u['employment_id']] = $u;
        }

        foreach ($this->store->all('employees') as $e) {
            if (trim((string) ($e['deleted_at'] ?? '')) === '') {
                $problems = array_merge($problems, $this->carry($e, $e, $logins)['problems']);
            }
        }

        return $problems;
    }

    /** @return ?array<string, mixed> the login bound to this employee row */
    public function loginOf(string $employeeId): ?array
    {
        if ($employeeId === '') {
            return null;
        }

        foreach ($this->users->all()['users'] ?? [] as $u) {
            if ((string) ($u['employment_id'] ?? '') === $employeeId) {
                return $u;
            }
        }

        return null;
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $patchOf */
    private function rewrite(string $table, callable $patchOf): int
    {
        $store = $this->stores[$table] ?? $this->store;

        try {
            $rows = $store->all($table);
        } catch (\Throwable) {
            return 0; // tolerated: an installation without this table holds no copy to carry
        }

        $n = 0;

        foreach ($rows as $row) {
            $patch = $patchOf($row);

            if ($patch !== [] && isset($row['id'])) {
                $store->put($table, ['id' => $row['id']] + $patch);
                $n++;
            }
        }

        return $n;
    }
}
