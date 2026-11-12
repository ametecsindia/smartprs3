<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

/**
 * EXPORT, RE-IMPORT, AND PROVE NOTHING MOVED — Part XXXII §XXXII.7.
 *
 * The definition-of-done item is *"import and export work where relevant, with
 * NO SILENT DROPS and proven ROUND-TRIP SYMMETRY"*, and both halves name a real
 * failure:
 *
 *   A SILENT DROP is a column the exporter did not write and the importer did
 *   not miss. The customer opens the file, sees their data, re-imports it, and
 *   the field that was not in the file is now empty — not wrong, EMPTY, which
 *   looks like a deliberate clearing and is indistinguishable from one.
 *
 *   ASYMMETRY is a value that survives the round trip in a different form:
 *   `01-04-2026` exported and `2026-01-04` on the way back, a decimal that gains
 *   a place, a boolean written as "Yes" and read as the string "Yes". Each is
 *   silent, each is one cell, and each is discovered months later by somebody
 *   who cannot explain it.
 *
 * So this harness compares FIELD BY FIELD and reports both directions: what was
 * dropped, and what came back different. It deliberately does not compare the
 * two files — two files can differ harmlessly in quoting and column order, and a
 * byte comparison would fail on formatting while missing a changed value.
 *
 * A MASKED CELL IS NOT A DIFFERENCE, IT IS A REFUSAL. A bank account exported
 * masked and re-imported would silently overwrite the real number with dots.
 * `Composite` already refuses that on the way in; this harness asserts the
 * refusal happened rather than treating the masked value as data that changed.
 */
final class RoundTrip
{
    /**
     * @param array<string, mixed> $original the values as stored
     * @param array<string, mixed> $fields the declared schema, keyed by field
     * @param callable(array<string, mixed>): array<string, string> $export
     * @param callable(array<string, string>): array<string, mixed> $import
     * @return array{
     *     symmetric: bool, dropped: list<string>, changed: list<string>,
     *     refused: list<string>, checked: int
     * }
     */
    public static function check(array $original, array $fields, callable $export, callable $import): array
    {
        $exported = $export($original);
        $dropped = [];

        // Direction one: every declared field must appear in the file. A field
        // the exporter skipped is a field the importer will clear.
        foreach (array_keys($fields) as $field) {
            if (!array_key_exists($field, $exported)) {
                $dropped[] = $field;
            }
        }

        $reimported = $import($exported);
        $changed = [];
        $refused = [];

        // Direction two: every value must come back as itself.
        foreach ($original as $field => $value) {
            if (!array_key_exists($field, $reimported)) {
                // Already reported as dropped, unless the importer lost it on
                // its own — which is a different fault with the same symptom.
                if (!in_array($field, $dropped, true)) {
                    $dropped[] = $field;
                }

                continue;
            }

            $back = $reimported[$field];

            // A refusal is the correct outcome for a masked cell, not a
            // difference. Treating it as one would push somebody to "fix" it by
            // accepting the mask, which writes dots over a real account number.
            if (is_string($back) && str_starts_with($back, 'REFUSED:')) {
                $refused[] = $field;

                continue;
            }

            if (!self::same($value, $back)) {
                $changed[] = sprintf('%s: %s → %s', $field, self::show($value), self::show($back));
            }
        }

        return [
            'symmetric' => $dropped === [] && $changed === [],
            'dropped' => $dropped,
            'changed' => $changed,
            'refused' => $refused,
            'checked' => count($original),
        ];
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if (is_object($a) && is_object($b) && method_exists($a, 'equals')) {
            return $a->equals($b);
        }

        if ($a instanceof \DateTimeInterface && $b instanceof \DateTimeInterface) {
            return $a->format('Y-m-d H:i:s') === $b->format('Y-m-d H:i:s');
        }

        // Strict on purpose. `'0'` and `0` and `false` all survive a loose
        // comparison and are three different things in a spreadsheet cell.
        return $a === $b;
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_object($value) && method_exists($value, '__toString') => (string) $value,
            is_object($value) => get_debug_type($value),
            is_array($value) => json_encode($value, JSON_THROW_ON_ERROR),
            default => var_export($value, true),
        };
    }
}
