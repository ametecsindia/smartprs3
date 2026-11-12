<?php declare(strict_types=1);
// DEV ONLY (12 Oct): sample documents for the sample employees, so the Document Tracker agrees with
// each employee's Documents Status. "Complete" → every expected kind, approved; anything else → all
// but three, pending. Files are shared, clearly labelled placeholders (uploads/2/employees/sample-docs/).
// PCC / DRA / Passport take the employee's own expiry dates. Skips a kind already on file. Delete after.
use SmartPRS\Surface\Employment;

if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
set_time_limit(0);
$sdKinds = array_map(static fn (array $f): string => (string) $f['label'], $employeeFields->documents());
$sdStatusField = array_values(array_filter($employeeFields->inUse(), static fn (array $f): bool => (string) $f['code'] === 'docs_status'))[0] ?? null;
$sdCols = array_flip($store->columnsOf('documents'));
$sdSlug = static fn (string $k): string => trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($k)), '-');
$sdExpiry = ['PCC Certificate' => 'pcc_expiry', 'DRA Certificate' => 'dra_expiry', 'Passport Copy' => 'passport_expiry'];
$sdPartialSkip = ['Relieving Letter', 'Last Organisation Experience Certificate', 'Last Salary Slip'];
$sdHave = [];
foreach ($store->all('documents') as $d) { $sdHave[(string) $d['employee_id'] . '|' . strtolower((string) $d['kind'])] = true; }
$sdN = 0;
$sdPeople = 0;
$sdMissingFile = [];

foreach (liveRows($store, 'employees') as $e) {
    $code = Employment::code($e);
    if (str_starts_with($code, 'ADM') || trim((string) ($e['name'] ?? '')) === '') { continue; }
    $status = $sdStatusField === null ? 'Complete' : trim((string) ($sdStatusField['storage'] === 'extra'
        ? ($employeeExtras->forEmployment($code)['docs_status'] ?? '') : ($e[(string) $sdStatusField['column']] ?? '')));
    $complete = strcasecmp($status, 'Complete') === 0;
    $sdPeople++;

    foreach ($sdKinds as $kind) {
        if (isset($sdHave[(string) $e['id'] . '|' . strtolower($kind)]) || (!$complete && in_array($kind, $sdPartialSkip, true))) { continue; }
        $file = 'uploads/2/employees/sample-docs/sample-' . $sdSlug($kind) . '.pdf';
        is_file(__DIR__ . '/../../public/' . $file) || $sdMissingFile[$kind] = $file;
        $exp = isset($sdExpiry[$kind]) ? substr((string) ($e[$sdExpiry[$kind]] ?? ''), 0, 10) : '';
        $store->put('documents', array_intersect_key(array_filter([
            'employee_id' => (string) $e['id'], 'kind' => $kind, 'status' => $complete ? 'approved' : 'pending',
            'expiry' => $exp !== '' ? $exp : null, 'file_path' => $file,
            'file_size' => is_file(__DIR__ . '/../../public/' . $file) ? filesize(__DIR__ . '/../../public/' . $file) : null,
            'created_at' => ((string) ($e['doj'] ?? '') ?: date('Y-m-d')) . ' 10:00:00',
        ], static fn ($v): bool => $v !== null), $sdCols));
        $sdN++;
    }
}
$audit('document.sample_data', 'documents', null, null, ['rows' => $sdN], 'Sample documents loaded');
?><pre id="sd"><?= e("{$sdN} document(s) added for {$sdPeople} employee(s)." . ($sdMissingFile === [] ? '' : ' MISSING FILES: ' . implode(', ', $sdMissingFile))) ?></pre>
