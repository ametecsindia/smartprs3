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
$qrSource = $qrAtt->sourceFor((string) ($ownRow['employer_company_id'] ?? ''));

if ($qrSource === 'smartept') {
    $_SESSION['flash_problem'] = 'Your company records attendance through SmartEPT. Punch in SmartEPT; it reaches SmartPRS3 by itself.';
    header('Location: ?r=punch');
    exit;
}

if ($qrSource === 'qr' && (post('qr') === '' || ($_COOKIE['sprs_app_device'] ?? '') === '')) {
    $_SESSION['flash_problem'] = 'Your company uses QR Attendance: open the SmartPRS3 app → Punch → Scan QR at your office gate.';
    header('Location: ?r=punch');
    exit;
}
$today = $attendanceReport->day($code, date('Y-m-d'), $calendar->shiftStart(), $calendar->shiftEnd());
$open = array_filter($today['unpaired'], static fn (array $p): bool => $p['direction'] === 'in');
$direction = $open === [] ? 'in' : 'out';
// A DAY OFF (W.OFF, holiday, approved full-day leave — owner, 14 Oct): the punch is allowed and
// kept, but it counts only once the reporting line approves it, so a regularization is raised
// for it now. Held punches are not in $today, so the direction comes from today's raw punches.
$offWhy = $attendance->offDayReason($code, date('Y-m-d'));
if ($offWhy !== null) {
    $raw = array_values(array_filter($attendance->punchesFor($code), static fn (array $p): bool => substr((string) $p['at'], 0, 10) === date('Y-m-d')));
    $direction = $raw !== [] && (string) end($raw)['direction'] === 'in' ? 'out' : 'in';
}
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

// THE GEO-FENCE DECIDES PUNCH-IN (owner, 12 Oct): inside the person's fence, or refused with the
// distance. Nobody fenced = no check. Punch-out is recorded wherever it happens, with its location.
if ($direction === 'in' && $punchSource !== SmartPRS\Surface\QrAttendance::SOURCE) {
    $fenceCheck = (new SmartPRS\Surface\GeoFence($store))->check($ownRow, $lat, $lng);

    if (!$fenceCheck['ok']) {
        $audit('attendance.punch.refused_geofence', 'punch', $code, null, ['at' => $now, 'gps' => $reference], $fenceCheck['why']);
        $_SESSION['flash_problem'] = 'Punch-in refused. ' . $fenceCheck['why'];
        header('Location: ?r=punch');
        exit;
    }
}

try {
    $attendance->record($code, $now, $direction, $punchSource, $reference);
    $audit('attendance.punch.recorded', 'punch', $code, null,
        ['at' => $now, 'direction' => $direction, 'source' => $punchSource, 'gps' => $reference], $punchSource === 'web' ? 'Self punch from the web app' : 'QR punch at ' . $qrCheck['gate']['name']);
    $_SESSION['flash_notice'] = 'Punched ' . strtoupper($direction) . ' at ' . substr($now, 11, 5) . ($punchSource !== 'web' ? ' — ' . $qrCheck['why'] : ($reference === null ? ' (no location)' : ' with location'));

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
