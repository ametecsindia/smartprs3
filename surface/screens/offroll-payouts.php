<?php declare(strict_types=1); if (!$may('employee.view')): ?>
<div class="page-header"><div><h2>Agent Payouts</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// FIELD FORCE → AGENT PAYOUTS (23 Oct 2026). A company's month, priced: Generate builds one line per agent
// from the pay structure in force, the approved days and the approved ledger, with TDS by the Government rule
// and a hold on any agent who worked without a valid DRA / PCC (Organization Configuration's choice). A second
// person approves; the lines are then PENDING PAYMENT until marked paid with the date and UTR. Vendor-paid
// agents are paid as their vendor, together. Every figure carries its derivation (Explain).
use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\Amount;
use SmartPRS\Surface\Dates;

include __DIR__ . '/../partials/offroll-boot.php';

if (!$opReady) {
    echo '<div class="page-header"><div><h2>Agent Payouts</h2></div></div>' . $opNotReady;
    return;
}

$view = (string) ($_GET['view'] ?? '') === 'pending' ? 'pending' : 'runs';
$runs = $op->runs();
$runsById = array_column($runs, null, 'id');
$sel = $runsById[(string) ($_GET['run'] ?? '')] ?? null;
$allLines = $op->lines();
$byRun = [];

foreach ($allLines as $l) {
    isset($opAgents[(string) $l['agent_id']]) && $byRun[(string) $l['payout_id']][] = $l;
}

$money = static fn (array $ls, string $col, ?string $status = null): Money => Money::sum(Money::zero(), ...array_map(static fn (array $l): Money => Amount::of((string) ($l[$col] ?? '')),
    array_values(array_filter($ls, static fn (array $l): bool => $status === null || (string) $l['payment_status'] === $status))));
$payBadge = ['paid' => 'badge-green', 'on_hold' => 'badge-red', 'pending' => 'badge-amber'];
$dmy = static fn (string $d): string => $d === '' ? '—' : Dates::dmy(substr($d, 0, 10));
$month = static fn (string $p): string => date('F Y', (int) strtotime($p . '-01'));
$csrf = '<input type="hidden" name="csrf" value="' . e($session->token()) . '"><input type="hidden" name="action" value="offroll-payout">';
$latestActivity = '';

foreach ($op->activity() as $r) {
    $latestActivity = max($latestActivity, (string) ($r['updated_at'] ?? ''));
}

// PENDING: approved runs' unpaid lines; a vendor's lines in one run are one payment.
$pending = [];

foreach ($runs as $run) {
    if ((string) $run['status'] !== 'approved') {
        continue;
    }

    foreach ($byRun[(string) $run['id']] ?? [] as $l) {
        if ((string) $l['payment_status'] === 'paid') {
            continue;
        }

        $key = (string) $l['paid_to'] === 'vendor' ? 'v' . $run['id'] . '-' . $l['vendor_id'] : 'a' . $l['id'];
        $pending[$key]['run'] = $run;
        $pending[$key]['lines'][] = $l;
    }
}

$modes = (array) $opD['payment_modes'];
$payForm = static function (string $formId) use ($csrf, $modes): string {
    $o = '';
    foreach ($modes as $k => $v) {
        $o .= '<option value="' . e((string) $k) . '">' . e((string) $v) . '</option>';
    }

    return '<form method="post" id="' . e($formId) . '" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;padding:12px 14px;margin:0;border-bottom:1px solid var(--border)">' . $csrf
        . '<input type="hidden" name="op" value="pay"><b style="align-self:center">Mark ticked as paid:</b>'
        . '<div><label>Paid on</label><input type="date" name="paid_on" value="' . e(date('Y-m-d')) . '" max="' . e(date('Y-m-d')) . '" required></div>'
        . '<div><label>Mode</label><select name="mode">' . $o . '</select></div>'
        . '<div><label>UTR / reference</label><input name="ref" placeholder="bank UTR" style="width:12rem"></div>'
        . '<div><button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-check-double"></i> Mark paid</button></div>'
        . '<span class="note" style="align-self:center">A vendor\'s lines are paid together as one payment.</span></form>';
};
?>
<div class="page-header">
  <div><h2>Agent Payouts</h2><p>Off-roll agents' monthly payout per company &middot; TDS <?= $opSet['tds_enabled'] === 'yes' ? 'on (' . e((string) ($opD['tds']['sections'][$opSet['tds_section']]['label'] ?? '')) . ' by default)' : 'off' ?> &middot; expired certificate: <?= e(strtolower((string) ($opD['settings_options']['expired_certificate'][$opSet['expired_certificate']] ?? ''))) ?> &middot; <a href="?r=offroll-settings">Organization Configuration</a></p></div>
  <div class="actions">
    <a class="btn <?= $view === 'runs' ? 'btn-primary' : 'btn-outline' ?> btn-sm" href="?r=offroll-payouts">Payout runs</a>
    <a class="btn <?= $view === 'pending' ? 'btn-primary' : 'btn-outline' ?> btn-sm" href="?r=offroll-payouts&amp;view=pending">Pending payment (<?= count($pending) ?>)</a>
  </div>
</div>

<?php if ($view === 'pending'): ?>
<div class="card" style="padding:0">
<?php if ($opMayApprove && $pending !== []): ?><?= $payForm('op-pay') ?><?php endif; ?>
  <div class="table-wrap"><table>
    <thead><tr><?= $opMayApprove ? '<th></th>' : '' ?><th>Month</th><th>Company</th><th>Payee</th><th>Bank / UPI</th><th class="n">Agents</th><th class="n">Gross</th><th class="n">TDS</th><th class="n">Net payable</th><th>Status</th></tr></thead><tbody>
<?php if ($pending === []): ?><tr><td colspan="10" class="note">Nothing approved is waiting for payment.</td></tr><?php endif; ?>
<?php $sumNet = Money::zero(); foreach ($pending as $key => $g): $l0 = $g['lines'][0]; $isV = (string) $l0['paid_to'] === 'vendor'; $v = $isV ? ($opVendors[(string) $l0['vendor_id']] ?? []) : null; $a = $opAgents[(string) $l0['agent_id']];
    $payee = $isV ? $v : $a; $held = array_filter($g['lines'], static fn (array $l): bool => (string) $l['payment_status'] === 'on_hold'); $net = $money($g['lines'], 'net'); $sumNet = $sumNet->plus($net); ?>
    <tr>
<?php if ($opMayApprove): ?><td><input type="checkbox" form="op-pay" name="lines[]" value="<?= e((string) $l0['id']) ?>" <?= $held !== [] ? 'disabled title="On hold — release it first"' : '' ?>></td><?php endif; ?>
      <td style="white-space:nowrap"><a href="?r=offroll-payouts&amp;run=<?= e((string) $g['run']['id']) ?>"><?= e($month((string) $g['run']['period'])) ?></a></td>
      <td><?= e($opCompanies[(string) ($g['run']['employer_company_id'] ?? '')] ?? '—') ?></td>
      <td><?= $isV ? '<b>' . e((string) ($v['name'] ?? 'Vendor')) . '</b> <span class="badge badge-gray">vendor</span>' : '<a href="?r=offroll-agent&amp;id=' . e((string) $a['id']) . '">' . e($op->label($a)) . '</a>' ?><div class="note">PAN <?= e((string) ($payee['pan'] ?? '') ?: 'not on file') ?></div></td>
      <td class="note"><?= e(trim(implode(' · ', array_filter([(string) ($payee['bank_name'] ?? ''), (string) ($payee['bank_account'] ?? ''), (string) ($payee['ifsc'] ?? ''), (string) ($payee['upi'] ?? '')])))) ?: '<span class="badge badge-red">no bank on file</span>' ?></td>
      <td class="n"><?= count($g['lines']) ?></td>
      <td class="n"><?= e($money($g['lines'], 'gross')->format()) ?></td><td class="n"><?= e($money($g['lines'], 'tds_amount')->format()) ?></td><td class="n"><b><?= e($net->format()) ?></b></td>
      <td><?= $held !== [] ? '<span class="badge badge-red">On hold</span><div class="note">' . e((string) (array_values($held)[0]['hold_reason'] ?? '')) . '</div>' : '<span class="badge badge-amber">Pending payment</span>' ?></td>
    </tr>
<?php endforeach; ?>
<?php if ($pending !== []): ?><tr style="font-weight:700"><td colspan="<?= $opMayApprove ? 8 : 7 ?>">Total waiting</td><td class="n"><?= e($sumNet->format()) ?></td><td></td></tr><?php endif; ?>
    </tbody></table></div>
</div>
<?php return; endif; ?>

<?php if ($opMayApprove): ?>
<div class="card">
  <form method="post" class="row" style="align-items:flex-end;gap:10px;flex-wrap:wrap;margin:0"><?= $csrf ?><input type="hidden" name="op" value="generate">
    <div><label>Company *</label><select name="employer_company_id" required><option value="">— pick —</option><?php foreach ($opCompanies as $k => $v): ?><option value="<?= e($k) ?>" <?= (string) ($sel['employer_company_id'] ?? '') === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div><label>Month *</label><input type="month" name="period" value="<?= e((string) ($sel['period'] ?? date('Y-m', strtotime('first day of last month')))) ?>" max="<?= e(date('Y-m')) ?>" required></div>
    <div><button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-gears"></i> Generate / regenerate draft</button></div>
    <p class="note" style="margin:0;flex:1;min-width:260px">Counts <?= $opSet['count_basis'] === 'all' ? 'approved and pending' : 'approved' ?> days and approved adjustments of the month. A draft can be regenerated after corrections; an approved payout is fixed.</p>
  </form>
</div>
<?php endif; ?>

<div class="card" style="padding:0">
  <div class="table-wrap"><table>
    <thead><tr><th>Month</th><th>Company</th><th>Status</th><th class="n">Lines</th><th class="n">Gross</th><th class="n">TDS</th><th class="n">Net</th><th class="n">Paid</th><th class="n">Pending / held</th><th>Generated</th><th>Approved</th></tr></thead><tbody>
<?php if ($runs === []): ?><tr><td colspan="11" class="note">No payout yet — generate one above.</td></tr><?php endif; ?>
<?php foreach ($runs as $run): $ls = $byRun[(string) $run['id']] ?? []; ?>
    <tr style="<?= $sel !== null && (string) $sel['id'] === (string) $run['id'] ? 'background:var(--hover, #f8fafc)' : '' ?>">
      <td><a href="?r=offroll-payouts&amp;run=<?= e((string) $run['id']) ?>"><b><?= e($month((string) $run['period'])) ?></b></a></td>
      <td><?= e($opCompanies[(string) ($run['employer_company_id'] ?? '')] ?? '—') ?></td>
      <td><span class="badge <?= (string) $run['status'] === 'approved' ? 'badge-green' : 'badge-amber' ?>"><?= e((string) ($opD['payout_statuses'][(string) $run['status']] ?? $run['status'])) ?></span></td>
      <td class="n"><?= count($ls) ?></td><td class="n"><?= e($money($ls, 'gross')->format()) ?></td><td class="n"><?= e($money($ls, 'tds_amount')->format()) ?></td><td class="n"><b><?= e($money($ls, 'net')->format()) ?></b></td>
      <td class="n"><?= e($money($ls, 'net', 'paid')->format()) ?></td><td class="n"><?= e($money($ls, 'net', 'pending')->plus($money($ls, 'net', 'on_hold'))->format()) ?></td>
      <td class="note"><?= e((string) ($run['created_by'] ?? '')) ?><br><?= e($dmy((string) ($run['updated_at'] ?? $run['created_at'] ?? ''))) ?></td>
      <td class="note"><?= e((string) ($run['approved_by'] ?? '—')) ?><br><?= e($dmy((string) ($run['approved_at'] ?? ''))) ?></td>
    </tr>
<?php endforeach; ?>
    </tbody></table></div>
</div>

<?php if ($sel !== null): $ls = $byRun[(string) $sel['id']] ?? []; $isDraft = (string) $sel['status'] === 'draft';
    usort($ls, static fn (array $x, array $y): int => strcmp($op->code($opAgents[(string) $x['agent_id']]), $op->code($opAgents[(string) $y['agent_id']]))); ?>
<div class="card" style="padding:0">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <div><h3 style="margin:0"><?= e($month((string) $sel['period'])) ?> &middot; <?= e($opCompanies[(string) ($sel['employer_company_id'] ?? '')] ?? '') ?> <span class="badge <?= $isDraft ? 'badge-amber' : 'badge-green' ?>"><?= e((string) ($opD['payout_statuses'][(string) $sel['status']] ?? $sel['status'])) ?></span></h3>
      <p class="note" style="margin:2px 0 0"><?= count($ls) ?> line(s) &middot; gross <?= e($money($ls, 'gross')->format()) ?> &middot; TDS <?= e($money($ls, 'tds_amount')->format()) ?> &middot; net <b><?= e($money($ls, 'net')->format()) ?></b>
        <?php $flagged = count(array_filter($ls, static fn (array $l): bool => trim((string) ($l['flags'] ?? '')) !== '')); echo $flagged > 0 ? ' &middot; <b style="color:#b91c1c">' . $flagged . ' flagged for certificates</b>' : ''; ?>
        <?= $isDraft && $latestActivity !== '' && $latestActivity > (string) ($sel['updated_at'] ?? '') ? ' &middot; <b style="color:#b45309">activity changed after this draft — regenerate</b>' : '' ?></p></div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <form method="post" style="margin:0"><?= $csrf ?><input type="hidden" name="op" value="advice"><input type="hidden" name="what" value="register"><input type="hidden" name="run" value="<?= e((string) $sel['id']) ?>"><button class="btn btn-outline btn-sm" type="submit"><i class="fas fa-file-excel"></i> Payout register</button></form>
      <form method="post" style="margin:0"><?= $csrf ?><input type="hidden" name="op" value="advice"><input type="hidden" name="what" value="bank"><input type="hidden" name="run" value="<?= e((string) $sel['id']) ?>"><button class="btn btn-outline btn-sm" type="submit"><i class="fas fa-building-columns"></i> Bank advice</button></form>
<?php if ($opMayApprove && $isDraft): ?>
      <form method="post" style="margin:0"><?= $csrf ?><input type="hidden" name="op" value="generate"><input type="hidden" name="employer_company_id" value="<?= e((string) $sel['employer_company_id']) ?>"><input type="hidden" name="period" value="<?= e((string) $sel['period']) ?>"><button class="btn btn-outline btn-sm" type="submit"><i class="fas fa-rotate"></i> Regenerate</button></form>
      <form method="post" style="margin:0"><?= $csrf ?><input type="hidden" name="op" value="approve"><input type="hidden" name="run" value="<?= e((string) $sel['id']) ?>"><button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-check"></i> Approve payout</button></form>
      <form method="post" style="margin:0"><?= $csrf ?><input type="hidden" name="op" value="discard"><input type="hidden" name="run" value="<?= e((string) $sel['id']) ?>"><button class="btn btn-outline btn-sm" type="submit" title="Discard this draft"><i class="fas fa-trash"></i> Discard draft</button></form>
<?php endif; ?>
    </div>
  </div>
<?php if ($opMayApprove && !$isDraft): ?><?= $payForm('op-pay-run') ?><?php endif; ?>
  <div class="table-wrap"><table style="font-size:12.5px">
    <thead><tr><?= $opMayApprove && !$isDraft ? '<th></th>' : '' ?><th>Agent &middot; paid to</th><th class="n">Days</th><th class="n">Visits</th><th class="n">PTP / paid</th><th class="n">Collected</th><th class="n">Fixed</th><th class="n">Variable</th><th class="n">Collection</th><th class="n">Adj. / ded.</th><th class="n">Gross</th><th class="n">TDS</th><th class="n">Net</th><th>Payment</th><th></th></tr></thead><tbody>
<?php if ($ls === []): ?><tr><td colspan="15" class="note">No lines.</td></tr><?php endif; ?>
<?php foreach ($ls as $l): $a = $opAgents[(string) $l['agent_id']]; $var = Amount::of((string) $l['day_amount'])->plus(Amount::of((string) $l['visit_amount']))->plus(Amount::of((string) $l['ptp_amount']))->plus(Amount::of((string) $l['paid_account_amount']));
    $why = (array) json_decode((string) ($l['explain'] ?? '[]'), true); ?>
    <tr id="line-<?= e((string) $l['id']) ?>">
<?php if ($opMayApprove && !$isDraft): ?><td><input type="checkbox" form="op-pay-run" name="lines[]" value="<?= e((string) $l['id']) ?>" <?= (string) $l['payment_status'] !== 'pending' ? 'disabled' : '' ?>></td><?php endif; ?>
      <td style="white-space:nowrap"><a href="?r=offroll-agent&amp;id=<?= e((string) $a['id']) ?>&amp;month=<?= e((string) $sel['period']) ?>"><?= e($op->label($a)) ?></a><div class="note" style="font-size:11px"><?= (string) $l['paid_to'] === 'vendor' ? 'via ' . e((string) ($opVendors[(string) $l['vendor_id']]['name'] ?? 'vendor')) : 'paid to the agent' ?></div><?= trim((string) ($l['flags'] ?? '')) !== '' ? '<div style="color:#b91c1c;font-size:11px;max-width:260px;white-space:normal"><i class="fas fa-triangle-exclamation"></i> ' . e((string) $l['flags']) . '</div>' : '' ?></td>
      <td class="n"><?= e(rtrim(rtrim((string) $l['days_present'], '0'), '.') ?: '0') ?></td><td class="n"><?= (int) $l['visits'] ?></td><td class="n"><?= (int) $l['ptp_count'] ?> / <?= (int) $l['paid_accounts'] ?></td>
      <td class="n"><?= e($opMoney((string) $l['amount_collected'])) ?></td><td class="n"><?= e($opMoney((string) $l['fixed_amount'])) ?></td><td class="n"><?= e($var->format()) ?></td><td class="n"><?= e($opMoney((string) $l['collection_amount'])) ?></td>
      <td class="n" style="white-space:nowrap"><?= Amount::of((string) $l['adjustments'])->isZero() ? '' : e('+ ' . $opMoney((string) $l['adjustments'])) . '<br>' ?><?= Amount::of((string) $l['deductions'])->isZero() ? '' : e('− ' . $opMoney((string) $l['deductions'])) ?><?= Amount::of((string) $l['adjustments'])->isZero() && Amount::of((string) $l['deductions'])->isZero() ? '<span class="note">—</span>' : '' ?></td>
      <td class="n"><?= e($opMoney((string) $l['gross'])) ?></td><td class="n"><?= e($opMoney((string) $l['tds_amount'])) ?><div class="note" style="font-size:10.5px"><?= e(rtrim(rtrim((string) $l['tds_rate'], '0'), '.') ?: '0') ?>%</div></td><td class="n"><b><?= e($opMoney((string) $l['net'])) ?></b></td>
      <td><span class="badge <?= $payBadge[(string) $l['payment_status']] ?? 'badge-gray' ?>"><?= e((string) ($opD['payment_statuses'][(string) $l['payment_status']] ?? $l['payment_status'])) ?></span>
        <?= (string) $l['payment_status'] === 'paid' ? '<div class="note" style="font-size:11px">' . e($dmy((string) $l['paid_on']) . ' · ' . strtoupper((string) $l['payment_mode']) . ' ' . (string) ($l['payment_ref'] ?? '')) . '</div>' : (trim((string) ($l['hold_reason'] ?? '')) !== '' ? '<div class="note" style="font-size:11px;max-width:200px">' . e((string) $l['hold_reason']) . '</div>' : '') ?>
<?php if ($opMayApprove && (string) $l['payment_status'] !== 'paid'): ?>
        <form method="post" style="display:flex;gap:4px;margin-top:4px"><?= $csrf ?><input type="hidden" name="op" value="<?= (string) $l['payment_status'] === 'on_hold' ? 'release' : 'hold' ?>"><input type="hidden" name="line" value="<?= e((string) $l['id']) ?>"><input type="hidden" name="run" value="<?= e((string) $sel['id']) ?>">
          <input name="reason" placeholder="<?= (string) $l['payment_status'] === 'on_hold' ? 'why released' : 'why held' ?>" style="width:7.5rem;font-size:11px" <?= (string) $l['payment_status'] === 'on_hold' ? '' : 'required' ?>><button class="btn btn-outline btn-sm" type="submit" style="font-size:11px"><?= (string) $l['payment_status'] === 'on_hold' ? 'Release' : 'Hold' ?></button></form>
<?php endif; ?></td>
      <td><details><summary class="note" style="cursor:pointer">Explain</summary><ol style="margin:6px 0 0 16px;padding:0;font-size:11.5px;min-width:320px"><?php foreach ($why as $w): ?><li><?= e((string) $w) ?></li><?php endforeach; ?></ol></details></td>
    </tr>
<?php endforeach; ?>
    </tbody></table></div>
</div>
<?php endif; ?>
