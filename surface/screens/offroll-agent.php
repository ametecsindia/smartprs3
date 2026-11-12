<?php declare(strict_types=1); if (!$may('employee.view')): ?>
<div class="page-header"><div><h2>Off-roll Agent</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// FIELD FORCE → OFF-ROLL AGENT PROFILE (23 Oct 2026): one page per agent — deployment, certificates and bank
// letters, KYC documents, the pay structure with its history (revised from a date, never overwritten), the
// month's attendance with what it has earned so far and how, the adjustments ledger, every payout and
// payment, and the mobile app's visit log. Replaces the old Off-roll KYC & Earnings page (?r=offroll-kyc).
use SmartPRS\Surface\Amount;
use SmartPRS\Surface\Dates;
use SmartPRS\Surface\OffrollAgents;
use SmartPRS\Surface\OffrollPay;

include __DIR__ . '/../partials/offroll-boot.php';

if (!$opReady) {
    echo '<div class="page-header"><div><h2>Off-roll Agent</h2></div></div>' . $opNotReady;
    return;
}

$id = (string) ($_GET['id'] ?? '');
$a = $opAgents[$id] ?? null;

if ($a === null) {
    echo '<div class="page-header"><div><h2>Off-roll Agent</h2></div></div><div class="card"><p class="note" style="margin:0">No such agent, or not one you can see. <a href="?r=offroll-agents">All agents</a></p></div>';
    return;
}

$today = date('Y-m-d');
$month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($_GET['month'] ?? '')) === 1 ? (string) $_GET['month'] : date('Y-m');
$monthEnd = (new DateTimeImmutable($month . '-01'))->modify('last day of this month')->format('Y-m-d');
$dmy = static fn (string $d): string => $d === '' ? '—' : Dates::dmy(substr($d, 0, 10));
$plans = $op->plans($id);
$planNow = OffrollPay::planOn($plans, $a, $today);
$legacy = new OffrollAgents($store, __DIR__ . '/../../config/offroll-agents.json');
$back = '?r=offroll-agent&id=' . rawurlencode($id) . '&month=' . $month;
$hidden = static fn (): string => '<input type="hidden" name="csrf" value="' . e($session->token()) . '"><input type="hidden" name="id" value="' . e($id) . '"><input type="hidden" name="month" value="' . e($month) . '"><input type="hidden" name="back" value="offroll-agent">';

// THE MONTH: its days, and what they have earned so far (by the counting rule in force).
$days = array_values(array_filter($op->activity($month), static fn (array $r): bool => (string) $r['agent_id'] === $id));
$byDate = array_column($days, null, 'activity_date');
$counts = $opSet['count_basis'] === 'all' ? ['approved', 'pending'] : ['approved'];
$counted = array_values(array_filter($days, static fn (array $r): bool => in_array((string) ($r['approval'] ?? ''), $counts, true)));
$ledgerAll = $legacy->ledger($id, $month)['entries'];
$ledgerOk = array_values(array_filter($ledgerAll, static fn (array $l): bool => (string) $l['status'] === 'approved'));
$daysBasis = match ($opSet['fixed_basis']) {
    'prorata_working' => (string) $calendar->workingDaysBetween($month . '-01', $monthEnd),
    'prorata_fixed' => $opSet['fixed_days'],
    default => (string) (int) substr($monthEnd, 8, 2),
};
$live = OffrollPay::priceMonth($a, $plans, $counted, $ledgerOk, $opSet, $opD, $month, $daysBasis, $opWindow);
$pendingDays = count(array_filter($days, static fn (array $r): bool => (string) ($r['approval'] ?? '') === 'pending'));

// PAYOUTS of this agent, newest first.
$runs = array_column($op->runs(), null, 'id');
$lines = array_values(array_filter($op->lines(), static fn (array $l): bool => (string) $l['agent_id'] === $id && isset($runs[(string) $l['payout_id']])));
usort($lines, static fn (array $x, array $y): int => strcmp((string) $runs[(string) $y['payout_id']]['period'], (string) $runs[(string) $x['payout_id']]['period']));

$auths = array_values(array_filter(OffrollPay::rows($store, 'agent_authorizations'), static fn (array $r): bool
    => (string) ($r['agent_id'] ?? '') === $id && !\SmartPRS\Surface\Modules::isRetired($r)));
$visits = array_values(array_filter(OffrollPay::rows($store, OffrollPay::VISITS), static fn (array $v): bool => (string) $v['agent_id'] === $id));
usort($visits, static fn (array $x, array $y): int => strcmp((string) $y['visit_at'], (string) $x['visit_at']));
$visits = array_slice($visits, 0, 30);
$st = (array) $opD['day_statuses'];
$stColor = ['present' => '#dcfce7', 'half_day' => '#fef9c3', 'absent' => '#fee2e2', 'leave' => '#e0e7ff', 'week_off' => '#f1f5f9'];
$slabs = OffrollPay::parseSlabs((string) ($planNow['collection_slabs'] ?? '')) ?? [];
?>
<style>.op-kv th{text-align:left;width:34%;color:var(--text2);font-weight:600;padding:6px 8px;vertical-align:top;background:none}.op-kv td{padding:6px 8px}</style>
<div class="page-header">
  <div><h2><?= e($op->label($a)) ?> <span class="badge <?= strtolower((string) ($a['status'] ?? '')) === 'active' ? 'badge-green' : 'badge-gray' ?>"><?= e((string) ($a['status'] ?? '')) ?></span></h2>
    <p><?= e(implode(' · ', array_filter([$opCompanies[(string) ($a['employer_company_id'] ?? '')] ?? '', $opBranches[(string) ($a['branch_id'] ?? '')] ?? '',
        trim((string) ($a['client_bank'] ?? '')) !== '' ? 'for ' . $a['client_bank'] . (trim((string) ($a['portfolio'] ?? '')) !== '' ? ' — ' . $a['portfolio'] : '') : '',
        'deployed ' . $dmy((string) ($a['joining_date'] ?? '')) . (trim((string) ($a['exit_date'] ?? '')) !== '' ? ' to ' . $dmy((string) $a['exit_date']) : '')]))) ?></p></div>
  <div class="actions">
<?php if ($opMayEdit): ?><a class="btn btn-outline btn-sm" href="?r=m&amp;k=offroll-agents&amp;edit=<?= e($id) ?>"><i class="fas fa-pen"></i> Edit details</a><?php endif; ?>
    <a class="btn btn-outline btn-sm" href="?r=offroll-agents">&larr; All agents</a>
  </div>
</div>

<div class="row" style="align-items:stretch;gap:14px;flex-wrap:wrap">
  <div class="card" style="flex:1;min-width:300px;margin:0">
    <h3 style="margin-top:0">Deployment &amp; payee</h3>
    <table class="op-kv" style="width:100%"><tbody>
      <tr><th>Supervisor</th><td><?= e($opEmpLabel[(string) ($a['supervisor_employee_id'] ?? '')] ?? '—') ?></td></tr>
      <tr><th>Vendor / agency</th><td><?= e($opVendorName($a) ?: '—') ?></td></tr>
      <tr><th>Payout goes to</th><td><?= OffrollPay::paidTo($a) === 'vendor' ? 'The vendor (' . e($opVendorName($a)) . ')' : 'The agent directly' ?></td></tr>
      <tr><th>Mobile &middot; email</th><td><?= e((string) ($a['mobile'] ?? '—')) ?> &middot; <?= e((string) ($a['email'] ?? '—')) ?></td></tr>
      <tr><th>PAN &middot; TDS</th><td><?= e((string) ($a['pan'] ?? '')) ?: '<span class="badge badge-red">No PAN — higher TDS rate</span>' ?> &middot; <?= e((string) ($opD['tds']['sections'][trim((string) ($a['tds_section'] ?? '')) ?: $opSet['tds_section']]['label'] ?? '')) ?><?= trim((string) ($a['tds_section'] ?? '')) === '' ? ' <span class="note">(default)</span>' : '' ?></td></tr>
      <tr><th>Bank</th><td><?= e(trim(implode(' · ', array_filter([(string) ($a['bank_name'] ?? ''), (string) ($a['bank_account'] ?? ''), (string) ($a['ifsc'] ?? ''), (string) ($a['upi'] ?? '')])))) ?: '<span class="badge badge-red">Not on file</span>' ?></td></tr>
    </tbody></table>
  </div>
  <div class="card" style="flex:1;min-width:300px;margin:0">
    <h3 style="margin-top:0">Certificates &amp; bank letters</h3>
    <table class="op-kv" style="width:100%"><tbody>
<?php foreach (['dra' => 'DRA (IIBF)', 'pcc' => 'Police clearance'] as $c => $lab): $b = $opBand((string) ($a[$c . '_expiry'] ?? '')); ?>
      <tr><th><?= e($lab) ?></th><td><?= e((string) ($a[$c . '_cert_no'] ?? '')) ?: '—' ?> &middot; issued <?= e($dmy((string) ($a[$c . '_issue_date'] ?? ''))) ?> &middot; valid up to <b><?= e($dmy((string) ($a[$c . '_expiry'] ?? ''))) ?></b> <span class="badge <?= $opBadge[$b] ?>"><?= e($opBandLabel[$b]) ?></span></td></tr>
<?php endforeach; ?>
<?php if ($auths === []): ?>
      <tr><th>Bank letter</th><td><span class="badge badge-gray">None recorded</span></td></tr>
<?php endif; ?>
<?php foreach ($auths as $au): $b = $opBand((string) ($au['valid_to'] ?? '')); ?>
      <tr><th>Bank letter</th><td><?= e((string) ($au['bank'] ?? '')) ?> &middot; <?= e((string) ($au['portfolio'] ?? '')) ?> &middot; <?= e((string) ($au['auth_no'] ?? '')) ?> &middot; <?= e($dmy((string) ($au['valid_from'] ?? ''))) ?> to <b><?= e($dmy((string) ($au['valid_to'] ?? ''))) ?></b> <span class="badge <?= $opBadge[$b] ?>"><?= e($opBandLabel[$b]) ?></span></td></tr>
<?php endforeach; ?>
    </tbody></table>
<?php if ($opMayEdit): ?><p style="margin:8px 0 0"><a class="btn btn-outline btn-sm" href="?r=m&amp;k=agent-authorizations&amp;add=1#add-row"><i class="fas fa-plus"></i> Add bank letter</a></p><?php endif; ?>
  </div>
</div>

<div class="card">
  <h3 style="margin-top:0">KYC documents</h3>
  <div class="table-wrap"><table><tbody>
<?php foreach ((array) $opD['documents'] as $slot => $label): $path = trim((string) ($a[$slot] ?? '')); ?>
    <tr><td><b><?= e((string) $label) ?></b></td>
      <td><?= $path !== '' ? '<span class="badge badge-green">Uploaded</span> <a href="' . e($path) . '" target="_blank"><i class="fas fa-paperclip"></i> ' . e(basename($path)) . '</a>' : '<span class="badge badge-red">Not uploaded</span>' ?></td>
      <td style="text-align:right"><?php if ($opMayEdit): ?><form method="post" enctype="multipart/form-data" style="display:inline-flex;gap:6px;align-items:center;margin:0"><?= $hidden() ?><input type="hidden" name="action" value="offroll-agent-save"><input type="hidden" name="op" value="upload"><input type="hidden" name="slot" value="<?= e((string) $slot) ?>">
        <input type="file" name="<?= e((string) $slot) ?>" accept="<?= e(EMPLOYEE_FILE_ACCEPT) ?>" required style="width:220px;font-size:12px"><button class="btn btn-sm btn-outline" type="submit"><i class="fas fa-upload"></i> <?= $path !== '' ? 'Replace' : 'Upload' ?></button></form><?php endif; ?></td></tr>
<?php endforeach; ?>
  </tbody></table></div>
</div>

<div class="card" id="pay">
  <h3 style="margin-top:0">Pay structure <span class="note" style="font-weight:normal">in force today: <?= e($op->planSummary($planNow)) ?></span></h3>
  <div class="table-wrap"><table>
    <thead><tr><th>From</th><th>Pay structure</th><th>Note</th><th>Recorded by</th></tr></thead><tbody>
<?php if ($plans === []): ?><tr><td colspan="4" class="note">No pay structure yet<?= $planNow !== null ? ' — paying on the register\'s old rate: ' . e($op->planSummary($planNow)) : '' ?>.</td></tr><?php endif; ?>
<?php foreach ($plans as $p): ?>
    <tr><td style="white-space:nowrap"><b><?= e($dmy((string) $p['effective_from'])) ?></b><?= $planNow !== null && (string) ($planNow['id'] ?? '') === (string) $p['id'] ? ' <span class="badge badge-green">in force</span>' : '' ?></td>
      <td><?= e($op->planSummary($p)) ?></td><td class="note"><?= e((string) ($p['note'] ?? '')) ?></td><td class="note"><?= e((string) ($p['created_by'] ?? '')) ?></td></tr>
<?php endforeach; ?>
    </tbody></table></div>
<?php if ($opMayEdit): ?>
  <fieldset class="form-section" style="margin-top:12px"><legend>Revise the pay structure (a new version from a date — the old one is kept)</legend>
  <form method="post" class="row" style="align-items:flex-end;gap:10px;flex-wrap:wrap;margin:0"><?= $hidden() ?><input type="hidden" name="action" value="offroll-plan"><input type="hidden" name="op" value="save">
    <div><label>In force from *</label><input type="date" name="effective_from" value="<?= e($month . '-01') ?>" required></div>
<?php foreach ((array) $opD['plan_components'] as $k => $c): ?>
    <div><label><?= e((string) $c['label']) ?></label><input name="<?= e((string) $k) ?>" inputmode="decimal" value="<?= e(trim((string) ($planNow[$k] ?? '')) === '' ? '' : rtrim(rtrim((string) $planNow[$k], '0'), '.')) ?>" style="width:120px"></div>
<?php endforeach; ?>
    <div><label>Monthly cap (₹)</label><input name="monthly_cap" inputmode="decimal" value="<?= e(trim((string) ($planNow['monthly_cap'] ?? '')) === '' ? '' : rtrim(rtrim((string) $planNow['monthly_cap'], '0'), '.')) ?>" style="width:120px"></div>
    <div style="flex-basis:100%"></div>
    <div><label>Collection slabs (instead of a flat %)</label>
      <div class="note" style="margin-bottom:4px">Each band's % applies to the part of the month's collection inside it; leave the last "up to" blank for no top.</div>
      <div class="row" style="gap:6px;flex-wrap:wrap">
<?php for ($i = 0; $i < 4; $i++): ?>
        <span style="display:inline-flex;gap:4px;align-items:center;border:1px solid var(--border);border-radius:8px;padding:4px 6px">up to ₹<input name="slab_upto[]" inputmode="decimal" value="<?= e((string) ($slabs[$i]['upto'] ?? '')) ?>" style="width:100px"> at <input name="slab_pct[]" inputmode="decimal" value="<?= e((string) ($slabs[$i]['percent'] ?? '')) ?>" style="width:60px">%</span>
<?php endfor; ?>
      </div></div>
    <div style="flex:1;min-width:200px"><label>Note</label><input name="note" placeholder="e.g. revised after Q2 review"></div>
    <div><button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-check"></i> Save pay structure</button></div>
  </form></fieldset>
<?php endif; ?>
</div>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <h3 style="margin:0">Attendance &amp; earnings &mdash; <?= e(date('F Y', (int) strtotime($month . '-01'))) ?></h3>
    <form method="get" class="row" style="margin:0;gap:6px;align-items:flex-end"><input type="hidden" name="r" value="offroll-agent"><input type="hidden" name="id" value="<?= e($id) ?>">
      <div><label>Month</label><input type="month" name="month" value="<?= e($month) ?>"></div><div><button class="go" type="submit">Show</button></div></form>
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:3px;margin:12px 0">
<?php for ($dt = new DateTimeImmutable($month . '-01'); $dt->format('Y-m-d') <= $monthEnd; $dt = $dt->modify('+1 day')): $k = $dt->format('Y-m-d'); $r = $byDate[$k] ?? null; $s = (string) ($r['day_status'] ?? ''); ?>
    <a href="?r=offroll-activity&amp;month=<?= e($month) ?>&amp;agent=<?= e($id) ?>&amp;date=<?= e($k) ?>#entry" title="<?= e(Dates::dmy($k) . ($r === null ? ' — nothing recorded' : ' — ' . ($st[$s]['label'] ?? $s) . ', ' . (int) ($r['visits'] ?? 0) . ' visits, ' . Amount::of((string) ($r['amount_collected'] ?? ''))->format() . ' collected, ' . (string) ($r['approval'] ?? ''))) ?>"
      style="display:inline-flex;flex-direction:column;align-items:center;justify-content:center;width:38px;height:44px;border-radius:6px;border:1px solid <?= ($r['approval'] ?? '') === 'pending' ? '#f59e0b' : 'var(--border)' ?>;background:<?= $stColor[$s] ?? 'transparent' ?>;text-decoration:none;color:var(--text1);font-size:11px">
      <span style="opacity:.65"><?= e($dt->format('d')) ?></span><b><?= e((string) ($st[$s]['short'] ?? '·')) ?></b></a>
<?php endfor; ?>
  </div>
  <p class="note" style="margin:0 0 10px">P present · H half day · A absent · L leave · WO week off · amber border = pending approval (<?= $pendingDays ?> day(s)). Click a day to record or correct it.</p>
  <div style="background:linear-gradient(135deg,#0f1d33,#1e3a5f);color:#fff;border-radius:12px;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <div><div style="font-size:10.5px;letter-spacing:1px;text-transform:uppercase;opacity:.7">Earned so far (before TDS)</div><div style="font-size:26px;font-weight:800"><?= e($opMoney($live['gross'])) ?></div>
      <div style="font-size:12px;opacity:.8"><?= e(rtrim(rtrim($live['days_present'], '0'), '.') ?: '0') ?> day(s) · <?= (int) $live['visits'] ?> visits · <?= (int) $live['ptp_count'] ?> PTP · <?= (int) $live['paid_accounts'] ?> paid · <?= e($opMoney($live['amount_collected'])) ?> collected</div></div>
    <div style="font-size:12px;max-width:520px;opacity:.9"><?= e(implode(' · ', $live['explain']) ?: 'Nothing counted yet this month.') ?><?= $opSet['count_basis'] === 'approved' && $pendingDays > 0 ? '<br><span style="color:#fde68a">' . $pendingDays . ' day(s) pending approval are not counted yet.</span>' : '' ?></div>
  </div>
<?php if ($live['flags'] !== []): ?><p class="problem" style="margin:10px 0 0"><?= e(implode('; ', $live['flags'])) ?></p><?php endif; ?>
</div>

<div class="card">
  <h3 style="margin-top:0">Adjustments, deductions &amp; recoveries &mdash; <?= e(date('F Y', (int) strtotime($month . '-01'))) ?></h3>
<?php if ($opMayEdit): ?>
  <form method="post" class="row" style="align-items:flex-end;gap:10px;flex-wrap:wrap;margin:0 0 10px"><?= $hidden() ?><input type="hidden" name="action" value="offroll-earning"><input type="hidden" name="op" value="record">
    <div><label>Type</label><select name="kind"><?php foreach ((array) $opD['kinds_manual'] as $k): ?><option value="<?= e((string) $k) ?>"><?= e((string) $opD['kinds'][$k]['label']) ?></option><?php endforeach; ?></select></div>
    <div><label>Amount (₹) *</label><input name="amount" inputmode="decimal" required style="width:120px"></div>
    <div><label>Date</label><input type="date" name="date" value="<?= e(min($today, $monthEnd)) ?>" required></div>
    <div style="flex:1;min-width:200px"><label>Reason *</label><input name="note" required placeholder="e.g. festival bonus · penalty for missed PTP · advance recovery"></div>
    <div><button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-plus"></i> Add</button></div>
  </form>
<?php endif; ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Entry</th><th class="n">Amount</th><th>Status</th><th>By</th><th>In payout</th><th></th></tr></thead><tbody>
<?php if ($ledgerAll === []): ?><tr><td colspan="7" class="note">None this month.</td></tr><?php endif; ?>
<?php foreach ($ledgerAll as $l): $k = (array) ($opD['kinds'][(string) $l['kind']] ?? ['label' => $l['kind']]); ?>
    <tr style="<?= (string) $l['status'] === 'rejected' ? 'opacity:.5' : '' ?>">
      <td class="note" style="white-space:nowrap"><?= e($dmy((string) $l['entry_date'])) ?></td>
      <td><b><?= e((string) $k['label']) ?></b><div class="note"><?= e((string) ($l['note'] ?? '')) ?></div></td>
      <td class="n" style="white-space:nowrap;font-weight:700;color:<?= $l['sign'] === '-' ? '#b91c1c' : '#15803d' ?>"><?= e($l['sign']) ?> <?= e($l['money']->format()) ?></td>
      <td><span class="badge <?= ['approved' => 'badge-green', 'rejected' => 'badge-red'][(string) $l['status']] ?? 'badge-amber' ?>"><?= e((string) ($opD['statuses'][(string) $l['status']] ?? $l['status'])) ?></span></td>
      <td class="note"><?= e((string) ($l['created_by'] ?? '')) ?></td>
      <td class="note"><?= trim((string) ($l['payout_id'] ?? '')) !== '' && isset($runs[(string) $l['payout_id']]) ? e(date('M Y', (int) strtotime($runs[(string) $l['payout_id']]['period'] . '-01'))) : '—' ?></td>
      <td style="text-align:right;white-space:nowrap"><?php if ($opMayApprove && (string) $l['status'] === 'pending'): ?>
        <form method="post" style="display:inline-flex;gap:4px;align-items:center"><?= $hidden() ?><input type="hidden" name="action" value="offroll-earning"><input type="hidden" name="op" value="decide"><input type="hidden" name="entry" value="<?= e((string) $l['id']) ?>">
          <input name="remarks" placeholder="remarks" style="width:8rem">
          <button class="btn btn-outline btn-sm" name="decision" value="approve" title="Approve"><i class="fas fa-check"></i></button>
          <button class="btn btn-outline btn-sm" name="decision" value="reject" title="Reject"><i class="fas fa-xmark"></i></button></form>
      <?php endif; ?></td></tr>
<?php endforeach; ?>
    </tbody></table></div>
</div>

<div class="card" style="padding:0">
  <div class="card-header"><h3 style="margin:0">Payouts &amp; payments</h3></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Month</th><th class="n">Days</th><th class="n">Visits</th><th class="n">Collected</th><th class="n">Gross</th><th class="n">TDS</th><th class="n">Net</th><th>Payout</th><th>Payment</th></tr></thead><tbody>
<?php if ($lines === []): ?><tr><td colspan="9" class="note">No payout yet.</td></tr><?php endif; ?>
<?php foreach ($lines as $l): $run = $runs[(string) $l['payout_id']]; ?>
    <tr><td><a href="?r=offroll-payouts&amp;run=<?= e((string) $run['id']) ?>#line-<?= e((string) $l['id']) ?>"><?= e(date('F Y', (int) strtotime((string) $run['period'] . '-01'))) ?></a></td>
      <td class="n"><?= e(rtrim(rtrim((string) $l['days_present'], '0'), '.') ?: '0') ?></td><td class="n"><?= (int) $l['visits'] ?></td>
      <td class="n"><?= e($opMoney((string) $l['amount_collected'])) ?></td><td class="n"><?= e($opMoney((string) $l['gross'])) ?></td><td class="n"><?= e($opMoney((string) $l['tds_amount'])) ?></td><td class="n"><b><?= e($opMoney((string) $l['net'])) ?></b></td>
      <td><span class="badge <?= (string) $run['status'] === 'approved' ? 'badge-green' : 'badge-amber' ?>"><?= e((string) ($opD['payout_statuses'][(string) $run['status']] ?? $run['status'])) ?></span></td>
      <td><span class="badge <?= ['paid' => 'badge-green', 'on_hold' => 'badge-red'][(string) $l['payment_status']] ?? 'badge-amber' ?>"><?= e((string) ($opD['payment_statuses'][(string) $l['payment_status']] ?? $l['payment_status'])) ?></span>
        <?= (string) $l['payment_status'] === 'paid' ? '<div class="note">' . e($dmy((string) $l['paid_on']) . ' · ' . strtoupper((string) $l['payment_mode']) . ' ' . (string) ($l['payment_ref'] ?? '')) . '</div>' : (trim((string) ($l['hold_reason'] ?? '')) !== '' ? '<div class="note">' . e((string) $l['hold_reason']) . '</div>' : '') ?></td></tr>
<?php endforeach; ?>
    </tbody></table></div>
</div>

<details class="card" style="padding:0">
  <summary class="card-header" style="cursor:pointer"><h3 style="margin:0;display:inline">Mobile app log <span class="note" style="font-weight:normal">last 30 check-ins, check-outs and visits (<?= count($visits) ?>) — click to open</span></h3></summary>
  <div class="table-wrap"><table>
    <thead><tr><th>When</th><th>Event</th><th>Account</th><th class="n">Amount</th><th>GPS</th><th>Note</th></tr></thead><tbody>
<?php if ($visits === []): ?><tr><td colspan="6" class="note">Nothing from the app yet.</td></tr><?php endif; ?>
<?php foreach ($visits as $v): ?>
    <tr><td class="note" style="white-space:nowrap"><?= e(Dates::dmy(substr((string) $v['visit_at'], 0, 10)) . ' ' . substr((string) $v['visit_at'], 11, 5)) ?></td>
      <td><?= e(['login' => 'Check-in', 'logout' => 'Check-out'][(string) $v['outcome']] ?? (string) ($opD['visit_outcomes'][(string) $v['outcome']] ?? $v['outcome'])) ?></td>
      <td><?= e((string) ($v['account_ref'] ?? '')) ?></td><td class="n"><?= trim((string) ($v['amount'] ?? '')) === '' ? '' : e($opMoney((string) $v['amount'])) ?></td>
      <td class="note"><?= trim((string) ($v['geo'] ?? '')) === '' ? '—' : '<a target="_blank" rel="noopener" href="https://maps.google.com/?q=' . e((string) $v['geo']) . '">' . e((string) $v['geo']) . '</a>' ?></td><td class="note"><?= e((string) ($v['note'] ?? '')) ?></td></tr>
<?php endforeach; ?>
    </tbody></table></div>
</details>
