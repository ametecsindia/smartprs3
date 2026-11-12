<?php
/**
 * ROSTER: assign a Working Shift to one employee or everybody in an org scope
 * for a date range, or cancel a roster row. `op` = assign | cancel.
 */
declare(strict_types=1);

use SmartPRS\Surface\Roster;

$back = '?r=roster&month=' . rawurlencode(post('month') !== '' ? post('month') : date('Y-m'));

foreach (array_keys(ORG_FILTERS) as $k) {
    if (post('f_' . $k) !== '') {
        $back .= '&f_' . $k . '=' . rawurlencode(post('f_' . $k));
    }
}

if (!$may('employee.update')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'employee.update')->summary;
    header('Location: ' . $back);
    exit;
}

$roster = new Roster($store, __DIR__ . '/../../config/roster.json');
$now = date('Y-m-d H:i:s');
$by = (string) $session->user()['email'];

if (post('op') === 'cancel') {
    $result = $roster->cancel(post('id'), $by, $now);

    if ($result['ok']) {
        $audit('roster.cancelled', 'rosters', post('id'), null, ['status' => (string) $roster->declared()['cancelled_status']], 'cancelled from the Roster screen');
    }
} else {
    // Who: one employee, or everybody the org filter (posted back as f_*) leaves in view.
    $scoped = $scope->only(liveRows($store, 'employees'), $may('employee.list'));

    if (post('employee_id') !== '') {
        $ids = array_map(static fn (array $e): string => (string) $e['id'], array_filter($scoped, static fn (array $e): bool => (string) $e['id'] === post('employee_id')));
    } else {
        $filter = [];

        foreach (array_keys(ORG_FILTERS) as $k) {
            $filter[$k] = strcasecmp(post('f_' . $k), 'all') === 0 ? '' : post('f_' . $k);
        }

        $ids = array_map(static fn (array $e): string => (string) $e['id'], orgFilterApply($store, $scoped, $filter));
    }

    $result = $roster->assign(array_values($ids), post('shift_id'), post('from'), post('to'), post('note'), $by, $now);

    if ($result['ok']) {
        $audit('roster.assigned', 'rosters', (string) ($result['rows'][0]['id'] ?? ''), null,
            ['employees' => count($result['rows']), 'shift_id' => post('shift_id'), 'from' => post('from'), 'to' => post('to')], post('note') === '' ? 'rostered' : post('note'));
    }
}

$_SESSION[$result['ok'] ? 'flash_notice' : 'flash_problem'] = $result['why'];
header('Location: ' . $back);
exit;
