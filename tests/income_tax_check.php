<?php
// Self-check for SmartPRS\Surface\IncomeTax against hand-worked FY 2026-27 figures.
// Run: php tests/income_tax_check.php   (prints OK or the first mismatch and exits 1)
declare(strict_types=1);
require_once __DIR__ . '/../src/Platform/Support/Money.php';
foreach (['Rational', 'RoundingMode'] as $c) { is_file($f = __DIR__ . "/../src/Platform/Support/{$c}.php") && require_once $f; }
require_once __DIR__ . '/../surface/Amount.php';
require_once __DIR__ . '/../surface/IncomeTax.php';
use SmartPRS\Surface\IncomeTax as T;

$y = T::year(__DIR__ . '/../config/income-tax.json', '2026-27');
$fail = static function (string $what, $got, $want): never { fwrite(STDERR, "FAIL {$what}: got {$got}, want {$want}\n"); exit(1); };
$eq = static function (string $what, int $got, int $want) use ($fail): void { $got === $want || $fail($what, $got / 100, $want / 100); };
$r = static fn (int $rupees): int => $rupees * 100;

// New regime, 12,75,000 gross: taxable 12,00,000 → full rebate → nil.
$a = T::compute($y, 'new', ['gross' => $r(1275000)]);
$eq('new 12.75L taxable', $a['taxable'], $r(1200000));
$eq('new 12.75L total', $a['total'], 0);

// New regime, 13,00,000 gross: taxable 12,25,000; slab tax 63,750; marginal relief caps at 25,000; cess 1,000 → 26,000.
$b = T::compute($y, 'new', ['gross' => $r(1300000)]);
$eq('new 13L taxable', $b['taxable'], $r(1225000));
$eq('new 13L total', $b['total'], $r(26000));

// New regime, 20,75,000 gross: taxable 20,00,000; tax 20k+40k+60k+80k = 2,00,000; cess 8,000 → 2,08,000.
$c = T::compute($y, 'new', ['gross' => $r(2075000)]);
$eq('new 20.75L total', $c['total'], $r(208000));

// Old regime, 10,00,000 gross, PF 21,600 + PPF 1,50,000 (80C capped 1.5L), 80D 25,000, PT 2,400:
// taxable = 10,00,000 − 50,000 − 2,400 − 1,50,000 − 25,000 = 7,72,600; tax 12,500 + 20% × 2,72,600 = 67,020; cess 2,680.80 → 69,700.80 → 69,700.
$d = T::compute($y, 'old', ['gross' => $r(1000000), 'employee_pf' => $r(21600), 'pt' => $r(2400),
    'items' => ['PPF' => $r(150000), 'MED_SELF' => $r(25000)]]);
$eq('old 10L taxable', $d['taxable'], $r(772600));
$eq('old 10L total', $d['total'], $r(69700));

// Old regime HRA: basic 4,80,000, HRA 2,40,000, rent 2,40,000 metro → least of 2,40,000 / 1,92,000 / 2,40,000 = 1,92,000.
$e = T::compute($y, 'old', ['gross' => $r(900000), 'basic_da' => $r(480000), 'hra_received' => $r(240000), 'rent_annual' => $r(240000), 'metro' => true]);
$eq('old HRA exempt', $e['exempt']['HRA'], $r(192000));

// Monthly TDS: 26,000 a year, nothing paid, from October (6 months left) → 4,334 (rounded up so the year is covered).
$eq('months left Oct', T::monthsLeft('2026-10'), 6);
$eq('months left Apr', T::monthsLeft('2026-04'), 12);
$eq('months left Mar', T::monthsLeft('2027-03'), 1);
$eq('monthly', T::monthly($r(26000), 0, 6), $r(4334));
// Non-resident, new regime, 12,75,000: taxable 12,00,000, no rebate → 60,000 + 2,400 cess = 62,400.
$n = T::compute($y, 'new', ['gross' => $r(1275000), 'resident' => false]);
$eq('non-resident no rebate', $n['total'], $r(62400));
// WHY: 3,58,516 gross new regime → taxable 2,83,520, inside the 4L nil slab; 10L → rebated to nil.
$w = T::compute($y, 'new', ['gross' => $r(358516)]);
str_contains($w['why'], 'nil slab') || exit("FAIL why nil slab: {$w['why']}\n");
$w = T::compute($y, 'new', ['gross' => $r(1000000)]);
str_contains($w['why'], 'fully rebated') || exit("FAIL why rebate: {$w['why']}\n");
echo "OK\n";
