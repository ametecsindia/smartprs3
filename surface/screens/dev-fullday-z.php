<?php declare(strict_types=1);
// DEV ONLY (owner, 23 Oct): Full Day = 8 hours on the General shift (was 8.50). Afternoon / Night (7) untouched.
// Half Day is left as declared, or derives from full (4h). ?apply=1 writes; otherwise a dry run. Delete after.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
$fdApply = (string) ($_GET['apply'] ?? '') === '1';
$fdOut = [];
foreach (liveRows($store, 'shifts') as $s) {
    $fdOut[] = trim((string) ($s['name'] ?? '')) . ' ' . substr((string) ($s['start_time'] ?? ''), 0, 5) . '-' . substr((string) ($s['end_time'] ?? ''), 0, 5)
        . ' | full ' . ((string) ($s['full_day_hours'] ?? '') ?: '—') . ' | half ' . ((string) ($s['half_day_hours'] ?? '') ?: '—') . ' | break ' . ((string) ($s['break_budget'] ?? '') ?: '—')
        . ($fdApply && strcasecmp(trim((string) ($s['name'] ?? '')), 'General') === 0 ? ' → full 8' : '');
    $fdApply && strcasecmp(trim((string) ($s['name'] ?? '')), 'General') === 0 && $store->put('shifts', ['id' => $s['id'], 'full_day_hours' => '8']);
}
$fdApply && $audit('shift.full_day_hours', 'shifts', null, null, ['full_day_hours' => 8], 'Full day set to 8 hours on the General shift');
?><pre id="fd"><?= e(implode("\n", $fdOut)) ?></pre>
