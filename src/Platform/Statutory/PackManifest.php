<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Statutory;

/**
 * THE SIGNED HEADER — Part XVII §XVII.4.2.
 *
 * Validation here is mechanical on purpose. R-XVII-028 says the signing service
 * refuses a pack missing any `verification.*` field or carrying a value with no
 * `source_reference`, and "the signing gate is where the boxed rule becomes
 * mechanical" — an editorial obligation that depends on somebody remembering is
 * an obligation that holds until the week it matters.
 *
 * SmartPRS 2 carries NO source citation on any of its ~90 statutory values. Not
 * a stale one: none. So the field cannot be optional here, and it cannot have a
 * default, because a default citation is a citation to nothing that reads like a
 * citation to something.
 */
final readonly class PackManifest
{
    public const SCHEMA_VERSION = 1;

    /** @param array<string, mixed> $raw */
    private function __construct(public array $raw)
    {
    }

    /**
     * @param array<string, mixed> $raw
     * @throws PackRejected listing EVERY problem, not the first
     */
    public static function parse(array $raw): self
    {
        $problems = [];
        $schema = $raw['manifest_schema_version'] ?? null;

        if ($schema !== self::SCHEMA_VERSION) {
            // "The platform refuses unknown" — a manifest from a future format
            // half-understood is worse than one not read at all.
            throw new PackRejected(['manifest_schema_version is ' . var_export($schema, true)
                . ', this build knows only ' . self::SCHEMA_VERSION
                . '. A pack a build cannot fully read must not be half-applied.']);
        }

        foreach ([
            'pack.pack_key', 'pack.jurisdiction_code', 'pack.statute_code', 'pack.pack_version',
            'pack.title', 'pack.publisher',
            'validity.effective_from', 'validity.announced_at', 'validity.published_at',
            'compatibility.min_platform_version',
            'verification.verified_by', 'verification.verified_role', 'verification.verified_on',
            'verification.reviewed_by', 'verification.reviewed_role', 'verification.reviewed_on',
            'verification.evidence_document_sha256',
            'integrity.canonicalisation', 'integrity.content_hash_alg', 'integrity.content_hash',
            'integrity.signature_alg', 'integrity.signature', 'integrity.public_key_id',
            'integrity.countersignature', 'integrity.countersign_key_id',
            'content_summary.change_note',
        ] as $path) {
            if (trim((string) (self::at($raw, $path) ?? '')) === '') {
                $problems[] = "{$path} is missing or empty";
            }
        }

        // At least one primary source, each fully attributed. This is the field
        // whose absence in SmartPRS 2 is the whole reason for this part.
        $sources = self::at($raw, 'verification.primary_sources');

        if (!is_array($sources) || $sources === []) {
            $problems[] = 'verification.primary_sources is empty — a statutory value whose authority is not '
                . 'stated is a number somebody typed';
        } else {
            foreach ($sources as $i => $source) {
                foreach (['authority', 'reference', 'dated', 'retrieved_on', 'document_sha256'] as $field) {
                    if (trim((string) ($source[$field] ?? '')) === '') {
                        $problems[] = "verification.primary_sources[{$i}].{$field} is missing — an authority "
                            . 'survives its URL rotting only if the instrument itself is identified and hashed';
                    }
                }
            }
        }

        if (self::at($raw, 'integrity.canonicalisation') !== 'JCS/RFC-8785') {
            $problems[] = 'integrity.canonicalisation must be JCS/RFC-8785; a signature over any other '
                . 'serialisation is a signature over a formatting choice';
        }

        if (self::at($raw, 'integrity.signature_alg') !== 'Ed25519') {
            $problems[] = 'integrity.signature_alg must be Ed25519';
        }

        if (self::at($raw, 'integrity.content_hash_alg') !== 'SHA-256') {
            $problems[] = 'integrity.content_hash_alg must be SHA-256';
        }

        $statutes = ['epf', 'esi', 'pt', 'lwf', 'tds', 'bonus', 'gratuity', 'minimum_wages',
            'maternity', 'leave_statutory'];

        if (!in_array(self::at($raw, 'pack.statute_code'), $statutes, true)) {
            $problems[] = 'pack.statute_code ' . var_export(self::at($raw, 'pack.statute_code'), true)
                . ' is not one of: ' . implode(', ', $statutes);
        }

        if (preg_match('/^IN(-[A-Z]{2})?$/', (string) self::at($raw, 'pack.jurisdiction_code')) !== 1) {
            $problems[] = 'pack.jurisdiction_code must be IN or an ISO 3166-2:IN subdivision (§XVII.4.4)';
        }

        if ($problems !== []) {
            throw new PackRejected($problems);
        }

        return new self($raw);
    }

    public function packKey(): string
    {
        return (string) self::at($this->raw, 'pack.pack_key');
    }

    public function version(): string
    {
        return (string) self::at($this->raw, 'pack.pack_version');
    }

    public function statute(): string
    {
        return (string) self::at($this->raw, 'pack.statute_code');
    }

    public function jurisdiction(): string
    {
        return (string) self::at($this->raw, 'pack.jurisdiction_code');
    }

    public function effectiveFrom(): \DateTimeImmutable
    {
        return new \DateTimeImmutable((string) self::at($this->raw, 'validity.effective_from'));
    }

    public function effectiveTo(): ?\DateTimeImmutable
    {
        $to = self::at($this->raw, 'validity.effective_to');

        return $to === null || $to === '' ? null : new \DateTimeImmutable((string) $to);
    }

    public function contentHash(): string
    {
        return (string) self::at($this->raw, 'integrity.content_hash');
    }

    public function signature(): string
    {
        return (string) self::at($this->raw, 'integrity.signature');
    }

    public function countersignature(): string
    {
        return (string) self::at($this->raw, 'integrity.countersignature');
    }

    public function keyId(): string
    {
        return (string) self::at($this->raw, 'integrity.public_key_id');
    }

    public function countersignKeyId(): string
    {
        return (string) self::at($this->raw, 'integrity.countersign_key_id');
    }

    public function minPlatformVersion(): string
    {
        return (string) self::at($this->raw, 'compatibility.min_platform_version');
    }

    public function maxPlatformVersion(): ?string
    {
        $max = self::at($this->raw, 'compatibility.max_platform_version');

        return $max === null || $max === '' ? null : (string) $max;
    }

    /** @return list<string> */
    public function requiredComponentFlags(): array
    {
        return array_values((array) (self::at($this->raw, 'compatibility.requires_component_flags') ?? []));
    }

    /** @return list<string> */
    public function requiredSymbols(): array
    {
        return array_values((array) (self::at($this->raw, 'compatibility.requires_symbols') ?? []));
    }

    public function verifiedBy(): string
    {
        return (string) self::at($this->raw, 'verification.verified_by');
    }

    public function changeNote(): string
    {
        return (string) self::at($this->raw, 'content_summary.change_note');
    }

    /**
     * Is this pack a SYNTHETIC one — machinery evidence rather than law?
     *
     * A synthetic pack declares itself. It is not detected by heuristic and not
     * inferred from a missing field, because the failure mode being prevented is
     * a test fixture reaching a customer looking like content: whatever else is
     * true of a pack that says this, nobody can mistake it for the law.
     */
    public function isSynthetic(): bool
    {
        return (bool) (self::at($this->raw, 'verification.synthetic') ?? false);
    }

    /** The manifest as signed: everything except the two signatures themselves. */
    public function signablePart(): array
    {
        $raw = $this->raw;
        unset($raw['integrity']['signature'], $raw['integrity']['countersignature']);

        return $raw;
    }

    private static function at(array $raw, string $path): mixed
    {
        $cursor = $raw;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }
}
