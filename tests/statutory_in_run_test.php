<?php

declare(strict_types=1);

/**
 * A SYNTHETIC PACK MUST NEVER PRODUCE A DEDUCTION LINE ON A PAYSLIP.
 *
 * The statutory engine is finished, and until now it was reachable from one
 * screen and called by nothing in the payroll run. A closed month therefore
 * contained no PF, ESI, PT or TDS — and, worse, no record that the question had
 * ever been asked. The fix is that the run ASKS, every head, every employment,
 * every close, and records what came back.
 *
 * The thing that must not happen while fixing it is far worse than the thing
 * being fixed. The only pack in this build is SYNTHETIC: jurisdiction IN-ZZ,
 * authority "this repository". Wiring its PT into the run would give every
 * payslip in the product a made-up statutory deduction that reduces somebody's
 * real net pay, under a trace that explains the arithmetic perfectly and
 * therefore looks completely plausible.
 *
 * So the load-bearing assertion here is a RUPEE FIGURE: the net of a month closed
 * WITH the statutory step is the same ₹49,800.00 as the net of a month closed
 * without one. Not "the two nets are equal" — that relationship is satisfied by
 * two identically wrong numbers, which is exactly how this repository once shipped
 * every payslip twelve times too high beneath correct working. A figure a person
 * can check by hand is the only kind of assertion that catches a synthetic
 * deduction sneaking onto a document that leaves the building.
 */

use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\Attendance;
use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\Leave;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\PayrollPeriod;
use SmartPRS\Surface\Payslip;
use SmartPRS\Surface\Register;
use SmartPRS\Surface\Statutory;
use SmartPRS\Surface\Store;
use SmartPRS\Surface\Structure;

group('statutory in run');

$root = __DIR__ . '/..';
$builder = $root . '/tools/phase6/synthetic-pack.php';

/** A register over its own empty store, so the two closes below cannot see each other. */
$registerWith = static function (?Statutory $statutory) use ($root): array {
    $store = (new Store(sys_get_temp_dir() . '/prs3-statrun-' . bin2hex(random_bytes(4))))
        ->forTenant('1');
    $store->put('employees', ['id' => '1', 'emp_code' => 'SP-001', 'name' => 'Kavita Sharma',
        'ctc' => '600000.00', 'doj' => '2026-01-01', 'status' => 'active']);
    // Approved PAID leave across the month, which is the cheapest way to give
    // this employment payable days without an attendance fixture. It matters that
    // there ARE payable days: with none, the gross prorates to zero, the statutory
    // wage base is zero, and a synthetic PT would compute to ₹0 and slip past the
    // net assertion below without moving it. The test has to be run against a
    // month in which a wrong deduction would actually show.
    $store->put('leaves', ['id' => 'L-1', 'employment_id' => 'SP-001', 'state' => 'approved',
        'leave_type' => 'EL', 'from' => '2026-10-01', 'to' => '2026-10-31',
        'requested_at' => '2026-09-01T00:00:00+05:30']);

    $policy = new LeavePolicy($root . '/config/leave-policy.json');
    $calendar = new Calendar($root . '/config/calendar.json');
    $period = new PayrollPeriod(new Attendance($store), new Leave($store, $policy), $policy, $calendar);
    $structure = new Structure($store, $root . '/config/salary-structure.json');

    return [new Register($store, $period, $structure, $statutory), $calendar];
};

// ------------------------------------------------------ the pack is synthetic
$statutory = new Statutory($builder);
$asOf = new DateTimeImmutable('2026-10-31');

ok($statutory->isAvailable(), 'the synthetic pack loads, or nothing below is testing anything: '
    . $statutory->why());
ok(in_array('PT', $statutory->heads($asOf), true),
    'the run discovers PT as a declared head by reading the rules in the pack in force — not from a '
    . "list of 'PF', 'ESI', 'PT', 'TDS' typed into PHP");
ok($statutory->isSynthetic($asOf),
    'and the pack declares itself synthetic through the manifest, which is the flag the refusal turns '
    . 'on — never a string match on the jurisdiction code');

// ------------------------------------------- the two closes, and the ONE figure
[$plain, $calendar] = $registerWith(null);
$without = $plain->close('2026-10', $calendar->shiftStart(), $calendar->shiftEnd(), 'maker@x');
ok($without['ok'], 'a month closes with no statutory step at all: ' . $without['why']);

[$asked, $calendar] = $registerWith($statutory);
$with = $asked->close('2026-10', $calendar->shiftStart(), $calendar->shiftEnd(), 'maker@x');
ok($with['ok'], 'and a month closed WITH the statutory step still closes — refusing to run payroll '
    . 'because no compliance owner has signed a pack would make the product unusable: ' . $with['why']);

$plainRow = $without['run']['rows']['SP-001'];
$askedRow = $with['run']['rows']['SP-001'];

// THE ASSERTION. A rupee figure a person can check by hand: ₹6,00,000 a year is
// ₹50,000 a month; 22 payable days of 31 calendar days is ₹50,000.00 of gross;
// the structure's one flat deduction of ₹200 leaves ₹49,800.00. If a synthetic PT
// ever reaches a payslip, this is the line that fails, and it fails by a rupee
// amount somebody can point at rather than by an equality between two computed
// quantities that could both be wrong together.
same('₹49,800.00', (string) $plainRow['net'], 'the net with no statutory step at all, in rupees');
same('₹49,800.00', (string) $askedRow['net'],
    'and the IDENTICAL rupee figure after every declared head was asked. A synthetic pack states no '
    . "law, so it must not move anybody's net pay by a paisa — not a figure, not a zero, not a "
    . 'stamped one behind a banner');
same('₹49,800.00', (string) $with['run']['net'],
    "and the month's control total is the same figure, so nothing was netted off at the run level "
    . 'either');

foreach ((array) $askedRow['lines'] as $line) {
    ok((string) $line['code'] !== 'PT',
        'no PT line was written onto the payslip (found ' . (string) $line['code'] . ')');
}

same(count((array) $plainRow['lines']), count((array) $askedRow['lines']),
    'the run asked for PT and produced exactly the same number of lines as a run that never asked');

// ------------------------------------------------- and it SAYS so, by name
$concerns = implode(' ', array_map('strval', (array) $askedRow['concerns']));

ok(str_contains($concerns, 'PT'), 'the concern names the head that was not computed');
ok(str_contains($concerns, 'SYNTHETIC') && str_contains($concerns, 'IN-ZZ'),
    'and says what the pack in force actually is, by jurisdiction');
ok(str_contains($concerns, 'signed pack'),
    'and that what is missing is a SIGNED PACK from a named compliance owner — not broken code, '
    . 'which is the wrong thing for a payroll officer to go and chase');
ok(str_contains($concerns, 'UNKNOWN'),
    'and that the amount owed is unknown rather than nil, because "we deducted nothing" and "nobody '
    . 'could tell us what to deduct" are different facts');

// -------------------------------- the document that leaves the building carries it
$slip = Payslip::from($with['run'], '2026-10', 'SP-001');
ok($slip['ok'], 'the payslip issues: ' . $slip['why']);
same('₹49,800.00', (string) $slip['slip']['net'], 'with the same net, read from the stored run');
ok($slip['slip']['deductions'] !== [] , 'the payslip still has its structural deductions');

foreach ((array) $slip['slip']['deductions'] as $deduction) {
    ok((string) $deduction['code'] !== 'PT',
        'and none of them is PT (found ' . (string) $deduction['code'] . ')');
}

ok(in_array($askedRow['concerns'][0] ?? null, (array) $slip['slip']['concerns'], true),
    'the concern reaches Payslip::from()\'s output. The payslip is the document shown to a bank, a '
    . 'landlord and a tribunal — a refusal that stops at the register is a refusal nobody outside '
    . 'the building ever sees');

// ------------------------------------------------------------- NON-VACUITY
//
// Everything above would also pass against a statutory step that was never
// called, or a Statutory that returns an empty array for everything. These four
// prove the two paths genuinely differ and that the synthetic branch is the one
// that ran.
same([], (array) $plainRow['concerns'],
    'the run that never asked raises NO concern — so the concerns below are the statutory step '
    . 'speaking, not something the period engine was going to say anyway');
ok((array) $askedRow['concerns'] !== [],
    'and the run that asked raises one. If this passed with the assertion above, the two paths are '
    . 'the same path and every "no PT line" assertion here is vacuous');

$structure = new Structure(
    (new Store(sys_get_temp_dir() . '/prs3-statrun-flags-' . bin2hex(random_bytes(4))))->forTenant('1'),
    $root . '/config/salary-structure.json',
);
$flags = [];

// The FLAGS the structure declares, exactly as the register hands them over — not
// a list of component codes typed here, which would make this test agree with a
// register that had stopped reading the configuration.
foreach ($structure->on('2026-10-31') as $component) {
    $flags[$component->code] = $component->flags;
}

// The month's OWN wage base, taken from the lines the run actually produced, and
// the flags the structure actually declares. This is the assertion that makes the
// net comparison load-bearing: if the synthetic pack priced this base at ₹0 the
// two nets would match no matter what the run did, and every "no PT line" check
// above would be vacuous.
$runAmounts = [];

foreach ((array) $plainRow['lines'] as $line) {
    $runAmounts[(string) $line['code']] = Money::ofMinor((int) $line['minor']);
}

$raw = $statutory->compute('PT', 'SP-001', $asOf, $runAmounts, $flags);
ok($raw['row'] !== null && $raw['row']->applicable && !$raw['row']->amount->isZero(),
    'the engine, asked directly with THIS month\'s wage base, DOES return a non-zero synthetic '
    . 'figure (' . ($raw['row']?->amount->format() ?? 'refused') . '). That is exactly the number '
    . 'that must never reach a payslip, and it is the number the net assertion above proves did not');

$answer = $statutory->forEmployment('SP-001', $asOf, $runAmounts, $flags);
same([], $answer['rows'],
    'and the surface the RUN calls returns no rows for the same base — the refusal is a decision '
    . 'about the pack, not an engine that computes nothing');
same(1, count($answer['concerns']),
    'and exactly one reason, one per declared head. A synthetic pack is a REASON, not a row');
