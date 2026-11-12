<?php declare(strict_types=1); if (!$may('employee.view')): ?>
<div class="page-header"><div><h2>Agent Pay Reports</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// FIELD FORCE → AGENT PAY REPORTS (23 Oct 2026): payout register, pending payments, agent statement (a
// financial year), vendor-wise totals, TDS for the quarterly return, attendance & activity, certificate
// compliance — each on screen and as a formatted Excel download of exactly the rows shown.
use SmartPRS\Surface\Dates;
use SmartPRS\Surface\Sheet;

include __DIR__ . '/../partials/offroll-boot.php';

if (!$opReady) {
    echo '<div class="page-header"><div><h2>Agent Pay Reports</h2></div></div>' . $opNotReady;
    return;
}

$params = $_GET;
include __DIR__ . '/../partials/offroll-report-data.php';
$names = ['register' => 'Payout register', 'pending' => 'Pending payments', 'statement' => 'Agent statement', 'vendor' => 'Vendor-wise', 'tds' => 'TDS register', 'activity' => 'Attendance & activity', 'compliance' => 'Certificate compliance'];
$money = array_fill_keys($rep['money'], true);
$bold = array_fill_keys($rep['bold'], true);
$hidden = '';

foreach (['rep', 'month', 'f_company', 'fy', 'q', 'agent'] as $k) {
    $hidden .= '<input type="hidden" name="' . $k . '" value="' . e((string) ($params[$k] ?? '')) . '">';
}
?>
<div class="page-header">
  <div><h2>Agent Pay Reports</h2><p><?= e($rep['title']) ?> &middot; <?= e($rep['subtitle']) ?></p></div>
  <div class="actions"><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="offroll-export"><input type="hidden" name="what" value="report"><?= $hidden ?>
    <button class="btn btn-outline btn-sm" type="submit" style="white-space:nowrap"><i class="fas fa-file-excel"></i> Export to Excel</button></form></div>
</div>

<div class="card">
  <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">
<?php foreach ($names as $k => $n): ?><a class="btn <?= $rep['key'] === $k ? 'btn-primary' : 'btn-outline' ?> btn-sm" href="?r=offroll-reports&amp;rep=<?= e($k) ?>"><?= e($n) ?></a><?php endforeach; ?>
  </div>
  <form method="get" class="row" style="align-items:flex-end;margin:0;gap:10px;flex-wrap:wrap"><input type="hidden" name="r" value="offroll-reports"><input type="hidden" name="rep" value="<?= e($rep['key']) ?>">
<?php if (in_array('month', $rep['filters'], true)): ?><div><label>Month</label><input type="month" name="month" value="<?= e($repMonth) ?>"></div><?php endif; ?>
<?php if (in_array('fy', $rep['filters'], true)): ?><div><label>Financial year</label><select name="fy"><?php for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 4; $y--): ?><option value="<?= $y ?>" <?= $repFy === $y ? 'selected' : '' ?>><?= $y ?>-<?= substr((string) ($y + 1), 2) ?></option><?php endfor; ?></select></div><?php endif; ?>
<?php if (in_array('q', $rep['filters'], true)): ?><div><label>Quarter</label><select name="q"><option value="0">Whole year</option><?php for ($i = 1; $i <= 4; $i++): ?><option value="<?= $i ?>" <?= $repQ === $i ? 'selected' : '' ?>>Q<?= $i ?></option><?php endfor; ?></select></div><?php endif; ?>
<?php if (in_array('agent', $rep['filters'], true)): ?><div style="min-width:240px"><label>Agent</label><select name="agent"><option value="">— pick —</option><?php foreach ($opAgents as $k => $a): ?><option value="<?= e((string) $k) ?>" <?= $repAgent === (string) $k ? 'selected' : '' ?>><?= e($op->label($a)) ?></option><?php endforeach; ?></select></div><?php endif; ?>
<?php if (in_array('company', $rep['filters'], true)): ?><div><label>Company</label><select name="f_company"><option value="">ALL</option><?php foreach ($opCompanies as $k => $v): ?><option value="<?= e($k) ?>" <?= $repCo === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div><button class="go" type="submit">Show</button></div>
  </form>
</div>

<div class="card" style="padding:0">
  <div class="table-wrap"><table style="font-size:12.5px">
    <thead><tr><?php foreach ($rep['head'] as $i => $h): ?><th<?= isset($money[$i]) ? ' class="n"' : '' ?>><?= e($h) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php if ($rep['rows'] === []): ?><tr><td colspan="<?= count($rep['head']) ?>" class="note">Nothing for this selection.</td></tr><?php endif; ?>
<?php foreach ($rep['rows'] as $ri => $row): ?>
    <tr style="<?= isset($bold[$ri]) ? 'font-weight:700;background:var(--hover, #f8fafc)' : '' ?>"><?php foreach (array_values($row) as $i => $v): $v = (string) $v; ?>
      <td<?= isset($money[$i]) ? ' class="n" style="white-space:nowrap"' : '' ?>><?= isset($money[$i]) && $v !== '' ? e($opMoney($v)) : (in_array($v, ['Expired', 'Not on file'], true) ? '<span class="badge badge-red">' . e($v) . '</span>' : (str_starts_with($v, 'Expiring') ? '<span class="badge badge-amber">' . e($v) . '</span>' : ($v === 'Valid' ? '<span class="badge badge-green">Valid</span>' : e(Dates::inText($v))))) ?></td>
<?php endforeach; ?></tr>
<?php endforeach; ?>
    </tbody></table></div>
</div>
