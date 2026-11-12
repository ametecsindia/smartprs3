<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * EVERY MACHINE PUNCH COMES IN HERE — SmartPRS 2's PunchIngestService +
 * ETimeOfficeService::import, one door for: the Smart Biometric Bridge
 * (/api/v1/attendance/punches), SmartEPT's webhook, the field-force app,
 * ZKTeco/eSSL ADMS push (/iclock) and the eTimeOffice / eTimeTrackLite /
 * Generic pulls. Lands in `attendance_logs`, the table `Attendance` settles.
 *
 * Same three promises as SmartPRS 2:
 *   - NEVER TWICE. Each punch's external_id is claimed in `punch_receipts`
 *     (unique per tenant) before it is written; a retry is a "duplicate".
 *   - NEVER GUESSED. A device id that is nobody is HELD in `attendance_pending`
 *     and counted in `biometric_unmapped` until HR maps it (Biometric Mapping).
 *   - DIRECTION: in/out as sent; "unknown" (or a single-reader device) becomes
 *     the opposite of that person's previous punch that day, a repeat within
 *     60 seconds keeping the same direction.
 *
 * ponytail: the "previous punch that day" read is a per-employee-day SELECT on
 * attendance_logs; add an (tenant_id, emp_code, log_date) index if a tenant's
 * punch table gets large.
 */
final class PunchIngest
{
    public const MAX_BATCH = 1000;
    private const REPEAT_SECONDS = 60;

    /** @var array<string, string>|null lower(emp_code) => code */
    private ?array $byCode = null;
    /** @var array<string, list<string>>|null lower(device_user_id) => codes */
    private ?array $byDevice = null;
    /** @var array<string, string> code => name */
    private array $names = [];
    /** @var array<string, list<array{0: string, 1: string}>> code|day => [[at, dir]] */
    private array $dayPunches = [];

    public function __construct(
        private readonly RowStore $store,
        private readonly \PDO $pdo,
        private readonly string $tenantId,
    ) {
    }

    /** SmartPRS 2's SBB contract: required fields, sizes, IN/OUT/UNKNOWN. Null = fine. */
    public static function invalid(array $p): ?string
    {
        foreach (['external_id' => 96, 'device_sn' => 64, 'device_user_id' => 64, 'punch_at' => 32, 'direction' => 16] as $f => $max) {
            $v = $p[$f] ?? null;

            if (!is_scalar($v) || trim((string) $v) === '' || strlen(trim((string) $v)) > $max) {
                return 'VALIDATION';
            }
        }

        foreach (['employee_code' => 64, 'verify_mode' => 24, 'device_status_raw' => 8] as $f => $max) {
            $v = $p[$f] ?? null;

            if ($v !== null && (!is_scalar($v) || strlen(trim((string) $v)) > $max)) {
                return 'VALIDATION';
            }
        }

        return in_array(strtoupper(trim((string) $p['direction'])), ['IN', 'OUT', 'UNKNOWN'], true) ? null : 'VALIDATION';
    }

    /** "2026-10-16 09:01:00" exactly, no zone — SmartPRS 2's parseNaiveLocal. */
    public static function naive(string $v): ?string
    {
        $v = trim($v);

        if (preg_match('~^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$~', $v) !== 1) {
            return null;
        }

        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $v);

        return $d !== false && $d->format('Y-m-d H:i:s') === $v ? $v : null;
    }

    /**
     * @param list<array<string, mixed>> $punches each: punch_at (Y-m-d H:i:s), device_user_id,
     *        and optionally external_id, device_sn, direction (in|out|unknown), employee_code, verify_mode
     * @param array<string, mixed> $cfg emp_prefix, device_mode ('single' re-sequences)
     * @return array{batch: array<string, int>, results: list<array<string, mixed>>}
     */
    public function ingest(array $punches, string $source, array $cfg = []): array
    {
        $counts = ['received' => count($punches), 'accepted' => 0, 'duplicates' => 0, 'pending' => 0, 'rejected' => 0];
        $results = [];
        $unmapped = [];
        $prefix = trim((string) ($cfg['emp_prefix'] ?? ''));
        $single = strtolower(trim((string) ($cfg['device_mode'] ?? ''))) === 'single';
        usort($punches, static fn (array $a, array $b): int => strcmp((string) ($a['punch_at'] ?? ''), (string) ($b['punch_at'] ?? '')));

        foreach ($punches as $p) {
            $at = self::naive((string) ($p['punch_at'] ?? ''));
            $sn = mb_substr(trim((string) ($p['device_sn'] ?? '')), 0, 64);
            $user = mb_substr(trim((string) ($p['device_user_id'] ?? '')), 0, 64);
            $ext = mb_substr(trim((string) ($p['external_id'] ?? '')), 0, 120);

            if ($at === null || $user === '') {
                $results[] = ['external_id' => $ext ?: null, 'status' => 'rejected', 'reason' => $at === null ? 'TIME_FORMAT' : 'VALIDATION'];
                $counts['rejected']++;
                continue;
            }

            $dir = strtolower(trim((string) ($p['direction'] ?? 'unknown')));
            $dir = in_array($dir, ['in', 'out'], true) ? $dir : 'unknown';
            $ext = $ext !== '' ? $ext : 'punch:' . ($sn ?: '-') . ':' . $user . ':' . $at . ':' . $dir;
            $given = trim((string) ($p['employee_code'] ?? ''));
            $full = $given !== '' ? $given : $prefix . $user;
            $code = $this->match($given, $prefix . $user, $user);

            if (!$this->claim($ext)) {
                $results[] = ['external_id' => $ext, 'status' => 'duplicate'];
                $counts['duplicates']++;
                continue;
            }

            if ($code === null) {
                $this->hold(['device_sn' => $sn ?: null, 'device_user_id' => $user, 'device_code' => $full, 'punch_at' => $at,
                    'direction' => $dir === 'unknown' ? null : $dir, 'verify_mode' => $p['verify_mode'] ?? null,
                    'external_id' => $ext, 'source' => $source]);
                $unmapped[$full] = ($unmapped[$full] ?? 0) + 1;
                $results[] = ['external_id' => $ext, 'status' => 'pending', 'reason' => 'EMPLOYEE_NOT_MAPPED'];
                $counts['pending']++;
                continue;
            }

            if ($dir === 'unknown' || $single) {
                $dir = $this->sequenced($code, $at);
            }

            if (!$this->write($code, $at, $dir, $source, $sn, $user, $ext, $p['verify_mode'] ?? null)) {
                $results[] = ['external_id' => $ext, 'status' => 'duplicate'];
                $counts['duplicates']++;
                continue;
            }

            $results[] = ['external_id' => $ext, 'status' => 'accepted'];
            $counts['accepted']++;
        }

        $this->countUnmapped($unmapped, $source);

        return ['batch' => $counts, 'results' => $results];
    }

    /** The employment code a device id belongs to, or null (never a guess). */
    public function match(string $given, string $full, string $raw): ?string
    {
        if ($this->byCode === null) {
            $this->byCode = [];
            $this->byDevice = [];

            foreach ($this->store->all('employees') as $e) {
                $c = Employment::code($e);

                if ($c === '' || trim((string) ($e['deleted_at'] ?? '')) !== '') {
                    continue;
                }

                $this->byCode[strtolower($c)] = $c;
                $this->names[$c] = (string) ($e['name'] ?? '');
                $d = strtolower(trim((string) ($e['device_user_id'] ?? '')));

                if ($d !== '') {
                    $this->byDevice[$d][] = $c;
                }
            }
        }

        foreach (array_unique(array_filter([strtolower($given), strtolower($full), strtolower($raw)])) as $k) {
            if (isset($this->byCode[$k])) {
                return $this->byCode[$k];
            }
        }

        foreach (array_unique(array_filter([strtolower($full), strtolower($raw)])) as $k) {
            if (count($this->byDevice[$k] ?? []) === 1) {
                return $this->byDevice[$k][0];
            }
        }

        return null;
    }

    /** One external id, once per tenant. False = already seen. */
    private function claim(string $ext): bool
    {
        $s = $this->pdo->prepare('INSERT IGNORE INTO punch_receipts (tenant_id, external_id, created_at) VALUES (?, ?, NOW())');
        $s->execute([$this->tenantId, $ext]);

        return $s->rowCount() > 0;
    }

    /** @return list<array{0: string, 1: string}> the day's punches on file, read once per employee-day */
    private function dayOf(string $code, string $day): array
    {
        $k = $code . '|' . $day;

        if (!isset($this->dayPunches[$k])) {
            $s = $this->pdo->prepare('SELECT punch_at, direction FROM attendance_logs WHERE tenant_id = ? AND emp_code = ? AND log_date = ? ORDER BY punch_at');
            $s->execute([$this->tenantId, $code, $day]);
            $this->dayPunches[$k] = array_map(static fn (array $r): array => [substr((string) $r['punch_at'], 0, 19), (string) $r['direction']], $s->fetchAll(\PDO::FETCH_ASSOC));
        }

        return $this->dayPunches[$k];
    }

    private function sequenced(string $code, string $at): string
    {
        $prev = null;

        foreach ($this->dayOf($code, substr($at, 0, 10)) as [$t, $d]) {
            if ($t <= $at) {
                $prev = [$t, $d];
            }
        }

        if ($prev === null) {
            return 'in';
        }

        return strtotime($at) - strtotime($prev[0]) <= self::REPEAT_SECONDS ? $prev[1] : ($prev[1] === 'in' ? 'out' : 'in');
    }

    /** False = this employee already has this exact punch (same instant, same direction); nothing written. */
    public function write(string $code, string $at, string $dir, string $source, string $sn, string $user, string $ext, mixed $verify): bool
    {
        if (in_array([substr($at, 0, 19), $dir], $this->dayOf($code, substr($at, 0, 10)), true)) {
            return false;
        }

        $this->store->put('attendance_logs', ['emp_code' => $code, 'emp_name' => $this->names[$code] ?? null,
            'log_date' => substr($at, 0, 10), 'punch_at' => $at, 'direction' => $dir, 'source' => $source,
            'device_sn' => $sn !== '' ? $sn : null, 'device_user_id' => $user, 'external_id' => $ext,
            'verify_mode' => is_scalar($verify) && (string) $verify !== '' ? mb_substr((string) $verify, 0, 24) : null]);
        $this->dayPunches[$code . '|' . substr($at, 0, 10)][] = [substr($at, 0, 19), $dir];

        return true;
    }

    /** @param array<string, mixed> $row */
    private function hold(array $row): void
    {
        $this->store->put('attendance_pending', $row + ['resolved_at' => null]);
    }

    /** @param array<string, int> $unmapped */
    private function countUnmapped(array $unmapped, string $source): void
    {
        foreach ($unmapped as $code => $n) {
            $s = $this->pdo->prepare('INSERT INTO biometric_unmapped (tenant_id, device_code, source, punches, last_seen, created_at, updated_at)
                VALUES (?, ?, ?, ?, NOW(), NOW(), NOW()) ON DUPLICATE KEY UPDATE punches = punches + VALUES(punches), source = VALUES(source), last_seen = NOW(), updated_at = NOW()');
            $s->execute([$this->tenantId, mb_substr((string) $code, 0, 80), $source, $n]);
        }
    }

    /**
     * HR mapped a device id to an employee: every held punch for it moves into
     * attendance_logs (SmartPRS 2 promotePending), and the counter is cleared.
     */
    public function promote(string $deviceCode, string $empCode): int
    {
        $moved = 0;
        $lc = strtolower(trim($deviceCode));
        $codes = [$lc => true];

        foreach ($this->store->all('attendance_pending') as $r) {
            if (trim((string) ($r['resolved_at'] ?? '')) !== ''
                || (strtolower((string) ($r['device_code'] ?? '')) !== $lc && strtolower((string) ($r['device_user_id'] ?? '')) !== $lc)) {
                continue;
            }

            $at = substr((string) $r['punch_at'], 0, 19);
            $dir = in_array((string) ($r['direction'] ?? ''), ['in', 'out'], true) ? (string) $r['direction'] : $this->sequenced($empCode, $at);
            $this->match($empCode, '', '');
            $written = $this->write($empCode, $at, $dir, (string) ($r['source'] ?? 'device'), (string) ($r['device_sn'] ?? ''),
                (string) ($r['device_user_id'] ?? ''), (string) ($r['external_id'] ?? ''), $r['verify_mode'] ?? null);
            $this->store->put('attendance_pending', ['id' => $r['id'], 'resolved_at' => date('Y-m-d H:i:s')]);
            $codes[strtolower((string) ($r['device_code'] ?? ''))] = true;
            $written && $moved++;   // a held punch already on file is resolved, not added again
        }

        foreach (array_keys($codes) as $c) {
            $this->pdo->prepare('DELETE FROM biometric_unmapped WHERE tenant_id = ? AND LOWER(device_code) = ?')->execute([$this->tenantId, $c]);
        }

        return $moved;
    }
}
