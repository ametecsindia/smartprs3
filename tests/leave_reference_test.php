<?php

declare(strict_types=1);

/**
 * A LEDGER ENTRY'S REFERENCE MUST NAME WHOSE ENTRY IT IS.
 *
 * `Leave::credit()` treats an entry as already posted when the EMPLOYMENT and the
 * reference both match. The unique index on `leave_entries` is
 * `(tenant_id, source_reference)` — no employment in it. So while the references
 * read `opening:CL` and `accrual:CL:2026-01`, the code's rule and the database's
 * rule were different rules, and the database's was narrower.
 *
 * The consequence: adding the SECOND employment to a tenant fataled on a
 * duplicate key, after the employee row had already been written. A person on the
 * books, no opening leave balance, and a 500 on the screen. Invisible on the file
 * store, which has no unique index at all.
 *
 * This test asserts the invariant that makes the tenant-wide index mean what the
 * code means: every reference this class writes carries the employment.
 */

use SmartPRS\Surface\Leave;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\Store;

group('leave references');

$store = (new Store(sys_get_temp_dir() . '/prs3-lr-' . bin2hex(random_bytes(4))))->forTenant('1');
$leave = new Leave($store, new LeavePolicy(__DIR__ . '/../config/leave-policy.json'));

// TWO employments, the case that used to fatal.
$leave->openBalances('SP-001', '2026-01-01');
$leave->openBalances('SP-002', '2026-01-01');
$leave->accrueThrough('SP-001', '2026-01-01', '2026-03-31');
$leave->accrueThrough('SP-002', '2026-01-01', '2026-03-31');

$entries = $store->all('leave_entries');

// NON-VACUITY: there must BE entries, or every assertion below is about nothing.
ok(count($entries) > 4, sprintf(
    'Two employments must produce a ledger with entries for both — got %d.', count($entries)));

$byEmployment = [];

foreach ($entries as $entry) {
    $byEmployment[(string) $entry['employment_id']][] = (string) $entry['source_reference'];
}

ok(isset($byEmployment['SP-001'], $byEmployment['SP-002']),
    'BOTH employments have ledger entries — the second one used to collide with the first.');

// THE INVARIANT. A tenant-wide unique index only means what the code means while
// every reference carries the employment.
$seen = [];

foreach ($entries as $entry) {
    $reference = (string) $entry['source_reference'];
    $employment = (string) $entry['employment_id'];

    ok(str_contains($reference, $employment), sprintf(
        'Reference "%s" must carry the employment (%s) — the unique index is tenant-wide, so a '
        . 'reference that names only the leave type collides with every other employment.',
        $reference, $employment));

    ok(!isset($seen[$reference]), sprintf(
        'Reference "%s" appears twice in one tenant, which the database refuses.', $reference));
    $seen[$reference] = true;
}

// Posting the same thing twice is still refused — the dedupe still works.
$before = count($store->all('leave_entries'));
$leave->openBalances('SP-001', '2026-01-01');
$leave->accrueThrough('SP-001', '2026-01-01', '2026-03-31');

same($before, count($store->all('leave_entries')),
    'Re-running opening balances and accrual posts nothing new — idempotence is what the reference '
    . 'is for, and it must survive the employment being added to it.');

// A ledger written under the OLD reference format is still recognised, so
// re-running an accrual over existing rows does not post a second copy.
$legacy = (new Store(sys_get_temp_dir() . '/prs3-lg-' . bin2hex(random_bytes(4))))->forTenant('1');
$legacy->put('leave_entries', ['employment_id' => 'SP-001', 'leave_type' => 'CL',
    'kind' => 'opening', 'days' => '6.0000', 'effective_on' => '2026-01-01',
    'reason' => 'Opening balance on joining', 'source_reference' => 'opening:CL']);

$over = new Leave($legacy, new LeavePolicy(__DIR__ . '/../config/leave-policy.json'));
$over->openBalances('SP-001', '2026-01-01');

$openings = array_values(array_filter($legacy->all('leave_entries'),
    static fn (array $e): bool => (string) $e['leave_type'] === 'CL'
        && (string) $e['kind'] === 'opening'));

same(1, count($openings),
    'A row written under the old reference format is still matched, so the fix does not double '
    . 'every opening balance already in a customer\'s ledger.');
