<?php declare(strict_types=1);
// DEV ONLY (owner, 24 Oct): the employees the dashboard counts as "(not set)" — Staff Type (type), PF / ESIC Applicable
// and (for the system Company Admin record) branch / department. Shows the values in use and, with ?go=1, fills only
// the BLANK fields: PF / ESIC from the Government rule on the employee's own salary (governmentStatutory(), the same
// answer the Directory shows), Staff Type Office, and ADM001's branch / department. Nothing already set is changed.
// Delete this file after.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
$nsGo = ($_GET['go'] ?? '') === '1';
$nsVals = ['type' => [], 'pf_applicable' => [], 'esi_applicable' => []];
$all = $store->all('employees');
foreach ($all as $e) { foreach (array_keys($nsVals) as $c) { $v = (string) ($e[$c] ?? ''); $nsVals[$c][$v] = ($nsVals[$c][$v] ?? 0) + 1; } }
// The spelling the register already uses for yes / no in each column (the most common one).
$spell = static function (string $col, bool $yes) use ($nsVals): string {
    $best = ''; $n = -1;
    foreach ($nsVals[$col] as $v => $c) {
        $isYes = in_array(strtolower((string) $v), ['1', 'yes', 'y', 'true'], true);
        $isNo = in_array(strtolower((string) $v), ['0', 'no', 'n', 'false'], true);
        if ((($yes && $isYes) || (!$yes && $isNo)) && $c > $n) { $best = (string) $v; $n = $c; }
    }
    if ($best !== '') { return $best; }
    // ponytail: no value of that kind on file yet — a numeric column (TINYINT pf_applicable) takes 1 / 0.
    $numeric = $nsVals[$col] !== [] && array_filter(array_keys($nsVals[$col]), static fn ($v) => $v !== '' && !is_numeric((string) $v)) === [];
    return $numeric ? ($yes ? '1' : '0') : ($yes ? 'Yes' : 'No');
};
$byName = static function (string $t, string $name) use ($store): string {
    foreach (liveRows($store, $t) as $r) { if (strcasecmp(trim((string) ($r['name'] ?? '')), $name) === 0) { return (string) $r['id']; } }
    return '';
};
$nsOut = [];
foreach ($all as $e) {
    $put = [];
    $gov = null;
    foreach (['pf_applicable' => 'PF', 'esi_applicable' => 'ESI'] as $col => $head) {
        if (trim((string) ($e[$col] ?? '')) === '') {
            $gov ??= governmentStatutory($e);
            isset($gov[$head]) && $put[$col] = $spell($col, strtolower((string) $gov[$head]) === 'yes');
        }
    }
    trim((string) ($e['type'] ?? '')) === '' && $put['type'] = 'Office';
    if (SmartPRS\Surface\Employment::code($e) === 'ADM001') {
        trim((string) ($e['branch_id'] ?? '')) === '' && ($b = $byName('branches', 'KONDAPUR')) !== '' && $put['branch_id'] = $b;
        trim((string) ($e['department_id'] ?? '')) === '' && ($d = $byName('departments', 'Administration')) !== '' && $put['department_id'] = $d;
    }
    if ($put === []) { continue; }
    $nsOut[] = SmartPRS\Surface\Employment::code($e) . ' ' . (string) ($e['name'] ?? '') . ': ' . json_encode($put);
    if ($nsGo) {
        $store->put('employees', ['id' => $e['id']] + $put);
        $audit('employee.updated', 'employees', (string) $e['id'], array_intersect_key($e, $put), $put, 'Blank fields filled (dashboard "not set")');
    }
}
?><pre id="ns"><?= e(($nsGo ? "APPLIED\n" : "DRY RUN\n") . 'values in use: ' . json_encode($nsVals) . "\n" . implode("\n", $nsOut)) ?></pre>
