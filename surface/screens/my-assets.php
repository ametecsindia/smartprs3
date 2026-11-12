<?php
declare(strict_types=1);
// MY ASSETS (owner, 30 Sep): every signed-in employee sees what is issued to
// THEM — read-only, nobody else's. The account's own employee record is the
// only key; an account with no employee record sees nothing (fails closed).
// Also lists their damaged / lost returns still awaiting settlement, because
// those hold their Exit & FnF.
use SmartPRS\Surface\Assets;
use SmartPRS\Surface\Amount;

$maId = (string) ($session->user()['employee_id'] ?? '');
$maAssets = new Assets($store, __DIR__ . '/../../config/assets.json');
$maOut = $maId === '' ? ['held' => [], 'unsettled' => []] : $maAssets->outstandingFor($maId);
$maNames = orgNames($store);
$maModel = static fn (array $r): string => (string) ($r['make_model'] ?? '') ?: ((string) ($r['asset_name'] ?? '') ?: '—');
$maWhere = static fn (array $r): string => trim(((string) ($maNames['companies'][(string) ($r['employer_company_id'] ?? '')] ?? '')) . ' / ' . ((string) ($maNames['branches'][(string) ($r['branch_id'] ?? '')] ?? '')), ' /') ?: '—';
usort($maOut['held'], static fn (array $a, array $b): int => strcmp((string) ($a['issue_date'] ?? ''), (string) ($b['issue_date'] ?? '')));
?>
<div class="page-header">
  <div><h2>My Assets</h2>
    <p>Company items issued to you. Everything here must be returned before your exit clearance.</p></div>
  <div><span class="badge badge-green"><?= count($maOut['held']) ?> with you</span></div>
</div>

<?php if ($maId === ''): ?>
<div class="card"><p class="note" style="margin:0">This login is not linked to an employee record, so there are no assets to show.</p></div>
<?php return; endif; ?>

<div class="card">
  <h3 style="margin:0 0 10px">Issued to me <span class="note">(<?= count($maOut['held']) ?>)</span></h3>
  <div class="table-wrap"><table>
    <thead><tr><th>Asset ID</th><th>Category</th><th>Make / model</th><th>Serial number</th><th>Issued on</th><th>Condition at issue</th><th>Purpose</th><th>Company / branch</th></tr></thead>
    <tbody>
<?php if ($maOut['held'] === []): ?><tr><td colspan="8" class="note">Nothing is issued to you right now.</td></tr><?php endif; ?>
<?php foreach ($maOut['held'] as $r): ?>
      <tr>
        <td><b><?= e((string) ($r['asset_id'] ?? '')) ?></b></td>
        <td><?= e((string) ($r['asset_type'] ?? '') ?: '—') ?></td>
        <td><?= e($maModel($r)) ?></td>
        <td><code><?= e((string) ($r['serial_no'] ?? '') ?: '—') ?></code></td>
        <td class="note"><?= e((string) ($r['issue_date'] ?? '') ?: '—') ?></td>
        <td class="note"><?= e(ucfirst((string) ($r['condition'] ?? '')) ?: '—') ?></td>
        <td class="note"><?= e((string) ($r['purpose'] ?? '') ?: '—') ?></td>
        <td class="note"><?= e($maWhere($r)) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php if ($maOut['unsettled'] !== []): ?>
<div class="card">
  <h3 style="margin:0 0 4px">Returned damaged / lost — not yet settled <span class="note">(<?= count($maOut['unsettled']) ?>)</span></h3>
  <p class="note" style="margin:0 0 10px">These hold your exit clearance and full &amp; final settlement until HR marks them settled.</p>
  <div class="table-wrap"><table>
    <thead><tr><th>Asset ID</th><th>Category</th><th>Make / model</th><th>Returned on</th><th>Condition</th><th>Final status</th><th style="text-align:right">Recovery (₹)</th><th>Recovery status</th></tr></thead>
    <tbody>
<?php foreach ($maOut['unsettled'] as $r): ?>
      <tr>
        <td><b><?= e((string) ($r['asset_id'] ?? '')) ?></b></td>
        <td><?= e((string) ($r['asset_type'] ?? '') ?: '—') ?></td>
        <td><?= e($maModel($r)) ?></td>
        <td class="note"><?= e((string) ($r['return_date'] ?? '') ?: '—') ?></td>
        <td class="note"><?= e(ucfirst((string) ($r['condition'] ?? '')) ?: '—') ?></td>
        <td class="note"><?= e((string) ($r['damage_resolution'] ?? '') ?: '—') ?></td>
        <td style="text-align:right"><?= e(trim((string) ($r['recovery_amount'] ?? '')) === '' ? '—' : Amount::of($r['recovery_amount'])->format()) ?></td>
        <td class="note"><?= e((string) ($r['recovery_status'] ?? '') ?: '—') ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
