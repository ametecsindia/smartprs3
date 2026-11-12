<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx;

use SmartPRS\Platform\Spx\Types\Type;
use SmartPRS\Platform\Spx\Types\TypeKind;

/**
 * The declared variable namespaces (Part VII §5.7 plus Part VIII §11.0).
 *
 * A symbol table is built **per decision point**, and it is the ceiling on what
 * a rule can see: a rule at `attendance.day.classify` cannot read `salary.*`
 * because that namespace is not in its table — a publish-time error, and there
 * is no binding for it at evaluation either (Part VII §10.1c-4, two gates, one
 * static and one dynamic).
 *
 * That is the whole data-access control. The engine holds no connection, no
 * repository and no container; the context is a finite typed value object
 * assembled by the domain service after authorisation. There is no symbol that
 * can reach a row the context does not contain, and no accessor anywhere takes a
 * subject — so no expression can name another employee or another tenant.
 */
final readonly class SymbolTable
{
    /**
     * @param array<string, Symbol> $symbols  'employee.code' => Symbol
     * @param list<string> $namespaces
     * @param array<string, list<string>> $valueSets  set name => members
     * @param array<string, Type> $aggMetrics  measure/ledger code => result type
     */
    private function __construct(
        private array $symbols,
        public array $namespaces,
        private array $valueSets = [],
        private array $aggMetrics = [],
    ) {
    }

    /** Everything the blueprint declares. A decision point restricts it. */
    public static function catalogue(): self
    {
        $symbols = self::definitions();
        $namespaces = [];

        foreach (array_keys($symbols) as $key) {
            $namespaces[strstr($key, '.', true) ?: $key] = true;
        }

        $namespaces['agg'] = true;      // §5.9, resolved from the prepared context
        $namespaces['event'] = true;    // §5.9.3, legal inside a predicate block only

        return new self($symbols, array_keys($namespaces), [], AggMetrics::types());
    }

    /** The decision point's declaration: these namespaces and no others. */
    public static function forNamespaces(string ...$namespaces): self
    {
        return self::catalogue()->restrictedTo(...$namespaces);
    }

    public function restrictedTo(string ...$namespaces): self
    {
        // `event` rides along with `agg`: a predicate block is the only place it
        // resolves, and TypeChecker enforces that scoping.
        if (in_array('agg', $namespaces, true)) {
            $namespaces[] = 'event';
        }

        $kept = [];

        foreach ($this->symbols as $key => $symbol) {
            if (in_array(strstr($key, '.', true), $namespaces, true)) {
                $kept[$key] = $symbol;
            }
        }

        return new self($kept, array_values(array_unique($namespaces)), $this->valueSets, $this->aggMetrics);
    }

    /**
     * R-VII-015: a decision point may declare selected symbols not-null-required.
     * A null in one of these fails the evaluation with ERR_MISSING_INPUT rather
     * than quietly not matching — because SmartPRS 2's paidLeaveDays():1065
     * treats an unknown leave type as PAID, a silent default that costs money.
     */
    public function requiringNotNull(string ...$symbols): self
    {
        $updated = $this->symbols;

        foreach ($symbols as $symbol) {
            if (!isset($updated[$symbol])) {
                throw new \InvalidArgumentException("Cannot require not-null on undeclared symbol '{$symbol}'.");
            }

            $updated[$symbol] = $updated[$symbol]->requiringNotNull();
        }

        return new self($updated, $this->namespaces, $this->valueSets, $this->aggMetrics);
    }

    /** @param list<string> $members */
    public function withValueSet(string $name, array $members): self
    {
        return new self(
            $this->symbols,
            $this->namespaces,
            [$name => $members] + $this->valueSets,
            $this->aggMetrics,
        );
    }

    /** @param array<string, Type> $types  key => declared type */
    public function withKeyedTypes(string $symbol, array $types): self
    {
        if (!isset($this->symbols[$symbol])) {
            throw new \InvalidArgumentException("Cannot type keys of undeclared symbol '{$symbol}'.");
        }

        $updated = $this->symbols;
        $updated[$symbol] = $updated[$symbol]->withKeyedTypes($types);

        return new self($updated, $this->namespaces, $this->valueSets, $this->aggMetrics);
    }

    /** Declare an aggregation measure or ledger and the type it returns (§5.9.2). */
    public function withAggMetric(string $code, Type $type): self
    {
        return new self(
            $this->symbols,
            $this->namespaces,
            $this->valueSets,
            [$code => $type] + $this->aggMetrics,
        );
    }

    public function hasNamespace(string $namespace): bool
    {
        return in_array($namespace, $this->namespaces, true);
    }

    public function symbol(string $key): ?Symbol
    {
        return $this->symbols[$key] ?? null;
    }

    /** @return list<string> */
    public function symbolNames(): array
    {
        return array_keys($this->symbols);
    }

    public function aggMetric(string $code): ?Type
    {
        return $this->aggMetrics[$code] ?? null;
    }

    /** Null when the set is not declared here: membership is then unchecked. */
    public function valueSet(string $name): ?array
    {
        return $this->valueSets[$name] ?? null;
    }

    /**
     * THE NAMESPACE CATALOGUE — Part VII §5.7, Part VIII §11.0.
     *
     * Each entry is a declared symbol, its type, and for a keyed accessor its
     * parameter types. Nothing here takes a subject; nothing here is a name.
     * `salary.component('BASIC')` is by CODE, never by name, because deciding PF
     * eligibility with str_contains(name, 'basic') is SmartPRS 2's most
     * reproducible defect family (AppDataController:2173).
     *
     * @return array<string, Symbol>
     */
    private static function definitions(): array
    {
        $symbols = [
            // --- employee.* -------------------------------------------------
            'employee.code' => new Symbol(Type::text()),
            'employee.uuid' => new Symbol(Type::text()),
            'employee.joining_date' => new Symbol(Type::date()),
            'employee.exit_date' => new Symbol(Type::date()),
            'employee.stage' => self::codeSymbol('employment_stage'),
            'employee.category' => self::codeSymbol('employee_category'),
            'employee.employment_type' => self::codeSymbol('employment_type'),
            'employee.gender' => self::codeSymbol('gender'),
            'employee.dob' => new Symbol(Type::date()),
            'employee.is_active' => new Symbol(Type::boolean()),
            'employee.custom' => self::keyedSymbol(Type::text(), Type::code()),

            // --- service.* --------------------------------------------------
            'service.years' => new Symbol(Type::number()),
            'service.months' => new Symbol(Type::number()),
            'service.days' => new Symbol(Type::number()),
            'service.completed_years' => new Symbol(Type::number()),

            // --- period.* ---------------------------------------------------
            'period.start' => new Symbol(Type::date()),
            'period.end' => new Symbol(Type::date()),
            'period.month' => new Symbol(Type::number()),
            'period.year' => new Symbol(Type::number()),
            'period.fin_year' => new Symbol(Type::text()),
            // Days, not Number. A proration factor is
            // payable_days / working_days, and that is only dimensionless — a
            // Number — when both sides carry the same unit. Declared as Number
            // these read Days / Number = Days, so every one of Part VIII's five
            // proration formulas failed to type against the engine meant to run
            // them. "22 working days" IS a quantity of days; calling it a count
            // was the error, and it is the same unit confusion Part VIII §5.3
            // exists to stop, one level up in the symbol table.
            'period.working_days' => new Symbol(Type::days()),
            'period.calendar_days' => new Symbol(Type::days()),
            'period.payable_days' => new Symbol(Type::days()),
            'period.cut_off_day' => new Symbol(Type::number()),
            'period.code' => self::codeSymbol('payroll_period'),
            'period.periods_per_year' => new Symbol(Type::number()),
            'period.fin_year_start' => new Symbol(Type::date()),
            'period.fin_year_end' => new Symbol(Type::date()),

            // --- shift.* ----------------------------------------------------
            'shift.code' => self::codeSymbol('shift'),
            'shift.start' => new Symbol(Type::time()),
            'shift.end' => new Symbol(Type::time()),
            'shift.hours' => new Symbol(Type::hours()),
            'shift.grace_in' => new Symbol(Type::minutes()),
            'shift.grace_out' => new Symbol(Type::minutes()),
            'shift.is_night' => new Symbol(Type::boolean()),
            'shift.full_day_minutes' => new Symbol(Type::minutes()),
            'shift.half_day_minutes' => new Symbol(Type::minutes()),
            'shift.break_budget_minutes' => new Symbol(Type::minutes()),
            'shift.source' => self::codeSymbol('shift_source'),

            // --- day.* ------------------------------------------------------
            'day.date' => new Symbol(Type::date()),
            'day.weekday' => self::codeSymbol('weekday'),
            'day.is_weekly_off' => new Symbol(Type::boolean()),
            'day.is_holiday' => new Symbol(Type::boolean()),
            'day.holiday_type' => self::codeSymbol('holiday_type'),
            'day.is_working_day' => new Symbol(Type::boolean()),
            'day.status' => self::codeSymbol('attendance_status'),
            'day.day_type' => self::codeSymbol('day_type'),

            // --- attendance.* — instants, never minutes-since-midnight -------
            'attendance.first_in' => new Symbol(Type::dateTime()),
            'attendance.last_out' => new Symbol(Type::dateTime()),
            'attendance.punch_count' => new Symbol(Type::number()),
            'attendance.session_count' => new Symbol(Type::number()),
            'attendance.worked_minutes' => new Symbol(Type::minutes()),
            'attendance.late_minutes' => new Symbol(Type::minutes()),
            'attendance.early_exit_minutes' => new Symbol(Type::minutes()),
            'attendance.late_exit_minutes' => new Symbol(Type::minutes()),
            'attendance.break_minutes' => new Symbol(Type::minutes()),
            'attendance.shortfall_minutes' => new Symbol(Type::minutes()),
            'attendance.ot_minutes' => new Symbol(Type::minutes()),
            'attendance.status' => self::codeSymbol('attendance_status'),
            'attendance.has_in' => new Symbol(Type::boolean()),
            'attendance.has_out' => new Symbol(Type::boolean()),
            'attendance.source_mix' => new Symbol(Type::listOf(Type::code('punch_source'))),
            'attendance.is_corrected' => new Symbol(Type::boolean()),

            // --- leave.* ----------------------------------------------------
            'leave.type' => self::codeSymbol('leave_type'),
            'leave.units' => new Symbol(Type::days()),
            'leave.is_paid' => new Symbol(Type::boolean()),
            'leave.is_statutory' => new Symbol(Type::boolean()),
            'leave.from' => new Symbol(Type::date()),
            'leave.to' => new Symbol(Type::date()),
            'leave.day_part' => self::codeSymbol('day_part'),
            'leave.balance' => self::keyedSymbol(Type::days(), Type::code('leave_type')),
            'leave.entitlement' => self::keyedSymbol(Type::days(), Type::code('leave_type')),
            'leave.pending' => self::keyedSymbol(Type::days(), Type::code('leave_type')),
            'leave.on_date' => self::keyedSymbol(Type::code('leave_type'), Type::date()),

            // --- salary.* — by CODE, never by name ---------------------------
            'salary.component' => self::keyedSymbol(Type::money(), Type::code('salary_component')),
            'salary.gross' => new Symbol(Type::money()),
            'salary.ctc' => new Symbol(Type::money()),
            'salary.basic' => new Symbol(Type::money()),
            'salary.net' => new Symbol(Type::money()),
            'salary.has_component' => self::keyedSymbol(Type::boolean(), Type::code('salary_component')),
            'salary.flag' => self::keyedSymbol(Type::boolean(), Type::code('salary_component'), Type::code('component_flag')),
            'salary.wage_base' => self::keyedSymbol(Type::money(), Type::code('wage_base')),
            'salary.balance_pool' => new Symbol(Type::money()),
            'salary.net_after_statutory' => new Symbol(Type::money()),

            // --- statutory.* — no threshold is ever a literal in a rule ------
            // The type comes from the CODE, and ultimately from the signed
            // rule pack that carries the value (spine S12). StatutoryKeys is the
            // shipped default so a checkout with no pack installed still
            // type-checks; a resolved pack overrides it with withKeyedTypes().
            'statutory.value' => self::keyedSymbol(Type::money(), Type::code('statutory_rule'))
                ->withKeyedTypes(StatutoryKeys::types()),
            'statutory.applies' => self::keyedSymbol(Type::boolean(), Type::code('statutory_rule')),
            'statutory.pack_version' => self::keyedSymbol(Type::text(), Type::code('statute')),

            // --- credential.* / compliance.* — the DRA gate ------------------
            'credential.exists' => self::keyedSymbol(Type::boolean(), Type::code('credential_type')),
            'credential.status' => self::keyedSymbol(Type::code('credential_status'), Type::code('credential_type')),
            'credential.expiry' => self::keyedSymbol(Type::date(), Type::code('credential_type')),
            'credential.days_to_expiry' => self::keyedSymbol(Type::number(), Type::code('credential_type')),
            'credential.is_valid' => self::keyedSymbol(Type::boolean(), Type::code('credential_type'), Type::date()),
            'compliance.score' => new Symbol(Type::number()),
            'compliance.open_cases' => self::keyedSymbol(Type::number(), Type::code('compliance_category')),
            'compliance.risk_level' => self::codeSymbol('risk_level'),

            // --- request.* / run.* / policy.* / context.* --------------------
            'request.type' => self::codeSymbol('request_type'),
            'request.amount' => new Symbol(Type::money()),
            'request.from' => new Symbol(Type::date()),
            'request.to' => new Symbol(Type::date()),
            'request.field' => self::keyedSymbol(Type::text(), Type::code()),
            'run.type' => self::codeSymbol('run_type'),
            'run.lot' => new Symbol(Type::number()),
            // A policy's type comes from its KEY, through PolicyKeys — the
            // mechanism Symbol::$keyedTypes was built for and nothing had filled
            // in. Until it was filled in, `policy.value` was Number for every key,
            // and sixteen of the twenty-five worked rules in Part VII §12 —
            // RL-ATT-001, the Section 127 acceptance test, among them — did not
            // type-check against the engine meant to run them: Minutes compared
            // with Number, NOT applied to a Number, IN given a Number.
            //
            // An undeclared key is now a publish-time error naming the key, so a
            // typo in a policy name is caught at authoring rather than read as a
            // missing value at runtime.
            'policy.value' => self::keyedSymbol(Type::number(), Type::code('policy_key'))
                ->withKeyedTypes(PolicyKeys::types()),
            'context.event' => self::codeSymbol('money_point'),
            'context.evaluation_date' => new Symbol(Type::date()),
            'context.tenant_timezone' => new Symbol(Type::text()),

            // --- component.* and structure.* ---------------------------------
            //
            // Used by Part VIII's worked formulas and declared in no namespace
            // table — §11.0 lists seven namespaces and neither of these is among
            // them. The concepts are real: the LOP deduction genuinely IS the
            // difference between a component's full and prorated amounts, and a
            // balance component genuinely needs the weights the structure
            // declares. The gap was in §11.0, not in the formulas.
            //
            // component.* is THE COMPONENT BEING CALCULATED — the one this
            // formula is bound to. There is no accessor that names another
            // component here; `salary.component('CODE')` is that, and it is by
            // code, never by name. So a formula cannot read a sibling's
            // prorated amount by accident.
            'component.code' => self::codeSymbol('salary_component'),
            'component.version' => new Symbol(Type::text()),
            'component.full_amount' => new Symbol(Type::money()),
            'component.prorated_amount' => new Symbol(Type::money()),
            'component.recomputed_amount' => new Symbol(Type::money()),
            'component.eligibility' => new Symbol(Type::boolean()),

            // structure.* is the resolved salary structure this employee is on.
            // balance_weights feeds ALLOCATE, so it is a List<Number> — the
            // weights, not the amounts, because ALLOCATE's residue-to-last rule
            // (Part VIII §6.4) is what turns weights into exact paise.
            'structure.version' => new Symbol(Type::text()),
            'structure.resolved' => new Symbol(Type::boolean()),
            'structure.balance_weights' => new Symbol(Type::listOf(Type::number())),

            // --- Part VIII §11.0 --------------------------------------------
            'settlement.payable_days' => new Symbol(Type::days()),
            'settlement.scheduled_payable_days' => new Symbol(Type::days()),
            'settlement.absent_days' => new Symbol(Type::days()),
            'settlement.lop_days' => new Symbol(Type::days()),
            'settlement.present_days' => new Symbol(Type::days()),
            'settlement.weekly_off_days' => new Symbol(Type::days()),
            'settlement.holiday_days' => new Symbol(Type::days()),
            'settlement.leave_days' => self::keyedSymbol(Type::days(), Type::code('leave_type')),
            'settlement.ot_minutes' => new Symbol(Type::minutes()),
            'settlement.late_count' => new Symbol(Type::number()),
            'settlement.shortfall_minutes' => new Symbol(Type::minutes()),
            'settlement.compoff_earned' => new Symbol(Type::days()),
            'settlement.compoff_availed' => new Symbol(Type::days()),
            'settlement.generation' => new Symbol(Type::number()),
            'ytd.gross' => new Symbol(Type::money()),
            'ytd.component' => self::keyedSymbol(Type::money(), Type::code('salary_component')),
            'ytd.statutory' => self::keyedSymbol(Type::money(), Type::code('statutory_head')),
            'ytd.taxable_income' => new Symbol(Type::money()),
            'ytd.tax_deducted' => new Symbol(Type::money()),
            'ytd.periods_run' => new Symbol(Type::number()),
            'input.value' => self::keyedSymbol(Type::money(), Type::code('input_code')),
            'input.recoveries_due' => new Symbol(Type::money()),
            'input.has' => self::keyedSymbol(Type::boolean(), Type::code('input_code')),
            'claim.approved_amount' => new Symbol(Type::money()),
            'claim.claimed_amount' => new Symbol(Type::money()),
            'claim.type' => self::codeSymbol('claim_type'),
            'claim.incurred_on' => new Symbol(Type::date()),
            'loan.principal' => new Symbol(Type::money()),
            'loan.instalment' => new Symbol(Type::money()),
            'loan.outstanding' => new Symbol(Type::money()),
            'loan.instalments_paid' => new Symbol(Type::number()),
            'loan.instalments_total' => new Symbol(Type::number()),
            'loan.rate' => new Symbol(Type::percentage()),
            'loan.moratorium_periods' => new Symbol(Type::number()),
            'prior.line_amount' => self::keyedSymbol(Type::money(), Type::code('salary_component')),
            'prior.gross' => new Symbol(Type::money()),
            'prior.net' => new Symbol(Type::money()),
            'prior.run_version_id' => new Symbol(Type::text()),
            'formula.value' => self::keyedSymbol(Type::money(), Type::code('formula')),

            // --- event.* — inside an agg predicate block only (§5.9.3) -------
            'event.date' => new Symbol(Type::date()),
            'event.instant' => new Symbol(Type::dateTime()),
            'event.value' => new Symbol(Type::number()),
            'event.source' => self::codeSymbol('punch_source'),
            'event.status' => self::codeSymbol('event_status'),
            'event.shift' => self::codeSymbol('shift'),
            'event.branch' => self::codeSymbol('org_unit'),
        ];

        // org.* — every dimension is a Code, nullable, resolved from the
        // effective-dated assignment row as at the evaluation date. Never a
        // name: SmartPRS 2 compares company_name strings, so a rename breaks it.
        foreach ([
            'legal_entity', 'company', 'establishment', 'state', 'zone', 'branch', 'department',
            'sub_department', 'team', 'cost_centre', 'business_unit', 'client_process',
            'designation', 'grade', 'location', 'pay_group',
        ] as $dimension) {
            $symbols['org.' . $dimension] = self::codeSymbol('org_unit');
        }

        $symbols['org.reports_to_code'] = new Symbol(Type::text());
        $symbols['org.is_in'] = self::keyedSymbol(Type::boolean(), Type::code('org_dimension'), Type::code('org_unit'));

        return $symbols;
    }

    /** A plain Code symbol, optionally bound to a declared value set. */
    private static function codeSymbol(?string $valueSet = null): Symbol
    {
        return new Symbol(Type::code($valueSet));
    }

    /** A keyed accessor: `salary.component('BASIC')`, `org.is_in(dim, unit)`. */
    private static function keyedSymbol(Type $type, Type ...$params): Symbol
    {
        return new Symbol($type, $params);
    }

    /** Every namespace the grammar knows, declared or not. Used for better errors. */
    public const KNOWN_NAMESPACES = [
        'employee', 'org', 'service', 'period', 'shift', 'day', 'attendance', 'leave', 'salary',
        'statutory', 'policy', 'credential', 'compliance', 'request', 'run', 'context',
        'settlement', 'ytd', 'input', 'claim', 'loan', 'prior', 'formula', 'agg', 'event',
    ];

    /** The value sets a Code type may name, for a clearer publish-time message. */
    public function describesValueSet(Type $type): bool
    {
        return $type->is(TypeKind::Code) && $type->parameter !== null && isset($this->valueSets[$type->parameter]);
    }
}
