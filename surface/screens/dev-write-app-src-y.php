<?php declare(strict_types=1);
// DEV ONLY (6 Nov 2026): puts the Employee app's updated MainActivity.java (selfie option; one universal app 6 Nov; QR scan camera 9 Nov; SmartEPT-style sign-in + activation code 12 Nov; Explore Demo button 12 Nov v1.3; SmartPRS logo + theme on the sign-in screens 12 Nov v1.4) in place —
// the file is nested deeper than the file bridge reaches. Refuses if the app file changed since it was reviewed.
// Administrator only. Delete with the other dev screens.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
$f = __DIR__ . '/../../mobile/employee-android/app/src/main/java/com/ametecs/smartprs3/employee/MainActivity.java';
$new = __DIR__ . '/../../storage/app-src/MainActivity.java';
$reviewed = ['71b650ca49074af184a713bc496c97b91874cd44', 'ed6319126bf63cbc8f4b7ddb86edb0dae3ff377f']; // the v1.3 / v1.4 app files this update (v1.5: pinch zoom) may replace
$now = is_file($f) ? sha1_file($f) : '';
$msg = !is_file($new) ? 'Update file missing.'
    : ($now === sha1_file($new) ? 'Already in place.'
    : (!in_array($now, $reviewed, true) ? 'Not copied: the app file changed since it was reviewed.'
    : (copy($new, $f) ? 'Copied. Build the APK in Android Studio.' : 'Not copied: could not write.')));
?><div class="card"><h2>SmartPRS app — v1.5 (pinch zoom)</h2><p id="src"><?= e($msg) ?></p></div>
