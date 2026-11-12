<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx\Ast;

/**
 * `employee.code`, `salary.component('BASIC')`, `org.is_in('branch','HYD')`.
 *
 * The only way to read anything. There is no accessor that takes a subject, so
 * no expression can name another employee or another tenant (Part VII §10.1c-2):
 * the keyed accessors take a code from a value set, never an identifier.
 *
 * `args` is null for a plain path and a (possibly empty) list for a keyed one —
 * `leave.balance` and `leave.balance('SL')` are different symbols.
 */
final readonly class VariableRef extends Node
{
    /** @param list<string> $path  @param list<Node>|null $args */
    public function __construct(
        public string $namespace,
        public array $path,
        public ?array $args,
        int $line,
        int $column,
    ) {
        parent::__construct($line, $column);
    }

    /** `salary.component` — the symbol-table key, without the arguments. */
    public function symbol(): string
    {
        return $this->namespace . '.' . implode('.', $this->path);
    }

    public function kind(): string
    {
        return 'var';
    }

    public function children(): array
    {
        return $this->args ?? [];
    }

    public function fields(): array
    {
        return ['namespace' => $this->namespace, 'path' => $this->path, 'keyed' => $this->args !== null];
    }
}
