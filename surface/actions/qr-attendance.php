<?php
/**
 * QR ATTENDANCE — save the attendance source per company, the QR rules, or one gate (Time & Attendance →
 * QR Attendance, owner 9 Nov 2026). Everything is one versioned customisation (QrAttendance::customisationKey):
 * each save writes a new version holding the whole payload, so nothing is overwritten and "Reset link" leaves
 * the old secret only in history, where no gate screen can use it.
 */
declare(strict_types=1);

use SmartPRS\Surface\QrAttendance;

$qaBack = '?r=qr-attendance';
// Back to the QR Attendance page the form was on (owner, 12 Nov 2026: QR Attendance split into SmartEPT-style pages).
in_array(post('qp'), ['dashboard', 'ledger', 'phones', 'unmatched', 'employees', 'gates', 'policy'], true) && $qaBack .= '&qp=' . post('qp');

if (!$may('tenant.settings.update')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary;
    header('Location: ' . $qaBack);
    exit;
}

$qa = QrAttendance::load($customisation, __DIR__ . '/../..');
$qaData = $qa->saved();
$qaGates = $qa->gates();
$qaOp = post('op');
$qaProblem = null;
$qaNote = '';

if ($qaOp === 'company') {
    // ONE COMPANY'S BOX (owner, 9 Nov 2026 evening): its attendance source and its own QR rules, saved together.
    $qaCo = null;
    foreach (liveRows($store, 'companies') as $c) {
        (string) $c['id'] === post('company_id') && $qaCo = $c;
    }
    if ($qaCo === null || !array_key_exists(post('source'), $qa->sourceOptions())) {
        $qaProblem = 'Choose the company and its attendance source.';
    } else {
        $qaSettings = [];
        foreach ($qa->settingSpecs() as $k => $spec) {
            $v = trim(post('s_' . $k));
            if (preg_match('/^\d+$/', $v) !== 1 || (int) $v < (int) $spec['min'] || (int) $v > (int) $spec['max']) {
                $qaProblem = $spec['label'] . ' must be a whole number from ' . $spec['min'] . ' to ' . $spec['max'] . '.';
                break;
            }
            $qaSettings[$k] = (int) $v;
        }
        $qaData['sources'] = [(string) $qaCo['id'] => post('source')] + (array) ($qaData['sources'] ?? []);
        $qaData['company_settings'] = [(string) $qaCo['id'] => $qaSettings] + (array) ($qaData['company_settings'] ?? []);
        $qaData['phone_approval'] = [(string) $qaCo['id'] => post('phone_approval') === 'auto' ? 'auto' : 'required'] + (array) ($qaData['phone_approval'] ?? []);
        $qaNote = (string) $qaCo['name'] . ' saved — ' . $qa->sourceOptions()[post('source')];
    }
} elseif (in_array($qaOp, ['add-gate', 'edit-gate'], true)) {
    $qaBranch = null;
    foreach (liveRows($store, 'branches') as $b) {
        (string) $b['id'] === post('branch_id') && $qaBranch = $b;
    }
    $qaLat = trim(post('lat'));
    $qaLng = trim(post('lng'));
    $qaRadius = trim(post('radius_m'));
    $qaRSpec = $qa->settingSpecs()['radius_m'];

    if ($qaBranch === null) {
        $qaProblem = 'Choose the branch the gate is at.';
    } elseif (trim(post('name')) === '') {
        $qaProblem = 'Give the gate a name.';
    } elseif (($qaLat === '') !== ($qaLng === '') || ($qaLat !== '' && (!is_numeric($qaLat) || abs((float) $qaLat) > 90 || !is_numeric($qaLng) || abs((float) $qaLng) > 180))) {
        $qaProblem = 'Latitude (−90 to 90) and Longitude (−180 to 180) go together — give both, or leave both blank for the branch location.';
    } elseif ($qaRadius !== '' && (preg_match('/^\d+$/', $qaRadius) !== 1 || (int) $qaRadius < (int) $qaRSpec['min'] || (int) $qaRadius > (int) $qaRSpec['max'])) {
        $qaProblem = 'Geo-fence radius must be ' . $qaRSpec['min'] . ' to ' . $qaRSpec['max'] . ' metres.';
    } else {
        // The screen fills Latitude / Longitude from the branch (owner, 12 Nov 2026): the branch's own location saved back = follows the branch.
        if ($qaLat !== '' && is_numeric((string) ($qaBranch['lat'] ?? '')) && is_numeric((string) ($qaBranch['lng'] ?? ''))
            && abs((float) $qaLat - (float) $qaBranch['lat']) < 0.0000005 && abs((float) $qaLng - (float) $qaBranch['lng']) < 0.0000005) {
            $qaLat = $qaLng = '';
        }
        $qaRow = ['branch_id' => (string) $qaBranch['id'], 'name' => mb_substr(trim(post('name')), 0, 60), 'lat' => $qaLat, 'lng' => $qaLng,
            'radius_m' => $qaRadius === '' ? null : (int) $qaRadius];
        // PER-GATE RULES (owner, 9 Nov 2026): blank = the company's rule.
        foreach (QrAttendance::GATE_KEYS as $gk) {
            $gs = $qa->settingSpecs()[$gk];
            $gv = trim(post('g_' . $gk));
            if ($gv !== '' && (preg_match('/^\d+$/', $gv) !== 1 || (int) $gv < (int) $gs['min'] || (int) $gv > (int) $gs['max'])) {
                $qaProblem = $gs['label'] . ' must be blank (company rule) or ' . $gs['min'] . ' to ' . $gs['max'] . '.';
            }
            $qaRow[$gk] = $gv === '' ? '' : (int) $gv;
        }
    }
    if ($qaProblem === null && $qaBranch !== null && trim(post('name')) !== '') {

        if ($qaOp === 'add-gate') {
            $qaNum = 1 + array_reduce($qaGates, static fn (int $m, array $g): int => max($m, (int) substr((string) ($g['code'] ?? ''), 1)), 0);
            $qaGates[] = ['code' => 'G' . $qaNum] + $qaRow + ['secret' => QrAttendance::newSecret(), 'active' => true];
            $qaNote = 'Gate G' . $qaNum . ' added — open its gate screen on the tablet / TV at the entrance';
        } else {
            $qaFound = false;
            foreach ($qaGates as $i => $g) {
                if ((string) ($g['code'] ?? '') === post('code')) {
                    $qaGates[$i] = $qaRow + $g;
                    $qaFound = true;
                }
            }
            $qaFound || $qaProblem = 'That gate is not on file.';
            $qaNote = 'Gate ' . post('code') . ' saved';
        }
    }
    $qaData['gates'] = $qaGates;
} elseif ($qaOp === 'add-exception') {
    // EXCEPTIONS (owner, 9 Nov 2026): geo-fence (per branch) or phone approval (per employee); Always or a date range.
    $qaKind = post('kind') === 'phone' ? 'phone' : 'geofence';
    $qaEmp = post('employee_id') === '' ? null : $store->find('employees', post('employee_id'));
    $qaBranchOk = $qaKind === 'phone' || array_filter(liveRows($store, 'branches'), static fn (array $b): bool => (string) $b['id'] === post('branch_id')) !== [];
    $qaFrom = post('when') === 'range' ? post('from') : '';
    $qaTo = post('when') === 'range' ? post('to') : '';
    $qaIso = static fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;

    if ($qaEmp === null) {
        $qaProblem = 'Choose the employee.';
    } elseif (!$qaBranchOk) {
        $qaProblem = 'That branch is not on file.';
    } elseif (post('when') === 'range' && (!$qaIso($qaFrom) || !$qaIso($qaTo) || $qaTo < $qaFrom)) {
        $qaProblem = 'Give a From and a To date (To on or after From).';
    } elseif (trim(post('reason')) === '') {
        $qaProblem = 'Give the reason for the exception.';
    } else {
        $qaData['exceptions'] = array_values((array) ($qaData['exceptions'] ?? []));
        $qaData['exceptions'][] = ['id' => 'X' . bin2hex(random_bytes(4)), 'kind' => $qaKind, 'employee_id' => (string) $qaEmp['id'],
            'branch_id' => $qaKind === 'geofence' ? post('branch_id') : '', 'from' => $qaFrom, 'to' => $qaTo, 'reason' => mb_substr(trim(post('reason')), 0, 160),
            'by' => (string) $session->user()['email'], 'at' => date('Y-m-d H:i:s')];
        $qaNote = ($qaKind === 'phone' ? 'Phone approval' : 'Geo-fence') . ' exception added for ' . SmartPRS\Surface\Employment::code($qaEmp);
    }
} elseif ($qaOp === 'remove-exception') {
    $qaBefore = count((array) ($qaData['exceptions'] ?? []));
    $qaData['exceptions'] = array_values(array_filter((array) ($qaData['exceptions'] ?? []), static fn (mixed $x): bool => !is_array($x) || (string) ($x['id'] ?? '') !== post('code')));
    if ($qaBefore === count($qaData['exceptions'])) {
        $qaProblem = 'That exception is not on file.';
    } else {
        $qaNote = 'Exception removed';
    }
} elseif (in_array($qaOp, ['reset-gate', 'toggle-gate'], true)) {
    $qaFound = false;
    foreach ($qaGates as $i => $g) {
        if ((string) ($g['code'] ?? '') === post('code')) {
            if ($qaOp === 'reset-gate') {
                $qaGates[$i]['secret'] = QrAttendance::newSecret();
            } else {
                $qaGates[$i]['active'] = !(bool) ($g['active'] ?? true);
            }
            $qaFound = true;
            $qaNote = 'Gate ' . post('code') . ($qaOp === 'reset-gate' ? ': new screen link made — the old one has stopped' : ($qaGates[$i]['active'] ? ' switched on' : ' switched off'));
        }
    }
    $qaFound || $qaProblem = 'That gate is not on file.';
    $qaData['gates'] = $qaGates;
} else {
    $qaProblem = 'Nothing to save.';
}

if ($qaProblem === null) {
    $qaR = $customisation->saveRaw($qa->customisationKey(), $qaData, (string) ($session->user()['id'] ?? ''), (string) $session->user()['email'], 'QR Attendance: ' . $qaNote);
    $qaR['ok'] && $audit('qr_attendance.saved', 'qr_attendance', $qa->customisationKey(), null, ['op' => $qaOp, 'code' => post('code')], $qaNote);
    $_SESSION[$qaR['ok'] ? 'flash_notice' : 'flash_problem'] = $qaR['ok'] ? $qaNote . '.' : $qaR['why'];
} else {
    $_SESSION['flash_problem'] = $qaProblem;
}

header('Location: ' . $qaBack . ($qaProblem !== null && $qaOp === 'edit-gate' ? '&edit=' . rawurlencode(post('code')) : ''));
exit;
