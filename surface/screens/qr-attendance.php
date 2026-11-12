<?php declare(strict_types=1); if (!$may('tenant.settings.update')): ?>
<div class="page-header"><div><h2>QR Attendance</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// QR ATTENDANCE (owner, 9–10 Nov 2026) — SmartEPT's QR Attendance without Gate-to-PC. One box per company; every part is a
// column table (owner, 10 Nov: "column wise tables for each branch with company name on top"): Company settings, QR rules,
// Phone approval exceptions, then one table per branch ("Company · Branch") of its gates with the Add row as the last row,
// and its geo-fence exceptions. Inputs sit in their columns through the HTML form="" attribute, so nothing floats.
// Written by surface/actions/qr-attendance.php; read by surface/QrAttendance.php, Punch and public/qr.php.
$qaBase = ((($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http')
    . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/app.php'))), '/');
$qa = SmartPRS\Surface\QrAttendance::load($customisation, __DIR__ . '/../..');
$qaCompanies = liveRows($store, 'companies');
$qaBranches = liveRows($store, 'branches');
$qaBrCo = static fn (array $b): string => (string) (($b['employer_company_id'] ?? '') ?: ($b['company_id'] ?? ''));
// ONE GATE PER BRANCH BY DEFAULT (owner, 12 Nov 2026: "keep the gate name as the Branch name and Main Entrance for all
// branches; if other gates are needed, users will add"): every branch of a QR company gets "<Branch> Main Entrance",
// on the branch's own location (follows Branches), saved once as a new version; the old lone "Main entrance" is renamed.
$qaDefData = $qa->saved();
$qaDefGates = $qa->gates();
$qaDefAdded = [];
foreach ($qaBranches as $b) {
    if ($qa->sourceFor($qaBrCo($b)) !== 'qr') { continue; }
    $bId = (string) $b['id'];
    $bName = trim((string) $b['name']) . ' Main Entrance';
    $has = false;
    foreach ($qaDefGates as $i => $g) {
        if ((string) ($g['branch_id'] ?? '') !== $bId) { continue; }
        $has = true;
        strcasecmp(trim((string) ($g['name'] ?? '')), 'Main entrance') === 0 && ($qaDefGates[$i]['name'] = $bName) && $qaDefAdded[] = 'renamed ' . $g['code'];
    }
    if (!$has) {
        $n = 1 + array_reduce($qaDefGates, static fn (int $m, array $g): int => max($m, (int) substr((string) ($g['code'] ?? ''), 1)), 0);
        $qaDefGates[] = ['code' => 'G' . $n, 'branch_id' => $bId, 'name' => $bName, 'lat' => '', 'lng' => '', 'radius_m' => null,
            'interval_s' => '', 'grace_s' => '', 'gps_accuracy_m' => '', 'duplicate_window_min' => '', 'secret' => SmartPRS\Surface\QrAttendance::newSecret(), 'active' => true];
        $qaDefAdded[] = 'G' . $n . ' ' . $bName;
    }
}
if ($qaDefAdded !== [] && $may('tenant.settings.update')) {
    $qaDefData['gates'] = $qaDefGates;
    $qaDefR = $customisation->saveRaw($qa->customisationKey(), $qaDefData, (string) ($session->user()['id'] ?? ''), (string) $session->user()['email'], 'QR Attendance: default gates — ' . implode(', ', $qaDefAdded));
    $qaDefR['ok'] && $audit('qr_attendance.saved', 'qr_attendance', $qa->customisationKey(), null, ['op' => 'default-gates', 'gates' => $qaDefAdded], 'Default gate per branch');
    $qa = SmartPRS\Surface\QrAttendance::load(new SmartPRS\Surface\Customisation($store), __DIR__ . '/../..');
}
$qaHooks = array_values(array_filter(liveRows($store, 'smartept_webhook_endpoints'), static fn (array $w): bool => (bool) ($w['active'] ?? false)));
$qaEditCode = (string) ($_GET['edit'] ?? '');
$qaPage = in_array((string) ($_GET['qp'] ?? ''), ['dashboard', 'ledger', 'phones', 'unmatched', 'employees', 'gates', 'policy'], true) ? (string) $_GET['qp'] : 'dashboard';
$qaHid = static fn (string $op, string $code = ''): string => '<input type="hidden" name="csrf" value="' . e($session->token()) . '"><input type="hidden" name="action" value="qr-attendance"><input type="hidden" name="qp" value="' . e($qaPage) . '"><input type="hidden" name="op" value="' . e($op) . '"><input type="hidden" name="code" value="' . e($code) . '">';
$qaWhen = static fn (mixed $t): string => $t ? SmartPRS\Surface\Dates::dmy(substr((string) $t, 0, 10)) . ' ' . substr((string) $t, 11, 5) : 'never';
$qaColours = ['#1F3A5F', '#B8893B', '#0F766E', '#7C3AED', '#BE123C', '#0369A1']; // ponytail: box colours cycle by company order
$qaSpecs = $qa->settingSpecs();
$qaShort = static fn (string $k): string => (string) ($qaSpecs[$k]['short'] ?? $qaSpecs[$k]['label'] ?? $k);
$qaEmps = liveRows($store, 'employees');
$qaEmpLabel = static fn (array $e): string => SmartPRS\Surface\Employment::code($e) . ' · ' . trim((string) ($e['name'] ?? ''));
$qaEmpName = [];
foreach ($qaEmps as $e) { $qaEmpName[(string) $e['id']] = $qaEmpLabel($e); }
$qaToday = date('Y-m-d');
$qaFid = 0;
// ONE EXCEPTIONS TABLE (phone exceptions per company, geo-fence exceptions per branch); the last row adds one.
$qaExceptions = static function (string $kind, string $title, array $rows, array $choices, string $scopeField, string $scopeId)
    use ($qaHid, $qaEmpName, $qaToday, &$qaFid): string {
    $f = 'qa-f' . (++$qaFid);
    $out = '<form method="post" id="' . $f . '">' . $qaHid('add-exception') . '<input type="hidden" name="kind" value="' . e($kind) . '"><input type="hidden" name="' . e($scopeField) . '" value="' . e($scopeId) . '"></form>'
        . '<div class="qa-cap">' . $title . '<button type="button" class="go btn-sm qa-add" data-qa-add="' . $f . '-row"><i class="fas fa-plus"></i> Add</button></div><div class="table-wrap"><table class="qa-t"><thead><tr><th style="width:24%">Employee</th><th style="width:12%">Applies</th><th style="width:13%">From</th><th style="width:13%">To</th><th>Reason</th><th style="width:13%">Added by</th><th style="width:9%">Status</th><th style="width:80px"></th></tr></thead><tbody>';
    foreach ($rows as $x) {
        $always = trim((string) ($x['from'] ?? '')) === '' && trim((string) ($x['to'] ?? '')) === '';
        $live = SmartPRS\Surface\QrAttendance::exceptionLive($x, $qaToday);
        $out .= '<tr><td><b>' . e((string) ($qaEmpName[(string) $x['employee_id']] ?? $x['employee_id'])) . '</b></td><td>' . ($always ? 'Always' : 'Date range') . '</td><td>'
            . ($always ? '—' : e(SmartPRS\Surface\Dates::dmy((string) $x['from']))) . '</td><td>' . ($always ? '—' : e(SmartPRS\Surface\Dates::dmy((string) $x['to']))) . '</td><td>' . e((string) ($x['reason'] ?? ''))
            . '</td><td class="note">' . e((string) ($x['by'] ?? '')) . '<br>' . e(SmartPRS\Surface\Dates::dmy(substr((string) ($x['at'] ?? ''), 0, 10))) . '</td><td><span class="badge badge-'
            . ($live ? 'green">In force' : 'gray">Not in force') . '</span></td><td><form method="post" style="margin:0">' . $qaHid('remove-exception', (string) $x['id'])
            . '<button class="btn btn-sm btn-outline" type="submit" title="Remove" onclick="return confirm(\'Remove this exception?\')"><i class="fas fa-xmark"></i></button></form></td></tr>';
    }
    $out .= '<tr class="qa-new" id="' . $f . '-row" hidden><td>' . employeePicker($f . '-emp', 'employee_id', $choices, '', 'Type code or name', true, 'form="' . $f . '"') . '</td>'
        . '<td><select form="' . $f . '" name="when" data-qa-when><option value="always">Always</option><option value="range">Date range</option></select></td>'
        . '<td><input form="' . $f . '" type="date" name="from" data-qa-range disabled></td><td><input form="' . $f . '" type="date" name="to" data-qa-range disabled></td>'
        . '<td colspan="3"><input form="' . $f . '" name="reason" required maxlength="160" placeholder="Reason — ' . ($kind === 'phone' ? 'demo / Android review' : 'field visits / works off-site') . '"></td>'
        . '<td><button form="' . $f . '" class="go qa-btn" type="submit"><i class="fas fa-floppy-disk"></i> Save</button></td></tr></tbody></table></div>';

    return $out;
};
?>
<style>
  .qa-co{border:4px double var(--qa);border-radius:14px;padding:14px 16px 6px;margin:0 0 18px;background:var(--card)}
  .qa-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 10px}
  .qa-head h3{margin:0;color:var(--qa);font-size:18px;display:flex;align-items:center;gap:8px}
  .qa-cap{margin:14px 0 6px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text2);display:flex;align-items:center;gap:8px}
  .qa-cap .qa-add{margin-left:auto;text-transform:none;letter-spacing:0}
  .qa-cap .note+.qa-add{margin-left:10px}
  .qa-cap .qa-co-name{color:var(--qa);text-transform:none;letter-spacing:0;font-size:14px}
  .qa-t{width:100%;table-layout:fixed}
  .qa-t td,.qa-t th{vertical-align:middle;padding-left:6px;padding-right:6px}
  .qa-t th{white-space:normal;line-height:1.25;word-break:normal}
  .qa-t td input,.qa-t td select,.qa-t .emp-pick,.qa-t .emp-pick-text{width:100%;min-width:0;box-sizing:border-box}
  .qa-t .qa-new td{background:color-mix(in srgb,var(--qa) 6%,transparent)}
  .qa-t .qa-rng{font-size:11px;color:var(--text2);margin-top:2px}
  .qa-btn{white-space:nowrap;width:100%;justify-content:center}
  .qa-acts{display:flex;gap:5px;flex-wrap:nowrap;justify-content:flex-start;align-items:center}
  .qa-acts .btn-sm{padding:5px 8px}
  .qa-br{border:2px solid color-mix(in srgb,var(--qa) 40%,transparent);border-radius:12px;padding:2px 12px 10px;margin:12px 0}
  @media (max-width:760px){.qa-t{table-layout:auto}}
</style>
<?php
// ── QR ATTENDANCE AS ITS OWN SET OF PAGES (owner, 12 Nov 2026: "similar to SmartEPT; not within one single page" — reference
// admin.smartept.com/qronex). Same grouping as SmartEPT's QR module: Overview (Dashboard, Attendance ledger), Approvals (Phones),
// Organisation (Employees, Offices & gates), Settings (Module & policy). Nothing about saving changed: every form still posts to
// surface/actions/qr-attendance.php (or mobile-device.php) and comes back to the page it was sent from (`qp`).
$qaNow = date('Y-m-d');
$qaCoName = []; foreach ($qaCompanies as $c) { $qaCoName[(string) $c['id']] = (string) $c['name']; }
$qaBrName = []; foreach ($qaBranches as $b) { $qaBrName[(string) $b['id']] = (string) $b['name']; }
$qaQrCos = array_values(array_filter(array_map('strval', array_keys($qaCoName)), static fn (string $id): bool => $qa->sourceFor($id) === 'qr'));
$qaScoped = $scope->only($qaEmps, $may('employee.list'));
$qaQrEmps = array_values(array_filter($qaScoped, static fn (array $e): bool => in_array((string) ($e['employer_company_id'] ?? ''), $qaQrCos, true)));
$qaByCode = []; foreach ($qaQrEmps as $e) { $qaByCode[SmartPRS\Surface\Employment::code($e)] = $e; }
$qaGateName = []; foreach ($qa->gates() as $g) { $qaGateName[(string) $g['code']] = (string) $g['name']; }
// A QR punch's reference is qr:<gate>:<window>|gps:…|<metres>m[|geofence-exception] (surface/actions/self-punch.php).
$qaRef = static function (?string $ref) use ($qaGateName): array {
    $parts = explode('|', (string) $ref);
    $gate = explode(':', $parts[0])[1] ?? '';
    $m = ''; foreach ($parts as $p) { preg_match('/^(\d+)m$/', $p, $mm) === 1 && $m = $mm[1] . ' m'; }
    return ['gate' => $gate, 'gate_name' => $qaGateName[$gate] ?? $gate, 'distance' => in_array('geofence-exception', $parts, true) ? 'geo-fence exception' : $m];
};
$qaPunches = static function (string $from, string $to, array $emps) use ($attendance): array {
    $out = [];
    foreach ($emps as $code => $e) {
        foreach ($attendance->punchesFor((string) $code) as $p) {
            $d = substr((string) $p['at'], 0, 10);
            (string) $p['source'] === SmartPRS\Surface\QrAttendance::SOURCE && $d >= $from && $d <= $to && $out[] = $p + ['emp' => $e];
        }
    }
    usort($out, static fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));
    return $out;
};
// Refused scans are written to the audit trail as attendance.punch.refused_qr (UTC on the server clock).
$qaAuditWhen = static function (array $e): string {
    $at = (string) ($e['occurred_at'] ?? '');
    try { return (new DateTimeImmutable($at, preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $at) === 1 ? null : new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'); }
    catch (\Throwable) { return $at; } // an unparseable stamp is shown as stored
};
$qaAudit = null;
$qaAuditRows = static function () use (&$qaAudit, $store, $qaAuditWhen): array {
    if ($qaAudit === null) {
        $qaAudit = [];
        foreach ($store->all('audit') as $e) {
            $a = (string) ($e['action'] ?? '');
            if (!str_starts_with($a, 'qr_attendance.') && !str_starts_with($a, 'mobile_device.') && $a !== 'attendance.punch.refused_qr') { continue; }
            $p = $e['payload'] ?? []; is_string($p) && $p = (array) json_decode($p, true);
            $n = $p['new_value'] ?? null; is_string($n) && $n = json_decode($n, true);
            $qaAudit[] = ['at' => $qaAuditWhen($e), 'action' => $a, 'id' => (string) ($e['entity_id'] ?? ''), 'by' => (string) ($e['actor'] ?? ''), 'why' => (string) ($e['reason'] ?? ''), 'new' => is_array($n) ? $n : []];
        }
        usort($qaAudit, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));
    }
    return $qaAudit;
};
$qaDmyT = static fn (string $t): string => SmartPRS\Surface\Dates::dmy(substr($t, 0, 10)) . ' ' . substr($t, 11, 8);
$qaDevices = liveRows($store, 'mobile_devices');
$qaPending = count(array_filter($qaDevices, static fn (array $d): bool => (string) $d['status'] === 'pending'));
// UNMATCHED PUNCHES (owner, 12 Nov 2026): a punch from SmartEPT (or a device) whose employee code is nobody in SmartPRS3 is
// held, never guessed (PunchIngest); it is matched here and its held punches move into attendance.
$qaHeld = [];
try {
    foreach ($store->all('attendance_pending') as $p) {
        trim((string) ($p['resolved_at'] ?? '')) === '' && $qaHeld[strtolower((string) (($p['device_code'] ?? '') ?: ($p['device_user_id'] ?? '')))][] = $p;
    }
    $qaUnmapped = $store->all('biometric_unmapped');
} catch (\Throwable $qaX) {
    $qaUnmapped = []; // ponytail: a store without these tables has nothing held
}
$qaUnmapped = array_values(array_filter($qaUnmapped, static fn (array $u): bool => isset($qaHeld[strtolower((string) ($u['device_code'] ?? ''))])));
usort($qaUnmapped, static fn (array $a, array $b): int => strcmp((string) ($b['last_seen'] ?? ''), (string) ($a['last_seen'] ?? '')));
$qaPages = [
    'Overview' => ['dashboard' => ['Dashboard', 'fa-gauge-high'], 'ledger' => ['Attendance ledger', 'fa-list']],
    'Approvals' => ['phones' => ['Phones', 'fa-mobile-screen-button'], 'unmatched' => ['Unmatched punches', 'fa-link-slash']],
    'Organisation' => ['employees' => ['Employees', 'fa-users'], 'gates' => ['Offices & gates', 'fa-building']],
    'Settings' => ['policy' => ['Module & policy', 'fa-sliders']],
];
$qaLabel = ''; foreach ($qaPages as $grp) { isset($grp[$qaPage]) && $qaLabel = $grp[$qaPage][0]; }
$qaXls = static fn (string $table, string $file): string => '<button type="button" class="btn btn-outline btn-sm" onclick="qaXls(\'' . e($table) . '\',\'' . e($file) . '\')"><i class="fas fa-file-excel"></i> Export Excel</button>';
?>
<style>
  .qa-nav{display:flex;flex-wrap:wrap;gap:6px 10px;align-items:flex-end;margin:0 0 16px;padding:8px 10px;background:var(--card);border:1px solid var(--border);border-radius:12px}
  .qa-nav-g{display:flex;flex-direction:column;gap:4px}
  .qa-nav-g>span{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text3);padding-left:4px}
  .qa-nav-g>div{display:flex;gap:4px}
  .qa-nav a{display:inline-flex;align-items:center;gap:7px;padding:7px 9px;border-radius:8px;text-decoration:none;color:var(--text1);font-size:13px;font-weight:600;white-space:nowrap}
  .qa-nav a:hover{background:color-mix(in srgb,#1F3A5F 8%,transparent)}
  .qa-nav a.on{background:#1F3A5F;color:#fff}
  .qa-nav a .badge{margin-left:2px}
  .qa-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:14px}
  .qa-stat{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px 16px;display:flex;justify-content:space-between;gap:10px;text-decoration:none;color:inherit}
  .qa-stat b{display:block;font-size:26px;color:#1F3A5F;line-height:1.2}
  .qa-stat small{font-size:12px;color:var(--text2)}
  .qa-stat i{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex:none}
  .qa-two{display:grid;grid-template-columns:minmax(240px,1fr) 2fr;gap:14px;align-items:start}
  @media (max-width:900px){.qa-two{grid-template-columns:1fr}}
  .qa-wait a{display:flex;justify-content:space-between;padding:9px 4px;border-bottom:1px solid var(--border);text-decoration:none;color:inherit}
  .qa-tabs{display:flex;gap:6px;margin:0 0 10px}
  .qa-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border:1px solid var(--border);border-radius:8px;text-decoration:none;color:var(--text1);font-size:13px;font-weight:600;background:var(--card)}
  .qa-pill.on{background:#1F3A5F;border-color:#1F3A5F;color:#fff}
  /* QR ATTENDANCE → EMPLOYEES (owner, 12 Nov 2026): nothing bold on this page; the activation result can be closed */
  #qa-emps td, #qa-emps td *:not(i){font-weight:400 !important}
  #qa-emps .badge{white-space:nowrap}
  #qa-emps td a{color:inherit}
  .qa-ma{position:relative;padding:4px 40px 4px 4px}
  .qa-ma-x{position:absolute;top:2px;right:2px;width:28px;height:28px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text2);cursor:pointer}
  .qa-ma-x:hover{color:#DC2626;border-color:#DC2626}
  .qa-ma-h{color:#166534;margin-bottom:6px}
  .qa-ma table{width:auto;border:0;margin:0;background:transparent}
  .qa-ma table td{border:0 !important;padding:2px 16px 2px 0 !important;background:transparent !important;white-space:nowrap}
  .qa-ma table td:first-child{color:var(--text2)}
  .qa-ma .qa-ma-code{font:16px ui-monospace,Consolas,monospace !important;letter-spacing:.1em}
</style>
<div class="page-header"><div><h2>QR Attendance<?= $qaLabel !== '' ? ' · ' . e($qaLabel) : '' ?></h2></div></div>
<nav class="qa-nav">
<?php foreach ($qaPages as $grp => $items): ?>
  <div class="qa-nav-g"><span><?= e($grp) ?></span><div>
<?php foreach ($items as $k => [$l, $ic]): ?>
    <a href="?r=qr-attendance&amp;qp=<?= e($k) ?>" class="<?= $k === $qaPage ? 'on' : '' ?>"><i class="fas <?= e($ic) ?>"></i> <?= e($l) ?><?= $k === 'phones' && $qaPending > 0 ? ' <span class="badge badge-amber">' . $qaPending . '</span>' : '' ?><?= $k === 'unmatched' && $qaUnmapped !== [] ? ' <span class="badge badge-red">' . count($qaUnmapped) . '</span>' : '' ?></a>
<?php endforeach; ?>
  </div></div>
<?php endforeach; ?>
</nav>
<?php if ($qaQrCos === [] && in_array($qaPage, ['dashboard', 'ledger', 'employees', 'gates'], true)): ?>
<div class="card note">No company uses QR Attendance yet — choose <b>QR Attendance</b> as the attendance source in <a href="?r=qr-attendance&amp;qp=policy">Module &amp; policy</a>.</div>
<?php endif; ?>

<?php if ($qaPage === 'dashboard'):
    $qaToday = $qaPunches($qaNow, $qaNow, $qaByCode);
    $qaRefusedToday = array_filter($qaAuditRows(), static fn (array $a): bool => $a['action'] === 'attendance.punch.refused_qr' && substr($a['at'], 0, 10) === $qaNow);
    $qaPresent = count(array_unique(array_map(static fn (array $p): string => (string) $p['employment_id'], $qaToday))); ?>
<div class="qa-stats">
  <a class="qa-stat" href="?r=qr-attendance&amp;qp=employees"><span><small>Employees</small><b><?= count($qaQrEmps) ?></b><small>In QR companies</small></span><i class="fas fa-users" style="background:#EEF2FF;color:#4338CA"></i></a>
  <a class="qa-stat" href="?r=qr-attendance&amp;qp=ledger"><span><small>Present today</small><b><?= $qaPresent ?></b><small>Scanned at a gate</small></span><i class="fas fa-circle-check" style="background:#ECFDF5;color:#047857"></i></a>
  <a class="qa-stat" href="?r=qr-attendance&amp;qp=ledger"><span><small>Punches today</small><b><?= count($qaToday) ?></b><small>IN and OUT</small></span><i class="fas fa-clock" style="background:#EFF6FF;color:#1D4ED8"></i></a>
  <a class="qa-stat" href="?r=qr-attendance&amp;qp=ledger&amp;view=rejected"><span><small>Rejected today</small><b><?= count($qaRefusedToday) ?></b><small>Scans refused</small></span><i class="fas fa-triangle-exclamation" style="background:#FFF1F2;color:#BE123C"></i></a>
  <a class="qa-stat" href="?r=qr-attendance&amp;qp=phones&amp;s=pending"><span><small>Phones waiting</small><b><?= $qaPending ?></b><small>For approval</small></span><i class="fas fa-mobile-screen-button" style="background:#FFFBEB;color:#B45309"></i></a>
</div>
<div class="qa-two">
  <div class="card"><h3 style="margin:0 0 6px">Waiting for approval</h3><div class="qa-wait">
    <a href="?r=qr-attendance&amp;qp=phones&amp;s=pending"><span>Phones</span><span class="badge badge-<?= $qaPending > 0 ? 'amber' : 'gray' ?>"><?= $qaPending ?></span></a>
    <a href="?r=qr-attendance&amp;qp=unmatched"><span>Unmatched punches</span><span class="badge badge-<?= $qaUnmapped !== [] ? 'red' : 'gray' ?>"><?= count($qaUnmapped) ?></span></a>
    <a href="?r=attendance-corrections"><span>Attendance regularizations</span><span class="note">open <i class="fas fa-arrow-right"></i></span></a>
  </div></div>
  <div class="card" style="padding:0"><h3 style="margin:0;padding:14px 16px 6px">Offices</h3><div class="table-wrap"><table>
    <thead><tr><th>Company</th><th>Branch</th><th>Gates active</th><th>Employees</th><th>Present today</th></tr></thead><tbody>
<?php $qaAny = false; foreach ($qaBranches as $b): $bId = (string) $b['id']; if (!in_array($qaBrCo($b), $qaQrCos, true)) { continue; } $qaAny = true;
      $bG = array_filter($qa->gates(), static fn (array $g): bool => (string) ($g['branch_id'] ?? '') === $bId);
      $bE = array_filter($qaQrEmps, static fn (array $e): bool => (string) ($e['branch_id'] ?? '') === $bId);
      $bCodes = array_map(static fn (array $e): string => SmartPRS\Surface\Employment::code($e), $bE);
      $bP = count(array_unique(array_filter(array_map(static fn (array $p): string => (string) $p['employment_id'], $qaToday), static fn (string $c): bool => in_array($c, $bCodes, true)))); ?>
      <tr><td><?= e($qaCoName[$qaBrCo($b)] ?? '') ?></td><td><b><?= e((string) $b['name']) ?></b></td>
        <td><?= count(array_filter($bG, static fn (array $g): bool => (bool) ($g['active'] ?? true))) ?> / <?= count($bG) ?></td><td><?= count($bE) ?></td><td><?= $bP ?></td></tr>
<?php endforeach; if (!$qaAny): ?><tr><td colspan="5" class="note">No branch of a QR company yet.</td></tr><?php endif; ?>
    </tbody></table></div></div>
</div>
<div class="card" style="padding:0;margin-top:14px"><h3 style="margin:0;padding:14px 16px 6px">Recent activity</h3><div class="table-wrap"><table>
  <thead><tr><th style="width:150px">When</th><th>What happened</th><th style="width:180px">Record</th><th style="width:200px">By</th></tr></thead><tbody>
<?php $qaRecent = array_slice($qaAuditRows(), 0, 12); foreach ($qaRecent as $a): ?>
    <tr><td><?= e($qaDmyT($a['at'])) ?></td><td><?= e($a['why'] !== '' ? $a['why'] : $a['action']) ?><div class="note" style="font-size:11px"><code><?= e($a['action']) ?></code></div></td>
      <td><?= e(isset($qaByCode[$a['id']]) ? $qaEmpLabel($qaByCode[$a['id']]) : $a['id']) ?></td><td class="note"><?= e($a['by']) ?></td></tr>
<?php endforeach; if ($qaRecent === []): ?><tr><td colspan="4" class="note">Nothing yet.</td></tr><?php endif; ?>
  </tbody></table></div></div>

<?php elseif ($qaPage === 'ledger'):
    $qaView = ($_GET['view'] ?? '') === 'rejected' ? 'rejected' : 'verified';
    $qaPer = reportPeriod(isset($_GET['p']) ? $_GET : ['p' => 'custom', 'from' => $qaNow, 'to' => $qaNow], 4, $qaNow);
    $qaF = orgFilter();
    $qaPick = [];
    foreach (orgFilterApply($store, $qaQrEmps, $qaF) as $e) { $qaPick[SmartPRS\Surface\Employment::code($e)] = $e; }
    $qaKeep = ['qp' => 'ledger', 'view' => $qaView];
    $qaRows = $qaView === 'verified' ? $qaPunches($qaPer['from'], $qaPer['to'], $qaPick)
        : array_values(array_filter($qaAuditRows(), static fn (array $a): bool => $a['action'] === 'attendance.punch.refused_qr' && isset($qaPick[$a['id']]) && substr($a['at'], 0, 10) >= $qaPer['from'] && substr($a['at'], 0, 10) <= $qaPer['to'])); ?>
<div class="card">
  <?= orgFilterBar('qr-attendance', $qaF, orgFilterOptions($store, $qaQrEmps), $qaKeep, 'Show', null, $qaPer, ['custom', 'week', 'mtd', 'month', 'lastmonth']) ?>
  <div class="qa-tabs" style="justify-content:space-between;align-items:center">
    <div class="qa-tabs" style="margin:0">
<?php foreach (['verified' => 'Verified', 'rejected' => 'Rejected attempts'] as $k => $l): ?>
      <a class="qa-pill<?= $k === $qaView ? ' on' : '' ?>" href="?<?= e(http_build_query(['view' => $k] + $_GET)) ?>"><?= e($l) ?></a>
<?php endforeach; ?>
    </div><?= $qaXls('qa-ledger', 'qr-attendance-' . $qaView . '-' . SmartPRS\Surface\Dates::dmy($qaPer['from']) . '-to-' . SmartPRS\Surface\Dates::dmy($qaPer['to']) . '.csv') ?>
  </div>
</div>
<div class="card" style="padding:0"><div class="table-wrap"><table id="qa-ledger">
<?php if ($qaView === 'verified'): ?>
  <thead><tr><th>Date</th><th>Time</th><th>Emp ID · Name</th><th>Company</th><th>Branch</th><th>Type</th><th>Gate</th><th>Distance</th><th>Reference</th></tr></thead><tbody>
<?php foreach ($qaRows as $p): $r = $qaRef($p['source_reference'] ?? null); $e = $p['emp']; ?>
    <tr><td><?= e(SmartPRS\Surface\Dates::dmy(substr((string) $p['at'], 0, 10))) ?></td><td><?= e(substr((string) $p['at'], 11, 8)) ?></td><td><b><?= e($qaEmpLabel($e)) ?></b></td><td><?= e($qaCoName[(string) ($e['employer_company_id'] ?? '')] ?? '') ?></td>
      <td><?= e($qaBrName[(string) ($e['branch_id'] ?? '')] ?? '') ?></td><td><span class="badge badge-<?= $p['direction'] === 'in' ? 'green' : 'blue' ?>"><?= e(strtoupper((string) $p['direction'])) ?></span></td>
      <td><?= e($r['gate'] . ' · ' . $r['gate_name']) ?></td><td><?= e($r['distance']) ?></td><td class="note" style="font-size:11px;overflow-wrap:anywhere"><?= e((string) ($p['source_reference'] ?? '')) ?></td></tr>
<?php endforeach; if ($qaRows === []): ?><tr><td colspan="9" class="note">No QR punch for these filters.</td></tr><?php endif; ?>
<?php else: ?>
  <thead><tr><th>Date</th><th>Time</th><th>Emp ID · Name</th><th>Company</th><th>Branch</th><th>Check failed</th><th>Why</th></tr></thead><tbody>
<?php foreach ($qaRows as $a): $e = $qaPick[$a['id']]; ?>
    <tr><td><?= e(SmartPRS\Surface\Dates::dmy(substr($a['at'], 0, 10))) ?></td><td><?= e(substr($a['at'], 11, 8)) ?></td><td><b><?= e($qaEmpLabel($e)) ?></b></td><td><?= e($qaCoName[(string) ($e['employer_company_id'] ?? '')] ?? '') ?></td>
      <td><?= e($qaBrName[(string) ($e['branch_id'] ?? '')] ?? '') ?></td><td><span class="badge badge-red"><?= e((string) ($a['new']['reason'] ?? '')) ?></span></td><td><?= e($a['why']) ?></td></tr>
<?php endforeach; if ($qaRows === []): ?><tr><td colspan="7" class="note">No refused scan for these filters.</td></tr><?php endif; ?>
<?php endif; ?>
  </tbody></table></div></div>

<?php elseif ($qaPage === 'employees'):
    $qaF = orgFilter();
    $qaList = orgFilterApply($store, $qaQrEmps, $qaF);
    $qaMaRow = (array) ($_SESSION['ma_row'] ?? []);
    unset($_SESSION['ma_row']); // shown once, under that employee's row
    $qaDevBy = []; foreach ($qaDevices as $d) { $qaDevBy[(string) ($d['employee_id'] ?? '')][] = (string) $d['status']; }
    $qaToday = $qaPunches($qaNow, $qaNow, $qaByCode);
    $qaLast = []; foreach ($qaPunches('0000-00-00', $qaNow, $qaByCode) as $p) { $qaLast[(string) $p['employment_id']] ??= (string) $p['at']; } ?>
<div class="card">
  <?= orgFilterBar('qr-attendance', $qaF, orgFilterOptions($store, $qaQrEmps), ['qp' => 'employees']) ?>
  <div style="display:flex;justify-content:flex-end"><?= $qaXls('qa-emps', 'qr-attendance-employees-' . SmartPRS\Surface\Dates::dmy($qaNow) . '.csv') ?></div>
</div>
<div class="card" style="padding:0"><div class="table-wrap"><table id="qa-emps">
  <thead><tr><th>Emp ID · Name</th><th>Company</th><th>Branch</th><th>Phone</th><th>Exceptions</th><th>Today IN</th><th>Today OUT</th><th>Last QR punch</th><th class="no-export" style="width:110px">App code</th></tr></thead><tbody>
<?php foreach ($qaList as $e): $id = (string) $e['id']; $code = SmartPRS\Surface\Employment::code($e);
      $st = $qaDevBy[$id] ?? []; $ph = in_array('approved', $st, true) ? ['green', 'Approved'] : (in_array('pending', $st, true) ? ['amber', 'Waiting'] : ($st === [] ? ['gray', 'No phone'] : ['red', ucfirst((string) end($st))]));
      $tIn = ''; $tOut = ''; foreach (array_reverse($qaToday) as $p) { if ((string) $p['employment_id'] !== $code) { continue; } $p['direction'] === 'in' ? ($tIn = $tIn ?: substr((string) $p['at'], 11, 5)) : ($tOut = substr((string) $p['at'], 11, 5)); }
      $x = [];
      $qa->phoneAutoApproved($id, (string) ($e['employer_company_id'] ?? ''), [], $qaNow) && $qa->phoneApproval((string) ($e['employer_company_id'] ?? '')) === 'required' && $x[] = 'Phone';
      $qa->geofenceExempt($id, (string) ($e['branch_id'] ?? ''), $qaNow) && $x[] = 'Geo-fence'; ?>
    <tr><td><?= e($qaEmpLabel($e)) ?></td><td><?= e($qaCoName[(string) ($e['employer_company_id'] ?? '')] ?? '') ?></td><td><?= e($qaBrName[(string) ($e['branch_id'] ?? '')] ?? '') ?></td>
      <td><span class="badge badge-<?= $ph[0] ?>"><?= e($ph[1]) ?></span></td><td><?= $x === [] ? '<span class="note">—</span>' : implode(' ', array_map(static fn (string $s): string => '<span class="badge badge-blue">' . e($s) . '</span>', $x)) ?></td>
      <td><?= e($tIn ?: '—') ?></td><td><?= e($tOut ?: '—') ?></td><td><?= isset($qaLast[$code]) ? e($qaDmyT($qaLast[$code])) : '<span class="note">never</span>' ?></td>
      <td class="no-export"><?php if (!in_array('approved', $st, true)): // SEND THE APP ACTIVATION CODE from the row (owner, 12 Nov 2026) ?>
        <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="mobile-activation">
          <input type="hidden" name="subject" value="e:<?= e($id) ?>"><input type="hidden" name="back" value="qr-attendance&amp;qp=employees">
          <button class="btn btn-sm btn-outline" type="submit" title="Email this employee the app activation code and sign-in details"><i class="fas fa-key"></i> Send code</button></form>
      <?php else: ?><span class="note">Registered</span><?php endif; ?></td></tr>
<?php if (($qaMaRow['subject'] ?? '') === 'e:' . $id): ?>
    <tr id="ma-e-<?= e((string) preg_replace('/[^a-z0-9]/', '-', strtolower($id))) ?>" class="no-export"><td colspan="9" style="background:<?= $qaMaRow['ok'] ? '#ECFDF5' : '#FEF2F2' ?>;border-left:4px solid <?= $qaMaRow['ok'] ? '#16A34A' : '#DC2626' ?>">
<?php if (($qaMaRow['fields'] ?? []) !== []): ?>
      <div class="qa-ma">
        <button type="button" class="qa-ma-x" title="Close" onclick="this.closest('tr').remove()"><i class="fas fa-xmark"></i></button>
        <div class="qa-ma-h"><i class="fas fa-circle-check"></i> Activation code sent — app sign-in details</div>
        <table><tbody>
<?php   foreach ((array) $qaMaRow['fields'] as $fk => $fv): ?>
          <tr><td><?= e((string) $fk) ?></td><td<?= $fk === 'Activation code' ? ' class="qa-ma-code"' : '' ?>><?= e((string) $fv) ?></td></tr>
<?php   endforeach; ?>
        </tbody></table>
      </div>
<?php else: ?>
      <div class="qa-ma"><button type="button" class="qa-ma-x" title="Close" onclick="this.closest('tr').remove()"><i class="fas fa-xmark"></i></button>
      <i class="fas <?= $qaMaRow['ok'] ? 'fa-circle-check' : 'fa-circle-exclamation' ?>" style="color:<?= $qaMaRow['ok'] ? '#16A34A' : '#DC2626' ?>"></i> <?= e((string) $qaMaRow['text']) ?></div>
<?php endif; ?></td></tr>
<?php endif; ?>
<?php endforeach; if ($qaList === []): ?><tr><td colspan="9" class="note">No employee of a QR company for these filters.</td></tr><?php endif; ?>
  </tbody></table></div></div>

<?php elseif ($qaPage === 'unmatched'):
    $qaSrcF = (string) ($_GET['src'] ?? 'smartept');
    $qaRows = array_values(array_filter($qaUnmapped, static fn (array $u): bool => $qaSrcF === 'all' || (string) ($u['source'] ?? '') === $qaSrcF));
    $qaMapChoices = [];
    foreach ($qaScoped as $e) { $qaMapChoices[(string) $e['id']] = $qaEmpLabel($e); } ?>
<div class="card">
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
<?php foreach (['smartept' => 'From SmartEPT', 'all' => 'All sources'] as $k => $l): ?>
    <a class="qa-pill<?= $qaSrcF === $k ? ' on' : '' ?>" href="?r=qr-attendance&amp;qp=unmatched&amp;src=<?= e($k) ?>"><?= e($l) ?></a>
<?php endforeach; ?>
    <span class="note" style="margin-left:auto">Held, never guessed. Match once — held punches move into attendance.</span>
  </div>
</div>
<div class="card" style="padding:0"><div class="table-wrap"><table>
  <thead><tr><th style="width:14%">Code sent</th><th style="width:10%">Source</th><th style="width:9%">Held</th><th style="width:22%">Held punches</th><th style="width:12%">Last seen</th><th>Match to employee</th></tr></thead><tbody>
<?php foreach ($qaRows as $u): $code = (string) $u['device_code']; $held = $qaHeld[strtolower($code)] ?? [];
      usort($held, static fn (array $a, array $b): int => strcmp((string) ($a['punch_at'] ?? ''), (string) ($b['punch_at'] ?? ''))); $mf = 'qa-m-' . md5($code); ?>
    <tr><td><code><?= e($code) ?></code></td><td><?= e($u['source'] === 'smartept' ? 'SmartEPT' : (string) ($u['source'] ?? '')) ?></td><td><?= count($held) ?></td>
      <td class="note" style="font-size:11.5px"><?= e(implode(', ', array_map(static fn (array $p): string => SmartPRS\Surface\Dates::dmy(substr((string) ($p['punch_at'] ?? ''), 0, 10)) . ' ' . substr((string) ($p['punch_at'] ?? ''), 11, 5) . ' ' . strtoupper((string) ($p['direction'] ?? '')), array_slice($held, -4)))) ?><?= count($held) > 4 ? ' …' : '' ?></td>
      <td class="note"><?= e(($u['last_seen'] ?? '') ? $qaDmyT((string) $u['last_seen']) : '') ?></td>
      <td><form method="post" id="<?= e($mf) ?>" style="display:flex;gap:6px;margin:0"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="biometric"><input type="hidden" name="op" value="map">
        <input type="hidden" name="device_code" value="<?= e($code) ?>"><input type="hidden" name="back" value="qr-attendance&amp;qp=unmatched">
        <div style="flex:1"><?= employeePicker($mf . '-e', 'employee', $qaMapChoices, '', 'Type code or name', true) ?></div>
        <button class="go btn-sm" type="submit"><i class="fas fa-link"></i> Match</button></form></td></tr>
<?php endforeach; if ($qaRows === []): ?><tr><td colspan="6" class="note">Nothing unmatched<?= $qaSrcF === 'smartept' ? ' from SmartEPT' : '' ?> — every punch that arrived belongs to an employee.</td></tr><?php endif; ?>
  </tbody></table></div></div>

<?php elseif ($qaPage === 'phones'):
    $mdParams = $params ?? null; $params = $_GET;
    include __DIR__ . '/../partials/people-mobile-devices-data.php';
    $params = $mdParams;
    $qaCounts = []; foreach ($mdAll as $r) { $qaCounts[(string) $r['status']] = ($qaCounts[(string) $r['status']] ?? 0) + 1; }
    $qaTrans = (array) $mdDeclared['transitions']; ?>
<?php $maBackTo = 'qr-attendance&qp=phones'; include __DIR__ . '/../partials/mobile-activation-form.php'; ?>
<div class="card">
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <a href="?r=qr-attendance&amp;qp=phones" class="qa-pill<?= $mdStatus === '' ? ' on' : '' ?>">All <?= count($mdAll) ?></a>
<?php foreach ($mdStatuses as $s => $meta): ?>
    <a href="?r=qr-attendance&amp;qp=phones&amp;s=<?= e((string) $s) ?>" class="qa-pill<?= $mdStatus === $s ? ' on' : '' ?>"><?= e((string) $meta['label']) ?> <?= (int) ($qaCounts[$s] ?? 0) ?></a>
<?php endforeach; ?>
    <span class="note" style="margin-left:auto">Match the code shown on the phone before approving. Only an approved phone can sign in and scan.</span>
  </div>
</div>
<div class="card" style="padding:0"><div class="table-wrap"><table>
  <thead><tr><th>Emp ID · Name</th><th>Phone</th><th>Match code</th><th>Registered</th><th>Last seen</th><th>Status</th><th style="width:300px"></th></tr></thead><tbody>
<?php foreach ($mdRows as $r): $st = (string) $r['status']; $meta = (array) ($mdStatuses[$st] ?? ['label' => $st, 'badge' => 'gray']); ?>
    <tr><td><b><?= e($mdPeople[(string) ($r['employee_id'] ?? '')] ?? '—') ?></b></td>
      <td><?= e((string) ($r['device_name'] ?? '') ?: 'Mobile device') ?><div class="note" style="font-size:11px"><?= e(implode(' · ', array_filter([(string) ($r['model'] ?? ''), (string) ($r['platform'] ?? ''), (string) ($r['os_version'] ?? '')]))) ?></div></td>
      <td><code><?= e((string) ($r['code'] ?? '') ?: '—') ?></code></td>
      <td><?= trim((string) ($r['created_at'] ?? '')) !== '' ? e($qaDmyT((string) $r['created_at'])) : '—' ?></td>
      <td><?= trim((string) ($r['last_seen_at'] ?? '')) !== '' ? e($qaDmyT((string) $r['last_seen_at'])) : '—' ?></td>
      <td><span class="badge badge-<?= e((string) $meta['badge']) ?>"><?= e((string) $meta['label']) ?></span><?php if (trim((string) ($r['approved_by'] ?? '')) !== ''): ?><div class="note" style="font-size:11px">by <?= e((string) $r['approved_by']) ?></div><?php endif; ?></td>
      <td><div class="qa-acts" style="flex-wrap:wrap">
<?php foreach ($mdDevices->movesFrom($st) as $op): $t = (array) $qaTrans[$op]; ?>
        <form method="post" style="margin:0;display:flex;gap:4px"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="mobile-device">
          <input type="hidden" name="op" value="<?= e($op) ?>"><input type="hidden" name="id" value="<?= e((string) $r['id']) ?>"><input type="hidden" name="back" value="qr-attendance&amp;qp=phones<?= $mdStatus !== '' ? '&amp;s=' . e($mdStatus) : '' ?>">
<?php if (!empty($t['reason_required'])): ?><input name="reason" placeholder="Reason (shown on the phone)" required style="width:170px"><?php endif; ?>
          <button class="btn btn-sm btn-outline" type="submit"><i class="fas <?= ['approve' => 'fa-check', 'reject' => 'fa-xmark', 'revoke' => 'fa-ban'][$op] ?? 'fa-arrow-right' ?>"></i> <?= e((string) $t['label']) ?></button></form>
<?php endforeach; ?>
      </div></td></tr>
<?php endforeach; if ($mdRows === []): ?><tr><td colspan="7" class="note">No phone<?= $mdStatus === '' ? ' has registered yet.' : ' is ' . e((string) $mdStatuses[$mdStatus]['label']) . '.' ?></td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php endif; ?>

<?php if (in_array($qaPage, ['policy', 'gates', 'phones'], true)): ?>
<?php if ($qaPage === 'phones'): ?><h3 style="margin:18px 0 8px">Phone approval exceptions <span class="note" style="font-weight:400;font-size:13px">— these employees' new phones are approved without the Admin (demo / Android review)</span></h3><?php endif; ?>
<?php if ($qaCompanies === []): ?><div class="card note">No companies yet — add one in Organization Configuration → Companies.</div><?php endif; ?>
<?php foreach ($qaCompanies as $qaI => $co): $coId = (string) $co['id']; $src = $qa->sourceFor($coId); $isQr = $src === 'qr';
    $coName = (string) $co['name'];
    $coHooks = array_values(array_filter($qaHooks, static fn (array $w): bool => in_array((string) ($w['employer_company_id'] ?? ''), ['', $coId], true)));
    $coBranches = array_values(array_filter($qaBranches, static fn (array $b): bool => $qaBrCo($b) === $coId));
    $cf = 'qa-co-' . $coId; if ($qaPage === 'gates' && !$isQr) { continue; } ?>
<div class="qa-co" data-qa-co style="--qa:<?= e($qaColours[$qaI % count($qaColours)]) ?>">
  <form method="post" id="<?= e($cf) ?>"><?= $qaHid('company') ?><input type="hidden" name="company_id" value="<?= e($coId) ?>"></form>
  <div class="qa-head"><h3><i class="fas fa-building"></i> <?= e($coName) ?></h3>
    <?php if ($qaPage === 'policy'): ?><button form="<?= e($cf) ?>" class="go" type="submit"><i class="fas fa-floppy-disk"></i> Save settings</button><?php endif; ?></div>
<?php if ($qaPage === 'policy'): ?>

  <div class="table-wrap"><table class="qa-t"><thead><tr><th style="width:34%">Attendance source</th><th style="width:34%">Phone approval</th><th>SmartEPT link</th></tr></thead><tbody><tr>
    <td><select form="<?= e($cf) ?>" name="source" data-qa-src required><?= $src === 'standard' ? '<option value="" selected disabled>Choose the attendance source</option>' : '' ?><?php foreach ($qa->sourceOptions() as $k => $label): if ($k === 'standard') { continue; } // owner, 12 Nov 2026: only SmartEPT punch or QR ?><option value="<?= e($k) ?>"<?= $k === $src ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></td>
    <td><select form="<?= e($cf) ?>" name="phone_approval"><option value="required"<?= $qa->phoneApproval($coId) === 'required' ? ' selected' : '' ?>>Admin approval needed</option>
      <option value="auto"<?= $qa->phoneApproval($coId) === 'auto' ? ' selected' : '' ?>>No approval — whole company (demo company / Android review)</option></select>
      <div class="qa-rng">Only a few people (a demo login)? <a href="?r=qr-attendance&amp;qp=phones">Phones → Phone approval exceptions</a></div></td>
    <td><?php if ($src !== 'smartept'): ?><span class="note">Not used</span>
      <?php elseif ($coHooks === []): ?><span class="badge badge-amber">No SmartEPT webhook</span> <a href="?r=integrations">Create in API Keys &amp; Integrations</a>
      <?php else: foreach ($coHooks as $w): ?><div><span class="badge badge-green">Linked</span> <?= e((string) $w['name']) ?> · last <?= e($qaWhen($w['last_received_at'] ?? null)) ?> · <?= (int) ($w['accepted_count'] ?? 0) ?> accepted</div><?php endforeach; endif; ?></td>
  </tr></tbody></table></div>

  <div data-qa-qr style="<?= $isQr ? '' : 'display:none' ?>">
    <div class="qa-cap">QR rules — <span class="qa-co-name"><?= e($coName) ?></span></div>
    <div class="table-wrap"><table class="qa-t"><thead><tr><?php foreach ($qaSpecs as $k => $spec): ?><th title="<?= e((string) $spec['label']) ?>"><?= e($qaShort($k)) ?></th><?php endforeach; ?></tr></thead><tbody><tr>
<?php foreach ($qaSpecs as $k => $spec): ?>
      <td><input form="<?= e($cf) ?>" type="number" name="s_<?= e($k) ?>" min="<?= (int) $spec['min'] ?>" max="<?= (int) $spec['max'] ?>" step="1" value="<?= $qa->setting($k, $coId) ?>" required title="<?= e((string) $spec['label']) ?>">
        <div class="qa-rng"><?= (int) $spec['min'] ?> – <?= (int) $spec['max'] ?></div></td>
<?php endforeach; ?>
    </tr></tbody></table></div>
  </div>

<?php endif; if ($qaPage === 'phones'): $coEmpChoices = [];
  foreach ($qaEmps as $e) { (string) ($e['employer_company_id'] ?? '') === $coId && $coEmpChoices[(string) $e['id']] = $qaEmpLabel($e); }
  $coPhoneX = array_values(array_filter($qa->exceptions('phone'), static fn (array $x): bool => isset($coEmpChoices[(string) $x['employee_id']]))); ?>
  <?= $qaExceptions('phone', 'Phone approval exceptions — <span class="qa-co-name">' . e($coName) . '</span>', $coPhoneX, $coEmpChoices, 'company_id', $coId) ?>
<?php endif; if ($qaPage === 'gates'): ?>

  <div data-qa-qr style="<?= $isQr ? '' : 'display:none' ?>">
<?php if ($coBranches === []): ?><div class="qa-cap">Gates</div><div class="note" style="margin-bottom:10px">No branches in this company yet — add them in Organization Configuration → Branches.</div><?php endif; ?>
<?php foreach ($coBranches as $b): $bId = (string) $b['id'];
      $bGates = array_values(array_filter($qa->gates(), static fn (array $g): bool => (string) ($g['branch_id'] ?? '') === $bId));
      $bLoc = trim((string) ($b['lat'] ?? '')) !== '' && trim((string) ($b['lng'] ?? '')) !== '' ? $b['lat'] . ', ' . $b['lng'] : '';
      $gf = 'qa-g-' . $bId;
      // LOCATION FROM THE BRANCH (owner, 12 Nov 2026): Latitude / Longitude are filled from the branch's location; a branch with
      // none set is looked up by its name, city and state (OpenStreetMap) in the browser. Saved equal to the branch = follows the branch.
      $bTrim = static fn (mixed $v): string => rtrim(rtrim(trim((string) $v), '0'), '.');
      $bLat = $bTrim($b['lat'] ?? ''); $bLng = $bTrim($b['lng'] ?? '');
      $bGeo = $bLat === '' || $bLng === '' ? implode(', ', array_filter([trim((string) $b['name']), trim((string) ($b['city'] ?? '')), trim((string) ($b['state'] ?? '')), 'India'])) : ''; ?>
    <div class="qa-br">
      <div class="qa-cap"><span class="qa-co-name"><?= e($coName) ?></span> · <i class="fas fa-location-dot"></i> <span class="qa-co-name"><?= e((string) $b['name']) ?></span>
        <span class="note" style="margin-left:auto;text-transform:none;letter-spacing:0;font-weight:400"><?= $bLoc !== '' ? 'Branch location ' . e($bLoc) : '<span class="badge badge-amber">Branch location not set</span>' ?></span>
        <button type="button" class="go btn-sm qa-add" style="margin-left:10px" data-qa-add="<?= e($gf) ?>-row"><i class="fas fa-plus"></i> Add gate</button></div>
      <div class="table-wrap"><table class="qa-t"><thead><tr>
        <th style="width:48px">Code</th><th>Gate</th><th style="width:84px">Latitude</th><th style="width:84px">Longitude</th>
<?php   foreach (['radius_m', ...SmartPRS\Surface\QrAttendance::GATE_KEYS] as $gk): ?><th style="width:60px" title="<?= e((string) $qaSpecs[$gk]['label']) ?>"><?= e($qaShort($gk)) ?></th><?php endforeach; ?>
        <th style="width:64px">Status</th><th style="width:272px">Scanner link / actions</th></tr></thead><tbody>
<?php   foreach ($bGates as $g): $on = (bool) ($g['active'] ?? true); $code = (string) $g['code'];
          $gUrl = $qaBase . '/qr.php?' . http_build_query(['t' => $tenantId, 'g' => $code, 'k' => $g['secret']]);
          if ($code === $qaEditCode): $ef = 'qa-e-' . $code; ?>
        <tr class="qa-new" id="qa-edit"><td><b><?= e($code) ?></b><form method="post" id="<?= e($ef) ?>"><?= $qaHid('edit-gate', $code) ?><input type="hidden" name="branch_id" value="<?= e($bId) ?>"></form></td>
          <td><input form="<?= e($ef) ?>" name="name" required maxlength="60" value="<?= e((string) $g['name']) ?>"></td>
          <td><input form="<?= e($ef) ?>" name="lat" inputmode="decimal" value="<?= e(trim((string) ($g['lat'] ?? '')) !== '' ? (string) $g['lat'] : $bLat) ?>" data-qa-geo="<?= e($bGeo) ?>"></td>
          <td><input form="<?= e($ef) ?>" name="lng" inputmode="decimal" value="<?= e(trim((string) ($g['lng'] ?? '')) !== '' ? (string) $g['lng'] : $bLng) ?>"></td>
          <td><input form="<?= e($ef) ?>" type="number" name="radius_m" min="<?= (int) $qaSpecs['radius_m']['min'] ?>" max="<?= (int) $qaSpecs['radius_m']['max'] ?>" value="<?= e((string) ($g['radius_m'] ?? '')) ?>" placeholder="<?= $qa->setting('radius_m', $coId) ?>"></td>
<?php       foreach (SmartPRS\Surface\QrAttendance::GATE_KEYS as $gk): ?>
          <td><input form="<?= e($ef) ?>" type="number" name="g_<?= e($gk) ?>" min="<?= (int) $qaSpecs[$gk]['min'] ?>" max="<?= (int) $qaSpecs[$gk]['max'] ?>" value="<?= e((string) ($g[$gk] ?? '')) ?>" placeholder="<?= $qa->setting($gk, $coId) ?>" title="Blank = company rule"></td>
<?php       endforeach; ?>
          <td><span class="badge badge-<?= $on ? 'green' : 'gray' ?>"><?= $on ? 'Active' : 'Off' ?></span></td>
          <td><div class="qa-acts"><button form="<?= e($ef) ?>" class="go btn-sm" type="submit"><i class="fas fa-floppy-disk"></i> Save</button><a class="btn btn-sm btn-outline" href="?r=qr-attendance&amp;qp=gates">Cancel</a></div></td></tr>
<?php     else: ?>
        <tr><td><b><?= e($code) ?></b></td><td><?= e((string) $g['name']) ?></td>
          <td><?= e(trim((string) ($g['lat'] ?? '')) !== '' ? (string) $g['lat'] : $bLat) ?></td>
          <td><?= e(trim((string) ($g['lng'] ?? '')) !== '' ? (string) $g['lng'] : $bLng) ?></td>
          <td<?= ($g['radius_m'] ?? '') !== '' && $g['radius_m'] !== null ? ' style="font-weight:700"' : '' ?>><?= (int) (($g['radius_m'] ?? 0) ?: $qa->setting('radius_m', $coId)) ?></td>
<?php       foreach (SmartPRS\Surface\QrAttendance::GATE_KEYS as $gk): $own = ($g[$gk] ?? '') !== ''; ?>
          <td<?= $own ? ' style="font-weight:700" title="Set on this gate"' : ' class="note" title="Company rule"' ?>><?= $qa->gateSetting($g, $gk, $coId) ?></td>
<?php       endforeach; ?>
          <td><span class="badge badge-<?= $on ? 'green' : 'gray' ?>"><?= $on ? 'Active' : 'Off' ?></span></td>
          <td><div class="qa-acts">
            <a class="btn btn-sm btn-outline" href="<?= e($gUrl) ?>" target="_blank" rel="noopener" title="Open the scanner screen in a new window" onclick="window.open(this.href, 'qa-gate-<?= e($code) ?>', 'popup,width=1280,height=800'); return false;"><i class="fas fa-up-right-from-square"></i> Open</a>
            <button class="btn btn-sm btn-outline" type="button" data-qa-copy="<?= e($gUrl) ?>" title="Copy the scanner link"><i class="fas fa-copy"></i> Copy</button>
            <a class="btn btn-sm btn-outline" href="?r=qr-attendance&amp;qp=gates&amp;edit=<?= e($code) ?>#qa-edit" title="Edit"><i class="fas fa-pen"></i></a>
            <form method="post" style="margin:0"><?= $qaHid('reset-gate', $code) ?><button class="btn btn-sm btn-outline" type="submit" title="New scanner link — the old one stops" onclick="return confirm('New scanner link? The gate screen using the old link stops at once.')"><i class="fas fa-rotate"></i></button></form>
            <form method="post" style="margin:0"><?= $qaHid('toggle-gate', $code) ?><button class="btn btn-sm btn-outline" type="submit" title="<?= $on ? 'Switch off' : 'Switch on' ?>"><i class="fas fa-power-off"></i></button></form>
          </div></td></tr>
<?php     endif; ?>
<?php   endforeach; ?>
<?php   if ($bGates === []): // A BRANCH WITH NO GATE YET (owner, 12 Nov 2026): its location still shows, from Branches. ?>
        <tr><td class="note">—</td><td class="note">No gate yet — <a href="#" data-qa-add="<?= e($gf) ?>-row" onclick="return false">add one</a></td>
          <td><?= $bLat !== '' ? e($bLat) : '<span class="badge badge-amber">not set</span>' ?></td><td><?= $bLng !== '' ? e($bLng) : '<span class="badge badge-amber">not set</span>' ?></td>
          <td class="note"><?= $qa->setting('radius_m', $coId) ?></td>
<?php       foreach (SmartPRS\Surface\QrAttendance::GATE_KEYS as $gk): ?><td class="note"><?= $qa->setting($gk, $coId) ?></td><?php endforeach; ?>
          <td></td><td></td></tr>
<?php   endif; ?>
        <tr class="qa-new" id="<?= e($gf) ?>-row" hidden><td class="note">New<form method="post" id="<?= e($gf) ?>"><?= $qaHid('add-gate') ?><input type="hidden" name="branch_id" value="<?= e($bId) ?>"></form></td>
          <td><input form="<?= e($gf) ?>" name="name" required maxlength="60" placeholder="Gate name"></td>
          <td><input form="<?= e($gf) ?>" name="lat" inputmode="decimal" value="<?= e($bLat) ?>" data-qa-geo="<?= e($bGeo) ?>"></td>
          <td><input form="<?= e($gf) ?>" name="lng" inputmode="decimal" value="<?= e($bLng) ?>"></td>
          <td><input form="<?= e($gf) ?>" type="number" name="radius_m" min="<?= (int) $qaSpecs['radius_m']['min'] ?>" max="<?= (int) $qaSpecs['radius_m']['max'] ?>" placeholder="<?= $qa->setting('radius_m', $coId) ?>"></td>
<?php   foreach (SmartPRS\Surface\QrAttendance::GATE_KEYS as $gk): ?>
          <td><input form="<?= e($gf) ?>" type="number" name="g_<?= e($gk) ?>" min="<?= (int) $qaSpecs[$gk]['min'] ?>" max="<?= (int) $qaSpecs[$gk]['max'] ?>" placeholder="<?= $qa->setting($gk, $coId) ?>" title="Blank = company rule"></td>
<?php   endforeach; ?>
          <td></td><td><div class="qa-acts"><button form="<?= e($gf) ?>" class="go btn-sm" type="submit"><i class="fas fa-floppy-disk"></i> Save</button><button class="btn btn-sm btn-outline" type="button" data-qa-add="<?= e($gf) ?>-row">Cancel</button></div></td></tr>
      </tbody></table></div>
      <div class="note" style="font-size:11px;margin-top:4px">Grey values and blank boxes follow the company rule; bold = set on the gate. Latitude / Longitude come from the branch location.</div>
<?php   $bEmpChoices = [];
        foreach ($qaEmps as $e) {
            ((string) ($e['branch_id'] ?? '') === $bId || in_array($bId, branchAdminBranches((string) ($e['admin_branches'] ?? '')), true)) && $bEmpChoices[(string) $e['id']] = $qaEmpLabel($e);
        }
        $bGeoX = array_values(array_filter($qa->exceptions('geofence'), static fn (array $x): bool => (string) ($x['branch_id'] ?? '') === $bId)); ?>
      <?= $qaExceptions('geofence', 'Geo-fence exceptions — <span class="qa-co-name">' . e((string) $b['name']) . '</span>', $bGeoX, $bEmpChoices, 'branch_id', $bId) ?>
    </div>
<?php endforeach; ?>
  </div>
<?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>
<script>
// Applies: Date range opens From / To; + Add opens the hidden add row; Copy copies a scanner link; the source picked shows its parts.
document.querySelectorAll('[data-qa-when]').forEach(function (sel) {
  var row = sel.closest('tr'), sync = function () { row.querySelectorAll('[data-qa-range]').forEach(function (i) { i.disabled = sel.value !== 'range'; i.required = sel.value === 'range'; if (i.disabled) { i.value = ''; } }); };
  sel.addEventListener('change', sync); sync();
});
document.querySelectorAll('[data-qa-add]').forEach(function (b) {
  b.addEventListener('click', function () {
    var r = document.getElementById(b.dataset.qaAdd); r.hidden = !r.hidden;
    if (!r.hidden) { var i = r.querySelector('input:not([type=hidden]):not([readonly])'); i && i.focus(); }
  });
});
// A branch with no location set: look it up once by its name, city and state, and fill the gate's Latitude / Longitude.
document.querySelectorAll('[data-qa-geo]').forEach(function (lat) {
  var q = lat.dataset.qaGeo, lng = lat.closest('tr').querySelector('input[name=lng]');
  if (!q || lat.value !== '') { return; }
  fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(q)).then(function (r) { return r.json(); }).then(function (d) {
    if (d && d[0] && lat.value === '') { lat.value = (+d[0].lat).toFixed(6); lng.value = (+d[0].lon).toFixed(6); lat.title = lng.title = 'Found for ' + q; }
  }).catch(function () { lat.placeholder = 'enter'; });
});
document.querySelectorAll('[data-qa-copy]').forEach(function (b) {
  b.addEventListener('click', function () {
    var done = function () { var t = b.innerHTML; b.innerHTML = '<i class="fas fa-check"></i> Copied'; setTimeout(function () { b.innerHTML = t; }, 1500); };
    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(b.dataset.qaCopy).then(done); return; }
    var ta = document.createElement('textarea'); ta.value = b.dataset.qaCopy; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); done();
  });
});
document.querySelectorAll('[data-qa-co]').forEach(function (box) {
  var sel = box.querySelector('[data-qa-src]'); if (!sel) { return; }
  sel.addEventListener('change', function () { box.querySelectorAll('[data-qa-qr]').forEach(function (el) { el.style.display = sel.value === 'qr' ? '' : 'none'; }); });
});
function qaXls(id, name) { // the table on screen, same rows — handed back as a formatted Excel file (action csv-xlsx)
  var t = document.getElementById(id); if (!t) { return; }
  var csv = [].filter.call(t.rows, function (r) { return !r.classList.contains('no-export'); }).map(function (r) { return [].filter.call(r.cells, function (c) { return !c.classList.contains('no-export'); }).map(function (c) { return '"' + c.innerText.trim().replace(/"/g, '""') + '"'; }).join(','); }).join('\r\n');
  var f = document.createElement('form'); f.method = 'post'; f.action = '?r=qr-attendance';
  [['csrf', <?= json_encode((string) $session->token()) ?>], ['action', 'csv-xlsx'], ['name', name], ['csv', csv]].forEach(function (kv) { var i = document.createElement('input'); i.type = 'hidden'; i.name = kv[0]; i.value = kv[1]; f.appendChild(i); });
  document.body.appendChild(f); f.submit(); f.remove();
}
</script>
