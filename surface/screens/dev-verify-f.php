<?php declare(strict_types=1);
// DEV ONLY (12 Oct): check the seeded test data the way payroll will read it. Delete after.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
set_time_limit(0);
$vfShifts = new SmartPRS\Surface\Shifts($store);
$vfRows = [];
$vfBad = [];
// Punches per employee-day: more than 4 is a duplicate run; an odd count on a past day needs regularisation.
$vfPerDay = [];
foreach ($store->all('attendance_logs') as $l) {
    $d = substr((string) $l['punch_at'], 0, 10);
    $d >= '2026-02-01' && $vfPerDay[(string) $l['emp_code'] . '|' . $d] = ($vfPerDay[(string) $l['emp_code'] . '|' . $d] ?? 0) + 1;
}
foreach ($vfPerDay as $k => $n) { ($n > 4 || ($n % 2 === 1 && !str_ends_with($k, date('Y-m-d')))) && $vfBad[] = "{$k}: {$n} punch(es)"; }
$vfMonths = (array) ($_GET['m'] ?? ['2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09']);
foreach (liveRows($store, 'employees') as $e) {
    if (strcasecmp((string) ($e['status'] ?? ''), 'active') !== 0 || (string) ($e['branch_id'] ?? '') === '') { continue; }
    $c = SmartPRS\Surface\Employment::code($e);
    foreach ($vfMonths as $m) {
        $to = date('Y-m-t', strtotime($m . '-01'));
        if ((string) $e['doj'] > $to) { continue; }
        [$s, $en] = $vfShifts->times($e, $calendar->shiftStart(), $calendar->shiftEnd());
        $p = $period->forPeriod($c, $m . '-01', $to, $s, $en);
        $row = [$c, $m, $p['payable']->toDecimalString(), (int) $p['period_days'], (string) ($p['worked_days'] ?? $p['worked']), (int) $p['paid_leave'], (int) $p['unpaid_leave'],
            (int) ($p['absent'] ?? 0), (int) $p['needing_attention'], count($p['overlaps']), (int) ($p['holidays'] ?? 0), (string) ($p['off_days'] ?? '')];
        $vfRows[] = implode("\t", $row);
        ($p['needing_attention'] > 0 || $p['overlaps'] !== []) && $vfBad[] = "{$c} {$m}: attention {$p['needing_attention']}, overlaps " . count($p['overlaps']);
    }
}
file_put_contents(__DIR__ . '/../../storage/dev-verify.tsv', "code\tmonth\tpayable\tperiod_days\tworked\tpaid_leave\tunpaid_leave\tabsent\tattention\toverlaps\tholidays\toff\n" . implode("\n", $vfRows) . "\n\nPROBLEMS\n" . implode("\n", $vfBad));
?><pre id="vf"><?= e(count($vfRows) . ' row(s), ' . count($vfBad) . ' problem(s)') ?></pre>
