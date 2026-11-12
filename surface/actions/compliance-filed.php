<?php
/**
 * RECORD A STATUTORY FILING — one row in compliance_filings: which obligation,
 * which period, which company, filed when, the reference and who recorded it.
 * A period already recorded is refused rather than overwritten (law 3).
 */
declare(strict_types=1);

use SmartPRS\Surface\Amount;
use SmartPRS\Surface\StatutoryReports;

$back = '?r=compliance-alerts&company=' . rawurlencode(trim(post('company'))) . '&periods=' . rawurlencode(trim(post('periods')));
$fail = static function (string $why) use ($back): never {
    $_SESSION['flash_problem'] = $why;
    header('Location: ' . $back);
    exit;
};

if (!$may('payroll.run')) {
    $fail('Denied: ' . $access->decide((string) $session->user()['role'], 'payroll.run')->summary);
}

$cal = StatutoryReports::config(__DIR__ . '/../../config/compliance-calendar.json');
$obligation = trim(post('obligation'));
$period = trim(post('period'));
$companyId = trim(post('company'));
$filedOn = trim(post('filed_on'));
$amount = trim(post('amount'));

if (!in_array($obligation, array_column((array) $cal['obligations'], 'key'), true)) {
    $fail('That is not an obligation the calendar declares.');
}

if (preg_match('/^\d{4}-\d{2}(-Q[1-4])?$/', $period) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $filedOn) !== 1) {
    $fail('A filing needs the period and the date it was filed.');
}

if ($companyId !== '' && $store->find('companies', $companyId) === null) {
    $fail('No such company.');
}

try {
    $amountStored = $amount === '' ? null : Amount::of($amount)->toDecimalString();
} catch (\InvalidArgumentException) {
    $fail('The amount is not a rupee figure.');
}

foreach (liveRows($store, 'compliance_filings') as $f) {
    if ((string) $f['obligation'] === $obligation && (string) $f['period'] === $period
        && (string) ($f['employer_company_id'] ?? '') === $companyId) {
        $fail($obligation . ' for ' . $period . ' is already recorded as filed on ' . (string) $f['filed_on']
            . '. It is not overwritten; a correction is a note on the Compliance filings register.');
    }
}

$row = $store->put('compliance_filings', [
    'employer_company_id' => $companyId === '' ? null : $companyId,
    'obligation' => $obligation,
    'period' => $period,
    'filed_on' => $filedOn,
    'reference' => trim(post('reference')),
    'amount' => $amountStored,
    'filed_by' => (string) ($session->user()['email'] ?? ''),
    'status' => 'filed',
    'created_at' => date('Y-m-d H:i:s'),
]);
$audit('compliance.filed', 'compliance_filings', (string) $row['id'], null,
    ['obligation' => $obligation, 'period' => $period, 'filed_on' => $filedOn], 'Statutory filing recorded');
$_SESSION['flash_notice'] = $obligation . ' for ' . $period . ' recorded as filed on ' . $filedOn . '.';
header('Location: ' . $back);
exit;
