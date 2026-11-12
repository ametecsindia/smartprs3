<?php
/**
 * PF & ESI REMITTANCE UPLOAD (owner, 14 Oct). Every row goes through Modules::save() — the Add
 * form's own checks. A row with no amount at all is skipped (the template lists every employee).
 * A NEWER LINE SUPERSEDES: once a row for an employee and wage month is saved, any earlier line for
 * that same employee and month is retired — kept in history, out of the totals — so uploading a
 * corrected file never counts a month twice (law 3: corrections supersede, never overwrite).
 */
declare(strict_types=1);

use SmartPRS\Surface\ModuleBulk;
use SmartPRS\Surface\Sheet;

// ONE UPLOAD FOR BOTH REMITTANCE REGISTERS (14 Oct): PF & ESI, and TDS — `k` picks it.
$puKey = in_array(post('k'), ['tds-remittances', 'pt-remittances'], true) ? post('k') : 'pf-esi-remittances';
$puBack = '?r=' . $puKey;
$puModule = $modules->find($puKey);

if ($puModule === null || !$may($modules->permission($puModule, 'edit'))) {
    $_SESSION['flash_problem'] = $puModule === null ? 'No such register.' : 'Denied: ' . $access->decide((string) $session->user()['role'], $modules->permission($puModule, 'edit'))->summary;
    header('Location: ' . $puBack);
    exit;
}

$puUpload = (string) ($_FILES['sheet']['tmp_name'] ?? '');

if (!is_uploaded_file($puUpload)) {
    $_SESSION['flash_problem'] = 'No file was uploaded. Nothing was read.';
    header('Location: ' . $puBack);
    exit;
}

try {
    $puRead = ModuleBulk::read(ModuleBulk::columns($modules->fields($puModule)),
        Sheet::toCsv((string) file_get_contents($puUpload), (string) ($_FILES['sheet']['name'] ?? 'upload.csv')));
} catch (\Throwable $e) {
    $puRead = ['rows' => [], 'problem' => $e->getMessage()];
}

if ($puRead['problem'] !== null) {
    $_SESSION['flash_problem'] = $puRead['problem'];
    header('Location: ' . $puBack);
    exit;
}

$puAmounts = $puKey === 'pt-remittances' ? ['pt_deducted', 'pt_paid'] : ($puKey === 'tds-remittances' ? ['tds_deducted', 'tds_deposited'] : ['gross_wages', 'epf_wages', 'eps_wages', 'edli_wages', 'epf_ee_share', 'eps_er_share', 'epf_er_share',
    'refund_of_advances', 'esi_wages', 'esi_ee_share', 'esi_er_share']);
$puTable = (string) $puModule['table'];
$puSaved = 0;
$puSkipped = 0;
$puSuperseded = 0;
$puRefused = [];
$puExisting = [];

foreach ($store->all($puTable) as $x) {
    isRetired($x) || $puExisting[(string) $x['employee_id'] . '|' . (string) $x['month']][] = (string) $x['id'];
}

foreach ($puRead['rows'] as $r) {
    $in = $r['input'];

    if (array_filter($puAmounts, static fn (string $c): bool => trim((string) ($in[$c] ?? '')) !== '' && trim((string) $in[$c]) !== '0') === []) {
        $puSkipped++;
        continue;
    }

    if (trim((string) ($in['employee_id'] ?? '')) !== '' && !$modules->rowVisible($puModule, ['employee_id' => (string) $in['employee_id']])) {
        $puRefused[] = 'Line ' . $r['line'] . ': that employee is outside your reach.';
        continue;
    }

    $res = $modules->save($puModule, $in);

    if (!$res['ok']) {
        $puRefused[] = 'Line ' . $r['line'] . ': ' . $res['why'];
        continue;
    }

    $puSaved++;
    $newId = (string) ($res['row']['id'] ?? '');
    $key = (string) ($res['row']['employee_id'] ?? '') . '|' . (string) ($res['row']['month'] ?? '');
    $audit('module.row.saved', $puTable, $newId, null, $res['row'], 'Remittance upload');

    foreach ($puExisting[$key] ?? [] as $oldId) {
        if ($oldId !== $newId && ($modules->retire($puModule, $oldId)['ok'] ?? false)) {
            $puSuperseded++;
        }
    }

    $puExisting[$key] = [$newId];
}

$_SESSION['flash_notice'] = $puSaved . ' remittance line(s) saved' . ($puSuperseded > 0 ? ', ' . $puSuperseded . ' earlier line(s) for the same employee and month superseded' : '')
    . ($puSkipped > 0 ? ', ' . $puSkipped . ' row(s) with no amount skipped' : '') . '.';
$puRefused === [] || $_SESSION['flash_problem'] = count($puRefused) . ' row(s) refused — fix and upload just those: ' . implode(' ', array_slice($puRefused, 0, 25)) . (count($puRefused) > 25 ? ' …' : '');
header('Location: ' . $puBack);
exit;
