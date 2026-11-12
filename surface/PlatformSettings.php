<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * THE PLATFORM'S OWN SETTINGS — one JSON value per key in `platform_settings` (SmartPRS 2: the
 * `app_settings` row with tenant 0). Keys in use: smtp, whatsapp, demo_gate, landing.
 *
 * ponytail: reads the whole (tiny) table; a handful of keys, never per-request in a loop.
 */
final class PlatformSettings
{
    public function __construct(private readonly RowStore $store)
    {
    }

    /** @return array<string, mixed> */
    public function get(string $key): array
    {
        $row = $this->row($key);
        $value = $row === null ? null : json_decode((string) ($row['value'] ?? ''), true);

        return is_array($value) ? $value : [];
    }

    /** @param array<string, mixed> $value */
    public function put(string $key, array $value): void
    {
        $now = date('Y-m-d H:i:s');
        $row = $this->row($key);
        $put = ['ckey' => $key, 'value' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'updated_at' => $now];
        $this->store->put('platform_settings', $row === null ? $put + ['created_at' => $now] : $put + ['id' => $row['id']]);
    }

    /** @return array<string, mixed>|null */
    private function row(string $key): ?array
    {
        try {
            foreach ($this->store->all('platform_settings') as $r) {
                if ((string) $r['ckey'] === $key) {
                    return $r;
                }
            }
        } catch (\Throwable) {
            return null; // the migration has not run yet: nothing is saved, which is what the screens then say
        }

        return null;
    }
}
