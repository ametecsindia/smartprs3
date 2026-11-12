<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx\Ast;

use SmartPRS\Platform\Spx\SpxSyntaxError;

/**
 * The AST reader and writer (Part VII §10.1a-3/4).
 *
 * The stored artefact is the AST, so a published rule cannot be re-parsed
 * differently by a later parser version. That only holds if the stored form is
 * read by an *explicit reader*: `unserialize()` is forbidden on tenant-supplied
 * bytes, and this codec never constructs a class named by the payload. The
 * `match` below is the whole set of node kinds it can produce; an unknown kind
 * is an error, not a dynamic instantiation.
 *
 * ponytail: the blueprint says "versioned binary encoding"; this is versioned
 * JSON. It round-trips exactly, it is diffable in a review, and it is one
 * function. Move to a packed encoding when AST storage volume actually shows up
 * in a bill.
 */
final class Codec
{
    /** Bump when the node set changes shape. Stored alongside every AST. */
    public const VERSION = 1;

    /** @return array<string, mixed> */
    public static function encode(Node $node): array
    {
        $encoded = ['k' => $node->kind(), 'l' => $node->line, 'c' => $node->column];

        if ($node->fields() !== []) {
            $encoded['f'] = $node->fields();
        }

        if ($node->children() !== []) {
            $encoded['n'] = array_map(self::encode(...), $node->children());
        }

        return $encoded;
    }

    /**
     * The same encoding without positions — the canonical form of a subtree.
     *
     * An aggregation predicate's identity is its shape, not where in the source
     * it was written: the same predicate in two rules must hash to the same
     * `qualifier_hash` so it maps to the same counter definition (§5.9.4).
     *
     * @return array<string, mixed>
     */
    public static function encodeCanonical(Node $node): array
    {
        $encoded = ['k' => $node->kind()];

        if ($node->fields() !== []) {
            $encoded['f'] = $node->fields();
        }

        if ($node->children() !== []) {
            $encoded['n'] = array_map(self::encodeCanonical(...), $node->children());
        }

        return $encoded;
    }

    /** @param array<string, mixed> $data */
    public static function decode(array $data): Node
    {
        $kind = self::string($data, 'k');
        $line = self::int($data, 'l');
        $column = self::int($data, 'c');
        $raw = $data['f'] ?? [];
        /** @var array<string, mixed> $fields */
        $fields = is_array($raw) ? $raw : [];
        $encoded = $data['n'] ?? [];
        $children = [];

        foreach (is_array($encoded) ? $encoded : [] as $child) {
            if (!is_array($child)) {
                throw new SpxSyntaxError('Malformed stored AST: a child node is not an object', $line, $column);
            }

            $children[] = self::decode($child);
        }

        return match ($kind) {
            'literal' => new Literal(
                LiteralKind::from(self::string($fields, 'literal')),
                self::string($fields, 'text'),
                isset($fields['unit']) && is_string($fields['unit']) ? $fields['unit'] : null,
                $line,
                $column,
            ),
            'list' => new ListLiteral($children, $line, $column),
            'var' => new VariableRef(
                self::string($fields, 'namespace'),
                array_values(array_map(strval(...), is_array($fields['path'] ?? null) ? $fields['path'] : [])),
                ($fields['keyed'] ?? false) === true ? $children : null,
                $line,
                $column,
            ),
            'call' => new FunctionCall(self::string($fields, 'name'), $children, $line, $column),
            'agg' => new AggCall(self::string($fields, 'function'), $children, $line, $column),
            'window' => new WindowSpec(self::string($fields, 'window'), $children, $line, $column),
            'predicate' => new PredicateBlock(self::child($children, 0, $line, $column), $line, $column),
            'unary' => new Unary(
                self::string($fields, 'operator'),
                self::child($children, 0, $line, $column),
                $line,
                $column,
            ),
            'binary' => new Binary(
                self::string($fields, 'operator'),
                self::child($children, 0, $line, $column),
                self::child($children, 1, $line, $column),
                $line,
                $column,
            ),
            'between' => new Between(
                self::child($children, 0, $line, $column),
                self::child($children, 1, $line, $column),
                self::child($children, 2, $line, $column),
                $line,
                $column,
            ),
            'case' => self::decodeCase($fields, $children, $line, $column),
            default => throw new SpxSyntaxError("Unknown AST node kind '{$kind}' in the stored artefact", $line, $column),
        };
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<Node> $children
     */
    private static function decodeCase(array $fields, array $children, int $line, int $column): CaseExpr
    {
        $branches = is_int($fields['branches'] ?? null) ? $fields['branches'] : 0;

        if (count($children) !== $branches * 2 + 1) {
            throw new SpxSyntaxError('Malformed stored AST: CASE branch count does not match its children', $line, $column);
        }

        $whens = [];

        for ($i = 0; $i < $branches; $i++) {
            $whens[] = [$children[$i * 2], $children[$i * 2 + 1]];
        }

        return new CaseExpr($whens, $children[$branches * 2], $line, $column);
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new SpxSyntaxError("Malformed stored AST: missing string field '{$key}'", 0, 0);
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function int(array $data, string $key): int
    {
        if (!isset($data[$key]) || !is_int($data[$key])) {
            throw new SpxSyntaxError("Malformed stored AST: missing integer field '{$key}'", 0, 0);
        }

        return $data[$key];
    }

    /** @param list<Node> $children */
    private static function child(array $children, int $index, int $line, int $column): Node
    {
        return $children[$index]
            ?? throw new SpxSyntaxError('Malformed stored AST: a node is missing an operand', $line, $column);
    }
}
