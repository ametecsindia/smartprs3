<?php
/**
 * THE PHASE 9 EXIT GATE — Part XXXII, Delivery, deployment and operations.
 *
 *   php tools/phase9/exit-gate.php
 *
 * Four exit conditions:
 *
 *   1. A clean on-premise install completes with NO DEFAULT CREDENTIAL,
 *      registers its scheduler and worker, and passes its own health check.
 *   2. An update applies, fails deliberately, and rolls back both code AND
 *      database.
 *   3. A restore drill succeeds and its date is visible.
 *   4. The operations console shows every item in Part XXXI §XXXI.7.
 *
 * Part XXXII says this phase "starts at low intensity alongside Phase 1" and
 * that these are "the capabilities whose absence in SmartPRS 2 causes the most
 * customer-visible harm, and they cannot be bolted on at the end without being
 * bolted on badly".
 */
declare(strict_types=1);

require_once __DIR__ . '/../../tests/lib/bootstrap.php';

use SmartPRS\Platform\Operations\HealthCheck;
use SmartPRS\Platform\Operations\Installer;
use SmartPRS\Platform\Operations\OperationsConsole;
use SmartPRS\Platform\Operations\RestoreDrill;
use SmartPRS\Platform\Operations\Scheduler;
use SmartPRS\Platform\Operations\UpdatePackage;

$root = dirname(__DIR__, 2);
$results = [];

echo "SmartPRS 3 — Phase 9 exit gate (Part XXXII, Delivery, deployment and operations)\n";

$goodEnvironment = ['php_8_3' => true, 'pdo_mysql' => true, 'openssl' => true, 'writable_storage' => true];

/** A health check set that passes. */
$healthy = static function (): HealthCheck {
    $health = new HealthCheck();
    $health->register('database', static fn (): array => ['status' => HealthCheck::OK, 'observed' => '3ms'],
        '< 100ms', 'check the database host and connection pool', 'ops');
    $health->register('storage', static fn (): array => ['status' => HealthCheck::OK, 'observed' => '41% used'],
        '< 85%', 'open the retention policy or extend the volume', 'ops');
    $health->register('scheduler', static fn (): array => ['status' => HealthCheck::OK, 'observed' => '20s ago'],
        '< 15 minutes', 'restart the scheduler and clear stale leases', 'ops');

    return $health;
};

// =============================================================================
// 1. A CLEAN INSTALL, NO DEFAULT CREDENTIAL, SCHEDULER AND WORKER REGISTERED
// =============================================================================

$problems = [];
$installer = new Installer();
$scheduler = new Scheduler();
$outcome = $installer->install($goodEnvironment, $healthy(), $scheduler);

if (!$outcome['ok']) {
    $problems[] = 'a clean install did not complete: ' . implode('; ', $outcome['problems']);
}

// THE CLAUSE: no default credential, asserted structurally rather than claimed.
if ($installer->defaultCredentials() !== []) {
    $problems[] = 'the installer writes default credential(s): '
        . implode(', ', $installer->defaultCredentials())
        . '. An installer that creates admin/admin and asks the operator to change it has created an '
        . 'account that is admin/admin on every site where the person who installed it was not the person '
        . 'who was going to use it';
}

if ($outcome['enrolment_token'] === null || strlen($outcome['enrolment_token']) < 32) {
    $problems[] = 'the install produced no usable single-use enrolment token, so there is no way to create '
        . 'the first administrator except by seeding one';
}

// And no password-shaped literal anywhere in the installer.
$installerSource = (string) file_get_contents($root . '/src/Platform/Operations/Installer.php');

foreach (['admin123', 'password', 'changeme', 'default_password', 'Admin@123'] as $shape) {
    if (stripos((string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $installerSource), $shape) !== false) {
        $problems[] = "the installer source contains '{$shape}'";
    }
}

if (count($scheduler->jobs()) < 3) {
    $problems[] = 'the install registered ' . count($scheduler->jobs()) . ' job(s). An install that '
        . '"succeeds" and leaves nothing running is one whose payroll reminders, statutory sweeps and '
        . 'notification retries silently never happen, and the first symptom is a missed filing';
}

if ($scheduler->workers() === []) {
    $problems[] = 'the install registered no worker';
}

// A scheduler that has stopped must not look like one with nothing to do.
if ($scheduler->isAlive(new DateTimeImmutable('2026-09-01 12:00'))) {
    $problems[] = 'a scheduler that has never beaten reported itself alive';
}

$scheduler->beat(new DateTimeImmutable('2026-09-01 11:58'));

if (!$scheduler->isAlive(new DateTimeImmutable('2026-09-01 12:00'))) {
    $problems[] = 'a scheduler that beat two minutes ago reported itself dead';
}

if ($scheduler->isAlive(new DateTimeImmutable('2026-09-01 13:00'))) {
    $problems[] = 'a scheduler that last beat an hour ago reported itself alive';
}

// An install onto a host missing a prerequisite REFUSES rather than half-running.
$short = (new Installer())->install(['php_8_3' => true], $healthy(), new Scheduler());

if ($short['ok']) {
    $problems[] = 'an install onto a host missing prerequisites succeeded. It would fail later, in '
        . 'production, on a symptom that names something else';
}

// The install checks ITSELF: a broken health check must fail the install.
$broken = new HealthCheck();
$broken->register('database', static fn (): array => throw new RuntimeException('connection refused'),
    '< 100ms', 'check the database host', 'ops');

if ((new Installer())->install($goodEnvironment, $broken, new Scheduler())['ok']) {
    $problems[] = 'an install whose own health check could not run reported success. A probe that threw is '
        . 'not a probe that passed';
}

$results[] = [
    'name' => 'A clean install completes with no default credential, registers its scheduler and worker, '
        . 'and passes its own health check',
    'ran' => true,
    'problems' => $problems,
    'detail' => 'the installer writes no credential of any kind and issues a single-use enrolment token '
        . 'instead; it registers ' . count($scheduler->jobs()) . ' jobs and ' . count($scheduler->workers())
        . ' worker; a missing prerequisite refuses the install rather than half-running it; the heartbeat '
        . 'distinguishes a stopped scheduler from an idle one; and an install whose health check THREW is a '
        . 'failed install, not a passed one',
];

// =============================================================================
// 2. AN UPDATE APPLIES, FAILS DELIBERATELY, AND ROLLS BACK CODE AND DATABASE
// =============================================================================

$problems = [];
$state = ['code' => '3.0.0', 'schema' => '3.0.0', 'backups' => []];

$backup = static function () use (&$state): string {
    $ref = 'bkp-' . count($state['backups']);
    $state['backups'][] = $ref;

    return $ref;
};
$restoreDb = static function (string $ref) use (&$state): void { $state['schema'] = '3.0.0'; };
$restoreCode = static function (string $v) use (&$state): void { $state['code'] = $v; };

// A clean update.
$ok = (new UpdatePackage('3.0.0', '3.1.0', signatureValid: true))->apply(
    $backup,
    static function () use (&$state): void { $state['code'] = '3.1.0'; },
    static function () use (&$state): void { $state['schema'] = '3.1.0'; },
    $restoreDb,
    $restoreCode,
);

if (!$ok['ok'] || $state['code'] !== '3.1.0' || $state['schema'] !== '3.1.0') {
    $problems[] = 'a clean update did not apply: ' . ($ok['failure'] ?? 'no reason given');
}

if ($ok['journal'][0] !== 'database backed up as bkp-0 BEFORE any change') {
    $problems[] = 'the backup was not the FIRST thing the update did. An update that migrates before it '
        . 'can go back has no rollback, whatever the runbook says';
}

// THE DELIBERATE FAILURE — the criterion's own words.
$state = ['code' => '3.1.0', 'schema' => '3.1.0', 'backups' => []];

$failed = (new UpdatePackage('3.1.0', '3.2.0', signatureValid: true))->apply(
    $backup,
    static function () use (&$state): void { $state['code'] = '3.2.0'; },
    static function (): void { throw new RuntimeException('migration 0042 hit a duplicate key'); },
    static function (string $ref) use (&$state): void { $state['schema'] = '3.1.0'; },
    static function (string $v) use (&$state): void { $state['code'] = $v; },
);

if ($failed['ok']) {
    $problems[] = 'a deliberately failing update reported success';
}

if (!$failed['rolled_back']) {
    $problems[] = 'the failed update did not report a rollback';
}

if ($state['code'] !== '3.1.0') {
    $problems[] = "after the failed update the code is {$state['code']}, expected 3.1.0";
}

if ($state['schema'] !== '3.1.0') {
    $problems[] = "after the failed update the schema is {$state['schema']}, expected 3.1.0. BOTH must go "
        . 'back: rolling back only the code leaves version N running against a schema for N+1, and every '
        . 'symptom then points somewhere else';
}

// An unsigned package is refused before anything is touched.
$state = ['code' => '3.1.0', 'schema' => '3.1.0', 'backups' => []];
$unsigned = (new UpdatePackage('3.1.0', '3.2.0', signatureValid: false))->apply(
    $backup,
    static function () use (&$state): void { $state['code'] = '3.2.0'; },
    static function () use (&$state): void { $state['schema'] = '3.2.0'; },
    $restoreDb, $restoreCode,
);

if ($unsigned['ok'] || $state['code'] !== '3.1.0' || $state['backups'] !== []) {
    $problems[] = 'an unsigned update package was applied, or touched something before being refused';
}

// A backup that produced no reference stops the update: there is nothing to
// return to, and proceeding from there is an update with no way back that does
// not know it.
$noBackup = (new UpdatePackage('3.1.0', '3.2.0', true))->apply(
    static fn (): string => '',
    static function (): void {}, static function (): void {},
    $restoreDb, $restoreCode,
);

if ($noBackup['ok']) {
    $problems[] = 'an update proceeded after its pre-update backup produced no reference';
}

// No --force parameter, anywhere on the class.
foreach ((new ReflectionClass(UpdatePackage::class))->getMethods() as $method) {
    foreach ($method->getParameters() as $parameter) {
        if (preg_match('/force|skip|ignore|unsafe/i', $parameter->getName()) === 1) {
            $problems[] = "UpdatePackage::{$method->getName()}() takes \${$parameter->getName()}";
        }
    }
}

$results[] = [
    'name' => 'An update applies, fails deliberately, and rolls back both code and database',
    'ran' => true,
    'problems' => $problems,
    'detail' => 'a clean update applies with the backup taken FIRST; a deliberately failing migration rolls '
        . 'back the database and then the code, in reverse order, leaving neither ahead of the other; an '
        . 'unsigned package is refused before anything is touched; a backup with no reference stops the '
        . 'update; and no method offers a force flag',
];

// =============================================================================
// 3. A RESTORE DRILL SUCCEEDS AND ITS DATE IS VISIBLE
// =============================================================================

$problems = [];
$drill = new RestoreDrill();

if ($drill->lastSuccessful() !== null) {
    $problems[] = 'a drill that has never run reported a last successful date';
}

if (!str_contains($drill->consoleValue(new DateTimeImmutable('2026-09-01')), 'NEVER PROVEN RESTORABLE')) {
    $problems[] = 'a system that has never proven a restore shows a blank rather than saying so. A backup '
        . 'nobody has restored is a belief, and the console must say which one it is';
}

$restored = $drill->run('bkp-nightly-2026-08-31', new DateTimeImmutable('2026-09-01 02:00'),
    static fn (): bool => true,
    static fn (): array => ['tables' => 41, 'rows' => 128_540],
    ['tables' => 41, 'rows' => 128_540]);

if (!$restored['ok']) {
    $problems[] = 'a good restore drill failed: ' . $restored['note'];
}

if ($drill->lastSuccessful()?->format('Y-m-d') !== '2026-09-01') {
    $problems[] = 'the successful drill date is not readable';
}

if (!str_contains($drill->consoleValue(new DateTimeImmutable('2026-09-08')), '2026-09-01')) {
    $problems[] = 'the drill date is not visible in the console value, so an operator cannot answer "when '
        . 'was this last proven restorable?" from one screen';
}

// A CORRUPT backup restores perfectly: the files land and the process exits zero.
$corrupt = $drill->run('bkp-corrupt', new DateTimeImmutable('2026-09-02 02:00'),
    static fn (): bool => true,
    static fn (): array => ['tables' => 41, 'rows' => 3],
    ['tables' => 41, 'rows' => 128_540]);

if ($corrupt['ok']) {
    $problems[] = 'a restore that landed 3 rows instead of 128,540 was recorded as a successful drill. A '
        . 'drill that only restores has proven the restore command runs, which nobody doubted';
}

if ($drill->lastSuccessful()?->format('Y-m-d') !== '2026-09-01') {
    $problems[] = 'the failed drill overwrote the last SUCCESSFUL date';
}

$thrown = $drill->run('bkp-gone', new DateTimeImmutable('2026-09-03 02:00'),
    static fn (): bool => throw new RuntimeException('the destination is unreachable'),
    static fn (): array => ['tables' => 0, 'rows' => 0], ['tables' => 41, 'rows' => 1]);

if ($thrown['ok']) {
    $problems[] = 'a restore drill that threw was recorded as successful';
}

$results[] = [
    'name' => 'A restore drill succeeds and its date is visible',
    'ran' => true,
    'problems' => $problems,
    'detail' => 'the drill restores AND verifies what landed, so a corrupt backup that restores perfectly '
        . 'fails the drill; a system that has never drilled says NEVER PROVEN RESTORABLE rather than '
        . 'showing a blank; a failed drill does not overwrite the last successful date; and the date is on '
        . 'the console value an operator reads',
];

// =============================================================================
// 4. THE OPERATIONS CONSOLE SHOWS EVERY ITEM IN PART XXXI §XXXI.7
// =============================================================================

$problems = [];
$console = new OperationsConsole();
$now = '2026-09-01T12:00:00+05:30';

foreach ([
    ['scheduler', 'Heartbeat age', '20s', 'Run now / clear stale lease'],
    ['queues', 'Depth (default)', '12', 'Pause / drain / scale workers'],
    ['failed_jobs', 'Dead-lettered', '3', 'Replay (idempotent) / discard with reason'],
    ['notifications', 'Queued / sent / delivered / failed', '0 / 41 / 40 / 1', 'Retry / inspect / test transport'],
    ['webhooks', 'Failed deliveries', '0', 'Redeliver / rotate secret'],
    ['integrations', 'Devices stale', '1', 'Test connection / rewind watermark'],
    ['data_protection', 'Last proven restorable', '2026-09-01 (7 days ago)', 'Run a restore drill now'],
    ['licence_and_packs', 'Oldest pack age', '31 days', 'Check for updates / preview diff'],
    ['migrations_and_version', 'Schema fingerprint', 'matches', 'Re-run the fingerprint check'],
    ['capacity', 'Projected exhaustion', '2027-02-14', 'Open the retention policy'],
    ['health_and_conditions', 'Failing checks', '0', 'Run a health check / download bundle'],
    ['audit_and_compliance', 'Chain verification age', '4 hours', 'Run verification'],
] as [$panel, $label, $value, $action]) {
    $console->item($panel, $label, $value, $action, asOf: $now);
}

if ($console->missingPanels() !== []) {
    $problems[] = 'the console does not show these §XXXI.7 panels: '
        . implode(', ', $console->missingPanels());
}

if ($console->unactionableItems() !== []) {
    $problems[] = 'these items have no next step: ' . implode(', ', $console->unactionableItems());
}

// R-XXXI-090 enforced at construction, not by review.
try {
    $console->item('scheduler', 'A number', '7', asOf: $now);
    $problems[] = 'an item with neither an action nor a runbook was accepted. The pressure on a console is '
        . 'always toward one more number, and the one added under pressure is the one with nowhere to go';
} catch (InvalidArgumentException) {
    // Refused, as it must be.
}

try {
    $console->item('scheduler', 'A number', '7', 'Do something');
    $problems[] = 'an item with no freshness was accepted (R-XXXI-091)';
} catch (InvalidArgumentException) {
    // Refused, as it must be.
}

try {
    $console->item('a_panel_somebody_invented', 'x', '1', 'y', asOf: $now);
    $problems[] = 'an invented panel was accepted';
} catch (InvalidArgumentException) {
    // Refused, as it must be.
}

// A runbook alone satisfies the rule — the specification says "an action, a
// runbook link, or both", and narrowing that would be this gate inventing policy.
try {
    $console->item('capacity', 'Growth per table class', '2.1 GB/month',
        runbook: 'https://runbook/capacity', asOf: $now);
} catch (InvalidArgumentException) {
    $problems[] = 'an item carrying only a runbook link was refused; §XXXI.7 allows an action, a runbook, '
        . 'or both';
}

// The question R-XXXI-093 says an on-premise operator must answer from one screen.
$dataProtection = $console->panel('data_protection');
$answersIt = false;

foreach ($dataProtection as $item) {
    if (str_contains(strtolower($item['label']), 'restorable')) {
        $answersIt = true;
    }
}

if (!$answersIt) {
    $problems[] = 'the data-protection panel does not answer "when was it last proven restorable?" — two '
        . 'questions live there, and only the second one is evidence';
}

$results[] = [
    'name' => 'The operations console shows every item in Part XXXI §XXXI.7',
    'ran' => true,
    'problems' => $problems,
    'detail' => 'all ' . count(OperationsConsole::PANELS) . ' panels present with '
        . $console->itemCount() . ' items, every one carrying an action or a runbook and its freshness; an '
        . 'item with neither, an item with no freshness and an invented panel are all refused at '
        . 'construction; and the data-protection panel answers the restorability question an on-premise '
        . 'operator must not need a support call for',
];

// =============================================================================

$failed = 0;

foreach ($results as $index => $result) {
    if ($result['problems'] !== []) {
        $failed++;
        printf("\n  %d. \033[31mFAIL      \033[0m  %s\n", $index + 1, $result['name']);

        foreach ($result['problems'] as $problem) {
            echo "        └─ {$problem}\n";
        }

        continue;
    }

    printf("\n  %d. \033[32mPASS      \033[0m  %s\n        %s\n", $index + 1, $result['name'],
        $result['detail']);
}

printf("\n%s  %d of %d Phase 9 exit criteria proven\n", $failed === 0 ? 'PASS' : 'FAIL',
    count($results) - $failed, count($results));

exit($failed === 0 ? 0 : 1);
