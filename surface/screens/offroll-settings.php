<?php declare(strict_types=1); if (!$may('payroll.view')): ?>
<div class="page-header"><div><h2>Off-roll Agent Payouts</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'payroll.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// ORGANIZATION CONFIGURATION → OFF-ROLL AGENT PAYOUTS (owner, 23 Oct 2026: "give customized option to select
// under Organization Configuration"). How a fixed payout is pro-rated, which days a payout counts, what happens
// to an agent who worked without a valid DRA / PCC, and TDS on / off with its default section. Every save is a
// new Customisation version; a payout already approved keeps the settings it was generated with.
include __DIR__ . '/../partials/offroll-boot.php';

$can = $may('tenant.settings.update');
$opt = (array) $opD['settings_options'];
$hist = $customisation->history($op->key());
$tds = (array) $opD['tds'];
$radio = static function (string $k) use ($opt, $opSet, $can): string {
    $h = '';
    foreach ((array) $opt[$k] as $v => $label) {
        $h .= '<label style="display:flex;gap:8px;align-items:flex-start;margin:6px 0;font-weight:normal"><input type="radio" name="' . e($k) . '" value="' . e((string) $v) . '"'
            . ($opSet[$k] === (string) $v ? ' checked' : '') . ($can ? '' : ' disabled') . ' style="margin-top:3px"> <span>' . e((string) $label) . '</span></label>';
    }

    return $h;
};
?>
<div class="page-header">
  <div><h2>Off-roll Agent Payouts</h2><p>Organization Configuration &middot; the rules every off-roll agent payout follows &middot; version <?= e((string) ($hist[0]['version'] ?? '0 (shipped defaults)')) ?></p></div>
  <div class="actions"><a class="btn btn-outline btn-sm" href="?r=offroll-payouts">Agent Payouts</a></div>
</div>

<form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="offroll-settings-save">
<div class="row" style="align-items:stretch;gap:14px;flex-wrap:wrap">
  <div class="card" style="flex:1;min-width:340px;margin:0">
    <h3 style="margin-top:0">1. Fixed payout when the agent works part of the month</h3>
    <?= $radio('fixed_basis') ?>
    <div style="margin:6px 0 0 26px"><label>Fixed number of days</label><input name="fixed_days" inputmode="numeric" value="<?= e($opSet['fixed_days']) ?>" style="width:80px" <?= $can ? '' : 'disabled' ?>></div>
    <p class="note">A half day counts as half. The pro-rated amount never exceeds the full fixed amount. Per-day, per-visit, PTP, paid-account and collection pay always follow the days actually worked.</p>
  </div>
  <div class="card" style="flex:1;min-width:340px;margin:0">
    <h3 style="margin-top:0">2. Which days a payout counts</h3>
    <?= $radio('count_basis') ?>
    <h3>3. Agent worked without a valid DRA or PCC</h3>
    <?= $radio('expired_certificate') ?>
    <p class="note">Checked day by day against the certificate's "valid up to" date. A certificate not on file counts as not valid. A held line is released by hand on Agent Payouts, with a reason.</p>
  </div>
  <div class="card" style="flex:1;min-width:340px;margin:0">
    <h3 style="margin-top:0">4. TDS on payouts</h3>
    <?= $radio('tds_enabled') ?>
    <div style="margin-top:8px"><label>Default section (an agent or vendor may name its own)</label>
      <select name="tds_section" <?= $can ? '' : 'disabled' ?>><?php foreach ((array) $tds['sections'] as $k => $s): ?><option value="<?= e((string) $k) ?>" <?= $opSet['tds_section'] === (string) $k ? 'selected' : '' ?>><?= e((string) $s['label']) ?></option><?php endforeach; ?></select></div>
    <ul style="margin:12px 0 0 18px;padding:0;font-size:12.5px;line-height:1.6">
<?php foreach ((array) $tds['sections'] as $k => $s): if ($k === 'none') { continue; } ?>
      <li><b><?= e((string) $s['label']) ?></b>: <?= $s['rates']['individual'] === $s['rates']['other'] ? e((string) $s['rates']['other']) . '%' : e((string) $s['rates']['individual']) . '% individual / HUF, ' . e((string) $s['rates']['other']) . '% others' ?>
        &middot; deducted above <?= e(implode(' or ', array_filter([$s['single_threshold'] ? $opMoney((string) $s['single_threshold']) . ' in one payment' : '', $s['year_threshold'] ? $opMoney((string) $s['year_threshold']) . ' in the financial year' : '']))) ?></li>
<?php endforeach; ?>
      <li><b>No PAN on file</b>: <?= e((string) $tds['no_pan_rate']) ?>% (or the section rate, if higher)</li>
    </ul>
    <p class="note"><?= e((string) $tds['source']) ?>. Government rates are not edited here; they change in <code>config/offroll-agents.json</code> when the law does.</p>
  </div>
</div>
<?php if ($can): ?><p><button class="btn btn-primary" type="submit"><i class="fas fa-check"></i> Save settings</button></p><?php endif; ?>
</form>

<?php if ($hist !== []): ?>
<div class="card" style="padding:0"><div class="card-header"><h3 style="margin:0">History</h3></div>
  <div class="table-wrap"><table><thead><tr><th>Version</th><th>Saved</th><th>By</th></tr></thead><tbody>
<?php foreach (array_slice($hist, 0, 10) as $h): ?><tr><td><?= e((string) $h['version']) ?></td><td><?= e(\SmartPRS\Surface\Dates::dmy(substr((string) ($h['created_at'] ?? ''), 0, 10)) . ' ' . substr((string) ($h['created_at'] ?? ''), 11, 5)) ?></td><td><?= e((string) ($h['changed_by'] ?? '')) ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
<?php endif; ?>
