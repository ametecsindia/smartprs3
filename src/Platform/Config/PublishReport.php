<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Config;

/**
 * The gates' outcomes for one publication attempt of one configuration artefact
 * (Part VIII §9 and §14.5 for a formula; Part IX §IX.3.6 for a workflow).
 *
 * R-VIII-013 asks for this to be STORED, not just returned: "so a blocked
 * publication is explainable and a passed one is evidenced". Three years later,
 * the question about a payslip is not only "what did this formula compute" but
 * "what was checked before it was allowed to compute anything", and an answer
 * of "the usual checks" is not an answer.
 */
final readonly class PublishReport
{
    /**
     * @param string $subject  what was checked, as the report should name it —
     *                         "F-HRA-PCT-BASIC v3 · comp.earning · Money(INR)"
     * @param list<PublishCheck> $checks
     */
    public function __construct(
        public string $subject,
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
            "{$verdict}  {$this->subject}",
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
