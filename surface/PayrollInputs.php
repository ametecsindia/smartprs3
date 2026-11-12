<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\RoundingMode;

/**
 * EVERY OTHER EARNING AND DEDUCTION, INTO THE CLOSE (owner, 7 Oct).
 *
 * Approved incentives / commissions (gross as INCENTIVE plus the TDS already
 * computed on them), bonus and encashment, approved overtime, approved expense
 * reimbursements and manual deductions — each read from the register people
 * already fill in, by the rules in config/payroll-inputs.json. Before this they
 * sat on their own screens and no payslip ever carried one.
 *
 * READ, NEVER MARKED: the same month closed twice reads the same entries, so a
 * regenerated run is identical. An entry for a month that is already closed
 * is not paid by a later month — it belongs to its own month's correction.
 */
final class PayrollInputs
{
    /** @var array<string, mixed> */
    private array $config;

    /** @var array<string, list<array<string, mixed>>> table => rows */
    private array $rows = [];

    private ?TaxDeclarations $tax = null;

    public function __construct(private readonly RowStore $store, string $configPath)
    {
        $this->config = (array) json_decode((string) @file_get_contents($configPath), true);
        $this->commissionRules = (array) (((array) json_decode((string) @file_get_contents(dirname($configPath) . '/compensation.json'), true))['commission'] ?? []);
    }

    /** @var array<string, bool> payroll months whose Fixed Commission entries were already raised in this run */
    private array $fixedDone = [];

    /** @var array<string, mixed> config/compensation.json `commission` — which Salary Types take flat TDS (29 Oct). */
    private array $commissionRules = [];

    /** TDS ON SALARY (14 Oct): the month's TDS from the employee's declaration joins the close. */
    public function withTax(TaxDeclarations $tax): self
    {
        $this->tax = $tax;

        return $this;
    }

    /**
     * @param array<string, mixed> $employee
     * @return array{lines: list<array<string, mixed>>, concerns: list<string>}
     */
    public function forEmployment(array $employee, string $period, string $from, string $to, array $base = []): array
    {
        $id = (string) ($employee['id'] ?? '');
        $lines = [];
        $concerns = [];
        // FLAT 194H OR SLABS (owner, 29 Oct 2026): Salary Type "Commission" → the entry's flat TDS, no slab TDS;
        // Salary / Salary & Commission → no flat TDS on the entry, the commission is taxed on the slabs below.
        $flatTds = Compensation::flatTds($employee, $this->commissionRules);

        if ($id === '') {
            return ['lines' => [], 'concerns' => []];
        }

        // FIXED COMMISSION, PAID BY ITSELF (owner, 3 Nov 2026): before the month's commissions are read, every Active
        // Fixed Commission scheme raises its entry for each covered employee (once — a regenerated month adds nothing).
        if (!isset($this->fixedDone[$period])) {
            $this->fixedDone[$period] = true;
            try {
                Compensation::ensureFixed($this->store, $period, $this->commissionRules) > 0 && $this->rows = array_diff_key($this->rows, ['commissions' => 1]);
            } catch (\Throwable $e) {
                $concerns[] = 'Fixed Commission entries for ' . $period . ' not raised: ' . $e->getMessage();
            }
        }

        foreach ((array) ($this->config['sources'] ?? []) as $src) {
            $statuses = array_map('strtolower', (array) ($src['statuses'] ?? []));

            foreach ($this->rowsOf((string) $src['table']) as $r) {
                // A row with NO employee is for EVERYONE in the run, when the source
                // says so (a festival bonus for all, 7 Oct).
                $forAll = trim((string) ($r['employee_id'] ?? '')) === '' && (bool) ($src['all_when_blank'] ?? false);

                if ((!$forAll && (string) ($r['employee_id'] ?? '') !== $id)
                    || !in_array(strtolower(trim((string) ($r['status'] ?? ''))), $statuses, true)) {
                    continue;
                }

                if (isset($src['month'])) {
                    if (substr(trim((string) ($r[$src['month']] ?? '')), 0, 7) !== $period) {
                        continue;
                    }
                } else {
                    $on = substr((string) ($r[$src['date']] ?? ''), 0, 10);

                    if ($on === '' || $on < $from || $on > $to) {
                        continue;
                    }
                }

                $type = (string) ($r['type'] ?? '');
                $label = (string) $src['label'] . ($type !== '' ? " ({$type})" : '') . ' #' . (string) ($r['id'] ?? '');

                if (in_array($type, (array) ($src['skip_types'] ?? []), true)) {
                    $concerns[] = "{$label} not deducted — {$type} is computed automatically now; cancel or waive the manual entry";

                    continue;
                }

                if (isset($src['only_when'])) {
                    $w = (array) $src['only_when'];
                    $v = trim((string) ($r[$w['field']] ?? ''));

                    if (!($v === '' ? (bool) ($w['blank_counts'] ?? false) : str_starts_with($v, (string) $w['starts_with']))) {
                        continue;
                    }
                }

                [$amount, $how] = $this->basisAmount($r, $src, $base);
                $amount ??= $this->amountOf($r, $src);

                if ($amount === null) {
                    $concerns[] = "{$label} has no amount" . ($how !== '' ? " — {$how}" : '') . ' — not paid';

                    continue;
                }

                // COMMISSION OR INCENTIVE (owner, 2 Nov 2026): an entry under a Commission Setup / Incentive Setup scheme is
                // its own payslip line, named with its purpose and scheme; anything else keeps the source's code and label.
                $scheme = isset($src['code_by_scheme_kind']) ? $this->schemeOf((string) ($r['scheme_id'] ?? '')) : null;
                $schemeKind = trim((string) ($scheme['scheme_kind'] ?? ''));
                if ($schemeKind !== '') {
                    $label = (string) (($src['label_by_scheme_kind'] ?? [])[$schemeKind] ?? $src['label'])
                        . (trim((string) ($r['purpose'] ?? '')) !== '' ? ' — ' . trim((string) $r['purpose']) : '')
                        . ' (scheme "' . (string) ($scheme['name'] ?? '') . '") #' . (string) ($r['id'] ?? '');
                }

                $how !== '' && $label .= " = {$how}";

                $code = (string) (($src['code_by_scheme_kind'] ?? [])[$schemeKind] ?? ($src['code_by_type'] ?? [])[$type] ?? $src['code']);
                // A BONUS TYPE IS ITS OWN PAYSLIP LINE (owner, 5 Nov 2026): "<code>:<type>" prints under the type's name
                // (payslipLines, ComponentRules::labelFor). A line exists only when the entry pays, so it prints only then.
                if (!empty($src['code_named_by_type']) && trim($type) !== '' && !isset(($src['code_by_type'] ?? [])[$type])) {
                    $code .= ':' . trim($type);
                }
                $when = isset($src['month']) ? (string) $r[$src['month']] : (string) $r[$src['date']];
                $amount->minor === 0 || $lines[] = self::line($code, (string) $src['kind'], $amount,
                    "{$label}, {$when}, status " . (string) $r['status']);

                // THE ENTRY'S OWN TDS COUNTS for Commission-type staff (29 Oct) and for any entry under a scheme whose
                // "TDS deduction applicable" is ticked (Commission / Incentive Setup, 30 Oct) — for salaried staff it is
                // then credited against the slab TDS below (TaxDeclarations::monthlyTds), so nothing is taxed twice.
                if (isset($src['tds']) && ($flatTds || $this->schemeTakesTds((string) ($r['scheme_id'] ?? '')))) {
                    $tds = trim((string) ($r[$src['tds']] ?? ''));

                    if ($tds !== '' && ($tdsMoney = Amount::of($tds))->minor > 0) {
                        $lines[] = self::line((string) (($src['tds_code_by_scheme_kind'] ?? [])[$schemeKind] ?? $src['tds_code']), 'deduction', $tdsMoney,
                            "TDS on {$label}" . (($r['tds_rate'] ?? '') !== '' ? ' at ' . $r['tds_rate'] . '%' : '') . ', as worked out when the entry was made');
                    }
                }
            }
        }

        // TDS ON SALARY, after every other line, because this month's incentives and the TDS
        // already taken on them change what is left to deduct. A refusal is a concern on the row.
        if ($this->tax !== null && $base !== [] && !$flatTds) {
            try {
                $tds = $this->tax->monthlyTds($employee, $period, $base, $lines);
                $tds !== null && $tds['amount']->minor > 0 && $lines[] = self::line('TDS', 'deduction', $tds['amount'], $tds['trace']);
            } catch (\Throwable $e) {
                $concerns[] = 'TDS on salary not worked out: ' . $e->getMessage();
            }
        }

        return ['lines' => $lines, 'concerns' => $concerns];
    }

    /** @var array<string, bool> scheme id => TDS applicable on a Commission / Incentive Setup scheme */
    private array $schemeTds = [];

    private function schemeTakesTds(string $schemeId): bool
    {
        $s = $this->schemeOf($schemeId);

        return $s !== null && trim((string) ($s['scheme_kind'] ?? '')) !== '' && Compensation::tdsApplicable($s);
    }

    /** @var array<string, ?array<string, mixed>> scheme id => row, read once */
    private array $schemes = [];

    /** @return ?array<string, mixed> */
    private function schemeOf(string $schemeId): ?array
    {
        if ($schemeId === '') {
            return null;
        }
        if (!array_key_exists($schemeId, $this->schemes)) {
            try {
                $this->schemes[$schemeId] = $this->store->find('incentive_schemes', $schemeId);
            } catch (\Throwable) { // gate:allow-swallow no incentive_schemes table on a file store: the entry keeps its source's code
                $this->schemes[$schemeId] = null;
            }
        }

        return $this->schemes[$schemeId];
    }

    /**
     * THE AMOUNT FROM ITS BASIS (owner, 7 Oct): "% of a component" or "days ×
     * per-day rate of a component", worked out from the employee's own FULL-MONTH
     * salary figures in $base (component code => Money, plus GROSS and CTC) —
     * never typed, so it cannot disagree with the salary it is based on.
     * [null, ''] when the row has no computed basis (Fixed / blank).
     *
     * @param array<string, mixed> $r @param array<string, mixed> $src @param array<string, Money> $base
     * @return array{0: ?Money, 1: string}
     */
    private function basisAmount(array $r, array $src, array $base): array
    {
        $b = (array) ($src['basis'] ?? []);
        $kind = strtolower(trim((string) ($r[(string) ($b['field'] ?? '')] ?? '')));

        if ($b === [] || !in_array($kind, ['percent', 'days'], true)) {
            return [null, ''];
        }

        $component = strtoupper(trim((string) ($r[(string) $b['component_field']] ?? '')));
        $value = trim((string) ($r[(string) $b['value_field']] ?? ''));

        if ($component === '' || preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
            return [null, 'its basis needs a salary component and a number'];
        }

        $of = $base[$component] ?? null;

        if ($of === null) {
            return [null, "{$component} is not on this employee's salary"];
        }

        str_contains($value, '.') && $value = rtrim(rtrim($value, '0'), '.') ?: '0';
        $divisor = max(1, (int) ($b['per_day_divisor'] ?? 30));

        return $kind === 'percent'
            ? [$of->multipliedBy(Rational::fromDecimalString($value)->dividedBy(Rational::of(100)), RoundingMode::HalfUp),
                "{$value}% of {$component} {$of->format()}"]
            : [$of->multipliedBy(Rational::fromDecimalString($value)->dividedBy(Rational::of($divisor)), RoundingMode::HalfUp),
                "{$value} day(s) × {$component} {$of->format()} ÷ {$divisor}"];
    }

    /** @param array<string, mixed> $r @param array<string, mixed> $src */
    private function amountOf(array $r, array $src): ?Money
    {
        foreach ([(string) ($src['amount'] ?? ''), (string) ($src['amount_fallback'] ?? '')] as $field) {
            $v = $field === '' ? '' : trim((string) ($r[$field] ?? ''));

            if ($v !== '') {
                return Amount::of($v);
            }
        }

        // Overtime with hours and a rate but no amount: hours × rate × multiplier, rounded once.
        if (isset($src['amount_from_hours'])) {
            $h = (array) $src['amount_from_hours'];
            $hours = trim((string) ($r[$h['hours']] ?? ''));
            $rate = trim((string) ($r[$h['rate']] ?? ''));

            if ($hours !== '' && $rate !== '') {
                $mult = trim((string) ($r[$h['multiplier']] ?? '')) ?: '1';

                return Amount::of($rate)->multipliedBy(
                    Rational::fromDecimalString($hours)->multipliedBy(Rational::fromDecimalString($mult)),
                    RoundingMode::HalfUp);
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function line(string $code, string $kind, Money $amount, string $trace): array
    {
        return ['code' => $code, 'kind' => $kind, 'amount' => $amount->format(), 'minor' => $amount->minor, 'trace' => $trace];
    }

    /** @return list<array<string, mixed>> */
    private function rowsOf(string $table): array
    {
        if (!isset($this->rows[$table])) {
            try {
                $this->rows[$table] = array_values(array_filter($this->store->all($table),
                    static fn (array $r): bool => !Modules::isRetired($r)));
            } catch (\Throwable) {
                $this->rows[$table] = [];
            }
        }

        return $this->rows[$table];
    }
}
