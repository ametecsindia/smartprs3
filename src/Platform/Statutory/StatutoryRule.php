<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Statutory;

/**
 * ONE STATUTORY CALCULATION, AS DATA — Part XVII §XVII.4.3.
 *
 * Three things make this a rule rather than a row of settings:
 *
 *   ITS WAGE BASE IS A COMPONENT FLAG, NEVER A NAME (§XVII.6). SmartPRS 2's PF
 *   base is whichever components a controller happens to list, so a customer who
 *   renames a component changes what it contributes to. Here the rule says
 *   `is_pf_base` and the tenant declares which of their components carry it.
 *
 *   IT CARRIES ITS OWN SOURCE (R-XVII-028 iii). A rule or slab value with no
 *   `source_reference` cannot be signed. SmartPRS 2 has no citation on any of
 *   its ~90 statutory values, so nobody can answer "says who?" about a single
 *   figure it deducts.
 *
 *   ITS ARITHMETIC IS SPX, EVALUATED BY THE ONE ENGINE (R-XVII-122). Not PHP in
 *   a pack, which would be code arriving through a content channel — the thing
 *   the whole three-artefact model exists to prevent.
 */
final readonly class StatutoryRule
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public string $code,
        public string $head,
        public string $calculation,
        public string $wageBaseFlag,
        public string $rounding,
        public string $sourceReference,
        public ?string $eligibility = null,
        public ?string $slabTable = null,
        public array $raw = [],
    ) {
        if (trim($sourceReference) === '') {
            throw new PackRejected([
                "rule '{$code}' has no source_reference. R-XVII-028: the signing service refuses a pack with "
                . 'a value whose authority is not stated, because a rate nobody can attribute is a rate '
                . 'somebody typed — and every one of SmartPRS 2\'s statutory values is exactly that.',
            ]);
        }

        if (trim($wageBaseFlag) === '') {
            throw new PackRejected([
                "rule '{$code}' names no wage-base flag. A base that resolves to nothing deducts nothing and "
                . 'reports success.',
            ]);
        }

        if (str_contains($calculation, '<?php') || str_contains($calculation, 'function ')) {
            throw new PackRejected([
                "rule '{$code}' looks like it carries code rather than an expression. A pack is content; code "
                . 'arriving through the content channel is the deployment this model exists to avoid.',
            ]);
        }
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            code: (string) ($raw['code'] ?? ''),
            head: (string) ($raw['head'] ?? ''),
            calculation: (string) ($raw['calculation'] ?? ''),
            wageBaseFlag: (string) ($raw['wage_base_flag'] ?? ''),
            rounding: (string) ($raw['rounding'] ?? 'half_up'),
            sourceReference: (string) ($raw['source_reference'] ?? ''),
            eligibility: $raw['eligibility'] ?? null,
            slabTable: $raw['slab_table'] ?? null,
            raw: $raw,
        );
    }
}
