<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow;

use SmartPRS\Platform\Config\Publisher;
use SmartPRS\Platform\Persistence\TenantScopedQuery;
use SmartPRS\Platform\Tenancy\TenantContext;

/**
 * PUBLISHING A WORKFLOW, WITH THE PAYLOAD DERIVED FROM THE DEFINITION.
 *
 * The third of three — Rules\RulePublisher, Formula\FormulaPublisher, this —
 * and it exists for the same reason as the other two: Config\Publisher takes a
 * payload array because it publishes any artefact type, and hand-mapping a
 * definition onto columns is a second record of the same facts kept in step by
 * hand. A workflow whose `is_section_56_operation` is true on the object and
 * false in the row is a maker-checker control that quietly is not one.
 *
 * What makes this one different is that a workflow payload is FOUR tables — the
 * header, its nodes, each node's participants, and the transitions between
 * them. So the rows below the header are written through Publisher's `children`
 * callback, inside Publisher's own transaction. Writing them afterwards, in a
 * second transaction, is how a published workflow ends up with an approval
 * chain that has no steps.
 *
 * There is no path to a published workflow that skips Validator: the report
 * comes from `Validator::validate()` over the same definition, and the caller
 * has no argument through which to supply a different one.
 */
final class WorkflowPublisher
{
    public function __construct(
        private readonly Publisher $publisher,
        private readonly \PDO $pdo,
        private readonly TenantContext $tenant,
    ) {
    }

    /**
     * @param  array<string, string>  $audit  created_by, approved_by, reason, change_note
     * @return int the new config_versions id
     *
     * @throws \SmartPRS\Platform\Config\PublicationRefused when a check blocks
     */
    public function publish(
        WorkflowDefinition $definition,
        \DateTimeImmutable $now,
        ?ValidationEnvironment $environment = null,
        array $audit = [],
    ): int {
        return $this->publisher->publish(
            'workflow',
            $definition->key,
            'workflow_payloads',
            self::payload($definition, $now),
            $definition->scope,
            $definition->range,
            Validator::validate($definition, $environment),
            $now,
            $definition->priority,
            self::checksum($definition),
            $audit,
            fn (int $payloadId): null => $this->writeGraph($definition, $payloadId, $now),
        );
    }

    /**
     * The header row, entirely from the definition.
     *
     * Deliberately exhaustive: every column of `workflow_payloads` that is not
     * an id appears once, on the left, with the property it comes from on the
     * right. A column added to the migration without a line here fails on the
     * INSERT rather than being written as a silent default — which is the whole
     * difference between this and R-IX-011's account of SmartPRS 2's safeRow().
     *
     * @return array<string, mixed>
     */
    public static function payload(WorkflowDefinition $definition, \DateTimeImmutable $now): array
    {
        $timestamp = $now->format('Y-m-d H:i:s');

        return [
            'subject_entity_key' => $definition->subjectEntityKey,
            'subject_amount_path' => $definition->subjectAmountPath,
            'start_node_key' => $definition->startNodeKey,
            'business_description' => $definition->businessDescription,

            'allows_self_approval' => $definition->allowsSelfApproval ? 1 : 0,
            'self_approval_override' => $definition->selfApprovalOverride,
            'is_section_56_operation' => $definition->isSection56Operation ? 1 : 0,
            'segregation_sets_json' => $definition->segregationSets === []
                ? null
                : self::json($definition->segregationSets),

            'return_policy' => $definition->returnPolicy,
            'cancel_policy' => $definition->cancelPolicy,
            // §IX.3.2's comment policy is per node (`requires_comment`), and the
            // header column is the definition-wide default the nodes fall back
            // to. Nothing on WorkflowDefinition sets it, so it takes the
            // column's own default rather than being invented here.
            'comment_policy' => 'optional',
            'instance_key_expr' => $definition->instanceKeyExpression,
            'sla_calendar_key' => $definition->slaCalendarKey,
            'timezone_source' => 'tenant',

            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    /**
     * The nodes, their participants and the transitions — inside the caller's
     * transaction.
     */
    private function writeGraph(WorkflowDefinition $definition, int $payloadId, \DateTimeImmutable $now): null
    {
        $timestamp = $now->format('Y-m-d H:i:s');

        foreach ($definition->nodes as $node) {
            $nodeId = (int) TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_nodes')->insert([
                'workflow_payload_id' => $payloadId,
                'node_key' => $node->key,
                'node_type' => $node->type->value,
                'label' => $node->label,
                'display_order' => $node->displayOrder,
                'params_json' => $node->params === [] ? null : self::json($node->params),
                'guard_expr' => $node->guardExpression,
                'requires_comment' => $node->requiresComment,
                'required_permission' => $node->requiredPermission,
                'sla_minutes' => $node->slaMinutes,
                'reminder_schedule_json' => $node->reminderSchedule === []
                    ? null
                    : self::json($node->reminderSchedule),
                'escalation_policy' => $node->escalationPolicy,
                'escalation_target' => $node->escalationTarget,
                'delegation_allowed' => $node->delegationAllowed ? 1 : 0,
                'reassignment_resets_sla' => $node->reassignmentResetsSla ? 1 : 0,
                'on_no_participant' => $node->onNoParticipant,
                'is_terminal' => $node->type->isTerminal() ? 1 : 0,
            ]);

            foreach ($node->participants as $seq => $spec) {
                TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_node_participants')->insert([
                    'workflow_node_id' => $nodeId,
                    // The stored order is the position in the declared list, not
                    // the spec's own `seq` — two specs carrying the same seq
                    // would collide on the unique index, and the list the author
                    // wrote is the order they meant.
                    'seq' => $seq,
                    'kind' => $spec->kind,
                    'params_json' => $spec->params === [] ? null : self::json($spec->params),
                    'is_optional' => $spec->optional ? 1 : 0,
                    'exclude_requester' => $spec->excludeRequester ? 1 : 0,
                    'exclude_prior_actors' => $spec->excludePriorActors ? 1 : 0,
                ]);
            }
        }

        foreach ($definition->transitions as $index => $transition) {
            TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_definition_transitions')->insert([
                'workflow_payload_id' => $payloadId,
                'from_node_key' => $transition->from,
                'outcome_code' => $transition->outcome,
                'to_node_key' => $transition->to,
                'condition_expr' => $transition->conditionExpression,
                'label' => $transition->label === '' ? null : $transition->label,
                // First match wins in DECLARED order, so the stored order is the
                // list position. Two transitions sharing an `order` would
                // collide on the unique index and, worse, make "which matched"
                // depend on row order — the thing this column exists to prevent.
                'evaluation_order' => $index,
            ]);
        }

        // The timestamp is deliberately unused on the child rows: those tables
        // carry no created_at, because a node has no life of its own. It belongs
        // to exactly one immutable published version, and that version is dated.
        unset($timestamp);

        return null;
    }

    /**
     * Tamper evidence over the whole graph, not only the header.
     *
     * A workflow's meaning is in its nodes and transitions, so a checksum over
     * the header alone would not change when somebody swapped an approver for a
     * notifier. Everything that decides where an instance goes is hashed.
     */
    public static function checksum(WorkflowDefinition $definition): string
    {
        return hash('sha256', self::json([
            'key' => $definition->key,
            'subject' => $definition->subjectEntityKey,
            'start' => $definition->startNodeKey,
            'self_approval' => [$definition->allowsSelfApproval, $definition->selfApprovalOverride],
            'section_56' => $definition->isSection56Operation,
            'segregation' => $definition->segregationSets,
            'policies' => [$definition->returnPolicy, $definition->cancelPolicy],
            'instance_key' => $definition->instanceKeyExpression,
            'nodes' => array_map(static fn (Node $n): array => [
                $n->key, $n->type->value, $n->label, $n->params, $n->guardExpression,
                $n->requiredPermission, $n->slaMinutes, $n->escalationPolicy, $n->escalationTarget,
                $n->onNoParticipant,
                array_map(
                    static fn (ParticipantSpec $p): array => [$p->kind, $p->params, $p->optional],
                    $n->participants,
                ),
            ], $definition->nodes),
            'transitions' => array_map(static fn (Transition $t): array => [
                $t->from, $t->outcome, $t->to, $t->conditionExpression,
            ], $definition->transitions),
            'scope' => $definition->scope->toJson(),
            'effective_from' => $definition->range->from->format('Y-m-d'),
            'effective_to' => $definition->range->effectiveTo()?->format('Y-m-d'),
            'version_no' => $definition->versionNo,
        ]));
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
