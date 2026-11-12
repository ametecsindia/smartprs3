<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Access;

/**
 * Steps 5 and 6 of the §XXII.4.1 authorisation pipeline: the permission check
 * and the subject scope check, with the §XXII.13 trace attached to both answers.
 *
 * The absolute rule of this class is R-XXII-095: **there is no code path that
 * returns an allow from an error.** There is exactly one `catch` here, it is the
 * outermost frame, and it denies. SmartPRS 2 has six controls that return
 * "allowed" from a `catch`, one of them under the source comment
 * *"fail open — availability over strictness on pivot errors"*.
 *
 * Every branch below either returns a denial or falls through to the next
 * question. `allow` is returned from exactly one place — the single point where
 * one in-force assignment has been shown to carry both the permission and the
 * record. Reading the file, you can count the allows: there is one.
 *
 * Not in this class, on purpose, because they are other steps of the same
 * pipeline and belong to modules that do not exist yet: assurance (MFA/re-auth,
 * step 4), the commercial gate (step 7), the state gate (step 8), field-level
 * projection (step 10) and the audit write (step 11). Each denies independently.
 * ponytail: wire them in as the pipeline is assembled — but never by adding a
 * bypass here.
 */
final readonly class AccessDecider
{
    public function __construct(private PermissionCatalogue $catalogue)
    {
    }

    /**
     * @param  list<Assignment>  $assignments  the actor's assignments; effective
     *         dating is applied here, so callers pass history and not a filtered
     *         "current" view (R-XXII-029).
     * @param  \DateTimeImmutable  $asOf  the BUSINESS date of the operation, and
     *         required — there is no `now()` in here. "What could this user do on
     *         15 May?" and "may they do it today?" are the same call, and a
     *         re-opened May approval must not be authorised against today's org.
     */
    public function decide(
        array $assignments,
        Permission|string $permission,
        SubjectContext $subject,
        \DateTimeImmutable $asOf,
    ): AccessDecision {
        try {
            return $this->evaluate($assignments, $permission, $subject, $asOf);
        } catch (\Throwable $e) {
            // The only catch in the authorisation path, and it denies (R-XXII-095).
            // R-XXII-094 row 1: an exception anywhere in steps 1–8 is a 403 with
            // an immediate alert. The cause goes into the trace, never into the
            // decision — the actor learns "denied", the operator learns why.
            return AccessDecision::deny(
                AccessDecision::ERROR,
                'Authorisation could not be evaluated, so it denied.',
                ['fault           ' . $e::class . ': ' . $e->getMessage()],
            );
        }
    }

    /** @param list<Assignment> $assignments */
    private function evaluate(
        array $assignments,
        Permission|string $permission,
        SubjectContext $subject,
        \DateTimeImmutable $asOf,
    ): AccessDecision {
        // Permission::of() throws on a malformed code; the outer catch denies.
        $permission = Permission::of($permission);

        $header = [
            'Operation       ' . $permission->code,
            'As at           ' . $asOf->format('d-M-Y') . '  (business date of the operation)',
        ];

        // Step 2 of the pipeline. R-XXII-097: cross-tenant existence is not
        // disclosable, so this is a 404 with NO trace beyond the fact of it.
        if (!$subject->sameTenant() && !$subject->actorInPlatformTenant) {
            return AccessDecision::deny(
                AccessDecision::NOT_FOUND,
                'The record is not in the actor\'s tenant.',
                ['Tenancy         denied before any permission was consulted (R-XXII-097)'],
            );
        }

        // R-XXII-010b / R-XXII-094: an operation bound to a code the catalogue
        // does not carry is a deployment defect. It denies and it alerts; it is
        // never treated as "no permission required".
        if (!$this->catalogue->contains($permission)) {
            return AccessDecision::deny(
                AccessDecision::PERMISSION_UNKNOWN,
                "'{$permission->code}' is not in the permission catalogue.",
                [
                    ...$header,
                    'Catalogue       no declared entry'
                        . ($this->catalogue->isGenerative($permission)
                            ? ' and no published ' . $permission->module() . ' artefact mints it'
                            : '; ' . $permission->module() . '.* is not a reserved generative namespace'),
                    'What to change  ship a catalogue row for this code, or unbind the operation',
                ],
            );
        }

        $composition = new ScopeComposition($assignments);
        $inForce = $composition->inForce($asOf);

        // R-XXII-036: a user with no assignment in force has an EMPTY permission
        // set. They are not an administrator. SmartPRS 2 maps a missing role to
        // the display label 'Admin' and then skips gating for that label.
        if ($inForce === []) {
            return AccessDecision::deny(
                AccessDecision::NO_ASSIGNMENT,
                'The actor holds no role assignment in force on this date.',
                [
                    ...$header,
                    'Assignments     ' . count($assignments) . ' on record, 0 in force',
                    'What to change  grant a role and a scope; an unassigned user has no access',
                ],
            );
        }

        $considered = [];
        $nearMiss = [];

        foreach ($inForce as $index => $assignment) {
            $name = $assignment->label !== '' ? $assignment->label : 'A' . ($index + 1);

            // R-XXII-094: an unresolved role code on an assignment denies and
            // alerts. It does not skip to the next assignment — a defect in the
            // actor's grants is not something another grant may answer for.
            if ($assignment->role === null) {
                return AccessDecision::deny(
                    AccessDecision::ROLE_UNKNOWN,
                    "Assignment {$name} names role '{$assignment->roleCode}', which does not resolve.",
                    [
                        ...$header,
                        "Assignment      {$name} · " . $assignment->describe(),
                        'What to change  this is a deployment or data defect, not a grant to widen',
                    ],
                );
            }

            // Same reasoning for a scope that did not load. An assignment with a
            // null scope is unbounded if you let it through, which is the
            // SmartPRS 2 selfScope() default inverted into a rule.
            if ($assignment->scope === null) {
                return AccessDecision::deny(
                    AccessDecision::SCOPE_MISSING,
                    "Assignment {$name} has no resolvable data scope.",
                    [
                        ...$header,
                        "Assignment      {$name} · " . $assignment->describe(),
                        'What to change  a missing scope is never an unbounded scope',
                    ],
                );
            }

            if (!$assignment->role->grants($permission)) {
                $considered[] = "Not consulted   {$name} (" . $assignment->role . ', scope '
                    . $assignment->scope . ') — does not carry this permission';

                continue;
            }

            // From here the permission axis is satisfied by THIS assignment, so
            // only THIS assignment's scope may answer the record question. This
            // is R-XXII-050, and it is the line that closes the cross-axis leak:
            // no other assignment's scope is consulted for this permission.
            if ($assignment->scope->requiresSubject() && !$subject->isResolvable()) {
                return AccessDecision::deny(
                    AccessDecision::SUBJECT_UNRESOLVABLE,
                    'The subject carries neither an employee nor an org unit, so no scope can place it.',
                    [
                        ...$header,
                        "Assignment      {$name} · " . $assignment->describe(),
                        'What to change  resolve the subject before the check; an unplaceable subject denies',
                    ],
                );
            }

            // ScopeResolutionFailed from here propagates to the outer catch and
            // denies with ACCESS_ERROR. It is deliberately not caught locally:
            // a local catch would have to decide whether to continue the loop,
            // and "continue" is how a broken scope becomes an ignored scope.
            if ($assignment->scope->covers($subject)) {
                return AccessDecision::allow(
                    "Granted by assignment {$name}.",
                    [
                        ...$header,
                        'Permission      ' . $permission->code . ' — held',
                        "  satisfied by  {$name} · role " . $assignment->role,
                        'Data scope      ' . $assignment->scope . ' — satisfied',
                        ...$considered,
                    ],
                );
            }

            $nearMiss[] = "Nearest miss    {$name} · role " . $assignment->role
                . ' carries the permission, but its scope ' . $assignment->scope
                . ' does not reach this record';
        }

        // R-XXII-051: name which axis failed. "Held but out of scope" and "not
        // held at all" are different problems with different fixes, and telling
        // them apart is the whole point of separating the two questions.
        return $nearMiss !== []
            ? AccessDecision::deny(
                AccessDecision::SCOPE_DENIED,
                'The permission is held, but no assignment carrying it reaches this record.',
                [
                    ...$header,
                    'Permission      ' . $permission->code . ' — HELD',
                    'Data scope      NOT SATISFIED',
                    ...$nearMiss,
                    ...$considered,
                    'What to change  a scope that includes this record, or the same role scoped to it',
                ],
            )
            : AccessDecision::deny(
                AccessDecision::PERMISSION_DENIED,
                'No assignment in force carries this permission.',
                [
                    ...$header,
                    'Permission      ' . $permission->code . ' — NOT HELD',
                    ...$considered,
                    'What to change  a role carrying this permission, scoped to this record',
                ],
            );
    }
}
