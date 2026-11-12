<?php

declare(strict_types=1);

/**
 * SAAS PLATFORM — what computes behind the hand-built screens.
 *
 * MRR adds up live subscriptions at plan price; GST splits by state; a
 * quotation is plan × seats × months less coupon plus tax; a coupon says why
 * it cannot be used; a flag resolves tenant → global → default; the dashboard
 * counts what the store holds.
 */

use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\SaasPlatform;
use SmartPRS\Surface\Store;

group('platform-section');

$root = dirname(__DIR__);
$platform = new Store(sys_get_temp_dir() . '/prs3-platform-' . bin2hex(random_bytes(4)));
$saas = new SaasPlatform($platform, $root . '/config');

$growth = ['id' => '1', 'name' => 'Growth', 'base_price' => '2500.0000', 'per_user_price' => '40.0000', 'seat_max' => '25',
    'features' => '{"modules":["attendance","payroll"]}'];
$unlimited = ['id' => '2', 'name' => 'Enterprise', 'base_price' => '9000.0000', 'per_user_price' => '10.0000', 'seat_max' => null];

// ---- plan price -----------------------------------------------------------
same('2500.00', SaasPlatform::monthly($growth, 25)->toDecimalString(), 'seats within the cap: the base price');
same('2500.00', SaasPlatform::monthly($growth, 3)->toDecimalString(), 'fewer seats than the cap never discounts');
same('2900.00', SaasPlatform::monthly($growth, 35)->toDecimalString(), '10 over the cap × 40');
same('9000.00', SaasPlatform::monthly($unlimited, 900)->toDecimalString(), 'no cap: no extras, ever');
same(['attendance', 'payroll'], SaasPlatform::planModules($growth), 'modules read from the features JSON');
same([], SaasPlatform::planModules($unlimited), 'no features: no modules');
same(12, $saas->months('annual'), 'annual bills twelve months (config/plans.json)');
same(1, $saas->months('weird'), 'an unknown cycle is one month');

// ---- MRR ------------------------------------------------------------------
$subs = [
    ['id' => '1', 'tenant_id' => '1', 'plan_id' => '1', 'seats' => 35, 'status' => 'active'],
    ['id' => '2', 'tenant_id' => '2', 'plan_id' => '2', 'seats' => 300, 'status' => 'trial'],
    ['id' => '3', 'tenant_id' => '3', 'plan_id' => '1', 'seats' => 10, 'status' => 'cancelled'],
    ['id' => '4', 'tenant_id' => '4', 'plan_id' => '77', 'seats' => 10, 'status' => 'active'],
];
$mrr = $saas->mrr($subs, [$growth, $unlimited]);
same('11900.00', $mrr['mrr']->toDecimalString(), 'MRR = 2900 + 9000; cancelled and unpriced add nothing');
same(3, $mrr['live'], 'three live subscriptions (trial counts, cancelled does not)');
same(1, $mrr['unpriced'], 'one live subscription points at a plan that is gone — said, not hidden');

// ---- GST ------------------------------------------------------------------
same('36', $saas->stateCode('Telangana'), 'state name → code');
same('36', $saas->stateCode('Telangana (36)'), 'PRS2 stored "Name (code)"');
same('27', $saas->stateCode('27'), 'a code is a code');
same('', $saas->stateCode('Atlantis'), 'unknown state: no code, taxed as IGST');
$intra = $saas->tax(Money::of('1000.00'), 'Telangana');
same('CGST+SGST', $intra['mode'], 'home state splits');
same('90.00', $intra['cgst']->toDecimalString(), 'CGST is half of 18%');
same('180.00', $intra['tax']->toDecimalString(), 'tax total 18%');
same('1180.00', $intra['total']->toDecimalString(), 'total with tax');
$inter = $saas->tax(Money::of('1000.00'), 'Maharashtra');
same('IGST', $inter['mode'], 'another state is IGST');
same('180.00', $inter['igst']->toDecimalString(), 'IGST at the full rate');
same('0.00', $inter['cgst']->toDecimalString(), 'no CGST on an inter-state invoice');
$odd = $saas->tax(Money::of('33.33'), 'Telangana');
same('6.00', $odd['tax']->toDecimalString(), 'each half rounded on its own (3.00 + 3.00) so the printed lines add up');

// ---- coupons --------------------------------------------------------------
$coupon = ['code' => 'LAUNCH10', 'type' => 'percent', 'value' => '10.00', 'valid_from' => '2026-09-01', 'valid_till' => '2026-09-30',
    'max_uses' => 20, 'used_count' => 3, 'applies_to' => 'signup', 'plan_ids' => '1,2', 'status' => 'active'];
same(null, SaasPlatform::couponProblem($coupon, '2026-09-09', 'signup', '1'), 'valid today, in window, under the limit');
ok(SaasPlatform::couponProblem($coupon, '2026-08-31') !== null, 'before valid_from');
ok(SaasPlatform::couponProblem($coupon, '2026-10-01') !== null, 'after valid_till');
ok(SaasPlatform::couponProblem(['used_count' => 20] + $coupon, '2026-09-09') !== null, 'used up');
ok(SaasPlatform::couponProblem($coupon, '2026-09-09', 'renewal') !== null, 'signup-only coupon on a renewal');
ok(SaasPlatform::couponProblem($coupon, '2026-09-09', 'signup', '9') !== null, 'not for this plan');
ok(SaasPlatform::couponProblem(['status' => 'disabled'] + $coupon, '2026-09-09') !== null, 'disabled');
same(null, SaasPlatform::couponProblem(['max_uses' => 0, 'plan_ids' => '', 'applies_to' => 'both'] + $coupon, '2026-09-09', 'renewal', '9'), 'no limits: valid anywhere');
same('250.00', SaasPlatform::discount($coupon, Money::of('2500.00'))->toDecimalString(), '10% off');
same('300.00', SaasPlatform::discount(['type' => 'flat', 'value' => '300'], Money::of('2500.00'))->toDecimalString(), 'flat ₹300');
same('100.00', SaasPlatform::discount(['type' => 'flat', 'value' => '300'], Money::of('100.00'))->toDecimalString(), 'a flat discount never exceeds the subtotal');
same('0.03', SaasPlatform::discount(['type' => 'percent', 'value' => '33.33'], Money::of('0.10'))->toDecimalString(), 'percent rounds half-up to paise');

// ---- quotations -----------------------------------------------------------
$q = $saas->quote($growth, 35, 'annual', $coupon, 'Telangana');
same(12, $q['months'], 'annual = 12 months');
same('34800.00', $q['subtotal']->toDecimalString(), '2900 × 12');
same('3480.00', $q['discount']->toDecimalString(), '10% coupon');
same('31320.00', $q['taxable']->toDecimalString(), 'subtotal less discount');
same('5637.60', $q['tax']->toDecimalString(), '18% GST split in two');
same('36957.60', $q['total']->toDecimalString(), 'the figure on the quote');
$q2 = $saas->quote($growth, 10, 'quarterly', null, 'Karnataka', '500');
same('7500.00', $q2['subtotal']->toDecimalString(), '2500 × 3');
same('500.00', $q2['discount']->toDecimalString(), 'a typed flat discount');
same('IGST', $q2['mode'], 'Karnataka is inter-state');
same('8260.00', $q2['total']->toDecimalString(), '7000 + 18%');
same('0.00', $saas->quote($growth, 10, 'monthly', null, 'Telangana', '99999')['taxable']->toDecimalString(), 'a discount larger than the price is capped, never negative');

// ---- invoices -------------------------------------------------------------
same('SP-2026-000042', $saas->invoiceNumber(42, '2026-09-09'), 'prefix-year-sequence');
$invoice = ['id' => '5', 'amount' => '1000.0000', 'tax' => '180.0000'];
$payments = [['invoice_id' => '5', 'amount' => '500.0000', 'status' => 'succeeded'], ['invoice_id' => '5', 'amount' => '680', 'status' => 'failed'], ['invoice_id' => '6', 'amount' => '680', 'status' => 'succeeded']];
same('680.00', SaasPlatform::balance($invoice, $payments)->toDecimalString(), 'only succeeded payments against THIS invoice count');

// ---- feature flags --------------------------------------------------------
$rows = [
    ['flag_key' => 'whatsapp_alerts', 'tenant_ref' => null, 'value' => 'on'],
    ['flag_key' => 'whatsapp_alerts', 'tenant_ref' => '2', 'value' => 'off'],
    ['flag_key' => 'coupons', 'tenant_ref' => '3', 'value' => 'off'],
];
same('off', $saas->flagState('whatsapp_alerts', '2', $rows), 'the tenant row wins over global');
same('on', $saas->flagState('whatsapp_alerts', '1', $rows), 'no tenant row: the global row');
same('on', $saas->flagState('whatsapp_alerts', null, $rows), 'global view: the global row');
same('on', $saas->flagState('coupons', '1', $rows), 'no rows at all: the config default');
same('off', $saas->flagState('coupons', '3', $rows), 'a tenant switched it off');
same('off', $saas->flagState('made_up', '1', $rows), 'an undeclared key is off');

// ---- licences -------------------------------------------------------------
$lic = [['id' => 1, 'amc_expires_on' => '2026-09-20', 'status' => 'active'], ['id' => 2, 'amc_expires_on' => '2026-12-01', 'status' => 'active'],
    ['id' => 3, 'amc_expires_on' => '2026-01-01', 'status' => 'active'], ['id' => 4, 'amc_expires_on' => '2026-09-10', 'status' => 'revoked'], ['id' => 5, 'status' => 'active']];
same([1, 3], array_map(static fn (array $l): int => (int) $l['id'], SaasPlatform::expiring($lic, '2026-09-09', 30)), 'within 30 days or already lapsed; revoked and undated ignored');

// ---- dashboard over a store -------------------------------------------------
$platform->put('tenants', ['id' => '1', 'code' => 'A', 'name' => 'A', 'status_code' => 'active']);
$platform->put('tenants', ['id' => '2', 'code' => 'B', 'name' => 'B', 'status_code' => 'suspended']);
$platform->put('plans', $growth);
$platform->put('subscriptions', ['id' => '1', 'tenant_id' => '1', 'plan_id' => '1', 'seats' => 30, 'status' => 'active']);
$platform->put('invoices', ['id' => '1', 'tenant_id' => '1', 'amount' => '2700', 'tax' => '486', 'status' => 'issued', 'due_on' => '2026-09-01']);
$platform->put('invoices', ['id' => '2', 'tenant_id' => '1', 'amount' => '100', 'tax' => '18', 'status' => 'paid', 'due_on' => '2026-09-01']);
$platform->put('leads', ['id' => '1', 'name' => 'L', 'status' => 'new', 'created_at' => '2026-09-02 10:00:00']);
$platform->put('leads', ['id' => '2', 'name' => 'M', 'status' => 'new', 'created_at' => '2026-08-02 10:00:00']);
$platform->put('licences', ['id' => '1', 'amc_expires_on' => '2026-09-20', 'status' => 'active']);
$platform->forTenant('1')->put('employees', ['id' => '1', 'emp_code' => 'E1', 'name' => 'E']);
$d = $saas->dashboard('2026-09-09');
same(2, $d['tenants'], 'two tenants');
same(['active' => 1, 'suspended' => 1], $d['tenants_by_status'], 'by status');
same('2700.00', $d['mrr']->toDecimalString(), 'MRR: 2500 + 5 × 40');
same(1, $d['invoices_due'], 'one open invoice');
same('3186.00', $d['invoices_due_amount']->toDecimalString(), 'amount + tax outstanding');
same(1, $d['invoices_overdue'], 'past its due date');
same(1, $d['leads_this_month'], 'September lead only');
same(1, $d['licences_expiring'], 'one licence inside the window');
$tenants = $saas->tenants();
same('Growth', $tenants[0]['plan'], 'tenant 1 is on Growth');
same(1, $tenants[0]['employees'], 'employee count through the tenant-scoped store');
same('', $tenants[1]['plan'], 'tenant 2 has no live subscription');
same('on', $saas->flagState('whatsapp_alerts', '2', [['flag_key' => 'whatsapp_alerts', 'tenant_ref' => '2', 'value' => ''], $rows[0]]), 'a cleared tenant override falls through to global');
