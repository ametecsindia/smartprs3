<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

/**
 * WHICH RULE APPLIES TO `(field, surface, actor)` — Part X §X.4.2, R-X-030.
 *
 * Four steps, most specific first, and a fourth that is the whole design:
 *
 *   1. rows for `(field, surface, role)` where role is one the actor holds;
 *   2. the row for `(field, surface, role IS NULL)`;
 *   3. the field's own default visibility and requiredness;
 *   4. **hidden and never. Absence of a rule never grants access.**
 *
 * Across several of the actor's roles the MOST PERMISSIVE visibility applies,
 * the STRICTEST requiredness applies, and the change workflows UNION — any one
 * requiring approval means approval. Those three are deliberately not the same
 * direction: access follows the role that grants it, obligation follows the role
 * that imposes it, and approval is a floor nobody can lower.
 *
 * R-X-030: "surface-rule resolution fails closed at every step: an unknown role,
 * a null scope, an unresolvable field, an SPX evaluation error in
 * `required_expression`, or any exception denies visibility and, for a write,
 * denies the write."
 *
 * That rule has an origin worth keeping in front of anyone editing this file.
 * SmartPRS 2's `ApprovalService::denyUnlessRole()` wraps its role check in
 * `catch (\Throwable) { return null; }`, where `null` means ALLOWED, under the
 * comment "fail open — availability over strictness on pivot errors". One catch
 * block grants access across most of its employee subsystem. So every failure
 * path below returns `denied()`, and the reason travels with it.
 */
final class SurfaceRuleResolver
{
    /** @param list<SurfaceRule> $rules the whole matrix; filtering happens here, not at the call site */
    public function __construct(private readonly array $rules)
    {
    }

    /**
     * @param  list<mixed>  $actorRoles  declared wide: this crosses in from the actor's
     *         resolved role assignment, so PHP gives no static guarantee its elements are
     *         strings — the filter below is what actually enforces it, and R-X-030 fails
     *         closed rather than trusting the type.
     */
    public function resolve(
        ?FieldDefinition $field,
        Surface $surface,
        array $actorRoles,
    ): EffectiveRule {
        // An unresolvable field. Not an exception: a caller asking about a field
        // that does not exist for this tenant, scope or date gets the same answer
        // as one asking about a field they may not see, which is the answer that
        // leaks nothing about which of the two it was.
        if ($field === null) {
            return self::denied('(unknown)', $surface, 'the field does not resolve for this subject, scope or date');
        }

        // An actor with no roles at all. Absence of a role is not the "all roles"
        // row: that row exists to say what EVERY role may do, and somebody
        // holding none is not every role.
        $roles = array_values(array_filter($actorRoles, static fn (mixed $r): bool => is_string($r) && $r !== ''));

        $roleRules = [];
        $allRoleRule = null;

        foreach ($this->rules as $rule) {
            if ($rule->fieldKey !== $field->fieldKey || $rule->surface !== $surface) {
                continue;
            }

            if ($rule->roleKey === null) {
                $allRoleRule = $rule;

                continue;
            }

            if (in_array($rule->roleKey, $roles, true)) {
                $roleRules[] = $rule;
            }
        }

        if ($roleRules !== []) {
            return self::combine($field, $surface, $roleRules, 'step 1: rules for the roles this actor holds');
        }

        if ($allRoleRule !== null) {
            return self::combine($field, $surface, [$allRoleRule], 'step 2: the all-roles row for this surface');
        }

        // Step 3. The field's own defaults, which for a field nobody has
        // configured are hidden/never — step 4 by another route.
        if ($field->defaultVisibility === Visibility::Hidden) {
            return self::denied(
                $field->fieldKey,
                $surface,
                'step 4: no rule for this field on this surface, and the field defaults to hidden. '
                . 'Absence of a rule never grants access (R-X-030)',
            );
        }

        return new EffectiveRule(
            fieldKey: $field->fieldKey,
            surface: $surface,
            visibility: $field->defaultVisibility,
            requiredness: $field->defaultRequiredness,
            masking: $field->maskingRule,
            changeWorkflowKeys: [],
            why: "step 3: no surface rule, so the field's own defaults apply",
        );
    }

    /**
     * Most permissive visibility, strictest requiredness, union of workflows.
     *
     * @param list<SurfaceRule> $rules
     */
    private static function combine(
        FieldDefinition $field,
        Surface $surface,
        array $rules,
        string $step,
    ): EffectiveRule {
        $visibility = Visibility::Hidden;
        $requiredness = Requiredness::Never;
        $masking = $field->maskingRule;
        $workflows = [];
        $expression = null;
        $winner = null;

        foreach ($rules as $rule) {
            if ($rule->visibility->rank() > $visibility->rank()) {
                $winner = $rule;
            }

            $visibility = $visibility->mostPermissiveOf($rule->visibility);
            $requiredness = $requiredness->strictestOf($rule->requiredness);

            if ($rule->requiredExpression !== null) {
                $expression = $rule->requiredExpression;
            }

            // A masking OVERRIDE only ever narrows what a role sees relative to
            // the field's own rule being absent; it never widens it to `none`
            // for a field the definition classed sensitive. That check lives on
            // the definition, so here the override simply applies.
            if ($rule->maskingOverride !== null) {
                $masking = $rule->maskingOverride;
            }

            if ($rule->changeWorkflowKey !== null && !in_array($rule->changeWorkflowKey, $workflows, true)) {
                $workflows[] = $rule->changeWorkflowKey;
            }
        }

        $why = $step;

        if (count($rules) > 1) {
            // $winner is genuinely nullable here (every rule can rank equal, e.g. all
            // Hidden, and then nothing ever wins), so the null case is real, not a
            // formality — PHPStan mis-scores the nullsafe as dead when it is inlined
            // into this concatenation. Spelling it out keeps the guard live.
            $winnerRole = $winner === null ? 'tied' : ($winner->roleKey ?? 'tied');
            $why .= ' — ' . count($rules) . ' role rows combined: the most permissive visibility ('
                . $winnerRole . '), the strictest requirement, and the union of '
                . (count($workflows) === 1 ? 'one approval workflow' : count($workflows) . ' approval workflows');
        }

        return new EffectiveRule(
            fieldKey: $field->fieldKey,
            surface: $surface,
            visibility: $visibility,
            requiredness: $requiredness,
            masking: $masking,
            changeWorkflowKeys: $workflows,
            why: $why,
            requiredExpression: $expression,
        );
    }

    /** Step 4, and every failure path. */
    private static function denied(string $fieldKey, Surface $surface, string $why): EffectiveRule
    {
        return new EffectiveRule(
            fieldKey: $fieldKey,
            surface: $surface,
            visibility: Visibility::Hidden,
            requiredness: Requiredness::Never,
            masking: 'full',
            changeWorkflowKeys: [],
            why: $why,
        );
    }
}
