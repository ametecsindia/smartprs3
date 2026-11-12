<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Persistence;

/**
 * R-XXII-084 / R-XXII-118 — the one audited way to reach across tenants.
 *
 * SmartPRS 2's cross-tenant capability is the *absence* of a value:
 * `when($tid, …)` skips its callback when `$tid` is null or 0, so a null-tenant
 * account, `platform_support`, and any code path that loses the variable all
 * silently read every customer. Here the capability is a positive grant that
 * has to be asked for, is refused unless the call site is declared, records
 * itself BEFORE the block runs, and closes itself in a `finally`.
 *
 * While a grant is open, and only then, TenantScopedQuery::acrossTenants() will
 * build a statement with no tenant predicate. There is no other way to get one.
 *
 * NOT provided, on purpose: a nesting counter, a TTL timer, an async-safe
 * context. This is a synchronous PHP request. The `ttl` of R-XXII-084 is a
 * property of the platform *grant* that authorises the operator, not of this
 * block, and lives with the permission check the caller must already have done.
 */
final class CrossTenantEscape
{
    /**
     * The declared call sites (R-XXII-084: "a CI grep asserts crossTenant()
     * appears only in the declared allow-list"). `Class::method`, exact match.
     *
     * @var list<string>
     */
    public const DECLARED_CALL_SITES = [
        // The scheduler sweep: walks every tenant to enqueue its per-tenant work.
        'SmartPRS\Platform\Scheduler\TenantSweep::run',
        // Platform administration (§XXII.5.5): the tenant list and health board.
        'SmartPRS\Platform\Admin\TenantDirectory::list',
        // Rule-pack installation (§XXII.5.2): writes platform-owned tables only.
        'SmartPRS\Statutory\Application\InstallRulePack::install',
    ];

    private static bool $open = false;

    /** @var \Closure(array{permission: string, reason: string, call_site: string, at: string}): void */
    private readonly \Closure $record;

    /**
     * @param  callable(array{permission: string, reason: string, call_site: string, at: string}): void  $record
     *         the audit sink; there is no default, so it cannot be forgotten
     * @param  list<string>  $allowedCallSites  defaults to the shipped list; the
     *         parameter exists so the suite can prove the refusal path without
     *         adding a test class to the product's allow-list
     */
    public function __construct(
        callable $record,
        private readonly array $allowedCallSites = self::DECLARED_CALL_SITES,
    ) {
        // The sink was a `mixed` property with an is_callable() guard. It is a
        // type declaration now, for the same reason R-VII-036's trace is a
        // constructor argument: a law the compiler enforces cannot be reached
        // by a path nobody read. The refusal moves from a runtime throw to the
        // call boundary, which is earlier and closer to whoever got it wrong.
        $this->record = $record(...);
    }

    /** True only inside run(). Read by TenantScopedQuery::acrossTenants(). */
    public static function isOpen(): bool
    {
        return self::$open;
    }

    /**
     * @template T
     * @param  string  $permission  a `platform.*` permission the caller has already been granted
     * @param  string  $reason      free text, mandatory — a ticket reference or an operator note
     * @param  callable(): T  $work
     * @return T
     */
    public function run(string $permission, string $reason, callable $work): mixed
    {
        if (!str_starts_with($permission, 'platform.')) {
            throw new CrossTenantRefused(
                "Cross-tenant reach requires a platform.* permission; '{$permission}' is not one."
            );
        }

        if (trim($reason) === '') {
            throw new CrossTenantRefused('Cross-tenant reach requires a reason. An empty one is not a reason.');
        }

        $callSite = self::callSite();

        if (!in_array($callSite, $this->allowedCallSites, true)) {
            throw new CrossTenantRefused(
                "{$callSite} is not a declared cross-tenant call site. Add it to "
                . 'CrossTenantEscape::DECLARED_CALL_SITES with a review, or do not cross tenants.'
            );
        }

        if (self::$open) {
            throw new CrossTenantRefused('A cross-tenant grant is already open; nesting hides the reason of the inner one.');
        }

        // Audited BEFORE the block runs, so an escape that then throws is still
        // on the record (R-XXII-084).
        ($this->record)([
            'permission' => $permission,
            'reason' => $reason,
            'call_site' => $callSite,
            'at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ]);

        self::$open = true;

        try {
            return $work();
        } finally {
            self::$open = false;
        }
    }

    /** `Class::method` of whoever called run(). */
    private static function callSite(): string
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = $frames[2] ?? [];

        return isset($caller['class'])
            ? $caller['class'] . '::' . ($caller['function'] ?? '?')
            : ($caller['function'] ?? '{main}');
    }
}
