<?php declare(strict_types=1);
// DEV ONLY (owner, 30 Oct 2026: "Mark attendance in realistic way for all employees till today including leaves,
// half days, late logins, W.OFFs, etc."). From each active employee's joining date (not before 01-01-2026) up to
// TODAY, only days with NO punches and NO leave are touched — what is already on file is never changed:
//   · weekly offs and holidays: nothing (they settle as W.OFF / Holiday from Organization Configuration)
//   · ~3%  approved full-day leave (CL / SL / EL with balance), through Leave::request + decide (ledger moves)
//   · ~2%  approved HALF-day leave (second half) + morning punches up to the shift's half
//   · ~3%  absent (no punches)
//   · ~3%  half day worked (out after about half the shift)
//   · ~1.5% missing out-punch
//   · the rest: in + out around the employee's own shift — ~14% late (11–45 min), ~6% early exit
//   · chronic latecomers (about 1 in 12 people) are late on ~35% of days
//   · today: an in-punch only while the shift is still running
// ?go=1 applies; otherwise a dry run (per-month counts). Re-running adds nothing twice. Delete with the other dev-* screens.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
@set_time_limit(900);
$faGo = ($_GET['go'] ?? '') === '1';
// FROM THE DAY AFTER THE LAST FILL (23 Oct): earlier gaps are the sample's own absences (and Jul–Sep payroll is generated),
// so they are left as they are. ?from=YYYY-MM-DD widens it.
$faFloor = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) === 1 ? (string) $_GET['from'] : '2026-10-24';
$faTo = date('Y-m-d');
$faTenant = (string) ($store->tenantId() ?? $tenantId);
$faShifts = new SmartPRS\Surface\Shifts($store);
$faBy = [];
foreach ($store->all('attendance_logs') as $r) {
    $d = substr((string) ($r['punch_at'] ?? $r['log_date'] ?? ''), 0, 10);
    $d >= $faFloor && $faBy[(string) ($r['emp_code'] ?? '')][$d] = true;
}
$faLeave = [];
foreach ($leave->all() as $l) {
    if (in_array(strtolower((string) ($l['state'] ?? '')), ['rejected', 'cancelled', 'withdrawn'], true)) { continue; }
    for ($x = substr((string) $l['from'], 0, 10); $x !== '' && $x <= substr((string) $l['to'], 0, 10); $x = date('Y-m-d', strtotime($x . ' +1 day'))) { $faLeave[(string) $l['employment_id']][$x] = true; }
}
$faHol = [];
foreach ([2026, 2027] as $y) { foreach ($calendar->holidays($y) as $hd => $hn) { $faHol[$hd] = true; } }
$faAdd = []; $faLeaves = []; $faMonth = []; $faPeople = 0; $faErr = [];
$faCount = static function (array &$m, string $d, string $k): void { $m[substr($d, 0, 7)][$k] = ($m[substr($d, 0, 7)][$k] ?? 0) + 1; };
$faPunch = static function (array $e, string $code, int $ts, string $dir) use ($faTenant): array {
    return [$faTenant, ($e['employer_company_id'] ?? null) ?: null, $code, (string) ($e['name'] ?? ''), date('Y-m-d', $ts), date('Y-m-d H:i:s', $ts), $dir, 'sample-fill-30oct'];
};
foreach (liveRows($store, 'employees') as $e) {
    $code = SmartPRS\Surface\Employment::code($e);
    if ($code === '' || str_starts_with($code, 'ADM') || strtolower(trim((string) ($e['status'] ?? 'active'))) !== 'active') { continue; }
    $faPeople++;
    $start = max($faFloor, substr((string) ($e['doj'] ?? ''), 0, 10) ?: $faFloor);
    // TODAY IN THE COMPANY'S OWN TIME ZONE (Organization Configuration → Companies): punches are wall-clock times there.
    $tz = trim((string) ($store->find('companies', (string) ($e['employer_company_id'] ?? ''))['timezone'] ?? '')) ?: date_default_timezone_get();
    try { $local = new DateTimeImmutable('now', new DateTimeZone($tz)); } catch (\Throwable) { $local = new DateTimeImmutable('now'); }
    $nowTs = strtotime($local->format('Y-m-d H:i:s'));
    $today = $local->format('Y-m-d');
    $exit = substr(trim((string) ($e['exit_date'] ?? '')), 0, 10);
    $end = $exit !== '' && $exit < $today ? $exit : $today;
    $types = [];
    try { $types = array_keys($policy->typesFor(leaveScopeContext($store, $e))); } catch (\Throwable) { $types = []; }
    $lt = array_values(array_intersect(['CL', 'SL', 'EL'], $types));
    $chronic = crc32('late' . $code) % 12 === 0;
    for ($d = $start; $d <= $end; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        if ($calendar->isWeeklyOff($d) || isset($faHol[$d]) || isset($faBy[$code][$d]) || isset($faLeave[$code][$d])) { continue; }
        mt_srand(crc32('fill30' . $code . $d));
        $roll = mt_rand(1, 1000);
        [$s, $en] = $faShifts->times($e, $calendar->shiftStart(), $calendar->shiftEnd(), $d);
        $sTs = strtotime("{$d} {$s}");
        $eTs = strtotime("{$d} {$en}"); if ($eTs <= $sTs) { $eTs += 86400; }
        $halfTs = $sTs + intdiv($eTs - $sTs, 2);
        if ($d === $today && $sTs + 15 * 60 > $nowTs) { continue; }   // today's shift has not started yet
        if ($roll <= 30 && $lt !== []) { $faLeaves[] = [$code, $lt[mt_rand(0, count($lt) - 1)], $d, null]; $faCount($faMonth, $d, 'leave'); continue; }
        if ($roll <= 50 && $lt !== [] && $d < $today) {   // half-day leave (second half) + the morning worked
            $faLeaves[] = [$code, $lt[mt_rand(0, count($lt) - 1)], $d, 'second'];
            $faAdd[] = $faPunch($e, $code, $sTs + mt_rand(-10, 8) * 60 + mt_rand(0, 59), 'in');
            $faAdd[] = $faPunch($e, $code, $halfTs + mt_rand(0, 20) * 60 + mt_rand(0, 59), 'out');
            $faCount($faMonth, $d, 'half_leave'); continue;
        }
        if ($roll <= 80) { $faCount($faMonth, $d, 'absent'); continue; }
        $late = mt_rand(1, 100) <= ($chronic ? 35 : 14);
        $in = $sTs + ($late ? mt_rand(11, 45) : mt_rand(-12, 8)) * 60 + mt_rand(0, 59);
        if ($roll <= 110 && $d < $today) {                // half day worked
            $out = $halfTs + mt_rand(0, 30) * 60 + mt_rand(0, 59);
            $faCount($faMonth, $d, 'half_day');
        } elseif ($roll <= 125 && $d < $today) {          // forgot to punch out
            $out = null;
            $faCount($faMonth, $d, 'no_out');
        } else {
            $out = $eTs + (mt_rand(1, 100) <= 6 ? -mt_rand(20, 70) : mt_rand(0, 45)) * 60 + mt_rand(0, 59);
            $faCount($faMonth, $d, 'present');
        }
        $late && $faCount($faMonth, $d, 'late');
        if ($d === $today && $in > $nowTs) { continue; }
        if ($d === $today && $out !== null && $out > $nowTs) { $out = null; }   // today's shift not over yet
        $faAdd[] = $faPunch($e, $code, $in, 'in');
        $out === null || $faAdd[] = $faPunch($e, $code, $out, 'out');
    }
}
$faLeaveDone = 0;
if ($faGo) {
    $now = date('Y-m-d H:i:s');
    foreach (array_chunk($faAdd, 400) as $chunk) {
        $sql = 'INSERT INTO attendance_logs (tenant_id, employer_company_id, emp_code, emp_name, log_date, punch_at, direction, source, created_at, updated_at) VALUES '
            . implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?,?)'));
        $vals = [];
        foreach ($chunk as $row) { array_push($vals, ...$row); $vals[] = $now; $vals[] = $now; }
        $pdo->prepare($sql)->execute($vals);
    }
    foreach ($faLeaves as [$code, $type, $d, $half]) {
        try {
            $rq = $leave->request($code, $type, $d, $d, $half === null ? 'Personal work (sample)' : 'Personal work — afternoon (sample)', $half);
            $leave->decide((string) $rq['id'], true, 'sample-fill-30oct', 'Approved (sample data)');
            $faLeaveDone++;
        } catch (\Throwable $x) { $faErr[] = "{$code} {$d} {$type}" . ($half ? ' (half)' : '') . ': ' . $x->getMessage(); }
    }
    $audit('attendance.sample_fill', 'attendance_logs', 'to-' . $faTo, null, ['punches' => count($faAdd), 'leaves' => $faLeaveDone], 'Sample attendance up to ' . $faTo);
}
ksort($faMonth);
$faLines = [];
foreach ($faMonth as $m => $c) {
    $faLines[] = sprintf('%s  present %4d · late %4d · half day %3d · no out-punch %3d · leave %3d · half-day leave %3d · absent %3d',
        $m, $c['present'] ?? 0, $c['late'] ?? 0, $c['half_day'] ?? 0, $c['no_out'] ?? 0, $c['leave'] ?? 0, $c['half_leave'] ?? 0, $c['absent'] ?? 0);
}
?><pre id="fa"><?= e(sprintf("%s: %d active employees, up to %s. Punches %d, leaves %d%s.\n%s%s",
    $faGo ? 'APPLIED' : 'DRY RUN (nothing saved)', $faPeople, $faTo, count($faAdd), $faGo ? $faLeaveDone : count($faLeaves), $faGo ? ' of ' . count($faLeaves) : '',
    implode("\n", $faLines) ?: 'No empty working days — attendance is already complete.',
    $faErr === [] ? '' : "\nLeave not filed (balance / rule): " . count($faErr) . "\n" . implode("\n", array_slice($faErr, 0, 15)))) ?></pre>
