<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Lifecycle\EmploymentTimeline;

/**
 * TRANSFERS: RAISED, APPROVED, APPLIED ON THE DAY, AND ON THE TIMELINE.
 *
 * SmartPRS 2's `TransferService` (rev 77) moves an employee between branches or
 * companies in the group: the request rides the approval engine, and once
 * approved it is APPLIED when the effective date arrives — the employee row is
 * rewritten, the from-values snapshotted onto the transfer, and the row kept as
 * `applied` for ever. All of that is kept, on the ported `transfers` table.
 *
 * What is added is the one thing SmartPRS 2 cannot answer: WHERE WAS THIS PERSON
 * IN MARCH? The apply step records a `transferred` event on the employee
 * timeline (`Surface\Timeline`) with the from/to on it, so payroll, the muster
 * and a letter can read the branch as of any date instead of only the current
 * one. An employee with no events yet has its joining recorded from doj first,
 * because a timeline cannot start with a transfer — and that is said, not hidden.
 *
 * The maker is not the checker: a transfer is raised with `employee.update` and
 * decided with `request.decide`-class authority (here `leave.request.decide`,
 * the deciding permission this build already grants), and the raiser's own
 * decision is refused.
 */
final class Transfers
{
    public const COLLECTION = 'transfers';

    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const APPLIED = 'applied';

    public function __construct(
        private readonly RowStore $store,
        private readonly Timeline $timeline,
    ) {
    }

    /** @return list<array<string, mixed>> newest first */
    public function all(?string $status = null): array
    {
        $rows = $this->store->all(self::COLLECTION);

        if ($status !== null) {
            $rows = array_filter($rows, static fn (array $r): bool => (string) ($r['status'] ?? '') === $status);
        }

        $rows = array_values($rows);
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function forEmployment(string $employeeId): array
    {
        return array_values(array_filter($this->all(), static fn (array $r): bool => (string) ($r['employee_id'] ?? '') === $employeeId));
    }

    public function find(string $id): ?array
    {
        return $this->store->find(self::COLLECTION, $id);
    }

    /**
     * Raise one. From-values are snapshotted NOW so the trail shows where the
     * person was when it was asked for, even if the record changes before it is applied.
     *
     * @return array{ok: bool, why: string, transfer?: array<string, mixed>}
     */
    public function raise(array $employment, array $input, string $by): array
    {
        $toBranch = trim((string) ($input['to_branch'] ?? ''));
        $toDepartment = trim((string) ($input['to_department'] ?? ''));
        $toCompany = trim((string) ($input['to_company'] ?? ''));
        $effective = trim((string) ($input['effective_date'] ?? ''));
        $reason = trim((string) ($input['reason'] ?? ''));

        if ($toBranch === '' && $toDepartment === '' && $toCompany === '') {
            return ['ok' => false, 'why' => 'A transfer moves somebody somewhere: name a branch, a department or a company.'];
        }

        if (!self::isDate($effective)) {
            return ['ok' => false, 'why' => "'{$effective}' is not an effective date (Y-m-d)."];
        }

        if ($reason === '') {
            return ['ok' => false, 'why' => 'A transfer needs a reason. It is what goes on the order and on the timeline.'];
        }

        $fromBranch = trim((string) ($employment['branch'] ?? ''));
        // The company by NAME: the trail is read by people, and "moved from 229" is not a place.
        $fromCompany = (string) ($employment['employer_company_id'] ?? '');
        $fromCompany = $fromCompany === '' ? '' : (string) ($this->nameOf('companies', $fromCompany) ?? $fromCompany);

        if ($toBranch !== '' && strcasecmp($toBranch, $fromBranch) === 0 && $toDepartment === '' && $toCompany === '') {
            return ['ok' => false, 'why' => "{$employment['name']} is already in {$fromBranch}."];
        }

        foreach ($this->forEmployment((string) $employment['id']) as $open) {
            if (in_array((string) $open['status'], [self::PENDING, self::APPROVED], true)) {
                return ['ok' => false, 'why' => 'A transfer is already ' . $open['status'] . ' for this employee (effective '
                    . $open['effective_date'] . '). Decide or apply that one first: two open transfers end with the person in two places.'];
            }
        }

        $row = $this->store->put(self::COLLECTION, [
            'employee_id' => (string) $employment['id'],
            // The type SmartPRS 2 asks for first (Branch / Department / Company transfer);
            // derived from the destination when the form did not say.
            'type' => in_array(strtolower(trim((string) ($input['type'] ?? ''))), ['company', 'branch', 'department'], true)
                ? strtolower(trim((string) $input['type'])) : ($toCompany !== '' ? 'company' : ($toBranch !== '' ? 'branch' : 'department')),
            'from_company' => $fromCompany !== '' ? $fromCompany : null,
            'from_branch' => $fromBranch !== '' ? $fromBranch : null,
            'to_company' => $toCompany !== '' ? $toCompany : null,
            'to_company_id' => trim((string) ($input['to_company_id'] ?? '')) !== '' ? (string) $input['to_company_id'] : null,
            'to_branch' => $toBranch !== '' ? $toBranch : null,
            'to_department' => $toDepartment !== '' ? $toDepartment : null,
            'effective_date' => $effective,
            'reason' => mb_substr($reason, 0, 255),
            'status' => self::PENDING,
            'approver_name' => null,
            'decided_by' => null,
            'decided_at' => null,
            'remarks' => 'raised by ' . $by,
        ]);

        return ['ok' => true, 'transfer' => $row, 'why' => 'Transfer raised for ' . Employment::code($employment)
            . ", effective {$effective}. It waits for a decision; nothing has moved."];
    }

    /**
     * Approve or reject. An approval applies at once when the date has arrived.
     *
     * @return array{ok: bool, why: string}
     */
    public function decide(string $id, bool $approve, string $by, string $remarks, string $today, bool $selfOk = false): array
    {
        $t = $this->find($id);

        if ($t === null) {
            return ['ok' => false, 'why' => 'No such transfer.'];
        }

        if ((string) $t['status'] !== self::PENDING) {
            return ['ok' => false, 'why' => "This transfer is already {$t['status']}."];
        }

        // $selfOk: Administrator (or a configured self-approval role) decides its own (owner, 7 Oct 2026).
        if (!$selfOk && str_contains((string) ($t['remarks'] ?? ''), 'raised by ' . $by)) {
            return ['ok' => false, 'why' => 'The person who raised a transfer does not decide it. Maker and checker are two people.'];
        }

        if (!$approve && trim($remarks) === '') {
            return ['ok' => false, 'why' => 'A rejection needs a remark — it is what the requester reads.'];
        }

        $t['status'] = $approve ? self::APPROVED : self::REJECTED;
        $t['decided_by'] = $by;
        $t['decided_at'] = date('Y-m-d H:i:s');
        $t['approver_name'] = $by;
        $t['remarks'] = trim((string) ($t['remarks'] ?? '')) . ($remarks !== '' ? ' · ' . trim($remarks) : '');
        $this->store->put(self::COLLECTION, $t);

        if (!$approve) {
            return ['ok' => true, 'why' => 'Transfer rejected. Nothing moved; the row is kept.'];
        }

        if ((string) $t['effective_date'] <= $today) {
            $applied = $this->apply($t, $by);

            return ['ok' => true, 'why' => 'Approved and applied: ' . $applied['why']];
        }

        return ['ok' => true, 'why' => "Approved. It applies on {$t['effective_date']} — run \"Apply due\" on or after that "
            . 'day, or wait for the scheduler.'];
    }

    /**
     * Apply every approved transfer whose date has arrived.
     *
     * @return array{applied: int, waiting: int, notes: list<string>}
     */
    public function applyDue(string $today, string $by): array
    {
        $applied = 0;
        $waiting = 0;
        $notes = [];

        foreach ($this->all(self::APPROVED) as $t) {
            if ((string) $t['effective_date'] > $today) {
                $waiting++;

                continue;
            }

            $r = $this->apply($t, $by);
            $notes[] = $r['why'];

            if ($r['ok']) {
                $applied++;
            }
        }

        return ['applied' => $applied, 'waiting' => $waiting, 'notes' => $notes];
    }

    /** @return array{ok: bool, why: string} */
    private function apply(array $t, string $by): array
    {
        $employment = $this->store->find('employees', (string) $t['employee_id']);

        if ($employment === null) {
            return ['ok' => false, 'why' => "Transfer {$t['id']}: the employee no longer exists; left approved and unapplied."];
        }

        $code = Employment::code($employment);
        $from = array_filter(['branch' => $employment['branch'] ?? null, 'department' => $employment['department'] ?? null,
            'company' => $employment['employer_company_id'] ?? null]);
        $to = array_filter(['branch' => $t['to_branch'] ?? null, 'department' => $t['to_department'] ?? null,
            'company' => $t['to_company'] ?? null]);
        $note = '';

        // RESOLVED BEFORE ANYTHING IS RECORDED, so a refusal leaves no half-move.
        // THE COMPANY FIRST, so a same-named branch or department is found in
        // the company the person is moving TO, and a department in the branch
        // they are moving to (28 Sep: it took the first same-named row, and when
        // none matched it moved the text but left the old id behind).
        $newCompany = trim((string) ($t['to_company_id'] ?? '')) ?: trim((string) ($employment['employer_company_id'] ?? ''));

        if (($t['to_branch'] ?? '') !== '' && $t['to_branch'] !== null) {
            $branchId = $this->idByName('branches', (string) $t['to_branch'], ['employer_company_id' => $newCompany]);

            if ($branchId === null) {
                return ['ok' => false, 'why' => "{$code}: no branch named '{$t['to_branch']}' in the destination company — nothing was moved."];
            }

            $employment['branch'] = $t['to_branch'];
            $employment['branch_id'] = $branchId;
        } elseif (($t['to_company_id'] ?? '') !== '' && $t['to_company_id'] !== null
            && (string) $t['to_company_id'] !== (string) ($employment['employer_company_id'] ?? '')) {
            // A company move with the branch left "unchanged" would keep the OLD
            // company's branch — refused rather than filed half-moved.
            return ['ok' => false, 'why' => "{$code}: a move to another company needs the branch in that company too."];
        }

        if (($t['to_department'] ?? '') !== '' && $t['to_department'] !== null) {
            $departmentId = $this->idByName('departments', (string) $t['to_department'],
                ['branch_id' => (string) ($employment['branch_id'] ?? ''), 'employer_company_id' => $newCompany]);

            if ($departmentId === null) {
                return ['ok' => false, 'why' => "{$code}: no department named '{$t['to_department']}' there — nothing was moved."];
            }

            $employment['department'] = $t['to_department'];
            $employment['department_id'] = $departmentId;
        }


        try {
            if ($this->timeline->events($code) === []) {
                $doj = Employment::joinedOn($employment);

                if ($doj !== '') {
                    $this->timeline->record($code, EmploymentTimeline::JOINED, $doj,
                        'Recorded from doj when the first timeline event was needed', [], $by);
                    $note = " ({$code}'s timeline was empty, so joining on {$doj} was recorded first.)";
                }
            }

            $this->timeline->record($code, EmploymentTimeline::TRANSFERRED, (string) $t['effective_date'],
                // FLAT STRINGS on the event: the timeline replays details as text, and a nested array became "Array".
                (string) $t['reason'], ['from' => implode('/', array_map('strval', $from)), 'to' => implode('/', array_map('strval', $to))], $by);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'why' => "{$code}: the timeline refused the transfer — " . $e->getMessage()
                . ' Left approved and unapplied.'];
        }

        // THE REGISTER IDS MOVE WITH THE NAMES: the Directory, the filters and the
        // muster read branch_id / department_id; a transfer that rewrote only the
        // text left every filter on the old branch.
        if (($t['to_company_id'] ?? '') !== '' && $t['to_company_id'] !== null) {
            $employment['employer_company_id'] = $t['to_company_id'];
            // A COMPANY MOVE CLEARS THE OLD MANAGER AND TEAM (designation stays), as
            // SmartPRS 2 does; the Transfer onboarding card asks the new company
            // to assign them. An approver who no longer covers the person is
            // worse than none: requests would wait on somebody who cannot see them.
            $employment['reporting_manager_id'] = null;
            $employment['reporting_manager'] = null;
            $employment['team_id'] = null;
            $employment['team'] = null;
            $employment['team_leader'] = null;
        }

        $this->store->put('employees', $employment);

        $t['status'] = self::APPLIED;
        $t['applied_at'] = date('Y-m-d H:i:s');
        $this->store->put(self::COLLECTION, $t);

        return ['ok' => true, 'why' => "{$code} moved" . ($from !== [] ? ' from ' . implode('/', array_map('strval', $from)) : '')
            . ' to ' . implode('/', array_map('strval', $to)) . " with effect from {$t['effective_date']}.{$note}"];
    }

    /**
     * The order goes out once: mint the acknowledgement token and stamp
     * order_sent_at. Returns the token (existing or new); null when the transfer
     * is not approved/applied or does not exist.
     */
    public function issueOrder(string $id, string $now): ?string
    {
        $t = $this->find($id);

        if ($t === null || !in_array((string) $t['status'], [self::APPROVED, self::APPLIED], true)) {
            return null;
        }

        $token = trim((string) ($t['accept_token'] ?? ''));

        if ($token === '') {
            $token = bin2hex(random_bytes(24));
            $t['accept_token'] = $token;
        }

        if (trim((string) ($t['order_sent_at'] ?? '')) === '') {
            $t['order_sent_at'] = $now;
        }

        $this->store->put(self::COLLECTION, $t);

        return $token;
    }

    /** The employee's acknowledgement — recorded once; the transfer still applies on its date. @return array{ok: bool, why: string} */
    public function acknowledge(string $id, string $by, string $now): array
    {
        $t = $this->find($id);

        if ($t === null || (string) $t['status'] === self::REJECTED || (string) $t['status'] === self::PENDING) {
            return ['ok' => false, 'why' => 'There is no approved transfer order to acknowledge.'];
        }

        if (trim((string) ($t['accepted_at'] ?? '')) !== '') {
            return ['ok' => true, 'why' => 'Already acknowledged on ' . substr((string) $t['accepted_at'], 0, 16) . '. Nothing changed.'];
        }

        $t['accepted_at'] = $now;
        $t['remarks'] = trim((string) ($t['remarks'] ?? '')) . ' · acknowledged by ' . $by;
        $this->store->put(self::COLLECTION, $t);

        return ['ok' => true, 'why' => 'Transfer order acknowledged; it is on the file.'];
    }

    /**
     * SmartPRS 2's PROCESS TRACKER (rev 78): the stages of one transfer, each
     * done / todo / fail / prog with its date or what it waits for.
     *
     * @param ?string $onboarding the onboarding board status for a company move, if any
     * @return list<array{state: string, label: string, sub: string}>
     */
    public static function tracker(array $t, ?string $onboarding = null): array
    {
        $when = static fn ($v): string => trim((string) ($v ?? '')) === '' ? '' : substr((string) $v, 0, 16);
        $steps = [['state' => 'done', 'label' => 'Raised', 'sub' => $when($t['created_at'] ?? '')]];
        $status = (string) ($t['status'] ?? '');

        if ($status === self::REJECTED) {
            $steps[] = ['state' => 'fail', 'label' => 'Rejected', 'sub' => trim((string) ($t['decided_by'] ?? '') . ' ' . $when($t['decided_at'] ?? ''))];

            return $steps;
        }

        $steps[] = $status === self::PENDING
            ? ['state' => 'todo', 'label' => 'Approval', 'sub' => 'awaiting ' . ((string) ($t['approver_name'] ?? '') !== '' ? (string) $t['approver_name'] : 'approver')]
            : ['state' => 'done', 'label' => 'Approved', 'sub' => trim((string) ($t['decided_by'] ?? '') . ' ' . $when($t['decided_at'] ?? ''))];
        $sent = $when($t['order_sent_at'] ?? '');
        $steps[] = ['state' => $sent !== '' ? 'done' : 'todo', 'label' => 'Order letter', 'sub' => $sent !== '' ? $sent : ($status === self::PENDING ? 'after approval' : 'queued on this screen')];
        $ack = $when($t['accepted_at'] ?? '');
        $steps[] = ['state' => $ack !== '' ? 'done' : 'todo', 'label' => 'Acknowledged', 'sub' => $ack !== '' ? $ack : 'awaiting employee'];
        $applied = $when($t['applied_at'] ?? '');
        $steps[] = ['state' => $applied !== '' ? 'done' : 'todo', 'label' => 'Applied', 'sub' => $applied !== '' ? $applied : ($status === self::APPROVED ? 'on ' . (string) ($t['effective_date'] ?? 'effective date') : '')];

        if ((string) ($t['type'] ?? '') === 'company') {
            $steps[] = ['state' => $onboarding === 'completed' ? 'done' : ($onboarding !== null ? 'prog' : 'todo'), 'label' => 'Onboarding',
                'sub' => $onboarding === 'completed' ? 'formalities complete' : ($onboarding !== null ? 'formalities in progress' : 'starts on apply')];
        }

        return $steps;
    }

    private function nameOf(string $collection, string $id): ?string
    {
        try {
            $r = $this->store->find($collection, $id);
        } catch (\Throwable) { // gate:allow-swallow no such register on a file store — the name is shown as the id
            return null;
        }

        return $r === null ? null : (trim((string) ($r['name'] ?? '')) ?: null);
    }

    /**
     * The id of the unit with this name — narrowed by its parents when more than
     * one shares the name; null when nothing (or still more than one) matches.
     *
     * @param array<string, string> $within parent column => id, applied only to break a tie
     */
    private function idByName(string $collection, string $name, array $within = []): ?string
    {
        try {
            $matches = array_values(array_filter($this->store->all($collection), static fn (array $r): bool
                => strcasecmp(trim((string) ($r['name'] ?? '')), trim($name)) === 0 && (string) ($r['deleted_at'] ?? '') === ''));
        } catch (\Throwable) { // gate:allow-swallow no such register on a file store — the text column still moves
            return null;
        }

        foreach ($within as $column => $id) {
            if (count($matches) <= 1 || $id === '') {
                break;
            }

            $narrowed = array_values(array_filter($matches, static fn (array $r): bool => (string) ($r[$column] ?? '') === $id));
            $matches = $narrowed !== [] ? $narrowed : $matches;
        }

        return count($matches) === 1 ? (string) $matches[0]['id'] : null;
    }

    /** The values a transfer order letter needs beyond the employee's own. */
    public static function letterContext(array $t): array
    {
        $from = array_filter([(string) ($t['from_branch'] ?? '')]);
        $to = array_filter([(string) ($t['to_company'] ?? ''), (string) ($t['to_branch'] ?? ''), (string) ($t['to_department'] ?? '')]);

        return [
            'transfer_from' => $from === [] ? '' : 'from ' . implode(', ', $from),
            'transfer_to' => implode(', ', $to),
            'effective_date' => (string) ($t['effective_date'] ?? ''),
            'transfer_reason' => trim((string) ($t['reason'] ?? '')) === '' ? '' : 'Reason: ' . trim((string) $t['reason']) . '.',
        ];
    }

    private static function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
