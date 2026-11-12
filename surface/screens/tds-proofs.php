<?php declare(strict_types=1);
/**
 * INVESTMENT PROOFS (owner, 14 Oct). The employee uploads a proof against each declared item while
 * the organisation's proof window is open; HR approves (with the amount the proof supports) or
 * rejects with a reason. After the proof cut-off only approved amounts count (organisation setting)
 * and the remaining months' TDS absorbs the difference.
 */
use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\IncomeTax;
use SmartPRS\Surface\TaxDeclarations;

include __DIR__ . '/../partials/tds-lib.php';
$tpFy = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['fy'] ?? '')) === 1 ? (string) $_GET['fy'] : $taxDeclarations->fy();
$tpYear = $taxDeclarations->year($tpFy);
$tpWho = tdsWho();
$tpToday = date('Y-m-d');
$tpWin = $taxDeclarations->window('proof', $tpToday);
$tpBadge = static fn (string $s): string => '<span class="badge badge-' . ($s === 'approved' ? 'green' : ($s === 'rejected' ? 'red' : 'amber')) . '">' . e(ucfirst($s)) . '</span>';
$tpFile = static fn (array $p): string => (string) ($p['file_path'] ?? '') === '' ? '—' : '<a href="' . e((string) $p['file_path']) . '" target="_blank" rel="noopener"><i class="fas fa-paperclip"></i> ' . e((string) ($p['file_name'] ?? 'file')) . '</a>';
echo tdsCss();

// ---- HR: EVERY PROOF, TO VERIFY ----------------------------------------------------------------
if ($tpWho['hr'] && $tpWho['e'] === null):
    $tpFilter = orgFilter();
    $tpState = in_array((string) ($_GET['status'] ?? ''), ['pending', 'approved', 'rejected'], true) ? (string) $_GET['status'] : '';
    $tpData = tdsRows($tpFy, $tpFilter, '');
    $tpIds = [];
    foreach ($tpData['rows'] as $r) { $tpIds[(string) $r['e']['id']] = $r['e']; }
    $tpAll = $taxDeclarations->proofs($tpFy);
    $tpCounts = [];
    foreach ($tpAll as $p) { $tpCounts[(string) $p['employee_id']] = ($tpCounts[(string) $p['employee_id']] ?? 0) + 1; }
    $tpRows = array_values(array_filter($tpAll, static fn (array $p): bool => isset($tpIds[(string) $p['employee_id']]) && ($tpState === '' || (string) $p['status'] === $tpState)));
    usort($tpRows, static fn (array $a, array $b): int => [(string) $a['status'] !== 'pending', (string) $b['created_at']] <=> [(string) $b['status'] !== 'pending', (string) $a['created_at']]);
    $tpLabels = array_column((array) $tpYear['items'], 'label', 'code') + ['HRA' => 'House rent (HRA)'];
    $tpN = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
    foreach ($tpAll as $p) { $tpN[(string) $p['status']] = ($tpN[(string) $p['status']] ?? 0) + 1; }
    $tpMayReview = $may('payroll.run') || isAdministrator();
?>
  <div class="page-header">
    <div><h2>Investment Proofs</h2><p><?= e($tpFy) ?> &middot; proof window <b><?= e($tpWin['why']) ?></b><?= $taxDeclarations->proofsCutOff($tpToday) ? ' &middot; <b>cut-off passed: only approved amounts count</b>' : '' ?></p></div>
    <div class="actions" style="display:flex;gap:8px;flex-wrap:wrap">
      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="tds-export"><input type="hidden" name="kind" value="proofs"><input type="hidden" name="fy" value="<?= e($tpFy) ?>"><input type="hidden" name="status" value="<?= e($tpState) ?>"><?= keepHidden(orgFilterKeep($tpFilter)) ?>
        <button class="btn btn-primary" type="submit"><i class="fas fa-file-excel"></i> Export proofs</button></form>
      <a class="btn btn-outline" href="?r=tds-declaration&amp;fy=<?= e($tpFy) ?>"><i class="fas fa-file-signature"></i> Declarations</a>
    </div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:16px">
<?php foreach (['Waiting for review' => $tpN['pending'], 'Approved' => $tpN['approved'], 'Rejected' => $tpN['rejected']] as $l => $v): ?>
    <div class="card" style="margin:0;padding:14px 16px"><div class="note" style="text-transform:uppercase;font-size:11px;letter-spacing:.05em"><?= e($l) ?></div><div style="font-size:26px;font-weight:700"><?= (int) $v ?></div></div>
<?php endforeach; ?>
  </div>
  <div class="card"><?= tdsOrgButtons('tds-proofs', $tpFilter, $tpData['scoped'], $tpCounts, ($tpState !== '' ? '&amp;status=' . $tpState : '') . '&amp;fy=' . e($tpFy),
      '<div><label for="tp_status">Status</label><select id="tp_status" name="status"><option value="">All</option>'
      . implode('', array_map(static fn (string $s): string => '<option value="' . $s . '"' . ($tpState === $s ? ' selected' : '') . '>' . ucfirst($s) . '</option>', ['pending', 'approved', 'rejected']))
      . '</select></div><input type="hidden" name="fy" value="' . e($tpFy) . '">') ?>
    <p class="note" style="margin:-4px 0 0">Button counts = proofs uploaded.</p></div>
  <div class="card" style="padding:0">
    <div class="card-header"><div><h3 style="font-size:15px;font-weight:700">Proofs</h3><p class="note" style="margin:0">Pending first. Approve with the amount the proof supports — it can be less than claimed.</p></div><div class="actions"><span class="badge badge-gray"><?= count($tpRows) ?> proof(s)</span></div></div>
    <div class="table-wrap"><table>
      <thead><tr><th>Employee</th><th>Item</th><th class="tds-n">Declared</th><th class="tds-n">Claimed</th><th>Proof</th><th>Uploaded</th><th>Status</th><th>Review</th></tr></thead>
      <tbody>
<?php if ($tpRows === []): ?><tr><td colspan="8" class="note">No proofs match these filters.</td></tr><?php endif; ?>
<?php foreach ($tpRows as $p): $pe = $tpIds[(string) $p['employee_id']]; $pd = $tpData['decls'][(string) $p['employee_id']] ?? null; $c = (string) $p['item_code'];
      $declared = $c === 'HRA' ? TaxDeclarations::rentAnnual((array) ($pd['rent'] ?? [])) : IncomeTax::p($pd['items'][$c] ?? ''); ?>
        <tr><td><b><?= e(Employment::code($pe)) ?></b><br><span class="note"><?= e((string) ($pe['name'] ?? '')) ?></span></td>
          <td><?= e((string) ($tpLabels[$c] ?? $c)) ?></td><td class="tds-n"><?= tdsMoney($declared) ?></td><td class="tds-n"><?= tdsMoney(IncomeTax::p($p['amount_claimed'])) ?></td>
          <td><?= $tpFile($p) ?></td><td><?= e(SmartPRS\Surface\Dates::dmy(substr((string) $p['created_at'], 0, 10))) ?></td>
          <td><?= $tpBadge((string) $p['status']) ?><?= (string) $p['status'] === 'approved' ? '<br><span class="note">' . tdsMoney(IncomeTax::p($p['approved_amount'])) . '</span>' : '' ?><?= (string) ($p['review_note'] ?? '') !== '' ? '<br><span class="note">' . e((string) $p['review_note']) . '</span>' : '' ?><?= (string) ($p['reviewed_by'] ?? '') !== '' ? '<br><span class="note">by ' . e((string) $p['reviewed_by']) . '</span>' : '' ?></td>
          <td style="min-width:300px"><?php if ($tpMayReview): ?>
            <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:0"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="tds-proof-review"><input type="hidden" name="id" value="<?= e((string) $p['id']) ?>"><input type="hidden" name="back" value="<?= e((string) ($_SERVER['QUERY_STRING'] ?? '')) ?>">
              <input name="approved" inputmode="decimal" style="width:110px;padding:5px 8px" value="<?= e(Money::ofMinor((string) $p['status'] === 'approved' ? IncomeTax::p($p['approved_amount']) : IncomeTax::p($p['amount_claimed']))->toDecimalString()) ?>" title="Approved amount">
              <input name="note" placeholder="Note (needed to reject)" style="width:150px;padding:5px 8px">
              <button class="btn btn-sm" name="decision" value="approved" style="background:#047857;color:#fff;border:0">Approve</button>
              <button class="btn btn-sm" name="decision" value="rejected" style="background:#fff;color:#b91c1c;border:1px solid #fecaca">Reject</button></form>
          <?php endif; ?></td></tr>
<?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
<?php return; endif;

// ---- ONE EMPLOYEE -------------------------------------------------------------------------------
if ($tpWho['e'] === null): ?>
  <div class="page-header"><div><h2>Investment Proofs</h2></div></div>
  <div class="card"><p class="note" style="margin:0">This login is not linked to an employee record, so there are no proofs to submit.</p></div>
<?php return; endif;

$e = $tpWho['e'];
$tpDecl = $taxDeclarations->declaration((string) $e['id'], $tpFy);
$tpMine = $taxDeclarations->proofs($tpFy, (string) $e['id']);
$tpCanUpload = $tpWin['open'] || $may('payroll.run') || isAdministrator();
$tpNeed = $tpDecl === null ? [] : $taxDeclarations->proofItems($tpDecl, $tpFy);
?>
  <div class="page-header">
    <div><h2>Investment Proofs <?= $tpWho['self'] ? '' : '&mdash; ' . e(Employment::label($e)) ?></h2><p><?= e($tpFy) ?> &middot; proof window <b><?= e($tpWin['why']) ?></b></p></div>
    <div class="actions" style="display:flex;gap:8px">
      <?php if ($tpWho['hr']): ?><a class="btn btn-outline" href="?r=tds-proofs&amp;fy=<?= e($tpFy) ?>"><i class="fas fa-arrow-left"></i> All proofs</a><?php endif; ?>
      <a class="btn btn-outline" href="?r=tds-declaration<?= $tpWho['self'] ? '' : '&amp;id=' . e((string) $e['id']) ?>&amp;fy=<?= e($tpFy) ?>"><i class="fas fa-file-signature"></i> My declaration</a>
    </div>
  </div>
<?php if ($tpDecl === null): ?>
  <div class="card"><p class="note" style="margin:0">You have no declaration for <?= e($tpFy) ?>, so there is nothing to prove. Declare first on <a href="?r=tds-declaration">TDS Declaration</a>.</p></div>
<?php return; endif; ?>
<?php if (!$tpCanUpload): ?>
  <div class="card" style="border-left:4px solid #b45309"><b>The proof window is <?= e($tpWin['why']) ?>.</b> Proofs can be uploaded only while it is open. <?= $taxDeclarations->proofsCutOff($tpToday) ? 'Only approved amounts now count towards your tax.' : '' ?></div>
<?php endif; ?>
  <div class="card" style="padding:0">
    <div class="card-header"><div><h3 style="font-size:15px;font-weight:700">What needs a proof</h3><p class="note" style="margin:0">One row per declared item. Upload PDF or image, up to <?= (int) (EMPLOYEE_FILE_MAX_BYTES / 1024 / 1024) ?> MB each; several files per item are fine.</p></div></div>
    <div class="table-wrap"><table>
      <thead><tr><th>Item</th><th>What to upload</th><th class="tds-n">Declared</th><th>Uploaded</th><th>Upload</th></tr></thead>
      <tbody>
<?php foreach ($tpNeed as $c => $it): $declared = $c === 'HRA' ? TaxDeclarations::rentAnnual((array) $tpDecl['rent']) : IncomeTax::p($tpDecl['items'][$c] ?? ''); $mine = array_values(array_filter($tpMine, static fn (array $p): bool => (string) $p['item_code'] === $c)); ?>
        <tr><td><b><?= e((string) $it['label']) ?></b></td><td class="note" style="max-width:280px"><?= e((string) ($it['proof'] ?? '')) ?></td><td class="tds-n"><?= tdsMoney($declared) ?></td>
          <td><?php if ($mine === []): ?><span class="badge badge-gray">Nothing yet</span><?php endif; ?>
<?php foreach ($mine as $p): ?>
            <div style="margin-bottom:4px"><?= $tpFile($p) ?> &middot; <?= tdsMoney(IncomeTax::p($p['amount_claimed'])) ?> <?= $tpBadge((string) $p['status']) ?><?= (string) ($p['review_note'] ?? '') !== '' ? ' <span class="note">' . e((string) $p['review_note']) . '</span>' : '' ?>
<?php if ((string) $p['status'] === 'pending' && $tpCanUpload): ?>
              <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="tds-proof-withdraw"><input type="hidden" name="id" value="<?= e((string) $p['id']) ?>"><button class="btn btn-sm" style="padding:1px 8px" title="Withdraw">&times;</button></form>
<?php endif; ?></div>
<?php endforeach; ?></td>
          <td><?php if ($tpCanUpload): ?>
            <form method="post" enctype="multipart/form-data" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:0"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="tds-proof-upload"><input type="hidden" name="employee_id" value="<?= e((string) $e['id']) ?>"><input type="hidden" name="fy" value="<?= e($tpFy) ?>"><input type="hidden" name="item" value="<?= e($c) ?>">
              <input name="amount" inputmode="decimal" placeholder="Amount ₹" required style="width:110px;padding:5px 8px" value="<?= e(Money::ofMinor($declared)->toDecimalString()) ?>">
              <input type="file" name="proof" accept="<?= e(EMPLOYEE_FILE_ACCEPT) ?>" required style="max-width:190px">
              <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-upload"></i> Upload</button></form>
          <?php else: ?><span class="note">window <?= e($tpWin['why']) ?></span><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if ($tpNeed === []): ?><tr><td colspan="5" class="note">Nothing you declared needs a proof (amounts taken from payroll and other income do not).</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>
