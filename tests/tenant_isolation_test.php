<?php

declare(strict_types=1);

use SmartPRS\Platform\Access\DataScope;
use SmartPRS\Platform\Access\ScopeResolutionFailed;
use SmartPRS\Platform\Access\SubjectContext;
use SmartPRS\Platform\Persistence\CrossTenantEscape;
use SmartPRS\Platform\Persistence\CrossTenantRefused;
use SmartPRS\Platform\Persistence\IsolationTestGenerator;
use SmartPRS\Platform\Persistence\Repository;
use SmartPRS\Platform\Persistence\TableRegistry;
use SmartPRS\Platform\Persistence\TenancyViolation;
use SmartPRS\Platform\Persistence\TenantScopedQuery;
use SmartPRS\Platform\Tenancy\TenantContext;
use SmartPRS\Platform\Tenancy\TenantNotEstablished;

require_once __DIR__ . '/lib/tenant_fixture.php';

/** A declared cross-tenant call site, so the escape's happy path is exercised. */
final class IsolationSweepCaller
{
    public function __construct(private readonly CrossTenantEscape $escape)
    {
    }

    /** @return list<array<string, mixed>> */
    public function sweep(PDO $pdo, string $table): array
    {
        return $this->escape->run(
            'platform.tenant.list',
            'Support ticket SUP-4471',
            static fn (): array => TenantScopedQuery::acrossTenants($pdo, $table)->select(['id', 'tenant_id']),
        );
    }

    public function renamePlatformRow(PDO $pdo, int $id): int
    {
        return $this->escape->run(
            'platform.rulepack.install',
            'Rule pack IN-2026.1',
            static fn (): int => TenantScopedQuery::acrossTenants($pdo, 'status_domains')
                ->where('id', '=', $id)
                ->update(['label' => 'installed by the rule pack']),
        );
    }

    public function reachTenantTable(PDO $pdo): int
    {
        return $this->escape->run(
            'platform.tenant.list',
            'should be refused',
            static fn (): int => TenantScopedQuery::acrossTenants($pdo, 'status_values')->delete(),
        );
    }
}

// ---------------------------------------------------------------------------

group('TableRegistry');

ok(TableRegistry::isTenantScoped('a_table_nobody_declared'), 'an unknown table is tenant-scoped — the registry fails closed');
ok(TableRegistry::isTenantScoped('installations'), 'installations is tenant-scoped: it carries tenant_id NOT NULL');
ok(!TableRegistry::isTenantScoped('tenants'), 'tenants is platform-scoped');
ok(!TableRegistry::isTenantScoped('status_domains'), 'status_domains is platform-scoped');
same(
    TableRegistry::PLATFORM_TABLES,
    require __DIR__ . '/../tools/gates/allow/platform-tables.php',
    'the build gate and the runtime registry are the same list, not two copies',
);
same(
    ['installations', 'status_values'],
    TableRegistry::tenantScoped(['installations', 'status_domains', 'status_values', 'tenants']),
    'tenantScoped() filters the platform set out',
);

group('CrossTenantEscape');

$audited = [];
$escape = new CrossTenantEscape(
    static function (array $row) use (&$audited): void {
        $audited[] = $row;
    },
    ['IsolationSweepCaller::sweep', 'IsolationSweepCaller::renamePlatformRow', 'IsolationSweepCaller::reachTenantTable'],
);

throws(
    CrossTenantRefused::class,
    static fn () => $escape->run('platform.tenant.list', 'a reason', static fn () => null),
    'an undeclared call site cannot cross tenants',
);
throws(
    CrossTenantRefused::class,
    static fn () => (new CrossTenantEscape(static fn () => null, ['{main}']))
        ->run('tenant.employee.list', 'a reason', static fn () => null),
    'a non-platform permission cannot cross tenants',
);
throws(
    CrossTenantRefused::class,
    static fn () => (new CrossTenantEscape(static fn () => null, ['{main}']))
        ->run('platform.tenant.list', '   ', static fn () => null),
    'an empty reason cannot cross tenants',
);
throws(
    \TypeError::class,
    static fn () => new CrossTenantEscape('not callable'),
    'an escape with no audit sink cannot be constructed — and the refusal is a TypeError rather than '
    . 'a runtime guard, because the sink is a `callable` parameter now instead of a `mixed` property '
    . 'checked with is_callable(). A law the compiler enforces cannot be reached by a path nobody '
    . 'read, which is the same move as R-VII-036 making the trace a constructor argument',
);
ok(!CrossTenantEscape::isOpen(), 'no grant is open by default');

// ---------------------------------------------------------------------------

$fixture = tenant_fixture(getenv('SMARTPRS_TEST_DATABASE_ISOLATION') ?: 'smartprs3_isolation_check');

if ($fixture === null) {
    return;
}

[$pdo, $database, $tenantA, $tenantB] = $fixture;

$contextA = new TenantContext();
$contextA->establish($tenantA);

group('TenantScopedQuery');

same(
    1,
    TenantScopedQuery::on($pdo, $contextA, 'status_values')->count(),
    'count() is scoped too — a dashboard tile cannot aggregate another tenant (R-XXII-124)',
);
same(
    2,
    (int) $pdo->query('SELECT COUNT(*) FROM status_values')->fetchColumn(),
    'the fixture really does hold both tenants\' rows',
);
same(
    $tenantA,
    (string) TenantScopedQuery::on($pdo, $contextA, 'status_values')->first(['tenant_id'])['tenant_id'],
    'first() returns this tenant\'s row',
);
throws(
    TenantNotEstablished::class,
    static fn () => TenantScopedQuery::on($pdo, new TenantContext(), 'status_values')->count(),
    'an aggregate with no tenant throws rather than counting everything',
);
throws(
    TenancyViolation::class,
    static fn () => TenantScopedQuery::on($pdo, $contextA, 'status_values')->update(['tenant_id' => 999]),
    'an update may not move a row to another tenant',
);
throws(
    TenancyViolation::class,
    static fn () => TenantScopedQuery::on($pdo, $contextA, 'status_domains')->update(['label' => 'x']),
    'platform reference data is read-only to a tenant (R-XXII-120)',
);
throws(
    CrossTenantRefused::class,
    static fn () => TenantScopedQuery::acrossTenants($pdo, 'status_values')->select(['id']),
    'acrossTenants() without an open grant is refused',
);
throws(
    \InvalidArgumentException::class,
    static fn () => TenantScopedQuery::on($pdo, $contextA, 'status_values')->where('id) OR 1=1 -- ', '=', 1),
    'an identifier that is not an identifier is refused',
);
same(
    0,
    TenantScopedQuery::on($pdo, $contextA, 'status_values')->whereIn('id', [])->count(),
    'an empty IN list matches nothing — it does not disappear',
);

group('CrossTenantEscape — the granted path');

$caller = new IsolationSweepCaller($escape);
$swept = $caller->sweep($pdo, 'status_values');

same(2, count($swept), 'a granted sweep sees every tenant');
same(
    [$tenantA, $tenantB],
    array_values(array_unique(array_map(static fn (array $r): string => (string) $r['tenant_id'], $swept))),
    'the sweep returned both tenants',
);
same(1, count($audited), 'the escape wrote exactly one audit record');
same('IsolationSweepCaller::sweep', $audited[0]['call_site'] ?? null, 'the audit record names the call site');
same('Support ticket SUP-4471', $audited[0]['reason'] ?? null, 'the audit record carries the reason');
ok(!CrossTenantEscape::isOpen(), 'the grant closed itself');

same(
    1,
    $caller->renamePlatformRow($pdo, 1),
    'platform reference data is writable inside a grant — that is how a rule pack installs',
);
throws(
    TenancyViolation::class,
    static fn () => $caller->reachTenantTable($pdo),
    'a grant does not authorise writing another tenant\'s business rows',
);
ok(!CrossTenantEscape::isOpen(), 'the grant closed itself after the refusal too');

group('Repository');

$rowA = (string) $pdo->query("SELECT id FROM status_values WHERE tenant_id = {$tenantA}")->fetchColumn();

$subject = static fn (?string $actorEmployeeId, ?string $actorTenant = null): SubjectContext => new SubjectContext(
    tenantId: $tenantA,
    actorTenantId: $actorTenant ?? $tenantA,
    actorEmployeeId: $actorEmployeeId,
);

// EMPLOYEE_COLUMN is overridden because `status_values` is the only tenant table
// the foundation has; the People tables a `self` scope really narrows are later.
$repository = static fn (?DataScope $scope, ?SubjectContext $for = null): Repository
    => new class ($pdo, $contextA, $scope, $for) extends Repository {
        protected const EMPLOYEE_COLUMN = 'id';

        protected function table(): string
        {
            return 'status_values';
        }

        /** @return list<array<string, mixed>> */
        public function all(): array
        {
            return $this->query()->select(['id', 'tenant_id']);
        }
    };

same(1, count($repository(null)->all()), 'a repository with no data scope is still tenant-scoped');
same(
    1,
    count($repository(DataScope::tenantWide(), $subject(null))->all()),
    'a tenant-wide scope adds nothing — the tenant predicate already is that scope',
);
same(
    1,
    count($repository(DataScope::selfOnly(), $subject($rowA))->all()),
    'a self scope compiles to a predicate on top of tenancy',
);
same(
    0,
    count($repository(DataScope::selfOnly(), $subject(null))->all()),
    'a self scope with no employee binding is the empty set, never the tenant (R-XXII-042)',
);

throws(
    ScopeResolutionFailed::class,
    static fn () => $repository(DataScope::of('reporting_tree'), $subject($rowA))->all(),
    'a placement scope on a repository that declares no as-at date denies rather than '
    . 'resolving the reporting line from current state (R-XXII-043, R-XXII-094)',
);
throws(
    ScopeResolutionFailed::class,
    static fn () => $repository(DataScope::of('dimension_filter'), $subject($rowA))->all(),
    'and a scope type this build has no compiler for denies rather than running unscoped',
);
throws(
    ScopeResolutionFailed::class,
    static fn () => $repository(DataScope::tenantWide(), $subject(null, '999'))->all(),
    'a tenant-wide scope for another tenant denies',
);
throws(
    CrossTenantRefused::class,
    static fn () => $repository(DataScope::platformWide(), $subject(null))->all(),
    'a platform scope does not widen a repository query — that is what the escape is for',
);
throws(
    \InvalidArgumentException::class,
    static fn () => $repository(DataScope::selfOnly()),
    'a data scope with no subject to evaluate it against is refused at construction',
);

group('Tenant isolation (generated)');

$generator = new IsolationTestGenerator($pdo, $database);
$tables = $generator->tables();

// Every tenant-scoped table, and the count is asserted so that a new one
// cannot arrive without an isolation case arriving with it. R-XXII-138: the
// suite is GENERATED from the schema rather than written per table, so twelve
// new tables in one migration produced twelve new isolation cases with no test
// author involved — which is the only way this stays complete.
// 32 foundation + 85 ported from SmartPRS 2 + 5 the rebuild adds (`enrolment`
// is the sixth and is platform-scoped, so it is not here). Asserted as a number,
// because "some tables were checked" is the shape of a check that stops noticing.
// 147 tenant-scoped tables: the foundation's, everything ported from SmartPRS 2
// — including the 39 it creates at RUNTIME inside controllers — and the ones the
// rebuild adds, less the platform-scoped set. Asserted as a NUMBER, because
// "some tables were checked" is the shape of a check that has stopped noticing,
// and this suite proves write-path isolation on every one of them.
// 150 with `employment_events`, which is tenant-scoped like everything else that
// is about a person — a timeline is one of the most sensitive reads in the
// product (who resigned, when, and why) and the isolation suite proves the write
// path on it like every other table.
// 151 with `salary_component_rules`: a rule says who a component applies to, and
// a rule leaking across tenants is one company's pay structure read from another
// company's screen.
// 152 with `employee_field_values`: what somebody typed about themselves in a
// field their employer added is as much theirs as any column on `employees`.
// 153 with `payslip_formats`: what one company's payslip looks like is not
// another company's business.
// 178 with the 14–16 Oct tenant registers: pf_esi_remittances, tax_declarations, tax_proofs,
// tds_remittances, pt_remittances and the SmartEPT / WhatsApp / punch-receipt tables of 16 Oct.
// 186 with letter_lop, letter_acks (23 Oct) and the six off-roll agent tables (23 Oct): offroll_vendors,
// offroll_pay_plans, offroll_activity, offroll_visits, offroll_payouts, offroll_payout_lines.
same(186, count($tables), 'the generator found every tenant-scoped table in the schema');
ok(!in_array('tenants', $tables, true), 'the generator skips platform-scoped tables');

$results = $generator->run($tenantA, $tenantB);

same(count($tables) * 5, count($results), 'five Law 6 assertions per tenant-scoped table, less the three that cannot be written to');

foreach ($results as $result) {
    ok($result['passed'], "{$result['table']}: {$result['assertion']} — {$result['detail']}");
}

$pdo->exec("DROP DATABASE `{$database}`");
