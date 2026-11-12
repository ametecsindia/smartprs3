<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * A SMALL OWNER-EDITABLE LIST OF {id, label} OPTIONS — versioned through
 * Customisation like everything else configurable, never a hardcoded array in
 * a screen. `id` is a stable slug minted once from the label and kept even if
 * the label is later renamed, so anything that already points at the id (a
 * saved ID card format scoped to it, a past visitor_log row's type_id) never
 * silently repoints at a different option.
 *
 * For a genuinely small, rarely-changed list an owner grows over months, not
 * per-transaction data — that belongs in a real table (see visitor_log).
 *
 * Used for: ID Card Format's external card types (Visitor is always built
 * in; Client, Guest, ... are the owner's own), and Visit Purpose (Interview,
 * Maintenance, ...).
 */
final class NamedList
{
    /**
     * @param list<array{id: string, label: string}> $seed the shipped defaults,
     *   used until the owner saves their own list (a save of just one item is
     *   still "their own list", same convention as LeavePolicy)
     */
    public function __construct(
        private readonly Customisation $customisation,
        private readonly string $key,
        private readonly array $seed = [],
    ) {
    }

    /** @return list<array{id: string, label: string}> */
    public function all(): array
    {
        $saved = $this->customisation->live()[$this->key] ?? null;

        if (!is_array($saved) || !is_array($saved['items'] ?? null)) {
            return $this->seed;
        }

        $out = [];

        foreach ($saved['items'] as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            $label = trim((string) ($row['label'] ?? ''));

            if ($id === '' || $label === '') {
                continue;
            }

            $out[] = ['id' => $id, 'label' => $label];
        }

        return $out === [] ? $this->seed : $out;
    }

    public function label(string $id): string
    {
        foreach ($this->all() as $row) {
            if ($row['id'] === $id) {
                return $row['label'];
            }
        }

        return '';
    }

    /**
     * @param list<array{id?: string, label: string}> $items new or renamed rows,
     *   in the order they should show; a blank label drops the row
     * @return array{ok: bool, why: string, version?: int}
     */
    public function save(array $items, string $byId, string $byLabel): array
    {
        $out = [];
        $seen = [];

        foreach ($items as $item) {
            $label = mb_substr(trim((string) ($item['label'] ?? '')), 0, 80);

            if ($label === '') {
                continue;
            }

            $id = trim((string) ($item['id'] ?? ''));

            if ($id === '' || isset($seen[$id])) {
                $id = self::slugFor($label, $seen);
            }

            $seen[$id] = true;
            $out[] = ['id' => $id, 'label' => $label];
        }

        if ($out === []) {
            return ['ok' => false, 'why' => 'Keep at least one option — an empty list would leave nothing for the dropdown to offer.'];
        }

        return $this->customisation->saveRaw($this->key, ['items' => $out], $byId, $byLabel, $this->key);
    }

    /** @param array<string, bool> $seen ids already taken in this save */
    private static function slugFor(string $label, array $seen): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($label)), '-');
        $base = $base === '' ? 'item-' . substr(md5($label), 0, 6) : $base;
        $id = $base;
        $n = 2;

        while (isset($seen[$id])) {
            $id = $base . '-' . $n;
            $n++;
        }

        return $id;
    }
}
