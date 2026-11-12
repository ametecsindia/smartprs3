<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * A CUSTOMER'S OWN VERSION OF A MODULE, LAID OVER THE ONE THAT SHIPPED.
 *
 * `config/modules.json` says what SmartPRS 3 ships with. This says what this
 * customer changed: the field they call "Employee code" and everyone here calls
 * "Token no.", the four statuses they actually use instead of the eight in the
 * box, the columns they want in the list, the fields they never fill in. In
 * SmartPRS 2 every one of those is a code change and a release.
 *
 * WHAT CAN BE OVERRIDDEN IS A CLOSED LIST, and the reason is not tidiness. The
 * table a module reads, the permission it needs and the table a lookup points at
 * are the things a customisation must never touch: an administrator renaming a
 * field must not be able to point a screen at another table or drop the
 * permission in front of it. So `table`, `key`, `permissions` and lookup targets
 * are structural and refused here — loudly, naming the key, because a
 * customisation that quietly does nothing is worse than one that fails.
 *
 * EVERY SAVE IS A NEW VERSION. Nothing is overwritten, because "what did this
 * field say before somebody changed it?" is asked precisely when something has
 * gone wrong, and that is when an overwritten row has nothing to say.
 *
 * ponytail: no effective-dating on customisations — the highest version is the
 * live one. Add `effective_from` when a customer needs a label to change with a
 * financial year rather than immediately.
 */
final class Customisation
{
    private const COLLECTION = 'module_customisations';

    /** What an administrator may change about a module. Anything else is refused. */
    public const MODULE_KEYS = ['label', 'columns', 'search', 'display'];

    /** What an administrator may change about one field. Note `lookup` is NOT here. */
    public const FIELD_KEYS = ['label', 'type', 'required', 'readonly', 'hidden', 'help', 'options'];

    /** @var array<string, array<string, mixed>>|null module key => overrides */
    private ?array $live = null;

    public function __construct(private readonly RowStore $store)
    {
    }

    /**
     * The live overrides for every module: the highest version of each.
     *
     * @return array<string, array<string, mixed>>
     */
    public function live(): array
    {
        if ($this->live !== null) {
            return $this->live;
        }

        try {
            $rows = $this->store->all(self::COLLECTION);
        } catch (\Throwable) {
            // A missing table means "nothing customised yet", which is the
            // truthful reading BEFORE the migration has run and the only one that
            // lets the application start. It is not silence about a failure: the
            // configure screen reports it when somebody tries to save.
            return $this->live = [];
        }

        $best = [];

        foreach ($rows as $row) {
            $key = (string) ($row['module_key'] ?? '');
            $version = (int) ($row['version'] ?? 0);

            if ($key === '' || $version <= (int) ($best[$key]['version'] ?? 0)) {
                continue;
            }

            $payload = json_decode((string) ($row['payload'] ?? ''), true);
            $best[$key] = ['version' => $version, 'payload' => is_array($payload) ? $payload : []];
        }

        return $this->live = array_map(static fn (array $r): array => $r['payload'], $best);
    }

    /**
     * Lay this customer's overrides over a shipped module declaration.
     *
     * Fields MERGE rather than replace: an override that says "this one field is
     * required" must not silently discard the lookup, options and help the
     * shipped declaration gave every other field.
     */
    public function apply(array $module): array
    {
        $override = $this->live()[(string) $module['key']] ?? [];

        if ($override === []) {
            return $module;
        }

        foreach (self::MODULE_KEYS as $key) {
            if (array_key_exists($key, $override)) {
                $module[$key] = $override[$key];
            }
        }

        foreach ((array) ($override['fields'] ?? []) as $name => $spec) {
            $module['fields'][$name] = array_merge(
                (array) ($module['fields'][$name] ?? []),
                array_intersect_key((array) $spec, array_flip(self::FIELD_KEYS)),
            );
        }

        $module['customised'] = true;

        return $module;
    }

    /**
     * EVERYTHING THIS CUSTOMER HAS CHANGED, in one list.
     *
     * A configuration-first product where the configuration is spread across
     * three screens and a database table has the same problem as a code-first one:
     * nobody can answer "what is different about our installation?" without
     * going and looking in four places. This answers it in one.
     *
     * IT NAMES ORPHANS. A customisation whose key is no longer declared anywhere
     * — a module removed from the registry, a workflow retired — sits in the
     * table doing nothing, for ever, while somebody believes their change is in
     * force. That is the quietest failure this whole table can have, so it is
     * reported rather than filtered out of the list.
     *
     * @param array<string, string> $known every declared key => how to describe it
     * @return list<array<string, mixed>>
     */
    public function inventory(array $known): array
    {
        try {
            $rows = $this->store->all(self::COLLECTION);
        } catch (\Throwable $e) {
            return [['error' => $e->getMessage()]];
        }

        $latest = [];

        foreach ($rows as $row) {
            $key = (string) ($row['module_key'] ?? '');

            if ($key === '' || (int) $row['version'] <= (int) ($latest[$key]['version'] ?? 0)) {
                continue;
            }

            $latest[$key] = $row + ['versions' => 0];
        }

        foreach ($rows as $row) {
            $key = (string) ($row['module_key'] ?? '');

            if (isset($latest[$key])) {
                $latest[$key]['versions']++;
            }
        }

        $out = [];

        foreach ($latest as $key => $row) {
            [$kind, $name] = str_contains($key, ':')
                ? explode(':', $key, 2)
                : ['module', $key];

            $out[] = [
                'kind' => $kind,
                'key' => $name,
                'label' => $known[$key] ?? '',
                'orphan' => !array_key_exists($key, $known),
                'version' => (int) $row['version'],
                'versions' => (int) $row['versions'],
                'changed_by' => (string) ($row['changed_by'] ?? ''),
                'at' => (string) ($row['created_at'] ?? ''),
                'note' => (string) ($row['note'] ?? ''),
                'payload' => (string) ($row['payload'] ?? ''),
            ];
        }

        usort($out, static fn (array $a, array $b): int
            => [$b['orphan'], $a['kind'], $a['key']] <=> [$a['orphan'], $b['kind'], $b['key']]);

        return $out;
    }

    /** @return list<array<string, mixed>> every version of one module's customisation, newest first */
    public function history(string $moduleKey): array
    {
        try {
            $rows = array_values(array_filter(
                $this->store->all(self::COLLECTION),
                static fn (array $r): bool => (string) ($r['module_key'] ?? '') === $moduleKey,
            ));
        } catch (\Throwable) {
            return [];
        }

        usort($rows, static fn (array $a, array $b): int => (int) $b['version'] <=> (int) $a['version']);

        return $rows;
    }

    /**
     * Record a new version of a module's customisation.
     *
     * @param array<string, mixed> $override
     * @return array{ok: bool, why: string, version?: int}
     */
    public function save(string $moduleKey, array $override, ?array $shipped, string $byId,
        string $byLabel, string $note = ''): array
    {
        if ($shipped === null) {
            return ['ok' => false, 'why' => "There is no module '{$moduleKey}' to customise."];
        }

        $problems = self::problems($override, $shipped);

        if ($problems !== []) {
            return ['ok' => false, 'why' => implode(' ', $problems)];
        }

        return $this->saveRaw($moduleKey, $override, $byId, $byLabel, $note);
    }

    /**
     * Append a version with the checking already done.
     *
     * Separate because a workflow customisation is checked against a workflow's
     * own rules, not a module's — but it is the SAME record, versioned the same
     * way, in the same append-only table. A second table for the same idea is the
     * defect this rebuild exists to remove.
     *
     * @param array<string, mixed> $override
     * @return array{ok: bool, why: string, version?: int}
     */
    public function saveRaw(string $key, array $override, string $byId, string $byLabel,
        string $note = ''): array
    {
        $moduleKey = $key;
        $version = (int) ($this->history($moduleKey)[0]['version'] ?? 0) + 1;

        try {
            $this->store->put(self::COLLECTION, [
                'module_key' => $moduleKey,
                'version' => $version,
                'payload' => json_encode($override, JSON_UNESCAPED_UNICODE),
                'changed_by_id' => $byId,
                'changed_by' => $byLabel,
                'note' => $note === '' ? null : $note,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'why' => $e->getMessage()];
        }

        $this->live = null;

        return ['ok' => true, 'version' => $version, 'why' => sprintf(
            'Saved as version %d. Version %s is kept — a customisation is a change to what a screen '
            . 'means, and what it meant before is the question asked when something has gone wrong.',
            $version,
            $version === 1 ? '0 (the one that shipped)' : (string) ($version - 1),
        )];
    }

    /**
     * What is wrong with a proposed customisation.
     *
     * @return list<string>
     */
    public static function problems(array $override, array $shipped): array
    {
        $problems = [];
        $columns = array_map('strval', (array) ($shipped['columns'] ?? []));
        $known = array_values(array_unique(array_merge(
            $columns,
            array_map('strval', array_keys((array) ($shipped['fields'] ?? []))),
        )));

        foreach (array_keys($override) as $key) {
            if ($key !== 'fields' && !in_array($key, self::MODULE_KEYS, true)) {
                $problems[] = sprintf(
                    '"%s" cannot be customised — it is structural. A screen\'s table, its key and the '
                    . 'permission in front of it are not settings: an administrator renaming a field '
                    . 'must not be able to point it at another table or take the permission off it.',
                    $key,
                );
            }
        }

        foreach (['columns', 'search'] as $key) {
            foreach ((array) ($override[$key] ?? []) as $column) {
                if (!in_array((string) $column, $known, true)) {
                    $problems[] = sprintf(
                        '%s lists "%s", which this module has no field for. Refused rather than '
                        . 'ignored: a column that shows nothing looks like empty data.',
                        ucfirst($key), (string) $column,
                    );
                }
            }
        }

        if (array_key_exists('columns', $override) && (array) $override['columns'] === []) {
            $problems[] = 'A module with no columns is a list of blank rows. Leave at least one.';
        }

        foreach ((array) ($override['fields'] ?? []) as $name => $spec) {
            if (!in_array((string) $name, $known, true)) {
                $problems[] = sprintf('There is no field "%s" on this module to customise.', (string) $name);

                continue;
            }

            foreach (array_keys((array) $spec) as $key) {
                if (!in_array((string) $key, self::FIELD_KEYS, true)) {
                    $problems[] = sprintf('"%s" is not something that can be set on field "%s".',
                        (string) $key, (string) $name);
                }
            }

            // CHECKED ON THE MERGED FIELD, not on the override alone. A module
            // that ships a field as required, customised only to hide it, has an
            // override in which `required` never appears — so a check reading the
            // override would pass while the screen it produces refuses every
            // save. That is the exact shape this codebase keeps finding: a check
            // that silently checks nothing.
            $spec = array_merge((array) ($shipped['fields'][$name] ?? []), (array) $spec);

            if (($spec['required'] ?? false) && ($spec['hidden'] ?? false)) {
                // Caught here rather than at save time on the row, where it would
                // read as a mysterious refusal nobody can act on: the field
                // demanding a value is not on the screen.
                $problems[] = sprintf(
                    'Field "%s" cannot be both required and hidden — every save would be refused for '
                    . 'a value nobody can see a box to type into.',
                    (string) $name,
                );
            }
        }

        return $problems;
    }
}
