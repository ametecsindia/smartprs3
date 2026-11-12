<?php
/**
 * COMMISSION ENTRIES — BULK SAMPLE FOR ONE SCHEME (owner, 3 Nov 2026). "ALL employees" cannot share one lump-sum Target
 * Achieved: this sheet lists every active employee the scheme covers (within the Company / Branch picked, within the
 * user's reach, not paid by a Fixed Commission), with the scheme's target, a Target Achieved cell to fill, and the
 * achievement %, commission, TDS and net payable as Excel formulas built from the scheme (Compensation::excelGross /
 * excelTds — the rules Save applies). Uploaded with the register's own Upload: every row goes through Modules::save,
 * which works the money out again; the formula columns carry no field code and are not read back.
 */
declare(strict_types=1);

use SmartPRS\Surface\Compensation;
use SmartPRS\Surface\Sheet;

$csModule = $modules->find('commissions');
$csBack = '?r=' . moduleRoute('commissions');

if ($csModule === null || !$may($modules->permission($csModule, 'edit'))) {
    $_SESSION['flash_problem'] = 'Denied: you cannot add commission entries.';
    header('Location: ' . $csBack);
    exit;
}

$csScheme = trim(post('scheme_id')) === '' ? null : $store->find('incentive_schemes', post('scheme_id'));

if ($csScheme === null) {
    $_SESSION['flash_problem'] = 'Select the scheme to download the sample file.';
    header('Location: ' . $csBack);
    exit;
}

$csMonth = preg_match('/^\d{4}-\d{2}/', post('month')) === 1 ? substr(post('month'), 0, 7) : date('Y-m');

if (Compensation::excelGross($csScheme, 'A1', 'B1') === null) {
    $_SESSION['flash_problem'] = 'Scheme "' . (string) ($csScheme['name'] ?? '') . '" does not work from a Target Achieved — '
        . (Compensation::isFixedCommission($csScheme) ? 'it is a Fixed Commission, paid automatically every month.' : 'enter its amount once on the form.');
    header('Location: ' . $csBack);
    exit;
}

$csRules = (array) ((new Compensation($store, __DIR__ . '/../../config/compensation.json'))->config()['commission'] ?? []);
$csRoles = (array) (orgNames($store)['designation_roles'] ?? []);
$csAllSch = liveRows($store, 'incentive_schemes');
$csCo = trim(post('employer_company_id'));
$csBr = trim(post('branch_id'));
$csIncentive = strcasecmp(trim((string) ($csScheme['scheme_kind'] ?? '')), 'Incentive') === 0;

// the labels the importer maps back to ids — the register's own dropdown labels
$csSpec = [];
foreach ($modules->fields($csModule)['fields'] as $f) {
    $csSpec[(string) $f['name']] = $f;
}
$csLabel = static function (string $field, string $id) use ($csSpec): string {
    foreach ((array) ($csSpec[$field]['lookup'] ?? []) as $o) {
        if ((string) $o['id'] === $id) {
            return (string) $o['label'];
        }
    }

    return $id;
};

$csTarget = trim((string) ($csScheme['target_amount'] ?? ''));
// a figure the sheet must hold as a NUMBER (Sheet::isAmount wants decimals), exact as typed: 2 → "2.00", 2.125 → "2.125"
$csNum = static function (string $x): string { $t = Compensation::rateText($x); return str_contains($t, '.') ? $t : $t . '.00'; };
$csTdsKind = trim((string) ($csScheme['scheme_kind'] ?? '')) !== '';
$csData = [];

foreach (liveRows($store, 'employees') as $e) {
    if (!in_array(strtolower(trim((string) ($e['status'] ?? ''))), ['', 'active'], true)
        || ($csCo !== '' && (string) ($e['employer_company_id'] ?? '') !== $csCo)
        || ($csBr !== '' && (string) ($e['branch_id'] ?? '') !== $csBr)
        || !($csTdsKind || Compensation::flatTds($e, $csRules))   // a Setup scheme: its own Applies to Salary Type decides (eligible)
        || !Compensation::eligible($csScheme, $e, $csRoles)
        || Compensation::fixedSchemeFor($csAllSch, $e, $csRoles, $csMonth) !== null
        || !$modules->rowVisible($csModule, ['employee_id' => (string) $e['id']])) {
        continue;
    }
    // TDS % on this row: the scheme's (Commission / Incentive Setup); an older scheme without a kind: Salary Type decides
    $tdsRate = $csTdsKind
        ? (Compensation::tdsApplicable($csScheme) ? (trim((string) ($csScheme['tds_rate'] ?? '')) ?: '0') : '0')
        : (Compensation::flatTds($e, $csRules) ? (trim((string) ($csScheme['tds_rate'] ?? '')) ?: (string) ($csRules['default_tds_rate'] ?? '0')) : '0');
    $csData[] = [
        $csLabel('employee_id', (string) $e['id']),
        $csLabel('employer_company_id', (string) ($e['employer_company_id'] ?? '')),
        $csLabel('branch_id', (string) ($e['branch_id'] ?? '')),
        $csLabel('scheme_id', (string) $csScheme['id']),
        $csMonth,
        'Simple claim',
        'Pending',
        $csTarget === '' ? '' : $csNum($csTarget),
        '',   // Target Achieved — to fill
        '', '',
        $csNum($tdsRate),
        '', '',
        '',
    ];
}

if ($csData === []) {
    // SAY WHY NOBODY IS LISTED (owner, 3 Nov 2026): who the scheme is for, and how many people match each part of it.
    $csWho = [];
    foreach (['employer_company_id' => 'Company', 'branch_id' => 'Branch', 'department_id' => 'Department', 'team_id' => 'Team', 'designation_id' => 'Designation', 'employee_id' => 'Employee'] as $csK => $csN) {
        $csV = trim((string) ($csScheme[$csK] ?? ''));
        $csV === '' || $csWho[] = $csN . ' ' . (isset($csSpec[$csK]) ? $csLabel($csK, $csV) : $csV);
    }
    trim((string) ($csScheme['scope_role'] ?? '')) === '' || $csWho[] = 'Role ' . (string) $csScheme['scope_role'];
    $csInScope = 0;
    $csTyped = 0;
    $csFixed = 0;
    $csTypes = [];
    foreach (liveRows($store, 'employees') as $e) {
        if (!in_array(strtolower(trim((string) ($e['status'] ?? ''))), ['', 'active'], true)
            || !Compensation::eligible(['eligible_salary_types' => ''] + $csScheme, $e, $csRoles)) {
            continue;   // outside the scheme's Who it is for (Salary Type aside)
        }
        $csInScope++;
        $csTypes[trim((string) ($e['salary_type'] ?? '')) ?: 'not set'] = ($csTypes[trim((string) ($e['salary_type'] ?? '')) ?: 'not set'] ?? 0) + 1;
        if ($csTdsKind || Compensation::flatTds($e, $csRules)) {
            Compensation::eligible($csScheme, $e, $csRoles) && $csTyped++;
            Compensation::fixedSchemeFor($csAllSch, $e, $csRoles, $csMonth) !== null && $csFixed++;
        }
    }
    $csTypeText = implode(', ', array_map(static fn ($t, $n) => $n . ' ' . $t, array_keys($csTypes), $csTypes));
    $_SESSION['flash_problem'] = 'Scheme "' . (string) ($csScheme['name'] ?? '') . '" is for ' . ($csWho === [] ? 'everyone' : implode(' · ', $csWho))
        . ' · Salary Type ' . (trim((string) ($csScheme['eligible_salary_types'] ?? '')) ?: 'any') . '. '
        . ($csInScope === 0 ? 'No active employee works there, so there is nobody to list.'
            : $csInScope . ' active employee(s) work there (' . $csTypeText . '), but '
              . ($csTyped === 0 ? 'none has the Salary Type this scheme pays.' : ($csFixed >= $csTyped ? 'they are all paid by a Fixed Commission.' : 'none of them is within your reach.')))
        . ' Change the scheme\'s Who it is for in Commission Setup, or the employees\' Branch / Salary Type in the Directory, then download again.';
    header('Location: ' . $csBack);
    exit;
}

// columns: A Employee · B Company · C Branch · D Scheme · E Month · F Claim type · G Status · H Target · I Target Achieved ·
//          J Achievement % · K Commission · L TDS % · M TDS · N Net · O Short description
$csHead = ['Employee *', 'Company', 'Branch', 'Scheme *', 'Payroll Month *', 'Claim Type *', 'Status', 'Target per person (₹)', 'Target Achieved (₹) * — TYPE THIS',
    'Achievement %', 'Commission — Gross (₹)', 'TDS %', 'TDS (₹)', 'Net Payable (₹)', 'Short Description'];
$csCodes = ['employee_id', 'employer_company_id', 'branch_id', 'scheme_id', 'month', 'claim_type', 'status', '', 'base_amount', '', '', '', '', '', 'remarks'];
// the hint row: the importer's own hint for each coded cell (so it is skipped on upload), plain words elsewhere
$csHint = array_map(static fn (string $code, string $words): string => $code !== '' && isset($csSpec[$code]) ? \SmartPRS\Surface\ModuleBulk::hint($csSpec[$code]) : $words, $csCodes,
    ['', '', '', '', '', '', '', 'From the scheme', '', 'Worked out', 'Worked out by the scheme', 'From the scheme', 'Worked out', 'Worked out — Commission − TDS', '']);
$csLists = [];
$csStatus = (array) ($csSpec['status']['options'] ?? []);
$csStatus === [] || $csLists[6] = array_values(array_map('strval', array_is_list($csStatus) ? $csStatus : array_values($csStatus)));

$csName = preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($csScheme['name'] ?? 'scheme')));
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="commission-entries-' . trim((string) $csName, '-') . '-' . $csMonth . '.xlsx"');
echo Sheet::xlsx([$csHead, $csCodes, $csHint, ...$csData], $csLists, 3, [], [], [], [
    9 => 'IF(OR(I{r}="",N(H{r})=0),"",ROUND(I{r}/H{r}*100,2))',
    10 => (string) Compensation::excelGross($csScheme, 'I{r}', 'H{r}'),
    12 => Compensation::excelTds($csScheme, 'K{r}', 'L{r}'),
    13 => 'IF(K{r}="","",K{r}-M{r})',
], [], [0 => 30.0, 1 => 22.0, 2 => 18.0, 3 => 24.0, 8 => 20.0, 10 => 18.0, 13 => 18.0], [], [], 'Commission Entries');
exit;
