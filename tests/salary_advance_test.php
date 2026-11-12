<?php

declare(strict_types=1);

use SmartPRS\Surface\Modules;
use SmartPRS\Surface\Recoveries;
use SmartPRS\Surface\SalaryAdvance;
use SmartPRS\Surface\Store;

group('salary advance');

$dir = sys_get_temp_dir() . '/prs3-adv-' . bin2hex(random_bytes(4));
$store = (new Store($dir))->forTenant('1');
$store->put('designations', ['id' => '1', 'name' => 'Recovery Manager']);
$store->put('designations', ['id' => '2', 'name' => 'Director']);
$store->put('designations', ['id' => '3', 'name' => 'HR Manager']);
$store->put('designations', ['id' => '4', 'name' => 'Tele-caller']);
$store->put('employees', ['id' => '1', 'emp_code' => 'E-1', 'name' => 'Asha', 'designation_id' => '1']);
$store->put('employees', ['id' => '2', 'emp_code' => 'E-2', 'name' => 'Dev', 'designation_id' => '2']);
$store->put('employees', ['id' => '3', 'emp_code' => 'E-3', 'name' => 'Hema', 'designation_id' => '3']);
$store->put('employees', ['id' => '4', 'emp_code' => 'E-4', 'name' => 'Tara', 'designation_id' => '4', 'reporting_manager_id' => '1']);
$registry = $dir . '/modules.json';
file_put_contents($registry, json_encode(['modules' => []]));
$sa = new SalaryAdvance($store, new Modules($store, $registry), dirname(__DIR__) . '/config/salary-advance.json');
$people = $store->all('employees');

same(['Asha'], array_column($sa->signers($sa->levels()[0], $people), 'name'), 'the Manager level offers the managers');
same(['Dev'], array_column($sa->signers($sa->levels()[1], $people), 'name'), 'the Director level offers the directors');
same(['Hema'], array_column($sa->signers($sa->levels()[2], $people), 'name'), 'the HR Admin level offers HR / branch / company admins');

same(500000, SalaryAdvance::emiMinor('15000.00', 3), '15,000 over 3 months = 5,000 a month');
same(333400, SalaryAdvance::emiMinor('10000', 3), '10,000 over 3 = 3,334 a month, rounded UP to the rupee (the last instalment is the remainder)');
same(1000000, SalaryAdvance::emiMinor('10000', 1), 'one month = the whole amount');
same(0, SalaryAdvance::emiMinor('10000', 0), 'no tenure, no EMI');

same('pending', $sa->overall([]), 'nothing decided: pending');
// ONLY HR DECIDES (owner, 22 Sep; config deciding_level = hr). Manager and
// Director decisions are recorded and shown, and move nothing.
same('pending', $sa->overall(['manager' => 'Approved', 'director' => 'Approved']), 'Manager and Director approving without HR: still pending');
same('approved', $sa->overall(['hr' => 'Approved']), 'HR alone approving: approved — Manager and Director not needed');
same('approved', $sa->overall(['manager' => 'Approved', 'director' => 'Rejected', 'hr' => 'Approved']), 'a Director rejection does not block HR\'s approval');
same('rejected', $sa->overall(['manager' => 'Approved', 'director' => 'Approved', 'hr' => 'Rejected']), 'HR rejecting: rejected, whatever the others said');
same('on hold', $sa->overall(['manager' => 'Approved', 'hr' => 'On Hold']), 'HR on hold: on hold');
same('hr', (string) ($sa->decidingLevel()['key'] ?? ''), 'the deciding level is read from config/salary-advance.json');
same('approved', $sa->statusOf(['status' => 'pending', 'manager_status' => 'Rejected', 'hr_status' => 'Approved']),
    'a row saved under the old all-three rule reads under today\'s: HR approved it, so it is approved');
same('disbursed', $sa->statusOf(['status' => 'disbursed', 'hr_status' => 'Rejected']), 'once disbursed, the stored status is history');
$saLoans = new SalaryAdvance($store, new Modules($store, $registry), dirname(__DIR__) . '/config/loans.json');
same('approved', $saLoans->overall(['director' => 'Rejected', 'hr' => 'Approved']), 'Loans follow the same rule: HR decides');

$tara = $store->find('employees', '4');
ok(!$sa->shape(['amount_requested' => '15000', 'remarks' => ''], $tara, '2026-09-09')['ok'], 'a reason is required');
ok(!$sa->shape(['amount_requested' => '15000', 'amount' => '16000', 'remarks' => 'x'], $tara, '2026-09-09')['ok'], 'sanctioned cannot exceed requested');
ok(!$sa->shape(['amount_requested' => '15000', 'remarks' => 'x', 'manager_status' => 'Approved'], $tara, '2026-09-09')['ok'], 'a decision needs a decider');
$r = $sa->shape(['amount_requested' => '15000', 'amount' => '12000', 'tenure_months' => '4', 'emi_start' => '2026-10', 'remarks' => 'Medical',
    'manager_id' => '1', 'manager_status' => 'Approved', 'director_id' => '2', 'director_status' => 'Approved', 'hr_id' => '3', 'hr_status' => 'Approved'], $tara, '2026-09-09');
ok($r['ok'], 'a full request shapes');
same('3000.00', $r['row']['per_month'], 'the EMI is derived, never typed');
same('12000', $r['row']['balance'], 'the opening balance is the sanctioned amount');
same('approved', $r['row']['status'], 'three approvals: approved');

// Payroll waits for the EMI start month.
$adv = $store->put('advances', array_merge($r['row'], ['status' => 'disbursed']));
$rec = new Recoveries($store);
same([], $rec->forEmployment($tara, '2026-09')['lines'], 'September takes nothing: the EMI starts in October');
same(1, count($rec->forEmployment($tara, '2026-10')['lines']), 'October takes the first instalment');
same(300000, (int) $rec->forEmployment($tara, '2026-10')['lines'][0]['minor'], 'of 3,000');
