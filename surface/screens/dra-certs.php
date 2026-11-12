<?php declare(strict_types=1); if (!$may('employee.view')): ?>
<div class="page-header"><div><h2>DRA Certificates</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's DRA Certifications register — here read from the employee's
// own dra_status / dra_expiry (Employees → Employee), banded against today
// with the window from config/field-reports.json, expiring-first. Each row
// links to the employee's edit form, because that is where the certificate
// is recorded; nothing is typed twice.
$params = $_GET;
include __DIR__ . '/../partials/dra-certs-data.php';
$badge = ['expired' => 'badge-red', 'due' => 'badge-amber', 'valid' => 'badge-green', 'none' => 'badge-gray'];
$anchor = (string) $cfg['employee_edit_anchor'];
?>
<div class="page-header">
  <div><h2>DRA Certificates</h2><p>IIBF Debt Recovery Agent certificate per employee: expiring within <b><?= $window ?> days</b> first, then expired, then valid. Recorded on the employee itself; the window is <code>expiry_window_days</code> in <code>config/field-reports.json</code>.</p></div>
  <form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="field-report-csv"><input type="hidden" name="report" value="dra-certs"><?php foreach ($filter as $k => $v): ?><input type="hidden" name="f_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?><button class="btn btn-outline btn-sm" type="submit"><i class="fas fa-file-csv"></i> CSV</button></form>
</div>
<?= orgFilterBar('dra-certs', $filter, orgFilterOptions($store, $people)) ?>
<div class="card">
  <p class="note" style="margin-top:0">
    <span class="badge badge-amber">due within <?= $window ?> days: <?= (int) $counts['due'] ?></span>
    <span class="badge badge-red">expired: <?= (int) $counts['expired'] ?></span>
    <span class="badge badge-green">valid: <?= (int) $counts['valid'] ?></span>
    <span class="badge badge-gray">no expiry recorded: <?= (int) $counts['none'] ?></span>
    &mdash; <?= count($rows) ?> of <?= count($people) ?> live employee(s) in view, as of <?= e($today) ?>.</p>
<?php if ($rows === []): ?>
  <p class="note">No employees match the filter.</p>
<?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>Code</th><th>Name</th><th>Company</th><th>Branch</th><th>DRA status</th><th>Expiry</th><th class="n">Days left</th><th>Band</th><th></th></tr>
<?php foreach ($rows as $row): ?>
    <tr>
      <td><?= e($row['code']) ?></td><td><?= e($row['name']) ?></td><td><?= e($row['company']) ?></td><td><?= e($row['branch']) ?></td>
      <td><?= e($row['dra_status']) ?: '<span class="note">—</span>' ?></td>
      <td><?= e($row['dra_expiry']) ?: '<span class="note">not recorded</span>' ?></td>
      <td class="n"><?= $row['days_left'] === null ? '—' : (int) $row['days_left'] ?></td>
      <td><span class="badge <?= $badge[$row['band']] ?>"><?= e($row['band']) ?></span></td>
      <td><?php if ($may('employee.update')): ?><a class="btn btn-sm" href="?r=employees&amp;edit=<?= e($row['employee_id']) ?>#<?= e($anchor) ?>"><i class="fas fa-pen"></i> Record</a><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </table></div>
<?php endif; ?>
  <p class="note">A certificate that expires today is <b>expired</b> today, not valid: the bank's authorisation check asks whether the agent holds a valid certificate on the day of the visit.</p>
</div>
