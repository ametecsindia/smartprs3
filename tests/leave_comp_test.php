<?php

declare(strict_types=1);

/**
 * LEAVE + COMPENSATION & CLAIMS — the arithmetic behind the hand-built screens.
 *
 * Every figure here is a rupee amount a person can check by hand: a 2% rate on
 * ₹1,23,456.78 is ₹2,469.14 (2,469.1356 rounds half-up), a ₹47,500 loan at
 * ₹4,000 a month ends with a twelfth instalment of ₹3,500, and a slab rate
 * applies to the WHOLE figure of the band it falls in.
 */

use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\Compensation;
use SmartPRS\Surface\Store;

group('leave-comp');

$root = __DIR__ . '/..';
$cfg = $root . '/config/compensation.json';

// ---- commission: percent / fixed / slab / open ------------------------------
$r = Compensation::commission(['rate_type' => 'percent', 'rate_value' => '2'], '123456.78');
same('₹2,469.14', $r['gross']->format(), '2% of ₹1,23,456.78, half-up to the paisa');
ok(str_contains($r['working'], '× 2%'), 'the working names the rate');
ok($r['paid'], 'a non-zero gross is paid');

$r = Compensation::commission(['rate_type' => 'fixed', 'rate_value' => '250'], '99999');
same('₹250.00', $r['gross']->format(), 'a fixed scheme pays its rupees regardless of the figure');

$slab = ['rate_type' => 'slab', 'slabs' => json_encode([
    ['upto' => '', 'rate' => '3'], ['upto' => '100000', 'rate' => '1'], ['upto' => '200000', 'rate' => '2'],
])];
same('₹1,000.00', Compensation::commission($slab, '100000')['gross']->format(), 'exactly on the limit belongs to the lower band');
same('₹3,000.02', Compensation::commission($slab, '150001')['gross']->format(), 'the band rate applies to the WHOLE figure, not the excess');
same('₹9,000.00', Compensation::commission($slab, '300000')['gross']->format(), 'above the last limit: the open-ended band, wherever it was typed');
same([['upto' => '100000', 'rate' => '1'], ['upto' => '200000', 'rate' => '2'], ['upto' => '', 'rate' => '3']],
    Compensation::slabsOf($slab), 'bands are ordered by limit with the open one last');
$noTop = Compensation::commission(['rate_type' => 'slab', 'slabs' => '[{"upto":"1000","rate":"5"}]'], '5000');
ok(!$noTop['paid'] && $noTop['gross']->isZero(), 'a figure no band covers pays nothing rather than guessing a rate');

$r = Compensation::commission(['rate_type' => 'open'], '0', '', '1234.50');
same('₹1,234.50', $r['gross']->format(), 'an open scheme pays the typed amount');

// ---- the target gate ---------------------------------------------------------
$gated = ['rate_type' => 'percent', 'rate_value' => '5', 'threshold' => '80'];
$r = Compensation::commission($gated, '79999', '100000');
same('79.99', $r['achievement'], 'achievement is shown truncated to two places');
ok(!$r['paid'] && $r['gross']->isZero(), '79.999% is below an 80% gate — nothing paid');
$r = Compensation::commission($gated, '80000', '100000');
same('80', $r['achievement'], 'exactly 80% — a whole number prints whole');
same('₹4,000.00', $r['gross']->format(), 'on the gate pays');
$r = Compensation::commission($gated, '80000', '');
ok(!$r['paid'] && str_contains($r['working'], 'no target'), 'a gate with no target refuses rather than assumes');

// ---- TDS 194H ----------------------------------------------------------------
$t = Compensation::tds(Money::of('2469.14'), '2');
same('₹49.38', $t['tds']->format(), '2% TDS on ₹2,469.14 (49.3828 → 49.38)');
same('₹2,419.76', $t['net']->format(), 'net = gross − TDS');
same('₹2,469.14', Compensation::tds(Money::of('2469.14'), '')['net']->format(), 'no rate = no TDS');
same('2', Compensation::rateText('2.0000'), 'a DECIMAL(18,4) rate reads as typed');
ok(str_contains(Compensation::tds(Money::of('100'), '2.5000')['working'], 'TDS 2.5%'), 'and the working says 2.5%, not 2.5000%');
throws(InvalidArgumentException::class, static fn () => Compensation::commission(['rate_type' => 'percent', 'rate_value' => '2'], '1,2x'), 'a figure that is not money is refused');

// ---- loan EMI schedule -------------------------------------------------------
$s = Compensation::schedule('47500', '4000', '2026-01');
same(12, count($s), '₹47,500 at ₹4,000 a month is twelve instalments');
same('2026-01', $s[0]['month'], 'starts at the start month');
same('2026-12', $s[11]['month'], 'and walks the calendar, one instalment a month');
same('₹4,000.00', $s[0]['instalment']->format(), 'the standing EMI');
same('₹3,500.00', $s[11]['instalment']->format(), 'the LAST instalment is the remainder, never ₹4,000');
same('₹0.00', $s[11]['after']->format(), 'and it clears the loan');
same('₹43,500.00', $s[0]['after']->format(), 'outstanding after the first');
same('₹47,500.00', Money::sum(...array_map(static fn (array $x): Money => $x['instalment'], $s))->format(), 'the instalments sum exactly to the principal');
same(3, count(Compensation::schedule('10000.01', '4000', '2025-11')), 'a paisa over eight thousand needs a third instalment');
same('2026-01', Compensation::schedule('10000.01', '4000', '2025-11')[2]['month'], 'and the month rolls over the year end');
same([], Compensation::schedule('0', '4000', '2026-01'), 'nothing owed: no schedule');
same([], Compensation::schedule('5000', '0', '2026-01'), 'no EMI: no schedule rather than an endless one');
throws(InvalidArgumentException::class, static fn () => Compensation::schedule('5000', '500', 'Jan 2026'), 'the start month must be YYYY-MM');

// ---- recovery read from the closed runs, reversed months excluded ----------
$store = (new Store(sys_get_temp_dir() . '/prs3-lc-' . bin2hex(random_bytes(4))))->forTenant('1');
$put = static function (string $period, int $minor) use ($store): void {
    $store->put('payroll_runs', ['id' => 'R-' . $period, 'period' => $period, 'status' => 'approved',
        'rows' => ['SP-001' => ['name' => 'K', 'lines' => [
            ['code' => 'LOAN-7', 'kind' => 'deduction', 'minor' => $minor, 'amount' => '', 'trace' => ''],
            ['code' => 'ADV-2', 'kind' => 'deduction', 'minor' => 100, 'amount' => '', 'trace' => ''],
        ]]]]);
};
$put('2026-01', 400000);
$put('2026-02', 400000);
$put('2026-03', 400000);
$store->put('payroll_corrections', ['id' => 'C1', 'period' => '2026-02', 'new_fingerprint' => 'reversal']);
$comp = new Compensation($store, $cfg);
same(['2026-01' => 400000, '2026-03' => 400000], $comp->recoveredByPeriod('SP-001', 'LOAN-7'), 'two months recovered; the reversed February is not counted');
same([], $comp->recoveredByPeriod('SP-002', 'LOAN-7'), 'another employment: nothing');
same([], $comp->recoveredByPeriod('SP-001', 'LOAN-8'), 'another loan: nothing');
same(['pending', 'claimed'], $comp->config()['claims']['expenses']['pending'], 'the claims vocabulary is configuration');

// ---- bank payout pack: BankAdvice's CSV for an approved run ----------------
$run = ['period' => '2026-03', 'approved_by' => 'x', 'payable_minor' => 3528387 + 0 + -500,
    'rows' => ['SP-001' => ['name' => 'K, Sharma', 'net_minor' => 3528387], 'SP-002' => ['name' => 'R', 'net_minor' => -500], 'SP-003' => ['name' => 'Z', 'net_minor' => 0]]];
$pack = SmartPRS\Surface\BankAdvice::generate($run, ['SP-001' => ['account' => '123', 'ifsc' => 'HDFC0000001'], 'SP-002' => ['account' => '9', 'ifsc' => 'X'], 'SP-003' => ['account' => '9', 'ifsc' => 'X']]);
ok($pack['ok'], 'an approved run that reconciles produces the pack');
same("employee,name,account,ifsc,amount,of_which_arrears\nSP-001,\"K, Sharma\",123,HDFC0000001,35283.87,0.00\n", $pack['csv'], 'one credit line, plain decimals, the comma in the name quoted');
same(2, count($pack['recoveries']), 'the negative and the nil net are listed, not dropped');
ok(!SmartPRS\Surface\BankAdvice::generate(['period' => '2026-03', 'rows' => []], [])['ok'], 'an unapproved run produces no file');
