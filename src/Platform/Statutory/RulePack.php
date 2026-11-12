<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Statutory;

/**
 * ONE STATUTE, ONE JURISDICTION, ONE VERSION — Part XVII §XVII.4.1.
 *
 * Not "all of Indian payroll law in one file", for the four reasons the
 * blueprint gives, of which two are operational and decide the shape of this
 * class: states amend independently (a Telangana PT notification must not make a
 * Karnataka-only customer re-accept anything), and blast radius (a defective
 * pack must be withdrawable without withdrawing unrelated statutes).
 *
 * IMMUTABLE ONCE SIGNED (R-XVII-007). There is no setter here and no method that
 * returns a modified copy. A correction is a new `pack_version` that supersedes,
 * which is the same rule spine S4 applies to every configurable artefact: nothing
 * is overwritten, because the payslip issued last March was computed against what
 * the pack said last March.
 */
final readonly class RulePack
{
    /** @param array<string, mixed> $content */
    private function __construct(public PackManifest $manifest, public array $content)
    {
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $content
     * @throws PackRejected
     */
    public static function of(array $manifest, array $content): self
    {
        $parsed = PackManifest::parse($manifest);
        $problems = [];

        foreach ((array) ($content['rules'] ?? []) as $i => $raw) {
            try {
                StatutoryRule::fromArray((array) $raw);
            } catch (PackRejected $e) {
                foreach ($e->reasons as $reason) {
                    $problems[] = "rules[{$i}]: {$reason}";
                }
            }
        }

        if (($content['rules'] ?? []) === []) {
            $problems[] = 'the pack declares no rules — an empty pack activates successfully and computes '
                . 'nothing, which is the failure mode hardest to notice';
        }

        // Every slab value carries its own authority too. A band table is where
        // SmartPRS 2's PT went wrong: a typo of the form [[15000]] silently
        // zeroes a state, and nothing in the file says who typed it.
        foreach ((array) ($content['slab_tables'] ?? []) as $code => $table) {
            if (trim((string) ($table['source_reference'] ?? '')) === '') {
                $problems[] = "slab_tables.{$code} has no source_reference";
            }

            if (($table['bands'] ?? []) === []) {
                $problems[] = "slab_tables.{$code} has no bands — an empty band table resolves every wage to "
                    . 'nothing and reports success, which is DEFECT-F-24 with a different typo';
            }
        }

        if ($problems !== []) {
            throw new PackRejected($problems, $parsed->packKey());
        }

        return new self($parsed, $content);
    }

    /** @return list<StatutoryRule> */
    public function rules(): array
    {
        return array_map(
            static fn (array $raw): StatutoryRule => StatutoryRule::fromArray($raw),
            array_values((array) ($this->content['rules'] ?? [])),
        );
    }

    public function rule(string $code): ?StatutoryRule
    {
        foreach ($this->rules() as $rule) {
            if ($rule->code === $code) {
                return $rule;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function goldenCases(): array
    {
        return array_values((array) ($this->content['golden_cases'] ?? []));
    }

    /** @return array<string, array{bands: list<array{from: int, rate: string}>, flat?: bool}> */
    public function slabTables(): array
    {
        $tables = [];

        foreach ((array) ($this->content['slab_tables'] ?? []) as $code => $table) {
            $tables[(string) $code] = ['bands' => array_values((array) $table['bands'])]
                + (isset($table['flat']) ? ['flat' => (bool) $table['flat']] : []);
        }

        return $tables;
    }

    /** The hash the manifest must agree with, computed from the content itself. */
    public function computedContentHash(): string
    {
        return Jcs::hash($this->content);
    }

    public function id(): string
    {
        return $this->manifest->packKey() . '@' . $this->manifest->version();
    }
}
