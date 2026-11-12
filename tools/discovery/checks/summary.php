<?php
/**
 * Builds SUMMARY.md and manifest.json from a completed pack directory.
 *
 * The summary LEADS with what changes the migration plan and puts the raw dumps
 * last. It reads only files inside the pack, so it can be re-run after a pack has
 * been edited or partially re-collected:
 *
 *   php checks/summary.php --pack=./discovery-pack-acme-2026-09-03
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

const DSC_SEVERITY_ORDER = ['BLOCKER' => 0, 'HIGH' => 1, 'MEDIUM' => 2, 'LOW' => 3, 'INFO' => 4];

function dsc_build_summary(string $pack, array $meta = []): void
{
    $pack = rtrim($pack, "/\\");
    $findings = [];
    $notes = [];
    foreach (glob($pack . '/_meta/findings-*.json') ?: [] as $f) {
        $j = json_decode((string) file_get_contents($f), true);
        foreach ((array) ($j['findings'] ?? []) as $x) {
            $x['check'] = $j['check'] ?? basename($f);
            $findings[] = $x;
        }
        foreach ((array) ($j['notes'] ?? []) as $x) {
            $x['check'] = $j['check'] ?? basename($f);
            $notes[] = $x;
        }
    }
    usort($findings, static fn($a, $b) =>
        [DSC_SEVERITY_ORDER[$a['severity']] ?? 9, $a['area'], $a['title']]
        <=> [DSC_SEVERITY_ORDER[$b['severity']] ?? 9, $b['area'], $b['title']]);

    $j = static function (string $rel) use ($pack) {
        $p = $pack . '/' . $rel;
        return is_file($p) ? json_decode((string) file_get_contents($p), true) : null;
    };
    $fp = $j('schema/fingerprint.json') ?? [];
    $env = $j('env/fingerprint.json') ?? [];
    $base = $j('profile/baseline.json') ?? [];
    $prov = $j('schema/provenance_summary.json') ?? [];

    $count = static fn(string $sev) => count(array_filter($findings, static fn($x) => $x['severity'] === $sev));
    $blockers = $count('BLOCKER');
    $high = $count('HIGH');

    $md = [];
    $md[] = '# SmartPRS 2 Discovery Pack — ' . ($meta['install'] ?? ($fp['install'] ?? basename($pack)));
    $md[] = '';
    $md[] = '**Collected:** ' . ($meta['collected_at'] ?? ($env['collected_at_utc'] ?? '?')) . ' UTC'
        . '  ·  **By:** ' . ($env['collected_by'] ?? '?')
        . '  ·  **Redaction:** ' . (($env['redacted'] ?? true) ? 'REDACTED (default)' : '**UNREDACTED — contains PII**');
    $md[] = '**Database:** ' . ($fp['database'] ?? '?')
        . '  ·  **Schema fingerprint:** `' . ($fp['schema_fingerprint'] ?? 'not computed') . '`';
    $md[] = '';
    $md[] = "> This pack is the Gate G-1 evidence for this install. Until it is validated and countersigned, "
        . "every migration figure for this customer stays PROVISIONAL (R-XXIX-001, R-XXIX-002).";
    $md[] = '';

    /* ---- 1. What changes the migration plan -------------------------- */
    $md[] = '## 1. What changes the migration plan';
    $md[] = '';
    $md[] = "**$blockers blockers, $high high findings.** Everything here is evidence from THIS install; "
        . 'nothing is assumed from the SmartPRS 2 source tree.';
    $md[] = '';
    if (!$findings) {
        $md[] = '_No findings were recorded. That is itself suspicious — check `_meta/` for collection errors._';
    }
    foreach (['BLOCKER', 'HIGH'] as $sev) {
        $rows = array_values(array_filter($findings, static fn($x) => $x['severity'] === $sev));
        if (!$rows) {
            continue;
        }
        $md[] = "### $sev";
        $md[] = '';
        foreach ($rows as $f) {
            $md[] = '- **' . $f['title'] . '**  \\';
            $md[] = '  ' . str_replace("\n", ' ', (string) $f['detail'])
                . '  _(' . $f['area'] . ' · ' . $f['check'] . ')_';
        }
        $md[] = '';
    }

    /* ---- 2. Migration profile ---------------------------------------- */
    $md[] = '## 2. This install\'s migration profile';
    $md[] = '';
    $md[] = '| Fact | Value |';
    $md[] = '|---|---|';
    $md[] = '| Schema fingerprint | `' . ($fp['schema_fingerprint'] ?? '?') . '` |';
    $md[] = '| Tables in the live database | ' . ($fp['table_count'] ?? '?') . ' |';
    $md[] = '| Tables no migration creates | ' . ($fp['tables_without_migration'] ?? '?')
        . ' (' . ($prov['migration_source'] ?? 'source unknown') . ') |';
    $md[] = '| Tables defined in both places (drift candidates) | ' . ($prov['both_definitions'] ?? '?') . ' |';
    $md[] = '| Expected tables absent here | ' . ($prov['absent'] ?? '?') . ' |';
    $md[] = '| Migrations the ledger says ran | ' . ($fp['migrations_ran'] ?? '?') . ' |';
    $md[] = '| Foreign keys | ' . ($fp['foreign_key_count'] ?? '?') . ' |';
    $md[] = '| `attendance_pending` shape | ' . ($fp['attendance_pending_shape'] ?? '?') . ' |';
    $md[] = '| MySQL `sql_mode` | `' . ($fp['sql_mode'] ?? '?') . '` |';
    $md[] = '| App timezone | ' . (($env['laravel']['APP_TIMEZONE'] ?? null) ?? '(unset → UTC)') . ' |';
    $md[] = '| PHP / MySQL | ' . ($env['php']['version'] ?? '?') . ' / ' . ($env['mysql']['version'] ?? '?') . ' |';
    $md[] = '| Queue worker running | ' . (($env['operational']['queue_worker_running'] ?? null) === true ? 'yes' : 'NO') . ' |';
    $md[] = '| `demo:reset` scheduled | ' . ($env['operational']['demo_reset']['scheduled'] ?? '?') . ' |';
    $md[] = '';

    $div = (array) ($fp['divergent_columns'] ?? []);
    $md[] = '### Type divergences carried by this install';
    $md[] = '';
    if ($div) {
        $md[] = '| Column | Actual type here |';
        $md[] = '|---|---|';
        foreach ($div as $k => $v) {
            $md[] = "| `$k` | `$v` |";
        }
        $md[] = '';
        $md[] = 'Each of these makes a transformation conditional. Register them against the profile '
            . '(R-XXIX-010) before writing any migration code for this customer.';
    } else {
        $md[] = 'None of the probed columns diverge from the expected type on this install. '
            . 'See `schema/divergent_columns.csv` for the full probe result including absent columns.';
    }
    $md[] = '';

    /* ---- 3. Reconciliation baselines --------------------------------- */
    $md[] = '## 3. Reconciliation baselines';
    $md[] = '';
    $md[] = 'Counts are **' . ($base['counts_mode'] ?? '?') . '**. Only exact counts may be cited in a '
        . 'reconciliation report (R-XXIX-012).';
    $md[] = '';
    $md[] = '| Entity | Count |';
    $md[] = '|---|---|';
    foreach (['tenants', 'tenants_live', 'companies', 'employees_total', 'employees_live',
              'employees_archived', 'employees_live_and_unarchived', 'users', 'attendance_logs',
              'payroll_runs', 'payslips', 'leaves', 'documents', 'loans', 'commissions', 'activity_logs'] as $k) {
        if (array_key_exists($k, $base)) {
            $md[] = '| ' . $k . ' | ' . ($base[$k] === null ? '_(table absent)_' : number_format((int) $base[$k])) . ' |';
        }
    }
    if (isset($base['total_bytes'])) {
        $md[] = '| database size | ' . round(((int) $base['total_bytes']) / 1048576, 1) . ' MB |';
    }
    $md[] = '';

    /* ---- 4. Contamination register ----------------------------------- */
    $reg = $pack . '/defects/contamination_register.csv';
    $md[] = '## 4. Contamination register';
    $md[] = '';
    if (is_file($reg)) {
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim((string) file_get_contents($reg)))));
        $head = array_shift($rows);
        if ($head && $rows) {
            $md[] = '| ' . implode(' | ', $head) . ' |';
            $md[] = '|' . str_repeat('---|', count($head));
            foreach ($rows as $r) {
                $md[] = '| ' . implode(' | ', array_map(static fn($v) => str_replace('|', '\\|', (string) $v), $r)) . ' |';
            }
            $md[] = '';
            $md[] = '**Every `disposition` cell above is empty by design.** R-XXIX-028: the Product Owner fills '
                . 'them before Gate G-3, and no data may be loaded into SmartPRS 3 until they are filled.';
        }
    } else {
        $md[] = '_Not collected (`d9_defects` did not run)._';
    }
    $md[] = '';

    /* ---- 5. Gate readiness ------------------------------------------- */
    $have = static fn(string $rel) => is_file($pack . '/' . $rel) || is_dir($pack . '/' . $rel);
    $gates = [
        ['G-1 Schema Capture', $have('schema/create_tables.sql') && $have('profile/row_counts.csv') && $have('env/fingerprint.json'),
            'D1 + D2 + D11 collected; fingerprint registered; install assigned to a profile'],
        ['G-2 Data Profile', $have('integrity/enum_violations.csv') && $have('integrity/orphans.csv')
            && $have('money/loans_summary.csv') && $have('files/inventory.csv'),
            'D3 + D4 + D7 + D8 + D10 collected; exception counts inside an agreed budget'],
        ['G-3 Contamination Disposition', $have('defects/contamination_register.csv'),
            'D9 collected AND every register row carries a Product-Owner disposition'],
    ];
    $md[] = '## 5. Gate readiness';
    $md[] = '';
    $md[] = '| Gate | Evidence collected | Condition |';
    $md[] = '|---|---|---|';
    foreach ($gates as [$g, $ok, $cond]) {
        $md[] = "| $g | " . ($ok ? 'yes' : '**NO**') . " | $cond |";
    }
    $md[] = '';
    $md[] = 'Collected evidence is necessary, not sufficient: a gate is passed by a person reading it and '
        . 'countersigning, with recorded exceptions. It may not be passed by omission (R-XXIX-032).';
    $md[] = '';

    /* ---- 6. Everything else ------------------------------------------ */
    $md[] = '## 6. Other findings';
    $md[] = '';
    foreach (['MEDIUM', 'LOW', 'INFO'] as $sev) {
        $rows = array_values(array_filter($findings, static fn($x) => $x['severity'] === $sev));
        foreach ($rows as $f) {
            $md[] = '- `' . $sev . '` **' . $f['title'] . '** — ' . str_replace("\n", ' ', (string) $f['detail']);
        }
    }
    $md[] = '';

    /* ---- 7. Collection notes ----------------------------------------- */
    $md[] = '## 7. Collection notes (skipped, failed, absent)';
    $md[] = '';
    if ($notes) {
        $md[] = 'A note is normal: SmartPRS 2 installs differ, and a missing table is data, not an error.';
        $md[] = '';
        $md[] = '| Check | Step | Note |';
        $md[] = '|---|---|---|';
        foreach ($notes as $n) {
            $md[] = '| ' . $n['check'] . ' | ' . $n['step'] . ' | '
                . str_replace('|', '\\|', mb_substr((string) $n['note'], 0, 180)) . ' |';
        }
    } else {
        $md[] = 'None.';
    }
    $md[] = '';

    /* ---- 8. Contents -------------------------------------------------- */
    $md[] = '## 8. Pack contents';
    $md[] = '';
    $md[] = '| Artefact | File(s) | Part XXIX |';
    $md[] = '|---|---|---|';
    foreach ([
        ['Schema capture', 'schema/create_tables.sql, columns.csv, indexes.csv, foreign_keys.csv, provenance.csv, divergent_columns.csv, fingerprint.json', 'D1'],
        ['Row counts and date ranges', 'profile/row_counts.csv, tenant_counts.csv, date_ranges.csv, baseline.json', 'D2'],
        ['Enum violations', 'integrity/enum_violations.csv, enum_observed.csv, enums_declared.csv', 'D3'],
        ['Orphans and duplicates', 'integrity/orphans.csv, duplicates.csv, orphan_samples/, duplicate_samples/', 'D4'],
        ['app_state profile', 'blobs/app_state_inventory.csv, app_state_tenants.csv, app_state_config/, app_state_roles/', 'D5'],
        ['Statutory and policy config', 'blobs/statutory_settings*, late_policy.csv, shifts.csv, app_settings.csv, credential_presence.csv', 'D6'],
        ['Loans and advances', 'money/loans_derived.csv, loans_over_recovered.csv, advances.csv', 'D7'],
        ['Leave derivation', 'leave/derivation.csv, lop_credited_as_paid.csv, lop_overlapping_payslips.csv', 'D8'],
        ['Defect evidence', 'defects/*.csv, defects/contamination_register.csv', 'D9'],
        ['Document storage', 'files/inventory.csv, file_manifest.csv, public_exposure.csv, htaccess.csv', 'D10'],
        ['Environment fingerprint', 'env/fingerprint.json, env/default_accounts.csv', 'D11'],
        ['Chain of custody', 'manifest.json, manifest.sig (when signed)', 'D12'],
    ] as [$a, $f, $d]) {
        $md[] = "| $a | `$f` | $d |";
    }
    $md[] = '';
    $md[] = '---';
    $md[] = '';
    $md[] = 'Handle this pack per `RUNBOOK.md`: it is classified as containing personal data even when '
        . 'redacted, must be encrypted at rest, transported over an authenticated channel, and destroyed '
        . '90 days after the install\'s sign-off (R-XXIX-009).';

    file_put_contents($pack . '/SUMMARY.md', implode("\n", $md) . "\n");
}

/** D12 — manifest with a SHA-256 per file, optionally signed. */
function dsc_build_manifest(string $pack, array $meta = [], ?string $signKey = null): void
{
    $pack = rtrim($pack, "/\\");
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pack, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($pack) + 1));
        if ($rel === 'manifest.json' || $rel === 'manifest.sig') {
            continue;
        }
        $files[$rel] = ['bytes' => (int) $f->getSize(), 'sha256' => hash_file('sha256', $f->getPathname())];
    }
    ksort($files);

    $manifest = [
        'pack' => basename($pack),
        'install' => $meta['install'] ?? null,
        'customer' => $meta['customer'] ?? null,
        'collected_by' => $meta['collected_by'] ?? null,
        'collection_started_utc' => $meta['started'] ?? null,
        'collection_finished_utc' => gmdate('c'),
        'redacted' => $meta['redacted'] ?? true,
        'tool_version' => $meta['tool_version'] ?? 'smartprs-discovery/1.0',
        'schema_fingerprint' => $meta['schema_fingerprint'] ?? null,
        'file_count' => count($files),
        'files' => $files,
    ];
    file_put_contents($pack . '/manifest.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    if ($signKey !== null && is_readable($signKey) && function_exists('openssl_sign')) {
        $key = @openssl_pkey_get_private((string) file_get_contents($signKey));
        $sig = '';
        if ($key && openssl_sign((string) file_get_contents($pack . '/manifest.json'), $sig, $key, OPENSSL_ALGO_SHA256)) {
            file_put_contents($pack . '/manifest.sig', base64_encode($sig) . "\n");
            return;
        }
    }
    if ($signKey !== null) {
        file_put_contents($pack . '/manifest.sig',
            "UNSIGNED — the signing key could not be used on this machine.\n"
            . "R-XXIX-008 requires a signed pack: sign manifest.json before sending it.\n"
            . "  openssl dgst -sha256 -sign ametecs-discovery.key -out manifest.sig manifest.json\n");
    }
}

if (realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__)) {
    $opt = dsc_options($_SERVER['argv']);
    $pack = (string) ($opt['pack'] ?? $opt['out'] ?? '');
    if ($pack === '' || !is_dir($pack)) {
        fwrite(STDERR, "Usage: php checks/summary.php --pack=./discovery-pack-<install>-<date> [--sign-key=PATH]\n");
        exit(2);
    }
    dsc_build_summary($pack);
    dsc_build_manifest($pack, [], isset($opt['sign-key']) ? (string) $opt['sign-key'] : null);
    fwrite(STDERR, "Wrote $pack/SUMMARY.md and manifest.json\n");
}
