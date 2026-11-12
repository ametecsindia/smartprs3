<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * THE POINTS ENGINE — Performance & Rewards → Points Rules / Ledger / Leaderboard.
 *
 * A Points Rule row is "this EVENT is worth these POINTS" (negative deducts).
 * SmartPRS 2 applied them once a month from the leaderboard's "Auto-apply
 * rules": scan the month, count each employee's events, write one ledger row
 * per employee per event. This class does exactly that, with the amounts
 * always read from the rule rows and the event sources from
 * config/points.json — nothing here knows what a late arrival costs.
 *
 * Every auto row explains itself: the note is "Auto: Late arrival × 3 days
 * @ -2 = -6", and the `source_ref` is `auto:YYYY-MM` so a month applied twice
 * is refused rather than double-counted.
 */
final class Points
{
    /** @var array<string, mixed> */
    private readonly array $config;

    public function __construct(private readonly RowStore $store, string $configPath)
    {
        $this->config = (array) json_decode((string) file_get_contents($configPath), true);
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config;
    }

    public static function sourceRef(string $month): string
    {
        return 'auto:' . $month;
    }

    /**
     * The rule that applies to an event: the company's own row first, else a
     * row declared for ALL companies. Rule rows are `point_rules`.
     *
     * @param list<array<string, mixed>> $rules
     * @return array<string, array<string, mixed>> event => rule row
     */
    public static function rulesByEvent(array $rules, string $companyId = ''): array
    {
        $byEvent = [];

        foreach ($rules as $rule) {
            $event = trim((string) ($rule['event'] ?? ''));
            $ruleCompany = trim((string) ($rule['employer_company_id'] ?? ''));

            // A company's rule applies to that company only; asking for ALL gets the ALL rules.
            if ($event === '' || ($ruleCompany !== '' && $ruleCompany !== $companyId)) {
                continue;
            }

            $specific = $ruleCompany !== '';

            // A company-specific rule beats an ALL rule; the first of equals wins.
            if (!isset($byEvent[$event]) || ($specific && trim((string) ($byEvent[$event]['employer_company_id'] ?? '')) === '')) {
                $byEvent[$event] = $rule;
            }
        }

        return $byEvent;
    }

    /**
     * APPLY RULES TO COUNTED EVENTS. Pure: no store, no clock.
     *
     * @param array<string, array<string, mixed>> $rulesByEvent from rulesByEvent()
     * @param list<array{employee_id: string, event: string, count: int, employer_company_id?: string}> $events
     * @return list<array<string, mixed>> ledger rows to write (no id yet)
     */
    public static function apply(array $rulesByEvent, array $events, string $month, string $notePrefix): array
    {
        $rows = [];

        foreach ($events as $ev) {
            $rule = $rulesByEvent[$ev['event']] ?? null;
            $count = (int) $ev['count'];

            if ($rule === null || $count <= 0) {
                continue; // no rule row = the event is worth nothing, by design
            }

            $each = (int) $rule['points'];
            $points = $each * $count;

            if ($points === 0) {
                continue;
            }

            $rows[] = [
                'employee_id' => (string) $ev['employee_id'],
                'employer_company_id' => trim((string) ($ev['employer_company_id'] ?? ($rule['employer_company_id'] ?? ''))) === '' ? null : (string) ($ev['employer_company_id'] ?? $rule['employer_company_id']),
                'event' => (string) $ev['event'],
                'category' => (string) ($rule['category'] ?? ($points < 0 ? 'negative' : 'positive')),
                'points' => $points,
                'date' => $month . '-01',
                'note' => $notePrefix . $ev['event'] . ' × ' . $count . ' @ ' . $each . ' = ' . $points,
                'source_ref' => self::sourceRef($month),
            ];
        }

        return $rows;
    }

    /**
     * COUNT A MONTH'S EVENTS per employee from what the product already knows:
     * the muster totals (late days, days by status), awards dated in the month,
     * test attempts passed in the month. Which source feeds which event is
     * config/points.json.
     *
     * @param array<string, array{late_days: int, by_status: array<string, int>}> $musterTotals employee id => totals
     * @param array<string, array<string, mixed>> $employeesById
     * @param list<array<string, mixed>> $awards
     * @param list<array<string, mixed>> $attempts
     * @return list<array{employee_id: string, event: string, count: int, employer_company_id: string}>
     */
    public function monthEvents(array $musterTotals, array $employeesById, array $awards, array $attempts, string $month): array
    {
        $events = [];
        $perEmployee = [];
        $inMonth = static fn (mixed $date): bool => str_starts_with((string) $date, $month);

        foreach ($awards as $a) {
            if ($inMonth($a['date'] ?? '')) {
                $perEmployee[(string) $a['employee_id']]['awards'] = ($perEmployee[(string) $a['employee_id']]['awards'] ?? 0) + 1;
            }
        }

        foreach ($attempts as $t) {
            if ((string) ($t['status'] ?? '') === Assessments::PASSED && $inMonth($t['attempted_on'] ?? '')) {
                $perEmployee[(string) $t['employee_id']]['passed'] = ($perEmployee[(string) $t['employee_id']]['passed'] ?? 0) + 1;
            }
        }

        foreach ($employeesById as $id => $e) {
            $id = (string) $id;
            $totals = $musterTotals[$id] ?? ['late_days' => 0, 'by_status' => []];
            $late = (int) ($totals['late_days'] ?? 0);
            $byStatus = (array) ($totals['by_status'] ?? []);
            $company = (string) ($e['employer_company_id'] ?? '');

            foreach ((array) ($this->config['events'] ?? []) as $event => $spec) {
                $count = match ((string) ($spec['source'] ?? 'manual')) {
                    'attendance.late_days' => $late,
                    'attendance.status' => (int) ($byStatus[(string) ($spec['status'] ?? '')] ?? 0),
                    'attendance.perfect' => ($musterTotals[$id] ?? null) !== null && $late === 0
                        && (int) ($byStatus[(string) ($spec['absent_status'] ?? '')] ?? 0) === 0 ? 1 : 0,
                    'attendance.streak' => $late === 0 && (int) ($byStatus[(string) ($spec['present_status'] ?? '')] ?? 0) >= (int) ($spec['streak_days'] ?? 0)
                        && (int) ($spec['streak_days'] ?? 0) > 0 ? 1 : 0,
                    'awards' => (int) ($perEmployee[$id]['awards'] ?? 0),
                    'tests.passed' => (int) ($perEmployee[$id]['passed'] ?? 0),
                    default => 0,
                };

                if ($count > 0) {
                    $events[] = ['employee_id' => $id, 'event' => (string) $event, 'count' => $count, 'employer_company_id' => $company];
                }
            }
        }

        return $events;
    }

    /**
     * Has this month been auto-applied already — for anybody, or (with ids)
     * for any of these employees? The scope filter on the leaderboard means a
     * month can be applied branch by branch; an employee is never done twice.
     *
     * @param list<string> $employeeIds
     */
    public function applied(string $month, array $employeeIds = []): bool
    {
        foreach ($this->store->all('points_ledger') as $row) {
            if ((string) ($row['source_ref'] ?? '') === self::sourceRef($month)
                && ($employeeIds === [] || in_array((string) ($row['employee_id'] ?? ''), $employeeIds, true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * THE LEADERBOARD: net points per employee over a period ('' = all time,
     * 'YYYY' or 'YYYY-MM' = rows whose date starts with it), ranked by net with
     * ties sharing a rank (1, 1, 3).
     *
     * @param list<array<string, mixed>> $ledger
     * @return list<array{employee_id: string, positive: int, negative: int, net: int, entries: int, rank: int}>
     */
    public static function leaderboard(array $ledger, string $period = ''): array
    {
        $by = [];

        foreach ($ledger as $row) {
            if ($period !== '' && !str_starts_with((string) ($row['date'] ?? ''), $period)) {
                continue;
            }

            $id = (string) ($row['employee_id'] ?? '');
            $points = (int) ($row['points'] ?? 0);
            $by[$id] ??= ['employee_id' => $id, 'positive' => 0, 'negative' => 0, 'net' => 0, 'entries' => 0, 'rank' => 0];
            $by[$id][$points < 0 ? 'negative' : 'positive'] += $points;
            $by[$id]['net'] += $points;
            $by[$id]['entries']++;
        }

        $rows = array_values($by);
        usort($rows, static fn (array $a, array $b): int => $b['net'] <=> $a['net'] ?: strcmp($a['employee_id'], $b['employee_id']));
        $rank = 0;

        foreach ($rows as $i => $r) {
            if ($i === 0 || $r['net'] !== $rows[$i - 1]['net']) {
                $rank = $i + 1;
            }

            $rows[$i]['rank'] = $rank;
        }

        return $rows;
    }
}
