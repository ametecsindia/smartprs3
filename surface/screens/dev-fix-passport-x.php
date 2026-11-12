<?php declare(strict_types=1);
// DEV ONLY (12 Oct): the sample "Passport Copy" documents were loaded without an expiry because
// Passport Expiry Date is held beside the record, not on employees. Give each one its employee's date. Delete after.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
$fxEmp = [];
foreach (liveRows($store, 'employees') as $e) { $fxEmp[(string) $e['id']] = SmartPRS\Surface\Employment::code($e); }
$fxN = 0;
foreach ($store->all('documents') as $d) {
    if ((string) $d['kind'] !== 'Passport Copy' || trim((string) ($d['expiry'] ?? '')) !== '' || !isset($fxEmp[(string) $d['employee_id']])) { continue; }
    $exp = substr((string) ($employeeExtras->forEmployment($fxEmp[(string) $d['employee_id']])['passport_expiry'] ?? ''), 0, 10);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) !== 1) { continue; }
    $store->put('documents', ['id' => $d['id'], 'expiry' => $exp]);
    $fxN++;
}
?><pre id="fx"><?= e("{$fxN} passport cop(ies) given their expiry date.") ?></pre>
