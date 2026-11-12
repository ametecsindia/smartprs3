<?php

declare(strict_types=1);

use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\ChargeRules;
use SmartPRS\Surface\Leave;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\LeaveRules;
use SmartPRS\Surface\Store;

/**
 * A HOLIDAY INSIDE A LEAVE SPAN WAS CHARGED.
 *
 * `Leave::workingDaysBetween()` counted days with `(int) $day->format('N') <= 5`
 * — Monday to Friday, in PHP, ignoring the working calendar entirely. Somebody
 * who took the week of a festival paid for the festival out of their own leave,
 * which is wrong everywhere and is the commonest leave complaint there is. A
 * tenant whose weekly off is Sunday only also got Saturdays for free, because the
 * code's idea of a week was not theirs.
 *
 * The assertion that carries the weight is the DIFFERENCE one: the same span
 * costs a different number of days with and without the calendar. Asserting only
 * the new figure would pass against a class that had never read a calendar at all.
 */

group('Charge rules');

$root = dirname(__DIR__);
$charge = new ChargeRules($root . '/config/leave-charge-rules.json');
$calendar = new Calendar($root . '/config/calendar.json');

same(null, $charge->refusal(), 'the shipped charge rules file parses and publishes');
same(2, count($charge->rules()), 'both declared rules were built');

// A holiday the shipped calendar actually declares — otherwise every assertion
// below is about an ordinary Tuesday.
$holidays = array_keys($calendar->holidays());
ok($holidays !== [], 'the shipped calendar declares at least one holiday');

// The first weekday holiday a week past the rules' effective date (2026-01-01), so the span around it is in force too —
// the shipped calendar also lists 2025, when these rules were not yet in force.
$holiday = array_values(array_filter($holidays, static fn (string $d): bool => $d >= '2026-01-08'
    && !in_array((new DateTimeImmutable($d))->format('D'), $calendar->weeklyOff(), true)))[0] ?? $holidays[0];
$day = new DateTimeImmutable($holiday);
ok(!in_array($day->format('D'), $calendar->weeklyOff(), true),
    'and the one under test is a working weekday, so the exemption below is about the HOLIDAY '
    . 'and not about the weekend it happens to fall on');

// A span of exactly that one day.
$one = $charge->chargeFor('E-1', 'EL', $holiday, $holiday, $calendar);

same(0, $one['days'], 'a leave request for a holiday costs nothing');
same('LV-CHG-EXEMPT-NON-WORKING', $one['exempt'][0]['rule'] ?? '', 'and the rule that said so is named');
same([], $one['charged'], 'with nothing charged');

// An ordinary working day beside it costs one. Without this, the assertion
// above passes against a rule that exempts everything.
$before = $day->modify('-1 day');

while (in_array($before->format('D'), $calendar->weeklyOff(), true)
    || isset($calendar->holidays()[$before->format('Y-m-d')])) {
    $before = $before->modify('-1 day');
}

$ordinary = $charge->chargeFor('E-1', 'EL', $before->format('Y-m-d'), $before->format('Y-m-d'), $calendar);

same(1, $ordinary['days'], 'an ordinary working day costs one');
same('LV-CHG-WORKING-DAY', $ordinary['charged'][0]['rule'] ?? '', 'through the charging rule');

// ---------------------------------------------------------------------------
// THE DIFFERENCE THE CALENDAR MAKES, on a span that contains the holiday. This
// is the assertion that fails if the calendar is ever quietly dropped again.
// ---------------------------------------------------------------------------

$spanFrom = $day->modify('-2 days')->format('Y-m-d');
$spanTo = $day->modify('+2 days')->format('Y-m-d');

$policy = new LeavePolicy($root . '/config/leave-policy.json');
$rules = new LeaveRules($root . '/config/leave-rules.json');

$store = (new Store(sys_get_temp_dir() . '/chg-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('employees', ['emp_code' => 'E-1', 'name' => 'Festival Week']);
$store->put('leave_types', ['id' => '1', 'name' => 'Earned leave']);

$withCalendar = new Leave($store, $policy, $rules, $charge, $calendar);
$withoutRules = new Leave($store, $policy, $rules);

$aware = $withCalendar->chargeableDays('E-1', 'EL', $spanFrom, $spanTo);
$blind = $withoutRules->chargeableDays('E-1', 'EL', $spanFrom, $spanTo);

ok($aware['days'] < $blind['days'],
    'the same span costs FEWER days once the calendar is read — the holiday inside it is no '
    . 'longer taken out of somebody\'s balance');
ok($aware['exempt'] !== [], 'and the exempted days are listed, not silently missing');

// The fallback says out loud that it is one. A wrong answer that announces
// itself is recoverable; a wrong answer that looks right is not.
same(null, $aware['why'], 'the calendar-aware count has nothing to apologise for');
ok(str_contains((string) $blind['why'], 'Monday to Friday'),
    'and the fallback names exactly what it did instead');

// ---------------------------------------------------------------------------
// WITHOUT A CALENDAR THE RULES CANNOT ANSWER, and that is REPORTED. The symbols
// are null rather than false, so a rule asking about a holiday produces an
// unknown instead of quietly answering "no" for a holiday list nobody loaded.
// ---------------------------------------------------------------------------

$noCalendar = $charge->chargeFor('E-1', 'EL', $spanFrom, $spanTo, null);

ok($noCalendar['undecided'] !== [], 'with no calendar, every day is undecided');
ok(str_contains((string) $noCalendar['why'], 'could not be decided'),
    'and the span says its total is not known');

// And a request over such a span is REFUSED rather than written for a number
// nobody computed.
$storeless = (new Store(sys_get_temp_dir() . '/chg2-' . bin2hex(random_bytes(4))))->forTenant('1');
$storeless->put('employees', ['emp_code' => 'E-2', 'name' => 'No Calendar']);
$storeless->put('leave_types', ['id' => '1', 'name' => 'Earned leave']);
$undecidable = new Leave($storeless, $policy, $rules, $charge, null);

throws(InvalidArgumentException::class,
    static fn () => $undecidable->request('E-2', 'EL', $spanFrom, $spanTo, 'festival week'),
    'a request whose days cannot be counted is refused, not written for a guess');

// NON-VACUITY: break a rule and confirm the file publishes none of them.
$broken = sys_get_temp_dir() . '/charge-broken.json';
$declared = json_decode((string) file_get_contents($root . '/config/leave-charge-rules.json'), true);
$declared['rules'][0]['when'] = 'day.is_weekly_off OR day.no_such_symbol';
file_put_contents($broken, json_encode($declared));

$refusing = new ChargeRules($broken);
same([], $refusing->rules(), 'one unparseable rule publishes none of them');
ok(str_contains((string) $refusing->refusal(), 'LV-CHG-EXEMPT-NON-WORKING'),
    'and the refusal names the rule that caused it');
unlink($broken);
