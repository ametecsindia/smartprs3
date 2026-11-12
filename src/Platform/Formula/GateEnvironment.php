<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Formula;

/**
 * What the gates need to know beyond the formula itself (Part VIII §9).
 *
 * G2 resolves keyed accessors "in this tenant, in this scope, on
 * `effective_from`" — so it needs the component codes, statutory codes, policy
 * keys and leave types that actually exist there. G6 needs to know which symbols
 * the slice schema declares positive. G11 needs the other published versions of
 * the artefact. G12 needs who approved.
 *
 * EVERY REGISTRY DEFAULTS TO NULL, NOT TO EMPTY. A null registry means "this
 * checkout cannot resolve these", and the gate records `NOT CHECKED` naming
 * what was missing. An empty array means "there are none", and the gate blocks
 * every reference. The distinction is the whole point: a gate handed nothing
 * must never report `pass`.
 */
final readonly class GateEnvironment
{
    /**
     * @param list<string>|null $componentCodes  codes with a version effective on the date
     * @param list<string>|null $statutoryCodes  codes present in the resolved pack
     * @param list<string>|null $policyKeys      published policy artefacts
     * @param list<string>|null $leaveTypes      the leave-type value set
     * @param list<string>|null $formulaCodes    other published formulas
     * @param list<string>|null $offeredSlices   the calculation point's declared slices
     * @param list<string>      $positiveSymbols symbols the slice schema declares positive and not-null
     * @param list<Formula>     $published       other published versions of this artefact
     * @param array<string, string> $extraEdges  graph edges the AST cannot show: node => node
     * @param list<SampleCase>|null $sampleCases  runnable cases (R-VIII-047)
     * @param array<string, Formula> $graph       the formula and its dependencies, for running them
     */
    public function __construct(
        public ?array $componentCodes = null,
        public ?array $statutoryCodes = null,
        public ?array $policyKeys = null,
        public ?array $leaveTypes = null,
        public ?array $formulaCodes = null,
        public ?array $offeredSlices = null,
        public array $positiveSymbols = self::POSITIVE_BY_DEFAULT,
        public array $published = [],
        public array $extraEdges = [],
        /**
         * R-VIII-047. The RUNNABLE sample cases, and the graph to run them
         * against. Null means the checkout could not supply them, and G10 says
         * NOT CHECKED rather than counting names and calling it green.
         *
         * @var list<SampleCase>|null
         */
        public ?array $sampleCases = null,
        /** @var array<string, Formula> the formula under test plus its dependencies */
        public array $graph = [],
    ) {
    }

    /**
     * Symbols a slice schema declares positive and not-null, so a division by
     * one of them is guarded by declaration rather than by habit.
     *
     * SmartPRS 2 guards `max(1, $working)` at :1127 and `max(1.0, $den)` at
     * :1287 but not `$empWorking` at :1272. A divisor guard that is a habit
     * rather than a checked property is the shape of an outage on the one
     * employee it misses.
     */
    public const POSITIVE_BY_DEFAULT = [
        'period.calendar_days',
        'period.periods_per_year',
    ];

    /** The registries a full publish needs, for a checkout that has them. */
    public function withRegistries(
        ?array $componentCodes = null,
        ?array $statutoryCodes = null,
        ?array $policyKeys = null,
        ?array $leaveTypes = null,
        ?array $formulaCodes = null,
    ): self {
        return new self(
            $componentCodes ?? $this->componentCodes,
            $statutoryCodes ?? $this->statutoryCodes,
            $policyKeys ?? $this->policyKeys,
            $leaveTypes ?? $this->leaveTypes,
            $formulaCodes ?? $this->formulaCodes,
            $this->offeredSlices,
            $this->positiveSymbols,
            $this->published,
            $this->extraEdges,
        );
    }

    /** @return list<string>|null the registry for a dependency kind */
    public function registryFor(string $kind): ?array
    {
        return match ($kind) {
            'component' => $this->componentCodes,
            'statutory' => $this->statutoryCodes,
            'policy' => $this->policyKeys,
            'formula' => $this->formulaCodes,
            default => null,
        };
    }
}
