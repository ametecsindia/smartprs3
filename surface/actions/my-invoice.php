<?php
/**
 * A CLOUD SUBSCRIBER DOWNLOADS ITS OWN INVOICE AS A PDF (owner, 13 Nov 2026) — My Subscription → Invoices → PDF.
 * The tenant store only ever returns this organisation's own invoices, so another tenant's id finds nothing.
 */
declare(strict_types=1);

$miRow = null;

foreach ($may('tenant.settings.view') ? liveRows($store, 'invoices') : [] as $mi) {
    (string) $mi['id'] === post('id') && $miRow = $mi;
}

if ($miRow === null) {
    $_SESSION['flash_problem'] = 'That invoice is not available.';
    header('Location: ?r=my-subscription');
    exit;
}

$miTenant = (array) $platform->find('tenants', (string) $miRow['tenant_id']);
$miSub = $platform->find('subscriptions', (string) ($miRow['subscription_id'] ?? ''));
$miPlan = $miSub === null ? null : $platform->find('plans', (string) ($miSub['plan_id'] ?? ''));
$miPdf = (new SmartPRS\Surface\SaasPlatform($platform, __DIR__ . '/../../config'))->invoicePdf($miRow, $miTenant, liveRows($store, 'payments'), (string) ($miPlan['name'] ?? ''));

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $miRow['number']) . '.pdf"');
echo $miPdf;
exit;
