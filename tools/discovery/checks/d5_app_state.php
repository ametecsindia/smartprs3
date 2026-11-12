<?php
/**
 * D5 — the settings.app_state JSON blob, per tenant.
 *
 * Three analysts independently call this the single highest-risk unknown in the
 * project: ~60 collections in one untyped JSON column, last-write-wins, no version,
 * no history, no schema, and the role permission matrix lives inside it.
 *
 * REDACTION (default): this check writes a STRUCTURAL PROFILE — collection names,
 * element counts, key unions, element shapes, byte sizes, per-collection SHA-256 —
 * and dumps CONTENTS only for collections on the configuration allow-list below.
 * The verbatim blob required by R-XXIX-017 is written only with --unredacted, which
 * requires --i-have-consent. A redacted pack therefore does NOT satisfy R-XXIX-017
 * on its own; the verbatim capture is taken on the customer's own machine and kept
 * there, or taken later under an agreed data-processing basis (see RUNBOOK.md).
 *
 * Read-only. Standalone: php checks/d5_app_state.php --app=/path/to/smartprs2
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

/**
 * Collections known to be CONFIGURATION rather than personal data. Only these are
 * dumped with their contents in a redacted pack. Anything not on this list — and in
 * particular the server-overlaid copies of employees, payroll runs, complaints and
 * archived employees — is profiled structurally only.
 */
const D5_CONFIG_COLLECTIONS = [
    'roles', 'permissions', 'perms', 'leaveTypes', 'leave_types', 'holidays', 'letterTemplates',
    'letters', 'templates', 'pointRules', 'point_rules', 'incentiveSchemes', 'incentive_schemes',
    'salarySetup', 'salary_setup', 'salaryComponents', 'salary_components', 'payCycles', 'pay_cycles',
    'policies', 'notices', 'awards', 'kb', 'kbTopics', 'faqs', 'deductionTypes', 'deduction_types',
    'shifts', 'workweek', 'weeklyOff', 'settings', 'featureFlags', 'feature_flags', 'assetTypes',
    'documentKinds', 'document_kinds', 'statuses', 'menu', 'nav', 'theme', 'branding',
];

/** Collections known to hold personal data — never dumped, profiled only, and called out. */
const D5_PII_COLLECTIONS = [
    'employees', 'archivedEmployees', 'archived_employees', 'candidates', 'recruitment', 'complaints',
    'escalations', 'posh', 'poshComplaints', 'references', 'documents', 'payslips', 'payrollRuns',
    'payroll_runs', 'tdsReturns', 'tds_returns', 'attendance', 'leaves', 'loans', 'advances',
    'commissions', 'clawbacks', 'users', 'contacts',
];

function d5_app_state(DscCtx $c): void
{
    if (!dsc_has_cols($c, 'settings', ['key', 'value'])) {
        $c->note('d5', 'settings table or its key/value columns are absent — app_state cannot be located');
        $c->finding('HIGH', 'app_state', 'No settings.key/value columns on this install',
            'app_state could not be located. Confirm by hand which table holds it before Gate G-1.');
        dsc_finish($c, 'd5_app_state');
        return;
    }

    $hasTenant = dsc_has_cols($c, 'settings', ['tenant_id']);
    $hasUpdated = dsc_has_cols($c, 'settings', ['updated_at']);
    $sel = 'id, ' . ($hasTenant ? 'tenant_id' : 'NULL AS tenant_id')
        . ', `key`, `value`' . ($hasUpdated ? ', updated_at' : ', NULL AS updated_at');

    $rows = dsc_q($c, "SELECT $sel FROM settings WHERE `key` = 'app_state'
                        ORDER BY " . ($hasTenant ? 'tenant_id, id' : 'id'), [], 'd5.1');

    // A duplicate app_state row per tenant is itself a finding: settings has no unique
    // key on the runtime variant, so first() reads an arbitrary one.
    $perTenant = [];
    foreach ($rows as $r) {
        $perTenant[$r['tenant_id'] === null ? 'NULL' : (string) $r['tenant_id']][] = $r;
    }
    foreach ($perTenant as $tid => $set) {
        if (count($set) > 1) {
            $c->finding('BLOCKER', 'app_state', "Tenant $tid has " . count($set) . ' app_state rows',
                'settings has no unique (tenant_id, key) constraint on this install, so the application '
                . 'reads an arbitrary one of these with first(). Which row is live cannot be determined '
                . 'from the database. The customer must identify it before anything is normalised.');
        }
    }

    $inventory = [];
    $tenantSummary = [];

    foreach ($rows as $r) {
        $tid = $r['tenant_id'] === null ? 'NULL' : (string) $r['tenant_id'];
        $raw = (string) ($r['value'] ?? '');
        $bytes = strlen($raw);

        // R-XXIX-017 verbatim capture — only in an unredacted pack. Never re-encoded:
        // re-encoding loses key order, numeric-string coercion and invalid UTF-8, all of
        // which are themselves evidence.
        if (!$c->redact) {
            dsc_write_text($c, "blobs/app_state/{$tid}-{$r['id']}.json", $raw);
        }

        $doc = json_decode($raw, true);
        $parseError = json_last_error() === JSON_ERROR_NONE ? '' : json_last_error_msg();

        $tenantSummary[] = [
            'tenant' => $tid,
            'settings_row_id' => (string) $r['id'],
            'bytes' => $bytes,
            'parses' => is_array($doc) ? 'yes' : 'no',
            'parse_error' => $parseError,
            'top_level_keys' => is_array($doc) ? count($doc) : 0,
            'sha256' => hash('sha256', $raw),
            'updated_at' => (string) ($r['updated_at'] ?? ''),
        ];

        if (!is_array($doc)) {
            $inventory[] = ['tenant' => $tid, 'collection' => '(UNPARSEABLE)', 'type' => '',
                'elements' => 0, 'distinct_shapes' => 0, 'key_union' => '', 'bytes' => $bytes,
                'classification' => 'unparseable', 'sha256' => hash('sha256', $raw),
                'updated_at' => (string) ($r['updated_at'] ?? ''), 'note' => $parseError];
            $c->finding('BLOCKER', 'app_state', "Tenant $tid: app_state does not parse as JSON",
                "$parseError. On installs where settings.value is longText rather than json, a "
                . 'truncated or invalid document can sit there with no error ever raised. R-XXIX-021: '
                . 'no automatic normalisation. This tenant needs a human decision per collection, and '
                . 'the blob must be recovered from a backup if it is truncated.',
                ['tenant' => $tid, 'bytes' => $bytes]);
            continue;
        }

        if ($bytes > 4 * 1024 * 1024) {
            $c->finding('HIGH', 'app_state', "Tenant $tid: app_state is " . round($bytes / 1048576, 1) . ' MB',
                'Every /app/state write rewrites the whole document. At this size, last-write-wins '
                . 'conflicts are near-certain and undetectable (K-… OQ 6).');
        }

        foreach ($doc as $coll => $val) {
            $class = d5_classify((string) $coll);
            $type = gettype($val);
            $elements = is_array($val) ? count($val) : 0;
            $shapes = [];
            $keys = [];
            if (is_array($val)) {
                foreach ($val as $el) {
                    if (!is_array($el)) {
                        continue;
                    }
                    $k = array_keys($el);
                    sort($k);
                    $sig = implode('|', array_map('strval', $k));
                    $shapes[$sig] = ($shapes[$sig] ?? 0) + 1;
                    $keys = array_unique(array_merge($keys, array_map('strval', $k)));
                }
            }
            sort($keys);
            $encoded = json_encode($val, JSON_INVALID_UTF8_SUBSTITUTE);

            $inventory[] = [
                'tenant' => $tid,
                'collection' => (string) $coll,
                'type' => $type . (is_array($val) && !array_is_list($val) ? ' (map)' : ''),
                'elements' => $elements,
                'distinct_shapes' => count($shapes),
                'key_union' => implode('|', $keys),
                'bytes' => strlen((string) $encoded),
                'classification' => $class,
                'sha256' => hash('sha256', (string) $encoded),
                'updated_at' => (string) ($r['updated_at'] ?? ''),
                'note' => count($shapes) > 1 ? 'HETEROGENEOUS: elements do not share one shape' : '',
            ];

            // Contents: configuration collections only, unless the pack is unredacted.
            if ($class === 'configuration' || !$c->redact) {
                dsc_write_text($c, "blobs/app_state_config/{$tid}/" . d5_safe((string) $coll) . '.json',
                    json_encode($val, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
            }

            if (count($shapes) > 1) {
                $c->finding('HIGH', 'app_state', "Tenant $tid / $coll: " . count($shapes) . ' distinct element shapes',
                    'R-XXIX-021: the migration MUST NOT attempt automatic normalisation of a heterogeneous '
                    . 'collection. This raises a blocking exception requiring a per-collection human decision.',
                    ['tenant' => $tid, 'collection' => $coll, 'shapes' => array_keys($shapes)]);
            }
        }

        // The permission matrix: the only existing access-control data in the product.
        $roles = $doc['roles'] ?? $doc['DB']['roles'] ?? null;
        if (is_array($roles)) {
            $matrix = [];
            foreach ($roles as $role) {
                if (!is_array($role)) {
                    continue;
                }
                $perms = $role['perms'] ?? $role['permissions'] ?? [];
                $matrix[] = [
                    'tenant' => $tid,
                    'role' => (string) ($role['name'] ?? $role['title'] ?? $role['id'] ?? '?'),
                    'permission_count' => is_array($perms) ? count($perms, COUNT_RECURSIVE) : 0,
                    'permission_keys' => is_array($perms) ? implode('|', array_map('strval', array_keys($perms))) : '',
                ];
            }
            dsc_write_csv($c, "blobs/app_state_roles/{$tid}.csv", $matrix, 'none');
            if ($matrix) {
                $c->finding('BLOCKER', 'app_state', "Tenant $tid carries " . count($matrix) . ' role permission matrices in app_state',
                    'R-XXIX-020: extract into a legacy_role_permissions staging table and treat as CUSTOMER '
                    . 'INTENT TO BE RE-CONFIRMED, never as configuration to apply silently. SmartPRS 2 does not '
                    . 'enforce these matrices; SmartPRS 3 would. Applying them as-is stops users who work today. '
                    . 'PRODUCT OWNER DECISION D-XXIX-12.',
                    ['tenant' => $tid, 'roles' => count($matrix)]);
            }
        }
    }

    dsc_write_csv($c, 'blobs/app_state_inventory.csv', $inventory, 'none');
    dsc_write_csv($c, 'blobs/app_state_tenants.csv', $tenantSummary, 'none');

    if (!$rows) {
        $c->finding('HIGH', 'app_state', 'No app_state row found for any tenant',
            'Either no tenant has ever loaded the SPA, or the state lives elsewhere on this install. '
            . 'Confirm before concluding there is nothing to migrate: the boot script writes the '
            . 'prototype demo data into app_state on first login, so an empty result here can flip '
            . 'to a full blob the next time somebody signs in.');
    } else {
        $totalCollections = count(array_unique(array_column($inventory, 'collection')));
        $c->finding('BLOCKER', 'app_state',
            count($rows) . ' app_state blobs, ' . $totalCollections . ' distinct collections, '
            . number_format((int) array_sum(array_column($tenantSummary, 'bytes'))) . ' bytes total',
            'R-XXIX-018: every collection needs one of four dispositions (NORMALISE / DUPLICATE-OF-TABLE / '
            . 'PROTOTYPE SEED / DISCARD), decided by the Product Owner with the customer. A collection with '
            . 'no recorded disposition blocks cutover. R-XXIX-019: the NORMALISE-vs-PROTOTYPE-SEED call must '
            . 'be evidenced by diffing against the prototype seed shipped with the revision in env/fingerprint.json — '
            . 'the per-collection sha256 in blobs/app_state_inventory.csv is what that diff runs against.',
            ['blobs' => count($rows), 'collections' => $totalCollections]);
    }

    if ($c->redact) {
        $c->note('d5', 'Redacted pack: verbatim blobs NOT written (R-XXIX-017 capture is deferred). '
            . 'Configuration collections were dumped; personal-data collections were profiled only.');
    }

    dsc_finish($c, 'd5_app_state');
}

function d5_classify(string $collection): string
{
    $n = strtolower($collection);
    foreach (D5_PII_COLLECTIONS as $p) {
        if ($n === strtolower($p)) {
            return 'personal-data';
        }
    }
    foreach (D5_CONFIG_COLLECTIONS as $p) {
        if ($n === strtolower($p)) {
            return 'configuration';
        }
    }
    return 'unclassified';   // profiled only; treated as personal data until classified
}

function d5_safe(string $s): string
{
    return preg_replace('/[^A-Za-z0-9_.-]+/', '_', $s) ?: 'collection';
}

if (realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__)) {
    dsc_standalone('d5_app_state.php', 'd5_app_state');
}
