<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Access;

/**
 * THE OPERATION REGISTRY AND ITS BUILD-FAILING SWEEP — Part XXII §XXII.4.2.
 *
 * R-XXII-074: "A route with no operation binding MUST fail the build." The
 * sweep enumerates every registered route at boot in a test, resolves each to an
 * operation, and fails on any route that resolves to none.
 *
 * This is Law 5's gate — authorisation is server-side and fails closed — and
 * until now Law 5 was the one law in spine S1 with no mechanical check, which
 * this repository's own README calls a wish.
 *
 * The sweep lives in the domain rather than in tools/gates/ because it needs the
 * permission catalogue and the live route list, not a tokeniser. Everything it
 * cannot see it reports as NOT CHECKED, never as a pass: a sweep that runs with
 * no route list and reports success is the failure mode this codebase has
 * already had once.
 */
final class OperationRegistry
{
    /** @var array<string, Operation> */
    private array $operations = [];

    /** @param list<Operation> $operations */
    public function __construct(array $operations = [])
    {
        foreach ($operations as $operation) {
            $this->register($operation);
        }
    }

    public function register(Operation $operation): void
    {
        if (isset($this->operations[$operation->code])) {
            throw new \InvalidArgumentException("Operation '{$operation->code}' is already registered.");
        }

        // Two operations binding the same route is two answers to "what may
        // this caller do here", and the one that wins would be load order.
        foreach ($operation->httpBindings as $binding) {
            $existing = $this->forRoute($binding);

            if ($existing !== null) {
                throw new \InvalidArgumentException(
                    "Route '{$binding}' is already bound to operation '{$existing->code}'; "
                    . "'{$operation->code}' cannot bind it too."
                );
            }
        }

        $this->operations[$operation->code] = $operation;
    }

    public function get(string $code): ?Operation
    {
        return $this->operations[$code] ?? null;
    }

    public function forRoute(string $route): ?Operation
    {
        foreach ($this->operations as $operation) {
            if (in_array($route, $operation->httpBindings, true)) {
                return $operation;
            }
        }

        return null;
    }

    /** @return list<Operation> */
    public function all(): array
    {
        return array_values($this->operations);
    }

    /**
     * The live unauthenticated surface, as the registry states it.
     *
     * R-XXII-075: this is an explicit allow-list reviewed as a unit, and the
     * sweep asserts the live surface equals it exactly. SmartPRS 2's is 78
     * declarations across four route files and three conditional blocks, and
     * nobody can state it from the code — which is the same as not having one.
     *
     * @return list<string>
     */
    public function unauthenticatedRoutes(): array
    {
        $routes = [];

        foreach ($this->operations as $operation) {
            if ($operation->unauthenticated) {
                foreach ($operation->httpBindings as $binding) {
                    $routes[] = $binding;
                }
            }
        }

        sort($routes);

        return $routes;
    }
}
