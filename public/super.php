<?php
/**
 * THE SUPER ADMIN DOOR — SmartPRS 2's /super login portal.
 *
 * A SEPARATE CONSOLE, not a corner of the company app (owner, 16 Oct 2026). Its own
 * cookie (SMARTPRS_SUPER — a company login on app.php is a different session and
 * never sees or shares it), its own sign-in against `platform_users`, its own
 * navy header with only the platform menu. Once signed in, this file serves the
 * platform screens itself (/super?r=tenants …) by running app.php's screen engine
 * in console mode — app.php refuses a platform session at its own door.
 *
 * FIRST RUN: with no platform account yet, the page asks for the one-time setup
 * token written to storage/super-setup-token.txt on the server — readable only by
 * whoever has the server's files — and creates the first Super Admin from it.
 * (CLI alternative: php tools/make-super-admin.php email@x "Name")
 *
 * FORGOTTEN PASSWORD (5 Nov 2026): "Forgot password?" emails a one-time link (?reset=…) from the platform
 * mailbox (config/password-reset.json: link hours, request limit). When no mailbox is set up yet, a one-time
 * token in storage/super-reset-token.txt turns the page into a reset form instead; the file is deleted once used.
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

use SmartPRS\Platform\Identity\PasswordHasher;
use SmartPRS\Surface\Database;
use SmartPRS\Surface\PlatformMail;
use SmartPRS\Surface\PlatformSettings;
use SmartPRS\Surface\Session;
use SmartPRS\Surface\SqlStore;

session_name('SMARTPRS_SUPER');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

if (($_SESSION['user']['platform'] ?? false) === true) {
    define('SMARTPRS_SUPER_CONSOLE', true);
    require __DIR__ . '/app.php';
    exit;
}

$h = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$pdo = Database::connect(__DIR__ . '/../.env');
$problem = null;
$notice = null;

if ($pdo === null) {
    $problem = 'The database is not available: ' . Database::why();
}

$hasher = PasswordHasher::platformDefault();
// A ONE-TIME EMAIL CHANGE (5 Nov 2026): storage/super-email-change.json {"from", "to"}, written on the server by the
// support team, renames that Super Admin login on the next page load and is then deleted.
$emailChangeFile = __DIR__ . '/../storage/super-email-change.json';

if ($pdo !== null && is_file($emailChangeFile)) {
    $ec = json_decode((string) file_get_contents($emailChangeFile), true);

    if (is_array($ec) && trim((string) ($ec['from'] ?? '')) !== '' && trim((string) ($ec['to'] ?? '')) !== '') {
        $pdo->prepare('UPDATE platform_users SET email = ?, updated_at = NOW() WHERE LOWER(email) = ?')
            ->execute([strtolower(trim((string) $ec['to'])), strtolower(trim((string) $ec['from']))]);
        $notice = 'The Super Admin email is now ' . strtolower(trim((string) $ec['to'])) . '.';
    }

    unlink($emailChangeFile);
}
$count = $pdo === null ? 0 : (int) $pdo->query('SELECT COUNT(*) FROM platform_users')->fetchColumn();
$tokenFile = __DIR__ . '/../storage/super-setup-token.txt';
$resetFile = __DIR__ . '/../storage/super-reset-token.txt';
$resetting = $count > 0 && is_file($resetFile);
$fpCfg = json_decode((string) file_get_contents(__DIR__ . '/../config/password-reset.json'), true);
$fpHours = max(1, (int) ($fpCfg['link_hours'] ?? 0));
$settings = $pdo === null ? null : new PlatformSettings(new SqlStore($pdo));
$mode = $count === 0 ? 'create' : ($resetting ? 'token' : (isset($_GET['forgot']) ? 'forgot' : (isset($_GET['reset']) ? 'link' : 'signin')));
$linkToken = (string) ($_GET['reset'] ?? '');
/** The live emailed reset for this token (hashed in platform_settings `super_password_resets`), else null. */
$liveReset = static function (string $token) use ($settings): ?array {
    foreach ((array) ($settings?->get('super_password_resets')['rows'] ?? []) as $row) {
        if ($token !== '' && hash_equals((string) $row['token'], hash('sha256', $token)) && (string) $row['expires_at'] >= date('Y-m-d H:i:s')) {
            return $row;
        }
    }

    return null;
};

if ($pdo !== null && $count === 0 && !is_file($tokenFile)) {
    @file_put_contents($tokenFile, bin2hex(random_bytes(16)) . "\n");
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $pdo !== null) {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if (!hash_equals((string) $_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $problem = 'The form expired — reload the page and try again.';
    } elseif ($count === 0) {
        $token = trim((string) @file_get_contents($tokenFile));

        if ($token === '' || !hash_equals($token, trim((string) ($_POST['token'] ?? '')))) {
            $problem = 'That setup token is not the one in storage/super-setup-token.txt on this server.';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($password) < Session::PASSWORD_FLOOR) {
            $problem = 'A valid email and a password of at least ' . Session::PASSWORD_FLOOR . ' characters are needed.';
        } else {
            $pdo->prepare('INSERT INTO platform_users (name, email, password, role, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())')
                ->execute([trim((string) ($_POST['name'] ?? '')) ?: 'Super Admin', $email, $hasher->hash($password), 'super_admin']);
            @unlink($tokenFile);
            $count = 1;
            $notice = 'Super Admin created. Sign in below.';
        }
    } elseif ($mode === 'forgot') {
        $fpTimes = array_values(array_filter((array) ($_SESSION['fp_times'] ?? []), static fn ($t): bool => (int) $t > time() - 900));
        $mail = new PlatformMail($settings, __DIR__ . '/../.env');

        if (count($fpTimes) >= (int) ($fpCfg['requests_per_15_minutes'] ?? 0)) {
            $problem = 'Too many requests. Try again in 15 minutes.';
        } elseif (!$mail->ready()) {
            $problem = 'Password reset by email is not set up yet (SaaS Platform → Platform Email). Ask another Super Admin to set your password under Platform Staff.';
        } else {
            $_SESSION['fp_times'] = [...$fpTimes, time()];
            $q = $pdo->prepare('SELECT id, email FROM platform_users WHERE LOWER(email) = ? AND disabled_at IS NULL LIMIT 1');
            $q->execute([$email]);

            if (($u = $q->fetch() ?: null) !== null) {
                $token = bin2hex(random_bytes(24));
                $rows = array_values(array_filter((array) ($settings->get('super_password_resets')['rows'] ?? []),
                    static fn (array $row): bool => (string) $row['expires_at'] >= date('Y-m-d H:i:s')));
                $rows[] = ['email' => (string) $u['email'], 'token' => hash('sha256', $token), 'expires_at' => date('Y-m-d H:i:s', time() + $fpHours * 3600)];
                $settings->put('super_password_resets', ['rows' => $rows]);
                $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . strtok((string) $_SERVER['REQUEST_URI'], '?');
                $out = $mail->send([(string) $u['email']], 'Reset your SmartPRS Super Admin password', "Hello,\n\nA password reset was asked for this SmartPRS Super Admin login. Set a new password here (the link works once, for "
                    . $fpHours . ' hour' . ($fpHours === 1 ? '' : 's') . "):\n" . $base . '?reset=' . rawurlencode($token) . "\n\nIf you did not ask for this, ignore this email.");
                $out['ok'] || error_log('super forgot-password mail not sent: ' . $out['why']);
            }

            $notice = 'If a Super Admin login uses that email, a reset link has been sent to it.';
        }
    } elseif ($mode === 'link') {
        $row = $liveReset((string) ($_POST['token'] ?? ''));

        if ($row === null) {
            $problem = 'This link has expired or was already used. Ask for a new one.';
        } elseif (strlen($password) < Session::PASSWORD_FLOOR || $password !== (string) ($_POST['password2'] ?? '')) {
            $problem = 'The new password needs at least ' . Session::PASSWORD_FLOOR . ' characters, typed the same twice.';
        } else {
            $pdo->prepare('UPDATE platform_users SET password = ?, updated_at = NOW() WHERE LOWER(email) = ?')->execute([$hasher->hash($password), strtolower((string) $row['email'])]);
            $settings->put('super_password_resets', ['rows' => array_values(array_filter((array) ($settings->get('super_password_resets')['rows'] ?? []),
                static fn (array $x): bool => (string) $x['token'] !== (string) $row['token']))]);
            $mode = 'signin';
            $notice = 'Password reset for ' . $row['email'] . '. Sign in below.';
        }
    } elseif ($resetting) {
        $token = trim((string) @file_get_contents($resetFile));
        $q = $pdo->prepare('SELECT id, email FROM platform_users WHERE LOWER(email) = ? LIMIT 1');
        $q->execute([$email]);
        $u = $q->fetch() ?: null;

        if ($token === '' || !hash_equals($token, trim((string) ($_POST['token'] ?? '')))) {
            $problem = 'That reset token is not the one in storage/super-reset-token.txt on this server.';
        } elseif ($u === null) {
            // The token proves access to the server's files, so the account names may be shown.
            $problem = 'No Super Admin has that email. Super Admin accounts: '
                . implode(', ', $pdo->query('SELECT email FROM platform_users ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN)) . '.';
        } elseif (strlen($password) < Session::PASSWORD_FLOOR) {
            $problem = 'The new password needs at least ' . Session::PASSWORD_FLOOR . ' characters.';
        } else {
            $pdo->prepare('UPDATE platform_users SET password = ?, updated_at = NOW() WHERE id = ?')->execute([$hasher->hash($password), $u['id']]);
            @unlink($resetFile);
            $resetting = false;
            $mode = 'signin';
            $notice = 'Password reset for ' . $u['email'] . '. Sign in below.';
        }
    } else {
        $tries = (int) ($_SESSION['super_tries'] ?? 0);

        if ($tries >= 5 && time() < (int) ($_SESSION['super_locked'] ?? 0)) {
            $problem = 'Too many attempts. Try again in a few minutes.';
        } else {
            $q = $pdo->prepare('SELECT * FROM platform_users WHERE LOWER(email) = ? LIMIT 1');
            $q->execute([$email]);
            $u = $q->fetch() ?: null;

            if ($u === null || trim((string) ($u['disabled_at'] ?? '')) !== '' || !$hasher->verify($password, (string) $u['password'])) {
                $_SESSION['super_tries'] = $tries + 1;
                $_SESSION['super_locked'] = $tries + 1 >= 5 ? time() + 300 : 0;
                $problem = 'Those credentials were not accepted.';
            } else {
                session_regenerate_id(true);
                $_SESSION['super_tries'] = 0;
                $pdo->prepare('UPDATE platform_users SET last_login_at = NOW() WHERE id = ?')->execute([$u['id']]);
                $_SESSION['user'] = ['id' => 'platform-' . $u['id'], 'email' => (string) $u['email'], 'name' => (string) ($u['name'] ?? ''),
                    // The platform console's own powers are behind tenant.settings.update; a Super Admin holds every one.
                    'role' => 'administrator', 'employee_id' => null, 'tenant_id' => '', 'platform' => true,
                    'platform_role' => (string) $u['role']];
                header('Location: ?r=platform-dashboard');
                exit;
            }
        }
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>SmartPRS Super Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
/* THE SMARTPRS 3 SIGN-IN LOOK (owner, 12 Nov 2026): navy brand panel on the left, the form on the right — same as app.php's sign-in. */
:root{--ink:#0f1d33;--muted:#64748b;--line:#e2e8f0;--navy:#0a1628;--navy2:#102744;--accent:#f97316;--blue:#3b5bdb;--bad:#b91c1c;--ok:#15803d;--card:#fff}
*{box-sizing:border-box}
body{margin:0;font:15px/1.5 'Plus Jakarta Sans',system-ui,sans-serif;color:var(--ink);min-height:100vh;display:grid;grid-template-columns:1.35fr 1fr;background:#fff}
.brand{background:radial-gradient(ellipse at 30% 20%,#17345c 0,var(--navy2) 35%,var(--navy) 80%);color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:40px;gap:26px}
.brand .mark{width:62px;height:62px;border-radius:16px;background:linear-gradient(135deg,#3b82f6,#2563eb);display:grid;place-items:center;font-size:28px;box-shadow:0 10px 30px -8px rgba(59,130,246,.6)}
.brand h2{margin:0;font-size:30px;font-weight:800;letter-spacing:-.5px}.brand h2 span{color:#60a5fa}
.brand .sub{margin:-16px 0 0;color:#94a3b8;font-size:14px}
.brand ul{list-style:none;margin:10px 0 0;padding:0;display:grid;gap:16px;max-width:360px}
.brand li{display:flex;gap:12px;align-items:flex-start;font-size:13px;color:#cbd5e1}.brand li b{display:block;color:#fff;font-size:14px}
.brand li i{width:34px;height:34px;border-radius:9px;background:rgba(59,130,246,.15);color:#60a5fa;display:grid;place-items:center;flex:none}
.side{display:grid;place-items:center;padding:32px}
.card{width:min(400px,100%)}
.tag{display:inline-block;background:var(--navy);color:#fff;font-size:11px;letter-spacing:.08em;text-transform:uppercase;padding:3px 8px;border-radius:4px}
h1{font-size:26px;margin:12px 0 4px;font-weight:800}p{color:var(--muted);margin:0 0 18px}
label{display:block;font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.4px;color:#475569;margin:14px 0 6px}
input{width:100%;border:1.5px solid var(--line);border-radius:10px;padding:11px 12px;font:inherit;background:#f8fafc;color:var(--ink)}
input:focus{outline:none;border-color:var(--blue);background:#fff}
button{width:100%;margin-top:20px;background:linear-gradient(90deg,var(--blue),var(--accent));color:#fff;border:0;border-radius:10px;padding:12px;font:inherit;font-weight:700;cursor:pointer}
.msg{padding:10px 12px;border-radius:8px;margin-bottom:12px;font-size:14px}.bad{background:rgba(185,28,28,.1);color:var(--bad)}.ok{background:rgba(21,128,61,.12);color:var(--ok)}
.card a{color:var(--accent);text-decoration:none}.foot{margin-top:28px;text-align:center;color:#94a3b8;font-size:12px}
code{font-size:12px}
@media (max-width:860px){body{grid-template-columns:1fr}.brand{display:none}}
</style></head><body>
<aside class="brand"><div class="mark"><i class="fas fa-bolt"></i></div><h2>Smart<span>PRS</span></h2><p class="sub">Platform console</p>
  <ul><li><i class="fas fa-building"></i><div><b>Tenants &amp; plans</b>Every customer workspace, its plan, seats and status</div></li>
  <li><i class="fas fa-file-invoice"></i><div><b>Billing</b>Subscriptions, invoices, payments and quotations</div></li>
  <li><i class="fas fa-key"></i><div><b>Licences &amp; demos</b>On-prem licences, releases, Explore Demo requests</div></li></ul></aside>
<div class="side">
<form class="card" method="post" autocomplete="on" action="<?= $h(match ($mode) { 'forgot' => '?forgot=1', 'link' => '?reset=' . rawurlencode($linkToken), default => 'super.php' }) ?>">
  <span class="tag">Super Admin</span>
  <h1><?= $h(['create' => 'Create the Super Admin', 'token' => 'Reset the Super Admin password', 'forgot' => 'Forgot password', 'link' => 'Set a new password', 'signin' => 'Platform sign in'][$mode]) ?></h1>
  <p><?= $h(['create' => 'First run: paste the one-time token from storage/super-setup-token.txt on this server.',
      'token' => 'Paste the one-time reset token, your Super Admin email and a new password.',
      'forgot' => 'Enter your Super Admin email. We will email you a link to set a new password.',
      'link' => 'Choose a new password for your Super Admin login.',
      'signin' => 'SmartPRS platform console — tenants, plans, billing, on-prem licences.'][$mode]) ?></p>
  <?php if ($problem !== null): ?><div class="msg bad"><?= $h($problem) ?></div><?php endif; ?>
  <?php if ($notice !== null): ?><div class="msg ok"><?= $h($notice) ?></div><?php endif; ?>
  <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
  <?php if ($mode === 'link'): ?>
    <?php if ($liveReset($linkToken) === null): ?><div class="msg bad">This link has expired or was already used. <a href="?forgot=1">Ask for a new one</a>.</div><?php else: ?>
    <input type="hidden" name="token" value="<?= $h($linkToken) ?>">
    <label for="password">New password (<?= Session::PASSWORD_FLOOR ?>+ characters)</label><input id="password" name="password" type="password" required minlength="<?= Session::PASSWORD_FLOOR ?>" autocomplete="new-password" autofocus>
    <label for="password2">Repeat it</label><input id="password2" name="password2" type="password" required autocomplete="new-password">
    <button type="submit">Set password</button>
    <?php endif; ?>
  <?php else: ?>
    <?php if ($mode === 'token'): ?><label for="token">Reset token</label><input id="token" name="token" required autocomplete="off"><?php endif; ?>
    <?php if ($mode === 'create'): ?>
      <label for="token">Setup token</label><input id="token" name="token" required autocomplete="off">
      <label for="name">Name</label><input id="name" name="name" value="Super Admin">
    <?php endif; ?>
    <label for="email">Email</label><input id="email" name="email" type="email" required autocomplete="username" autofocus>
    <?php if ($mode !== 'forgot'): ?>
      <label for="password"><?= $mode === 'token' ? 'New password (' . Session::PASSWORD_FLOOR . '+ characters)' : 'Password' . ($mode === 'create' ? ' (' . Session::PASSWORD_FLOOR . '+ characters)' : '') ?></label><input id="password" name="password" type="password" required autocomplete="<?= $mode === 'signin' ? 'current-password' : 'new-password' ?>">
    <?php endif; ?>
    <button type="submit"><?= $h(['create' => 'Create', 'token' => 'Reset password', 'forgot' => 'Send reset link', 'signin' => 'Sign in'][$mode]) ?></button>
  <?php endif; ?>
  <p style="margin:14px 0 0;text-align:right;font-size:14px"><?php if ($mode === 'signin'): ?><a href="?forgot=1">Forgot password?</a><?php elseif ($mode !== 'create' && $mode !== 'token'): ?><a href="super.php">Back to sign in</a><?php endif; ?></p>
  <div class="foot">SmartPRS 3 &middot; by Ametecs India</div>
</form>
</div>
</body></html>
