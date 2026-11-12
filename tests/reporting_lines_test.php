<?php

declare(strict_types=1);

use SmartPRS\Surface\ReportingLines;
use SmartPRS\Surface\Store;

/**
 * A REPORTING CYCLE IS A SECURITY FINDING, NOT A TIDINESS ONE.
 *
 * `Platform\Access\DataScope::reaches()` refuses a chain that repeats — "a cycle
 * is a data defect, never a licence to widen scope" — and it cannot fire against
 * this product: the surface hands it a chain ONE manager long, because
 * `employees.reporting_manager_id` is a flat column, and a one-element chain
 * cannot repeat. Nothing had ever asked whether the tenant's data contained one.
 *
 * It matters because the manager role reaches its direct reports. In a two-person
 * ring each IS the other's direct report, so each can read the other's salary,
 * leave and attendance — and every one of those reads is correctly authorised,
 * because the data says they are managers. Nothing looks broken. The answer is
 * just wrong, which is the kind that survives an audit.
 */

group('Reporting lines');

/** @param array<string, ?string> $reporting code => manager code (or null) */
$tenantOf = static function (array $reporting): ReportingLines {
    $store = (new Store(sys_get_temp_dir() . '/rl-' . bin2hex(random_bytes(4))))->forTenant('1');
    $id = 0;
    $ids = [];

    foreach (array_keys($reporting) as $code) {
        $ids[$code] = (string) ++$id;
    }

    foreach ($reporting as $code => $managerCode) {
        $store->put('employees', [
            'id' => $ids[$code],
            'emp_code' => $code,
            'name' => $code,
            // The column holds an employees.id, which is exactly the translation
            // that makes this class worth having rather than a one-line grep.
            'reporting_manager_id' => $managerCode === null ? '' : ($ids[$managerCode] ?? '999'),
        ]);
    }

    return new ReportingLines($store);
};

// ---------------------------------------------------------------------------
// A HEALTHY TREE HAS NOTHING TO REPORT. Without this every assertion below
// passes against a check that flags everybody.
// ---------------------------------------------------------------------------

$healthy = $tenantOf(['E-1' => null, 'E-2' => 'E-1', 'E-3' => 'E-2', 'E-4' => 'E-1']);

same([], $healthy->cycles(), 'an ordinary reporting tree has no cycles');
same([], $healthy->danglingManagers(), 'and no dangling managers');

// ---------------------------------------------------------------------------
// THE TWO-PERSON RING. The case a reorganisation typed in over an afternoon
// actually produces, and the one that grants two people each other's salary.
// ---------------------------------------------------------------------------

$ring = $tenantOf(['E-1' => 'E-2', 'E-2' => 'E-1', 'E-3' => 'E-1']);
$cycles = $ring->cycles();

same(1, count($cycles), 'a two-person ring is one cycle, reported once and not once per member');
same(['E-1', 'E-2'], $cycles[0]['members'],
    'and BOTH members are named — the first version marked a node after stepping past it, so the '
    . 'last member was never in the path and a ring of two read as one person managing themselves, '
    . 'which sends whoever has to fix it to one row instead of two');
ok(str_contains($cycles[0]['why'], 'read the others'),
    'and the reason says what it costs, not just that it is a cycle');

// E-3 hangs off the ring and is NOT part of it. Reporting "E-3 eventually loops"
// to somebody who has to fix this is less useful than naming the two in the ring.
ok(!in_array('E-3', $cycles[0]['members'], true),
    'somebody reporting INTO a ring is not reported as part of it');

// ---------------------------------------------------------------------------
// A SELF-MANAGING ROW is the one-element case and reads differently to whoever
// fixes it: a typo, not a half-finished reorganisation.
// ---------------------------------------------------------------------------

$itself = $tenantOf(['E-1' => 'E-1', 'E-2' => null]);
$self = $itself->cycles();

same(1, count($self), 'a row pointing at itself is a cycle');
same(['E-1'], $self[0]['members'], 'of one member');
ok(str_contains($self[0]['why'], 'its own manager'), 'and it says so in those words');

// ---------------------------------------------------------------------------
// A LONGER RING, to prove the walk is a walk and not a pair check.
// ---------------------------------------------------------------------------

$four = $tenantOf(['E-1' => 'E-2', 'E-2' => 'E-3', 'E-3' => 'E-4', 'E-4' => 'E-1']);
$found = $four->cycles();

same(1, count($found), 'a four-person ring is one cycle');
same(['E-1', 'E-2', 'E-3', 'E-4'], $found[0]['members'], 'with all four named');

// ---------------------------------------------------------------------------
// THE OTHER SILENT FAILURE. A manager id pointing at nothing means the person
// has no manager at all — their leave reaches nobody's queue — and the screen
// shows a manager because the column has a value in it.
// ---------------------------------------------------------------------------

$dangling = $tenantOf(['E-1' => null, 'E-2' => 'E-GONE']);
$missing = $dangling->danglingManagers();

same(1, count($missing), 'a manager id pointing at no employment is reported');
same('E-2', $missing[0]['employment'] ?? '', 'naming who is affected');
same([], $dangling->cycles(), 'and it is not confused with a cycle — they need different fixes');
