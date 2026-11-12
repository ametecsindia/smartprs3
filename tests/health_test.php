<?php

declare(strict_types=1);

use SmartPRS\Platform\Operations\HealthCheck;
use SmartPRS\Surface\Health;
use SmartPRS\Surface\Session;
use SmartPRS\Surface\Store;

/**
 * A CHECK THAT THREW IS NOT A CHECK THAT PASSED.
 *
 * `Platform\Operations\HealthCheck` turns a probe that raises into a typed
 * UNKNOWN and counts it as failing — "because the alternative is a green
 * dashboard produced by a monitoring system that cannot reach the thing it
 * monitors, which is the worst possible arrangement of those two facts". It was
 * finished, tested, and reached from nothing but a phase gate.
 *
 * The assertions here are about the SHAPE of the report rather than about
 * whether this particular installation is healthy, because the installation
 * changes and the shape must not: every check names what it observed, what the
 * threshold was, what to do and whose job it is, and a probe that could not run
 * says so instead of passing.
 */

group('Installation health');

$root = dirname(__DIR__);

// NO DATABASE AT ALL, which is the state this whole class exists to make
// visible — and the one where a badly built health screen shows all green
// because every probe quietly failed.
$store = (new Store(sys_get_temp_dir() . '/health-' . bin2hex(random_bytes(4))))->forTenant('1');
$report = (new Health($store, $root, null))->report();

same(false, $report['ok'], 'an installation with no database is not healthy');
ok(count($report['checks']) >= 7, 'every registered check is in the report');

// THE PROBES THAT NEED A DATABASE MUST SAY THEY COULD NOT RUN. This is the
// assertion the whole file is for: `unknown`, not `ok`, and never absent.
same(HealthCheck::UNKNOWN, $report['checks']['schema.migrations_applied']['status'] ?? '',
    'a probe that could not run reports UNKNOWN rather than passing');
ok(str_contains((string) $report['checks']['schema.migrations_applied']['observed'],
    'could not run'), 'and says so in the observed value');
ok(in_array('schema.migrations_applied', array_map(
    static fn (string $f): string => explode(' ', $f)[0], $report['failing']), true),
    'and UNKNOWN counts as FAILING — a green board on a system nobody can reach is worse than '
    . 'a red one');

// The one that does not need a database still answers.
same(HealthCheck::FAILING, $report['checks']['database.reachable']['status'] ?? '',
    'no connection is reported as failing, not as unknown — this probe CAN run and its answer is no');

// ---------------------------------------------------------------------------
// EVERY CHECK CARRIES WHAT TO DO AND WHOSE JOB IT IS. The platform refuses a
// registration without them: "an alert nobody owns, with no stated next step,
// is a notification that trains people to ignore notifications."
// ---------------------------------------------------------------------------

foreach ($report['checks'] as $code => $check) {
    ok(trim((string) ($check['remediation'] ?? '')) !== '',
        "check {$code} says what to do about it");
    ok(trim((string) ($check['owner'] ?? '')) !== '', "check {$code} names an owner");
    ok(trim((string) ($check['threshold'] ?? '')) !== '',
        "check {$code} states its threshold — a colour with no threshold sends somebody to look");
    ok(trim((string) ($check['observed'] ?? '')) !== '',
        "check {$code} states what it observed, not just a verdict");
}

// ---------------------------------------------------------------------------
// THE TWO DEFERRED DECISIONS ARE REPORTED, not left to a banner somebody
// dismisses. A deferred decision that nothing reports is a decision nobody makes.
// ---------------------------------------------------------------------------

$store->put('users', ['email' => Session::LOCAL_DEFAULT_EMAIL, 'name' => 'Local']);
$withDefault = (new Health($store, $root, null))->report();

same(HealthCheck::FAILING, $withDefault['checks']['access.default_credential']['status'] ?? '',
    'the seeded default credential is a failing check while it exists');

// And it clears when the account goes — otherwise the check is a permanent red
// that people learn to scroll past, which is the failure mode it warns about.
$clean = (new Store(sys_get_temp_dir() . '/health2-' . bin2hex(random_bytes(4))))->forTenant('1');
$without = (new Health($clean, $root, null))->report();

same(HealthCheck::OK, $without['checks']['access.default_credential']['status'] ?? '',
    'and it clears when the account is gone — a permanently red check is one people scroll past');

// The password floor reports the REAL constant against the owner's standard of 8 (22 Oct 2026).
same(Session::PASSWORD_FLOOR >= 8 ? HealthCheck::OK : HealthCheck::FAILING,
    $without['checks']['access.password_floor']['status'] ?? '',
    'the password floor check reads the constant rather than restating it');
