<?php
// Self-check for gratuity under the Code on Social Security 2020 (config/gratuity.json).
// Run: php tests/gratuity_check.php   (prints OK or the first mismatch)
declare(strict_types=1);
spl_autoload_register(static function (string $c): void {
    foreach (['SmartPRS\\Platform\\' => __DIR__ . '/../src/Platform/', 'SmartPRS\\Surface\\' => __DIR__ . '/../surface/'] as $p => $d) {
        if (str_starts_with($c, $p) && is_file($f = $d . str_replace('\\', '/', substr($c, strlen($p))) . '.php')) { require_once $f; }
    }
});
use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\StatutoryReports as R;
$eq = static function (string $what, $got, $want): void { $got === $want || exit("FAIL {$what}: got " . var_export($got, true) . ' want ' . var_export($want, true) . "\n"); };
$cfg = R::config(__DIR__ . '/../config/gratuity.json');
$w = Money::of('26000.00');                                   // 26000/26 = 1000 a day → 15000 a counted year
// Eligibility is FULL years — 4y 11m is not five years.
$eq('4y11m not eligible', R::gratuity($cfg, $w, '2021-11-01', '2026-10-16')['eligible'], false);
$eq('5y eligible', R::gratuity($cfg, $w, '2021-10-16', '2026-10-16')['eligible'], true);
// Part year counts only when MORE than six months.
$eq('7y 6m 0d → 7 years', R::gratuity($cfg, $w, '2019-04-16', '2026-10-16')['years'], 7);
$eq('7y 6m 1d → 8 years', R::gratuity($cfg, $w, '2019-04-15', '2026-10-16')['years'], 8);
$eq('7y 6m 0d amount', R::gratuity($cfg, $w, '2019-04-16', '2026-10-16')['amount']->toDecimalString(), '105000.00');
$eq('7y 6m 27d amount', R::gratuity($cfg, $w, '2019-03-19', '2026-10-15')['amount']->toDecimalString(), '120000.00');
// Fixed term: one year; death / disablement: no minimum.
$eq('fixed term 1y', R::gratuity($cfg, $w, '2025-10-01', '2026-10-16', 'Fixed Term')['eligible'], true);
$eq('permanent 1y', R::gratuity($cfg, $w, '2025-10-01', '2026-10-16', 'Permanent')['eligible'], false);
$eq('death 2y', R::gratuity($cfg, $w, '2024-10-01', '2026-10-16', 'Permanent', 'Death')['eligible'], true);
// Cap.
$eq('cap', R::gratuity($cfg, Money::of('500000.00'), '1990-01-01', '2026-10-16')['amount']->toDecimalString(), '2000000.00');
// Wages: Basic + DA, rate not amount, 50% add-back.
$run = ['period' => '2026-09', 'rows' => ['E1' => ['period_days' => '30', 'payable_days' => '15', 'lines' => [
    ['code' => 'BASIC', 'kind' => 'earning', 'minor' => 1000000], ['code' => 'DA', 'kind' => 'earning', 'minor' => 200000],
    ['code' => 'HRA', 'kind' => 'earning', 'minor' => 600000], ['code' => 'SPECIAL', 'kind' => 'earning', 'minor' => 1200000],
    ['code' => 'REIMBURSEMENT', 'kind' => 'earning', 'minor' => 500000], ['code' => 'PF_EMPLOYEE', 'kind' => 'deduction', 'minor' => 120000]]]]];
// Half month paid → rate doubles: wages 24000, remuneration 60000, excluded 36000 > 30000 → add 6000 → 30000.
$eq('wages rate + 50% rule', R::lastDrawnWages('E1', Money::zero(), [$run], [], $cfg, '2026-10-16')['wage']->toDecimalString(), '30000.00');
// Before 21-11-2025 no add-back.
$eq('before code', R::lastDrawnWages('E1', Money::zero(), [array_replace($run, ['period' => '2025-09'])], [], $cfg, '2025-10-16')['wage']->toDecimalString(), '24000.00');
echo "OK\n";
