<?php
/**
 * THE WEBHOOK ENGINE GATE — Part XXIV §XXIV.4.
 *
 *   php tools/api/webhook-gate.php
 *
 * Four properties, each answering a recorded SmartPRS 2 finding:
 *
 *   1. The signature covers the timestamp and the nonce — SEC-C-16, where it
 *      covers neither and the only replay protection is an index that is inert
 *      wherever `tenant_id` is NULL.
 *   2. Replay is rejected at the transport layer, with nonces retained for at
 *      least twice the clock tolerance.
 *   3. Retry is exponential with FULL jitter and honours `Retry-After` —
 *      SmartPRS 2 has no retry anywhere in its codebase.
 *   4. Nothing is dropped silently: a delivery that could not be attempted at
 *      all is recorded with that classification — DEF-C-14, where a failed pull
 *      is printed to the console and vanishes.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../tests/lib/bootstrap.php';

use SmartPRS\Platform\Webhook\DeliveryEngine;
use SmartPRS\Platform\Webhook\ReplayGuard;
use SmartPRS\Platform\Webhook\RetrySchedule;
use SmartPRS\Platform\Webhook\Signature;

$results = [];

echo "SmartPRS 3 — webhook engine gate (Part XXIV §XXIV.4)\n";

// =============================================================================
// 1. THE SIGNATURE COVERS THE TIMESTAMP AND THE NONCE
// =============================================================================

$problems = [];
$secret = Signature::generateSecret();
$body = '{"event":"payroll.run.approved","id":"019372ab"}';
$now = 1785312131;
$header = Signature::sign($secret, $now, 'nonce-1', $body);

if (!str_starts_with($header, 'v1=')) {
    $problems[] = 'the signature carries no version prefix. Without one the algorithm can never be '
        . 'migrated without breaking every receiver at once';
}

if (!Signature::verify($header, [$secret], $now, 'nonce-1', $body, $now)) {
    $problems[] = 'a correct signature did not verify';
}

// THE FINDING: changing the timestamp or the nonce must invalidate it. A
// signature over the body alone authenticates the content and says nothing
// about the occasion.
if (Signature::verify($header, [$secret], $now + 1, 'nonce-1', $body, $now + 1)) {
    $problems[] = 'the signature does not cover the TIMESTAMP. Anyone who captures one valid delivery can '
        . 'replay it forever, and every replay verifies perfectly — this is SEC-C-16 exactly';
}

if (Signature::verify($header, [$secret], $now, 'nonce-2', $body, $now)) {
    $problems[] = 'the signature does not cover the NONCE';
}

if (Signature::verify($header, [$secret], $now, 'nonce-1', $body . ' ', $now)) {
    $problems[] = 'the signature does not cover the body';
}

if (Signature::verify($header, [Signature::generateSecret()], $now, 'nonce-1', $body, $now)) {
    $problems[] = 'a signature verified against the wrong secret';
}

// Freshness is checked even when the signature is perfect.
if (Signature::verify($header, [$secret], $now, 'nonce-1', $body, $now + 3600)) {
    $problems[] = 'an hour-old delivery verified. A signature that verifies is not a delivery that is '
        . 'current';
}

// During rotation, BOTH secrets are offered so a receiver that has taken the new
// one and a receiver that has not are both satisfied.
$successor = Signature::generateSecret();
$dual = Signature::header([$successor, $secret], $now, 'nonce-3', $body);

foreach ([['the successor', $successor], ['the outgoing secret', $secret]] as [$which, $key]) {
    if (!Signature::verify($dual, [$key], $now, 'nonce-3', $body, $now)) {
        $problems[] = "during rotation a receiver holding {$which} could not verify. Rotation that breaks "
            . 'one side is rotation nobody performs';
    }
}

if (strlen(Signature::generateSecret()) < 64) {
    $problems[] = 'the generated secret is under 256 bits (R-XXIV-093)';
}

// Constant-time comparison, asserted structurally: an early-returning comparison
// leaks how much of the signature was right, one byte at a time.
$source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Platform/Webhook/Signature.php');

if (!str_contains($source, 'hash_equals')) {
    $problems[] = 'the signature comparison is not constant-time';
}

if (preg_match('/if\s*\(\s*\$expected\s*===\s*/', $source) === 1) {
    $problems[] = 'the signature is compared with ===, which returns as soon as two bytes differ';
}

$results[] = [
    'name' => 'The delivery signature covers the timestamp and the nonce, not only the body',
    'problems' => $problems,
    'detail' => 'v1=hex(hmac_sha256("v1.timestamp.nonce.body")); changing the timestamp, the nonce, the '
        . 'body or the secret each invalidates it; an hour-old delivery is refused even with a perfect '
        . 'signature; both secrets verify during a rotation overlap; and the comparison is constant-time '
        . '— the one property SmartPRS 2 already gets right, and the only one kept',
];

// =============================================================================
// 2. REPLAY IS REJECTED AT THE TRANSPORT LAYER
// =============================================================================

$problems = [];
$guard = new ReplayGuard(toleranceSeconds: 300);

if (!$guard->accept('ep-1', 'n-1', $now, $now)['accepted']) {
    $problems[] = 'a fresh, unseen nonce was refused';
}

$second = $guard->accept('ep-1', 'n-1', $now, $now + 1);

if ($second['accepted']) {
    $problems[] = 'the SAME nonce was accepted twice. Freshness alone lets an attacker replay a captured '
        . 'request as often as the window allows';
}

if ($guard->accept('ep-1', 'n-2', $now - 3600, $now)['accepted']) {
    $problems[] = 'a request an hour old was accepted. Uniqueness alone lets an attacker replay one from '
        . 'last year';
}

if (!$guard->accept('ep-2', 'n-1', $now, $now)['accepted']) {
    $problems[] = 'a nonce used on a DIFFERENT endpoint was refused; nonces are scoped per endpoint';
}

if ($guard->accept('ep-1', '  ', $now, $now)['accepted']) {
    $problems[] = 'a request with no nonce was accepted, so nothing distinguishes it from a replay of '
        . 'itself';
}

// THE ARITHMETIC: retention is at least twice the tolerance, so there is no
// window in which a captured request is both fresh enough and forgotten enough.
if ($guard->retentionSeconds() < 300 * 2) {
    $problems[] = 'nonces are retained for ' . $guard->retentionSeconds() . 's against a 300s tolerance. '
        . 'Anything under twice the tolerance leaves a gap where a replay is both accepted as fresh and '
        . 'forgotten as new';
}

$short = new ReplayGuard(toleranceSeconds: 300, retentionSeconds: 60);

if ($short->retentionSeconds() < 600) {
    $problems[] = 'a caller asked for a retention shorter than twice the tolerance and got it';
}

$results[] = [
    'name' => 'Replay is rejected at the transport layer, independent of the handler',
    'problems' => $problems,
    'detail' => 'a repeated nonce, a stale timestamp and an absent nonce are each refused with the reason; '
        . 'nonces are scoped per endpoint; and retention is floored at twice the clock tolerance even when '
        . 'a caller asks for less — the gap between the two is exactly where a replay is both fresh and '
        . 'forgotten',
];

// =============================================================================
// 3. RETRY: EXPONENTIAL, FULL JITTER, AND Retry-After WINS
// =============================================================================

$problems = [];
$schedule = new RetrySchedule();

if ($schedule->attempts() !== 9) {
    $problems[] = 'the default schedule has ' . $schedule->attempts() . ' attempts, expected 9';
}

$window = $schedule->windowSeconds();

if ($window < 46 * 3600 * 0.9 || $window > 46 * 3600 * 1.1) {
    $problems[] = 'the nominal window is ' . round($window / 3600, 1) . 'h, expected roughly 46h';
}

// FULL jitter, asserted as a DISTRIBUTION rather than trusted from one draw: a
// uniform sample across the whole interval, not a wobble around the nominal.
$draws = [];

for ($i = 0; $i < 400; $i++) {
    $draws[] = $schedule->nextDelay(4)['delay'];      // nominal 3600
}

$min = min($draws);
$max = max($draws);
$mean = array_sum($draws) / count($draws);

if ($min > 3600 * 0.15 || $max < 3600 * 0.85) {
    $problems[] = "the jitter spans {$min}s to {$max}s of a 3600s interval. FULL jitter draws uniformly "
        . 'across the whole interval: a receiver that comes back up must not be hit by every queued '
        . 'delivery in the same second, which is a thundering herd the SENDER caused';
}

if (abs($mean - 1800) > 300) {
    $problems[] = 'the jitter mean is ' . round($mean) . 's against an expected ~1800s for a uniform draw';
}

// The receiver's own Retry-After wins.
$honoured = $schedule->nextDelay(2, retryAfter: 45);

if ($honoured['delay'] !== 45 || !str_contains($honoured['source'], 'receiver')) {
    $problems[] = 'Retry-After was not honoured. The receiver knows something about their own recovery '
        . 'that a schedule cannot, and overriding it is how a 429 becomes an outage';
}

$clamped = $schedule->nextDelay(2, retryAfter: 999999);

if ($clamped['delay'] !== 86400 || !str_contains($clamped['source'], 'clamped')) {
    $problems[] = 'an absurd Retry-After was not clamped, or was clamped silently. A week-long delay '
        . 'parks a delivery past its own retention';
}

try {
    $schedule->nextDelay(99);
    $problems[] = 'the schedule offered a delay past its own end. An exhausted delivery dead-letters — it '
        . 'does not get another delay';
} catch (LogicException) {
    // Refused, as it must be.
}

try {
    new RetrySchedule([]);
    $problems[] = 'an empty schedule was accepted: the event would neither deliver nor dead-letter, it '
        . 'would simply disappear';
} catch (InvalidArgumentException) {
    // Refused, as it must be.
}

$results[] = [
    'name' => 'Retry is exponential with full jitter, and the receiver\'s Retry-After wins',
    'problems' => $problems,
    'detail' => '9 attempts over ~' . round($window / 3600) . 'h; jitter verified as a DISTRIBUTION over '
        . '400 draws (min ' . $min . 's, max ' . $max . 's, mean ' . round($mean) . 's of a 3600s '
        . 'interval) rather than trusted from one lucky sample; Retry-After is honoured and an absurd one '
        . 'is clamped WITH the clamp stated; and an exhausted or empty schedule is refused',
];

// =============================================================================
// 4. NOTHING IS DROPPED SILENTLY
// =============================================================================

$problems = [];
$engine = new DeliveryEngine($schedule);
$ok = static fn (): array => ['status' => 200, 'retry_after' => null, 'body' => ''];

$delivered = $engine->deliver('sub-1', 'evt-1', 'payroll.run.approved', '{}', 0, $ok, $now);

if ($delivered['outcome'] !== DeliveryEngine::DELIVERED) {
    $problems[] = 'a 2xx was not recorded as delivered';
}

// THE FINDING: a delivery that could not be attempted AT ALL is still recorded.
$refused = $engine->deliver('sub-2', 'evt-2', 'x', '{}', 0, $ok, $now, egressAllowed: false);

if ($refused['outcome'] !== DeliveryEngine::NOT_ATTEMPTED) {
    $problems[] = 'an egress refusal was not recorded as an attempt. DEF-C-14 is exactly this: a failed '
        . 'pull printed to the console, vanishing — and an integration discovered to have been dead for '
        . 'three weeks';
}

$noSecret = $engine->deliver('sub-3', 'evt-3', 'x', '{}', 0, $ok, $now, secretPresent: false);

if ($noSecret['outcome'] !== DeliveryEngine::NOT_ATTEMPTED) {
    $problems[] = 'a missing signing secret was not recorded as an attempt';
}

// Classification, because the operator response differs by class.
foreach ([
    [500, DeliveryEngine::FAILED_RECEIVER, 'their problem, usually transient'],
    [401, DeliveryEngine::FAILED_AUTH, 'a credential to rotate'],
    [422, DeliveryEngine::FAILED_CLIENT, 'a contract mismatch retrying will not fix'],
    [0, DeliveryEngine::FAILED_TIMEOUT, 'no response inside the timeout'],
] as $index => [$status, $expected, $meaning]) {
    $outcome = $engine->deliver("sub-c{$index}", "evt-c{$index}", 'x', '{}', 0,
        static fn (): array => ['status' => $status, 'retry_after' => null, 'body' => ''], $now);

    if ($outcome['outcome'] !== $expected) {
        $problems[] = "HTTP {$status} classified as {$outcome['outcome']}, expected {$expected} "
            . "({$meaning}). \"Failed 47 times\" tells an operator to look; \"failed 47 times with 401\" "
            . 'tells them what to do';
    }
}

$thrown = $engine->deliver('sub-4', 'evt-4', 'x', '{}', 0,
    static fn (): array => throw new RuntimeException('name resolution failed'), $now);

if ($thrown['outcome'] !== DeliveryEngine::FAILED_DNS) {
    $problems[] = 'an unreachable endpoint threw out of the engine rather than becoming a recorded attempt';
}

// Exhaustion dead-letters, keeping the ORIGINAL event uuid.
$fail = static fn (): array => ['status' => 503, 'retry_after' => null, 'body' => ''];
$last = null;

for ($attempt = 0; $attempt < $schedule->attempts(); $attempt++) {
    $last = $engine->deliver('sub-5', 'evt-5', 'leave.approved', '{}', $attempt, $fail, $now);
}

if ($last['outcome'] !== DeliveryEngine::DEAD_LETTERED) {
    $problems[] = 'an exhausted delivery did not dead-letter';
}

if (!isset($engine->deadLetters()['evt-5'])) {
    $problems[] = 'the dead-lettered delivery is not in the queue, so it is not visible or replayable';
}

if (($engine->replay('evt-5')['event_uuid'] ?? '') !== 'evt-5') {
    $problems[] = 'a replay does not preserve the original event uuid. A replay with a fresh id is a '
        . 'SECOND event, and a receiver doing its job correctly acts on it twice';
}

try {
    $engine->replay('evt-never-existed');
    $problems[] = 'a replay of an event that was never dead-lettered invented one';
} catch (InvalidArgumentException) {
    // Refused, as it must be.
}

// Consecutive failures drive the automatic disable, and a success resets it.
$health = new DeliveryEngine($schedule, consecutiveFailureThreshold: 3);

for ($i = 0; $i < 3; $i++) {
    $health->deliver('sub-h', "evt-h{$i}", 'x', '{}', 0, $fail, $now);
}

if (!$health->shouldDisable('sub-h')) {
    $problems[] = 'a subscription failing past its threshold was not marked for disabling';
}

$health->deliver('sub-h', 'evt-h-ok', 'x', '{}', 0, $ok, $now);

if ($health->shouldDisable('sub-h')) {
    $problems[] = 'a successful delivery did not reset the consecutive-failure count, so one bad hour '
        . 'would disable a subscription that has been healthy since';
}

$results[] = [
    'name' => 'Nothing is dropped silently, and every failure is classified',
    'problems' => $problems,
    'detail' => count($engine->attempts()) . ' attempts recorded including the two that could NOT be '
        . 'attempted (egress refused, no secret); 5xx, 401, 4xx, timeout and DNS are separately '
        . 'classified; exhaustion dead-letters into a visible queue that preserves the original event '
        . 'uuid so a replay de-duplicates; and a success resets the consecutive-failure count',
];

// =============================================================================

$failed = 0;

foreach ($results as $index => $result) {
    if ($result['problems'] !== []) {
        $failed++;
        printf("\n  %d. \033[31mFAIL\033[0m  %s\n", $index + 1, $result['name']);

        foreach ($result['problems'] as $problem) {
            echo "        └─ {$problem}\n";
        }

        continue;
    }

    printf("\n  %d. \033[32mPASS\033[0m  %s\n        %s\n", $index + 1, $result['name'], $result['detail']);
}

printf("\n%s  %d of %d webhook engine properties proven\n", $failed === 0 ? 'PASS' : 'FAIL',
    count($results) - $failed, count($results));

exit($failed === 0 ? 0 : 1);
