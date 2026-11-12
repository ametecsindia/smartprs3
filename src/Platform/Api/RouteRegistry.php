<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Api;

/**
 * EVERY ROUTE, DECLARED ONCE, AS DATA — Part XXIV §XXIV.3.
 *
 * The OpenAPI document is GENERATED from this (R-XXIV-057), never hand-written,
 * because "a hand-maintained specification drifts" — SmartPRS 2's own
 * documentation disagrees with its source in sixteen recorded places, including
 * three code comments that state the opposite of the code they annotate.
 *
 * So a route is not a line in a router plus a paragraph in a wiki. It is one
 * declaration carrying everything the contract needs: the permission, the
 * scopes, the request and response schemas, and the error codes it can return.
 * A route that cannot say what it returns cannot be published, and a route that
 * cannot be published cannot be called.
 *
 * THE PERMISSION IS MANDATORY (R-XXIV-022). Not "should have one" — a route
 * registered without a policy binding is refused here, at the moment of
 * registration, because the alternative is an endpoint that is open by omission.
 * That is the single most common way an API grows a hole: not a wrong
 * permission, an absent one.
 *
 * AND THE ERROR CODES ARE DECLARED. A client can only handle what it knows can
 * happen; an endpoint that returns an undeclared code hands its caller a branch
 * nobody wrote.
 */
final class RouteRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $routes = [];

    /**
     * @param list<string> $scopes
     * @param list<string> $errorCodes beyond the universal ones every route can return
     * @throws \InvalidArgumentException on a declaration the contract cannot publish
     */
    public function register(
        string $method,
        string $path,
        string $operationId,
        string $permission,
        array $scopes,
        string $responseSchema,
        ?string $requestSchema = null,
        array $errorCodes = [],
        bool $idempotent = false,
        string $summary = '',
    ): void {
        $problems = RouteGrammar::check([
            'method' => $method, 'path' => $path, 'operation' => $operationId, 'idempotent' => $idempotent,
        ]);

        if ($problems !== []) {
            throw new \InvalidArgumentException(
                "{$method} {$path} does not satisfy the URL grammar:\n  · " . implode("\n  · ", $problems)
            );
        }

        if (trim($permission) === '') {
            throw new \InvalidArgumentException(
                "{$method} {$path} declares no permission. A route registered without a policy binding is "
                . 'open by omission, and that is the most common way an API grows a hole: not a wrong '
                . 'permission, an absent one.'
            );
        }

        if ($scopes === []) {
            throw new \InvalidArgumentException(
                "{$method} {$path} declares no scopes. A token that can call an endpoint no scope covers "
                . 'is a token whose blast radius nobody can state.'
            );
        }

        if (trim($responseSchema) === '') {
            throw new \InvalidArgumentException(
                "{$method} {$path} declares no response schema, so it cannot be published — and a route "
                . 'that cannot be published cannot be called.'
            );
        }

        if (in_array(strtoupper($method), ['POST', 'PATCH'], true) && $requestSchema === null) {
            throw new \InvalidArgumentException(
                "{$method} {$path} accepts a body and declares no request schema. An undeclared body is "
                . 'one the server validates by accident.'
            );
        }

        foreach ($errorCodes as $code) {
            if (!ErrorCatalogue::knows($code)) {
                throw new \InvalidArgumentException(
                    "{$method} {$path} declares error code '{$code}', which is not in the published "
                    . 'catalogue. A client can only handle what it knows can happen.'
                );
            }
        }

        $key = strtoupper($method) . ' ' . $path;

        if (isset($this->routes[$key])) {
            throw new \InvalidArgumentException(
                "{$key} is already registered. Two declarations of one route are two contracts, and the "
                . 'one that wins is whichever loaded last.'
            );
        }

        $this->routes[$key] = [
            'method' => strtoupper($method),
            'path' => $path,
            'operation_id' => $operationId,
            'permission' => $permission,
            'scopes' => array_values($scopes),
            'request_schema' => $requestSchema,
            'response_schema' => $responseSchema,
            'error_codes' => array_values($errorCodes),
            'idempotent' => $idempotent,
            'summary' => $summary,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        ksort($this->routes);

        return $this->routes;
    }

    public function count(): int
    {
        return count($this->routes);
    }

    /** @return list<string> operation ids used more than once */
    public function duplicateOperationIds(): array
    {
        $seen = [];
        $duplicates = [];

        foreach ($this->routes as $route) {
            $id = $route['operation_id'];

            if (isset($seen[$id])) {
                $duplicates[] = $id;
            }

            $seen[$id] = true;
        }

        return array_values(array_unique($duplicates));
    }

    /**
     * Controllers reachable from more than one prefix — R-XXIV-001's own test.
     *
     * @return list<string>
     */
    public function operationsOnMultiplePrefixes(): array
    {
        $prefixes = [];

        foreach ($this->routes as $route) {
            $segments = array_values(array_filter(explode('/', $route['path'])));
            $prefixes[$route['operation_id']][$segments[2] ?? ''] = true;
        }

        return array_keys(array_filter($prefixes, static fn (array $p): bool => count($p) > 1));
    }
}
