<?php
/**
 * THE ON-PREM DESK (Super Admin) — SmartPRS 2's OnpremClientController, op by op:
 *
 *   save-client      add / edit the client (price, GST state, term or fixed expiry, expiry mode, seats)
 *   pay              record a payment (NEFT / UPI / cheque / cash / gateway)
 *   invoice          give the sale an invoice number (shared series with SaaS invoices) and open it
 *   invoice-pdf      download the invoice as a PDF
 *   invoice-email    email the PDF invoice with the pay-online link (to the client, or an address typed)
 *   partial          tick / untick "activate on partial payment"
 *   issue            generate the online License Code (SPRS-XXXX-…) — paid in full, or partial ticked
 *   lic              generate a signed, machine-locked .lic file and download it
 *   lic-download     download the last generated .lic again
 *   renew            extend the live licence: N months or until a date
 *   shift            release the machine binding so the client can activate a new server
 *   revoke           revoke the live licence (activation, check-ins and updates refused)
 *   delete           delete the client and its licence records (SmartPRS 2 hard delete)
 *
 * Every move is a licence_events row and an audit entry.
 */
declare(strict_types=1);

if (!$may('tenant.settings.update')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary;
    header('Location: ?r=onprem');
    exit;
}

$saas = new SmartPRS\Surface\SaasPlatform($platform, __DIR__ . '/../../config');
$authority = new SmartPRS\Surface\LicenceAuthority($platform, dirname(__DIR__, 2));
$declared = (array) $saas->declared()['onprem'];
$op = post('op');
$clientId = post('client');
$client = $clientId === '' ? null : $platform->find('onprem_clients', $clientId);
$today = date('Y-m-d');
$by = (string) ($session->user()['email'] ?? 'platform');
$back = '?r=onprem' . ($clientId === '' ? '' : '&client=' . $clientId);
$go = static function (string $kind, string $msg, string $to): never {
    $_SESSION[$kind === 'ok' ? 'flash_notice' : 'flash_problem'] = $msg;
    header('Location: ' . $to);
    exit;
};
$isDate = static fn (string $v): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1;
// THE CLIENT'S EMAILS (SmartPRS 2: key, .lic and invoice are emailed to the client, fail-soft — the screen still shows them).
$mailClient = static function (string $kind, array $v, array $attachments = [], string $to = '') use ($client, $platform): string {
    $to = trim($to) ?: trim((string) ($client['email'] ?? ''));

    if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        return ' (not emailed: the client has no email address)';
    }

    [$subject, $text] = SmartPRS\Surface\LicenceAuthority::clientEmail($kind, $client, $v);
    $r = (new SmartPRS\Surface\PlatformMail(new SmartPRS\Surface\PlatformSettings($platform), dirname(__DIR__, 2) . '/.env'))->send([$to], $subject, $text, [], $attachments);

    return $r['ok'] ? ' Emailed to ' . $to . '.' : ' (not emailed: ' . $r['why'] . ')';
};
$payLink = static fn (array $c): string => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'smartprs.com')
    . '/licence.php?t=' . rawurlencode((string) ($c['invoice_token'] ?? ''));

if ($op === 'save-client') {
    $id = post('id');
    $before = $id === '' ? null : $platform->find('onprem_clients', $id);

    try {
        $price = SmartPRS\Surface\Amount::of(post('price') === '' ? '0' : post('price'));
    } catch (\InvalidArgumentException $e) {
        $go('bad', $e->getMessage(), '?r=onprem&edit=' . ($id === '' ? 'new' : $id) . '#form');
    }

    $email = trim(post('email'));
    $row = ['company' => trim(post('company')), 'contact_name' => trim(post('contact_name')) ?: null,
        'email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null, 'mobile' => trim(post('mobile')) ?: null,
        'gstin' => strtoupper(trim(post('gstin'))) ?: null, 'state' => trim(post('state')) ?: null, 'address' => trim(post('address')) ?: null,
        'notes' => trim(post('notes')) ?: null, 'edition' => 'full',
        'employee_band' => in_array(post('employee_band'), (array) $declared['employee_bands'], true) ? post('employee_band') : null,
        'price' => $price->toDecimalString(), 'amc_percent' => post('amc_percent') === '' ? '18' : (string) max(0, min(100, (int) post('amc_percent'))),
        'licence_term_months' => post('licence_term_months') === '' ? null : max(1, min(120, (int) post('licence_term_months'))),
        'licence_expires_on' => $isDate(post('licence_expires_on')) ? post('licence_expires_on') : null,
        'expiry_mode' => post('expiry_mode') === 'notify' ? 'notify' : 'renew',
        'seats' => (int) post('seats') > 0 ? (int) post('seats') : null,
        'activate_on_partial' => post('activate_on_partial') === '1' ? 1 : 0];

    if ($row['company'] === '') {
        $go('bad', 'A client needs a company name.', '?r=onprem&edit=' . ($id === '' ? 'new' : $id) . '#form');
    }

    if ($before !== null) {
        $row['id'] = $id;
    } else {
        $row['paid_total'] = '0';
    }

    $saved = $platform->put('onprem_clients', $row);
    $live = $authority->liveFor((string) $saved['id']);

    // The expiry mode and seat cap travel to the live licence (SmartPRS 2), so the next check-in carries them.
    if ($live !== null) {
        $platform->put('licences', ['id' => $live['id'], 'expiry_mode' => $row['expiry_mode'], 'seats' => $row['seats']]);
    }

    $audit($before === null ? 'onprem_client.created' : 'onprem_client.updated', 'onprem_clients', (string) $saved['id'], $before, $row, 'platform staff');
    $go('ok', 'Client ' . $row['company'] . ' saved.', '?r=onprem&client=' . (string) $saved['id']);
}

if ($client === null) {
    $go('bad', 'Nothing to do: unknown client.', '?r=onprem');
}

$live = $authority->liveFor($clientId);
$totals = SmartPRS\Surface\LicenceAuthority::totals($client, $saas);
$paid = SmartPRS\Surface\Amount::of($client['paid_total'] ?? '0');

if ($op === 'pay') {
    try {
        $amount = SmartPRS\Surface\Amount::of(post('amount'));
    } catch (\InvalidArgumentException $e) {
        $go('bad', $e->getMessage(), $back);
    }

    if ($amount->isZero() || $amount->isNegative()) {
        $go('bad', 'A payment is a positive amount.', $back);
    }

    $row = ['client_id' => $clientId, 'amount' => $amount->toDecimalString(),
        'mode' => in_array(post('mode'), (array) $declared['payment_modes'], true) ? post('mode') : 'bank transfer',
        'reference' => trim(post('reference')) ?: null, 'paid_on' => $isDate(post('paid_on')) ? post('paid_on') : $today, 'entered_by' => $by];
    $saved = $platform->put('onprem_payments', $row);
    $total = $paid->plus($amount);
    $platform->put('onprem_clients', ['id' => $clientId, 'paid_total' => $total->toDecimalString()]);
    $audit('onprem_payment.recorded', 'onprem_payments', (string) $saved['id'], ['paid_total' => $client['paid_total']], ['paid_total' => $total->toDecimalString()] + $row, $row['mode']);
    $go('ok', $amount->format() . ' recorded; ' . $total->format() . ' of ' . $totals['total']->format() . ' (incl. GST) paid.', $back);
}

if ($op === 'invoice') {
    if ($totals['price']->isZero()) {
        $go('bad', 'Set the licence price first — the invoice needs an amount.', $back);
    }

    if (trim((string) ($client['invoice_no'] ?? '')) === '') {
        $seq = 1 + count(array_filter($platform->all('invoices'), static fn (array $i): bool => trim((string) ($i['number'] ?? '')) !== ''))
            + count(array_filter($platform->all('onprem_clients'), static fn (array $c): bool => trim((string) ($c['invoice_no'] ?? '')) !== ''));
        $platform->put('onprem_clients', ['id' => $clientId, 'invoice_no' => $saas->invoiceNumber($seq, $today), 'invoice_date' => $today,
            'invoice_token' => bin2hex(random_bytes(16))]);
        $audit('onprem_client.invoiced', 'onprem_clients', $clientId, null, ['invoice_no' => $saas->invoiceNumber($seq, $today)], 'licence invoice');
    }

    $client = (array) $platform->find('onprem_clients', $clientId);

    if (trim((string) ($client['invoice_token'] ?? '')) === '') {
        $client['invoice_token'] = bin2hex(random_bytes(16));
        $platform->put('onprem_clients', ['id' => $clientId, 'invoice_token' => $client['invoice_token']]);
    }

    header('Location: ?r=onprem-invoice&client=' . $clientId);
    exit;
}

// THE INVOICE AS A PDF, DOWNLOADED OR EMAILED (owner, 13 Nov 2026: "it should be PDF and send email option available").
if ($op === 'invoice-pdf' || $op === 'invoice-email') {
    if (trim((string) ($client['invoice_no'] ?? '')) === '') {
        $go('bad', 'Raise the invoice first.', $back);
    }

    $pdf = SmartPRS\Surface\LicenceAuthority::invoicePdf($client, $saas);
    $file = preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $client['invoice_no']) . '.pdf';

    if ($op === 'invoice-pdf') {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        echo $pdf;
        exit;
    }

    $to = trim(post('to'));

    if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        $_SESSION['flash_problem'] = "'{$to}' is not an email address.";
        header('Location: ?r=onprem-invoice&client=' . $clientId);
        exit;
    }

    if (trim((string) ($client['invoice_token'] ?? '')) === '') {
        $client['invoice_token'] = bin2hex(random_bytes(16));
        $platform->put('onprem_clients', ['id' => $clientId, 'invoice_token' => $client['invoice_token']]);
    }

    // SmartPRS 2 invoice(): the PDF attached, with the pay-online link (public/licence.php, Razorpay).
    $sent = $mailClient('invoice', ['number' => (string) $client['invoice_no'], 'price' => $totals['price']->format(), 'tax' => $totals['tax']->format(),
        'total' => $totals['total']->format(), 'balance' => $totals['balance']->format(), 'link' => $payLink($client)], [[$file, $pdf]], $to);
    $ok = str_starts_with($sent, ' Emailed');
    $ok && $audit('onprem_client.invoice_emailed', 'onprem_clients', $clientId, null, ['invoice_no' => $client['invoice_no'], 'to' => $to ?: $client['email']], 'licence invoice emailed');
    $_SESSION[$ok ? 'flash_notice' : 'flash_problem'] = 'Invoice ' . (string) $client['invoice_no'] . ($ok ? ' (PDF).' . $sent : ' NOT emailed' . $sent);
    header('Location: ?r=onprem-invoice&client=' . $clientId);
    exit;
}

if ($op === 'partial') {
    $to = (int) ($client['activate_on_partial'] ?? 0) === 1 ? 0 : 1;
    $platform->put('onprem_clients', ['id' => $clientId, 'activate_on_partial' => $to]);
    $audit('onprem_client.partial', 'onprem_clients', $clientId, ['activate_on_partial' => (int) ($client['activate_on_partial'] ?? 0)], ['activate_on_partial' => $to], 'platform staff');
    $go('ok', $to === 1 ? 'Activation on partial payment approved.' : 'Activation now needs full payment.', $back);
}

if ($op === 'issue' || $op === 'lic') {
    $fullyPaid = !$totals['price']->isZero() && $paid->compareTo($totals['total']) >= 0;

    if (!$fullyPaid && (int) ($client['activate_on_partial'] ?? 0) !== 1) {
        $go('bad', 'Key NOT generated: payment is partial (' . $paid->format() . ' of ' . $totals['total']->format() . ' incl. GST). Tick "Activate on partial payment" if you approve.', $back);
    }

    $expiry = SmartPRS\Surface\LicenceAuthority::resolveExpiry($client);
    $mode = (string) ($client['expiry_mode'] ?? 'renew') === 'notify' ? 'notify' : 'renew';
    $seats = post('seats') !== '' ? max(0, (int) post('seats')) : (int) ($client['seats'] ?? 0);

    if ($op === 'issue') {
        if ($live !== null) {
            $go('bad', 'This client already has a live licence — revoke it first if you really need a new key.', $back);
        }

        $key = SmartPRS\Surface\LicenceAuthority::generateKey();
        $lic = $authority->issue($clientId, $key, $expiry, $mode, $seats ?: null, $key, $by);
        $audit('licence.issued', 'licences', (string) $lic['id'], null, ['key_last4' => $lic['key_last4'], 'amc_expires_on' => $expiry], 'client ' . (string) $client['company']);
        $go('ok', 'License Code generated for ' . (string) $client['company'] . ' — valid till ' . SmartPRS\Surface\Dates::dmy($expiry) . '. It is shown on the client card (Show key).'
            . $mailClient('key', ['key' => $key, 'until' => SmartPRS\Surface\Dates::dmy($expiry)]), $back);
    }

    if (!$authority->canSign()) {
        $go('bad', 'Cannot generate a .lic: the signing key storage/keys/license_private.pem is missing on this server (see deploy/DEPLOY.md, step "Licence key").', $back);
    }

    $fp = trim(post('fingerprint'));

    if ($fp === '' && $live !== null) {
        $fp = $authority->knownFingerprint($live);
    }

    if ($fp === '') {
        $go('bad', 'A .lic is locked to one server: paste the fingerprint shown on the client\'s Activation screen.', $back);
    }

    $key = ($live !== null ? $authority->plainKey($live) : null) ?? SmartPRS\Surface\LicenceAuthority::generateKey();
    $token = $authority->sign(['key' => $key, 'company' => (string) $client['company'], 'device_limit' => $seats ?: null,
        'expires_at' => $expiry, 'fingerprint' => $fp]);
    $lic = $authority->issue($clientId, $key, $expiry, $mode, $seats ?: null, $token, $by);
    $platform->put('licences', ['id' => $lic['id'], 'fingerprint' => $fp, 'status' => 'active']);
    $authority->event((string) $lic['id'], 'lic_generated', '.lic for server ' . $fp . ' by ' . $by);
    $audit('licence.lic_generated', 'licences', (string) $lic['id'], null, ['fingerprint' => $fp, 'expires_at' => $expiry, 'seats' => $seats], 'client ' . (string) $client['company']);
    $mailClient('lic', ['key' => $key, 'seats' => $seats > 0 ? (string) $seats : 'unlimited', 'until' => SmartPRS\Surface\Dates::dmy($expiry), 'token' => $token]);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . SmartPRS\Surface\LicenceAuthority::filename($key) . '"');
    echo $token . "\n";
    exit;
}

if ($op === 'lic-download') {
    $stored = $live === null ? null : $authority->reveal($live);

    if ($stored === null || !SmartPRS\Surface\Licence::looksLikeLicenceFile($stored)) {
        $go('bad', 'No stored .lic for ' . (string) $client['company'] . ' yet — use "Generate .lic file" once; it can be re-downloaded afterwards.', $back);
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . SmartPRS\Surface\LicenceAuthority::filename(SmartPRS\Surface\Licence::keyInsideToken($stored)) . '"');
    echo $stored . "\n";
    exit;
}

if ($op === 'delete') {
    foreach ($platform->all('licences') as $l) {
        if ((string) $l['client_id'] === $clientId) {
            $pdo?->prepare('DELETE FROM licence_events WHERE licence_id = ?')->execute([$l['id']]);
            $pdo?->prepare('DELETE FROM licence_devices WHERE licence_id = ?')->execute([$l['id']]);
        }
    }

    $pdo?->prepare('DELETE FROM licences WHERE client_id = ?')->execute([$clientId]);
    $pdo?->prepare('DELETE FROM onprem_payments WHERE client_id = ?')->execute([$clientId]);
    $pdo?->prepare('DELETE FROM onprem_clients WHERE id = ?')->execute([$clientId]);
    $audit('onprem_client.deleted', 'onprem_clients', $clientId, $client, null, 'deleted with its licence records by ' . $by);
    $go('ok', 'Client "' . (string) $client['company'] . '" and its licence records were deleted.', '?r=onprem');
}

if ($live === null) {
    $go('bad', 'No live licence for this client.', $back);
}

$lid = (string) $live['id'];

if ($op === 'renew') {
    $until = post('renew_until');
    $months = (int) post('renew_months');

    if ($isDate($until) && $until >= $today) {
        $new = $until;
    } else {
        $base = max((string) ($live['amc_expires_on'] ?? ''), $today);
        $new = (new DateTimeImmutable(substr($base, 0, 10)))->modify('+' . ($months > 0 ? $months : ((int) ($client['licence_term_months'] ?? 0) ?: 12)) . ' months')->format('Y-m-d');
    }

    $platform->put('licences', ['id' => $lid, 'amc_expires_on' => $new]);
    $authority->event($lid, 'amc_renewed', 'Licence extended to ' . $new . ' by ' . $by);
    $audit('licence.renewed', 'licences', $lid, ['amc_expires_on' => $live['amc_expires_on']], ['amc_expires_on' => $new], 'platform staff');
    $go('ok', 'Licence renewed till ' . SmartPRS\Surface\Dates::dmy($new) . '. An online install picks it up at its next daily check-in; a .lic install needs a fresh .lic.', $back);
}

if ($op === 'shift') {
    $platform->put('licences', ['id' => $lid, 'fingerprint' => null, 'server_name' => null, 'reactivations_used' => (int) ($live['reactivations_used'] ?? 0) + 1]);
    $authority->releaseDevices($lid);
    $authority->event($lid, 'machine_shifted', 'Binding released by ' . $by . ' (was ' . ((string) ($live['fingerprint'] ?? '') ?: 'unbound') . ')');
    $audit('licence.machine_released', 'licences', $lid, ['fingerprint' => $live['fingerprint']], ['fingerprint' => null], 'platform staff');
    $go('ok', 'Machine binding released — the client can activate on the NEW server now.', $back);
}

if ($op === 'revoke') {
    $platform->put('licences', ['id' => $lid, 'status' => 'revoked']);
    $authority->event($lid, 'revoked', 'Revoked by ' . $by);
    $audit('licence.revoked', 'licences', $lid, ['status' => $live['status']], ['status' => 'revoked'], 'platform staff');
    $go('ok', 'Licence revoked — activation, check-ins and updates are refused for it; the install stops at its next daily check.', $back);
}

$go('bad', 'Nothing to do: unknown operation.', $back);
