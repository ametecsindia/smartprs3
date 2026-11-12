<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Migration;

use SmartPRS\Platform\Support\Money;

/**
 * THE REPORT THE CUSTOMER READS — Part XXIX §XXIX.7, R-XXIX-105 and R-XXIX-107.
 *
 * Two requirements shape every line of output here, and both are about LANGUAGE
 * rather than arithmetic.
 *
 *   R-XXIX-105: a Class C difference "MUST be reported in Part E under the
 *   heading *Defects corrected*, MUST NOT be counted in the failure total, and
 *   MUST NOT be described in the report as a variance, a discrepancy, an error or
 *   an exception. The report's language matters: the customer reads it, and the
 *   first SmartPRS 3 payroll will look different in exactly these ways." So
 *   `render()` refuses those four words in the corrections section, and the gate
 *   greps for them — a rule about wording that lives only in a style guide is a
 *   rule that lasts until the first person in a hurry.
 *
 *   R-XXIX-107: the sum of all Class C money is stated ONCE, in one place, as
 *   "the total financial effect of correcting known SmartPRS 2 defects". That
 *   single number is what the Product Owner and the customer most need, and no
 *   other part of the programme produces it.
 *
 * The report PASSES only when there are no Class E failures. Class C corrections
 * are successes and Class X differences are accepted with their references — so a
 * pass here means "nothing is unexplained", which is a stronger and more useful
 * claim than "nothing differs".
 */
final class ReconciliationReport
{
    /** @var list<Variance> */
    private array $variances = [];

    public function __construct(public readonly string $tenant, public readonly string $scope)
    {
    }

    public function add(Variance $variance): void
    {
        $this->variances[] = $variance;
    }

    /** @return list<Variance> */
    public function failures(): array
    {
        return array_values(array_filter($this->variances, static fn (Variance $v): bool => $v->isFailure()));
    }

    /** @return list<Variance> */
    public function corrections(): array
    {
        return array_values(array_filter($this->variances,
            static fn (Variance $v): bool => $v->isCorrection()));
    }

    /** @return list<Variance> */
    public function explained(): array
    {
        return array_values(array_filter($this->variances,
            static fn (Variance $v): bool => $v->effectiveClass === Variance::EXPLAINED));
    }

    public function passes(): bool
    {
        return $this->failures() === [];
    }

    /** R-XXIX-107's single number. */
    public function totalFinancialEffectOfCorrections(): Money
    {
        $total = Money::zero();

        foreach ($this->corrections() as $correction) {
            $total = $total->plus($correction->delta());
        }

        return $total;
    }

    /** @return array<string, array{count: int, money: Money}> per Class C code */
    public function correctionsByCode(): array
    {
        $byCode = [];

        foreach ($this->corrections() as $correction) {
            $code = (string) $correction->classCCode;
            $byCode[$code] ??= ['count' => 0, 'money' => Money::zero()];
            $byCode[$code]['count']++;
            $byCode[$code]['money'] = $byCode[$code]['money']->plus($correction->delta());
        }

        return $byCode;
    }

    /** The four words a correction may never be called. */
    public const FORBIDDEN_IN_CORRECTIONS = ['variance', 'discrepancy', 'error', 'exception'];

    public function render(): string
    {
        $out = [
            "RECONCILIATION — {$this->tenant} · {$this->scope}",
            str_repeat('=', 72),
            '',
            'PART A — OUTCOME',
            sprintf('  %s: %d unexplained difference(s)',
                $this->passes() ? 'PASS' : 'FAIL', count($this->failures())),
            '',
        ];

        if ($this->failures() !== []) {
            $out[] = 'PART B — UNEXPLAINED DIFFERENCES (each blocks sign-off)';

            foreach ($this->failures() as $failure) {
                $out[] = sprintf('  %s · %s · %s: was %s, now %s (%s)',
                    $failure->checkCode, $failure->subject, $failure->period,
                    $failure->legacyValue->format(), $failure->newValue->format(),
                    $failure->delta()->format());

                if ($failure->demotionReason !== '') {
                    $out[] = '      ' . $failure->demotionReason;
                }
            }

            $out[] = '';
        }

        if ($this->explained() !== []) {
            $out[] = 'PART C — DIFFERENCES ACCEPTED WITH A RECORDED EXPLANATION';

            foreach ($this->explained() as $item) {
                $out[] = sprintf('  %s · %s: %s — %s, accepted by %s',
                    $item->checkCode, $item->subject, $item->delta()->format(),
                    $item->reference, $item->acceptedBy);
            }

            $out[] = '';
        }

        // PART E. Deliberately not called a variance, a discrepancy, an error or
        // an exception — the customer reads this, and the first SmartPRS 3
        // payroll will look different in exactly these ways.
        $out[] = 'PART E — DEFECTS CORRECTED';
        $out[] = '  These figures differ because SmartPRS 3 applies a rule SmartPRS 2 applied incorrectly.';
        $out[] = '  Each is a success, and none is counted in the outcome above.';
        $out[] = '';

        foreach ($this->correctionsByCode() as $code => $summary) {
            $out[] = sprintf('  %-14s %-52s %d employee-period(s)  %s',
                $code,
                ClassCRegister::ENTRIES[$code]['defect'] . ' (' . ClassCRegister::citation($code) . ')',
                $summary['count'],
                $summary['money']->format());
        }

        $out[] = '';
        $out[] = '  TOTAL FINANCIAL EFFECT OF CORRECTING KNOWN SmartPRS 2 DEFECTS: '
            . $this->totalFinancialEffectOfCorrections()->format();
        $out[] = '  (stated once, here, and nowhere else — this is the single number the Product Owner and';
        $out[] = '   the customer most need, and no other part of the programme produces it)';

        return implode("\n", $out);
    }

    /**
     * Does the corrections section use any of the four forbidden words?
     *
     * @return list<string>
     */
    public function forbiddenLanguageInCorrections(): array
    {
        $rendered = $this->render();
        $partE = substr($rendered, (int) strpos($rendered, 'PART E'));
        $found = [];

        foreach (self::FORBIDDEN_IN_CORRECTIONS as $word) {
            if (preg_match('/\b' . $word . '/i', $partE) === 1) {
                $found[] = $word;
            }
        }

        return $found;
    }
}
