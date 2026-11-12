<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * REGISTERED PHONES FOR THE ATTENDANCE APP — SmartPRS 2's Mobile Devices.
 *
 * A phone registers as pending; an administrator approves, rejects (with the
 * reason the phone shows) or revokes it. Which move is allowed from which
 * status is config/mobile-devices.json, so the screen offers exactly the
 * buttons the action will accept and neither carries a status name.
 */
final class MobileDevices
{
    /** @var array<string, mixed> */
    private array $declared;

    public function __construct(private readonly RowStore $store, string $file)
    {
        $d = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (!is_array($d) || !is_array($d['transitions'] ?? null) || !is_array($d['statuses'] ?? null)) {
            throw new \RuntimeException("{$file} does not declare mobile device statuses and transitions.");
        }

        $this->declared = $d;
    }

    /** @return array<string, mixed> */
    public function declared(): array
    {
        return $this->declared;
    }

    /** @return list<string> the moves a row in this status may make */
    public function movesFrom(string $status): array
    {
        $out = [];

        foreach ((array) $this->declared['transitions'] as $op => $t) {
            if (in_array(strtolower(trim($status)), (array) $t['from'], true)) {
                $out[] = (string) $op;
            }
        }

        return $out;
    }

    /**
     * A new registration, as the phone (or an administrator on its behalf) makes it.
     *
     * @param array<string, string> $in
     * @return array{ok: bool, why: string, row?: array<string, mixed>}
     */
    public function register(array $in, string $now): array
    {
        $deviceId = trim((string) ($in['device_id'] ?? ''));

        if ($deviceId === '') {
            return ['ok' => false, 'why' => 'A device id is needed — it is how the phone identifies itself on every punch.'];
        }

        foreach ($this->store->all((string) $this->declared['table']) as $existing) {
            if ((string) ($existing['device_id'] ?? '') === $deviceId && trim((string) ($existing['deleted_at'] ?? '')) === '') {
                return ['ok' => false, 'why' => "Device {$deviceId} is already registered (" . (string) $existing['status'] . '). Decide that row rather than adding a second.'];
            }
        }

        $row = $this->store->put((string) $this->declared['table'], [
            'device_id' => $deviceId,
            'device_name' => trim((string) ($in['device_name'] ?? '')) ?: null,
            'employee_id' => trim((string) ($in['employee_id'] ?? '')) ?: null,
            'model' => trim((string) ($in['model'] ?? '')) ?: null,
            'platform' => trim((string) ($in['platform'] ?? '')) ?: null,
            'os_version' => trim((string) ($in['os_version'] ?? '')) ?: null,
            'status' => (string) $this->declared['registered'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['ok' => true, 'why' => "Device {$deviceId} registered as " . (string) $this->declared['registered'] . '.', 'row' => $row];
    }

    /**
     * MAY THIS SIGNED-IN PERSON USE THE APP ON THIS PHONE? (owner, 5 Nov 2026) Only the newest live
     * registration of the phone counts; it must be approved, and bound to this person's employee
     * (an approved phone not yet bound is bound by the first sign-in, boot-hooks). Fails closed.
     *
     * @param iterable<array<string, mixed>> $rows the tenant's mobile_devices
     * @return string|null null = allowed, else the reason the phone shows
     */
    public static function gate(iterable $rows, string $deviceId, string $employeeId, string $userId = ''): ?string
    {
        $hit = null;

        foreach ($rows as $d) {
            if ((string) ($d['device_id'] ?? '') === $deviceId && trim((string) ($d['deleted_at'] ?? '')) === ''
                && ($hit === null || (int) $d['id'] > (int) $hit['id'])) {
                $hit = $d;
            }
        }

        if ($hit === null || strtolower((string) ($hit['status'] ?? '')) !== 'approved') {
            return trim((string) ($hit['reject_reason'] ?? ''))
                ?: 'This phone is not approved for SmartPRS. Ask your administrator to approve it (Administration → Mobile Devices).';
        }

        $bound = trim((string) ($hit['employee_id'] ?? ''));
        // A PHONE REGISTERED BY A LOGIN WITH NO EMPLOYEE RECORD (Admin / HR, 6 Nov 2026) is that login's phone only.
        $boundUser = trim((string) ($hit['user_id'] ?? ''));

        if ($bound === '' && $boundUser !== '') {
            return $boundUser === trim($userId) ? null : 'This phone is registered to another SmartPRS login. Sign in with that login, or ask your administrator.';
        }

        return $bound === '' || $bound === trim($employeeId) ? null
            : 'This phone is registered to another employee. Sign in with that employee\'s account, or ask your administrator.';
    }

    /**
     * Move one device: approve / reject / revoke, as declared.
     *
     * @param array<string, mixed> $row
     * @return array{ok: bool, why: string, row?: array<string, mixed>}
     */
    public function decide(array $row, string $op, string $reason, string $by, string $now): array
    {
        $t = $this->declared['transitions'][$op] ?? null;
        $status = strtolower(trim((string) ($row['status'] ?? '')));

        if (!is_array($t)) {
            return ['ok' => false, 'why' => "There is no '{$op}' move for a device."];
        }

        if (!in_array($status, (array) $t['from'], true)) {
            return ['ok' => false, 'why' => sprintf('A %s device cannot be %sd — only a %s one.', $status ?: 'blank',
                $op, implode(' or ', (array) $t['from']))];
        }

        $reason = trim($reason);

        if ($reason === '' && (bool) ($t['reason_required'] ?? false)) {
            return ['ok' => false, 'why' => strtolower((string) $t['label']) . 'ing needs a reason — the phone shows it to the person holding it.'];
        }

        $row['status'] = (string) $t['to'];
        $row['approved_by'] = $by;
        $row['approved_at'] = $now;
        $row['reject_reason'] = $reason !== '' ? $reason : ((string) ($t['default_reason'] ?? '') ?: null);
        $row['updated_at'] = $now;
        $saved = $this->store->put((string) $this->declared['table'], $row);

        return ['ok' => true, 'why' => sprintf('%s: device %s is now %s.', (string) $t['label'], (string) ($row['device_id'] ?? ''), $row['status']), 'row' => $saved];
    }
}
