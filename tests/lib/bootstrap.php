<?php

declare(strict_types=1);

/**
 * Test bootstrap. No Composer, no PHPUnit — packagist is unreachable in the
 * build environment, so the suite is plain PHP (README explains).
 */

spl_autoload_register(static function (string $class): void {
    $roots = [
        'SmartPRS\\Platform\\' => __DIR__ . '/../../src/Platform/',
        'SmartPRS\\Surface\\' => __DIR__ . '/../../surface/',
    ];
    $path = null;

    foreach ($roots as $prefix => $root) {
        if (str_starts_with($class, $prefix)) {
            $path = $root . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        }
    }

    if ($path === null) {
        return;
    }

    if (is_file($path)) {
        require_once $path;
    }
});

final class Assertions
{
    public static int $passed = 0;
    /** @var list<string> */
    public static array $failures = [];
    public static string $group = '';
}

function group(string $name): void
{
    Assertions::$group = $name;
}

function ok(bool $condition, string $message): void
{
    if ($condition) {
        Assertions::$passed++;

        return;
    }

    Assertions::$failures[] = Assertions::$group . ': ' . $message;
}

function same(mixed $expected, mixed $actual, string $message): void
{
    ok(
        $expected === $actual,
        $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')',
    );
}

/** @param class-string<\Throwable> $expected */
function throws(string $expected, callable $fn, string $message): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        ok($e instanceof $expected, $message . ' (threw ' . $e::class . ' instead of ' . $expected . ')');

        return;
    }

    ok(false, $message . ' (nothing was thrown)');
}
