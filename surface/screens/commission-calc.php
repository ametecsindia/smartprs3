<?php declare(strict_types=1); if (!$may('payroll.run')): ?>
<div class="page-header"><div><h2>Commission Calculator</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'payroll.run')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Commission / Incentive Calculator: pick a scheme and a month,
// load the employees it applies to, type each one's figure, preview the payout
// with its working, then create the entries. THE SCHEME DECIDES THE MONEY:
// the preview and the commit both recompute from the scheme row, so nothing
// typed on the way can carry a rate.
use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\Amount;
use SmartPRS\Surface\Compensation;
use SmartPRS\Surface\Employment;

$compensation = new Compensation($store, __DIR__ . '/../../config/compensation.json');
$commissionRules = (array) $compensation->config()['commission'];
// ONLY SCHEMES SET UP ON COMMISSION SETUP / INCENTIVE SETUP (owner, 30 Oct 2026); older ones are no longer offered.
$schemes = array_values(array_filter(liveRows($store, 'incentive_schemes'),
    static fn (array $s): bool => strtolower((string) ($s['status'] ?? '')) === 'active' && trim((string) ($s['scheme_kind'] ?? '')) !== ''));
$ccRoles = (array) (orgNames($store)['designation_roles'] ?? []);
$schemeId = trim((string) ($_GET['scheme'] ?? ''));
$scheme = null;

foreach ($schemes as $s) {
    if ((string) $s['id'] === $schemeId) {
        $scheme = $s;
    }
}

$month = trim((string) ($_GET['month'] ?? date('Y-m')));
$month = preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? $month : date('Y-m');
$status = (string) ($_GET['status'] ?? $commissionRules['default_status']);
$status = in_array($status, ['pending', 'approved'], true) ? $status : (string) $commissionRules['default_status'];
$filter = orgFilter();
$people = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
$loaded = $scheme !== null && ($_GET['load'] ?? '') !== '';

// The scheme's own scope (ALL when blank) and the filter bar, both applied.
// Every Applies-to field and the Salary Type (Compensation::eligible).
$inScope = static fn (array $e): bool => Compensation::eligible($scheme, $e, $ccRoles);
$rows = $scheme === null ? [] : array_values(array_filter(orgFilterApply($store, $people, $filter), $inScope));
usort($rows, static fn (array $a, array $b): int => strcmp(Employment::code($a), Employment::code($b)));
$c = (array) ($_GET['c'] ?? []);
$t = (array) ($_GET['t'] ?? []);
$m = (array) ($_GET['m'] ?? []);
$rateType = strtolower(trim((string) ($scheme['rate_type'] ?? 'open')));
$gated = trim((string) ($scheme['threshold'] ?? '')) !== '';
$tdsRate = trim((string) ($scheme['tds_rate'] ?? '')) === '' ? (string) $commissionRules['default_tds_rate'] : (string) $scheme['tds_rate'];
$preview = [];
$problems = [];
$totalNet = Money::zero();

if ($loaded && ($_GET['calc'] ?? '') !== '') {
    foreach ($rows as $e) {
        $id = (string) $e['id'];
        $figure = trim((string) ($c[$id] ?? ''));
        $target = trim((string) ($t[$id] ?? ''));
        $manual = trim((string) ($m[$id] ?? ''));

        if ($figure === '' && $manual === '') {
            continue;
        }

        try {
            $result = Compensation::commission($scheme, $figure === '' ? '0' : $figure, $target, $manual);
            // THE SCHEME'S TDS — the same rule the commit applies (Compensation::schemeTds).
            $tds = Compensation::schemeTds($result['gross'], $scheme, $e, $commissionRules);
        } catch (\InvalidArgumentException $ex) {
            $problems[] = Employment::code($e) . ': ' . $ex->getMessage();

            continue;
        }

        // THE SCHEME'S OWN LIMITS — shown here, and the commit leaves such a person out (owner, 2 Nov 2026).
        $ccEntries ??= $store->all('commissions');
        $ccLimit = Compensation::limitsProblem($scheme, (string) $e['id'], $month, $result['gross'], $ccEntries);
        if ($ccLimit !== null) {
            $problems[] = Employment::code($e) . ': ' . $ccLimit . ' — left out';

            continue;
        }

        $preview[] = ['e' => $e, 'figure' => $figure, 'target' => $target, 'manual' => $manual] + $result
            + ['tds' => $tds['tds'], 'net' => $tds['net'], 'working_tds' => $tds['working']];
        $totalNet = $totalNet->plus($tds['net']);
    }
}

$window = '';

if ($scheme !== null) {
    $from = substr(trim((string) ($scheme['valid_from'] ?? '')), 0, 7);
    $till = substr(trim((string) ($scheme['valid_till'] ?? '')), 0, 7);

    if (($from !== '' && $month < $from) || ($till !== '' && $month > $till)) {
        $window = 'The month ' . $month . ' is outside this scheme\'s window (' . ($from ?: 'open') . ' → ' . ($till ?: 'open') . '). No entries are created for a month outside it — change the month or the scheme\'s Valid From / Till.';
    }
}
?>
<div class="page-header">
  <div><h2>Commission Calculator</h2><p>Pick a scheme and a month, load the people it applies to, type the figures, preview with the working, then create the entries. Gross − TDS (as set on the scheme) = net, and the net is what pays.</p></div>
  <div><a class="btn btn-outline" href="?r=m&k=commission-setup"><i class="fas fa-bullseye"></i> Commission Setup</a>
    <a class="btn btn-outline" href="?r=m&k=incentive-setup"><i class="fas fa-gift"></i> Incentive Setup</a>
    <a class="btn btn-outline" href="?r=m&k=commissions"><i class="fas fa-percent"></i> Entries</a></div>
</div>
<div class="card">
  <form method="get">
    <input type="hidden" name="r" value="commission-calc">
    <input type="hidden" name="load" value="1">
    <div class="row" style="align-items:flex-end">
      <div style="flex:2"><label for="cc-scheme">Scheme</label>
        <select id="cc-scheme" name="scheme" required>
          <option value="">— pick an active scheme —</option>
<?php foreach ($schemes as $s): ?>
          <option value="<?= e((string) $s['id']) ?>"<?= (string) $s['id'] === $schemeId ? ' selected' : '' ?>><?= e((string) $s['name']) ?> — <?= e((string) ($s['rate_type'] ?? 'open')) ?><?= trim((string) ($s['rate_value'] ?? '')) !== '' ? ' ' . e((string) $s['rate_value']) : '' ?><?= trim((string) ($s['portfolio'] ?? '')) !== '' ? ' · ' . e((string) $s['portfolio']) : '' ?></option>
<?php endforeach; ?>
        </select></div>
      <div><label for="cc-month">For month</label><input id="cc-month" name="month" value="<?= e($month) ?>" pattern="\d{4}-\d{2}" placeholder="YYYY-MM" required></div>
      <div><label for="cc-status">Create as</label>
        <select id="cc-status" name="status">
          <option value="pending"<?= $status === 'pending' ? ' selected' : '' ?>>Pending (needs approval)</option>
          <option value="approved"<?= $status === 'approved' ? ' selected' : '' ?>>Approved (counts in payroll)</option>
        </select></div>
    </div>
    <div class="row" style="align-items:flex-end;margin-top:8px">
<?php foreach (ORG_FILTERS as $k => $label): ?>
      <div><label for="cc-f-<?= e($k) ?>"><?= e($label) ?></label><select id="cc-f-<?= e($k) ?>" name="f_<?= e($k) ?>"><option value="">ALL</option>
<?php foreach (orgFilterOptions($store, $people)[$k] ?? [] as $opt): ?>
        <option<?= strcasecmp((string) $opt, $filter[$k]) === 0 ? ' selected' : '' ?>><?= e((string) $opt) ?></option>
<?php endforeach; ?>
      </select></div>
<?php endforeach; ?>
      <div><button class="go" type="submit"><i class="fas fa-user-plus"></i> Load employees</button></div>
    </div>
  </form>
<?php if ($schemes === []): ?>
  <p class="note" style="margin-top:8px">No active scheme. Create one on Commission &amp; Incentive Schemes — how it pays, the TDS rate and who it applies to are read from that row.</p>
<?php endif; ?>
</div>
<?php if ($scheme !== null): ?>
<div class="card" style="margin-top:12px">
  <h2><?= e((string) $scheme['name']) ?></h2>
  <div class="row">
    <div><b>Pays</b><br><?= e($rateType) ?><?= trim((string) ($scheme['rate_value'] ?? '')) !== '' ? ' ' . e((string) $scheme['rate_value']) . ($rateType === 'percent' ? '% of collected' : ' per row') : '' ?>
<?php if ($rateType === 'slab'): ?><br><span class="note"><?php foreach (Compensation::slabsOf($scheme) as $band): ?><?= $band['upto'] === '' ? 'above' : 'up to ' . e(Amount::of($band['upto'])->format()) ?>: <?= e($band['rate']) ?>% &nbsp;<?php endforeach; ?></span><?php endif; ?></div>
    <div><b>Gate</b><br><?= $gated ? 'pays only at ≥ ' . e(capDecimals((string) $scheme['threshold'])) . '% of target' . (trim((string) ($scheme['target_amount'] ?? '')) !== '' ? ' (target ' . e(Amount::of((string) $scheme['target_amount'])->format()) . ')' : '') : 'none' ?><?= trim((string) ($scheme['min_collected'] ?? '')) !== '' ? '<br><span class="note">minimum ' . e(Amount::of((string) $scheme['min_collected'])->format()) . ' collected</span>' : '' ?><?= strtolower((string) ($scheme['pay_on'] ?? '')) === 'excess' ? '<br><span class="note">paid only on the amount above it</span>' : '' ?></div>
    <div><b>Eligible</b><br><?= e(trim((string) ($scheme['eligible_salary_types'] ?? '')) ?: 'All employees') ?></div>
    <div><b>TDS</b><br><?= !SmartPRS\Surface\Compensation::tdsApplicable($scheme) ? 'Not applicable' : (strtolower((string) ($scheme['tds_mode'] ?? '')) === 'flat' ? 'Flat ' . e(SmartPRS\Surface\Amount::of(trim((string) ($scheme['tds_flat'] ?? '')) ?: '0')->format()) : e($tdsRate) . '%') ?> <span class="note"><?= e(ucfirst((string) ($scheme['scheme_kind'] ?? ''))) ?> Setup</span></div>
    <div><b>Applies to</b><br><?= count($rows) ?> employee(s) in view</div>
  </div>
<?php if ($window !== ''): ?><p class="note" style="margin-top:8px"><?= e($window) ?></p><?php endif; ?>
</div>
<?php endif; ?>
<?php if ($loaded && $rows !== []): ?>
<div class="card" style="margin-top:12px">
  <form method="get">
    <input type="hidden" name="r" value="commission-calc">
    <input type="hidden" name="load" value="1"><input type="hidden" name="calc" value="1">
    <input type="hidden" name="scheme" value="<?= e($schemeId) ?>"><input type="hidden" name="month" value="<?= e($month) ?>"><input type="hidden" name="status" value="<?= e($status) ?>">
<?php foreach ($filter as $k => $v): ?><input type="hidden" name="f_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
    <h2>Figures — <?= count($rows) ?> employee(s) <span class="note">leave a row blank to skip it</span></h2>
    <div class="table-wrap"><table>
      <thead><tr><th>Employee</th><?= $rateType === 'open' ? '<th class="n">Amount (₹)</th>' : '<th class="n">Collected (₹)</th>' ?><?= $gated ? '<th class="n">Target (₹)</th>' : '' ?></tr></thead>
      <tbody>
<?php foreach ($rows as $e): $id = (string) $e['id']; ?>
        <tr><td><code><?= e(Employment::code($e)) ?></code> <?= e((string) ($e['name'] ?? '')) ?></td>
<?php if ($rateType === 'open'): ?>
          <td class="n"><input name="m[<?= e($id) ?>]" value="<?= e(trim((string) ($m[$id] ?? ''))) ?>" inputmode="decimal" style="max-width:160px"></td>
<?php else: ?>
          <td class="n"><input name="c[<?= e($id) ?>]" value="<?= e(trim((string) ($c[$id] ?? ''))) ?>" inputmode="decimal" style="max-width:160px"></td>
<?php endif; ?>
<?php if ($gated): ?>
          <td class="n"><input name="t[<?= e($id) ?>]" value="<?= e(trim((string) ($t[$id] ?? '')) !== '' ? trim((string) $t[$id]) : capDecimals(trim((string) ($scheme['target_amount'] ?? '')))) ?>" inputmode="decimal" style="max-width:160px"></td>
<?php endif; ?>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table></div>
    <div style="margin-top:10px"><button class="go" type="submit"><i class="fas fa-calculator"></i> Calculate</button></div>
  </form>
</div>
<?php elseif ($loaded): ?>
<div class="card" style="margin-top:12px"><p class="note">Nobody is in view: the scheme's own scope and the filter above leave no employee.</p></div>
<?php endif; ?>
<?php foreach ($problems as $p): ?>
<div class="card" style="margin-top:12px"><div class="problem" style="margin:0"><?= e($p) ?> — that row was left out of the preview.</div></div>
<?php endforeach; ?>
<?php if ($preview !== []): ?>
<div class="card" style="margin-top:12px">
  <h2>Preview — <?= count($preview) ?> row(s), net total <span style="font-family:var(--mono)"><?= e($totalNet->format()) ?></span></h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Employee</th><th class="n">Figure</th><?= $gated ? '<th class="n">Achieved</th>' : '' ?><th class="n">Gross</th><th class="n">TDS</th><th class="n">Net</th><th>Working</th></tr></thead>
    <tbody>
<?php foreach ($preview as $p): ?>
      <tr>
        <td><code><?= e(Employment::code($p['e'])) ?></code> <?= e((string) ($p['e']['name'] ?? '')) ?></td>
        <td class="n" style="font-family:var(--mono)"><?= e(Amount::of($rateType === 'open' ? $p['manual'] : $p['figure'])->format()) ?></td>
<?php if ($gated): ?><td class="n"><?= $p['achievement'] === null ? '—' : e($p['achievement']) . '%' ?></td><?php endif; ?>
        <td class="n" style="font-family:var(--mono)"><?= e($p['gross']->format()) ?></td>
        <td class="n note" style="font-family:var(--mono)"><?= e($p['tds']->format()) ?></td>
        <td class="n"><b style="font-family:var(--mono)"><?= e($p['net']->format()) ?></b></td>
        <td class="note"><?= e($p["working"]) ?>; <?= e($p["working_tds"]) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
  <form method="post" style="margin-top:10px">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="commission-commit">
    <input type="hidden" name="scheme" value="<?= e($schemeId) ?>"><input type="hidden" name="month" value="<?= e($month) ?>"><input type="hidden" name="status" value="<?= e($status) ?>">
<?php foreach ($preview as $p): $id = (string) $p['e']['id']; ?>
    <input type="hidden" name="c[<?= e($id) ?>]" value="<?= e($p['figure']) ?>"><input type="hidden" name="t[<?= e($id) ?>]" value="<?= e($p['target']) ?>"><input type="hidden" name="m[<?= e($id) ?>]" value="<?= e($p['manual']) ?>">
<?php endforeach; ?>
    <button class="go" type="submit"><i class="fas fa-check"></i> Create <?= count($preview) ?> entries as <?= e($status) ?></button>
    <span class="note">Rows that pay nothing are recorded too, at zero, with the working that says why — a gate not cleared is a fact, not a gap.</span>
  </form>
</div>
<?php endif; ?>
