<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Access\DataScope;
use SmartPRS\Platform\Access\ScopeResolutionFailed;
use SmartPRS\Platform\Access\SubjectContext;

/**
 * WHOSE ROW IS THIS?
 *
 * Tenant isolation in this product is structural and proven: one customer cannot
 * see another's rows. Isolation WITHIN a tenant did not exist at all. Every
 * signed-in person could read every employee in their own organisation — an
 * audit found seventeen ways, and the worst of them was `?r=me`, the screen
 * titled "what an employee sees", which had no permission check on it and would
 * render anybody's payslip, net pay and complete leave ledger to anybody who
 * changed the sequential integer in the address bar.
 *
 * THE MACHINERY WAS ALREADY HERE. `Platform\Access\DataScope` has thirteen scope
 * types, `covers()` and a SQL `predicate()`; `SubjectContext` carries
 * `actorEmployeeId` and documents it as "users.employee_id"; `Persistence\
 * Repository` compiles the `self` scope to `employee_id = :scope_employee`. All
 * of it tested, none of it called. The surface handed every role
 * `DataScope::tenantWide()` and asked no further questions. This class is the
 * join, not a new idea — it exists so the surface stops having an opinion of its
 * own about who may see what.
 *
 * AN UNBOUND ACCOUNT SEES NOTHING OF ITS OWN, not everything. `DataScope`'s `self`
 * resolves to the EMPTY set when the actor has no employee behind it, and that
 * is deliberately the safe direction: a user with no `employee_id` is somebody
 * whose identity nobody established, and "we could not work out who you are" must
 * never resolve to "so here is everyone".
 *
 * IT IS A SECOND LOCK, NOT THE ONLY ONE. Permissions still gate the screen; this
 * decides which ROWS a person who is allowed on the screen may see. Somebody with
 * `payroll.view` sees any payslip because that is what the permission means;
 * somebody without it sees their own and is told why.
 *
 * A MANAGER SEES THEIR TEAM, AND THEIR TEAM'S TEAMS. The `manager` role in
 * Authorisation holds their team's attendance and leave decisions, and this is
 * where "their team" is decided — again by asking DataScope, which already
 * implements `reporting_tree` and compiles it to a bounded walk of the chain.
 *
 * IT IS THE WHOLE LINE BELOW THEM, not one level (owner, 23 Sep: "this is how
 * the actual hierarchy follows in any organization"). If 1 reports to 2, 2 to 3
 * and 3 to 4, then 2 sees 1; 3 sees 2 and 1; 4 sees 3, 2 and 1. This used to be
 * `direct_reports` — one level — on the argument that a skip-level manager not
 * seeing their reports' reports was "the safe direction to be wrong in". It is
 * the safe direction only if being wrong is acceptable, and it was not: a
 * department head could not approve, read or regularise for anybody but the
 * handful reporting to them directly, which is not what accountability means in
 * an organisation of more than two levels.
 *
 * Changing it HERE changes it everywhere, which is the point of there being one
 * answer to "whose row is this?" — approvals, leave, attendance regularisation,
 * payslips and every picker narrowed by only() all move together. That is also
 * why this is the file to be careful in: there is no second place to fix.
 */
final class Scope
{
    /**
     * @param ?string $actorEmployment `users.employee_id` for the signed-in
     *     account, or null when the account is not linked to an employee
     * @param ?\Closure $reportsTo `fn (string $employmentId): ?string` returning
     *     that employee's `employees.reporting_manager_id` (the real column —
     *     see database/migrations/2026_02_01_000300_port_people_tables.php), or
     *     null when it has no manager on record.
     *
     *     PASSING THIS IS THE GRANT. A Scope built without it reaches `self` and
     *     nothing else, which is what every account had before the manager role
     *     existed; a Scope built with it reaches everybody below the actor in the
     *     reporting line, at every level. The
     *     caller supplies it only for an account whose role carries team reach,
     *     and decides that by asking the decider (`$access->allows($role,
     *     'leave.request.decide')`) — never by comparing a role string.
     */
    /** BEYOND THE REPORTING LINE: this role reads its own branch, and no further. */
    public const BRANCH = 'branch';

    /** BEYOND THE REPORTING LINE: this role reads the whole company. */
    public const COMPANY = 'company';

    /**
     * NOTHING BEYOND THE REPORTING LINE (owner, 28 Sep): an employee or agent
     * sees only themselves; somebody with people under them sees themselves and
     * their whole downline — no colleague outside it, whatever permission they
     * hold. The default for every role not declared otherwise.
     */
    public const LINE = 'line';

    /** @var array<string, list<string>> employment id => its manager chain, for this request only */
    private array $chains = [];

    /** @var array<string, true> employment ids whose manager chain loops, seen this request */
    private array $cycled = [];

    /**
     * @param ?\Closure $branchOf `fn (string $employmentId): string` — the branch
     *     that employee belongs to, as a comparable key (its `branch_id`, or the
     *     legacy text column folded to lower case when there is no id). Empty
     *     string for an employee whose branch nobody has set.
     * @param string $beyondLine what this account may read OUTSIDE its own
     *     reporting line, once a permission has said it reads beyond itself at
     *     all: self::COMPANY or self::BRANCH. It is declared per role on Roles &
     *     Permissions, never inferred from a permission — see
     *     Authorisation::reach() for why that was tried and abandoned.
     */
    public function __construct(
        private readonly ?string $actorEmployment,
        private readonly ?\Closure $reportsTo = null,
        private readonly ?\Closure $branchOf = null,
        private readonly string $beyondLine = self::COMPANY,
        // MORE THAN ONE BRANCH (owner, 8 Oct: "If the designation is set as Branch
        // Admin, that employee should be given option to select multiple branches
        // as the same person may look after multiple branches"). `fn (): list<string>`
        // — the branch keys this account looks after besides its own, from the
        // Directory. Read once, lazily.
        private readonly ?\Closure $extraBranches = null,
    ) {
    }

    /** @var list<string>|null */
    private ?array $myBranches = null;

    /**
     * Every branch this account reaches under BRANCH reach: its own and any it
     * was given on the Directory. Empty when none is known.
     *
     * @return list<string>
     */
    public function myBranches(): array
    {
        if ($this->myBranches === null) {
            $own = $this->branchOf === null || !$this->isBound() ? '' : trim((string) ($this->branchOf)((string) $this->own()));
            $extra = $this->extraBranches === null ? [] : array_map(static fn ($b): string => trim((string) $b), (array) ($this->extraBranches)());
            $this->myBranches = array_values(array_unique(array_filter(array_merge([$own], $extra), static fn (string $b): bool => $b !== '')));
        }

        return $this->myBranches;
    }

    /**
     * Is this account's reach decided by its reporting line at all?
     *
     * TRUE FOR EVERY ACCOUNT THAT IS A PERSON (owner, 24 Sep: "any user who has
     * reporting direct people or the person is reporting and other people are
     * reporting under that... the hierarchy up should be able to see all the
     * downline members"). It used to be true only for an account whose ROLE was
     * declared team-limited, which is why the reporting tree never engaged for
     * Team Lead, Manager or HR Manager on this install: those are custom roles,
     * `Authorisation::reach()` answered `self` for them, so the caller never
     * handed over the resolver and the line was never walked.
     *
     * AN ACCOUNT WITH NOBODY UNDER IT NEEDS NO SPECIAL CASE. `reporting_tree`
     * covers exactly the people whose manager chain contains this account — for
     * somebody nobody reports to, that is nobody, and the answer falls through to
     * their own record plus whatever `$beyondLine` allows. There is nothing here
     * to ask the data about, which is also why this no longer pretends to.
     */
    public function leadsTeam(): bool
    {
        return $this->reportsTo !== null && $this->isBound();
    }

    /**
     * Is this employee BELOW this account in the reporting line — not this
     * account itself?
     *
     * For a screen asking "am I this person's manager?" rather than "may I read
     * this row?". The leave queue asked it with an inline
     * `$emp['reporting_manager_id'] === $own`, which was one level and a private
     * copy of an authorisation rule; this is the same tree every other answer
     * comes from, with the actor's own record taken back out of it.
     */
    public function inMyLine(string $employmentId): bool
    {
        return $employmentId !== '' && $employmentId !== $this->own()
            && $this->lineCovers($employmentId);
    }

    /**
     * Employment ids whose reporting chain was found to loop during this request.
     *
     * @return list<string>
     */
    public function cycledEmployments(): array
    {
        return array_keys($this->cycled);
    }

    /**
     * The platform scope's answer, with a reporting cycle taken as a DENIAL of
     * the line — never as an allow (R-XXII-095), and never as a fatal error.
     *
     * DataScope raises on a cycle, and it is right to: the decider must not
     * guess. But this surface asks once per row of every list, and letting the
     * raise escape turned one bad `reporting_manager_id` into a blank Directory
     * for every account whose own line walked through it (24 Sep: employee 56)
     * — including the administrator who needed that screen to fix the row.
     * Denying the line keeps the rule; the permission-based reach beyond the
     * line still applies, and the defect is recorded for the screen to name.
     */
    private function lineCovers(string $employmentId): bool
    {
        try {
            return $this->scope()->covers($this->subject($employmentId));
        } catch (ScopeResolutionFailed $e) {
            if (!str_contains($e->getMessage(), 'reporting_cycle_detected')) {
                throw $e;
            }

            $this->cycled[$employmentId] = true;

            return false;
        }
    }

    /** What this account may read outside its own line: self::BRANCH or self::COMPANY. */
    public function beyondLine(): string
    {
        return in_array($this->beyondLine, [self::BRANCH, self::COMPANY], true) ? $this->beyondLine : self::LINE;
    }

    /** Is this account linked to an employee at all? */
    public function isBound(): bool
    {
        return $this->actorEmployment !== null && $this->actorEmployment !== '';
    }

    /** The employee this account IS, or null when nobody established that. */
    public function own(): ?string
    {
        return $this->isBound() ? $this->actorEmployment : null;
    }

    /**
     * May this account read this employee's rows?
     *
     * @param bool $mayReadOthers whether the actor holds the permission that
     *     grants sight of employees other than their own
     */
    public function covers(string $employmentId, bool $mayReadOthers): bool
    {
        // THE PERMISSION SAYS "BEYOND YOURSELF". IT DOES NOT SAY "EVERYBODY", and
        // conflating the two is what this branch used to do. `employee.list`,
        // `leave.balance.view_others` and `attendance.day.view.history` are held by
        // the administrator AND by the reporting manager, and for the manager they
        // are meant to reach their team. Short-circuiting on the flag handed the
        // manager the whole tenant the moment the role was declared — the row guard
        // stopped running for exactly the account it was introduced for. A sweep
        // signed in as a manager found nine of them in demo data that has no
        // reporting lines at all, so the correct answer everywhere was "yourself".
        //
        // So the permission decides WHETHER this account reads beyond itself, and
        // the scope decides HOW FAR. An account with no team has no second answer
        // to give, and for it the flag still means the tenant.
        // AN ACCOUNT WITH NOBODY BEHIND IT reads beyond itself only when its
        // role reads the whole company (the administrator). It used to read
        // everything whenever a permission said "others" — so an unlinked
        // Team Lead login saw the whole tenant. Fails closed now.
        if (!$this->isBound()) {
            return $mayReadOthers && $this->beyondLine() === self::COMPANY;
        }

        // THE REPORTING LINE FIRST, AND IT NEEDS NO PERMISSION. Asked of the
        // PLATFORM's scope, not answered here with a string comparison. The
        // comparison would be right today and would not know about a reporting
        // tree the moment one is granted — and a second implementation of an
        // authorisation rule is always the one that gets it wrong.
        if ($this->lineCovers($employmentId)) {
            return true;
        }

        // BEYOND THE LINE IS A DECLARED SETTING, NOT A GUESS. A permission says
        // this account reads past its own record; how far past is the role's own
        // `beyondLine` — the whole company, or the branch it stands in. Without
        // the permission there is no "beyond" to measure at all.
        return $mayReadOthers && $this->reachesOutsideLine($employmentId);
    }

    /**
     * May this account read a record that is NOT in its reporting line?
     *
     * THE ACTOR'S OWN BRANCH IS THE MEASURE, and when nobody has set it this
     * returns TRUE rather than false. That is a deliberate exception to failing
     * closed, and it is the only one here: branch became a required field on
     * employees on 23 Sep, so every employee created before that has none, and a
     * branch scope that failed closed against an unset branch would blank every
     * screen for every one of them on the day it shipped — a lockout wearing the
     * costume of a security control. The Roles & Permissions screen says so out
     * loud rather than letting it be a silent widening, and it stops applying to
     * an account the moment somebody gives that employee a branch.
     */
    private function reachesOutsideLine(string $employmentId): bool
    {
        if ($this->beyondLine() === self::LINE) {
            return false;
        }

        if ($this->beyondLine() === self::COMPANY || $this->branchOf === null) {
            return true;
        }

        $mine = $this->myBranches();

        if ($mine === []) {
            return true;
        }

        return in_array(trim((string) ($this->branchOf)($employmentId)), $mine, true);
    }

    /**
     * The platform scope this account reaches records through.
     *
     * `reporting_tree` at the platform's own maximum depth: everybody below this
     * account in the line, at every level. It was `direct_reports` — one column
     * read per record, no graph to walk, no depth to bound, no cycle to detect —
     * which was cheaper and wrong: a department head could not read, approve or
     * regularise for anybody but the handful reporting to them directly.
     *
     * DataScope needed nothing added for this. It already takes a depth, already
     * walks `managerChain` and already raises on a cycle; the work was
     * `managerChain()` below, not the scope.
     */
    private function scope(): DataScope
    {
        return $this->leadsTeam()
            // includeSelf: a manager is also a person with their own payslip, and
            // "sees the team" must not mean "stops seeing themselves".
            ? DataScope::of('reporting_tree', includeSelf: true,
                depth: DataScope::MAX_REPORTING_DEPTH, key: 'REPORTING_TREE')
            : DataScope::selfOnly();
    }

    /**
     * THE WHOLE LINE ABOVE AN EMPLOYEE, immediate manager first.
     *
     * Bounded by DataScope::MAX_REPORTING_DEPTH, which is also the depth the
     * scope is evaluated at — asking for more links than can ever be read would
     * be work done to be thrown away.
     *
     * NO CYCLE GUARD HERE, DELIBERATELY. A reporting cycle fills the chain with
     * repeats and DataScope::reaches() raises on exactly that (R-XXII-044: "a
     * cycle is a data defect, never a licence to widen scope, so it raises
     * rather than quietly truncating"). Breaking the loop early on a repeat
     * would hide the defect from the one component whose job is to report it,
     * and a hierarchy quietly missing a level is worse than one that says so.
     * The depth bound alone is what stops the walk.
     *
     * Memoised per request: covers() is called once per row by only(), so a
     * fifty-person list walks fifty chains of up to twelve links, and each link
     * is a store read. The same employee's chain is asked for repeatedly within
     * one page and does not change while it renders.
     *
     * @return list<string>
     */
    private function managerChain(string $employmentId): array
    {
        if (isset($this->chains[$employmentId])) {
            return $this->chains[$employmentId];
        }

        $chain = [];
        $at = $employmentId;

        for ($level = 0; $level < DataScope::MAX_REPORTING_DEPTH; $level++) {
            $manager = ($this->reportsTo)($at);

            if (!is_string($manager) || $manager === '') {
                break;
            }

            $chain[] = $manager;
            $at = $manager;
        }

        return $this->chains[$employmentId] = $chain;
    }

    private function subject(string $employmentId): SubjectContext
    {
        return new SubjectContext(
            tenantId: 'surface',
            actorTenantId: 'surface',
            actorEmployeeId: $this->own(),
            employeeId: $employmentId,
            // Immediate manager first, as SubjectContext documents. An employee
            // with no manager on record has an EMPTY chain, not a null in it —
            // an unknown manager is nobody, never anybody.
            //
            // THE WHOLE CHAIN, not one link. This used to pass the immediate
            // manager only, which paired with a `direct_reports` scope to mean a
            // skip-level manager could not see their own reports' reports. That
            // is not how an organisation is shaped: if 1 reports to 2, 2 to 3
            // and 3 to 4, then 3 and 4 are both accountable for 1 and were both
            // being told "that employee does not report to you".
            managerChain: $this->leadsTeam() && $employmentId !== ''
                ? $this->managerChain($employmentId)
                : [],
        );
    }

    /**
     * Why a read was refused, in words somebody can act on.
     *
     * The three refusals are different and must not be collapsed. "That is not
     * your record" is a person looking at a colleague. "This account is not
     * linked to an employee" is an administrator's configuration problem
     * wearing the costume of a permission error, and telling somebody they lack
     * permission when in fact nobody set up their record sends them to the wrong
     * person. "They do not report to you" is a manager who has team reach and is
     * standing outside their team — they are not missing a setting and they are
     * not looking at their own record, and if that reads as either of the other
     * two they go and ask for a permission nobody should give them.
     */
    public function why(string $employmentId): string
    {
        if (!$this->isBound()) {
            return 'This account is not linked to an employee, so there is no "your own" for it '
                . 'to fall back to. That is a setting somebody has to make, not a permission you '
                . 'are missing — an account with nobody behind it sees nothing of its own rather '
                . 'than everything, because "we could not work out who you are" must never resolve '
                . 'to "so here is everyone".';
        }

        if ($this->leadsTeam()) {
            return 'That employee is not in your reporting line'
                . ($this->beyondLine() === self::BRANCH ? ', and not in your branch either' : '')
                . ($this->beyondLine() === self::LINE ? ' — and your role reads nobody outside it' : '')
                . '. Your reach is everybody below you in the line, at every level — the people '
                . 'whose reporting manager you are, the people whose manager THEY are, and so on — '
                . 'plus your own record'
                . ($this->beyondLine() === self::BRANCH
                    ? ', plus your own branch where a permission lets you read past yourself.'
                    : '.')
                . ' If you need this record, that is a reporting line somebody has to set, not a '
                . 'permission to widen.';
        }

        return 'That is somebody else\'s record. You are seeing this rather than a blank screen '
            . 'because knowing a record exists and that it is not yours is different from being '
            . 'told it does not exist.';
    }

    /** Does this account read EVERY employee (no row filter needed)? */
    public function seesEveryone(bool $mayReadOthers): bool
    {
        return $mayReadOthers && $this->beyondLine() === self::COMPANY;
    }

    /**
     * Narrow a list of employee rows to the ones this account may read.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function only(array $rows, bool $mayReadOthers): array
    {
        // Same rule as covers(), and it has to be the same rule: a picker narrowed
        // by a different test from the screen behind it is how the whole staff
        // directory ended up printed above screens that refused every row in it.
        if ($mayReadOthers && $this->beyondLine() === self::COMPANY) {
            return $rows;
        }

        return array_values(array_filter($rows, fn (array $row): bool
            => $this->covers((string) ($row['id'] ?? ''), $mayReadOthers)));
    }
}
