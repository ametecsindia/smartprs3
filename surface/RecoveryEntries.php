<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * LOANS / SALARY ADVANCE RECOVERY — amounts recorded by hand against an advance
 * or a loan (owner, 22 Sep). Compensation & Claims → Loans / Salary Advance
 * Recovery writes them; `Recoveries` (payroll) and `Compensation` (the
 * "Recovered so far" / balance on the Salary Advance, Loans and EMI Schedule
 * screens) read them.
 *
 * Two MODES, and they move the balance in two different ways:
 *   - `payroll`   — deduct this amount from that month's payroll. It becomes
 *                   the month's ADV-<id> / LOAN-<id> deduction line IN PLACE OF
 *                   the EMI. Once the month is closed the RUN holds it, and the
 *                   outstanding is derived from the run exactly as for an EMI —
 *                   so it is never counted twice.
 *   - `collected` — the amount was received outside payroll (cash, bank, UPI).
 *                   On no payslip; it lowers the outstanding from the month it
 *                   was received, so later EMIs and the last-instalment
 *                   remainder come out right.
 *
 * NOTHING IS EDITED OR DELETED (law 3): a mistaken entry is CANCELLED — kept,
 * with who, when and why — and a cancelled entry counts for nothing.
 *
 * `loan_recovery_entries` ships with this release (database/migrations/
 * 2026_09_22_000100_loan_recovery_entries.php). The application may not create
 * it (law 2), so until the migration runs the entries live in the tenant's file
 * store — `over()` picks, the screen says which.
 */
final class RecoveryEntries
{
    public const COLLECTION = 'loan_recovery_entries';

    public const PAYROLL = 'payroll';

    public const COLLECTED = 'collected';

    public const ACTIVE = 'active';

    public const CANCELLED = 'cancelled';

    /** kind => the table, the payroll line prefix, and the words for it. */
    public const KINDS = [
        'advance' => ['table' => 'advances', 'prefix' => Recoveries::ADVANCE, 'noun' => 'salary advance', 'title' => 'Advance Salary Recovery'],
        'loan' => ['table' => 'loans', 'prefix' => Recoveries::LOAN, 'noun' => 'loan', 'title' => 'Loan Recovery'],
    ];

    /** @var array<int, self> one per store object, so every reader in a request sees the same place */
    private static array $over = [];

    /** @var list<array<string, mixed>>|null */
    private ?array $rows = null;

    public function __construct(private readonly RowStore $store, private readonly bool $inFiles = false)
    {
    }

    /**
     * The entries for a store: its own table where the migration has run (or
     * where it is itself the file store), else the tenant's file store.
     */
    public static function over(RowStore $store): self
    {
        $key = spl_object_id($store);

        if (isset(self::$over[$key])) {
            return self::$over[$key];
        }

        if ($store instanceof Store) {
            return self::$over[$key] = new self($store);
        }

        try {
            $store->columnsOf(self::COLLECTION);

            return self::$over[$key] = new self($store);
        } catch (\InvalidArgumentException) {
            // Tolerated exactly when the table does not exist yet: the migration
            // has not run. Any other failure is not this exception and propagates.
            $tenant = (string) ($store->tenantId() ?? '');

            return self::$over[$key] = new self((new Store(__DIR__ . '/../storage'))
                ->forTenant($tenant === '' ? '1' : $tenant), true);
        }
    }

    public function inFiles(): bool
    {
        return $this->inFiles;
    }

    public static function lineCode(string $kind, string $obligationId): string
    {
        return (string) (self::KINDS[$kind]['prefix'] ?? '') . $obligationId;
    }

    /** @return list<array<string, mixed>> every entry, cancelled ones included (the screen lists them) */
    public function all(): array
    {
        return $this->rows ??= $this->store->all(self::COLLECTION);
    }

    /** @return list<array<string, mixed>> the entries that count, for one payroll line code */
    public function activeFor(string $lineCode): array
    {
        return array_values(array_filter($this->all(), static fn (array $r): bool =>
            (string) ($r['line_code'] ?? '') === $lineCode && (string) ($r['status'] ?? self::ACTIVE) !== self::CANCELLED));
    }

    /** The amount recorded against one month's payroll for one line, in paise; null when nothing was. */
    public function payrollMinor(string $lineCode, string $period): ?int
    {
        $found = null;

        foreach ($this->activeFor($lineCode) as $r) {
            if ((string) ($r['mode'] ?? '') === self::PAYROLL && (string) ($r['period'] ?? '') === $period) {
                $found = ($found ?? 0) + Amount::of((string) $r['amount'])->minor;
            }
        }

        return $found;
    }

    /** @return array<string, int> period => paise collected outside payroll, for one line */
    public function collectedByPeriod(string $lineCode): array
    {
        $out = [];

        foreach ($this->activeFor($lineCode) as $r) {
            if ((string) ($r['mode'] ?? '') === self::COLLECTED) {
                $p = (string) ($r['period'] ?? '');
                $out[$p] = ($out[$p] ?? 0) + Amount::of((string) $r['amount'])->minor;
            }
        }

        return $out;
    }

    /** Paise collected outside payroll up to and including a month. */
    public function collectedThrough(string $lineCode, string $period): int
    {
        $sum = 0;

        foreach ($this->collectedByPeriod($lineCode) as $p => $minor) {
            if ($p <= $period) {
                $sum += $minor;
            }
        }

        return $sum;
    }

    /**
     * Validate and shape an entry from the form.
     *
     * @param array<string, mixed> $input mode, amount, period (payroll) or collected_on (collected), reference, remarks
     * @param array<string, mixed> $obligation the advances / loans row
     * @param array<string, mixed> $employee
     * @param int $availableMinor what may still be recorded (Recoveries::availableMinor)
     * @return array{ok: bool, why: string, row?: array<string, mixed>}
     */
    public function shape(array $input, string $kind, array $obligation, array $employee, int $availableMinor, string $by): array
    {
        if (!isset(self::KINDS[$kind])) {
            return ['ok' => false, 'why' => 'Pick Advance Salary Recovery or Loan Recovery.'];
        }

        if ((string) ($obligation['employee_id'] ?? '') !== (string) ($employee['id'] ?? '')) {
            return ['ok' => false, 'why' => 'That ' . self::KINDS[$kind]['noun'] . ' is not this employee\'s.'];
        }

        $mode = trim((string) ($input['mode'] ?? ''));
        $amount = trim((string) ($input['amount'] ?? ''));

        if (!in_array($mode, [self::PAYROLL, self::COLLECTED], true)) {
            return ['ok' => false, 'why' => 'Choose whether the amount is deducted from payroll or was already collected.'];
        }

        if (preg_match('/^\d+(\.\d{1,2})?$/', $amount) !== 1 || Amount::of($amount)->minor <= 0) {
            return ['ok' => false, 'why' => 'Amount must be a rupee figure above zero, e.g. 5000 or 5000.50.'];
        }

        if (Amount::of($amount)->minor > $availableMinor) {
            return ['ok' => false, 'why' => 'Amount is more than is still to be recovered ('
                . \SmartPRS\Platform\Support\Money::ofMinor($availableMinor)->format() . ' after entries already booked).'];
        }

        $collectedOn = null;

        if ($mode === self::PAYROLL) {
            $period = trim((string) ($input['period'] ?? ''));

            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
                return ['ok' => false, 'why' => 'Pick the payroll month (YYYY-MM) to deduct it from.'];
            }
        } else {
            $collectedOn = trim((string) ($input['collected_on'] ?? ''));
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $collectedOn);

            if ($d === false || $d->format('Y-m-d') !== $collectedOn) {
                return ['ok' => false, 'why' => 'Enter the date the amount was collected.'];
            }

            $period = substr($collectedOn, 0, 7);
        }

        return ['ok' => true, 'why' => 'ok', 'row' => [
            'kind' => $kind,
            'obligation_id' => (string) $obligation['id'],
            'line_code' => self::lineCode($kind, (string) $obligation['id']),
            'employee_id' => (string) $employee['id'],
            'mode' => $mode,
            'period' => $period,
            'amount' => $amount,
            'collected_on' => $collectedOn,
            'reference' => mb_substr(trim((string) ($input['reference'] ?? '')), 0, 120) ?: null,
            'remarks' => mb_substr(trim((string) ($input['remarks'] ?? '')), 0, 255) ?: null,
            'status' => self::ACTIVE,
            'created_by' => $by,
        ]];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function put(array $row): array
    {
        $this->rows = null;

        return $this->store->put(self::COLLECTION, $row);
    }

    public function find(string $id): ?array
    {
        return $this->store->find(self::COLLECTION, $id);
    }
}
