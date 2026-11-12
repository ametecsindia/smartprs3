<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Api;

/**
 * THE CLOSED, PUBLISHED, VERSIONED CODE CATALOGUE — Part XXIV R-XXIV-042.
 *
 * "Clients branch on `code`, never on `message`." That only works if the set of
 * codes is finite, published and stable — so it lives here, as data, and
 * `ErrorEnvelope` refuses anything not in it. An ad-hoc code invented at a call
 * site is a code no client has ever seen and therefore cannot handle; it arrives
 * as an unhandled branch in somebody else's software.
 *
 * Adding a code is ADDITIVE and safe. Removing or repurposing one is BREAKING,
 * which is why `SINCE` records the version each was introduced in: a client
 * written against v3.0 must keep working, and the only way to know whether a
 * code is safe to rely on is to know when it appeared.
 *
 * Each code has exactly ONE status. A code whose status varies by call site is a
 * code a client cannot handle — it has to branch on the pair, and nobody does.
 */
final class ErrorCatalogue
{
    /** @var array<string, array{status: int, since: string, retryable_note?: string}> */
    public const CODES = [
        // 400 — malformed
        'request.malformed' => ['status' => 400, 'since' => '3.0'],
        'request.unknown_filter' => ['status' => 400, 'since' => '3.0'],
        'request.bad_cursor' => ['status' => 400, 'since' => '3.0'],
        'idempotency.key_required' => ['status' => 400, 'since' => '3.0'],

        // 401 — uniform for every cause, so nothing distinguishes "no such user"
        // from "wrong password" from "expired token"
        'auth.unauthenticated' => ['status' => 401, 'since' => '3.0'],

        // 403 — authenticated, not permitted
        'auth.forbidden' => ['status' => 403, 'since' => '3.0'],
        'auth.insufficient_scope' => ['status' => 403, 'since' => '3.0'],
        'auth.out_of_data_scope' => ['status' => 403, 'since' => '3.0'],

        // 404 — does not exist OR is another tenant's, indistinguishable by design
        'resource.not_found' => ['status' => 404, 'since' => '3.0'],

        'request.method_not_allowed' => ['status' => 405, 'since' => '3.0'],
        'request.unsupported_media_type' => ['status' => 415, 'since' => '3.0'],

        // 409 — state conflict
        'state.conflict' => ['status' => 409, 'since' => '3.0'],
        'idempotency.in_progress' => ['status' => 409, 'since' => '3.0'],
        'period.locked' => ['status' => 409, 'since' => '3.0'],

        'endpoint.sunset' => ['status' => 410, 'since' => '3.0'],
        'precondition.failed' => ['status' => 412, 'since' => '3.0'],

        // 422 — semantically invalid
        'validation.failed' => ['status' => 422, 'since' => '3.0'],
        'idempotency.key_reuse_conflict' => ['status' => 422, 'since' => '3.0'],
        'rule.violated' => ['status' => 422, 'since' => '3.0'],

        'record.locked_by_workflow' => ['status' => 423, 'since' => '3.0'],
        'rate.exceeded' => ['status' => 429, 'since' => '3.0'],
        'quota.exceeded' => ['status' => 429, 'since' => '3.0'],

        'server.unexpected' => ['status' => 500, 'since' => '3.0'],
        'dependency.unavailable' => ['status' => 503, 'since' => '3.0'],
        'maintenance.in_progress' => ['status' => 503, 'since' => '3.0'],
    ];

    public static function knows(string $code): bool
    {
        return isset(self::CODES[$code]);
    }

    public static function statusFor(string $code): int
    {
        return self::CODES[$code]['status'] ?? throw new \LogicException("Unknown error code '{$code}'.");
    }

    public static function documentationUrl(string $code): string
    {
        return 'https://developer.ametecs.com/api/v3/errors/' . $code;
    }

    /** Every code is `namespace.reason`, and the gate asserts it. */
    public static function malformedCodes(): array
    {
        return array_values(array_filter(
            array_keys(self::CODES),
            static fn (string $code): bool => preg_match('/^[a-z]+(_[a-z]+)*\.[a-z]+(_[a-z]+)*$/', $code) !== 1,
        ));
    }
}
