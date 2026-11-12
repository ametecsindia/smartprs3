<?php declare(strict_types=1); if (!$may('employee.view')): ?>
<div class="page-header"><div><h2>Agent Authorisations</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Agent Authorization register, read expiring-first: the bank's
// letter / ID per employee and portfolio with its validity. Rows are added and
// edited in the agent-authorizations module (Add / Edit / Delete, Customise);
// this screen is the ordered view with the window from config/field-reports.json.
$params = $_GET;
include __DIR__ . '/../partials/agent-auth-data.php';
$badge = ['expired' => 'badge-red', 'due' => 'badge-amber', 'valid' => 'badge-green', 'none' => 'badge-gray'];
?>
<div class="page-header">
  <div><h2>Agent Authorisations</h2><p>Bank authorisation letters / IDs per employee or off-roll agent, bank and portfolio. Expiring within <b><?= $window ?> days</b> first, then expired, then valid &mdash; the window is <code>expiry_window_days</code> in <code>config/field-reports.json</code>.</p></div>
  <div class="row">
<?php if ($may('employee.update')): ?>
    <a class="btn btn-primary btn-sm" href="?r=m&amp;k=agent-authorizations"><i class="fas fa-plus"></i> Add / manage</a>
<?php endif; ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="field-report-csv"><input type="hidden" name="report" value="agent-auth"><?php foreach ($filter as $k => $v): ?><input type="hidden" name="f_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?><button class="btn btn-outline btn-sm" type="submit"><i class="fas fa-file-csv"></i> CSV</button></form>
  </div>
</div>
<?= orgFilterBar('agent-auth', $filter, orgFilterOptions($store, $people)) ?>
<div class="card">
  <p class="note" style="margin-top:0">
    <span class="badge badge-amber">due within <?= $window ?> days: <?= (int) $counts['due'] ?></span>
    <span class="badge badge-red">expired: <?= (int) $counts['expired'] ?></span>
    <span class="badge badge-green">valid: <?= (int) $counts['valid'] ?></span>
    <span class="badge badge-gray">no end date: <?= (int) $counts['none'] ?></span>
    &mdash; <?= count($rows) ?> authorisation(s) for <?= count($inView) ?> employee(s) in view, as of <?= e($today) ?>.</p>
<?php if ($rows === []): ?>
  <p class="note">No authorisations for the employees in view. <?php if ($may('employee.update')): ?>Record one from <a href="?r=m&amp;k=agent-authorizations">Add / manage</a>.<?php endif; ?></p>
<?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>Code</th><th>Name</th><th>Bank</th><th>Portfolio</th><th>Letter no.</th><th>Valid from</th><th>Valid to</th><th class="n">Days left</th><th>Band</th><th>Status</th><th></th></tr>
<?php foreach ($rows as $row): ?>
    <tr>
      <td><?= e($row['code']) ?></td><td><?= e($row['name']) ?></td><td><?= e($row['bank']) ?></td><td><?= e($row['portfolio']) ?></td><td><?= e($row['auth_no']) ?></td>
      <td><?= e($row['valid_from']) ?: '—' ?></td><td><?= e($row['valid_to']) ?: '<span class="note">not set</span>' ?></td>
      <td class="n"><?= $row['days_left'] === null ? '—' : (int) $row['days_left'] ?></td>
      <td><span class="badge <?= $badge[$row['band']] ?>"><?= e($row['band']) ?></span></td>
      <td><?= e($row['status']) ?></td>
      <td><?php if ($may('employee.update')): ?><a class="btn btn-sm" href="?r=m&amp;k=agent-authorizations&amp;edit=<?= e($row['id']) ?>"><i class="fas fa-pen"></i> Edit</a><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </table></div>
<?php endif; ?>
  <p class="note">The band is computed from <b>Valid to</b> against today; the <b>Status</b> column is what somebody recorded. When the two disagree, the date wins &mdash; the status is a note, the date is the letter.</p>
</div>
