<?php
/**
 * CERTIFICATE-LINKED DEDUCTIONS — SAVE THE RULES (owner, 13 Oct). Every save is a
 * new Customisation version; nothing already paid changes. Every value is checked
 * against the catalogue it came from, so a rule can only point at a real
 * deduction, a real earning and a real status field.
 */
declare(strict_types=1);

$back = '?r=certificate-holds';
$fail = static function (string $why) use ($back): never {
    $_SESSION['flash_problem'] = $why;
    header('Location: ' . $back);
    exit;
};

if (!$may('tenant.settings.update')) {
    $fail('Denied: ' . $access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary);
}

$catalogue = $componentRules->catalogue();
$kindOf = [];

foreach ((array) ($catalogue['components'] ?? []) as $c) {
    $kindOf[(string) ($c['code'] ?? '')] = (string) ($c['kind'] ?? '');
}

$fields = array_map(static fn ($f): string => (string) ($f['column'] ?? $f['code'] ?? ''), (array) ($catalogue['status_fields'] ?? []));
$onExit = array_keys((array) ($certificateHolds->declared()['on_exit_options'] ?? []));
$rules = [];
$seenKeys = $seenDeductions = $seenRefunds = [];

foreach ((array) ($_POST['rules'] ?? []) as $r) {
    $r = (array) $r;
    $label = trim((string) ($r['label'] ?? ''));
    $deduction = trim((string) ($r['deduction_code'] ?? ''));

    // The blank "add another" row, left blank.
    if ($label === '' && $deduction === '') {
        continue;
    }

    $label === '' && $fail('Every rule needs a certificate name.');
    ($kindOf[$deduction] ?? '') === 'deduction' || $fail("{$label}: pick the deduction from the list.");
    $refund = trim((string) ($r['refund_code'] ?? ''));
    ($kindOf[$refund] ?? '') === 'earning' || $fail("{$label}: pick the earning the refund is paid as.");
    $field = trim((string) ($r['status_field'] ?? ''));
    in_array($field, $fields, true) || $fail("{$label}: pick the status field that shows the certificate.");
    $cleared = array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), (array) ($r['cleared_values'] ?? []))));
    $cleared === [] && $fail("{$label}: tick at least one value that means the certificate is submitted.");
    $exit = trim((string) ($r['on_exit'] ?? ''));
    in_array($exit, $onExit, true) || $fail("{$label}: choose what happens if the employee leaves without the certificate.");
    isset($seenDeductions[$deduction]) && $fail("{$label}: {$deduction} is already used by another rule.");
    isset($seenRefunds[$refund]) && $fail("{$label}: {$refund} is already the refund of another rule — each rule needs its own refund line, or one rule's refund would count as another's.");
    $key = trim((string) ($r['key'] ?? ''));
    $key = $key !== '' ? $key : (strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $label)) ?: 'rule') . '-' . substr(md5($label . microtime()), 0, 4);
    isset($seenKeys[$key]) && $fail("{$label}: duplicate rule.");
    $seenKeys[$key] = $seenDeductions[$deduction] = $seenRefunds[$refund] = true;
    $rules[] = ['key' => $key, 'label' => $label, 'active' => isset($r['active']), 'deduction_code' => $deduction,
        'refund_code' => $refund, 'status_field' => $field, 'cleared_values' => $cleared, 'on_exit' => $exit];
}

$result = $customisation->saveRaw($certificateHolds->key(), ['rules' => $rules],
    (string) ($session->user()['id'] ?? ''), (string) $session->user()['email'], 'Certificate-linked Deductions saved');
$_SESSION[$result['ok'] ? 'flash_notice' : 'flash_problem'] = $result['why'];
header('Location: ' . $back);
exit;
