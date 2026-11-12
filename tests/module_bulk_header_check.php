<?php
// Self-check (14 Oct): a bulk sample whose labels include a field code ("CIN", "Remarks") must still be
// read by its codes row — every column kept. Run: php tests/module_bulk_header_check.php → OK
declare(strict_types=1);
spl_autoload_register(function ($c) { $f = __DIR__ . '/../surface/' . basename(str_replace('\\', '/', $c)) . '.php'; if (is_file($f)) require $f; });
use SmartPRS\Surface\ModuleBulk; use SmartPRS\Surface\Sheet;
$F = static fn ($n, $l, $t = 'text', $o = [], $lk = []) => ['name' => $n, 'label' => $l, 'type' => $t, 'options' => $o, 'lookup' => $lk, 'required' => false, 'readonly' => false, 'min' => null];
$fields = [$F('employee_id', 'Employee', 'text', [], [['id' => '73', 'label' => 'ABCR-2001 — K']]), $F('month', 'Wage month', 'month'), $F('tds_deducted', 'TDS deducted', 'number'), $F('cin', 'CIN'), $F('remarks', 'Remarks')];
$r = ModuleBulk::read($fields, Sheet::toCsv(ModuleBulk::sample($fields, [['ABCR-2001 — K', '2026-09', '1500', '', 'x']]), 'a.xlsx'));
$in = $r['rows'][0]['input'] ?? [];
if (count($r['rows']) !== 1 || ($in['employee_id'] ?? '') !== '73' || ($in['tds_deducted'] ?? '') !== '1500') { fwrite(STDERR, "FAIL " . json_encode($r) . "\n"); exit(1); }
echo "OK\n";
