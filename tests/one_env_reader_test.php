<?php

declare(strict_types=1);

/**
 * THERE IS ONE .env READER, AND `parse_ini_file()` IS NOT IT.
 *
 * PHP's INI parser comments with `;`. This project's `.env` — like every `.env` —
 * comments with `#`, and it also contains a line reading
 * `# Instants are stored UTC (spine S16).` So `parse_ini_file()` does not return a
 * partial array or drop the comment: it raises a syntax error and returns FALSE.
 * Every caller then sees an empty configuration.
 *
 * That is what makes it worth a test rather than a code review note. The failure
 * does not look like a parsing failure. `bootstrap-local.php` announced
 * `APP_ENV is "", not "local"` about a file whose second line reads `APP_ENV=local`
 * and refused to continue; `migrate.php` lost DB_DATABASE and went looking in the
 * wrong database. Both blamed the user's file, and the file was correct. Three
 * tools were written this way in one sitting, each one a fresh re-derivation of
 * something `Surface\Database::env()` had already got right.
 *
 * So the rule is structural: anything that reads `.env` goes through the one reader
 * that understands this project's `.env`. This test is the only thing standing
 * between that rule and the fourth re-derivation.
 */

use SmartPRS\Surface\Database;

group('one env reader');

// ------------------------------------------------------- the reader still works
$file = sys_get_temp_dir() . '/prs3-env-' . bin2hex(random_bytes(4));

file_put_contents($file, <<<'ENV'
APP_NAME="SmartPRS 3"
APP_ENV=local

# Instants are stored UTC (spine S16). This is the tenant's *business* timezone,
# used to derive the business date. Per-tenant values override it at runtime.
DB_DATABASE=smartprs3
DB_PASSWORD=
ENV);

$read = Database::env($file);

same('local', $read['APP_ENV'] ?? null,
    'The reader gets a value that sits below a `#` comment containing brackets — the exact shape '
    . 'that makes parse_ini_file() return false for the whole file.');
same('SmartPRS 3', $read['APP_NAME'] ?? null, 'and it strips the quotes around a value.');
same('smartprs3', $read['DB_DATABASE'] ?? null, 'and reads a key after the comment block.');
ok(!array_key_exists('# Instants are stored UTC (spine S16). This is the tenant\'s *business* '
    . 'timezone,', $read), 'and does not turn a comment into a key.');

// THE NON-VACUITY CHECK, and in this file it is the whole argument: if PHP's own
// parser handled this file, the rule below would be pedantry rather than a
// defect. It does not. Asserted rather than asserted-about.
same(false, @parse_ini_file($file, false, INI_SCANNER_RAW),
    'parse_ini_file() returns FALSE outright on this file — not a partial array, not the file '
    . 'without its comments. Every value a caller wanted is simply gone, and the caller is left '
    . 'reporting that the configuration is empty.');

@unlink($file);

// ------------------------------------------------ and nothing else parses .env
// Grepped, because this is a rule about the shape of the codebase and not about
// the behaviour of any one function. `Database::env()` itself is excluded: it is
// the reader, and it does not call the INI parser anyway.
$offenders = [];

foreach (['tools', 'surface', 'public', 'src'] as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__ . '/../' . $directory,
            FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // TOKENS, NOT TEXT. A regex over the file body found three offenders on the
        // first run and all three were COMMENTS — every fix for this defect carries
        // a note naming parse_ini_file to explain why it is not used, and a
        // text search cannot tell an explanation from a call. A check that fails on
        // the documentation of its own rule is one somebody deletes.
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && $token[0] === T_STRING && $token[1] === 'parse_ini_file') {
                $offenders[] = str_replace(dirname(__DIR__) . '/', '', $file->getPathname());

                break;
            }
        }
    }
}

same([], $offenders,
    'Nothing calls parse_ini_file(). Reading .env goes through Surface\Database::env(), which is '
    . 'the one place that knows this project\'s .env comments with `#`. A second reader is not a '
    . 'style problem here: it returns an empty configuration and then reports the user\'s correct '
    . 'file as wrong.');
