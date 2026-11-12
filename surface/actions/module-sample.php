<?php
/**
 * BULK UPLOAD SAMPLE for any register on the generic module screen — the
 * module's own fields, dropdowns and date cells (surface/ModuleBulk.php).
 */
declare(strict_types=1);

use SmartPRS\Surface\ModuleBulk;

$mbModule = $modules->find(post('k'));

if ($mbModule === null || !$may($modules->permission($mbModule, 'edit'))) {
    $_SESSION['flash_problem'] = $mbModule === null ? 'No such register.' : 'Denied: ' . $access->decide((string) $session->user()['role'], $modules->permission($mbModule, 'edit'))->summary;
    header('Location: ?r=home');
    exit;
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9-]+/', '-', strtolower((string) $mbModule['key'])) . '-bulk-upload-sample.xlsx"');
$mbEmpCo = [];
if (!empty($mbModule['company_follows_employee'])) {
    foreach ($store->all('employees') as $mbE) { $mbEmpCo[(string) $mbE['id']] = (string) ($mbE['employer_company_id'] ?? ''); }
}
echo ModuleBulk::sample(ModuleBulk::columns($modules->fields($mbModule)), [], $mbEmpCo);
exit;
