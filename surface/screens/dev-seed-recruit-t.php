<?php declare(strict_types=1);
// DEV ONLY (12 Oct): sample Recruitment data — requisitions, job posts, portal postings, candidates
// at every stage, built FROM the seeded joiners (Feb → Oct 2026) so each hire points at the real employee.
// Run once as administrator at ?r=dev-seed-recruit-t; it refuses to run twice. Delete after.
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Recruitment;

if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
set_time_limit(0);
$sdMark = '[sample data]';
$sdOut = [];

foreach ($store->all(Recruitment::REQUISITIONS) as $sdR) {
    if (str_contains((string) ($sdR['justification'] ?? ''), $sdMark)) {
        echo '<pre id="sd">Already seeded — nothing done.</pre>';
        return;
    }
}

$sdBy = (string) ($session->user()['email'] ?? 'admin');
$sdAt = static fn (string $date, string $time = '10:00:00'): string => $date . ' ' . $time;
$sdDay = static fn (string $date, int $days): string => date('Y-m-d', strtotime($date . ' ' . ($days >= 0 ? '+' : '') . $days . ' days'));
$sdSources = ['naukri', 'indeed', 'naukri', 'linkedin', 'referral', 'apna', 'naukri', 'walk_in', 'foundit', 'indeed'];
$sdPrev = ['Hinduja Global Solutions', 'Teleperformance', 'Bajaj Finserv', 'HDB Financial', 'Concentrix', 'Startek', 'iEnergizer', 'Vertex', 'Muthoot Finance', 'Fresher'];
$sdCodes = [];
$sdSeq = 0;
$sdCode = static function (string $date) use (&$sdSeq): string { $sdSeq++; return sprintf('REQ-%s-%03d', substr($date, 0, 4), $sdSeq); };
foreach ($store->all(Recruitment::REQUISITIONS) as $sdR) {
    preg_match('/-(\d+)$/', (string) ($sdR['req_code'] ?? ''), $m) === 1 && $sdSeq = max($sdSeq, (int) $m[1]);
}

$sdReq = static function (array $row) use ($store, $sdMark): array {
    return $store->put(Recruitment::REQUISITIONS, $row + ['filled' => 0, 'engagement' => 'on_roll', 'priority' => 'normal',
        'justification' => $sdMark, 'requested_by' => 'hr.manager']);
};
$sdPost = static function (array $req, array $channels, string $created) use ($jobPostings, $jobPostsStore): void {
    $jobPostsStore->put($jobPostings::TABLE, [
        'job_id' => (string) $req['id'], 'title' => (string) $req['title'], 'openings' => (int) $req['openings'], 'engagement' => 'on_roll',
        'description' => "We are hiring a {$req['title']} for our {$req['department']} team at {$req['location']}.\n\nWhat you will do:\n- Handle the day's allocated accounts / tasks to target\n- Keep accurate records in the system every day\n- Follow the company's code of conduct and fair-practice rules\n\nWhat we offer: fixed salary + incentives, PF and ESI, paid leave, training.",
        'skills' => 'Communication, Telugu, Hindi, English, MS Excel', 'experience_min' => '0', 'experience_max' => '3',
        'ctc_min' => $req['ctc_min'], 'ctc_max' => $req['ctc_max'], 'show_salary' => 'yes', 'location' => $req['location'],
        'education' => 'Any graduate / 12th pass', 'notice_period' => 'Immediate to 30 days', 'industry' => 'BPM / Collections',
        'functional_area' => $req['department'], 'contact_email' => 'careers@example.com', 'status' => in_array('live', array_column($channels, 'status'), true) ? 'live' : 'closed',
        'channels' => json_encode($channels, JSON_UNESCAPED_SLASHES), 'created_by' => 'hr.manager', 'updated_by' => 'hr.manager',
        'created_at' => $created . ' 11:00:00', 'updated_at' => $created . ' 11:00:00',
    ]);
};
$sdChannels = static function (string $posted, bool $live, int $i) use ($sdDay): array {
    $out = [];
    foreach (array_slice(['naukri', 'indeed', 'linkedin', 'apna'], 0, 2 + $i % 3) as $k => $p) {
        $out[] = ['portal' => $p, 'ref' => strtoupper(substr($p, 0, 2)) . '-' . (40100 + $i * 7 + $k), 'url' => '', 'posted_on' => $sdDay($posted, $k),
            'expires_on' => $sdDay($posted, 30 + $k), 'status' => $live ? 'live' : 'closed', 'by' => 'hr.manager'];
    }
    return $out;
};
$sdIv = static function (string $cid, int $round, string $when, string $status, ?int $score = null, ?string $rec = null, ?string $fb = null) use ($store): void {
    $store->put(Recruitment::INTERVIEWS, array_filter(['candidate_id' => $cid, 'round' => $round, 'round_label' => $round === 1 ? 'HR round' : 'Operations round',
        'scheduled_at' => $when, 'mode' => $round === 1 ? 'phone' : 'in_person', 'panel' => $round === 1 ? 'HR' : 'Team Lead, Branch Manager',
        'status' => $status, 'score' => $score, 'recommendation' => $rec, 'feedback' => $fb], static fn ($v): bool => $v !== null));
};

// ------------------------------------------------ 1. the joiners → requisitions and hires
$sdJoiners = array_values(array_filter(liveRows($store, 'employees'), static fn (array $e): bool => (string) ($e['doj'] ?? '') >= '2026-02-01'));
usort($sdJoiners, static fn (array $a, array $b): int => strcmp((string) $a['doj'], (string) $b['doj']));
$sdGroups = [];
foreach ($sdJoiners as $e) {
    $k = implode('|', [(string) ($e['employer_company_id'] ?? ''), orgValueOf($store, $e, 'designation'), orgValueOf($store, $e, 'department'), orgValueOf($store, $e, 'branch')]);
    $sdGroups[$k][] = $e;
}

$sdOpenForPipeline = [];
$gi = 0;
foreach ($sdGroups as $k => $people) {
    [$coId, $desig, $dept, $branch] = explode('|', $k);
    $first = (string) $people[0]['doj'];
    $raised = $sdDay($first, -30);
    $spare = $gi % 3 === 0;                       // every third requisition still has an opening
    $ctcs = array_map(static fn (array $e): int => (int) explode('.', (string) ($e['ctc'] ?? '0'))[0], $people);
    $req = $sdReq(['req_code' => $sdCode($raised), 'title' => $desig ?: 'Executive', 'employer_company_id' => $coId === '' ? null : $coId,
        'department' => $dept ?: null, 'location' => $branch ?: null, 'openings' => count($people) + ($spare ? 1 : 0), 'filled' => count($people),
        'ctc_min' => (string) min($ctcs), 'ctc_max' => (string) (max($ctcs) + 30000), 'priority' => $gi % 4 === 1 ? 'urgent' : 'normal',
        'target_date' => $sdDay($first, 5), 'approval_status' => 'approved', 'status' => $spare ? 'open' : 'closed',
        'approved_by' => 'Department Director / Branch Admin: director · HR Admin: hr.manager', 'approved_at' => $sdAt($sdDay($raised, 2)),
        'justification' => "Volumes up at {$branch}; replacement and new seats. {$sdMark}", 'created_at' => $sdAt($raised), 'updated_at' => $sdAt($sdDay($raised, 2))]);
    $sdPost($req, $sdChannels($sdDay($raised, 3), $spare, $gi), $sdDay($raised, 3));
    $spare && $sdOpenForPipeline[] = $req;

    foreach ($people as $pi => $e) {
        $doj = (string) $e['doj'];
        $applied = $sdDay($doj, -24 + min($pi, 8));
        $src = $sdSources[($gi + $pi) % count($sdSources)];
        $ctc = (int) explode('.', (string) ($e['ctc'] ?? '0'))[0];
        $code = Employment::code($e);
        $c = $store->put(Recruitment::CANDIDATES, [
            'name' => (string) $e['name'], 'mobile' => $e['mobile'] ?? null, 'email' => $e['email'] ?? null, 'position' => (string) $req['title'],
            'job_id' => (string) $req['id'], 'pool' => 0, 'source' => $src, 'location' => $branch ?: null,
            'current_company' => $sdPrev[($gi + $pi) % count($sdPrev)], 'current_designation' => $pi % 3 === 0 ? 'Fresher' : 'Executive',
            'current_ctc' => (string) intdiv($ctc * 85, 100), 'expected_ctc' => (string) $ctc, 'experience_years' => (string) (($gi + $pi) % 5),
            'skills' => 'Communication, Telugu, Hindi', 'notes' => 'Notice period: ' . (['Immediate', '15 days', '30 days'][($gi + $pi) % 3]),
            'applied_on' => $applied, 'stage' => 'hired', 'offered_ctc' => (string) $ctc, 'offer_doj' => $doj, 'offer_status' => 'accepted',
            'offer_notes' => "Hired as {$code} by hr.manager (sample data)", 'created_at' => $sdAt($applied, '09:30:00'), 'updated_at' => $sdAt($doj, '09:00:00'),
        ]);
        $sdIv((string) $c['id'], 1, $sdAt($sdDay($applied, 3), '11:00:00'), 'completed', 7 + ($pi % 3), 'hire', 'Clear speech, willing to work in shifts.');
        $sdIv((string) $c['id'], 2, $sdAt($sdDay($applied, 8), '15:00:00'), 'completed', 7 + (($pi + 1) % 3), $pi % 2 === 0 ? 'strong_hire' : 'hire', 'Good on the role-play; joins on ' . $doj . '.');
    }
    $sdOut[] = "{$req['req_code']} {$req['title']} @ {$branch}: " . count($people) . ' hired' . ($spare ? ', 1 still open' : ', closed');
    $gi++;
}

// ------------------------------------------------ 2. new requisitions in October — every approval state
$sdTpl = $sdGroups === [] ? null : reset($sdGroups)[0];
$sdDesig = $sdTpl === null ? 'Tele-caller' : (orgValueOf($store, $sdTpl, 'designation') ?: 'Tele-caller');
$sdDept = $sdTpl === null ? 'Collections' : orgValueOf($store, $sdTpl, 'department');
$sdBranch = $sdTpl === null ? '' : orgValueOf($store, $sdTpl, 'branch');
$sdCo = $sdTpl === null ? null : ((string) ($sdTpl['employer_company_id'] ?? '') ?: null);
$sdBase = ['title' => $sdDesig, 'employer_company_id' => $sdCo, 'department' => $sdDept ?: null, 'location' => $sdBranch ?: null, 'ctc_min' => '216000', 'ctc_max' => '276000'];
$today = date('Y-m-d');
$bulk = $sdReq($sdBase + ['req_code' => $sdCode('2026-09-22'), 'openings' => 6, 'priority' => 'critical', 'target_date' => '2026-11-02',
    'approval_status' => 'approved', 'status' => 'open', 'approved_by' => 'Department Director / Branch Admin: director · HR Admin: hr.manager',
    'approved_at' => $sdAt('2026-09-24'), 'justification' => "New bank portfolio from November: six more seats. {$sdMark}", 'created_at' => $sdAt('2026-09-22')]);
$sdPost($bulk, $sdChannels('2026-09-25', true, 2), '2026-09-25');
$sdOpenForPipeline[] = $bulk;
$sdReq($sdBase + ['req_code' => $sdCode('2026-10-08'), 'openings' => 2, 'approval_status' => 'pending', 'status' => 'open',
    'justification' => "Two resignations this month. {$sdMark}", 'created_at' => $sdAt('2026-10-08')]);
$sdReq($sdBase + ['req_code' => $sdCode('2026-10-05'), 'openings' => 1, 'priority' => 'urgent', 'approval_status' => 'pending_hr', 'status' => 'open',
    'approved_by' => 'Department Director / Branch Admin: director', 'approved_at' => $sdAt('2026-10-06'),
    'justification' => "Team lead for the new portfolio. {$sdMark}", 'created_at' => $sdAt('2026-10-05')]);
$sdReq($sdBase + ['req_code' => $sdCode('2026-09-15'), 'openings' => 3, 'approval_status' => 'rejected', 'status' => 'closed',
    'approved_by' => 'Department Director / Branch Admin: director', 'approved_at' => $sdAt('2026-09-17'),
    'reject_reason' => 'Budget not approved for this quarter; raise again in January.', 'justification' => "Night shift seats. {$sdMark}", 'created_at' => $sdAt('2026-09-15')]);
$sdOut[] = 'October: 1 approved and posted (6 openings), 1 waiting on Director / Branch Admin, 1 waiting on HR Admin, 1 rejected';

// ------------------------------------------------ 3. candidates in progress, every stage
$sdPeople = [
    // name, stage, source, days ago applied, note
    ['Arjun Reddy', 'applied', 'naukri', 2], ['Bhavana Rao', 'applied', 'naukri', 2], ['Chaitanya Varma', 'applied', 'indeed', 1], ['Deepika Nair', 'applied', 'apna', 0],
    ['Farhan Sheikh', 'screening', 'naukri', 6], ['Gayathri Iyer', 'screening', 'linkedin', 5], ['Harish Goud', 'screening', 'referral', 4],
    ['Imran Khan', 'interview', 'naukri', 12], ['Jyothi Lakshmi', 'interview', 'indeed', 11], ['Karthik Menon', 'interview', 'walk_in', 9],
    ['Lavanya Prasad', 'offer', 'naukri', 18], ['Mahesh Babu K', 'offer', 'referral', 16],
    ['Naveen Chandra', 'rejected', 'indeed', 20, 'Not comfortable with field visits.'], ['Om Prakash', 'rejected', 'naukri', 19, 'Offer declined — joined another company.'],
    ['Pooja Sharma', 'rejected', 'apna', 15, 'Did not attend the interview twice (no show).'],
];
$sdNo = 0;
foreach ($sdPeople as $i => $p) {
    [$name, $stage, $src, $ago] = $p;
    $req = $sdOpenForPipeline[$i % max(1, count($sdOpenForPipeline))] ?? $bulk;
    $applied = $sdDay($today, -$ago);
    $first = strtolower(explode(' ', $name)[0]);
    $c = $store->put(Recruitment::CANDIDATES, array_filter([
        'name' => $name, 'mobile' => '+91 90000 ' . sprintf('%05d', 10101 + $i * 37), 'email' => "{$first}.{$i}@example.com", 'position' => (string) $req['title'],
        'job_id' => (string) $req['id'], 'pool' => 0, 'source' => $src, 'location' => $req['location'] ?? null, 'current_company' => $sdPrev[$i % count($sdPrev)],
        'current_designation' => $i % 3 === 0 ? 'Fresher' : 'Tele-caller', 'current_ctc' => (string) (180000 + 6000 * $i), 'expected_ctc' => (string) (228000 + 6000 * $i),
        'experience_years' => (string) ($i % 4), 'skills' => 'Communication, Telugu, Hindi', 'notes' => 'Notice period: ' . (['Immediate', '15 days', '30 days'][$i % 3]),
        'applied_on' => $applied, 'stage' => $stage, 'reject_reason' => $p[4] ?? null,
        'offered_ctc' => $name === 'Lavanya Prasad' ? '240000' : null, 'offer_doj' => $name === 'Lavanya Prasad' ? '2026-11-02' : null,
        'offer_status' => $name === 'Lavanya Prasad' ? 'sent' : null, 'created_at' => $sdAt($applied, '09:30:00'),
    ], static fn ($v): bool => $v !== null));
    $cid = (string) $c['id'];
    if (in_array($stage, ['interview', 'offer'], true) || ($stage === 'rejected' && $i % 2 === 0)) {
        $sdIv($cid, 1, $sdAt($sdDay($applied, 3), '11:00:00'), 'completed', 7, 'hire', 'Good communication.');
    }
    if ($stage === 'interview') {
        $sdIv($cid, 2, $sdAt($sdDay($today, 1 + $i % 3), '15:00:00'), 'scheduled');
    }
    if ($stage === 'offer') {
        $sdIv($cid, 2, $sdAt($sdDay($applied, 7), '15:00:00'), 'completed', 8, 'strong_hire', 'Recommended for the new portfolio.');
    }
    if ($name === 'Pooja Sharma') {
        $sdIv($cid, 1, $sdAt($sdDay($applied, 4), '11:00:00'), 'no_show');
    }
    $sdNo++;
}
// Talent pool: applied with no requisition yet.
foreach ([['Ramya Krishna', 'naukri'], ['Sandeep Kulkarni', 'linkedin'], ['Tanvi Joshi', 'company_website']] as $i => [$name, $src]) {
    $store->put(Recruitment::CANDIDATES, ['name' => $name, 'mobile' => '+91 90000 ' . sprintf('%05d', 20202 + $i * 41), 'email' => strtolower(explode(' ', $name)[0]) . ".pool{$i}@example.com",
        'pool' => 1, 'source' => $src, 'location' => $sdBranch ?: null, 'current_company' => $sdPrev[($i + 3) % count($sdPrev)], 'experience_years' => (string) (2 + $i),
        'current_ctc' => (string) (240000 + 12000 * $i), 'expected_ctc' => (string) (300000 + 12000 * $i), 'skills' => 'Team handling, Excel, Collections',
        'applied_on' => $sdDay($today, -10 - $i), 'stage' => 'applied', 'created_at' => $sdAt($sdDay($today, -10 - $i), '09:30:00')]);
    $sdNo++;
}
$sdOut[] = count($sdJoiners) . " hired candidates linked to their employees; {$sdNo} in progress / pool";
$audit('recruitment.sample_data', 'recruitment', null, null, ['by' => $sdBy, 'summary' => $sdOut], 'Recruitment sample data');
?><pre id="sd"><?= e(implode("\n", $sdOut)) ?></pre>
