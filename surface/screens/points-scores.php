<?php declare(strict_types=1); if (!$may('employee.view')): ?>
<div class="page-header"><div><h2>Leaderboard</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Scores & Leaderboard: employees ranked by the sum of their
// Points Ledger rows, with "Auto-apply rules" for a month. The sums and ranks
// are Surface\Points::leaderboard(); the auto-apply is
// surface/actions/points-auto-apply.php and never this screen.
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Points;

$points = new Points($store, __DIR__ . '/../../config/points.json');
$cfg = $points->config();
$people = $scope->only($scope->only(liveRows($store, 'employees'), $may('employee.list')), $may('employee.list'));
$filter = orgFilter();
$inView = [];

foreach (orgFilterApply($store, $people, $filter) as $e) {
    $inView[(string) $e['id']] = $e;
}

$months = SmartPRS\Surface\Attrition::months(date('Y-m'), max(1, (int) ($cfg['leaderboard']['history_months'] ?? 12)));
$period = preg_match('/^\d{4}(-\d{2})?$/', (string) ($_GET['period'] ?? '')) === 1 ? (string) $_GET['period'] : '';
$ledger = array_values(array_filter($store->all('points_ledger'), static fn (array $r): bool => isset($inView[(string) ($r['employee_id'] ?? '')])));
$board = Points::leaderboard($ledger, $period);
$canApply = $may('employee.update');
$appliedMonths = [];

foreach ($months as $m) {
    $appliedMonths[$m] = $points->applied($m);
}
?>
<div class="page-header">
  <div><h2>Leaderboard</h2><p>Employees ranked by net points from the Points Ledger. Ties share a rank. Period: <?= $period === '' ? 'all time' : e($period) ?>.</p></div>
  <div><a class="btn btn-outline" href="?r=m&k=points"><i class="fas fa-book-open"></i> Points Ledger</a> <a class="btn btn-outline" href="?r=m&k=point-rules"><i class="fas fa-list-check"></i> Points Rules</a></div>
</div>
<?php // ONE FORM (owner, 13 Oct): this screen's own choice sits in the filter bar — one Show, no second form. ?>
<?= orgFilterBar('points-scores', $filter, orgFilterOptions($store, $people), [], 'Show', null, null, null,
    '<div><label for="period">Period</label><select id="period" name="period"><option value="">ALL time</option>' . implode('', array_map(static fn ($m): string => '<option value="' . e((string) $m) . '"' . ($m === $period ? ' selected' : '') . '>' . e((string) $m) . '</option>', array_reverse($months))) . '</select></div>') ?>
<div class="card">
<?php if ($board === []): ?>
  <p class="note">No points in this period for the employees in view. Points arrive from the ledger — by hand, or by auto-applying the rules for a month.</p>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th class="n">Rank</th><th>Code</th><th>Employee</th><th>Department</th><th class="n">Positive</th><th class="n">Negative</th><th class="n">Net</th><th class="n">Entries</th></tr></thead>
    <tbody>
<?php foreach ($board as $r): $e = $inView[$r['employee_id']]; ?>
      <tr>
        <td class="n"><b><?= (int) $r['rank'] ?></b></td>
        <td><b><?= e(Employment::code($e)) ?></b></td>
        <td><?= e((string) ($e['name'] ?? '')) ?></td>
        <td class="note"><?= e(orgValueOf($store, $e, 'department')) ?></td>
        <td class="n" style="font-family:var(--mono)"><?= (int) $r['positive'] ?></td>
        <td class="n" style="font-family:var(--mono)"><?= (int) $r['negative'] ?></td>
        <td class="n" style="font-family:var(--mono)"><b><?= (int) $r['net'] ?></b></td>
        <td class="n note"><?= (int) $r['entries'] ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="note">Net = positive + negative, summed from every ledger row dated in the period. Open the ledger to see each row and its note.</p>
<?php endif; ?>
</div>
<?php if ($canApply): ?>
<div class="card">
  <h2>Auto-apply rules for a month</h2>
  <p class="note">Scans the month's attendance (late days, absent days, perfect attendance, on-time streak), awards and passed tests for every employee in view, and writes one ledger row per employee per event at the points the rule says. A month already applied is refused — delete its <code>auto:YYYY-MM</code> rows first to redo it. Which data feeds each event is <code>config/points.json</code>; the amounts are only ever the rule rows.</p>
  <form method="post" class="row" style="align-items:flex-end">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="points-auto-apply">
<?php foreach ($filter as $k => $v): ?><input type="hidden" name="f_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
    <div><label for="apply_month">Month</label><select id="apply_month" name="month"><?php foreach (array_reverse($months) as $m): ?><option value="<?= e($m) ?>"><?= e($m) ?><?= $appliedMonths[$m] ? ' (applied)' : '' ?></option><?php endforeach; ?></select></div>
    <div><button class="btn btn-primary" type="submit"><i class="fas fa-wand-magic-sparkles"></i> Auto-apply</button></div>
  </form>
  <div class="table-wrap" style="margin-top:10px"><table>
    <thead><tr><th>Event</th><th>Counted from</th><th>Applied as</th></tr></thead>
    <tbody><?php foreach ((array) ($cfg['events'] ?? []) as $event => $spec): ?><tr><td><b><?= e((string) $event) ?></b></td><td class="note"><?= e((string) ($spec['source'] ?? '')) ?></td><td class="note"><?= e((string) ($spec['applied_as'] ?? '')) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
</div>
<?php endif; ?>
