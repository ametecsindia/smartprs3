<?php

declare(strict_types=1);

use SmartPRS\Surface\AlertEmails;
use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\Store;

group('alert_emails_extras');

// Owner, 12 Oct: "the expiry alert should be for all documents with expiry date including Passport".
// Passport Expiry Date has no column on employees — it is held beside the record
// (employee_field_values), so the alert must read it there.
$store = (new Store(sys_get_temp_dir() . '/aex-' . bin2hex(random_bytes(4))))->forTenant('1');
$d = static fn (int $n): string => date('Y-m-d', strtotime('+' . $n . ' days'));
$store->put('employees', ['id' => '1', 'emp_code' => 'E1', 'name' => 'Asha', 'doj' => '2020-01-01']);
$store->put('employee_field_values', ['employment_code' => 'E1', 'field_code' => 'passport_expiry', 'value' => $d(15)]);
$ae = new AlertEmails($store, new Customisation($store), dirname(__DIR__) . '/config/alert-emails.json');
$due = $ae->due($store->all('employees'), date('Y-m-d'), static fn (array $e): array => ['emp_name' => (string) $e['name']],
    static fn (array $e): bool => false, static fn (array $e): ?string => null);
same(['Passport'], array_values(array_map(static fn (array $x): string => (string) $x['values']['certificate'],
    array_filter($due, static fn (array $x): bool => $x['alert'] === 'certificate_expiry'))), 'a passport expiring in 15 days is due, read from the held fields');

// Owner, 12 Oct: the previous-day absence alert lives in Alert Email Drafts, not a separate tab.
// It ships off; when on, the employees the attendance rules call ABSENT yesterday are mailed.
$absentOn = static fn (string $date): array => $date === date('Y-m-d', strtotime('-1 day')) ? ['E1'] : [];
$keysOf = static fn (array $due): array => array_values(array_map(static fn (array $x): string => $x['key'],
    array_filter($due, static fn (array $x): bool => $x['alert'] === 'absent_yesterday')));
$run = static fn () => $ae->due($store->all('employees'), date('Y-m-d'), static fn (array $e): array => ['emp_name' => (string) $e['name']],
    static fn (array $e): bool => false, static fn (array $e): ?string => null, null, $absentOn);
same([], $keysOf($run()), 'shipped off: nobody is told about yesterday');
$ae->saveAlert('absent_yesterday', ['enabled' => '1', 'subject' => 'Absent on {absent_date}', 'body' => 'Dear {emp_name}', 'to_employee' => '1'], [], '1', 'hr');
$ae = new AlertEmails($store, new Customisation($store), dirname(__DIR__) . '/config/alert-emails.json');
$due = $run();
same(['absent:1:' . date('Y-m-d', strtotime('-1 day'))], $keysOf($due), 'switched on: the absent employee is due once for yesterday');
same(SmartPRS\Surface\Dates::dmy(date('Y-m-d', strtotime('-1 day'))), array_values(array_filter($due, static fn (array $x): bool => $x['alert'] === 'absent_yesterday'))[0]['values']['absent_date'] ?? null, 'with the date in the draft');
