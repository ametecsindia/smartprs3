<?php declare(strict_types=1); if (!$may('payroll.structure.view')): ?>
<div class="page-header"><div><h2>Statutory Configuration</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'payroll.structure.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's statutory configuration screen let an administrator type PF, ESI,
// PT and TDS rates into a settings row. Here the rates are SIGNED PACKS and this
// screen shows which are in force, with the tenant-editable rules (gratuity,
// bonus, the due-date calendar) read from their config files, read-only.
use SmartPRS\Surface\StatutoryReports;

$asOf = date('Y-m-d');
$packs = $statutory->packsByHead(new DateTimeImmutable($asOf));
$files = ['gratuity' => 'config/gratuity.json', 'statutory-bonus' => 'config/statutory-bonus.json', 'compliance-calendar' => 'config/compliance-calendar.json'];
?>
<div class="page-header"><div><h2>Statutory Configuration</h2><p>What decides a statutory figure in this build, and where each rule lives. None of it is in PHP.</p></div></div>
<div class="card">
  <h2>Rate packs in force on <?= e($asOf) ?></h2>
<?php if (!$statutory->isAvailable()): ?>
  <div class="problem">No pack is in force: <?= e($statutory->why()) ?>. Payroll closes without statutory lines and every payslip says so.</div>
<?php elseif ($packs === []): ?>
  <div class="problem">The pack store loaded but no pack declares a head on <?= e($asOf) ?> — nothing is in force today.</div>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Head</th><th>Pack</th><th>Jurisdiction</th><th>State</th></tr></thead>
    <tbody>
<?php foreach ($packs as $head => $pack): ?>
      <tr><td><b><?= e((string) $head) ?></b></td><td><code><?= e($pack->manifest->packKey()) ?></code> v<?= e($pack->manifest->version()) ?></td><td><?= e($pack->manifest->jurisdiction()) ?></td>
        <td><?php if ($pack->manifest->isSynthetic()): ?><span class="badge badge-red">synthetic — states no law</span><?php else: ?><span class="badge badge-green">signed</span><?php endif; ?></td></tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
  <p class="note">A real rate is a pack under <code>packs/</code> signed by a named compliance owner and verified on load. Typing a PF rate into a settings row — SmartPRS 2's screen — is a value attributed to nobody that somebody eventually deducts from a salary. The tenant's own declared settings from SmartPRS 2 are kept as registers: <a href="?r=m&amp;k=pf-esi">PF &amp; ESI settings</a>, <a href="?r=m&amp;k=tds-rules">TDS rules</a>, <a href="?r=m&amp;k=pt-slabs">PT slabs</a> — reference data until a pack carries them.</p>
</div>
<?php foreach ($files as $name => $rel): $cfg = StatutoryReports::config(__DIR__ . '/../../' . $rel); ?>
<div class="card">
  <h2><?= e($name) ?> <span class="note"><code><?= e($rel) ?></code></span></h2>
  <p class="note"><?= e((string) ($cfg['note'] ?? '')) ?></p>
  <div class="table-wrap"><table>
    <tbody>
<?php foreach ($cfg as $k => $v): if ($k === 'note' || str_ends_with((string) $k, '_note')): continue; endif; ?>
      <tr><td><code><?= e((string) $k) ?></code></td><td style="white-space:normal;font-family:var(--mono);font-size:11px"><?= e(is_scalar($v) ? (string) $v : (string) json_encode($v, JSON_UNESCAPED_SLASHES)) ?></td></tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endforeach; ?>
