<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Formula;

use SmartPRS\Platform\Spx\Ast\AggCall;
use SmartPRS\Platform\Spx\Ast\Node;
use SmartPRS\Platform\Spx\Ast\VariableRef;
use SmartPRS\Platform\Spx\Compiled;
use SmartPRS\Platform\Spx\Functions\Catalogue;

/**
 * Deriving a formula's dependency set from its compiled AST (Part VIII §7.1).
 *
 * The edge table of §7.1, read from the other direction:
 *
 *   salary.component('CODE')   → component
 *   formula.value('CODE')      → formula
 *   statutory.value('CODE')    → statutory
 *   policy.value('key')        → policy      (a source)
 *   agg.balance('code', …)     → ledger      (a source)
 *   settlement.*               → settlement  (a source)
 *   input.value('CODE')        → input       (a source)
 *
 * R-VIII-012: this is the ONLY way a dependency set is produced. There is no
 * setter, and Formula refuses an authored one — because a hand-maintained
 * dependency list is a second source of truth, and a stale one silently breaks
 * both cycle detection and the topological order that payroll correctness rests
 * on.
 */
final class Dependencies
{
    /** symbol => dependency kind, for keyed accessors whose key is the code. */
    private const KEYED = [
        'salary.component' => 'component',
        'formula.value' => 'formula',
        'statutory.value' => 'statutory',
        'policy.value' => 'policy',
        'input.value' => 'input',
    ];

    /** agg functions whose first argument names a ledger rather than an event. */
    private const LEDGER_AGGS = ['balance'];

    /**
     * Every dependency the expression names, deduplicated, in a stable order.
     *
     * @return list<Dependency>
     */
    public static function of(Compiled $compiled): array
    {
        $found = [];
        self::walk($compiled->ast, $found);
        ksort($found);

        return array_values($found);
    }

    /**
     * The dependencies of one kind, as bare codes. Convenient for the graph and
     * for the "which components does this formula read" question a reviewer asks.
     *
     * @return list<string>
     */
    public static function codesOfKind(Compiled $compiled, string $kind): array
    {
        $codes = [];

        foreach (self::of($compiled) as $dependency) {
            if ($dependency->kind === $kind) {
                $codes[] = $dependency->code;
            }
        }

        return $codes;
    }

    /** @param array<string, Dependency> $found */
    private static function walk(Node $node, array &$found): void
    {
        if ($node instanceof VariableRef) {
            self::fromVariable($node, $found);
        }

        if ($node instanceof AggCall && in_array($node->function, self::LEDGER_AGGS, true)) {
            $code = isset($node->args[0]) ? Catalogue::literalText($node->args[0]) : null;

            if ($code !== null) {
                $dependency = new Dependency('ledger', $code);
                $found[$dependency->node()] = $dependency;
            }
        }

        foreach ($node->children() as $child) {
            self::walk($child, $found);
        }
    }

    /** @param array<string, Dependency> $found */
    private static function fromVariable(VariableRef $node, array &$found): void
    {
        $symbol = $node->symbol();

        // A settlement read is a source: the frozen payroll-facing view of the
        // period (spine S10 layer 4). A formula never reads punches.
        if ($node->namespace === 'settlement') {
            $dependency = new Dependency('settlement', $symbol);
            $found[$dependency->node()] = $dependency;

            return;
        }

        $kind = self::KEYED[$symbol] ?? null;

        if ($kind === null) {
            return;
        }

        // §7.1 R-VIII-033: the key is a string literal or the reference is not
        // expressible. A computed key would make the graph incomplete, so there
        // is no production for one — reaching here without a literal means the
        // grammar changed and the graph would silently stop being sound.
        $code = isset($node->args[0]) ? Catalogue::literalText($node->args[0]) : null;

        if ($code === null) {
            throw new \LogicException(
                "'{$symbol}' was reached with a non-literal key. The dependency graph cannot be "
                . 'derived from it, so the grammar must not permit it (Part VII §5.3 key_arg ::= string_lit).'
            );
        }

        $dependency = new Dependency($kind, $code);
        $found[$dependency->node()] = $dependency;
    }
}
