<?php
/**
 * REQUIREMENT → BUILD TRACEABILITY — Part XXXV.
 *
 *   php tools/programme/traceability.php              summary by part
 *   php tools/programme/traceability.php R-XIV        one part, requirement by requirement
 *   php tools/programme/traceability.php --uncited    only what nothing references
 *
 * WHAT THIS TOOL PROVES, AND WHAT IT DOES NOT.
 *
 * It reads every requirement identifier from the generated register and asks one
 * mechanical question of the build: does anything under `src/`, `tests/` or
 * `tools/` name it? That is a real question with a real answer, and it finds the
 * requirements nothing in this repository has ever mentioned.
 *
 * It is NOT a conformance report, and saying so is the whole reason this header
 * is long. A citation is evidence that somebody was thinking about a requirement
 * at the moment they wrote a line; it is not evidence that the line satisfies it.
 * A requirement cited in a docblock and implemented wrongly looks exactly like
 * one cited and implemented correctly. Presenting a citation count as "97%
 * compliant" would be the same species of claim as SmartPRS 2's `score => 92` —
 * a number that reads as a verdict and was never computed from evidence.
 *
 * So the output separates three states, and the middle one is the useful one:
 *
 *   CITED IN src/       an implementation names it — attention, not proof
 *   CITED ONLY IN tests/ or tools/   a CHECK names it, which is stronger: something
 *                       runs and fails if it stops holding
 *   UNCITED             nothing in this repository has ever mentioned it
 *
 * The uncited list is what this tool is for. Some of those requirements belong to
 * parts this build has not reached (the SPA, the API surface, the control plane);
 * others are ones that were simply missed. The tool cannot tell those apart, and
 * does not pretend to — it prints the list and leaves the judgement to a reader.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$argument = $argv[1] ?? null;

$register = $root . '/docs/registers/REGISTER-REQUIREMENTS.md';

if (!is_file($register)) {
    echo "The requirements register is missing.\n";

    exit(1);
}

// ---- Every requirement identifier the blueprint defines ----------------------
//
// Read from the DOCS, not from the register's summary table: the register
// tabulates counts per part, and what is needed here is the identifiers
// themselves. Taking them from the source of truth also means a requirement
// added to a part appears here on the next run with no list to maintain.

$requirements = [];

foreach (glob($root . '/docs/*.md') ?: [] as $doc) {
    preg_match_all('/\b(R-[IVXL]+-\d{3}|R-[IVXL]+-\d{2}|R-\d{2,3})\b/',
        (string) file_get_contents($doc), $m);

    foreach ($m[1] as $id) {
        preg_match('/^R-([IVXL]+|\d+)-?/', $id, $pm);
        $requirements[$id] = 'R-' . ($pm[1] ?? '?');
    }
}

ksort($requirements);

if ($requirements === []) {
    echo "No requirement identifiers found.\n";

    exit(1);
}

// ---- What the build says about each -----------------------------------------

/** @return array{src: int, checks: int} */
function citationsOf(string $id, array $corpus): array
{
    $src = 0;
    $checks = 0;

    foreach ($corpus as $path => $content) {
        if (!str_contains($content, $id)) {
            continue;
        }

        // A word-boundary check, so R-XIV-06 does not match R-XIV-068.
        if (preg_match('/\b' . preg_quote($id, '/') . '\b/', $content) !== 1) {
            continue;
        }

        if (str_starts_with($path, 'src/')) {
            $src++;
        } else {
            $checks++;
        }
    }

    return ['src' => $src, 'checks' => $checks];
}

$corpus = [];

foreach (['src', 'tests', 'tools', 'database', 'public'] as $dir) {
    if (!is_dir($root . '/' . $dir)) {
        continue;
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir)) as $file) {
        // This file is excluded from its own corpus. A tool that counts its own
        // examples as citations reports coverage it created by being written,
        // which is the self-referential contamination this build keeps refusing.
        if ($file->getPathname() === __FILE__) {
            continue;
        }

        if ($file->isFile() && in_array($file->getExtension(), ['php', 'json', 'yaml', 'yml'], true)) {
            $corpus[str_replace($root . '/', '', $file->getPathname())] =
                (string) file_get_contents($file->getPathname());
        }
    }
}

$byPart = [];
$rows = [];

foreach ($requirements as $id => $part) {
    $citations = citationsOf($id, $corpus);

    $state = match (true) {
        $citations['checks'] > 0 => 'checked',
        $citations['src'] > 0 => 'cited',
        default => 'uncited',
    };

    $rows[$id] = ['part' => $part, 'state' => $state] + $citations;
    $byPart[$part][$state] = ($byPart[$part][$state] ?? 0) + 1;
}

// ---- Output ------------------------------------------------------------------

echo "\n  SmartPRS 3 — requirement traceability (Part XXXV)\n";
echo '  ' . str_repeat('=', 92) . "\n";

if ($argument === '--uncited') {
    $uncited = array_filter($rows, static fn (array $r): bool => $r['state'] === 'uncited');

    printf("\n  %d requirement(s) that NOTHING in this repository names.\n\n", count($uncited));
    echo "  Some belong to parts this build has not reached — the SPA, the public API surface, the\n"
        . "  SaaS control plane. Others were simply missed. This tool cannot tell those apart and\n"
        . "  does not guess; the judgement is a reader's.\n\n";

    $currentPart = '';

    foreach ($uncited as $id => $row) {
        if ($row['part'] !== $currentPart) {
            $currentPart = $row['part'];
            printf("\n    %s\n      ", $currentPart);
            $column = 0;
        }

        printf('%-14s', $id);

        if (++$column % 6 === 0) {
            echo "\n      ";
        }
    }

    echo "\n";

    exit(0);
}

if ($argument !== null && str_starts_with($argument, 'R-')) {
    $filtered = array_filter($rows, static fn (array $r): bool => $r['part'] === rtrim($argument, '-'));

    printf("\n  %s — %d requirement(s)\n\n", $argument, count($filtered));

    foreach ($filtered as $id => $row) {
        printf("    %s  %-14s %s\n",
            match ($row['state']) {
                'checked' => "\033[32m✓\033[0m",
                'cited' => "\033[33m·\033[0m",
                default => "\033[31m✗\033[0m",
            },
            $id,
            match ($row['state']) {
                'checked' => "named by {$row['checks']} check(s) and {$row['src']} source file(s) — "
                    . 'something RUNS and fails if this stops holding',
                'cited' => "named by {$row['src']} source file(s), by no check — attention, not proof",
                default => 'named by nothing in this repository',
            });
    }

    exit(0);
}

$totals = ['checked' => 0, 'cited' => 0, 'uncited' => 0];

printf("\n  %-10s %8s %10s %8s %10s   %s\n", 'PART', 'DEFINED', 'CHECKED', 'CITED', 'UNCITED', '');
echo '  ' . str_repeat('-', 92) . "\n";

uksort($byPart, static fn (string $a, string $b): int => strcmp($a, $b));

foreach ($byPart as $part => $counts) {
    $checked = $counts['checked'] ?? 0;
    $cited = $counts['cited'] ?? 0;
    $uncited = $counts['uncited'] ?? 0;
    $defined = $checked + $cited + $uncited;

    $totals['checked'] += $checked;
    $totals['cited'] += $cited;
    $totals['uncited'] += $uncited;

    // A bar, because forty rows of numbers is a table nobody reads twice.
    $width = 24;
    $bar = str_repeat('█', (int) round($checked / max($defined, 1) * $width))
        . str_repeat('▒', (int) round($cited / max($defined, 1) * $width));

    printf("  %-10s %8d %10d %8d %10d   %s\n", $part, $defined, $checked, $cited, $uncited, $bar);
}

echo '  ' . str_repeat('-', 92) . "\n";

$defined = array_sum($totals);

printf("  %-10s %8d %10d %8d %10d\n\n", 'TOTAL', $defined,
    $totals['checked'], $totals['cited'], $totals['uncited']);

printf("  █ CHECKED  %d (%d%%) — a test or a gate names it, so something RUNS and fails if it stops\n"
    . "             holding. This is the only one of the three that is evidence.\n\n",
    $totals['checked'], (int) round($totals['checked'] / $defined * 100));

printf("  ▒ CITED    %d (%d%%) — a source file names it. That means somebody was thinking about the\n"
    . "             requirement when they wrote the line. It does NOT mean the line satisfies it: a\n"
    . "             requirement cited and implemented wrongly looks exactly like one cited and\n"
    . "             implemented correctly.\n\n",
    $totals['cited'], (int) round($totals['cited'] / $defined * 100));

printf("    UNCITED  %d (%d%%) — nothing in this repository names it. Run with --uncited to list them.\n\n",
    $totals['uncited'], (int) round($totals['uncited'] / $defined * 100));

echo "  This is a traceability report, NOT a conformance report. Presenting the first two columns\n"
    . "  added together as a compliance percentage would be the same species of claim as SmartPRS 2's\n"
    . "  `score => 92`: a number that reads as a verdict and was never computed from evidence.\n\n";

echo "  php tools/programme/traceability.php R-XIV        one part, requirement by requirement\n";
echo "  php tools/programme/traceability.php --uncited    everything nothing references\n\n";
