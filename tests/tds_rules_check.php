<?php declare(strict_types=1);
// Known answers for FY 2026-27 new/old regime, run against config/income-tax.json.
// Usage: php tests/tds_rules_check.php  (from the repo root)
$root = dirname(__DIR__);
spl_autoload_register(static function (string $c) use ($root): void {
    foreach (['SmartPRS\\Surface\\' => '/surface/', 'SmartPRS\\' => '/src/'] as $ns => $dir) {
        if (str_starts_with($c, $ns)) { $f = $root . $dir . str_replace('\\', '/', substr($c, strlen($ns))) . '.php'; if (is_file($f)) { require $f; return; } }
    }
});
use SmartPRS\Surface\IncomeTax;
$Y = IncomeTax::year($root . '/config/income-tax.json', '2026-27');
$t = static fn (string $reg, int $rupees): int => intdiv(IncomeTax::compute($Y, $reg, ['gross' => $rupees * 100, 'items' => []])['total'], 100);
$cases = [
    ['new', 1275000, 0],        // taxable 12,00,000 → fully rebated
    ['new', 1575000, 109200],   // 15L: 20k+40k+45k = 1,05,000 + 4% cess
    ['new', 1285000, 10400],    // 12.1L: marginal relief → 10,000 + 4%
    ['new', 2475000, 312000],   // 24L: 3,00,000 + 4%
    ['old', 1050000, 117000],   // 10L taxable: 12,500 + 1,00,000 = 1,12,500 + 4%
    ['old', 550000, 0],         // 5L taxable → 87A rebate
];
$fail = 0;
foreach ($cases as [$r, $g, $want]) { $got = $t($r, $g); $ok = $got === $want; $fail += $ok ? 0 : 1; echo ($ok ? 'ok  ' : 'FAIL') . " $r gross $g → $got (want $want)\n"; }
// Salary paid before SmartPRS 3 counts as this employer's salary, and the parts add up.
$r = IncomeTax::compute($Y, 'new', ['gross' => 1000000_00, 'items' => ['EARLIER_SALARY' => 575000_00]]);
$fail += $r['total'] === 109200_00 && $r['parts']['this_employer'] === 1575000_00 && $r['parts']['salary_income'] === 1500000_00 ? 0 : 1;
echo ($r['total'] === 109200_00 ? 'ok  ' : 'FAIL') . " earlier salary 5.75L + payroll 10L → " . intdiv($r['total'], 100) . " (want 109200)\n";
// The year rolls over by itself: 2027-28 (not in the file) carries 2026-27 forward; an earlier year has nothing.
$next = IncomeTax::year($root . '/config/income-tax.json', '2027-28');
$fail += ($next['carried_forward_from'] ?? '') === '2026-27' && $next['regimes'] === $Y['regimes'] ? 0 : 1;
$fail += IncomeTax::year($root . '/config/income-tax.json', '2019-20') === [] ? 0 : 1;
$fail += IncomeTax::fyOf('2027-04-01') === '2027-28' && IncomeTax::fyOf('2027-03-31') === '2026-27' ? 0 : 1;
// Form 16: PAN from TRACES file names, quarters of an April year.
use SmartPRS\Surface\Form16;
$fail += Form16::panIn('ABCDE1234F_2027-28.pdf') === 'ABCDE1234F' && Form16::panIn('form16_abcde1234f.pdf') === 'ABCDE1234F' && Form16::panIn('report.pdf') === '' ? 0 : 1;
$fail += [Form16::quarter('2026-04'), Form16::quarter('2026-09'), Form16::quarter('2026-12'), Form16::quarter('2027-03')] === [1, 2, 3, 4] ? 0 : 1;
$fail += Form16::isPdf("%PDF-1.7\n") && !Form16::isPdf('PK') ? 0 : 1;
echo $fail === 0 ? "ok   year roll-over, Form 16 PAN / quarter\n" : "FAIL somewhere ($fail)\n";
assert(IncomeTax::monthly(60000000, 0, 6) === 10000000);
assert(IncomeTax::monthsLeft('2026-10') === 6);
exit($fail);
