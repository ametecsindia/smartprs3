<?php
// % INCREASE IS WORKED OUT, NEVER TYPED (owner, 30 Oct 2026): Increment / Appraisal's % Increase = (new − old) / old × 100,
// field option `percent_change` → Modules::percentChange(). Run: php tests/percent_change_check.php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
use SmartPRS\Surface\Modules;
$cases = [['350000', '420000', '20.00'], ['350000.0000', '385000', '10.00'], ['300000', '310000', '3.33'], ['300000', '305000', '1.67'],
    ['420000', '350000', '-16.67'], ['0', '100', null], ['', '100', null], ['100', '', null], ['abc', '1', null], ['100000', '100000', '0.00'], ['3', '4', '33.33']];
$f = [];
foreach ($cases as [$o, $n, $want]) { $got = Modules::percentChange($o, $n); $got === $want || $f[] = "{$o} to {$n}: got " . var_export($got, true) . ', want ' . var_export($want, true); }
echo (count($cases) - count($f)) . ' / ' . count($f) . " (passed / failed)\n";
foreach ($f as $x) { echo "  FAIL: {$x}\n"; }
exit($f === [] ? 0 : 1);
