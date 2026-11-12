<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use SmartPRS\Platform\Access\DataScope;
use SmartPRS\Platform\Access\ScopeResolutionFailed;
use SmartPRS\Platform\Access\SubjectContext;
use SmartPRS\Platform\Organisation\AssignmentHistory;
use SmartPRS\Platform\Organisation\OrgUnit;
use SmartPRS\Platform\Organisation\OrgUnitRepository;
use SmartPRS\Platform\Organisation\ReportingCycleDetected;
use SmartPRS\Platform\Organisation\ReportingLine;
use SmartPRS\Platform\Persistence\AsAtDateRequired;
use SmartPRS\Platform\Persistence\EffectiveDatedRepository;
use SmartPRS\Platform\Persistence\Repository;
use SmartPRS\Platform\Persistence\TenantScopedQuery;
use SmartPRS\Platform\Tenancy\TenantContext;

/**
 * Part V §V.6 against a real MariaDB — the effective-dated employment model, the
 * as-at read layer, and the six data scopes that could not be compiled before it
 * existed.
 *
 * Everything here executes SQL. The overlap assertion in particular is on the
 * DATABASE's refusal, not on an application check: the whole claim of V.6.4 is
 * that "at most one open row" is a constraint, and only a rejected INSERT
 * demonstrates that.
 */

group('Organisation');

$dsn = getenv('SMARTPRS_TEST_DSN') ?: 'mysql:host=127.0.0.1;charset=utf8mb4';
$user = getenv('SMARTPRS_TEST_USER') ?: 'root';
$password = getenv('SMARTPRS_TEST_PASSWORD') ?: '';
$database = getenv('SMARTPRS_TEST_DATABASE_ORGANISATION') ?: 'smartprs3_organisation_check';

try {
    $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    echo "  ! organisation tests skipped: no database ({$e->getMessage()})\n";

    return;
}

require_once __DIR__ . '/lib/laravel_schema_stub.php';

Schema::reset();

foreach (($files = glob(__DIR__ . '/../database/migrations/*.php')) === false ? [] : $files as $file) {
    (require $file)->up();
}

$pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
$pdo->exec("CREATE DATABASE `{$database}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$database}`");

foreach (Schema::$statements as $statement) {
    $pdo->exec($statement);
}

// ---------------------------------------------------------------------------
// Fixture. Two tenants, one org tree each, and a deliberate reporting cycle.
// Every tenant row goes in through TenantScopedQuery, so the fixture itself
// would fail if the layer stopped stamping the tenant (R-XXII-083).
// ---------------------------------------------------------------------------

$pdo->exec("INSERT INTO tenants (uuid, code, name, status_code) VALUES ('a', 'alpha', 'Alpha', 'active')");
$pdo->exec("INSERT INTO tenants (uuid, code, name, status_code) VALUES ('b', 'bravo', 'Bravo', 'active')");
$tenants = array_column($pdo->query('SELECT id FROM tenants ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'id');
/** @var string $tenantA */
/** @var string $tenantB */
[$tenantA, $tenantB] = [(string) $tenants[0], (string) $tenants[1]];

$context = static function (string $tenantId): TenantContext {
    $c = new TenantContext();
    $c->establish($tenantId);

    return $c;
};

$contextA = $context($tenantA);
$contextB = $context($tenantB);

$uuid = static fn (string $seed): string => substr(md5($seed), 0, 16);

/** An org unit type, then units hung off each other with a real materialised path. */
$typeId = static function (PDO $pdo, TenantContext $tenant, string $code, int $order) use ($uuid): string {
    return TenantScopedQuery::on($pdo, $tenant, 'org_unit_types')->insert([
        'uuid' => $uuid($code . $tenant->tenantId()),
        'code' => $code,
        'label' => ucfirst($code),
        'level_order' => $order,
    ]);
};

$unit = static function (
    PDO $pdo,
    TenantContext $tenant,
    string $typeId,
    string $code,
    ?array $parent,
) use ($uuid): array {
    $id = TenantScopedQuery::on($pdo, $tenant, 'org_units')->insert([
        'org_unit_type_id' => $typeId,
        'parent_id' => $parent['id'] ?? null,
        'uuid' => $uuid($code . $tenant->tenantId()),
        'code' => $code,
        'name' => $code,
        'path' => 'PLACEHOLDER',
        'depth' => $parent === null ? 0 : $parent['depth'] + 1,
    ]);

    // The write service maintains `path` in the same transaction as `parent_id`;
    // here the same rule, applied by hand.
    $path = ($parent['path'] ?? '/') . $id . '/';
    TenantScopedQuery::on($pdo, $tenant, 'org_units')->where('id', '=', $id)->update(['path' => $path]);

    return ['id' => $id, 'path' => $path, 'depth' => $parent === null ? 0 : $parent['depth'] + 1];
};

// Tenant A's tree:
//   LE1 → CO1 → BR-HYD → DEP-COLL → TEAM-B
//             → BR-CHN
//             → BR-SOLO   (a branch with no children at all)
$leType = $typeId($pdo, $contextA, 'legal_entity', 10);
$coType = $typeId($pdo, $contextA, 'company', 20);
$brType = $typeId($pdo, $contextA, 'branch', 30);
$deType = $typeId($pdo, $contextA, 'department', 40);
$teType = $typeId($pdo, $contextA, 'team', 50);

$le1 = $unit($pdo, $contextA, $leType, 'LE1', null);
$co1 = $unit($pdo, $contextA, $coType, 'CO1', $le1);
$hyd = $unit($pdo, $contextA, $brType, 'BR-HYD', $co1);
$chn = $unit($pdo, $contextA, $brType, 'BR-CHN', $co1);
$solo = $unit($pdo, $contextA, $brType, 'BR-SOLO', $co1);
$coll = $unit($pdo, $contextA, $deType, 'DEP-COLL', $hyd);
$teamB = $unit($pdo, $contextA, $teType, 'TEAM-B', $coll);

// Tenant B's tree, so a cross-tenant assertion has something to fail against.
$leTypeB = $typeId($pdo, $contextB, 'legal_entity', 10);
$brTypeB = $typeId($pdo, $contextB, 'branch', 30);
$leB = $unit($pdo, $contextB, $leTypeB, 'LE1', null);
$hydB = $unit($pdo, $contextB, $brTypeB, 'BR-HYD', $leB);

$employment = static function (
    PDO $pdo,
    TenantContext $tenant,
    array $legalEntity,
    string $code,
) use ($uuid): string {
    $personId = TenantScopedQuery::on($pdo, $tenant, 'people')->insert([
        'uuid' => $uuid('p' . $code . $tenant->tenantId()),
        'display_name' => $code,
    ]);

    return TenantScopedQuery::on($pdo, $tenant, 'employments')->insert([
        'person_id' => $personId,
        'legal_entity_org_unit_id' => $legalEntity['id'],
        'uuid' => $uuid('e' . $code . $tenant->tenantId()),
        'employee_code' => $code,
        'joined_on' => '2026-01-01',
    ]);
};

$assign = static function (
    PDO $pdo,
    TenantContext $tenant,
    string $employmentId,
    string $from,
    ?string $to,
    array $row,
) use ($uuid): string {
    return TenantScopedQuery::on($pdo, $tenant, 'employment_assignments')->insert([
        'employment_id' => $employmentId,
        'uuid' => $uuid($employmentId . $from . $to . $tenant->tenantId()),
        'effective_from' => $from,
        'effective_to' => $to,
        'source' => 'hire',
    ] + $row);
};

// The reporting tree of tenant A:
//   M  → D1 → G1 → GG1        (depths 1, 2, 3)
//      → D2
//   OUT reports to nobody, and is in BR-CHN.
//   CYC1 ⇄ CYC2 is a real two-employment loop, off M's line.
$m = $employment($pdo, $contextA, $le1, 'M');
$d1 = $employment($pdo, $contextA, $le1, 'D1');
$d2 = $employment($pdo, $contextA, $le1, 'D2');
$g1 = $employment($pdo, $contextA, $le1, 'G1');
$gg1 = $employment($pdo, $contextA, $le1, 'GG1');
$out = $employment($pdo, $contextA, $le1, 'OUT');
$cyc1 = $employment($pdo, $contextA, $le1, 'CYC1');
$cyc2 = $employment($pdo, $contextA, $le1, 'CYC2');
$mover = $employment($pdo, $contextA, $le1, 'MOVER');

$inHyd = [
    'legal_entity_org_unit_id' => $le1['id'],
    'company_org_unit_id' => $co1['id'],
    'branch_org_unit_id' => $hyd['id'],
    'department_org_unit_id' => $coll['id'],
    'team_org_unit_id' => $teamB['id'],
];

$assign($pdo, $contextA, $m, '2026-01-01', null, $inHyd);
$assign($pdo, $contextA, $d1, '2026-01-01', null, $inHyd + ['reporting_manager_employment_id' => $m]);
$assign($pdo, $contextA, $d2, '2026-01-01', null, $inHyd + ['reporting_manager_employment_id' => $m]);
$assign($pdo, $contextA, $g1, '2026-01-01', null, $inHyd + ['reporting_manager_employment_id' => $d1]);
$assign($pdo, $contextA, $gg1, '2026-01-01', null, $inHyd + ['reporting_manager_employment_id' => $g1]);
$assign($pdo, $contextA, $out, '2026-01-01', null, [
    'legal_entity_org_unit_id' => $le1['id'],
    'company_org_unit_id' => $co1['id'],
    'branch_org_unit_id' => $chn['id'],
]);
$assign($pdo, $contextA, $cyc1, '2026-01-01', null, $inHyd + ['reporting_manager_employment_id' => $cyc2]);
$assign($pdo, $contextA, $cyc2, '2026-01-01', null, $inHyd + ['reporting_manager_employment_id' => $cyc1]);

// MOVER is the employment the branch question is asked about: three dated rows,
// abutting exactly, the last one open.
$assign($pdo, $contextA, $mover, '2026-01-01', '2026-04-30', $inHyd);
$assign($pdo, $contextA, $mover, '2026-05-01', '2026-05-31', [
    'legal_entity_org_unit_id' => $le1['id'],
    'company_org_unit_id' => $co1['id'],
    'branch_org_unit_id' => $chn['id'],
]);
$assign($pdo, $contextA, $mover, '2026-06-01', null, [
    'legal_entity_org_unit_id' => $le1['id'],
    'company_org_unit_id' => $co1['id'],
    'branch_org_unit_id' => $solo['id'],
]);

// Tenant B: its own manager and report, with the same shape.
$mB = $employment($pdo, $contextB, $leB, 'M');
$rB = $employment($pdo, $contextB, $leB, 'R');
$assign($pdo, $contextB, $mB, '2026-01-01', null, ['branch_org_unit_id' => $hydB['id']]);
$assign($pdo, $contextB, $rB, '2026-01-01', null, [
    'branch_org_unit_id' => $hydB['id'],
    'reporting_manager_employment_id' => $mB,
]);

$on = static fn (string $date): DateTimeImmutable
    => new DateTimeImmutable($date, new DateTimeZone('UTC'));

// ---------------------------------------------------------------------------

group('OrgUnit');

$units = new OrgUnitRepository($pdo, $contextA);

$teamUnit = $units->find($teamB['id']);
ok($teamUnit instanceof OrgUnit, 'a unit is found by id, inside its tenant');
same(
    [$le1['id'], $co1['id'], $hyd['id'], $coll['id']],
    $teamUnit?->ancestorIds(),
    'ancestors come off the materialised path, root first, self excluded',
);
ok($teamUnit?->isWithin($hyd['id']) === true, 'a team is within its branch');
ok($teamUnit?->isWithin($teamB['id']) === true, 'a unit is within itself — every scope type assumes it');
ok($teamUnit?->isWithin($chn['id']) === false, 'and is not within a sibling branch');

same(
    [$le1['id'], $co1['id'], $hyd['id'], $coll['id']],
    array_map(static fn (OrgUnit $u): string => $u->id, $units->ancestorsOf($teamB['id'])),
    'ancestorsOf() hydrates the same chain, depth ordered',
);
same(
    [$coll['id'], $teamB['id']],
    array_map(static fn (OrgUnit $u): string => $u->id, $units->descendantsOf($hyd['id'])),
    'descendantsOf() walks the subtree by path prefix, the unit itself excluded',
);
same([], $units->descendantsOf($solo['id']), 'a unit with no children has no descendants');
same(null, $units->find($hydB['id']), "another tenant's unit does not exist here");
throws(
    InvalidArgumentException::class,
    static fn () => new OrgUnit('1', null, '1/2', 0, 'X', 'X'),
    'a path without both delimiters is refused — a prefix must not match half an id',
);

// ---------------------------------------------------------------------------

group('AssignmentHistory');

$history = new AssignmentHistory($pdo, $contextA);
$branchOn = static fn (string $date): ?string
    => $history->dimensionOn($mover, 'branch_org_unit_id', $on($date));

// "What was this employee's branch on 15 May?" — V.6.3.
same($hyd['id'], $branchOn('2026-03-15'), 'in March the branch is Hyderabad');
same($hyd['id'], $branchOn('2026-04-30'), 'on the last day of the first row it is still Hyderabad (boundary)');
same($chn['id'], $branchOn('2026-05-01'), 'on the first day of the second row it is Chennai (boundary)');
same($chn['id'], $branchOn('2026-05-15'), 'ON 15 MAY IT IS CHENNAI — the question SmartPRS 2 cannot answer');
same($chn['id'], $branchOn('2026-05-31'), 'on the last day of the second row it is still Chennai (boundary)');
same($solo['id'], $branchOn('2026-06-01'), 'on the first day of the open row it is BR-SOLO (boundary)');
same($solo['id'], $branchOn('2030-01-01'), 'and an open row stays in force for every later date');
same(null, $branchOn('2025-12-31'), 'before the first row there is no assignment, and NULL is the answer');

same(3, count($history->historyOf($mover)), 'the audit view returns every version, not one');
same(
    ['2026-01-01', '2026-05-01', '2026-06-01'],
    array_column($history->historyOf($mover), 'effective_from'),
    'the audit view is ordered oldest first',
);
same(null, $history->contiguityBreach($mover), 'the three rows tile the timeline with no gap and no overlap');
same(1, count($history->historyOf($m)), 'an employment that never moved has one row');

throws(
    InvalidArgumentException::class,
    static fn () => $history->dimensionOn($mover, 'salary', $on('2026-05-15')),
    'a dimension that is not a column is a programming error, not a NULL',
);

// A row that starts in the future is invisible until its own effective date.
// The predecessor is closed first — the open-row constraint enforces that order,
// which is exactly V.6.4 mechanism 3 showing up as a database error if you skip it.
$pdo->prepare('UPDATE employment_assignments SET effective_to = "2026-12-31"
               WHERE employment_id = ? AND effective_from = "2026-01-01"')->execute([$d2]);
$assign($pdo, $contextA, $d2, '2027-01-01', null, [
    'legal_entity_org_unit_id' => $le1['id'],
    'branch_org_unit_id' => $chn['id'],
    'reporting_manager_employment_id' => $m,
]);

same($hyd['id'], $history->dimensionOn($d2, 'branch_org_unit_id', $on('2026-12-31')),
    'a future-dated assignment is invisible the day before it takes effect');
same($chn['id'], $history->dimensionOn($d2, 'branch_org_unit_id', $on('2027-01-01')),
    'and is in force on the day itself — no job applies it, the calendar does');

// ---------------------------------------------------------------------------

group('The open-row constraint (V.6.4)');

// The database refuses a second OPEN row for one employment. Asserted on the
// constraint violation, not on any application check.
$violation = null;

try {
    $assign($pdo, $contextA, $m, '2026-07-01', null, ['branch_org_unit_id' => $chn['id']]);
} catch (PDOException $e) {
    $violation = $e;
}

ok($violation instanceof PDOException, 'a second open assignment row is refused by the database');
same('23000', $violation?->getCode(), 'the refusal is an integrity-constraint violation, not an application error');
ok(
    str_contains((string) $violation?->getMessage(), 'employment_assignments_one_open_unique'),
    'and it names the open-marker unique index: ' . substr((string) $violation?->getMessage(), 0, 120),
);

$duplicateStart = null;

try {
    $assign($pdo, $contextA, $mover, '2026-05-01', '2026-05-15', ['branch_org_unit_id' => $chn['id']]);
} catch (PDOException $e) {
    $duplicateStart = $e;
}

ok($duplicateStart instanceof PDOException, 'a second row starting on a day already claimed is refused');
ok(
    str_contains((string) $duplicateStart?->getMessage(), 'employment_assignments_start_unique'),
    'and it names the duplicate-start unique index',
);

// The honest limit, asserted so nobody believes more than is true: two CLOSED
// rows can still overlap, and only the contiguity assertion catches it.
$assign($pdo, $contextA, $out, '2026-02-01', '2026-03-31', ['branch_org_unit_id' => $hyd['id']]);
ok(
    $history->contiguityBreach($out) !== null,
    'two closed rows CAN overlap — MySQL has no exclusion constraint, so V.6.4 mechanism 4 '
    . 'catches it: ' . (string) $history->contiguityBreach($out),
);
$pdo->prepare('DELETE FROM employment_assignments WHERE employment_id = ? AND effective_from = "2026-02-01"')
    ->execute([$out]);
same(null, $history->contiguityBreach($out), 'and the fixture is put back');

// ---------------------------------------------------------------------------

group('EffectiveDatedRepository');

$dated = new class ($pdo, $contextA) extends EffectiveDatedRepository {
    protected function table(): string
    {
        return 'employment_assignments';
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->query()->select(['id', 'employment_id']);
    }
};

throws(
    AsAtDateRequired::class,
    static fn () => $dated->all(),
    'a read with no as-at date throws — there is no silent "today" (spine S4)',
);
throws(
    AsAtDateRequired::class,
    static fn () => $dated->asAt(),
    'and asking the view for its date throws too, rather than inventing one',
);
ok($dated->asOf($on('2026-05-15'))->all() !== [], 'given a date, the same repository reads');
same(
    '2026-05-15',
    $dated->asOf($on('2026-05-15'))->asAt()->format('Y-m-d'),
    'the view remembers the date it was given',
);
throws(
    AsAtDateRequired::class,
    static fn () => $dated->all(),
    'and asOf() returns a view rather than mutating — the original still refuses',
);

// ---------------------------------------------------------------------------

group('ReportingLine');

$line = new ReportingLine($pdo, $contextA);
$may = $on('2026-05-15');

same([$d1, $d2], $line->directReportsOf($m, $may), 'direct reports are the level below, and only that');
same([], $line->directReportsOf($gg1, $may), 'a leaf has no reports');
same([$d1, $d2], $line->reportingTreeOf($m, 1, $may), 'depth 1 is the direct reports');
same([$d1, $d2, $g1], $line->reportingTreeOf($m, 2, $may), 'depth 2 adds the next level');
same([$d1, $d2, $g1, $gg1], $line->reportingTreeOf($m, 3, $may), 'depth 3 adds the one after that');
same([$d1, $d2, $g1, $gg1], $line->reportingTreeOf($m, null, $may), 'an unbounded tree is bounded at the platform maximum');
ok(!in_array($out, $line->reportingTreeOf($m, null, $may), true), 'a manager reaches nobody outside their line');
same([], $line->reportingTreeOf($out, null, $may), 'and somebody with no reports reaches nobody at all');

throws(
    InvalidArgumentException::class,
    static fn () => $line->reportingTreeOf($m, DataScope::MAX_REPORTING_DEPTH + 1, $may),
    'a depth beyond access.reporting_tree.max_depth is refused (R-XXII-044)',
);

// The cycle. CYC1 reports to CYC2 and CYC2 reports to CYC1; the walk must raise,
// not spin.
same([$cyc2], $line->directReportsOf($cyc1, $may), 'one level of a cycle is not yet a cycle');
throws(
    ReportingCycleDetected::class,
    static fn () => $line->reportingTreeOf($cyc1, null, $may),
    'A REPORTING CYCLE RAISES ReportingCycleDetected rather than looping (R-XXII-044)',
);

// Cross-tenant: tenant B's manager resolves nothing inside tenant A.
same([], $line->directReportsOf($mB, $may), "tenant B's manager has no reports inside tenant A");
same([$rB], (new ReportingLine($pdo, $contextB))->directReportsOf($mB, $may), 'and has one inside tenant B');

// The line is dated: before D1's assignment starts, D1 is nobody's report.
same([], $line->directReportsOf($m, $on('2025-06-01')), 'the reporting line did not exist before the assignments did');

// ---------------------------------------------------------------------------

group('Compiled data scopes');

$subject = static fn (?string $actorEmployeeId, string $actorTenant = null): SubjectContext
    => new SubjectContext(
        tenantId: $tenantA,
        actorTenantId: $actorTenant ?? $tenantA,
        actorEmployeeId: $actorEmployeeId,
    );

/** A repository over `employments`, one row per person, scoped and dated. */
$people = static function (?DataScope $scope, ?SubjectContext $for, string $date = '2026-05-15') use ($pdo, $contextA): Repository {
    return new class ($pdo, $contextA, $scope, $for, $date) extends Repository {
        protected const EMPLOYEE_COLUMN = 'id';

        public function __construct(
            PDO $pdo,
            TenantContext $tenant,
            ?DataScope $scope,
            ?SubjectContext $subject,
            private readonly string $date,
        ) {
            parent::__construct($pdo, $tenant, $scope, $subject);
        }

        protected function table(): string
        {
            return 'employments';
        }

        protected function scopeAsAt(): ?DateTimeImmutable
        {
            return new DateTimeImmutable($this->date, new DateTimeZone('UTC'));
        }

        /** @return list<string> */
        public function codes(): array
        {
            return array_column($this->query()->orderBy('employee_code')->select(['employee_code']), 'employee_code');
        }
    };
};

same(
    ['D1', 'D2'],
    $people(DataScope::of('direct_reports'), $subject($m))->codes(),
    'direct_reports compiles to a predicate that returns exactly the level below',
);
same(
    ['D1', 'D2', 'G1'],
    $people(DataScope::of('reporting_tree', depth: 2), $subject($m))->codes(),
    'reporting_tree honours its declared depth',
);
same(
    ['D1', 'D2', 'G1', 'GG1', 'M'],
    $people(DataScope::of('reporting_tree', depth: 3, includeSelf: true), $subject($m))->codes(),
    'include_self adds the manager, and only when asked',
);
ok(
    !in_array('OUT', $people(DataScope::of('reporting_tree'), $subject($m))->codes(), true),
    'a manager sees nobody outside their line',
);
same(
    [],
    $people(DataScope::of('direct_reports'), $subject(null))->codes(),
    'an actor with no employee binding reaches nobody (R-XXII-042)',
);
same(
    [],
    $people(DataScope::of('direct_reports'), $subject($mB))->codes(),
    "a manager from tenant B resolves no reports inside tenant A",
);
throws(
    ReportingCycleDetected::class,
    static fn () => $people(DataScope::of('reporting_tree'), $subject($cyc1))->codes(),
    'and a cyclic actor denies at the repository too, rather than hanging the request',
);

// Org-unit scopes.
same(
    ['CYC1', 'CYC2', 'D1', 'D2', 'G1', 'GG1', 'M'],
    $people(DataScope::of('branch', [$hyd['id']]), $subject($m))->codes(),
    'a branch scope with descendants reaches everybody at or under the branch',
);
same(
    ['MOVER', 'OUT'],
    $people(DataScope::of('branch', [$chn['id']]), $subject($m))->codes(),
    'and the sibling branch reaches its own — MOVER included, because in May that is where MOVER is',
);
same(
    ['CYC1', 'CYC2', 'D1', 'D2', 'G1', 'GG1', 'M', 'MOVER'],
    $people(DataScope::of('branch', [$hyd['id']]), $subject($m), '2026-03-15')->codes(),
    'and in March, before the transfer, the same scope reaches MOVER instead',
);
same(
    [],
    $people(DataScope::of('branch', [$solo['id']]), $subject($m))->codes(),
    'a unit with no children and no members reaches nobody — never everybody',
);
same(
    ['MOVER'],
    $people(DataScope::of('branch', [$solo['id']]), $subject($m), '2026-06-15')->codes(),
    'and reaches MOVER once the transfer into it takes effect — the scope is dated too',
);
same(
    ['CYC1', 'CYC2', 'D1', 'D2', 'G1', 'GG1', 'M'],
    $people(DataScope::of('team', [$teamB['id']]), $subject($m))->codes(),
    'a team scope matches the team column exactly (include_descendants defaults false)',
);
same(
    ['MOVER', 'OUT'],
    $people(DataScope::of('unit_list', [$chn['id']], includeDescendants: false), $subject($m))->codes(),
    'a unit_list scope matches any dimension, which is what mixing types means',
);
same(
    [],
    $people(DataScope::of('branch', [$hydB['id']]), $subject($m))->codes(),
    "a scope naming another tenant's unit is the empty set, never unfiltered",
);
same(
    9,
    count($people(DataScope::of('legal_entity', [$le1['id']]), $subject($m))->codes()),
    'a legal-entity scope reaches the whole entity',
);

// The as-at date is part of the scope, and a repository without one denies.
$undated = new class ($pdo, $contextA, DataScope::of('branch', [$hyd['id']]), $subject($m)) extends Repository {
    protected const EMPLOYEE_COLUMN = 'id';

    protected function table(): string
    {
        return 'employments';
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->query()->select(['id']);
    }
};

throws(
    ScopeResolutionFailed::class,
    static fn () => $undated->all(),
    'a placement scope on a repository with no as-at date denies rather than using today',
);

$pdo->exec("DROP DATABASE `{$database}`");
