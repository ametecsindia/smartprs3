<?php declare(strict_types=1);
// DEV ONLY (12 Oct): list roster rows with employee code and shift times. Delete after.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
$roE = []; foreach ($store->all('employees') as $e) { $roE[(string) $e['id']] = SmartPRS\Surface\Employment::code($e); }
$roS = []; foreach ($store->all('shifts') as $s) { $roS[(string) $s['id']] = $s['name'] . ' ' . $s['start_time'] . '-' . $s['end_time']; }
$roOut = [];
try { foreach ($store->all('rosters') as $r) { $roOut[] = implode(' | ', [$r['id'], $roE[(string) $r['employee_id']] ?? $r['employee_id'], $r['from_date'], $r['to_date'], $roS[(string) $r['shift_id']] ?? $r['shift_id'], $r['status'] ?? '', $r['created_at'] ?? '', $r['note'] ?? '']); } } catch (\Throwable $x) { $roOut[] = $x->getMessage(); }
?><pre id="ro"><?= e(count($roOut) . "\n" . implode("\n", $roOut)) ?></pre>
