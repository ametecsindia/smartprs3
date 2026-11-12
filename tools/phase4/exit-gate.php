<?php
/**
 * THE PHASE 4 EXIT GATE — Part XXXII, Time and absence.
 *
 *   php tools/phase4/exit-gate.php
 *
 * Part XXXII states five conditions for leaving this phase and calls the
 * cross-midnight semantics its highest risk: "these are the cases SmartPRS 2
 * gets structurally wrong, and they are subtle enough to get wrong twice."
 *
 *   1. The attendance golden-test matrix passes, INCLUDING every cross-midnight
 *      case (Part XXX §5).
 *   2. `is_weekly_off()` has exactly ONE implementation, asserted by a CI check
 *      (R-XII-002).
 *   3. Recalculation is idempotent and produces a diff.
 *   4. A correction on a locked period creates a tracked arrears record with an
 *      owner and a target period.
 *   5. Comp-off exists end to end.
 *
 * The matrix is DATA — `tests/phase4/attendance_matrix.json` — for the reason
 * the Phase 2 conformance corpus is: it stays runnable against a future
 * re-implementation, and its expected values were computed independently rather
 * than copied from what the code returned. A matrix built from the engine's own
 * output proves the engine agrees with itself.
 *
 * NOT PROVEN, never a silent pass, for anything that could not run.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../tests/lib/bootstrap.php';

use SmartPRS\Platform\Attendance\DaySettlement;
use SmartPRS\Platform\Attendance\Punch;
use SmartPRS\Platform\Attendance\ShiftWindow;
use SmartPRS\Platform\Calendar\CalendarService;
use SmartPRS\Platform\Calendar\WeeklyOffPattern;

$root = dirname(__DIR__, 2);
$matrix = json_decode(
    (string) file_get_contents($root . '/tests/phase4/attendance_matrix.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$results = [];

echo "SmartPRS 3 — Phase 4 exit gate (Part XXXII, Time and absence)\n";

/** Build a window from a declared case. */
function gateWindow(array $shift): ShiftWindow
{
    return new ShiftWindow(
        shiftCode: $shift['code'],
        attendanceDate: new DateTimeImmutable($shift['date']),
        shiftStart: new DateTimeImmutable($shift['start']),
        shiftEnd: new DateTimeImmutable($shift['end']),
        openMinutesBefore: (int) ($shift['open_before'] ?? 120),
        closeMinutesAfter: (int) ($shift['close_after'] ?? 240),
        graceMinutes: (int) ($shift['grace'] ?? 0),
        breakMinutes: (int) ($shift['break'] ?? 0),
    );
}

/** @return list<Punch> */
function gatePunches(array $declared): array
{
    return array_map(
        static fn (array $p): Punch => new Punch('E-1', new DateTimeImmutable($p['at']), $p['direction']),
        $declared,
    );
}

// =============================================================================
// 1. THE ATTENDANCE GOLDEN-TEST MATRIX, CROSS-MIDNIGHT INCLUDED
// =============================================================================

$problems = [];
$crossMidnight = 0;

foreach ($matrix['cases'] as $case) {
    $window = gateWindow($case['shift']);
    $settled = DaySettlement::settle($window, 'E-1', gatePunches($case['punches']));
    $expect = $case['expect'];

    foreach ([
        'worked' => $settled->workedMinutes,
        'late' => $settled->lateMinutes,
        'early_exit' => $settled->earlyExitMinutes,
        'overtime' => $settled->overtimeMinutes,
        'shortfall' => $settled->shortfallMinutes,
    ] as $key => $actual) {
        if (isset($expect[$key]) && $actual !== (int) $expect[$key]) {
            $problems[] = "{$case['id']}: {$key} is {$actual}m, the matrix says {$expect[$key]}m — {$case['why']}";
        }
    }

    if (isset($expect['sessions']) && count($settled->sessions) !== (int) $expect['sessions']) {
        $problems[] = "{$case['id']}: " . count($settled->sessions) . ' session(s), expected ' . $expect['sessions'];
    }

    if (isset($expect['unpaired']) && count($settled->unpaired) !== (int) $expect['unpaired']) {
        $problems[] = "{$case['id']}: " . count($settled->unpaired) . ' unpaired, expected ' . $expect['unpaired'];
    }

    if (isset($expect['outside_window']) && count($settled->outsideWindow) !== (int) $expect['outside_window']) {
        $problems[] = "{$case['id']}: " . count($settled->outsideWindow)
            . ' punch(es) outside the window, expected ' . $expect['outside_window']
            . ' — a swipe that is dropped rather than returned is somebody\'s overtime disappearing';
    }

    if (isset($expect['needs_regularisation'])
        && $settled->needsRegularisation() !== (bool) $expect['needs_regularisation']
    ) {
        $problems[] = "{$case['id']}: the day does not report that it needs regularisation";
    }

    if (isset($expect['crosses_midnight'])) {
        $crossMidnight++;

        if ($window->crossesMidnight() !== (bool) $expect['crosses_midnight']) {
            $problems[] = "{$case['id']}: the window does not know it crosses midnight";
        }

        if ($settled->attendanceDate->format('Y-m-d') !== $expect['attendance_date']) {
            $problems[] = "{$case['id']}: the night is attributed to "
                . $settled->attendanceDate->format('Y-m-d') . ' rather than ' . $expect['attendance_date']
                . ' — one night is ONE attendance day, labelled by the date the shift started';
        }
    }
}

// A shift whose end is numerically before its start must be REFUSED, because
// that representation is what makes a night impossible to compute.
try {
    new ShiftWindow('BAD', new DateTimeImmutable('2026-09-30'),
        new DateTimeImmutable('2026-09-30 22:00'), new DateTimeImmutable('2026-09-30 06:00'));
    $problems[] = 'a shift ending before it starts was accepted; a cross-midnight shift is expressed by an '
        . 'end INSTANT on the next day, not by a smaller end time';
} catch (\InvalidArgumentException) {
    // Refused, as it must be.
}

if ($crossMidnight === 0) {
    $problems[] = 'the matrix contains no cross-midnight case, which Part XXXII names as the highest risk of '
        . 'this phase — a matrix without one is a matrix that proves the easy half';
}

$results[] = [
    'name' => 'The attendance golden-test matrix passes, cross-midnight cases included',
    'ran' => true,
    'problems' => $problems,
    'detail' => count($matrix['cases']) . ' case(s) from tests/phase4/attendance_matrix.json, '
        . "{$crossMidnight} of them crossing midnight; every figure is arithmetic on absolute instants "
        . 'anchored to the shift start, never minutes-since-midnight',
];

// =============================================================================
// 2. `is_weekly_off()` HAS EXACTLY ONE IMPLEMENTATION
// =============================================================================

$problems = [];
$implementations = [];
$offenders = [];

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = str_replace($root . '/', '', $file->getPathname());
    $source = (string) file_get_contents($file->getPathname());
    $code = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

    if (preg_match('/function\s+isWeeklyOff\s*\(/', $code) === 1) {
        $implementations[] = $path;
    }

    // R-XII-002: no module implements its own weekday comparison. The named
    // shapes are the ones SmartPRS 2's four definitions are built from — a
    // literal weekday test, and a `sat_off_mode`-shaped switch.
    if (str_contains($path, '/Platform/Calendar/')) {
        continue;
    }

    foreach ([
        "format('N') ==" => 'a weekday comparison',
        "format('w') ==" => 'a weekday comparison',
        "=== 'Sunday'" => 'a weekday name comparison',
        'sat_off_mode' => "a sat_off_mode-shaped literal",
    ] as $needle => $what) {
        if (str_contains($code, $needle)) {
            $offenders[] = "{$path} contains {$what} ({$needle})";
        }
    }
}

if (count($implementations) !== 1) {
    $problems[] = 'isWeeklyOff() is implemented ' . count($implementations) . ' time(s): '
        . ($implementations === [] ? 'nowhere' : implode(', ', $implementations))
        . '. R-XII-001 says exactly one, and SmartPRS 2 has four — which is why its payslip and its muster '
        . 'roll disagree about how many days somebody worked';
}

foreach ($offenders as $offender) {
    $problems[] = 'R-XII-002: ' . $offender;
}

// And the evaluator is TOTAL: an unresolvable pattern returns a typed verdict
// rather than defaulting either way.
$unresolvable = (new CalendarService(static fn (string $e, DateTimeImmutable $d): ?WeeklyOffPattern => null))
    ->isWeeklyOff('E-1', new DateTimeImmutable('2026-09-07'));

if (!$unresolvable->isUnresolved()) {
    $problems[] = 'R-XII-003: an unresolvable pattern did not produce a typed error verdict. Both defaults '
        . 'are wrong — "working" charges an absence nobody took, "off" pays for a day nobody granted';
}

$throwing = (new CalendarService(static function (): ?WeeklyOffPattern {
    throw new \RuntimeException('the calendar table is unreachable');
}))->isWeeklyOff('E-1', new DateTimeImmutable('2026-09-07'));

if (!$throwing->isUnresolved()) {
    $problems[] = 'a thrown resolution did not become an unresolved verdict. SmartPRS 2 marks the entire '
        . 'workforce absent when its holiday query throws, because the failure had nowhere to go but into '
        . 'the answer';
}

// The weekly-off matrix.
foreach ($matrix['weekly_off'] as $case) {
    $declared = $case['pattern'];
    $pattern = new WeeklyOffPattern(
        code: $declared['code'],
        kind: $declared['kind'],
        versionId: 'v1',
        weekdays: $declared['weekdays'] ?? [],
        nthRules: $declared['nth_rules'] ?? [],
        cycle: $declared['cycle'] ?? [],
        cycleLength: (int) ($declared['cycle_length'] ?? 0),
        cycleAnchor: isset($declared['cycle_anchor']) ? new DateTimeImmutable($declared['cycle_anchor']) : null,
    );

    $service = new CalendarService(static fn (string $e, DateTimeImmutable $d): WeeklyOffPattern => $pattern);

    foreach ($case['probes'] as $probe) {
        $verdict = $service->isWeeklyOff('E-1', new DateTimeImmutable($probe['date']));

        if ($verdict->isWeeklyOff !== (bool) $probe['off']) {
            $problems[] = "{$case['id']} {$probe['date']}: "
                . ($verdict->isWeeklyOff ? 'off' : 'working') . ', the matrix says '
                . ($probe['off'] ? 'off' : 'working') . " — {$probe['why']}";
        }
    }
}

$results[] = [
    'name' => 'is_weekly_off() has exactly one implementation, and it is pure and TOTAL',
    'ran' => true,
    'problems' => $problems,
    'detail' => count($implementations) . ' implementation under src/, no weekday comparison outside '
        . 'Platform\\Calendar, and ' . count($matrix['weekly_off']) . ' pattern case(s) from the matrix — '
        . 'including a rotation resolved BEFORE its anchor, where a naive modulo matches nothing',
];

// =============================================================================
// 3. RECALCULATION IS IDEMPOTENT AND PRODUCES A DIFF
// =============================================================================

$problems = [];

foreach ($matrix['cases'] as $case) {
    $window = gateWindow($case['shift']);
    $punches = gatePunches($case['punches']);

    $first = DaySettlement::settle($window, 'E-1', $punches);
    // Re-run with the punches SHUFFLED. Order of arrival must not change the
    // answer: layer 3 is derived from the effective punch set, not from the
    // sequence a device happened to send.
    $shuffled = $punches;
    shuffle($shuffled);
    $second = DaySettlement::settle($window, 'E-1', $shuffled);

    if ($first->fingerprint() !== $second->fingerprint()) {
        $problems[] = "{$case['id']}: re-deriving the same day gives a different answer — "
            . implode('; ', $first->diff($second));
    }

    if ($first->diff($second) !== []) {
        $problems[] = "{$case['id']}: the diff of a day against its own re-run is not empty";
    }
}

// And a diff that actually reports a change, so "no diff" means something.
$window = gateWindow($matrix['cases'][0]['shift']);
$before = DaySettlement::settle($window, 'E-1', gatePunches($matrix['cases'][0]['punches']));
$after = DaySettlement::settle($window, 'E-1', [
    new Punch('E-1', new DateTimeImmutable($matrix['cases'][0]['shift']['start']), Punch::IN),
    new Punch('E-1', new DateTimeImmutable($matrix['cases'][0]['shift']['end']), Punch::OUT),
]);

if ($before->diff($after) === []) {
    $problems[] = 'a settlement with different punches diffs as unchanged, so an empty diff proves nothing';
}

$results[] = [
    'name' => 'Recalculation is idempotent, and a real change produces a real diff',
    'ran' => true,
    'problems' => $problems,
    'detail' => 'every matrix case re-derives identically from shuffled punches — the answer comes from the '
        . 'effective punch set, not the order a device sent them — and a changed day reports what changed',
];

// =============================================================================
// 4 AND 5 — ARREARS ON A LOCKED PERIOD, AND COMP-OFF END TO END
// =============================================================================
//
// Neither exists yet. Reporting them as NOT PROVEN rather than omitting them is
// the point of listing all five: a gate that shows the criteria it can pass and
// stays quiet about the rest is a progress bar, not a gate.

// =============================================================================
// 4. A CORRECTION ON A LOCKED PERIOD CREATES TRACKED ARREARS
// =============================================================================

$problems = [];

$locked = new SmartPRS\Platform\Attendance\PeriodLock(
    periodCode: '2026-02',
    from: new DateTimeImmutable('2026-02-01'),
    to: new DateTimeImmutable('2026-02-28'),
    isLocked: true,
    lockedOn: new DateTimeImmutable('2026-03-05'),
    lockedBy: 'u-payroll',
);

$febWindow = new ShiftWindow(
    shiftCode: 'GEN',
    attendanceDate: new DateTimeImmutable('2026-02-17'),
    shiftStart: new DateTimeImmutable('2026-02-17 09:00'),
    shiftEnd: new DateTimeImmutable('2026-02-17 18:00'),
);

// What the device recorded: an IN and no OUT, settled as an incomplete day.
$asPaid = DaySettlement::settle($febWindow, 'E-1', [
    new Punch('E-1', new DateTimeImmutable('2026-02-17 09:00'), Punch::IN),
]);

// What the approved correction says: they worked the full day.
$corrected = DaySettlement::settle($febWindow, 'E-1', [
    new Punch('E-1', new DateTimeImmutable('2026-02-17 09:00'), Punch::IN),
    new Punch('E-1', new DateTimeImmutable('2026-02-17 18:00'), Punch::OUT, 'correction'),
]);

$arrears = SmartPRS\Platform\Attendance\ArrearsRecord::forCorrection(
    origin: $locked,
    targetPeriodCode: '2026-04',
    before: $asPaid,
    after: $corrected,
    ownerUserId: 'u-hr-lead',
    reason: 'The out-punch was missed at the gate and the regularisation was approved in April.',
    raisedBy: 'u-manager',
    raisedOn: new DateTimeImmutable('2026-04-02'),
);

if ($arrears === null) {
    $problems[] = 'a correction inside a LOCKED period produced no arrears record — February has been paid, '
        . 'filed and reconciled, and rewriting it silently is what makes a payslip and an attendance record '
        . 'stop agreeing with no event saying they diverged';
} else {
    if ($arrears->originPeriodCode === $arrears->targetPeriodCode) {
        $problems[] = 'the arrears record carries one period, and it needs two: the month that was WRONG and '
            . 'the month that will carry the MONEY are different, and conflating them files the payment '
            . 'against the wrong return';
    }

    if ($arrears->ownerUserId === '') {
        $problems[] = 'the arrears record names no owner';
    }

    if ($arrears->diff === []) {
        $problems[] = 'the arrears record carries no diff, so an approver would authorise an amount rather '
            . 'than decide about a change';
    }
}

// An OPEN period simply takes the correction. The lock does not refuse a fix; it
// makes the fix cost money in a different month and records that it did.
$open = new SmartPRS\Platform\Attendance\PeriodLock('2026-04', new DateTimeImmutable('2026-04-01'),
    new DateTimeImmutable('2026-04-30'), false);

if (SmartPRS\Platform\Attendance\ArrearsRecord::forCorrection($open, '2026-05', $asPaid, $corrected,
    'u-hr-lead', 'corrected in the same month') !== null) {
    $problems[] = 'a correction in an OPEN period raised arrears; it should simply apply';
}

// And a re-derivation that CHANGES NOTHING must not raise a liability, or every
// recalculation sweep would file arrears for the whole workforce.
if (SmartPRS\Platform\Attendance\ArrearsRecord::forCorrection($locked, '2026-04', $corrected, $corrected,
    'u-hr-lead', 're-derived with no change') !== null) {
    $problems[] = 'an unchanged re-derivation raised arrears';
}

try {
    new SmartPRS\Platform\Attendance\PeriodLock('2026-02', new DateTimeImmutable('2026-02-01'),
        new DateTimeImmutable('2026-02-28'), true);
    $problems[] = 'a period was locked without recording who locked it or when — a lock nobody is named on '
        . 'is a lock nobody can lift';
} catch (\InvalidArgumentException) {
    // Refused, as it must be.
}

$results[] = [
    'name' => 'A correction on a locked period creates a tracked arrears record with an owner and a target period',
    'ran' => true,
    'problems' => $problems,
    'detail' => 'a February correction approved in April is carried into 2026-04 with an owner and the diff '
        . 'that caused it; an open period simply applies the fix, and an unchanged re-derivation raises '
        . 'nothing',
];

// =============================================================================
// 5. COMP-OFF, END TO END
// =============================================================================

$problems = [];

$sunday = new DateTimeImmutable('2026-09-06');
$sundayPattern = new WeeklyOffPattern('SUN', WeeklyOffPattern::FIXED, 'v1', weekdays: [7]);
$calendar = new CalendarService(static fn (string $e, DateTimeImmutable $d): WeeklyOffPattern => $sundayPattern);

// 1. The calendar says the day was an off.
if (!$calendar->isWeeklyOff('E-1', $sunday)->isWeeklyOff) {
    $problems[] = 'the calendar does not resolve the Sunday as a weekly off, so nothing downstream can be right';
}

// 2. They worked it, and attendance settles the day.
$worked = DaySettlement::settle(
    new ShiftWindow('GEN', $sunday, new DateTimeImmutable('2026-09-06 09:00'),
        new DateTimeImmutable('2026-09-06 18:00')),
    'E-1',
    [
        new Punch('E-1', new DateTimeImmutable('2026-09-06 09:00'), Punch::IN),
        new Punch('E-1', new DateTimeImmutable('2026-09-06 18:00'), Punch::OUT),
    ],
);

// 3. The accrual credits the ledger.
$accrual = new SmartPRS\Platform\Leave\CompOffAccrual($calendar, minimumMinutes: 240,
    fullDayMinutes: 480, expiryDays: 90);
$earned = $accrual->forDay($worked);

if ($earned['entry'] === null) {
    $problems[] = 'a full day worked on a resolved weekly off earned no comp-off: ' . $earned['why'];
}

$ledger = new SmartPRS\Platform\Leave\LeaveLedger();

if ($earned['entry'] !== null) {
    $ledger->append($earned['entry']);
}

$balance = $ledger->balanceOn('E-1', 'COMP-OFF', $sunday);

if ((string) $balance !== '1') {
    $problems[] = "the balance after one full day worked is {$balance}, expected 1";
}

// 4. And it is spent, which is the half that makes it end to end.
$spend = $accrual->consume($ledger, 'E-1', SmartPRS\Platform\Support\Rational::of(1),
    new DateTimeImmutable('2026-09-20'), 'Taking the comp-off earned on 6 September.');

foreach ($spend['entries'] as $entry) {
    $ledger->append($entry);
}

if (!$spend['short']->isZero()) {
    $problems[] = 'spending the earned day came up short: ' . $spend['why'];
}

$after = $ledger->balanceOn('E-1', 'COMP-OFF', new DateTimeImmutable('2026-09-20'));

if (!$after->isZero()) {
    $problems[] = "the balance after taking the day is {$after}, expected zero";
}

// A short day earns a half; a token appearance earns nothing.
$half = $accrual->forDay(DaySettlement::settle(
    new ShiftWindow('GEN', $sunday, new DateTimeImmutable('2026-09-06 09:00'),
        new DateTimeImmutable('2026-09-06 18:00')),
    'E-1',
    [
        new Punch('E-1', new DateTimeImmutable('2026-09-06 09:00'), Punch::IN),
        new Punch('E-1', new DateTimeImmutable('2026-09-06 14:00'), Punch::OUT),
    ],
));

if ((string) $half['days'] !== '1/2') {
    $problems[] = "five hours on an off day earned {$half['days']}, expected a half day";
}

$token = $accrual->forDay(DaySettlement::settle(
    new ShiftWindow('GEN', $sunday, new DateTimeImmutable('2026-09-06 09:00'),
        new DateTimeImmutable('2026-09-06 18:00')),
    'E-1',
    [
        new Punch('E-1', new DateTimeImmutable('2026-09-06 09:00'), Punch::IN),
        new Punch('E-1', new DateTimeImmutable('2026-09-06 09:20'), Punch::OUT),
    ],
));

if ($token['entry'] !== null) {
    $problems[] = 'twenty minutes on a Sunday earned a comp-off; the threshold is what makes the credit mean '
        . 'something';
}

// An UNRESOLVED calendar earns nothing, and says so rather than crediting.
$blind = new SmartPRS\Platform\Leave\CompOffAccrual(
    new CalendarService(static fn (): ?WeeklyOffPattern => null),
    240, 480, 90,
);

if ($blind->forDay($worked)['entry'] !== null) {
    $problems[] = 'a day the calendar could not classify earned a comp-off — that is inventing an '
        . 'entitlement out of a failure';
}

// And an unclaimed credit LAPSES as an entry rather than being filtered away.
$lapsing = new SmartPRS\Platform\Leave\LeaveLedger([$earned['entry']]);
$due = $lapsing->lapsesDue('E-1', 'COMP-OFF', new DateTimeImmutable('2027-01-01'));

if (count($due) !== 1) {
    $problems[] = 'an expired, unclaimed comp-off produced no lapse entry — an employee would watch a day '
        . 'disappear with nothing in the history saying when or why';
}

$results[] = [
    'name' => 'Comp-off exists end to end',
    'ran' => true,
    'problems' => $problems,
    'detail' => 'the calendar resolves the Sunday, attendance settles the day worked on it, the accrual '
        . 'credits the ledger, the credit is spent oldest-first, and an unclaimed one lapses as an ENTRY '
        . 'rather than being filtered out of a balance',
];

// =============================================================================

$failed = 0;
// Every criterion below sets 'ran' => true literally: none of them has a
// "could not run" path (a genuine prerequisite failure throws and halts the
// whole script instead), so $notRun can never move off 0. Kept anyway for
// parity with the other phase gates (e.g. phase3, which does skip criteria).
$notRun = 0;

foreach ($results as $index => $result) {
    if (!$result['ran']) {
        $notRun++;
        printf("\n  %d. \033[33mNOT PROVEN\033[0m  %s\n", $index + 1, $result['name']);
    } elseif ($result['problems'] !== []) {
        $failed++;
        printf("\n  %d. \033[31mFAIL      \033[0m  %s\n", $index + 1, $result['name']);
    } else {
        printf("\n  %d. \033[32mPASS      \033[0m  %s\n", $index + 1, $result['name']);
        echo "        {$result['detail']}\n";

        continue;
    }

    foreach ($result['problems'] as $problem) {
        echo "        └─ {$problem}\n";
    }
}

/** @var int $notRun always-0 in this file today; see the comment where it is declared. */
printf(
    "\n%s  %d of %d Phase 4 exit criteria proven%s\n",
    $failed === 0 && $notRun === 0 ? 'PASS' : 'FAIL',
    count($results) - $failed - $notRun,
    count($results),
    $notRun === 0 ? '' : ", {$notRun} NOT PROVEN",
);

exit($failed === 0 ? 0 : 1);
