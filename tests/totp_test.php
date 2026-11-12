<?php

declare(strict_types=1);

use SmartPRS\Platform\Identity\RecoveryCodes;
use SmartPRS\Platform\Identity\Totp;
use SmartPRS\Platform\Support\FrozenClock;

// ---------------------------------------------------------------------------
// RFC 6238 Appendix B — the published test vectors, all of them, at every
// specified time and for all three algorithms.
//
// These are the reason TOTP is testable with no network and no authenticator
// app: if this file passes, the implementation is RFC 6238, and if a future
// "optimisation" breaks it, it says so here rather than in the field.
//
// The RFC's seeds are ASCII, repeated to the algorithm's block size:
//   SHA1    "12345678901234567890"                                (20 bytes)
//   SHA256  "12345678901234567890123456789012"                    (32 bytes)
//   SHA512  "1234567890123456789012345678901234567890
//            123456789012345678901234"                            (64 bytes)
// All vectors are 8 digits with T0 = 0 and X = 30.
// ---------------------------------------------------------------------------

group('Totp — RFC 6238 Appendix B test vectors');

$seeds = [
    'sha1' => substr(str_repeat('1234567890', 3), 0, 20),
    'sha256' => substr(str_repeat('1234567890', 4), 0, 32),
    'sha512' => substr(str_repeat('1234567890', 7), 0, 64),
];

same(20, strlen($seeds['sha1']), 'the SHA-1 seed is 20 bytes');
same(32, strlen($seeds['sha256']), 'the SHA-256 seed is 32 bytes');
same(64, strlen($seeds['sha512']), 'the SHA-512 seed is 64 bytes');

/** time => [sha1, sha256, sha512] */
$vectors = [
    59 => ['94287082', '46119246', '90693936'],
    1_111_111_109 => ['07081804', '68084774', '25091201'],
    1_111_111_111 => ['14050471', '67062674', '99943326'],
    1_234_567_890 => ['89005924', '91819424', '93441116'],
    2_000_000_000 => ['69279037', '90698825', '38618901'],
    20_000_000_000 => ['65353130', '77737706', '47863826'],
];

foreach ($vectors as $time => $expected) {
    foreach (['sha1', 'sha256', 'sha512'] as $index => $algorithm) {
        $totp = new Totp(algorithm: $algorithm, digits: 8, period: 30);
        $secret = Totp::base32Encode($seeds[$algorithm]);
        $at = new DateTimeImmutable('@' . $time);

        same(
            $expected[$index],
            $totp->codeAt($secret, $at),
            "RFC 6238 vector T={$time} {$algorithm} = {$expected[$index]}",
        );
    }
}

// The RFC's own time-step column, checked separately, because an off-by-one
// here would still pass above if the truncation compensated for it.
group('Totp — the RFC 6238 time step');

$totp = new Totp(digits: 8);

same(1, $totp->counterAt(new DateTimeImmutable('@59')), 'T=59 is step 1');
same(37_037_036, $totp->counterAt(new DateTimeImmutable('@1111111109')), 'T=1111111109 is step 37037036');
same(37_037_037, $totp->counterAt(new DateTimeImmutable('@1111111111')), 'T=1111111111 is step 37037037');
same(41_152_263, $totp->counterAt(new DateTimeImmutable('@1234567890')), 'T=1234567890 is step 41152263');
same(66_666_666, $totp->counterAt(new DateTimeImmutable('@2000000000')), 'T=2000000000 is step 66666666');
same(666_666_666, $totp->counterAt(new DateTimeImmutable('@20000000000')), 'T=20000000000 is step 666666666');

// ---------------------------------------------------------------------------
// Base32 — RFC 4648 §10 test vectors, because the whole factor is worthless if
// the secret an authenticator app scans is not the secret we hold.
// ---------------------------------------------------------------------------

group('Totp — RFC 4648 base32');

$b32 = [
    '' => '',
    'f' => 'MY======',
    'fo' => 'MZXQ====',
    'foo' => 'MZXW6===',
    'foob' => 'MZXW6YQ=',
    'fooba' => 'MZXW6YTB',
    'foobar' => 'MZXW6YTBOI======',
];

foreach ($b32 as $raw => $encoded) {
    same($encoded, Totp::base32Encode((string) $raw), "base32('{$raw}') = '{$encoded}'");

    if ($encoded !== '') {
        same((string) $raw, Totp::base32Decode($encoded), "base32 decode of '{$encoded}' round-trips");
    }
}

same(
    $seeds['sha1'],
    Totp::base32Decode(Totp::base32Encode($seeds['sha1'])),
    'the 20-byte RFC seed round-trips through base32',
);

throws(
    InvalidArgumentException::class,
    static fn () => Totp::base32Decode('MZXW6YT1'),   // '1' is not in the alphabet
    'a secret with a non-base32 character is refused, not partially decoded',
);

// ---------------------------------------------------------------------------
// The drift window, at its exact boundaries. FrozenClock throughout — nothing
// here may consult the wall clock.
// ---------------------------------------------------------------------------

group('Totp — the drift window at its boundaries');

$totp = new Totp(driftSteps: 1);
$secret = Totp::base32Encode($seeds['sha1']);

/** A claim function that accepts everything, for the window tests. */
$acceptAll = static fn (int $counter): bool => true;

// Anchor at a step boundary so "one step either side" is exact.
$anchor = 1_700_000_040;                       // divisible by 30
same(0, $anchor % 30, 'the anchor sits exactly on a step boundary');

$current = intdiv($anchor, 30);
$codeFor = static fn (int $counter): string => $totp->codeForCounter($secret, $counter);

$clockAt = static fn (int $timestamp): FrozenClock => FrozenClock::at('@' . $timestamp);

foreach ([-1, 0, 1] as $step) {
    same(
        $current + $step,
        $totp->verify($secret, $codeFor($current + $step), $clockAt($anchor), $acceptAll),
        "a code from step {$step} is inside the drift window",
    );
}

foreach ([-2, 2] as $step) {
    same(
        null,
        $totp->verify($secret, $codeFor($current + $step), $clockAt($anchor), $acceptAll),
        "a code from step {$step} is outside the drift window",
    );
}

// The boundary in TIME rather than in steps: the last second at which the
// previous step's code is still accepted, and the first at which it is not.
$lastSecondOfWindow = $anchor + 29;            // still step $current
same(
    $current - 1,
    $totp->verify($secret, $codeFor($current - 1), $clockAt($lastSecondOfWindow), $acceptAll),
    'at the last second of the current step, the previous step is still accepted',
);
same(
    null,
    $totp->verify($secret, $codeFor($current - 1), $clockAt($anchor + 30), $acceptAll),
    'one second later the step has advanced and that code is two steps back — refused',
);

// A zero drift window means exactly one step, which is what an installation
// tightening the platform constant would get.
$strict = new Totp(driftSteps: 0);
same(
    $current,
    $strict->verify($secret, $strict->codeForCounter($secret, $current), $clockAt($anchor), $acceptAll),
    'driftSteps = 0 still accepts the current step',
);
same(
    null,
    $strict->verify($secret, $strict->codeForCounter($secret, $current - 1), $clockAt($anchor), $acceptAll),
    'driftSteps = 0 refuses the previous step',
);

same(
    null,
    $totp->verify($secret, '12345', $clockAt($anchor), $acceptAll),
    'a code of the wrong length is refused before any HMAC is computed',
);

// ---------------------------------------------------------------------------
// Replay. R-XXII-195: a code still inside its window is not usable twice.
// ---------------------------------------------------------------------------

group('Totp — replay rejection');

$consumed = [];

// What the database does with UNIQUE(factor_id, counter): record it, and say
// whether it was new.
$claim = static function (int $counter) use (&$consumed): bool {
    if (isset($consumed[$counter])) {
        return false;
    }

    $consumed[$counter] = true;

    return true;
};

$code = $codeFor($current);
$clock = $clockAt($anchor);

same($current, $totp->verify($secret, $code, $clock, $claim), 'the code is accepted the first time');
same([$current => true], $consumed, 'and the counter was claimed');
same(null, $totp->verify($secret, $code, $clock, $claim), 'REPLAY: the same code is refused the second time');

// Still refused later in the same drift window, which is the case an
// implementation that only remembers "the last code" gets wrong.
same(
    null,
    $totp->verify($secret, $code, $clockAt($anchor + 29), $claim),
    'and it stays refused for the rest of its drift window',
);

// The next step's code is a different counter and is unaffected.
same(
    $current + 1,
    $totp->verify($secret, $codeFor($current + 1), $clockAt($anchor + 30), $claim),
    'the next step\'s code is a different counter and is accepted',
);

// ---------------------------------------------------------------------------
// Secrets and the provisioning URI.
// ---------------------------------------------------------------------------

group('Totp — secrets and provisioning');

$fresh = Totp::generateSecret();
same(32, strlen(rtrim($fresh, '=')), 'a 160-bit secret is 32 base32 characters');
ok($fresh !== Totp::generateSecret(), 'two generated secrets differ');
throws(
    InvalidArgumentException::class,
    static fn () => Totp::generateSecret(8),
    'a secret below 128 bits is refused',
);

$uri = $totp->provisioningUri('MZXW6YTB', 'priya@acme.example', 'SmartPRS');

ok(str_starts_with($uri, 'otpauth://totp/SmartPRS%3Apriya%40acme.example?'), 'the label is issuer:account, escaped');
ok(str_contains($uri, 'secret=MZXW6YTB'), 'the secret is carried unpadded');
ok(str_contains($uri, 'issuer=SmartPRS'), 'and the issuer is repeated as a parameter for newer apps');
ok(str_contains($uri, 'algorithm=SHA1') && str_contains($uri, 'digits=6') && str_contains($uri, 'period=30'), 'the parameters are explicit, not assumed');

throws(
    InvalidArgumentException::class,
    static fn () => $totp->provisioningUri('MZXW6YTB', '', 'SmartPRS'),
    'an unlabelled provisioning URI is refused',
);

throws(
    InvalidArgumentException::class,
    static fn () => new Totp(algorithm: 'md5'),
    'an algorithm outside RFC 6238 is refused',
);

// ---------------------------------------------------------------------------
// Recovery codes — single use, hashed at rest.
// ---------------------------------------------------------------------------

group('RecoveryCodes — single use and hashed at rest');

$issued = RecoveryCodes::issue(8);

same(8, count($issued['codes']), 'the count is configurable');
same(8, count($issued['hashes']), 'and every code has a hash');
same(8, count(array_unique($issued['codes'])), 'the codes are distinct');

$plain = $issued['codes'][3];

same(1, preg_match('/^[A-Z2-7]{4}(-[A-Z2-7]{4}){3}$/', $plain), 'a code is four groups of four base32 characters');

// HASHED AT REST: no stored value contains, equals, or reveals a code.
foreach ($issued['hashes'] as $index => $hash) {
    same(64, strlen($hash), 'hash ' . $index . ' is a 64-character sha256 digest');
    same(1, preg_match('/^[0-9a-f]{64}$/', $hash), 'hash ' . $index . ' is lowercase hex, not the code');
}

foreach ($issued['codes'] as $index => $code) {
    ok(
        !in_array($code, $issued['hashes'], true)
        && !in_array(str_replace('-', '', $code), $issued['hashes'], true),
        'code ' . $index . ' does not appear among the stored hashes',
    );
}

$remaining = $issued['hashes'];

$afterFirst = RecoveryCodes::redeem($plain, $remaining);

ok($afterFirst !== null, 'a valid code is redeemed');
same(7, count($afterFirst), 'and the returned set is one shorter — consumption is the return value, not a side effect');

same(
    null,
    RecoveryCodes::redeem($plain, $afterFirst),
    'SINGLE USE: the same code cannot be redeemed a second time',
);

same(
    6,
    count(RecoveryCodes::redeem($issued['codes'][0], $afterFirst) ?? []),
    'a different code still works after the first is spent, and leaves six',
);

// Normalisation: the user retypes what is on the printout, badly.
$sloppy = strtolower(str_replace('-', ' ', $issued['codes'][1]));
ok(RecoveryCodes::redeem($sloppy, $afterFirst) !== null, 'case and separators are normalised on redemption');

same(null, RecoveryCodes::redeem('AAAA-BBBB-CCCC-DDDD', $issued['hashes']), 'an invented code is refused');
same(null, RecoveryCodes::redeem($plain, []), 'an exhausted set redeems nothing');

throws(
    InvalidArgumentException::class,
    static fn () => RecoveryCodes::issue(0),
    'issuing zero recovery codes is refused',
);
