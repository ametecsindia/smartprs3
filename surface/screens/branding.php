<?php declare(strict_types=1); if (!$may('tenant.settings.view')): ?>
<div class="page-header"><div><h2>Company Branding</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Company Branding: per company a logo, a brand colour and the
// display name, previewed as they print. The fields ARE the companies row
// (color, logo_path, logo_text), so the edit is the companies register's own
// form — this screen previews the sidebar header and a document heading with
// them and links to that form. No second place to store a logo.
$companies = liveRows($store, 'companies');
$canEdit = $may('tenant.settings.update');
$hex = static fn (string $c): string => preg_match('/^#[0-9a-fA-F]{6}$/', $c) === 1 ? $c : '';
$fallback = 'var(--accent)'; // the product accent, when a company has no colour of its own
?>
<div class="page-header">
  <div><h2>Company Branding</h2>
    <p>Each company's logo, brand colour and logo text as they appear on the sidebar, payslips, letters and the ID card.</p></div>
  <div><a class="btn btn-outline" href="?r=m&amp;k=companies"><i class="fas fa-building"></i> Companies register</a>
    <a class="btn btn-outline" href="?r=configure-id-card"><i class="fas fa-id-card"></i> ID Card Format</a></div>
</div>

<?php if ($companies === []): ?>
<div class="card"><p class="note" style="margin:0">No companies yet. Add one under Companies; its brand fields appear here.</p></div>
<?php endif; ?>

<?php foreach ($companies as $c):
    $colour = $hex(trim((string) ($c['color'] ?? '')));
    $logo = trim((string) ($c['logo_path'] ?? ''));
    $text = trim((string) ($c['logo_text'] ?? '')) ?: strtoupper(substr(trim((string) ($c['code'] ?? $c['name'] ?? '')), 0, 3));
    $shown = $colour !== '' ? $colour : $fallback; ?>
<div class="card">
  <div class="row" style="justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
    <div style="flex:1;min-width:260px">
      <h3 style="margin:0 0 4px"><?= e((string) ($c['name'] ?? '')) ?> <span class="badge badge-gray"><?= e((string) ($c['code'] ?? '')) ?></span></h3>
      <table style="font-size:13px">
        <tr><td class="note" style="padding:2px 10px 2px 0">Brand colour</td><td><?php if ($colour !== ''): ?><span style="display:inline-block;width:14px;height:14px;border-radius:3px;vertical-align:middle;background:<?= e($colour) ?>"></span> <code><?= e($colour) ?></code><?php else: ?><span class="badge badge-amber">not set &mdash; the product accent is used</span><?php endif; ?></td></tr>
        <tr><td class="note" style="padding:2px 10px 2px 0">Logo file</td><td><?php if ($logo !== ''): ?><code><?= e($logo) ?></code><?= is_file(__DIR__ . '/../../public/' . ltrim($logo, '/')) ? '' : ' <span class="badge badge-red">file missing</span>' ?><?php else: ?><span class="badge badge-amber">none &mdash; logo text prints instead</span><?php endif; ?></td></tr>
        <tr><td class="note" style="padding:2px 10px 2px 0">Logo text</td><td><code><?= e($text) ?></code></td></tr>
      </table>
<?php if ($canEdit): ?>
      <p style="margin:10px 0 0"><a class="btn btn-primary btn-sm" href="?r=m&amp;k=companies&amp;edit=<?= e((string) $c['id']) ?>"><i class="fas fa-pen"></i> Change logo / colour / text</a></p>
<?php endif; ?>
    </div>
    <div style="flex:1;min-width:280px">
      <div class="note" style="margin-bottom:4px">Sidebar header preview</div>
      <div style="background:#0c1929;border-radius:10px;padding:14px 16px;display:flex;align-items:center;gap:12px">
        <div style="width:38px;height:38px;border-radius:9px;background:<?= e($shown) ?>;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:13px;overflow:hidden">
<?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt="" style="max-width:38px;max-height:38px;object-fit:contain"><?php else: ?><?= e($text) ?><?php endif; ?>
        </div>
        <div style="color:#fff"><div style="font-weight:800;font-size:15px"><?= e((string) ($c['name'] ?? '')) ?></div><div style="font-size:11px;opacity:.7">SmartPRS</div></div>
      </div>
      <div class="note" style="margin:10px 0 4px">Document heading preview (payslip / letter)</div>
      <div style="border:1px solid var(--border,#e2e8f0);border-top:5px solid <?= e($shown) ?>;border-radius:8px;padding:12px 14px;display:flex;gap:12px;align-items:center;background:#fff">
<?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt="" style="height:34px;max-width:120px;object-fit:contain"><?php else: ?><span style="font-weight:900;color:<?= e($shown) ?>;font-size:18px"><?= e($text) ?></span><?php endif; ?>
        <div><div style="font-weight:700"><?= e(trim((string) ($c['legal_name'] ?? '')) ?: (string) ($c['name'] ?? '')) ?></div>
          <div class="note" style="font-size:11px"><?= e(trim((string) ($c['address'] ?? ''))) ?></div></div>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>

<div class="note">The product's own sidebar logo is the build's, not the tenant's: SmartPRS 2's per-account app logo
  upload was dropped &mdash; a company logo already brands every document, and a second logo store was a second thing
  to go missing.</div>
