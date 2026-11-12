<?php declare(strict_types=1); if (!$may('employee.view')): ?>
<div class="page-header"><div><h2>Agent Pay Structure</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// FIELD FORCE → AGENT PAY STRUCTURE (23 Oct 2026): every off-roll agent's pay structure in force on a date,
// the next revision if one is already set, and the bulk sheet to set or revise many at once. One agent's
// structure is revised on their profile. Components and their meaning: config/offroll-agents.json.
use SmartPRS\Surface\Dates;
use SmartPRS\Surface\OffrollPay;

include __DIR__ . '/../partials/offroll-boot.php';

if (!$opReady) {
    echo '<div class="page-header"><div><h2>Agent Pay Structure</h2></div></div>' . $opNotReady;
    return;
}

$on = \SmartPRS\Surface\Dates::toIso((string) ($_GET['on'] ?? '')) ?? date('Y-m-d');
$fCo = (string) ($_GET['f_company'] ?? '');
$fVe = (string) ($_GET['vendor'] ?? '');
$plans = $op->plansByAgent();
$rows = [];
$none = 0;

foreach ($opAgents as $id => $a) {
    if ($fCo !== '' && (string) ($a['employer_company_id'] ?? '') !== $fCo || $fVe !== '' && (string) ($a['vendor_id'] ?? '') !== $fVe) {
        continue;
    }

    $p = OffrollPay::planOn($plans[$id] ?? [], $a, $on);
    $next = null;

    foreach (array_reverse($plans[$id] ?? []) as $x) {
        if ((string) $x['effective_from'] > $on) {
            $next = $x;
            break;
        }
    }

    $p === null && $none++;
    $rows[] = ['a' => $a, 'p' => $p, 'next' => $next, 'versions' => count($plans[$id] ?? [])];
}

$comp = (array) $opD['plan_components'];
?>
<div class="page-header">
  <div><h2>Agent Pay Structure</h2><p>What each off-roll agent is paid on, in force on <?= e(Dates::dmy($on)) ?> &middot; <?= count($rows) ?> agent(s)<?= $none > 0 ? ' &middot; <b style="color:#b91c1c">' . $none . ' with no pay structure</b>' : '' ?></p></div>
<?php if ($opMayEdit): ?>
  <div class="actions">
    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="offroll-plan"><input type="hidden" name="op" value="sample">
      <button class="btn btn-outline btn-sm" type="submit"><i class="fas fa-file-excel"></i> Bulk sample (agents pre-listed)</button></form>
    <form method="post" enctype="multipart/form-data" style="display:inline-flex;gap:6px;align-items:center"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="offroll-plan"><input type="hidden" name="op" value="import">
      <input type="file" name="sheet" accept=".xlsx,.xls,.csv" required style="width:210px;font-size:12px"><button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-file-arrow-up"></i> Upload</button></form>
  </div>
<?php endif; ?>
</div>

<div class="card">
  <form method="get" class="row" style="align-items:flex-end;margin:0;gap:10px;flex-wrap:wrap"><input type="hidden" name="r" value="offroll-pay-plans">
    <div><label>In force on</label><input type="date" name="on" value="<?= e($on) ?>"></div>
    <div><label>Company</label><select name="f_company"><option value="">ALL</option><?php foreach ($opCompanies as $k => $v): ?><option value="<?= e($k) ?>" <?= $fCo === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
    <div><label>Vendor</label><select name="vendor"><option value="">ALL</option><?php foreach ($opVendors as $k => $v): ?><option value="<?= e((string) $k) ?>" <?= $fVe === (string) $k ? 'selected' : '' ?>><?= e((string) $v['name']) ?></option><?php endforeach; ?></select></div>
    <div><button class="go" type="submit">Show</button></div>
  </form>
</div>

<div class="card" style="padding:0">
  <div class="table-wrap"><table>
    <thead><tr><th>Agent</th><th>Company</th><th>From</th>
<?php foreach ($comp as $c): ?><th class="n"><?= e((string) $c['label']) ?></th><?php endforeach; ?>
      <th>Collection slabs</th><th class="n">Monthly cap</th><th>Next revision</th><th class="n">Versions</th><th></th></tr></thead>
    <tbody>
<?php if ($rows === []): ?><tr><td colspan="<?= 8 + count($comp) ?>" class="note">No agents.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): $a = $r['a']; $p = $r['p']; $slabs = OffrollPay::parseSlabs((string) ($p['collection_slabs'] ?? '')) ?? []; ?>
    <tr>
      <td style="white-space:nowrap"><a href="?r=offroll-agent&amp;id=<?= e((string) $a['id']) ?>"><?= e($op->label($a)) ?></a></td>
      <td><?= e($opCompanies[(string) ($a['employer_company_id'] ?? '')] ?? '—') ?></td>
      <td style="white-space:nowrap"><?= $p === null ? '<span class="badge badge-red">None</span>' : e((string) ($p['effective_from'] ?? '') === '' ? 'register rate' : Dates::dmy((string) $p['effective_from'])) ?></td>
<?php foreach ($comp as $k => $c): $v = trim((string) ($p[$k] ?? '')); ?>
      <td class="n"><?= $v === '' || \SmartPRS\Surface\Amount::of($v)->isZero() ? '<span class="note">—</span>' : ($k === 'collection_percent' ? e(rtrim(rtrim(\SmartPRS\Surface\Amount::of($v)->toDecimalString(), '0'), '.')) . '%' : e($opMoney($v))) ?></td>
<?php endforeach; ?>
      <td><?= $slabs === [] ? '<span class="note">—</span>' : e(implode(' / ', array_map(static fn (array $s): string => $s['percent'] . '%' . ($s['upto'] === '' ? ' above' : ' to ' . $opMoney($s['upto'])), $slabs))) ?></td>
      <td class="n"><?= trim((string) ($p['monthly_cap'] ?? '')) === '' ? '<span class="note">—</span>' : e($opMoney((string) $p['monthly_cap'])) ?></td>
      <td class="note"><?= $r['next'] === null ? '—' : e('from ' . Dates::dmy((string) $r['next']['effective_from']) . ': ' . $op->planSummary($r['next'])) ?></td>
      <td class="n"><?= (int) $r['versions'] ?></td>
      <td><a class="btn btn-outline btn-sm" href="?r=offroll-agent&amp;id=<?= e((string) $a['id']) ?>#pay"><?= $opMayEdit ? 'Revise' : 'History' ?></a></td>
    </tr>
<?php endforeach; ?>
    </tbody></table></div>
</div>
<p class="note">A revision applies from its own date: variable pay (per day, visit, PTP, account paid, collection) uses the structure in force on each day worked; the fixed amount uses the one in force at month end, pro-rated as Organization Configuration &rarr; Off-roll Agent Payouts says (now: <?= e((string) ($opD['settings_options']['fixed_basis'][$opSet['fixed_basis']] ?? '')) ?>).</p>
