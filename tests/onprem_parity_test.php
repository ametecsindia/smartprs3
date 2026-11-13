<?php

declare(strict_types=1);

use SmartPRS\Surface\LicenceAuthority;
use SmartPRS\Surface\Store;

/** Owner, 13 Nov 2026: on-prem licensing exactly as SmartPRS 2 — client emails, .lic fingerprint fallback. */
group('onprem parity');

$p = new Store(sys_get_temp_dir() . '/onp' . getmypid() . bin2hex(random_bytes(3)));
$a = new LicenceAuthority($p, dirname(__DIR__));
$lic = $p->put('licences', ['client_id' => '1', 'fingerprint' => null, 'status' => 'active']);
$p->put('licence_devices', ['licence_id' => (string) $lic['id'], 'device_uid' => 'OLD', 'fingerprint' => 'OLD', 'status' => 'active', 'last_seen_at' => '2026-11-01 10:00:00']);
$p->put('licence_devices', ['licence_id' => (string) $lic['id'], 'device_uid' => 'NEW', 'fingerprint' => 'NEW', 'status' => 'active', 'last_seen_at' => '2026-11-12 10:00:00']);
$p->put('licence_devices', ['licence_id' => (string) $lic['id'], 'device_uid' => 'GONE', 'fingerprint' => 'GONE', 'status' => 'deactivated', 'last_seen_at' => '2026-11-13 10:00:00']);
same('NEW', $a->knownFingerprint($lic), 'an unbound licence re-issues its .lic to the server seat seen last');
same('BOUND', $a->knownFingerprint(['fingerprint' => 'BOUND'] + $lic), 'a bound licence keeps its own fingerprint');

$c = ['company' => 'Acme', 'contact_name' => 'Ravi'];
[$s, $t] = LicenceAuthority::clientEmail('key', $c, ['key' => 'SPRS-AAAA', 'until' => '31-12-2027']);
ok(str_contains($t, 'SPRS-AAAA') && str_contains($t, '31-12-2027'), 'the key email carries the key and the date');
[$s, $t] = LicenceAuthority::clientEmail('invoice', $c, ['number' => 'PRS-1', 'price' => 'a', 'tax' => 'b', 'total' => 'c', 'balance' => 'd', 'link' => 'https://x/licence.php?t=1']);
ok(str_contains($s, 'PRS-1') && str_contains($t, 'licence.php?t=1'), 'the invoice email carries the number and the pay link');
[$s, $t] = LicenceAuthority::clientEmail('lic', $c, ['key' => 'K', 'seats' => 'unlimited', 'until' => 'x', 'token' => 'TOKEN.SIG']);
ok(str_contains($t, 'TOKEN.SIG'), 'the .lic email carries the licence code');
