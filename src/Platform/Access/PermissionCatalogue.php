<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Access;

/**
 * The permission catalogue of Part XXII §XXII.2.2, with the extensibility rule
 * R-XXII-010 forced on it by Appendix A/B: the catalogue is **extensible by a
 * defined process, not closed**, and the process has exactly two paths.
 *
 *  A. Ametecs-authored entry — a declared row, shipped in a release migration.
 *     catalogue.php is that seed, generated from the blueprint's own register.
 *
 *  B. Reserved generative namespace — `form.*`, `report.*` and `dashboard.*`,
 *     and no others (R-XXII-010c). A tenant-created artefact mints its codes
 *     mechanically from its own key when a version is **published**, and they
 *     are revoked when it is retired. The tenant never authors a code; it
 *     authors an artefact, and the platform derives the code.
 *
 * Anything that is neither is rejected. That is R-XXII-010b — the closure
 * property, asserted where it is actually true instead of over a whole catalogue
 * that 395 real codes already contradict.
 *
 * Why a generative *namespace* and not "let tenants name permissions": a code
 * for a form that does not exist yet cannot be seeded by a migration, but a
 * `form.*` code can still be *proved* — the platform checks it against a
 * published form definition. Unprovable codes stay rejected.
 */
final readonly class PermissionCatalogue
{
    /** R-XXII-010c: platform-owned and closed. A fourth is a release decision. */
    public const RESERVED_NAMESPACES = ['form', 'report', 'dashboard'];

    /**
     * The verbs the platform mints for a published artefact of each kind. These
     * bind to the *generic* operation that serves every artefact of that kind
     * (R-XXII-010, path B), so the set is fixed by the operations that exist,
     * not by anything the tenant writes.
     *
     * ponytail: the blueprint does not enumerate these; they are the verbs the
     * form/report/dashboard operations in Parts XIV and XXVI actually declare.
     * Move them into the operation registry the moment that registry exists —
     * a mint verb with no operation behind it is a code nothing can check.
     */
    private const GENERATIVE_VERBS = [
        'form' => ['view', 'submit', 'update', 'delete', 'export'],
        'report' => ['view', 'run', 'export', 'schedule'],
        'dashboard' => ['view', 'configure', 'export'],
    ];

    /**
     * @param  array<string, array{0:string,1:string,2:string}>  $declared
     *         code => [module, declaring parts, §XXII.2.2 group]
     * @param  ?\Closure(string, string): bool  $publishedArtefact
     *         (kind, artefact key) => is a version of it published right now?
     *         NULL means no artefact repository is wired: every generative code
     *         is then unprovable and therefore rejected. Fail closed.
     */
    public function __construct(
        private array $declared,
        private ?\Closure $publishedArtefact = null,
    ) {
        // R-XXII-010a: "the catalogue validator MUST reject one that appears".
        // A declared row in a reserved namespace would shadow a minted code and
        // survive the artefact's retirement, which is exactly the grant that
        // outlives its reason.
        // ponytail: O(n) over 531 keys per construction. Build the catalogue
        // once per request; hoist this into the release build if it ever shows
        // up in a profile.
        foreach (array_keys($declared) as $code) {
            if (in_array(explode('.', $code, 2)[0], self::RESERVED_NAMESPACES, true)) {
                throw new \InvalidArgumentException(
                    "Declared catalogue entry '{$code}' lies in the reserved generative "
                    . 'namespace ' . explode('.', $code, 2)[0] . '.* — R-XXII-010a forbids seeding one. '
                    . 'Codes there are minted from published artefact versions.'
                );
            }
        }
    }

    /** The shipped seed. */
    public static function seeded(?\Closure $publishedArtefact = null): self
    {
        return new self(require __DIR__ . '/catalogue.php', $publishedArtefact);
    }

    /** Does this code exist — declared, or minted from a published artefact? */
    public function contains(Permission|string $permission): bool
    {
        $permission = Permission::of($permission);

        return isset($this->declared[$permission->code]) || $this->isMinted($permission);
    }

    /** @throws UnknownPermission */
    public function assertKnown(Permission|string $permission): void
    {
        $permission = Permission::of($permission);

        if ($this->contains($permission)) {
            return;
        }

        throw new UnknownPermission(
            "'{$permission->code}' is not in the permission catalogue. It is not an "
            . 'Ametecs-authored entry (R-XXII-010b), and '
            . ($this->isGenerative($permission)
                ? 'no published ' . $permission->module() . ' artefact mints it.'
                : $permission->module() . '.* is not a reserved generative namespace '
                    . '(' . implode('.*, ', self::RESERVED_NAMESPACES) . '.*).')
        );
    }

    /**
     * Does this code lie in a reserved generative namespace? True says only
     * "this is path B territory" — not that the code is valid. A `form.*` code
     * with no published form behind it is generative and still rejected.
     */
    public function isGenerative(Permission|string $permission): bool
    {
        return in_array(Permission::of($permission)->module(), self::RESERVED_NAMESPACES, true);
    }

    /** The declaring parts and catalogue group, for the role editor and audit. */
    public function describe(Permission|string $permission): ?array
    {
        $row = $this->declared[Permission::of($permission)->code] ?? null;

        return $row === null ? null : ['module' => $row[0], 'parts' => $row[1], 'group' => $row[2]];
    }

    /** @return list<string> */
    public function declaredCodes(): array
    {
        return array_keys($this->declared);
    }

    /**
     * `<kind>.<artefact key>.<verb>` — exactly three segments, a mint verb for
     * that kind, and a published artefact behind it. Every failure returns
     * false; nothing here throws its way into an allow.
     */
    private function isMinted(Permission $permission): bool
    {
        if (count($permission->segments) !== 3 || $this->publishedArtefact === null) {
            return false;
        }

        [$kind, $key, $verb] = $permission->segments;

        if (!in_array($verb, self::GENERATIVE_VERBS[$kind] ?? [], true)) {
            return false;
        }

        return ($this->publishedArtefact)($kind, $key) === true;
    }
}
