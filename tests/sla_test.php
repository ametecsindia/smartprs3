<?php

declare(strict_types=1);

use SmartPRS\Surface\Requests;
use SmartPRS\Surface\Sla;
use SmartPRS\Surface\Store;

/**
 * `sla_hours` IS READ, AND THE PLATFORM IS THE ONE READING IT.
 *
 * Ten approval steps declare a due-within and nothing read it: at hour 49 of a
 * 48-hour SLA, nothing happened. `Surface\Sla` bridges the linear chain
 * `Surface\Requests` runs onto `Platform\Workflow\Sla\Sweep`, and what is being
 * tested here is that the bridge preserves the three properties `Sweep` exists
 * to enforce — because a bridge that quietly recomputes them is worse than no
 * bridge: it looks like the platform is deciding.
 *
 *   1. THE BREACH TIME IS `fire_at`, NOT THE SWEEP'S CLOCK. The load-bearing
 *      assertion is a DATE STRING, not "breached_at is not now": a sweep run
 *      three days late must still say the breach happened on the 3rd.
 *   2. A STALE TIMER'S AUTOMATIC DECISION IS SUPPRESSED. The same request is
 *      swept twice, once by a sweep that is on time and once by one that is
 *      four hours behind, and the second must NOT auto-approve.
 *   3. REMINDERS COALESCE PER RECIPIENT. Two overdue requests for one approver
 *      are one digest entry naming two, not two entries.
 *
 * THE CLOCK IS FIXED. Every instant below is written out; nothing calls `now`,
 * so a test that passes at 09:00 cannot fail at 23:59 on the last of the month.
 *
 * The NON-VACUITY section at the end proves the four states are genuinely
 * different values and not four spellings of the same pass.
 */

group('sla');

// ---------------------------------------------------------------- fixed clock
//
// Everything is in +05:30 because that is what `date('c')` writes on the target
// machine, and a breach date that shifts by a timezone is the defect R-IX-057
// is about.
$RAISED   = '2026-09-01T09:00:00+05:30';
$ON_TIME  = new DateTimeImmutable('2026-09-02T08:00:00+05:30'); // 23 h after raising
$LATE     = new DateTimeImmutable('2026-09-06T09:30:00+05:30'); // 5 days after raising
$DUE_AT   = '2026-09-03T09:00:00+05:30';                        // 48 h after raising

$workflows = __DIR__ . '/../config/workflows.json';

/** A store of its own per case, so no two cases can see each other's requests. */
$storeWith = static function (array $rows): Store {
    $store = (new Store(sys_get_temp_dir() . '/prs3-sla-' . bin2hex(random_bytes(4))))->forTenant('1');

    foreach ($rows as $row) {
        $store->put('workflow_requests', $row + ['tenant_id' => '1']);
    }

    return $store;
};

/** One open expense claim of ₹1,000 — under every threshold but the manager's. */
$claim = static fn (string $id, string $raisedAt): array => [
    'id' => $id, 'workflow' => 'expense-claim', 'subject_table' => 'expenses',
    'subject_id' => 'E-1', 'amount' => '1000.00', 'note' => 'Taxi',
    'state' => Requests::OPEN, 'at_step' => 'manager',
    'raised_by_id' => '7', 'raised_by' => 'Kavita Sharma', 'raised_at' => $raisedAt,
];

$reviewOf = static function (Store $store, DateTimeImmutable $at) use ($workflows): array {
    return (new Sla(new Requests($store, $workflows)))->review($at);
};

// ------------------------------------------------- the fixture is the real one
//
// Read from `config/workflows.json` rather than typed here: a test that invents
// its own 48 would still pass if somebody deleted every `sla_hours` in the file.
$manager = null;

foreach ((new Requests($storeWith([]), $workflows))->workflow('expense-claim')['steps'] as $step) {
    if ($step['key'] === 'manager') {
        $manager = $step;
    }
}

same(48, $manager['sla_hours'] ?? null,
    'the expense claim manager step really declares a 48-hour due-within in config/workflows.json');

// ------------------------------------------------------------- inside its SLA
$within = $reviewOf($storeWith([$claim('R-1', $RAISED)]), $ON_TIME);

same(Sla::WITHIN, $within['requests'][0]['state'], 'a claim 23 hours into a 48-hour SLA is within it');
same(null, $within['requests'][0]['breached_at'], 'and nothing breached, so there is no breach time');
same($DUE_AT, $within['requests'][0]['due_at'], 'its deadline is 48 hours after it was raised');
same(0, $within['counts']['breached'], 'nothing is counted as breached');
same([], $within['digest'], 'and nobody is in the digest, because nobody is overdue');

// ------------------------------------------------------------------- breached
$breached = $reviewOf($storeWith([$claim('R-1', $RAISED)]), $LATE);
$row = $breached['requests'][0];

same(Sla::BREACHED, $row['state'], 'five days into a 48-hour SLA it has breached');

// THE BREACH TIME IS THE DEADLINE, NOT THE SWEEP'S CLOCK. Written out as a
// string: "breached_at !== now" would also be satisfied by any other wrong
// instant, and the wrong instant a careless bridge produces is the sweep's own.
same($DUE_AT, $row['breached_at'],
    'the breach is dated from fire_at — 2026-09-03 09:00, when the SLA elapsed');
ok($row['breached_at'] !== $breached['as_of'],
    'and not from the sweep clock, which is three days later');

// 2026-09-03 09:00 to 2026-09-06 09:30 is 3 days 30 minutes = 4,350 minutes.
same(4350, $row['over_by_minutes'], 'it is 3 days and 30 minutes over, measured from the deadline');
same('72h 30m over', Sla::overBy((int) $row['over_by_minutes']), 'which reads as 72h 30m over (owner rule: durations read 45m, 1h 05m)');
same(1, $breached['counts']['breached'], 'and it is counted once');
same(Sla::ACTION_TAKEN, $row['action_taken'], 'and what was done about it is: reported only');

// A LATER SWEEP MUST NOT MOVE THE BREACH. The same request seen a week later is
// the same breach at the same instant — this is the property that makes an SLA
// report survive a scheduler that was switched off over a long weekend.
$muchLater = $reviewOf($storeWith([$claim('R-1', $RAISED)]),
    new DateTimeImmutable('2026-09-13T09:30:00+05:30'));

same($DUE_AT, $muchLater['requests'][0]['breached_at'],
    'a sweep run a week later still dates the breach to the day it happened');
ok($muchLater['requests'][0]['over_by_minutes'] > $row['over_by_minutes'],
    'though the request is by then further over');

// ------------------------------------------------------- no due-within at all
//
// The attendance-regularisation manager step declares 24 hours, so a step with
// none is built by stripping it — through the SAME chain, so what differs is
// only the declaration.
$noSla = json_decode((string) file_get_contents($workflows), true);

foreach ($noSla['workflows'] as $i => $flow) {
    foreach ($flow['steps'] as $j => $step) {
        unset($noSla['workflows'][$i]['steps'][$j]['sla_hours']);
    }
}

$strippedFile = sys_get_temp_dir() . '/prs3-sla-nosla-' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($strippedFile, json_encode($noSla));

$undeclared = (new Sla(new Requests($storeWith([$claim('R-1', $RAISED)]), $strippedFile)))->review($LATE);
$undeclaredRow = $undeclared['requests'][0];

same(Sla::NO_DEADLINE, $undeclaredRow['state'],
    'a step that declares no due-within is its own state, five days in');
ok($undeclaredRow['state'] !== Sla::WITHIN,
    'and specifically NOT "within" — nobody set a deadline, which is not the same as keeping one');
same(0, $undeclared['counts']['within'], 'it is not counted as within SLA');
same(0, $undeclared['counts']['breached'], 'nor as breached');
same(1, $undeclared['counts']['no_deadline'], 'it is counted on its own');
same(null, $undeclaredRow['due_at'], 'there is no deadline to print');
ok(str_contains((string) $undeclaredRow['why'], 'not "on time"'),
    'and it says so: ' . substr((string) $undeclaredRow['why'], 0, 60) . '…');

unlink($strippedFile);

// --------------------------------------------------- coalesced, one per person
//
// Two overdue claims, both waiting on the same step and therefore the same
// approver. R-IX-061: ONE digest entry naming two, not two entries.
$two = $reviewOf($storeWith([$claim('R-1', $RAISED), $claim('R-2', $RAISED)]), $LATE);

same(2, $two['counts']['breached'], 'both claims are overdue');
same(1, count($two['digest']), 'and they coalesce into ONE digest entry, not one message each');
same(2, $two['digest'][0]['count'], 'which names both');
same(['R-1', 'R-2'], $two['digest'][0]['request_ids'], 'and lists both by id');
same('administrator', $two['digest'][0]['recipient'], 'addressed to the step\'s approver');
ok((bool) $two['digest'][0]['recipient_is_a_role'],
    'and honest that this is a role and not a person, because that is all the surface knows');

// AND NOBODY WAS TOLD.
same(false, $two['digest'][0]['sent'], 'nothing was sent — there is no channel in this build');
ok(str_contains((string) $two['digest'][0]['why_not_sent'], 'REPORTED, NOT SENT'),
    'and the entry carries the reason, so a screen cannot print it as an escalation');
ok(str_contains(Sla::REPORTED_NOT_SENT, 'shown on a page somebody may not open'),
    'the wording keeps the distinction between escalated and merely displayed');

// ----------------------------------------------------------- stale suppression
//
// R-IX-066. A step that decides on a human's behalf, swept ON TIME and swept
// LATE. The late one must withhold — a scheduler outage followed by a catch-up
// sweep must never silently approve a backlog.
$auto = json_decode((string) file_get_contents($workflows), true);

foreach ($auto['workflows'] as $i => $flow) {
    if ($flow['key'] !== 'expense-claim') {
        continue;
    }

    foreach ($flow['steps'] as $j => $step) {
        if ($step['key'] === 'manager') {
            $auto['workflows'][$i]['steps'][$j]['escalation_policy'] = 'auto_approve';
            $auto['workflows'][$i]['steps'][$j]['stale_threshold_minutes'] = 15;
        }
    }
}

$autoFile = sys_get_temp_dir() . '/prs3-sla-auto-' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($autoFile, json_encode($auto));

$sweptAt = static fn (string $at): array => (new Sla(new Requests(
    $storeWith([$claim('R-1', $RAISED)]), $autoFile)))->review(new DateTimeImmutable($at));

// Five minutes after the deadline: inside the 15-minute stale threshold.
$prompt = $sweptAt('2026-09-03T09:05:00+05:30');
// Four hours after it: the scheduler was not running.
$stale = $sweptAt('2026-09-03T13:00:00+05:30');

same(false, $prompt['requests'][0]['suppressed'],
    'a sweep five minutes behind is trusted, so the automatic decision stands');
ok(in_array('escalation at 2026-09-03 09:00 on task R-1 → auto_decide: escalation_policy is '
    . 'auto_approve and the SLA elapsed', $prompt['requests'][0]['sweep'], true),
    'and the platform says so in its own words');

same(true, $stale['requests'][0]['suppressed'],
    'a sweep four hours behind has its automatic decision WITHHELD (R-IX-066)');
same(1, $stale['counts']['suppressed'], 'and the suppression is counted, not swallowed');
ok(str_contains((string) $stale['requests'][0]['why_suppressed'], 'stale_timer'),
    'with the platform\'s reason: ' . substr((string) $stale['requests'][0]['why_suppressed'], 0, 50) . '…');
ok(!str_contains(implode(' ', $stale['requests'][0]['sweep']), 'auto_decide'),
    'and nothing auto-decided during the outage — that is the whole point of R-IX-066');

// The breach is still reported at the right instant either way: suppressing the
// DECISION must not suppress the RECORD (R-IX-065).
same($DUE_AT, $stale['requests'][0]['breached_at'],
    'the breach is still recorded, and still dated from fire_at');

unlink($autoFile);

// ---------------------------------------------------------------- unanswerable
$gone = $reviewOf($storeWith([['workflow' => 'no-such-workflow'] + $claim('R-1', $RAISED)]), $LATE);

same(Sla::UNANSWERABLE, $gone['requests'][0]['state'],
    'a request whose workflow is no longer declared cannot be measured');
ok($gone['requests'][0]['state'] !== Sla::WITHIN,
    'and is not quietly reported as within its SLA');

// ------------------------------------------------------------------ NON-VACUITY
//
// Everything above is `same()` against a constant, and four constants that were
// accidentally equal would pass every one of them. These prove the outcomes are
// genuinely different — and that the same request really does change answer
// when only the clock, or only the declaration, changes.
group('sla non-vacuity');

$states = [Sla::WITHIN, Sla::BREACHED, Sla::NO_DEADLINE, Sla::UNANSWERABLE];
same(4, count(array_unique($states)), 'the four states are four different values, not four names for one');

same(4, count(array_unique([
    $within['requests'][0]['state'],
    $breached['requests'][0]['state'],
    $undeclaredRow['state'],
    $gone['requests'][0]['state'],
])), 'and the four cases above really produced four different answers');

ok($within['requests'][0]['state'] !== $breached['requests'][0]['state'],
    'the SAME request, same store shape, same 48 hours: only the clock moved, and the answer changed');
ok($prompt['requests'][0]['suppressed'] !== $stale['requests'][0]['suppressed'],
    'the SAME request and the same deadline: only the sweep\'s lateness moved, and suppression changed');
ok($breached['requests'][0]['over_by_minutes'] !== $muchLater['requests'][0]['over_by_minutes'],
    'lateness moves with the sweep even though the breach instant does not — so the two are not one field');
same(1, count(array_unique([$breached['requests'][0]['breached_at'], $muchLater['requests'][0]['breached_at']])),
    'while the breach instant is the same on both sweeps');

ok($two['digest'][0]['count'] > count($two['digest']),
    'the digest really coalesced: two overdue tasks, one entry — if it did not, these would be equal');
same(0, count($within['digest']),
    'and a digest is not produced unconditionally: with nothing overdue there is no entry at all');
