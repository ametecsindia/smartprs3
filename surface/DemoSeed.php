<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;

/**
 * EXPLORE DEMO — FILL ONE VISITOR'S OWN DEMO COMPANY (owner, 12 Nov 2026).
 *
 * Runs inside the application (surface/actions/demo-setup.php), signed in as the demo company's administrator, so every
 * row goes through the same classes a real company uses: Users::create for logins, Leave::request/decide for leaves,
 * Register::close/approve for payroll. Shape and numbers are config/demo-seed.json:
 *   one company · two branches · per branch: Branch Admin → Operations Manager → Team Leads → Agents;
 *   QR punches (DemoGate set the company to QR, phones approved automatically) · leaves · punches from the first day of the month `months` back
 *   until yesterday · payroll for those complete months (older approved, latest awaiting approval).
 *
 * ponytail: deterministic per tenant (mt_srand on the tenant id) — two demos look alike but not identical.
 */
final class DemoSeed
{
    public const DONE_KEY = 'demo-seeded';

    /**
     * @param array<string, mixed> $cfg config/demo-seed.json
     * @param callable(): Users $users a Users bound to the access rebuilt AFTER the designations exist (roles come from them)
     * @return array{logins: array<string, string>, people: int, punches: int, leaves: int, payroll: list<string>, problems: list<string>}
     */
    public static function run(RowStore $store, array $cfg, callable $users, Leave $leave, Calendar $calendar, Shifts $shifts,
        Register $register, Customisation $customisation, string $tenantId, string $today, string $by): array
    {
        mt_srand(crc32('demo' . $tenantId));
        $out = ['logins' => [], 'people' => 0, 'punches' => 0, 'leaves' => 0, 'payroll' => [], 'problems' => []];
        // What this run made, so "Convert to client" can remove exactly the sample data and keep the visitor's own (DemoGate::removeSample).
        $sample = ['branches' => [], 'departments' => [], 'designations' => [], 'teams' => [], 'employees' => [], 'codes' => []];
        $cols = static fn (string $t): array => array_flip($store->columnsOf($t));
        $only = static fn (array $row, string $t): array => array_filter(array_intersect_key($row, $cols($t)), static fn ($v): bool => $v !== null && $v !== '');
        $pick = static fn (array $a): mixed => $a[mt_rand(0, count($a) - 1)];
        $companies = $store->all('companies');

        if ($companies === []) {
            throw new \RuntimeException('The demo company has no company row.');
        }

        $company = $companies[0];
        $cid = (string) $company['id'];
        $store->put('companies', $only(['id' => $cid, 'state' => $cfg['state'] ?? '', 'timezone' => $cfg['timezone'] ?? ''], 'companies') + ['id' => $cid]);

        // ------------------------------------------------------------ organisation
        $dept = [];
        foreach ((array) $cfg['departments'] as $code => $name) {
            $dept[$code] = $sample['departments'][] = $store->put('departments', $only(['name' => $name, 'code' => $code, 'employer_company_id' => $cid, 'is_active' => 1], 'departments'));
        }

        $des = [];
        foreach ((array) $cfg['designations'] as $key => $d) {
            $des[$key] = $sample['designations'][] = $store->put('designations', $only(['name' => $d['name'], 'role' => $d['role'], 'department_id' => $dept[$d['dept']]['id'] ?? null,
                'employer_company_id' => $cid, 'is_active' => 1], 'designations'));
        }

        $branches = [];
        foreach ((array) $cfg['branches'] as $b) {
            $branches[] = $store->put('branches', $only(['name' => $b['name'], 'code' => $b['code'], 'city' => $b['city'], 'state' => $b['state'],
                'lat' => $b['lat'], 'lng' => $b['lng'], 'employer_company_id' => $cid, 'is_active' => 1], 'branches')) + ['_cfg' => $b];
        }

        // ------------------------------------------------------------ people
        $months = max(1, (int) ($cfg['months'] ?? 2));
        $from = date('Y-m-01', strtotime(substr($today, 0, 7) . '-01 -' . $months . ' months'));
        $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
        $used = [];
        $seq = 0;
        $domain = 'c' . $tenantId . '.' . (string) $cfg['email_domain'];
        $ctc = static function (string $role) use ($cfg): string {
            // "3.6" lakh → 360 hundredths of a lakh, read as a string (Law 7: no float near money).
            [$lo, $hi] = array_map(static function ($v): int {
                [$int, $frac] = explode('.', (string) $v . '.');

                return (int) $int * 100 + (int) str_pad(substr($frac, 0, 2), 2, '0');
            }, (array) $cfg['ctc_lpa'][$role]);

            return Money::ofMinor(intdiv(mt_rand($lo, $hi), 5) * 5 * 1000 * 100)->toDecimalString();
        };
        $made = [];
        $person = function (string $role, array $branch, ?array $boss, string $teamKey) use (&$seq, &$used, &$made, $cfg, $pick, $ctc, $des, $cid, $from, $domain, $store, $only): array {
            do {
                $gender = mt_rand(1, 100) <= 60 ? 'male' : 'female';
                $first = (string) $pick((array) $cfg['names'][$gender]);
                $last = (string) $pick((array) $cfg['names']['last']);
            } while (isset($used[$first . $last]));

            $used[$first . $last] = true;
            [$bank, $ifsc] = $pick((array) $cfg['banks']);
            $doj = date('Y-m-d', strtotime($from . ' -' . mt_rand(120, 1200) . ' days'));
            $row = $store->put('employees', $only([
                'emp_code' => sprintf('%s-%03d', $cfg['code_prefix'], ++$seq), 'name' => $first . ' ' . $last, 'first_name' => $first, 'last_name' => $last,
                'gender' => $gender, 'dob' => date('Y-m-d', strtotime($from . ' -' . mt_rand(22 * 365, 45 * 365) . ' days')), 'doj' => $doj,
                'email' => strtolower(preg_replace('/[^a-z]/i', '', $first) . '.' . preg_replace('/[^a-z]/i', '', $last)) . '@' . $domain,
                'mobile' => (string) mt_rand(6, 9) . str_pad((string) mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT),
                'employer_company_id' => $cid, 'branch_id' => (string) $branch['id'], 'department_id' => (string) $des[$role]['department_id'],
                'designation_id' => (string) $des[$role]['id'], 'role' => (string) $cfg['designations'][$role]['role'],
                'reporting_manager_id' => $boss['id'] ?? null, 'employment_type' => 'Permanent', 'type' => 'office',
                'ctc' => $ctc($role), 'salary_type' => 'monthly', 'status' => 'active', 'pt_state' => (string) $cfg['pt_state'],
                'bank_name' => $bank, 'ifsc' => $ifsc, 'bank_acc' => (string) mt_rand(100000000, 999999999) . (string) mt_rand(10000, 99999),
                'account_holder' => $first . ' ' . $last, 'payment_mode' => 'Bank transfer', 'nationality' => 'Indian',
                'address' => mt_rand(1, 20) . '-' . mt_rand(1, 99) . ', ' . $pick((array) $cfg['areas']) . ', ' . $branch['_cfg']['city'] . ', ' . $branch['_cfg']['state'],
                'work_location' => (string) $branch['name'], 'created_at' => date('Y-m-d H:i:s'),
            ], 'employees'));
            $made[] = $row + ['_role' => $role, '_boss' => $boss, '_team' => $teamKey];
            $sample['employees'][] = (string) $row['id'];
            $sample['codes'][] = (string) $row['emp_code'];

            return $row;
        };

        foreach ($branches as $bi => $branch) {
            $admin = $person('admin', $branch, null, '');
            $manager = $person('manager', $branch, $admin, '');

            for ($t = 1; $t <= (int) $branch['_cfg']['team_leads']; $t++) {
                $lead = $person('leader', $branch, $manager, '');
                $team = $store->put('teams', $only(['name' => $branch['name'] . ' Team ' . $t, 'employer_company_id' => $cid,
                    'manager_id' => $manager['id'], 'leader_id' => $lead['id'], 'status' => 'active'], 'teams'));
                $sample['teams'][] = (string) $team['id'];
                $store->put('employees', ['id' => $lead['id'], 'team_id' => $team['id']]);
                [$lo, $hi] = (array) $branch['_cfg']['members_per_team'];

                for ($m = mt_rand((int) $lo, (int) $hi); $m > 0; $m--) {
                    $member = $person('member', $branch, $lead, (string) $team['id']);
                    $store->put('employees', ['id' => $member['id'], 'team_id' => $team['id']]);
                }
            }
        }

        $out['people'] = count($made);

        // ------------------------------------------------------------ logins (role = the designation's role)
        $u = $users();
        $loginOf = [];
        foreach ($made as $e) {
            $r = $u->create((string) $e['email'], (string) $cfg['password'], (string) $cfg['designations'][$e['_role']]['role'], (string) $e['id']);
            $r['ok'] ? $loginOf[(string) $e['id']] = (string) $e['email'] : $out['problems'][] = $e['emp_code'] . ': ' . $r['why'];
            if ($r['ok'] && !isset($out['logins'][$e['_role']])) {
                $out['logins'][$e['_role']] = (string) $e['email'];
            }
        }

        // ------------------------------------------------------------ leaves, applied as the employee, decided by their boss
        $working = [];
        for ($d = strtotime($from); $d <= strtotime($yesterday); $d += 86400) {
            $calendar->isWorkingDay(date('Y-m-d', $d)) && $working[] = date('Y-m-d', $d);
        }

        $onLeave = [];
        $lv = (array) $cfg['leave'];
        foreach ($made as $e) {
            $code = (string) $e['emp_code'];
            $leave->openBalances($code, (string) $e['doj']);
            $leave->accrueThrough($code, (string) $e['doj'], $today);
            [$slo, $shi] = (array) $lv['spells_per_employee'];

            for ($s = mt_rand((int) $slo, (int) $shi); $s > 0 && $working !== []; $s--) {
                $r = mt_rand(1, 100);
                $type = (string) array_key_first((array) $lv['mix_percent']);
                $acc = 0;
                foreach ((array) $lv['mix_percent'] as $t => $pct) {
                    if ($r <= ($acc += (int) $pct)) {
                        $type = (string) $t;
                        break;
                    }
                }

                [$dlo, $dhi] = (array) $lv['spell_days'][$type];
                $span = array_slice($working, mt_rand(0, count($working) - 1), mt_rand((int) $dlo, (int) $dhi));
                if (array_filter($span, static fn (string $d): bool => isset($onLeave[$code . '|' . $d])) !== []) {
                    continue;
                }

                try {
                    $req = $leave->request($code, $type, $span[0], $span[count($span) - 1], (string) $pick((array) $lv['reasons'][$type]));
                    $leave->decide((string) $req['id'], true, (string) ($e['_boss']['name'] ?? $by), 'Approved');
                    $out['leaves']++;
                    foreach ($span as $d) {
                        $onLeave[$code . '|' . $d] = true;
                    }
                } catch (\InvalidArgumentException $why) {
                    $out['problems'][] = $code . ' leave not taken: ' . $why->getMessage(); // the leave rules said no — the day stays a working day
                }
            }
        }

        // ------------------------------------------------------------ QR punches, on each working day of each person's own shift
        $rates = (array) $cfg['attendance_rates_percent'];
        $grace = $calendar->graceMinutes() ?? 10;
        $min = static fn (string $hm): int => (int) substr($hm, 0, 2) * 60 + (int) substr($hm, 3, 2);
        foreach ($working as $date) {
            foreach ($made as $e) {
                $code = (string) $e['emp_code'];
                $r = mt_rand(1, 1000);
                if (isset($onLeave[$code . '|' . $date]) || $r <= (int) $rates['absent_unplanned'] * 10) {
                    continue;
                }

                [$start, $end] = $shifts->times($e, $calendar->shiftStart(), $calendar->shiftEnd(), $date);
                $s = $min($start);
                $t = $min($end);
                $t <= $s && $t += 1440;
                $late = $r <= ((int) $rates['absent_unplanned'] + (int) $rates['late_past_grace']) * 10;
                $half = !$late && $r <= ((int) $rates['absent_unplanned'] + (int) $rates['late_past_grace'] + (int) $rates['early_exit_half_day']) * 10;
                $in = $s + ($late ? $grace + mt_rand(1, 35) : mt_rand(-14, max(0, $grace - 1)));
                $out_ = $half ? $s + intdiv($t - $s, 2) + mt_rand(0, 30) : $t + max(0, $in - $s) + mt_rand(0, 25);
                foreach ([[$in, 'in'], [$out_, 'out']] as [$at, $dir]) {
                    $stamp = date('Y-m-d H:i:s', strtotime($date) + $at * 60);
                    $store->put('attendance_logs', ['emp_code' => $code, 'emp_name' => (string) $e['name'], 'log_date' => substr($stamp, 0, 10),
                        'punch_at' => $stamp, 'direction' => $dir, 'source' => QrAttendance::SOURCE]);
                    $out['punches']++;
                }
            }
        }

        // ------------------------------------------------------------ payroll: the complete months, older ones approved
        $signers = array_values(array_unique(array_merge([$by], array_values($loginOf))));
        for ($m = $months; $m >= 1; $m--) {
            $period = date('Y-m', strtotime(substr($today, 0, 7) . '-01 -' . $m . ' months'));
            $closed = $register->close($period, $calendar->shiftStart(), $calendar->shiftEnd(), $by);
            if (!$closed['ok']) {
                $out['problems'][] = 'Payroll ' . $period . ': ' . $closed['why'];
                continue;
            }

            $out['payroll'][] = $period;
            for ($i = 1; $m > 1 && $i < count($signers) && !isset(($register->find($period) ?? [])['approved_by']); $i++) {
                $a = $register->approve($period, $signers[$i], 'Explore Demo', true);
                $a['ok'] || $out['problems'][] = 'Payroll ' . $period . ' approval: ' . $a['why'];
                if (!$a['ok']) {
                    break;
                }
            }
        }

        foreach (['departments', 'designations'] as $t) {
            $sample[$t] = array_map(static fn (array $r): string => (string) $r['id'], $sample[$t]);
        }
        $sample['branches'] = array_map(static fn (array $b): string => (string) $b['id'], $branches);
        $sample['payroll'] = $out['payroll'];
        $customisation->saveRaw(self::DONE_KEY, ['at' => date('c'), 'logins' => $out['logins'], 'sample' => $sample], $by, $by, 'Explore Demo company built');

        return $out;
    }
}
