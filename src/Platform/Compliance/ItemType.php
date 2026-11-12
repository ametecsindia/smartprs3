<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Compliance;

/**
 * A COMPLIANCE ITEM TYPE, AS DATA — Part XXI §XXI.3.1, R-XXI-023.
 *
 * DRA, PCC, BGV, code of conduct, agent authorisation, POSH, training and any
 * customer-invented type are instances of THIS ONE MODEL. A tenant administrator
 * adds a type with expiry tracking, an alert ladder, an evidence requirement and
 * a money gate, and it appears in the register, the score, the gate and the audit
 * pack with no deployment. SmartPRS 2's item type list is a closed set of PHP
 * literals, so a customer wanting a firearms licence tracked waits for a release.
 *
 * Three things are FIXED rather than configurable, each because making it
 * configurable is how a compliance system quietly stops checking:
 *
 *   `missingRecordIsValid` is false and has no setter (R-XXI-041). Absence of a
 *   record is never a compliant state. SmartPRS 2 awards 20 points when the
 *   `complaints` table does not exist and 0 when `dra_certs` does not — the fix
 *   is to make absence impossible, not to pick which direction to guess in.
 *
 *   `onNotAssessed` defaults to `block`, and a `regulatory` evidence class may
 *   never set it to `off`. A gate that waves through what it could not assess is
 *   a gate that is off on exactly the records that needed it.
 *
 *   NO BEHAVIOUR COMES FROM THE NAME (R-XXI-024). The code is the identity;
 *   renaming "DRA Certificate" to "IIBF Recovery Agent Certificate" changes
 *   nothing. SmartPRS 2 locks ESI contribution periods by `stripos($k, 'esi')`
 *   on a DISPLAY LABEL, so renaming a deduction silently loses the lock.
 */
final readonly class ItemType
{
    public const BLOCK = 'block';
    public const WARN = 'warn';
    public const OFF = 'off';

    public const REGULATORY = 'regulatory';

    /** Absence of a record is never compliance. Fixed, not configurable. */
    public bool $missingRecordIsValid;

    /**
     * @param list<string> $validStateCodes THE single definition of "valid" for this type
     * @param list<string> $subjectKinds
     * @param array<string, string> $partialCredit state => fraction, as a decimal string
     */
    public function __construct(
        public string $code,
        public string $name,
        public array $validStateCodes,
        public string $appliesExpression,
        public string $appliesReasonTemplate,
        public array $subjectKinds = ['person'],
        public string $evidenceClass = 'operational',
        public string $onNotAssessed = self::BLOCK,
        public int $scoreWeight = 1,
        public array $partialCredit = [],
        public string $origin = 'tenant',
        public string $versionId = 'v1',
    ) {
        $this->missingRecordIsValid = false;

        if ($validStateCodes === []) {
            throw new \InvalidArgumentException(
                "Item type '{$code}' declares no valid states, so nothing can ever satisfy it and every "
                . 'subject fails forever. An empty definition of valid is not a strict policy, it is a '
                . 'broken one.'
            );
        }

        foreach ($validStateCodes as $state) {
            ComplianceState::assertKnown($state);
        }

        if (in_array(ComplianceState::NOT_APPLICABLE, $validStateCodes, true)) {
            throw new \InvalidArgumentException(
                "Item type '{$code}' lists not_applicable as a VALID state. Not applicable is an exclusion "
                . 'from scoring, not a pass — counting it as one is how a register of subjects nobody has '
                . 'to check reads as a compliant workforce.'
            );
        }

        if (!in_array($onNotAssessed, [self::BLOCK, self::WARN, self::OFF], true)) {
            throw new \InvalidArgumentException("'{$onNotAssessed}' is not an on_not_assessed setting.");
        }

        if ($evidenceClass === self::REGULATORY && $onNotAssessed === self::OFF) {
            throw new \InvalidArgumentException(
                "Item type '{$code}' is a REGULATORY type and sets on_not_assessed to 'off'. R-XXI-054 "
                . 'forbids it: a regulatory gate that waves through what it could not assess is a gate that '
                . 'is off on exactly the records that needed it.'
            );
        }

        if (trim($appliesExpression) === '') {
            throw new \InvalidArgumentException(
                "Item type '{$code}' has no applicability expression. A type that applies to everyone is a "
                . 'statement somebody made; a type with no expression is a question nobody asked, and the '
                . 'two produce different rows.'
            );
        }
    }

    public function treatsAsValid(string $state): bool
    {
        return in_array($state, $this->validStateCodes, true);
    }

    /** Fraction of the weight a state earns — default: valid earns all, everything else nothing. */
    public function creditFor(string $state): string
    {
        return $this->partialCredit[$state] ?? ($this->treatsAsValid($state) ? '1' : '0');
    }
}
