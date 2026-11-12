<?php

declare(strict_types=1);

/**
 * APPROVAL AND REVERSAL — the two acts that make a closed month a paid one, or
 * an unpaid one, without ever recomputing it.
 *
 * `Payslip` reads `status`, `approved_by` and `approved_at` and watermarks a
 * payslip from an unapproved run as NOT AUTHORISED. If nothing in the product
 * ever sets those three fields, every payslip the system can produce is
 * watermarked forever and the check means nothing. So the first thing this file
 * proves is that approval sets exactly the fields the payslip reads.
 *
 * And a closed month is never recomputed, so the only honest way to undo one is
 * to record a reversal against it. The property that matters is that the
 * original run comes back BYTE-IDENTICAL afterwards: what was paid is history,
 * and a reversal that edits the run it reverses has destroyed the only record of
 * what the payment actually was.
 */

use SmartPRS\Surface\Attendance;
use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\Leave;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\PayrollPeriod;
use SmartPRS\Surface\Payslip;
use SmartPRS\Surface\Register;
use SmartPRS\Surface\Store;
use SmartPRS\Surface\Structure;

group('register approval');

$root = __DIR__ . '/..';
$store = (new Store(sys_get_temp_dir() . '/prs3-approval-' . bin2hex(random_bytes(4))))
    ->forTenant('1');
$store->put('employees', ['id' => '1', 'emp_code' => 'SP-001', 'name' => 'Kavita Sharma',
    'ctc' => '600000.00', 'doj' => '2026-01-01', 'status' => 'active']);
// Approved PAID leave for March gives the run something to pay (as recovery_test.php
// does) — with no payable days the net is ₹0 and the reversal's delta proves nothing.
$store->put('leaves', ['id' => 'L-1', 'employment_id' => 'SP-001', 'state' => 'approved',
    'leave_type' => 'EL', 'from' => '2026-03-01', 'to' => '2026-03-31', 'requested_at' => '2026-02-01T00:00:00+05:30']);

$policy = new LeavePolicy($root . '/config/leave-policy.json');
$calendar = new Calendar($root . '/config/calendar.json');
$period = new PayrollPeriod(new Attendance($store), new Leave($store, $policy), $policy, $calendar);
$register = new Register($store, $period, new Structure($store, $root . '/config/salary-structure.json'));

$closed = $register->close('2026-03', $calendar->shiftStart(), $calendar->shiftEnd(), 'maker@x');
ok($closed['ok'], 'a month closes, which is the ground everything below stands on: ' . $closed['why']);

// NON-VACUITY: the ordinary approval must WORK, or every refusal below proves
// only that the register refuses everything.
$approved = $register->approve('2026-03', 'checker@x', 'Checked against the attendance register');
ok($approved['ok'], 'a second person approves a closed run: ' . $approved['why']);

// THE THREE FIELDS `Payslip` READS. Asserted through `Payslip` itself rather
// than against the stored row, because the row agreeing with this test and
// disagreeing with the document is the failure this file exists to catch.
$stored = $register->find('2026-03');
same('approved', (string) $stored['status'], 'the stored run says approved — the field the payslip reads');
same('checker@x', (string) $stored['approved_by'], 'and who signed it');
ok((string) $stored['approved_at'] !== '', 'and when, which is half of an approval');

$slip = Payslip::from($stored, '2026-03', 'SP-001');
ok($slip['ok'], 'the approved run still produces a payslip: ' . $slip['why']);
ok($slip['slip']['approved'] === true,
    'and it is NOT watermarked unauthorised — approval is the moment a person signs, and until '
    . 'something sets these fields every payslip the system can produce is a draft forever');
same('checker@x', $slip['slip']['approved_by'], 'the payslip names the person who signed it');

// A RUN THAT IS NOT CLOSED IS NOT APPROVED. Two acts, not one.
$never = $register->approve('2026-04', 'checker@x', 'Looks fine');
ok(!$never['ok'], 'a month that was never closed cannot be approved');
ok(str_contains($never['why'], '2026-04') && str_contains($never['why'], 'nothing to'),
    'and the refusal says which month and what to do about it: ' . $never['why']);
ok(!isset($never['run']), 'and carries no run — a refusal is never a blank success');

$store->put('payroll_runs', ['id' => 'draft-1', 'cycle_label' => '2026-05', 'status' => 'draft',
    'employees_count' => 1, 'net_total' => '0.0000', 'closed_by' => 'maker@x']);
$draft = $register->approve('2026-05', 'checker@x', 'Signing early');
ok(!$draft['ok'], 'a run sitting in any state other than closed cannot be approved either');
ok(str_contains($draft['why'], 'not closed'),
    'and the refusal says so in a sentence somebody can act on: ' . $draft['why']);

// A SECOND APPROVAL IS REFUSED, not recorded over the first. Which of two
// signatures the money went out on is not a question to answer later.
$again = $register->approve('2026-03', 'other@x', 'Approving again');
ok(!$again['ok'], 'an already-approved run is not approved a second time');
ok(str_contains($again['why'], 'checker@x'),
    'and the refusal names the approval that already exists: ' . $again['why']);
same('checker@x', (string) $register->find('2026-03')['approved_by'],
    'and the first approver is still the approver — the refused attempt changed nothing');

// REVERSAL. The original run is not deleted and not edited: the whole property.
$before = json_encode($register->find('2026-03'));

$unexplained = $register->reverse('2026-03', '   ', 'checker@x');
ok(!$unexplained['ok'], 'a reversal with no stated reason is refused');
ok(str_contains($unexplained['why'], 'reason'),
    'and says that the reason is the point: ' . $unexplained['why']);
ok(!isset($unexplained['reversal']), 'and records nothing at all');
same($before, json_encode($register->find('2026-03')),
    'a refused reversal leaves the run exactly as it was');

$reversed = $register->reverse('2026-03', 'The March attendance file was loaded twice', 'checker@x');
ok($reversed['ok'], 'a reversal with a reason is recorded: ' . $reversed['why']);
same($before, json_encode($register->find('2026-03')),
    'and the ORIGINAL RUN IS BYTE-IDENTICAL afterwards — what was paid is history, and a reversal '
    . 'that edits the run it reverses has destroyed the only record of the payment');

$recorded = $register->reversalOf('2026-03');
ok($recorded !== null, 'the reversal is readable as a record against the period');
same('checker@x', (string) $recorded['raised_by'], 'naming who reversed it');
ok(str_contains((string) $recorded['reason'], 'loaded twice'), 'and why, in their words');
ok((int) $recorded['net_delta_minor'] === -(int) $stored['net_minor'],
    'and it is the NEGATIVE of what the run paid, to the paisa — the recovery is the figure '
    . 'anybody acts on');
ok((int) $recorded['net_delta_minor'] !== 0, 'which is not zero, or the line above proves nothing');

$twice = $register->reverse('2026-03', 'Loaded twice again', 'checker@x');
ok(!$twice['ok'], 'a month is not reversed twice — that recovers the same pay twice');
ok(str_contains($twice['why'], 'already reversed'), 'and says so: ' . $twice['why']);

// A REVERSED MONTH IS NOT THEN APPROVED FOR PAYMENT.
$closedApril = $register->close('2026-04', $calendar->shiftStart(), $calendar->shiftEnd(), 'maker@x');
ok($closedApril['ok'], 'April closes: ' . $closedApril['why']);
ok($register->reverse('2026-04', 'Wrong shift window', 'checker@x')['ok'], 'and is reversed');
$dead = $register->approve('2026-04', 'checker@x', 'Pay it anyway');
ok(!$dead['ok'], 'a reversed run cannot afterwards be approved for payment');
ok(str_contains($dead['why'], 'reversed'), 'and the refusal says why: ' . $dead['why']);

// ----------------------------------------------------------- two-step signing
// SmartPRS 2's HR → Finance, as data (config/payroll-approval.json). Each step is
// a different person from the closer and from every earlier signer.
group('register approval — two steps');
$store2 = (new Store(sys_get_temp_dir() . '/prs3-approval2-' . bin2hex(random_bytes(4))))->forTenant('1');
$store2->put('employees', ['id' => '1', 'emp_code' => 'SP-001', 'name' => 'Kavita Sharma', 'ctc' => '600000.00', 'doj' => '2026-01-01', 'status' => 'active']);
$period2 = new PayrollPeriod(new Attendance($store2), new Leave($store2, $policy), $policy, $calendar);
// The SHIPPED file is one step (owner, 11 Sep — see its note and the assertion below);
// the chain is still data, so a two-step tenant is exercised with its own steps.
$shippedSteps = (array) (json_decode((string) file_get_contents($root . '/config/payroll-approval.json'), true)['steps'] ?? []);
same(1, count($shippedSteps), 'the shipped configuration declares one step, as the owner asked (11 Sep)');
$twoStep = new Register($store2, $period2, new Structure($store2, $root . '/config/salary-structure.json'), null, null, [
    ['key' => 'hr', 'label' => 'HR Approval', 'permission' => 'payroll.run.approve'],
    ['key' => 'finance', 'label' => 'Finance Approval', 'permission' => 'payroll.payout.authorise'],
]);
same(2, count($twoStep->approvalSteps()), 'a tenant that declares two steps gets two');
$c2 = $twoStep->close('2026-04', $calendar->shiftStart(), $calendar->shiftEnd(), 'maker@x');
ok($c2['ok'], 'April closes: ' . $c2['why']);
$r4 = $twoStep->find('2026-04');
same(0, $twoStep->stageOf($r4), 'a closed run waits on step 1');
same('hr', $twoStep->nextStep($r4)['key'], 'which is HR');
ok(!$twoStep->approve('2026-04', 'maker@x', '')['ok'], 'the closer cannot sign step 1');
$s1 = $twoStep->approve('2026-04', 'hr@x', 'attendance checked');
ok($s1['ok'] && $s1['final'] === false, 'HR signs, and it is not final: ' . $s1['why']);
$r4 = $twoStep->find('2026-04');
same('hr_approved', (string) $r4['status'], 'the stored status names the stage');
ok(!isset($r4['approved_by']), 'and the run is NOT approved for payment yet');
same('finance', $twoStep->nextStep($r4)['key'], 'Finance is next');
ok(!$twoStep->approve('2026-04', 'hr@x', 'again')['ok'], 'HR cannot also sign as Finance');
ok(!$twoStep->approve('2026-04', 'maker@x', '')['ok'], 'nor can the closer');
$s2 = $twoStep->approve('2026-04', 'finance@x', 'funds confirmed');
ok($s2['ok'] && $s2['final'] === true, 'Finance signs, final: ' . $s2['why']);
$r4 = $twoStep->find('2026-04');
same('approved', (string) $r4['status'], 'approved');
same('finance@x', (string) $r4['approved_by'], 'signed by Finance');
ok(str_contains((string) $r4['approval_note'], 'hr@x') && str_contains((string) $r4['approval_note'], 'finance@x'), 'both signatures are on the note');
same(null, $twoStep->stageOf($r4), 'nothing left to sign');
ok(!$twoStep->approve('2026-04', 'director@x', '')['ok'], 'a third signature is refused');
