<?php
/**
 * BIOMETRIC SYNC / TEST / MAP (SmartPRS 2 BiometricConfigController sync, test, mapEmployee).
 *
 *   sync  pull the last N days from an eTimeOffice / eTimeTrackLite / Generic device and ingest
 *   test  pull the last day WITHOUT writing — shows what the server answers
 *   map   device id → employee: sets the employee's device user id (cleared from anyone else
 *         holding it) and moves every held punch for it into attendance
 */
declare(strict_types=1);

$bioBack = '?r=' . (post('back') === 'qr-attendance&qp=unmatched' ? 'qr-attendance&qp=unmatched' : 'biometric-mapping'); // QR Attendance → Unmatched punches (12 Nov 2026) matches here too

if (!$may('tenant.settings.update') || $tenantId === '' || $pdo === null) {
    $_SESSION['flash_problem'] = 'Denied, or no database: biometric sync needs a company workspace on MySQL.';
    header('Location: ' . $bioBack);
    exit;
}

$bioOp = post('op');

if ($bioOp === 'sync' || $bioOp === 'test') {
    $cfg = $store->find('biometric_configs', post('id'));

    if ($cfg === null) {
        $_SESSION['flash_problem'] = 'No such device.';
    } else {
        @set_time_limit(180);
        $r = (new SmartPRS\Surface\BiometricSync($store, $pdo, $tenantId, dirname(__DIR__, 2)))->run($cfg, $bioOp === 'test' ? 1 : max(1, (int) post('days')), $bioOp === 'test');
        $_SESSION['bio_result'] = $r + ['title' => ($bioOp === 'test' ? 'Test of ' : 'Sync of ') . (string) ($cfg['label'] ?? 'device') . ($bioOp === 'test' ? ' (nothing written)' : '')];
        $audit('biometric.' . $bioOp, 'biometric_configs', (string) $cfg['id'], null, ['fetched' => $r['fetched'], 'accepted' => $r['accepted'], 'error' => $r['error']], 'manual');
    }
} elseif ($bioOp === 'map') {
    $emp = $store->find('employees', post('employee'));
    $code = trim(post('device_code'));

    // ONE ATTENDANCE SOURCE PER COMPANY (owner, 12 Nov 2026): SmartEPT punches never land on a QR Attendance company's employee.
    $bioFromEpt = false;
    foreach ($store->all('biometric_unmapped') as $u) {
        strcasecmp((string) ($u['device_code'] ?? ''), $code) === 0 && (string) ($u['source'] ?? '') === 'smartept' && $bioFromEpt = true;
    }
    $bioQrCo = $emp !== null && $bioFromEpt && SmartPRS\Surface\QrAttendance::load($customisation, dirname(__DIR__, 2))->takesSmartEpt((string) ($emp['employer_company_id'] ?? '')) === false;

    if ($emp === null || $code === '') {
        $_SESSION['flash_problem'] = 'Choose an employee to map this device id to.';
    } elseif ($bioQrCo) {
        $_SESSION['flash_problem'] = SmartPRS\Surface\Employment::label($emp) . '\'s company records attendance by QR Attendance or the biometric device, so SmartEPT punches are not taken for them (one source per company — QR Attendance → Module & policy).';
    } else {
        foreach ($store->all('employees') as $other) {
            if ((string) $other['id'] !== (string) $emp['id'] && strcasecmp(trim((string) ($other['device_user_id'] ?? '')), $code) === 0) {
                $store->put('employees', ['id' => $other['id'], 'device_user_id' => null]);
            }
        }

        $store->put('employees', ['id' => $emp['id'], 'device_user_id' => $code]);
        $moved = (new SmartPRS\Surface\PunchIngest($store, $pdo, $tenantId))->promote($code, SmartPRS\Surface\Employment::code($emp));
        $audit('biometric.mapped', 'employees', (string) $emp['id'], ['device_user_id' => $emp['device_user_id'] ?? null], ['device_user_id' => $code], $moved . ' held punch(es) moved into attendance');
        $_SESSION['flash_notice'] = 'Device id ' . $code . ' is now ' . SmartPRS\Surface\Employment::label($emp) . ' — ' . $moved . ' held punch(es) moved into attendance.';
    }
}

header('Location: ' . $bioBack);
exit;
