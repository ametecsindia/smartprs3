<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

use SmartPRS\Platform\Rules\Actions\ActionCatalogue;
use SmartPRS\Platform\Spx\SymbolTable;
use SmartPRS\Platform\Spx\Types\Type;

/**
 * THE DECISION-POINT CATALOGUE — Part VII §3 and §5.8.
 *
 * A decision point is the named place a rule may be attached, and its symbol
 * table is the ceiling on what a rule attached there can see (R-VII-006). The
 * table is built ONCE, here, and handed to `SpxExpression::parse()` at publish
 * time; the type checker then refuses `salary.*` in an attendance rule as a
 * publish-time error, and the evaluator has no binding for it either (§10.1c-4,
 * two gates, one static and one dynamic).
 *
 * ponytail: a const table and one factory, not a registry object with a
 * mutator. The catalogue is closed by definition — a new decision point is a
 * blueprint change and therefore a code change, which is the point. When the
 * config-artefact layer starts storing decision points, this file becomes the
 * seed for that table and nothing else moves.
 */
final class DecisionPoints
{
    /**
     * The §3.2 catalogue, verbatim: for each decision point the namespaces it
     * exposes, the actions a rule attached there may emit, and the conflict
     * policy that governs it. `agg` implies `event`, which
     * SymbolTable::restrictedTo() adds for us.
     *
     * R-VII-005 requires all three: "a declared symbol table, a declared action
     * allow-list, and a conformance test asserting the caller never applies an
     * action the decision point does not declare." Until the allow-list was
     * here, that assertion had nothing to read — so `actionSweep()` below is the
     * test, and `PublishGates` is the publish-time half.
     *
     * `context` is on every point: it carries `context.tenant_timezone`, which
     * is a declared symbol precisely so no default for it can hide in code.
     *
     * `not_null` is R-VII-015's `not_null_symbols_json`, and it was the last
     * piece of that rule with no caller. Symbol carries the flag, SymbolTable
     * has `requiringNotNull()`, the evaluator raises ERR_MISSING_INPUT on it,
     * spx_safety_test asserts the behaviour — and NOT ONE DECISION POINT
     * DECLARED A SYMBOL, so in production the branch was unreachable and every
     * missing input read as "no rule matched". That is the failure this
     * codebase keeps finding in itself: a mechanism complete except for the
     * declaration that arms it.
     *
     * What goes on a list, and what deliberately does not: a symbol belongs
     * here when NULL cannot be a legitimate value at that point AND reading it
     * as "not matched" has a financial or compliance consequence. `leave.type`
     * is the blueprint's own case — SmartPRS 2's `paidLeaveDays():1065` treats
     * an unknown type_id as PAID, and `LeaveController::apply()` never writes
     * type_id at all, so the silent default has a direct cost. `day.date` is
     * here because a day being classified always has a date, and a classifier
     * that does not know WHICH day is not classifying anything.
     *
     * `leave.balance` is on the leave points for the same reason one layer
     * down: a balance is always computable, so a NULL balance does not mean
     * "zero left", it means the ledger read did not happen. Charging leave
     * against a balance nobody read is the shape of the defect, not a variant
     * of it.
     *
     * `leave.balance` is on the leave points for the same reason one layer
     * down: a balance is always computable, so a NULL balance does not mean
     * "zero left", it means the ledger read did not happen. Charging leave
     * against a balance nobody read is the shape of the defect, not a variant
     * of it.
     *
     * `attendance.worked_minutes` is deliberately NOT here: null is legitimate
     * on a holiday, and requiring it would fail evaluations that are correct.
     * A not-null list that over-reaches gets relaxed under pressure until it
     * declares nothing, which is where this one started.
     *
     * @var array<string, array{ns: list<string>, actions: list<string>, policy: ConflictPolicy, not_null?: list<string>}>
     */
    private const POINTS = [
        'attendance.day.classify' => [
            'ns' => ['employee', 'org', 'service', 'period', 'shift', 'day', 'attendance', 'leave', 'policy', 'context', 'agg'],
            'actions' => ['SET_STATUS', 'FLAG_FOR_REVIEW', 'NOTIFY'],
            'policy' => ConflictPolicy::HighestPriorityOnly,
            'not_null' => ['day.date'],
        ],
        'attendance.day.penalise' => [
            'ns' => ['employee', 'org', 'service', 'period', 'shift', 'day', 'attendance', 'leave', 'policy', 'context', 'agg'],
            'actions' => ['APPLY_DAY_PENALTY', 'APPLY_LOP', 'OFFSET_SHORTFALL', 'FLAG_FOR_REVIEW', 'NOTIFY'],
            'policy' => ConflictPolicy::AllMatch,
            'not_null' => ['day.date'],
        ],
        'attendance.day.earn' => [
            'ns' => ['employee', 'org', 'service', 'period', 'shift', 'day', 'attendance', 'leave', 'policy', 'context', 'agg'],
            'actions' => ['CREATE_OT', 'CREATE_COMP_OFF', 'NOTIFY'],
            'policy' => ConflictPolicy::AllMatch,
            'not_null' => ['day.date'],
        ],
        'attendance.grace' => [
            'ns' => ['employee', 'org', 'period', 'shift', 'day', 'attendance', 'policy', 'context'],
            'actions' => ['SET_GRACE'],
            'policy' => ConflictPolicy::HighestPriorityOnly,
            'not_null' => ['day.date'],
        ],
        'attendance.calendar.weekly_off' => [
            'ns' => ['employee', 'org', 'period', 'day', 'policy', 'context'],
            'actions' => ['SET_DAY_TYPE'],
            'policy' => ConflictPolicy::HighestPriorityOnly,
            'not_null' => ['day.date'],
        ],
        'leave.request.validate' => [
            'ns' => ['employee', 'org', 'service', 'period', 'day', 'leave', 'request', 'policy', 'context', 'agg'],
            'actions' => ['BLOCK', 'REQUIRE_DOCUMENT', 'CONVERT_TO', 'ROUTE_TO_WORKFLOW', 'NOTIFY'],
            'policy' => ConflictPolicy::AllMatch,
            'not_null' => ['leave.type', 'leave.balance'],
        ],
        'leave.request.charge' => [
            'ns' => ['employee', 'org', 'period', 'day', 'leave', 'request', 'policy', 'context', 'agg'],
            'actions' => ['DEDUCT', 'CHARGE_DAY', 'EXEMPT_DAY'],
            'policy' => ConflictPolicy::AllMatch,
            'not_null' => ['leave.type', 'leave.balance'],
        ],
        'payroll.component.eligibility' => [
            'ns' => ['employee', 'org', 'service', 'period', 'salary', 'statutory', 'policy', 'context', 'agg'],
            'actions' => ['ADD_COMPONENT', 'SUPPRESS_COMPONENT', 'SET_BASIS', 'CAP'],
            'policy' => ConflictPolicy::AllMatch,
            'not_null' => ['period.start', 'period.end'],
        ],
        'payroll.recovery' => [
            'ns' => ['employee', 'org', 'service', 'period', 'salary', 'policy', 'context', 'agg'],
            'actions' => ['CREATE_RECOVERY', 'SET_RECOVERY_ORDER', 'SUPPRESS_COMPONENT'],
            'policy' => ConflictPolicy::AllMatch,
            'not_null' => ['period.start', 'period.end'],
        ],
        'payroll.run.gate' => [
            'ns' => ['employee', 'org', 'period', 'salary', 'run', 'policy', 'context', 'agg'],
            'actions' => ['GATE_PAYMENT', 'FLAG_FOR_REVIEW', 'REQUIRE_APPROVAL'],
            'policy' => ConflictPolicy::AllMatch,
            'not_null' => ['period.start', 'period.end'],
        ],
        'compliance.payment_gate' => [
            'ns' => ['employee', 'org', 'credential', 'compliance', 'request', 'policy', 'context'],
            'actions' => ['GATE_PAYMENT', 'RAISE_ITEM', 'SET_RISK', 'NOTIFY'],
            'policy' => ConflictPolicy::AllMatch,
        ],
        'compliance.item.raise' => [
            'ns' => ['employee', 'org', 'credential', 'compliance', 'day', 'policy', 'context'],
            'actions' => ['RAISE_ITEM', 'SET_RISK', 'NOTIFY'],
            'policy' => ConflictPolicy::AllMatch,
        ],
        'platform.config.guard' => [
            'ns' => ['org', 'policy', 'statutory', 'context'],
            'actions' => ['BLOCK', 'REQUIRE_APPROVAL', 'WRITE_AUDIT'],
            'policy' => ConflictPolicy::AllMatch,
        ],
    ];

    /**
     * §5.7: `policy.value(k)` is Number by default, and a key that means minutes
     * must SAY minutes — otherwise `attendance.late_minutes > policy.value(k)`
     * compares Minutes with Number and the unit-confusion family walks straight
     * back in. Declaring the keys also makes an unknown key a publish-time error
     * instead of a runtime null.
     *
     * @return array<string, Type>
     */
    private static function policyKeys(): array
    {
        return [
            'late_grace_min' => Type::minutes(),
            'late_band_half_min' => Type::minutes(),
            'late_band_absent_min' => Type::minutes(),
            'ot_daily_threshold_hours' => Type::hours(),
            'ot_rounding_block_min' => Type::minutes(),
            'ot_enabled' => Type::boolean(),
            'comp_off_min_minutes' => Type::minutes(),
            'comp_off_full_minutes' => Type::minutes(),
            'sandwich_enabled' => Type::boolean(),
            'sandwich_exempt_types' => Type::listOf(Type::code('leave_type')),
            'absence_hold_for_review' => Type::boolean(),
            'credential_gate_mode' => Type::code('credential_gate_mode'),
            'credential_gate_events' => Type::listOf(Type::code('money_point')),
            'credential_gate_type' => Type::code('credential_type'),
        ];
    }

    /**
     * The declared value sets. A Code compared with a string literal is checked
     * against these AT PUBLISH — `day.holiday_type = 'festvial'` is refused with
     * a position, rather than silently never matching.
     *
     * @return array<string, list<string>>
     */
    private static function valueSets(): array
    {
        return [
            'weekday' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
            'holiday_type' => ['national', 'festival', 'optional', 'restricted', 'none'],
            'employment_stage' => ['probation', 'confirmed', 'notice', 'exited'],
            'leave_type' => ['EL', 'SL', 'CL', 'ML', 'LOP', 'COMP_OFF'],
            'credential_type' => ['DRA', 'PAN', 'AADHAAR'],
            'credential_status' => ['verified', 'pending', 'expired', 'rejected'],
            'credential_gate_mode' => ['off', 'warn', 'block'],
            'money_point' => [
                'commission.approve', 'incentive.commit', 'offroll_earning.approve',
                'expense.approve', 'payroll.commit',
            ],
            'attendance_status' => ['PRESENT', 'ABSENT', 'HALF_DAY', 'WEEKLY_OFF', 'HOLIDAY', 'ON_LEAVE'],
            'policy_key' => [], // filled from policyKeys() below
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::POINTS);
    }

    public static function knows(string $decisionPoint): bool
    {
        return isset(self::POINTS[$decisionPoint]);
    }

    /** @return array{ns: list<string>, actions: list<string>, policy: ConflictPolicy} */
    private static function point(string $decisionPoint): array
    {
        if (!isset(self::POINTS[$decisionPoint])) {
            throw new \InvalidArgumentException(
                "'{$decisionPoint}' is not a declared decision point. The catalogue is closed (§3): "
                . implode(', ', self::all())
            );
        }

        return self::POINTS[$decisionPoint];
    }

    /** The symbol table a rule at this decision point is published against. */
    public static function symbols(string $decisionPoint): SymbolTable
    {
        $namespaces = self::point($decisionPoint)['ns'];
        $policyKeys = self::policyKeys();
        $table = SymbolTable::forNamespaces(...$namespaces);

        foreach (self::valueSets() as $name => $members) {
            $table = $table->withValueSet(
                $name,
                $name === 'policy_key' ? array_keys($policyKeys) : $members,
            );
        }

        if (in_array('policy', $namespaces, true)) {
            $table = $table->withKeyedTypes('policy.value', $policyKeys);
        }

        // R-VII-015. The call that was missing: without it the flag is set on
        // no symbol anywhere, the evaluator's ERR_MISSING_INPUT branch is
        // unreachable, and a rule reading an input nobody supplied quietly does
        // not match.
        return $table->requiringNotNull(...self::notNull($decisionPoint));
    }

    /**
     * The symbols that must not be null when a rule at this point is evaluated
     * (R-VII-015). Empty for a point where every input has a legitimate null.
     *
     * @return list<string>
     */
    public static function notNull(string $decisionPoint): array
    {
        return self::point($decisionPoint)['not_null'] ?? [];
    }

    /**
     * The actions a rule attached here may emit (§3.2, R-VII-005).
     *
     * @return list<string>
     */
    public static function actions(string $decisionPoint): array
    {
        return self::point($decisionPoint)['actions'];
    }

    public static function emits(string $decisionPoint, string $actionCode): bool
    {
        return in_array($actionCode, self::point($decisionPoint)['actions'], true);
    }

    /**
     * §3.2's declared default. The engine reads THIS rather than the
     * highest-precedence candidate's own `on_conflict`, so a set whose rules
     * disagree cannot decide its own policy by winning precedence.
     */
    public static function conflictPolicy(string $decisionPoint): ConflictPolicy
    {
        return self::point($decisionPoint)['policy'];
    }

    /**
     * Actions the catalogue declares that NO decision point may emit.
     *
     * The mirror of Access\OperationSweep, and for the same reason: an action
     * nothing can emit is either a gap in this table or dead weight in the
     * catalogue, and both are invisible until something asks. The sweep does not
     * decide which — it reports, and a person decides.
     *
     * @return list<string>
     */
    public static function unreachableActions(): array
    {
        $emittable = [];

        foreach (self::POINTS as $point) {
            foreach ($point['actions'] as $code) {
                $emittable[$code] = true;
            }
        }

        return array_values(array_filter(
            ActionCatalogue::codes(),
            static fn (string $code): bool => !isset($emittable[$code]),
        ));
    }

    /**
     * Action codes named in this table that the catalogue does not define.
     *
     * The other direction, and the more dangerous one: an allow-list naming an
     * action nobody implements would let a rule be published that can never be
     * applied.
     *
     * @return list<string>
     */
    public static function undefinedActions(): array
    {
        $undefined = [];

        foreach (self::POINTS as $name => $point) {
            foreach ($point['actions'] as $code) {
                if (!ActionCatalogue::knows($code)) {
                    $undefined[] = $name . ' → ' . $code;
                }
            }
        }

        return $undefined;
    }
}
