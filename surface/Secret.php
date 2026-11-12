<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * A SECRET THE SERVER MUST READ BACK — a licence key the Super Admin re-shows,
 * a SmartEPT webhook secret the signature check needs. Hashing would lose it;
 * plain text would leak it in any database dump. AES-256-GCM under the
 * install's APP_KEY (.env), the same job Laravel's Crypt did in SmartPRS 2.
 *
 * ponytail: one key, no rotation. Rotating APP_KEY makes every stored secret
 * unreadable — decrypt() then returns null and the caller says so.
 */
final class Secret
{
    private const PREFIX = 'sx1:';

    public static function encrypt(string $plain, ?string $envFile = null): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key($envFile), OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipher === false) {
            throw new \RuntimeException('openssl could not encrypt the secret.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $stored, ?string $envFile = null): ?string
    {
        if ($stored === null || !str_starts_with($stored, self::PREFIX)) {
            return null;
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);

        if ($raw === false || strlen($raw) < 29) {
            return null;
        }

        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key($envFile), OPENSSL_RAW_DATA,
            substr($raw, 0, 12), substr($raw, 12, 16));

        return $plain === false ? null : $plain;
    }

    private static function key(?string $envFile): string
    {
        $env = Database::env($envFile ?? __DIR__ . '/../.env');
        $appKey = (string) ($env['APP_KEY'] ?? '');

        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7));
        }

        if ($appKey === '') {
            // No APP_KEY (a local install): a random key kept beside the licence key, made once.
            $file = dirname($envFile ?? __DIR__ . '/../.env') . '/storage/keys/secret.key';

            if (!is_file($file)) {
                @mkdir(dirname($file), 0700, true);
                file_put_contents($file, bin2hex(random_bytes(32)), LOCK_EX);
            }

            $appKey = trim((string) file_get_contents($file));
        }

        if ($appKey === '') {
            throw new \RuntimeException('APP_KEY is empty in .env and storage/keys/secret.key could not be made — set APP_KEY.');
        }

        return hash('sha256', 'smartprs-secret|' . $appKey, true);
    }
}
