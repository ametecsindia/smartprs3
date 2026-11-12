<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Formula;

use SmartPRS\Platform\Spx\SymbolTable;
use SmartPRS\Platform\Spx\Types\Type;

/**
 * WHAT `formula.value('CODE')` RETURNS — Part VIII §11.0.
 *
 * "`formula.value('CODE')` typed by the referenced formula." The type is the
 * referenced formula's declared `result_type`, and until something says so,
 * every reference to another formula is untyped — so `F-OT-AMOUNT`, which is
 * `hourly_rate × hours × multiplier`, read as Null × Hours and did not compile.
 *
 * The fourth register of this shape, and the last one: PolicyKeys types a
 * policy by its key, AggMetrics types an event by its metric, StatutoryKeys
 * types a statutory value by its code and defers to the signed pack, and this
 * types a formula by the formula. Each closed the same hole — a keyed accessor
 * whose type depends on its key, with nothing declaring the key.
 *
 * Unlike the other three this one is not a file of literals: the types are
 * already declared, on the formulas themselves, and a second copy of them would
 * be the second source of truth R-VIII-012 refuses for dependency sets. It is
 * assembled from the published formulas of the tenant and scope being compiled
 * against, which is also why a formula referencing one that does not exist is a
 * G2 failure naming the code rather than a null at runtime.
 */
final class FormulaRegistry
{
    /** @var array<string, Type> */
    private array $types = [];

    /** @param list<Formula> $formulas */
    public function __construct(array $formulas = [])
    {
        foreach ($formulas as $formula) {
            $this->add($formula);
        }
    }

    public function add(Formula $formula): void
    {
        $this->declare($formula->code, $formula->resultType);
    }

    /**
     * Declare a code and its type without a whole Formula.
     *
     * Publication is ordered: a formula that references another must compile
     * before the referenced one can be evaluated, and at that moment only the
     * referenced formula's DECLARED type matters, not its expression. This is
     * also what lets the blueprint harness compile a worked formula against the
     * result types the document declares beside it.
     */
    public function declare(string $code, Type $type): void
    {
        $existing = $this->types[$code] ?? null;

        if ($existing !== null && !$existing->equals($type)) {
            throw new \InvalidArgumentException(
                "Formula '{$code}' is already registered as {$existing} and cannot also be {$type}. "
                . 'A code with two result types is two formulas.'
            );
        }

        $this->types[$code] = $type;
    }

    public function typeOf(string $code): ?Type
    {
        return $this->types[$code] ?? null;
    }

    public function has(string $code): bool
    {
        return isset($this->types[$code]);
    }

    /** @return list<string> */
    public function codes(): array
    {
        $codes = array_keys($this->types);
        sort($codes);

        return $codes;
    }

    /** @return array<string, Type> */
    public function keyedTypes(): array
    {
        return $this->types;
    }

    /**
     * A symbol table in which `formula.value('CODE')` has the type the
     * referenced formula declares.
     */
    public function applyTo(SymbolTable $symbols): SymbolTable
    {
        return $this->types === []
            ? $symbols
            : $symbols->withKeyedTypes('formula.value', $this->types);
    }
}
