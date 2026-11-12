<?php
/**
 * SHARED BY THE APPRAISAL RATING SAMPLE, IMPORT AND EXPORT (8 Oct, owner):
 * Performance Appraisal → per criteria box a rating sample; Increment /
 * Appraisal → import it (ratings finalised, CTC raised, increment logged) and
 * export previous CTC, rating, % per rating, amount increased and CTC after.
 * Included by surface/actions/appraisal-*.php and the Increment / Appraisal
 * panel; needs app.php's globals ($store, …) and its org helpers.
 */
declare(strict_types=1);

use SmartPRS\Surface\Amount;
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Increments;
use SmartPRS\Surface\PerformanceAppraisal;

if (!function_exists('appraisalOrgValues')) {
    /** What PerformanceAppraisal::boxFor() matches an employee on. */
    function appraisalOrgValues(SmartPRS\Surface\RowStore $store, array $e): array
    {
        return ['role' => orgValueOf($store, $e, 'role'), 'employee' => orgValueOf($store, $e, 'employee')] + orgEnrich($store, $e);
    }

    /** The marker every appraisal increment carries in its remarks — Apply's and the import's. */
    function appraisalMarker(string $fy, string $period): string
    {
        return "[PA:{$fy}:{$period}]";
    }

    /**
     * Every criteria box on screen, with the review periods it can be rated for.
     *
     * @param list<string> $fys @param list<string> $periods
     * @return list<array{fy: string, box: array<string, mixed>, label: string, periods: list<string>}>
     */
    function appraisalChoices(PerformanceAppraisal $appraisal, array $fys, array $periods, int $fyStart): array
    {
        $out = [];

        foreach ($fys as $fy) {
            $covers = array_values(array_filter($periods, static fn (string $p): bool => PerformanceAppraisal::keyCoversPeriod($fy, $p, $fyStart)));

            foreach ($appraisal->boxesFor($fy) as $box) {
                $scope = array_filter((array) ($box['scope'] ?? []), static fn ($v): bool => trim((string) $v) !== '');
                $who = $scope === [] ? 'All employees' : implode(' · ', array_map(static fn (string $k, $v): string => ucfirst($k) . ': ' . $v, array_keys($scope), $scope));
                $out[] = ['fy' => $fy, 'box' => $box, 'periods' => $covers,
                    'label' => PerformanceAppraisal::label($fy) . ' — ' . $who . ' — ' . PerformanceAppraisal::describeScale($box)];
            }
        }

        return $out;
    }

    /**
     * The employees this box rates: in reach, and this is their MOST SPECIFIC
     * box for the key (the one Apply and the Performance screen would use).
     *
     * @param list<array<string, mixed>> $people
     * @return list<array<string, mixed>>
     */
    function appraisalCovered(SmartPRS\Surface\RowStore $store, PerformanceAppraisal $appraisal, string $fy, string $boxId, array $people): array
    {
        return array_values(array_filter($people, static function (array $e) use ($store, $appraisal, $fy, $boxId): bool {
            $b = $appraisal->boxFor($fy, appraisalOrgValues($store, $e));

            return $b !== null && (string) $b['id'] === $boxId && trim((string) ($e['exit_date'] ?? '')) === '';
        }));
    }

    /** Old CTC, rating and % of an appraisal increment — from its columns, else from the remarks older rows carry. */
    function appraisalIncrementFacts(array $inc): array
    {
        $remarks = (string) ($inc['remarks'] ?? '');
        $rating = trim((string) ($inc['rating'] ?? ''));
        $pct = trim((string) ($inc['pct'] ?? ''));
        $old = trim((string) ($inc['old_ctc'] ?? ''));
        $rating === '' && preg_match('/\] rating (\S+)/', $remarks, $m) === 1 && $rating = $m[1];
        $pct === '' && preg_match('/\+([\d.]+)%/', $remarks, $m) === 1 && $pct = $m[1];
        $old === '' && preg_match('/\(([^→(]+) →/u', $remarks, $m) === 1 && $old = (string) preg_replace('/[^\d.]/', '', $m[1]);
        $new = trim((string) ($inc['new_ctc'] ?? ''));
        $amount = ($old !== '' && $new !== '' && is_numeric($old) && is_numeric($new))
            ? Amount::of($new)->minus(Amount::of($old))->toDecimalString() : '';

        return ['rating' => $rating, 'pct' => $pct, 'old' => $old === '' ? '' : Amount::of($old)->toDecimalString(),
            'new' => $new === '' ? '' : Amount::of($new)->toDecimalString(), 'amount' => $amount];
    }

    /**
     * THE APPRAISAL RESULT TABLE — what the screen lists and the export writes.
     * $fy / $period blank = every appraisal increment in reach.
     *
     * @param list<array<string, mixed>> $people employees in reach (after filters)
     * @return list<array<string, string>>
     */
    function appraisalResults(SmartPRS\Surface\RowStore $store, PerformanceAppraisal $appraisal, array $people, string $fy, string $period): array
    {
        $byId = [];

        foreach ($people as $e) {
            $byId[(string) $e['id']] = $e;
        }

        $lookups = attendanceLookups($store, employmentLabels($store));
        $needle = $fy !== '' && $period !== '' ? appraisalMarker($fy, $period) : ($fy !== '' ? "[PA:{$fy}:" : '[PA:');
        $rows = [];

        foreach ($store->all(Increments::TABLE) as $inc) {
            $remarks = (string) ($inc['remarks'] ?? '');
            $e = $byId[(string) ($inc['employee_id'] ?? '')] ?? null;

            if ($e === null || (string) ($inc['status'] ?? '') !== 'Effected' || !str_contains($remarks, $needle) || preg_match('/\[PA:([^:\]]+):([^\]]+)\]/', $remarks, $m) !== 1) {
                continue;
            }

            $f = appraisalIncrementFacts($inc);
            $box = trim((string) ($inc['appraisal_box_id'] ?? '')) !== '' ? $appraisal->box($m[1], (string) $inc['appraisal_box_id'])
                : $appraisal->boxFor($m[1], appraisalOrgValues($store, $e));
            $slab = $box === null || $f['rating'] === '' ? null : PerformanceAppraisal::slabForValue($box, $f['rating']);
            $org = employeeOrgCells($store, $e, $lookups);
            $rows[] = ['emp_code' => Employment::code($e), 'name' => (string) ($e['name'] ?? ''), 'company' => $org['company'],
                'branch' => $org['branch'], 'department' => $org['department'], 'designation' => $org['designation'],
                'criteria' => PerformanceAppraisal::label($m[1]), 'period' => $m[2], 'rating' => $f['rating'],
                'description' => (string) ($slab['description'] ?? ''), 'old' => $f['old'], 'pct' => $f['pct'],
                'amount' => $f['amount'], 'new' => $f['new'], 'effective' => substr((string) ($inc['effective'] ?? ''), 0, 10),
                'status' => (string) ($inc['status'] ?? '')];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['criteria'], $a['period'], $a['emp_code']] <=> [$b['criteria'], $b['period'], $b['emp_code']]);

        return $rows;
    }

    /** The day after a key's date range — the default effective date of its increments. */
    function appraisalDefaultEffective(string $fy, int $fyStart): string
    {
        $r = PerformanceAppraisal::range($fy, $fyStart);

        return $r === null ? date('Y-m-d') : date('Y-m-d', (int) strtotime($r['to'] . ' +1 day'));
    }
}
