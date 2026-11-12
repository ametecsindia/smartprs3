<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx;

/**
 * THE RESOURCE LIMITS — Part VII §7.7 and §10.1b/§10.3.
 *
 * The claim being defended is that a customer-authored rule cannot exhaust
 * resources. Every unbounded construct is absent or bounded:
 *
 *   loop            — no production in the grammar
 *   recursion       — no user functions; AST depth capped at publish
 *   blow-up         — node budget at runtime, source length and list length at publish
 *   regex           — literal pattern, anchored, linted, step-limited
 *   time            — a wall-clock budget per evaluation
 *
 * Every breach is a NAMED failure with the budget in it, never a hang and never
 * a partial answer. A budget breach aborts the whole evaluation (§5.8).
 *
 * The instance is the per-evaluation counter; the static methods are the regex
 * half, which is needed at publish time too.
 */
final class Sandbox
{
    public const MAX_NODES = 5000;
    public const MAX_DEPTH = 32;
    public const MAX_MILLIS = 20;
    public const MAX_REGEX_STEPS = 10000;
    public const MAX_TEXT_CHARS = 512;

    /** Checking the clock on every node costs more than the budget it guards. */
    private const CLOCK_EVERY = 64;

    private int $nodes = 0;

    /** Nanoseconds, from hrtime(true) — an int on every 64-bit build. */
    private readonly int $deadline;

    public function __construct(
        public readonly int $nodeBudget = self::MAX_NODES,
        public readonly int $millisBudget = self::MAX_MILLIS,
    ) {
        // hrtime, not the wall clock: an evaluation must not be affected by an
        // NTP step, and the domain has no clock (Law 3).
        $this->deadline = hrtime(true) + $millisBudget * 1_000_000;
    }

    /** Count one evaluated node. Returns the breach, or null to continue. */
    public function tick(): ?Value
    {
        if (++$this->nodes > $this->nodeBudget) {
            return Value::error(
                ErrorCode::BudgetNodes,
                "Evaluation exceeded the node budget of {$this->nodeBudget}",
            );
        }

        if ($this->nodes % self::CLOCK_EVERY === 0 && hrtime(true) > $this->deadline) {
            return Value::error(
                ErrorCode::BudgetTime,
                "Evaluation exceeded its time budget of {$this->millisBudget} ms",
            );
        }

        return null;
    }

    public function nodesEvaluated(): int
    {
        return $this->nodes;
    }

    /**
     * The publish-time regex linter (§10.3). Rejects the shapes with a
     * super-linear worst case before they can ever be evaluated.
     *
     * ponytail: the nested-quantifier test rejects any quantified group whose
     * body contains a quantifier or an alternation — so `(ab|cd)+` is refused
     * along with `(a|aa)+`. Over-strict, deliberately: the safe direction of a
     * false positive here is an author rewriting a pattern.
     */
    public static function lintPattern(string $pattern): ?string
    {
        if ($pattern === '') {
            return 'MATCHES needs a pattern';
        }

        if (mb_strlen($pattern, 'UTF-8') > self::MAX_TEXT_CHARS) {
            return 'A MATCHES pattern is bounded to ' . self::MAX_TEXT_CHARS . ' characters';
        }

        foreach ([
            '/\\\\[1-9]/' => 'backreferences',
            '/\(\?<[=!]/' => 'lookbehind',
            '/\(\?(?:R|[0-9]|&|P>)/' => 'recursion',
            '/\(\?\{|\(\?\?\{/' => 'code assertions',
        ] as $probe => $what) {
            if (preg_match($probe, $pattern) === 1) {
                return "MATCHES rejects {$what}: the pattern must be linear-time bounded (§10.3)";
            }
        }

        $nested = self::findNestedQuantifier($pattern);

        if ($nested !== null) {
            return "MATCHES rejects the nested unbounded quantifier '{$nested}': "
                . 'it is the catastrophic-backtracking shape (§10.3)';
        }

        if (@preg_match(self::compile($pattern), '') === false) {
            return 'MATCHES pattern is not a valid regular expression';
        }

        return null;
    }

    /**
     * Evaluate an already-linted pattern under a step limit. Returns a Boolean
     * Value, or ERR_REGEX_STEPS — never a hang, never a silent false.
     */
    public static function matches(string $pattern, string $subject): Value
    {
        if (mb_strlen($subject, 'UTF-8') > self::MAX_TEXT_CHARS) {
            return Value::error(ErrorCode::Arg, 'MATCHES subject exceeds ' . self::MAX_TEXT_CHARS . ' characters');
        }

        $backtrack = ini_get('pcre.backtrack_limit');
        $recursion = ini_get('pcre.recursion_limit');
        ini_set('pcre.backtrack_limit', (string) self::MAX_REGEX_STEPS);
        ini_set('pcre.recursion_limit', (string) self::MAX_REGEX_STEPS);

        try {
            $result = @preg_match(self::compile($pattern), $subject);
        } finally {
            ini_set('pcre.backtrack_limit', $backtrack === false ? '1000000' : $backtrack);
            ini_set('pcre.recursion_limit', $recursion === false ? '100000' : $recursion);
        }

        if ($result === false) {
            return preg_last_error() === PREG_BACKTRACK_LIMIT_ERROR
                ? Value::error(ErrorCode::RegexSteps, 'MATCHES exceeded ' . self::MAX_REGEX_STEPS . ' regex steps')
                : Value::error(ErrorCode::Arg, 'MATCHES failed: ' . preg_last_error_msg());
        }

        return Value::boolean($result === 1);
    }

    /**
     * The compiler anchors the pattern at both ends, so a rule cannot
     * accidentally match a substring (§10.3), and picks a delimiter the pattern
     * does not contain.
     */
    public static function compile(string $pattern): string
    {
        foreach (['~', '#', '%', '!', '@'] as $delimiter) {
            if (!str_contains($pattern, $delimiter)) {
                return $delimiter . '\A(?:' . $pattern . ')\z' . $delimiter . 'u';
            }
        }

        // Every candidate appears in the pattern; escape one and use it.
        return '~\A(?:' . str_replace('~', '\~', $pattern) . ')\z~u';
    }

    /** The offending quantified group, or null. */
    private static function findNestedQuantifier(string $pattern): ?string
    {
        $length = strlen($pattern);
        $stack = [];
        $inClass = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === '\\') {
                $i++;

                continue;
            }

            if ($inClass) {
                $inClass = $char !== ']';

                continue;
            }

            if ($char === '[') {
                $inClass = true;

                continue;
            }

            if ($char === '(') {
                $stack[] = $i;

                continue;
            }

            if ($char !== ')' || $stack === []) {
                continue;
            }

            $open = (int) array_pop($stack);
            $body = substr($pattern, $open + 1, $i - $open - 1);
            $after = $pattern[$i + 1] ?? '';

            if (!self::isUnboundedQuantifier($pattern, $i + 1)) {
                continue;
            }

            if (preg_match('/[*+]|\{\d+,\}|\|/', $body) === 1) {
                return substr($pattern, $open, $i - $open + 2) . ($after === '{' ? '…' : '');
            }
        }

        return null;
    }

    private static function isUnboundedQuantifier(string $pattern, int $at): bool
    {
        $char = $pattern[$at] ?? '';

        if ($char === '*' || $char === '+') {
            return true;
        }

        return $char === '{' && preg_match('/^\{\d+,\}/', substr($pattern, $at)) === 1;
    }
}
