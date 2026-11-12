<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * WHO OVERRIDES EVERY OTHER APPROVAL (owner, 12 Oct 2026): a Company Admin or
 * HR Admin approval is final at any step of any multi-step approval. The roles
 * are declared in config/approval-authority.json, never written here.
 */
final class ApprovalAuthority
{
    /** @var list<string> lower-cased */
    private array $finalRoles;

    public function __construct(string $file)
    {
        $config = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $this->finalRoles = array_map(static fn ($r): string => strtolower(trim((string) $r)),
            (array) (is_array($config) ? ($config['final_roles'] ?? []) : []));
    }

    public function isFinal(string $role): bool
    {
        // ADMINISTRATOR HAS NO RESTRICTIONS (owner, D-OWN-01): always final, whatever the file lists.
        return strtolower(trim($role)) === 'administrator'
            || (trim($role) !== '' && in_array(strtolower(trim($role)), $this->finalRoles, true));
    }
}
