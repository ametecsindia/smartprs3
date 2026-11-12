<?php declare(strict_types=1); if (!$may('employee.view')): ?>
<div class="page-header"><div><h2>Notice Board</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Notice Board: company notices, published ones on every dashboard.
// Here a notice has a validity window; this screen shows current / upcoming /
// expired / draft, and add/edit/delete are the `notices` register.
use SmartPRS\Surface\Notices;

$today = date('Y-m-d');
$board = (new Notices($store))->split($today);
$sections = [
    'current' => ['Current', 'green', 'Published and inside their dates — what a dashboard shows.'],
    'upcoming' => ['Upcoming', 'amber', 'Published, valid from a later date.'],
    'expired' => ['Expired', 'gray', 'Past their valid-to date; kept for the record.'],
    'draft' => ['Drafts', 'gray', 'Not published.'],
];
?>
<div class="page-header">
  <div><h2>Notice Board</h2><p><?= count($board['current']) ?> current · <?= count($board['upcoming']) ?> upcoming · <?= count($board['expired']) ?> expired · <?= count($board['draft']) ?> draft, as at <?= e($today) ?>. A notice is current when it is published and today falls between its valid-from and valid-to (blank valid-to = no expiry).</p></div>
<?php if ($may('employee.update')): ?>
  <a class="btn btn-primary" href="?r=m&amp;k=notices"><i class="fas fa-plus"></i> Add / edit notices</a>
<?php endif; ?>
</div>
<?php foreach ($sections as $state => [$label, $colour, $why]): ?>
<div class="card">
  <h2><?= e($label) ?> <span class="badge badge-<?= e($colour) ?>"><?= count($board[$state]) ?></span> <span class="note"><?= e($why) ?></span></h2>
<?php if ($board[$state] === []): ?>
  <p class="note">None.</p>
<?php else: ?>
<?php foreach ($board[$state] as $n): ?>
  <div style="border-top:1px solid var(--line);padding:10px 0">
    <b><?= e((string) ($n['title'] ?? '')) ?></b>
    <span class="note">posted <?= e((string) ($n['posted_on'] ?? '—')) ?><?= ($n['posted_by'] ?? '') !== '' ? ' by ' . e((string) $n['posted_by']) : '' ?> · valid <?= e((string) ($n['valid_from'] ?? $n['posted_on'] ?? '—')) ?> → <?= e(trim((string) ($n['valid_to'] ?? '')) === '' ? 'no expiry' : (string) $n['valid_to']) ?></span>
    <div style="white-space:pre-wrap;margin-top:4px"><?= e((string) ($n['body'] ?? '')) ?></div>
  </div>
<?php endforeach; ?>
<?php endif; ?>
</div>
<?php endforeach; ?>
