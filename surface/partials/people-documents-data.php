<?php declare(strict_types=1);
/**
 * DOCUMENTS — the rows. Included by the `document-tracker` route block and by
 * surface/actions/people-export.php with $params set. Sets $dtFilter,
 * $dtOptions, $dtVisible, $dtById, $dtQ, $dtDocs, $dtGroups, $dtKinds,
 * $dtExpected, $dtMissing, $dtRowsShown (expiring-first), $dtSoon and $export.
 */
use SmartPRS\Surface\Employment;

$dtScoped = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
$dtFilter = [];

foreach (array_keys(ORG_FILTERS) as $k) {
    $v = trim((string) ($params['f_' . $k] ?? ''));
    $dtFilter[$k] = strcasecmp($v, 'all') === 0 ? '' : $v;
}

$dtOptions = orgFilterOptions($store, $dtScoped);
$dtVisible = orgFilterApply($store, $dtScoped, $dtFilter);
$dtById = [];

foreach ($dtVisible as $dtE) {
    $dtById[(string) $dtE['id']] = $dtE;
}

$dtQ = strtolower(trim((string) ($params['q'] ?? '')));
$dtDocs = array_values(array_filter($store->all('documents'), static fn (array $d): bool => isset($dtById[(string) ($d['employee_id'] ?? '')]) && (string) ($d['deleted_at'] ?? '') === ''));
// UPLOADED ANYWHERE, LISTED HERE (owner, 12 Oct): the employee form and self-onboarding keep
// document files — and the photograph — on the employee record, not in `documents`. They are
// listed beside the tracker's own rows, read-only (`from_record`): they are changed on the
// employee's form, so a reviewer sees every file an employee has without knowing where it came in.
$dtRecordFields = array_merge($employeeFields->documents(), array_values(array_filter($employeeFields->inUse(),
    static fn (array $f): bool => (string) $f['code'] === 'photo_path')));

foreach ($dtById as $dtId => $dtE) {
    $dtHeld = $employeeExtras->forEmployment(Employment::code($dtE));

    foreach ($dtRecordFields as $f) {
        // A DOCUMENT field's file is held beside the record like an extra (self-onboarding uploads), never in a column.
        $dtPath = trim((string) (in_array($f['storage'] ?? 'extra', ['extra', 'document'], true) ? ($dtHeld[(string) $f['code']] ?? '') : ($dtE[(string) ($f['column'] ?? '')] ?? '')));

        if ($dtPath !== '') {
            $dtDocs[] = ['id' => '', 'employee_id' => (string) $dtId, 'kind' => (string) $f['label'], 'status' => 'on employee record',
                'file_path' => str_contains($dtPath, '/') ? $dtPath : '', 'created_at' => '', 'from_record' => true, 'field' => (string) $f['code']];
        }
    }
}

$dtGroups = [];

foreach ($dtDocs as $d) {
    $dtGroups[(string) $d['employee_id']][] = $d;
}

// THE KINDS: the register's expected documents (Organization Setup → Employee fields) plus whatever is already on file.
$dtExpected = array_values(array_unique(array_map(static fn (array $k): string => (string) $k['label'], $employeeFields->documents())));
$dtKinds = array_values(array_unique(array_merge($dtExpected, array_map(static fn (array $d): string => (string) ($d['kind'] ?? ''), $dtDocs))));
sort($dtKinds);
$dtToday = date('Y-m-d');
$dtDeclared = json_decode((string) file_get_contents(__DIR__ . '/../../config/documents.json'), true) ?: [];
$dtStatuses = array_map('strval', (array) ($dtDeclared['statuses'] ?? ['pending', 'approved', 'rejected']));
$dtWindow = (int) ($dtDeclared['expiring_window_days'] ?? 30);
$dtSoon = date('Y-m-d', strtotime('+' . $dtWindow . ' days'));
// WHO IS MISSING WHAT: expected kinds with no row, per employee.
$dtMissing = [];

foreach ($dtVisible as $dtE) {
    $have = array_map(static fn (array $d): string => strtolower((string) $d['kind']), $dtGroups[(string) $dtE['id']] ?? []);
    $dtMissing[(string) $dtE['id']] = array_values(array_filter($dtExpected, static fn (string $k): bool => !in_array(strtolower($k), $have, true)));
}

// The employee's soonest expiry decides the order: expired first, then expiring, then the rest; then people with nothing on file.
$dtSoonest = static function (string $id) use ($dtGroups): string {
    $dates = array_filter(array_map(static fn (array $d): string => (string) ($d['expiry'] ?? ''), $dtGroups[$id] ?? []));

    return $dates === [] ? '9999-99-99' : min($dates);
};
// `missing=1` widens the list to everybody with a gap — PRS2's "find who is missing what".
$dtShowMissing = (string) ($params['missing'] ?? '') === '1';
$dtRowsShown = array_values(array_filter($dtVisible, static fn (array $e): bool => (isset($dtGroups[(string) $e['id']]) || ($dtShowMissing && ($dtMissing[(string) $e['id']] ?? []) !== []))
    && ($dtQ === '' || str_contains(strtolower(Employment::label($e)), $dtQ))));
usort($dtRowsShown, static fn (array $a, array $b): int => [isset($dtGroups[(string) $a['id']]) ? 0 : 1, $dtSoonest((string) $a['id']), Employment::code($a)]
    <=> [isset($dtGroups[(string) $b['id']]) ? 0 : 1, $dtSoonest((string) $b['id']), Employment::code($b)]);

$export = ['title' => 'Documents' . ($dtShowMissing ? ' — including missing' : ''), 'filter' => $dtFilter + ($dtQ !== '' ? ['search' => $dtQ] : []),
    'columns' => ['CODE', 'EMPLOYEE', 'DOCUMENT KIND', 'STATUS', 'EXPIRY', 'EXPIRY STATE', 'FILE', 'ADDED', 'MISSING KINDS'], 'rows' => []];

foreach ($dtRowsShown as $dtE) {
    $id = (string) $dtE['id'];
    $docs = $dtGroups[$id] ?? [];
    usort($docs, static fn (array $a, array $b): int => [(string) ($a['expiry'] ?? '') === '' ? 1 : 0, (string) ($a['expiry'] ?? '')] <=> [(string) ($b['expiry'] ?? '') === '' ? 1 : 0, (string) ($b['expiry'] ?? '')]);
    $missing = implode(', ', $dtMissing[$id] ?? []);

    if ($docs === []) {
        $export['rows'][] = [Employment::code($dtE), trim((string) ($dtE['name'] ?? '')), '', '', '', '', '', '', $missing];

        continue;
    }

    foreach ($docs as $d) {
        $exp = (string) ($d['expiry'] ?? '');
        $export['rows'][] = [Employment::code($dtE), trim((string) ($dtE['name'] ?? '')), (string) $d['kind'], (string) ($d['status'] ?? ''), $exp,
            $exp === '' ? '' : ($exp < $dtToday ? 'expired' : ($exp <= $dtSoon ? 'expiring' : 'valid')), basename((string) ($d['file_path'] ?? '')),
            substr((string) ($d['created_at'] ?? ''), 0, 10), $missing];
    }
}
