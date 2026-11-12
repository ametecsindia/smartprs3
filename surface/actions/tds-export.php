<?php
/**
 * EXPORT TDS DECLARATIONS OR INVESTMENT PROOFS (owner, 14 Oct) — Excel, exactly the filters on the
 * screen. Declarations carry one column per declared item, the rent and both regimes' tax; proofs
 * one row per uploaded proof with its verification.
 */
declare(strict_types=1);

use SmartPRS\Surface\Employment;
use SmartPRS\Surface\IncomeTax;
use SmartPRS\Surface\Sheet;
use SmartPRS\Surface\TaxDeclarations;

include __DIR__ . '/../partials/tds-lib.php';
($may('payroll.view') || isAdministrator()) || (function (): never { $_SESSION['flash_problem'] = 'Denied: exports need payroll.view.'; header('Location: ?r=tds-declaration'); exit; })();
$fy = preg_match('/^\d{4}-\d{2}$/', post('fy')) === 1 ? post('fy') : $taxDeclarations->fy();
$year = $taxDeclarations->year($fy);
$filter = [];
foreach (array_keys(ORG_FILTERS) as $k) { $v = trim(post('f_' . $k)); $filter[$k] = strcasecmp($v, 'all') === 0 ? '' : $v; }
$m = static fn (int $minor): string => SmartPRS\Platform\Support\Money::ofMinor($minor)->toDecimalString();

if (post('kind') === 'proofs') {
    $data = tdsRows($fy, $filter, '');
    $ids = [];
    foreach ($data['rows'] as $r) { $ids[(string) $r['e']['id']] = $r['e']; }
    $labels = array_column((array) $year['items'], 'label', 'code') + ['HRA' => 'House rent (HRA)'];
    $head = ['Employee ID', 'Name', 'Company', 'Branch', 'Item', 'Amount claimed', 'Status', 'Approved amount', 'Reviewed by', 'Reviewed on', 'Note', 'Uploaded on', 'File'];
    $rows = [];
    foreach ($taxDeclarations->proofs($fy) as $p) {
        $e = $ids[(string) $p['employee_id']] ?? null;
        if ($e === null || (post('status') !== '' && (string) $p['status'] !== post('status'))) { continue; }
        $rows[] = [Employment::code($e), (string) ($e['name'] ?? ''), orgValueOf($store, $e, 'company'), orgValueOf($store, $e, 'branch'),
            (string) ($labels[(string) $p['item_code']] ?? $p['item_code']), $m(IncomeTax::p($p['amount_claimed'])), ucfirst((string) $p['status']),
            (string) $p['status'] === 'approved' ? $m(IncomeTax::p($p['approved_amount'])) : '', (string) ($p['reviewed_by'] ?? ''),
            substr((string) ($p['reviewed_at'] ?? ''), 0, 10), (string) ($p['review_note'] ?? ''), substr((string) $p['created_at'], 0, 10), (string) ($p['file_name'] ?? '')];
    }
    $bytes = Sheet::report('Investment proofs ' . $fy, 'Generated ' . date('d-m-Y H:i'), $head, $rows, [5, 7], [], 'Proofs');
    $name = 'investment-proofs-' . $fy;
} else {
    $data = tdsRows($fy, $filter, in_array(post('state'), ['declared', 'pending'], true) ? post('state') : '');
    $items = array_values(array_filter((array) $year['items'], static fn (array $i): bool => !isset($i['auto']) && (string) $i['code'] !== 'NPS_EMPLOYER'));
    $head = array_merge(['Employee ID', 'Name', 'PAN', 'Company', 'Branch', 'Status', 'Regime', 'Declared on', 'Monthly rent', 'Months', 'Rent city', 'Landlord', 'Landlord PAN'],
        array_map(static fn (array $i): string => (string) $i['label'], $items),
        ['Gross salary (year)', 'Taxable — new', 'Tax — new', 'Taxable — old', 'Tax — old', 'Monthly TDS (applicable regime)']);
    $rows = [];
    foreach ($data['rows'] as $r) {
        $e = $r['e'];
        $d = $r['d'];
        $s = tdsSheets($e, $fy, $d);
        $rent = (array) ($d['rent'] ?? []);
        $rows[] = array_merge([Employment::code($e), (string) ($e['name'] ?? ''), (string) ($e['pan'] ?? ''), orgValueOf($store, $e, 'company'), orgValueOf($store, $e, 'branch'),
            $d === null ? 'Not declared' : 'Declared', ucfirst($s['regime']), $d === null ? '' : substr((string) $d['submitted_at'], 0, 10),
            (string) ($rent['monthly'] ?? ''), (string) ($rent['months'] ?? ''), (string) ($rent['city'] ?? ''), (string) ($rent['landlord_name'] ?? ''), (string) ($rent['landlord_pan'] ?? '')],
            array_map(static fn (array $i): string => ($v = IncomeTax::p($d['items'][(string) $i['code']] ?? '')) > 0 ? $m($v) : '', $items),
            [$m($s['figures']['gross']), $m($s['new']['taxable']), $m($s['new']['total']), $m($s['old']['taxable']), $m($s['old']['total']), $m((int) $s['monthly_tds'])]);
    }
    $first = 13;
    $money = array_merge([8], range($first, $first + count($items) + 5));
    $bytes = Sheet::report('TDS declarations ' . $fy, 'Generated ' . date('d-m-Y H:i') . ' · amounts in rupees', $head, $rows, $money, [], 'Declarations');
    $name = 'tds-declarations-' . $fy;
}

$audit('tds.exported', 'tax_declarations', post('kind') === 'proofs' ? 'proofs' : 'declarations', null, ['fy' => $fy, 'rows' => count($rows)], 'TDS export');
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $name . '.xlsx"');
echo $bytes;
exit;
