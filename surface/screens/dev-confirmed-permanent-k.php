<?php declare(strict_types=1);
// DEV ONLY (owner, 5 Nov 2026): "In case if confirmed is there anywhere, change those to Permanent."
// Employee Type and Employee Stage holding "confirmed" (any case) become "Permanent". Only those cells change.
// ?apply=1 writes; without it, a dry run. Delete after.
use SmartPRS\Surface\Employment;
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
$cpApply = (string) ($_GET['apply'] ?? '') === '1';
$cpOut = [];
foreach (liveRows($store, 'employees') as $e) {
    $cpSet = [];
    foreach (['employment_type', 'employment_stage'] as $cpCol) {
        if (strcasecmp(trim((string) ($e[$cpCol] ?? '')), 'confirmed') === 0) { $cpSet[$cpCol] = 'Permanent'; }
    }
    if ($cpSet === []) { continue; }
    $cpOut[] = Employment::code($e) . ' ' . trim((string) ($e['name'] ?? '')) . ' | ' . implode(', ', array_keys($cpSet)) . ': confirmed -> Permanent' . ($cpApply ? ' DONE' : ' (dry run)');
    $cpApply && $store->put('employees', ['id' => $e['id']] + $cpSet);
}
$cpApply && $cpOut !== [] && $audit('employee.confirmed_to_permanent', 'employees', null, null, ['rows' => count($cpOut)], '"confirmed" changed to Permanent');
?><pre id="cp"><?= e(count($cpOut) . " employee(s)" . ($cpApply ? ' changed' : ' to change') . "\n" . implode("\n", $cpOut)) ?></pre>
