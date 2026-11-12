<?php

declare(strict_types=1);

use SmartPRS\Platform\Webhook\DeliveryEngine;
use SmartPRS\Platform\Webhook\ReplayGuard;
use SmartPRS\Platform\Webhook\RetrySchedule;
use SmartPRS\Platform\Webhook\Signature;

group('webhook');

$secret = Signature::generateSecret();
$body = '{"event":"payroll.run.approved"}';
$now = 1785312131;
$header = Signature::sign($secret, $now, 'n-1', $body);

ok(str_starts_with($header, 'v1='),
    'the signature carries a version prefix, so the algorithm can be migrated without breaking every '
    . 'receiver at once');
ok(Signature::verify($header, [$secret], $now, 'n-1', $body, $now), 'a correct signature verifies');

ok(!Signature::verify($header, [$secret], $now + 1, 'n-1', $body, $now + 1),
    'the signature covers the TIMESTAMP — SmartPRS 2\'s does not, so anyone who captures one valid '
    . 'delivery can replay it forever and every replay verifies perfectly');
ok(!Signature::verify($header, [$secret], $now, 'n-2', $body, $now), 'and the nonce');
ok(!Signature::verify($header, [$secret], $now, 'n-1', $body . ' ', $now), 'and the body');
ok(!Signature::verify($header, [$secret], $now, 'n-1', $body, $now + 3600),
    'and a perfect signature on an hour-old delivery is still refused: a signature that verifies is not '
    . 'a delivery that is current');

$successor = Signature::generateSecret();
$dual = Signature::header([$successor, $secret], $now, 'n-3', $body);
ok(Signature::verify($dual, [$successor], $now, 'n-3', $body, $now)
    && Signature::verify($dual, [$secret], $now, 'n-3', $body, $now),
    'during rotation both secrets verify, so neither side of the overlap breaks');

ok(strlen(Signature::generateSecret()) >= 64, 'secrets carry at least 256 bits');

// -----------------------------------------------------------------------------

$guard = new ReplayGuard(300);
ok($guard->accept('ep', 'n', $now, $now)['accepted'], 'a fresh unseen nonce is accepted');
ok(!$guard->accept('ep', 'n', $now, $now)['accepted'],
    'the same nonce twice is a replay — freshness alone lets an attacker replay as often as the window '
    . 'allows');
ok(!$guard->accept('ep', 'n2', $now - 3600, $now)['accepted'],
    'and a stale timestamp is refused — uniqueness alone lets them replay one from last year');
ok($guard->accept('other-ep', 'n', $now, $now)['accepted'], 'nonces are scoped per endpoint');
ok(!$guard->accept('ep', ' ', $now, $now)['accepted'], 'a request with no nonce is refused');
same(600, (new ReplayGuard(300, 60))->retentionSeconds(),
    'retention is floored at twice the tolerance whatever the caller asks for — the gap between them is '
    . 'exactly where a replay is both fresh enough to accept and forgotten enough to look new');

// -----------------------------------------------------------------------------

$schedule = new RetrySchedule();
same(9, $schedule->attempts(), 'nine attempts');

$draws = [];
for ($i = 0; $i < 200; $i++) { $draws[] = $schedule->nextDelay(4)['delay']; }

ok(min($draws) < 3600 * 0.2 && max($draws) > 3600 * 0.8,
    'FULL jitter draws across the whole interval, not a wobble around the nominal — a receiver coming '
    . 'back up must not be hit by every queued delivery in the same second');

same(45, $schedule->nextDelay(2, 45)['delay'],
    "the receiver's Retry-After wins: they know something about their own recovery that a schedule "
    . 'cannot, and overriding it is how a 429 becomes an outage');
same(86400, $schedule->nextDelay(2, 999999)['delay'],
    'and an absurd one is clamped, so a delivery cannot be parked past its own retention');

throws(LogicException::class, static fn () => $schedule->nextDelay(99),
    'an exhausted delivery dead-letters rather than getting another delay');
throws(InvalidArgumentException::class, static fn () => new RetrySchedule([]),
    'an empty schedule would neither deliver nor dead-letter — the event would simply disappear');

// -----------------------------------------------------------------------------

$engine = new DeliveryEngine($schedule);
$ok = static fn (): array => ['status' => 200, 'retry_after' => null, 'body' => ''];
$fail = static fn (): array => ['status' => 503, 'retry_after' => null, 'body' => ''];

same(DeliveryEngine::DELIVERED, $engine->deliver('s', 'e1', 'x', '{}', 0, $ok, $now)['outcome'],
    'a 2xx is delivered');
same(DeliveryEngine::NOT_ATTEMPTED,
    $engine->deliver('s', 'e2', 'x', '{}', 0, $ok, $now, egressAllowed: false)['outcome'],
    'a delivery that could not be attempted AT ALL is recorded with that classification — SmartPRS 2 '
    . 'prints a failed pull to the console where it vanishes, and the integration is found dead weeks later');
same(DeliveryEngine::FAILED_AUTH,
    $engine->deliver('s', 'e3', 'x', '{}', 0,
        static fn (): array => ['status' => 401, 'retry_after' => null, 'body' => ''], $now)['outcome'],
    'a 401 is classified as a credential to rotate, not a transient failure to wait out');
same(DeliveryEngine::FAILED_DNS,
    $engine->deliver('s', 'e4', 'x', '{}', 0,
        static fn (): array => throw new RuntimeException('no such host'), $now)['outcome'],
    'and an unreachable endpoint becomes a recorded attempt rather than an exception out of the engine');

$last = null;

for ($a = 0; $a < $schedule->attempts(); $a++) {
    $last = $engine->deliver('s5', 'e5', 'leave.approved', '{}', $a, $fail, $now);
}

ok($last !== null, 'the schedule ran at least one attempt to exhaust — a zero-attempt schedule '
    . 'would leave dead-lettering untested rather than merely untriggered');
same(DeliveryEngine::DEAD_LETTERED, $last['outcome'] ?? null, 'exhaustion dead-letters');
same('e5', $engine->replay('e5')['event_uuid'],
    'and the replay preserves the original event uuid, so a receiver that already processed it '
    . 'de-duplicates — a replay with a fresh id is a second event');

$health = new DeliveryEngine($schedule, 3);
for ($i = 0; $i < 3; $i++) { $health->deliver('h', "f{$i}", 'x', '{}', 0, $fail, $now); }
ok($health->shouldDisable('h'), 'consecutive failures past the threshold mark a subscription for disabling');
$health->deliver('h', 'good', 'x', '{}', 0, $ok, $now);
ok(!$health->shouldDisable('h'),
    'and a success resets the count, so one bad hour does not disable a subscription healthy since');
