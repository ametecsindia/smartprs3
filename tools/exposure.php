<?php

declare(strict_types=1);

/**
 * SIGN IN AS AN ORDINARY EMPLOYEE AND TRY TO READ A COLLEAGUE'S SECRETS.
 *
 * A person did this once, by hand, with curl and a text editor, and found
 * SEVENTEEN screens that would hand a `viewer` somebody else's salary, bank
 * details, ledger or password hash. That audit cannot be run again — it lived in
 * one person's afternoon — and nothing at all stops the eighteenth from being
 * added tomorrow. So it is written down here as something a machine does.
 *
 * THE METHOD IS THE POINT: it does not grep for things that LOOK like a salary.
 * A pattern search invents its own answer — `\d{9,18}` matches a timestamp and
 * misses a formatted one. Instead it reads the ACTUAL VALUES out of MySQL first
 * — this employment's ctc, that one's account number, the argon2 hash on a user
 * row — and then asserts those exact strings are absent from every byte the
 * viewer was served. There is no judgement in the comparison and nothing to
 * tune: either the number that is in the database came back down the socket, or
 * it did not.
 *
 * IT REFUSES TO RUN (exit 2) rather than report a clean sweep over nothing:
 *
 *   - if the temporary viewer could not be created,
 *   - if it could not sign in as them — every route would render the sign-in
 *     page, contain no secrets, and pass,
 *   - if there is no employment data whose values it could search for — an
 *     empty needle list matches nothing and proves nothing,
 *   - if the POSITIVE CONTROL is missing from a page. Something the viewer IS
 *     allowed to see is asserted PRESENT on every response. If it is not there,
 *     the sweep is looking at error pages, not screens, and "no salary on this
 *     page" means "there is no page".
 *
 * That is this codebase's standing rule in one file: "found nothing" and
 * "looked at nothing" must never print the same.
 *
 * Usage: php tools/exposure.php
 */

require_once __DIR__ . '/../surface/RowStore.php';
require_once __DIR__ . '/../surface/Store.php';
require_once __DIR__ . '/../surface/SqlStore.php';
require_once __DIR__ . '/../surface/Database.php';
require_once __DIR__ . '/../surface/Session.php';

spl_autoload_register(static function (string $class): void {
    $path = __DIR__ . '/../src/Platform/'
        . str_replace('\\', '/', substr($class, strlen('SmartPRS\\Platform\\'))) . '.php';

    if (str_starts_with($class, 'SmartPRS\\Platform\\') && is_file($path)) {
        require_once $path;
    }
});

use SmartPRS\Platform\Identity\PasswordHasher;
use SmartPRS\Surface\Database;
use SmartPRS\Surface\Session;

const PROBE_EMAIL = 'exposure-probe@smartprslocal';
const PROBE_PASSWORD = 'exposure-probe-pw';

$root = dirname(__DIR__);

$refuse = static function (string $why, string ...$more): never {
    fwrite(STDERR, "Refused: {$why}\n\n" . implode("\n", $more) . "\n");

    exit(2);
};

$pdo = Database::connect($root . '/.env');

if ($pdo === null) {
    $refuse('no database.', Database::why(), '',
        'The values this check hunts for are read OUT of MySQL. Without it there is nothing to',
        'search for, and a run would sweep every route against an empty needle list and pass.');
}

// ---------------------------------------------------------------- the tenant
$tenant = (int) $pdo->query('SELECT tenant_id FROM users WHERE email = '
    . $pdo->quote(Session::LOCAL_DEFAULT_EMAIL) . ' LIMIT 1')->fetchColumn();

if ($tenant === 0) {
    $refuse('no seeded local administrator, so there is no demo tenant to probe.',
        'Seed one: php tools/seed-local-admin.php');
}

// ------------------------------------------------- what must not come back
// Read the real values. Anything NULL or blank is NOT searched for and is
// reported as an unasserted class at the end — grepping a page for "" either
// matches everything or nothing depending on the implementation, and proves
// neither. A class with no data in this tenant is a class this run did not
// check, and it says so rather than counting as clean.
$columns = [
    'ctc' => 'salary (ctc)',
    'bank_acc' => 'bank account number',
    'ifsc' => 'IFSC code',
    'pan' => 'PAN',
    'uan' => 'UAN',
    'aadhaar' => 'Aadhaar',
];

$employments = $pdo->query('SELECT id, emp_code, name, ' . implode(', ', array_keys($columns))
    . ' FROM employees WHERE tenant_id = ' . $tenant
    . ' AND deleted_at IS NULL ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

if ($employments === []) {
    $refuse('there is no employment in tenant ' . $tenant . ', so there are no colleague values',
        'to hunt for and every route would sweep clean having searched for nothing.',
        'Seed some: php tools/seed-demo-data.php');
}

/** Indian digit grouping, as Platform\Support\Money::format() renders it: 42,00,000. */
$grouped = static function (string $major): string {
    if (strlen($major) <= 3) {
        return $major;
    }

    return strrev(implode(',', str_split(strrev(substr($major, 0, -3)), 2))) . ',' . substr($major, -3);
};

/** @var array<string, array{class: string, who: string}> $needles */
$needles = [];
$found = array_fill_keys(array_values($columns), 0);

foreach ($employments as $row) {
    $who = (string) $row['name'] . ' (' . (string) $row['emp_code'] . ')';

    foreach ($columns as $column => $class) {
        $value = trim((string) ($row[$column] ?? ''));

        if ($value === '') {
            continue;
        }

        // A salary reaches the page formatted, so the raw DECIMAL(18,4) is only
        // one of the shapes it can arrive in. All of them are the same secret.
        $shapes = [$value];

        if ($column === 'ctc') {
            $major = (string) (int) (float) $value;

            if ((int) $major <= 0) {
                continue;
            }

            $shapes = [$value, $major, $grouped($major), number_format((float) $major)];
        }

        foreach (array_unique($shapes) as $shape) {
            // Four characters is the floor at which a match is evidence rather
            // than a coincidence: "0", "12" and "200" occur on every screen.
            if (strlen($shape) < 4) {
                continue;
            }

            $needles[$shape] = ['class' => $class, 'who' => $who];
        }

        $found[$class]++;
    }
}

// Password hashes, from the user rows themselves. Truncated to a prefix so a
// page that prints only part of one still gets caught.
$hashes = $pdo->query('SELECT email, password FROM users WHERE tenant_id = ' . $tenant)
    ->fetchAll(PDO::FETCH_ASSOC);
$found['password hash'] = 0;

foreach ($hashes as $user) {
    $hash = trim((string) $user['password']);

    if (strlen($hash) < 24) {
        continue;
    }

    $needles[substr($hash, 0, 24)] = ['class' => 'password hash', 'who' => (string) $user['email']];
    $found['password hash']++;
}

$unasserted = array_keys(array_filter($found, static fn (int $n): bool => $n === 0));

// The non-empty assertion, made out loud rather than assumed. If this ever
// fires the sweep below would be searching every page for the empty string.
foreach ($needles as $needle => $meta) {
    if (trim((string) $needle) === '') {
        $refuse('one of the values to search for is empty.',
            'Searching a page for "" is not a search. ' . $meta['class'] . ' for ' . $meta['who']);
    }
}

if ($needles === []) {
    $refuse('not one salary, bank detail, identifier or password hash has a value in tenant '
        . $tenant . '.',
        'Every route would be searched for nothing at all and reported clean.',
        'Seed some: php tools/seed-demo-data.php');
}

printf("%d value(s) from %d employment(s) in tenant %d must not appear.\n",
    count($needles), count($employments), $tenant);

// -------------------------------------------------------- the viewer account
// Created here rather than assumed: a probe that needs a hand-made account is a
// probe that stops being run. The role rides on `status` — see Session::enrol().
$pdo->prepare('DELETE FROM users WHERE email = ?')->execute([PROBE_EMAIL]);

register_shutdown_function(static function () use ($pdo): void {
    // ALWAYS, including on a refusal or a fatal. A probe account with a known
    // password left behind by a security check is its own finding.
    try {
        $pdo->prepare('DELETE FROM users WHERE email = ?')->execute([PROBE_EMAIL]);
    } catch (\Throwable $e) {
        fwrite(STDERR, "\nCOULD NOT DELETE THE PROBE ACCOUNT " . PROBE_EMAIL . ': '
            . $e->getMessage() . "\nDelete it by hand.\n");
    }
});

$created = $pdo->prepare('INSERT INTO users (tenant_id, email, password, status, created_at,'
    . ' updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())')->execute([
        $tenant, PROBE_EMAIL, PasswordHasher::platformDefault()->hash(PROBE_PASSWORD), 'viewer',
    ]);

if (!$created) {
    $refuse('could not create the temporary viewer.',
        'Without an ordinary-employee account there is nobody to run this audit AS, and a sweep',
        'signed in as the administrator would find nothing because the administrator may see it all.');
}

// ---------------------------------------------------------------- the server
$port = (int) (getenv('SMARTPRS_EXPOSURE_PORT') ?: 8398);
$host = "127.0.0.1:{$port}";
$log = (string) tempnam(sys_get_temp_dir(), 'prs3-exposure');
$jar = (string) tempnam(sys_get_temp_dir(), 'prs3-exposure-jar');

$server = proc_open(
    sprintf('exec php -S %s -t %s/public', $host, $root),
    [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $pipes,
);

if (!is_resource($server)) {
    $refuse('could not start the built-in server, so nothing could be requested.');
}

register_shutdown_function(static function () use ($server, $log, $jar): void {
    proc_terminate($server, SIGKILL);
    @unlink($log);
    @unlink($jar);
});

for ($i = 0; $i < 40; $i++) {
    usleep(150_000);

    if (@file_get_contents("http://{$host}/app.php?r=signin") !== false) {
        break;
    }
}

$fetch = static function (string $query, string $post = '') use ($host, $jar): string {
    return (string) shell_exec(sprintf(
        'curl -sS -b %s -c %s %s "http://%s/app.php%s" 2>&1',
        escapeshellarg($jar), escapeshellarg($jar),
        $post === '' ? '' : '-X POST -d ' . escapeshellarg($post),
        $host, $post === '' ? $query : '',
    ));
};

$fetch('?r=signin');
$fetch('', 'action=signin&email=' . PROBE_EMAIL . '&password=' . PROBE_PASSWORD);

// THE POSITIVE CONTROL. The sidebar prints the local part of the signed-in
// address on every real screen, and nowhere else. Its presence is the proof that
// what came back is a page this viewer was actually served — without it, "no
// salary here" only means "this is the sign-in form".
$control = explode('@', PROBE_EMAIL)[0];

if (!str_contains($fetch('?r=employees'), $control)) {
    $refuse('could not sign in as the temporary viewer.',
        'Every route below would have been served the sign-in page: no salary on it, no bank',
        'detail on it, and a clean sweep that examined no screen at all. That is the exact',
        'shape of vacuous pass this file exists to make impossible.');
}

echo "signed in as {$control} (role: viewer), positive control present.\n\n";

// ------------------------------------------------------------------ the sweep
// Routes read out of the front controller, like tools/smoke.php does. A
// hand-kept list is a list that misses the route somebody added on Tuesday —
// which is precisely the eighteenth finding this file is here to catch.
preg_match_all("/\\\$route === '([a-z-]+)'/",
    (string) file_get_contents($root . '/public/app.php'), $matched);
// A PUBLIC PAGE IS NOT A SCREEN. `onboard` is the self-onboarding link a joiner
// opens without an account: it renders its own page and exits before the layout,
// so it never carries the positive control and never can. It is excluded by name
// rather than by "did not carry the control", because the second rule is exactly
// the silence this sweep refuses to count as clean.
$screenFiles = array_map(static fn (string $f): string => basename($f, '.php'), glob($root . '/surface/screens/*.php') ?: []);
$routes = array_values(array_diff(array_unique(array_merge($matched[1], $screenFiles)), ['onboard', 'set-password']));
sort($routes);

// A colleague to ask about. Every subject-bearing spelling the routes actually
// read is supplied at once: `id` (leave, attendance, payroll, me, settlement),
// `e` (letters), `code` + `period` (payslip, register). A route ignores the
// parameters it does not know, so one query covers them all.
$subject = $employments[0];
$period = (string) ($pdo->query('SELECT cycle_label FROM payroll_runs WHERE tenant_id = '
    . $tenant . ' ORDER BY id DESC LIMIT 1')->fetchColumn() ?: date('Y-m'));

$carrying = 'id=' . rawurlencode((string) $subject['id'])
    . '&e=' . rawurlencode((string) $subject['id'])
    . '&code=' . rawurlencode((string) $subject['emp_code'])
    . '&period=' . rawurlencode($period)
    . '&t=offer';

$visits = [];

foreach ($routes as $route) {
    $visits[$route] = '?r=' . rawurlencode($route);
    $visits[$route . ' (about a colleague)'] = '?r=' . rawurlencode($route) . '&' . $carrying;
}

// `k` names a module or a report, not an employment, so those two get their own
// visits: the module screen over the employees table is where the salary column
// is padlocked, and a report is the other way a total leaves the building.
$visits['m (employees module)'] = '?r=m&k=employees&' . $carrying;

foreach ((array) json_decode((string) file_get_contents($root . '/config/reports.json'),
    true)['reports'] as $report) {
    $key = (string) $report['key'];
    $visits["reports (k={$key})"] = '?r=reports&k=' . rawurlencode($key);
}

$leaks = [];
$blind = [];

foreach ($visits as $label => $query) {
    $body = $fetch($query);

    if (!str_contains($body, $control)) {
        // Not a leak — worse. This response is not a screen, so its silence
        // about salary means nothing at all.
        $blind[] = $label;
        printf("  %-38s NOT A SCREEN\n", $label);

        continue;
    }

    $hits = [];

    foreach ($needles as $needle => $meta) {
        // Digit-bounded so 720000 does not match inside 7200000, and so a
        // coincidence has to be the whole value rather than a piece of it.
        if (preg_match('/(?<![\d])' . preg_quote((string) $needle, '/') . '(?![\d])/',
                $body, $_, PREG_OFFSET_CAPTURE) !== 1) {
            continue;
        }

        $at = (int) strpos($body, (string) $needle);
        // The window is cut at a byte offset, so it usually opens in the middle
        // of a tag — drop that fragment before stripping the rest.
        $snippet = preg_replace('/\s+/', ' ', strip_tags(preg_replace('/^[^<>]*>/', '',
            substr($body, max(0, $at - 70), 170))));

        $hits[$meta['class']] ??= ['who' => $meta['who'], 'value' => (string) $needle,
            'context' => trim((string) $snippet)];
    }

    if ($hits !== []) {
        $leaks[$label] = $hits;
    }

    printf("  %-38s %s\n", $label, $hits === [] ? 'clean' : 'EXPOSED — '
        . implode(', ', array_keys($hits)));
}

echo "\n";

foreach ($leaks as $label => $hits) {
    echo "{$label}\n";

    foreach ($hits as $class => $hit) {
        echo "    {$class} of {$hit['who']} — \"{$hit['value']}\"\n";
        echo '    ... ' . substr($hit['context'], 0, 150) . "\n";
    }
}

if ($unasserted !== []) {
    echo "\nNOT ASSERTED (no such value in tenant {$tenant}, so this run did not check it):\n    "
        . implode(', ', $unasserted) . "\n"
        . "    These classes leaked in the manual audit. This run says nothing about them.\n";
}

if ($blind !== []) {
    $refuse(count($blind) . ' response(s) did not carry the positive control.',
        'These were not screens — an error page, a redirect or the sign-in form: '
            . implode(', ', array_slice($blind, 0, 8)),
        '',
        'Their silence about salary is not evidence of anything, and counting them as clean',
        'would be the whole sweep quietly examining nothing.');
}

printf("\n%s  %d visit(s), %d value(s) hunted, %d screen(s) exposing.\n",
    $leaks === [] ? 'PASS' : 'FAIL', count($visits), count($needles), count($leaks));

exit($leaks === [] ? 0 : 1);
