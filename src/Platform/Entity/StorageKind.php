<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

/**
 * WHERE THE VALUE ACTUALLY SITS — Part X §X.2.2, R-X-004 and R-X-006.
 *
 * Three kinds, and the one that matters is `Attribute`: every customer-defined
 * field lives in one typed row of `entity_field_values`, created by a tenant
 * administrator at runtime WITH NO DDL. SmartPRS 2 creates 34 `employees`
 * columns, six tables and several master tables at request time, always untyped
 * — which is why its production database user must hold ALTER, and why nothing
 * can be said about what shape any of that data is in.
 *
 * `Generated` is a PROMOTION: a field measured to be query-hot gets a generated
 * column and an index, added by migration at deploy time. It is not a different
 * kind of field and it changes no caller, because R-X-006 says the storage kind
 * is invisible above the repository — every surface reads through the field
 * definition. If promoting a field changed a single call site, the abstraction
 * would be a comment rather than a boundary.
 */
enum StorageKind: string
{
    case Column = 'column';
    case Attribute = 'attribute';
    case Generated = 'generated';

    /** Whether creating a field of this kind requires a migration (R-X-004). */
    public function requiresMigration(): bool
    {
        return $this !== self::Attribute;
    }

    public function why(): string
    {
        return match ($this) {
            self::Column => 'a real, migration-owned column: core identity and hot-query attributes',
            self::Attribute => 'one typed row in entity_field_values — no DDL, ever',
            self::Generated => 'a generated column over the attribute row, added by migration as a promotion',
        };
    }
}
