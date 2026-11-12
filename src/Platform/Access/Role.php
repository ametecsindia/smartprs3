<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Access;

/**
 * A role — a named, versioned bundle of permissions (Part XXII §XXII.2.3).
 *
 * Three deliberate absences, each of which is a rule and not an omission:
 *
 *  1. **No deny rules, no negative permissions** (R-XXII-039). A role grants; it
 *     never revokes. A deny interacts with the union of R-XXII-030 — a user may
 *     hold several assignments — to make the effective set depend on the ORDER
 *     the assignments are evaluated in, and order-dependence in authorisation is
 *     precisely the property the rest of this platform exists to eliminate. It
 *     also makes the customer-facing question "what can this role do?" answerable
 *     only by simulating every combination the user might hold. "HR Manager
 *     without payroll approval" is a cloned role without that permission, which
 *     is a set anyone can read off the screen.
 *
 *  2. **No role inheritance** (R-XXII-038). "Manager inherits Employee" makes one
 *     role's effective set a function of another role's edits, so no role can be
 *     reviewed on its own. Reuse is cloning (R-XXII-020) plus permission groups
 *     in the editor, which are presentation with no runtime meaning.
 *
 *  3. **No permission-level graph walk at request time** (R-XXII-037). `implies[]`
 *     is real, but its closure is computed HERE, at publication, and materialised
 *     into the flat set — the database equivalent is `role_permissions_effective`.
 *     Evaluation is then a single hash lookup.
 *
 * System roles are Ametecs-shipped and cloneable but not editable; custom roles
 * are the tenant's (R-XXII-020). The distinction is carried so an upgrade can
 * reconcile the former without touching the latter.
 */
final readonly class Role
{
    /** @param array<string, true> $permissions the materialised closure, code => true */
    private function __construct(
        public string $code,
        public string $name,
        public int $version,
        public bool $isSystem,
        private array $permissions,
    ) {
    }

    /**
     * Publish a role version: validate every granted code against the catalogue,
     * close it over `implies[]`, and freeze the result.
     *
     * The catalogue is optional here because publication is not the closure
     * point — AccessDecider re-checks the catalogue on every decision, so a role
     * published without one can still grant nothing uncatalogued. Passing it
     * turns a request-time denial into a publish-time error, which is where a
     * customer can act on it, so pass it.
     *
     * @param  list<Permission|string>  $granted  the directly granted codes
     * @param  array<string, list<string>>  $implies  code => codes it entails.
     *         The graph MUST be acyclic (R-XXII-014); a cycle is a build failure
     *         and is raised here rather than looping at request time.
     */
    public static function publish(
        string $code,
        string $name,
        int $version,
        bool $isSystem,
        array $granted,
        array $implies = [],
        ?PermissionCatalogue $catalogue = null,
    ): self {
        if ($code === '' || $version < 1) {
            throw new \InvalidArgumentException('A role version needs a code and a version number from 1.');
        }

        $effective = [];
        $queue = [];

        foreach ($granted as $one) {
            $queue[] = Permission::of($one)->code;
        }

        // Breadth-first closure. `$effective` doubles as the visited set, so a
        // cycle in implies[] terminates instead of spinning — but it is still
        // rejected below, because a cycle means the graph is wrong.
        while ($queue !== []) {
            $current = array_shift($queue);

            if (isset($effective[$current])) {
                continue;
            }

            $catalogue?->assertKnown($current);
            $effective[$current] = true;

            foreach ($implies[$current] ?? [] as $implied) {
                $queue[] = Permission::of($implied)->code;
            }
        }

        self::assertAcyclic($implies);

        ksort($effective);

        return new self($code, $name, $version, $isSystem, $effective);
    }

    public function grants(Permission|string $permission): bool
    {
        // A hash lookup on the materialised closure. No prefix matching, no
        // wildcard, no walk — `payroll.approve` does not grant
        // `payroll.approve.finance` unless implies[] said so at publication.
        return isset($this->permissions[Permission::of($permission)->code]);
    }

    /** @return list<string> */
    public function permissionCodes(): array
    {
        return array_keys($this->permissions);
    }

    public function __toString(): string
    {
        return '"' . $this->name . '" v' . $this->version . ($this->isSystem ? ' (system)' : '');
    }

    /** @param array<string, list<string>> $implies */
    private static function assertAcyclic(array $implies): void
    {
        // ponytail: DFS over the edges of one role's implies map — tens of nodes.
        // The real check belongs to the catalogue build, once for the whole graph.
        $state = [];

        $visit = static function (string $node) use (&$visit, $implies, &$state): void {
            if (($state[$node] ?? null) === 'done') {
                return;
            }

            if (($state[$node] ?? null) === 'open') {
                throw new \InvalidArgumentException(
                    "implies[] contains a cycle through '{$node}'. R-XXII-014: the "
                    . 'implication graph must be acyclic — a cycle makes the effective '
                    . 'permission set of a role undefined.'
                );
            }

            $state[$node] = 'open';

            foreach ($implies[$node] ?? [] as $next) {
                $visit($next);
            }

            $state[$node] = 'done';
        };

        foreach (array_keys($implies) as $node) {
            $visit($node);
        }
    }
}
