<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Leave\LeaveLedger;
use SmartPRS\Platform\Leave\LeaveLedgerEntry;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\RoundingMode;

/**
 * A LEAVE REQUEST, AND A BALANCE THAT IS A SUM.
 *
 * The balance is never a stored column. It is `SUM(credits) − SUM(debits)` over
 * the ledger at the date asked about, computed by `Platform\Leave\LeaveLedger`.
 * A stored balance is right on the day it is written and wrong the first time a
 * back-dated approval, a reversal or a lapse lands — and the customer finds out
 * when an employee's leave card disagrees with their payslip.
 *
 * A REQUEST IS NOT A LEDGER ENTRY. Requesting leave moves nothing: the days are
 * only debited when somebody with `leave.request.decide` approves it, and the
 * debit carries the request id as its `sourceReference` so the movement can be
 * traced back to the decision that caused it. Debiting on submission and
 * crediting back on rejection is the same arithmetic and a much worse audit
 * trail — the ledger would show days that were never taken.
 *
 * A REJECTED REQUEST NEVER TOUCHES THE LEDGER AT ALL, and a cancelled approval
 * is a REVERSAL entry rather than a deletion. Deleting the debit gives the same
 * balance and destroys the evidence that leave was approved and then withdrawn.
 */
final class Leave
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';

    private const REQUESTS = 'leaves';   // the ported table
    private const ENTRIES = 'leave_entries';

    public function __construct(
        private readonly RowStore $store,
        private readonly ?LeavePolicy $policy = null,
        // THE PUBLISHED LEAVE RULES. Optional so this class still runs
        // standalone — and when it is absent `request()` says so and refuses,
        // rather than accepting everything the way it used to.
        private readonly ?LeaveRules $rules = null,
        // WHICH DAYS OF A SPAN ACTUALLY COST. Absent, the fallback below counts
        // Monday to Friday and says so on the screen — see chargeableDays().
        private readonly ?ChargeRules $chargeRules = null,
        // The working calendar the charge rules read. Both or neither: charge
        // rules without a calendar produce unknowns for every day, which is
        // correct and useless.
        private readonly ?Calendar $calendar = null,
        // WHAT THE PERSON IS TOLD. Absent, nothing is queued and nothing
        // pretends to have been — a decision that notifies nobody is the
        // behaviour this class had until now, and it should not be silent about
        // continuing.
        private readonly ?Notifications $notifications = null,
    ) {
    }

    /**
     * How many days a span costs, and why each day did or did not count.
     *
     * `workingDaysBetween()` — Monday to Friday, in PHP — is still here and is
     * now NAMED as a fallback rather than being the answer. Two things it got
     * wrong: a holiday inside the span was charged, so somebody who took the week
     * of a festival paid for the festival out of their own leave; and a tenant
     * whose weekly off is Sunday only got Saturdays free, because the code's idea
     * of a week was not theirs.
     *
     * @return array{days: int, charged: list<array{date: string, rule: string}>,
     *     exempt: list<array{date: string, reason: string, rule: string}>,
     *     undecided: list<array{date: string, why: string}>, why: ?string, trace: ?string}
     */
    public function chargeableDays(string $employmentId, string $type, string $from,
        string $to): array
    {
        if ($this->chargeRules === null) {
            return [
                'days' => self::workingDaysBetween($from, $to),
                'charged' => [],
                'exempt' => [],
                'undecided' => [],
                'why' => 'No charge rules are loaded, so this span was counted Monday to Friday '
                    . 'without reading the working calendar — a holiday inside it has been charged '
                    . 'and a weekly off the tenant declares elsewhere has not been honoured.',
                'trace' => null,
            ];
        }

        // THE EMPLOYEE, so a holiday scoped to one company/branch/role/
        // designation charges correctly for this person rather than as if
        // every holiday reached everybody. Not found (a bad employment id, or
        // no employees table on this store): charged unscoped, same as before.
        $employment = [];

        try {
            foreach ($this->store->all('employees') as $e) {
                if (Employment::code($e) === $employmentId) {
                    $employment = $e;

                    break;
                }
            }
        } catch (\Throwable) {
            // gate:allow-swallow no employees table on a file store: charged unscoped
        }

        return $this->chargeRules->chargeFor($employmentId, $type, $from, $to, $this->calendar,
            $employment, $this->companyNames(), $this->designationRoles());
    }

    /** @var array<string, string>|null company id => name, read once — for holiday scope matching */
    private ?array $companyNamesCache = null;

    /** @return array<string, string> company id => name */
    private function companyNames(): array
    {
        if ($this->companyNamesCache !== null) {
            return $this->companyNamesCache;
        }

        $names = [];

        try {
            foreach ($this->store->all('companies') as $c) {
                $names[(string) ($c['id'] ?? '')] = trim((string) ($c['name'] ?? ''));
            }
        } catch (\Throwable) {
            // gate:allow-swallow no companies table on a file store: company scope matches nobody
        }

        return $this->companyNamesCache = $names;
    }

    /** @var array<string, string>|null designation id => role name, read once — for holiday scope matching */
    private ?array $designationRolesCache = null;

    /**
     * @return array<string, string> designation id => its Role — Role/
     * Designation Setup's own Role column, never `employees.role` (the RBAC
     * access tier).
     */
    private function designationRoles(): array
    {
        if ($this->designationRolesCache !== null) {
            return $this->designationRolesCache;
        }

        $roles = [];

        try {
            foreach ($this->store->all('designations') as $d) {
                $roles[(string) ($d['id'] ?? '')] = trim((string) ($d['role'] ?? ''));
            }
        } catch (\Throwable) {
            // gate:allow-swallow no designations table on a file store: role scope matches nobody
        }

        return $this->designationRolesCache = $roles;
    }

    /**
     * Days already committed to OTHER requests still awaiting a decision.
     *
     * The rule engine needs this to answer `leave.pending`, and without it an
     * employee with five days can submit five separate five-day requests: every
     * one passes a balance check on its own, and the ledger finds out when the
     * last is approved.
     */
    public function pendingDays(string $employmentId, string $type, ?string $excludeRequestId = null): Rational
    {
        $pending = Rational::of(0);

        foreach ($this->requestsFor($employmentId) as $request) {
            if ($request['state'] !== self::PENDING || (string) $request['leave_type'] !== $type
                || ($excludeRequestId !== null && (string) $request['id'] === $excludeRequestId)) {
                continue;
            }

            // fromDecimalString, not (int): a half-day request is 0.5 (10 Oct).
            $pending = $pending->plus(Rational::fromDecimalString(self::decimal($request['days'])));
        }

        return $pending;
    }

    /**
     * Open every type the policy credits on joining. The AMOUNTS come from the
     * policy, not from this file — an opening entitlement is a business decision.
     *
     * A type this employee is not eligible for (wrong marital status or gender —
     * see LeavePolicy::eligible()) is skipped: crediting Maternity Leave to an
     * employee who can never see or request it, because they are not Married and
     * Female, would sit on the ledger as a number nobody can explain. Called
     * again for someone already credited, it is a no-op for what already posted
     * (each reference names itself) and picks up anything newly eligible — a
     * marital-status/gender correction, or a policy change that starts crediting
     * a type in full — without needing a fresh hire event to trigger it.
     *
     * ponytail: eligibility here only checks the fixed facts (marital status,
     * gender), not a type's Company/Branch/Role/Designation scope — that would
     * need the same org-name resolution app.php's leaveScopeContext() does,
     * which this class has no access to. None of the shipped types combine a
     * nonzero opening balance with that scope today; add the resolution here
     * (an injected closure, the same shape Scope's $reportsTo already is) the
     * day one does.
     */
    public function openBalances(string $employmentId, string $joinedOn): void
    {
        if ($this->policy === null) {
            return;
        }

        $employment = $this->eligibilityContext($employmentId);

        foreach ($this->policy->types() as $code => $type) {
            if (Rational::fromDecimalString((string) $type['opening_days'])->isZero()) {
                continue;
            }

            if (!$this->policy->eligible((string) $code, $employment)) {
                continue;
            }

            $this->credit($employmentId, (string) $code, (string) $type['opening_days'],
                LeaveLedger::OPENING, 'Opening balance on joining',
                self::reference($employmentId, "opening:{$code}"), $joinedOn);
        }
    }

    /**
     * The employee row with its org NAMES filled from the ids, which is what
     * LeavePolicy::eligible() matches a type's scope against.
     *
     * @return array<string, mixed>
     */
    private function eligibilityContext(string $employmentId): array
    {
        $employment = $this->findEmployment($employmentId) ?? [];

        // ELIGIBILITY BY THE EMPLOYEE'S CURRENT ORG NAMES, read from the ids
        // (28 Sep). The raw row has no company name at all and — for anybody
        // added through the Directory — a blank branch text, so a company- or
        // branch-scoped type never credited a new joiner's opening balance.
        $employment['company'] = (string) ($this->companyNames()[(string) ($employment['employer_company_id'] ?? '')] ?? ($employment['company'] ?? ''));

        foreach (['branch' => 'branches', 'department' => 'departments', 'designation' => 'designations', 'team' => 'teams'] as $key => $table) {
            $id = trim((string) ($employment[$key . '_id'] ?? ''));

            if ($id !== '') {
                try {
                    $unit = $this->store->find($table, $id);
                } catch (\Throwable) {
                    $unit = null; // a register this store does not have names nothing
                }

                $name = trim((string) ($unit['name'] ?? ''));
                $name !== '' && $employment[$key] = $name;
            }
        }

        $employment['role'] = (string) ($this->designationRoles()[(string) ($employment['designation_id'] ?? '')] ?? ($employment['role'] ?? ''));

        return $employment;
    }

    /** The raw `employees` row for this code, or null if none has it. */
    private function findEmployment(string $employmentId): ?array
    {
        foreach ($this->store->all('employees') as $employment) {
            if (Employment::code($employment) === $employmentId) {
                return $employment;
            }
        }

        return null;
    }

    /**
     * Post monthly accrual up to and including the month `$asOf` falls in.
     *
     * Idempotent: each posting names itself, so a page that accrues on load is
     * safe to refresh. A balance that grows every time somebody looks at it is
     * the kind of bug found six months later by an employee who is pleased.
     *
     * @return list<string> the postings made, for the screen to show
     */
    public function accrueThrough(string $employmentId, string $joinedOn, string $asOf): array
    {
        if ($this->policy === null) {
            return [];
        }

        $posted = [];
        // ONLY A TYPE THIS EMPLOYEE HAS (12 Oct): a leave type scoped to another
        // company accrued for everybody, so an Ametecs employee's ledger also
        // grew ABC Recoveries' EL — hidden on screen, but spent by F&F encashment.
        $employment = $this->eligibilityContext($employmentId);

        foreach ($this->policy->types() as $code => $type) {
            $rate = Rational::fromDecimalString((string) $type['accrual_days_per_month']);

            if ($rate->isZero() || !$this->policy->eligible((string) $code, $employment)) {
                continue;
            }

            $cap = Rational::fromDecimalString((string) $type['maximum_days']);
            $month = (new \DateTimeImmutable($joinedOn))->modify('first day of this month');
            $last = (new \DateTimeImmutable($asOf))->modify('first day of this month');

            while ($month <= $last) {
                $stamp = $month->format('Y-m');
                $reference = self::reference($employmentId, "accrual:{$code}:{$stamp}");
                $effectiveOn = $month->format('Y-m-t');

                // THE CAP STOPS ACCRUAL. It does not truncate what is already
                // earned — a screen refresh must never take away days somebody
                // worked for.
                $balance = $this->balance($employmentId, (string) $code,
                    new \DateTimeImmutable($effectiveOn));

                if ($balance->compareTo($cap) >= 0) {
                    $month = $month->modify('+1 month');

                    continue;
                }

                if ($this->credit($employmentId, (string) $code,
                    (string) $type['accrual_days_per_month'], LeaveLedger::ACCRUAL,
                    "Monthly accrual for {$stamp}", $reference, $effectiveOn)) {
                    $posted[] = $reference;
                }

                $month = $month->modify('+1 month');
            }
        }

        return $posted;
    }

    /**
     * Debit encashed days, naming the settlement that caused it.
     *
     * A DEBIT, not a deletion of the credits. The ledger keeps saying the days
     * were earned AND that they were paid out, which are two facts an employee
     * asks about separately.
     */
    public function encash(string $employmentId, string $type, string $days, string $on,
        string $reason, string $reference): bool
    {
        return $this->credit($employmentId, $type, $days, LeaveLedger::ENCASHED, $reason,
            self::reference($employmentId, "encash:{$reference}:{$type}"), $on);
    }

    /**
     * WHOSE ENTRY THIS IS, IN THE REFERENCE ITSELF.
     *
     * `credit()` treats an entry as already posted when the EMPLOYMENT and the
     * reference both match — which is the right rule. The database disagreed: the
     * unique index on `leave_entries` is `(tenant_id, source_reference)`, with no
     * employee in it. So the references read `opening:CL` and `accrual:CL:2026-01`,
     * naming the leave type and the month but not the person.
     *
     * The in-memory check passed for a second employee, because it compares the
     * employee; the INSERT then collided with the first employee's row, because
     * the index does not. Adding the SECOND employee to a tenant fataled with a
     * duplicate-key error, and the employee row had already landed — so it left a
     * person on the books with no opening leave balance and a 500 on the screen.
     *
     * Invisible on the file store, which has no unique index at all, and found by
     * an agent adding a second employee while doing something else entirely.
     *
     * The reference now carries the employee, which makes a tenant-wide index
     * mean the same thing the code means. `tests/leave_reference_test.php` asserts
     * every reference this class writes contains the employee, so the next
     * reference format somebody invents cannot quietly drop it.
     */
    private static function reference(string $employmentId, string $what): string
    {
        return $employmentId . ':' . $what;
    }

    /**
     * Post an EARNED credit once — a comp-off, today; anything the attendance
     * rules decide a day is owed, tomorrow.
     *
     * Public because the earning happens in attendance and the ledger lives
     * here, and the alternative was for `Attendance` to write `leave_entries`
     * rows itself: a second writer to a ledger whose whole value is that one
     * thing writes it. Idempotent by reference, exactly like accrual, so the
     * same day cannot credit twice however many times somebody presses the
     * button.
     *
     * REFUSES A TYPE THE POLICY DOES NOT DECLARE. A credit posted to an
     * undeclared type is a balance no screen shows and no payroll spends — it is
     * not a smaller feature than the real thing, it is a day somebody has earned
     * and cannot find.
     */
    /**
     * Post a year-end LAPSE — a debit of what did not carry forward.
     *
     * A debit, never a deletion: the ledger keeps saying the days were granted
     * and that they lapsed, which are the two things an employee asks about
     * when the January balance is smaller than the December one.
     */
    public function lapse(string $employmentId, string $type, string $days, string $on,
        string $reason, string $reference): bool
    {
        return $this->credit($employmentId, $type, $days, LeaveLedger::LAPSED, $reason,
            self::reference($employmentId, $reference), $on);
    }

    public function creditEarned(string $employmentId, string $type, string $days,
        string $reason, string $reference, string $on): bool
    {
        if ($this->policy !== null && !array_key_exists($type, $this->policy->types())) {
            throw new \InvalidArgumentException(
                "config/leave-policy.json declares no leave type '{$type}', so a credit posted to it "
                . 'would be days nobody can see or spend. Declare the type first.'
            );
        }

        return $this->credit($employmentId, $type, $days, LeaveLedger::EARNED, $reason,
            self::reference($employmentId, $reference), $on);
    }

    /** Post an entry once. Returns false if this exact posting already exists. */
    private function credit(string $employmentId, string $type, string $days, string $kind,
        string $reason, string $reference, string $effectiveOn): bool
    {
        // The legacy form is the same reference without the employee on the
        // front — rows written before the fix. Matched so that re-running an
        // accrual over an existing ledger does not post a second copy of an entry
        // that is already there under the old name.
        $legacy = str_contains($reference, ':')
            ? substr($reference, strpos($reference, ':') + 1)
            : $reference;

        foreach ($this->entriesOf($employmentId) as $entry) {
            if (in_array((string) ($entry['source_reference'] ?? ''), [$reference, $legacy], true)) {
                return false;
            }
        }

        $this->putEntry([
            'employment_id' => $employmentId,
            'leave_type' => $type,
            'kind' => $kind,
            'days' => $days,
            'effective_on' => $effectiveOn,
            'reason' => $reason,
            'source_reference' => $reference,
        ]);

        return true;
    }

    /**
     * What the published rules say about a request that has not been made yet.
     *
     * Public because the SCREEN needs the same answer the write path gets — a
     * form that offers a button and then refuses the submission is a form that
     * knew and did not say. One evaluation, two callers, no second opinion.
     *
     * @return array{allowed: bool, undecided: bool, blocks: list<array{code: string, reason: string,
     *     rule: string}>, documents: list<array{type: string, by: string, rule: string}>,
     *     why: string, trace: ?string}
     */
    public function validate(string $employmentId, string $type, string $from, string $to,
        ?int $days = null, ?string $excludeRequestId = null, bool $inHalves = false): array
    {
        // THE CHARGEABLE COUNT, not the calendar-blind one. What the balance
        // rules compare against has to be the same number the balance is
        // actually reduced by, or a request for a week containing a holiday is
        // refused on a figure nobody would ever be charged.
        $days ??= $this->chargeableDays($employmentId, $type, $from, $to)['days'];
        $asOf = new \DateTimeImmutable($from);

        if ($this->rules === null) {
            return [
                'allowed' => false,
                'undecided' => true,
                'blocks' => [['code' => 'POLICY_NOT_LOADED', 'rule' => '',
                    'reason' => 'No leave rules were given to this class, so nothing has checked this '
                        . 'request. That is reported rather than waved through: a validator that did '
                        . 'not run and one that found nothing wrong look identical from here.']],
                'documents' => [],
                'why' => 'No leave rules are loaded, so this request has not been checked.',
                'trace' => null,
            ];
        }

        $type_ = $this->policy?->types()[$type] ?? [];

        return $this->rules->validate($employmentId, $from, [
            'leave.type' => $type,
            'leave.units' => $days,
            'leave.from' => $from,
            'leave.to' => $to,
            // KEYED BY THE TYPE, because the symbol is. `leave.balance` is a
            // keyed accessor in the decision point's table — the rules ask
            // `leave.balance(leave.type)` and the engine looks the answer up
            // under the rendered key, so writing the bare name here resolves to
            // nothing and the comparison poisons rather than passing.
            // IN HALVES for a half-day request (12 Oct): the rules compare whole
            // units, so a 0.5 request against a 0.5 balance was 1 > 0 and refused.
            // Counting units, balance and pending all in halves is the same rule.
            "leave.balance('{$type}')" => $this->balance($employmentId, $type, $asOf)
                ->multipliedBy(Rational::of($inHalves ? 2 : 1))->toInt(RoundingMode::Down),
            // THE REQUEST BEING APPROVED IS NOT "OTHER PENDING" LEAVE: counting
            // it against itself refused an approver narrowing five days to three.
            "leave.pending('{$type}')" => $this->pendingDays($employmentId, $type, $excludeRequestId)
                ->multipliedBy(Rational::of($inHalves ? 2 : 1))->toInt(RoundingMode::Down),
            "leave.entitlement('{$type}')" => (int) ($type_['opening'] ?? 0),
            'leave.is_paid' => in_array($type, $this->policy?->paidCodes() ?? [], true),
            'leave.is_statutory' => (bool) ($type_['statutory'] ?? false),
        ]);
    }

    /** The two halves of a day a half-day leave can be (10 Oct). Stored as the reason's prefix. */
    public const HALVES = ['first' => 'First half', 'second' => 'Second half'];

    /**
     * Which half a HALF-DAY request is, or null for whole days. A half day is a
     * one-date request of 0.5 days; the half is the reason's "[First half] " prefix.
     *
     * ponytail: the half lives in the reason text (no migration); a `half` column
     * on `leaves` if anything ever needs to query by it.
     */
    public static function halfOf(array $request): ?string
    {
        if (!Rational::fromDecimalString(self::decimal($request['days'] ?? '0'))->equals(Rational::of(1, 2))) {
            return null;
        }

        foreach (self::HALVES as $k => $label) {
            if (str_starts_with((string) ($request['reason'] ?? ''), '[' . $label . ']')) {
                return $k;
            }
        }

        return 'first';
    }

    private static function decimal(mixed $v): string
    {
        $v = trim((string) $v);

        return preg_match('/^\d+(\.\d+)?$/', $v) === 1 ? $v : '0';
    }

    public function request(string $employmentId, string $type, string $from, string $to,
        string $reason, ?string $half = null): array
    {
        $charge = $this->chargeableDays($employmentId, $type, $from, $to);
        $days = $charge['days'];

        // HALF DAY (owner, 10 Oct): one date, which must be a chargeable day, costs 0.5.
        if ($half !== null) {
            if (!isset(self::HALVES[$half])) {
                throw new \InvalidArgumentException('A half day is either the first half or the second half.');
            }

            if ($from !== $to) {
                throw new \InvalidArgumentException('A half-day leave is for one date: From and To must be the same day.');
            }

            if ($days !== 1) {
                throw new \InvalidArgumentException('That date is a weekly off or a holiday, so there is no half day to take leave for.');
            }
        }

        // A SPAN WITH AN UNDECIDED DAY HAS NO TRUSTWORTHY TOTAL, and writing it
        // anyway would put a request in the queue for a number of days nobody
        // computed. Refused with the reason, which is the same discipline the
        // balance check follows.
        if ($charge['undecided'] !== []) {
            throw new \InvalidArgumentException((string) $charge['why']);
        }

        if ($days <= 0) {
            throw new \InvalidArgumentException(
                'A leave request that spans no days is a request for nothing. Check the dates.'
            );
        }

        if (trim($reason) === '') {
            throw new \InvalidArgumentException(
                'A leave request carries a reason. The approver is deciding about a person\'s time, and '
                . '"approved" against a blank field is a decision nobody can review later.'
            );
        }

        // THE BALANCE CHECK THE PRODUCT DID NOT HAVE. Until this call, the two
        // guards above were the whole of validation: an employee with three days
        // of earned leave could ask for forty, the row was written, and it
        // joined the approvals queue looking exactly like every other one — with
        // the balance nowhere on the screen the approver reads.
        //
        // The policy is config/leave-rules.json and the decision is the rule
        // engine's, so "how many days may somebody go over" is a file a customer
        // edits and not a release. Refused BEFORE the write, because a request
        // that exists and should not is a thing somebody now has to withdraw.
        // A half day is ONE unit in halves (balance and pending doubled to match).
        $decision = $half !== null
            ? $this->validate($employmentId, $type, $from, $to, 1, null, true)
            : $this->validate($employmentId, $type, $from, $to, $days);

        if (!$decision['allowed']) {
            throw new \InvalidArgumentException($decision['why']);
        }

        if ($half !== null) {
            $days = '0.5';
            $reason = '[' . self::HALVES[$half] . '] ' . $reason;
        }

        // THE SCHEMA'S NAMES, and only those. This wrote `employment_id`, `from`,
        // `to`, `state` and `requested_at` — none of them columns on `leaves` —
        // so submitting a request against MySQL threw, while the file store
        // accepted every one of them for months. A read can fall back to an old
        // name; a WRITE cannot, because writing the old name is the fault.
        return $this->ported($this->store->put(self::REQUESTS, [
            'employee_id' => $this->employeeIdOf($employmentId),
            'type_id' => $this->typeIdOf($type),
            'from_date' => $from,
            'to_date' => $to,
            'days' => (string) $days,
            'reason' => $reason,
            'status' => self::PENDING,
            'decided_by' => null,
            'decided_at' => null,
            'decision_note' => null,
            // Given rather than left to the store's default so the file store
            // and MySQL order requests the same way — `requestsFor()` sorts on
            // it, and a row with no timestamp sorts to the bottom for ever.
            'created_at' => date('c'),
        ]));
    }

    /**
     * Approve or reject. The ledger moves ONLY on approval.
     *
     * @throws \InvalidArgumentException on a decision that cannot have happened
     */
    public function decide(string $requestId, bool $approve, string $by, string $note,
        ?string $from = null, ?string $to = null): array
    {
        // Ported on the way in: this method read `state` off the raw row, which
        // on MySQL is `status` — so a decided request looked pending and could
        // be decided twice.
        $request = $this->ported($this->store->find(self::REQUESTS, $requestId)
            ?? throw new \InvalidArgumentException('No such leave request.'));

        if ($request['state'] !== self::PENDING) {
            throw new \InvalidArgumentException(
                "This request is already {$request['state']}. A second decision on a decided request is "
                . 'either a duplicate submission or two approvers acting on stale screens, and neither '
                . 'should silently overwrite the first.'
            );
        }

        if (trim($note) === '') {
            throw new \InvalidArgumentException('A decision carries a note, whichever way it goes.');
        }

        // AN APPROVER MAY NARROW WHAT WAS ASKED — three of the five days, not
        // all five — but the dates and the day count are never typed separately:
        // the count is recomputed from the (possibly edited) range through the
        // same chargeable-days reading the original request used, so the two can
        // never disagree, and the balance rules are asked about the range that
        // will actually be granted before it is.
        $revisedRange = $approve && (($from !== null && $from !== $request['from'])
            || ($to !== null && $to !== $request['to']));

        if ($revisedRange) {
            $from = $from ?? $request['from'];
            $to = $to ?? $request['to'];

            if (strtotime($from) === false || strtotime($to) === false || $from > $to) {
                throw new \InvalidArgumentException(
                    "'{$from}' to '{$to}' is not a valid range to approve.");
            }

            $decision = $this->validate($request['employment_id'], $request['leave_type'], $from, $to, null, (string) $request['id']);

            if (!$decision['allowed']) {
                throw new \InvalidArgumentException("Cannot approve {$from} to {$to}: {$decision['why']}");
            }

            $request['from'] = $from;
            $request['to'] = $to;
            $wasHalf = self::halfOf($request) !== null;
            $request['days'] = $decision['days'] ?? $this->chargeableDays(
                $request['employment_id'], $request['leave_type'], $from, $to)['days'];
            // A half day moved to another single working date stays a half day.
            $wasHalf && $from === $to && (int) $request['days'] === 1 && $request['days'] = '0.5';
        }

        $request['state'] = $approve ? self::APPROVED : self::REJECTED;
        $request['decided_by'] = $by;
        $request['decided_at'] = date('c');
        $request['decision_note'] = $note;

        // A PARTIAL write, in the schema's names. Writing `$request` back whole
        // would send `state`, `from`, `to`, `employment_id` and `leave_type` —
        // the ported keys this class puts on the row for the screens — and the
        // store refuses every one of them.
        $this->store->put(self::REQUESTS, [
            'id' => $request['id'],
            'status' => $request['state'],
            'decided_by' => $by,
            'decided_at' => $request['decided_at'],
            'decision_note' => $note,
            ...($revisedRange ? [
                'from_date' => $request['from'],
                'to_date' => $request['to'],
                'days' => (string) $request['days'],
            ] : []),
        ]);

        if ($approve) {
            // The debit names the request that caused it. A movement with no
            // source is a number in a ledger nobody can trace to a decision.
            $this->putEntry([
                'employment_id' => $request['employment_id'],
                'leave_type' => $request['leave_type'],
                'kind' => LeaveLedger::CONSUMED,
                'days' => $request['days'],
                'effective_on' => $request['from'],
                'reason' => "Approved by {$by}: {$note}",
                'source_reference' => $request['id'],
            ]);
        }

        // TELL THE PERSON WHOSE LEAVE IT IS. Until this call the product decided
        // somebody's leave and told them nothing: they found out by opening the
        // screen and looking, which is exactly what SmartPRS 2 does and is the
        // reason its customers ask for an app.
        //
        // Queued, not sent — no transport is configured in this build and
        // inventing one would mark messages SENT that nobody received. Dedupe by
        // the REQUEST id, so a decision recorded twice, or a screen that
        // re-renders, cannot deliver the same rejection four times.
        //
        // Failure here does not undo the decision. The leave IS decided and the
        // ledger has moved; throwing now would leave a person unable to see a
        // decision that has already happened because a template was missing.
        try {
            $this->notifications?->queue(
                $approve ? 'leave.approved' : 'leave.rejected',
                (string) $request['employment_id'],
                'leave.decided:' . $request['id'],
                [
                    'days' => (string) $request['days'],
                    'type' => (string) $request['leave_type'],
                    'from' => (string) $request['from'],
                    'to' => (string) $request['to'],
                    'decided_by' => $by,
                    'note' => $note,
                ],
            );
        } catch (\Throwable $e) {
            // NOT SWALLOWED — carried on the row so the screen can say a person
            // was not told. An empty catch here would be the product quietly
            // deciding somebody's leave and quietly not telling them, which is
            // the two failures this whole change exists to end, together.
            $request['notification_failed'] = $e->getMessage();
        }

        return $request;
    }

    /**
     * A ported `leaves` row in the shape every screen reads.
     *
     * DEFECT: this class filtered on `$r['employment_id']` (line 245) and read
     * `state`, `from`, `to`, `requested_at` and `leave_type` off the same row.
     * The ported `leaves` table has NONE of those names: the person is
     * `employee_id` — an `employees.id`, not a code — and the rest are `status`,
     * `from_date`, `to_date`, `created_at` and `type_id`. Same defect as
     * `employee_code`: written before the port, invisible for months because the
     * file store returns whatever key it was handed.
     *
     * Renaming the one key would have stopped the warning and left the screen
     * showing nothing for ever — an employee CODE never equals a numeric
     * `employee_id` — and the next matching row would have brought the other
     * four warnings back. So the row is translated ONCE here on the way out of
     * the store: the schema's names, with the old names left in place when a row
     * already carries them (file-store rows and fixtures). Read-only; nothing
     * writes the old names.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function ported(array $row): array
    {
        $row['employment_id'] ??= $this->codeOf($row['employee_id'] ?? null);
        $row['state'] ??= (string) ($row['status'] ?? self::PENDING);
        $row['from'] ??= (string) ($row['from_date'] ?? '');
        $row['to'] ??= (string) ($row['to_date'] ?? '');
        $row['requested_at'] ??= (string) ($row['created_at'] ?? '');
        $row['leave_type'] ??= $this->typeCode($row['type_id'] ?? null);
        // Both are columns now (2026_03_01_000500) and both are NULL on a
        // pending row; named here so the screen's `decided_by === null` is a
        // comparison rather than an undefined-key warning on a ported row.
        $row['decided_by'] ??= null;
        $row['decision_note'] ??= null;

        return $row;
    }

    /**
     * `leaves.employee_id` from an employee CODE — the reverse of `codeOf()`,
     * and the reason a write cannot simply rename its key: an employee code is
     * never a numeric `employees.id`, so writing the code into `employee_id`
     * would store a row belonging to nobody.
     */
    private function employeeIdOf(string $employmentId): string
    {
        foreach ($this->store->all('employees') as $employment) {
            if (Employment::code($employment) === $employmentId) {
                return (string) $employment['id'];
            }
        }

        throw new \InvalidArgumentException(
            "No employee has the code '{$employmentId}', so there is nobody to record this leave "
            . 'against. Refused rather than stored with an empty person, which reads as a request '
            . 'nobody made and is invisible on every screen that lists one.'
        );
    }

    /**
     * `leaves.type_id` from a policy CODE — the reverse of `typeCode()`, matched
     * on the type's name because the ported table has no code column.
     *
     * A type the tenant has no row for is REFUSED. The column is nullable, so
     * the alternative is a leave request with no type, which payroll reads as
     * not-in-the-paid-list and counts as loss of pay — a wrong payslip produced
     * by a silent write.
     */
    private function typeIdOf(string $code): string
    {
        $name = (string) ($this->policy?->types()[$code]['name'] ?? '');

        foreach ($this->store->all('leave_types') as $type) {
            if ($name !== '' && strcasecmp((string) $type['name'], $name) === 0) {
                return (string) $type['id'];
            }
        }

        // A NEW COMPANY HAS NO ROWS YET (owner, 6 Nov 2026: an employee's leave was refused in a new company): a type
        // the leave policy declares is created here, named and paid exactly as the policy says, so the request carries it.
        if ($name !== '') {
            $made = $this->store->put('leave_types', ['name' => $name, 'paid' => ($this->policy?->types()[$code]['paid'] ?? false) ? 1 : 0]);

            if ((string) ($made['id'] ?? '') !== '') {
                return (string) $made['id'];
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'This tenant has no leave type named "%s" (policy code %s), so the request has no type '
            . 'to carry. Add it to the leave types first: a request stored without one is counted '
            . 'as loss of pay by payroll.',
            $name === '' ? $code : $name,
            $code,
        ));
    }

    /**
     * `leaves.employee_id` is an `employees.id`; every screen here identifies an
     * employee by its CODE, which is what the ledger stores too.
     *
     * ponytail: one lookup per row, through the tenant-scoped store. A join
     * belongs here as a named repository method the day a screen lists leave for
     * everybody; for one employee's requests it would be a query to save four.
     */
    /** @var array<string, string> employees.id => code, remembered per request — a muster ports every leave row for every person */
    private array $codeCache = [];

    /** @var array<string, string> leave_types.id => policy code */
    private array $typeCache = [];

    private function codeOf(mixed $employeeId): string
    {
        if ((string) $employeeId === '') {
            return '';
        }

        if (!isset($this->codeCache[(string) $employeeId])) {
            $employment = $this->store->find('employees', (string) $employeeId);
            $this->codeCache[(string) $employeeId] = $employment === null ? '' : Employment::code($employment);
        }

        return $this->codeCache[(string) $employeeId];
    }

    /**
     * `leaves.type_id` points at the tenant's `leave_types`; the policy — and
     * payroll's paid list — speak in CODES. Matched on the type's name, because
     * the ported table has no code column.
     *
     * ponytail: a type whose name is not in `config/leave-policy.json` keeps the
     * table's own name, which is not in the paid list, so payroll counts it as
     * unpaid. That is visible on the register rather than silent, and the real
     * fix is a `code` column on `leave_types`.
     */
    private function typeCode(mixed $typeId): string
    {
        if ((string) $typeId === '') {
            return '';
        }

        if (isset($this->typeCache[(string) $typeId])) {
            return $this->typeCache[(string) $typeId];
        }

        return $this->typeCache[(string) $typeId] = $this->typeCodeUncached($typeId);
    }

    private function typeCodeUncached(mixed $typeId): string
    {
        $name = (string) ($this->store->find('leave_types', (string) $typeId)['name'] ?? '');

        foreach ($this->policy?->types() ?? [] as $code => $type) {
            if (strcasecmp((string) $type['name'], $name) === 0) {
                return (string) $code;
            }
        }

        return $name;
    }

    /**
     * One request, in the shape every screen reads.
     *
     * Here because the front controller was asking the store for a row from
     * `leave_requests` — a table that has never existed under any name, so on
     * MySQL the lookup threw and on files it returned null. The trail recorded
     * every leave decision as a change from nothing as a result.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $requestId): ?array
    {
        $row = $this->store->find(self::REQUESTS, $requestId);

        return $row === null ? null : $this->ported($row);
    }

    /**
     * EVERY REQUEST STILL AWAITING A DECISION, oldest first — the Leave
     * Approvals queue. Who may decide which of them is the caller's question
     * (permission, or being the requester's reporting manager), not this class's.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        $rows = array_values(array_filter(array_map($this->ported(...), $this->store->all(self::REQUESTS)),
            static fn (array $r): bool => $r['state'] === self::PENDING));

        usort($rows, static fn (array $a, array $b): int => strcmp($a['requested_at'], $b['requested_at']));

        return $rows;
    }

    /** Every request, any state, in the shape every screen reads. @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_map($this->ported(...), $this->store->all(self::REQUESTS));
    }

    /** @return list<array<string, mixed>> */
    public function requestsFor(string $employmentId): array
    {
        $rows = array_values(array_filter(array_map($this->ported(...), $this->store->all(self::REQUESTS)),
            static fn (array $r): bool => $r['employment_id'] === $employmentId));

        usort($rows, static fn (array $a, array $b): int => strcmp($b['requested_at'], $a['requested_at']));

        return $rows;
    }

    /** The balance, SUMMED by the platform ledger at the date asked about. */
    public function balance(string $employmentId, string $type, \DateTimeImmutable $asOf): Rational
    {
        return $this->ledger($employmentId)->balanceOn($employmentId, $type, $asOf);
    }

    public function statement(string $employmentId, string $type, \DateTimeImmutable $asOf): string
    {
        return $this->ledger($employmentId)->statement($employmentId, $type, $asOf);
    }

    /**
     * Is this leave type PAID? The policy's own flag (Leave & Absence → leave
     * types), the same answer payroll pays from. Null when no policy is loaded.
     */
    public function isPaidType(string $code): ?bool
    {
        return $this->policy === null ? null : in_array($code, $this->policy->paidCodes(), true);
    }

    /** Approved leave days inside a period — what reduces payable days. */
    public function approvedDaysIn(string $employmentId, string $from, string $to): int
    {
        $days = 0;

        foreach ($this->requestsFor($employmentId) as $request) {
            if ($request['state'] !== self::APPROVED) {
                continue;
            }

            if ($request['from'] <= $to && $request['to'] >= $from) {
                $days += (int) $request['days'];
            }
        }

        return $days;
    }

    /**
     * LEDGER ROWS BY EMPLOYEE, READ ONCE PER REQUEST (13 Oct). balance() and
     * credit() each re-read the WHOLE leave_entries table — the Leave Balance
     * Report did that ~2 x types x employees times, plus once per accrual month,
     * and took 141 s. This class is the only writer of the table, so every write
     * goes through putEntry() and the cache never goes stale within a request.
     * ponytail: whole-tenant read held in memory; read per employee with a WHERE
     * if one tenant's ledger ever outgrows memory.
     *
     * @var array<string, list<array<string, mixed>>>|null
     */
    private ?array $entriesByEmployment = null;

    /** @return list<array<string, mixed>> */
    private function entriesOf(string $employmentId): array
    {
        if ($this->entriesByEmployment === null) {
            $this->entriesByEmployment = [];

            foreach ($this->store->all(self::ENTRIES) as $row) {
                $this->entriesByEmployment[(string) ($row['employment_id'] ?? '')][] = $row;
            }
        }

        return $this->entriesByEmployment[$employmentId] ?? [];
    }

    /** Write a ledger row and keep the per-request cache true. */
    private function putEntry(array $row): void
    {
        $saved = $this->store->put(self::ENTRIES, $row);

        if ($this->entriesByEmployment !== null) {
            $this->entriesByEmployment[(string) $row['employment_id']][] = $saved + $row;
        }
    }

    private function ledger(string $employmentId): LeaveLedger
    {
        $entries = [];

        foreach ($this->entriesOf($employmentId) as $row) {

            $entries[] = new LeaveLedgerEntry(
                // `leave_entries.id` is a BIGINT and the driver hands it back as
                // a native int (ATTR_EMULATE_PREPARES is off), which a
                // `string $id` parameter rejects outright under strict_types.
                // Cast here rather than widening the entry: the id is used as a
                // string everywhere in the ledger — concatenated into
                // `:lapse`, passed as `consumesEntryId`, compared with `===` —
                // so widening one property would have to widen three and every
                // comparison with them. This is the one place a row becomes an
                // entry, so it is the one place the type has to be settled.
                id: (string) $row['id'],
                employmentId: $row['employment_id'],
                leaveTypeCode: $row['leave_type'],
                kind: $row['kind'],
                days: Rational::fromDecimalString((string) $row['days']),
                effectiveOn: new \DateTimeImmutable($row['effective_on']),
                reason: $row['reason'],
                sourceReference: $row['source_reference'] ?? null,
            );
        }

        return new LeaveLedger($entries);
    }

    /**
     * ponytail: Monday to Friday, inclusive of both ends. The real product asks
     * the calendar service, which resolves weekly-off patterns, holidays and
     * rosters per employee — that is `Platform\Calendar\CalendarService`, and
     * wiring it here needs a published pattern per tenant, which is the next
     * slice rather than a gap in this one.
     */
    /**
     * THE FALLBACK, and it is wrong in two known ways — which is why it is
     * private, named, and reached only when no charge rules were given.
     *
     * Monday to Friday, ignoring the working calendar: a holiday inside the span
     * is charged, and a tenant whose weekly off is Sunday only gets Saturdays
     * free. Kept rather than deleted so a `Leave` built without rules still
     * answers something, and every path that reaches it says on the screen that
     * it did.
     */
    private static function workingDaysBetween(string $from, string $to): int
    {
        $start = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);

        if ($end < $start) {
            return 0;
        }

        $days = 0;

        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            if ((int) $day->format('N') <= 5) {
                $days++;
            }
        }

        return $days;
    }
}
