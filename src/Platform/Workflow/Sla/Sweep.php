<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow\Sla;

use SmartPRS\Platform\Workflow\Node;
use SmartPRS\Platform\Workflow\TaskState;

/**
 * THE `workflow:timers` SWEEP — Part IX §IX.7.6.
 *
 * Pure decisions. The repository selects due timers `FOR UPDATE SKIP LOCKED`
 * and writes what comes back; this decides what should happen, and can be run
 * against a hypothetical clock without touching anything.
 *
 * Three properties from §IX.7.6, and each one is a defect somewhere else when
 * it is missing:
 *
 *   1. BREACH TIME COMES FROM `fire_at`, NEVER FROM THE SWEEP'S WALL CLOCK.
 *      A notification can be late; a report about when the breach happened
 *      must not be.
 *   2. AN AUTOMATIC DECISION ON A STALE TIMER IS SUPPRESSED (R-IX-066). This
 *      is the one place where "the job caught up" is the dangerous outcome: a
 *      scheduler outage followed by a catch-up sweep must never silently
 *      approve a backlog of payments.
 *   3. REMINDERS ARE COALESCED PER RECIPIENT (R-IX-061). An approver with
 *      fourteen overdue tasks gets one digest naming fourteen items. A
 *      reminder storm is SmartPRS 2's broadcast defect with a schedule
 *      attached.
 */
final class Sweep
{
    /**
     * How late a timer may fire before an automatic decision is withheld, when
     * the node declares no `stale_timer_policy` threshold of its own.
     *
     * Not a business default — a business default would be a Law 1 violation.
     * It is the boundary at which this code stops believing its own clock, and
     * the direction it errs in is the safe one: suppress and alert rather than
     * decide. A node that wants a different threshold declares one.
     */
    public const DEFAULT_STALE_THRESHOLD_MINUTES = 15;

    /**
     * Decide one timer.
     *
     * @param TaskState|null $taskState the current state of the timer's task;
     *                                  null for an instance-level timer
     */
    public static function decide(
        Timer $timer,
        Node $node,
        \DateTimeImmutable $now,
        ?TaskState $taskState = TaskState::Pending,
    ): SweepDecision {
        $lateness = $timer->latenessMinutes($now);

        // A decided task's timers are dead weight. They are cancelled rather
        // than deleted, so "this reminder never went out because the task was
        // already approved" stays answerable.
        if ($taskState !== null && $taskState !== TaskState::Pending) {
            return new SweepDecision(
                $timer,
                SweepDecision::CANCELLED,
                "the task was already {$taskState->value}, so this timer has nothing left to act on",
                latenessMinutes: $lateness,
            );
        }

        return match ($timer->kind) {
            'reminder' => self::reminder($timer, $node, $lateness),
            'due' => self::due($timer, $node, $now, $lateness),
            'escalation' => self::escalation($timer, $node, $now, $lateness),
            'stale_check' => self::staleCheck($timer, $node, $lateness),
            default => new SweepDecision(
                $timer,
                SweepDecision::RECORD_ONLY,
                "timer kind '{$timer->kind}' has no sweep behaviour declared",
                latenessMinutes: $lateness,
            ),
        };
    }

    /**
     * The breach itself.
     *
     * R-IX-065: every breach emits `workflow.sla.breached` whether or not an
     * escalation policy acts on it. Reporting on breaches must not depend on
     * somebody having configured an escalation — which is exactly how a breach
     * report ends up showing only the workflows that were already being watched.
     */
    private static function due(Timer $timer, Node $node, \DateTimeImmutable $now, int $lateness): SweepDecision
    {
        return new SweepDecision(
            $timer,
            SweepDecision::RECORD_ONLY,
            'the SLA elapsed; the breach is recorded and reportable',
            events: ['workflow.sla.breached'],
            latenessMinutes: $lateness,
            // Measured from the stored fire_at, so a sweep that ran three hours
            // late still reports a breach of the right duration.
            breachMinutes: $timer->latenessMinutes($now),
        );
    }

    private static function reminder(Timer $timer, Node $node, int $lateness): SweepDecision
    {
        return new SweepDecision(
            $timer,
            SweepDecision::NOTIFY,
            ($timer->offsetMinutes ?? 0) < 0
                ? 'a nudge before the SLA elapses'
                : 'a chaser after the SLA elapsed',
            events: ['workflow.reminder.due'],
            target: $node->escalationTarget,
            latenessMinutes: $lateness,
        );
    }

    /** §IX.7.5's table, one row at a time. */
    private static function escalation(Timer $timer, Node $node, \DateTimeImmutable $now, int $lateness): SweepDecision
    {
        $breach = $timer->latenessMinutes($now);

        if (TimerSchedule::decidesAutomatically($node)) {
            $threshold = self::staleThreshold($node);

            // R-IX-066, and the whole reason this class returns decisions
            // instead of performing them.
            if ($lateness > $threshold) {
                return new SweepDecision(
                    $timer,
                    SweepDecision::SUPPRESSED,
                    "this timer fired {$lateness} minutes after it was due, over the stale threshold of "
                    . "{$threshold}. An automatic {$node->escalationPolicy} on a stale timer is a decision "
                    . 'nobody took during an outage nobody noticed, so it is withheld and the instance is '
                    . 'blocked with reason stale_timer (R-IX-066).',
                    events: ['workflow.sla.breached', 'workflow.timer.stale'],
                    latenessMinutes: $lateness,
                    breachMinutes: $breach,
                );
            }

            return new SweepDecision(
                $timer,
                SweepDecision::AUTO_DECIDE,
                "escalation_policy is {$node->escalationPolicy} and the SLA elapsed",
                events: ['workflow.sla.breached', 'workflow.task.auto_decided'],
                outcome: $node->escalationPolicy === 'auto_approve' ? 'approved' : 'rejected',
                latenessMinutes: $lateness,
                breachMinutes: $breach,
            );
        }

        return match ($node->escalationPolicy) {
            'notify_only' => new SweepDecision(
                $timer,
                SweepDecision::NOTIFY,
                'the escalation target is notified; the task stays with its assignee',
                events: ['workflow.sla.breached', 'workflow.sla.escalated'],
                target: $node->escalationTarget,
                latenessMinutes: $lateness,
                breachMinutes: $breach,
            ),
            'reassign' => new SweepDecision(
                $timer,
                SweepDecision::REASSIGN,
                'the task is closed expired and a new one opened for the escalation target',
                events: ['workflow.sla.breached', 'workflow.sla.escalated'],
                target: $node->escalationTarget,
                latenessMinutes: $lateness,
                breachMinutes: $breach,
            ),
            'route_to_node' => new SweepDecision(
                $timer,
                SweepDecision::ROUTE_TO_NODE,
                'open tasks are closed expired and the named node is activated',
                events: ['workflow.sla.breached', 'workflow.sla.escalated'],
                target: $node->escalationTarget,
                latenessMinutes: $lateness,
                breachMinutes: $breach,
            ),
            default => new SweepDecision(
                $timer,
                SweepDecision::RECORD_ONLY,
                'escalation_policy is none; the breach is recorded and the task stays',
                events: ['workflow.sla.breached'],
                latenessMinutes: $lateness,
                breachMinutes: $breach,
            ),
        };
    }

    /**
     * The stale check as its own timer.
     *
     * It exists so the suppression is visible in the timer table rather than
     * only in the escalation's behaviour: an operator looking at why a payment
     * batch did not auto-approve finds a row saying so.
     */
    private static function staleCheck(Timer $timer, Node $node, int $lateness): SweepDecision
    {
        $threshold = self::staleThreshold($node);

        return $lateness > $threshold
            ? new SweepDecision(
                $timer,
                SweepDecision::SUPPRESSED,
                "the sweep is {$lateness} minutes behind this timer, over the {$threshold} minute stale "
                . 'threshold — SLA processing is degraded and automatic decisions are withheld (R-IX-066)',
                events: ['workflow.timer.stale'],
                latenessMinutes: $lateness,
            )
            : new SweepDecision(
                $timer,
                SweepDecision::RECORD_ONLY,
                "the sweep is within {$threshold} minutes of this timer, so automatic decisions are trusted",
                latenessMinutes: $lateness,
            );
    }

    private static function staleThreshold(Node $node): int
    {
        $declared = $node->staleThresholdMinutes;

        return $declared === null || $declared < 1 ? self::DEFAULT_STALE_THRESHOLD_MINUTES : $declared;
    }

    /**
     * R-IX-061: one digest per recipient, not one message per timer.
     *
     * Coalescing is done on the SWEEP'S output rather than inside the
     * notification engine, because the engine cannot know that fourteen
     * messages arriving together were one sweep — and "fourteen overdue tasks"
     * is a different message from "a task is overdue", fourteen times.
     *
     * @param  list<SweepDecision>            $decisions
     * @param  array<string, string>          $assignees  task id => recipient
     * @return array<string, list<SweepDecision>> recipient => their decisions
     */
    public static function coalesce(array $decisions, array $assignees): array
    {
        $byRecipient = [];

        foreach ($decisions as $decision) {
            if ($decision->action !== SweepDecision::NOTIFY) {
                continue;
            }

            $recipient = $decision->target
                ?? $assignees[$decision->timer->taskId ?? '']
                ?? null;

            if ($recipient === null) {
                continue;
            }

            $byRecipient[$recipient][] = $decision;
        }

        return $byRecipient;
    }
}
