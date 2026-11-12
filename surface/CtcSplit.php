<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Payroll\PayrollRun;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;

/**
 * CTC INCLUDES THE EMPLOYER'S CONTRIBUTIONS (owner, 15 Oct: "deductions should be aggregate of all
 * deductions including PF employee, PF employer, PT…"). The month's CTC is then gross earnings PLUS the
 * employer lines (PF / ESI / NPS employer…), so the gross the engine splits into Basic, HRA and the
 * balancing Special allowance is the CTC LESS what the employer pays on top. Employer PF is a
 * percentage of Basic, Basic a percentage of gross — so the gross is found as a fixed point:
 * gross = CTC − employer(gross), iterated on a full month until it stops moving.
 */
final class CtcSplit
{
    /** @param list<\SmartPRS\Platform\Payroll\SalaryComponent> $components the employee's own resolved structure */
    public static function gross(Money $monthCtc, array $components, string $period): Money
    {
        if ($components === [] || $monthCtc->isZero() || $monthCtc->isNegative()) {
            return $monthCtc;
        }

        $g = $monthCtc;

        // ponytail: 25 rounds; employer contributions grow far slower than gross (12% of a part of it),
        // so this settles in 3–5. A paisa-level two-cycle, if one ever occurs, ends on the last value.
        for ($i = 0; $i < 25; $i++) {
            $slip = (new PayrollRun($period, $components, [[
                'employment_id' => 'ctc-split', 'gross' => $g, 'payable_days' => Rational::of(30), 'period_days' => 30,
            ]]))->payslips[0];
            $next = $monthCtc->minus($slip->employerCost());

            if ($next->equals($g) || $next->isNegative()) {
                return $next->isNegative() ? $g : $next;
            }

            $g = $next;
        }

        return $g;
    }
}
