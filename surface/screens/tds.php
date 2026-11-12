<?php declare(strict_types=1); if (!$may('payroll.view')): ?>
<div class="page-header"><div><h2>TDS</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'payroll.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's TDS screen projected a year's tax from CTC with slabs typed into
// the page. Here: what the closed runs deducted per employee, and the
// slabs are in config/income-tax.json (see ?r=tds-rules).
$heads = ['TDS'];
$route = 'tds';
$title = 'TDS';
include __DIR__ . '/../partials/statutory-head-summary.php';
?>
