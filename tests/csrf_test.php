<?php

declare(strict_types=1);

/**
 * EVERY POST IS FORGEABLE UNTIL SOMETHING PROVES IT CAME FROM HERE.
 *
 * Without a token, any site a signed-in user visits can submit this
 * application's forms in their name: approve an increment, close a payroll
 * month, publish a salary structure, move a customer's approval threshold. The
 * user sees nothing, because a cross-site POST renders its response somewhere
 * they never look.
 *
 * THE FIRST ASSERTION IN THIS FILE IS THAT THE VALID CASE PASSES, and it is
 * first on purpose. A `verify()` that returns false unconditionally satisfies
 * every negative test below while protecting nothing and breaking every screen
 * in the product. The negative tests alone cannot tell those two apart.
 *
 * The last two assertions read the front controller itself. The token is only
 * protection if it is CHECKED — a verifier nobody calls is a comment — and only
 * usable if every form CARRIES it, since a form that does not is now a screen
 * that refuses to work.
 */

use SmartPRS\Surface\Session;
use SmartPRS\Surface\Store;

use SmartPRS\Platform\Identity\PasswordHasher;

group('csrf');

$store = new Store(sys_get_temp_dir() . '/prs3-csrf-' . bin2hex(random_bytes(4)));
$session = new Session($store, PasswordHasher::platformDefault());

// One browser session. `$_SESSION` is the whole of the session state these two
// methods touch, so a fresh array is a fresh session — no cookie jar needed.
$_SESSION = [];
$token = $session->token();

// NON-VACUITY. If this fails, nothing below means anything.
ok($session->verify($token), 'the token this session was issued VERIFIES — a verifier that says '
    . 'no to everything passes every test below while breaking every form in the product');

ok(!$session->verify('not-the-token'), 'a wrong token does not verify');
ok(!$session->verify(''), 'an empty token does not verify');
ok(!$session->verify(null), 'an absent token does not verify — a form that sends no field at all '
    . 'is refused exactly like one that sends the wrong value');

// A PREFIX IS NOT A MATCH. `===` would already get this right, but the reason
// `hash_equals` is there is timing, and a length-insensitive comparison written
// by hand is the classic way to lose both properties at once.
ok(!$session->verify(substr($token, 0, strlen($token) - 1)),
    'a token truncated by one character does not verify');
ok(!$session->verify(substr($token, 0, 8)), 'the first eight characters do not verify');
ok(!$session->verify($token . 'x'), 'the right token with something appended does not verify');

// STABLE WITHIN A SESSION. If it were re-minted per call, the value in a form
// already on screen would stop matching the moment the page was re-rendered,
// and people would learn that this application "sometimes just fails".
same($token, $session->token(), 'the token is the same one for the life of the session');
ok($session->verify($session->token()), 'and it still verifies on a second read');

// DIFFERENT BETWEEN SESSIONS. This is the whole mechanism: the attacker has a
// session of their own and can read its token freely, so that token must be
// worthless against anybody else's.
$mine = $token;
$_SESSION = [];
$theirs = $session->token();

ok($mine !== $theirs, 'a second session gets a different token');
ok(!$session->verify($mine), "another session's token is refused here");

$_SESSION = [];
$_SESSION['csrf'] = $mine;
ok($session->verify($mine), 'and the original token still verifies in its own session');
ok(!$session->verify($theirs), 'while the second session\'s does not');

// A session that has never been issued a token accepts nothing — including the
// empty string it would hold if `token()` had never run.
$_SESSION = [];
ok(!$session->verify(''), 'a session with no token issued accepts nothing');
ok(!$session->verify('anything'), 'not even a plausible-looking value');

// It is long enough to be worth guessing at: 32 random bytes as hex.
ok(strlen($mine) >= 32, 'the token is long enough that guessing it is not a strategy');
ok(ctype_xdigit($mine), 'and it is hex, so it survives a form field untouched');

// ---- The front controller must actually use it -----------------------------

$app = (string) file_get_contents(__DIR__ . '/../public/app.php');

// ONE check, and it sits ABOVE the first action. Below the first action would
// mean the first action runs unprotected — which is how a "single check" ends
// up guarding everything except the one thing somebody added at the top.
$guard = strpos($app, '$session->verify(post(\'csrf\'))');
$firstAction = strpos($app, 'if ($action === ');

ok($guard !== false, 'the front controller checks the token');
ok($guard !== false && $firstAction !== false && $guard < $firstAction,
    'and it checks BEFORE the first action can run');

// EVERY form that POSTs carries the field. A form that does not is not a small
// omission any more — it is a screen that refuses everything typed into it.
preg_match_all('~<form\b[^>]*method="post"[^>]*>(.{0,400})~is', $app, $forms);
$without = 0;

foreach ($forms[0] as $i => $whole) {
    // A form reachable with NO session (the set-password invitation link) carries a
    // one-time token instead and says so on the tag; everything else carries the CSRF field.
    if (!str_contains($forms[1][$i], 'name="csrf"') && !str_contains($whole, 'data-token-secured=')) {
        $without++;
    }
}

ok(count($forms[0]) > 0, 'there are POST forms in the front controller to check');
same(0, $without, 'every form that POSTs carries the token — one that does not is a broken screen, '
    . 'and the smoke test only issues GETs so it would not find it');
