<?php

declare(strict_types=1);

use SmartPRS\Surface\QrAttendance;

/**
 * Owner, 13 Nov 2026: "QR Attendance + Biometric" as one attendance source — the gate QR in the app and the biometric
 * device both count; the day's first IN and last OUT come from whichever was punched.
 */
group('qr + biometric');

$cfg = json_decode((string) file_get_contents(dirname(__DIR__) . '/config/qr-attendance.json'), true);
ok(isset($cfg['sources']['qr_biometric']), 'the source is offered in Module & policy');
$qa = new QrAttendance($cfg, ['sources' => ['C1' => 'qr_biometric'], 'gates' => [['code' => 'G1', 'branch_id' => 'B1', 'active' => true]]]);
same('qr_biometric', $qa->sourceFor('C1'), 'saved and read back as chosen');
same('qr', $qa->modeFor(['employer_company_id' => 'C1', 'branch_id' => 'B1']), 'in the app it punches by the gate QR');
same('standard', $qa->modeFor(['employer_company_id' => 'C1', 'branch_id' => 'B2']), 'no gate at the branch: the regular punch, as for QR');
ok($qa->usesGates('C1'), 'its gates and QR rules are shown and used');
ok($qa->takesSmartEpt('C1') === false, 'SmartEPT punches are not taken (one source of each kind)');
ok($qa->takesSmartEpt('C9'), 'a Standard company still takes SmartEPT punches');
