<?php declare(strict_types=1);
// MONEY LEFT: an approved advance becomes disbursed, which is what payroll recovers.
$saKind ??= ['table' => 'advances', 'route' => 'salary-advance', 'config' => 'salary-advance.json', 'title' => 'Salary Advance', 'noun' => 'advance', 'Noun' => 'Advance'];

if (!$may('payroll.run')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'payroll.run')->summary;
    header('Location: ?r=' . $saKind['route']);
    exit;
}

$adv = $store->find($saKind['table'], post('id'));

$saRules = new SmartPRS\Surface\SalaryAdvance($store, $modules, __DIR__ . '/../../config/' . $saKind['config']);

if ($adv === null || $saRules->statusOf($adv) !== 'approved') {
    $_SESSION['flash_problem'] = 'Only an approved ' . $saKind['noun'] . ' can be disbursed.';
} elseif ((string) ($adv['amount'] ?? '') === '') {
    $_SESSION['flash_problem'] = 'Set the sanctioned amount before disbursing.';
} elseif (($saManual = (string) ($adv['recovery_mode'] ?? '') === \SmartPRS\Surface\SalaryAdvance::MANUAL) === false && (int) ($adv['tenure_months'] ?? 0) < 1) {
    $_SESSION['flash_problem'] = 'Set the tenure before disbursing — payroll needs the EMI (or choose Manual recovery).';
} else {
    $store->put($saKind['table'], ['id' => (string) $adv['id'], 'status' => 'disbursed', 'disbursed_on' => date('Y-m-d')]);
    $audit($saKind['noun'] . '.disbursed', $saKind['noun'], (string) $adv['id'], $adv, ['status' => 'disbursed'],
        $saManual ? 'Disbursed; manual recovery' : 'Disbursed; recovery from ' . (string) ($adv['emi_start'] ?? 'next run'));
    $_SESSION['flash_notice'] = $saKind['Noun'] . ' #' . $adv['id'] . ' disbursed; ' . ($saManual
        ? 'recovery is manual — record each amount on Compensation & Claims → Loans / Salary Advance Recovery.'
        : 'payroll recovers ' . SmartPRS\Platform\Support\Money::of((string) $adv['per_month'])->format() . ' per month from ' . (string) ($adv['emi_start'] ?? 'the next run') . '.');
}

header('Location: ?r=' . $saKind['route']);
exit;
