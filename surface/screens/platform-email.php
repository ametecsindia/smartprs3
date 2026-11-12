<?php declare(strict_types=1); if (!$may('tenant.settings.update') || !$isPlatformAdmin): ?>
<div class="page-header"><div><h2>Platform Email (SMTP)</h2></div></div>
<div class="card"><div class="problem" style="margin:0">The platform mailbox is managed from the Super Admin console (/super).</div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's "Platform Email (SMTP)": the platform's own FROM mailbox — new-workspace credentials,
// demo passkeys, website-lead alerts. Written by surface/actions/platform-email.php.
$pmEnv = dirname(__DIR__, 2) . '/.env';
$pm = (new SmartPRS\Surface\PlatformMail(new SmartPRS\Surface\PlatformSettings($platform), $pmEnv))->config();
$hid = static fn (string $op): string => '<input type="hidden" name="csrf" value="' . e($session->token()) . '"><input type="hidden" name="action" value="platform-email"><input type="hidden" name="op" value="' . e($op) . '">';
?>
<div class="page-header"><div><h2>Platform Email (SMTP)</h2>
  <p>The mailbox the platform itself sends from — new workspace logins, demo passkeys, website-lead alerts. A company's own alert mail keeps using that company's own mail server.</p></div>
  <span class="badge badge-<?= $pm['enabled'] && $pm['host'] !== '' ? 'green' : 'gray' ?>"><?= $pm['enabled'] && $pm['host'] !== '' ? 'Sending on' : 'Not sending' ?></span></div>
<div class="card"><h3 style="margin-top:0">Mail server</h3>
  <form method="post" class="row" style="gap:12px;flex-wrap:wrap;align-items:flex-end"><?= $hid('save') ?>
    <div><label>SMTP host</label><input name="host" value="<?= e((string) $pm['host']) ?>" placeholder="smtp.gmail.com"></div>
    <div><label>Port</label><input name="port" type="number" value="<?= (int) $pm['port'] ?>" style="width:90px"></div>
    <div><label>Encryption</label><select name="encryption"><?php foreach (['tls' => 'TLS (587)', 'ssl' => 'SSL (465)', 'none' => 'None'] as $k => $l): ?><option value="<?= $k ?>"<?= $pm['encryption'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div><label>Username</label><input name="username" value="<?= e((string) $pm['username']) ?>" autocomplete="off"></div>
    <div><label>Password<?= $pm['has_password'] ? ' (saved — blank keeps it)' : '' ?></label><input name="password" type="password" autocomplete="new-password"></div>
    <div><label>From email</label><input name="from_email" type="email" value="<?= e((string) $pm['from_email']) ?>" placeholder="no-reply@smartprs.com"></div>
    <div><label>From name</label><input name="from_name" value="<?= e((string) $pm['from_name']) ?>"></div>
    <div><label>Reply-to</label><input name="reply_to" type="email" value="<?= e((string) $pm['reply_to']) ?>"></div>
    <div><label><input type="checkbox" name="enabled" value="1"<?= $pm['enabled'] ? ' checked' : '' ?>> Send from this mailbox</label></div>
    <button class="go" type="submit"><i class="fas fa-floppy-disk"></i> Save</button></form></div>
<div class="card"><h3 style="margin-top:0">Send a test email</h3>
  <form method="post" class="row" style="gap:10px;flex-wrap:wrap;align-items:flex-end"><?= $hid('test') ?>
    <div><label>To</label><input name="to" type="email" required value="<?= e((string) ($session->user()['email'] ?? '')) ?>"></div>
    <button class="btn btn-outline" type="submit"><i class="fas fa-paper-plane"></i> Send test</button></form></div>
