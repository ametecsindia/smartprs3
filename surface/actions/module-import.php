<?php
/**
 * BULK UPLOAD into any register: every row through Modules::save() — the Add
 * form's own checks — good rows kept, refused rows listed with line and reason.
 */
declare(strict_types=1);

use SmartPRS\Surface\ModuleBulk;
use SmartPRS\Surface\Sheet;

$miModule = $modules->find(post('k'));
$miBack = '?r=' . ($miModule === null ? 'home' : moduleRoute((string) $miModule['key']));

if ($miModule === null || !$may($modules->permission($miModule, 'edit'))) {
    $_SESSION['flash_problem'] = $miModule === null ? 'No such register.' : 'Denied: ' . $access->decide((string) $session->user()['role'], $modules->permission($miModule, 'edit'))->summary;
    header('Location: ' . $miBack);
    exit;
}

$miUpload = (string) ($_FILES['sheet']['tmp_name'] ?? '');

if (!is_uploaded_file($miUpload)) {
    $_SESSION['flash_problem'] = 'No file was uploaded. Nothing was read.';
    header('Location: ' . $miBack);
    exit;
}

try {
    $miRead = ModuleBulk::read(ModuleBulk::columns($modules->fields($miModule)),
        Sheet::toCsv((string) file_get_contents($miUpload), (string) ($_FILES['sheet']['name'] ?? 'upload.csv')));
} catch (\Throwable $e) {
    $miRead = ['rows' => [], 'problem' => $e->getMessage()];
}

if ($miRead['problem'] !== null) {
    $_SESSION['flash_problem'] = $miRead['problem'];
    header('Location: ' . $miBack);
    exit;
}

$miOwner = $modules->ownerColumn($miModule);
$miSaved = 0;
$miRefused = [];

foreach ($miRead['rows'] as $r) {
    $in = $r['input'];

    if ($miOwner !== null && $miOwner !== 'id' && trim((string) ($in[$miOwner] ?? '')) !== '' && !$modules->rowVisible($miModule, [$miOwner => (string) $in[$miOwner]])) {
        $miRefused[] = 'Line ' . $r['line'] . ': that employee is outside your reach.';
        continue;
    }

    $res = $modules->save($miModule, $in);

    if ($res['ok']) {
        $miSaved++;
        $audit('module.row.saved', (string) $miModule['table'], (string) ($res['row']['id'] ?? ''), null, $res['row'], 'Bulk upload into ' . $miModule['label']);
    } else {
        $miRefused[] = 'Line ' . $r['line'] . ': ' . $res['why'];
    }
}

$_SESSION['flash_notice'] = $miSaved . ' row(s) saved into ' . $miModule['label'] . '.';
$miRefused === [] || $_SESSION['flash_problem'] = count($miRefused) . ' row(s) refused — fix and upload just those: ' . implode(' ', array_slice($miRefused, 0, 25)) . (count($miRefused) > 25 ? ' …' : '');
header('Location: ' . $miBack);
exit;
