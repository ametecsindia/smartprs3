<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Statutory\Applicability;
use SmartPRS\Platform\Statutory\ContributionRow;
use SmartPRS\Platform\Statutory\PackStore;
use SmartPRS\Platform\Statutory\PackVerifier;
use SmartPRS\Platform\Statutory\RulePack;
use SmartPRS\Platform\Statutory\StatutoryEngine;
use SmartPRS\Platform\Statutory\StatutoryUnresolved;
use SmartPRS\Platform\Support\Money;

/**
 * THE STATUTORY ENGINE, ON A PAYSLIP — and the loudest thing on this screen is
 * what it is NOT.
 *
 * Phase 6 is recorded as BLOCKED, not failed: the machinery is finished and
 * proven, and the CONTENT waits for a named compliance owner who can sign real
 * rates. Part XXXII is explicit — "no compliance owner, no phase" — because a
 * platform cannot compute a statutory figure it cannot attribute to somebody.
 *
 * So the pack loaded here is the SYNTHETIC one. Jurisdiction `IN-ZZ` does not
 * exist, the authority is this repository, and `verification.synthetic` is true,
 * which makes every figure derived from it carry a synthetic stamp all the way
 * to the screen. It would have been easy to type real PF and ESI rates into a
 * JSON file and have this page look finished. That is precisely the failure the
 * blueprint names: values attributed to nobody, in a file that looks
 * authoritative, which somebody eventually deducts from a real salary.
 *
 * What IS proven end to end here: signature and countersignature verification,
 * JCS canonicalisation, the immutable store, effective-dated resolution,
 * applicability with a reason and evidence, the wage base assembled from
 * declared FLAGS rather than a list of component names, and a figure that
 * carries its rule, pack version, content hash and source reference so it can be
 * re-derived in 2031.
 *
 * AN UNDECLARED STATUTE IS NOT "DOES NOT APPLY". It is undetermined, and it says
 * so — the difference between a decision somebody made and a question nobody
 * asked.
 *
 * ponytail: one establishment, one head, declared in this file. Real
 * applicability is per establishment with an evidence document; that needs the
 * compliance owner too.
 */
final class Statutory
{
    public const ESTABLISHMENT = 'EST-SYNTH';

    private ?StatutoryEngine $engine = null;

    private ?PackStore $store = null;

    private bool $available = false;

    private string $why = 'not loaded yet';

    public function __construct(private readonly string $builder)
    {
    }

    private function boot(): void
    {
        if ($this->engine !== null || $this->why !== 'not loaded yet') {
            return;
        }

        if (!is_file($this->builder) || !extension_loaded('sodium')) {
            // Stated, not silently skipped. A payslip that quietly loses its
            // statutory lines because an extension is missing is the exact shape
            // of the failure this whole subsystem exists to prevent.
            $this->why = extension_loaded('sodium')
                ? 'the synthetic pack builder is not present in this deployment'
                : 'the sodium extension is not loaded, so no pack signature can be verified — and an '
                    . 'unverified pack is refused rather than trusted';

            return;
        }

        require_once $this->builder;

        try {
            ['pack' => $pack, 'keys' => $keys] = syntheticPack();
            $store = new PackStore(new PackVerifier($keys, '3.2.0'));
            $store->put($pack);

            $applicability = new Applicability();
            $applicability->declare(
                head: 'PT',
                establishmentCode: self::ESTABLISHMENT,
                packKey: $pack->manifest->packKey(),
                from: $pack->manifest->effectiveFrom(),
                applies: true,
                reason: 'declared for the demonstration establishment so the machinery can be seen '
                    . 'working; it states no law',
                evidence: 'SYNTHETIC — tools/phase6/synthetic-pack.php, which is a repository file and '
                    . 'not a statutory authority',
            );

            $this->engine = new StatutoryEngine($store, $applicability);
            $this->store = $store;
            $this->available = true;
            $this->why = 'the synthetic pack verified and is in force';
        } catch (\Throwable $e) {
            // A pack that fails verification is REFUSED, and the reason is
            // carried to the screen. This is the branch that must never become a
            // silent zero.
            $this->why = 'the pack was REFUSED: ' . $e->getMessage();
        }
    }

    public function why(): string
    {
        $this->boot();

        return $this->why;
    }

    public function isAvailable(): bool
    {
        $this->boot();

        return $this->available;
    }

    /**
     * Compute one head for one employee.
     *
     * @param array<string, Money> $amounts component code => amount
     * @param array<string, array<string, bool>> $flags component code => declared flags
     * @return array{row: ?ContributionRow, refusal: ?string}
     */
    public function compute(string $head, string $employmentId, \DateTimeImmutable $asOf,
        array $amounts, array $flags): array
    {
        $this->boot();

        if (!$this->available) {
            return ['row' => null, 'refusal' => 'No statutory computation was attempted because '
                . $this->why . '. This is REPORTED rather than returned as a zero: a zero deduction '
                . 'that should have been a real one looks exactly like a correct one on the payslip, '
                . 'and the employee finds out at assessment.'];
        }

        try {
            return ['row' => $this->engine->compute($head, $employmentId, self::ESTABLISHMENT,
                $asOf, $amounts, $flags), 'refusal' => null];
        } catch (StatutoryUnresolved $e) {
            return ['row' => null, 'refusal' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['row' => null, 'refusal' => 'The engine refused: ' . $e->getMessage()];
        }
    }

    /**
     * THE HEADS THE PACKS THEMSELVES DECLARE, head => the pack that declares it.
     *
     * Discovered from the rules in force on the date, never a list of 'PF', 'ESI',
     * 'PT', 'TDS' typed into PHP. A statutory head this build has not met yet is a
     * pack somebody installs, not a release — the same law that makes the wage
     * base a declared FLAG rather than a list of component names.
     *
     * @return array<string, RulePack>
     */
    public function packsByHead(\DateTimeImmutable $asOf): array
    {
        $this->boot();

        if ($this->store === null) {
            return [];
        }

        $byHead = [];

        foreach ($this->store->installed() as $id) {
            // `installed()` hands back "pack_key@version"; resolution is by
            // effective date, so the version is asked for rather than parsed.
            $pack = $this->store->resolve(substr($id, 0, (int) strrpos($id, '@')), $asOf);

            if ($pack === null) {
                continue;
            }

            foreach ($pack->rules() as $rule) {
                $byHead[$rule->head] = $pack;
            }
        }

        ksort($byHead);

        return $byHead;
    }

    /** @return list<string> the statutory heads in force on a date */
    public function heads(\DateTimeImmutable $asOf): array
    {
        return array_keys($this->packsByHead($asOf));
    }

    /**
     * Is any pack in force a SYNTHETIC one? Asked of the manifest, which declares
     * it (`PackManifest::isSynthetic()`) — never inferred from the jurisdiction
     * code, because a heuristic that reads `IN-ZZ` is a heuristic that lets the
     * next fixture through under a plausible-looking code.
     */
    public function isSynthetic(\DateTimeImmutable $asOf): bool
    {
        foreach ($this->packsByHead($asOf) as $pack) {
            if ($pack->manifest->isSynthetic()) {
                return true;
            }
        }

        return false;
    }

    /**
     * EVERY DECLARED HEAD, ASKED FOR ONE EMPLOYMENT: rows, or a stated reason
     * there are none.
     *
     * A SYNTHETIC PACK IS A REASON, NOT A ROW. It never produces a contribution
     * line — not a figure, not a zero, not a stamped one — because a synthetic
     * deduction on a payslip reduces somebody's net pay by an amount attributed to
     * nobody, and it looks entirely plausible while doing it. What the run records
     * instead is that the question was asked and could not be answered, which is
     * the difference between a payslip that is silent and a payslip that is wrong.
     *
     * @param array<string, Money> $amounts component code => amount this period
     * @param array<string, array<string, bool>> $flags component code => declared flags
     * @return array{rows: list<ContributionRow>, concerns: list<string>}
     */
    public function forEmployment(string $employmentId, \DateTimeImmutable $asOf,
        array $amounts, array $flags): array
    {
        $this->boot();

        if (!$this->available) {
            return ['rows' => [], 'concerns' => ['No statutory head was computed for this employee '
                . 'because ' . $this->why . '. The run closed anyway — refusing to pay a month because '
                . 'no pack is installed would make the product unusable — but this payslip states no '
                . 'PF, ESI, PT or TDS, and what is owed for the period is UNKNOWN rather than nil.']];
        }

        $rows = [];
        $concerns = [];

        foreach ($this->packsByHead($asOf) as $head => $pack) {
            if ($pack->manifest->isSynthetic()) {
                $concerns[] = sprintf(
                    '%s was NOT computed and no %s line appears below. The only pack in force for it is '
                    . 'SYNTHETIC — jurisdiction %s, and the "authority" is this repository — so it states '
                    . 'no law and must not reduce anybody\'s net pay. The net on this payslip is the net '
                    . 'before any %s. What is missing is a signed pack for the jurisdiction from a named '
                    . 'compliance owner, not working code: the engine was asked for this employee, on '
                    . 'this date, and refused rather than invent a figure. Until that pack is installed '
                    . 'the %s owed for this period is UNKNOWN, not nil.',
                    $head, $head, $pack->manifest->jurisdiction(), $head, $head,
                );

                continue;
            }

            $computed = $this->compute($head, $employmentId, $asOf, $amounts, $flags);

            if ($computed['row'] === null) {
                $concerns[] = $head . ' was NOT computed and no ' . $head . ' line appears below. '
                    . $computed['refusal'];

                continue;
            }

            if ($computed['row']->applicability->undetermined) {
                // ponytail: only UNDETERMINED raises a concern. "Does not apply,
                // because X" is a decision somebody made and can defend; a
                // question nobody asked is the finding. Surface the determined
                // non-application too the day an inspector wants it on the face
                // of the payslip rather than in the applicability record.
                $concerns[] = $computed['row']->applicability->describe()
                    . ', so nothing was deducted. That is not a nil determination — it is a question '
                    . 'nobody has answered for this establishment.';

                continue;
            }

            if ($computed['row']->applicable) {
                $rows[] = $computed['row'];
            }
        }

        return ['rows' => $rows, 'concerns' => $concerns];
    }
}
