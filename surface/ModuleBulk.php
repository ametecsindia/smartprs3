<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * BULK UPLOAD FOR EVERY REGISTER (owner, 7 Oct: "sample bulk upload template
 * matching these cells … ensure the same thing is followed across application
 * wide"). One sample and one importer for every module on the generic screen,
 * built from the module's own field list, so the template always matches the
 * Add form cell for cell:
 *
 *   row 1  the labels (* = required)          row 2  the field codes the importer reads
 *   row 3  what each cell takes               row 4+ data
 *
 * A dropdown field gets an Excel dropdown of exactly the choices the form
 * offers (lookups as their labels, value=>label options as labels); dates are
 * real dates shown dd-mm-yyyy. On upload every row goes through
 * Modules::save() — the same validation as the Add form — and a refused row is
 * reported with its line and reason while the good rows are kept.
 */
final class ModuleBulk
{
    public const MAX_ROWS = 2000;

    /** @param array<string, mixed> $spec Modules::fields() @return list<array<string, mixed>> */
    public static function columns(array $spec): array
    {
        return array_values(array_filter((array) ($spec['fields'] ?? []), static fn (array $f): bool
            => !$f['readonly'] && !in_array((string) $f['type'], ['file', 'color'], true)));
    }

    /** @param array<string, mixed> $f @return list<string> the labels a cell may hold */
    private static function choices(array $f): array
    {
        if ((array) $f['lookup'] !== []) {
            return array_values(array_map(static fn (array $o): string => (string) $o['label'], (array) $f['lookup']));
        }

        if ((string) $f['type'] === 'checkbox') {
            return ['Yes', 'No'];
        }

        $o = (array) $f['options'];

        return array_values(array_map('strval', array_is_list($o) ? $o : array_values($o)));
    }

    public static function hint(array $f): string
    {
        $what = match (true) {
            self::choices($f) !== [] => 'Pick from the list',
            (string) $f['type'] === 'date' => 'Date dd-mm-yyyy',
            (string) $f['type'] === 'datetime-local' => 'dd-mm-yyyy hh:mm',
            (string) $f['type'] === 'month' => 'Month YYYY-MM e.g. 2026-10',
            (string) $f['type'] === 'number' => 'Number' . (($f['min'] ?? null) !== null ? ' ≥ ' . $f['min'] : ''),
            (string) $f['type'] === 'time' => 'Time hh:mm',
            default => 'Text',
        };

        return ($f['required'] ? 'Required · ' : '') . $what;
    }

    /**
     * The .xlsx bytes. `$data` are rows already filled in (row 4 on), one cell per field in order —
     * e.g. every employee pre-listed for a monthly register (14 Oct).
     *
     * @param list<array<string, mixed>> $fields
     * @param list<list<string>> $data
     * @param array<string, string> $employeeCompany employee id => company id. When the sheet has both an
     *        Employee and a Company column, the Company dropdown lists only the picked employee's company
     *        (owner, 22 Oct: "company name should be dependent dropdown based on the employee selected").
     */
    public static function sample(array $fields, array $data = [], array $employeeCompany = []): string
    {
        $cascades = [];
        $empCol = $coCol = null;

        foreach ($fields as $i => $f) {
            (string) $f['name'] === 'employee_id' && (array) $f['lookup'] !== [] && $empCol = $i;
            (string) $f['name'] === 'employer_company_id' && (array) $f['lookup'] !== [] && $coCol = $i;
        }

        if ($empCol !== null && $coCol !== null && $employeeCompany !== []) {
            $coLabel = [];
            foreach ((array) $fields[$coCol]['lookup'] as $o) { $coLabel[(string) $o['id']] = (string) $o['label']; }
            $groups = [];
            foreach ((array) $fields[$empCol]['lookup'] as $o) {
                $co = $coLabel[$employeeCompany[(string) $o['id']] ?? ''] ?? '';
                $co === '' || $groups[(string) $o['label']] = [$co];
            }
            $groups === [] || $cascades[] = ['parentCol' => $empCol, 'childCol' => $coCol, 'groups' => $groups];
        }

        $lists = [];
        $dates = [];
        $widths = [];

        foreach ($fields as $i => $f) {
            $c = self::choices($f);
            $c === [] || $lists[$i] = $c;
            (string) $f['type'] === 'date' && $dates[] = $i;
            $widths[$i] = 22.0;
        }

        return Sheet::xlsx([
            array_map(static fn (array $f): string => (string) $f['label'] . ($f['required'] ? ' *' : ''), $fields),
            array_map(static fn (array $f): string => (string) $f['name'], $fields),
            array_map(self::hint(...), $fields),
            ...$data,
        ], $lists, 3, $cascades, [], $dates, [], [], $widths);
    }

    /**
     * The uploaded sheet as rows ready for Modules::save().
     *
     * @param list<array<string, mixed>> $fields
     * @return array{rows: list<array{line: int, input: array<string, string>}>, problem: ?string}
     */
    public static function read(array $fields, string $csv): array
    {
        $lines = [];
        $h = fopen('php://memory', 'w+');
        fwrite($h, $csv);
        rewind($h);

        while (($cells = fgetcsv($h, 0, ',', '"', '')) !== false) {
            $lines[] = array_map(static fn ($v): string => trim((string) $v), $cells);
        }

        fclose($h);

        $byName = [];
        $byLabel = [];

        foreach ($fields as $f) {
            $byName[strtolower((string) $f['name'])] = $f;
            $byLabel[strtolower(rtrim((string) $f['label'], ' *'))] = $f;
        }

        // THE HEADER: the codes row if it is there, else the labels row.
        $header = null;
        $start = 0;

        // THE ROW WITH THE MOST FIELD CODES, not the first with any (14 Oct): a label that happens to be
        // its own code ("Remarks", "UAN", "Status", "CIN") made the labels row win, and every other column
        // of every data row was silently dropped.
        $best = 0;

        foreach (array_slice($lines, 0, 5, true) as $i => $cells) {
            $named = count(array_filter($cells, static fn (string $c): bool => isset($byName[strtolower($c)])));

            if ($named > $best) {
                [$header, $start, $best] = [array_map(static fn (string $c): ?array => $byName[strtolower($c)] ?? null, $cells), $i + 1, $named];
            }
        }

        if ($header === null) {
            foreach (array_slice($lines, 0, 5, true) as $i => $cells) {
                $named = array_filter($cells, static fn (string $c): bool => isset($byLabel[strtolower(rtrim($c, ' *'))]));

                if (count($named) > 0) {
                    [$header, $start] = [array_map(static fn (string $c): ?array => $byLabel[strtolower(rtrim($c, ' *'))] ?? null, $cells), $i + 1];
                    break;
                }
            }
        }

        if ($header === null) {
            return ['rows' => [], 'problem' => 'No column of this register was recognised. Use the downloaded sample as it is — only fill rows 4 onwards.'];
        }

        $rows = [];

        foreach (array_slice($lines, $start, null, true) as $i => $cells) {
            if (implode('', $cells) === '') {
                continue;
            }

            $input = [];
            $isHint = true;

            foreach ($header as $col => $f) {
                if ($f === null) {
                    continue;
                }

                $v = $cells[$col] ?? '';
                $isHint = $isHint && ($v === '' || $v === self::hint($f));
                $input[(string) $f['name']] = self::value($f, $v);
            }

            if ($isHint) {
                continue; // the "what each cell takes" row
            }

            $rows[] = ['line' => $i + 1, 'input' => $input];

            if (count($rows) > self::MAX_ROWS) {
                return ['rows' => [], 'problem' => 'More than ' . self::MAX_ROWS . ' rows — split the file. Nothing was saved.'];
            }
        }

        return ['rows' => $rows, 'problem' => $rows === [] ? 'The sheet has no data rows.' : null];
    }

    /** A cell as the value the form would post: a label becomes its id / key. */
    private static function value(array $f, string $v): string
    {
        if ($v === '') {
            return '';
        }

        if ((array) $f['lookup'] !== []) {
            foreach ((array) $f['lookup'] as $o) {
                if (strcasecmp((string) $o['label'], $v) === 0 || (string) $o['id'] === $v) {
                    return (string) $o['id'];
                }
            }

            // "AIPL-1002" alone matches the option that starts with it.
            foreach ((array) $f['lookup'] as $o) {
                if (stripos((string) $o['label'], $v . ' ') === 0) {
                    return (string) $o['id'];
                }
            }

            return $v;
        }

        if ((string) $f['type'] === 'checkbox') {
            return in_array(strtolower($v), ['yes', 'y', '1', 'true'], true) ? '1' : '0';
        }

        $o = (array) $f['options'];

        if (!array_is_list($o)) {
            foreach ($o as $key => $label) {
                if (strcasecmp((string) $label, $v) === 0 || strcasecmp((string) $key, $v) === 0) {
                    return (string) $key;
                }
            }
        } else {
            foreach ($o as $opt) {
                if (strcasecmp((string) $opt, $v) === 0) {
                    return (string) $opt;
                }
            }
        }

        // A month typed as a date ("01-10-2026" → ISO via the sheet reader) is its month.
        if ((string) $f['type'] === 'month' && preg_match('/^(\d{4}-\d{2})-\d{2}$/', $v, $m) === 1) {
            return $m[1];
        }

        if ((string) $f['type'] === 'month' && ($iso = Dates::toIso($v)) !== null) {
            return substr($iso, 0, 7);
        }

        return $v;
    }
}
