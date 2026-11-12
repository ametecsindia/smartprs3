<?php

declare(strict_types=1);

use SmartPRS\Surface\Attendance;
use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\DayRules;
use SmartPRS\Surface\EarnRules;
use SmartPRS\Surface\Leave;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\Store;

/**
 * A NUMBER COMPUTED FOR MONTHS AND READ BY NOBODY.
 *
 * `DaySettlement` has produced `overtimeMinutes` for every settled day since
 * Phase 4. The attendance screen printed it in a column. No comp-off was ever
 * credited, no overtime was ever recorded, and no rule could have said otherwise
 * because `attendance.day.earn` had no rules published against it at all.
 *
 * The assertions that matter here are the ones about POSTING TWICE. A credit
 * that appears again on a refresh is the accrual defect this codebase has
 * already fixed once, and a comp-off balance is much harder to notice going
 * wrong than a leave balance, because nobody watches it.
 */

group('Earn rules');

$root = dirname(__DIR__);
$earn = new EarnRules($root . '/config/attendance-earn-rules.json');

same(null, $earn->refusal(), 'the shipped earn rules file parses and publishes');
same(3, count($earn->rules()), 'all three declared rules were built');
same('CO', $earn->compOffType(), 'and it names the leave type a comp-off credits');

$policy = new LeavePolicy($root . '/config/leave-policy.json');
ok(array_key_exists('CO', $policy->types()),
    'the comp-off type is DECLARED in the leave policy — a credit to an undeclared type is a '
    . 'day somebody earned and cannot find');

$store = (new Store(sys_get_temp_dir() . '/earn-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('employees', ['emp_code' => 'E-9', 'name' => 'Weekend Worker']);
$store->put('leave_types', ['id' => '1', 'name' => 'Compensatory off']);

$calendar = new Calendar($root . '/config/calendar.json');
$rules = new DayRules($root . '/config/attendance-rules.json');
$attendance = new Attendance($store, $rules, $calendar, $earn);
$leave = new Leave($store, $policy);

// A FULL SHIFT ON A WEEKLY OFF. 2026-09-06 is a Sunday; the shipped calendar
// names Sunday as a weekly off, which is what makes the rule fire.
$off = new DateTimeImmutable('2026-09-06');
ok(in_array($off->format('D'), $calendar->weeklyOff(), true),
    'the fixture date really is a weekly off in the shipped calendar — otherwise every '
    . 'assertion below is about an ordinary Sunday nobody declared');

// 14 Oct 2026: a punch on a W.OFF counts only once its reporting manager approves it (an approved
// punch is the 'regularised' one) — so the weekend shift is recorded as approved.
$attendance->record('E-9', '2026-09-06 09:00:00', 'in', 'regularised');
$attendance->record('E-9', '2026-09-06 18:30:00', 'out', 'regularised');

$earned = $attendance->earnings('E-9', '2026-09-01', '2026-09-30', '09:00', '18:00');

same(1, count($earned), 'the day is reported as having earned something');
same('ATT-COMP-OFF-WEEKLY-OFF', $earned[0]['comp_offs'][0]['rule'] ?? '',
    'and the rule that said so is named');
same('1', $earned[0]['comp_offs'][0]['days'] ?? '', 'one day');

// NOTHING IS WRITTEN BY READING. earnings() runs on a screen render; a credit
// posted there would post again on every refresh.
same([], $store->all('leave_entries'), 'reading what a day earned writes nothing');

// ---------------------------------------------------------------------------
// POSTING, AND POSTING AGAIN.
// ---------------------------------------------------------------------------

$posted = $attendance->postEarnings($leave, 'E-9', '2026-09-01', '2026-09-30', '09:00', '18:00');

same(1, count($posted), 'posting credits the day once');
$balance = $leave->balance('E-9', 'CO', new DateTimeImmutable('2026-09-30'));
same('1', (string) $balance->toInt(SmartPRS\Platform\Support\RoundingMode::Down),
    'and the comp-off balance is one day');

$again = $attendance->postEarnings($leave, 'E-9', '2026-09-01', '2026-09-30', '09:00', '18:00');

same([], $again, 'posting the same range again posts nothing');
same('1', (string) $leave->balance('E-9', 'CO', new DateTimeImmutable('2026-09-30'))
    ->toInt(SmartPRS\Platform\Support\RoundingMode::Down),
    'and the balance is still one day — a comp-off balance that grows on a double-click is '
    . 'one nobody can dispute');

// An overlapping range does not re-credit either, which is the case a person
// actually creates: they post September, then post the quarter.
$overlapping = $attendance->postEarnings($leave, 'E-9', '2026-07-01', '2026-12-31', '09:00', '18:00');
same([], $overlapping, 'nor does a wider range that contains the day already posted');

// ---------------------------------------------------------------------------
// AN ORDINARY WORKING DAY EARNS A COMP-OFF FROM NOBODY. Without this the
// assertions above pass against a rule that fires on everything.
// ---------------------------------------------------------------------------

$store2 = (new Store(sys_get_temp_dir() . '/earn2-' . bin2hex(random_bytes(4))))->forTenant('1');
$store2->put('employees', ['emp_code' => 'E-10', 'name' => 'Weekday Worker']);
$store2->put('leave_types', ['id' => '1', 'name' => 'Compensatory off']);
$weekday = new Attendance($store2, $rules, $calendar, $earn);
$leave2 = new Leave($store2, $policy);

// 2026-09-08 is a Tuesday.
$weekday->record('E-10', '2026-09-08 09:00:00', 'in', 'device');
$weekday->record('E-10', '2026-09-08 18:30:00', 'out', 'device');

$weekdayEarned = $weekday->earnings('E-10', '2026-09-01', '2026-09-30', '09:00', '18:00');
$compOffs = array_merge(...array_map(static fn (array $d): array => $d['comp_offs'], $weekdayEarned ?: [[]]));

same([], $compOffs, 'a Tuesday earns no comp-off however long somebody stays');
same([], $weekday->postEarnings($leave2, 'E-10', '2026-09-01', '2026-09-30', '09:00', '18:00'),
    'and posting credits nothing');

// ---------------------------------------------------------------------------
// IT REFUSES RATHER THAN GUESSES. No comp-off type declared, no credit.
// ---------------------------------------------------------------------------

$typeless = sys_get_temp_dir() . '/earn-no-type.json';
$declared = json_decode((string) file_get_contents($root . '/config/attendance-earn-rules.json'), true);
unset($declared['comp_off']);
file_put_contents($typeless, json_encode($declared));

$blind = new Attendance($store2, $rules, $calendar, new EarnRules($typeless));
throws(RuntimeException::class,
    static fn () => $blind->postEarnings($leave2, 'E-10', '2026-09-01', '2026-09-30', '09:00', '18:00'),
    'with no comp-off type declared, posting refuses rather than choosing one');
unlink($typeless);

// And a credit to a type the leave policy does not declare is refused at the
// ledger, which is the second door on the same mistake.
throws(InvalidArgumentException::class,
    static fn () => $leave2->creditEarned('E-10', 'NOPE', '1', 'test', 'test:1', '2026-09-08'),
    'and the ledger refuses a credit to a type the policy does not declare');

// ---------------------------------------------------------------------------
// AN EXPRESSION PARAMETER IS RESOLVED, NOT PASSED THROUGH AS ITS OWN NAME.
// `"minutes": "attendance.ot_minutes"` emitted the STRING for as long as the
// bridge treated every parameter as a literal — an overtime entry whose value
// was the name of the symbol, silently.
// ---------------------------------------------------------------------------

$weekdayOt = array_merge(...array_map(static fn (array $d): array => $d['overtime'],
    $weekdayEarned ?: [[]]));

ok($weekdayOt !== [], 'the Tuesday recorded overtime');
same(30, $weekdayOt[0]['minutes'] ?? 0,
    'and the minutes are the NUMBER the day worked past its shift, not the name of the symbol');
