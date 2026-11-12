<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;

/**
 * THE CANONICAL PARSER AND FORMATTER FOR EVERY TYPE — Part X §X.3.2, stages 3
 * and 6 of the write pipeline, and R-X-026.
 *
 * Four of R-X-025's nine artefacts live here: the server validator, the import
 * parser, the export formatter and the sanitiser. They are one file on purpose.
 * SmartPRS 2 parses dates through THREE different implementations with different
 * fallbacks, which is how the same spreadsheet imports differently depending on
 * which screen you used — and it exports `dra_expiry` as `Y-m-d` under a
 * `(DD-MM-YYYY)` header, which is the same defect from the other side.
 *
 * R-X-026 is absolute and slightly counter-intuitive: **dates in files are
 * DD-MM-YYYY and the header says so.** Import additionally accepts ISO and Excel
 * serial numbers, because a file arrives however it arrives; export emits
 * exactly what its header declares, because a file leaves however we say it
 * does. Being liberal in one direction and strict in the other is what makes a
 * round trip possible at all.
 *
 * Nothing here rounds silently, guesses a format from the shape of the value, or
 * turns an unparseable cell into null. A value that cannot be represented
 * exactly is an error naming the expected format — `'Contract'` silently
 * becoming Permanent is one of the defects this part exists to end.
 */
final class ValueCoercion
{
    /** R-X-026. The only date format a file ever carries out of SmartPRS 3. */
    public const FILE_DATE_FORMAT = 'd-m-Y';

    public const FILE_DATETIME_FORMAT = 'd-m-Y H:i';

    /** Excel's day zero, for the serial numbers a spreadsheet hands over instead of text. */
    private const EXCEL_EPOCH = '1899-12-30';

    /**
     * Parse a value supplied by a surface into its storage representation.
     *
     * @param  array<string, mixed>  $valueSetMembers  the allowed codes, for a code type
     * @return array{ok: bool, value: mixed, error: string}
     */
    public static function coerce(FieldDefinition $field, mixed $raw, array $valueSetMembers = []): array
    {
        $type = $field->authoringType;

        if (is_string($raw)) {
            // Stage 6, applied before validation rather than after: a value is
            // validated in the form it will be STORED in, or the validator is
            // checking something the database never sees. SmartPRS 2 skips
            // `stripHtmlDeep()` on three of its nine paths and renders the
            // results via innerHTML "with inconsistent escaping" by its own
            // codebase's admission.
            $raw = self::sanitise($raw);
        }

        try {
            $value = self::parse($field, $type, $raw, $valueSetMembers);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'value' => null, 'error' => $e->getMessage()];
        }

        $error = self::validate($field, $value);

        return $error === null
            ? ['ok' => true, 'value' => $value, 'error' => '']
            : ['ok' => false, 'value' => null, 'error' => $error];
    }

    /**
     * @param  array<string, mixed>  $valueSetMembers
     */
    private static function parse(
        FieldDefinition $field,
        AuthoringType $type,
        mixed $raw,
        array $valueSetMembers,
    ): mixed {
        return match ($type->storageType()) {
            StorageType::Text, StorageType::LongText, StorageType::EncryptedText => self::text($field, $raw),
            StorageType::Number, StorageType::Reference => self::integer($field, $raw),
            // A file-bearing field stores a REFERENCE to the stored object, not
            // the bytes. X.3.2 #19-22: the export emits a signed URL or the file
            // inside an archive, and the import accepts a relative path within
            // one — so what crosses this boundary is an id, and the content is
            // checked by FileValidation at upload rather than here.
            StorageType::FileRef => self::integer($field, $raw),
            StorageType::Decimal => self::decimal($field, $raw),
            StorageType::Money => self::money($field, $raw),
            StorageType::Date => self::date($field, $raw),
            StorageType::DateTime => self::dateTime($field, $raw),
            StorageType::Bool => self::boolean($field, $raw),
            StorageType::Code => self::code($field, $raw, $valueSetMembers),
            StorageType::CodeList => self::codeList($field, $raw, $valueSetMembers),
            // A composite arrives as an array of members, already split out of
            // its columns by Composite::fromCells(). Each member is validated
            // here so a bank account cannot carry an IFSC that fails its shape.
            StorageType::Composite, StorageType::AddressRef => self::composite($field, $raw),
            default => throw new \InvalidArgumentException(
                "Field '{$field->fieldKey}' has storage type {$type->storageType()->value}, which has no "
                . 'canonical parser in this build. R-X-025 makes such a type unavailable for selection, so '
                . 'reaching here means a definition was constructed around the check.'
            ),
        };
    }

    private static function text(FieldDefinition $field, mixed $raw): string
    {
        if (is_int($raw) || is_float($raw)) {
            // A spreadsheet hands over an employee code of 001234 as the number
            // 1234. Accepting it as text is right; accepting it after the
            // leading zeros are gone is not, so the caller is told rather than
            // handed a value that looks fine.
            throw new \InvalidArgumentException(
                "'{$field->fieldKey}' expects text and was given the number {$raw}. If this came from a "
                . 'spreadsheet, format the column as text: a code with leading zeros loses them silently '
                . 'otherwise.'
            );
        }

        if (!is_string($raw)) {
            throw new \InvalidArgumentException("'{$field->fieldKey}' expects text, got " . get_debug_type($raw) . '.');
        }

        $value = trim($raw);

        if ($field->authoringType === AuthoringType::Email) {
            $value = strtolower($value);

            if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException("'{$value}' is not an email address.");
            }
        }

        if ($field->authoringType === AuthoringType::Url) {
            if (preg_match('#^https?://[^\s/@]+\.[^\s/]+#i', $value) !== 1) {
                throw new \InvalidArgumentException(
                    "'{$value}' is not an http or https URL. Other schemes are refused rather than rendered."
                );
            }

            if (str_contains(parse_url($value, PHP_URL_USER) ?? '', '')
                && parse_url($value, PHP_URL_USER) !== null
            ) {
                throw new \InvalidArgumentException('A URL field does not carry credentials (X.3.2 #11).');
            }
        }

        if ($field->authoringType === AuthoringType::GovernmentIdentifier) {
            return self::identifier($field, Encryption::normalise($value));
        }

        if ($field->authoringType === AuthoringType::Time
            && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) !== 1
        ) {
            throw new \InvalidArgumentException(
                "'{$value}' is not a time of day. A time field is HH:MM, 24-hour, and is a time of day rather "
                . 'than an instant — an instant is a datetime and carries a zone.'
            );
        }

        return $value;
    }

    private static function integer(FieldDefinition $field, mixed $raw): int
    {
        if (is_int($raw)) {
            return $raw;
        }

        if (is_string($raw) && preg_match('/^-?\d+$/', trim($raw)) === 1) {
            return (int) trim($raw);
        }

        throw new \InvalidArgumentException(
            "'{$field->fieldKey}' expects a whole number, got " . self::show($raw)
            . '. Digits only: a thousands separator is a display choice and is not accepted as input.'
        );
    }

    /** Kept as an exact decimal STRING. A float here is Law 7's whole point. */
    private static function decimal(FieldDefinition $field, mixed $raw): string
    {
        if (is_float($raw)) {
            throw new \InvalidArgumentException(
                "'{$field->fieldKey}' was given a float. A decimal is carried as an exact string, because "
                . '0.1 + 0.2 is not 0.3 and a payslip built on that cannot be reconciled (Law 7).'
            );
        }

        $text = is_int($raw) ? (string) $raw : trim((string) $raw);

        if (preg_match('/^-?\d+(\.\d+)?$/', $text) !== 1) {
            throw new \InvalidArgumentException("'{$field->fieldKey}' expects a decimal, got " . self::show($raw) . '.');
        }

        $scale = (int) ($field->constraints['decimal_scale'] ?? 4);
        $fraction = strpos($text, '.') === false ? '' : substr($text, strpos($text, '.') + 1);

        if (strlen($fraction) > $scale) {
            throw new \InvalidArgumentException(
                "'{$text}' needs {$fraction} places and '{$field->fieldKey}' declares a scale of {$scale}. "
                . 'The value is REFUSED rather than rounded: rounding somebody\'s input without telling them '
                . 'is how a figure that was checked stops matching the figure that was stored.'
            );
        }

        return $text;
    }

    private static function money(FieldDefinition $field, mixed $raw): Money
    {
        if ($raw instanceof Money) {
            return $raw;
        }

        if (is_float($raw)) {
            throw new \InvalidArgumentException(
                "'{$field->fieldKey}' is money and was given a float (Law 7). Supply an exact decimal string."
            );
        }

        return Money::of(is_int($raw) ? (string) $raw : trim((string) $raw));
    }

    private static function date(FieldDefinition $field, mixed $raw): \DateTimeImmutable
    {
        if ($raw instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($raw)->setTime(0, 0);
        }

        $text = trim((string) $raw);

        // An Excel serial. Accepted on import (R-X-026) because a spreadsheet
        // hands one over whether or not anybody wanted it; never emitted.
        if (preg_match('/^\d{1,5}$/', $text) === 1) {
            return (new \DateTimeImmutable(self::EXCEL_EPOCH))->modify('+' . (int) $text . ' days');
        }

        foreach ([self::FILE_DATE_FORMAT, 'Y-m-d', 'd/m/Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $text);

            if ($parsed !== false && $parsed->format($format) === $text) {
                return $parsed;
            }
        }

        throw new \InvalidArgumentException(
            "'{$text}' is not a date for '{$field->fieldKey}'. Files carry DD-MM-YYYY and the header says so "
            . '(R-X-026); ISO and Excel serial numbers are also accepted on the way in. Nothing is guessed: '
            . '03-04-2026 is 3 April, never 4 March.'
        );
    }

    private static function dateTime(FieldDefinition $field, mixed $raw): \DateTimeImmutable
    {
        if ($raw instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($raw);
        }

        $text = trim((string) $raw);

        foreach ([self::FILE_DATETIME_FORMAT, 'Y-m-d H:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d H:i'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $text);

            if ($parsed !== false) {
                return $parsed;
            }
        }

        throw new \InvalidArgumentException("'{$text}' is not a date and time for '{$field->fieldKey}'.");
    }

    private static function boolean(FieldDefinition $field, mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        $text = strtolower(trim((string) $raw));

        return match ($text) {
            'yes', 'y', 'true', '1' => true,
            'no', 'n', 'false', '0' => false,
            default => throw new \InvalidArgumentException(
                "'{$raw}' is not a yes or a no for '{$field->fieldKey}'. A blank cell is NOT false: a tri-state "
                . 'answer is a code field with a third value, never a nullable boolean (X.3.2 #12).'
            ),
        };
    }

    /** @param array<string, mixed> $members code => label */
    private static function code(FieldDefinition $field, mixed $raw, array $members): string
    {
        $text = trim((string) $raw);

        if ($members === []) {
            throw new \InvalidArgumentException(
                "'{$field->fieldKey}' is a code field and its value set '{$field->valueSetKey}' resolved to no "
                . 'members. An empty allow-list accepts nothing — it does not accept everything.'
            );
        }

        if (array_key_exists($text, $members)) {
            return $text;
        }

        // The LABEL is accepted too: X.3.2 #13 says the export cell carries the
        // label and the code is accepted, so the reverse has to hold or a file
        // this system produced would not import back into it.
        foreach ($members as $code => $label) {
            if (strcasecmp((string) $label, $text) === 0) {
                return (string) $code;
            }
        }

        throw new \InvalidArgumentException(
            "'{$text}' is not a member of '{$field->valueSetKey}'. Allowed: "
            . implode(', ', array_map(
                static fn (string $c, mixed $l): string => "{$c} ({$l})",
                array_keys($members),
                array_values($members),
            ))
            . '. An unknown code is a row error and never a silent skip (R-X-027).'
        );
    }

    /** @param array<string, mixed> $members */
    private static function codeList(FieldDefinition $field, mixed $raw, array $members): array
    {
        $items = is_array($raw) ? $raw : array_filter(array_map('trim', explode('|', (string) $raw)), 'strlen');
        $codes = [];

        foreach ($items as $item) {
            $code = self::code($field, $item, $members);

            if (!in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        $min = $field->constraints['min_selected'] ?? null;
        $max = $field->constraints['max_selected'] ?? null;

        if ($min !== null && count($codes) < (int) $min) {
            throw new \InvalidArgumentException("'{$field->fieldKey}' needs at least {$min} selection(s).");
        }

        if ($max !== null && count($codes) > (int) $max) {
            throw new \InvalidArgumentException("'{$field->fieldKey}' allows at most {$max} selection(s).");
        }

        return $codes;
    }

    /**
     * A composite: its members, each checked, and the declared checksum applied
     * to the one that carries an identifier.
     *
     * @return array<string, mixed>
     */
    private static function composite(FieldDefinition $field, mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException(
                "'{$field->fieldKey}' is a {$field->authoringType->value} and arrives as its members, not as "
                . 'one string. A composite squeezed into a single cell is a value nobody can edit.'
            );
        }

        $known = array_column(Composite::subFields($field->authoringType), 'key');
        $value = [];

        foreach ($raw as $key => $member) {
            if (!in_array((string) $key, $known, true)) {
                // R-X-031's principle at the member level: an unknown member is
                // refused, never dropped. A typo'd sub-field that silently
                // vanishes is a bank account with no IFSC.
                throw new \InvalidArgumentException(
                    "'{$key}' is not part of a {$field->authoringType->value}. Its members are: "
                    . implode(', ', $known) . '.'
                );
            }

            $value[(string) $key] = is_string($member) ? self::sanitise($member) : $member;
        }

        foreach ((array) ($field->constraints['member_patterns'] ?? []) as $member => $pattern) {
            if (isset($value[$member]) && preg_match((string) $pattern, (string) $value[$member]) !== 1) {
                throw new \InvalidArgumentException(
                    "'{$value[$member]}' does not match the pattern declared for the {$member} of "
                    . "'{$field->fieldKey}'. The pattern is configuration, so a country with a different "
                    . 'shape declares its own rather than needing a code change.'
                );
            }
        }

        return $value;
    }

    /**
     * A government identifier: pattern, then the declared checksum.
     *
     * WHICH checksum applies is configuration on the field; the algorithms are
     * arithmetic and live in Checksum. A number that passes is a number that was
     * not MISTYPED — it is not proof the identifier exists, and treating it as
     * proof is how a system stops asking for the document.
     */
    private static function identifier(FieldDefinition $field, string $value): string
    {
        $algorithm = (string) ($field->constraints['checksum'] ?? 'none');
        $failure = Checksum::verify($value, $algorithm);

        if ($failure !== null) {
            throw new \InvalidArgumentException(
                "'{$field->fieldKey}' failed its {$algorithm} check: {$failure}"
            );
        }

        return $value;
    }

    /**
     * Every declared validator on the definition (X.2.4), applied after parsing.
     *
     * Returns the FIRST failure of this field, and the caller collects failures
     * across fields — R-X-014 requires every field's error together, and
     * `formatErrors()` short-circuiting on the first one is the SmartPRS 2
     * behaviour that makes fixing an import a twelve-round trip.
     */
    private static function validate(FieldDefinition $field, mixed $value): ?string
    {
        $c = $field->constraints;

        if (is_string($value)) {
            $length = mb_strlen($value);

            if (isset($c['min_length']) && $length < (int) $c['min_length']) {
                return "'{$field->fieldKey}' needs at least {$c['min_length']} characters and has {$length}.";
            }

            if (isset($c['max_length']) && $length > (int) $c['max_length']) {
                return "'{$field->fieldKey}' allows at most {$c['max_length']} characters and has {$length}.";
            }

            if (isset($c['regex_pattern']) && preg_match((string) $c['regex_pattern'], $value) !== 1) {
                return (string) ($c['regex_error_message']
                    ?? "'{$value}' does not match the pattern declared for '{$field->fieldKey}'.");
            }
        }

        // Bounds are compared as EXACT rationals, not floats (Law 7). A
        // declared maximum of 9999.99 against a value of 9999.99 must not
        // depend on which of the two the binary representation rounded up.
        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1)) {
            $numeric = Rational::fromDecimalString((string) $value);

            if (isset($c['min_value'])
                && $numeric->compareTo(Rational::fromDecimalString((string) $c['min_value'])) < 0
            ) {
                return "'{$field->fieldKey}' has a declared minimum of {$c['min_value']}.";
            }

            if (isset($c['max_value'])
                && $numeric->compareTo(Rational::fromDecimalString((string) $c['max_value'])) > 0
            ) {
                return "'{$field->fieldKey}' has a declared maximum of {$c['max_value']}.";
            }
        }

        return null;
    }

    /**
     * Stage 6 — Part X §X.5.2.
     *
     * Strips markup and control characters, normalises to NFC and collapses
     * whitespace. Formula-injection neutralisation is NOT applied here: it
     * belongs to the export formatter, because `=SUM(A1)` is a legitimate thing
     * for somebody to type into a notes field and a dangerous thing to write
     * into a spreadsheet cell. Neutralising at input would corrupt the stored
     * value to protect a format it is not in yet.
     */
    public static function sanitise(string $value): string
    {
        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;

        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        }

        return trim(preg_replace('/[ \t]+/u', ' ', $value) ?? $value);
    }

    /**
     * The export cell — R-X-026 and the "Import/export representation" column of
     * X.3.2. Whatever this emits, the header declares, and `fromCell()` reads
     * back to the same value.
     *
     * @param array<string, mixed> $valueSetMembers
     */
    public static function toCell(FieldDefinition $field, mixed $value, array $valueSetMembers = []): string
    {
        if ($value === null) {
            return '';
        }

        $cell = match ($field->authoringType->storageType()) {
            StorageType::Date => $value instanceof \DateTimeInterface
                ? $value->format(self::FILE_DATE_FORMAT)
                : (string) $value,
            StorageType::DateTime => $value instanceof \DateTimeInterface
                ? $value->format(self::FILE_DATETIME_FORMAT)
                : (string) $value,
            StorageType::Bool => $value ? 'Yes' : 'No',
            StorageType::Money => $value instanceof Money ? $value->toDecimalString() : (string) $value,
            // The LABEL, per X.3.2 #13 — and `fromCell()` accepts it back.
            StorageType::Code => (string) ($valueSetMembers[(string) $value] ?? $value),
            StorageType::CodeList => implode('|', array_map(
                static fn (string $code): string => (string) ($valueSetMembers[$code] ?? $code),
                (array) $value,
            )),
            default => (string) $value,
        };

        return self::neutraliseFormula($cell);
    }

    /**
     * Read an export cell back. The inverse of `toCell()`, and asserted as such
     * per field by the Phase 3 exit gate — a formatter with no parser is a
     * one-way trip, and "we can export it" is not the same claim as "we can get
     * it back".
     *
     * @param  array<string, mixed>  $valueSetMembers
     * @return array{ok: bool, value: mixed, error: string}
     */
    public static function fromCell(FieldDefinition $field, string $cell, array $valueSetMembers = []): array
    {
        $cell = self::denutraliseFormula($cell);

        if (trim($cell) === '') {
            return ['ok' => true, 'value' => null, 'error' => ''];
        }

        return self::coerce($field, $cell, $valueSetMembers);
    }

    /**
     * A cell beginning `=`, `+`, `-` or `@` is a formula to a spreadsheet, and a
     * spreadsheet opens exported files. The leading apostrophe is Excel's own
     * "this is text" marker and survives a round trip through it.
     */
    private static function neutraliseFormula(string $cell): string
    {
        return $cell !== '' && str_contains("=+-@", $cell[0]) ? "'" . $cell : $cell;
    }

    private static function denutraliseFormula(string $cell): string
    {
        return str_starts_with($cell, "'") ? substr($cell, 1) : $cell;
    }

    private static function show(mixed $raw): string
    {
        return is_scalar($raw) ? "'" . (string) $raw . "'" : get_debug_type($raw);
    }
}
