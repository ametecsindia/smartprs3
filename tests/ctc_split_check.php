<?php
// Self-check for CtcSplit: CTC 30,000 a month, Basic 50% of gross, employer PF 12% of Basic, Special the balance.
// gross g with g + 0.06g = 30,000 → g = 28,301.89; Basic 14,150.94(5); employer PF 1,698.11 → g + PF = 30,000.00.
// Run: php tests/ctc_split_check.php   (prints OK or the first mismatch and exits 1)
declare(strict_types=1);
spl_autoload_register(static function (string $c): void {
    foreach (['SmartPRS\\Platform\\' => __DIR__ . '/../src/Platform/', 'SmartPRS\\Surface\\' => __DIR__ . '/../surface/'] as $p => $d) {
        if (str_starts_with($c, $p) && is_file($f = $d . str_replace('\\', '/', substr($c, strlen($p))) . '.php')) { require_once $f; }
    }
});
use SmartPRS\Platform\Payroll\SalaryComponent as C;
use SmartPRS\Platform\Payroll\PayrollRun;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;

$components = [
    new C(code: 'BASIC', kind: C::EARNING, basis: C::PERCENT_OF_GROSS, flags: ['prorates' => true], ratePercent: 50, sequence: 1),
    new C(code: 'PF_EMPLOYER', kind: C::EMPLOYER_CONTRIBUTION, basis: C::PERCENT_OF, ofComponent: 'BASIC', ratePercent: 12, sequence: 2),
    new C(code: 'PF_EMPLOYEE', kind: C::DEDUCTION, basis: C::PERCENT_OF, ofComponent: 'BASIC', ratePercent: 12, sequence: 3),
    new C(code: 'SPECIAL', kind: C::EARNING, basis: C::BALANCE, flags: ['prorates' => true], sequence: 4),
];
$ctc = Money::of('30000.00');
$g = SmartPRS\Surface\CtcSplit::gross($ctc, $components, '2026-10');
$slip = (new PayrollRun('2026-10', $components, [['employment_id' => 'x', 'gross' => $g, 'payable_days' => Rational::of(31), 'period_days' => 31]]))->payslips[0];
$cost = $slip->grossEarnings()->plus($slip->employerCost());
abs($cost->minus($ctc)->minor) <= 1 || exit("FAIL cost {$cost->format()} ≠ CTC {$ctc->format()} (gross {$g->format()})\n");
$g->minor === 2830189 || exit("FAIL gross {$g->format()}\n");
echo "OK\n";
