<?php

declare(strict_types=1);

/**
 * THE CONFORMANCE CASE CLASSES OF PART XXX §XXX.3, VERBATIM.
 *
 * This is the list, and the runner reports every entry in it — as PASS, FAIL,
 * or NOT COVERED. A conformance suite that silently omits the case classes
 * nobody has written yet reports the same green as one that covers them all,
 * which is the vacuous-gate failure this repository has already been bitten by
 * once. So the denominator lives here, in a file that is the blueprint's table
 * and nothing else, and only the numerator is earned.
 *
 * R-XXX-020: each suite is located outside every module namespace and depends
 * on no module. R-XXX-021: each case is a declarative fixture in a data file,
 * not a PHP method, "so that the same corpus is runnable against a future
 * re-implementation of an engine, which is the point".
 *
 * @return array<string, array<string, string>> engine => case class => what it must assert
 */

return [
    'SPX' => [
        'SPX-01' => 'Grammar — accept. Valid expressions parse; the AST round-trips to canonical source and back to an identical AST',
        'SPX-02' => 'Grammar — reject. Assignment, loops, host escapes, unbalanced parentheses, unknown function, unknown namespace: rejected at publish, naming the token and position',
        'SPX-03' => 'Type checking. Money * Money, Days + Hours, Money + Number are publish-time type errors naming both types; Money / Number and Money * Number succeed',
        'SPX-04' => 'No silent coercion. Days and Hours in one expression is a type error',
        'SPX-05' => 'Percent-of, not modulo. X % 40 is 40 percent of X; MOD is the remainder',
        'SPX-06' => 'ROUND requires a mode. ROUND(x) with no mode is a publish-time error',
        'SPX-07' => 'Determinism. The corpus evaluated repeatedly, in shuffled order, is byte-identical every time',
        'SPX-08' => 'No clock, no randomness. context.evaluation_date is the only temporal input; changing the host clock changes nothing',
        'SPX-09' => 'Budgets. Node count and depth at the limit and one above; each yields a typed budget error, never silently swallowed',
        'SPX-10' => 'Totality. Division by zero, SLAB miss, BANDS miss, COALESCE over all-null: each a typed error value that propagates',
        'SPX-11' => 'Fail closed on error. An evaluation error inside a rule that would otherwise permit an action: the action does not apply',
        'SPX-12' => 'Stored AST wins. A published expression evaluates identically after a parser change, because the AST is stored',
        'SPX-13' => 'Symbol table. A namespace not declared for the evaluation context is a publish-time error naming it',
        'SPX-14' => 'Date functions. CLAMP_DAY_TO_MONTH, EOMONTH/SOMONTH at boundaries, NTH_WEEKDAY against an independently generated calendar table',
        'SPX-15' => 'MATCHES is anchored and bounded. A catastrophic-backtracking pattern terminates with a typed error',
    ],

    'RULES' => [
        'RC-01' => 'Purity. Evaluating any rule set writes nothing; the engine returns AppliedAction[] + DecisionTrace and the caller applies them',
        'RC-02' => 'Specificity precedence. The higher summed weight wins, against an independently computed weight table',
        'RC-03' => 'Priority tie-break. The higher priority wins, and the trace names the loser and why it lost',
        'RC-04' => 'No third tie-break. Equal specificity and priority with satisfiable overlap BLOCKS publication, naming both artefacts',
        'RC-05' => 'Conflict detection is sound. A narrow overlap is detected; a provably disjoint pair is NOT reported',
        'RC-06' => 'on_conflict semantics. first_match, all_match and highest_priority_only give three declared, traced outcomes',
        'RC-07' => 'except short-circuits. A rule whose when and except are both true does not apply, and the trace records why',
        'RC-08' => 'Effective-date resolution. The version in force ON THE EVALUATION DATE applies, never the newest-published',
        'RC-09' => 'Aggregation windows. Exact counts at both boundaries of every declared window type',
        'RC-10' => 'agg.occurrence_index across a window with a cap. The Nth occurrence carries index N and the cap stops further penalties',
        'RC-11' => 'agg.collapse_repeats at the window boundary ±1 second. Deterministic, and collapsed events stay retrievable',
        'RC-12' => 'agg.streak forwards and backwards over a gap. Exact length, with the terminating event named',
        'RC-13' => 'agg.balance against a ledger with a superseded entry. Reads the ledger as at the date, not the current row set',
        'RC-14' => 'Trace completeness. Inputs, rule code, version, matched condition, actions and losing candidates; a trace-less result throws',
        'RC-15' => 'Action catalogue is closed. A rule referencing an action outside the decision point\'s catalogue is rejected at publish',
        'RC-16' => 'Simulator equals production. The same subject and date through both paths gives identical actions and traces, byte for byte',
        'RC-17' => 'Stored regression cases run at publish. A rule whose stored expectations no longer hold is refused',
        'RC-18' => 'Fail closed. Fault injection at symbol resolution, aggregation, ledger read and scope resolution leaks no partial action set',
        'RC-19' => 'Impact preview is exact. The previewed changed-employee count and the actual differ by zero',
        'RC-20' => 'Determinism under parallelism. The same corpus across many workers gives identical outcomes and traces',
    ],

    'FORMULA' => [
        'FC-01' => 'Integer paise throughout. No float appears in any intermediate of a dependency graph',
        'FC-02' => 'Division is exact rational until an explicit ROUND. (₹1,00,000 ÷ 3) × 3 is exactly ₹1,00,000.00',
        'FC-03' => 'ALLOCATE sums to the whole, for randomised (amount, weights) tuples including zero-sum, single and many-element cases',
        'FC-04' => 'The residue case. A prorated three-component split sums to the prorated gross exactly; naive independent rounding fails it',
        'FC-05' => 'Rounding modes. Each declared mode at .005, .015, .025 and at negative values; a component with no declared mode fails at publish',
        'FC-06' => 'Rounding points are declared and honoured. Rounding at the component versus at the total gives two correct, traced results',
        'FC-07' => 'Cycle rejection. A → B → C → A and a self-reference are rejected at publish, naming the full cycle path',
        'FC-08' => 'Topological evaluation. A graph declared in reverse dependency order evaluates correctly',
        'FC-09' => 'Trace substitution. The trace renders source, substituted values, each step, the rounding mode and unit, and the artefact version',
        'FC-10' => 'Effective-dated formulas. A version published in October effective 1-Oct is not used for an August evaluation',
        'FC-11' => 'Currency is carried. Adding two Money values of different currencies is a type error, not a silent addition',
        'FC-12' => 'Zero and negative. A disallowed negative is a named failure, never a silent max(0, …)',
        'FC-13' => 'Determinism. The corpus evaluated repeatedly in shuffled order is byte-identical',
        'FC-14' => 'Publish-time sample assertion. A formula whose named sample expectations no longer hold is refused',
    ],

    'WORKFLOW' => [
        'WC-01' => 'A decided step is immutable. The second decision is refused; the first decision, actor, timestamp and comment are unchanged',
        'WC-02' => 'Concurrent decisions. Exactly one succeeds, the other gets a conflict, and exactly one transition row exists',
        'WC-03' => 'Every node type has declared semantics, including quorum at n−1, n and n+1 approvals',
        'WC-04' => 'In-flight version pinning. An instance mid-flight on v1 completes on v1 while a new instance starts on v2',
        'WC-05' => 'SLA and escalation by clock advance. Breach−1s and breach+1s: no escalation, then exactly one',
        'WC-06' => 'Self-approval prohibition. The requester holding the approver role is refused, routed per the declared fallback, and traced',
        'WC-07' => 'Maker-checker shapes. Every shape uses the same engine; zero module-specific approval code exists',
        'WC-08' => 'Every transition writes audit. Actor, timestamp, decision and comment present; an audit write failure fails the transaction',
        'WC-09' => 'Delegation and backup approvers. Active, expired and circular; a circular delegation is refused at configuration time',
        'WC-10' => 'Amount-based routing. Values at, below and above each threshold select the exact route, traced',
        'WC-11' => 'Return-for-edit preserves history. Prior decisions stay visible and the resubmission is a new step, not an overwrite',
        'WC-12' => 'Cancellation is terminal. Any transition after cancel is refused',
    ],
];
