<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Formula;

use SmartPRS\Platform\Spx\Context;
use SmartPRS\Platform\Spx\Evaluator;
use SmartPRS\Platform\Spx\Types\Type;
use SmartPRS\Platform\Spx\Types\TypeKind;
use SmartPRS\Platform\Spx\Value;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;

/**
 * EVALUATING A SET OF FORMULAS — Part VIII §7.3, §7.4, §6.3.
 *
 * Three properties, each of which SmartPRS 2 lacks and pays for:
 *
 *   ORDER IS TOPOLOGICAL (R-VIII-037). Not sequence_no, which governs payslip
 *   presentation and nothing else. SmartPRS 2 sorts by seq ASC and then
 *   hard-codes five ordered passes, so a customer who reorders their payslip
 *   changes their arithmetic.
 *
 *   ROUNDING HAPPENS ONCE, at the declared boundary (§6.3). Intermediates stay
 *   exact rationals; a dependent reads the published rounded number unless the
 *   producer is an intermediate that declares `reference_value = exact`.
 *
 *   MEMOISATION IS PER-BATCH AND IN-MEMORY (R-VIII-038). Never a cross-request
 *   cache, never a TTL: a stale amount is a wrong payslip, not a slow one. A
 *   formula referenced by six components is evaluated once and the other five
 *   record a reference to the single computed trace.
 */
final class Calculator
{
    /** @var array<string, FormulaResult> memo key => result */
    private array $memo = [];

    private int $memoHits = 0;

    /**
     * @param array<string, mixed> $formulas code => formula, all published; declared wide
     *        because this constructor is a boundary the caller can hand anything to, and
     *        the loop below is what actually proves each value is a published Formula
     */
    public function __construct(private readonly array $formulas)
    {
        foreach ($this->formulas as $code => $formula) {
            if (!$formula instanceof Formula) {
                throw new \InvalidArgumentException('A calculator holds Formula values only.');
            }

            if ($formula->code !== $code) {
                throw new \InvalidArgumentException(
                    "Formula '{$formula->code}' is keyed as '{$code}'; the key is the code."
                );
            }

            if (!$formula->isPublished()) {
                throw new \InvalidArgumentException(
                    "Formula {$formula->label()} is '{$formula->status}'. Only published formulas evaluate (§4.1)."
                );
            }
        }
    }

    /**
     * The graph of everything this calculator holds, with the edges the ASTs
     * declare. Component edges the structure supplies are added by the caller
     * through Graph::addEdge() before asking for an order.
     */
    public function graph(): Graph
    {
        $graph = new Graph();

        foreach ($this->formulas as $formula) {
            $graph->add($formula->node(), $formula->dependsOn, $formula->sequenceNo, $formula->label());
        }

        return $graph;
    }

    /**
     * Evaluation order over the formulas alone.
     *
     * @return list<Formula>
     * @throws CircularDependency
     */
    public function order(): array
    {
        $order = [];

        foreach ($this->graph()->evaluationOrder() as $node) {
            $code = str_starts_with($node, 'formula:') ? substr($node, 8) : null;

            if ($code !== null && isset($this->formulas[$code])) {
                $order[] = $this->formulas[$code];
            }
        }

        return $order;
    }

    /**
     * Evaluate every formula, in dependency order, against one prepared context.
     *
     * The context is assembled by the caller after authorisation (§8.1): the
     * engine holds no connection and no repository, so a formula cannot read
     * anything the caller did not put in front of it.
     *
     * @return array<string, FormulaResult> code => result, in evaluation order
     * @throws CircularDependency
     */
    public function evaluateAll(Context $context, string $contextDigest = ''): array
    {
        $results = [];

        foreach ($this->order() as $formula) {
            $results[$formula->code] = $this->evaluate($formula, $context, $contextDigest, $results);
        }

        return $results;
    }

    public function memoHits(): int
    {
        return $this->memoHits;
    }

    /**
     * One formula, given the results of everything it depends on.
     *
     * @param array<string, FormulaResult> $computed
     */
    public function evaluate(
        Formula $formula,
        Context $context,
        string $contextDigest,
        array $computed = [],
    ): FormulaResult {
        // §7.4: (formula_version_id, context_digest). Employee and period are in
        // the digest, because they are what the context is assembled from.
        $key = $formula->versionId . '|' . $contextDigest;

        if (isset($this->memo[$key])) {
            $this->memoHits++;

            return $this->memo[$key]->asMemoHit();
        }

        $missing = $this->missingDependency($formula, $computed);

        if ($missing !== null) {
            // §7.5: impossible by construction, because the topological order
            // guarantees it. If it happens it is a platform bug, not a
            // configuration error, and it says so rather than computing a zero.
            return $this->memo[$key] = FormulaResult::failed(
                $formula->code,
                $formula->versionId,
                "ERR_TYPE: {$formula->label()} was evaluated before '{$missing}', which it depends on. "
                . 'The topological order guarantees this cannot happen, so this is a platform bug (§7.5).',
                $this->header($formula) . "  not evaluated\n",
            );
        }

        $value = Evaluator::evaluate($formula->expression, $this->bind($formula, $context, $computed));

        // Checking the property directly (rather than calling isError()) is what
        // lets the analyser see $value->error as non-null in this block — it is
        // the same condition, and isError() itself is defined as this comparison.
        if ($value->error !== null) {
            return $this->memo[$key] = FormulaResult::failed(
                $formula->code,
                $formula->versionId,
                $value->error->value,
                $this->header($formula) . '  ' . $value->error->value . "\n",
            );
        }

        if ($value->isNull()) {
            return $this->memo[$key] = FormulaResult::failed(
                $formula->code,
                $formula->versionId,
                'ERR_MISSING_INPUT: the expression evaluated to UNKNOWN, which is not a payable amount',
                $this->header($formula) . "  UNKNOWN\n",
            );
        }

        return $this->memo[$key] = $value->type->is(TypeKind::Money)
            ? $this->moneyResult($formula, $value)
            : FormulaResult::value(
                $formula->code,
                $formula->versionId,
                $value,
                $formula->rounding,
                $this->header($formula) . '  = ' . $value->describe() . "\n",
            );
    }

    private function moneyResult(Formula $formula, Value $value): FormulaResult
    {
        // The exact rational, in minor units, BEFORE the boundary. This is the
        // number §6.2 keeps so that rounding happens once rather than per
        // operation, and it is what the delta below is measured against.
        $exact = $value->rational();
        $currency = $value->type->parameter ?? 'INR';

        // The declared mode is applied to the EXACT rational, not to a Money
        // already truncated out of it: truncating first and rounding second
        // rounds twice, and half_up over a value that truncation has already
        // moved is not half_up over the value the formula produced.
        $rounded = $formula->rounding->isNone()
            ? Money::ofMinor($this->towardZero($exact), $currency)
            : Money::ofMinor(
                $formula->rounding->mode->divide(
                    $exact->numerator,
                    $exact->denominator * $formula->rounding->unitInMinor,
                ) * $formula->rounding->unitInMinor,
                $currency,
            );

        $delta = FormulaResult::money(
            $formula->code,
            $formula->versionId,
            $rounded,
            $exact,
            $formula->rounding,
            '',
        )->deltaMinor();

        $trace = $this->header($formula)
            . '  ' . $formula->expression->source . "\n"
            . '  exact                  = ' . $exact->numerator . '/' . $exact->denominator . " paise\n"
            . '  ' . str_pad($formula->rounding->describe(), 22) . '= ' . $rounded->format()
            . '   delta ' . ($delta === null ? '—' : $this->renderDelta($delta)) . "\n";

        // R-VIII-030: the declared bound is applied HERE, after rounding, at a
        // step that appears in the trace whether or not it bound. A clamp that
        // only shows up when it fires cannot be told apart from no clamp at
        // all, and "was there a floor on this component?" is a question an
        // administrator asks about a payslip that looks right.
        if ($formula->clamp !== null) {
            $clamped = $formula->clamp->applyTo($rounded, $formula->code);
            $trace .= '  ' . $clamped['step'] . "\n";

            if ($clamped['error'] !== null) {
                return FormulaResult::failed(
                    $formula->code,
                    $formula->versionId,
                    $clamped['error'],
                    $trace,
                );
            }

            $rounded = $clamped['money'];
        }

        return FormulaResult::money(
            $formula->code,
            $formula->versionId,
            $rounded,
            $exact,
            $formula->rounding,
            $trace,
        );
    }

    /**
     * Bind `formula.value('CODE')` to what the referenced formula produced,
     * honouring its declared `reference_value` (§4.1, D-VIII-01).
     *
     * @param array<string, FormulaResult> $computed
     */
    private function bind(Formula $formula, Context $context, array $computed): Context
    {
        $bindings = [];

        foreach ($formula->dependencyCodes('formula') as $code) {
            $result = $computed[$code] ?? null;
            $producer = $this->formulas[$code] ?? null;

            if ($result === null || $producer === null || $result->isError()) {
                continue;
            }

            $reference = $result->referenceValue($producer->referenceValue);

            if ($reference !== null) {
                // The reference is a RATIONAL in minor units, and for an
                // intermediate declaring `exact` it is deliberately not a whole
                // number of paise — that is the entire point of D-VIII-01.
                //
                // It was previously bound as `Money::ofMinor(intdiv(n, d))`,
                // which truncated the exact value to whole paise and threw the
                // remainder away: `exact` was honoured in name and defeated one
                // line later. (₹1,00,000 ÷ 3) × 3 came back ₹99,999.99, which
                // is the specific arithmetic FC-02 exists to refuse.
                //
                // A Money-typed quantity carries the rational intact, so the
                // exactness survives to the dependent's own declared rounding
                // boundary — which is where §6.2 says rounding happens, once.
                $bindings["formula.value('{$code}')"] = Value::quantityOfType(
                    Type::money($producer->unit ?? 'INR'),
                    $reference,
                );

                continue;
            }

            if ($result->value !== null) {
                $bindings["formula.value('{$code}')"] = $result->value;
            }
        }

        return $bindings === [] ? $context : $context->withSymbols($bindings);
    }

    /**
     * The first dependency this formula names that the calculator holds and has
     * not computed yet. Null when everything it needs is available.
     *
     * @param array<string, FormulaResult> $computed
     */
    private function missingDependency(Formula $formula, array $computed): ?string
    {
        foreach ($formula->dependencyCodes('formula') as $code) {
            if (isset($this->formulas[$code]) && !isset($computed[$code])) {
                return $code;
            }
        }

        return null;
    }

    private function header(Formula $formula): string
    {
        return $formula->describe() . "\n"
            . '  ' . $formula->businessDescription . "\n";
    }

    private function towardZero(Rational $exact): int
    {
        return intdiv($exact->numerator, $exact->denominator);
    }

    private function renderDelta(Rational $delta): string
    {
        if ($delta->numerator === 0) {
            return '0';
        }

        return $delta->denominator === 1
            ? (string) $delta->numerator
            : $delta->numerator . '/' . $delta->denominator;
    }
}
