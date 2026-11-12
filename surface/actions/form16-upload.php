<?php
/**
 * UPLOAD FORM 16 PART A (owner, 15 Oct) — TRACES PDFs, or the ZIP TRACES gives (password typed, or
 * each company's TAN tried). Bulk: each PDF goes to the employee whose PAN is in its file name.
 * Single (`id` posted): the file goes to that employee whatever it is called.
 */
declare(strict_types=1);

use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Form16;

include __DIR__ . '/../partials/form16-lib.php';
$fy = preg_match('/^\d{4}-\d{2}$/', trim(post('fy'))) === 1 ? trim(post('fy')) : $taxDeclarations->fy();
$back = '?r=form16&fy=' . $fy;
$fail = static function (string $why) use ($back): never { $_SESSION['flash_problem'] = $why; header('Location: ' . $back); exit; };
($may('payroll.run') || isAdministrator()) || $fail('Denied: uploading Form 16 needs payroll.run.');

// Everyone who may get a certificate this year, by id and by PAN.
$people = f16People($fy, $taxDeclarations->runRows());
$byPan = [];
$byId = [];
foreach ($people as $e) {
    $byId[(string) $e['id']] = $e;
    ($p = strtoupper(trim((string) ($e['pan'] ?? '')))) === '' || $byPan[$p] = $e;
}
$target = post('id') === '' ? null : ($byId[post('id')] ?? $fail('That employee is not in your reach for ' . $fy . '.'));

// Collect [name, bytes] PDFs from the upload — ZIPs opened here, never written to disk unchecked.
$pdfs = [];
$problems = [];
$files = $_FILES['files'] ?? null;
is_array($files) && is_array($files['name'] ?? null) || $fail('No file arrived. Pick the files again.');
$passwords = array_values(array_unique(array_filter(array_merge([trim((string) ($_POST['password'] ?? ''))],
    array_map(static fn (array $c): string => strtoupper(trim((string) ($c['tan'] ?? ''))), liveRows($store, 'companies'))))));

foreach ($files['name'] as $i => $name) {
    $name = (string) $name;
    if ((int) $files['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $files['tmp_name'][$i])) { $problems[] = $name . ': did not arrive'; continue; }
    if ((int) $files['size'][$i] > 50 * 1024 * 1024) { $problems[] = $name . ': larger than 50 MB'; continue; }
    $tmp = (string) $files['tmp_name'][$i];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if ($ext === 'pdf') {
        $pdfs[] = [$name, (string) file_get_contents($tmp)];
        continue;
    }

    if ($ext !== 'zip' || $target !== null) { $problems[] = $name . ': only PDF' . ($target === null ? ' or ZIP' : '') . ' files'; continue; }
    if (!class_exists(\ZipArchive::class)) { $problems[] = $name . ': ZIP support is not enabled in PHP — extract it and upload the PDFs'; continue; }
    $z = new \ZipArchive();
    if ($z->open($tmp) !== true) { $problems[] = $name . ': not a readable ZIP'; continue; }
    for ($k = 0; $k < $z->numFiles; $k++) {
        $entry = (string) $z->getNameIndex($k);
        if (strtolower(pathinfo($entry, PATHINFO_EXTENSION)) !== 'pdf') { continue; }
        $bytes = $z->getFromIndex($k);
        foreach ($passwords as $pw) {
            if ($bytes !== false && Form16::isPdf((string) $bytes)) { break; }
            $z->setPassword($pw);
            $bytes = $z->getFromIndex($k);
        }
        $bytes === false || !Form16::isPdf((string) $bytes)
            ? $problems[] = basename($entry) . ': could not be opened — check the ZIP password'
            : $pdfs[] = [basename($entry), (string) $bytes];
    }
    $z->close();
}

$by = (string) ($session->user()['email'] ?? '');
$stored = 0;
$unmatched = [];

foreach ($pdfs as [$name, $bytes]) {
    $pan = Form16::panIn($name);
    $e = $target ?? ($pan === '' ? null : ($byPan[$pan] ?? null));
    if ($e === null) { $unmatched[] = $name . ($pan === '' ? ' (no PAN in the name)' : ' (PAN ' . $pan . ' is on no employee)'); continue; }
    try {
        $row = f16Store()->addPartA((string) $e['id'], $fy, (string) ($e['pan'] ?? $pan), $bytes, $name, $by);
        $audit('form16.part_a.uploaded', 'form16_part_a', (string) $row['id'], null, ['fy' => $fy, 'employee' => Employment::code($e), 'file' => $name], 'Form 16 Part A');
        $stored++;
    } catch (\InvalidArgumentException | \RuntimeException $x) {
        $problems[] = $name . ': ' . $x->getMessage();
    }
}

$msg = $stored . ' Part A file(s) stored.';
$unmatched !== [] && $msg .= ' Not matched to an employee: ' . implode('; ', array_slice($unmatched, 0, 15)) . (count($unmatched) > 15 ? ' …' : '') . '. Add the PAN on the employee record, or upload from that employee\'s row.';
$problems !== [] && $msg .= ' Problems: ' . implode('; ', array_slice($problems, 0, 15)) . (count($problems) > 15 ? ' …' : '') . '.';
$_SESSION[$stored > 0 && $unmatched === [] && $problems === [] ? 'flash_notice' : 'flash_problem'] = $msg;
header('Location: ' . $back);
exit;
