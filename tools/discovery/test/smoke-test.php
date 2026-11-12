<?php
/**
 * Smoke test for the discovery toolkit.
 *
 * Loads test/fixture.sql — a miniature SmartPRS 2 install carrying the pathologies
 * Part XXIX describes — runs the whole collector against it, and asserts that each
 * pathology is actually found. If a check silently stops detecting something, this
 * fails.
 *
 *   mysql -u root < test/fixture.sql
 *   php test/smoke-test.php --host=127.0.0.1 --user=ro --pass=ro
 *
 * Needs a throwaway MySQL/MariaDB. Never point it at a customer database.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

$opt = dsc_options($_SERVER['argv']);
$pack = sys_get_temp_dir() . '/smartprs-discovery-smoke';
@exec('rm -rf ' . escapeshellarg($pack));

$args = ['--db=' . ($opt['db'] ?? 'smartprs_fixture'), '--install=smoke', '--out=' . $pack];
foreach (['host', 'port', 'user', 'pass'] as $k) {
    if (isset($opt[$k])) {
        $args[] = "--$k=" . $opt[$k];
    }
}
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../run-discovery.php')
     . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
exec($cmd, $out, $rc);
if ($rc !== 0) {
    fwrite(STDERR, implode("\n", $out) . "\n");
    exit(1);
}

$summary = (string) @file_get_contents($pack . '/SUMMARY.md');
$read = static function (string $rel) use ($pack): string {
    return (string) @file_get_contents($pack . '/' . $rel);
};

$fails = 0;
$check = static function (string $what, bool $ok) use (&$fails): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
    if (!$ok) {
        $fails++;
    }
};

echo "Discovery smoke test — pack at $pack\n\n";

// The pack exists and leads with findings, not dumps.
$check('SUMMARY.md leads with "What changes the migration plan"',
    str_contains($summary, '## 1. What changes the migration plan')
    && strpos($summary, '## 1.') < strpos($summary, '## 8. Pack contents'));
$check('manifest.json lists every file with a SHA-256',
    str_contains($read('manifest.json'), '"sha256"'));

// D1 — schema, provenance, divergence, fingerprint.
$check('D1 captured SHOW CREATE TABLE', str_contains($read('schema/create_tables.sql'), 'CREATE TABLE'));
$check('D1 computed a schema fingerprint', str_contains($read('schema/fingerprint.json'), 'sha256:'));
$check('D1 found runtime-only tables', str_contains($read('schema/provenance.csv'), 'RUNTIME-ONLY'));
$check('D1 flagged employees.dob as VARCHAR (type divergence)',
    (bool) preg_match('/employees,dob,varchar\(255\).*DIVERGENT/', $read('schema/divergent_columns.csv')));
$check('D1 flagged salary_components.calc_value as DECIMAL',
    (bool) preg_match('/salary_components,calc_value,"?decimal.*DIVERGENT/', $read('schema/divergent_columns.csv')));
$check('D1 classified attendance_pending as mixed',
    str_contains($read('schema/fingerprint.json'), '"attendance_pending_shape": "mixed"'));

// D2 — counts.
$check('D2 produced exact counts', str_contains($read('profile/row_counts.csv'), ',exact,'));
$check('D2 recorded a NULL tenant sentinel', str_contains($read('profile/tenant_counts.csv'), 'NULL-sentinel'));
$check('D2 wrote reconciliation baselines', str_contains($read('profile/baseline.json'), '"employees_live"'));

// D3 — enum violations (the blanked-write class).
$check('D3 found blanked enum values', str_contains($read('integrity/enum_violations.csv'), 'blanked-empty-string'));
$check('D3 recorded the declared enum sets', str_contains($read('integrity/enums_declared.csv'), 'enum('));

// D4 — orphans and duplicates.
$check('D4 found the payslip-with-no-employee orphan', (bool) preg_match('/^O1,.*,1,OK/m', $read('integrity/orphans.csv')));
$check('D4 found the payslip-with-no-run orphan', (bool) preg_match('/^O2,.*,1,OK/m', $read('integrity/orphans.csv')));
$check('D4 found unattributable attendance punches', (bool) preg_match('/^O5,/m', $read('integrity/orphans.csv')));
$check('D4 found duplicate PAN', (bool) preg_match('/employees\.pan,1,/', $read('integrity/duplicates.csv')));

// D5 — app_state.
$check('D5 profiled app_state collections', str_contains($read('blobs/app_state_inventory.csv'), 'configuration'));
$check('D5 classified a PII collection as personal-data', str_contains($read('blobs/app_state_inventory.csv'), 'personal-data'));
$check('D5 detected the unparseable blob', str_contains($read('blobs/app_state_inventory.csv'), 'UNPARSEABLE'));
$check('D5 detected a heterogeneous collection', str_contains($read('blobs/app_state_inventory.csv'), 'HETEROGENEOUS'));
$check('D5 extracted the role permission matrix', str_contains($summary, 'role permission matrices in app_state'));
$check('D5 did NOT dump a personal-data collection',
    !is_file($pack . '/blobs/app_state_config/1/employees.json')
    && !is_file($pack . '/blobs/app_state_config/1/complaints.json'));
$check('D5 DID dump a configuration collection', is_file($pack . '/blobs/app_state_config/1/leaveTypes.json'));

// D6 — config.
$check('D6 flagged blob + superseded statutory tables both populated',
    str_contains($summary, 'BOTH the statutory blob and the superseded rate tables'));
$check('D6 found the cross-midnight shift', str_contains($summary, 'look cross-midnight'));
$check('D6 found the dead late-penalty engine', str_contains($summary, 'DEAD IN THE FIELD'));
$check('D6 withheld the plaintext SMTP password',
    str_contains($read('blobs/app_settings.csv'), 'CREDENTIAL WITHHELD')
    && !str_contains($read('blobs/app_settings.csv'), 'S3cretPlainText'));

// D7 — money.
$check('D7 derived loan balances', str_contains($read('money/loans_derived.csv'), 'derived_outstanding'));
$check('D7 found an over-recovered loan', str_contains($summary, 'loans have been OVER-recovered'));
$check('D7 found the never-recovered loan', str_contains($summary, 'outstanding = 0 or NULL'));
$check('D7 found recoveries against a deleted run', str_contains($summary, 'recoveries against a deleted payroll run'));

// D8 — leave.
$check('D8 confirmed type_id is never populated', str_contains($summary, 'leaves.type_id populated on 0'));
$check('D8 found the LOP mispayment population', str_contains($summary, 'were paid in full'));
$check('D8 kept type_name readable under redaction', str_contains($read('leave/lop_credited_as_paid.csv'), 'Loss of Pay'));
$check('D8 redacted the employee identity', !str_contains($read('leave/lop_credited_as_paid.csv'), 'EMP001'));

// D9 — defects.
$check('D9 found fabricated paid payroll runs', str_contains($summary, "marked 'paid' AND carry the fabrication signature"));
$check('D9 found payslips at exactly CTC/12', str_contains($summary, 'net exactly equal to CTC/12'));
$check('D9 found stranded commissions', str_contains($summary, "not in status 'approved'"));
$check('D9 found the audit chain break', str_contains($summary, 'audit rows carry no row_hash'));
$check('D9 found demo attendance', str_contains($summary, "tagged source='demo'"));
$check('D9 found the demo tenant', str_contains($summary, 'match the demo discriminator'));
$check('D9 found archived employees consuming seats', str_contains($summary, 'archived but not deleted'));
$check('D9 found the locked-period correction', str_contains($summary, 'locked payroll period'));
$check('D9 found the backdated salary reduction', str_contains($summary, 'salary reductions produced no recovery'));
$check('D9 wrote the contamination register with an empty disposition column',
    (bool) preg_match('/^id,contamination_class,count,money_at_risk,scope,evidence,disposition$/m',
        $read('defects/contamination_register.csv')));

// D11 — environment.
$check('D11 wrote the environment fingerprint', str_contains($read('env/fingerprint.json'), '"mysql"'));
$check('D11 found the default credential account still using a published password',
    str_contains($read('env/default_accounts.csv'), ',YES'));

// Redaction, globally: no fixture PII may appear anywhere in the pack.
$leaked = [];
foreach (['Asha Rao', 'asha@acme.test', '9000000001', 'ABCDE1111F', 'EMP001', 'S3cretPlainText'] as $needle) {
    $hits = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pack, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && str_contains((string) file_get_contents($f->getPathname()), $needle)) {
            $hits[] = str_replace($pack . '/', '', $f->getPathname());
        }
    }
    if ($hits) {
        $leaked[$needle] = $hits;
    }
}
$check('no fixture PII appears anywhere in a redacted pack'
    . ($leaked ? ' — LEAKED: ' . json_encode($leaked) : ''), $leaked === []);

echo "\n" . ($fails === 0 ? "PASS — all checks green\n" : "FAIL — $fails check(s) failed\n");
exit($fails === 0 ? 0 : 1);
