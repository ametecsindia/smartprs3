<?php
/** RELEASE / WITHDRAW FORM 16 FOR A YEAR (owner, 15 Oct) — a new Customisation version, audited. */
declare(strict_types=1);

include __DIR__ . '/../partials/form16-lib.php';
$fy = preg_match('/^\d{4}-\d{2}$/', trim(post('fy'))) === 1 ? trim(post('fy')) : $taxDeclarations->fy();
if (!$may('payroll.run') && !isAdministrator()) {
    $_SESSION['flash_problem'] = 'Denied: releasing Form 16 needs payroll.run.';
    header('Location: ?r=form16&fy=' . $fy);
    exit;
}
$on = post('on') === '1';
$r = f16Store()->setReleased($fy, $on, (string) ($session->user()['id'] ?? ''), (string) ($session->user()['email'] ?? ''));
$r['ok'] && $audit('form16.released', 'module_customisations', 'form16-settings', null, ['fy' => $fy, 'released' => $on], 'Form 16 ' . ($on ? 'released' : 'withdrawn'));
$_SESSION[$r['ok'] ? 'flash_notice' : 'flash_problem'] = $r['ok'] ? ('Form 16 for ' . $fy . ($on ? ' is now visible to employees under Statutory & Compliance → Form 16.' : ' is hidden from employees again.')) : $r['why'];
header('Location: ?r=form16&fy=' . $fy);
exit;
