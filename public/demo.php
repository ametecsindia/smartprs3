<?php
/**
 * /demo — EXPLORE DEMO (owner, 12 Nov 2026; SmartPRS 2 rev 185's passkey gate kept).
 *
 * Step 1: name, company, mobile, email → a passkey by email + WhatsApp (surface/DemoGate.php).
 * Step 2: the passkey → the visitor's OWN demo company is made and they are signed in as its administrator; the first
 *         page fills it with sample data (surface/actions/demo-setup.php). It lasts the demo days, then is deleted.
 * Opened from the mobile app (its Explore Demo button), the phone is registered to that company at once.
 * The Super Admin sees every request under SaaS Platform → Demo Requests (days, extend, resend, revoke, delete now).
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
use SmartPRS\Surface\DemoGate;
use SmartPRS\Surface\PlatformSettings;
use SmartPRS\Surface\SqlStore;

$env = __DIR__ . '/../.env';
$tz = (string) (Database::env($env)['SMARTPRS_DEFAULT_BUSINESS_TIMEZONE'] ?? '');
if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
    date_default_timezone_set($tz);
}

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
$_SESSION['demo_csrf'] ??= bin2hex(random_bytes(32));
$h = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$pdo = Database::connect($env);
$problem = isset($_GET['expired']) ? 'Your demo has ended and its sample company was deleted. Request a new passkey below to start again.' : null;
$notice = null;
$gate = null;
$days = 7;

try {
    if ($pdo === null) {
        throw new \RuntimeException('The demo is not available right now.');
    }

    $platform = new SqlStore($pdo);
    $gate = new DemoGate($platform, new PlatformSettings($platform), $env);
    $days = $gate->days();
    $gate->sweep($pdo); // ended demos are deleted here too, in case the scheduler is not running
} catch (\Throwable $e) {
    error_log('demo: ' . $e->getMessage());
    $problem = $gate === null ? 'The demo is not available right now — please try again later, or write to sales@ametecsindia.com.' : $problem;
}

if ($gate !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $op = (string) ($_POST['op'] ?? '');

    if (!hash_equals((string) $_SESSION['demo_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $problem = 'The form expired — reload the page and try again.';
    } elseif (trim((string) ($_POST['website'] ?? '')) !== '') {
        $notice = 'Passkey sent.'; // honeypot: a bot is told it worked, nothing is made
    } elseif ($op === 'request') {
        $name = substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
        $email = strtolower(substr(trim((string) ($_POST['email'] ?? '')), 0, 160));
        $mobile = DemoGate::mobile((string) ($_POST['mobile'] ?? ''));

        if ($name === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $problem = 'Please give your name and a valid email.';
        } elseif ($mobile === null) {
            $problem = 'Please enter a valid 10-digit mobile number (starting 6, 7, 8 or 9).';
        } else {
            $row = $gate->request($name, $mobile, $email, substr(trim((string) ($_POST['company'] ?? '')), 0, 160));
            $channels = $gate->sendPin($row);
            $notice = $channels !== []
                ? 'Your passkey is on its way to your ' . implode(' and ', $channels) . '. Enter it below — your demo company stays for ' . $days . ' day' . ($days === 1 ? '' : 's') . '.'
                : null;
            $problem = $channels === [] ? 'We could not reach you by email/WhatsApp right now — please retry in a minute, or write to sales@ametecsindia.com.' : null;
        }
    } elseif ($op === 'enter') {
        $tries = (int) ($_SESSION['demo_tries'] ?? 0);
        $row = null;
        $login = null;

        if ($tries >= 5 && time() < (int) ($_SESSION['demo_locked'] ?? 0)) {
            $problem = 'Too many attempts. Try again in a few minutes.';
        } elseif (($row = $gate->byPin(trim((string) ($_POST['pin'] ?? '')))) === null) {
            $_SESSION['demo_tries'] = $tries + 1;
            $_SESSION['demo_locked'] = $tries + 1 >= 5 ? time() + 300 : 0;
            $problem = 'That passkey is not valid (or its time window has ended). Submit a new request to get a fresh one.';
        } else {
            try {
                $login = $gate->provision($row);
            } catch (\Throwable $e) {
                error_log('demo provision ' . $row['id'] . ': ' . $e->getMessage());
                $login = null;
            }
        }

        if ($row !== null && $login === null) {
            $problem = 'Your demo company could not be made just now — please try again in a minute, or write to sales@ametecsindia.com.';
        } elseif ($row !== null) {
            // FROM THE MOBILE APP: its web view carries the phone's id; register that phone to this demo company, approved.
            $appDev = substr((string) preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($_COOKIE['sprs_app_device'] ?? '')), 0, 80);
            $u = $login['user'];
            $tid = (string) $login['tenant']['id'];

            if ($appDev !== '') {
                $tStore = $platform->forTenant($tid);
                $mine = array_filter($tStore->all('mobile_devices'), static fn (array $d): bool => (string) $d['device_id'] === $appDev && (string) ($d['user_id'] ?? '') === (string) $u['id']);
                $mine === [] && $tStore->put('mobile_devices', ['device_id' => $appDev, 'user_id' => $u['id'], 'status' => 'approved', 'approved_by' => 'Auto — Explore Demo',
                    'approved_at' => date('Y-m-d H:i:s'), 'code' => strtoupper(substr($appDev, -6)), 'platform' => 'android', 'created_at' => date('Y-m-d H:i:s')]);
            }

            session_regenerate_id(true);
            $_SESSION = ['user' => ['id' => $u['id'], 'email' => $u['email'], 'role' => 'administrator', 'employee_id' => null, 'tenant_id' => $tid],
                'demo_pass' => ['id' => (string) $row['id']]];

            if ((string) ($row['last_login_at'] ?? '') === '') {
                $platform->put('demo_requests', ['id' => $row['id'], 'last_login_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            }

            header('Location: app.php?r=demo-setup');
            exit;
        }
    }
}
// The website's contact block (Super Admin → Landing Page (CMS)) for the footer and WhatsApp button.
$lc = [];
try {
    $lc = (new SmartPRS\Surface\LandingContent(__DIR__ . '/../config/landing.json', isset($platform) ? new PlatformSettings($platform) : null))->content();
} catch (\Throwable $e) {
    $lc = [];
}
$ct = (array) ($lc['contact'] ?? []);
$waNo = (string) preg_replace('/\D+/', '', (string) ($ct['whatsapp'] ?? ''));
$askedPin = $notice !== null || ($_POST['op'] ?? '') === 'enter';
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Explore the SmartPRS demo</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="icon" type="image/png" href="images/logo-icon.png">
<style>
/* EXPLORE DEMO PAGE — Rovexy's explore-demo layout in the SmartPRS 3 palette (owner, 12 Nov 2026). */
:root{--navy:#0a1628;--navy2:#102744;--accent:#f97316;--accent2:#fb923c;--bg:#f6f8fb;--border:#e6ebf2;--text:#0f1d33;--text2:#516074;--text3:#94a3b8;--green:#10b981;--bad:#b91c1c;--ok:#15803d}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Plus Jakarta Sans',sans-serif}
body{background:var(--bg);color:var(--text);line-height:1.55;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
.wrap{max-width:1160px;margin:0 auto;padding:0 clamp(16px,4vw,28px)}
nav{position:sticky;top:0;z-index:50;background:var(--navy);border-bottom:1px solid rgba(255,255,255,.08)}
nav .wrap{display:flex;align-items:center;justify-content:space-between;height:66px;gap:16px}
.nl{display:flex;gap:24px;align-items:center;font-size:14.5px;color:rgba(255,255,255,.78)}.nl a:hover{color:#fff}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:9px;padding:12px 22px;border-radius:10px;font-weight:700;font-size:15px;cursor:pointer;border:0;transition:.18s}
.btn-accent{background:var(--accent);color:#fff;box-shadow:0 10px 26px rgba(249,115,22,.3)}.btn-accent:hover{background:#ea580c}
.btn-out{border:1.5px solid rgba(255,255,255,.35);color:#fff;padding:9px 18px;font-size:14px}
main{padding:56px 0 80px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:28px;align-items:start}
.pitch{background:radial-gradient(ellipse at 20% 0%,#17345c 0,var(--navy2) 40%,var(--navy) 85%);color:#fff;border-radius:22px;padding:clamp(28px,4vw,44px)}
.eyebrow{color:var(--accent2);font-weight:700;font-size:14px}
.pitch h1{font-size:clamp(30px,3.6vw,44px);font-weight:800;letter-spacing:-1px;line-height:1.1;margin:10px 0 16px}
.pitch>p{color:rgba(255,255,255,.75);font-size:17px;margin-bottom:26px}
.pitch ul{list-style:none;display:grid;gap:18px;margin-bottom:30px}
.pitch li{display:flex;gap:14px}.pitch li i{width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,.1);display:grid;place-items:center;font-size:12px;flex:none;margin-top:2px}
.pitch li b{display:block;font-size:16px}.pitch li span{color:rgba(255,255,255,.7);font-size:14.5px}
.pitch .small{color:rgba(255,255,255,.6);font-size:13px;margin-top:12px}
.card{background:#fff;border:1px solid var(--border);border-radius:22px;padding:clamp(24px,3.4vw,36px);box-shadow:0 24px 50px -30px rgba(15,29,51,.35)}
.card h2{font-size:20px;margin-bottom:4px}.card .note{color:var(--text2);font-size:14px;margin-bottom:18px}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
label{display:block;font-weight:700;font-size:12.5px;color:#334155;margin:14px 0 6px}
input{width:100%;border:1.5px solid #cbd5e1;border-radius:10px;padding:11px 12px;font:inherit;font-size:15px;background:#f8fafc;color:var(--text)}
input:focus{outline:none;border-color:var(--accent);background:#fff}
input.pin{font-size:26px;letter-spacing:10px;text-align:center;font-weight:800}
.card .btn{width:100%;margin-top:20px}
.msg{padding:12px 14px;border-radius:10px;font-size:14px;margin-bottom:14px}.bad{background:rgba(185,28,28,.08);color:var(--bad)}.ok{background:rgba(21,128,61,.1);color:var(--ok)}
.sep{display:flex;align-items:center;gap:12px;color:var(--text3);font-size:13px;margin:26px 0 6px}.sep::before,.sep::after{content:'';flex:1;height:1px;background:var(--border)}
.hp{position:absolute;left:-9999px}
.terms{color:var(--text3);font-size:12.5px;margin-top:12px}
.wa{position:fixed;right:22px;bottom:22px;background:#25d366;color:#fff;font-weight:700;padding:12px 18px;border-radius:30px;box-shadow:0 12px 26px rgba(37,211,102,.4);display:flex;gap:8px;align-items:center;z-index:60}
@media(max-width:900px){.grid{grid-template-columns:1fr}.nl a:not(.btn){display:none}footer .cols{grid-template-columns:1fr 1fr}}
@media(max-width:520px){.row2,footer .cols{grid-template-columns:1fr}}
</style></head><body>
<nav><div class="wrap">
  <a href="./" title="SmartPRS home"><img src="images/logo.png" alt="SmartPRS" style="height:38px;width:auto;display:block"></a>
  <div class="nl"><a href="./#how">How it works</a><a href="./#product">Product</a><a href="./#pricing">Pricing</a><a href="./#faq">FAQ</a><a href="app.php?r=signin">Sign in</a>
    <a href="./#contact" class="btn btn-out">Book a live demo</a></div>
</div></nav>
<main><div class="wrap grid">
  <section class="pitch">
    <div class="eyebrow">Explore Demo</div>
    <h1>See SmartPRS at work in your own company</h1>
    <p>Your own SmartPRS, already full: two branches, their teams, two months of attendance, leave and payroll — ready the moment you sign in.</p>
    <ul>
      <li><i class="fas fa-check"></i><div><b>Two branches and their teams</b><span>Branch Admin, Operations Manager, Team Leads and agents — each with a login of their own.</span></div></li>
      <li><i class="fas fa-check"></i><div><b>Two months of real-looking data</b><span>QR punches, late logins, approved leave and payroll already generated — the older month approved.</span></div></li>
      <li><i class="fas fa-check"></i><div><b>Every feature switched on</b><span>QR attendance, approvals, payslips, statutory reports, HR letters, RBI audit — and the mobile app.</span></div></li>
      <li><i class="fas fa-check"></i><div><b>Change anything</b><span>Add your own people, rules and salary structures; it is your demo for <?= (int) $days ?> day<?= $days === 1 ? '' : 's' ?>.</span></div></li>
    </ul>
    <a href="#start" class="btn btn-accent"><i class="fas fa-play"></i> Open my demo</a>
    <p class="small">We send a 6-digit passkey to your email and WhatsApp; enter it and your company opens.</p>
  </section>
  <section class="card" id="start">
    <?php if ($problem !== null): ?><div class="msg bad"><?= $h($problem) ?></div><?php endif; ?>
    <?php if ($notice !== null): ?><div class="msg ok"><?= $h($notice) ?></div><?php endif; ?>
    <?php if (!$askedPin): ?>
    <form method="post" autocomplete="on">
      <h2>Open your demo company</h2><p class="note">Takes a minute. No card, nothing to install.</p>
      <input type="hidden" name="csrf" value="<?= $h($_SESSION['demo_csrf']) ?>"><input type="hidden" name="op" value="request">
      <input class="hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
      <div class="row2">
        <div><label for="name">Your name</label><input id="name" name="name" required maxlength="120" value="<?= $h((string) ($_POST['name'] ?? '')) ?>"></div>
        <div><label for="company">Company name</label><input id="company" name="company" required maxlength="160" value="<?= $h((string) ($_POST['company'] ?? '')) ?>"></div>
        <div><label for="mobile">Mobile number</label><input id="mobile" name="mobile" required inputmode="tel" maxlength="20" value="<?= $h((string) ($_POST['mobile'] ?? '')) ?>"></div>
        <div><label for="email">Work e-mail</label><input id="email" name="email" type="email" required maxlength="160" value="<?= $h((string) ($_POST['email'] ?? '')) ?>"></div>
      </div>
      <button class="btn btn-accent" type="submit">Send my passkey <i class="fas fa-arrow-right"></i></button>
      <p class="terms">By continuing you agree to be contacted by Ametecs India about SmartPRS.</p>
    </form>
    <div class="sep">Already have a passkey?</div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
      <?php if ($askedPin): ?><h2>Enter your passkey</h2><p class="note">The 6-digit passkey from your email or WhatsApp.</p><?php endif; ?>
      <input type="hidden" name="csrf" value="<?= $h($_SESSION['demo_csrf']) ?>"><input type="hidden" name="op" value="enter">
      <label for="pin">Passkey</label><input id="pin" class="pin" name="pin" required inputmode="numeric" pattern="\d{6}" maxlength="6" <?= $askedPin ? 'autofocus' : '' ?>>
      <button class="btn btn-accent" type="submit"><i class="fas fa-play"></i> Open my demo company</button>
    </form>
    <?php if ($askedPin): ?><p class="terms" style="text-align:center"><a href="demo.php" style="color:var(--accent)">Use a different email or mobile</a></p><?php endif; ?>
  </section>
</div></main>
<?php $sfContact = $ct; $sfAbout = 'HR, attendance and payroll for India\'s collections & recovery industry. A product of Ametecs India Pvt. Ltd.'; $sfHome = './';
require __DIR__ . '/../surface/partials/site-footer.php'; ?>
<?php if ($waNo !== ''): ?><a class="wa" href="https://wa.me/<?= $h($waNo) ?>?text=<?= urlencode('Hi! I would like to know more about SmartPRS.') ?>" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> WhatsApp us</a><?php endif; ?>
</body></html>
