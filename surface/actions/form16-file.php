<?php
/**
 * DOWNLOAD FORM 16 PART A (owner, 15 Oct) — one employee's PDF (HR in reach, or the employee once
 * the year is released), or every uploaded Part A in the filter as one ZIP (HR).
 */
declare(strict_types=1);

use SmartPRS\Surface\Employment;

include __DIR__ . '/../partials/form16-lib.php';
$fy = preg_match('/^\d{4}-\d{2}$/', trim(post('fy'))) === 1 ? trim(post('fy')) : $taxDeclarations->fy();
$fail = static function (string $why) use ($fy): never { $_SESSION['flash_problem'] = $why; header('Location: ?r=form16&fy=' . $fy); exit; };
$hr = $may('payroll.view');
$partA = f16Store()->partA($fy);

if (post('all') === '1') {
    $hr || $fail('Denied: downloading everyone\'s Form 16 needs payroll.view.');
    class_exists(\ZipArchive::class) || $fail('ZIP support is not enabled in PHP on this server.');
    $_GET = $_POST; // orgFilter() reads the filter from the query
    $people = orgFilterApply($store, f16People($fy, $taxDeclarations->runRows()), orgFilter());
    $tmp = tempnam(sys_get_temp_dir(), 'f16');
    $z = new \ZipArchive();
    $z->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $n = 0;
    foreach ($people as $e) {
        $row = $partA[(string) $e['id']] ?? null;
        $file = $row === null ? null : f16Store()->file($row);
        if ($file === null) { continue; }
        $z->addFile($file, preg_replace('/[^A-Za-z0-9_-]+/', '_', Employment::code($e) . '_' . ((string) ($e['pan'] ?? '') ?: 'NOPAN')) . '_Form16_PartA_' . $fy . '.pdf');
        $n++;
    }
    $z->close();
    $n === 0 && $fail('No Part A is uploaded for the employees in view.');
    $audit('form16.part_a.downloaded', 'form16_part_a', 'all', null, ['fy' => $fy, 'files' => $n], 'Form 16 Part A (all)');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="Form16_PartA_' . $fy . '.zip"');
    header('Content-Length: ' . (string) filesize($tmp));
    readfile($tmp);
    @unlink($tmp); // gate:allow-swallow a temp file the OS clears anyway
    exit;
}

$id = post('id');
$e = $id === '' ? null : $store->find('employees', $id);
$own = (string) ($session->user()['employee_id'] ?? '');
$ok = $e !== null && (($hr && $scope->only([$e], $may('employee.list')) !== []) || ($id === $own && f16Store()->released($fy)));
$ok || $fail('That certificate is not available to you.');
$row = $partA[$id] ?? null;
$file = $row === null ? null : f16Store()->file($row);
$file === null && $fail('Part A for this employee has not been uploaded.');
$audit('form16.part_a.downloaded', 'form16_part_a', (string) $row['id'], null, ['fy' => $fy, 'employee' => Employment::code($e)], 'Form 16 Part A');
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_-]+/', '_', Employment::code($e)) . '_Form16_PartA_' . $fy . '.pdf"');
header('Content-Length: ' . (string) filesize($file));
readfile($file);
exit;
