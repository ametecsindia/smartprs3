<?php declare(strict_types=1);
// DEV ONLY (owner, 13 Oct): re-shape the SAMPLE punches to the new W.OFF schedule.
//   · a date that is now a weekly off  -> its punches are removed (backed up to storage/dev-woff-backup-*.json)
//   · a working day an employee has no punch and no leave on -> realistic in/out punches are added when the
//     day used to be a weekend (Sat/Sun) or NOBODY punched that day (the whole company can't be absent);
//     a deliberate weekday absence in the sample is left alone.
// ?go=1 applies; without it this is a dry run. Re-running adds nothing twice. Delete this file after.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
@set_time_limit(300);
$wpGo = ($_GET['go'] ?? '') === '1';
$wpFrom = '2026-01-01';
$wpTo = date('Y-m-d', strtotime('-1 day'));
$wpTenant = (string) ($store->tenantId() ?? $tenantId);
$wpShifts = new SmartPRS\Surface\Shifts($store);

// Punches by code and date.
$wpByDay = [];      // code => date => [rows]
$wpAnyOn = [];      // date => true when anybody punched
foreach ($store->all('attendance_logs') as $r) {
    $d = substr((string) ($r['punch_at'] ?? $r['log_date'] ?? ''), 0, 10);
    $c = (string) ($r['emp_code'] ?? '');
    if ($d === '' || $c === '') { continue; }
    $wpByDay[$c][$d][] = $r;
    $wpAnyOn[$d] = true;
}

// Leave days (any state but rejected/cancelled) by code.
$wpLeave = [];
foreach ($leave->all() as $l) {
    if (in_array(strtolower((string) ($l['state'] ?? '')), ['rejected', 'cancelled', 'withdrawn'], true)) { continue; }
    $f = substr((string) $l['from'], 0, 10); $t = substr((string) $l['to'], 0, 10);
    for ($x = $f; $x !== '' && $x <= $t; $x = date('Y-m-d', strtotime($x . ' +1 day'))) { $wpLeave[(string) $l['employment_id']][$x] = true; }
}

$wpHolidays = [];
foreach ([2026] as $y) { foreach ($calendar->holidays($y) as $hd => $hn) { $wpHolidays[$hd] = true; } }

// LOW-COVERAGE DAYS: a working day on which more than 15 people have no complete in+out pair is a
// hole in the sample, not a real day — those people get filled (missing out-punches too).
$wpLowDay = [];
for ($d = $wpFrom; $d <= $wpTo; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
    if ($calendar->isWeeklyOff($d) || isset($wpHolidays[$d])) { continue; }
    $miss = 0;
    foreach (liveRows($store, 'employees') as $e) {
        if (substr((string) ($e['doj'] ?? ''), 0, 10) > $d) { continue; }
        $dirs = array_map(static fn ($r): string => (string) $r['direction'], $wpByDay[SmartPRS\Surface\Employment::code($e)][$d] ?? []);
        (!in_array('in', $dirs, true) || !in_array('out', $dirs, true)) && $miss++;
    }
    $miss > 15 && $wpLowDay[$d] = true;
}
$wpRemove = []; $wpAdd = []; $wpPeople = 0;
foreach (liveRows($store, 'employees') as $e) {
    $code = SmartPRS\Surface\Employment::code($e);
    if ($code === '') { continue; }
    $wpPeople++;
    $start = max($wpFrom, substr((string) ($e['doj'] ?? ''), 0, 10) ?: $wpFrom);
    $exit = substr(trim((string) ($e['exit_date'] ?? '')), 0, 10);
    $end = $exit !== '' ? min($wpTo, $exit) : $wpTo;

    for ($d = $wpFrom; $d <= $wpTo; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $has = $wpByDay[$code][$d] ?? [];
        if ($calendar->isWeeklyOff($d)) {
            foreach ($has as $r) { $wpRemove[] = $r; }
            continue;
        }
        if ($d < $start || $d > $end || isset($wpHolidays[$d]) || isset($wpLeave[$code][$d])) { continue; }
        $dirs = array_map(static fn ($r): string => (string) $r['direction'], $has);
        $onlyIn = $has !== [] && in_array('in', $dirs, true) && !in_array('out', $dirs, true);
        if ($has !== [] && !($onlyIn && isset($wpLowDay[$d]))) { continue; }
        $dow = (int) date('N', strtotime($d));
        if ($has === [] && $dow < 6 && isset($wpAnyOn[$d]) && !isset($wpLowDay[$d])) { continue; }   // a real weekday absence: keep it
        mt_srand(crc32($code . $d));
        if (!$onlyIn && mt_rand(1, 100) <= 6) { continue; }   // ~6% stay absent, as people do
        [$s, $en] = $wpShifts->times($e, $calendar->shiftStart(), $calendar->shiftEnd(), $d);
        $late = mt_rand(1, 100) <= 15;
        $in = strtotime("{$d} {$s}") + ($late ? mt_rand(11, 40) : mt_rand(-12, 8)) * 60 + mt_rand(0, 59);
        $outBase = strtotime("{$d} {$en}"); if ($outBase <= strtotime("{$d} {$s}")) { $outBase += 86400; }
        $out = $outBase + (mt_rand(1, 100) <= 8 ? -mt_rand(20, 70) : mt_rand(0, 45)) * 60 + mt_rand(0, 59);
        foreach ($onlyIn ? [[$out, 'out']] : [[$in, 'in'], [$out, 'out']] as [$ts, $dir]) {
            $wpAdd[] = [$wpTenant, ($e['employer_company_id'] ?? null) ?: null, $code, (string) ($e['name'] ?? ''), date('Y-m-d', $ts), date('Y-m-d H:i:s', $ts), $dir, 'sample-woff-fill'];
        }
    }
}

// ?stats=1: working dates where few people have a complete in+out pair.
$wpStats = [];
if (($_GET['stats'] ?? '') === '1') {
    for ($d = $wpFrom; $d <= $wpTo; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        if ($calendar->isWeeklyOff($d) || isset($wpHolidays[$d])) { continue; }
        $pairs = 0; $inOnly = 0; $none = 0;
        foreach (liveRows($store, 'employees') as $e) {
            $c = SmartPRS\Surface\Employment::code($e); $rows = $wpByDay[$c][$d] ?? [];
            if (substr((string) ($e['doj'] ?? ''), 0, 10) > $d) { continue; }
            $dirs = array_map(static fn ($r): string => (string) $r['direction'], $rows);
            if ($rows === []) { $none++; } elseif (in_array('out', $dirs, true) && in_array('in', $dirs, true)) { $pairs++; } else { $inOnly++; }
        }
        ($inOnly + $none) > 15 && $wpStats[] = "{$d}: pairs {$pairs}, one-punch {$inOnly}, none {$none}";
    }
}
$wpMsg = implode("\n", $wpStats) . "\n" . sprintf('%s: %d employees, %s to %s. Remove %d punch(es) on new weekly offs; add %d punch(es) (%d day(s)).',
    $wpGo ? 'APPLIED' : 'DRY RUN', $wpPeople, $wpFrom, $wpTo, count($wpRemove), count($wpAdd), intdiv(count($wpAdd), 2));

if ($wpGo) {
    if ($wpRemove !== []) {
        file_put_contents(__DIR__ . '/../../storage/dev-woff-backup-' . date('Ymd-His') . '.json', json_encode($wpRemove, JSON_PRETTY_PRINT));
        foreach (array_chunk(array_map(static fn ($r): int => (int) $r['id'], $wpRemove), 500) as $ids) {
            $pdo->prepare('DELETE FROM attendance_logs WHERE tenant_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')
                ->execute(array_merge([$wpTenant], $ids));
        }
    }
    $now = date('Y-m-d H:i:s');
    foreach (array_chunk($wpAdd, 400) as $chunk) {
        $sql = 'INSERT INTO attendance_logs (tenant_id, employer_company_id, emp_code, emp_name, log_date, punch_at, direction, source, created_at, updated_at) VALUES '
            . implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?,?)'));
        $vals = [];
        foreach ($chunk as $row) { array_push($vals, ...$row); $vals[] = $now; $vals[] = $now; }
        $pdo->prepare($sql)->execute($vals);
    }
    $audit('attendance.sample_reshaped', 'attendance_logs', 'woff', null, ['removed' => count($wpRemove), 'added' => count($wpAdd)], 'Sample punches re-shaped to the new W.OFF schedule');
}
?><pre id="wp"><?= e($wpMsg) ?></pre>
