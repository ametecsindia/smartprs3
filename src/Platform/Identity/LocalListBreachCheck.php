<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Identity;

/**
 * A breach check over a corpus held locally: the on-premise and air-gapped
 * answer, and the one that makes the mechanism testable without egress.
 *
 * The corpus is indexed by k-anonymity prefix at construction, so the lookup is
 * a hash lookup and the class shares `isBreached()` — and therefore the
 * property that the implementation never sees a plaintext candidate — with the
 * range-API implementation it stands in for.
 *
 * Two input shapes, because the two real corpora have two shapes:
 *
 *  - `fromPasswords()` takes plaintext passwords, for a small curated list and
 *    for tests. It hashes them here; the plaintexts are not retained.
 *  - `fromSha1Digests()` takes uppercase SHA-1 hex digests, which is what a
 *    downloaded haveibeenpwned corpus actually is. This is the production path
 *    for an on-premise installation: no plaintext ever exists.
 *
 * ponytail: the index is an in-memory array. A full pwned-passwords corpus is
 * ~850 million digests and does not belong in PHP memory — that deployment
 * points `unavailable()` at a MariaDB table or a sorted-file bisect and
 * overrides `suffixesFor()`. The interesting behaviour (the prefix split, the
 * constant-time suffix compare, the outage path) is identical either way, which
 * is why testing it here tests it there.
 */
final class LocalListBreachCheck extends BreachCheck
{
    /** @param array<string, list<string>> $index prefix => suffixes */
    private function __construct(
        private readonly array $index,
        private readonly bool $available,
    ) {
    }

    /** @param iterable<string> $passwords plaintext; hashed here and discarded */
    public static function fromPasswords(iterable $passwords): self
    {
        $digests = [];

        foreach ($passwords as $password) {
            $digests[] = strtoupper(sha1($password));
        }

        return self::fromSha1Digests($digests);
    }

    /** @param iterable<string> $digests 40-character SHA-1 hex, any case */
    public static function fromSha1Digests(iterable $digests): self
    {
        $index = [];

        foreach ($digests as $digest) {
            $digest = strtoupper(trim($digest));

            if (strlen($digest) !== 40 || preg_match('/^[0-9A-F]{40}$/', $digest) !== 1) {
                throw new \InvalidArgumentException(
                    "'{$digest}' is not a SHA-1 hex digest. A breach corpus that is "
                    . 'silently half-loaded is worse than none, so this refuses rather '
                    . 'than skipping the row.'
                );
            }

            $index[substr($digest, 0, self::PREFIX_LENGTH)][] = substr($digest, self::PREFIX_LENGTH);
        }

        return new self($index, true);
    }

    /**
     * A corpus that is configured but not reachable — the outage case, so the
     * fail-closed path of R-XXII-184 is exercisable in a test rather than only
     * in an incident.
     */
    public static function unavailable(): self
    {
        return new self([], false);
    }

    /** @return iterable<string> */
    protected function suffixesFor(string $sha1Prefix5): iterable
    {
        if (!$this->available) {
            throw new BreachCheckUnavailable(
                'The breach corpus could not be consulted. R-XXII-184: this refuses the '
                . 'password change with a retry-later; it never reads as "not breached".'
            );
        }

        return $this->index[$sha1Prefix5] ?? [];
    }
}
