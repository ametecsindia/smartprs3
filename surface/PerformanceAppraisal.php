<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Rational;

/**
 * PERFORMANCE APPRAISAL SLABS — Organization Configuration → Performance
 * Appraisal. Per financial year (or half-year / quarter / month — whichever
 * granularity the owner runs appraisals on), one or more "boxes": a scope
 * (company/branch/department/role/designation/team/employee — blank means
 * everyone) plus its own unlimited table of {rating -> % salary increase}
 * slabs, and its own rating TYPE (numeric, for a 0–10 style scale, or custom
 * text labels, for an org that grades S/M/A/R/T or anything else).
 *
 * MULTIPLE BOXES CAN COVER ONE FY — the owner's ask: "the next customisation
 * within the same FY, different company or branch… should follow the same
 * formatting." An employee can fall inside more than one box's scope (e.g. a
 * blank/"everyone" box and a specific-branch box); boxFor() picks the MOST
 * SPECIFIC match, the same precedence Salary Structure's ComponentRules
 * already uses for scoped overrides ("a branch's own rule wins for that
 * component only; everything nobody has overridden falls through").
 *
 * THIS IS SEPARATE FROM Performance::prg(): that stays the static, global,
 * informational band shown on the Performance screen. This is the tenant's
 * own, per-FY-and-scope, unlimited table an Apply action actually acts on.
 *
 * SLAB MODES (21 Sep, owner's ask): a numeric box's slabs are either one
 * EXACT rating per row, or a RANGE (from – to, inclusive, decimals allowed —
 * "4.5 to 5 → 12%"). Every slab also carries a free-text DESCRIPTION shown
 * beside it. A custom-label box is always exact: "S to A" is not a range.
 *
 * WIRED TO THE FINANCIAL YEAR: every key has a real date range (range(),
 * from Organization Setup → Financial Year's start month), a Performance
 * review period is read against the key whose range holds that period's
 * last day (keyCoversPeriod()), and resolve() is what the Performance
 * screen's recommended-increment column and Apply both read — one answer.
 *
 * ARCHIVE: keys whose financial year has ended leave the main screen and are
 * listed, together with every earlier saved version of any box (the
 * customisation is versioned — nothing is overwritten), by archive().
 *
 * WHICH EMPLOYEES GOT RAISED is not tracked here — it's answered by scanning
 * the `increments` register the Apply action writes to (surface/actions/
 * performance-appraisal.php), which already exists to answer exactly that.
 */
final class PerformanceAppraisal
{
    /** The customisation key the tenant's own FY boxes are saved under. */
    public const KEY = 'performance-appraisal';

    /** The org dimensions a box can be scoped to — same set and order as ORG_FILTERS. */
    public const SCOPE_DIMENSIONS = ['company', 'branch', 'department', 'role', 'designation', 'team', 'employee'];

    /** Granularities the "+ Add New" form offers, in the order shown. */
    public const GRANULARITIES = ['yearly' => 'Yearly', 'half_year' => 'Half-Yearly', 'quarter' => 'Quarterly', 'month' => 'Monthly'];

    private const MONTHS = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'];

    public function __construct(
        private readonly ?Customisation $customisation = null,
    ) {
    }

    /** @return array<string, array{boxes: list<array{id: string, scope: array<string, string>, type: string, slabs: list<array{rating: string, increment_pct: string}>}>}> */
    public function years(): array
    {
        $saved = $this->customisation?->live()[self::KEY]['years'] ?? null;

        return is_array($saved) ? $saved : [];
    }

    /** Every FY that has at least one box, newest/most-granular first. */
    public function fys(): array
    {
        $keys = array_keys($this->years());
        usort($keys, static fn (string $a, string $b): int => self::sortTuple($b) <=> self::sortTuple($a));

        return $keys;
    }

    /** @return list<array{id: string, scope: array<string, string>, type: string, slabs: list<array{rating: string, increment_pct: string}>}> */
    public function boxesFor(string $fy): array
    {
        return (array) ($this->years()[$fy]['boxes'] ?? []);
    }

    /**
     * The most specific box in $fy whose scope matches this employee, or
     * null when no box covers them at all — Apply must never guess a rate
     * for an employee no customisation was written for.
     *
     * @param array<string, string> $employeeOrgValues company/branch/… => name, from orgEnrich() + orgValueOf($e,'role'/'employee')
     */
    public function boxFor(string $fy, array $employeeOrgValues): ?array
    {
        $best = null;
        $bestSpecificity = -1;

        foreach ($this->boxesFor($fy) as $box) {
            $scope = (array) ($box['scope'] ?? []);

            if (!self::scopeMatches($scope, $employeeOrgValues)) {
                continue;
            }

            $specificity = self::specificity($scope);

            if ($specificity > $bestSpecificity) {
                $best = $box;
                $bestSpecificity = $specificity;
            }
        }

        return $best;
    }

    /**
     * The % increase for a rating within ONE box, honouring that box's own
     * rating type: numeric boxes match the employee's actual (int) final
     * rating; custom-label boxes match the SCALE LABEL for that rating
     * (config/performance.json's `scale`), case-insensitively — so an org
     * that relabels its scale to S/M/A/R/T can slab against those letters
     * without the underlying review ever storing anything but a number.
     *
     * @param array{type: string, slabs: list<array{rating: string, increment_pct: string}>} $box
     */
    public static function pctFor(array $box, int $rating, string $ratingLabel): ?string
    {
        $slab = self::slabFor($box, $rating, $ratingLabel);

        return $slab === null ? null : (string) $slab['increment_pct'];
    }

    /**
     * The slab (rating, rating_to, description, increment_pct) a rating falls
     * in, honouring the box's type and slab mode. Numeric comparison is exact
     * (Rational), never a float.
     *
     * @param array<string, mixed> $box
     * @return array<string, string>|null
     */
    public static function slabFor(array $box, int $rating, string $ratingLabel): ?array
    {
        return self::slabForValue($box, (string) $rating, $ratingLabel);
    }

    /**
     * THE SAME, FOR A RATING AS IT WAS GIVEN — a label ("S"), a number ("4")
     * or a point inside a slab ("4.6"). `$legacyLabel` is the shipped scale's
     * name for a numeric rating recorded before the review used this box's own
     * scale, so an older review still lands in a label box.
     *
     * @param array<string, mixed> $box
     * @return array<string, string>|null
     */
    public static function slabForValue(array $box, string $value, string $legacyLabel = ''): ?array
    {
        $value = trim($value);
        $format = self::format($box);

        foreach ((array) ($box['slabs'] ?? []) as $slab) {
            $from = trim((string) ($slab['rating'] ?? ''));

            if ($format === 'labels') {
                if ($from !== '' && (strcasecmp($from, $value) === 0 || ($legacyLabel !== '' && strcasecmp($from, trim($legacyLabel)) === 0))) {
                    return $slab;
                }

                continue;
            }

            $to = $format === 'slab' ? trim((string) ($slab['rating_to'] ?? '')) : $from;

            if (!self::isDecimal($value) || !self::isDecimal($from) || !self::isDecimal($to)) {
                continue;
            }

            $v = Rational::fromDecimalString($value);

            if ($v->compareTo(Rational::fromDecimalString($from)) >= 0 && $v->compareTo(Rational::fromDecimalString($to)) <= 0) {
                return $slab;
            }
        }

        return null;
    }

    /** How reviewers rate under this box: 'labels' (S, M, A…), 'numbers' (exact values) or 'slab' (any number inside the ranges). */
    public static function format(array $box): string
    {
        if ((string) ($box['type'] ?? 'numeric') === 'custom') {
            return 'labels';
        }

        return (string) ($box['slab_mode'] ?? 'exact') === 'range' ? 'slab' : 'numbers';
    }

    /** The three formats as the screen offers them, and what each is stored as. */
    public const FORMATS = [
        'labels' => ['type' => 'custom', 'slab_mode' => 'exact'],
        'numbers' => ['type' => 'numeric', 'slab_mode' => 'exact'],
        'slab' => ['type' => 'numeric', 'slab_mode' => 'range'],
    ];

    /**
     * A typed rating checked against the box's own scale: the canonical value
     * to store, or null when it is not on that scale (a label nobody set up,
     * a number no row has, a point outside every slab).
     */
    public static function acceptValue(array $box, string $typed): ?string
    {
        $slab = self::slabForValue($box, $typed);

        if ($slab === null) {
            return null;
        }

        return self::format($box) === 'slab' ? trim($typed) : (string) $slab['rating'];
    }

    /**
     * THE RATING SCALE A REVIEWER USES FOR THIS EMPLOYEE AND PERIOD — the
     * most specific box covering them in the finest key whose dates hold the
     * period's last day. Null when no criterion covers them: the review then
     * falls back to the shipped 1–5 scale in config/performance.json.
     *
     * @param array<string, string> $employeeOrgValues
     * @return array{fy: string, box: array<string, mixed>, format: string}|null
     */
    public function scaleFor(string $period, array $employeeOrgValues, int $fyStartMonth): ?array
    {
        foreach ($this->fys() as $fy) {
            if (!self::keyCoversPeriod($fy, $period, $fyStartMonth)) {
                continue;
            }

            $box = $this->boxFor($fy, $employeeOrgValues);

            if ($box !== null) {
                return ['fy' => $fy, 'box' => $box, 'format' => self::format($box)];
            }
        }

        return null;
    }

    /** One box by key and id, or null. @return array<string, mixed>|null */
    public function box(string $fy, string $boxId): ?array
    {
        foreach ($this->boxesFor($fy) as $b) {
            if ((string) ($b['id'] ?? '') === $boxId) {
                return $b;
            }
        }

        return null;
    }

    /** A box's scale in words: "Rating labels S, M, A, R, T" / "Numbers 1–5" / "Slab 1–5". */
    public static function describeScale(array $box): string
    {
        $slabs = (array) ($box['slabs'] ?? []);

        return match (self::format($box)) {
            'labels' => 'Rating labels ' . implode(', ', array_map(static fn (array $s): string => (string) $s['rating'], $slabs)),
            'numbers' => 'Numbers ' . implode(', ', array_map(static fn (array $s): string => (string) $s['rating'], $slabs)),
            default => 'Slab ranges ' . implode(', ', array_map(static fn (array $s): string => $s['rating'] . '–' . ($s['rating_to'] ?? ''), $slabs)),
        };
    }

    /**
     * EVERYTHING THE REST OF THE APP ASKS OF THIS SCREEN, IN ONE CALL: for a
     * Performance review period and one employee's org values + final rating,
     * which key covers it (the finest granularity whose range holds the
     * period's last day wins — a quarter's own table over the year's), which
     * box is most specific for them, and which slab the rating lands in.
     *
     * @param array<string, string> $employeeOrgValues
     * @return array{fy: string, pct: string, description: string, scope: array<string, string>}|null
     */
    public function resolve(string $period, array $employeeOrgValues, string $rating, string $ratingLabel, int $fyStartMonth,
        ?array $boxHint = null): ?array
    {
        // THE BOX THE REVIEW WAS RATED ON wins when the review recorded one:
        // a rating given as "S" is only meaningful on the scale it was given on.
        if ($boxHint !== null && ($hinted = $this->box((string) $boxHint['fy'], (string) $boxHint['id'])) !== null) {
            $slab = self::slabForValue($hinted, $rating, $ratingLabel);

            return $slab === null ? null : ['fy' => (string) $boxHint['fy'], 'pct' => (string) $slab['increment_pct'],
                'description' => (string) ($slab['description'] ?? ''), 'scope' => (array) ($hinted['scope'] ?? [])];
        }

        foreach ($this->fys() as $fy) { // already finest-first within a year
            if (!self::keyCoversPeriod($fy, $period, $fyStartMonth)) {
                continue;
            }

            $box = $this->boxFor($fy, $employeeOrgValues);
            $slab = $box === null ? null : self::slabForValue($box, $rating, $ratingLabel);

            if ($slab !== null) {
                return ['fy' => $fy, 'pct' => (string) $slab['increment_pct'], 'description' => (string) ($slab['description'] ?? ''),
                    'scope' => (array) ($box['scope'] ?? [])];
            }
        }

        return null;
    }

    /**
     * The real dates a key stands for. Yearly = the financial year starting
     * in `year` at the configured start month; H1/H2, Q1–Q4 are its halves and
     * quarters; a month key names that month of that financial year.
     *
     * @return array{from: string, to: string}|null
     */
    public static function range(string $fy, int $fyStartMonth): ?array
    {
        $p = self::parseKey($fy);
        $start = max(1, min(12, $fyStartMonth));

        [$offset, $months] = match ($p['granularity']) {
            'yearly' => [0, 12],
            'half_year' => [$p['sub'] === 'H2' ? 6 : 0, 6],
            'quarter' => [3 * ((int) substr($p['sub'], 1) - 1), 3],
            'month' => [((int) substr($p['sub'], 1) - $start + 12) % 12, 1],
            default => [null, null],
        };

        if ($offset === null) {
            return null;
        }

        $from = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $p['year'], $start)))->modify('+' . $offset . ' months');

        return ['from' => $from->format('Y-m-d'), 'to' => $from->modify('+' . $months . ' months')->modify('-1 day')->format('Y-m-d')];
    }

    /**
     * A Performance review period ("2026-H1", "2026-Q3", "2026-FY" — see
     * Performance::periods(), which counts CALENDAR halves/quarters) as dates.
     *
     * @return array{from: string, to: string}|null
     */
    public static function periodRange(string $period): ?array
    {
        if (preg_match('/^(\d{4})-(H[12]|Q[1-4]|FY)$/', $period, $m) !== 1) {
            return null;
        }

        [$index, $months] = match ($m[2][0]) {
            'H' => [(int) substr($m[2], 1) - 1, 6],
            'Q' => [(int) substr($m[2], 1) - 1, 3],
            default => [0, 12],
        };
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', (int) $m[1], $index * $months + 1));

        return ['from' => $from->format('Y-m-d'), 'to' => $from->modify('+' . $months . ' months')->modify('-1 day')->format('Y-m-d')];
    }

    /** Does this key's date range hold the review period's last day? */
    public static function keyCoversPeriod(string $fy, string $period, int $fyStartMonth): bool
    {
        $k = self::range($fy, $fyStartMonth);
        $p = self::periodRange($period);

        return $k !== null && $p !== null && $p['to'] >= $k['from'] && $p['to'] <= $k['to'];
    }

    /** The first year of the financial year $today falls in. */
    public static function currentFyYear(string $today, int $fyStartMonth): int
    {
        $y = (int) substr($today, 0, 4);

        return (int) substr($today, 5, 2) >= $fyStartMonth ? $y : $y - 1;
    }

    /** A key whose financial year has not ended yet stays on the main screen. */
    public static function isCurrent(string $fy, string $today, int $fyStartMonth): bool
    {
        return self::parseKey($fy)['year'] >= self::currentFyYear($today, $fyStartMonth);
    }

    /**
     * THE ARCHIVE: (a) every box of a key whose financial year has ended, and
     * (b) every earlier saved version of any box — the customisation is
     * versioned, so what a box said before it was edited is still there.
     * Newest first.
     *
     * @return list<array{fy: string, box: array<string, mixed>, saved_at: string, saved_by: string, status: string, replaced_at: string}>
     */
    public function archive(string $today, int $fyStartMonth): array
    {
        if ($this->customisation === null) {
            return [];
        }

        $versions = array_reverse($this->customisation->history(self::KEY)); // oldest first
        $live = $this->years();
        $out = [];
        $seen = [];
        $lastSeen = []; // fy|boxId => index into $out of the latest snapshot seen

        foreach ($versions as $i => $row) {
            $payload = json_decode((string) ($row['payload'] ?? ''), true);
            $when = (string) ($row['created_at'] ?? $row['updated_at'] ?? '');

            foreach ((array) ($payload['years'] ?? []) as $fy => $yr) {
                foreach ((array) ($yr['boxes'] ?? []) as $box) {
                    $sig = $fy . '|' . ($box['id'] ?? '') . '|' . json_encode([$box['scope'] ?? [], $box['type'] ?? '', $box['slab_mode'] ?? '', $box['slabs'] ?? []]);

                    if (isset($seen[$sig])) {
                        continue;
                    }

                    $seen[$sig] = true;
                    $slot = $fy . '|' . ($box['id'] ?? '');

                    if (isset($lastSeen[$slot])) {
                        $out[$lastSeen[$slot]]['replaced_at'] = $when;
                    }

                    $out[] = ['fy' => (string) $fy, 'box' => $box, 'saved_at' => (string) ($box['saved_at'] ?? $when),
                        'saved_by' => (string) ($box['saved_by'] ?? $row['changed_by'] ?? ''), 'status' => '', 'replaced_at' => ''];
                    $lastSeen[$slot] = array_key_last($out);
                }
            }
        }

        $keep = [];

        foreach ($out as $entry) {
            $stillLive = false;

            foreach ((array) ($live[$entry['fy']]['boxes'] ?? []) as $b) {
                if ((string) ($b['id'] ?? '') === (string) ($entry['box']['id'] ?? '')
                    && json_encode([$b['scope'] ?? [], $b['type'] ?? '', $b['slab_mode'] ?? '', $b['slabs'] ?? []])
                        === json_encode([$entry['box']['scope'] ?? [], $entry['box']['type'] ?? '', $entry['box']['slab_mode'] ?? '', $entry['box']['slabs'] ?? []])) {
                    $stillLive = true;
                }
            }

            if ($stillLive && self::isCurrent($entry['fy'], $today, $fyStartMonth)) {
                continue; // today's criteria: on the main screen, not the archive
            }

            $entry['status'] = $stillLive ? 'Past financial year' : 'Superseded by a later edit';
            $keep[] = $entry;
        }

        usort($keep, static fn (array $a, array $b): int => [$b['fy'], $b['saved_at']] <=> [$a['fy'], $a['saved_at']]);

        return $keep;
    }

    /**
     * Every problem with a slab table, PER FIELD, so the form can put each
     * message next to the box it is about. Keys: row index => field => text.
     * `clean` is the table as it will be saved.
     *
     * @param list<array<string, mixed>> $slabRows
     * @return array{errors: array<int, array<string, string>>, clean: list<array<string, string>>}
     */
    public static function checkSlabs(string $type, string $slabMode, array $slabRows): array
    {
        $numeric = $type !== 'custom';
        $ranged = $numeric && $slabMode === 'range';
        $errors = [];
        $clean = [];
        $seen = [];
        $spans = [];

        foreach (array_values($slabRows) as $i => $row) {
            $from = trim((string) ($row['rating'] ?? ''));
            $to = $ranged ? trim((string) ($row['rating_to'] ?? '')) : '';
            $desc = trim((string) ($row['description'] ?? ''));
            $pct = self::normalisePct((string) ($row['increment_pct'] ?? ''));

            if ($from === '' && $to === '' && $desc === '' && $pct === '') {
                continue; // a blank row on the form is not a slab
            }

            $e = [];

            if ($from === '') {
                $e['rating'] = $ranged ? 'Enter the lowest rating of this slab.' : 'Enter the rating.';
            } elseif ($numeric && !self::isDecimal($from)) {
                $e['rating'] = "'{$from}' is not a number. Switch the type to Custom labels for text ratings.";
            } elseif (!$numeric && mb_strlen($from) > 40) {
                $e['rating'] = 'A rating label is 40 characters at most.';
            }

            if ($ranged) {
                if ($to === '') {
                    $e['rating_to'] = 'Enter the highest rating of this slab.';
                } elseif (!self::isDecimal($to)) {
                    $e['rating_to'] = "'{$to}' is not a number.";
                } elseif (!isset($e['rating']) && Rational::fromDecimalString($to)->compareTo(Rational::fromDecimalString($from)) < 0) {
                    $e['rating_to'] = 'The "to" rating cannot be below the "from" rating.';
                }
            }

            if ($pct === '') {
                $e['increment_pct'] = 'Enter the % salary increase (0 for none).';
            } elseif (!self::isDecimal($pct)) {
                $e['increment_pct'] = "'" . trim((string) ($row['increment_pct'] ?? '')) . "' is not a percentage — type a number such as 8 or 12.5.";
            } elseif (Rational::fromDecimalString($pct)->compareTo(Rational::of(1000)) > 0) {
                $e['increment_pct'] = 'That is more than a 1000% increase — check the number.';
            }

            if (mb_strlen($desc) > 200) {
                $e['description'] = 'Keep the description to 200 characters.';
            }

            if (!isset($e['rating']) && !isset($e['rating_to'])) {
                if ($ranged) {
                    $f = Rational::fromDecimalString($from);
                    $t = Rational::fromDecimalString($to);

                    foreach ($spans as $j => [$sf, $st]) {
                        if ($f->compareTo($st) <= 0 && $t->compareTo($sf) >= 0) {
                            $e['rating'] = 'This range overlaps row ' . ($j + 1) . '.';
                        }
                    }

                    $spans[$i] = [$f, $t];
                } else {
                    $key = $numeric ? (string) Rational::fromDecimalString($from) : mb_strtolower($from);

                    if (isset($seen[$key])) {
                        $e['rating'] = "Rating '{$from}' is already in row " . ($seen[$key] + 1) . '.';
                    }

                    $seen[$key] = $i;
                }
            }

            if ($e !== []) {
                $errors[$i] = $e;

                continue;
            }

            $clean[] = ['rating' => $from, 'rating_to' => $to, 'description' => $desc, 'increment_pct' => $pct];
        }

        return ['errors' => $errors, 'clean' => $clean];
    }

    /** "20%", " 20 % ", "20" → "20"; anything else is returned trimmed for the caller to refuse. */
    public static function normalisePct(string $typed): string
    {
        $t = trim($typed);

        return preg_match('/^(\d+(?:\.\d+)?)\s*%$/', $t, $m) === 1 ? $m[1] : $t;
    }

    private static function isDecimal(string $v): bool
    {
        return preg_match('/^\d+(\.\d+)?$/', $v) === 1;
    }

    /**
     * Create or update one box. Identity is FY + box id when editing, or FY +
     * an EXACT scope match when creating (re-submitting the same scope's
     * slabs updates that box rather than adding a duplicate that would tie
     * with it on specificity forever after).
     *
     * @param array<string, string> $scope
     * @param list<array<string, mixed>> $slabRows
     * @return array{ok: bool, why: string, errors?: array<int, array<string, string>>}
     */
    public function saveBox(string $fy, ?string $boxId, array $scope, string $type, array $slabRows,
        string $byId, string $byLabel, string $slabMode = 'exact'): array
    {
        if ($this->customisation === null) {
            return ['ok' => false, 'why' => 'Customisation storage is not available here.'];
        }

        if ($fy === '' || self::parseKey($fy)['granularity'] === 'custom') {
            return ['ok' => false, 'why' => 'Pick a year and a period type first.'];
        }

        $type = $type === 'custom' ? 'custom' : 'numeric';
        $slabMode = $type === 'numeric' && $slabMode === 'range' ? 'range' : 'exact';
        $cleanScope = [];

        foreach (self::SCOPE_DIMENSIONS as $d) {
            $v = trim((string) ($scope[$d] ?? ''));
            $v !== '' && $cleanScope[$d] = $v;
        }

        $checked = self::checkSlabs($type, $slabMode, $slabRows);

        if ($checked['errors'] !== []) {
            $first = array_key_first($checked['errors']);

            return ['ok' => false, 'errors' => $checked['errors'],
                'why' => 'Row ' . ($first + 1) . ': ' . reset($checked['errors'][$first]) . ' Nothing was lost — fix the highlighted boxes and save again.'];
        }

        $clean = $checked['clean'];

        if ($clean === []) {
            return ['ok' => false, 'why' => 'At least one rating/% slab is needed.'];
        }

        $years = $this->years();
        $boxes = (array) ($years[$fy]['boxes'] ?? []);
        $record = static fn (string $id): array => ['id' => $id, 'scope' => $cleanScope, 'type' => $type, 'slab_mode' => $slabMode,
            'slabs' => $clean, 'saved_at' => date('Y-m-d H:i:s'), 'saved_by' => $byLabel];
        $id = $boxId !== null && $boxId !== '' ? $boxId : self::newBoxId();
        $found = false;

        foreach ($boxes as $idx => $b) {
            if ((string) ($b['id'] ?? '') === $id) {
                $boxes[$idx] = $record($id);
                $found = true;

                break;
            }
        }

        if (!$found) {
            // No id matched (a brand-new box) — but a box with this EXACT
            // scope already existing in this FY is that same customisation
            // being re-saved, not a second, permanently-tied-for-specificity
            // one.
            foreach ($boxes as $idx => $b) {
                if ((array) ($b['scope'] ?? []) === $cleanScope) {
                    $id = (string) $b['id'];
                    $boxes[$idx] = $record($id);
                    $found = true;

                    break;
                }
            }
        }

        if (!$found) {
            $boxes[] = $record($id);
        }

        $years[$fy] = ['boxes' => array_values($boxes)];

        return $this->customisation->saveRaw(self::KEY, ['years' => $years], $byId, $byLabel,
            'Performance appraisal — ' . self::label($fy) . (count($cleanScope) === 0 ? ', all employees' : ''));
    }

    /** The FY key for Year + Granularity (+ sub-period for anything shorter than a year). */
    public static function buildKey(int $year, string $granularity, string $sub): string
    {
        return match ($granularity) {
            'half_year' => in_array($sub, ['H1', 'H2'], true) ? $year . '-' . $sub : '',
            'quarter' => in_array($sub, ['Q1', 'Q2', 'Q3', 'Q4'], true) ? $year . '-' . $sub : '',
            'month' => preg_match('/^M(0[1-9]|1[0-2])$/', $sub) === 1 ? $year . '-' . $sub : '',
            'yearly' => $year . '-' . str_pad((string) (($year + 1) % 100), 2, '0', STR_PAD_LEFT),
            default => '',
        };
    }

    /** A FY key, read back into what created it (and a display label). */
    public static function parseKey(string $fy): array
    {
        if (preg_match('/^(\d{4})-H([12])$/', $fy, $m) === 1) {
            return ['year' => (int) $m[1], 'granularity' => 'half_year', 'sub' => 'H' . $m[2],
                'label' => $m[1] . ' H' . $m[2] . ' (Half-Yearly)'];
        }

        if (preg_match('/^(\d{4})-Q([1-4])$/', $fy, $m) === 1) {
            return ['year' => (int) $m[1], 'granularity' => 'quarter', 'sub' => 'Q' . $m[2],
                'label' => $m[1] . ' Q' . $m[2] . ' (Quarterly)'];
        }

        if (preg_match('/^(\d{4})-M(0[1-9]|1[0-2])$/', $fy, $m) === 1) {
            return ['year' => (int) $m[1], 'granularity' => 'month', 'sub' => 'M' . $m[2],
                'label' => $m[1] . ' ' . self::MONTHS[(int) $m[2]] . ' (Monthly)'];
        }

        if (preg_match('/^(\d{4})-(\d{2})$/', $fy, $m) === 1) {
            return ['year' => (int) $m[1], 'granularity' => 'yearly', 'sub' => '', 'label' => $fy . ' (Yearly)'];
        }

        return ['year' => 0, 'granularity' => 'custom', 'sub' => '', 'label' => $fy];
    }

    /** The FY key, as a person reads it: "2026-27 (Yearly)", "2026 Q2 (Quarterly)", … */
    public static function label(string $fy): string
    {
        return self::parseKey($fy)['label'];
    }

    /** @param array<string, string> $scope @param array<string, string> $employeeOrgValues */
    private static function scopeMatches(array $scope, array $employeeOrgValues): bool
    {
        foreach (self::SCOPE_DIMENSIONS as $d) {
            $want = trim((string) ($scope[$d] ?? ''));

            if ($want !== '' && strcasecmp(trim((string) ($employeeOrgValues[$d] ?? '')), $want) !== 0) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, string> $scope */
    private static function specificity(array $scope): int
    {
        $n = 0;

        foreach (self::SCOPE_DIMENSIONS as $d) {
            trim((string) ($scope[$d] ?? '')) !== '' && $n++;
        }

        return $n;
    }

    /** Sort tuple for fys(): newest year first, then finer granularity first, then sub-period. */
    private static function sortTuple(string $fy): array
    {
        $p = self::parseKey($fy);
        $order = ['month' => 4, 'quarter' => 3, 'half_year' => 2, 'yearly' => 1, 'custom' => 0];

        return [$p['year'], $order[$p['granularity']] ?? 0, $p['sub']];
    }

    private static function newBoxId(): string
    {
        return bin2hex(random_bytes(4));
    }
}
