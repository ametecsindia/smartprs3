<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow;

/**
 * INSTANCE ADVANCE — Part IX §IX.6.5, §IX.6.7, §IX.4.
 *
 * R-IX-049: node completion evaluation happens under a row lock on the instance
 * and is THE ONLY place node outcomes are computed. This class is that place.
 * SmartPRS 2's leave balance check has no transaction, no lock and no re-check
 * at approval, so two simultaneous applications both pass; a quorum tally
 * computed in application memory is the same race with a different name.
 *
 * R-IX-021: no node resolves an error into an approval. An evaluation failure,
 * a resolution failure or a timeout PARKS or FAILS the instance and raises an
 * alert. SmartPRS 2 destroys 50.5% of its caught exceptions and its
 * authorisation helper returns "allowed" on any exception
 * (ApprovalService.php:365-380) — which is the same shape of bug, upstream.
 */
final class Engine
{
    /** @var \Closure(string, Instance): bool */
    private readonly \Closure $evaluateGuard;

    /**
     * @param callable(string, Instance): bool $evaluateGuard
     *        Evaluates a Boolean SPX expression against the instance context.
     *        Supplied by the caller; a throw from it parks the instance.
     */
    public function __construct(callable $evaluateGuard)
    {
        $this->evaluateGuard = $evaluateGuard(...);
    }

    /**
     * The outcome of a node whose tasks have all been decided (§IX.6.5).
     *
     * @param list<Task> $tasks the tasks of ONE node
     */
    public function outcomeOf(Node $node, array $tasks): ?string
    {
        $approved = 0;
        $rejected = 0;
        $returned = 0;
        $decided = 0;

        foreach ($tasks as $task) {
            if ($task->isOpen()) {
                continue;
            }

            $decided++;

            match ($task->state) {
                TaskState::Approved => $approved++,
                TaskState::Rejected => $rejected++,
                TaskState::Returned => $returned++,
                default => null,
            };
        }

        return match ($node->type) {
            // §IX.6.5: "the REJECTION WINS deterministically: completion
            // evaluation applies rejection precedence regardless of arrival
            // order. The approval is still recorded as a fact and appears in
            // the trace." Order of arrival deciding an outcome is how the same
            // inputs produce two different answers on two different days.
            NodeType::Approval, NodeType::ParallelAll => match (true) {
                $rejected > 0 => 'rejected',
                $returned > 0 => 'returned',
                $decided === count($tasks) && $tasks !== [] => $node->type === NodeType::ParallelAll
                    ? 'joined'
                    : 'approved',
                default => null,
            },

            NodeType::ParallelAny => $decided > 0 ? 'first_completed' : null,

            NodeType::Quorum => $this->quorumOutcome($node, $approved, $rejected, count($tasks)),

            NodeType::ReturnForEdit => match (true) {
                $returned > 0 => 'returned',
                $decided > 0 => 'resubmitted',
                default => null,
            },

            default => null,
        };
    }

    /**
     * §IX.4.6. `n` approvals decide it; the moment enough rejections exist that
     * `n` approvals are no longer POSSIBLE, it is decided the other way — a
     * quorum that waits for a majority it can no longer reach is a task sitting
     * in an inbox for nothing.
     */
    private function quorumOutcome(Node $node, int $approved, int $rejected, int $taskCount): ?string
    {
        $n = (int) ($node->params['n'] ?? 0);
        $m = (int) ($node->params['m'] ?? $taskCount);

        if ($approved >= $n) {
            return 'approved';
        }

        if ($rejected > $m - $n) {
            return 'rejected';
        }

        return null;
    }

    /**
     * The transition a node's outcome takes (§IX.3.2): the first declared
     * transition whose outcome matches and whose condition holds. First match
     * wins, in declared order, so an administrator reads it top to bottom.
     *
     * @throws NodeStranded when no transition matches — an instance that has
     *         nowhere to go parks; it never picks a branch to be helpful.
     */
    public function nextNode(WorkflowDefinition $definition, string $nodeKey, string $outcome, Instance $instance): string
    {
        foreach ($definition->transitionsFrom($nodeKey) as $transition) {
            if ($transition->outcome !== $outcome) {
                continue;
            }

            if ($transition->conditionExpression === null) {
                return $transition->to;
            }

            try {
                if (($this->evaluateGuard)($transition->conditionExpression, $instance)) {
                    return $transition->to;
                }
            } catch (\Throwable $e) {
                // R-IX-021: an evaluation failure parks. It never falls through
                // to the next transition, because "the condition errored so we
                // took the other branch" is a decision nobody made.
                throw new NodeStranded(
                    $nodeKey,
                    $outcome,
                    "evaluating `{$transition->conditionExpression}` failed: " . $e->getMessage(),
                );
            }
        }

        throw new NodeStranded($nodeKey, $outcome, 'no declared transition matches');
    }

    /**
     * §IX.6.7: a node whose guard is false is SKIPPED, not failed — and the
     * skip is recorded, because a step that silently did not happen is
     * indistinguishable from one that happened and did nothing.
     */
    public function shouldSkip(Node $node, Instance $instance): bool
    {
        if ($node->guardExpression === null) {
            return false;
        }

        try {
            return !($this->evaluateGuard)($node->guardExpression, $instance);
        } catch (\Throwable $e) {
            throw new NodeStranded(
                $node->key,
                'guard',
                "evaluating the guard `{$node->guardExpression}` failed: " . $e->getMessage(),
            );
        }
    }
}
