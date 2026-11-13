<?php declare(strict_types=1); if (!$may('tenant.settings.view')): ?>
<div class="page-header"><div><h2>Alert Email Drafts</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// ORGANIZATION CONFIGURATION → ALERT EMAIL DRAFTS (owner, 8 Oct). Every email
// the product sends by itself — certificate expiry (DRA, PCC, any certificate)
// at 15 / 7 / 1 days, probation ending, birthdays, anniversaries, and the
// appraisal / promotion / increment / confirmation letters — with when it goes,
// who gets it, and the draft text with fields to insert. See AlertEmails.
use SmartPRS\Surface\AlertEmails;
use SmartPRS\Surface\Dates;

$aeAlerts = new AlertEmails($store, $customisation, __DIR__ . '/../../config/alert-emails.json');
$aeDecl = $aeAlerts->declared();
$aeList = $aeAlerts->alerts();
$aeSmtp = $aeAlerts->smtp();
$aeEdit = $may('tenant.settings.update');
$aeRoles = $access->roles();
$aeLog = $aeAlerts->log(60);
$aeRanToday = $aeAlerts->ranOn(date('Y-m-d'));
$aeWhen = static function (array $a) use ($aeDecl): string {
    return match ((string) $a['trigger']) {
        'days_before' => implode(', ', (array) $a['days']) . ' day(s) before',
        'on_day' => 'On the day',
        'morning_after' => 'The morning after',
        'increment' => (string) ($aeDecl['when_options'][$a['when'] ?? 'on_record'] ?? ''),
        'confirmation' => 'When confirmed',
        default => '',
    };
};
$aeWho = static function (array $a): string {
    $r = (array) $a['recipients'];
    $p = array_merge(!empty($r['employee']) ? ['Employee'] : [], !empty($r['manager']) ? ['Reporting manager'] : [], (array) ($r['roles'] ?? []),
        trim((string) ($r['cc'] ?? '')) !== '' ? ['CC: ' . $r['cc']] : []);

    return $p === [] ? '— nobody —' : implode(', ', $p);
};
$aeSample = ['emp_name' => 'Asha Rao', 'emp_code' => 'AI2001', 'designation' => 'Collection Executive', 'department' => 'Operations', 'branch' => 'KONDAPUR',
    'company' => 'Your Company', 'reporting_manager' => 'AI1002 · Ravi Kumar', 'doj' => '01-04-2024', 'email' => 'asha@example.com', 'today' => Dates::dmy(date('Y-m-d')),
    'certificate' => 'DRA Certificate', 'expiry_date' => Dates::dmy(date('Y-m-d', strtotime('+7 days'))), 'days_left' => '7', 'probation_end' => Dates::dmy(date('Y-m-d', strtotime('+15 days'))),
    'confirmation_date' => Dates::dmy(date('Y-m-d')), 'years' => '2', 'old_ctc' => '₹6,00,000.00', 'new_ctc' => '₹6,60,000.00', 'increment_amount' => '₹60,000.00',
    'increment_pct' => '10', 'rating' => 'A', 'review_period' => '2026-H2', 'effective_date' => '01-04-2027', 'new_designation' => 'Team Leader'];
?>
<style>
.ae-chip{display:inline-block;margin:0 6px 6px 0;padding:3px 9px;border:1px solid var(--border);border-radius:999px;font-size:12px;background:var(--bg2,#f8fafc);cursor:pointer;color:var(--text2)}
.ae-chip:hover{border-color:var(--accent);color:var(--accent)}
.ae-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end}
.ae-preview{white-space:pre-wrap;border:1px dashed var(--border);border-radius:10px;padding:12px 14px;background:var(--bg2,#f8fafc);font-size:13px;line-height:1.5}
.ae-card summary{cursor:pointer;list-style:none;display:flex;justify-content:space-between;align-items:center;gap:10px}
.ae-card summary::-webkit-details-marker{display:none}
</style>

<div class="page-header">
  <div><h2>Alert Email Drafts</h2><p>Every email SmartPRS sends by itself &mdash; when it goes, who gets it, and the text, with fields like employee name, ID and designation filled in for each person.</p></div>
<?php if ($aeEdit): ?>
  <form method="post" style="margin:0" onsubmit="return confirm('Send everything due today now? Emails already sent are not sent again.')">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="alert-emails"><input type="hidden" name="step" value="run">
    <button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane"></i> Run today's alerts now</button></form>
<?php endif; ?>
</div>

<div class="card">
  <div class="card-header"><div>
    <h3 style="font-size:15px;font-weight:700"><i class="fas fa-server" style="color:var(--accent)"></i> Mail server
      <span class="badge <?= $aeSmtp['enabled'] ? 'badge-green' : 'badge-red' ?>"><?= $aeSmtp['enabled'] ? 'Sending ON' : 'Sending OFF' ?></span></h3>
    <p class="note" style="margin:0">The account the alerts are sent from (Gmail / Google Workspace: smtp.gmail.com, 587, TLS, an app password; Microsoft 365: smtp.office365.com, 587, TLS; Zoho: smtp.zoho.in, 465, SSL).
      Alerts go out automatically once a day, on the first page anyone opens after the "send after" time<?= $aeRanToday ? ' — <b>today\'s run is done</b>' : '' ?>.</p></div></div>
<?php if ($aeEdit): ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="alert-emails"><input type="hidden" name="step" value="smtp">
    <div class="ae-grid">
      <div><label>Mail server (SMTP host)</label><input name="host" value="<?= e((string) $aeSmtp['host']) ?>" placeholder="smtp.gmail.com"></div>
      <div><label>Port</label><input name="port" type="number" min="1" max="65535" value="<?= (int) $aeSmtp['port'] ?>"></div>
      <div><label>Security</label><select name="encryption"><?php foreach (['tls' => 'TLS (STARTTLS, port 587)', 'ssl' => 'SSL (port 465)', 'none' => 'None'] as $k => $l): ?><option value="<?= $k ?>"<?= $aeSmtp['encryption'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <div><label>Username</label><input name="username" value="<?= e((string) $aeSmtp['username']) ?>" autocomplete="off"></div>
      <div><label>Password <?= $aeSmtp['has_password'] ? '<span class="note">(saved — blank keeps it)</span>' : '' ?></label><input name="password" type="password" value="" autocomplete="new-password"></div>
      <div><label>From email</label><input name="from_email" type="email" value="<?= e((string) $aeSmtp['from_email']) ?>"></div>
      <div><label>From name</label><input name="from_name" value="<?= e((string) $aeSmtp['from_name']) ?>"></div>
      <div><label>Reply-to (optional)</label><input name="reply_to" type="email" value="<?= e((string) $aeSmtp['reply_to']) ?>"></div>
      <div><label>Send after (daily)</label><input name="send_after" type="time" value="<?= e((string) $aeSmtp['send_after']) ?>"></div>
      <div><label>Most emails per run</label><input name="max_per_run" type="number" min="1" max="1000" value="<?= (int) $aeSmtp['max_per_run'] ?>"></div>
      <div><label class="note" style="display:flex;gap:8px;align-items:center;font-weight:600"><input type="checkbox" name="enabled" value="1"<?= $aeSmtp['enabled'] ? ' checked' : '' ?> style="width:auto"> Send emails</label></div>
      <div><button class="go" type="submit"><i class="fas fa-check"></i> Save mail server</button></div>
    </div>
  </form>
  <form method="post" style="display:flex;gap:8px;align-items:end;margin-top:12px;padding-top:12px;border-top:1px dashed var(--border)">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="alert-emails"><input type="hidden" name="step" value="test">
    <div style="min-width:260px"><label>Send a test email to</label><input name="test_to" type="email" value="<?= e((string) ($session->user()['email'] ?? '')) ?>"></div>
    <button class="btn btn-outline" type="submit"><i class="fas fa-vial"></i> Send test</button>
  </form>
<?php else: ?>
  <p class="note" style="margin:0"><?= e((string) $aeSmtp['host']) ?>:<?= (int) $aeSmtp['port'] ?> · from <?= e((string) $aeSmtp['from_email']) ?></p>
<?php if (($aeFromWarn = $aeAlerts->fromWarning()) !== null): ?><div class="problem" style="margin:8px 0 0"><?= e($aeFromWarn) ?></div><?php endif; ?>
<?php endif; ?>
</div>

<div class="card" style="padding:0">
  <div class="card-header" style="padding:16px 18px 0"><h3 style="font-size:15px;font-weight:700">All email alerts</h3></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Type</th><th>Alert</th><th>When it is sent</th><th>Who gets it</th><th>Status</th><th></th></tr></thead>
    <tbody>
<?php foreach ($aeList as $k => $a): ?>
    <tr><td class="note"><?= e((string) $a['group']) ?></td><td><b><?= e((string) $a['label']) ?></b><?= $a['customised'] ? ' <span class="badge badge-gray">your version</span>' : '' ?></td>
      <td><?= e($aeWhen($a)) ?></td><td class="note"><?= e($aeWho($a)) ?></td>
      <td><span class="badge <?= !empty($a['enabled']) ? 'badge-green' : 'badge-gray' ?>"><?= !empty($a['enabled']) ? 'On' : 'Off' ?></span></td>
      <td><a class="btn btn-outline btn-sm" href="#ae-<?= e($k) ?>" onclick="document.getElementById('ae-<?= e($k) ?>').open=true"><i class="fas fa-pen"></i> Edit</a></td></tr>
<?php endforeach; ?>
    </tbody></table></div>
</div>

<?php foreach ($aeList as $k => $a): ?>
<details class="card ae-card" id="ae-<?= e($k) ?>">
  <summary><div><h3 style="font-size:15px;font-weight:700;margin:0"><?= e((string) $a['label']) ?> <span class="badge <?= !empty($a['enabled']) ? 'badge-green' : 'badge-gray' ?>"><?= !empty($a['enabled']) ? 'On' : 'Off' ?></span></h3>
    <p class="note" style="margin:2px 0 0"><?= e((string) $a['trigger_note']) ?></p></div><i class="fas fa-chevron-down note"></i></summary>
  <form method="post" style="margin-top:14px" data-ae-form>
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="alert-emails"><input type="hidden" name="alert" value="<?= e($k) ?>">
    <div class="ae-grid">
      <div><label class="note" style="display:flex;gap:8px;align-items:center;font-weight:600"><input type="checkbox" name="enabled" value="1"<?= !empty($a['enabled']) ? ' checked' : '' ?> style="width:auto"> Send this alert automatically</label></div>
<?php if ($a['trigger'] === 'days_before'): ?>
      <div><label>Days before (comma-separated)</label><input name="days" value="<?= e(implode(', ', (array) $a['days'])) ?>" placeholder="15, 7, 1"></div>
<?php endif; ?>
<?php if (isset($a['when'])): ?>
      <div><label>When to send</label><select name="when"><?php foreach ((array) $aeDecl['when_options'] as $wk => $wl): ?><option value="<?= e($wk) ?>"<?= $a['when'] === $wk ? ' selected' : '' ?>><?= e($wl) ?></option><?php endforeach; ?></select></div>
<?php endif; ?>
<?php if (isset($a['lookback_days'])): ?>
      <div><label>Catch up records up to (days old)</label><input name="lookback_days" type="number" min="0" max="90" value="<?= (int) $a['lookback_days'] ?>"></div>
<?php endif; ?>
    </div>
<?php if (isset($a['sources'])): ?>
    <div style="margin-top:12px"><label>Certificates checked</label>
<?php foreach ((array) $aeDecl['certificate_sources'] as $sk => $src): ?>
      <label class="note" style="display:flex;gap:8px;align-items:center;margin:4px 0"><input type="checkbox" name="sources[]" value="<?= e($sk) ?>"<?= in_array($sk, (array) $a['sources'], true) ? ' checked' : '' ?> style="width:auto">
        <?= e((string) $src['label']) ?><?= isset($src['columns']) ? ' — ' . e(implode(', ', (array) $src['columns'])) : '' ?></label>
<?php endforeach; ?>
    </div>
<?php endif; ?>
    <div style="margin-top:12px"><label>Who gets it</label>
      <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center">
        <label class="note" style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="to_employee" value="1"<?= !empty($a['recipients']['employee']) ? ' checked' : '' ?> style="width:auto"> The employee</label>
        <label class="note" style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="to_manager" value="1"<?= !empty($a['recipients']['manager']) ? ' checked' : '' ?> style="width:auto"> Their reporting manager</label>
<?php foreach ($aeRoles as $role): ?>
        <label class="note" style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="roles[]" value="<?= e($role) ?>"<?= in_array($role, (array) $a['recipients']['roles'], true) ? ' checked' : '' ?> style="width:auto"> <?= e(ucfirst($role)) ?> (role)</label>
<?php endforeach; ?>
      </div>
      <div style="max-width:520px;margin-top:8px"><label>Also copy (CC) — emails, comma-separated</label><input name="cc" value="<?= e((string) $a['recipients']['cc']) ?>" placeholder="hr@company.com"></div>
    </div>
    <div style="margin-top:12px"><label>Subject</label><input name="subject" value="<?= e((string) $a['subject']) ?>" data-ae-field></div>
    <div style="margin-top:10px"><label>Email text</label><textarea name="body" rows="10" data-ae-field style="font-family:inherit"><?= e((string) $a['body']) ?></textarea></div>
    <div style="margin-top:8px"><span class="note" style="margin-right:6px">Insert a field:</span>
<?php foreach ((array) $aeDecl['placeholders'] as $pk => $pl): ?><span class="ae-chip" data-ae-insert="{<?= e($pk) ?>}" title="<?= e($pl) ?>"><?= e(preg_replace('/ \(.*\)$/', '', $pl)) ?></span><?php endforeach; ?>
    </div>
    <div style="margin-top:10px"><label>Preview (sample employee)</label><div class="ae-preview" data-ae-preview></div></div>
<?php if ($aeEdit): ?>
    <div style="display:flex;gap:8px;margin-top:12px;justify-content:flex-end">
      <button class="go" type="submit" name="step" value="save"><i class="fas fa-check"></i> Save</button>
<?php if ($a['customised']): ?><button class="btn btn-outline" type="submit" name="step" value="reset" formnovalidate onclick="return confirm('Go back to the shipped draft and settings for this alert?')"><i class="fas fa-rotate-left"></i> Back to default</button><?php endif; ?>
    </div>
<?php endif; ?>
  </form>
</details>
<?php endforeach; ?>

<div class="card" style="padding:0">
  <div class="card-header" style="padding:16px 18px 0"><div><h3 style="font-size:15px;font-weight:700">Recent emails</h3><p class="note" style="margin:0">Each one sent or tried; a failed email is tried again on the next run.</p></div></div>
  <div class="table-wrap"><table>
    <thead><tr><th>When</th><th>Alert</th><th>Employee</th><th>To</th><th>Subject</th><th>Status</th></tr></thead>
    <tbody>
<?php foreach ($aeLog as $l): $m = json_decode((string) ($l['meta'] ?? ''), true) ?: []; $ok = (string) ($l['email_status'] ?? '') === 'sent'; ?>
    <tr><td class="note"><?= e(substr((string) ($l['created_at'] ?? ''), 0, 16)) ?></td><td><?= e((string) ($aeList[substr((string) $l['kind'], 6)]['label'] ?? $l['kind'])) ?></td>
      <td><?= e((string) ($m['recipient'] ?? '')) ?></td><td class="note"><?= e(implode(', ', (array) ($m['to'] ?? []))) ?><?= ($m['cc'] ?? []) !== [] ? '<br>cc ' . e(implode(', ', (array) $m['cc'])) : '' ?></td>
      <td><?= e((string) ($l['title'] ?? '')) ?></td>
      <td><span class="badge <?= $ok ? 'badge-green' : 'badge-red' ?>"><?= $ok ? 'Sent' : 'Failed' ?></span><?= (string) ($m['result'] ?? '') === '' ? '' : '<div class="note" style="max-width:320px;font-size:11px">' . e((string) $m['result']) . '</div>' ?></td></tr>
<?php endforeach; ?>
<?php if ($aeLog === []): ?><tr><td colspan="6" class="note">No alert email has been sent yet.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>

<script>
(function () {
  var SAMPLE = <?= json_encode($aeSample, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var last = null;
  document.querySelectorAll('[data-ae-form]').forEach(function (f) {
    var subj = f.querySelector('[name=subject]'), body = f.querySelector('[name=body]'), out = f.querySelector('[data-ae-preview]');
    function fill(t) { return t.replace(/\{([a-z_]+)\}/g, function (m, k) { return Object.prototype.hasOwnProperty.call(SAMPLE, k) ? SAMPLE[k] : m; }); }
    function paint() { out.textContent = 'Subject: ' + fill(subj.value) + '\n\n' + fill(body.value); }
    [subj, body].forEach(function (el) { el.addEventListener('focus', function () { last = el; }); el.addEventListener('input', paint); });
    f.querySelectorAll('[data-ae-insert]').forEach(function (chip) {
      chip.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
      chip.addEventListener('click', function () {
        var el = (last && f.contains(last)) ? last : body, v = chip.getAttribute('data-ae-insert');
        var s = el.selectionStart || el.value.length, en = el.selectionEnd || s;
        el.value = el.value.slice(0, s) + v + el.value.slice(en); el.focus(); el.selectionStart = el.selectionEnd = s + v.length; paint();
      });
    });
    paint();
  });
  if (location.hash.indexOf('#ae-') === 0) { var d = document.getElementById(location.hash.slice(1)); if (d) { d.open = true; } }
})();
</script>
