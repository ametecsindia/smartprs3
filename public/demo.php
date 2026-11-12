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
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>SmartPRS — Explore Demo</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{--navy:#0c1929;--accent:#f97316;--bg:#f0f4f8;--card:#fff;--line:#e2e8f0;--muted:#64748b;--ink:#0f172a;--bad:#b91c1c;--ok:#15803d}
@media (prefers-color-scheme:dark){:root{--bg:#0b1220;--card:#111827;--line:#334155;--muted:#94a3b8;--ink:#e2e8f0}}
*{box-sizing:border-box}body{margin:0;font:15px/1.5 system-ui,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--ink)}
header{background:var(--navy);color:#fff;padding:16px 24px;display:flex;align-items:center;gap:10px;font-weight:800;font-size:18px}
header .mark{width:32px;height:32px;border-radius:8px;background:var(--accent);display:grid;place-items:center}header span{color:var(--accent)}
main{max-width:920px;margin:28px auto;padding:0 16px;display:grid;grid-template-columns:1fr 1fr;gap:18px}
@media (max-width:760px){main{grid-template-columns:1fr}}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:24px}
h1{grid-column:1/-1;margin:0;font-size:24px}.lead{grid-column:1/-1;margin:0;color:var(--muted)}
h2{font-size:16px;margin:0 0 4px}.card p{color:var(--muted);margin:0 0 14px;font-size:13.5px}
label{display:block;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);margin:10px 0 5px}
input{width:100%;border:1.5px solid var(--line);border-radius:9px;padding:10px 12px;font:inherit;background:var(--card);color:var(--ink)}
input.pin{font-size:24px;letter-spacing:8px;text-align:center;font-weight:800}
button{width:100%;margin-top:16px;background:var(--accent);color:#fff;border:0;border-radius:9px;padding:12px;font-weight:700;font-size:15px;cursor:pointer}
.msg{grid-column:1/-1;padding:12px 16px;border-radius:10px;font-size:14px}.bad{background:rgba(185,28,28,.1);color:var(--bad)}.ok{background:rgba(21,128,61,.12);color:var(--ok)}
.hp{position:absolute;left:-9999px}
</style></head><body>
<header><div class="mark"><i class="fas fa-bolt"></i></div>Smart<span>PRS</span>&nbsp;<small style="font-weight:600;opacity:.75;font-size:13px">Explore Demo</small></header>
<main>
  <h1>Explore SmartPRS with your own demo company</h1>
  <p class="lead">Your own company with two branches, their teams, two months of attendance, leaves and payroll — every feature open. Add your own employees and data and test anything. It stays for <?= (int) $days ?> day<?= $days === 1 ? '' : 's' ?>, then it is deleted.</p>
  <?php if ($problem !== null): ?><div class="msg bad"><?= $h($problem) ?></div><?php endif; ?>
  <?php if ($notice !== null): ?><div class="msg ok"><?= $h($notice) ?></div><?php endif; ?>
  <form class="card" method="post" autocomplete="on">
    <h2><i class="fas fa-key" style="color:var(--accent)"></i> 1. Get a passkey</h2>
    <p>We send it to your email and WhatsApp.</p>
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['demo_csrf']) ?>"><input type="hidden" name="op" value="request">
    <input class="hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
    <label for="name">Full name</label><input id="name" name="name" required maxlength="120" value="<?= $h((string) ($_POST['name'] ?? '')) ?>">
    <label for="mobile">Mobile</label><input id="mobile" name="mobile" required inputmode="tel" maxlength="20" value="<?= $h((string) ($_POST['mobile'] ?? '')) ?>">
    <label for="email">Official email</label><input id="email" name="email" type="email" required maxlength="160" value="<?= $h((string) ($_POST['email'] ?? '')) ?>">
    <label for="company">Company</label><input id="company" name="company" required maxlength="160" value="<?= $h((string) ($_POST['company'] ?? '')) ?>">
    <button type="submit"><i class="fas fa-paper-plane"></i> Send my passkey</button>
  </form>
  <form class="card" method="post" autocomplete="off">
    <h2><i class="fas fa-right-to-bracket" style="color:var(--accent)"></i> 2. Enter the passkey</h2>
    <p>The 6-digit passkey from your email or WhatsApp.</p>
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['demo_csrf']) ?>"><input type="hidden" name="op" value="enter">
    <label for="pin">Passkey</label><input id="pin" class="pin" name="pin" required inputmode="numeric" pattern="\d{6}" maxlength="6">
    <button type="submit"><i class="fas fa-play"></i> Open my demo company</button>
  </form>
</main>
</body></html>
