<?php
/**
 * SUBMIT A TDS DECLARATION (14 Oct). The window is checked HERE, not only on the screen: outside it
 * the employee is refused (fails closed); HR (payroll.run) and the Administrator may declare on
 * someone's behalf at any time. Saved as a new row; the earlier declaration is retired (law 3).
 */
declare(strict_types=1);

use SmartPRS\Surface\IncomeTax;
use SmartPRS\Surface\TaxDeclarations;

include __DIR__ . '/../partials/tds-lib.php';
$tdBack = '?r=tds-declaration';
$tdFail = static function (string $why) use (&$tdBack): never { $_SESSION['flash_problem'] = $why; header('Location: ' . $tdBack); exit; };
$tdWho = tdsWho();
$tdWho['e'] === null && $tdFail('There is no employee record you may declare for.');
$e = $tdWho['e'];
$tdBack = '?r=tds-declaration' . ($tdWho['self'] ? '' : '&id=' . rawurlencode((string) $e['id']));
$fy = preg_match('/^\d{4}-\d{2}$/', trim(post('fy'))) === 1 ? trim(post('fy')) : $taxDeclarations->fy();
$year = $taxDeclarations->year($fy);
$year === [] && $tdFail('No income-tax rules are configured for ' . $fy . '.');
$tdBack .= '&fy=' . $fy;

if (!$taxDeclarations->window('declaration', date('Y-m-d'), $e)['open'] && !$may('payroll.run') && !isAdministrator()) {
    $tdFail('The declaration window is ' . $taxDeclarations->window('declaration', date('Y-m-d'), $e)['why'] . ' — a declaration cannot be submitted outside it.');
}

$regime = post('regime');
in_array($regime, array_keys((array) $year['regimes']), true) || $tdFail('Pick the new or the old regime.');
$known = array_column((array) $year['items'], 'code');
$items = [];

foreach ((array) ($_POST['items'] ?? []) as $c => $v) {
    $v = trim((string) $v);
    if ($v === '' || !in_array((string) $c, $known, true)) { continue; }
    try { $m = IncomeTax::p($v); } catch (\InvalidArgumentException) { $tdFail('"' . $v . '" is not an amount.'); }
    $m < 0 && $tdFail('Amounts cannot be negative.');
    $m > 0 && $items[(string) $c] = SmartPRS\Platform\Support\Money::ofMinor($m)->toDecimalString();
}

$rent = [];
foreach (['monthly', 'months', 'city', 'landlord_name', 'landlord_pan', 'relationship', 'address', 'lender_name', 'lender_address', 'lender_pan', 'lta_journey'] as $k) { $rent[$k] = trim((string) ($_POST['rent'][$k] ?? '')); }
$rent['landlord_pan'] = strtoupper($rent['landlord_pan']);
$rent['lender_pan'] = strtoupper($rent['lender_pan']);
IncomeTax::p($items['HL_INTEREST'] ?? '') > 0 && $rent['lender_name'] === '' && $tdFail('Give the lender\'s name for the home-loan interest (Form 124 asks for it).');
try { $rent['monthly'] = $rent['monthly'] === '' ? '' : SmartPRS\Platform\Support\Money::ofMinor(IncomeTax::p($rent['monthly']))->toDecimalString(); } catch (\InvalidArgumentException) { $tdFail('The monthly rent is not an amount.'); }
$rent['months'] = (string) max(0, min(12, (int) ($rent['months'] === '' ? 12 : $rent['months'])));
$annual = TaxDeclarations::rentAnnual($rent);

if ($annual > 0) {
    $rent['landlord_name'] === '' && $tdFail('Give the landlord\'s name for the rent you declared.');
    if ($annual > IncomeTax::p($year['hra']['landlord_pan_above_annual_rent']) && preg_match('/^[A-Z]{5}\d{4}[A-Z]$/', $rent['landlord_pan']) !== 1) {
        $tdFail('Rent over ' . SmartPRS\Platform\Support\Money::ofMinor(IncomeTax::p($year['hra']['landlord_pan_above_annual_rent']))->format() . ' a year needs the landlord\'s PAN (10 characters, e.g. ABCDE1234F).');
    }
}

$row = $taxDeclarations->save((string) $e['id'], $fy, $regime, $items, $rent, post('parents_senior') === '1', (string) ($session->user()['email'] ?? 'unknown'));
$audit('tds.declared', 'tax_declarations', (string) $row['id'], null, ['fy' => $fy, 'regime' => $regime, 'items' => $items, 'rent_annual' => $annual], 'TDS declaration ' . $fy);
$_SESSION['flash_notice'] = 'Declaration for ' . $fy . ' submitted (' . $regime . ' regime). Monthly TDS from the next payroll is worked out on it.';
header('Location: ' . $tdBack);
exit;
