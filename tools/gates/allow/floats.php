<?php

/**
 * Law 7 allow-list: lines where a float is NOT a money value.
 *
 * `no-float-money.php` promised this file in its own docblock and never read it,
 * so an entry here was inert for the life of the project. That mattered the day
 * the gate was pointed at `surface/` and `public/` — fourteen thousand lines it
 * had been reporting PASS over without opening — because display code legitimately
 * uses floats for things that are not amounts: the width of a bar, the argument to
 * `number_format()` for a COUNT, the type of a generic escaping helper. With no
 * working escape hatch the only ways to a green gate were to contort correct code
 * or to leave the gate red until somebody switched it off.
 *
 * KEYED BY WHAT THE LINE SAYS, NOT BY WHERE IT IS. The first version keyed on
 * file:line, which is the obvious choice and wrong for a fourteen-thousand-line
 * front controller: any edit ABOVE an allowed line renumbers it, the entry stops
 * matching, and the gate reports lines it had been told to ignore. That happened
 * twice in one afternoon, and the fix each time was to renumber entries without
 * re-reading them — which is precisely the ritual that turns an allow-list into a
 * rubber stamp. The text keeps the property the line number was chosen for: change
 * what the line DOES and the entry stops matching, so somebody looks again.
 * Whitespace is normalised, so reindenting is not a re-review.
 *
 * An allowed line is SUPPRESSED, NOT DROPPED: the runner counts every one and can
 * print it, so this cannot quietly become the rule. If you are about to add a line
 * that computes, compares, sums or stores rupees — stop. That is the defect Law 7
 * exists for: SmartPRS 2 decides whether to recover a loan instalment with a float
 * comparison, so an instalment silently stops being deducted when the residual
 * balance lands a fraction of a paisa from the comparand, and nobody finds out
 * until the loan does not close.
 *
 * @return array{lines: array<string, string>, notes: array<string, string>}
 */

declare(strict_types=1);

return [
    'lines' => [
        // The HTML escaping helper. It accepts float because it accepts anything
        // printable; it never does arithmetic and never decides anything.
        'public/app.php|function e(string|int|float|bool|null $s): string'
            => 'e() is the escaping helper — a union type on a printable value, no arithmetic',

        // number_format() on a COUNT measure. Reports return an int for a count and
        // an exact decimal string for money, and the money branch of each of these
        // ternaries goes through Money::format(). This is the other branch.
        "public/app.php|: number_format((float) \$kpi['value']) ?></h3>"
            => 'count measure, not money — the money branch of this ternary uses Money::format()',
        "public/app.php|? e(\$rupees(\$row['value'])) : number_format((float) \$row['value']) ?>"
            => 'count measure, not money — the money branch of this ternary uses Money::format()',
        "public/app.php|: number_format((float) \$row['value'],"
            => 'count measure, not money — the money branch of this ternary uses Money::format()',
        "public/app.php|: number_format((float) \$stat['value']) ?></h3>"
            => 'count measure, not money — the money branch of this ternary uses Money::format()',

        // A BAR'S WIDTH IS A PICTURE. The proportion of a panel a bar fills is
        // allowed to be approximate to a fraction of a pixel; the figure printed
        // beside it is not, and that figure comes from the exact value.
        "public/app.php|\$top = max(array_map(static fn (array \$r): float => (float) \$r['value'], \$panel['rows'])); ?>"
            => 'largest row value, used only to scale bar widths in CSS — the printed figure is exact',
        "public/app.php|\$top > 0 ? round((float) \$row['value'] / \$top * 100) : 0 ?>%;background:<?="
            => 'bar width as a percentage of the largest row — a picture, not an amount',

        // A DECIMAL COLUMN THAT IS NOT AN AMOUNT (23 Sep). capDecimals() trims a
        // `decimal(10,4)` to 2dp FOR DISPLAY — a geofence radius, a lat/lng, a
        // percentage rate. Its own docblock rules money out in terms: money is
        // already exact through Money::format() and never reaches a raw column
        // string, so it never reaches this function.
        "public/app.php|\$rounded = rtrim(rtrim(number_format((float) \$value, 2, '.', ''), '0'), '.');"
            => 'capDecimals() — display rounding for a decimal(10,4) measure (radius, lat/lng, rate); money never reaches it',

        // THE DONUT IS THE NON-MONEY BRANCH (23 Sep). The panel is `elseif
        // ($panel['money'])` → bars, printed with $rupees() and exact; `else` →
        // this donut, which is therefore only ever drawn for a COUNT measure.
        // The slice geometry is a picture; the legend figure is an integer count.
        "public/app.php|\$sliceTotal = array_sum(array_map(static fn (array \$r): float => (float) \$r['value'], \$shown));"
            => "count measure — the donut is the `else` of \$panel['money']; the bar view above is the money one",
        "public/app.php|\$share = \$sliceTotal > 0 ? (float) \$row['value'] / \$sliceTotal * 100 : 0;"
            => 'a donut slice as a percentage of the rows shown — a picture, not an amount',
        "public/app.php|<span style=\"color:var(--text3)\"><?= number_format((float) \$row['value']) ?>"
            => "count measure — the donut legend; the money branch prints \$rupees(\$row['value'])",

        // LEAVE IS COUNTED IN DAYS (23 Sep). available / balance / pending on the
        // Leave screen are day counts from the leave ledger — half-days are why
        // they are decimal at all. The cast is a `> 0` test that picks a colour,
        // enables an option, or decides whether to print "pending approval".
        // Flagged only because a (float) cast is reported unconditionally,
        // whatever the variable is called.
        "public/app.php|<div style=\"font:700 30px var(--mono);color:<?= (float) \$lvT['available'] > 0 ? 'var(--green,#16a34a)' : 'var(--red,#b42318)' ?>;margin:6px 0 2px\"><?= e(\$lvT['available']) ?></div>"
            => 'leave days, not money — a > 0 test choosing the figure colour',
        "public/app.php|<div class=\"note\">available &middot; balance <?= e(\$lvT['balance']) ?><?= (float) \$lvT['pending'] > 0 ? ' &middot; ' . e(\$lvT['pending']) . ' pending approval' : '' ?></div>"
            => 'leave days, not money — a > 0 test deciding whether to print the pending note',
        "public/app.php|<option value=\"<?= e((string) \$lvCode) ?>\" data-capped=\"<?= \$lvT['capped'] ? '1' : '0' ?>\" data-available=\"<?= e(\$lvT['available']) ?>\"<?= \$lvT['capped'] && (float) \$lvT['available'] <= 0 ? ' disabled' : '' ?>><?= e(\$lvT['name']) ?> — <?= \$lvT['capped'] ? e(\$lvT['available']) . ' available' : 'unpaid' ?></option>"
            => 'leave days, not money — a <= 0 test disabling a leave type with nothing left',

        // A KPI PERCENTAGE (23 Sep). percent_achieved against a target, used to
        // pick a badge colour on the KRA/KPI achievement table. Not an amount.
        "public/app.php|<?php \$kaPct = (float) (\$en['percent_achieved'] ?? 0); \$kaPctBadge = \$kaPct >= 100 ? 'badge-green' : (\$kaPct >= 75 ? 'badge-amber' : 'badge-red'); ?>"
            => 'percent achieved against a KPI target — picks a badge colour, not an amount',

        // ------------------------------------------------------------------
        // A PDF WRITER'S GEOMETRY IS NOT MONEY (23 Sep).
        //
        // surface/IdCardPdf.php draws a CR80 card by hand — 242.65 × 153.01
        // POINTS — because no PDF library is installed and none is added. Its
        // floats are glyph widths, text sizes, x/y/w/h, ellipse and rounded-
        // rectangle control points and a barcode module width. There is not a
        // rupee in the file: it reads a name, a code and a photograph and emits
        // a page. Sub-point precision is the POINT of these values, so the
        // fix the gate suggests — integer minor units — would be wrong here,
        // not merely unnecessary.
        //
        // This is the name-based heuristic missing, which the gate's own
        // docblock predicts: GATE_MONEY_ARG is applied to round() and
        // number_format() but NOT to a cast or a type declaration, so every
        // `float` in a 500-line typesetter is reported. Suppressed by line
        // text and therefore still counted and still printed on every run.
        // ponytail: 13 entries rather than one file-level exemption, because
        // the gate has no file-level form and adding one is a change to a
        // gate, which needs the lead architect. Revisit if a second hand-
        // written renderer lands.
        'surface/IdCardPdf.php|private static function charWeight(string $ch): float'
            => 'PDF typesetting — a glyph advance width in points, not an amount',
        'surface/IdCardPdf.php|public static function textWidth(string $s, float $size, bool $bold = false): float'
            => 'PDF typesetting — text width and point size, not an amount',
        'surface/IdCardPdf.php|public static function fitOneLine(string $s, float $maxWidth, float $size, bool $bold = false): string'
            => 'PDF typesetting — a line box width in points, not an amount',
        'surface/IdCardPdf.php|public static function wrapLines(string $s, float $maxWidth, float $size, bool $bold, int $maxLines): array'
            => 'PDF typesetting — a line box width in points, not an amount',
        'surface/IdCardPdf.php|private static function shapePath(float $x, float $y, float $w, float $h, string $shape): string'
            => 'PDF geometry — card coordinates in points, not an amount',
        'surface/IdCardPdf.php|private static function roundedRectPath(float $x, float $y, float $w, float $h, float $r): string'
            => 'PDF geometry — rectangle and corner radius in points, not an amount',
        'surface/IdCardPdf.php|private static function ellipsePath(float $x, float $y, float $w, float $h): string'
            => 'PDF geometry — ellipse bounds in points, not an amount',
        "surface/IdCardPdf.php|\$p = static fn (float ...\$n): string => implode(' ', array_map(static fn (float \$v): string => sprintf('%.2f', \$v), \$n));"
            => 'PDF geometry — formats path operands to 2dp for the content stream, not an amount',
        'surface/IdCardPdf.php|$shapePath = self::shapePath((float) $px, (float) $py, (float) $pw, (float) $ph, $photoShape);'
            => 'PDF geometry — the photo frame in points, not an amount',
        "surface/IdCardPdf.php|\$out[] = \$rgb(\$c['header']) . ' rg ' . self::ellipsePath((float) (\$ringCx - \$ringR), (float) (\$ringCy - \$ringR), (float) (\$ringR * 2), (float) (\$ringR * 2)) . ' f';"
            => 'PDF geometry — the photo ring in points, not an amount',
        'surface/IdCardPdf.php|$innerPath = self::shapePath((float) $px + 3, (float) $py + 3, (float) $pw - 6, (float) $ph - 6, $photoShape);'
            => 'PDF geometry — the photo frame inset by 3 points, not an amount',
        'surface/IdCardPdf.php|$ringPath = self::shapePath((float) $px + $inset, (float) $py + $inset, (float) $pw - 2 * $inset, (float) $ph - 2 * $inset, $photoShape);'
            => 'PDF geometry — the photo ring inset in points, not an amount',
        'surface/IdCardPdf.php|$bx = (float) $x;'
            => 'PDF geometry — the barcode origin in points; the module width beside it is sub-point on purpose',

        // AN EXCEL DATE IS A DAY COUNT (23 Sep). PhpSpreadsheet stores a date as
        // a serial number — days since 1900, the fraction being the time of day —
        // and excelToDateTimeObject() takes exactly that. The cast is guarded by
        // Date::isDateTime($cell), so it is reached only for a cell the workbook
        // itself says is a date. Nothing here is a cell's VALUE as money.
        "surface/Sheet.php|? \\PhpOffice\\PhpSpreadsheet\\Shared\\Date::excelToDateTimeObject((float) \$value)->format('Y-m-d')"
            => 'an Excel serial date (days since 1900, fraction = time of day), guarded by Date::isDateTime — not an amount',

        // surface/TablePdf.php (8 Oct) is the second hand-written PDF writer the
        // IdCardPdf note above anticipated: a report table laid out in POINTS on an
        // A4 landscape page. Column widths, cell x/y and font size — no amount is
        // computed here; the figures it prints arrive already formatted as strings.
        'surface/TablePdf.php|$width = array_map(static fn (int $l): float => $usable * $l / $sum, $len);'
            => 'PDF geometry — column widths in points, proportional to text length, not an amount',
        'surface/TablePdf.php|private static function cell(float $x, float $y, float $w, string $t, bool $right, bool $bold): string'
            => 'PDF geometry — a table cell box in points, not an amount',
        'surface/TablePdf.php|private static function text(float $x, float $y, float $size, string $t, array $rgb, bool $bold = false): string'
            => 'PDF typesetting — text position and point size, not an amount',

        // The appraisal sample sheet's rating scale (8 Oct): the lowest and highest
        // rating on the slab table become an Excel data-validation range. A rating
        // is a score such as 3.5 — not money, never summed into pay here.
        "surface/actions/appraisal-sample.php|\$min = min(array_map(static fn (array \$s): float => (float) \$s['rating'], \$asSlabs));"
            => 'appraisal rating scale bound for an Excel validation rule — a score, not an amount',
        "surface/actions/appraisal-sample.php|\$max = max(array_map(static fn (array \$s): float => (float) (\$s['rating_to'] ?? \$s['rating']), \$asSlabs));"
            => 'appraisal rating scale bound for an Excel validation rule — a score, not an amount',

        // Geo-fence punch check (12 Oct): GPS coordinates in degrees and distances in km.
        // Geometry, never money — nothing here is summed into pay.
        'surface/GeoFence.php|$tol = preg_match(\'/(\\d+(?:\\.\\d+)?)\\s*km/i\', (string) ($rule[\'outside\'] ?? \'\'), $m) === 1 ? (float) $m[1] : 0.0;'
            => 'geo-fence tolerance in km read from the Outside Rule — a distance, not an amount',
        'surface/GeoFence.php|return [\'lat\' => (float) $rule[\'lat\'], \'lng\' => (float) $rule[\'lng\'], \'radius_km\' => max(0.0, (float) ($rule[\'radius_km\'] ?? 0)),'
            => 'geo-fence centre (degrees) and radius (km) from the Geo-Fence Rule — coordinates, not an amount',
        'surface/GeoFence.php|return [\'lat\' => (float) $employment[\'home_lat\'], \'lng\' => (float) $employment[\'home_lng\'],'
            => 'geo-fence centre from the Home Latitude / Longitude — coordinates in degrees, not an amount',
        'surface/GeoFence.php|\'radius_km\' => max(0.0, (float) ($employment[\'geo_radius_km\'] ?? 0)) ?: 0.5, \'tolerance_km\' => 0.0, \'source\' => \'home\', \'label\' => \'your home location\'];'
            => 'geo-fence radius in km from Allowed Radius — a distance, not an amount',
        'surface/GeoFence.php|$d = self::distanceKm((float) $lat, (float) $lng, $fence[\'lat\'], $fence[\'lng\']);'
            => 'punch GPS position in degrees, measured against the fence — coordinates, not an amount',
        'surface/GeoFence.php|public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float'
            => 'great-circle (haversine) distance between two GPS points — degrees in, km out, not an amount',
        'surface/GeoFence.php|private static function km(float $km): string'
            => 'formats a distance in km for the punch message — not an amount',
    ],
    'notes' => [
        // WAS: "surface/ has no entries and should keep none: every float there
        // was a real Law 7 violation on a money path and all of them were fixed
        // rather than allowed." True when written, and no longer: it predates
        // IdCardPdf.php (11 Sep), a hand-written PDF writer, and Sheet.php's
        // xlsx reader. Both are rendering and parsing code with no money in
        // them at all. The sentence is kept here rather than deleted because
        // the standard it sets is still the right one.
        'surface' => 'surface/ carries entries ONLY for code that handles no money at all — a PDF '
            . "writer's point geometry and an Excel serial date. Every float on an actual money "
            . 'path in surface/ was a real Law 7 violation and every one was FIXED rather than '
            . 'allowed. That remains the standard: if the line computes, compares, sums or stores '
            . 'rupees, it does not belong in this file.',
    ],
];
