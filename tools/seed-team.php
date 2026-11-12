<?php

declare(strict_types=1);

/**
 * SEED A REAL-LOOKING TEAM, ITS LOGINS, ITS LEAVES AND ITS ATTENDANCE.
 *
 *   php tools/seed-team.php --reset            wipe the tenant's people data, then build
 *   php tools/seed-team.php                    build (skips codes that already exist)
 *   php tools/seed-team.php --tenant=2 --dry-run
 *
 * Everything about the team is config/seed-team.json: two branches of the
 * tenant's company, each with one manager, two team leaders (Collections /
 * Recovery) and its executives; two branch admins; one HR manager. CTC bands per
 * role; PAN on everybody; PF and ESI applicability derived from the CTC through
 * the ceilings in the same file.
 *
 * Then, day by day from `from` to `to`, on the tenant's own working days
 * (Holiday / W.OFF Schedule) and each employee's own Working Shift:
 *   • leaves are REQUESTED through the leave module as the employee's own login
 *     and DECIDED by the branch manager, the HR manager or the branch admin —
 *     the audit trail shows who did what;
 *   • every other working day has four punches (in, out for lunch, back, out);
 *     a few are late past grace, a couple leave early (a half day), and an
 *     unplanned absence is rare — the rates are in the config and read like a
 *     real team, not a random dump.
 * No payslip is generated: pay runs are for the owner to run by hand.
 *
 * --reset deletes, for this tenant only: employees, their logins (the seeded
 * admin account stays), attendance logs / ratings / corrections, leaves and the
 * leave ledger, employment events, teams, loans, advances, payroll runs and
 * payslips. Organization Setup (companies, branches, departments, designations)
 * is kept; missing entries the config names are added.
 *
 * ponytail: deterministic (seeded mt_rand) so two runs give the same team.
 */
require_once __DIR__ . '/../tests/lib/bootstrap.php';

use SmartPRS\Platform\Identity\PasswordHasher;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\Amount;
use SmartPRS\Surface\Authorisation;
use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\ChargeRules;
use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\Database;
use SmartPRS\Surface\Leave;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\LeaveRules;
use SmartPRS\Surface\Modules;
use SmartPRS\Surface\Shifts;
use SmartPRS\Surface\SqlStore;
use SmartPRS\Surface\Trail;
use SmartPRS\Surface\Users;

$opts = getopt('', ['tenant::', 'from::', 'to::', 'dry-run', 'reset']);
$cfg = json_decode((string) file_get_contents(__DIR__ . '/../config/seed-team.json'), true, 32, JSON_THROW_ON_ERROR);
$from = (string) ($opts['from'] ?? $cfg['from']);
$to = (string) ($opts['to'] ?? $cfg['to']);
$dry = isset($opts['dry-run']);
$pdo = Database::connect(__DIR__ . '/../.env');

if ($pdo === null) {
    fwrite(STDERR, "No database connection — this seeds MySQL; check .env.\n");
    exit(1);
}

$tenant = (string) ($opts['tenant'] ?? '');

if ($tenant === '') {
    $u = $pdo->query("SELECT tenant_id FROM users WHERE email = 'admin@smartprslocal' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $tenant = (string) ($u['tenant_id'] ?? '');
}

if ($tenant === '') {
    fwrite(STDERR, "Which tenant? Pass --tenant=<id>.\n");
    exit(1);
}

$store = (new SqlStore($pdo))->forTenant($tenant);
$has = static fn (string $table): bool => (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();

// ------------------------------------------------------------------ reset
if (isset($opts['reset'])) {
    echo "reset: tenant {$tenant}\n";

    foreach (['attendance_logs', 'attendance_ratings', 'attendance_corrections', 'leaves', 'leave_entries', 'employment_events',
        'loans', 'advances', 'payslips', 'payroll_runs', 'teams', 'employees'] as $table) {
        if (!$has($table)) {
            continue;
        }

        $n = $dry ? (int) $pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE tenant_id = " . (int) $tenant)->fetchColumn()
            : $pdo->exec("DELETE FROM `{$table}` WHERE tenant_id = " . (int) $tenant);
        printf("  - %-24s %d row(s)\n", $table, $n);
    }

    // Logins bound to an employee go with the employee; the seeded admin and any unbound account stay.
    $n = $dry ? (int) $pdo->query('SELECT COUNT(*) FROM users WHERE tenant_id = ' . (int) $tenant . ' AND employee_id IS NOT NULL')->fetchColumn()
        : $pdo->exec('DELETE FROM users WHERE tenant_id = ' . (int) $tenant . ' AND employee_id IS NOT NULL');
    printf("  - %-24s %d row(s)\n", 'users (employee logins)', $n);
}

// ------------------------------------------------------------------ organization setup
$live = static fn (string $t): array => array_values(array_filter($store->all($t), static fn (array $r): bool => !Modules::isRetired($r)));
$companies = $live('companies');

if ($companies === []) {
    fwrite(STDERR, "No company under Organization Setup → Companies. Add at least one first.\n");
    exit(1);
}

$company = $companies[0];

foreach ($companies as $c) {
    if ((string) ($cfg['company_code'] ?? '') !== '' && strcasecmp((string) ($c['code'] ?? ''), (string) $cfg['company_code']) === 0) {
        $company = $c;
    }
}

$companyId = (string) $company['id'];
echo "company: {$company['name']}\n";
$byName = static function (array $rows, string $name): ?array {
    foreach ($rows as $r) {
        if (strcasecmp(trim((string) ($r['name'] ?? '')), $name) === 0) {
            return $r;
        }
    }

    return null;
};
$ensure = static function (string $table, string $name, array $row) use ($store, $live, $byName, $dry): array {
    $found = $byName($live($table), $name);

    if ($found !== null) {
        return $found;
    }

    echo "  + {$table}: {$name}\n";

    return $dry ? ['id' => '0'] + $row : $store->put($table, $row);
};
$columnsOf = static fn (string $t): array => array_flip($store->columnsOf($t));
$only = static fn (array $row, array $columns): array => array_filter(array_intersect_key($row, $columns), static fn ($v): bool => $v !== null && $v !== '');
$deptCols = $columnsOf('departments');
$desCols = $columnsOf('designations');
$brCols = $columnsOf('branches');
$teamCols = $columnsOf('teams');
$empCols = $columnsOf('employees');

$branches = [];

foreach ((array) $cfg['branches'] as $b) {
    $branches[(string) $b['code']] = $ensure('branches', (string) $b['name'], $only(['name' => $b['name'], 'code' => $b['code'], 'city' => $b['city'], 'state' => $b['state'] ?? '', 'employer_company_id' => $companyId, 'is_active' => 1], $brCols)) + ['executives' => (int) $b['executives']];
}

$departments = [];

foreach ((array) $cfg['departments'] as $code => $name) {
    $departments[$code] = $ensure('departments', (string) $name, $only(['name' => $name, 'code' => $code, 'employer_company_id' => $companyId, 'is_active' => 1], $deptCols));
}

$designation = static function (string $name, string $deptCode) use ($ensure, $departments, $companyId, $only, $desCols): array {
    return $ensure('designations', $name, $only(['name' => $name, 'department_id' => $departments[$deptCode]['id'] ?? null, 'employer_company_id' => $companyId, 'is_active' => 1], $desCols));
};
$titles = [
    'leader' => $designation((string) $cfg['designations']['leader'], 'CO'),
    'manager' => $designation((string) $cfg['designations']['manager'], 'CO'),
    'admin' => $designation((string) $cfg['designations']['admin'], 'ADMIN'),
    'hr' => $designation((string) $cfg['designations']['hr'], 'HR'),
];
$executiveTitles = [];

foreach ((array) $cfg['designations']['executive'] as $i => $name) {
    $executiveTitles[] = $designation((string) $name, $i === 0 ? 'CO' : 'RO');
}

// ------------------------------------------------------------------ the people
mt_srand(20260909);
$pick = static fn (array $rows): mixed => $rows === [] ? null : $rows[mt_rand(0, count($rows) - 1)];
$between = static fn (int $lo, int $hi): int => mt_rand($lo, $hi);
// Names a Hyderabad collections floor would actually have — first names and
// surnames drawn from the same community, so nobody is "Sunitha Khan".
$communities = [
    ['male' => ['Ramesh', 'Suresh', 'Naveen', 'Kiran', 'Srinivas', 'Venkatesh', 'Mahesh', 'Rajesh', 'Anil', 'Praveen', 'Vijay', 'Karthik', 'Shiva', 'Harsha', 'Pavan', 'Bharath', 'Sai Kumar', 'Ravi Teja', 'Ganesh', 'Dinesh', 'Prakash', 'Santosh', 'Ashok', 'Chandu', 'Mallikarjun'],
     'female' => ['Swathi', 'Divya', 'Sravani', 'Lakshmi', 'Anusha', 'Mounika', 'Keerthi', 'Bhavani', 'Sunitha', 'Rekha', 'Nagalakshmi', 'Sushma', 'Madhavi', 'Jyothi', 'Harini', 'Vasavi', 'Tejaswini', 'Shruthi', 'Nandini', 'Deepa'],
     'last' => ['Reddy', 'Naidu', 'Goud', 'Yadav', 'Rao', 'Chowdary', 'Varma', 'Prasad', 'Babu', 'Raju', 'Murthy', 'Setty', 'Nayak', 'Kumar'],
     'fathers' => ['Venkata Rao', 'Narayana', 'Krishna Murthy', 'Subba Rao', 'Satyanarayana', 'Ramulu', 'Mohan Reddy', 'Srinivasa Rao', 'Anjaneyulu', 'Yellaiah', 'Rajaiah', 'Mallesh', 'Nageswara Rao'],
     'weight' => 70],
    ['male' => ['Imran', 'Arif', 'Farhan', 'Abdul Rahman', 'Yaseen', 'Sohail', 'Irfan', 'Mohammed Ali', 'Sameer', 'Zubair'],
     'female' => ['Ayesha', 'Fathima', 'Sana', 'Naseem', 'Farheen', 'Rubina'],
     'last' => ['Khan', 'Shaik', 'Syed', 'Pasha', 'Baig'],
     'fathers' => ['Abdul Rahman', 'Mohammed Yousuf', 'Syed Ahmed', 'Abdul Khader', 'Mohammed Ismail'],
     'weight' => 18],
    ['male' => ['Sandeep', 'Rohit', 'Nikhil', 'Manoj', 'Ankit', 'Vikas', 'Rahul', 'Amit'],
     'female' => ['Priyanka', 'Pallavi', 'Neha', 'Pooja', 'Shweta', 'Ritika'],
     'last' => ['Sharma', 'Gupta', 'Patel', 'Verma', 'Agarwal', 'Jain', 'Mishra'],
     'fathers' => ['Ramesh Chand', 'Suresh Kumar', 'Om Prakash', 'Rajendra Prasad', 'Mahendra'],
     'weight' => 12],
];
$banks = [['HDFC Bank', 'HDFC0001234'], ['ICICI Bank', 'ICIC0002345'], ['State Bank of India', 'SBIN0003456'], ['Axis Bank', 'UTIB0004567'], ['Kotak Mahindra Bank', 'KKBK0005678'], ['Union Bank of India', 'UBIN0806789']];
$areas = ['Kondapur', 'Gachibowli', 'Miyapur', 'Kukatpally', 'Madhapur', 'Chandanagar', 'Lingampally', 'Nizampet', 'Hafeezpet', 'Manikonda', 'Tolichowki', 'Mehdipatnam', 'Ameerpet', 'Serilingampally'];
$usedNames = [];
$usedPan = [];
$name = static function () use (&$usedNames, $pick, $communities): array {
    do {
        $r = mt_rand(1, 100);
        $acc = 0;
        $c = $communities[0];

        foreach ($communities as $candidate) {
            $acc += (int) $candidate['weight'];

            if ($r <= $acc) {
                $c = $candidate;

                break;
            }
        }

        $gender = mt_rand(1, 100) <= 62 ? 'male' : 'female';
        $first = $pick($c[$gender]);
        $last = $pick($c['last']);
        $full = $first . ' ' . $last;
    } while (isset($usedNames[$full]));

    $usedNames[$full] = true;

    return [$full, $first, $last, $gender, $pick($c['fathers'])];
};
// PAN: five letters (the fourth is P for a person, the fifth the surname's initial), four digits, a letter.
$pan = static function (string $last) use (&$usedPan): string {
    do {
        $p = chr(mt_rand(65, 90)) . chr(mt_rand(65, 90)) . chr(mt_rand(65, 90)) . 'P' . strtoupper(substr($last, 0, 1)) . str_pad((string) mt_rand(1000, 9999), 4, '0', STR_PAD_LEFT) . chr(mt_rand(65, 90));
    } while (isset($usedPan[$p]));

    $usedPan[$p] = true;

    return $p;
};
$aadhaar = static fn (): string => (string) mt_rand(2, 9) . str_pad((string) mt_rand(0, 99999), 5, '0', STR_PAD_LEFT) . str_pad((string) mt_rand(0, 99999), 5, '0', STR_PAD_LEFT) . mt_rand(0, 9);
$mobile = static fn (): string => (string) mt_rand(6, 9) . str_pad((string) mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT);
$lpa = static fn (string $lo, string $hi): int => mt_rand((int) round((float) $lo * 100), (int) round((float) $hi * 100)); // hundredths of a lakh — a band, not money
$ctcOf = static function (string $role) use ($cfg, $lpa): string {
    [$lo, $hi] = (array) $cfg['ctc_lpa'][$role];
    $hundredths = $lpa((string) $lo, (string) $hi);
    $hundredths = intdiv($hundredths, 5) * 5;   // 1.85, 2.40, 3.15 … the way an offer letter reads

    return Money::ofMinor($hundredths * 1000 * 100)->toDecimalString(); // ×1,000 rupees per hundredth of a lakh
};
// PF and ESI from the CTC through the config's ceilings, exactly (paise, never a float).
$monthlyMinor = static fn (string $ctc): int => intdiv(Amount::of($ctc)->minor, 12);
$basicMinor = static fn (string $ctc): int => intdiv($monthlyMinor($ctc) * (int) $cfg['basic_percent_of_ctc'], 100);
$pfApplies = static fn (string $ctc): bool => $basicMinor($ctc) <= Amount::of((string) $cfg['pf_monthly_basic_ceiling'])->minor;
$esiApplies = static fn (string $ctc): bool => $monthlyMinor($ctc) <= Amount::of((string) $cfg['esi_monthly_gross_ceiling'])->minor;
$emailDomain = (string) $cfg['email_domain'];
$existing = [];

foreach ($store->all('employees') as $e) {
    $existing[strtoupper((string) ($e['emp_code'] ?? ''))] = $e;
}

$seq = 0;

foreach (array_keys($existing) as $code) {
    preg_match('/^AM-(\d+)$/', $code, $m) && $seq = max($seq, (int) $m[1]);
}

// WHICH executives joined this year: N ordinal positions drawn from the whole roll, both branches.
$joinedInYear = [];
$executiveOffset = 0;
$perBranch = intdiv((int) ($cfg['joined_in_year_count'] ?? 0) + count((array) $cfg['branches']) - 1, max(1, count((array) $cfg['branches'])));

foreach ((array) $cfg['branches'] as $b) {
    $n = min($perBranch, (int) $b['executives']);
    $n > 0 && $joinedInYear = array_merge($joinedInYear, array_map(static fn (int $i): int => $i + $executiveOffset, (array) array_rand(array_fill(1, (int) $b['executives'], true), $n)));
    $executiveOffset += (int) $b['executives'];
}
$made = [];
$makePerson = static function (string $role, array $branch, string $deptCode, array $title, ?array $reportsTo, string $teamFn) use (
    &$seq, &$made, &$existing, $joinedInYear, $name, $pan, $aadhaar, $mobile, $ctcOf, $pfApplies, $esiApplies, $pick, $banks, $areas,
    $companyId, $departments, $emailDomain, $cfg, $from, $store, $dry, $only, $empCols
): array {
    [$full, $first, $last, $gender, $fatherFirst] = $name();
    $code = sprintf('AM-%03d', ++$seq);
    $ctc = $ctcOf($role);
    $age = $role === 'executive' ? mt_rand(22, 34) : ($role === 'leader' ? mt_rand(27, 38) : mt_rand(32, 48));
    $dob = sprintf('%d-%02d-%02d', (int) substr($from, 0, 4) - $age, mt_rand(1, 12), mt_rand(1, 28));
    // Most joined in the last three years; a few executives (picked across both branches) joined inside the seeded year.
    static $executiveNo = 0;

    if ($role === 'executive' && in_array(++$executiveNo, $joinedInYear, true)) {
        $doj = date('Y-m-d', strtotime($from . ' +' . mt_rand(25, 140) . ' days'));
    } else {
        $doj = date('Y-m-d', strtotime($from . ' -' . mt_rand(120, 1100) . ' days'));
    }

    [$bank, $ifsc] = $pick($banks);
    $email = strtolower(preg_replace('/[^a-z]/i', '', $first) . '.' . preg_replace('/[^a-z]/i', '', $last)) . '@' . $emailDomain;
    $pf = $pfApplies($ctc);
    $esi = $esiApplies($ctc);
    $row = [
        'emp_code' => $code, 'name' => $full, 'first_name' => $first, 'last_name' => $last, 'gender' => $gender,
        'father' => $fatherFirst . ' ' . $last,
        'dob' => $dob, 'doj' => $doj,
        'email' => $email, 'personal_email' => $email, 'mobile' => $mobile(), 'whatsapp' => null,
        'employer_company_id' => $companyId,
        'branch_id' => (string) $branch['id'],
        'department_id' => (string) $departments[$deptCode]['id'],
        'designation_id' => (string) $title['id'],
        'reporting_manager_id' => $reportsTo['id'] ?? null,
        'employment_type' => 'Permanent',
        'type' => $role === 'executive' && str_contains(strtolower((string) $title['name']), 'field') ? 'field' : 'office',
        'ctc' => $ctc, 'salary_type' => 'monthly',
        'status' => 'active',
        'pf_applicable' => $pf ? 1 : 0, 'esi_applicable' => $esi ? 1 : 0,
        'uan' => $pf ? '10' . str_pad((string) mt_rand(0, 9999999999), 10, '0', STR_PAD_LEFT) : null,
        'esic_no' => $esi ? '52' . str_pad((string) mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT) : null,
        'pt_state' => (string) $cfg['pt_state'],
        'pan' => $pan($last), 'aadhaar' => $aadhaar(),
        'bank_name' => $bank, 'ifsc' => $ifsc, 'bank_acc' => str_pad((string) mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT) . str_pad((string) mt_rand(0, 99999), 5, '0', STR_PAD_LEFT), 'account_holder' => $full,
        'payment_mode' => 'Bank transfer',
        'marital_status' => $age >= 28 ? $pick(['Married', 'Married', 'Single']) : 'Single',
        'nationality' => 'Indian', 'blood_group' => $pick(['A+', 'B+', 'O+', 'AB+', 'O-', 'A-']),
        'address' => mt_rand(1, 20) . '-' . mt_rand(1, 99) . '/' . mt_rand(1, 9) . ', ' . $pick($areas) . ', Hyderabad, Telangana',
        'emergency_name' => $fatherFirst . ' ' . $last, 'emergency_phone' => $mobile(), 'emergency_relation' => $pick(['Father', 'Mother', 'Spouse', 'Brother']),
        'work_location' => (string) $branch['name'], 'cost_center' => (string) ($branch['code'] ?? ''),
        'notice_period_days' => $role === 'executive' ? 30 : 60,
        'highest_qualification' => $role === 'executive' ? $pick(['B.Com', 'B.A', 'B.Sc', 'Intermediate', 'B.Tech']) : $pick(['MBA', 'B.Com', 'B.Tech', 'M.Com']),
        'gratuity_applicable' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    $row = $only($row, $empCols);

    if (isset($existing[$code])) {
        $stored = $existing[$code];
        echo "  = {$code} {$stored['name']} already there\n";
    } else {
        printf("  + %s %-24s %-20s %-9s %-12s CTC %s  PF %s ESI %s  joined %s\n", $code, $full, (string) $title['name'], (string) $branch['name'], (string) $departments[$deptCode]['name'], $ctc, $pf ? 'yes' : 'no', $esi ? 'yes' : 'no', $doj);
        $stored = $dry ? $row + ['id' => (string) (1000 + $seq)] : $store->put('employees', $row);
    }

    $stored['_role'] = $role;
    $stored['_team'] = $teamFn;
    $stored['_branch'] = (string) $branch['code'];
    $made[] = $stored;

    return $stored;
};

echo "people:\n";
$hr = $makePerson('hr', $branches[array_key_first($branches)], 'HR', $titles['hr'], null, '');
$managers = [];
$leaders = [];
$admins = [];

foreach ($branches as $bcode => $branch) {
    $manager = $makePerson('manager', $branch, 'CO', $titles['manager'], null, '');
    $managers[$bcode] = $manager;
    $admins[$bcode] = $makePerson('admin', $branch, 'ADMIN', $titles['admin'], $manager, '');
    $leaders[$bcode] = [];

    foreach (['CO', 'RO'] as $fn) {
        $leaders[$bcode][$fn] = $makePerson('leader', $branch, $fn, $titles['leader'], $manager, $fn);
    }

    for ($i = 0; $i < (int) $branch['executives']; $i++) {
        $fn = $i % 2 === 0 ? 'CO' : 'RO';
        $makePerson('executive', $branch, $fn, $executiveTitles[$i % count($executiveTitles)], $leaders[$bcode][$fn], $fn);
    }
}

// Teams: one per branch per function, led by its team leader under the branch manager.
echo "teams:\n";
$teamRows = [];

foreach ($branches as $bcode => $branch) {
    foreach ((array) $cfg['teams'] as $fn => $label) {
        $teamName = $branch['name'] . ' ' . $label;
        $teamRows[$bcode . $fn] = $ensure('teams', $teamName, $only(['name' => $teamName, 'function' => $fn, 'employer_company_id' => $companyId,
            'manager_id' => $managers[$bcode]['id'], 'leader_id' => $leaders[$bcode][$fn]['id'], 'status' => 'active'], $teamCols));
    }
}

foreach ($made as $i => $e) {
    if ($e['_team'] !== '' && isset($teamRows[$e['_branch'] . $e['_team']]) && !$dry && (string) ($e['team_id'] ?? '') === '') {
        $store->put('employees', ['id' => $e['id'], 'team_id' => $teamRows[$e['_branch'] . $e['_team']]['id']]);
    }
}

// Departments' heads and the branch's "reporting" line: the HR manager reports to nobody, managers to nobody.
if (!$dry) {
    $store->put('departments', ['id' => $departments['HR']['id'], 'head_employee_id' => $hr['id']]);
}

// ------------------------------------------------------------------ logins
echo "logins (password from config/seed-team.json):\n";
$access = new Authorisation();
$users = new Users($store, $access, PasswordHasher::platformDefault());
$password = (string) $cfg['password'];
$haveLogin = [];

foreach ($store->all('users') as $u) {
    $haveLogin[(string) ($u['employee_id'] ?? '')] = $u;
}

$loginOf = [];

foreach ($made as $e) {
    $role = match ($e['_role']) { 'manager', 'leader' => 'manager', 'admin', 'hr' => 'administrator', default => 'viewer' };

    if (isset($haveLogin[(string) $e['id']])) {
        $loginOf[(string) $e['id']] = $haveLogin[(string) $e['id']];

        continue;
    }

    if ($dry) {
        $loginOf[(string) $e['id']] = ['email' => $e['email']];

        continue;
    }

    $r = $users->create((string) $e['email'], $password, $role, (string) $e['id']);

    if (!$r['ok']) {
        echo "  ! {$e['emp_code']} {$e['email']}: {$r['why']}\n";

        continue;
    }

    $loginOf[(string) $e['id']] = $r['user'] ?? ['email' => $e['email']];
}

printf("  %d login(s) — executives sign in as viewers, managers and team leaders as reporting managers, branch admins and HR as administrators.\n", count($loginOf));

// ------------------------------------------------------------------ leave balances, then leaves
$customisation = new Customisation($store);
$calendar = new Calendar(__DIR__ . '/../config/calendar.json', $customisation);
$policy = new LeavePolicy(__DIR__ . '/../config/leave-policy.json', $customisation);
$leaveRules = new LeaveRules(__DIR__ . '/../config/leave-rules.json');
$chargeRules = new ChargeRules(__DIR__ . '/../config/leave-charge-rules.json');
$leave = new Leave($store, $policy, $leaveRules, $chargeRules, $calendar, null);
$trail = new Trail($store);
$shifts = new Shifts($store);
$grace = $calendar->graceMinutes() ?? 10;
$workingDates = [];

for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
    $date = date('Y-m-d', $d);
    $calendar->isWorkingDay($date) && $workingDates[] = $date;
}

printf("calendar: %d working day(s) between %s and %s per the tenant's Holiday / W.OFF schedule\n", count($workingDates), $from, $to);
$onLeave = [];   // code|date => leave type
$leaveCfg = (array) $cfg['leave'];
$mix = (array) $leaveCfg['mix_percent'];
$leaveType = static function () use ($mix): string {
    $r = mt_rand(1, 100);
    $acc = 0;

    foreach ($mix as $t => $pct) {
        $acc += (int) $pct;

        if ($r <= $acc) {
            return (string) $t;
        }
    }

    return (string) array_key_first($mix);
};
$approverWeights = (array) $leaveCfg['approver_weights_percent'];
$approverFor = static function (array $e) use ($approverWeights, $managers, $admins, $hr): array {
    $r = mt_rand(1, 100);
    $acc = 0;

    foreach ($approverWeights as $who => $pct) {
        $acc += (int) $pct;

        if ($r <= $acc) {
            return match ($who) { 'hr' => $hr, 'admin' => $admins[$e['_branch']] ?? $hr, default => $managers[$e['_branch']] ?? $hr };
        }
    }

    return $hr;
};
$requested = 0;
$approved = 0;
$refused = 0;
$leaveDays = 0;
echo "leaves (applied as the employee, decided by the branch manager / HR / branch admin):\n";

foreach ($made as $e) {
    $code = (string) $e['emp_code'];
    $doj = (string) ($e['doj'] ?? $from);
    $own = array_values(array_filter($workingDates, static fn (string $d): bool => $d >= $doj && $d >= date('Y-m-d', strtotime($doj . ' +45 days'))));

    if ($own === [] || $dry) {
        continue;
    }

    $leave->openBalances($code, $doj);
    $leave->accrueThrough($code, $doj, $to);
    [$lo, $hi] = (array) $leaveCfg['spells_per_employee'];
    $spells = mt_rand((int) $lo, (int) $hi);
    $taken = [];   // date => true, so spells never overlap or touch
    $usedEl = false;

    for ($s = 0; $s < $spells; $s++) {
        $type = $leaveType();

        if ($type === 'EL' && ($usedEl || $doj > date('Y-m-d', strtotime($from . ' -180 days')))) {
            $type = 'CL';   // EL is earned monthly: only long-tenured people have enough, and one trip a year
        }

        [$dlo, $dhi] = (array) $leaveCfg['spell_days'][$type];
        $days = mt_rand((int) $dlo, (int) $dhi);
        $startIdx = mt_rand(0, count($own) - 1);
        $span = array_slice($own, $startIdx, $days);

        if (count($span) < $days) {
            continue;
        }

        $clash = false;

        foreach ($span as $d) {
            $clash = $clash || isset($taken[$d]) || isset($taken[date('Y-m-d', strtotime($d . ' -1 day'))]) || isset($taken[date('Y-m-d', strtotime($d . ' +1 day'))]);
        }

        if ($clash) {
            continue;
        }

        $reason = (string) $pick((array) $leaveCfg['reasons'][$type]);
        $approver = $approverFor($e);
        $applicant = (string) ($loginOf[(string) $e['id']]['email'] ?? $e['email']);
        $deciderLogin = (string) ($loginOf[(string) $approver['id']]['email'] ?? $approver['email']);
        $decider = (string) $approver['name'];   // the statement reads "Approved by <name>", as the screen writes it

        try {
            $req = $leave->request($code, $type, $span[0], $span[count($span) - 1], $reason);
            $requested++;
            $trail->record('leave.requested', 'leave_request', (string) $req['id'], null,
                ['type' => $type, 'from' => $span[0], 'to' => $span[count($span) - 1], 'days' => (string) count($span)], (string) $e['id'], $applicant, $reason);
            $leave->decide((string) $req['id'], true, $decider, 'Approved');
            $trail->record('leave.decided', 'leave_request', (string) $req['id'], ['state' => Leave::PENDING], ['state' => Leave::APPROVED], (string) $approver['id'], $deciderLogin, 'Approved by ' . (string) $approver['name']);
            $approved++;
            $usedEl = $usedEl || $type === 'EL';

            foreach ($span as $d) {
                $taken[$d] = true;
                $onLeave[$code . '|' . $d] = $type;
                $leaveDays++;
            }
        } catch (\InvalidArgumentException $why) {
            // The leave module refused (balance, rules) — a refusal is data too: leave the day to attendance.
            $refused++;
        }
    }
}

printf("  %d request(s), %d approved, %d refused by the leave rules, %d leave day(s) in all.\n", $requested, $approved, $refused, $leaveDays);

// ------------------------------------------------------------------ attendance
$rates = (array) $cfg['attendance_rates_percent'];
$sources = (array) $cfg['shift_source'];
$have = [];

foreach ($store->all('attendance_logs') as $log) {
    $have[(string) ($log['emp_code'] ?? '') . '|' . substr((string) ($log['punch_at'] ?? $log['log_date'] ?? ''), 0, 10)] = true;
}

$punches = 0;
$days = 0;
$absent = 0;
$late = 0;
$half = 0;
$put = static function (string $code, string $emp, string $at, string $direction, string $source) use ($store, $dry, &$punches): void {
    $punches++;
    $dry || $store->put('attendance_logs', ['emp_code' => $code, 'emp_name' => $emp, 'log_date' => substr($at, 0, 10), 'punch_at' => $at, 'direction' => $direction, 'source' => $source]);
};
$minutes = static fn (string $hm): int => (int) substr($hm, 0, 2) * 60 + (int) substr($hm, 3, 2);
$stamp = static fn (string $date, int $m): string => date('Y-m-d H:i:s', strtotime($date) + $m * 60);
$month = '';

foreach ($workingDates as $date) {
    if (substr($date, 0, 7) !== $month) {
        $month = substr($date, 0, 7);
        echo "  punching {$month} … " . ($punches > 0 ? "({$punches} so far)" : '') . "\n";
        flush();
    }

    foreach ($made as $e) {
        $code = (string) $e['emp_code'];

        if (isset($have[$code . '|' . $date]) || isset($onLeave[$code . '|' . $date]) || $date < (string) ($e['doj'] ?? $date)) {
            continue;
        }

        $r = mt_rand(1, 1000);   // per mille, so a 1% rate is a real 1%

        if ($r <= (int) $rates['absent_unplanned'] * 10) {
            $absent++;

            continue;   // no punch at all — a rare unplanned absence
        }

        [$start, $end] = $shifts->times($e, $calendar->shiftStart(), $calendar->shiftEnd(), $date);
        $s = $minutes($start);
        $t = $minutes($end);
        $t <= $s && $t += 24 * 60;
        $isLate = $r <= ((int) $rates['absent_unplanned'] + (int) $rates['late_past_grace']) * 10;
        $isHalf = !$isLate && $r <= ((int) $rates['absent_unplanned'] + (int) $rates['late_past_grace'] + (int) $rates['early_exit_half_day']) * 10;
        $in = $s + ($isLate ? $grace + mt_rand(1, 35) : mt_rand(-14, $grace - 1));
        $isLate && $late++;
        $isHalf && $half++;
        $lunch = $s + intdiv($t - $s, 2) - 30 + mt_rand(-15, 15);
        $back = $lunch + mt_rand(30, 50);
        // A present day works the whole shift: lunch and a late start are made up at the end.
        $out = $isHalf ? $s + intdiv($t - $s, 2) + mt_rand(0, 30) : $t + ($back - $lunch) + max(0, $in - $s) + mt_rand(0, 25);
        $source = (string) ($sources[(string) ($e['type'] ?? 'office')] ?? 'biometric');
        $emp = (string) $e['name'];
        $put($code, $emp, $stamp($date, $in), 'in', $source);

        if (!$isHalf) {
            $put($code, $emp, $stamp($date, $lunch), 'out', $source);
            $put($code, $emp, $stamp($date, $back), 'in', $source);
        }

        $put($code, $emp, $stamp($date, $out), 'out', $source);
        $days++;
    }
}

printf("\n%s%d people, %d working day(s) %s → %s; %d employee-day(s) punched, %d on approved leave, %d unplanned absence(s), %d late arrival(s), %d half day(s), %d punch(es). No payslip was generated.\n",
    $dry ? '[dry run] ' : '', count($made), count($workingDates), $from, $to, $days, $leaveDays, $absent, $late, $half, $punches);
