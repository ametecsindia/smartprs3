<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Statutory;

use SmartPRS\Platform\Spx\Compiled;
use SmartPRS\Platform\Spx\Context;
use SmartPRS\Platform\Spx\Evaluator;
use SmartPRS\Platform\Spx\SymbolTable;
use SmartPRS\Platform\Spx\Types\Type;
use SmartPRS\Platform\Spx\Value;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\RoundingMode;

/**
 * THE ONE IMPLEMENTATION OF EVERY STATUTORY CALCULATION — Part XVII §XVII.10,
 * R-XVII-122.
 *
 * "The payslip, the register, the payslip PDF, the challan, the return, the ECR,
 * the simulator, the preview, the impact analysis, ESS, the mobile app, every
 * export and every report call the same evaluation and render what it returns."
 *
 * So there is exactly one `compute()` in this codebase, and the Phase 6 exit gate
 * greps for a second. SmartPRS 2's evidence is what makes that a CI check rather
 * than a code-review note: its PT is computed in four places that disagree, and
 * the only behavioural test for it is a hand-copied duplicate of the production
 * code that silently stopped testing production the first time somebody forgot
 * to re-copy it.
 *
 * Two structural refusals here, both of which SmartPRS 2 answers with a zero:
 *
 *   NO PACK, NO NUMBER. An unresolvable statute does not deduct nothing — it
 *   throws. A zero deduction that should have been ₹1,800 looks exactly like a
 *   correct ₹0 on a payslip, and the employee finds out at assessment.
 *
 *   NO SNAPSHOT, NO FIGURE (R-XVII-118). A statutory amount without a complete
 *   snapshot reference is a programming error that throws, because a figure
 *   nobody can re-derive in 2031 is not a figure, it is a claim.
 */
final class StatutoryEngine
{
    public function __construct(
        private readonly PackStore $store,
        private readonly Applicability $applicability,
    ) {
    }

    /**
     * @param array<string, Money> $componentAmounts component code => amount this period
     * @param array<string, array<string, bool>> $componentFlags component code => its declared flags
     * @throws StatutoryUnresolved when no pack, no rule, or no applicability answers
     */
    public function compute(
        string $head,
        string $employmentId,
        string $establishmentCode,
        \DateTimeImmutable $asOf,
        array $componentAmounts,
        array $componentFlags,
    ): ContributionRow {
        $binding = $this->applicability->resolve($head, $establishmentCode, $asOf);

        if (!$binding->applies) {
            return ContributionRow::notApplicable($head, $employmentId, $binding);
        }

        $pack = $this->store->resolve($binding->packKey, $asOf);

        if ($pack === null) {
            throw new StatutoryUnresolved(
                "{$head} applies to {$establishmentCode} from {$binding->reason}, and no pack "
                . "'{$binding->packKey}' is in force on " . $asOf->format('Y-m-d') . '. A statute that '
                . 'applies and cannot be computed is a refusal, never a zero: a zero deduction that should '
                . 'have been a real one looks exactly like a correct one on the payslip, and the employee '
                . 'finds out at assessment.'
            );
        }

        $rule = $this->ruleFor($pack, $head);

        // The wage base is the sum of components carrying the DECLARED FLAG.
        // Never a list of names (§XVII.6): SmartPRS 2's PF base is whichever
        // components a controller happens to enumerate, so renaming a component
        // silently changes what it contributes to.
        $base = Money::zero();
        $composition = [];

        foreach ($componentAmounts as $code => $amount) {
            if (($componentFlags[$code][$rule->wageBaseFlag] ?? false) === true) {
                $base = $base->plus($amount);
                $composition[] = $code;
            }
        }

        $values = (array) ($pack->content['values'] ?? []);
        $context = new Context(
            evaluationDate: $asOf,
            symbols: $this->symbolsFor($base, $rule->wageBaseFlag, $values, $asOf),
            slabs: $pack->slabTables(),
        );

        $compiled = Compiled::parse($rule->calculation, SymbolTable::catalogue());
        $result = Evaluator::evaluate($compiled, $context);

        if ($result->isError() || $result->isNull()) {
            throw new StatutoryUnresolved(
                "Rule '{$rule->code}' of {$pack->id()} did not produce a value ("
                . ($result->isNull() ? 'null' : $result->errorMessage() ?? 'error')
                . '). An unevaluable statutory rule is a refusal; the alternative is a payslip carrying a '
                . 'number nobody can account for.'
            );
        }

        // SPX money is carried in MINOR units, so the rule's result is already
        // paise and the only thing left is the pack's declared rounding. There is
        // no scaling factor here on purpose: a units conversion between the
        // engine and the payslip is a hundredfold error waiting for the one
        // component nobody tested.
        $amount = Money::ofMinor($result->rational()->toInt($this->rounding($rule->rounding)));

        return new ContributionRow(
            head: $head,
            employmentId: $employmentId,
            wageBase: $base,
            baseComposition: $composition,
            wageBaseFlag: $rule->wageBaseFlag,
            amount: $amount,
            ruleCode: $rule->code,
            packKey: $pack->manifest->packKey(),
            packVersion: $pack->manifest->version(),
            packContentHash: $pack->manifest->contentHash(),
            packKeyId: $pack->manifest->keyId(),
            packEffectiveFrom: $pack->manifest->effectiveFrom(),
            resolvedValues: $values,
            rounding: $rule->rounding,
            applicability: $binding,
            sourceReference: $rule->sourceReference,
            synthetic: $pack->manifest->isSynthetic(),
        );
    }

    private function ruleFor(RulePack $pack, string $head): StatutoryRule
    {
        foreach ($pack->rules() as $rule) {
            if ($rule->head === $head) {
                return $rule;
            }
        }

        throw new StatutoryUnresolved(
            "Pack {$pack->id()} carries no rule for head '{$head}', so the statute is bound and "
            . 'uncomputable. Falling through to zero here is the shape of the defect this engine exists to '
            . 'remove.'
        );
    }

    /**
     * The pack's values, bound into the vocabulary SPX already declares.
     *
     * `salary.wage_base('<flag>')` and `statutory.value('<CODE>')` are existing
     * keyed symbols, so a pack supplies values INTO the catalogue rather than
     * inventing a namespace of its own. That is what keeps "no threshold is ever
     * a literal in a rule" enforceable: a rule that wants a number has exactly
     * one way to ask for one, and it names the code rather than the figure.
     *
     * @param array<string, array{type: string, value: string}> $values
     */
    private function symbolsFor(
        Money $base,
        string $flag,
        array $values,
        \DateTimeImmutable $asOf,
    ): array {
        $symbols = [
            "salary.wage_base('{$flag}')" => Value::money($base),
            'period.end' => Value::date($asOf),
        ];

        foreach ($values as $code => $declared) {
            // The pack declares the TYPE of each value it carries (§XVII.4.3).
            // Inferring it from the way the number was written is how a rate of
            // "12" becomes ₹12.00 in a build somebody upgrades on a Friday.
            $symbols["statutory.value('{$code}')"] = match ($declared['type'] ?? '') {
                'percentage' => Value::quantityOfType(
                    Type::percentage(),
                    Rational::fromDecimalString((string) $declared['value']),
                ),
                'money' => Value::money(Money::of((string) $declared['value'])),
                'days' => Value::quantityOfType(
                    Type::days(),
                    Rational::fromDecimalString((string) $declared['value']),
                ),
                default => throw new StatutoryUnresolved(
                    "Pack value '{$code}' declares type '" . ($declared['type'] ?? '(none)')
                    . "', which this build cannot bind. A statutory value whose type is guessed is a rate "
                    . 'that can become an amount.'
                ),
            };
        }

        return $symbols;
    }

    private function rounding(string $mode): RoundingMode
    {
        return match ($mode) {
            'half_up' => RoundingMode::HalfUp,
            'half_down' => RoundingMode::HalfDown,
            'up' => RoundingMode::Up,
            'down' => RoundingMode::Down,
            default => throw new StatutoryUnresolved(
                "'{$mode}' is not a rounding mode this build knows. A pack whose rounding cannot be honoured "
                . 'must not be approximated with a default — rounding IS the statutory answer for PF.'
            ),
        };
    }
}
