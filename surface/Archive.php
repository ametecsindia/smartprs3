<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * DELETING AN EMPLOYEE IS A TIMER, A BUNDLE, AND A MOVE — NEVER A DELETE.
 *
 * Delete on the Directory schedules the employee (backup_due_at = now + the
 * grace in config/archive.json, backup_by = who). Until then it is still a
 * normal employee and the schedule can be cancelled. When the timer lapses
 * the sweep — run on the next request, and by tools/db/schedule.php archive —
 * writes everything the employee has into one JSON bundle under
 * storage/archive/<tenant>/, copies its files beside it, re-points every row
 * that names it by code at the archived code (CODE_YYYY-MM-DD), marks the
 * employee archived, and so releases the original code for somebody new.
 *
 * Law 3: nothing is overwritten. The bundle is the record at the moment of
 * archiving; the rows themselves stay, under the archived code, on the
 * Backed up / Old data screen.
 */
final class Archive
{
    public function __construct(
        private readonly RowStore $store,
        private readonly string $configFile,
        private readonly string $archiveDir,
        private readonly string $uploadsDir,
    ) {
    }

    public function config(): array
    {
        $c = json_decode((string) file_get_contents($this->configFile), true);

        if (!is_array($c)) {
            throw new \RuntimeException("config/archive.json is missing or unreadable at {$this->configFile}.");
        }

        return $c;
    }

    public function graceHours(): int
    {
        return (int) ($this->config()['grace_hours'] ?? 72);
    }

    /** Start the timer. @return array{ok: bool, why: string, due?: string} */
    public function schedule(array $employment, string $by, string $now): array
    {
        if (trim((string) ($employment['archived_at'] ?? '')) !== '') {
            return ['ok' => false, 'why' => Employment::label($employment) . ' is already archived.'];
        }

        if (trim((string) ($employment['backup_due_at'] ?? '')) !== '') {
            return ['ok' => false, 'why' => Employment::label($employment) . ' is already scheduled — due ' . $employment['backup_due_at'] . '.'];
        }

        $due = date('Y-m-d H:i:s', strtotime($now . ' +' . $this->graceHours() . ' hours'));
        $this->store->put('employees', ['id' => (string) $employment['id'], 'backup_due_at' => $due, 'backup_by' => $by]);

        return ['ok' => true, 'due' => $due, 'why' => Employment::label($employment) . ' will be backed up and moved to Old data at ' . $due
            . ' (' . $this->graceHours() . ' hours from now). Until then nothing changes, and Cancel undoes it.'];
    }

    public function cancel(array $employment): array
    {
        if (trim((string) ($employment['backup_due_at'] ?? '')) === '') {
            return ['ok' => false, 'why' => Employment::label($employment) . ' is not scheduled for deletion.'];
        }

        $this->store->put('employees', ['id' => (string) $employment['id'], 'backup_due_at' => null, 'backup_by' => null]);

        return ['ok' => true, 'why' => 'Deletion of ' . Employment::label($employment) . ' cancelled; the employee stays as it is.'];
    }

    /** Every employee whose timer has lapsed, archived. @return list<array{code: string, archived_code: string, bundle: string}> */
    public function sweep(string $now, string $by): array
    {
        $done = [];

        foreach ($this->store->all('employees') as $e) {
            $due = trim((string) ($e['backup_due_at'] ?? ''));

            if ($due === '' || $due > $now || trim((string) ($e['archived_at'] ?? '')) !== '') {
                continue;
            }

            $done[] = $this->archive($e, $now, $by);
        }

        return $done;
    }

    /** Bundle, relink, mark, release the code. @return array{code: string, archived_code: string, bundle: string, rows: int, files: int} */
    public function archive(array $e, string $now, string $by): array
    {
        $config = $this->config();
        $code = Employment::code($e);
        $id = (string) $e['id'];
        $archivedCode = $code . date((string) ($config['code_suffix_format'] ?? '_Y-m-d'), strtotime($now));
        $bundle = ['archived_at' => $now, 'archived_by' => $by, 'code' => $code, 'archived_code' => $archivedCode, 'employee' => $e, 'tables' => [], 'payroll' => [], 'files' => []];
        $rowCount = 0;

        // ROWS THAT NAME THE EMPLOYMENT BY CODE: bundled, then re-pointed at the
        // archived code so the history stays attached and the code is free.
        foreach ((array) ($config['relink_by_code'] ?? []) as $table => $column) {
            foreach ($this->rowsWhere($table, $column, $code) as $row) {
                $bundle['tables'][$table][] = $row;
                $rowCount++;
                $this->store->put($table, ['id' => (string) $row['id'], $column => $archivedCode]);
            }
        }

        // ROWS THAT NAME IT BY ID: bundled; they keep pointing at the same row.
        foreach ((array) ($config['bundle_by_id'] ?? []) as $table => $column) {
            foreach ($this->rowsWhere($table, $column, $id) as $row) {
                $bundle['tables'][$table][] = $row;
                $rowCount++;
            }
        }

        // PAYROLL: the lines of every closed run that paid this code. The runs
        // themselves are closed months and stay whole.
        try {
            foreach ($this->store->all((string) ($config['payroll_runs_table'] ?? 'payroll_runs')) as $run) {
                $rows = json_decode((string) ($run['run_rows'] ?? '[]'), true);
                $rows = is_array($rows) ? $rows : (array) ($run['rows'] ?? []);

                if (isset($rows[$code])) {
                    $bundle['payroll'][(string) ($run['period'] ?? $run['id'])] = $rows[$code];
                    $rowCount++;
                }
            }
        } catch (\Throwable $ex) {
            $bundle['payroll_note'] = 'payroll runs not read: ' . $ex->getMessage();
        }

        // FILES: the photograph and every document path the record or its extra
        // fields hold, copied beside the bundle.
        $dir = rtrim($this->archiveDir, '/') . '/' . preg_replace('/[^A-Za-z0-9_.-]+/', '_', $archivedCode);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("The archive folder {$dir} could not be created.");
        }

        $paths = [];

        foreach ($e as $v) {
            is_string($v) && str_starts_with($v, 'uploads/') && $paths[] = $v;
        }

        foreach ($bundle['tables']['employee_field_values'] ?? [] as $row) {
            $v = (string) ($row['value'] ?? '');
            str_starts_with($v, 'uploads/') && $paths[] = $v;
        }

        foreach ($bundle['tables']['documents'] ?? [] as $row) {
            foreach (['path', 'file_path'] as $k) {
                $v = (string) ($row[$k] ?? '');
                $v !== '' && $paths[] = $v;
            }
        }

        $fileCount = 0;

        foreach (array_unique($paths) as $rel) {
            $src = rtrim($this->uploadsDir, '/') . '/' . ltrim(preg_replace('#^uploads/#', '', $rel), '/');

            if (is_file($src) && copy($src, $dir . '/' . basename($src))) {
                $bundle['files'][] = ['from' => $rel, 'to' => basename($src)];
                $fileCount++;
            }
        }

        $file = $dir . '/bundle.json';
        file_put_contents($file, json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // THE MOVE: the employee keeps its row, under the archived code, out of
        // every list (archived_at + status), and the original code is free.
        $this->store->put('employees', [
            'id' => $id,
            Employment::CODE_COLUMN => $archivedCode,
            'status' => (string) ($config['archived_status'] ?? 'archived'),
            'archived_at' => $now,
            'archived_by' => $by,
            'backup_due_at' => null,
        ]);

        return ['code' => $code, 'archived_code' => $archivedCode, 'bundle' => $file, 'rows' => $rowCount, 'files' => $fileCount];
    }

    /** @return list<array<string, mixed>> */
    private function rowsWhere(string $table, string $column, string $value): array
    {
        try {
            return array_values(array_filter($this->store->all($table), static fn (array $r): bool => (string) ($r[$column] ?? '') === $value));
        } catch (\Throwable) {
            return []; // a table this installation does not have holds nothing of this employee
        }
    }
}
