<?php

declare(strict_types=1);

use SmartPRS\Surface\Leave;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\LeaveRules;
use SmartPRS\Surface\Store;

/**
 * THE CHECK LEAVE DID NOT HAVE.
 *
 * `Leave::request()` validated two things: that the dates spanned at least one
 * day, and that a reason was typed. Not the balance. An employee with three days
 * of earned leave could ask for forty, the row was written, and it joined the
 * approvals queue looking exactly like every other request — with the balance
 * nowhere on the screen the approver reads.
 *
 * The first assertion below is the one that matters, and it is written as a
 * request that MUST be refused rather than as one that must be accepted: a test
 * that only submits valid requests passes just as happily against a class that
 * validates nothing at all, which is precisely the state this file was written
 * to end.
 */

group('Leave rules');

$rules = new LeaveRules(dirname(__DIR__) . '/config/leave-rules.json');

same(null, $rules->refusal(), 'the shipped leave rules file parses and publishes');
same(3, count($rules->rules()), 'all three declared rules were built');

$policy = new LeavePolicy(dirname(__DIR__) . '/config/leave-policy.json');
$store = (new Store(sys_get_temp_dir() . '/leave-rules-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('employees', ['emp_code' => 'E-1', 'name' => 'Balance Test']);
// `leaves.type_id` points at the tenant's leave_types, matched by NAME against
// the policy — so a request cannot be written without the row existing.
$store->put('leave_types', ['id' => '1', 'name' => 'Earned leave']);
$leave = new Leave($store, $policy, $rules);

$leave->openBalances('E-1', '2026-01-01');
// ACCRUED, not just opened. EL opens at zero and earns 1.5 days a month, so a
// balance assertion against an account that has only been opened proves nothing
// about the rule and everything about the fixture.
$leave->accrueThrough('E-1', '2026-01-01', '2026-09-01');
$type = $policy->paidCodes()[0];
$balance = $leave->balance('E-1', $type, new DateTimeImmutable('2026-09-01'));

ok($balance->compareTo(SmartPRS\Platform\Support\Rational::of(0)) > 0,
    'the fixture has an opening balance, so a refusal below is about the RULE and not about '
    . 'an employment with nothing to spend');

// ---------------------------------------------------------------------------
// THE ONE THAT COSTS. More days than the balance holds.
// ---------------------------------------------------------------------------

$over = $leave->validate('E-1', $type, '2026-09-07', '2026-12-31');

same(false, $over['allowed'], 'a request for more days than the balance is refused');
same(false, $over['undecided'], 'and it is refused by a RULE, not by the policy failing to load');
same('INSUFFICIENT_BALANCE', $over['blocks'][0]['code'] ?? '', 'with the reason named');
same('LV-NO-BALANCE', $over['blocks'][0]['rule'] ?? '', 'and the rule that said so named too');

// The write path enforces it, not just the read path. A validator the screen
// consults and the writer does not is a validator anybody can walk past.
throws(InvalidArgumentException::class,
    static fn () => $leave->request('E-1', $type, '2026-09-07', '2026-12-31', 'a long holiday'),
    'and request() refuses to write it, not merely to display a warning');

same([], $leave->requestsFor('E-1'), 'nothing was written — a refused request does not exist');

// ---------------------------------------------------------------------------
// A request WITHIN the balance goes through, so the rule is not simply
// refusing everything. Without this the assertions above pass against a
// validator that says no to the world, which every security sweep reads as fine.
// ---------------------------------------------------------------------------

$fine = $leave->validate('E-1', $type, '2026-09-07', '2026-09-08');

same(true, $fine['allowed'], 'a request inside the balance is allowed');
same([], $fine['blocks'], 'with nothing blocking it');

$written = $leave->request('E-1', $type, '2026-09-07', '2026-09-08', 'two days off');
same(1, count($leave->requestsFor('E-1')), 'and it is written');
same(Leave::PENDING, $written['state'], 'as a pending request');

// ---------------------------------------------------------------------------
// DAYS ALREADY ASKED FOR ARE NOT STILL AVAILABLE. The request above is pending
// and has committed two days; a second request that fits the raw balance but not
// the balance-less-pending must be refused. Without this an employee with five
// days submits five five-day requests, each passing on its own.
// ---------------------------------------------------------------------------

$remaining = $leave->balance('E-1', $type, new DateTimeImmutable('2026-09-10'));
$pending = $leave->pendingDays('E-1', $type);

ok($pending->compareTo(SmartPRS\Platform\Support\Rational::of(0)) > 0,
    'the pending request is counted as pending — otherwise the next assertion proves nothing');

// Ask for exactly the whole remaining balance while days are already committed.
$days = $remaining->toInt(SmartPRS\Platform\Support\RoundingMode::Down);
$to = (new DateTimeImmutable('2026-09-14'))->modify('+' . ($days + 2) . ' days')->format('Y-m-d');
$second = $leave->validate('E-1', $type, '2026-09-14', $to);

same(false, $second['allowed'], 'a second request that ignores the pending days is refused');

// ---------------------------------------------------------------------------
// A LONG ABSENCE ASKS FOR A DOCUMENT — and does not block. The two are
// different answers and ALL_MATCH is what lets a request carry both at once,
// rather than telling somebody one thing per attempt.
// ---------------------------------------------------------------------------

$fresh = (new Store(sys_get_temp_dir() . '/leave-rules-doc-' . bin2hex(random_bytes(4))))->forTenant('1');
$fresh->put('employees', ['emp_code' => 'E-2', 'name' => 'Document Test']);
$fresh->put('leave_types', ['id' => '1', 'name' => 'Earned leave']);
$second_ = new Leave($fresh, $policy, $rules);
$second_->openBalances('E-2', '2026-01-01');
$second_->accrueThrough('E-2', '2026-01-01', '2026-09-01');

$long = $second_->validate('E-2', $type, '2026-09-07', '2026-09-11');

same(true, $long['allowed'], 'five days inside the balance is allowed');
same('SUPPORTING', $long['documents'][0]['type'] ?? '', 'and asks for a supporting document');
same('LV-LONG-ABSENCE-DOC', $long['documents'][0]['rule'] ?? '', 'naming the rule that asked');

$short = $second_->validate('E-2', $type, '2026-09-07', '2026-09-08');
same([], $short['documents'], 'two days asks for nothing — the rule has a threshold and it bites');

// ---------------------------------------------------------------------------
// IT REFUSES RATHER THAN PASSES WHEN IT CANNOT DECIDE. A validator that could
// not run and one that found nothing wrong are the same shape on a screen and
// opposite facts, so the field every caller reads first says no.
// ---------------------------------------------------------------------------

$missing = new LeaveRules('/no/such/leave-rules.json');
$answer = $missing->validate('E-1', '2026-09-07', []);

same(false, $answer['allowed'], 'a missing rules file allows nothing');
same(true, $answer['undecided'], 'and says it could not decide rather than that it found nothing');
same('POLICY_NOT_LOADED', $answer['blocks'][0]['code'] ?? '', 'with a code the caller can act on');

// The same when the class is built with no rules at all, which is how every
// tool that constructs `Leave` for another purpose reaches this path.
$unruled = new Leave($fresh, $policy);
$unchecked = $unruled->validate('E-2', $type, '2026-09-07', '2026-09-08');

same(false, $unchecked['allowed'], 'a Leave with no rules refuses rather than accepting everything');
same(true, $unchecked['undecided'], 'and reports that nothing checked it');

// NON-VACUITY: break a rule and confirm the whole file refuses rather than
// publishing the two that still parse.
$broken = sys_get_temp_dir() . '/leave-rules-broken.json';
$declared = json_decode((string) file_get_contents(dirname(__DIR__) . '/config/leave-rules.json'), true);
$declared['rules'][0]['when'] = 'leave.units > leave.no_such_symbol';
file_put_contents($broken, json_encode($declared));

$refusing = new LeaveRules($broken);
same([], $refusing->rules(), 'one unparseable rule publishes none of them');
ok(str_contains((string) $refusing->refusal(), 'LV-NO-BALANCE'),
    'and the refusal names the rule that caused it');
unlink($broken);
