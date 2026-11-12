<?php
/**
 * ASSET EXPORTS (owner, 29 Sep): Asset Stock and Asset Issue & Returns each
 * download what they show — as .csv or as a real .pdf (Surface\TablePdf, no
 * library). Every figure comes from the same unit rows the screens count, and
 * the Company / Branch / Held-by filters on screen are carried into the file.
 *
 *   report: summary | units | available | repair | lost | issued
 *   format: csv | pdf
 */
declare(strict_types=1);

use SmartPRS\Surface\Assets;
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\TablePdf;

if (!$may('employee.view')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'employee.view')->summary;
    header('Location: ?r=asset-stock');
    exit;
}

$assets = new Assets($store, __DIR__ . '/../../config/assets.json');
$declared = $assets->declared();
$report = in_array(post('report'), ['summary', 'units', 'available', 'repair', 'lost', 'issued', 'returned'], true) ? post('report') : 'summary';
$format = post('format') === 'pdf' ? 'pdf' : 'csv';
$co = preg_match('/^\d{1,12}$/', post('company')) === 1 ? post('company') : '';
$br = preg_match('/^\d{1,12}$/', post('branch')) === 1 ? post('branch') : '';
$emp = preg_match('/^\d{1,12}$/', post('emp')) === 1 ? post('emp') : '';
$assets->onlyAt($co, $br);
$showCost = $may('employee.salary.view');
$names = orgNames($store);
$companyNames = (array) ($names['companies'] ?? []);
$branchNames = (array) ($names['branches'] ?? []);
$people = [];
foreach ($scope->only(liveRows($store, 'employees'), $may('employee.list')) as $e) { $people[(string) $e['id']] = $e; }
$model = static fn (array $r): string => (string) ($r['make_model'] ?? '') ?: (string) ($r['asset_name'] ?? '');
// PDF shows ₹1,850.00; CSV keeps the plain decimal so a spreadsheet can add it up.
$money = static fn ($v): string => trim((string) $v) === '' ? '' : ($format === 'pdf' ? SmartPRS\Surface\Amount::of($v)->format() : SmartPRS\Surface\Amount::of($v)->toDecimalString());
$byId = static fn (array $a, array $b): int => strcmp((string) ($a['asset_id'] ?? ''), (string) ($b['asset_id'] ?? ''));
$statusFor = ['available' => (string) ($declared['initial_status'] ?? 'in stock'), 'repair' => 'under repair', 'lost' => 'lost'];
$titles = ['summary' => 'Asset stock summary', 'units' => 'Asset register — every unit', 'available' => 'Available stock',
    'repair' => 'Under repair', 'lost' => 'Lost', 'issued' => 'Issued assets — to be returned', 'returned' => 'Returned items'];
$isoDay = static fn (string $v): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : '';
$retTo = $isoDay(post('to')) ?: date('Y-m-d');
$retFrom = $isoDay(post('from')) ?: date('Y-m-01', strtotime($retTo));
$retFrom > $retTo && [$retFrom, $retTo] = [$retTo, $retFrom];
$right = [];

if ($report === 'summary') {
    $shown = array_values(array_filter(array_keys((array) $declared['statuses']), static fn (string $s): bool => $s !== 'retired'));
    $head = array_merge(['Category', 'Make / model', 'Total'], array_map(static fn (string $s): string => $assets->label($s), $shown));
    $rows = [];
    $tot = array_fill(0, count($shown) + 1, 0);
    foreach ($assets->stockBook() as $b) {
        $line = [$b['category'], $b['model'], (string) $b['total']];
        $tot[0] += $b['total'];
        foreach ($shown as $i => $s) { $n = (int) ($b['counts'][$s] ?? 0); $line[] = (string) $n; $tot[$i + 1] += $n; }
        $rows[] = $line;
    }
    $rows[] = array_merge(['All items', ''], array_map('strval', $tot));
    $right = range(2, count($head) - 1);
} elseif ($report === 'returned') {
    // RETURNED ITEMS IN A PERIOD (owner, 22 Oct 2026): every return on the trail (asset.return), with the unit,
    // who held it, when it was issued and returned, in what condition, and what became of it. The unit row only
    // remembers its LATEST issue, so each return is read from the trail entry written when it happened.
    $unitsById = [];
    foreach ($assets->live() as $u) { $unitsById[(string) $u['id']] = $u; }
    $trailRows = $store->all('audit');
    $localDay = static function (string $at): string {
        try { return (new DateTimeImmutable($at, preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $at) === 1 ? null : new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d'); }
        catch (\Throwable) { return substr($at, 0, 10); }
    };
    $pl = static function (array $e, string $k): array { $p = $e['payload'] ?? []; is_string($p) && $p = (array) json_decode($p, true); $v = $p[$k] ?? []; is_string($v) && $v = (array) json_decode($v, true); return is_array($v) ? $v : []; };
    $issuedOn = []; // unit|employee => list of issue days, from the trail
    foreach ($trailRows as $e) {
        if ((string) ($e['action'] ?? '') === 'asset.issue') {
            $n = $pl($e, 'new_value');
            $issuedOn[(string) ($e['entity_id'] ?? '') . '|' . (string) ($n['issued_to_employee_id'] ?? '')][] = (string) ($n['issue_date'] ?? '') ?: $localDay((string) ($e['occurred_at'] ?? ''));
        }
    }
    $mgrOf = static function (?array $p) use ($store): string { if ($p === null) { return ''; } $m = $store->find('employees', (string) ($p['reporting_manager_id'] ?? '')); return $m === null ? '' : Employment::code($m) . ' ' . (string) ($m['name'] ?? ''); };
    $head = array_merge(['Asset ID', 'Category', 'Make / model', 'Serial number'], $showCost ? ['Cost'] : [],
        ['Employee ID', 'Employee name', 'Designation', 'Department', 'Reporting manager', 'Company', 'Branch', 'Issued on', 'Returned on', 'Days held',
            'Purpose', 'Returned condition', 'Final status', 'Recovery', 'Received by', 'Status now']);
    $rows = [];
    $sortKey = [];
    foreach ($trailRows as $e) {
        if ((string) ($e['action'] ?? '') !== 'asset.return') { continue; }
        $was = $pl($e, 'old_value');
        $now = $pl($e, 'new_value');
        $empId = (string) ($was['issued_to_employee_id'] ?? '');
        $unit = $unitsById[(string) ($e['entity_id'] ?? '')] ?? null;
        if ($unit === null || $empId === '' || ($emp !== '' && $empId !== $emp)) { continue; }
        $back = (string) ($now['return_date'] ?? '') ?: $localDay((string) ($e['occurred_at'] ?? ''));
        if ($back < $retFrom || $back > $retTo) { continue; }
        // The unit row still describes this return when it has not been issued again since.
        $same = (string) ($unit['return_date'] ?? '') === $back && trim((string) ($unit['issued_to_employee_id'] ?? '')) === '';
        $out = (string) ($was['issue_date'] ?? '');
        if ($out === '') {
            $before = array_values(array_filter($issuedOn[(string) $e['entity_id'] . '|' . $empId] ?? [], static fn (string $d): bool => $d !== '' && $d <= $back));
            $out = $before === [] ? ($same ? (string) ($unit['issue_date'] ?? '') : '') : max($before);
        }
        $p = $people[$empId] ?? $store->find('employees', $empId);
        $cond = (string) ($now['condition'] ?? '') ?: ($same ? (string) ($unit['condition'] ?? '') : '');
        $res = (string) ($now['damage_resolution'] ?? '') ?: ($same ? (string) ($unit['damage_resolution'] ?? '') : '');
        $recAmt = (string) ($now['recovery_amount'] ?? '') ?: ($same ? (string) ($unit['recovery_amount'] ?? '') : '');
        $recSt = (string) ($now['recovery_status'] ?? '') ?: ($same ? (string) ($unit['recovery_status'] ?? '') : '');
        $days = $out !== '' ? (string) (int) round((strtotime($back) - strtotime($out)) / 86400) : '';
        $rows[] = array_merge([(string) ($unit['asset_id'] ?? ''), (string) ($unit['asset_type'] ?? ''), $model($unit), (string) ($unit['serial_no'] ?? '')],
            $showCost ? [$money($unit['cost'] ?? '')] : [],
            [$p !== null ? Employment::code($p) : '', (string) ($p['name'] ?? ''),
                $p !== null ? orgValueOf($store, $p, 'designation') : '', $p !== null ? orgValueOf($store, $p, 'department') : '', $mgrOf($p),
                $p !== null ? orgValueOf($store, $p, 'company') : '', $p !== null ? orgValueOf($store, $p, 'branch') : '',
                SmartPRS\Surface\Dates::dmy($out), SmartPRS\Surface\Dates::dmy($back), $days,
                (string) ($was['purpose'] ?? '') ?: ($same ? (string) ($unit['purpose'] ?? '') : ''), ucfirst($cond), $res,
                trim(($recAmt !== '' ? $money($recAmt) . ' ' : '') . $recSt), (string) ($now['returned_by'] ?? '') ?: (string) ($e['actor'] ?? ''),
                $assets->label($assets->statusOf($unit))]);
        $sortKey[] = $back . '|' . (string) ($unit['asset_id'] ?? '');
    }
    array_multisort($sortKey, SORT_DESC, $rows);
    $right = $showCost ? [4, 14] : [13]; // cost and days held
} elseif ($report === 'issued') {
    $held = (array) ($declared['held_statuses'] ?? ['assigned']);
    $list = array_values(array_filter($assets->live(), static fn (array $r): bool => in_array($assets->statusOf($r), $held, true)
        && ($emp === '' || (string) ($r['issued_to_employee_id'] ?? '') === $emp)));
    usort($list, $byId);
    $head = array_merge(['Asset ID', 'Category', 'Make / model', 'Serial number'], $showCost ? ['Cost'] : [],
        ['Issued on', 'Employee ID', 'Employee name', 'Designation', 'Department', 'Reporting manager', 'Company', 'Branch', 'Purpose']);
    $rows = [];
    foreach ($list as $r) {
        $p = $people[(string) ($r['issued_to_employee_id'] ?? '')] ?? null;
        $mgr = $p !== null ? ($people[(string) ($p['reporting_manager_id'] ?? '')] ?? null) : null;
        $rows[] = array_merge([(string) $r['asset_id'], (string) ($r['asset_type'] ?? ''), $model($r), (string) ($r['serial_no'] ?? '')],
            $showCost ? [$money($r['cost'] ?? '')] : [],
            [(string) ($r['issue_date'] ?? ''), $p !== null ? Employment::code($p) : '', (string) ($p['name'] ?? ''),
                $p !== null ? orgValueOf($store, $p, 'designation') : '', $p !== null ? orgValueOf($store, $p, 'department') : '',
                $mgr !== null ? Employment::code($mgr) . ' ' . (string) ($mgr['name'] ?? '') : '',
                $p !== null ? orgValueOf($store, $p, 'company') : '', $p !== null ? orgValueOf($store, $p, 'branch') : '', (string) ($r['purpose'] ?? '')]);
    }
    $right = $showCost ? [4] : [];
} else {
    $list = $report === 'units' ? $assets->live() : $assets->inStatus($statusFor[$report]);
    usort($list, $byId);
    $head = array_merge(['Asset ID', 'Category', 'Make / model', 'Serial number', 'Company', 'Branch', 'Status', 'Condition', 'Held by'],
        $showCost ? ['Cost'] : [], ['Purchased', 'Final status', 'Recovery']);
    $rows = [];
    foreach ($list as $r) {
        $p = $people[(string) ($r['issued_to_employee_id'] ?? '')] ?? null;
        $rec = trim((string) ($r['recovery_amount'] ?? '')) === '' ? (string) ($r['recovery_status'] ?? '')
            : trim($money($r['recovery_amount']) . ' ' . (string) ($r['recovery_status'] ?? ''));
        $rows[] = array_merge([(string) $r['asset_id'], (string) ($r['asset_type'] ?? ''), $model($r), (string) ($r['serial_no'] ?? ''),
            $companyNames[(string) ($r['employer_company_id'] ?? '')] ?? '', $branchNames[(string) ($r['branch_id'] ?? '')] ?? '',
            $assets->label($assets->statusOf($r)), ucfirst((string) ($r['condition'] ?? '')),
            $p !== null ? Employment::code($p) . ' ' . (string) ($p['name'] ?? '') : ''],
            $showCost ? [$money($r['cost'] ?? '')] : [],
            [(string) ($r['purchase_date'] ?? ''), (string) ($r['damage_resolution'] ?? ''), $rec]);
    }
    $right = $showCost ? [9] : [];
}

$scopeLine = ($co !== '' ? ($companyNames[$co] ?? '') : 'All companies') . ($br !== '' ? ' · ' . ($branchNames[$br] ?? '') : '')
    . ($report === 'issued' && $emp !== '' && isset($people[$emp]) ? ' · held by ' . Employment::code($people[$emp]) . ' ' . (string) ($people[$emp]['name'] ?? '') : '')
    . ($report === 'returned' ? ' · returned ' . SmartPRS\Surface\Dates::dmy($retFrom) . ' to ' . SmartPRS\Surface\Dates::dmy($retTo) . ($emp !== '' && isset($people[$emp]) ? ' by ' . Employment::code($people[$emp]) . ' ' . (string) ($people[$emp]['name'] ?? '') : '') : '')
    . ' · ' . ($report === 'summary' ? count($rows) - 1 : count($rows)) . ' row(s) · ' . date('d-m-Y, H:i');
$file = 'assets-' . $report . '-' . date('Ymd-Hi');
$audit('asset.exported', 'assets', $report, null, ['format' => $format, 'rows' => count($rows), 'company' => $co, 'branch' => $br, 'emp' => $emp]
    + ($report === 'returned' ? ['from' => $retFrom, 'to' => $retTo] : []), $titles[$report]);

// RETURNED ITEMS ON ONE A4 PAGE WIDTH: 21 columns truncate to nothing in a PDF, so the PDF carries the 12 that
// identify the return (the CSV keeps every column).
if ($format === 'pdf' && $report === 'returned') {
    $c = $showCost ? 1 : 0;
    // ponytail: TablePdf sizes columns by text length; the trailing spaces give the short-but-dense IDs and branch names room.
    $head = ['Asset ID      ', 'Item', 'Serial number', 'Employee', 'Designation', 'Branch      ', 'Issued', 'Returned', 'Days held', 'Condition', 'Final status', 'Status now'];
    $rows = array_map(static fn (array $r): array => [$r[0], trim($r[1] . ' · ' . $r[2], ' ·'), $r[3], trim($r[4 + $c] . ' ' . $r[5 + $c]), $r[6 + $c], $r[10 + $c],
        $r[11 + $c], $r[12 + $c], $r[13 + $c], $r[15 + $c], trim($r[16 + $c] . ' ' . $r[17 + $c]), $r[19 + $c]], $rows);
    $right = [8];
}

if ($format === 'pdf') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $file . '.pdf"');
    echo TablePdf::render($titles[$report], $scopeLine, $head, $rows, $right);
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $file . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // Excel reads the ₹ and names correctly with a BOM
fputcsv($out, $head, ',', '"', '\\');
foreach ($rows as $r) { fputcsv($out, $r, ',', '"', '\\'); }
fclose($out);
exit;
