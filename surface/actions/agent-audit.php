<?php
/**
 * THE AUDIT REPORT ITSELF (owner, 30 Sep) — SmartPRS 2's agent-audit-pdf /
 * agent-audit-bulk-pdf layout, as a print-ready A4 page (the browser's Print →
 * Save as PDF gives the file). One employee → the single report; several → a
 * summary cover page and one report per agent, page-break separated; `sample`
 * → SmartPRS 2's illustrative sample on the picked company's letterhead.
 * Data: SmartPRS\Surface\AgentAudit (a port of agentAuditData / scoreFor).
 */
declare(strict_types=1);

use SmartPRS\Surface\AgentAudit;
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\StatutoryReports;

$aaPermission = (string) StatutoryReports::config(__DIR__ . '/../../config/audit-reports.json')['permission'];

if (!$may($aaPermission)) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], $aaPermission)->summary;
    header('Location: ?r=audit-reports');
    exit;
}

$aaToday = date('Y-m-d');
$aaGenerated = date('d-m-Y') . ', ' . date('H:i') . ' IST';
$aaPeople = [];

foreach ($scope->only(liveRows($store, 'employees'), $may('employee.list')) as $e) {
    $aaPeople[(string) $e['id']] = $e;
}

// THE LETTERHEAD: the employer company's own name, logo, colour, address, GSTIN.
$aaBrand = static function (?array $co): array {
    $color = trim((string) ($co['color'] ?? ''));

    return ['name' => trim((string) ($co['legal_name'] ?? '')) ?: (trim((string) ($co['name'] ?? '')) ?: 'Company'),
        'color' => preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? $color : '#ea580c',
        'logo' => trim((string) ($co['logo_path'] ?? '')), 'tagline' => trim((string) ($co['tagline'] ?? '')),
        'address' => trim((string) ($co['address'] ?? '')), 'gstin' => trim((string) ($co['gstin'] ?? ''))];
};
$aaCompanyOf = static fn (array $e): ?array => ($id = (string) ($e['employer_company_id'] ?? '')) === '' ? null : $store->find('companies', $id);

$agents = [];

if (post('mode') === 'sample') {
    // SmartPRS 2's sampleAuditPdf(), verbatim data, on the filtered company's letterhead (else the first company).
    $aaCo = null;

    foreach (liveRows($store, 'companies') as $c) {
        if ($aaCo === null || (post('company') !== '' && (string) ($c['name'] ?? '') === post('company'))) {
            $aaCo = $c;
        }
    }

    $d = static fn (string $mod): string => date('Y-m-d', (int) strtotime($aaToday . ' ' . $mod));
    $mk = static fn (string $param, string $value, string $state, string $evidence): array => compact('param', 'value', 'state', 'evidence');
    $groups = [
        'Identity & KYC' => [$mk('Photograph on record', 'Yes', 'ok', 'ID card file'), $mk('PAN', 'ABCPK1234F', 'ok', 'Employee master'),
            $mk('UAN (PF)', 'Pending allotment', 'warn', 'Employee master'), $mk('Employee ID card issued', 'Yes', 'ok', 'Auto-generated')],
        'DRA Certification (IIBF)' => [$mk('Valid IIBF DRA certificate', 'Held & valid', 'ok', 'Cert DRA-2025-11223 · valid to ' . $d('+14 months'))],
        'Background Verification' => [$mk('Police clearance (PCC)', 'Clear', 'ok', 'Valid to ' . $d('+8 months')), $mk('Background verification (BGV)', 'Clear', 'ok', 'Agency: SecureCheck India'),
            $mk('References / guarantors (min 2)', '2 on record', 'ok', 'Employee references')],
        'Code of Conduct' => [$mk('Signed undertaking of adherence', 'Acknowledged', 'ok', 'Code of Conduct register')],
        'Authorisation' => [$mk('Bank/portfolio authorisation', 'Sample Bank — Retail NPA Pool', 'ok', 'Auth SB/2026/0417 · to ' . $d('+6 months'))],
        'Conduct Record' => [$mk('Borrower complaints (last 90 days)', '0', 'ok', 'Complaints register'), $mk('Disciplinary actions', '0', 'ok', 'HR record')],
    ];
    $agents[] = ['brand' => $aaBrand($aaCo), 'groups' => $groups, 'pass' => 11, 'warn' => 1, 'fail' => 0, 'score' => 92,
        'verdict' => 'COMPLIANT — WITH OPEN ITEMS', 'ref' => 'RAC/' . substr($aaToday, 0, 4) . '/SAMPLE', 'hash' => 'SAMPLE-ILLUSTRATION',
        'auditFrom' => date('d-m-Y', (int) strtotime($d('-90 days'))), 'auditTo' => date('d-m-Y', (int) strtotime($aaToday)), 'sample' => true,
        'm' => ['name' => 'A. Kumar (Sample Agent)', 'code' => 'SAMPLE-001', 'designation' => 'Recovery Agent', 'status' => 'Active',
            'doj' => $d('-2 years'), 'mobile' => '+91 90000 00000', 'department' => 'Collections']];
} else {
    $ids = post('one') !== '' ? [post('one')] : array_values(array_unique(array_filter(array_map(static fn ($v): string => is_string($v) ? trim($v) : '', (array) ($_POST['ids'] ?? [])))));
    $ids = array_slice($ids, 0, 150); // SmartPRS 2's cap per bulk report
    $chosen = array_values(array_filter(array_map(static fn (string $id): ?array => $aaPeople[$id] ?? null, $ids)));
    usort($chosen, static fn (array $a, array $b): int => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

    if ($chosen === []) {
        $_SESSION['flash_problem'] = 'Pick at least one employee you can see.';
        header('Location: ?r=audit-reports');
        exit;
    }

    foreach ($chosen as $e) {
        $agents[] = AgentAudit::of($store, $e, $aaToday) + ['brand' => $aaBrand($aaCompanyOf($e)), 'sample' => false,
            'm' => ['name' => (string) ($e['name'] ?? ''), 'code' => Employment::code($e), 'designation' => orgValueOf($store, $e, 'designation') ?: '—',
                'status' => ucfirst((string) (($e['status'] ?? '') ?: 'active')), 'doj' => Employment::joinedOn($e) ?: '—',
                'mobile' => trim((string) ($e['mobile'] ?? '')) ?: '—', 'department' => orgValueOf($store, $e, 'department') ?: '—']];
    }

    $audit('report.exported', 'report', 'agent-audit', null, ['agents' => array_column(array_column($agents, 'm'), 'code')],
        'Recovery-agent audit report for ' . count($agents) . ' employee(s)');
}

$vColors = ['ok' => ['#15803d', '#f0fdf4'], 'warn' => ['#b45309', '#fffbeb'], 'bad' => ['#b91c1c', '#fef2f2']];
$vLabel = ['ok' => 'Compliant', 'warn' => 'Attention', 'bad' => 'Non-compliant'];
$verdictColor = static fn (array $a): string => $a['fail'] > 0 ? '#b91c1c' : ($a['warn'] > 0 ? '#b45309' : '#15803d');
$bulk = count($agents) > 1;
$lead = $agents[0]['brand'];
$title = $agents[0]['sample'] ? 'agent-audit-SAMPLE' : ($bulk ? 'agent-audit-bulk-' . date('Ymd-Hi') : 'agent-audit-' . $agents[0]['m']['code'] . '-' . date('Ymd'));

$letterhead = static function (array $b, string $sub, string $right): string {
    return '<table class="head" width="100%" style="border-bottom:3px solid ' . e($b['color']) . '"><tr><td width="62%">'
        . ($b['logo'] !== '' ? '<img src="' . e($b['logo']) . '" style="max-height:38px;max-width:170px;object-fit:contain;margin-bottom:4px"><br>' : '')
        . '<span class="co">' . e($b['name']) . '</span>'
        . ($b['tagline'] !== '' ? ' <span class="co" style="font-weight:normal;font-style:italic;font-size:10px"> &middot; ' . e($b['tagline']) . '</span>' : '')
        . '<div class="co-sub">' . $sub . '</div></td><td width="38%" class="rpt">' . $right . '</td></tr></table>';
};

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title><?= e($title) ?></title>
<style>
  @page { size: A4 portrait; margin: 12mm; }
  * { font-family: "DejaVu Sans", Arial, sans-serif; box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  body { margin: 0; color: #1f2937; font-size: 11px; background: #e5e7eb; }
  .sheet { background: #fff; width: 210mm; min-height: 297mm; margin: 12px auto; padding: 12mm; box-shadow: 0 1px 6px rgba(0,0,0,.15); }
  .head { padding: 0 0 10px; margin-bottom: 4px; border-collapse: collapse; }
  .head td { vertical-align: top; }
  .co { font-size: 19px; font-weight: bold; color: #111827; }
  .co-sub { font-size: 9.5px; color: #6b7280; line-height: 1.5; }
  .rpt { text-align: right; font-size: 9.5px; color: #6b7280; line-height: 1.6; }
  .rpt b { color: #111827; }
  .band { color: #fff; padding: 8px 12px; font-size: 14px; font-weight: bold; margin: 8px 0 0; overflow: hidden; }
  .band .v { float: right; background: #fff; font-size: 10px; padding: 2px 10px; border-radius: 10px; }
  .meta { width: 100%; border-collapse: collapse; margin: 10px 0 6px; }
  .meta td { border: 1px solid #e5e7eb; padding: 5px 8px; width: 25%; }
  .meta .k { font-size: 8px; color: #9ca3af; text-transform: uppercase; letter-spacing: .4px; }
  .meta .val { font-size: 11px; font-weight: bold; }
  .score { width: 100%; border-collapse: collapse; margin: 6px 0 10px; }
  .score td { text-align: center; padding: 7px; border: 1px solid #e5e7eb; background: #f9fafb; width: 25%; }
  .score .big { font-size: 20px; font-weight: bold; }
  .score .lbl { font-size: 8px; text-transform: uppercase; color: #9ca3af; letter-spacing: .4px; }
  h2 { font-size: 12px; color: #111827; padding-left: 8px; margin: 14px 0 4px; }
  table.p { width: 100%; border-collapse: collapse; }
  table.p th { background: #f9fafb; border: 1px solid #e5e7eb; padding: 5px 8px; text-align: left; font-size: 8px; text-transform: uppercase; color: #9ca3af; letter-spacing: .3px; }
  table.p td { border: 1px solid #e5e7eb; padding: 5px 8px; font-size: 10.5px; }
  table.p tr { page-break-inside: avoid; }
  .pill { font-size: 9px; font-weight: bold; padding: 1px 8px; border-radius: 9px; white-space: nowrap; }
  .foot { margin-top: 18px; padding-top: 8px; font-size: 8.5px; color: #6b7280; line-height: 1.6; }
  .sign { border-collapse: collapse; }
  .sign td { padding-top: 28px; font-size: 9px; color: #6b7280; text-align: center; }
  .sign .l { border-top: 1px solid #111827; margin: 0 10px; padding-top: 3px; }
  .bar { position: sticky; top: 0; background: #1f2937; color: #fff; padding: 8px 14px; display: flex; gap: 10px; align-items: center; justify-content: center; font: 13px Arial, sans-serif; z-index: 2; }
  .bar button { background: #ea580c; color: #fff; border: 0; border-radius: 6px; padding: 6px 14px; font-weight: bold; cursor: pointer; }
  @media print { body { background: #fff; } .bar { display: none; } .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; page-break-after: always; } .sheet:last-child { page-break-after: auto; } }
</style></head>
<body>
<div class="bar"><span><?= $bulk ? count($agents) . ' audit reports' : 'Audit report' ?> — A4</span><button type="button" onclick="window.print()">Print / Save as PDF</button></div>
<?php if ($bulk):
    $sumOk = $sumWarn = $sumBad = 0;
    foreach ($agents as $a) { $a['fail'] > 0 ? $sumBad++ : ($a['warn'] > 0 ? $sumWarn++ : $sumOk++); } ?>
<div class="sheet">
  <?= $letterhead($lead, 'Recovery Agent Compliance — Bulk Audit File', '<b>Bulk Compliance Audit</b><br>Agents covered: <b>' . count($agents) . '</b><br>Generated: <b>' . e($aaGenerated) . '</b>') ?>
  <div class="band" style="background:<?= e($lead['color']) ?>">Audit Summary — <?= count($agents) ?> agent(s)</div>
  <table class="score"><tr>
    <td><div class="big" style="color:#15803d"><?= $sumOk ?></div><div class="lbl">Compliant</div></td>
    <td><div class="big" style="color:#b45309"><?= $sumWarn ?></div><div class="lbl">With open items</div></td>
    <td><div class="big" style="color:#b91c1c"><?= $sumBad ?></div><div class="lbl">Non-compliant</div></td>
    <td><div class="big" style="color:<?= e($lead['color']) ?>"><?= count($agents) ?></div><div class="lbl">Total agents</div></td>
  </tr></table>
  <table class="p">
    <tr><th width="4%">#</th><th width="26%">Agent</th><th width="12%">Code</th><th width="18%">Department</th><th width="10%">Score</th><th width="30%">Verdict</th></tr>
<?php foreach ($agents as $i => $a): $vc = $verdictColor($a); $vb = $a['fail'] > 0 ? '#fef2f2' : ($a['warn'] > 0 ? '#fffbeb' : '#f0fdf4'); ?>
    <tr><td><?= $i + 1 ?></td><td><?= e($a['m']['name']) ?></td><td><?= e($a['m']['code']) ?></td><td><?= e($a['m']['department']) ?></td>
      <td style="color:<?= $vc ?>;font-weight:bold"><?= (int) $a['score'] ?>%</td><td><span class="pill" style="color:<?= $vc ?>;background:<?= $vb ?>"><?= e($a['verdict']) ?></span></td></tr>
<?php endforeach; ?>
  </table>
  <div class="foot" style="border-top:2px solid <?= e($lead['color']) ?>">Individual agent reports follow, one per section. Generated from live records by the SmartPRS Recovery-Agent Compliance module.</div>
</div>
<?php endif; ?>
<?php foreach ($agents as $a): $b = $a['brand']; $m = $a['m']; $vc = $verdictColor($a); ?>
<div class="sheet">
  <?= $letterhead($b, ($b['address'] !== '' ? e($b['address']) . '<br>' : '') . ($b['gstin'] !== '' ? 'GSTIN: ' . e($b['gstin']) : ''),
      '<b>Recovery Agent Compliance File</b><br>Report Ref: <b>' . e($a['ref']) . '</b><br>Generated: <b>' . e($aaGenerated) . '</b><br>Audit period: '
      . e($a['auditFrom']) . ' – ' . e($a['auditTo']) . '<br>Tamper-evident hash: <b>' . e($a['hash']) . '</b>') ?>
<?php if ($a['sample']): ?>
  <div style="background:#fef3c7;border:1px dashed #b45309;color:#92400e;font-size:9.5px;font-weight:bold;padding:5px 10px;margin:6px 0 0;text-align:center">SAMPLE REPORT — illustrative data only. Real reports are generated from live employee records.</div>
<?php endif; ?>
  <div class="band" style="background:<?= e($b['color']) ?>">Recovery Agent — RBI Compliance Audit Report <span class="v" style="color:<?= $vc ?>"><?= e($a['verdict']) ?></span></div>
  <table class="meta"><tr>
    <td><div class="k">Agent Name</div><div class="val"><?= e($m['name']) ?></div></td>
    <td><div class="k">Agent Code</div><div class="val"><?= e($m['code']) ?></div></td>
    <td><div class="k">Designation</div><div class="val"><?= e($m['designation']) ?></div></td>
    <td><div class="k">Status</div><div class="val"><?= e($m['status']) ?></div></td>
  </tr><tr>
    <td><div class="k">Date of Joining</div><div class="val"><?= e($m['doj']) ?></div></td>
    <td><div class="k">Mobile</div><div class="val"><?= e($m['mobile']) ?></div></td>
    <td><div class="k">Department</div><div class="val"><?= e($m['department']) ?></div></td>
    <td><div class="k">Compliance Score</div><div class="val" style="color:<?= $vc ?>"><?= (int) $a['score'] ?>%</div></td>
  </tr></table>
  <table class="score"><tr>
    <td><div class="big" style="color:#15803d"><?= (int) $a['pass'] ?></div><div class="lbl">Compliant</div></td>
    <td><div class="big" style="color:#b45309"><?= (int) $a['warn'] ?></div><div class="lbl">Needs attention</div></td>
    <td><div class="big" style="color:#b91c1c"><?= (int) $a['fail'] ?></div><div class="lbl">Non-compliant</div></td>
    <td><div class="big" style="color:<?= e($b['color']) ?>"><?= (int) $a['score'] ?>%</div><div class="lbl">Overall score</div></td>
  </tr></table>
<?php foreach ($a['groups'] as $gname => $rows): ?>
  <h2 style="border-left:4px solid <?= e($b['color']) ?>"><?= e((string) $gname) ?></h2>
  <table class="p">
    <tr><th width="34%">Parameter</th><th width="24%">On Record</th><th width="15%">Status</th><th>Evidence</th></tr>
<?php foreach ($rows as $r): $c = $vColors[$r['state']]; ?>
    <tr><td><?= e($r['param']) ?></td><td><?= e($r['value']) ?></td>
      <td><span class="pill" style="color:<?= $c[0] ?>;background:<?= $c[1] ?>"><?= $vLabel[$r['state']] ?></span></td>
      <td style="color:#6b7280"><?= e($r['evidence'] !== '' ? $r['evidence'] : '—') ?></td></tr>
<?php endforeach; ?>
  </table>
<?php endforeach; ?>
  <table class="sign" width="100%"><tr>
    <td width="33%"><div class="l">Prepared by (Compliance Officer)</div></td>
    <td width="34%"><div class="l">Reviewed by (Operations Head)</div></td>
    <td width="33%"><div class="l">For <?= e($b['name']) ?></div></td>
  </tr></table>
  <div class="foot" style="border-top:2px solid <?= e($b['color']) ?>">Generated from live records by the SmartPRS Recovery-Agent Compliance module. This report is a tamper-evident record (hash-chained audit log).
    Regulatory basis: RBI Guidelines on Recovery Agents, RBI Outsourcing Directions 2025, and IIBF DRA certification norms — indicative; verify against the latest RBI directions applicable to the engaging bank/NBFC before filing.</div>
</div>
<?php endforeach; ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
</body></html>
<?php exit;
