<?php
/**
 * THE QR ATTENDANCE GATE SCREEN (owner, 9 Nov 2026) — SmartEPT's QR display page, for SmartPRS3.
 *
 *   GET /qr.php?t=<tenant>&g=<gate code>&k=<display key>            the full-screen page for a tablet / TV at the gate
 *   GET /qr.php?t=<tenant>&g=<gate code>&k=<display key>&token=1    {qr, window, expires_in, interval, server_time}
 *
 * No sign-in: the long display key in the link IS the credential (Time & Attendance → QR Attendance shows the link;
 * "Reset link" makes a new key and the old screen stops). The code changes every few seconds and means nothing
 * without an approved phone, the employee's own sign-in, their branch and their GPS (surface/QrAttendance.php).
 * Clock and date are the gate's company time zone (Organization Configuration → Companies → Time zone).
 */
declare(strict_types=1);

require __DIR__ . '/../surface/partials/endpoint-boot.php';

use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\QrAttendance;

$tenantId = preg_match('/^\d{1,12}$/', (string) ($_GET['t'] ?? '')) === 1 ? (string) $_GET['t'] : '';
$wantsToken = isset($_GET['token']);
$stop = static function (string $why) use ($wantsToken): never {
    if ($wantsToken) {
        json_out(403, ['ok' => false, 'error' => $why]);
    }
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>QR Attendance</title>'
        . '<body style="font-family:system-ui;background:#1F3A5F;color:#fff;display:grid;place-items:center;height:100vh;margin:0;text-align:center"><p style="font-size:1.4rem;max-width:80vw">'
        . htmlspecialchars($why) . '</p></body>';
    exit;
};

$store = $tenantId === '' ? null : $platform->forTenant($tenantId);
$qa = $store === null ? null : QrAttendance::load(new Customisation($store), $root);
$gate = $qa?->gate((string) ($_GET['g'] ?? ''));

if ($gate === null || !hash_equals((string) ($gate['secret'] ?? ''), (string) ($_GET['k'] ?? ''))) {
    $stop('This gate screen link is not valid, or it was reset. Open the new link from Time & Attendance → QR Attendance.');
}

$live = static fn (array $rows): array => array_values(array_filter($rows, static fn (array $r): bool => trim((string) ($r['deleted_at'] ?? '')) === ''));
$branch = null;
foreach ($live($store->all('branches')) as $b) {
    (string) $b['id'] === (string) ($gate['branch_id'] ?? '') && $branch = $b;
}
$company = null;
foreach ($live($store->all('companies')) as $c) {
    (string) $c['id'] === (string) ($branch['employer_company_id'] ?? $branch['company_id'] ?? '') && $company = $c;
}
$tz = trim((string) ($company['timezone'] ?? ''));
in_array($tz, timezone_identifiers_list(), true) && date_default_timezone_set($tz);

if ($wantsToken) {
    if (!(bool) ($gate['active'] ?? true)) {
        json_out(200, ['ok' => true, 'active' => false, 'qr' => null, 'expires_in' => 5, 'interval' => 5, 'server_time' => date(DATE_ATOM)]);
    }

    json_out(200, ['ok' => true, 'active' => true] + $qa->token($gate, null, (string) ($company['id'] ?? '')) + ['server_time' => date(DATE_ATOM)]);
}

$h = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES);
$tokenUrl = '?' . http_build_query(['t' => $tenantId, 'g' => $gate['code'], 'k' => $gate['secret'], 'token' => 1]);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $h($gate['name']) ?> · <?= $h($company['name'] ?? '') ?></title>
<script src="js/qrcode.min.js"></script>
<style>
  :root{--navy:#1F3A5F;--gold:#B8893B}
  *{box-sizing:border-box}html,body{margin:0;height:100%}
  body{background:var(--navy);color:#EAF0F7;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2vh;padding:2vh 16px;text-align:center}
  .co{background:#fff;color:var(--navy);border-radius:12px;padding:8px 18px;font-weight:700;font-size:clamp(1.1rem,3vh,1.8rem)}
  h1{margin:0;font-size:clamp(1.2rem,3.4vh,2.4rem)}.sub{color:#C9D5E4;font-size:clamp(.9rem,2.2vh,1.4rem)}
  #qr{background:#fff;padding:clamp(12px,2.5vh,28px);border-radius:20px;width:min(64vh,86vw);aspect-ratio:1;display:flex;align-items:center;justify-content:center}
  #qr img,#qr canvas{width:100%!important;height:100%!important;image-rendering:pixelated}
  .bar{width:min(64vh,86vw);height:8px;flex-shrink:0;background:#2D527F;border-radius:4px;overflow:hidden}.bar i{display:block;height:100%;background:var(--gold);transform-origin:left}
  .row{display:flex;gap:24px;justify-content:center;flex-wrap:wrap;font-size:clamp(.9rem,2.2vh,1.4rem)}
  .clock{font-variant-numeric:tabular-nums;font-size:clamp(1.4rem,4.5vh,3rem);font-weight:700}
  .off{color:#FCA5A5}.msg{font-size:clamp(1rem,3vh,2rem);color:var(--navy)}
</style>
</head>
<body>
  <div class="co"><?= $h($company['name'] ?? 'SmartPRS3') ?></div>
  <div><h1><?= /* the gate's name already starts with its branch ("EON IT Park Main Entrance", 12 Nov 2026): no second branch name */
    $h(stripos((string) $gate['name'], trim((string) ($branch['name'] ?? ''))) === 0 || trim((string) ($branch['name'] ?? '')) === '' ? (string) $gate['name'] : ($branch['name'] . ' · ' . $gate['name'])) ?></h1><div class="sub">Open the SmartPRS3 app → Punch → Scan QR</div></div>
  <div id="qr" role="img" aria-label="Attendance QR code"></div>
  <div class="bar"><i id="bar"></i></div>
  <div class="row"><span class="clock" id="clock">--:--:--</span></div>
  <div class="row"><span id="date"></span><span id="net">Connecting…</span></div>
<script>
var TOKEN_URL = <?= json_encode($tokenUrl) ?>, TZ = <?= json_encode(date_default_timezone_get()) ?>;
var box = document.getElementById('qr'), bar = document.getElementById('bar'), net = document.getElementById('net');
var qr = null, expiresAt = 0, interval = 3, skew = 0, timer = null;
function render(text) {
  box.innerHTML = '';
  if (!text) { box.innerHTML = '<div class="msg">This gate is switched off</div>'; return; }
  new QRCode(box, { text: text, width: 1024, height: 1024, correctLevel: QRCode.CorrectLevel.M });
}
function refresh() {
  clearTimeout(timer);
  fetch(TOKEN_URL, { headers: { Accept: 'application/json' }, cache: 'no-store' }).then(function (r) {
    if (r.status === 403) { box.innerHTML = '<div class="msg">This screen link was reset. Open the new link from Time &amp; Attendance → QR Attendance.</div>'; net.textContent = 'Stopped'; net.className = 'off'; return null; }
    if (!r.ok) { throw new Error(r.status); }
    return r.json();
  }).then(function (d) {
    if (d === null) { return; }
    skew = Date.parse(d.server_time) - Date.now(); interval = d.interval; expiresAt = Date.now() + d.expires_in * 1000;
    if (d.qr !== qr) { qr = d.qr; render(qr); }
    net.textContent = '● Online'; net.className = '';
    timer = setTimeout(refresh, Math.max(250, d.expires_in * 1000 + 150));
  }).catch(function () {
    // Offline: hide the code so a stale one is never scanned, and retry.
    qr = null; box.innerHTML = '<div class="msg">Reconnecting…</div>'; net.textContent = 'Offline — codes paused'; net.className = 'off';
    timer = setTimeout(refresh, 2000);
  });
}
setInterval(function () {
  var left = Math.max(0, expiresAt - Date.now());
  bar.style.transform = 'scaleX(' + Math.min(1, left / (interval * 1000)) + ')';
  var now = new Date(Date.now() + skew);
  document.getElementById('clock').textContent = now.toLocaleTimeString('en-IN', { timeZone: TZ, hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true }).toUpperCase();
  var p = new Intl.DateTimeFormat('en-GB', { timeZone: TZ, weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric' }).formatToParts(now).reduce(function (a, x) { a[x.type] = x.value; return a; }, {});
  document.getElementById('date').textContent = p.weekday + ', ' + p.day + '-' + p.month + '-' + p.year;
}, 200);
refresh();
if (navigator.wakeLock) { navigator.wakeLock.request('screen').catch(function () {}); }
document.addEventListener('visibilitychange', function () { if (!document.hidden) { if (navigator.wakeLock) { navigator.wakeLock.request('screen').catch(function () {}); } refresh(); } });
</script>
</body>
</html>
