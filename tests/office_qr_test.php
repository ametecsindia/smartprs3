<?php
// Office staff punch by QR by default in a Standard company once their branch has an active gate (owner, 12 Nov 2026).
declare(strict_types=1);
require __DIR__ . '/../surface/QrAttendance.php';
use SmartPRS\Surface\QrAttendance;
$cfg = json_decode((string) file_get_contents(__DIR__ . '/../config/qr-attendance.json'), true);
$q = new QrAttendance($cfg, ['gates' => [['code' => 'G1', 'branch_id' => 'b1', 'active' => true], ['code' => 'G2', 'branch_id' => 'b2', 'active' => false]],
    'sources' => ['c2' => 'smartept']]);
$e = static fn (string $type, string $branch, string $co = 'c1'): array => ['type' => $type, 'branch_id' => $branch, 'employer_company_id' => $co];
$ok = 0; $cases = [
    ['office, gate', $q->modeFor($e('Office', 'b1')), 'qr'],
    ['field, gate', $q->modeFor($e('Field', 'b1')), 'standard'],
    ['office, inactive gate', $q->modeFor($e('Office', 'b2')), 'standard'],
    ['office, no gate', $q->modeFor($e('office', 'b9')), 'standard'],
    ['office, smartept company', $q->modeFor($e('Office', 'b1', 'c2')), 'smartept'],
    ['blank type', $q->modeFor($e('', 'b1')), 'standard'],
    ['standard company has gates', $q->usesGates('c1') ? 'yes' : 'no', 'yes'],
    ['smartept company has none', $q->usesGates('c2') ? 'yes' : 'no', 'no'],
];
foreach ($cases as [$n, $got, $want]) { $got === $want ? $ok++ : fwrite(STDERR, "FAIL $n: $got != $want\n"); }
echo "$ok/" . count($cases) . "\n"; exit($ok === count($cases) ? 0 : 1);
