<?php declare(strict_types=1); if (!$may('payroll.view')): ?>
<div class="page-header"><div><h2>Certificate-linked Deductions</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'payroll.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// CERTIFICATE-LINKED DEDUCTIONS (owner, 13 Oct). The rules (which Salary
// Structure deduction, which status field, which values mean "submitted",
// how the refund is paid, what happens on exit), the link check against the
// Salary Structure rules in force, and the per-employee ledger of what is held.
// Engine: surface/CertificateHolds.php, read by every payroll generation.
use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\CertificateHolds;
use SmartPRS\Surface\Dates;
use SmartPRS\Surface\Employment;

$chCanEdit = $may('tenant.settings.update');
$chDeclared = $certificateHolds->declared();
$chRules = $certificateHolds->rules();
$chCatalogue = $componentRules->catalogue();
$chDeductions = $chEarnings = [];

foreach ((array) ($chCatalogue['components'] ?? []) as $c) {
    $c = (array) $c;
    ((string) ($c['kind'] ?? '')) === 'deduction' && $chDeductions[(string) $c['code']] = (string) $c['label'];
    ((string) ($c['kind'] ?? '')) === 'earning' && $chEarnings[(string) $c['code']] = (string) $c['label'];
}

$chStatusFields = [];

foreach ((array) ($chCatalogue['status_fields'] ?? []) as $f) {
    $chStatusFields[(string) ($f['column'] ?? $f['code'])] = (string) ($f['label'] ?? $f['code']);
}

// The values each status field can hold, from the employee field's own dropdown.
$chFieldOptions = [];
$chWalk = static function (mixed $o) use (&$chWalk, &$chFieldOptions): void {
    if (!is_array($o)) {
        return;
    }

    if (isset($o['column'], $o['options']) && is_array($o['options'])) {
        $chFieldOptions[(string) $o['column']] = array_map('strval', $o['options']);
    }

    foreach ($o as $v) {
        $chWalk($v);
    }
};
$chWalk(json_decode((string) file_get_contents(__DIR__ . '/../../config/employee-fields.json'), true));
$chOnExit = (array) ($chDeclared['on_exit_options'] ?? []);
// The "submitted" choices: every value the certificate status fields offer.
$chClearOpts = array_values(array_unique(array_merge([], ...array_values(array_intersect_key($chFieldOptions, $chStatusFields)))));

// THE NEXT PAYROLL: the month after the latest closed one, else this month.
$chLast = '';

foreach ($register->runs() as $chRun) {
    $chLast = max($chLast, (string) ($chRun['period'] ?? ''));
}

$chNext = $chLast === '' ? date('Y-m') : date('Y-m', (int) strtotime($chLast . '-01 +1 month'));
$chNextEnd = date('Y-m-t', (int) strtotime($chNext . '-01'));

// LINK CHECK against the Salary Structure rules in force today.
$chInForce = $componentRules->inForce(date('Y-m-d'));
$chLinks = [];

foreach ($chRules as $i => $r) {
    $msgs = [];
    $found = array_values(array_filter($chInForce, static fn (array $x): bool => (string) ($x['component_code'] ?? '') === (string) ($r['deduction_code'] ?? '')));
    $cleared = array_map('strtolower', array_map('strval', (array) ($r['cleared_values'] ?? [])));

    if ($found === []) {
        $msgs[] = ['bad', 'No Salary Structure rule deducts ' . ($chDeductions[(string) ($r['deduction_code'] ?? '')] ?? $r['deduction_code'] ?? '') . ' — nothing is held. Add one in Salary Structure with calculation "Employee Status".'];
    }

    foreach ($found as $x) {
        $d = is_array($x['detail'] ?? null) ? $x['detail'] : (array) json_decode((string) ($x['detail'] ?? ''), true);
        $where = trim(implode(' · ', array_filter([(string) ($x['scope_company'] ?? ''), (string) ($x['scope_branch'] ?? ''), (string) ($x['scope_designation'] ?? ''), (string) ($x['scope_employee'] ?? '')]))) ?: 'everyone';
        $cond = trim((string) ($d['status_condition'] ?? ''));

        if ((string) ($x['calculation'] ?? '') !== 'employee_status') {
            $msgs[] = ['bad', "Rule for {$where} is not \"Employee Status\" — it deducts every month whatever the certificate says, and keeps deducting after it is submitted."];
        } elseif ((string) ($d['status_field'] ?? '') !== (string) ($r['status_field'] ?? '')) {
            $msgs[] = ['bad', "Rule for {$where} reads " . ($chStatusFields[(string) ($d['status_field'] ?? '')] ?? ($d['status_field'] ?? '—')) . ', not ' . ($chStatusFields[(string) ($r['status_field'] ?? '')] ?? '—') . '.'];
        } elseif ($cond === '') {
            $msgs[] = ['bad', "Rule for {$where} has no status value, so it deducts whenever the field holds ANY value — including Submitted. Set it to the pending value (e.g. Pending)."];
        } elseif (in_array(strtolower($cond), $cleared, true)) {
            $msgs[] = ['bad', "Rule for {$where} deducts when the status is \"{$cond}\", which this rule counts as submitted — it would deduct and refund at once."];
        } else {
            $msgs[] = ['ok', "Rule for {$where}: deducts ₹" . (string) ($d['status_amount'] ?? '') . " a month while the status is \"{$cond}\"."];
        }
    }

    $chLinks[$i] = $msgs;
}

// THE LEDGER — what is held for each employee in view, and what the next payroll does with it.
$chPeople = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
$chFilter = orgFilter();
$chLedger = $certificateHolds->ledger($chNext);
$chRows = [];

foreach (orgFilterApply($store, $chPeople, $chFilter) as $emp) {
    foreach ($chRules as $r) {
        if (!(bool) ($r['active'] ?? false)) {
            continue;
        }

        $t = $chLedger[Employment::code($emp)][(string) $r['key']] ?? null;

        if ($t === null || $t['held'] === 0) {
            continue;
        }

        $bal = $t['held'] - $t['refunded'];
        $chRows[] = ['emp' => $emp, 'rule' => $r, 't' => $t, 'balance' => $bal,
            'next' => CertificateHolds::outcome($r, $emp, $bal, $chNextEnd)];
    }
}

$chNextLabel = ['refund' => ['green', 'Refund in ' . date('M Y', (int) strtotime($chNext . '-01'))], 'hold' => ['amber', 'Keep holding'],
    'forfeit' => ['red', 'Forfeit (left)'], 'none' => ['gray', 'Settled']];
$chMoney = static fn (int $m): string => Money::ofMinor($m)->format();
$chSel = static function (string $name, array $opts, string $cur, bool $blank = false): string {
    $h = '<select name="' . e($name) . '">' . ($blank ? '<option value="">— pick —</option>' : '');

    foreach ($opts as $v => $l) {
        $h .= '<option value="' . e((string) $v) . '"' . ((string) $v === $cur ? ' selected' : '') . '>' . e((string) $l) . '</option>';
    }

    return $h . '</select>';
};
?>
<div class="page-header">
  <div><h2>Certificate-linked Deductions</h2>
    <p>DRA, PCC or any other deduction held every month until the employee submits the certificate — then everything held so far is paid back in one line on the next payroll. The deduction itself is the Salary Structure rule (calculation <b>Employee Status</b>); this screen decides when it is refunded.</p></div>
  <div class="actions"><a class="btn btn-outline" href="?r=structure">Salary Structure</a></div>
</div>

<div class="card">
  <h2 style="margin-top:0">Rules <span class="note"><?= $certificateHolds->isCustomised() ? 'this organisation\'s own rules' : 'shipped rules, all off — tick Active and save to start' ?></span></h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="certificate-holds-save">
    <div class="table-wrap"><table>
      <thead><tr><th>Active</th><th>Certificate</th><th>Deduction (Salary Structure)</th><th>Status field</th><th>Values that mean "submitted"</th><th>Refund paid as</th><th>If the employee leaves without it</th></tr></thead>
      <tbody>
<?php foreach (array_merge($chRules, [['key' => '', 'label' => '', 'active' => false, 'cleared_values' => []]]) as $i => $r): $new = (string) ($r['key'] ?? '') === ''; $sf = (string) ($r['status_field'] ?? ''); ?>
        <tr<?= $new ? ' class="note"' : '' ?>>
          <td><input type="hidden" name="rules[<?= $i ?>][key]" value="<?= e((string) ($r['key'] ?? '')) ?>"><input type="checkbox" name="rules[<?= $i ?>][active]" value="1"<?= ($r['active'] ?? false) ? ' checked' : '' ?>></td>
          <td><input name="rules[<?= $i ?>][label]" value="<?= e((string) ($r['label'] ?? '')) ?>" placeholder="<?= $new ? 'Add another, e.g. Police verification' : '' ?>" style="min-width:160px"></td>
          <td><?= $chSel("rules[{$i}][deduction_code]", $chDeductions, (string) ($r['deduction_code'] ?? ''), true) ?></td>
          <td><?= $chSel("rules[{$i}][status_field]", $chStatusFields, $sf, true) ?></td>
          <td style="white-space:nowrap"><?php foreach ($chClearOpts as $opt): ?><label style="display:inline-flex;gap:4px;margin:0 8px 0 0;font-weight:400"><input type="checkbox" name="rules[<?= $i ?>][cleared_values][]" value="<?= e($opt) ?>"<?= in_array($opt, (array) ($r['cleared_values'] ?? []), true) ? ' checked' : '' ?>> <?= e($opt) ?></label><?php endforeach; ?></td>
          <td><?= $chSel("rules[{$i}][refund_code]", $chEarnings, (string) ($r['refund_code'] ?? ''), true) ?></td>
          <td><?= $chSel("rules[{$i}][on_exit]", $chOnExit, (string) ($r['on_exit'] ?? ''), true) ?></td>
        </tr>
<?php if (!$new && ($chLinks[$i] ?? []) !== []): ?>
        <tr><td></td><td colspan="6" class="note" style="padding-top:0"><?php foreach ($chLinks[$i] as [$ok, $m]): ?><div><span class="badge badge-<?= $ok === 'ok' ? 'green' : 'red' ?>"><?= $ok === 'ok' ? 'linked' : 'check' ?></span> <?= e($m) ?></div><?php endforeach; ?></td></tr>
<?php endif; ?>
<?php endforeach; ?>
      </tbody>
    </table></div>
<?php if ($chCanEdit): ?>
    <p style="margin:10px 0 0"><button class="btn btn-primary" type="submit"><i class="fas fa-floppy-disk"></i> Save rules</button>
      <span class="note">Fill the last row to add another certificate. Untick Active to stop refunds for that certificate (nothing already paid changes). Every save is kept as a version.</span></p>
<?php endif; ?>
  </form>
  <details class="why"><summary>How it works with payroll</summary><ul class="note">
    <li>Every month the Salary Structure rule deducts the amount while the employee's status is still pending (e.g. DRA Status = Pending).</li>
    <li>When HR sets the status to a "submitted" value on the employee record, the deduction stops and the <b>next payroll generated</b> adds one earning line — the refund — equal to everything held in earlier payrolls minus anything already refunded. The payslip shows it with the months it covers.</li>
    <li>Nothing is marked: held and refunded are read from the closed payrolls, so regenerating a month gives the same figure and a refund is never paid twice. A reversed or deleted payroll counts nothing.</li>
    <li>If the employee leaves before submitting: <b>Refund</b> pays the balance in their last payroll; <b>Forfeit</b> keeps it and the payslip says so.</li>
  </ul></details>
</div>

<div class="card"><?= orgFilterBar('certificate-holds', $chFilter, orgFilterOptions($store, $chPeople)) ?></div>
<div class="card">
  <h2 style="margin-top:0">Held so far <span class="note">closed payrolls up to <?= e($chLast === '' ? '—' : date('M Y', (int) strtotime($chLast . '-01'))) ?> · next payroll <?= e(date('M Y', (int) strtotime($chNext . '-01'))) ?></span></h2>
<?php if ($chRows === []): ?>
  <p class="note">Nothing held for the employees in view<?= array_filter($chRules, static fn (array $r): bool => (bool) ($r['active'] ?? false)) === [] ? ' — no rule is active yet' : '' ?>.</p>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Emp ID</th><th>Name</th><th>Company</th><th>Certificate</th><th>Status now</th><th class="n">Months</th><th class="n">Held</th><th class="n">Refunded</th><th class="n">Balance</th><th>Next payroll</th><th></th></tr></thead>
    <tbody>
<?php foreach ($chRows as $row): [$tone, $txt] = $chNextLabel[$row['next']]; ?>
      <tr>
        <td><?= e(Employment::code($row['emp'])) ?></td>
        <td><?= e((string) ($row['emp']['name'] ?? '')) ?></td>
        <td><?= e(orgValueOf($store, $row['emp'], 'company')) ?></td>
        <td><?= e((string) ($row['rule']['label'] ?? '')) ?></td>
        <td><?= e((string) ($row['emp'][(string) ($row['rule']['status_field'] ?? '')] ?? '') ?: '—') ?></td>
        <td class="n"><?= count(array_unique($row['t']['months'])) ?></td>
        <td class="n"><?= e($chMoney($row['t']['held'])) ?></td>
        <td class="n"><?= e($chMoney($row['t']['refunded'])) ?></td>
        <td class="n"><b><?= e($chMoney($row['balance'])) ?></b></td>
        <td><span class="badge badge-<?= $tone ?>"><?= e($txt) ?></span></td>
        <td><?php if ($may('employee.update')): ?><a class="btn btn-outline btn-sm" href="?r=employees&amp;edit=<?= e((string) $row['emp']['id']) ?>">Update status</a><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>
