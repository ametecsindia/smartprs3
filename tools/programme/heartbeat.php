<?php
/**
 * A HEARTBEAT, SO A REFRESH MEANS SOMETHING.
 *
 *   php tools/programme/heartbeat.php "what is being worked on right now"
 *
 * The status page can already show which files changed and when. What it cannot
 * show from file timestamps alone is whether work is HAPPENING or has STOPPED —
 * a directory whose newest file is four hours old looks the same whether the
 * build is paused, finished, or broken.
 *
 * So this writes one small file each time a piece of work starts or lands, and
 * the console renders its age. A heartbeat forty minutes old is a legitimate
 * answer — "nothing since 14:20" — and it is a far more useful answer than a
 * page that looks identical either way.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);

file_put_contents($root . '/docs/heartbeat.json', json_encode([
    'at' => date('c'),
    'doing' => $argv[1] ?? 'working',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

echo "heartbeat: " . ($argv[1] ?? 'working') . "\n";
