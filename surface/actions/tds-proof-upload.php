<?php
/**
 * UPLOAD AN INVESTMENT PROOF (14 Oct) — only while the proof window is open (HR / Administrator any
 * time), only against an item the employee declared. Stored under public/uploads/<tenant>/tax-proofs.
 */
declare(strict_types=1);

use SmartPRS\Surface\IncomeTax;

include __DIR__ . '/../partials/tds-lib.php';
$tpWho = tdsWho();
$tpBack = '?r=tds-proofs' . ($tpWho['self'] || $tpWho['e'] === null ? '' : '&id=' . rawurlencode((string) $tpWho['e']['id']));
$tpFail = static function (string $why) use ($tpBack): never { $_SESSION['flash_problem'] = $why; header('Location: ' . $tpBack); exit; };
$tpWho['e'] === null && $tpFail('There is no employee record you may upload for.');
$e = $tpWho['e'];
$fy = preg_match('/^\d{4}-\d{2}$/', trim(post('fy'))) === 1 ? trim(post('fy')) : $taxDeclarations->fy();

if (!$taxDeclarations->window('proof', date('Y-m-d'))['open'] && !$may('payroll.run') && !isAdministrator()) {
    $tpFail('The proof window is ' . $taxDeclarations->window('proof', date('Y-m-d'))['why'] . '.');
}

$decl = $taxDeclarations->declaration((string) $e['id'], $fy);
$item = post('item');
($decl === null || !isset($taxDeclarations->proofItems($decl, $fy)[$item])) && $tpFail('That item is not in your declaration for ' . $fy . '.');

try { $amount = IncomeTax::p(post('amount')); } catch (\InvalidArgumentException) { $tpFail('The amount is not a rupee figure.'); }
$amount <= 0 && $tpFail('Give the amount this proof shows.');

$file = $_FILES['proof'] ?? null;
(!is_array($file) || (int) ($file['error'] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) && $tpFail('The file did not arrive. Pick it again and upload.');
$ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
in_array($ext, explode(',', str_replace('.', '', EMPLOYEE_FILE_ACCEPT)), true) || $tpFail('.' . $ext . ' is not accepted (' . EMPLOYEE_FILE_ACCEPT . ').');
(int) $file['size'] > EMPLOYEE_FILE_MAX_BYTES && $tpFail('The file is larger than ' . (EMPLOYEE_FILE_MAX_BYTES / 1024 / 1024) . ' MB.');

$rel = 'uploads/' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $tenantId === '' ? 'platform' : $tenantId) . '/tax-proofs';
$dir = __DIR__ . '/../../public/' . $rel;
(!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) && $tpFail('The uploads folder could not be created.');
$name = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $e['id'] . '_' . $fy . '_' . $item) . '_' . date('YmdHis') . '.' . $ext;
move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $name) || $tpFail('The file could not be stored.');

$row = $taxDeclarations->addProof((string) $e['id'], $fy, $item, SmartPRS\Platform\Support\Money::ofMinor($amount)->toDecimalString(), $rel . '/' . $name, mb_substr((string) $file['name'], 0, 180));
$audit('tds.proof.uploaded', 'tax_proofs', (string) $row['id'], null, ['fy' => $fy, 'item' => $item, 'amount' => $amount / 100], 'Investment proof');
$_SESSION['flash_notice'] = 'Proof uploaded — HR will verify it.';
header('Location: ' . $tpBack . '&fy=' . $fy);
exit;
