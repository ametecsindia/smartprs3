<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * THE CONNECTION, AND WHY THERE ISN'T ONE.
 *
 * The application runs on the JSON store OR on MySQL, and which one it is must
 * never be a guess. This class either hands back a PDO or hands back the reason
 * there is none — and the reason reaches the screen, because an application that
 * silently falls back to file storage is one where somebody enters a month of
 * payroll into the wrong thing and finds out when they look for it in the
 * database.
 *
 * IT NEVER CREATES THE DATABASE and never runs a migration. Schema changes are
 * migrations, run deliberately by somebody who meant to; an application that
 * migrates on boot is one where a bad deploy alters a live table because a page
 * was loaded.
 *
 * ponytail: no pool, no retry, no read replica. One connection per request, from
 * .env, which is what a Laragon install has.
 */
final class Database
{
    private static ?\PDO $pdo = null;

    private static string $why = 'not attempted yet';

    /** Read `.env` into an array. Absent keys are absent, not empty strings. */
    public static function env(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $values = [];

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value, " \t\"'");
        }

        return $values;
    }

    /** The connection, or null with a reason `why()` will give. */
    public static function connect(string $envFile): ?\PDO
    {
        if (self::$why !== 'not attempted yet') {
            return self::$pdo;
        }

        $env = self::env($envFile);
        $database = $env['DB_DATABASE'] ?? '';

        if ($database === '' || ($env['DB_CONNECTION'] ?? 'mysql') !== 'mysql') {
            self::$why = is_file($envFile)
                ? 'the .env file does not name a MySQL database in DB_DATABASE'
                : 'there is no .env file — copy .env.example to .env and fill in DB_DATABASE';

            return null;
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306', $database);

        try {
            self::$pdo = new \PDO($dsn, $env['DB_USERNAME'] ?? 'root', $env['DB_PASSWORD'] ?? '', [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                // Real prepared statements: emulation sends the query with the
                // values pasted in, which is the thing binding exists to avoid.
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (\PDOException $e) {
            self::$why = "the database `{$database}` could not be opened: " . $e->getMessage();

            return null;
        }

        // Connected is not the same as migrated. An empty database looks exactly
        // like a working one until the first query, and the first query is
        // usually somebody's real work.
        $tables = (int) self::$pdo->query(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        )->fetchColumn();

        if ($tables === 0) {
            self::$why = "the database `{$database}` is reachable but EMPTY — run the migrations "
                . 'before using it. This application does not create tables on boot: a page load '
                . 'that alters a live schema is how a bad deploy becomes a data loss.';
            self::$pdo = null;

            return null;
        }

        self::$why = "connected to `{$database}` ({$tables} tables)";

        return self::$pdo;
    }

    public static function why(): string
    {
        return self::$why;
    }
}
