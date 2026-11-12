<?php
declare(strict_types=1);
// Self-check: Late Login Policy "Late minutes counted from" (owner, 14 Oct).
// Run: php -d zend.assertions=1 -d assert.exception=1 tests/late_policy_basis_check.php
require_once dirname(__DIR__) . '/surface/LatePolicy.php';
use SmartPRS\Surface\LatePolicy;

$p = ['mode' => 'Tiered', 'grace_min' => 10, 'lates_before_cut' => 0, 'cut_mode' => 'Every', 'l1_min' => 30, 'l1_cut' => '0.5'];
$day = static fn (int $late): array => [['date' => '2026-10-01', 'late' => $late, 'off' => false]];

// From the shift start: 35 late minutes reach the 30-minute criterion.
assert(LatePolicy::penalties($p + ['late_from' => 'start'], $day(35))[0]['halves'] === 1);
// After the grace period: 35 − 10 = 25, below 30 — no cut.
assert(LatePolicy::penalties($p + ['late_from' => 'grace'], $day(35)) === []);
// After grace, 45 late = 35 counted; the reason says so.
$r = LatePolicy::penalties($p + ['late_from' => 'grace'], $day(45));
assert(count($r) === 1 && str_contains($r[0]['penalties'][0]['reason'], '35 after 10 min grace'));
// Within grace is never late, either basis.
assert(LatePolicy::penalties($p + ['late_from' => 'grace'], $day(10)) === []);
// No basis given = start of shift (old rows unchanged).
assert(LatePolicy::penalties($p, $day(35))[0]['halves'] === 1);
// COUNT BASED (owner, 14 Oct): the 3rd late login of the month costs half a day, however few minutes.
$c = ['mode' => 'Tiered', 'grace_min' => 10, 'lates_before_cut' => 0, 'cut_mode' => 'Every', 'l1_min' => 3, 'l1_cut' => '0.5', 'deduct_by' => 'count']; // pre-plan policy (no plan): unchanged behaviour
$month = [];
foreach ([1, 2, 3, 4] as $i) { $month[] = ['date' => "2026-10-0{$i}", 'late' => 15, 'off' => false]; }
$cut = LatePolicy::penalties($c, $month);
assert(array_column($cut, 'date') === ['2026-10-03', '2026-10-04'], 'from the 3rd late login on');
assert(str_contains($cut[0]['penalties'][0]['reason'], 'late login no. 3'));
// Time based with a 30-minute criterion: 15 minutes late never costs.
$t = $c; $t['deduct_by'] = 'time'; $t['l1_min'] = 30;
assert(LatePolicy::penalties($t, $month) === [], 'time based: 15 min is under 30');
// THE PLAN (any number of rows): count — from the 3rd ½ day, from the 5th 1 day; every — every 2 = ½ day.
$base = ['mode' => 'Tiered', 'grace_min' => 10, 'lates_before_cut' => 0];
$five = [];
foreach ([1, 2, 3, 4, 5] as $i) { $five[] = ['date' => "2026-10-0{$i}", 'late' => 15, 'off' => false]; }
// COUNT: "3 late logins = ½ day, 5 late logins = 1 day" — 5 late logins cost 1 day in all (½ at the 3rd, ½ more at the 5th).
$cnt = LatePolicy::penalties($base + ['plan' => ['by' => 'count', 'rows' => [['3', '0.5'], ['5', '1']]]], $five);
assert(array_map(static fn ($r) => [$r['date'], $r['halves']], $cnt) === [['2026-10-03', 1], ['2026-10-05', 1]]);
assert(str_contains($cnt[1]['penalties'][0]['reason'], '0.5 day(s) already cut'));
$ev = LatePolicy::penalties(['lates_before_cut' => 1] + $base + ['plan' => ['by' => 'every', 'every_n' => 2, 'every_d' => '0.5']], $five);
assert(array_column($ev, 'date') === ['2026-10-03', '2026-10-05'], 'every 2nd chargeable late after 1 free');
$tm = LatePolicy::penalties($base + ['plan' => ['by' => 'time', 'rows' => [['10', '0.5'], ['60', '1'], ['120', '1.5'], ['200', '2']]]],
    [['date' => 'a', 'late' => 130, 'off' => false], ['date' => 'b', 'late' => 12, 'off' => false]]);
assert($tm[0]['halves'] === 3 && $tm[1]['halves'] === 1, 'four time rows, highest reached applies');
echo "late_policy_basis_check: ok\n";
