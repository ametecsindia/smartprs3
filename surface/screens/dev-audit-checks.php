<?php declare(strict_types=1);
// DEV ONLY (owner, 16 Oct 2026): READ-ONLY cross-verification of the whole application against
// Organization Configuration. Nothing is written to the database. Output: storage/audit/checks.json.
// Delete after the audit.
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Shifts;
use SmartPRS\Surface\LatePolicy;

if (!isAdministrator((string) ($session->user()['role'] ?? ''))) { echo 'administrator only'; return; }
@set_time_limit(300);
$A = [];
$row = static function (string $area, string $menu, string $item, string $check, string $expected, string $actual, string $result, array $evidence = [], string $desc = '') use (&$A): void {
    $A[] = compact('area', 'menu', 'item', 'check', 'expected', 'actual', 'result', 'desc') + ['evidence' => array_slice(array_values($evidence), 0, 80), 'evidence_count' => count($evidence)];
};
$run = static function (string $area, string $menu, string $check, callable $fn) use ($row): void {
    try { $fn(); } catch (\Throwable $x) { $row($area, $menu, '', $check, 'Check runs', 'Error: ' . $x->getMessage() . ' @' . basename($x->getFile()) . ':' . $x->getLine(), 'ERROR'); }
};
$q = static function (string $sql) use ($pdo, $tenantId): array {
    return $pdo->query(str_replace(':T', $pdo->quote((string) $tenantId), $sql))->fetchAll(\PDO::FETCH_ASSOC);
};
$blank = static fn ($v): bool => $v === null || trim((string) $v) === '';
$lc = static fn ($v): string => strtolower(trim((string) $v));
$today = date('Y-m-d');
$year = (int) date('Y');

$companies = $q('SELECT * FROM companies WHERE tenant_id = :T AND deleted_at IS NULL ORDER BY id');
$branches = $q('SELECT * FROM branches WHERE tenant_id = :T AND deleted_at IS NULL ORDER BY id');
$departments = $q('SELECT * FROM departments WHERE tenant_id = :T AND deleted_at IS NULL ORDER BY id');
$designations = $q('SELECT * FROM designations WHERE tenant_id = :T AND deleted_at IS NULL ORDER BY id');
$teams = $q('SELECT * FROM teams WHERE tenant_id = :T AND deleted_at IS NULL ORDER BY id');
$shifts = $q('SELECT * FROM shifts WHERE tenant_id = :T ORDER BY id');
$emps = liveRows($store, 'employees');
$active = array_values(array_filter($emps, static fn ($e): bool => trim((string) ($e['archived_at'] ?? '')) === '' && trim((string) ($e['exit_date'] ?? '')) === ''));
$byId = static function (array $rows): array { $o = []; foreach ($rows as $r) { $o[(string) $r['id']] = $r; } return $o; };
$C = $byId($companies); $B = $byId($branches); $D = $byId($departments); $G = $byId($designations); $TM = $byId($teams); $S = $byId($shifts);
$cName = static fn ($id): string => (string) ($C[(string) $id]['name'] ?? ($id === null || $id === '' ? '(none)' : 'company#' . $id));
$code = static fn (array $e): string => Employment::code($e);
$names = static fn (array $rows): array => array_values(array_filter(array_map(static fn ($r): string => trim((string) ($r['name'] ?? '')), $rows)));
$roleNames = array_values(array_unique(array_filter(array_map(static fn ($r): string => trim((string) ($r['role'] ?? '')), $designations))));
$ptStates = ['telangana', 'andhra pradesh', 'maharashtra', 'karnataka', 'tamil nadu', 'west bengal', 'gujarat', 'madhya pradesh', 'kerala', 'assam', 'odisha', 'bihar', 'jharkhand', 'meghalaya', 'tripura', 'sikkim', 'mizoram', 'manipur', 'nagaland', 'puducherry', 'punjab'];
$pfPrefix = ['TS' => 'telangana', 'AP' => 'andhra pradesh', 'MH' => 'maharashtra', 'KN' => 'karnataka', 'TN' => 'tamil nadu', 'DL' => 'delhi', 'GJ' => 'gujarat', 'WB' => 'west bengal', 'UP' => 'uttar pradesh', 'RJ' => 'rajasthan', 'KR' => 'kerala', 'PB' => 'punjab', 'PU' => 'punjab', 'HR' => 'haryana', 'MP' => 'madhya pradesh', 'OR' => 'odisha', 'BR' => 'bihar', 'JH' => 'jharkhand', 'GA' => 'goa', 'AS' => 'assam', 'CG' => 'chhattisgarh', 'UK' => 'uttarakhand', 'HP' => 'himachal pradesh', 'JK' => 'jammu and kashmir'];
$tanCity = ['HYD' => 'hyderabad', 'PNE' => 'pune', 'MUM' => 'mumbai', 'BLR' => 'bengaluru', 'CHE' => 'chennai', 'DEL' => 'delhi', 'KOL' => 'kolkata', 'AHM' => 'ahmedabad', 'NGP' => 'nagpur', 'VPN' => 'visakhapatnam'];

// ================= COMPANIES =================
$run('Organization Setup', 'Companies', 'Mandatory company fields', function () use ($companies, $row, $blank, $lc, $ptStates) {
    $need = ['name' => 'Name', 'code' => 'Code', 'legal_name' => 'Legal name', 'type' => 'Type', 'state' => 'State', 'timezone' => 'Time zone', 'pan' => 'PAN', 'tan' => 'TAN',
        'gstin' => 'GSTIN', 'pf_establishment_code' => 'PF establishment code', 'esic_employer_code' => 'ESIC employer code', 'pt_registration_no' => 'PT registration no',
        'address' => 'Address', 'phone' => 'Phone', 'email' => 'Email', 'status' => 'Status', 'color' => 'Colour', 'tenure_basis' => 'Tenure basis', 'paid_during_training' => 'Paid during training'];
    foreach ($companies as $c) {
        $miss = [];
        foreach ($need as $k => $l) {
            if ($k === 'pt_registration_no' && !in_array($lc($c['state'] ?? ''), $ptStates, true)) { continue; }
            if ($blank($c[$k] ?? null)) { $miss[] = $l; }
        }
        $row('Organization Setup', 'Companies', (string) $c['name'], 'All company fields used by payroll, statutory returns, letters and payslips are filled',
            'Every field filled', $miss === [] ? 'All filled' : count($miss) . ' blank: ' . implode(', ', $miss), $miss === [] ? 'PASS' : (array_intersect($miss, ['GSTIN', 'PT registration no', 'PF establishment code', 'ESIC employer code', 'TAN', 'PAN', 'State', 'Time zone']) ? 'FAIL' : 'WARN'),
            $miss, 'Blank statutory identifiers (PAN/TAN/GSTIN/PF/ESIC/PT) leave challans, Form 16, ECR and payslips without the employer identity. Fill them on Organization Configuration → Companies.');
    }
});
$run('Organization Setup', 'Companies', 'Time zone valid', function () use ($companies, $row) {
    foreach ($companies as $c) {
        $tz = trim((string) ($c['timezone'] ?? ''));
        $ok = $tz !== '' && in_array($tz, timezone_identifiers_list(), true);
        $row('Organization Setup', 'Companies', (string) $c['name'], 'Time zone set and valid — every task of this company runs on it',
            'A valid IANA zone', $tz === '' ? '(blank)' : $tz, $ok ? 'PASS' : 'FAIL', [], 'The application now runs each request on the signed-in person\'s company time zone.');
    }
});
$run('Organization Setup', 'Companies', 'PF code / TAN match the company state', function () use ($companies, $branches, $row, $lc, $pfPrefix, $tanCity) {
    foreach ($companies as $c) {
        $st = $lc($c['state'] ?? '');
        $pf = strtoupper(trim((string) ($c['pf_establishment_code'] ?? '')));
        if ($pf !== '') {
            $pfSt = $pfPrefix[substr($pf, 0, 2)] ?? '?';
            $row('Organization Setup', 'Companies', (string) $c['name'], 'PF establishment code region matches company state',
                'Prefix of a ' . ($c['state'] ?? '?') . ' PF office', $pf . ' → ' . ucwords($pfSt), $pfSt === $st ? 'PASS' : 'FAIL', [],
                'The first two letters of a PF establishment code are the EPFO state office. A code from another state means ECR/challans go out under the wrong establishment.');
        }
        $tan = strtoupper(trim((string) ($c['tan'] ?? '')));
        if ($tan !== '') {
            $cities = array_map($lc, array_column(array_filter($branches, static fn ($b): bool => (string) $b['employer_company_id'] === (string) $c['id']), 'city'));
            $tc = $tanCity[substr($tan, 0, 3)] ?? '';
            $row('Organization Setup', 'Companies', (string) $c['name'], 'TAN area code matches a city where the company has a branch',
                'TAN of ' . implode('/', array_unique($cities)), $tan . ' → ' . ($tc !== '' ? ucwords($tc) : substr($tan, 0, 3)), $tc !== '' && in_array($tc, $cities, true) ? 'PASS' : 'WARN', [],
                'A TAN is issued by the jurisdiction where the deductor is located. Verify the TAN if the company has no office in that city.');
        }
    }
});
$run('Organization Setup', 'Companies', 'One master company, unique names and codes', function () use ($companies, $row, $lc) {
    $masters = array_column(array_filter($companies, static fn ($c): bool => (int) ($c['is_master'] ?? 0) === 1), 'name');
    $row('Organization Setup', 'Companies', 'All companies', 'Exactly one master (holding) company', '1', (string) count($masters) . ' (' . implode(', ', $masters) . ')', count($masters) === 1 ? 'PASS' : 'FAIL');
    foreach (['name', 'code'] as $k) {
        $d = array_keys(array_filter(array_count_values(array_map($lc, array_column($companies, $k))), static fn ($n) => $n > 1));
        $row('Organization Setup', 'Companies', 'All companies', 'Company ' . $k . 's are unique', 'No duplicates', $d === [] ? 'No duplicates' : implode(', ', $d), $d === [] ? 'PASS' : 'FAIL');
    }
});
$run('Organization Setup', 'Companies', 'Probation settings per company', function () use ($companies, $row, $probation) {
    foreach ($companies as $c) {
        $cfg = $probation->config((string) $c['id']);
        $own = $probation->isOverridden((string) $c['id']);
        $row('Organization Setup', 'Companies → Probation', (string) $c['name'], 'Probation policy in force',
            'Months set; reminders set', ($own ? 'Own policy: ' : 'Inherits default: ') . (int) $cfg['default_months'] . ' months + ' . (int) ($cfg['default_days'] ?? 0) . ' days; reminders ' . json_encode($cfg['reminder_days'] ?? $cfg['remind_days'] ?? null),
            (int) $cfg['default_months'] > 0 ? 'PASS' : 'FAIL');
    }
});

// ================= BRANCHES / DEPARTMENTS / DESIGNATIONS / TEAMS =================
$run('Organization Setup', 'Branches', 'Branch fields and company link', function () use ($branches, $C, $row, $blank, $lc, $cName, $q, $ptStates) {
    $pt = array_count_values(array_map($lc, array_column($q('SELECT state FROM pt_slabs WHERE tenant_id = :T'), 'state')));
    foreach ($branches as $b) {
        $miss = [];
        foreach (['code' => 'Code', 'city' => 'City', 'state' => 'State', 'address' => 'Address', 'phone' => 'Phone', 'email' => 'Email', 'manager_id' => 'Branch manager', 'lat' => 'Latitude', 'lng' => 'Longitude', 'pincode' => 'Pincode'] as $k => $l) {
            if ($blank($b[$k] ?? null)) { $miss[] = $l; }
        }
        $row('Organization Setup', 'Branches', (string) $b['name'], 'Branch fields filled', 'Every field filled', $miss === [] ? 'All filled' : implode(', ', $miss) . ' blank', $miss === [] ? 'PASS' : (in_array('State', $miss, true) ? 'FAIL' : 'WARN'), $miss,
            'Branch manager is used for approvals/notifications; latitude/longitude for geo-fencing; state for Professional Tax.');
        $row('Organization Setup', 'Branches', (string) $b['name'], 'Branch belongs to an existing company', 'Company set', $cName($b['employer_company_id'] ?? ''), isset($C[(string) ($b['employer_company_id'] ?? '')]) ? 'PASS' : 'FAIL');
        $cs = $lc($C[(string) ($b['employer_company_id'] ?? '')]['state'] ?? '');
        $row('Organization Setup', 'Branches', (string) $b['name'], 'Branch state vs company state', 'Same state, or a deliberate interstate branch', ($b['state'] ?? '') . ' vs company ' . ($C[(string) ($b['employer_company_id'] ?? '')]['state'] ?? ''), $cs === $lc($b['state'] ?? '') ? 'PASS' : 'WARN');
        if (in_array($lc($b['state'] ?? ''), $ptStates, true)) {
            $n = $pt[$lc($b['state'])] ?? 0;
            $row('Statutory', 'Professional Tax slabs', (string) $b['name'], 'PT slabs exist for the branch state (' . $b['state'] . ')', 'At least one PT slab for ' . $b['state'], $n . ' slab(s)', $n > 0 ? 'PASS' : 'FAIL', [],
                'Employees in this branch are charged PT by their branch state; with no slab, PT computes as zero.');
        }
    }
});
$run('Organization Setup', 'Departments', 'Department links', function () use ($departments, $C, $B, $row, $blank, $cName) {
    foreach ($departments as $d) {
        $miss = [];
        foreach (['employer_company_id' => 'Company', 'branch_id' => 'Branch', 'head_employee_id' => 'Department head', 'code' => 'Code'] as $k => $l) { if ($blank($d[$k] ?? null)) { $miss[] = $l; } }
        $row('Organization Setup', 'Branches → Departments', (string) $d['name'], 'Department company / branch / head set', 'All set', $miss === [] ? 'All set (' . $cName($d['employer_company_id']) . ')' : implode(', ', $miss) . ' blank', in_array('Company', $miss, true) ? 'FAIL' : ($miss === [] ? 'PASS' : 'WARN'), $miss,
            'A department with no branch is offered under every branch of its company; no head means no department-level approver.');
        if (!$blank($d['branch_id'] ?? null) && isset($B[(string) $d['branch_id']]) && (string) $B[(string) $d['branch_id']]['employer_company_id'] !== (string) $d['employer_company_id']) {
            $row('Organization Setup', 'Branches → Departments', (string) $d['name'], 'Department branch belongs to the department company', 'Same company', 'Branch of ' . $cName($B[(string) $d['branch_id']]['employer_company_id']), 'FAIL');
        }
    }
});
$run('Organization Setup', 'Role / Designation Setup', 'Designation role, level, company', function () use ($designations, $row, $blank, $cName) {
    foreach ($designations as $g) {
        $miss = [];
        foreach (['role' => 'Role', 'level' => 'Level', 'code' => 'Code', 'department_id' => 'Department', 'grade' => 'Grade'] as $k => $l) { if ($blank($g[$k] ?? null)) { $miss[] = $l; } }
        $res = in_array('Role', $miss, true) || in_array('Level', $miss, true) ? 'FAIL' : ($miss === [] ? 'PASS' : 'WARN');
        $row('Organization Setup', 'Role / Designation Setup', (string) $g['name'], 'Designation has Role, Level, Code, Department, Grade',
            'All set', ($miss === [] ? 'All set' : implode(', ', $miss) . ' blank') . '; company: ' . ($blank($g['employer_company_id'] ?? null) ? 'all companies (shared)' : $cName($g['employer_company_id'])), $res, $miss,
            'Role drives Roles & Permissions, approval routing, holiday/payslip/salary scopes; Level drives hierarchy and the Director/Manager rules.');
    }
});
$run('Organization Setup', 'Teams', 'Team links', function () use ($teams, $row, $blank, $cName) {
    foreach ($teams as $t) {
        $miss = [];
        foreach (['employer_company_id' => 'Company', 'manager_id' => 'Manager', 'leader_id' => 'Team leader', 'function' => 'Function'] as $k => $l) { if ($blank($t[$k] ?? null)) { $miss[] = $l; } }
        $row('Organization Setup', 'Teams', (string) $t['name'], 'Team company / manager / leader set', 'All set', $miss === [] ? 'All set' : implode(', ', $miss) . ' blank', in_array('Company', $miss, true) ? 'FAIL' : ($miss === [] ? 'PASS' : 'WARN'), $miss,
            'Team Lead approvals and team scopes read the team\'s leader and manager.');
    }
});

// ================= SHIFTS / LATE POLICY =================
$run('Organization Setup', 'Shifts', 'Shift definitions', function () use ($shifts, $row, $blank, $cName) {
    foreach ($shifts as $s) {
        $st = (string) ($s['start_time'] ?? ''); $en = (string) ($s['end_time'] ?? '');
        $span = $st !== '' && $en !== '' ? ((strtotime('2000-01-01 ' . $en) - strtotime('2000-01-01 ' . $st) + 86400) % 86400) / 3600 : 0;
        $full = (float) ($s['full_day_hours'] ?? 0); $half = (float) ($s['half_day_hours'] ?? 0);
        $issues = [];
        if ($st === '' || $en === '') { $issues[] = 'start/end blank'; }
        if ($full > $span + 0.001) { $issues[] = "full-day hours {$full} > shift span {$span}"; }
        if ($half >= $full && $full > 0) { $issues[] = 'half-day hours not below full-day hours'; }
        if ($blank($s['employer_company_id'] ?? null)) { $issues[] = 'no company (offered to every company)'; }
        if (trim((string) ($s['company_name'] ?? '')) !== '' && !$blank($s['employer_company_id'] ?? null) && strcasecmp(trim((string) $s['company_name']), $cName($s['employer_company_id'])) !== 0) { $issues[] = 'company_name text "' . $s['company_name'] . '" ≠ ' . $cName($s['employer_company_id']); }
        $row('Organization Setup', 'Shifts', (string) $s['name'] . ' (' . $st . '–' . $en . ')', 'Shift times, hours and company consistent',
            'Full-day ≤ span, half < full, company set', 'Span ' . round($span, 2) . 'h, full ' . $full . 'h, half ' . $half . 'h, grace ' . ($s['grace_min'] ?? '-') . 'm, company ' . $cName($s['employer_company_id'] ?? ''),
            $issues === [] ? 'PASS' : (count($issues) === 1 && str_starts_with($issues[0], 'no company') ? 'WARN' : 'FAIL'), $issues);
    }
});
$run('Organization Setup', 'Late Login Policy', 'Late policy vs shifts and companies', function () use ($q, $row, $C, $cName, $shifts, $lc) {
    $lp = $q('SELECT * FROM late_policy WHERE tenant_id = :T');
    foreach ($lp as $p) {
        $issues = [];
        if ((string) ($p['employer_company_id'] ?? '') !== '' && !isset($C[(string) $p['employer_company_id']])) { $issues[] = 'company not found'; }
        $sh = array_values(array_filter($shifts, static fn ($s): bool => substr((string) $s['start_time'], 0, 5) === substr((string) ($p['shift_start'] ?? ''), 0, 5) && substr((string) $s['end_time'], 0, 5) === substr((string) ($p['shift_end'] ?? ''), 0, 5)));
        if (trim((string) ($p['shift_start'] ?? '')) !== '' && $sh === []) { $issues[] = 'shift window ' . $p['shift_start'] . '–' . $p['shift_end'] . ' matches no configured shift'; }
        foreach ($sh as $s) { if ((int) ($s['grace_min'] ?? -1) !== (int) ($p['grace_min'] ?? -2)) { $issues[] = 'grace ' . $p['grace_min'] . 'm here vs ' . $s['grace_min'] . 'm on shift ' . $s['name']; } }
        $row('Organization Setup', 'Late Login Policy', 'Policy #' . $p['id'] . ' (' . ($p['scope'] ?? 'all') . ($p['scope_target'] ? ': ' . $p['scope_target'] : '') . ', ' . $cName($p['employer_company_id'] ?? '') . ')',
            'Late policy agrees with the shift it applies to', 'Same window and grace as the shift', 'Window ' . ($p['shift_start'] ?? '-') . '–' . ($p['shift_end'] ?? '-') . ', grace ' . ($p['grace_min'] ?? '-') . 'm, full-day ' . ($p['full_day_hours'] ?? '-') . 'h',
            $issues === [] ? 'PASS' : 'WARN', $issues, 'Two different grace periods for one shift give different "late" answers on the Attendance Report and in payroll deductions.');
    }
});

// ================= STATUTORY CONFIG =================
$run('Statutory', 'PF / ESI settings', 'Every company has PF/ESI rates', function () use ($q, $companies, $row) {
    $s = []; foreach ($q('SELECT * FROM pf_esi_settings WHERE tenant_id = :T') as $r) { $s[(string) ($r['employer_company_id'] ?? '')] = $r; }
    foreach ($companies as $c) {
        $r = $s[(string) $c['id']] ?? $s[''] ?? null;
        $row('Statutory', 'PF / ESI settings', (string) $c['name'], 'PF / ESI rates configured for the company', 'PF 12/12, EPS 8.33, ESI 0.75/3.25, ceiling 21000 (or company-specific)',
            $r === null ? 'No row — engine defaults used' : 'PF ' . $r['pf_ee_pct'] . '/' . $r['pf_er_pct'] . ', EPS ' . $r['eps_pct'] . ', ESI ' . $r['esi_ee_pct'] . '/' . $r['esi_er_pct'] . ', ceiling ' . $r['esi_ceiling'] . (isset($s[(string) $c['id']]) ? '' : ' (tenant-wide row)'),
            $r === null ? 'WARN' : ((float) $r['esi_ee_pct'] <= 0 || (float) $r['esi_er_pct'] <= 0 || trim((string) $r['eps_pct']) === '' || trim((string) $r['esi_ceiling']) === '' ? 'FAIL' : 'PASS'), [],
            'ESI employee/employer % of 0, or a blank EPS % / ESI wage ceiling, makes every ESI-applicable employee\'s ESI compute as zero and the EPS split undefined.');
    }
});
$run('Statutory', 'Professional Tax slabs', 'PT slab content', function () use ($q, $row) {
    foreach ($q('SELECT * FROM pt_slabs WHERE tenant_id = :T ORDER BY state, wage_from') as $p) {
        $row('Statutory', 'Professional Tax slabs', (string) $p['state'], 'PT slab', 'Slab for the state', 'Wage ' . $p['wage_from'] . '–' . $p['wage_to'] . ' → ₹' . $p['amount'], 'INFO');
    }
});

// ================= HOLIDAYS / WEEKLY OFF =================
$run('Organization Setup', 'Holiday / W.OFF Schedule', 'Holidays configured and reaching each company/branch', function () use ($calendar, $companies, $branches, $row, $year, $designations) {
    $all = $calendar->holidays($year);
    $row('Organization Setup', 'Holiday / W.OFF Schedule', 'Calendar ' . $year, 'Holidays declared for the year', 'At least the national holidays', count($all) . ' holiday(s); weekly off: ' . $calendar->weeklyOffLabel(), count($all) > 0 ? 'PASS' : 'FAIL',
        array_map(static fn ($d, $n): string => $d . ' ' . $n, array_keys($all), array_values($all)));
    $cn = []; foreach ($companies as $c) { $cn[(string) $c['id']] = (string) $c['name']; }
    $dr = []; foreach ($designations as $g) { $dr[(string) $g['id']] = (string) ($g['role'] ?? ''); }
    foreach ($branches as $b) {
        $h = $calendar->holidaysForEmployment(['employer_company_id' => $b['employer_company_id'], 'branch' => $b['name'], 'branch_id' => $b['id']], $cn, $dr, $year);
        $row('Organization Setup', 'Holiday / W.OFF Schedule', (string) $b['name'] . ' (' . ($cn[(string) $b['employer_company_id']] ?? '') . ')', 'Holidays reaching this branch', '≥ 1', count($h) . ' holiday(s)', count($h) > 0 ? 'PASS' : 'FAIL',
            array_map(static fn ($d, $n): string => $d . ' ' . $n, array_keys($h), array_values($h)));
    }
});

// ================= PAY CYCLES / PAYSLIP FORMATS =================
$run('Payroll', 'Salary Schedule / Pay Cycle', 'Pay cycle covers every employee', function () use ($payCycles, $active, $row, $code) {
    $rules = $payCycles->rules();
    $row('Organization Setup', 'Salary Schedule / Pay Cycle', 'Rules', 'Pay cycle rules declared', '≥ 1 active rule', count($rules) . ' rule(s)', count($rules) > 0 ? 'PASS' : 'FAIL',
        array_map(static fn ($r): string => ($r['name'] ?? ('#' . ($r['id'] ?? ''))) . ': ' . json_encode(array_filter($r, static fn ($v) => !is_array($v) && $v !== '' && $v !== null)), $rules));
    $none = [];
    foreach ($active as $e) { if ($payCycles->for($e) === null) { $none[] = $code($e); } }
    $row('Payroll', 'Salary Schedule / Pay Cycle', 'Employees', 'Every active employee is covered by a pay cycle', '0 uncovered', count($none) . ' of ' . count($active) . ' uncovered', $none === [] ? 'PASS' : 'FAIL', $none,
        'An employee no pay-cycle rule covers has no pay window or pay date.');
});
$run('Payroll', 'Payslip format', 'Payslip format scope names and coverage', function () use ($q, $companies, $branches, $teams, $designations, $roleNames, $row, $lc) {
    $fs = array_values(array_filter($q('SELECT * FROM payslip_formats WHERE tenant_id = :T'), static fn ($f): bool => trim((string) ($f['retired_at'] ?? '')) === ''));
    $valid = ['scope_company' => array_map($lc, array_column($companies, 'name')), 'scope_branch' => array_map($lc, array_column($branches, 'name')), 'scope_team' => array_map($lc, array_column($teams, 'name')),
        'scope_role' => array_map($lc, $roleNames), 'scope_designation' => array_map($lc, array_column($designations, 'name'))];
    foreach ($fs as $f) {
        $bad = [];
        foreach ($valid as $k => $ok) { foreach (array_filter(array_map('trim', explode(',', (string) ($f[$k] ?? '')))) as $v) { if (!in_array($lc($v), $ok, true)) { $bad[] = $k . ' "' . $v . '"'; } } }
        $row('Organization Setup', 'Payslip format', (string) $f['name'], 'Scope names exist in Organization Configuration', 'All names configured', $bad === [] ? 'All valid' : implode('; ', $bad), $bad === [] ? 'PASS' : 'FAIL', $bad);
    }
    $unscoped = array_filter($fs, static fn ($f): bool => trim((string) ($f['scope_company'] ?? '') . ($f['scope_branch'] ?? '') . ($f['scope_team'] ?? '') . ($f['scope_role'] ?? '') . ($f['scope_designation'] ?? '') . ($f['scope_employee'] ?? '')) === '');
    foreach ($companies as $c) {
        $has = $unscoped !== [] || array_filter($fs, static fn ($f): bool => in_array($lc($c['name']), array_map($lc, explode(',', (string) ($f['scope_company'] ?? ''))), true)) !== [];
        $row('Organization Setup', 'Payslip format', (string) $c['name'], 'A payslip format applies to this company', 'Company-scoped or default format', $has ? 'Yes' : 'None — built-in default layout used', $has ? 'PASS' : 'WARN');
    }
});

// ================= TEXT-NAME REFERENCES ANYWHERE =================
$run('Data integrity', 'All modules', 'Names typed into scope/text columns exist in Organization Configuration', function () use ($q, $companies, $branches, $departments, $designations, $teams, $shifts, $roleNames, $row, $lc, $pdo) {
    $valid = ['company' => array_map($lc, array_column($companies, 'name')), 'branch' => array_map($lc, array_column($branches, 'name')), 'department' => array_map($lc, array_column($departments, 'name')),
        'designation' => array_map($lc, array_column($designations, 'name')), 'team' => array_map($lc, array_column($teams, 'name')), 'role' => array_map($lc, $roleNames), 'shift' => array_map($lc, array_column($shifts, 'name'))];
    $targets = ['employees' => ['branch' => 'branch', 'department' => 'department', 'designation' => 'designation', 'team' => 'team'],
        'salary_component_rules' => ['scope_company' => 'company', 'scope_branch' => 'branch', 'scope_role' => 'role', 'scope_team' => 'team', 'scope_designation' => 'designation'],
        'payslip_formats' => ['scope_company' => 'company', 'scope_branch' => 'branch', 'scope_role' => 'role', 'scope_team' => 'team', 'scope_designation' => 'designation'],
        'transfers' => ['from_company' => 'company', 'from_branch' => 'branch', 'to_company' => 'company', 'to_branch' => 'branch', 'to_department' => 'department'],
        'job_openings' => ['department' => 'department'], 'onboarding' => ['company_name' => 'company'], 'shifts' => ['company_name' => 'company'], 'performance_reviews' => ['company_name' => 'company']];
    foreach ($targets as $t => $cols) {
        foreach ($cols as $col => $kind) {
            try { $rows = $q('SELECT id, `' . $col . '` v' . ($t === 'employees' ? ', emp_code' : '') . ' FROM `' . $t . '` WHERE tenant_id = :T' . ($t === 'employees' ? ' AND deleted_at IS NULL' : '') . " AND `{$col}` IS NOT NULL AND `{$col}` <> ''"); }
            catch (\Throwable $x) { error_log('audit text ' . $t . '.' . $col . ': ' . $x->getMessage()); continue; /* tolerated: column absent on this install */ }
            $bad = [];
            foreach ($rows as $r) {
                $vals = str_starts_with(trim((string) $r['v']), '[') ? (array) json_decode((string) $r['v'], true) : explode(',', (string) $r['v']);
                foreach (array_filter(array_map('trim', array_map('strval', $vals))) as $v) {
                    if (in_array($lc($v), ['all', 'any', '*', '__any__'], true)) { continue; }
                    if (!in_array($lc($v), $valid[$kind], true)) { $bad[] = $t . '#' . $r['id'] . (isset($r['emp_code']) ? ' ' . $r['emp_code'] : '') . ': "' . $v . '"'; }
                }
            }
            $row('Data integrity', ucfirst(str_replace('_', ' ', $t)), $t . '.' . $col, ucfirst($kind) . ' names used here exist in Organization Configuration',
                'Only configured ' . $kind . ' names', count($rows) . ' row(s) checked, ' . count($bad) . ' unknown', $bad === [] ? 'PASS' : 'FAIL', $bad,
                'A name that is not in the register (renamed, misspelt or deleted) silently stops matching: the rule/format/scope no longer applies to anyone.');
        }
    }
});

// ================= EMPLOYEES vs CONFIGURATION =================
$run('People', 'Directory', 'Organisation fields set on every active employee', function () use ($active, $row, $blank, $code) {
    foreach (['employer_company_id' => 'Company', 'branch_id' => 'Branch', 'department_id' => 'Department', 'designation_id' => 'Designation', 'team_id' => 'Team', 'reporting_manager_id' => 'Reporting manager', 'doj' => 'Date of joining', 'gender' => 'Gender'] as $k => $l) {
        $miss = array_map($code, array_values(array_filter($active, static fn ($e): bool => $blank($e[$k] ?? null))));
        $row('People', 'Directory', $l, $l . ' set on every active employee', '0 blank', count($miss) . ' of ' . count($active) . ' blank', $miss === [] ? 'PASS' : (in_array($k, ['team_id', 'reporting_manager_id', 'gender'], true) ? 'WARN' : 'FAIL'), $miss);
    }
});
$run('People', 'Directory', 'Text copies agree with the linked register', function () use ($emps, $B, $D, $G, $TM, $row, $code, $lc) {
    $map = ['branch' => [$B, 'branch_id'], 'department' => [$D, 'department_id'], 'designation' => [$G, 'designation_id'], 'team' => [$TM, 'team_id']];
    foreach ($map as $k => [$reg, $idc]) {
        $bad = [];
        foreach ($emps as $e) {
            $id = (string) ($e[$idc] ?? ''); $txt = trim((string) ($e[$k] ?? ''));
            if ($id === '' || $txt === '' || !isset($reg[$id])) { continue; }
            if ($lc($reg[$id]['name']) !== $lc($txt)) { $bad[] = $code($e) . ': ' . $k . ' text "' . $txt . '" but linked ' . $k . ' is "' . $reg[$id]['name'] . '"'; }
        }
        $row('People', 'Directory', ucfirst($k), 'Employee ' . $k . ' text column = name of the linked ' . $k, '0 mismatches', count($bad) . ' mismatch(es)', $bad === [] ? 'PASS' : 'FAIL', $bad,
            'Some engines (e.g. holiday scoping by branch) still read the text copy. When it differs from the linked register, those features treat the employee as a different branch/department.');
    }
});
$run('People', 'Directory', 'Team and reporting manager belong to the employee company', function () use ($active, $TM, $emps, $row, $code, $cName, $B, $lc) {
    $eById = []; foreach ($emps as $x) { $eById[(string) $x['id']] = $x; }
    $badT = []; $badM = []; $badTB = [];
    foreach ($active as $e) {
        $co = (string) ($e['employer_company_id'] ?? '');
        $t = $TM[(string) ($e['team_id'] ?? '')] ?? null;
        if ($t !== null && (string) $t['employer_company_id'] !== '' && (string) $t['employer_company_id'] !== $co) { $badT[] = $code($e) . ': team "' . $t['name'] . '" is ' . $cName($t['employer_company_id']) . ', employee is ' . $cName($co); }
        if ($t !== null) {
            foreach ($B as $b) {
                if (str_contains($lc($t['name']), $lc($b['name'])) && (string) $b['id'] !== (string) ($e['branch_id'] ?? '')) { $badTB[] = $code($e) . ': team "' . $t['name'] . '" but branch ' . ($B[(string) ($e['branch_id'] ?? '')]['name'] ?? '-'); }
            }
        }
        $m = $eById[(string) ($e['reporting_manager_id'] ?? '')] ?? null;
        if ($m !== null && (string) ($m['employer_company_id'] ?? '') !== $co) { $badM[] = $code($e) . ' (' . $cName($co) . ') reports to ' . $code($m) . ' (' . $cName($m['employer_company_id'] ?? '') . ')'; }
    }
    $row('People', 'Directory', 'Team', 'Employee team belongs to the employee company', '0', (string) count($badT), $badT === [] ? 'PASS' : 'FAIL', $badT);
    $row('People', 'Directory', 'Team vs branch', 'Branch-named team matches the employee branch', '0', (string) count($badTB), $badTB === [] ? 'PASS' : 'WARN', $badTB, 'Teams are named after branches (e.g. "KONDAPUR Collections"); an employee in another branch on that team is likely mis-assigned.');
    $row('People', 'Hierarchy', 'Reporting manager', 'Reporting manager is in the same company', '0 cross-company (or deliberate)', (string) count($badM), $badM === [] ? 'PASS' : 'WARN', $badM);
});
$run('People', 'Directory', 'PT state = branch state', function () use ($active, $B, $row, $code, $lc) {
    $bad = [];
    foreach ($active as $e) {
        $bs = (string) ($B[(string) ($e['branch_id'] ?? '')]['state'] ?? '');
        $pt = trim((string) ($e['pt_state'] ?? ''));
        if ($bs !== '' && $pt !== '' && $lc($bs) !== $lc($pt)) { $bad[] = $code($e) . ': PT state ' . $pt . ', branch state ' . $bs; }
    }
    $row('Statutory', 'Professional Tax', 'Employees', 'Employee PT state matches branch state', '0 mismatches', count($bad) . ' mismatch(es)', $bad === [] ? 'PASS' : 'FAIL', $bad, 'PT is levied by the state of the place of work.');
});
$run('Time & Attendance', 'Shifts / Roster', 'Shift resolves for every employee and belongs to their company', function () use ($active, $store, $row, $code, $cName, $lc, $today, $S) {
    $sh = new Shifts($store);
    $none = []; $cross = []; $txt = [];
    foreach ($active as $e) {
        $s = $sh->for($e, $today);
        if ($s === null) { $none[] = $code($e); continue; }
        if (trim((string) ($s['employer_company_id'] ?? '')) !== '' && (string) $s['employer_company_id'] !== (string) ($e['employer_company_id'] ?? '')) { $cross[] = $code($e) . ' (' . $cName($e['employer_company_id'] ?? '') . ') on shift ' . $s['name'] . ' of ' . $cName($s['employer_company_id']); }
        $t = trim((string) ($e['shift'] ?? ''));
        if ($t !== '' && $t !== (string) ($s['id'] ?? '') && $lc($t) !== $lc($s['name'] ?? '') && $lc($t) !== $lc($s['code'] ?? '')) { $txt[] = $code($e) . ': Directory shift "' . ($S[$t]['name'] ?? $t) . '" but shift applied today is "' . ($s['name'] ?? '') . '" (roster / scoped shift)'; }
        if ($t !== '' && ctype_digit($t) && !isset($S[$t])) { $txt[] = $code($e) . ': Directory shift id ' . $t . ' does not exist'; }
    }
    $row('Time & Attendance', 'Shifts / Roster', 'Employees', 'A shift resolves for every active employee today', '0 without shift', count($none) . ' without (default window used)', $none === [] ? 'PASS' : 'WARN', $none);
    $row('Time & Attendance', 'Shifts / Roster', 'Employees', 'Resolved shift belongs to the employee company', '0', (string) count($cross), $cross === [] ? 'PASS' : 'FAIL', $cross);
    $row('Time & Attendance', 'Shifts / Roster', 'Employees', 'Shift on the Directory = shift actually applied today', '0', (string) count($txt), $txt === [] ? 'PASS' : 'WARN', $txt);
});
$run('Time & Attendance', 'Late Login Policy', 'Late policy applies to every employee', function () use ($active, $store, $row, $code) {
    $lp = new LatePolicy($store); $none = [];
    foreach ($active as $e) { if ($lp->for($e) === null) { $none[] = $code($e); } }
    $row('Time & Attendance', 'Late Login Policy', 'Employees', 'A late-login policy covers every active employee', '0 uncovered', count($none) . ' uncovered', $none === [] ? 'PASS' : 'WARN', $none);
});
$run('Time & Attendance', 'Holidays', 'Every employee receives the holiday calendar', function () use ($active, $calendar, $companies, $designations, $row, $code, $year) {
    $cn = []; foreach ($companies as $c) { $cn[(string) $c['id']] = (string) $c['name']; }
    $dr = []; foreach ($designations as $g) { $dr[(string) $g['id']] = (string) ($g['role'] ?? ''); }
    $none = [];
    foreach ($active as $e) { if ($calendar->holidaysForEmployment($e, $cn, $dr, $year) === []) { $none[] = $code($e); } }
    $row('Time & Attendance', 'Holidays', 'Employees', 'Every active employee gets at least one holiday in ' . $year, '0 with no holidays', count($none) . ' with none', $none === [] ? 'PASS' : 'FAIL', $none,
        'Holiday scope is matched on company, branch text, role… — an employee whose fields do not match any holiday row works every holiday on paper.');
});
$run('People', 'Probation', 'Probation end date follows the company policy', function () use ($active, $probation, $row, $code, $cName) {
    $bad = []; $n = 0;
    foreach ($active as $e) {
        if (!$probation->onProbation($e)) { continue; }
        $n++;
        $cfg = $probation->configFor($e);
        $doj = Employment::joinedOn($e);
        if ($doj === '') { $bad[] = $code($e) . ': on probation, no joining date'; continue; }
        $expected = (new \DateTimeImmutable($doj))->modify('+' . (int) $cfg['default_months'] . ' months +' . (int) ($cfg['default_days'] ?? 0) . ' days')->format('Y-m-d');
        $set = substr(trim((string) ($e['probation_end'] ?? '')), 0, 10);
        if ($set !== '' && $set !== $expected) { $bad[] = $code($e) . ' (' . $cName($e['employer_company_id'] ?? '') . '): end ' . $set . ', policy gives ' . $expected . ' (override/extension?)'; }
    }
    $row('People', 'Probation', 'Employees on probation (' . $n . ')', 'Probation end = joining date + company probation period', '0 differences (or recorded extensions)', count($bad) . ' difference(s)', $bad === [] ? 'PASS' : 'WARN', $bad);
});
$run('Statutory', 'PF / ESI', 'PF/ESI enrolment data', function () use ($active, $row, $code, $blank) {
    $pfNoUan = []; $esiNo = [];
    foreach ($active as $e) {
        if ((string) ($e['pf_applicable'] ?? '') === '1' && $blank($e['uan'] ?? null)) { $pfNoUan[] = $code($e); }
        if ((string) ($e['esi_applicable'] ?? '') === '1' && $blank($e['esic_no'] ?? null)) { $esiNo[] = $code($e); }
    }
    $row('Statutory', 'PF / ESI', 'Employees', 'PF-applicable employees have a UAN', '0 missing', count($pfNoUan) . ' missing', $pfNoUan === [] ? 'PASS' : 'WARN', $pfNoUan, 'ECR needs a UAN for every member.');
    $row('Statutory', 'PF / ESI', 'Employees', 'ESI-applicable employees have an ESIC number', '0 missing', count($esiNo) . ' missing', $esiNo === [] ? 'PASS' : 'WARN', $esiNo);
});
$run('People', 'Directory', 'Employee company time zone', function () use ($active, $C, $row, $code) {
    $bad = array_map($code, array_values(array_filter($active, static fn ($e): bool => trim((string) ($C[(string) ($e['employer_company_id'] ?? '')]['timezone'] ?? '')) === '')));
    $row('People', 'Directory', 'Employees', 'Every employee\'s company has a time zone', '0', (string) count($bad), $bad === [] ? 'PASS' : 'FAIL', $bad);
});
$run('People', 'Directory', 'Unique device ids / codes', function () use ($emps, $row, $code, $lc) {
    foreach (['device_user_id' => 'Biometric device user id', 'emp_code' => 'Employee ID'] as $k => $l) {
        $vals = array_filter(array_map(static fn ($e) => $lc($e[$k] ?? ''), $emps));
        $d = array_keys(array_filter(array_count_values($vals), static fn ($n) => $n > 1));
        $row('People', 'Directory', $l, $l . ' is unique', '0 duplicates', count($d) . ' duplicate(s)', $d === [] ? 'PASS' : 'FAIL', $d);
    }
});
$run('Administration', 'Users', 'Login role vs designation role', function () use ($q, $emps, $G, $row, $lc) {
    $eById = []; foreach ($emps as $x) { $eById[(string) $x['id']] = $x; }
    $diff = [];
    foreach ($q('SELECT * FROM users WHERE tenant_id = :T') as $u) {
        $e = $eById[(string) ($u['employee_id'] ?? '')] ?? null;
        if ($e === null) { continue; }
        $role = trim((string) ($u['role'] ?? $u['status'] ?? ''));
        $dr = trim((string) ($G[(string) ($e['designation_id'] ?? '')]['role'] ?? ''));
        if ($role !== '' && $dr !== '' && $lc($role) !== $lc($dr) && !in_array($lc($role), ['administrator', 'company admin'], true)) { $diff[] = ($u['email'] ?? '#' . $u['id']) . ': login role "' . $role . '", designation role "' . $dr . '"'; }
    }
    $row('Administration', 'Users / Roles & Permissions', 'Linked logins', 'Login role matches the employee\'s designation role', '0 differences (or deliberate)', count($diff) . ' difference(s)', $diff === [] ? 'PASS' : 'WARN', $diff);
});

// ================= ATTENDANCE DATA vs EMPLOYEES / CLOCK =================
$run('Time & Attendance', 'Attendance Logs', 'Punch data integrity', function () use ($q, $emps, $row, $code) {
    $codes = []; foreach ($emps as $e) { $codes[strtolower($code($e))] = true; }
    foreach ($q("SELECT emp_code, COUNT(*) n FROM attendance_logs WHERE tenant_id = :T GROUP BY emp_code") as $r) { if (!isset($codes[strtolower((string) $r['emp_code'])])) { $unknown[] = $r['emp_code'] . ' (' . $r['n'] . ' punches)'; } }
    $unknown ??= [];
    $row('Time & Attendance', 'Attendance Logs', 'Punches', 'Every punch belongs to an employee on file', '0 unknown codes', count($unknown) . ' unknown code(s)', $unknown === [] ? 'PASS' : 'WARN', $unknown);
    $future = $q("SELECT emp_code, punch_at, source FROM attendance_logs WHERE tenant_id = :T AND punch_at > NOW() ORDER BY punch_at LIMIT 200");
    $row('Time & Attendance', 'Attendance Logs', 'Punches', 'No punch is later than now (company time)', '0', count($future) . ' future punch(es)', $future === [] ? 'PASS' : 'FAIL', array_map(static fn ($f): string => $f['emp_code'] . ' ' . $f['punch_at'] . ' ' . $f['source'], $future));
    $mis = $q("SELECT emp_code, punch_at, log_date FROM attendance_logs WHERE tenant_id = :T AND log_date <> DATE(punch_at) LIMIT 200");
    $row('Time & Attendance', 'Attendance Logs', 'Punches', 'Punch date column = date of the punch time', '0', count($mis) . ' mismatch(es)', $mis === [] ? 'PASS' : 'FAIL', array_map(static fn ($f): string => $f['emp_code'] . ' ' . $f['punch_at'] . ' stored on ' . $f['log_date'], $mis));
    $dup = $q("SELECT emp_code, punch_at, direction, COUNT(*) n FROM attendance_logs WHERE tenant_id = :T GROUP BY emp_code, punch_at, direction HAVING COUNT(*) > 1 LIMIT 300");
    $row('Time & Attendance', 'Attendance Logs', 'Punches', 'No duplicate punch (same employee, time, direction)', '0', count($dup) . ' duplicated punch(es)', $dup === [] ? 'PASS' : 'FAIL', array_map(static fn ($f): string => $f['emp_code'] . ' ' . $f['punch_at'] . ' ' . $f['direction'] . ' ×' . $f['n'], $dup),
        'Run php tools\\db\\dedupe-punches.php --apply to clear them (keeps the first, backs up the rest).');
    $unl = $q("SELECT emp_code, punch_at, direction FROM attendance_logs WHERE tenant_id = :T AND source = 'manual' AND (external_id IS NULL OR external_id = '') LIMIT 300");
    $row('Time & Attendance', 'Attendance Regularization', 'Manual punches', 'Every manual punch is linked to an approved regularization', '0 unlinked', count($unl) . ' unlinked', $unl === [] ? 'PASS' : 'FAIL', array_map(static fn ($f): string => $f['emp_code'] . ' ' . $f['punch_at'] . ' ' . $f['direction'], $unl),
        'Run php tools\\db\\dedupe-punches.php --apply to link them.');
});
$run('Payroll', 'Salary structure', 'Salary component rules reference live scopes', function () use ($q, $row) {
    $n = $q('SELECT COUNT(*) n FROM salary_component_rules WHERE tenant_id = :T')[0]['n'] ?? 0;
    $row('Organization Setup', 'Salary structure', 'Component rules', 'Salary component rules on file', '≥ 1', $n . ' rule row(s) (scope names checked under Data integrity)', (int) $n > 0 ? 'PASS' : 'FAIL');
});

file_put_contents(realpath(__DIR__ . '/../..') . '/storage/audit/checks.json', json_encode(['generated_at' => date('Y-m-d H:i:s'), 'timezone' => date_default_timezone_get(), 'rows' => $A], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
$cnt = array_count_values(array_column($A, 'result'));
echo '<pre id="au">checks=' . count($A) . ' ' . json_encode($cnt) . '</pre>';
