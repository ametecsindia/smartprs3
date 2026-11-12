<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * THE UPLOAD SAMPLE, FILLED IN AND BROUGHT BACK.
 *
 * The Employee fields register already generates the sample: a label row, a
 * CODE row, a type row, an allowed-values row and an example row, one column per
 * field this tenant captures. This class reads that same file back — the code
 * row is the contract, matched by NAME so a reordered sheet still imports — and
 * turns each data row into an employee, through the same field list the Add
 * form uses. There is no second list of columns anywhere.
 *
 * SmartPRS 2's `EmployeeImportService` (36 KB) does this with a mapping UI, a
 * duplicate mode and a history table (`employee_import_jobs`, ported and used
 * here). Three things it does that this refuses:
 *
 * 1. IT NEVER GUESSES A DATE. `01/09/2026` is refused with the line named; the
 *    sample says `Y-m-d` and the register said so on the type row.
 * 2. IT NEVER IMPORTS A ROW WITH AN ERROR IN IT "as far as it got". A row is
 *    whole or it is refused; a half-imported employee is a payslip with a blank
 *    where the bank account should be.
 * 3. IT NEVER WRITES ON PREVIEW. Preview keeps the file under storage/imports/
 *    with a token and reports every row's verdict; commit reads the SAME file
 *    back by token. What was previewed is what is imported.
 *
 * Duplicate mode is the uploader's explicit choice: `skip` leaves an existing
 * code alone, `update` writes the non-empty cells over it. There is no
 * "create a second row" — a code is an employee, and two rows with one code is
 * the defect that stops payroll.
 */
final class EmployeeImport
{
    public const JOBS = 'employee_import_jobs';
    public const MAX_ROWS = 2000;

    /** OTHER BRANCHES HANDLED (owner, 26 Oct): branches besides the Home one, kept beside the record. */
    public const BRANCHES = 'admin_branches';

    public const BRANCHES_LABEL = 'Other Branches Handled';

    public function __construct(
        private readonly RowStore $store,
        private readonly EmployeeFields $fields,
        private readonly EmployeeExtras $extras,
        private readonly string $spool,
        private readonly array $lookups = [],
    ) {
    }

    /**
     * Read the file: find the code row, skip the sample's meta rows, type-check
     * every data row against the register.
     *
     * @return array{rows: list<array<string, mixed>>, columns: list<string>, unknown: list<string>,
     *               ok: int, errors: int, header_line: int}
     */
    public function parse(string $text, string $dupMode = 'skip'): array
    {
        $fields = $this->fields->inUse();
        $byKey = [];
        // COLUMN MATCHING GUESSED FOR YOU (PRS2): a heading matches by column, code
        // or label with everything but letters and digits stripped, and the
        // aliases in config/employee-import.json map other systems' headings on.
        $norm = static fn (string $h): string => preg_replace('/[^a-z0-9]/', '', strtolower($h)) ?? '';

        foreach ($fields as $f) {
            foreach ([(string) ($f['storage'] === 'column' ? $f['column'] : $f['code']), (string) $f['code'], (string) $f['label']] as $k) {
                $byKey[strtolower($k)] = $f;
                $byKey[$norm($k)] = $f;
            }
        }

        // Not a register field (the Directory draws it on the Branch box), so it is matched here by code or label.
        foreach ([self::BRANCHES, self::BRANCHES_LABEL] as $k) {
            $byKey[strtolower($k)] = $byKey[$norm($k)] = ['code' => self::BRANCHES, 'label' => self::BRANCHES_LABEL, 'storage' => 'extra', 'type' => 'Text'];
        }

        foreach ((array) ($this->declared()['aliases'] ?? []) as $alias => $code) {
            $target = $byKey[$norm((string) $code)] ?? null;
            $target !== null && !isset($byKey[$norm((string) $alias)]) && $byKey[$norm((string) $alias)] = $target;
        }

        $byKey = array_filter($byKey, static fn ($v, $k): bool => $k !== '', ARRAY_FILTER_USE_BOTH);

        $lines = preg_split('/\R/', preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text) ?: [];
        $headerLine = 0;
        $columns = [];
        $unknown = [];

        // THE CODE ROW is the first row where most cells name a field. The label
        // row above it usually does too, so the LAST of the first three
        // qualifying rows wins — the sample puts labels, then codes.
        foreach (array_slice($lines, 0, 4, true) as $i => $raw) {
            $cells = array_map(static fn (?string $c): string => strtolower(trim((string) $c)), str_getcsv($raw, ',', '"', '\\'));
            $hits = count(array_filter($cells, static fn (string $c): bool => isset($byKey[$c]) || isset($byKey[$norm($c)])));

            if ($cells !== [] && $hits >= max(1, (int) ceil(count(array_filter($cells)) / 2))) {
                $headerLine = $i + 1;
                $columns = $cells;
            }
        }

        if ($columns === []) {
            throw new \RuntimeException('No row of the file names the fields. Download the upload sample from '
                . 'Employee fields and fill THAT in — its second row carries the column codes the import matches on.');
        }

        // Headings resolve to the field they matched, so the rest of the parse reads one key per column.
        foreach ($columns as $i => $c) {
            if ($c === '') {
                continue;
            }

            $f = $byKey[$c] ?? $byKey[$norm($c)] ?? null;

            if ($f === null) {
                $unknown[] = $c;
            } else {
                $columns[$i] = strtolower((string) $f['code']);
            }
        }

        $types = EmployeeFields::TYPES;
        $rows = [];
        $ok = 0;
        $errors = 0;
        $existing = [];

        foreach ($this->store->all('employees') as $e) {
            $code = Employment::code($e);

            if ($code !== '') {
                $existing[strtolower($code)] = $e;
            }
        }

        $seen = [];
        $skipMeta = 0;
        $sampleLines = []; // the sample's type + example lines skipped below, named when nothing else is read

        foreach ($lines as $i => $raw) {
            $line = $i + 1;

            if ($line <= $headerLine || trim($raw) === '') {
                continue;
            }

            $cells = array_map(static fn (?string $c): string => trim((string) $c), str_getcsv($raw, ',', '"', '\\'));

            // THE SAMPLE'S META ROWS: types, allowed values, example. The type row
            // is recognisable (every non-empty cell is a declared type); the two
            // after it belong to it.
            if ($skipMeta === 0 && $line === $headerLine + 1
                && array_filter($cells) !== [] && array_diff(array_filter($cells), $types) === []) {
                $skipMeta = 3;
            }

            if ($skipMeta > 0) {
                $skipMeta--;
                $codeIdx = array_key_first(array_filter($columns, static fn (string $k): bool => (string) ($byKey[$k]['column'] ?? '') === Employment::CODE_COLUMN));
                $sampleLines[$line] = $codeIdx === null ? '' : (string) ($cells[$codeIdx] ?? '');

                continue;
            }

            $row = ['line' => $line, 'values' => [], 'extras' => [], 'problems' => [], 'code' => '', 'action' => 'create'];

            foreach ($columns as $idx => $key) {
                if ($key === '' || !isset($byKey[$key])) {
                    continue;
                }

                $f = $byKey[$key];
                $value = trim((string) ($cells[$idx] ?? ''));

                if ($value === '') {
                    continue;
                }

                // "NO ONE", CHOSEN ON PURPOSE (24 Sep): the field's declared
                // none_option is an answer, stored as no value at all, and it
                // satisfies `required` below where an empty cell does not.
                if (self::isNone($f, $value)) {
                    $row['none'][(string) $f['code']] = true;

                    if (($f['storage'] ?? 'column') === 'column') {
                        $row['values'][(string) $f['column']] = null;
                    }

                    continue;
                }

                if ((string) $f['code'] === self::BRANCHES) {
                    $row['branches_cell'] = $value;   // resolved below, once the row's company and Home branch are known

                    continue;
                }

                $value = self::normalise($f, $value);
                $why = $this->problemWith($f, $value);

                if ($why !== null) {
                    // THE CELL, NAMED: the field, the sheet column and the line —
                    // so the person opens the file and goes straight to it.
                    $row['problems'][] = $f['label'] . ' (column ' . self::columnLetter($idx) . "): {$why}";

                    continue;
                }

                // A LOOKUP CELL CAN NAME THE OPTION BY LABEL ("KONDAPUR Collections")
                // OR BY ITS STORED KEY — problemWith() above accepts either, but the
                // column only holds the key ("14"), so a label match is translated
                // back to it here. Without this, a valid label sails through
                // validation and then hits MySQL as text in an int column.
                if (($f['control'] ?? '') === 'lookup') {
                    foreach (($this->lookups[(string) ($f['lookup'] ?? '')] ?? []) as $id => $label) {
                        if (strcasecmp((string) $label, $value) === 0) {
                            $value = (string) $id;

                            break;
                        }
                    }
                }

                // YES AND NO ARE WORDS ON A FILE AND A NUMBER IN THE COLUMN — same
                // translation employeeFieldValues() does for the Add/Edit Employee
                // form, and on the same trigger: a field typed 'Boolean (Yes/No/NA)'
                // OR a plain List field whose value just happens to BE yes/no (e.g.
                // "Documents On File": type List, options Yes/No, column an int).
                // Checking the type alone missed that second case and let a literal
                // "No" reach MySQL for an int column — refused, not truncated.
                if (($f['type'] ?? '') === 'Boolean (Yes/No/NA)' || in_array(strtolower($value), ['yes', 'no'], true)) {
                    if (in_array((string) ($f['column_type'] ?? ''), ['boolean', 'tinyint', 'int'], true)) {
                        $value = match (strtolower($value)) { 'yes' => '1', 'no' => '0', default => $value };
                    }
                }

                if (($f['storage'] ?? 'column') === 'column') {
                    $row['values'][(string) $f['column']] = $value;
                } else {
                    $row['extras'][(string) $f['code']] = $value;
                }
            }

            $row['code'] = (string) ($row['values'][Employment::CODE_COLUMN] ?? '');
            $lower = strtolower($row['code']);

            if ($row['code'] === '') {
                $row['problems'][] = 'No employee code. A row without one is an employee nothing can point at.';
            } elseif (isset($seen[$lower])) {
                $row['problems'][] = "Code {$row['code']} appears twice in this file (first on line {$seen[$lower]}).";
            } elseif (isset($existing[$lower])) {
                if ($dupMode === 'update') {
                    $row['action'] = 'update';
                    $row['existing_id'] = (string) $existing[$lower]['id'];
                } else {
                    $row['action'] = 'skip';
                }
            } elseif (trim((string) ($row['values']['name'] ?? '')) === '') {
                $row['problems'][] = 'A new employee needs a name.';
            }

            // OTHER BRANCHES HANDLED: names (or "All Branches") of the row's company, Home branch left out.
            if (isset($row['branches_cell'])) {
                $was = (array) ($existing[$lower] ?? []);
                $company = (string) ($row['values']['employer_company_id'] ?? $was['employer_company_id'] ?? '');
                $home = (string) ($row['values']['branch_id'] ?? $was['branch_id'] ?? '');
                $ids = [];
                foreach (preg_split('/\s*[,;|]\s*/', $row['branches_cell']) ?: [] as $name) {
                    $hit = false;
                    foreach ($this->store->all('branches') as $b) {
                        if ($company !== '' && (string) ($b['employer_company_id'] ?? '') !== '' && (string) $b['employer_company_id'] !== $company) {
                            continue;
                        }
                        if (preg_match('/^all( branches)?$/i', $name) === 1 || strcasecmp(trim((string) ($b['name'] ?? '')), $name) === 0) {
                            $ids[] = (string) $b['id'];
                            $hit = true;
                        }
                    }
                    $name === '' || $hit || $row['problems'][] = self::BRANCHES_LABEL . ": '{$name}' is not a branch of this employee's company.";
                }
                $row['extras'][self::BRANCHES] = implode(',', array_values(array_unique(array_diff($ids, [$home]))));
                unset($row['branches_cell']);
            }

            // A REQUIRED FIELD MISSING FROM A NEW EMPLOYEE'S ROW, whether the
            // cell is empty or the sheet has no such column at all — the loop
            // above only ever visits columns the file carries, so a sample with
            // the Branch column deleted would otherwise import a whole
            // organisation with no branches in it (owner, 23 Sep: Branch and
            // Reporting Manager mandatory on the Add form AND the upload, "this
            // will help in having proper hierarchy up to date").
            //
            // ONLY ON CREATE. An `update` row writes just the cells it carries,
            // so a blank one leaves what the employee already has — demanding a
            // value there would refuse a file whose whole purpose is to correct
            // one column. A `skip` row writes nothing at all.
            if ($row['action'] === 'create') {
                foreach ($fields as $f) {
                    if (!($f['required'] ?? false) || isset($row['none'][(string) $f['code']])) {
                        continue;
                    }

                    $held = ($f['storage'] ?? 'column') === 'column'
                        ? ($row['values'][(string) $f['column']] ?? '')
                        : ($row['extras'][(string) $f['code']] ?? '');

                    if (trim((string) $held) === '') {
                        $row['problems'][] = $f['label'] . ' is required, and this row has none.';
                    }
                }
            }

            // THE ORGANISATION MUST HANG TOGETHER (28 Sep), exactly as on the
            // Directory form: an update is checked as the employee will be after
            // it — their current units with this row's cells laid over them.
            if (in_array($row['action'], ['create', 'update'], true)) {
                $after = $row['action'] === 'update'
                    ? array_merge((array) ($existing[$lower] ?? []), array_filter($row['values'], static fn ($v): bool => $v !== null && $v !== ''))
                    : $row['values'];

                foreach (OrgConsistency::problems($this->store, $after) as $orgProblem) {
                    $row['problems'][] = $orgProblem;
                }
            }

            if ($row['code'] !== '') {
                $seen[$lower] = $line;
            }

            if ($row['problems'] === []) {
                $ok++;
            } else {
                $errors++;
                $row['action'] = 'error';
            }

            $rows[] = $row;

            if (count($rows) > self::MAX_ROWS) {
                throw new \RuntimeException('More than ' . self::MAX_ROWS . ' rows. Split the file: an import is reviewed '
                    . 'before it is committed, and nobody reviews two thousand lines.');
            }
        }

        return ['rows' => $rows, 'columns' => $columns, 'unknown' => $unknown, 'ok' => $ok, 'errors' => $errors,
            'header_line' => $headerLine, 'sample_lines' => $sampleLines];
    }

    /**
     * WHY NOTHING WAS READ, by line (owner, 5 Nov 2026: "the error description is not
     * clear where is the error in the file") — '' when the file has rows.
     */
    public static function emptyWhy(array $parse): string
    {
        if ($parse['rows'] !== []) {
            return '';
        }

        $sample = (array) ($parse['sample_lines'] ?? []);

        if ($sample === []) {
            return 'The file has no employee rows below the heading row (line ' . (int) $parse['header_line'] . '). Type one employee per line from line '
                . ((int) $parse['header_line'] + 1) . ' down and upload it again.';
        }

        $lines = array_keys($sample);
        $first = min($lines);
        $examples = array_filter(array_slice($sample, 1, null, true));
        $exampleLines = array_keys(array_slice($sample, 1, null, true));

        return 'The file has no employee rows. Line ' . $first . ' is the sample\'s data-type row'
            . ($exampleLines === [] ? '' : ' and line' . (count($exampleLines) > 1 ? 's ' . min($exampleLines) . '–' . max($exampleLines) . ' are' : ' ' . $exampleLines[0] . ' is')
                . ' its example row' . (count($exampleLines) > 1 ? 's' : '') . ($examples === [] ? '' : ' (' . implode(', ', $examples) . ')'))
            . ' — the import always skips ' . (count($lines) > 1 ? 'these' : 'it') . '. Keep lines 1–' . max($lines)
            . ' as they are, type your employees from line ' . (max($lines) + 1) . ' down, and upload the file again.';
    }

    /** What a value becomes before it is checked or stored: trimmed, and upper-cased where the field says so (PAN, IFSC). */
    /**
     * Is this the field's declared "no one" answer (`none_option.value` in the
     * catalogue — Reporting Manager's "top of the organisation")? Compared
     * without case, so a sheet typed by hand still matches.
     *
     * @param array<string, mixed> $f
     */
    public static function isNone(array $f, string $value): bool
    {
        $none = trim((string) ($f['none_option']['value'] ?? ''));

        return $none !== '' && strcasecmp(trim($value), $none) === 0;
    }

    public static function normalise(array $f, string $value): string
    {
        $value = trim($value);

        return ($f['uppercase'] ?? false) ? strtoupper($value) : $value;
    }

    /** A 0-based column index as the sheet letter: 0 → A, 26 → AA. */
    public static function columnLetter(int $index): string
    {
        $s = '';

        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26) . $s;
        }

        return $s;
    }

    /**
     * ONE VALUE AGAINST ITS FIELD — the same check whether the value came from the
     * Add / Edit form, self-onboarding or an uploaded sheet. Null when it is fine,
     * else a sentence a person can act on. The field's `pattern` (a regex in
     * config/employee-fields.json, with `pattern_hint` saying what it wants) is
     * what makes a PAN a PAN and a mobile number ten digits.
     */
    public function problemWith(array $f, string $value): ?string
    {
        $type = (string) ($f['type'] ?? 'Text');
        $pattern = (string) ($f['pattern'] ?? '');

        // A DROPDOWN (LOOKUP) CELL IS CHECKED AGAINST ITS LIST FIRST (owner, 27–28 Oct: no last-minute
        // errors). The sample's dropdown offers the option's NAME ("General"), which parse() turns into its
        // id afterwards — testing that name as the field's data type ("Numeric") refused the very value the
        // sample offered. A name on the list, or its stored id, is right; anything else is named.
        if (($f['control'] ?? '') === 'lookup') {
            $options = $this->lookups[(string) ($f['lookup'] ?? '')] ?? [];

            if ($options === []) {
                return null;
            }

            foreach ($options as $id => $label) {
                if (strcasecmp((string) $label, $value) === 0 || (string) $id === $value) {
                    return null;
                }
            }

            return "'{$value}' is not one of the " . (string) ($f['lookup'] ?? 'options') . ' set up under Organization Setup.';
        }


        if ($pattern !== '' && preg_match('~' . $pattern . '~', $value) !== 1) {
            return "'{$value}' is not " . (string) ($f['pattern_hint'] ?? 'in the expected format') . '.';
        }

        if ($type === 'Date') {
            return Dates::toIso($value) !== null ? null : "'{$value}' is not a date (dd-mm-yyyy).";
        }

        if ($type === 'Numeric') {
            return preg_match('/^-?\d+(\.\d+)?$/', $value) === 1 ? null : "'{$value}' is not a number.";
        }

        if ($type === 'Email') {
            return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? null : "'{$value}' is not an email address.";
        }

        if ($type === 'Boolean (Yes/No/NA)') {
            return in_array(strtolower($value), ['yes', 'no', 'na', '1', '0'], true) ? null : "'{$value}' is not Yes, No or NA.";
        }

        if ($type === 'List' && !empty($f['options'])) {
            foreach ((array) $f['options'] as $o) {
                if (strcasecmp((string) $o, $value) === 0) {
                    return null;
                }
            }

            return "'{$value}' is not one of: " . implode(' | ', (array) $f['options']) . '.';
        }

        return null;
    }

    /**
     * Keep the file, record the job as previewed, return the token and the parse.
     *
     * @return array{token: string, parse: array<string, mixed>, job: array<string, mixed>}
     */
    public function preview(string $text, string $filename, string $dupMode, string $by): array
    {
        $parse = $this->parse($text, $dupMode);

        if (!is_dir($this->spool) && !mkdir($this->spool, 0775, true) && !is_dir($this->spool)) {
            throw new \RuntimeException("The import spool {$this->spool} could not be created.");
        }

        $token = bin2hex(random_bytes(12));
        file_put_contents($this->spool . '/' . $token . '.csv', $text);

        $job = $this->store->put(self::JOBS, [
            'token' => $token,
            'filename' => mb_substr($filename, 0, 255),
            'status' => 'previewed',
            'dup_mode' => $dupMode === 'update' ? 'update' : 'skip',
            'total_rows' => count($parse['rows']),
            'error_count' => $parse['errors'],
            'errors' => json_encode(self::errorList($parse['rows']), JSON_UNESCAPED_UNICODE),
            'mapping' => json_encode($parse['columns'], JSON_UNESCAPED_UNICODE),
            'imported_by' => $by,
        ]);

        return ['token' => $token, 'parse' => $parse, 'job' => $job];
    }

    /**
     * Commit a previewed file. Rows with problems are never written.
     *
     * @return array{ok: bool, why: string, created: int, updated: int, skipped: int, errors: int, codes: list<string>}
     */
    public function commit(string $token, string $by): array
    {
        $job = null;

        foreach ($this->store->all(self::JOBS) as $j) {
            if ((string) ($j['token'] ?? '') === $token) {
                $job = $j;

                break;
            }
        }

        $path = $this->spool . '/' . preg_replace('/[^a-f0-9]/', '', $token) . '.csv';

        if ($job === null || !is_file($path)) {
            return ['ok' => false, 'why' => 'That preview is not here any more. Upload the file again.', 'created' => 0,
                'updated' => 0, 'skipped' => 0, 'errors' => 0, 'codes' => []];
        }

        if ((string) $job['status'] !== 'previewed') {
            return ['ok' => false, 'why' => "This file was already {$job['status']}. Importing it twice would write "
                . 'every row twice.', 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'codes' => []];
        }

        $parse = $this->parse((string) file_get_contents($path), (string) $job['dup_mode']);
        $created = $updated = $skipped = 0;
        $codes = [];

        // ALL-OR-NOTHING (PRS2): a file with a refused row is not imported at all.
        // Half a staff list is worse than none — the refused rows are in the
        // report; fix them and check the file again.
        if (($this->declared()['all_or_nothing'] ?? true) && $parse['errors'] > 0) {
            return ['ok' => false, 'why' => "{$parse['errors']} row(s) are refused, so nothing was written: an import is all or nothing. "
                . 'Download the failure report, fix those rows, and check the file again.', 'created' => 0, 'updated' => 0, 'skipped' => 0,
                'errors' => $parse['errors'], 'codes' => []];
        }

        foreach ($parse['rows'] as $row) {
            if ($row['action'] === 'error') {
                continue;
            }

            if ($row['action'] === 'skip') {
                $skipped++;

                continue;
            }

            $values = $row['values'];

            // ROLE IS NEVER A CELL IN THE FILE — Organization Configuration →
            // Role / Designation Setup is the only place it comes from
            // (roleFromDesignation() in public/app.php does the same thing for
            // the Add/Self-onboarding forms; duplicated here because this class
            // never sees $_POST or orgNames(), only this row and $this->lookups).
            // Update mode with the Designation column left out of the file keeps
            // whatever designation the record already has.
            $designationId = (string) ($values['designation_id']
                ?? ($row['action'] === 'update' ? (string) ($this->store->find('employees', (string) $row['existing_id'])['designation_id'] ?? '') : ''));
            $values['role'] = $designationId === '' ? '' : (string) ($this->lookups['designation_roles'][$designationId] ?? '');

            if ($row['action'] === 'update') {
                unset($values[Employment::CODE_COLUMN]);
                $this->store->put('employees', $values + ['id' => $row['existing_id']]);
                $updated++;
            } else {
                $this->store->put('employees', $values + [
                    'status' => $values['status'] ?? 'active',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $created++;
                $codes[] = $row['code'];
            }

            if ($row['extras'] !== []) {
                $this->extras->save($row['code'], $row['extras'], $by);
            }
        }

        $job['status'] = 'imported';
        $job['created_count'] = $created;
        $job['updated_count'] = $updated;
        $job['skipped_count'] = $skipped;
        $job['error_count'] = $parse['errors'];
        $job['imported_by'] = $by;
        $job['imported_at'] = date('Y-m-d H:i:s');
        $this->store->put(self::JOBS, $job);
        @unlink($path);

        return ['ok' => true, 'created' => $created, 'updated' => $updated, 'skipped' => $skipped,
            'errors' => $parse['errors'], 'codes' => $codes,
            'why' => "{$created} created, {$updated} updated, {$skipped} skipped (code already here), "
                . "{$parse['errors']} row(s) refused and not written."];
    }

    /** config/employee-import.json — the heading aliases and the all-or-nothing rule. @return array<string, mixed> */
    public function declared(): array
    {
        $file = dirname(__DIR__) . '/config/employee-import.json';
        $d = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($d) ? $d : ['aliases' => [], 'all_or_nothing' => true];
    }

    /** @return list<array<string, mixed>> newest first */
    public function history(int $limit = 50): array
    {
        $rows = $this->store->all(self::JOBS);
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return array_slice($rows, 0, $limit);
    }

    /** @return list<array{line: int, problems: list<string>}> */
    public static function errorList(array $rows): array
    {
        $out = [];

        foreach ($rows as $r) {
            if ($r['problems'] !== []) {
                $out[] = ['line' => (int) $r['line'], 'problems' => $r['problems']];
            }
        }

        return $out;
    }

    /** The refused lines of a job as CSV, for the person who has to fix the sheet. */
    public static function errorCsv(array $job): string
    {
        $errors = json_decode((string) ($job['errors'] ?? '[]'), true) ?: [];
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['line', 'problem'], ',', '"', '\\');

        foreach ($errors as $e) {
            foreach ((array) ($e['problems'] ?? []) as $p) {
                fputcsv($out, [(int) $e['line'], (string) $p], ',', '"', '\\');
            }
        }

        rewind($out);

        return (string) stream_get_contents($out);
    }
}
