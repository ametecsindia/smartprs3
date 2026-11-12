<?php
/**
 * /signup — SELF-SERVE SIGNUP & CHECKOUT (owner, 12 Nov 2026: "same SmartPRS2 logic … payment gateway … separate page",
 * as smartprs.com/signup). The website's "Get started" opens it with ?plan=<name>.
 *
 * Left: the organisation (company, admin, email, mobile, state + GSTIN for the GST split, web address, employees,
 * companies). Right: plan, advance period, coupon (only when one is live), the price — computed on the SERVER for every
 * change (surface/Signup.php::price), never trusted from the browser — terms, then:
 *   Pay securely & create workspace → Razorpay checkout → the signature is checked → the workspace is made and the
 *     administrator sets their own password (surface/Signup.php::provision).
 *   Send me a Quotation → the signup is saved as a quotation and emailed with a link back here to pay (?q=…).
 * Razorpay keys: Super Admin → Payment Gateways (gateway "razorpay", status Configured/Live).
 */
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    foreach (['SmartPRS\\Platform\\' => '/../src/Platform/', 'SmartPRS\\Surface\\' => '/../surface/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $f = __DIR__ . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($f)) {
                require_once $f;
            }

            return;
        }
    }
});

use SmartPRS\Surface\Database;
use SmartPRS\Surface\Signup;

$env = __DIR__ . '/../.env';
$tz = (string) (Database::env($env)['SMARTPRS_DEFAULT_BUSINESS_TIMEZONE'] ?? '');
$tz !== '' && in_array($tz, timezone_identifiers_list(), true) && date_default_timezone_set($tz);
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
$_SESSION['signup_csrf'] ??= bin2hex(random_bytes(32));
$h = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$gst = json_decode((string) file_get_contents(__DIR__ . '/../config/gst.json'), true) ?: [];
$pdo = Database::connect($env);
$platform = $pdo === null ? null : new SmartPRS\Surface\SqlStore($pdo);
$content = (new SmartPRS\Surface\LandingContent(__DIR__ . '/../config/landing.json',
    $platform === null ? null : new SmartPRS\Surface\PlatformSettings($platform)))->content();
$pricing = (array) ($content['pricing'] ?? []);
$sg = $platform === null ? null : new Signup($platform, $pricing, $gst, $env);
$states = ['Andaman & Nicobar Islands (35)', 'Andhra Pradesh (37)', 'Arunachal Pradesh (12)', 'Assam (18)', 'Bihar (10)', 'Chandigarh (04)',
    'Chhattisgarh (22)', 'Dadra & Nagar Haveli and Daman & Diu (26)', 'Delhi (07)', 'Goa (30)', 'Gujarat (24)', 'Haryana (06)',
    'Himachal Pradesh (02)', 'Jammu & Kashmir (01)', 'Jharkhand (20)', 'Karnataka (29)', 'Kerala (32)', 'Ladakh (38)', 'Lakshadweep (31)',
    'Madhya Pradesh (23)', 'Maharashtra (27)', 'Manipur (14)', 'Meghalaya (17)', 'Mizoram (15)', 'Nagaland (13)', 'Odisha (21)',
    'Puducherry (34)', 'Punjab (03)', 'Rajasthan (08)', 'Sikkim (11)', 'Tamil Nadu (33)', 'Telangana (36)', 'Tripura (16)',
    'Uttar Pradesh (09)', 'Uttarakhand (05)', 'West Bengal (19)'];

/** @param array<string, mixed> $body */
$json = static function (int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

// A live coupon for this plan, or a reason. @return array{0: ?array, 1: ?string}
$couponFor = static function (string $code, string $planId) use ($platform): array {
    $code = strtoupper(trim($code));

    if ($code === '') {
        return [null, null];
    }

    foreach ($platform->all('coupons') as $c) {
        if (strcasecmp((string) ($c['code'] ?? ''), $code) === 0) {
            $why = SmartPRS\Surface\SaasPlatform::couponProblem($c, date('Y-m-d'), 'signup', $planId);

            return $why === null ? [$c, null] : [null, $why];
        }
    }

    return [null, 'Coupon ' . $code . ' is not valid.'];
};
$couponsLive = false;

if ($platform !== null) {
    try {
        foreach ($platform->all('coupons') as $c) {
            $couponsLive = $couponsLive || SmartPRS\Surface\SaasPlatform::couponProblem($c, date('Y-m-d'), 'signup') === null;
        }
    } catch (\Throwable) {
        $couponsLive = false;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $in = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
    $v = static fn (string $k): string => trim((string) ($in[$k] ?? ''));

    if ($sg === null) {
        $json(503, ['ok' => false, 'error' => 'Signup is not available right now — please write to sales@ametecsindia.com.']);
    }

    if (!hash_equals((string) $_SESSION['signup_csrf'], $v('csrf'))) {
        $json(419, ['ok' => false, 'error' => 'This page expired — reload it and try again.']);
    }

    $op = $v('op');

    if ($op !== 'price' && !in_array('setup_charge', $platform->columnsOf('signups'), true)) {
        $json(503, ['ok' => false, 'error' => 'Signup needs the 12-11-2026 database update (php tools/db/migrate.php). Please write to sales@ametecsindia.com meanwhile.']);
    }
    $plan = $sg->plan($v('plan')) ?? $sg->bestFit(max(1, (int) $v('seats')));
    $seats = max(1, min(100000, (int) $v('seats')));
    $companies = max(1, min(100, (int) $v('companies') ?: 1));
    $months = (int) $v('months');

    if ($seats > $sg->maxEmployees()) {
        $json(422, ['ok' => false, 'contact' => true, 'error' => 'Above ' . $sg->maxEmployees() . ' employees we price your package with you — please contact sales (sales@ametecsindia.com).']);
    }

    $planId = $op === 'price' ? '' : $sg->planRow($plan);
    [$coupon, $couponWhy] = $couponFor($v('coupon'), $planId);
    $price = $sg->price($plan, $seats, $companies, $months, $coupon, $v('state'));

    if ($op === 'price') {
        $json(200, ['ok' => true, 'price' => $price, 'coupon_problem' => $couponWhy, 'fmt_subscription_after' => Signup::rupees($price['subscription'] - $price['coupon_off']),
            'fmt' => array_map(static fn ($x) => is_int($x) ? Signup::rupees($x) : $x, $price)]);
    }

    // ---- the buyer, as SmartPRS 2 validates them (validateSignup) ----
    $email = strtolower($v('admin_email'));
    $slug = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $v('subdomain')));
    $gstin = strtoupper($v('gstin'));
    $problem = match (true) {
        $v('company') === '' || mb_strlen($v('company')) > 150 => 'Please enter your company / group name.',
        $v('admin_name') === '' => 'Please enter your name.',
        filter_var($email, FILTER_VALIDATE_EMAIL) === false => 'Please enter a valid work email — it becomes your login.',
        !in_array($v('state'), $states, true) => 'Please select your state — it decides how GST appears on your tax invoice.',
        $gstin !== '' && preg_match('/^[0-9]{2}[A-Z0-9]{13}$/', $gstin) !== 1 => 'GSTIN must be 15 characters, starting with the 2-digit state code (e.g. 36AAHCT0971F1ZB).',
        $gstin !== '' && substr($gstin, 0, 2) !== $sg->stateCode($v('state')) => 'Your GSTIN starts with state code ' . substr($gstin, 0, 2) . ' but you selected ' . $v('state') . '. Please correct one of them.',
        $slug !== '' && (strlen($slug) < 3 || strlen($slug) > 10) => 'Your web address must be 3 to 10 letters/numbers (no spaces or symbols).',
        $slug !== '' && $sg->slugTaken($slug) => 'The web address "' . $slug . '" is already taken — please pick another.',
        $sg->emailTaken($email) => 'That email already has a SmartPRS account. Sign in instead, or use a different email.',
        $couponWhy !== null => $couponWhy,
        $op === 'order' && $v('terms') !== '1' => 'Please accept the Terms & Conditions and Refund Policy.',
        default => null,
    };

    if ($problem !== null && $op !== 'complete') {
        $json(422, ['ok' => false, 'error' => $problem]);
    }

    $cycleKey = (string) (array_search($price['months'], (array) (json_decode((string) file_get_contents(__DIR__ . '/../config/plans.json'), true)['cycles'] ?? []), true) ?: 'quarterly');
    $row = ['company' => mb_substr($v('company'), 0, 150), 'admin_name' => mb_substr($v('admin_name'), 0, 120), 'admin_email' => $email,
        'mobile' => mb_substr($v('mobile'), 0, 20) ?: null, 'plan_id' => $planId, 'seats' => $seats, 'companies' => $companies, 'cycle' => $cycleKey,
        'amount' => sprintf('%.2f', $price['taxable'] / 100), 'tax' => sprintf('%.2f', $price['tax'] / 100),
        'coupon_code' => $price['coupon'], 'coupon_discount' => sprintf('%.2f', $price['coupon_off'] / 100), 'setup_charge' => sprintf('%.2f', $price['setup'] / 100),
        'state' => $v('state'), 'gstin' => $gstin ?: null, 'subdomain' => $slug ?: null, 'updated_at' => date('Y-m-d H:i:s')];

    if ($op === 'quote') {
        $saved = $platform->put('signups', $row + ['uuid' => bin2hex(random_bytes(16)), 'status' => 'quoted', 'created_at' => date('Y-m-d H:i:s')]);
        $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        $link = $base . '/signup.php?q=' . rawurlencode((string) $saved['uuid']);
        $mail = new SmartPRS\Surface\PlatformMail(new SmartPRS\Surface\PlatformSettings($platform), $env);
        $sent = $mail->send([$email], 'SmartPRS quotation — ' . $row['company'],
            'Thank you for your interest in SmartPRS, ' . $row['admin_name'] . '. Here is your quotation. Pay securely online any time with the link below — your workspace is created the moment the payment goes through.',
            ['Plan' => $plan['name'] . ' — includes up to ' . $plan['employees'] . ' employees', 'Employees' => (string) $seats,
                'Companies' => (string) $companies, 'Billing period' => $price['cycle_label'] . ' (' . $price['months'] . ' months)' . ($price['discount_percent'] ? ' · ' . $price['discount_percent'] . '% advance discount' : ''),
                'Subscription' => Signup::rupees($price['subscription'] - $price['coupon_off']), 'Setup & onboarding (one time)' => Signup::rupees($price['setup']),
                'GST (' . (int) ($gst['rate_percent'] ?? 0) . '%)' => Signup::rupees($price['tax']), 'Total' => Signup::rupees($price['total']),
                'Valid for' => (int) (json_decode((string) file_get_contents(__DIR__ . '/../config/plans.json'), true)['quotation_valid_days'] ?? 15) . ' days', 'Pay online' => $link]);
        $json(200, ['ok' => true, 'message' => $sent['ok'] ? 'Quotation emailed to ' . $email . ' with a secure payment link.'
            : 'Your quotation is saved. We could not email it just now — use this link to pay: ' . $link, 'link' => $link]);
    }

    $gw = $sg->gateway();

    if ($op === 'order') {
        if ($gw === null) {
            $json(422, ['ok' => false, 'error' => 'Online payment is not configured yet. Please write to sales@ametecsindia.com and we will set you up the same day.']);
        }

        $saved = $platform->put('signups', $row + ['uuid' => bin2hex(random_bytes(16)), 'status' => 'pending', 'terms_accepted_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s')]);
        $order = $sg->razorpayOrder($gw, $price['total'], 'SIGNUP-' . (string) $saved['id'], ['signup_uuid' => (string) $saved['uuid'], 'company' => $row['company']]);

        if (!$order['ok']) {
            $json(422, ['ok' => false, 'error' => 'Could not start the payment: ' . $order['why']]);
        }

        $platform->put('signups', ['id' => (string) $saved['id'], 'gateway_order_id' => $order['id']]);
        $json(200, ['ok' => true, 'orderId' => $order['id'], 'keyId' => $gw['key'], 'amountPaise' => $price['total'], 'uuid' => (string) $saved['uuid'],
            'name' => $row['admin_name'], 'email' => $email, 'mobile' => (string) ($row['mobile'] ?? '')]);
    }

    if ($op === 'complete') {
        $s = null;

        foreach ($platform->all('signups') as $r) {
            if (hash_equals((string) ($r['uuid'] ?? ''), $v('uuid'))) {
                $s = $r;
            }
        }

        if ($s === null) {
            $json(404, ['ok' => false, 'error' => 'Signup not found.']);
        }

        if ((string) $s['status'] === 'provisioned') {
            $json(200, ['ok' => true, 'message' => 'Your workspace is ready — sign in with ' . (string) $s['admin_email'] . '.', 'redirect' => 'app.php?r=signin']);
        }

        if ($gw === null || (string) ($s['gateway_order_id'] ?? '') === '' || !hash_equals((string) $s['gateway_order_id'], $v('razorpay_order_id'))) {
            $json(422, ['ok' => false, 'error' => 'Payment/order mismatch.']);
        }

        if (!Signup::signatureOk($gw['secret'], $v('razorpay_order_id'), $v('razorpay_payment_id'), $v('razorpay_signature'))) {
            $json(422, ['ok' => false, 'error' => 'Payment signature verification failed.']);
        }

        $platform->put('signups', ['id' => (string) $s['id'], 'gateway_payment_id' => $v('razorpay_payment_id')]);

        try {
            $made = $sg->provision($s, $v('razorpay_payment_id'), new SmartPRS\Surface\SaasPlatform($platform, __DIR__ . '/../config'));
        } catch (\Throwable $e) {
            error_log('signup provision ' . (string) $s['id'] . ': ' . $e->getMessage());
            $json(500, ['ok' => false, 'error' => 'Your payment was received (reference ' . $v('razorpay_payment_id') . ') but the workspace could not be made just now. We have your details — our team will finish it and email you within the hour (sales@ametecsindia.com).']);
        }

        if ((string) ($s['coupon_code'] ?? '') !== '') {
            foreach ($platform->all('coupons') as $c) {
                strcasecmp((string) $c['code'], (string) $s['coupon_code']) === 0
                    && $platform->put('coupons', ['id' => (string) $c['id'], 'used_count' => (int) ($c['used_count'] ?? 0) + 1]);
            }
        }

        $setLink = 'app.php?r=set-password&t=' . rawurlencode($made['token']);
        $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        (new SmartPRS\Surface\PlatformMail(new SmartPRS\Surface\PlatformSettings($platform), $env))->send([(string) $s['admin_email']],
            'Welcome to SmartPRS — your workspace is ready',
            'Payment received with thanks, ' . (string) $s['admin_name'] . '! Your SmartPRS workspace for ' . (string) $s['company'] . ' is ready. Set your password with the link below (valid 72 hours), then sign in at your company address.',
            ['Invoice' => (string) $made['invoice']['number'], 'Total paid' => Signup::rupees((int) round(100 * ((float) $s['amount'] + (float) $s['tax']))),
                'Payment reference' => $v('razorpay_payment_id'), 'Sign-in' => $base . '/c/' . $made['slug'], 'Login email' => (string) $s['admin_email'],
                'Set your password' => $base . '/' . $setLink]);
        $json(200, ['ok' => true, 'message' => 'Payment received and your workspace is ready! Create your password in the next step — then sign in at /c/' . $made['slug'] . ' with ' . (string) $s['admin_email'] . '.',
            'redirect' => $setLink]);
    }

    $json(400, ['ok' => false, 'error' => 'Unknown request.']);
}

// ---- the page ----
$pick = $sg?->plan((string) ($_GET['plan'] ?? '')) ?? ($sg?->plans()[1] ?? $sg?->plans()[0] ?? null);
$pre = [];

if ($platform !== null && ($q = (string) ($_GET['q'] ?? '')) !== '') {
    foreach ($platform->all('signups') as $r) {
        if (hash_equals((string) ($r['uuid'] ?? ''), $q) && (string) $r['status'] === 'quoted') {
            $pre = $r;
            $pick = $sg->plan((string) ($platform->find('plans', (string) $r['plan_id'])['name'] ?? '')) ?? $pick;
        }
    }
}

$months0 = (int) (array_values((array) ($pricing['cloud']['cycles'] ?? []))[0]['months'] ?? 3);
$preMonths = $pre === [] ? $months0 : (int) ((json_decode((string) file_get_contents(__DIR__ . '/../config/plans.json'), true)['cycles'] ?? [])[(string) $pre['cycle']] ?? $months0);
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Get started — SmartPRS by Ametecs</title>
<link rel="icon" type="image/png" href="images/logo-icon.png">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{--navy:#0a1628;--navy2:#102744;--accent:#f97316;--ink:#0f1d33;--mut:#64748b;--line:#e5e7eb;--soft:#f6f8fb}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Plus Jakarta Sans',system-ui,sans-serif}
body{background:var(--soft);color:var(--ink)}
.top{background:var(--navy);padding:14px 0}.wrap{max-width:1080px;margin:0 auto;padding:0 20px}.top img{height:38px;display:block}
.hero{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;padding:30px 0 86px;text-align:center}.hero h1{font-size:28px;font-weight:800}.hero p{opacity:.8;margin-top:6px;font-size:14.5px}
.card{background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 10px 30px rgba(15,29,51,.07)}
.shell{margin-top:-56px;padding-bottom:60px}.grid{display:grid;grid-template-columns:1.25fr .9fr}
@media(max-width:860px){.grid{grid-template-columns:1fr}.grid>.pad:first-child{border-right:0!important;border-bottom:1px solid var(--line)}}
.pad{padding:26px 28px}h2{font-size:16px;margin-bottom:12px;font-weight:800}h2 i{color:var(--accent);margin-right:7px}
.plans,.cycles{display:grid;grid-template-columns:repeat(3,1fr);gap:9px}
.plan,.cyc{border:1.5px solid var(--line);border-radius:12px;padding:11px 8px;cursor:pointer;text-align:center;background:#fff}
.plan.on,.cyc.on{border-color:var(--accent);background:#fff7ed;box-shadow:0 0 0 3px rgba(249,115,22,.12)}
.plan b,.cyc b{display:block;font-size:13.5px}.plan .pr{font-size:17px;font-weight:800;margin:3px 0}.plan small{color:var(--mut);font-size:11px;display:block;line-height:1.45}
.cyc small{display:block;font-size:11px;font-weight:700;color:#16a34a;margin-top:2px}
label.fl{display:block;font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:var(--mut);margin:14px 0 5px}
.fi{width:100%;padding:11px 13px;border:1.5px solid var(--line);border-radius:9px;font-size:14.5px;background:#fff}.fi:focus{outline:none;border-color:var(--accent)}
.inc{font-size:12px;color:var(--mut);line-height:1.6;margin-top:5px}.inc i{color:#16a34a;margin-right:5px}
.quote{border-top:1px dashed var(--line);margin-top:16px;padding-top:12px;font-size:13.5px}
.quote .row{display:flex;justify-content:space-between;gap:10px;padding:3.5px 0;color:var(--mut)}.quote .row b{color:var(--ink);white-space:nowrap}
.quote .total{display:flex;justify-content:space-between;background:var(--navy);color:#fff;border-radius:10px;padding:12px 14px;margin-top:10px;font-size:15px;font-weight:700}
.btn{display:flex;align-items:center;justify-content:center;gap:9px;width:100%;background:var(--accent);color:#fff;border:none;border-radius:10px;padding:14px;font-size:15px;font-weight:700;cursor:pointer;margin-top:14px}
.btn:disabled{opacity:.6;cursor:wait}.btn.ghost{background:#fff;color:var(--accent);border:1.5px solid var(--accent);margin-top:10px}
.terms{display:flex;gap:9px;align-items:flex-start;margin-top:14px;font-size:13px;line-height:1.5}.terms input{width:17px;height:17px;accent-color:var(--accent);margin-top:2px;flex-shrink:0}.terms a{color:var(--accent);font-weight:600}
.fine{font-size:11.5px;color:var(--mut);text-align:center;margin-top:9px;line-height:1.5}
.msg{display:none;border-radius:9px;padding:10px 13px;font-size:13px;margin-top:12px}.msg.err{display:block;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c}.msg.ok{display:block;background:#dcfce7;border:1px solid #bbf7d0;color:#166534}
.okpane{display:none;text-align:center;padding:46px 30px}.okpane .tick{width:64px;height:64px;border-radius:50%;background:#dcfce7;color:#16a34a;font-size:28px;display:flex;align-items:center;justify-content:center;margin:0 auto 16px}
</style></head>
<body>
<div class="top"><div class="wrap"><a href="./"><img src="images/logo.png" alt="SmartPRS"></a></div></div>
<div class="hero"><div class="wrap"><h1>Start your SmartPRS workspace</h1><p>All modules and QR Code Attendance in every plan · pay only for headcount · GST invoice emailed instantly</p></div></div>
<div class="wrap shell">
<?php if ($sg === null || $pick === null): ?>
  <div class="card pad" style="text-align:center">Signup is not available right now — please write to <a href="mailto:sales@ametecsindia.com">sales@ametecsindia.com</a>.</div>
<?php else: ?>
  <div class="card" id="formcard"><div class="grid">
    <div class="pad" style="border-right:1px solid var(--line)">
      <h2><i class="fas fa-building"></i>Your organisation</h2>
      <label class="fl" for="f_company">Company / group name</label><input class="fi" id="f_company" maxlength="150" placeholder="e.g. Apex Collections Pvt. Ltd." value="<?= $h((string) ($pre['company'] ?? '')) ?>">
      <label class="fl" for="f_name">Your name (admin)</label><input class="fi" id="f_name" maxlength="120" placeholder="Full name" value="<?= $h((string) ($pre['admin_name'] ?? '')) ?>">
      <label class="fl" for="f_email">Work email (your login)</label><input class="fi" id="f_email" type="email" placeholder="you@company.com" value="<?= $h((string) ($pre['admin_email'] ?? '')) ?>">
      <label class="fl" for="f_mobile">Mobile</label><input class="fi" id="f_mobile" maxlength="20" placeholder="10-digit mobile" value="<?= $h((string) ($pre['mobile'] ?? '')) ?>">
      <label class="fl" for="f_state">State (for GST on your invoice)</label>
      <select class="fi" id="f_state"><option value="">Select your state…</option><?php foreach ($states as $st): ?><option<?= ($pre['state'] ?? '') === $st ? ' selected' : '' ?>><?= $h($st) ?></option><?php endforeach; ?></select>
      <label class="fl" for="f_gstin">GSTIN (optional — printed on your tax invoice)</label><input class="fi" id="f_gstin" maxlength="15" style="text-transform:uppercase" placeholder="e.g. 36AAHCT0971F1ZB" value="<?= $h((string) ($pre['gstin'] ?? '')) ?>">
      <div class="inc">Telangana businesses are billed CGST 9% + SGST 9%; other states IGST 18% — same total either way.</div>
      <label class="fl" for="f_slug">Choose your SmartPRS web address (optional)</label>
      <div style="display:flex;align-items:center;gap:6px"><span style="font-size:13px;color:var(--mut);white-space:nowrap"><?= $h((string) ($_SERVER['HTTP_HOST'] ?? 'smartprs.com')) ?>/c/</span><input class="fi" id="f_slug" maxlength="10" style="text-transform:lowercase" placeholder="e.g. abcrecover" value="<?= $h((string) ($pre['subdomain'] ?? '')) ?>"></div>
      <div class="inc">3–10 letters/numbers, no spaces. This becomes your branded login page. Leave blank and we will create one from your company name.</div>
      <label class="fl" for="f_seats">Total employees you will manage</label><input class="fi" id="f_seats" type="number" min="1" value="<?= (int) ($pre['seats'] ?? $pick['employees']) ?>">
      <label class="fl" for="f_companies">Companies in your group</label><input class="fi" id="f_companies" type="number" min="1" max="100" value="<?= (int) ($pre['companies'] ?? 1) ?>">
      <div class="inc">Every plan includes 1 company. Each additional company is a flat <?= $h(Signup::rupees(100 * (int) ($pricing['cloud']['company_fee'] ?? 0))) ?>/month — your employee limit stays the same across ALL companies.</div>
      <div class="inc" style="margin-top:16px"><i class="fas fa-check"></i>All modules included &nbsp; <i class="fas fa-check"></i>QR Code Attendance &amp; mobile app<br><i class="fas fa-check"></i>GST tax invoice &nbsp; <i class="fas fa-check"></i>Your admin login right after payment</div>
    </div>
    <div class="pad">
      <h2><i class="fas fa-tags"></i>Plan &amp; billing</h2>
      <div class="plans" id="plans"><?php foreach ($sg->plans() as $p): ?>
        <div class="plan<?= $p['name'] === $pick['name'] ? ' on' : '' ?>" data-plan="<?= $h((string) $p['name']) ?>"><b><?= $h((string) $p['name']) ?></b><div class="pr"><?= $h(Signup::rupees(100 * (int) $p['monthly'])) ?><small style="display:inline;font-weight:500">/mo</small></div>
          <small>Up to <?= (int) $p['employees'] ?> employees<br>+<?= $h(Signup::rupees(100 * (int) ($p['extra'] ?? 0))) ?>/extra</small></div><?php endforeach; ?></div>
      <label class="fl">Advance period</label>
      <div class="cycles" id="cycles"><?php foreach ($sg->cycles() as $c): ?>
        <div class="cyc<?= (int) $c['months'] === $preMonths ? ' on' : '' ?>" data-months="<?= (int) $c['months'] ?>"><b><?= $h((string) $c['label']) ?></b><small><?= (int) $c['months'] ?> months · <?= (int) $c['discount'] ? (int) $c['discount'] . '% off' : 'standard' ?></small></div><?php endforeach; ?></div>
      <?php if ($couponsLive): ?><label class="fl" for="f_coupon">Have a coupon code?</label><input class="fi" id="f_coupon" maxlength="40" style="text-transform:uppercase" placeholder="e.g. LAUNCH25"><?php endif; ?>
      <div class="quote" id="quote"></div>
      <label class="terms"><input type="checkbox" id="f_terms"><span>I have read and accept the <a href="terms-and-conditions" target="_blank">Terms &amp; Conditions</a> and <a href="refund-policy" target="_blank">Refund Policy</a> of SmartPRS by Ametecs India Pvt. Ltd.</span></label>
      <button class="btn" id="paybtn" type="button"><i class="fas fa-lock"></i> Pay securely &amp; create workspace</button>
      <button class="btn ghost" id="quotebtn" type="button"><i class="fas fa-file-invoice"></i> Send me a Quotation</button>
      <div class="fine">Need approval from your finance team first? Get a quotation emailed to you with a secure payment link — pay anytime to create the workspace.</div>
      <div class="msg" id="msg"></div>
      <div class="fine">Payments are processed by Razorpay. Minimum billing period is <?= $months0 ?> months.<br>Prices exclude GST (<?= (int) ($gst['rate_percent'] ?? 0) ?>%), shown above before you pay.</div>
    </div>
  </div></div>
  <div class="card okpane" id="okpane"><div class="tick"><i class="fas fa-check"></i></div><h2 style="font-size:22px">Welcome aboard!</h2>
    <p id="okmsg" style="color:var(--mut);max-width:520px;margin:8px auto 0;line-height:1.6"></p><p style="margin-top:18px"><a id="oklink" href="app.php?r=signin" style="color:var(--accent);font-weight:700">Continue →</a></p></div>
<?php endif; ?>
</div>
<?php if ($sg !== null && $pick !== null): ?>
<script src="<?= Signup::CHECKOUT ?>"></script>
<script>
(function () {
  var csrf = <?= json_encode($_SESSION['signup_csrf']) ?>, st = {plan: <?= json_encode($pick['name']) ?>, months: <?= $preMonths ?>};
  var $ = function (id) { return document.getElementById(id); }, msg = $('msg'), timer = null;
  function say(t, ok) { msg.className = 'msg ' + (ok ? 'ok' : 'err'); msg.textContent = t; }
  function data(op) {
    return {csrf: csrf, op: op, plan: st.plan, months: st.months, seats: $('f_seats').value, companies: $('f_companies').value,
      coupon: $('f_coupon') ? $('f_coupon').value : '', company: $('f_company').value, admin_name: $('f_name').value, admin_email: $('f_email').value,
      mobile: $('f_mobile').value, state: $('f_state').value, gstin: $('f_gstin').value, subdomain: $('f_slug').value, terms: $('f_terms').checked ? '1' : ''};
  }
  function post(body) { return fetch('signup.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)}).then(function (r) { return r.json(); }); }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); }
  function row(l, v, strong) { return '<div class="row"><span>' + l + '</span><b' + (strong ? ' style="color:#16a34a"' : '') + '>' + v + '</b></div>'; }
  function price() {
    post(data('price')).then(function (j) {
      var q = $('quote');
      if (!j.ok) { q.innerHTML = '<div class="row" style="color:#b91c1c">' + esc(j.error) + '</div>'; $('paybtn').disabled = $('quotebtn').disabled = !!j.contact; return; }
      $('paybtn').disabled = $('quotebtn').disabled = false;
      var p = j.price, f = j.fmt, h = '';
      f.subscription_after = j.fmt_subscription_after;
      st.plan = p.plan;
      document.querySelectorAll('.plan').forEach(function (e) { e.classList.toggle('on', e.getAttribute('data-plan') === p.plan); });
      h += row(esc(p.plan) + ' plan (includes ' + p.plan_employees + ' employees + 1 company)', f.per_month + '/mo');
      if (p.extra > 0) h += row(p.extra + ' extra employee' + (p.extra > 1 ? 's' : '') + ' (in the monthly price)', '');
      if (p.companies > 1) h += row((p.companies - 1) + ' extra compan' + (p.companies > 2 ? 'ies' : 'y') + ' (in the monthly price)', '');
      h += row('Billing period', p.months + ' months');
      if (p.discount > 0) h += row(p.cycle_label + ' discount (' + p.discount_percent + '%)', '− ' + f.discount, true);
      if (p.coupon_off > 0) h += row('Coupon ' + esc(p.coupon), '− ' + f.coupon_off, true);
      if (j.coupon_problem) h += '<div class="row" style="color:#b91c1c">' + esc(j.coupon_problem) + '</div>';
      h += row('Subscription (' + p.months + ' months)', f.subscription_after);
      h += row('Setup &amp; onboarding (one time)', f.setup);
      h += row('Subtotal', f.taxable);
      h += row('GST (' + <?= (int) ($gst['rate_percent'] ?? 0) ?> + '%' + (p.tax_mode === 'CGST+SGST' ? ' · CGST + SGST' : '') + ')', f.tax);
      h += '<div class="total"><span>Payable now</span><span>' + f.total + '</span></div>';
      q.innerHTML = h;
    });
  }
  function later() { clearTimeout(timer); timer = setTimeout(price, 200); }
  document.querySelectorAll('.plan').forEach(function (e) { e.addEventListener('click', function () { st.plan = e.getAttribute('data-plan'); price(); }); });
  document.querySelectorAll('.cyc').forEach(function (e) { e.addEventListener('click', function () {
    st.months = +e.getAttribute('data-months'); document.querySelectorAll('.cyc').forEach(function (x) { x.classList.toggle('on', x === e); }); price(); }); });
  ['f_seats', 'f_companies', 'f_state', 'f_coupon'].forEach(function (id) { $(id) && $(id).addEventListener('input', later); });
  $('f_seats').addEventListener('change', function () { st.plan = ''; price(); });
  $('quotebtn').addEventListener('click', function () {
    var b = this; b.disabled = true;
    post(data('quote')).then(function (j) { b.disabled = false; say(j.ok ? j.message : j.error, j.ok); });
  });
  $('paybtn').addEventListener('click', function () {
    var b = this;
    if (!$('f_terms').checked) { say('Please accept the Terms & Conditions and Refund Policy.'); return; }
    b.disabled = true; say('Starting secure payment…', true);
    post(data('order')).then(function (j) {
      if (!j.ok) { b.disabled = false; say(j.error); return; }
      var rz = new Razorpay({key: j.keyId, order_id: j.orderId, amount: j.amountPaise, currency: 'INR', name: 'SmartPRS by Ametecs',
        description: st.plan + ' plan — ' + st.months + ' months', prefill: {name: j.name, email: j.email, contact: j.mobile}, theme: {color: '#f97316'},
        handler: function (r) {
          say('Payment received — creating your workspace…', true);
          post({csrf: csrf, op: 'complete', uuid: j.uuid, razorpay_order_id: r.razorpay_order_id, razorpay_payment_id: r.razorpay_payment_id,
            razorpay_signature: r.razorpay_signature}).then(function (k) {
            if (!k.ok) { say(k.error); return; }
            $('formcard').style.display = 'none'; $('okpane').style.display = 'block'; $('okmsg').textContent = k.message; $('oklink').href = k.redirect;
            setTimeout(function () { location.href = k.redirect; }, 2500);
          });
        },
        modal: {ondismiss: function () { b.disabled = false; say('Payment was not completed — you can try again.'); }}});
      rz.on('payment.failed', function (e) { b.disabled = false; say('Payment failed: ' + (e.error && e.error.description || 'please try again.')); });
      rz.open();
    }).catch(function () { b.disabled = false; say('Could not reach the server — check your connection and try again.'); });
  });
  price();
})();
</script>
<?php endif; ?>
</body></html>
