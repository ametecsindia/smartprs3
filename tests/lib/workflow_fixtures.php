<?php

declare(strict_types=1);

/**
 * The workflow definition that publishes cleanly, and the node helper that
 * builds it.
 *
 * It lives here rather than in one test file because two files need it and both
 * must use the SAME definition: workflow_engine_test.php asserts the eighteen
 * validator checks against it, and workflow_publisher_test.php writes it to four
 * tables. A publisher exercising a definition the validator never saw would
 * prove nothing about the pair.
 */

use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Scope;
use SmartPRS\Platform\Workflow\Node;
use SmartPRS\Platform\Workflow\NodeType;
use SmartPRS\Platform\Workflow\ParticipantSpec;
use SmartPRS\Platform\Workflow\Transition;
use SmartPRS\Platform\Workflow\WorkflowDefinition;

function wfNode(string $key, NodeType $type, array $extra = []): Node
{
    return new Node(...([
        'key' => $key,
        'type' => $type,
        'label' => ucfirst(str_replace('_', ' ', $key)),
    ] + $extra));
}

/** A definition that publishes cleanly, as the baseline for the failures below. */
function wfDefinition(array $overrides = []): WorkflowDefinition
{
    $defaults = [
        'key' => 'LEAVE-APPROVE',
        'name' => 'Leave approval',
        'businessDescription' => 'A leave application is approved by the requester\'s manager before it charges.',
        'subjectEntityKey' => 'leave_request',
        'startNodeKey' => 'raise',
        'nodes' => [
            wfNode('raise', NodeType::Submit),
            wfNode('manager', NodeType::Approval, [
                'participants' => [new ParticipantSpec('reporting_line', ['level_n' => 1])],
            ]),
            wfNode('amend', NodeType::ReturnForEdit, [
                'participants' => [new ParticipantSpec('requester')],
            ]),
            wfNode('charge', NodeType::Finalise, ['params' => ['completion_event_key' => 'leave.charged']]),
            wfNode('refuse', NodeType::Reject, ['params' => ['reason_required' => true]]),
        ],
        'transitions' => [
            new Transition('raise', 'submitted', 'manager'),
            new Transition('raise', 'blocked', 'refuse'),
            new Transition('manager', 'approved', 'charge'),
            new Transition('manager', 'rejected', 'refuse'),
            new Transition('manager', 'returned', 'amend'),
            new Transition('manager', 'escalated', 'manager'),
            new Transition('manager', 'auto_decided', 'charge'),
            new Transition('amend', 'returned', 'amend'),
            new Transition('amend', 'resubmitted', 'manager'),
            new Transition('amend', 'exhausted', 'refuse'),
            new Transition('amend', 'abandoned', 'refuse'),
        ],
        'scope' => Scope::tenantDefault(),
        'range' => EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-04-01')),
        'versionNo' => 1,
        'versionId' => 'wf-v1',
        'status' => 'published',
    ];

    return new WorkflowDefinition(...array_merge($defaults, $overrides));
}
