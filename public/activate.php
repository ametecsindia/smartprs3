<?php
/**
 * ON-PREMISE ACTIVATION — SmartPRS 2's /activate screen. Shows this server's
 * fingerprint (what Ametecs needs to issue a machine-locked .lic) and takes either
 * a License Code (SPRS-XXXX-…, activated online) or a .lic file (pasted or
 * uploaded). The licence gate in public/app.php sends an unlicensed or expired
 * on-premise install here; on the cloud this page only says it is not needed.
 */
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'SmartPRS\\Surface\\')) {
        $f = __DIR__ . '/../surface/' . substr($class, 17) . '.php';

        if (is_file($f)) {
            require_once $f;
        }
    }
});

use SmartPRS\Surface\Licence;

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
$h = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$lic = new Licence();
$msg = null;
$ok = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $lic->isOnPrem()) {
    if (!hash_equals((string) ($_SESSION['act_csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        $msg = 'The form expired — reload the page and try again.';
    } elseif (((int) ($_SESSION['act_tries'] ?? 0)) >= 10 && time() - (int) ($_SESSION['act_first'] ?? 0) < 600) {
        $msg = 'Too many attempts. Wait ten minutes and try again.';
    } else {
        if (time() - (int) ($_SESSION['act_first'] ?? 0) >= 600) {
            $_SESSION['act_first'] = time();
            $_SESSION['act_tries'] = 0;
        }

        $_SESSION['act_tries'] = (int) ($_SESSION['act_tries'] ?? 0) + 1;
        $input = trim((string) ($_POST['code'] ?? ''));

        if ($input === '' && is_uploaded_file((string) ($_FILES['lic']['tmp_name'] ?? ''))) {
            $input = trim((string) file_get_contents((string) $_FILES['lic']['tmp_name']));
        }

        $active = 0;
        $pdo = SmartPRS\Surface\Database::connect(__DIR__ . '/../.env');

        if ($pdo !== null) {
            $free = implode(',', array_map(static fn (string $s): string => $pdo->quote($s), Licence::SEAT_FREE));
            $active = (int) $pdo->query("SELECT COUNT(*) FROM employees WHERE deleted_at IS NULL AND (status IS NULL OR LOWER(status) NOT IN ({$free}))")->fetchColumn();
        }

        $r = $input === '' ? ['ok' => false, 'error' => 'Enter the License Code or choose the .lic file.'] : $lic->activate($input, $active);
        $ok = $r['ok'];
        $msg = $ok ? 'Activated' . (($r['company'] ?? '') !== '' ? ' for ' . $r['company'] : '') . '. You can sign in now.' : (string) $r['error'];
    }
}

$_SESSION['act_csrf'] ??= bin2hex(random_bytes(16));
$st = $lic->status();
$labels = ['none' => 'Not activated', 'ok' => 'Active', 'expired' => 'Expired'];
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Activate SmartPRS</title>
<style>
:root{--ink:#0f172a;--muted:#64748b;--line:#e2e8f0;--brand:#006699;--ok:#15803d;--bad:#b91c1c;--bg:#f1f5f9;--card:#fff}
@media (prefers-color-scheme:dark){:root{--ink:#e2e8f0;--muted:#94a3b8;--line:#334155;--bg:#0b1220;--card:#111827}}
body{margin:0;font:15px/1.5 system-ui,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--ink)}
.wrap{max-width:620px;margin:48px auto;padding:0 16px}.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:24px;margin-bottom:16px}
h1{font-size:22px;margin:0 0 4px}p.note{color:var(--muted);margin:4px 0 0}code{font-size:13px;word-break:break-all;background:rgba(0,102,153,.08);padding:2px 6px;border-radius:4px}
label{display:block;font-weight:600;margin:14px 0 6px}textarea,input[type=file]{width:100%;box-sizing:border-box}textarea{min-height:90px;border:1px solid var(--line);border-radius:8px;padding:10px;font:13px monospace;background:var(--card);color:var(--ink)}
button{margin-top:16px;background:var(--brand);color:#fff;border:0;border-radius:8px;padding:10px 18px;font-weight:600;cursor:pointer}
.msg{padding:10px 14px;border-radius:8px;margin-bottom:16px}.msg.ok{background:rgba(21,128,61,.12);color:var(--ok)}.msg.bad{background:rgba(185,28,28,.1);color:var(--bad)}
dl{display:grid;grid-template-columns:150px 1fr;gap:6px 12px;margin:12px 0 0}dt{color:var(--muted)}dd{margin:0}a{color:var(--brand)}
</style></head><body><div class="wrap">
<div class="card"><h1>Activate SmartPRS</h1>
<?php if (!$lic->isOnPrem()): ?>
  <p class="note">This is a cloud installation — no licence activation is needed. <a href="app.php">Go to sign in</a>.</p></div>
<?php else: ?>
  <p class="note">Enter the License Code Ametecs sent you, or the .lic licence file. One licence works on one server.</p>
  <dl><dt>Status</dt><dd><b><?= $h($labels[$st['state']] ?? $st['state']) ?></b><?= $st['expires_on'] ? ' · valid till ' . $h(date('d-m-Y', strtotime($st['expires_on']))) : '' ?><?= $st['company'] !== '' ? ' · ' . $h($st['company']) : '' ?><?= $st['reason'] !== '' ? ' · ' . $h(str_replace('_', ' ', $st['reason'])) : '' ?></dd>
    <dt>This server</dt><dd><code><?= $h($lic->fingerprint()) ?></code></dd>
    <dt>Licence server</dt><dd><?= $h($lic->server()) ?></dd>
    <?php if ($st['seats'] !== null): ?><dt>Employees</dt><dd>up to <?= (int) $st['seats'] ?></dd><?php endif; ?></dl>
</div>
<?php if ($msg !== null): ?><div class="msg <?= $ok ? 'ok' : 'bad' ?>"><?= $h($msg) ?></div><?php endif; ?>
<form class="card" method="post" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= $h($_SESSION['act_csrf']) ?>">
  <label for="code">License Code or licence file contents</label>
  <textarea id="code" name="code" placeholder="SPRS-XXXX-XXXX-XXXX-XXXX"></textarea>
  <label for="lic">…or upload the .lic file</label>
  <input id="lic" type="file" name="lic" accept=".lic,.txt">
  <button type="submit">Activate</button>
  <p class="note">Need help? Send the server fingerprint above to <?= $h(Licence::CONTACT) ?>. <a href="app.php">Back to sign in</a></p>
</form>
<?php endif; ?>
</div></body></html>
