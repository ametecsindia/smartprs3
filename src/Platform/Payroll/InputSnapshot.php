<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Payroll;

use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;

/**
 * WHAT THE RUN WAS COMPUTED FROM — Part XIV §XIV.6, R-XIV-072.
 *
 * The fourth Phase 5 exit criterion is two statements, and the second is the one
 * that is usually missing: a re-run reproduces the original result exactly, AND
 * it reports any changed input. A re-run that quietly produces a different
 * number because somebody edited a salary yesterday is not reproducibility, it is
 * a second opinion nobody asked for.
 *
 * So every input a payslip depends on is captured here, by value, at the moment
 * the run was computed: the contracted gross, the payable and period days, the
 * recovery instalments, and the VERSION of every component that took part. The
 * version matters most — SmartPRS 2 recomputes an old month against the
 * component definitions as they stand today, so re-opening March in June silently
 * reprices March.
 *
 * A snapshot is not a cache of the answer. It is a record of the question.
 */
final readonly class InputSnapshot
{
    /**
     * @param array<string, string> $componentVersions component code => version id
     * @param array<string, Money>  $recoveries        component code => instalment taken
     */
    public function __construct(
        public string $employmentId,
        public string $periodCode,
        public Money $contractedGross,
        public Rational $payableDays,
        public int $periodDays,
        public array $componentVersions,
        public array $recoveries = [],
    ) {
    }

    /**
     * @param list<SalaryComponent> $components
     * @param array<string, Money>  $recoveries
     */
    public static function of(
        string $employmentId,
        string $periodCode,
        Money $contractedGross,
        Rational $payableDays,
        int $periodDays,
        array $components,
        array $recoveries = [],
    ): self {
        $versions = [];

        foreach ($components as $component) {
            $versions[$component->code] = $component->code . '-v1';
        }

        ksort($versions);

        return new self(
            $employmentId,
            $periodCode,
            $contractedGross,
            $payableDays,
            $periodDays,
            $versions,
            $recoveries,
        );
    }

    /** A stable identity for the whole input set. Equal fingerprints mean equal questions. */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->canonical(), JSON_THROW_ON_ERROR));
    }

    /**
     * What CHANGED between the inputs of two runs, in words.
     *
     * Named rather than counted: "the run differs" tells a payroll officer to go
     * looking, and looking is the part that takes a day.
     *
     * @return list<string> empty means the two runs asked the same question
     */
    public function diff(self $other): array
    {
        $changes = [];
        $mine = $this->canonical();
        $theirs = $other->canonical();

        foreach ($mine as $key => $value) {
            if (!array_key_exists($key, $theirs)) {
                $changes[] = "{$key} was {$value} and is no longer an input";

                continue;
            }

            if ($theirs[$key] !== $value) {
                $changes[] = "{$key} changed from {$value} to {$theirs[$key]}";
            }
        }

        foreach ($theirs as $key => $value) {
            if (!array_key_exists($key, $mine)) {
                $changes[] = "{$key} is a new input, now {$value}";
            }
        }

        return $changes;
    }

    /** @return array<string, string> every input flattened to a comparable, printable value */
    private function canonical(): array
    {
        $flat = [
            'employment' => $this->employmentId,
            'period' => $this->periodCode,
            'contracted_gross' => (string) $this->contractedGross->minor,
            'payable_days' => (string) $this->payableDays,
            'period_days' => (string) $this->periodDays,
        ];

        foreach ($this->componentVersions as $code => $version) {
            $flat["component[{$code}]"] = $version;
        }

        $recoveries = $this->recoveries;
        ksort($recoveries);

        foreach ($recoveries as $code => $amount) {
            $flat["recovery[{$code}]"] = (string) $amount->minor;
        }

        return $flat;
    }
}
