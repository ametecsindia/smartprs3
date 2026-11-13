<?php

declare(strict_types=1);

use SmartPRS\Surface\QrAttendance;

/**
 * Owner, 13 Nov 2026: the app keeps a normal Punch IN / OUT for field staff, judged by their Geo-Fence Rule, whatever
 * the company's attendance source (QR, biometric, SmartEPT). Office staff with no fence keep the company's source.
 */
group('field punch');

$cfg = json_decode((string) file_get_contents(dirname(__DIR__) . '/config/qr-attendance.json'), true);
$qa = new QrAttendance($cfg, ['sources' => ['C1' => 'qr', 'C2' => 'biometric'], 'gates' => [['code' => 'G1', 'branch_id' => 'B1', 'active' => true]],
    'exceptions' => [['kind' => 'geofence', 'employee_id' => 'E9', 'branch_id' => 'B1']]]);
$office = ['id' => 'E1', 'employer_company_id' => 'C1', 'branch_id' => 'B1'];
$field = ['id' => 'E2', 'employer_company_id' => 'C1', 'branch_id' => 'B1'];
ok(!$qa->regularPunch($office, false, true, '2026-11-13'), 'office staff at a QR gate with no fence: QR only');
ok($qa->regularPunch($field, true, true, '2026-11-13'), 'field staff with a geo-fence rule: normal Punch IN / OUT in the app');
ok($qa->regularPunch(['id' => 'E9', 'employer_company_id' => 'C1', 'branch_id' => 'B1'], false, true, '2026-11-13'), 'a geo-fence exception: normal punch too');
ok($qa->regularPunch(['id' => 'E3', 'employer_company_id' => 'C2', 'branch_id' => 'B1'], true, true, '2026-11-13'), 'biometric company, fenced field staff: normal punch');
ok(!$qa->regularPunch($field, true, false, '2026-11-13'), 'not in the app (web browser): the company source decides');
ok($qa->regularPunch(['id' => 'E4', 'employer_company_id' => 'C9', 'branch_id' => 'B5'], false, false, '2026-11-13'), 'standard company: normal punch as always');
