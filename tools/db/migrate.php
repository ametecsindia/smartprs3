<?php
/**
 * CREATE THE DATABASE AND PUT SOMETHING IN IT — without artisan.
 *
 *   php tools/db/migrate.php [--fresh]
 *
 * `php artisan migrate` needs the Laravel skeleton and a vendor/ directory,
 * neither of which is in this repository yet. The migrations themselves are
 * real and final; what is missing is only the runner. This is that runner, and
 * it is a DEV TOOL, not the application — on a machine with Composer installed,
 * `php artisan migrate` runs these same files against the real schema builder
 * and neither this file nor the stub it loads is involved.
 *
 * It then publishes three versions of one rule THROUGH THE REAL PUBLISHER, so
 * what lands in config_versions, rule_payloads, config_publication_checks and
 * the audit ledger is what the platform writes, not fixture rows typed to look
 * like it.
 *
 * Connection: SMARTPRS_DSN / SMARTPRS_USER / SMARTPRS_PASSWORD / SMARTPRS_DB.
 * The defaults are Laragon's out-of-the-box MySQL (root, no password).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../tests/lib/bootstrap.php';
require_once __DIR__ . '/../../tests/lib/laravel_schema_stub.php';

use Illuminate\Support\Facades\Schema;
use SmartPRS\Platform\Audit\AuditLedger;
use SmartPRS\Platform\Config\ConfigVersionRepository;
use SmartPRS\Platform\Config\Publisher;
use SmartPRS\Platform\Persistence\StatusLookup;
use SmartPRS\Platform\Rules\Actions\Action;
use SmartPRS\Platform\Rules\ConflictPolicy;
use SmartPRS\Platform\Rules\DecisionPoints;
use SmartPRS\Platform\Rules\Rule;
use SmartPRS\Platform\Rules\RulePublisher;
use SmartPRS\Platform\Rules\SpxExpression;
use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Scope;
use SmartPRS\Platform\Tenancy\TenantContext;

// THE MIGRATION ACCOUNT FROM .env, not root. Law 2 gives migrations their own
// privileged account precisely so the application's does not hold DDL — and this
// tool defaulted to root, which meant the separation existed in the configuration
// and not in the thing that actually runs. `root` remains the fallback only for a
// machine where the migration account has not been made yet, which on a first run
// is every machine; tools/db/bootstrap-local.php creates it and fills these in.
//
// The environment variables still win, because a CI job or a DBA running this by
// hand has credentials of their own and should not have to edit a file to use them.
// Database::env(), not parse_ini_file(): PHP's INI parser comments with `;` and
// treats a `#` line as a syntax error, so it returns FALSE for this project's own
// .env and every credential silently becomes missing.
require_once __DIR__ . '/../../surface/RowStore.php';
require_once __DIR__ . '/../../surface/Store.php';
require_once __DIR__ . '/../../surface/SqlStore.php';
require_once __DIR__ . '/../../surface/Database.php';

$fromEnvFile = \SmartPRS\Surface\Database::env(__DIR__ . '/../../.env');

$dsn = getenv('SMARTPRS_DSN') ?: sprintf('mysql:host=%s;port=%s;charset=utf8mb4',
    $fromEnvFile['DB_HOST'] ?? '127.0.0.1', $fromEnvFile['DB_PORT'] ?? '3306');
$user = getenv('SMARTPRS_USER') ?: ($fromEnvFile['DB_MIGRATION_USERNAME'] ?? '') ?: 'root';
$password = getenv('SMARTPRS_PASSWORD') ?: (string) ($fromEnvFile['DB_MIGRATION_PASSWORD'] ?? '');
$database = getenv('SMARTPRS_DB') ?: ($fromEnvFile['DB_DATABASE'] ?? '') ?: 'smartprs3';
$fresh = in_array('--fresh', $_SERVER['argv'], true);

try {
    $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    // THE ADVICE HAS TO MATCH THE FAILURE. This said "Start MySQL/MariaDB" for
    // every connection error, and printed it for an account whose authentication
    // plugin the running server does not load — so it told somebody to start a
    // service that was already running, three lines after a step that had just
    // talked to it. A message that names the wrong cause costs more than no
    // message: it is followed.
    $why = $e->getMessage();

    fwrite(STDERR, "Cannot connect as `{$user}`: {$why}\n\n");

    if (str_contains($why, '1524') || str_contains($why, 'not loaded')) {
        fwrite(STDERR,
            "The server IS running. This account exists and was created with an authentication\n"
            . "plugin this server does not load — MySQL 8.4 removed mysql_native_password, and a\n"
            . "my.ini that still names it as the default makes every account created that way\n"
            . "unusable while CREATE USER reports success.\n\n"
            . "Recreate both accounts against a plugin the server has:\n"
            . "    php tools/db/bootstrap-local.php\n");
    } elseif (str_contains($why, '1045') || str_contains($why, 'Access denied')) {
        fwrite(STDERR,
            "The server is running and refused these credentials. If `{$user}` has not been created\n"
            . "yet, make both accounts:\n    php tools/db/bootstrap-local.php\n");
    } else {
        fwrite(STDERR, "Start MySQL/MariaDB (in Laragon: Start All), then run this again.\n"
            . "Override with SMARTPRS_DSN / SMARTPRS_USER / SMARTPRS_PASSWORD.\n");
    }

    exit(1);
}

$exists = (bool) $pdo->query(
    "SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = " . $pdo->quote($database)
)->fetchColumn();

// A DATABASE THAT ALREADY EXISTS IS MIGRATED FORWARD, NOT DROPPED. Until this
// existed, the only way to add a column was --fresh, which on a customer's
// installation means destroying their payroll to ship a schema change. That is
// not a limitation of a tool, it is a product that cannot be updated.
//
// Which migrations have run is recorded IN THE DATABASE, in `migrations`. A file
// list on disk cannot answer "has this one been applied here?", and guessing
// wrong in either direction is bad: re-running a CREATE fails, and skipping one
// leaves a schema nobody can account for.
if ($exists && $fresh) {
    $pdo->exec("DROP DATABASE `{$database}`");
    $exists = false;
}

if (!$exists) {
    $pdo->exec("CREATE DATABASE `{$database}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

$pdo->exec("USE `{$database}`");
$pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                applied_at DATETIME NOT NULL,
                UNIQUE KEY migrations_migration_unique (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$applied = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
$tableCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ' . $pdo->quote($database)
    . " AND TABLE_NAME <> 'migrations'"
)->fetchColumn();

// A DATABASE WITH TABLES AND AN EMPTY LEDGER IS REFUSED, not assumed. It could
// be an installation from before this ledger existed, whose schema is complete
// — or one that is several migrations behind. Marking everything applied would
// be a check that checks nothing: the ledger would say the schema is current
// while a column nothing created is still missing, and the failure surfaces
// later as a query against a column that is not there. So the operator says
// which it is.
if ($applied === [] && $tableCount > 0 && !$fresh
    && !in_array('--baseline', $_SERVER['argv'], true)) {
    fwrite(STDERR, "`{$database}` has {$tableCount} table(s) but no record of which migrations "
        . "built them.\n\nThis is refused rather than guessed. Either:\n"
        . "  --baseline   the schema is already current; record every migration as applied\n"
        . "  --fresh      drop it and rebuild from the migrations (destroys the data)\n\n"
        . "Assuming the first would leave a ledger claiming the schema is up to date while a "
        . "column nothing created is missing.\n");

    exit(1);
}

if ($applied === [] && $tableCount > 0) {
    $record = $pdo->prepare('INSERT INTO migrations (migration, applied_at) VALUES (?, NOW())');

    foreach (glob(__DIR__ . '/../../database/migrations/*.php') as $file) {
        $record->execute([basename($file)]);
        $applied[] = basename($file);
    }

    echo "baselined: " . count($applied) . " migration(s) recorded as already applied, on your "
        . "word that the schema matches them.\n";
}

// ---- the migrations --------------------------------------------------------

Schema::reset();
$files = glob(__DIR__ . '/../../database/migrations/*.php');
sort($files);

$pending = array_values(array_filter($files,
    static fn (string $f): bool => !in_array(basename($f), $applied, true)));

// EVERY migration is loaded so the stub's picture of the schema is complete —
// the table count below and the checks that read it must describe the whole
// database, not only what changed today. Only the PENDING ones are executed.
$statements = [];

foreach ($files as $file) {
    $before = count(Schema::$statements);
    (require $file)->up();

    if (in_array($file, $pending, true)) {
        $statements = array_merge($statements,
            array_slice(Schema::$statements, $before));
        echo "  migrated  " . basename($file) . "\n";
    }
}

foreach ($statements as $statement) {
    $pdo->exec($statement);
}

$record = $pdo->prepare('INSERT INTO migrations (migration, applied_at) VALUES (?, NOW())');

foreach ($pending as $file) {
    $record->execute([basename($file)]);
}

printf("\n%d migration(s) applied, %d already there, %d table(s).\n\n",
    count($pending), count($files) - count($pending), count(Schema::$blueprints));

// A NEW TABLE IS INVISIBLE UNTIL IT IS GRANTED. The application account holds
// table-by-table privileges — Law 2, no ALTER/CREATE/DROP anywhere — so a table
// this run created is one the application cannot see at all: `information_schema`
// only shows a table to an account with some privilege on it, and the store
// correctly refuses a table it cannot find rather than showing an empty screen.
//
// `laragon-setup.bat` runs the grant step straight after this one for exactly
// that reason. Running `migrate` on its own does not, and the symptom is a fatal
// on a screen naming a table that plainly exists — said here so nobody spends an
// afternoon on it.
if ($pending !== []) {
    echo "  NEXT      the application account has no privileges on any table created just now.\n"
        . "            Run:  php tools/db/grant-application-account.php --apply\n"
        . "            (laragon-setup.bat does this for you; migrate on its own does not.)\n\n";
}

if ($pending === []) {
    echo "Nothing to do — the schema is up to date.\n";

    exit(0);
}

// ---- the minimum a tenant needs to exist -----------------------------------

// A SEED IS NOT A MIGRATION, and this block was written as if it were. It runs
// whenever ANY migration was applied, and it inserts the founding tenant, the
// status vocabulary and three published rule versions unconditionally — which
// worked for as long as every run started from an empty database and applied
// all of them.
//
// The first migration ever ADDED to an already-migrated database found it
// immediately: 000600 applied and was recorded, then this line threw
// "Duplicate entry for key tenants_uuid_unique" and the whole tool exited
// non-zero. `laragon-setup.bat` reads that exit code and stops with "Migrate
// refused", on a database whose schema is in fact perfectly up to date — so the
// visible failure names the wrong thing entirely, which is the most expensive
// kind. Every future migration would have done the same to every existing
// install.
//
// Guarded by asking the database rather than by inferring from `$pending`: what
// this block is really for is "has this database been seeded", and the count of
// migrations applied this run has never been the answer to that.
$alreadySeeded = (int) $pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn() > 0;

if ($alreadySeeded) {
    echo "  seed      skipped: this database already has a tenant, so the founding seed below
"
        . "            would duplicate it. The schema change above is applied and recorded.

";

    exit(0);
}

$pdo->exec("INSERT INTO tenants (uuid, code, name, status_code, created_at, updated_at)
            VALUES (UNHEX(REPEAT('a', 32)), 'T1', 'Ametecs India', 'active', NOW(), NOW())");
$tenantId = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO status_domains (uuid, domain_key, label, owning_context, created_at, updated_at)
            VALUES (UNHEX(REPEAT('b', 32)), 'config_version_status', 'Config version status', 'configuration', NOW(), NOW())");
$domainId = (int) $pdo->lastInsertId();

$seed = 0;
$insertStatus = $pdo->prepare(
    'INSERT INTO status_values (tenant_id, status_domain_id, uuid, code, label, sort, is_active, created_at, updated_at)
     VALUES (:t, :d, UNHEX(:u), :c, :l, :s, 1, NOW(), NOW())'
);

foreach (['draft', 'testing', 'pending_approval', 'published', 'superseded', 'archived'] as $code) {
    $insertStatus->execute([
        't' => $tenantId,
        'd' => $domainId,
        'u' => str_pad(dechex(++$seed), 32, '0', STR_PAD_LEFT),
        'c' => $code,
        'l' => ucwords(str_replace('_', ' ', $code)),
        's' => $seed,
    ]);
}

$pdo->exec("INSERT INTO config_artefacts
              (tenant_id, uuid, artefact_type, artefact_key, name, owning_context, is_active, created_at, updated_at)
            VALUES ({$tenantId}, UNHEX(REPEAT('c', 32)), 'rule', 'LATE-PENALTY',
                    'Late arrival penalty', 'attendance', 1, NOW(), NOW())");

echo "  seeded    tenant T1, the config_version_status vocabulary, artefact rule:LATE-PENALTY\n\n";

// ---- three versions, published the way the platform publishes --------------

$tenant = new TenantContext();
$tenant->establish($tenantId);
$statuses = new StatusLookup($pdo, $tenant);
$versions = new ConfigVersionRepository($pdo, $tenant, $statuses);
$ledger = new AuditLedger($pdo, $tenant);
$publisher = new Publisher($pdo, $tenant, $statuses, $versions, $ledger);

$point = 'attendance.day.penalise';
$symbols = DecisionPoints::symbols($point);
$rules = new RulePublisher($publisher);
$version = 0;

/**
 * One version, published through RulePublisher — so the nine gates run for real,
 * their outcomes are stored, and every `rule_payloads` column comes from the
 * Rule object rather than from an array typed out here.
 *
 * @param array<string, list<string>> $scope
 */
$publish = static function (
    string $source,
    string $description,
    array $scope,
    string $from,
    string $note,
) use ($rules, $symbols, $point, &$version): int {
    $version++;

    $rule = new Rule(
        code: 'LATE-PENALTY',
        decisionPoint: $point,
        module: 'attendance',
        name: 'Late arrival penalty',
        businessDescription: $description,
        scope: $scope === [] ? Scope::tenantDefault() : Scope::of($scope),
        priority: 0,
        range: EffectiveRange::openEndedFrom(new DateTimeImmutable($from)),
        versionNo: $version,
        versionId: 'late-penalty-v' . $version,
        status: 'published',
        when: SpxExpression::parse($source, $symbols),
        then: [new Action('APPLY_DAY_PENALTY', [
            'fraction' => SpxExpression::parse('0.5d', $symbols, boolean: false),
            'reason' => $description,
        ])],
        except: SpxExpression::parse('day.is_holiday OR day.is_weekly_off', $symbols),
        onConflict: ConflictPolicy::AllMatch,
        sourceRef: 'PayrollGenController::attendanceCut():449-455',
    );

    return $rules->publish(
        $rule,
        new DateTimeImmutable($from . ' 09:00:00'),
        // No sibling set and no stored cases in a fresh database, so R07 and
        // R08 record NOT CHECKED — the honest outcome, and exactly what the
        // console then displays.
        null,
        // There is no users table yet, so these are bare ids. They differ
        // because the rule is money-affecting and R09 refuses a self-approval.
        ['created_by' => '1', 'approved_by' => '2', 'reason' => $note, 'change_note' => $note],
    );
};

$v1 = $publish(
    "attendance.late_minutes > policy.value('late_grace_min')",
    'Any late arrival over the grace costs half a day.',
    [],
    '2026-04-01',
    'Opening policy for FY 2026-27.',
);
echo "  published rule:LATE-PENALTY v1  tenant default, from 2026-04-01\n";

$v2 = $publish(
    "attendance.late_minutes > policy.value('late_grace_min') AND NOT day.is_weekly_off",
    'A late arrival over the grace costs half a day, except on a weekly off.',
    [],
    '2026-08-01',
    'Weekly offs were being penalised. Corrected from August.',
);
echo "  published rule:LATE-PENALTY v2  tenant default, from 2026-08-01"
   . "  (v1 closed automatically at 2026-07-31)\n";

$v3 = $publish(
    "attendance.late_minutes > policy.value('late_grace_min') * 2",
    'Hyderabad runs a doubled grace while the flyover work continues.',
    ['branch' => ['HYD']],
    '2026-08-01',
    'Branch variation, requested by the Hyderabad site head.',
);
echo "  published rule:LATE-PENALTY v3  branch = HYD, from 2026-08-01\n";

$verified = $ledger->verify();
echo "\n  audit     " . (int) ($ledger->head()['seq'] ?? 0) . " ledger entr(ies), chain "
   . ($verified === null ? 'intact' : 'BROKEN at seq ' . $verified['seq']) . "\n";

echo "\nDone. Now:\n"
   . "  php -S localhost:8000 -t public          then open http://localhost:8000\n"
   . "  (or point Laragon at " . dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . "public)\n\n";
