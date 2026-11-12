<?php declare(strict_types=1); if (!$may('payroll.view')): ?>
<div class="page-header"><div><h2>Compliance Alerts</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'payroll.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// The statutory due-date register: every obligation in config/compliance-calendar.json
// for the last N periods, with its state — filed, overdue, due within the
// warning period, upcoming — per company. Marking one filed is a row in
// compliance_filings with who, when and the reference; never an edit.
// Credential expiries (DRA, PCC) are the Compliance watch screen, unchanged.
use SmartPRS\Surface\Amount;
use SmartPRS\Surface\StatutoryReports;

$cal = StatutoryReports::config(__DIR__ . '/../../config/compliance-calendar.json');
$today = date('Y-m-d');
$lead = (int) $cal['lead_days'];
$count = max(1, min(24, (int) ($_GET['periods'] ?? $cal['periods_shown'])));
$companies = [];

foreach (liveRows($store, 'companies') as $c) {
    $companies[(string) $c['id']] = trim((string) ($c['name'] ?? ''));
}

$companyId = trim((string) ($_GET['company'] ?? ''));
$companyId = isset($companies[$companyId]) ? $companyId : '';
$filings = [];

foreach (liveRows($store, 'compliance_filings') as $f) {
    $filings[(string) ($f['employer_company_id'] ?? '') . '|' . (string) $f['obligation'] . '|' . (string) $f['period']] = $f;
}

$rows = [];
$counts = [StatutoryReports::OVERDUE => 0, StatutoryReports::DUE => 0, StatutoryReports::UPCOMING => 0, StatutoryReports::FILED => 0];

foreach ((array) $cal['obligations'] as $o) {
    foreach (StatutoryReports::periods($o, $today, $count) as $p) {
        $filed = $filings[$companyId . '|' . (string) $o['key'] . '|' . $p['period']] ?? null;
        $state = StatutoryReports::filingState($p['due'], $filed === null ? null : (string) ($filed['filed_on'] ?? ''), $today, $lead);
        $counts[$state]++;
        $rows[] = ['o' => $o, 'period' => $p['period'], 'due' => $p['due'], 'state' => $state, 'filed' => $filed,
            'days' => SmartPRS\Surface\Compliance::daysBetween($today, $p['due'])];
    }
}

$rank = [StatutoryReports::OVERDUE => 0, StatutoryReports::DUE => 1, StatutoryReports::UPCOMING => 2, StatutoryReports::FILED => 3];
usort($rows, static fn (array $a, array $b): int => [$rank[$a['state']], $a['due']] <=> [$rank[$b['state']], $b['due']]);
$badge = [StatutoryReports::OVERDUE => 'badge-red', StatutoryReports::DUE => 'badge-amber', StatutoryReports::UPCOMING => 'badge-gray', StatutoryReports::FILED => 'badge-green'];
?>
<div class="page-header">
  <div><h2>Compliance Alerts</h2><p><?= $counts[StatutoryReports::OVERDUE] ?> overdue, <?= $counts[StatutoryReports::DUE] ?> due within <?= $lead ?> days, <?= $counts[StatutoryReports::UPCOMING] ?> upcoming, <?= $counts[StatutoryReports::FILED] ?> filed — for <?= $companyId === '' ? 'the tenant as a whole (no company picked)' : e($companies[$companyId]) ?>. Due days are <code>config/compliance-calendar.json</code>.</p></div>
  <form method="get" class="row" style="align-items:flex-end"><input type="hidden" name="r" value="compliance-alerts">
    <div><label for="company">Company</label><select id="company" name="company"><option value="">ALL (tenant)</option><?php foreach ($companies as $id => $name): ?><option value="<?= e($id) ?>"<?= $id === $companyId ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select></div>
    <div><label for="periods">Periods</label><input type="number" id="periods" name="periods" min="1" max="24" value="<?= $count ?>" style="width:70px"></div>
    <div><button class="btn btn-outline" type="submit">Show</button></div>
    <div><a class="btn btn-outline" href="?r=compliance"><i class="fas fa-id-badge"></i> Credential expiries</a></div></form>
</div>
<div class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>Obligation</th><th>Period</th><th>Due</th><th>State</th><th>Filed</th><th>Blocks</th><th></th></tr></thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr>
        <td><b><?= e((string) $r['o']['label']) ?></b><br><span class="note"><?= e((string) $r['o']['head']) ?> · <?= e((string) ($r['o']['authority'] ?? '')) ?></span></td>
        <td><?= e($r['period']) ?></td>
        <td><?= e($r['due']) ?><br><span class="note"><?= $r['days'] < 0 ? abs($r['days']) . ' days ago' : 'in ' . $r['days'] . ' days' ?></span></td>
        <td><span class="badge <?= $badge[$r['state']] ?>"><?= e($r['state']) ?></span></td>
        <td class="note"><?php if ($r['filed'] !== null): ?><?= e((string) $r['filed']['filed_on']) ?><?= trim((string) ($r['filed']['reference'] ?? '')) !== '' ? ' · ' . e((string) $r['filed']['reference']) : '' ?><?= trim((string) ($r['filed']['amount'] ?? '')) !== '' ? ' · ' . e(Amount::of($r['filed']['amount'])->format()) : '' ?><br>by <?= e((string) ($r['filed']['filed_by'] ?? '')) ?><?php else: ?>—<?php endif; ?></td>
        <td class="note" style="white-space:normal;font-size:11px"><?= e((string) $r['o']['blocks']) ?></td>
        <td><?php if ($r['filed'] === null && $may('payroll.run')): ?>
          <details><summary class="btn btn-outline btn-sm">Mark filed</summary>
          <form method="post" class="row" style="align-items:flex-end;margin-top:6px"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="compliance-filed"><input type="hidden" name="obligation" value="<?= e((string) $r['o']['key']) ?>"><input type="hidden" name="period" value="<?= e($r['period']) ?>"><input type="hidden" name="company" value="<?= e($companyId) ?>"><input type="hidden" name="periods" value="<?= $count ?>">
            <div><label>Filed on</label><input type="date" name="filed_on" value="<?= e($today) ?>" required></div>
            <div><label>Reference (TRRN / challan / ack)</label><input name="reference"></div>
            <div><label>Amount (₹)</label><input name="amount" inputmode="decimal"></div>
            <div><button class="btn btn-primary btn-sm" type="submit">Record</button></div></form></details>
        <?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="note">A filing is recorded with who and when, against the company picked above (or the tenant when none is). It is never edited: a wrong one is a second row and a note on the Compliance filings register in Audit Reports.</p>
</div>
