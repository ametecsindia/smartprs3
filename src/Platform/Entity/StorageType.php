<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

/**
 * The sixteen storage types — Part X §X.3.1, R-X-005.
 *
 * "Exactly one `v_*` column is populated per `entity_field_values` row,
 * determined by `data_type_code` and enforced by a CHECK constraint." So the
 * mapping from a type to its column is not a convention, it is the thing the
 * constraint is written from — and `column()` below is where the migration and
 * the writer both read it, so they cannot drift.
 *
 * Changing a field's type is a new dated version plus a migration-run
 * conversion. It is never an in-place reinterpretation of the bytes already
 * there, which is what "just change the column" means and why SmartPRS 2's
 * untyped runtime columns hold four representations of the same date.
 */
enum StorageType: string
{
    case Text = 'text';
    case LongText = 'long_text';
    case EncryptedText = 'encrypted_text';
    case Number = 'number';
    case Decimal = 'decimal';
    case Money = 'money';
    case Date = 'date';
    case DateTime = 'datetime';
    case Bool = 'bool';
    case Code = 'code';
    case CodeList = 'code_list';
    case Json = 'json';
    case Reference = 'reference';
    case FileRef = 'file_ref';
    case AddressRef = 'address_ref';
    case Composite = 'composite';

    /** The one `v_*` column this type populates. */
    public function column(): string
    {
        return match ($this) {
            self::Text, self::EncryptedText => 'v_text',
            self::LongText => 'v_long_text',
            self::Number, self::Reference, self::FileRef, self::AddressRef => 'v_number',
            self::Decimal, self::Money => 'v_decimal',
            self::Date => 'v_date',
            self::DateTime => 'v_datetime',
            self::Bool => 'v_bool',
            self::Code => 'v_code',
            self::CodeList, self::Json, self::Composite => 'v_json',
        };
    }

    /** @return list<string> every column the CHECK constraint has to know about */
    public static function columns(): array
    {
        $columns = [];

        foreach (self::cases() as $type) {
            $columns[$type->column()] = true;
        }

        return array_keys($columns);
    }
}
