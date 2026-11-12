<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

/**
 * R-VII-035: the engine throws exactly one exception type, and only for
 * `status = failed`. A business outcome is never an exception; an evaluation
 * error is never a business outcome.
 *
 * R-VII-041/042: the failure MUST surface. It carries the rule code, the version,
 * the error code and the failing node, and it carries the trace — so the subject
 * becomes a named exception on the run, the operator sees it, and a payroll run
 * cannot progress past preview while it is unresolved. There is no configuration,
 * per tenant or per deployment, that turns this into a silent pass.
 *
 * R-VII-021: at a payment gate this specifically means the payment is HELD, not
 * released. SmartPRS 2's DRA gate is designed to "never break the payment path
 * on error" — a reasonable instinct that produces the wrong answer for a `block`
 * tenant. See D-VII-01.
 */
final class RuleEvaluationFailed extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly ?string $ruleCode,
        public readonly ?string $ruleVersionId,
        public readonly ?string $nodePath,
        public readonly DecisionTrace $trace,
        string $detail,
    ) {
        parent::__construct(
            'Rule evaluation failed: ' . $errorCode
            . ($ruleCode !== null ? ' in rule ' . $ruleCode : '')
            . ($nodePath !== null && $nodePath !== '' ? ' at ' . $nodePath : '')
            . '. ' . $detail
            . ' The subject is an exception on this derivation; it is not given a default outcome (R-VII-041).'
        );
    }
}
