<?php

declare(strict_types=1);

use SmartPRS\Surface\Notifications;
use SmartPRS\Surface\RequestNotices;
use SmartPRS\Surface\Scope;
use SmartPRS\Surface\Store;

/**
 * A REQUEST IS TOLD (owner, 8 Oct): the requester's whole line up, Branch Admins
 * of their branch (own or looked-after), and company-wide roles — never the
 * requester, and every recipient gets their own message.
 */

group('request notices');

$root = dirname(__DIR__);
$store = (new Store(sys_get_temp_dir() . '/rqn-' . bin2hex(random_bytes(4))))->forTenant('1');
// 1 → 2 → 3 (line); 4 Branch Admin of B1; 5 Branch Admin of B2 who also looks after B1; 6 Branch Admin of B2 only.
$store->put('employees', ['id' => '1', 'emp_code' => 'E1', 'name' => 'Asha', 'branch_id' => 'B1', 'reporting_manager_id' => '2', 'role' => 'Employee']);
$store->put('employees', ['id' => '2', 'emp_code' => 'E2', 'name' => 'TL', 'branch_id' => 'B1', 'reporting_manager_id' => '3', 'role' => 'Team Lead']);
$store->put('employees', ['id' => '3', 'emp_code' => 'E3', 'name' => 'Mgr', 'branch_id' => 'B2', 'role' => 'Manager']);
$store->put('employees', ['id' => '4', 'emp_code' => 'E4', 'name' => 'BA1', 'branch_id' => 'B1', 'role' => 'Branch Admin']);
$store->put('employees', ['id' => '5', 'emp_code' => 'E5', 'name' => 'BA2', 'branch_id' => 'B2', 'role' => 'Branch Admin']);
$store->put('employees', ['id' => '6', 'emp_code' => 'E6', 'name' => 'BA3', 'branch_id' => 'B2', 'role' => 'Branch Admin']);

$reach = static fn (string $r): string => match ($r) {
    'Branch Admin' => Scope::BRANCH, 'HR Admin', 'Company Admin', 'administrator' => Scope::COMPANY, default => Scope::LINE,
};
$notifications = new Notifications($store, $root . '/config/notifications.json');
$rn = new RequestNotices($store, $notifications, $reach(...), ['administrator', 'HR Admin', 'Company Admin', 'Branch Admin', 'Employee'],
    static fn (string $code): array => $code === 'E5' ? ['B1'] : []);

$who = $rn->recipients($store->find('employees', '1'));
ok(in_array('E2', $who, true) && in_array('E3', $who, true), 'the whole line up: team lead and their manager');
ok(in_array('E4', $who, true), 'the Branch Admin of the branch');
ok(in_array('E5', $who, true), 'a Branch Admin who also looks after that branch');
ok(!in_array('E6', $who, true), 'not a Branch Admin of another branch');
ok(in_array('role:HR Admin', $who, true) && in_array('role:Company Admin', $who, true) && in_array('role:administrator', $who, true), 'company-wide roles');
ok(!in_array('E1', $who, true) && !in_array('role:Employee', $who, true), 'never the requester, never a line-only role');

$n = $rn->notify($store->find('employees', '1'), 'Leave request', 'leave', '77', '2 day(s) of CL');
same(count($who), $n, 'one message per recipient');
same(0, $rn->notify($store->find('employees', '1'), 'Leave request', 'leave', '77', '2 day(s) of CL'), 'and none again for the same request');
same(1, count($notifications->queuedFor('E3')), 'the manager has it in their own feed');
same(1, $notifications->unreadFor('', ['role:HR Admin']), 'and the HR Admin bell counts it');
$m = $notifications->queuedFor('E4')[0];
same('request.raised.leave', $m['kind'], 'kind routes to the approval screen');
ok(str_contains((string) $m['title'], 'E1') && str_contains((string) $m['body'], '2 day(s) of CL'), 'with the details in the message');
