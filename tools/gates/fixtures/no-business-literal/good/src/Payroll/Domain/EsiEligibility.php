<?php
declare(strict_types=1);

namespace SmartPRS\Payroll\Domain;

use SmartPRS\Payroll\Domain\Exception\UnknownComponentException;

/** CLEAN FIXTURE — every literal is structural, identity, or allow-listed. */
enum Cadence: string
{
    case Monthly = 'monthly';                   // enum case value: allowed
    case Weekly = 'weekly';
}

final class EsiEligibility
{
    /** @var array<string,int> */
    private array $limits = ['ceiling' => 0];   // array key: allowed

    public function __construct(private readonly ComponentRegistry $registry)
    {
    }

    public function applies(string $component, int $gross): bool
    {
        if (!$this->registry->has($component)) {
            throw new UnknownComponentException('no such component: ' . $component);
        }
        $ceiling = $this->limits['ceiling'];     // index subscript: allowed
        if ($ceiling === 0) {                    // 0: allowed
            return false;
        }
        return $gross <= $ceiling;
    }

    public function currency(): string
    {
        return 'INR';                            // allow-listed: reported as a suppression
    }

    public function direction(): int
    {
        return -1;                               // -1: allowed
    }
}
