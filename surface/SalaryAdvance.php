<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;

/**
 * A SALARY ADVANCE: requested, sanctioned, approved at three levels, recovered.
 *
 * config/salary-advance.json declares the levels and who may sign them, and
 * `deciding_level` (22 Sep: HR) — the one level whose decision sets the status; the
 * rows live in `advances` (the table Recoveries reads at payroll). The EMI is
 * DERIVED — sanctioned ÷ tenure, rounded up to the rupee — never typed, and the
 * overall status is derived from the three decisions: any Rejected → rejected,
 * any On Hold → on hold, all Approved → approved, else pending. Disbursement is
 * a separate act (money left), from which payroll recovers.
 */
final class SalaryAdvance
{
    public const EMI = 'emi';

    public const MANUAL = 'manual';

    private array $config;

    public function __construct(private readonly RowStore $store, private readonly Modules $modules, string $configFile)
    {
        $c = json_decode((string) file_get_contents($configFile), true);

        if (!is_array($c) || !is_array($c['levels'] ?? null)) {
            throw new \RuntimeException("config/salary-advance.json is missing or unreadable at {$configFile}.");
        }

        $this->config = $c;
    }

    public function config(): array
    {
        return $this->config;
    }

    /** @return list<array{key: string, label: string, designation_words: list<string>}> */
    public function levels(): array
    {
        return array_values((array) $this->config['levels']);
    }

    /** The employees who may sign a level: designation contains one of its words. @param list<array<string, mixed>> $employees */
    public function signers(array $level, array $employees): array
    {
        $out = [];

        foreach ($employees as $e) {
            foreach ((array) ($level['exclude_words'] ?? []) as $word) {
                if ($this->modules->holdsDesignation($e, (string) $word)) {
                    continue 2;
                }
            }

            foreach ((array) ($level['designation_words'] ?? []) as $word) {
                if ($this->modules->holdsDesignation($e, (string) $word)) {
                    $out[] = $e;

                    break;
                }
            }
        }

        // NEVER AN EMPTY LIST (owner, 28 Sep: no blank dropdowns). When nobody's
        // designation or job role carries the words, everybody not excluded is
        // offered and the screen says so.
        if ($out === []) {
            foreach ($employees as $e) {
                foreach ((array) ($level['exclude_words'] ?? []) as $word) {
                    if ($this->modules->holdsDesignation($e, (string) $word)) {
                        continue 2;
                    }
                }

                $out[] = $e + ['_unmatched' => true];
            }
        }

        usort($out, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $out;
    }

    /** EMI in paise: sanctioned ÷ tenure rounded UP to the rupee; the last instalment is whatever is left. */
    public static function emiMinor(string $sanctioned, int $tenure): int
    {
        if ($tenure < 1) {
            return 0;
        }

        $minor = Amount::of($sanctioned)->minor;
        $perMonth = intdiv($minor, $tenure);
        $rupees = intdiv($perMonth + 99, 100); // round up to the rupee

        return min($rupees * 100, $minor);
    }

    /** The level whose decision alone decides the status (config `deciding_level`), or null when every level must approve. */
    public function decidingLevel(): ?array
    {
        $key = (string) ($this->config['deciding_level'] ?? '');

        foreach ($this->levels() as $lvl) {
            if ((string) $lvl['key'] === $key) {
                return $lvl;
            }
        }

        return null;
    }

    /** The overall status from the decisions. @param array<string, string> $decisions level key => decision */
    public function overall(array $decisions): string
    {
        $s = (array) $this->config['statuses'];

        // ONE DECIDING LEVEL (owner, 22 Sep: HR Admin / HR Manager). The other
        // levels' decisions are recorded and shown, but only this one counts.
        if (($deciding = $this->decidingLevel()) !== null) {
            return match ((string) ($decisions[(string) $deciding['key']] ?? '')) {
                'Approved' => (string) $s['approved'],
                'Rejected' => (string) $s['rejected'],
                'On Hold' => (string) $s['on_hold'],
                default => (string) $s['pending'],
            };
        }

        $values = [];

        foreach ($this->levels() as $lvl) {
            $values[] = (string) ($decisions[$lvl['key']] ?? '');
        }

        if (in_array('Rejected', $values, true)) {
            return (string) $s['rejected'];
        }

        if (in_array('On Hold', $values, true)) {
            return (string) $s['on_hold'];
        }

        if ($values !== [] && count(array_filter($values, static fn (string $v): bool => $v === 'Approved')) === count($values)) {
            return (string) $s['approved'];
        }

        return (string) $s['pending'];
    }

    /**
     * A stored row's status UNDER THE RULE IN FORCE. Rows saved before HR became
     * the deciding level (22 Sep) carry the old all-three verdict; until money
     * has moved, the status is re-derived from the recorded decisions so every
     * screen and the Disburse button agree with today's rule. Once disbursed the
     * stored status is history and is returned unchanged.
     *
     * @param array<string, mixed> $row
     */
    public function statusOf(array $row): string
    {
        $stored = (string) ($row['status'] ?? '');

        if (!in_array($stored, ['', 'pending', 'approved', 'rejected', 'on hold'], true)) {
            return $stored;
        }

        $decisions = [];

        foreach ($this->levels() as $lvl) {
            $decisions[(string) $lvl['key']] = (string) ($row[$lvl['key'] . '_status'] ?? '');
        }

        return $this->overall($decisions);
    }

    /** Validate and shape a row from the form. @return array{ok: bool, why: string, row?: array<string, mixed>} */
    public function shape(array $input, array $employee, string $today): array
    {
        $requested = trim((string) ($input['amount_requested'] ?? ''));
        $sanctioned = trim((string) ($input['amount'] ?? ''));
        $tenure = (int) ($input['tenure_months'] ?? 0);
        $reason = trim((string) ($input['remarks'] ?? ''));
        $emiStart = trim((string) ($input['emi_start'] ?? ''));
        // RECOVERY: Option 1 'emi' (tenure → EMI, recovered every month) or
        // Option 2 'manual' (no EMI; payroll takes only what is recorded on
        // Loans / Salary Advance Recovery). Owner, 22 Sep.
        $mode = trim((string) ($input['recovery_mode'] ?? '')) === self::MANUAL ? self::MANUAL : self::EMI;

        if ($mode === self::MANUAL) {
            $tenure = 0;
            $emiStart = '';
        }

        if ($reason === '') {
            return ['ok' => false, 'why' => 'A reason is required.'];
        }

        foreach (['Amount requested' => $requested, 'Amount sanctioned' => $sanctioned] as $label => $v) {
            if ($v !== '' && preg_match('/^\d+(\.\d{1,2})?$/', $v) !== 1) {
                return ['ok' => false, 'why' => "{$label} must be a rupee figure, e.g. 15000 or 15000.50."];
            }
        }

        if ($requested === '') {
            return ['ok' => false, 'why' => 'Amount requested is required.'];
        }

        if ($sanctioned !== '' && Amount::of($sanctioned)->minor > Amount::of($requested)->minor) {
            return ['ok' => false, 'why' => 'The sanctioned amount cannot exceed the amount requested.'];
        }

        if ($tenure !== 0 && !in_array($tenure, array_map('intval', (array) $this->config['tenure_months']), true)) {
            return ['ok' => false, 'why' => 'Tenure must be one of the declared choices.'];
        }

        if ($emiStart !== '' && preg_match('/^\d{4}-\d{2}$/', $emiStart) !== 1) {
            return ['ok' => false, 'why' => 'EMI start is a month, YYYY-MM.'];
        }

        $decisions = [];
        $row = [
            'employee_id' => (string) $employee['id'],
            'amount_requested' => $requested,
            'amount' => $sanctioned !== '' ? $sanctioned : null,
            'balance' => $sanctioned !== '' ? $sanctioned : null,
            'tenure_months' => $tenure > 0 ? $tenure : null,
            'per_month' => $sanctioned !== '' && $tenure > 0 ? Money::ofMinor(self::emiMinor($sanctioned, $tenure))->toDecimalString() : null,
            'emi_start' => $emiStart !== '' ? $emiStart : null,
            'recovery_mode' => $mode,
            'remarks' => mb_substr($reason, 0, 255),
            'requested_on' => (string) ($input['requested_on'] ?? '') ?: $today,
        ];

        foreach ($this->levels() as $lvl) {
            $k = (string) $lvl['key'];
            $who = trim((string) ($input[$k . '_id'] ?? ''));
            $decision = trim((string) ($input[$k . '_status'] ?? ''));

            if ($decision !== '' && !in_array($decision, (array) $this->config['decisions'], true)) {
                return ['ok' => false, 'why' => "'{$decision}' is not a decision for {$lvl['label']}."];
            }

            if ($decision !== '' && $who === '') {
                return ['ok' => false, 'why' => "Pick who decided for {$lvl['label']}."];
            }

            $row[$k . '_id'] = $who !== '' ? $who : null;
            $row[$k . '_status'] = $decision !== '' ? $decision : null;
            $decisions[$k] = $decision;
        }

        $row['status'] = $this->overall($decisions);

        return ['ok' => true, 'why' => 'ok', 'row' => $row];
    }

    /** What payroll has recovered so far, in paise, from the closed runs. */
    public function recoveredMinor(string $employmentCode, string $advanceId, Compensation $compensation): int
    {
        return array_sum($compensation->recoveredByPeriod($employmentCode, (string) $this->config['line_prefix'] . $advanceId));
    }
}
