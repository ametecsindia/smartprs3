<?php

declare(strict_types=1);

use SmartPRS\Surface\AlertEmails;
use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\Smtp;
use SmartPRS\Surface\Store;

/**
 * ALERT EMAILS (owner, 8 Oct): certificate expiry at 15 / 7 / 1 days, drafts
 * with {fields}, who gets them, and letters for recorded increments.
 */
group('Alert emails');

same(15, AlertEmails::milestone([15, 7, 1], 13), '13 days left: the 15-day mail (catches up a missed day)');
same(7, AlertEmails::milestone([15, 7, 1], 7), '7 days left: the 7-day mail');
same(1, AlertEmails::milestone([15, 7, 1], 0), 'expiring today: the 1-day mail if not sent yet');
same(null, AlertEmails::milestone([15, 7, 1], 20), '20 days left: nothing yet');
same(null, AlertEmails::milestone([15, 7, 1], -1), 'already expired: nothing');
same('Hi Asha, {nope}', AlertEmails::render('Hi {emp_name}, {nope}', ['emp_name' => 'Asha']), 'fields fill in; an unknown one stays visible');

$users = [['email' => 'hr@x.com', 'role' => 'HR Admin', 'disabled' => false], ['email' => 'gone@x.com', 'role' => 'HR Admin', 'disabled' => true]];
$byId = ['10' => ['id' => '10', 'email' => 'mgr@x.com']];
$emp = ['id' => '1', 'email' => 'Asha@X.com', 'reporting_manager_id' => '10'];
same(['to' => ['asha@x.com'], 'cc' => ['mgr@x.com', 'hr@x.com', 'cc@x.com']],
    AlertEmails::addresses(['employee' => true, 'manager' => true, 'roles' => ['hr admin'], 'cc' => 'cc@x.com, asha@x.com'], $emp, $byId, $users),
    'employee addressed; manager, enabled role logins and CC copied; no duplicates, disabled logins skipped');
same(['to' => ['mgr@x.com', 'hr@x.com'], 'cc' => []], AlertEmails::addresses(['employee' => true, 'manager' => true, 'roles' => ['HR Admin']],
    ['id' => '1', 'reporting_manager_id' => '10'], $byId, $users), 'no employee email: the copies become the addressees');

$store = (new Store(sys_get_temp_dir() . '/ae-' . bin2hex(random_bytes(4))))->forTenant('1');
$d = static fn (int $n): string => date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' days'));
$store->put('employees', ['id' => '1', 'emp_code' => 'E1', 'name' => 'Asha', 'dra_expiry' => $d(7), 'pcc_expiry' => $d(30), 'doj' => '2020-01-01']);
$store->put('employees', ['id' => '2', 'emp_code' => 'E2', 'name' => 'Left', 'dra_expiry' => $d(1), 'exit_date' => $d(-10)]);
$store->put('documents', ['employee_id' => '1', 'kind' => 'Police Verification', 'expiry' => $d(1), 'status' => 'Verified']);
$store->put('increments', ['id' => 'i1', 'employee_id' => '1', 'status' => 'Effected', 'old_ctc' => '600000.00', 'new_ctc' => '660000.00', 'pct' => '10',
    'effective' => $d(30), 'decided_at' => date('Y-m-d H:i:s'), 'new_designation' => 'Team Leader']);
$ae = new AlertEmails($store, new Customisation($store), dirname(__DIR__) . '/config/alert-emails.json');
$ae->saveAlert('promotion_letter', ['enabled' => '1', 'subject' => 'Promoted to {new_designation}', 'body' => 'Dear {emp_name}, {new_ctc} from {effective_date}', 'when' => 'on_record',
    'lookback_days' => '7', 'to_employee' => '1'], [], '1', 'hr');
$ae = new AlertEmails($store, new Customisation($store), dirname(__DIR__) . '/config/alert-emails.json');
$due = $ae->due($store->all('employees'), date('Y-m-d'), static fn (array $e): array => ['emp_name' => (string) $e['name']],
    static fn (array $e): bool => false, static fn (array $e): ?string => null);
$keys = array_map(static fn (array $x): string => $x['alert'] . ':' . ($x['values']['certificate'] ?? ''), $due);
sort($keys);
same(['certificate_expiry:DRA Certificate', 'certificate_expiry:Police Verification', 'promotion_letter:'], $keys,
    'DRA in 7 days and a document in 1 day are due, PCC in 30 is not, a leaver is never mailed, the promotion letter goes');
$promo = array_values(array_filter($due, static fn (array $x): bool => $x['alert'] === 'promotion_letter'))[0];
same('₹6,60,000.00', $promo['values']['new_ctc'], 'letter fields come from the increment');
same('Promoted to Team Leader', AlertEmails::render($ae->alerts()['promotion_letter']['subject'], $promo['values']), 'and the saved draft renders');
same(false, $ae->saveAlert('birthday', ['subject' => 'Hi {bogus}', 'body' => 'x'], [], '1', 'hr')['ok'], 'an unknown field in a draft is refused');

$msg = Smtp::message(['from_email' => 'hr@x.com', 'from_name' => 'HR'], ['a@x.com'], ['b@x.com'], 'Süb', "line\n.dot", '<p>x</p>');
ok(str_contains($msg, "Subject: =?UTF-8?B?" . base64_encode('Süb') . '?=') && str_contains($msg, "Cc: b@x.com\r\n"), 'non-ASCII subject encoded; Cc header');
ok(!preg_match('/^\.[^.]/m', $msg), 'no line starts with a single dot (SMTP end-of-data)');

// PROBATION COMPLETION AND EMI DEDUCTION (owner, 8 Oct) — both on by default.
$store2 = (new Store(sys_get_temp_dir() . '/ae2-' . bin2hex(random_bytes(4))))->forTenant('1');
$store2->put('employees', ['id' => '5', 'emp_code' => 'E5', 'name' => 'Ravi', 'doj' => '2026-01-01']);
$ae2 = new AlertEmails($store2, new Customisation($store2), dirname(__DIR__) . '/config/alert-emails.json');
$monthEnd = date('Y-m-t');
$fiveBefore = date('Y-m-d', strtotime($monthEnd . ' -5 days'));
$due2 = $ae2->due($store2->all('employees'), $fiveBefore, static fn (array $e): array => ['emp_name' => (string) $e['name']],
    static fn (array $e): bool => true, static fn (array $e): ?string => $fiveBefore,
    static fn (array $e, string $p): array => [['code' => 'LOAN-9', 'amount' => '₹4,000.00', 'label' => 'loan', 'detail' => 'instalment 3 of 12']]);
$kinds = array_column($due2, 'alert');
ok(in_array('probation_completed', $kinds, true), 'probation completion is due on the end date');
ok(in_array('emi_deduction', $kinds, true), 'the EMI email is due five days before the month ends');
$emiMail = array_values(array_filter($due2, static fn (array $x): bool => $x['alert'] === 'emi_deduction'))[0];
same('₹4,000.00', $emiMail['values']['emi_amount'], 'with the instalment the payroll will take');
same([], array_filter($ae2->due($store2->all('employees'), date('Y-m-d', strtotime($monthEnd . ' -10 days')), static fn (array $e): array => [],
    static fn (array $e): bool => false, static fn (array $e): ?string => null,
    static fn (array $e, string $p): array => [['code' => 'LOAN-9']]), static fn (array $x): bool => $x['alert'] === 'emi_deduction'), 'not ten days before');
