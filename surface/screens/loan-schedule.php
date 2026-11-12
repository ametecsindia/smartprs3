<?php declare(strict_types=1); if (!$may('payroll.view')): ?>
<div class="page-header"><div><h2>Loan EMI Schedule</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'payroll.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's loan statement: the planned EMIs month by month, and against
// each month what the closed payroll actually recovered. The plan is arithmetic
// (Compensation::schedule); the actual is read from the runs, never from a
// column somebody decremented (see Recoveries).
use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\Amount;
use SmartPRS\Surface\Compensation;
use SmartPRS\Surface\Employment;

$compensation = new Compensation($store, __DIR__ . '/../../config/compensation.json');
$loanRules = (array) $compensation->config()['loan'];
$people = [];

foreach ($scope->only(liveRows($store, 'employees'), $may('employee.list')) as $e) {
    $people[(string) $e['id']] = $e;
}

$filter = orgFilter();
$inView = array_flip(array_map(static fn (array $e): string => (string) $e['id'], orgFilterApply($store, array_values($people), $filter)));
$loans = array_values(array_filter($store->all('loans'), static fn (array $l): bool => isset($inView[(string) ($l['employee_id'] ?? '')])));
usort($loans, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
$who = static fn (array $row): string => isset($people[(string) $row['employee_id']])
    ? Employment::code($people[(string) $row['employee_id']]) . ' ' . (string) ($people[(string) $row['employee_id']]['name'] ?? '')
    : '#' . (string) $row['employee_id'];
$pickedId = trim((string) ($_GET['id'] ?? ''));
$picked = $pickedId === '' ? null : $store->find('loans', $pickedId);

if ($picked !== null && !isset($inView[(string) ($picked['employee_id'] ?? '')])) {
    $picked = null;
}
?>
<div class="page-header">
  <div><h2>Loan EMI Schedule</h2><p>Planned instalments from the start month, and what each closed payroll actually recovered. The last instalment is the remainder, never the standing EMI.</p></div>
  <a class="btn btn-outline" href="?r=m&k=loans"><i class="fas fa-building-columns"></i> Loans register</a>
</div>
<?php if ($picked !== null):
    $opening = trim((string) ($picked['outstanding'] ?? '')) !== '' ? (string) $picked['outstanding'] : (string) ($picked['amount'] ?? '');
    $start = trim((string) ($picked['start_month'] ?? ''));
    $assumed = '';

    if (preg_match('/^\d{4}-\d{2}$/', $start) !== 1) {
        $from = substr((string) ($picked['decided_at'] ?? $picked['created_at'] ?? date('Y-m-d')), 0, 7);
        $start = preg_match('/^\d{4}-\d{2}$/', $from) === 1 ? $from : date('Y-m');
        $assumed = 'No start month is recorded on the loan, so the schedule is shown from ' . $start . ' (the month it was decided or entered). Set "EMIs start" on the loan to pin it.';
    }

    $problem = '';
    $plan = [];

    try {
        $plan = Compensation::schedule($opening, (string) ($picked['emi'] ?? ''), $start);
    } catch (\InvalidArgumentException $e) {
        $problem = 'The schedule cannot be computed: ' . $e->getMessage();
    }

    $code = isset($people[(string) $picked['employee_id']]) ? Employment::code($people[(string) $picked['employee_id']]) : '';
    $taken = $code === '' ? [] : $compensation->recoveredByPeriod($code, (string) $loanRules['line_prefix'] . (string) $picked['id']);
    $recoveredMinor = array_sum($taken);
    $openingMoney = Amount::of($opening);
    $left = $openingMoney->minus(Money::ofMinor($recoveredMinor));
    $status = strtolower(trim((string) ($picked['status'] ?? '')));
?>
<div class="card">
  <h2>Loan #<?= e((string) $picked['id']) ?> &middot; <?= e($who($picked)) ?>
    <span class="badge <?= in_array($status, (array) $loanRules['recovering_statuses'], true) ? 'badge-green' : 'badge-gray' ?>"><?= e($status) ?></span></h2>
  <div class="row">
    <div><b>Opening</b><br><span style="font-family:var(--mono)"><?= e($openingMoney->format()) ?></span><br><span class="note"><?= trim((string) ($picked['outstanding'] ?? '')) !== '' ? 'the recorded opening outstanding' : 'the loan amount (no opening outstanding recorded)' ?></span></div>
    <div><b>EMI</b><br><span style="font-family:var(--mono)"><?= e(Amount::of($picked['emi'] ?? '')->format()) ?></span><br><span class="note">tenure sanctioned: <?= e((string) ($picked['tenure'] ?? '—')) ?> months; planned: <?= count($plan) ?></span></div>
    <div><b>Recovered by payroll</b><br><span style="font-family:var(--mono)"><?= e(Money::ofMinor($recoveredMinor)->format()) ?></span><br><span class="note"><?= count($taken) ?> instalment(s) in closed runs; reversed months not counted</span></div>
    <div><b>Outstanding now</b><br><span style="font-family:var(--mono);font-weight:600"><?= e($left->format()) ?></span><br><span class="note">= opening − recovered</span></div>
  </div>
<?php if ($assumed !== ''): ?>
  <p class="note" style="margin-top:8px"><?= e($assumed) ?></p>
<?php endif; ?>
<?php if (!in_array($status, (array) $loanRules['recovering_statuses'], true)): ?>
  <p class="note">Payroll recovers only loans whose status is one of <?= e(implode(', ', (array) $loanRules['recovering_statuses'])) ?>; this one is <b><?= e($status) ?></b>, so the plan below is what WOULD be deducted once it is.</p>
<?php endif; ?>
<?php if ($problem !== ''): ?>
  <div class="problem"><?= e($problem) ?></div>
<?php elseif ($plan === []): ?>
  <p class="note">Nothing to schedule: the opening figure or the EMI is zero.</p>
<?php else: ?>
  <div class="table-wrap" style="margin-top:10px"><table>
    <thead><tr><th>#</th><th>Month</th><th class="n">Planned EMI</th><th class="n">Planned outstanding after</th><th class="n">Actually recovered</th><th>Run</th></tr></thead>
    <tbody>
<?php $seen = []; foreach ($plan as $row): $actual = $taken[$row['month']] ?? null; $seen[$row['month']] = true; ?>
      <tr>
        <td><?= (int) $row['n'] ?></td>
        <td><?= e($row['month']) ?></td>
        <td class="n" style="font-family:var(--mono)"><?= e($row['instalment']->format()) ?><?= $row['instalment']->compareTo(Amount::of($picked['emi'] ?? '')) < 0 ? ' <span class="note">(remainder)</span>' : '' ?></td>
        <td class="n note" style="font-family:var(--mono)"><?= e($row['after']->format()) ?></td>
        <td class="n" style="font-family:var(--mono)"><?= $actual === null ? '—' : e(Money::ofMinor($actual)->format()) ?></td>
        <td><?= $actual === null ? '<span class="badge badge-gray">not yet</span>' : '<span class="badge badge-green">closed</span>' ?></td>
      </tr>
<?php endforeach; ?>
<?php foreach ($taken as $period => $minor): if (isset($seen[$period])) { continue; } ?>
      <tr>
        <td>—</td><td><?= e((string) $period) ?></td><td class="n note">outside the plan</td><td class="n note">—</td>
        <td class="n" style="font-family:var(--mono)"><?= e(Money::ofMinor($minor)->format()) ?></td>
        <td><span class="badge badge-amber">closed, off-plan</span></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="note">The plan is the opening figure at the EMI, month by month; the recovered column is read from the closed payroll runs (line <code><?= e((string) $loanRules['line_prefix'] . (string) $picked['id']) ?></code>). An instalment in a month the plan did not expect is listed rather than hidden.</p>
<?php endif; ?>
</div>
<?php endif; ?>
<?= orgFilterBar('loan-schedule', $filter, orgFilterOptions($store, array_values($people))) ?>
<div class="card">
  <h2>Loans <span class="badge badge-gray"><?= count($loans) ?></span></h2>
<?php if ($loans === []): ?>
  <p class="note">No loans in view. Record one on the Loans register.</p>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>#</th><th>Employee</th><th class="n">Amount</th><th class="n">EMI</th><th>Start</th><th>Status</th><th></th></tr></thead>
    <tbody>
<?php foreach ($loans as $loan): ?>
      <tr>
        <td><?= e((string) $loan['id']) ?></td>
        <td><?= e($who($loan)) ?></td>
        <td class="n" style="font-family:var(--mono)"><?= e(Amount::of($loan['amount'] ?? '')->format()) ?></td>
        <td class="n" style="font-family:var(--mono)"><?= e(Amount::of($loan['emi'] ?? '')->format()) ?></td>
        <td class="note"><?= e((string) ($loan['start_month'] ?? '—')) ?></td>
        <td><span class="badge badge-gray"><?= e(strtolower((string) ($loan['status'] ?? ''))) ?></span></td>
        <td><a class="btn btn-outline btn-sm" href="?r=loan-schedule&id=<?= e((string) $loan['id']) ?>">Schedule</a></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>
