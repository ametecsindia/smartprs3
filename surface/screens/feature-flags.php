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
        . ($own ? '' : '<span class="note" style="font-size:10px"> ' . ($tenant === '' ? '' : 'same') . '</span>')
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
// CLIENTS DOWN, FEATURES ACROSS (owner, 13 Nov 2026): the first row is the setting every client gets; each client row shows
// "same" where it follows that row, or its own on/off with ↺ to go back to it.
?>
<div class="page-header"><div><h2>Feature Flags</h2>
  <p>Switch product features on or off. The <b>All clients</b> row is what every client gets; a client row changes that client only, and ↺ puts it back to the All clients setting.</p></div></div>

<div class="card" style="padding:0"><div class="table-wrap"><table>
  <thead><tr><th>Client</th><?php foreach ($keys as $key => $meta): ?><th title="<?= e((string) ($meta['description'] ?? '')) ?>"><?= e((string) ($meta['label'] ?? $key)) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php if ($keys === []): ?><tr><td class="note">No flags declared. Add a key to config/feature-flags.json first.</td></tr><?php endif; ?>
  <tr><td><b>All clients</b></td><?php foreach ($keys as $key => $meta): ?><td style="white-space:nowrap"><?= $cell((string) $key, '') ?></td><?php endforeach; ?></tr>
<?php foreach ($tenants as $t): ?>
  <tr><td><b><?= e((string) $t['name']) ?></b></td><?php foreach ($keys as $key => $meta): ?><td style="white-space:nowrap"><?= $cell((string) $key, (string) $t['id']) ?></td><?php endforeach; ?></tr>
<?php endforeach; ?>
  </tbody></table></div></div>
<div class="card"><h3 style="margin-top:0">What each feature does</h3><ul style="margin:0">
<?php foreach ($keys as $key => $meta): ?>  <li><b><?= e((string) ($meta['label'] ?? $key)) ?></b> — <?= e((string) ($meta['description'] ?? '')) ?></li>
<?php endforeach; ?></ul></div>
