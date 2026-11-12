<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow;

/**
 * A delegation chain that never terminates (Part IX §IX.5.5).
 *
 * The twin of Organisation\ReportingCycleDetected, and thrown for the same
 * reason: a cycle DENIES, it never widens scope. A → B → A has no terminal
 * delegate, and the tempting resolution — take the first hop and stop — hands
 * the approval to whichever of the two the loop happened to visit first, which
 * on half the runs is the person the delegation existed to route around.
 *
 * Caught by the participant resolver, which excludes that candidate with the
 * cycle named in the trace rather than failing the whole node: one user's
 * broken delegation must not block a node that has three other eligible
 * approvers. If the set then empties, `on_no_participant` decides what happens,
 * which is a policy decision somebody wrote down.
 */
final class CircularDelegation extends \RuntimeException
{
}
