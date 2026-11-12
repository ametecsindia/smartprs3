<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * QR ATTENDANCE (owner, 9 Nov 2026) — SmartEPT's QR Attendance (QRONEX) concept, without Gate-to-PC.
 *
 * Each company picks its ATTENDANCE SOURCE: Standard (biometric / web & app punch, as before), SmartEPT punch
 * (punches arrive from SmartEPT's signed webhook, /api/v1/webhooks/smartept/{slug}), or QR Attendance.
 *
 * QR: a gate screen (public/qr.php, opened on a tablet / TV at the entrance) shows a code that changes every
 * interval_s seconds:  "PRSQ1.<gate code>.<interval>.<window>.<sig>", window = floor(unix / interval),
 * sig = first 20 hex of HMAC-SHA256 with the gate's own secret — the same shape as SmartEPT's QRX1 token. Nothing is
 * stored until somebody scans. The employee scans it in the SmartPRS3 mobile app (Punch); the server checks, in
 * SmartEPT's order: the code is a real gate's code and signed right, not older than interval + grace, the gate is
 * active, the gate is at one of the employee's branches, GPS accuracy, distance from the gate, a repeat scan.
 *
 * Everything (sources, settings, gates with their display secret) is ONE versioned customisation (key
 * qr-attendance) — no table; "Reset link" writes a new version with a new secret, so the old screen link stops.
 * Coordinates are geometry, not money, so floats are right here (Law 7 is about money).
 */
final class QrAttendance
{
    public const PREFIX = 'PRSQ1';
    public const SOURCE = 'qr';

    /** @param array<string, mixed> $config config/qr-attendance.json  @param array<string, mixed> $saved the live customisation */
    public function __construct(private readonly array $config, private readonly array $saved)
    {
    }

    public static function load(Customisation $customisation, string $root): self
    {
        $config = json_decode((string) @file_get_contents($root . '/config/qr-attendance.json'), true);
        $config = is_array($config) ? $config : ['settings' => [], 'sources' => []];

        return new self($config, $customisation->live()[self::key($config)] ?? []);
    }

    /** @param array<string, mixed> $config */
    public static function key(array $config): string
    {
        return (string) ($config['customisation_key'] ?? 'qr-attendance');
    }

    public function customisationKey(): string
    {
        return self::key($this->config);
    }

    /** @return array<string, mixed> the whole saved payload, for a save that changes one part of it */
    public function saved(): array
    {
        return $this->saved;
    }

    /** @return array<string, string> code => label */
    public function sourceOptions(): array
    {
        return array_map('strval', (array) ($this->config['sources'] ?? []));
    }

    /**
     * THE PUNCH THIS EMPLOYEE USES (owner, 12 Nov 2026): the company's source, except that in a Standard company an
     * Office employee (config office_qr) whose branch has an active gate punches by QR — Field staff keep the location punch.
     *
     * @param array<string, mixed> $employee
     */
    public function modeFor(array $employee): string
    {
        $source = $this->sourceFor((string) ($employee['employer_company_id'] ?? ''));
        $rule = (array) ($this->config['office_qr'] ?? []);
        $type = trim((string) ($employee[(string) ($rule['field'] ?? 'type')] ?? ''));

        if ($source !== 'standard' || $type === '' || !in_array(strtolower($type), array_map('strtolower', array_map('strval', (array) ($rule['values'] ?? []))), true)) {
            return $source;
        }

        foreach ($this->gates() as $g) {
            if ((bool) ($g['active'] ?? true) && (string) ($g['branch_id'] ?? '') !== '' && (string) $g['branch_id'] === (string) ($employee['branch_id'] ?? '')) {
                return self::SOURCE;
            }
        }

        return $source;
    }

    /** A company whose branches have gates: a QR company, or a Standard one whose Office staff punch by QR (office_qr). */
    public function usesGates(string $companyId): bool
    {
        $source = $this->sourceFor($companyId);

        return $source === self::SOURCE || ($source === 'standard' && (array) (((array) ($this->config['office_qr'] ?? []))['values'] ?? []) !== []);
    }

    /** standard | smartept | qr — a company never set (or set to something unknown) is Standard. */
    public function sourceFor(string $companyId): string
    {
        $s = (string) (((array) ($this->saved['sources'] ?? []))[$companyId] ?? '');

        return array_key_exists($s, $this->sourceOptions()) ? $s : 'standard';
    }

    /** @return array<string, array{label: string, default: int, min: int, max: int}> */
    public function settingSpecs(): array
    {
        return (array) ($this->config['settings'] ?? []);
    }

    /** Each company has its own rules (owner, 9 Nov 2026: "each settings for each company"); unset = shipped default. */
    public function setting(string $key, string $companyId = ''): int
    {
        $spec = (array) ($this->settingSpecs()[$key] ?? []);
        $v = ((array) (((array) ($this->saved['company_settings'] ?? []))[$companyId] ?? []))[$key]
            ?? ((array) ($this->saved['settings'] ?? []))[$key] ?? ($spec['default'] ?? 0);

        return max((int) ($spec['min'] ?? PHP_INT_MIN), min((int) ($spec['max'] ?? PHP_INT_MAX), (int) $v));
    }

    /** The keys a gate may set for itself (owner, 9 Nov 2026: per-gate overrides); radius_m is per gate already. */
    public const GATE_KEYS = ['interval_s', 'grace_s', 'gps_accuracy_m', 'duplicate_window_min'];

    /** A gate's own value if it set one, else its company's rule — held to the same allowed range. */
    public function gateSetting(array $gate, string $key, string $companyId = ''): int
    {
        $own = $gate[$key] ?? null;

        if (!in_array($key, self::GATE_KEYS, true) || $own === null || $own === '') {
            return $this->setting($key, $companyId);
        }

        $spec = (array) ($this->settingSpecs()[$key] ?? []);

        return max((int) ($spec['min'] ?? PHP_INT_MIN), min((int) ($spec['max'] ?? PHP_INT_MAX), (int) $own));
    }

    /** @return list<array<string, mixed>> */
    public function gates(): array
    {
        return array_values(array_filter((array) ($this->saved['gates'] ?? []), 'is_array'));
    }

    /** @return array<string, mixed>|null */
    public function gate(string $code): ?array
    {
        foreach ($this->gates() as $g) {
            if (hash_equals((string) ($g['code'] ?? ''), $code)) {
                return $g;
            }
        }

        return null;
    }

    /**
     * EXCEPTIONS (owner, 9 Nov 2026): `geofence` — this employee may QR-punch at this branch's gates from anywhere (the
     * distance / GPS checks are skipped, nothing else); `phone` — this employee's new phone is approved without the Admin.
     * Each is Always (no dates) or for a date range, with a reason.
     *
     * @return list<array<string, mixed>>
     */
    public function exceptions(string $kind): array
    {
        return array_values(array_filter((array) ($this->saved['exceptions'] ?? []),
            static fn (mixed $x): bool => is_array($x) && (string) ($x['kind'] ?? '') === $kind));
    }

    public static function exceptionLive(array $x, string $date): bool
    {
        $from = trim((string) ($x['from'] ?? ''));
        $to = trim((string) ($x['to'] ?? ''));

        return ($from === '' || $from <= $date) && ($to === '' || $date <= $to);
    }

    public function geofenceExempt(string $employeeId, string $branchId, string $date): bool
    {
        foreach ($this->exceptions('geofence') as $x) {
            if ((string) ($x['employee_id'] ?? '') === $employeeId && (string) ($x['branch_id'] ?? '') === $branchId && self::exceptionLive($x, $date)) {
                return true;
            }
        }

        return false;
    }

    /** "required" (default) or "auto" — a demo client / Android review company needs no phone approval. */
    public function phoneApproval(string $companyId): string
    {
        return (string) (((array) ($this->saved['phone_approval'] ?? []))[$companyId] ?? '') === 'auto' ? 'auto' : 'required';
    }

    /**
     * Is a new phone approved without the Admin? Yes when the employee's company needs no phone approval, or the employee
     * has a live phone exception. A login with no employee record: only when EVERY company needs none (fails closed).
     *
     * @param list<string> $allCompanyIds
     */
    public function phoneAutoApproved(?string $employeeId, ?string $companyId, array $allCompanyIds, string $date): bool
    {
        if ($employeeId === null) {
            return $allCompanyIds !== [] && array_filter($allCompanyIds, fn (string $c): bool => $this->phoneApproval($c) !== 'auto') === [];
        }

        if ($companyId !== null && $this->phoneApproval($companyId) === 'auto') {
            return true;
        }

        foreach ($this->exceptions('phone') as $x) {
            if ((string) ($x['employee_id'] ?? '') === $employeeId && self::exceptionLive($x, $date)) {
                return true;
            }
        }

        return false;
    }

    public static function newSecret(): string
    {
        return bin2hex(random_bytes(20));
    }

    /** @return array{qr: string, window: int, expires_in: int, interval: int} */
    public function token(array $gate, ?int $now = null, string $companyId = ''): array
    {
        $now ??= time();
        $interval = $this->gateSetting($gate, 'interval_s', $companyId);
        $window = intdiv($now, $interval);

        return ['qr' => self::PREFIX . '.' . $gate['code'] . '.' . $interval . '.' . $window . '.' . self::sign($gate, $interval, $window),
            'window' => $window, 'expires_in' => $interval - ($now % $interval), 'interval' => $interval];
    }

    private static function sign(array $gate, int $interval, int $window): string
    {
        return substr(hash_hmac('sha256', self::PREFIX . '|' . $gate['code'] . '|' . $interval . '|' . $window, (string) ($gate['secret'] ?? '')), 0, 20);
    }

    /**
     * Is this scan good? Checks stop at the first failure (SmartEPT's order); the punch direction and the
     * day-off rules stay with the punch action, exactly as for any other punch.
     *
     * @param list<string> $branchIds the employee's Home branch and the branches they handle
     * @param array<string, array{lat: mixed, lng: mixed}> $branchCoords branch id => coordinates (a gate without its own)
     * @param string|null $lastQrAt the employee's last QR punch time ("Y-m-d H:i:s"), for the repeat-scan check
     * @param string $companyId the employee's company — whose QR rules apply
     * @param string|null $employeeId for the geo-fence exception
     * @return array{ok: bool, code: string, why: string, gate?: array<string, mixed>, window?: int, exempt?: bool, distance_m?: int}
     */
    public function verify(string $qr, array $branchIds, array $branchCoords, string $lat, string $lng, string $accuracy,
        ?string $lastQrAt, ?int $now = null, string $companyId = '', ?string $employeeId = null): array
    {
        $now ??= time();
        $bad = static fn (string $code, string $why): array => ['ok' => false, 'code' => $code, 'why' => $why];

        if (preg_match('/^' . self::PREFIX . '\.([A-Za-z0-9]{1,16})\.(\d{1,3})\.(\d{1,12})\.([0-9a-f]{20})$/', trim($qr), $m) !== 1 || (int) $m[2] < 1) {
            return $bad('QR_INVALID', 'This is not a SmartPRS attendance QR code. Scan the code on the gate screen.');
        }

        $gate = $this->gate($m[1]);

        if ($gate === null || !hash_equals(self::sign($gate, (int) $m[2], (int) $m[3]), $m[4])) {
            return $bad('QR_INVALID', 'This QR code is not valid for this company. Scan the code on your office gate screen.');
        }

        $age = $now - (int) $m[3] * (int) $m[2];

        if ($age < 0 || $age > (int) $m[2] + $this->gateSetting($gate, 'grace_s', $companyId)) {
            return $bad('QR_EXPIRED', 'This QR code has expired. Scan the code showing on the screen now.');
        }

        if (!(bool) ($gate['active'] ?? true)) {
            return $bad('GATE_INACTIVE', 'This gate (' . $gate['name'] . ') is switched off. Ask HR.');
        }

        if (!in_array((string) ($gate['branch_id'] ?? ''), $branchIds, true)) {
            return $bad('BRANCH_NOT_ASSIGNED', 'This gate (' . $gate['name'] . ') is not at your branch. Scan the gate of your own branch.');
        }

        $gLat = trim((string) ($gate['lat'] ?? '')) !== '' ? $gate['lat'] : ($branchCoords[(string) $gate['branch_id']]['lat'] ?? '');
        $gLng = trim((string) ($gate['lng'] ?? '')) !== '' ? $gate['lng'] : ($branchCoords[(string) $gate['branch_id']]['lng'] ?? '');
        $distance = null;
        $exempt = $employeeId !== null && $this->geofenceExempt($employeeId, (string) $gate['branch_id'], date('Y-m-d', $now));

        if (!$exempt && is_numeric($gLat) && is_numeric($gLng)) { // a gate with no location anywhere is not fenced
            if (!is_numeric($lat) || !is_numeric($lng)) {
                return $bad('NO_LOCATION', 'Your location is needed to punch by QR. Allow location for SmartPRS3 and try again.');
            }

            if (is_numeric($accuracy) && (float) $accuracy > $this->gateSetting($gate, 'gps_accuracy_m', $companyId)) {
                return $bad('LOW_GPS_ACCURACY', 'Your GPS is not accurate enough (' . (int) round((float) $accuracy) . ' m; needs '
                    . $this->gateSetting($gate, 'gps_accuracy_m', $companyId) . ' m or better). Step outside or wait a moment and scan again.');
            }

            $distance = (int) round(GeoFence::distanceKm((float) $lat, (float) $lng, (float) $gLat, (float) $gLng) * 1000);
            $radius = (int) ($gate['radius_m'] ?? 0) ?: $this->setting('radius_m', $companyId);

            if ($distance > $radius) {
                return $bad('OUTSIDE_GEOFENCE', 'You are ' . $distance . ' m from ' . $gate['name'] . '; QR punch is allowed only within ' . $radius . ' m.');
            }
        }

        $dup = $this->gateSetting($gate, 'duplicate_window_min', $companyId);

        if ($lastQrAt !== null && $dup > 0 && $now - strtotime($lastQrAt) < $dup * 60) {
            return $bad('DUPLICATE', 'Already punched at ' . substr($lastQrAt, 11, 5) . '. Repeat scans within ' . $dup . ' min are ignored.');
        }

        return ['ok' => true, 'code' => 'OK', 'why' => 'QR checked at ' . $gate['name'] . ($exempt ? ' (geo-fence exception)' : ($distance === null ? '' : ' (' . $distance . ' m away)')),
            'gate' => $gate, 'window' => (int) $m[3], 'exempt' => $exempt] + ($distance === null ? [] : ['distance_m' => $distance]);
    }
}
