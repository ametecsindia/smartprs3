<?php declare(strict_types=1);
// DEV ONLY (owner, 16 Oct 2026 night): full field-level test run. READ-ONLY for the database.
//   ?r=dev-fieldtest-h&masters=1     org masters as JSON (for the browser harness)
//   POST ft_kind=res  ft_part,payload  -> storage/audit/fieldtest/res-<part>.json
//   POST ft_kind=shot ft_id,data       -> storage/audit/fieldtest/shots/<id>.jpg
// Delete with the other dev-* screens when testing is done.
if (!isAdministrator((string) ($session->user()['role'] ?? ''))) { echo 'administrator only'; return; }
$ftDir = realpath(__DIR__ . '/../..') . '/storage/audit/fieldtest';
is_dir($ftDir . '/shots') || mkdir($ftDir . '/shots', 0775, true);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['ft_kind'])) {
    $ftKind = (string) $_POST['ft_kind'];
    if ($ftKind === 'shot') {
        $ftId = preg_replace('/[^a-z0-9-]/', '', (string) ($_POST['ft_id'] ?? ''));
        $ftData = (string) ($_POST['data'] ?? '');
        $ftBin = base64_decode((string) preg_replace('#^data:image/\w+;base64,#', '', $ftData), true);
        $ftOk = $ftId !== '' && $ftBin !== false && file_put_contents($ftDir . '/shots/' . $ftId . '.jpg', $ftBin) !== false;
    } else {
        $ftPart = preg_replace('/[^a-z0-9-]/', '', (string) ($_POST['ft_part'] ?? ''));
        $ftOk = $ftPart !== '' && file_put_contents($ftDir . '/res-' . $ftPart . '.json', (string) ($_POST['payload'] ?? '')) !== false;
    }
    echo '<pre id="ft">' . ($ftOk ? 'saved' : 'failed') . '</pre>';
    return;
}

if (($_GET['masters'] ?? '') === '1') {
    $ftT = (string) $tenantId;
    $ftQ = static function (string $sql) use ($pdo, $ftT): array {
        $s = $pdo->prepare($sql);
        $s->execute([$ftT]);
        return $s->fetchAll(\PDO::FETCH_ASSOC);
    };
    $ftOut = [
        'companies' => $ftQ('SELECT id, name, state, timezone FROM companies WHERE tenant_id = ? AND deleted_at IS NULL'),
        'branches' => $ftQ('SELECT id, name, city, state, employer_company_id FROM branches WHERE tenant_id = ? AND deleted_at IS NULL'),
        'departments' => $ftQ('SELECT id, name, employer_company_id, branch_id FROM departments WHERE tenant_id = ? AND deleted_at IS NULL'),
        'designations' => $ftQ('SELECT id, name, employer_company_id, department_id, role FROM designations WHERE tenant_id = ? AND deleted_at IS NULL'),
        'teams' => $ftQ('SELECT id, name, employer_company_id FROM teams WHERE tenant_id = ? AND deleted_at IS NULL'),
        'shifts' => $ftQ('SELECT id, name, employer_company_id FROM shifts WHERE tenant_id = ?'),
        'leave_types' => $ftQ('SELECT id, name FROM leave_types WHERE tenant_id = ?'),
        'employees' => $ftQ('SELECT id, emp_code, name, employer_company_id, branch, status FROM employees WHERE tenant_id = ? AND deleted_at IS NULL'),
    ];
    echo '<pre id="ft">' . e(json_encode($ftOut, JSON_UNESCAPED_UNICODE)) . '</pre>';
    return;
}
if (($_GET['run'] ?? '') === 'inspect') {
    // read-only: the newest rows of the tables the server-side validation test touched
    $ftOut = [];
    foreach (['attendance_logs', 'attendance_corrections', 'messages_log', 'sms_settings', 'wa_settings'] as $t) {
        $ftOut[$t] = $pdo->query('SELECT * FROM `' . $t . '` WHERE tenant_id = ' . $pdo->quote((string) $tenantId) . ' ORDER BY id DESC LIMIT 3')->fetchAll(\PDO::FETCH_ASSOC);
    }
    foreach ($ftOut as &$rows) { foreach ($rows as &$r) { foreach ($r as $k => $v) { if (str_contains($k, 'key')) { $r[$k] = $v === null || $v === '' ? $v : '(set)'; } } } }
    unset($rows, $r);
    file_put_contents($ftDir . '/inspect.json', json_encode($ftOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo '<pre id="ft">inspect</pre>';
    return;
}
if (($_GET['run'] ?? '') === 'zip') {
    // one archive of every result and screenshot of this run, so it can be fetched in one go
    @set_time_limit(0);
    session_write_close();
    $z = new \ZipArchive();
    $zf = dirname($ftDir) . '/fieldtest.zip';
    @unlink($zf);
    $ok = $z->open($zf, \ZipArchive::CREATE) === true;
    $n = 0;
    foreach (array_merge(glob($ftDir . '/*.json') ?: [], glob($ftDir . '/shots/*.jpg') ?: []) as $f) {
        $ok && $z->addFile($f, substr($f, strlen($ftDir) + 1)) && $n++;
    }
    $ok && $z->close();
    echo '<pre id="ft">zip ' . $n . ' ' . (is_file($zf) ? filesize($zf) : 0) . '</pre>';
    return;
}
if (($_GET['run'] ?? '') === 'payroll') {
    // every stored payroll run: reproduce it with Register::verify() and dump its rows for the arithmetic /
    // statutory checks. Reads only.
    @set_time_limit(0);
    session_write_close();
    $ftRuns = [];
    foreach ($register->runs() as $run) {
        $p = (string) $run['period'];
        try { $v = $register->verify($p); } catch (\Throwable $x) { $v = ['reproduced' => false, 'why' => 'verify threw: ' . $x->getMessage()]; }
        $ftRuns[] = ['period' => $p, 'status' => $run['status'] ?? '', 'employee_count' => $run['employee_count'] ?? 0,
            'net' => $run['net'] ?? '', 'earnings' => $run['earnings'] ?? '', 'deductions' => $run['deductions'] ?? '',
            'closed_at' => $run['closed_at'] ?? '', 'verify' => $v, 'rows' => $run['rows'] ?? [], 'inputs' => $run['inputs'] ?? []];
    }
    $ftEmp = [];
    foreach ($pdo->query('SELECT e.emp_code, e.name, e.gender, e.branch, e.ctc, e.pt_state, e.pf_applicable, e.esi_applicable, e.physically_challenged, b.state AS branch_state FROM employees e LEFT JOIN branches b ON b.id = e.branch_id WHERE e.tenant_id = ' . $pdo->quote((string) $tenantId))->fetchAll(\PDO::FETCH_ASSOC) as $r) { $ftEmp[(string) $r['emp_code']] = $r; }
    file_put_contents($ftDir . '/payroll.json', json_encode(['runs' => $ftRuns, 'employees' => $ftEmp], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    echo '<pre id="ft">payroll ' . count($ftRuns) . '</pre>';
    return;
}
if (($_GET['run'] ?? '') === 'checks') {
    // the project's self-check scripts (each uses in-memory stores / temp files; nothing touches the database)
    @set_time_limit(0);
    session_write_close();
    $ftRoot = realpath(__DIR__ . '/../..');
    $ftPhp = '';
    foreach (glob('C:/laragon/bin/php/*/php.exe') ?: [] as $c) { $ftPhp = $c; }
    $ftRes = [];
    foreach (glob($ftRoot . '/tests/*_check.php') ?: [] as $f) {
        $t0 = microtime(true);
        $p = proc_open('"' . $ftPhp . '" -d zend.assertions=1 -d assert.exception=1 tests/' . basename($f), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $ftRoot);
        if (!is_resource($p)) { $ftRes[] = ['file' => basename($f), 'exit' => -1, 'out' => 'could not start']; continue; }
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        $code = proc_close($p);
        $ftRes[] = ['file' => basename($f), 'exit' => $code, 'ms' => (int) ((microtime(true) - $t0) * 1000), 'out' => substr($out, -4000)];
    }
    file_put_contents($ftDir . '/checks.json', json_encode($ftRes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo '<pre id="ft">checks ' . count($ftRes) . '</pre>';
    return;
}
echo '<pre id="ft">ready</pre>';
