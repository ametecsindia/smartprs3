<?php declare(strict_types=1);
// DEV ONLY (owner, 24 Oct): today (24-10-2026) is a weekly off and is skipped. This completes the sample
// attendance up to YESTERDAY: a day whose sample in-punch has no out-punch (the 23 Oct fill ran mid-shift)
// gets an out-punch at the end of the employee's own shift (8% early exit, else 0–45 min after).
// Touches only days whose punches came from the sample fill (source 'sample-oct-fill'). ?go=1 applies;
// otherwise a dry run. Re-running adds nothing twice. Delete this file after.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
@set_time_limit(300);
$foGo = ($_GET['go'] ?? '') === '1';
$foTo = date('Y-m-d', strtotime('-1 day'));
$foTenant = (string) ($store->tenantId() ?? $tenantId);
$foShifts = new SmartPRS\Surface\Shifts($store);
$foDays = [];
foreach ($store->all('attendance_logs') as $r) {
    $d = substr((string) ($r['log_date'] ?? $r['punch_at'] ?? ''), 0, 10);
    if ($d < '2026-10-01' || $d > $foTo) { continue; }
    $k = (string) ($r['emp_code'] ?? '') . '|' . $d;
    $foDays[$k]['dirs'][strtolower((string) ($r['direction'] ?? ''))] = true;
    $foDays[$k]['sample'] = ($foDays[$k]['sample'] ?? false) || (string) ($r['source'] ?? '') === 'sample-oct-fill';
}
$foEmp = [];
foreach (liveRows($store, 'employees') as $e) { $foEmp[SmartPRS\Surface\Employment::code($e)] = $e; }
$foAdd = [];
foreach ($foDays as $k => $x) {
    [$code, $d] = explode('|', $k, 2);
    if (!$x['sample'] || !isset($x['dirs']['in']) || isset($x['dirs']['out']) || !isset($foEmp[$code])) { continue; }
    $e = $foEmp[$code];
    mt_srand(crc32('oct26out' . $code . $d));
    [$s, $en] = $foShifts->times($e, $calendar->shiftStart(), $calendar->shiftEnd(), $d);
    $ob = strtotime("{$d} {$en}"); if ($ob <= strtotime("{$d} {$s}")) { $ob += 86400; }
    $out = $ob + (mt_rand(1, 100) <= 8 ? -mt_rand(20, 70) : mt_rand(0, 45)) * 60 + mt_rand(0, 59);
    $foAdd[] = [$foTenant, ($e['employer_company_id'] ?? null) ?: null, $code, (string) ($e['name'] ?? ''), $d, date('Y-m-d H:i:s', $out), 'out', 'sample-oct-fill'];
}
if ($foGo && $foAdd !== []) {
    $now = date('Y-m-d H:i:s');
    foreach (array_chunk($foAdd, 400) as $chunk) {
        $vals = [];
        foreach ($chunk as $row) { array_push($vals, ...$row); $vals[] = $now; $vals[] = $now; }
        $pdo->prepare('INSERT INTO attendance_logs (tenant_id, employer_company_id, emp_code, emp_name, log_date, punch_at, direction, source, created_at, updated_at) VALUES '
            . implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?,?)')))->execute($vals);
    }
    $audit('attendance.sample_outs', 'attendance_logs', 'oct-2026', null, ['punches' => count($foAdd)], 'Missing sample check-outs to ' . $foTo);
}
$foByDay = [];
foreach ($foAdd as $r) { $foByDay[$r[4]] = ($foByDay[$r[4]] ?? 0) + 1; }
ksort($foByDay);
?><pre id="fo"><?= e(($foGo ? 'APPLIED' : 'DRY RUN') . ': ' . count($foAdd) . " missing check-out(s) up to {$foTo}" . ($foByDay === [] ? '.' : ' — ' . implode(', ', array_map(static fn ($d, $n): string => SmartPRS\Surface\Dates::dmy($d) . ": {$n}", array_keys($foByDay), $foByDay)))) ?></pre>
