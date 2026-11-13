<?php

declare(strict_types=1);

use SmartPRS\Surface\QrAttendance;

/**
 * Owner, 13 Nov 2026: Punch is for everyone. Where QR Attendance is not available to an employee (no active gate at
 * their branch — a field agent), the regular Punch IN / OUT works, judged by that employee's geo-fence.
 */
group('punch without gate');

$cfg = json_decode((string) file_get_contents(dirname(__DIR__) . '/config/qr-attendance.json'), true);
$qa = new QrAttendance($cfg, ['sources' => ['C1' => 'qr'], 'gates' => [['code' => 'G1', 'branch_id' => 'B1', 'active' => true]],
    'exceptions' => [['kind' => 'geofence', 'employee_id' => 'E9', 'branch_id' => 'B2']]]);
same('qr', $qa->modeFor(['employer_company_id' => 'C1', 'branch_id' => 'B1']), 'a QR company employee at a gated branch punches by QR');
same('standard', $qa->modeFor(['employer_company_id' => 'C1', 'branch_id' => 'B2']), 'no gate at their branch = the regular punch, by geo-fence');
same('standard', $qa->modeFor(['employer_company_id' => 'C1', 'branch_id' => '']), 'no branch at all = the regular punch');
ok($qa->geofenceExempt('E9', 'B2', '2026-11-13'), 'a geo-fence exception for the person at their branch skips the fence');
