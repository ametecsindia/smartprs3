<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Access;

use SmartPRS\Platform\Config\PublishCheck;
use SmartPRS\Platform\Config\PublishReport;

/**
 * THE SWEEP THAT MAKES OPERATION BINDING MANDATORY — Part XXII R-XXII-074/075/076.
 *
 * Law 5 says authorisation is server-side and fails closed. Every other law in
 * spine S1 has a mechanical gate in tools/gates/; Law 5 did not, because its
 * check needs the live route list and the permission catalogue rather than a
 * tokeniser. This is that check.
 *
 * The eight assertions of R-XXII-076 in order, plus route coverage (R-XXII-074)
 * and the unauthenticated allow-list (R-XXII-075). Each is a separate recorded
 * outcome, so a failing build names which property broke rather than saying
 * "the sweep failed".
 *
 * Anything the caller did not supply — the route list, the catalogue, the
 * declared maker-checker set — is NOT CHECKED and warns. It is never a pass.
 */
final class OperationSweep
{
    /**
     * @param list<string>|null       $liveRoutes        every registered route, 'METHOD /path'
     * @param list<string>|null       $unauthenticatedAllowList reviewed as a unit
     * @param list<string>|null       $scopedPermissions catalogue rows marked Scoped = yes
     * @param list<string>|null       $privilegedPermissions risk_class privileged or platform
     * @param list<string>|null       $makerCheckerOperations the design declares these maker-checker
     * @param list<string>|null       $writingOperations detected by the write-path decorator in test mode
     */
    public static function run(
        OperationRegistry $registry,
        ?PermissionCatalogue $catalogue = null,
        ?array $liveRoutes = null,
        ?array $unauthenticatedAllowList = null,
        ?array $scopedPermissions = null,
        ?array $privilegedPermissions = null,
        ?array $makerCheckerOperations = null,
        ?array $writingOperations = null,
    ): PublishReport {
        return new PublishReport(
            'operation registry — ' . count($registry->all()) . ' operations',
            [
                self::coverage($registry, $liveRoutes),
                self::unauthenticatedSurface($registry, $unauthenticatedAllowList),
                self::permissionExistence($registry, $catalogue),
                self::subjectResolvability($registry),
                self::scopeParity($registry, $scopedPermissions),
                self::csrfJustification($registry),
                self::privilegedAssurance($registry, $privilegedPermissions),
                self::makerCheckerParity($registry, $makerCheckerOperations),
                self::auditClass($registry, $writingOperations),
            ],
        );
    }

    /** R-XXII-074: a route with no operation fails the build. */
    private static function coverage(OperationRegistry $registry, ?array $liveRoutes): PublishCheck
    {
        $name = 'Operation coverage';

        if ($liveRoutes === null) {
            return PublishCheck::couldNotCheck('OP1', $name,
                'no live route list supplied. This is THE Law 5 assertion — every route resolves to an '
                . 'operation — and it cannot run without the routes the application actually registered.');
        }

        $unbound = [];

        foreach ($liveRoutes as $route) {
            if ($registry->forRoute($route) === null) {
                $unbound[] = $route;
            }
        }

        return $unbound === []
            ? PublishCheck::pass('OP1', $name)
            : PublishCheck::block('OP1', $name, [
                'these routes resolve to no operation: ' . implode(', ', $unbound),
                'There is no default operation and no "public unless stated" (R-XXII-074).',
            ]);
    }

    /** R-XXII-075: the live unauthenticated surface equals the allow-list, exactly. */
    private static function unauthenticatedSurface(OperationRegistry $registry, ?array $allowList): PublishCheck
    {
        $name = 'Unauthenticated allow-list';
        $live = $registry->unauthenticatedRoutes();

        if ($allowList === null) {
            return $live === []
                ? PublishCheck::pass('OP2', $name)
                : PublishCheck::couldNotCheck('OP2', $name,
                    'the registry declares ' . count($live) . ' unauthenticated route(s) and no allow-list was '
                    . 'supplied to compare them against: ' . implode(', ', $live));
        }

        sort($allowList);

        $added = array_values(array_diff($live, $allowList));
        $removed = array_values(array_diff($allowList, $live));
        $issues = [];

        if ($added !== []) {
            $issues[] = 'not on the allow-list: ' . implode(', ', $added)
                . ' — amend the list in the same commit, so the unauthenticated surface is reviewed as a unit';
        }

        if ($removed !== []) {
            $issues[] = 'on the allow-list but no longer unauthenticated: ' . implode(', ', $removed)
                . ' — remove them, or the list stops describing anything';
        }

        return $issues === [] ? PublishCheck::pass('OP2', $name) : PublishCheck::block('OP2', $name, $issues);
    }

    /** R-XXII-076: an operation naming a permission absent from the catalogue. */
    private static function permissionExistence(OperationRegistry $registry, ?PermissionCatalogue $catalogue): PublishCheck
    {
        $name = 'Permission existence';

        if ($catalogue === null) {
            return PublishCheck::couldNotCheck('OP3', $name, 'no permission catalogue supplied');
        }

        $issues = [];

        foreach ($registry->all() as $operation) {
            if ($operation->requiredPermission === '' && $operation->unauthenticated) {
                continue;
            }

            if (!$catalogue->contains($operation->requiredPermission)) {
                $issues[] = "'{$operation->code}' requires '{$operation->requiredPermission}', "
                    . 'which is not in the permission catalogue';
            }
        }

        return $issues === [] ? PublishCheck::pass('OP3', $name) : PublishCheck::block('OP3', $name, $issues);
    }

    /** R-XXII-076: scope_requirement = required with subject_kind = none. */
    private static function subjectResolvability(OperationRegistry $registry): PublishCheck
    {
        $name = 'Subject resolvability';
        $issues = [];

        foreach ($registry->all() as $operation) {
            if ($operation->scopeRequirement !== 'required') {
                continue;
            }

            if ($operation->subjectKind === 'none') {
                $issues[] = "'{$operation->code}' requires a data scope and declares subject_kind 'none' — "
                    . 'there is nothing to scope the decision against, so it would have to pass or fail blindly';
            }

            if ($operation->subjectResolver === null) {
                $issues[] = "'{$operation->code}' requires a data scope and declares no subject_resolver";
            }
        }

        return $issues === [] ? PublishCheck::pass('OP4', $name) : PublishCheck::block('OP4', $name, $issues);
    }

    /** R-XXII-076: scope declaration parity, in both directions. */
    private static function scopeParity(OperationRegistry $registry, ?array $scopedPermissions): PublishCheck
    {
        $name = 'Scope declaration parity';

        if ($scopedPermissions === null) {
            return PublishCheck::couldNotCheck('OP5', $name,
                'the catalogue rows marked Scoped = yes were not supplied, so parity in either direction '
                . 'could not be checked');
        }

        $issues = [];

        foreach ($registry->all() as $operation) {
            $isScoped = in_array($operation->requiredPermission, $scopedPermissions, true);
            $declares = $operation->scopeRequirement === 'required';

            if ($isScoped && !$declares) {
                $issues[] = "'{$operation->code}': permission '{$operation->requiredPermission}' is scoped in "
                    . 'the catalogue and the operation declares scope_requirement not_applicable — the scope '
                    . 'would silently not be applied';
            }

            if (!$isScoped && $declares) {
                $issues[] = "'{$operation->code}': declares scope_requirement required and "
                    . "'{$operation->requiredPermission}' is not a scoped permission";
            }
        }

        return $issues === [] ? PublishCheck::pass('OP5', $name) : PublishCheck::block('OP5', $name, $issues);
    }

    /** R-XXII-076: a CSRF-exempt route with no declared compensating control. */
    private static function csrfJustification(OperationRegistry $registry): PublishCheck
    {
        $name = 'CSRF justification';
        $issues = [];

        foreach ($registry->all() as $operation) {
            if ($operation->csrfExempt && ($operation->csrfExemptReason === null || $operation->csrfExemptReason === '')) {
                $issues[] = "'{$operation->code}' is CSRF-exempt with no declared compensating control. "
                    . "SmartPRS 2's /webhooks/interakt is exempt with no control named, unlike every sibling.";
            }
        }

        return $issues === [] ? PublishCheck::pass('OP6', $name) : PublishCheck::block('OP6', $name, $issues);
    }

    /** R-XXII-076: a privileged permission bound to an operation with no assurance. */
    private static function privilegedAssurance(OperationRegistry $registry, ?array $privileged): PublishCheck
    {
        $name = 'Privileged assurance';

        if ($privileged === null) {
            return PublishCheck::couldNotCheck('OP7', $name,
                'the permissions with risk_class privileged or platform were not supplied');
        }

        $issues = [];

        foreach ($registry->all() as $operation) {
            if (in_array($operation->requiredPermission, $privileged, true) && $operation->assurance === 'none') {
                $issues[] = "'{$operation->code}' is privileged and declares assurance 'none' — "
                    . 'a stolen session is then enough to use it';
            }
        }

        return $issues === [] ? PublishCheck::pass('OP7', $name) : PublishCheck::block('OP7', $name, $issues);
    }

    /** R-XXII-076: an operation the design declares maker-checker with no workflow binding. */
    private static function makerCheckerParity(OperationRegistry $registry, ?array $declared): PublishCheck
    {
        $name = 'Maker-checker parity';

        if ($declared === null) {
            return PublishCheck::couldNotCheck('OP8', $name,
                'the set of operations the design declares maker-checker was not supplied');
        }

        $issues = [];

        foreach ($declared as $code) {
            $operation = $registry->get($code);

            if ($operation === null) {
                $issues[] = "'{$code}' is declared maker-checker and is not in the registry at all";

                continue;
            }

            if ($operation->makerChecker === null || $operation->makerChecker === '') {
                $issues[] = "'{$code}' is declared maker-checker and binds no workflow — "
                    . 'the control exists on paper only';
            }
        }

        return $issues === [] ? PublishCheck::pass('OP8', $name) : PublishCheck::block('OP8', $name, $issues);
    }

    /** R-XXII-076: audit_class none on an operation that writes. */
    private static function auditClass(OperationRegistry $registry, ?array $writing): PublishCheck
    {
        $name = 'Audit class';

        if ($writing === null) {
            return PublishCheck::couldNotCheck('OP9', $name,
                'the write-path decorator did not report which operations write, so an unaudited write '
                . 'could not be looked for');
        }

        $issues = [];

        foreach ($writing as $code) {
            $operation = $registry->get($code);

            if ($operation !== null && $operation->auditClass === 'none') {
                $issues[] = "'{$code}' writes and declares audit_class 'none' — the change would leave no trace";
            }
        }

        return $issues === [] ? PublishCheck::pass('OP9', $name) : PublishCheck::block('OP9', $name, $issues);
    }
}
