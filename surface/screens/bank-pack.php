<?php declare(strict_types=1); if (!$may('payroll.view')): ?>
<div class="page-header"><div><h2>Bank Payout Pack</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'payroll.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// The bank file for one approved payroll run: employee, account, IFSC, net —
// BankAdvice::generate() does the work (nobody dropped, reconciled to the run,
// negatives listed as recoveries) and surface/actions/bank-pack-csv.php hands
// the same CSV out as a download.
use SmartPRS\Surface\BankAdvice;
use SmartPRS\Surface\Compensation;
use SmartPRS\Surface\Employment;

$compensation = new Compensation($store, __DIR__ . '/../../config/compensation.json');
$csvPermission = (string) $compensation->config()['bank_pack']['csv_download_permission'];
$runs = $register->runs();
$period = trim((string) ($_GET['period'] ?? ''));
$run = $period === '' ? null : $register->find($period);
$advice = null;

if ($run !== null) {
    $accounts = [];

    foreach ($scope->only($store->all('employees'), $may('employee.list')) as $employment) {
        $accounts[(string) Employment::code($employment)] = [
            'account' => Employment::value($employment, 'bank_acc'),
            'ifsc' => Employment::value($employment, 'ifsc'),
        ];
    }

    $advice = BankAdvice::generate($run, $accounts);
}
?>
<div class="page-header">
  <div><h2>Bank Payout Pack</h2><p>Per approved payroll run: one row per employee with account, IFSC and the net to credit, as a CSV the bank portal takes. The file reconciles to the run or it is not produced.</p></div>
  <a class="btn btn-outline" href="?r=register"><i class="fas fa-book"></i> Payroll register</a>
</div>
<div class="card">
  <form method="get" class="row" style="align-items:flex-end">
    <input type="hidden" name="r" value="bank-pack">
    <div><label for="bp-period">Payroll month</label>
      <select id="bp-period" name="period">
        <option value="">— pick a closed run —</option>
<?php foreach ($runs as $r): ?>
        <option value="<?= e((string) $r['period']) ?>"<?= (string) $r['period'] === $period ? ' selected' : '' ?>><?= e((string) $r['period']) ?> — <?= isset($r['approved_by']) ? 'approved' : 'closed, not approved' ?> · <?= (int) $r['employee_count'] ?> employee(s) · payable <?= e((string) $r['payable']) ?></option>
<?php endforeach; ?>
      </select></div>
    <div><button class="go" type="submit">Show</button></div>
  </form>
<?php if ($runs === []): ?>
  <p class="note" style="margin-top:8px">No payroll month has been closed yet. Close one under Generate Payroll; the pack is produced from the closed run, never from live figures.</p>
<?php endif; ?>
</div>
<?php if ($run !== null && $advice !== null): ?>
<div class="card" style="margin-top:12px">
  <h2><?= e((string) $run['period']) ?> <span class="badge <?= isset($run['approved_by']) ? 'badge-green' : 'badge-amber' ?>"><?= isset($run['approved_by']) ? 'approved by ' . e((string) $run['approved_by']) : 'not approved' ?></span></h2>
<?php if (!$advice['ok']): ?>
  <div class="problem"><?= e($advice['why']) ?></div>
<?php else: ?>
  <p class="note"><?= e($advice['why']) ?></p>
  <div class="table-wrap"><table>
    <thead><tr><th>Employee</th><th>Name</th><th>Account</th><th>IFSC</th><th class="n">Of which arrears</th><th class="n">Net to credit</th></tr></thead>
    <tbody>
<?php foreach ($advice['lines'] as $line): ?>
      <tr><td><code><?= e((string) $line['employment_id']) ?></code></td><td><?= e((string) $line['name']) ?></td>
        <td><code><?= e((string) $line['account']) ?></code></td><td><code><?= e((string) $line['ifsc']) ?></code></td>
        <td class="n note" style="font-family:var(--mono)"><?= e((string) $line['arrears']) ?></td>
        <td class="n"><b style="font-family:var(--mono)"><?= e((string) $line['amount']) ?></b></td></tr>
<?php endforeach; ?>
      <tr><th colspan="5">Total to be credited</th><td class="n"><b style="font-family:var(--mono)"><?= e((string) $advice['total']) ?></b></td></tr>
    </tbody>
  </table></div>
<?php if ($may($csvPermission)): ?>
  <form method="post" style="margin-top:10px">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="bank-pack-csv">
    <input type="hidden" name="period" value="<?= e((string) $run['period']) ?>">
    <button class="go" type="submit"><i class="fas fa-file-csv"></i> Download CSV</button>
    <span class="note">employee, name, account, ifsc, amount, of_which_arrears — plain decimals, no separators.</span>
  </form>
<?php else: ?>
  <p class="note">Downloading the file needs <code><?= e($csvPermission) ?></code>; the table above is the same rows.</p>
<?php endif; ?>
<?php endif; ?>
<?php if (($advice['recoveries'] ?? []) !== []): ?>
  <div class="table-wrap" style="margin-top:12px"><table>
    <thead><tr><th>Not paid by credit</th><th class="n">Amount</th><th>Why</th></tr></thead>
    <tbody>
<?php foreach ($advice['recoveries'] as $recovery): ?>
      <tr><td><code><?= e((string) $recovery['employment_id']) ?></code> <?= e((string) $recovery['name']) ?></td>
        <td class="n" style="font-family:var(--mono);font-weight:600"><?= e((string) $recovery['amount']) ?></td>
        <td class="note"><?= e((string) $recovery['why']) ?></td></tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>
<?php endif; ?>
