<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Formula;

/**
 * The twelve gates' outcomes for one publication attempt (Part VIII §9, §14.5).
 *
 * R-VIII-013 asks for this to be STORED, not just returned: "so a blocked
 * publication is explainable and a passed one is evidenced". Three years later,
 * the question about a payslip is not only "what did this formula compute" but
 * "what was checked before it was allowed to compute anything", and an answer
 * of "the usual checks" is not an answer.
 */
final readonly class PublishReport
{
    /** @param list<PublishCheck> $checks */
    public function __construct(
        public Formula $formula,
        public array $checks,
    ) {
    }

    /** No gate blocked. Warns and lints do not stop a publication (§9). */
    public function isPublishable(): bool
    {
        return $this->blocking() === [];
    }

    /** @return list<PublishCheck> */
    public function blocking(): array
    {
        return array_values(array_filter($this->checks, static fn (PublishCheck $c): bool => $c->blocks()));
    }

    /** @return list<PublishCheck> */
    public function ofSeverity(string $severity): array
    {
        return array_values(array_filter(
            $this->checks,
            static fn (PublishCheck $c): bool => $c->severity === $severity,
        ));
    }

    public function check(string $gate): ?PublishCheck
    {
        foreach ($this->checks as $check) {
            if ($check->gate === $gate) {
                return $check;
            }
        }

        return null;
    }

    /** @return array<string, string> gate => severity, for the stored row */
    public function outcomes(): array
    {
        $outcomes = [];

        foreach ($this->checks as $check) {
            $outcomes[$check->gate] = $check->severity;
        }

        return $outcomes;
    }

    public function render(): string
    {
        $verdict = $this->isPublishable() ? 'PUBLISHABLE' : 'BLOCKED';
        $lines = [
            "{$verdict}  {$this->formula->describe()}",
            '',
        ];

        foreach ($this->checks as $check) {
            $lines[] = $check->render();
        }

        $lines[] = '';
        $lines[] = sprintf(
            '%d checks: %d pass, %d warn, %d lint, %d block',
            count($this->checks),
            count($this->ofSeverity('pass')),
            count($this->ofSeverity('warn')),
            count($this->ofSeverity('lint')),
            count($this->ofSeverity('block')),
        );

        return implode("\n", $lines);
    }

    public function __toString(): string
    {
        return $this->render();
    }
}
