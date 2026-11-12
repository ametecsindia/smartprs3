<?php
/**
 * SmartPRS 2 → SmartPRS 3 Discovery Pack collector (Part XXIX §XXIX.2, D1–D12).
 *
 * READ-ONLY. Every statement issued is checked against a SELECT/SHOW/EXPLAIN
 * allow-list before it reaches the server. No DDL, no INSERT/UPDATE/DELETE, no
 * long-running transaction, no table lock.
 *
 *   php run-discovery.php --app=/path/to/smartprs2 --install=acme-hq
 *
 * See README.md for everything else. RUNBOOK.md is the on-site procedure.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

const DSC_TOOL_VERSION = 'smartprs-discovery/1.0';

/** Order matters: D10's access-log extract is collected FIRST (R-XXIX-029). */
const DSC_CHECKS = [
    'd10_files' => 'D10  document storage and public exposure (access logs rotate — collected first)',
    'd11_env' => 'D11  environment fingerprint',
    'd1_schema' => 'D1   schema, provenance and fingerprint',
    'd2_counts' => 'D2   row counts, tenant counts, date ranges',
    'd3_enums' => 'D3   enum violation scans',
    'd4_orphans' => 'D4   orphan and duplicate scans',
    'd5_app_state' => 'D5   the app_state blob profile',
    'd6_config_blobs' => 'D6   statutory settings, late policy, shifts, credentials',
    'd7_money' => 'D7   loan and advance reconciliation',
    'd8_leave' => 'D8   leave derivation and the LOP blast radius',
    'd9_defects' => 'D9   evidence for each known live defect',
];

$opt = dsc_options($_SERVER['argv']);
if (isset($opt['help']) || isset($opt['h'])) {
    echo dsc_usage('run-discovery.php'), "\n\nChecks run, in order:\n";
    foreach (DSC_CHECKS as $k => $d) {
        echo "  $d   (checks/$k.php)\n";
    }
    echo "\nRun one on its own with --only=d3_enums, or skip one with --skip=d2_counts.\n";
    exit(0);
}

$started = gmdate('c');
$c = dsc_bootstrap($opt);

$only = isset($opt['only']) ? array_map('trim', explode(',', (string) $opt['only'])) : null;
$skip = isset($opt['skip']) ? array_map('trim', explode(',', (string) $opt['skip'])) : [];

echo "SmartPRS 2 discovery — " . DSC_TOOL_VERSION . "\n";
echo "  install    : {$c->install}\n";
echo "  database   : {$c->db}\n";
echo "  pack       : {$c->out}\n";
echo "  redaction  : " . ($c->redact ? 'ON (default)' : 'OFF — this pack will contain PII') . "\n";
echo "  counts     : " . (($opt['counts'] ?? 'exact')) . ($opt['skip-heavy'] ?? false ? ', skipping heavy tables' : '') . "\n";
echo str_repeat('-', 72), "\n";

$timing = [];
foreach (DSC_CHECKS as $check => $label) {
    if (($only !== null && !in_array($check, $only, true)) || in_array($check, $skip, true)) {
        echo "  SKIP  $label\n";
        continue;
    }
    require_once __DIR__ . '/checks/' . $check . '.php';
    if (!function_exists($check)) {
        fwrite(STDERR, "  FAIL  $check: function not found\n");
        continue;
    }
    $t = microtime(true);
    echo "  run   $label ... ";
    try {
        $check($c);
        $secs = round(microtime(true) - $t, 1);
        $timing[$check] = $secs;
        echo "{$secs}s\n";
    } catch (Throwable $e) {
        $timing[$check] = 'FAILED';
        echo "FAILED\n";
        $c->note($check, 'aborted: ' . $e->getMessage());
        dsc_finish($c, $check);
        fwrite(STDERR, "        " . $e->getMessage() . "\n");
    }
}

// D12 — summary and signed manifest.
require_once __DIR__ . '/checks/summary.php';

$fp = is_file($c->out . '/schema/fingerprint.json')
    ? json_decode((string) file_get_contents($c->out . '/schema/fingerprint.json'), true) : [];

dsc_write_json($c, '_meta/run.json', [
    'tool_version' => DSC_TOOL_VERSION,
    'started_utc' => $started,
    'finished_utc' => gmdate('c'),
    'elapsed_seconds' => $c->elapsed(),
    'php' => PHP_VERSION,
    'options' => array_diff_key($opt, ['pass' => 1, 'dsn' => 1, 'salt' => 1]),
    'check_seconds' => $timing,
]);

dsc_build_summary($c->out, [
    'install' => $c->install,
    'collected_at' => $started,
]);
dsc_build_manifest($c->out, [
    'install' => $c->install,
    'customer' => $opt['customer'] ?? null,
    'collected_by' => $opt['collected-by'] ?? null,
    'started' => $started,
    'redacted' => $c->redact,
    'tool_version' => DSC_TOOL_VERSION,
    'schema_fingerprint' => $fp['schema_fingerprint'] ?? null,
], isset($opt['sign-key']) ? (string) $opt['sign-key'] : null);

echo str_repeat('-', 72), "\n";
$blockers = 0;
$high = 0;
foreach ($c->findings as $f) {
    if ($f['severity'] === 'BLOCKER') {
        $blockers++;
    } elseif ($f['severity'] === 'HIGH') {
        $high++;
    }
}
echo "Done in {$c->elapsed()}s. $blockers blockers, $high high findings, "
    . count($c->notes) . " collection notes.\n";
echo "Read first: {$c->out}" . DIRECTORY_SEPARATOR . "SUMMARY.md\n";
if (!$c->redact) {
    echo "\n*** THIS PACK CONTAINS UNREDACTED PERSONAL DATA. Encrypt it before it leaves the site. ***\n";
}
