<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Formula;

/**
 * THE DEPENDENCY GRAPH — Part VIII §7.
 *
 * Built at publish time from compiled ASTs, per resolved scope (R-VIII-035).
 * Two things come out of it, and both are correctness-critical:
 *
 *   1. CYCLES ARE REFUSED, with the cycle named (R-VIII-034). A cycle is never
 *      broken at runtime by a depth limit, an iteration cap or a "use the last
 *      known value" fallback — R-VIII-036 — and the evaluator has no
 *      cycle-handling path at all, because it cannot be handed a cyclic graph.
 *
 *   2. EVALUATION ORDER IS TOPOLOGICAL, not the component's sequence_no
 *      (R-VIII-037). SmartPRS 2 conflates them: computeSlipFromComponents()
 *      sorts by seq ASC and then hard-codes five ordered passes because a
 *      sequence number cannot express a dependency — so a customer who reorders
 *      their payslip changes their arithmetic. Here sequence_no governs
 *      presentation and nothing else.
 *
 * Where the order is not total — two components with no dependency between them
 * — ties break by sequence_no then code, so the order is identical across runs,
 * machines and PHP versions.
 */
final class Graph
{
    /** @var array<string, list<string>> node => nodes it depends on */
    private array $edges = [];

    /** @var array<string, array<string, string>> node => (dependency => why) */
    private array $origins = [];

    /** @var array<string, int> node => sequence_no, the declared tie-break */
    private array $sequence = [];

    /** @var array<string, string> node => a label for the error message */
    private array $labels = [];

    /**
     * Add a node and the edges out of it.
     *
     * @param list<Dependency> $dependencies
     */
    public function add(string $node, array $dependencies, int $sequenceNo = 0, ?string $label = null): void
    {
        $this->edges[$node] ??= [];
        $this->sequence[$node] = $sequenceNo;
        $this->labels[$node] = $label ?? $node;

        foreach ($dependencies as $dependency) {
            $target = $dependency->node();
            $this->edges[$node][] = $target;
            $this->edges[$target] ??= [];
            $this->sequence[$target] ??= 0;
            $this->labels[$target] ??= $target;
            $this->origins[$node][$target] ??= $dependency->kind === 'formula'
                ? "formula.value('{$dependency->code}')"
                : $dependency->kind . " '{$dependency->code}'";
        }

        $this->edges[$node] = array_values(array_unique($this->edges[$node]));
    }

    /** An edge the AST cannot show: a component's configured formula, a percent_of basis. */
    public function addEdge(string $from, string $to, string $why): void
    {
        $this->edges[$from] ??= [];
        $this->edges[$to] ??= [];
        $this->sequence[$from] ??= 0;
        $this->sequence[$to] ??= 0;
        $this->labels[$from] ??= $from;
        $this->labels[$to] ??= $to;

        if (!in_array($to, $this->edges[$from], true)) {
            $this->edges[$from][] = $to;
        }

        $this->origins[$from][$to] ??= $why;
    }

    /** @return list<string> */
    public function nodes(): array
    {
        $nodes = array_keys($this->edges);
        sort($nodes);

        return $nodes;
    }

    /** @return list<string> the nodes $node depends on */
    public function dependenciesOf(string $node): array
    {
        return $this->edges[$node] ?? [];
    }

    /**
     * Evaluation order: a dependency always precedes what depends on it.
     *
     * @return list<string>
     * @throws CircularDependency
     */
    public function evaluationOrder(): array
    {
        $cycle = $this->findCycle();

        if ($cycle !== null) {
            throw CircularDependency::of($cycle, $this->labels, $this->origins);
        }

        $order = [];
        $state = [];

        // Deterministic entry order, so two runs produce the same total order
        // even where the partial order leaves a choice.
        foreach ($this->sortedNodes() as $node) {
            $this->visit($node, $state, $order);
        }

        return $order;
    }

    /**
     * The first cycle found, as the list of nodes in traversal order with the
     * closing node repeated, or null when the graph is acyclic.
     *
     * Tarjan finds every SCC; what an operator needs is ONE cycle they can read,
     * in the order the references were written. A depth-first walk that keeps
     * its own stack gives exactly that, at the same cost.
     *
     * @return list<string>|null
     */
    public function findCycle(): ?array
    {
        $state = [];
        $stack = [];

        foreach ($this->sortedNodes() as $node) {
            $cycle = $this->descend($node, $state, $stack);

            if ($cycle !== null) {
                return $cycle;
            }
        }

        return null;
    }

    public function hasCycle(): bool
    {
        return $this->findCycle() !== null;
    }

    /**
     * @param array<string, int> $state    0/absent = unseen, 1 = on the stack, 2 = done
     * @param list<string> $stack
     * @return list<string>|null
     */
    private function descend(string $node, array &$state, array &$stack): ?array
    {
        $current = $state[$node] ?? 0;

        if ($current === 2) {
            return null;
        }

        if ($current === 1) {
            // Closing the loop: return the stack from the first sighting on.
            $from = array_search($node, $stack, true);

            return array_values(array_merge(array_slice($stack, (int) $from), [$node]));
        }

        $state[$node] = 1;
        $stack[] = $node;

        foreach ($this->edges[$node] ?? [] as $next) {
            $cycle = $this->descend($next, $state, $stack);

            if ($cycle !== null) {
                return $cycle;
            }
        }

        array_pop($stack);
        $state[$node] = 2;

        return null;
    }

    /**
     * @param array<string, bool> $state
     * @param list<string> $order
     */
    private function visit(string $node, array &$state, array &$order): void
    {
        if (isset($state[$node])) {
            return;
        }

        $state[$node] = true;

        $dependencies = $this->edges[$node] ?? [];
        usort($dependencies, fn (string $a, string $b): int => $this->compareNodes($a, $b));

        foreach ($dependencies as $next) {
            $this->visit($next, $state, $order);
        }

        $order[] = $node;
    }

    /** @return list<string> */
    private function sortedNodes(): array
    {
        $nodes = array_keys($this->edges);
        usort($nodes, fn (string $a, string $b): int => $this->compareNodes($a, $b));

        return $nodes;
    }

    /** R-VIII-037's tie-break: sequence_no, then code. Never anything ambient. */
    private function compareNodes(string $a, string $b): int
    {
        return [$this->sequence[$a] ?? 0, $a] <=> [$this->sequence[$b] ?? 0, $b];
    }
}
