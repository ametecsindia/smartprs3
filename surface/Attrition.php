<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Rational;

/**
 * ATTRITION — Performance & Rewards → Attrition.
 *
 * Joiners and leavers per month from the employee record: date of joining,
 * and the exit date (the `exit_date` column, else the timeline's exit event,
 * handed in by the caller). Headcount on a day = joined on or before it and
 * not exited on or before it, so closing = opening + joiners − leavers holds
 * by construction. Rate = leavers ÷ average headcount, average = (opening +
 * closing) ÷ 2, kept as an exact Rational; the screen shows the fraction and
 * a truncated percentage with the working beside it.
 */
final class Attrition
{
    /** @return list<string> 'YYYY-MM', oldest first, ending with $toMonth */
    public static function months(string $toMonth, int $count): array
    {
        $out = [];
        $y = (int) substr($toMonth, 0, 4);
        $m = (int) substr($toMonth, 5, 2);

        for ($i = 0; $i < max(1, $count); $i++) {
            array_unshift($out, sprintf('%04d-%02d', $y, $m));
            $m--;

            if ($m === 0) {
                $m = 12;
                $y--;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $employees the rows in view (scope is the caller's job)
     * @param array<string, string> $exitedOn employee id => exit date, for those the record does not carry
     * @param list<string> $months from months()
     * @param array<string, mixed> $cfg config/attrition.json
     * @return array{rows: list<array{month: string, opening: int, joiners: int, leavers: int, closing: int, average: Rational, rate: ?Rational, working: string}>,
     *     unplaced: list<string>, joiners: array<string, list<string>>, leavers: array<string, list<string>>}
     */
    public static function table(array $employees, array $exitedOn, array $months, array $cfg): array
    {
        $people = [];
        $unplaced = [];
        $percentPlaces = (int) ($cfg['percent_places'] ?? 2);
        $leftStatuses = array_map('strval', (array) ($cfg['left_statuses'] ?? []));

        foreach ($employees as $e) {
            $id = (string) ($e['id'] ?? '');
            $doj = substr(trim(Employment::joinedOn($e)), 0, 10);
            $exit = substr(trim((string) ($e['exit_date'] ?? '')), 0, 10);

            if ($exit === '') {
                $exit = substr(trim($exitedOn[$id] ?? ''), 0, 10);
            }

            if ($doj === '') {
                $unplaced[] = Employment::codeOrSay($e) . ' (no date of joining)';

                continue;
            }

            if ($exit === '' && in_array(strtolower((string) ($e['status'] ?? '')), $leftStatuses, true)) {
                $unplaced[] = Employment::codeOrSay($e) . ' (left, date unknown)';
            }

            $people[] = ['code' => Employment::codeOrSay($e), 'doj' => $doj, 'exit' => $exit];
        }

        $headcountOn = static fn (string $day): int => count(array_filter($people,
            static fn (array $p): bool => $p['doj'] <= $day && ($p['exit'] === '' || $p['exit'] > $day)));
        $rows = [];
        $joinersBy = [];
        $leaversBy = [];

        foreach ($months as $month) {
            $first = $month . '-01';
            $last = date('Y-m-t', (int) strtotime($first));
            $before = date('Y-m-d', (int) strtotime($first . ' -1 day'));
            $opening = $headcountOn($before);
            $closing = $headcountOn($last);
            $joiners = [];
            $leavers = [];

            foreach ($people as $p) {
                if (str_starts_with($p['doj'], $month)) {
                    $joiners[] = $p['code'];
                }

                if ($p['exit'] !== '' && str_starts_with($p['exit'], $month)) {
                    $leavers[] = $p['code'];
                }
            }

            $average = Rational::of($opening + $closing, 2);
            $rate = $opening + $closing > 0 ? Rational::of(2 * count($leavers), $opening + $closing) : null;
            $working = 'average headcount = (' . $opening . ' + ' . $closing . ') ÷ 2 = ' . $average->toDecimalString(1)
                . '; rate = ' . count($leavers) . ' ÷ ' . $average->toDecimalString(1) . ' = '
                . ($rate === null ? 'undefined (no headcount)' : $rate . ' = ' . $rate->multipliedBy(Rational::of(100))->toDecimalString($percentPlaces) . '%');
            $rows[] = ['month' => $month, 'opening' => $opening, 'joiners' => count($joiners), 'leavers' => count($leavers),
                'closing' => $closing, 'average' => $average, 'rate' => $rate, 'working' => $working];
            $joinersBy[$month] = $joiners;
            $leaversBy[$month] = $leavers;
        }

        return ['rows' => $rows, 'unplaced' => $unplaced, 'joiners' => $joinersBy, 'leavers' => $leaversBy];
    }
}
