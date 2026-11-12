<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow;

/**
 * One node of a workflow definition (Part IX §IX.3.2).
 *
 * R-IX-012: every node's label must be non-empty. It is what the inbox, the
 * trace and the audit ledger render, and an unnamed step is an unexplainable
 * step (Law 4).
 *
 * R-IX-011: `params` are validated at publish against the schema declared for
 * the node type; an unknown key is a publish error, never silently discarded.
 * That rule exists because SmartPRS 2's safeRow() (ApprovalService.php:262-266)
 * drops any field the table does not have — no error, no log, no user signal —
 * losing nine known fields including the expense reason and the loan tenure.
 */
final readonly class Node
{
    /** §IX.3.2. What happens when nobody resolves (§IX.5.4). */
    public const ON_NO_PARTICIPANT = [
        'fail_instance',
        'route_to_node',
        'assign_to_fallback',
        'auto_approve_with_reason',
    ];

    public const ESCALATION_POLICIES = [
        'none', 'notify_only', 'reassign', 'route_to_node', 'auto_approve', 'auto_reject',
    ];

    public const COMMENT_REQUIREMENTS = ['never', 'on_reject', 'always'];

    /** The keys each node type accepts in `params`. R-IX-011's schema, minimal. */
    private const PARAM_SCHEMA = [
        'quorum' => ['n', 'm', 'tie_policy'],
        'condition_branch' => ['branches'],
        'auto_approve' => ['expression', 'reason'],
        'return_for_edit' => ['max_returns'],
        'parallel_all' => ['children'],
        'parallel_any' => ['children'],
        'escalate' => ['after_minutes'],
        'notify' => ['template_key'],
        'submit' => [],
        // §IX.9.1: amount routing is a property of an approval node, not a
        // bespoke comparison somebody writes into a condition. W08 checks the
        // bands are contiguous, non-overlapping and exhaustive.
        'approval' => ['amount_bands'],
        'reject' => ['reason_required'],
        'cancel' => [],
        'finalise' => ['completion_event_key'],
    ];

    /** @var list<ParticipantSpec> */
    public array $participants;

    /**
     * @param list<mixed>          $participants declared wide: this constructor is the
     *        boundary a caller hands participants to, and the loop below is what actually
     *        proves each one is a ParticipantSpec before it is stored
     * @param array<string, mixed>  $params
     * @param list<int>             $reminderSchedule offsets in minutes from due_at
     */
    public function __construct(
        public string $key,
        public NodeType $type,
        public string $label,
        array $participants = [],
        public array $params = [],
        public ?string $guardExpression = null,
        public string $requiresComment = 'never',
        public ?string $requiredPermission = null,
        public ?int $slaMinutes = null,
        public array $reminderSchedule = [],
        public string $escalationPolicy = 'none',
        public ?string $escalationTarget = null,
        public string $onNoParticipant = 'fail_instance',
        public bool $delegationAllowed = true,
        public bool $reassignmentResetsSla = false,
        public int $displayOrder = 0,
        public ?string $slaCalendarKey = null,
        /** R-IX-066. Minutes a timer may fire late before an automatic decision is withheld. */
        public ?int $staleThresholdMinutes = null,
        /** R-IX-064. "Escalate once and then nothing" recreates the problem one level higher. */
        public int $escalateLevelsMax = 1,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $key)) {
            throw new \InvalidArgumentException("Node key '{$key}' must be lower_snake_case, 2-64 characters.");
        }

        if (trim($label) === '') {
            throw new \InvalidArgumentException(
                "Node '{$key}': label is mandatory (R-IX-012). It is what the inbox and the trace render, "
                . 'and an unnamed step is an unexplainable step.'
            );
        }

        if (!in_array($requiresComment, self::COMMENT_REQUIREMENTS, true)) {
            throw new \InvalidArgumentException("Node '{$key}': requires_comment is never, on_reject or always.");
        }

        if (!in_array($escalationPolicy, self::ESCALATION_POLICIES, true)) {
            throw new \InvalidArgumentException("Node '{$key}': '{$escalationPolicy}' is not an escalation policy.");
        }

        if (!in_array($onNoParticipant, self::ON_NO_PARTICIPANT, true)) {
            throw new \InvalidArgumentException("Node '{$key}': '{$onNoParticipant}' is not an on_no_participant policy.");
        }

        // R-IX-011: an unknown params key is an error, not a silent discard.
        $allowed = self::PARAM_SCHEMA[$type->value] ?? [];
        $unknown = array_diff(array_keys($params), $allowed);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                "Node '{$key}' ({$type->value}): unknown parameter(s) " . implode(', ', $unknown)
                . '. Declared: ' . ($allowed === [] ? 'none' : implode(', ', $allowed))
                . '. An unknown key is a publish error, never a silent discard (R-IX-011).'
            );
        }

        foreach ($participants as $participant) {
            if (!$participant instanceof ParticipantSpec) {
                throw new \InvalidArgumentException("Node '{$key}': participants are ParticipantSpec values.");
            }
        }

        /** @var list<ParticipantSpec> $participants */
        $this->participants = $participants;

        if ($type === NodeType::Quorum) {
            $n = $params['n'] ?? null;
            $m = $params['m'] ?? null;

            // W12: 1 <= n <= m, and a tie policy, or the tally has no defined
            // answer at the boundary it exists to sit on.
            if (!is_int($n) || !is_int($m) || $n < 1 || $n > $m) {
                throw new \InvalidArgumentException(
                    "Node '{$key}': a quorum needs integers n and m with 1 <= n <= m (W12)."
                );
            }

            if (($params['tie_policy'] ?? '') === '') {
                throw new \InvalidArgumentException("Node '{$key}': a quorum must declare a tie_policy (W12).");
            }
        }

        // W16: an escalation that decides for a human needs a clock to decide by.
        if (in_array($escalationPolicy, ['auto_approve', 'auto_reject'], true) && $slaMinutes === null) {
            throw new \InvalidArgumentException(
                "Node '{$key}': escalation_policy '{$escalationPolicy}' decides on a human's behalf, "
                . 'so it must declare sla_minutes (W16).'
            );
        }

        // R-IX-064: repeating up the reporting line is the point. Zero levels
        // means the escalation does nothing, which is a policy of `none`
        // written the long way round and almost certainly not what was meant.
        if ($escalateLevelsMax < 1) {
            throw new \InvalidArgumentException(
                "Node '{$key}': escalate_levels_max is at least 1. An escalation that escalates zero levels "
                . "is escalation_policy 'none' with extra configuration (R-IX-064)."
            );
        }

        if ($staleThresholdMinutes !== null && $staleThresholdMinutes < 1) {
            throw new \InvalidArgumentException(
                "Node '{$key}': stale_threshold_minutes is at least 1. A threshold of zero suppresses every "
                . 'automatic decision, including the ones taken on time (R-IX-066).'
            );
        }

        // A reminder offset is relative to due_at, so a reminder schedule with
        // no SLA has nothing to be relative to (§IX.7.4).
        if ($reminderSchedule !== [] && $slaMinutes === null) {
            throw new \InvalidArgumentException(
                "Node '{$key}': a reminder schedule needs sla_minutes — offsets are minutes relative to "
                . 'due_at, and without a due_at they resolve to nothing (§IX.7.4).'
            );
        }
    }

    /** @return list<string> the outcome codes this node can produce */
    public function outcomes(): array
    {
        if ($this->type !== NodeType::ConditionBranch) {
            return $this->type->outcomes();
        }

        $branches = $this->params['branches'] ?? [];

        return is_array($branches) ? array_values(array_map(strval(...), $branches)) : [];
    }

    public function isTerminal(): bool
    {
        return $this->type->isTerminal();
    }

    public function describe(): string
    {
        return $this->key . ' [' . $this->type->value . '] ' . $this->label;
    }
}
