<?php declare(strict_types=1);
// DEV ONLY (12 Oct): list payroll overlaps (worked AND on approved leave) for the seeded people. Delete after.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
set_time_limit(0);
$okPlan = json_decode((string) file_get_contents(__DIR__ . '/../../storage/dev-seed-plan.json'), true);
$okSh = new SmartPRS\Surface\Shifts($store);
$out = [];
foreach ($store->all('employees') as $e) {
    $c = SmartPRS\Surface\Employment::code($e);
    if (!isset($okPlan['windows'][$c])) { continue; }
    [$s, $en] = $okSh->times($e, $calendar->shiftStart(), $calendar->shiftEnd());
    for ($m = substr((string) $okPlan['windows'][$c], 0, 7); $m <= date('Y-m'); $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
        $p = $period->forPeriod($c, $m . '-01', date('Y-m-t', strtotime($m . '-01')), $s, $en);
        foreach ($p['overlaps'] as $o) { $out[] = $c . ' ' . json_encode($o); }
    }
}
?><pre id="ok"><?= e(count($out) . "\n" . implode("\n", $out)) ?></pre>
