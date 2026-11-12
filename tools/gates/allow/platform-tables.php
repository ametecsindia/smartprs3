<?php
/**
 * Law 6 allow-list: tables that are platform-scoped, not tenant-scoped, and so
 * are exempt from the NOT NULL tenant_id requirement.
 *
 * The list itself lives in the runtime registry the persistence layer reads —
 * src/Platform/Persistence/TableRegistry.php — so the build rule and the query
 * builder cannot drift apart. A migration linter that allow-lists a table the
 * runtime still scopes (or vice versa) is worse than no linter.
 *
 * Adding a table there asserts "no tenant owns a row in this table". If a tenant
 * can own a row, it is a business table and it needs tenant_id — SmartPRS 2's
 * data leak was the declared tenancy scope filtering on a column that did not
 * exist (spine S1 Law 6).
 *
 * If the registry is missing this returns nothing, which makes the gate
 * stricter, not looser: every table is then required to carry tenant_id.
 *
 * @return list<string>
 */
declare(strict_types=1);

$registry = __DIR__ . '/../../../src/Platform/Persistence/TableRegistry.php';

if (!is_file($registry)) {
    return [];
}

require_once $registry;

return SmartPRS\Platform\Persistence\TableRegistry::PLATFORM_TABLES;
