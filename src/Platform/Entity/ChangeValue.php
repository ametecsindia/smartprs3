<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

/**
 * PATCH SEMANTICS, AS A TYPE — Part X §X.5.1, R-X-036.
 *
 * "Only fields present in `changes` are affected. A field absent from `changes`
 * is untouched. Clearing a field is the EXPLICIT `Clear()` operation."
 *
 * Three states and not two, because `null` cannot carry the difference between
 * "I am not changing this" and "I am emptying this", and every system that tries
 * to make it carry both eventually empties something. SmartPRS 2's employee save
 * is a full-record replace that NULLs every omitted key — behaviour the spine
 * calls "catastrophic behind a public API", and it is already catastrophic
 * behind a form: a save from a screen that shows twelve of forty fields wipes the
 * other twenty-eight.
 *
 * `Clear` is refused when the field is required on the resolving surface. That
 * refusal is in EntityWriteService, not here: this type carries the intent, and
 * whether the intent is permitted depends on the surface and the actor.
 */
final readonly class ChangeValue
{
    private function __construct(
        public string $kind,
        public mixed $value = null,
    ) {
    }

    public static function set(mixed $value): self
    {
        return new self('set', $value);
    }

    public static function clear(): self
    {
        return new self('clear');
    }

    /**
     * Present in the command and explicitly not a change.
     *
     * Useful because an import row and an API PATCH both want to say "I read
     * this field and it is the same", which is a different fact from having
     * omitted it — the first can be reported back as `unchanged`, the second
     * cannot be reported at all.
     */
    public static function unchanged(): self
    {
        return new self('unchanged');
    }

    public function isSet(): bool
    {
        return $this->kind === 'set';
    }

    public function isClear(): bool
    {
        return $this->kind === 'clear';
    }

    public function isUnchanged(): bool
    {
        return $this->kind === 'unchanged';
    }

    public function describe(): string
    {
        return match ($this->kind) {
            'set' => 'set to ' . self::render($this->value),
            'clear' => 'cleared',
            default => 'unchanged',
        };
    }

    private static function render(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            is_array($value) => '[' . implode(', ', array_map(self::render(...), $value)) . ']',
            default => get_debug_type($value),
        };
    }
}
