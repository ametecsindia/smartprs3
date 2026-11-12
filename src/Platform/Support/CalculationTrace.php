<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Support;

/**
 * Spine Law 4 — EVERY NUMBER EXPLAINS ITSELF (V.5.8).
 *
 * A tree of steps. Each node names what was produced, the inputs it consumed,
 * the artefact and version that produced it, and the result. Engines return one
 * alongside every derived value; Explain(entity, field) renders it (spine S15).
 *
 * ponytail: V.5.8 also lists overrides, downstream effects and produced_at.
 * Those are added when the module that owns them exists — an override list with
 * no override mechanism to fill it is a field that is always empty.
 */
final readonly class CalculationTrace
{
    /**
     * @param  array<string, mixed>  $inputs  name => value (Money, Rational, scalar)
     * @param  list<self>  $children
     */
    public function __construct(
        public string $label,
        public mixed $result,
        public array $inputs = [],
        public ?string $artefact = null,
        public ?string $version = null,
        public ?string $expression = null,
        public ?string $substituted = null,
        public ?string $rounding = null,
        public array $children = [],
    ) {
    }

    public function withChild(self $child): self
    {
        return new self(
            $this->label,
            $this->result,
            $this->inputs,
            $this->artefact,
            $this->version,
            $this->expression,
            $this->substituted,
            $this->rounding,
            [...$this->children, $child],
        );
    }

    /**
     * The S7 worked-example shape:
     *
     *   HRA  = ₹6,000.00
     *        = BASIC % 40
     *        = ₹15,000.00 × 40%
     *        formula:  HRA v3
     *        inputs:   BASIC = ₹15,000.00
     *        rounding: 2dp, half-up
     */
    public function render(int $indent = 0): string
    {
        $pad = str_repeat('    ', $indent);
        $continuation = $pad . str_repeat(' ', max(strlen($this->label), 4)) . ' ';

        $lines = [$pad . $this->label . ' = ' . self::value($this->result)];

        foreach ([$this->expression, $this->substituted] as $line) {
            if ($line !== null) {
                $lines[] = $continuation . '= ' . $line;
            }
        }

        if ($this->artefact !== null) {
            $lines[] = $continuation . 'source:   ' . $this->artefact
                . ($this->version !== null ? ' ' . $this->version : '');
        }

        if ($this->inputs !== []) {
            $rendered = [];

            foreach ($this->inputs as $name => $value) {
                $rendered[] = $name . ' = ' . self::value($value);
            }

            $lines[] = $continuation . 'inputs:   ' . implode(', ', $rendered);
        }

        if ($this->rounding !== null) {
            $lines[] = $continuation . 'rounding: ' . $this->rounding;
        }

        foreach ($this->children as $child) {
            $lines[] = $child->render($indent + 1);
        }

        return implode("\n", $lines);
    }

    public function toArray(): array
    {
        return array_filter([
            'label' => $this->label,
            'result' => self::value($this->result),
            'inputs' => array_map(self::value(...), $this->inputs),
            'artefact' => $this->artefact,
            'version' => $this->version,
            'expression' => $this->expression,
            'substituted' => $this->substituted,
            'rounding' => $this->rounding,
            'children' => array_map(static fn (self $c): array => $c->toArray(), $this->children),
        ], static fn (mixed $v): bool => $v !== null && $v !== []);
    }

    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    public function __toString(): string
    {
        return $this->render();
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            $value instanceof Money => $value->format(),
            $value instanceof \DateTimeInterface => $value->format('d-M-Y'),
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_array($value) => implode(', ', array_map(self::value(...), $value)),
            default => (string) $value,
        };
    }
}
