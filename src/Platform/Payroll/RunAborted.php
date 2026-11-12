<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Payroll;

/**
 * THE RUN STOPPED — Part XIV §XIV.5.6, R-XIV-068.
 *
 * A reconciliation failure is not a warning, a status column or a report
 * somebody reads afterwards. It is this: an exception that ends the run before a
 * single payslip is issued, carrying the check, the employment and the
 * difference to the paise.
 *
 * The failures travel WITH the exception rather than being logged and discarded,
 * because "the payroll failed" is not an actionable sentence. "RC-03 on E-1042:
 * expected ₹45,633.33, got ₹45,633.32, a difference of −₹0.01" is.
 */
final class RunAborted extends \RuntimeException
{
    /**
     * @param list<array{check: string, employment: ?string, expected: string, actual: string, difference: string}> $failures
     */
    public function __construct(public readonly array $failures)
    {
        parent::__construct(
            'The payroll run was ABORTED by the reconciliation assertion — '
            . count($failures) . " check(s) failed and no payslip is issued:\n" . self::render($failures)
        );
    }

    /** @return list<string> the checks that failed, deduplicated, in the order first seen */
    public function checks(): array
    {
        $seen = [];

        foreach ($this->failures as $failure) {
            $seen[$failure['check']] = true;
        }

        return array_keys($seen);
    }

    public function failed(string $check): bool
    {
        return in_array($check, $this->checks(), true);
    }

    /** @param list<array{check: string, employment: ?string, expected: string, actual: string, difference: string}> $failures */
    private static function render(array $failures): string
    {
        return implode("\n", array_map(
            static fn (array $f): string => sprintf(
                '  %s on %s: expected %s, got %s (difference %s)',
                $f['check'],
                $f['employment'] ?? 'the run',
                $f['expected'],
                $f['actual'],
                $f['difference'],
            ),
            $failures,
        ));
    }
}
