<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow;

/**
 * The instance has nowhere to go (Part IX §IX.4, the `park` disposition).
 *
 * "Nothing is ever silently skipped, and nothing ever auto-approves as a
 * consequence of an error" (R-IX-021). This is thrown so the caller parks the
 * instance — state `blocked`, an operator alert, no data change — rather than
 * choosing a branch on the instance's behalf.
 */
final class NodeStranded extends \RuntimeException
{
    public function __construct(
        public readonly string $nodeKey,
        public readonly string $outcome,
        string $why,
    ) {
        parent::__construct(
            "Node '{$nodeKey}' produced outcome '{$outcome}' and {$why}. "
            . 'The instance is blocked and an operator alerted; no business decision was taken.'
        );
    }
}
