<?php declare(strict_types=1);
// DEV ONLY (owner, 23 Oct): sample portrait photos (AI-generated faces, not real people) for the
// employees in the expanded Ametecs hierarchy — Ejaz Hussain (AI2001) keeps his own. Each photo was
// picked for the employee's gender; a row whose recorded gender differs is SKIPPED, not overwritten.
// ?apply=1 writes; without it, a dry run. Delete after.
use SmartPRS\Surface\Employment;
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
$phPlan = ['AIPL-1002' => 'Male', 'AIPL-1003' => 'Male', 'AIPL-1004' => 'Male', 'AIPL-1007' => 'Female', 'AIPL-1008' => 'Male', 'AIPL-1009' => 'Female', 'AIPL-1010' => 'Female', 'AIPL-1011' => 'Male', 'AIPL-1012' => 'Male', 'AIPL-1013' => 'Male', 'AIPL-1016' => 'Male', 'AIPL-1017' => 'Male', 'AIPL-1037' => 'Male'];
$phApply = (string) ($_GET['apply'] ?? '') === '1';
$phOut = [];
foreach (liveRows($store, 'employees') as $e) {
    $code = Employment::code($e);
    if (!isset($phPlan[$code])) { continue; }
    $file = 'uploads/2/employees/' . $code . '_photo_path_20261023.jpg';
    $g = trim((string) ($e['gender'] ?? ''));
    $ok = strcasecmp($g, $phPlan[$code]) === 0 && is_file(__DIR__ . '/../../public/' . $file);
    $phOut[] = $code . ' ' . trim((string) ($e['name'] ?? '')) . ' | gender ' . ($g ?: '?') . ' | photo for ' . $phPlan[$code] . ' | ' . ($ok ? ($phApply ? 'SET' : 'would set') : 'SKIPPED (gender/file mismatch)');
    if ($ok && $phApply) { $store->put('employees', ['id' => $e['id'], 'photo_path' => $file]); }
}
$phApply && $audit('employee.sample_photos', 'employees', null, null, ['rows' => count($phOut)], 'Sample photos set (hierarchy)');
?><pre id="ph"><?= e(implode("\n", $phOut)) ?></pre>
