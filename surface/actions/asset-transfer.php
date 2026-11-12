<?php
/**
 * TRANSFER ASSETS TO ANOTHER COMPANY / BRANCH (owner, 30 Sep), from Asset
 * Stock: the ticked units (ids[]) move to one company + branch. Each unit goes
 * through Assets::transfer() on its own (status, branch-of-company and
 * already-there checks) and is audited; the flash says which moved and which
 * were refused.
 */
declare(strict_types=1);

use SmartPRS\Surface\Assets;

$keep = [];
foreach (['company', 'branch'] as $k) {
    if (preg_match('/^\d{1,12}$/', post('f_' . $k)) === 1) {
        $keep[] = $k . '=' . post('f_' . $k);
    }
}
$back = '?r=asset-stock' . ($keep !== [] ? '&' . implode('&', $keep) : '');

if (!$may('employee.update')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'employee.update')->summary;
    header('Location: ' . $back);
    exit;
}

$assets = new Assets($store, __DIR__ . '/../../config/assets.json');
$names = orgNames($store);
$toCo = post('to_company');
$toBr = post('to_branch');
$where = static fn (string $co, string $br): string => trim(((string) ($names['companies'][$co] ?? '')) . ' / ' . ((string) ($names['branches'][$br] ?? '')), ' /') ?: '—';
$ids = array_values(array_unique(array_filter(array_map(static fn ($v): string => is_string($v) ? trim($v) : '', (array) ($_POST['ids'] ?? [])),
    static fn (string $v): bool => $v !== '')));
$done = [];
$failed = [];

foreach ($ids as $unitId) {
    $unit = $store->find((string) $assets->declared()['table'], $unitId);

    if ($unit === null || SmartPRS\Surface\Modules::isRetired($unit)) {
        $failed[] = 'No live asset with id ' . $unitId . '.';
        continue;
    }

    $fromWhere = $where((string) ($unit['employer_company_id'] ?? ''), (string) ($unit['branch_id'] ?? ''));
    $r = $assets->transfer($unit, $toCo, $toBr, $fromWhere . ' → ' . $where($toCo, $toBr) . (post('note') !== '' ? ' (' . post('note') . ')' : ''),
        (string) $session->user()['email'], date('Y-m-d H:i:s'));

    if (!$r['ok']) {
        $failed[] = $r['why'];
        continue;
    }

    $done[] = (string) ($unit['asset_id'] ?? $unitId);
    $audit('asset.transfer', 'assets', (string) $unit['id'],
        ['employer_company_id' => (string) ($unit['employer_company_id'] ?? ''), 'branch_id' => (string) ($unit['branch_id'] ?? '')],
        ['employer_company_id' => (string) ($r['row']['employer_company_id'] ?? ''), 'branch_id' => (string) ($r['row']['branch_id'] ?? '')],
        'transfer from Asset Stock' . (post('note') !== '' ? ': ' . post('note') : ''));
}

if ($ids === []) {
    $_SESSION['flash_problem'] = 'Tick at least one item to transfer.';
}

if ($done !== []) {
    $_SESSION['flash_notice'] = 'Transferred ' . count($done) . ' item' . (count($done) === 1 ? '' : 's') . ' to ' . $where($toCo, $toBr) . ': ' . implode(', ', $done) . '.';
}

if ($failed !== []) {
    // Many units failing for the same reason (e.g. no branch picked) say it once.
    $_SESSION['flash_problem'] = ($done !== [] ? 'Not transferred — ' : '') . implode(' ', array_unique($failed));
}

header('Location: ' . $back);
exit;
