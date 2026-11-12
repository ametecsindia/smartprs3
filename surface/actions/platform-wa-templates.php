<?php
/**
 * WHATSAPP TEMPLATES (platform): seed SmartPRS 2's defaults, save, set status, delete, test, export.
 * Same rules as SmartPRS 2's WaTemplateController: lowercase_underscore names, {{n}} without gaps,
 * a content change on a submitted/approved template sends it back to Draft (it must be re-approved).
 */
declare(strict_types=1);

$wtBack = '?r=platform-wa-templates';
$wtSay = static function (bool $ok, string $msg, string $to = '') use ($wtBack): never {
    $_SESSION[$ok ? 'flash_notice' : 'flash_problem'] = $msg;
    header('Location: ' . ($to ?: $wtBack));
    exit;
};

if (!$may('tenant.settings.update') || !$isPlatformAdmin) {
    $wtSay(false, 'Platform WhatsApp templates are managed from the Super Admin console.');
}

$wtOp = post('op');
$wtNow = date('Y-m-d H:i:s');
$wtPurposes = ['welcome', 'payment', 'renewal', 'lead', 'otp', 'custom'];

if ($wtOp === 'seed') {
    if ($platform->all('wa_templates') !== []) {
        $wtSay(false, 'Templates already exist — nothing loaded.');
    }

    // SmartPRS 2's platform defaults (seedPlatformDefaults + the rev 98 OTP template), word for word.
    foreach ([
        ['welcome', 'smartprs_welcome', 'utility', 5, "Hi {{1}}, welcome to SmartPRS! Your workspace for {{2}} is ready.\nSign in at {{3}}\nEmail: {{4}}\nTemporary password: {{5}}\nPlease change the password after your first sign-in. — Team Ametecs", 'Ravi Kumar, Apex Collections Pvt Ltd, https://app.smartprs.com/login, ravi@apex.in, Xy7#kPq2Lm9w'],
        ['payment', 'smartprs_payment', 'utility', 7, "Hi {{1}}, payment received!\nPlan: {{2}} ({{3}} employees, {{4}})\nAmount paid: {{5}}\nPayment ref: {{6}}\nSubscription active until {{7}}.\nYour GST tax invoice has been emailed. — SmartPRS by Ametecs", 'Ravi Kumar, Growth, 75, Quarterly (3 months), Rs 8850.00, pay_Nx12345, 06-09-2026'],
        ['renewal', 'smartprs_renewal', 'utility', 5, "Hi {{1}}, your SmartPRS {{2}} subscription expires on {{3}} ({{4}}).\nRenew here to avoid interruption: {{5}}\n— SmartPRS by Ametecs", 'Apex Collections, Growth, 06-09-2026, in 7 days, https://app.smartprs.com'],
        ['lead', 'smartprs_lead', 'utility', 5, "New SmartPRS demo request: {{1}} from {{2}}.\nMobile: {{3}}, City: {{4}}, Employees: {{5}}.\nOpen the Super Admin console → Leads to follow up.", 'Ravi Kumar, Apex Collections, 9876543210, Vijayawada, 26 - 75'],
        ['otp', 'smartprs_otp', 'authentication', 1, '{{1}} is your SmartPRS verification code. For your security, do not share this code.', '482913'],
    ] as [$purpose, $name, $category, $vars, $body, $sample]) {
        $platform->put('wa_templates', ['purpose' => $purpose, 'name' => $name, 'language' => 'en', 'category' => $category, 'body' => $body,
            'sample_values' => $sample, 'var_count' => $vars, 'status' => 'draft', 'created_at' => $wtNow, 'updated_at' => $wtNow]);
    }

    $wtSay(true, 'SmartPRS default templates loaded as Draft — submit each in Interakt, then mark it Approved.');
}

if ($wtOp === 'export') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="smartprs-whatsapp-templates.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Template Name', 'Language', 'Category', 'Purpose', 'Variables', 'Message Body', 'Sample Values (in order)', 'Status in SmartPRS']);
    foreach ($platform->all('wa_templates') as $t) {
        fputcsv($out, [$t['name'], $t['language'], $t['category'], $t['purpose'], $t['var_count'], $t['body'], $t['sample_values'], $t['status']]);
    }
    exit;
}

$wtRow = post('id') === '' ? null : $platform->find('wa_templates', post('id'));

if ($wtOp === 'save') {
    $name = strtolower(trim(post('name')));
    $body = trim(post('body'));

    if (preg_match('/^[a-z0-9_]{1,120}$/', $name) !== 1) {
        $wtSay(false, 'Template name must be lowercase letters, numbers and underscores only (WhatsApp rule), e.g. smartprs_welcome.', $wtBack . '&edit=' . (post('id') ?: 'new') . '#form');
    }

    preg_match_all('/\{\{(\d+)\}\}/', $body, $m);
    $nums = array_map('intval', $m[1]);
    $varCount = $nums === [] ? 0 : max($nums);

    if ($body === '' || ($nums !== [] && count(array_unique($nums)) !== $varCount)) {
        $wtSay(false, 'Variables must be numbered {{1}}, {{2}}, … without gaps (and the body cannot be empty).', $wtBack . '&edit=' . (post('id') ?: 'new') . '#form');
    }

    $row = ['purpose' => in_array(post('purpose'), $wtPurposes, true) ? post('purpose') : 'custom', 'name' => $name,
        'language' => trim(post('language')) ?: 'en', 'category' => in_array(post('category'), ['utility', 'marketing', 'authentication'], true) ? post('category') : 'utility',
        'body' => $body, 'sample_values' => trim(post('sample_values')) ?: null, 'var_count' => $varCount, 'updated_at' => $wtNow];

    if ($wtRow === null) {
        $platform->put('wa_templates', $row + ['status' => 'draft', 'created_at' => $wtNow]);
        $wtSay(true, 'Template created — now copy it into the Interakt dashboard and submit it for approval.');
    }

    $changed = (string) $wtRow['status'] !== 'draft'
        && ($wtRow['body'] !== $row['body'] || $wtRow['name'] !== $row['name'] || $wtRow['language'] !== $row['language'] || $wtRow['category'] !== $row['category']);
    $platform->put('wa_templates', $row + ['id' => $wtRow['id']] + ($changed ? ['status' => 'draft', 'last_error' => null] : []));
    $wtSay(true, 'Template saved' . ($changed ? ' — content changed, so the status is back to Draft (re-submit in Interakt).' : '.'));
}

if ($wtRow === null) {
    $wtSay(false, 'No such template.');
}

if ($wtOp === 'status') {
    $to = in_array(post('status'), ['draft', 'submitted', 'approved', 'rejected'], true) ? post('status') : 'draft';
    $platform->put('wa_templates', ['id' => $wtRow['id'], 'status' => $to, 'updated_at' => $wtNow]);
    $wtSay(true, (string) $wtRow['name'] . ' is now ' . ucfirst($to) . '.');
}

if ($wtOp === 'delete') {
    $pdo->prepare('DELETE FROM wa_templates WHERE id = ?')->execute([$wtRow['id']]);
    $audit('platform.wa_template_deleted', 'wa_templates', (string) $wtRow['id'], $wtRow, null, 'platform staff');
    $wtSay(true, 'Template ' . (string) $wtRow['name'] . ' deleted.');
}

if ($wtOp === 'test') {
    $values = array_values(array_filter(array_map('trim', explode(',', (string) ($wtRow['sample_values'] ?? ''))), static fn (string $v): bool => $v !== ''));
    $r = (new SmartPRS\Surface\WhatsApp($platform, new SmartPRS\Surface\PlatformSettings($platform), dirname(__DIR__, 2) . '/.env'))
        ->send(post('mobile'), (string) $wtRow['name'], array_slice(array_pad($values, (int) $wtRow['var_count'], '-'), 0, (int) $wtRow['var_count']), 'template-test', (string) $wtRow['language']);
    $platform->put('wa_templates', ['id' => $wtRow['id'], 'last_test_at' => $wtNow, 'last_error' => $r['ok'] ? null : $r['why']]);
    $wtSay($r['ok'], $r['ok'] ? 'Test sent — ' . $r['why'] : 'Test failed: ' . $r['why']);
}

$wtSay(false, 'Nothing to do.');
