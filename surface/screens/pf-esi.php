<?php declare(strict_types=1); if (!$may('payroll.view')): ?>
<div class="page-header"><div><h2>PF &amp; ESI</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'payroll.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's PF & ESIC contribution register — here read from the closed
// runs, with the rates coming from the signed pack (or refused, when the only
// pack is synthetic). Applicability per employee is the PF & ESI Registrations
// register.
$heads = ['PF', 'ESI'];
$route = 'pf-esi';
$title = 'PF & ESI';
include __DIR__ . '/../partials/statutory-head-summary.php';
?>
<p class="note"><a class="btn btn-outline btn-sm" href="?r=m&amp;k=pf-esi-register"><i class="fas fa-id-card"></i> PF &amp; ESI registrations</a> <a class="btn btn-outline btn-sm" href="?r=compliance-alerts"><i class="fas fa-triangle-exclamation"></i> ECR / ESI due dates</a></p>
