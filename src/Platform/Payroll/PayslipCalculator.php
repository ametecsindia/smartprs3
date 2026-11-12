<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Payroll;

use SmartPRS\Platform\Formula\Calculator;
use SmartPRS\Platform\Formula\FormulaResult;
use SmartPRS\Platform\Formula\Graph;
use SmartPRS\Platform\Spx\Context;
use SmartPRS\Platform\Spx\Types\TypeKind;
use SmartPRS\Platform\Spx\Value;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\RoundingMode;

/**
 * GROSS AND DAYS IN, A PAYSLIP OUT — Part XIV §XIV.5, stages 9 to 22.
 *
 * Pure, and every figure is exact integer paise. The two decisions that make the
 * golden matrix pass are worth stating, because both are places where the
 * obvious implementation is wrong:
 *
 *   PRORATE THE GROSS, THEN SPLIT. Not split, then prorate each part. The second
 *   order rounds three times and the parts no longer sum to the prorated gross —
 *   ₹50,000 at 22/24 becomes 22,916.67 + 9,166.67 + 13,750.00 = 45,833.34, a
 *   paisa more than the 45,833.33 the employee was owed. The payslip then prints
 *   an equation an employee can see is wrong, and RC-01 fails.
 *
 *   THE BALANCE COMPONENT ABSORBS THE RESIDUE. Whatever is left after the
 *   percentage components is the balance, computed by subtraction rather than by
 *   its own rate, so the lines sum to the gross by construction. This is Part
 *   VIII's `ALLOCATE` invariant applied to a salary structure, and it is the one
 *   money split SmartPRS 2 gets right — generalised here rather than reproduced
 *   in one controller.
 *
 * A component that does not prorate is untouched by either. Professional tax is
 * a flat monthly amount, and prorating it would under-deduct against a return
 * that expects the full figure. Whether a component prorates is a FLAG, which is
 * why this method has no special case for it.
 *
 * ORDER OF ARITHMETIC IS NOT ORDER ON THE PAGE. Amounts are computed in
 * TOPOLOGICAL order — a component a formula reads is computed before the formula
 * that reads it, whatever number either carries — and the lines are then emitted
 * in the structure's own declared order. Part VIII §7 states the rule and names
 * what it costs to conflate them: SmartPRS 2 sorts by seq ASC and hard-codes
 * five ordered passes, so a customer who reorders their payslip changes their
 * arithmetic. Here `sequence` governs presentation and the tie-break, nothing
 * else. A cycle is refused by the graph — never resolved by evaluation order,
 * never broken by a depth limit — before a single amount is computed.
 *
 * THE FORMULA BASIS IS COMPUTED BY THE FORMULA ENGINE, not here. This class
 * assembles the context (the gross, the days, the components already computed)
 * and hands it to `Formula\Calculator`, which owns rounding at the declared
 * boundary, exact rationals in between, and the trace. There is no second
 * evaluator in payroll and there must never be one: two engines are two answers.
 */
final class PayslipCalculator
{
    /** @var list<SalaryComponent> topological — the order amounts are computed in */
    private readonly array $order;

    /** Null when the structure declares no formula component; nothing to evaluate. */
    private readonly ?Calculator $calculator;

    /**
     * @param list<SalaryComponent> $components in the order they appear on the slip
     */
    public function __construct(private readonly array $components)
    {
        $formulas = [];

        foreach ($this->components as $component) {
            if ($component->formula !== null) {
                $formulas[$component->formula->code] = $component->formula;   // PT's formula id is PT_ (SalaryComponent::compile)
            }
        }

        $this->calculator = $formulas === [] ? null : new Calculator($formulas);
        // Built here, in the constructor, so that a structure whose components
        // depend on one another in a circle is refused when it is PUBLISHED —
        // `Structure::publish()` builds this object and throws the reason away —
        // rather than on the close, in the dark, by whichever component the
        // declared order happened to reach first.
        $this->order = $this->topologicalOrder();
    }

    /**
     * @param array<string, Money> $recoveries component code => amount to recover this period
     */
    public function calculate(
        string $employmentId,
        string $periodCode,
        Money $contractedGross,
        Rational $payableDays,
        int $periodDays,
        array $recoveries = [],
    ): Payslip {
        if ($periodDays < 1) {
            throw new \InvalidArgumentException(
                'A period of zero days has no proration denominator, and dividing by it would produce a '
                . 'payslip nobody could explain.'
            );
        }

        // A FRACTION OF DAYS, NOT A COUNT. It was `int` until a rule engine
        // started classifying days, at which point half a day became a thing
        // the product can say — and an int parameter can only round it, in one
        // direction, silently, on the number that multiplies somebody's gross.
        // Rational keeps it exact: 17 and a half days is 35/2, never 17.5,
        // because 17.5 is a float and law 7 says money is exact.
        if ($payableDays->isNegative() || $payableDays->compareTo(Rational::of($periodDays)) > 0) {
            throw new \InvalidArgumentException(
                "{$payableDays->toDecimalString()} payable day(s) in a {$periodDays}-day period is not a fraction of a "
                . 'month. '
                . 'A figure outside the period is a settlement question, not a proration.'
            );
        }

        // ZERO PAYABLE DAYS — full-period absence, nothing employed to price at
        // all. A flat, non-prorating component (Professional Tax and the like,
        // see SalaryComponent's own doc-comment) is deducted at its whole
        // monthly figure whatever the gross is, by design — that is what "does
        // not prorate" means for a month somebody partly worked. But a month
        // with NOTHING payable in it is not a proration case: there is no
        // gross to compute ANY component against, prorating or not, so every
        // line — earning and deduction alike — is correctly zero rather than a
        // flat charge applied as if the month had happened.
        //
        // This is not the clamp Payslip's own doc-comment argues against.
        // Clamping hides a REAL shortfall — deductions that exceeded a gross
        // that was actually earned — behind a zero, which is exactly the
        // SmartPRS 2 defect RC-03 exists to catch. Here there is no shortfall
        // to hide: nothing was earned and nothing is owed, so zero is the
        // honest total, not a cosmetic one.
        if ($payableDays->isZero()) {
            return new Payslip($employmentId, $periodCode, [], sprintf(
                '0 of %d day(s) payable this period — no employment activity to price, so no earning or '
                . 'deduction line applies and net pay is %s.',
                $periodDays,
                Money::zero()->format(),
            ));
        }

        // Stage one: prorate the GROSS, once, exactly.
        $factor = $payableDays->dividedBy(Rational::of($periodDays));
        $proratedGross = $contractedGross->multipliedBy($factor, RoundingMode::HalfUp);

        $trace = [
            "Contracted gross {$contractedGross->format()}, {$payableDays->toDecimalString()}/{$periodDays} payable"
            . " → {$proratedGross->format()} (prorated ONCE, on the gross, before any split)",
        ];

        // As paid: every component against this month's actual figures.
        $paid = $this->pass(
            $contractedGross,
            $proratedGross,
            $recoveries,
            $this->contextFor($periodCode, $payableDays, $periodDays),
            "{$employmentId}|{$periodCode}|as-paid",
        );

        // The same structure on a full month — the RC-08 term, which is what
        // proration is measured against. A full month is its own answer, so the
        // second pass is skipped rather than computed to the same numbers.
        $full = $payableDays->equals(Rational::of($periodDays)) ? $paid : $this->pass(
            $contractedGross,
            $contractedGross,
            $recoveries,
            $this->contextFor($periodCode, Rational::of($periodDays), $periodDays),
            "{$employmentId}|{$periodCode}|full-month",
        );

        $lines = [];
        $balanceLines = [];

        foreach ($this->components as $component) {
            if (!array_key_exists($component->code, $paid['amounts'])) {
                // A schedule with no instalment due. Not a zero line: a line of
                // zero on a payslip reads as "we took nothing from a loan you
                // have", which is a different statement from the loan not being
                // due.
                continue;
            }

            $amount = $paid['amounts'][$component->code];
            $line = new PayslipLine(
                componentCode: $component->code,
                componentVersionId: $component->code . '-v1',
                kind: $component->kind,
                amount: $amount,
                amountBeforeProration: $full['amounts'][$component->code] ?? $amount,
                trace: $this->traceFor($component, $contractedGross, $proratedGross, $paid),
            );

            if ($component->isBalance()) {
                $balanceLines[] = $line;
                $trace[] = 'Balance component ' . $component->code . ' absorbed the residue: '
                    . $amount->format();

                continue;
            }

            $lines[] = $line;
        }

        return new Payslip(
            $employmentId,
            $periodCode,
            array_merge($lines, $balanceLines),
            implode("\n", $trace),
        );
    }

    /**
     * Every component's amount against ONE set of figures, in topological order.
     *
     * @param array<string, Money> $recoveries
     * @return array{amounts: array<string, Money>, traces: array<string, string>}
     */
    private function pass(
        Money $contractedGross,
        Money $proratedGross,
        array $recoveries,
        ?Context $context,
        string $digest,
    ): array {
        $amounts = [];
        $traces = [];
        $earnings = Money::zero();

        foreach ($this->order as $component) {
            $base = $component->prorates() ? $proratedGross : $contractedGross;

            if ($component->isBalance()) {
                // The residue, by SUBTRACTION. This is what makes the earning
                // lines sum to the prorated gross exactly rather than to within
                // a paisa. Every other earning is already computed: the graph
                // says so, and refused the structure if it could not be.
                $amount = $proratedGross->minus($earnings);
            } elseif ($component->basis === SalaryComponent::FORMULA) {
                $result = $this->evaluate($component, $base, $amounts, $context, $digest);
                $amount = $result->money ?? Money::zero();
                $traces[$component->code] = rtrim($result->trace);
            } elseif ($component->basis === SalaryComponent::SLAB) {
                $amount = $this->slabAmount($component, $base, $amounts);
            } else {
                $amount = match ($component->basis) {
                    SalaryComponent::FLAT => Money::of((string) $component->flatAmount),
                    SalaryComponent::PERCENT_OF_GROSS => $base->multipliedBy(
                        Rational::percent((int) $component->ratePercent),
                        RoundingMode::HalfUp,
                    ),
                    SalaryComponent::PERCENT_OF => ($amounts[$component->ofComponent] ?? Money::zero())
                        ->multipliedBy(Rational::percent((int) $component->ratePercent), RoundingMode::HalfUp),
                    SalaryComponent::SCHEDULE => $recoveries[$component->code] ?? Money::zero(),
                    default => Money::zero(),
                };
            }

            if ($component->basis === SalaryComponent::SCHEDULE && $amount->isZero()) {
                continue;
            }

            $amounts[$component->code] = $amount;

            if ($component->kind === SalaryComponent::EARNING && !$component->isBalance()) {
                $earnings = $earnings->plus($amount);
            }
        }

        return ['amounts' => $amounts, 'traces' => $traces];
    }

    /**
     * One formula component, computed by the formula engine.
     *
     * The context is assembled here and is the whole of what the expression can
     * reach: the gross this component computes against, the month's payable and
     * calendar days, and every component already computed, by CODE. The engine
     * holds no connection and no repository, so there is nothing else it could
     * read even if an expression named it.
     *
     * @param array<string, Money> $amounts the components computed so far, this pass
     */
    private function evaluate(
        SalaryComponent $component,
        Money $gross,
        array $amounts,
        ?Context $context,
        string $digest,
    ): FormulaResult {
        if ($this->calculator === null || $context === null || $component->formula === null) {
            throw new \LogicException(
                "'{$component->code}' is a formula component and no formula engine was built for it. "
                . 'This is a platform bug, not a configuration error.'
            );
        }

        $symbols = ['salary.gross' => Value::money($gross)];

        foreach ($amounts as $code => $amount) {
            $symbols["salary.component('{$code}')"] = Value::money($amount);
        }

        $result = $this->calculator->evaluate($component->formula, $context->withSymbols($symbols), $digest);

        if ($result->isError()) {
            throw new \RuntimeException(sprintf(
                '%s could not be computed: %s. The run is refused rather than continued without the '
                . 'line — a missing earning is somebody underpaid, and on a payslip it looks exactly '
                . 'like a component nobody declared. The expression is: %s',
                $component->code,
                (string) $result->error,
                $component->formula->expression->source,
            ));
        }

        if ($result->money === null) {
            throw new \RuntimeException(sprintf(
                '%s produced %s, which is not an amount of money, so there is no line to put on a '
                . 'payslip. The run is refused rather than showing a figure nobody can pay. The '
                . 'expression is: %s',
                $component->code,
                $result->describe(),
                $component->formula->expression->source,
            ));
        }

        return $result;
    }

    /**
     * One slab component: which declared range the base falls in, and that
     * range's flat amount or its percent of the same base. `$component->slab`
     * arrives already sorted and validated non-overlapping (`compileSlab()`
     * refused the structure at publish otherwise), so this only has to find
     * the range and never has to adjudicate between two that could both
     * match — that ambiguity cannot reach here.
     *
     * @param array<string, Money> $amounts the components computed so far, this pass
     */
    private function slabAmount(SalaryComponent $component, Money $base, array $amounts): Money
    {
        $against = $component->ofComponent !== null
            ? ($amounts[$component->ofComponent] ?? Money::zero())
            : $base;

        foreach ($component->slab as $range) {
            if ($against->compareTo($range['from']) < 0) {
                continue;
            }

            if ($range['to'] !== null && $against->compareTo($range['to']) > 0) {
                continue;
            }

            return $range['amount'] !== null
                ? $range['amount']
                : $against->multipliedBy(Rational::percent((int) $range['percent']), RoundingMode::HalfUp);
        }

        throw new \RuntimeException(sprintf(
            '%s is a slab component and %s falls in none of its declared ranges (%s). The run is '
            . 'refused rather than computed as zero, which would read on a payslip exactly like a '
            . 'component nobody declared.',
            $component->code,
            $against->format(),
            implode(', ', array_map(
                static fn (array $r): string => $r['from']->format() . '–' . ($r['to']?->format() ?? 'and above'),
                $component->slab,
            )),
        ));
    }

    /**
     * The period's own days and its own clock. R-VII-019: there is no NOW() in
     * an expression, which is what lets March, recomputed in November, produce
     * March's answer.
     */
    private function contextFor(string $periodCode, Rational $payableDays, int $periodDays): ?Context
    {
        if ($this->calculator === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}$/', $periodCode) !== 1) {
            throw new \InvalidArgumentException(
                "'{$periodCode}' is not a YYYY-MM payroll period, and a formula component is evaluated "
                . 'as of the period it belongs to. The run is refused rather than evaluated as of a '
                . 'date this build had to guess.'
            );
        }

        return (new Context(new \DateTimeImmutable($periodCode . '-01', new \DateTimeZone('UTC'))))
            ->withSymbols([
                'period.payable_days' => Value::quantity(TypeKind::Days, $payableDays),
                'period.calendar_days' => Value::quantity(TypeKind::Days, Rational::of($periodDays)),
            ]);
    }

    /**
     * THE EVALUATION ORDER, and the cycle refusal that comes with it (§7.2).
     *
     * Three kinds of edge, only one of which an AST can show:
     *
     *   a formula reads `salary.component('X')`  — derived from the expression
     *   a percent_of names the component it is a percentage of
     *   the balance depends on EVERY other earning, because it is what is left
     *   after them — which is what makes a formula that reads the balance a
     *   cycle rather than a component evaluated at the wrong moment.
     *
     * @return list<SalaryComponent>
     */
    private function topologicalOrder(): array
    {
        $graph = new Graph();
        $byCode = [];

        foreach ($this->components as $component) {
            $byCode[$component->code] = $component;
            $graph->add('component:' . $component->code, [], $component->sequence, $component->describe());
        }

        foreach ($this->components as $component) {
            $node = 'component:' . $component->code;

            if ($component->formula !== null) {
                foreach ($component->formula->dependencyCodes('component') as $target) {
                    if (!isset($byCode[$target])) {
                        throw new \InvalidArgumentException(sprintf(
                            "'%s' reads salary.component('%s'), and no component of this structure is "
                            . 'called that. The structure is refused rather than published with a '
                            . 'reference that would read as zero: a silent zero underpays somebody and '
                            . 'looks exactly like a component nobody declared. Declared here: %s.',
                            $component->code,
                            $target,
                            implode(', ', array_keys($byCode)),
                        ));
                    }

                    $graph->addEdge($node, 'component:' . $target, "salary.component('{$target}')");
                }

                continue;
            }

            if ($component->basis === SalaryComponent::PERCENT_OF && isset($byCode[(string) $component->ofComponent])) {
                $graph->addEdge($node, 'component:' . $component->ofComponent,
                    "a percentage of {$component->ofComponent}");
            }

            if ($component->basis === SalaryComponent::SLAB && $component->ofComponent !== null
                && isset($byCode[$component->ofComponent])) {
                $graph->addEdge($node, 'component:' . $component->ofComponent,
                    "a slab of {$component->ofComponent}");
            }

            if (!$component->isBalance()) {
                continue;
            }

            foreach ($this->components as $other) {
                if ($other->kind === SalaryComponent::EARNING && !$other->isBalance()) {
                    $graph->addEdge($node, 'component:' . $other->code,
                        'the balance is what is left after ' . $other->code);
                }
            }
        }

        $order = [];

        foreach ($graph->evaluationOrder() as $node) {
            $code = substr($node, strlen('component:'));

            if (isset($byCode[$code])) {
                $order[] = $byCode[$code];
            }
        }

        return $order;
    }

    /**
     * @param array{amounts: array<string, Money>, traces: array<string, string>} $paid
     */
    private function traceFor(
        SalaryComponent $component,
        Money $contractedGross,
        Money $proratedGross,
        array $paid,
    ): string {
        $amount = $paid['amounts'][$component->code];
        $base = $component->prorates() ? $proratedGross : $contractedGross;

        return match ($component->basis) {
            // "a flat statutory amount" was asserted here for EVERY flat component,
            // whatever it was. The shipped structure has one — a 200.00 deduction
            // with no state, no slab and no legal basis behind it — and this line
            // printed it on the employee's payslip as a statutory amount. The
            // engine cannot know whether a flat figure answers to a statute; only a
            // signed pack from a compliance owner knows that, and this build has
            // none. So it states what it actually did.
            SalaryComponent::FLAT => "{$component->code} is a flat {$amount->format()} declared in the "
                . 'salary structure, not derived from a rate or a slab'
                . ($component->prorates() ? '' : ', and it does NOT prorate — a flat monthly figure is '
                    . 'for the month, and prorating it would under-deduct against a return that expects '
                    . 'it whole'),
            SalaryComponent::PERCENT_OF_GROSS => "{$component->ratePercent}% of {$base->format()}"
                . ' = ' . $amount->format(),
            SalaryComponent::PERCENT_OF => "{$component->ratePercent}% of {$component->ofComponent} ("
                . ($paid['amounts'][$component->ofComponent] ?? Money::zero())->format() . ') = '
                . $amount->format(),
            SalaryComponent::SCHEDULE => "recovery instalment {$amount->format()} from its own schedule",
            SalaryComponent::SLAB => sprintf(
                'slab on %s (%s) = %s, from the declared range that %s falls in',
                $component->ofComponent ?? 'gross',
                ($component->ofComponent !== null
                    ? ($paid['amounts'][$component->ofComponent] ?? Money::zero())
                    : $base)->format(),
                $amount->format(),
                $component->ofComponent ?? 'gross',
            ),
            SalaryComponent::BALANCE => "the balance of {$proratedGross->format()} after "
                . $proratedGross->minus($amount)->format() . ' of other earnings = ' . $amount->format()
                . ' — computed by subtraction, so the lines sum to the gross by construction rather '
                . 'than to within a paisa of it',
            // THE ENGINE'S OWN TRACE, not a re-description of it. The sentence
            // stored with the line is the one the calculator wrote while it was
            // computing the number: the expression, the exact rational before the
            // boundary, the declared rounding and what it cost in paise. A fresh
            // explanation written afterwards is a second opinion about arithmetic
            // that has already happened, and it can be wrong while the number is
            // right.
            SalaryComponent::FORMULA => $paid['traces'][$component->code] ?? $amount->format(),
            default => $amount->format(),
        };
    }
}
