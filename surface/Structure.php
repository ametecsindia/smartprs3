<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Payroll\PayslipCalculator;
use SmartPRS\Platform\Payroll\SalaryComponent;

/**
 * THE SALARY STRUCTURE IS CONFIGURATION — the governing sentence of this rebuild,
 * made literal: "CHANGE CONFIGURATION, NOT CODE."
 *
 * Until this class existed, `structure()` in the front controller returned four
 * hand-written `new SalaryComponent(...)` calls. That is the first law broken in
 * the most ordinary way there is: the percentage in HRA is a business decision,
 * and a business decision inside a PHP file is a release every time it changes.
 *
 * NOTHING IS OVERWRITTEN. A structure is a LIST of effective-dated versions, and
 * publishing a new one appends. Payroll for March asks for the structure as it
 * stood in March and gets it, which is the only way a reprint of an old payslip
 * can agree with the original. A structure edited in place makes every historic
 * payslip unreproducible, silently, and the discovery is always a dispute.
 *
 * A DATE WITH NO VERSION IS A REFUSAL, not an empty structure. An empty
 * structure computes a payslip of zero lines and a net pay of the full gross,
 * which is a wrong number produced confidently — the one outcome worth more
 * engineering than a crash.
 *
 * ponytail: versions live in one JSON file plus the store; no signing, no
 * approval workflow. Add those when a second person can publish.
 */
final class Structure
{
    public function __construct(
        private readonly RowStore $store,
        private readonly string $shippedFile,
    ) {
    }

    /**
     * Every version, oldest first, shipped and published together.
     *
     * @return list<array<string, mixed>>
     */
    public function versions(): array
    {
        $shipped = [];

        if (is_file($this->shippedFile)) {
            $decoded = json_decode((string) file_get_contents($this->shippedFile), true);

            if (!is_array($decoded)) {
                throw new \RuntimeException(
                    'config/salary-structure.json is not readable as JSON. A structure that cannot be '
                    . 'parsed is refused here rather than treated as an empty structure, which would pay '
                    . 'every employee their full gross with no deductions.'
                );
            }

            foreach ($decoded as $version) {
                $version['source'] = 'shipped';
                $shipped[] = $version;
            }
        }

        foreach ($this->store->all('structures') as $version) {
            $version['source'] = 'published';
            $shipped[] = $version;
        }

        usort($shipped, static fn (array $a, array $b): int
            => strcmp((string) $a['effective_from'], (string) $b['effective_from']));

        return $shipped;
    }

    /**
     * The version in force on a date.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException when no version covers the date
     */
    public function versionOn(string $date): array
    {
        $inForce = null;

        foreach ($this->versions() as $version) {
            if (strcmp((string) $version['effective_from'], $date) <= 0) {
                $inForce = $version;
            }
        }

        if ($inForce === null) {
            throw new \RuntimeException(
                "No salary structure is effective on {$date}. The run is refused rather than computed "
                . 'against an empty structure — an empty structure produces a payslip with no deductions '
                . 'and a net pay equal to the gross, which is a wrong number stated confidently.'
            );
        }

        return $inForce;
    }

    /**
     * IS THE CONTRACTED FIGURE A YEAR OR A MONTH?
     *
     * Declared on the structure version, never assumed. `employees.ctc` is a single
     * number read by payroll every month, and until this was here nothing in the
     * product said which it was: the column held annual figures and the register
     * used them as the month's gross, so every payslip was twelve times too high
     * beneath a per-line derivation that was arithmetically perfect. A wrong number
     * with correct working under it is worse than a wrong number, because the
     * working is what persuades somebody to trust the input.
     *
     * ANYTHING THIS BUILD DOES NOT UNDERSTAND IS REFUSED, not defaulted. A basis of
     * "yearly" or "per annum" or a typo would otherwise silently mean monthly and
     * hand back a figure twelve times out — which is the exact defect this method
     * exists to close, reintroduced through the spelling of a config value.
     */
    public function contractedCostBasis(string $date): string
    {
        $basis = (string) ($this->versionOn($date)['contracted_cost_basis'] ?? 'annual');

        if (!in_array($basis, ['annual', 'monthly'], true)) {
            throw new \RuntimeException(sprintf(
                'The salary structure effective on %s declares contracted_cost_basis "%s", which '
                . 'this build does not understand. It must be "annual" or "monthly". The run is '
                . 'refused rather than guessed: guessing wrong here multiplies or divides every '
                . 'payslip in the month by twelve, and the payslip would show its working and look '
                . 'right.', $date, $basis));
        }

        return $basis;
    }

    /**
     * The version in force on a date, as engine components.
     *
     * @return list<SalaryComponent>
     */
    public function on(string $date): array
    {
        return self::components($this->versionOn($date));
    }

    /**
     * @param array<string, mixed> $version
     * @return list<SalaryComponent>
     */
    public static function components(array $version): array
    {
        $components = [];

        foreach ((array) ($version['components'] ?? []) as $row) {
            // The engine validates kind, basis, and the combinations that make no
            // sense. Repeating those checks here would be a second opinion that
            // can drift from the first, so the exception is allowed through.
            $components[] = new SalaryComponent(
                code: (string) $row['code'],
                kind: (string) $row['kind'],
                basis: (string) $row['basis'],
                flags: (array) ($row['flags'] ?? []),
                ofComponent: isset($row['of_component']) ? (string) $row['of_component'] : null,
                ratePercent: isset($row['rate_percent']) ? (int) $row['rate_percent'] : null,
                flatAmount: isset($row['flat_amount']) ? (string) $row['flat_amount'] : null,
                sequence: (int) ($row['sequence'] ?? 0),
                // The formula basis's declaration, passed through as written.
                // The engine parses it, type-checks it against the two
                // namespaces a payslip line may read, and refuses the structure
                // if it cannot — here, at publish, with a person present.
                declaredFormula: (array) ($row['formula'] ?? []),
            );
        }

        return $components;
    }

    /**
     * Publish a new effective-dated version. Appends; never replaces.
     *
     * @return array{ok: bool, why: string, version?: array<string, mixed>}
     */
    public function publish(string $effectiveFrom, string $json, string $note): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom) !== 1) {
            return ['ok' => false, 'why' => "'{$effectiveFrom}' is not a date in YYYY-MM-DD form."];
        }

        foreach ($this->versions() as $existing) {
            if ((string) $existing['effective_from'] === $effectiveFrom) {
                return ['ok' => false, 'why' => "A version is already effective from {$effectiveFrom}. "
                    . 'Two structures in force on the same day is an ambiguity payroll cannot resolve, '
                    . 'so the second one is refused rather than allowed to win by insertion order.'];
            }
        }

        $components = json_decode($json, true);

        if (!is_array($components) || $components === []) {
            return ['ok' => false, 'why' => 'The components must be a non-empty JSON array.'];
        }

        $version = ['effective_from' => $effectiveFrom, 'note' => $note, 'components' => $components];

        try {
            // Built here and thrown away — the point is that a structure which the
            // engine would refuse at run time is refused at PUBLISH time instead,
            // when a person is present to read the reason.
            //
            // The CALCULATOR is built too, and not only the components: an
            // expression that does not parse is caught by the component, but a
            // component that reads one that does not exist, and a set of
            // components that depend on each other in a circle, are properties of
            // the whole structure and are only visible to the graph. A cycle
            // discovered on a close is discovered at 3am; discovered here it is a
            // sentence naming both components and the reference to change.
            new PayslipCalculator(self::components($version));
        } catch (\Throwable $e) {
            return ['ok' => false, 'why' => 'The engine refused this structure: ' . $e->getMessage()];
        }

        $this->store->put('structures', $version);

        return ['ok' => true, 'why' => "Published, effective from {$effectiveFrom}. Nothing was "
            . 'overwritten: runs before that date still use the version that was in force then.',
            'version' => $version];
    }
}
