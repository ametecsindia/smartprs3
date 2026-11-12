<?php
/**
 * THE BANK FILE AS A DOWNLOAD — the same CSV BankAdvice::generate() shows on
 * the Bank Payout Pack screen, for one approved run. A run that does not
 * reconcile or is missing an account produces no file: the refusal goes back
 * to the screen as the problem it is.
 */
declare(strict_types=1);

use SmartPRS\Surface\BankAdvice;
use SmartPRS\Surface\Compensation;
use SmartPRS\Surface\Employment;

$compensation = new Compensation($store, __DIR__ . '/../../config/compensation.json');
$csvPermission = (string) $compensation->config()['bank_pack']['csv_download_permission'];
$period = trim(post('period'));

if (!$may($csvPermission)) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], $csvPermission)->summary;
    header('Location: ?r=bank-pack&period=' . rawurlencode($period));
    exit;
}

$run = $period === '' ? null : $register->find($period);

if ($run === null) {
    $_SESSION['flash_problem'] = 'No closed run for ' . $period . '.';
    header('Location: ?r=bank-pack');
    exit;
}

$accounts = [];

foreach ($scope->only($store->all('employees'), $may('employee.list')) as $employment) {
    $accounts[(string) Employment::code($employment)] = [
        'account' => Employment::value($employment, 'bank_acc'),
        'ifsc' => Employment::value($employment, 'ifsc'),
    ];
}

$advice = BankAdvice::generate($run, $accounts);

if (!$advice['ok']) {
    $_SESSION['flash_problem'] = $advice['why'];
    header('Location: ?r=bank-pack&period=' . rawurlencode($period));
    exit;
}

$audit('bank-pack.downloaded', 'payroll_run', $period, null,
    ['lines' => count($advice['lines']), 'total' => (string) $advice['total']], 'Bank payout pack CSV downloaded');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="bank-pack-' . preg_replace('/[^0-9-]/', '', $period) . '.csv"');
echo $advice['csv'];
exit;
