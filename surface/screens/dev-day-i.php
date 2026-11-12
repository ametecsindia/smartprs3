<?php declare(strict_types=1);
// DEV ONLY (12 Oct): one employee's settled days with punches. Delete after.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
$diC = (string) ($_GET['c'] ?? ''); $diF = (string) ($_GET['f'] ?? date('Y-m-01')); $diT = (string) ($_GET['t'] ?? date('Y-m-d'));
$diE = null; foreach ($store->all('employees') as $x) { SmartPRS\Surface\Employment::code($x) === $diC && $diE = $x; }
[$diS, $diEn] = (new SmartPRS\Surface\Shifts($store))->times((array) $diE, $calendar->shiftStart(), $calendar->shiftEnd());
$diOut = ["shift {$diS}-{$diEn}"];
foreach ($attendance->settledDays($diC, $diS, $diEn, $diF, $diT) as $d) {
    $diOut[] = $d['date'] . ' ' . $d['classification']['status'] . ' worked=' . $d['settlement']->workedMinutes . ' ' . substr((string) $d['classification']['why'], 0, 90);
}
foreach ($attendance->punchesFor($diC) as $p) { (string) $p['at'] >= $diF && $diOut[] = 'punch ' . $p['at'] . ' ' . $p['direction']; }
?><pre id="di"><?= e(implode("\n", $diOut)) ?></pre>
