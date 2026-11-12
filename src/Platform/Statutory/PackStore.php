<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Statutory;

/**
 * THE INSTALLED PACKS — Part XVII R-XVII-007, R-XVII-026, R-XVII-120.
 *
 * Three properties, each of which is a refusal rather than a convention:
 *
 *   A SIGNED VERSION IS NEVER WRITTEN TWICE. `put()` on an existing version
 *   throws. Content is not edited in place; a correction is a new version that
 *   supersedes. Allowing the overwrite would mean a payslip issued in March
 *   cannot be re-derived in 2031, because the pack it names now holds different
 *   numbers under the same version.
 *
 *   THE CONTENT HASH IS RE-VERIFIED ON EVERY READ. Not on import only. A pack
 *   verified once and cached forever is a pack whose file can be edited after
 *   the check, and on an on-premise install that file sits on a disk somebody
 *   has a shell on.
 *
 *   NOTHING IS EVER DELETED. A withdrawn pack is MARKED withdrawn and stays
 *   readable, because a withdrawal that erases the version makes every payslip
 *   computed under it unexplainable — turning a bad pack into an audit failure
 *   across every payslip it ever touched.
 */
final class PackStore
{
    /** @var array<string, RulePack> */
    private array $packs = [];

    /** @var array<string, string> pack id => why it was withdrawn */
    private array $withdrawn = [];

    public function __construct(private readonly PackVerifier $verifier)
    {
    }

    /** @throws PackRejected */
    public function put(RulePack $pack): void
    {
        if (isset($this->packs[$pack->id()])) {
            throw new PackRejected(
                ['version ' . $pack->manifest->version() . ' is already installed and a signed version is '
                    . 'immutable (R-XVII-007). A correction is a new version that supersedes — overwriting '
                    . 'this one would silently change what a payslip already issued under it says it used'],
                $pack->manifest->packKey(),
            );
        }

        $this->verifier->verify($pack);
        $this->packs[$pack->id()] = $pack;
    }

    /** @throws PackRejected when the stored bytes no longer hash to what was verified */
    public function get(string $packKey, string $version): ?RulePack
    {
        $pack = $this->packs[$packKey . '@' . $version] ?? null;

        if ($pack !== null) {
            $this->verifier->verifyContentHash($pack);
        }

        return $pack;
    }

    /**
     * The version in force for a date — spine S4 semantics, applied to law.
     *
     * A pack published in September that takes effect on 1 October must not
     * affect September payroll, so resolution is by `effective_from`, never by
     * `published_at` and never by "the latest one installed".
     */
    public function resolve(string $packKey, \DateTimeImmutable $asOf): ?RulePack
    {
        $best = null;

        foreach ($this->packs as $pack) {
            if ($pack->manifest->packKey() !== $packKey) {
                continue;
            }

            $from = $pack->manifest->effectiveFrom();
            $to = $pack->manifest->effectiveTo();

            if ($from > $asOf || ($to !== null && $to < $asOf)) {
                continue;
            }

            if ($best === null || $from > $best->manifest->effectiveFrom()) {
                $best = $pack;
            }
        }

        if ($best !== null) {
            $this->verifier->verifyContentHash($best);
        }

        return $best;
    }

    public function withdraw(string $packKey, string $version, string $reason): void
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A pack withdrawn for no stated reason cannot be reviewed.');
        }

        $this->withdrawn[$packKey . '@' . $version] = $reason;
    }

    public function isWithdrawn(string $packKey, string $version): bool
    {
        return isset($this->withdrawn[$packKey . '@' . $version]);
    }

    /** @return list<string> */
    public function installed(): array
    {
        return array_keys($this->packs);
    }
}
