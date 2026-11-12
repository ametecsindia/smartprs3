<?php declare(strict_types=1);
// ADMINISTRATION → AUDIT LOG (owner, 22 Oct 2026): every sign-in and sign-out, and every addition, change and
// deletion across the application — who, when, which module, which record, which field, old value → new value —
// on one page, filtered by date range / category / module / user / word, exported to Excel. Rows come from the
// hash-chained trail (surface/partials/audit-log-data.php); the chain itself is still verified on ?r=trail.
if (!$may('audit.tenant.read')): ?>
<div class="page-header"><div><h2>Audit Log</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'audit.tenant.read')->summary) ?></div></div>
<?php return; endif;

$params = $_GET;
include __DIR__ . '/../partials/audit-log-data.php';
$alShown = array_slice($alRows, 0, 1000);
$alBadge = ['Login' => 'badge-gray', 'Logout' => 'badge-gray', 'Added' => 'badge-green', 'Changed' => 'badge-amber', 'Deleted / removed' => 'badge-red', 'Approval / decision' => 'badge-blue', 'Export' => 'badge-gray'];
$alSelect = static function (string $name, string $label, array $options, string $current): string {
    $h = '<div><label for="al_' . $name . '">' . e($label) . '</label><select id="al_' . $name . '" name="' . $name . '"><option value="">ALL</option>';
    foreach ($options as $o) { $h .= '<option' . ((string) $o === $current ? ' selected' : '') . '>' . e((string) $o) . '</option>'; }
    return $h . '</select></div>';
};
?>
<div class="page-header">
  <div><h2>Audit Log</h2><p>Who signed in, and who added, changed or deleted what &mdash; module, record, field, old value &rarr; new value &middot; <?= e(SmartPRS\Surface\Dates::dmy($alFrom)) ?> to <?= e(SmartPRS\Surface\Dates::dmy($alTo)) ?></p></div>
  <div class="actions">
    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="audit-log-export"><?= keepHidden($alKeep) ?>
      <button class="btn btn-outline btn-sm" type="submit"><i class="fas fa-file-excel"></i> Export to Excel</button></form>
  </div>
</div>
<div class="card">
  <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin:0"><input type="hidden" name="r" value="audit-log">
    <div><label for="al_from">From</label><input type="date" id="al_from" name="from" value="<?= e($alFrom) ?>" style="width:auto"></div>
    <div><label for="al_to">To</label><input type="date" id="al_to" name="to" value="<?= e($alTo) ?>" style="width:auto"></div>
    <?= $alSelect('cat', 'Category', array_keys($alBadge), $alCat) ?>
    <?= $alSelect('mod', 'Module', array_keys($alMods), $alMod) ?>
    <?= $alSelect('by', 'User', array_keys($alActors), $alBy) ?>
    <div><label for="al_q">Contains</label><input id="al_q" name="q" value="<?= e($alWord) ?>" placeholder="employee, field, value…" style="width:200px"></div>
    <button class="btn btn-outline btn-sm" type="submit"><i class="fas fa-filter"></i> Show</button>
  </form>
  <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:12px">
<?php foreach ($alBadge as $alC => $alClass): ?>
    <a class="badge <?= $alClass ?>" style="padding:5px 10px;text-decoration:none;<?= $alCat === $alC ? 'outline:2px solid #0f172a' : '' ?>" href="?r=audit-log&amp;<?= e(http_build_query(['cat' => $alCat === $alC ? '' : $alC] + $alKeep)) ?>"><?= e($alC) ?> <?= (int) ($alCats[$alC] ?? 0) ?></a>
<?php endforeach; ?>
  </div>
</div>
<div class="card" style="padding:0">
<?php if ($alRows === []): ?>
  <div class="empty"><i class="fas fa-clock-rotate-left"></i>Nothing recorded for these filters.</div>
<?php else: ?>
  <div class="table-wrap"><style>.al-t td,.al-t th{overflow-wrap:anywhere;vertical-align:top}</style><table class="al-t" style="table-layout:fixed;width:100%">
    <colgroup><col style="width:8%"><col style="width:10%"><col style="width:7%"><col style="width:7%"><col style="width:13%"><col style="width:8%"><col style="width:8%"><col style="width:14%"><col style="width:25%"></colgroup>
    <thead><tr><th>When</th><th>User</th><th>Category</th><th>Module</th><th>What happened</th><th>Record</th><th>Field</th><th>Old value</th><th>New value</th></tr></thead>
    <tbody>
<?php foreach ($alShown as $r): $alN = max(1, count($r['changes'])); ?>
<?php foreach ($r['changes'] === [] ? [null] : $r['changes'] as $alI => $c): ?>
    <tr>
<?php if ($alI === 0): ?>
      <td rowspan="<?= $alN ?>"><?= e($r['when']) ?></td>
      <td rowspan="<?= $alN ?>"><?= e($r['by']) ?></td>
      <td rowspan="<?= $alN ?>"><span class="badge <?= $alBadge[$r['cat']] ?? 'badge-gray' ?>"><?= e($r['cat']) ?></span></td>
      <td rowspan="<?= $alN ?>"><?= e($r['mod']) ?></td>
      <td rowspan="<?= $alN ?>"><?= e($r['what']) ?><div class="note"><code><?= e($r['action']) ?></code></div></td>
      <td rowspan="<?= $alN ?>"><?= e($r['record'] !== '' ? $r['record'] : '—') ?></td>
<?php endif; ?>
<?php if ($c === null): ?>
      <td class="note">—</td><td></td><td></td>
<?php else: ?>
      <td><b><?= e($c['field']) ?></b></td>
      <td style="white-space:pre-wrap;overflow-wrap:anywhere;vertical-align:top;color:#b91c1c"><?= e($c['old'] !== '' ? $c['old'] : '—') ?></td>
      <td style="white-space:pre-wrap;overflow-wrap:anywhere;vertical-align:top;color:#15803d"><?= e($c['new'] !== '' ? $c['new'] : '—') ?></td>
<?php endif; ?>
    </tr>
<?php endforeach; ?>
<?php endforeach; ?>
    </tbody></table></div>
<?php if (count($alRows) > count($alShown)): ?><p class="note" style="padding:10px 16px;margin:0">Showing the newest <?= count($alShown) ?> of <?= count($alRows) ?> entries — narrow the dates or filters, or Export to Excel for all of them.</p><?php endif; ?>
<?php endif; ?>
</div>
