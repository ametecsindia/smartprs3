<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

/**
 * The interval and set solver of Part VII §11.4, over the decidable fragment:
 * numeric and temporal comparisons, BETWEEN, IN, and equality on Code and
 * Boolean, combined with AND.
 *
 * Given the conjunction of two rules' claims it returns either a WITNESS — a
 * concrete assignment satisfying both, which is what the publish-time refusal
 * shows the administrator — or NULL, meaning provably disjoint.
 *
 * Anything the fragment does not cover never reaches here: `Expression::claims()`
 * answers NULL and the detector treats the pair as conflicting. That asymmetry is
 * R-VII-060 and it is the whole safety argument. Full satisfiability is
 * undecidable; this solver is deliberately small and deliberately honest about
 * where it stops.
 *
 * ponytail: integers only, one interval and two sets per symbol. Minutes, days,
 * counts and money-in-paise are all integers (Law 7), and a date is its ordinal
 * day. No rationals, no cross-symbol arithmetic, no disjunction — a rule needing
 * any of those answers NULL from claims() and is treated as conflicting, which
 * is the safe direction.
 */
final class Satisfiability
{
    /**
     * @param  list<Claim>  $claims  the conjunction
     * @return array<string, int|string>|null  a satisfying assignment, or null if
     *         the conjunction is provably unsatisfiable
     */
    public static function witness(array $claims): ?array
    {
        /** @var array<string, array{lo: int, hi: int, allow: ?list<string>, deny: list<string>, kind: ?string}> $vars */
        $vars = [];

        foreach ($claims as $claim) {
            $symbol = $claim->symbol;
            $vars[$symbol] ??= ['lo' => PHP_INT_MIN, 'hi' => PHP_INT_MAX, 'allow' => null, 'deny' => [], 'kind' => null];
            $kind = $claim->isNumeric() ? 'numeric' : 'set';

            // A symbol constrained numerically by one rule and as a code by the
            // other is not something this solver models. Treating it as
            // satisfiable keeps the fail-safe direction (R-VII-060).
            if ($vars[$symbol]['kind'] !== null && $vars[$symbol]['kind'] !== $kind) {
                return self::assign($vars);
            }

            $vars[$symbol]['kind'] = $kind;

            if ($claim->isNumeric()) {
                /** @var int $value */
                $value = $claim->value;

                match ($claim->operator) {
                    '>' => $vars[$symbol]['lo'] = max($vars[$symbol]['lo'], $value + 1),
                    '>=' => $vars[$symbol]['lo'] = max($vars[$symbol]['lo'], $value),
                    '<' => $vars[$symbol]['hi'] = min($vars[$symbol]['hi'], $value - 1),
                    '<=' => $vars[$symbol]['hi'] = min($vars[$symbol]['hi'], $value),
                    '=' => [
                        $vars[$symbol]['lo'] = max($vars[$symbol]['lo'], $value),
                        $vars[$symbol]['hi'] = min($vars[$symbol]['hi'], $value),
                    ],
                    // `<>` on an unbounded integer never makes a conjunction
                    // unsatisfiable on its own, and modelling the hole would buy
                    // nothing: the answer stays "satisfiable", which is safe.
                    default => null,
                };

                if ($vars[$symbol]['lo'] > $vars[$symbol]['hi']) {
                    return null;
                }

                continue;
            }

            if ($claim->operator === 'in' || $claim->operator === '=') {
                $members = is_array($claim->value) ? $claim->value : [(string) $claim->value];
                $vars[$symbol]['allow'] = $vars[$symbol]['allow'] === null
                    ? array_values($members)
                    : array_values(array_intersect($vars[$symbol]['allow'], $members));
            } else {
                $members = is_array($claim->value) ? $claim->value : [(string) $claim->value];
                $vars[$symbol]['deny'] = array_values(array_unique([...$vars[$symbol]['deny'], ...$members]));
            }

            if ($vars[$symbol]['allow'] !== null
                && array_diff($vars[$symbol]['allow'], $vars[$symbol]['deny']) === []) {
                return null;
            }
        }

        return self::assign($vars);
    }

    /**
     * @param  array<string, array{lo: int, hi: int, allow: ?list<string>, deny: list<string>, kind: ?string}>  $vars
     * @return array<string, int|string>
     */
    private static function assign(array $vars): array
    {
        $witness = [];
        ksort($vars);

        foreach ($vars as $symbol => $var) {
            if ($var['kind'] === 'set') {
                $remaining = $var['allow'] === null
                    ? ['(any value not in [' . implode(', ', $var['deny']) . '])']
                    : array_values(array_diff($var['allow'], $var['deny']));
                $witness[$symbol] = (string) ($remaining[0] ?? '');

                continue;
            }

            // Prefer the tight bound, so the witness reads like the §8.2
            // Example 5 message: "late_minutes = 31m (satisfies both)".
            $witness[$symbol] = match (true) {
                $var['lo'] !== PHP_INT_MIN => $var['lo'],
                $var['hi'] !== PHP_INT_MAX => $var['hi'],
                default => 0,
            };
        }

        return $witness;
    }
}
