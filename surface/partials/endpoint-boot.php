<?php
/**
 * THE SHARED START OF EVERY MACHINE-FACING ENDPOINT — /api/v1, /api/mobile, /iclock,
 * /update (licence server). Same autoloader as public/app.php, the same .env, the
 * same database; no session, no cookie, no CSRF (the caller is a device or a server,
 * and each endpoint checks its own credential).
 *
 * Gives the including file: $root, $pdo (\PDO), $platform (unscoped SqlStore),
 * json_out(), body_json(), body_form().
 */
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    foreach (['SmartPRS\\Platform\\' => '/../../src/Platform/', 'SmartPRS\\Surface\\' => '/../'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $path = __DIR__ . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($path)) {
                require_once $path;
            }

            return;
        }
    }
});

$root = dirname(__DIR__, 2);
// Devices send local (Indian) time and SmartEPT / field-force send an offset: both are read in the
// business timezone from .env, whatever php.ini says.
$bootTz = (string) (SmartPRS\Surface\Database::env($root . '/.env')['SMARTPRS_DEFAULT_BUSINESS_TIMEZONE'] ?? '');
if ($bootTz !== '' && in_array($bootTz, timezone_identifiers_list(), true)) {
    date_default_timezone_set($bootTz);
}

/** @param array<string, mixed> $body */
function json_out(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** @return array<string, mixed>|null */
function body_json(string $raw): ?array
{
    $j = json_decode($raw, true);

    return is_array($j) ? $j : null;
}

/** Form or JSON body, whichever the caller sent. @return array<string, mixed> */
function body_form(string $raw): array
{
    return $_POST !== [] ? $_POST : (body_json($raw) ?? []);
}

$pdo = SmartPRS\Surface\Database::connect($root . '/.env');

if ($pdo === null) {
    json_out(503, ['ok' => false, 'error' => 'The database is not available: ' . SmartPRS\Surface\Database::why()]);
}

$platform = new SmartPRS\Surface\SqlStore($pdo);
