<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow\Sla;

use SmartPRS\Platform\Workflow\Node;

/**
 * WHAT TIMERS A NODE PRODUCES WHEN IT ACTIVATES — Part IX §IX.7.3/§IX.7.4.
 *
 * Pure: a node, a calendar, an activation instant, and out come the rows.
 * Nothing here writes, notifies or reads a clock — `$activatedAt` is the only
 * time that exists, which is what lets the same activation be replayed in a
 * test and produce the same due date three years later.
 *
 * R-IX-056: a node with no SLA produces no timers, and that is a recorded
 * configuration choice (`sla_minutes IS NULL`), not an absence of the
 * capability. The distinction matters because SmartPRS 2 has no `due_at`
 * column anywhere: there, an item assigned to somebody who never acts is
 * pending forever, invisibly.
 */
final class TimerSchedule
{
    /**
     * Every timer for one activation, in fire order.
     *
     * @param  string|null  $taskId  null for an instance-level timer
     * @return list<Timer>
     */
    public static function forNode(
        Node $node,
        BusinessCalendar $calendar,
        \DateTimeImmutable $activatedAt,
        ?string $taskId = null,
    ): array {
        if ($node->slaMinutes === null) {
            // R-IX-056. No SLA is a choice; a reminder schedule with nothing to
            // be relative to is a configuration error, and saying so is better
            // than quietly dropping the reminders somebody configured.
            if ($node->reminderSchedule !== []) {
                throw new \InvalidArgumentException(
                    "Node '{$node->key}' declares a reminder schedule and no sla_minutes. Reminder offsets are "
                    . 'relative to due_at (§IX.7.4), so without one there is nothing for them to be relative to.'
                );
            }

            return [];
        }

        $dueAt = $calendar->add($activatedAt, $node->slaMinutes);

        $timers = [new Timer('due', $dueAt, $node->key, $taskId)];

        // §IX.7.4: offsets are in minutes relative to due_at. Negative is a
        // pre-due nudge, positive an overdue chaser. They are numbered in the
        // order the author declared them, because that number is half of
        // R-IX-060's idempotency key and must not move when the list is sorted.
        foreach ($node->reminderSchedule as $index => $offset) {
            $timers[] = new Timer(
                'reminder',
                self::shift($calendar, $dueAt, $offset),
                $node->key,
                $taskId,
                $index + 1,
                offsetMinutes: $offset,
            );
        }

        if ($node->escalationPolicy !== 'none') {
            // The escalation fires AT the breach, not after a second interval:
            // §IX.7.5 defines every policy's behaviour "at breach". Repeating
            // escalation up the reporting line (R-IX-064) is the next timer,
            // created when this one fires, not a list computed up front — the
            // line above the assignee is not known until the escalation
            // resolves it.
            $timers[] = new Timer('escalation', $dueAt, $node->key, $taskId);
        }

        // R-IX-066: a node that decides on a human's behalf carries a stale
        // check, because the dangerous case is not the timer firing — it is the
        // timer firing LATE, during a catch-up sweep after an outage.
        if (self::decidesAutomatically($node)) {
            $timers[] = new Timer('stale_check', $dueAt, $node->key, $taskId);
        }

        usort($timers, static fn (Timer $a, Timer $b): int => $a->fireAt <=> $b->fireAt);

        return $timers;
    }

    /**
     * Reassignment (§IX.7.3): timers are recreated only if the node says so.
     *
     * R-IX-040's default is false, and it is the right default: a clock that
     * resets every time a task is passed on makes SLA reporting meaningless,
     * because the way to never breach is to forward the task.
     *
     * @return list<Timer> the timers now in force
     */
    public static function onReassignment(
        Node $node,
        BusinessCalendar $calendar,
        \DateTimeImmutable $originallyActivatedAt,
        \DateTimeImmutable $reassignedAt,
        ?string $newTaskId = null,
    ): array {
        return self::forNode(
            $node,
            $calendar,
            $node->reassignmentResetsSla ? $reassignedAt : $originallyActivatedAt,
            $newTaskId,
        );
    }

    public static function decidesAutomatically(Node $node): bool
    {
        return in_array($node->escalationPolicy, ['auto_approve', 'auto_reject'], true);
    }

    /**
     * A reminder offset applied to the due instant.
     *
     * On a business-hours calendar the offset is business time too — "a day
     * before" a Monday-morning deadline means the previous working day, not
     * Sunday, when nobody will read it.
     */
    private static function shift(BusinessCalendar $calendar, \DateTimeImmutable $dueAt, int $offset): \DateTimeImmutable
    {
        if ($offset >= 0) {
            return $calendar->add($dueAt, $offset);
        }

        if ($calendar->clockMode !== 'business_hours') {
            return $dueAt->modify($offset . ' minutes');
        }

        // Walking backwards through working windows is the same walk with the
        // ends swapped, and doing it by search rather than by a second
        // implementation keeps ONE definition of what a working minute is.
        $candidate = $dueAt->modify($offset . ' minutes');

        for ($guard = 0; $guard < 400; $guard++) {
            if ($calendar->elapsedMinutes($candidate, $dueAt) >= -$offset) {
                return $candidate;
            }

            $candidate = $candidate->modify('-1 hour');
        }

        return $candidate;
    }
}
