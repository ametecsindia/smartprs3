<?php

declare(strict_types=1);

use SmartPRS\Surface\MobileActivation;

group('mobile_activation');

// Owner, 12 Nov 2026: SmartEPT-style phone sign-in — a single-use, expiring activation code; a one-time login link.
$dir = sys_get_temp_dir() . '/sprs-ma-' . bin2hex(random_bytes(4));
$ma = new MobileActivation(['code_length' => 8, 'valid_hours' => 72, 'attempts' => 3, 'lock_minutes' => 5, 'login_link_seconds' => 120], $dir);
$now = 1_800_000_000;
[$code, $saved, $until] = $ma->issue([], 'e:7', 'admin@x', $now);

ok(preg_match('/^[A-HJ-NP-Z2-9]{8}$/', $code) === 1, 'code: 8 characters, no 0/O/1/I');
same(date('Y-m-d H:i:s', $now + 72 * 3600), $until, 'valid for 72 hours');
ok(!str_contains(json_encode($saved), $code), 'the code itself is never stored, only its hash');
same(true, MobileActivation::matches($saved, 'e:7', $code, $now), 'right code, right person: accepted');
same(true, MobileActivation::matches($saved, 'e:7', strtolower(substr($code, 0, 4) . '-' . substr($code, 4)), $now), 'case and dashes ignored');
same(false, MobileActivation::matches($saved, 'e:8', $code, $now), 'someone else\'s code: refused');
same(false, MobileActivation::matches($saved, 'e:7', 'WRONG234', $now), 'wrong code: refused');
same(false, MobileActivation::matches($saved, 'e:7', $code, $now + 73 * 3600), 'expired code: refused');
same(false, MobileActivation::matches(MobileActivation::consume($saved, 'e:7'), 'e:7', $code, $now), 'used once: gone');
[$code2, $saved2] = $ma->issue($saved, 'e:7', 'admin@x', $now);
same(false, MobileActivation::matches($saved2, 'e:7', $code, $now) && $code !== $code2, 'a new code replaces the old one');

same(0, $ma->locked('PH1', $now), 'no tries yet: may try');
$ma->failed('PH1', $now); $ma->failed('PH1', $now);
same(0, $ma->locked('PH1', $now), 'two wrong tries: may still try');
$ma->failed('PH1', $now);
ok($ma->locked('PH1', $now) > 0, 'three wrong tries: paused');
same(0, $ma->locked('PH1', $now + 301), 'pause ends by itself');
$ma->cleared('PH1');

$t = $ma->mintLogin('42', '3', 'PH1', $now);
same(null, $ma->takeLogin($t, 'PH2', $now), 'login link on another phone: refused (and spent)');
$t = $ma->mintLogin('42', '3', 'PH1', $now);
same('42', (string) ($ma->takeLogin($t, 'PH1', $now + 5)['user'] ?? ''), 'login link on its own phone: signs that user in');
same(null, $ma->takeLogin($t, 'PH1', $now + 6), 'login link works once');
$t = $ma->mintLogin('42', '3', 'PH1', $now);
same(null, $ma->takeLogin($t, 'PH1', $now + 121), 'login link expires');
same(null, $ma->takeLogin('../../etc', 'PH1', $now), 'not a link token: refused');
