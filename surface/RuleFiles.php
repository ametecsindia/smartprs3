<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * GENERAL SETTINGS — every rule file the product ships, and what this tenant
 * has customised over it.
 *
 * SmartPRS 2's Settings screen was a form of assorted switches. Here every
 * rule is a file under config/, and a tenant's change to one is a versioned
 * row in module_customisations keyed by the file's `customisation_key` (or a
 * module's key). This lists the files with that status so an administrator can
 * see, in one place, which rules are as shipped and which were changed, by
 * whom, and when — and go to the screen that changes them.
 */
final class RuleFiles
{
    public function __construct(private readonly string $directory, private readonly Customisation $customisation)
    {
    }

    /**
     * @return list<array{file: string, note: string, key: string|null, customised: bool, version: int, changed_by: string, at: string}>
     */
    public function inventory(): array
    {
        $live = $this->customisation->live();
        $out = [];

        foreach (glob($this->directory . '/*.json') ?: [] as $path) {
            $data = json_decode((string) file_get_contents($path), true);
            $data = is_array($data) ? $data : [];
            $name = basename($path, '.json');
            $key = isset($data['customisation_key']) ? (string) $data['customisation_key'] : null;
            $latest = $key === null ? null : ($this->customisation->history($key)[0] ?? null);

            $out[] = [
                'file' => $name,
                'note' => trim((string) ($data['note'] ?? $data['description'] ?? '')),
                'key' => $key,
                'customised' => $key !== null && isset($live[$key]),
                'version' => (int) ($latest['version'] ?? 0),
                'changed_by' => (string) ($latest['changed_by'] ?? ''),
                'at' => (string) ($latest['created_at'] ?? ''),
            ];
        }

        usort($out, static fn (array $a, array $b): int => [$b['customised'], $a['file']] <=> [$a['customised'], $b['file']]);

        return $out;
    }
}
