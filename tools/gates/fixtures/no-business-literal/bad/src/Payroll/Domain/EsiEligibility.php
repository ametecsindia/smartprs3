<?php
declare(strict_types=1);

namespace SmartPRS\Payroll\Domain;

/** VIOLATING FIXTURE — the SmartPRS 2 pattern, verbatim in shape. */
final class EsiEligibility
{
    private const CEILING = 21000;              // numeric literal in an assignment

    public function applies(string $component, int $gross): bool
    {
        if ($component === 'BASIC') {            // string literal in a comparison
            return true;
        }
        $rate = 0.75;                            // numeric literal in an assignment
        return $gross <= 21000 && $rate > 0.5;   // numeric literals in comparisons
    }

    public function label(): string
    {
        return 'Employee State Insurance';       // string literal in a return
    }
}
