<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Access\AccessDecider;
use SmartPRS\Platform\Access\AccessDecision;
use SmartPRS\Platform\Access\Assignment;
use SmartPRS\Platform\Access\DataScope;
use SmartPRS\Platform\Access\Permission;
use SmartPRS\Platform\Access\PermissionCatalogue;
use SmartPRS\Platform\Access\Role;
use SmartPRS\Platform\Access\SubjectContext;

/**
 * AUTHORISATION, THROUGH THE REAL DECIDER.
 *
 * Every action in the application asks `Platform\Access\AccessDecider` — the
 * same class the permission tests and the route-policy sweep exercise. There is
 * no `if ($user['role'] === 'administrator')` anywhere in the surface, and that
 * absence is the point: a role check written inline is a second authorisation
 * implementation, and the second one is always the one that gets it wrong.
 *
 * IT FAILS CLOSED, and not by convention. `AccessDecider::decide()` has the only
 * catch in the authorisation path and that catch DENIES — an exception anywhere
 * in the decision becomes a refusal with the cause in the trace, so the actor
 * learns "denied" and the operator learns why. Nothing here can turn that off.
 *
 * The role definitions below are seeded demo data. Which permissions a role
 * grants is configuration in the real product; what is NOT configuration is the
 * decision procedure, and that lives entirely in Platform.
 */
final class Authorisation
{
    /**
     * Where an owner-edited grid (surface/screens/roles.php) is kept — one
     * versioned Customisation key, the same mechanism the menu and the
     * employee-field register already use. `role => code => 'view' | 'edit'
     * | 'full' | 'none'` — ONE INDIVIDUAL PERMISSION CODE AT A TIME (10 Sep:
     * moved off `role => module => state`, which silently entangled every
     * code sharing a module's first segment — "Directory" and "Add Employee"
     * both live under `employee.*` and are genuinely different screens; a
     * module-wide grid could not set one without the other). A role or code
     * missing from it keeps the shipped default below — an empty override,
     * or a code the screen never offers a row for, changes nothing. This is
     * Law 5 read correctly, not broken: the decision is still made by
     * AccessDecider from a materialised Role, still catalogue-checked, still
     * audited — only the GRANTED-CODES input is now configuration (the
     * governing principle at the top of CLAUDE.md) behind its own
     * permission, `access.role.update`, rather than a PHP edit.
     */
    public const CUSTOMISATION_KEY = 'access:role-permissions';
    private const PUNCH = 'ess.attendance.punch';

    /**
     * WHERE THE PER-ROLE REACH IS KEPT — `role => 'branch' | 'company'`, the one
     * setting that says how much of the organisation a role reads OUTSIDE its own
     * reporting line. Edited on Roles & Permissions, versioned like every other
     * customisation, and read by `reach()` below. A role missing from it keeps
     * `defaultReach()`.
     */
    public const REACH_KEY = 'access:role-reach';

    /**
     * MENU ITEMS THAT WERE OPEN TO EVERYONE (owner, 22 Oct 2026). Granted to every role by default — nobody loses
     * what they had — and switchable per role on Roles & Permissions; app.php refuses the screen when switched off.
     */
    public const MENU_CODES = ['home.dashboard.view', 'salary.live.view', 'request.inbox.view', 'notification.inbox.view',
        'assistant.view', 'help.howitworks.view', 'asset.mine.view', 'onboarding.self.view', 'leave.approvals.view'];

    /** The 3 tiers this build ships, keyed the same way `$customRoles` entries would collide on. */
    private const BUILT_IN = ['administrator', 'manager', 'viewer'];

    private readonly AccessDecider $decider;

    /** @var list<string> every permission code the catalogue declares */
    private array $declaredCodes = [];

    /** @var array<string, list<Assignment>> */
    private array $assignments;

    /**
     * @param list<string> $customRoles Every distinct, non-blank Role value from
     *     Organization → Role/Designation Setup (`orgNames($store)['designation_roles']`
     *     in `public/app.php`, deduped and trimmed there) — ROLES, not just designations
     *     grouped under one of the 3 built-in tiers. Each becomes its own independently
     *     permissioned entry on this screen and its own assignable value for a login's
     *     `users.status`, exactly like administrator/manager/viewer already are, with
     *     ONE difference: a role the owner has not yet visited Roles & Permissions for
     *     starts with NO grants at all (Law 5: fails closed) rather than a hand-picked
     *     default — there is no sensible built-in default for a role nobody but the
     *     owner defined. `reach()` below defaults an unrecognised role to 'self' for the
     *     same reason; a real per-role reach setting is not built yet (flagged in
     *     docs/session-handoff.md §7), so a brand-new custom role reads no wider than
     *     its own account until that exists.
     */
    public function __construct(private readonly ?Customisation $customisation = null, array $customRoles = [])
    {
        $catalogue = PermissionCatalogue::seeded();
        $this->decider = new AccessDecider($catalogue);
        $this->declaredCodes = array_values(array_map('strval', $catalogue->declaredCodes()));

        // ONE ROLE PER NAME, WHATEVER THE CASE (28 Sep): "HR Admin" and "HR admin"
        // typed on two designations were two roles with two permission columns.
        // The first spelling met is the role; later spellings fold into it.
        $byLower = [];

        foreach ($customRoles as $customRole) {
            $customRole = trim((string) $customRole);
            $customRole !== '' && ($byLower[strtolower($customRole)] ??= $customRole);
        }

        $customRoles = array_values($byLower);

        $administrator = [
            'employee.view', 'employee.create', 'employee.update',
            // THE FIRST FIVE-WAY SPLIT OF A SHARED CODE (10 Sep, owner's
            // instruction): Directory, Probation, Teams, Transfers and ID
            // Cards used to share `employee.view` in the Roles & Permissions
            // grid, so the grid could only set one state for all five.
            // Granted here ADDITIVELY, alongside `employee.view` which stays
            // (Part X/XX/XXIV still check it directly) — nobody's access
            // changes today; only the editor's granularity does. See
            // docs/22-PART-XXII-roles-security-tenancy.md's new
            // "per-screen splits" subsection for why these five and not a
            // module-wide code.
            'employee.directory.view', 'employee.probation.view', 'employee.team.view',
            'employee.transfer.view', 'employee.idcard.view',
            'payroll.run', 'payroll.view',
            'payroll.structure.view', 'payroll.structure.publish', 'payroll.run.approve',
            // The FINANCE signature on a payroll run (config/payroll-approval.json step 2).
            'payroll.payout.authorise',
            'attendance.day.view', 'attendance.punch.view', 'attendance.punch.create.manual',
            // PUNCH IN / OUT from the web app (Time & Attendance → Punch): everybody
            // punches their own day; the action decides the direction server-side.
            'ess.attendance.punch',
            // THE ROW-SCOPE GUARDS EACH NAME ONE OF THESE, and until this line they
            // were granted to NOBODY. The names were all checked against the
            // catalogue when the guards went in — and existing in the catalogue is
            // not the same as being held by a role, so every guard fell to its
            // self-only branch for every account in the product, administrator
            // included. An HR administrator could not open a colleague's leave, and
            // no test said so: a guard that refuses everybody refuses the attacker
            // too, and every security sweep read that as a pass. It took a sweep
            // signed in as somebody who SHOULD see the colleague to find it, which
            // is the whole reason `--tenant-wide` exists.
            'employee.list', 'employee.document.view',
            // Additive split of `employee.document.view` (17 Sep, owner's
            // instruction): HR Letters used to share it with Document
            // Tracker, so granting a custom "HR Manager" role letters
            // access also handed it every uploaded employee document.
            // `employee.document.view` stays granted here unchanged —
            // nobody's access changes today; only the editor's granularity
            // does, same as the five-way split above.
            'employee.letters.view',
            'leave.balance.view_others', 'attendance.day.view.history',
            'leave.request.view', 'leave.request.submit', 'leave.request.decide',
            'leave.balance.view',
            // Customising a module changes what a screen MEANS for everybody, so
            // it sits behind the tenant's settings permission rather than the
            // module's own edit permission: filling a row in and redefining the
            // field are not the same act, and giving them one name is how the
            // second gets granted by somebody who meant the first.
            'tenant.settings.view', 'tenant.settings.update',
            // Letters that print a salary need this ON TOP of employee.update —
            // an offer letter and a warning letter are both "a letter", and
            // treating them as one permission is how somebody who may issue
            // warnings ends up able to print everybody's CTC.
            'employee.salary.view',
            // CHANGING A SALARY (21 Sep, owner hit it on Performance Appraisal →
            // Apply: "Denied: No assignment in force carries this permission").
            // Declared in the catalogue, checked by Apply, and held by NOBODY —
            // the same "declared but unheld" trap named twice above, so the
            // screen refused everybody, administrator included.
            'employee.salary.update',
            // The audit trail is every act in the tenant with the actor's name and
            // the payload on it — a salary change reads back off it in full. It had
            // NO permission on it at all, which made it the widest read in the
            // product behind the narrowest door: being signed in.
            'audit.tenant.read',
            // The approvals list prints who asked for what and for how much. The
            // single request behind it derives its permission from the module it is
            // about; the LIST had none, so the amounts were readable by anybody.
            'request.view',
            // ACCOUNTS ARE NOT SETTINGS. The accounts screen could have been hung
            // off `tenant.settings.update`, which the administrator already holds,
            // and it would have worked — but "may change the company's address" and
            // "may make somebody an administrator" are different powers, and the
            // day they need separating is the day somebody discovers they were the
            // same permission all along. Granted here and to no other role: a
            // manager decides their team's leave, not who exists.
            'access.user.list', 'access.user.create', 'access.user.update',
            // Loading a device's punch file writes attendance, which becomes payable
            // days on a payslip — so it sits with the administrator and not with the
            // manager who approves their team's leave. Granted here rather than left
            // in the catalogue unheld: a permission no role holds turns its screen
            // into a refusal for everybody, which every security sweep reads as a
            // pass. That has already happened twice in this file.
            'attendance.punch.ingest',
            // A SHEET OF ATTENDANCE IS A DEVICE FILE TYPED BY A PERSON. Loading one and
            // approving one are two permissions on purpose — the uploader is the
            // supervisor whose register it is, the approver is whoever answers for
            // payroll — and this build grants both to the one role that exists. A
            // STATED ceiling, like regularisation's, not a silent one.
            'attendance.bulk.upload', 'attendance.bulk.decide',
            // The muster reads what the register reads; who it shows is Scope's job.
            'attendance.report.view',
            // HIRING. Raising and working the pipeline is HR's; approving a requisition
            // (`recruitment.pipeline.publish`) is the act that commits headcount and money,
            // and sits with the administrator like every other approval in this build.
            'recruitment.requisition.view', 'recruitment.requisition.create', 'recruitment.pipeline.view',
            'recruitment.pipeline.manage', 'recruitment.pipeline.publish', 'recruitment.candidate.view',
            'recruitment.candidate.edit', 'recruitment.candidate.import', 'recruitment.interview.manage',
            // MINTING A CREDENTIAL IS NOT A SETTING. A key issued here pushes
            // attendance into payroll and reads the employee list without a
            // session, a cookie or a CSRF token — none of the accidental
            // protection a browser brings applies to it. `tenant.settings.update`
            // would have worked and is one word shorter, and "may change the
            // company's address" and "may mint a key that writes attendance" are
            // different powers. Administrator only: a manager decides their team's
            // leave, not what may talk to the API.
            'access.apikey.list', 'access.apikey.issue', 'access.apikey.revoke',
            // WHO MAY EDIT THIS VERY GRID (surface/screens/roles.php). Declared
            // in the catalogue since XXII but never granted to anybody until the
            // grid existed to grant it from — the same "declared but unheld"
            // trap the comment above the requisition codes already names.
            'access.role.update',
        ];

        // A REPORTING MANAGER. It sits between viewer and administrator and the
        // gap on either side is the point: it can decide its team's leave, which
        // viewer cannot, and it cannot see a salary or a payroll run, which the
        // administrator can. Every code below was checked against
        // src/Platform/Access/catalogue.php — the two ways to get this wrong have
        // both already happened here once: a code the catalogue does not carry
        // denies everybody including the administrator (PERMISSION_UNKNOWN), and
        // a code no role holds turns its guard into a refusal of the whole world,
        // which every security sweep reads as a pass.
        //
        // No payroll.*, no employee.salary.view: "who reports to me" and "what
        // they are paid" are different questions, and a manager needing the
        // second is a decision somebody makes deliberately, not one that arrives
        // free with the first.
        $manager = [
            'employee.view', 'employee.list',
            // Additive split of `employee.view` — see the administrator
            // array above for why. The manager held employee.view before
            // this, so it holds all five new per-screen codes now.
            'employee.directory.view', 'employee.probation.view', 'employee.team.view',
            'employee.transfer.view', 'employee.idcard.view',
            'attendance.day.view', 'attendance.day.view.history', 'attendance.punch.view',
            'attendance.report.view', 'ess.attendance.punch',
            'leave.request.view', 'leave.request.submit', 'leave.request.decide',
            'leave.balance.view', 'leave.balance.view_others',
        ];

        // A LOGIN WITH NO DESIGNATION IS SELF-ONLY (owner, 13 Nov 2026): the same menus as
        // the Employee/Agent role (config/role-defaults.json), plus punching their own day.
        // Once a designation is set, that designation's access applies instead; a saved
        // Roles & Permissions tick for Viewer still wins over this default.
        $viewer = array_merge(['ess.attendance.punch'], self::defaultsFor('Employee/Agent'));

        // AN OWNER-EDITED GRID, LAYERED OVER THE SHIPPED DEFAULT — same shape as
        // Navigation's 'nav:sidebar' override. Missing entirely (nobody has ever
        // saved the roles screen) or missing a role/code inside it: that role
        // or code keeps the default above, so an empty override changes nothing.
        $override = (array) ($this->customisation?->live()[self::CUSTOMISATION_KEY] ?? []);

        // ADMINISTRATOR HAS NO RESTRICTIONS (owner, 7 Oct 2026, D-OWN-01): every declared
        // code, and no override on the Roles grid can take one away.
        $administrator = array_values(array_unique(array_merge($administrator, $this->declaredCodes)));
        $manager = array_merge($manager, self::MENU_CODES);
        $managerDefault = $manager; // the reporting-manager tier's shipped grants, reused by config/role-defaults.json `preset`
        $manager = self::withOverride($manager, (array) ($override['manager'] ?? []));
        $viewer = self::withOverride($viewer, (array) ($override['viewer'] ?? []));
        // PUNCH FOR EVERYONE (owner, 13 Nov 2026): a grid saved before the rule cannot take it away; the geo-fence is the exception.
        $manager[] = self::PUNCH;
        $viewer[] = self::PUNCH;

        $administrator = Role::publish('administrator', 'Administrator', 1, true, $administrator, catalogue: $catalogue);
        $manager = Role::publish('manager', 'Reporting manager', 1, false, $manager, catalogue: $catalogue);
        $viewer = Role::publish('viewer', 'Viewer', 1, false, $viewer, catalogue: $catalogue);

        $tenantWide = DataScope::tenantWide();

        $this->assignments = [
            'administrator' => [Assignment::of($administrator, $tenantWide, label: 'Administrator')],
            // ponytail: the manager's ROW reach is Surface\Scope's direct_reports,
            // not this scope. `decide()` below is asked "may this role do this
            // verb?" with no subject in hand, and a `direct_reports` scope on the
            // assignment requiresSubject() — so it would answer
            // SUBJECT_UNRESOLVABLE to every permission and the manager would hold
            // nothing at all. This class is the permission axis; Scope.php is the
            // record axis; they are the two orthogonal questions of S13 and this
            // line is where they are kept apart. Upgrade path: give decide() the
            // subject it is deciding about, then this becomes the real scope.
            'manager' => [Assignment::of($manager, $tenantWide, label: 'Reporting manager')],
            'viewer' => [Assignment::of($viewer, $tenantWide, label: 'Viewer')],
        ];

        // EVERY OTHER ROLE THE OWNER HAS ACTUALLY TYPED INTO ORGANIZATION →
        // ROLE/DESIGNATION SETUP — one real, independently-permissioned role
        // per distinct Role value, not a display grouping under one of the 3
        // tiers above. A name colliding (case-insensitively) with a built-in
        // tier's key or label is skipped rather than shadowing it — "Administrator"
        // typed as a designation's Role stays the one real administrator tier,
        // never a second, differently-permissioned role of the same name.
        $builtInLabels = ['administrator', 'reporting manager', 'manager', 'viewer', 'employee (viewer)'];

        foreach (array_unique($customRoles) as $customRole) {
            $customRole = trim($customRole);

            if ($customRole === '' || in_array(strtolower($customRole), $builtInLabels, true)) {
                continue;
            }

            // "COMPANY ADMIN" DEFAULTS TO EVERYTHING, NOT NOTHING (owner's
            // instruction, 19 Sep). Every other custom role still fails
            // closed exactly as before — nobody has visited Roles &
            // Permissions for it yet, so it starts with zero grants — but a
            // role by this exact name (their own Role/Designation Setup's
            // highest-level entry, Level 10 in their example) is meant to
            // be the tenant's own full-access role from the moment it
            // exists; waiting for someone to click through and grant it
            // every permission by hand first is a worse default than
            // starting fully open and dialling back what shouldn't be
            // there. A saved per-code state in $override still narrows
            // this exactly as it would any other role — see
            // withOverride()'s 'none' branch — so editing individual rows
            // and saving still works the same way afterward.
            // READY-MADE ROLES (owner, 6 Nov 2026: an HR Admin in a new company saw only employee menus):
            // config/role-defaults.json `preset` gives a role nobody has set up yet the Company Admin's full access or
            // the reporting manager's; what Roles & Permissions has saved for a code still wins (withOverride below).
            $preset = self::presetFor($customRole);
            $defaultGranted = strtolower($customRole) === 'company admin' || $preset === 'full' ? $catalogue->declaredCodes()
                : ($preset === 'manager' ? array_values(array_unique(array_merge($managerDefault, self::defaultsFor($customRole)))) : self::defaultsFor($customRole));
            $grants = [...self::withOverride($defaultGranted, (array) ($override[$customRole] ?? [])), self::PUNCH];
            $published = Role::publish($customRole, $customRole, 1, false, $grants, catalogue: $catalogue);
            $this->assignments[$customRole] = [Assignment::of($published, $tenantWide, label: $customRole)];
        }
    }

    public function decide(string $role, string $permission): AccessDecision
    {
        return $this->decider->decide(
            $this->assignments[$role] ?? [],
            $permission,
            new SubjectContext(tenantId: 'T-DEMO', actorTenantId: 'T-DEMO'),
            new \DateTimeImmutable(),
        );
    }

    public function allows(string $role, string $permission): bool
    {
        return $this->decide($role, $permission)->allowed;
    }

    /**
     * HOW FAR THIS ROLE READS, as a declaration rather than an inference.
     *
     * The surface needs to know whether an account is limited to its own team, and
     * the tempting way to work that out is to look at a permission — "an account
     * that may decide leave has a team". That was tried and it was wrong within an
     * hour: the administrator may decide leave too, so it was demoted to its own
     * team and stopped being able to see anybody, on three screens, silently. A
     * permission answers "may they do this verb"; it was never going to answer "and
     * how much of the organisation is theirs", and asking it to is how one fact
     * gets stored in two places that then disagree.
     *
     * `self` is the safe default for a role nobody listed here, for the same reason
     * everything else in this file fails closed.
     */
    public function reach(string $role): string
    {
        $saved = (array) ($this->customisation?->live()[self::REACH_KEY] ?? []);
        $chosen = strtolower(trim((string) ($saved[$role] ?? '')));

        if (in_array($chosen, [Scope::LINE, Scope::BRANCH, Scope::COMPANY], true)) {
            return strtolower(trim($role)) === 'administrator' ? Scope::COMPANY : $chosen;
        }

        return self::defaultReach($role);
    }

    /**
     * What a role reads beyond its own line when nobody has chosen yet.
     *
     * The administrator is the tenant by definition — it is the account that
     * configures everything, including this. EVERY OTHER ROLE DEFAULTS TO ITS OWN
     * BRANCH, which is the owner's instruction of 23 Sep ("Company Admin and HR
     * Admin / HR Manager can be with the option All Branches or the entire
     * company wide. All other roles should have branch assigned") and is also the
     * safe direction: a role nobody has configured reads less, not more.
     *
     * A role whose people have no branch on record is not blanked by that — see
     * Scope::reachesOutsideLine() for the one deliberate exception and why it is
     * stated on the screen rather than left silent.
     */
    public static function defaultReach(string $role): string
    {
        $role = strtolower(trim($role));

        if ($role === 'administrator') {
            return Scope::COMPANY;
        }

        // DECLARED, NOT TYPED (Law 1): config/role-reach.json lists which roles
        // read the company or their branch out of the box; everybody else reads
        // only their own reporting line (owner, 28 Sep). A missing or broken
        // file fails closed to the line.
        static $declared = null;
        $file = dirname(__DIR__) . '/config/role-reach.json';
        $declared ??= (is_file($file) ? json_decode((string) file_get_contents($file), true) : null) ?: [];

        foreach ([Scope::COMPANY, Scope::BRANCH] as $level) {
            foreach ((array) ($declared[$level] ?? []) as $name) {
                if (strtolower(trim((string) $name)) === $role) {
                    return $level;
                }
            }
        }

        return Scope::LINE;
    }

    /**
     * Save the per-role reach grid. `role => 'branch' | 'company'`.
     *
     * Its own customisation key rather than a corner of the permissions record:
     * the two are edited on one screen and are different facts, and a grid of
     * permission states that also carried a reach string would have to be
     * defended against every consumer of that record which reasonably expects
     * `role => code => state` all the way down.
     *
     * @param array<string, string> $reach
     * @return array{ok: bool, why: string, version?: int|string}
     */
    public function saveReach(array $reach, string $byId, string $byLabel, string $note = ''): array
    {
        if ($this->customisation === null) {
            return ['ok' => false, 'why' => 'Customisation storage is not available here.'];
        }

        $clean = [];

        foreach ($reach as $role => $value) {
            $role = trim((string) $role);
            $value = strtolower(trim((string) $value));

            if ($role === '' || !in_array($role, $this->roles(), true)) {
                continue;
            }

            if (!in_array($value, [Scope::LINE, Scope::BRANCH, Scope::COMPANY], true)) {
                return ['ok' => false, 'why' => sprintf(
                    '"%s" for %s is not one of "%s", "%s" or "%s" — refused rather than saved blind.',
                    $value, $role, Scope::LINE, Scope::BRANCH, Scope::COMPANY,
                )];
            }

            // THE ADMINISTRATOR IS NOT NARROWABLE FROM THIS SCREEN. It is the role
            // that configures the product, and an administrator who has narrowed
            // themselves to a branch cannot always widen themselves back — the
            // same reasoning that keeps `access.role.update` at Full for it.
            if (strtolower($role) === 'administrator') {
                $value = Scope::COMPANY;
            }

            $clean[$role] = $value;
        }

        return $this->customisation->saveRaw(self::REACH_KEY, $clean, $byId, $byLabel, $note);
    }

    /**
     * MAY THIS ROLE DECIDE SOMETHING IT RAISED OR CLOSED ITSELF — owner's
     * instruction (12 Sep 2026): the company administrator may approve or
     * sign anything pending for the tenant — a leave request, a finance
     * request, a payroll run — even one the administrator itself raised or
     * closed, because on a genuinely one-person account that is the only
     * signature there is. `Register::approve()` and `Requests::decide()`
     * keep the ordinary refusal (Law 5: fails closed) for every other role:
     * a chain the requester can complete alone is a form, not a control,
     * and staying a control is the whole reason the check exists there at
     * all — this is the single, named exception to it, not its removal.
     *
     * Same shape as `reach()` above, for the same reason: a role-keyed fact
     * belongs in exactly one place, declared, not inlined at every call
     * site that needs it.
     */
    public function maySelfApprove(string $role): bool
    {
        return $role === 'administrator';
    }

    /**
     * MAY SOMEBODY IN $actorRole GIVE A LOGIN $targetRole? (28 Sep.) Only the
     * administrator, or a role holding `access.role.update`, may hand out any
     * role. Anybody else who manages users may only hand out a role that can do
     * nothing they cannot — otherwise a user manager makes an administrator.
     */
    public function mayGrant(string $actorRole, string $targetRole): bool
    {
        if ($actorRole === 'administrator' || $this->allows($actorRole, 'access.role.update')) {
            return true;
        }

        if ($targetRole === 'administrator') {
            return false;
        }

        foreach ($this->declaredCodes as $code) {
            if ($this->allows($targetRole, $code) && !$this->allows($actorRole, $code)) {
                return false;
            }
        }

        return true;
    }

    /** The declared role name for any spelling of it ('hr admin' → 'HR Admin'), or '' when none. */
    public function canonicalRole(string $role): string
    {
        foreach ($this->roles() as $known) {
            if (strcasecmp($known, trim($role)) === 0) {
                return $known;
            }
        }

        return '';
    }

    /** @return list<string> the roles this build declares */
    public function roles(): array
    {
        return array_keys($this->assignments);
    }

    /** @return list<string> the permissions this build knows about, for the screen */
    public function granted(string $role): array
    {
        $granted = [];

        foreach (['employee.view', 'employee.create', 'employee.update',
            'attendance.day.view', 'attendance.punch.create.manual',
            'leave.request.submit', 'leave.request.decide',
            'payroll.run', 'payroll.view',
            'payroll.structure.view', 'payroll.structure.publish',
            'payroll.run.approve'] as $permission) {
            if ($this->allows($role, $permission)) {
                $granted[] = $permission;
            }
        }

        return $granted;
    }

    /**
     * A code counts as VIEW-tier for the roles screen's editor when it only
     * lets somebody look — `view`, `list`, `read` or a `view_…` qualifier
     * (`leave.balance.view_others`).
     */
    public static function isViewTier(string $code): bool
    {
        foreach (Permission::of($code)->segments as $segment) {
            if ($segment === 'view' || $segment === 'list' || $segment === 'read'
                || str_starts_with($segment, 'view')) {
                return true;
            }
        }

        return false;
    }

    /** The words that make a code FULL-tier: destructive or administrative, never routine data entry. */
    private const FULL_TIER_WORDS = ['delete', 'revoke', 'reset', 'suspend', 'lock', 'override',
        'waive', 'write_off', 'archive', 'purge', 'impersonate', 'cancel'];

    /**
     * A code counts as FULL-tier — the top of the four-state grid — when it
     * removes, revokes or otherwise administers rather than records: deleting
     * a row, revoking a key, overriding a decision. Ordinary create/update/
     * submit/approve/manage codes are EDIT-tier, one step below.
     */
    public static function isFullTier(string $code): bool
    {
        foreach (Permission::of($code)->segments as $segment) {
            foreach (self::FULL_TIER_WORDS as $word) {
                if ($segment === $word || str_starts_with($segment, $word)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Which of the four states a code belongs to: 'view', 'edit' or 'full'.
     * "Edit" on the grid grants a module's view- and edit-tier codes; "Full
     * access" additionally grants its full-tier codes; "View" grants only
     * the view tier; "No access" grants none of it.
     */
    public static function tierOf(string $code): string
    {
        if (self::isViewTier($code)) {
            return 'view';
        }

        return self::isFullTier($code) ? 'full' : 'edit';
    }

    /**
     * Lay a saved code => 'view'|'edit'|'full'|'none' grid over one role's
     * shipped default grant list, ONE PERMISSION CODE AT A TIME. A code
     * absent from `$states` is untouched — the default stands — so a grid
     * saved before the catalogue grew a code does not silently lock it to
     * nothing, and a code the screen never offers a row for (never reached
     * from any menu item) can never be touched by this grid at all.
     *
     * 'view'/'edit'/'full' all grant a code that is ITSELF only view-tier —
     * there is no separate "edit version" of a single view-only permission,
     * so for such a code the three states are equivalent to "granted"; only
     * 'none' differs. That is not a bug: it is the honest shape of a single
     * code, not a module's worth of them.
     *
     * @param list<string> $defaultGranted
     * @param array<string, string> $states code => state
     * @return list<string>
     */
    /**
     * A NEW ROLE'S SHIPPED GRANTS (owner, 6 Nov 2026): the menu codes plus config/role-defaults.json `every_role`
     * (own leave — "No assignment in force carries this permission" on Leave in a new company), minus any
     * `not_by_default` code naming this role (AI Assistant off for the Employee profile). Roles & Permissions wins.
     */
    /** 'full', 'manager' or '' — config/role-defaults.json `preset`, names matched ignoring case, spaces and punctuation. */
    private static function presetFor(string $role): string
    {
        $file = dirname(__DIR__) . '/config/role-defaults.json';
        $cfg = (is_file($file) ? json_decode((string) file_get_contents($file), true) : null) ?: [];
        $norm = static fn (string $x): string => (string) preg_replace('/[^a-z0-9]/', '', strtolower($x));

        foreach ((array) ($cfg['preset'] ?? []) as $preset => $roles) {
            if (in_array($norm($role), array_map(static fn ($r): string => $norm((string) $r), (array) $roles), true)) {
                return (string) $preset;
            }
        }

        return '';
    }

    private static function defaultsFor(string $role): array
    {
        static $cfg = null;
        $file = dirname(__DIR__) . '/config/role-defaults.json';
        $cfg ??= (is_file($file) ? json_decode((string) file_get_contents($file), true) : null) ?: [];
        $norm = static fn (string $s): string => (string) preg_replace('/[^a-z0-9]/', '', strtolower($s));
        $codes = array_values(array_unique(array_merge(self::MENU_CODES, array_map('strval', (array) ($cfg['every_role'] ?? [])))));

        foreach ((array) ($cfg['not_by_default'] ?? []) as $code => $roles) {
            if (in_array($norm($role), array_map(static fn ($r): string => $norm((string) $r), (array) $roles), true)) {
                $codes = array_values(array_diff($codes, [(string) $code]));
            }
        }

        return $codes;
    }

    private static function withOverride(array $defaultGranted, array $states): array
    {
        $set = array_fill_keys($defaultGranted, true);

        foreach ($states as $code => $state) {
            $code = (string) $code;

            // A KEY LEFT OVER FROM BEFORE 10 SEP — this override used to be
            // keyed by bare module name ("attendance"), not a full code
            // ("attendance.day.view"); `Permission::of()` THROWS for
            // anything that is not a real 2-to-5-segment code, and that
            // exception was reaching every page in the product (Authorisation
            // is built on every request), not just this screen. A stale key
            // was never in $defaultGranted either, so the safe reading is
            // exactly "this entry grants nothing" — validated up front, once,
            // rather than letting it surface deeper inside tierOf/isViewTier.
            try {
                Permission::of($code);
            } catch (\InvalidArgumentException) {
                continue;
            }

            unset($set[$code]);

            if ($state === 'full') {
                $set[$code] = true;
            } elseif ($state === 'edit' && self::tierOf($code) !== 'full') {
                $set[$code] = true;
            } elseif ($state === 'view' && self::isViewTier($code)) {
                $set[$code] = true;
            }
            // 'none' (or anything else unrecognised): the code stays removed.
        }

        return array_keys($set);
    }
}
