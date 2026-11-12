<?php declare(strict_types=1);
// SAVE A SALARY ADVANCE (new or edited). The EMI and the overall status are
// derived by SalaryAdvance::shape; each level's decision is stamped with a time
// the first time it is recorded or when it changes.
// Also the LOANS save: surface/actions/loans-save.php sets $saKind and includes this file.
$saKind ??= ['table' => 'advances', 'route' => 'salary-advance', 'config' => 'salary-advance.json', 'title' => 'Salary Advance', 'noun' => 'advance', 'Noun' => 'Advance'];

if (!$may('payroll.run')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'payroll.run')->summary;
    header('Location: ?r=' . $saKind['route']);
    exit;
}

$salaryAdvance = new SmartPRS\Surface\SalaryAdvance($store, $modules, __DIR__ . '/../../config/' . $saKind['config']);
$saTarget = $store->find('employees', post('employee_id'));
$saExisting = post('id') === '' ? null : $store->find($saKind['table'], post('id'));

if ($saTarget === null || !$scope->covers((string) $saTarget['id'], $may('employee.list'))) {
    $_SESSION['flash_problem'] = 'Pick an employee in your scope.';
    header('Location: ?r=' . $saKind['route'] . '&add=1');
    exit;
}

$shaped = $salaryAdvance->shape($_POST, $saTarget, date('Y-m-d'));

if (!$shaped['ok']) {
    $_SESSION['flash_problem'] = 'Not saved — ' . $shaped['why'];
    header('Location: ?r=' . $saKind['route'] . '&' . (post('id') === '' ? 'add=1' : 'edit=' . rawurlencode(post('id'))) . '#sa-form');
    exit;
}

$row = $shaped['row'];

// OPTION 2 (MANUAL) NEEDS ITS COLUMN. On a database install the recovery_mode
// column arrives with 2026_09_22_000100; until `composer db:migrate` has run it
// is refused by name rather than saved as an EMI the user did not ask for.
if (!($store instanceof \SmartPRS\Surface\Store) && !in_array('recovery_mode', $store->columnsOf($saKind['table']), true)) {
    if ($row['recovery_mode'] === \SmartPRS\Surface\SalaryAdvance::MANUAL) {
        $_SESSION['flash_problem'] = 'Not saved — Manual recovery needs the database update: run composer db:migrate on the server, then save again.';
        header('Location: ?r=' . $saKind['route'] . '&' . (post('id') === '' ? 'add=1' : 'edit=' . rawurlencode(post('id'))) . '#sa-form');
        exit;
    }

    unset($row['recovery_mode']);
}

foreach ($salaryAdvance->levels() as $lvl) {
    $k = (string) $lvl['key'];
    $before = (string) ($saExisting[$k . '_status'] ?? '');
    $row[$k . '_at'] = $row[$k . '_status'] === null ? null : ($before === $row[$k . '_status'] ? ($saExisting[$k . '_at'] ?? date('Y-m-d H:i:s')) : date('Y-m-d H:i:s'));
}

// A DISBURSED ADVANCE KEEPS ITS DISBURSED STATUS: the decisions are history once money left.
if ($saExisting !== null && in_array((string) ($saExisting['status'] ?? ''), ['disbursed', 'recovering', 'recovered'], true)) {
    $row['status'] = (string) $saExisting['status'];
}

$row['decided_by'] = (string) $session->user()['email'];

if ($saExisting !== null) {
    $row['id'] = (string) $saExisting['id'];
}

if ($saKind['table'] === 'loans') {
    // The recovery engine reads the loan's legacy columns too — kept in step, never diverging.
    $row['emi'] = $row['per_month'];
    $row['tenure'] = $row['tenure_months'];
    $row['outstanding'] = $row['balance'];
    $row['start_month'] = $row['emi_start'];
}

$saved = $store->put($saKind['table'], $row);
$audit($saExisting === null ? $saKind['noun'] . '.requested' : $saKind['noun'] . '.updated', $saKind['noun'], (string) $saved['id'], $saExisting, $row,
    $saKind['title'] . ' for ' . \SmartPRS\Surface\Employment::label($saTarget) . ': ' . $row['status']);
$_SESSION['flash_notice'] = $saKind['title'] . ' #' . $saved['id'] . ' saved for ' . \SmartPRS\Surface\Employment::label($saTarget) . ' — ' . $row['status']
    . ($row['per_month'] !== null ? '; EMI ' . SmartPRS\Platform\Support\Money::of($row['per_month'])->format() . ' × ' . (int) $row['tenure_months'] . ' months' : '')
    . (($row['recovery_mode'] ?? '') === \SmartPRS\Surface\SalaryAdvance::MANUAL ? '; manual recovery — record amounts on Loans / Salary Advance Recovery' : '') . '.';
header('Location: ?r=' . $saKind['route']);
exit;
