<?php declare(strict_types=1); if (!$may('employee.view')): ?>
<div class="page-header"><div><h2>Greetings</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Greetings + Greetings Log: birthday and work-anniversary wishes,
// sent by a daily job. No scheduler or transport here, so: who is due today and
// this week (from DOB and DOJ), a preview of the wording, a button that queues
// the greeting to the Messages Log, and the log of what was queued.
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Greetings;
use SmartPRS\Surface\Messaging;

$messaging = new Messaging($store, __DIR__ . '/../../config/communication.json');
$greetings = new Greetings($messaging, $letters);
$cfg = $greetings->config();
$today = date('Y-m-d');
$people = $scope->only($scope->only(liveRows($store, 'employees'), $may('employee.list')), $may('employee.view'));
$due = $greetings->due($people, $today);
$log = $messaging->log('greeting');
$mayQueue = $may('employee.update');
?>
<div class="page-header">
  <div><h2>Greetings</h2><p>Birthdays from DOB, work anniversaries from DOJ (from <?= (int) ($cfg['anniversary']['min_years'] ?? 1) ?> year). Wording is <code>config/communication.json</code>, rendered by the letter engine. <b>No transport is configured</b>: Queue puts the greeting in the <a href="?r=m&amp;k=messages-log">Messages Log</a> as <code><?= e((string) $messaging->config()['queued_status']) ?></code>.</p></div>
</div>

<div class="card">
  <h2>Due today and in the next <?= (int) ($cfg['week_days_ahead'] ?? 7) ?> days <span class="note"><?= count($due) ?> · <?= count($people) ?> employees checked</span></h2>
<?php if ($due === []): ?>
  <p class="note">Nobody is due. A person with no DOB or DOJ on the employee cannot be due — fill them in on the employee record.</p>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>When</th><th>Occasion</th><th>Code</th><th>Employee</th><th>Channel</th><th>Greeting</th><th></th></tr></thead>
    <tbody>
<?php foreach ($due as $d): $e = $d['employee']; $r = $greetings->render($d, $company); $done = $greetings->alreadyQueued((string) $e['id'], $d['kind'], $d['on']); ?>
      <tr>
        <td><?= $d['in_days'] === 0 ? '<span class="badge badge-green">today</span>' : e($d['on']) . ' <span class="note">in ' . $d['in_days'] . 'd</span>' ?></td>
        <td><?= $d['kind'] === 'birthday' ? '<i class="fas fa-cake-candles"></i> Birthday' : '<i class="fas fa-award"></i> ' . $d['years'] . ' year' . ($d['years'] === 1 ? '' : 's') ?></td>
        <td><b><?= e(Employment::code($e)) ?></b></td>
        <td><?= e((string) ($e['name'] ?? '')) ?></td>
        <td><?= e($r['channel']) ?> <span class="note"><?= e($messaging->addressOf($e, $r['channel']) ?: 'no address') ?></span></td>
        <td class="note" style="white-space:normal;max-width:360px"><?php if ($r['ok']): ?><b><?= e($r['subject']) ?></b><br><?= nl2br(e($r['body'])) ?><?php else: ?><span class="problem" style="display:inline;padding:2px 6px">Not rendered: <?= e($r['why']) ?></span><?php endif; ?></td>
        <td>
<?php if ($done): ?>
          <span class="badge badge-gray">queued</span>
<?php elseif ($mayQueue && $r['ok'] && $messaging->addressOf($e, $r['channel']) !== ''): ?>
          <form method="post" style="margin:0">
            <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
            <input type="hidden" name="action" value="greeting-queue">
            <input type="hidden" name="e" value="<?= e((string) $e['id']) ?>">
            <input type="hidden" name="kind" value="<?= e($d['kind']) ?>">
            <input type="hidden" name="on" value="<?= e($d['on']) ?>">
            <button class="btn btn-sm btn-primary" type="submit">Queue</button>
          </form>
<?php else: ?>
          <span class="note"><?= $mayQueue ? 'cannot queue' : 'needs employee.update' ?></span>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>

<div class="card">
  <h2>Greetings log <span class="note"><?= count($log) ?></span></h2>
<?php if ($log === []): ?>
  <p class="note">Nothing queued yet.</p>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>When</th><th>Occasion</th><th>Employee</th><th>Channel</th><th>To</th><th>Subject</th><th>Status</th><th>By</th></tr></thead>
    <tbody>
<?php foreach (array_slice($log, 0, 200) as $row): ?>
      <tr>
        <td class="note"><?= e((string) ($row['sent_at'] ?? '')) ?></td>
        <td><?= e((string) ($row['target'] ?? '')) ?></td>
        <td><?= e((string) ($row['employee_id'] ?? '')) ?></td>
        <td><?= e((string) ($row['channel'] ?? '')) ?></td>
        <td class="note"><?= e((string) ($row['to_address'] ?? '')) ?></td>
        <td><?= e((string) ($row['subject'] ?? '')) ?></td>
        <td><span class="badge badge-<?= (string) ($row['status'] ?? '') === 'sent' ? 'green' : 'amber' ?>"><?= e((string) ($row['status'] ?? '')) ?></span></td>
        <td class="note"><?= e((string) ($row['sent_by_label'] ?? '')) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>
