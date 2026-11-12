<?php
/**
 * DAY-WISE SHIFT TIMINGS — SAVE ONE SHIFT'S WEEK (owner, 13 Oct). Each save is
 * a new Customisation version of the whole map; other shifts' days are kept.
 */
declare(strict_types=1);

$back = '?r=setup-shifts#shift-week';
$fail = static function (string $why) use ($back): never {
    $_SESSION['flash_problem'] = $why;
    header('Location: ' . $back);
    exit;
};

$may('tenant.settings.update') || $fail('Denied: only someone who can change organisation settings can save shift timings.');

$shiftId = trim((string) ($_POST['shift_id'] ?? ''));
$shift = $shiftId === '' ? null : $store->find('shifts', $shiftId);
$shift === null && $fail('That shift no longer exists — reload the page.');
$name = trim((string) ($shift['name'] ?? 'Shift'));
$dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
$days = [];

foreach ($dayNames as $n => $dayName) {
    $start = trim((string) ($_POST['days'][$n]['start'] ?? ''));
    $end = trim((string) ($_POST['days'][$n]['end'] ?? ''));

    // A day still at the regular times (the boxes show them, 14 Oct) is stored as blank = regular.
    $regStart = substr((string) ($shift['start_time'] ?? ''), 0, 5);
    $regEnd = substr((string) ($shift['end_time'] ?? ''), 0, 5);
    if (($start === '' && $end === '') || ($start === $regStart && $end === $regEnd)) {
        continue; // blank day = the shift's regular times
    }

    ($start === '' || $end === '') && $fail("{$name}, {$dayName}: give both a start and an end time, or leave both blank to use the regular time.");
    foreach ([$start, $end] as $t) {
        preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) === 1 || $fail("{$name}, {$dayName}: '{$t}' is not a time (HH:MM).");
    }
    $start === $end && $fail("{$name}, {$dayName}: start and end are the same time.");
    $days[(string) $n] = ['start' => $start, 'end' => $end];
}

// ATTENDANCE DURATION on the shift row itself (owner, 14 Oct): full-day / half-day hours, break allowed.
$dur = [];
foreach (['full_day_hours' => 24, 'half_day_hours' => 24, 'break_budget' => 480] as $col => $max) {
    $raw = trim((string) ($_POST[$col] ?? ''));
    if ($raw !== '' && (preg_match('/^\d+(\.\d+)?$/', $raw) !== 1 || (float) $raw > $max)) {
        $fail("{$name}: '{$raw}' is not a valid " . str_replace('_', ' ', $col) . " (0 to {$max}).");
    }
    $dur[$col] = $raw === '' ? null : ($col === 'break_budget' ? (string) (int) $raw : $raw);
}
$dur['half_day_hours'] !== null && $dur['full_day_hours'] !== null && (float) $dur['half_day_hours'] > (float) $dur['full_day_hours']
    && $fail("{$name}: half-day hours cannot be more than full-day hours.");
$store instanceof SmartPRS\Surface\SqlStore && $dur = array_intersect_key($dur, array_flip($store->columnsOf('shifts')));
if ($dur !== []) {
    $store->put('shifts', $dur + $shift); // whole row back: the file store replaces rows
    $audit('shift.duration.updated', 'shifts', $shiftId, array_intersect_key($shift, $dur), $dur, "Attendance duration for {$name}");
}

$map = (new SmartPRS\Surface\Shifts($store))->week();
if ($days === []) { unset($map[$shiftId]); } else { $map[$shiftId] = $days; }

$result = $customisation->saveRaw(SmartPRS\Surface\Shifts::WEEK_KEY, ['shifts' => $map],
    (string) ($session->user()['id'] ?? ''), (string) $session->user()['email'], "Day-wise timings saved for {$name}");
$_SESSION[$result['ok'] ? 'flash_notice' : 'flash_problem'] = $result['ok']
    ? "{$name}: day-wise timings saved (" . ($days === [] ? 'every day uses the regular time' : count($days) . ' day(s) with their own time') . '). ' . $result['why']
    : $result['why'];
header('Location: ' . $back);
exit;
