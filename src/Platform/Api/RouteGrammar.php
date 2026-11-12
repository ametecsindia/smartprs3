<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Api;

/**
 * THE ROUTE-DECLARATION LINTER — Part XXIV §XXIV.2.2.
 *
 * `/api/v3` is the ONLY programmatic surface (R-XXIV-001). There is no second,
 * private, SPA-only endpoint family: the React client authenticates as a
 * first-party user and calls the identical routes, with the identical
 * serialisation, authorisation and error envelope a third party gets. SmartPRS 2
 * has two front ends and view-shaped payloads, and its `/app/data` returns a
 * fixed 62-field projection built for one screen — salary and Aadhaar included,
 * with no field-level control.
 *
 * Every rule below is enforced here rather than documented, and three of them
 * exist because of a specific defect:
 *
 *   NO `PUT`, ANYWHERE (R-XXIV-005). Not discouraged — absent. A full-replace
 *   update is how SmartPRS 2's employee save nulls every omitted key, and a
 *   method documented as dangerous is a method somebody uses on a Friday.
 *
 *   UUIDv7 ONLY. A route whose path parameter is typed as an integer fails the
 *   build. Sequential integer ids are enumerable across tenants, and a 404 that
 *   is indistinguishable from a 403 (R-XXIV-044) is worth nothing if the caller
 *   can count.
 *
 *   TENANT IS NEVER A PARAMETER. It comes from the credential, always. The
 *   moment a tenant id is addressable, it is forgeable — SmartPRS 2 has queries
 *   where NULL and 0 mean "all tenants".
 */
final class RouteGrammar
{
    public const METHODS = ['GET', 'POST', 'PATCH', 'DELETE'];

    /** A state transition that is not a resource. Always POST, always idempotency-keyed. */
    public const VERBS = ['approve', 'publish', 'recalculate', 'freeze', 'void', 'reject', 'cancel'];

    /**
     * @param array{method: string, path: string, operation: string, idempotent?: bool} $route
     * @return list<string> empty means the route is admissible
     */
    public static function check(array $route): array
    {
        $problems = [];
        $method = strtoupper($route['method']);
        $path = $route['path'];

        if ($method === 'PUT') {
            $problems[] = 'PUT is not used anywhere in this API. A full-replace update is how SmartPRS 2\'s '
                . 'employee save nulls every omitted key; the method is REMOVED rather than documented as '
                . 'dangerous, because a documented hazard is one somebody uses on a Friday';
        } elseif (!in_array($method, self::METHODS, true)) {
            $problems[] = "'{$method}' is not one of " . implode('/', self::METHODS);
        }

        if (!str_starts_with($path, '/api/v3/')) {
            $problems[] = "'{$path}' is outside /api/v3. There is no second, private, SPA-only endpoint "
                . 'family: the front end calls the same routes a third party does, or the two diverge and '
                . "neither side's tests can see it";
        }

        $segments = array_values(array_filter(explode('/', substr($path, strlen('/api/v3')))));
        $collections = [];
        $depth = 0;

        foreach ($segments as $segment) {
            if (str_starts_with($segment, '{')) {
                $name = trim($segment, '{}');

                if (!str_ends_with($name, ':uuid')) {
                    $problems[] = "path parameter '{$segment}' is not typed as a UUID. Sequential integer "
                        . 'ids are enumerable across tenants, and a 404 that is deliberately '
                        . 'indistinguishable from a 403 is worth nothing if the caller can count';
                }

                $depth++;

                continue;
            }

            if (in_array($segment, self::VERBS, true)) {
                if ($method !== 'POST') {
                    $problems[] = "verb endpoint '{$segment}' is {$method}; a verb is always POST";
                }

                if (($route['idempotent'] ?? false) !== true) {
                    $problems[] = "verb endpoint '{$segment}' is not idempotency-keyed. Every verb moves a "
                        . 'state, and a retried state transition without a key is a second approval';
                }

                continue;
            }

            $collections[] = $segment;

            if (preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $segment) !== 1) {
                $problems[] = "'{$segment}' is not a kebab-case noun. `/salary-structures`, never "
                    . '`/getSalaryStructure` — a verb in a collection name is a screen leaking into a URL';
            } elseif (!str_ends_with($segment, 's') && !str_ends_with($segment, 'data')) {
                $problems[] = "'{$segment}' is not plural";
            }

            if (str_contains($segment, 'tenant')) {
                $problems[] = "'{$segment}' addresses the tenant. Tenant is derived from the credential, "
                    . 'always — the moment it is addressable it is forgeable, and SmartPRS 2 has queries '
                    . 'where NULL and 0 mean "all tenants"';
            }
        }

        if (count($collections) > 2) {
            $problems[] = 'nesting is ' . count($collections) . ' levels deep; the maximum is two. Deeper '
                . 'relationships are filters on a top-level collection, because a four-level URL encodes a '
                . 'navigation path rather than a resource';
        }

        foreach (['tenant_id', 'tenant'] as $forbidden) {
            if (str_contains($path, $forbidden)) {
                $problems[] = "the path carries '{$forbidden}'";
            }
        }

        // An unsafe method that creates, moves money, transitions or notifies
        // REQUIRES a key (R-XXIV-031).
        if (in_array($method, ['POST', 'PATCH', 'DELETE'], true)
            && ($route['idempotent'] ?? false) !== true
            && $method === 'POST'
        ) {
            $problems[] = "POST {$path} declares no idempotency requirement. A create replayed on a "
                . 'timeout is a duplicate employee, a duplicate payment or a second approval, and the '
                . 'caller cannot tell which of the two attempts landed';
        }

        return $problems;
    }
}
