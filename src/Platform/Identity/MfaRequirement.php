<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Identity;

use SmartPRS\Platform\Access\Permission;
use SmartPRS\Platform\Access\Role;

/**
 * Who must hold MFA, and whether this session may perform this operation
 * (R-XXII-194, R-XXII-198; spine S13 "mandatory for privileged roles").
 *
 * The resolution is by **permission risk class, never by role name**. That is
 * the whole design: a tenant can invent a role called "Assistant" that happens
 * to carry `payroll.run.approve`, and it is in scope automatically, because the
 * scope is computed from what the role grants rather than from what it is
 * called. SmartPRS 2 makes 62 authorisation decisions by comparing role *name*
 * strings, which is the failure mode this inverts.
 *
 * **Denied, not warned.** A holder of a `privileged` or `platform` permission
 * who has not enrolled does not get a banner; `assess()` returns a reason code
 * and the operation does not happen. R-XXII-198 is explicit that the session is
 * never silently upgraded — the API says `MFA_REQUIRED` and the client prompts.
 *
 * The grace period of R-XXII-194 governs something else, and the two are easy
 * to confuse: during grace a privileged holder can still *sign in* and enrol,
 * and their **assignment** suspends at the end of it. It never lets a
 * privileged *operation* through without MFA. `assignmentSuspendsAt()` is the
 * grace period; `assess()` is the operation gate; they do not interact.
 */
final readonly class MfaRequirement
{
    public const RISK_ROUTINE = 'routine';
    public const RISK_SENSITIVE = 'sensitive';
    public const RISK_PRIVILEGED = 'privileged';
    public const RISK_PLATFORM = 'platform';

    /** R-XXII-194 — the classes that mandate enrolment. */
    public const MANDATORY_RISK_CLASSES = [self::RISK_PRIVILEGED, self::RISK_PLATFORM];

    /** The actor holds a mandating permission and has enrolled no factor. */
    public const NOT_ENROLLED = 'MFA_NOT_ENROLLED';

    /** Enrolled, but this session has not presented the factor (R-XXII-198). */
    public const REQUIRED = 'MFA_REQUIRED';

    /** The operation declares `assurance = reauth(n)` and the session is stale. */
    public const REAUTH_REQUIRED = 'REAUTH_REQUIRED';

    /**
     * @param  array<string, string>  $riskClassByPermission  permission code =>
     *         risk class. This is `permissions.risk_class_code` (R-XXII-011),
     *         which the shipped catalogue file does not yet carry as a column —
     *         so it is passed in, and `seededRiskClasses()` is the interim
     *         source. Depended on **by shape**, not by owner.
     * @param  list<string>  $mandatoryRiskClasses
     * @param  list<string>  $enforcedRoleCodes  R-XXII-194's `enforced_for_roles[]`
     *         — additional roles a tenant puts in scope by hand, on top of the
     *         automatic privileged set. It may only add.
     * @param  int  $gracePeriodDays  D-XXII-06: mandatory at v3.0 with a
     *         configurable, modest grace period, and assignment SUSPENSION
     *         rather than deletion at expiry.
     */
    public function __construct(
        private array $riskClassByPermission,
        public array $mandatoryRiskClasses = self::MANDATORY_RISK_CLASSES,
        public array $enforcedRoleCodes = [],
        public int $gracePeriodDays = 14,
    ) {
        foreach ($mandatoryRiskClasses as $class) {
            if (!in_array($class, [self::RISK_ROUTINE, self::RISK_SENSITIVE, self::RISK_PRIVILEGED, self::RISK_PLATFORM], true)) {
                throw new \InvalidArgumentException("'{$class}' is not a permission risk class (R-XXII-011).");
            }
        }

        if ($gracePeriodDays < 0) {
            throw new \InvalidArgumentException('A negative MFA grace period is not a shorter grace period.');
        }
    }

    /**
     * The risk classes the blueprint's §XXII.2.2 catalogue table marks `P` and
     * `p`, transcribed.
     *
     * ponytail: an explicit map rather than a prefix rule. `platform.*` is not
     * reliably platform-class (`platform.support.read` is not), and inferring a
     * risk class from a code's spelling is how a new permission silently lands
     * outside MFA enforcement. Delete this and read the column the day
     * `permissions.risk_class_code` ships; until then it is the honest subset,
     * and a code missing from it is treated as `routine` — which is why the
     * catalogue column, not this map, is the real answer.
     *
     * @return array<string, string>
     */
    public static function seededRiskClasses(): array
    {
        $platform = [
            'platform.tenant.list', 'platform.tenant.create', 'platform.tenant.suspend',
            'platform.tenant.impersonate', 'platform.staff.manage', 'platform.release.publish',
            'platform.licence.issue', 'platform.rulepack.sign', 'platform.audit.read',
            'ops.backup.restore.test',
        ];

        $privileged = [
            'tenant.settings.update', 'tenant.subscription.manage',
            'access.user.create', 'access.user.update', 'access.user.disable',
            'access.user.credential.reset', 'access.user.session.revoke', 'access.user.mfa.reset',
            'access.role.create', 'access.role.update', 'access.role.publish',
            'access.assignment.grant', 'access.assignment.revoke',
            'access.apikey.issue', 'access.apikey.revoke',
            'employee.record.archive', 'employee.record.delete', 'employee.bank.update',
            'employee.salary.update', 'employee.import.run',
            'org.unit.deactivate', 'master.credential.update',
            'attendance.bulk.upload', 'attendance.bulk.decide', 'attendance.day.recalculate',
            'attendance.device.manage',
            'leave.balance.adjust', 'leave.encashment.decide',
            'payroll.run.create', 'payroll.run.submit', 'payroll.run.approve', 'payroll.run.reject',
            'payroll.run.lock', 'payroll.run.reopen', 'payroll.line.decide',
            'payroll.payslip.publish', 'payroll.bankfile.generate', 'payroll.disburse.mark',
            'payroll.arrears.manage', 'payroll.structure.publish',
            'statutory.config.publish', 'statutory.rulepack.accept', 'compliance.gate.override',
            'request.money.confirm', 'loan.sanction', 'loan.schedule.manage',
            'commission.scheme.publish', 'bankpack.generate',
            'rules.artefact.publish', 'schema.field.manage', 'workflow.definition.publish',
            'integration.connector.manage', 'ops.job.retry',
        ];

        return [
            ...array_fill_keys($platform, self::RISK_PLATFORM),
            ...array_fill_keys($privileged, self::RISK_PRIVILEGED),
        ];
    }

    public function riskClassOf(Permission|string $permission): string
    {
        // Unknown means routine, and that is a deliberate, stated default: the
        // risk class of an uncatalogued code is a catalogue defect for
        // PermissionCatalogue to deny on (R-XXII-010b), not something to guess
        // at here. This class never *grants*; it only ever adds a requirement.
        return $this->riskClassByPermission[Permission::of($permission)->code] ?? self::RISK_ROUTINE;
    }

    public function mandatesEnrolment(Permission|string $permission): bool
    {
        return in_array($this->riskClassOf($permission), $this->mandatoryRiskClasses, true);
    }

    /**
     * Which of this actor's permissions put them in scope — the list the
     * enrolment notice names, so "why am I being told to set up MFA?" has an
     * answer that is not "ask your administrator".
     *
     * @param  list<Role>  $roles
     * @return list<string>
     */
    public function mandatingPermissions(array $roles): array
    {
        $codes = [];

        foreach ($roles as $role) {
            $byRoleCode = in_array($role->code, $this->enforcedRoleCodes, true);

            foreach ($role->permissionCodes() as $code) {
                if ($byRoleCode || $this->mandatesEnrolment($code)) {
                    $codes[$code] = true;
                }
            }
        }

        ksort($codes);

        return array_keys($codes);
    }

    /** @param list<Role> $roles */
    public function isMandatoryFor(array $roles): bool
    {
        return $this->mandatingPermissions($roles) !== [];
    }

    /**
     * The assurance gate for one operation.
     *
     * @param  list<Role>  $roles  the actor's roles, as resolved for this request
     * @param  bool  $enrolled  does the actor hold any MFA factor?
     * @param  bool  $sessionSatisfiedMfa  did THIS session present one?
     *         R-XXII-210: a remember-me token must never make this true.
     * @param  ?int  $reauthSecondsRequired  the operation's `assurance = reauth(n)`
     * @param  ?int  $secondsSinceReauth  NULL when the session has never re-authed
     * @return ?string  NULL when the operation may proceed; a reason code otherwise
     */
    public function assess(
        array $roles,
        bool $enrolled,
        bool $sessionSatisfiedMfa,
        Permission|string|null $operation = null,
        ?int $reauthSecondsRequired = null,
        ?int $secondsSinceReauth = null,
    ): ?string {
        $mandatory = $this->isMandatoryFor($roles)
            || ($operation !== null && $this->mandatesEnrolment($operation));

        if ($mandatory && !$enrolled) {
            // Denied, not warned. A privileged actor with no second factor is
            // exactly the account credential stuffing is looking for.
            return self::NOT_ENROLLED;
        }

        if ($mandatory && !$sessionSatisfiedMfa) {
            return self::REQUIRED;
        }

        if ($reauthSecondsRequired !== null
            && ($secondsSinceReauth === null || $secondsSinceReauth > $reauthSecondsRequired)) {
            return self::REAUTH_REQUIRED;
        }

        return null;
    }

    /**
     * R-XXII-194 / edge case 13: at this instant the assignment is **suspended**,
     * not deleted, the user keeps their non-privileged assignments, and both the
     * user and the grantor are notified. Reinstatement on enrolment is automatic.
     */
    public function assignmentSuspendsAt(\DateTimeImmutable $becameMandatoryAt): \DateTimeImmutable
    {
        return $becameMandatoryAt->modify('+' . $this->gracePeriodDays . ' days');
    }
}
