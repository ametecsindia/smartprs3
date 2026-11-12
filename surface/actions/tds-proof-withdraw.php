<?php
/** WITHDRAW A PENDING PROOF (14 Oct) — the employee's own, while the window is open. Retired, not deleted. */
declare(strict_types=1);

include __DIR__ . '/../partials/tds-lib.php';
$p = $taxDeclarations->proof(post('id'));
$own = (string) ($session->user()['employee_id'] ?? '');
$ok = $p !== null && (string) $p['status'] === 'pending'
    && (((string) $p['employee_id'] === $own && $taxDeclarations->window('proof', date('Y-m-d'))['open']) || $may('payroll.run') || isAdministrator());
$ok ? $taxDeclarations->withdrawProof((string) $p['id']) : $_SESSION['flash_problem'] = 'That proof cannot be withdrawn (already reviewed, not yours, or the window is closed).';
$ok && $_SESSION['flash_notice'] = 'Proof withdrawn.';
header('Location: ?r=tds-proofs' . ($p !== null && (string) $p['employee_id'] !== $own ? '&id=' . rawurlencode((string) $p['employee_id']) : ''));
exit;
