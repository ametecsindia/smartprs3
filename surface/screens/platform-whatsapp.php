<?php declare(strict_types=1); if (!$may('tenant.settings.update') || !$isPlatformAdmin): ?>
<div class="page-header"><div><h2>WhatsApp API (Interakt)</h2></div></div>
<div class="card"><div class="problem" style="margin:0">The platform WhatsApp key is managed from the Super Admin console (/super).</div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's platform "WhatsApp API (Interakt)": the key for PLATFORM sends — workspace welcome,
// demo passkeys (OTP template), website-lead alerts. Written by surface/actions/platform-whatsapp.php.
$waSet = new SmartPRS\Surface\PlatformSettings($platform);
$wa = (new SmartPRS\Surface\WhatsApp($platform, $waSet, dirname(__DIR__, 2) . '/.env'))->config();
try {
    $waLog = $platform->all('wa_log');
} catch (\Throwable) {
    $waLog = []; // table arrives with the 16 Oct migration
}
usort($waLog, static fn (array $a, array $b): int => (int) $b['id'] <=> (int) $a['id']);
$hid = static fn (string $op): string => '<input type="hidden" name="csrf" value="' . e($session->token()) . '"><input type="hidden" name="action" value="platform-whatsapp"><input type="hidden" name="op" value="' . e($op) . '">';
?>
<div class="page-header"><div><h2>WhatsApp API (Interakt)</h2>
  <p>The Interakt account the platform sends from. Paste the API key from Interakt → Settings → Developer Setting. Templates are managed under WhatsApp Templates.</p></div>
  <span class="badge badge-<?= $wa['enabled'] && $wa['has_key'] ? 'green' : 'gray' ?>"><?= $wa['enabled'] && $wa['has_key'] ? 'Connected' : 'Not connected' ?></span></div>
<div class="card"><h3 style="margin-top:0">Interakt account</h3>
  <form method="post" class="row" style="gap:12px;flex-wrap:wrap;align-items:flex-end"><?= $hid('save') ?>
    <div style="flex:1;min-width:260px"><label>API key<?= $wa['has_key'] ? ' (saved — blank keeps it)' : '' ?></label><input name="key" type="password" autocomplete="off" placeholder="Base64 key from Interakt"></div>
    <div style="flex:1;min-width:260px"><label>API URL</label><input name="url" value="<?= e($wa['url']) ?>"></div>
    <div><label>Country code</label><input name="country_code" value="<?= e($wa['country_code']) ?>" style="width:80px"></div>
    <div><label><input type="checkbox" name="enabled" value="1"<?= $wa['enabled'] ? ' checked' : '' ?>> Active</label></div>
    <button class="go" type="submit"><i class="fas fa-floppy-disk"></i> Save</button></form></div>
<div class="card"><h3 style="margin-top:0">Send a test message</h3>
  <form method="post" class="row" style="gap:10px;flex-wrap:wrap;align-items:flex-end"><?= $hid('test') ?>
    <div><label>Mobile</label><input name="mobile" required placeholder="98765 43210"></div>
    <div><label>Template name</label><input name="template" required value="smartprs_otp"></div>
    <div style="flex:1;min-width:220px"><label>Values {{1}}, {{2}}… (comma-separated)</label><input name="values" value="482913"></div>
    <button class="btn btn-outline" type="submit"><i class="fab fa-whatsapp"></i> Send test</button></form></div>
<div class="card" style="padding:0"><div style="padding:14px 18px"><b>Recent sends</b> <span class="note">(last 25)</span></div><div class="table-wrap"><table>
  <thead><tr><th>When</th><th>Mobile</th><th>Template</th><th>Kind</th><th>Status</th><th>Detail</th></tr></thead><tbody>
<?php foreach (array_slice($waLog, 0, 25) as $l): ?>
  <tr><td class="note"><?= e(SmartPRS\Surface\Dates::dmy(substr((string) $l['created_at'], 0, 10)) . ' ' . substr((string) $l['created_at'], 11, 5)) ?></td>
    <td><?= e((string) $l['mobile']) ?></td><td><code><?= e((string) $l['template']) ?></code></td><td><?= e((string) $l['kind']) ?></td>
    <td><span class="badge badge-<?= (string) $l['status'] === 'sent' ? 'green' : 'red' ?>"><?= e((string) $l['status']) ?></span></td>
    <td class="note"><?= e((string) ($l['error'] ?? '')) ?></td></tr>
<?php endforeach; if ($waLog === []): ?><tr><td colspan="6" class="note" style="text-align:center;padding:24px">Nothing sent yet.</td></tr><?php endif; ?>
  </tbody></table></div></div>
