<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

use SmartPRS\Platform\Spx\Types\Type;

/**
 * THE AUTHORING TYPE CATALOGUE — Part X §X.3, R-X-025.
 *
 * Thirty-two authoring types over sixteen storage types, because what an
 * administrator PICKS and what the database HOLDS are different questions: a
 * dropdown, a radio group and a custom reference list are one storage type and
 * three widgets, and pretending otherwise is how a codebase ends up with three
 * validators for one thing.
 *
 * The rule that makes this table worth having is R-X-025: every type must carry
 * NINE artefacts — a server validator, a client validator, an import parser, an
 * export formatter, a template cell specification, an API schema fragment, an
 * SPX type, a mask renderer and a diff renderer — and **a type without all nine
 * is not available for selection**.
 *
 * So `ARTEFACTS` below records which of the nine each type actually has in THIS
 * build, and `available()` is the list an administrator may choose from. Nothing
 * is quietly half-built: a type that is declared and incomplete reports as
 * incomplete, by name, and `tools/phase3/exit-gate.php` prints the list. The
 * alternative — shipping thirty-two names of which nineteen work — is the
 * failure this codebase keeps declining to repeat: the difference between "not
 * built yet" and "built" must be legible without running it.
 *
 * The file-bearing types (photo, file, document, signature) and the composite
 * ones (address, bank data) are declared here and NOT available: they need
 * content-sniffed MIME validation, a virus-scan verdict and a document-type
 * catalogue, none of which exist yet. Declaring them now is what stops a
 * fourteenth type being invented for "employee photo" later.
 */
enum AuthoringType: string
{
    case Text = 'text';
    case LongText = 'long_text';
    case Number = 'number';
    case Decimal = 'decimal';
    case Currency = 'currency';
    case Date = 'date';
    case DateTime = 'datetime';
    case Time = 'time';
    case Email = 'email';
    case Mobile = 'mobile';
    case Url = 'url';
    case Boolean = 'boolean';
    case Dropdown = 'dropdown';
    case MultiSelect = 'multi_select';
    case Radio = 'radio';
    case Checkbox = 'checkbox';
    case CheckboxGroup = 'checkbox_group';
    case Address = 'address';
    case Photo = 'photo';
    case File = 'file';
    case Document = 'document';
    case Signature = 'signature';
    case EmployeeLookup = 'employee_lookup';
    case ManagerLookup = 'manager_lookup';
    case OrganisationLookup = 'organisation_lookup';
    case Country = 'country';
    case State = 'state';
    case District = 'district';
    case PostalCode = 'postal_code';
    case BankData = 'bank_data';
    case GovernmentIdentifier = 'government_identifier';
    case CustomReferenceList = 'custom_reference_list';

    /** R-X-025's nine. A type missing any one of them is not selectable. */
    public const ARTEFACT_NAMES = [
        'server_validator',
        'client_validator',
        'import_parser',
        'export_formatter',
        'template_cell',
        'api_schema',
        'spx_type',
        'mask_renderer',
        'diff_renderer',
    ];

    /**
     * Which of the nine each type carries in this build.
     *
     * `true` means every one. A list means those and no others, so the gap is
     * readable without running anything.
     *
     * @var array<string, true|list<string>>
     */
    private const ARTEFACTS = [
        'text' => true,
        'long_text' => true,
        'number' => true,
        'decimal' => true,
        'currency' => true,
        'date' => true,
        'datetime' => true,
        'time' => true,
        'email' => true,
        'mobile' => true,
        'url' => true,
        'boolean' => true,
        'dropdown' => true,
        'multi_select' => true,
        'radio' => true,
        'checkbox' => true,
        'checkbox_group' => true,
        'employee_lookup' => true,
        'manager_lookup' => true,
        'organisation_lookup' => true,
        'country' => true,
        'state' => true,
        'district' => true,
        'postal_code' => true,
        'custom_reference_list' => true,

        // Composite and encrypted, and complete as of the Encryption/Checksum/
        // Composite work: an address and a bank account decompose into one
        // column per sub-field and round-trip exactly (X.3.2 #18, #30), and a
        // government identifier carries a declared pattern and a declared
        // checksum with the algorithms in Checksum.
        'address' => true,
        'bank_data' => true,
        'government_identifier' => true,

        // The file-bearing four. Complete as of Entity\FileValidation: MIME is
        // sniffed from CONTENT and the extension must additionally agree
        // (R-X-028), the size bounds are enforced, and nothing is servable until
        // its scan verdict is `clean` — pending and error are both NO, because a
        // scanner outage that released files would be the outage worth causing.
        //
        // Their import/export representation is the file REFERENCE, not the
        // bytes: an export emits a signed URL or the file inside a ZIP, and an
        // import accepts a relative path inside an uploaded archive. That is why
        // the round trip below is over the reference and not the content.
        'photo' => true,
        'file' => true,
        'document' => true,
        'signature' => true,
    ];

    /** The storage type the value actually sits in (X.3.1). */
    public function storageType(): StorageType
    {
        return match ($this) {
            self::Text, self::Email, self::Url, self::PostalCode, self::Time => StorageType::Text,
            self::LongText => StorageType::LongText,
            self::Number => StorageType::Number,
            self::Decimal => StorageType::Decimal,
            self::Currency => StorageType::Money,
            self::Date => StorageType::Date,
            self::DateTime => StorageType::DateTime,
            self::Mobile, self::GovernmentIdentifier => StorageType::EncryptedText,
            self::Boolean, self::Checkbox => StorageType::Bool,
            self::Dropdown, self::Radio, self::Country, self::State,
            self::District, self::CustomReferenceList => StorageType::Code,
            self::MultiSelect, self::CheckboxGroup => StorageType::CodeList,
            self::Address => StorageType::AddressRef,
            self::Photo, self::File, self::Document, self::Signature => StorageType::FileRef,
            self::EmployeeLookup, self::ManagerLookup, self::OrganisationLookup => StorageType::Reference,
            self::BankData => StorageType::Composite,
        };
    }

    /**
     * The SPX type the field reads as inside a rule or a formula.
     *
     * This is why `employee.custom('key')` can be type-checked at publish: a
     * custom field is not an untyped bag, it is a declared type the expression
     * compiler already understands.
     */
    public function spxType(): Type
    {
        return match ($this->storageType()) {
            StorageType::Number => Type::number(),
            StorageType::Decimal => Type::number(),
            StorageType::Money => Type::money(),
            StorageType::Date => Type::date(),
            StorageType::DateTime => Type::dateTime(),
            StorageType::Bool => Type::boolean(),
            StorageType::Code => Type::code(),
            StorageType::CodeList => Type::listOf(Type::code()),
            StorageType::Reference => Type::number(),
            default => Type::text(),
        };
    }

    /** @return list<string> the R-X-025 artefacts this type does NOT have yet */
    public function missingArtefacts(): array
    {
        // Every current entry happens to be `true` (all nine types are complete),
        // so PHPStan narrows $has to the literal `true` from the constant's actual
        // values and calls the branch below dead. It is not: ARTEFACTS's declared
        // shape (see its @var) is `true|list<string>` on purpose, for the day a
        // type is added that is genuinely missing artefacts, and this branch is
        // what tells that case apart from "everything present" before calling
        // array_diff() on it.
        /** @var true|list<string> $has */
        $has = self::ARTEFACTS[$this->value] ?? [];

        if ($has === true) {
            return [];
        }

        return array_values(array_diff(self::ARTEFACT_NAMES, $has));
    }

    /** R-X-025: a type without all nine is not available for selection. */
    public function isAvailable(): bool
    {
        return $this->missingArtefacts() === [];
    }

    /** @return list<self> what an administrator may actually pick today */
    public static function available(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $t): bool => $t->isAvailable()));
    }

    /** @return list<self> declared, incomplete, and reported as such */
    public static function incomplete(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $t): bool => !$t->isAvailable()));
    }
}
