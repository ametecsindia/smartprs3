<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Payroll;

use SmartPRS\Platform\Formula\Formula;
use SmartPRS\Platform\Formula\Rounding;
use SmartPRS\Platform\Spx\Compiled;
use SmartPRS\Platform\Spx\SpxSyntaxError;
use SmartPRS\Platform\Spx\SymbolTable;
use SmartPRS\Platform\Spx\Types\Type;
use SmartPRS\Platform\Spx\Types\TypeKind;
use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\Scope;

/**
 * A SALARY COMPONENT, DEFINED BY FLAGS RATHER THAN BY NAME — Part XV §XV.2.
 *
 * The Phase 5 exit gate states it as a property of the whole codebase: **no
 * component name appears as a literal anywhere**. Not "BASIC", not "HRA", not
 * "PF". That rule is why this class has no `isBasic()` and no `if ($code ===
 * …)`: everything a component DOES is a declared flag, so a tenant whose basic
 * is called "Consolidated Pay" and whose HRA does not exist gets the same engine
 * as everybody else.
 *
 * SmartPRS 2 is the counter-example on every axis. Its component semantics are
 * decided by string comparison against names in nineteen places; its PF base is
 * whichever components a controller happens to list; and a customer who renames
 * a component silently changes what it contributes to, because the name WAS the
 * behaviour.
 *
 * Four bases, and the fourth is the one that makes a payslip add up:
 *
 *   FLAT              a stated amount
 *   PERCENT_OF_GROSS  a rate on the gross
 *   PERCENT_OF        a rate on another component
 *   BALANCE           whatever is left after the others — the residue absorber
 *
 * Without a balance component, three components each rounded to the paisa sum to
 * the gross plus or minus a paisa, and the payslip prints an equation an
 * employee can see is wrong.
 *
 * And a sixth, FORMULA, because five fixed shapes are not "change configuration,
 * not code". A customer whose HRA is "40% of basic, capped at ₹20,000", or "the
 * greater of ₹1,500 and 10% of basic", could express none of it: the answer was
 * a release, at the one place a customer meets the product first. A formula
 * component declares an EXPRESSION, and the expression is computed by
 * `Platform\Formula` — the finished engine that until now no screen called — so
 * there is exactly one evaluator in this product and not a second one grown
 * quietly inside payroll.
 *
 * Everything the formula engine needs to publish a formula is declared HERE, at
 * publish time, where a person is reading: the expression parses or the
 * structure is refused; it type-checks to Money or the structure is refused; it
 * declares its own rounding, because there is no default rounding anywhere
 * (R-VIII-025); and it carries the sentence that will be printed underneath the
 * number on somebody's payslip.
 */
final readonly class SalaryComponent
{
    public const EARNING = 'earning';
    public const DEDUCTION = 'deduction';
    public const RECOVERY = 'recovery';
    public const EMPLOYER_CONTRIBUTION = 'employer_contribution';

    public const KINDS = [self::EARNING, self::DEDUCTION, self::RECOVERY, self::EMPLOYER_CONTRIBUTION];

    public const FLAT = 'flat';
    public const PERCENT_OF_GROSS = 'percent_of_gross';
    public const PERCENT_OF = 'percent_of';
    public const BALANCE = 'balance';
    public const SCHEDULE = 'schedule';
    public const FORMULA = 'formula';
    public const SLAB = 'slab';

    public const BASES = [
        self::FLAT, self::PERCENT_OF_GROSS, self::PERCENT_OF, self::BALANCE, self::SCHEDULE, self::FORMULA,
        self::SLAB,
    ];

    /**
     * WHAT AN EXPRESSION MAY SEE, decided here and nowhere else.
     *
     * Two namespaces: `salary.*` — the gross this component computes against and
     * `salary.component('CODE')` for every component already computed — and
     * `period.*` for the month's payable and calendar days. Nothing else is in
     * the table, so `employee.dob`, a statutory head, a ledger balance or
     * another employee is a PUBLISH-TIME refusal ("Namespace 'employee' is not
     * declared available at this decision point") rather than a null at run
     * time. That is the ceiling, and it is deliberately low: a payslip line is
     * arithmetic over this month's own figures, and every widening of it is a
     * new thing the payroll run has to fetch, authorise and reproduce in 2031.
     *
     * ponytail: two namespaces, no per-tenant table. Widen here — one line —
     * when a customer needs a component keyed off employment data, and add the
     * assembly that binds it in PayslipCalculator at the same time.
     */
    public const NAMESPACES = ['salary', 'period'];

    /** The published formula this component computes through — FORMULA basis only. */
    public ?Formula $formula;

    /**
     * SLAB basis only: the ranges, sorted `from` ascending, in the exact shape
     * `PayslipCalculator` reads them in — never re-sorted or re-parsed there,
     * because a second place that orders or reads them is a second place that
     * can disagree with this one about which range an amount falls in.
     *
     * @var list<array{from: Money, to: ?Money, amount: ?Money, percent: ?int}>
     */
    public array $slab;

    /**
     * @param array<string, bool> $flags what this component IS, declared:
     *        `pf_base`, `esi_base`, `pt_base`, `prorates`, `taxable`, and any
     *        tenant-declared flag a rule reads. Nothing here is checked against a
     *        list, because a statutory scheme this build has not met yet is a
     *        flag somebody adds rather than a release.
     */
    public function __construct(
        public string $code,
        public string $kind,
        public string $basis,
        public array $flags = [],
        public ?string $ofComponent = null,
        public ?int $ratePercent = null,
        public ?string $flatAmount = null,
        public int $sequence = 0,
        /**
         * The FORMULA basis's declaration, exactly as the salary structure
         * writes it: `expression`, `rounding_mode`, `rounding_unit`,
         * `description`, `sample_cases`. One bag rather than five constructor
         * parameters, because it is one config row and every key in it is
         * meaningless on the other five bases.
         *
         * @var array<string, mixed>
         */
        public array $declaredFormula = [],
        /**
         * SLAB basis only, declared exactly as the salary structure writes it:
         * a list of `{from, to, amount, percent}` where `to` is null on (and
         * only on) the last, open-ended range, and exactly one of `amount`
         * (an exact decimal) or `percent` (a whole number, the same integer
         * percent PERCENT_OF and PERCENT_OF_GROSS already require) is set per
         * row — never both, because a range whose applicable value is
         * ambiguous between a rupee figure and a rate is a range nobody
         * decided the meaning of.
         *
         * @var list<array{from: string, to: ?string, amount: ?string, percent: ?int}>
         */
        array $declaredSlab = [],
    ) {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("'{$kind}' is not a component kind.");
        }

        if (!in_array($basis, self::BASES, true)) {
            throw new \InvalidArgumentException("'{$basis}' is not a component basis.");
        }

        if ($basis === self::PERCENT_OF && $ofComponent === null) {
            throw new \InvalidArgumentException(
                "'{$code}' is a percentage OF something and does not say of what. A rate with no base is a "
                . 'number that reads as an amount.'
            );
        }

        if (in_array($basis, [self::PERCENT_OF, self::PERCENT_OF_GROSS], true) && $ratePercent === null) {
            throw new \InvalidArgumentException("'{$code}' is a percentage basis and declares no rate.");
        }

        if ($basis === self::FLAT && $flatAmount === null) {
            throw new \InvalidArgumentException("'{$code}' is a flat component and declares no amount.");
        }

        // A declaration nobody reads is worse than a missing one: somebody wrote
        // an expression, saw it published, and is being paid by a rate.
        if ($basis !== self::FORMULA && $declaredFormula !== []) {
            throw new \InvalidArgumentException(
                "'{$code}' declares a formula but its basis is '{$basis}', so the expression would never "
                . "be computed. Set the basis to 'formula', or remove the declaration."
            );
        }

        if ($basis !== self::SLAB && $declaredSlab !== []) {
            throw new \InvalidArgumentException(
                "'{$code}' declares slab ranges but its basis is '{$basis}', so they would never be "
                . "read. Set the basis to 'slab', or remove the ranges."
            );
        }

        if ($basis === self::SLAB && $declaredSlab === []) {
            throw new \InvalidArgumentException(
                "'{$code}' is a slab component and declares no ranges. There is nothing to compute, "
                . 'and a component with nothing to compute pays zero without saying so.'
            );
        }

        $this->formula = $basis === self::FORMULA
            ? self::compile($code, $kind, $sequence, $declaredFormula)
            : null;
        $this->slab = $basis === self::SLAB ? self::compileSlab($code, $declaredSlab) : [];
    }

    /** Does this component's amount scale with the days actually payable? */
    public function prorates(): bool
    {
        return (bool) ($this->flags['prorates'] ?? false);
    }

    public function isBalance(): bool
    {
        return $this->basis === self::BALANCE;
    }

    /**
     * Whether this component enters a named statutory base.
     *
     * A flag nobody declared is FALSE. That is the conservative default here and
     * the opposite of the rule for seats: including a component in a statutory
     * base by accident over-deducts from an employee's pay, and the employee is
     * the one who notices.
     */
    public function entersBase(string $flag): bool
    {
        return (bool) ($this->flags[$flag] ?? false);
    }

    public function describe(): string
    {
        $what = match ($this->basis) {
            self::FLAT => "a flat {$this->flatAmount}",
            self::PERCENT_OF_GROSS => "{$this->ratePercent}% of gross",
            self::PERCENT_OF => "{$this->ratePercent}% of {$this->ofComponent}",
            self::BALANCE => 'the balance of the gross after the others',
            self::SCHEDULE => 'an amount from its own schedule',
            self::FORMULA => 'the formula ' . ($this->formula?->expression->source ?? '(none)'),
            self::SLAB => count($this->slab) . ' slab range(s)'
                . ($this->ofComponent !== null ? " of {$this->ofComponent}" : ' of gross'),
        };

        return "{$this->code} ({$this->kind}, {$what}"
            . ($this->prorates() ? ', prorates' : ', does not prorate') . ')';
    }

    /**
     * Turn the declared formula into a PUBLISHED `Formula`, or refuse the whole
     * structure with a sentence naming this component and what to change.
     *
     * Every refusal below happens where a person is standing — `Structure`
     * builds the components once at publish and reports what came back — rather
     * than at 3am on a close, and never as a component that quietly computes
     * zero. A missing earning is somebody underpaid, and on a payslip it looks
     * exactly like a component nobody declared.
     *
     * @param array<string, mixed> $declared
     */
    private static function compile(string $code, string $kind, int $sequence, array $declared): Formula
    {
        $source = trim((string) ($declared['expression'] ?? ''));

        if ($source === '') {
            throw new \InvalidArgumentException(
                "'{$code}' is a formula component and declares no expression. There is nothing to "
                . 'compute, and a component with nothing to compute pays zero without saying so.'
            );
        }

        $mode = trim((string) ($declared['rounding_mode'] ?? ''));

        if ($mode === '') {
            throw new \InvalidArgumentException(
                "'{$code}' declares an expression and no rounding_mode. There is no default rounding "
                . 'anywhere in SmartPRS 3 (R-VIII-025): declare one of half_up, half_down, half_even, '
                . "up, down, ceil, floor, and rounding_unit ('0.01' for the paisa, '1.00' for the rupee)."
            );
        }

        $description = trim((string) ($declared['description'] ?? ''));

        if (mb_strlen($description) < Formula::MIN_DESCRIPTION) {
            throw new \InvalidArgumentException(
                "'{$code}' declares an expression and no description of at least "
                . Formula::MIN_DESCRIPTION . ' characters. It is printed under the number on the '
                . 'payslip, so leaving it blank hands an employee an amount and no sentence.'
            );
        }

        $cases = array_map(strval(...), array_values((array) ($declared['sample_cases'] ?? [])));

        if (count($cases) < 3) {
            throw new \InvalidArgumentException(
                "'{$code}' names " . count($cases) . ' sample case(s); three is the minimum before a '
                . 'formula computes anybody\'s pay (R-VIII-047). Name the months you expect it to '
                . 'handle — for example "full month", "mid-month joiner", "loss of pay" — so that a '
                . 'later change to it has something to be re-run against.'
            );
        }

        try {
            $expression = Compiled::parse($source, self::symbols());
        } catch (SpxSyntaxError $e) {
            throw new \InvalidArgumentException(
                "'{$code}' declares an expression this build cannot read: " . $e->getMessage()
                . '. The structure is refused rather than published with a component that would fail '
                . 'on a close; fix the expression and publish again.'
            );
        }

        $check = $expression->typeCheck();

        if (!$check->isValid()) {
            throw new \InvalidArgumentException(
                "'{$code}' declares an expression that does not check out: "
                . implode('; ', $check->messages()) . '. An expression may read salary.gross, '
                . "salary.component('CODE') for a component of this structure, period.payable_days "
                . 'and period.calendar_days, and nothing else.'
            );
        }

        if (!$check->type->is(TypeKind::Money)) {
            throw new \InvalidArgumentException(
                "'{$code}' declares an expression that produces {$check->type}, not money. A payslip "
                . 'line is an amount in rupees, so the structure is refused rather than published with '
                . 'a line whose value would be coerced into one.'
            );
        }

        try {
            $rounding = Rounding::of($mode, trim((string) ($declared['rounding_unit'] ?? '0.01')));
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException("'{$code}': " . $e->getMessage());
        }

        // A two-letter component (PT, DA, OT) is still a formula: the formula register wants 3+ characters,
        // so its formula id is padded (PT -> PT_). Payslip lines stay keyed by the component code.
        return new Formula(
            code: str_pad($code, 3, '_'),
            calculationPoint: 'comp.' . $kind,
            module: 'payroll',
            name: $code,
            businessDescription: $description,
            expression: $expression,
            resultType: Type::money(),
            rounding: $rounding,
            scope: Scope::tenantDefault(),
            // ponytail: the effective range that matters is the salary
            // STRUCTURE VERSION's, and payroll already resolves that before it
            // ever reaches here; the Calculator never consults this one. Pass
            // the version's range through when formulas become artefacts in
            // their own right with their own effective dating.
            range: EffectiveRange::openEndedFrom(new \DateTimeImmutable('1970-01-01')),
            versionNo: 1,
            versionId: str_pad($code, 3, '_') . '-v1',
            status: 'published',
            unit: 'INR',
            sequenceNo: $sequence,
            sampleTests: $cases,
        );
    }

    private static function symbols(): SymbolTable
    {
        return SymbolTable::forNamespaces(...self::NAMESPACES);
    }

    /**
     * Turn the declared ranges into ordered, exact, non-overlapping bounds —
     * or refuse the whole structure naming this component and what is wrong.
     * Every check below happens at publish, where a person is reading, for
     * the same reason `compile()` above refuses a formula there: a slab that
     * cannot decide which range an amount falls in is worse discovered on a
     * close than at the point somebody typed it.
     *
     * @param list<array<string, mixed>> $declared
     * @return list<array{from: Money, to: ?Money, amount: ?Money, percent: ?int}>
     */
    private static function compileSlab(string $code, array $declared): array
    {
        $ranges = [];

        foreach ($declared as $i => $row) {
            $n = $i + 1;
            $fromRaw = trim((string) ($row['from'] ?? ''));
            $toRaw = $row['to'] === null || $row['to'] === '' ? null : trim((string) $row['to']);
            $amountRaw = $row['amount'] === null || $row['amount'] === '' ? null : trim((string) $row['amount']);
            $percentRaw = $row['percent'] ?? null;

            try {
                $from = Money::of($fromRaw === '' ? '0' : $fromRaw);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException(
                    "'{$code}' slab range {$n}: '{$fromRaw}' is not an exact amount for \"from\". "
                    . $e->getMessage()
                );
            }

            $to = null;

            if ($toRaw !== null) {
                try {
                    $to = Money::of($toRaw);
                } catch (\InvalidArgumentException $e) {
                    throw new \InvalidArgumentException(
                        "'{$code}' slab range {$n}: '{$toRaw}' is not an exact amount for \"to\", and "
                        . 'only the last range may be left open-ended (blank means \"and above\"). '
                        . $e->getMessage()
                    );
                }

                if ($to->compareTo($from) < 0) {
                    throw new \InvalidArgumentException(
                        "'{$code}' slab range {$n}: \"to\" ({$to->format()}) is before \"from\" "
                        . "({$from->format()}). A range that ends before it starts matches nothing, "
                        . 'which pays this component zero without saying so.'
                    );
                }
            }

            if (($amountRaw === null) === ($percentRaw === null)) {
                throw new \InvalidArgumentException(
                    "'{$code}' slab range {$n}: declare exactly one of an amount or a percent, never "
                    . 'both and never neither — a range whose applicable value is ambiguous between a '
                    . 'rupee figure and a rate is a range nobody decided the meaning of.'
                );
            }

            $amount = null;
            $percent = null;

            if ($amountRaw !== null) {
                try {
                    $amount = Money::of($amountRaw);
                } catch (\InvalidArgumentException $e) {
                    throw new \InvalidArgumentException(
                        "'{$code}' slab range {$n}: '{$amountRaw}' is not an exact amount. "
                        . $e->getMessage()
                    );
                }
            } else {
                if (!preg_match('/^\d+$/', (string) $percentRaw)) {
                    throw new \InvalidArgumentException(
                        "'{$code}' slab range {$n}: '{$percentRaw}' is not a whole-number percent. A "
                        . 'fractional percent is refused rather than truncated, the same as every '
                        . 'other percentage basis in this engine.'
                    );
                }

                $percent = (int) $percentRaw;
            }

            $ranges[] = ['from' => $from, 'to' => $to, 'amount' => $amount, 'percent' => $percent];
        }

        usort($ranges, static fn (array $a, array $b): int => $a['from']->compareTo($b['from']));

        foreach ($ranges as $i => $range) {
            $isLast = $i === count($ranges) - 1;

            if (!$isLast && $range['to'] === null) {
                throw new \InvalidArgumentException(
                    "'{$code}' slab range starting at {$range['from']->format()} is open-ended (no "
                    . '"to") but is not the last range — only the highest range may say "and above".'
                );
            }

            if ($i > 0) {
                $previous = $ranges[$i - 1];

                if ($previous['to'] === null || $range['from']->compareTo($previous['to']) <= 0) {
                    throw new \InvalidArgumentException(
                        "'{$code}' slab range starting at {$range['from']->format()} overlaps the one "
                        . "before it (ending at " . ($previous['to']?->format() ?? 'and above')
                        . '). Two ranges that can both match the same amount is an ambiguity payroll '
                        . 'cannot resolve.'
                    );
                }
            }
        }

        return $ranges;
    }
}
