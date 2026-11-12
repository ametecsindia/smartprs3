<?php
/**
 * MOVE AN ASSET: issue / return / repair / retire, per config/assets.json's
 * transitions. Stamps who and when on the row and in the audit trail.
 */
declare(strict_types=1);

use SmartPRS\Surface\Assets;

// Back to whichever screen posted: Asset Stock (repair / restock / retire) or Asset Issue & Returns.
$back = '?r=' . (post('back') === 'asset-stock' ? 'asset-stock' : 'asset-issue') . (preg_match('/^\d{1,12}$/', post('emp')) === 1 ? '&emp=' . post('emp') : '');

if (!$may('employee.update')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'employee.update')->summary;
    header('Location: ' . $back);
    exit;
}

$assets = new Assets($store, __DIR__ . '/../../config/assets.json');

// SEVERAL ITEMS IN ONE ISSUE (owner, 30 Sep): the Issue form posts one
// employee + date and items[n][id|condition|purpose]. Each line goes through
// Assets::move() on its own — the same checks as a single issue (still
// available, live employee, reissue confirmation) — and the flash says which
// were issued and which were refused.
if (post('op') === 'issue' && is_array($_POST['items'] ?? null)) {
    $str = static fn ($v): string => is_string($v) ? trim($v) : '';
    $shared = array_map($str, array_diff_key($_POST, ['csrf' => 1, 'action' => 1, 'items' => 1]));
    $lines = [];

    foreach ($_POST['items'] as $line) {
        $lineId = is_array($line) ? $str($line['id'] ?? '') : '';

        if ($lineId !== '' && !isset($lines[$lineId])) {
            $lines[$lineId] = ['condition' => $str($line['condition'] ?? ''), 'purpose' => $str($line['purpose'] ?? '')];
        }
    }

    $done = [];
    $failed = [];

    foreach ($lines as $lineId => $line) {
        $unit = $store->find((string) $assets->declared()['table'], (string) $lineId);

        if ($unit === null || SmartPRS\Surface\Modules::isRetired($unit)) {
            $failed[] = 'No live asset with id ' . $lineId . '.';
            continue;
        }

        $r = $assets->move($unit, 'issue', array_merge($shared, $line), (string) $session->user()['email'], date('Y-m-d H:i:s'));

        if (!$r['ok']) {
            $failed[] = (string) ($unit['asset_id'] ?? $lineId) . ': ' . $r['why'];
            continue;
        }

        $done[] = (string) ($unit['asset_id'] ?? $lineId);
        $audit('asset.issue', 'assets', (string) $unit['id'],
            ['status' => (string) ($unit['status'] ?? ''), 'issued_to_employee_id' => (string) ($unit['issued_to_employee_id'] ?? '')],
            ['status' => (string) $r['row']['status'], 'issued_to_employee_id' => (string) ($r['row']['issued_to_employee_id'] ?? '')],
            'issue from the Issue & Return screen' . (count($lines) > 1 ? ' (' . count($lines) . ' items in one issue)' : ''));
    }

    if ($lines === []) {
        $_SESSION['flash_problem'] = 'Pick at least one item to issue.';
    }

    if ($done !== []) {
        $_SESSION['flash_notice'] = 'Issued ' . count($done) . ' item' . (count($done) === 1 ? '' : 's') . ': ' . implode(', ', $done) . '.';
    }

    if ($failed !== []) {
        $_SESSION['flash_problem'] = ($done !== [] ? 'Not issued — ' : '') . implode(' ', $failed);
    }

    header('Location: ' . $back);
    exit;
}

$row = post('id') === '' ? null : $store->find((string) $assets->declared()['table'], post('id'));

if ($row === null || SmartPRS\Surface\Modules::isRetired($row)) {
    $result = ['ok' => false, 'why' => 'No live asset with that id.'];
} else {
    // EVERY FIELD THE FORMS POST (29 Sep): this passed four, so purpose, the
    // damage/recovery answers on a return and the reissue confirmation were
    // dropped here before Assets::move() ever saw them.
    $result = $assets->move($row, post('op'), array_map(static fn ($v): string => is_string($v) ? trim($v) : '',
        array_diff_key($_POST, ['csrf' => 1, 'action' => 1])),
        (string) $session->user()['email'], date('Y-m-d H:i:s'));

    if ($result['ok']) {
        // THE FULL ISSUE → RETURN STORY ON THE TRAIL (owner, 22 Oct 2026: Returned Items report): the unit row is
        // reused on the next issue, so what this return looked like is kept here.
        $amKeep = ['asset_id', 'issue_date', 'issued_by', 'purpose', 'condition', 'return_date', 'returned_by', 'damage_resolution', 'recovery_amount', 'recovery_status'];
        $amAfter = array_merge($row, (array) $result['row']);
        $audit('asset.' . post('op'), 'assets', (string) $row['id'],
            ['status' => (string) ($row['status'] ?? ''), 'issued_to_employee_id' => (string) ($row['issued_to_employee_id'] ?? '')]
                + array_map('strval', array_intersect_key($row, array_flip(['asset_id', 'issue_date', 'issued_by', 'purpose', 'condition']))),
            ['status' => (string) $result['row']['status'], 'issued_to_employee_id' => (string) ($result['row']['issued_to_employee_id'] ?? '')]
                + array_map(static fn ($v): string => (string) $v, array_intersect_key($amAfter, array_flip($amKeep))),
            post('op') . ' from the Issue & Return screen');
    }
}

$_SESSION[$result['ok'] ? 'flash_notice' : 'flash_problem'] = $result['why'];
header('Location: ' . $back);
exit;
