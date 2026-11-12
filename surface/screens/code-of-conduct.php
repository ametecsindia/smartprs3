<?php declare(strict_types=1); if (!$may('employee.view')): ?>
<div class="page-header"><div><h2>Code of Conduct</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2: the code's text with an "I acknowledge" button for the employee,
// and the acknowledgement register for HR. The versions are
// config/code-of-conduct.json; an acknowledgement records the version, who
// and when, and is never edited — a new version needs a new acknowledgement.
use SmartPRS\Surface\Employment;

$cfg = (array) json_decode((string) file_get_contents(__DIR__ . '/../../config/code-of-conduct.json'), true);
$today = date('Y-m-d');
$versions = array_values(array_filter((array) ($cfg['versions'] ?? []), static fn (array $v): bool => (string) ($v['effective_from'] ?? '') <= $today));
usort($versions, static fn (array $a, array $b): int => strcmp((string) $b['effective_from'], (string) $a['effective_from']));
$current = $versions[0] ?? null;
$own = $scope->own();
$mayOthers = $may('employee.list');
$people = $scope->only($scope->only(liveRows($store, 'employees'), $may('employee.list')), $mayOthers);
$byId = [];

foreach ($people as $e) {
    $byId[(string) $e['id']] = $e;
}

$acks = [];

foreach ($store->all('code_of_conduct_ack') as $a) {
    if ((string) ($a['acknowledged'] ?? '') === '1' && isset($byId[(string) ($a['employee_id'] ?? '')])) {
        $acks[(string) $a['employee_id']][(string) ($a['version'] ?? '')] = $a;
    }
}

$mineCurrent = $own === null || $current === null ? null : ($acks[(string) $own][(string) $current['version']] ?? null);
$filter = orgFilter();
$inView = orgFilterApply($store, $people, $filter);
$done = 0;

foreach ($inView as $e) {
    $done += $current !== null && isset($acks[(string) $e['id']][(string) $current['version']]) ? 1 : 0;
}
?>
<div class="page-header">
  <div><h2>Code of Conduct</h2><p>Current version <b><?= e((string) ($current['version'] ?? '—')) ?></b><?= $current === null ? '' : ' effective ' . e((string) $current['effective_from']) ?>. Every employee acknowledges the current version once; a new version needs a fresh acknowledgement. Text and versions: <code>config/code-of-conduct.json</code>.</p></div>
</div>
<?php if ($current !== null): ?>
<div class="card">
  <h2><?= e((string) ($current['title'] ?? 'Code of Conduct')) ?> <span class="badge badge-gray">v<?= e((string) $current['version']) ?></span></h2>
<?php foreach ((array) ($current['sections'] ?? []) as $s): ?>
  <h3 style="margin:12px 0 4px"><?= e((string) ($s['heading'] ?? '')) ?></h3>
  <p><?= e((string) ($s['text'] ?? '')) ?></p>
<?php endforeach; ?>
<?php if ($own === null): ?>
  <p class="note">Your account is not linked to an employee, so there is nobody to record an acknowledgement for.</p>
<?php elseif ($mineCurrent !== null): ?>
  <p><span class="badge badge-green">Acknowledged</span> <span class="note">v<?= e((string) $mineCurrent['version']) ?> on <?= e((string) ($mineCurrent['acknowledged_at'] ?? $mineCurrent['ack_date'] ?? '')) ?> by <?= e((string) ($mineCurrent['acknowledged_by'] ?? '')) ?></span></p>
<?php else: ?>
  <form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="coc-acknowledge"><input type="hidden" name="version" value="<?= e((string) $current['version']) ?>">
    <label style="display:block;margin:10px 0"><input type="checkbox" name="agree" value="1" required> <?= e((string) ($cfg['statement'] ?? 'I acknowledge the Code of Conduct.')) ?></label>
    <button class="btn btn-primary" type="submit"><i class="fas fa-check"></i> I acknowledge v<?= e((string) $current['version']) ?></button>
  </form>
<?php endif; ?>
</div>
<?php else: ?>
<div class="card"><div class="problem">No Code of Conduct version is effective yet — add one to <code>config/code-of-conduct.json</code>.</div></div>
<?php endif; ?>
<?php if ($mayOthers): ?>
<?= orgFilterBar('code-of-conduct', $filter, orgFilterOptions($store, $people)) ?>
<div class="card">
  <h2>Acknowledgements <span class="badge badge-green"><?= $done ?></span> <span class="badge badge-amber"><?= count($inView) - $done ?> pending</span></h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Code</th><th>Employee</th><th>Department</th><th>Current version</th><th>When</th><th>Earlier versions</th></tr></thead>
    <tbody>
<?php foreach ($inView as $e): $id = (string) $e['id']; $cur = $current === null ? null : ($acks[$id][(string) $current['version']] ?? null);
      $earlier = array_diff(array_keys($acks[$id] ?? []), [(string) ($current['version'] ?? '')]); ?>
      <tr>
        <td><b><?= e(Employment::code($e)) ?></b></td>
        <td><?= e((string) ($e['name'] ?? '')) ?></td>
        <td class="note"><?= e(orgValueOf($store, $e, 'department')) ?></td>
        <td><?= $cur === null ? '<span class="badge badge-amber">pending</span>' : '<span class="badge badge-green">acknowledged</span>' ?></td>
        <td class="note"><?= $cur === null ? '—' : e((string) ($cur['acknowledged_at'] ?? $cur['ack_date'] ?? '')) . ' · ' . e((string) ($cur['acknowledged_by'] ?? '')) ?></td>
        <td class="note"><?= $earlier === [] ? '—' : e('v' . implode(', v', $earlier)) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="note">Version history: <?php foreach ($versions as $v): ?><b>v<?= e((string) $v['version']) ?></b> from <?= e((string) $v['effective_from']) ?>; <?php endforeach; ?> the register keeps every version's acknowledgement.</p>
</div>
<?php endif; ?>
