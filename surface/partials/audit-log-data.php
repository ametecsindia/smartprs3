<?php declare(strict_types=1);
/**
 * AUDIT LOG ROWS (owner, 22 Oct 2026: one page under Administration — who signed in when, who added, changed or
 * deleted what, in which module, which field, from what value to what). Read from the hash-chained trail every
 * $audit() call writes. Included by surface/screens/audit-log.php ($params = $_GET) and
 * surface/actions/audit-log-export.php ($params = $_POST), so the Excel file is exactly the rows shown.
 * Sets $alFrom, $alTo, $alCat, $alMod, $alBy, $alWord, $alKeep, $alRows, $alCats, $alMods, $alActors.
 */
use SmartPRS\Surface\Dates;
use SmartPRS\Surface\Employment;

$alIso = static fn (string $v): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : '';
$alTo = $alIso(trim((string) ($params['to'] ?? ''))) ?: date('Y-m-d');
$alFrom = $alIso(trim((string) ($params['from'] ?? ''))) ?: date('Y-m-d', strtotime($alTo . ' -6 days'));
$alFrom > $alTo && [$alFrom, $alTo] = [$alTo, $alFrom];
$alCat = trim((string) ($params['cat'] ?? ''));
$alMod = trim((string) ($params['mod'] ?? ''));
$alBy = trim((string) ($params['by'] ?? ''));
$alWord = mb_strtolower(trim((string) ($params['q'] ?? '')));
$alKeep = ['from' => $alFrom, 'to' => $alTo, 'cat' => $alCat, 'mod' => $alMod, 'by' => $alBy, 'q' => $alWord];

// WHAT A TABLE IS CALLED ON SCREEN: the module that keeps it, else the area its action belongs to.
$alTableLabel = [];
$alFieldLabel = [];
foreach ($modules->all() as $alM) {
    $alT = (string) ($alM['table'] ?? '');
    $alT !== '' && !isset($alTableLabel[$alT]) && $alTableLabel[$alT] = (string) ($alM['label'] ?? $alT);
    foreach ((array) ($alM['fields'] ?? []) as $alK => $alF) { $alFieldLabel[$alT][(string) $alK] = (string) ($alF['label'] ?? $alK); }
}
$alArea = ['user' => 'Users & Access', 'access' => 'Users & Access', 'employment' => 'People', 'employee' => 'People', 'attendance' => 'Time & Attendance',
    'leave' => 'Leave', 'payroll' => 'Payroll', 'tenant' => 'Organization Setup', 'setup' => 'Organization Setup', 'org' => 'Organization Setup',
    'menu' => 'Organization Setup', 'workflow' => 'Organization Setup', 'idcard' => 'ID Cards', 'report' => 'Reports', 'letter' => 'HR Letters',
    'recruitment' => 'Hiring & Onboarding', 'onboarding' => 'Hiring & Onboarding', 'kra_kpi' => 'Performance', 'request' => 'Requests & Approvals',
    'commission' => 'Compensation & Claims'];
$alModuleOf = static function (array $e) use ($alTableLabel, $alArea): string {
    $t = (string) ($e['entity_type'] ?? '');
    if (isset($alTableLabel[$t])) { return $alTableLabel[$t]; }
    $head = strtok((string) ($e['action'] ?? ''), '.') ?: '';
    return $alArea[$head] ?? ucfirst(str_replace(['_', '-'], ' ', $t !== '' ? $t : $head));
};
$alPayload = static function (array $e): array {
    $p = $e['payload'] ?? [];
    is_string($p) && $p = (array) json_decode($p, true);
    $o = $p['old_value'] ?? null; $n = $p['new_value'] ?? null;
    is_string($o) && $o = json_decode($o, true); is_string($n) && $n = json_decode($n, true);
    return [is_array($o) ? $o : null, is_array($n) ? $n : null];
};
$alCategoryOf = static function (array $e) use ($alPayload): string {
    $a = strtolower((string) ($e['action'] ?? ''));
    [$o, $n] = $alPayload($e);
    return match (true) {
        str_contains($a, 'signed_in') => 'Login',
        str_contains($a, 'signed_out') => 'Logout',
        str_contains($a, 'export') => 'Export',
        preg_match('/decided|approved|rejected|confirm|extended/', $a) === 1 => 'Approval / decision',
        preg_match('/delet|retired|archiv|discard|revoked|removed|cancel/', $a) === 1 || ($o !== null && $n === null) => 'Deleted / removed',
        preg_match('/created|added|imported|invited|applied|requested|raised|issued|resigned|rejoined/', $a) === 1 || ($o === null && $n !== null) => 'Added',
        default => 'Changed',
    };
};

// A VALUE AS PEOPLE READ IT: ids as names, ISO dates dd-mm-yyyy, lists joined.
$alNames = orgNames($store);
$alEmp = [];
foreach ($store->all('employees') as $alE) { $alL = Employment::label($alE); $alEmp[(string) $alE['id']] = $alL; $alEmp[Employment::code($alE)] = $alL; }
$alRef = ['employer_company_id' => 'companies', 'company_id' => 'companies', 'branch_id' => 'branches', 'department_id' => 'departments',
    'designation_id' => 'designations', 'team_id' => 'teams'];
$alShow = static function (string $k, $v) use ($alNames, $alEmp, $alRef): string {
    if ($v === null) { return ''; }
    if (is_bool($v)) { return $v ? 'Yes' : 'No'; }
    if (is_array($v)) { return array_is_list($v) && array_filter($v, 'is_scalar') === $v ? implode(', ', array_map('strval', $v)) : (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    $s = trim((string) $v);
    if (isset($alRef[$k]) && isset($alNames[$alRef[$k]][$s])) { return $alNames[$alRef[$k]][$s]; }
    if (in_array($k, ['employee_id', 'reporting_manager_id'], true) && isset($alEmp[$s])) { return $alEmp[$s]; }
    return preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $s) === 1 ? Dates::dmy(substr($s, 0, 10)) . substr($s, 10) : $s;
};
$alNoise = ['id', 'tenant_id', 'created_at', 'updated_at', 'deleted_at', 'hash', 'prev_hash'];
$alChanges = static function (array $e) use ($alPayload, $alShow, $alNoise, $alFieldLabel): array {
    [$o, $n] = $alPayload($e);
    $labels = $alFieldLabel[(string) ($e['entity_type'] ?? '')] ?? [];
    $out = [];
    foreach (array_unique(array_merge(array_keys($o ?? []), array_keys($n ?? []))) as $k) {
        $k = (string) $k;
        if (in_array($k, $alNoise, true)) { continue; }
        $was = $alShow($k, $o[$k] ?? null); $now = $alShow($k, $n[$k] ?? null);
        if ($was === $now || ($o !== null && $n !== null && !array_key_exists($k, $n))) { continue; }
        $out[] = ['field' => $labels[$k] ?? ucfirst(str_replace('_', ' ', preg_replace('/_id$/', '', $k) ?? $k)), 'old' => $was, 'new' => $now];
    }
    return $out;
};
$alWhen = static function (array $e): string {
    // Stored without an offset (MySQL DATETIME) = UTC, the server clock the trail writes on.
    $at = (string) ($e['occurred_at'] ?? '');
    try { return (new DateTimeImmutable($at, preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $at) === 1 ? null : new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('d-m-Y H:i:s'); }
    catch (\Throwable) { return (string) ($e['occurred_at'] ?? ''); }
};

$alRows = [];
$alCats = [];
$alMods = [];
$alActors = [];
// The trail's `occurred_at` is ISO-8601 with its offset; compared on the company's own calendar day.
foreach (array_reverse($store->all('audit')) as $e) {
    $when = $alWhen($e);
    $day = substr($when, 6, 4) . '-' . substr($when, 3, 2) . '-' . substr($when, 0, 2);
    if ($day < $alFrom || $day > $alTo) { continue; }
    $row = ['when' => $when, 'by' => (string) ($e['actor'] ?? ''), 'cat' => $alCategoryOf($e), 'mod' => $alModuleOf($e),
        'what' => trim((string) ($e['reason'] ?? '')) !== '' ? (string) $e['reason'] : ucfirst(str_replace(['.', '_'], ' ', (string) ($e['action'] ?? ''))),
        'action' => (string) ($e['action'] ?? ''), 'record' => (string) ($e['entity_id'] ?? '')];
    $row['record'] = in_array($row['cat'], ['Login', 'Logout'], true) ? '' : ($alEmp[$row['record']] ?? $row['record']);
    $alCats[$row['cat']] = ($alCats[$row['cat']] ?? 0) + 1;
    $alMods[$row['mod']] = true;
    $alActors[$row['by']] = true;
    if (($alCat !== '' && $row['cat'] !== $alCat) || ($alMod !== '' && $row['mod'] !== $alMod) || ($alBy !== '' && $row['by'] !== $alBy)) { continue; }
    $row['changes'] = $alChanges($e);
    if ($alWord !== '' && !str_contains(mb_strtolower(implode(' ', [$row['what'], $row['action'], $row['record'], $row['by'], $row['mod'], json_encode($row['changes'], JSON_UNESCAPED_UNICODE)])), $alWord)) { continue; }
    $alRows[] = $row;
}
ksort($alMods);
ksort($alActors);
