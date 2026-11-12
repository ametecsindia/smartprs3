<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx;

/**
 * The typed error values of Part VII §5.8.
 *
 * Every SPX function is TOTAL: it returns one of these rather than throwing, the
 * error poisons its containing expression, and the rule evaluation FAILS —
 * loudly, to an operator. COALESCE absorbs a Null; it does not absorb an error.
 *
 * There is no configuration, per tenant or per deployment, that turns one of
 * these into a silent pass (R-VII-042). SmartPRS 2 destroys 50.5% of its caught
 * exceptions; that is the behaviour this enum exists to make impossible.
 */
enum ErrorCode: string
{
    case DivZero = 'ERR_DIV_ZERO';
    case Type = 'ERR_TYPE';
    case MissingInput = 'ERR_MISSING_INPUT';
    case Arg = 'ERR_ARG';
    case BudgetNodes = 'ERR_BUDGET_NODES';
    case BudgetTime = 'ERR_BUDGET_TIME';
    case BudgetDepth = 'ERR_BUDGET_DEPTH';
    case RegexSteps = 'ERR_REGEX_STEPS';
    case AggUnavailable = 'ERR_AGG_UNAVAILABLE';
    case SetMember = 'ERR_SET_MEMBER';
    case Precision = 'ERR_PRECISION';

    /** A budget breach aborts the whole evaluation; the rest poison and propagate. */
    public function abortsEvaluation(): bool
    {
        return match ($this) {
            self::BudgetNodes, self::BudgetTime, self::BudgetDepth, self::AggUnavailable => true,
            default => false,
        };
    }
}
