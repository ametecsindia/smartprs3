<?php declare(strict_types=1);
// DEV ONLY (23 Oct): one employee's month, day by day — why Absent and "need regularisation" differ. Delete after testing.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
$c = (string) ($_GET['code'] ?? 'AI2001'); $f = (string) ($_GET['from'] ?? '2026-09-01'); $t = (string) ($_GET['to'] ?? '2026-09-30');
$s = $calendar->shiftStart(); $en = $calendar->shiftEnd();
$days = [];
foreach ($attendance->settledDays($c, $s, $en, $f, $t) as $d) { if ($d['date'] >= $f && $d['date'] <= $t) { $days[$d['date']] = $d; } }
echo '<pre id="rt">';
for ($x = new DateTimeImmutable($f); $x->format('Y-m-d') <= $t; $x = $x->modify('+1 day')) {
    $k = $x->format('Y-m-d');
    $d = $days[$k] ?? ($attendance->dayWithoutPunches($c, $k, $s, $en) + ['unpunched' => true]);
    echo $k, ' ', $x->format('D'), "\t", (string) ($d['classification']['status'] ?? '?'), "\t", isset($d['unpunched']) ? 'no-punch' : 'punched',
        "\t", $d['settlement']->needsRegularisation() ? 'NEEDS-REG' : '', "\n";
}
$r = $period->forPeriod($c, $f, $t, $s, $en);
echo "\nworked={$r['worked']} off={$r['off_days']} hol={$r['holidays']} absent={$r['absent']} attention={$r['needing_attention']} payable=" . (is_object($r['payable']) ? $r['payable']->toDecimalString(2) : $r['payable']) . ' unpriced=' . json_encode($r['unpriced']) . "\n";
$run = $register->find(substr($f, 0, 7));
$row = (array) (($run['rows'] ?? [])[$c] ?? []);
echo 'RUN closed_at=' . ($run['closed_at'] ?? '') . ' approved_at=' . ($run['approved_at'] ?? '') . ' window=' . json_encode(array_intersect_key((array) $run, array_flip(['from', 'to', 'window_from', 'window_to', 'period']))) . "\n" . 'ROWDAYS=' . json_encode($row['days'] ?? null) . ' payable=' . json_encode($row['payable_days'] ?? $row['payable'] ?? null) . ' concerns=' . json_encode($row['concerns'] ?? null) . ' window=' . json_encode($row['window'] ?? null) . ' cycle=' . json_encode($row['cycle'] ?? null) . ' period_days=' . json_encode($row['period_days'] ?? null) . ' shift=' . json_encode($row['shift'] ?? null) . "\n";
echo '</pre>';
