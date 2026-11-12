<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow\Persistence;

use SmartPRS\Platform\Persistence\TenantScopedQuery;
use SmartPRS\Platform\Tenancy\TenantContext;

/**
 * Resolving a status CODE to its row (spine S16, Part XXVIII §XXVIII.4).
 *
 * "No database ENUM types." A status is a row in `status_values` under a domain
 * in `status_domains`, so a tenant can add one, a transition set can constrain
 * it, and a report can name it — none of which an ENUM permits without a
 * migration and a deploy.
 *
 * The cost of that is a lookup on every write, which is why this memoises: the
 * vocabulary is small, changes at configuration time, and is read constantly.
 * An unknown code THROWS rather than inserting: a status that does not exist is
 * a bug in the caller, and creating it on demand would let a typo become a new
 * state nothing transitions out of.
 */
final class StatusVocabulary
{
    /** @var array<string, int> "domain:code" => status_values.id */
    private array $ids = [];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly TenantContext $tenant,
    ) {
    }

    public function id(string $domain, string $code): int
    {
        $key = $domain . ':' . $code;

        if (isset($this->ids[$key])) {
            return $this->ids[$key];
        }

        // `status_domains` is platform data — the DOMAIN is owned by the
        // platform, the VALUES inside it are tenant configuration (S16). Two
        // reads rather than a subquery, because the tenant predicate applies to
        // exactly one of them and a join would blur which.
        $domainRow = TenantScopedQuery::on($this->pdo, $this->tenant, 'status_domains')
            ->where('domain_key', '=', $domain)
            ->first(['id']);

        $row = $domainRow === null ? null : TenantScopedQuery::on($this->pdo, $this->tenant, 'status_values')
            ->where('status_domain_id', '=', (int) $domainRow['id'])
            ->where('code', '=', $code)
            ->first(['id']);

        if ($row === null) {
            throw new \RuntimeException(
                "Status '{$code}' is not declared in domain '{$domain}'. A status is configuration, not a "
                . 'string a caller invents — creating it on demand would let a typo become a state nothing '
                . 'transitions out of.'
            );
        }

        return $this->ids[$key] = (int) $row['id'];
    }
}
