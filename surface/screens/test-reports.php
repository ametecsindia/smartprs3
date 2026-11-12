<?php declare(strict_types=1); if (!$may('employee.view')): ?>
<div class="page-header"><div><h2>Test Reports</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Test Reports: attempts and pass rate per test, plus (new) per
// department. The rates are Surface\Assessments::reports() — exact fractions
// with the working; the employees are the ones in view.
use SmartPRS\Surface\Assessments;

$people = $scope->only($scope->only(liveRows($store, 'employees'), $may('employee.list')), $may('employee.list'));
$filter = orgFilter();
$deptOf = [];

foreach (orgFilterApply($store, $people, $filter) as $e) {
    $deptOf[(string) $e['id']] = orgValueOf($store, $e, 'department');
}

$attempts = array_values(array_filter($store->all('test_attempts'), static fn (array $a): bool => isset($deptOf[(string) ($a['employee_id'] ?? '')])));
$rep = Assessments::reports(liveRows($store, 'tests'), $attempts, $deptOf);
$pct = static fn (?SmartPRS\Platform\Support\Rational $r): string => $r === null ? '—' : $r->multipliedBy(SmartPRS\Platform\Support\Rational::of(100))->toDecimalString(1) . '%';
?>
<div class="page-header">
  <div><h2>Test Reports</h2><p>Attempts and pass rate per test and per department, for the employees in view. Pass rate = passed ÷ finished attempts; attempts still in progress are not counted.</p></div>
  <div><a class="btn btn-outline" href="?r=m&k=test-attempts"><i class="fas fa-square-poll-vertical"></i> Test Results</a></div>
</div>
<?= orgFilterBar('test-reports', $filter, orgFilterOptions($store, $people)) ?>
<div class="card">
  <h2>Per test</h2>
<?php if ($rep['by_test'] === []): ?>
  <p class="note">No tests yet.</p>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Test</th><th class="n">Attempts</th><th class="n">Passed</th><th class="n">Failed</th><th class="n">Pass rate</th><th>Working</th></tr></thead>
    <tbody><?php foreach ($rep['by_test'] as $r): ?><tr><td><b><?= e($r['name']) ?></b></td><td class="n"><?= $r['attempts'] ?></td><td class="n"><?= $r['passed'] ?></td><td class="n"><?= $r['failed'] ?></td><td class="n"><b><?= e($pct($r['rate'])) ?></b></td><td class="note"><?= e($r['working']) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
<?php endif; ?>
</div>
<div class="card">
  <h2>Per department</h2>
<?php if ($rep['by_department'] === []): ?>
  <p class="note">No finished attempt yet for the employees in view.</p>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Department</th><th class="n">Attempts</th><th class="n">Passed</th><th class="n">Failed</th><th class="n">Pass rate</th><th>Working</th></tr></thead>
    <tbody><?php foreach ($rep['by_department'] as $r): ?><tr><td><b><?= e($r['department']) ?></b></td><td class="n"><?= $r['attempts'] ?></td><td class="n"><?= $r['passed'] ?></td><td class="n"><?= $r['failed'] ?></td><td class="n"><b><?= e($pct($r['rate'])) ?></b></td><td class="note"><?= e($r['working']) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
<?php endif; ?>
</div>
