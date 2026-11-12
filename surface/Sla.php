<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Workflow\Node;
use SmartPRS\Platform\Workflow\NodeType;
use SmartPRS\Platform\Workflow\Sla\BusinessCalendar;
use SmartPRS\Platform\Workflow\Sla\Sweep;
use SmartPRS\Platform\Workflow\Sla\Timer;
use SmartPRS\Platform\Workflow\Sla\TimerSchedule;
use SmartPRS\Platform\Workflow\TaskState;

/**
 * `sla_hours` READ AT LAST — AND READ BY THE PLATFORM, NOT BY THIS FILE.
 *
 * Ten approval steps in `config/workflows.json` declare `sla_hours`. It is
 * editable on the configure-workflow screen, and until this class existed
 * nothing anywhere read it: at hour 49 of a 48-hour SLA, nothing whatsoever
 * happened. `Platform\Workflow\Sla` — `TimerSchedule`, `Timer`, `Sweep` — was
 * finished, tested, and called by nothing.
 *
 * So this is a BRIDGE and deliberately not an engine. It turns the linear chain
 * `Surface\Requests` actually runs into the `Node` and `Timer` values the
 * platform already knows how to decide about, asks `Sweep::decide()`, and
 * reports what came back. There is no lateness arithmetic in this file. There
 * is no second definition of "breached", of "stale", or of "who gets told".
 * Every one of those numbers comes out of the platform, which is the only way
 * the answer on this screen and the answer a real timer table would give can be
 * the same answer.
 *
 * THE THREE PROPERTIES `Sweep` ENFORCES, AND WHY THEY SURVIVE THE BRIDGE:
 *
 *   1. THE BREACH TIME COMES FROM `fire_at`, NEVER FROM THE SWEEP'S CLOCK.
 *      `fireAt` here is computed from stored inputs — the instant the step
 *      became the open one (its last decision, or `raised_at`) plus the
 *      declared hours — so it is the same instant on every sweep, whether this
 *      runs at 09:00 or three days later after an outage. `breached_at` below
 *      is that instant. It is never `$now`.
 *
 *   2. AN AUTOMATIC DECISION ON A STALE TIMER IS SUPPRESSED. No step in this
 *      build declares an escalation policy, so today nothing can auto-decide
 *      and there is nothing to suppress. The moment one does, `Sweep` withholds
 *      it and this class reports the suppression rather than hiding it — a
 *      catch-up sweep after a scheduler outage must not silently approve a
 *      backlog. The suppression is NOT reimplemented here; it is read off the
 *      decision.
 *
 *   3. REMINDERS ARE COALESCED PER RECIPIENT. `Sweep::coalesce()` does it. An
 *      approver sitting on fourteen overdue requests is one digest line naming
 *      fourteen, not fourteen lines.
 *
 * WHAT THIS DOES NOT DO, WHICH IS MOST OF WHAT PEOPLE ASSUME AN SLA DOES.
 * Nothing here notifies anybody, escalates anything, reassigns anything or
 * decides anything. There is no notification channel in this build:
 * `Platform\Notification\Outbox` records message states and sends no message
 * anywhere. An overdue approval is REPORTED — it appears in this review, and in
 * `tools/workflow-sweep.php`'s output, and nowhere else. The approver it is
 * waiting on is not told. See `REPORTED_NOT_SENT`, which is carried on every
 * digest entry rather than written once in a comment, because the difference
 * between "escalated" and "shown on a page somebody may not open" is exactly
 * the claim this product keeps having to remove.
 *
 * ponytail: timers are DERIVED at read time, not stored rows. §IX.7.3 wants a
 * durable timer table and `Platform\Workflow\Persistence\TimerRepository` is
 * it; deriving them costs nothing while nothing is sent, because the only thing
 * a stored row buys that a derived one does not is R-IX-060 idempotency — "this
 * chaser already went out". Nothing goes out. The upgrade path is to write the
 * timers at `raise()`/`decide()` and sweep the table instead of this loop; the
 * shapes below do not change.
 */
final class Sla
{
    /** A deadline is declared and it has not passed. */
    public const WITHIN = 'within';

    /** A deadline is declared and it has passed. */
    public const BREACHED = 'breached';

    /**
     * NOT "on time". A step nobody set a deadline for.
     *
     * Its own state, never folded in with the ones that are inside their SLA,
     * because the two are opposite facts about the configuration: one is a
     * promise being kept and the other is no promise at all. Counting them
     * together produces the "98% within SLA" number that is really "we did not
     * set deadlines on most of these".
     */
    public const NO_DEADLINE = 'no_deadline';

    /** The question cannot be asked: no workflow, no step, or an unreadable one. */
    public const UNANSWERABLE = 'unanswerable';

    /** What this build does about a breach, spelled the same way everywhere. */
    public const ACTION_TAKEN = 'reported only — nobody was told';

    /**
     * The sentence a screen prints under any breach list, and the one carried on
     * every digest entry.
     */
    public const REPORTED_NOT_SENT =
        'REPORTED, NOT SENT. This is a report and not a notification: nobody has been told. '
        . 'There is no notification channel in this build — Platform\\Notification\\Outbox records '
        . 'message states and delivers nothing — so an overdue approval is visible to whoever opens '
        . 'this page and invisible to everybody else, including the approver it is waiting on. '
        . 'Nothing has been escalated, reassigned or decided. "Escalated" and "shown on a page '
        . 'somebody may not open" are different things, and only the second one has happened.';

    private readonly BusinessCalendar $calendar;

    public function __construct(private readonly Requests $requests)
    {
        // 24/7, because `sla_hours` is the only thing declared and it says
        // hours, not working hours. Stated as a CHOICE rather than left to a
        // null the arithmetic reinterprets: a 48-hour SLA that starts at 18:00
        // on Friday breaches on Sunday evening, when nobody was going to look
        // at it. `BusinessCalendar` already does working-hours arithmetic; it
        // needs a calendar declaration to do it against, and there is none.
        $this->calendar = BusinessCalendar::alwaysOpen();
    }

    /**
     * Every open request, asked of the platform, plus the coalesced digest.
     *
     * @return array{
     *     as_of: string,
     *     requests: list<array<string, mixed>>,
     *     digest: list<array<string, mixed>>,
     *     counts: array{open: int, within: int, breached: int, no_deadline: int, unanswerable: int, suppressed: int},
     *     reported_not_sent: string
     * }
     */
    public function review(\DateTimeImmutable $now): array
    {
        $rows = [];
        $decisions = [];
        $assignees = [];

        foreach ($this->requests->open() as $request) {
            [$row, $taken] = $this->consider($request, $now);

            $rows[] = $row;
            $assignees[(string) $row['request_id']] = (string) $row['approver'];

            foreach ($taken as $decision) {
                $decisions[] = $decision;
            }
        }

        $counts = ['open' => count($rows), 'within' => 0, 'breached' => 0,
            'no_deadline' => 0, 'unanswerable' => 0, 'suppressed' => 0];

        foreach ($rows as $row) {
            $counts[(string) $row['state']]++;
            $counts['suppressed'] += $row['suppressed'] ? 1 : 0;
        }

        return [
            'as_of' => $now->format('c'),
            'requests' => $rows,
            'digest' => $this->digest($decisions, $assignees, $rows),
            'counts' => $counts,
            'reported_not_sent' => self::REPORTED_NOT_SENT,
        ];
    }

    /**
     * ONE LINE PER PERSON, NOT ONE PER TASK — R-IX-061, done by `Sweep`.
     *
     * The coalescing is `Sweep::coalesce()`'s and not this file's. What this
     * adds is the printable line, and the honesty: `sent` is false on every
     * entry, and false is the only value it can currently take.
     *
     * @param  list<\SmartPRS\Platform\Workflow\Sla\SweepDecision> $decisions
     * @param  array<string, string>                              $assignees request id => approver
     * @param  list<array<string, mixed>>                         $rows
     * @return list<array<string, mixed>>
     */
    private function digest(array $decisions, array $assignees, array $rows): array
    {
        $byId = [];

        foreach ($rows as $row) {
            $byId[(string) $row['request_id']] = $row;
        }

        $digest = [];

        foreach (Sweep::coalesce($decisions, $assignees) as $recipient => $theirs) {
            $ids = [];
            $lines = [];

            foreach ($theirs as $decision) {
                $id = (string) ($decision->timer->taskId ?? '');
                $row = $byId[$id] ?? null;

                if ($row === null) {
                    continue;
                }

                $ids[] = $id;
                $lines[] = sprintf('%s · %s · %s, %s',
                    (string) $row['workflow_label'], (string) $row['step_label'],
                    $id, self::overBy((int) ($row['over_by_minutes'] ?? 0)));
            }

            $digest[] = [
                'recipient' => (string) $recipient,
                // The only identity this surface has for an approver is the
                // step's `by`, which is a ROLE. So "one line per person" is one
                // line per role until there is an assignment model — which is
                // an understatement of the coalescing, never an overstatement.
                'recipient_is_a_role' => true,
                'count' => count($ids),
                'request_ids' => $ids,
                'lines' => $lines,
                'summary' => sprintf('%s: %d approval(s) past their SLA.', (string) $recipient, count($ids)),
                'sent' => false,
                'why_not_sent' => self::REPORTED_NOT_SENT,
            ];
        }

        return $digest;
    }

    /**
     * One request: build its node and timers, ask the sweep, report the answer.
     *
     * @param  array<string, mixed> $request
     * @return array{0: array<string, mixed>, 1: list<\SmartPRS\Platform\Workflow\Sla\SweepDecision>}
     */
    private function consider(array $request, \DateTimeImmutable $now): array
    {
        $id = (string) $request['id'];
        $workflow = $this->requests->workflow((string) $request['workflow']);

        $row = [
            'request_id' => $id,
            'workflow' => (string) $request['workflow'],
            'workflow_label' => $workflow === null ? (string) $request['workflow'] : (string) $workflow['label'],
            'subject_id' => (string) ($request['subject_id'] ?? ''),
            'raised_by' => (string) ($request['raised_by'] ?? ''),
            'raised_at' => (string) ($request['raised_at'] ?? ''),
            'step' => (string) ($request['at_step'] ?? ''),
            'step_label' => '',
            'approver' => '',
            'activated_at' => '',
            'state' => self::UNANSWERABLE,
            'sla_hours' => null,
            'due_at' => null,
            'breached_at' => null,
            'over_by_minutes' => null,
            'suppressed' => false,
            'sweep' => [],
            'why' => '',
            'action_taken' => self::ACTION_TAKEN,
        ];

        if ($workflow === null) {
            $row['why'] = 'The workflow this request was raised under is no longer declared, so there '
                . 'is no step to read an SLA from. Reported as unanswerable rather than as on time.';

            return [$row, []];
        }

        $step = null;

        foreach ($this->requests->chainFor($workflow, (string) $request['amount']) as $candidate) {
            if ($candidate['applies'] && $candidate['type'] === 'approval'
                && (string) $candidate['key'] === (string) $request['at_step']) {
                $step = $candidate;
            }
        }

        if ($step === null) {
            $row['why'] = 'The step this request is waiting on is not in its chain any more — the '
                . 'workflow was changed after it was raised. There is no deadline to measure against, '
                . 'and guessing one would date the breach from a step nobody is on.';

            return [$row, []];
        }

        $row['step_label'] = (string) $step['label'];
        $row['approver'] = (string) ($step['by'] ?? '');

        try {
            $activatedAt = $this->activatedAt($request);
            $node = $this->nodeFor($step);
        } catch (\Exception $e) {
            $row['why'] = 'This request cannot be measured: ' . $e->getMessage()
                . ' Reported rather than treated as within its SLA.';

            return [$row, []];
        }

        $row['activated_at'] = $activatedAt->format('c');

        if ($node->slaMinutes === null) {
            $row['state'] = self::NO_DEADLINE;
            $row['why'] = sprintf('"%s" declares no due-within, so there is no deadline to be inside '
                . 'or outside of. This is not "on time" — it is a step nobody set a deadline for, and '
                . 'it is counted separately for that reason.', (string) $step['label']);

            return [$row, []];
        }

        $row['sla_hours'] = intdiv($node->slaMinutes, 60);

        // R-IX-056/§IX.7.4: the platform decides what timers a node produces.
        $timers = TimerSchedule::forNode($node, $this->calendar, $activatedAt, $id);
        $decisions = [];

        foreach ($timers as $timer) {
            if ($timer->kind === 'due') {
                $row['due_at'] = $timer->fireAt->format('c');
            }

            // The sweep decides DUE timers. A timer that has not fired is not
            // this sweep's business, exactly as `TimerRepository::claim()`
            // selects only `fire_at <= now`.
            if ($timer->fireAt >= $now) {
                continue;
            }

            $decision = Sweep::decide($timer, $node, $now, TaskState::Pending);
            $decisions[] = $decision;
            $row['sweep'][] = $decision->describe();

            // The FIRST suppression, not the last: timers fire in order, and the
            // one that explains why an automatic decision was withheld is the
            // escalation's, not the stale_check's restatement after it.
            if ($decision->isSuppressed() && !$row['suppressed']) {
                $row['suppressed'] = true;
                $row['why_suppressed'] = $decision->reason;
            }

            if ($timer->kind !== 'due') {
                continue;
            }

            $row['state'] = self::BREACHED;
            // FROM `fire_at`, NOT FROM `$now`. A sweep that runs three days late
            // still reports the breach as having happened when it happened.
            $row['breached_at'] = $timer->fireAt->format('c');
            $row['over_by_minutes'] = $decision->breachMinutes;
            $row['why'] = $decision->reason;
        }

        if ($row['state'] === self::UNANSWERABLE) {
            $row['state'] = self::WITHIN;
            $row['why'] = sprintf('Due by %s. Nothing has elapsed.', (string) $row['due_at']);
        }

        return [$row, $decisions];
    }

    /**
     * The `Node` this step is, in the platform's own vocabulary.
     *
     * `escalation_policy`, `escalation_target` and `stale_threshold_minutes` are
     * forwarded if a step declares them. Nothing in this build does, so every
     * node here is `none` and nothing can auto-decide — but forwarding them is
     * three lines, and NOT forwarding them would mean a customer who declared
     * `auto_approve` got it silently ignored, which is the defect this whole
     * class exists to remove one instance of.
     *
     * @param array<string, mixed> $step
     */
    private function nodeFor(array $step): Node
    {
        $hours = $step['sla_hours'] ?? null;

        if ($hours !== null && !is_int($hours) && !(is_string($hours) && ctype_digit($hours))) {
            throw new \InvalidArgumentException(sprintf(
                'its due-within is "%s", which is not a whole number of hours, so it cannot be a '
                . 'deadline.', is_scalar($hours) ? (string) $hours : gettype($hours)));
        }

        return new Node(
            key: (string) $step['key'],
            type: NodeType::Approval,
            label: (string) $step['label'],
            requiresComment: (string) ($step['requires_comment'] ?? 'never'),
            slaMinutes: $hours === null ? null : (int) $hours * 60,
            // A single at-deadline chaser, so the breach produces the NOTIFY
            // decision `Sweep::coalesce()` digests. §IX.7.4 offsets are minutes
            // from due_at; 0 is "when it goes overdue".
            // ponytail: not configurable. `reminder_offsets` on a step would
            // pass straight through to `reminderSchedule` when somebody wants
            // pre-due nudges.
            reminderSchedule: $hours === null ? [] : [0],
            escalationPolicy: (string) ($step['escalation_policy'] ?? 'none'),
            escalationTarget: isset($step['escalation_target']) ? (string) $step['escalation_target'] : null,
            staleThresholdMinutes: isset($step['stale_threshold_minutes'])
                ? (int) $step['stale_threshold_minutes'] : null,
        );
    }

    /**
     * WHEN THIS STEP BECAME THE OPEN ONE, which is what the SLA runs from.
     *
     * The last decision taken on the request, or `raised_at` if none has been.
     * Not `raised_at` always: an expense claim raised on the 1st and approved by
     * the manager on the 9th did not put finance 8 days behind on the moment
     * they received it.
     *
     * @param array<string, mixed> $request
     */
    private function activatedAt(array $request): \DateTimeImmutable
    {
        $decisions = $this->requests->decisions((string) $request['id']);
        $last = $decisions === [] ? null : $decisions[count($decisions) - 1];

        return new \DateTimeImmutable($last === null
            ? (string) $request['raised_at']
            : (string) $last['decided_at']);
    }

    /** "2 h 05 m over". Presentation of the platform's number, not a second one. */
    public static function overBy(int $minutes): string
    {
        return AttendanceReport::hm($minutes) . ' over'; // minutes under an hour, else hours (owner, 23 Oct)
    }
}
