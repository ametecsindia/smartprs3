<?php declare(strict_types=1); if (!$may('employee.view')): ?>
<div class="page-header"><div><h2>Agent Attendance &amp; Activity</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// FIELD FORCE → AGENT ATTENDANCE & ACTIVITY (23 Oct 2026). One row per off-roll agent per day — present /
// half day / absent / leave / week off, check-in and out, accounts allotted, visits, promises to pay, accounts
// paid, amount collected. Three ways in, one table: typed here, the bulk sheet (one date, every agent
// pre-listed), or the mobile app (/api/v1/offroll/activity — check-in / out with GPS and each visit). Every
// day is approved by a second person before a payout counts it (Organization Configuration decides).
use SmartPRS\Surface\Amount;
use SmartPRS\Surface\Dates;

include __DIR__ . '/../partials/offroll-boot.php';

if (!$opReady) {
    echo '<div class="page-header"><div><h2>Agent Attendance &amp; Activity</h2></div></div>' . $opNotReady;
    return;
}

$month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($_GET['month'] ?? '')) === 1 ? (string) $_GET['month'] : date('Y-m');
$monthEnd = (new DateTimeImmutable($month . '-01'))->modify('last day of this month')->format('Y-m-d');
$view = (string) ($_GET['view'] ?? 'grid') === 'list' ? 'list' : 'grid';
$fCo = (string) ($_GET['f_company'] ?? '');
$fBr = (string) ($_GET['f_branch'] ?? '');
$fVe = (string) ($_GET['vendor'] ?? '');
$fAg = (string) ($_GET['agent'] ?? '');
$fAp = (string) ($_GET['ap'] ?? '');
$fSrc = (string) ($_GET['src'] ?? '');
$agents = array_filter($opAgents, static fn (array $a): bool => ($fCo === '' || (string) ($a['employer_company_id'] ?? '') === $fCo)
    && ($fBr === '' || (string) ($a['branch_id'] ?? '') === $fBr) && ($fVe === '' || (string) ($a['vendor_id'] ?? '') === $fVe)
    && ($fAg === '' || (string) $a['id'] === $fAg)
    && (trim((string) ($a['joining_date'] ?? '')) === '' || substr((string) $a['joining_date'], 0, 10) <= $monthEnd)
    && (trim((string) ($a['exit_date'] ?? '')) === '' || substr((string) $a['exit_date'], 0, 10) >= $month . '-01'));
$all = array_values(array_filter($op->activity($month), static fn (array $r): bool => isset($agents[(string) $r['agent_id']])));
$rows = array_values(array_filter($all, static fn (array $r): bool => ($fAp === '' || (string) ($r['approval'] ?? '') === $fAp) && ($fSrc === '' || (string) ($r['source'] ?? '') === $fSrc)));
usort($rows, static fn (array $x, array $y): int => strcmp((string) $y['activity_date'], (string) $x['activity_date']) ?: strcmp($op->code($opAgents[(string) $x['agent_id']]), $op->code($opAgents[(string) $y['agent_id']])));
$grid = [];

foreach ($all as $r) {
    $grid[(string) $r['agent_id']][(string) $r['activity_date']] = $r;
}

$st = (array) $opD['day_statuses'];
$stColor = ['present' => '#dcfce7', 'half_day' => '#fef9c3', 'absent' => '#fee2e2', 'leave' => '#e0e7ff', 'week_off' => '#f1f5f9'];
$pend = count(array_filter($all, static fn (array $r): bool => (string) ($r['approval'] ?? '') === 'pending'));
$tot = ['days' => Amount::quantity('0'), 'visits' => 0, 'ptp' => 0, 'paid' => 0, 'coll' => \SmartPRS\Platform\Support\Money::zero()];

foreach ($all as $r) {
    if ((string) ($r['approval'] ?? '') === 'rejected') {
        continue;
    }

    $tot['days'] = $tot['days']->plus(Amount::quantity((string) ($st[(string) $r['day_status']]['days'] ?? '0')));
    $tot['visits'] += (int) ($r['visits'] ?? 0);
    $tot['ptp'] += (int) ($r['ptp_count'] ?? 0);
    $tot['paid'] += (int) ($r['paid_accounts'] ?? 0);
    $tot['coll'] = $tot['coll']->plus(Amount::of((string) ($r['amount_collected'] ?? '')));
}

// THE ENTRY FORM, prefilled when a day is picked (from the grid or the profile).
$eAgent = (string) ($_GET['ea'] ?? $_GET['agent'] ?? '');
$eDate = Dates::toIso((string) ($_GET['date'] ?? '')) ?? min(date('Y-m-d'), $monthEnd);
$eRow = $eAgent !== '' && isset($opAgents[$eAgent]) ? $op->day($eAgent, $eDate) : null;
$eLocked = $eRow !== null && $op->locked($eRow);
$keep = static fn (array $over = []): string => '?' . http_build_query(array_merge(['r' => 'offroll-activity', 'month' => $month, 'view' => $view, 'f_company' => $fCo, 'f_branch' => $fBr, 'vendor' => $fVe, 'agent' => $fAg, 'ap' => $fAp, 'src' => $fSrc], $over));
$srcBadge = ['manual' => 'badge-blue', 'upload' => 'badge-gray', 'app' => 'badge-green'];
$geo = static fn (string $g): string => $g === '' ? '' : ' <a target="_blank" rel="noopener" title="GPS ' . e($g) . '" href="https://maps.google.com/?q=' . e($g) . '"><i class="fas fa-location-dot"></i></a>';
?>
<div class="page-header">
  <div><h2>Agent Attendance &amp; Activity</h2><p><?= e(date('F Y', (int) strtotime($month . '-01'))) ?> &middot; <?= count($agents) ?> agent(s) &middot; <?= count($all) ?> day(s) recorded &middot; <b><?= $pend ?></b> pending approval</p></div>
  <div class="actions"><a class="btn btn-outline btn-sm" href="<?= e($keep(['view' => $view === 'grid' ? 'list' : 'grid'])) ?>"><i class="fas <?= $view === 'grid' ? 'fa-list' : 'fa-table-cells' ?>"></i> <?= $view === 'grid' ? 'Day-by-day list &amp; approvals' : 'Month grid' ?></a></div>
</div>

<div class="kpi-strip">
  <div class="kpi blue"><h3><?= e(rtrim(rtrim($tot['days']->toDecimalString(), '0'), '.') ?: '0') ?></h3><p>days worked</p><div class="sub">present + half days (not rejected)</div></div>
  <div class="kpi green"><h3><?= $tot['visits'] ?></h3><p>field visits</p><div class="sub"><?= $tot['ptp'] ?> promises to pay &middot; <?= $tot['paid'] ?> accounts paid</div></div>
  <div class="kpi green"><h3><?= e($opMoney($tot['coll']->toDecimalString())) ?></h3><p>collected</p><div class="sub">by these agents this month</div></div>
  <a class="kpi orange" href="<?= e($keep(['view' => 'list', 'ap' => 'pending'])) ?>" style="text-decoration:none"><h3><?= $pend ?></h3><p>pending approval</p><div class="sub">a payout counts approved days</div></a>
</div>

<div class="card">
  <form method="get" class="row" style="align-items:flex-end;margin:0;gap:10px;flex-wrap:wrap"><input type="hidden" name="r" value="offroll-activity"><input type="hidden" name="view" value="<?= e($view) ?>">
    <div><label>Month</label><input type="month" name="month" value="<?= e($month) ?>"></div>
    <div><label>Company</label><select name="f_company"><option value="">ALL</option><?php foreach ($opCompanies as $k => $v): ?><option value="<?= e($k) ?>" <?= $fCo === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div><label>Branch</label><select name="f_branch"><option value="">ALL</option><?php foreach ($opBranches as $k => $v): ?><option value="<?= e($k) ?>" <?= $fBr === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div><label>Vendor</label><select name="vendor"><option value="">ALL</option><?php foreach ($opVendors as $k => $v): ?><option value="<?= e((string) $k) ?>" <?= $fVe === (string) $k ? 'selected' : '' ?>><?= e((string) $v['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>Agent</label><select name="agent"><option value="">ALL</option><?php foreach ($opAgents as $k => $a): ?><option value="<?= e((string) $k) ?>" <?= $fAg === (string) $k ? 'selected' : '' ?>><?= e($op->label($a)) ?></option><?php endforeach; ?></select></div>
    <div><label>Approval</label><select name="ap"><option value="">ALL</option><?php foreach ((array) $opD['approvals'] as $k => $v): ?><option value="<?= e((string) $k) ?>" <?= $fAp === (string) $k ? 'selected' : '' ?>><?= e((string) $v) ?></option><?php endforeach; ?></select></div>
    <div><label>Source</label><select name="src"><option value="">ALL</option><?php foreach ((array) $opD['sources'] as $k => $v): ?><option value="<?= e((string) $k) ?>" <?= $fSrc === (string) $k ? 'selected' : '' ?>><?= e((string) $v) ?></option><?php endforeach; ?></select></div>
    <div><button class="go" type="submit">Show</button></div>
  </form>
</div>

<?php if ($opMayEdit): ?>
<div class="card" id="entry">
  <h3 style="margin-top:0">Record a day <?= $eRow !== null ? '<span class="badge badge-amber">correcting the day on file</span>' : '' ?><?= $eLocked ? ' <span class="badge badge-red">inside an approved payout — cannot change</span>' : '' ?></h3>
  <form method="post" class="row" style="align-items:flex-end;gap:10px;flex-wrap:wrap;margin:0"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="offroll-activity"><input type="hidden" name="op" value="save"><input type="hidden" name="month" value="<?= e($month) ?>">
    <div style="min-width:240px"><label>Agent *</label><select name="agent_id" required><option value="">— pick —</option><?php foreach ($opAgents as $k => $a): ?><option value="<?= e((string) $k) ?>" <?= $eAgent === (string) $k ? 'selected' : '' ?>><?= e($op->label($a)) ?></option><?php endforeach; ?></select></div>
    <div><label>Date *</label><input type="date" name="activity_date" value="<?= e($eDate) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
    <div><label>Day *</label><select name="day_status" required><?php foreach ($st as $k => $s): ?><option value="<?= e((string) $k) ?>" <?= (string) ($eRow['day_status'] ?? 'present') === (string) $k ? 'selected' : '' ?>><?= e((string) $s['label']) ?></option><?php endforeach; ?></select></div>
    <div><label>Check-in</label><input type="time" name="check_in" value="<?= e((string) ($eRow['check_in'] ?? '')) ?>"></div>
    <div><label>Check-out</label><input type="time" name="check_out" value="<?= e((string) ($eRow['check_out'] ?? '')) ?>"></div>
    <div><label>Accounts allotted</label><input name="allocated_accounts" inputmode="numeric" value="<?= e((string) ($eRow['allocated_accounts'] ?? '')) ?>" style="width:90px"></div>
    <div><label>Visits</label><input name="visits" inputmode="numeric" value="<?= e((string) ($eRow['visits'] ?? '')) ?>" style="width:80px"></div>
    <div><label>PTPs</label><input name="ptp_count" inputmode="numeric" value="<?= e((string) ($eRow['ptp_count'] ?? '')) ?>" style="width:70px"></div>
    <div><label>Accounts paid</label><input name="paid_accounts" inputmode="numeric" value="<?= e((string) ($eRow['paid_accounts'] ?? '')) ?>" style="width:80px"></div>
    <div><label>Collected (₹)</label><input name="amount_collected" inputmode="decimal" value="<?= e(trim((string) ($eRow['amount_collected'] ?? '')) === '' ? '' : Amount::of((string) $eRow['amount_collected'])->toDecimalString()) ?>" style="width:120px"></div>
    <div style="flex:1;min-width:200px"><label>Remarks</label><input name="remarks" value="<?= e((string) ($eRow['remarks'] ?? '')) ?>" placeholder="route, bucket, reason for absence…"></div>
    <div><button class="btn btn-primary btn-sm" type="submit" <?= $eLocked ? 'disabled' : '' ?>><i class="fas fa-check"></i> Save day</button></div>
  </form>
  <div class="row" style="gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:12px;border-top:1px dashed var(--border);padding-top:12px">
    <form method="post" class="row" style="gap:6px;align-items:flex-end;margin:0"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="offroll-activity"><input type="hidden" name="op" value="sample"><input type="hidden" name="f_company" value="<?= e($fCo) ?>"><input type="hidden" name="f_branch" value="<?= e($fBr) ?>"><input type="hidden" name="vendor" value="<?= e($fVe) ?>">
      <div><label>Bulk sheet for the day</label><input type="date" name="day" value="<?= e(min(date('Y-m-d'), $monthEnd)) ?>" max="<?= e(date('Y-m-d')) ?>"></div>
      <div><button class="btn btn-outline btn-sm" type="submit"><i class="fas fa-file-excel"></i> Download (agents pre-listed)</button></div></form>
    <form method="post" enctype="multipart/form-data" class="row" style="gap:6px;align-items:flex-end;margin:0"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="offroll-activity"><input type="hidden" name="op" value="import"><input type="hidden" name="month" value="<?= e($month) ?>">
      <div><label>Upload the filled sheet</label><input type="file" name="sheet" accept=".xlsx,.xls,.csv" required style="width:220px;font-size:12px"></div>
      <div><button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-file-arrow-up"></i> Upload</button></div></form>
    <p class="note" style="margin:0;flex:1;min-width:260px">Mobile app: <code>POST /api/v1/offroll/activity</code> with an API key (scope <i>ingest</i>) — check-in, check-out and each visit with GPS; it fills the same day rows (source: Mobile app).</p>
  </div>
</div>
<?php endif; ?>

<?php if ($view === 'grid'): ?>
<div class="card" style="padding:0">
  <div class="table-wrap"><table style="font-size:12px">
    <thead><tr><th style="position:sticky;left:0;background:var(--card);z-index:1">Agent</th>
<?php for ($dt = new DateTimeImmutable($month . '-01'); $dt->format('Y-m-d') <= $monthEnd; $dt = $dt->modify('+1 day')): ?><th style="text-align:center;padding:4px 2px;min-width:28px"><?= e($dt->format('d')) ?><div class="note" style="font-size:9px"><?= e(substr($dt->format('D'), 0, 2)) ?></div></th><?php endfor; ?>
      <th class="n">Days</th><th class="n">Visits</th><th class="n">PTP</th><th class="n">Paid</th><th class="n">Collected</th><th class="n">Pending</th></tr></thead>
    <tbody>
<?php if ($agents === []): ?><tr><td colspan="40" class="note">No agents match.</td></tr><?php endif; ?>
<?php foreach ($agents as $id => $a): $g = $grid[$id] ?? []; $d = Amount::quantity('0'); $v = $p = $pa = $pn = 0; $c = \SmartPRS\Platform\Support\Money::zero(); ?>
    <tr><td style="white-space:nowrap;position:sticky;left:0;background:var(--card)"><a href="?r=offroll-agent&amp;id=<?= e((string) $id) ?>&amp;month=<?= e($month) ?>"><?= e($op->label($a)) ?></a></td>
<?php for ($dt = new DateTimeImmutable($month . '-01'); $dt->format('Y-m-d') <= $monthEnd; $dt = $dt->modify('+1 day')): $k = $dt->format('Y-m-d'); $r = $g[$k] ?? null; $s = (string) ($r['day_status'] ?? '');
      if ($r !== null && (string) $r['approval'] !== 'rejected') { $d = $d->plus(Amount::quantity((string) ($st[$s]['days'] ?? '0'))); $v += (int) ($r['visits'] ?? 0); $p += (int) ($r['ptp_count'] ?? 0); $pa += (int) ($r['paid_accounts'] ?? 0); $c = $c->plus(Amount::of((string) ($r['amount_collected'] ?? ''))); }
      $r !== null && (string) $r['approval'] === 'pending' && $pn++; ?>
      <td style="text-align:center;padding:2px;background:<?= $r === null ? 'transparent' : ($stColor[$s] ?? 'transparent') ?>;<?= ($r['approval'] ?? '') === 'pending' ? 'box-shadow:inset 0 0 0 2px #f59e0b;' : '' ?><?= ($r['approval'] ?? '') === 'rejected' ? 'text-decoration:line-through;opacity:.5;' : '' ?>">
<?php if ($opMayEdit && ($r === null || !$op->locked($r)) && $k <= date('Y-m-d')): ?><a href="<?= e($keep(['ea' => $id, 'date' => $k])) ?>#entry" style="text-decoration:none;color:var(--text1)" title="<?= e(Dates::dmy($k) . ($r === null ? ' — record' : ' — ' . ($st[$s]['label'] ?? $s) . ', ' . (int) ($r['visits'] ?? 0) . ' visits, ' . Amount::of((string) ($r['amount_collected'] ?? ''))->format() . ', ' . (string) $r['approval'])) ?>"><?= $r === null ? '<span style="opacity:.25">·</span>' : '<b>' . e((string) ($st[$s]['short'] ?? '?')) . '</b>' ?></a>
<?php else: ?><?= $r === null ? '' : '<b>' . e((string) ($st[$s]['short'] ?? '?')) . '</b>' ?><?php endif; ?></td>
<?php endfor; ?>
      <td class="n"><?= e(rtrim(rtrim($d->toDecimalString(), '0'), '.') ?: '0') ?></td><td class="n"><?= $v ?></td><td class="n"><?= $p ?></td><td class="n"><?= $pa ?></td><td class="n" style="white-space:nowrap"><?= e($c->format()) ?></td><td class="n"><?= $pn > 0 ? '<span class="badge badge-amber">' . $pn . '</span>' : '0' ?></td></tr>
<?php endforeach; ?>
    </tbody></table></div>
</div>
<p class="note">P present · H half day · A absent · L leave · WO week off · amber outline = pending approval · struck through = rejected. Click a day to record or correct it.</p>
<?php else: ?>
<div class="card" style="padding:0">
<?php if ($opMayApprove): ?>
  <form method="post" id="op-decide" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:12px 14px;margin:0;border-bottom:1px solid var(--border)"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="offroll-activity"><input type="hidden" name="op" value="decide"><input type="hidden" name="back" value="<?= e($keep()) ?>">
    <b>Ticked days:</b><input name="remarks" placeholder="remarks (optional)" style="width:14rem">
    <button class="btn btn-primary btn-sm" name="decision" value="approve"><i class="fas fa-check"></i> Approve</button>
    <button class="btn btn-outline btn-sm" name="decision" value="reject"><i class="fas fa-xmark"></i> Reject</button>
    <span class="note">The person who recorded a day does not approve it<?= $opIsAdmin ? ' (the Administrator may)' : '' ?>.</span></form>
<?php endif; ?>
  <div class="table-wrap"><table>
    <thead><tr><?= $opMayApprove ? '<th><input type="checkbox" onclick="document.querySelectorAll(\'.op-day\').forEach(function(c){if(!c.disabled){c.checked=this.checked;}}.bind(this))" title="Tick all pending"></th>' : '' ?><th>Date</th><th>Agent</th><th>Day</th><th>In &ndash; out</th><th class="n">Allotted</th><th class="n">Visits</th><th class="n">PTP</th><th class="n">Paid</th><th class="n">Collected</th><th>Source</th><th>Approval</th><th>Remarks</th><th></th></tr></thead>
    <tbody>
<?php if ($rows === []): ?><tr><td colspan="14" class="note">Nothing recorded for this filter.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): $a = $opAgents[(string) $r['agent_id']]; $lk = $op->locked($r); ?>
    <tr style="<?= (string) $r['approval'] === 'rejected' ? 'opacity:.55' : '' ?>">
<?php if ($opMayApprove): ?><td><input type="checkbox" class="op-day" form="op-decide" name="ids[]" value="<?= e((string) $r['id']) ?>" <?= $lk || (string) $r['approval'] !== 'pending' ? 'disabled' : '' ?>></td><?php endif; ?>
      <td style="white-space:nowrap"><?= e(Dates::dmy((string) $r['activity_date'])) ?></td>
      <td style="white-space:nowrap"><a href="?r=offroll-agent&amp;id=<?= e((string) $a['id']) ?>&amp;month=<?= e($month) ?>"><?= e($op->label($a)) ?></a></td>
      <td><?= e((string) ($st[(string) $r['day_status']]['label'] ?? $r['day_status'])) ?></td>
      <td style="white-space:nowrap"><?= e((string) ($r['check_in'] ?? '') ?: '—') ?><?= $geo((string) ($r['check_in_geo'] ?? '')) ?> &ndash; <?= e((string) ($r['check_out'] ?? '') ?: '—') ?><?= $geo((string) ($r['check_out_geo'] ?? '')) ?></td>
      <td class="n"><?= e((string) ($r['allocated_accounts'] ?? '')) ?></td><td class="n"><?= (int) ($r['visits'] ?? 0) ?></td><td class="n"><?= (int) ($r['ptp_count'] ?? 0) ?></td><td class="n"><?= (int) ($r['paid_accounts'] ?? 0) ?></td>
      <td class="n" style="white-space:nowrap"><?= e($opMoney((string) ($r['amount_collected'] ?? ''))) ?></td>
      <td><span class="badge <?= $srcBadge[(string) ($r['source'] ?? '')] ?? 'badge-gray' ?>"><?= e((string) ($opD['sources'][(string) ($r['source'] ?? '')] ?? $r['source'])) ?></span></td>
      <td><span class="badge <?= ['approved' => 'badge-green', 'rejected' => 'badge-red'][(string) $r['approval']] ?? 'badge-amber' ?>"><?= e((string) ($opD['approvals'][(string) $r['approval']] ?? $r['approval'])) ?></span><?= $lk ? ' <span class="badge badge-blue">paid out</span>' : '' ?>
        <div class="note" style="font-size:11px"><?= e(trim((string) ($r['created_by'] ?? '')) . (trim((string) ($r['decided_by'] ?? '')) !== '' ? ' → ' . $r['decided_by'] : '')) ?></div></td>
      <td class="note" style="max-width:220px"><?= e(trim((string) ($r['remarks'] ?? '') . ((string) ($r['decision_remarks'] ?? '') !== '' ? ' · ' . $r['decision_remarks'] : ''))) ?></td>
      <td><?php if ($opMayEdit && !$lk): ?><a class="btn btn-outline btn-sm" href="<?= e($keep(['ea' => (string) $a['id'], 'date' => (string) $r['activity_date']])) ?>#entry" title="Correct"><i class="fas fa-pen"></i></a><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
    </tbody></table></div>
</div>
<?php endif; ?>
