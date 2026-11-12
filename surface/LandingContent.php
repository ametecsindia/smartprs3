<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * THE PUBLIC LANDING PAGE'S WORDS — config/landing.json is the shipped default; the Super Admin's
 * edits (SaaS Platform → Landing Page (CMS), SmartPRS 2's /admin/landing) are kept in
 * platform_settings 'landing' and win over it. Reset = forget the edits.
 *
 * The shipped file is also the SHAPE: an edit can change any text, add or remove rows of a list
 * (stats, features, plans, clients), but cannot invent keys the page does not print.
 */
final class LandingContent
{
    public function __construct(private readonly string $defaultsFile, private readonly ?PlatformSettings $settings)
    {
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        $c = json_decode((string) @file_get_contents($this->defaultsFile), true);

        return is_array($c) ? $c : [];
    }

    /** @return array<string, mixed> */
    public function content(): array
    {
        $saved = $this->settings?->get('landing') ?? [];

        return array_replace($this->defaults(), $saved);
    }

    public function edited(): bool
    {
        return ($this->settings?->get('landing') ?? []) !== [];
    }

    /** @param array<string, mixed> $posted the form's content[...] tree */
    public function save(array $posted): void
    {
        $this->settings?->put('landing', self::coerce($this->defaults(), $posted));
    }

    public function reset(): void
    {
        $this->settings?->put('landing', []);
    }

    /** Posted values shaped like the shipped content: strings stay strings, lists drop blank rows. */
    private static function coerce(mixed $shape, mixed $posted): mixed
    {
        if (is_array($shape) && array_is_list($shape)) {
            $rowShape = $shape[0] ?? '';
            $out = [];

            foreach (is_array($posted) ? $posted : [] as $item) {
                $row = self::coerce($rowShape, $item);

                if (implode('', (array) $row) !== '') {
                    $out[] = $row;
                }
            }

            return $out;
        }

        if (is_array($shape)) {
            $out = [];

            foreach ($shape as $k => $sub) {
                $out[$k] = self::coerce($sub, is_array($posted) ? ($posted[$k] ?? null) : null);
            }

            return $out;
        }

        return str_replace("\r\n", "\n", trim(is_scalar($posted) ? (string) $posted : ''));
    }
}
