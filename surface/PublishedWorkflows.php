<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Config\PublishReport;
use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Scope;
use SmartPRS\Platform\Workflow\Node;
use SmartPRS\Platform\Workflow\NodeType;
use SmartPRS\Platform\Workflow\ParticipantSpec;
use SmartPRS\Platform\Workflow\Transition;
use SmartPRS\Platform\Workflow\Validator;
use SmartPRS\Platform\Workflow\WorkflowDefinition;

/**
 * THE DECLARED WORKFLOWS, COMPILED AND VALIDATED BY THE PLATFORM'S OWN GATES.
 *
 * `config/workflows.json` is read by `Surface\Requests`, which runs a linear
 * chain with amount routing — 384 lines that work. Meanwhile
 * `Platform\Workflow` has parallel nodes, quorum, SLA timers, delegation,
 * escalation, a validator with eleven publish gates, and nothing on any screen
 * ever entered it. The file's own note has said so honestly since somebody
 * noticed it claimed otherwise.
 *
 * This is the first half of closing that: every declared workflow is COMPILED
 * into a real `WorkflowDefinition` and put through `Validator::validate()`, so
 * the eleven gates run against what a customer actually declared. It does not
 * yet run instances — `Requests` still decides — and saying which half is done
 * is the whole reason the note in workflows.json exists.
 *
 * WHY VALIDATION FIRST, AND NOT AS A STEPPING STONE. A workflow that cannot
 * reach its end node, or whose approval node has an outcome no transition
 * covers, is a request that gets raised and then STRANDS — sitting in a queue
 * pointing at a node nothing can leave, with the money or the leave frozen
 * behind it. SmartPRS 2 has no equivalent check at all; a chain there is
 * whatever the controller happens to do. Catching it when the file is written,
 * rather than when somebody's leave is in it, is worth more than running the
 * engine and is a prerequisite for doing so safely.
 *
 * IT REFUSES A FILE IT CANNOT COMPILE rather than validating the workflows that
 * happened to build. A validator that silently checks four of five declarations
 * is the shape this codebase keeps finding: it reads as a pass.
 */
final class PublishedWorkflows
{
    /** @var array<string, WorkflowDefinition>|null */
    private ?array $definitions = null;

    private ?string $refusal = null;

    public function __construct(private readonly string $file)
    {
    }

    /**
     * Every declared workflow, as a real definition.
     *
     * @return array<string, WorkflowDefinition> keyed by workflow key
     */
    public function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $this->definitions = [];
        $decoded = is_file($this->file)
            ? json_decode((string) file_get_contents($this->file), true)
            : null;

        if (!is_array($decoded) || !is_array($decoded['workflows'] ?? null)) {
            $this->refusal = basename($this->file) . ' is missing or unreadable.';

            return [];
        }

        foreach ($decoded['workflows'] as $declared) {
            $key = (string) ($declared['key'] ?? '');

            try {
                $this->definitions[$key] = $this->compile($declared);
            } catch (\Throwable $e) {
                $this->definitions = [];
                $this->refusal = "Workflow '{$key}' in " . basename($this->file)
                    . ' could not be compiled: ' . $e->getMessage()
                    . ' No workflow is validated while a declared one is invalid.';

                return [];
            }
        }

        return $this->definitions;
    }

    /**
     * The platform's eleven publish gates, run against every declaration.
     *
     * @return array<string, PublishReport> keyed by workflow key
     */
    public function reports(): array
    {
        $reports = [];

        foreach ($this->definitions() as $key => $definition) {
            $reports[$key] = Validator::validate($definition);
        }

        return $reports;
    }

    /** @return list<string> the keys of workflows the gates refuse to publish */
    public function unpublishable(): array
    {
        $bad = [];

        foreach ($this->reports() as $key => $report) {
            if (!$report->isPublishable()) {
                $bad[] = $key;
            }
        }

        return $bad;
    }

    public function refusal(): ?string
    {
        $this->definitions();

        return $this->refusal;
    }

    /**
     * A declared workflow, as the platform models one.
     *
     * The declaration is a LIST OF STEPS, which is the right shape for a person
     * writing one and is not a graph. The graph is derived here: each step
     * becomes a node, and each consecutive pair becomes an `approved` transition,
     * with every approval node also transitioning to a terminal `rejected` end —
     * because an approval with an outcome no transition covers is exactly what
     * gate W03 refuses, and it is the defect that strands a request in a queue
     * pointing at a node nothing can leave.
     *
     * @param array<string, mixed> $declared
     */
    private function compile(array $declared): WorkflowDefinition
    {
        $steps = array_values((array) ($declared['steps'] ?? []));

        if ($steps === []) {
            throw new \InvalidArgumentException('A workflow declares at least one step.');
        }

        $nodes = [];
        $transitions = [];
        $order = 0;
        $stepKeys = [];

        foreach ($steps as $step) {
            $stepKeys[] = (string) ($step['key'] ?? '');
        }

        foreach ($steps as $index => $step) {
            $key = (string) ($step['key'] ?? '');
            $type = NodeType::from((string) ($step['type'] ?? 'approval'));

            $nodes[] = new Node(
                key: $key,
                type: $type,
                label: (string) ($step['label'] ?? $key),
                participants: $this->participantsFor($step, $type),
                requiresComment: (string) ($step['requires_comment'] ?? 'never'),
                // MINUTES, from hours. `sla_hours` is what a person writes and
                // the platform counts in minutes; converting here rather than
                // changing the file keeps the declaration in the unit a customer
                // thinks in.
                slaMinutes: isset($step['sla_hours']) ? (int) $step['sla_hours'] * 60 : null,
                displayOrder: $order++,
            );
        }

        // The three ends every chain needs, and they are TERMINAL node types —
        // not auto-approve nodes with nothing after them. The first attempt used
        // `auto_approve` for the ends and gate W14 refused every workflow in the
        // file: "no path from the start node reaches a finalise node — a workflow
        // that cannot complete is not a workflow". It was right. An auto-approve
        // node is a step that decides; a finalise node is the end, and a graph
        // whose end is a step is a graph that never ends.
        $nodes[] = new Node(key: 'completed', type: NodeType::Finalise, label: 'Approved',
            displayOrder: $order++);
        $nodes[] = new Node(key: 'refused', type: NodeType::Reject, label: 'Rejected',
            displayOrder: $order++);
        $nodes[] = new Node(key: 'withdrawn', type: NodeType::Cancel, label: 'Withdrawn',
            displayOrder: $order++);

        // EVERY OUTCOME A NODE CAN PRODUCE NEEDS SOMEWHERE TO GO. Gate W03 says
        // so and it is not pedantry: an approval node produces five outcomes,
        // not two, and the ones nobody thinks about are exactly the ones that
        // strand a request — `escalated` when an SLA fires, `returned` when an
        // approver sends it back, `auto_decided` when a timer decides for them.
        // A request sitting at a node with no transition for the outcome it just
        // produced is somebody's leave, or somebody's money, frozen with no
        // screen able to say why.
        //
        // TWO NODES THE DECLARATION DOES NOT MENTION, and gate W05 is why they
        // exist rather than being shortcuts. The first version routed `returned`
        // and `escalated` back to the node that produced them, and W05 refused
        // every workflow in the file: "a cycle with no return_for_edit or
        // escalate node in it — an approval loop with no human-visible return is
        // an infinite loop". It was right. A request that bounces between an
        // approver and themselves is a request nobody can see moving.
        //
        // So: ONE `sent_back` node, of the platform's own return_for_edit type,
        // which is where a returned request visibly sits until the requester
        // resubmits it; and one escalate node PER APPROVAL, because escalation
        // in a linear chain means the person above decides, and a single shared
        // escalate node would lose which step it came from.
        $nodes[] = new Node(key: 'sent_back', type: NodeType::ReturnForEdit,
            label: 'Sent back to the requester',
            // THE REQUESTER, NAMED. Gate W11 refuses a task node with no
            // participant and no policy for having none, because such a node
            // "can only ever fail the instance" — and it is right: a returned
            // request assigned to nobody is a request that dies the moment it is
            // sent back, which is the state an employee would report as "my
            // leave disappeared".
            participants: [new ParticipantSpec('requester')],
            displayOrder: $order++);

        $transitions[] = new Transition('sent_back', 'resubmitted', $stepKeys[0], order: $order++);
        $transitions[] = new Transition('sent_back', 'returned', 'sent_back', order: $order++);
        $transitions[] = new Transition('sent_back', 'exhausted', 'withdrawn', order: $order++);
        $transitions[] = new Transition('sent_back', 'abandoned', 'withdrawn', order: $order++);

        foreach ($steps as $index => $step) {
            $key = $stepKeys[$index];
            $type = NodeType::from((string) ($step['type'] ?? 'approval'));
            $next = $stepKeys[$index + 1] ?? 'completed';
            $nextStep = $steps[$index + 1] ?? null;
            $escalation = 'escalate_' . $key;

            if ($type->createsTasks()) {
                $nodes[] = new Node(key: $escalation, type: NodeType::Escalate,
                    label: 'Escalated from ' . (string) ($step['label'] ?? $key),
                    // WHOEVER DECIDES THE NEXT STEP. An escalation with no
                    // participant fails the instance (W11), and the honest
                    // participant for "the person above" in a linear chain is
                    // the one the next step names. When there is no next step,
                    // the escalation has nowhere above it to go and the node
                    // says so with a policy rather than failing silently.
                    participants: $nextStep === null ? []
                        : $this->participantsFor($nextStep, NodeType::Approval),
                    onNoParticipant: $nextStep === null ? 'auto_approve_with_reason' : 'fail_instance',
                    displayOrder: $order++);

                // UP THE CHAIN, not round in a circle. Escalating means the
                // person above decides, and in a linear chain that is the next
                // step — which is also why the escalate node is per-approval:
                // one shared node would not know which step it came from.
                $transitions[] = new Transition($escalation, 'escalated', $next, order: $order++);
            }

            foreach ($type->outcomes() as $outcome) {
                $to = match ($outcome) {
                    'submitted', 'approved', 'auto_approved', 'joined', 'first_completed',
                    'notified', 'auto_decided' => $next,
                    'rejected' => 'refused',
                    // BLOCKED IS NOT REJECTED. A submission the platform blocked
                    // never became a request anybody decided, and filing it as a
                    // rejection would tell the employee their manager said no.
                    'blocked', 'abandoned', 'exhausted', 'parked' => 'withdrawn',
                    'returned' => 'sent_back',
                    'escalated' => $escalation,
                    'resubmitted', 'not_applicable' => $next,
                    default => $next,
                };

                $transitions[] = new Transition($key, $outcome, $to, order: $order++);
            }
        }

        return new WorkflowDefinition(
            // UPPER-CASE-HYPHEN, which is the platform's key shape. The file
            // uses `leave-request` because that is what a person types and what
            // a URL carries; translating here rather than renaming the file
            // keeps the declaration in the form its readers use.
            key: strtoupper((string) ($declared['key'] ?? '')),
            name: (string) ($declared['label'] ?? $declared['key'] ?? ''),
            businessDescription: (string) ($declared['description'] ?? ''),
            subjectEntityKey: (string) ($declared['subject'] ?? ''),
            startNodeKey: (string) ($steps[0]['key'] ?? ''),
            nodes: $nodes,
            transitions: $transitions,
            scope: Scope::tenantDefault(),
            range: EffectiveRange::fromInclusive((string) ($declared['from'] ?? '2026-01-01'), null),
            versionNo: 1,
            versionId: strtolower((string) ($declared['key'] ?? '')) . '-v1',
            status: 'published',
            // NOBODY APPROVES THEIR OWN. Not left to the default because the
            // default is a decision somebody else made: SmartPRS 2's `admin`
            // satisfies both isHR() and isFinance() on the salary approval, so
            // one person alone completes a maker-checker control. Stated here so
            // the day a customer asks to turn it off is a day somebody argues
            // about it.
            allowsSelfApproval: false,
        );
    }

    /**
     * @param array<string, mixed> $step
     * @return list<ParticipantSpec>
     */
    private function participantsFor(array $step, NodeType $type): array
    {
        $by = (string) ($step['by'] ?? '');

        if ($by === '' || in_array($type, [NodeType::AutoApprove, NodeType::Notify], true)) {
            return [];
        }

        // `requester` and `previous_node_actor` are participant kinds in their
        // own right (§IX.5.2); anything else the file names is a role.
        return [in_array($by, ['requester', 'previous_node_actor', 'initiator_manager'], true)
            ? new ParticipantSpec($by)
            : new ParticipantSpec('role_in_scope', ['role_key' => $by])];
    }
}
