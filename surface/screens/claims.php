<?php declare(strict_types=1); if (!$may('payroll.view')): ?>
<div class="page-header"><div><h2>Claim Approvals</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'payroll.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's approval queue for Expense Claims and Salary Advances: what is
// pending, who raised it, approve or reject with a reason. The decision is
// written by surface/actions/decide-claim.php — status, who and when on the
// row, the reason in the audit trail — and never here.
$compensation = new SmartPRS\Surface\Compensation($store, __DIR__ . '/../../config/compensation.json');
$claimRules = (array) $compensation->config()['claims'];
$canDecide = $may('payroll.run');
$people = [];

foreach ($scope->only(liveRows($store, 'employees'), $may('employee.list')) as $e) {
    $people[(string) $e['id']] = $e;
}

$filter = orgFilter();
$inView = array_flip(array_map(static fn (array $e): string => (string) $e['id'], orgFilterApply($store, array_values($people), $filter)));
$labels = ['expenses' => 'Expense claim', 'advances' => 'Salary advance'];
$pending = [];
$decided = [];

foreach ($labels as $table => $label) {
    if (!is_array($claimRules[$table] ?? null)) {
        continue;
    }

    foreach ($store->all($table) as $row) {
        if (!isset($inView[(string) ($row['employee_id'] ?? '')])) {
            continue;
        }

        $status = strtolower(trim((string) ($row['status'] ?? '')));
        $row['_table'] = $table;
        $row['_label'] = $label;

        if (in_array($status, (array) $claimRules[$table]['pending'], true)) {
            $pending[] = $row;
        } elseif (trim((string) ($row['decided_at'] ?? '')) !== '') {
            $decided[] = $row;
        }
    }
}

usort($pending, static fn (array $a, array $b): int => strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? '')));
usort($decided, static fn (array $a, array $b): int => strcmp((string) $b['decided_at'], (string) $a['decided_at']));
$decided = array_slice($decided, 0, 20);
$who = static fn (array $row): string => isset($people[(string) $row['employee_id']])
    ? SmartPRS\Surface\Employment::code($people[(string) $row['employee_id']]) . ' ' . (string) ($people[(string) $row['employee_id']]['name'] ?? '')
    : '#' . (string) $row['employee_id'];
$what = static fn (array $row): string => trim(implode(' · ', array_filter([
    (string) ($row['type'] ?? ''), (string) ($row['date'] ?? ''), (string) ($row['remarks'] ?? '')])));
?>
<div class="page-header">
  <div><h2>Claim Approvals</h2><p>Expense claims and salary advances waiting for a decision. Approve or reject with a reason; the row records who and when, the trail records why.</p></div>
  <div><a class="btn btn-outline" href="?r=m&k=expenses"><i class="fas fa-receipt"></i> Expense Claims</a>
    <a class="btn btn-outline" href="?r=salary-advance"><i class="fas fa-hand-holding-dollar"></i> Salary Advance</a></div>
</div>
<?= orgFilterBar('claims', $filter, orgFilterOptions($store, array_values($people))) ?>
<div class="card">
  <h2>Pending <span class="badge badge-amber"><?= count($pending) ?></span></h2>
<?php if ($pending === []): ?>
  <p class="note">Nothing is waiting. A new expense claim or advance is pending until somebody decides it here.</p>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Employee</th><th>Kind</th><th>What</th><th class="n">Amount</th><th>Raised</th><th style="min-width:420px">Decision</th></tr></thead>
    <tbody>
<?php foreach ($pending as $row): ?>
      <tr>
        <td><?= e($who($row)) ?></td>
        <td><span class="badge badge-gray"><?= e($row['_label']) ?></span></td>
        <td class="note"><?= e($what($row)) ?></td>
        <td class="n"><b style="font-family:var(--mono)"><?= e(SmartPRS\Surface\Amount::of($row['amount'] ?? '')->format()) ?></b></td>
        <td class="note"><?= e(substr((string) ($row['created_at'] ?? ''), 0, 10)) ?></td>
        <td>
<?php if ($canDecide): ?>
          <form method="post" class="row" style="align-items:center;gap:6px;margin:0">
            <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
            <input type="hidden" name="action" value="decide-claim">
            <input type="hidden" name="table" value="<?= e($row['_table']) ?>">
            <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
            <div style="flex:1 1 200px"><input name="reason" placeholder="Reason (required to reject)"></div>
            <div style="flex:0 0 auto"><button class="btn btn-primary btn-sm" type="submit" name="decision" value="approve"><i class="fas fa-check"></i> Approve</button></div>
            <div style="flex:0 0 auto"><button class="btn btn-outline btn-sm" type="submit" name="decision" value="reject">Reject</button></div>
          </form>
<?php else: ?>
          <span class="note">Deciding needs <code>payroll.run</code>.</span>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>
<?php if ($decided !== []): ?>
<div class="card" style="margin-top:12px">
  <h2>Recently decided</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Employee</th><th>Kind</th><th>What</th><th class="n">Amount</th><th>Status</th><th>By</th><th>When</th></tr></thead>
    <tbody>
<?php foreach ($decided as $row): $st = strtolower((string) $row['status']); ?>
      <tr>
        <td><?= e($who($row)) ?></td>
        <td><span class="badge badge-gray"><?= e($row['_label']) ?></span></td>
        <td class="note"><?= e($what($row)) ?></td>
        <td class="n" style="font-family:var(--mono)"><?= e(SmartPRS\Surface\Amount::of($row['amount'] ?? '')->format()) ?></td>
        <td><span class="badge <?= $st === 'rejected' ? 'badge-red' : 'badge-green' ?>"><?= e($st) ?></span></td>
        <td class="note"><?= e((string) ($row['decided_by'] ?? '')) ?></td>
        <td class="note"><?= e(substr((string) $row['decided_at'], 0, 16)) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="note">The reason given with each decision is in the audit trail under <code>claim.decided</code>.</p>
</div>
<?php endif; ?>
