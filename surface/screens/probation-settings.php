<?php declare(strict_types=1); if (!$may('tenant.settings.view')): ?>
<div class="page-header"><div><h2>Probation Settings</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Probation Settings: the default months and the reminder
// milestones. The values are config/probation.json's defaults with this
// tenant's versioned customisation over them (Surface\Probation::config());
// saving posts the existing `probation-config` action, which appends a version
// through Customisation::saveRaw — the same form the Probation board carries.
$declared = $probation->declared();
$probConfig = $probation->config();
$history = $customisation->history((string) $declared['customisation_key']);
$shipped = (array) $declared['defaults'];
?>
<div class="page-header">
  <div><h2>Probation Settings</h2><p>How long probation runs unless a record says otherwise, and when HR is nudged before it ends.</p></div>
  <div><a class="btn btn-outline" href="?r=probation"><i class="fas fa-hourglass-half"></i> Probation board</a></div>
</div>

<div class="card">
<?php if ($may('tenant.settings.update')): ?>
  <form method="post" class="row" style="align-items:flex-end;gap:12px;flex-wrap:wrap"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="probation-config">
    <div><label>Default months</label><input name="default_months" type="number" min="1" max="36" value="<?= (int) $probConfig['default_months'] ?>" style="width:90px"></div>
    <div><label>Reminder milestones (days before end)</label><input name="reminder_days" value="<?= e(implode(', ', $probConfig['reminder_days'])) ?>" style="width:220px"></div>
    <label class="note"><input type="checkbox" name="notify_hr" value="1"<?= ($probConfig['notify_hr'] ?? true) ? ' checked' : '' ?>> Tell HR</label>
    <label class="note"><input type="checkbox" name="notify_manager" value="1"<?= $probConfig['notify_manager'] ? ' checked' : '' ?>> Tell the reporting manager</label>
    <label class="note"><input type="checkbox" name="congratulate_employee" value="1"<?= ($probConfig['congratulate_employee'] ?? false) ? ' checked' : '' ?>> Congratulate the employee on confirmation</label>
    <button class="go" type="submit">Save as version <?= (int) ($history[0]['version'] ?? 0) + 1 ?></button>
  </form>
<?php else: ?>
  <p class="note" style="margin:0"><?= (int) $probConfig['default_months'] ?> months &middot; milestones <?= e(implode(', ', $probConfig['reminder_days'])) ?> days &middot; HR <?= ($probConfig['notify_hr'] ?? true) ? 'told' : 'not told' ?> &middot; manager <?= $probConfig['notify_manager'] ? 'told' : 'not told' ?> &middot; congratulations <?= ($probConfig['congratulate_employee'] ?? false) ? 'on' : 'off' ?></p>
<?php endif; ?>
  <p class="note" style="margin:10px 0 0">Shipped defaults: <?= (int) $shipped['default_months'] ?> months, milestones <?= e(implode(', ', (array) $shipped['reminder_days'])) ?> days.
    End date = date of joining + default months, unless the employee carries its own <code>probation_end</code>. A milestone of 0 is the day itself.</p>
</div>

<div class="card" style="padding:0">
  <div class="table-wrap"><table>
    <thead><tr><th>Version</th><th>Months</th><th>Milestones</th><th>HR</th><th>Manager</th><th>Congratulate</th><th>By</th><th>When</th></tr></thead>
    <tbody>
<?php if ($history === []): ?><tr><td colspan="8" class="note">Never customised &mdash; the shipped defaults apply.</td></tr><?php endif; ?>
<?php foreach ($history as $h): $p = json_decode((string) ($h['payload'] ?? ''), true); $p = is_array($p) ? $p : []; ?>
      <tr><td><span class="badge badge-<?= $h === $history[0] ? 'green' : 'gray' ?>">v<?= (int) $h['version'] ?></span></td>
        <td><?= (int) ($p['default_months'] ?? 0) ?></td><td><?= e(implode(', ', (array) ($p['reminder_days'] ?? []))) ?></td>
        <td><span class="badge badge-<?= ($p['notify_hr'] ?? true) ? 'green' : 'red' ?>"><?= ($p['notify_hr'] ?? true) ? 'Yes' : 'No' ?></span></td><td><span class="badge badge-<?= !empty($p['notify_manager']) ? 'green' : 'red' ?>"><?= !empty($p['notify_manager']) ? 'Yes' : 'No' ?></span></td><td><span class="badge badge-<?= !empty($p['congratulate_employee']) ? 'green' : 'red' ?>"><?= !empty($p['congratulate_employee']) ? 'Yes' : 'No' ?></span></td><td><?= e((string) ($h['changed_by'] ?? '')) ?></td><td class="note"><?= e(substr((string) ($h['created_at'] ?? ''), 0, 16)) ?></td></tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
</div>
