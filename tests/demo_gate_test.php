<?php
/** EXPLORE DEMO: only a DEMO-… tenant may ever be wiped (DemoGate::wipe refuses the rest). php tests/demo_gate_test.php */
declare(strict_types=1);

require_once __DIR__ . '/../surface/DemoGate.php';

use SmartPRS\Surface\DemoGate;

$cases = [[['code' => 'DEMO-12'], true], [['code' => 'DEMO-PLAY'], true], [['code' => 'ACME'], false], [['code' => 'demo-1'], false], [[], false], [null, false]];
$fail = 0;
foreach ($cases as [$t, $want]) {
    $got = DemoGate::isDemoTenant($t);
    $got === $want || ++$fail && fwrite(STDERR, 'FAIL ' . json_encode($t) . "\n");
}
echo $fail === 0 ? count($cases) . "/" . count($cases) . " ok\n" : "{$fail} failed\n";
exit($fail === 0 ? 0 : 1);
