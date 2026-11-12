<?php
/**
 * SAVE A LEAD, MOVE ITS STATUS, ADD A LIVE-DEMO ENTRY AS A LEAD, OR CONVERT
 * IT TO A TENANT. Convert creates the tenant row (code from the company
 * name, status active), stamps the lead converted with the tenant id, and
 * leaves the subscription to the Subscriptions screen — a plan and seats
 * are a commercial conversation, not a guess made here.
 */
declare(strict_types=1);

if (!$may('tenant.settings.update')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary;
    header('Location: ?r=leads');
    exit;
}

$saas = new SmartPRS\Surface\SaasPlatform($platform, __DIR__ . '/../../config');
$pipeline = $saas->leadPipeline();
$op = post('op');
$id = post('id');
$before = $id === '' || $op === 'from-demo' ? null : $platform->find('leads', $id);
$status = static fn (string $s): string => isset($pipeline['statuses'][$s]) ? $s : 'new';

if ($op === 'save') {
    $row = ['name' => trim(post('name')), 'company' => trim(post('company')) ?: null, 'designation' => trim(post('designation')) ?: null,
        'city' => trim(post('city')) ?: null, 'mobile' => trim(post('mobile')) ?: null, 'email' => trim(post('email')) ?: null,
        'employees' => trim(post('employees')) ?: null, 'challenges' => trim(post('challenges')) ?: null, 'notes' => trim(post('notes')) ?: null,
        'source' => in_array(post('source'), (array) $pipeline['sources'], true) ? post('source') : 'manual', 'status' => $status(post('status'))];

    if ($row['name'] === '') {
        $_SESSION['flash_problem'] = 'A lead needs a name.';
        header('Location: ?r=leads&edit=' . ($id === '' ? 'new' : $id));
        exit;
    }

    if ($before !== null) {
        $row['id'] = $id;
    }

    $saved = $platform->put('leads', $row);
    $audit($before === null ? 'lead.created' : 'lead.updated', 'leads', (string) $saved['id'], $before, $row, 'platform staff');
    $_SESSION['flash_notice'] = 'Lead ' . $row['name'] . ' saved.';
} elseif ($op === 'status' && $before !== null) {
    $to = $status(post('status'));
    $platform->put('leads', ['id' => $id, 'status' => $to]);
    $audit('lead.' . $to, 'leads', $id, ['status' => $before['status']], ['status' => $to], 'moved by platform staff');
    $_SESSION['flash_notice'] = (string) $before['name'] . ' is now ' . $to . '.';
} elseif ($op === 'from-demo') {
    $demo = $platform->find('demo_requests', $id);

    if ($demo === null) {
        $_SESSION['flash_problem'] = 'No such live-demo entry.';
        header('Location: ?r=leads');
        exit;
    }

    $row = ['name' => (string) $demo['name'], 'company' => (string) ($demo['company'] ?? '') ?: null, 'mobile' => (string) ($demo['phone'] ?? '') ?: null,
        'email' => (string) ($demo['email'] ?? '') ?: null, 'source' => 'live-demo', 'status' => 'new', 'notes' => 'Live demo entry #' . $id];
    $saved = $platform->put('leads', $row);
    $audit('lead.created', 'leads', (string) $saved['id'], null, $row, 'from live-demo entry ' . $id);
    $_SESSION['flash_notice'] = 'Lead ' . $row['name'] . ' added from the live demo.';
} elseif ($op === 'convert' && $before !== null) {
    if ((string) ($before['converted_tenant_id'] ?? '') !== '') {
        $_SESSION['flash_problem'] = 'Already converted to tenant #' . (string) $before['converted_tenant_id'] . '.';
        header('Location: ?r=leads');
        exit;
    }

    $name = trim((string) ($before['company'] ?? '')) ?: (string) $before['name'];
    $base = 'T-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '', $name) ?? 'NEW', 0, 12));
    $codes = array_map(static fn (array $t): string => (string) $t['code'], $platform->all('tenants'));
    $code = $base;

    for ($n = 2; in_array($code, $codes, true); $n++) {
        $code = $base . '-' . $n;
    }

    $tenant = $platform->put('tenants', ['code' => $code, 'name' => $name, 'status_code' => 'active', 'onboarded_on' => date('Y-m-d')]);
    $platform->put('leads', ['id' => $id, 'status' => 'converted', 'converted_tenant_id' => (string) $tenant['id']]);
    $audit('tenant.created', 'tenants', (string) $tenant['id'], null, ['code' => $code, 'name' => $name], 'converted from lead ' . $id);
    $audit('lead.converted', 'leads', $id, ['status' => $before['status']], ['status' => 'converted', 'converted_tenant_id' => $tenant['id']], 'tenant ' . $code);
    $_SESSION['flash_notice'] = "Tenant {$code} ({$name}) created. Add its subscription next.";
    header('Location: ?r=subscriptions&edit=new');
    exit;
} else {
    $_SESSION['flash_problem'] = 'Nothing to do: unknown lead or operation.';
}

header('Location: ?r=leads');
exit;
