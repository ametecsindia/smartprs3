<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Payroll\PayrollRun;
use SmartPRS\Platform\Payroll\RunAborted;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\RoundingMode;

/**
 * A PAYROLL MONTH, NOT A PAYSLIP — the register.
 *
 * Until now the surface computed one payslip for one employee on demand. That
 * is a calculator. A payroll is a MONTH: every employee, one control total,
 * one act of approval, and a figure somebody signs.
 *
 * A CLOSED PERIOD IS NOT RE-RUN. Once a period is closed, `open()` refuses. A
 * silent recompute is how two people quote two net totals for the same month
 * from the same system and neither is wrong. To change a closed month you open
 * the next one, or you reverse — both leave a record; a rerun leaves none.
 *
 * THE FINGERPRINT IS STORED WITH THE RUN, so `verify()` can recompute the month
 * from the same inputs and the same structure version and say whether it still
 * produces the identical figures to the paisa. A payroll that cannot be
 * reproduced is a payroll nobody can defend in an audit, and the only honest way
 * to know is to try it rather than to assume it.
 *
 * WHAT WAS NOT PAYABLE IS REPORTED, NOT EXCLUDED. An employee with an
 * unpaired punch is listed with the reason next to it and given zero payable
 * days — a quietly dropped employee is an unpaid employee who finds out on the
 * 7th.
 *
 * ponytail: no bank file. A reversal is stored as a `payroll_corrections` row
 * marked with `new_fingerprint = 'reversal'`, because that table already holds
 * "a record raised against a run that is never touched" and a reversal is
 * exactly that. Upgrade path when reversals grow their own fields: a `kind`
 * column on `payroll_corrections`, or a `payroll_reversals` table.
 */
final class Register
{
    /**
     * What sits in a reversal's `new_fingerprint`. A reversal recomputes
     * NOTHING — that is the point of it — so there is no second fingerprint,
     * and the column that would have held one says which kind of record this is.
     */
    public const REVERSED = 'reversal';

    /** A run thrown away while testing (see discard()) — kept, never listed or re-read. */
    public const DISCARDED = 'discarded';

    public function __construct(
        private readonly RowStore $store,
        private readonly PayrollPeriod $period,
        private readonly Structure $structure,
        // ponytail: optional so the existing callers and every test that builds a
        // register for an unrelated reason keep working unchanged. The PRODUCT
        // wires one in; a register constructed without one performs no statutory
        // step at all and says nothing about it, which is honest only because the
        // absence is a wiring decision in this file's callers rather than
        // something a payroll officer can cause. Make it required the day there
        // is more than one caller who could get it wrong.
        private readonly ?Statutory $statutory = null,
        // ponytail: optional for exactly the reason the statutory step above is —
        // every existing caller and every test that builds a register for an
        // unrelated reason keeps working. A register constructed without one
        // recovers no loan and no advance, which is the behaviour this build had
        // before `Recoveries` existed.
        private readonly ?Recoveries $recoveries = null,
        // THE APPROVAL STEPS, from config/payroll-approval.json when the product
        // wires them in. None given = one step, which is what every existing
        // caller and test expects. Each step: key, label, permission.
        private readonly array $approvalSteps = [],
        // THE SALARY SCHEDULE / PAY CYCLE rows (Organization Setup). With one
        // wired in, each employee's attendance window for the period is the one
        // its winning rule declares — 21st to 20th, 26th to 25th — and the row
        // says so. Without one, the calendar month, as before.
        private readonly ?PayCycles $cycles = null,
        // WORKING SHIFTS by scope: each employee's day is judged against ITS
        // shift's times; the caller's times are the default for anybody no row covers.
        private readonly ?Shifts $shifts = null,
        // THE SALARY STRUCTURE SCREEN'S OWN SCOPED RULES (Organization
        // Configuration → Salary structure) — company/branch/team/designation/
        // employee-specific component overrides, resolved per employment and
        // merged over the flat structure above. Requires `$cycles` too (the
        // same scope ladder ComponentRules matches against); without either
        // one, every employment prices off the flat structure alone, exactly
        // as before this existed — a wiring decision in this file's callers,
        // not something a payroll officer can cause.
        private readonly ?ComponentRules $componentRules = null,
        // WHICH STATUTORY HEADS APPLY TO EACH EMPLOYEE (owner, 28 Sep): PF, ESI,
        // PT, LWF and TDS answered No on the employee's record take their
        // components off that employee's payslip. Without one, every component
        // applies to everybody, exactly as before this existed.
        private readonly ?StatutoryApplicability $applicability = null,
        // INCENTIVES, BONUS, OVERTIME, REIMBURSEMENTS AND MANUAL DEDUCTIONS
        // (owner, 7 Oct) — config/payroll-inputs.json. Without one, none.
        private readonly ?PayrollInputs $inputs = null,
        // CERTIFICATE-LINKED DEDUCTIONS (owner, 13 Oct): DRA / PCC held until the
        // certificate is in, then refunded in one line. Without one, none.
        private readonly ?CertificateHolds $holds = null,
        // CTC INCLUDES EMPLOYER CONTRIBUTIONS (owner, 15 Oct) — asked at close time; see CtcSplit.
        private readonly ?\Closure $ctcIncludesEmployer = null,
    ) {
    }

    /** @return list<array<string, mixed>> newest first */
    public function runs(): array
    {
        $runs = array_map(self::ported(...), array_filter($this->store->all('payroll_runs'),
            static fn (array $r): bool => (string) ($r['status'] ?? '') !== self::DISCARDED));
        usort($runs, static fn (array $a, array $b): int
            => strcmp((string) $b['period'], (string) $a['period']));

        return $runs;
    }

    /**
     * A ported `payroll_runs` row in the shape the register and its screens read.
     *
     * This class was written against `period`, `employee_count`, `closed_at`,
     * `net`, `payable` and `rows`. SmartPRS 2's row is a schedule lot:
     * `cycle_label`, `employees_count`, `locked_at`, `net_total` — and for the
     * fingerprint, the inputs and the per-employment rows it has no column at
     * all, which is why 2026_03_01_000500 adds those and this maps the rest.
     *
     * MONEY IS STORED ONCE, as the schema's DECIMAL, and the formatted figures
     * are derived here. Storing "12,34,567.00" in a decimal column is a refusal
     * or a truncation depending on the mode, and storing both is two numbers
     * that can disagree.
     *
     * @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    private static function ported(array $run): array
    {
        $run['period'] ??= (string) ($run['cycle_label'] ?? '');
        $run['closed_at'] ??= (string) ($run['locked_at'] ?? '');
        $run['employee_count'] ??= (int) ($run['employees_count'] ?? 0);
        // `approved_by` is a users.id on the ported table and this build signs
        // with the account's email, so the label has its own column.
        $run['approved_by'] ??= $run['approved_by_name'] ?? null;
        $run['net_minor'] ??= self::minorOf($run['net_total'] ?? null);
        $run['payable_minor'] ??= self::minorOf($run['payable_amount'] ?? null);
        $run['arrears_minor'] ??= self::minorOf($run['arrears_amount'] ?? null);
        $run['net'] ??= Money::ofMinor((int) $run['net_minor'])->format();
        $run['payable'] ??= Money::ofMinor((int) $run['payable_minor'])->format();
        $run['arrears_total'] ??= Money::ofMinor((int) $run['arrears_minor'])->format();
        $run['earnings'] ??= Money::ofMinor(self::minorOf($run['earnings_total'] ?? null))->format();
        $run['deductions'] ??= Money::ofMinor(self::minorOf($run['deductions_total'] ?? null))
            ->format();
        $run['rows'] ??= self::decoded($run['run_rows'] ?? null);
        $run['inputs'] ??= self::decoded($run['run_inputs'] ?? null);
        // Assigned, not `??=`: `arrears` is a real column, so it arrives as the
        // JSON text MySQL stores rather than as the list every caller iterates.
        $run['arrears'] = self::decoded($run['arrears'] ?? null);

        return $run;
    }

    /**
     * The same run in the SCHEMA's names — the only shape a write may take.
     *
     * @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    private static function row(array $run): array
    {
        return [
            'cycle_label' => $run['period'],
            'status' => isset($run['approved_by']) ? 'approved' : (string) ($run['status'] ?? 'closed'),
            'employees_count' => (int) $run['employee_count'],
            'net_total' => self::majorOf((int) $run['net_minor']),
            'earnings_total' => self::majorOf((int) $run['earnings_minor']),
            'deductions_total' => self::majorOf((int) $run['deductions_minor']),
            'arrears_amount' => self::majorOf((int) $run['arrears_minor']),
            'payable_amount' => self::majorOf((int) $run['payable_minor']),
            'locked_at' => $run['closed_at'],
            'closed_by' => $run['closed_by'],
            'fingerprint' => $run['fingerprint'],
            'structure_effective_from' => $run['structure_effective_from'],
            'shift' => $run['shift'],
            'run_inputs' => $run['inputs'],
            'run_rows' => $run['rows'],
            'arrears' => $run['arrears'],
        ];
    }

    /** Paise from a DECIMAL(18,4) as the driver hands it back. */
    private static function minorOf(mixed $value): int
    {
        return Amount::of((string) ($value ?? 0))->minor;
    }

    /** Rupees as a decimal string the column takes — never the formatted figure. */
    private static function majorOf(int $minor): string
    {
        return Money::ofMinor($minor)->toDecimalString();
    }

    /**
     * A JSON column as the list it holds. MySQL hands back text; the file store
     * hands back the array it was given, and both must read the same.
     *
     * @return array<string|int, mixed>
     */
    private static function decoded(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return is_string($value) && $value !== '' ? (array) json_decode($value, true) : [];
    }

    public function find(string $period): ?array
    {
        foreach ($this->runs() as $run) {
            if ((string) $run['period'] === $period) {
                return $run;
            }
        }

        return null;
    }

    /**
     * Compute and close a month.
     *
     * $windowFrom/$windowTo, given together (Y-m-d), stand in for the calendar
     * month as the attendance window for anyone the Salary Schedule / Pay Cycle
     * rules do not already cover — a one-off custom range for this close, not a
     * saved rule. The PERIOD BEING CLOSED IS STILL A CALENDAR MONTH regardless:
     * that identity is what statutory filing, arrears and reversal are keyed on,
     * and a custom window does not change it — see PayCycles' own custom cutoff
     * for the same distinction (its window can straddle two months; its period
     * never does).
     *
     * @return array{ok: bool, why: string, run?: array<string, mixed>}
     */
    public function close(string $period, string $shiftStart, string $shiftEnd, string $by,
        ?string $windowFrom = null, ?string $windowTo = null): array
    {
        if (preg_match('/^\d{4}-\d{2}$/', $period) !== 1) {
            return ['ok' => false, 'why' => "'{$period}' is not a period in YYYY-MM form."];
        }

        if (($windowFrom === null) !== ($windowTo === null)) {
            return ['ok' => false, 'why' => 'A custom attendance window needs both a From and a To date.'];
        }

        if ($windowFrom !== null && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $windowFrom) !== 1
                || preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $windowTo) !== 1 || $windowFrom > $windowTo)) {
            return ['ok' => false, 'why' => 'The custom attendance window is not two valid dates with '
                . 'From on or before To.'];
        }

        if ($this->find($period) !== null) {
            return ['ok' => false, 'why' => "{$period} is already closed. It is not recomputed: a "
                . 'silent re-run is how two people quote two net totals for the same month from the '
                . 'same system. To change a closed month, open the next one or reverse into it — '
                . 'both leave a record.'];
        }

        $computed = $this->computeMonth($period, $shiftStart, $shiftEnd, $windowFrom, $windowTo);

        if (!$computed['ok']) {
            return $computed;
        }

        // ARREARS. A correction raised against an EARLIER month produced a
        // difference nobody has paid yet. Carrying it here is what makes the
        // correction a payment rather than a note: an unsettled difference that
        // never reaches a payslip is a report that agrees with itself and pays
        // nobody.
        $arrears = $this->unsettledArrears($period);
        $arrearsMinor = 0;

        foreach ($arrears as $line) {
            $arrearsMinor += (int) $line['amount_minor'];
        }

        $stored = ['closed_at' => date('c'), 'closed_by' => $by]
            + $computed['run'];
        $stored['arrears'] = $arrears;
        $stored['arrears_minor'] = $arrearsMinor;
        $stored['arrears_total'] = Money::ofMinor($arrearsMinor)->format();
        // The month's own net and the arrears are kept SEPARATE and also summed.
        // A single blended figure cannot answer "what did this month cost?" or
        // "what did we still owe from before?", and both questions get asked.
        $stored['payable_minor'] = (int) $stored['net_minor'] + $arrearsMinor;
        $stored['payable'] = Money::ofMinor($stored['payable_minor'])->format();

        // Written in the SCHEMA's names. This put the surface's own run shape
        // straight into `payroll_runs` — `period`, `employee_count`, `net`,
        // `closed_at`, `rows` — and the table has none of those, so closing a
        // month against MySQL was refused outright.
        $stored = self::ported($this->store->put('payroll_runs', self::row($stored)));

        // Settled only AFTER the run is stored: a correction marked paid against
        // a run that then failed to store is a difference that has vanished.
        foreach ($arrears as $line) {
            $correction = $this->store->find('payroll_corrections', (string) $line['correction']);

            if ($correction !== null) {
                $correction['settled_in'] = $period;
                $correction['settled_at'] = date('c');
                $this->store->put('payroll_corrections', $correction);
            }
        }

        return ['ok' => true, 'run' => $stored, 'why' => "{$period} is closed. Net "
            . $stored['net'] . ' across ' . $stored['employee_count'] . ' employee(s)'
            . ($arrears === [] ? '' : ', plus ' . $stored['arrears_total'] . ' of arrears from '
                . count($arrears) . ' earlier correction line(s), giving ' . $stored['payable']
                . ' payable')
            . ', fingerprinted so the month can be recomputed and compared rather than trusted.'];
    }

    /**
     * Approve a closed run. Until then it is computed but not payable.
     *
     * MAKER AND CHECKER ARE DIFFERENT PEOPLE. A run approved by the person who
     * closed it has had one pair of eyes on it, and the control exists precisely
     * because the person who built a figure is the worst placed to notice it is
     * wrong. This refuses; it does not warn.
     *
     * @return array{ok: bool, why: string, run?: array<string, mixed>}
     */
    /** @return list<array{key: string, label: string, permission: string, prompt?: string}> */
    public function approvalSteps(): array
    {
        return $this->approvalSteps === [] ? [['key' => 'final', 'label' => 'Approval', 'permission' => 'payroll.run.approve']] : array_values($this->approvalSteps);
    }

    /**
     * Which step a run is waiting on: 0-based index into approvalSteps(), or null
     * when fully approved. Intermediate stages are written to `status` as
     * `<key>_approved`; the final one is `approved`.
     */
    public function stageOf(array $run): ?int
    {
        $status = (string) ($run['status'] ?? 'closed');

        if ($status === 'approved' || isset($run['approved_by'])) {
            return null;
        }

        $steps = $this->approvalSteps();

        foreach ($steps as $i => $step) {
            if ($status === $step['key'] . '_approved') {
                return $i + 1 < count($steps) ? $i + 1 : null;
            }
        }

        return 0;
    }

    /** The step a run waits on, or null when approved. */
    public function nextStep(array $run): ?array
    {
        $i = $this->stageOf($run);

        return $i === null ? null : $this->approvalSteps()[$i];
    }

    /** Names of everyone who has signed so far, from the note the steps write. */
    public static function signers(array $run): array
    {
        preg_match_all('/\bby ([^\s|]+) on /', (string) ($run['approval_note'] ?? ''), $m);

        return array_values(array_unique(array_merge($m[1], array_filter([(string) ($run['approved_by'] ?? '')]))));
    }

    /**
     * Sign the next approval step. The last step approves for payment.
     *
     * @param bool $selfApprovalAllowed the ONE named exception to "the person who
     *        closed a run cannot approve it" (Authorisation::maySelfApprove(),
     *        owner's instruction 12 Sep 2026) — resolved by the caller from the
     *        signer's role, never guessed at here: this class stays authorisation-
     *        blind, the same as every other engine class in `surface/`.
     * @return array{ok: bool, why: string, run?: array<string, mixed>, step?: array<string, mixed>, final?: bool}
     */
    public function approve(string $period, string $by, string $note, bool $selfApprovalAllowed = false): array
    {
        $run = $this->find($period);

        if ($run === null) {
            return ['ok' => false, 'why' => "{$period} has not been closed, so there is nothing to "
                . 'approve.'];
        }

        if (isset($run['approved_by'])) {
            return ['ok' => false, 'why' => "{$period} was already approved by {$run['approved_by']}"
                . ' on ' . substr((string) ($run['approved_at'] ?? ''), 0, 10) . '. A second approval '
                . 'is refused rather than recorded over the first: which of two signatures the money '
                . 'went out on is not a question anybody should have to answer later.'];
        }

        $stage = $this->stageOf($run);
        $status = (string) ($run['status'] ?? 'closed');

        if ($stage === 0 && $status !== 'closed') {
            return ['ok' => false, 'why' => "{$period} is '" . $status
                . "', not closed, so there is nothing to approve. Close the month first — approval "
                . 'signs a total, and a total that can still change is not one anybody can sign.'];
        }

        $reversal = $this->reversalOf($period);

        if ($reversal !== null) {
            return ['ok' => false, 'why' => "{$period} was reversed by {$reversal['raised_by']} "
                . '(' . $reversal['reason'] . '), so it cannot now be approved for payment. Close '
                . 'the corrected month instead.'];
        }

        if (!$selfApprovalAllowed && (string) $run['closed_by'] === $by) {
            return ['ok' => false, 'why' => 'The person who closed a run cannot approve it. That is a '
                . 'refusal rather than a warning, because the control exists precisely for the case '
                . 'where one person is in a hurry — and the person who built a figure is the worst '
                . 'placed to notice it is wrong.'];
        }

        if (in_array($by, self::signers($run), true)) {
            return ['ok' => false, 'why' => "{$by} has already signed an earlier step of {$period}. "
                . 'Each step is a different pair of eyes, or it is one step written twice.'];
        }

        $steps = $this->approvalSteps();
        $step = $steps[(int) $stage];
        $final = (int) $stage === count($steps) - 1;
        $line = $step['label'] . ' by ' . $by . ' on ' . date('Y-m-d H:i') . ($note !== '' ? ': ' . $note : '');
        $trail = trim((string) ($run['approval_note'] ?? ''));
        $trail = $trail === '' ? $line : $trail . ' | ' . $line;

        if (!$final) {
            $this->store->put('payroll_runs', [
                'id' => $run['id'],
                'status' => $step['key'] . '_approved',
                'approval_note' => $trail,
            ]);
            $run['status'] = $step['key'] . '_approved';
            $run['approval_note'] = $trail;

            return ['ok' => true, 'run' => $run, 'step' => $step, 'final' => false,
                'why' => "{$period}: {$step['label']} recorded by {$by}. Waiting on " . $steps[(int) $stage + 1]['label'] . '.'];
        }

        $run['approved_by'] = $by;
        $run['approved_at'] = date('c');
        $run['approval_note'] = $trail;
        $run['status'] = 'approved';

        $this->store->put('payroll_runs', [
            'id' => $run['id'],
            'status' => 'approved',
            'approved_by_name' => $by,
            'approved_at' => $run['approved_at'],
            'approval_note' => $trail,
        ]);

        return ['ok' => true, 'run' => $run, 'step' => $step, 'final' => true, 'why' => "{$period} is approved for payment: "
            . $run['payable'] . ' by ' . $by . ', closed by ' . $run['closed_by'] . '.'];
    }

    public function reverse(string $period, string $reason, string $by): array
    {
        $original = $this->find($period);

        if ($original === null) {
            return ['ok' => false, 'why' => "{$period} has not been closed, so there is nothing to "
                . 'reverse. A month that never ran is undone by not running it.'];
        }

        if (trim($reason) === '') {
            return ['ok' => false, 'why' => 'A reversal with no reason is a month of pay withdrawn '
                . 'and nobody able to say why. The reason is the whole point of reversing rather '
                . 'than deleting — the run survives, so the explanation has to as well.'];
        }

        $already = $this->reversalOf($period);

        if ($already !== null) {
            return ['ok' => false, 'why' => "{$period} was already reversed by "
                . (string) $already['raised_by'] . ' on '
                . substr((string) $already['raised_at'], 0, 10) . ' ("' . (string) $already['reason']
                . '"). A second reversal would recover the same month twice.'];
        }

        $deltas = [];
        $netMinor = 0;

        foreach ((array) $original['rows'] as $code => $row) {
            $was = self::netMinor((array) $row);
            $netMinor += $was;
            $deltas[(string) $code] = [
                'name' => (string) $row['name'],
                'was' => (string) ($row['net'] ?? '—'),
                'now' => 'reversed',
                'delta_minor' => -$was,
                'delta' => Money::ofMinor(-$was)->format(),
                'why' => 'the run this was paid in was reversed: ' . $reason,
            ];
        }

        $reversal = self::corrected($this->store->put('payroll_corrections', [
            'period' => $period,
            'corrects' => (string) $original['id'],
            'original_fingerprint' => (string) $original['fingerprint'],
            // Not a fingerprint: nothing was recomputed. See `REVERSED`.
            'new_fingerprint' => self::REVERSED,
            'raised_at' => date('c'),
            'raised_by' => $by,
            'reason' => $reason,
            'net_delta_minor' => -$netMinor,
            'deltas' => $deltas,
        ]));

        return ['ok' => true, 'reversal' => $reversal, 'why' => "{$period} is REVERSED: "
            . $reversal['net_delta'] . ' across ' . count($deltas) . ' employee(s), because '
            . $reason . '. The original run is untouched and still reads exactly as it was paid — '
            . 'the reversal stands beside it, and the recovery is carried into the next month\'s '
            . 'close as arrears.'
            // ponytail: the month's OWN net is reversed. Arrears this run paid on
            // behalf of EARLIER months stay settled — they were genuinely owed —
            // and that is stated rather than left to be discovered. Unsettle them
            // here the day a reversal has to claw back a whole payment file.
            . ((array) ($original['arrears'] ?? []) === [] ? '' : ' The '
                . (string) $original['arrears_total'] . ' of arrears this run paid for EARLIER '
                . 'months is NOT reversed — it was owed before this month and is still owed; '
                . 're-raise it as a correction if it has to come back too.')];
    }

    /**
     * THROW A GENERATED RUN AWAY SO THE PERIOD CAN BE GENERATED AGAIN (21 Sep
     * 2026, owner: "we are still testing, we have to regenerate the payroll
     * multiple times"). Law 3 still holds — nothing is erased: the run row is
     * kept with status `discarded` and its label renamed so the period is free,
     * corrections raised against it are parked the same way, and arrears it
     * had settled are released so the next run carries them again. Only
     * available while config/payroll-approval.json says `allow_discard: true`;
     * set it to false before real payroll runs on this installation.
     *
     * @return array{ok: bool, why: string}
     */
    public function discard(string $period, string $by, string $reason): array
    {
        $raw = null;

        foreach ($this->store->all('payroll_runs') as $r) {
            if ((string) ($r['status'] ?? '') !== self::DISCARDED && (string) ($r['cycle_label'] ?? $r['period'] ?? '') === $period) {
                $raw = $r;
            }
        }

        if ($raw === null) {
            return ['ok' => false, 'why' => "There is no generated payroll for {$period}."];
        }

        if (trim($reason) === '') {
            return ['ok' => false, 'why' => 'Say why this payroll is being deleted — it is kept in the history with that reason.'];
        }

        $stamp = date('Y-m-d H:i');
        $label = "{$period} · deleted {$stamp}";

        foreach ($this->store->all('payroll_corrections') as $c) {
            if ((string) ($c['period'] ?? '') === $period) {
                // Raised against the run being thrown away: parked with it. `period` and
                // `settled_in` are varchar(7), so the label never goes in them; settling it in
                // its own period keeps it out of arrears, and correctionsFor() drops it because
                // the run it `corrects` is now discarded.
                $this->store->put('payroll_corrections', ['id' => $c['id'], 'settled_in' => $period, 'settled_at' => date('c')]);
            } elseif ((string) ($c['settled_in'] ?? '') === $period) {
                // Arrears this run had paid: owed again, so the next run carries them.
                $this->store->put('payroll_corrections', ['id' => $c['id'], 'settled_in' => null, 'settled_at' => null]);
            }
        }

        $this->store->put('payroll_runs', ['id' => $raw['id'], 'status' => self::DISCARDED, 'cycle_label' => $label]);

        return ['ok' => true, 'why' => "Payroll for {$period} deleted by {$by} ({$reason}). It can be generated again now; "
            . 'the deleted run is kept in the history as "' . $label . '".'];
    }

    /**
     * The reversal raised against a period, if there is one.
     *
     * @return array<string, mixed>|null
     */
    public function reversalOf(string $period): ?array
    {
        foreach ($this->correctionsFor($period) as $correction) {
            if ((string) ($correction['new_fingerprint'] ?? '') === self::REVERSED) {
                return $correction;
            }
        }

        return null;
    }

    /**
     * Correction lines from EARLIER periods that no run has paid yet.
     *
     * @return list<array<string, mixed>>
     */
    public function unsettledArrears(string $period): array
    {
        $lines = [];

        foreach (array_map(self::corrected(...), $this->store->all('payroll_corrections')) as $correction) {
            if (($correction['settled_in'] ?? null) !== null
                || (string) $correction['period'] >= $period) {
                continue;
            }

            foreach ((array) $correction['deltas'] as $code => $delta) {
                $lines[] = [
                    'employment_id' => (string) $code,
                    'name' => (string) $delta['name'],
                    'from_period' => (string) $correction['period'],
                    'correction' => (string) $correction['id'],
                    'amount_minor' => (int) $delta['delta_minor'],
                    'amount' => (string) $delta['delta'],
                    'why' => (string) $correction['reason'],
                ];
            }
        }

        return $lines;
    }

    /**
     * Compute a month from what the records say TODAY. Stores nothing — both
     * `close()` and `correct()` build on it, so a correction is computed by
     * exactly the same path as the original and cannot quietly differ in method.
     *
     * @return array{ok: bool, why: string, run?: array<string, mixed>}
     */
    private function computeMonth(string $period, string $shiftStart, string $shiftEnd,
        ?string $windowFrom = null, ?string $windowTo = null): array
    {
        // STRUCTURE LOOKUP STAYS ON THE CALENDAR MONTH regardless of a custom
        // attendance window below — which salary structure version applies is a
        // question about the period itself, not about which days its attendance
        // was drawn from.
        $from = $period . '-01';
        $to = date('Y-m-t', (int) strtotime($from));

        try {
            $components = $this->structure->on($to);
            $basis = $this->structure->contractedCostBasis($to);
            $version = $this->structure->versionOn($to);
        } catch (\Throwable $e) {
            return ['ok' => false, 'why' => $e->getMessage()];
        }

        $employments = $this->store->all('employees');

        // THE CTC IN FORCE FOR THIS MONTH (8 Oct): an appraisal effective after
        // the month ends leaves the month on the CTC it replaced.
        try {
            $employments = Increments::inForce($employments, $this->store->all(Increments::TABLE), $to);
        } catch (\Throwable) { // gate:allow-swallow no increments register in this store (tests): the record as it stands
        }

        if ($employments === []) {
            return ['ok' => false, 'why' => 'There are no employees to pay. An empty run is refused '
                . 'rather than closed as a month with a net total of zero, which reads like a payroll '
                . 'that ran.'];
        }

        $inputs = [];
        $rows = [];
        // The employee ROW by code, so the recovery step below can ask about the
        // loans and advances of the person it is paying. The payslips the engine
        // hands back carry the code and nothing else.
        $byCode = [];
        $employmentSignature = [];

        // EACH EMPLOYMENT PRICES OFF ITS OWN RESOLVED COMPONENTS — the Salary
        // Structure screen's company/branch/team/designation/employee rules,
        // merged over the flat structure for whatever no rule covers (see
        // ComponentRules::merge()). `PayrollRun` prices ALL its inputs with
        // ONE shared component list, so employments whose winning rules are
        // the exact same set (same signature — see `signatureOf()`) share one
        // run; everyone else gets their own. Grouped rather than one-run-
        // per-employee, because most employments in a real tenant share a
        // branch and therefore a signature.
        $groups = [];

        foreach ($employments as $employment) {
            $code = Employment::code($employment);
            $byCode[$code] = $employment;
            $cycle = $this->cycles?->for($employment);
            // A SAVED PAY CYCLE RULE always wins (it is the deliberate, per-scope
            // answer to "which days"). Failing that, an ad-hoc window given for
            // just this close is used; failing that, the whole calendar month.
            [$eFrom, $eTo] = match (true) {
                $cycle !== null => PayCycles::window($cycle, $period),
                $windowFrom !== null && $windowTo !== null => [$windowFrom, $windowTo],
                default => [$from, $to],
            };
            [$eStart, $eEnd] = $this->shifts?->times($employment, $shiftStart, $shiftEnd) ?? [$shiftStart, $shiftEnd];
            $derived = $this->period->forPeriod($code, $eFrom, $eTo, $eStart, $eEnd);

            // NOT EMPLOYED IN THIS WINDOW AT ALL (7 Oct): joined after it ended,
            // or left before it began. Out of the run — the exit month is the
            // last one payroll pays; the rest is the full & final.
            if (($derived['joined_on'] ?? null) !== null && $derived['joined_on'] > $eTo
                || ($derived['exited_on'] ?? null) !== null && $derived['exited_on'] < $eFrom) {
                unset($byCode[$code]);

                continue;
            }

            // Reported, never excluded. A dropped employee is an unpaid employee
            // who finds out on the 7th.
            $concerns = [];

            if ($derived['needing_attention'] > 0) {
                $concerns[] = $derived['needing_attention'] . ' day(s) need regularisation';
            }

            if ($derived['overlaps'] !== []) {
                $concerns[] = count($derived['overlaps']) . ' day(s) both worked and on approved leave';
            }

            if ((string) ($derived['unpaid_leave_days'] ?? $derived['unpaid_leave']) !== '0') {
                $concerns[] = ($derived['unpaid_leave_days'] ?? $derived['unpaid_leave']) . ' day(s) of loss of pay';
            }

            $inputRow = [
                'employment_id' => $code,
                // ONE MONTH'S GROSS, not the contract. The line used to read
                // Money::of(Employment::ctc(...)) — the annual figure straight in as
                // the month — and every payslip this product could produce was twelve
                // times too high beneath a per-line trace that explained the
                // arithmetic correctly. The basis is declared on the structure
                // version in force, so a customer who quotes monthly CTC changes a
                // config value rather than waiting for a release.
                'gross' => Employment::monthlyGross($employment, $basis),
                // NOT `(int)`. `payable` is a Rational in days and can be a half
                // — an in-punch with no out-punch is half a day under the
                // published rules. An int cast here would round it, silently, on
                // the number that multiplies somebody's gross.
                'payable_days' => $derived['payable'],
                'period_days' => (int) $derived['period_days'],
            ];
            $inputs[] = $inputRow;

            // WHICH SALARY STRUCTURE RULES GOVERN THIS EMPLOYMENT, resolved
            // now, at close time — the same scope ladder the Salary Structure
            // screen resolves against live. The winning rule IDS are stored on
            // the row below so `verify()` can reconstruct this exact group
            // later from the ids a close recorded, never by re-resolving scope
            // against employment data that may have changed since.
            $resolved = $this->componentRules !== null && $this->cycles !== null
                ? $this->componentRules->resolveFor($employment, $to, $this->cycles)
                : [];
            // THE STATUTORY HEADS THIS EMPLOYEE IS EXCLUDED FROM are part of
            // the grouping key: two people on the same rules but one with PF
            // switched off price against two different component lists, so
            // they cannot share a run. Blank answers exclude nothing.
            $statutoryOff = $this->applicability?->offFor($employment, null, $to) ?? [];
            $signature = ($this->componentRules?->signatureOf($resolved) ?? '')
                . ($statutoryOff === [] ? '' : '|off:' . implode(',', $statutoryOff));
            $groups[$signature]['resolved'] ??= $resolved;
            $groups[$signature]['off'] ??= $statutoryOff;
            $groups[$signature]['inputs'][] = $inputRow;
            $employmentSignature[$code] = $signature;

            $rows[$code] = [
                'name' => (string) $employment['name'],
                'gross' => Employment::monthlyGross($employment, $basis)->toDecimalString(),
                'payable_days' => $derived['payable']->toDecimalString(),
                'period_days' => (int) $derived['period_days'],
                // HOW THE PAYABLE DAYS WERE MADE UP — printed on the payslip (7 Oct).
                'days' => [
                    'worked' => (string) ($derived['worked_days'] ?? $derived['worked']),
                    'off' => (string) ($derived['off_days'] ?? '0'),
                    'holidays' => (int) ($derived['holidays'] ?? 0),
                    // IN DAYS, halves included (12 Oct): half a day of CL is 0.5 on the payslip.
                    'paid_leave' => (string) ($derived['paid_leave_days'] ?? $derived['paid_leave']),
                    'unpaid_leave' => (string) ($derived['unpaid_leave_days'] ?? $derived['unpaid_leave']),
                    'absent' => (int) ($derived['absent'] ?? 0),
                    'late_penalty' => \SmartPRS\Platform\Support\Rational::of((int) ($derived['penalty_halves'] ?? 0), 2)->toDecimalString(),
                    'late_marks' => count((array) ($derived['penalties'] ?? [])),
                    'exited_on' => $derived['exited_on'] ?? null,
                ],
                'concerns' => $concerns,
                'window' => $eFrom . ' to ' . $eTo,
                'shift' => $eStart . '–' . $eEnd,
                'cycle' => $cycle === null ? null : (string) ($cycle['name'] ?? ''),
                'pay_day' => $cycle === null ? null : (int) ($cycle['pay_day'] ?? 0),
                // The actual calendar date, not just a day of the month — a window
                // can close in one month and pay in a later one (pay_month_offset).
                'pay_date' => $cycle === null ? null : PayCycles::payDate($cycle, $period),
                // The winning rule ids this employment priced against — empty
                // when none covered it (priced off the flat structure alone).
                // Read back by verify(), never by anything that prices pay.
                'component_rule_ids' => array_map(
                    static fn (array $r): string => (string) ($r['id'] ?? ''),
                    $resolved,
                ),
                // WHAT WAS LEFT OFF, AND WHY — stored with the row so the
                // payslip can say "PF not applicable" instead of just not
                // having a PF line, and so verify() rebuilds the same list.
                // WHO THEY WERE WHEN THIS MONTH CLOSED (28 Sep): the payslip header
                // — company, branch, department, designation, PAN, UAN, joining —
                // is read from here, so a transfer next month does not rewrite
                // this month's payslip.
                'as_closed' => array_intersect_key($employment, array_flip(['name', 'employer_company_id',
                    'branch_id', 'branch', 'department_id', 'department', 'designation_id', 'designation',
                    'team_id', 'team', 'pan', 'uan', 'doj', 'training_start_date', 'training_end_date'])),
                'statutory_off' => $statutoryOff,
                // THE BRANCH / STATUS each rule priced from for this person, so
                // verify() rebuilds the same component list (28 Sep).
                'component_rule_variants' => $this->componentRules?->variantsOf($resolved) ?? [],
                'statutory_not_applicable' => $this->applicability?->offLabels($employment, null, $to) ?? [],
            ];
        }

        // ONE PayrollRun PER GROUP, then combined — PayrollRun itself still
        // prices every input in it against exactly one shared component list
        // (unchanged, untouched), so a group with its own resolved rules is a
        // separate run rather than a special case inside it. Each group is
        // independently reconciled by its own run's own constructor.
        $groupRuns = [];
        $ctcGross = [];
        $flagsBySignature = [];
        $componentsBySignature = [];

        try {
            foreach ($groups as $signature => $group) {
                $groupComponents = StatutoryApplicability::without(
                    $this->componentRules !== null
                        ? $this->componentRules->merge($group['resolved'], $components)
                        : $components,
                    (array) ($group['off'] ?? []),
                );
                // CTC INCLUDES THE EMPLOYER'S CONTRIBUTIONS: the gross split into components is the
                // CTC less the employer lines, so gross + employer = the contract (CtcSplit).
                if ($this->ctcIncludesEmployer !== null && ($this->ctcIncludesEmployer)()) {
                    foreach ($group['inputs'] as $gi => $gIn) {
                        $group['inputs'][$gi]['gross'] = CtcSplit::gross($gIn['gross'], $groupComponents, $period);
                        $ctcGross[(string) $gIn['employment_id']] = $group['inputs'][$gi]['gross'];
                    }
                }
                $groupRuns[$signature] = new PayrollRun($period, $groupComponents, $group['inputs']);
                $componentsBySignature[$signature] = $groupComponents;

                // Flags by component code, for the statutory wage base — per
                // GROUP, because two employments can now legitimately price
                // off different components. The FLAGS, never the codes: the
                // pack names `is_pf_base` and the tenant declares which
                // component carries it, so a renamed component cannot
                // silently change what it contributes to.
                $groupFlags = [];

                foreach ($groupComponents as $component) {
                    $groupFlags[$component->code] = $component->flags;
                }

                $flagsBySignature[$signature] = $groupFlags;
            }
        } catch (RunAborted $aborted) {
            return ['ok' => false, 'why' => 'The run was ABORTED by its own reconciliation, so nothing '
                . 'was closed: ' . json_encode($aborted->failures)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'why' => 'The engine refused: ' . $e->getMessage()];
        }

        // The split gross is what was paid from, so it is what the run stores (and verify() re-reads).
        foreach ($inputs as $ii => $iIn) {
            if (isset($ctcGross[(string) $iIn['employment_id']])) {
                $rows[(string) $iIn['employment_id']]['ctc_month'] = $iIn['gross']->toDecimalString();
                $inputs[$ii]['gross'] = $ctcGross[(string) $iIn['employment_id']];
                $rows[(string) $iIn['employment_id']]['gross'] = $inputs[$ii]['gross']->toDecimalString();
            }
        }

        $totals = ['earnings' => Money::zero(), 'deductions' => Money::zero(),
            'net' => Money::zero(), 'employee_count' => 0];
        $allPayslips = [];

        foreach ($groupRuns as $groupRun) {
            $groupTotals = $groupRun->totals();
            $totals['earnings'] = $totals['earnings']->plus($groupTotals['earnings']);
            $totals['deductions'] = $totals['deductions']->plus($groupTotals['deductions']);
            $totals['net'] = $totals['net']->plus($groupTotals['net']);
            $totals['employee_count'] += $groupTotals['employee_count'];
            array_push($allPayslips, ...$groupRun->payslips);
        }

        $asOf = new \DateTimeImmutable($to);
        // Deductions the STRUCTURE engine did not compute — statutory contributions
        // and loan/advance instalments — in paise, for the month. They are added to
        // the engine's own totals rather than re-derived from the line kinds, so
        // the run's control total is the engine's figure plus exactly what these
        // two steps returned and cannot drift from the payslips beneath it.
        $asideMinor = 0;
        // EARNINGS FROM OUTSIDE THE STRUCTURE — incentives, bonus, OT,
        // reimbursements (7 Oct). Added to the engine's earnings and net exactly
        // as the aside deductions are taken off them.
        $extraMinor = 0;
        $holdsLedger = null;

        foreach ($allPayslips as $slip) {
            $flags = $flagsBySignature[$employmentSignature[$slip->employmentId] ?? ''] ?? [];
            $lines = [];
            $amounts = [];

            foreach ($slip->lines as $line) {
                $lines[] = ['code' => $line->componentCode, 'kind' => $line->kind,
                    'amount' => $line->amount->format(), 'minor' => $line->amount->minor,
                    'trace' => $line->trace];
                $amounts[$line->componentCode] = $line->amount;
            }

            // EVERY DECLARED STATUTORY HEAD IS ASKED, FOR EVERY EMPLOYMENT, ON
            // EVERY CLOSE. Before this the engine was reachable from one screen and
            // no closed month contained a single statutory line — worse, no record
            // that the question had ever been put. A real contribution becomes a
            // deduction line like any other, carrying the rule, pack version and
            // content hash in its trace. A refusal becomes a CONCERN on this row,
            // which `Payslip::from()` already carries onto the document.
            $statutory = $this->statutory?->forEmployment($slip->employmentId, $asOf, $amounts, $flags)
                ?? ['rows' => [], 'concerns' => []];
            // A HEAD SWITCHED OFF FOR THIS EMPLOYEE (answered No, or not applicable
            // during probation — 8 Oct) is off for the engine too, not only for the
            // structure: no row, and no "was not computed" concern for it either.
            $offHeads = $this->applicability?->offHeads($byCode[$slip->employmentId] ?? [], null, $to) ?? [];

            if ($offHeads !== []) {
                $isOff = static function (string $head) use ($offHeads): bool {
                    $head = strtoupper($head);

                    foreach ($offHeads as $h) {
                        if ($head === $h || str_starts_with($head, $h . '_') || str_starts_with($head, $h . ' ')) {
                            return true;
                        }
                    }

                    return false;
                };
                $statutory['rows'] = array_values(array_filter($statutory['rows'], static fn ($r): bool => !$isOff((string) $r->head)));
                $statutory['concerns'] = array_values(array_filter($statutory['concerns'],
                    static fn (string $c): bool => !$isOff((string) strtok($c, ' '))));
            }
            $asideOwnMinor = 0;

            foreach ($statutory['rows'] as $row) {
                $lines[] = ['code' => $row->head, 'kind' => 'deduction',
                    'amount' => $row->amount->format(), 'minor' => $row->amount->minor,
                    // The row's own explanation, stored with the figure. It names
                    // the rule, the pack and its content hash, which is what makes
                    // the deduction re-derivable in 2031 rather than a claim.
                    'trace' => $row->explain()];
                $asideOwnMinor += $row->amount->minor;
            }

            // EVERY ACTIVE LOAN AND ADVANCE IS RECOVERED, on every close, for every
            // employee. Before this the two screens were CRUD and two reports,
            // and no instalment had ever reached a payslip — the customer was told
            // the recovery was manual, which means somebody typing a deduction off
            // a spreadsheet every month. An instalment is a deduction line like any
            // other, carrying which one of how many it is and what is left after
            // it; anything that cannot be computed becomes a CONCERN on this row,
            // exactly as a statutory refusal does.
            $recovery = $this->recoveries?->forEmployment($byCode[$slip->employmentId] ?? [], $period)
                ?? ['lines' => [], 'concerns' => []];

            foreach ($recovery['lines'] as $line) {
                $lines[] = $line;
                $asideOwnMinor += (int) $line['minor'];
            }

            [$inFrom, $inTo] = explode(' to ', (string) $rows[$slip->employmentId]['window']) + [1 => $to];
            $extra = $this->inputs?->forEmployment($byCode[$slip->employmentId] ?? [], $period, $inFrom, $inTo,
                $this->inputs === null ? [] : $this->fullMonth($slip->employmentId, $period, $inputs,
                    $componentsBySignature[$employmentSignature[$slip->employmentId] ?? ''] ?? []))
                ?? ['lines' => [], 'concerns' => []];
            $extraOwnMinor = 0;

            foreach ($extra['lines'] as $line) {
                $lines[] = $line;
                $line['kind'] === 'earning' ? $extraOwnMinor += (int) $line['minor'] : $asideOwnMinor += (int) $line['minor'];
            }

            // THE HELD DRA / PCC AMOUNT, PAID BACK once the certificate is in (or on
            // exit, when the rule says refund). One ledger read per close, not per slip.
            $holdsLedger ??= $this->holds?->ledger($period) ?? [];
            $held = $this->holds?->forEmployment($byCode[$slip->employmentId] ?? [], $period, $to, $holdsLedger)
                ?? ['lines' => [], 'concerns' => []];

            foreach ($held['lines'] as $line) {
                $lines[] = $line;
                $extraOwnMinor += (int) $line['minor'];
            }

            $extra['concerns'] = array_merge($extra['concerns'], $held['concerns']);

            // ESI AS PER GOVERNMENT RULES WHEN NOTHING IS SET (owner, 16 Oct 2026) — config/esi.json.
            foreach ($this->governmentEsi($slip->employmentId, $byCode[$slip->employmentId] ?? [], $period, $lines, $offHeads, (array) $rows[$slip->employmentId]) as $line) {
                $lines[] = $line;
                $line['kind'] === 'deduction' && $asideOwnMinor += (int) $line['minor'];
            }

            $asideMinor += $asideOwnMinor;
            $extraMinor += $extraOwnMinor;
            // A HEAD THE PAYSLIP ALREADY CARRIES (e.g. PT from the Salary structure) is not "NOT computed":
            // that note contradicted the PT line printed right above it (QA, 7 Nov 2026).
            $statutory['concerns'] = array_values(array_filter($statutory['concerns'], static function (string $c) use ($lines): bool {
                $head = strtoupper((string) strtok($c, ' '));

                foreach ($lines as $l) {
                    $code = strtoupper((string) ($l['code'] ?? ''));

                    if (($code === $head || str_starts_with($code, $head . '_')) && (int) ($l['minor'] ?? 0) !== 0
                        && str_contains($c, ' was NOT computed')) {
                        return false;
                    }
                }

                return true;
            }));
            $rows[$slip->employmentId]['concerns'] = array_merge(
                (array) $rows[$slip->employmentId]['concerns'],
                $statutory['concerns'],
                $recovery['concerns'],
                $extra['concerns'],
            );
            $rows[$slip->employmentId]['lines'] = $lines;
            $rows[$slip->employmentId]['earnings'] = $slip->grossEarnings()
                ->plus(Money::ofMinor($extraOwnMinor))->format();
            $rows[$slip->employmentId]['deductions'] = $slip->totalDeductions()
                ->plus(Money::ofMinor($asideOwnMinor))->format();
            $rows[$slip->employmentId]['net'] = $slip->netPay()
                ->plus(Money::ofMinor($extraOwnMinor))
                ->minus(Money::ofMinor($asideOwnMinor))->format();
            // The ENGINE's net less what the statutory and recovery steps actually
            // returned — not a re-derivation from the line kinds: an employer
            // contribution is not a deduction, and a correction that re-adds up the
            // lines itself is a second opinion that can drift from the payslip it
            // is correcting. With no statutory rows and no instalment this is the
            // engine's net to the paisa, which is the property that keeps a refusal
            // from moving anybody's pay.
            $rows[$slip->employmentId]['net_minor'] = $slip->netPay()->minor + $extraOwnMinor - $asideOwnMinor;
        }

        $computedRun = [
            'period' => $period,
            'structure_effective_from' => (string) $version['effective_from'],
            'shift' => $shiftStart . '–' . $shiftEnd,
            'employee_count' => $totals['employee_count'],
            'earnings' => $totals['earnings']->plus(Money::ofMinor($extraMinor))->format(),
            'deductions' => $totals['deductions']->plus(Money::ofMinor($asideMinor))->format(),
            'net' => $totals['net']->plus(Money::ofMinor($extraMinor))->minus(Money::ofMinor($asideMinor))->format(),
            // The minor units are what is STORED (as the schema's DECIMAL); the
            // formatted figures above are for the screen and are derived again
            // on read, so there is one number and not two that can disagree.
            'earnings_minor' => $totals['earnings']->minor + $extraMinor,
            'deductions_minor' => $totals['deductions']->minor + $asideMinor,
            'net_minor' => $totals['net']->minor + $extraMinor - $asideMinor,
            // The identity a re-run must reproduce, stored WITH the run so the
            // claim can be tested later rather than asserted.
            //
            // ponytail: the fingerprint covers the STRUCTURE run only, so `verify()`
            // reproduces the component lines and neither the statutory ones nor the
            // recovery instalments. For recoveries that is not a hole either: an
            // instalment is derived from the CLOSED RUNS, which are exactly the
            // records a re-run may not change, so it is reproducible by reading
            // rather than by recomputing. Put both into the fingerprint the day
            // `verify()` is asked to cover the whole payslip rather than the
            // structure that priced it. That is
            // stated rather than hidden, and it is not yet a hole: the only pack
            // this build has is synthetic and produces no rows at all. The day a
            // signed pack lands, the pack key, version and content hash each row
            // already carries have to go into the fingerprint too — otherwise a
            // month recomputed after a pack supersession verifies as identical
            // while its statutory figures have moved.
            //
            // ONE fingerprint across every group — `PayrollRun::fingerprintOf()`
            // is the exact same algorithm `fingerprint()` always was, run over
            // every group's payslips combined, so a month closed with no
            // Salary Structure rules wired in (one group, the whole tenant)
            // produces byte-identical fingerprints to before this existed.
            'fingerprint' => PayrollRun::fingerprintOf($allPayslips),
            'inputs' => array_map(static fn (array $i): array => [
                'employment_id' => $i['employment_id'], 'gross' => $i['gross']->format(),
                'payable_days' => $i['payable_days']->toDecimalString(),
                'period_days' => $i['period_days'],
            ], $inputs),
            'rows' => $rows,
        ];

        return ['ok' => true, 'why' => 'computed', 'run' => $computedRun];
    }

    /**
     * WHAT THIS EMPLOYEE EARNS IN A FULL MONTH, by component (BASIC, HRA … with
     * the flat structure's C-/D- prefix dropped), plus GROSS and CTC (annual) —
     * the base a "% of Basic" bonus or a "days × Basic" encashment is worked
     * out from (PayrollInputs). Full month, so absence does not shrink a bonus.
     *
     * @param list<array<string, mixed>> $inputs @param list<\SmartPRS\Platform\Payroll\SalaryComponent> $components
     * @return array<string, Money>
     */
    private function fullMonth(string $code, string $period, array $inputs, array $components): array
    {
        foreach ($inputs as $in) {
            if ((string) $in['employment_id'] !== $code || $components === []) {
                continue;
            }

            $out = ['GROSS' => $in['gross'], 'CTC' => $in['gross']->multipliedBy(12)];

            try {
                $full = (new \SmartPRS\Platform\Payroll\PayslipCalculator($components))->calculate($code, $period,
                    $in['gross'], Rational::of((int) $in['period_days']), (int) $in['period_days']);
            } catch (\Throwable) {
                return $out;
            }

            foreach ($full->lines as $line) {
                if ($line->kind === 'earning') {
                    $out[(string) preg_replace('/^[A-Z]-/', '', $line->componentCode)] = $line->amount;
                } else {
                    // DEDUCTIONS AND EMPLOYER LINES TOO (14 Oct), keyed apart so no earning basis can
                    // read one: the monthly TDS projects the year's employee PF, PT and employer NPS.
                    $out[($line->kind === 'deduction' ? 'DED:' : 'ER:') . $line->componentCode] = $line->amount;
                }
            }

            return $out;
        }

        return [];
    }

    /**
     * CORRECT a closed month. The original is never touched.
     *
     * `close()` refuses a second run for good reason, but "no" on its own is not
     * an answer: a payroll officer who has just found a missing punch needs
     * somewhere to go, and if the system offers nothing they will edit the
     * database. So the correction is a FIRST-CLASS RECORD — the month recomputed
     * from what the records say today, stored ALONGSIDE the original with the
     * difference per employee and a reason somebody typed.
     *
     * THE DIFFERENCE IS THE PRODUCT, not the new total. What was paid is
     * history; what is owed (or owed back) is the only figure anyone acts on,
     * and it is the one a correction that overwrites the original destroys.
     *
     * A CORRECTION THAT CHANGES NOTHING IS STILL RECORDED. "We checked and it
     * was right" is an answer worth keeping — otherwise the same question gets
     * asked again next month by somebody who cannot tell it was already
     * investigated.
     *
     * @return array{ok: bool, why: string, correction?: array<string, mixed>}
     */
    public function correct(string $period, string $reason, string $by, string $shiftStart,
        string $shiftEnd): array
    {
        $original = $this->find($period);

        if ($original === null) {
            return ['ok' => false, 'why' => "{$period} has not been closed, so there is nothing to "
                . 'correct. Close it first — a correction to a month that never ran is just a run.'];
        }

        if (trim($reason) === '') {
            return ['ok' => false, 'why' => 'A correction with no reason is an unexplained change to a '
                . 'figure somebody was already paid. The reason is the whole point of recording it '
                . 'rather than re-running.'];
        }

        $computed = $this->computeMonth($period, $shiftStart, $shiftEnd);

        if (!$computed['ok']) {
            return $computed;
        }

        $now = $computed['run'];
        $deltas = [];

        foreach ($now['rows'] as $code => $row) {
            $was = $original['rows'][$code] ?? null;
            $wasMinor = $was === null ? 0 : self::netMinor($was);
            $isMinor = self::netMinor($row);

            if ($wasMinor === $isMinor && $was !== null) {
                continue;
            }

            $deltas[$code] = [
                'name' => (string) $row['name'],
                'was' => $was === null ? 'not in the original run' : (string) ($was['net'] ?? '—'),
                'now' => (string) ($row['net'] ?? '—'),
                'delta_minor' => $isMinor - $wasMinor,
                'delta' => Money::ofMinor($isMinor - $wasMinor)->format(),
                'why' => $was === null
                    ? 'this employee was not in the original run, so the whole figure is the '
                        . 'difference — which is not always money owed: an employee with no '
                        . 'payable days and a flat deduction recomputes to a negative net'
                    : 'the recomputation from today\'s records differs from what was paid',
            ];
        }

        // An employee that was paid and has since disappeared is a difference
        // too, and the one most easily lost: it is absent from the new run, so a
        // loop over the new run alone would never see it.
        foreach ($original['rows'] as $code => $was) {
            if (!isset($now['rows'][$code])) {
                $deltas[$code] = [
                    'name' => (string) $was['name'],
                    'was' => (string) ($was['net'] ?? '—'),
                    'now' => 'no longer in the run',
                    'delta_minor' => -self::netMinor($was),
                    'delta' => Money::ofMinor(-self::netMinor($was))->format(),
                    'why' => 'this employee was paid in the original run and is not in the '
                        . 'recomputation — a recovery, and the difference most easily lost, because '
                        . 'nothing in the new run points at it',
                ];
            }
        }

        $netDelta = 0;

        foreach ($deltas as $delta) {
            $netDelta += $delta['delta_minor'];
        }

        $correction = [
            'period' => $period,
            'corrects' => (string) $original['id'],
            'original_fingerprint' => (string) $original['fingerprint'],
            'new_fingerprint' => (string) $now['fingerprint'],
            'raised_at' => date('c'),
            'raised_by' => $by,
            'reason' => $reason,
            'net_delta_minor' => $netDelta,
            'deltas' => $deltas,
        ];

        // `net_delta` — the formatted figure — was written here and is NOT a
        // column: `payroll_corrections` stores `net_delta_minor` and nothing
        // else about the amount. It is derived on read in `corrected()`, so the
        // screen still shows it and there is one stored number rather than two.
        $correction = self::corrected($this->store->put('payroll_corrections', $correction));

        return ['ok' => true, 'correction' => $correction, 'why' => $deltas === []
            ? "{$period} was recomputed and NOTHING CHANGED. The correction is recorded anyway — "
                . '"we checked and it was right" is an answer worth keeping, or the same question '
                . 'gets asked again next month by somebody who cannot tell it was investigated.'
            : count($deltas) . ' employee(s) differ, a net of ' . $correction['net_delta']
                . '. The original run is UNTOUCHED: what was paid is history, and the difference is '
                . 'the only figure anyone acts on.'];
    }

    /** @return list<array<string, mixed>> corrections raised against a period */
    public function correctionsFor(string $period): array
    {
        $discarded = [];
        foreach ($this->store->all('payroll_runs') as $r) {
            if ((string) ($r['status'] ?? '') === self::DISCARDED) {
                $discarded[(string) $r['id']] = true;
            }
        }

        return array_values(array_filter(
            array_map(self::corrected(...), $this->store->all('payroll_corrections')),
            static fn (array $c): bool => (string) $c['period'] === $period
                && !isset($discarded[(string) ($c['corrects'] ?? '')])));
    }

    /**
     * A stored correction with its two derived fields put back: the formatted
     * amount, which is not a column, and `deltas`, which MySQL hands back as the
     * JSON text it stores rather than as the map the screen iterates.
     *
     * @param array<string, mixed> $correction
     * @return array<string, mixed>
     */
    private static function corrected(array $correction): array
    {
        $correction['net_delta'] ??= Money::ofMinor((int) ($correction['net_delta_minor'] ?? 0))
            ->format();
        $correction['deltas'] = self::decoded($correction['deltas'] ?? null);

        return $correction;
    }

    /** @param array<string, mixed> $row */
    private static function netMinor(array $row): int
    {
        return (int) ($row['net_minor'] ?? 0);
    }

    /**
     * Recompute a closed month from its stored inputs and the structure version
     * it used, and compare to the paisa.
     *
     * @return array{reproduced: bool, why: string, fingerprint?: string}
     */
    public function verify(string $period): array
    {
        $stored = $this->find($period);

        if ($stored === null) {
            return ['reproduced' => false, 'why' => "{$period} has not been closed, so there is "
                . 'nothing to reproduce.'];
        }

        $version = null;

        foreach ($this->structure->versions() as $candidate) {
            if ((string) $candidate['effective_from'] === (string) $stored['structure_effective_from']) {
                $version = $candidate;
            }
        }

        if ($version === null) {
            // Stated, not guessed at with the current structure — which would
            // produce a mismatch that looks like a payroll bug.
            return ['reproduced' => false, 'why' => 'The structure version effective from '
                . $stored['structure_effective_from'] . ' is no longer present, so the month CANNOT BE '
                . 'REPRODUCED. This is reported rather than recomputed against the current structure, '
                . 'which would show a difference that looks like a payroll error and is not.'];
        }

        $inputs = array_map(static fn (array $i): array => [
            'employment_id' => (string) $i['employment_id'],
            'gross' => Money::of(str_replace(['₹', ','], '', (string) $i['gross'])),
            // Back through the exact reader, not `(int)`. A stored run is
            // re-verified against its fingerprint, and an int cast here would
            // make a month that PAID 17.5 days re-compute as 17 and report
            // itself as tampered with — or, worse, verify clean having changed
            // what somebody was paid.
            'payable_days' => Rational::fromDecimalString((string) $i['payable_days']),
            'period_days' => (int) $i['period_days'],
        ], (array) $stored['inputs']);

        $flatComponents = Structure::components($version);

        // EACH EMPLOYMENT'S RESOLVED SALARY STRUCTURE RULES, exactly as the
        // close recorded their ids — reconstructed via `ComponentRules::byId()`,
        // never by re-resolving scope against today's employment data, which
        // may have moved somebody to a different branch since (`byId()` exists
        // precisely for this). `component_rule_ids` is absent on a run closed
        // before Salary Structure rules were wired into payroll; those group
        // under '' below and verify exactly as before, off the flat structure
        // alone.
        $rows = (array) ($stored['rows'] ?? []);
        $groups = [];

        foreach ($inputs as $input) {
            $ruleIds = (array) ($rows[$input['employment_id']]['component_rule_ids'] ?? []);
            // As CLOSED, never re-read from today's employee record: somebody
            // whose PF was switched on since must still reproduce without it.
            $off = array_map('strval', (array) ($rows[$input['employment_id']]['statutory_off'] ?? []));
            $variants = (array) ($rows[$input['employment_id']]['component_rule_variants'] ?? []);
            $signature = implode(',', $ruleIds) . ($off === [] ? '' : '|off:' . implode(',', $off))
                . ($variants === [] ? '' : '#' . md5((string) json_encode($variants)));
            $groups[$signature]['rule_ids'] ??= $ruleIds;
            $groups[$signature]['off'] ??= $off;
            $groups[$signature]['variants'] ??= $variants;
            $groups[$signature]['inputs'][] = $input;
        }

        $allPayslips = [];

        foreach ($groups as $group) {
            $resolved = [];

            foreach ($group['rule_ids'] as $id) {
                $rule = $this->componentRules?->byId((string) $id);

                if ($rule === null) {
                    return ['reproduced' => false, 'why' => "Salary Structure rule {$id}, which this "
                        . "month's close priced at least one employment against, no longer exists, so "
                        . 'the month CANNOT BE REPRODUCED. This is reported rather than recomputed some '
                        . 'other way, which would show a difference that looks like a payroll error and '
                        . 'is not.'];
                }

                $resolved[] = $rule;
            }

            $resolved = ComponentRules::withVariants($resolved, (array) ($group['variants'] ?? []));

            $groupComponents = StatutoryApplicability::without(
                $this->componentRules !== null
                    ? $this->componentRules->merge($resolved, $flatComponents)
                    : $flatComponents,
                (array) ($group['off'] ?? []),
            );

            try {
                $groupRun = new PayrollRun($period, $groupComponents, $group['inputs']);
            } catch (\Throwable $e) {
                return ['reproduced' => false, 'why' => 'The recomputation itself failed: ' . $e->getMessage()];
            }

            array_push($allPayslips, ...$groupRun->payslips);
        }

        $now = PayrollRun::fingerprintOf($allPayslips);

        return $now === (string) $stored['fingerprint']
            ? ['reproduced' => true, 'fingerprint' => $now,
                'why' => 'Recomputed from the stored inputs against the structure version (and, where '
                    . 'recorded, the Salary Structure rules) this run used, and every figure on every '
                    . 'line matches to the paisa.']
            : ['reproduced' => false, 'fingerprint' => $now,
                'why' => 'The recomputation produced DIFFERENT figures. The stored run is the one that '
                    . 'was paid; the difference is the thing to investigate, and it is stated here '
                    . 'rather than resolved by overwriting either side.'];
    }

    /**
     * ESI as per Government rules for one payslip — only when the Salary structure produced no ESI line and
     * ESI is not switched off for the employee. Lines carry their own working (law 4).
     *
     * @param list<array<string, mixed>> $lines the payslip lines so far
     * @param list<string> $offHeads statutory heads switched off for this employee
     * @param array<string, mixed> $row the run row (gross = contracted monthly gross, payable_days, period_days)
     * @return list<array{code: string, kind: string, amount: string, minor: int, trace: string}>
     */
    private function governmentEsi(string $code, array $employment, string $period, array $lines, array $offHeads, array $row): array
    {
        static $cfg = null;
        $cfg ??= json_decode((string) @file_get_contents(__DIR__ . '/../config/esi.json'), true) ?: [];

        if ($cfg === [] || in_array('ESI', array_map('strtoupper', $offHeads), true)) {
            return [];
        }

        $bare = static fn (string $c): string => strtoupper((string) preg_replace('/^[A-Z]-/', '', $c));
        $eeCode = (string) $cfg['employee_code'];
        $erCode = (string) $cfg['employer_code'];

        // A share the Salary structure sets (Custom) wins; a share it does not set — or sets
        // "As per Government rules" — is computed here (owner, 16 Oct).
        $setByStructure = [];
        foreach ($lines as $l) {
            in_array($bare((string) $l['code']), [$eeCode, $erCode], true) && $setByStructure[$bare((string) $l['code'])] = true;
        }
        if (isset($setByStructure[$eeCode], $setByStructure[$erCode])) {
            return [];
        }

        $isPwd = in_array((string) ($employment[(string) ($cfg['pwd_field'] ?? '')] ?? ''), (array) ($cfg['pwd_values'] ?? []), true);
        $ceiling = Money::of((string) ($isPwd ? $cfg['wage_ceiling_pwd'] : $cfg['wage_ceiling']));
        $gross = Money::of((string) ($row['gross'] ?? '0'));
        $covered = $gross->compareTo($ceiling) <= 0;
        $why = 'monthly gross ' . $gross->format() . ($covered ? ' within' : ' above') . ' the ESI ceiling ' . $ceiling->format();

        if (!$covered) {
            // Covered earlier in the same contribution period → stays covered to its end.
            $m = (int) substr($period, 5, 2);
            $y = (int) substr($period, 0, 4);
            $starts = array_map('intval', (array) $cfg['contribution_periods_start_months']);
            rsort($starts);
            $startM = 0;
            foreach ($starts as $sm) { if ($m >= $sm) { $startM = $sm; break; } }
            $start = $startM === 0 ? sprintf('%04d-%02d', $y - 1, max($starts)) : sprintf('%04d-%02d', $y, $startM);

            foreach ($this->runs() as $run) {
                $p = (string) ($run['period'] ?? '');

                if ($p >= $start && $p < $period) {
                    foreach ((array) ($run['rows'][$code]['lines'] ?? []) as $l) {
                        if ($bare((string) ($l['code'] ?? '')) === $erCode) {
                            $covered = true;
                        }
                    }
                }
            }

            if (!$covered) {
                return [];
            }

            $why .= ', but covered earlier in the contribution period from ' . $start . ', so continued to its end';
        }

        $exclude = array_map('strtoupper', (array) ($cfg['wages_exclude'] ?? []));
        $wageMinor = 0;

        foreach ($lines as $l) {
            if ((string) ($l['kind'] ?? '') === 'earning' && !in_array($bare((string) $l['code']), $exclude, true)) {
                $wageMinor += (int) $l['minor'];
            }
        }

        if ($wageMinor <= 0) {
            return [];
        }

        $wages = Money::ofMinor($wageMinor);
        $round = static fn (Money $x): Money => ($cfg['round_up_to_rupee'] ?? true) ? $x->roundTo(100, RoundingMode::Ceiling) : $x;
        $pct = static fn (string $p): Rational => Rational::fromDecimalString($p)->dividedBy(Rational::of(100));
        $er = $round($wages->multipliedBy($pct((string) $cfg['employer_percent']), RoundingMode::HalfUp));
        $payable = Rational::fromDecimalString((string) ($row['payable_days'] ?? '0'));
        $daily = $payable->compareTo(Rational::of(0)) > 0 ? $wages->multipliedBy(Rational::of(1)->dividedBy($payable), RoundingMode::HalfUp) : $wages;
        $exempt = $daily->compareTo(Money::of((string) $cfg['daily_wage_exempt_upto'])) <= 0;
        $ee = $exempt ? Money::zero() : $round($wages->multipliedBy($pct((string) $cfg['employee_percent']), RoundingMode::HalfUp));
        $base = 'ESI as per Government rules: ' . $why . '; ESI wages ' . $wages->format();
        $out = [];

        if (!$ee->isZero() && !isset($setByStructure[$eeCode])) {
            $out[] = ['code' => $eeCode, 'kind' => 'deduction', 'amount' => $ee->format(), 'minor' => $ee->minor,
                'trace' => $base . ' × ' . $cfg['employee_percent'] . '% = ' . $ee->format() . ' (rounded up to the rupee)'];
        }

        isset($setByStructure[$erCode]) || $out[] = ['code' => $erCode, 'kind' => 'employer_contribution', 'amount' => $er->format(), 'minor' => $er->minor,
            'trace' => $base . ' × ' . $cfg['employer_percent'] . '% = ' . $er->format() . ($exempt ? '; average daily wage ' . $daily->format() . ' within ' . Money::of((string) $cfg['daily_wage_exempt_upto'])->format() . ', so no employee share' : '')];

        return $out;
    }
}
