<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * RECOVERY-AGENT RBI COMPLIANCE AUDIT — Statutory & Compliance → Audit Reports.
 *
 * A port of SmartPRS 2's ComplianceController::agentAuditData() and
 * ::scoreFor() (owner, 30 Sep: "use PRS2 Audit report format and same logic").
 * Same parameters, same groups, same pass / attention / fail rules, same
 * verdict, same score weights, same reference and hash. Differences are only
 * where SmartPRS 3 keeps the data somewhere else:
 *  - no `dra_certs` table here: the DRA check reads employees.dra_status /
 *    dra_expiry (SmartPRS 2's own fallback), and so does the DRA part of the score;
 *  - the Code of Conduct acknowledgement is keyed by employee_id here, not user_id;
 *  - designation / department are the Organization Setup names (orgValueOf),
 *    passed in by the caller, never the raw column.
 * Every read tolerates a table this installation does not have (counted as
 * nothing on record), as SmartPRS 2's Schema::hasTable() guards did.
 */
final class AgentAudit
{
    public const WINDOW_DAYS = 90; // SmartPRS 2: "approx last 90 days"

    /**
     * @param array<string, mixed> $e the employee row
     * @return array{groups: array<string, list<array{param: string, value: string, state: string, evidence: string}>>,
     *     pass: int, warn: int, fail: int, score: int, verdict: string, ref: string, hash: string, auditFrom: string, auditTo: string}
     */
    public static function of(RowStore $store, array $e, string $today): array
    {
        $id = (string) ($e['id'] ?? '');
        $code = Employment::code($e);
        $groups = [];
        $pass = 0;
        $warn = 0;
        $fail = 0;
        $add = static function (string $group, string $param, string $value, string $state, string $evidence = '') use (&$groups, &$pass, &$warn, &$fail): void {
            $groups[$group][] = ['param' => $param, 'value' => $value, 'state' => $state, 'evidence' => $evidence];
            $state === 'ok' ? $pass++ : ($state === 'warn' ? $warn++ : $fail++);
        };
        $ok = static fn (bool $b): string => $b ? 'ok' : 'bad';
        $v = static fn (string $k): string => trim((string) ($e[$k] ?? ''));
        $mine = static fn (string $table): array => array_values(array_filter(self::rows($store, $table),
            static fn (array $r): bool => (string) ($r['employee_id'] ?? '') === $id));

        // 1 — Identity & KYC
        $photo = $v('photo_path') !== '';
        $add('Identity & KYC', 'Photograph on record', $photo ? 'Yes' : 'Missing', $ok($photo), $photo ? 'ID card file' : '');
        $add('Identity & KYC', 'PAN', $v('pan') !== '' ? $v('pan') : 'Not on record', $ok($v('pan') !== ''), 'Employee master');
        $add('Identity & KYC', 'UAN (PF)', $v('uan') ?: '—', $v('uan') !== '' ? 'ok' : 'warn', 'Employee master');
        $add('Identity & KYC', 'Employee ID card issued', $photo ? 'Yes' : 'Pending', $ok($photo), 'Auto-generated');

        // 2 — DRA certification (IIBF)
        $draExpiry = substr($v('dra_expiry'), 0, 10);
        $draOk = $v('dra_status') === 'verified' && ($draExpiry === '' || $draExpiry >= $today);
        $draNote = $v('dra_status') === 'verified' ? 'Verified' . ($draExpiry !== '' ? ' · valid to ' . $draExpiry : '') : 'Not on record';
        $add('DRA Certification (IIBF)', 'Valid IIBF DRA certificate', $draOk ? 'Held & valid' : 'Not verified', $ok($draOk), $draNote);

        // 3 — PCC / police verification
        $pccExpiry = substr($v('pcc_expiry'), 0, 10);
        $pccOk = in_array($v('pcc_status'), ['verified', 'submitted'], true) && ($pccExpiry === '' || $pccExpiry >= $today);
        $add('Background Verification', 'Police clearance (PCC)', $pccOk ? 'Clear' : ($v('pcc_status') ?: 'Pending'),
            $pccOk ? 'ok' : ($v('pcc_status') === 'pending' ? 'warn' : 'bad'), $pccExpiry !== '' ? 'Valid to ' . $pccExpiry : '');

        // BGV — the latest row
        $bgvRows = $mine('bgv');
        usort($bgvRows, static fn (array $a, array $b): int => (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));
        $bgv = $bgvRows[0] ?? null;
        $bgvStatus = (string) ($bgv['status'] ?? '');
        $bgvClear = $bgv !== null && in_array(strtolower($bgvStatus), ['clear', 'completed', 'verified'], true);
        $add('Background Verification', 'Background verification (BGV)', $bgv !== null ? ucfirst($bgvStatus) : 'Not on record',
            $bgv !== null && $bgvClear ? 'ok' : 'warn', $bgv !== null && trim((string) ($bgv['agency'] ?? '')) !== '' ? 'Agency: ' . $bgv['agency'] : '');
        $nextDue = $bgv === null ? '' : substr(trim((string) ($bgv['next_due'] ?? '')), 0, 10);

        if ($nextDue === '' && $bgv !== null) { // older rows keep it inside the checks JSON ('n')
            $c = json_decode((string) ($bgv['checks'] ?? ''), true);
            $nextDue = is_array($c) ? substr((string) ($c['n'] ?? ''), 0, 10) : '';
        }

        if ($nextDue !== '') {
            $days = (int) ((strtotime($nextDue) - strtotime($today)) / 86400);
            $add('Background Verification', 'Periodic re-verification', 'Next: ' . $nextDue, $nextDue < $today ? 'bad' : ($days <= 30 ? 'warn' : 'ok'), 'Scheduled');
        }

        // References / guarantors (min 2 for field staff)
        $refs = count($mine('employee_references'));
        $add('Background Verification', 'References / guarantors (min 2)', $refs . ' on record', $refs >= 2 ? 'ok' : ($refs === 1 ? 'warn' : 'bad'), 'Employee references');

        // 4 — Code of Conduct acknowledgement
        $coc = array_filter($mine('code_of_conduct_ack'), static fn (array $a): bool => (string) ($a['acknowledged'] ?? '') === '1');
        $add('Code of Conduct', 'Signed undertaking of adherence', $coc !== [] ? 'Acknowledged' : 'Not on record', $coc !== [] ? 'ok' : 'warn', 'Code of Conduct register');

        // 5 — Authorisation / empanelment: the first one still in force
        $live = null;

        foreach ($mine('agent_authorizations') as $a) {
            $to = substr(trim((string) ($a['valid_to'] ?? '')), 0, 10);

            if ($to === '' || $to >= $today) {
                $live = $a;
                break;
            }
        }

        $liveTo = $live === null ? '' : substr(trim((string) ($live['valid_to'] ?? '')), 0, 10);
        $add('Authorisation', 'Bank/portfolio authorisation', $live !== null ? ((string) ($live['bank'] ?? '') . ' — ' . (string) ($live['portfolio'] ?? '')) : 'None on record',
            $live !== null ? 'ok' : 'warn', $live !== null && trim((string) ($live['auth_no'] ?? '')) !== '' ? 'Auth ' . $live['auth_no'] . ($liveTo !== '' ? ' · to ' . $liveTo : '') : '');

        // 6 — Conduct & complaints (last 90 days)
        $from = date('Y-m-d', (int) strtotime($today . ' -' . self::WINDOW_DAYS . ' days'));
        $complaintRows = $mine('complaints');
        $recent = count(array_filter($complaintRows, static fn (array $c): bool
            => !array_key_exists('date', $c) || ((string) $c['date'] !== '' && substr((string) $c['date'], 0, 10) >= $from)));
        $add('Conduct Record', 'Borrower complaints (last 90 days)', (string) $recent, $recent === 0 ? 'ok' : ($recent <= 2 ? 'warn' : 'bad'), 'Complaints register');
        $add('Conduct Record', 'Disciplinary actions', '0', 'ok', 'HR record'); // SmartPRS 2 prints a fixed 0 — no register behind it

        $ref = 'RAC/' . substr($today, 0, 4) . '/' . strtoupper($code);

        return [
            'groups' => $groups, 'pass' => $pass, 'warn' => $warn, 'fail' => $fail,
            'score' => self::score($store, $e, $today, $complaintRows),
            'verdict' => $fail > 0 ? 'NON-COMPLIANT' : ($warn > 0 ? 'COMPLIANT — WITH OPEN ITEMS' : 'COMPLIANT'),
            'ref' => $ref,
            'hash' => substr(hash('sha256', $ref . '|' . $id . '|' . date('c', (int) strtotime($today))), 0, 16),
            'auditFrom' => date('d-m-Y', (int) strtotime($from)),
            'auditTo' => date('d-m-Y', (int) strtotime($today)),
        ];
    }

    /**
     * SmartPRS 2's scoreFor(): DRA 25, PCC 20, BGV 20, signed NDA 15, no open
     * complaints 20 — its own rules, which are stricter than the table's (PCC
     * must be `verified`, BGV must be `clear`, complaints over all time).
     *
     * @param list<array<string, mixed>> $complaintRows this employee's complaints
     */
    public static function score(RowStore $store, array $e, string $today, array $complaintRows): int
    {
        $id = (string) ($e['id'] ?? '');
        $s = 0;
        $draExpiry = substr(trim((string) ($e['dra_expiry'] ?? '')), 0, 10);
        $s += (string) ($e['dra_status'] ?? '') === 'verified' && ($draExpiry === '' || $draExpiry >= $today) ? 25 : 0;
        $pccExpiry = substr(trim((string) ($e['pcc_expiry'] ?? '')), 0, 10);
        $s += (string) ($e['pcc_status'] ?? '') === 'verified' && ($pccExpiry === '' || $pccExpiry >= $today) ? 20 : 0;

        foreach (self::rows($store, 'bgv') as $b) {
            if ((string) ($b['employee_id'] ?? '') === $id && (string) ($b['status'] ?? '') === 'clear') {
                $s += 20;
                break;
            }
        }

        foreach (self::rows($store, 'letters') as $l) {
            if ((string) ($l['employee_id'] ?? '') === $id && (string) ($l['letter_type'] ?? '') === 'nda'
                && (string) ($l['is_template'] ?? '0') !== '1' && (string) ($l['status'] ?? '') === 'signed') {
                $s += 15;
                break;
            }
        }

        $open = array_filter($complaintRows, static fn (array $c): bool => in_array((string) ($c['status'] ?? ''), ['open', 'pending', 'in_progress'], true));
        $s += $open === [] ? 20 : 0;

        return min(100, max(0, $s));
    }

    /** @return list<array<string, mixed>> live rows, or none when the table is absent */
    private static function rows(RowStore $store, string $table): array
    {
        try {
            return array_values(array_filter($store->all($table), static fn (array $r): bool => trim((string) ($r['deleted_at'] ?? '')) === ''));
        } catch (\Throwable) {
            // A table this installation does not have: nothing on record, as SmartPRS 2's Schema::hasTable() guard.
            return [];
        }
    }
}
