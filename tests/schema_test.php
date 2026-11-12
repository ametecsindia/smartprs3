<?php

declare(strict_types=1);

use SmartPRS\Platform\Persistence\TableRegistry;
use Illuminate\Support\Facades\Schema;

/**
 * Runs the foundation migrations for real against MariaDB and asserts the spine
 * S16 data conventions against information_schema — not against the source text.
 *
 * Skips (rather than fails) when no database is reachable, so the value-object
 * suite still runs on a machine without one. The runner reports the skip.
 */

group('Schema');

$dsn = getenv('SMARTPRS_TEST_DSN') ?: 'mysql:host=127.0.0.1;charset=utf8mb4';
$user = getenv('SMARTPRS_TEST_USER') ?: 'root';
$password = getenv('SMARTPRS_TEST_PASSWORD') ?: '';
$database = getenv('SMARTPRS_TEST_DATABASE') ?: 'smartprs3_schema_check';

try {
    $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    echo "  ! schema tests skipped: no database ({$e->getMessage()})\n";

    return;
}

require_once __DIR__ . '/lib/laravel_schema_stub.php';

// --- compile the migrations -------------------------------------------------

Schema::reset();

$files = glob(__DIR__ . '/../database/migrations/*.php');
sort($files);

ok($files !== [], 'there are migration files to run');

foreach ($files as $file) {
    $migration = require $file;
    $migration->up();
}

// Seventeen foundation tables, the four rule/formula payload tables, the eight
// the workflow engine needs, and the two §IX.7 adds for SLA timers and the
// sweep's heartbeat. The count is asserted rather than described because a
// migration that silently stops creating a table is exactly the kind of thing a
// test suite should notice.
// 35 foundation tables plus the 87 ported from SmartPRS 2 (`tenants` is shared).
// Asserted as a NUMBER because a migration that silently stops creating tables is
// exactly what a test suite should notice — and after the port, "some tables
// exist" would notice nothing.
// 35 foundation + 87 ported from SmartPRS 2 + 6 the rebuild adds because
// SmartPRS 2 has no equivalent (see the surface-tables migration for the reason
// against each). A number, because "some tables exist" notices nothing.
// 35 foundation + 87 ported from SmartPRS 2's migrations + 39 recovered from the
// tables it creates at RUNTIME inside controllers + 8 the rebuild adds. The
// runtime 39 are why a migration-only reading of SmartPRS 2 undercounts by a
// third, and why this number is asserted rather than described.
// 35 foundation + 126 ported from SmartPRS 2 + 8 the rebuild adds + `overtime`,
// which SmartPRS 2 READS but nothing in it creates + `employment_events`, which
// is where leaving stopped being a flag and became an event with a date.
// +1: `visitor_log`, added 11 Sep for the owner's generalised Visitor/Client/
// Guest/... card types — the ID Card format's "remember this visit" feature.
// +2: `kra_kpi_targets` and `kra_kpi_achievements`, 16 Sep — the KRA/KPI
// register and the achievements recorded against it.
// +1: `loan_recovery_entries`, 22 Sep — an amount recovered against a salary
// advance or a loan, append-only: a mistake is cancelled, never edited.
//
// NOT +1 for `leads`. 2026_09_21_000100_create_leads_table.php creates a table
// that 2026_02_01_001500_port_runtime_created_tables.php ALREADY defines, so
// the total does not move — which is precisely the sort of thing this line
// exists to settle. A count kept by hand would have read 194 and been wrong.
//
// THIS NUMBER IS MEANT TO NEED CHANGING. Every migration that adds a table
// fails this line, and that is the point: a schema that grows without anybody
// noticing is how SmartPRS 2 ended up with 39 tables created at runtime inside
// controllers. Moving the number is a deliberate act with a comment beside it.
same(212, count(Schema::$blueprints), 'the schema defines 212 tables (195 + the 14–16 Oct PF/ESI, TDS, PT and Form 16 registers and the Super Admin / SmartEPT / WhatsApp tables + letter_lop, letter_acks 23 Oct + the six off-roll agent tables 23 Oct)');

// --- run them for real ------------------------------------------------------

$pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
$pdo->exec("CREATE DATABASE `{$database}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$database}`");

foreach (Schema::$statements as $statement) {
    try {
        $pdo->exec($statement);
    } catch (PDOException $e) {
        ok(false, 'migration failed: ' . $e->getMessage() . "\n" . $statement);

        return;
    }
}

ok(true, 'every foundation migration executes against MariaDB ' . $pdo->query('SELECT VERSION()')->fetchColumn());

$query = static function (string $sql) use ($pdo, $database): array {
    $statement = $pdo->prepare($sql);
    $statement->execute(['db' => $database]);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
};

$tables = array_column(
    $query('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db ORDER BY t'),
    't',
);
same(212, count($tables), 'and all 212 exist in the database');

// --- spine S16: no ENUM, anywhere ------------------------------------------

$enums = $query(
    'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = :db AND DATA_TYPE IN ("enum", "set")'
);
same([], $enums, 'no ENUM or SET column exists anywhere (S16)');

// --- Law 6: tenant_id NOT NULL, except the declared platform set -----------

// The platform-scoped set is READ FROM THE RUNTIME REGISTRY, not restated here.
// tools/gates/allow/platform-tables.php already says why: "a migration linter
// that allow-lists a table the runtime still scopes (or vice versa) is worse
// than no linter". This test had its own hand-kept copy of the list until
// `scheduler_heartbeats` was added to the registry and the copy did not know —
// which is the drift, arriving exactly as predicted.
$platformScoped = array_values(array_filter(
    TableRegistry::PLATFORM_TABLES,
    static fn (string $t): bool => in_array($t, $tables, true),
));

ok(
    in_array('tenants', $platformScoped, true) && in_array('status_domains', $platformScoped, true),
    'the registry still declares the two foundation platform tables — `tenants` IS the tenant, and '
    . '`status_domains` is a platform-owned vocabulary namespace whose values are tenant data',
);

foreach ($tables as $table) {
    $columns = array_column(
        $query(
            'SELECT COLUMN_NAME AS c, IS_NULLABLE AS n, COLUMN_TYPE AS ct FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = "' . $table . '"'
        ),
        null,
        'c',
    );

    if (in_array($table, $platformScoped, true)) {
        ok(!isset($columns['tenant_id']), "{$table} is platform-scoped and carries no tenant_id");

        continue;
    }

    ok(isset($columns['tenant_id']), "{$table} carries tenant_id");
    same('NO', $columns['tenant_id']['n'] ?? null, "{$table}.tenant_id is NOT NULL (Law 6)");
    ok(
        !array_key_exists('tenant', $columns) && !array_key_exists('company_id', $columns),
        "{$table} uses one tenancy column name only (S16)",
    );
}

// --- Law 6: tenant_id leads every unique index on a tenant-scoped table ----

foreach ($tables as $table) {
    $indexes = $query(
        'SELECT INDEX_NAME AS i, COLUMN_NAME AS c, SEQ_IN_INDEX AS s, NON_UNIQUE AS nu
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = "' . $table . '" ORDER BY i, s'
    );

    $unique = [];

    foreach ($indexes as $row) {
        if ((int) $row['nu'] === 0 && $row['i'] !== 'PRIMARY') {
            $unique[$row['i']][(int) $row['s']] = $row['c'];
        }
    }

    foreach ($unique as $name => $columns) {
        if (in_array($table, $platformScoped, true)) {
            continue;
        }

        same('tenant_id', $columns[1] ?? null, "unique index {$name} on {$table} leads with tenant_id");
    }
}

// --- S16: every foreign key declares an ON DELETE behaviour ----------------

$foreignKeys = $query(
    'SELECT rc.CONSTRAINT_NAME AS n, rc.TABLE_NAME AS t, rc.DELETE_RULE AS d, rc.REFERENCED_TABLE_NAME AS rt
     FROM information_schema.REFERENTIAL_CONSTRAINTS rc WHERE rc.CONSTRAINT_SCHEMA = :db ORDER BY n'
);

ok(count($foreignKeys) >= 15, 'the foundation declares foreign keys on its relationships (' . count($foreignKeys) . ')');

foreach ($foreignKeys as $fk) {
    ok(
        in_array($fk['d'], ['CASCADE', 'RESTRICT', 'SET NULL', 'NO ACTION'], true),
        "{$fk['n']} declares ON DELETE {$fk['d']}",
    );
}

// The DDL is the source of truth for "declared" — MySQL reports an omitted
// ON DELETE as RESTRICT too, so assert the text as well.
$fkStatements = 0;

foreach (Schema::$statements as $statement) {
    preg_match_all('/FOREIGN KEY[^,\n]*/', $statement, $matches);

    foreach ($matches[0] as $line) {
        $fkStatements++;
        ok(str_contains($line, 'ON DELETE'), 'foreign key DDL declares ON DELETE explicitly: ' . trim($line));
    }
}
same(count($foreignKeys), $fkStatements, 'every declared foreign key reached the database');

// Evidence is never cascaded away with its tenant.
foreach ($foreignKeys as $fk) {
    if (in_array($fk['t'], ['audit_entries', 'audit_anchors'], true) && $fk['rt'] === 'tenants') {
        same('RESTRICT', $fk['d'], "{$fk['t']}.tenant_id is RESTRICT — deleting a tenant cannot delete its evidence");
    }
}

// --- S16: dates are DATE, instants are DATETIME, money is DECIMAL(18,4) ----

$dateShaped = $query(
    'SELECT TABLE_NAME AS t, COLUMN_NAME AS c, DATA_TYPE AS dt, COLUMN_TYPE AS ct
     FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db
       AND (COLUMN_NAME LIKE "%\_on" OR COLUMN_NAME LIKE "%\_at" OR COLUMN_NAME LIKE "%amount%")'
);

ok($dateShaped !== [], 'there are dated columns to check');

foreach ($dateShaped as $column) {
    if ($column['t'] === 'incentive_schemes' && $column['c'] === 'pay_on') {
        // "Pay the rate on" the whole amount or only the excess (29 Oct 2026) — a choice, not a date; narrow and stated.
        same('varchar', $column['dt'], "{$column['t']}.{$column['c']} is a choice (whole / excess) and is text");
    } elseif (str_ends_with($column['c'], '_on')) {
        same('date', $column['dt'], "{$column['t']}.{$column['c']} is DATE");
    } elseif (str_ends_with($column['c'], '_at')) {
        same('datetime', $column['dt'], "{$column['t']}.{$column['c']} is DATETIME, never TIMESTAMP or VARCHAR");
    } elseif (str_ends_with($column['c'], '_path')) {
        // `subject_amount_path` NAMES the money field a workflow routes on; it
        // does not hold an amount. The rule below is worth keeping strict, so
        // the exception is narrow and stated rather than the rule being loosened.
        same('varchar', $column['dt'], "{$column['t']}.{$column['c']} names a field and is text, not an amount");
    } else {
        same('decimal(18,4)', $column['ct'], "{$column['t']}.{$column['c']} is DECIMAL(18,4)");
    }
}

// --- the tables the brief asks for, by name --------------------------------

foreach ([
    'tenants', 'installations',
    'org_unit_types', 'org_units', 'classification_types', 'classifications',
    'config_artefacts', 'config_versions', 'config_version_scopes',
    'status_domains', 'status_values', 'status_transitions',
    'audit_entries', 'audit_anchors',
] as $expected) {
    ok(in_array($expected, $tables, true), "table {$expected} exists");
}

// --- the audit ledger is append-only in shape ------------------------------

$auditColumns = array_column(
    $query(
        'SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = "audit_entries"'
    ),
    'c',
);

ok(in_array('prev_hash', $auditColumns, true), 'audit_entries carries prev_hash');
ok(in_array('hash', $auditColumns, true), 'audit_entries carries hash');
ok(in_array('seq', $auditColumns, true), 'audit_entries carries a per-tenant sequence');
ok(!in_array('updated_at', $auditColumns, true), 'audit_entries has no updated_at — there is no update path');


// =============================================================================
// §IX.6.3 — THE STRUCTURAL HALF OF "A DECISION CANNOT BE RE-DECIDED"
//
// Part IX says stating that decisions are immutable is not enough, because
// SmartPRS 2's problem is that nothing stops the write. Three of the four
// mechanisms are schema, so they are asserted here rather than trusted.
// =============================================================================

group('Workflow immutability mechanisms');

$indexes = $query(
    'SELECT TABLE_NAME AS t, INDEX_NAME AS i, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols, NON_UNIQUE AS nu
     FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = :db
       AND TABLE_NAME IN ("workflow_transitions", "workflow_completion_outbox", "workflow_tasks")
     GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE'
);

$unique = [];

foreach ($indexes as $index) {
    if ((int) $index['nu'] === 0) {
        $unique["{$index['t']}:{$index['cols']}"] = true;
    }
}

ok(
    isset($unique['workflow_transitions:tenant_id,workflow_instance_id,seq']),
    'mechanism 3: the transition ledger is unique on (tenant, instance, seq), so a replayed decision '
    . 'cannot obtain a second seq for the same instance',
);

ok(
    isset($unique['workflow_completion_outbox:tenant_id,workflow_instance_id,completion_event_key']),
    'mechanism 4: the completion outbox is unique on the event key, so even a double-finalise cannot '
    . 'produce two transfer orders (R-IX-026) — SmartPRS 2 re-fires its transfer-order side effect',
);

$taskColumns = $query(
    'SELECT COLUMN_NAME AS c, IS_NULLABLE AS n FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = :db AND TABLE_NAME = "workflow_tasks"'
);
$byName = [];

foreach ($taskColumns as $column) {
    $byName[$column['c']] = $column;
}

ok(
    isset($byName['row_version']) && $byName['row_version']['n'] === 'NO',
    'mechanism 1: workflow_tasks carries a NOT NULL row_version, which the conditional UPDATE keys on — '
    . 'zero affected rows is 409 workflow.task_already_decided',
);

ok(
    isset($byName['decided_by_user_id']) && isset($byName['decided_at']),
    'and the decision is recorded on the task row it belongs to',
);

// R-IX-046: the comment lives on the transition, so a blank re-decide cannot
// erase the prior decision's stated reason. SmartPRS 2 writes remarks
// unconditionally on every decide, which is exactly how that happens.
$transitionColumns = $query(
    'SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = :db AND TABLE_NAME = "workflow_transitions"'
);
$transitionNames = array_column($transitionColumns, 'c');

ok(in_array('comment', $transitionNames, true), 'R-IX-046: a decision comment is a column on the transition');
ok(
    in_array('was_attempt', $transitionNames, true),
    'R-IX-050: and a decision that arrives after the node was decided is still written, as an attempt — '
    . 'that a second approver disagreed is material even when it changes nothing',
);
ok(
    in_array('resolution_json', $transitionNames, true),
    'R-IX-028: with who was resolved, from which spec, and every exclusion applied — because "why did '
    . 'this come to me" is unanswerable in SmartPRS 2',
);

// R-VIII-013 / §14.5: the gate outcomes are stored, one row per gate.
$checks = $query(
    'SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = :db AND TABLE_NAME = "config_publication_checks"'
);
$checkNames = array_column($checks, 'c');

foreach (['gate_code', 'severity', 'messages_json'] as $needed) {
    ok(in_array($needed, $checkNames, true),
        "a publication check row carries {$needed}, so a blocked publication is explainable and a passed one evidenced");
}


$pdo->exec("DROP DATABASE `{$database}`");
