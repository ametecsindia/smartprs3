<?php
/**
 * REGISTER, APPROVE, REJECT OR REVOKE A MOBILE DEVICE.
 *
 * `op` is one of config/mobile-devices.json's transitions, or `register`.
 * The row gets its new status, who decided and when; the reason (shown on the
 * phone) is kept on the row and in the audit trail.
 */
declare(strict_types=1);

if (!$may('tenant.settings.update')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary;
    header('Location: ?r=mobile-devices');
    exit;
}

$devices = new SmartPRS\Surface\MobileDevices($store, __DIR__ . '/../../config/mobile-devices.json');
$op = post('op');
$now = date('Y-m-d H:i:s');
$by = (string) $session->user()['email'];

if ($op === 'register') {
    $result = $devices->register($_POST, $now);

    if ($result['ok']) {
        $audit('mobile_device.registered', 'mobile_devices', (string) $result['row']['id'], null,
            ['device_id' => $result['row']['device_id'], 'employee_id' => $result['row']['employee_id'], 'status' => $result['row']['status']], 'registered by hand');
    }
} else {
    $row = post('id') === '' ? null : $store->find((string) $devices->declared()['table'], post('id'));
    $result = $row === null ? ['ok' => false, 'why' => 'No such device.'] : $devices->decide($row, $op, post('reason'), $by, $now);

    if ($result['ok']) {
        $audit('mobile_device.' . $op, 'mobile_devices', (string) $row['id'], ['status' => (string) $row['status']],
            ['status' => $result['row']['status'], 'approved_by' => $by], trim(post('reason')) === '' ? $op : trim(post('reason')));
    }
}

$_SESSION[$result['ok'] ? 'flash_notice' : 'flash_problem'] = $result['why'];
// QR Attendance → Phones decides the same rows and comes back there (owner, 12 Nov 2026); anything else → Mobile Devices.
header('Location: ?r=' . (preg_match('/^qr-attendance&qp=phones(&s=[a-z]+)?$/', post('back')) === 1 ? post('back') : 'mobile-devices'));
exit;
