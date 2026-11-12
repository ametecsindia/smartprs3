<?php declare(strict_types=1);
// DEV ONLY (owner, 23 Oct): realistic sample attendance from 01-10-2026 up to TODAY for every active
// employee, so Live Salary / reports have the current days. Only days with NO punches are touched:
//   · weekly offs and holidays: nothing (they settle as W.OFF / Holiday)
//   · ~4% of working days: an approved paid leave (CL or SL with balance), through Leave::request + decide
//   · ~5% of working days: left absent
//   · the rest: an in + out punch around the employee's own shift (15% late, 8% early exit)
// ?go=1 applies; otherwise a dry run. Re-running adds nothing twice. Delete this file after.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
@set_time_limit(600);
$fbGo = ($_GET['go'] ?? '') === '1';
$fbFrom = '2026-10-01';
$fbTo = date('Y-m-d');
$fbTenant = (string) ($store->tenantId() ?? $tenantId);
$fbShifts = new SmartPRS\Surface\Shifts($store);
$fbBy = [];
foreach ($store->all('attendance_logs') as $r) {
    $d = substr((string) ($r['punch_at'] ?? $r['log_date'] ?? ''), 0, 10);
    $d >= $fbFrom && $fbBy[(string) ($r['emp_code'] ?? '')][$d] = true;
}
$fbLeave = [];
foreach ($leave->all() as $l) {
    if (in_array(strtolower((string) ($l['state'] ?? '')), ['rejected', 'cancelled', 'withdrawn'], true)) { continue; }
    for ($x = substr((string) $l['from'], 0, 10); $x !== '' && $x <= substr((string) $l['to'], 0, 10); $x = date('Y-m-d', strtotime($x . ' +1 day'))) { $fbLeave[(string) $l['employment_id']][$x] = true; }
}
$fbHol = [];
foreach ($calendar->holidays(2026) as $hd => $hn) { $fbHol[$hd] = true; }
$fbAdd = []; $fbLeaves = []; $fbAbsent = 0; $fbPeople = 0; $fbErr = [];
foreach (liveRows($store, 'employees') as $e) {
    $code = SmartPRS\Surface\Employment::code($e);
    if ($code === '' || str_starts_with($code, 'ADM') || strtolower(trim((string) ($e['status'] ?? 'active'))) !== 'active') { continue; }
    $fbPeople++;
    $start = max($fbFrom, substr((string) ($e['doj'] ?? ''), 0, 10) ?: $fbFrom);
    $types = [];
    try { $types = array_keys($policy->typesFor(leaveScopeContext($store, $e))); } catch (\Throwable) {}
    $lt = array_values(array_intersect(['CL', 'SL'], $types));
    for ($d = $start; $d <= $fbTo; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        if ($calendar->isWeeklyOff($d) || isset($fbHol[$d]) || isset($fbBy[$code][$d]) || isset($fbLeave[$code][$d])) { continue; }
        mt_srand(crc32('oct26' . $code . $d));
        $roll = mt_rand(1, 100);
        if ($roll <= 4 && $lt !== []) { $fbLeaves[] = [$code, $lt[mt_rand(0, count($lt) - 1)], $d]; continue; }
        if ($roll <= 9) { $fbAbsent++; continue; }
        [$s, $en] = $fbShifts->times($e, $calendar->shiftStart(), $calendar->shiftEnd(), $d);
        $in = strtotime("{$d} {$s}") + (mt_rand(1, 100) <= 15 ? mt_rand(11, 40) : mt_rand(-12, 8)) * 60 + mt_rand(0, 59);
        $ob = strtotime("{$d} {$en}"); if ($ob <= strtotime("{$d} {$s}")) { $ob += 86400; }
        $out = $ob + (mt_rand(1, 100) <= 8 ? -mt_rand(20, 70) : mt_rand(0, 45)) * 60 + mt_rand(0, 59);
        if ($d === date('Y-m-d') && $out > time()) { $out = null; }   // today's shift not over yet: in-punch only
        foreach (array_filter([[$in, 'in'], $out === null ? null : [$out, 'out']]) as [$ts, $dir]) {
            $fbAdd[] = [$fbTenant, ($e['employer_company_id'] ?? null) ?: null, $code, (string) ($e['name'] ?? ''), date('Y-m-d', $ts), date('Y-m-d H:i:s', $ts), $dir, 'sample-oct-fill'];
        }
    }
}
$fbLeaveDone = 0;
if ($fbGo) {
    $now = date('Y-m-d H:i:s');
    foreach (array_chunk($fbAdd, 400) as $chunk) {
        $sql = 'INSERT INTO attendance_logs (tenant_id, employer_company_id, emp_code, emp_name, log_date, punch_at, direction, source, created_at, updated_at) VALUES '
            . implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?,?)'));
        $vals = [];
        foreach ($chunk as $row) { array_push($vals, ...$row); $vals[] = $now; $vals[] = $now; }
        $pdo->prepare($sql)->execute($vals);
    }
    foreach ($fbLeaves as [$code, $type, $d]) {
        try {
            $rq = $leave->request($code, $type, $d, $d, 'Personal work (sample)');
            $leave->decide((string) $rq['id'], true, 'sample-oct-fill', 'Approved (sample data)');
            $fbLeaveDone++;
        } catch (\Throwable $x) { $fbErr[] = "{$code} {$d}: " . $x->getMessage(); }
    }
    $audit('attendance.sample_october', 'attendance_logs', 'oct-2026', null, ['punches' => count($fbAdd), 'leaves' => $fbLeaveDone], 'Sample attendance to ' . $fbTo);
}
?><pre id="fb"><?= e(sprintf("%s: %d employees, %s to %s. Punches %d (~%d present days), leaves %d%s, left absent %d.%s",
    $fbGo ? 'APPLIED' : 'DRY RUN', $fbPeople, $fbFrom, $fbTo, count($fbAdd), intdiv(count($fbAdd), 2), $fbGo ? $fbLeaveDone : count($fbLeaves), $fbGo ? ' of ' . count($fbLeaves) : '', $fbAbsent,
    $fbErr === [] ? '' : "\nLeave not filed (balance / rule): " . count($fbErr) . "\n" . implode("\n", array_slice($fbErr, 0, 10)))) ?></pre>
