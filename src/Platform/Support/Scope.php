<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Support;

/**
 * The universal targeting expression of spine S3.1, attached to every
 * configurable artefact.
 *
 * A predicate is `dimension => IN [values]`. `ALL` — unconstrained on that
 * dimension — is represented by the dimension simply being absent, so an empty
 * Scope is the tenant default with specificity 0. That keeps the weight sum a
 * one-liner and makes "did the author constrain this?" unambiguous.
 */
final readonly class Scope
{
    /**
     * The weight table of spine S3.2, exactly. NOT tenant-editable: a customer
     * changing precedence weights would silently change every historical
     * resolution. Stored here as a platform constant and mirrored by the
     * platform weight table in the database.
     *
     * ponytail / blueprint gap: S3.1 lists 21 scopeable dimensions but the S3.2
     * weight table declares only 20 — `pay_group` has no weight. It is accepted
     * as a legal dimension with weight 0, which means constraining it adds no
     * specificity and therefore *increases* the chance of a blocked publication.
     * That is the safe direction. Raise it with the Product Owner (D-S3-01)
     * rather than inventing a weight.
     */
    public const WEIGHTS = [
        'employee' => 1000,
        'team' => 500,
        'sub_department' => 450,
        'department' => 400,
        'designation' => 350,
        'grade' => 340,
        'shift' => 330,
        'employee_category' => 320,
        'employment_type' => 310,
        'employment_stage' => 300,
        'cost_centre' => 250,
        'client_process' => 240,
        'business_unit' => 230,
        'location' => 200,
        'branch' => 180,
        'zone' => 160,
        'state' => 140,
        'establishment' => 120,
        'company' => 100,
        'legal_entity' => 60,
        'pay_group' => 0,
    ];

    /** @param array<string, list<string>> $predicates dimension => IN list */
    private function __construct(public array $predicates)
    {
    }

    /**
     * @param  array<string, list<string>|string>  $predicates
     */
    public static function of(array $predicates): self
    {
        $normalised = [];

        foreach ($predicates as $dimension => $values) {
            if (!array_key_exists($dimension, self::WEIGHTS)) {
                throw new \InvalidArgumentException(
                    "Unknown scope dimension '{$dimension}'. Scopeable dimensions are fixed by spine S3.1."
                );
            }

            $values = array_values(array_unique(array_map(strval(...), (array) $values)));

            if ($values === []) {
                throw new \InvalidArgumentException(
                    "Dimension '{$dimension}' has an empty IN list. Omit the dimension to mean ALL."
                );
            }

            sort($values); // canonical, so two equal scopes serialise identically
            $normalised[$dimension] = $values;
        }

        ksort($normalised);

        return new self($normalised);
    }

    /** The tenant default: unconstrained on every dimension, specificity 0. */
    public static function tenantDefault(): self
    {
        return new self([]);
    }

    /** Spine S3.2 rule 1: sum of the weights of the constrained dimensions. */
    public function specificity(): int
    {
        return array_sum(array_map(
            static fn (string $dimension): int => self::WEIGHTS[$dimension],
            array_keys($this->predicates),
        ));
    }

    /**
     * Every constrained dimension must be present in the subject and match.
     * A subject that does not carry a constrained dimension does not match —
     * fails closed, never "unknown therefore allowed".
     *
     * @param  array<string, string|int|null>  $subjectDimensions
     */
    public function matches(array $subjectDimensions): bool
    {
        foreach ($this->predicates as $dimension => $values) {
            $subjectValue = $subjectDimensions[$dimension] ?? null;

            if ($subjectValue === null || !in_array((string) $subjectValue, $values, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Spine S3.2 precedence: specificity, then declared priority, then nothing.
     * Returns 1 if this scope wins, -1 if $other wins. A full tie throws —
     * there is no third tie-break.
     */
    public function compareTo(
        self $other,
        int $priority = 0,
        int $otherPriority = 0,
        string $label = 'this artefact',
        string $otherLabel = 'the other artefact',
    ): int {
        $bySpecificity = $this->specificity() <=> $other->specificity();

        if ($bySpecificity !== 0) {
            return $bySpecificity;
        }

        $byPriority = $priority <=> $otherPriority;

        if ($byPriority !== 0) {
            return $byPriority;
        }

        throw new AmbiguousConfiguration(
            [$label, $otherLabel],
            'Both have specificity ' . $this->specificity() . " and priority {$priority}."
        );
    }

    /** True when both would win a comparison — i.e. compareTo() would throw. */
    public function tiesWith(self $other, int $priority = 0, int $otherPriority = 0): bool
    {
        return $this->specificity() === $other->specificity() && $priority === $otherPriority;
    }

    public function isTenantDefault(): bool
    {
        return $this->predicates === [];
    }

    /** Canonical JSON for the version row's scope_json (V.5.2 persistence). */
    public function toJson(): string
    {
        return json_encode(
            $this->predicates,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /** The normalised predicate rows for config_version_scopes. */
    public function toPredicateRows(): array
    {
        $rows = [];

        foreach ($this->predicates as $dimension => $values) {
            foreach ($values as $value) {
                $rows[] = ['dimension' => $dimension, 'operator' => 'IN', 'value' => $value];
            }
        }

        return $rows;
    }

    public function __toString(): string
    {
        return $this->isTenantDefault() ? '{tenant default}' : $this->toJson();
    }
}
