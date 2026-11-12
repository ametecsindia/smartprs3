<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

use SmartPRS\Platform\Config\Publisher;
use SmartPRS\Platform\Rules\Actions\Action;
use SmartPRS\Platform\Rules\Actions\ActionCatalogue;

/**
 * PUBLISHING A RULE, WITH THE PAYLOAD DERIVED FROM THE RULE.
 *
 * The twin of Formula\FormulaPublisher and it exists for the same reason.
 * Config\Publisher takes a payload array because it publishes any artefact type,
 * which leaves one seam a caller can get wrong: hand-mapping a Rule's fields
 * onto `rule_payloads` columns. Two records of the same facts kept in step by
 * hand is what R-VIII-012 refuses for dependency sets, and it is no better here
 * — an `is_money_affecting` that says false in the row and true on the object is
 * a rule that skipped maker-checker.
 *
 * So this is the only way a rule is published: the report comes from PublishGates
 * over the same object, and every column comes from the object. There is no
 * argument through which a caller can disagree with the rule being published.
 */
final class RulePublisher
{
    public function __construct(private readonly Publisher $publisher)
    {
    }

    /**
     * @param  array<string, string>  $audit  created_by, approved_by, reason, change_note
     * @return int the new config_versions id
     *
     * @throws \SmartPRS\Platform\Config\PublicationRefused when a gate blocks
     */
    public function publish(
        Rule $rule,
        \DateTimeImmutable $now,
        ?GateEnvironment $environment = null,
        array $audit = [],
    ): int {
        // The gates need to know who authored and who approved, and the audit
        // array already carries it. Reading it here rather than asking the
        // caller for it twice is what stops R10 from being handed a different
        // approver than the one the version records.
        $environment = $environment === null
            ? GateEnvironment::fromAudit($audit)
            : new GateEnvironment(
                $environment->siblings,
                $environment->tests,
                $environment->authorUserId ?? (isset($audit['created_by']) ? (string) $audit['created_by'] : null),
                $environment->approverUserId ?? (isset($audit['approved_by']) ? (string) $audit['approved_by'] : null),
            );

        return $this->publisher->publish(
            'rule',
            $rule->code,
            'rule_payloads',
            self::payload($rule, $now),
            $rule->scope,
            $rule->range,
            PublishGates::run($rule, $environment),
            $now,
            $rule->priority,
            $rule->checksum(),
            $audit,
        );
    }

    /**
     * The payload row, entirely from the rule.
     *
     * Deliberately exhaustive rather than clever: every column of `rule_payloads`
     * that is not an id appears once, on the left, with the property it comes
     * from on the right. A column added to the migration without a line here
     * fails on the INSERT rather than being written as a silent default.
     *
     * @return array<string, mixed>
     */
    public static function payload(Rule $rule, \DateTimeImmutable $now): array
    {
        $timestamp = $now->format('Y-m-d H:i:s');

        return [
            'decision_point' => $rule->decisionPoint,
            'module' => $rule->module,
            'business_description' => $rule->businessDescription,

            // R-VII-009: the AST is the evaluated artefact and the source is
            // documentation. Both are stored, and the checksum binds them.
            'when_src' => $rule->when->source(),
            'when_ast' => self::ast($rule->when),
            'except_src' => $rule->except?->source(),
            'except_ast' => $rule->except === null ? null : self::ast($rule->except),
            'then_json' => self::json(array_map(
                static fn (Action $a): array => [
                    'action' => $a->code,
                    'parameters' => $a->sourceForm(),
                    'money_affecting' => $a->isMoneyAffecting(),
                ],
                $rule->then,
            )),

            'on_conflict' => $rule->onConflict->value,
            'stop_on_match' => $rule->stopOnMatch ? 1 : 0,
            'severity' => $rule->severity,
            'requires_approval' => $rule->requiresApproval ? 1 : 0,
            'is_money_affecting' => $rule->isMoneyAffecting() ? 1 : 0,
            'tags_json' => $rule->tags === [] ? null : self::json($rule->tags),
            'source_ref' => $rule->sourceRef,
            'checksum' => $rule->checksum(),

            // R-VII-017: which function catalogue this rule compiled against, so
            // a catalogue upgrade can find every rule that predates it.
            'catalogue_version' => (int) explode('.', ActionCatalogue::VERSION)[0],

            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    /** The canonical AST, as the column's own comment says it stores it. */
    private static function ast(Expression $expression): string
    {
        if (!$expression instanceof SpxExpression) {
            throw new \InvalidArgumentException(
                'Only an SpxExpression can be published: the stored artefact is its AST, and an '
                . get_debug_type($expression) . ' has none (R-VII-009).'
            );
        }

        return $expression->compiled()->toJson();
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
