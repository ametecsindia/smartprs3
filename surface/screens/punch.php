<?php declare(strict_types=1); if (!$may('ess.attendance.punch')): ?>
<div class="page-header"><div><h2>Punch In / Out</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'ess.attendance.punch')->summary) ?></div>
  <p class="note" style="margin-top:10px">Punching from the web app needs <code>ess.attendance.punch</code>. Device and file punches still land under Attendance.</p></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's "Punch In / Out" on Daily Attendance: the signed-in person's
// own employee, today's state, one button. The direction is decided by the
// action from the open punches, so the button only says which it will be.
$ownId = $scope->own();
$ownRow = $ownId === null ? null : $store->find('employees', $ownId);
$code = $ownRow === null ? '' : SmartPRS\Surface\Employment::code($ownRow);
$today = $code === '' ? null : $attendanceReport->day($code, date('Y-m-d'), $calendar->shiftStart(), $calendar->shiftEnd());
// THE NEXT PUNCH AND THE STATUS follow the LAST punch today (owner, 13 Nov 2026) — the same rule the punch action uses.
$todayRaw = $code === '' ? [] : array_values(array_filter($attendance->punchesFor($code), static fn (array $p): bool => substr((string) $p['at'], 0, 10) === date('Y-m-d')));
$lastPunch = $todayRaw === [] ? null : end($todayRaw);
$next = $lastPunch !== null && (string) $lastPunch['direction'] === 'in' ? 'out' : 'in';
$fence = $ownRow === null ? null : (new SmartPRS\Surface\GeoFence($store))->fenceFor($ownRow);
// THE COMPANY'S ATTENDANCE SOURCE (owner, 9 Nov 2026) — Time & Attendance → QR Attendance.
$qrSource = $ownRow === null ? 'standard' : SmartPRS\Surface\QrAttendance::load($customisation, __DIR__ . '/../..')->modeFor($ownRow); // Office staff: QR by default (12 Nov 2026)
$inApp = ($_COOKIE['sprs_app_device'] ?? '') !== '';
?>
<style>
  /* THE PUNCH SCREEN IN THE APP (owner, 12 Nov 2026): one full-width punch button, the camera full width, today's figures as tiles. */
  body.in-app #qr-punch, body.in-app #self-punch { display:block; }
  body.in-app #self-punch > div { flex:none !important; }
  body.in-app .punch-main { display:flex; width:100%; justify-content:center; align-items:center; gap:10px; min-height:58px !important; font-size:17px !important; font-weight:700; border-radius:14px !important; color:#fff; margin:6px 0 4px; }
  body.in-app #qr-cam { max-width:none !important; }
  body.in-app #qr-photo { display:none; width:100%; justify-content:center; }
  body.in-app .punch-stats { display:grid !important; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; }
  body.in-app .punch-stats > div { background:var(--bg,#f8fafc); border:1px solid var(--border,#e2e8f0); border-radius:12px; padding:10px 12px; }
  body.in-app .punch-stats b { display:block; font-size:12px !important; color:var(--text3,#64748b); font-weight:600; margin-bottom:2px; }
  body.in-app .punch-stats br { display:none; }
  /* CURRENT STATUS (owner, 13 Nov 2026): punched IN / OUT / not yet, at a glance */
  .punch-status { display:flex; align-items:center; gap:10px; padding:12px 14px; border-radius:12px; font-weight:700; font-size:15px; margin:4px 0 12px; }
  .punch-status i { font-size:20px; }
  .punch-status-in { background:#dcfce7; color:#166534; }
  .punch-status-out { background:#fee2e2; color:#991b1b; }
  .punch-status-none { background:#f1f5f9; color:#475569; }
  body.in-app .punch-status, body.in-app .punch-status span { font-size:15px !important; }
</style>
<div class="page-header">
  <div><h2>Punch In / Out</h2><p><?= e((new DateTimeImmutable('today'))->format('l, d-m-Y')) ?> &middot; from this web app; device and mobile punches land in the same register</p></div>
  <a class="btn btn-outline" href="?r=today"><i class="fas fa-calendar-day"></i> Today</a>
</div>
<?php if ($ownRow === null): ?>
<div class="card"><div class="problem" style="margin:0">This account is not bound to an employee, so there is nobody to punch for. An administrator binds accounts on the Accounts screen.</div></div>
<?php else: ?>
<div class="card">
  <h2><?= e($code) ?> <span class="note"><?= e((string) ($ownRow['name'] ?? '')) ?></span></h2>
  <div class="punch-status punch-status-<?= $lastPunch === null ? 'none' : (string) $lastPunch['direction'] ?>"><i class="fas <?= $lastPunch === null ? 'fa-circle-minus' : ((string) $lastPunch['direction'] === 'in' ? 'fa-circle-check' : 'fa-circle-stop') ?>"></i>
    <span><?= $lastPunch === null ? 'Not punched in yet today' : ((string) $lastPunch['direction'] === 'in' ? 'Punched IN' : 'Punched OUT') . ' at ' . e(substr((string) $lastPunch['at'], 11, 5)) ?></span></div>
<?php if ($qrSource === 'smartept'): ?>
  <div class="note" style="font-size:14px"><i class="fas fa-desktop"></i> Your company records attendance through <b>SmartEPT</b>. Punch in SmartEPT; the punch reaches SmartPRS3 by itself and shows here.</div>
<?php elseif ($qrSource === 'qr' && !$inApp): ?>
  <div class="note" style="font-size:14px"><i class="fas fa-qrcode"></i> Your company uses <b>QR Attendance</b>. Open the SmartPRS3 app on your approved phone → Punch → <b>Scan QR</b> at your office gate.</div>
<?php elseif ($qrSource === 'qr'): ?>
  <form method="post" id="qr-punch">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="self-punch">
    <input type="hidden" name="qr" value=""><input type="hidden" name="lat" value=""><input type="hidden" name="lng" value=""><input type="hidden" name="accuracy" value="">
    <button class="go punch-main" type="button" id="qr-start" style="background:var(--<?= $next === 'in' ? 'green' : 'red' ?>)"><i class="fas fa-qrcode"></i> Scan QR to punch <?= strtoupper($next) ?></button>
    <div id="qr-cam" style="display:none;margin-top:12px;max-width:420px">
      <video id="qr-video" playsinline muted style="width:100%;border-radius:14px;background:#000;aspect-ratio:1;object-fit:cover"></video>
      <div class="note" id="qr-msg" style="margin-top:6px">Point the camera at the QR on the gate screen.</div>
    </div>
    <label class="btn btn-outline" id="qr-photo" style="display:none;margin-top:10px"><i class="fas fa-camera"></i> Take a photo of the QR<input type="file" accept="image/*" capture="environment" style="display:none"></label>
    <div class="note" id="qr-gps" style="margin-top:8px">Location is needed for a QR punch — allow it when asked.</div>
  </form>
  <script src="js/jsQR.js"></script>
  <script>
  // SCAN THE GATE QR (owner, 9 Nov 2026, as in SmartEPT): GPS starts at once so the code is sent the moment it is read
  // (it lives only a few seconds); live camera where the page may use it, else a photo of the code.
  (function () {
    var f = document.getElementById('qr-punch'), video = document.getElementById('qr-video'), msg = document.getElementById('qr-msg'), gps = document.getElementById('qr-gps');
    var cv = document.createElement('canvas'), cx = cv.getContext('2d', { willReadFrequently: true }), stream = null, sent = false;
    if (navigator.geolocation) {
      navigator.geolocation.watchPosition(function (p) {
        f.lat.value = p.coords.latitude; f.lng.value = p.coords.longitude; f.accuracy.value = Math.round(p.coords.accuracy);
        gps.textContent = 'Location ready (±' + Math.round(p.coords.accuracy) + ' m).';
      }, function () { gps.textContent = 'Location is off — allow location for SmartPRS3, or the QR punch is refused.'; }, { enableHighAccuracy: true, maximumAge: 5000 });
    }
    var read = function (src, w, h) {
      cv.width = w; cv.height = h; cx.drawImage(src, 0, 0, w, h);
      var c = jsQR(cx.getImageData(0, 0, w, h).data, w, h, { inversionAttempts: 'dontInvert' });
      return c && /^PRSQ1\./.test(c.data) ? c.data : null;
    };
    var send = function (code) {
      if (sent) { return; } sent = true;
      if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); }
      msg.textContent = 'QR read — punching…'; f.qr.value = code; f.submit();
    };
    var photo = document.getElementById('qr-photo');
    photo.querySelector('input').addEventListener('change', function () {
      var file = this.files && this.files[0]; if (!file) { return; }
      var img = new Image(); img.onload = function () {
        var k = Math.min(1, 1280 / Math.max(img.width, img.height)), code = read(img, Math.round(img.width * k), Math.round(img.height * k));
        code ? send(code) : (gps.textContent = 'No SmartPRS QR found in the photo — take it again, closer and straight on.');
      }; img.src = URL.createObjectURL(file);
    });
    document.getElementById('qr-start').addEventListener('click', function () {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { photo.style.display = 'inline-flex'; return; }
      navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then(function (s) {
        stream = s; video.srcObject = s; document.getElementById('qr-cam').style.display = 'block'; video.play();
        var tick = function () {
          if (sent) { return; }
          if (video.readyState >= 2) { var code = read(video, 480, Math.round(480 * video.videoHeight / (video.videoWidth || 480))); if (code) { send(code); return; } }
          requestAnimationFrame(tick);
        };
        tick();
      }).catch(function () { photo.style.display = 'inline-flex'; gps.textContent = 'The camera could not open here — take a photo of the QR instead.'; });
    });
    // FROM HOME'S "Scan QR" CARD (owner, 12 Nov 2026): the camera opens at once.
    if (/[?&]scan=1/.test(location.search)) { document.getElementById('qr-start').click(); }
  })();
  </script>
<?php else: ?>
  <form method="post" id="self-punch" class="row" style="align-items:center">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="self-punch">
    <input type="hidden" name="lat" value=""><input type="hidden" name="lng" value="">
    <div style="flex:0"><button class="go punch-main" type="submit" style="background:var(--<?= $next === 'in' ? 'green' : 'red' ?>)"><i class="fas fa-fingerprint"></i> Punch <?= strtoupper($next) ?></button></div>
    <div class="note" id="punch-gps"><?= $fence === null
        ? 'Your location is asked for when you press the button and kept with the punch. No geo-fence is set for you.'
        : '<b>Geo-fence on:</b> punch-in is allowed only within ' . e(rtrim(rtrim(number_format($fence['radius_km'] + $fence['tolerance_km'], 1, '.', ''), '0'), '.')) . ' km of ' . e($fence['label']) . '. Allow location when the browser asks.' ?></div>
  </form>
<?php endif; ?>
  <div class="row punch-stats" style="margin-top:14px">
<?php $pIns = array_values(array_filter($todayRaw, static fn (array $p): bool => (string) $p['direction'] === 'in'));
      $pOuts = array_values(array_filter($todayRaw, static fn (array $p): bool => (string) $p['direction'] === 'out')); ?>
    <div><b>First in</b><br><?= e($pIns === [] ? '—' : substr((string) $pIns[0]['at'], 11, 5)) ?></div>
    <div><b>Last out</b><br><?= e($pOuts === [] ? '—' : substr((string) end($pOuts)['at'], 11, 5)) ?></div>
    <div><b>Worked so far</b><br><?= e(SmartPRS\Surface\AttendanceReport::hm((int) $today['worked_minutes'])) ?></div>
    <div><b>Punches</b><br><?= count($todayRaw) ?></div>
  </div>
</div>
<?php // MY ATTENDANCE ON THE PUNCH TAB (owner, 13 Nov 2026): the last 7 days — present, late, approved leave, W.OFF,
      // holiday — from the same engine as the Attendance Report and payroll, with the full log one tap away.
if ($ownRow !== null):
    $paFrom = date('Y-m-d', strtotime('-6 days'));
    $paCells = (array) (($attendanceReport->muster([$ownRow], $paFrom, date('Y-m-d'), $calendar->shiftStart(), $calendar->shiftEnd(), date('Y-m-d'))['rows'][0] ?? [])['cells'] ?? []);
    $paLabel = ['PRESENT' => ['Present', 'green'], 'ABSENT' => ['Absent', 'red'], 'HALF_DAY' => ['Half day', 'amber'], 'WEEKLY_OFF' => ['W.OFF', 'gray'],
        'HOLIDAY' => ['Holiday', 'blue'], 'ON_LEAVE' => ['Leave', 'blue'], 'UNPAID_LEAVE' => ['Unpaid leave', 'amber'], 'NOT_YET' => ['Today', 'gray'], 'NOT_JOINED' => ['—', 'gray']]; ?>
<div class="card" style="margin-top:12px">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px"><b style="color:#1F3A5F">My attendance — last 7 days</b>
    <a class="btn btn-sm btn-outline" href="?r=my-attendance"><i class="fas fa-calendar-check"></i> Full log</a></div>
<?php foreach (array_reverse($paCells, true) as $paD => $paC): $paL = $paLabel[(string) ($paC['status'] ?? '')] ?? [ucwords(strtolower(str_replace('_', ' ', (string) ($paC['status'] ?? '')))), 'gray']; ?>
  <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:7px 0;border-top:1px solid var(--border,#e2e8f0);font-size:13px">
    <span><b><?= e(SmartPRS\Surface\Dates::dmy((string) $paD)) ?></b> <span class="note"><?= e(date('D', (int) strtotime((string) $paD))) ?></span></span>
    <span class="note"><?= e(((string) ($paC['first_in'] ?? '') ?: '—') . ' – ' . ((string) ($paC['last_out'] ?? '') ?: '—')) ?><?= (int) ($paC['late_minutes'] ?? 0) > 0 ? ' · late ' . e(SmartPRS\Surface\AttendanceReport::hm((int) $paC['late_minutes'])) : '' ?></span>
    <span class="badge badge-<?= e($paL[1]) ?>"><?= e($paL[0]) ?></span>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<script>
// Ask the browser for a position once, then submit; denied or slow = submit without.
(function () {
  var f = document.getElementById('self-punch'); if (!f) { return; }
  var sent = false;
  f.addEventListener('submit', function (ev) {
    if (sent || !navigator.geolocation) { return; }
    ev.preventDefault(); sent = true;
    var go = function (lat, lng) { f.lat.value = lat == null ? '' : lat; f.lng.value = lng == null ? '' : lng; f.submit(); };
    navigator.geolocation.getCurrentPosition(function (p) { go(p.coords.latitude, p.coords.longitude); }, function () { go(null, null); }, { enableHighAccuracy: true, timeout: 8000 });
  });
})();
</script>
<?php endif; ?>
