<?php

declare(strict_types=1);

use SmartPRS\Platform\Api\Cursor;
use SmartPRS\Platform\Api\ErrorCatalogue;
use SmartPRS\Platform\Api\ErrorEnvelope;
use SmartPRS\Platform\Api\Idempotency;
use SmartPRS\Platform\Api\RouteGrammar;

group('api');

same([], RouteGrammar::check(['method' => 'GET', 'path' => '/api/v3/salary-structures/{id:uuid}',
    'operation' => 'x']), 'a well-formed route is admissible');

ok(RouteGrammar::check(['method' => 'PUT', 'path' => '/api/v3/employees/{id:uuid}', 'operation' => 'x'])
    !== [], 'PUT is absent from this API rather than discouraged — a full-replace update is how SmartPRS 2 '
    . 'nulls every omitted key, and a documented hazard is one somebody uses on a Friday');

ok(RouteGrammar::check(['method' => 'GET', 'path' => '/api/v3/employees/{id}', 'operation' => 'x']) !== [],
    'an integer path parameter is refused: sequential ids are enumerable across tenants, and a 404 '
    . 'deliberately indistinguishable from a 403 is worth nothing if the caller can count');

ok(RouteGrammar::check(['method' => 'GET', 'path' => '/api/v3/tenants/{id:uuid}/employees',
    'operation' => 'x']) !== [], 'the tenant is never addressable — the moment it is, it is forgeable');

ok(RouteGrammar::check(['method' => 'GET', 'path' => '/app/data', 'operation' => 'x']) !== [],
    'there is no second, private, SPA-only surface');

// -----------------------------------------------------------------------------

same(422, ErrorCatalogue::statusFor('validation.failed'), 'a code has exactly one status');
same([], ErrorCatalogue::malformedCodes(), 'every published code is namespace.reason');

throws(LogicException::class,
    static fn () => ErrorEnvelope::build('invented.here', 422, 'x', 'r', 'c'),
    'an ad-hoc code is refused — clients branch on `code`, and one no client has seen arrives as an '
    . "unhandled branch in somebody else's software");

throws(LogicException::class,
    static fn () => ErrorEnvelope::build('validation.failed', 400, 'x', 'r', 'c'),
    'and a published code returned under the wrong status is refused too');

$server = ErrorEnvelope::build('server.unexpected', 500,
    'SQLSTATE[42S02] in /var/www/app/Repo.php line 88', 'r', 'c');

same([], ErrorEnvelope::leaks($server),
    'a 500 carries a request id and nothing else; SmartPRS 2 returns raw exception messages from 194 '
    . 'sites, disclosing schema and paths to any authenticated caller');
ok(ErrorEnvelope::leaks(['e' => 'in /var/www/x.php line 1']) !== [],
    'and the leak detector detects a leak, so a clean result means clean rather than broken');

ok(!ErrorEnvelope::retryable('validation.failed', 422),
    'retryable is a CONTRACT: a client that retries a validation failure submits twice');
ok(ErrorEnvelope::retryable('rate.exceeded', 429), 'and a throttle is retryable');

// -----------------------------------------------------------------------------

$keys = new Idempotency();
$calls = 0;
$work = static function () use (&$calls): array { $calls++; return ['n' => $calls]; };

$keys->run('T', 'C', 'E', 'k', ['a' => 1], $work, true, 1000);
$replay = $keys->run('T', 'C', 'E', 'k', ['a' => 1], $work, true, 1001);

same(1, $calls, 'a replay does not execute again');
ok($replay['replayed'], 'and says it was replayed');

$conflict = $keys->run('T', 'C', 'E', 'k', ['a' => 2], $work, true, 1002);
same(Idempotency::CONFLICT, $conflict['outcome'],
    'a key reused with a different body is refused and NEVER executed — two different requests believe '
    . 'they are the same operation, and running either is a coin flip about which effect lands');
same(1, $calls, 'and nothing ran');

throws(LogicException::class,
    static fn () => $keys->run('T', 'C', 'E', 'k2', [], $work, transactionOpen: false, now: 1),
    'the key and the effect share a transaction: recording it after a commit is not idempotent under a '
    . 'crash between the two — the money moved, nothing knows, and the retry moves it again');

// -----------------------------------------------------------------------------

$cursor = Cursor::encode(['id' => 'x'], 'created_at,id');
same('x', Cursor::decode($cursor, 'created_at,id')['k']['id'], 'a cursor round-trips');

throws(InvalidArgumentException::class, static fn () => Cursor::decode($cursor, 'name,id'),
    'a cursor replayed against a different ordering is refused — the same cursor under a different sort '
    . 'points at a different window, and nothing in the response would say so');

ok(Cursor::rejectOffsetParameters(['page' => 2]) !== null,
    'offset pagination is refused: an insert pushes a row onto the next page and it is exported twice, a '
    . 'delete pulls one back and it is never exported, and in payroll somebody is always joining');
same(null, Cursor::rejectOffsetParameters(['cursor' => 'x']), 'a cursor query is fine');
same(500, Cursor::limit(999999), 'the limit is clamped in one place');

// -----------------------------------------------------------------------------
// The route registry, the generated document, and the three contract gates.
// -----------------------------------------------------------------------------

use SmartPRS\Platform\Api\ContractDiff;
use SmartPRS\Platform\Api\OpenApiGenerator;
use SmartPRS\Platform\Api\RouteRegistry;
use SmartPRS\Platform\Api\SchemaValidator;

group('openapi');

$registry = new RouteRegistry();
$registry->register('GET', '/api/v3/employees', 'employees.list', 'employee.view',
    ['employees:read'], 'EmployeeCollection');

same(1, $registry->count(), 'a well-formed route registers');

throws(InvalidArgumentException::class,
    static fn () => (new RouteRegistry())->register('GET', '/api/v3/x', 'x', '', ['s'], 'T'),
    'a route with no permission cannot exist — that is the most common way an API grows a hole: not a '
    . 'wrong permission, an absent one');

throws(InvalidArgumentException::class,
    static fn () => (new RouteRegistry())->register('GET', '/api/v3/x', 'x', 'p', ['s'], 'T', null,
        ['invented.code']),
    'a route declaring an unpublished error code is refused — a client can only handle what it knows '
    . 'can happen');

throws(InvalidArgumentException::class, static function () use ($registry): void {
    $registry->register('GET', '/api/v3/employees', 'other', 'p', ['s'], 'T');
}, 'one route, one contract — two declarations are two contracts and the last one loaded wins');

$schemas = [
    'Employee' => ['type' => 'object', 'required' => ['id'],
        'properties' => ['id' => ['type' => 'string'], 'dept' => ['type' => 'string']]],
    'EmployeeCollection' => ['type' => 'object', 'required' => ['data'],
        'properties' => ['data' => ['type' => 'array',
            'items' => ['$ref' => '#/components/schemas/Employee']]]],
];

$doc = (new OpenApiGenerator($registry, '3.0.0'))->generate($schemas);

same('3.1.0', $doc['openapi'], 'the document is OpenAPI 3.1, generated rather than maintained');
ok(isset($doc['paths']['/api/v3/employees']['get']['x-permission']),
    'and the permission travels into it — authorisation documented only in code is discovered by '
    . 'getting a 403');

$tenantDoc = (new OpenApiGenerator($registry, '3.0.0'))
    ->generate($schemas, ['Employee' => ['linkedin' => 'string']]);

ok(isset($tenantDoc['components']['schemas']['Employee']['properties']['linkedin']),
    'a tenant custom field reaches the published contract, which is what makes adding one a '
    . 'configuration task all the way out to the API');
ok(!isset($doc['components']['schemas']['Employee']['properties']['linkedin']),
    'and it does not leak into the generic document every other tenant reads');

same([], SchemaValidator::validate($doc, 'Employee', ['id' => 'x', 'dept' => 'OPS']),
    'a conforming payload conforms');
ok(SchemaValidator::validate($doc, 'Employee', ['dept' => 'OPS']) !== [],
    'a missing required field is caught');
ok(SchemaValidator::validate($doc, 'Employee', ['id' => 'x', 'salary' => '50000']) !== [],
    'and so is an UNDECLARED one — that field is either a leak, or one clients depend on before it is a '
    . 'contract, at which point removing it breaks something never promised');
ok(SchemaValidator::validate($doc, 'EmployeeCollection', ['data' => [['dept' => 'x']]]) !== [],
    'validation reaches through a $ref into collection members');

$grown = $doc;
$grown['components']['schemas']['Employee']['properties']['grade'] = ['type' => 'string'];
ok(ContractDiff::compare($doc, $grown)['compatible'], 'adding an optional field is additive');

$shrunk = $doc;
unset($shrunk['components']['schemas']['Employee']['properties']['dept']);
ok(!ContractDiff::compare($doc, $shrunk)['compatible'],
    'removing a response field is BREAKING — a change is breaking if it can break somebody who did '
    . 'nothing wrong');

$gone = $doc;
unset($gone['paths']['/api/v3/employees']);
ok(!ContractDiff::compare($doc, $gone, ['we approve breaking changes'])['compatible'],
    'a general waiver does not approve a specific break');
ok(ContractDiff::compare($doc, $gone, ['removed GET /api/v3/employees'])['compatible'],
    'but a waiver naming the exact change does, so there is a route to an approved break and the gate '
    . 'is not something people bypass');
