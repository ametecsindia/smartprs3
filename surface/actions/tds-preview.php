<?php
/**
 * LIVE TAX PREVIEW (14 Oct): the computation sheet for what is typed on the declaration form,
 * before it is saved. Read-only — returns an HTML fragment and stores nothing.
 */
declare(strict_types=1);

use SmartPRS\Surface\IncomeTax;
use SmartPRS\Surface\TaxDeclarations;

include __DIR__ . '/../partials/tds-lib.php';
$tdWho = tdsWho();
$fy = preg_match('/^\d{4}-\d{2}$/', trim(post('fy'))) === 1 ? trim(post('fy')) : $taxDeclarations->fy();

if ($tdWho['e'] === null || $taxDeclarations->year($fy) === []) {
    http_response_code(403);
    echo '<p class="note">Nothing to work out.</p>';
    exit;
}

$items = [];
foreach ((array) ($_POST['items'] ?? []) as $c => $v) {
    try { ($m = IncomeTax::p(trim((string) $v))) > 0 && $items[(string) $c] = $m; } catch (\InvalidArgumentException) { // gate:allow-swallow a half-typed amount previews as nothing; Submit validates it
    }
}
$rent = (array) ($_POST['rent'] ?? []);
try { $annual = TaxDeclarations::rentAnnual(['monthly' => trim((string) ($rent['monthly'] ?? '')), 'months' => (string) ($rent['months'] ?? '12')]); } catch (\InvalidArgumentException) { $annual = 0; }
$decl = ['regime' => post('regime'), 'parents_senior' => post('parents_senior') === '1' ? 'yes' : 'no', 'items' => [], 'rent' => []];
$regime = in_array(post('regime'), ['new', 'old'], true) ? post('regime') : null;
echo tdsSheetHtml(tdsSheets($tdWho['e'], $fy, $decl, $items, ['annual' => $annual, 'city' => trim((string) ($rent['city'] ?? ''))], $regime));
exit;
