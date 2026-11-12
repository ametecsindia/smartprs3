<?php declare(strict_types=1); if (!$may('leave.balance.view_others')): ?>
<div class="page-header"><div><h2>Leave Balance Report</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'leave.balance.view_others')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// LEAVE BALANCE REPORT (owner, 10 Oct) — Admin/HR: per employee, for one
// financial year, the opening balance, leave availed and the balance left, every
// leave type stacked in the same cell. Rows are what Scope lets this person see;
// filters are the shared orgFilter* bar plus FY. Figures come from the leave
// ledger (Leave::balance) and approved requests — nothing stored.
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Leave;

$lbStartMonth = financialYearStart($customisation);
$lbNowFy = (int) date('Y') - ((int) date('n') < $lbStartMonth ? 1 : 0);
$lbFy = (int) ($_GET['fy'] ?? $lbNowFy);
$lbFy = $lbFy >= 1990 && $lbFy <= $lbNowFy ? $lbFy : $lbNowFy;
$lbFrom = new DateTimeImmutable(sprintf('%04d-%02d-01', $lbFy, $lbStartMonth));
$lbTo = $lbFrom->modify('+1 year -1 day');
$lbToday = new DateTimeImmutable(date('Y-m-d'));
$lbAsOf = $lbTo < $lbToday ? $lbTo : $lbToday;
$lbLabel = $lbStartMonth === 1 ? (string) $lbFy : $lbFy . '-' . substr((string) ($lbFy + 1), 2);

$lbScoped = $scope->only(liveRows($store, 'employees'), true);
$lbFilter = orgFilter();
$lbOptions = orgFilterOptions($store, $lbScoped);
$lbPeople = orgFilterApply($store, $lbScoped, $lbFilter);
usort($lbPeople, static fn (array $a, array $b): int => strnatcasecmp(Employment::code($a), Employment::code($b)));

// Approved requests by employee code, read once.
$lbApproved = [];
foreach ($leave->all() as $lbReq) {
    $lbReq['state'] === Leave::APPROVED && $lbApproved[(string) $lbReq['employment_id']][] = $lbReq;
}

$lbRows = [];
foreach ($lbPeople as $lbE) {
    $lbCode = Employment::code($lbE);
    if ($lbCode === '') {
        continue;
    }
    $lbJoined = Employment::joinedOn($lbE, date('Y-m-d'));
    if ($lbJoined > $lbAsOf->format('Y-m-d')) {
        continue;   // not yet employed in this FY
    }
    $leave->accrueThrough($lbCode, $lbJoined, date('Y-m-d'));
    // Opening = closing of the previous FY, or the joining-day credit for a mid-year joiner.
    $lbOpenOn = $lbJoined >= $lbFrom->format('Y-m-d') ? new DateTimeImmutable($lbJoined) : $lbFrom->modify('-1 day');
    $lbTypes = [];

    foreach ($policy->typesFor(leaveScopeContext($store, $lbE)) as $lbT => $lbType) {
        $lbT = (string) $lbT;
        // ponytail: a request is counted in the FY its start date falls in; a span across 31 Mar counts whole in the earlier year.
        $lbAvailed = SmartPRS\Platform\Support\Rational::of(0);
        foreach ($lbApproved[$lbCode] ?? [] as $lbReq) {
            if ((string) $lbReq['leave_type'] === $lbT && $lbReq['from'] >= $lbFrom->format('Y-m-d') && $lbReq['from'] <= $lbAsOf->format('Y-m-d')) {
                $lbAvailed = $lbAvailed->plus(SmartPRS\Platform\Support\Rational::fromDecimalString(preg_match('/^\d+(\.\d+)?$/', (string) $lbReq['days']) === 1 ? (string) $lbReq['days'] : '0'));   // half days are 0.5
            }
        }
        $lbPaid = (bool) ($lbType['paid'] ?? false);
        $lbOpen = $lbPaid ? $leave->balance($lbCode, $lbT, $lbOpenOn)->toDecimalString(1) : '';
        $lbBal = $lbPaid ? $leave->balance($lbCode, $lbT, $lbAsOf)->toDecimalString(1) : '';
        // A type with nothing to say (all zero) is left out so the cell stays readable.
        $lbAvailed = $lbAvailed->toDecimalString(1);
        $lbZero = static fn (string $d): bool => $d === '' || SmartPRS\Platform\Support\Rational::fromDecimalString($d)->isZero();
        if ($lbZero($lbAvailed) && $lbZero($lbOpen) && $lbZero($lbBal)) {
            continue;
        }
        $lbTypes[$lbT] = ['name' => (string) $lbType['name'], 'open' => $lbOpen, 'availed' => $lbAvailed, 'bal' => $lbBal];
    }

    $lbRows[] = ['e' => $lbE, 'code' => $lbCode, 'types' => $lbTypes];
}

$lbCell = static function (array $types, string $k): string {
    if ($types === []) {
        return '<span class="note">&mdash;</span>';
    }
    $h = '';
    foreach ($types as $code => $t) {
        $v = $t[$k] === '' ? '&mdash;' : e($t[$k]);
        $h .= '<div class="lb-line" title="' . e($t['name']) . '"><span>' . e((string) $code) . '</span><b>' . $v . '</b></div>';
    }
    return $h;
};
?>
<style>
  .lb-line{display:flex;justify-content:space-between;gap:14px;font-size:12.5px;line-height:1.55}
  .lb-line span{color:var(--muted,#64748b);font-family:var(--mono)}
  .lb-line b{font-family:var(--mono)}
  .lb-table td{vertical-align:top}
  .lb-table td.lb-n{min-width:110px}
</style>
<div class="page-header">
  <div><h2>Leave Balance Report</h2>
    <p>Opening balance, leave availed and balance left, by leave type, for FY <?= e($lbLabel) ?>
      (<?= e($lbFrom->format('d-m-Y')) ?> to <?= e($lbTo->format('d-m-Y')) ?>)</p></div>
</div>
<?php
$lbFyOpts = '';
for ($y = $lbNowFy; $y >= $lbNowFy - 5; $y--) {
    $lbFyOpts .= '<option value="' . $y . '"' . ($y === $lbFy ? ' selected' : '') . '>'
        . ($lbStartMonth === 1 ? $y : $y . '-' . substr((string) ($y + 1), 2)) . '</option>';
}
// The FY select joins the shared org filter bar's form (form= attribute), so one Show applies both.
echo str_replace('<form method="get" class="row org-filter"', '<form method="get" id="lb-filter" class="row org-filter"',
    orgFilterBar('leave-balance-report', $lbFilter, $lbOptions));
?>
<div class="row" style="margin:-6px 0 14px;align-items:flex-end">
  <div><label for="lb-fy">Financial year</label>
    <select id="lb-fy" name="fy" form="lb-filter" onchange="this.form.submit()"><?= $lbFyOpts ?></select></div>
</div>
<div class="card" style="padding:0">
  <div class="card-header"><div>
    <h3 style="font-size:15px;font-weight:700"><?= count($lbRows) ?> employee(s)</h3>
    <p class="note" style="margin:0">Balance is as at <?= e($lbAsOf->format('d-m-Y')) ?>. Opening + credited in the year (accrual, comp-off) &minus; availed &minus; lapsed = balance. Types with nothing in all three are left out.</p>
  </div>
<?php if ($lbRows !== []): ?>
    <div class="actions"><button type="button" class="btn btn-outline" onclick="lbExport()"><i class="fas fa-file-excel"></i> Export Excel</button></div>
<?php endif; ?>
  </div>
<?php if ($lbRows === []): ?>
  <div class="empty"><i class="fas fa-filter"></i>No employees match these filters.</div>
<?php else: ?>
  <div class="table-wrap"><table class="lb-table">
    <thead><tr><th>Emp ID</th><th>Name</th><th>Company</th><th>Branch</th><th>Department</th>
      <th>Opening Leave Balance</th><th>Leaves Availed</th><th>Balance Leaves</th></tr></thead>
    <tbody>
<?php foreach ($lbRows as $lbR): ?>
    <tr>
      <td><code><?= e($lbR['code']) ?></code></td>
      <td><?= e(trim((string) ($lbR['e']['name'] ?? ''))) ?></td>
      <td><?= e(orgValueOf($store, $lbR['e'], 'company')) ?></td>
      <td><?= e(orgValueOf($store, $lbR['e'], 'branch')) ?></td>
      <td><?= e(orgValueOf($store, $lbR['e'], 'department')) ?></td>
      <td class="lb-n"><?= $lbCell($lbR['types'], 'open') ?></td>
      <td class="lb-n"><?= $lbCell($lbR['types'], 'availed') ?></td>
      <td class="lb-n"><?= $lbCell($lbR['types'], 'bal') ?></td>
    </tr>
<?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
</div>
<?php
// EXPORT (owner, 28 Oct): the same rows, one line per employee per leave type, through csv-xlsx → formatted Excel.
$lbQ = static fn (string $v): string => '"' . str_replace('"', '""', $v) . '"';
$lbCsv = [implode(',', array_map($lbQ, ['Emp ID', 'Name', 'Company', 'Branch', 'Department', 'Leave Type', 'Opening Leave Balance', 'Leaves Availed', 'Balance Leaves']))];
foreach ($lbRows as $lbR) {
    $lbBase = [$lbR['code'], trim((string) ($lbR['e']['name'] ?? '')), orgValueOf($store, $lbR['e'], 'company'), orgValueOf($store, $lbR['e'], 'branch'), orgValueOf($store, $lbR['e'], 'department')];
    foreach ($lbR['types'] === [] ? ['' => ['name' => '', 'open' => '', 'availed' => '', 'bal' => '']] : $lbR['types'] as $lbCode => $lbT) {
        $lbCsv[] = implode(',', array_map($lbQ, [...$lbBase, $lbT['name'] === '' ? (string) $lbCode : $lbCode . ' - ' . $lbT['name'], $lbT['open'], $lbT['availed'], $lbT['bal']]));
    }
}
?>
<script>
function lbExport(){
  var f=document.createElement('form'); f.method='post'; f.action='?r=leave-balance-report';
  [['csrf',<?= json_encode((string) $session->token()) ?>],['action','csv-xlsx'],['name','leave-balance-report.csv'],['csv',<?= json_encode(implode("\r\n", $lbCsv)) ?>]].forEach(function(kv){var i=document.createElement('input');i.type='hidden';i.name=kv[0];i.value=kv[1];f.appendChild(i);});
  document.body.appendChild(f); f.submit(); f.remove();
}
</script>
