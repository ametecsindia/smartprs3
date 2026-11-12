<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

/**
 * THE COMPOSITE TYPES AND THEIR SUB-FIELDS — Part X §X.3.2 #18 and #30.
 *
 * An address and a bank account are one FIELD to the person configuring the
 * system and several columns to the file that carries them. X.3.2 fixes the
 * shape: "one column per sub-field, named `<FIELD> — LINE 1`, `<FIELD> — CITY`,
 * …; round-trips exactly."
 *
 * That last clause is why this class exists rather than a JSON blob in a cell.
 * A composite exported as `{"line1":"…","city":"…"}` is a value a person cannot
 * edit in a spreadsheet, and a person who edits it anyway produces a cell the
 * importer rejects for reasons it cannot explain. One column per sub-field is
 * the shape somebody can actually work in.
 *
 * The sub-field list is code and the per-country RULES are configuration. Which
 * sub-fields exist is structural — an address has a line 1 and a postal code in
 * every country — while which of them are REQUIRED is not: India needs a state
 * and a six-digit PIN, and a jurisdiction that needs neither says so in the
 * field's constraints rather than in a branch here.
 *
 * SmartPRS 2 has no composite type at all: an address is five loose columns
 * whose names differ per screen, and its bank details are three columns with no
 * relationship between them, so a half-entered account is indistinguishable
 * from a complete one.
 */
final class Composite
{
    /**
     * @return list<array{key: string, label: string, type: AuthoringType, required_default: bool}>
     */
    public static function subFields(AuthoringType $type): array
    {
        return match ($type) {
            AuthoringType::Address => [
                ['key' => 'line1', 'label' => 'LINE 1', 'type' => AuthoringType::Text, 'required_default' => true],
                ['key' => 'line2', 'label' => 'LINE 2', 'type' => AuthoringType::Text, 'required_default' => false],
                ['key' => 'landmark', 'label' => 'LANDMARK', 'type' => AuthoringType::Text, 'required_default' => false],
                ['key' => 'city', 'label' => 'CITY', 'type' => AuthoringType::Text, 'required_default' => true],
                ['key' => 'district', 'label' => 'DISTRICT', 'type' => AuthoringType::Text, 'required_default' => false],
                ['key' => 'state', 'label' => 'STATE', 'type' => AuthoringType::Text, 'required_default' => true],
                ['key' => 'postal_code', 'label' => 'POSTAL CODE', 'type' => AuthoringType::PostalCode, 'required_default' => true],
                ['key' => 'country', 'label' => 'COUNTRY', 'type' => AuthoringType::Text, 'required_default' => true],
            ],
            AuthoringType::BankData => [
                // The account number is the only encrypted member, and the only
                // one that gets a blind index. The IFSC is public information
                // printed on a cheque; encrypting it would cost the ability to
                // group by bank for no protection.
                ['key' => 'account_number', 'label' => 'ACCOUNT NUMBER', 'type' => AuthoringType::Text, 'required_default' => true],
                ['key' => 'ifsc', 'label' => 'IFSC', 'type' => AuthoringType::Text, 'required_default' => true],
                ['key' => 'holder_name', 'label' => 'ACCOUNT HOLDER', 'type' => AuthoringType::Text, 'required_default' => true],
                ['key' => 'account_type', 'label' => 'ACCOUNT TYPE', 'type' => AuthoringType::Text, 'required_default' => false],
            ],
            default => throw new \InvalidArgumentException(
                "{$type->value} is not a composite type. Only address and bank_data decompose into columns."
            ),
        };
    }

    public static function isComposite(AuthoringType $type): bool
    {
        return in_array($type, [AuthoringType::Address, AuthoringType::BankData], true);
    }

    /** Which member holds the value that is encrypted and blind-indexed, if any. */
    public static function encryptedMember(AuthoringType $type): ?string
    {
        return $type === AuthoringType::BankData ? 'account_number' : null;
    }

    /**
     * The export columns for one composite field — `<FIELD> — CITY` and so on.
     *
     * @param  array<string, mixed>|null  $value
     * @return array<string, string>  header => cell
     */
    public static function toCells(FieldDefinition $field, ?array $value): array
    {
        $cells = [];

        foreach (self::subFields($field->authoringType) as $sub) {
            $header = $field->columnHeader() . ' — ' . $sub['label'];
            $raw = $value[$sub['key']] ?? null;

            // The masked member exports masked unless the profile carries the
            // sensitive-export permission (X.3.2 #30). That decision belongs to
            // the export profile; what belongs here is that the DEFAULT is
            // masked, so an export written without thinking about it is safe.
            $cells[$header] = $sub['key'] === self::encryptedMember($field->authoringType)
                ? Masking::apply($raw, $field->maskingRule === 'none' ? 'last4' : $field->maskingRule)
                : (string) ($raw ?? '');
        }

        return $cells;
    }

    /**
     * Read a composite back from its columns.
     *
     * @param  array<string, string>  $cells  header => cell, as a spreadsheet row supplies them
     * @return array{ok: bool, value: array<string, mixed>|null, error: string}
     */
    public static function fromCells(FieldDefinition $field, array $cells): array
    {
        $value = [];
        $errors = [];
        $required = (array) ($field->constraints['required_sub_fields'] ?? []);
        $present = 0;

        foreach (self::subFields($field->authoringType) as $sub) {
            $header = $field->columnHeader() . ' — ' . $sub['label'];
            $cell = trim((string) ($cells[$header] ?? ''));

            if ($cell === '') {
                // Required-ness per sub-field is CONFIGURATION (per-country
                // rules), with the structural default as the fallback.
                if (in_array($sub['key'], $required, true)
                    || ($required === [] && $sub['required_default'])
                ) {
                    $errors[] = "'{$header}' is required for this " . $field->authoringType->value . '.';
                }

                continue;
            }

            if (str_starts_with($cell, '••••')) {
                $errors[] = "'{$header}' was exported masked and cannot be imported back. Re-entering a masked "
                    . 'value is how a placeholder becomes somebody\'s account number.';

                continue;
            }

            $value[$sub['key']] = $cell;
            $present++;
        }

        if ($present === 0) {
            // Every column blank is "no value", not "a value with nothing in
            // it". A half-entered composite is an error; an absent one is not.
            return ['ok' => true, 'value' => null, 'error' => ''];
        }

        if ($errors !== []) {
            return ['ok' => false, 'value' => null, 'error' => implode(' ', $errors)];
        }

        return ['ok' => true, 'value' => $value, 'error' => ''];
    }

    /** One-line rendering, for a directory column or a letter token. */
    public static function compose(AuthoringType $type, ?array $value): string
    {
        if ($value === null) {
            return '';
        }

        $parts = [];

        foreach (self::subFields($type) as $sub) {
            if (($value[$sub['key']] ?? '') !== '') {
                $parts[] = (string) $value[$sub['key']];
            }
        }

        return implode(', ', $parts);
    }
}
