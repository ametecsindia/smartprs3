<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow;

/**
 * One edge of a workflow definition (Part IX §IX.3.2).
 *
 * Transitions are evaluated in declared order and the FIRST match wins. That is
 * a decision, not an accident: an ordered list an administrator can read top to
 * bottom is explainable, and "whichever matched" is not.
 */
final readonly class Transition
{
    public function __construct(
        public string $from,
        public string $outcome,
        public string $to,
        public ?string $conditionExpression = null,
        public string $label = '',
        public int $order = 0,
    ) {
        if ($from === '' || $to === '' || $outcome === '') {
            throw new \InvalidArgumentException('A transition names a from node, an outcome and a to node.');
        }
    }

    public function describe(): string
    {
        return $this->from . ' --' . $this->outcome
            . ($this->conditionExpression === null ? '' : ' when ' . $this->conditionExpression)
            . '--> ' . $this->to;
    }
}
