<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * WHICH FIELDS THIS CUSTOMER KEEPS ON AN EMPLOYEE.
 *
 * Three screens ask an employee for their details — the Add form HR fills in,
 * the self-onboarding page the employee fills in, and the bulk upload file
 * somebody prepares in Excel. Until this class existed each of those three
 * carried its own list, so "we also capture the mother's name" meant editing
 * three files and remembering the third. They now read ONE list, and this is it.
 *
 * IT IS PER TENANT. A collections agency and a software company do not keep the
 * same fields, and the whole point of the rebuild is that the difference between
 * them is configuration rather than a branch in the code. The selection is
 * stored through `Customisation`, in the same append-only, versioned table every
 * other customisation lives in — a second table for the same idea is the defect
 * this rebuild exists to remove.
 *
 * EVERY FIELD IS CAPTURABLE, AND THE CUSTOMER CHOOSES ITS DATA TYPE. An earlier
 * cut of this greyed out any field `employees` had no column for — which was the
 * product telling a customer what they may know about their own staff. The
 * application still does not add columns at runtime (law 2, and the account it
 * runs as has no privilege to), so a field with no column is kept BESIDE the
 * record through `EmployeeExtras`. What each field says about itself is where it
 * is kept, never whether it may be kept at all.
 *
 * The data type is the customer's too: Text, Numeric, Alpha Numeric, Date, Email, Image/PDF/Doc,
 * Boolean or List. It decides the control the three screens render and the type
 * column in the upload sample, so changing it here changes what is asked for in
 * all three.
 */
final class EmployeeFields
{
    /** The customisation key. Same table, same versioning, same audit. */
    public const KEY = 'employee-fields';

    /**
     * A SEPARATE key for fields the tenant invented themselves, never mixed
     * into the shipped catalogue file. `config/employee-fields.json` is one
     * file shared by every tenant; a field one customer adds is theirs
     * alone, so it lives in `Customisation` like every other per-tenant
     * choice, and is laid over the shipped catalogue at read time instead
     * of being written into it.
     */
    private const CUSTOM_KEY = 'employee-fields-custom';

    private const TABLE = 'employees';

    /** The data types a customer may choose from, as the artefact listed them. */
    public const TYPES = ['Text', 'Numeric', 'Alpha Numeric', 'Date', 'Boolean (Yes/No/NA)', 'List', 'Email', 'Image/PDF/Doc'];

    /** @var array<string, mixed>|null */
    private ?array $catalogue = null;

    /** @var list<string>|null */
    private ?array $columns = null;

    /** @var array<string, string>|null */
    private ?array $columnTypes = null;

    public function __construct(
        private readonly RowStore $store,
        private readonly Customisation $customisation,
        private readonly string $file,
    ) {
    }

    /** @return array<string, mixed> the whole declared catalogue */
    public function catalogue(): array
    {
        if ($this->catalogue !== null) {
            return $this->catalogue;
        }

        $decoded = is_file($this->file)
            ? json_decode((string) file_get_contents($this->file), true)
            : null;

        if (!is_array($decoded) || ($decoded['sections'] ?? null) === null) {
            throw new \RuntimeException(
                'config/employee-fields.json is missing or unreadable. Refused rather than treated '
                . 'as "no fields", which would offer an empty form that looks like a tenant who '
                . 'keeps nothing about their people.'
            );
        }

        // A TENANT'S OWN FIELDS, LAID OVER THE SHIPPED CATALOGUE. Each one is
        // appended into the section it was declared for; a custom field
        // naming a section nobody shipped is simply not shown rather than
        // crashing the screen — that section's own catalogue entry going
        // away later should not take somebody's data with it.
        foreach ($this->customFields() as $custom) {
            foreach ($decoded['sections'] as $i => $section) {
                if ((string) $section['id'] === (string) $custom['section']) {
                    $decoded['sections'][$i]['fields'][] = [
                        'code' => $custom['code'], 'label' => $custom['label'],
                        'type' => $custom['type'], 'control' => null, 'column' => null,
                        'custom' => true,
                    ];

                    break;
                }
            }
        }

        return $this->catalogue = $decoded;
    }

    /**
     * The fields this tenant has invented themselves, in the order they were
     * added — none of them shipped in `config/employee-fields.json`.
     *
     * @return list<array{code: string, label: string, type: string, section: string}>
     */
    public function customFields(): array
    {
        return (array) ($this->customisation->live()[self::CUSTOM_KEY]['fields'] ?? []);
    }

    /**
     * Normalised for a duplicate check: lower-cased, letters and digits
     * only. "Blood Group" and "blood-group " land on the same string, which
     * is the point — a customer typing a field that already exists with
     * different punctuation should be told, not given a second one.
     */
    private static function normalise(string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower($text));
    }

    /**
     * Every existing field close enough to `$label` or `$code` that adding
     * another one beside it is probably a mistake — named, so the screen can
     * ask "did you mean this?" instead of silently refusing or silently
     * making a near-duplicate.
     *
     * @return list<array{code: string, label: string, section: string}>
     */
    public function similarTo(string $label, string $code): array
    {
        $needleLabel = self::normalise($label);
        $needleCode = self::normalise($code);
        $found = [];

        foreach ($this->catalogue()['sections'] as $section) {
            foreach ((array) $section['fields'] as $field) {
                $haveLabel = self::normalise((string) $field['label']);
                $haveCode = self::normalise((string) $field['code']);

                if ($haveLabel === '' || $needleLabel === '') {
                    continue;
                }

                $labelClose = $haveLabel === $needleLabel
                    || str_contains($haveLabel, $needleLabel) || str_contains($needleLabel, $haveLabel);
                $codeClose = $needleCode !== '' && $haveCode === $needleCode;

                if ($labelClose || $codeClose) {
                    $found[] = ['code' => (string) $field['code'], 'label' => (string) $field['label'],
                        'section' => (string) $section['title']];
                }
            }
        }

        return $found;
    }

    /**
     * Add a field this tenant invented — never one already in the
     * catalogue, whose code the check above is guarding. Refused, not
     * silently ignored, when the section does not exist, the code is
     * already taken, or the type is not one of `self::TYPES`; a close
     * label match is refused too UNLESS `$confirm` says the customer was
     * shown the suggestion and asked to add it anyway. Saving also marks
     * the new field captured, on the same terms as any field somebody
     * ticks by hand — otherwise "add a field" would add a field nobody
     * sees, on a screen whose whole premise is that a tick is what makes a
     * field real.
     *
     * @return array{ok: bool, why: string, similar: list<array{code: string, label: string, section: string}>}
     */
    public function addField(string $sectionId, string $label, string $type, bool $confirm,
        string $byId, string $byLabel, string $note = ''): array
    {
        $label = trim($label);

        if ($label === '') {
            return ['ok' => false, 'why' => 'A field needs a name.', 'similar' => []];
        }

        if (!in_array($type, self::TYPES, true)) {
            return ['ok' => false, 'why' => "'{$type}' is not a data type this product knows how to "
                . 'render. The types are: ' . implode(', ', self::TYPES) . '.', 'similar' => []];
        }

        // NOT A TYPE A CUSTOM FIELD MAY CHOOSE. Image/PDF/Doc fields with a
        // column (photo_path) and the Documents section's own entries each
        // go through their own upload handling, keyed off a `control` this
        // field would have none of — a custom one would collect a filename
        // as plain text and never actually move the file anywhere.
        if ($type === 'Image/PDF/Doc') {
            return ['ok' => false, 'why' => 'A custom field cannot be a file upload — that needs its '
                . 'own handling this register does not give a tenant-added field. Use one of the '
                . 'other types.', 'similar' => []];
        }

        $sectionExists = false;

        foreach ((array) $this->catalogue()['sections'] as $section) {
            if ((string) $section['id'] === $sectionId) {
                $sectionExists = true;

                break;
            }
        }

        if (!$sectionExists) {
            return ['ok' => false, 'why' => "'{$sectionId}' is not a section this register has.",
                'similar' => []];
        }

        $code = self::normalise($label);
        $code = $code === '' ? '' : 'custom_' . $code;

        $similar = $this->similarTo($label, $code);

        if ($similar !== [] && !$confirm) {
            return ['ok' => false, 'why' => count($similar) === 1
                ? "This looks like it might already be '{$similar[0]['label']}' (in "
                    . "{$similar[0]['section']}). Add it anyway if it is genuinely different."
                : 'This looks like it might already exist under a different name. Add it anyway if '
                    . 'it is genuinely different.', 'similar' => $similar];
        }

        $custom = $this->customFields();

        foreach ($custom as $existing) {
            if ((string) $existing['code'] === $code) {
                return ['ok' => false, 'why' => 'That field already exists.', 'similar' => []];
            }
        }

        $custom[] = ['code' => $code, 'label' => $label, 'type' => $type, 'section' => $sectionId];
        $saved = $this->customisation->saveRaw(self::CUSTOM_KEY, ['fields' => $custom], $byId, $byLabel,
            $note === '' ? "Added field '{$label}'" : $note);

        if (!($saved['ok'] ?? false)) {
            return ['ok' => false, 'why' => (string) ($saved['why'] ?? 'Nothing was saved.'), 'similar' => []];
        }

        // FORCE A RE-READ: the catalogue was already cached (possibly without
        // this field) earlier in the same request, by `similarTo()` above.
        $this->catalogue = null;

        $selection = [];

        foreach ($this->inUse() as $field) {
            $selection[(string) $field['code']] = ['required' => (bool) $field['required'],
                'type' => (string) $field['type']];
        }

        $selection[$code] = ['required' => false, 'type' => $type];
        $this->save($selection, $byId, $byLabel, "Captured new field '{$label}'");

        return ['ok' => true, 'why' => "'{$label}' added and captured — it now appears on the Add "
            . 'form, self-onboarding and the bulk upload sample.', 'similar' => []];
    }

    /**
     * The register: every section, every field, with what is true about it here.
     *
     * @return list<array<string, mixed>>
     */
    public function sections(): array
    {
        $chosen = $this->chosen();
        $columns = $this->columns();
        $sections = [];

        foreach ((array) $this->catalogue()['sections'] as $section) {
            $fields = [];

            foreach ((array) $section['fields'] as $field) {
                $column = $field['column'] ?? null;
                // A DOCUMENT IS NOT A COLUMN. It is an expectation about the
                // employee's file, so it is always available and never written
                // to `employees`.
                $isDocument = ($field['control'] ?? '') === 'document';
                $available = $isDocument
                    || ($column !== null && in_array((string) $column, $columns, true));

                $code = (string) $field['code'];
                $saved = $chosen[$code] ?? null;
                // THE CUSTOMER'S TYPE WINS over the catalogue's suggestion. The
                // catalogue says what the field usually is; the register says
                // what this customer keeps, and the three screens render the
                // second.
                $type = (string) ($saved['type'] ?? $field['type'] ?? 'Text');

                // EXCEPT A FILE. A photograph, a logo, a signed copy is uploaded,
                // never typed as a path — a register saved before the catalogue
                // said so, or with Text picked by mistake, does not turn the
                // picker back into a text box on three screens.
                if (($field['type'] ?? '') === 'Image/PDF/Doc') {
                    $type = 'Image/PDF/Doc';
                }

                // array_merge, NOT `+`: the union operator keeps the LEFT-hand
                // key, so `$field + ['type' => …]` silently kept the catalogue's
                // suggestion and threw away the type the customer chose.
                $fields[] = array_merge($field, [
                    'available' => $available,
                    'document' => $isDocument,
                    'type' => in_array($type, self::TYPES, true) ? $type : 'Text',
                    // WHERE IT IS KEPT, not whether it may be. A column when the
                    // table has one, beside the record when it has not.
                    'storage' => $isDocument ? 'document' : ($available ? 'column' : 'extra'),
                    // WHAT THE COLUMN WILL ACCEPT, alongside what the form shows.
                    // They are different questions: `pf_applicable` renders as a
                    // Yes/No list and is a TINYINT, and the form that does not
                    // know that writes "Yes" into a number.
                    'column_type' => $column === null ? '' : ($this->types()[(string) $column] ?? ''),
                    'core' => (bool) ($field['core'] ?? false),
                    'captured' => (bool) ($field['core'] ?? false) || $saved !== null,
                    // THREE WAYS A FIELD BECOMES MANDATORY, and the catalogue's
                    // own `required` is the one that was missing. `core` is the
                    // product saying a record is meaningless without it; the
                    // saved value is this customer's own tick on this screen;
                    // and `required` in the catalogue is the product saying a
                    // field the customer may otherwise configure freely has to
                    // be filled when it IS captured — branch and reporting
                    // manager, because a hierarchy with holes in it is not a
                    // hierarchy (owner, 23 Sep).
                    //
                    // IT HAD TO BE A THIRD SOURCE RATHER THAN A CHANGE TO THE
                    // OTHER TWO. `core` also means "cannot be turned off", which
                    // is a different promise and not one to make here. And the
                    // saved selection is a SNAPSHOT of the screen at the moment
                    // Save was pressed — the same trap the attendance report's
                    // columns sprang — so flipping the catalogue alone would
                    // have reached a brand-new tenant and nobody else.
                    'required' => (bool) ($field['core'] ?? false)
                        || (bool) ($field['required'] ?? false)
                        || (bool) ($saved['required'] ?? false),
                ]);
            }

            $sections[] = ['id' => (string) $section['id'], 'title' => (string) $section['title'],
                'accent' => (string) ($section['accent'] ?? '#3C3F36'),
                'desc' => (string) ($section['desc'] ?? ''), 'fields' => $fields];
        }

        return $sections;
    }

    /**
     * The fields the three screens should show, in catalogue order.
     *
     * Every captured field that is not a document — whether it is kept in a
     * column or beside the record. This is the ONE list the Add form,
     * self-onboarding and the import sample are built from.
     *
     * @return list<array<string, mixed>>
     */
    public function inUse(): array
    {
        $fields = [];

        foreach ($this->sections() as $section) {
            foreach ($section['fields'] as $field) {
                if ($field['captured'] && !$field['document']) {
                    $fields[] = $field + ['section' => $section['title']];
                }
            }
        }

        return $fields;
    }

    /**
     * The documents this customer expects on an employee's file.
     *
     * @return list<array<string, mixed>>
     */
    public function documents(): array
    {
        $documents = [];

        foreach ($this->sections() as $section) {
            foreach ($section['fields'] as $field) {
                if ($field['document'] && $field['captured']) {
                    $documents[] = $field;
                }
            }
        }

        return $documents;
    }

    /**
     * Save a selection.
     *
     * @param array<string, array<string, mixed>> $selection code => ['required' => bool]
     * @return array{ok: bool, why: string, refused: list<string>}
     */
    public function save(array $selection, string $byId, string $byLabel, string $note = ''): array
    {
        $refused = $this->refusals($selection);

        if ($refused !== []) {
            return ['ok' => false, 'refused' => $refused, 'why' => 'Nothing was saved.'];
        }

        $clean = [];

        foreach ($selection as $code => $options) {
            $type = (string) ($options['type'] ?? '');
            $clean[(string) $code] = [
                'required' => (bool) ($options['required'] ?? false),
                'type' => in_array($type, self::TYPES, true) ? $type : 'Text',
            ];
        }

        // EVERY CODE THE CATALOGUE CARRIES AS OF THIS SAVE. So a field the
        // catalogue adds LATER can be told apart, at the next read, from one
        // this tenant already saw here and chose to leave unticked — see the
        // `known` handling in chosen().
        $known = [];

        foreach ((array) $this->catalogue()['sections'] as $section) {
            foreach ((array) $section['fields'] as $field) {
                $known[] = (string) $field['code'];
            }
        }

        $result = $this->customisation->saveRaw(self::KEY,
            ['captured' => $clean, 'known' => array_values(array_unique($known))], $byId, $byLabel,
            $note === '' ? 'Employee field register' : $note);

        return ['ok' => (bool) $result['ok'], 'refused' => [],
            'why' => (string) ($result['why'] ?? '')];
    }

    /**
     * Everything wrong with a proposed selection, in words.
     *
     * @param array<string, array<string, mixed>> $selection
     * @return list<string>
     */
    public function refusals(array $selection): array
    {
        $known = [];
        $refused = [];

        foreach ((array) $this->catalogue()['sections'] as $section) {
            foreach ((array) $section['fields'] as $field) {
                $known[(string) $field['code']] = $field;
            }
        }

        foreach (array_keys($selection) as $code) {
            $code = (string) $code;

            if (!isset($known[$code])) {
                $refused[] = "'{$code}' is not a field in config/employee-fields.json. A field "
                    . 'nobody declared is one no screen would know how to render.';

                continue;
            }

            // NO FIELD IS REFUSED FOR WANT OF A COLUMN. One that has none is
            // kept beside the record instead; the register says which, and the
            // customer decides what they keep.
            $type = (string) ($selection[$code]['type'] ?? '');

            if ($type !== '' && !in_array($type, self::TYPES, true)) {
                $refused[] = "'{$type}' is not a data type this product knows how to render. The "
                    . 'types are: ' . implode(', ', self::TYPES) . '.';
            }
        }

        // A CORE FIELD CANNOT BE TURNED OFF, and saying so is better than
        // quietly putting it back: somebody who unticked it meant something.
        foreach ($known as $code => $field) {
            if (($field['core'] ?? false) && !isset($selection[$code])) {
                $refused[] = "'" . (string) $field['label'] . "' cannot be turned off. An employee "
                    . 'with no code or no name is a row nothing else in the product can point at — '
                    . 'attendance, leave, payroll and every letter are keyed by it.';
            }
        }

        return $refused;
    }

    /**
     * The captured fields that have no column and are therefore kept beside the
     * record. Named on the screen so nobody has to guess where a value went.
     *
     * @return list<string>
     */
    public function keptBesideTheRecord(): array
    {
        $beside = [];

        foreach ($this->inUse() as $field) {
            if ($field['storage'] === 'extra') {
                $beside[] = (string) $field['label'];
            }
        }

        return $beside;
    }

    /**
     * What this tenant has chosen, or the starting set when nobody has chosen.
     *
     * NOT AN EMPTY SET WHEN NOTHING IS SAVED. An empty register would mean an Add
     * form with two boxes on it, which reads as a broken screen rather than an
     * unconfigured one.
     *
     * @return array<string, array<string, mixed>>
     */
    private function chosen(): array
    {
        $record = (array) ($this->customisation->live()[self::KEY] ?? []);
        $saved = (array) ($record['captured'] ?? []);

        if ($saved === []) {
            // A FRESH TENANT: nothing saved yet, so the starting set is every
            // field the catalogue marks default or core.
            $starting = [];

            foreach ((array) $this->catalogue()['sections'] as $section) {
                foreach ((array) $section['fields'] as $field) {
                    if (($field['default'] ?? false) || ($field['core'] ?? false)) {
                        $starting[(string) $field['code']] = [
                            'required' => (bool) ($field['core'] ?? false),
                            'type' => (string) ($field['type'] ?? 'Text'),
                        ];
                    }
                }
            }

            return $starting;
        }

        // A TENANT WHO HAS ALREADY CUSTOMISED THEIR REGISTER keeps their own
        // choices — but a field the catalogue has ADDED SINCE THEY LAST
        // SAVED (never offered to them, so never something they could have
        // turned off) is not the same thing as a field they deliberately
        // unticked. `known` is the snapshot of every code the catalogue
        // carried at their last save (written by save(), below); a
        // default/core field missing from BOTH the saved selection and that
        // snapshot is new since then and joins the register automatically —
        // exactly as it would for a brand-new tenant — instead of silently
        // staying off the Add form, self-onboarding and the bulk sample
        // until somebody happens to revisit this screen and re-save it. A
        // record saved before this reconciliation existed carries no
        // `known` list at all, read as "nothing was known yet" — so on the
        // first read after this change ships, every current default/core
        // field not already captured is reconciled in once; from the next
        // save onward `known` is recorded and only genuinely new fields
        // keep getting added automatically.
        $known = array_map('strval', (array) ($record['known'] ?? []));

        foreach ((array) $this->catalogue()['sections'] as $section) {
            foreach ((array) $section['fields'] as $field) {
                $code = (string) $field['code'];

                if (isset($saved[$code]) || in_array($code, $known, true)) {
                    continue;
                }

                if (!(($field['default'] ?? false) || ($field['core'] ?? false))) {
                    continue;
                }

                $saved[$code] = [
                    'required' => (bool) ($field['core'] ?? false),
                    'type' => (string) ($field['type'] ?? 'Text'),
                ];

                // A FIELD A NEWER CATALOGUE SPLITS INTO SEVERAL REPLACES the
                // one(s) it names — the split address fields replace the
                // old single-line `address`/`permanent_address`, so the
                // legacy field drops out of THIS tenant's stale selection
                // the moment its replacement is reconciled in, rather than
                // the old box and the new ones showing side by side.
                foreach ((array) ($field['replaces'] ?? []) as $old) {
                    unset($saved[(string) $old]);
                }
            }
        }

        return $saved;
    }

    /**
     * The base type of each column — `tinyint`, `varchar`, `date` — with the
     * length stripped, because nothing here needs it.
     *
     * @return array<string, string>
     */
    private function types(): array
    {
        if ($this->columnTypes !== null) {
            return $this->columnTypes;
        }

        if (!$this->store instanceof SqlStore) {
            return $this->columnTypes = [];
        }

        try {
            $types = [];

            foreach ($this->store->columnTypes(self::TABLE) as $column => $type) {
                $types[(string) $column] = strtolower(trim(explode('(', (string) $type)[0]));
            }

            return $this->columnTypes = $types;
        } catch (\Throwable) {
            return $this->columnTypes = [];
        }
    }

    /**
     * The columns the live table actually has.
     *
     * Asked of the DATABASE, not of the catalogue — the catalogue is what we
     * would like to keep, and this is what there is room for.
     *
     * @return list<string>
     */
    private function columns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }

        // THE FILE STORE HAS NO COLUMNS — it takes whatever key it is handed, so
        // asking it which columns exist returns whichever keys the rows that are
        // already there happen to use. On an installation with no employees yet
        // that is none, and every field would report itself unavailable on a
        // store that would have accepted all of them.
        if (!$this->store instanceof SqlStore) {
            $declared = [];

            foreach ((array) $this->catalogue()['sections'] as $section) {
                foreach ((array) $section['fields'] as $field) {
                    if (($field['column'] ?? null) !== null) {
                        $declared[] = (string) $field['column'];
                    }
                }
            }

            return $this->columns = $declared;
        }

        try {
            return $this->columns = array_map('strval', $this->store->columnsOf(self::TABLE));
        } catch (\Throwable) {
            // A store that cannot answer is not the same as a table with no
            // columns. Reported by the screen; here it means "nothing is
            // available", which is the safe reading — it refuses saves rather
            // than accepting values into a table it cannot see.
            return $this->columns = [];
        }
    }
}
