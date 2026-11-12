<?php
// Run: php tests/signup_checkout_test.php
// Signup & checkout: price parity with SmartPRS 2 priceFor (+setup, coupon, GST) and the website calculator,
// then a paid signup end to end over a file store (Razorpay signature checked, workspace made).
declare(strict_types=1);
spl_autoload_register(static function (string $c): void {
    foreach (['SmartPRS\\Platform\\' => '/../src/Platform/', 'SmartPRS\\Surface\\' => '/../surface/'] as $p => $d) {
        if (str_starts_with($c, $p)) { $f = __DIR__ . $d . str_replace('\\', '/', substr($c, strlen($p))) . '.php'; is_file($f) && require $f; return; }
    }
});
use SmartPRS\Surface\{Store, Signup, SaasPlatform};
$R = dirname(__DIR__);
$P = json_decode(file_get_contents("$R/config/landing.json"), true)['pricing'];
$G = json_decode(file_get_contents("$R/config/gst.json"), true);
$tmp = sys_get_temp_dir() . '/sp3-signup-test-' . bin2hex(random_bytes(4));
$store = new Store($tmp);
$sg = new Signup($store, $P, $G, "$R/.env");
$n = 0; $fail = [];
$t = function (string $name, bool $ok, string $why = '') use (&$n, &$fail) { $ok ? $n++ : $fail[] = "$name $why"; };

// 1. SmartPRS 2's formula, independently (rupees, 2-dp rounding), vs Signup::price (paise)
$sp2 = function (array $plan, int $seats, int $months, int $co, ?array $cpn = null): array {
    $disc = [3 => 0, 6 => 0.10, 12 => 0.25][$months];
    $perMonth = $plan['monthly'] + $plan['extra'] * max(0, $seats - $plan['employees']) + 1000 * ($co - 1);
    $amount = round($perMonth * $months * (1 - $disc), 2);
    if ($cpn) { $amount -= $cpn['type'] === 'flat' ? min($cpn['value'], $amount) : round($amount * $cpn['value'] / 100, 2); }
    $amount = round($amount + 2500 + 100 * $seats, 2);
    $tax = round($amount * 0.18, 2);
    return [$amount, $tax, round($amount + $tax, 2)];
};
foreach ($sg->plans() as $plan) foreach ([1, 20, 25, 26, 50, 77, 100] as $seats) foreach ([3, 6, 12] as $m) foreach ([1, 3] as $co) foreach ([null, ['code' => 'X10', 'type' => 'percent', 'value' => '10'], ['code' => 'F500', 'type' => 'flat', 'value' => '500']] as $cp) {
    [$a, $tx, $tot] = $sp2($plan, $seats, $m, $co, $cp);
    $q = $sg->price($plan, $seats, $co, $m, $cp, 'Karnataka (29)');
    $t("price {$plan['name']} $seats $m $co " . ($cp['code'] ?? '-'), $q['taxable'] === (int) round($a * 100) && $q['tax'] === (int) round($tx * 100) && $q['total'] === (int) round($tot * 100), json_encode([$q['taxable'], $q['tax'], $q['total'], $a, $tx, $tot]));
}
$tg = $sg->price($sg->plan('Growth'), 50, 1, 3, null, 'Telangana (36)');
$tk = $sg->price($sg->plan('Growth'), 50, 1, 3, null, 'Karnataka (29)');
$t('Telangana CGST+SGST, same total as IGST', $tg['tax_mode'] === 'CGST+SGST' && $tk['tax_mode'] === 'IGST' && $tg['total'] === $tk['total'] && $tg['total'] === 1770000);
$t('best fit 10/26/51', $sg->bestFit(10)['name'] === 'Starter' && $sg->bestFit(26)['name'] === 'Growth' && $sg->bestFit(51)['name'] === 'Professional');
$t('rupees format', Signup::rupees(42775000) === '₹4,27,750' && Signup::rupees(150) === '₹1.50' && Signup::rupees(177000) === '₹1,770');

// 2. a paid signup, end to end
$store->put('payment_gateways', ['gateway' => 'razorpay', 'status' => 'Live', 'mode' => 'test', 'key_id' => 'rzp_test_x', 'secret' => 'shh']);
$gw = $sg->gateway();
$t('gateway read from Payment Gateways', $gw !== null && $gw['key'] === 'rzp_test_x' && $gw['secret'] === 'shh');
$sig = hash_hmac('sha256', 'order_1|pay_1', 'shh');
$t('signature ok / tampered refused', Signup::signatureOk('shh', 'order_1', 'pay_1', $sig) && !Signup::signatureOk('shh', 'order_1', 'pay_2', $sig));
$plan = $sg->plan('Growth'); $planId = $sg->planRow($plan);
$t('plan row made once, then found', $sg->planRow($plan) === $planId);
$q = $sg->price($plan, 60, 2, 6, null, 'Telangana (36)');
$s = $store->put('signups', ['uuid' => 'u1', 'company' => 'Apex Collections Pvt Ltd', 'admin_name' => 'Ravi', 'admin_email' => 'ravi@apex.test', 'mobile' => '9000000000',
    'plan_id' => $planId, 'seats' => 60, 'companies' => 2, 'cycle' => 'halfyear', 'amount' => sprintf('%.2f', $q['taxable'] / 100), 'tax' => sprintf('%.2f', $q['tax'] / 100),
    'coupon_code' => null, 'coupon_discount' => '0.00', 'setup_charge' => sprintf('%.2f', $q['setup'] / 100), 'state' => 'Telangana (36)', 'gstin' => null,
    'subdomain' => 'apex', 'status' => 'pending', 'gateway_order_id' => 'order_1']);
$made = $sg->provision($s, 'pay_1', new SaasPlatform($store, "$R/config"));
$ten = $store->find('tenants', $made['tenant_id']);
$t('tenant: slug, state, seats, owner', $ten['subdomain'] === 'apex' && $ten['seats_licensed'] === 60 && $ten['owner_email'] === 'ravi@apex.test' && $ten['status_code'] === 'active');
$t('company made', count($store->forTenant($made['tenant_id'])->all('companies')) === 1);
$adm = array_values(array_filter($store->all('users'), fn ($u) => $u['email'] === 'ravi@apex.test'));
$t('administrator made', count($adm) === 1 && $adm[0]['tenant_id'] === $made['tenant_id']);
$t('set-password token issued', strlen($made['token']) === 48);
$sub = array_values($store->all('subscriptions'))[0];
// Growth 60 emp, 2 companies, 6 months: (2500 + 10x50 + 1000) x 6 = 24,000 − 10% = 21,600
$t('subscription: halfyear, 21,600, 6 months', $sub['cycle'] === 'halfyear' && $sub['amount'] === '21600.00' && $sub['current_period_end'] === date('Y-m-d', strtotime('+6 months')), json_encode($sub));
$inv = $made['invoice'];
// + setup 2,500 + 6,000 = 30,100; GST 5,418
$t('invoice: paid, taxable 30,100, GST 5,418', $inv['status'] === 'paid' && $inv['amount'] === '30100.00' && $inv['tax'] === '5418.00', json_encode($inv));
$pay = array_values($store->all('payments'))[0];
$t('payment: 35,518 succeeded, razorpay pay_1', $pay['amount'] === '35518.00' && $pay['status'] === 'succeeded' && $pay['gateway_txn_id'] === 'pay_1');
$t('signup marked provisioned', $store->find('signups', (string) $s['id'])['status'] === 'provisioned');
$t('slug and email now taken', $sg->slugTaken('apex') && $sg->emailTaken('RAVI@apex.test'));
echo "PASS $n\n"; foreach ($fail as $f) echo "FAIL $f\n";
exit($fail === [] ? 0 : 1);
