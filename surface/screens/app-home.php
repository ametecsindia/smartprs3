<?php
/**
 * THE APP'S HOME SCREEN (owner, 5 Nov 2026: "it should behave as a Mobile App, NOT the browser window with left menu
 * bar … small icons for each menu item, with Home etc. icons at the bottom"). Opened first inside the SmartPRS3 Android
 * app instead of the full Dashboard (lighter, so the app opens faster). Every menu item this person may open, as an icon
 * tile, grouped by its menu section — the same list and the same permissions as the web menu ($navigation->forViewer).
 */
declare(strict_types=1);

$ahSections = [];
foreach ($navigation->forViewer($may, $navBadges ?? [], $navFlags ?? []) as $ahS) {
    if (($ahS['platform_only'] ?? false) === true) { continue; }
    $ahItems = array_values(array_filter((array) $ahS['items'], static fn (array $i): bool => (bool) ($i['allowed'] ?? false)));
    $ahItems === [] || $ahSections[] = ['label' => (string) $ahS['label'], 'icon' => (string) ($ahS['icon'] ?? 'fa-folder'), 'items' => $ahItems];
}
$ahName = trim((string) ($acctName ?? '')) ?: explode('@', (string) ($session->user()['email'] ?? ''))[0];
$ahHour = (int) date('G');
?>
<style>
  .ah-hello{background:linear-gradient(135deg,var(--accent),color-mix(in srgb,var(--accent) 60%,#1F3A5F));color:#fff;border-radius:16px;padding:18px 18px 16px;margin:4px 0 16px}
  .ah-hello h2{margin:0;font-size:19px;color:#fff}
  .ah-hello p{margin:4px 0 0;opacity:.9;font-size:13px}
  .ah-sec{margin:0 0 18px}
  .ah-sec h3{font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--text2);margin:0 4px 8px;display:flex;gap:6px;align-items:center}
  .ah-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
  .ah-tile{display:flex;flex-direction:column;align-items:center;gap:6px;text-decoration:none;color:var(--text1);padding:10px 4px 8px;border-radius:14px;background:var(--card);border:1px solid var(--border);position:relative;min-height:84px}
  .ah-tile:active{transform:scale(.96)}
  .ah-ic{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;font-size:18px;color:var(--accent);background:color-mix(in srgb,var(--accent) 12%,var(--card))}
  .ah-tile span{font-size:11.5px;line-height:1.25;text-align:center;overflow-wrap:anywhere}
  .ah-tile b{position:absolute;top:4px;right:6px;min-width:18px;height:18px;padding:0 5px;border-radius:9px;background:#dc2626;color:#fff;font-size:10px;line-height:18px}
  @media (min-width:600px){.ah-grid{grid-template-columns:repeat(6,minmax(0,1fr))}}
</style>
<div class="ah-hello"><h2><?= $ahHour < 12 ? 'Good morning' : ($ahHour < 17 ? 'Good afternoon' : 'Good evening') ?>, <?= e($ahName) ?></h2>
  <p><?= e(SmartPRS\Surface\Dates::dmy(date('Y-m-d'))) ?> &middot; tap a tile to open it</p></div>
<?php foreach ($ahSections as $ahS): ?>
<div class="ah-sec"><h3><i class="fas <?= e($ahS['icon']) ?>"></i> <?= e($ahS['label']) ?></h3>
  <div class="ah-grid">
<?php   foreach ($ahS['items'] as $ahI): ?>
    <a class="ah-tile" href="<?= e((string) preg_replace('/^\?r=dashboard$/', '?r=dashboard&full=1', (string) $ahI['href'])) /* the app sends a bare dashboard address to Home (6 Nov: tile did nothing) */ ?>"><span class="ah-ic"><i class="fas <?= e((string) $ahI['icon']) ?>"></i></span><span><?= e((string) $ahI['label']) ?></span><?php if ((int) ($ahI['count'] ?? 0) > 0): ?><b><?= (int) $ahI['count'] ?></b><?php endif; ?></a>
<?php   endforeach; ?>
  </div></div>
<?php endforeach; ?>
