<?php

declare(strict_types=1);

use SmartPRS\Platform\Notification\Outbox;
use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\ChargeRules;
use SmartPRS\Surface\Leave;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\LeaveRules;
use SmartPRS\Surface\Notifications;
use SmartPRS\Surface\Store;

/**
 * THE PRODUCT DECIDED SOMEBODY'S LEAVE AND TOLD THEM NOTHING.
 *
 * `Platform\Notification\Outbox` has had a careful contract since Phase 8 —
 * queued, sent, delivered, and delivered ONLY on a provider acknowledgement —
 * and nothing had ever queued a message into it. An employee found out that
 * their leave was rejected by opening the screen and looking, which is what
 * SmartPRS 2 does and is the reason its customers ask for an app.
 *
 * The assertion this file exists for is the one about state: every message is
 * QUEUED and nothing here may ever say SENT. A `mail()` call against an
 * unconfigured host returns true on plenty of systems, and the state that lies
 * about is the one that costs a payroll team four hundred notifications.
 */

group('Notifications');

$root = dirname(__DIR__);
$policy = new LeavePolicy($root . '/config/leave-policy.json');
$rules = new LeaveRules($root . '/config/leave-rules.json');
$charge = new ChargeRules($root . '/config/leave-charge-rules.json');
$calendar = new Calendar($root . '/config/calendar.json');

$store = (new Store(sys_get_temp_dir() . '/notif-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('employees', ['emp_code' => 'E-1', 'name' => 'Told At Last']);
$store->put('leave_types', ['id' => '1', 'name' => 'Earned leave']);

$notifications = new Notifications($store, $root . '/config/notifications.json');
$leave = new Leave($store, $policy, $rules, $charge, $calendar, $notifications);

same(false, $notifications->transportConfigured(),
    'no transport is configured, and the config says so rather than leaving it to be discovered');
ok($notifications->transportNote() !== '', 'and explains why nothing is invented in its place');

$leave->openBalances('E-1', '2026-01-01');
$leave->accrueThrough('E-1', '2026-01-01', '2026-09-01');

$request = $leave->request('E-1', 'EL', '2026-09-07', '2026-09-08', 'two days off');

same([], $notifications->queued(), 'making a request queues nothing — nobody has decided anything yet');

// ---------------------------------------------------------------------------
// A DECISION TELLS THE PERSON.
// ---------------------------------------------------------------------------

$leave->decide((string) $request['id'], true, 'admin@smartprslocal', 'Cover arranged');

$queued = $notifications->queued();
same(1, count($queued), 'approving a request queues one message');
same('leave.approved', $queued[0]['kind'] ?? '', 'naming the event');
same(Outbox::QUEUED, $queued[0]['email_status'] ?? '',
    'and it is QUEUED — nothing in this build sends, and nothing claims to');

ok(str_contains((string) $queued[0]['body'], 'Cover arranged'),
    'the decision note reaches the person, because a decision they have to ask about is worse '
    . 'than no message at all');
ok(str_contains((string) $queued[0]['body'], '2026-09-07'), 'and so do the dates it was about');

// ---------------------------------------------------------------------------
// IDEMPOTENT. A screen that re-renders, or a decision recorded twice, must not
// deliver the same message again — four copies of one rejection is the version
// of this failure that reaches the founder.
// ---------------------------------------------------------------------------

same(false, $notifications->queue('leave.approved', 'E-1', 'leave.decided:' . $request['id']),
    'queueing the same dedupe key again queues nothing');
same(1, count($notifications->queued()), 'and the queue is still one message long');

// ---------------------------------------------------------------------------
// A REJECTION SAYS WHY.
// ---------------------------------------------------------------------------

$second = $leave->request('E-1', 'EL', '2026-09-14', '2026-09-15', 'more days off');
$leave->decide((string) $second['id'], false, 'admin@smartprslocal', 'Two people already away');

$all = $notifications->queued();
same(2, count($all), 'a rejection queues a message too');
$rejection = array_values(array_filter($all,
    static fn (array $m): bool => ($m['kind'] ?? '') === 'leave.rejected'));
same(1, count($rejection), 'named as a rejection, not as an approval');
ok(str_contains((string) $rejection[0]['body'], 'Two people already away'),
    'and carries the reason — Leave::decide() refuses a decision without a note precisely so '
    . 'that this message can never be empty');

// ---------------------------------------------------------------------------
// REDACTED ON THE WAY IN, not on the way out. A redaction applied at display
// time does not apply to the database, the backup or the support export.
// ---------------------------------------------------------------------------

same([], $notifications->leaking(), 'no stored message leaks anything the redactor should remove');

$notifications->queue('leave.approved', 'E-1', 'redaction-probe', [
    'days' => '1', 'type' => 'EL', 'from' => '2026-10-01', 'to' => '2026-10-01',
    'decided_by' => 'admin', 'note' => 'your OTP is 483920 and your card is 4111111111111111',
]);

$probe = array_values(array_filter($notifications->queued(),
    static fn (array $m): bool => ($m['dedupe_key'] ?? '') === 'redaction-probe'));

ok(!str_contains((string) $probe[0]['body'], '4111111111111111'),
    'a card number typed into a decision note does not reach the stored message');
same([], $notifications->leaking(),
    'and the store still reports nothing leaking — asked of the STORE, which is what a backup '
    . 'and a support export actually contain');

// ---------------------------------------------------------------------------
// AN UNDECLARED EVENT IS REFUSED. Composing a subject line from an event code
// sends a customer something nobody wrote.
// ---------------------------------------------------------------------------

throws(InvalidArgumentException::class,
    static fn () => $notifications->queue('leave.something-nobody-declared', 'E-1', 'x'),
    'an event the config does not declare is refused rather than improvised');

// ---------------------------------------------------------------------------
// A LEAVE BUILT WITHOUT NOTIFICATIONS STILL DECIDES. The decision is the
// product; telling somebody is not allowed to become a way to fail it.
// ---------------------------------------------------------------------------

$quiet = new Leave($store, $policy, $rules, $charge, $calendar);
$third = $quiet->request('E-1', 'EL', '2026-09-21', '2026-09-21', 'one more day');
$decided = $quiet->decide((string) $third['id'], true, 'admin@smartprslocal', 'Fine');

same(Leave::APPROVED, $decided['state'], 'a decision goes through with no notifications wired');
same(3, count($notifications->queued()), 'and nothing was queued for it');
