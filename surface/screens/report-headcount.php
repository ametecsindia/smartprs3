<?php declare(strict_types=1);
// HEADCOUNT, THE OWNER'S WAY (9 Sep): company and overall headcount on top; pick a
// company, then narrow by branch / department / designation / team; then the
// branches with their counts; then, per branch, the departments and the
// designations with their counts. Every figure is a count of live employees
// in the viewer's scope, resolved through Organization Setup's registers.
if (!$may('employee.list')): ?>
<div class="page-header"><div><h2>Headcount</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.list')->summary) ?></div></div>
<?php return; endif;

$hcPeople = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
$hcPeople = array_values(array_filter($hcPeople, static fn (array $e): bool => trim((string) ($e['archived_at'] ?? '')) === ''
    && in_array(strtolower(trim((string) ($e['status'] ?? 'active'))), ['active', ''], true)));
$hcOf = static fn (array $e, string $k): string => orgValueOf($store, $e, $k) ?: '(not set)';
$hcCount = static function (array $people, string $k) use ($hcOf): array {
    $counts = [];
    foreach ($people as $e) { $v = $hcOf($e, $k); $counts[$v] = ($counts[$v] ?? 0) + 1; }
    uksort($counts, static fn (string $a, string $b): int => ($a === '(not set)') <=> ($b === '(not set)') ?: strcasecmp($a, $b));
    return $counts;
};
$hcFilter = orgFilter();
$hcCompany = $hcFilter['company'];
$hcByCompany = $hcCount($hcPeople, 'company');
$hcOverall = count($hcPeople);
// Within the picked company, the other filters apply.
$hcInCompany = $hcCompany === '' ? $hcPeople : array_values(array_filter($hcPeople, static fn (array $e): bool => strcasecmp($hcOf($e, 'company'), $hcCompany) === 0));
$hcOptions = orgFilterOptions($store, $hcInCompany);
$hcNarrow = orgFilterApply($store, $hcInCompany, ['branch' => $hcFilter['branch'], 'department' => $hcFilter['department'], 'designation' => $hcFilter['designation'], 'team' => $hcFilter['team'], 'employee' => '']);
$hcByBranch = $hcCount($hcNarrow, 'branch');
$hcQuery = '&amp;f_company=' . e(rawurlencode($hcCompany));
$hcChip = static fn (string $label, int $n): string => '<span style="display:inline-block;margin:4px 8px 4px 0;padding:6px 12px;border:1px solid #cbd5e1;border-radius:999px;background:#fff"><b>' . e($label) . '</b>: <span style="font-family:var(--mono);font-weight:700;color:#c2410c">' . $n . '</span></span>';
?>
<div class="page-header">
  <div><h2>Headcount</h2><p>Live employees by company, branch, department, designation and team &mdash; from Organization Setup's registers</p></div>
  <div class="actions"><a class="btn btn-outline" href="?r=reports"><i class="fas fa-chart-column"></i> Reports</a>
    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="field-report-csv"><input type="hidden" name="report" value="headcount"><input type="hidden" name="g" value="branch"><input type="hidden" name="f_company" value="<?= e($hcCompany) ?>"><button class="btn btn-outline" type="submit"><i class="fas fa-file-csv"></i> CSV</button></form></div>
</div>

<div class="card">
  <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center">
    <div style="padding:10px 16px;border-radius:10px;background:#0f1d33;color:#fff"><div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;opacity:.8">Overall headcount</div><div style="font-size:26px;font-weight:800;font-family:var(--mono)"><?= $hcOverall ?></div></div>
<?php foreach ($hcByCompany as $hcCn => $hcN): ?>
    <a href="?r=report-headcount&amp;f_company=<?= e(rawurlencode((string) $hcCn)) ?>" style="text-decoration:none;color:inherit;padding:10px 16px;border-radius:10px;border:2px solid <?= strcasecmp((string) $hcCn, $hcCompany) === 0 ? '#f97316' : '#e2e8f0' ?>;background:<?= strcasecmp((string) $hcCn, $hcCompany) === 0 ? '#fff7ed' : '#fff' ?>">
      <div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#64748b">Company</div><div style="font-weight:800"><?= e((string) $hcCn) ?></div><div style="font-size:22px;font-weight:800;font-family:var(--mono);color:#1e3a5f"><?= (int) $hcN ?></div></a>
<?php endforeach; ?>
  </div>
</div>

<div class="card">
  <form method="get" class="row" style="align-items:flex-end;margin:0"><input type="hidden" name="r" value="report-headcount">
    <div><label for="hc_company">Company</label><select id="hc_company" name="f_company" onchange="this.form.submit()"><option value="">ALL</option>
<?php foreach (array_keys($hcByCompany) as $hcCn): ?><option <?= strcasecmp((string) $hcCn, $hcCompany) === 0 ? 'selected' : '' ?>><?= e((string) $hcCn) ?></option><?php endforeach; ?></select></div>
<?php foreach (['branch' => 'Branch', 'department' => 'Department', 'designation' => 'Designation', 'team' => 'Team'] as $hk => $hl): ?>
    <div><label for="hc_<?= $hk ?>"><?= $hl ?></label><select id="hc_<?= $hk ?>" name="f_<?= $hk ?>" <?= $hcCompany === '' ? 'disabled title="Pick a company first"' : '' ?>><option value="">ALL</option>
<?php foreach ($hcOptions[$hk] ?? [] as $ho): ?><option <?= strcasecmp((string) $ho, $hcFilter[$hk]) === 0 ? 'selected' : '' ?>><?= e((string) $ho) ?></option><?php endforeach; ?></select></div>
<?php endforeach; ?>
    <div><button class="go" type="submit">Show</button></div>
<?php if ($hcCompany === ''): ?><div class="note">Pick a company to narrow by branch, department, designation or team.</div><?php endif; ?>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0"><?= $hcCompany === '' ? 'All companies' : e($hcCompany) ?> <span class="note"><?= count($hcNarrow) ?> employee(s)<?= implode('', array_map(static fn (string $k): string => $hcFilter[$k] !== '' ? ' · ' . ucfirst($k) . ': ' . e($hcFilter[$k]) : '', ['branch', 'department', 'designation', 'team'])) ?></span></h2>
  <div><?php foreach ($hcByBranch as $hcBn => $hcN): ?><?= $hcChip('Branch ' . (string) $hcBn, (int) $hcN) ?><?php endforeach; ?><?php if ($hcByBranch === []): ?><span class="note">nobody in this view</span><?php endif; ?></div>
</div>

<?php foreach ($hcByBranch as $hcBn => $hcN): $hcInBranch = array_values(array_filter($hcNarrow, static fn (array $e): bool => $hcOf($e, 'branch') === (string) $hcBn)); ?>
<div class="card">
  <h3 style="margin-top:0;color:#1e3a5f"><i class="fas fa-building"></i> <?= e((string) $hcBn) ?> <span class="badge badge-green"><?= (int) $hcN ?></span></h3>
  <div style="margin:8px 0 4px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#64748b">Departments</div>
  <div><?php foreach ($hcCount($hcInBranch, 'department') as $hcDn => $hcDc): ?><?= $hcChip((string) $hcDn, (int) $hcDc) ?><?php endforeach; ?></div>
  <div style="margin:10px 0 4px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#64748b">Designations</div>
  <div><?php foreach ($hcCount($hcInBranch, 'designation') as $hcGn => $hcGc): ?><?= $hcChip((string) $hcGn, (int) $hcGc) ?><?php endforeach; ?></div>
<?php $hcTeams = $hcCount($hcInBranch, 'team'); if (count($hcTeams) > 1 || !isset($hcTeams['(not set)'])): ?>
  <div style="margin:10px 0 4px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#64748b">Teams</div>
  <div><?php foreach ($hcTeams as $hcTn => $hcTc): ?><?= $hcChip((string) $hcTn, (int) $hcTc) ?><?php endforeach; ?></div>
<?php endif; ?>
</div>
<?php endforeach; ?>
