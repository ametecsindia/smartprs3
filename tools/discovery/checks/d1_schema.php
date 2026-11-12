<?php
/**
 * D1 — schema capture, provenance and the schema fingerprint.
 *
 * Answers, per install:
 *   - what the schema actually is (SHOW CREATE TABLE, columns, indexes, FKs)
 *   - which tables exist that NO migration creates (migration-vs-runtime provenance)
 *   - which of the XXIX.1.2 type divergences this install carries
 *   - which shape attendance_pending has (bulk / quarantine / mixed)
 *   - the schema fingerprint that assigns this install to a migration profile
 *
 * Read-only. Standalone: php checks/d1_schema.php --app=/path/to/smartprs2
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

/** The 49 runtime-only tables of J-… §2.1.1, plus the dead ones, as a fallback
 *  when --app is not available to derive provenance from the migrations directory. */
const D1_EXPECTED_RUNTIME_ONLY = [
    'statutory_settings', 'pay_cycles', 'roster', 'overtime', 'salary_payments', 'loan_recoveries',
    'min_wages', 'dra_certs', 'posh_complaints', 'user_password_resets', 'policies', 'awards',
    'notices', 'feature_flags', 'transfers', 'onboarding', 'onboarding_items', 'offroll_agents',
    'agent_earnings', 'biometric_configs', 'biometric_unmapped', 'attendance', 'attendance_pending',
    'employee_company', 'notifications_feed', 'company_emails', 'pf_esi_settings', 'tds_rules',
    'pt_slabs', 'salary_runs', 'salary_slips', 'leave_applications', 'mobile_devices', 'licences',
    'licence_events', 'licence_devices', 'releases', 'release_grants', 'client_updates',
    'onprem_clients', 'onprem_payments',
];

/** The XXIX.1.2 / J-… Q9 divergence probes: table => columns whose live type decides the profile. */
const D1_DIVERGENCE_PROBES = [
    'employees' => ['dob', 'doj', 'dra_expiry', 'pcc_expiry', 'pcc_deadline', 'geo_outside',
        'pcc_status', 'dra_status', 'aadhaar', 'national_id', 'employment_type', 'employment_stage',
        'archived_at', 'backup_due_at', 'deleted_at', 'probation_months', 'geo_radius_km'],
    'salary_components' => ['calc_value', 'ctype', 'scope', 'seq', 'code'],
    'settings' => ['value', 'tenant_id', 'key'],
    'attendance_pending' => ['*'],
    'attendance_logs' => ['tenant_id', 'emp_code', 'direction', 'source', 'punch_at', 'log_date',
        'external_id', 'device_sn', 'device_user_id', 'verify_mode'],
    'incentive_schemes' => ['*'],
    'offroll_agents' => ['*'],
    'documents' => ['tenant_id', 'status', 'kind', 'category', 'file_path', 'file_size'],
    'code_of_conduct_ack' => ['*'],
    'training_subjects' => ['*'],
    'training_programs' => ['status'],
    'messages_log' => ['sent_at'],
    'letters' => ['status', 'company_id', 'employee_id', 'is_template'],
    'loans' => ['*'],
    'commissions' => ['status', 'amount', 'gross_amount', 'month'],
    'shifts' => ['start_time', 'end_time', 'full_day_hours', 'half_day_hours'],
    'late_policy' => ['shift_start', 'shift_end', 'mode', 'cut_mode', 'full_day_hours'],
    'faqs' => ['question'],
    'test_attempts' => ['score'],
];

/** The type each column SHOULD have, so the summary can call the divergence directly. */
const D1_EXPECTED_TYPE = [
    'employees.dob' => 'date', 'employees.doj' => 'date',
    'employees.dra_expiry' => 'date', 'employees.pcc_expiry' => 'date', 'employees.pcc_deadline' => 'date',
    'salary_components.calc_value' => 'varchar',   // must hold a formula string, e.g. "0.4 * BASIC"
    'messages_log.sent_at' => 'timestamp',
];

function d1_schema(DscCtx $c): void
{
    $db = $c->db;

    // D1.0  Server facts the rest of the pack is interpreted against.
    $server = [
        'database' => $db,
        'version' => (string) dsc_val($c, 'SELECT VERSION()', [], 'version', ''),
        'sql_mode' => (string) dsc_val($c, 'SELECT @@SESSION.sql_mode', [], 'sql_mode', ''),
        'global_sql_mode' => (string) dsc_val($c, 'SELECT @@GLOBAL.sql_mode', [], 'sql_mode', ''),
        'time_zone_session' => (string) dsc_val($c, 'SELECT @@SESSION.time_zone', [], 'tz', ''),
        'time_zone_global' => (string) dsc_val($c, 'SELECT @@GLOBAL.time_zone', [], 'tz', ''),
        'character_set_server' => (string) dsc_val($c, 'SELECT @@character_set_server', [], 'charset', ''),
        'collation_server' => (string) dsc_val($c, 'SELECT @@collation_server', [], 'charset', ''),
        'lower_case_table_names' => (string) dsc_val($c, 'SELECT @@lower_case_table_names', [], 'lctn', ''),
    ];
    $server['grants'] = dsc_check_grants($c);
    dsc_write_json($c, 'schema/server.json', $server);

    $strict = str_contains(strtoupper($server['sql_mode']), 'STRICT');
    $c->finding($strict ? 'INFO' : 'HIGH', 'schema',
        'SQL mode is ' . ($strict ? 'STRICT' : 'NON-STRICT'),
        $strict
            ? 'Enum violations THREW on write: the affected values are missing from the data entirely.'
            : 'Enum violations were SILENTLY BLANKED on write: affected columns hold empty strings or '
              . 'NULL where a real value was entered. Different remediation from the strict case (R-XXIX-014).',
        ['sql_mode' => $server['sql_mode']]);

    // D1.1  Table inventory: engine, collation, row estimate, size.
    $tables = dsc_q($c, "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_ROWS,
                                DATA_LENGTH, INDEX_LENGTH, DATA_LENGTH + INDEX_LENGTH AS TOTAL_BYTES,
                                AUTO_INCREMENT, CREATE_TIME, UPDATE_TIME, TABLE_COMMENT
                           FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
                          ORDER BY TABLE_NAME", [$db], 'd1.1');
    dsc_write_csv($c, 'schema/tables.csv', $tables, 'none');

    $liveTables = array_column($tables, 'TABLE_NAME');
    $engines = array_count_values(array_map(static fn($r) => (string) $r['ENGINE'], $tables));
    $collations = array_count_values(array_map(static fn($r) => (string) $r['TABLE_COLLATION'], $tables));
    if (count($engines) > 1) {
        $c->finding('MEDIUM', 'schema', 'Mixed storage engines', json_encode($engines), $engines);
    }
    if (count($collations) > 1) {
        $c->finding('MEDIUM', 'schema', 'Mixed table collations',
            'A join between two collations either fails or silently disables index use. '
            . json_encode($collations), $collations);
    }

    // D1.2  Full column definitions. THIS is the schema authority, not the migrations.
    $cols = dsc_q($c, "SELECT TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE,
                              CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE,
                              IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY, COLLATION_NAME, COLUMN_COMMENT
                         FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = ?
                        ORDER BY TABLE_NAME, ORDINAL_POSITION", [$db], 'd1.2');
    dsc_write_csv($c, 'schema/columns.csv', $cols, 'none');

    // D1.3  Every index — a missing composite index is per-install (J-… Q5).
    $idx = dsc_q($c, "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME,
                             SUB_PART, NULLABLE, INDEX_TYPE
                        FROM information_schema.STATISTICS
                       WHERE TABLE_SCHEMA = ?
                       ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX", [$db], 'd1.3');
    dsc_write_csv($c, 'schema/indexes.csv', $idx, 'none');

    // D1.4  Foreign keys. Expect four, all Spatie.
    $fks = dsc_q($c, "SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME,
                             REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                        FROM information_schema.KEY_COLUMN_USAGE
                       WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL
                       ORDER BY TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME", [$db], 'd1.4');
    dsc_write_csv($c, 'schema/foreign_keys.csv', $fks, 'none');
    $c->finding(count($fks) <= 4 ? 'INFO' : 'MEDIUM', 'schema',
        count($fks) . ' foreign keys in the whole database',
        'Expected 4, all in the Spatie permission migration. Every other reference is unconstrained, '
        . 'so the orphan counts in integrity/orphans.csv are the real referential state.',
        ['count' => count($fks)]);

    // D1.5  SHOW CREATE TABLE for every table — the faithful record.
    $ddl = "-- SmartPRS 2 discovery: SHOW CREATE TABLE for every table in `$db`\n"
         . "-- Install: {$c->install}   Collected: " . gmdate('c') . "\n\n";
    foreach ($liveTables as $t) {
        $r = dsc_q($c, 'SHOW CREATE TABLE ' . dsc_id($t), [], 'showcreate:' . $t);
        $sql = $r ? (string) ($r[0]['Create Table'] ?? $r[0]['Create View'] ?? '') : '-- UNAVAILABLE';
        // AUTO_INCREMENT drifts on every insert; strip it so re-running the pack is reproducible.
        $sql = preg_replace('/ AUTO_INCREMENT=\d+/', '', $sql) ?? $sql;
        $ddl .= "-- ===== $t =====\n$sql;\n\n";
    }
    dsc_write_text($c, 'schema/create_tables.sql', $ddl);

    // D1.6  The migration ledger: what this install BELIEVES it ran.
    $mig = dsc_has_table($c, 'migrations')
        ? dsc_q($c, 'SELECT id, migration, batch FROM migrations ORDER BY id', [], 'd1.6')
        : [];
    dsc_write_csv($c, 'schema/migrations_ledger.csv', $mig, 'none');
    $ranMigrations = array_column($mig, 'migration');

    // D1.7  PROVENANCE — the migration-versus-runtime question.
    $prov = d1_provenance($c, $liveTables, $ranMigrations);
    dsc_write_csv($c, 'schema/provenance.csv', $prov['rows'], 'none');
    dsc_write_json($c, 'schema/provenance_summary.json', $prov['summary']);

    $noMigration = array_values(array_filter($prov['rows'],
        static fn($r) => $r['present'] === 'yes' && $r['creates_migration'] === 'no'));
    $c->finding(count($noMigration) ? 'BLOCKER' : 'INFO', 'schema',
        count($noMigration) . ' live tables that no migration creates',
        'These tables exist only because application code created them at run time. Their schema '
        . 'cannot be read from source and must be taken from schema/create_tables.sql. '
        . 'Source of the migration list: ' . $prov['summary']['migration_source'] . '.',
        ['tables' => array_column($noMigration, 'table')]);

    $missing = array_values(array_filter($prov['rows'], static fn($r) => $r['present'] === 'no'));
    if ($missing) {
        $c->finding('HIGH', 'schema', count($missing) . ' expected tables are ABSENT on this install',
            'A feature whose table never got created is a feature nobody used — or one that failed '
            . 'silently. Either way it is not migration scope here.',
            ['tables' => array_column($missing, 'table')]);
    }

    // D1.8  The type-divergence probes, answered directly.
    $div = [];
    foreach (D1_DIVERGENCE_PROBES as $t => $want) {
        $m = dsc_cols($c, $t);
        if ($m === []) {
            $div[] = ['table' => $t, 'column' => '(table absent)', 'column_type' => '', 'is_nullable' => '',
                      'column_default' => '', 'expected' => '', 'verdict' => 'TABLE ABSENT'];
            continue;
        }
        $names = ($want === ['*']) ? array_keys($m) : $want;
        foreach ($names as $col) {
            if (!isset($m[$col])) {
                if ($want !== ['*']) {
                    $div[] = ['table' => $t, 'column' => $col, 'column_type' => '', 'is_nullable' => '',
                              'column_default' => '', 'expected' => D1_EXPECTED_TYPE["$t.$col"] ?? '',
                              'verdict' => 'COLUMN ABSENT'];
                }
                continue;
            }
            $row = $m[$col];
            $full = null;
            foreach ($cols as $cr) {
                if ($cr['TABLE_NAME'] === $t && $cr['COLUMN_NAME'] === $col) {
                    $full = $cr;
                    break;
                }
            }
            $expected = D1_EXPECTED_TYPE["$t.$col"] ?? '';
            $actual = strtolower((string) $row['DATA_TYPE']);
            $verdict = $expected === '' ? '' : ($actual === $expected ? 'AS EXPECTED' : 'DIVERGENT');
            $div[] = [
                'table' => $t,
                'column' => $col,
                'column_type' => (string) $row['COLUMN_TYPE'],
                'is_nullable' => (string) $row['IS_NULLABLE'],
                'column_default' => (string) ($full['COLUMN_DEFAULT'] ?? ''),
                'expected' => $expected,
                'verdict' => $verdict,
            ];
        }
    }
    usort($div, static fn($a, $b) => [$a['table'], $a['column']] <=> [$b['table'], $b['column']]);
    dsc_write_csv($c, 'schema/divergent_columns.csv', $div, 'none');

    $bad = array_values(array_filter($div, static fn($r) => $r['verdict'] === 'DIVERGENT'));
    foreach ($bad as $r) {
        $sev = in_array($r['table'] . '.' . $r['column'], ['salary_components.calc_value'], true) ? 'BLOCKER' : 'HIGH';
        $c->finding($sev, 'schema',
            "Type divergence: {$r['table']}.{$r['column']} is {$r['column_type']}, expected {$r['expected']}",
            $r['table'] === 'salary_components'
                ? 'calc_value holds a formula string such as "0.4 * BASIC". A DECIMAL column has already '
                  . 'silently truncated every formula on this install to a number — payroll correctness '
                  . 'diverges here (J-… §9 Q2 M3). Check money/ and defects/ before assuming any payslip is reproducible.'
                : 'Date arithmetic on this column is string comparison on this install: compliance alerts '
                  . 'and service-length derivations fire on the wrong people (J-… Q9 D1/D2).');
    }

    // D1.9  attendance_pending discriminator.
    $apCols = array_keys(dsc_cols($c, 'attendance_pending'));
    $shape = 'ABSENT';
    if ($apCols) {
        $bulk = count(array_intersect(['in_at', 'out_at', 'batch', 'log_date'], $apCols)) >= 3;
        $quar = count(array_intersect(['punch_at', 'device_code', 'external_id', 'source'], $apCols)) >= 3;
        $shape = $bulk && $quar ? 'mixed' : ($bulk ? 'bulk' : ($quar ? 'quarantine' : 'unrecognised'));
        $rows = dsc_has_table($c, 'attendance_pending')
            ? (int) dsc_val($c, 'SELECT COUNT(*) FROM attendance_pending', [], 'd1.9', 0) : 0;
        $c->finding($shape === 'mixed' ? 'BLOCKER' : ($rows > 0 ? 'HIGH' : 'INFO'), 'schema',
            "attendance_pending shape: $shape ($rows rows)",
            $shape === 'mixed'
                ? 'Both column sets present. R-XXIX-011: every row must be routed individually by '
                  . 'populated-column set; rows matching neither shape must be counted and listed. Do not guess.'
                : 'Records which of the two disjoint features owns this table here.',
            ['shape' => $shape, 'rows' => $rows, 'columns' => $apCols]);
    }

    // D1.10 The schema fingerprint. Installs sharing it share a migration profile.
    $fpParts = [];
    foreach ($cols as $r) {
        $fpParts[] = implode('|', [$r['TABLE_NAME'], $r['COLUMN_NAME'], $r['COLUMN_TYPE'],
            $r['IS_NULLABLE'], (string) $r['COLUMN_DEFAULT'], $r['EXTRA'], (string) $r['COLLATION_NAME']]);
    }
    foreach ($idx as $r) {
        $fpParts[] = implode('|', ['IDX', $r['TABLE_NAME'], $r['INDEX_NAME'], $r['NON_UNIQUE'],
            $r['SEQ_IN_INDEX'], $r['COLUMN_NAME'], (string) $r['SUB_PART']]);
    }
    sort($fpParts, SORT_STRING);
    $fingerprint = 'sha256:' . hash('sha256', implode("\n", $fpParts));

    dsc_write_json($c, 'schema/fingerprint.json', [
        'install' => $c->install,
        'database' => $db,
        'schema_fingerprint' => $fingerprint,
        'table_count' => count($liveTables),
        'column_count' => count($cols),
        'index_count' => count($idx),
        'foreign_key_count' => count($fks),
        'attendance_pending_shape' => $shape,
        'divergent_columns' => array_column(
            array_map(static fn($r) => [$r['table'] . '.' . $r['column'], $r['column_type']], $bad), 1, 0),
        'migrations_ran' => count($ranMigrations),
        'tables_without_migration' => count($noMigration),
        'sql_mode' => $server['sql_mode'],
    ]);
    $c->finding('INFO', 'schema', 'Schema fingerprint ' . substr($fingerprint, 7, 16) . '…',
        'Register this install against this fingerprint in the schema profile register (R-XXIX-010). '
        . count($liveTables) . ' tables, ' . count($cols) . ' columns, ' . count($idx) . ' index parts.',
        ['fingerprint' => $fingerprint]);

    dsc_finish($c, 'd1_schema');
}

/**
 * Provenance: for every live table, does a migration create it, does runtime code create it,
 * or neither? Derived from the application source when --app is given; otherwise from the
 * known runtime-only list in Part XXIX.
 */
function d1_provenance(DscCtx $c, array $liveTables, array $ranMigrations): array
{
    $app = isset($c->opt['app']) ? rtrim((string) $c->opt['app'], "/\\") : null;
    $byMigration = [];
    $byRuntime = [];
    $source = 'known list from Part XXIX §XXIX.1.1 (no --app given, so the source tree was not read)';

    if ($app !== null && is_dir($app . '/database/migrations')) {
        $source = 'parsed from ' . $app;
        $byMigration = d1_scan_creates($app . '/database/migrations');
        foreach (['/app', '/database/seeders', '/routes'] as $sub) {
            if (is_dir($app . $sub)) {
                $byRuntime = array_merge_recursive($byRuntime, d1_scan_creates($app . $sub));
            }
        }
    }

    $rows = [];
    $known = array_fill_keys(D1_EXPECTED_RUNTIME_ONLY, true);
    $universe = array_unique(array_merge($liveTables, array_keys($byMigration), array_keys($known)));
    sort($universe);

    foreach ($universe as $t) {
        $present = in_array($t, $liveTables, true);
        if ($byMigration || $byRuntime) {
            $mig = isset($byMigration[$t]) ? 'yes' : 'no';
            $run = isset($byRuntime[$t]) ? 'yes' : 'no';
        } else {
            $mig = isset($known[$t]) ? 'no' : ($present ? 'unknown' : 'no');
            $run = isset($known[$t]) ? 'yes' : 'unknown';
        }
        $rows[] = [
            'table' => $t,
            'present' => $present ? 'yes' : 'no',
            'creates_migration' => $mig,
            'creates_runtime' => $run,
            'verdict' => !$present ? 'ABSENT'
                : ($mig === 'no' && $run === 'yes' ? 'RUNTIME-ONLY'
                : ($mig === 'yes' && $run === 'yes' ? 'BOTH (drift candidate)'
                : ($mig === 'yes' ? 'MIGRATION' : 'UNATTRIBUTED'))),
            'migration_sites' => isset($byMigration[$t]) ? implode(' ', (array) $byMigration[$t]) : '',
            'runtime_sites' => isset($byRuntime[$t]) ? implode(' ', array_slice((array) $byRuntime[$t], 0, 6)) : '',
        ];
    }

    $count = static fn(string $v) => count(array_filter($rows, static fn($r) => $r['verdict'] === $v));
    return [
        'rows' => $rows,
        'summary' => [
            'migration_source' => $source,
            'live_tables' => count($liveTables),
            'runtime_only' => $count('RUNTIME-ONLY'),
            'both_definitions' => $count('BOTH (drift candidate)'),
            'migration_only' => $count('MIGRATION'),
            'unattributed' => $count('UNATTRIBUTED'),
            'absent' => $count('ABSENT'),
            'migrations_ran' => count($ranMigrations),
        ],
    ];
}

/** Grep a source tree for table-creating calls. Returns table => [file:line, …]. */
function d1_scan_creates(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') {
            continue;
        }
        $lines = @file($f->getPathname(), FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            continue;
        }
        foreach ($lines as $i => $line) {
            // Schema::create('t'  |  Schema::createIfNotExists('t'  |  CREATE TABLE `t`
            if (preg_match_all('/Schema::(?:connection\([^)]*\)->)?create(?:IfNotExists)?\(\s*[\'"]([a-z0-9_]+)[\'"]/i', $line, $m)) {
                foreach ($m[1] as $t) {
                    $out[$t][] = basename($f->getPathname()) . ':' . ($i + 1);
                }
            }
            if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z0-9_]+)`?/i', $line, $m)) {
                foreach ($m[1] as $t) {
                    $out[$t][] = basename($f->getPathname()) . ':' . ($i + 1);
                }
            }
        }
    }
    foreach ($out as $t => $sites) {
        $out[$t] = array_values(array_unique($sites));
    }
    ksort($out);
    return $out;
}

if (realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__)) {
    dsc_standalone('d1_schema.php', 'd1_schema');
}
