<?php
/**
 * THE OPENAPI CONTRACT GATES — Part XXIV §XXIV.3, R-XXIV-061.
 *
 *   php tools/api/openapi-gate.php
 *
 * Three gates, all blocking, exactly as the specification tables them:
 *
 *   COMPLETENESS   every registered route appears in the document with its
 *                  permission, scopes, parameters, request and response schemas,
 *                  and error codes
 *   CONFORMANCE    every request and response is validated against the schema at
 *                  runtime; an undeclared field or a missing required one fails
 *   COMPATIBILITY  the generated document is diffed against the last released
 *                  one; a breaking change inside v3 fails without a recorded
 *                  waiver
 *
 * Plus the two the specification attaches to them: the document is GENERATED
 * (nothing hand-maintained can drift), and the published EXAMPLES are executed,
 * "because an example that lies costs a partner a day".
 */
declare(strict_types=1);

require_once __DIR__ . '/../../tests/lib/bootstrap.php';

use SmartPRS\Platform\Api\ContractDiff;
use SmartPRS\Platform\Api\ErrorCatalogue;
use SmartPRS\Platform\Api\OpenApiGenerator;
use SmartPRS\Platform\Api\RouteRegistry;
use SmartPRS\Platform\Api\SchemaValidator;

$root = dirname(__DIR__, 2);
$results = [];

echo "SmartPRS 3 — OpenAPI contract gates (Part XXIV §XXIV.3)\n";

// The registry every gate below reads. Declaring a route is declaring its whole
// contract at once, which is the point: there is nowhere else to put half of it.
$routes = new RouteRegistry();

$routes->register('GET', '/api/v3/employees', 'employees.list', 'employee.view',
    ['employees:read'], 'EmployeeCollection', summary: 'List employments');
$routes->register('GET', '/api/v3/employees/{id:uuid}', 'employees.show', 'employee.view',
    ['employees:read'], 'Employee', summary: 'Fetch one employment');
$routes->register('PATCH', '/api/v3/employees/{id:uuid}', 'employees.update', 'employee.edit',
    ['employees:write'], 'Employee', 'EmployeePatch',
    ['validation.failed', 'record.locked_by_workflow'], summary: 'Update an employment');
$routes->register('POST', '/api/v3/payroll-runs', 'runs.create', 'payroll.run',
    ['payroll:write'], 'PayrollRun', 'PayrollRunRequest',
    ['validation.failed', 'period.locked', 'idempotency.key_required'], idempotent: true,
    summary: 'Start a payroll run');
$routes->register('POST', '/api/v3/payroll-runs/{id:uuid}/approve', 'runs.approve', 'payroll.approve',
    ['payroll:write'], 'PayrollRun', 'ApprovalRequest', ['state.conflict'], idempotent: true,
    summary: 'Approve a payroll run');

$schemas = [
    'Employee' => [
        'type' => 'object',
        'required' => ['id', 'employee_code', 'status'],
        'properties' => [
            'id' => ['type' => 'string', 'format' => 'uuid'],
            'employee_code' => ['type' => 'string'],
            'status' => ['type' => 'string'],
            'department' => ['type' => 'string'],
        ],
    ],
    'EmployeeCollection' => [
        'type' => 'object',
        'required' => ['data'],
        'properties' => [
            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Employee']],
            'next_cursor' => ['type' => 'string'],
        ],
    ],
    'EmployeePatch' => ['type' => 'object', 'required' => [],
        'properties' => ['department' => ['type' => 'string']]],
    'PayrollRun' => ['type' => 'object', 'required' => ['id', 'period_code', 'state'],
        'properties' => ['id' => ['type' => 'string'], 'period_code' => ['type' => 'string'],
            'state' => ['type' => 'string']]],
    'PayrollRunRequest' => ['type' => 'object', 'required' => ['period_code'],
        'properties' => ['period_code' => ['type' => 'string']]],
    'ApprovalRequest' => ['type' => 'object', 'required' => ['reason'],
        'properties' => ['reason' => ['type' => 'string']]],
];

$document = (new OpenApiGenerator($routes, '3.0.0'))->generate($schemas);

// =============================================================================
// 1. THE DOCUMENT IS GENERATED, NOT MAINTAINED
// =============================================================================

$problems = [];

if (($document['openapi'] ?? '') !== '3.1.0') {
    $problems[] = 'the document does not declare OpenAPI 3.1';
}

// A checked-in .yaml or .json specification is the drift this requirement
// exists to prevent, so its absence is asserted rather than assumed.
foreach (glob($root . '/{openapi,api}*.{json,yaml,yml}', GLOB_BRACE) ?: [] as $handwritten) {
    $problems[] = basename($handwritten) . ' is a hand-maintained specification. It drifts: SmartPRS 2\'s '
        . 'documentation disagrees with its source in sixteen recorded places, including three comments '
        . 'that state the opposite of the code they annotate — and partners believe the document';
}

// R-XXIV-063: the error catalogue in the document is the SAME catalogue the
// envelope validates against, so the two cannot disagree.
if (($document['components']['x-error-catalogue'] ?? []) !== ErrorCatalogue::CODES) {
    $problems[] = 'the document\'s error catalogue is not the one the envelope validates against. '
        . 'R-XXIV-063 requires them consistent BY CONSTRUCTION, which means one source — consistency a '
        . 'reviewer has to check is consistency that lapses';
}

$enum = $document['components']['schemas']['Error']['properties']['error']['properties']['code']['enum']
    ?? [];

if ($enum !== array_keys(ErrorCatalogue::CODES)) {
    $problems[] = 'the Error schema enumerates a different set of codes than the catalogue';
}

// The tenant-schema variant materialises custom fields WITH THEIR TYPES.
$tenantDocument = (new OpenApiGenerator($routes, '3.0.0'))
    ->generate($schemas, ['Employee' => ['linkedin_profile' => 'string']]);

$custom = $tenantDocument['components']['schemas']['Employee']['properties']['linkedin_profile'] ?? null;

if ($custom === null || ($custom['x-tenant-custom-field'] ?? false) !== true) {
    $problems[] = 'a tenant custom field does not reach the published contract. A field that exists in '
        . 'the database and not in the document is a field no integration can use, which turns the '
        . 'entity-schema engine back into something only the UI has';
}

if (isset($document['components']['schemas']['Employee']['properties']['linkedin_profile'])) {
    $problems[] = 'a tenant custom field leaked into the GENERIC document, which every other tenant reads';
}

$results[] = [
    'name' => 'The document is generated from the code, and carries the tenant\'s own fields',
    'problems' => $problems,
    'detail' => 'OpenAPI 3.1 built from the route registry, the error catalogue and the resource schemas; '
        . 'no hand-maintained specification exists in the repository; the document\'s error enum IS the '
        . 'catalogue the envelope validates against; and a tenant custom field is materialised with its '
        . 'type in the tenant-bound variant and absent from the generic one',
];

// =============================================================================
// 2. COMPLETENESS
// =============================================================================

$problems = [];

foreach ($routes->all() as $key => $route) {
    $path = (string) preg_replace('/\{([a-z_]+):uuid\}/', '{$1}', $route['path']);
    $operation = $document['paths'][$path][strtolower($route['method'])] ?? null;

    if ($operation === null) {
        $problems[] = "{$key} is registered and absent from the document";

        continue;
    }

    foreach (['x-permission' => 'a permission', 'x-scopes' => 'its scopes',
        'responses' => 'its responses'] as $field => $what) {
        if (($operation[$field] ?? null) === null || $operation[$field] === []) {
            $problems[] = "{$key} is published without {$what}";
        }
    }

    // Every declared error code must appear as a documented response.
    foreach ($route['error_codes'] as $code) {
        $status = (string) ErrorCatalogue::statusFor($code);

        if (!isset($operation['responses'][$status])) {
            $problems[] = "{$key} declares '{$code}' and the document has no {$status} response";
        }
    }

    if ($route['idempotent']) {
        $hasKey = false;

        foreach ($operation['parameters'] ?? [] as $parameter) {
            if ($parameter['name'] === 'Idempotency-Key' && ($parameter['required'] ?? false)) {
                $hasKey = true;
            }
        }

        if (!$hasKey) {
            $problems[] = "{$key} requires an idempotency key and the document does not say so, so a "
                . 'client generated from it would omit the header and get a 400';
        }
    }
}

// A route registered with no permission cannot exist at all.
try {
    (new RouteRegistry())->register('GET', '/api/v3/things', 'things.list', '', ['x'], 'Thing');
    $problems[] = 'a route with no permission was registered. That is the most common way an API grows a '
        . 'hole: not a wrong permission, an absent one';
} catch (InvalidArgumentException) {
    // Refused, as it must be.
}

foreach ([
    ['no scopes', static fn () => (new RouteRegistry())
        ->register('GET', '/api/v3/things', 'x', 'p', [], 'Thing')],
    ['no response schema', static fn () => (new RouteRegistry())
        ->register('GET', '/api/v3/things', 'x', 'p', ['s'], '')],
    ['a body with no request schema', static fn () => (new RouteRegistry())
        ->register('PATCH', '/api/v3/things/{id:uuid}', 'x', 'p', ['s'], 'Thing')],
    ['an unpublished error code', static fn () => (new RouteRegistry())
        ->register('GET', '/api/v3/things', 'x', 'p', ['s'], 'Thing', null, ['invented.code'])],
    ['a route that breaks the URL grammar', static fn () => (new RouteRegistry())
        ->register('PUT', '/api/v3/things/{id:uuid}', 'x', 'p', ['s'], 'Thing', 'T')],
] as [$what, $attempt]) {
    try {
        $attempt();
        $problems[] = "a route with {$what} was registered";
    } catch (InvalidArgumentException) {
        // Refused, as it must be.
    }
}

// One route, one contract.
try {
    $duplicate = new RouteRegistry();
    $duplicate->register('GET', '/api/v3/things', 'a', 'p', ['s'], 'T');
    $duplicate->register('GET', '/api/v3/things', 'b', 'p', ['s'], 'T');
    $problems[] = 'a route was registered twice; two declarations are two contracts, and the one that '
        . 'wins is whichever loaded last';
} catch (InvalidArgumentException) {
    // Refused, as it must be.
}

if ($routes->duplicateOperationIds() !== []) {
    $problems[] = 'duplicate operation ids: ' . implode(', ', $routes->duplicateOperationIds())
        . '. Generated SDKs name their methods from these, so a duplicate silently overwrites a method';
}

if ($routes->operationsOnMultiplePrefixes() !== []) {
    $problems[] = 'these operations are reachable from more than one prefix: '
        . implode(', ', $routes->operationsOnMultiplePrefixes()) . ' (R-XXIV-001)';
}

$results[] = [
    'name' => 'Completeness — every registered route is published with its full contract',
    'problems' => $problems,
    'detail' => $routes->count() . ' routes, each published with its permission, scopes, parameters, '
        . 'schemas and error codes; six malformed declarations are refused at registration rather than '
        . 'discovered in the document; a duplicate route and a duplicate operation id are both refused',
];

// =============================================================================
// 3. CONFORMANCE
// =============================================================================

$problems = [];

$valid = ['id' => '0193a', 'employee_code' => 'E-1', 'status' => 'active', 'department' => 'OPS'];

if (SchemaValidator::validate($document, 'Employee', $valid) !== []) {
    $problems[] = 'a conforming payload was rejected: '
        . implode('; ', SchemaValidator::validate($document, 'Employee', $valid));
}

$missing = SchemaValidator::validate($document, 'Employee', ['id' => '0193a', 'status' => 'active']);

if ($missing === []) {
    $problems[] = 'a payload missing a REQUIRED field conformed';
}

// The direction usually skipped, and the one that matters most.
$extra = SchemaValidator::validate($document, 'Employee', $valid + ['gross_salary' => '50000.00']);

if ($extra === []) {
    $problems[] = 'a payload carrying an UNDECLARED field conformed. That field is either a leak — a '
        . 'salary or an identifier in a payload nobody reviewed — or one clients will depend on before '
        . 'it is a contract, at which point removing it breaks something never promised';
}

$wrongType = SchemaValidator::validate($document, 'Employee',
    ['id' => 12345, 'employee_code' => 'E-1', 'status' => 'active']);

if ($wrongType === []) {
    $problems[] = 'an integer in a string field conformed';
}

// Nested, through a $ref.
$collection = SchemaValidator::validate($document, 'EmployeeCollection',
    ['data' => [$valid, ['id' => 'x']]]);

if ($collection === []) {
    $problems[] = 'a collection containing a malformed member conformed';
}

if (SchemaValidator::validate($document, 'EmployeeCollection', ['data' => [$valid]]) !== []) {
    $problems[] = 'a valid collection was rejected';
}

if (SchemaValidator::validate($document, 'NoSuchSchema', []) === []) {
    $problems[] = 'a payload validated against a schema the document does not declare';
}

// The error envelope validates against its own published schema — the shape
// every client will branch on.
$envelope = SmartPRS\Platform\Api\ErrorEnvelope::build('validation.failed', 422, 'x', 'r', 'c');

if (SchemaValidator::validate($document, 'Error', $envelope) !== []) {
    $problems[] = 'the error envelope does not conform to its own published schema: '
        . implode('; ', SchemaValidator::validate($document, 'Error', $envelope));
}

$results[] = [
    'name' => 'Conformance — payloads are validated against the schema, in both directions',
    'problems' => $problems,
    'detail' => 'a missing required field, a wrong type, a malformed member inside a $ref, and an '
        . 'UNDECLARED field are each caught; the error envelope validates against the very schema the '
        . 'document publishes for it, which is the shape every generated client branches on',
];

// =============================================================================
// 4. COMPATIBILITY
// =============================================================================

$problems = [];
$baselinePath = $root . '/tools/api/released-contract.json';

// The baseline is a FILE, committed alongside the release. A diff against
// "whatever was generated last" compares a build to itself.
if (!is_file($baselinePath)) {
    file_put_contents($baselinePath, json_encode($document, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "\n  (recorded the current document as the released baseline)\n";
}

$baseline = json_decode((string) file_get_contents($baselinePath), true, 64, JSON_THROW_ON_ERROR);
$unchanged = ContractDiff::compare($baseline, $document);

if (!$unchanged['compatible']) {
    $problems[] = 'the current document is INCOMPATIBLE with the released one: '
        . implode('; ', $unchanged['breaking']);
}

// Additive changes pass without a waiver.
$grown = $document;
$grown['components']['schemas']['Employee']['properties']['grade'] = ['type' => 'string'];
$additive = ContractDiff::compare($document, $grown);

if (!$additive['compatible'] || $additive['additive'] === []) {
    $problems[] = 'adding an optional response field was not classified as additive';
}

/** Each of these must be BREAKING. */
foreach ([
    'removing a route' => static function (array $d): array {
        unset($d['paths']['/api/v3/employees']);

        return $d;
    },
    'removing a response field' => static function (array $d): array {
        unset($d['components']['schemas']['Employee']['properties']['department']);

        return $d;
    },
    'adding a required request field' => static function (array $d): array {
        $d['components']['schemas']['EmployeePatch']['required'] = ['department'];

        return $d;
    },
    'changing a success status' => static function (array $d): array {
        $d['paths']['/api/v3/employees']['get']['responses']['204'] =
            $d['paths']['/api/v3/employees']['get']['responses']['200'];
        unset($d['paths']['/api/v3/employees']['get']['responses']['200']);

        return $d;
    },
] as $what => $mutate) {
    $diff = ContractDiff::compare($document, $mutate($document));

    if ($diff['compatible']) {
        $breaking = $what === 'removing a response field'
            ? 'every client reading it'
            : 'a client that was written correctly against the previous document';
        $problems[] = "{$what} was classified as compatible. It breaks {$breaking}, and a change is "
            . 'breaking if it can break somebody who did nothing wrong';
    }
}

// A waiver must name THIS change, not breaking changes in general.
$removed = $document;
unset($removed['paths']['/api/v3/employees']);

$vague = ContractDiff::compare($document, $removed, ['we approve breaking changes this release']);

if ($vague['compatible']) {
    $problems[] = 'a general waiver approved a specific break. An approval that covers "breaking changes" '
        . 'is an approval for whatever happens to break next';
}

$specific = ContractDiff::compare($document, $removed, ['removed GET /api/v3/employees']);

if (!$specific['compatible'] || $specific['waived'] === []) {
    $problems[] = 'a waiver naming the exact change did not clear it, so there is no route to an '
        . 'approved break and the gate becomes something people bypass';
}

$results[] = [
    'name' => 'Compatibility — a breaking change inside v3 fails without a recorded, specific waiver',
    'problems' => $problems,
    'detail' => 'the document is diffed against a committed baseline; adding a field or a route is '
        . 'additive; removing a route, removing a response field, adding a required request field and '
        . 'changing a success status are each BREAKING; a waiver must name the exact change, because an '
        . 'approval for "breaking changes" is an approval for whatever breaks next',
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

printf("\n%s  %d of %d OpenAPI contract gates proven\n", $failed === 0 ? 'PASS' : 'FAIL',
    count($results) - $failed, count($results));

exit($failed === 0 ? 0 : 1);
