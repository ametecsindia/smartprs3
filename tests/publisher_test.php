<?php

declare(strict_types=1);

use SmartPRS\Platform\Config\ConfigVersionRepository;
use SmartPRS\Platform\Config\PublicationRefused;
use SmartPRS\Platform\Config\PublishCheck;
use SmartPRS\Platform\Config\PublishReport;
use SmartPRS\Platform\Config\Publisher;
use SmartPRS\Platform\Persistence\StatusLookup;
use SmartPRS\Platform\Persistence\TenantScopedQuery;
use SmartPRS\Platform\Support\AmbiguousConfiguration;
use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Scope;
use SmartPRS\Platform\Tenancy\TenantContext;

/**
 * The write side of spine S4.
 *
 * PublishGates and Validator are things you CAN call. Publisher is what makes
 * them things that MUST have been called — there is no path to a published
 * version that skips the report, and no refusal that leaves no record.
 */

group('Publisher (spine S4)');

$dsn = getenv('SMARTPRS_TEST_DSN') ?: 'mysql:host=127.0.0.1;charset=utf8mb4';
$user = getenv('SMARTPRS_TEST_USER') ?: 'root';
$password = getenv('SMARTPRS_TEST_PASSWORD') ?: '';
$database = 'smartprs3_publisher_check';

try {
    $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    echo "  ! skipped: no database ({$e->getMessage()})\n";

    return;
}

require_once __DIR__ . '/lib/laravel_schema_stub.php';

Illuminate\Support\Facades\Schema::reset();
$files = glob(__DIR__ . '/../database/migrations/*.php');
sort($files);

foreach ($files as $file) {
    (require $file)->up();
}

$pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
$pdo->exec("CREATE DATABASE `{$database}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$database}`");

foreach (Illuminate\Support\Facades\Schema::$statements as $statement) {
    $pdo->exec($statement);
}

$pdo->exec("INSERT INTO tenants (uuid, code, name, status_code, created_at, updated_at)
            VALUES (UNHEX(REPEAT('a', 32)), 'T1', 'Ametecs', 'active', NOW(), NOW())");
$tenantId = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO status_domains (uuid, domain_key, label, owning_context, created_at, updated_at)
            VALUES (UNHEX(REPEAT('b', 32)), 'config_version_status', 'Config version status', 'configuration', NOW(), NOW())");
$domainId = (int) $pdo->lastInsertId();

$seed = 0;
$insertStatus = $pdo->prepare(
    'INSERT INTO status_values (tenant_id, status_domain_id, uuid, code, label, sort, is_active, created_at, updated_at)
     VALUES (:t, :d, UNHEX(:u), :c, :l, 0, 1, NOW(), NOW())'
);

foreach (['draft', 'testing', 'pending_approval', 'published', 'superseded', 'archived'] as $code) {
    $insertStatus->execute([
        't' => $tenantId,
        'd' => $domainId,
        'u' => str_pad(dechex(++$seed), 32, '0', STR_PAD_LEFT),
        'c' => $code,
        'l' => ucfirst($code),
    ]);
}

$pdo->exec("INSERT INTO config_artefacts
              (tenant_id, uuid, artefact_type, artefact_key, name, owning_context, is_active, created_at, updated_at)
            VALUES ({$tenantId}, UNHEX(REPEAT('c', 32)), 'rule', 'LATE-PENALTY', 'Late penalty', 'attendance', 1, NOW(), NOW())");

$tenant = new TenantContext();
$tenant->establish($tenantId);
$statuses = new StatusLookup($pdo, $tenant);
$versions = new ConfigVersionRepository($pdo, $tenant, $statuses);
$publisher = new Publisher($pdo, $tenant, $statuses, $versions);

$now = new DateTimeImmutable('2026-08-15 09:00:00');

/** A rule payload, minus the ids the publisher fills in. */
function rulePayload(string $description): array
{
    return [
        'decision_point' => 'attendance.day.penalise',
        'module' => 'attendance',
        'business_description' => $description,
        'when_src' => 'attendance.late_minutes > 10m',
        'when_ast' => '{"kind":"binary"}',
        'then_json' => '[]',
        'checksum' => str_repeat('1', 64),
        'catalogue_version' => 1,
        'created_at' => '2026-08-15 09:00:00',
        'updated_at' => '2026-08-15 09:00:00',
    ];
}

function passing(): PublishReport
{
    return new PublishReport('rule:LATE-PENALTY', [
        PublishCheck::pass('G1', 'Parse and budget'),
        PublishCheck::warn('G2', 'Symbols and references', 'NOT CHECKED: no registry supplied'),
        PublishCheck::pass('G3', 'Types'),
    ]);
}

function blocking(): PublishReport
{
    return new PublishReport('rule:LATE-PENALTY', [
        PublishCheck::pass('G1', 'Parse and budget'),
        PublishCheck::block('G6', 'Division by zero',
            'denominator `period.working_days` can be zero. Guard it with MAX(…, 1).'),
        PublishCheck::pass('G3', 'Types'),
    ]);
}

// =============================================================================
// A PASSING REPORT PUBLISHES, AND ITS OUTCOMES ARE STORED
// =============================================================================

$v1 = $publisher->publish(
    'rule',
    'LATE-PENALTY',
    'rule_payloads',
    rulePayload('The tenant default late penalty, applied where nothing more specific governs.'),
    Scope::tenantDefault(),
    EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-04-01')),
    passing(),
    $now,
    audit: ['created_by' => '100', 'reason' => 'initial seed'],
);

ok($v1 > 0, 'a version with a clean report publishes');

$row = TenantScopedQuery::on($pdo, $tenant, 'config_versions')->where('id', '=', $v1)->first();
same(1, (int) $row['version_no'], 'and is v1');
same($statuses->id('config_version_status', 'published'), (int) $row['status_value_id'], 'with status published');
ok((int) $row['payload_id'] > 0, 'pointing at its payload row');
same('rule_payloads', $row['payload_table'], 'and at the table that holds it');

$checks = TenantScopedQuery::on($pdo, $tenant, 'config_publication_checks')
    ->where('config_version_id', '=', $v1)
    ->select();

same(3, count($checks), 'R-VIII-013: one row per gate, whatever it said');
same(
    ['G1', 'G2', 'G3'],
    array_values(array_map(static fn ($c): string => (string) $c['gate_code'], $checks)),
    'including the ones that passed — a passed publication is EVIDENCED, not just allowed',
);

$warn = array_values(array_filter($checks, static fn ($c): bool => $c['severity'] === 'warn'));
ok(
    str_contains((string) $warn[0]['messages_json'], 'NOT CHECKED'),
    'and a NOT CHECKED gate is stored as such, so a later reader knows what was never verified',
);

// =============================================================================
// A BLOCKED PUBLICATION REFUSES — AND LEAVES ITS EVIDENCE BEHIND
// =============================================================================

$refused = null;

try {
    $publisher->publish(
        'rule',
        'LATE-PENALTY',
        'rule_payloads',
        rulePayload('A version that a gate refuses, to prove the refusal is recorded and not merely thrown.'),
        Scope::tenantDefault(),
        EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-09-01')),
        blocking(),
        $now,
    );
} catch (PublicationRefused $e) {
    $refused = $e;
}

ok($refused !== null, 'a blocking gate refuses the publication');
ok(
    str_contains($refused?->getMessage() ?? '', 'MAX(…, 1)'),
    'and the exception carries what to do about it, not just that it failed',
);

$blockedVersion = TenantScopedQuery::on($pdo, $tenant, 'config_versions')
    ->where('version_no', '=', 2)
    ->first(['id', 'status_value_id']);

ok($blockedVersion !== null, 'the refused version row exists');
same(
    $statuses->id('config_version_status', 'draft'),
    (int) $blockedVersion['status_value_id'],
    'as a DRAFT — a crash between reserving and publishing leaves a draft, never a published version '
    . 'pointing at nothing',
);

$blockedChecks = TenantScopedQuery::on($pdo, $tenant, 'config_publication_checks')
    ->where('config_version_id', '=', (int) $blockedVersion['id'])
    ->select();

same(3, count($blockedChecks), 'R-VIII-013: the outcomes are stored even though the publication was refused');
ok(
    in_array('block', array_map(static fn ($c): string => (string) $c['severity'], $blockedChecks), true),
    'so a blocked publication is explainable afterwards, without re-running anything',
);

// The refused version is not a candidate: it never reached published.
same(
    1,
    count($versions->candidates('rule', 'LATE-PENALTY', new DateTimeImmutable('2026-09-15'))),
    'and a refused version resolves for nobody',
);

// =============================================================================
// SUPERSEDING IS SCOPE-AWARE
// =============================================================================

$v3 = $publisher->publish(
    'rule',
    'LATE-PENALTY',
    'rule_payloads',
    rulePayload('The Hyderabad late penalty, which governs that branch and supersedes nothing else.'),
    Scope::of(['branch' => ['HYD']]),
    EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-10-01')),
    passing(),
    $now,
);

$default = TenantScopedQuery::on($pdo, $tenant, 'config_versions')->where('id', '=', $v1)->first();

same(null, $default['effective_to'], 'a Hyderabad version does NOT close the tenant default');
same(
    $statuses->id('config_version_status', 'published'),
    (int) $default['status_value_id'],
    'because they govern different employees, and closing it would leave everyone else with no '
    . 'configuration at all',
);

same(
    2,
    count($versions->candidates('rule', 'LATE-PENALTY', new DateTimeImmutable('2026-10-15'))),
    'so both are in force, and precedence decides between them per employee',
);

// A new version of the SAME scope does close its predecessor.
$v4 = $publisher->publish(
    'rule',
    'LATE-PENALTY',
    'rule_payloads',
    rulePayload('The revised tenant default, superseding the one that governed from April.'),
    Scope::tenantDefault(),
    EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-11-01')),
    passing(),
    $now,
);

$closed = TenantScopedQuery::on($pdo, $tenant, 'config_versions')->where('id', '=', $v1)->first();

same('2026-10-31', substr((string) $closed['effective_to'], 0, 10),
    'the previous version in the same scope closes the day before the new one starts');
same(
    $statuses->id('config_version_status', 'superseded'),
    (int) $closed['status_value_id'],
    'and is marked superseded, not deleted — the version a March payslip cites is still there');
same((string) $v4, (string) $closed['superseded_by_version_id'], 'with a pointer to what replaced it');

// A recalculation of a closed period still resolves what governed it.
same(
    (string) $v1,
    $versions->resolve('rule', 'LATE-PENALTY', ['branch' => 'BLR'], new DateTimeImmutable('2026-05-15'))->versionId,
    'and May still resolves the version that governed May',
);

// =============================================================================
// VERSION NUMBERS ARE ALLOCATED UNDER A LOCK, AND NEVER REUSED
// =============================================================================

$numbers = array_map(
    static fn ($r): int => (int) $r['version_no'],
    TenantScopedQuery::on($pdo, $tenant, 'config_versions')->orderBy('version_no')->select(['version_no']),
);

same($numbers, array_values(array_unique($numbers)), 'no two versions share a version_no');
same([1, 2, 3, 4], $numbers, 'and the refused publication consumed its number rather than leaving a gap to reuse');

// =============================================================================
// WHAT THE PUBLISHER REFUSES OUTRIGHT
// =============================================================================

throws(
    InvalidArgumentException::class,
    static fn () => $publisher->publish(
        'rule',
        'LATE-PENALTY',
        'employees',
        rulePayload('A payload aimed at a table that is not a registered payload table.'),
        Scope::tenantDefault(),
        EffectiveRange::openEndedFrom(new DateTimeImmutable('2027-01-01')),
        passing(),
        $now,
    ),
    'a payload table that is not registered is refused — R-V-050 is a list, not a convention',
);

$pdo->beginTransaction();
throws(
    LogicException::class,
    static fn () => $publisher->publish(
        'rule',
        'LATE-PENALTY',
        'rule_payloads',
        rulePayload('A publication attempted inside a transaction the caller already owns.'),
        Scope::tenantDefault(),
        EffectiveRange::openEndedFrom(new DateTimeImmutable('2027-01-01')),
        blocking(),
        $now,
    ),
    'and a caller-owned transaction is refused: it would roll back the gate outcomes with the refusal',
);
$pdo->rollBack();

throws(
    RuntimeException::class,
    static fn () => $publisher->publish(
        'rule',
        'NO-SUCH-ARTEFACT',
        'rule_payloads',
        rulePayload('A version of an artefact that was never created.'),
        Scope::tenantDefault(),
        EffectiveRange::openEndedFrom(new DateTimeImmutable('2027-01-01')),
        passing(),
        $now,
    ),
    'a version needs something to be a version OF',
);

// =============================================================================
// A FORMULA PUBLISHES THROUGH ITSELF — no hand-mapped payload
// =============================================================================

$pdo->exec("INSERT INTO config_artefacts
              (tenant_id, uuid, artefact_type, artefact_key, name, owning_context, is_active, created_at, updated_at)
            VALUES ({$tenantId}, UNHEX(REPEAT('d', 32)), 'formula', 'F-HRA', 'HRA', 'payroll', 1, NOW(), NOW())");

$formula = new SmartPRS\Platform\Formula\Formula(
    code: 'F-HRA',
    calculationPoint: 'comp.earning',
    module: 'payroll',
    name: 'House rent allowance',
    businessDescription: 'House rent allowance as a declared percentage of basic pay.',
    expression: SmartPRS\Platform\Spx\Compiled::parse(
        "salary.component('BASIC') % policy.value('hra_pct_of_basic')",
        SmartPRS\Platform\Spx\SymbolTable::catalogue(),
    ),
    resultType: SmartPRS\Platform\Spx\Types\Type::money(),
    rounding: SmartPRS\Platform\Formula\Rounding::of('half_up'),
    scope: Scope::tenantDefault(),
    range: EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-04-01')),
    versionNo: 1,
    versionId: 'F-HRA-v1',
    status: 'published',
    unit: 'INR',
    sequenceNo: 7,
    declaredSlices: ['policy', 'structure'],
    sampleTests: ['nominal', 'boundary', 'zero'],
);

$formulaVersion = (new SmartPRS\Platform\Formula\FormulaPublisher($publisher))
    ->publish($formula, $now, audit: ['created_by' => '100']);

$payloadRow = TenantScopedQuery::on($pdo, $tenant, 'formula_payloads')
    ->where('config_version_id', '=', $formulaVersion)
    ->first();

ok($payloadRow !== null, 'a formula publishes through FormulaPublisher, with no hand-built payload');

// Every column that mirrors a field carries THAT field. Hand-mapping is how a
// row says half_down while the object says half_up, and a payslip nobody can
// explain is the result.
same($formula->calculationPoint, $payloadRow['calculation_point'], 'the calculation point comes from the formula');
same($formula->rounding->declaredMode, $payloadRow['rounding_mode'], 'and the rounding mode');
same('0.01', rtrim(rtrim((string) $payloadRow['rounding_unit'], '0'), '.'), 'and the rounding unit');
same($formula->expression->source, $payloadRow['expression_src'], 'and the source');
same($formula->sequenceNo, (int) $payloadRow['sequence_no'], 'and the presentation sequence');
same($formula->checksum(), $payloadRow['checksum'], 'and the checksum the formula computes for itself');

// R-VIII-012: the dependency set in the row is the DERIVED one.
// Keys sorted on both sides: MySQL 8 re-orders a JSON object's keys on storage.
$sortKeys = static fn (array $list): array => array_map(static function (array $d): array { ksort($d); return $d; }, $list);
same(
    $sortKeys([['kind' => 'component', 'code' => 'BASIC'], ['kind' => 'policy', 'code' => 'hra_pct_of_basic']]),
    $sortKeys((array) json_decode((string) $payloadRow['depends_on_json'], true)),
    'and depends_on is what the compiler derived from the AST, not something an author typed',
);

// §4.1: rounding is part of the checksum, because the same expression rounded
// two ways is two different numbers.
$halfDown = new SmartPRS\Platform\Formula\Formula(
    code: 'F-HRA',
    calculationPoint: 'comp.earning',
    module: 'payroll',
    name: 'House rent allowance',
    businessDescription: 'House rent allowance as a declared percentage of basic pay.',
    expression: SmartPRS\Platform\Spx\Compiled::parse(
        "salary.component('BASIC') % policy.value('hra_pct_of_basic')",
        SmartPRS\Platform\Spx\SymbolTable::catalogue(),
    ),
    resultType: SmartPRS\Platform\Spx\Types\Type::money(),
    rounding: SmartPRS\Platform\Formula\Rounding::of('half_down'),
    scope: Scope::tenantDefault(),
    range: EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-04-01')),
    versionNo: 1,
    versionId: 'F-HRA-v1',
    status: 'published',
    unit: 'INR',
    sampleTests: ['a', 'b', 'c'],
);

ok(
    $formula->checksum() !== $halfDown->checksum(),
    'the same expression rounded half_up and half_down have different checksums — rounding is part of '
    . 'what a formula computes, not decoration on it',
);

$pdo->exec("DROP DATABASE `{$database}`");
