<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow;

/**
 * THE PARTICIPANT RESOLUTION ALGORITHM — Part IX §IX.5.1.
 *
 * Resolution runs AT NODE ACTIVATION, not at instance creation. That single
 * choice removes SmartPRS 2's most consequential routing limitation: escalation
 * there is computed once, at creation (RequestController.php:531), so a request
 * never re-routes when the amount is edited or the employee transfers.
 *
 * The ten steps of §IX.5.1 in order, with two that matter more than the rest:
 *
 *   Step 9, the self-approval prohibition (R-IX-036). It covers THREE
 *   identities — the initiating user, the subject employee, and anyone acting
 *   under a delegation from either — because an employee must not approve their
 *   own salary change even when somebody else raised it.
 *
 *   Step 8's prior-actor exclusion, which is segregation enforced at DECISION
 *   time and not only at publish (R-IX-039), because role membership changes
 *   between the two.
 *
 * And the rule underneath both: when the set empties, `on_no_participant`
 * applies. The engine never quietly leaves the requester assigned, and never
 * treats an empty set as "nobody needs to approve this".
 */
final class ParticipantResolver
{
    /** @var \Closure(ParticipantSpec, ResolutionContext): list<string> */
    private readonly \Closure $resolveKind;

    /**
     * @param callable(ParticipantSpec, ResolutionContext): list<string> $resolveKind
     *        Resolves one spec to candidate user ids. Supplied by the caller
     *        because it reaches org assignments, roles and groups — none of
     *        which the engine may touch itself (spine S13, the engine holds no
     *        repository).
     */
    public function __construct(callable $resolveKind)
    {
        $this->resolveKind = $resolveKind(...);
    }

    public function resolve(Node $node, ResolutionContext $context): ResolutionResult
    {
        $candidates = [];
        $producedBy = [];
        $exclusions = [];
        $substitutions = [];

        // Steps 2-3: resolve each spec in seq order, union preserving first-seen.
        $specs = $node->participants;
        usort($specs, static fn (ParticipantSpec $a, ParticipantSpec $b): int => $a->seq <=> $b->seq);

        foreach ($specs as $spec) {
            foreach (($this->resolveKind)($spec, $context) as $user) {
                if (!in_array($user, $candidates, true)) {
                    $candidates[] = $user;
                    $producedBy[$user] = $spec->describe();
                }
            }
        }

        // Steps 4-5: delegation, then out-of-office backup (§IX.5.5), chained,
        // dated, and refused when circular. `delegationAllowed` is read HERE:
        // the publisher has written that column since the workflow payload
        // landed and nothing consulted it, so a node declaring that its approval
        // may not be delegated was delegating it anyway.
        $substituted = [];

        foreach ($candidates as $user) {
            if (!$node->delegationAllowed) {
                $substituted[] = $user;

                continue;
            }

            try {
                $chain = Delegation::chain($context->delegations, $user, $context->asOf);
            } catch (CircularDelegation $e) {
                // Excluded, not fatal: one broken delegation must not block a
                // node with three other eligible approvers. If the set empties,
                // on_no_participant decides — a policy somebody wrote down.
                $exclusions[] = "{$user}: " . $e->getMessage();

                continue;
            }

            if ($chain === null) {
                $substituted[] = $user;

                continue;
            }

            $why = implode(', then ', array_map(
                static fn (Delegation $d): string => $d->describe(),
                $chain['hops'],
            ));

            $substitutions[] = "{$user} → {$chain['to']} ({$why})";

            if (!in_array($chain['to'], $substituted, true)) {
                $substituted[] = $chain['to'];
                $producedBy[$chain['to']] = ($producedBy[$user] ?? '?') . " via {$why}";
            }
        }

        $candidates = $substituted;
        $kept = [];

        foreach ($candidates as $user) {
            // Step 6: inactive, locked or lapsed as at the activation date.
            if (in_array($user, $context->inactiveUsers, true)) {
                $exclusions[] = "{$user}: the account is inactive, locked or its role has lapsed";

                continue;
            }

            // Step 7: fails closed on the node's required permission (S13).
            if ($node->requiredPermission !== null
                && !in_array($node->requiredPermission, $context->permissionsByUser[$user] ?? [], true)
            ) {
                $exclusions[] = "{$user}: does not hold {$node->requiredPermission}";

                continue;
            }

            // Step 8: prior actors in the same segregation set. Enforced HERE,
            // at decision time, and not only at publish — role membership
            // changes between publication and decision (R-IX-039).
            $decided = $context->priorActors[$user] ?? null;

            if ($decided !== null && $this->sharesSegregationSet($context, $node->key, $decided)) {
                $exclusions[] = "{$user}: already decided '{$decided}', which is in a segregation set with "
                    . "'{$node->key}'";

                continue;
            }

            // Step 9: the self-approval prohibition (R-IX-036), all three
            // identities. Overridden only by the definition, never by silence.
            $selfReason = $this->selfApprovalReason($user, $context);

            if ($selfReason !== null && !$context->selfApprovalPermitted) {
                $exclusions[] = "{$user}: {$selfReason} (R-IX-036)";

                continue;
            }

            $kept[] = $user;
        }

        // Step 10: an empty set is a policy decision, never a silent pass.
        return new ResolutionResult(
            $node->key,
            $kept,
            array_intersect_key($producedBy, array_flip($kept)),
            $exclusions,
            $substitutions,
            $kept === [] ? $node->onNoParticipant : null,
        );
    }

    /**
     * The three identities R-IX-036 covers, named so the trace can say which.
     */
    private function selfApprovalReason(string $user, ResolutionContext $context): ?string
    {
        if ($user === $context->requesterUserId) {
            return 'is the requester';
        }

        if ($context->subjectEmployeeUserId !== null && $user === $context->subjectEmployeeUserId) {
            return 'is the employee this subject concerns — an employee must not approve their own change '
                . 'even when somebody else raised it';
        }

        // The third identity: whoever holds the work under a delegation from
        // either of the first two. Followed through the CHAIN and only while the
        // delegation is in force — a delegation that expired last month does not
        // taint the person who once held it.
        foreach ([$context->requesterUserId, $context->subjectEmployeeUserId] as $barred) {
            if ($barred === null) {
                continue;
            }

            try {
                $chain = Delegation::chain($context->delegations, $barred, $context->asOf);
            } catch (CircularDelegation | AmbiguousDelegation) {
                // A chain we cannot resolve is one we cannot clear either. Fail
                // closed: if the requester's delegation is broken, nobody
                // downstream of it is cleared to approve their own request.
                return "holds or may hold a delegation from {$barred}, whose delegation chain does not "
                    . 'resolve — an unresolvable chain is not evidence of separation (R-IX-036)';
            }

            if ($chain === null) {
                continue;
            }

            $holders = array_map(static fn (Delegation $d): string => $d->to, $chain['hops']);

            if (in_array($user, $holders, true)) {
                return "holds a delegation from {$barred}, who may not approve this";
            }
        }

        return null;
    }

    private function sharesSegregationSet(ResolutionContext $context, string $nodeKey, string $decidedNodeKey): bool
    {
        foreach ($context->segregationSets as $set) {
            $keys = $set['node_keys'] ?? [];

            if (in_array($nodeKey, $keys, true) && in_array($decidedNodeKey, $keys, true)) {
                return true;
            }
        }

        return false;
    }
}
