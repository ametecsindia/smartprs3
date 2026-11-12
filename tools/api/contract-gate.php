<?php
/**
 * THE /api/v3 CONTRACT GATE — Part XXIV §XXIV.2.
 *
 *   php tools/api/contract-gate.php
 *
 * Part XXXII names the API contract as the coordination point for the front-end
 * track: *"Front end — runs Phase 2 onward, per module — consumes /api/v3; the
 * API contract is the coordination point."* A contract that is described in a
 * document and not enforced by anything is a contract both sides interpret
 * differently, and the divergence is found by a customer.
 *
 * Five properties, each with the SmartPRS 2 defect that requires it.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../tests/lib/bootstrap.php';

use SmartPRS\Platform\Api\Cursor;
use SmartPRS\Platform\Api\ErrorCatalogue;
use SmartPRS\Platform\Api\ErrorEnvelope;
use SmartPRS\Platform\Api\Idempotency;
use SmartPRS\Platform\Api\RouteGrammar;

$root = dirname(__DIR__, 2);
$results = [];

echo "SmartPRS 3 — /api/v3 contract gate (Part XXIV)\n";

// =============================================================================
// 1. THE URL GRAMMAR
// =============================================================================

$problems = [];

$admissible = [
    ['method' => 'GET', 'path' => '/api/v3/employees', 'operation' => 'employees.list'],
    ['method' => 'GET', 'path' => '/api/v3/employees/{id:uuid}', 'operation' => 'employees.show'],
    ['method' => 'PATCH', 'path' => '/api/v3/employees/{id:uuid}', 'operation' => 'employees.update'],
    ['method' => 'GET', 'path' => '/api/v3/employees/{id:uuid}/payslips', 'operation' => 'payslips.list'],
    ['method' => 'POST', 'path' => '/api/v3/payroll-runs', 'operation' => 'runs.create',
        'idempotent' => true],
    ['method' => 'POST', 'path' => '/api/v3/payroll-runs/{id:uuid}/approve', 'operation' => 'runs.approve',
        'idempotent' => true],
    ['method' => 'DELETE', 'path' => '/api/v3/salary-structures/{id:uuid}', 'operation' => 'structures.archive'],
];

foreach ($admissible as $route) {
    $found = RouteGrammar::check($route);

    if ($found !== []) {
        $problems[] = "{$route['method']} {$route['path']} was refused: " . implode('; ', $found);
    }
}

/** Each of these must be REFUSED, for its own stated reason. */
$refusals = [
    ['a PUT route', ['method' => 'PUT', 'path' => '/api/v3/employees/{id:uuid}', 'operation' => 'x'],
        'PUT is not used'],
    ['an integer path parameter',
        ['method' => 'GET', 'path' => '/api/v3/employees/{id}', 'operation' => 'x'], 'not typed as a UUID'],
    ['a camelCase verb collection',
        ['method' => 'GET', 'path' => '/api/v3/getSalaryStructure', 'operation' => 'x'], 'kebab-case noun'],
    ['a singular collection',
        ['method' => 'GET', 'path' => '/api/v3/employee', 'operation' => 'x'], 'not plural'],
    ['a tenant in the path',
        ['method' => 'GET', 'path' => '/api/v3/tenants/{id:uuid}/employees', 'operation' => 'x'],
        'derived from the credential'],
    ['three levels of nesting',
        ['method' => 'GET', 'path' => '/api/v3/employees/{a:uuid}/payslips/{b:uuid}/lines',
            'operation' => 'x'], 'maximum is two'],
    ['a route outside /api/v3',
        ['method' => 'GET', 'path' => '/app/data', 'operation' => 'x'], 'second, private, SPA-only'],
    ['a GET verb endpoint',
        ['method' => 'GET', 'path' => '/api/v3/payroll-runs/{id:uuid}/approve', 'operation' => 'x'],
        'always POST'],
    ['a verb with no idempotency key',
        ['method' => 'POST', 'path' => '/api/v3/payroll-runs/{id:uuid}/approve', 'operation' => 'x'],
        'not idempotency-keyed'],
    ['a POST create with no idempotency key',
        ['method' => 'POST', 'path' => '/api/v3/employees', 'operation' => 'x'],
        'declares no idempotency requirement'],
];

foreach ($refusals as [$what, $route, $expect]) {
    $found = RouteGrammar::check($route);

    if ($found === []) {
        $problems[] = "{$what} was ACCEPTED";

        continue;
    }

    if (!str_contains(implode(' ', $found), $expect)) {
        $problems[] = "{$what} was refused for '" . implode('; ', $found)
            . "' rather than mentioning '{$expect}'";
    }
}

$results[] = [
    'name' => 'The URL grammar is enforced by a linter, not documented',
    'problems' => $problems,
    'detail' => count($admissible) . ' admissible routes accepted and ' . count($refusals)
        . ' inadmissible ones each refused for their own reason — PUT is ABSENT rather than discouraged '
        . '(a full-replace update is how SmartPRS 2 nulls every omitted key), identifiers are UUIDs only '
        . '(a sequential id is enumerable, and a 404 deliberately indistinguishable from a 403 is worth '
        . 'nothing if the caller can count), and the tenant is never addressable',
];

// =============================================================================
// 2. ONE ERROR ENVELOPE, FROM A CLOSED CATALOGUE
// =============================================================================

$problems = [];

if (ErrorCatalogue::malformedCodes() !== []) {
    $problems[] = 'these codes are not namespace.reason: ' . implode(', ', ErrorCatalogue::malformedCodes());
}

$validation = ErrorEnvelope::build('validation.failed', 422, 'The request could not be processed.',
    'req-1', 'corr-1', [[
        'field' => 'joining_date', 'code' => 'date.before_dob',
        'message' => 'Joining date precedes date of birth.', 'value_seen' => '1999-03-14',
    ]]);

foreach (['code', 'message', 'status', 'request_id', 'correlation_id', 'documentation_url',
    'details', 'retryable'] as $key) {
    if (!array_key_exists($key, $validation['error'])) {
        $problems[] = "the envelope is missing '{$key}'";
    }
}

if ($validation['error']['retryable'] !== false) {
    $problems[] = 'a 422 was marked retryable. `retryable` is a CONTRACT, not a hint: a client that '
        . 'retries a validation failure because a field said "maybe" is a client that submits twice';
}

if (!ErrorEnvelope::retryable('rate.exceeded', 429)
    || !ErrorEnvelope::retryable('idempotency.in_progress', 409)) {
    $problems[] = 'a genuinely retryable outcome is not marked retryable, so a client will treat a '
        . 'throttle as a permanent failure';
}

// An unpublished code is refused rather than passed through.
try {
    ErrorEnvelope::build('something.invented_here', 422, 'x', 'r', 'c');
    $problems[] = 'an ad-hoc error code was accepted. Clients branch on `code`; a code no client has seen '
        . "arrives as an unhandled branch in somebody else's software";
} catch (LogicException) {
    // Refused, as it must be.
}

// A code returned under the wrong status is refused.
try {
    ErrorEnvelope::build('validation.failed', 400, 'x', 'r', 'c');
    $problems[] = 'a published code was returned under a different status; a client would have to branch '
        . 'on the pair, and nobody does';
} catch (LogicException) {
    // Refused, as it must be.
}

// A 500 says nothing but its request id.
$server = ErrorEnvelope::build('server.unexpected', 500,
    'SQLSTATE[42S02]: Base table or view not found in /var/www/app/Repo.php line 88', 'req-2', 'corr-2');

if (ErrorEnvelope::leaks($server) !== []) {
    $problems[] = 'a 500 leaked ' . implode(' and ', ErrorEnvelope::leaks($server))
        . '. SmartPRS 2 returns raw exception messages from 194 sites, disclosing schema and file paths to '
        . 'any authenticated caller';
}

if ($server['error']['message'] !== 'An unexpected error occurred.') {
    $problems[] = 'a 500 carried a message. No message, no trace, ever — every 500 message is written '
        . 'under pressure, and an internal detail that reaches a caller is one an attacker reads';
}

// The leak detector is probed, so a clean result means clean.
if (ErrorEnvelope::leaks(['error' => ['message' => 'in /var/www/x.php line 12']]) === []) {
    $problems[] = 'the leak detector does not detect a leak, so every clean result above proves only that '
        . 'the detector is broken';
}

// A detail with no field cannot be shown next to the input that caused it.
try {
    ErrorEnvelope::build('validation.failed', 422, 'x', 'r', 'c', [['code' => 'a', 'message' => 'b']]);
    $problems[] = 'a validation detail with no field was accepted';
} catch (LogicException) {
    // Refused, as it must be.
}

// 404 and "another tenant's record" must be the SAME code.
if (ErrorCatalogue::statusFor('resource.not_found') !== 404
    || ErrorCatalogue::knows('resource.forbidden_other_tenant')) {
    $problems[] = 'there is a distinct code for a cross-tenant miss. A 403 for a record that exists in '
        . 'another tenant CONFIRMS it exists — an enumeration oracle wearing an access-control label';
}

$results[] = [
    'name' => 'One error envelope, from a closed and published code catalogue',
    'problems' => $problems,
    'detail' => count(ErrorCatalogue::CODES) . ' published codes, each namespace.reason with exactly one '
        . 'status; an ad-hoc code and a code under the wrong status are both refused; a 500 carries a '
        . 'request id and nothing else, with the leak detector probed against a known leak; and there is '
        . 'no separate code for another tenant\'s record',
];

// =============================================================================
// 3. IDEMPOTENCY
// =============================================================================

$problems = [];
$keys = new Idempotency();
$calls = 0;
$work = static function () use (&$calls): array {
    $calls++;

    return ['id' => 'created-once', 'call' => $calls];
};

$first = $keys->run('T-1', 'C-1', 'POST /payroll-runs', 'k-1', ['a' => 1], $work, true, 1000);

if ($first['outcome'] !== Idempotency::FIRST_USE || $calls !== 1) {
    $problems[] = 'the first use did not execute exactly once';
}

$replay = $keys->run('T-1', 'C-1', 'POST /payroll-runs', 'k-1', ['a' => 1], $work, true, 1001);

if ($replay['outcome'] !== Idempotency::REPLAYED || !$replay['replayed'] || $calls !== 1) {
    $problems[] = 'a replay with the same fingerprint executed again';
}

if ($replay['response'] !== $first['response']) {
    $problems[] = 'the replay did not return the STORED response byte-identically. Re-deriving it would '
        . "return today's data for yesterday's request";
}

$conflict = $keys->run('T-1', 'C-1', 'POST /payroll-runs', 'k-1', ['a' => 2], $work, true, 1002);

if ($conflict['outcome'] !== Idempotency::CONFLICT || $calls !== 1) {
    $problems[] = 'a key reused with a DIFFERENT body was executed. Two different requests believe they '
        . 'are the same operation, and running either is a coin flip about which effect lands';
}

$expired = $keys->run('T-1', 'C-1', 'POST /payroll-runs', 'k-1', ['a' => 1], $work, true, 1000 + 86401);

if ($expired['outcome'] !== Idempotency::FIRST_USE) {
    $problems[] = 'a key older than the retention window was not treated as first use, so it would return '
        . '409 forever';
}

// The record and the effect share a transaction, or the class refuses.
try {
    $keys->run('T-1', 'C-1', 'POST /x', 'k-2', [], $work, transactionOpen: false, now: 2000);
    $problems[] = 'the key was recorded outside a transaction. R-XXIV-033: recording it after a commit is '
        . 'not idempotent under a crash between the two — the money moved, nothing knows the key was '
        . 'used, and the retry moves it again';
} catch (LogicException) {
    // Refused, as it must be.
}

// A required key that is absent is a 400, not a silent execution.
$missing = $keys->run('T-1', 'C-1', 'POST /x', null, [], $work, true, 3000);

if ($missing['response']['code'] !== 'idempotency.key_required') {
    $problems[] = 'a required idempotency key that was absent did not return idempotency.key_required';
}

// A FAILED attempt does not hold the key.
$failing = new Idempotency();

try {
    $failing->run('T-1', 'C-1', 'POST /x', 'k-3', [],
        static fn (): array => throw new RuntimeException('the domain refused'), true, 4000);
} catch (RuntimeException) {
    // Expected.
}

if ($failing->has('T-1', 'C-1', 'POST /x', 'k-3')) {
    $problems[] = 'a failed attempt left its key in flight, so every retry returns 409 forever';
}

// The retention window is bounded.
try {
    new Idempotency(retentionSeconds: 604801);
    $problems[] = 'a retention window longer than seven days was accepted';
} catch (InvalidArgumentException) {
    // Refused, as it must be.
}

$results[] = [
    'name' => 'Idempotency covers all five situations, in the domain transaction',
    'problems' => $problems,
    'detail' => 'first use executes once; a replay returns the STORED response rather than re-deriving '
        . 'it; a key reused with a different body is refused and NEVER executed; an expired key is a fresh '
        . 'first use; a failed attempt releases its key; and the class refuses to run at all outside the '
        . 'caller\'s transaction, because a key recorded after a commit is not idempotent under a crash '
        . 'between the two',
];

// =============================================================================
// 4. CURSOR PAGINATION, AND NO OFFSET ANYWHERE
// =============================================================================

$problems = [];
$cursor = Cursor::encode(['id' => '0193a...', 'created_at' => '2026-09-01'], 'created_at,id');
$decoded = Cursor::decode($cursor, 'created_at,id');

if ($decoded['k']['id'] !== '0193a...') {
    $problems[] = 'a cursor did not round-trip';
}

foreach ([
    ['a cursor replayed against a different ordering', static fn () => Cursor::decode($cursor, 'name,id')],
    ['a cursor that is not base64', static fn () => Cursor::decode('!!!not-a-cursor!!!', 'created_at,id')],
    ['a cursor that decodes to something else', static fn () => Cursor::decode(
        rtrim(strtr(base64_encode('{"nope":1}'), '+/', '-_'), '='), 'created_at,id')],
] as [$what, $attempt]) {
    try {
        $attempt();
        $problems[] = "{$what} was accepted";
    } catch (InvalidArgumentException) {
        // Refused, as it must be.
    }
}

foreach (['offset' => 100, 'page' => 2, 'per_page' => 50, 'skip' => 10] as $parameter => $value) {
    if (Cursor::rejectOffsetParameters([$parameter => $value]) === null) {
        $problems[] = "'{$parameter}' was accepted. Offset pagination silently skips and duplicates rows "
            . 'under concurrent writes — an insert pushes a row onto the next page and it is exported '
            . 'twice; a delete pulls one back and it is never exported. In a payroll system somebody is '
            . 'always joining, leaving or being corrected';
    }
}

if (Cursor::rejectOffsetParameters(['cursor' => 'x', 'limit' => 50]) !== null) {
    $problems[] = 'a legitimate cursor query was rejected';
}

if (Cursor::limit(100000) !== 500 || Cursor::limit('abc') !== 50 || Cursor::limit(0) !== 1) {
    $problems[] = 'the limit is not clamped in one place, so an endpoint will invent its own maximum';
}

// No offset vocabulary anywhere under src/.
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src')) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php'
        || str_contains($file->getPathname(), '/Api/Cursor.php')) {
        continue;
    }

    $code = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '',
        (string) file_get_contents($file->getPathname()));

    if (preg_match("/->offset\(|'per_page'|\bLIMIT\s+\d+\s+OFFSET\b/i", $code) === 1) {
        $problems[] = str_replace($root . '/', '', $file->getPathname()) . ' uses offset pagination';
    }
}

$results[] = [
    'name' => 'Cursor pagination on every collection, and the offset vocabulary is refused',
    'problems' => $problems,
    'detail' => 'a cursor round-trips, carries its sort so it cannot be replayed against a different '
        . 'ordering (which would silently return a different window), and is refused when malformed; '
        . 'offset, page, per_page and skip are each rejected with the reason; the limit is clamped in one '
        . 'place; and nothing under src/ uses offset pagination',
];

// =============================================================================
// 5. NO SECOND SURFACE, AND NO SCREEN-SHAPED PAYLOAD
// =============================================================================

$problems = [];
$screenShaped = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src')) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = str_replace($root . '/', '', $file->getPathname());
    $code = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '',
        (string) file_get_contents($file->getPathname()));

    // A second route prefix is the defect R-XXIV-001 names.
    if (preg_match("#['\"]/(app|admin|ajax|internal)/#", $code) === 1) {
        $problems[] = "{$path} declares a route outside /api/v3. SmartPRS 2's `/app/data` returns a fixed "
            . '62-field projection built for one screen, salary and identifier included, with no '
            . 'field-level control';
    }

    // A method named for a screen returns a payload shaped by one.
    if (preg_match('/function\s+(for|render)(Dashboard|Screen|Page|View|Grid)\w*\s*\(/i', $code) === 1) {
        $screenShaped[] = $path;
    }
}

foreach ($screenShaped as $path) {
    $problems[] = "{$path} shapes a payload for a screen. A resource representation is defined ONCE and "
        . 'screens select fields from it; the alternative is a payload that changes when a designer moves '
        . 'a column';
}

$results[] = [
    'name' => '/api/v3 is the only surface, and no payload is shaped by a screen',
    'problems' => $problems,
    'detail' => 'nothing under src/ declares a route outside /api/v3 or shapes a response for a named '
        . 'screen — the React client authenticates as a first-party user and calls the identical routes a '
        . 'third party does, with the identical serialisation, authorisation and error envelope',
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

printf("\n%s  %d of %d /api/v3 contract properties proven\n", $failed === 0 ? 'PASS' : 'FAIL',
    count($results) - $failed, count($results));

exit($failed === 0 ? 0 : 1);
