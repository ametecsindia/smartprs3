<?php

declare(strict_types=1);

/**
 * POINT A TOKEN AT /api/v3 AND TRY TO GET SOMETHING FOR NOTHING.
 *
 * `tools/exposure.php` signs in as an ordinary employee and asks "did a SECRET
 * come back?". `tools/scope.php` asks "WHOSE ROW is this?". Both of them are
 * about a browser session. This one is about the thing that has no session at
 * all: a bearer token held by an integration, a mobile client or the middleware
 * beside a biometric device — the surface with no CSRF, no cookie, no sign-in
 * page, and therefore none of the accidental protection those bring.
 *
 * SIX REFUSALS ARE ASSERTED, and every one of them is a way an API is opened by
 * accident rather than by decision:
 *
 *   no credential at all          401 — the endpoint that is open by omission
 *   a token that was never issued 401 — the prefix nothing carries
 *   an EXPIRED credential         401 — the key whose expiry nothing reads
 *   an INACTIVE credential        401 — revocation that keeps working for reads
 *   a valid token WITHOUT the scope 403 — the endpoint reachable by every key
 *   ANOTHER TENANT'S credential   404 — the whole point of structural tenancy
 *
 * AND THE POSITIVE CONTROL, which is what stops this being a sweep over nothing.
 * A credential that IS valid, DOES hold the scope and IS in the right tenant must
 * read its own employment back, by name. If it cannot, every refusal above was a
 * broken endpoint refusing everybody — indistinguishable, on the screen, from an
 * endpoint that is correctly closed. That is the shape of vacuous pass this
 * codebase keeps finding, so it exits 2 rather than printing a clean sweep.
 *
 * IT REFUSES TO RUN (exit 2) if: there is no database, there are not two tenants
 * to tell apart, a credential could not be minted, the built-in server could not
 * be started or reached, or the positive control did not come back. A sweep that
 * authenticated nothing finds no leaks and prints exactly like one that found
 * none.
 *
 * NOTHING IS LEFT BEHIND. Five credentials and two employments are created and
 * deleted, and the row counts of every table it touches are taken before and
 * compared after — `tests/modules_rollback_test.php` exists because a tool in
 * this repository once promised it kept nothing and had been writing rows into
 * seventy-seven tables on every run.
 *
 * Usage: php tools/api-sweep.php
 */

require_once __DIR__ . '/../surface/RowStore.php';
require_once __DIR__ . '/../surface/Store.php';
require_once __DIR__ . '/../surface/SqlStore.php';
require_once __DIR__ . '/../surface/Database.php';
require_once __DIR__ . '/../surface/ApiCredential.php';

spl_autoload_register(static function (string $class): void {
    $path = __DIR__ . '/../src/Platform/'
        . str_replace('\\', '/', substr($class, strlen('SmartPRS\\Platform\\'))) . '.php';

    if (str_starts_with($class, 'SmartPRS\\Platform\\') && is_file($path)) {
        require_once $path;
    }
});

use SmartPRS\Surface\ApiCredential;
use SmartPRS\Surface\Database;

/** Everything this run creates is named with it, so a leftover row is identifiable. */
const SWEEP_MARK = 'api-sweep-probe';

$root = dirname(__DIR__);

$refuse = static function (string $why, string ...$more): never {
    fwrite(STDERR, "Refused: {$why}\n\n" . implode("\n", $more) . "\n");

    exit(2);
};

$pdo = Database::connect($root . '/.env');

if ($pdo === null) {
    $refuse('no database.', Database::why(), '',
        'This sweep mints credentials in `api_keys` and reads employments back through the',
        'endpoint. Without a database there is nothing to authenticate against, and every probe',
        'below would be answered by the same 503 — six refusals that prove nothing at all.');
}

// ------------------------------------------------------------ the two tenants
// Two are REQUIRED. The cross-tenant probe is the assertion this file exists for
// and it cannot be made against one tenant; running without it would report five
// passes and silently skip the sixth.
$tenants = $pdo->query('SELECT id FROM tenants ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

if (count($tenants) < 2) {
    $refuse('there are fewer than two tenants, so "another tenant\'s rows" does not exist here.',
        'Structural tenancy is the assertion this sweep is for, and a single-tenant database',
        'cannot fail it. Seed a second tenant before trusting a pass from this file.');
}

[$tenantA, $tenantB] = [(string) $tenants[0], (string) $tenants[1]];

// -------------------------------------------------------- counted before, after
$tables = ['api_keys', 'employees', 'attendance_logs'];

$sweepRows = static function () use ($pdo): void {
    foreach (['DELETE FROM attendance_logs WHERE device_user_id = ?',
        'DELETE FROM api_keys WHERE name = ?',
        'DELETE FROM employees WHERE emp_code = ?'] as $statement) {
        $pdo->prepare($statement)->execute([SWEEP_MARK]);
    }
};

// Residue from an interrupted earlier run goes FIRST, before the baseline is
// taken — otherwise deleting it would show up as this run having lost rows.
$sweepRows();

$before = [];

foreach ($tables as $table) {
    $before[$table] = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
}

$cleanup = static function () use ($pdo, $tables, $before, $sweepRows): void {
    $sweepRows();

    $leaked = [];

    foreach ($tables as $table) {
        $now = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();

        if ($now !== $before[$table]) {
            $leaked[] = "{$table}: {$before[$table]} before, {$now} after";
        }
    }

    if ($leaked !== []) {
        fwrite(STDERR, "\nTHIS SWEEP LEAKED ROWS AND COULD NOT CLEAN UP AFTER ITSELF:\n    "
            . implode("\n    ", $leaked) . "\n    Delete them by hand: everything it creates is "
            . 'named ' . SWEEP_MARK . ".\n");
    }
};

// ALWAYS, including on a refusal or a fatal. A working credential left behind by
// a security check is its own finding.
register_shutdown_function($cleanup);

// ------------------------------------------------------- an employment each side
// One per tenant, with a uuid, because the single-employment endpoint addresses a
// row by its uuid and the ported `employees.uuid` is nullable and empty in this
// database. Seeded rather than borrowed: a probe that needs a hand-made row is a
// probe that stops being run.
$seedEmployment = static function (string $tenant, string $uuid, string $name)
    use ($pdo, $refuse): void {
    $done = $pdo->prepare('INSERT INTO employees (tenant_id, uuid, emp_code, name, status,'
        . ' created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())')
        ->execute([$tenant, $uuid, SWEEP_MARK, $name, 'active']);

    if (!$done) {
        $refuse("could not seed an employment in tenant {$tenant}.",
            'Without a row on each side there is nothing to ask one tenant for and nothing to',
            'refuse the other, so the cross-tenant probe would pass having read nothing.');
    }
};

$uuidA = '9a51e0d0-0000-4000-8000-00000000000a';
$uuidB = '9a51e0d0-0000-4000-8000-00000000000b';
$nameA = 'Sweep Alpha ' . SWEEP_MARK;
$nameB = 'Sweep Bravo ' . SWEEP_MARK;

$seedEmployment($tenantA, $uuidA, $nameA);
$seedEmployment($tenantB, $uuidB, $nameB);

// ---------------------------------------------------------------- the credentials
$mint = static function (string $tenant, array $scopes, ?string $expires, int $active)
    use ($pdo, $refuse): string {
    $minted = ApiCredential::mint(SWEEP_MARK, $scopes, $expires);
    $row = $minted['row'];

    $done = $pdo->prepare('INSERT INTO api_keys (tenant_id, name, prefix, key_hash, scopes,'
        . ' expires_at, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
        ->execute([$tenant, $row['name'], $row['prefix'], $row['key_hash'],
            json_encode($row['scopes'], JSON_THROW_ON_ERROR), $row['expires_at'], $active]);

    if (!$done) {
        $refuse('could not mint a credential.',
            'Every probe below would then be an unauthenticated request answered 401 — six',
            'refusals that say nothing about scopes, expiry, revocation or tenancy, and a sweep',
            'that authenticated nothing prints exactly like one that found nothing.');
    }

    // THE TOKEN IS RETURNED, NEVER STORED AND NEVER PRINTED. The row carries the
    // hash; this is the only moment the plaintext exists.
    return $minted['token'];
};

$read = ['employments.read'];
$full = ['employments.read', 'attendance.write'];
$yesterday = (new DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');

$minted = 0;
$count = static function (string $token) use (&$minted): string {
    $minted++;

    return $token;
};

$tokens = [
    'valid'    => $count($mint($tenantA, $full, null, 1)),
    'no scope' => $count($mint($tenantA, ['reporting.read'], null, 1)),
    'expired'  => $count($mint($tenantA, $full, $yesterday, 1)),
    'inactive' => $count($mint($tenantA, $full, null, 0)),
    'other tenant' => $count($mint($tenantB, $full, null, 1)),
    // Holding SOME scope is not holding the right one, and deny-by-default is
    // only real if that is true.
    'read only' => $count($mint($tenantA, $read, null, 1)),
    // A token of exactly the right SHAPE whose prefix nothing carries. The
    // malformed one below is a different failure and both are asserted.
    'never issued' => 'sprs3_' . bin2hex(random_bytes(6)) . '_' . bin2hex(random_bytes(24)),
    'malformed' => 'not-a-token',
];

// ------------------------------------------------------------------- the server
$port = (int) (getenv('SMARTPRS_API_SWEEP_PORT') ?: 8399);
$host = "127.0.0.1:{$port}";
$log = (string) tempnam(sys_get_temp_dir(), 'prs3-api-sweep');

$server = proc_open(
    sprintf('exec php -S %s -t %s/public', $host, $root),
    [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $pipes,
);

if (!is_resource($server)) {
    $refuse('could not start the built-in server, so nothing could be requested.');
}

register_shutdown_function(static function () use ($server, $log): void {
    proc_terminate($server, SIGKILL);
    @unlink($log);
});

$reachable = false;

for ($i = 0; $i < 40; $i++) {
    usleep(150_000);

    if (@file_get_contents("http://{$host}/api.php/api/v3/employments") !== false
        || str_contains((string) @file_get_contents($log), 'Development Server')) {
        $reachable = true;

        break;
    }
}

if (!$reachable) {
    $refuse('the endpoint could not be reached.',
        'Every probe would have failed to connect, which reads as "refused" and is not: an',
        'endpoint nobody could talk to leaks nothing and proves nothing.');
}

/**
 * One request. Returns the status and the body, and nothing about the token.
 *
 * @return array{status: int, body: string}
 */
$call = static function (string $method, string $path, ?string $token, string $body = '',
    array $headers = []) use ($host, $tenantA): array {
    // EVERY REQUEST ASKS FOR TENANT A, out loud, in the headers a hopeful caller
    // would try if they thought tenancy were negotiable. The cross-tenant probes
    // below are made with tenant B's credential, so if any of this ever reached
    // the store the sweep would see A's employment in B's response. Sent on all
    // of them rather than on one, because "the request cannot choose the tenant"
    // is a property of the endpoint and not of one route.
    $headers = array_merge($headers, ['X-Tenant: ' . $tenantA, 'X-Tenant-Id: ' . $tenantA]);

    $command = ['curl', '-sS', '-o', '-', '-w', '\n%{http_code}', '-X', $method];

    if ($token !== null) {
        $command[] = '-H';
        $command[] = 'Authorization: Bearer ' . $token;
    }

    foreach ($headers as $header) {
        $command[] = '-H';
        $command[] = $header;
    }

    if ($body !== '') {
        $command[] = '-H';
        $command[] = 'Content-Type: application/json';
        $command[] = '-d';
        $command[] = $body;
    }

    $command[] = "http://{$host}/api.php{$path}";

    $out = (string) shell_exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1');
    $split = strrpos($out, "\n");

    return $split === false
        ? ['status' => 0, 'body' => $out]
        : ['status' => (int) substr($out, $split + 1), 'body' => substr($out, 0, $split)];
};

// ------------------------------------------------------- the positive control
// FIRST, and the run stops here if it fails. Everything after it is an assertion
// that something did NOT come back, and every one of those is satisfied by an
// endpoint that is simply broken.
$control = $call('GET', '/api/v3/employments/' . $uuidA, $tokens['valid']);

if ($control['status'] !== 200 || !str_contains($control['body'], $nameA)) {
    $refuse('the positive control did not come back.',
        'A valid credential, holding the scope, in the right tenant, asked for its own tenant\'s',
        'employment and got HTTP ' . $control['status'] . ' without the row in it. Every refusal',
        'this file asserts below would then be an endpoint refusing everybody, which looks',
        'exactly like an endpoint that is correctly closed. Server log:',
        substr((string) @file_get_contents($log), -800));
}

$punch = json_encode(['punches' => [['device_user_id' => SWEEP_MARK, 'direction' => 'in',
    'punch_at' => (new DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s')]]],
    JSON_THROW_ON_ERROR);

// THE SECOND POSITIVE CONTROL, for the write. Without it, "the wrong scope is
// refused" is satisfied by an ingest endpoint that refuses everybody — and the
// one write on this API is the reason the API exists at all. The punch is
// deliberately unmappable (no employment carries this enrolment number), so the
// control proves the endpoint RAN without adding attendance to anybody's month.
$wrote = $call('POST', '/api/v3/attendance-punches', $tokens['valid'], $punch,
    ['Idempotency-Key: ' . bin2hex(random_bytes(8))]);

if ($wrote['status'] !== 201 || !str_contains($wrote['body'], '"unmapped"')) {
    $refuse('the write positive control did not come back.',
        'A valid credential holding attendance.write pushed one punch and got HTTP '
            . $wrote['status'] . '. Every scope refusal below would then be an ingest endpoint',
        'refusing everybody, which is indistinguishable from one that is correctly gated.',
        substr($wrote['body'], 0, 400));
}

// name, method, path, token, body, expected status, and what must NOT be in the body.
//
// DECLARED, because the analyser could not work it out and said something
// alarming instead. Thirteen tuples of seven differently-shaped literals is a
// union PHPStan gives up on: it typed `$expected` as *NEVER* and then concluded
// that `$problems !== []` below is ALWAYS TRUE — which reads as "every probe in
// the API security sweep always reports a finding", the opposite of the truth
// (the sweep reports 0 findings and has done since it was written). A security
// check the analyser describes wrongly is one somebody eventually stops
// believing, so the shape is written down rather than guessed at.
//
/** @var list<array{string, string, string, string|null, string, int, list<string>}> $probes */
$probes = [
    ['no credential at all', 'GET', '/api/v3/employments', null, '', 401, [$nameA, $nameB]],
    ['a malformed token', 'GET', '/api/v3/employments', $tokens['malformed'], '', 401, [$nameA]],
    ['a token never issued', 'GET', '/api/v3/employments', $tokens['never issued'], '', 401, [$nameA]],
    ['an EXPIRED credential', 'GET', '/api/v3/employments', $tokens['expired'], '', 401, [$nameA]],
    ['an INACTIVE credential', 'GET', '/api/v3/employments', $tokens['inactive'], '', 401, [$nameA]],
    ['a valid token WITHOUT the scope (read)', 'GET', '/api/v3/employments', $tokens['no scope'],
        '', 403, [$nameA]],
    ['a valid token WITHOUT the scope (write)', 'POST', '/api/v3/attendance-punches',
        $tokens['no scope'], $punch, 403, []],
    ['a read-only token attempting the write', 'POST', '/api/v3/attendance-punches',
        $tokens['read only'], $punch, 403, []],
    ['another tenant asking for this tenant\'s employment', 'GET', '/api/v3/employments/' . $uuidA,
        $tokens['other tenant'], '', 404, [$nameA]],
    ['another tenant listing employments', 'GET', '/api/v3/employments?limit=500',
        $tokens['other tenant'], '', 200, [$nameA]],
    ['this tenant asking for the other tenant\'s employment', 'GET', '/api/v3/employments/' . $uuidB,
        $tokens['valid'], '', 404, [$nameB]],
    ['an unknown endpoint', 'GET', '/api/v3/nothing-here', $tokens['valid'], '', 404, [$nameA]],
    ['the write with no idempotency key', 'POST', '/api/v3/attendance-punches', $tokens['valid'],
        $punch, 400, []],
];

echo "positive controls present: a valid credential read its own tenant's employment, and a\n"
    . "credential holding attendance.write pushed a punch (unmapped, so nothing was written).\n";
printf("%d probe(s) against http://%s/api.php/api/v3\n\n", count($probes), $host);

$findings = [];

foreach ($probes as [$label, $method, $path, $token, $body, $expected, $forbidden]) {
    $headers = $method === 'POST' && $expected !== 400 ? ['Idempotency-Key: ' . bin2hex(random_bytes(8))] : [];
    $response = $call($method, $path, $token, $body, $headers);
    $problems = [];

    if ($response['status'] !== $expected) {
        $problems[] = "answered {$response['status']}, expected {$expected}";
    }

    foreach ($forbidden as $secret) {
        if ($secret !== '' && str_contains($response['body'], $secret)) {
            $problems[] = "the response carried \"{$secret}\"";
        }
    }

    // NO TOKEN, HASH OR SECRET EVER COMES BACK. Asserted rather than assumed:
    // an error that echoes what it was given is how a token reaches a log
    // aggregator, a screenshot and a support ticket.
    foreach ($tokens as $name => $value) {
        if (str_contains($value, '_') && str_contains($response['body'], $value)) {
            $problems[] = "the response echoed the '{$name}' token back";
        }
    }

    if ($problems !== []) {
        $findings[$label] = $problems;
    }

    printf("  %-46s %s\n", $label, $problems === [] ? 'refused as it should'
        : 'FINDING — ' . implode('; ', $problems));
}

// The hash must not be readable through the endpoint either — the sweep's own
// credentials are in `api_keys`, and a modules-style generic read over that table
// would serve them.
$hashes = $pdo->prepare('SELECT key_hash FROM api_keys WHERE name = ?');
$hashes->execute([SWEEP_MARK]);
$leakedHash = [];

foreach ($hashes->fetchAll(PDO::FETCH_COLUMN) as $hash) {
    $page = $call('GET', '/api/v3/employments?limit=500', $tokens['valid']);

    if (str_contains($page['body'], (string) $hash)) {
        $leakedHash[] = 'a stored key hash came back through the employments endpoint';
    }
}

if ($leakedHash !== []) {
    $findings['a stored hash reached a response'] = $leakedHash;
}

echo "\n";

foreach ($findings as $label => $problems) {
    echo "{$label}\n";

    foreach ($problems as $problem) {
        echo "    {$problem}\n";
    }
}

printf("%s  %d probe(s), 2 positive controls, %d finding(s). %d credential(s) and 2 employment(s) "
    . "created and removed.\n",
    $findings === [] ? 'PASS' : 'FAIL', count($probes), count($findings), $minted);

exit($findings === [] ? 0 : 1);
