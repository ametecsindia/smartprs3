<?php

/**
 * SmartPRS 3 — /api/v3, THE ONLY PROGRAMMATIC SURFACE.
 *
 *   php -S localhost:8000 -t public      then   /api.php/api/v3/employments
 *
 * `src/Platform/Api/` has been nine finished, tested classes — idempotency,
 * cursors, the error envelope, the closed code catalogue, the route registry and
 * its grammar, a schema validator, an OpenAPI generator and a contract differ —
 * with NO HTTP ENDPOINT ANYWHERE IN THE PRODUCT. Routes were registered inside a
 * developer console page, as a display. So there is no integration, no mobile
 * client, and no way for a biometric device's middleware to PUSH a punch:
 * attendance can only arrive when a person uploads a file. This is that endpoint,
 * and the one write on it is punch ingest, because that is the gap that costs a
 * customer money.
 *
 * IT FAILS CLOSED, EVERYWHERE, and that is the whole design rather than a habit:
 *
 *   An unknown route is a REFUSAL (404), never a 200 with an empty body. A path
 *   that exists under another method is a 405, so a client learns which of the
 *   two mistakes it made.
 *   An unknown query parameter is a refusal, not something quietly ignored — a
 *   filter that silently does nothing returns a page that looks right.
 *   EVERY non-2xx goes through `ErrorEnvelope`, including the 404, the 405 and
 *   the 500, because an application that shapes its own errors carefully and
 *   lets the framework's 500 out in a different form hands the client two shapes
 *   to parse and the second one at the worst moment.
 *   A 500 carries a reference and NOTHING else. The detail goes to the server
 *   log. SmartPRS 2 returns raw exception messages from 194 sites.
 *   A scope not held is a refusal even where the code path would have worked.
 *   No token, no hash and no secret is ever logged or echoed.
 *
 * TENANCY IS STRUCTURAL. The tenant comes from the CREDENTIAL ROW and from
 * nowhere else, and every read below goes through `(new SqlStore($pdo))
 * ->forTenant($tenant)`, which puts `WHERE tenant_id = ?` on the query itself. No
 * handler here builds a query and none of them can forget. A tenant, employee
 * or organisation id is never accepted from the caller as a scope — the id in the
 * path selects a row WITHIN the tenant the credential already decided.
 *
 * ponytail: one file, no framework, no router package, no dependency injection
 * container. The routes are a `RouteRegistry` — the real one, so the grammar, the
 * mandatory permission and the declared error codes are enforced at boot and this
 * file cannot register an endpoint the contract could not publish. The ceiling is
 * this file's length; the upgrade path is one handler per class the day there are
 * more than a dozen, and the registry declarations move with them unchanged.
 */
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    foreach ([
        'SmartPRS\\Platform\\' => '/../src/Platform/',
        'SmartPRS\\Surface\\' => '/../surface/',
    ] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $path = __DIR__ . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($path)) {
                require_once $path;
            }

            return;
        }
    }
});

use SmartPRS\Platform\Api\Cursor;
use SmartPRS\Platform\Api\ErrorCatalogue;
use SmartPRS\Platform\Api\ErrorEnvelope;
use SmartPRS\Platform\Api\Idempotency;
use SmartPRS\Platform\Api\RouteRegistry;
use SmartPRS\Platform\Attendance\Punch;
use SmartPRS\Platform\Identity\PasswordHasher;
use SmartPRS\Surface\ApiCredential;
use SmartPRS\Surface\Database;
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\PunchImport;
use SmartPRS\Surface\SqlStore;

/** How many punches one request may carry. A body is a request, not a migration. */
const API_PUNCH_MAXIMUM = 1000;

/** The largest body this endpoint reads, so a hostile caller cannot exhaust memory. */
const API_BODY_MAXIMUM_BYTES = 1_048_576;

// ---------------------------------------------------------------- the request
// A reference the caller can quote and the log can be searched by. It is the ONLY
// thing a 500 says, so it has to exist before anything that can fail.
$requestId = bin2hex(random_bytes(8));
$correlationId = trim((string) ($_SERVER['HTTP_X_CORRELATION_ID'] ?? '')) ?: $requestId;

/**
 * Send a response and stop. Every exit from this file goes through here or
 * through `refuse()`, so nothing can answer in a shape a client has not seen.
 */
$respond = static function (int $status, array $payload) use ($requestId, $correlationId): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Request-Id: ' . $requestId);
    header('X-Correlation-Id: ' . $correlationId);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";

    exit;
};

/**
 * A refusal, in the one envelope. The status comes from the CATALOGUE, never
 * from the call site — a code whose status varies by call site is a code a
 * client cannot handle, and `ErrorEnvelope` refuses the mismatch anyway.
 *
 * @param list<array{field: string, code: string, message: string}> $details
 */
$refuse = static function (string $code, string $message, array $details = [])
    use ($respond, $requestId, $correlationId): never {
    $respond(
        ErrorCatalogue::statusFor($code),
        ErrorEnvelope::build($code, ErrorCatalogue::statusFor($code), $message, $requestId,
            $correlationId, $details),
    );
};

// AN EXCEPTION ANYWHERE IS A 500 WITH A REFERENCE AND NO INTERNAL DETAIL. The
// detail goes to the server log, where an operator can read it and a caller
// cannot. Registered before the first line that can throw, because a handler
// installed after the failure is a handler that does not run.
set_exception_handler(static function (\Throwable $e) use ($respond, $requestId, $correlationId): void {
    // The reference ties this line to the body the caller got. Nothing from the
    // request is written here: a body can carry a device serial or an employee
    // code, and a log is the wrong place to keep either.
    error_log(sprintf('[smartprs3-api] %s %s: %s in %s:%d', $requestId, $e::class, $e->getMessage(),
        $e->getFile(), $e->getLine()));

    $respond(500, ErrorEnvelope::build('server.unexpected', 500, '', $requestId, $correlationId));
});

// ------------------------------------------------------------------ the routes
// The REAL registry, so every declaration below is checked at boot: the URL
// grammar (no PUT, no addressable tenant, no integer path parameter, plural
// kebab-case nouns), a MANDATORY permission, at least one scope, a response
// schema, and error codes that exist in the published catalogue. A route this
// file could not publish is a route it cannot serve.
$routes = new RouteRegistry();

$routes->register('GET', '/api/v3/employments', 'listEmployments', 'employee.list',
    ['employments.read'], 'EmploymentPage', errorCodes: ['request.unknown_filter', 'request.bad_cursor'],
    summary: 'One page of employees, cursor-paged.');

$routes->register('GET', '/api/v3/employments/{employment:uuid}', 'getEmployment', 'employee.view',
    ['employments.read'], 'Employment', errorCodes: ['resource.not_found'],
    summary: 'One employee, by its uuid.');

$routes->register('POST', '/api/v3/attendance-punches', 'ingestPunches', 'attendance.punch.ingest',
    ['attendance.write'], 'PunchIngestResult', requestSchema: 'PunchIngest',
    errorCodes: ['validation.failed', 'idempotency.key_required', 'idempotency.in_progress',
        'idempotency.key_reuse_conflict'],
    idempotent: true, summary: 'Push punches from a device or its middleware.');

// ----------------------------------------------------------------- match first
// The path, with the script name stripped so it works both under `php -S` (where
// it arrives as /api.php/api/v3/...) and behind a rewrite (where it arrives as
// /api/v3/... already).
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');

if ($script !== '' && str_starts_with($path, $script)) {
    $path = substr($path, strlen($script));
}

$path = rtrim($path, '/');

if ($path === '') {
    $path = '/';
}

$matched = null;
$parameters = [];
$otherMethods = [];

foreach ($routes->all() as $route) {
    // `{name:uuid}` and nothing else — the grammar has already refused anything
    // that is not typed as a UUID, so this is the only substitution there can be.
    $pattern = '#^' . preg_replace(
        '/\\\{[a-z_]+\\\:uuid\\\}/i',
        '([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})',
        preg_quote($route['path'], '#'),
    ) . '$#';

    if (preg_match($pattern, $path, $found) !== 1) {
        continue;
    }

    if ($route['method'] !== $method) {
        $otherMethods[] = $route['method'];

        continue;
    }

    $matched = $route;
    $parameters = array_slice($found, 1);
}

if ($matched === null && $otherMethods !== []) {
    header('Allow: ' . implode(', ', array_unique($otherMethods)));
    $refuse('request.method_not_allowed', "That path exists and does not accept {$method}. It "
        . 'accepts ' . implode(' or ', array_unique($otherMethods)) . '.');
}

if ($matched === null) {
    // A REFUSAL, never a 200 with an empty body. An endpoint that answers an
    // unknown path with success is one where a client's typo looks like a working
    // integration returning no rows.
    $refuse('resource.not_found', 'There is no such endpoint. Every route in this product is under '
        . '/api/v3, and a path outside it is refused rather than served: there is no second, '
        . 'private, SPA-only endpoint family.');
}

// -------------------------------------------------------------- the connection
$pdo = Database::connect(__DIR__ . '/../.env');

if ($pdo === null) {
    // Said out loud rather than falling back to file storage. An API that quietly
    // answers from a different store than the screens is one where a month of
    // punches lands somewhere nobody looks.
    $refuse('dependency.unavailable', 'The database is not available, so this request cannot be '
        . 'answered. It is refused rather than served from anywhere else.');
}

// ------------------------------------------------------------ who is calling
// The header, wherever this deployment puts it. Some server/PHP combinations do
// not populate HTTP_AUTHORIZATION, and a missing header would read as "no
// credential" — a refusal that looks like the client's fault.
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

if ($authorization === '' && function_exists('getallheaders')) {
    foreach ((array) getallheaders() as $name => $value) {
        if (strcasecmp((string) $name, 'Authorization') === 0) {
            $authorization = (string) $value;
        }
    }
}

$platform = new SqlStore($pdo);
$credentials = new ApiCredential($platform, PasswordHasher::platformDefault());
$credential = $credentials->authenticate($authorization);

if (!$credential['ok']) {
    // ONE SENTENCE FOR EVERY CAUSE. "No such prefix", "wrong secret", "expired"
    // and "revoked" are the same answer on the wire, because the difference
    // between them tells an attacker which half of the guess was right. The
    // stated reason goes to the log, where the operator is.
    error_log(sprintf('[smartprs3-api] %s auth refused: %s', $requestId, $credential['why']));

    header('WWW-Authenticate: Bearer');
    $refuse('auth.unauthenticated', 'That request was not authenticated. Present a credential as '
        . '`Authorization: Bearer <token>`. This answer is deliberately the same for a missing, '
        . 'unknown, mistyped, revoked and expired credential.');
}

// DENY BY DEFAULT. The route declares the scopes that reach it; a credential
// holding none of them is refused here, before the handler exists, so an endpoint
// cannot be reachable because its own body forgot to ask.
if (array_intersect($matched['scopes'], $credential['scopes']) === []) {
    $refuse('auth.insufficient_scope', 'This credential does not hold the scope this endpoint '
        . 'requires (' . implode(' or ', $matched['scopes']) . '). It is refused even though the '
        . 'credential is valid: a scope is what makes a token\'s blast radius statable, and an '
        . 'endpoint reachable without one is reachable by every integration you ever issued a key to.');
}

// THE TENANT COMES FROM THE CREDENTIAL ROW. Nothing below reads a tenant from the
// path, the query or the body, and `forTenant()` puts the clause on the query
// rather than leaving it to a handler to remember.
$store = $platform->forTenant($credential['tenant_id']);
$credentials->markUsed($store, $credential['credential_id']);

/** Every query parameter this endpoint knows, and a refusal for anything else. */
$onlyQuery = static function (array $allowed) use ($refuse): array {
    $offset = Cursor::rejectOffsetParameters($_GET);

    if ($offset !== null) {
        $refuse('request.unknown_filter', $offset);
    }

    $unknown = array_diff(array_keys($_GET), $allowed);

    if ($unknown !== []) {
        // Refused rather than ignored. A filter the server drops silently returns
        // more rows than were asked for and every one of them looks right.
        $refuse('request.unknown_filter', "This endpoint does not know the parameter(s) '"
            . implode("', '", $unknown) . "'. They are refused rather than ignored: a filter the "
            . 'server drops silently returns a page that looks correct and is not. Known: '
            . implode(', ', $allowed) . '.');
    }

    return $_GET;
};

/**
 * WHAT AN EMPLOYMENT LOOKS LIKE ON THE WIRE — a declared projection, not the row.
 *
 * SmartPRS 2's `/app/data` returns a fixed 62-field projection built for one
 * screen, salary and Aadhaar included, with no field-level control. So this names
 * the seven fields it publishes and nothing reaches a caller by being in the
 * table: no ctc, no bank account, no PAN, UAN or Aadhaar. Adding a field here is
 * a deliberate act somebody reviews.
 *
 * THE IDENTIFIER IS THE ROW'S `uuid`, never its integer id. `RouteGrammar`
 * refuses an integer path parameter because sequential ids are enumerable across
 * tenants, and a 404 that is deliberately indistinguishable from a 403 is worth
 * nothing if the caller can count. A row whose `uuid` was never filled in is
 * therefore NOT ADDRESSABLE, and this says so — with a null id and a count on the
 * page — rather than falling back to the integer, which would be the grammar's
 * one rule quietly undone by a nullable column.
 */
$asEmployment = static fn (array $row): array => [
    'id' => trim((string) ($row['uuid'] ?? '')) === '' ? null : (string) $row['uuid'],
    'code' => Employment::code($row),
    'name' => (string) ($row['name'] ?? ''),
    'status' => (string) ($row['status'] ?? ''),
    'joined_on' => Employment::joinedOn($row),
    'department' => (string) ($row['department'] ?? ''),
    'designation' => (string) ($row['designation'] ?? ''),
];

// ---------------------------------------------------------------- the handlers
if ($matched['operation_id'] === 'listEmployments') {
    $query = $onlyQuery(['cursor', 'limit']);
    $limit = Cursor::limit($query['limit'] ?? null);
    $sort = 'id:asc';
    $after = null;

    if (trim((string) ($query['cursor'] ?? '')) !== '') {
        try {
            $decoded = Cursor::decode((string) $query['cursor'], $sort);
        } catch (\InvalidArgumentException $e) {
            // The class's own sentence, which explains WHICH way the cursor was
            // wrong — its text is the documentation for this failure.
            $refuse('request.bad_cursor', $e->getMessage());
        }

        $after = (string) ($decoded['k']['id'] ?? '');
    }

    // ponytail: read the tenant's employees and window them in PHP. `RowStore`
    // pages by OFFSET, and an offset is exactly what a cursor exists to avoid —
    // an insert before the cursor pushes a row onto the next page and it is
    // exported twice, a delete pulls one back and it is never exported. The
    // ceiling is one whole-table read per page, which is what `PunchImport` and
    // the payroll register already do to this table. The upgrade path is a named
    // `SqlStore` method with `WHERE id > ? ORDER BY id LIMIT ?` — the cursor
    // already carries exactly that key, so nothing above this line changes.
    $rows = $store->all('employees');
    usort($rows, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

    $window = [];
    $unaddressable = 0;

    foreach ($rows as $row) {
        if ($after !== null && (int) $row['id'] <= (int) $after) {
            continue;
        }

        if (count($window) >= $limit) {
            break;
        }

        $window[] = $row;
    }

    foreach ($window as $row) {
        if (trim((string) ($row['uuid'] ?? '')) === '') {
            $unaddressable++;
        }
    }

    $last = $window === [] ? null : (string) $window[count($window) - 1]['id'];

    $respond(200, [
        'data' => array_map($asEmployment, $window),
        'page' => [
            'limit' => $limit,
            // A cursor only when there is a next page to ask for. Handing one back
            // on the last page makes a client loop once more to learn nothing.
            'next_cursor' => $last !== null && count($window) === $limit
                ? Cursor::encode(['id' => $last], $sort) : null,
            // Reported, never silent — the same rule as an unmapped punch. A row
            // with no uuid is in this page and cannot be fetched singly, and
            // saying so is the difference between a known gap and a client
            // concluding the record does not exist.
            'unaddressable' => $unaddressable,
        ],
    ]);
}

if ($matched['operation_id'] === 'getEmployment') {
    $onlyQuery([]);
    $wanted = strtolower($parameters[0] ?? '');
    $found = null;

    // Matched WITHIN the tenant the credential decided: `all()` is already
    // `WHERE tenant_id = ?`, so another tenant's uuid simply is not here — and
    // "not found" is the same answer as "outside your tenant" by design, because
    // a 403 for a record that exists elsewhere confirms that it exists.
    foreach ($store->all('employees') as $row) {
        if (strtolower(trim((string) ($row['uuid'] ?? ''))) === $wanted && $wanted !== '') {
            $found = $row;
        }
    }

    if ($found === null) {
        $refuse('resource.not_found', 'There is no such employee. This answer is deliberately '
            . 'identical for a record that does not exist and one that belongs to another tenant: a '
            . 'refusal that distinguishes them is an enumeration oracle wearing an access-control '
            . 'label.');
    }

    $respond(200, ['data' => $asEmployment($found)]);
}

// ------------------------------------------------------------- the one write
if ($matched['operation_id'] === 'ingestPunches') {
    // A query string on the write, too. This endpoint takes none, and a parameter
    // it ignores is one a client believes did something.
    $onlyQuery([]);

    if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
        $refuse('request.unsupported_media_type', 'This endpoint reads JSON. Send '
            . 'Content-Type: application/json.');
    }

    $raw = (string) file_get_contents('php://input', false, null, 0, API_BODY_MAXIMUM_BYTES + 1);

    if (strlen($raw) > API_BODY_MAXIMUM_BYTES) {
        $refuse('request.malformed', 'That body is larger than this endpoint reads ('
            . API_BODY_MAXIMUM_BYTES . ' bytes). Send fewer punches per request.');
    }

    try {
        $body = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        // The parser's own message is not repeated: it names byte offsets in a
        // body this endpoint has already refused to interpret.
        $refuse('request.malformed', 'That body is not JSON.');
    }

    if (!is_array($body) || !is_array($body['punches'] ?? null) || $body['punches'] === []) {
        $refuse('validation.failed', 'Send { "punches": [ … ] } with at least one punch.', [[
            'field' => 'punches',
            'code' => 'required',
            'message' => 'An ingest with no punches is refused rather than answered with a count of '
                . 'zero, which reads to a device\'s middleware as a successful upload.',
        ]]);
    }

    if (count($body['punches']) > API_PUNCH_MAXIMUM) {
        $refuse('validation.failed', 'That is more punches than one request carries.', [[
            'field' => 'punches',
            'code' => 'too_many',
            'message' => 'At most ' . API_PUNCH_MAXIMUM . ' per request; this one carries '
                . count($body['punches']) . '. Page them with separate idempotency keys.',
        ]]);
    }

    // VALIDATED AT THE BOUNDARY, FIELD BY FIELD, WITH THE INDEX ON EVERY FINDING.
    // A detail without a field cannot be shown next to the input that caused it,
    // which is the only reason details exist.
    $shapes = [
        'device_user_id' => ['/^[A-Za-z0-9._-]{1,64}$/', true,
            'the enrolment number the device recorded, as letters, digits, dot, dash or underscore'],
        'punch_at' => ['/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', true,
            'the instant, as YYYY-MM-DD HH:MM:SS in the business timezone — never with an offset, '
            . 'because the file import writes wall-clock instants and two punch sources meaning '
            . 'different things by the same column is unauditable'],
        'device_sn' => ['/^[A-Za-z0-9._-]{0,64}$/', false, 'the device serial'],
        'verify_mode' => ['/^[A-Za-z0-9._-]{0,32}$/', false, 'how the device verified the person'],
    ];
    $details = [];
    $punches = [];

    foreach ($body['punches'] as $index => $punch) {
        if (!is_array($punch)) {
            $details[] = ['field' => "punches[{$index}]", 'code' => 'malformed',
                'message' => 'Each punch is an object.'];

            continue;
        }

        $clean = [];

        foreach ($shapes as $field => [$pattern, $required, $what]) {
            $value = trim((string) ($punch[$field] ?? ''));

            if ($value === '' && !$required) {
                $clean[$field] = '';

                continue;
            }

            if (preg_match($pattern, $value) !== 1) {
                $details[] = ['field' => "punches[{$index}].{$field}", 'code' => 'malformed',
                    'message' => "Expected {$what}."];

                continue 2;
            }

            $clean[$field] = $value;
        }

        // NEVER INVENTED. A row whose direction cannot be read is refused, not
        // defaulted to `in`: a settled day computed from a guessed direction is a
        // payable day nobody can audit.
        $direction = strtolower(trim((string) ($punch['direction'] ?? '')));

        if (!in_array($direction, [Punch::IN, Punch::OUT], true)) {
            $details[] = ['field' => "punches[{$index}].direction", 'code' => 'malformed',
                'message' => "Expected '" . Punch::IN . "' or '" . Punch::OUT . "'. A direction is "
                    . 'never defaulted here: a settled day computed from a guessed one is a payable '
                    . 'day nobody can audit.'];

            continue;
        }

        $clean['direction'] = $direction;
        $punches[] = $clean;
    }

    if ($details !== []) {
        // NOTHING IS WRITTEN when any punch is malformed. A partial ingest leaves
        // the client unable to say which half landed, and its retry duplicates
        // whichever half did.
        $refuse('validation.failed', 'Some punches could not be read, so none were written.',
            array_slice($details, 0, 50));
    }

    /**
     * THE IMPORT ITSELF, THROUGH `PunchImport` AND NOT BESIDE IT.
     *
     * The punches are handed to the same class the file upload uses, as the
     * delimited text it already parses. That is deliberate: `PunchImport` owns the
     * enrolment-number lookup, the ambiguity refusal, the `external_id` natural
     * key and the "unmapped punches are reported and NOT written" rule, and a
     * second implementation of any of those here would be the one that gets it
     * wrong. The validation above guarantees no field can contain the delimiter.
     *
     * ponytail: a tab-delimited round trip rather than a new entry point. The
     * ceiling is that the layout is fixed here and the fields must be
     * delimiter-free — which they are, by refusal, not by escaping. The upgrade
     * path is an `importPunches(array $punches)` on `PunchImport` that both this
     * and `import()` call; it changes nothing about the result or the natural key.
     */
    $layout = [
        'name' => 'api',
        'delimiter' => "\t",
        'columns' => ['device_user_id' => 0, 'punch_at' => 1, 'direction' => 2,
            'device_sn' => 3, 'verify_mode' => 4],
        'timestamp_format' => 'Y-m-d H:i:s',
        'directions' => [Punch::IN => Punch::IN, Punch::OUT => Punch::OUT],
        'identifies' => (string) ($body['identifies'] ?? 'device_user_id'),
    ];

    $text = implode("\n", array_map(static fn (array $p): string => implode("\t", [
        $p['device_user_id'], $p['punch_at'], $p['direction'], $p['device_sn'], $p['verify_mode'],
    ]), $punches));

    $import = new PunchImport($store);
    $endpoint = $matched['method'] . ' ' . $matched['path'];
    $key = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));

    /**
     * IDEMPOTENCY AND THE NATURAL KEY DO DIFFERENT JOBS, and both are here.
     *
     * `Idempotency` answers "is this the SAME REQUEST arriving twice?" — a
     * timeout, a retry, a middleware that did not see the 200. `PunchImport`'s
     * `external_id` answers "is this the SAME PUNCH arriving twice?" — a client
     * that re-sends yesterday's export under a new key is not replaying, it is
     * duplicating, and only the data can see that. R-XXIV-034 says so in as many
     * words, and this is the endpoint it was written about.
     *
     * THE RECORD IS WRITTEN IN THE SAME TRANSACTION AS THE EFFECT (R-XXIV-033),
     * which is why the transaction is opened HERE, before `run()`, and the work is
     * a callable inside it rather than something recorded afterwards.
     *
     * ponytail: `Idempotency` keeps its records in a PHP array, so they live for
     * one request and this endpoint can only ever answer `first_use` or refuse a
     * missing key — the replay, in-flight and key-reuse rows of its table are real
     * and are exercised in tests, and across processes it is the NATURAL KEY that
     * actually protects the data. The ceiling is named rather than hidden because
     * it is load-bearing: closing it needs an `api_idempotency_keys` table, which
     * needs a migration, and this account has no DDL by design.
     */
    $pdo->beginTransaction();

    try {
        $outcome = (new Idempotency())->run(
            tenantId: $credential['tenant_id'],
            credentialId: $credential['credential_id'],
            endpoint: $endpoint,
            key: $key === '' ? null : $key,
            requestBody: $body,
            work: static fn (): array => $import->import($text, $layout),
            transactionOpen: true,
            now: time(),
        );
    } catch (\Throwable $e) {
        $pdo->rollBack();

        throw $e;
    }

    if ($outcome['outcome'] === Idempotency::CONFLICT
        || $outcome['outcome'] === Idempotency::IN_PROGRESS) {
        // NOTHING RAN. The class returns these without executing the work, so the
        // transaction is rolled back rather than committed empty.
        $pdo->rollBack();

        $code = (string) ($outcome['response']['code'] ?? 'state.conflict');
        $refuse($code, match ($code) {
            'idempotency.key_required' => 'This endpoint requires an Idempotency-Key header. A '
                . 'create replayed on a timeout is a duplicate punch, a duplicate payment or a '
                . 'second approval, and the caller cannot tell which of the two attempts landed.',
            'idempotency.in_progress' => 'A request with this idempotency key is still running. '
                . 'Retry it; this is the one refusal in this API that is safe to repeat.',
            default => 'This idempotency key was already used with a DIFFERENT body, so nothing '
                . 'was executed. Two different requests believe they are the same operation, and '
                . 'running either would be a coin flip about which effect lands.',
        });
    }

    $pdo->commit();

    $result = (array) $outcome['response'];

    $respond(201, [
        'data' => [
            'parsed' => $result['parsed'],
            'imported' => $result['imported'],
            // The natural key at work: the same punch sent again, under any key or
            // none, is counted here and written once.
            'already_present' => $result['already_present'],
            // REPORTED AND NOT WRITTEN, the same rule as the file import. An
            // unmapped punch is somebody who worked and will not be paid for it;
            // a punch attached to a plausible employee pays the wrong person.
            // The first is a finding, the second is unrecoverable.
            'unmapped' => (object) $result['unmapped'],
            'ambiguous' => (object) $result['ambiguous'],
            'unparsable' => $result['unparsable'],
            'devices' => (object) $result['devices'],
        ],
        'idempotency' => ['outcome' => $outcome['outcome'], 'replayed' => $outcome['replayed']],
    ]);
}

// Unreachable: every registered operation is handled above. Said out loud anyway,
// because "fell off the end of the router" must never be a 200 with no body.
$refuse('server.unexpected', '');
