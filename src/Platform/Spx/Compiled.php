<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx;

use SmartPRS\Platform\Spx\Ast\AggCall;
use SmartPRS\Platform\Spx\Ast\Codec;
use SmartPRS\Platform\Spx\Ast\FunctionCall;
use SmartPRS\Platform\Spx\Ast\Literal;
use SmartPRS\Platform\Spx\Ast\Node;
use SmartPRS\Platform\Spx\Functions\Catalogue;

/**
 * THE STORED ARTEFACT — spine S5, Part VII §10.1a-3.
 *
 * "The stored artefact is the AST plus the source text, so a published rule
 * cannot be re-parsed differently by a later version of the parser."
 *
 * Both halves matter. The AST is what evaluates, so a parser change cannot
 * silently re-interpret a rule that has been paying people for two years. The
 * source is what an author reads in a diff, and what the trace shows.
 *
 * The encoding is versioned JSON with an explicit reader (Ast\Codec).
 * `unserialize()` never touches these bytes: there is no PHP object
 * deserialisation anywhere on a tenant-supplied path (§10.1a-4).
 *
 * Three signatures the rule engine depends on and this class must keep stable:
 *   Compiled::parse(string, SymbolTable): Compiled
 *   Compiled::typeCheck(): TypeCheckResult
 *   Evaluator::evaluate(Compiled, Context): Value
 */
final readonly class Compiled
{
    /**
     * Functions whose answer is assembled by the caller before evaluation
     * begins, rather than fetched by the evaluator (which cannot fetch).
     */
    public const PREPARED_FUNCTIONS = [
        'PAYABLE_DAYS', 'ATTENDANCE_HOURS', 'OT_HOURS', 'LEAVE_UNITS', 'LEAVE_DAYS_IN_RANGE',
    ];

    public function __construct(
        public string $source,
        public Node $ast,
        public SymbolTable $symbols,
        public int $codecVersion = Codec::VERSION,
        public int $catalogueVersion = Catalogue::VERSION,
    ) {
    }

    /** @throws SpxSyntaxError */
    public static function parse(string $source, SymbolTable $symbols): self
    {
        return new self($source, Parser::parse($source), $symbols);
    }

    public function typeCheck(): TypeCheckResult
    {
        return TypeChecker::check($this->ast, $this->symbols);
    }

    /** The same table, another decision point — re-checking is the caller's job. */
    public function withSymbols(SymbolTable $symbols): self
    {
        return new self($this->source, $this->ast, $symbols, $this->codecVersion, $this->catalogueVersion);
    }

    public function nodeCount(): int
    {
        return $this->ast->size();
    }

    public function depth(): int
    {
        return $this->ast->depth();
    }

    /**
     * Every prepared-context key this expression will look up, as far as it can
     * be known statically. The caller resolves these — aggregation counters,
     * settlement answers — and puts them in the Context. A key that is not
     * prepared becomes a named ERR_AGG_UNAVAILABLE or ERR_MISSING_INPUT at
     * evaluation, never a query and never a zero.
     *
     * @return list<string>
     */
    public function preparedKeys(): array
    {
        $keys = [];
        self::collectKeys($this->ast, $keys);
        sort($keys);

        return array_values(array_unique($keys));
    }

    /** @param list<string> $keys */
    private static function collectKeys(Node $node, array &$keys): void
    {
        if ($node instanceof AggCall) {
            $keys[] = Evaluator::aggKey($node);
        }

        if ($node instanceof FunctionCall && in_array($node->name, self::PREPARED_FUNCTIONS, true)) {
            $arguments = [];

            foreach ($node->args as $argument) {
                if (!$argument instanceof Literal) {
                    $arguments = null;      // a computed argument: known only at runtime

                    break;
                }

                $arguments[] = Evaluator::literalValue($argument);
            }

            if ($arguments !== null) {
                $keys[] = Context::key($node->name, $arguments);
            }
        }

        foreach ($node->children() as $child) {
            self::collectKeys($child, $keys);
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'codec' => $this->codecVersion,
            'catalogue' => $this->catalogueVersion,
            'source' => $this->source,
            'ast' => Codec::encode($this->ast),
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, SymbolTable $symbols): self
    {
        $codec = $data['codec'] ?? null;

        if (!is_int($codec) || $codec > Codec::VERSION) {
            throw new SpxSyntaxError(
                'Stored AST encoding version ' . var_export($codec, true)
                . ' is not readable by this build (current ' . Codec::VERSION . ')',
                0,
                0,
            );
        }

        if (!is_array($data['ast'] ?? null) || !is_string($data['source'] ?? null)) {
            throw new SpxSyntaxError('Stored artefact is missing its source or its AST', 0, 0);
        }

        return new self(
            $data['source'],
            Codec::decode($data['ast']),
            $symbols,
            $codec,
            is_int($data['catalogue'] ?? null) ? $data['catalogue'] : Catalogue::VERSION,
        );
    }

    public static function fromJson(string $json, SymbolTable $symbols): self
    {
        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new SpxSyntaxError('Stored artefact is not an object', 0, 0);
        }

        return self::fromArray($data, $symbols);
    }
}
