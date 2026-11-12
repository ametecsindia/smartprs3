<?php

declare(strict_types=1);

use SmartPRS\Surface\PublishedWorkflows;

/**
 * THE DECLARED WORKFLOWS, THROUGH THE PLATFORM'S OWN PUBLISH GATES.
 *
 * `Platform\Workflow` has parallel nodes, quorum, SLA timers, delegation,
 * escalation and a validator with eleven gates, and nothing on any screen had
 * ever entered it. `Surface\Requests` runs a linear chain that works, and the
 * note in `config/workflows.json` has said so honestly since somebody noticed it
 * claimed otherwise.
 *
 * This closes the first half: every declaration is compiled into a real
 * `WorkflowDefinition` and validated. Building it was worth the trouble on its
 * own, because four of the eleven gates refused the shipped file in turn and
 * every one of them was right — W03 (an approval produces five outcomes, not
 * two, and the ones nobody thinks about are what strand a request), W14 (a chain
 * whose end is a step is a chain that never ends), W05 (an approver returning a
 * request to themselves is an invisible infinite loop) and W11 (a task node with
 * no participant can only ever fail the instance).
 *
 * The assertion that matters most is the last one: break the declaration and the
 * gates must refuse it. A validator that passes everything is the shape this
 * codebase keeps finding.
 */

group('Published workflows');

$root = dirname(__DIR__);
$workflows = new PublishedWorkflows($root . '/config/workflows.json');

same(null, $workflows->refusal(), 'every declared workflow compiles');
ok(count($workflows->definitions()) >= 5, 'and there are workflows to compile');

// EVERY ONE PASSES EVERY GATE. Asserted as a list of the failures rather than a
// count, so a failure names itself instead of arriving as "expected 0, got 1".
same([], $workflows->unpublishable(),
    'every declared workflow passes all eleven publish gates');

// The gates actually RAN — a report with no checks in it would satisfy the
// assertion above while proving nothing.
$reports = $workflows->reports();
$first = $reports['leave-request'] ?? null;

ok($first !== null, 'the leave workflow is among them');
ok(count($first->checks) >= 10, 'and its report carries the full set of gates, not an empty pass');

// ---------------------------------------------------------------------------
// THE DERIVED GRAPH IS A GRAPH, not a list with a hopeful name. A declaration
// lists the people who decide; these are the parts it does not mention and
// without which the gates refuse it.
// ---------------------------------------------------------------------------

$definition = $workflows->definitions()['leave-request'];
$nodeKeys = array_map(static fn ($n): string => $n->key, $definition->nodes);

ok(in_array('completed', $nodeKeys, true), 'the chain has a terminal finalise node');
ok(in_array('refused', $nodeKeys, true), 'and a terminal reject node');
ok(in_array('sent_back', $nodeKeys, true),
    'and a visible return-for-edit node — an approver returning a request to themselves is an '
    . 'infinite loop nobody can see, which is exactly what gate W05 refuses');

// ---------------------------------------------------------------------------
// NON-VACUITY, TWICE OVER. Both halves must bite: a file that will not compile,
// and a file that compiles into a graph the gates refuse.
// ---------------------------------------------------------------------------

$broken = sys_get_temp_dir() . '/workflows-broken.json';
$declared = json_decode((string) file_get_contents($root . '/config/workflows.json'), true);
$declared['workflows'][0]['steps'][1]['type'] = 'not_a_node_type';
file_put_contents($broken, json_encode($declared));

$refusing = new PublishedWorkflows($broken);
same([], $refusing->definitions(), 'one uncompilable workflow publishes none of them');
ok(str_contains((string) $refusing->refusal(), 'leave-request'),
    'and the refusal names the workflow that caused it');
unlink($broken);

// A workflow that COMPILES and is still wrong. This is the half that matters:
// the gates have to bite on a graph, not only on a file that will not parse.
$noSubmit = sys_get_temp_dir() . '/workflows-no-submit.json';
$declared = json_decode((string) file_get_contents($root . '/config/workflows.json'), true);
$declared['workflows'] = [$declared['workflows'][0]];
// Drop the submit step, keeping the approvals. Every step still compiles, the
// graph is still connected, and the workflow has no entry point.
array_shift($declared['workflows'][0]['steps']);
file_put_contents($noSubmit, json_encode($declared));

$entryless = new PublishedWorkflows($noSubmit);
same(null, $entryless->refusal(), 'a chain with no submit step still COMPILES — the file is valid');
same(['leave-request'], $entryless->unpublishable(),
    'and the gates refuse it anyway: W01 wants exactly one submit node, because a workflow with '
    . 'no defined start is a request nobody can raise');
unlink($noSubmit);

// And the legitimate small case publishes: submit plus one approver is a real
// chain and must not be caught by anything above.
$minimal = sys_get_temp_dir() . '/workflows-minimal.json';
$declared = json_decode((string) file_get_contents($root . '/config/workflows.json'), true);
$declared['workflows'] = [$declared['workflows'][0]];
$declared['workflows'][0]['steps'] = array_slice($declared['workflows'][0]['steps'], 0, 2);
file_put_contents($minimal, json_encode($declared));

$small = new PublishedWorkflows($minimal);
same([], $small->unpublishable(), 'submit plus one approver is a legitimate workflow');
unlink($minimal);
