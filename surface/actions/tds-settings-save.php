<?php
/** SAVE TDS WINDOWS & REMINDERS (14 Oct) — a new Customisation version; dates checked, days listed as numbers. */
declare(strict_types=1);

include __DIR__ . '/../partials/tds-lib.php';
$fail = static function (string $why): never { $_SESSION['flash_problem'] = $why; header('Location: ?r=tds-settings'); exit; };
($may('payroll.structure.publish') || isAdministrator()) || $fail('Denied: TDS settings need payroll.structure.publish.');
// The year in force is set in Organization Configuration → Companies → Financial Year, not here.
$s = ['fy' => $taxDeclarations->fy()];

foreach (['declaration_from', 'declaration_to', 'proof_from', 'proof_to'] as $k) {
    $v = trim(post($k));
    $v !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1 && $fail('Dates must be real dates.');
    $s[$k] = $v;
}

foreach (['declaration', 'proof'] as $w) {
    $s[$w . '_to'] !== '' && $s[$w . '_from'] === '' && $fail('A window that closes needs a date it opens.');
    $s[$w . '_to'] !== '' && $s[$w . '_to'] < $s[$w . '_from'] && $fail(ucfirst($w) . ' window: it cannot close before it opens.');
    $s[$w . '_reminder_days'] = array_values(array_unique(array_filter(array_map('intval', preg_split('/[^0-9]+/', post($w . '_reminder_days')) ?: []), static fn (int $d): bool => $d > 0 && $d <= 90)));
    rsort($s[$w . '_reminder_days']);
}

$s['new_joiner_days'] = max(0, min(180, (int) post('new_joiner_days')));
$s['after_proof_cutoff'] = post('after_proof_cutoff') === 'keep_declared' ? 'keep_declared' : 'approved_only';
$s['tds_in_payroll'] = post('tds_in_payroll') === '1';
$r = $taxDeclarations->saveSettings($s, (string) ($session->user()['id'] ?? ''), (string) ($session->user()['email'] ?? ''));
$r['ok'] ? $audit('tds.settings.saved', 'module_customisations', 'tds-settings', null, $s, 'TDS windows and reminders') : null;
$_SESSION[$r['ok'] ? 'flash_notice' : 'flash_problem'] = $r['ok'] ? 'TDS settings saved.' : $r['why'];
header('Location: ?r=tds-settings');
exit;
