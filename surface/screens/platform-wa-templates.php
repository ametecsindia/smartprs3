<?php declare(strict_types=1); if (!$may('tenant.settings.update') || !$isPlatformAdmin): ?>
<div class="page-header"><div><h2>WhatsApp Templates</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Platform WhatsApp templates are managed from the Super Admin console (/super).</div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's platform template registry (rev 92): write the template here → copy it into the
// Interakt dashboard and submit it → mark it Approved → test it. The platform sends a purpose
// (welcome, payment, renewal, lead, otp) through the APPROVED template of that purpose.
// Written by surface/actions/platform-wa-templates.php.
$wtPurposes = ['welcome' => 'Signup welcome', 'payment' => 'Payment confirmation', 'renewal' => 'Renewal reminder',
    'lead' => 'Website lead alert', 'otp' => 'OTP / verification (Authentication)', 'custom' => 'Custom'];
$wtStatusBadge = ['draft' => 'gray', 'submitted' => 'blue', 'approved' => 'green', 'rejected' => 'red'];
try {
    $wtRows = $platform->all('wa_templates');
} catch (\Throwable) {
    $wtRows = null;
}
$wtEdit = null;
foreach ($wtRows ?? [] as $t) {
    if ((string) $t['id'] === (string) ($_GET['edit'] ?? '')) {
        $wtEdit = $t;
    }
}
$wtOrder = array_flip(array_keys($wtPurposes));
$wtRows === null || usort($wtRows, static fn (array $a, array $b): int => [$wtOrder[$a['purpose']] ?? 9, (string) $a['name']] <=> [$wtOrder[$b['purpose']] ?? 9, (string) $b['name']]);
$hid = static fn (string $op, string $id = ''): string => '<input type="hidden" name="csrf" value="' . e($session->token()) . '"><input type="hidden" name="action" value="platform-wa-templates"><input type="hidden" name="op" value="' . e($op) . '"><input type="hidden" name="id" value="' . e($id) . '">';
$wtReady = (new SmartPRS\Surface\WhatsApp($platform, new SmartPRS\Surface\PlatformSettings($platform), dirname(__DIR__, 2) . '/.env'))->ready();
?>
<div class="page-header"><div><h2>WhatsApp Templates</h2>
  <p>Write a template here, copy it into the <a href="https://app.interakt.ai/templates/list" target="_blank" rel="noopener">Interakt dashboard</a> and submit it for approval, then mark it <b>Approved</b> here. The platform sends each purpose through its approved template.</p></div>
  <div class="row" style="gap:8px">
<?php if ($wtRows === []): ?><form method="post"><?= $hid('seed') ?><button class="btn btn-outline" type="submit"><i class="fas fa-wand-magic-sparkles"></i> Load SmartPRS defaults</button></form><?php endif; ?>
    <form method="post"><?= $hid('export') ?><button class="btn btn-outline" type="submit"><i class="fas fa-file-csv"></i> Export CSV</button></form>
    <a class="go" href="?r=platform-wa-templates&amp;edit=new#form"><i class="fas fa-plus"></i> New template</a></div></div>
<?php if ($wtRows === null): ?><div class="card"><div class="problem" style="margin:0">The templates table is not there yet — run the database migration.</div></div><?php return; endif; ?>
<?php if (!$wtReady): ?><div class="card"><div class="note" style="margin:0"><i class="fas fa-circle-info"></i> WhatsApp (Interakt) is not connected yet — tests will fail until the key is saved under WhatsApp API (Interakt).</div></div><?php endif; ?>
<?php if ($wtEdit !== null || ($_GET['edit'] ?? '') === 'new'): $t = $wtEdit ?? []; ?>
<div class="card" id="form"><h3 style="margin-top:0"><?= $wtEdit === null ? 'New template' : 'Edit ' . e((string) $t['name']) ?></h3>
  <form method="post"><?= $hid('save', (string) ($t['id'] ?? '')) ?>
    <div class="row" style="gap:12px;flex-wrap:wrap">
      <div><label>Purpose</label><select name="purpose"><?php foreach ($wtPurposes as $k => $l): ?><option value="<?= $k ?>"<?= ($t['purpose'] ?? 'custom') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label>Template name (exactly as in Interakt)</label><input name="name" required pattern="[a-z0-9_]+" value="<?= e((string) ($t['name'] ?? '')) ?>" placeholder="smartprs_welcome"></div>
      <div><label>Language</label><input name="language" value="<?= e((string) ($t['language'] ?? 'en')) ?>" style="width:80px"></div>
      <div><label>Category</label><select name="category"><?php foreach (['utility', 'marketing', 'authentication'] as $c): ?><option<?= ($t['category'] ?? 'utility') === $c ? ' selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select></div></div>
    <label style="margin-top:10px;display:block">Body — variables as {{1}}, {{2}}… without gaps</label>
    <textarea name="body" rows="6" required style="width:100%"><?= e((string) ($t['body'] ?? '')) ?></textarea>
    <label style="margin-top:10px;display:block">Sample values, in order (comma-separated — Interakt asks for these, and tests use them)</label>
    <input name="sample_values" style="width:100%" value="<?= e((string) ($t['sample_values'] ?? '')) ?>">
    <div class="row" style="gap:8px;margin-top:12px"><button class="go" type="submit"><i class="fas fa-floppy-disk"></i> Save</button><a class="btn btn-outline" href="?r=platform-wa-templates">Cancel</a></div></form></div>
<?php endif; ?>
<div class="card" style="padding:0"><div class="table-wrap"><table>
  <thead><tr><th>Purpose</th><th>Template</th><th>Body</th><th>Status</th><th>Last test</th><th></th></tr></thead><tbody>
<?php foreach ($wtRows as $t): $id = (string) $t['id']; ?>
  <tr><td><?= e($wtPurposes[(string) $t['purpose']] ?? (string) $t['purpose']) ?></td>
    <td><code><?= e((string) $t['name']) ?></code><div class="note"><?= e((string) $t['category']) ?> · <?= e((string) $t['language']) ?> · <?= (int) $t['var_count'] ?> var(s)</div></td>
    <td style="max-width:380px;white-space:pre-wrap;font-size:12.5px"><?= e((string) $t['body']) ?></td>
    <td><form method="post" style="display:inline"><?= $hid('status', $id) ?><select name="status" onchange="this.form.submit()"><?php foreach (array_keys($wtStatusBadge) as $s): ?><option value="<?= $s ?>"<?= (string) $t['status'] === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></form>
      <div><span class="badge badge-<?= $wtStatusBadge[(string) $t['status']] ?? 'gray' ?>"><?= e(ucfirst((string) $t['status'])) ?></span></div></td>
    <td class="note"><?= $t['last_test_at'] ? e(SmartPRS\Surface\Dates::dmy(substr((string) $t['last_test_at'], 0, 10)) . ' ' . substr((string) $t['last_test_at'], 11, 5)) : '—' ?><?= ($t['last_error'] ?? '') !== '' && $t['last_error'] !== null ? '<div style="color:#b91c1c">' . e((string) $t['last_error']) . '</div>' : '' ?></td>
    <td style="white-space:nowrap">
      <form method="post" style="display:inline-flex;gap:4px"><?= $hid('test', $id) ?><input name="mobile" placeholder="mobile" required style="width:120px"><button class="btn btn-sm btn-outline" type="submit" title="Send with the sample values"><i class="fab fa-whatsapp"></i> Test</button></form>
      <a class="btn btn-sm btn-outline" href="?r=platform-wa-templates&amp;edit=<?= e($id) ?>#form">Edit</a>
      <form method="post" style="display:inline" onsubmit="return confirm('Delete template <?= e((string) $t['name']) ?>?')"><?= $hid('delete', $id) ?><button class="btn btn-sm btn-outline" type="submit"><i class="fas fa-trash"></i></button></form></td></tr>
<?php endforeach; if ($wtRows === []): ?><tr><td colspan="6" class="note" style="text-align:center;padding:24px">No templates yet — load the SmartPRS defaults or add one.</td></tr><?php endif; ?>
  </tbody></table></div></div>
