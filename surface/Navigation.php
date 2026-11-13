<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * THE SIDEBAR IS A DECLARATION.
 *
 * SmartPRS 2's menu lives in `layouts/app.blade.php` — fifteen anchors with their
 * icons and routes written out, wrapped in `@if ($u->isSuperAdmin())`. A customer
 * who wants Leave above Attendance, their own word for Employees, or the Payroll
 * section hidden from branch managers needs a developer and a release. That is
 * the same bargain as the module registry, and it is refused here for the same
 * reason: the menu is `config/navigation.json`, laid over by whatever this
 * customer has changed.
 *
 * AN ITEM NOBODY MAY OPEN IS MARKED, NOT REMOVED. A menu that silently shortens
 * itself teaches people the product is smaller than it is, and it hides the fact
 * that a permission is missing behind the appearance of a feature that was never
 * built. The screens themselves refuse; the menu is honest about why.
 *
 * A ROUTE THE APPLICATION DOES NOT ANSWER IS REPORTED. A customised menu pointing
 * at a route that no longer exists is a dead link somebody added in good faith,
 * and a dead link in a sidebar reads as a broken product rather than a wrong
 * setting.
 *
 * ponytail: one level of nesting, no drag-and-drop reorder. Order is the order in
 * the JSON, which is what an administrator editing a list expects.
 */
final class Navigation
{
    private const PREFIX = 'nav:';

    /** What a customer may change about a section or an item. */
    public const SECTION_KEYS = ['label', 'hidden', 'order'];
    public const ITEM_KEYS = ['label', 'icon', 'hidden', 'order'];

    /** @var list<array<string, mixed>>|null */
    private ?array $sections = null;

    public function __construct(
        private readonly string $file,
        private readonly ?Customisation $customisation = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function sections(): array
    {
        if ($this->sections !== null) {
            return $this->sections;
        }

        $decoded = is_file($this->file)
            ? json_decode((string) file_get_contents($this->file), true)
            : null;

        if (!is_array($decoded) || !is_array($decoded['sections'] ?? null)) {
            throw new \RuntimeException(
                "The navigation registry at {$this->file} is missing or unreadable. Refused rather "
                . 'than treated as "no menu", which would render an application with an empty '
                . 'sidebar and no indication that anything was wrong.'
            );
        }

        $override = $this->customisation?->live()[self::PREFIX . 'sidebar'] ?? [];

        return $this->sections = self::overlay(self::withDropIns($decoded['sections'], $this->file), $override);
    }

    /**
     * DROP-IN MENU ITEMS: config/navigation.d/<section>.json holds
     * {"section": "<key>", "items": [...]} (or a list of those); the items are
     * appended to that section, and a section key nobody declared is refused
     * loudly. A module ships its sidebar entries beside its declarations.
     *
     * @param list<array<string, mixed>> $sections
     * @return list<array<string, mixed>>
     */
    public static function withDropIns(array $sections, string $file): array
    {
        $dir = dirname($file) . '/' . pathinfo($file, PATHINFO_FILENAME) . '.d';

        foreach (glob($dir . '/*.json') ?: [] as $dropIn) {
            $decoded = json_decode((string) file_get_contents($dropIn), true);
            $blocks = is_array($decoded) && isset($decoded['section']) ? [$decoded] : (array) $decoded;

            foreach ($blocks as $block) {
                $key = (string) ($block['section'] ?? '');
                $placed = false;

                foreach ($sections as $i => $section) {
                    if ((string) ($section['key'] ?? '') === $key) {
                        $have = array_column((array) ($section['items'] ?? []), 'key');

                        foreach ((array) ($block['items'] ?? []) as $item) {
                            in_array((string) ($item['key'] ?? ''), $have, true) || $sections[$i]['items'][] = $item;
                        }

                        $placed = true;
                    }
                }

                if (!$placed) {
                    throw new \RuntimeException("The menu drop-in {$dropIn} names a section '{$key}' the shipped menu does not have.");
                }
            }
        }

        return $sections;
    }

    /**
     * Lay a customer's menu changes over the shipped one.
     *
     * @param list<array<string, mixed>> $sections
     */
    public static function overlay(array $sections, array $override): array
    {
        if ($override === []) {
            return $sections;
        }

        foreach ($sections as $i => $section) {
            $change = array_intersect_key(
                (array) ($override['sections'][(string) $section['key']] ?? []),
                array_flip(self::SECTION_KEYS),
            );
            $sections[$i] = array_merge($section, $change);

            foreach ((array) $section['items'] as $j => $item) {
                $sections[$i]['items'][$j] = array_merge($item, array_intersect_key(
                    (array) ($override['items'][(string) $item['key']] ?? []),
                    array_flip(self::ITEM_KEYS),
                ));
            }

            usort($sections[$i]['items'], static fn (array $a, array $b): int
                => ($a['order'] ?? 500) <=> ($b['order'] ?? 500));
        }

        usort($sections, static fn (array $a, array $b): int
            => ($a['order'] ?? 500) <=> ($b['order'] ?? 500));

        return $sections;
    }

    /**
     * The menu as it should be drawn for one person.
     *
     * @param callable(string): bool $may
     * @param array<string, int> $badges live counts, by badge key
     * @param array<string, bool> $flags facts about the viewer an item's
     *     `show_if` names (e.g. `leave_approver`: holds leave decisions or has
     *     people reporting to them). Such an item is LEFT OUT, not locked, for
     *     anyone the fact is not true of — a menu entry for a queue that can
     *     never hold anything of yours is noise, not a permission to explain.
     * @return list<array<string, mixed>>
     */
    public function forViewer(callable $may, array $badges = [], array $flags = []): array
    {
        $out = [];

        foreach ($this->sections() as $section) {
            if ($section['hidden'] ?? false) {
                continue;
            }

            $items = [];

            foreach ((array) $section['items'] as $item) {
                if (($item['hidden'] ?? false) || (isset($item['show_if']) && !($flags[(string) $item['show_if']] ?? false))) {
                    continue;
                }

                $permission = (string) ($item['permission'] ?? '');
                $badge = (string) ($item['badge'] ?? '');

                $items[] = $item + [
                    // MARKED, not removed. The screen refuses; the menu says why.
                    // ALSO (owner, 13 Nov 2026): a screen that also needs these codes — HR letters need issuing rights, not just View.
                    'allowed' => ($permission === '' || $may($permission))
                        && array_reduce((array) ($item['also'] ?? []), static fn (bool $c, $p): bool => $c && $may((string) $p), true),
                    'count' => $badge === '' ? null : ($badges[$badge] ?? null),
                    // A MODULE ITEM opens the module engine's screen. It is one
                    // sidebar entry like any other; only where it points differs.
                    'href' => isset($item['module'])
                        ? '?r=m&k=' . rawurlencode((string) $item['module'])
                        : '?r=' . rawurlencode((string) ($item['route'] ?? '')),
                ];
            }

            // A SECTION WITH NOTHING IN IT YET IS STILL A SECTION. The owner
            // dictates the sub-menus section by section; until a section has
            // its items it is shown with a note, so the shape of the product is
            // visible before its contents are.
            if ($items !== [] || ($section['keep_when_empty'] ?? false)) {
                $out[] = ['key' => $section['key'], 'label' => $section['label'],
                    'icon' => (string) ($section['icon'] ?? ''), 'items' => $items,
                    'platform_only' => (bool) ($section['platform_only'] ?? false),
                    'empty_note' => (string) ($section['empty_note'] ?? '')];
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function history(): array
    {
        return $this->customisation?->history(self::PREFIX . 'sidebar') ?? [];
    }

    /**
     * @param array<string, mixed> $override
     * @param list<string> $routes the routes the application actually answers
     * @return array{ok: bool, why: string, version?: int}
     */
    public function customise(array $override, array $routes, string $byId, string $byLabel,
        string $note = ''): array
    {
        if ($this->customisation === null) {
            return ['ok' => false, 'why' => 'Customisation storage is not available here.'];
        }

        $decoded = json_decode((string) file_get_contents($this->file), true);
        $problems = self::problems($override, self::withDropIns((array) $decoded['sections'], $this->file), $routes);

        if ($problems !== []) {
            return ['ok' => false, 'why' => implode(' ', $problems)];
        }

        return $this->customisation->saveRaw(self::PREFIX . 'sidebar', $override, $byId, $byLabel,
            $note);
    }

    /**
     * What is wrong with a proposed menu.
     *
     * @param list<array<string, mixed>> $shipped
     * @param list<string> $routes
     * @return list<string>
     */
    public static function problems(array $override, array $shipped, array $routes): array
    {
        $problems = [];
        $sectionKeys = [];
        $itemKeys = [];

        foreach ($shipped as $section) {
            $sectionKeys[] = (string) $section['key'];

            foreach ((array) $section['items'] as $item) {
                $itemKeys[] = (string) $item['key'];
            }
        }

        foreach (array_keys($override) as $key) {
            if (!in_array((string) $key, ['sections', 'items'], true)) {
                $problems[] = sprintf('"%s" is not part of a menu.', (string) $key);
            }
        }

        foreach ((array) ($override['sections'] ?? []) as $key => $change) {
            if (!in_array((string) $key, $sectionKeys, true)) {
                $problems[] = sprintf('There is no menu section "%s".', (string) $key);
            }

            foreach (array_keys((array) $change) as $field) {
                if (!in_array((string) $field, self::SECTION_KEYS, true)) {
                    $problems[] = sprintf('"%s" cannot be set on a section — the permission an item '
                        . 'sits behind is not a menu setting, it is the lock on the screen.',
                        (string) $field);
                }
            }
        }

        foreach ((array) ($override['items'] ?? []) as $key => $change) {
            if (!in_array((string) $key, $itemKeys, true)) {
                $problems[] = sprintf('There is no menu item "%s".', (string) $key);
            }

            foreach (array_keys((array) $change) as $field) {
                if (!in_array((string) $field, self::ITEM_KEYS, true)) {
                    $problems[] = sprintf(
                        '"%s" cannot be set on a menu item. Hiding an item from the menu is a '
                        . 'convenience; the permission in front of the screen is the control, and a '
                        . 'menu that could change it would be a lock with its key taped to it.',
                        (string) $field);
                }
            }

            if (trim((string) ($change['label'] ?? 'x')) === '') {
                $problems[] = sprintf('Menu item "%s" cannot be nameless.', (string) $key);
            }
        }

        // EVERY SHIPPED ROUTE MUST STILL BE ANSWERED. Checked against the routes
        // the application really has, not a list somebody maintains beside it.
        foreach ($shipped as $section) {
            if ($section['hidden'] ?? false) {
                // A hidden section opens nothing; a dead route behind it is not a
                // link anybody can click, so it is not the dead link this check exists for.
                continue;
            }

            foreach ((array) $section['items'] as $item) {
                if (isset($item['module'])) {
                    // A module item is answered by ?r=m; whether the module exists is
                    // the module engine's question, asked in navigation_test.php.
                    continue;
                }

                if ($item['hidden'] ?? false) {
                    // MARKED, not removed (see forViewer()): a hidden item is not in
                    // the sidebar, so a route it points at that nothing yet answers is
                    // not a dead link somebody can follow — it is a screen not built
                    // yet, already declared and waiting, not a broken product today.
                    continue;
                }

                if ($routes !== [] && !in_array((string) $item['route'], $routes, true)) {
                    $problems[] = sprintf(
                        'The menu points at "%s", which this application does not answer. A dead '
                        . 'link in a sidebar reads as a broken product rather than a wrong setting.',
                        (string) $item['route']);
                }
            }
        }

        return $problems;
    }
}
