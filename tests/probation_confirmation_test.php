<?php

declare(strict_types=1);

/**
 * PROBATION → CONFIRMATION → PAYROLL (owner, 28 Sep 2026).
 *
 *  1. Admin / HR Admin are told, in their logins, at the milestones set.
 *  2. Confirm and Extend both need remarks; Extend moves the end later.
 *  3. Confirmation makes the employee Permanent on the Directory.
 *  4. (screen) Employee Type starts blank — see employee-fields.json.
 *  5. PF / ESI / PT / LWF / TDS answered at confirmation reach the payroll close,
 *     its reproduction and the payslip.
 */

use SmartPRS\Platform\Payroll\SalaryComponent;
use SmartPRS\Surface\Attendance;
use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\EmployeeExtras;
use SmartPRS\Surface\Leave;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\Notifications;
use SmartPRS\Surface\PayrollPeriod;
use SmartPRS\Surface\Payslip;
use SmartPRS\Surface\Probation;
use SmartPRS\Surface\Register;
use SmartPRS\Surface\StatutoryApplicability;
use SmartPRS\Surface\Store;
use SmartPRS\Surface\Structure;
use SmartPRS\Surface\Timeline;

group('probation confirmation');

$root = dirname(__DIR__);
$store = (new Store(sys_get_temp_dir() . '/prs3-probconf-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('companies', ['id' => 'C1', 'name' => 'Ametecs India']);
// Added on the DIRECTORY as Probationary — no employment_stage at all.
$store->put('employees', ['id' => '1', 'emp_code' => 'AI-101', 'name' => 'Directory Joiner', 'doj' => '2026-03-28',
    'employment_type' => 'Probationary', 'employer_company_id' => 'C1', 'designation' => 'Analyst']);
$store->put('employees', ['id' => '2', 'emp_code' => 'AI-102', 'name' => 'Hired Joiner', 'doj' => '2026-04-20',
    'employment_stage' => 'probation', 'employment_type' => 'Probationary']);
$store->put('employees', ['id' => '3', 'emp_code' => 'AI-103', 'name' => 'Already Permanent', 'doj' => '2020-01-01',
    'employment_type' => 'Permanent']);

$customisation = new Customisation($store);
$p = new Probation($store, $root . '/config/probation.json', $customisation, new Timeline($store));
$n = new Notifications($store, $root . '/config/notifications.json');

// ---------------------------------------------------------------- who is on it
ok($p->onProbation($store->find('employees', '1')), 'Employee Type "Probationary" alone puts somebody on probation');
ok(!$p->onProbation($store->find('employees', '3')), 'Permanent is not on probation');
$board = $p->board($store->all('employees'), '2026-09-28');
same(2, count($board['rows']), 'the Directory-added probationer is on the board');
$rowsByCode = array_column($board['rows'], null, 'code');
same('2026-09-28', $rowsByCode['AI-101']['end'], 'six months from 28 Mar ends 28 Sep');
same('today', $rowsByCode['AI-101']['bucket'], 'the last day is its own bucket');
same(1, $board['buckets']['today'], 'and counted');
same('Ametecs India', $rowsByCode['AI-101']['company'], 'the row names the company');

// ------------------------------------------------------ 1. reminders to roles
same(['administrator', 'Company Admin', 'HR Admin'], $p->notifyRoles(), 'Admin and HR Admin roles are told by default');
$p->remind($n, $store->all('employees'), '2026-09-28');
$forHr = $n->queuedFor('', 50, [Notifications::ROLE_PREFIX . 'HR Admin']);
$kinds = array_count_values(array_column($forHr, 'kind'));
same(1, $kinds['probation.last_day'] ?? 0, 'HR Admin sees "ends today" for the last-day employee');
same(1, $kinds['probation.reminder'] ?? 0, 'and ONE reminder for the one 22 days out — the 30-day milestone, not three');
same(2, count($n->queuedFor('', 50, [Notifications::ROLE_PREFIX . 'administrator'])), 'the administrator sees the same two');
same(0, count($n->queuedFor('', 50, [Notifications::ROLE_PREFIX . 'viewer'])), 'an employee login sees none of them');
same([], $n->queuedFor('hr', 50), 'nothing is addressed to the retired "hr" code any more');
same(2, $n->unreadFor('', [Notifications::ROLE_PREFIX . 'HR Admin']), 'the bell counts them unread');
$before = count($store->all('notification_log'));
$p->remind($n, $store->all('employees'), '2026-09-28');
same($before, count($store->all('notification_log')), 'running the sweep again sends nothing twice');
$p->remind($n, $store->all('employees'), '2026-10-05');
$later = array_count_values(array_column($n->queuedFor('', 50, [Notifications::ROLE_PREFIX . 'HR Admin']), 'kind'));
same(1, $later['probation.overdue'] ?? 0, 'a week later the unconfirmed one is overdue');
same(2, $later['probation.reminder'] ?? 0, 'and the other crossed the 15-day milestone — one more, not a repeat');
$one = $forHr[0];
ok($n->markRead((string) $one['id'], '', false, '2026-09-28 10:00:00', [Notifications::ROLE_PREFIX . 'HR Admin']), 'HR Admin may mark a role message read');
ok(!$n->markRead((string) $one['id'], 'AI-103', false, '2026-09-28 10:00:00', [Notifications::ROLE_PREFIX . 'viewer']), 'somebody else may not');
ok(!$p->saveNotifyRoles([], ['administrator'], 'u', 'U')['ok'], 'nobody told is refused');
ok(!$p->saveNotifyRoles(['Ghost'], ['administrator'], 'u', 'U')['ok'], 'an unknown role is refused');
ok($p->saveNotifyRoles(['administrator', 'HR Manager'], ['administrator', 'HR Manager'], 'u', 'U')['ok'], 'the tenant chooses its own');
same(['administrator', 'HR Manager'], $p->notifyRoles(), 'and it is read back');
same(6, $p->config()['default_months'], 'saving the roles left the probation months alone');

// --------------------------------------------- 2. remarks for confirm/extend
$e1 = $store->find('employees', '1');
ok(!$p->confirm($e1, '2026-09-28', 'hr', '')['ok'], 'a confirmation without remarks is refused');
ok(!$p->confirm($e1, '2026-09-28', 'hr', '   ')['ok'], 'whitespace is not remarks');
ok(!$p->extend($e1, '2026-12-31', 'hr', '')['ok'], 'an extension without remarks is refused');
ok(!$p->extend($e1, '2026-09-01', 'hr', 'more time')['ok'], 'an extension cannot move the end earlier');
same('Probationary', $store->find('employees', '1')['employment_type'], 'refusals changed nothing');

// --------------------------------------------------- 3. Permanent on confirm
$split = (new StatutoryApplicability($root . '/config/statutory-applicability.json'))
    ->split(['PF' => 'Yes', 'ESI' => 'No', 'PT' => 'No', 'LWF' => 'No', 'TDS' => 'Yes'], ['pf_applicable', 'esi_applicable']);
ok($split['ok'], 'five answers split');
same(['pf_applicable' => 1, 'esi_applicable' => 'No'], $split['columns'], 'PF is a flag column, ESI a text one');
same(['pt_applicable' => 'No', 'lwf_applicable' => 'No', 'tds_applicable' => 'Yes'], $split['extras'], 'PT, LWF and TDS are kept beside the record');
ok(!(new StatutoryApplicability($root . '/config/statutory-applicability.json'))->split(['PF' => 'maybe'], [])['ok'], 'only Yes or No');
$confirmed = $p->confirm(array_merge($e1, $split['columns']), '2026-09-28', 'hr', 'Met every target');
ok($confirmed['ok'], 'confirmed with remarks');
$after = $store->find('employees', '1');
same('Permanent', $after['employment_type'], 'the Directory\'s Employee Type reads Permanent');
same('Permanent', $after['employment_stage'], 'and so does the stage');
same('2026-09-28', $after['confirmation_date'], 'with the date');
same(1, $after['pf_applicable'], 'and the PF answer landed on the row');
ok(!$p->onProbation($after), 'off probation');
same(1, count($p->board($store->all('employees'), '2026-09-28')['rows']), 'and off the board');

// ------------------------------------------------ 5. statutory reaches payroll
$sa = new StatutoryApplicability($root . '/config/statutory-applicability.json');
same('', StatutoryApplicability::normalise(''), 'blank is not decided');
same('No', StatutoryApplicability::normalise('0'), 'a TINYINT 0 reads No');
same([], $sa->offFor(['emp_code' => 'X', 'pf_applicable' => null], []), 'nothing answered, nothing switched off');
same(['PF_EMPLOYEE', 'PF_EMPLOYER', 'PT'], $sa->offFor(['emp_code' => 'X', 'pf_applicable' => 0], ['pt_applicable' => 'No']), 'PF No and PT No switch off their components');
$components = [
    new SalaryComponent('C-BASIC', SalaryComponent::EARNING, SalaryComponent::PERCENT_OF_GROSS, ['prorates' => true], ratePercent: 50),
    new SalaryComponent('C-SPECIAL', SalaryComponent::EARNING, SalaryComponent::BALANCE, ['prorates' => true]),
    new SalaryComponent('D-PT', SalaryComponent::DEDUCTION, SalaryComponent::FLAT, ['prorates' => false], flatAmount: '200.00'),
    new SalaryComponent('PF_EMPLOYEE', SalaryComponent::DEDUCTION, SalaryComponent::FLAT, ['prorates' => false], flatAmount: '1800.00'),
];
same(['C-BASIC', 'C-SPECIAL'], array_map(static fn (SalaryComponent $c): string => $c->code,
    StatutoryApplicability::without($components, ['PF_EMPLOYEE', 'PT'])), 'a flat D-PT and a ruled PF_EMPLOYEE both come off');
same(4, count(StatutoryApplicability::without($components, [])), 'and nothing comes off when nothing is switched off');

// A real close: two identical employees, one with PT = No.
$pay = (new Store(sys_get_temp_dir() . '/prs3-probpay-' . bin2hex(random_bytes(4))))->forTenant('1');
foreach (['SP-1' => 'Yes', 'SP-2' => 'No'] as $code => $pt) {
    $pay->put('employees', ['id' => $code, 'emp_code' => $code, 'name' => $code, 'ctc' => '600000.00', 'doj' => '2026-01-01', 'status' => 'active']);
    $pay->put('leaves', ['id' => 'L-' . $code, 'employment_id' => $code, 'state' => 'approved', 'leave_type' => 'EL',
        'from' => '2026-10-01', 'to' => '2026-10-31', 'requested_at' => '2026-09-01T00:00:00+05:30']);
}
$extras = new EmployeeExtras($pay);
$extras->save('SP-1', ['pt_applicable' => 'Yes'], 't');
$extras->save('SP-2', ['pt_applicable' => 'No'], 't');
$policy = new LeavePolicy($root . '/config/leave-policy.json');
$calendar = new Calendar($root . '/config/calendar.json');
$register = new Register($pay, new PayrollPeriod(new Attendance($pay), new Leave($pay, $policy), $policy, $calendar), new Structure($pay, $root . '/config/salary-structure.json'),
    applicability: new StatutoryApplicability($root . '/config/statutory-applicability.json', $extras));
$closed = $register->close('2026-10', $calendar->shiftStart(), $calendar->shiftEnd(), 'maker@x');
ok($closed['ok'], 'the month closes: ' . ($closed['why'] ?? ''));
$rows = $closed['run']['rows'];
$codes = static fn (array $row): array => array_column((array) $row['lines'], 'code');
ok(in_array('D-PT', $codes($rows['SP-1']), true), 'PT Yes: the PT line is there');
ok(!in_array('D-PT', $codes($rows['SP-2']), true), 'PT No: no PT line');
same(20000, $rows['SP-2']['net_minor'] - $rows['SP-1']['net_minor'], 'and the net is exactly ₹200.00 higher — the PT that was not deducted');
same(['Professional Tax (PT)'], $rows['SP-2']['statutory_not_applicable'], 'the row says why');
$verified = $register->verify('2026-10');
ok($verified['reproduced'], 'the month reproduces to the paisa with the exclusion: ' . $verified['why']);
$extras->save('SP-2', ['pt_applicable' => 'Yes'], 't');
ok($register->verify('2026-10')['reproduced'], 'and still does after the answer changes — reproduction reads what was CLOSED');
$slip = Payslip::from($register->find('2026-10'), '2026-10', 'SP-2');
same(['Professional Tax (PT)'], $slip['slip']['statutory_not_applicable'] ?? null, 'the payslip carries "not applicable: PT"');
