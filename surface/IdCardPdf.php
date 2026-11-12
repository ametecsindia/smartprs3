<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * AN ID CARD AS A PDF, WRITTEN BY HAND.
 *
 * No PDF library is installed and the repository rule is "no new dependency
 * without asking a human". A CR80 card is a rectangle, three colours, a dozen
 * lines of text and one photograph — a page a PDF writer of ninety lines emits
 * correctly, so that is what this is. Text is the base-14 Helvetica (no font
 * embedding, so no Unicode beyond Latin-1: names outside it are transliterated
 * and the screen says so). A JPEG photograph is embedded as-is (DCTDecode); a PNG
 * or WebP photo is not embedded — the PDF says "photo on file" in its place,
 * because decoding PNG by hand is where a ninety-line writer stops being honest.
 *
 * Every value comes from the employee record. Nothing is typed onto the card.
 */
final class IdCardPdf
{
    /** CR80: 85.60 × 53.98 mm, in points. */
    private const W = 242.65;
    private const H = 153.01;

    /** Width, in points, of the 'stripe' header style's coloured side bar. */
    private const STRIPE_W = 14.0;

    // TEXT FITTING (11 Sep) — this writer has no font-metrics table and no PDF
    // library, so nothing ever checked whether a company name or a field value
    // actually fit before this; a long one just printed past the card edge or
    // over the next line, silently. No embedded AFM width table either (a real
    // one is ~230 entries per font and this is a 90-line writer on purpose) —
    // instead every character is bucketed narrow/normal/wide/upper-digit by
    // its own shape, which is within a few percent of the real Helvetica
    // metrics for ordinary business text (names, addresses, departments) and
    // is all fitting/truncation needs: close enough to decide "does this fit",
    // not exact enough to kern with.
    // ponytail: 4-bucket width heuristic, not a real AFM table — upgrade if a
    // font ever needs exact kerning (this writer never will, it only fits).
    private const NARROW = "ijl.,:;'`!|I ";
    private const WIDE = 'mwMW@%';

    private static function charWeight(string $ch): float
    {
        if (str_contains(self::NARROW, $ch)) {
            return 0.28;
        }

        if (str_contains(self::WIDE, $ch)) {
            return 0.82;
        }

        if (ctype_upper($ch) || ctype_digit($ch)) {
            return 0.64;
        }

        if (ctype_lower($ch)) {
            return 0.52;
        }

        return 0.5;
    }

    /** Approximate width of $s set at $size points, in points. */
    public static function textWidth(string $s, float $size, bool $bold = false): float
    {
        $w = 0.0;

        foreach (str_split($s) as $ch) {
            $w += self::charWeight($ch);
        }

        return $w * $size * ($bold ? 1.08 : 1.0);
    }

    /**
     * NOTHING ON A CARD IS CUT OFF (owner, 23 Oct): the largest size, from $size down to $min, at
     * which $s fits on one line in $maxWidth. Used for short texts (name, labels, footer) so the
     * whole text prints, smaller if it must, instead of "EMERGENCY CONT...".
     */
    public static function sizeFor(string $s, float $maxWidth, float $size, bool $bold = false, float $min = 4.5): float
    {
        while ($size > $min && self::textWidth($s, $size, $bold) > $maxWidth) {
            $size -= 0.5;
        }

        return max($min, $size);
    }

    /**
     * A value wrapped onto as many lines as it needs (up to 3 at $size, else smaller type), long
     * single words such as e-mail addresses broken by character — never trimmed (owner, 23 Oct).
     *
     * @return array{0: float, 1: list<string>}
     */
    public static function fitLines(string $s, float $maxWidth, float $size, bool $bold = false, float $min = 5.0): array
    {
        for ($sz = $size; ; $sz -= 0.5) {
            $lines = [];
            foreach (self::wrapLines($s, $maxWidth, $sz, $bold, PHP_INT_MAX) as $line) {
                while (mb_strlen($line) > 1 && self::textWidth($line, $sz, $bold) > $maxWidth) {
                    $n = mb_strlen($line) - 1;
                    while ($n > 1 && self::textWidth(mb_substr($line, 0, $n), $sz, $bold) > $maxWidth) {
                        $n--;
                    }
                    $lines[] = mb_substr($line, 0, $n);
                    $line = mb_substr($line, $n);
                }
                $lines[] = $line;
            }
            if (count($lines) <= 3 || $sz - 0.5 < $min) {
                return [$sz, $lines];
            }
        }
    }

    /** $s if it already fits in $maxWidth points at $size; otherwise trimmed with a trailing "...". */
    public static function fitOneLine(string $s, float $maxWidth, float $size, bool $bold = false): string
    {
        if ($maxWidth <= 0 || self::textWidth($s, $size, $bold) <= $maxWidth) {
            return $s;
        }

        $n = mb_strlen($s);

        while ($n > 0 && self::textWidth(mb_substr($s, 0, $n) . '...', $size, $bold) > $maxWidth) {
            $n--;
        }

        return $n === 0 ? '...' : mb_substr($s, 0, $n) . '...';
    }

    /**
     * $s word-wrapped to fit $maxWidth points at $size, at most $maxLines lines; the
     * last line is ellipsized (via fitOneLine) if words remain past $maxLines. A
     * single word that alone exceeds $maxWidth is placed on its own line unbroken
     * (rare with real names/addresses; not worth hard character-splitting for).
     *
     * @return list<string>
     */
    public static function wrapLines(string $s, float $maxWidth, float $size, bool $bold, int $maxLines): array
    {
        $words = preg_split('/\s+/', trim($s)) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $tried = $current === '' ? $word : $current . ' ' . $word;

            if ($current !== '' && self::textWidth($tried, $size, $bold) > $maxWidth) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $tried;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        if ($lines === []) {
            return [''];
        }

        if (count($lines) <= $maxLines) {
            return $lines;
        }

        // More lines came out than the budget allows -- keep the first ones as-is
        // and fold everything past that into one final line, fitOneLine()
        // trimming it with "..." if it still doesn't fit as a single line.
        return $lines; // never trimmed with "..." (owner, 23 Oct) — the caller stops at the card's edge
    }

    /**
     * Path-construction operators (no paint operator — caller adds f/S/W n) for
     * the photo box: a rounded rectangle for 'square'/'rounded', an ellipse
     * (already forced to a circle by the caller when width=height) for
     * 'oval'/'circle'. Raw PDF has neither primitive natively — both are the
     * standard 4-Bezier approximation (kappa ≈0.5523), same trick every real
     * PDF/SVG renderer uses for a rounded rect or a circle.
     */
    private static function shapePath(float $x, float $y, float $w, float $h, string $shape): string
    {
        if ($shape === 'oval' || $shape === 'circle') {
            return self::ellipsePath($x, $y, $w, $h);
        }

        return self::roundedRectPath($x, $y, $w, $h, $shape === 'rounded' ? min(16.0, $w / 2, $h / 2) : min(4.0, $w / 2, $h / 2));
    }

    private static function roundedRectPath(float $x, float $y, float $w, float $h, float $r): string
    {
        $k = 0.5523 * $r;
        $x2 = $x + $w;
        $y2 = $y + $h;
        $p = static fn (float ...$n): string => implode(' ', array_map(static fn (float $v): string => sprintf('%.2f', $v), $n));

        return $p($x + $r, $y) . ' m ' . $p($x2 - $r, $y) . ' l '
            . $p($x2 - $r + $k, $y, $x2, $y + $r - $k, $x2, $y + $r) . ' c ' . $p($x2, $y2 - $r) . ' l '
            . $p($x2, $y2 - $r + $k, $x2 - $r + $k, $y2, $x2 - $r, $y2) . ' c ' . $p($x + $r, $y2) . ' l '
            . $p($x + $r - $k, $y2, $x, $y2 - $r + $k, $x, $y2 - $r) . ' c ' . $p($x, $y + $r) . ' l '
            . $p($x, $y + $r - $k, $x + $r - $k, $y, $x + $r, $y) . ' c h';
    }

    private static function ellipsePath(float $x, float $y, float $w, float $h): string
    {
        $rx = $w / 2;
        $ry = $h / 2;
        $cx = $x + $rx;
        $cy = $y + $ry;
        $kx = 0.5523 * $rx;
        $ky = 0.5523 * $ry;
        $p = static fn (float ...$n): string => implode(' ', array_map(static fn (float $v): string => sprintf('%.2f', $v), $n));

        return $p($cx + $rx, $cy) . ' m '
            . $p($cx + $rx, $cy + $ky, $cx + $kx, $cy + $ry, $cx, $cy + $ry) . ' c '
            . $p($cx - $kx, $cy + $ry, $cx - $rx, $cy + $ky, $cx - $rx, $cy) . ' c '
            . $p($cx - $rx, $cy - $ky, $cx - $kx, $cy - $ry, $cx, $cy - $ry) . ' c '
            . $p($cx + $kx, $cy - $ry, $cx + $rx, $cy - $ky, $cx + $rx, $cy) . ' c h';
    }

    /**
     * THE HEADER BAND'S SILHOUETTE (11 Sep, template gallery) — 'solid' is the
     * plain colour rectangle this always was; the other 4 give it a different
     * outline instead (a diagonal cut, an arc/wave bottom edge, a side stripe,
     * or no band at all — 'badge' rings the photo in the header colour instead,
     * drawn later in renderCard() once the photo box position is known). Same 5
     * shapes the HTML/JS renderers draw with CSS clip-path/box-shadow — see
     * idCardHtml() in public/app.php.
     */
    private static function headerBandOp(string $style, int $wN, int $hN, int $band, string $headerFill): string
    {
        $y0 = $hN - $band;

        return match ($style) {
            // Cut on the RIGHT (where the title badge sits, if anything) — the
            // LEFT stays full height, since that's where the company name and
            // tagline print and a cut there was clipping real text (11 Sep).
            'diagonal' => $headerFill . ' rg ' . sprintf('0 %d m %d %d l %d %.2f l 0 %d l h f', $hN, $wN, $hN, $wN, $y0 + $band * 0.55, $y0),
            'arc' => $headerFill . ' rg ' . self::archPath($wN, $hN, $band) . ' f',
            'stripe' => $headerFill . ' rg 0 0 ' . self::STRIPE_W . ' ' . $hN . ' re f',
            'badge' => '', // no band — the ring behind the photo carries the colour
            default => $headerFill . ' rg 0 ' . $y0 . ' ' . $wN . ' ' . $band . ' re f',
        };
    }

    /** A shallow single-bezier dip across the header's bottom edge — a "wave". */
    private static function archPath(int $wN, int $hN, int $band): string
    {
        $y0 = $hN - $band;
        $dip = min(14.0, $band * 0.4);
        $midY = $y0 - $dip;
        $p = static fn (float ...$n): string => implode(' ', array_map(static fn (float $v): string => sprintf('%.2f', $v), $n));

        return $p(0, $hN) . ' m ' . $p($wN, $hN) . ' l ' . $p($wN, $y0) . ' l '
            . $p($wN * 0.66, $midY, $wN * 0.33, $midY, 0.0, $y0) . ' c h';
    }

    /**
     * @param array<string, string> $lines label => value, printed in order
     * @param string|null $jpeg raw JPEG bytes of the photograph, or null
     * @return string the PDF file
     */
    public static function render(string $company, string $tagline, string $name, string $designation,
        array $lines, ?string $jpeg, array $jpegSize = [0, 0], string $footer = ''): string
    {
        $t = static fn (string $s): string => self::pdfString($s);
        $navy = '0.122 0.227 0.373 rg';
        $gold = '0.722 0.537 0.231 rg';
        $content = [];
        $content[] = '1 1 1 rg 0 0 ' . self::W . ' ' . self::H . ' re f';                 // white card
        $content[] = $navy . ' 0 ' . (self::H - 34) . ' ' . self::W . ' 34 re f';           // header band
        $content[] = $gold . ' 0 0 ' . self::W . ' 6 re f';                                 // gold foot
        $content[] = '1 1 1 rg BT /F2 11 Tf 10 ' . (self::H - 15) . ' Td ' . $t($company) . ' Tj ET';
        $content[] = '1 1 1 rg BT /F1 6.5 Tf 10 ' . (self::H - 27) . ' Td ' . $t($tagline) . ' Tj ET';

        // photo box
        $px = 10;
        $py = 18;
        $pw = 62;
        $ph = 80;
        $content[] = '0.94 0.95 0.96 rg ' . $px . ' ' . $py . ' ' . $pw . ' ' . $ph . ' re f';
        $content[] = '0.75 0.75 0.75 RG 0.5 w ' . $px . ' ' . $py . ' ' . $pw . ' ' . $ph . ' re S';

        if ($jpeg !== null) {
            $content[] = 'q ' . $pw . ' 0 0 ' . $ph . ' ' . $px . ' ' . $py . ' cm /Im1 Do Q';
        } else {
            $content[] = '0.5 0.5 0.5 rg BT /F1 6 Tf ' . ($px + 8) . ' ' . ($py + $ph / 2) . ' Td (photo on file) Tj ET';
        }

        $x = $px + $pw + 10;
        $y = self::H - 50;
        $content[] = $navy . ' BT /F2 12 Tf ' . $x . ' ' . $y . ' Td ' . $t($name) . ' Tj ET';
        $y -= 12;
        $content[] = '0.2 0.2 0.2 rg BT /F1 8 Tf ' . $x . ' ' . $y . ' Td ' . $t($designation) . ' Tj ET';
        $y -= 13;

        foreach ($lines as $label => $value) {
            if (trim((string) $value) === '') {
                continue;
            }

            $content[] = '0.45 0.45 0.45 rg BT /F1 6.5 Tf ' . $x . ' ' . $y . ' Td ' . $t(strtoupper((string) $label)) . ' Tj ET';
            $content[] = '0.1 0.1 0.1 rg BT /F1 8 Tf ' . ($x + 62) . ' ' . $y . ' Td ' . $t((string) $value) . ' Tj ET';
            $y -= 11;

            if ($y < 14) {
                break;
            }
        }

        if ($footer !== '') {
            $content[] = '0.45 0.45 0.45 rg BT /F1 5.5 Tf 10 9 Td ' . $t($footer) . ' Tj ET';
        }

        $stream = implode("\n", $content);

        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $resources = '/Font << /F1 5 0 R /F2 6 0 R >>' . ($jpeg !== null ? ' /XObject << /Im1 7 0 R >>' : '');
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . '] /Contents 4 0 R /Resources << ' . $resources . ' >> >>';
        $objects[] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        if ($jpeg !== null) {
            $objects[] = '<< /Type /XObject /Subtype /Image /Width ' . (int) $jpegSize[0] . ' /Height ' . (int) $jpegSize[1]
                . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($jpeg) . " >>\nstream\n"
                . $jpeg . "\nendstream";
        }

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref
0 ' . (count($objects) + 1) . "
0000000000 65535 f 
";

        foreach ($offsets as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }

        $pdf .= '<< /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf = str_replace("<< /Size", "trailer\n<< /Size", $pdf);
        $pdf .= "startxref\n" . $xref . "\n%%EOF\n";

        return $pdf;
    }

    /**
     * A card in the tenant's FORMAT (see IdCardFormat): size, orientation,
     * colours, chosen fields, logo, photograph, footer.
     *
     * @param array{w: string, h: string, portrait: bool, colors: array<string, string>, company: string,
     *   tagline: string, title: string, name: string, designation: string,
     *   lines: list<array{label: string, value: string, kind: string}>,
     *   photo: ?array{bytes: string, size: array{int, int}}, logo: ?array{bytes: string, size: array{int, int}},
     *   footer: string, show_photo: bool, no_photo_label?: string, back_text?: string,
     *   header_style?: string} $card  w/h in points as decimal strings.
     *   back_text, when non-blank, adds a 2nd page: the same colours, a wrapped block of that text, nothing else.
     */
    public static function renderCard(array $card): string
    {
        $t = static fn (string $s): string => self::pdfString($s);
        $W = $card['w'];
        $H = $card['h'];
        // Whole points for the layout arithmetic: the decimal strings come from
        // IdCardFormat::points() and the part before the dot is the integer.
        $wN = (int) explode('.', $W)[0];
        $hN = (int) explode('.', $H)[0];
        $c = $card['colors'];
        $rgb = static fn (string $hex): string => IdCardFormat::rgb($hex);
        $portrait = $card['portrait'];
        $hs = (string) ($card['header_style'] ?? 'solid');
        // 'stripe'/'badge' put no colour behind the header text (a side bar, or
        // nothing at all — the badge ring is drawn later, behind the photo), so
        // the text itself switches from header_text-on-header to text-on-background.
        $headerFg = in_array($hs, ['stripe', 'badge'], true) ? $c['text'] : $c['header_text'];
        // Everything that otherwise hugs the left edge (header text, the photo
        // box, the footer) shifts right by the stripe's own width so nothing
        // prints under it — the stripe runs the card's FULL height, not just
        // the header row.
        $leftPad = $hs === 'stripe' ? (int) self::STRIPE_W + 4 : 0;
        $textX = ($card['logo'] !== null ? 40 : 10) + $leftPad;
        $maxTextW = max(10, $wN - $textX - 10);

        // Wrapped BEFORE the header band is drawn (11 Sep — the band used to be a
        // fixed height and a long company name just printed past its bottom edge
        // or over the tagline beneath it): a name that needs a 2nd line grows the
        // band by 10pt so nothing prints outside it or on top of the tagline.
        $companyLines = $card['show_company_name'] ? self::wrapLines($card['company'], $maxTextW, 11, true, 2) : [];
        $band = ($portrait ? 44 : 34) + (count($companyLines) > 1 ? 10 : 0);

        $out = [];
        $out[] = $rgb($c['background']) . ' rg 0 0 ' . $W . ' ' . $H . ' re f';
        $out[] = self::headerBandOp($hs, $wN, $hN, $band, $rgb($c['header']));
        $out[] = $rgb($c['accent']) . ' rg 0 0 ' . $W . ' 6 re f';

        if ($card['logo'] !== null) {
            $out[] = 'q 26 0 0 26 8 ' . ($hN - $band + intdiv($band - 26, 2)) . ' cm /Im2 Do Q';
        }

        $headerTop = $hN - ($card['show_company_name'] ? 15 : 22);
        $afterCompany = $headerTop;

        foreach ($companyLines as $i => $line) {
            $out[] = $rgb($headerFg) . ' rg BT /F2 11 Tf ' . $textX . ' ' . ($headerTop - $i * 11) . ' Td ' . $t($line) . ' Tj ET';
            $afterCompany = $headerTop - $i * 11;
        }

        if ($card['tagline'] !== '') {
            $out[] = $rgb($headerFg) . ' rg BT /F1 ' . self::sizeFor($card['tagline'], $maxTextW, 6.5) . ' Tf ' . $textX . ' ' . ($afterCompany - 11) . ' Td '
                . $t($card['tagline']) . ' Tj ET';
        }

        if ($card['title'] !== '') {
            // Pinned near the TOP of the band, not the bottom (11 Sep) — 'diagonal'
            // and 'arc' both cut away colour near the band's bottom-right corner,
            // where this used to sit; header_text-coloured text over the (now
            // uncoloured) background there printed invisibly.
            $out[] = $rgb($headerFg) . ' rg BT /F2 7 Tf ' . ($wN - 10 - strlen($card['title']) * 4) . ' ' . ($hN - 14) . ' Td ' . $t($card['title']) . ' Tj ET';
        }

        // photo box and the column the text starts in
        $pw = $portrait ? 70 : 62;
        $ph = $portrait ? 86 : 80;
        $photoShape = (string) ($card['photo_shape'] ?? 'square');

        // A CIRCLE FORCES A TRUE SQUARE BOX FIRST (11 Sep) -- a rounded-corner
        // path at 50% radius on a non-square box already gives an oval (that IS
        // the "oval" option, no extra code needed for it); a circle just needs
        // the box itself square before the same 50% radius is applied.
        if ($photoShape === 'circle') {
            $ph = $pw = min($pw, $ph);
        }

        if ($portrait) {
            $px = intdiv($wN - $pw, 2);
            $py = $hN - $band - 8 - $ph;
            $x = 12 + $leftPad;
            $y = $py - 16;
        } else {
            $px = 10 + $leftPad;
            $py = 18;
            $x = $px + $pw + 10;
            $y = $hN - $band - 16;
        }

        if ($card['show_photo']) {
            $shapePath = self::shapePath((float) $px, (float) $py, (float) $pw, (float) $ph, $photoShape);

            // BADGE RING (11 Sep, template gallery) — header_style 'badge' has no
            // header band at all (see headerBandOp()); instead a circle in the
            // header colour, a bit larger than the photo box, sits BEHIND it —
            // drawn first so the photo box paints over its middle, leaving a ring.
            if ($hs === 'badge') {
                $ringR = max($pw, $ph) / 2 + 6;
                $ringCx = $px + $pw / 2;
                $ringCy = $py + $ph / 2;
                $out[] = $rgb($c['header']) . ' rg ' . self::ellipsePath((float) ($ringCx - $ringR), (float) ($ringCy - $ringR), (float) ($ringR * 2), (float) ($ringR * 2)) . ' f';
            }

            $out[] = '0.94 0.95 0.96 rg ' . $shapePath . ' f';

            $photoBorder = (string) ($card['photo_border'] ?? 'single');
            $photoBorderAmt = max(0, min(100, (int) ($card['photo_border_amount'] ?? 40)));

            if ($photoBorder === 'double') {
                $out[] = $rgb($c['header']) . ' RG 1 w ' . $shapePath . ' S';
                $innerPath = self::shapePath((float) $px + 3, (float) $py + 3, (float) $pw - 6, (float) $ph - 6, $photoShape);
                $out[] = $rgb($c['accent']) . ' RG 0.75 w ' . $innerPath . ' S';
            } elseif ($photoBorder === 'blur') {
                // NO SOFT-MASK/ALPHA MACHINERY IN A 90-LINE PDF WRITER -- a "blurred"
                // edge is approximated with a few concentric rings fading from white
                // toward mid-grey at full opacity, rather than true alpha blending
                // (which would need ExtGState objects this writer doesn't have).
                // ponytail: solid-colour ring fade, not a real blur — a genuine one
                // needs an ExtGState /ca soft mask, worth adding only if this
                // approximation ever looks wrong printed.
                $rings = max(1, (int) round($photoBorderAmt / 20));

                for ($ringI = 0; $ringI < $rings; $ringI++) {
                    $inset = 1 + $ringI * 1.5;
                    $shade = 1 - ($ringI / max(1, $rings)) * 0.5;
                    $shadeStr = sprintf('%.3f %.3f %.3f', $shade, $shade, $shade);
                    $ringPath = self::shapePath((float) $px + $inset, (float) $py + $inset, (float) $pw - 2 * $inset, (float) $ph - 2 * $inset, $photoShape);
                    $out[] = $shadeStr . ' RG 0.75 w ' . $ringPath . ' S';
                }
            } else {
                $out[] = '0.75 0.75 0.75 RG 0.5 w ' . $shapePath . ' S';
            }

            $noPhotoLabel = (string) ($card['no_photo_label'] ?? '');

            if ($noPhotoLabel !== '') {
                // NO EMPLOYEE RECORD TO EMBED A PHOTOGRAPH FROM (a visitor, client,
                // guest, ...) — the box that would hold a photo prints the card
                // type's own label instead, bold and large enough to read at arm's
                // length, fit-checked (not just estimated) and centred in the box.
                $visSize = 11;
                $visLabel = strtoupper($noPhotoLabel);

                while ($visSize > 6 && self::textWidth($visLabel, $visSize, true) > $pw - 6) {
                    $visSize--;
                }

                $visSize = self::sizeFor($visLabel, $pw - 6, $visSize, true);
                $visX = $px + intdiv(max(0, $pw - (int) round(self::textWidth($visLabel, $visSize, true))), 2);
                $out[] = '0.35 0.35 0.35 rg BT /F2 ' . $visSize . ' Tf ' . $visX . ' ' . ($py + intdiv($ph, 2) - 4) . ' Td ' . $t($visLabel) . ' Tj ET';
            } elseif ($card['photo'] !== null) {
                // CLIPPED TO THE SAME SHAPE (11 Sep) — "W n" sets the clip path
                // without painting it, so the embedded JPEG only shows through
                // the circle/oval/rounded box instead of always filling the
                // rectangle it is actually stored as.
                $out[] = 'q ' . $shapePath . ' W n ' . $pw . ' 0 0 ' . $ph . ' ' . $px . ' ' . $py . ' cm /Im1 Do Q';
            } else {
                $out[] = '0.5 0.5 0.5 rg BT /F1 6 Tf ' . ($px + 8) . ' ' . ($py + intdiv($ph, 2)) . ' Td (photo on file) Tj ET';
            }
        } elseif (!$portrait) {
            $x = 12;
        }

        // Identity block and field values now fit-checked against the actual space
        // to their right (11 Sep — nothing here ever checked before; a long name or
        // value just printed past the card edge, or over the photo in portrait).
        $identityMaxW = max(10, $wN - $x - 10);
        $out[] = $rgb($c['text']) . ' rg BT /F2 ' . self::sizeFor($card['name'], $identityMaxW, 12, true, 7) . ' Tf ' . $x . ' ' . $y . ' Td ' . $t($card['name']) . ' Tj ET';
        $y -= 12;
        $out[] = $rgb($c['label']) . ' rg BT /F1 ' . self::sizeFor($card['designation'], $identityMaxW, 8) . ' Tf ' . $x . ' ' . $y . ' Td ' . $t($card['designation']) . ' Tj ET';
        $y -= $portrait ? 14 : 13;

        $labelW = $portrait ? 70 : 62;
        $lineGap = 11;
        $labelMaxW = max(10, $labelW - 4);
        $valueMaxW = max(10, $wN - ($x + $labelW) - 8);

        foreach ($card['lines'] as $line) {
            if ($y < 22) {
                break;
            }

            if ($line['kind'] === 'line') {
                $y -= 8;
                $out[] = '0.6 0.6 0.6 RG 0.5 w ' . $x . ' ' . $y . ' m ' . ($x + ($portrait ? $wN - 24 : 110)) . ' ' . $y . ' l S';
                $out[] = $rgb($c['label']) . ' rg BT /F1 ' . self::sizeFor($line['label'], $wN - $x - 10, 5.5, false, 4) . ' Tf ' . $x . ' ' . ($y - 7) . ' Td ' . $t($line['label']) . ' Tj ET';
                $y -= 16;

                continue;
            }

            if ($line['kind'] === 'barcode') {
                $modules = $line['value'] === '' ? '' : Barcode::code128($line['value']);

                // BARS ARE SOLID BLACK ALWAYS (11 Sep) -- same reasoning as the
                // HTML/JS renderers: a themed-colour barcode on a low-contrast
                // palette is a barcode a scanner may not read.
                if ($modules !== '') {
                    $barAvailW = max(10, $wN - $x - 10);
                    $barH = 16.0;
                    $unit = min(1.0, $barAvailW / strlen($modules));
                    $bx = (float) $x;
                    $by = $y - $barH + 4;
                    $i = 0;
                    $n = strlen($modules);

                    while ($i < $n) {
                        $bit = $modules[$i];
                        $j = $i;

                        while ($j < $n && $modules[$j] === $bit) {
                            $j++;
                        }

                        $runW = ($j - $i) * $unit;

                        if ($bit === '1') {
                            $out[] = '0 0 0 rg ' . sprintf('%.2f %.2f %.2f %.2f re f', $bx, $by, $runW, $barH);
                        }

                        $bx += $runW;
                        $i = $j;
                    }

                    $y -= $barH + 3;
                    $out[] = $rgb($c['label']) . ' rg BT /F1 ' . self::sizeFor($line['value'], $barAvailW, 5.5, false, 4) . ' Tf ' . $x . ' ' . $y . ' Td ' . $t($line['value']) . ' Tj ET';
                    $y -= 10;
                }

                continue;
            }

            if ($line['value'] === '') {
                continue;
            }

            // LABEL AND VALUE IN FULL (owner, 23 Oct): the label shrinks to fit its column, the value
            // wraps onto the lines it needs.
            [$vSize, $vLines] = self::fitLines($line['value'], $valueMaxW, 8);
            $out[] = $rgb($c['label']) . ' rg BT /F1 ' . self::sizeFor(strtoupper($line['label']), $labelMaxW, 6.5, false, 4) . ' Tf ' . $x . ' ' . $y . ' Td ' . $t(strtoupper($line['label'])) . ' Tj ET';
            foreach ($vLines as $k => $vLine) {
                $out[] = $rgb($c['text']) . ' rg BT /F1 ' . $vSize . ' Tf ' . ($x + $labelW) . ' ' . ($y - $k * ($vSize + 1.5)) . ' Td ' . $t($vLine) . ' Tj ET';
            }
            $y -= $lineGap + (count($vLines) - 1) * ($vSize + 1.5);
        }

        if ($card['footer'] !== '') {
            $out[] = $rgb($c['label']) . ' rg BT /F1 ' . self::sizeFor($card['footer'], $wN - 20 - $leftPad, 5.5, false, 4) . ' Tf ' . (10 + $leftPad) . ' 9 Td ' . $t($card['footer']) . ' Tj ET';
        }

        $frontImages = [];

        if ($card['photo'] !== null && $card['show_photo']) {
            $frontImages['Im1'] = $card['photo'];
        }

        if ($card['logo'] !== null) {
            $frontImages['Im2'] = $card['logo'];
        }

        $faces = [['stream' => implode("\n", $out), 'images' => $frontImages]];
        $backText = trim((string) ($card['back_text'] ?? ''));
        $backLines = (array) ($card['back_lines'] ?? []);

        if ($backText !== '' || $backLines !== []) {
            $faces[] = ['stream' => self::renderBackFace($backText, $wN, $hN, $c, $rgb, $t, $backLines), 'images' => []];
        }

        // N PAGES, BUILT GENERICALLY (11 Sep, back side added) — object numbers
        // are just "wherever this object lands in $objects", figured out as
        // each page's pieces are appended, rather than hardcoded: the single-
        // page writer this used to be could get away with fixed numbers, a
        // 1-or-2-page one can't.
        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';                                                         // 1
        $objects[] = null;                                                                                       // 2 Pages, filled below
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';        // 3 F1
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';   // 4 F2

        $pageNums = [];

        foreach ($faces as $face) {
            $pageObjNum = count($objects) + 1;
            $objects[] = null; // filled once we know this page's Contents/image object numbers

            $contentObjNum = count($objects) + 1;
            $objects[] = '<< /Length ' . strlen($face['stream']) . " >>\nstream\n" . $face['stream'] . "\nendstream";

            $xobjRefs = [];

            foreach ($face['images'] as $name => $img) {
                $imgObjNum = count($objects) + 1;
                $objects[] = '<< /Type /XObject /Subtype /Image /Width ' . (int) $img['size'][0] . ' /Height ' . (int) $img['size'][1]
                    . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($img['bytes']) . " >>\nstream\n"
                    . $img['bytes'] . "\nendstream";
                $xobjRefs[] = '/' . $name . ' ' . $imgObjNum . ' 0 R';
            }

            $resources = '/Font << /F1 3 0 R /F2 4 0 R >>' . ($xobjRefs === [] ? '' : ' /XObject << ' . implode(' ', $xobjRefs) . ' >>');
            $objects[$pageObjNum - 1] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $W . ' ' . $H . '] /Contents ' . $contentObjNum . ' 0 R /Resources << ' . $resources . ' >> >>';
            $pageNums[] = $pageObjNum;
        }

        $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', array_map(static fn (int $n): string => $n . ' 0 R', $pageNums)) . '] /Count ' . count($pageNums) . ' >>';

        return self::assemble($objects);
    }

    /**
     * The back of the card — a header band in the same accent colour and a
     * block of wrapped text, nothing else on it needs to earn its place. Kept
     * separate from renderCard()'s front-face drawing because the two faces
     * have almost nothing in common beyond the palette.
     *
     * @param array<string, string> $c
     */
    private static function renderBackFace(string $text, int $wN, int $hN, array $c, callable $rgb, callable $t, array $lines = []): string
    {
        $out = [];
        $out[] = $rgb($c['background']) . ' rg 0 0 ' . $wN . ' ' . $hN . ' re f';
        $out[] = $rgb($c['accent']) . ' rg 0 0 ' . $wN . ' 6 re f';
        $out[] = $rgb($c['header']) . ' rg 0 ' . ($hN - 6) . ' ' . $wN . ' 6 re f';

        $y = $hN - 22;

        // FIELDS CHOSEN FOR THE BACK (owner, 22 Oct 2026): label/value rows, a signature line, or the
        // barcode, drawn the way the front draws them; then the back text below.
        $labelW = 70;
        foreach ($lines as $line) {
            if ($y < 22) {
                break;
            }
            if ($line['kind'] === 'line') {
                $y -= 8;
                $out[] = '0.6 0.6 0.6 RG 0.5 w 12 ' . $y . ' m ' . min($wN - 12, 140) . ' ' . $y . ' l S';
                $out[] = $rgb($c['label']) . ' rg BT /F1 ' . self::sizeFor($line['label'], $wN - 24, 5.5, false, 4) . ' Tf 12 ' . ($y - 7) . ' Td ' . $t($line['label']) . ' Tj ET';
                $y -= 16;
                continue;
            }
            if ($line['kind'] === 'barcode') {
                $modules = $line['value'] === '' ? '' : Barcode::code128($line['value']);
                if ($modules !== '') {
                    $unit = min(1.0, max(10, $wN - 24) / strlen($modules));
                    $bx = 12.0;
                    $by = $y - 12;
                    $n = strlen($modules);
                    $i = 0;
                    while ($i < $n) {
                        $j = $i;
                        while ($j < $n && $modules[$j] === $modules[$i]) {
                            $j++;
                        }
                        if ($modules[$i] === '1') {
                            $out[] = '0 0 0 rg ' . sprintf('%.2f %.2f %.2f %.2f re f', $bx, $by, ($j - $i) * $unit, 16.0);
                        }
                        $bx += ($j - $i) * $unit;
                        $i = $j;
                    }
                    $y -= 19;
                    $out[] = $rgb($c['label']) . ' rg BT /F1 ' . self::sizeFor($line['value'], $wN - 24, 5.5, false, 4) . ' Tf 12 ' . $y . ' Td ' . $t($line['value']) . ' Tj ET';
                    $y -= 10;
                }
                continue;
            }
            if ($line['value'] === '') {
                continue;
            }
            [$vSize, $vLines] = self::fitLines($line['value'], max(10, $wN - 24 - $labelW), 8);
            $out[] = $rgb($c['label']) . ' rg BT /F1 ' . self::sizeFor(strtoupper($line['label']), $labelW - 4, 6.5, false, 4) . ' Tf 12 ' . $y . ' Td ' . $t(strtoupper($line['label'])) . ' Tj ET';
            foreach ($vLines as $k => $vLine) {
                $out[] = $rgb($c['text']) . ' rg BT /F1 ' . $vSize . ' Tf ' . (12 + $labelW) . ' ' . ($y - $k * ($vSize + 1.5)) . ' Td ' . $t($vLine) . ' Tj ET';
            }
            $y -= 11 + (count($vLines) - 1) * ($vSize + 1.5);
        }
        if ($lines !== []) {
            $y -= 4;
        }

        foreach (self::wrapLines($text, $wN - 24, 7.5, false, 12) as $line) {
            if ($y < 14) {
                break;
            }

            $out[] = $rgb($c['text']) . ' rg BT /F1 7.5 Tf 12 ' . $y . ' Td ' . $t($line) . ' Tj ET';
            $y -= 11;
        }

        return implode("\n", $out);
    }

    /** Objects → a PDF file with a correct xref. */
    private static function assemble(array $objects): string
    {
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";

        foreach ($offsets as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }

        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";

        return $pdf;
    }

    /** A PDF literal string in WinAnsi: escaped, and anything outside Latin-1 transliterated. */
    private static function pdfString(string $s): string
    {
        $s = Dates::inText($s);
        $latin = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);

        if ($latin === false) {
            $latin = preg_replace('/[^\x20-\x7E]/', '?', $s) ?? '';
        }

        return '(' . strtr($latin, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => ' ', "\n" => ' ']) . ')';
    }

    /**
     * The photograph as JPEG bytes and its size, for embedding in the hand-written
     * PDF below as a `/DCTDecode` stream — the one image format this file can embed
     * without a decoder, because a JPEG file already IS that stream, byte for byte.
     *
     * A source that already is a JPEG is returned untouched. Anything else GD can
     * read (PNG, GIF, WEBP, BMP) is decoded and RE-ENCODED to JPEG here, once, so
     * the same photo a colleague sees on their own screen (any format) is the one
     * that prints on the card — not "photo on file" for everyone whose upload
     * happened not to be a JPEG. Transparency is flattened onto white first: a
     * `/DCTDecode` stream has no alpha channel, and an unflattened transparent PNG
     * would print with a black background where it should be blank.
     *
     * Null means "cannot embed this one" — no `is_file`, no `getimagesize`, GD not
     * compiled in, or a format GD itself does not decode — and the card falls back
     * to "photo on file" rather than failing the whole PDF. That fallback is a
     * deliberate degrade, not an error swallowed silently: the caller reports it.
     */
    public static function jpegFrom(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $info = @getimagesize($path);

        if ($info === false) {
            return null;
        }

        if (($info[2] ?? 0) === IMAGETYPE_JPEG) {
            return ['bytes' => (string) file_get_contents($path), 'size' => [(int) $info[0], (int) $info[1]]];
        }

        if (!extension_loaded('gd')) {
            return null;
        }

        $source = match ($info[2] ?? 0) {
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            IMAGETYPE_BMP => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($path) : false,
            default => false,
        };

        if (!$source instanceof \GdImage) {
            return null;
        }

        $w = imagesx($source);
        $h = imagesy($source);
        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
        imagealphablending($flat, true);
        imagecopy($flat, $source, 0, 0, 0, 0, $w, $h);
        imagedestroy($source);

        ob_start();
        imagejpeg($flat, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($flat);

        return $bytes === '' ? null : ['bytes' => $bytes, 'size' => [$w, $h]];
    }
}
