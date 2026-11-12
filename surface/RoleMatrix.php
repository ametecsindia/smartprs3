<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Access\Permission;
use SmartPRS\Platform\Access\PermissionCatalogue;

/**
 * ROLES & PERMISSIONS AS A FOUR-STATE GRID, ONE PERMISSION CODE AT A TIME.
 *
 * SmartPRS 2's screen was a grid of checkboxes per role and module with a
 * Save button — and a footnote saying enforcement was "the next phase". This
 * grid is real: saving it writes through `Authorisation::CUSTOMISATION_KEY`,
 * the one versioned override every role's permission set is built from, so
 * every guard in the product answers differently the next request. Nothing
 * about that is a role check written inline — it is still
 * `Surface\Authorisation`, the one place a role is granted anything, only
 * its input is now configuration (CLAUDE.md's governing principle) instead
 * of a PHP edit, behind its own permission (`access.role.update`).
 *
 * THE GRAIN IS THE INDIVIDUAL PERMISSION CODE, not the module (10 Sep — this
 * used to collapse every code sharing a module's first segment into one row:
 * "Directory" (`employee.view`) and "Add Employee" (`employee.create`) both
 * live under `employee.*` and could not be set independently, which is
 * exactly what an owner picking what a role may do needs to do). A code is
 * still collapsed to one of four states for the editor — No access, View,
 * Edit, Full access — `Authorisation::tierOf()` decides that; this class only
 * asks it, per code now rather than per module's worth of them.
 *
 * A code with no natural "edit" or "full" version of itself (a bare view-only
 * permission) still offers all four states; View/Edit/Full all grant it and
 * only No access differs. That is the honest shape of one code, not a gap.
 *
 * Per-artefact codes (`report.<key>.view` and the like) are minted when an
 * artefact is published and never sit in the declared catalogue, so they do
 * not appear here: they are decided per artefact, not per role.
 */
final class RoleMatrix
{
    public const STATES = ['none', 'view', 'edit', 'full'];

    public function __construct(
        private readonly Authorisation $access,
        private readonly PermissionCatalogue $catalogue,
        private readonly ?Customisation $customisation = null,
    ) {
    }

    /** True when this build's catalogue actually declares this code — an editor row must never offer one it does not. */
    public function isDeclared(string $code): bool
    {
        return $code !== '' && in_array($code, $this->catalogue->declaredCodes(), true);
    }

    /** The permission module a code (or a sidebar item's gate) lives in — display/grouping only, never the save key. */
    public static function moduleOf(string $permission): string
    {
        return $permission === '' ? '' : Permission::of($permission)->module();
    }

    /**
     * The state to show this role × code as, for pre-filling the editor.
     *
     * 11 Sep, later still: this used to always answer `Authorisation::
     * tierOf($code)` — a fixed fact about the CODE, never the literal button
     * an owner clicked. For a bare view-tier code (request.view, employee.view
     * and the like, which have no separate Edit or Full grant of their own)
     * that meant picking Edit or Full, saving, and reopening the screen ALWAYS
     * showed View checked again — the save had gone through (the code really
     * was granted), but the screen could not tell the owner which of the three
     * equivalent buttons they had actually pressed, and "it says saved but
     * doesn't show what I saved" is indistinguishable from a lost save. Now
     * the LITERAL state last written for this role × code — read straight off
     * the saved override, not recomputed — is shown when there is one; only a
     * code nobody has ever explicitly set for this role falls back to the
     * shipped default's tier. Nothing about what a state actually GRANTS
     * changed (Authorisation::withOverride() is untouched) — only what the
     * grid honestly reports back.
     */
    public function state(string $role, string $code): string
    {
        $override = $this->customisation?->live()[Authorisation::CUSTOMISATION_KEY] ?? [];
        $saved = (string) ($override[$role][$code] ?? '');

        if (in_array($saved, self::STATES, true)) {
            return $saved;
        }

        // COMPANY ADMIN'S STILL-UNSAVED DEFAULT READS AS "FULL ACCESS" ON
        // EVERY ROW (owner's instruction, 19 Sep), not whichever of
        // View/Edit/Full happens to be that particular code's own natural
        // tier. It already holds every declared code by default
        // (Authorisation's custom-role construction grants this exact role
        // name everything from the start) — this only decides which of the
        // functionally-equivalent labels an still-unsaved cell shows for
        // it, and "Full Access" is the truthful, unambiguous one for a
        // role that already has everything. A row this HAS been saved for
        // (the branch above) is never touched by this — editing one down
        // to View, Edit or No Access and saving still shows exactly that.
        if ($this->access->allows($role, $code) && strtolower($role) === 'company admin') {
            return 'full';
        }

        return $this->access->allows($role, $code) ? Authorisation::tierOf($code) : 'none';
    }

    /**
     * Save a role => code => state grid. Only codes actually posted are
     * touched — a code this screen never offered a row for (not reached from
     * any menu item) is never required and never overwritten, so it keeps
     * whatever the shipped default or an earlier save already gave it. Every
     * posted code must be one the catalogue actually declares and every
     * posted state must be one of the four, or the whole save is refused
     * rather than guessed at.
     *
     * `access.role.update` — the permission that gates editing this very
     * screen — is pinned to 'full' for the administrator role WHEN POSTED:
     * saving anything less would strip whoever is signed in of the ability
     * to open or fully administer this screen again, a lockout this screen
     * must not be able to cause. It normally is never posted at all (no menu
     * item's own gate is `access.role.update`), which pins it just as
     * effectively — the guard below is defence in depth, not the only one.
     *
     * @param array<string, array<string, string>> $grid role => code => state
     * @return array{ok: bool, why: string, version?: int}
     */
    public function save(array $grid, string $byId, string $byLabel, string $note = ''): array
    {
        if ($this->customisation === null) {
            return ['ok' => false, 'why' => 'Customisation storage is not available here.'];
        }

        $roles = $this->access->roles();
        $clean = [];

        foreach ($roles as $role) {
            if (!array_key_exists($role, $grid)) {
                continue; // this role's columns were never on the screen (hidden fields still post them; a genuinely absent role changes nothing for it)
            }

            foreach ((array) $grid[$role] as $code => $state) {
                $code = (string) $code;
                $state = (string) $state;

                if (!$this->isDeclared($code)) {
                    return ['ok' => false, 'why' => sprintf(
                        '"%s" is not a permission this build declares — refused rather than saved blind.', $code,
                    )];
                }

                if (!in_array($state, self::STATES, true)) {
                    return ['ok' => false, 'why' => sprintf(
                        '"%s" for %s / %s is not no-access, view, edit or full access.', $state, $role, $code,
                    )];
                }

                $clean[$role][$code] = $state;
            }
        }

        if (isset($clean['administrator']['access.role.update'])
            && $clean['administrator']['access.role.update'] !== 'full') {
            return ['ok' => false, 'why' => 'The administrator role must keep Full access on '
                . '"access.role.update" — anything else removes every administrator account\'s, '
                . 'including yours, ability to open or fully administer this screen again.'];
        }

        return $this->customisation->saveRaw(Authorisation::CUSTOMISATION_KEY, $clean, $byId, $byLabel, $note);
    }
}
