<?php

declare(strict_types=1);

use SmartPRS\Platform\Api\Idempotency;
use SmartPRS\Surface\PunchImport;
use SmartPRS\Surface\Store;

/**
 * THE FIVE ROWS OF R-XXIV-032, AND THE TWO DIFFERENT JOBS THEY DO NOT DO.
 *
 * `Idempotency`'s docblock carries the table this file asserts:
 *
 *   first use                            execute; record the response BEFORE returning it
 *   replay, same fingerprint, completed  the STORED response, byte-identical
 *   replay, same fingerprint, in flight  409 idempotency.in_progress
 *   replay, DIFFERENT fingerprint        422 key_reuse_conflict — never executed
 *   key older than the window            treated as first use
 *
 * All five are exercised, and so is the rule underneath them: the record is
 * written in the SAME TRANSACTION as the effect, so `run()` refuses outright when
 * the caller has no transaction open. A design that records the key after a
 * successful commit is not idempotent under a crash between the two.
 *
 * AND THEN THE HALF IDEMPOTENCY CANNOT DO (R-XXIV-034). An idempotency key
 * answers "is this the SAME REQUEST arriving twice?". `PunchImport`'s
 * `external_id` answers "is this the SAME PUNCH arriving twice?". A client that
 * re-sends yesterday's export under a fresh key is not replaying, it is
 * duplicating — the key says first use, correctly, and only the data can see
 * that the punch is already there. Both halves are asserted, and so is the fact
 * that neither covers the other.
 *
 * NEVER `assert()`: `zend.assertions` is off here and a file written with it
 * passes without running a check.
 */

group('api idempotency — the five rows');

$keys = new Idempotency();
$ran = 0;
$work = static function () use (&$ran): array {
    $ran++;

    return ['imported' => 1, 'attempt' => $ran];
};

// 1 — FIRST USE.
$first = $keys->run('T-A', 'C-1', 'POST /api/v3/attendance-punches', 'k-1', ['a' => 1], $work,
    transactionOpen: true, now: 1_000);

same(Idempotency::FIRST_USE, $first['outcome'], 'a key never seen before is first use');
same(1, $ran, 'and the work ran, once');
ok(!$first['replayed'], 'and it does not claim to be a replay');

// 2 — REPLAY, SAME FINGERPRINT, COMPLETED.
$replay = $keys->run('T-A', 'C-1', 'POST /api/v3/attendance-punches', 'k-1', ['a' => 1], $work,
    transactionOpen: true, now: 1_001);

same(Idempotency::REPLAYED, $replay['outcome'], 'the same key with the same body is a replay');
same(1, $ran, 'and the work did NOT run again');
same($first['response'], $replay['response'],
    'the stored response comes back byte-identical rather than re-derived — re-running the read '
    . 'would answer today\'s data to yesterday\'s request');
ok($replay['replayed'], 'and it says so');

// 3 — REPLAY, SAME FINGERPRINT, IN FLIGHT. Reached by asking again from INSIDE
// the work, which is exactly the situation the row describes: the first attempt
// has not finished and the client has already retried.
$inFlightSaw = null;
$reentrant = new Idempotency();
$reentrant->run('T-A', 'C-1', 'E', 'k-2', ['a' => 1],
    static function () use ($reentrant, &$inFlightSaw): array {
        $inFlightSaw = $reentrant->run('T-A', 'C-1', 'E', 'k-2', ['a' => 1],
            static fn (): array => ['must not run' => true], transactionOpen: true, now: 1_000);

        return ['done' => true];
    }, transactionOpen: true, now: 1_000);

same(Idempotency::IN_PROGRESS, $inFlightSaw['outcome'],
    'a key whose first attempt has not finished is in progress, not a second execution');
same('idempotency.in_progress', $inFlightSaw['response']['code'],
    'and it comes back as the published code a client can branch on');

// 4 — REPLAY, DIFFERENT FINGERPRINT. The row that earns the class.
$conflict = $keys->run('T-A', 'C-1', 'POST /api/v3/attendance-punches', 'k-1', ['a' => 2], $work,
    transactionOpen: true, now: 1_002);

same(Idempotency::CONFLICT, $conflict['outcome'],
    'the same key with a DIFFERENT body is refused: two different requests believe they are the '
    . 'same operation, and running either is a coin flip about which effect lands');
same('idempotency.key_reuse_conflict', $conflict['response']['code'], 'with the published code');
same(1, $ran, 'and NOTHING was executed');

// 5 — KEY OLDER THAN THE WINDOW. Treated as first use, because a key old enough
// to have fallen out of the window is a key nobody is retrying.
$short = new Idempotency(retentionSeconds: 10);
$aged = 0;
$agedWork = static function () use (&$aged): array {
    $aged++;

    return ['n' => $aged];
};

$short->run('T-A', 'C-1', 'E', 'k-3', ['a' => 1], $agedWork, transactionOpen: true, now: 1_000);
$after = $short->run('T-A', 'C-1', 'E', 'k-3', ['a' => 1], $agedWork, transactionOpen: true,
    now: 1_100);

same(Idempotency::FIRST_USE, $after['outcome'], 'a key past the retention window is first use again');
same(2, $aged, 'and the work runs, rather than a month-old key blocking a legitimately new request');

// ------------------------------------------------------- the rules underneath
group('api idempotency — the rules underneath');

throws(LogicException::class,
    static fn () => $keys->run('T-A', 'C-1', 'E', 'k-9', [], $work, transactionOpen: false, now: 1),
    'the record and the effect share ONE transaction — recording the key after a successful commit '
    . 'is not idempotent under a crash between the two: the effect landed, nothing knows the key '
    . 'was used, and the retry does it again');

$missing = $keys->run('T-A', 'C-1', 'E', null, [], $work, transactionOpen: true, now: 1);
same(Idempotency::CONFLICT, $missing['outcome'], 'a write with no key at all is refused');
same('idempotency.key_required', $missing['response']['code'],
    'because a create replayed on a timeout is a duplicate, and the caller cannot tell which of '
    . 'the two attempts landed');

// A FAILED ATTEMPT DOES NOT HOLD THE KEY. Leaving the record in flight would
// answer 409 forever to a client whose request never actually ran.
$failing = new Idempotency();
$attempts = 0;

throws(RuntimeException::class, static function () use ($failing, &$attempts): void {
    $failing->run('T-A', 'C-1', 'E', 'k-boom', ['a' => 1], static function () use (&$attempts): array {
        $attempts++;

        throw new RuntimeException('the write failed');
    }, transactionOpen: true, now: 1_000);
}, 'a failing effect propagates rather than being swallowed');

$retry = $failing->run('T-A', 'C-1', 'E', 'k-boom', ['a' => 1],
    static function () use (&$attempts): array {
        $attempts++;

        return ['ok' => true];
    }, transactionOpen: true, now: 1_001);

same(Idempotency::FIRST_USE, $retry['outcome'],
    'and the retry is a legitimate first use — a failed attempt that kept the key would answer 409 '
    . 'forever to a request that never ran');
same(2, $attempts, 'so the work ran on the retry');

// ---------------------------------------- the half the key cannot do: the data
group('api idempotency — and the natural key');

$directory = sys_get_temp_dir() . '/prs3-punchkey-' . bin2hex(random_bytes(4));
$store = (new Store($directory))->forTenant('T-A');

register_shutdown_function(static function () use ($directory): void {
    foreach (glob($directory . '/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($directory);
});

$store->put('employees', ['emp_code' => 'SP-900', 'name' => 'Natural Key', 'device_user_id' => '37']);

$import = new PunchImport($store);
$layout = PunchImport::layout(['api' => [
    'delimiter' => "\t",
    'columns' => ['device_user_id' => 0, 'punch_at' => 1, 'direction' => 2, 'device_sn' => 3],
    'timestamp_format' => 'Y-m-d H:i:s',
    'directions' => ['in' => 'in', 'out' => 'out'],
]]);

$body = ['punches' => [['device_user_id' => '37', 'punch_at' => '2026-09-01 09:03:11',
    'direction' => 'in', 'device_sn' => 'ZK-01']]];
$text = "37\t2026-09-01 09:03:11\tin\tZK-01";

// TWO REQUESTS, TWO DIFFERENT KEYS, THE SAME PUNCH. The idempotency key is
// correct to call both of these first use — they ARE different requests. The
// duplicate is stopped by the data, and by nothing else.
$fresh = new Idempotency();
$one = $fresh->run('T-A', 'C-1', 'E', 'key-one', $body,
    static fn (): array => $import->import($text, $layout), transactionOpen: true, now: 1_000);
$two = $fresh->run('T-A', 'C-1', 'E', 'key-two', $body,
    static fn (): array => $import->import($text, $layout), transactionOpen: true, now: 1_001);

same(Idempotency::FIRST_USE, $one['outcome'], 'the first push is first use');
same(Idempotency::FIRST_USE, $two['outcome'],
    'and so is the second — a different key IS a different request, and the key is right to say so');
same(1, $one['response']['imported'], 'the first push wrote the punch');
same(0, $two['response']['imported'],
    'the second wrote NOTHING, because `external_id` is built from the device, the person and the '
    . 'instant — the same punch under a new key is duplicating, not replaying');
same(1, $two['response']['already_present'], 'and the duplicate is counted rather than dropped silently');
same(1, $store->count(PunchImport::COLLECTION), 'one punch in the table, from two accepted requests');

// AND THE OTHER WAY ROUND. The natural key cannot stop a replay from writing
// something new, and does not try to: an idempotency key is what makes the same
// REQUEST safe to retry, whatever it contained.
$second = "37\t2026-09-01 18:04:02\tout\tZK-01";
$secondBody = ['punches' => [['punch_at' => '2026-09-01 18:04:02']]];

$fresh->run('T-A', 'C-1', 'E', 'key-three', $secondBody,
    static fn (): array => $import->import($second, $layout), transactionOpen: true, now: 1_002);
$replayed = $fresh->run('T-A', 'C-1', 'E', 'key-three', $secondBody,
    static fn (): array => $import->import($second, $layout), transactionOpen: true, now: 1_003);

same(Idempotency::REPLAYED, $replayed['outcome'], 'the same key with the same body replays');
same(2, $store->count(PunchImport::COLLECTION),
    'and the store was not touched a second time — the two mechanisms divide the work rather than '
    . 'duplicating it');

// UNMAPPED IS REPORTED AND NOT WRITTEN, the same rule the file import keeps: an
// unmapped punch is somebody who worked and will not be paid; a punch attached to
// a plausible employment pays the wrong person.
$unmapped = $import->import("99\t2026-09-02 09:00:00\tin\tZK-01", $layout);

same(0, $unmapped['imported'], 'a punch from an unmapped device id is not written');
same(['99' => 1], $unmapped['unmapped'], 'it is reported, by device id, with a count');
same(2, $store->count(PunchImport::COLLECTION), 'and the table is unchanged');

// ------------------------------------------------------------- NON-VACUITY
// Every assertion above would be satisfied by a class that always returned the
// same outcome, or by an import that never wrote anything at all. Both are
// excluded here by comparing the OUTCOMES against each other rather than each
// against a constant.
group('api idempotency — non-vacuity');

$outcomes = [$first['outcome'], $replay['outcome'], $inFlightSaw['outcome'], $conflict['outcome']];

same(4, count(array_unique($outcomes)),
    'the four live rows of the table produced four DIFFERENT outcomes, so `run()` is deciding '
    . 'rather than returning a constant');

ok($first['response'] !== $conflict['response'],
    'and a first use and a conflict do not answer the same thing');

ok($one['response']['imported'] !== $two['response']['imported'],
    'the two identical punches under different keys had genuinely different effects — if the '
    . 'import wrote nothing at all, both would read zero and this file would prove nothing');

same(2, $store->count(PunchImport::COLLECTION),
    'and the store really does hold rows, so "wrote nothing the second time" is a decision and not '
    . 'an import that never worked');

$missCount = 0;

foreach ([['38', 1_000], ['39', 1_001]] as [$device, $when]) {
    $result = $fresh->run('T-A', 'C-1', 'E', 'k-' . $device, ['d' => $device],
        static fn (): array => $import->import("{$device}\t2026-09-03 09:00:00\tin\tZK-01", $layout),
        transactionOpen: true, now: $when);
    $missCount += count($result['response']['unmapped']);
}

same(2, $missCount,
    'two different unmapped device ids were each reported once, so `unmapped` tracks the input '
    . 'rather than being a constant that happens to have been right twice');
