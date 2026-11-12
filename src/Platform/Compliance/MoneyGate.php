<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Compliance;

/**
 * THE GENERALISED COMPLIANCE-TO-MONEY GATE — Part XXI §XXI.6, Part XVIII §XVIII.9.
 *
 * A payment or an action is bound to compliance item types by CONFIGURATION —
 * item type × payment type × severity — so "no commission without a valid DRA
 * certificate" is a binding a customer declares, not a branch somebody wrote. A
 * customer who wants incentives gated on a firearms licence gets it without a
 * release.
 *
 * Three properties, and the third is the one that usually gets built wrong:
 *
 *   IT CALLS THE ONE SERVICE. There is no query here and no second definition of
 *   valid. R-XXI-050 lists the gate as a consumer, and a gate with its own
 *   validity rule is how SmartPRS 2 ended up requiring `verified` for DRA and
 *   accepting `submitted` for PCC, asymmetrically and undocumented.
 *
 *   IT RESOLVES AS AT THE DECISION'S OWN DATE (R-XXI-055). A commission approved
 *   for September asks about September. Resolving on "today" is how a payment
 *   passes in October against a certificate that had expired in September, and
 *   the re-run in an audit disagrees with the payment that was made.
 *
 *   NOT ASSESSED BLOCKS BY DEFAULT. The gate does not treat "we could not check"
 *   as "it is fine". That default may be relaxed per type to `warn`, and never to
 *   `off` for a regulatory type — the ItemType constructor refuses it, so the
 *   gate does not have to trust that nobody tried.
 */
final class MoneyGate
{
    /**
     * @param array<string, list<string>> $bindings payment/action type => item type codes it requires
     */
    public function __construct(
        private readonly ComplianceService $compliance,
        private readonly array $bindings,
        private readonly array $types,
    ) {
    }

    /**
     * @return array{allowed: bool, blocked: list<string>, warnings: list<string>, why: list<string>}
     */
    public function evaluate(string $actionType, string $subjectRef, \DateTimeImmutable $decisionDate): array
    {
        $required = $this->bindings[$actionType] ?? null;

        if ($required === null) {
            // An UNBOUND action type is not an open door. A gate asked about
            // something nobody configured must say so rather than answering yes,
            // because a typo in an action code would otherwise disable the gate
            // silently and permanently.
            return [
                'allowed' => false,
                'blocked' => [$actionType],
                'warnings' => [],
                'why' => ["no compliance binding is declared for action type '{$actionType}', so this gate "
                    . 'has never been configured for it. An unconfigured gate that allows is a gate a typo '
                    . 'in an action code switches off'],
            ];
        }

        $blocked = [];
        $warnings = [];
        $why = [];

        foreach ($this->compliance->statusMany([$subjectRef], $required, $decisionDate) as $status) {
            $type = $this->types[$status->itemTypeCode];

            if (!$status->isApplicable()) {
                $why[] = $status->explain();

                continue;
            }

            if ($status->isNotAssessed()) {
                match ($type->onNotAssessed) {
                    ItemType::BLOCK => $blocked[] = $status->itemTypeCode,
                    ItemType::WARN => $warnings[] = $status->itemTypeCode,
                    default => null,
                };

                $why[] = $status->explain();

                continue;
            }

            if ($status->isValid) {
                $why[] = $status->explain();

                continue;
            }

            if ($status->waived) {
                // A waiver lets the money through and does NOT make the record
                // valid. Both facts are recorded, because the auditor's question
                // is not "did it pass?" but "who decided it could?".
                $warnings[] = $status->itemTypeCode . ' (waived, not valid)';
                $why[] = $status->explain();

                continue;
            }

            $blocked[] = $status->itemTypeCode;
            $why[] = $status->explain();
        }

        return [
            'allowed' => $blocked === [],
            'blocked' => $blocked,
            'warnings' => $warnings,
            'why' => $why,
        ];
    }
}
