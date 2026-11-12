<?php declare(strict_types=1);
/**
 * ONE DATASET (config/report-datasets.json) — included by the screen (with
 * $params = $_GET) and by surface/actions/report-dataset-export.php (with
 * $params = $_POST), so the download is exactly the rows shown. Sets $dsCfg,
 * $dsAll, $ds, $dsFilter, $dsPeriod, $dsKeep, $dsColumns, $dsRows (every row) and
 * $dsWhy (a refusal, else '').
 */
$dsCfg = (array) json_decode((string) file_get_contents(__DIR__ . '/../../config/report-datasets.json'), true);
$dsAll = [];

foreach ((array) ($dsCfg['datasets'] ?? []) as $dsD) {
    $dsAll[(string) $dsD['key']] = $dsD;
}

$ds = $dsAll[(string) ($params['d'] ?? '')] ?? ($dsAll[array_key_first($dsAll)] ?? null);
$dsRows = [];
$dsColumns = [];
$dsWhy = '';
$dsFilter = [];

foreach (array_keys(ORG_FILTERS) as $dsK) {
    $dsV = trim((string) ($params['f_' . $dsK] ?? ''));
    $dsFilter[$dsK] = strcasecmp($dsV, 'all') === 0 ? '' : $dsV;
}

$dsPeriod = $ds !== null && ($ds['date_column'] ?? '') !== '' && isset($params['p']) ? reportPeriod($params, financialYearStart($customisation), date('Y-m-d')) : null;
$dsKeep = ['d' => (string) ($ds['key'] ?? '')] + ($dsPeriod === null ? [] : ['p' => $dsPeriod['mode'], 'month' => $dsPeriod['month'], 'year' => $dsPeriod['year'], 'fy' => (string) ($params['fy'] ?? ''), 'from' => $dsPeriod['from'], 'to' => $dsPeriod['to']]);

if ($ds === null) {
    $dsWhy = 'No dataset is declared in config/report-datasets.json.';
} elseif (!$may((string) $ds['permission'])) {
    $dsWhy = 'Denied: ' . $access->decide((string) $session->user()['role'], (string) $ds['permission'])->summary;
} else {
    $dsColumns = (array) $ds['columns'];
    // THE PEOPLE: the viewer's scope first, then the Organization Setup filter — a row
    // belongs to an employee, and an employee outside the scope takes their rows with them.
    $dsPeople = orgFilterApply($store, $scope->only(liveRows($store, 'employees'), $may('employee.list')), $dsFilter);
    $dsById = [];
    $dsByCode = [];

    foreach ($dsPeople as $dsE) {
        $dsById[(string) $dsE['id']] = $dsE;
        $dsByCode[\SmartPRS\Surface\Employment::code($dsE)] = $dsE;
    }

    $dsNames = orgNames($store);
    $dsTypes = array_column(liveRows($store, 'leave_types'), 'name', 'id');
    $dsBy = (string) ($ds['people_column'] ?? 'employee_id');
    $dsDate = (string) ($ds['date_column'] ?? '');
    $dsMoney = array_flip((array) ($ds['money'] ?? []));

    try {
        if (($ds['source'] ?? '') === 'register') {
            // PAYSLIPS LIVE IN THE REGISTER'S RUNS: one row per employee per run, its lines summed by kind.
            $dsSource = [];

            foreach ($register->runs() as $dsRun) {
                foreach ((array) ($dsRun['rows'] ?? []) as $dsCode => $dsOwn) {
                    $dsGross = 0;
                    $dsDed = 0;

                    foreach ((array) ($dsOwn['lines'] ?? []) as $dsLine) {
                        $dsKind = (string) ($dsLine['kind'] ?? '');
                        $dsKind === \SmartPRS\Platform\Payroll\SalaryComponent::EARNING && $dsGross += (int) ($dsLine['minor'] ?? 0);
                        in_array($dsKind, [\SmartPRS\Platform\Payroll\SalaryComponent::DEDUCTION, \SmartPRS\Platform\Payroll\SalaryComponent::RECOVERY], true) && $dsDed += (int) ($dsLine['minor'] ?? 0);
                    }

                    $dsSource[] = ['period' => (string) $dsRun['period'], 'emp_code' => (string) $dsCode, 'status' => (string) ($dsRun['status'] ?? ''), 'closed_at' => (string) ($dsRun['closed_at'] ?? ''),
                        'gross' => \SmartPRS\Platform\Support\Money::ofMinor($dsGross)->toDecimalString(), 'deductions' => \SmartPRS\Platform\Support\Money::ofMinor($dsDed)->toDecimalString(),
                        'net' => \SmartPRS\Platform\Support\Money::ofMinor((int) ($dsOwn['net_minor'] ?? $dsGross - $dsDed))->toDecimalString()];
                }
            }

            $dsBy = 'emp_code';
        } else {
            $dsSource = (string) $ds['table'] === 'employees' ? $dsPeople : $store->all((string) $ds['table']);
        }
    } catch (\Throwable $dsErr) {
        $dsSource = [];
        $dsWhy = 'This dataset cannot be read: ' . $dsErr->getMessage();
    }

    foreach ($dsSource as $dsR) {
        $dsOwner = $dsBy === 'id' ? $dsR : ($dsBy === 'emp_code' ? ($dsByCode[(string) ($dsR['emp_code'] ?? '')] ?? null) : ($dsById[(string) ($dsR['employee_id'] ?? '')] ?? null));

        if ($dsOwner === null) {
            continue;   // nobody in view owns this row
        }

        if ($dsPeriod !== null) {
            $dsOn = (string) ($dsR[$dsDate] ?? '');
            $dsOn = strlen($dsOn) === 7 ? $dsOn . '-01' : substr($dsOn, 0, 10);

            if ($dsOn < substr($dsPeriod['from'], 0, strlen($dsOn) === 7 ? 7 : 10) || $dsOn > $dsPeriod['to']) {
                continue;
            }
        }

        $dsLine = [];

        foreach (array_keys($dsColumns) as $dsC) {
            $dsLine[] = match (true) {
                in_array($dsC, ['company', 'branch', 'department', 'designation', 'team'], true) => orgValueOf($store, $dsOwner, $dsC),
                $dsC === 'employee_id' => \SmartPRS\Surface\Employment::label($dsOwner),
                $dsC === 'emp_name' && trim((string) ($dsR[$dsC] ?? '')) === '' => (string) ($dsOwner['name'] ?? ''),
                $dsC === 'type_id' && (string) $ds['table'] === 'leaves' => (string) ($dsTypes[(string) ($dsR['type_id'] ?? '')] ?? ($dsR['type_id'] ?? '')),
                isset($dsMoney[$dsC]) && trim((string) ($dsR[$dsC] ?? '')) !== '' => \SmartPRS\Surface\Amount::of((string) $dsR[$dsC])->toDecimalString(),
                default => (string) ($dsR[$dsC] ?? ''),
            };
        }

        $dsRows[] = $dsLine;
    }
}
