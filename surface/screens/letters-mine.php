<?php declare(strict_types=1);
// MY LETTERS (owner, 23 Oct 2026): every letter issued to the signed-in employee, as it went out; a letter
// that needs acknowledgement carries an "I acknowledge" button until it is signed off.
use SmartPRS\Surface\Letters;

$lmOwn = $scope->own();
?>
<div class="page-header"><div><h2>My Letters</h2><p>Letters issued to you. Letters marked <b>Needs your acknowledgement</b> must be read and acknowledged.</p></div></div>
<?php if ($lmOwn === null): ?>
<div class="card"><p class="note" style="margin:0">Your login is not linked to an employee record, so there are no letters to show.</p></div>
<?php return; endif; ?>
<?php
$lmAcks = [];
try {
    foreach ($store->all('letter_acks') as $a) { $lmAcks[(string) ($a['letter_id'] ?? '')] = $a; }
} catch (\Throwable) {
    $lmAcks = [];   // letter_acks not migrated yet: nothing acknowledged
}
$lmLetters = $letters->issued((string) $lmOwn);
?>
<?php if ($lmLetters === []): ?>
<div class="card"><p class="note" style="margin:0">No letters have been issued to you yet.</p></div>
<?php endif; ?>
<?php foreach ($lmLetters as $lm): $lmT = $letters->find((string) ($lm['letter_type'] ?? '')); $lmNeeds = $lmT !== null && Letters::needsAck($lmT); $lmAck = $lmAcks[(string) $lm['id']] ?? null; ?>
<div class="card"<?= $lmNeeds && $lmAck === null ? ' style="border-left:4px solid var(--red)"' : '' ?>>
  <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <h3 style="margin:0;font-size:16px"><?= e((string) ($lmT['label'] ?? $lm['letter_type'])) ?> <span class="note">· issued <?= e(date('d-m-Y', (int) strtotime((string) ($lm['issued_on'] ?? '')))) ?></span></h3>
<?php if ($lmNeeds): ?>
    <?= $lmAck === null ? '<span class="badge badge-red">Needs your acknowledgement</span>' : '<span class="badge badge-green">Acknowledged on ' . e(date('d-m-Y H:i', (int) strtotime((string) $lmAck['acknowledged_at']))) . '</span>' ?>
<?php endif; ?>
  </div>
  <details<?= $lmNeeds && $lmAck === null ? ' open' : '' ?> style="margin-top:8px"><summary class="note" style="cursor:pointer">Read the letter</summary>
    <pre style="white-space:pre-wrap;background:#fff;border:1px solid var(--border);padding:16px;border-radius:6px;font:13px/1.7 Georgia,serif;margin-top:8px"><?= e((string) ($lm['body'] ?? '')) ?></pre>
  </details>
<?php if ($lmNeeds && $lmAck === null): ?>
  <form method="post" style="margin-top:10px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="letter-acknowledge">
    <input type="hidden" name="id" value="<?= e((string) $lm['id']) ?>">
    <label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;margin:0"><input type="checkbox" name="agree" value="1" required> I have read this letter and I accept it.</label>
    <button type="submit" class="btn btn-primary"><i class="fas fa-signature"></i> I acknowledge</button>
  </form>
<?php endif; ?>
</div>
<?php endforeach; ?>
