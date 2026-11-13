<?php declare(strict_types=1); if (!$may('tenant.settings.update')): ?>
<div class="page-header"><div><h2>Feature Flags</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Feature Flags: platform toggles. The keys are declared in
// config/feature-flags.json; a feature_flags row overrides — a global row,
// or one per tenant that wins over global. Each cell shows what resolves and
// flips it (surface/actions/feature-flag.php).
$saas = new SmartPRS\Surface\SaasPlatform($platform, __DIR__ . '/../../config');
$keys = $saas->flagKeys();
$rows = $platform->all('feature_flags');
$tenants = $platform->all('tenants');
$hasRow = static function (string $key, string $tenant) use ($rows): bool {
    foreach ($rows as $r) {
        if ((string) $r['flag_key'] === $key && (string) ($r['tenant_ref'] ?? '') === $tenant && in_array((string) ($r['value'] ?? ''), ['on', 'off'], true)) {
            return true;
        }
    }

    return false;
};
$cell = static function (string $key, string $tenant) use ($saas, $rows, $hasRow, $session): string {
    $state = $saas->flagState($key, $tenant === '' ? null : $tenant, $rows);
    $own = $hasRow($key, $tenant);
    $h = '<span class="badge badge-' . ($state === 'on' ? 'green' : 'gray') . '">' . $state . '</span>'
        . ($own ? '' : '<span class="note" style="font-size:10px"> ' . ($tenant === '' ? 'default' : 'same as all') . '</span>')
        . '<form method="post" style="display:inline;margin-left:4px"><input type="hidden" name="csrf" value="' . e($session->token()) . '">'
        . '<input type="hidden" name="action" value="feature-flag"><input type="hidden" name="op" value="set"><input type="hidden" name="key" value="' . e($key) . '">'
        . '<input type="hidden" name="tenant_id" value="' . e($tenant) . '"><input type="hidden" name="value" value="' . ($state === 'on' ? 'off' : 'on') . '">'
        . '<button class="btn btn-sm btn-outline" type="submit" title="switch ' . ($state === 'on' ? 'off' : 'on') . '"><i class="fas fa-toggle-' . ($state === 'on' ? 'on' : 'off') . '"></i></button></form>';

    if ($own && $tenant !== '') {
        $h .= '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . e($session->token()) . '">'
            . '<input type="hidden" name="action" value="feature-flag"><input type="hidden" name="op" value="clear"><input type="hidden" name="key" value="' . e($key) . '">'
            . '<input type="hidden" name="tenant_id" value="' . e($tenant) . '"><button class="btn btn-sm btn-outline" type="submit" title="follow global">↺</button></form>';
    }

    return $h;
};
?>
<?php
// ONE CLIENT AT A TIME (owner, 13 Nov 2026: "why do you keep adding all the client names in columns"): the table shows the
// setting for ALL clients; pick a client to see or change only that client's exceptions. Clients that differ are named.
$pick = (string) ($_GET['t'] ?? '');
$picked = null;
foreach ($tenants as $t) { (string) $t['id'] === $pick && $picked = $t; }
$exceptions = static function (string $key) use ($tenants, $hasRow, $saas, $rows): string {
    $out = [];
    foreach ($tenants as $t) {
        if ($hasRow($key, (string) $t['id'])) {
            $out[] = e((string) $t['name']) . ' (' . $saas->flagState($key, (string) $t['id'], $rows) . ')';
        }
    }
    return $out === [] ? '<span class="note">none</span>' : implode(', ', $out);
};
?>
<div class="page-header"><div><h2>Feature Flags</h2>
  <p>Switch product features on or off. <b>All clients</b> is the setting everyone gets. To give one client a different setting, pick the client below — only that client changes; ↺ puts it back to the all-clients setting.</p></div></div>

<form method="get" class="card" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap"><input type="hidden" name="r" value="feature-flags">
  <div><label>Change for one client</label><select name="t" onchange="this.form.submit()"><option value="">— all clients only —</option>
<?php foreach ($tenants as $t): ?><option value="<?= e((string) $t['id']) ?>"<?= $picked !== null && (string) $picked['id'] === (string) $t['id'] ? ' selected' : '' ?>><?= e((string) $t['name']) ?></option><?php endforeach; ?></select></div>
</form>

<div class="card" style="padding:0"><div class="table-wrap"><table>
  <thead><tr><th>Feature</th><th>All clients</th><?php if ($picked !== null): ?><th><?= e((string) $picked['name']) ?></th><?php endif; ?><th>Clients with their own setting</th></tr></thead><tbody>
<?php if ($keys === []): ?><tr><td colspan="4" class="note">No flags declared. Add a key to config/feature-flags.json first.</td></tr><?php endif; ?>
<?php foreach ($keys as $key => $meta): ?>
  <tr>
    <td><b><?= e((string) ($meta['label'] ?? $key)) ?></b><div class="note" style="font-size:11px;max-width:360px;white-space:normal"><?= e((string) ($meta['description'] ?? '')) ?></div></td>
    <td style="white-space:nowrap"><?= $cell((string) $key, '') ?></td>
<?php if ($picked !== null): ?>
    <td style="white-space:nowrap"><?= $cell((string) $key, (string) $picked['id']) ?></td>
<?php endif; ?>
    <td style="white-space:normal;font-size:12px"><?= $exceptions((string) $key) ?></td>
  </tr>
<?php endforeach; ?>
  </tbody></table></div></div>
