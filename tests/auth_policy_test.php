<?php

declare(strict_types=1);

use SmartPRS\Platform\Access\Role;
use SmartPRS\Platform\Identity\CredentialSeeding;
use SmartPRS\Platform\Identity\LockoutPolicy;
use SmartPRS\Platform\Identity\LockoutState;
use SmartPRS\Platform\Identity\MfaRequirement;
use SmartPRS\Platform\Identity\NullBreachCheck;
use SmartPRS\Platform\Identity\PasswordHasher;
use SmartPRS\Platform\Identity\PasswordPolicy;
use SmartPRS\Platform\Identity\SessionPolicy;
use SmartPRS\Platform\Support\FrozenClock;

// ---------------------------------------------------------------------------
// Lockout. SmartPRS 2 has none: no failed_login table, no locked_until column,
// no Failed listener, and a 5/min per-IP throttle an attacker with a hundred
// addresses never meets.
// ---------------------------------------------------------------------------

group('LockoutPolicy — the threshold');

$policy = LockoutPolicy::platformDefault();
$t0 = new DateTimeImmutable('2026-09-04T10:00:00Z');

/** n failures, one second apart, ending at $t0. */
$failures = static fn (int $n, int $spacingSeconds = 1): array => array_map(
    static fn (int $i): DateTimeImmutable => $t0->modify('-' . ($i * $spacingSeconds) . ' seconds'),
    range(0, max(0, $n - 1)),
);

$at = static fn (string $offset): FrozenClock => FrozenClock::at(
    $t0->modify($offset)->format(DateTimeInterface::ATOM),
);

same(10, $policy->threshold, 'the default account threshold is 10 failures');

$nine = $policy->evaluate($failures(9), [], 0, $at('+1 minute'));
ok(!$nine->blocked, 'nine failures inside the window do not lock');
same(1, $nine->remainingBeforeBlock, 'and the actor has one attempt left');

$ten = $policy->evaluate($failures(10), [], 0, $at('+1 minute'));
ok($ten->blocked, 'the tenth locks the account');
same(LockoutState::KEY_ACCOUNT, $ten->key, 'on the account key');
ok($ten->refusesVerification(), 'and the handler must not even reach the password hasher');
same(
    $t0->modify('+15 minutes')->format(DateTimeInterface::ATOM),
    $ten->blockedUntil?->format(DateTimeInterface::ATOM),
    'for lock_duration_minutes from the most recent failure',
);

group('LockoutPolicy — the window expires');

// The same ten failures, spaced five minutes apart, so only four fall inside
// the fifteen-minute window ending at the newest.
$spread = $policy->evaluate($failures(10, 300), [], 0, $at('+1 minute'));

ok(!$spread->blocked, 'ten failures spread beyond the window do not lock');
same(4, $spread->failuresInWindow, 'only the four inside the window are counted');

// And a lock, once served, releases by expiry (R-XXII-191).
ok($policy->evaluate($failures(10), [], 0, $at('+14 minutes'))->blocked, 'the lock holds for its duration');
ok(!$policy->evaluate($failures(10), [], 0, $at('+16 minutes'))->blocked, 'and clears by expiry afterwards');

group('LockoutPolicy — progressive backoff');

same(15, $policy->lockDurationMinutes(0), 'the first lock is 15 minutes');
same(30, $policy->lockDurationMinutes(1), 'the second doubles to 30');
same(60, $policy->lockDurationMinutes(2), 'the third to 60');
same(120, $policy->lockDurationMinutes(3), 'the fourth to 120');
same(240, $policy->lockDurationMinutes(4), 'the fifth to 240');
same(240, $policy->lockDurationMinutes(9), 'and it caps there — an unbounded backoff is a permanent lock with a friendly name');
same(240, $policy->lockDurationMinutes(400), 'including at an absurd exponent, which is not computed and discarded');

$third = $policy->evaluate($failures(10), [], 2, $at('+30 minutes'));
ok($third->blocked, 'a third lock is still in force at +30 minutes, where the first would have cleared');
same(
    $t0->modify('+60 minutes')->format(DateTimeInterface::ATOM),
    $third->blockedUntil?->format(DateTimeInterface::ATOM),
    'because its duration was the backed-off 60 minutes',
);

$flat = LockoutPolicy::forTenant(['progressiveBackoff' => false]);
same(15, $flat->lockDurationMinutes(5), 'with backoff off, every lock is the base duration');

group('LockoutPolicy — per-source and per-account are separate, and source goes first');

// R-XXII-192: five failures from ONE source trips the source key while the
// account is nowhere near its threshold of ten. The victim is not locked out.
$oneSource = $policy->evaluate($failures(5), $failures(5), 0, $at('+1 minute'));

ok($oneSource->blocked, 'five failures from one source are blocked');
same(LockoutState::KEY_SOURCE, $oneSource->key, 'on the SOURCE key, not the account');
ok(
    !$policy->evaluate($failures(5), [], 0, $at('+1 minute'))->blocked,
    'and the account itself is untouched — a colleague cannot lock you out before a meeting',
);
ok(
    $policy->sourceThreshold < $policy->threshold,
    'which works only because the source threshold sits below the account threshold',
);

// Distributed failure is what account lockout is actually for: ten failures on
// the account, spread thinly enough that no single source is blocked.
$distributed = $policy->evaluate($failures(10), $failures(2), 0, $at('+1 minute'));

ok($distributed->blocked, 'distributed failure still locks the account');
same(LockoutState::KEY_ACCOUNT, $distributed->key, 'on the account key, which no per-source limit could see');

$accountFirst = LockoutPolicy::forTenant(['sourceFirst' => false]);
same(
    LockoutState::KEY_ACCOUNT,
    $accountFirst->evaluate($failures(10), $failures(10), 0, $at('+1 minute'))->key,
    'the ordering is configuration — a tenant behind one NAT gateway may invert it',
);

group('LockoutPolicy — the floor');

ok(LockoutPolicy::forTenant(['threshold' => 3])->threshold === 3, 'a tenant may lock sooner than the floor');
foreach ([
    [['threshold' => 50], 'a threshold above the floor'],
    [['lockMinutes' => 1], 'a lock shorter than the floor'],
    [['sourceThreshold' => 100], 'a source throttle above the floor'],
    [['uniformDelayMilliseconds' => 0], 'a uniform delay below the floor, which reopens the timing oracle'],
] as [$override, $what]) {
    throws(
        DomainException::class,
        static fn () => LockoutPolicy::forTenant($override),
        "a tenant cannot loosen the lockout floor with {$what}",
    );
}

throws(
    InvalidArgumentException::class,
    static fn () => LockoutPolicy::forTenant(['threshold' => 0]),
    'a threshold of zero — which would lock every account on its first attempt — is refused',
);

// ---------------------------------------------------------------------------
// R-XXII-193. A locked account and an account that does not exist must be
// indistinguishable to the caller. SmartPRS 2's login path returns a distinct
// message for a disabled account, which is a free account-existence oracle on
// the one endpoint an attacker is already pointed at.
// ---------------------------------------------------------------------------

group('LockoutPolicy — a locked account and an unknown account are indistinguishable');

$clock = $at('+1 minute');

$locked = $policy->evaluate($failures(10), [], 0, $clock);
$unknown = $policy->evaluate([], [], 0, $clock, accountExists: false);
$wrongPassword = $policy->evaluate($failures(1), [], 0, $clock);
$throttledSource = $policy->evaluate($failures(1), $failures(5), 0, $clock);

ok($locked->blocked, 'the first account is locked');
ok(!$unknown->accountExists, 'the second does not exist');
ok(!$wrongPassword->blocked, 'the third simply mistyped');
ok($throttledSource->blocked, 'the fourth is coming from a throttled source');

$response = $locked->publicResponse();

foreach (['unknown account' => $unknown, 'wrong password' => $wrongPassword, 'throttled source' => $throttledSource] as $label => $state) {
    same($response, $state->publicResponse(), "the {$label} response is byte-identical to the locked-account response");
}

same(LockoutState::UNIFORM_FAILURE, $response['reason'], 'there is one reason code for every failure mode');
same(401, $response['status'], 'and one status code — a 429 for a lock is the same oracle in a different hat');
ok(!array_key_exists('retry_after', $response), 'and no Retry-After, which would say "this one is locked"');
ok(
    !str_contains(strtolower($response['message']), 'lock')
    && !str_contains(strtolower($response['message']), 'exist')
    && !str_contains(strtolower($response['message']), 'too many'),
    'the message names neither the lock nor the account\'s existence',
);

// The operator, on the other side of the audit boundary, sees everything.
ok($locked->toAudit() !== $unknown->toAudit(), 'the AUDIT rows do differ — the operator is not the attacker');
same(true, $locked->toAudit()['blocked'], 'the audit row records the block');
same(false, $unknown->toAudit()['account_exists'], 'and records that the account did not exist');
same(LockoutState::KEY_ACCOUNT, $locked->toAudit()['key'], 'and which key tripped');

// ---------------------------------------------------------------------------
// MFA. R-XXII-194: mandatory for every holder of a privileged or platform
// risk-class permission, resolved by RISK CLASS and never by role name.
// ---------------------------------------------------------------------------

group('MfaRequirement — mandatory for privileged holders, denied when not enrolled');

$mfa = new MfaRequirement(MfaRequirement::seededRiskClasses());

$clerk = Role::publish('CLERK', 'Attendance Clerk', 1, false, ['attendance.day.view', 'leave.request.submit']);
$approver = Role::publish('APPROVER', 'Payroll Approver', 3, false, ['payroll.run.approve', 'payroll.run.view']);
$platformStaff = Role::publish('PLATFORM_OPS', 'Platform Operations', 1, true, ['platform.tenant.suspend']);

// A tenant-invented role that happens to carry a privileged permission is in
// scope automatically. This is the whole point of resolving by risk class:
// SmartPRS 2 makes 62 decisions by comparing role NAME strings.
$innocuous = Role::publish('ASSISTANT', 'Assistant', 1, false, ['payroll.run.approve']);

ok(!$mfa->isMandatoryFor([$clerk]), 'a routine role does not mandate MFA');
ok($mfa->isMandatoryFor([$approver]), 'a role carrying a privileged permission does');
ok($mfa->isMandatoryFor([$platformStaff]), 'and so does a platform-class one');
ok($mfa->isMandatoryFor([$innocuous]), 'RESOLVED BY RISK CLASS: a role called "Assistant" carrying payroll.run.approve is in scope');
ok($mfa->isMandatoryFor([$clerk, $approver]), 'and one privileged assignment among several puts the actor in scope');

same(MfaRequirement::RISK_PRIVILEGED, $mfa->riskClassOf('payroll.run.approve'), 'payroll.run.approve is privileged');
same(MfaRequirement::RISK_PLATFORM, $mfa->riskClassOf('platform.tenant.suspend'), 'platform.tenant.suspend is platform');
same(MfaRequirement::RISK_ROUTINE, $mfa->riskClassOf('attendance.day.view'), 'and an ordinary read is routine');
same(['payroll.run.approve'], $mfa->mandatingPermissions([$approver]), 'the notice can name exactly why');

// DENIED, not warned.
same(
    MfaRequirement::NOT_ENROLLED,
    $mfa->assess([$approver], enrolled: false, sessionSatisfiedMfa: false),
    'a PRIVILEGED ACTOR WITHOUT MFA IS DENIED',
);
same(
    MfaRequirement::NOT_ENROLLED,
    $mfa->assess([$innocuous], enrolled: false, sessionSatisfiedMfa: false),
    'including through a custom role nobody thought to flag',
);
same(
    MfaRequirement::REQUIRED,
    $mfa->assess([$approver], enrolled: true, sessionSatisfiedMfa: false),
    'enrolled but not presented this session is MFA_REQUIRED, not a silent upgrade (R-XXII-198)',
);
same(
    null,
    $mfa->assess([$approver], enrolled: true, sessionSatisfiedMfa: true),
    'and an enrolled actor who presented the factor may proceed',
);
same(
    null,
    $mfa->assess([$clerk], enrolled: false, sessionSatisfiedMfa: false),
    'a routine actor is not forced to enrol',
);
same(
    MfaRequirement::NOT_ENROLLED,
    $mfa->assess([$clerk], enrolled: false, sessionSatisfiedMfa: false, operation: 'payroll.run.approve'),
    'but a routine actor attempting a privileged operation is still denied',
);

group('MfaRequirement — re-authentication and the grace period');

same(
    MfaRequirement::REAUTH_REQUIRED,
    $mfa->assess([$clerk], true, true, reauthSecondsRequired: 900, secondsSinceReauth: 901),
    'assurance = reauth(900) refuses a session that re-authed 901 seconds ago',
);
same(
    null,
    $mfa->assess([$clerk], true, true, reauthSecondsRequired: 900, secondsSinceReauth: 900),
    'and admits one exactly on the boundary',
);
same(
    MfaRequirement::REAUTH_REQUIRED,
    $mfa->assess([$clerk], true, true, reauthSecondsRequired: 900, secondsSinceReauth: null),
    'a session that has never re-authed does not satisfy a reauth requirement',
);

$became = new DateTimeImmutable('2026-09-01T00:00:00Z');
same(
    '2026-09-15',
    (new MfaRequirement(MfaRequirement::seededRiskClasses(), gracePeriodDays: 14))
        ->assignmentSuspendsAt($became)->format('Y-m-d'),
    'the grace period suspends the ASSIGNMENT — it never lets a privileged operation through',
);

// A tenant may add roles beyond the automatic privileged set; it may only add.
$extra = new MfaRequirement(MfaRequirement::seededRiskClasses(), enforcedRoleCodes: ['CLERK']);
ok($extra->isMandatoryFor([$clerk]), 'enforced_for_roles[] puts an otherwise routine role in scope');
ok($mfa->isMandatoryFor([$approver]), 'and removing a role from that list cannot take a privileged one out of scope');

throws(
    InvalidArgumentException::class,
    static fn () => new MfaRequirement([], mandatoryRiskClasses: ['very-risky']),
    'an unknown risk class is refused (R-XXII-011 fixes the vocabulary)',
);

// ---------------------------------------------------------------------------
// Sessions. SmartPRS 2: 120-minute idle timeout, NO absolute cap, remember-me
// at the framework's five-year default, no SESSION_SECURE_COOKIE default.
// ---------------------------------------------------------------------------

group('SessionPolicy — idle timeout and the absolute cap');

$session = SessionPolicy::tenantDefault();
$startedAt = new DateTimeImmutable('2026-09-04T08:00:00Z');

$sessionAt = static fn (string $instant): FrozenClock => FrozenClock::at($instant);

same(
    null,
    $session->expiredReason($startedAt, new DateTimeImmutable('2026-09-04T09:00:00Z'), $sessionAt('2026-09-04T10:30:00Z')),
    'a session seen 90 minutes ago is still live under a 120-minute idle timeout',
);
same(
    SessionPolicy::EXPIRED_IDLE,
    $session->expiredReason($startedAt, new DateTimeImmutable('2026-09-04T09:00:00Z'), $sessionAt('2026-09-04T11:01:00Z')),
    'and expires once the idle timeout passes',
);
same(
    SessionPolicy::EXPIRED_ABSOLUTE,
    $session->expiredReason($startedAt, new DateTimeImmutable('2026-09-04T19:59:00Z'), $sessionAt('2026-09-04T20:01:00Z')),
    'and a continuously active session STILL ends at the 12-hour absolute cap',
);
ok($session->absoluteLifetimeMinutes > 0, 'R-XXII-205: the absolute lifetime is not optional');

group('SessionPolicy — cookie flags');

$flags = $session->cookieFlags();

same(true, $flags['secure'], 'Secure is set, with no environment variable that can unset it');
same(true, $flags['httponly'], 'HttpOnly is set');
same('Lax', $flags['samesite'], 'SameSite is Lax');
same('__Host-session', $flags['name'], 'and the __Host- prefix makes the browser enforce the other three');
same(null, $flags['domain'], 'with no Domain, as __Host- requires');

$loopback = $session->cookieFlags(loopbackDevelopment: true);
same(false, $loopback['secure'], 'a declared local loopback is the only exception');
same('session', $loopback['name'], 'and it drops the prefix rather than shipping a cookie the browser discards');

group('SessionPolicy — no five-year remember-me');

ok(!$session->rememberMeSatisfiesAssurance(), 'a remembered device never satisfies an assurance requirement (R-XXII-210)');
same(30, $session->rememberDeviceDays, 'remember-device is bounded at 30 days by default');
same(90, SessionPolicy::MAX_REMEMBER_DEVICE_DAYS, 'and at 90 by the platform, whatever a tenant configures');
throws(
    InvalidArgumentException::class,
    static fn () => SessionPolicy::forTenant(['rememberDeviceDays' => 1_825]),
    'the five-year remember-me cannot be reconstructed',
);
ok(
    !SessionPolicy::platformAccountFloor()->rememberDeviceEnabled,
    'and it is never available for a platform account (R-XXII-197)',
);

group('SessionPolicy — the floor, concurrency and re-auth');

foreach ([
    [['absoluteLifetimeMinutes' => 100_000], 'an absolute lifetime above the floor'],
    [['idleTimeoutMinutes' => 600], 'an idle timeout above the floor'],
    [['concurrentSessionLimit' => 100], 'a concurrent-session limit above the floor'],
    [['encryptPayload' => false], 'session payload encryption switched off (R-XXII-207)'],
    [['regenerateOnPrivilegeChange' => false], 'session-id regeneration switched off'],
] as [$override, $what]) {
    throws(
        DomainException::class,
        static fn () => SessionPolicy::forTenant($override),
        "a tenant cannot loosen the session floor with {$what}",
    );
}

ok(SessionPolicy::forTenant(['idleTimeoutMinutes' => 15])->idleTimeoutMinutes === 15, 'but may tighten it freely');

throws(
    InvalidArgumentException::class,
    static fn () => SessionPolicy::forTenant(['absoluteLifetimeMinutes' => 30, 'idleTimeoutMinutes' => 120]),
    'an absolute lifetime below the idle timeout would make the idle timeout decoration',
);

same(['allow' => true, 'evict' => 0], $session->admitSession(4), 'a fifth session is admitted under a limit of five');
same(['allow' => true, 'evict' => 1], $session->admitSession(5), 'a sixth evicts the oldest, as configured');
same(
    ['allow' => false, 'evict' => 0],
    SessionPolicy::forTenant(['onLimit' => SessionPolicy::ON_LIMIT_DENY_NEW])->admitSession(5),
    'or is denied, where the tenant chose deny_new',
);

ok($session->needsReauth(901), 'a privileged operation 901 seconds after re-auth needs another');
ok(!$session->needsReauth(900), 'and not one exactly on the boundary');
ok($session->needsReauth(null), 'a session that never re-authed always needs one');

// ---------------------------------------------------------------------------
// No default credential. R-XXII-227 / R-XXII-229 / R-XXII-223.
// ---------------------------------------------------------------------------

group('CredentialSeeding — the first administrator cannot be given a literal');

$seedPolicy = PasswordPolicy::platformAccountFloor();
$hasher = PasswordHasher::bcrypt(4);
$noCorpus = new NullBreachCheck();

// THE STRUCTURAL RULE: there is no string parameter for a password anywhere on
// this class, so a literal is a TypeError before any policy runs.
throws(
    TypeError::class,
    static fn () => CredentialSeeding::prompted('password'),
    'a literal cannot be passed at all — prompted() takes a reader, not a secret',
);
throws(
    TypeError::class,
    static fn () => CredentialSeeding::prompted('a-perfectly-strong-passphrase-2026'),
    'and that is true of a STRONG literal too, which is the point',
);

$constructors = array_map(
    static fn (ReflectionMethod $m): string => $m->getName(),
    (new ReflectionClass(CredentialSeeding::class))->getMethods(ReflectionMethod::IS_PUBLIC),
);
same(
    ['prompted', 'generated', 'createFirstAdministrator'],
    $constructors,
    'the class has exactly two constructors and neither accepts a secret',
);
ok(
    !(new ReflectionMethod(CredentialSeeding::class, '__construct'))->isPublic(),
    'and the constructor itself is private, so there is no third way in',
);

// A reader that returns a literal is caught by the R-XXII-230 deny list.
foreach (['password', 'smartprs-demo', 'ametecs', 'admin123', 'Welcome123'] as $shipped) {
    throws(
        DomainException::class,
        static fn () => CredentialSeeding::prompted(static fn (): string => $shipped)
            ->createFirstAdministrator('admin@acme.example', $seedPolicy, $hasher, $noCorpus),
        "a reader returning the historical credential '{$shipped}' is refused",
    );
}

throws(
    DomainException::class,
    static fn () => CredentialSeeding::prompted(static fn (): string => 'short')
        ->createFirstAdministrator('admin@acme.example', $seedPolicy, $hasher, $noCorpus),
    'and so is anything else the platform-account policy refuses',
);

group('CredentialSeeding — the two paths that do work');

$generated = CredentialSeeding::generated()->createFirstAdministrator(
    'admin@acme.example',
    $seedPolicy,
    $hasher,
    $noCorpus,
    tenantName: 'Acme Manufacturing',
);

ok(is_string($generated['display_once']), 'the generated path returns the secret to show once');
ok(strlen((string) $generated['display_once']) >= $seedPolicy->minLength, 'and it satisfies the platform-account floor');
ok($hasher->verify((string) $generated['display_once'], $generated['password_hash']), 'the stored hash verifies against it');
ok(!str_contains($generated['password_hash'], (string) $generated['display_once']), 'and the hash is not the secret');
ok(
    CredentialSeeding::generated()->createFirstAdministrator('admin@acme.example', $seedPolicy, $hasher, $noCorpus)['display_once']
        !== $generated['display_once'],
    'two generated secrets differ — there is no default credential to publish in a README',
);

$typed = CredentialSeeding::prompted(static fn (): string => 'nineteen-lemons-turn-slowly')
    ->createFirstAdministrator('admin@acme.example', $seedPolicy, $hasher, $noCorpus);

same(null, $typed['display_once'], 'the prompted path echoes NOTHING back — the operator already knows it');
ok($hasher->verify('nineteen-lemons-turn-slowly', $typed['password_hash']), 'and the hash is of what they typed');
same('admin@acme.example', $typed['email'], 'the account is the address given');

group('CredentialSeeding — the guards');

throws(
    DomainException::class,
    static fn () => CredentialSeeding::generated()->createFirstAdministrator(
        'admin@acme.example',
        $seedPolicy,
        $hasher,
        $noCorpus,
        anyAdministratorExists: true,
    ),
    'R-XXII-227: an administrator that already exists is never RESET by this path either',
);

throws(
    DomainException::class,
    static fn () => CredentialSeeding::generated()
        ->createFirstAdministrator('admin at smartprs', $seedPolicy, $hasher, $noCorpus),
    'a first administrator needs a real address — an activation must be able to reach it',
);

// `admin@smartprs.local` is a legitimate address on an on-premise install. What
// made it a defect in SmartPRS 2 was the credential attached to it and the
// README that published the pair, and both are gone: this account exists only
// with a secret nobody outside the installation has ever seen.
$onPrem = CredentialSeeding::generated()
    ->createFirstAdministrator('admin@smartprs.local', $seedPolicy, $hasher, $noCorpus);

ok(!$hasher->verify('password', $onPrem['password_hash']), 'admin@smartprs.local / password no longer authenticates anything');

throws(
    DomainException::class,
    static fn () => CredentialSeeding::generated()
        ->createFirstAdministrator('admin@acme.example', PasswordPolicy::forTenant(), $hasher, null),
    'and a policy that breach-checks, wired to no corpus, refuses rather than seeding blind',
);

throws(
    InvalidArgumentException::class,
    static fn () => CredentialSeeding::generated(8),
    'a generated secret below 128 bits is refused',
);
