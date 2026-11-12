<?php

declare(strict_types=1);

use SmartPRS\Platform\Support\Clock;
use SmartPRS\Platform\Support\FrozenClock;
use SmartPRS\Platform\Support\SystemClock;
use SmartPRS\Platform\Tenancy\TenantContext;
use SmartPRS\Platform\Tenancy\TenantNotEstablished;

group('Clock');

$clock = FrozenClock::at('2026-08-15T20:30:00Z');

// FrozenClock and SystemClock both declare `implements Clock`, so the type
// system already guarantees "FrozenClock/SystemClock is a Clock" — the old
// instanceof checks here asserted nothing further; behaviour is exercised below.
same('2026-08-15 20:30:00', $clock->now()->format('Y-m-d H:i:s'), 'a frozen instant does not move');
same('2026-08-15 20:30:00', $clock->now()->format('Y-m-d H:i:s'), 'and still does not move on a second read');
same('UTC', $clock->now()->getTimezone()->getName(), 'instants are UTC (spine S16)');

// 20:30 UTC is already the next day in Asia/Kolkata (+05:30) — the business
// date is not the UTC date, which is exactly why domain code must ask.
same('2026-08-16', $clock->today()->format('Y-m-d'), 'today() is the business date, not the UTC date');

$clock->advance('+1 day');
same('2026-08-16 20:30:00', $clock->now()->format('Y-m-d H:i:s'), 'a frozen clock can be advanced deliberately');

$sydney = FrozenClock::at('2026-08-15T20:30:00Z', 'Australia/Sydney');
same('2026-08-16', $sydney->today()->format('Y-m-d'), 'the business timezone is injected, never assumed');

group('TenantContext');

$context = new TenantContext();

ok(!$context->isEstablished(), 'a fresh context holds no tenant');
throws(
    TenantNotEstablished::class,
    static fn () => $context->tenantId(),
    'reading an unestablished tenant throws — there is no null-tenant state (Law 6)',
);

$context->establish(7);
same('7', $context->tenantId(), 'establishing is explicit and then readable');
ok($context->isEstablished(), 'isEstablished');

$context->establish('7');
same('7', $context->tenantId(), 're-establishing the same tenant is a no-op, so middleware is idempotent');

throws(
    LogicException::class,
    static fn () => $context->establish(8),
    'a request may not switch tenants mid-flight',
);
throws(
    InvalidArgumentException::class,
    static fn () => (new TenantContext())->establish(''),
    'an empty tenant id is rejected',
);

$context->release();
ok(!$context->isEstablished(), 'release() returns the context to unestablished, for a queue worker between jobs');
throws(
    TenantNotEstablished::class,
    static fn () => $context->tenantId(),
    'and reading it after release throws again',
);

$other = new TenantContext();
$other->establish(9);
$context->establish(7);
same('9', $other->tenantId(), 'two contexts are independent — no static global state');
same('7', $context->tenantId(), 'and neither leaks into the other');
