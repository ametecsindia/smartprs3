<?php declare(strict_types=1);
// CANCEL A RECOVERY ENTRY — kept, never deleted (law 3). A payroll entry whose month
// is approved cannot be cancelled: the payslip already took it.
use SmartPRS\Surface\RecoveryEntries;

if (!$may('payroll.run')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'payroll.run')->summary;
    header('Location: ?r=loan-recovery');
    exit;
}

$lrEntries = RecoveryEntries::over($store);
$lrE = $lrEntries->find(post('id'));
$lrRun = $lrE === null ? null : $register->find((string) $lrE['period']);

if ($lrE === null || !$scope->covers((string) $lrE['employee_id'], $may('employee.list'))) {
    $_SESSION['flash_problem'] = 'That recovery entry is not in your scope.';
} elseif ((string) ($lrE['status'] ?? '') === RecoveryEntries::CANCELLED) {
    $_SESSION['flash_problem'] = 'That entry is already cancelled.';
} elseif (trim(post('reason')) === '') {
    $_SESSION['flash_problem'] = 'Say why the entry is being cancelled.';
} elseif ((string) $lrE['mode'] === RecoveryEntries::PAYROLL && $lrRun !== null && isset($lrRun['approved_by'])) {
    $_SESSION['flash_problem'] = 'Payroll for ' . $lrE['period'] . ' is approved and already deducted this — raise a correction instead.';
} else {
    $lrEntries->put(['id' => (string) $lrE['id'], 'status' => RecoveryEntries::CANCELLED, 'cancelled_by' => (string) $session->user()['email'],
        'cancelled_at' => date('Y-m-d H:i:s'), 'cancel_reason' => mb_substr(trim(post('reason')), 0, 255)]);
    $audit('recovery.cancelled', 'loan_recovery_entry', (string) $lrE['id'], $lrE, ['status' => RecoveryEntries::CANCELLED], trim(post('reason')));
    $_SESSION['flash_notice'] = 'Recovery entry cancelled.' . ((string) $lrE['mode'] === RecoveryEntries::PAYROLL && $lrRun !== null
        ? ' ' . $lrE['period'] . ' payroll is already generated — click Edit on it in Generate Payroll so the payslip drops it.' : '');
}

header('Location: ?r=loan-recovery');
exit;
