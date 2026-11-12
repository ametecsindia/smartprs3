<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Persistence;

/**
 * Spine Law 6 / R-XXII-120 — every table is in exactly one class: tenant-scoped
 * or platform-scoped. This declares the platform class; everything else is
 * tenant-scoped by omission.
 *
 * FAIL CLOSED. A table nobody has thought about is tenant-scoped, so the query
 * builder puts `tenant_id = ?` on it and a missing tenant column is a loud SQL
 * error at the first query. The opposite default — "unknown means unscoped" —
 * is precisely SmartPRS 2's behaviour, where a scope filtering a non-existent
 * column made eight models global.
 *
 * This constant is also the gate's allow-list: tools/gates/allow/platform-tables.php
 * returns it, so the runtime rule and the build rule cannot drift apart.
 */
final class TableRegistry
{
    /**
     * The platform-scoped set. Adding a table here asserts "no tenant owns a
     * row in this table". Keep it short, keep every entry commented.
     *
     * @var list<string>
     */
    public const PLATFORM_TABLES = [
        // The tenant table defines tenants; it cannot be scoped by one.
        'tenants',
        // NOTE: `installations` is deliberately absent. Its migration carries
        // `tenant_id NOT NULL` — an installation belongs to exactly one tenant.
        'licences',
        'releases',

        // Ported from SmartPRS 2. The SaaS catalogue: a plan and a payment
        // gateway are offered BY the platform TO tenants, so no tenant owns a
        // row. Declared here rather than given a tenant_id nobody could fill in.
        'plans',
        'payment_gateways',

        // The one-time enrolment token creates the FIRST account, which
        // necessarily happens before there is a tenant to scope it by. Declared
        // here rather than given a tenant_id nobody could fill in.
        'enrolment',

        // Recovered from SmartPRS 2's RUNTIME-created tables. Licensing, releases,
        // signups and the coupon/lead pipeline are all platform-side: a tenant
        // does not own the licence issued to it, and a signup exists before the
        // tenant does.
        'licences',
        'licence_devices',
        'licence_events',
        'releases',
        'release_grants',
        'client_updates',
        'onprem_clients',
        'onprem_payments',
        'signups',
        'leads',
        'demo_requests',
        // 16 Oct 2026, Super Admin console: its own logins, the platform mailbox / WhatsApp settings belong
        // to the platform, never to a tenant. (wa_templates / wa_log carry tenant_id, so they stay tenant-scoped.)
        'platform_users',
        'platform_settings',
        'coupons',
        'coupon_redemptions',
        // A quotation is priced BEFORE the tenant exists; a feature flag row is
        // the platform's setting FOR a tenant (`tenant_ref`, nullable = global),
        // not a row the tenant owns — the same shape as a licence's client_id.
        'quotations',
        'feature_flags',
        'renewals',
        'subscription_alerts',
        'bank_invoices',

        'rule_packs',
        'rule_pack_versions',

        // Status vocabulary NAMESPACES (spine S16, "no database ENUM types").
        // The domain — `payroll_run_status` — is platform-owned; the values
        // inside it live in `status_values`, which is tenant-scoped.
        'status_domains',

        // The sweep runs on the tenant-independent platform schedule (§IX.7.6),
        // and a heartbeat a tenant owned would go missing exactly when that
        // tenant's own data was the problem. R-IX-067 reads this to decide
        // whether SLA processing is degraded.
        'scheduler_heartbeats',

        // Framework / operational tables. No business rows, no tenant column.
        'migrations',
        'jobs',
        'job_batches',
        'failed_jobs',
        'cache',
        'cache_locks',
        'sessions',
        'password_reset_tokens',
    ];

    /** The tenancy column, spelled one way (spine S16). SmartPRS 2 spells it four. */
    public const TENANT_COLUMN = 'tenant_id';

    public static function isTenantScoped(string $table): bool
    {
        return !in_array($table, self::PLATFORM_TABLES, true);
    }

    /**
     * @param  list<string>  $tables  usually every table in the schema
     * @return list<string>
     */
    public static function tenantScoped(array $tables): array
    {
        return array_values(array_filter($tables, self::isTenantScoped(...)));
    }
}
