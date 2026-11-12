<?php

declare(strict_types=1);

/**
 * STATUTORY & COMPLIANCE — the arithmetic behind the hand-built screens, and
 * the declarations behind the register screens. Every figure is one a person
 * can check by hand against the config files.
 */

use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\Modules;
use SmartPRS\Surface\Navigation;
use SmartPRS\Surface\StatutoryReports as S;
use SmartPRS\Surface\Store;

group('statutory: gratuity');

$root = __DIR__ . '/..';
$g = S::config($root . '/config/gratuity.json');

same(0, S::serviceMonths('', '2026-09-09'), 'no joining date = no service');
same(0, S::serviceMonths('2027-01-01', '2026-09-09'), 'a joining date in the future = no service');
same(65, S::serviceMonths('2021-04-01', '2026-09-09'), '1 Apr 2021 to 9 Sep 2026 is 65 whole months');

$r = S::gratuity($g, Money::of('20000'), '2021-04-01', '2026-09-09');
ok($r['eligible'], '5 years 5 months is eligible');
same(5, $r['years'], '5 months does not round up to a sixth year');
same('₹57,692.31', $r['amount']->format(), '20,000 × 15/26 × 5 = 57,692.3077 → half-up to the paisa');
ok(str_contains($r['working'], '× 15/26 × 5'), 'the working names the factor from config');

$r = S::gratuity($g, Money::of('20000'), '2021-01-15', '2026-09-09');
same(6, $r['years'], '5 years 7 months counts as 6 years (part year from 6 months, per config)');
same('₹69,230.77', $r['amount']->format(), '20,000 × 15/26 × 6');

$r = S::gratuity($g, Money::of('20000'), '2022-06-01', '2026-09-09');
ok(!$r['eligible'] && $r['amount']->isZero(), '4 years 3 months is below the 5-year rule: nothing accrued');
same(4, $r['years'], '3 months does not round up');
$r = S::gratuity($g, Money::of('20000'), '2022-01-01', '2026-09-09');
// 16 Oct 2026: gratuity follows the Code on Social Security 2020 — FIVE FULL years (config/gratuity.json),
// so the old PRS2 4-years-240-days reading no longer makes 4y 8m eligible.
ok(!$r['eligible'] && $r['amount']->isZero(), '4 years 8 months is not five full years under the Code on Social Security: nothing accrued');

$r = S::gratuity($g, Money::of('9000000'), '2000-01-01', '2026-09-09');
ok($r['capped'], 'a huge basic over 26 years hits the cap');
same(Money::of((string) $g['statutory_cap'])->format(), $r['amount']->format(), 'the cap is the configured one');

$r = S::gratuity($g, Money::of('20000'), '', '2026-09-09');
ok(!$r['eligible'], 'no joining date is not eligible, and says so');

group('statutory: last drawn basic');

$version = ['components' => [
    ['code' => 'C-BASIC', 'basis' => 'percent_of_gross', 'rate_percent' => 50, 'flags' => ['pf_base' => true]],
    ['code' => 'C-HRA', 'basis' => 'percent_of', 'flags' => []],
]];
same(['C-BASIC'], S::flaggedCodes($version, 'pf_base'), 'the flagged code is found');
same([], S::flaggedCodes($version, 'nope'), 'an unknown flag matches nothing');

$runs = [
    ['period' => '2026-08', 'rows' => ['SP-001' => ['lines' => [['code' => 'C-BASIC', 'minor' => 2500000], ['code' => 'C-HRA', 'minor' => 1000000]]]]],
    ['period' => '2026-07', 'rows' => ['SP-001' => ['lines' => [['code' => 'C-BASIC', 'minor' => 2400000]]]]],
];
$b = S::lastDrawnBasic('SP-001', Money::of('60000'), $runs, $version, 'pf_base');
same('₹25,000.00', $b['basic']->format(), 'the newest closed run wins');
ok(str_contains($b['basis'], '2026-08'), 'and the basis names it');
$b = S::lastDrawnBasic('SP-999', Money::of('60000'), $runs, $version, 'pf_base');
same('₹30,000.00', $b['basic']->format(), 'no run: 50% of gross from the structure');
$b = S::lastDrawnBasic('SP-999', Money::of('60000'), $runs, $version, 'nope');
same('₹60,000.00', $b['basic']->format(), 'nothing flagged: the whole gross, stated');
ok(str_contains($b['basis'], 'whole monthly gross'), 'the basis says so');

group('statutory: bonus');

$bc = S::config($root . '/config/statutory-bonus.json');
$r = S::bonus($bc, ['2026-04' => Money::of('6000'), '2026-05' => Money::of('8000'), '2026-06' => Money::of('25000'), '2026-07' => Money::zero()]);
same(2, $r['eligible_months'], 'the month over the eligibility ceiling and the unpaid month do not count');
same('₹13,000.00', $r['base']->format(), '6,000 + min(8,000, 7,000)');
same('₹1,082.90', $r['at_min']->format(), '13,000 × 8.33%');
same('₹2,600.00', $r['at_max']->format(), '13,000 × 20%');
ok(!$r['months'][2]['eligible'] && $r['months'][2]['counted']->isZero(), 'the 25,000 month is listed as ineligible');
same('2026-27', S::financialYear('2026-04', 4), 'April opens the financial year');
same('2025-26', S::financialYear('2026-03', 4), 'March closes the previous one');

group('statutory: heads from closed runs');

$runs = [
    ['period' => '2026-05', 'rows' => ['SP-001' => ['lines' => [['code' => 'D-PT', 'minor' => 20000], ['code' => 'PF', 'minor' => 180000]]]]],
    ['period' => '2026-04', 'rows' => ['SP-001' => ['lines' => [['code' => 'D-PT', 'minor' => 20000]]], 'SP-002' => ['lines' => [['code' => 'PT', 'minor' => 15000]]]]],
];
$t = S::byEmployment($runs, ['PT', 'D-PT']);
same(['2026-04' => 20000, '2026-05' => 20000], $t['SP-001'], 'both PT spellings count, periods ascending');
same(['2026-04' => 15000], $t['SP-002'], 'the second employment');
same(['SP-001' => ['2026-05' => 180000]], S::byEmployment($runs, ['PF'], '2026-05'), 'a from-period filter');
same(0, S::linesMinor(['lines' => []], ['PF']), 'no lines = zero, not a refusal');

group('statutory: compliance calendar');

$cal = S::config($root . '/config/compliance-calendar.json');
$byKey = array_column($cal['obligations'], null, 'key');
$p = S::periods($byKey['pf-ecr'], '2026-09-09', 3);
same([['period' => '2026-07', 'due' => '2026-08-15'], ['period' => '2026-08', 'due' => '2026-09-15'], ['period' => '2026-09', 'due' => '2026-10-15']],
    $p, 'PF ECR: wage month due on the 15th of the next month');
$q = S::periods($byKey['tds-return'], '2026-09-09', 3);
same(['2025-26-Q4', '2026-27-Q1', '2026-27-Q2'], array_column($q, 'period'), 'three quarters back from Sep 2026, named by financial year');
same('2026-05-31', $q[0]['due'], 'Jan–Mar 2026 is Q4 of 2025-26, due 31 May 2026');
same('2026-10-31', $q[2]['due'], 'Jul–Sep 2026 due 31 Oct 2026');
$q3 = S::periods($byKey['tds-return'], '2027-01-10', 1);
same([['period' => '2026-27-Q4', 'due' => '2027-05-31']], $q3, 'January sits in Q4 of 2026-27, due 31 May 2027');
$q3 = S::periods($byKey['tds-return'], '2026-12-10', 1);
same([['period' => '2026-27-Q3', 'due' => '2027-01-31']], $q3, 'Oct–Dec is due the following January');

same(S::FILED, S::filingState('2026-08-15', '2026-08-10', '2026-09-09', 7), 'filed beats overdue');
same(S::OVERDUE, S::filingState('2026-08-15', null, '2026-09-09', 7), 'past due, not filed');
same(S::DUE, S::filingState('2026-09-15', '', '2026-09-09', 7), 'inside the lead window');
same(S::UPCOMING, S::filingState('2026-10-15', null, '2026-09-09', 7), 'beyond it');

foreach ($cal['obligations'] as $o) {
    ok(trim((string) ($o['blocks'] ?? '')) !== '', 'obligation ' . $o['key'] . ' says what missing it blocks');
    ok(isset($cal['heads'][(string) $o['head']]), 'obligation ' . $o['key'] . ' names a declared head');
}

group('statutory: module declarations');

$modules = new Modules(new Store(sys_get_temp_dir() . '/prs3-stat-' . bin2hex(random_bytes(3))), $root . '/config/modules.json');
$byKey = array_column($modules->all(), null, 'key');

foreach (['pf-esi-register', 'tds-declarations', 'tds-returns', 'bonus-register', 'posh', 'min-wages', 'pt-slabs'] as $key) {
    ok(isset($byKey[$key]), "module $key is declared");
    $m = $byKey[$key] ?? ['columns' => [], 'fields' => []];

    foreach ((array) $m['columns'] as $c) {
        ok(isset($m['fields'][$c]), "$key lists $c and declares it");
    }
}

same('employee.document.view', $byKey['posh']['permissions']['view'] ?? '', 'POSH is confidential: behind the document permission, not employee.view');

group('statutory: menu');

$sections = (new Navigation($root . '/config/navigation.json'))->sections();
$stat = array_values(array_filter($sections, static fn (array $s): bool => ($s['key'] ?? '') === 'statutory'))[0] ?? [];
$keys = array_column((array) ($stat['items'] ?? []), 'key');

foreach (['pf-esi', 'tds', 'pt', 'gratuity', 'bonus', 'tds-returns', 'compliance-alerts', 'posh', 'min-wages', 'audit-reports', 'statutory-config'] as $k) {
    ok(in_array($k, $keys, true), "SmartPRS 2 sub-menu '$k' is on the Statutory & Compliance menu");
}

$audit = S::config($root . '/config/audit-reports.json');
$posh = array_values(array_filter($audit['registers'], static fn (array $r): bool => $r['key'] === 'posh'))[0] ?? [];
ok(($posh['confidential'] ?? false) === true, 'the POSH register is marked confidential and is never exported in bulk');
