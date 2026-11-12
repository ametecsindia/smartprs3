<?php

declare(strict_types=1);

namespace SmartPRS\Platform\ModulePack;

/**
 * A VERTICAL, SHIPPED AS CONFIGURATION — Part XXXII Phase 7.
 *
 * The exit criterion is one clause: *the module-pack mechanism installs the
 * collections pack into a clean tenant and **the pack contains no PHP***.
 *
 * That last clause is the whole design. A module pack is compliance item types,
 * workflows, rules, report definitions, field definitions and vocabularies — all
 * DATA. The moment a pack can carry code, three things follow at once: a vertical
 * becomes a deployment, a customer's pack can do anything the application can do,
 * and "change configuration, not code" becomes a slogan with an exception in it.
 *
 * So the ban is enforced structurally rather than by review, in two directions:
 * a pack is refused if any value looks like code, and refused if it declares a
 * file extension the loader would execute. Both checks fire before anything is
 * installed, because a partially installed pack is worse than a rejected one.
 */
final readonly class ModulePack
{
    private const CODE_MARKERS = ['<?php', '<?=', 'eval(', 'function ', 'require ', 'include ',
        'shell_exec', 'system(', 'passthru', 'base64_decode', '__halt_compiler'];

    /** @param array<string, mixed> $content */
    private function __construct(
        public string $key,
        public string $version,
        public string $title,
        public array $content,
    ) {
    }

    /**
     * @param array<string, mixed> $declared
     * @throws ModulePackRejected listing every reason
     */
    public static function of(array $declared): self
    {
        $problems = [];

        foreach (['pack_key', 'pack_version', 'title'] as $field) {
            if (trim((string) ($declared[$field] ?? '')) === '') {
                $problems[] = "{$field} is missing";
            }
        }

        $content = (array) ($declared['content'] ?? []);

        if ($content === []) {
            $problems[] = 'the pack declares no content, so installing it changes nothing and reports success';
        }

        self::scanForCode($content, '', $problems);

        foreach ((array) ($declared['files'] ?? []) as $file) {
            if (preg_match('/\.(php|phtml|phar|inc)$/i', (string) $file) === 1) {
                $problems[] = "the pack ships '{$file}', which the loader would execute. A module pack is "
                    . 'configuration; a pack that can carry code can do anything the application can do';
            }
        }

        if ($problems !== []) {
            throw new ModulePackRejected($problems, (string) ($declared['pack_key'] ?? '(unidentified)'));
        }

        return new self(
            (string) $declared['pack_key'],
            (string) $declared['pack_version'],
            (string) $declared['title'],
            $content,
        );
    }

    /** @return list<array<string, mixed>> */
    public function section(string $name): array
    {
        return array_values((array) ($this->content[$name] ?? []));
    }

    /** @return list<string> */
    public function sections(): array
    {
        return array_keys($this->content);
    }

    /** @param list<string> $problems */
    private static function scanForCode(mixed $value, string $path, array &$problems): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                self::scanForCode($child, $path === '' ? (string) $key : "{$path}.{$key}", $problems);
            }

            return;
        }

        if (!is_string($value)) {
            return;
        }

        foreach (self::CODE_MARKERS as $marker) {
            if (str_contains($value, $marker)) {
                $problems[] = "content at '{$path}' contains '{$marker}'. A module pack carries "
                    . 'declarations — item types, workflows, rules, reports, vocabularies — and never code. '
                    . 'SPX expressions are the one executable thing in a pack, and SPX is not '
                    . 'Turing-complete, has no I/O and cannot reach the filesystem';
            }
        }
    }
}
