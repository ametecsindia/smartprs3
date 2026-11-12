<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * A SPREADSHEET OF ATTENDANCE, HELD UNTIL SOMEBODY APPROVES IT.
 *
 * Most customers on SmartPRS 2 have no device at half their branches. Attendance
 * there is a supervisor's register, typed into a sheet at month end and mailed to
 * HR — and in SmartPRS 2 that sheet is loaded by `AttendanceBulkController` (rev
 * 82): template → fill one row per employee-day → upload → pending batch → an
 * administrator approves → punches. This is that screen, rebuilt on the rule
 * that everything here already obeys: PUNCHES IN, PAYABLE DAYS OUT. An approved
 * row becomes two ordinary punches in `attendance_logs` with `source = bulk`,
 * and the register settles them exactly as it settles a device's.
 *
 * FOUR THINGS IT REFUSES, each of which SmartPRS 2 does and each of which costs
 * somebody a day of pay.
 *
 * 1. IT NEVER MATCHES A PERSON BY NAME ALONE WHEN TWO PEOPLE HAVE IT. SmartPRS 2
 *    resolves by lower-cased name as a fallback and takes whichever it saw last.
 *    Two "Ravi Kumar"s in a collections agency is not a corner case; a row that
 *    names one of them is REFUSED with both codes in the message, and the
 *    uploader puts the code in.
 *
 * 2. IT NEVER GUESSES A DATE FORMAT. `01/09/2026` is September here and January
 *    in the library that parses it, and both parse. The accepted formats are
 *    declared below and a value that does not re-format back to itself is
 *    refused, line by line, with the line number.
 *
 * 3. IT NEVER WRITES A PUNCH ON UPLOAD. Upload produces claims. Only a decision
 *    with `attendance.bulk.decide` turns them into punches, and a rejected batch
 *    adds nothing — not a zero-minute day, not a cancelled one.
 *
 * 4. IT NEVER APPROVES TWICE. A batch's pending rows are the only ones a decision
 *    touches, and every punch it writes carries `external_id = bulk:<batch>:<line>`
 *    so a second approval — a double click, a resubmitted form — finds them
 *    already present and writes nothing.
 *
 * Errors are KEPT, not dropped: an unreadable line is stored with its error so
 * the uploader sees exactly which line of their file and why. A row is never
 * deleted; the uploader may WITHDRAW one before approval and it stays, marked.
 *
 * ponytail: CSV only, read whole, no xlsx parser (SmartPRS 2 parses xlsx in the
 * browser with SheetJS; Excel saves CSV in one click). One batch is one file;
 * the ceiling is stated in MAX_ROWS.
 */
final class AttendanceBulk
{
    public const COLLECTION = 'attendance_bulk_rows';

    public const PENDING = 'pending';
    public const ERROR = 'error';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const WITHDRAWN = 'withdrawn';

    /** What a punch written from an approved row says it is. */
    public const SOURCE = 'bulk';

    /** One file, one batch. Above this, split the file — stated, not silently truncated. */
    public const MAX_ROWS = 3000;

    /**
     * The two templates. Column NAMES, matched from the header row — never
     * positions, because the first thing a person does to a template is reorder
     * the columns.
     */
    public const DAY_COLUMNS = ['emp_code', 'employee_name', 'date', 'in_time', 'out_time'];
    public const PUNCH_COLUMNS = ['emp_code', 'employee_name', 'date', 'punch_time', 'direction'];

    /** Declared, and a value must re-format back to itself. No strtotime(). */
    private const DATE_FORMATS = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y'];

    /** The words a direction cell may hold. Anything else is refused by line. */
    private const IN_WORDS = ['in', 'i', '1', 'entry', 'checkin', 'check-in', 'check in'];
    private const OUT_WORDS = ['out', 'o', '0', 'exit', 'checkout', 'check-out', 'check out'];

    public function __construct(
        private readonly RowStore $store,
        private readonly Attendance $attendance,
    ) {
    }

    /** The CSV a person downloads, fills and uploads. `day` or `punch`. */
    public static function template(string $kind): string
    {
        if ($kind === 'punch') {
            return implode(',', self::PUNCH_COLUMNS) . "\n"
                . "EMP100,Asha Rao,05-06-2026,09:15,in\n"
                . "EMP100,Asha Rao,05-06-2026,18:35,out\n";
        }

        return implode(',', self::DAY_COLUMNS) . "\n"
            . "EMP100,Asha Rao,05-06-2026,09:15,18:35\n"
            . "EMP101,Vikram Reddy,05-06-2026,09:30,18:10\n";
    }

    /**
     * The file's lines as claims, and every line that could not be read.
     *
     * Nothing is dropped silently. A truncated last line (the usual way a
     * spreadsheet export ends) stops that line and not the file.
     *
     * @return array{kind: string, rows: list<array<string, mixed>>,
     *               unparsable: list<array{line: int, why: string}>}
     * @throws \RuntimeException when there is no header, or it is neither template
     */
    public static function parse(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?: [];
        // A UTF-8 BOM, which Excel writes in front of the first header cell.
        $lines[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($lines[0] ?? '')) ?? '';

        $header = array_map(
            static fn (string $h): string => strtolower(trim(trim($h), '"')),
            str_getcsv((string) ($lines[0] ?? ''), ',', '"', '\\'),
        );

        $kind = match (true) {
            self::hasAll($header, self::DAY_COLUMNS) => 'day',
            self::hasAll($header, self::PUNCH_COLUMNS) => 'punch',
            default => throw new \RuntimeException(
                'The first line is not one of the two templates. It must name the columns '
                . implode(', ', self::DAY_COLUMNS) . ' (one row per employee-day) or '
                . implode(', ', self::PUNCH_COLUMNS) . ' (one row per punch). Download the '
                . 'template rather than typing the header: a column matched by position would '
                . 'read an out time as an in time the first time somebody reorders the sheet.'
            ),
        };

        $index = array_flip($header);
        $rows = [];
        $unparsable = [];

        foreach ($lines as $offset => $raw) {
            $line = $offset + 1;

            if ($line === 1 || trim($raw) === '') {
                continue;
            }

            $cells = str_getcsv($raw, ',', '"', '\\');
            $cell = static fn (string $name): string => trim((string) ($cells[$index[$name]] ?? ''));

            $row = [
                'line_no' => $line,
                'given_code' => $cell('emp_code'),
                'given_name' => $cell('employee_name'),
                'date' => $cell('date'),
            ];

            if ($kind === 'day') {
                $row['in'] = $cell('in_time');
                $row['out'] = $cell('out_time');
            } else {
                $row['time'] = $cell('punch_time');
                $row['direction'] = $cell('direction');
            }

            if ($row['given_code'] === '' && $row['given_name'] === '') {
                $unparsable[] = ['line' => $line, 'why' => 'names nobody — both emp_code and '
                    . 'employee_name are empty'];

                continue;
            }

            $rows[] = $row;
        }

        return ['kind' => $kind, 'rows' => $rows, 'unparsable' => $unparsable];
    }

    /** @param list<string> $header @param list<string> $needed */
    private static function hasAll(array $header, array $needed): bool
    {
        return array_diff($needed, $header) === [];
    }

    /** A declared date format or nothing. `01/09/2026` is September and only September. */
    public static function date(string $value): ?string
    {
        foreach (self::DATE_FORMATS as $format) {
            $d = \DateTimeImmutable::createFromFormat('!' . $format, $value);

            if ($d !== false && $d->format($format) === $value) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * `9:5`, `09:05`, `9.05 AM`, `18:35` → `HH:MM`, or nothing.
     *
     * An Excel time fraction (0.3854) is NOT accepted: it would need a float, and
     * a float is refused in this codebase at the gate. Save the sheet as CSV with
     * the cells formatted as times and Excel writes `09:15`.
     */
    public static function time(string $value): ?string
    {
        $s = strtoupper(str_replace(' ', '', trim($value)));

        if ($s === '') {
            return null;
        }

        $meridian = null;

        foreach (['AM', 'PM'] as $suffix) {
            if (str_ends_with($s, $suffix)) {
                $meridian = $suffix;
                $s = substr($s, 0, -2);
            }
        }

        // `6 PM` is a time; a bare `6` is not — with no meridian and no minutes
        // there is nothing to say it was not `06:00` typed by somebody who meant
        // the evening.
        if (preg_match('/^(\d{1,2})(?:[:.](\d{1,2}))?$/', $s, $m) !== 1
            || (!isset($m[2]) && $meridian === null)) {
            return null;
        }

        $h = (int) $m[1];
        $min = (int) ($m[2] ?? 0);

        if ($meridian === 'PM' && $h < 12) {
            $h += 12;
        }

        if ($meridian === 'AM' && $h === 12) {
            $h = 0;
        }

        if ($h > 23 || $min > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $h, $min);
    }

    /**
     * Code => employee code, and lower-cased name => codes, from the data.
     *
     * `emp_code` and `device_user_id` (the biometric enrolment number, so a sheet
     * copied off a device matches) are both looked up. A name is looked up too,
     * but a name held by two employees resolves to NEITHER — the message names
     * both and the uploader supplies the code.
     *
     * @return array{codes: array<string, string>, names: array<string, list<string>>}
     */
    public function directory(): array
    {
        $codes = [];
        $names = [];

        foreach ($this->store->all('employees') as $employee) {
            $code = Employment::code($employee);

            if ($code === '') {
                continue;
            }

            $codes[strtolower($code)] = $code;
            $device = strtolower(trim((string) ($employee['device_user_id'] ?? '')));

            if ($device !== '' && !isset($codes[$device])) {
                $codes[$device] = $code;
            }

            $name = strtolower(preg_replace('/\s+/', ' ', trim((string) ($employee['name'] ?? ''))) ?? '');

            if ($name !== '') {
                $names[$name][] = $code;
            }
        }

        return ['codes' => $codes, 'names' => $names];
    }

    /**
     * Load one file into one pending batch. Every row is kept, valid or not.
     *
     * `$now` is passed in so a test can put "today" where it needs it; the screen
     * passes the clock. Nothing here writes a punch.
     *
     * @return array{batch: string, kind: string, total: int, pending: int,
     *               errors: int, unparsable: int, unknown: array<string, int>}
     */
    public function upload(string $text, string $by, \DateTimeImmutable $now): array
    {
        $parsed = self::parse($text);

        if ($parsed['rows'] === [] && $parsed['unparsable'] === []) {
            throw new \RuntimeException('The file has a header and no rows. Nothing was loaded.');
        }

        if (count($parsed['rows']) > self::MAX_ROWS) {
            throw new \RuntimeException(count($parsed['rows']) . ' rows is more than one batch takes ('
                . self::MAX_ROWS . '). Split the file: a batch is approved as a whole, and a whole '
                . 'year of a branch behind one button is a decision nobody can review.');
        }

        $directory = $this->directory();
        $batch = Store::id();
        $today = $now->format('Y-m-d');
        $clock = $now->format('H:i');
        $pending = 0;
        $errors = 0;
        $unknown = [];

        foreach ($parsed['rows'] as $row) {
            $claim = [
                'batch' => $batch,
                'line_no' => (int) $row['line_no'],
                'emp_code' => null,
                'given_code' => $row['given_code'] === '' ? null : $row['given_code'],
                'given_name' => $row['given_name'] === '' ? null : $row['given_name'],
                'log_date' => null,
                'in_time' => null,
                'out_time' => null,
                'status' => self::PENDING,
                'error' => null,
                'uploaded_by' => $by,
                'decided_by' => null,
                'decided_at' => null,
            ];

            $why = $this->resolve($row, $directory, $claim, $today, $clock, $parsed['kind']);

            if ($why !== null) {
                $claim['status'] = self::ERROR;
                $claim['error'] = mb_substr($why, 0, 300);
                $errors++;

                if ($claim['emp_code'] === null && $row['given_code'] !== '') {
                    $unknown[$row['given_code']] = ($unknown[$row['given_code']] ?? 0) + 1;
                }
            } else {
                $pending++;
            }

            $this->store->put(self::COLLECTION, $claim);
        }

        foreach ($parsed['unparsable'] as $bad) {
            $this->store->put(self::COLLECTION, [
                'batch' => $batch, 'line_no' => $bad['line'], 'emp_code' => null,
                'given_code' => null, 'given_name' => null, 'log_date' => null,
                'in_time' => null, 'out_time' => null, 'status' => self::ERROR,
                'error' => 'Line ' . $bad['line'] . ' ' . $bad['why'], 'uploaded_by' => $by,
                'decided_by' => null, 'decided_at' => null,
            ]);
        }

        return ['batch' => $batch, 'kind' => $parsed['kind'],
            'total' => count($parsed['rows']) + count($parsed['unparsable']),
            'pending' => $pending, 'errors' => $errors + count($parsed['unparsable']),
            'unparsable' => count($parsed['unparsable']), 'unknown' => $unknown];
    }

    /**
     * Fill the claim in from the row, or say why it cannot be.
     *
     * @param array<string, mixed> $row
     * @param array{codes: array<string, string>, names: array<string, list<string>>} $directory
     * @param array<string, mixed> $claim
     */
    private function resolve(array $row, array $directory, array &$claim, string $today,
        string $clock, string $kind): ?string
    {
        $code = strtolower((string) $row['given_code']);
        $name = strtolower(preg_replace('/\s+/', ' ', (string) $row['given_name']) ?? '');

        if ($code !== '') {
            if (!isset($directory['codes'][$code])) {
                return "No employee has the code or device id '{$row['given_code']}'. Not matched "
                    . 'by name instead: a sheet that says one code and means another is how the '
                    . 'wrong person gets paid.';
            }

            $claim['emp_code'] = $directory['codes'][$code];
        } else {
            $matches = $directory['names'][$name] ?? [];

            if ($matches === []) {
                return "No employee is named '{$row['given_name']}' and no code was given.";
            }

            if (count($matches) > 1) {
                return "'{$row['given_name']}' is " . count($matches) . ' employees ('
                    . implode(', ', $matches) . '). Put the code in the emp_code column.';
            }

            $claim['emp_code'] = $matches[0];
        }

        $date = self::date((string) $row['date']);

        if ($date === null) {
            return "'{$row['date']}' is not a date in any accepted form (" . implode(', ',
                self::DATE_FORMATS) . ').';
        }

        if ($date > $today) {
            return "{$date} has not happened yet. Attendance is recorded, never scheduled.";
        }

        $claim['log_date'] = $date;

        if ($kind === 'punch') {
            $time = self::time((string) $row['time']);
            $word = strtolower(trim((string) $row['direction']));

            if ($time === null) {
                return "'{$row['time']}' is not a time (HH:MM, optionally AM/PM).";
            }

            if (in_array($word, self::IN_WORDS, true)) {
                $claim['in_time'] = $time;
            } elseif (in_array($word, self::OUT_WORDS, true)) {
                $claim['out_time'] = $time;
            } else {
                return "'{$row['direction']}' is not a direction. Use in or out — a punch whose "
                    . 'direction is guessed settles a day nobody can audit.';
            }
        } else {
            $in = (string) $row['in'];
            $out = (string) $row['out'];

            if ($in === '' && $out === '') {
                return 'Neither an in time nor an out time. An absent day is not uploaded; it is '
                    . 'the absence of punches.';
            }

            if ($in !== '' && ($claim['in_time'] = self::time($in)) === null) {
                return "'{$in}' is not a time (HH:MM, optionally AM/PM).";
            }

            if ($out !== '' && ($claim['out_time'] = self::time($out)) === null) {
                return "'{$out}' is not a time (HH:MM, optionally AM/PM).";
            }

            if ($claim['in_time'] !== null && $claim['out_time'] !== null
                && $claim['out_time'] <= $claim['in_time']) {
                return "Out {$claim['out_time']} is not after in {$claim['in_time']}. A night shift is "
                    . 'uploaded as two punch rows (the punch template), one per date.';
            }
        }

        foreach (['in_time', 'out_time'] as $column) {
            if ($date === $today && $claim[$column] !== null && $claim[$column] > $clock) {
                return "{$claim[$column]} today is still in the future.";
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> every row of this tenant, newest batch first */
    public function all(): array
    {
        $rows = $this->store->all(self::COLLECTION);

        usort($rows, static fn (array $a, array $b): int =>
            [(string) ($b['created_at'] ?? ''), (string) $b['batch'], (int) $a['line_no']]
            <=> [(string) ($a['created_at'] ?? ''), (string) $a['batch'], (int) $b['line_no']]);

        return $rows;
    }

    /**
     * One line per batch: who, when, and how many rows in each state.
     *
     * @return list<array{batch: string, uploaded_by: string, at: string, total: int,
     *               pending: int, errors: int, approved: int, rejected: int, withdrawn: int,
     *               decided_by: string, decided_at: string}>
     */
    public function batches(): array
    {
        $out = [];

        foreach ($this->all() as $row) {
            $key = (string) $row['batch'];
            $out[$key] ??= ['batch' => $key, 'uploaded_by' => (string) ($row['uploaded_by'] ?? ''),
                'at' => (string) ($row['created_at'] ?? ''), 'total' => 0, 'pending' => 0,
                'errors' => 0, 'approved' => 0, 'rejected' => 0, 'withdrawn' => 0,
                'decided_by' => (string) ($row['decided_by'] ?? ''),
                'decided_at' => (string) ($row['decided_at'] ?? '')];
            $out[$key]['total']++;
            $bucket = match ((string) $row['status']) {
                self::PENDING => 'pending', self::ERROR => 'errors', self::APPROVED => 'approved',
                self::REJECTED => 'rejected', default => 'withdrawn',
            };
            $out[$key][$bucket]++;
        }

        return array_values($out);
    }

    /** @return list<array<string, mixed>> the rows of one batch, in file order */
    public function rows(string $batch): array
    {
        $rows = array_values(array_filter($this->all(),
            static fn (array $r): bool => (string) $r['batch'] === $batch));

        usort($rows, static fn (array $a, array $b): int => (int) $a['line_no'] <=> (int) $b['line_no']);

        return $rows;
    }

    /**
     * Approve or reject every PENDING row of a batch. Only an approval touches
     * attendance, and only by adding.
     *
     * @return array{ok: bool, why: string, decided: int, punches: int, already: int}
     */
    public function decide(string $batch, bool $approve, string $by, ?string $onlyRow = null): array
    {
        $pending = array_values(array_filter($this->rows($batch),
            static fn (array $r): bool => (string) $r['status'] === self::PENDING && ($onlyRow === null || (string) $r['id'] === $onlyRow)));

        if ($pending === []) {
            return ['ok' => false, 'decided' => 0, 'punches' => 0, 'already' => 0,
                'why' => 'This batch has no pending rows. It was already decided, or every row '
                    . 'was an error — errors are never approved into attendance.'];
        }

        $present = [];

        foreach ($this->store->all('attendance_logs') as $punch) {
            $ref = (string) ($punch['external_id'] ?? '');

            if ($ref !== '') {
                $present[$ref] = true;
            }
        }

        $punches = 0;
        $already = 0;
        $when = date('Y-m-d H:i:s');

        foreach ($pending as $row) {
            if ($approve) {
                foreach (['in_time' => 'in', 'out_time' => 'out'] as $column => $direction) {
                    if ($row[$column] === null || $row[$column] === '') {
                        continue;
                    }

                    $reference = self::reference((string) $row['batch'], (int) $row['line_no'], $direction);

                    if (isset($present[$reference])) {
                        $already++;

                        continue;
                    }

                    // The same punch already on file (typed in, another batch) is not added twice.
                    $this->attendance->record((string) $row['emp_code'],
                        $row['log_date'] . ' ' . $row[$column] . ':00', $direction, self::SOURCE, $reference) ? $punches++ : $already++;
                    $present[$reference] = true;
                }
            }

            $row['status'] = $approve ? self::APPROVED : self::REJECTED;
            $row['decided_by'] = $by;
            $row['decided_at'] = $when;
            $this->store->put(self::COLLECTION, $row);
        }

        return ['ok' => true, 'decided' => count($pending), 'punches' => $punches, 'already' => $already,
            'why' => $approve
                ? count($pending) . ' row(s) approved — ' . $punches . ' punch(es) written to attendance '
                    . 'with source=bulk and the batch and line on each, so approving this batch again '
                    . 'writes nothing.' . ($already > 0 ? " {$already} were already present." : '')
                : count($pending) . ' row(s) rejected. No punch was written — not a zero-minute one, '
                    . 'not a cancelled one. The days stay as the device or the register left them.'];
    }

    /**
     * ONE ROW, DECIDED BY THE APPROVER (owner, 12 Oct: "for admins or reporting managers it
     * would be Approve or Reject"). A pending row is approved (its punches written) or
     * rejected; a refused row can only be rejected, which closes it. The batch buttons
     * remain for deciding everything at once.
     *
     * @return array{ok: bool, why: string}
     */
    public function decideRow(string $id, bool $approve, string $by): array
    {
        $row = $this->store->find(self::COLLECTION, $id);

        if ($row === null) {
            return ['ok' => false, 'why' => 'No such row.'];
        }

        $status = (string) $row['status'];

        if ($status === self::ERROR) {
            if ($approve) {
                return ['ok' => false, 'why' => 'Line ' . $row['line_no'] . ' was refused (' . trim((string) ($row['error'] ?? ''))
                    . '). A refused row is never approved — fix it in the sheet and load it again, or reject it.'];
            }

            $row['status'] = self::REJECTED;
            $row['decided_by'] = $by;
            $row['decided_at'] = date('Y-m-d H:i:s');
            $this->store->put(self::COLLECTION, $row);

            return ['ok' => true, 'why' => 'Line ' . $row['line_no'] . ' rejected.'];
        }

        if ($status !== self::PENDING) {
            return ['ok' => false, 'why' => 'Line ' . $row['line_no'] . ' is already ' . $status . '.'];
        }

        $r = $this->decide((string) $row['batch'], $approve, $by, $id);

        return ['ok' => $r['ok'], 'why' => 'Line ' . $row['line_no'] . ($approve ? ' approved — ' . $r['punches'] . ' punch(es) written.' : ' rejected. No punch was written.')];
    }

    /** Withdraw one row before it is decided. It stays, marked; nothing is deleted. */
    public function withdraw(string $id): array
    {
        $row = $this->store->find(self::COLLECTION, $id);

        if ($row === null) {
            return ['ok' => false, 'why' => 'No such row.'];
        }

        if (!in_array((string) $row['status'], [self::PENDING, self::ERROR], true)) {
            return ['ok' => false, 'why' => 'Row ' . $row['line_no'] . ' was already ' . $row['status']
                . '. A decided row is the evidence behind a punch and is not withdrawn.'];
        }

        $row['status'] = self::WITHDRAWN;
        $this->store->put(self::COLLECTION, $row);

        return ['ok' => true, 'why' => 'Line ' . $row['line_no'] . ' withdrawn. It stays in the batch, '
            . 'marked, so the file and the batch still agree line for line.'];
    }

    /** The idempotency key on every punch this class writes. */
    public static function reference(string $batch, int $line, string $direction): string
    {
        return "bulk:{$batch}:{$line}:{$direction}";
    }
}
