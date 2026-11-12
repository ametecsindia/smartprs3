<?php
/**
 * MY ATTENDANCE (owner, 12 Nov 2026: "the employee can see their attendance logs and reports of themselves, including
 * all punches in and out timings"). The signed-in person's own month — status, first in, last out, worked, late — and
 * every punch of each day with where it came from (QR gate, SmartEPT, web / app, device, manual, regularised).
 * Read-only, own record only (session employee_id); the same engine as the Attendance Report and payroll.
 * Also a tab in the SmartPRS3 app's bottom bar.
 */
declare(strict_types=1);

use SmartPRS\Surface\Dates;
use SmartPRS\Surface\Employment;

$maMe = trim((string) ($session->user()['employee_id'] ?? '')) === '' ? null : $store->find('employees', (string) $session->user()['employee_id']);
?>
<div class="page-header"><div><h2>My Attendance</h2></div></div>
<?php if ($maMe === null): ?>
<div class="card note">This login has no employee record, so there is no attendance of your own to show.</div>
<?php return; endif;

$maMonth = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['m'] ?? '')) === 1 ? (string) $_GET['m'] : date('Y-m');
$maFrom = $maMonth . '-01';
$maTo = date('Y-m-t', (int) strtotime($maFrom));
$maToday = date('Y-m-d');
$maCode = Employment::code($maMe);
$maRow = $attendanceReport->muster([$maMe], $maFrom, $maTo, $calendar->shiftStart(), $calendar->shiftEnd(), $maToday)['rows'][0] ?? null;
$maCells = (array) ($maRow['cells'] ?? []);
// Every punch of the month, by day (held ones on an off day included, marked).
$maPunches = [];
foreach ($attendance->punchesFor($maCode) as $p) {
    $d = substr((string) $p['at'], 0, 10);
    $d >= $maFrom && $d <= $maTo && $maPunches[$d][] = $p;
}
$maLabel = ['PRESENT' => ['Present', 'green'], 'ABSENT' => ['Absent', 'red'], 'HALF_DAY' => ['Half day', 'amber'], 'WEEKLY_OFF' => ['W.OFF', 'gray'],
    'HOLIDAY' => ['Holiday', 'blue'], 'ON_LEAVE' => ['Leave', 'blue'], 'UNPAID_LEAVE' => ['Unpaid leave', 'amber'], 'NOT_YET' => ['—', 'gray'], 'NOT_JOINED' => ['—', 'gray']];
$maSrc = ['qr' => 'QR gate', 'smartept' => 'SmartEPT', 'web' => 'App / web', 'manual' => 'Manual', 'regularised' => 'Regularised', 'bulk' => 'Upload',
    'biometric' => 'Device', 'iclock' => 'Device', 'sbb' => 'Device', 'fieldforce' => 'Field app'];
// Durations app-wide: under an hour in minutes, else hours and minutes (owner rule).
$maDur = static fn (int $m): string => $m <= 0 ? '—' : ($m < 60 ? $m . 'm' : intdiv($m, 60) . 'h ' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT) . 'm');
$maCount = [];
$maWorked = 0;
$maLate = 0;
foreach ($maCells as $c) {
    $st = (string) ($c['status'] ?? '');
    $maCount[$st] = ($maCount[$st] ?? 0) + 1;
    $maWorked += (int) ($c['worked_minutes'] ?? 0);
    (int) ($c['late_minutes'] ?? 0) > 0 && $maLate++;
}
?>
<style>
  .ma-sum{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:8px;margin-bottom:12px}
  .ma-sum div{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:10px 12px}
  .ma-sum b{display:block;font-size:20px;color:#1F3A5F}
  .ma-sum small{color:var(--text2);font-size:12px}
  .ma-day{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:10px 12px;margin-bottom:8px}
  .ma-top{display:flex;justify-content:space-between;align-items:center;gap:8px}
  .ma-top b{color:#1F3A5F}
  .ma-row{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:6px;margin-top:6px;font-size:12.5px}
  .ma-row span{color:var(--text2);display:block;font-size:11px}
  .ma-p{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
  .ma-p i{font-style:normal;font-size:11.5px;padding:3px 8px;border-radius:8px;background:color-mix(in srgb,#1F3A5F 7%,transparent)}
  .ma-p i.in{border-left:3px solid #16a34a}.ma-p i.out{border-left:3px solid #2563eb}.ma-p i.held{opacity:.6}
</style>
<div class="card">
  <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin:0"><input type="hidden" name="r" value="my-attendance">
    <div><label for="ma-m">Month</label><input type="month" id="ma-m" name="m" value="<?= e($maMonth) ?>"></div>
    <button class="go" type="submit">Show</button>
    <a class="btn btn-outline" href="?r=attendance-corrections"><i class="fas fa-pen-to-square"></i> Request a correction</a>
  </form>
</div>
<div class="ma-sum">
  <div><b><?= (int) ($maCount['PRESENT'] ?? 0) ?></b><small>Present</small></div>
  <div><b><?= (int) ($maCount['HALF_DAY'] ?? 0) ?></b><small>Half day</small></div>
  <div><b><?= (int) ($maCount['ABSENT'] ?? 0) ?></b><small>Absent</small></div>
  <div><b><?= (int) ($maCount['ON_LEAVE'] ?? 0) + (int) ($maCount['UNPAID_LEAVE'] ?? 0) ?></b><small>Leave</small></div>
  <div><b><?= (int) ($maCount['WEEKLY_OFF'] ?? 0) + (int) ($maCount['HOLIDAY'] ?? 0) ?></b><small>W.OFF / Holiday</small></div>
  <div><b><?= $maLate ?></b><small>Late logins</small></div>
  <div><b><?= e($maDur($maWorked)) ?></b><small>Worked</small></div>
</div>
<?php foreach (array_reverse($maCells, true) as $d => $c):
    if ((string) ($c['status'] ?? '') === 'NOT_YET' && ($maPunches[$d] ?? []) === []) { continue; }
    $lb = $maLabel[(string) ($c['status'] ?? '')] ?? [ucwords(strtolower(str_replace('_', ' ', (string) ($c['status'] ?? '')))), 'gray']; ?>
<div class="ma-day">
  <div class="ma-top"><b><?= e(Dates::dmy((string) $d)) ?> · <?= e(date('D', (int) strtotime((string) $d))) ?></b><span class="badge badge-<?= e($lb[1]) ?>"><?= e($lb[0]) ?></span></div>
  <div class="ma-row">
    <div><span>First in</span><?= e((string) ($c['first_in'] ?? '') ?: '—') ?></div>
    <div><span>Last out</span><?= e((string) ($c['last_out'] ?? '') ?: '—') ?></div>
    <div><span>Worked</span><?= e($maDur((int) ($c['worked_minutes'] ?? 0))) ?></div>
    <div><span>Late</span><?= e($maDur((int) ($c['late_minutes'] ?? 0))) ?></div>
  </div>
<?php if (($maPunches[$d] ?? []) !== []): ?>
  <div class="ma-p">
<?php   foreach ($maPunches[$d] as $p): $dir = strtolower((string) $p['direction']); ?>
    <i class="<?= $dir === 'in' ? 'in' : 'out' ?>"><?= e(strtoupper($dir)) ?> <?= e(substr((string) $p['at'], 11, 5)) ?> · <?= e($maSrc[(string) $p['source']] ?? ucfirst((string) $p['source'])) ?></i>
<?php   endforeach; ?>
  </div>
<?php endif; ?>
</div>
<?php endforeach; ?>
<?php if ($maCells === []): ?><div class="card note">Nothing for this month.</div><?php endif; ?>
