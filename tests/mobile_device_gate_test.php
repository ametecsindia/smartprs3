<?php

declare(strict_types=1);

use SmartPRS\Surface\MobileDevices;

group('mobile_device_gate');

// Owner, 5 Nov 2026: the employee app works only from a phone the Admin approved; a revoked phone cannot sign in.
$rows = [
    ['id' => '1', 'device_id' => 'P1', 'status' => 'approved', 'employee_id' => '7'],
    ['id' => '2', 'device_id' => 'P2', 'status' => 'pending', 'employee_id' => '7'],
    ['id' => '3', 'device_id' => 'P3', 'status' => 'revoked', 'employee_id' => '7', 'reject_reason' => 'Phone lost.'],
    ['id' => '4', 'device_id' => 'P4', 'status' => 'approved', 'employee_id' => null],
    ['id' => '5', 'device_id' => 'P5', 'status' => 'approved', 'employee_id' => '7', 'deleted_at' => '2026-11-01 10:00:00'],
    ['id' => '6', 'device_id' => 'P6', 'status' => 'approved', 'employee_id' => '7'],
    ['id' => '7', 'device_id' => 'P6', 'status' => 'revoked', 'employee_id' => '7'],
    ['id' => '8', 'device_id' => 'P8', 'status' => 'approved', 'employee_id' => null, 'user_id' => '42'],
];

same(null, MobileDevices::gate($rows, 'P1', '7'), 'approved phone, its own employee: allowed');
ok(MobileDevices::gate($rows, 'P1', '8') !== null, 'approved phone, a different employee: refused');
ok(MobileDevices::gate($rows, 'P1', '') !== null, 'approved phone, an account bound to no employee: refused');
ok(MobileDevices::gate($rows, 'P2', '7') !== null, 'pending phone: refused');
same('Phone lost.', MobileDevices::gate($rows, 'P3', '7'), 'revoked phone: refused with the reason the Admin gave');
same(null, MobileDevices::gate($rows, 'P4', '9'), 'approved phone not yet linked: allowed (the first sign-in links it)');
ok(MobileDevices::gate($rows, 'P5', '7') !== null, 'a deleted registration does not count');
ok(MobileDevices::gate($rows, 'NOPE', '7') !== null, 'an unknown phone: refused');
ok(MobileDevices::gate($rows, 'P6', '7') !== null, 'the newest registration of a phone decides');

// One app for everybody (6 Nov 2026): a phone registered by a login with no employee record is that login's only.
same(null, MobileDevices::gate($rows, 'P8', '', '42'), 'admin phone, its own login: allowed');
ok(MobileDevices::gate($rows, 'P8', '7', '50') !== null, 'admin phone, somebody else: refused');
same(null, MobileDevices::gate($rows, 'P1', '7', '50'), 'employee phone still decided by the employee');
