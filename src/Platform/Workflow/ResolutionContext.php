<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow;

/**
 * The prepared context participant resolution reads (Part IX §IX.5.1 step 1).
 *
 * Assembled by the caller after authorisation. The engine holds no repository
 * and no connection, so nothing here can be fetched from inside — the same
 * stance the rule and formula engines take, for the same reason.
 *
 * R-IX-027: org assignments are read AS AT the activation date, effective-dated,
 * never from a current-state column on the employee. An employee who transferred
 * on 1 August, whose leave request activated its department-head node on 31
 * July, routes to the July head.
 */
final readonly class ResolutionContext
{
    /**
     * @param array<string, list<string>> $permissionsByUser user id => permission codes
     * @param list<Delegation>            $delegations       delegations AND out-of-office backups,
     *        of any date. They were an `array<from, to>` map until the WC-09 work: a map cannot
     *        express an expired delegation, so "active, expired and circular" had two states it
     *        could not represent. Filtering by `asOf` happens in the resolver, not here, because
     *        the whole set is what a cycle is detected against.
     * @param array<string, string>       $priorActors       user id => the node key they already decided
     * @param list<string>                $inactiveUsers
     * @param list<array{set_key: string, node_keys: list<string>, rule: string}> $segregationSets
     */
    public function __construct(
        public \DateTimeImmutable $asOf,
        public string $requesterUserId,
        public ?string $subjectEmployeeUserId = null,
        public array $permissionsByUser = [],
        public array $delegations = [],
        public array $priorActors = [],
        public array $inactiveUsers = [],
        public array $segregationSets = [],
        public bool $selfApprovalPermitted = false,
    ) {
    }

    public function withPriorActor(string $user, string $nodeKey): self
    {
        return new self(
            $this->asOf,
            $this->requesterUserId,
            $this->subjectEmployeeUserId,
            $this->permissionsByUser,
            $this->delegations,
            [$user => $nodeKey] + $this->priorActors,
            $this->inactiveUsers,
            $this->segregationSets,
            $this->selfApprovalPermitted,
        );
    }
}
