<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Statutory;

use SmartPRS\Platform\Support\Money;

/**
 * THE PACK'S OWN EVIDENCE, RE-RUN ON THE CUSTOMER'S BUILD — Part XVII
 * R-XVII-008, R-XVII-125.
 *
 * A pack carries executable golden cases inside the signed envelope, and the
 * installation re-runs them locally on import, BEFORE activation, against its
 * own platform build. "A pack whose golden cases fail on the customer's build is
 * rejected, not warned about — because that divergence means the customer's
 * engine does not compute what Ametecs verified."
 *
 * Two properties do the work, and both are refusals of how SmartPRS 2 tests:
 *
 *   THE CASES RUN THROUGH THE REAL ENGINE (R-XVII-125). This class holds no
 *   arithmetic of its own; it calls `StatutoryEngine::compute()`, the same method
 *   the payslip calls. `tests/manual/pt_extract.php` in SmartPRS 2 is a
 *   hand-copied duplicate of production code carrying an instruction to
 *   re-extract on every change, so the only behavioural PT test silently stopped
 *   testing production the first time somebody forgot.
 *
 *   THE CASES ARE INSIDE THE SIGNATURE. They cannot be edited to make a pack
 *   pass, because editing them changes the content hash and the pack stops
 *   verifying. Evidence that the person shipping the content can quietly adjust
 *   is not evidence.
 */
final class GoldenReplay
{
    public function __construct(private readonly StatutoryEngine $engine)
    {
    }

    /**
     * @return list<string> empty means the build computes what Ametecs verified
     */
    public function run(RulePack $pack): array
    {
        $failures = [];
        $cases = $pack->goldenCases();

        if ($cases === []) {
            // Not a pass. A pack with no cases has shipped no evidence, and an
            // empty suite reports success louder than a failing one.
            return ['the pack carries NO golden cases, so importing it proves only that it parses '
                . '(R-XVII-008 requires executable evidence inside the signed envelope)'];
        }

        foreach ($cases as $case) {
            $id = (string) ($case['id'] ?? '(unnamed)');

            try {
                $row = $this->engine->compute(
                    head: (string) $case['head'],
                    employmentId: 'golden:' . $id,
                    establishmentCode: (string) $case['establishment'],
                    asOf: new \DateTimeImmutable((string) $case['as_of']),
                    componentAmounts: array_map(
                        static fn (string $amount): Money => Money::of($amount),
                        (array) $case['components'],
                    ),
                    componentFlags: (array) $case['component_flags'],
                );
            } catch (\Throwable $e) {
                $failures[] = "{$id}: threw " . $e::class . ' — ' . $e->getMessage();

                continue;
            }

            if ($row->amount->toDecimalString() !== (string) $case['expect_amount']) {
                $failures[] = "{$id}: computed {$row->amount->toDecimalString()}, the pack's own signed "
                    . "evidence says {$case['expect_amount']} — this build does not compute what was verified";
            }

            if (isset($case['expect_base'])
                && $row->wageBase->toDecimalString() !== (string) $case['expect_base']
            ) {
                $failures[] = "{$id}: wage base is {$row->wageBase->toDecimalString()}, expected "
                    . "{$case['expect_base']} — the flag selected a different set of components than the "
                    . 'rule was verified against';
            }

            if (!$row->isComplete()) {
                $failures[] = "{$id}: the figure carries an incomplete snapshot, so it could not be "
                    . 're-derived offline (R-XVII-119)';
            }
        }

        return $failures;
    }
}
