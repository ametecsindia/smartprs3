<?php declare(strict_types=1);
// DEV ONLY (16 Oct 2026): READ-ONLY — runs Register::governmentEsi() over the newest run's stored payslip lines.
// Writes nothing. Delete after checking.
if (!isAdministrator((string) ($session->user()['role'] ?? ''))) { return; }
$ecRuns = $register->runs();
$ecRun = $ecRuns[0] ?? null;
$ecM = new ReflectionMethod($register, 'governmentEsi');
$ecOut = ['period' => $ecRun['period'] ?? null, 'covered' => 0, 'not' => 0, 'sample' => []];
$ecEmp = [];
foreach (liveRows($store, 'employees') as $e) { $ecEmp[SmartPRS\Surface\Employment::code($e)] = $e; }
foreach ((array) ($ecRun['rows'] ?? []) as $code => $row) {
    $lines = array_values(array_filter((array) ($row['lines'] ?? []), static fn ($l): bool => !in_array(preg_replace('/^[A-Z]-/', '', (string) $l['code']), ['ESI_EMPLOYEE', 'ESI_EMPLOYER'], true)));
    $out = $ecM->invoke($register, (string) $code, $ecEmp[$code] ?? [], (string) $ecRun['period'], $lines, [], (array) $row);
    $out === [] ? $ecOut['not']++ : $ecOut['covered']++;
    if ($out !== [] && count($ecOut['sample']) < 4) { $ecOut['sample'][] = [$code, $row['gross'] ?? '', array_map(static fn ($l): string => $l['code'] . ' ' . $l['amount'] . ' | ' . $l['trace'], $out)]; }
    if ($out === [] && count($ecOut['sample']) < 6 && (float) ($row['gross'] ?? 0) <= 21000) { $ecOut['sample'][] = [$code, $row['gross'] ?? '', 'NOT COVERED though gross <= 21000']; }
}
echo '<pre id="au">' . e(json_encode($ecOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
