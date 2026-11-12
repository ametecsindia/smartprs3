<?php declare(strict_types=1); if (!$may('payroll.view')): ?>
<div class="page-header"><div><h2>Salary Calculation Guide</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'payroll.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's "Salary Calculation Guide" was prose with numbers typed into a
// controller. This one is READ from the configuration payroll itself reads —
// surface/CalcGuide.php — so every figure is the live one and names its file
// or row. Read-only; printable through the browser.
use SmartPRS\Surface\CalcGuide;

$asOf = date('Y-m-d');
$problem = null;

try {
    $version = $structure->versionOn($asOf);
    $basis = $structure->contractedCostBasis($asOf);
} catch (\Throwable $e) {
    $problem = $e->getMessage();
    $version = [];
    $basis = '';
}

$guide = new CalcGuide(
    __DIR__ . '/../../config', $asOf, $version, $basis,
    $payCycles->rules(), $payCycles->declared(),
    liveRows($store, 'late_policy'),
    $statutory->isAvailable() ? $statutory->packsByHead(new DateTimeImmutable($asOf)) : [],
    $statutory->isAvailable() ? null : $statutory->why(),
);
$sections = $guide->sections();
?>
<div class="page-header">
  <div><h2>Salary Calculation Guide</h2>
    <p>How a payslip is computed, step by step, from the configuration in force on <?= e($asOf) ?>. Nothing below is typed here: every number is read from the file or row named beside it, and changes when that does.</p></div>
  <div><button class="btn btn-outline" type="button" onclick="window.print()">Print the guide</button></div>
</div>
<?php if ($problem !== null): ?><div class="card"><div class="problem" style="margin:0"><?= e($problem) ?></div></div><?php endif; ?>

<div class="card" style="padding:10px 14px"><div class="row" style="flex-wrap:wrap;gap:6px">
<?php foreach ($sections as $i => $s): ?><a class="btn btn-outline btn-sm" href="#cg-<?= e($s['id']) ?>"><?= $i + 1 ?> · <?= e($s['title']) ?></a><?php endforeach; ?>
</div></div>

<?php foreach ($sections as $i => $s): ?>
<div class="card" id="cg-<?= e($s['id']) ?>">
  <h2><?= $i + 1 ?> · <?= e($s['title']) ?></h2>
  <div class="note" style="margin-bottom:8px">Source: <code><?= e($s['source']) ?></code></div>
  <ul style="margin:0;padding-left:20px">
<?php foreach ($s['points'] as $p): ?>    <li style="margin:0 0 6px;line-height:1.55"><?= e($p) ?></li>
<?php endforeach; ?>
  </ul>
</div>
<?php endforeach; ?>

<div class="card">
  <h2>Where to see it applied</h2>
  <p class="note">Every closed month keeps, per line, the sentence explaining that number — open a <a href="?r=m&amp;k=payslips">payslip</a> and read the trace beside each amount. The <a href="?r=statutory-config">Statutory Configuration</a> screen lists the packs quoted above; the <a href="?r=m&amp;k=shifts">Working Shifts</a> and <a href="?r=roster">Roster</a> screens decide the shift each day is measured against.</p>
</div>
