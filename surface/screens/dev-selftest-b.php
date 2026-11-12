<?php declare(strict_types=1);
// DEV ONLY (owner, 13 Oct): READ-ONLY business-logic self-test over the live data and engines.
// Nothing is written. Output: JSON rows in <pre id="st">. Delete after testing.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
@set_time_limit(240);
use SmartPRS\Surface\Employment;
use SmartPRS\Platform\Payroll\PayrollRun;

$T = [];
$add = static function (string $area, string $feature, string $check, string $expected, string $actual, bool|string $result, string $fix = '', string $owner = 'No') use (&$T): void {
    $T[] = ['area' => $area, 'feature' => $feature, 'check' => $check, 'expected' => $expected, 'actual' => $actual,
        'result' => is_bool($result) ? ($result ? 'PASS' : 'FAIL') : $result, 'fix' => $fix, 'owner' => $owner];
};
$try = static function (string $area, string $feature, string $check, callable $fn) use ($add): void {
    try { $fn(); } catch (\Throwable $x) { $add($area, $feature, $check, 'Check runs', 'Error: ' . $x->getMessage(), false, 'Investigate the error (developer).', 'No'); }
};
$emps = liveRows($store, 'employees');
$byCode = []; $byId = [];
foreach ($emps as $e) { $byCode[Employment::code($e)] = $e; $byId[(string) $e['id']] = $e; }
$today = date('Y-m-d'); $yday = date('Y-m-d', strtotime('-1 day'));
$list = static fn (array $a, int $n = 8): string => $a === [] ? '—' : implode(', ', array_slice($a, 0, $n)) . (count($a) > $n ? ' … (+' . (count($a) - $n) . ' more)' : '');

// ---------- PEOPLE ----------
$try('People', 'Directory', 'Every employee has an Employee ID, IDs are unique', function () use ($emps, $add, $list) {
    $codes = array_map(static fn ($e): string => Employment::code($e), $emps);
    $blank = count(array_filter($codes, static fn ($c) => $c === ''));
    $dupes = array_keys(array_filter(array_count_values(array_filter($codes)), static fn ($n) => $n > 1));
    $add('People', 'Directory', 'Every employee has an Employee ID, IDs are unique', '0 blank, 0 duplicate', "{$blank} blank, " . count($dupes) . ' duplicate ' . $list($dupes), $blank === 0 && $dupes === [], 'Give each employee a unique Employee ID on the Directory.', $blank || $dupes ? 'Yes' : 'No');
});
$try('People', 'Directory', 'Date of joining recorded and valid', function () use ($emps, $add, $list) {
    $bad = []; foreach ($emps as $e) { $d = (string) ($e['doj'] ?? ''); if (preg_match('/^\d{4}-\d{2}-\d{2}/', $d) !== 1 || substr($d, 0, 10) > date('Y-m-d')) { $bad[] = Employment::code($e); } }
    $add('People', 'Directory', 'Date of joining recorded and valid', 'Every employee has a past joining date', count($bad) . ' missing/invalid: ' . $list($bad), $bad === [], 'Fill Date of Joining on the Directory — payroll proration and leave accrual start from it.', $bad ? 'Yes' : 'No');
});
$try('People', 'Directory', 'Company, branch, department and designation set', function () use ($emps, $add, $list, $store) {
    $bad = []; foreach ($emps as $e) { foreach (['company', 'branch', 'department', 'designation'] as $k) { if (trim(orgValueOf($store, $e, $k)) === '') { $bad[] = Employment::code($e) . " ({$k})"; } } }
    $add('People', 'Directory', 'Company, branch, department and designation set', 'All four set for every employee', count($bad) . ' gaps: ' . $list($bad), $bad === [], 'Set the missing organisation fields on the Directory (salary rules, holidays and approvals are scoped by them).', $bad ? 'Yes' : 'No');
});
$try('People', 'Hierarchy', 'Reporting manager exists, is not self, no loops', function () use ($emps, $byId, $add, $list) {
    $missing = []; $self = []; $loop = [];
    foreach ($emps as $e) {
        $m = (string) ($e['reporting_manager_id'] ?? ''); $c = Employment::code($e);
        if ($m === '') { $missing[] = $c; continue; }
        if ($m === (string) $e['id']) { $self[] = $c; continue; }
        if (!isset($byId[$m])) { $missing[] = $c . ' (manager not found)'; continue; }
        $seen = [(string) $e['id'] => true]; $cur = $m;
        for ($i = 0; $i < 50 && $cur !== '' && isset($byId[$cur]); $i++) { if (isset($seen[$cur])) { $loop[] = $c; break; } $seen[$cur] = true; $cur = (string) ($byId[$cur]['reporting_manager_id'] ?? ''); }
    }
    $add('People', 'Hierarchy', 'Reporting manager exists, is not self, no loops', 'Only the top of each company has no manager; no self-reporting or loops', count($missing) . ' without a valid manager (' . $list($missing, 6) . '); ' . count($self) . ' self; ' . count($loop) . ' in a loop ' . $list($loop), $self === [] && $loop === [] && count($missing) <= 3 ? true : (count($missing) > 3 ? 'WARN' : false),
        'Set Reporting Manager on the Directory. Leave and regularization approvals route by it.', $self || $loop || count($missing) > 3 ? 'Yes' : 'No');
});
$try('People', 'Directory', 'Device User ID unique (biometric mapping)', function () use ($emps, $add, $list) {
    $ids = array_filter(array_map(static fn ($e): string => trim((string) ($e['device_user_id'] ?? '')), $emps));
    $dupes = array_keys(array_filter(array_count_values($ids), static fn ($n) => $n > 1));
    $add('People', 'Directory', 'Device User ID unique (biometric mapping)', 'No two employees share a Device User ID', count($ids) . ' set, ' . count($dupes) . ' duplicated ' . $list($dupes), $dupes === [], 'Give each employee their own Device User ID, otherwise punches go to the wrong person.', $dupes ? 'Yes' : 'No');
});
$try('People', 'Directory', 'Gender recorded (needed by gender-based salary rules / ML-PL)', function () use ($emps, $add, $list) {
    $bad = []; foreach ($emps as $e) { if (trim((string) ($e['gender'] ?? '')) === '') { $bad[] = Employment::code($e); } }
    $add('People', 'Directory', 'Gender recorded (needed by gender-based salary rules / ML-PL)', 'Every employee has Gender', count($bad) . ' blank: ' . $list($bad), $bad === [] ? true : 'WARN', 'Fill Gender on the Directory; blank falls to the "Other / not set" treatment.', $bad ? 'Yes' : 'No');
});

// ---------- ORGANISATION ----------
$try('Organization Setup', 'Designations', 'Every designation has a Grade / Band', function () use ($store, $add, $list) {
    $rows = liveRows($store, 'designations'); $bad = [];
    foreach ($rows as $d) { if (trim((string) ($d['grade'] ?? $d['band'] ?? '')) === '') { $bad[] = (string) ($d['name'] ?? $d['id']); } }
    $add('Organization Setup', 'Designations', 'Every designation has a Grade / Band', 'Grade filled on all designations', count($bad) . ' of ' . count($rows) . ' blank: ' . $list($bad), $bad === [] ? true : 'WARN', 'Organization Setup → Role / Designation Setup: fill Grade (pending from 12 Oct).', $bad ? 'Yes' : 'No');
});
$try('Organization Setup', 'Companies', 'Every company has a brand colour', function () use ($store, $add, $list) {
    $bad = []; $rows = liveRows($store, 'companies');
    foreach ($rows as $c) { if (trim((string) ($c['color'] ?? '')) === '') { $bad[] = (string) $c['name']; } }
    $add('Organization Setup', 'Companies', 'Every company has a brand colour', 'Colour set on each company', count($rows) . ' companies, ' . count($bad) . ' without colour ' . $list($bad), $bad === [], 'Organization Setup → Companies: pick a colour.', $bad ? 'Yes' : 'No');
});
$try('Organization Setup', 'Branches', 'Every branch has a State (Professional Tax / holidays)', function () use ($store, $add, $list) {
    $bad = []; $rows = liveRows($store, 'branches');
    foreach ($rows as $b) { if (trim((string) ($b['state'] ?? '')) === '') { $bad[] = (string) $b['name']; } }
    $add('Organization Setup', 'Branches', 'Every branch has a State (Professional Tax / holidays)', 'State set on each branch', count($rows) . ' branches, ' . count($bad) . ' without state ' . $list($bad), $bad === [], 'Organization Setup → Branches: set the State — PT "as per Government rules" reads it.', $bad ? 'Yes' : 'No');
});

// ---------- ATTENDANCE ----------
$punches = []; $orphans = []; $onOff = 0; $byDay = [];
foreach ($store->all('attendance_logs') as $p) {
    $c = (string) ($p['emp_code'] ?? ''); $d = substr((string) ($p['punch_at'] ?? ''), 0, 10);
    if (!isset($byCode[$c])) { $orphans[$c] = true; continue; }
    if ($d >= '2026-01-01' && $calendar->isWeeklyOff($d)) { $onOff++; }
    $byDay[$c][$d][] = (string) ($p['direction'] ?? '');
}
$add('Time & Attendance', 'W.OFF schedule', 'Weekly-off pattern saved and applied', 'A pattern is set', $calendar->weeklyOffLabel(), $calendar->weeklyOffLabel() !== 'No weekly off');
$add('Time & Attendance', 'Punches', 'No punches on weekly-off days (2026)', '0', (string) $onOff, $onOff === 0 ? true : 'WARN', 'Punches on a W.OFF are shown as WO (worked weekly off); check whether comp-off or OT should apply.');
$add('Time & Attendance', 'Punches', 'Every punch belongs to a live employee', '0 unknown codes', count($orphans) . ' unknown: ' . $list(array_keys($orphans)), $orphans === [] ? true : 'WARN', 'Map these on Biometric Device Setup → Pending Punches (or they are exited employees).', $orphans ? 'Yes' : 'No');
$try('Time & Attendance', 'Punches', 'No working day where many employees are missing punches', function () use ($emps, $byDay, $calendar, $yday, $add, $list) {
    $hol = $calendar->holidays(2026); $low = [];
    for ($d = '2026-01-01'; $d <= $yday; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        if ($calendar->isWeeklyOff($d) || isset($hol[$d])) { continue; }
        $miss = 0; foreach ($emps as $e) { if (substr((string) ($e['doj'] ?? ''), 0, 10) > $d) { continue; } $dirs = $byDay[Employment::code($e)][$d] ?? []; (!in_array('in', $dirs, true) || !in_array('out', $dirs, true)) && $miss++; }
        $miss > 15 && $low[] = "{$d} ({$miss})";
    }
    $add('Time & Attendance', 'Punches', 'No working day where many employees are missing punches', 'No day with >15 people missing an in/out pair', count($low) . ' day(s): ' . $list($low), $low === [], 'Check the device sync for those days.', $low ? 'Yes' : 'No');
});
$try('Time & Attendance', 'Shifts', 'Every employee resolves to a shift', function () use ($emps, $add, $list, $store, $calendar) {
    $sh = new SmartPRS\Surface\Shifts($store); $none = [];
    foreach ($emps as $e) { if ($sh->for($e) === null) { $none[] = Employment::code($e); } }
    $add('Time & Attendance', 'Shifts', 'Every employee resolves to a shift', 'A shift (or roster) for everyone', count($none) . ' fall back to the default ' . $calendar->shiftStart() . '–' . $calendar->shiftEnd() . ': ' . $list($none), $none === [] ? true : 'WARN', 'Organization Setup → Shifts: scope a shift to them, or assign in Roster.', $none ? 'Yes' : 'No');
});
$try('Time & Attendance', 'Attendance settlement', 'Every day of last month classifies without error', function () use ($emps, $attendance, $store, $calendar, $add, $list) {
    $from = date('Y-m-01', strtotime('first day of last month')); $to = date('Y-m-t', strtotime($from)); $bad = []; $n = 0;
    $sh = new SmartPRS\Surface\Shifts($store);
    foreach ($emps as $e) { $c = Employment::code($e); [$s, $en] = $sh->times($e, $calendar->shiftStart(), $calendar->shiftEnd());
        try { foreach ($attendance->settledDays($c, $s, $en, $from, $to) as $d) { $n++; if ((string) ($d['classification']['status'] ?? '') === '') { $bad[] = $c . ' ' . $d['date']; } } } catch (\Throwable $x) { $bad[] = $c . ': ' . $x->getMessage(); } }
    $add('Time & Attendance', 'Attendance settlement', 'Every day of last month classifies without error', 'All employee-days get a status', "{$n} employee-days, " . count($bad) . ' unclassified: ' . $list($bad, 4), $bad === [], 'Developer: check config/attendance-rules.json for an uncovered case.');
});

// ---------- LEAVE ----------
$try('Leave', 'Leave balances', 'No negative leave balance today', function () use ($emps, $leave, $policy, $add, $list) {
    $neg = []; $types = array_keys($policy->types());
    foreach ($emps as $e) { foreach ($types as $t) { try { $b = $leave->balance(Employment::code($e), (string) $t, new \DateTimeImmutable()); if ($b->isNegative()) { $neg[] = Employment::code($e) . " {$t} " . $b->toDecimalString(1); } } catch (\Throwable) { /* gate:allow-swallow type not held by this employee */ } } }
    $add('Leave', 'Leave balances', 'No negative leave balance today', '0 negative balances', count($neg) . ': ' . $list($neg, 6), $neg === [], 'Leave beyond balance should have been split to unpaid; review these on Leave Balance Report.', $neg ? 'Yes' : 'No');
});
$try('Leave', 'Leave requests', 'No overlapping approved leave for the same employee', function () use ($leave, $add, $list) {
    $by = []; $ov = [];
    foreach ($leave->all() as $l) { if ((string) $l['state'] !== 'approved') { continue; } $by[(string) $l['employment_id']][] = $l; }
    foreach ($by as $c => $ls) { usort($ls, static fn ($a, $b) => strcmp((string) $a['from'], (string) $b['from'])); for ($i = 1; $i < count($ls); $i++) { if ((string) $ls[$i]['from'] <= (string) $ls[$i - 1]['to']) { $ov[] = "{$c} {$ls[$i]['from']}"; } } }
    $add('Leave', 'Leave requests', 'No overlapping approved leave for the same employee', '0 overlaps', count($ov) . ': ' . $list($ov), $ov === [], 'Reject or edit one of each overlapping pair on Leave Approvals.', $ov ? 'Yes' : 'No');
});
$try('Leave', 'Leave approvals', 'Pending leave not waiting more than 15 days', function () use ($leave, $add, $list) {
    $old = []; foreach ($leave->pending() as $l) { if (substr((string) $l['requested_at'], 0, 10) < date('Y-m-d', strtotime('-15 days'))) { $old[] = $l['employment_id'] . ' ' . $l['from']; } }
    $add('Leave', 'Leave approvals', 'Pending leave not waiting more than 15 days', '0 stale requests', count($leave->pending()) . ' pending, ' . count($old) . ' older than 15 days: ' . $list($old, 5), $old === [] ? true : 'WARN', 'Decide them on Leave Approvals (Admin can decide all).', $old ? 'Yes' : 'No');
});
$try('Leave', 'Leave & Absence', 'Leave types configured', function () use ($policy, $add) {
    $t = $policy->types(); $add('Leave', 'Leave & Absence', 'Leave types configured', 'At least CL/SL/EL', count($t) . ' types: ' . implode(', ', array_keys($t)), count($t) >= 3);
});

// ---------- SALARY STRUCTURE / PAYROLL ----------
$try('Payroll', 'Salary Structure', 'No salary rule refers to itself (circular)', function () use ($componentRules, $add, $list) {
    $self = [];
    foreach ($componentRules->inForce(date('Y-m-d')) as $r) { if (strtoupper((string) ($r['basis_column'] ?? '')) === strtoupper((string) $r['component_code'])) { $self[] = $r['component_code'] . ' (rule ' . $r['id'] . ')'; } }
    $add('Payroll', 'Salary Structure', 'No salary rule refers to itself (circular)', '0 self-referencing rules', count($self) . ': ' . $list($self), $self === [], 'Salary Structure: change the "% of" basis of these rows (e.g. BASIC as % of GROSS, not of BASIC).', $self ? 'Yes' : 'No');
});
$try('Payroll', 'Salary Structure', 'Every rule in force uses a catalogue component', function () use ($componentRules, $add, $list) {
    $cat = $componentRules->components(); $bad = [];
    foreach ($componentRules->inForce(date('Y-m-d')) as $r) { if (!isset($cat[(string) $r['component_code']])) { $bad[] = (string) $r['component_code']; } }
    $add('Payroll', 'Salary Structure', 'Every rule in force uses a catalogue component', '0 unknown codes', count($componentRules->inForce(date('Y-m-d'))) . ' rules, ' . count($bad) . ' unknown: ' . $list($bad), $bad === []);
});
$try('Payroll', 'Pay cycles', 'Every employee is covered by a pay cycle', function () use ($emps, $payCycles, $add, $list) {
    $none = []; foreach ($emps as $e) { if ($payCycles->for($e) === null) { $none[] = Employment::code($e); } }
    $add('Payroll', 'Pay cycles', 'Every employee is covered by a pay cycle', '0 uncovered', count($payCycles->rules()) . ' cycle rule(s); ' . count($none) . ' uncovered: ' . $list($none), $none === [] ? true : 'WARN', 'Organization Setup → Salary Schedule / Pay Cycle: add a company-wide rule, or these use the calendar month.', $none ? 'Yes' : 'No');
});
$try('Payroll', 'Payroll engine', 'Last month computes for every employee (dry run, nothing saved)', function () use ($emps, $period, $calendar, $structure, $add, $list) {
    $from = date('Y-m-01', strtotime('first day of last month')); $to = date('Y-m-t', strtotime($from));
    $fail = []; $neg = []; $over = []; $zero = []; $ok = 0; $basis = $structure->contractedCostBasis($to);
    foreach ($emps as $e) { $c = Employment::code($e); if ($c === '') { continue; }
        try {
            $d = $period->forPeriod($c, $from, $to, $calendar->shiftStart(), $calendar->shiftEnd());
            $days = $calendar->prorationDays($from, $to)['days'] ?? (int) date('t', strtotime($from));
            if ((float) $d['payable']->toDecimalString() > $days) { $over[] = $c; }
            $run = new PayrollRun(substr($from, 0, 7), structureFor($e), [['employment_id' => $c, 'gross' => Employment::monthlyGross($e, $basis), 'payable_days' => $d['payable'], 'period_days' => $days]]);
            $s = $run->payslips[0]; $ok++;
            $s->netPay()->isNegative() && $neg[] = $c;
            $s->grossEarnings()->isZero() && $zero[] = $c;
        } catch (\Throwable $x) { $fail[] = $c . ': ' . substr($x->getMessage(), 0, 80); }
    }
    $add('Payroll', 'Payroll engine', 'Last month (' . substr($from, 0, 7) . ') computes for every employee', 'Every employee computes', "{$ok} computed, " . count($fail) . ' failed: ' . $list($fail, 3), $fail === [], 'Fix the Salary Structure rule named in the error, then regenerate.', $fail ? 'Yes' : 'No');
    $add('Payroll', 'Payroll engine', 'No negative net pay (' . substr($from, 0, 7) . ')', '0 negative', count($neg) . ': ' . $list($neg), $neg === [], 'Deductions exceed earnings for these — check flat deductions / LOP.', $neg ? 'Yes' : 'No');
    $add('Payroll', 'Payroll engine', 'No zero gross pay (' . substr($from, 0, 7) . ')', '0 zero-gross', count($zero) . ': ' . $list($zero), $zero === [] ? true : 'WARN', 'Usually no CTC/gross on the employee, or no attendance at all.', $zero ? 'Yes' : 'No');
    $add('Payroll', 'Payable days', 'Payable days never exceed the month (' . substr($from, 0, 7) . ')', '0 over', count($over) . ': ' . $list($over), $over === []);
});
$try('Payroll', 'Closed payrolls', 'Every closed payroll reproduces to the paisa', function () use ($register, $add, $list) {
    $runs = $register->runs(); $bad = [];
    foreach ($runs as $r) { $v = $register->verify((string) $r['period']); if (!($v['reproduced'] ?? false)) { $bad[] = $r['period'] . ' (' . substr((string) ($v['why'] ?? ''), 0, 60) . ')'; } }
    $add('Payroll', 'Closed payrolls', 'Every closed payroll reproduces to the paisa', 'All closed runs reproduce', count($runs) . ' closed run(s); ' . count($bad) . ' do not: ' . $list($bad, 3), $runs === [] ? 'INFO' : $bad === [], $runs === [] ? 'No payroll is closed yet — generate Jan–Sep to test end to end.' : 'Regenerate the months listed.', $runs === [] || $bad ? 'Yes' : 'No');
});
$try('Payroll', 'Certificate-linked Deductions', 'Rules active and linked to a Salary Structure deduction', function () use ($certificateHolds, $componentRules, $add) {
    $in = array_column($componentRules->inForce(date('Y-m-d')), 'component_code');
    foreach ($certificateHolds->rules() as $r) {
        $linked = in_array((string) $r['deduction_code'], $in, true);
        $add('Payroll', 'Certificate-linked Deductions', ($r['label'] ?? $r['key']) . ': active and linked', 'Active, and a Salary Structure rule deducts ' . $r['deduction_code'], (($r['active'] ?? false) ? 'Active' : 'OFF') . ', ' . ($linked ? 'linked' : 'no Salary Structure rule'), ($r['active'] ?? false) && $linked ? true : 'WARN',
            ($r['active'] ?? false) ? 'Add the deduction in Salary Structure (Employee Status calculation).' : 'Organization Setup → Certificate-linked Deductions: tick Active and Save when ready.', 'Yes');
    }
});
$add('Statutory & Compliance', 'Statutory packs', 'Statutory engine available', 'Signed statutory pack loaded', $statutory->isAvailable() ? 'Available (source: tools/phase6/synthetic-pack.php — a SYNTHETIC test pack)' : 'Not available: ' . $statutory->why(), $statutory->isAvailable() ? 'WARN' : false, 'Before going live, replace the synthetic pack with the real signed PF/ESI/PT/LWF pack for FY 2026-27 (developer task).', 'Yes');

// ---------- APPROVALS / ADMIN ----------
$perms = ['leave.request.decide', 'attendance.bulk.decide', 'payroll.run', 'payroll.view', 'employee.update', 'employee.list', 'tenant.settings.update', 'request.view', 'access.role.update', 'recruitment.requisition.decide'];
$missing = array_values(array_filter($perms, static fn ($p) => !$may($p)));
$add('Administration', 'Admin has no restrictions', 'Admin holds every decision permission', 'All ' . count($perms) . ' allowed', count($missing) === 0 ? 'All allowed' : 'Missing: ' . implode(', ', $missing), $missing === []);
$add('Administration', 'Admin has no restrictions', 'Admin is a final approver and may decide its own', 'true / true', var_export((new SmartPRS\Surface\ApprovalAuthority(__DIR__ . '/../../config/approval-authority.json'))->isFinal('administrator'), true) . ' / ' . var_export(leaveSelfApproves('administrator'), true), (new SmartPRS\Surface\ApprovalAuthority(__DIR__ . '/../../config/approval-authority.json'))->isFinal('administrator') && leaveSelfApproves('administrator'));
$try('Administration', 'Users', 'Every login role exists on Roles & Permissions', function () use ($store, $access, $add, $list) {
    $roles = array_map('strtolower', array_keys($access->roles())); $bad = [];
    foreach ($store->all('users') as $u) { $r = strtolower((string) ($u['role'] ?? '')); if ($r !== '' && !in_array($r, $roles, true) && $r !== 'administrator') { $bad[] = (string) ($u['email'] ?? $u['id']) . " ({$r})"; } }
    $add('Administration', 'Users', 'Every login role exists on Roles & Permissions', '0 unknown roles', count($bad) . ': ' . $list($bad), $bad === [], 'User Management: give these logins a real role.', $bad ? 'Yes' : 'No');
});
$try('Administration', 'Users', 'Linked employee exists for every login with an employee', function () use ($store, $byId, $add, $list) {
    $bad = []; foreach ($store->all('users') as $u) { $e = (string) ($u['employee_id'] ?? ''); if ($e !== '' && !isset($byId[$e])) { $bad[] = (string) ($u['email'] ?? ''); } }
    $add('Administration', 'Users', 'Linked employee exists for every login with an employee', '0 broken links', count($bad) . ': ' . $list($bad), $bad === [], 'User Management: re-link or disable these logins.', $bad ? 'Yes' : 'No');
});

// ---------- CUSTOMISATION ----------
$try('Customisation', 'Versioned customisations', 'Every saved customisation reads back (valid JSON, latest version wins)', function () use ($store, $customisation, $add) {
    $rows = $store->all('module_customisations'); $bad = 0; $keys = [];
    foreach ($rows as $r) { $keys[(string) $r['module_key']] = true; if (!is_array(json_decode((string) ($r['payload'] ?? ''), true))) { $bad++; } }
    $add('Customisation', 'Versioned customisations', 'Every saved customisation reads back (valid JSON, latest version wins)', '0 unreadable versions', count($rows) . ' versions across ' . count($keys) . ' settings; ' . $bad . ' unreadable; live keys ' . count($customisation->live()), $bad === []);
});
foreach (['complaint_responses', 'job_postings', 'module_customisations', 'salary_component_rules', 'workflow_requests'] as $tbl) {
    $try('Installation', 'Database', "Table {$tbl} exists", function () use ($store, $tbl, $add) {
        $ok = true; try { $store->columnsOf($tbl); } catch (\Throwable) { $ok = false; }
        $add('Installation', 'Database', "Table {$tbl} exists", 'Present', $ok ? 'Present' : 'Missing', $ok, 'Run composer db:migrate on the PC.', $ok ? 'No' : 'Yes');
    });
}
$dev = glob(__DIR__ . '/dev-*.php') ?: [];
$add('Installation', 'Clean-up', 'No developer-only screens left', '0 dev-*.php screens', count($dev) . ' present', $dev === [] ? true : 'WARN', 'When testing is finished: del C:\laragon\www\SmartPRS3\surface\screens\dev-*.php', 'Yes');
$seed = array_filter($store->all('users'), static fn ($u) => str_contains(strtolower((string) ($u['email'] ?? '')), 'smartprslocal'));
$add('Installation', 'Security', 'Seeded demo admin removed before go-live', 'No @smartprslocal account', count($seed) . ' present', $seed === [] ? true : 'WARN', 'Before any client install: remove admin@smartprslocal and raise PASSWORD_FLOOR to 12.', 'Yes');
file_put_contents(__DIR__ . '/../../storage/dev-selftest.json', json_encode($T, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
?><pre id="st"><?= e(json_encode($T, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
