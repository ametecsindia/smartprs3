<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

/**
 * One expression's result, with everything the trace needs to show its work.
 *
 * Part VII §5.8: an SPX error is a typed *value* that propagates, not a thrown
 * exception. It reaches the engine here, in `errorCode`, and the engine turns it
 * into a named evaluation failure (R-VII-041). A `when` clause that errors is
 * never read as false.
 *
 * `value === null` with no errorCode is SPX's `unknown`, which is a third
 * boolean answer and not the same as false — §7.5 depends on the difference.
 */
final readonly class Evaluated
{
    /** @param array<string, mixed> $inputs symbol => substituted value, leaves only */
    public function __construct(
        public mixed $value,
        public string $substituted,
        public array $inputs = [],
        public ?string $errorCode = null,
        public ?string $nodePath = null,
    ) {
    }

    /** @param array<string, mixed> $inputs */
    public static function error(
        string $code,
        string $substituted,
        string $nodePath = '',
        array $inputs = [],
    ): self {
        return new self(null, $substituted, $inputs, $code, $nodePath);
    }

    public function isError(): bool
    {
        return $this->errorCode !== null;
    }

    public function isTrue(): bool
    {
        return $this->errorCode === null && $this->value === true;
    }

    /** SPX `unknown`: neither true, nor false, nor an error. */
    public function isUnknown(): bool
    {
        return $this->errorCode === null && $this->value === null;
    }

    /** "TRUE" / "FALSE" / "unknown" / "ERR_DIV_ZERO", for the rendered trace. */
    public function verdict(): string
    {
        return match (true) {
            $this->errorCode !== null => $this->errorCode,
            $this->value === true => 'TRUE',
            $this->value === false => 'FALSE',
            $this->value === null => 'unknown',
            default => Values::render($this->value),
        };
    }
}
