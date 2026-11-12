<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Statutory;

/**
 * ONE APPLICABILITY ANSWER, WITH ITS REASON — Part XVII §XVII.5.4.
 *
 * `undetermined` is a third state on purpose. "Does not apply" is a decision
 * somebody made and can defend; "not determined" is a question nobody asked.
 * Collapsing the two gives an establishment with no PT registration recorded the
 * same clean payslip as one lawfully outside the statute, and only one of those
 * is a finding at an inspection.
 */
final readonly class Binding
{
    public function __construct(
        public string $head,
        public string $establishmentCode,
        public string $packKey,
        public bool $applies,
        public string $reason,
        public string $evidence,
        public bool $undetermined = false,
    ) {
    }

    public function describe(): string
    {
        return match (true) {
            $this->undetermined => "{$this->head} is NOT DETERMINED for {$this->establishmentCode} — nobody "
                . 'has decided whether it applies, which is not the same as deciding that it does not',
            $this->applies => "{$this->head} applies to {$this->establishmentCode}: {$this->reason} "
                . "(evidence: {$this->evidence})",
            default => "{$this->head} does not apply to {$this->establishmentCode}: {$this->reason}",
        };
    }
}
