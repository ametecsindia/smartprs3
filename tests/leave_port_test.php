<?php

declare(strict_types=1);

/**
 * THE LEAVE ROW THAT WAS NEVER SHAPED LIKE THAT.
 *
 * `Leave` was written against a request row with `employment_id`, `state`,
 * `from`, `to`, `requested_at` and `leave_type` on it. The ported `leaves` table
 * has `employee_id` (an `employees.id`, not a code), `status`, `from_date`,
 * `to_date`, `created_at` and `type_id`. The same defect as `employee_code`, and
 * invisible for the same reason: the file store returns whatever key it was
 * handed, so the fixtures agreed with the mistake.
 *
 * The smoke check found the first name and would NOT have found the other five —
 * the filter never matched, so the screen complained once and then quietly
 * showed nothing. So the shape is asserted here, against the schema the
 * migrations build rather than against a fixture.
 */

use SmartPRS\Surface\Leave;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\Store;

group('leave port');

require_once __DIR__ . '/lib/laravel_schema_stub.php';

Illuminate\Support\Facades\Schema::reset();

foreach (glob(__DIR__ . '/../database/migrations/*.php') as $file) {
    (require $file)->up();
}

$leaves = Illuminate\Support\Facades\Schema::$blueprints['leaves'] ?? null;

ok($leaves !== null, 'the migrations create a leaves table');
ok($leaves->has('employee_id'),
    'the column `leaves` names the person by is `employee_id` — this file read `employment_id`');
ok(!$leaves->has('employment_id'),
    'and `employment_id` must NOT be on it, or both names would be right and neither authoritative');

$store = (new Store(sys_get_temp_dir() . '/prs3-leaveport-' . bin2hex(random_bytes(4))))
    ->forTenant('1');
$store->put('employees', ['id' => '17', 'tenant_id' => '1', 'emp_code' => 'SP-001', 'name' => 'A']);
$store->put('leave_types', ['id' => '4', 'tenant_id' => '1', 'name' => 'Casual Leave']);

// A row exactly as the ported table hands it back: ids as ints, schema names.
$store->put('leaves', [
    'id' => 5, 'tenant_id' => '1', 'employee_id' => 17, 'type_id' => 4,
    'from_date' => '2026-08-26', 'to_date' => '2026-08-27', 'days' => '2.0000',
    'status' => Leave::APPROVED, 'reason' => 'Family function',
    'created_at' => '2026-08-20 09:00:00',
]);

$leave = new Leave($store, new LeavePolicy(__DIR__ . '/../config/leave-policy.json'));
$requests = $leave->requestsFor('SP-001');

same(1, count($requests), 'a ported row is found by the employment CODE, not by the numeric id');
same(Leave::APPROVED, $requests[0]['state'], '`status` reads as the state the screens ask for');
same('2026-08-26', $requests[0]['from'], '`from_date` reads as `from`');
same('2026-08-27', $requests[0]['to'], '`to_date` reads as `to`');
same('2026-08-20 09:00:00', $requests[0]['requested_at'], '`created_at` reads as `requested_at`');
same('CL', $requests[0]['leave_type'],
    '`type_id` resolves to the policy CODE — payroll decides paid or unpaid by code, so a leave '
    . 'type left as a numeric id is a paid day counted as loss of pay');
same(2, $leave->approvedDaysIn('SP-001', '2026-08-01', '2026-08-31'),
    'and payroll sees the approved days, which is what a ported row silently cost it');

// A row already in the old shape still reads: the fallback is for the file store
// and for fixtures written before the port, and dropping it would turn a naming
// defect into missing data.
$store->put('leaves', [
    'id' => '6', 'tenant_id' => '1', 'employment_id' => 'SP-001', 'leave_type' => 'SL',
    'from' => '2026-09-03', 'to' => '2026-09-03', 'days' => '1', 'state' => Leave::PENDING,
    'reason' => 'Fever', 'requested_at' => '2026-09-01T09:00:00+05:30',
]);

same(2, count($leave->requestsFor('SP-001')), 'a pre-port row is still read');

// `leave_entries.id` is a BIGINT, and PDO hands it back as a native int with
// emulated prepares off — which `LeaveLedgerEntry`'s `string $id` rejects
// outright. Nothing on the smoke check's subject had a ledger entry, so the
// route rendered clean over a fatal waiting for the first employment that did.
$store->put('leave_entries', [
    'id' => 1, 'tenant_id' => '1', 'employment_id' => 'SP-001', 'leave_type' => 'CL',
    'kind' => 'opening', 'days' => '12.0000', 'effective_on' => '2026-03-01',
    'reason' => 'Opening balance on joining', 'source_reference' => 'opening:CL',
]);

same('12', (string) $leave->balance('SP-001', 'CL', new DateTimeImmutable('2026-09-07')),
    'an entry whose id came back as an int still becomes a ledger entry');
