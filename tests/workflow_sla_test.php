<?php

declare(strict_types=1);

use SmartPRS\Platform\Workflow\NodeType;
use SmartPRS\Platform\Workflow\ParticipantSpec;
use SmartPRS\Platform\Workflow\Sla\BusinessCalendar;
use SmartPRS\Platform\Workflow\Sla\Sweep;
use SmartPRS\Platform\Workflow\Sla\SweepDecision;
use SmartPRS\Platform\Workflow\Sla\Timer;
use SmartPRS\Platform\Workflow\Sla\TimerSchedule;
use SmartPRS\Platform\Workflow\TaskState;
use SmartPRS\Platform\Workflow\Validator;

require_once __DIR__ . '/lib/workflow_fixtures.php';

/**
 * Part IX §IX.7 — SLA, reminders and escalation.
 *
 * §IX.7.1: SmartPRS 2 has no `due_at`, no `sla_*`, no `reminder_sent_at` and no
 * `escalate_at` column anywhere, and none of its twelve scheduled commands
 * touches a pending approval. An item assigned to somebody who never acts is
 * pending forever, invisibly.
 *
 * The two properties worth the most here are the two that are easy to get
 * subtly wrong and impossible to notice afterwards: business-hours arithmetic
 * that quietly counts weekends, and a catch-up sweep that approves a backlog
 * nobody looked at.
 */

group('Workflow SLA (§IX.7)');

$ist = new DateTimeZone('Asia/Kolkata');

// 15 August falls on a Saturday in 2026 and so proves nothing on its own; the
// Monday is the one that exercises the holiday path.
$office = BusinessCalendar::weekdays(
    'HYD-OFFICE', $ist, '09:30', '18:00',
    holidays: ['2026-08-15', '2026-08-31'],
);

// =============================================================================
// BUSINESS-HOURS ARITHMETIC (R-IX-057, R-IX-058)
// =============================================================================

// Wednesday 26 Aug 2026, 16:00 IST. Two working hours later is 09:30 the next
// morning, not 18:00 the same evening.
$raised = new DateTimeImmutable('2026-08-26 16:00:00', $ist);

same(
    '2026-08-26 18:00',
    $office->add($raised, 120)->format('Y-m-d H:i'),
    'two working hours from 16:00 lands exactly at close of business, not a minute into tomorrow',
);

// The invariant behind that assertion, which is the one that actually has to
// hold: add() and elapsedMinutes() are the same walk in two directions, so they
// must never disagree about what a working minute is. An off-by-one at the
// window boundary here is a due date that drifts by a day, once, for the
// unlucky item raised at closing time.
foreach ([1, 59, 120, 121, 480, 481, 1000] as $minutes) {
    same(
        $minutes,
        $office->elapsedMinutes($raised, $office->add($raised, $minutes)),
        "add() and elapsedMinutes() agree at {$minutes} working minutes from 16:00 on a Wednesday",
    );
}

same(
    '2026-08-27 09:31',
    $office->add($raised, 121)->format('Y-m-d H:i'),
    'and the 121st minute is the first of the next working morning — the boundary rolls over once, '
    . 'at the boundary, rather than a minute early or a minute late',
);

// Friday 28 Aug 16:00 + 4 working hours must skip the weekend. Asserted against
// a calendar with NO holidays, so the only thing this can be testing is the
// weekly off — the holiday case gets its own assertion below.
$friday = new DateTimeImmutable('2026-08-28 16:00:00', $ist);
$noHolidays = BusinessCalendar::weekdays('HYD-NO-HOLIDAYS', $ist, '09:30', '18:00');

same(
    '2026-08-31 11:30',
    $noHolidays->add($friday, 240)->format('Y-m-d H:i'),
    'four working hours from Friday afternoon lands on Monday morning — R-IX-058 excludes weekly '
    . 'offs, so an SLA raised on a Friday is not silently spent while the office is shut',
);

same(
    '2026-09-01 11:30',
    $office->add($friday, 240)->format('Y-m-d H:i'),
    'and the same four hours on the calendar that declares the Monday a holiday lands a day later '
    . 'still — the two exclusions compose rather than one masking the other',
);

// Monday 31 August is a declared holiday. An SLA raised on the Friday before it
// has to skip the weekend AND the holiday.
$beforeHoliday = new DateTimeImmutable('2026-08-28 17:00:00', $ist);

same(
    '2026-09-01 10:00',
    $office->add($beforeHoliday, 90)->format('Y-m-d H:i'),
    'a declared holiday is excluded too, using the SAME holiday calendar the attendance engine uses '
    . '— there is not a second holiday list (R-IX-058). Sixty minutes are spent before close on the '
    . 'Friday, the weekend and the Monday holiday pass, and the last thirty land on Tuesday morning',
);

same(
    510,
    $office->elapsedMinutes(
        new DateTimeImmutable('2026-08-26 09:30:00', $ist),
        new DateTimeImmutable('2026-08-27 09:30:00', $ist),
    ),
    'a whole calendar day across one working day is 510 working minutes, not 1440 — a breach reported '
    . 'in calendar minutes makes every SLA report a work of fiction',
);

same(
    0,
    $office->elapsedMinutes(
        new DateTimeImmutable('2026-08-29 09:00:00', $ist),
        new DateTimeImmutable('2026-08-30 20:00:00', $ist),
    ),
    'and a weekend elapses zero working minutes, however long it is',
);

ok(
    !$office->isWorking(new DateTimeImmutable('2026-08-26 19:00:00', $ist))
    && $office->isWorking(new DateTimeImmutable('2026-08-26 11:00:00', $ist)),
    'isWorking answers the same question the arithmetic does, so an inbox badge and a due date '
    . 'cannot disagree about whether the office is open',
);

// A break splits the day. Lunch is not working time.
$withLunch = BusinessCalendar::weekdays(
    'HYD-SHIFT', $ist, '09:00', '18:00',
    breaks: [['start' => '13:00', 'end' => '14:00']],
);

same(
    '2026-08-26 14:30',
    $withLunch->add(new DateTimeImmutable('2026-08-26 12:00:00', $ist), 90)->format('Y-m-d H:i'),
    'ninety minutes from noon crosses a one-hour lunch break and lands at 14:30, not 13:30',
);

// The other two clock modes are what they say.
$roundTheClock = BusinessCalendar::alwaysOpen();

same(
    '2026-08-30 16:00',
    $roundTheClock->add($friday, 2880)->format('Y-m-d H:i'),
    'a calendar_hours calendar is plain elapsed time — two days is two days, weekend included',
);

throws(
    InvalidArgumentException::class,
    static fn () => new BusinessCalendar('BROKEN', $ist, clockMode: 'business_hours'),
    'a business-hours calendar with no working day is refused: no SLA measured against it could ever '
    . 'elapse, which is a configuration error rather than an office that is always shut',
);

throws(
    InvalidArgumentException::class,
    static fn () => new BusinessCalendar('BROKEN', $ist, ['mon' => ['start' => '18:00', 'end' => '09:00']]),
    'and a working day that ends before it starts is refused rather than treated as zero length',
);

// =============================================================================
// WHAT TIMERS A NODE PRODUCES (§IX.7.3, R-IX-056)
// =============================================================================

same(
    [],
    TimerSchedule::forNode(wfNode('manager', NodeType::Approval, [
        'participants' => [new ParticipantSpec('requester')],
    ]), $office, $raised),
    'a node with no SLA produces no timers — R-IX-056 makes that a recorded configuration choice, '
    . 'not an absence of the capability',
);

$timed = wfNode('manager', NodeType::Approval, [
    'participants' => [new ParticipantSpec('requester')],
    'slaMinutes' => 480,
    'reminderSchedule' => [-120, 1440],
    'escalationPolicy' => 'notify_only',
    'escalationTarget' => 'u-head',
]);

$timers = TimerSchedule::forNode($timed, $office, $raised, taskId: '77');
$kinds = array_map(static fn (Timer $t): string => $t->kind, $timers);

same(['reminder', 'due', 'escalation', 'reminder'], $kinds,
    'one due timer, one per reminder offset and one escalation, ordered by when they fire');

$due = array_values(array_filter($timers, static fn (Timer $t): bool => $t->kind === 'due'))[0];

same(
    '2026-08-27 15:30',
    $due->fireAt->setTimezone($ist)->format('Y-m-d H:i'),
    'due_at is eight WORKING hours after activation, computed once from the calendar',
);

$nudge = $timers[0];
ok(
    $nudge->fireAt < $due->fireAt,
    'a negative offset is a nudge BEFORE the deadline',
);
same(
    '2026-08-27 13:30',
    $nudge->fireAt->setTimezone($ist)->format('Y-m-d H:i'),
    'and it is two WORKING hours before it — "a day before" a Monday deadline must not land on a '
    . 'Sunday, when nobody will read it',
);

same(
    ['77|reminder|1', '77|due|0', '77|escalation|0', '77|reminder|2'],
    array_map(static fn (Timer $t): string => $t->idempotencyKey(), $timers),
    'R-IX-060: every timer carries the (task, kind, sequence) key the unique index enforces, so a '
    . 'scheduler catch-up cannot send the same reminder twice',
);

throws(
    InvalidArgumentException::class,
    static fn () => wfNode('manager', NodeType::Approval, [
        'participants' => [new ParticipantSpec('requester')],
        'reminderSchedule' => [-120],
    ]),
    'a reminder schedule with no SLA is refused — offsets are relative to due_at, and without one '
    . 'they resolve to nothing rather than to "now"',
);

// R-IX-040: the clock does not reset on reassignment unless the node says so.
$activated = $raised;
$reassigned = $raised->modify('+1 day');

$kept = TimerSchedule::onReassignment($timed, $office, $activated, $reassigned, '78');
same(
    $due->fireAt->format('c'),
    array_values(array_filter($kept, static fn (Timer $t): bool => $t->kind === 'due'))[0]->fireAt->format('c'),
    'reassignment does NOT move the deadline by default — a clock that resets when a task is passed '
    . 'on makes SLA reporting meaningless, because forwarding becomes the way never to breach',
);

$resetting = wfNode('manager', NodeType::Approval, [
    'participants' => [new ParticipantSpec('requester')],
    'slaMinutes' => 480,
    'reassignmentResetsSla' => true,
]);

ok(
    TimerSchedule::onReassignment($resetting, $office, $activated, $reassigned, '78')[0]->fireAt > $due->fireAt,
    'and a node that declares reassignment_resets_sla does move it — the behaviour exists, it is '
    . 'just not the default',
);

// =============================================================================
// THE SWEEP, AND THE ONE CASE WHERE CATCHING UP IS THE DANGEROUS OUTCOME
// =============================================================================

$auto = wfNode('finance', NodeType::Approval, [
    'participants' => [new ParticipantSpec('role_in_scope', ['role_key' => 'FIN'])],
    'slaMinutes' => 480,
    'escalationPolicy' => 'auto_approve',
    'staleThresholdMinutes' => 15,
]);

$escalation = new Timer('escalation', $due->fireAt, 'finance', '77');

$onTime = Sweep::decide($escalation, $auto, $due->fireAt->modify('+2 minutes'));

same(SweepDecision::AUTO_DECIDE, $onTime->action, 'a sweep running on time applies the declared auto_approve');
same('approved', $onTime->outcome, 'with the outcome the policy names');

$late = Sweep::decide($escalation, $auto, $due->fireAt->modify('+6 hours'));

same(
    SweepDecision::SUPPRESSED,
    $late->action,
    'R-IX-066: the SAME timer, fired six hours late, is SUPPRESSED — a scheduler outage followed by '
    . 'a catch-up sweep must never silently approve a backlog of payments',
);
ok($late->blocksInstance(), 'and the instance is blocked rather than left looking decided');
ok(
    str_contains($late->reason, 'stale_timer'),
    'with the reason named, so an operator finding a stalled payment batch learns why in one read',
);
ok(
    in_array('workflow.timer.stale', $late->events, true),
    'and an operator alert is emitted — this is the one place where "the job caught up" is the '
    . 'dangerous outcome, so it must not be silent',
);

// R-IX-065: the breach is reported whether or not anything escalates.
$none = wfNode('manager', NodeType::Approval, [
    'participants' => [new ParticipantSpec('requester')],
    'slaMinutes' => 480,
]);

$breach = Sweep::decide(new Timer('due', $due->fireAt, 'manager', '77'), $none, $due->fireAt->modify('+3 hours'));

same(SweepDecision::RECORD_ONLY, $breach->action, 'with escalation_policy none the task stays');
ok(
    in_array('workflow.sla.breached', $breach->events, true),
    'but the breach is still emitted — reporting on breaches must not depend on somebody having '
    . 'configured an escalation (R-IX-065)',
);
same(
    180,
    $breach->breachMinutes,
    'and the breach duration is measured from the stored fire_at, not from the sweep\'s wall clock, '
    . 'so a late sweep still reports WHEN the breach happened',
);

// A decided task's timers have nothing left to act on.
$decided = Sweep::decide(
    new Timer('reminder', $due->fireAt, 'manager', '77', 1),
    $none,
    $due->fireAt,
    TaskState::Approved,
);

same(SweepDecision::CANCELLED, $decided->action, 'a timer on an already-decided task is cancelled, not sent');
ok(
    str_contains($decided->reason, 'approved'),
    'saying what the task was, so "why did this reminder never go out" stays answerable',
);

// R-IX-061: one digest per recipient per sweep.
$storm = [];

foreach (range(1, 14) as $n) {
    $storm[] = Sweep::decide(new Timer('reminder', $due->fireAt, 'manager', (string) $n, 1), $none, $due->fireAt);
}

$assignees = array_fill_keys(array_map(strval(...), range(1, 14)), 'u-manager');
$coalesced = Sweep::coalesce($storm, $assignees);

same(['u-manager'], array_keys($coalesced), 'fourteen overdue tasks coalesce to ONE recipient');
same(
    14,
    count($coalesced['u-manager']),
    'naming all fourteen items — an approver gets one digest, not fourteen messages. A reminder '
    . 'storm is SmartPRS 2\'s broadcast defect with a schedule attached (R-IX-061)',
);

// =============================================================================
// W16 — WHAT MAY NOT BE CONFIGURED AT ALL (R-IX-063, R-IX-066)
// =============================================================================

$autoNode = static fn (array $extra = []) => wfNode('manager', NodeType::Approval, [
    'participants' => [new ParticipantSpec('reporting_line', ['level_n' => 1])],
    'slaMinutes' => 480,
    'escalationPolicy' => 'auto_approve',
] + $extra);

$noStalePolicy = Validator::validate(wfDefinition([
    'nodes' => [
        wfNode('raise', NodeType::Submit),
        $autoNode(),
        wfNode('amend', NodeType::ReturnForEdit, ['participants' => [new ParticipantSpec('requester')]]),
        wfNode('charge', NodeType::Finalise, ['params' => ['completion_event_key' => 'leave.charged']]),
        wfNode('refuse', NodeType::Reject, ['params' => ['reason_required' => true]]),
    ],
]))->check('W16');

ok(
    $noStalePolicy?->blocks(),
    'a node that auto-approves on breach must declare stale_threshold_minutes — the threshold at '
    . 'which an automatic decision is withheld is configuration, not a platform default (R-IX-066)',
);

$section56 = Validator::validate(wfDefinition([
    'isSection56Operation' => true,
    'nodes' => [
        wfNode('raise', NodeType::Submit),
        $autoNode(['staleThresholdMinutes' => 15]),
        wfNode('amend', NodeType::ReturnForEdit, ['participants' => [new ParticipantSpec('requester')]]),
        wfNode('charge', NodeType::Finalise, ['params' => ['completion_event_key' => 'leave.charged']]),
        wfNode('refuse', NodeType::Reject, ['params' => ['reason_required' => true]]),
    ],
]))->check('W16');

ok(
    $section56?->blocks(),
    'R-IX-063: auto_approve on a Section 56 maker-checker operation is refused outright',
);
ok(
    str_contains(implode(' ', $section56->messages ?? []), 'An approval nobody gave is not a control'),
    'and the refusal says why in the blueprint\'s own words — waiting out the clock must not be a '
    . 'way to obtain an approval',
);

$inSegregationSet = Validator::validate(wfDefinition([
    'nodes' => [
        wfNode('raise', NodeType::Submit),
        $autoNode(['staleThresholdMinutes' => 15]),
        wfNode('amend', NodeType::ReturnForEdit, ['participants' => [new ParticipantSpec('requester')]]),
        wfNode('charge', NodeType::Finalise, ['params' => ['completion_event_key' => 'leave.charged']]),
        wfNode('refuse', NodeType::Reject, ['params' => ['reason_required' => true]]),
    ],
    'segregationSets' => [
        ['set_key' => 'maker_checker', 'node_keys' => ['manager', 'charge'], 'rule' => 'distinct_actor'],
    ],
]))->check('W16');

ok(
    $inSegregationSet?->blocks(),
    'and so is auto_approve on a node inside a segregation set — segregation exists so that two '
    . 'different people decide, and an automatic decision is nobody deciding',
);

same(
    'pass',
    Validator::validate(wfDefinition([
        'nodes' => [
            wfNode('raise', NodeType::Submit),
            $autoNode(['staleThresholdMinutes' => 15]),
            wfNode('amend', NodeType::ReturnForEdit, ['participants' => [new ParticipantSpec('requester')]]),
            wfNode('charge', NodeType::Finalise, ['params' => ['completion_event_key' => 'leave.charged']]),
            wfNode('refuse', NodeType::Reject, ['params' => ['reason_required' => true]]),
        ],
    ]))->check('W16')?->severity,
    'an ordinary workflow that auto-approves a low-value step, with a declared stale threshold, is '
    . 'allowed — the prohibition is about controls, not about automation',
);

throws(
    InvalidArgumentException::class,
    static fn () => wfNode('manager', NodeType::Approval, [
        'participants' => [new ParticipantSpec('requester')],
        'slaMinutes' => 480,
        'escalationPolicy' => 'notify_only',
        'escalateLevelsMax' => 0,
    ]),
    'R-IX-064: escalating zero levels is escalation_policy none written the long way round, and is '
    . 'refused rather than accepted as a no-op nobody notices',
);
