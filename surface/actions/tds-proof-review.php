<?php
/** APPROVE OR REJECT AN INVESTMENT PROOF (14 Oct) — HR (payroll.run) or the Administrator. A rejection needs a reason; the employee is told either way. */
declare(strict_types=1);

use SmartPRS\Surface\Employment;
use SmartPRS\Surface\IncomeTax;

include __DIR__ . '/../partials/tds-lib.php';
$back = '?' . (preg_match('/^[A-Za-z0-9=&%_.\-]*$/', post('back')) === 1 && str_starts_with(post('back'), 'r=tds-proofs') ? post('back') : 'r=tds-proofs');
$fail = static function (string $why) use ($back): never { $_SESSION['flash_problem'] = $why; header('Location: ' . $back); exit; };
($may('payroll.run') || isAdministrator()) || $fail('Denied: verifying proofs needs payroll.run.');
$proof = $taxDeclarations->proof(post('id'));
$proof === null && $fail('No such proof.');
$decision = post('decision');
in_array($decision, ['approved', 'rejected'], true) || $fail('Approve or reject.');
$decision === 'rejected' && post('note') === '' && $fail('Say why the proof is rejected — the employee sees it.');
try { $amt = $decision === 'approved' ? IncomeTax::p(post('approved')) : 0; } catch (\InvalidArgumentException) { $fail('The approved amount is not a rupee figure.'); }
$decision === 'approved' && $amt <= 0 && $fail('Give the amount the proof supports.');
$row = $taxDeclarations->review((string) $proof['id'], $decision, SmartPRS\Platform\Support\Money::ofMinor($amt)->toDecimalString(), post('note'), (string) ($session->user()['email'] ?? ''));
$audit('tds.proof.' . $decision, 'tax_proofs', (string) $proof['id'], $proof, $row, 'Investment proof ' . $decision);
$emp = $store->find('employees', (string) $proof['employee_id']);

if ($emp !== null && Employment::code($emp) !== '') {
    try {
        $notifications->queue('tds.proof_reviewed', Employment::code($emp), 'tds-proof-reviewed:' . $proof['id'] . ':' . $decision . ':' . date('YmdHis'), [
            'item' => (string) $proof['item_code'], 'decision' => $decision,
            'amount' => SmartPRS\Platform\Support\Money::ofMinor($amt)->format(), 'note' => post('note') === '' ? '—' : post('note'),
        ]);
    } catch (\Throwable $x) {
        error_log('TDS proof notification not queued: ' . $x->getMessage());
    }
}

$_SESSION['flash_notice'] = 'Proof ' . $decision . '.';
header('Location: ' . $back);
exit;
