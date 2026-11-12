<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

/**
 * What the rule publish gates need to know beyond the rule itself.
 *
 * R07 needs the other rules at this decision point to run the conflict
 * detector. R08 needs the stored regression cases, and the siblings again,
 * because publishing re-runs every case at the affected decision point rather
 * than only this rule's (§11.6). R09 needs who authored and who approved.
 *
 * EVERY REGISTRY DEFAULTS TO NULL, NOT TO EMPTY — the same rule as
 * Formula\GateEnvironment, for the same reason. A null means "this checkout
 * cannot resolve these" and the gate records NOT CHECKED naming what was
 * missing; an empty array means "there are none" and the gate checks against
 * nothing and passes. A gate handed nothing must never report `pass`.
 */
final readonly class GateEnvironment
{
    /**
     * @param list<Rule>|null         $siblings  other rules at the same decision point,
     *                                           published plus in-flight candidates
     * @param list<RuleTestCase>|null $tests     stored regression cases for this rule
     */
    public function __construct(
        public ?array $siblings = null,
        public ?array $tests = null,
        public ?string $authorUserId = null,
        public ?string $approverUserId = null,
    ) {
    }

    /**
     * The audit metadata a publisher already carries, read as an environment.
     *
     * @param array<string, string> $audit
     * @param list<Rule>|null $siblings
     * @param list<RuleTestCase>|null $tests
     */
    public static function fromAudit(array $audit, ?array $siblings = null, ?array $tests = null): self
    {
        return new self(
            $siblings,
            $tests,
            isset($audit['created_by']) ? (string) $audit['created_by'] : null,
            isset($audit['approved_by']) ? (string) $audit['approved_by'] : null,
        );
    }
}
