<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow;

/**
 * A running workflow instance (Part IX §IX.6).
 *
 * R-IX-051: an in-flight instance completes on the `config_version_id` it
 * started on. Publishing a new workflow version never re-routes something
 * already in flight — the version is pinned here at start and read from here
 * for the rest of the instance's life.
 *
 * R-IX-043: no transition out of a terminal state exists. A correction is a new
 * instance carrying `supersedesInstanceId`.
 */
final readonly class Instance
{
    /**
     * @param list<Task>   $tasks
     * @param list<string> $trace  one line per transition, in order
     */
    public function __construct(
        public string $id,
        public string $definitionKey,
        public string $definitionVersionId,
        public string $subjectId,
        public string $requesterUserId,
        public string $currentNodeKey,
        public InstanceState $state = InstanceState::Running,
        public array $tasks = [],
        public array $trace = [],
        public ?string $instanceKey = null,
        public ?string $supersedesInstanceId = null,
        public int $seq = 0,
    ) {
    }

    /**
     * @throws \LogicException on a transition the state model does not permit
     */
    public function movedTo(
        InstanceState $state,
        string $nodeKey,
        string $traceLine,
        ?string $resumeReason = null,
    ): self {
        if (!$this->state->canMoveTo($state) && $this->state !== $state) {
            $why = $this->state->isTerminal()
                ? "instance {$this->id} is {$this->state->value}, which is terminal. A correction is a NEW "
                    . 'instance carrying supersedes_instance_id, so both the original decision and the '
                    . 'correction are in the ledger (R-IX-043). There is no resurrection.'
                : "instance {$this->id} cannot move from {$this->state->value} to {$state->value}";

            throw new \LogicException($why);
        }

        if ($this->state->needsResumeAuthority($state) && ($resumeReason === null || $resumeReason === '')) {
            throw new \LogicException(
                "Resuming instance {$this->id} from {$this->state->value} needs "
                . 'workflow.instance.resume and a stated reason (§IX.6.1).'
            );
        }

        return new self(
            $this->id,
            $this->definitionKey,
            $this->definitionVersionId,
            $this->subjectId,
            $this->requesterUserId,
            $nodeKey,
            $state,
            $this->tasks,
            [...$this->trace, $traceLine],
            $this->instanceKey,
            $this->supersedesInstanceId,
            $this->seq + 1,
        );
    }

    /** @param list<Task> $tasks */
    public function withTasks(array $tasks): self
    {
        return new self(
            $this->id,
            $this->definitionKey,
            $this->definitionVersionId,
            $this->subjectId,
            $this->requesterUserId,
            $this->currentNodeKey,
            $this->state,
            $tasks,
            $this->trace,
            $this->instanceKey,
            $this->supersedesInstanceId,
            $this->seq,
        );
    }

    /**
     * The correction path R-IX-043 permits: a new instance that points at this
     * one, rather than a write that erases what this one decided.
     */
    public function supersededBy(string $newInstanceId): self
    {
        if (!$this->state->isTerminal()) {
            throw new \LogicException(
                "Instance {$this->id} is still {$this->state->value}. Supersede a DECIDED instance; "
                . 'cancel a running one.'
            );
        }

        return new self(
            $newInstanceId,
            $this->definitionKey,
            $this->definitionVersionId,
            $this->subjectId,
            $this->requesterUserId,
            $this->currentNodeKey,
            InstanceState::Running,
            [],
            ["supersedes {$this->id}, which is {$this->state->value}"],
            $this->instanceKey,
            $this->id,
        );
    }

    /** @return list<Task> */
    public function openTasks(): array
    {
        return array_values(array_filter($this->tasks, static fn (Task $t): bool => $t->isOpen()));
    }

    public function isTerminal(): bool
    {
        return $this->state->isTerminal();
    }
}
