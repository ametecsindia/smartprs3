<?php

declare(strict_types=1);

use SmartPRS\Surface\Store;
use SmartPRS\Surface\Trail;

group('activity log: search by period, action, actor, subject and word');

$store = (new Store(sys_get_temp_dir() . '/prs3-trail-' . bin2hex(random_bytes(4))))->forTenant('1');
$trail = new Trail($store);
$trail->record('employee.created', 'employee', '7', null, ['name' => 'Asha'], '1', 'hr@example', 'Added SP-007');
$trail->record('leave.decided', 'leave_request', '3', ['state' => 'pending'], ['state' => 'approved'], '2', 'manager@example', 'Approved');
$trail->record('employee.updated', 'employee', '7', ['mobile' => '1'], ['mobile' => '2'], '1', 'hr@example', 'Mobile corrected');
$today = date('Y-m-d');

same(3, count($trail->search('', '', '', '', '', '', 100)), 'no filter = everything');
same(['employee.updated', 'employee.created'], array_column($trail->search('', '', '', 'hr@example', '', '', 100), 'action'), 'by actor, newest first');
same(1, count($trail->search('', '', 'leave.decided', '', '', '', 100)), 'by action');
same(2, count($trail->search('', '', '', '', 'employee', '', 100)), 'by subject type');
same(1, count($trail->search('', '', '', '', '', 'sp-007', 100)), 'a word, case-insensitively, in the reason');
same(1, count($trail->search('', '', '', '', '', 'approved', 100)), 'a word in the payload');
same(3, count($trail->search($today, $today, '', '', '', '', 100)), 'today is inside today');
same(0, count($trail->search('2000-01-01', '2000-12-31', '', '', '', '', 100)), 'and outside a past year');
same(2, count($trail->search('', '', '', '', '', '', 2)), 'the limit caps the rows');
same(['employee.created', 'employee.updated', 'leave.decided'], $trail->distinct('action'), 'distinct actions, sorted, for the dropdown');
