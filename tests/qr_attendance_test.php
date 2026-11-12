<?php

declare(strict_types=1);

use SmartPRS\Surface\QrAttendance;

group('qr_attendance');

// Owner, 9 Nov 2026: QR Attendance as in SmartEPT (rotating, signed gate code; scanned in the mobile app), no Gate-to-PC.
$config = json_decode((string) file_get_contents(__DIR__ . '/../config/qr-attendance.json'), true);
$gate = ['code' => 'G1', 'name' => 'Main gate', 'branch_id' => '5', 'lat' => '17.4600000', 'lng' => '78.3600000', 'radius_m' => 150, 'secret' => 'abc', 'active' => true];
$qr = new QrAttendance($config, ['sources' => ['1' => 'qr', '2' => 'smartept', '3' => 'nonsense'],
    'settings' => ['interval_s' => 3, 'grace_s' => 5, 'duplicate_window_min' => 2, 'gps_accuracy_m' => 50],
    'gates' => [$gate, ['code' => 'G2', 'name' => 'Old gate', 'branch_id' => '5', 'secret' => 'x', 'active' => false]]]);
$now = 1_800_000_000;
$t = $qr->token($gate, $now)['qr'];
$at = static fn (string $q, array $br = ['5'], string $lat = '17.4601', string $lng = '78.3600', string $acc = '10', ?string $last = null, ?int $when = null)
    => $qr->verify($q, $br, [], $lat, $lng, $acc, $last, $when ?? $now);

same('qr', $qr->sourceFor('1'), 'company set to QR');
same('smartept', $qr->sourceFor('2'), 'company set to SmartEPT');
same('standard', $qr->sourceFor('3'), 'an unknown source is Standard');
same('standard', $qr->sourceFor('9'), 'a company never set is Standard');
ok(preg_match('/^PRSQ1\.G1\.3\.\d+\.[0-9a-f]{20}$/', $t) === 1, 'token shape PRSQ1.<gate>.<interval>.<window>.<sig>');
same(true, $at($t)['ok'], 'fresh code, own branch, inside the fence: accepted');
same(true, $at($t, ['5'], '17.4601', '78.3600', '10', null, $now + 7)['ok'], 'within interval + grace: accepted');
same('QR_EXPIRED', $at($t, ['5'], '17.4601', '78.3600', '10', null, $now + 9)['code'], 'older than interval + grace: refused');
same('QR_EXPIRED', $at($t, ['5'], '17.4601', '78.3600', '10', null, $now - 5)['code'], 'from the future: refused');
same('QR_INVALID', $at(substr($t, 0, -1) . (substr($t, -1) === 'a' ? 'b' : 'a'))['code'], 'tampered signature: refused');
same('QR_INVALID', $at('hello')['code'], 'not a SmartPRS code: refused');
same('GATE_INACTIVE', $at($qr->token(['code' => 'G2', 'secret' => 'x'], $now)['qr'])['code'], 'switched-off gate: refused');
same('BRANCH_NOT_ASSIGNED', $at($t, ['6'])['code'], 'gate of another branch: refused');
same(true, $at($t, ['6', '5'])['ok'], 'a branch the employee handles counts');
same('NO_LOCATION', $at($t, ['5'], '', '')['code'], 'no GPS on a fenced gate: refused');
same('LOW_GPS_ACCURACY', $at($t, ['5'], '17.4601', '78.3600', '80')['code'], 'GPS worse than the setting: refused');
same('OUTSIDE_GEOFENCE', $at($t, ['5'], '17.4700', '78.3600')['code'], '1.1 km away: refused');
same('DUPLICATE', $at($t, ['5'], '17.4601', '78.3600', '10', date('Y-m-d H:i:s', $now - 60))['code'], 'repeat scan within 2 min: refused');
same(true, $at($t, ['5'], '17.4601', '78.3600', '10', date('Y-m-d H:i:s', $now - 180))['ok'], 'scan after the repeat window: accepted');
same(3, $qr->setting('interval_s'), 'setting read');
same(30, (new QrAttendance($config, ['settings' => ['interval_s' => 999]]))->setting('interval_s'), 'setting held to its allowed range');
same(150, (new QrAttendance($config, []))->setting('radius_m'), 'default from config');

// Each company has its own QR rules (owner, 9 Nov 2026 evening).
$qr2 = new QrAttendance($config, ['gates' => [$gate], 'company_settings' => ['1' => ['grace_s' => 0, 'interval_s' => 10], '2' => ['grace_s' => 15]]]);
same(10, $qr2->setting('interval_s', '1'), 'company 1 own interval');
same(3, $qr2->setting('interval_s', '2'), 'company 2 not set: default interval');
$t2 = $qr2->token($gate, $now, '2')['qr'];
same(true, $qr2->verify($t2, ['5'], [], '17.4601', '78.3600', '10', null, $now + 14, '2')['ok'], 'company 2 grace 15 s: accepted at 14 s');
same('QR_EXPIRED', $qr2->verify($t2, ['5'], [], '17.4601', '78.3600', '10', null, $now + 14, '1')['code'], 'same code under company 1 grace 0: refused');

// Per-gate overrides (owner, 9 Nov 2026): a gate's own interval / grace / GPS / repeat window; blank = company rule.
$gOwn = ['gps_accuracy_m' => 200, 'grace_s' => '', 'interval_s' => 10, 'duplicate_window_min' => 0] + $gate;
$qr3 = new QrAttendance($config, ['gates' => [$gOwn], 'company_settings' => ['1' => ['grace_s' => 1]]]);
same(10, $qr3->gateSetting($gOwn, 'interval_s', '1'), 'gate interval overrides company');
same(1, $qr3->gateSetting($gOwn, 'grace_s', '1'), 'blank gate grace = company rule');
$t3 = $qr3->token($gOwn, $now, '1');
same(10, $t3['interval'], 'token uses the gate interval');
same(true, $qr3->verify($t3['qr'], ['5'], [], '17.4601', '78.3600', '150', null, $now, '1')['ok'], 'gate GPS 200 m: 150 m accuracy accepted');
same(true, $qr3->verify($t3['qr'], ['5'], [], '17.4601', '78.3600', '10', date('Y-m-d H:i:s', $now - 30), $now, '1')['ok'], 'gate repeat window 0: no repeat check');

// Exceptions (owner, 9 Nov 2026): geo-fence exception per branch; phone approval not needed (demo / Android review) or per employee.
$day = date('Y-m-d', $now);
$qr4 = new QrAttendance($config, ['gates' => [$gate], 'phone_approval' => ['9' => 'auto'], 'exceptions' => [
    ['kind' => 'geofence', 'employee_id' => '7', 'branch_id' => '5', 'from' => '', 'to' => ''],
    ['kind' => 'geofence', 'employee_id' => '8', 'branch_id' => '5', 'from' => '2000-01-01', 'to' => '2000-01-31'],
    ['kind' => 'phone', 'employee_id' => '7', 'from' => $day, 'to' => $day]]]);
$far = static fn (?string $emp) => $qr4->verify($qr4->token($gate, $now)['qr'], ['5'], [], '17.5600', '78.3600', '900', null, $now, '1', $emp);
same(true, $far('7')['ok'], 'geo-fence exception (always): 11 km away, poor GPS — accepted');
same(true, $far('7')['exempt'], 'the punch is marked as an exception');
same('OUTSIDE_GEOFENCE', $far('8')['code'] === 'LOW_GPS_ACCURACY' ? 'OUTSIDE_GEOFENCE' : $far('8')['code'], 'expired exception: fence applies again');
same('LOW_GPS_ACCURACY', $far('9')['code'], 'no exception: fence applies');
same(true, $qr4->phoneAutoApproved('5', '9', ['9'], $day), 'company with no phone approval: approved at once');
same(true, $qr4->phoneAutoApproved('7', '1', ['1', '9'], $day), 'employee phone exception today: approved at once');
same(false, $qr4->phoneAutoApproved('7', '1', ['1', '9'], '2099-01-01'), 'phone exception outside its dates: needs approval');
same(false, $qr4->phoneAutoApproved('6', '1', ['1', '9'], $day), 'ordinary employee: needs approval');
same(false, $qr4->phoneAutoApproved(null, null, ['1', '9'], $day), 'login with no employee, one company still approves: needs approval');
same(true, $qr4->phoneAutoApproved(null, null, ['9'], $day), 'login with no employee, every company auto: approved');
