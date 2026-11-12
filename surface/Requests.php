<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;

/**
 * REQUESTS AND APPROVALS, FROM A DECLARATION.
 *
 * SmartPRS 2 answers its request screens from a 108 KB controller in which every
 * approval chain is written out in PHP. A customer who wants one more approver
 * above ₹50,000 needs a release. Here that is a threshold in
 * `config/workflows.json`.
 *
 * SELF-APPROVAL IS REFUSED, and refused rather than warned about. The whole
 * point of an approval is that a second person looked; a chain the requester can
 * complete alone is a form, not a control. It is checked at DECISION time and
 * not only hidden in the inbox, because a hidden button is a UI convention and
 * this is a rule.
 *
 * A STEP THAT DOES NOT APPLY IS SKIPPED AND SAID SO. Routing by amount means the
 * chain differs per request, and a person looking at an approved claim needs to
 * know whether finance saw it or whether the amount put it below their
 * threshold. "Skipped: ₹4,200 is under the ₹5,000 threshold" is an answer;
 * a missing row is not.
 *
 * THE DECISION IS APPENDED, NEVER OVERWRITTEN. A rejected step that is later
 * approved leaves both, because "who approved this, and had anyone rejected it
 * first?" is the question asked when something has gone wrong.
 *
 * ponytail: linear chains with amount routing. Parallel and quorum nodes exist
 * in `Platform\Workflow` and are not wired here — they need a second role to be
 * worth declaring, and declaring them for one role would be shape without
 * meaning.
 */
final class Requests
{
    public const OPEN = 'open';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    private const COLLECTION = 'workflow_requests';
    private const DECISIONS = 'workflow_decisions';

    /** @var list<array<string, mixed>>|null */
    private ?array $workflows = null;

    public function __construct(
        private readonly RowStore $store,
        private readonly string $file,
        private readonly ?WorkflowCustomisation $customisation = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function workflows(): array
    {
        if ($this->workflows !== null) {
            return $this->workflows;
        }

        $decoded = is_file($this->file)
            ? json_decode((string) file_get_contents($this->file), true)
            : null;

        if (!is_array($decoded) || !is_array($decoded['workflows'] ?? null)) {
            throw new \RuntimeException(
                "The workflow registry at {$this->file} is missing or unreadable. Refused rather "
                . 'than treated as "no workflows", which would leave every approval screen empty and '
                . 'nothing to say why.'
            );
        }

        // ONE HOOK, like the module registry. Applying the customer's thresholds
        // in chainFor() alone would leave the screen listing the shipped chain
        // while the routing used theirs — the two disagreeing about who has to
        // approve, which is the worst place for a disagreement.
        return $this->workflows = $this->customisation === null
            ? $decoded['workflows']
            : array_map($this->customisation->apply(...), $decoded['workflows']);
    }

    /**
     * WHO MAY LOOK AT A REQUEST, derived from what it is about.
     *
     * The same hole as the reports screen, found by the same audit: a request
     * could be opened by its id by anybody signed in, and a salary increment
     * carries the new CTC on the face of it. So a request inherits the VIEW
     * PERMISSION OF THE MODULE THAT DECLARES ITS SUBJECT TABLE — an increment
     * sits behind whatever guards the increments module, a leave request behind
     * whatever guards leave.
     *
     * The person who RAISED it can always see it. Their own claim is not a
     * disclosure to them, and a system that hides somebody's own request from
     * them is one they route around by asking in person.
     *
     * THE MODULE'S VIEW PERMISSION IS NOT ENOUGH ON ITS OWN, and that was a real
     * hole rather than a hypothetical one. The increments module declares
     * `view: employee.view` — which a viewer holds — and puts
     * `employee.salary.view` on `new_ctc`, so on the MODULE screen the amount is
     * masked and everything is well. A request prints that same amount on its
     * face, and inherited only the module's view permission: the field guard was
     * bypassed by rendering the value somewhere the field did not exist. So the
     * permission a request inherits is the one guarding THE FIELD ITS AMOUNT COMES
     * FROM when the workflow names one, and the module's view permission only when
     * it does not. Masking a column on one screen and printing it on another is
     * not a permission system, it is a habit.
     *
     * A subject table no module declares is refused, for the same reason as
     * everywhere else: "no rule found" must never read as "no rule applies".
     *
     * @return array{permission: ?string, why: ?string}
     */
    public function permissionFor(array $workflow, Modules $modules): array
    {
        $subject = (string) $workflow['subject'];
        $amountField = (string) ($workflow['amount_field'] ?? '');

        foreach ($modules->all() as $module) {
            if ((string) $module['table'] !== $subject) {
                continue;
            }

            $onTheField = (string) (((array) (((array) ($module['fields'] ?? []))[$amountField]
                ?? []))['permission'] ?? '');

            return [
                'permission' => $onTheField !== ''
                    ? $onTheField
                    : $modules->permission($module, 'view'),
                'why' => null,
            ];
        }

        return ['permission' => null, 'why' => sprintf(
            'No module declares `%s`, so there is no permission for requests raised under "%s" to '
            . 'inherit. Refused rather than shown to everybody.', $subject,
            (string) $workflow['label'])];
    }

    /** The workflow AS SHIPPED — what a customisation is compared against. */
    public function shipped(string $key): ?array
    {
        $decoded = is_file($this->file)
            ? json_decode((string) file_get_contents($this->file), true)
            : null;

        foreach ((array) ($decoded['workflows'] ?? []) as $workflow) {
            if ((string) $workflow['key'] === $key) {
                return $workflow;
            }
        }

        return null;
    }

    public function workflow(string $key): ?array
    {
        foreach ($this->workflows() as $workflow) {
            if ((string) $workflow['key'] === $key) {
                return $workflow;
            }
        }

        return null;
    }

    /**
     * The steps this request actually goes through, with the ones that do not
     * apply marked and REASONED rather than removed.
     *
     * ROUTING BY AMOUNT IS AN EXACT COMPARISON (Law 7). This used to be
     * `$amount > (float) $threshold` on two floats: a ₹50,000.00 claim that
     * reached here as 49999.999999999993 misses the director by a hundredth of
     * a paisa, and the request is fully approved by people who were never meant
     * to be the last word on it. The amount is an exact decimal string and the
     * comparison is integer minor units.
     *
     * @param  string  $amount  exact decimal, e.g. "50000.00" (rupees) or "10.5" (days)
     * @return list<array<string, mixed>>
     */
    public function chainFor(array $workflow, string $amount): array
    {
        $chain = [];

        foreach ((array) $workflow['steps'] as $step) {
            $threshold = $step['when_amount_over'] ?? null;
            $applies = $threshold === null
                || Amount::of($amount)->compareTo(Amount::of($threshold)) > 0;

            $chain[] = $step + [
                'applies' => $applies,
                'why' => $applies
                    ? ''
                    : sprintf('skipped — %s is not over the %s threshold',
                        self::amountLabel($amount, $workflow), self::amountLabel((string) $threshold, $workflow)),
            ];
        }

        return $chain;
    }

    /**
     * A day count is not money, and it is parsed by Money only because the same
     * exact minor-unit parse is what an exact comparison of "10.5" against "10"
     * needs; it is never formatted or stored as rupees.
     */
    public static function amountLabel(string $value, array $workflow): string
    {
        if ((string) ($workflow['amount_field'] ?? '') === 'days') {
            $days = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;

            return ($days === '' ? '0' : $days) . ' day(s)';
        }

        return Amount::of($value)->format();
    }

    /**
     * Raise a request.
     *
     * @return array{ok: bool, why: string, request?: array<string, mixed>}
     */
    public function raise(string $workflowKey, string $subjectId, string $amount, string $note,
        string $byId, string $byLabel): array
    {
        $workflow = $this->workflow($workflowKey);

        if ($workflow === null) {
            return ['ok' => false, 'why' => "No workflow '{$workflowKey}' is declared."];
        }

        // The amount routes the request, so an amount nobody can read exactly is
        // refused here rather than becoming 0.0 the way a float cast made it.
        try {
            Money::of($amount);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'why' => 'That amount is not an exact figure: ' . $e->getMessage()
                . ' The amount decides who has to approve this, so it is refused rather than '
                . 'rounded to something nobody typed.'];
        }

        $chain = array_values(array_filter($this->chainFor($workflow, $amount),
            static fn (array $s): bool => $s['applies'] && $s['type'] === 'approval'));

        if ($chain === []) {
            return ['ok' => false, 'why' => 'Every approval step is below its threshold for this '
                . 'amount, so nobody would ever decide it. That is a refusal rather than an '
                . 'auto-approval: a request that approves itself because the chain is empty is a '
                . 'control that has silently stopped existing.'];
        }

        $request = $this->store->put(self::COLLECTION, [
            'workflow' => $workflowKey,
            'subject_table' => (string) $workflow['subject'],
            'subject_id' => $subjectId,
            'amount' => $amount,
            'note' => $note,
            'state' => self::OPEN,
            'at_step' => (string) $chain[0]['key'],
            'raised_by_id' => $byId,
            'raised_by' => $byLabel,
            'raised_at' => date('c'),
        ]);

        return ['ok' => true, 'request' => $request, 'why' => sprintf(
            '%s raised. It waits on %s%s.',
            (string) $workflow['label'],
            (string) $chain[0]['label'],
            count($chain) > 1 ? ', then ' . implode(', then ', array_map(
                static fn (array $s): string => (string) $s['label'], array_slice($chain, 1))) : '',
        )];
    }

    /** @return list<array<string, mixed>> */
    public function open(): array
    {
        return array_values(array_filter($this->store->all(self::COLLECTION),
            static fn (array $r): bool => (string) $r['state'] === self::OPEN));
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $rows = $this->store->all(self::COLLECTION);
        usort($rows, static fn (array $a, array $b): int
            => strcmp((string) $b['raised_at'], (string) $a['raised_at']));

        return $rows;
    }

    /** @return list<array<string, mixed>> every decision taken on a request, in order */
    public function decisions(string $requestId): array
    {
        $rows = array_values(array_filter($this->store->all(self::DECISIONS),
            static fn (array $d): bool => (string) $d['request_id'] === $requestId));
        usort($rows, static fn (array $a, array $b): int
            => strcmp((string) $a['decided_at'], (string) $b['decided_at']));

        return $rows;
    }

    /**
     * Decide the step a request is waiting on.
     *
     * @param bool $selfApprovalAllowed the ONE named exception to "you raised this,
     *        so you cannot decide it" (Authorisation::maySelfApprove(), owner's
     *        instruction 12 Sep 2026) — resolved by the caller from the decider's
     *        role, never guessed at here: this class stays authorisation-blind,
     *        the same as every other engine class in `surface/`.
     * @param bool $finalApprover the decider is a FINAL APPROVER (owner, 12 Oct 2026: a Company
     *        Admin / HR Admin approval overrides every other approval — config/approval-authority.json,
     *        resolved by the caller from the role, like $selfApprovalAllowed): their approval at any
     *        step closes the request as approved; the steps still open are not waited for.
     * @return array{ok: bool, why: string, request?: array<string, mixed>}
     */
    public function decide(string $requestId, bool $approve, string $comment, string $byId,
        string $byLabel, bool $selfApprovalAllowed = false, bool $finalApprover = false): array
    {
        $request = $this->store->find(self::COLLECTION, $requestId);

        if ($request === null) {
            return ['ok' => false, 'why' => 'No such request.'];
        }

        if ((string) $request['state'] !== self::OPEN) {
            return ['ok' => false, 'why' => 'That request was already ' . $request['state']
                . '. Deciding it twice would record two outcomes for one question.'];
        }

        // REFUSED, not hidden. Hiding the button is a UI convention; this is a
        // rule, and a rule that only exists in a template is one an API call
        // walks straight past.
        if (!$selfApprovalAllowed && (string) $request['raised_by_id'] === $byId) {
            return ['ok' => false, 'why' => 'You raised this request, so you cannot decide it. The '
                . 'whole point of an approval is that a second person looked — a chain the '
                . 'requester can complete alone is a form, not a control.'];
        }

        $workflow = $this->workflow((string) $request['workflow']);

        if ($workflow === null) {
            return ['ok' => false, 'why' => 'The workflow this request was raised under is no longer '
                . 'declared, so there is no chain to advance it along. Reported rather than '
                . 'approved: a request completed against a workflow nobody can read is a decision '
                . 'nobody can defend.'];
        }

        $chain = array_values(array_filter($this->chainFor($workflow, (string) $request['amount']),
            static fn (array $s): bool => $s['applies'] && $s['type'] === 'approval'));
        $current = null;
        $index = -1;

        foreach ($chain as $i => $step) {
            if ((string) $step['key'] === (string) $request['at_step']) {
                $current = $step;
                $index = $i;
            }
        }

        if ($current === null) {
            return ['ok' => false, 'why' => 'The step this request is waiting on is not in its '
                . 'chain any more — the workflow was changed after it was raised. Reported rather '
                . 'than skipped forward.'];
        }

        $needsComment = (string) ($current['requires_comment'] ?? 'never');

        if (trim($comment) === ''
            && ($needsComment === 'always' || ($needsComment === 'on_reject' && !$approve))) {
            return ['ok' => false, 'why' => sprintf(
                '%s requires a reason%s. A decision with no reason is one nobody can question later, '
                . 'and the person it affects is the one who asks.',
                (string) $current['label'],
                $needsComment === 'on_reject' ? ' when rejecting' : '',
            )];
        }

        // APPENDED, never overwritten: "had anyone rejected it first?" is the
        // question asked when something has gone wrong.
        $this->store->put(self::DECISIONS, [
            'request_id' => $requestId,
            'step' => (string) $current['key'],
            'step_label' => (string) $current['label'],
            'outcome' => $approve ? 'approved' : 'rejected',
            'comment' => $comment,
            'decided_by_id' => $byId,
            'decided_by' => $byLabel,
            'decided_at' => date('c'),
        ]);

        if (!$approve) {
            $request['state'] = self::REJECTED;
            $request['closed_at'] = date('c');
            $this->store->put(self::COLLECTION, $request);

            return ['ok' => true, 'request' => $request, 'why' => 'Rejected at '
                . $current['label'] . '. The steps after it are not asked — a rejected request '
                . 'carried on to the next approver is a question nobody needed answered.'];
        }

        $next = $finalApprover ? null : ($chain[$index + 1] ?? null);

        if ($next === null && $finalApprover && isset($chain[$index + 1])) {
            $request['state'] = self::APPROVED;
            $request['closed_at'] = date('c');
            $this->store->put(self::COLLECTION, $request);

            return ['ok' => true, 'request' => $request, 'why' => 'Approved by a final approver at '
                . $current['label'] . '. The remaining steps are not waited for.'];
        }

        if ($next === null) {
            $request['state'] = self::APPROVED;
            $request['closed_at'] = date('c');
            $this->store->put(self::COLLECTION, $request);

            return ['ok' => true, 'request' => $request, 'why' => 'Approved. That was the last step '
                . 'in the chain for this amount.'];
        }

        $request['at_step'] = (string) $next['key'];
        $this->store->put(self::COLLECTION, $request);

        return ['ok' => true, 'request' => $request, 'why' => 'Approved at ' . $current['label']
            . '. It now waits on ' . $next['label'] . '.'];
    }
}
