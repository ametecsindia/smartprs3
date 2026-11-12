<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Attendance\Punch;

/**
 * THE FILE A BIOMETRIC DEVICE ALREADY WRITES, TURNED INTO PUNCHES.
 *
 * Every punch in this product is typed by a human, and attendance software is
 * bought for exactly the opposite. `attendance_logs` has carried `device_sn`,
 * `device_user_id`, `external_id` and `verify_mode` since the port and nothing
 * has ever filled one.
 *
 * WHAT THIS IS NOT: a device driver. There is no TCP protocol here, no vendor
 * SDK, no push listener. ZKTeco, eSSL and Matrix all export a delimited log
 * file, that file is the universal interface between the device and anything
 * else, and the import is the rung of the ladder that holds. A driver is the
 * next rung and it does not change anything below: it produces the same rows.
 *
 * THREE THINGS IT REFUSES TO DO, because each of them costs somebody money.
 *
 * 1. IT NEVER GUESSES WHO PUNCHED. A device knows an enrolment number — 37 —
 *    and nothing else. 37 becomes an employee by looking it up in
 *    `employees.device_user_id`, which is a column somebody filled in on
 *    purpose. When 37 is on no employee its punches are REPORTED and NOT
 *    IMPORTED: an unmapped punch is somebody who worked and will not be paid
 *    for it, and a punch attached to a plausible-looking employee pays the
 *    wrong person. The first is a finding, the second is unrecoverable.
 *
 * 2. IT NEVER IMPORTS THE SAME PUNCH TWICE. Somebody re-runs an import — the
 *    first one half-failed, the file was re-exported, the operator was not
 *    sure it worked. Doubling everyone's attendance is the defect this class
 *    of tool always ships with. Every punch carries a deterministic
 *    `external_id` built from the device, the person and the instant, so the
 *    second import finds it already present and writes nothing.
 *
 * 3. IT NEVER INVENTS A DIRECTION. A row whose direction the layout cannot
 *    read is reported by line number, not defaulted to `in`. A settled day
 *    computed from a guessed direction is a payable day nobody can audit.
 *
 * THE LAYOUT IS DATA, NOT CODE. Which column holds the enrolment number, which
 * holds the instant, what format that instant is in, what the delimiter is —
 * these differ per vendor and per firmware, so they are DECLARED in a JSON file
 * the customer holds (`config/punch-layouts.json`), the same way calendars,
 * letters and salary structures are declared in this product. A `case 'zkteco'`
 * in this file would be a business literal in code, which is Law 1, and it
 * would also mean a new device model is a release rather than an edit.
 *
 * ponytail: whole-file read, whole-collection scan for existing ids, no batching
 * and no resume. One device's month is a few thousand lines and this runs on a
 * laptop. When somebody imports a year across forty devices, the shape does not
 * change: the existing-id scan becomes a `WHERE external_id IN (…)` on a named
 * repository method, and the parse already streams line by line.
 */
final class PunchImport
{
    /**
     * The PORTED table — the same one `Attendance` settles from. A second
     * "device punches" table beside it would be two homes for the same data,
     * which is the defect this rebuild exists to remove.
     */
    public const COLLECTION = 'attendance_logs';

    /** What a device-imported row says it is. Manual punches say 'manual'. */
    public const SOURCE = 'device';

    public function __construct(private readonly RowStore $store)
    {
    }

    /**
     * The declared layouts, read from a JSON file the customer owns.
     *
     * @return array<string, array<string, mixed>> name => layout
     * @throws \RuntimeException when the file is missing or is not layouts
     */
    public static function layoutsFrom(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(
                "There is no layout file at {$path}. A punch export cannot be read without one: the "
                . 'column order differs per device vendor, and guessing which column holds the '
                . 'enrolment number is how punches get attached to the wrong person.'
            );
        }

        $layouts = json_decode((string) file_get_contents($path), true);

        if (!is_array($layouts) || $layouts === []) {
            throw new \RuntimeException(
                "{$path} is not a readable set of layouts. It must be a JSON object of "
                . 'name => layout, one entry per device export format.'
            );
        }

        return $layouts;
    }

    /**
     * One layout, checked before a single line is read.
     *
     * Checked HERE rather than per row, because a layout missing its timestamp
     * format fails identically on every line of a ten thousand line file, and
     * ten thousand identical findings is a report nobody reads.
     *
     * @param array<string, mixed> $layouts
     * @return array<string, mixed>
     * @throws \RuntimeException when the name is not declared or the layout is incomplete
     */
    public static function layout(array $layouts, string $name = ''): array
    {
        if ($name === '') {
            if (count($layouts) !== 1) {
                throw new \RuntimeException(
                    'This file declares ' . count($layouts) . ' layouts ('
                    . implode(', ', array_keys($layouts)) . ') and none was named. Name one with '
                    . '--layout-name: reading a ZKTeco file with an eSSL layout produces rows that '
                    . 'parse and mean something else.'
                );
            }

            $name = (string) array_key_first($layouts);
        }

        $layout = $layouts[$name] ?? null;

        if (!is_array($layout)) {
            throw new \RuntimeException(
                "No layout is declared under '{$name}'. Declared: "
                . implode(', ', array_keys($layouts)) . '.'
            );
        }

        $columns = is_array($layout['columns'] ?? null) ? $layout['columns'] : [];
        $missing = [];

        foreach (['device_user_id', 'punch_at', 'direction'] as $required) {
            if (!isset($columns[$required]) || !is_int($columns[$required])) {
                $missing[] = "columns.{$required}";
            }
        }

        if (!isset($layout['timestamp_format']) || !is_string($layout['timestamp_format'])) {
            $missing[] = 'timestamp_format';
        }

        if (!isset($layout['directions']) || !is_array($layout['directions'])
            || $layout['directions'] === []) {
            $missing[] = 'directions';
        }

        if ($missing !== []) {
            throw new \RuntimeException(
                "The layout '{$name}' does not declare " . implode(', ', $missing) . '. All four are '
                . 'required and none has a default: a punch with no declared direction column would '
                . 'have to be given one, and a settled day computed from an invented direction is a '
                . 'payable day nobody can audit. A device that truly emits no direction needs a '
                . 'declared alternation rule, which does not exist yet — say so rather than assume.'
            );
        }

        $layout['name'] = $name;

        return $layout;
    }

    /**
     * The file's lines as punch fields, and every line that could not be read.
     *
     * NOTHING IS DROPPED SILENTLY. A malformed line stops that line and not the
     * import: an export with one truncated row at the end (the device was
     * unplugged mid-write, which is how these files usually end) must still
     * yield the month, and the truncated row must still be visible.
     *
     * @param array<string, mixed> $layout as returned by `layout()`
     * @return array{punches: list<array<string, string>>,
     *               unparsable: list<array{line: int, text: string, why: string}>}
     */
    public static function parse(string $text, array $layout): array
    {
        $delimiter = (string) ($layout['delimiter'] ?? 'whitespace');
        $columns = $layout['columns'];
        $format = (string) $layout['timestamp_format'];
        $directions = $layout['directions'];
        $header = (bool) ($layout['header'] ?? false);

        $punches = [];
        $unparsable = [];
        $line = 0;

        foreach (preg_split('/\R/', $text) ?: [] as $raw) {
            $line++;

            if ($header && $line === 1) {
                continue;
            }

            if (trim($raw) === '') {
                continue;
            }

            $fields = $delimiter === 'whitespace'
                ? preg_split('/\s+/', trim($raw))
                : explode($delimiter, $raw);
            $fields = array_map(static fn (string $f): string => trim($f), $fields ?: []);

            $at = self::field($fields, $columns['punch_at']);
            $deviceUserId = self::field($fields, $columns['device_user_id']);
            $rawDirection = self::field($fields, $columns['direction']);

            $needed = max($columns['punch_at'], $columns['device_user_id'], $columns['direction']);

            if (count($fields) <= $needed) {
                $unparsable[] = ['line' => $line, 'text' => $raw, 'why' => 'the layout needs at least '
                    . ($needed + 1) . ' columns and this line has ' . count($fields)];

                continue;
            }

            if ($deviceUserId === '') {
                $unparsable[] = ['line' => $line, 'text' => $raw,
                    'why' => 'the device user id column is empty, so there is nobody to attribute it to'];

                continue;
            }

            $instant = self::instant($at, $format);

            if ($instant === null) {
                $unparsable[] = ['line' => $line, 'text' => $raw,
                    'why' => "'{$at}' is not a '{$format}' instant"];

                continue;
            }

            $direction = (string) ($directions[$rawDirection] ?? '');

            if (!in_array($direction, [Punch::IN, Punch::OUT], true)) {
                $unparsable[] = ['line' => $line, 'text' => $raw, 'why' => "the layout does not say "
                    . "what direction '{$rawDirection}' means"];

                continue;
            }

            $punches[] = [
                'device_user_id' => $deviceUserId,
                // The instant as the store keeps one — the same 'Y-m-d H:i:s'
                // wall-clock string `Attendance::record()` writes, with no
                // offset on it. The file's local time IS the business timezone;
                // stamping an offset here would make `SqlStore` convert to UTC
                // and this would be the only punch source in the product whose
                // instants mean something different from the others.
                'punch_at' => $instant->format('Y-m-d H:i:s'),
                'log_date' => $instant->format('Y-m-d'),
                'direction' => $direction,
                'device_sn' => self::field($fields, $columns['device_sn'] ?? null),
                'verify_mode' => self::field($fields, $columns['verify_mode'] ?? null),
                'line' => (string) $line,
            ];
        }

        return ['punches' => $punches, 'unparsable' => $unparsable];
    }

    private static function field(array $fields, mixed $index): string
    {
        return is_int($index) ? (string) ($fields[$index] ?? '') : '';
    }

    /**
     * The declared format or nothing — never `strtotime()`.
     *
     * `strtotime('01/09/2026')` is September in this market and January in the
     * library, and it succeeds either way. So the format is declared, and a
     * value that does not re-format back to itself is refused rather than
     * accepted as whatever the parser made of it.
     */
    private static function instant(string $value, string $format): ?\DateTimeImmutable
    {
        $instant = \DateTimeImmutable::createFromFormat('!' . $format, $value);

        return $instant !== false && $instant->format($format) === $value ? $instant : null;
    }

    /**
     * Enrolment number => employee code, from the data and nowhere else.
     *
     * `employees.device_user_id` is a real ported column, so this is a lookup
     * and not a heuristic. An enrolment number on TWO employees is refused as
     * ambiguous rather than resolved by taking the first: two people share a
     * number when somebody was re-enrolled onto a leaver's slot, and picking
     * either one pays the wrong person for a month.
     *
     * @param string $by 'device_user_id' (the enrolment number) or 'emp_code'
     *                   (some exports carry the employee code as the device
     *                   id — that is DECLARED by the layout, never detected)
     * @return array{codes: array<string, string>, ambiguous: array<string, int>}
     */
    public function directory(string $by = 'device_user_id'): array
    {
        $codes = [];
        $seen = [];

        foreach ($this->store->all('employees') as $employee) {
            $key = $by === 'emp_code'
                ? Employment::code($employee)
                : trim((string) ($employee['device_user_id'] ?? ''));
            $code = Employment::code($employee);

            if ($key === '' || $code === '') {
                // An employee with no code cannot receive a punch: `Attendance`
                // identifies an employee BY its code, so a row written against
                // an empty one is a punch nobody can ever look up.
                continue;
            }

            $seen[$key] = ($seen[$key] ?? 0) + 1;
            $codes[$key] = $code;
        }

        $ambiguous = array_filter($seen, static fn (int $n): bool => $n > 1);

        foreach (array_keys($ambiguous) as $key) {
            unset($codes[$key]);
        }

        return ['codes' => $codes, 'ambiguous' => $ambiguous];
    }

    /**
     * A punch's identity, which is the whole of the idempotency.
     *
     * Built from the DEVICE, the PERSON and the INSTANT — never from the file
     * name, the import time or a row counter, because the same punches arrive
     * again in a re-export with a different name and the import must recognise
     * them. Two punches that agree on all four are the same punch: a device
     * cannot record one person entering twice in the same second.
     */
    public static function externalId(array $punch): string
    {
        return implode(':', ['punch', $punch['device_sn'] === '' ? '-' : $punch['device_sn'],
            $punch['device_user_id'], $punch['punch_at'], $punch['direction']]);
    }

    /**
     * Import one export. Everything it could not do comes back with it.
     *
     * @param array<string, mixed> $layout as returned by `layout()`
     * @return array{parsed: int, imported: int, already_present: int,
     *               unmapped: array<string, int>, ambiguous: array<string, int>,
     *               unparsable: list<array{line: int, text: string, why: string}>,
     *               devices: array<string, int>}
     */
    public function import(string $text, array $layout, bool $dryRun = false): array
    {
        $parsed = self::parse($text, $layout);
        $directory = $this->directory((string) ($layout['identifies'] ?? 'device_user_id'));

        // Every external_id already stored, read ONCE. See the ponytail note at
        // the top for when this stops being the right shape.
        $present = [];

        foreach ($this->store->all(self::COLLECTION) as $row) {
            $existing = (string) ($row['external_id'] ?? '');

            if ($existing !== '') {
                $present[$existing] = true;
            }

            // SAME EMPLOYEE, SAME INSTANT, SAME DIRECTION = the same punch, whatever its source.
            $present['@' . ($row['emp_code'] ?? '') . '|' . substr((string) ($row['punch_at'] ?? ''), 0, 19) . '|' . ($row['direction'] ?? '')] = true;
        }

        $imported = 0;
        $already = 0;
        $unmapped = [];
        $ambiguous = [];
        $devices = [];

        foreach ($parsed['punches'] as $punch) {
            $key = $punch['device_user_id'];
            $devices[$punch['device_sn'] === '' ? '(no serial)' : $punch['device_sn']]
                = ($devices[$punch['device_sn'] === '' ? '(no serial)' : $punch['device_sn']] ?? 0) + 1;

            if (isset($directory['ambiguous'][$key])) {
                $ambiguous[$key] = ($ambiguous[$key] ?? 0) + 1;

                continue;
            }

            $code = $directory['codes'][$key] ?? null;

            if ($code === null) {
                // NOT IMPORTED, NOT GUESSED, NOT DROPPED. Counted by device id
                // so the report says "37 punched 42 times and is nobody".
                $unmapped[$key] = ($unmapped[$key] ?? 0) + 1;

                continue;
            }

            $externalId = self::externalId($punch);

            // Both halves of the idempotency: rows already in the table, and the
            // same punch appearing twice inside one file (devices re-emit the
            // tail of the log when an export is resumed).
            $natural = '@' . $code . '|' . substr((string) $punch['punch_at'], 0, 19) . '|' . $punch['direction'];

            if (isset($present[$externalId]) || isset($present[$natural])) {
                $already++;

                continue;
            }

            $present[$externalId] = true;
            $present[$natural] = true;

            if (!$dryRun) {
                $this->store->put(self::COLLECTION, [
                    'emp_code' => $code,
                    'log_date' => $punch['log_date'],
                    'punch_at' => $punch['punch_at'],
                    'direction' => $punch['direction'],
                    'source' => self::SOURCE,
                    'external_id' => $externalId,
                    'device_sn' => $punch['device_sn'],
                    'device_user_id' => $punch['device_user_id'],
                    'verify_mode' => $punch['verify_mode'],
                ]);
            }

            $imported++;
        }

        ksort($unmapped);
        ksort($ambiguous);

        return [
            'parsed' => count($parsed['punches']),
            'imported' => $imported,
            'already_present' => $already,
            'unmapped' => $unmapped,
            'ambiguous' => $ambiguous,
            'unparsable' => $parsed['unparsable'],
            'devices' => $devices,
        ];
    }
}
