<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

/**
 * THE REMAINING FOUR OF R-X-025's NINE — Part X §X.3.2.
 *
 * The client validator, the template cell specification, the API schema
 * fragment and the diff renderer. They are one class because they are one idea:
 * each is a PROJECTION of the field definition into the shape some consumer
 * needs, and every one of them derives from the definition rather than being
 * maintained beside it.
 *
 * That is the whole argument of Part X. SmartPRS 2 keeps four surfaces in step
 * with its field registry and nine surfaces out of step with it, so a field is
 * required on the form and optional in the API, has one regex in JavaScript and
 * a different one in PHP, and exports under a header that describes a format it
 * does not emit. Every one of those is two descriptions of one field, kept in
 * step by hand.
 *
 * So: one definition, five projections (these four plus the server validator in
 * ValueCoercion), and no way to change one without changing the source of all
 * of them.
 *
 * `tools/phase3/exit-gate.php` asserts that each of the nine resolves for every
 * available type, which is what stops a type from claiming completeness it does
 * not have — the exact over-claim this class was written to repair.
 */
final class FieldArtefacts
{
    /**
     * The client-side validation spec.
     *
     * Emitted as data for the browser to enforce, and deliberately a SUBSET of
     * what the server enforces: the client's job is to tell somebody about a
     * problem before they submit, not to be the check. A validator that exists
     * only on the client is not a validator, and every rule here is also
     * applied by ValueCoercion on the way in.
     *
     * @return array<string, mixed>
     */
    public static function clientValidator(FieldDefinition $field, EffectiveRule $rule): array
    {
        $c = $field->constraints;
        $storage = $field->authoringType->storageType();

        $spec = [
            'field_key' => $field->fieldKey,
            'input' => self::widget($field->authoringType),
            'required' => $rule->requiredness !== Requiredness::Never,
            'readonly' => !$rule->visibility->allowsWrite(),
            // R-X-031: a read-only field is rendered disabled AND its value is
            // rejected server-side. The client is told both, so it cannot
            // "helpfully" submit it anyway.
            'reject_if_submitted' => $rule->visibility === Visibility::Read,
        ];

        foreach (['min_length', 'max_length', 'min_value', 'max_value', 'min_selected', 'max_selected'] as $key) {
            if (isset($c[$key])) {
                $spec[$key] = $c[$key];
            }
        }

        if (isset($c['regex_pattern'])) {
            // The delimiters are stripped: a JavaScript RegExp does not take
            // PHP's, and shipping them is how a pattern silently never matches.
            $spec['pattern'] = trim((string) $c['regex_pattern'], '/#~');
            $spec['pattern_message'] = $c['regex_error_message'] ?? null;
        }

        if ($storage === StorageType::Date) {
            $spec['format'] = ValueCoercion::FILE_DATE_FORMAT;
        }

        if ($storage === StorageType::Decimal) {
            $spec['scale'] = $c['decimal_scale'] ?? 4;
        }

        if ($field->valueSetKey !== null) {
            $spec['value_set'] = $field->valueSetKey;
        }

        return $spec;
    }

    /**
     * The spreadsheet cell specification — the import template and the export
     * column, which are the SAME description on purpose.
     *
     * R-X-026 lives here: the header names the format, and the format it names
     * is the one the export formatter emits. SmartPRS 2 writes `(DD-MM-YYYY)`
     * into three headers and emits `Y-m-d` under them.
     *
     * @return array{header: string, format: string, note: string, data_validation: ?array<string, mixed>}
     */
    public static function templateCell(FieldDefinition $field): array
    {
        $storage = $field->authoringType->storageType();

        [$format, $note] = match ($storage) {
            StorageType::Date => [
                ValueCoercion::FILE_DATE_FORMAT,
                'DD-MM-YYYY. ISO and Excel serial numbers are also accepted on import.',
            ],
            StorageType::DateTime => [
                ValueCoercion::FILE_DATETIME_FORMAT,
                'DD-MM-YYYY HH:MM, in the tenant timezone.',
            ],
            StorageType::Bool => ['Yes/No', 'Yes or No. A blank cell is not No — leave it out to leave it alone.'],
            StorageType::Money => ['0.00', 'A plain decimal. No currency symbol and no thousands separator.'],
            StorageType::Decimal => [
                '0.' . str_repeat('0', (int) ($field->constraints['decimal_scale'] ?? 4)),
                'A plain decimal. A value needing more places than this is refused, not rounded.',
            ],
            StorageType::Code => ['text', 'The label, or the code. Both are accepted; the export writes the label.'],
            StorageType::CodeList => ['text', 'Labels separated by a pipe: Hindi|Telugu|English.'],
            StorageType::Reference => ['text', 'The stable CODE, never a name (R-X-027).'],
            default => ['text', ''],
        };

        return [
            // The single derivation. The import header, the export column and
            // this are one string because they are one thing.
            'header' => $field->columnHeader() . ($format === 'text' ? '' : " ({$format})"),
            'format' => $format,
            'note' => $note === '' ? $field->description : $note,
            // A dropdown ships its allowed values IN the template, so the file a
            // person fills in cannot contain a value the import will reject.
            'data_validation' => $field->valueSetKey === null
                ? null
                : ['kind' => 'list', 'value_set' => $field->valueSetKey],
        ];
    }

    /**
     * The `/api/v3` schema fragment for one field on one surface.
     *
     * Carries the surface's own answer rather than the field's, because the API
     * is one of the thirteen and its rules resolve like any other's. A schema
     * generated from the definition alone would advertise fields the caller may
     * not see — which is a disclosure, not a documentation bug.
     *
     * @return array<string, mixed>
     */
    public static function apiSchema(FieldDefinition $field, EffectiveRule $rule): array
    {
        $storage = $field->authoringType->storageType();

        $schema = [
            'type' => match ($storage) {
                StorageType::Number, StorageType::Reference => 'integer',
                StorageType::Bool => 'boolean',
                StorageType::CodeList => 'array',
                // Money and Decimal are STRINGS in JSON, never numbers: a JSON
                // number is a double in most parsers, and Law 7 does not
                // survive the client library.
                default => 'string',
            },
            'description' => $field->description,
            'readOnly' => !$rule->visibility->allowsWrite(),
        ];

        if ($storage === StorageType::Money) {
            $schema['format'] = 'decimal';
            $schema['x-money'] = ['currency_from' => 'tenant', 'exact' => true];
        }

        if ($storage === StorageType::Date) {
            $schema['format'] = 'date';
            $schema['x-note'] = 'ISO YYYY-MM-DD over the API; DD-MM-YYYY in files (R-X-026).';
        }

        if ($storage === StorageType::DateTime) {
            $schema['format'] = 'date-time';
        }

        if (isset($field->constraints['max_length'])) {
            $schema['maxLength'] = (int) $field->constraints['max_length'];
        }

        if ($field->valueSetKey !== null) {
            $schema['x-value-set'] = $field->valueSetKey;
        }

        if ($field->piiClass !== 'none') {
            // Declared so a client knows the value it received may be masked,
            // rather than discovering it by comparing two responses.
            $schema['x-pii-class'] = $field->piiClass;
            $schema['x-masking'] = $rule->masking;
        }

        return $schema;
    }

    /**
     * How a change to this field is shown to a reviewer — an approver reading a
     * maker-checker request, or somebody reading the audit trail.
     *
     * Masked on both sides for a PII field. A diff that renders the old and new
     * values in full to prove that a masked field changed has unmasked it, and
     * the approval screen is exactly where that would happen.
     */
    public static function diff(FieldDefinition $field, mixed $old, mixed $new, ?string $custom = null): string
    {
        $rule = $field->maskingRule;
        $before = Masking::apply($old, $rule, $custom);
        $after = Masking::apply($new, $rule, $custom);

        if ($before === $after && $rule !== 'none') {
            // Two different values that mask identically. Saying "•••• → ••••"
            // reads as "nothing changed", which is the opposite of true.
            return $field->label . ': changed (both values are masked under '
                . $rule . ', so the difference is not shown here)';
        }

        return $field->label . ': ' . ($before === '' ? '(empty)' : $before)
            . ' → ' . ($after === '' ? '(cleared)' : $after);
    }

    /** The input widget a surface renders, from the authoring type (X.3.2). */
    private static function widget(AuthoringType $type): string
    {
        return match ($type) {
            AuthoringType::LongText => 'textarea',
            AuthoringType::Number, AuthoringType::Decimal, AuthoringType::Currency => 'number',
            AuthoringType::Date => 'date',
            AuthoringType::DateTime => 'datetime-local',
            AuthoringType::Time => 'time',
            AuthoringType::Email => 'email',
            AuthoringType::Url => 'url',
            AuthoringType::Mobile => 'tel',
            AuthoringType::Boolean, AuthoringType::Checkbox => 'switch',
            AuthoringType::Dropdown, AuthoringType::Country, AuthoringType::State,
            AuthoringType::District, AuthoringType::CustomReferenceList => 'select',
            AuthoringType::Radio => 'radio',
            AuthoringType::MultiSelect, AuthoringType::CheckboxGroup => 'multi-select',
            AuthoringType::EmployeeLookup, AuthoringType::ManagerLookup => 'employee-typeahead',
            AuthoringType::OrganisationLookup => 'org-unit-picker',
            default => 'text',
        };
    }
}
