<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow;

use SmartPRS\Platform\Config\PublishCheck;
use SmartPRS\Platform\Config\PublishReport;

/**
 * THE EIGHTEEN WORKFLOW PUBLISH CHECKS — Part IX §IX.3.6.
 *
 * R-IX-018: publication runs W01–W18 and blocks on any failure, naming the
 * nodes and, for W10 and W17, the affected users or employees. "Ambiguity is a
 * configuration error, never a runtime coin-toss."
 *
 * The same NOT-CHECKED discipline as the formula gates: a check that needs the
 * tenant's roles, users or bindings and was not given them records NOT CHECKED
 * and warns. It never passes. Four of this repository's own enforcement gates
 * were once green over zero files, and that is not a mistake worth making twice.
 */
final class Validator
{
    public static function validate(WorkflowDefinition $definition, ?ValidationEnvironment $env = null): PublishReport
    {
        $env ??= new ValidationEnvironment();

        return new PublishReport($definition->describe(), [
            self::w01SingleSubmit($definition),
            self::w02Reachability($definition),
            self::w03OutcomeCoverage($definition),
            self::w04TargetsExist($definition),
            self::w05Cycles($definition),
            self::w06Expressions($definition, $env),
            self::w07AmountRouting($definition),
            self::w08AmountBands($definition),
            self::w09References($definition, $env),
            self::w10Segregation($definition, $env),
            self::w11ApprovalParticipants($definition),
            self::w12Quorum($definition),
            self::w13TerminalHasNoExit($definition),
            self::w14ReachableOutcomes($definition),
            self::w15RegisteredKeys($definition, $env),
            self::w16EscalationClock($definition),
            self::w17BindingTies($definition, $env),
            self::w18StoredCases($definition, $env),
        ]);
    }

    // W01 — exactly one submit, and it is the start node.
    private static function w01SingleSubmit(WorkflowDefinition $d): PublishCheck
    {
        $name = 'Single entry point';
        $submits = $d->nodesOfType(NodeType::Submit);

        if (count($submits) !== 1) {
            return PublishCheck::block('W01', $name,
                'a definition has exactly one submit node and this has ' . count($submits)
                . ' — a workflow with two entry points has no defined start');
        }

        if ($submits[0]->key !== $d->startNodeKey) {
            return PublishCheck::block('W01', $name,
                "start_node_key is '{$d->startNodeKey}' and the submit node is '{$submits[0]->key}'");
        }

        return PublishCheck::pass('W01', $name);
    }

    // W02 — every node reachable from the start.
    private static function w02Reachability(WorkflowDefinition $d): PublishCheck
    {
        $name = 'Reachability';
        $seen = [];
        $queue = [$d->startNodeKey];

        while ($queue !== []) {
            $key = array_shift($queue);

            if (isset($seen[$key]) || $d->node($key) === null) {
                continue;
            }

            $seen[$key] = true;

            foreach ($d->transitionsFrom($key) as $transition) {
                $queue[] = $transition->to;
            }
        }

        $unreachable = array_values(array_diff(array_keys($d->nodesByKey), array_keys($seen)));

        return $unreachable === []
            ? PublishCheck::pass('W02', $name)
            : PublishCheck::block('W02', $name,
                'unreachable from the start node: ' . implode(', ', $unreachable) . ' — dead configuration');
    }

    // W03 — every non-terminal node's outcomes are covered by transitions.
    private static function w03OutcomeCoverage(WorkflowDefinition $d): PublishCheck
    {
        $name = 'Outcome coverage';
        $issues = [];

        foreach ($d->nodesByKey as $node) {
            if ($node->isTerminal()) {
                continue;
            }

            $declared = [];

            foreach ($d->transitionsFrom($node->key) as $transition) {
                $declared[$transition->outcome] = true;
            }

            if ($declared === []) {
                $issues[] = "'{$node->key}' has no outgoing transition — an instance reaching it strands";

                continue;
            }

            $missing = array_values(array_diff($node->outcomes(), array_keys($declared)));

            if ($missing !== []) {
                $issues[] = "'{$node->key}' ({$node->type->value}) can produce "
                    . implode(', ', $missing) . ' with no declared transition';
            }
        }

        return $issues === [] ? PublishCheck::pass('W03', $name) : PublishCheck::block('W03', $name, $issues);
    }

    // W04 — every transition target exists.
    private static function w04TargetsExist(WorkflowDefinition $d): PublishCheck
    {
        $name = 'Transition targets';
        $issues = [];

        foreach ($d->transitions as $transition) {
            if ($d->node($transition->from) === null) {
                $issues[] = "transition from unknown node '{$transition->from}'";
            }

            if ($d->node($transition->to) === null) {
                $issues[] = "'{$transition->from}' --{$transition->outcome}--> unknown node '{$transition->to}'";
            }
        }

        return $issues === [] ? PublishCheck::pass('W04', $name) : PublishCheck::block('W04', $name, $issues);
    }

    // W05 — no cycle that does not pass through return_for_edit or escalate.
    private static function w05Cycles(WorkflowDefinition $d): PublishCheck
    {
        $name = 'Cycles';
        $state = [];
        $stack = [];
        $cycle = null;

        $descend = static function (string $key) use (&$descend, &$state, &$stack, &$cycle, $d): void {
            if ($cycle !== null || $d->node($key) === null) {
                return;
            }

            $current = $state[$key] ?? 0;

            if ($current === 2) {
                return;
            }

            if ($current === 1) {
                $from = array_search($key, $stack, true);
                $cycle = array_merge(array_slice($stack, (int) $from), [$key]);

                return;
            }

            $state[$key] = 1;
            $stack[] = $key;

            foreach ($d->transitionsFrom($key) as $transition) {
                $descend($transition->to);
            }

            array_pop($stack);
            $state[$key] = 2;
        };

        foreach (array_keys($d->nodesByKey) as $key) {
            $descend($key);

            if ($cycle === null) {
                continue;
            }

            // A loop through a node a human can see and act on is a legal
            // revision round. One with no such node is an infinite loop.
            foreach ($cycle as $inCycle) {
                if ($d->node($inCycle)?->type->breaksCycles() === true) {
                    $cycle = null;
                    $state = [];
                    $stack = [];

                    break;
                }
            }

            if ($cycle !== null) {
                return PublishCheck::block('W05', $name,
                    'a cycle with no return_for_edit or escalate node in it: ' . implode(' → ', $cycle)
                    . ' — an approval loop with no human-visible return is an infinite loop');
            }
        }

        return PublishCheck::pass('W05', $name);
    }

    // W06 — every expression parses, type-checks Boolean, within budget.
    private static function w06Expressions(WorkflowDefinition $d, ValidationEnvironment $env): PublishCheck
    {
        $name = 'Expressions';

        $expressions = [];

        foreach ($d->nodesByKey as $node) {
            if ($node->guardExpression !== null) {
                $expressions["{$node->key}.guard"] = $node->guardExpression;
            }

            if ($node->type === NodeType::AutoApprove && isset($node->params['expression'])) {
                $expressions["{$node->key}.auto_approve"] = (string) $node->params['expression'];
            }
        }

        foreach ($d->transitions as $transition) {
            if ($transition->conditionExpression !== null) {
                $expressions["{$transition->from}→{$transition->to}"] = $transition->conditionExpression;
            }
        }

        if ($expressions === []) {
            return PublishCheck::pass('W06', $name);
        }

        if ($env->symbols === null) {
            return PublishCheck::couldNotCheck('W06', $name,
                'no symbol table supplied for this subject entity, so ' . count($expressions)
                . ' expression(s) were not parsed or type-checked');
        }

        $issues = [];

        foreach ($expressions as $where => $source) {
            try {
                $check = \SmartPRS\Platform\Spx\Compiled::parse($source, $env->symbols)->typeCheck();

                if (!$check->isValid()) {
                    $issues[] = "{$where}: " . implode('; ', $check->messages());

                    continue;
                }

                if (!$check->isBoolean()) {
                    $issues[] = "{$where}: a guard or condition must be Boolean, and this is {$check->type}";
                }
            } catch (\Throwable $e) {
                $issues[] = "{$where}: " . $e->getMessage();
            }
        }

        return $issues === [] ? PublishCheck::pass('W06', $name) : PublishCheck::block('W06', $name, $issues);
    }

    // W07 — amount routing only where subject_amount_path is set.
    private static function w07AmountRouting(WorkflowDefinition $d): PublishCheck
    {
        $name = 'Amount routing';

        if ($d->subjectAmountPath !== null) {
            return PublishCheck::pass('W07', $name);
        }

        $routing = [];

        foreach ($d->transitions as $transition) {
            if ($transition->conditionExpression !== null
                && str_contains($transition->conditionExpression, 'amount')
            ) {
                $routing[] = $transition->describe();
            }
        }

        return $routing === []
            ? PublishCheck::pass('W07', $name)
            : PublishCheck::block('W07', $name,
                'these transitions route on an amount and the definition declares no subject_amount_path: '
                . implode('; ', $routing) . ' — Money never silently coerces (spine S5.1)');
    }

    /**
     * W08 — amount bands are contiguous, non-overlapping, and cover
     * (-∞, +∞) (§IX.9.1).
     *
     * `from` is inclusive, `to` is exclusive, NULL is unbounded, and everything
     * is in integer minor units (R-IX-077 — a float never touches a routing
     * threshold). A gap is an amount that routes nowhere, which is an instance
     * that strands at the node holding the money.
     *
     * The bands are sorted before they are read, so an author is not required
     * to declare them in order to be told the truth about them.
     */
    private static function w08AmountBands(WorkflowDefinition $d): PublishCheck
    {
        $name = 'Amount bands';
        $issues = [];

        foreach ($d->nodes as $node) {
            $bands = $node->params['amount_bands'] ?? null;

            if (!is_array($bands)) {
                continue;
            }

            if ($bands === []) {
                $issues[] = "{$node->key}: declares amount_bands with no bands in it, so no amount routes anywhere";

                continue;
            }

            $normalised = [];

            foreach ($bands as $index => $band) {
                if (!is_array($band) || !array_key_exists('from', $band) || !array_key_exists('to', $band)) {
                    $issues[] = "{$node->key} band " . ($index + 1) . ': a band declares `from` and `to`, '
                        . 'either of which may be NULL for unbounded';

                    continue 2;
                }

                $from = $band['from'];
                $to = $band['to'];

                foreach (['from' => $from, 'to' => $to] as $edge => $value) {
                    if ($value !== null && !is_int($value)) {
                        $issues[] = "{$node->key} band " . ($index + 1) . ": `{$edge}` is "
                            . get_debug_type($value) . ', not an integer in minor units. A float never '
                            . 'touches a routing threshold (R-IX-077).';

                        continue 3;
                    }
                }

                if ($from !== null && $to !== null && $to <= $from) {
                    $issues[] = "{$node->key} band " . ($index + 1) . ": empty — `to` ({$to}) is not "
                        . "above `from` ({$from}), and `to` is exclusive";

                    continue;
                }

                $normalised[] = ['from' => $from, 'to' => $to, 'seq' => $index + 1];
            }

            if ($issues !== []) {
                continue;
            }

            usort(
                $normalised,
                static fn (array $a, array $b): int => ($a['from'] ?? PHP_INT_MIN) <=> ($b['from'] ?? PHP_INT_MIN),
            );

            if ($normalised[0]['from'] !== null) {
                $issues[] = "{$node->key}: the lowest band starts at {$normalised[0]['from']}, so anything "
                    . 'below it routes nowhere. The first band\'s `from` is NULL.';
            }

            $last = $normalised[count($normalised) - 1];

            if ($last['to'] !== null) {
                $issues[] = "{$node->key}: the highest band ends at {$last['to']}, so anything at or above "
                    . 'it routes nowhere. The last band\'s `to` is NULL.';
            }

            for ($i = 1; $i < count($normalised); $i++) {
                $previous = $normalised[$i - 1];
                $current = $normalised[$i];

                if ($previous['to'] === null) {
                    $issues[] = "{$node->key}: band {$previous['seq']} is unbounded above and band "
                        . "{$current['seq']} starts at {$current['from']}, so they overlap";

                    continue;
                }

                if ($previous['to'] < $current['from']) {
                    $issues[] = "{$node->key}: nothing routes an amount in [{$previous['to']}, "
                        . "{$current['from']}) — bands {$previous['seq']} and {$current['seq']} leave a gap";
                }

                if ($previous['to'] > $current['from']) {
                    $issues[] = "{$node->key}: bands {$previous['seq']} and {$current['seq']} both claim "
                        . "[{$current['from']}, {$previous['to']}), and `to` is exclusive so they overlap";
                }
            }
        }

        if ($issues !== []) {
            return PublishCheck::block('W08', $name, $issues);
        }

        // A definition with no bands passes rather than reporting NOT CHECKED:
        // the absence is a property of the DEFINITION, not of the checkout, so
        // the check did run and found nothing wrong. NOT CHECKED is for a gate
        // that lacked something it needed, and this one needed nothing.
        return PublishCheck::pass('W08', $name);
    }

    /**
     * W15 — the subject entity and every emitted event key are registered
     * (Law 1).
     *
     * A completion event key nothing has registered is a string that looks like
     * an integration and is not one: the finalise node fires, the outbox row is
     * written, and no consumer exists. That is not a runtime error anywhere —
     * it is silence, which is why it has to be caught here.
     */
    private static function w15RegisteredKeys(WorkflowDefinition $d, ValidationEnvironment $env): PublishCheck
    {
        $name = 'Registered keys';

        $events = [];

        foreach ($d->nodes as $node) {
            $key = $node->params['completion_event_key'] ?? null;

            if (is_string($key) && $key !== '') {
                $events[$key] = $node->key;
            }
        }

        if ($env->entityKeys === null || $env->eventKeys === null) {
            $missing = $env->entityKeys === null ? 'entity' : 'event';
            $missing = $env->entityKeys === null && $env->eventKeys === null ? 'entity and event' : $missing;

            return PublishCheck::couldNotCheck('W15', $name,
                "no {$missing} key registry was supplied, so '{$d->subjectEntityKey}'"
                . ($events === [] ? '' : " and " . count($events) . ' completion event key(s)')
                . ' were not checked against anything');
        }

        $issues = [];

        if (!in_array($d->subjectEntityKey, $env->entityKeys, true)) {
            $issues[] = "subject_entity_key '{$d->subjectEntityKey}' is not a registered entity. "
                . 'Registered: ' . (implode(', ', $env->entityKeys) ?: '(none)') . '.';
        }

        foreach ($events as $key => $nodeKey) {
            if (!in_array($key, $env->eventKeys, true)) {
                $issues[] = "{$nodeKey} emits '{$key}', which no consumer has registered — the outbox row "
                    . 'would be written and nothing would read it.';
            }
        }

        return $issues === [] ? PublishCheck::pass('W15', $name) : PublishCheck::block('W15', $name, $issues);
    }

    // W09 — every referenced role, user, group and calendar exists.
    private static function w09References(WorkflowDefinition $d, ValidationEnvironment $env): PublishCheck
    {
        $name = 'References resolve';
        $issues = [];
        $warnings = [];
        $unchecked = [];

        foreach ($d->nodesByKey as $node) {
            foreach ($node->participants as $spec) {
                if ($spec->isSinglePointOfFailure()) {
                    $warnings[] = "'{$node->key}' names user " . (string) ($spec->params['user_id'] ?? '?')
                        . ' directly. A named user is a single point of failure — a role in scope survives '
                        . 'their leaving; §IX.5.9 exists because this one does not.';
                }

                [$registry, $value, $what] = match ($spec->kind) {
                    'role_in_scope' => [$env->roleKeys, (string) ($spec->params['role_key'] ?? ''), 'role'],
                    'named_user' => [$env->userIds, (string) ($spec->params['user_id'] ?? ''), 'user'],
                    'group' => [$env->groupIds, (string) ($spec->params['group_id'] ?? ''), 'group'],
                    default => [null, '', ''],
                };

                if ($what === '') {
                    continue;
                }

                if ($registry === null) {
                    $unchecked[$what] = true;

                    continue;
                }

                if (!in_array($value, $registry, true)) {
                    $issues[] = "'{$node->key}' names {$what} '{$value}', which is not active in this tenant";
                }
            }
        }

        if ($issues !== []) {
            return PublishCheck::block('W09', $name, $issues);
        }

        if ($unchecked !== []) {
            return PublishCheck::couldNotCheck('W09', $name,
                'no registry supplied for: ' . implode(', ', array_keys($unchecked)));
        }

        return $warnings === [] ? PublishCheck::pass('W09', $name) : PublishCheck::warn('W09', $name, $warnings);
    }

    // W10 — segregation sets name >= 2 existing nodes whose participants cannot intersect.
    private static function w10Segregation(WorkflowDefinition $d, ValidationEnvironment $env): PublishCheck
    {
        $name = 'Segregation sets';

        if ($d->segregationSets === []) {
            return PublishCheck::pass('W10', $name);
        }

        $issues = [];
        $unresolvable = false;

        foreach ($d->segregationSets as $set) {
            $keys = $set['node_keys'] ?? [];

            if (count($keys) < 2) {
                $issues[] = "segregation set '{$set['set_key']}' names fewer than two nodes";

                continue;
            }

            foreach ($keys as $key) {
                if ($d->node($key) === null) {
                    $issues[] = "segregation set '{$set['set_key']}' names unknown node '{$key}'";
                }
            }

            // The check §IX.1.4's `admin`-satisfies-both would fail: two nodes
            // whose resolvable participant sets intersect are one person
            // completing both halves of a maker-checker control.
            if ($env->participantsByNode === null) {
                $unresolvable = true;

                continue;
            }

            $seen = [];

            foreach ($keys as $key) {
                foreach ($env->participantsByNode[$key] ?? [] as $user) {
                    if (isset($seen[$user])) {
                        $issues[] = "segregation set '{$set['set_key']}': user '{$user}' can decide both "
                            . "'{$seen[$user]}' and '{$key}'. One person cannot complete both halves of a "
                            . 'maker-checker control (§IX.1.4).';

                        continue;
                    }

                    $seen[$user] = $key;
                }
            }
        }

        if ($issues !== []) {
            return PublishCheck::block('W10', $name, $issues);
        }

        return $unresolvable
            ? PublishCheck::couldNotCheck('W10', $name,
                'no resolved participant sets supplied, so the sets were checked for shape but not for intersection '
                . '— and intersection is the check that matters')
            : PublishCheck::pass('W10', $name);
    }

    // W11 — every approval node has a participant or an explicit no-participant policy.
    private static function w11ApprovalParticipants(WorkflowDefinition $d): PublishCheck
    {
        $name = 'Approval participants';
        $issues = [];

        foreach ($d->nodesByKey as $node) {
            if (!$node->type->createsTasks()) {
                continue;
            }

            $required = array_filter($node->participants, static fn (ParticipantSpec $p): bool => !$p->optional);

            if ($required === [] && $node->onNoParticipant === 'fail_instance') {
                $issues[] = "'{$node->key}' has no non-optional participant and no explicit on_no_participant "
                    . 'policy — it can only ever fail the instance';
            }
        }

        return $issues === [] ? PublishCheck::pass('W11', $name) : PublishCheck::block('W11', $name, $issues);
    }

    // W12 — quorum bounds. Enforced in Node's constructor; recorded here.
    private static function w12Quorum(WorkflowDefinition $d): PublishCheck
    {
        $name = 'Quorum bounds';
        $quorums = $d->nodesOfType(NodeType::Quorum);

        if ($quorums === []) {
            return PublishCheck::pass('W12', $name);
        }

        $issues = [];

        foreach ($quorums as $node) {
            $n = (int) ($node->params['n'] ?? 0);
            $m = (int) ($node->params['m'] ?? 0);

            if ($n < 1 || $n > $m) {
                $issues[] = "'{$node->key}': quorum {$n} of {$m} is not satisfiable";
            }
        }

        return $issues === [] ? PublishCheck::pass('W12', $name) : PublishCheck::block('W12', $name, $issues);
    }

    // W13 — terminal means terminal.
    private static function w13TerminalHasNoExit(WorkflowDefinition $d): PublishCheck
    {
        $name = 'Terminal nodes';
        $issues = [];

        foreach ($d->nodesByKey as $node) {
            if ($node->isTerminal() && $d->transitionsFrom($node->key) !== []) {
                $issues[] = "'{$node->key}' is a {$node->type->value} node and has outgoing transitions";
            }
        }

        return $issues === [] ? PublishCheck::pass('W13', $name) : PublishCheck::block('W13', $name, $issues);
    }

    // W14 — a path to finalise AND a path to reject.
    private static function w14ReachableOutcomes(WorkflowDefinition $d): PublishCheck
    {
        $name = 'Both outcomes reachable';
        $reachable = self::reachable($d);
        $issues = [];

        foreach ([[NodeType::Finalise, 'complete'], [NodeType::Reject, 'refuse']] as [$type, $verb]) {
            $found = false;

            foreach ($d->nodesOfType($type) as $node) {
                if (isset($reachable[$node->key])) {
                    $found = true;

                    break;
                }
            }

            if (!$found) {
                $issues[] = "no path from the start node reaches a {$type->value} node — "
                    . ($verb === 'refuse'
                        ? 'a workflow that cannot be refused is not a control'
                        : 'a workflow that cannot complete is not a workflow');
            }
        }

        return $issues === [] ? PublishCheck::pass('W14', $name) : PublishCheck::block('W14', $name, $issues);
    }

    /**
     * W16 — an escalation that decides for a human needs a clock, a stale
     * policy, and permission to exist at all (§IX.7.6, R-IX-063, R-IX-066).
     *
     * The clock half is also enforced in Node's constructor and recorded here.
     * The other two halves can only be checked at definition level, because
     * both depend on what the WORKFLOW is, not on what the node says about
     * itself: whether it is a Section 56 operation, and whether the node sits
     * inside a segregation set.
     */
    private static function w16EscalationClock(WorkflowDefinition $d): PublishCheck
    {
        $name = 'Escalation clock';
        $issues = [];

        $segregated = [];

        foreach ($d->segregationSets as $set) {
            foreach ($set['node_keys'] ?? [] as $key) {
                $segregated[$key] = $set['set_key'] ?? 'a segregation set';
            }
        }

        foreach ($d->nodesByKey as $node) {
            $decides = in_array($node->escalationPolicy, ['auto_approve', 'auto_reject'], true);

            if ($decides && $node->slaMinutes === null) {
                $issues[] = "'{$node->key}' escalates to {$node->escalationPolicy} with no sla_minutes";
            }

            if (!$decides) {
                continue;
            }

            // R-IX-063. "An approval nobody gave is not a control." A maker-
            // checker workflow whose checker step auto-approves on breach is
            // the same defect as `admin` satisfying both isHR() and
            // isFinance(), reached by waiting instead of by logging in.
            if ($d->isSection56Operation) {
                $issues[] = "'{$node->key}' escalates to {$node->escalationPolicy} on a Section 56 operation. "
                    . 'An approval nobody gave is not a control (R-IX-063), and waiting out the clock must not '
                    . 'be a way to obtain one.';
            }

            if (isset($segregated[$node->key])) {
                $issues[] = "'{$node->key}' escalates to {$node->escalationPolicy} and belongs to "
                    . "{$segregated[$node->key]}. Segregation exists so that two different people decide; "
                    . 'an automatic decision is nobody deciding (R-IX-063).';
            }

            // R-IX-066: the node must declare what happens when the timer fires
            // late. Sweep defaults to suppressing, which is the safe direction,
            // but a node that decides money on a clock should say which clock
            // it stops trusting rather than inheriting a platform number.
            if ($node->staleThresholdMinutes === null) {
                $issues[] = "'{$node->key}' escalates to {$node->escalationPolicy} and declares no "
                    . 'stale_threshold_minutes. A scheduler outage followed by a catch-up sweep must never '
                    . 'silently decide a backlog, so the threshold at which the decision is withheld is '
                    . 'configuration, not a platform default (R-IX-066).';
            }
        }

        return $issues === [] ? PublishCheck::pass('W16', $name) : PublishCheck::block('W16', $name, $issues);
    }

    // W17 — no binding tie on specificity AND priority.
    private static function w17BindingTies(WorkflowDefinition $d, ValidationEnvironment $env): PublishCheck
    {
        $name = 'Binding ties';

        if ($env->boundWorkflows === null) {
            return PublishCheck::couldNotCheck('W17', $name,
                'the other workflows bound to this (entity, operation) were not supplied, so a specificity '
                . 'and priority tie between them could not be looked for');
        }

        $issues = [];

        // ValidationEnvironment declares boundWorkflows as list<WorkflowDefinition>,
        // but nothing enforces that at runtime for a caller assembling it from a
        // repository — the instanceof below is what actually protects this loop,
        // so it is widened locally rather than trusted as certain.
        /** @var list<mixed> $boundWorkflows */
        $boundWorkflows = $env->boundWorkflows;

        foreach ($boundWorkflows as $other) {
            if (!$other instanceof WorkflowDefinition
                || $other->versionId === $d->versionId
                || $other->subjectEntityKey !== $d->subjectEntityKey
            ) {
                continue;
            }

            if ($d->scope->tiesWith($other->scope, $d->priority, $other->priority)
                && $d->range->overlaps($other->range)
            ) {
                $issues[] = "ties with {$other->label()} on specificity and priority, with overlapping dates — "
                    . 'which subject reaches which workflow would be a coin-toss (spine S3.2)';
            }
        }

        return $issues === [] ? PublishCheck::pass('W17', $name) : PublishCheck::block('W17', $name, $issues);
    }

    // W18 — stored test cases pass.
    private static function w18StoredCases(WorkflowDefinition $d, ValidationEnvironment $env): PublishCheck
    {
        $name = 'Stored test cases';

        if ($env->storedCaseResults === null) {
            return PublishCheck::couldNotCheck('W18', $name,
                'no stored case results supplied — the simulator of §IX.3.7 has not been run against this version');
        }

        $failed = array_keys(array_filter($env->storedCaseResults, static fn (bool $passed): bool => !$passed));

        return $failed === []
            ? PublishCheck::pass('W18', $name)
            : PublishCheck::block('W18', $name, 'these stored cases fail: ' . implode(', ', $failed));
    }

    /** @return array<string, true> */
    private static function reachable(WorkflowDefinition $d): array
    {
        $seen = [];
        $queue = [$d->startNodeKey];

        while ($queue !== []) {
            $key = array_shift($queue);

            if (isset($seen[$key]) || $d->node($key) === null) {
                continue;
            }

            $seen[$key] = true;

            foreach ($d->transitionsFrom($key) as $transition) {
                $queue[] = $transition->to;
            }
        }

        return $seen;
    }
}
