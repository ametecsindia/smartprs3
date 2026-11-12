<?php

declare(strict_types=1);

/**
 * The Part VII §12 worked rule library, encoded as fixtures and asserted —
 * against the REAL SPX compiler, type checker and evaluator.
 *
 * There is no test double any more. Every `when`, every `except` and every
 * expression-valued action parameter below is the §12 source, parsed by
 * `Compiled::parse()` against the decision point's real symbol table
 * (`Rules\DecisionPoints`), type-checked, and evaluated by `Evaluator::evaluate()`
 * under a `Sandbox`. So these tests now test three things at once: the engine,
 * the language, and the blueprint's own rule library.
 *
 * WHERE THE BLUEPRINT'S §12 SOURCE DID NOT COMPILE AS WRITTEN. Each is marked
 * `BLUEPRINT:` at the rule below, with what was changed and why. None was
 * papered over; all are reported. Two of the five have since been fixed at the
 * root rather than worked around here — tools/blueprint/compile-worked-rules.php
 * now compiles every worked rule in the documents on every run.
 *
 *   1. RL-ATT-001  FIXED. `event.value` and `policy.value(…)` were Number
 *                  whatever they named, so the §12 predicate compared Minutes
 *                  with Number. AggMetrics and PolicyKeys now declare both.
 *   2. RL-ATT-002  BANDS is written `[{from, to, result}, …]`. The grammar has no
 *                  record literal and `{…}` is the aggregation predicate block, so
 *                  that is a syntax error; BANDS takes two parallel lists.
 *   3. RL-ATT-007  the same, plus the band bounds are written as bare identifiers
 *                  (`comp_off_min_minutes`) rather than `policy.value(…)`.
 *   4. RL-LVE-001  FIXED. The corrected form names PREV_WORKING_DAY and
 *                  NEXT_WORKING_DAY, which were in no catalogue; they are now in
 *                  the Dates group, bounded and calendar-resolved.
 *   5. RL-CMP-001  `CONCAT('Credential DRA is ', credential.status('DRA'))` renders
 *                  an ABSENT credential as an empty string — the exact absence the
 *                  rule exists to surface.
 *
 * TWO OF THE SIXTEEN RULES ARE WRONG IN THEIR NAIVE FORM, and §12 flags both. Both
 * are asserted here in the naive form (to show the defect) and in the corrected
 * form (which is what is encoded):
 *
 *   RL-LVE-001  the two-day weekend. The naive adjacency test
 *               `leave.on_date(D-1)` / `leave.on_date(D+1)` charges NEITHER
 *               off-day of a Sat+Sun weekend, because the Saturday's next day is
 *               the Sunday and the Sunday carries no leave.
 *
 *   RL-CMP-001  the DRA absence asymmetry (R-VII-018). The naive gate asks
 *               "is the credential expired?", which lets an employee with NO
 *               credential row at all through the gate. Corrected form asks
 *               `NOT credential.is_valid(...)`, and `is_valid` is FALSE when the
 *               register holds no row — so absence of evidence is treated one
 *               way, declared, everywhere.
 */

use SmartPRS\Platform\Rules\ConflictPolicy;
use SmartPRS\Platform\Rules\DecisionPoints;
use SmartPRS\Platform\Rules\Engine;
use SmartPRS\Platform\Rules\EvaluationContext;
use SmartPRS\Platform\Rules\RuleEvaluationFailed;
use SmartPRS\Platform\Rules\RuleSet;
use SmartPRS\Platform\Rules\RuleTestCase;
use SmartPRS\Platform\Rules\SpxExpression;
use SmartPRS\Platform\Spx\Context as SpxContext;
use SmartPRS\Platform\Spx\Value;
use SmartPRS\Platform\Support\Rational;

require_once __DIR__ . '/lib/rules_fixtures.php';

group('Rules\WorkedLibrary');

$engine = new Engine();
$twoDays = Rational::of(2);
$oneDay = Rational::of(1);

$hyd = ['branch' => 'HYD', 'grade' => 'G3', 'department' => 'OPS', 'employee' => 'E-4471'];

/**
 * `day.*` is assembled by the caller from the subject date, exactly as a domain
 * service would before entering the engine (§7.1). It is not derived inside the
 * engine, because the engine has no clock and no calendar.
 */
$dayValues = static fn (string $date): array => [
    'day.date' => $date,
    'day.weekday' => strtolower((new DateTimeImmutable($date))->format('D')),
];

// ===========================================================================
// RL-ATT-001 · Three late arrivals over ten minutes in a payroll month
//              → half a day. (Governing brief §127 acceptance test.)
// ===========================================================================

$penalise = 'attendance.day.penalise';

// §12 verbatim, and it compiles. It did not when this test was written: both
// `event.value` and `policy.value(…)` were Number whatever they named, so the
// outer clause compared Minutes with Number and the predicate needed a `* 1m`
// to name a unit the engine should have known. Both are now declared —
// AggMetrics types one late_arrival's value as Minutes, PolicyKeys types
// late_grace_min as Minutes — so the workaround is gone and the rule is the
// document's own text.
$rlAtt001When = expr(
    "attendance.late_minutes > policy.value('late_grace_min')\n"
    . "AND agg.count(late_arrival, payroll_period, { event.value > policy.value('late_grace_min') }) >= 3",
    $penalise,
);

// §5.9: the counter is resolved by the CALLER before evaluation and keyed
// canonically. The evaluator never queries anything, so an unprepared counter is
// a named ERR_AGG_UNAVAILABLE and never a silent zero.
$lateCounter = $rlAtt001When->preparedKeys()[0];
same(1, count($rlAtt001When->preparedKeys()), 'RL-ATT-001: the rule declares exactly one prepared aggregation');
ok(
    str_starts_with($lateCounter, 'agg.count(late_arrival,payroll_period,{'),
    'and its canonical key names the event, the window and the predicate hash (§5.9.4)',
);

$rlAtt001 = rule(
    'RL-ATT-001',
    $penalise,
    $rlAtt001When,
    [
        ruleAction('APPLY_DAY_PENALTY', [
            'fraction' => expr('0.5d', $penalise, boolean: false),
            'reason' => 'Third or later late arrival over 10 minutes this payroll month',
            'cap_ref' => 'ATT-PENALTY-CAP-MONTH',
        ]),
        ruleAction('NOTIFY', [
            'event' => 'attendance.late_penalty',
            'audience_ref' => 'AUD-EMP-AND-MANAGER',
            'dedupe_key' => 'E-4471:2026-08-26',
        ]),
    ],
    except: expr('day.is_holiday OR day.is_weekly_off', $penalise),
    onConflict: ConflictPolicy::AllMatch,
    description: 'Three late arrivals over the grace in one payroll month cost half a day.',
    sourceRef: 'PayrollGenController::attendanceCut():449-455',
);

$set001 = RuleSet::of($penalise, $rlAtt001);
$base001 = [
    "policy.value('late_grace_min')" => 10,
    'day.is_holiday' => false,
    'day.is_weekly_off' => false,
];

/** The prepared counter carries its own SPX type — it is a call answer, not a symbol. */
$lates = static fn (int $count): array => [$lateCounter => Value::number(Rational::of($count))];

// The §12 trace: E-4471, 26-Aug-2026, fourth qualifying late.
$fourth = $engine->evaluate($penalise, $set001, ctx(
    $base001 + ['attendance.late_minutes' => 23] + $lates(4),
    date: '2026-08-26',
));
same(1, count($fourth->actionsOfCode('APPLY_DAY_PENALTY')), 'RL-ATT-001: the fourth qualifying late is penalised');
same('1/2', (string) $fourth->actions[0]->parameters['fraction'], 'RL-ATT-001: the penalty is half a day, exactly');
same(1, count($fourth->actionsOfCode('NOTIFY')), 'RL-ATT-001: and the employee and manager are notified');
ok(
    str_contains((string) $fourth->trace->matched()[0]->whenSubstituted, 'attendance.late_minutes = 23'),
    'RL-ATT-001: and the trace shows the value the condition read',
);

$second = $engine->evaluate($penalise, $set001, ctx(
    $base001 + ['attendance.late_minutes' => 23] + $lates(2),
    date: '2026-08-19',
));
same([], $second->actions, 'RL-ATT-001: the second qualifying late is free');

$withinGrace = $engine->evaluate($penalise, $set001, ctx(
    $base001 + ['attendance.late_minutes' => 9] + $lates(4),
    date: '2026-08-26',
));
same([], $withinGrace->actions, 'RL-ATT-001: a late inside the grace does not count, however many precede it');

$boundary = $engine->evaluate($penalise, $set001, ctx(
    $base001 + ['attendance.late_minutes' => 11] + $lates(3),
    date: '2026-08-26',
));
same(2, count($boundary->actions), 'RL-ATT-001: the boundary — exactly 3, one minute over grace — bites');

$onHoliday = $engine->evaluate($penalise, $set001, ctx(
    ["policy.value('late_grace_min')" => 10, 'day.is_holiday' => true, 'day.is_weekly_off' => false,
        'attendance.late_minutes' => 23] + $lates(4),
    date: '2026-08-26',
));
same([], $onHoliday->actions, 'RL-ATT-001: a holiday is excepted');

// An unprepared counter is a NAMED failure, never a zero that quietly acquits.
try {
    $engine->evaluate($penalise, $set001, ctx(
        $base001 + ['attendance.late_minutes' => 23],
        date: '2026-08-26',
    ));
    ok(false, 'expected an unprepared aggregation to fail the evaluation');
} catch (RuleEvaluationFailed $e) {
    same('ERR_AGG_UNAVAILABLE', $e->errorCode, 'RL-ATT-001: an unprepared counter is ERR_AGG_UNAVAILABLE (§5.9)');
    same([], $e->trace->actions, 'and no action escapes it — the engine cannot count for itself');
}

// ===========================================================================
// RL-ATT-002 · Banded lateness — over two hours is half a day, over four hours
//              is treated as absent. Hyderabad branch.
// ===========================================================================

// BLUEPRINT (2): §12 writes
//   BANDS(attendance.late_minutes, [ { from: half, to: absent, result: 0.5d },
//                                    { from: absent, to: NULL, result: 1.0d } ])
// The grammar has no record literal and `{…}` is the aggregation predicate
// block, so that is a SYNTAX error. BANDS takes two parallel lists of equal
// length, `BANDS(value, lower_bounds, results)`, with half-open bands
// [b(i), b(i+1)) and the last band open. The `from` values below are the
// blueprint's own, transcribed as the lower-bound list.
//
// This RESOLVES an ambiguity the record form never settled: `{from: 120, to: 240}`
// and `{from: 240, to: NULL}` both name 240, and the blueprint does not say which
// end is inclusive. Half-open [b, next) makes it structural — 240 is the FIRST
// minute of the absent band, not the last of the half-day band. That is the one
// outcome in this file that differs from the pre-SPX closure, and it differs
// because the closure had to pick a reading and the language now states one.
$bandFraction = expr(
    "BANDS(attendance.late_minutes,\n"
    . "  [ policy.value('late_band_half_min'), policy.value('late_band_absent_min') ],\n"
    . '  [ 0.5d, 1.0d ])',
    $penalise,
    boolean: false,
);

$rlAtt002 = rule(
    'RL-ATT-002',
    $penalise,
    expr("attendance.late_minutes > policy.value('late_band_half_min')", $penalise),
    [ruleAction('APPLY_DAY_PENALTY', ['fraction' => $bandFraction, 'reason' => 'Lateness band'])],
    scope: ['branch' => ['HYD']],
    onConflict: ConflictPolicy::AllMatch,
    description: 'Over two hours late is half a day; over four hours is treated as absent.',
    sourceRef: 'PayrollGenController::tierCut():482-495',
);

$bands = [
    "policy.value('late_band_half_min')" => 120,
    "policy.value('late_band_absent_min')" => 240,
];
$set002 = RuleSet::of($penalise, $rlAtt002);

same([], $engine->evaluate($penalise, $set002, ctx($bands + ['attendance.late_minutes' => 120],
    $hyd))->actions, 'RL-ATT-002: exactly two hours is not yet a penalty');
same(
    '1/2',
    (string) $engine->evaluate($penalise, $set002, ctx($bands + ['attendance.late_minutes' => 132],
        $hyd))->actions[0]->parameters['fraction'],
    'RL-ATT-002: 132 minutes lands in the half-day band (the §9.3 worked trace)',
);
same(
    '1/2',
    (string) $engine->evaluate($penalise, $set002, ctx($bands + ['attendance.late_minutes' => 239],
        $hyd))->actions[0]->parameters['fraction'],
    'RL-ATT-002: 239 minutes is the last minute of the half-day band',
);
same(
    '1',
    (string) $engine->evaluate($penalise, $set002, ctx($bands + ['attendance.late_minutes' => 240],
        $hyd))->actions[0]->parameters['fraction'],
    'RL-ATT-002: 240 minutes is the FIRST minute of the absent band — bands are half-open [b, next)',
);
same(
    '1',
    (string) $engine->evaluate($penalise, $set002, ctx($bands + ['attendance.late_minutes' => 241],
        $hyd))->actions[0]->parameters['fraction'],
    'RL-ATT-002: and 241 minutes is squarely inside it',
);
same([], $engine->evaluate($penalise, $set002, ctx($bands + ['attendance.late_minutes' => 300],
    ['branch' => 'CHN']))->actions, 'RL-ATT-002: and it governs Hyderabad only');

// Below the first band, BANDS refuses to guess rather than charging the L1 rate.
try {
    $engine->evaluate($penalise, RuleSet::of($penalise, rule(
        'RL-ATT-002U',
        $penalise,
        always($penalise),
        [ruleAction('APPLY_DAY_PENALTY', ['fraction' => $bandFraction, 'reason' => 'uncovered'])],
        onConflict: ConflictPolicy::AllMatch,
        description: 'A band table whose domain does not cover the value, to assert BANDS refuses to guess.',
    )), ctx($bands + ['attendance.late_minutes' => 5]));
    ok(false, 'expected an uncovered band domain to fail');
} catch (RuleEvaluationFailed $e) {
    same('ERR_ARG', $e->errorCode, 'RL-ATT-002: a value below the first band is ERR_ARG, not the lowest rate');
}

// The companion classify rule sets the STATUS, not only the money.
$classify = 'attendance.day.classify';
$rlAtt002b = rule(
    'RL-ATT-002B',
    $classify,
    expr("attendance.late_minutes > policy.value('late_band_absent_min')", $classify),
    [ruleAction('SET_STATUS', ['code' => 'ABSENT'])],
    scope: ['branch' => ['HYD']],
    description: 'Over four hours late is recorded as Absent, not merely paid as one.',
);
$statusSet = RuleSet::of($classify, $rlAtt002b);
same(
    'ABSENT',
    $engine->evaluate($classify, $statusSet, ctx($bands + ['attendance.late_minutes' => 300],
        $hyd))->actions[0]->parameters['code'],
    'RL-ATT-002: over four hours the status is ABSENT, so the muster roll agrees with the money',
);
same([], $engine->evaluate($classify, $statusSet, ctx($bands + ['attendance.late_minutes' => 200],
    $hyd))->actions, 'RL-ATT-002: and a half-day late is not marked absent');

// ===========================================================================
// RL-ATT-005 · Overtime — Hyderabad branch, Agent grade, above a daily threshold.
//              Two-dimension scope: 180 + 340 = 520.
// ===========================================================================

$earn = 'attendance.day.earn';

/**
 * The `attendance.day.earn` context: symbols plus the resolved EMPLOYEE holiday
 * calendar, which HOLIDAY_TYPE() reads (RL-ATT-008). This closure is the whole
 * of "a decision point that needs more than symbols" — one line, in the caller,
 * where the calendar was resolved under tenant scope.
 */
$earnSymbols = DecisionPoints::symbols($earn);
$earnContext = static fn (EvaluationContext $c): SpxContext => SpxExpression::context($c, $earnSymbols)
    ->withCalendar(
        'EMPLOYEE',
        holidays: ['2026-08-15', '2026-08-20'],
        holidayTypes: ['2026-08-15' => 'festival', '2026-08-20' => 'restricted'],
    );

$otMinutes = expr(
    "STEP(attendance.worked_minutes - TO_MINUTES(policy.value('ot_daily_threshold_hours')),"
    . " policy.value('ot_rounding_block_min'), policy.value('ot_rounding_block_min'), 'floor')",
    $earn,
    boolean: false,
    toSpxContext: $earnContext,
);

$rlAtt005 = rule(
    'RL-ATT-005',
    $earn,
    expr(
        "attendance.worked_minutes > TO_MINUTES(policy.value('ot_daily_threshold_hours'))\n"
        . "AND day.is_working_day\nAND employee.stage <> 'probation'",
        $earn,
        toSpxContext: $earnContext,
    ),
    [ruleAction('CREATE_OT', [
        'minutes' => $otMinutes,
        'multiplier_ref' => 'OT-MULT-WEEKDAY',
        'approval' => 'workflow',
    ])],
    scope: ['branch' => ['HYD'], 'grade' => ['G3']],
    except: expr("NOT policy.value('ot_enabled')", $earn, toSpxContext: $earnContext),
    onConflict: ConflictPolicy::AllMatch,
    description: 'Hyderabad Agents earn overtime above the daily hours threshold, in 30-minute blocks.',
    sourceRef: 'MasterController::save():755-763',
);

same(520, $rlAtt005->specificity(), 'RL-ATT-005: branch 180 + grade 340 = 520 (§8.2 Example 2)');

$set005 = RuleSet::of($earn, $rlAtt005);
$otBase = [
    // Hours, not minutes: §5.7 declares the key `ot_daily_threshold_hours`, and
    // TO_MINUTES() is the named, traced crossing (R-VII-013).
    "policy.value('ot_daily_threshold_hours')" => 8,
    "policy.value('ot_rounding_block_min')" => 30,
    "policy.value('ot_enabled')" => true,
    'day.is_working_day' => true,
    'employee.stage' => 'confirmed',
];

$ot = $engine->evaluate($earn, $set005, ctx($otBase + ['attendance.worked_minutes' => 612], $hyd));
same('120', (string) $ot->actions[0]->parameters['minutes'], 'RL-ATT-005: 612 − 480 = 132m floors to 120m in 30m blocks');
same('OT-MULT-WEEKDAY', $ot->actions[0]->parameters['multiplier_ref'], 'RL-ATT-005: with the weekday multiplier');
same('workflow', $ot->actions[0]->parameters['approval'], 'RL-ATT-005: and it goes to a workflow, not auto-approved');

same([], $engine->evaluate($earn, $set005, ctx($otBase + ['attendance.worked_minutes' => 480],
    $hyd))->actions, 'RL-ATT-005: exactly the threshold earns nothing');
same([], $engine->evaluate($earn, $set005, ctx($otBase + ['attendance.worked_minutes' => 612],
    ['branch' => 'CHN', 'grade' => 'G3']))->actions, 'RL-ATT-005: another branch is out of scope');
same([], $engine->evaluate($earn, $set005, ctx($otBase + ['attendance.worked_minutes' => 612],
    ['branch' => 'HYD', 'grade' => 'G5']))->actions, 'RL-ATT-005: another grade is out of scope');
same([], $engine->evaluate($earn, $set005, ctx(
    ["policy.value('ot_enabled')" => false] + $otBase + ['attendance.worked_minutes' => 612],
    $hyd,
))->actions, 'RL-ATT-005: the except clause switches the whole policy off');
same([], $engine->evaluate($earn, $set005, ctx(
    ['employee.stage' => 'probation'] + $otBase + ['attendance.worked_minutes' => 612],
    $hyd,
))->actions, 'RL-ATT-005: probationers do not earn it');

// ===========================================================================
// RL-ATT-007 · Sunday work earns comp-off — expressed as day.is_weekly_off, so a
//              six-day team with a Tuesday off is covered by the same rule.
// RL-ATT-008 · Working on a festival holiday earns two days.
// ===========================================================================

// BLUEPRINT (3): as (2), and §12 additionally writes the band bounds as bare
// identifiers — `{ from: comp_off_min_minutes, … }`. A bare word is not a value
// in SPX (a variable is namespaced, a function is called), so those are
// `policy.value(…)` here.
$compUnits = expr(
    "BANDS(attendance.worked_minutes,\n"
    . "  [ policy.value('comp_off_min_minutes'), policy.value('comp_off_full_minutes') ],\n"
    . '  [ 0.5d, 1.0d ])',
    $earn,
    boolean: false,
    toSpxContext: $earnContext,
);

$rlAtt007 = rule(
    'RL-ATT-007',
    $earn,
    expr(
        "day.is_weekly_off\nAND attendance.worked_minutes >= policy.value('comp_off_min_minutes')",
        $earn,
        toSpxContext: $earnContext,
    ),
    [ruleAction('CREATE_COMP_OFF', [
        'units' => $compUnits,
        'expiry_ref' => 'COMPOFF-EXPIRY-90D',
        'reason' => 'Worked on weekly off',
    ])],
    except: expr('day.is_holiday', $earn, toSpxContext: $earnContext),
    onConflict: ConflictPolicy::AllMatch,
    description: 'Working a weekly off earns comp-off, half a day or a full day by hours worked.',
);

$rlAtt008 = rule(
    'RL-ATT-008',
    $earn,
    expr(
        "day.is_holiday\nAND HOLIDAY_TYPE(day.date, 'EMPLOYEE') = 'festival'\n"
        . "AND attendance.worked_minutes >= policy.value('comp_off_min_minutes')",
        $earn,
        toSpxContext: $earnContext,
    ),
    [
        ruleAction('CREATE_COMP_OFF', [
            'units' => $twoDays,
            'expiry_ref' => 'COMPOFF-EXPIRY-90D',
            'reason' => 'Worked on festival holiday',
        ]),
        ruleAction('NOTIFY', [
            'event' => 'attendance.festival_work',
            'audience_ref' => 'AUD-MANAGER',
            'dedupe_key' => 'E-4471:2026-08-15',
        ]),
    ],
    priority: 50,
    onConflict: ConflictPolicy::AllMatch,
    description: 'Working a festival holiday earns two days of comp-off, resolved for the employee scope.',
    sourceRef: 'AbsenceService.php:225-236',
);

$compSet = RuleSet::of($earn, $rlAtt007, $rlAtt008);
$compBase = [
    "policy.value('comp_off_min_minutes')" => 240,
    "policy.value('comp_off_full_minutes')" => 480,
];

$sunday = $engine->evaluate($earn, $compSet, ctx(
    $compBase + $dayValues('2026-08-16')
        + ['day.is_weekly_off' => true, 'day.is_holiday' => false, 'attendance.worked_minutes' => 540],
    date: '2026-08-16',
));
same(1, count($sunday->actionsOfCode('CREATE_COMP_OFF')), 'RL-ATT-007: Sunday work earns comp-off');
same('1', (string) $sunday->actions[0]->parameters['units'], 'RL-ATT-007: a full day worked earns a full day');
same('ledger', $sunday->actions[0]->effectClass, 'RL-ATT-007: a comp-off credit is a LEDGER effect, reversed by a debit');

$halfSunday = $engine->evaluate($earn, $compSet, ctx(
    $compBase + $dayValues('2026-08-16')
        + ['day.is_weekly_off' => true, 'day.is_holiday' => false, 'attendance.worked_minutes' => 300],
    date: '2026-08-16',
));
same('1/2', (string) $halfSunday->actions[0]->parameters['units'], 'RL-ATT-007: a short day earns half');

$tuesdayOff = $engine->evaluate($earn, $compSet, ctx(
    $compBase + $dayValues('2026-08-18')
        + ['day.is_weekly_off' => true, 'day.is_holiday' => false, 'attendance.worked_minutes' => 540],
    date: '2026-08-18',
));
same(
    1,
    count($tuesdayOff->actionsOfCode('CREATE_COMP_OFF')),
    'RL-ATT-007: the rule says day.is_weekly_off, not Sunday — a Tuesday-off team is covered by the same rule',
);

$festival = $engine->evaluate($earn, $compSet, ctx(
    $compBase + $dayValues('2026-08-15')
        + ['day.is_weekly_off' => false, 'day.is_holiday' => true, 'attendance.worked_minutes' => 540],
    date: '2026-08-15',
));
same(1, count($festival->actionsOfCode('CREATE_COMP_OFF')), 'RL-ATT-008: festival-holiday work earns comp-off');
same('2', (string) $festival->actions[0]->parameters['units'], 'RL-ATT-008: and it is two days, not one');
same(1, count($festival->actionsOfCode('NOTIFY')), 'RL-ATT-008: the manager is notified');
ok(
    str_contains((string) $festival->trace->matched()[0]->whenSource, "HOLIDAY_TYPE(day.date, 'EMPLOYEE')"),
    'RL-ATT-008: and the holiday type came from the resolved calendar, not from a literal date list',
);

$festivalOnWeeklyOff = $engine->evaluate($earn, $compSet, ctx(
    $compBase + $dayValues('2026-08-15')
        + ['day.is_weekly_off' => true, 'day.is_holiday' => true, 'attendance.worked_minutes' => 540],
    date: '2026-08-15',
));
same(
    1,
    count($festivalOnWeeklyOff->actionsOfCode('CREATE_COMP_OFF')),
    'a festival falling on the weekly off credits two days once — RL-ATT-007 excepts it to RL-ATT-008',
);
same(
    '2',
    (string) $festivalOnWeeklyOff->actionsOfCode('CREATE_COMP_OFF')[0]->parameters['units'],
    'and the two-day festival rate is the one that applies',
);

$restrictedHoliday = $engine->evaluate($earn, $compSet, ctx(
    $compBase + $dayValues('2026-08-20')
        + ['day.is_weekly_off' => false, 'day.is_holiday' => true, 'attendance.worked_minutes' => 540],
    date: '2026-08-20',
));
same([], $restrictedHoliday->actions, 'RL-ATT-008: a non-festival holiday type does not earn the festival rate');

// ===========================================================================
// RL-CAL-001 · Second and fourth Saturday are weekly offs.
//              NTH_WEEKDAY counts ACTUAL Saturdays, not day-of-month bands.
// ===========================================================================

$weeklyOff = 'attendance.calendar.weekly_off';

$rlCal001 = rule(
    'RL-CAL-001',
    $weeklyOff,
    expr(
        "day.weekday = 'sat'\n"
        . "AND day.date IN [ NTH_WEEKDAY(SOMONTH(day.date), 'sat', 2), NTH_WEEKDAY(SOMONTH(day.date), 'sat', 4) ]",
        $weeklyOff,
    ),
    [ruleAction('SET_DAY_TYPE', ['type' => 'weekly_off'])],
    scope: ['company' => ['CMP7']],
    module: 'attendance',
    description: 'The second and fourth Saturday of each month are weekly offs for this company.',
    sourceRef: 'PayrollGenController::isWeekOff():70-89',
);

$calSet = RuleSet::of($weeklyOff, $rlCal001);
$company = ['company' => 'CMP7'];

$onDay = static fn (string $date) => $engine->evaluate(
    $weeklyOff,
    $calSet,
    ctx($dayValues($date), $company, date: $date),
);

// November 2026: Saturdays fall on 7, 14, 21, 28. Second = 14, fourth = 28.
foreach (['2026-11-14' => true, '2026-11-28' => true, '2026-11-07' => false, '2026-11-21' => false] as $date => $isOff) {
    same(
        $isOff ? 1 : 0,
        count($onDay($date)->actions),
        "RL-CAL-001: {$date} is " . ($isOff ? '' : 'not ') . 'a second or fourth Saturday',
    );
}

same([], $onDay('2026-11-15')->actions, 'RL-CAL-001: the rule is about Saturdays; Sunday is a companion rule');

// The defect this replaces: `ceil(day / 7)` is a day-of-month BAND, not a count
// of actual Saturdays. October 2026's Saturdays are 3, 10, 17, 24, 31 — a month
// that does not begin on a Sunday, which is where the two definitions diverge.
$nth = static fn (int $n): SpxExpression => expr(
    "NTH_WEEKDAY(SOMONTH(day.date), 'sat', {$n})",
    $weeklyOff,
    boolean: false,
);
$october = ctx($dayValues('2026-10-01'), $company, date: '2026-10-01');

same(
    '2026-10-10',
    $nth(2)->evaluate($october)->value->format('Y-m-d'),
    'RL-CAL-001: NTH_WEEKDAY says the second ACTUAL Saturday of Oct 2026 is the 10th',
);
same(2, (int) ceil(10 / 7), 'and here the SmartPRS 2 day-of-month band happens to agree');
same(
    '2026-10-24',
    $nth(4)->evaluate($october)->value->format('Y-m-d'),
    'RL-CAL-001: and the fourth actual Saturday is the 24th',
);
ok(
    (int) ceil(24 / 7) === 4,
    'the band agrees on the 24th, but it would also call the 22nd a "fourth Saturday" if it were one — '
    . 'counting actual weekdays is the only definition that cannot drift',
);
same(1, count($onDay('2026-10-24')->actions), 'RL-CAL-001: 24-Oct-2026 is a weekly off');
same(0, count($onDay('2026-10-31')->actions), 'RL-CAL-001: 31-Oct-2026 is the FIFTH Saturday and is a working day');

// ===========================================================================
// RL-ATT-012 · An employee-specific exception overriding a branch rule, with an
//              expiry that is a property of the configuration, not of a human.
// ===========================================================================

$grace = 'attendance.grace';

$graceLadder = RuleSet::of(
    $grace,
    rule('RL-ATT-003A', $grace, always($grace), [ruleAction('SET_GRACE', ['minutes' => 10])],
        description: 'Tenant default grace of ten minutes.'),
    rule('RL-ATT-003B', $grace, always($grace), [ruleAction('SET_GRACE', ['minutes' => 15])],
        scope: ['branch' => ['HYD']], description: 'Hyderabad branch grace of fifteen minutes.'),
    rule('RL-ATT-003C', $grace, always($grace), [ruleAction('SET_GRACE', ['minutes' => 20])],
        scope: ['branch' => ['HYD'], 'shift' => ['NIGHT-2200']],
        description: 'Hyderabad night shift grace of twenty minutes.'),
    rule('RL-ATT-012', $grace, always($grace), [ruleAction('SET_GRACE', ['minutes' => 60])],
        scope: ['employee' => ['E-4471']], from: '2026-09-01', to: '2027-02-28', severity: 'high_risk',
        description: 'Approved medical accommodation (HR ref ACC-2026-114). Grace of 60 minutes, '
            . 'reviewed on 28-Feb-2027. Supersedes the Hyderabad branch grace.'),
);

$dayShift = ['branch' => 'HYD', 'shift' => 'GEN-0930', 'employee' => 'E-4471'];
$nightShift = ['branch' => 'HYD', 'shift' => 'NIGHT-2200', 'employee' => 'E-4471'];

$august = $engine->evaluate($grace, $graceLadder, ctx(dimensions: $dayShift, date: '2026-08-12'));
same('RL-ATT-003B', $august->actions[0]->emittedBy['rule_code'], 'RL-ATT-003: the branch rule beats the tenant default');
same(15, $august->actions[0]->parameters['minutes'], 'and gives fifteen minutes');

$augustNight = $engine->evaluate($grace, $graceLadder, ctx(dimensions: $nightShift, date: '2026-08-12'));
same('RL-ATT-003C', $augustNight->actions[0]->emittedBy['rule_code'], 'RL-ATT-003: branch+shift 510 beats branch 180');

$september = $engine->evaluate($grace, $graceLadder, ctx(dimensions: $nightShift, date: '2026-09-15'));
same(
    'RL-ATT-012',
    $september->actions[0]->emittedBy['rule_code'],
    'RL-ATT-012: the employee accommodation (1000) beats branch+shift (510)',
);
same(60, $september->actions[0]->parameters['minutes'], 'and gives sixty minutes');
ok(
    str_contains((string) $september->trace->reasonFor('RL-ATT-003C'), 'lost on specificity (510 < 1000)'),
    'RL-ATT-012: and the branch rule is recorded as outranked, with the arithmetic',
);
ok($graceLadder->get('RL-ATT-012')->requiresApproval, 'RL-ATT-012: a high_risk rule forces maker-checker');

$march = $engine->evaluate($grace, $graceLadder, ctx(dimensions: $nightShift, date: '2027-03-01'));
same(
    'RL-ATT-003C',
    $march->actions[0]->emittedBy['rule_code'],
    'RL-ATT-012: the accommodation expires by construction and the branch rule resumes, with no human action',
);
same(
    'not_in_force',
    $march->trace->outcomeFor('RL-ATT-012'),
    'and the March trace shows the employee rule as not in force, so the employee can see why it stopped',
);

// ===========================================================================
// RL-LVE-001 · Sandwich leave. THE NAIVE FORM IS WRONG — §12 says so, and here
//              is the proof, followed by the corrected form that is encoded.
// ===========================================================================

$charge = 'leave.request.charge';

// Leave taken Fri 14-Aug and Mon 17-Aug. Sat 15 and Sun 16 are weekly offs.
$chargeSymbols = DecisionPoints::symbols($charge);
$chargeContext = static fn (EvaluationContext $c): SpxContext => SpxExpression::context($c, $chargeSymbols)
    ->withCalendar('EMPLOYEE', weeklyOffs: ['2026-08-15', '2026-08-16']);

// The leave register, resolved by the caller and keyed canonically by date.
$leaveRegister = [
    'leave.on_date(#2026-08-14#)' => 'EL',
    'leave.on_date(#2026-08-17#)' => 'EL',
];

// --- the naive form, for the record ---------------------------------------
$naiveSandwich = rule(
    'RL-LVE-001-NAIVE',
    $charge,
    expr(
        "policy.value('sandwich_enabled')\nAND (day.is_weekly_off OR day.is_holiday)\n"
        . "AND NOT IS_NULL(leave.on_date(DATEADD(day.date, -1, 'days')))\n"
        . "AND NOT IS_NULL(leave.on_date(DATEADD(day.date,  1, 'days')))",
        $charge,
        toSpxContext: $chargeContext,
    ),
    [ruleAction('CHARGE_DAY', ['date' => '2026-08-15', 'type' => 'EL', 'units' => $oneDay, 'reason' => 'naive'])],
    module: 'leave',
    onConflict: ConflictPolicy::AllMatch,
    severity: 'high_risk',
    description: 'The naive adjacency form, retained only to demonstrate the two-day-weekend defect.',
);

$naiveSet = RuleSet::of($charge, $naiveSandwich);
$naiveBase = ["policy.value('sandwich_enabled')" => true, 'day.is_holiday' => false] + $leaveRegister;

foreach (['2026-08-15', '2026-08-16'] as $offDay) {
    same(
        [],
        $engine->evaluate($charge, $naiveSet, ctx(
            $naiveBase + $dayValues($offDay) + ['day.is_weekly_off' => true],
            date: $offDay,
        ))->actions,
        "RL-LVE-001 NAIVE: {$offDay} is not charged — a two-day weekend defeats adjacency entirely",
    );
}

// --- the corrected form -----------------------------------------------------
// BLUEPRINT (4): §12's corrected form is
//   NOT IS_NULL(leave.on_date(PREV_WORKING_DAY(day.date, 'EMPLOYEE')))
//   AND NOT IS_NULL(leave.on_date(NEXT_WORKING_DAY(day.date, 'EMPLOYEE')))
// FIXED. Neither function was in the §5.6 catalogue, so the specification's own
// sandwich rule could not be published. This test used to assert that refusal
// and then encode a ONE-STEP unrolling with IS_WORKING_DAY() — correct only for
// a weekend of at most two off-days, and wrong for a long weekend.
//
// SPX has no loop by construction (§10.1b), so "walk to the nearest working day"
// cannot be written IN the language. It belongs in the catalogue, where the walk
// is bounded and resolves the same calendar IS_WORKING_DAY already does. Both
// functions are there now, and the rule below is §12 verbatim.
$rlLve001 = rule(
    'RL-LVE-001',
    $charge,
    expr(
        "policy.value('sandwich_enabled')\nAND (day.is_weekly_off OR day.is_holiday)\n"
        . "AND NOT IS_NULL(leave.on_date(PREV_WORKING_DAY(day.date, 'EMPLOYEE')))\n"
        . "AND NOT IS_NULL(leave.on_date(NEXT_WORKING_DAY(day.date, 'EMPLOYEE')))",
        $charge,
        toSpxContext: $chargeContext,
    ),
    [ruleAction('CHARGE_DAY', [
        'date' => expr('day.date', $charge, boolean: false, toSpxContext: $chargeContext),
        'type' => 'EL',
        'units' => $oneDay,
        'reason' => 'Sandwiched off-day between leave on the previous and next working days',
    ])],
    module: 'leave',
    except: expr(
        "leave.type IN policy.value('sandwich_exempt_types')\nOR leave.is_statutory",
        $charge,
        toSpxContext: $chargeContext,
    ),
    onConflict: ConflictPolicy::AllMatch,
    severity: 'high_risk',
    description: 'A weekly off or holiday between two leave days is charged, except sick leave and '
        . 'statutory leave such as maternity.',
    sourceRef: 'PayrollGenController::sandwichDays():770-813',
);

$sandwichSet = RuleSet::of($charge, $rlLve001);
$leaveBase = [
    "policy.value('sandwich_enabled')" => true,
    "policy.value('sandwich_exempt_types')" => ['SL'],
    'leave.is_statutory' => false,
    'leave.type' => 'EL',
    'day.is_holiday' => false,
] + $leaveRegister;

$charged = static fn (string $date, array $extra = []) => $engine->evaluate($charge, $sandwichSet, ctx(
    $extra + $leaveBase + $dayValues($date) + ['day.is_weekly_off' => in_array($date, ['2026-08-15', '2026-08-16'], true)],
    date: $date,
));

foreach (['2026-08-15', '2026-08-16'] as $offDay) {
    $result = $charged($offDay);
    same(1, count($result->actions), "RL-LVE-001 CORRECTED: {$offDay} is charged");
    same(
        $offDay,
        $result->actions[0]->parameters['date'],
        'and the charge names the day it charges',
    );
    same('1', (string) $result->actions[0]->parameters['units'], 'for one full day');
}

same(
    [],
    $charged('2026-08-15', ['leave.type' => 'SL'])->actions,
    'RL-LVE-001: Sick Leave is exempt — an exclusion that is not merely unconfigured in SmartPRS 2, it is inexpressible',
);
same(
    [],
    $charged('2026-08-15', ['leave.is_statutory' => true, 'leave.type' => 'ML'])->actions,
    'RL-LVE-001: statutory maternity leave is exempt',
);
same(
    'matched_then_excepted',
    $charged('2026-08-15', ['leave.type' => 'SL'])->trace->outcomeFor('RL-LVE-001'),
    'RL-LVE-001: and the trace says the rule applied and then let them off, not that it never applied',
);
same(
    [],
    $charged('2026-08-15', ["policy.value('sandwich_enabled')" => false])->actions,
    'RL-LVE-001: the whole policy switches off from configuration',
);
same(
    [],
    $charged('2026-08-14')->actions,
    'RL-LVE-001: a working day is not an off-day and is never sandwich-charged',
);

// ===========================================================================
// RL-CMP-001 · The DRA-expiry payment gate (off / warn / block), generalised.
//              THE ABSENCE ASYMMETRY IS THE SECOND DEFECT, and it is closed here.
// ===========================================================================

$gate = 'compliance.payment_gate';

// BLUEPRINT (5): §12 writes the reason as
//   CONCAT('Credential DRA is ', credential.status('DRA'))
// CONCAT joins a Null as nothing (§5.6), so an employee with NO credential row —
// the very case R-VII-018 exists for — reads "Credential DRA is " with the
// finding missing from it. COALESCE names the absence. LOWER() is what makes the
// two branches the same type: COALESCE unifies Text with Text, and a Code does
// not coerce to Text except through CONCAT, UPPER and LOWER (§5.4.2).
$rlCmp001 = rule(
    'RL-CMP-001',
    $gate,
    expr(
        "policy.value('credential_gate_mode') <> 'off'\n"
        . "AND context.event IN policy.value('credential_gate_events')\n"
        . "AND NOT credential.is_valid(policy.value('credential_gate_type'), context.evaluation_date)",
        $gate,
    ),
    [
        ruleAction('GATE_PAYMENT', [
            'mode' => expr("policy.value('credential_gate_mode')", $gate, boolean: false),
            'reason' => expr(
                "CONCAT('Credential DRA is ', COALESCE(LOWER(credential.status('DRA')), 'absent'))",
                $gate,
                boolean: false,
            ),
            'code' => 'CREDENTIAL_INVALID',
            'event' => expr('context.event', $gate, boolean: false),
        ]),
        ruleAction('WRITE_AUDIT', ['reason' => 'Credential gate evaluated', 'detail' => 'E-4471|gate']),
        ruleAction('NOTIFY', [
            'event' => 'compliance.gate_triggered',
            'audience_ref' => 'AUD-COMPLIANCE-AND-MANAGER',
            'dedupe_key' => 'gate:E-4471',
        ]),
    ],
    module: 'compliance',
    onConflict: ConflictPolicy::AllMatch,
    severity: 'high_risk',
    description: 'Any credential, any money point, any severity: an invalid credential warns or blocks the payment.',
    sourceRef: 'RequestController.php:1366-1394',
);

$gateSet = RuleSet::of($gate, $rlCmp001);
$gateDate = '2026-09-03';
$gateBase = [
    "policy.value('credential_gate_type')" => 'DRA',
    "policy.value('credential_gate_events')" => ['commission.approve', 'incentive.commit', 'offroll_earning.approve'],
    'context.event' => 'commission.approve',
];

/**
 * R-VII-018, resolved by the CALLER as the credential register answers it:
 * `is_valid` is FALSE when the register holds NO ROW for the type. Absence of
 * evidence is treated one way, declared, everywhere — and it is declared here,
 * once, rather than falling out of a null comparison inside the rule.
 */
$credential = static fn (?string $status, ?string $expiry): array => [
    "credential.status('DRA')" => $status,
    "credential.expiry('DRA')" => $expiry,
    "credential.is_valid('DRA',#{$gateDate}#)" => $status === 'verified'
        && $expiry !== null && $expiry >= $gateDate,
];

$expired = $credential('verified', '2026-07-31');
$valid = $credential('verified', '2027-07-31');
$missing = $credential(null, null);

$blocked = $engine->evaluate($gate, $gateSet, ctx(
    ["policy.value('credential_gate_mode')" => 'block'] + $expired + $gateBase,
    date: $gateDate,
));
same(1, count($blocked->actionsOfCode('GATE_PAYMENT')), 'RL-CMP-001: an expired DRA gates the payment');
same('block', $blocked->actionsOfCode('GATE_PAYMENT')[0]->parameters['mode'], 'RL-CMP-001: in block mode it blocks');
same(
    'Credential DRA is verified',
    $blocked->actionsOfCode('GATE_PAYMENT')[0]->parameters['reason'],
    'RL-CMP-001: with a reason the payer can read',
);
same(1, count($blocked->actionsOfCode('WRITE_AUDIT')), 'RL-CMP-001: and an audit row is written');

$warned = $engine->evaluate($gate, $gateSet, ctx(
    ["policy.value('credential_gate_mode')" => 'warn'] + $expired + $gateBase,
    date: $gateDate,
));
same('warn', $warned->actionsOfCode('GATE_PAYMENT')[0]->parameters['mode'], 'RL-CMP-001: in warn mode it warns');

$off = $engine->evaluate($gate, $gateSet, ctx(
    ["policy.value('credential_gate_mode')" => 'off'] + $expired + $gateBase,
    date: $gateDate,
));
same([], $off->actions, 'RL-CMP-001: in off mode nothing matches');

$otherEvent = $engine->evaluate($gate, $gateSet, ctx(
    ["policy.value('credential_gate_mode')" => 'block', 'context.event' => 'expense.approve'] + $expired + $gateBase,
    date: $gateDate,
));
same([], $otherEvent->actions, 'RL-CMP-001: an event outside the configured list is not gated');

$stillValid = $engine->evaluate($gate, $gateSet, ctx(
    ["policy.value('credential_gate_mode')" => 'block'] + $valid + $gateBase,
    date: $gateDate,
));
same([], $stillValid->actions, 'RL-CMP-001: a valid credential passes');

// THE ASYMMETRY, CLOSED (R-VII-018). No DRA row at all must BLOCK, not pass.
$noRecord = $engine->evaluate($gate, $gateSet, ctx(
    ["policy.value('credential_gate_mode')" => 'block'] + $missing + $gateBase,
    date: $gateDate,
));
same(
    1,
    count($noRecord->actionsOfCode('GATE_PAYMENT')),
    'RL-CMP-001: NO DRA RECORD AT ALL blocks — absence of evidence is not evidence of compliance (R-VII-018)',
);
same('block', $noRecord->actionsOfCode('GATE_PAYMENT')[0]->parameters['mode'], 'and it blocks, it does not warn');
same(
    'Credential DRA is absent',
    $noRecord->actionsOfCode('GATE_PAYMENT')[0]->parameters['reason'],
    'and the reason says the credential is absent, not that it expired',
);

// An evaluation failure at the gate HOLDS the payment (R-VII-021, D-VII-01).
// BLUEPRINT: §12 writes the failing condition as `credential.days_to_expiry('DRA') / 0`,
// which is a Number and cannot be a `when` at all — a `when` must type-check to
// Boolean (§8.3). Comparing it makes it one; the division still errors at runtime.
$failing = RuleSet::of($gate, rule('RL-CMP-001F', $gate,
    expr("credential.days_to_expiry('DRA') / 0 > 1", $gate),
    [ruleAction('GATE_PAYMENT', ['mode' => 'block', 'reason' => 'x', 'code' => 'Y', 'event' => 'commission.approve'])],
    module: 'compliance', onConflict: ConflictPolicy::AllMatch, severity: 'high_risk',
    description: 'A gate rule whose condition errors, to assert the payment is held and not released.'));

$held = (new SmartPRS\Platform\Rules\Simulator($engine))->run($gate, $failing, ctx(
    ["credential.days_to_expiry('DRA')" => 5] + $gateBase,
    date: $gateDate,
));
ok($held->failed(), 'RL-CMP-001: an evaluation failure at a payment gate is a failure, not a release (R-VII-021)');
same('ERR_DIV_ZERO', $held->trace->error['code'], 'and it is named ERR_DIV_ZERO, not swallowed');
same([], $held->actions, 'and no action — the caller holds the payment for review');

throws(
    InvalidArgumentException::class,
    static fn () => expr("credential.days_to_expiry('DRA') / 0", $gate),
    'a `when` that is not Boolean is refused at publish, before it can ever be evaluated (§8.3)',
);

// ===========================================================================
// The sandbox: a runaway rule condition is stopped by a NAMED failure (§7.7).
// ===========================================================================

group('Rules\Sandbox');

// A node bomb: one shallow expression with ~4,000 nodes, which is what fits in
// the 8,000-character publish-time source limit. It type-checks and publishes —
// the source cap and the AST depth cap do not catch it — so the runtime node
// budget is the thing that has to.
$bombArgs = 3900;
$bomb = 'MIN(' . implode(',', array_fill(0, $bombArgs, '1')) . ') > 0';
ok(strlen($bomb) <= 8000, 'the node bomb is inside the publish-time source length limit, so it publishes');

$bombRule = static fn (): SmartPRS\Platform\Rules\Rule => rule(
    'RL-BOMB',
    $penalise,
    expr($bomb, $penalise),
    [ruleAction('APPLY_DAY_PENALTY', ['fraction' => $oneDay, 'reason' => 'never emitted'])],
    onConflict: ConflictPolicy::AllMatch,
    description: 'A runaway condition, to assert the SPX node budget stops it by name.',
);
$bombSet = RuleSet::of($penalise, $bombRule());

$bombCompiled = SmartPRS\Platform\Spx\Compiled::parse($bomb, DecisionPoints::symbols($penalise));
same($bombArgs + 3, $bombCompiled->nodeCount(), 'the bomb is a wide expression: a call, its arguments, and a comparison');
ok($bombCompiled->depth() <= SmartPRS\Platform\Spx\Sandbox::MAX_DEPTH,
    'and a shallow one, so the publish-time depth cap does not catch it either');

// The decision point tightens its budget, which is what Sandbox's third argument
// to Evaluator::evaluate() is for.
try {
    (new Engine(nodeBudget: 500))->evaluate($penalise, $bombSet, ctx());
    ok(false, 'expected the node budget to stop the runaway condition');
} catch (RuleEvaluationFailed $e) {
    same('ERR_BUDGET_NODES', $e->errorCode, 'a node bomb ends in ERR_BUDGET_NODES, not a hang');
    same('RL-BOMB', $e->ruleCode, 'and the failing rule is named');
    ok(str_contains($e->getMessage(), 'node budget of 500'), 'with the budget that was exceeded in the message');
    same([], $e->trace->actions, 'and no action escapes a budget breach');
}

try {
    (new Engine(millisBudget: 0))->evaluate($penalise, $bombSet, ctx());
    ok(false, 'expected the time budget to stop the runaway condition');
} catch (RuleEvaluationFailed $e) {
    same('ERR_BUDGET_TIME', $e->errorCode, 'and an exhausted time budget is ERR_BUDGET_TIME');
    ok(str_contains($e->getMessage(), 'time budget'), 'named, with the budget in it');
}

// The budget is PER EXPRESSION and fresh each time: a rule set is not a runaway
// just because it has many rules, and one breach does not poison the next run.
$survivor = (new Engine(nodeBudget: 5000))->evaluate($penalise, $set002, ctx(
    $bands + ['attendance.late_minutes' => 132],
    $hyd,
));
same('1/2', (string) $survivor->actions[0]->parameters['fraction'], 'an ordinary rule is unaffected by the budget');

group('Rules\WorkedLibrary');

// ===========================================================================
// The stored regression harness (§11.6): the same fixtures as saved test cases.
// ===========================================================================

$cases = [
    new RuleTestCase(
        name: 'RL-ATT-001 matching: fourth qualifying late',
        ruleCode: 'RL-ATT-001',
        context: ctx($base001 + ['attendance.late_minutes' => 23] + $lates(4), date: '2026-08-26'),
        expectMatch: true,
        isSeeded: true,
    ),
    new RuleTestCase(
        name: 'RL-ATT-001 not matching: second qualifying late',
        ruleCode: 'RL-ATT-001',
        context: ctx($base001 + ['attendance.late_minutes' => 23] + $lates(2), date: '2026-08-19'),
        expectMatch: false,
        isSeeded: true,
    ),
    new RuleTestCase(
        name: 'RL-ATT-001 boundary: exactly three, one minute over grace',
        ruleCode: 'RL-ATT-001',
        context: ctx($base001 + ['attendance.late_minutes' => 11] + $lates(3), date: '2026-08-26'),
        expectMatch: true,
        expectActions: [
            ['APPLY_DAY_PENALTY', ['cap_ref' => 'ATT-PENALTY-CAP-MONTH', 'fraction' => '1/2',
                'reason' => 'Third or later late arrival over 10 minutes this payroll month']],
            ['NOTIFY', ['audience_ref' => 'AUD-EMP-AND-MANAGER', 'dedupe_key' => 'E-4471:2026-08-26',
                'event' => 'attendance.late_penalty']],
        ],
        isSeeded: true,
    ),
];

same(
    [],
    RuleTestCase::runAll($penalise, $set001, $cases, $engine),
    'R-VII-062: the three seeded cases (matching, not matching, boundary) all pass',
);

// A failing case blocks publication and says what differed.
$broken = new RuleTestCase(
    name: 'a case whose expectation no longer holds',
    ruleCode: 'RL-ATT-001',
    context: ctx($base001 + ['attendance.late_minutes' => 23] + $lates(1), date: '2026-08-05'),
    expectMatch: true,
);
$failures = RuleTestCase::runAll($penalise, $set001, [$broken], $engine);
same(1, count($failures), 'a failing stored case is reported');
ok(str_contains($failures[0]['message'], 'expected RL-ATT-001 to match'), 'naming what was expected');
ok(
    str_contains($failures[0]['message'], 'condition false'),
    'and why it did not — the trace reason, not a bare boolean',
);
ok(
    $failures[0]['trace']->decisionPoint === $penalise
    && $failures[0]['trace']->candidates !== [],
    'with the full trace attached, naming the decision point it explains and at least one candidate (§8.3)',
);

// ===========================================================================
// The simulator's day-by-day view: "3 lates in a month → half day" made visible
// as it accumulates (§11.3).
// ===========================================================================

$lateDays = ['2026-08-05' => 18, '2026-08-12' => 132, '2026-08-19' => 11, '2026-08-26' => 23];
$running = 0;
$byDate = [];

foreach ($lateDays as $date => $minutes) {
    $running++;
    $byDate[$date] = ['minutes' => $minutes, 'count' => $running];
}

$simulator = new SmartPRS\Platform\Rules\Simulator($engine);
$daily = $simulator->overRange(
    $penalise,
    $set001,
    new DateTimeImmutable('2026-08-05'),
    new DateTimeImmutable('2026-08-26'),
    static fn (DateTimeImmutable $d): EvaluationContext => ctx(
        $base001
            + ['attendance.late_minutes' => $byDate[$d->format('Y-m-d')]['minutes'] ?? 0]
            + $lates($byDate[$d->format('Y-m-d')]['count'] ?? 0),
        date: $d->format('Y-m-d'),
    ),
);

$penalised = array_keys(array_filter(
    $daily,
    static fn ($result): bool => $result->actionsOfCode('APPLY_DAY_PENALTY') !== [],
));
same(
    ['2026-08-19', '2026-08-26'],
    $penalised,
    'the simulator shows the third and fourth qualifying lates biting, and the first two free',
);
same(22, count($daily), 'and it returns one traced result per day in the range');

// ===========================================================================
// The four blueprint inconsistencies SPX had to resolve, checked from the RULE
// ENGINE side: the engine must consume exactly the choices SPX made, or a rule
// that publishes would mean something else when it runs.
// ===========================================================================

group('Rules\BlueprintConsistency');

// (1) MATCHES / CONTAINS on a Code is a PUBLISH-TIME error, so identity-from-name
//     is inexpressible in a rule — SmartPRS 2's regex over a component NAME
//     (AppDataController:2269) cannot be written here at all.
throws(
    InvalidArgumentException::class,
    static fn () => expr("employee.stage MATCHES 'prob.*'", $penalise),
    'MATCHES on a Code is refused at publish: identity comes from the code, never from a pattern over a name',
);
throws(
    InvalidArgumentException::class,
    static fn () => expr("employee.stage CONTAINS 'prob'", $penalise),
    'and so is CONTAINS on a Code — no amount of UPPER()ing rebuilds identity-from-name',
);
expr("employee.code MATCHES 'E-[0-9]+'", $penalise);
ok(true, 'while MATCHES over Text is fine: the ban is on deciding IDENTITY by pattern, not on patterns');

// (2) A keyed accessor takes an ARGUMENT LIST, not a single string literal —
//     which is what makes the two-argument accessors §5.7 declares expressible.
$twoArgument = expr("credential.is_valid('DRA', context.evaluation_date)", $gate, boolean: false);
same(
    false,
    $twoArgument->evaluate(ctx($credential(null, null) + $gateBase, date: $gateDate))->value,
    'a two-argument keyed accessor resolves against the canonical key the caller prepared',
);
throws(
    InvalidArgumentException::class,
    static fn () => expr("credential.is_valid('DRA')", $gate, boolean: false),
    'and its arity is checked at publish: one argument where two are declared is refused',
);

// (3) BANDS(value, lower_bounds, results) — two parallel lists, not a list of
//     {from, to, result} records. Contiguity and non-overlap become structural.
throws(
    SmartPRS\Platform\Spx\SpxSyntaxError::class,
    static fn () => expr(
        'BANDS(attendance.late_minutes, [ { from: 0m, to: NULL, result: 1 } ])',
        $penalise,
        boolean: false,
    ),
    'the blueprint\'s record form does not parse: the grammar has no record literal',
);
throws(
    InvalidArgumentException::class,
    static fn () => expr('BANDS(attendance.late_minutes, [0m, 120m], [1])', $penalise, boolean: false),
    'and the two lists must be the same length, checked at publish',
);

// (4) FLOOR() the FUNCTION is toward zero; the `floor` rounding MODE is toward −∞.
//     RL-ATT-005 uses STEP(..., 'floor'), so the engine depends on the second.
$negative = ctx(['attendance.late_minutes' => -50]);
same(
    '-30',
    (string) expr('FLOOR(attendance.late_minutes, 30)', $penalise, boolean: false)->evaluate($negative)->value,
    'FLOOR() the function is toward zero: −50 floors to −30 in 30-minute units',
);
same(
    '-60',
    (string) expr("STEP(attendance.late_minutes, 30m, 30m, 'floor')", $penalise, boolean: false)
        ->evaluate($negative)->value,
    "while the `floor` rounding MODE is toward −∞: the same −50 steps to −60",
);

// ===========================================================================
// The context adapter (§10.1c): every symbol reaches SPX as a typed Value, or as
// a NAMED error. Never as a guess.
// ===========================================================================

group('Rules\SpxContextAdapter');

$penaliseSymbols = DecisionPoints::symbols($penalise);

$wrapped = SpxExpression::context(ctx(['attendance.late_minutes' => 23]), $penaliseSymbols);
same(
    'Minutes',
    (string) $wrapped->symbol('attendance.late_minutes')->type,
    'a bare int under a Minutes symbol is wrapped as Minutes, from the DECLARED type — never inferred from the PHP value',
);
same(
    '2026-08-12',
    $wrapped->symbol('context.evaluation_date')->describe(),
    'and context.evaluation_date is the subject date: the only clock (R-VII-019)',
);

// A float has no exact value. Law 7 forbids one anywhere near a unit quantity,
// so it is refused rather than rounded into one.
$floatContext = SpxExpression::context(ctx(['attendance.late_minutes' => 12.5]), $penaliseSymbols);
same(
    'ERR_TYPE',
    $floatContext->symbol('attendance.late_minutes')->error->value,
    'a float is a named ERR_TYPE, not a silently rounded 12 or 13',
);
ok(
    str_contains((string) $floatContext->symbol('attendance.late_minutes')->detail, 'float has no exact value'),
    'and the detail says why, naming the entry',
);

// The exact form of the same quantity is accepted, so the refusal is about
// precision and not about halves.
same(
    '12.5',
    SpxExpression::context(ctx(['attendance.late_minutes' => Rational::of(25, 2)]), $penaliseSymbols)
        ->symbol('attendance.late_minutes')->describe(),
    'the exact Rational 25/2 is accepted: twelve and a half minutes, exactly',
);

foreach ([
    ['attendance.late_minutes' => 'twelve'],
    ['day.is_holiday' => 'yes'],
    ['employee.stage' => 42],
    ['day.date' => '12/08/2026'],
    ['day.date' => 'next friday'],
] as $mistyped) {
    $key = array_key_first($mistyped);
    same(
        'ERR_TYPE',
        SpxExpression::context(ctx($mistyped), $penaliseSymbols)->symbol($key)->error->value,
        "a mistyped '{$key}' is a named error, never a guess: " . var_export($mistyped[$key], true),
    );
}

// A key no symbol declares cannot be typed at all, so it is stored as a named
// error rather than dropped — a caller that prepared the wrong key finds out.
same(
    'ERR_TYPE',
    SpxExpression::context(ctx(['attendance.not_a_symbol' => 1]), $penaliseSymbols)
        ->prepared('attendance.not_a_symbol')->error->value,
    'an undeclared key is a named error too — its SPX type is genuinely unknown',
);

// And the error reaches an operator: it poisons the rule that READS it, by name.
$mistypedSet = RuleSet::of($penalise, rule(
    'RL-MISTYPED',
    $penalise,
    expr('attendance.late_minutes > 10m', $penalise),
    [ruleAction('APPLY_DAY_PENALTY', ['fraction' => $oneDay, 'reason' => 'never emitted'])],
    onConflict: ConflictPolicy::AllMatch,
    description: 'A rule reading a mistyped context entry, to assert the failure is named and surfaces.',
));

try {
    $engine->evaluate($penalise, $mistypedSet, ctx(['attendance.late_minutes' => 12.5]));
    ok(false, 'expected a mistyped context entry to fail the evaluation');
} catch (RuleEvaluationFailed $e) {
    same('ERR_TYPE', $e->errorCode, 'a mistyped input is ERR_TYPE at the rule that reads it');
    same('RL-MISTYPED', $e->ruleCode, 'and the rule is named');
    same([], $e->trace->actions, 'and nothing is emitted on a guess the engine refused to make');
}

// An unknown decision point has no symbol table, and inventing one would be the
// same guess one level up.
throws(
    InvalidArgumentException::class,
    static fn () => DecisionPoints::symbols('attendance.day.invented'),
    'the decision-point catalogue is closed: an unknown point has no symbol table (§3)',
);
