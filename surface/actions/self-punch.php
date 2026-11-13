<?php
/**
 * PUNCH IN / OUT FROM THE WEB APP — SmartPRS 2's Daily Attendance button.
 *
 * The signed-in person's OWN employee only (Scope::own — nothing is taken
 * from the form), the direction decided here from today's open punches, never
 * typed: an in-punch left open means the next one is OUT. Optional GPS from the
 * browser rides along as the reference so a geo-fence rule can judge it later.
 */
declare(strict_types=1);

if (!$may('ess.attendance.punch')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'ess.attendance.punch')->summary;
    header('Location: ?r=punch');
    exit;
}

$ownId = $scope->own();
$ownRow = $ownId === null ? null : $store->find('employees', $ownId);

if ($ownRow === null) {
    $_SESSION['flash_problem'] = 'This account is not bound to an employee, so there is nobody to punch for.';
    header('Location: ?r=punch');
    exit;
}

$code = SmartPRS\Surface\Employment::code($ownRow);
// THE COMPANY'S ATTENDANCE SOURCE (owner, 9 Nov 2026; Time & Attendance → QR Attendance): SmartEPT punch = attendance comes
// from SmartEPT only, so no punch here; QR Attendance = only a scan of the gate QR in the SmartPRS3 app counts; Standard = as before.
$qrAtt = SmartPRS\Surface\QrAttendance::load($customisation, __DIR__ . '/../..');
$qrSource = $qrAtt->modeFor($ownRow); // Office staff: QR by default (owner, 12 Nov 2026)

if ($qrSource === 'smartept' || $qrSource === 'biometric') {
    // Biometric (owner, 12 Nov 2026): attendance comes from the company's biometric device only — one source per company.
    $_SESSION['flash_problem'] = $qrSource === 'smartept' ? 'Your company records attendance through SmartEPT. Punch in SmartEPT; it reaches SmartPRS3 by itself.'
        : 'Your company records attendance on the biometric device. Punch on the device at your office; it reaches SmartPRS3 by itself.';
    header('Location: ?r=punch');
    exit;
}

if ($qrSource === 'qr' && (post('qr') === '' || ($_COOKIE['sprs_app_device'] ?? '') === '')) {
    $_SESSION['flash_problem'] = 'Your company uses QR Attendance: open the SmartPRS3 app → Punch → Scan QR at your office gate.';
    header('Location: ?r=punch');
    exit;
}
// IN, THEN OUT, THEN IN… (owner, 13 Nov 2026: "if already punched in, it should show Punched out after punching again"):
// the direction follows the employee's LAST punch today, whatever the shift times. It used to come from the shift-window
// pairing, which never saw punches outside the shift as open, so every scan was taken as IN.
$todayRaw = array_values(array_filter($attendance->punchesFor($code), static fn (array $p): bool => substr((string) $p['at'], 0, 10) === date('Y-m-d')));
$direction = $todayRaw !== [] && (string) end($todayRaw)['direction'] === 'in' ? 'out' : 'in';
// A DAY OFF (W.OFF, holiday, approved full-day leave — owner, 14 Oct): the punch is allowed and
// kept, but it counts only once the reporting line approves it, so a regularization is raised
// for it now. Held punches are not in $today, so the direction comes from today's raw punches.
$offWhy = $attendance->offDayReason($code, date('Y-m-d'));
$lat = trim(post('lat'));
$lng = trim(post('lng'));
$reference = ($lat !== '' && $lng !== '' && is_numeric($lat) && is_numeric($lng)) ? "gps:$lat,$lng" : null;
$now = date('Y-m-d H:i:s');
$punchSource = 'web';

// QR SCAN (SmartEPT's checks): the gate's code, its age, the gate, the employee's branches (Home + handled), GPS accuracy,
// distance from the gate, a repeat scan. The gate's fence replaces the personal Geo-Fence Rule for a QR punch.
if ($qrSource === 'qr') {
    $qrBranches = array_values(array_unique(array_filter([(string) ($ownRow['branch_id'] ?? ''), ...branchAdminBranches((string) ($ownRow['admin_branches'] ?? ''))])));
    $qrCoords = [];
    foreach (liveRows($store, 'branches') as $qrB) {
        $qrCoords[(string) $qrB['id']] = ['lat' => $qrB['lat'] ?? '', 'lng' => $qrB['lng'] ?? ''];
    }
    $qrLast = null;
    foreach ($attendance->punchesFor($code) as $qrP) {
        (string) $qrP['source'] === SmartPRS\Surface\QrAttendance::SOURCE && $qrLast = (string) $qrP['at'];
    }
    $qrCheck = $qrAtt->verify(post('qr'), $qrBranches, $qrCoords, $lat, $lng, trim(post('accuracy')), $qrLast, null, (string) ($ownRow['employer_company_id'] ?? ''), (string) $ownRow['id']);

    if (!$qrCheck['ok']) {
        $audit('attendance.punch.refused_qr', 'punch', $code, null, ['at' => $now, 'reason' => $qrCheck['code'], 'gps' => $reference], $qrCheck['why']);
        $_SESSION['flash_problem'] = 'QR punch refused. ' . $qrCheck['why'];
        header('Location: ?r=punch');
        exit;
    }

    $punchSource = SmartPRS\Surface\QrAttendance::SOURCE;
    $reference = 'qr:' . $qrCheck['gate']['code'] . ':' . $qrCheck['window'] . ($reference === null ? '' : '|' . $reference)
        . (isset($qrCheck['distance_m']) ? '|' . $qrCheck['distance_m'] . 'm' : '') . (($qrCheck['exempt'] ?? false) ? '|geofence-exception' : '');
}

// THE GEO-FENCE DECIDES THE REGULAR PUNCH, IN AND OUT (owner, 12 Oct; 13 Nov 2026: "Punch IN or Punch OUT within that
// geofence"): inside the person's fence, or refused with the distance. Nobody fenced = no check. A geo-fence exception
// (Time & Attendance → QR Attendance → Exceptions, for the person at their branch) lets them punch from anywhere.
$fenceExempt = $qrAtt->geofenceExempt((string) $ownRow['id'], (string) ($ownRow['branch_id'] ?? ''), date('Y-m-d'));

if ($punchSource !== SmartPRS\Surface\QrAttendance::SOURCE && !$fenceExempt) {
    $fenceCheck = (new SmartPRS\Surface\GeoFence($store))->check($ownRow, $lat, $lng);

    if (!$fenceCheck['ok']) {
        $audit('attendance.punch.refused_geofence', 'punch', $code, null, ['at' => $now, 'direction' => $direction, 'gps' => $reference], $fenceCheck['why']);
        $_SESSION['flash_problem'] = 'Punch ' . strtoupper($direction) . ' refused. ' . $fenceCheck['why'];
        header('Location: ?r=punch');
        exit;
    }
}

try {
    $attendance->record($code, $now, $direction, $punchSource, $reference);
    $audit('attendance.punch.recorded', 'punch', $code, null,
        ['at' => $now, 'direction' => $direction, 'source' => $punchSource, 'gps' => $reference], $punchSource === 'web' ? 'Self punch from the web app' : 'QR punch at ' . $qrCheck['gate']['name']);
    $_SESSION['flash_notice'] = 'Punched ' . strtoupper($direction) . ' at ' . substr($now, 11, 5) . ($punchSource !== 'web' ? ' — ' . $qrCheck['why']
        : ($fenceExempt ? ' (geo-fence exception)' : ($reference === null ? ' (no location)' : ' with location')));

    if ($offWhy !== null) {
        $offReason = 'Worked on ' . ($offWhy === 'leave' ? 'a day of approved leave' : 'a ' . $offWhy) . ' — punched ' . $direction . ($punchSource === 'web' ? ' from the web app' : ' by QR at ' . $qrCheck['gate']['name']);
        $offReq = $regularisation->request($code, date('Y-m-d'), $now, $direction, $offReason);

        if ($offReq['ok']) {
            $audit('attendance.regularisation.requested', 'regularisation', $offReq['request']['id'], null,
                ['date' => date('Y-m-d'), 'at' => $now, 'direction' => $direction], $offReason);
            requestNotify($ownRow, 'Attendance regularization', 'regularization', (string) $offReq['request']['id'],
                $direction . ' ' . substr($now, 11, 5) . ' on ' . SmartPRS\Surface\Dates::dmy(date('Y-m-d')) . '. Reason: ' . $offReason);
        }

        $_SESSION['flash_notice'] .= '. Today is ' . ($offWhy === 'leave' ? 'a day of approved leave' : 'a ' . $offWhy)
            . ': this punch counts only after your reporting manager approves it'
            . ($offReq['ok'] ? ' — sent for approval (Attendance Regularization).' : ' — ' . $offReq['why']);
    }
} catch (\Throwable $e) {
    $_SESSION['flash_problem'] = $e->getMessage();
}

header('Location: ?r=punch');
exit;
