<?php declare(strict_types=1); if (!$may('tenant.settings.update')): ?>
<div class="page-header"><div><h2>On-Prem Clients &amp; Licences</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's On-Prem desk (Super Admin): sale → payment (+GST) → invoice → licence key or
// machine-locked .lic → renew / move machine / revoke, with the licence history. Written by
// surface/actions/onprem.php; the licence server itself is public/update.php.
$saas = new SmartPRS\Surface\SaasPlatform($platform, __DIR__ . '/../../config');
$authority = new SmartPRS\Surface\LicenceAuthority($platform, dirname(__DIR__, 2));
$declared = (array) $saas->declared()['onprem'];
$q = strtolower(trim((string) ($_GET['q'] ?? '')));
$clients = array_values(array_filter($platform->all('onprem_clients'), static fn (array $c): bool => $q === ''
    || str_contains(strtolower(implode(' ', [(string) $c['company'], (string) ($c['contact_name'] ?? ''), (string) ($c['email'] ?? ''), (string) ($c['mobile'] ?? '')])), $q)));
usort($clients, static fn (array $a, array $b): int => (int) $b['id'] <=> (int) $a['id']);
$devices = $events = $payments = [];
foreach ($platform->all('licence_devices') as $d) { $devices[(string) $d['licence_id']][] = $d; }
foreach ($platform->all('licence_events') as $ev) { $events[(string) $ev['licence_id']][] = $ev; }
foreach ($platform->all('onprem_payments') as $p) { $payments[(string) $p['client_id']][] = $p; }
$editing = isset($_GET['edit']) ? $platform->find('onprem_clients', (string) $_GET['edit']) : null;
$v = static fn (string $k): string => (string) ($editing[$k] ?? '');
$today = date('Y-m-d');
$Amt = SmartPRS\Surface\Amount::class;
$states = array_keys((array) $saas->gst()['state_codes']);
$openClient = (string) ($_GET['client'] ?? '');
$dmy = static fn (?string $d): string => $d === null || $d === '' ? '—' : SmartPRS\Surface\Dates::dmy(substr($d, 0, 10));
$hidden = static fn (string $op, string $cid): string => '<input type="hidden" name="csrf" value="' . e($session->token()) . '"><input type="hidden" name="action" value="onprem"><input type="hidden" name="op" value="' . e($op) . '"><input type="hidden" name="client" value="' . e($cid) . '">';
?>
<div class="page-header"><div><h2>On-Prem Clients &amp; Licences</h2>
  <p><?= count($clients) ?> client(s). Sale → payment → invoice → License Code or machine-locked .lic → renew / move / revoke, one chain, recorded. Installs activate against <code><?= e((string) (($_SERVER['HTTPS'] ?? '') !== '' ? 'https' : 'http') . '://' . (string) ($_SERVER['HTTP_HOST'] ?? '')) ?>/update/activate</code>.</p></div>
  <div class="actions"><form method="get" style="display:inline-flex;gap:6px"><input type="hidden" name="r" value="onprem"><input name="q" value="<?= e((string) ($_GET['q'] ?? '')) ?>" placeholder="Search company, contact, email"><button class="btn btn-outline btn-sm" type="submit"><i class="fas fa-search"></i></button></form>
    <a class="btn btn-primary" href="?r=onprem&edit=new#form"><i class="fas fa-plus"></i> Add client</a></div></div>

<?php if (!$authority->canSign()): ?>
<div class="card"><div class="problem" style="margin:0"><b>.lic signing is off on this server:</b> <code>storage/keys/license_private.pem</code> is missing. Online License Codes still work. Copy the private key here to issue offline .lic files (deploy/DEPLOY.md, “Licence key”).</div></div>
<?php endif; ?>

<?php if (isset($_GET['edit'])): ?>
<div class="card" id="form"><h3 style="margin-top:0"><?= $editing === null ? 'New on-prem client' : 'Edit ' . e($v('company')) ?></h3>
  <form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="onprem"><input type="hidden" name="op" value="save-client"><input type="hidden" name="id" value="<?= e($v('id')) ?>">
    <div class="row" style="flex-wrap:wrap;gap:10px">
      <div style="flex:1;min-width:220px"><label>Company</label><input name="company" required value="<?= e($v('company')) ?>" style="width:100%"></div>
      <div><label>Contact</label><input name="contact_name" value="<?= e($v('contact_name')) ?>"></div>
      <div><label>Email</label><input name="email" type="email" value="<?= e($v('email')) ?>"></div>
      <div><label>Mobile</label><input name="mobile" type="tel" value="<?= e($v('mobile')) ?>"></div>
      <div><label>GSTIN</label><input name="gstin" value="<?= e($v('gstin')) ?>" style="width:170px"></div>
      <div><label>State (GST)</label><select name="state"><option value="">— choose —</option><?php foreach ($states as $s): ?><option<?= $s === $v('state') ? ' selected' : '' ?>><?= e((string) $s) ?></option><?php endforeach; ?></select></div>
      <div><label>Employee band</label><select name="employee_band"><option value="">—</option><?php foreach ((array) $declared['employee_bands'] as $b): ?><option<?= $b === $v('employee_band') ? ' selected' : '' ?>><?= e((string) $b) ?></option><?php endforeach; ?></select></div>
      <div><label>Employee cap (seats)</label><input name="seats" type="number" min="0" value="<?= e($v('seats')) ?>" style="width:110px" placeholder="0 = no cap"></div>
      <div><label>Licence price ₹ (excl. GST)</label><input name="price" inputmode="decimal" pattern="\d+(\.\d{1,2})?" value="<?= e($v('price') === '' ? '' : $Amt::of($v('price'))->toDecimalString()) ?>"></div>
      <div><label>AMC %</label><input name="amc_percent" type="number" min="0" max="100" value="<?= e($v('amc_percent') === '' ? '18' : (string) (int) $v('amc_percent')) ?>" style="width:80px"></div>
      <div><label>Licence term (months)</label><input name="licence_term_months" type="number" min="1" max="120" value="<?= e($v('licence_term_months')) ?>" style="width:90px" placeholder="12"></div>
      <div><label>…or valid till</label><input name="licence_expires_on" type="date" value="<?= e(substr($v('licence_expires_on'), 0, 10)) ?>"></div>
      <div><label>On expiry</label><select name="expiry_mode"><?php foreach ((array) ($declared['expiry_modes'] ?? []) as $mk => $ml): ?><option value="<?= e((string) $mk) ?>"<?= $mk === ($v('expiry_mode') ?: 'renew') ? ' selected' : '' ?> title="<?= e((string) $ml) ?>"><?= $mk === 'renew' ? 'Stop until renewed' : 'Keep running, show notice' ?></option><?php endforeach; ?></select></div>
      <div><label>Activate on partial payment</label><input type="checkbox" name="activate_on_partial" value="1" style="width:auto"<?= $v('activate_on_partial') === '1' ? ' checked' : '' ?>></div>
      <div style="flex-basis:100%"><label>Address</label><input name="address" value="<?= e($v('address')) ?>" style="width:100%"></div>
      <div style="flex-basis:100%"><label>Notes</label><input name="notes" value="<?= e($v('notes')) ?>" style="width:100%"></div>
    </div>
    <p style="margin:12px 0 0"><button class="go" type="submit"><i class="fas fa-save"></i> Save</button> <a class="btn btn-outline" href="?r=onprem">Cancel</a></p>
  </form></div>
<?php endif; ?>

<?php if ($clients === []): ?><div class="card"><p class="note" style="margin:0">No on-prem clients yet.</p></div><?php endif; ?>
<?php foreach ($clients as $c): $cid = (string) $c['id']; $t = SmartPRS\Surface\LicenceAuthority::totals($c, $saas); $paid = $Amt::of($c['paid_total'] ?? '0'); $live = $authority->liveFor($cid);
    $all = array_values(array_filter($platform->all('licences'), static fn (array $l): bool => (string) $l['client_id'] === $cid)); $lid = $live === null ? '' : (string) $live['id']; $key = $live === null ? null : $authority->plainKey($live);
    $lst = $live === null ? 'none' : (string) $live['status']; $expired = $live !== null && (string) ($live['amc_expires_on'] ?? '') !== '' && substr((string) $live['amc_expires_on'], 0, 10) < $today; ?>
<details class="card" style="padding:0"<?= $openClient === $cid ? ' open' : '' ?>>
  <summary style="cursor:pointer;padding:12px 16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <span><b><?= e((string) $c['company']) ?></b> <span class="note">· <?= e(trim(implode(' · ', array_filter([(string) ($c['contact_name'] ?? ''), (string) ($c['mobile'] ?? ''), (string) ($c['employee_band'] ?? '')])))) ?></span></span>
    <span><?= e($paid->format()) ?> of <?= e($t['total']->format()) ?>
      <span class="badge badge-<?= $t['balance']->isZero() ? 'green' : ($paid->isZero() ? 'red' : 'amber') ?>"><?= $t['balance']->isZero() ? 'paid' : ($paid->isZero() ? 'unpaid' : 'balance ' . e($t['balance']->format())) ?></span>
      <span class="badge badge-<?= $lst === 'active' ? ($expired ? 'red' : 'green') : ($lst === 'pending' ? 'amber' : 'gray') ?>"><?= $lst === 'none' ? 'no licence' : e($lst) . ($expired ? ' · expired' : '') ?></span>
      <a class="btn btn-sm btn-outline" href="?r=onprem&edit=<?= e($cid) ?>#form">Edit</a></span>
  </summary>
  <div style="padding:0 16px 14px">
    <div class="row" style="gap:16px;flex-wrap:wrap;align-items:flex-start">
      <div style="flex:2;min-width:320px">
        <h4 style="margin:8px 0 6px">Licence</h4>
<?php if ($live !== null): ?>
        <div style="border:1px solid var(--border);border-radius:8px;padding:10px;margin-bottom:8px">
          <div><b>Key</b> <code id="k<?= e($lid) ?>" data-key="<?= e((string) $key) ?>">SPRS-••••-••••-••••-<?= e((string) $live['key_last4']) ?></code>
            <?php if ($key !== null): ?><button class="btn btn-sm btn-outline" type="button" onclick="var c=document.getElementById('k<?= e($lid) ?>');c.textContent=c.dataset.key;this.remove()">Show key</button><?php endif; ?></div>
          <div class="note" style="margin-top:4px">Valid till <b<?= $expired ? ' style="color:#dc2626"' : '' ?>><?= e($dmy((string) ($live['amc_expires_on'] ?? ''))) ?></b>
            · on expiry: <?= (string) ($live['expiry_mode'] ?? 'renew') === 'notify' ? 'keep running, notice' : 'stop until renewed' ?>
            · seats <?= (int) ($live['seats'] ?? 0) > 0 ? (int) $live['seats'] : 'no cap' ?>
            · <?= $authority->reveal($live) !== null && SmartPRS\Surface\Licence::looksLikeLicenceFile((string) $authority->reveal($live)) ? '.lic (offline)' : 'online code' ?></div>
          <div class="note">Server: <?= trim((string) ($live['fingerprint'] ?? '')) === '' ? 'not bound yet' : '<code>' . e((string) $live['fingerprint']) . '</code> ' . e((string) ($live['server_name'] ?? '')) ?>
            · last check-in <?= e(($live['last_seen_at'] ?? '') ? SmartPRS\Surface\Dates::dmy(substr((string) $live['last_seen_at'], 0, 10)) . ' ' . substr((string) $live['last_seen_at'], 11, 5) : 'never') ?>
            · moves used <?= (int) ($live['reactivations_used'] ?? 0) ?>
            <?php if (($live['last_mismatch_at'] ?? '') !== '' && $live['last_mismatch_at'] !== null): ?><span class="badge badge-red">another machine tried this key on <?= e($dmy((string) $live['last_mismatch_at'])) ?></span><?php endif; ?></div>
          <div class="row" style="gap:6px;flex-wrap:wrap;margin-top:8px;align-items:flex-end">
            <form method="post" class="row" style="gap:6px;align-items:flex-end"><?= $hidden('renew', $cid) ?>
              <div><label>Renew by (months)</label><input name="renew_months" type="number" min="1" max="120" placeholder="<?= (int) ($c['licence_term_months'] ?? 0) ?: 12 ?>" style="width:90px"></div>
              <div><label>…or till</label><input name="renew_until" type="date"></div>
              <button class="btn btn-sm btn-primary" type="submit">Renew</button></form>
            <form method="post" style="display:inline"><?= $hidden('shift', $cid) ?><button class="btn btn-sm btn-outline" type="submit" onclick="return confirm('Release the machine binding so the client can activate on a new server?')">Release machine</button></form>
            <form method="post" style="display:inline"><?= $hidden('revoke', $cid) ?><button class="btn btn-sm btn-outline" type="submit" onclick="return confirm('Revoke this licence? The install stops at its next daily check and cannot update.')">Revoke</button></form>
            <form method="post" style="display:inline"><?= $hidden('lic-download', $cid) ?><button class="btn btn-sm btn-outline" type="submit"><i class="fas fa-download"></i> Last .lic</button></form>
          </div>
<?php foreach ($devices[$lid] ?? [] as $d): ?>
          <span class="badge badge-<?= (string) $d['status'] === 'active' ? 'green' : 'gray' ?>" style="margin-top:6px"><?= e((string) (($d['hostname'] ?? '') ?: $d['device_uid'])) ?> · <?= e((string) $d['status']) ?></span>
<?php endforeach; ?>
        </div>
<?php else: ?>
        <form method="post" style="display:inline"><?= $hidden('issue', $cid) ?><button class="btn btn-sm btn-primary" type="submit"><i class="fas fa-key"></i> Generate License Code (online)</button></form>
<?php endif; ?>
        <form method="post" class="row" style="gap:6px;align-items:flex-end;flex-wrap:wrap;margin-top:8px"><?= $hidden('lic', $cid) ?>
          <div style="flex:1;min-width:220px"><label>Server fingerprint (from the client's Activation screen)</label><input name="fingerprint" value="<?= e((string) ($live['fingerprint'] ?? '')) ?>" style="width:100%" placeholder="40 hex characters"></div>
          <div><label>Seats</label><input name="seats" type="number" min="0" value="<?= e((string) ($c['seats'] ?? '')) ?>" style="width:80px" placeholder="0"></div>
          <button class="btn btn-sm btn-outline" type="submit"<?= $authority->canSign() ? '' : ' disabled' ?>><i class="fas fa-file-shield"></i> Generate .lic file</button></form>
        <p class="note" style="font-size:12px;margin:6px 0 0">Valid till <?= e($dmy(SmartPRS\Surface\LicenceAuthority::resolveExpiry($c))) ?> when issued now (term / date on the client). Needs full payment incl. GST<?= (int) ($c['activate_on_partial'] ?? 0) === 1 ? ' — <b>partial approved</b>' : '' ?>.</p>
<?php $hist = []; foreach ($all as $l) { foreach ($events[(string) $l['id']] ?? [] as $ev) { $hist[] = $ev; } } usort($hist, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at'])); ?>
<?php if ($hist !== []): ?>
        <details style="margin-top:8px"><summary class="note">History (<?= count($hist) ?>)</summary>
          <div class="note" style="font-size:12px"><?php foreach (array_slice($hist, 0, 40) as $ev): ?><div><?= e(SmartPRS\Surface\Dates::dmy(substr((string) $ev['created_at'], 0, 10)) . ' ' . substr((string) $ev['created_at'], 11, 5)) ?> <b><?= e((string) $ev['type']) ?></b> <?= e((string) ($ev['detail'] ?? '')) ?> <?= e((string) ($ev['ip'] ?? '')) ?></div><?php endforeach; ?></div></details>
<?php endif; ?>
      </div>
      <div style="flex:1;min-width:260px">
        <h4 style="margin:8px 0 6px">Sale</h4>
        <div class="note">Price <?= e($t['price']->format()) ?> + <?= e($t['mode']) ?> <?= e($t['tax']->format()) ?> = <b><?= e($t['total']->format()) ?></b></div>
        <div class="note">Invoice <?= ($c['invoice_no'] ?? '') !== '' && $c['invoice_no'] !== null ? '<b>' . e((string) $c['invoice_no']) . '</b> of ' . e($dmy((string) ($c['invoice_date'] ?? ''))) : 'not raised' ?></div>
        <div class="row" style="gap:6px;margin:6px 0">
          <form method="post" style="display:inline"><?= $hidden('invoice', $cid) ?><button class="btn btn-sm btn-outline" type="submit"><i class="fas fa-file-invoice"></i> <?= ($c['invoice_no'] ?? '') !== '' && $c['invoice_no'] !== null ? 'Open invoice' : 'Raise invoice' ?></button></form>
          <form method="post" style="display:inline"><?= $hidden('partial', $cid) ?><button class="btn btn-sm btn-outline" type="submit"><?= (int) ($c['activate_on_partial'] ?? 0) === 1 ? 'Require full payment' : 'Allow partial activation' ?></button></form>
        </div>
        <h4 style="margin:10px 0 6px">Payments</h4>
<?php foreach ($payments[$cid] ?? [] as $p): ?>
        <div class="note" style="font-size:12px"><?= e($dmy((string) ($p['paid_on'] ?? ''))) ?> <b><?= e($Amt::of($p['amount'] ?? '0')->format()) ?></b> <?= e((string) ($p['mode'] ?? '')) ?> <?= e((string) ($p['reference'] ?? '')) ?></div>
<?php endforeach; ?>
        <form method="post" class="row" style="gap:8px;align-items:flex-end;flex-wrap:wrap;margin-top:6px"><?= $hidden('pay', $cid) ?>
          <div><label>Amount ₹</label><input name="amount" required inputmode="decimal" pattern="\d+(\.\d{1,2})?" style="width:110px"></div>
          <div><label>Mode</label><select name="mode"><?php foreach ((array) $declared['payment_modes'] as $m): ?><option><?= e((string) $m) ?></option><?php endforeach; ?></select></div>
          <div><label>Reference</label><input name="reference" style="width:120px"></div>
          <div><label>Paid on</label><input type="date" name="paid_on" value="<?= e($today) ?>"></div>
          <button class="btn btn-sm btn-outline" type="submit">Record</button>
        </form>
        <form method="post" style="margin-top:14px"><?= $hidden('delete', $cid) ?><button class="btn btn-sm btn-outline" type="submit" style="color:#dc2626" onclick="return confirm('Delete <?= e(addslashes((string) $c['company'])) ?> and ALL its licence records? This cannot be undone.')"><i class="fas fa-trash"></i> Delete client</button></form>
      </div>
    </div>
  </div>
</details>
<?php endforeach; ?>
