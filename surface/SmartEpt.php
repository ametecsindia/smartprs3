<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * SMARTEPT → SMARTPRS ATTENDANCE (SmartPRS 2's SmarteptWebhook, same contract).
 *
 * SmartEPT's OutboundPusher POSTs JSON to /api/v1/webhooks/smartept/{slug} with
 * X-SmartEPT-Signature: hex HMAC-SHA256(raw body, endpoint secret) and
 * X-SmartEPT-Event. Two events:
 *   attendance.punch  {company_id, employee_code|biometric_employee_id, punch_type IN|OUT, punched_at, device_id, verification_mode}
 *   attendance.daily  {company_id, records: [{employee_code, first_in, last_out}]}
 * Both become ordinary punches for PunchIngest with a deterministic external id,
 * so a resend is a duplicate.
 */
final class SmartEpt
{
    public const SOURCE = 'smartept';
    public const EVENTS = ['attendance.punch', 'attendance.daily'];
    public const SIGNATURE_HEADER = 'X-SmartEPT-Signature';
    public const EVENT_HEADER = 'X-SmartEPT-Event';
    public const MAX_BODY_BYTES = 2097152;
    private const DEVICE_FALLBACK = 'SMARTEPT';

    /** @return array{slug: string, secret: string} */
    public static function mint(): array
    {
        return ['slug' => bin2hex(random_bytes(8)), 'secret' => 'whk_ept_' . bin2hex(random_bytes(24))];
    }

    public static function signatureMatches(string $raw, string $secret, string $presented): bool
    {
        $presented = trim($presented);

        return $presented !== '' && $secret !== '' && hash_equals(hash_hmac('sha256', $raw, $secret), $presented);
    }

    /** @return array{punches: list<array<string, mixed>>, skipped: int, note: ?string} */
    public static function punchesFor(string $event, array $payload): array
    {
        $company = self::str($payload['company_id'] ?? '');

        if ($event === 'attendance.punch') {
            $code = self::str($payload['employee_code'] ?? '');
            $bio = self::str($payload['biometric_employee_id'] ?? '');
            $id = $code !== '' ? $code : $bio;
            $dir = strtoupper(self::str($payload['punch_type'] ?? ''));
            $dir = in_array($dir, ['IN', 'OUT'], true) ? $dir : 'UNKNOWN';
            $at = self::local(self::str($payload['punched_at'] ?? ''));

            if ($id === '' || $at === null) {
                return ['punches' => [], 'skipped' => 1, 'note' => 'Punch carried no employee or no time.'];
            }

            return ['punches' => [self::punch(self::str($payload['device_id'] ?? '') ?: self::DEVICE_FALLBACK, $bio ?: $id, $code,
                $dir, $at, self::str($payload['verification_mode'] ?? '') ?: 'SYSTEM', $company, $id)], 'skipped' => 0, 'note' => null];
        }

        $records = $payload['records'] ?? null;

        if (!is_array($records)) {
            return ['punches' => [], 'skipped' => 0, 'note' => 'Payload had no records array.'];
        }

        $punches = [];
        $skipped = 0;

        foreach ($records as $r) {
            $r = is_array($r) ? $r : [];
            $code = self::str($r['employee_code'] ?? '');
            $before = count($punches);

            foreach ([['first_in', 'IN'], ['last_out', 'OUT']] as [$field, $dir]) {
                $at = $code === '' ? null : self::local(self::str($r[$field] ?? ''));

                if ($at !== null) {
                    $punches[] = self::punch(self::DEVICE_FALLBACK, $code, $code, $dir, $at, 'SYSTEM', $company, $code);
                }
            }

            $skipped += count($punches) === $before ? 1 : 0;
        }

        return ['punches' => $punches, 'skipped' => $skipped,
            'note' => $skipped > 0 ? $skipped . ' record(s) produced no punch — no employee code, or no in/out time.' : null];
    }

    /** @return array<string, mixed> */
    private static function punch(string $sn, string $user, string $code, string $dir, string $at, string $verify, string $company, string $identity): array
    {
        return ['punch_at' => $at, 'device_sn' => mb_substr($sn, 0, 64), 'device_user_id' => mb_substr($user, 0, 64),
            'employee_code' => $code !== '' ? mb_substr($code, 0, 64) : null, 'direction' => $dir, 'verify_mode' => mb_substr($verify, 0, 24),
            'external_id' => 'ept_' . hash('sha256', implode('|', [self::SOURCE, $company, mb_strtolower($identity), $dir, $at]))];
    }

    /** An ISO time in SmartEPT's zone, as this server's local "Y-m-d H:i:s". */
    public static function local(string $v): ?string
    {
        if (preg_match('~^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}~', $v) !== 1) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($v))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null; // an unparseable time is refused, never guessed
        }
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }
}
