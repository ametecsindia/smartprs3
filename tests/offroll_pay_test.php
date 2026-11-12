<?php

declare(strict_types=1);

/**
 * OFF-ROLL AGENT PAYOUTS (23 Oct 2026) — pricing a month, slabs, pro-rating, TDS with the year
 * threshold and the no-PAN rate, vendor grouping, holds for an expired certificate, the
 * maker-checker on days and runs, mark-paid, and the mobile-app events. File store, no database.
 */

use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\Amount;
use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\OffrollPay;
use SmartPRS\Surface\Store;

$cfgFile = __DIR__ . '/../config/offroll-agents.json';
$declared = json_decode((string) file_get_contents($cfgFile), true);
$tdsCfg = $declared['tds'];

group('slabs');
same([], OffrollPay::parseSlabs(''), 'no slabs is an empty list');
same(null, OffrollPay::parseSlabs('[{"upto":"","percent":"2"},{"upto":"100","percent":"3"}]'), 'an open band must be last');
same(null, OffrollPay::parseSlabs('[{"upto":"200","percent":"2"},{"upto":"100","percent":"3"}]'), 'bands must rise');
$slabs = OffrollPay::parseSlabs('[{"upto":"100000","percent":"2"},{"upto":"","percent":"3"}]');
[$pay] = OffrollPay::slabPay(Amount::of('150000'), $slabs);
same('3500.00', $pay->toDecimalString(), 'tiered: 2% of the first 1,00,000 + 3% of the next 50,000');
[$pay] = OffrollPay::slabPay(Amount::of('40000'), $slabs);
same('800.00', $pay->toDecimalString(), 'inside the first band only');

group('plan in force');
$agent = ['id' => '1', 'name' => 'A', 'payout_type' => 'Per_visit', 'rate' => '40.0000'];
same('40.0000', OffrollPay::planOn([], $agent, '2026-10-05')['per_visit_rate'], 'no plan row: the register rate as a per-visit plan');
$plans = [['id' => '9', 'effective_from' => '2026-10-16', 'per_visit_rate' => '50'], ['id' => '8', 'effective_from' => '2026-10-01', 'per_visit_rate' => '40']];
same('8', OffrollPay::planOn($plans, $agent, '2026-10-15')['id'], 'the older plan up to the day before a revision');
same('9', OffrollPay::planOn($plans, $agent, '2026-10-16')['id'], 'the revision from its own date');
same(null, OffrollPay::planOn($plans, $agent, '2026-09-30'), 'before the first plan: nothing');

group('pricing a month');
$settings = ['fixed_basis' => 'prorata_fixed', 'fixed_days' => '26', 'count_basis' => 'approved', 'expired_certificate' => 'hold', 'tds_enabled' => 'yes', 'tds_section' => 'commission'];
$agent = ['id' => '1', 'name' => 'A', 'dra_expiry' => '2027-06-30', 'pcc_expiry' => '2027-03-31'];
$plans = [['id' => '5', 'effective_from' => '2026-01-01', 'fixed_monthly' => '6500', 'per_visit_rate' => '25', 'per_ptp_rate' => '10', 'collection_percent' => '1.5']];
$days = [];
for ($i = 1; $i <= 13; $i++) {
    $days[] = ['activity_date' => sprintf('2026-10-%02d', $i), 'day_status' => 'present', 'visits' => 8, 'ptp_count' => 2, 'paid_accounts' => 1, 'amount_collected' => '5000.0000'];
}
$days[] = ['activity_date' => '2026-10-14', 'day_status' => 'half_day', 'visits' => 3, 'ptp_count' => 0, 'paid_accounts' => 0, 'amount_collected' => ''];
$ledger = [['kind' => 'adjustment', 'amount' => '500.0000', 'entry_date' => '2026-10-10', 'note' => 'festival'], ['kind' => 'deduction', 'amount' => '200.0000', 'entry_date' => '2026-10-11'],
    ['kind' => 'recovery', 'amount' => '1000.0000', 'entry_date' => '2026-10-12', 'note' => 'advance']];
$p = OffrollPay::priceMonth($agent, $plans, $days, $ledger, $settings, $declared, '2026-10', '26', 30);
same('13.50', $p['days_present'], 'a half day is half');
same('3375.00', $p['fixed_amount'], 'fixed 6,500 × 13.5 ÷ 26');
same(107, $p['visits'], 'visits summed');
same('2675.00', $p['visit_amount'], '107 visits × 25');
same('260.00', $p['ptp_amount'], '26 PTPs × 10');
same('975.00', $p['collection_amount'], '1.5% of 65,000');
same('7585.00', $p['gross'], 'earned 7,285 + 500 adjustment − 200 deduction');
same('1000.00', $p['recoveries'], 'the recovery is kept apart — after TDS');
same([], $p['flags'], 'certificates valid: no flag');
$p2 = OffrollPay::priceMonth($agent, $plans, $days, [], ['fixed_basis' => 'full'] + $settings, $declared, '2026-10', '26', 30);
same('6500.00', $p2['fixed_amount'], 'full fixed when Organization Configuration says so');
$p3 = OffrollPay::priceMonth(['dra_expiry' => '2026-10-10'] + $agent, $plans, $days, [], $settings, $declared, '2026-10', '26', 30);
ok($p3['flags'] !== [] && str_contains($p3['flags'][0], 'DRA'), 'worked after the DRA expired: flagged');
$capped = OffrollPay::priceMonth($agent, [['monthly_cap' => '5000'] + $plans[0]], $days, [], $settings, $declared, '2026-10', '26', 30);
same('5000.00', $capped['gross'], 'the monthly cap holds');

group('TDS');
$t = OffrollPay::tds(Amount::of('15000'), Money::zero(), Money::zero(), 'commission', 'individual', true, $tdsCfg);
same('0.00', $t['tds'], 'commission below the year threshold: none');
$t = OffrollPay::tds(Amount::of('8000'), Amount::of('15000'), Amount::of('15000'), 'commission', 'individual', true, $tdsCfg);
same('460.00', $t['tds'], 'crossing the year: 2% of this run + what went untaxed earlier (23,000)');
$t = OffrollPay::tds(Amount::of('8000'), Amount::of('15000'), Amount::of('15000'), 'commission', 'individual', false, $tdsCfg);
same('4600.00', $t['tds'], 'no PAN: 20%');
$t = OffrollPay::tds(Amount::of('35000'), Money::zero(), Money::zero(), 'contract', 'other', true, $tdsCfg);
same('700.00', $t['tds'], 'contract single sum above 30,000, firm: 2%');
$t = OffrollPay::tds(Amount::of('25000'), Money::zero(), Money::zero(), 'contract', 'individual', true, $tdsCfg);
same('0.00', $t['tds'], 'contract under both thresholds: none');
same(['2026-04', '2027-03'], OffrollPay::fyOf('2026-10', 4), 'financial year from April');
same(['2026-04', '2027-03'], OffrollPay::fyOf('2027-02', 4), 'February is the same year');

group('end to end on a file store');
$dir = sys_get_temp_dir() . '/offroll-test-' . bin2hex(random_bytes(4));
mkdir($dir);
$store = (new Store($dir))->forTenant('1');
$pay = new OffrollPay($store, new Customisation($store), $cfgFile);
$store->put('offroll_vendors', ['name' => 'Sai Field Services', 'pan' => 'AAAFS1234K', 'entity_type' => 'other', 'tds_section' => 'contract', 'status' => 'Active']);
$a1 = $store->put('offroll_agents', ['name' => 'Ravi', 'agent_code' => 'OFR-0001', 'employer_company_id' => '7', 'paid_to' => 'agent', 'pan' => 'ABCPR1234K', 'dra_expiry' => '2027-01-01', 'pcc_expiry' => '2027-01-01', 'status' => 'Active']);
$a2 = $store->put('offroll_agents', ['name' => 'Sita', 'agent_code' => 'OFR-0002', 'employer_company_id' => '7', 'paid_to' => 'vendor', 'vendor_id' => '1', 'dra_expiry' => '2026-09-01', 'pcc_expiry' => '2027-01-01', 'status' => 'Active']);
same('OFR-0003', $pay->nextCode(), 'the next code in the series');
ok($pay->savePlan($a1, ['effective_from' => '01-09-2026', 'fixed_monthly' => '8000', 'per_visit_rate' => '20'], 'hr@x')['ok'], 'a plan saves (dd-mm-yyyy accepted)');
ok(!$pay->savePlan($a1, ['effective_from' => '01-09-2026'], 'hr@x')['ok'], 'a plan that pays nothing is refused');
$pay->savePlan($a2, ['effective_from' => '2026-09-01', 'per_visit_rate' => '30', 'collection_slabs' => '[{"upto":"50000","percent":"1"},{"upto":"","percent":"2"}]'], 'hr@x');
ok(!$pay->saveDay($a1, ['activity_date' => date('Y-m-d', strtotime('+2 days')), 'day_status' => 'present'], 'hr@x', 'manual')['ok'], 'no activity for a future day');
ok(!$pay->saveDay($a1, ['activity_date' => '2026-09-02', 'day_status' => 'absent', 'visits' => '3'], 'hr@x', 'manual')['ok'], 'an absent day cannot carry visits');
$ids = [];
foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $dt) {
    $ids[] = (string) $pay->saveDay($a1, ['activity_date' => $dt, 'day_status' => 'present', 'check_in' => '09:30', 'check_out' => '18:10', 'visits' => '10', 'amount_collected' => '4000'], 'hr@x', 'manual')['row']['id'];
    $ids[] = (string) $pay->saveDay($a2, ['activity_date' => $dt, 'day_status' => 'present', 'visits' => '12', 'amount_collected' => '30000'], 'hr@x', 'upload')['row']['id'];
}
same(0, $pay->decideDays($ids, true, 'hr@x', '', false)['done'], 'the recorder cannot approve their own days');
same(6, $pay->decideDays($ids, true, 'manager@x', '', false)['done'], 'a second person approves');
$r = $pay->generate('7', '2026-09', 'hr@x', static fn (): string => '26', 4, 30);
ok($r['ok'], 'September payout generated: ' . $r['why']);
$lines = $pay->lines((string) $r['run']['id']);
same(2, count($lines), 'one line per agent');
$byAgent = array_column($lines, null, 'agent_id');
$l1 = $byAgent[(string) $a1['id']];
same('923.08', $l1['fixed_amount'], 'fixed 8,000 × 3 ÷ 26');
same('600.00', $l1['visit_amount'], '30 visits × 20');
same('1523.08', $l1['gross'], 'gross');
same('pending', $l1['payment_status'], 'valid certificates: pending payment');
$l2 = $byAgent[(string) $a2['id']];
same('vendor', $l2['paid_to'], 'the vendor-paid agent');
same('1080.00', $l2['visit_amount'], '36 visits × 30');
same('1300.00', $l2['collection_amount'], 'slabs on 90,000: 1% of 50,000 + 2% of 40,000');
same('on_hold', $l2['payment_status'], 'DRA expired while working: held (Organization Configuration says hold)');
ok(!$pay->approve((string) $r['run']['id'], 'hr@x', false)['ok'], 'the generator does not approve the run');
ok($pay->approve((string) $r['run']['id'], 'admin@x', true)['ok'], 'the Administrator may (D-OWN-01)');
ok(!$pay->saveDay($a1, ['activity_date' => '2026-09-01', 'day_status' => 'absent'], 'hr@x', 'manual')['ok'], 'a day inside an approved payout cannot change');
ok(!$pay->generate('7', '2026-09', 'hr@x', static fn (): string => '26', 4, 30)['ok'], 'an approved run is not regenerated');
ok(!$pay->markPaid([(string) $l2['id']], date('Y-m-d'), 'neft', 'UTR1', 'acc@x')['ok'], 'a held line is not paid');
ok($pay->markPaid([(string) $l1['id']], date('Y-m-d'), 'neft', 'UTR123456', 'acc@x')['ok'], 'a pending line is paid');
same('paid', $store->find('offroll_payout_lines', (string) $l1['id'])['payment_status'], 'and reads paid');

group('mobile app events');
$ev = $pay->ingestApp([
    ['external_id' => 'e1', 'type' => 'login', 'agent_code' => 'OFR-0001', 'at' => date('Y-m-d') . 'T09:12:00+05:30', 'lat' => 17.45, 'lng' => 78.36],
    ['external_id' => 'e2', 'type' => 'visit', 'agent_code' => 'OFR-0001', 'at' => date('Y-m-d') . 'T10:40:00+05:30', 'outcome' => 'paid', 'amount' => '2500', 'account_ref' => 'LN123'],
    ['external_id' => 'e2', 'type' => 'visit', 'agent_code' => 'OFR-0001', 'at' => date('Y-m-d') . 'T10:40:00+05:30', 'outcome' => 'paid', 'amount' => '2500'],
    ['external_id' => 'e3', 'type' => 'visit', 'agent_code' => 'NOPE', 'at' => date('Y-m-d') . 'T10:40:00+05:30'],
    ['external_id' => 'e4', 'type' => 'visit', 'agent_code' => 'OFR-0001', 'at' => date('Y-m-d') . 'T10:40:00'],
]);
same(['accepted', 'accepted', 'duplicate', 'rejected', 'rejected'], array_column($ev, 'status'), 'accepted, a resend is a duplicate, unknown agent and a time with no offset refused');
$today = $pay->day((string) $a1['id'], substr((string) (new DateTimeImmutable(date('Y-m-d') . 'T10:40:00+05:30'))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d'), 0, 10));
ok($today !== null && (int) $today['visits'] === 1 && (int) $today['paid_accounts'] === 1, 'the visit rolled up into the day');

array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);
