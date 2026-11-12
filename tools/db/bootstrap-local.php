<?php

declare(strict_types=1);

/**
 * FIRST RUN: MAKE THE TWO ACCOUNTS THIS PRODUCT NEEDS AND WRITE THEM INTO .env.
 *
 * SmartPRS 3 ships with `DB_USERNAME=` blank, and that is not an oversight — Law 2
 * says the application's own account must never hold ALTER, CREATE or DROP, so
 * there are two accounts, not one:
 *
 *   smartprs3           the application. SELECT/INSERT/UPDATE/DELETE, nothing else.
 *   smartprs3_migrator  the migrations. DDL on this one database, nothing else.
 *
 * Neither exists on a fresh machine, and every tool that could create them needed
 * one of them to already exist: `migrate.php` wanted the migrator, and
 * `grant-application-account.php` connects AS the migrator to grant to the app.
 * So the first run had no way in, and the honest refusal the setup script printed
 * ("DB_USERNAME is empty") sent the person off to invent two accounts and two
 * passwords by hand, which is how a local password ends up being `root` on a
 * machine that later gets demoed from.
 *
 * THIS IS THE ONLY THING IN THE PRODUCT THAT WRITES CREDENTIALS INTO .env, and it
 * refuses to run unless APP_ENV=local. A tool that edits a server's .env is a tool
 * that eventually edits a production one at 11pm.
 *
 * THE PASSWORDS ARE GENERATED, NOT CHOSEN, and never printed. They go from
 * random_bytes into MySQL and into .env, and nowhere else — not to the console, not
 * to the audit trail, not into the shell history. Nobody needs to know them: the
 * application reads them from .env, and a person who needs database access should
 * be using their own account.
 *
 * Usage:
 *   php tools/db/bootstrap-local.php                        (root, no password)
 *   php tools/db/bootstrap-local.php --root-password=secret
 *   php tools/db/bootstrap-local.php --root-user=admin --root-password=secret
 */

require_once __DIR__ . '/../../surface/RowStore.php';
require_once __DIR__ . '/../../surface/Store.php';
require_once __DIR__ . '/../../surface/SqlStore.php';
require_once __DIR__ . '/../../surface/Database.php';

use SmartPRS\Surface\Database;

$root = dirname(__DIR__, 2);
$envPath = $root . '/.env';

$options = ['root-user' => 'root', 'root-password' => '', 'if-broken' => false];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--if-broken') {
        $options['if-broken'] = true;
    } elseif (preg_match('/^--([a-z-]+)=(.*)$/', $argument, $m)
        && array_key_exists($m[1], $options)) {
        $options[$m[1]] = $m[2];
    }
}

$refuse = static function (string $why, string ...$more): never {
    fwrite(STDERR, "Refused: {$why}\n\n" . implode("\n", $more) . "\n");

    exit(2);
};

if (!is_file($envPath)) {
    $refuse('there is no .env to write into.', 'Copy .env.example to .env first.');
}

// THROUGH Database::env(), WHICH IS THE READER THIS PRODUCT ALREADY HAS.
//
// This said `parse_ini_file()` and that was wrong in a way that took the whole
// setup down: PHP's INI parser treats `;` as the comment character, not `#`, so a
// .env with `# Instants are stored UTC (spine S16).` in it is a SYNTAX ERROR — the
// parser warns, returns false, and every single value comes back missing. The tool
// then reported "APP_ENV is not local" about a file whose first line says
// APP_ENV=local, and refused. A second implementation of something the codebase
// already had, wrong in a way that blamed the user's file.
$env = Database::env($envPath);

// THE REFUSAL THAT KEEPS THIS TOOL HONEST. Everything below writes secrets into a
// file and creates database accounts; on anything but a developer's own machine
// that is somebody else's decision, made with their own change process.
if (($env['APP_ENV'] ?? '') !== 'local') {
    $refuse(sprintf('APP_ENV is "%s", not "local".', (string) ($env['APP_ENV'] ?? '')),
        'This writes generated passwords into .env and creates MySQL accounts. That is a reasonable',
        'thing to do on a laptop and never a reasonable thing to do anywhere else — a tool that can',
        'edit a server\'s .env is one that eventually edits a production one at eleven at night.',
        'On a real environment, create the two accounts through whatever makes your other accounts.');
}

$database = (string) ($env['DB_DATABASE'] ?? 'smartprs3');
$app = 'smartprs3';
$migrator = (string) ($env['DB_MIGRATION_USERNAME'] ?? 'smartprs3_migrator');

if ($app === $migrator) {
    $refuse('the application account and the migration account are the same name.',
        'They are two accounts on purpose (Law 2): the application must not hold ALTER, CREATE or',
        'DROP. One account holding both sets of rights is the arrangement this separation exists',
        'to prevent, and naming them the same is that arrangement with extra steps.');
}

// ------------------------------------------------- is anything actually wrong?
//
// `--if-broken` exists because the setup script used to decide this by reading
// .env: if DB_USERNAME had a value, it skipped this step. That is the same mistake
// as the one two steps down the file — A NAME IN A CONFIGURATION FILE IS NOT A
// WORKING ACCOUNT. On a machine where the accounts had been created earlier with
// an authentication plugin the server no longer loads, .env looked complete, setup
// skipped straight past the step that would have fixed it, and migrate failed with
// SQLSTATE 1524 three lines later.
//
// So the question is asked of the SERVER: can each account actually sign in? Both
// working is the only answer that skips.
if ($options['if-broken']) {
    $works = static function (string $user, string $password) use ($env): bool {
        if ($user === '') {
            return false;
        }

        try {
            new PDO(
                sprintf('mysql:host=%s;port=%s;charset=utf8mb4',
                    $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306'),
                $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    };

    if ($works((string) ($env['DB_USERNAME'] ?? ''), (string) ($env['DB_PASSWORD'] ?? ''))
        && $works((string) ($env['DB_MIGRATION_USERNAME'] ?? ''),
            (string) ($env['DB_MIGRATION_PASSWORD'] ?? ''))) {
        echo "  Both database accounts exist and can sign in. Nothing to do.\n";

        exit(0);
    }

    echo "  At least one database account cannot sign in. Recreating both.\n";
}

// ------------------------------------------------------------------- the server
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;charset=utf8mb4',
            $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306'),
        $options['root-user'], $options['root-password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
} catch (Throwable $e) {
    $refuse(sprintf('could not connect as %s: %s', $options['root-user'], $e->getMessage()),
        'This step needs an account that may CREATE DATABASE and CREATE USER — on Laragon that is',
        'root. If root has a password, pass it:',
        '    php tools/db/bootstrap-local.php --root-password=yourpassword');
}

require_once __DIR__ . '/auth-plugin.php';

// 32 bytes of CSPRNG, base64, punctuation stripped so nothing has to be escaped on
// its way through a .env line, a SQL string and a Windows batch file. Length
// carries the strength; the alphabet only has to survive the journey.
$secret = static fn (): string
    => substr(strtr(base64_encode(random_bytes(32)), '+/=', 'xyz'), 0, 40);

$appPassword = $secret();
$migratorPassword = $secret();

$pdo->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s` DEFAULT CHARACTER SET utf8mb4 '
    . 'COLLATE utf8mb4_unicode_ci', $database));

try {
    $plugin = smartprs_identified_clause($pdo, $appPassword)['plugin'];
} catch (Throwable $e) {
    $refuse($e->getMessage());
}

foreach ([[$app, $appPassword], [$migrator, $migratorPassword]] as [$who, $secretFor]) {
    // The clause is built per password, so nothing here ever interpolates a secret
    // into SQL by hand — PDO::quote does it, once, in one place.
    $clause = smartprs_identified_clause($pdo, $secretFor)['clause'];

    foreach (['localhost', '127.0.0.1'] as $host) {
        $pdo->exec(sprintf("DROP USER IF EXISTS '%s'@'%s'", $who, $host));
        $pdo->exec(sprintf("CREATE USER '%s'@'%s' %s", $who, $host, $clause));
    }
}

// THE MIGRATOR GETS DDL ON ONE DATABASE. Not `*.*`, which is the shortcut every
// setup script takes and the reason a compromised application account on a shared
// server reaches the other databases on it.
foreach (['localhost', '127.0.0.1'] as $host) {
    // WITH GRANT OPTION, scoped to this one database. The migrator has to be able
    // to hand the application its table-by-table rights after the schema exists,
    // and the alternative is giving it the global CREATE USER privilege so it can
    // recreate the account each time — which is a far wider power than "may grant
    // on the database it owns", and it is global rather than scoped.
    $pdo->exec(sprintf('GRANT ALL PRIVILEGES ON `%s`.* TO \'%s\'@\'%s\' WITH GRANT OPTION',
        $database, $migrator, $host));
}

// The application account gets NOTHING yet, deliberately. Its grants are made
// table by table by grant-application-account.php once the schema exists, and a
// table that does not exist cannot be granted on — so a blanket grant here would
// be a permanent widening to save one ordering constraint.
$pdo->exec('FLUSH PRIVILEGES');

// ------------------------------------------------------------------- write .env
// Line-by-line so every comment, every unrelated setting and the file's own order
// survive. A rewritten .env is a diff nobody can read on a day something is wrong.
$lines = file($envPath, FILE_IGNORE_NEW_LINES);
$set = [
    'DB_USERNAME' => $app,
    'DB_PASSWORD' => $appPassword,
    'DB_MIGRATION_USERNAME' => $migrator,
    'DB_MIGRATION_PASSWORD' => $migratorPassword,
];
$seen = [];

foreach ($lines as $i => $line) {
    foreach ($set as $key => $value) {
        if (preg_match('/^' . preg_quote($key, '/') . '\s*=/', $line)) {
            $lines[$i] = $key . '=' . $value;
            $seen[$key] = true;
        }
    }
}

foreach ($set as $key => $value) {
    if (!isset($seen[$key])) {
        $lines[] = $key . '=' . $value;
    }
}

// The old file is kept. It holds nothing but blanks on a first run — and on a
// second run it holds the credentials that were working ten seconds ago, which is
// exactly what somebody wants back if this went wrong.
copy($envPath, $envPath . '.bak');
file_put_contents($envPath, implode("\n", $lines) . "\n");

printf("Database `%s` ready.\n", $database);
printf("Created `%s` (application) and `%s` (migrations), authenticating with %s.\n",
    $app, $migrator, $plugin);
printf("Generated passwords written to .env. Previous .env kept as .env.bak.\n\n");

echo "The passwords are not printed anywhere, including here. Nobody needs to know them: the\n"
    . "application reads them from .env, and a person who needs database access should be using\n"
    . "their own account rather than the product's.\n";
