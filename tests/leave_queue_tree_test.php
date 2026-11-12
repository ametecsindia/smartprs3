<?php

declare(strict_types=1);

use SmartPRS\Surface\Leave;
use SmartPRS\Surface\Scope;
use SmartPRS\Surface\Store;

/**
 * THE LEAVE APPROVALS QUEUE FOLLOWS THE WHOLE REPORTING TREE.
 *
 * Owner, 23 Sep, of the hierarchy: "I mean hierarchy tree based" — and of the
 * leave screens: "All these to be available only for the employee to whom other
 * people reports to and follow the hierarchy as mentioned earlier".
 *
 *     1 reports to 2, 2 reports to 3, 3 reports to 4
 *
 * 3 must see 1's leave, and 4 must see 1's, even though neither is 1's
 * immediate manager. leaveQueueFor() used to decide that with
 * `$emp['reporting_manager_id'] === $own` — ONE level, written inline, and
 * therefore unaffected when Scope was widened to the tree. It now asks Scope,
 * which is the single answer to "whose row is this?".
 *
 * The negative half matters as much: this must not become "anybody in the
 * line", which would put a manager's own leave in their report's queue.
 *
 * THE FUNCTION UNDER TEST LIVES IN public/app.php, which is a front controller:
 * requiring it would run the whole application. So its source is SLICED OUT of
 * the real file at run time and eval'd — never copied into this test, which
 * would be a test of a copy and would pass forever after the original changed.
 * Rename or delete leaveQueueFor() and this fails loudly instead of quietly
 * testing nothing.
 */

group('leave approvals · reporting tree');

if (!function_exists('liveRows')) {
    /** The one thing the sliced function reaches for; the real one adds soft-delete filtering. */
    function liveRows(SmartPRS\Surface\RowStore $store, string $table): array
    {
        return $store->all($table);
    }
}

if (!function_exists('leaveQueueFor')) {
    $lqFile = dirname(__DIR__) . '/public/app.php';
    $lqLines = file($lqFile);
    $lqFrom = null;

    foreach ($lqLines as $lqI => $lqLine) {
        if (str_starts_with($lqLine, 'function leaveQueueFor(')) {
            $lqFrom = $lqI;

            break;
        }
    }

    if ($lqFrom === null) {
        throw new RuntimeException('No top-level leaveQueueFor() in public/app.php — renamed?');
    }

    for ($lqTo = $lqFrom; $lqTo < count($lqLines) && rtrim($lqLines[$lqTo]) !== '}'; $lqTo++) {
        // the closing brace of a top-level function is the next line that is exactly "}"
    }

    eval(implode('', array_slice($lqLines, $lqFrom, $lqTo - $lqFrom + 1)));
}

$dir = sys_get_temp_dir() . '/prs3-lq-' . bin2hex(random_bytes(4));
$store = (new Store($dir))->forTenant('1');

// 1 -> 2 -> 3 -> 4, and 9 in a line of its own that nobody here may see.
foreach ([['1', 'E-1', '2'], ['2', 'E-2', '3'], ['3', 'E-3', '4'], ['4', 'E-4', ''], ['9', 'E-9', '8']] as [$id, $code, $mgr]) {
    $store->put('employees', ['id' => $id, 'emp_code' => $code, 'name' => 'Person ' . $id,
        'reporting_manager_id' => $mgr]);
}

$store->put('leave_types', ['id' => '7', 'name' => 'CL']);

foreach (['1', '2', '3', '9'] as $n) {
    $store->put('leaves', ['id' => 'r' . $n, 'employee_id' => $n, 'type_id' => '7',
        'from_date' => '2026-10-0' . $n, 'to_date' => '2026-10-0' . $n, 'days' => '1',
        'reason' => 'x', 'status' => 'pending', 'created_at' => '2026-09-0' . $n . 'T10:00:00']);
}

$leave = new Leave($store);

$reportsTo = static function (string $id) use ($store): ?string {
    $m = (string) ($store->find('employees', $id)['reporting_manager_id'] ?? '');

    return $m === '' ? null : $m;
};

/** A manager: reaches by reporting line, holds no leave permission of their own. */
$asManager = static fn (string $me): Scope => new Scope($me, $reportsTo(...));
$noPermission = static fn (string $p): bool => false;

$codesFor = static function (Scope $scope, callable $may) use ($store, $leave): array {
    $out = array_map(static fn (array $r): string => (string) $r['employment_id'],
        leaveQueueFor($store, $leave, $scope, $may));
    sort($out);

    return $out;
};

// --- down the whole line ------------------------------------------------------

same(['E-1'], $codesFor($asManager('2'), $noPermission),
    '2 sees their direct report');
same(['E-1', 'E-2'], $codesFor($asManager('3'), $noPermission),
    '3 sees 2 AND 1 — the skip-level, which is the whole point');
same(['E-1', 'E-2', 'E-3'], $codesFor($asManager('4'), $noPermission),
    '4 sees three levels down');

// --- never upward, never sideways, never your own -----------------------------

$queue1 = $codesFor($asManager('1'), $noPermission);
ok(!in_array('E-2', $queue1, true), '1 does NOT get their own manager\'s leave to approve');
same([], $queue1, 'somebody nobody reports to has an empty queue');
ok(!in_array('E-9', $codesFor($asManager('4'), $noPermission), true),
    'an unrelated line stays out of it');

foreach (['2', '3', '4'] as $me) {
    ok(!in_array('E-' . $me, $codesFor($asManager($me), $noPermission), true),
        "{$me} never finds their OWN request in their queue — nobody decides their own");
}

// --- self-approval is the one named exception ---------------------------------
// config/leave-policy.json self_approval_roles, e.g. Company Admin: nobody above
// them to ask. It is opt-in per role and must not leak in by default.

$withSelf = array_map(static fn (array $r): string => (string) $r['employment_id'],
    leaveQueueFor($store, $leave, $asManager('3'), $noPermission, null, true));
sort($withSelf);
same(['E-1', 'E-2', 'E-3'], $withSelf, 'with self-approval on, their own request joins the queue');

// --- an account with nobody behind it ----------------------------------------
// "We could not work out who you are" must never resolve to "so here is
// everyone" — the queue is the screen where that would be an approval.

same([], $codesFor(new Scope(null, $reportsTo(...)), $noPermission),
    'an unbound account has nothing to approve');

// --- the permission route is unchanged ----------------------------------------
// A role holding leave.request.decide and leading no team reads its scope, which
// for an administrator is the tenant. Everyone but themselves.

$admin = new Scope('4');
$mayAll = static fn (string $p): bool => true;
same(['E-1', 'E-2', 'E-3', 'E-9'], $codesFor($admin, $mayAll),
    'a leave approver with tenant reach sees every pending request but their own');
