<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * A TABLE AS A REAL .pdf FILE — no library.
 *
 * `vendor/` carries no dompdf/TCPDF/mPDF and adding one is a dependency the
 * customer installs; a report of rows and columns needs none of that. This
 * writes PDF 1.4 by hand: A4 landscape, the built-in Helvetica (WinAnsi, so
 * text outside Latin-1 — the rupee sign — is written as "Rs."), a title band,
 * a header row repeated on every page, alternate row shading and page numbers.
 * Column widths follow the longest text in each column, scaled to the page.
 *
 * NOTHING IS CUT OFF (owner, 23 Oct): a cell longer than its column wraps onto more lines and its
 * row grows to fit; pages break on the rows' real heights.
 */
final class TablePdf
{
    private const W = 842.0;   // A4 landscape, points
    private const H = 595.0;
    private const M = 28.0;    // margin
    private const ROW = 15.0;
    private const FS = 7.5;
    private const LH = 9.0;    // line height inside a wrapped cell

    /**
     * @param list<string> $head
     * @param list<list<string>> $rows
     * @param list<int> $right column indexes to right-align (amounts, counts)
     */
    public static function render(string $title, string $subtitle, array $head, array $rows, array $right = []): string
    {
        $cols = count($head);
        $len = array_map(static fn (string $h): int => max(4, mb_strlen($h)), $head);

        foreach ($rows as $r) {
            foreach ($r as $i => $c) {
                $len[$i] = max($len[$i] ?? 4, min(40, mb_strlen((string) $c)));
            }
        }

        $usable = self::W - 2 * self::M;
        // Each column at least as wide as its longest single word (codes, amounts, dates are never
        // split), the rest of the page shared by text length.
        $word = array_fill(0, $cols, 3);
        foreach (array_merge([$head], $rows) as $r) {
            foreach ($r as $i => $c) {
                foreach (preg_split('/\s+/', (string) $c) ?: [] as $wd) {
                    $word[$i] = max($word[$i] ?? 3, min(22, mb_strlen($wd)));
                }
            }
        }
        $minW = array_map(static fn (int $n): float => $n * self::FS * 0.56 + 8, $word);
        $free = $usable - array_sum($minW);
        if ($free < 0) {
            $width = array_map(static fn (float $m): float => $m * $usable / array_sum($minW), $minW);
        } else {
            $sum = array_sum($len) ?: 1;
            $width = array_map(static fn (float $m, int $l): float => $m + $free * $l / $sum, $minW, $len);
        }
        $wrapRow = static function (array $r, bool $upper) use ($cols, $width): array {
            $cells = [];
            for ($i = 0; $i < $cols; $i++) {
                $t = (string) ($r[$i] ?? '');
                $cells[] = self::wrap($upper ? strtoupper($t) : $t, $width[$i]);
            }
            return $cells;
        };
        $rowH = static fn (array $cells): float => self::ROW + (max(array_map('count', $cells) ?: [1]) - 1) * self::LH;
        $headCells = $wrapRow($head, true);
        $headH = $rowH($headCells);
        $top = self::H - self::M - 58;
        $bottom = self::M + 6;

        // Pages by real row heights.
        $chunks = [[]];
        $room = $top - $headH - $bottom;
        foreach ($rows as $r) {
            $cells = $wrapRow($r, false);
            $h = $rowH($cells);
            if ($chunks[count($chunks) - 1] !== [] && $h > $room) {
                $chunks[] = [];
                $room = $top - $headH - $bottom;
            }
            $chunks[count($chunks) - 1][] = $cells;
            $room -= $h;
        }
        $pages = [];

        foreach ($chunks as $p => $chunk) {
            $s = '';
            // Title band (navy) and subtitle.
            $s .= "0.122 0.227 0.373 rg " . self::M . ' ' . (self::H - self::M - 26) . ' ' . $usable . " 26 re f\n";
            $s .= self::text(self::M + 8, self::H - self::M - 17, 12, $title, [1, 1, 1], true);
            $s .= self::text(self::M, self::H - self::M - 40, 8, $subtitle, [0.28, 0.33, 0.41]);
            $y = $top;
            // Header row.
            $s .= "0.886 0.910 0.941 rg " . self::M . ' ' . ($y - 4 - ($headH - self::ROW)) . ' ' . $usable . ' ' . $headH . " re f\n";
            $s .= self::rowText($y, $width, $headCells, $right, true);
            $y -= $headH - self::ROW;

            foreach ($chunk as $n => $cells) {
                $h = $rowH($cells);
                $y -= self::ROW;

                if ($n % 2 === 1) {
                    $s .= "0.973 0.980 0.988 rg " . self::M . ' ' . ($y - 4 - ($h - self::ROW)) . ' ' . $usable . ' ' . $h . " re f\n";
                }

                $s .= self::rowText($y, $width, $cells, $right, false);
                $y -= $h - self::ROW;
            }

            if ($rows === []) {
                $s .= self::text(self::M + 4, $y - self::ROW, self::FS, 'No rows.', [0.4, 0.4, 0.4]);
            }

            $s .= self::text(self::W - self::M - 70, self::M - 12, 7, 'Page ' . ($p + 1) . ' of ' . count($chunks), [0.4, 0.4, 0.4]);
            $s .= self::text(self::M, self::M - 12, 7, 'SmartPRS · generated ' . date('Y-m-d H:i'), [0.4, 0.4, 0.4]);
            $pages[] = $s;
        }

        return self::document($pages);
    }

    /** @param list<list<string>> $cells */
    private static function rowText(float $y, array $width, array $cells, array $right, bool $bold): string
    {
        $s = '';
        $x = self::M;
        foreach ($cells as $i => $lines) {
            foreach ($lines as $k => $line) {
                $s .= self::cell($x, $y - $k * self::LH, $width[$i], $line, in_array($i, $right, true), $bold);
            }
            $x += $width[$i];
        }
        return $s;
    }

    /** The text broken into lines that fit $w — at spaces where it can, mid-word where it must. */
    private static function wrap(string $t, float $w): array
    {
        $max = max(1, (int) floor(($w - 6) / (self::FS * 0.5)));
        $lines = [];
        foreach (preg_split('/\s+/', trim($t)) ?: [] as $word) {
            while (mb_strlen($word) > $max) {
                $cur = $lines === [] ? '' : array_pop($lines);
                if ($cur !== '' && mb_strlen($cur) + 1 < $max) {
                    $take = $max - mb_strlen($cur) - 1;
                    $lines[] = $cur . ' ' . mb_substr($word, 0, $take);
                } else {
                    $cur === '' || $lines[] = $cur;
                    $take = $max;
                    $lines[] = mb_substr($word, 0, $take);
                }
                $word = mb_substr($word, $take);
            }
            $last = $lines === [] ? null : $lines[count($lines) - 1];
            if ($last !== null && mb_strlen($last) + 1 + mb_strlen($word) <= $max) {
                $lines[count($lines) - 1] = $last . ' ' . $word;
            } elseif ($word !== '') {
                $lines[] = $word;
            }
        }
        return $lines === [] ? [''] : $lines;
    }

    private static function cell(float $x, float $y, float $w, string $t, bool $right, bool $bold): string
    {
        $tx = $right ? $x + $w - 3 - mb_strlen($t) * self::FS * ($bold ? 0.62 : 0.5) : $x + 3;

        return self::text($tx, $y, self::FS, $t, [0.06, 0.09, 0.16], $bold);
    }

    /** @param array{0: float|int, 1: float|int, 2: float|int} $rgb */
    private static function text(float $x, float $y, float $size, string $t, array $rgb, bool $bold = false): string
    {
        $t = str_replace(['₹', '—', '–', '…'], ['Rs.', '-', '-', '...'], Dates::inText($t));
        $t = (string) @iconv('UTF-8', 'Windows-1252//TRANSLIT', $t);
        $t = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $t);

        return sprintf("%.3f %.3f %.3f rg BT /%s %.1f Tf %.2f %.2f Td (%s) Tj ET\n",
            $rgb[0], $rgb[1], $rgb[2], $bold ? 'F2' : 'F1', $size, $x, $y, $t);
    }

    /** @param list<string> $pages content streams */
    private static function document(array $pages): string
    {
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $next = 5;

        foreach ($pages as $content) {
            $pageId = $next++;
            $streamId = $next++;
            $kids[] = "{$pageId} 0 R";
            $objs[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . '] '
                . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$streamId} 0 R >>";
            $objs[$streamId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
        }

        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);
        $out = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($out);
        $out .= 'xref' . "\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";

        foreach ($offsets as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }

        return $out . 'trailer << /Size ' . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }
}
