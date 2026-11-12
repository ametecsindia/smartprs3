<?php declare(strict_types=1);
// DEV ONLY (owner, 16 Oct 2026): READ-ONLY audit of Organization Configuration against the whole
// application. Nothing in the database is written. Output goes to storage/audit/*.json.
//   ?r=dev-audit-org            server-side dump + checks  -> storage/audit/server.json
//   POST audit_part + payload   browser crawl results      -> storage/audit/crawl-<part>.json
// Delete after the audit.
if (!isAdministrator((string) ($session->user()['role'] ?? ''))) { echo 'administrator only'; return; }
@set_time_limit(300);
$auDir = realpath(__DIR__ . '/../..') . '/storage/audit';
is_dir($auDir) || mkdir($auDir, 0775, true);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['audit_part'])) {
    $auPart = preg_replace('/[^a-z0-9-]/', '', (string) $_POST['audit_part']);
    file_put_contents($auDir . '/crawl-' . $auPart . '.json', (string) ($_POST['payload'] ?? ''));
    echo '<pre id="au">saved ' . e($auPart) . ' ' . strlen((string) ($_POST['payload'] ?? '')) . '</pre>';
    return;
}

$auT = (string) $tenantId;
$auQ = static function (string $sql, array $args = []) use ($pdo): array {
    $s = $pdo->prepare($sql);
    $s->execute($args);
    return $s->fetchAll(\PDO::FETCH_ASSOC);
};
$auOut = ['generated_at' => date('Y-m-d H:i:s'), 'timezone' => date_default_timezone_get(), 'tenant_id' => $auT, 'schema' => [], 'org' => [], 'refs' => [], 'consistency' => []];

// ---- schema: every table, its columns, this tenant's live row count ----
$auDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$auCols = [];
foreach ($auQ('SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION', [$auDb]) as $r) {
    $auCols[$r['t']][] = $r['c'];
}
foreach ($auCols as $t => $cs) {
    $hasT = in_array('tenant_id', $cs, true);
    $live = in_array('deleted_at', $cs, true) ? ' AND deleted_at IS NULL' : '';
    try {
        $n = (int) $pdo->query('SELECT COUNT(*) FROM `' . $t . '` WHERE ' . ($hasT ? 'tenant_id = ' . $pdo->quote($auT) : '1=1') . $live)->fetchColumn();
    } catch (\Throwable $x) { $n = -1; error_log('audit count ' . $t . ': ' . $x->getMessage()); /* tolerated: reported as -1 */ }
    $auOut['schema'][$t] = ['columns' => $cs, 'tenant_scoped' => $hasT, 'rows' => $n];
}

// ---- organization configuration tables, every row and field ----
$auOrgTables = ['companies', 'branches', 'departments', 'designations', 'teams', 'shifts', 'holidays', 'pay_cycles', 'late_policies',
    'devices', 'biometric_configs', 'geofence_rules', 'incentive_schemes', 'payslip_formats', 'late_policy', 'pt_slabs', 'pf_esi_settings', 'leave_types', 'api_keys'];
$auHide = ['password', 'api_key', 'comm_key', 'secret', 'token'];
foreach ($auOrgTables as $t) {
    if (!isset($auCols[$t])) { continue; }
    $w = in_array('tenant_id', $auCols[$t], true) ? 'tenant_id = ' . $pdo->quote($auT) : '1=1';
    $rows = $pdo->query('SELECT * FROM `' . $t . '` WHERE ' . $w . ' ORDER BY id LIMIT 2000')->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { foreach ($r as $k => $v) { foreach ($auHide as $h) { if (str_contains($k, $h) && $v !== null && $v !== '') { $r[$k] = '(set)'; } } } }
    unset($r);
    $auOut['org'][$t] = $rows;
}

// ---- generic references: every *_id column pointing at an org master or an employee ----
$auTarget = ['employer_company_id' => 'companies', 'company_id' => 'companies', 'branch_id' => 'branches', 'department_id' => 'departments',
    'designation_id' => 'designations', 'team_id' => 'teams', 'shift_id' => 'shifts', 'employee_id' => 'employees', 'manager_id' => 'employees',
    'reporting_manager_id' => 'employees', 'team_lead_id' => 'employees', 'leader_id' => 'employees', 'head_employee_id' => 'employees'];
$auLiveIds = [];
foreach (array_unique(array_values($auTarget)) as $m) {
    if (!isset($auCols[$m])) { continue; }
    $live = in_array('deleted_at', $auCols[$m], true) ? ' AND deleted_at IS NULL' : '';
    $auLiveIds[$m] = array_flip(array_map('strval', $pdo->query('SELECT id FROM `' . $m . '` WHERE tenant_id = ' . $pdo->quote($auT) . $live)->fetchAll(\PDO::FETCH_COLUMN)));
}
foreach ($auCols as $t => $cs) {
    if (!in_array('tenant_id', $cs, true)) { continue; }
    $live = in_array('deleted_at', $cs, true) ? ' AND deleted_at IS NULL' : '';
    foreach ($auTarget as $col => $m) {
        if (!in_array($col, $cs, true) || !isset($auLiveIds[$m]) || $t === $m && $col === 'id') { continue; }
        $vals = $pdo->query('SELECT id, `' . $col . '` v FROM `' . $t . '` WHERE tenant_id = ' . $pdo->quote($auT) . $live . ' AND `' . $col . '` IS NOT NULL AND `' . $col . "` <> '' AND `" . $col . "` <> '0'")->fetchAll(\PDO::FETCH_ASSOC);
        $bad = [];
        foreach ($vals as $v) {
            foreach (preg_split('/\s*,\s*/', (string) $v['v']) as $one) {   // some scope columns hold several ids
                if ($one !== '' && ctype_digit($one) && !isset($auLiveIds[$m][$one])) { $bad[] = $t . '#' . $v['id'] . ' → ' . $m . '#' . $one; }
            }
        }
        $auOut['refs'][] = ['table' => $t, 'column' => $col, 'target' => $m, 'checked' => count($vals), 'broken' => count($bad), 'evidence' => array_slice($bad, 0, 40)];
    }
}

// ---- hierarchy consistency inside any row: branch/department/designation/team belong to the row's company ----
$auParent = [];
foreach (['branches', 'departments', 'designations', 'teams', 'shifts'] as $m) {
    if (!isset($auCols[$m])) { continue; }
    foreach ($pdo->query('SELECT * FROM `' . $m . '` WHERE tenant_id = ' . $pdo->quote($auT))->fetchAll(\PDO::FETCH_ASSOC) as $r) { $auParent[$m][(string) $r['id']] = $r; }
}
$auName = static fn (string $m, string $id): string => $id === '' ? '' : (string) ($auParent[$m][$id]['name'] ?? ($m . '#' . $id));
$auCompanyName = [];
foreach ($auOut['org']['companies'] ?? [] as $c) { $auCompanyName[(string) $c['id']] = (string) $c['name']; }
foreach ($auCols as $t => $cs) {
    if (!in_array('tenant_id', $cs, true) || !in_array('employer_company_id', $cs, true)) { continue; }
    $probe = array_values(array_intersect(['branch_id', 'department_id', 'designation_id', 'team_id'], $cs));
    if ($probe === [] ) { continue; }
    $live = in_array('deleted_at', $cs, true) ? ' AND deleted_at IS NULL' : '';
    $rows = $pdo->query('SELECT id, employer_company_id, ' . implode(', ', $probe) . (in_array('emp_code', $cs, true) ? ', emp_code' : '') . ' FROM `' . $t . '` WHERE tenant_id = ' . $pdo->quote($auT) . $live)->fetchAll(\PDO::FETCH_ASSOC);
    $bad = [];
    $map = ['branch_id' => 'branches', 'department_id' => 'departments', 'designation_id' => 'designations', 'team_id' => 'teams'];
    foreach ($rows as $r) {
        $co = (string) ($r['employer_company_id'] ?? '');
        if ($co === '') { continue; }
        foreach ($probe as $p) {
            $id = (string) ($r[$p] ?? '');
            if ($id === '' || $id === '0' || !ctype_digit($id) || !isset($auParent[$map[$p]][$id])) { continue; }
            $pc = (string) ($auParent[$map[$p]][$id]['employer_company_id'] ?? '');
            if ($pc !== '' && $pc !== $co) {
                $bad[] = $t . '#' . $r['id'] . (isset($r['emp_code']) ? ' (' . $r['emp_code'] . ')' : '') . ': company ' . ($auCompanyName[$co] ?? $co) . ' but ' . substr($p, 0, -3) . ' "' . $auName($map[$p], $id) . '" belongs to ' . ($auCompanyName[$pc] ?? $pc);
            }
        }
        // department under the row's branch, designation under the row's department
        $br = (string) ($r['branch_id'] ?? ''); $dp = (string) ($r['department_id'] ?? ''); $dg = (string) ($r['designation_id'] ?? '');
        if ($dp !== '' && $br !== '' && isset($auParent['departments'][$dp]) && (string) ($auParent['departments'][$dp]['branch_id'] ?? '') !== '' && (string) $auParent['departments'][$dp]['branch_id'] !== $br) {
            $bad[] = $t . '#' . $r['id'] . (isset($r['emp_code']) ? ' (' . $r['emp_code'] . ')' : '') . ': department "' . $auName('departments', $dp) . '" is set up under branch "' . $auName('branches', (string) $auParent['departments'][$dp]['branch_id']) . '", row is in branch "' . $auName('branches', $br) . '"';
        }
        if ($dg !== '' && $dp !== '' && isset($auParent['designations'][$dg]) && (string) ($auParent['designations'][$dg]['department_id'] ?? '') !== '' && (string) $auParent['designations'][$dg]['department_id'] !== $dp) {
            $bad[] = $t . '#' . $r['id'] . (isset($r['emp_code']) ? ' (' . $r['emp_code'] . ')' : '') . ': designation "' . $auName('designations', $dg) . '" is set up under department "' . $auName('departments', (string) $auParent['designations'][$dg]['department_id']) . '", row is in department "' . $auName('departments', $dp) . '"';
        }
    }
    $auOut['consistency'][] = ['table' => $t, 'checked' => count($rows), 'broken' => count($bad), 'evidence' => array_slice($bad, 0, 60)];
}

// holidays, weekly off and pay cycles live in customisation, not tables
$auOut['org']['holidays_' . date('Y')] = array_map(static fn ($d, $n): array => ['date' => $d, 'name' => $n], array_keys($calendar->holidays((int) date('Y'))), array_values($calendar->holidays((int) date('Y'))));
$auOut['org']['weekly_off'] = [['weekly_off' => $calendar->weeklyOffLabel()]];
$auOut['org']['pay_cycle_rules'] = array_map(static fn ($r): array => array_filter($r, static fn ($v): bool => !is_array($v)), $payCycles->rules());
file_put_contents($auDir . '/server.json', json_encode($auOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
echo '<pre id="au">done tables=' . count($auOut['schema']) . ' org=' . count($auOut['org']) . ' refs=' . count($auOut['refs']) . ' consistency=' . count($auOut['consistency']) . '</pre>';
