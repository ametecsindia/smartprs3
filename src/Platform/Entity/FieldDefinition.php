<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Scope;

/**
 * ONE EMPLOYEE FIELD, AS DATA — Part X §X.2, R-X-001 and R-X-002.
 *
 * "No employee field attribute may be expressed as a PHP literal, a Blade
 * template, a JavaScript constant, or a database ENUM." SmartPRS 2's field
 * registry is a PHP constant identical for every tenant, which is why adding one
 * field there costs six code edits at minimum and thirteen to sixteen for parity
 * across its surfaces — and why four of its surfaces derive from the registry
 * while nine do not.
 *
 * A field definition is a configuration artefact in the sense of spine S4: it
 * carries a scope and an effective range, it is versioned, and publishing
 * supersedes rather than overwrites. That is not ceremony. SmartPRS 2's registry
 * has no version, no date and no scope, so a change to it is retroactive to all
 * history on all tenants at once.
 *
 * Everything checked in the constructor is checked here rather than at publish
 * for the reason Rule and Formula give: an object that exists but could not be
 * published is a state somebody eventually evaluates.
 */
final readonly class FieldDefinition
{
    /** R-X-007. Immutable, and the join to SPX, templates, import headers and the API. */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{1,58}$/';

    /** X.2.5. Where a value must be unique, if anywhere. */
    public const UNIQUENESS_SCOPES = ['none', 'tenant', 'legal_entity', 'global'];

    /** X.2.6. What kind of personal data this is, which drives masking and retention. */
    public const PII_CLASSES = ['none', 'identifying', 'sensitive', 'restricted'];

    /** X.6.1. Whether a change is only audited, or becomes a dated row. */
    public const HISTORY_MODES = ['audit_only', 'dated'];

    /** X.2.6 / X.4.2. How a value is shown to somebody not entitled to all of it. */
    public const MASKING_RULES = ['none', 'last4', 'first_last', 'full', 'domain_only', 'custom'];

    /**
     * R-X-009. What a tenant may NOT change about a system field, named here so
     * the refusal cites something rather than being a hard-coded `if`.
     */
    public const LOCKABLE = [
        'field_key', 'authoring_type', 'storage_kind', 'uniqueness_scope', 'is_system',
    ];

    /**
     * @param list<string>          $lockedAttributes  R-X-009, for a system field
     * @param array<string, mixed>  $constraints       min/max/length/regex, per X.2.1
     */
    public function __construct(
        public string $fieldKey,
        public string $label,
        public string $description,
        public AuthoringType $authoringType,
        public StorageKind $storageKind,
        public string $groupKey,
        public int $displayOrder,
        public Scope $scope,
        public EffectiveRange $range,
        public int $versionNo,
        public string $versionId,
        public Visibility $defaultVisibility = Visibility::Hidden,
        public Requiredness $defaultRequiredness = Requiredness::Never,
        public string $uniquenessScope = 'none',
        public string $piiClass = 'none',
        public string $historyMode = 'audit_only',
        public string $maskingRule = 'none',
        public bool $isSystem = false,
        public bool $isEncrypted = false,
        public ?string $valueSetKey = null,
        public ?string $defaultExpression = null,
        public ?string $helpText = null,
        public array $lockedAttributes = [],
        public array $constraints = [],
        public ?string $supersedesFieldKey = null,
    ) {
        if (preg_match(self::KEY_PATTERN, $fieldKey) !== 1) {
            throw new \InvalidArgumentException(
                "'{$fieldKey}' is not a field key. R-X-007: lower-case, starting with a letter, 2 to 59 "
                . 'characters, because the key is the join to the SPX symbol, the template token, the import '
                . 'header, the export column and the API property — and a key that varies by surface is five '
                . 'keys.'
            );
        }

        if (trim($label) === '') {
            throw new \InvalidArgumentException("Field '{$fieldKey}' has no label. An unlabelled field on a form is a question nobody can answer.");
        }

        if (trim($description) === '') {
            throw new \InvalidArgumentException(
                "Field '{$fieldKey}' has no description. The Configuration Studio shows it to the administrator "
                . 'deciding whether to make this field mandatory, and "what is this field for" is exactly what '
                . 'SmartPRS 2 cannot answer about its own registry.'
            );
        }

        if (!$authoringType->isAvailable()) {
            throw new \InvalidArgumentException(
                "Authoring type '{$authoringType->value}' is declared but not available for selection "
                . '(R-X-025): it is missing ' . implode(', ', $authoringType->missingArtefacts())
                . '. A type without all nine artefacts renders on some surfaces and not others, which is the '
                . 'defect the rule exists to prevent.'
            );
        }

        if (!in_array($uniquenessScope, self::UNIQUENESS_SCOPES, true)) {
            throw new \InvalidArgumentException("'{$uniquenessScope}' is not a uniqueness scope (X.2.5).");
        }

        if (!in_array($piiClass, self::PII_CLASSES, true)) {
            throw new \InvalidArgumentException("'{$piiClass}' is not a PII class (X.2.6).");
        }

        if (!in_array($historyMode, self::HISTORY_MODES, true)) {
            throw new \InvalidArgumentException("'{$historyMode}' is not a history mode (X.6.1).");
        }

        if (!in_array($maskingRule, self::MASKING_RULES, true)) {
            throw new \InvalidArgumentException("'{$maskingRule}' is not a masking rule (X.2.6).");
        }

        foreach ($lockedAttributes as $attribute) {
            if (!in_array($attribute, self::LOCKABLE, true)) {
                throw new \InvalidArgumentException(
                    "'{$attribute}' is not a lockable attribute. The platform locks " . implode(', ', self::LOCKABLE)
                    . ', and a lock on anything else would be a rule a tenant could not read.'
                );
            }
        }

        // X.2.6: a field classed sensitive or restricted and NOT masked is a
        // field that will appear in full in a directory export somebody emails.
        if (in_array($piiClass, ['sensitive', 'restricted'], true) && $maskingRule === 'none') {
            throw new \InvalidArgumentException(
                "Field '{$fieldKey}' is classed {$piiClass} and declares no masking rule. The class is what "
                . 'decides who sees the value; a class with no rule attached is a label, and the value still '
                . 'renders in full to everybody.'
            );
        }

        // A dropdown with no value set is a free-text field wearing a select.
        if ($authoringType->storageType() === StorageType::Code && $valueSetKey === null) {
            throw new \InvalidArgumentException(
                "Field '{$fieldKey}' is a {$authoringType->value} and names no value set. Its allowed values "
                . 'are configuration (X.2.1), so a code field without one has no allowed values to check '
                . 'against and accepts anything.'
            );
        }
    }

    /** R-X-009: what a tenant may not change about this field. */
    public function isLocked(string $attribute): bool
    {
        return $this->isSystem && in_array($attribute, $this->lockedAttributes, true);
    }

    /** X.6.1: does a change to this field become a dated row, or only an audit entry? */
    public function isDated(): bool
    {
        return $this->historyMode === 'dated';
    }

    /** X.2.5: is there a database index behind this, or nothing? */
    public function isUnique(): bool
    {
        return $this->uniquenessScope !== 'none';
    }

    /** The SPX symbol a rule or formula reads this field through (R-X-007). */
    public function spxSymbol(): string
    {
        return "employee.custom('{$this->fieldKey}')";
    }

    /** The import header and export column identity — the same string, on purpose. */
    public function columnHeader(): string
    {
        return strtoupper(str_replace('_', ' ', $this->fieldKey));
    }

    public function describe(): string
    {
        return "{$this->fieldKey} ({$this->authoringType->value}, {$this->storageKind->value}, v{$this->versionNo})";
    }
}
