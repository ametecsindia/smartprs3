<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

/**
 * One atomic constraint inside the conflict detector's decidable fragment
 * (Part VII §11.4): a numeric or temporal comparison, a `BETWEEN`, an `IN`, or
 * equality on a `Code` or a `Boolean`.
 *
 * An Expression that can reduce itself to a conjunction of these is decidable;
 * anything else answers NULL and is treated as conflicting. That asymmetry is
 * deliberate — R-VII-060 forbids a false negative, and permits a false positive
 * because a false positive costs one `priority` keystroke.
 */
final readonly class Claim
{
    public const NUMERIC = ['>', '>=', '<', '<=', '=', '<>'];
    public const SET = ['in', 'not_in'];

    /** @param int|string|list<string> $value */
    public function __construct(
        public string $symbol,
        public string $operator,
        public int|string|array $value,
    ) {
        if (!in_array($operator, [...self::NUMERIC, ...self::SET], true)) {
            throw new \InvalidArgumentException("Unknown claim operator '{$operator}'.");
        }

        if (in_array($operator, self::SET, true) && !is_array($value)) {
            throw new \InvalidArgumentException("Operator '{$operator}' takes a list of members.");
        }

        if (is_array($value) && in_array($operator, self::NUMERIC, true)) {
            throw new \InvalidArgumentException("Operator '{$operator}' takes a scalar.");
        }
    }

    public static function between(string $symbol, int $lowInclusive, int $highInclusive): array
    {
        return [
            new self($symbol, '>=', $lowInclusive),
            new self($symbol, '<=', $highInclusive),
        ];
    }

    public function isNumeric(): bool
    {
        return is_int($this->value);
    }

    public function __toString(): string
    {
        return $this->symbol . ' ' . $this->operator . ' ' . Values::render($this->value);
    }
}
