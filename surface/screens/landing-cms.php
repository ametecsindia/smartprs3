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
    $long = strlen($value) > 90 || str_contains($value, "\n") || in_array($key, ['body', 'subtitle', 'features', 'address', 'proof', 'short', 'desc', 'lead', 'note', 'description', 'capacity_note'], true);

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
// ANY DEPTH (12 Nov 2026: the SmartPRS 3.0 pricing — plans, advance periods, on-premises — and the mobile-app screens
// nest one level deeper than the original sections). A group is a box of fields, a list is rows with an empty one to add.
$lcRender = static function (string $name, string $key, mixed $shape, mixed $value) use (&$lcRender, $lcField, $lcLabel): string {
    if (!is_array($shape)) {
        return $lcField($name, $key, is_scalar($value) ? (string) $value : '');
    }

    $html = '<div style="flex:1 1 100%;border:1px dashed var(--border,#e2e8f0);border-radius:10px;padding:10px"><div class="note" style="margin:0 0 6px;font-weight:700">' . e($lcLabel($key)) . '</div><div class="row" style="gap:10px;flex-wrap:wrap">';

    if (array_is_list($shape)) {
        // Every key any row carries (a plan's "highlight" sits on one row only) — row 0 alone would drop it on save.
        $rowShape = is_array($shape[0] ?? null) ? array_replace(...$shape) : ($shape[0] ?? '');
        $rows = array_values(is_array($value) ? $value : []);
        $rows[] = is_array($rowShape) ? array_fill_keys(array_keys($rowShape), '') : '';

        foreach ($rows as $i => $row) {
            $isNew = $i === count($rows) - 1;
            $html .= '<div class="row" style="flex:1 1 100%;gap:10px;flex-wrap:wrap;padding:8px;border:1px dashed var(--border,#e2e8f0);border-radius:8px">'
                . '<div class="note" style="flex:1 1 100%;margin:0">' . ($isNew ? '<i class="fas fa-plus"></i> Add a new row' : '#' . ($i + 1) . ' — clear every field to remove') . '</div>';

            if (is_array($rowShape)) {
                foreach ($rowShape as $col => $sub) {
                    $html .= $lcRender($name . '[' . $i . '][' . $col . ']', (string) $col, $sub, is_array($row) ? ($row[$col] ?? null) : null);
                }
            } else {
                $html .= $lcRender($name . '[' . $i . ']', $key, '', $row);
            }

            $html .= '</div>';
        }
    } else {
        foreach ($shape as $sub => $subShape) {
            $html .= $lcRender($name . '[' . $sub . ']', (string) $sub, $subShape, is_array($value) ? ($value[$sub] ?? null) : null);
        }
    }

    return $html . '</div></div>';
};
$lcLoose = [];
foreach ($lcShape as $key => $shape) {
    $value = $lcContent[$key] ?? $shape;
    if (!is_array($shape)) {
        $lcLoose[$key] = (string) $value;
        continue;
    }
    echo '<div class="card"><h3 style="margin-top:0">' . e($lcLabel((string) $key)) . '</h3><div class="row" style="gap:10px;flex-wrap:wrap">';
    foreach ((array_is_list($shape) ? [$key => $shape] : $shape) as $sub => $subShape) {
        echo array_is_list($shape)
            ? $lcRender("content[{$key}]", (string) $key, $shape, $value)
            : $lcRender("content[{$key}][{$sub}]", (string) $sub, $subShape, is_array($value) ? ($value[$sub] ?? null) : null);
    }
    echo '</div></div>';
}
?>
<div class="card"><h3 style="margin-top:0">Brand &amp; footer</h3><div class="row" style="gap:10px;flex-wrap:wrap">
<?php foreach ($lcLoose as $key => $value) { echo $lcField('content[' . $key . ']', (string) $key, $value); } ?>
</div><p class="note" style="margin-bottom:0">{{YEAR}} in the footer prints the current year.</p></div>
<div style="position:sticky;bottom:0;padding:12px 0;background:var(--bg,#f0f4f8)"><button class="go" type="submit"><i class="fas fa-floppy-disk"></i> Save &amp; publish</button></div>
</form>
