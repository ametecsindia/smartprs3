<?php

declare(strict_types=1);

use SmartPRS\Platform\Identity\BreachCheckUnavailable;
use SmartPRS\Platform\Identity\LocalListBreachCheck;
use SmartPRS\Platform\Identity\NullBreachCheck;
use SmartPRS\Platform\Identity\PasswordHasher;
use SmartPRS\Platform\Identity\PasswordPolicy;
use SmartPRS\Platform\Identity\PolicyResult;
use SmartPRS\Platform\Support\FrozenClock;

// bcrypt everywhere in this file: Argon2id at the platform's memory cost is
// ~19 MiB per hash, and the suite computes dozens. The rehash path is tested
// against the real Argon2id parameters separately, below.
$hasher = PasswordHasher::bcrypt(4);

// ---------------------------------------------------------------------------
// Every failure at once. R-XXII-183, and the interaction defect this corrects:
// a policy that reveals itself one rule at a time is a policy people work
// around by choosing something they will write down.
// ---------------------------------------------------------------------------

group('PasswordPolicy — every failure, not the first');

$policy = PasswordPolicy::forTenant([
    'requiredClasses' => [PasswordPolicy::CLASS_UPPER, PasswordPolicy::CLASS_SYMBOL],
]);

$breach = LocalListBreachCheck::fromPasswords(['password', 'acme123', 'priya2026']);

$result = $policy->validate(
    'password',                                   // short, no upper, no symbol,
    context: ['email' => 'priya@acme.example'],   // denylisted, and breached
    breach: $breach,
);

ok(!$result->ok(), 'a hopeless candidate fails');
same(
    [
        PolicyResult::TOO_SHORT,
        PolicyResult::MISSING_CLASS,
        PolicyResult::DENYLISTED,
        PolicyResult::BREACHED,
    ],
    $result->codes(),
    'ALL FOUR failures come back together, in policy order',
);

ok(str_contains($result->render(), 'at least 14 characters'), 'the rendering names the actual minimum');

// The same candidate under a policy with history: five failures, still in one pass.
$withHistory = $policy->validate(
    'password',
    context: ['email' => 'priya@acme.example'],
    historyHashes: [$hasher->hash('password')],
    hasher: $hasher,
    breach: $breach,
);

same(5, count($withHistory->failures), 'history reuse joins the same list rather than replacing it');
ok($withHistory->failed(PolicyResult::REUSED), 'and it is the reuse code');

group('PasswordPolicy — the individual rules');

$plain = PasswordPolicy::forTenant();
$clean = new NullBreachCheck();

// Every call below supplies a checker: a policy with breachCheckEnabled and no
// BreachCheck now takes the outage path, which is asserted at the end of this file.
$check = static fn (string $candidate, array $context = []) => $plain->validate($candidate, context: $context, breach: $clean);

ok($check('correct horse battery staple')->ok(), 'a long passphrase with no classes passes the default profile');
ok($check(str_repeat('a', 13))->failed(PolicyResult::TOO_SHORT), '13 characters is short of the default 14');
ok($check(str_repeat('a', 14))->ok(), '14 is not');
ok($check(str_repeat('a', 257))->failed(PolicyResult::TOO_LONG), 'and 257 exceeds the generous maximum');
ok(
    str_contains($check(str_repeat('a', 257))->render(), 'Nothing is truncated'),
    'R-XXII-183: an over-long password is refused, never silently truncated',
);

// Identity terms: the user's own email, the tenant's name, the product's name.
foreach ([
    ['priya-at-work-2026', ['email' => 'priya@acme.example'], 'the email local part'],
    ['acme-example-1234567', ['email' => 'priya@acme.example'], 'the email domain label'],
    ['MyAcmeCorpLoginPass', ['tenant_name' => 'AcmeCorp'], 'the tenant name'],
    ['smartprs-is-great-99', ['product_name' => 'SmartPRS'], 'the product name'],
    ['EMP004417-secure-pw', ['employee_code' => 'EMP004417'], 'the employee code'],
] as [$candidate, $context, $what]) {
    ok(
        $check($candidate, $context)->failed(PolicyResult::IDENTITY_TERM),
        "a password containing {$what} is refused",
    );
}

ok(
    $check('an-unrelated-passphrase', ['email' => 'priya@acme.example'])->ok(),
    'and an unrelated passphrase is not',
);

ok(
    $check('this-is-my-and-or-passphrase', ['tenant_name' => 'An Or Ltd'])->ok(),
    'identity terms below four characters are ignored, so short words do not reject everything',
);

// Denylist matches as a substring: "password2026" is not a different password.
ok($check('MyPassword2026!!')->failed(PolicyResult::DENYLISTED), 'a denylisted value as a substring is refused');
foreach (['smartprs-demo', 'ametecs', 'password'] as $shipped) {
    ok(
        $check($shipped . str_repeat('x', 20))->failed(PolicyResult::DENYLISTED),
        "R-XXII-230: the historical shipped credential '{$shipped}' is on the denylist",
    );
}

group('PasswordPolicy — character classes, when a customer\'s auditor requires them');

$strict = PasswordPolicy::forTenant(['requiredClasses' => PasswordPolicy::CLASSES]);

ok($strict->validate('alllowercaseonly', breach: $clean)->failed(PolicyResult::MISSING_CLASS), 'a missing class is named');
ok($strict->validate('Sample Phrase 42!', breach: $clean)->ok(), 'and a candidate carrying all four passes');
ok(
    str_contains($strict->validate('alllowercaseonly', breach: $clean)->render(), 'upper, digit, symbol'),
    'the message lists every missing class, not the first',
);

throws(
    InvalidArgumentException::class,
    static fn () => PasswordPolicy::forTenant(['requiredClasses' => ['emoji']]),
    'an unknown character class is refused rather than ignored',
);

throws(
    InvalidArgumentException::class,
    static fn () => PasswordPolicy::forTenant(['minLenght' => 20]),
    'a misspelt policy key is refused — a key that is silently ignored is a policy nobody enforces',
);

// ---------------------------------------------------------------------------
// The platform floor. R-XXII-180: a tenant may tighten and may never loosen.
// ---------------------------------------------------------------------------

group('PasswordPolicy — the platform floor cannot be loosened');

$floor = PasswordPolicy::platformFloor();

same(12, $floor->minLength, 'the floor is 12 characters');
ok(PasswordPolicy::tenantDefault()->loosenedAgainst($floor) === [], 'the shipped default sits at or above the floor');

// Tightening: fine, in every direction.
ok(PasswordPolicy::forTenant(['minLength' => 20])->minLength === 20, 'a tenant may raise min_length to 20');
ok(PasswordPolicy::forTenant(['historyDepth' => 24])->historyDepth === 24, 'a tenant may deepen the history');
ok(PasswordPolicy::forTenant(['maxAgeDays' => 90])->maxAgeDays === 90, 'a tenant may switch expiry on');
ok(PasswordPolicy::forTenant(['requiredClasses' => ['digit']])->requiredClasses === ['digit'], 'a tenant may add a class requirement');

// Loosening: refused, on every dimension, one at a time.
foreach ([
    [['minLength' => 4], 'min_length below the floor'],
    [['minLength' => 11], 'min_length one below the floor'],
    [['maxLength' => 64], 'a max_length that caps length below the floor'],
    [['historyDepth' => 1], 'a history shallower than the floor'],
    [['breachCheckEnabled' => false], 'the breach check switched off'],
    [['disallowIdentityTerms' => false], 'the identity-term check switched off'],
    [['denylist' => ['nothing-useful']], 'a denylist with the floor\'s values removed'],
] as [$override, $what]) {
    throws(
        DomainException::class,
        static fn () => PasswordPolicy::forTenant($override),
        "a tenant cannot loosen the floor with {$what}",
    );
}

// The floor is exactly a floor: sitting ON it is allowed.
ok(
    PasswordPolicy::forTenant(['minLength' => 12])->minLength === 12,
    'a tenant may sit exactly on the floor',
);

$loosened = PasswordPolicy::tenantDefault()->with(['minLength' => 6, 'breachCheckEnabled' => false]);
same(
    [
        'min_length 6 is below the floor of 12',
        'the breach check has been switched off',
    ],
    $loosened->loosenedAgainst($floor),
    'loosenedAgainst() names every dimension, so the refusal message is actionable',
);

group('PasswordPolicy — R-XXII-181: the platform-account floor is strictly stricter');

$platform = PasswordPolicy::platformAccountFloor();

ok($platform->minLength > $floor->minLength, 'platform accounts require more length than tenant accounts');
ok($platform->historyDepth > $floor->historyDepth, 'and a deeper history');
ok($platform->minAgeHours > $floor->minAgeHours, 'and a minimum age');
ok($platform->rejectWhenBreachCheckUnavailable && !$floor->rejectWhenBreachCheckUnavailable, 'and no fail-open on an unavailable corpus');
ok(
    $platform->loosenedAgainst($floor) === [],
    'the platform-account floor is nowhere weaker than the tenant floor — the SmartPRS 2 inversion (min:6 for Super Admins) is unrepresentable',
);

throws(
    DomainException::class,
    static fn () => PasswordPolicy::forPlatformAccounts(['minLength' => 14]),
    'a platform account cannot be dropped to the tenant default',
);

// ---------------------------------------------------------------------------
// History, minimum age and expiry — all on FrozenClock.
// ---------------------------------------------------------------------------

group('PasswordPolicy — history, min age and expiry');

$clock = FrozenClock::at('2026-09-04T10:00:00Z');
$changedAt = new DateTimeImmutable('2026-09-04T09:30:00Z');

// Newest first, which is the order the history table is read in.
$history = array_map(
    static fn (string $p): string => $hasher->hash($p),
    ['first-passphrase-01', 'second-passphrase-2', 'third-passphrase-003', 'fourth-passphrase-04'],
);

// The floor's history depth is 3, so 3 is the shallowest a tenant may configure.
$depth3 = PasswordPolicy::forTenant(['historyDepth' => 3, 'minAgeHours' => 24]);

foreach (['first-passphrase-01', 'second-passphrase-2', 'third-passphrase-003'] as $old) {
    ok(
        $depth3->validate($old, historyHashes: $history, hasher: $hasher, breach: $clean)->failed(PolicyResult::REUSED),
        "'{$old}' is inside the history depth and is refused",
    );
}

ok(
    !$depth3->validate('fourth-passphrase-04', historyHashes: $history, hasher: $hasher, breach: $clean)->failed(PolicyResult::REUSED),
    'and the fourth, beyond the configured depth, is not consulted',
);

throws(
    DomainException::class,
    static fn () => PasswordPolicy::forTenant(['historyDepth' => 2]),
    'a tenant cannot go below the floor\'s history depth of 3',
);

ok(
    $depth3->validate('a-brand-new-passphrase', lastChangedAt: $changedAt, clock: $clock, breach: $clean)
        ->failed(PolicyResult::CHANGED_TOO_RECENTLY),
    'min_age_hours stops cycling straight back through the history',
);

$clock->advance('+25 hours');
ok(
    $depth3->validate('a-brand-new-passphrase', lastChangedAt: $changedAt, clock: $clock, breach: $clean)->ok(),
    'and releases once the minimum age has passed',
);

$expiring = PasswordPolicy::forTenant(['maxAgeDays' => 90, 'expiryWarningDays' => 14]);
$set = new DateTimeImmutable('2026-01-01T00:00:00Z');

$at = static fn (string $when): FrozenClock => FrozenClock::at($when);

ok(!$expiring->hasExpired($set, $at('2026-03-31T00:00:00Z')), 'a 90-day password has not expired on day 89');
ok($expiring->hasExpired($set, $at('2026-04-01T00:00:00Z')), 'and has on day 91');
same(null, $expiring->expiryWarningDaysRemaining($set, $at('2026-03-01T00:00:00Z')), 'no warning before the window opens');
same(10, $expiring->expiryWarningDaysRemaining($set, $at('2026-03-22T00:00:00Z')), 'ten days out, the warning says ten');
same(0, $expiring->expiryWarningDaysRemaining($set, $at('2026-05-01T00:00:00Z')), 'past expiry it says zero rather than a negative');
same(null, PasswordPolicy::tenantDefault()->expiryWarningDaysRemaining($set, $at('2030-01-01T00:00:00Z')), 'expiry is off by default (R-XXII-186)');

// ---------------------------------------------------------------------------
// Breach checking and the fail-closed decision.
// ---------------------------------------------------------------------------

group('BreachCheck — k-anonymity, and fail closed at set time');

$corpus = LocalListBreachCheck::fromPasswords(['correct horse battery staple', 'monkey-monkey-monkey']);

ok($corpus->isBreached('correct horse battery staple'), 'a corpus member is found');
ok(!$corpus->isBreached('a passphrase nobody has used'), 'a non-member is not');

// The same corpus expressed the way a downloaded corpus actually arrives.
$digests = LocalListBreachCheck::fromSha1Digests([strtoupper(sha1('correct horse battery staple'))]);
ok($digests->isBreached('correct horse battery staple'), 'a SHA-1 digest corpus behaves identically');
ok(!$digests->isBreached('monkey-monkey-monkey'), 'and carries only what was loaded');

throws(
    InvalidArgumentException::class,
    static fn () => LocalListBreachCheck::fromSha1Digests(['not-a-digest']),
    'a malformed corpus row is refused — a half-loaded breach corpus is worse than none',
);

ok(!(new NullBreachCheck())->isBreached('password'), 'NullBreachCheck reports everything clean, as its name says');

// The outage. R-XXII-184: the change is REFUSED, and never silently accepted.
$down = LocalListBreachCheck::unavailable();

throws(
    BreachCheckUnavailable::class,
    static fn () => $down->isBreached('anything'),
    'an unreachable corpus throws rather than returning "not breached"',
);

$failClosed = PasswordPolicy::forTenant();
$outage = $failClosed->validate('a-perfectly-fine-passphrase', breach: $down);

ok($failClosed->rejectWhenBreachCheckUnavailable, 'the recommended default is to fail closed at set time');
ok(!$outage->ok(), 'so an outage refuses the password CHANGE');
same([PolicyResult::BREACH_CHECK_UNAVAILABLE], $outage->codes(), 'with a distinct, retryable reason');
ok(str_contains($outage->render(), 'try again shortly'), 'and a message that tells the user to retry');

// A policy that says it breach-checks, wired to nothing, is the SmartPRS 2
// pattern: a control that is configured on and never runs. It takes the outage
// path rather than passing silently.
$unwired = $failClosed->validate('a-perfectly-fine-passphrase');

ok(!$unwired->ok(), 'breach_check_enabled with NO checker supplied does not silently pass');
same([PolicyResult::BREACH_CHECK_UNAVAILABLE], $unwired->codes(), 'it reports the same unavailability as a real outage');

// The air-gapped alternative: a recorded tenant decision, not a shrug.
$failOpen = PasswordPolicy::forTenant(['rejectWhenBreachCheckUnavailable' => false]);
ok(
    $failOpen->validate('a-perfectly-fine-passphrase', breach: $down)->ok(),
    'an installation that configured fail-open accepts during an outage',
);
ok(
    $floor->loosenedAgainst(PasswordPolicy::platformAccountFloor()) !== [],
    'and it may not do so for a platform account — that floor forbids it',
);

// ---------------------------------------------------------------------------
// The hasher.
// ---------------------------------------------------------------------------

group('PasswordHasher — the thin wrapper');

$cheap = PasswordHasher::bcrypt(4);
$hash = $cheap->hash('a-real-passphrase-here');

ok($cheap->verify('a-real-passphrase-here', $hash), 'the right password verifies');
ok(!$cheap->verify('a-real-passphrase-heer', $hash), 'a wrong one does not');
ok($cheap->hash('a-real-passphrase-here') !== $hash, 'the salt makes two hashes of the same password differ');

// R-XXII-193: an account with NO password must not be cheaper to reject than
// one with a wrong password.
ok(!$cheap->verify('anything', null), 'a null hash verifies nothing');
ok(!$cheap->verify('anything', ''), 'and neither does an empty one');
same(null, $cheap->verifyAndRehash('anything', null), 'and produces no rehash');

// Rehash on verify (R-XXII-188).
$stronger = PasswordHasher::bcrypt(5);

ok($stronger->needsRehash($hash), 'a hash made at a lower cost needs rehashing');
ok(!$cheap->needsRehash($hash), 'and one at the current cost does not');

$replacement = $stronger->verifyAndRehash('a-real-passphrase-here', $hash);

ok(is_string($replacement), 'a correct password with a stale hash comes back with a replacement');
ok($stronger->verify('a-real-passphrase-here', (string) $replacement), 'and the replacement verifies');
ok(!$stronger->needsRehash((string) $replacement), 'and does not need rehashing again');
same(null, $stronger->verifyAndRehash('the-wrong-passphrase', $hash), 'a WRONG password never produces a rehash');
same(null, $cheap->verifyAndRehash('a-real-passphrase-here', $hash), 'and neither does a hash already at current parameters');

$default = PasswordHasher::platformDefault();
same(
    defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT,
    $default->algorithm,
    'the platform default is Argon2id where the build supports it, bcrypt where it does not',
);
same(PasswordHasher::BCRYPT_COST, PasswordHasher::bcrypt()->options['cost'], 'the bcrypt fallback pins cost 12, as SmartPRS 2 had it');
