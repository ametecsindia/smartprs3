<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * THE ID CARD FORMAT: what is on the card, in what colours, at what size.
 *
 * Declared in config/id-card.json (the fields a card may carry, the sizes, the
 * defaults); the tenant's choice is saved through `Customisation` under the
 * declared key, versioned, so the card printed in March can be explained in
 * June. `IdCardPdf` and the on-screen card both render from `format()`.
 */
final class IdCardFormat
{
    public function __construct(
        private readonly string $file,
        private readonly Customisation $customisation,
    ) {
    }

    public function declared(): array
    {
        $d = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : null;

        if (!is_array($d) || !is_array($d['fields'] ?? null) || !is_array($d['sizes'] ?? null) || !is_array($d['defaults'] ?? null)) {
            throw new \RuntimeException("{$this->file} does not declare an ID card format (fields, sizes, defaults).");
        }

        return $d;
    }

    /** The live format: the tenant's saved choice over the declared defaults, cleaned. */
    public function format(): array
    {
        $d = $this->declared();
        $saved = $this->customisation->live()[(string) $d['customisation_key']] ?? [];

        return $this->clean(is_array($saved) ? $saved : [], $d);
    }

    /** @return array{ok: bool, why: string, version?: int} */
    public function save(array $choice, string $byId, string $byLabel): array
    {
        $d = $this->declared();
        $clean = $this->clean($choice, $d);

        if ($clean['fields'] === [] && !$clean['show_photo']) {
            return ['ok' => false, 'why' => 'A card with no fields and no photograph is a coloured rectangle. Keep at least one.'];
        }

        return $this->customisation->saveRaw((string) $d['customisation_key'], $clean, $byId, $byLabel, 'ID card format');
    }

    // ------------------------------------------------------------ NAMED FORMATS (10 Sep)
    // The owner's ask: a format saved under a NAME for a company, branch, department,
    // designation or one employee; the most specific one that matches a person wins,
    // the unscoped format above is the fallback. Kept under one customisation key as a
    // list, versioned like everything else, so a deleted entry is a version too.

    // ROLE COMPARES AGAINST A RAW 'role' KEY ON THE EMPLOYEE ARRAY PASSED IN —
    // never `employees.role` (the administrator/manager/viewer access tier).
    // Callers (public/app.php) overlay the real job Role onto that key before
    // calling formatFor()/saveEntry(), the same way orgValueOf() resolves it
    // through the employee's designation_id into Role/Designation Setup —
    // IdCardFormat has no RowStore of its own to do that resolution here.
    public const SCOPES = ['company' => 'employer_company_id', 'branch' => 'branch_id', 'department' => 'department_id', 'role' => 'role', 'designation' => 'designation_id', 'employee' => 'id'];

    /**
     * VISITOR IS A CARD TEMPLATE, NOT AN EMPLOYEE — nobody visiting site has a
     * row in `employees`, so a format can still be NAMED and SAVED against
     * this sentinel `id` (never a real employee id) and `formatFor()` below
     * matches it exactly like any other employee-scoped entry. One constant,
     * shared by the Format Designer's Employee scope picker, the ID Cards
     * screen's Employee picker, and the PDF action — so the three can never
     * drift into checking three different sentinel strings.
     */
    public const VISITOR_ID = '__visitor__';

    /**
     * ANY OTHER CARD TYPE THE OWNER HAS ADDED under ID Card Format's "Card
     * types" list (Client, Guest, ...) gets a sentinel id shaped like this —
     * never a real employee id, exactly like VISITOR_ID, just one per type
     * instead of the single one Visitor used to have on its own. See
     * public/app.php's `$icExternalTypes` for where the built-in Visitor and
     * the owner's own list are combined into one set of options.
     */
    public const EXT_PREFIX = '__ext_';

    public static function isTemplateId(string $id): bool
    {
        return $id === self::VISITOR_ID || str_starts_with($id, self::EXT_PREFIX);
    }

    /**
     * A synthetic employee row for a template/no-record card — Visitor, or any
     * type the owner has added. Never a real person's data on its own; the ID
     * Cards screen overlays a real visitor_log entry's name/mobile/phone/
     * address/purpose on top of this when printing an actual visit rather
     * than previewing the bare template.
     */
    public static function templateRowFor(string $id, string $label): array
    {
        return ['id' => $id, 'emp_code' => strtoupper($label), 'name' => $label . ' Name', 'designation' => $label,
            'department' => '', 'branch' => '', 'doj' => date('Y-m-d'), 'dob' => '', 'blood_group' => '', 'mobile' => '',
            'email' => '', 'address' => '', 'emergency_phone' => '', 'device_user_id' => '', 'pan' => '', 'uan' => '',
            'esic_no' => '', 'photo_path' => '', 'employer_company_id' => '', 'branch_id' => '', 'department_id' => '',
            'designation_id' => '', 'visit_purpose' => '', 'visit_phone' => ''];
    }

    /** Kept for the handful of existing call sites; equivalent to templateRowFor(VISITOR_ID, 'Visitor'). */
    public static function visitorRow(): array
    {
        return self::templateRowFor(self::VISITOR_ID, 'Visitor');
    }

    /**
     * PRE-DRAFTED STARTING POINTS FOR THE BACK OF THE CARD (11 Sep) — the
     * owner asked for a handful of editable samples rather than a blank box;
     * picking one just fills the textarea, so it is a starting point, never
     * a locked-in choice. `{company}` is replaced the same way the footer
     * already is (see idCardFor() in public/app.php).
     *
     * @return array<string, string> label => draft text
     */
    public static function backSideSamples(): array
    {
        return [
            'Property & return' => 'This card is the property of {company}. If found, please return it to the '
                . 'nearest branch or contact HR. Unauthorised use, duplication or alteration of this card is prohibited.',
            'Terms of use' => 'This card must be worn visibly at all times on company premises. It is non-transferable '
                . 'and remains the property of {company}. Report loss or theft to HR immediately.',
            'Emergency & verification' => 'In case of emergency, please contact HR at {company}. This card certifies '
                . 'that the holder is associated with {company} as of the date of issue.',
            'Authorisation' => 'This identity card is issued by {company} and confirms the holder\'s association with '
                . 'the organisation. It must be produced on request by authorised personnel.',
            'Code of conduct' => 'The holder of this card has agreed to abide by the confidentiality and code-of-conduct '
                . 'policies of {company}. This card is valid only while the holder remains associated with the organisation.',
        ];
    }

    /** @return list<array{id: string, name: string, scope: array<string, string>, format: array<string, mixed>}> */
    public function entries(): array
    {
        $d = $this->declared();
        $saved = $this->customisation->live()[(string) $d['customisation_key'] . 's'] ?? [];
        $out = [];

        foreach (is_array($saved) ? (array) ($saved['entries'] ?? []) : [] as $e) {
            if (!is_array($e) || trim((string) ($e['name'] ?? '')) === '') {
                continue;
            }

            $scope = [];

            foreach (array_keys(self::SCOPES) as $k) {
                $scope[$k] = trim((string) ($e['scope'][$k] ?? ''));
            }

            $out[] = ['id' => (string) ($e['id'] ?? ''), 'name' => trim((string) $e['name']), 'scope' => $scope, 'format' => $this->clean((array) ($e['format'] ?? []), $d)];
        }

        return $out;
    }

    /** Save (or replace, by id) one named entry. @return array{ok: bool, why: string, id?: string} */
    public function saveEntry(string $id, string $name, array $scope, array $choice, string $byId, string $byLabel): array
    {
        $name = trim($name);

        if ($name === '') {
            return ['ok' => false, 'why' => 'A saved format needs a name (e.g. "Field agents – Kondapur").'];
        }

        $clean = $this->clean($choice, $this->declared());

        if ($clean['fields'] === [] && !$clean['show_photo']) {
            return ['ok' => false, 'why' => 'A card with no fields and no photograph is a coloured rectangle. Keep at least one.'];
        }

        $picked = [];

        foreach (array_keys(self::SCOPES) as $k) {
            $picked[$k] = trim((string) ($scope[$k] ?? ''));
        }

        if (array_filter($picked) === []) {
            return ['ok' => false, 'why' => 'Pick at least one of Company, Branch, Department, Role, Designation or Employee — a named format with no scope would apply to nobody. The unscoped format is the default for everyone.'];
        }

        $entries = array_values(array_filter($this->entries(), static fn (array $e): bool => $e['id'] !== $id));

        foreach ($entries as $e) {
            if (strcasecmp($e['name'], $name) === 0) {
                return ['ok' => false, 'why' => "A format named '{$name}' already exists — edit that one, or pick another name."];
            }
        }

        $id = $id !== '' ? $id : 'f' . substr(bin2hex(random_bytes(6)), 0, 10);
        $entries[] = ['id' => $id, 'name' => $name, 'scope' => $picked, 'format' => $clean];
        $r = $this->customisation->saveRaw((string) $this->declared()['customisation_key'] . 's', ['entries' => $entries], $byId, $byLabel, 'ID card format "' . $name . '"');

        return $r + ['id' => $id];
    }

    /** @return array{ok: bool, why: string} */
    public function deleteEntry(string $id, string $byId, string $byLabel): array
    {
        $before = $this->entries();
        $entries = array_values(array_filter($before, static fn (array $e): bool => $e['id'] !== $id));

        if (count($entries) === count($before)) {
            return ['ok' => false, 'why' => 'No such saved format.'];
        }

        return $this->customisation->saveRaw((string) $this->declared()['customisation_key'] . 's', ['entries' => $entries], $byId, $byLabel, 'ID card format deleted');
    }

    /**
     * The format for THIS employee: the matching entry with the most specific
     * scope (employee > designation > role > department > branch > company; a
     * tie goes to the entry that names more levels), else the unscoped format.
     * Role sits below designation — several designations usually share one
     * Role — and each rank exceeds the sum of every coarser one, so naming the
     * exact employee always wins over any combination of the rest.
     *
     * @return array{format: array<string, mixed>, entry: ?array<string, mixed>}
     */
    public function formatFor(array $employee): array
    {
        $rank = ['company' => 1, 'branch' => 2, 'department' => 4, 'role' => 8, 'designation' => 16, 'employee' => 32];
        $best = null;
        $bestScore = -1;

        foreach ($this->entries() as $e) {
            $score = 0;

            foreach (self::SCOPES as $k => $column) {
                if ($e['scope'][$k] === '') {
                    continue;
                }

                if ((string) ($employee[$column] ?? '') !== $e['scope'][$k]) {
                    $score = -1;

                    break;
                }

                $score += $rank[$k];
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $e;
            }
        }

        return ['format' => $best === null ? $this->format() : $best['format'], 'entry' => $best];
    }

    /**
     * TENTHS OF A MILLIMETRE, after orientation: [width, height].
     *
     * Integers, not floats — not because a card is money (law 7 is about money)
     * but because the gate that enforces law 7 cannot tell a millimetre from a
     * rupee, and an allow-list entry for a float is a review somebody has to do
     * for ever. Tenths are as fine as any card printer resolves.
     */
    public function dimensions(array $format): array
    {
        $sizes = $this->declared()['sizes'];
        $size = (string) $format['size'];

        if ($size === 'custom') {
            $w = self::tenths((string) $format['custom_w']);
            $h = self::tenths((string) $format['custom_h']);
        } else {
            $w = self::tenths((string) ($sizes[$size]['w'] ?? $sizes['CR80']['w']));
            $h = self::tenths((string) ($sizes[$size]['h'] ?? $sizes['CR80']['h']));
        }

        // Declared sizes are written landscape (wider than tall); a portrait
        // card is the same card turned.
        if ($format['orientation'] === 'portrait' && $w > $h || $format['orientation'] === 'landscape' && $h > $w) {
            [$w, $h] = [$h, $w];
        }

        return [$w, $h];
    }

    /**
     * The values to print for one employee, in the format's field order.
     *
     * @return list<array{code: string, label: string, value: string, kind: string}>
     */
    public function lines(array $format, array $employment): array
    {
        $declared = $this->declared()['fields'];
        $out = [];

        foreach ($format['fields'] as $code) {
            $f = $declared[$code] ?? null;

            if ($f === null) {
                continue;
            }

            // BARCODE ENCODES THE EMPLOYEE'S OWN ID, NOTHING ELSE (11 Sep) --
            // "basic employee details" per the owner's ask; Code::codeOrSay()
            // is the same value the Emp. ID field already prints, so scanning
            // the card and reading the Emp. ID always agree.
            $kind = (string) ($f['kind'] ?? 'value');
            $value = match ($kind) {
                'line' => '',
                'text' => (string) ($format[$code] ?? ''),
                'barcode' => Employment::codeOrSay($employment),
                default => trim((string) ($employment[(string) ($f['column'] ?? $code)] ?? '')),
            };

            if ($code === 'emp_code') {
                $value = Employment::codeOrSay($employment);
            }

            $out[] = ['code' => $code, 'label' => (string) $f['label'], 'value' => $value, 'kind' => $kind];
        }

        return $out;
    }

    private function clean(array $choice, array $d): array
    {
        $def = $d['defaults'];
        $out = $def;
        $out['size'] = isset($d['sizes'][(string) ($choice['size'] ?? '')]) ? (string) $choice['size'] : (string) $def['size'];
        $out['orientation'] = in_array((string) ($choice['orientation'] ?? ''), (array) $d['orientations'], true)
            ? (string) $choice['orientation'] : (string) $def['orientation'];
        $out['custom_w'] = self::mm($choice['custom_w'] ?? null, (string) $def['custom_w']);
        $out['custom_h'] = self::mm($choice['custom_h'] ?? null, (string) $def['custom_h']);

        $fields = $choice['fields'] ?? null;

        if (is_array($fields)) {
            $out['fields'] = array_values(array_filter(array_map('strval', $fields),
                static fn (string $c): bool => isset($d['fields'][$c])));
        }

        // FRONT OR BACK PER FIELD (owner, 22 Oct 2026): the ticked fields that print on the BACK of the card;
        // every other ticked field prints on the front. Always a subset of `fields`.
        $back = $choice['back_fields'] ?? null;
        $out['back_fields'] = is_array($back)
            ? array_values(array_intersect($out['fields'], array_map('strval', $back)))
            : array_values(array_intersect($out['fields'], (array) ($def['back_fields'] ?? [])));

        foreach (['show_logo', 'show_company_name', 'show_company_address', 'show_photo'] as $flag) {
            if (array_key_exists($flag, $choice)) {
                $out[$flag] = in_array($choice[$flag], [true, '1', 1, 'on', 'true'], true);
            }
        }

        foreach (['title', 'footer', 'valid_till'] as $text) {
            if (isset($choice[$text])) {
                $out[$text] = mb_substr(trim((string) $choice[$text]), 0, 120);
            }
        }

        // BACK OF THE CARD IS OPTIONAL (11 Sep) — blank means no 2nd page/face
        // at all, not a blank one. A paragraph, not a label, so it gets its own
        // longer cap rather than sharing title/footer's 120.
        if (isset($choice['back_text'])) {
            $out['back_text'] = mb_substr(trim((string) $choice['back_text']), 0, 600);
        }

        // VALID TILL CAN BE AUTOMATIC (11 Sep) — 'text' keeps the free-typed
        // value above exactly as before; 'days' computes "today + N days" at
        // PRINT time instead (see idCardFor() in public/app.php), so a card
        // reprinted later always shows a validity that starts from today, not
        // whatever date the format happened to be saved on.
        $out['valid_till_mode'] = ($choice['valid_till_mode'] ?? '') === 'days' ? 'days' : 'text';
        $days = (int) ($choice['valid_till_days'] ?? $def['valid_till_days']);
        $out['valid_till_days'] = $days >= 1 && $days <= 3650 ? $days : (int) $def['valid_till_days'];

        // HEADER STYLE (11 Sep, template gallery) — same closed-list pattern as
        // photo shape/border below: a template (see backSideSamples()'s sibling,
        // the config's own "templates" list) just pre-fills this and the colour
        // fields client-side before the owner saves, so nothing new is validated
        // here beyond the same 5 declared header shapes.
        $out['header_style'] = isset($d['header_styles'][(string) ($choice['header_style'] ?? '')]) ? (string) $choice['header_style'] : (string) $def['header_style'];

        // PHOTO BOX APPEARANCE (11 Sep) — shape and border are a closed list
        // straight from config/id-card.json (the same declared source the UI
        // dropdown reads), never freeform, so a bad value can't reach the
        // renderers. Blur amount is a 0-100 slider; anything else falls back.
        $out['photo_shape'] = isset($d['photo_shapes'][(string) ($choice['photo_shape'] ?? '')]) ? (string) $choice['photo_shape'] : (string) $def['photo_shape'];
        $out['photo_border'] = isset($d['photo_borders'][(string) ($choice['photo_border'] ?? '')]) ? (string) $choice['photo_border'] : (string) $def['photo_border'];
        $amount = (int) ($choice['photo_border_amount'] ?? $def['photo_border_amount']);
        $out['photo_border_amount'] = $amount >= 0 && $amount <= 100 ? $amount : (int) $def['photo_border_amount'];

        $colors = is_array($choice['colors'] ?? null) ? $choice['colors'] : [];

        foreach ($def['colors'] as $name => $fallback) {
            $c = strtoupper(trim((string) ($colors[$name] ?? '')));
            $out['colors'][$name] = preg_match('/^#[0-9A-F]{6}$/', $c) === 1 ? $c : $fallback;
        }

        return $out;
    }

    /** A millimetre figure as the string it was typed, kept when it is 30–300 mm. */
    private static function mm(mixed $v, string $fallback): string
    {
        if (!is_string($v) && !is_int($v)) {
            return $fallback;
        }

        $v = trim((string) $v);

        if (preg_match('/^\d{2,3}(\.\d)?$/', $v) !== 1) {
            return $fallback;
        }

        $tenths = self::tenths($v);

        return $tenths >= 300 && $tenths <= 3000 ? $v : $fallback;
    }

    /** '85.6' → 856, as integers all the way. */
    public static function tenths(string $mm): int
    {
        if (preg_match('/^(\d+)(?:\.(\d))?/', trim($mm), $m) !== 1) {
            return 0;
        }

        return (int) $m[1] * 10 + (int) ($m[2] ?? 0);
    }

    /** Tenths of a millimetre → PDF points with two decimals, by integer arithmetic (1 in = 25.4 mm = 72 pt). */
    public static function points(int $tenths): string
    {
        $hundredths = intdiv($tenths * 7200, 254);

        return intdiv($hundredths, 100) . '.' . sprintf('%02d', $hundredths % 100);
    }

    /** '#RRGGBB' → 'r g b' in 0..1 for a PDF operator, three decimals, integer arithmetic. */
    public static function rgb(string $hex): string
    {
        $hex = ltrim($hex, '#');
        $parts = [];

        foreach ([substr($hex, 0, 2), substr($hex, 2, 2), substr($hex, 4, 2)] as $p) {
            $thousandths = intdiv((int) hexdec($p) * 1000, 255);
            $parts[] = intdiv($thousandths, 1000) . '.' . sprintf('%03d', $thousandths % 1000);
        }

        return implode(' ', $parts);
    }
}
