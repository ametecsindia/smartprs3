<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * CODE 128 (SUBSET B), WRITTEN BY HAND — no PDF library, no barcode library,
 * same house rule as IdCardPdf: "no new dependency without asking a human".
 * Subset B covers the full printable ASCII range (32-126), which is every
 * character an employee code or visitor type label can actually contain.
 *
 * A TRUE QR CODE WAS CONSIDERED AND DROPPED (11 Sep) — QR needs Reed-Solomon
 * error correction and a matrix placement algorithm; hand-writing that
 * correctly, with no way to verify it against a real scanner in this
 * environment, risked shipping something that LOOKS like a QR code and does
 * not scan. Code 128 is a single lookup table plus one checksum sum — small
 * enough to hand-write correctly and, unlike QR, this repository already
 * trusts a hand-written binary format (IdCardPdf) built the same way.
 * ponytail: Code 128, not QR — a real QR reader was not available in this
 * environment to verify a hand-written encoder against; revisit if a QR
 * code becomes a hard requirement rather than "basic and scannable".
 *
 * @see https://en.wikipedia.org/wiki/Code_128 — subset B pattern table, the
 *   public specification this class implements; nothing here is any other
 *   party's code, only the widths the standard itself defines.
 */
final class Barcode
{
    /**
     * Subset B patterns for symbol values 0-102, each 6 widths (bar, space,
     * bar, space, bar, space) in modules, summing to 11. Index = symbol value
     * = ord(char) - 32 for a printable ASCII data character; the same table
     * is reused to encode the checksum digit (0-102), whatever it turns out
     * to be, regardless of what character that row nominally represents.
     */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411311', '113141', '114131',
        '311141', '411131', '211412',
    ];

    private const START_B = '211214';
    private const STOP = '2331112';

    /**
     * The full symbol as a string of modules — '1' ink, '0' gap, one
     * character per module — so a caller (HTML, or IdCardPdf) just draws a
     * unit-width rectangle for each '1' run and skips each '0' run. Nobody
     * downstream needs to know a barcode format exists.
     *
     * @return string module bits, or '' if $data has a character outside
     *   printable ASCII (32-126) Code 128 subset B cannot carry
     */
    public static function code128(string $data): string
    {
        if ($data === '' || preg_match('/^[\x20-\x7E]+$/', $data) !== 1) {
            return '';
        }

        $values = array_map(static fn (string $ch): int => ord($ch) - 32, str_split($data));
        $checksum = 104; // Start B's own value, weight 1 at position 0

        foreach ($values as $i => $v) {
            $checksum += $v * ($i + 1);
        }

        $checksum %= 103;

        $widths = [self::START_B];

        foreach ($values as $v) {
            $widths[] = self::PATTERNS[$v];
        }

        $widths[] = self::PATTERNS[$checksum];
        $widths[] = self::STOP;

        $modules = '';

        foreach ($widths as $pattern) {
            $ink = true; // every symbol's pattern starts on a bar

            foreach (str_split($pattern) as $w) {
                $modules .= str_repeat($ink ? '1' : '0', (int) $w);
                $ink = !$ink;
            }
        }

        return $modules;
    }
}
