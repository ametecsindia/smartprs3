<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow;

use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Scope;

/**
 * A workflow definition (Part IX §IX.3).
 *
 * §IX.3.1: an ordinary configuration artefact. It carries a scope, a priority,
 * an effective range, a version number and a status, and resolves through the
 * same `resolve(type, key, scope, as_of)` as a rule or a formula — because
 * spine S4 is one mechanism, not three that look alike.
 *
 * The structural invariants live in the constructor; the eighteen publish checks
 * of §IX.3.6 live in Validator, because several of them need the tenant's roles,
 * users and groups and so cannot be answered by the definition alone.
 */
final readonly class WorkflowDefinition
{
    public const STATUSES = ['draft', 'testing', 'pending_approval', 'published', 'superseded', 'archived'];

    public const SELF_APPROVAL_OVERRIDES = ['none', 'permission', 'named_role', 'workflow'];

    public const RETURN_POLICIES = ['restart', 'resume_at_return_node'];

    public const CANCEL_POLICIES = [
        'requester_until_first_decision',
        'requester_always',
        'permission_only',
        'never',
    ];

    public const MIN_DESCRIPTION = 20;

    /** @var array<string, Node> */
    public array $nodesByKey;

    /** @var list<Node> */
    public array $nodes;

    /** @var list<Transition> */
    public array $transitions;

    /**
     * @param list<mixed>      $nodes        declared wide: this constructor is the boundary
     *        a caller hands nodes to, and the loop below is what actually proves each one is
     *        a Node before it is stored
     * @param list<mixed>      $transitions  declared wide for the same reason as $nodes above
     * @param list<array{set_key: string, node_keys: list<string>, rule: string}> $segregationSets
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $businessDescription,
        public string $subjectEntityKey,
        public string $startNodeKey,
        array $nodes,
        array $transitions,
        public Scope $scope,
        public EffectiveRange $range,
        public int $versionNo,
        public string $versionId,
        public string $status,
        public bool $allowsSelfApproval = false,
        public string $selfApprovalOverride = 'none',
        public array $segregationSets = [],
        public string $returnPolicy = 'restart',
        public string $cancelPolicy = 'requester_until_first_decision',
        public ?string $subjectAmountPath = null,
        public ?string $instanceKeyExpression = null,
        public ?string $slaCalendarKey = null,
        public int $priority = 0,
        public bool $isSection56Operation = false,
    ) {
        if (!preg_match('/^[A-Z][A-Z0-9-]{2,63}$/', $key)) {
            throw new \InvalidArgumentException("Workflow key '{$key}' must match /^[A-Z][A-Z0-9-]{2,63}$/.");
        }

        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Workflow {$key}: '{$status}' is not a status.");
        }

        // R-IX-012: the description is what the trace and the audit ledger render.
        if (mb_strlen(trim($businessDescription)) < self::MIN_DESCRIPTION) {
            throw new \InvalidArgumentException(
                "Workflow {$key}: business_description is mandatory and at least "
                . self::MIN_DESCRIPTION . ' characters (R-IX-012).'
            );
        }

        if (!in_array($selfApprovalOverride, self::SELF_APPROVAL_OVERRIDES, true)) {
            throw new \InvalidArgumentException("Workflow {$key}: '{$selfApprovalOverride}' is not a self-approval override.");
        }

        if (!in_array($returnPolicy, self::RETURN_POLICIES, true)) {
            throw new \InvalidArgumentException("Workflow {$key}: '{$returnPolicy}' is not a return policy.");
        }

        if (!in_array($cancelPolicy, self::CANCEL_POLICIES, true)) {
            throw new \InvalidArgumentException("Workflow {$key}: '{$cancelPolicy}' is not a cancel policy.");
        }

        // R-IX-037: no configuration by which one person alone completes a
        // maker-checker control. This is precisely what `admin` satisfying both
        // isHR() and isFinance() achieves today (SalaryApprovalController:59-68).
        if ($isSection56Operation && ($allowsSelfApproval || $selfApprovalOverride !== 'none')) {
            throw new \InvalidArgumentException(
                "Workflow {$key}: a Section 56 operation may not permit self-approval by any route (R-IX-037). "
                . "The only honest override is 'workflow', which routes to a different person and so is not "
                . 'self-approval at all — and even that is refused here.'
            );
        }

        $byKey = [];

        foreach ($nodes as $node) {
            if (!$node instanceof Node) {
                throw new \InvalidArgumentException("Workflow {$key}: nodes are Node values.");
            }

            if (isset($byKey[$node->key])) {
                throw new \InvalidArgumentException("Workflow {$key}: duplicate node key '{$node->key}'.");
            }

            $byKey[$node->key] = $node;
        }

        if ($byKey === []) {
            throw new \InvalidArgumentException("Workflow {$key}: a definition needs at least one node.");
        }

        foreach ($transitions as $transition) {
            if (!$transition instanceof Transition) {
                throw new \InvalidArgumentException("Workflow {$key}: transitions are Transition values.");
            }
        }

        /** @var list<Node> $nodes */
        $this->nodes = $nodes;
        /** @var list<Transition> $transitions */
        $this->transitions = $transitions;
        $this->nodesByKey = $byKey;
    }

    public function node(string $key): ?Node
    {
        return $this->nodesByKey[$key] ?? null;
    }

    public function startNode(): ?Node
    {
        return $this->node($this->startNodeKey);
    }

    /**
     * The transitions out of a node, in declared order — first match wins.
     *
     * @return list<Transition>
     */
    public function transitionsFrom(string $nodeKey): array
    {
        $out = [];

        foreach ($this->transitions as $transition) {
            if ($transition->from === $nodeKey) {
                $out[] = $transition;
            }
        }

        usort($out, static fn (Transition $a, Transition $b): int => $a->order <=> $b->order);

        return $out;
    }

    /** @return list<Node> the nodes of a given type */
    public function nodesOfType(NodeType $type): array
    {
        return array_values(array_filter(
            $this->nodesByKey,
            static fn (Node $n): bool => $n->type === $type,
        ));
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function label(): string
    {
        return $this->key . ' v' . $this->versionNo;
    }

    public function describe(): string
    {
        return $this->label() . ' · ' . $this->subjectEntityKey . ' · ' . count($this->nodesByKey) . ' nodes';
    }
}
