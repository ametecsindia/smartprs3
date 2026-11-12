<?php declare(strict_types=1);
/**
 * SAMPLE OFF-ROLL AGENTS (owner, 23 Oct 2026: "add 20 off-role agents with the realistic scenario of certificate
 * validity, expiry, etc."). Needs $store, $op (OffrollPay), $calendar, $customisation and $seedBy (who is
 * recorded as the approver). Writes, relative to TODAY so it reads right whenever it is run:
 *
 *   3 vendors (one agreement ending within the month)
 *   20 agents across the collections company's branches, each with a Directory supervisor, a client bank and
 *     portfolio, PAN (two without), bank details; 12 paid directly, 8 through vendors. Certificates:
 *     11 fully valid · 3 DRA expiring within 30 days · 1 DRA expired before this month · 1 DRA expiring in the
 *     middle of this month · 1 new joiner whose DRA is not on file yet · 2 PCC expiring · 1 PCC expired ·
 *     1 agent exited last month · 1 suspended
 *   bank authorisation letters for 16 agents (2 expiring, 2 expired)
 *   a pay structure per agent (five realistic schemes; one revised mid-month)
 *   daily activity for two months ago, last month and this month to yesterday (Sundays week off, some leave,
 *     absences and half days); older months approved, this month approved to the 15th and pending after,
 *     two rejected; a few days from the mobile app with GPS and visit logs
 *   ledger entries (festival bonus, a penalty, an advance recovery)
 *   payouts: two months ago approved and paid; last month approved, direct agents paid, vendors pending,
 *     the expired-DRA agent held by the rule
 *
 * Every sample row is recognisable: agent codes OFR-0001…OFR-0020, created_by "sample-data".
 * Returns $seedOut (lines of what was done).
 */
use SmartPRS\Surface\OffrollPay;

$seedOut = [];
$day = static fn (string $base, int $n): string => date('Y-m-d', strtotime($base . ' ' . ($n >= 0 ? '+' : '') . $n . ' days'));
$today = date('Y-m-d');
$yesterday = $day($today, -1);
$m0 = date('Y-m');                                       // this month
$m1 = date('Y-m', strtotime('first day of last month'));  // last month
$m2 = date('Y-m', strtotime('first day of -2 months'));   // two months ago
$by = 'sample-data';
mt_srand(20261023);

// THE COMPANY: the collections one if there is one, else the first.
$companies = array_values(array_filter(OffrollPay::rows($store, 'companies'), static fn (array $c): bool => !\SmartPRS\Surface\Modules::isRetired($c)));
usort($companies, static fn (array $a, array $b): int => (int) (stripos((string) $b['name'], 'recover') !== false) <=> (int) (stripos((string) $a['name'], 'recover') !== false) ?: (int) $a['id'] <=> (int) $b['id']);
$co = $companies[0] ?? null;

if ($co === null) {
    $seedOut[] = 'No company in Organization Setup — nothing seeded.';
    return;
}

$branches = array_values(array_filter(OffrollPay::rows($store, 'branches'), static fn (array $b): bool => !\SmartPRS\Surface\Modules::isRetired($b) && (string) ($b['employer_company_id'] ?? '') === (string) $co['id']));
$branches === [] && $branches = array_values(array_filter(OffrollPay::rows($store, 'branches'), static fn (array $b): bool => !\SmartPRS\Surface\Modules::isRetired($b)));
$emps = array_values(array_filter(OffrollPay::rows($store, 'employees'), static fn (array $e): bool => trim((string) ($e['deleted_at'] ?? '')) === '' && (string) ($e['employer_company_id'] ?? '') === (string) $co['id']
    && trim((string) ($e['exit_date'] ?? $e['date_of_exit'] ?? '')) === ''));
$emps === [] && $emps = OffrollPay::rows($store, 'employees');
// SUPERVISORS: team leads / managers first, by branch where possible.
$score = static fn (array $e): int => preg_match('/team\s*lead|supervis|manager|lead/i', (string) ($e['designation'] ?? '') . ' ' . (string) ($e['role'] ?? '')) === 1 ? 0 : 1;
usort($emps, static fn (array $a, array $b): int => $score($a) <=> $score($b) ?: (int) $a['id'] <=> (int) $b['id']);
$supFor = static function (string $branchId) use ($emps): string {
    foreach ($emps as $e) {
        if ((string) ($e['branch_id'] ?? '') === $branchId) {
            return (string) $e['id'];
        }
    }

    return (string) ($emps[0]['id'] ?? '');
};
$bankNames = array_values(array_filter(array_map(static fn (array $b): string => trim((string) ($b['name'] ?? '')), OffrollPay::rows($store, 'banks'))));
$bankNames === [] && $bankNames = ['HDFC Bank', 'ICICI Bank', 'Bajaj Finance', 'Axis Bank', 'IDFC First Bank'];
$portfolios = ['Credit cards — bucket 1', 'Two-wheeler loans — bucket 2', 'Personal loans — bucket 1', 'Consumer durable loans', 'Credit cards — bucket 3 (60+ DPD)'];

// ---------------------------------------------------------------- vendors
$vendors = [];

foreach ([
    ['name' => 'Sri Sai Field Services', 'contact_person' => 'K. Narsimha', 'mobile' => '9848012345', 'email' => 'accounts@srisaifield.example', 'pan' => 'AAJFS4821K', 'gstin' => '36AAJFS4821K1Z5', 'entity_type' => 'other', 'tds_section' => 'contract', 'agreement_from' => $day($today, -400), 'agreement_to' => $day($today, 330)],
    ['name' => 'Mahalakshmi Recovery Associates', 'contact_person' => 'P. Sridevi', 'mobile' => '9701234567', 'email' => 'mra.accounts@example.com', 'pan' => 'AAQFM7310D', 'gstin' => '36AAQFM7310D1Z2', 'entity_type' => 'other', 'tds_section' => 'commission', 'agreement_from' => $day($today, -250), 'agreement_to' => $day($today, 115)],
    ['name' => 'Deccan Collection Agency', 'contact_person' => 'Md. Khaleel', 'mobile' => '9959087654', 'email' => 'deccan.collect@example.com', 'pan' => 'AAHFD2259Q', 'gstin' => '', 'entity_type' => 'other', 'tds_section' => 'contract', 'agreement_from' => $day($today, -345), 'agreement_to' => $day($today, 20)],
] as $v) {
    $vendors[] = $store->put(OffrollPay::VENDORS, array_filter($v, static fn ($x): bool => $x !== '') + ['employer_company_id' => (string) $co['id'], 'bank_name' => 'HDFC Bank',
        'bank_account' => '502000' . mt_rand(10000000, 99999999), 'ifsc' => 'HDFC000' . mt_rand(1000, 9999), 'status' => 'Active']);
}

$seedOut[] = count($vendors) . ' vendors';

// ---------------------------------------------------------------- agents
// [name, gender, paid via vendor index (null = direct), PAN?, DRA expiry offset (days from today, null = not on file),
//  PCC expiry offset, status, joined (days ago), exit (days ago or null), scheme]
$firstOfM0 = $m0 . '-01';
$midM0 = $m0 . '-12';
$people = [
    ['Ramesh Goud', 'Male', null, true, 540, 400, 'Active', 700, null, 'fixed_visit'],
    ['Srinivas Reddy Kotha', 'Male', null, true, 410, 280, 'Active', 650, null, 'fixed_visit'],
    ['Mahesh Yadav', 'Male', 0, true, 12, 300, 'Active', 600, null, 'visit_collection'],
    ['Venkatesh Naik', 'Male', 0, true, 610, 21, 'Active', 520, null, 'visit_collection'],
    ['Praveen Kumar Bandi', 'Male', null, true, (int) ((strtotime($firstOfM0) - strtotime($today)) / 86400) - 23, 250, 'Active', 480, null, 'slabs'],
    ['Naresh Chary', 'Male', 1, true, 365, 190, 'Active', 460, null, 'slabs'],
    ['Suresh Babu Pilli', 'Male', 1, true, 22, 420, 'Active', 430, null, 'per_day'],
    ['Kiran Kumar Mudiraj', 'Male', null, false, 300, 330, 'Active', 400, null, 'per_day'],
    ['Anil Rathod', 'Male', null, true, (int) ((strtotime($midM0) - strtotime($today)) / 86400), 360, 'Active', 380, null, 'fixed_visit'],
    ['Raju Banoth', 'Male', 2, true, 480, 9, 'Active', 360, null, 'visit_collection'],
    ['Shiva Prasad Gajula', 'Male', 2, true, 27, 200, 'Active', 340, null, 'visit_collection'],
    ['Yadagiri Kurma', 'Male', null, true, 390, -53, 'Active', 320, null, 'per_day'],
    ['Swathi Kanukuntla', 'Female', null, true, 700, 520, 'Active', 300, null, 'tele_field'],
    ['Lakshmi Narayana Dasari', 'Male', 1, true, 260, 230, 'Active', 280, null, 'slabs'],
    ['Sandeep Varma Indukuri', 'Male', null, true, 450, 380, 'Active', 260, null, 'revised'],
    ['Imran Pasha', 'Male', 0, false, 330, 300, 'Active', 240, null, 'visit_collection'],
    ['Syed Afroz', 'Male', null, true, 210, 160, 'Suspended', 220, null, 'fixed_visit'],
    ['Bhaskar Rao Tumma', 'Male', null, true, 150, 140, 'Terminated', 400, (int) ((strtotime($today) - strtotime($m1 . '-20')) / 86400), 'per_day'],
    ['Manjula Erra', 'Female', 2, true, 590, 450, 'Active', 200, null, 'tele_field'],
    ['Sai Kiran Thota', 'Male', null, true, null, 170, 'Onboarded', (int) ((strtotime($today) - strtotime($m0 . '-06')) / 86400), null, 'fixed_visit'],
];
$schemes = [
    'fixed_visit' => ['fixed_monthly' => '9000', 'per_visit_rate' => '20', 'note' => 'Fixed + per visit (standard field executive)'],
    'visit_collection' => ['per_visit_rate' => '45', 'collection_percent' => '1', 'per_ptp_rate' => '10', 'note' => 'Per visit + 1% of collection + PTP'],
    'slabs' => ['per_paid_account_rate' => '50', 'collection_slabs' => '[{"upto":"100000","percent":"1.5"},{"upto":"","percent":"2.5"}]', 'monthly_cap' => '45000', 'note' => 'Collection slabs + per account paid, capped'],
    'per_day' => ['per_day_rate' => '550', 'per_ptp_rate' => '15', 'note' => 'Daily wage + PTP incentive'],
    'tele_field' => ['fixed_monthly' => '12000', 'per_paid_account_rate' => '75', 'note' => 'Fixed + per account resolved (skip-trace & field)'],
    'revised' => ['fixed_monthly' => '8000', 'per_visit_rate' => '18', 'note' => 'Fixed + per visit'],
];
$agents = [];

foreach ($people as $i => [$name, $gender, $vix, $hasPan, $draOff, $pccOff, $status, $joinedAgo, $exitAgo, $scheme]) {
    $n = $i + 1;
    $br = $branches === [] ? null : $branches[$i % count($branches)];
    $draExp = $draOff === null ? null : $day($today, $draOff);
    $pccExp = $day($today, $pccOff);
    $row = [
        'agent_code' => sprintf('OFR-%04d', $n), 'name' => $name, 'gender' => $gender, 'mobile' => (string) (9000000000 + mt_rand(100000000, 899999999)),
        'email' => strtolower(str_replace(' ', '.', $name)) . '@agentmail.example', 'dob' => $day($today, -mt_rand(8500, 14000)),
        'pan' => $hasPan ? chr(65 + mt_rand(0, 25)) . chr(65 + mt_rand(0, 25)) . chr(65 + mt_rand(0, 25)) . 'P' . strtoupper($name[0]) . mt_rand(1000, 9999) . chr(65 + mt_rand(0, 25)) : null,
        'aadhaar_last4' => (string) mt_rand(1000, 9999), 'address' => mt_rand(1, 40) . '-' . mt_rand(1, 300) . ', ' . ['Kukatpally', 'Ameerpet', 'LB Nagar', 'Uppal', 'Kondapur', 'Dilsukhnagar', 'Secunderabad', 'Miyapur'][$i % 8] . ', Hyderabad',
        'city' => 'Hyderabad', 'employer_company_id' => (string) $co['id'], 'branch_id' => $br === null ? null : (string) $br['id'],
        'supervisor_employee_id' => $supFor($br === null ? '' : (string) $br['id']) ?: null,
        'client_bank' => $bankNames[$i % count($bankNames)], 'portfolio' => $portfolios[$i % count($portfolios)],
        'joining_date' => $day($today, -$joinedAgo), 'exit_date' => $exitAgo === null ? null : $day($today, -$exitAgo),
        'paid_to' => $vix === null ? 'agent' : 'vendor', 'vendor_id' => $vix === null ? null : (string) $vendors[$vix]['id'], 'vendor' => $vix === null ? null : (string) $vendors[$vix]['name'],
        'entity_type' => 'individual', 'bank_name' => ['State Bank of India', 'HDFC Bank', 'ICICI Bank', 'Union Bank of India', 'Axis Bank'][$i % 5],
        'bank_account' => (string) mt_rand(30000000, 39999999) . (string) mt_rand(100, 999), 'ifsc' => ['SBIN000', 'HDFC000', 'ICIC000', 'UBIN055', 'UTIB000'][$i % 5] . mt_rand(1000, 9999),
        'upi' => $vix === null ? (string) (9000000000 + $n) . '@ybl' : null,
        'dra_cert_no' => $draExp === null ? null : 'IIBF/DRA/' . (2024 + $i % 2) . '/' . mt_rand(100000, 999999), 'dra_issue_date' => $draExp === null ? null : $day($draExp, -1095),
        'dra_expiry' => $draExp, 'dra' => $draExp === null ? 'None' : ($draExp <= $today ? 'Expired' : 'Valid'),
        'pcc_cert_no' => 'TSP/PCC/' . mt_rand(10000, 99999), 'pcc_issue_date' => $day($pccExp, -730), 'pcc_expiry' => $pccExp, 'pcc' => $pccExp <= $today ? 'Expired' : 'Valid',
        'status' => $status,
    ];
    $agents[$n] = $store->put(OffrollPay::AGENTS, array_filter($row, static fn ($v): bool => $v !== null)) + ['_scheme' => $scheme];
}

$seedOut[] = count($agents) . ' agents (OFR-0001 to OFR-' . sprintf('%04d', count($agents)) . ')';

// ---------------------------------------------------------------- bank authorisation letters
$letters = 0;

foreach ($agents as $n => $a) {
    if (in_array($n, [17, 18, 20, 12], true)) {
        continue; // suspended, exited, new joiner (letter applied for), and one never issued
    }

    $to = match ($n) { 3 => $day($today, 18), 10 => $day($today, 26), 5 => $day($today, -15), 15 => $day($today, -40), default => $day($today, 120 + 15 * $n) };
    $store->put('agent_authorizations', ['agent_id' => (string) $a['id'], 'employer_company_id' => (string) $co['id'], 'bank' => (string) $a['client_bank'],
        'portfolio' => (string) $a['portfolio'], 'auth_no' => strtoupper(substr((string) $a['client_bank'], 0, 4)) . '/AUTH/' . date('Y') . '/' . sprintf('%05d', 3100 + $n),
        'valid_from' => $day($to, -365), 'valid_to' => $to, 'status' => $to <= $today ? 'Expired' : (strtotime($to) - strtotime($today) <= 30 * 86400 ? 'Expiring' : 'Valid')]);
    $letters++;
}

$seedOut[] = $letters . ' bank authorisation letters';

// ---------------------------------------------------------------- pay structures
foreach ($agents as $n => $a) {
    $start = max(substr((string) $a['joining_date'], 0, 10), $m2 . '-01');
    $op->savePlan($a, ['effective_from' => $start] + $schemes[$a['_scheme']], $by);

    if ($a['_scheme'] === 'revised') {
        $op->savePlan($a, ['effective_from' => $m0 . '-16', 'fixed_monthly' => '9500', 'per_visit_rate' => '22', 'note' => 'Revised after the quarterly review'], $by);
    }
}

$seedOut[] = 'pay structures for every agent (one revised from ' . \SmartPRS\Surface\Dates::dmy($m0 . '-16') . ')';

// ---------------------------------------------------------------- daily activity
$days = 0;
$dayIds = ['approve' => [], 'pending' => [], 'reject' => []];

foreach ($agents as $n => $a) {
    $from = max(substr((string) $a['joining_date'], 0, 10), $m2 . '-01');
    $to = trim((string) ($a['exit_date'] ?? '')) !== '' ? min(substr((string) $a['exit_date'], 0, 10), $yesterday) : $yesterday;
    $suspendedFrom = $a['status'] === 'Suspended' ? $m0 . '-08' : '9999-12-31';
    $skill = 0.75 + mt_rand(0, 50) / 100; // how productive this agent is

    for ($d = $from; $d <= $to; $d = $day($d, 1)) {
        if ($d >= $suspendedFrom) {
            break;
        }

        if (in_array($n, [1, 13], true) && $d >= $m0 . '-16') {
            continue; // these two use the mobile app from the 16th (below)
        }

        $dow = (int) date('w', strtotime($d));
        $roll = mt_rand(1, 100);
        $status = $dow === 0 ? 'week_off' : ($roll <= 4 ? 'absent' : ($roll <= 8 ? 'leave' : ($roll <= 13 ? 'half_day' : 'present')));

        if ($status === 'week_off' && mt_rand(1, 3) > 1) {
            continue; // Sundays mostly not recorded at all
        }

        $work = in_array($status, ['present', 'half_day'], true);
        $f = $status === 'half_day' ? 0.5 : 1.0;
        $visits = $work ? (int) round(mt_rand(6, 14) * $f * $skill) : 0;
        $ptp = $work ? min($visits, mt_rand(0, 4)) : 0;
        $paid = $work ? min($visits - $ptp, mt_rand(0, 3)) : 0;
        $coll = $paid > 0 ? (string) ($paid * mt_rand(18, 95) * 100) : '';
        $in = $work ? sprintf('%02d:%02d', 9 + intdiv(mt_rand(0, 50), 60), mt_rand(0, 50) % 60) : '';
        $out = $work ? ($status === 'half_day' ? sprintf('13:%02d', mt_rand(30, 59)) : sprintf('%02d:%02d', 17 + mt_rand(0, 2), mt_rand(0, 59))) : '';
        $src = mt_rand(1, 10) <= 7 ? 'upload' : 'manual';
        $res = $op->saveDay($a, ['activity_date' => $d, 'day_status' => $status, 'check_in' => $in, 'check_out' => $out, 'allocated_accounts' => $work ? (string) mt_rand(25, 60) : '',
            'visits' => (string) $visits, 'ptp_count' => (string) $ptp, 'paid_accounts' => (string) $paid, 'amount_collected' => $coll,
            'remarks' => $status === 'leave' ? 'Informed leave' : ($status === 'absent' ? 'Not reachable' : '')], $by, $src);

        if (!$res['ok']) {
            continue;
        }

        $days++;
        $bucket = substr($d, 0, 7) < $m0 || $d <= $m0 . '-15' ? 'approve' : 'pending';
        $bucket === 'approve' && mt_rand(1, 200) === 1 && $bucket = 'reject';
        $dayIds[$bucket][] = (string) $res['row']['id'];
    }
}

$op->decideDays($dayIds['approve'], true, $seedBy, '', true);
$op->decideDays($dayIds['reject'], false, $seedBy, 'Visits not supported by the allocation file', true);
$seedOut[] = $days . ' agent-days of activity (' . count($dayIds['approve']) . ' approved, ' . count($dayIds['pending']) . ' pending, ' . count($dayIds['reject']) . ' rejected)';

// A FEW DAYS FROM THE MOBILE APP (this month, after the 15th), with GPS and each visit.
$appEvents = [];
$outcomes = ['visited', 'ptp', 'paid', 'not_found', 'ptp', 'refused', 'paid', 'shifted'];

foreach ([1, 13] as $n) {
    for ($d = $m0 . '-16'; $d <= $yesterday; $d = $day($d, 1)) {
        if ((int) date('w', strtotime($d)) === 0 || $op->day((string) $agents[$n]['id'], $d) !== null) {
            continue;
        }

        $lat = 17.44 + mt_rand(0, 300) / 10000;
        $lng = 78.38 + mt_rand(0, 300) / 10000;
        $appEvents[] = ['external_id' => "seed-{$n}-{$d}-in", 'type' => 'login', 'agent_code' => (string) $agents[$n]['agent_code'], 'at' => $d . 'T09:' . sprintf('%02d', mt_rand(5, 40)) . ':00+05:30', 'lat' => $lat, 'lng' => $lng];

        for ($v = 0; $v < mt_rand(5, 9); $v++) {
            $o = $outcomes[mt_rand(0, count($outcomes) - 1)];
            $appEvents[] = ['external_id' => "seed-{$n}-{$d}-v{$v}", 'type' => 'visit', 'agent_code' => (string) $agents[$n]['agent_code'],
                'at' => $d . 'T' . sprintf('%02d:%02d', 10 + $v, mt_rand(0, 59)) . ':00+05:30', 'lat' => $lat + $v / 1000, 'lng' => $lng + $v / 1000,
                'outcome' => $o, 'amount' => $o === 'paid' ? (string) (mt_rand(15, 80) * 100) : '', 'account_ref' => 'LN' . mt_rand(1000000, 9999999)];
        }

        $appEvents[] = ['external_id' => "seed-{$n}-{$d}-out", 'type' => 'logout', 'agent_code' => (string) $agents[$n]['agent_code'], 'at' => $d . 'T18:' . sprintf('%02d', mt_rand(5, 50)) . ':00+05:30', 'lat' => $lat, 'lng' => $lng];
    }
}

$appOk = count(array_filter($op->ingestApp($appEvents), static fn (array $r): bool => $r['status'] === 'accepted'));
$seedOut[] = $appOk . ' mobile-app events (check-in / out and visits) for OFR-0001 and OFR-0013';

// ---------------------------------------------------------------- ledger: adjustments, deductions, recoveries
$legacy = new \SmartPRS\Surface\OffrollAgents($store, dirname(__DIR__, 2) . '/config/offroll-agents.json');
$ledger = [
    [2, $m1, '05', 'adjustment', '1500', 'Festival bonus (Dussehra)'], [6, $m1, '05', 'adjustment', '1500', 'Festival bonus (Dussehra)'], [13, $m1, '05', 'adjustment', '1500', 'Festival bonus (Dussehra)'],
    [4, $m1, '18', 'deduction', '500', 'Penalty — PTP broken without follow-up'], [8, $m1, '10', 'recovery', '2000', 'Advance recovery (advance of ₹4,000, 2 of 2)'],
    [8, $m2, '10', 'recovery', '2000', 'Advance recovery (advance of ₹4,000, 1 of 2)'], [1, $m0, '10', 'adjustment', '1000', 'Top performer of the week'],
    [15, $m0, '14', 'deduction', '300', 'Late submission of allocation report'],
];

foreach ($ledger as [$n, $m, $dd, $kind, $amt, $note]) {
    if (!isset($agents[$n])) {
        continue;
    }

    $r = $legacy->record($agents[$n], ['kind' => $kind, 'amount' => $amt, 'date' => min($m . '-' . $dd, $yesterday), 'note' => $note], $by);
    $r['ok'] && $legacy->decide((string) $r['row']['id'], true, $seedBy, 'Approved', true);
}

$seedOut[] = count($ledger) . ' ledger entries (bonus, penalty, advance recovery)';

// ---------------------------------------------------------------- payouts
$wd = static fn (string $f, string $l): string => (string) $calendar->workingDaysBetween($f, $l);
$fy = function_exists('financialYearStart') ? financialYearStart($customisation) : 4;
$window = (int) (\SmartPRS\Surface\FieldReports::config(dirname(__DIR__, 2) . '/config/field-reports.json')['expiry_window_days'] ?? 30);

foreach ([$m2, $m1] as $k => $m) {
    $g = $op->generate((string) $co['id'], $m, $by, $wd, $fy, $window);

    if (!$g['ok']) {
        $seedOut[] = 'Payout ' . $m . ': ' . $g['why'];
        continue;
    }

    $op->approve((string) $g['run']['id'], $seedBy, true);
    $seedOut[] = 'Payout ' . $m . ': ' . $g['why'] . ' Approved.';
    $paidOn = min($day(date('Y-m-d', strtotime($m . '-01 +1 month')), 6), $yesterday);
    $direct = $vend = [];

    foreach ($op->lines((string) $g['run']['id']) as $l) {
        if ((string) $l['payment_status'] !== 'pending') {
            continue;
        }

        (string) $l['paid_to'] === 'vendor' ? $vend[(string) $l['vendor_id']] = (string) $l['id'] : $direct[] = (string) $l['id'];
    }

    $op->markPaid($direct, $paidOn, 'neft', 'UTR' . str_replace('-', '', $paidOn) . 'A' . mt_rand(10000, 99999), $seedBy);

    if ($k === 0) {
        foreach ($vend as $lineId) {
            $op->markPaid([$lineId], $paidOn, 'neft', 'UTR' . str_replace('-', '', $paidOn) . 'V' . mt_rand(10000, 99999), $seedBy);
        }

        // the held line of that month was released and paid once the agent renewed — as a real office would
        foreach ($op->lines((string) $g['run']['id']) as $l) {
            if ((string) $l['payment_status'] === 'on_hold') {
                $op->hold((string) $l['id'], false, 'certificate renewal receipt seen');
                $op->markPaid([(string) $l['id']], $paidOn, 'imps', 'IMPS' . mt_rand(100000000, 999999999), $seedBy);
            }
        }
    }
}

$seedOut[] = 'This month (' . $m0 . ') is left for you to generate on Agent Payouts.';
