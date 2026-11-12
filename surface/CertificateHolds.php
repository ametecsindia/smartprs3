<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;

/**
 * CERTIFICATE-LINKED DEDUCTIONS (owner, 13 Oct).
 *
 * A DRA / PCC (or any other) deduction is taken every month by the Salary
 * Structure's own "Employee Status" rule while the certificate is pending. Once
 * the employee's status reads one of the rule's "submitted" values, the next
 * payroll pays back EVERYTHING held so far as one earning line.
 *
 * NOTHING IS MARKED. What was held and what was refunded are read from the
 * closed payrolls themselves — the deduction lines and the refund lines — so a
 * month regenerated gives the same refund, and a refund is never paid twice:
 * balance = held in earlier months − refunded in earlier months.
 *
 * ON EXIT without the certificate the organisation chooses per rule: refund it
 * in the employee's last payroll, or forfeit it (the payslip says so).
 *
 * Rules are Organization Setup → Certificate-linked Deductions, versioned in the
 * tenant's Customisation under config/certificate-holds.json's key.
 */
final class CertificateHolds
{
    public function __construct(
        private readonly RowStore $store,
        private readonly Customisation $customisation,
        private readonly string $configFile,
    ) {
    }

    /** @return array<string, mixed> */
    public function declared(): array
    {
        return (array) json_decode((string) @file_get_contents($this->configFile), true);
    }

    public function key(): string
    {
        return (string) ($this->declared()['customisation_key'] ?? 'certificate-holds');
    }

    /** @return list<array<string, mixed>> the rules in force: the saved version, else the shipped ones */
    public function rules(): array
    {
        $live = $this->customisation->live()[$this->key()]['rules'] ?? null;

        return array_values((array) ($live ?? ($this->declared()['rules'] ?? [])));
    }

    public function isCustomised(): bool
    {
        return isset($this->customisation->live()[$this->key()]);
    }

    /** @return list<array<string, mixed>> active rules only */
    private function active(): array
    {
        return array_values(array_filter($this->rules(), static fn (array $r): bool => (bool) ($r['active'] ?? false)
            && trim((string) ($r['deduction_code'] ?? '')) !== '' && trim((string) ($r['refund_code'] ?? '')) !== ''));
    }

    /**
     * What every employee has had held and refunded, per rule, in closed payrolls
     * BEFORE $period. Reversed and discarded months paid nothing and count nothing.
     *
     * @return array<string, array<string, array{held: int, refunded: int, months: list<string>}>> code => rule key => totals
     */
    public function ledger(string $period): array
    {
        $rules = $this->active();

        if ($rules === []) {
            return [];
        }

        $reversed = [];

        foreach ($this->store->all('payroll_corrections') as $c) {
            if ((string) ($c['new_fingerprint'] ?? '') === Register::REVERSED) {
                $reversed[(string) ($c['period'] ?? '')] = true;
            }
        }

        $out = [];

        foreach ($this->store->all('payroll_runs') as $run) {
            $ran = (string) ($run['period'] ?? $run['cycle_label'] ?? '');

            if ($ran === '' || $ran >= $period || isset($reversed[$ran])
                || (string) ($run['status'] ?? '') === Register::DISCARDED) {
                continue;
            }

            $rows = $run['rows'] ?? $run['run_rows'] ?? [];
            $rows = is_string($rows) ? (array) json_decode($rows, true) : (array) $rows;

            foreach ($rows as $code => $row) {
                foreach ((array) (((array) $row)['lines'] ?? []) as $line) {
                    $lc = (string) ($line['code'] ?? '');

                    foreach ($rules as $rule) {
                        $k = (string) $rule['key'];
                        $isHeld = $lc === (string) $rule['deduction_code'];
                        $isRefund = $lc === (string) $rule['refund_code'];

                        if (!$isHeld && !$isRefund) {
                            continue;
                        }

                        $e = $out[(string) $code][$k] ?? ['held' => 0, 'refunded' => 0, 'months' => []];
                        $isHeld ? $e['held'] += (int) ($line['minor'] ?? 0) : $e['refunded'] += (int) ($line['minor'] ?? 0);
                        $isHeld && (int) ($line['minor'] ?? 0) > 0 && $e['months'][] = $ran;
                        $out[(string) $code][$k] = $e;
                    }
                }
            }
        }

        return $out;
    }

    /** What the next payroll does for one employee under one rule: refund | hold | forfeit | none. */
    public static function outcome(array $rule, array $employee, int $balance, string $periodEnd): string
    {
        if ($balance <= 0) {
            return 'none';
        }

        $have = strtolower(trim((string) ($employee[(string) ($rule['status_field'] ?? '')] ?? '')));
        $cleared = array_map(static fn ($v): string => strtolower(trim((string) $v)), (array) ($rule['cleared_values'] ?? []));

        if ($have !== '' && in_array($have, $cleared, true)) {
            return 'refund';
        }

        $exit = substr(trim((string) ($employee['exit_date'] ?? '')), 0, 10);

        if ($exit !== '' && $exit <= $periodEnd) {
            return ((string) ($rule['on_exit'] ?? '')) === 'refund' ? 'refund' : 'forfeit';
        }

        return 'hold';
    }

    /**
     * The refund lines for one employee in the payroll for $period.
     *
     * @param array<string, mixed> $employee
     * @param array<string, array<string, array{held: int, refunded: int, months: list<string>}>>|null $ledger
     * @return array{lines: list<array<string, mixed>>, concerns: list<string>}
     */
    public function forEmployment(array $employee, string $period, string $periodEnd, ?array $ledger = null): array
    {
        $code = Employment::code($employee);
        $ledger ??= $this->ledger($period);
        $lines = [];
        $concerns = [];

        foreach ($this->active() as $rule) {
            $t = $ledger[$code][(string) $rule['key']] ?? null;

            if ($t === null) {
                continue;
            }

            $balance = $t['held'] - $t['refunded'];
            $months = array_values(array_unique($t['months']));
            sort($months);
            $span = $months === [] ? '' : ($months[0] === end($months) ? $months[0] : $months[0] . ' to ' . end($months));
            $what = (string) ($rule['label'] ?? $rule['key']);

            switch (self::outcome($rule, $employee, $balance, $periodEnd)) {
                case 'refund':
                    $lines[] = [
                        'code' => (string) $rule['refund_code'],
                        'kind' => 'earning',
                        'amount' => Money::ofMinor($balance)->format(),
                        'minor' => $balance,
                        'trace' => sprintf('%s: refund of %s held over %d month(s) (%s) — %s held less %s already refunded.',
                            $what, (string) $rule['deduction_code'], count($months), $span,
                            Money::ofMinor($t['held'])->format(), Money::ofMinor($t['refunded'])->format()),
                    ];
                    break;
                case 'forfeit':
                    $concerns[] = sprintf('%s: %s held over %d month(s) (%s) is forfeited — left without the certificate (Certificate-linked Deductions → on exit: forfeit).',
                        $what, Money::ofMinor($balance)->format(), count($months), $span);
                    break;
            }
        }

        return ['lines' => $lines, 'concerns' => $concerns];
    }
}
