<?php declare(strict_types=1); if (!$may('tenant.settings.update') || !$isPlatformAdmin): ?>
<div class="page-header"><div><h2>Landing Page (CMS)</h2></div></div>
<div class="card"><div class="problem" style="margin:0">The landing page is edited from the Super Admin console (/super).</div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Landing CMS (/admin/landing): every text on the public landing page (public/landing.php)
// — hero, stats, features, plans, clients, about, contact, footer. A blank row is removed on save; one
// empty row under each list adds a new one. Written by surface/actions/landing-cms.php.
$lc = new SmartPRS\Surface\LandingContent(dirname(__DIR__, 2) . '/config/landing.json', new SmartPRS\Surface\PlatformSettings($platform));
$lcContent = $lc->content();
$lcLabel = static fn (string $k): string => ucwords(str_replace(['_', '-'], ' ', ['n' => 'number', 'l' => 'label', 'desc' => 'description'][$k] ?? $k));
$lcField = static function (string $name, string $key, string $value) use ($lcLabel): string {
    $long = strlen($value) > 90 || str_contains($value, "\n") || in_array($key, ['body', 'subtitle', 'features', 'address', 'proof', 'short', 'desc'], true);

    return '<div style="flex:' . ($long ? '1 1 100%' : '1 1 220px') . '"><label>' . e($lcLabel($key)) . '</label>'
        . ($long ? '<textarea name="' . e($name) . '" rows="3" style="width:100%">' . e($value) . '</textarea>'
            : '<input name="' . e($name) . '" value="' . e($value) . '" style="width:100%">') . '</div>';
};
$lcShape = $lc->defaults();
?>
<div class="page-header"><div><h2>Landing Page (CMS)</h2>
  <p>Every word on the public page at <a href="landing.php" target="_blank" rel="noopener">landing.php</a>. Save publishes immediately.<?= $lc->edited() ? ' <span class="badge badge-blue">Edited</span>' : ' <span class="badge badge-gray">Shipped default</span>' ?></p></div>
  <div class="row" style="gap:8px"><a class="btn btn-outline" href="landing.php" target="_blank" rel="noopener"><i class="fas fa-eye"></i> View page</a>
<?php if ($lc->edited()): ?><form method="post" onsubmit="return confirm('Forget every edit and go back to the shipped wording?')"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="landing-cms"><input type="hidden" name="op" value="reset"><button class="btn btn-outline" type="submit"><i class="fas fa-rotate-left"></i> Reset to default</button></form><?php endif; ?></div></div>
<form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="landing-cms"><input type="hidden" name="op" value="save">
<?php
$lcLoose = [];
foreach ($lcShape as $key => $shape) {
    $value = $lcContent[$key] ?? $shape;
    if (!is_array($shape)) {
        $lcLoose[$key] = (string) $value;
        continue;
    }
    echo '<div class="card"><h3 style="margin-top:0">' . e($lcLabel((string) $key)) . '</h3>';
    if (array_is_list($shape)) {
        $cols = array_keys((array) ($shape[0] ?? []));
        $rows = array_values((array) $value);
        $rows[] = array_fill_keys($cols, '');
        foreach ($rows as $i => $row) {
            $isNew = $i === count($rows) - 1;
            echo '<div class="row" style="gap:10px;flex-wrap:wrap;padding:10px;margin-bottom:8px;border:1px dashed var(--border,#e2e8f0);border-radius:10px">'
                . '<div class="note" style="flex:1 1 100%;margin:0">' . ($isNew ? '<i class="fas fa-plus"></i> Add a new row' : '#' . ($i + 1) . ' — clear every field to remove') . '</div>';
            foreach ($cols as $col) {
                echo $lcField("content[{$key}][{$i}][{$col}]", (string) $col, (string) ($row[$col] ?? ''));
            }
            echo '</div>';
        }
    } else {
        echo '<div class="row" style="gap:10px;flex-wrap:wrap">';
        foreach ($shape as $sub => $_) {
            echo $lcField("content[{$key}][{$sub}]", (string) $sub, (string) ($value[$sub] ?? ''));
        }
        echo '</div>';
    }
    echo '</div>';
}
?>
<div class="card"><h3 style="margin-top:0">Brand &amp; footer</h3><div class="row" style="gap:10px;flex-wrap:wrap">
<?php foreach ($lcLoose as $key => $value) { echo $lcField('content[' . $key . ']', (string) $key, $value); } ?>
</div><p class="note" style="margin-bottom:0">{{YEAR}} in the footer prints the current year.</p></div>
<div style="position:sticky;bottom:0;padding:12px 0;background:var(--bg,#f0f4f8)"><button class="go" type="submit"><i class="fas fa-floppy-disk"></i> Save &amp; publish</button></div>
</form>
