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
$next = $today === null || array_filter($today['unpaired'], static fn (array $p): bool => $p['direction'] === 'in') === [] ? 'in' : 'out';
$fence = $ownRow === null ? null : (new SmartPRS\Surface\GeoFence($store))->fenceFor($ownRow);
// THE COMPANY'S ATTENDANCE SOURCE (owner, 9 Nov 2026) — Time & Attendance → QR Attendance.
$qrSource = $ownRow === null ? 'standard' : SmartPRS\Surface\QrAttendance::load($customisation, __DIR__ . '/../..')->modeFor($ownRow); // Office staff: QR by default (12 Nov 2026)
$inApp = ($_COOKIE['sprs_app_device'] ?? '') !== '';
?>
<div class="page-header">
  <div><h2>Punch In / Out</h2><p><?= e((new DateTimeImmutable('today'))->format('l, d-m-Y')) ?> &middot; from this web app; device and mobile punches land in the same register</p></div>
  <a class="btn btn-outline" href="?r=today"><i class="fas fa-calendar-day"></i> Today</a>
</div>
<?php if ($ownRow === null): ?>
<div class="card"><div class="problem" style="margin:0">This account is not bound to an employee, so there is nobody to punch for. An administrator binds accounts on the Accounts screen.</div></div>
<?php else: ?>
<div class="card">
  <h2><?= e($code) ?> <span class="note"><?= e((string) ($ownRow['name'] ?? '')) ?></span>
    <span class="badge <?= $today['punches'] === 0 ? 'badge-gray' : ($next === 'out' ? 'badge-green' : 'badge-amber') ?>"><?=
      $today['punches'] === 0 ? 'No punch yet today' : ($next === 'out' ? 'IN since ' . e((string) ($today['first_in'] ?? '')) : 'OUT at ' . e((string) ($today['last_out'] ?? ''))) ?></span></h2>
<?php if ($qrSource === 'smartept'): ?>
  <div class="note" style="font-size:14px"><i class="fas fa-desktop"></i> Your company records attendance through <b>SmartEPT</b>. Punch in SmartEPT; the punch reaches SmartPRS3 by itself and shows here.</div>
<?php elseif ($qrSource === 'qr' && !$inApp): ?>
  <div class="note" style="font-size:14px"><i class="fas fa-qrcode"></i> Your company uses <b>QR Attendance</b>. Open the SmartPRS3 app on your approved phone → Punch → <b>Scan QR</b> at your office gate.</div>
<?php elseif ($qrSource === 'qr'): ?>
  <form method="post" id="qr-punch">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="self-punch">
    <input type="hidden" name="qr" value=""><input type="hidden" name="lat" value=""><input type="hidden" name="lng" value=""><input type="hidden" name="accuracy" value="">
    <button class="go" type="button" id="qr-start" style="background:var(--<?= $next === 'in' ? 'green' : 'red' ?>)"><i class="fas fa-qrcode"></i> Scan QR to punch <?= strtoupper($next) ?></button>
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
  })();
  </script>
<?php else: ?>
  <form method="post" id="self-punch" class="row" style="align-items:center">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="self-punch">
    <input type="hidden" name="lat" value=""><input type="hidden" name="lng" value="">
    <div style="flex:0"><button class="go" type="submit" style="background:var(--<?= $next === 'in' ? 'green' : 'red' ?>)"><i class="fas fa-fingerprint"></i> Punch <?= strtoupper($next) ?></button></div>
    <div class="note" id="punch-gps"><?= $fence === null
        ? 'Your location is asked for when you press the button and kept with the punch. No geo-fence is set for you.'
        : '<b>Geo-fence on:</b> punch-in is allowed only within ' . e(rtrim(rtrim(number_format($fence['radius_km'] + $fence['tolerance_km'], 1, '.', ''), '0'), '.')) . ' km of ' . e($fence['label']) . '. Allow location when the browser asks.' ?></div>
  </form>
<?php endif; ?>
  <div class="row" style="margin-top:14px">
    <div><b>First in</b><br><?= e((string) ($today['first_in'] ?? '—')) ?></div>
    <div><b>Last out</b><br><?= e((string) ($today['last_out'] ?? '—')) ?></div>
    <div><b>Worked so far</b><br><?= e(SmartPRS\Surface\AttendanceReport::hm((int) $today['worked_minutes'])) ?></div>
    <div><b>Punches</b><br><?= (int) $today['punches'] ?></div>
  </div>
</div>
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
