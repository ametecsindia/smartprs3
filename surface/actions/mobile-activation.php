<?php
/**
 * ISSUE A MOBILE APP ACTIVATION CODE (owner, 12 Nov 2026 — SmartEPT-style first sign-in on a phone). Administration →
 * Mobile Devices and QR Attendance → Phones. A new code replaces the person's older one; it is stored hashed in the
 * versioned customisation `mobile-activation`, emailed to the person, and shown once on screen to pass on by WhatsApp / SMS.
 */
declare(strict_types=1);

use SmartPRS\Surface\MobileActivation;

$maBack = '?r=' . (preg_match('/^(mobile-devices|qr-attendance&qp=(phones|employees)(&s=[a-z]+)?)$/', post('back')) === 1 ? post('back') : 'mobile-devices');

if (!$may('tenant.settings.update')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary;
    header('Location: ' . $maBack);
    exit;
}

// WHO: "e:<employee id>" or "u:<user id>" (a login with no employee record).
[$maKind, $maId] = array_pad(explode(':', post('subject'), 2), 2, '');
$maEmp = $maKind === 'e' && $maId !== '' ? $store->find('employees', $maId) : null;
$maUser = null;
foreach ($store->all('users') as $u) {
    if (($maEmp !== null && (string) ($u['employee_id'] ?? '') === (string) $maEmp['id']) || ($maKind === 'u' && (string) $u['id'] === $maId)) {
        $maUser = $u;
    }
}

if (($maEmp === null && $maUser === null) || ($maEmp !== null && trim((string) ($maEmp['deleted_at'] ?? '')) !== '')) {
    $_SESSION['flash_problem'] = 'Choose the employee or login.';
} elseif ($maUser === null) {
    $_SESSION['flash_problem'] = SmartPRS\Surface\Employment::label($maEmp) . ' has no SmartPRS login yet, so the app cannot sign in. Give them a login first (User Management).';
} else {
    $maAct = MobileActivation::load(__DIR__ . '/../..');
    $maSubject = $maEmp !== null ? 'e:' . $maEmp['id'] : 'u:' . $maUser['id'];
    [$maCode, $maSaved, $maUntil] = $maAct->issue((array) ($customisation->live()[MobileActivation::KEY] ?? []), $maSubject, (string) $session->user()['email'], time());
    $maWho = $maEmp !== null ? SmartPRS\Surface\Employment::label($maEmp) : (string) $maUser['email'];
    $maR = $customisation->saveRaw(MobileActivation::KEY, $maSaved, (string) ($session->user()['id'] ?? ''), (string) $session->user()['email'], 'Activation code issued for ' . $maWho);

    if (!$maR['ok']) {
        $_SESSION['flash_problem'] = $maR['why'];
    } else {
        $audit('mobile_device.activation_issued', 'mobile_devices', $maSubject, null, ['for' => $maWho, 'valid_until' => $maUntil], 'Mobile app activation code issued for ' . $maWho);
        $maTo = trim((string) (($maEmp['email'] ?? '') ?: $maUser['email']));
        $maLogin = $maEmp !== null ? SmartPRS\Surface\Employment::code($maEmp) . ' or ' . $maUser['email'] : (string) $maUser['email'];
        $maUntilShow = SmartPRS\Surface\Dates::dmy(substr($maUntil, 0, 10)) . ' ' . substr($maUntil, 11, 5);
        // THE COMPANY ADDRESS THE APP ASKS FOR (owner, 12 Nov 2026: "send the activation code with the details to register"):
        // this server + /c/<workspace slug> (the tenant's own domain when it has one).
        $maTenant = $platform->find('tenants', (string) $tenantId) ?? [];
        $maScheme = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
        $maAddr = trim((string) ($maTenant['custom_domain'] ?? '')) !== '' ? 'https://' . trim((string) $maTenant['custom_domain'])
            : $maScheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/app.php'))), '/')
                . (trim((string) ($maTenant['subdomain'] ?? '')) !== '' ? '/c/' . trim((string) $maTenant['subdomain']) : '');
        $maMail = $maTo === '' || str_ends_with(strtolower($maTo), '@smartprslocal') ? ['ok' => false, 'why' => 'no email address on file']
            : (new SmartPRS\Surface\AlertEmails($store, $customisation, __DIR__ . '/../../config/alert-emails.json'))->send([$maTo], [],
                'Your SmartPRS mobile app activation code',
                "Hello {$maWho},\n\nTo use SmartPRS on your phone:\n\n"
                . "1. Install the SmartPRS3 app (your HR team shares the install file / store link).\n"
                . "2. Open it and enter:\n   Company address: {$maAddr}\n   Employee ID or email: {$maLogin}\n   Password: your SmartPRS password (use \"Forgot password\" on the sign-in page if you do not have one)\n"
                . "3. When the app asks for the activation code, enter:\n\n      {$maCode}\n\n"
                . "The code works once, on one phone, and is valid until {$maUntilShow}. After that the app signs you straight in.\n\nHR");
        $_SESSION['flash_notice'] = 'Activation code for ' . $maWho . ': ' . $maCode . ' — valid until ' . $maUntilShow . '. Company address: ' . $maAddr . '. '
            . ($maMail['ok'] ? 'Emailed to ' . $maTo . '.' : 'Not emailed (' . rtrim((string) $maMail['why'], '.') . ') — pass it on by WhatsApp / SMS.');
        // ONE FIELD PER LINE (owner, 12 Nov 2026: "it is coming as a paragraph which is completely confusing").
        $maFields = ['Employee' => $maWho, 'Activation code' => $maCode, 'Valid until' => $maUntilShow, 'Company address' => $maAddr,
            'Employee ID or email' => $maLogin, 'Password' => 'their SmartPRS password ("Forgot password" if they have none)',
            'Email' => $maMail['ok'] ? 'Sent to ' . $maTo : 'Not sent (' . rtrim((string) $maMail['why'], '.') . ') — pass it on by WhatsApp / SMS'];
    }
}

// SENT FROM A ROW OF QR ATTENDANCE → EMPLOYEES (owner, 12 Nov 2026: "the popup should be just beside the employee"): the
// message is shown under that employee's row, not as the page-wide popup.
if (str_starts_with($maBack, '?r=qr-attendance&qp=employees')) {
    $maMsg = (string) ($_SESSION['flash_notice'] ?? $_SESSION['flash_problem'] ?? '');
    $_SESSION['ma_row'] = ['subject' => post('subject'), 'ok' => isset($_SESSION['flash_notice']), 'text' => $maMsg, 'fields' => $maFields ?? []];
    unset($_SESSION['flash_notice'], $_SESSION['flash_problem']);
    $maBack .= '#ma-' . preg_replace('/[^a-z0-9]/', '-', strtolower(post('subject')));
}

// Mobile Devices / Phones: the same one-field-per-line box, under the Send code form.
if (!str_starts_with($maBack, '?r=qr-attendance&qp=employees') && ($maFields ?? []) !== []) {
    $_SESSION['ma_card'] = $maFields;
    unset($_SESSION['flash_notice']);
}

header('Location: ' . $maBack);
exit;
