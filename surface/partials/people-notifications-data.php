<?php declare(strict_types=1);
/**
 * NOTIFICATIONS — the viewer's activity stream. Included by the `notifications`
 * route block and by surface/actions/people-export.php with $params set (kind
 * filter). Sets $ntAll, $ntKinds, $ntKind, $ntRows, $ntRoutes, $ntUnread and $export.
 */
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Notifications;

// YOURS, unless you may read the tenant's audit trail — a colleague's absence notice is not your feed.
$ntOwnRow = $scope->own() === null ? null : $store->find('employees', (string) $scope->own());
$ntOwnCode = $ntOwnRow === null ? '' : Employment::code($ntOwnRow);
$ntLimit = (int) ((json_decode((string) file_get_contents(__DIR__ . '/../../config/notification-routes.json'), true) ?: [])['list_limit'] ?? 200);
// …AND YOUR ROLE'S: a message queued for "role:HR Admin" (probation reminders)
// is on the screen of every login holding that role, with or without an
// employee record of its own.
$ntRoleRecipient = Notifications::ROLE_PREFIX . (string) ($session->user()['role'] ?? '');
$ntAll = $may('audit.tenant.read') ? $notifications->queued($ntLimit) : $notifications->queuedFor($ntOwnCode, $ntLimit, [$ntRoleRecipient]);
// THE ORG FILTER (owner, 28 Sep) narrows by the employee a message is ABOUT:
// the recipient when that is an employee, else the employee code carried in
// the dedupe key (a probation reminder to "role:HR Admin" is keyed
// probation.reminder:<CODE>:…). A message about nobody drops out once any
// filter is set.
$ntFilter = [];
foreach (array_keys(ORG_FILTERS) as $k) {
    $v = trim((string) ($params['f_' . $k] ?? ''));
    $ntFilter[$k] = strcasecmp($v, 'all') === 0 ? '' : $v;
}
$ntScoped = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
$ntOptions = orgFilterOptions($store, $ntScoped);
if (array_filter($ntFilter) !== []) {
    $ntKnown = [];
    foreach ($ntScoped as $ntE) {
        $ntC = Employment::code($ntE);
        $ntC === '' || $ntKnown[$ntC] = true;
    }
    $ntPass = [];
    foreach (orgFilterApply($store, $ntScoped, $ntFilter) as $ntE) {
        $ntPass[Employment::code($ntE)] = true;
    }
    $ntAll = array_values(array_filter($ntAll, static function (array $m) use ($ntKnown, $ntPass): bool {
        $meta = json_decode((string) ($m['meta'] ?? ''), true);
        $cands = array_merge([is_array($meta) ? (string) ($meta['recipient'] ?? '') : ''], explode(':', (string) ($m['dedupe_key'] ?? '')));
        foreach ($cands as $c) {
            if (isset($ntKnown[$c])) {
                return isset($ntPass[$c]);
            }
        }
        return false;
    }));
}
$ntKinds = array_values(array_unique(array_map(static fn (array $m): string => (string) ($m['kind'] ?? ''), $ntAll)));
sort($ntKinds);
$ntKind = in_array((string) ($params['kind'] ?? ''), $ntKinds, true) ? (string) $params['kind'] : '';
$ntRows = array_values(array_filter($ntAll, static fn (array $m): bool => $ntKind === '' || (string) ($m['kind'] ?? '') === $ntKind));
$ntRoutes = (array) (json_decode((string) file_get_contents(__DIR__ . '/../../config/notification-routes.json'), true)['routes'] ?? []);
$ntUnread = count(array_filter($ntAll, static fn (array $m): bool => trim((string) ($m['read_at'] ?? '')) === ''));
// CODE => full employee row, so the "For" column can show "CODE · Name"
// (Employment::label()'s own convention) instead of a bare code nobody but
// HR can place from memory.
$ntEmployeesByCode = [];
foreach ($store->all('employees') as $ntEmpRow) {
    $ntEmpCode = Employment::code($ntEmpRow);
    $ntEmpCode === '' || $ntEmployeesByCode[$ntEmpCode] = $ntEmpRow;
}
$ntRecipient = static function (array $m) use ($ntEmployeesByCode): string {
    $meta = json_decode((string) ($m['meta'] ?? ''), true);
    $recipientCode = is_array($meta) ? (string) ($meta['recipient'] ?? '') : '';

    if ($recipientCode === '') {
        return '';
    }

    if (str_starts_with($recipientCode, Notifications::ROLE_PREFIX)) {
        return 'Role: ' . substr($recipientCode, strlen(Notifications::ROLE_PREFIX));
    }

    // A code with no matching row (employee since deleted) still shows the
    // code rather than nothing — never a silent blank on an old message.
    return isset($ntEmployeesByCode[$recipientCode])
        ? Employment::label($ntEmployeesByCode[$recipientCode])
        : $recipientCode;
};

$export = ['title' => 'Notifications' . ($ntKind !== '' ? ' — ' . $ntKind : ''), 'filter' => $ntKind !== '' ? ['type' => $ntKind] : [],
    'columns' => ['WHEN', 'EVENT', 'TITLE', 'MESSAGE', 'FOR', 'CHANNELS', 'STATE', 'READ'],
    'rows' => array_map(static fn (array $m): array => [(string) ($m['created_at'] ?? ''), (string) ($m['kind'] ?? ''), (string) ($m['title'] ?? ''), (string) ($m['body'] ?? ''),
        $ntRecipient($m), (string) ($m['channels'] ?? ''), (string) ($m['email_status'] ?? ''), trim((string) ($m['read_at'] ?? '')) === '' ? 'No' : substr((string) $m['read_at'], 0, 16)], $ntRows)];
