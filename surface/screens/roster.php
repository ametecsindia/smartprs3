<?php declare(strict_types=1); if (!$may('attendance.day.view')): ?>
<div class="page-header"><div><h2>Roster</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'attendance.day.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Roster: a day-wise shift plan per employee, overriding the
// default shift. Here a row covers a date range for one employee, assigned
// singly or to everybody in an org scope; the month grid shows employee × day →
// shift code (bold where a roster row applies, faint where the scoped Working
// Shift does). The most recent row covering a date wins — surface/Roster.php.
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Roster;
use SmartPRS\Surface\Shifts;

$roster = new Roster($store, __DIR__ . '/../../config/roster.json', new Shifts($store));
$canAssign = $may('employee.update');
$month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) === 1 ? (string) $_GET['month'] : date('Y-m');
$filter = orgFilter();
$scoped = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
usort($scoped, static fn (array $a, array $b): int => strcmp(Employment::code($a), Employment::code($b)));
$shown = orgFilterApply($store, $scoped, $filter);
// SEVERAL EMPLOYEES, TICKED (owner, 22 Oct 2026): the Employee box ticks any number; grid and Excel/CSV follow.
$sel = array_values(array_filter(array_map('trim', explode(',', (string) ($_GET['sel'] ?? '')))));
$sel === [] || $shown = array_values(array_filter($shown, static fn (array $e): bool => in_array(Employment::code($e), $sel, true)));
$shifts = array_values(array_filter($roster->shifts(), static fn (array $s): bool => !SmartPRS\Surface\Modules::isRetired($s)));
$days = (int) date('t', (int) strtotime($month . '-01'));
// A picked From/To (below the Show button) overrides the month view — an
// arbitrary range instead of a fixed calendar month; either way $dates drives
// the grid, so the grid itself never needs to know which mode is in play.
$rangeFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) === 1 ? (string) $_GET['from'] : '';
$rangeTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) === 1 ? (string) $_GET['to'] : '';
$dates = $rangeFrom !== '' && $rangeTo !== '' && $rangeTo >= $rangeFrom
    ? Roster::rangeDates($rangeFrom, $rangeTo, 93)
    : Roster::monthDates($month);
$grid = $roster->grid($shown, $dates);
$names = [];

foreach ($scoped as $e) {
    $names[(string) $e['id']] = Employment::code($e) . ' ' . (string) ($e['name'] ?? '');
}

$gridFrom = $dates[0] ?? ($month . '-01');
$gridTo = $dates[count($dates) - 1] ?? ($month . '-' . $days);
$recent = array_filter($roster->rows(), static fn (array $r): bool => (string) $r['to_date'] >= $gridFrom && (string) $r['from_date'] <= $gridTo && isset($names[(string) $r['employee_id']]));
usort($recent, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']) ?: (int) $b['id'] <=> (int) $a['id']);
$prev = date('Y-m', (int) strtotime($month . '-01 -1 month'));
$next = date('Y-m', (int) strtotime($month . '-01 +1 month'));
?>
<div class="page-header">
  <div><h2>Roster</h2>
    <p>Who is on which Working Shift, day by day. A roster row overrides the scoped shift from Organization Setup → Working Shifts for its dates; the most recent row covering a day wins, so a one-day override is a one-day row saved after the range.</p></div>
  <div><?php $peopleExport = ['screen' => 'roster', 'keep' => ['month' => $month, 'from' => $rangeFrom, 'to' => $rangeTo, 'sel' => implode(',', $sel)] + orgFilterKeep($filter)]; include __DIR__ . '/../partials/people-export-buttons.php'; ?>
    <a class="btn btn-outline btn-sm" href="?r=roster&amp;month=<?= e($prev) ?><?= orgFilterQuery($filter) ?>">&larr; <?= e($prev) ?></a>
    <b style="margin:0 10px"><?= e(date('F Y', (int) strtotime($month . '-01'))) ?></b>
    <a class="btn btn-outline btn-sm" href="?r=roster&amp;month=<?= e($next) ?><?= orgFilterQuery($filter) ?>"><?= e($next) ?> &rarr;</a></div>
</div>

<?php // ONE FORM (owner, 13 Oct): this screen's own choice sits in the filter bar — one Show, no second form. ?>
<?= orgFilterBar('roster', $filter, orgFilterOptions($store, $scoped), ['month' => $month], 'Show', $sel, null, null,
    '<div><label>Grid from</label><input type="date" name="from" value="' . e($rangeFrom) . '"></div><div><label>to</label><input type="date" name="to" value="' . e($rangeTo) . '"></div>') ?>
<p class="note" style="margin:-8px 0 14px">Leave both dates blank for the calendar month; a range longer than 93 days is cut to 93.<?php if ($rangeFrom !== '' || $rangeTo !== ''): ?> <a href="?r=roster&amp;month=<?= e($month) ?><?= orgFilterQuery($filter) ?>">Back to <?= e(date('F Y', (int) strtotime($month . '-01'))) ?></a><?php endif; ?></p>

<div class="card">
  <fieldset class="form-section"><legend>Roster sample &amp; import</legend>
  <form method="post" class="row" style="align-items:flex-end;gap:10px;flex-wrap:wrap">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="roster-sample">
<?php foreach ($filter as $k => $v): ?><input type="hidden" name="f_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
    <div><label>From</label><input type="date" name="from" value="<?= e($month) ?>-01" required></div>
    <div><label>To</label><input type="date" name="to" value="<?= e($month) ?>-<?= e((string) $days) ?>" required></div>
    <div><button class="btn btn-outline" type="submit"><i class="fas fa-download"></i> Download roster sample</button></div>
  </form>
  <p class="note">Emp ID, Name, Reporting Manager, Designation and Branch all come filled in for whoever the filter above
    shows &mdash; nothing to pick by hand. Every day column is a dropdown of the live shift codes from Organization Setup
    &rarr; Working Shifts, plus W.OFF / Holiday / Leave for marking those days (informational only: picking one there does
    not itself touch Weekly Off, Holidays or Leave &mdash; use those screens for that); a day-of-week row sits above the
    dates, and each value gets its own colour once filled in. The filename carries the range you picked.</p>
<?php if ($canAssign): ?>
  <form method="post" enctype="multipart/form-data" class="row" style="align-items:flex-end;gap:10px;flex-wrap:wrap;margin-top:10px">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="roster-import">
    <div style="flex:2"><label for="ros_file">The sample, filled in (.xlsx, .xls or .csv)</label>
      <input id="ros_file" name="sheet" type="file" accept=".csv,.xls,.xlsx,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></div>
    <div><button class="go" type="submit"><i class="fas fa-upload"></i> Import</button></div>
  </form>
  <p class="note">Only filled-in cells are read: a blank cell changes nothing for that day. Consecutive days on the same
    code become one roster row, same as filling in "Assign a shift" once for that range.</p>
<?php endif; ?>
  </fieldset>
</div>

<?php if ($shifts === []): ?>
<div class="card"><div class="problem" style="margin:0">No live Working Shift exists. Add one under <a href="?r=m&amp;k=shifts">Organization Setup → Working Shifts</a> before rostering anybody.</div></div>
<?php elseif ($canAssign): ?>
<div class="card">
  <fieldset class="form-section"><legend>Assign a shift</legend>
  <form method="post" class="row" style="align-items:flex-end;gap:10px;flex-wrap:wrap"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="roster-assign"><input type="hidden" name="op" value="assign"><input type="hidden" name="month" value="<?= e($month) ?>">
<?php foreach ($filter as $k => $v): ?><input type="hidden" name="f_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
    <div><label>Who</label><select name="employee_id">
      <option value="">Everyone in the filter above (<?= count($shown) ?>)</option>
<?php foreach ($shown as $e): ?><option value="<?= e((string) $e['id']) ?>"><?= e($names[(string) $e['id']]) ?></option><?php endforeach; ?>
    </select></div>
    <div><label>Working shift</label><select name="shift_id" required>
<?php foreach ($shifts as $s): ?><option value="<?= e((string) $s['id']) ?>"><?= e((string) ($s['code'] ?? '') !== '' ? (string) $s['code'] . ' — ' : '') ?><?= e((string) $s['name']) ?> (<?= e(substr((string) $s['start_time'], 0, 5)) ?>–<?= e(substr((string) $s['end_time'], 0, 5)) ?>)</option><?php endforeach; ?>
    </select></div>
    <div><label>From</label><input type="date" name="from" value="<?= e($month) ?>-01" required></div>
    <div><label>To</label><input type="date" name="to" value="<?= e($month) ?>-<?= e((string) $days) ?>" required></div>
    <div><label>Note</label><input name="note" placeholder="why (optional)"></div>
    <button class="go" type="submit">Roster</button>
  </form>
  <p class="note">For a single day's override, set From and To to the same date. Nothing is rewritten: the new row simply wins over the older range for those days.</p>
  </fieldset>
</div>
<?php endif; ?>

<div class="card" style="padding:0">
  <div class="table-wrap"><table style="font-size:11px">
    <thead><tr><th style="position:sticky;left:0;background:var(--card,#fff)">Employee</th>
<?php foreach ($dates as $date): $dow = date('D', (int) strtotime($date)); ?><th style="text-align:center;padding:4px 3px<?= $dow === 'Sun' ? ';color:var(--red)' : '' ?>"><?= e(date('j', (int) strtotime($date))) ?><br><span class="note" style="font-size:9px"><?= e(substr($dow, 0, 2)) ?></span></th><?php endforeach; ?>
    </tr></thead>
    <tbody>
<?php if ($shown === []): ?><tr><td colspan="<?= count($dates) + 1 ?>" class="note">No employee matches the filter.</td></tr><?php endif; ?>
<?php foreach ($shown as $e): $id = (string) $e['id']; ?>
      <tr><td style="white-space:nowrap;position:sticky;left:0;background:var(--card,#fff)"><b><?= e(Employment::code($e)) ?></b> <?= e((string) ($e['name'] ?? '')) ?></td>
<?php foreach ($dates as $date): $cell = $grid[$id][$date]; ?><td style="text-align:center;padding:4px 3px;<?= $cell['rostered'] ? 'font-weight:700' : 'color:var(--text3,#94a3b8)' ?>"><?= e($cell['code']) ?></td><?php endforeach; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="note" style="padding:8px 14px"><?= e((string) $roster->declared()['default_mark_note']) ?></p>
</div>

<div class="card" style="padding:0">
  <div style="padding:12px 14px"><b>Roster rows touching <?= e($gridFrom) ?> to <?= e($gridTo) ?></b> <span class="note">newest first — the one on top wins where two overlap</span></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Employee</th><th>Shift</th><th>From</th><th>To</th><th>Note</th><th>By</th><th></th></tr></thead>
    <tbody>
<?php if ($recent === []): ?><tr><td colspan="7" class="note">No roster row for this range; everybody is on their scoped Working Shift.</td></tr><?php endif; ?>
<?php foreach ($recent as $r): $s = $roster->shifts()[(string) $r['shift_id']] ?? []; ?>
      <tr><td><?= e($names[(string) $r['employee_id']]) ?></td><td><b><?= e((string) ($s['code'] ?? '')) ?></b> <?= e((string) ($s['name'] ?? '#' . (string) $r['shift_id'])) ?></td>
        <td><?= e((string) $r['from_date']) ?></td><td><?= e((string) $r['to_date']) ?></td><td class="note"><?= e((string) ($r['note'] ?? '')) ?></td>
        <td class="note"><?= e((string) ($r['created_by'] ?? '')) ?><br><?= e(substr((string) ($r['created_at'] ?? ''), 0, 16)) ?></td>
        <td><?php if ($canAssign): ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
          <input type="hidden" name="action" value="roster-assign"><input type="hidden" name="op" value="cancel"><input type="hidden" name="id" value="<?= e((string) $r['id']) ?>"><input type="hidden" name="month" value="<?= e($month) ?>">
          <button class="btn btn-sm btn-outline" type="submit">Cancel</button></form><?php endif; ?></td></tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
</div>
