<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * BACKGROUND VERIFICATION: ONE ROW PER EMPLOYMENT, ONE OUTCOME PER CHECK, A DUE DATE.
 *
 * SmartPRS 2's `bgv` screen: identity, address, education, employee, criminal,
 * references — each Pending / Clear / Flag / N/A — an overall status, the agency,
 * when it was verified and when it is due again. The ported `bgv` table keeps
 * the per-check outcomes in its `checks` JSON; the checks themselves are
 * config/bgv.json, so a seventh check is an edit there.
 *
 * THE OVERALL STATUS IS DERIVED unless somebody overrides it: any Flag →
 * discrepancy; all Clear/N/A → clear; some Pending → in progress; nothing done →
 * pending. Derived, because a row that says "clear" over a flagged check is the
 * row an auditor finds.
 */
final class Bgv
{
    public const COLLECTION = 'bgv';

    public function __construct(private readonly RowStore $store, private readonly string $configFile)
    {
    }

    /** @return array{checks: array<string,string>, outcomes: array<string,string>, statuses: array<string,string>, default_revalidate_months: int} */
    public function declared(): array
    {
        $d = json_decode((string) file_get_contents($this->configFile), true);

        if (!is_array($d) || !is_array($d['checks'] ?? null)) {
            throw new \RuntimeException("config/bgv.json is missing or unreadable at {$this->configFile}.");
        }

        return ['checks' => (array) $d['checks'], 'outcomes' => (array) $d['outcomes'], 'statuses' => (array) $d['statuses'],
            'default_revalidate_months' => (int) ($d['default_revalidate_months'] ?? 12)];
    }

    private function hasBranch(): bool
    {
        try {
            return in_array('branch_id', $this->store->columnsOf(self::COLLECTION), true);
        } catch (\Throwable) {
            return false; // a store that cannot list its columns has no branch column to write
        }
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $rows = array_map(self::decoded(...), $this->store->all(self::COLLECTION));
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? $b['created_at'] ?? ''), (string) ($a['updated_at'] ?? $a['created_at'] ?? '')));

        return array_values($rows);
    }

    public function forEmployment(string $employeeId): ?array
    {
        foreach ($this->all() as $r) {
            if ((string) ($r['employee_id'] ?? '') === $employeeId) {
                return $r;
            }
        }

        return null;
    }

    /** @return array{ok: bool, why: string, row?: array<string, mixed>} */
    public function save(array $employment, array $input, string $by, string $today): array
    {
        $d = $this->declared();
        $checks = [];

        foreach (array_keys($d['checks']) as $k) {
            $v = (string) ($input['chk_' . $k] ?? 'pending');

            if (!isset($d['outcomes'][$v])) {
                return ['ok' => false, 'why' => "'{$v}' is not an outcome for {$d['checks'][$k]}."];
            }

            $checks[$k] = $v;
        }

        $months = trim((string) ($input['revalidate_months'] ?? ''));
        $months = $months === '' ? $d['default_revalidate_months'] : $months;

        if (!ctype_digit((string) $months) || (int) $months < 1 || (int) $months > 120) {
            return ['ok' => false, 'why' => 'Re-verify every 1 to 120 months.'];
        }

        $verifiedOn = trim((string) ($input['verified_on'] ?? ''));

        if ($verifiedOn !== '' && \DateTimeImmutable::createFromFormat('!Y-m-d', $verifiedOn) === false) {
            return ['ok' => false, 'why' => "'{$verifiedOn}' is not a date."];
        }

        $derived = self::derive($checks);
        $status = (string) ($input['status'] ?? '');
        $status = $status !== '' && isset($d['statuses'][$status]) ? $status : $derived;
        $nextDue = $verifiedOn === '' ? null : date('Y-m-d', strtotime($verifiedOn . ' +' . (int) $months . ' months'));
        $existing = $this->forEmployment((string) $employment['id']);

        $put = [
            'id' => $existing['id'] ?? null,
            'employee_id' => (string) $employment['id'],
            'employer_company_id' => (string) ($employment['employer_company_id'] ?? '') ?: null,
            // THE EMPLOYEE'S BRANCH (owner, 21 Oct 2026), once migration 2026_10_21_000100 has added the column.
            'branch_id' => $this->hasBranch() ? ((string) ($employment['branch_id'] ?? '') ?: null) : null,
            'agency' => trim((string) ($input['agency'] ?? '')) ?: null,
            // AGENCY EMAIL (owner, 22 Oct 2026; migration 2026_10_22_000100) — only when the form sends it.
            ...(array_key_exists('agency_email', $input) ? ['agency_email' => trim((string) $input['agency_email']) ?: null] : []),
            // THE REAL COLUMNS SmartPRS 2 has — one per check, verified_on, the
            // interval and the due date — so the register screen (Modules) and
            // Compliance Alerts read the same cells this desk writes. The ported
            // `checks` VARCHAR(255) keeps only what has no column: the derived
            // status beside an override, and a short note.
            'checks' => json_encode(['d' => $derived, 't' => mb_substr(trim((string) ($input['notes'] ?? '')), 0, 120)]),
            'status' => $status,
            'completed_on' => $verifiedOn !== '' ? $verifiedOn : null,
            'verified_on' => $verifiedOn !== '' ? $verifiedOn : null,
            'revalidate_months' => (int) $months,
            'next_due' => $nextDue,
        ];

        foreach ($checks as $k => $v) {
            $put['chk_' . $k] = $v;
        }

        $row = $this->store->put(self::COLLECTION, array_filter($put, static fn ($v): bool => $v !== null));

        return ['ok' => true, 'row' => self::decoded($row), 'why' => 'BGV saved for ' . Employment::label($employment) . ': ' . ($d['statuses'][$status] ?? $status)
            . ($status !== $derived ? " (the checks say {$d['statuses'][$derived]}; status overridden)" : '') . ($nextDue !== null ? ", next due {$nextDue}" : '') . '.'];
    }

    /** Rows whose re-verification is due on or before a date. @return list<array<string, mixed>> */
    public function due(string $onOrBefore): array
    {
        return array_values(array_filter($this->all(), static fn (array $r): bool => (string) ($r['next_due'] ?? '') !== '' && $r['next_due'] <= $onOrBefore));
    }

    public static function derive(array $checks): string
    {
        if (in_array('flag', $checks, true)) {
            return 'discrepancy';
        }

        $answered = array_filter($checks, static fn (string $v): bool => $v !== 'pending');

        if ($answered === []) {
            return 'pending';
        }

        return count($answered) === count($checks) ? 'clear' : 'in_progress';
    }

    private static function decoded(array $row): array
    {
        $c = json_decode((string) ($row['checks'] ?? ''), true);
        $c = is_array($c) ? $c : [];
        $long = ['p' => 'pending', 'c' => 'clear', 'f' => 'flag', 'n' => 'na'];
        // Rows saved before the columns existed carry their outcomes in the JSON ('o').
        $outcomes = array_map(static fn ($v): string => $long[(string) $v] ?? (string) $v, (array) ($c['o'] ?? []));

        foreach ($row as $col => $v) {
            if (str_starts_with((string) $col, 'chk_') && (string) $v !== '') {
                $outcomes[substr((string) $col, 4)] = (string) $v;
            }
        }

        $row['outcomes'] = $outcomes;
        $row['derived_status'] = (string) ($c['d'] ?? self::derive($outcomes));
        $row['revalidate_months'] = (int) (($row['revalidate_months'] ?? 0) ?: ($c['m'] ?? 0));
        $row['next_due'] = (string) (($row['next_due'] ?? '') ?: ($c['n'] ?? ''));
        $row['completed_on'] = (string) (($row['completed_on'] ?? '') ?: ($row['verified_on'] ?? ''));
        $row['notes'] = (string) ($c['t'] ?? '');

        return $row;
    }
}
