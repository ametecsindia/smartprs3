<?php
/**
 * THE BUILD JOURNAL — what changed, when, and why.
 *
 *   php tools/programme/journal.php "title" "one line of what it does"
 *   php tools/programme/journal.php --list
 *
 * A build log that is written by hand at the end of a week is a build log
 * written from memory. This one is appended at the moment a piece lands, and the
 * console renders it, so "what has been happening?" has an answer that does not
 * depend on anyone remembering.
 *
 * It records what was CLAIMED. The gates on the same page record what is TRUE —
 * and the two being separate is deliberate: a journal entry is a person saying
 * they built something, and a passing gate is the build saying so itself.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/docs/build-journal.json';
$entries = is_file($path)
    ? json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR)
    : [];

if (($argv[1] ?? '') === '--list') {
    foreach (array_slice(array_reverse($entries), 0, 30) as $entry) {
        printf("  %s  %-52s %s\n", $entry['at'], $entry['title'], $entry['detail']);
    }

    exit(0);
}

if (($argv[1] ?? '') === '') {
    echo "usage: journal.php \"title\" \"detail\"\n";

    exit(1);
}

$entries[] = [
    'at' => date('Y-m-d H:i'),
    'title' => $argv[1],
    'detail' => $argv[2] ?? '',
];

file_put_contents($path, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

echo "recorded: {$argv[1]}\n";
