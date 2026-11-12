<?php declare(strict_types=1);
// SAVE A RECOVERY (Loans / Salary Advance Recovery). Shaped and checked by
// RecoveryEntries::shape against what may still be recorded (Recoveries::availableMinor);
// a payroll month that is already approved is refused — the entry would never reach it.
use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\RecoveryEntries;
use SmartPRS\Surface\Recoveries;

$lrBack = '?r=loan-recovery&add=' . rawurlencode(post('kind')) . '&emp=' . rawurlencode(post('employee_id')) . '&ob=' . rawurlencode(post('obligation_id')) . '#lr-form';

if (!$may('payroll.run')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'payroll.run')->summary;
    header('Location: ?r=loan-recovery');
    exit;
}

$lrKind = post('kind');
$lrEmp = $store->find('employees', post('employee_id'));
$lrRow = isset(RecoveryEntries::KINDS[$lrKind]) ? $store->find(RecoveryEntries::KINDS[$lrKind]['table'], post('obligation_id')) : null;

if ($lrEmp === null || !$scope->covers((string) $lrEmp['id'], $may('employee.list'))) {
    $_SESSION['flash_problem'] = 'Pick an employee in your scope.';
} elseif ($lrRow === null || !in_array((string) ($lrRow['status'] ?? ''), ['disbursed', 'recovering', 'active'], true)) {
    $_SESSION['flash_problem'] = 'Pick a disbursed advance / loan of this employee.';
} elseif (post('mode') === RecoveryEntries::PAYROLL && ($lrRun = $register->find(post('period'))) !== null && isset($lrRun['approved_by'])) {
    $_SESSION['flash_problem'] = 'Not saved — payroll for ' . post('period') . ' is already approved. Pick a later month, or record the amount as collected.';
} else {
    $lrEntries = RecoveryEntries::over($store);
    $lrAvailable = (new Recoveries($store, $lrEntries))->availableMinor($lrKind, $lrRow, $lrEmp);
    $shaped = $lrEntries->shape($_POST, $lrKind, $lrRow, $lrEmp, $lrAvailable, (string) $session->user()['email']);

    if (!$shaped['ok']) {
        $_SESSION['flash_problem'] = 'Not saved — ' . $shaped['why'];
    } else {
        $saved = $lrEntries->put($shaped['row'] + ['created_at' => date('Y-m-d H:i:s')]);
        $what = RecoveryEntries::KINDS[$lrKind]['noun'] . ' #' . $lrRow['id'];
        $amount = SmartPRS\Surface\Amount::of((string) $saved['amount'])->format();
        $audit('recovery.' . $saved['mode'], 'loan_recovery_entry', (string) $saved['id'], null, $saved,
            $amount . ' against ' . $what . ' for ' . Employment::label($lrEmp));
        $generated = $saved['mode'] === RecoveryEntries::PAYROLL && $register->find((string) $saved['period']) !== null;
        $_SESSION['flash_notice'] = $saved['mode'] === RecoveryEntries::PAYROLL
            ? $amount . ' will be deducted from ' . Employment::label($lrEmp) . "'s " . $saved['period'] . ' payroll against ' . $what . ' (in place of that month\'s EMI).'
                . ($generated ? ' ' . $saved['period'] . ' payroll is already generated — open Generate Payroll and click Edit on ' . $saved['period'] . ' so the payslip includes it.' : '')
            : $amount . ' recorded as collected against ' . $what . ' — the balance is now ' . Money::ofMinor(max(0, $lrAvailable - SmartPRS\Surface\Amount::of((string) $saved['amount'])->minor))->format() . ' still to record.';
        header('Location: ?r=loan-recovery');
        exit;
    }
}

header('Location: ' . $lrBack);
exit;
