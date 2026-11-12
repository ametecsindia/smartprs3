<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;

/**
 * THE ASSET REGISTER'S MOVES: issue, return, repair, retire.
 *
 * The register form (module `assets`) edits the facts of an asset. This class
 * moves its STATUS along config/assets.json's transitions and stamps who did it
 * and when — a laptop that says "assigned" with nobody's name on the stamp is a
 * laptop nobody can ask for back. `heldBy()` is what Exit & FnF clearance asks:
 * what is this person still holding.
 */
final class Assets
{
    /** @var array<string, mixed> */
    private array $config;

    /** The last holder of a returned unit — who owes a damaged/lost return. */
    public const RETURNED_FROM = 'returned_from_employee_id';

    /** @var array<string, bool>|null */
    private ?array $columns = null;

    /** @var array{0: string, 1: string} company id, branch id — '' reads all */
    private array $at = ['', ''];

    public function __construct(private readonly RowStore $store, string $configFile)
    {
        $decoded = json_decode((string) file_get_contents($configFile), true);

        if (!is_array($decoded)) {
            throw new \RuntimeException("{$configFile} is not readable as JSON; the register refuses to guess its statuses.");
        }

        $this->config = $decoded;
    }

    /** @return array<string, mixed> */
    public function declared(): array
    {
        return $this->config;
    }

    /** A row's status; a row saved without one (the register form never sets it) is the initial status. */
    public function statusOf(array $row): string
    {
        $status = trim((string) ($row['status'] ?? ''));

        return $status !== '' ? $status : (string) ($this->config['initial_status'] ?? '');
    }

    /** A status's label from config, or the status itself. */
    public function label(string $status): string
    {
        return (string) ($this->config['statuses'][$status]['label'] ?? $status);
    }

    /** Live rows in one status. @return list<array<string, mixed>> */
    public function inStatus(string $status): array
    {
        return array_values(array_filter($this->live(), fn (array $r): bool => $this->statusOf($r) === $status));
    }

    /**
     * THE STOCK BOOK: per Category + Make/model, how many units sit in each
     * status — COUNTED from the unit rows, never typed, so it cannot drift.
     *
     * @return list<array{category: string, model: string, counts: array<string, int>, total: int}>
     */
    public function stockBook(): array
    {
        $book = [];

        foreach ($this->live() as $r) {
            $category = trim((string) ($r['asset_type'] ?? '')) ?: '—';
            $model = trim((string) ($r['make_model'] ?? '')) ?: (trim((string) ($r['asset_name'] ?? '')) ?: '—');
            $key = strtolower($category . "\0" . $model);
            $book[$key] ??= ['category' => $category, 'model' => $model, 'counts' => [], 'total' => 0];
            $status = $this->statusOf($r);
            $book[$key]['counts'][$status] = ($book[$key]['counts'][$status] ?? 0) + 1;
            $book[$key]['total']++;
        }

        uasort($book, static fn (array $a, array $b): int => [$a['category'], $a['model']] <=> [$b['category'], $b['model']]);

        return array_values($book);
    }

    /** The moves a row in this status may make. @return list<string> */
    public function movesFrom(string $status): array
    {
        $ops = [];

        foreach ((array) $this->config['transitions'] as $op => $t) {
            if (in_array($status, (array) $t['from'], true)) {
                $ops[] = (string) $op;
            }
        }

        return $ops;
    }

    /**
     * @param array<string, mixed> $row the assets row
     * @param array<string, string> $input employee_id, on (date), condition, note
     * @return array{ok: bool, why: string, row?: array<string, mixed>}
     */
    public function move(array $row, string $op, array $input, string $by, string $now): array
    {
        $t = (array) ($this->config['transitions'][$op] ?? []);
        $status = $this->statusOf($row);

        if ($t === [] || !in_array($status, (array) $t['from'], true)) {
            return ['ok' => false, 'why' => (string) ($row['asset_id'] ?? 'This asset') . ' is ' . $this->label($status)
                . ' — it cannot be ' . strtolower((string) ($t['label'] ?? $op)) . 'd now. The screen was out of date; reload it.'];
        }

        $on = trim((string) ($input['on'] ?? ''));

        if ($on !== '' && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) !== 1
            || !checkdate((int) substr($on, 5, 2), (int) substr($on, 8, 2), (int) substr($on, 0, 4)))) {
            return ['ok' => false, 'why' => "'{$on}' is not a date (YYYY-MM-DD)."];
        }

        $on = $on === '' ? substr($now, 0, 10) : $on;
        $condition = trim((string) ($input['condition'] ?? ''));

        if ($condition !== '' && !in_array($condition, (array) $this->config['conditions'], true)) {
            return ['ok' => false, 'why' => "'{$condition}' is not a condition config/assets.json knows."];
        }

        // A RETURN IN A NAMED CONDITION does not go back on the shelf: a damaged
        // unit goes for repair, a lost one is lost — so "available" only ever
        // counts units somebody could actually be handed.
        $to = (string) (((array) ($t['by_condition'] ?? []))[$condition] ?? $t['to']);
        $change = ['id' => $row['id'], 'status' => $to, 'updated_at' => $now];

        if (!empty($t['needs_employee'])) {
            $employeeId = trim((string) ($input['employee_id'] ?? ''));
            $employee = $employeeId === '' ? null : $this->store->find('employees', $employeeId);

            if ($employee === null || Modules::isRetired($employee)) {
                return ['ok' => false, 'why' => 'Pick a live employee to issue the asset to.'];
            }

            // ONLY WITHIN THE EMPLOYEE'S OWN COMPANY AND BRANCH (owner, 30 Sep):
            // config `issue_within` lists the columns the unit and the employee
            // must share; the screen hides the rest, and this refuses a stale
            // or hand-made post.
            foreach ($this->issueWithin() as $col) {
                if (trim((string) ($row[$col] ?? '')) !== trim((string) ($employee[$col] ?? ''))) {
                    return ['ok' => false, 'why' => (string) ($row['asset_id'] ?? 'This asset') . ' belongs to a different '
                        . ($col === 'branch_id' ? 'branch' : ($col === 'employer_company_id' ? 'company' : str_replace('_id', '', $col)))
                        . ' than this employee — only stock at the employee\'s own company and branch can be issued.'];
                }
            }

            // A SECOND LAPTOP/CPU needs a yes (owner, 29 Sep): the screen asks
            // first; without that yes the move is refused, so nobody gets two
            // by a double click or a stale form.
            $already = $this->alreadyHolds($employeeId, (string) ($row['asset_type'] ?? ''), (string) $row['id']);

            if ($already !== null && (string) ($input['reissue_ok'] ?? '') !== '1') {
                return ['ok' => false, 'why' => 'A ' . (string) ($already['asset_type'] ?? 'unit') . ' ('
                    . (string) ($already['make_model'] ?? '') . ', S/N ' . ((string) ($already['serial_no'] ?? '') ?: '—')
                    . ') was already issued to this employee on ' . (string) ($already['issue_date'] ?? '?')
                    . '. Confirm the reissue to give another.'];
            }

            $change += ['issued_to_employee_id' => $employeeId, 'issue_date' => $on, 'issued_by' => $by, 'issued_at' => $now,
                'return_date' => null, 'returned_by' => null, 'returned_at' => null];

            // The issue form asks for a purpose; it was posted and never kept.
            $purpose = trim((string) ($input['purpose'] ?? ''));

            if ($purpose !== '') {
                $change['purpose'] = $purpose;
            }
        } elseif (in_array($status, (array) $this->config['held_statuses'], true) || (string) ($row['issued_to_employee_id'] ?? '') !== '') {
            // Leaving somebody's hands: the return is stamped whatever the move is called.
            $change += ['return_date' => $on, 'returned_by' => $by, 'returned_at' => $now, 'issued_to_employee_id' => null];

            // WHO RETURNED IT: a damaged or lost return is owed by that person
            // until it is settled, and Exit & FnF waits for it (migration
            // 2026_09_29_000200; written only once the column exists).
            if ($this->has(self::RETURNED_FROM) && (string) ($row['issued_to_employee_id'] ?? '') !== '') {
                $change[self::RETURNED_FROM] = (string) $row['issued_to_employee_id'];
            }
        }

        if ($condition !== '') {
            $change['condition'] = $condition;
        }

        // WHAT HAPPENED TO A DAMAGED/LOST ASSET, and WHAT IS OWED FOR IT — two
        // separate questions the return form has always asked (damage_resolution)
        // or now also asks (recovery_amount/recovery_status), neither of which
        // this method used to persist: the form posted them, and they were
        // silently dropped on every save. Found while wiring the Issue Tracker's
        // recovery fields through; fixed here since every caller of a return
        // routes through this one method.
        // Damage / recovery answers belong to a Damaged or Lost return only: for
        // any other condition they are dropped; for those two, a final status is required.
        $badReturn = array_key_exists($condition, (array) ($t['by_condition'] ?? []));

        if (!$badReturn) {
            unset($input['damage_resolution'], $input['recovery_amount'], $input['recovery_status']);
        } elseif (trim((string) ($input['damage_resolution'] ?? '')) === '') {
            return ['ok' => false, 'why' => 'A ' . $condition . ' return needs its final status (' . implode(', ', (array) ($this->config['damage_resolutions'] ?? [])) . ').'];
        }

        $resolution = trim((string) ($input['damage_resolution'] ?? ''));

        if ($resolution !== '') {
            if (!in_array($resolution, (array) ($this->config['damage_resolutions'] ?? []), true)) {
                return ['ok' => false, 'why' => "'{$resolution}' is not a final status config/assets.json knows."];
            }

            $change['damage_resolution'] = $resolution;
        }

        $recoveryStatus = trim((string) ($input['recovery_status'] ?? ''));

        if ($recoveryStatus !== '') {
            if (!in_array($recoveryStatus, (array) ($this->config['recovery_statuses'] ?? []), true)) {
                return ['ok' => false, 'why' => "'{$recoveryStatus}' is not a recovery status config/assets.json knows."];
            }

            $change['recovery_status'] = $recoveryStatus;
        }

        $recoveryAmountRaw = trim((string) ($input['recovery_amount'] ?? ''));

        if ($recoveryAmountRaw !== '') {
            try {
                $change['recovery_amount'] = Amount::of($recoveryAmountRaw)->toDecimalString();
            } catch (\InvalidArgumentException) {
                return ['ok' => false, 'why' => "'{$recoveryAmountRaw}' is not an amount."];
            }
        }

        $note = trim((string) ($input['note'] ?? ''));

        if ($note !== '') {
            $change['notes'] = trim((string) ($row['notes'] ?? '') . "\n[{$on} {$op} by {$by}] {$note}");
        }

        if (($change['recovery_status'] ?? '') === $this->payrollStatus() && !self::positive((string) ($change['recovery_amount'] ?? ''))) {
            return ['ok' => false, 'why' => '"' . $this->payrollStatus() . '" needs a recovery amount above zero.'];
        }

        $stored = $this->store->put((string) $this->config['table'], $change);
        $deducted = $this->syncRecoveryDeduction(array_merge($row, $stored), (string) ($row['issued_to_employee_id'] ?? ''));

        return ['ok' => true, 'why' => (string) $t['label'] . ': ' . (string) ($row['asset_id'] ?? '') . ' is now ' . $this->label($to) . '.' . $deducted, 'row' => $stored];
    }

    /**
     * The unit of the same category this employee already holds, when the
     * category is one config/assets.json asks to confirm before reissuing.
     *
     * @return array<string, mixed>|null
     */
    public function alreadyHolds(string $employeeId, string $category, string $exceptId = ''): ?array
    {
        $watched = array_map('strtolower', array_map('strval', (array) ($this->config['reissue_confirm_categories'] ?? [])));

        if (!in_array(strtolower(trim($category)), $watched, true)) {
            return null;
        }

        foreach ($this->heldBy($employeeId) as $held) {
            if ((string) $held['id'] !== $exceptId && strtolower(trim((string) ($held['asset_type'] ?? ''))) === strtolower(trim($category))) {
                return $held;
            }
        }

        return null;
    }

    /**
     * Columns a unit must share with the employee it is issued to
     * (config `issue_within`, e.g. company and branch).
     *
     * @return list<string>
     */
    public function issueWithin(): array
    {
        return array_values(array_filter(array_map('strval', (array) ($this->config['issue_within'] ?? [])), static fn (string $c): bool => preg_match('/^[a-z_]+$/', $c) === 1));
    }

    /**
     * MOVE A UNIT TO ANOTHER COMPANY / BRANCH (owner, 30 Sep). Only a unit in
     * one of config `transfer.from` (on the shelf, in repair) can move — an
     * issued unit comes back first. The branch must belong to the company. The
     * old and new place are written onto the unit's notes; the caller audits.
     *
     * @return array{ok: bool, why: string, row?: array<string, mixed>}
     */
    public function transfer(array $row, string $companyId, string $branchId, string $note, string $by, string $now): array
    {
        $tag = (string) ($row['asset_id'] ?? 'This asset');
        $status = $this->statusOf($row);
        $from = (array) ($this->config['transfer']['from'] ?? []);

        if (!in_array($status, $from, true)) {
            return ['ok' => false, 'why' => $tag . ' is ' . $this->label($status) . ' — only '
                . implode(' / ', array_map(fn (string $s): string => $this->label($s), $from)) . ' items can be transferred.'];
        }

        $companyId = trim($companyId);
        $branchId = trim($branchId);
        $withBranch = $this->has('branch_id');

        if ($companyId === '' || ($withBranch && $branchId === '')) {
            return ['ok' => false, 'why' => 'Pick the company' . ($withBranch ? ' and branch' : '') . ' to transfer to.'];
        }

        if ($withBranch) {
            $branch = $this->store->find('branches', $branchId);

            if ($branch === null || Modules::isRetired($branch)
                || (string) ($branch['employer_company_id'] ?? $branch['company_id'] ?? '') !== $companyId) {
                return ['ok' => false, 'why' => 'That branch does not belong to the company picked.'];
            }
        }

        $oldCo = trim((string) ($row['employer_company_id'] ?? ''));
        $oldBr = trim((string) ($row['branch_id'] ?? ''));

        if ($oldCo === $companyId && (!$withBranch || $oldBr === $branchId)) {
            return ['ok' => false, 'why' => $tag . ' is already at that company' . ($withBranch ? ' and branch' : '') . '.'];
        }

        $change = ['id' => $row['id'], 'employer_company_id' => $companyId, 'updated_at' => $now];

        if ($withBranch) {
            $change['branch_id'] = $branchId;
        }

        $line = '[' . substr($now, 0, 10) . ' transfer by ' . $by . '] from ' . ($oldCo ?: '—') . '/' . ($oldBr ?: '—')
            . ' to ' . $companyId . '/' . ($branchId ?: '—') . (trim($note) !== '' ? ': ' . trim($note) : '');
        $change['notes'] = trim((string) ($row['notes'] ?? '') . "\n" . $line);

        return ['ok' => true, 'why' => $tag . ' transferred.', 'row' => $this->store->put((string) $this->config['table'], $change)];
    }

    /** Does the assets table have this column yet (a pending migration leaves it out)? */
    public function has(string $column): bool
    {
        if ($this->columns === null) {
            try {
                $this->columns = array_fill_keys($this->store->columnsOf((string) $this->config['table']), true);
            } catch (\Throwable) {
                $this->columns = []; // no assets table on this installation: no columns
            }
        }

        return isset($this->columns[$column]);
    }

    /** A Damaged/Lost return whose final status and recovery are closed, per config `recovery_settled`. */
    public function isSettled(array $row): bool
    {
        $rule = (array) ($this->config['recovery_settled'] ?? []);

        if (!in_array((string) ($row['damage_resolution'] ?? ''), (array) ($rule['resolutions'] ?? []), true)) {
            return false;
        }

        $amount = trim((string) ($row['recovery_amount'] ?? ''));

        // Nothing charged (blank, zero or negative) needs no recovery status — a string test, no float (Law 7).
        return $amount === '' || str_starts_with($amount, '-') || preg_match('/^0*(\.0*)?$/', $amount) === 1
            || in_array((string) ($row['recovery_status'] ?? ''), (array) ($rule['statuses'] ?? []), true);
    }

    /**
     * WHAT HOLDS AN EMPLOYEE'S EXIT: units they still hold, and units they
     * returned Damaged or Lost whose recovery is not settled.
     *
     * @return array{held: list<array<string, mixed>>, unsettled: list<array<string, mixed>>}
     */
    public function outstandingFor(string $employeeId): array
    {
        $bad = array_keys((array) ($this->config['transitions']['return']['by_condition'] ?? []));
        $unsettled = [];

        if ($employeeId !== '' && $this->has(self::RETURNED_FROM)) {
            try {
                $all = $this->store->all((string) $this->config['table']);
            } catch (\Throwable) {
                $all = []; // no assets table: nothing outstanding
            }

            foreach ($all as $r) {
                if ((string) ($r[self::RETURNED_FROM] ?? '') === $employeeId && (string) ($r['deleted_at'] ?? '') === ''
                    && in_array((string) ($r['condition'] ?? ''), $bad, true) && !$this->isSettled($r)) {
                    $unsettled[] = $r;
                }
            }
        }

        return ['held' => $this->heldBy($employeeId), 'unsettled' => $unsettled];
    }

    /** One line per thing holding the exit; empty = assets are clear. @return list<string> */
    public function exitBlockers(string $employeeId): array
    {
        $o = $this->outstandingFor($employeeId);
        $lines = [];

        foreach ($o['held'] as $r) {
            $lines[] = (string) ($r['asset_id'] ?? '') . ' (' . (string) ($r['asset_type'] ?? '') . ') not returned';
        }

        foreach ($o['unsettled'] as $r) {
            $lines[] = (string) ($r['asset_id'] ?? '') . ' returned ' . (string) ($r['condition'] ?? '') . ' — recovery not settled';
        }

        return $lines;
    }

    /**
     * SETTLE A DAMAGED/LOST RETURN after the fact: final status, amount,
     * recovery status — the same vocabulary the Return form uses.
     *
     * @return array{ok: bool, why: string}
     */
    public function settleRecovery(array $row, array $input, string $by, string $now): array
    {
        $bad = array_keys((array) ($this->config['transitions']['return']['by_condition'] ?? []));

        if (!in_array((string) ($row['condition'] ?? ''), $bad, true) || (string) ($row[self::RETURNED_FROM] ?? '') === '') {
            return ['ok' => false, 'why' => (string) ($row['asset_id'] ?? 'This asset') . ' was not returned damaged or lost; there is no recovery to settle.'];
        }

        $resolution = trim((string) ($input['damage_resolution'] ?? ''));
        $status = trim((string) ($input['recovery_status'] ?? ''));
        $amountRaw = trim((string) ($input['recovery_amount'] ?? ''));

        if (!in_array($resolution, (array) ($this->config['damage_resolutions'] ?? []), true)) {
            return ['ok' => false, 'why' => 'Pick the final status (' . implode(', ', (array) ($this->config['damage_resolutions'] ?? [])) . ').'];
        }

        if ($status !== '' && !in_array($status, (array) ($this->config['recovery_statuses'] ?? []), true)) {
            return ['ok' => false, 'why' => "'{$status}' is not a recovery status config/assets.json knows."];
        }

        $change = ['id' => $row['id'], 'damage_resolution' => $resolution, 'recovery_status' => $status === '' ? null : $status, 'updated_at' => $now];

        if ($amountRaw !== '') {
            try {
                $change['recovery_amount'] = Amount::of($amountRaw)->toDecimalString();
            } catch (\InvalidArgumentException) {
                return ['ok' => false, 'why' => "'{$amountRaw}' is not an amount."];
            }
        }

        $change['notes'] = trim((string) ($row['notes'] ?? '') . "\n[" . substr($now, 0, 10) . " recovery by {$by}] {$resolution}"
            . ($amountRaw !== '' ? " · {$amountRaw}" : '') . ($status !== '' ? " · {$status}" : ''));
        if ($status === $this->payrollStatus() && !self::positive((string) ($change['recovery_amount'] ?? $row['recovery_amount'] ?? ''))) {
            return ['ok' => false, 'why' => '"' . $this->payrollStatus() . '" needs a recovery amount above zero.'];
        }

        $stored = $this->store->put((string) $this->config['table'], $change);
        $deducted = $this->syncRecoveryDeduction(array_merge($row, $stored), (string) ($row[self::RETURNED_FROM] ?? ''));

        return ['ok' => true, 'why' => (string) ($row['asset_id'] ?? '') . ': ' . ($this->isSettled($stored) ? 'recovery settled.' : 'recovery updated, still open.') . $deducted];
    }

    /** The recovery status that sends the amount to payroll (config `recovery_payroll.status`). */
    public function payrollStatus(): string
    {
        return (string) ($this->config['recovery_payroll']['status'] ?? '');
    }

    private static function positive(string $amount): bool
    {
        $amount = trim($amount);

        return $amount !== '' && !str_starts_with($amount, '-') && preg_match('/^0*(\.0*)?$/', $amount) !== 1;
    }

    /**
     * ASSET DAMAGE RECOVERY INTO PAYROLL (owner, 22 Oct 2026). One Deductions Ledger row per damaged/lost return,
     * linked by its Reference "[asset#<unit id>@<return date>]": created when the recovery status is the payroll
     * status, amount kept in step while it is still Pending, waived when the status moves to anything else. An
     * Applied row is never touched. Returns a sentence for the screen ('' when nothing changed).
     *
     * @param array<string, mixed> $unit the unit as stored after this save
     */
    private function syncRecoveryDeduction(array $unit, string $employeeId): string
    {
        $status = $this->payrollStatus();
        $returned = (string) ($unit['return_date'] ?? '');

        if ($status === '' || $employeeId === '' || $returned === '') {
            return '';
        }

        $marker = '[asset#' . (string) $unit['id'] . '@' . $returned . ']';
        $existing = null;

        try {
            foreach ($this->store->all('deductions') as $d) {
                if (str_contains((string) ($d['source_ref'] ?? ''), $marker)) {
                    $existing = $d;
                    break;
                }
            }
        } catch (\Throwable) {
            return ' The Deductions Ledger is not available, so nothing was sent to payroll.';
        }

        $amount = trim((string) ($unit['recovery_amount'] ?? ''));
        $wanted = (string) ($unit['recovery_status'] ?? '') === $status && self::positive($amount);
        $pending = $existing !== null && strcasecmp((string) ($existing['status'] ?? ''), 'Pending') === 0;

        if (!$wanted) {
            if ($pending) {
                $this->store->put('deductions', ['id' => $existing['id'], 'status' => 'Waived']);

                return ' The pending Asset Damage Recovery deduction for ' . (string) ($existing['month'] ?? '') . ' was withdrawn.';
            }

            return '';
        }

        if ($existing !== null && !$pending) {
            return ''; // already Applied (or Waived by hand): history is not rewritten
        }

        $month = $existing['month'] ?? $this->nextPayMonth(substr($returned, 0, 7));
        $this->store->put('deductions', array_filter([
            'id' => $existing['id'] ?? null,
            'employee_id' => $employeeId,
            'type' => (string) ($this->config['recovery_payroll']['deduction_type'] ?? 'Asset Damage Recovery'),
            'amount' => Amount::of($amount)->toDecimalString(),
            'month' => $month,
            'source_ref' => (string) ($unit['asset_id'] ?? '') . ' ' . (string) ($unit['asset_type'] ?? '') . ' returned '
                . (string) ($unit['condition'] ?? '') . ' on ' . Dates::dmy($returned) . ' ' . $marker,
            'status' => 'Pending',
        ], static fn ($v): bool => $v !== null));

        return ' Asset Damage Recovery of ' . Amount::of($amount)->format() . ' will be deducted in the ' . $month . ' payroll.';
    }

    /**
     * PAYROLL APPROVED → RECOVERY SETTLED (owner, 22 Oct 2026). When a month's payroll is fully approved, every
     * Asset Damage Recovery deduction the run actually took (its payslip line ASSET_DAMAGE_RECOVERY names the ledger
     * row "#id") becomes Applied and its asset's recovery status Paid; one withdrawn (Waived) for that month marks
     * the asset Waived off. A deduction for somebody not in the run stays Pending — nothing is marked unpaid as paid.
     *
     * @param array<string, mixed> $run the approved run (Register::approve()['run'])
     * @return array{paid: list<string>, waived: list<string>} asset IDs
     */
    public function settleFromApprovedRun(array $run, string $by): array
    {
        $out = ['paid' => [], 'waived' => []];
        $type = (string) ($this->config['recovery_payroll']['deduction_type'] ?? 'Asset Damage Recovery');
        $period = substr((string) ($run['period'] ?? ''), 0, 7);
        $taken = [];

        foreach ((array) ($run['rows'] ?? []) as $row) {
            foreach ((array) ($row['lines'] ?? []) as $line) {
                if ((string) ($line['code'] ?? '') === 'ASSET_DAMAGE_RECOVERY' && preg_match('/#(\d+)/', (string) ($line['trace'] ?? ''), $m) === 1) {
                    $taken[$m[1]] = true;
                }
            }
        }

        try {
            $ledger = $this->store->all('deductions');
        } catch (\Throwable) {
            return $out;
        }

        foreach ($ledger as $d) {
            if ((string) ($d['type'] ?? '') !== $type || substr((string) ($d['month'] ?? ''), 0, 7) !== $period
                || preg_match('/\[asset#(\d+)@(\d{4}-\d{2}-\d{2})\]/', (string) ($d['source_ref'] ?? ''), $m) !== 1) {
                continue;
            }

            $paid = isset($taken[(string) $d['id']]);
            $waived = strcasecmp((string) ($d['status'] ?? ''), 'Waived') === 0;

            if (!$paid && !$waived) {
                continue;
            }

            $paid && strcasecmp((string) ($d['status'] ?? ''), 'Applied') !== 0 && $this->store->put('deductions', ['id' => $d['id'], 'status' => 'Applied']);
            $unit = $this->store->find((string) $this->config['table'], $m[1]);

            if ($unit === null || (string) ($unit['return_date'] ?? '') !== $m[2] || (string) ($unit['recovery_status'] ?? '') !== $this->payrollStatus()) {
                continue; // that return is no longer the unit's latest, or its status was already changed by hand
            }

            $to = $paid ? 'Paid' : 'Waived off';
            $this->store->put((string) $this->config['table'], ['id' => $unit['id'], 'recovery_status' => $to,
                'notes' => trim((string) ($unit['notes'] ?? '') . "\n[" . date('Y-m-d') . " {$period} payroll approved by {$by}] recovery {$to}")]);
            $out[$paid ? 'paid' : 'waived'][] = (string) ($unit['asset_id'] ?? $unit['id']);
        }

        return $out;
    }

    /**
     * AN APPROVED PAYROLL DELETED (owner, 22 Oct 2026): the Asset Damage Recovery deductions it applied go back to
     * Pending (the regenerated month takes them again) and their assets back to the payroll-pending status — only
     * those this payroll had marked Paid, never one changed by hand.
     *
     * @return list<string> asset IDs put back
     */
    public function undoSettledByPayroll(string $period): array
    {
        $type = (string) ($this->config['recovery_payroll']['deduction_type'] ?? 'Asset Damage Recovery');
        $back = [];

        try {
            $ledger = $this->store->all('deductions');
        } catch (\Throwable) {
            return [];
        }

        foreach ($ledger as $d) {
            if ((string) ($d['type'] ?? '') !== $type || substr((string) ($d['month'] ?? ''), 0, 7) !== $period
                || strcasecmp((string) ($d['status'] ?? ''), 'Applied') !== 0
                || preg_match('/\[asset#(\d+)@(\d{4}-\d{2}-\d{2})\]/', (string) ($d['source_ref'] ?? ''), $m) !== 1) {
                continue;
            }

            $this->store->put('deductions', ['id' => $d['id'], 'status' => 'Pending']);
            $unit = $this->store->find((string) $this->config['table'], $m[1]);

            if ($unit !== null && (string) ($unit['return_date'] ?? '') === $m[2] && (string) ($unit['recovery_status'] ?? '') === 'Paid'
                && str_contains((string) ($unit['notes'] ?? ''), $period . ' payroll approved')) {
                $this->store->put((string) $this->config['table'], ['id' => $unit['id'], 'recovery_status' => $this->payrollStatus(),
                    'notes' => trim((string) ($unit['notes'] ?? '') . "\n[" . date('Y-m-d') . " {$period} payroll deleted] recovery back to " . $this->payrollStatus())]);
                $back[] = (string) ($unit['asset_id'] ?? $unit['id']);
            }
        }

        return $back;
    }

    /** The first month from $month on with no payroll run yet (a closed month is not re-opened). */
    private function nextPayMonth(string $month): string
    {
        $closed = [];

        try {
            foreach ($this->store->all('payroll_runs') as $r) {
                if (strtolower((string) ($r['status'] ?? '')) !== 'discarded') {
                    $closed[substr((string) ($r['period'] ?? $r['cycle_label'] ?? ''), 0, 7)] = true;
                }
            }
        } catch (\Throwable) {
            // no payroll yet: the return month itself
        }

        for ($i = 0; $i < 36 && isset($closed[$month]); $i++) {
            $month = date('Y-m', strtotime($month . '-01 +1 month'));
        }

        return $month;
    }

    /** Live assets in a held status, for one employee. @return list<array<string, mixed>> */
    public function heldBy(string $employeeId): array
    {
        return array_values(array_filter($this->live(), fn (array $a): bool => (string) ($a['issued_to_employee_id'] ?? '') === $employeeId
            && in_array($this->statusOf($a), (array) $this->config['held_statuses'], true)));
    }

    /** Narrow every read to one company and/or branch ('' = all). Returns $this. */
    public function onlyAt(string $companyId, string $branchId = ''): self
    {
        $this->at = [$companyId, $branchId];

        return $this;
    }

    /** @return list<array<string, mixed>> */
    public function live(): array
    {
        [$co, $br] = $this->at;

        try {
            return array_values(array_filter($this->store->all((string) $this->config['table']), static fn (array $r): bool => !Modules::isRetired($r)
                && ($co === '' || (string) ($r['employer_company_id'] ?? '') === $co)
                && ($br === '' || (string) ($r['branch_id'] ?? '') === $br)));
        } catch (\Throwable) {
            return []; // no assets table on this installation
        }
    }

    /** Sum of `cost` over rows, exact. @param list<array<string, mixed>> $rows */
    public static function cost(array $rows): Money
    {
        $sum = Money::zero();

        foreach ($rows as $r) {
            try {
                $sum = $sum->plus(Amount::of($r['cost'] ?? ''));
            } catch (\InvalidArgumentException) { // gate:allow-swallow a cost typed as words on a ported row adds nothing; the row still lists
            }
        }

        return $sum;
    }
}
