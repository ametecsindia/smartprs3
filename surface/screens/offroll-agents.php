<?php declare(strict_types=1); if (!$may('employee.view')): ?>
<div class="page-header"><div><h2>Off-roll Agents</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// FIELD FORCE → OFF-ROLL AGENTS (rebuilt 23 Oct 2026). The register's front page: compliance at a glance
// (DRA, PCC, bank authorisation, KYC), who is deployed where, under which supervisor and vendor, on which pay
// structure — and each agent's profile one click away. Add / Edit / Bulk upload are the offroll-agents
// register (the module engine: the same form, checks and sample sheet as every other register).
use SmartPRS\Surface\Dates;
use SmartPRS\Surface\OffrollPay;

include __DIR__ . '/../partials/offroll-boot.php';

if (!$opReady) {
    echo '<div class="page-header"><div><h2>Off-roll Agents</h2></div></div>' . $opNotReady;
    return;
}

$today = date('Y-m-d');
$fCo = (string) ($_GET['f_company'] ?? '');
$fBr = (string) ($_GET['f_branch'] ?? '');
$fVe = (string) ($_GET['vendor'] ?? '');
$fSt = (string) ($_GET['st'] ?? '');
$fCm = (string) ($_GET['cm'] ?? '');
$fQ = trim((string) ($_GET['q'] ?? ''));
$plans = $op->plansByAgent();
$auths = [];

foreach (OffrollPay::rows($store, 'agent_authorizations') as $au) {
    $aid = trim((string) ($au['agent_id'] ?? ''));
    $aid === '' || \SmartPRS\Surface\Modules::isRetired($au) || $auths[$aid][] = $au;
}

$slots = array_keys((array) $opD['documents']);
$rows = [];
$t = ['active' => 0, 'all' => 0, 'dra_due' => 0, 'dra_bad' => 0, 'pcc_due' => 0, 'pcc_bad' => 0, 'auth_due' => 0, 'auth_bad' => 0, 'kyc' => 0];

foreach ($opAgents as $id => $a) {
    $status = strtolower((string) ($a['status'] ?? ''));
    $dra = $opBand((string) ($a['dra_expiry'] ?? ''));
    $pcc = $opBand((string) ($a['pcc_expiry'] ?? ''));
    // THE BANK LETTER THAT MATTERS: the one valid furthest into the future.
    $best = null;

    foreach ($auths[$id] ?? [] as $au) {
        if ($best === null || (string) ($au['valid_to'] ?? '') > (string) ($best['valid_to'] ?? '')) {
            $best = $au;
        }
    }

    $auth = $best === null ? 'none' : $opBand((string) ($best['valid_to'] ?? ''));
    $kyc = count(array_filter($slots, static fn (string $s): bool => trim((string) ($a[$s] ?? '')) !== ''));
    $working = !in_array($status, ['terminated'], true) && (trim((string) ($a['exit_date'] ?? '')) === '' || (string) $a['exit_date'] >= $today);
    $r = ['a' => $a, 'dra' => $dra, 'pcc' => $pcc, 'auth' => $auth, 'auth_row' => $best, 'kyc' => $kyc, 'working' => $working,
        'plan' => OffrollPay::planOn($plans[$id] ?? [], $a, $today)];

    if ($working) {
        $t['all']++;
        $status === 'active' && $t['active']++;
        $dra === 'due' && $t['dra_due']++;
        in_array($dra, ['expired', 'none'], true) && $t['dra_bad']++;
        $pcc === 'due' && $t['pcc_due']++;
        in_array($pcc, ['expired', 'none'], true) && $t['pcc_bad']++;
        $auth === 'due' && $t['auth_due']++;
        in_array($auth, ['expired', 'none'], true) && $t['auth_bad']++;
        $kyc < count($slots) && $t['kyc']++;
    }

    if ($fCo !== '' && (string) ($a['employer_company_id'] ?? '') !== $fCo || $fBr !== '' && (string) ($a['branch_id'] ?? '') !== $fBr
        || $fVe !== '' && (string) ($a['vendor_id'] ?? '') !== $fVe || $fSt !== '' && strcasecmp($status, $fSt) !== 0) {
        continue;
    }

    if ($fCm === 'attention' && !array_intersect([$dra, $pcc, $auth], ['due', 'expired', 'none'])
        || $fCm === 'expired' && !array_intersect([$dra, $pcc], ['expired', 'none']) || $fCm === 'kyc' && $kyc >= count($slots)) {
        continue;
    }

    if ($fQ !== '' && stripos($op->code($a) . ' ' . (string) ($a['name'] ?? '') . ' ' . (string) ($a['mobile'] ?? '') . ' ' . (string) ($a['pan'] ?? ''), $fQ) === false) {
        continue;
    }

    $rows[] = $r;
}

$pending = \SmartPRS\Platform\Support\Money::zero();

foreach ($op->runs() as $run) {
    if ((string) $run['status'] !== 'approved') {
        continue;
    }

    foreach ($op->lines((string) $run['id']) as $l) {
        isset($opAgents[(string) $l['agent_id']]) && (string) $l['payment_status'] !== 'paid' && $pending = $pending->plus(\SmartPRS\Surface\Amount::of((string) $l['net']));
    }
}

$statuses = (array) ($modules->find('offroll-agents')['fields']['status']['options'] ?? ['Onboarded', 'Active', 'Suspended', 'Terminated']);
$dmy = static fn (string $d): string => $d === '' ? '' : Dates::dmy(substr($d, 0, 10));
$tile = static fn (string $label, int|string $n, string $sub, string $cls, string $href): string
    => '<a class="kpi ' . $cls . '" href="' . e($href) . '" style="text-decoration:none"><h3>' . e((string) $n) . '</h3><p>' . e($label) . '</p><div class="sub">' . e($sub) . '</div></a>';
?>
<div class="page-header">
  <div><h2>Off-roll Agents</h2><p><?= $t['all'] ?> deployed agent(s) &middot; vendor / contract agents outside payroll &middot; certificates checked against today (<?= e(Dates::dmy($today)) ?>), expiring = within <?= $opWindow ?> days</p></div>
  <div class="actions">
<?php if ($opMayEdit): ?>
    <a class="btn btn-primary btn-sm" href="?r=m&amp;k=offroll-agents&amp;add=1#add-row"><i class="fas fa-plus"></i> Add Agent</a>
    <a class="btn btn-outline btn-sm" href="?r=m&amp;k=offroll-agents"><i class="fas fa-file-arrow-up"></i> Bulk upload / edit register</a>
<?php endif; ?>
    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="offroll-export"><input type="hidden" name="what" value="agents">
      <button class="btn btn-outline btn-sm" type="submit"><i class="fas fa-file-excel"></i> Export</button></form>
  </div>
</div>

<div class="kpi-strip">
  <?= $tile('active', $t['active'], 'of ' . $t['all'] . ' deployed', 'blue', '?r=offroll-agents&st=active') ?>
  <?= $tile('DRA expiring', $t['dra_due'], 'within ' . $opWindow . ' days', 'orange', '?r=offroll-agents&cm=attention') ?>
  <?= $tile('DRA expired / not on file', $t['dra_bad'], 'cannot be sent to the field', 'red', '?r=offroll-agents&cm=expired') ?>
  <?= $tile('PCC expiring', $t['pcc_due'], 'within ' . $opWindow . ' days', 'orange', '?r=offroll-agents&cm=attention') ?>
  <?= $tile('PCC expired / not on file', $t['pcc_bad'], 'police clearance', 'red', '?r=offroll-agents&cm=expired') ?>
  <?= $tile('Bank letter expiring / missing', $t['auth_due'] + $t['auth_bad'], $t['auth_due'] . ' expiring · ' . $t['auth_bad'] . ' expired or none', 'orange', '?r=agent-auth') ?>
  <?= $tile('KYC incomplete', $t['kyc'], 'documents missing', 'orange', '?r=offroll-agents&cm=kyc') ?>
  <?= $tile('pending payment', $opMoney($pending->toDecimalString()), 'approved, not yet paid', 'green', '?r=offroll-payouts&view=pending') ?>
</div>

<div class="card">
  <form method="get" class="row" style="align-items:flex-end;margin:0;flex-wrap:wrap;gap:10px"><input type="hidden" name="r" value="offroll-agents">
    <div><label>Company</label><select name="f_company"><option value="">ALL</option><?php foreach ($opCompanies as $k => $v): ?><option value="<?= e($k) ?>" <?= $fCo === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div><label>Branch</label><select name="f_branch"><option value="">ALL</option><?php foreach ($opBranches as $k => $v): ?><option value="<?= e($k) ?>" <?= $fBr === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div><label>Vendor</label><select name="vendor"><option value="">ALL</option><?php foreach ($opVendors as $k => $v): ?><option value="<?= e((string) $k) ?>" <?= $fVe === (string) $k ? 'selected' : '' ?>><?= e((string) $v['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>Status</label><select name="st"><option value="">ALL</option><?php foreach ($statuses as $s): ?><option value="<?= e(strtolower((string) $s)) ?>" <?= strcasecmp($fSt, (string) $s) === 0 ? 'selected' : '' ?>><?= e((string) $s) ?></option><?php endforeach; ?></select></div>
    <div><label>Compliance</label><select name="cm"><option value="">ALL</option><option value="attention" <?= $fCm === 'attention' ? 'selected' : '' ?>>Needs attention (expiring / expired / missing)</option><option value="expired" <?= $fCm === 'expired' ? 'selected' : '' ?>>DRA or PCC expired / not on file</option><option value="kyc" <?= $fCm === 'kyc' ? 'selected' : '' ?>>KYC incomplete</option></select></div>
    <div style="flex:1;min-width:200px"><label>Search</label><input name="q" value="<?= e($fQ) ?>" placeholder="code, name, mobile or PAN"></div>
    <div><button class="go" type="submit">Show</button></div>
  </form>
</div>

<div class="card" style="padding:0">
  <div class="table-wrap"><table>
    <thead><tr><th>Agent</th><th>Company</th><th>Branch</th><th>Vendor &middot; paid to</th><th>Supervisor</th><th>Pay structure today</th><th>DRA valid up to</th><th>PCC valid up to</th><th>Bank letter</th><th class="n">KYC</th><th>Status</th><th></th></tr></thead>
    <tbody>
<?php if ($rows === []): ?><tr><td colspan="12" class="note">No off-roll agent<?= ($fCo . $fBr . $fVe . $fSt . $fCm . $fQ) !== '' ? ' matches the filter' : ' yet — use Add Agent' ?>.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): $a = $r['a']; $id = (string) $a['id']; ?>
    <tr style="<?= $r['working'] ? '' : 'opacity:.55' ?>">
      <td style="white-space:nowrap"><a href="?r=offroll-agent&amp;id=<?= e($id) ?>"><b><?= e($op->code($a)) ?></b> &middot; <?= e((string) ($a['name'] ?? '')) ?></a><div class="note"><?= e((string) ($a['mobile'] ?? '')) ?></div></td>
      <td><?= e($opCompanies[(string) ($a['employer_company_id'] ?? '')] ?? '—') ?></td>
      <td><?= e($opBranches[(string) ($a['branch_id'] ?? '')] ?? '—') ?></td>
      <td><?= e($opVendorName($a) ?: '—') ?><div class="note"><?= OffrollPay::paidTo($a) === 'vendor' ? 'paid to the vendor' : 'paid to the agent' ?></div></td>
      <td><?= e($opEmpLabel[(string) ($a['supervisor_employee_id'] ?? '')] ?? '—') ?></td>
      <td style="max-width:240px"><?= e($op->planSummary($r['plan'])) ?></td>
      <td style="white-space:nowrap"><?= e($dmy((string) ($a['dra_expiry'] ?? '')) ?: '—') ?><br><span class="badge <?= $opBadge[$r['dra']] ?>"><?= e($opBandLabel[$r['dra']]) ?></span></td>
      <td style="white-space:nowrap"><?= e($dmy((string) ($a['pcc_expiry'] ?? '')) ?: '—') ?><br><span class="badge <?= $opBadge[$r['pcc']] ?>"><?= e($opBandLabel[$r['pcc']]) ?></span></td>
      <td style="white-space:nowrap"><?= $r['auth_row'] === null ? '<span class="badge badge-gray">None</span>' : e((string) ($r['auth_row']['bank'] ?? '')) . ' · ' . e($dmy((string) ($r['auth_row']['valid_to'] ?? ''))) . '<br><span class="badge ' . $opBadge[$r['auth']] . '">' . e($opBandLabel[$r['auth']]) . '</span>' ?></td>
      <td class="n"><span class="badge <?= $r['kyc'] === count($slots) ? 'badge-green' : ($r['kyc'] > 0 ? 'badge-amber' : 'badge-red') ?>"><?= $r['kyc'] ?>/<?= count($slots) ?></span></td>
      <td><span class="badge <?= strtolower((string) ($a['status'] ?? '')) === 'active' ? 'badge-green' : (in_array(strtolower((string) ($a['status'] ?? '')), ['suspended', 'terminated'], true) ? 'badge-red' : 'badge-gray') ?>"><?= e((string) ($a['status'] ?? '')) ?></span></td>
      <td style="white-space:nowrap;text-align:right"><a class="btn btn-outline btn-sm" href="?r=offroll-agent&amp;id=<?= e($id) ?>">Profile</a><?php if ($opMayEdit): ?> <a class="btn btn-outline btn-sm" href="?r=m&amp;k=offroll-agents&amp;edit=<?= e($id) ?>" title="Edit on the register"><i class="fas fa-pen"></i></a><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
    </tbody></table></div>
</div>
<p class="note">An agent whose DRA or PCC is expired or not on file on a day they worked is <?= e(['allow' => 'paid normally', 'flag' => 'paid, with the payout line flagged', 'hold' => 'held on the payout until the hold is released'][$opSet['expired_certificate']] ?? '') ?> (Organization Configuration &rarr; Off-roll Agent Payouts). Bank letters are recorded under <a href="?r=agent-auth">Agent Authorisations</a>.</p>
