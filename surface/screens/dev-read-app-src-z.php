<?php declare(strict_types=1);
// DEV ONLY (5 Nov 2026): copies the Employee Android app's MainActivity.java to storage/dev-mainactivity.txt so it can be
// reviewed from the cloud session (the file is nested deeper than the file bridge reaches). Administrator only. Delete after.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
$f = __DIR__ . '/../../mobile/employee-android/app/src/main/java/com/ametecs/smartprs3/employee/MainActivity.java';
$ok = is_file($f) && copy($f, __DIR__ . '/../../storage/dev-mainactivity.txt');
?><pre id="src"><?= $ok ? 'copied' : 'not copied' ?></pre>
