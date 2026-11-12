<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Tenancy;

/**
 * Spine Law 6 — TENANCY IS STRUCTURAL. "There is no null-tenant state."
 *
 * Request-scoped holder, bound as a singleton in the container and established
 * exactly once per request/job by the tenancy middleware. Reading it before it
 * has been established throws: a query that would otherwise have silently run
 * across all tenants fails loudly instead.
 *
 * Not static, so a test (and a queue worker processing two tenants' jobs) can
 * hold independent instances. SmartPRS 2's global scope is inert because the
 * column it filters on does not exist; the fix is that nothing can read a tenant
 * that was never set.
 */
final class TenantContext
{
    private ?string $tenantId = null;

    /** Establishing is explicit and one-way for the life of the context. */
    public function establish(string|int $tenantId): void
    {
        $tenantId = (string) $tenantId;

        if ($tenantId === '') {
            throw new \InvalidArgumentException('Tenant id may not be empty.');
        }

        if ($this->tenantId !== null && $this->tenantId !== $tenantId) {
            throw new \LogicException(
                "Tenant context is already established as {$this->tenantId} and may not be "
                . "re-established as {$tenantId} within one request."
            );
        }

        $this->tenantId = $tenantId;
    }

    public function tenantId(): string
    {
        return $this->tenantId ?? throw new TenantNotEstablished(
            'No tenant has been established for this request. There is no null-tenant state (spine Law 6).'
        );
    }

    public function isEstablished(): bool
    {
        return $this->tenantId !== null;
    }

    /**
     * Only for the boundaries that legitimately cross tenants: a queue worker
     * between jobs, and the platform console. Never call this from a domain path.
     */
    public function release(): void
    {
        $this->tenantId = null;
    }
}
