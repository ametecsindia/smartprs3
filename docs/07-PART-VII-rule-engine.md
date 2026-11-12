# PART VII — THE UNIVERSAL RULE ENGINE

Status: DRAFT FOR PRODUCT OWNER APPROVAL
Conforms to: SPINE.md — S1 (seven laws), S3.1/S3.2 (scope and precedence), S4
(effective dating), S5 (SPX), S6 (rule engine contract), S7 (formula engine
boundary), S8 (workflow boundary), S14 (audit), S15 (explainability), S16 (data
conventions).

---

## 1. Purpose

This part specifies, to implementation grade, the single component on which the
entire SmartPRS 3 thesis rests: **the mechanism by which a customer's business
policy is expressed as data and evaluated deterministically, rather than written
in PHP and deployed.** It fixes the rule model, the SPX condition language, the
aggregation primitives, the closed typed action catalogue, the evaluation and
conflict-resolution semantics, the decision trace, the safety argument, the
authoring tools, the performance envelope, and the migration of SmartPRS 2's
hard-coded behaviour into seeded rules.

The acceptance test for this part is the governing brief's Section 127 standard:
**"3 late arrivals over 10 minutes = half day" must not require code.** Section
12 of this part writes that rule, and fifteen more like it, in full SPX, each
traced to the SmartPRS 2 hard-coding it replaces.

---

## 2. What SmartPRS 2 does today, and why it must change

SmartPRS 2 has no rule engine. It has approximately 950 distinct business rules
that were counted, named and evidenced by the as-is analysis — 566 distinct
`BR-*` identifiers carried forward from the reference catalogue plus 382 `NEW-*`
rules the catalogue never recorded — and of those, the overwhelming majority are
`if` statements, `private const` arrays and literal thresholds inside
controllers.

Seven properties of that situation are the direct cause of seven design
decisions in this part.

| # | SmartPRS 2 property | Evidence | Consequence | This part's answer |
|---|---|---|---|---|
| 1 | **A business rule is a literal in a controller.** Three late tiers and no fourth; a fall-through that charges sub-L1 lateness at the L1 rate; a break penalty fixed at `ceil(excess/30) × 0.25` day | `PayrollGenController.php:482-495`, `:494`, `:469` | Any variation is a code change and a release to every on-premise customer | §3 rule model, §6 action catalogue, §12 rule library |
| 2 | **Rule scope is three levels resolved by row order.** `employee(2) > team(1) > company(0)`, and ties break on `if ($tr > $rank)` — whichever row the database returned first | `LateArrivalService.php:184-204`, and three clones at `AttendanceReportController.php:800-822`, `TodayController.php:114-134`, `PayrollGenController.php:279-295` | The same employee can be governed by a different policy depending on query plan; no branch, department, designation, grade or location scope exists at all | §8 precedence, applying spine S3.2; ties **block publication** |
| 3 | **No rule is effective-dated.** A late policy edited in October rewrites August's payroll on the next regeneration | `late_policy` has no effective columns (`MasterController.php:237-241`); §5.2 b.23 of the attendance analysis | Historic payroll is not reproducible and not defensible | §3 versioning per spine S4; §9 trace pins version ids |
| 4 | **The same rule is implemented four times, differently.** Late minutes are computed in four places; working days have **three incompatible definitions**; presence has four | `AttendanceReportController.php:834-836`, `LateArrivalService.php:122-131`, `TodayController.php:142-145`, `PayrollGenController.php:437-439`; working days at `PayrollGenController::isWeekOff():70-89` vs `monthMeta():178-196` vs `PointsController::monthMeta():239-263` vs `AbsenceService.php:45,169` | Two screens disagree about whether an employee was late, and both are "the system" | §7 one evaluator, one decision point per question |
| 5 | **Rule outcomes are not explainable.** The payslip recovers Paid Days and LOP by **regex over a free-text sentence** — `/Paid\s+([\d.]+)\s+of\s+(\d+)\s+days/i` — and prints `daysInMonth / 0` when the sentence is absent | `AppDataController.php:1278-1282`; sentence emitted only when `factor < 0.9999` at `calcNote():537-540` | No number can be defended to an employee, an auditor or a labour officer | §9 decision trace, satisfying Law 4 |
| 6 | **Errors inside rule paths are swallowed.** `denyUnlessRole()` fails **open** on any exception across 71 call sites; `EffectiveDated::guardFinalisedPeriod()` fails open while `PayrollLock::denyUnlessSuperAdmin()` in the same call chain fails closed | `ApprovalService.php:365-380`; `EffectiveDated.php:115-117` vs `PayrollLock.php:91-96` (NEW-PAY-26, NEW-REQ-106) | A failed rule silently produces the permissive answer | §7.8 evaluation errors surface as a hard failure; §10 safety |
| 7 | **The one rule that is done well is not generalisable.** The DRA money gate — `off`/`warn`/`block`, per tenant, at three money points — is the product's strongest differentiator and is still a hard-coded `if` on one credential type at three call sites | `SettingsController.php:57,154`; `RequestController.php:1366-1394`; `IncentiveController.php:135`; `OffrollAgentController.php:249-262` | The gate cannot be applied to PCC, BGV or agent authorisation, and cannot be applied at a fourth money point | §12 rule RL-CMP-001 generalises it to any credential at any declared money point |

Two further findings shape the safety section specifically. SmartPRS 2 executes
**runtime DDL from request handlers** (`ApprovalService::ensureAuditCols():46-260`
runs `CREATE TABLE`/`ALTER TABLE` on nine tables on every inbox load), and it
accepts an admin-supplied provider URL and POSTs credentials to it with no scheme
or host allow-list (`SendMessageController::postProvider():234-266`, SEC-12).
Both are the shape of "configuration that reaches outside the sandbox". SPX is
specified so that neither is expressible.

**We are not replicating any of these defects.** Each is named at the design
decision it caused.

---

## 3. Scope — what the rule engine decides, and what it does not

### 3.1 The division of labour

SmartPRS 3 has three configuration engines. They are not interchangeable, and
the boundary is enforced by the type of thing each returns.

| Engine | Answers | Returns | Spine | May it mutate state? |
|---|---|---|---|---|
| **Rule engine** (this part) | *What outcome applies?* | An ordered list of typed `AppliedAction` + a `DecisionTrace` | S6 | **No.** Never. The calling domain service applies the actions in its own transaction |
| **Formula engine** (Part VIII) | *What amount?* | One typed value (`Money`, `Days`, `Number`) + a `CalculationTrace` | S7 | No |
| **Workflow engine** (Part IX) | *Who must agree, in what order, by when?* | A workflow instance and its transitions | S8 | Yes — it owns its own instance state |

The three compose in exactly one direction:

```
rule engine  → may emit an action whose parameter is a formula reference
             → may emit REQUIRE_APPROVAL(workflow_code)
formula      → may be gated by a rule (eligibility), never the reverse
workflow     → may branch on an SPX condition (same language, S8)
             → may not decide a business outcome; it routes a decision
```

**R-VII-001** A rule MUST NOT compute a money amount inline. Where an action
needs an amount, its parameter is either a literal, an SPX expression typed to
`Money`, or a reference to a published formula. Amount *arithmetic of any
complexity* belongs to the formula engine, which owns the dependency graph, the
rounding declarations and the `ALLOCATE` residue discipline (spine S7).

**R-VII-002** A rule MUST NOT route an approval itself. It emits
`REQUIRE_APPROVAL(workflow_code)`; the workflow engine owns approver resolution,
SLA, escalation and immutability of decided steps.

**R-VII-003** The formula engine MUST NOT contain conditional business
eligibility beyond `IF`/`CASE` arithmetic on its own inputs. "Whether this
component applies to this employee" is a rule
(`SUPPRESS_COMPONENT` / `ADD_COMPONENT`), not a formula returning zero. *Rationale
— SmartPRS 2 hides eligibility inside calculators, which is why probation
suppression of PF, PT and TDS is invisible at `computeSlip():2114-2117` and
duplicated at `computeSlipFromComponents():2284-2287`.*

### 3.2 Decision points — the closed set of questions the engine is asked

A **decision point** is a named, versioned hook that a domain service calls. It
is the unit of safety: it declares which namespaces are readable, which actions
are emittable, what result shape the caller expects, and the default conflict
policy. **A rule exists only inside a decision point.** This is what makes the
symbol table finite and the sandbox provable.

**R-VII-004** Every rule MUST belong to exactly one decision point. A decision
point is platform-owned metadata seeded by migration (Law 2), never
tenant-created.

| Decision point | Called by | Subject | Namespaces available | Actions emittable | Default conflict policy |
|---|---|---|---|---|---|
| `attendance.day.classify` | Attendance day derivation | employee-day | employee, org, service, period, shift, day, attendance, leave, policy, agg | `SET_STATUS`, `FLAG_FOR_REVIEW`, `NOTIFY` | `highest_priority_only` |
| `attendance.day.penalise` | Attendance day derivation, after classify | employee-day | as above + `day.status` | `APPLY_DAY_PENALTY`, `APPLY_LOP`, `OFFSET_SHORTFALL`, `FLAG_FOR_REVIEW`, `NOTIFY` | `all_match` |
| `attendance.day.earn` | Attendance day derivation | employee-day | as above | `CREATE_OT`, `CREATE_COMP_OFF`, `NOTIFY` | `all_match` |
| `attendance.calendar.weekly_off` | Calendar materialisation | employee-day | employee, org, day, period, policy | `SET_DAY_TYPE` | `highest_priority_only` |
| `attendance.grace` | Late computation | employee-day | employee, org, shift, day, attendance, policy | `SET_GRACE` | `highest_priority_only` |
| `leave.request.validate` | Leave application | leave request | employee, org, service, period, day, leave, policy, agg | `BLOCK`, `REQUIRE_DOCUMENT`, `CONVERT_TO`, `ROUTE_TO_WORKFLOW`, `NOTIFY` | `all_match` |
| `leave.request.charge` | Leave unit computation | leave request | as above + `day.*` per day in range | `DEDUCT`, `CHARGE_DAY`, `EXEMPT_DAY` | `all_match` |
| `payroll.component.eligibility` | Payroll pipeline, per component | employee-period-component | employee, org, service, period, salary, statutory, policy, agg | `ADD_COMPONENT`, `SUPPRESS_COMPONENT`, `SET_BASIS`, `CAP` | `all_match` |
| `payroll.recovery` | Payroll pipeline, recoveries | employee-period | employee, org, service, period, salary, policy, agg | `CREATE_RECOVERY`, `SUPPRESS_COMPONENT` | `all_match` |
| `payroll.run.gate` | Payroll preview | run | employee, org, period, salary, policy, agg | `GATE_PAYMENT`, `FLAG_FOR_REVIEW`, `REQUIRE_APPROVAL` | `all_match` |
| `compliance.payment_gate` | Every declared money point | employee + money event | employee, org, credential, compliance, policy, agg | `GATE_PAYMENT`, `RAISE_ITEM`, `SET_RISK`, `NOTIFY` | `all_match` |
| `compliance.item.raise` | Compliance scan | employee | employee, org, credential, compliance, day, policy | `RAISE_ITEM`, `SET_RISK`, `NOTIFY` | `all_match` |
| `platform.config.guard` | Configuration publish | artefact version | employee(n/a), org, policy, statutory | `BLOCK`, `REQUIRE_APPROVAL`, `WRITE_AUDIT` | `all_match` |

**R-VII-005** Adding a decision point is an Ametecs release activity: a seeded
`decision_points` row, a declared symbol table, a declared action allow-list, a
caller in a domain service, and a conformance test asserting the caller never
applies an action the decision point does not declare.

### 3.3 What is explicitly **not** a rule

| Not a rule | Where it lives | Why |
|---|---|---|
| Approver identity and chain | Workflow engine (S8) | Routing, not outcome |
| Rounding mode and unit for a money component | Component definition (S7, S12) | Rounding is a property of the value, not a decision |
| Statutory rates, ceilings, slabs, due dates | Signed rule pack (S12) | Third artefact class; Ametecs-signed, never tenant-editable |
| Field visibility and requiredness by role/surface | `field_surface_rules` (S9) | Metadata, evaluated by the entity engine; may *use* SPX for `required: conditional` |
| Data scope of a user (which employees they see) | Security policy (S13) | Authorisation must fail closed independently of any tenant-authored artefact. **A rule may never widen access** |
| Punch-to-employee identity mapping | Device adapter (S10.1) | Ingest concern |
| Retention and purge | Retention class (S16) | Platform lifecycle |

**R-VII-006** No rule, under any configuration, may affect authorisation, data
scope, tenancy, retention or audit completeness. The rule engine is invoked
**after** authorisation and **inside** an already tenant-scoped context.

---

## 4. The rule model

### 4.1 Field-by-field specification

A rule is a `config_artefact` of type `rule` with `config_versions` per spine S4.
Every field below is on the version, not the artefact, unless marked (A).

| Field | Type | Null | Meaning | Validation at publish | Effect |
|---|---|---|---|---|---|
| `code` (A) | `varchar(64)` | No | Stable business identifier, e.g. `HYD-LATE-003`. Appears in every trace | `^[A-Z][A-Z0-9-]{2,63}$`; unique per `(tenant_id, decision_point)` | Trace key; test fixtures reference it |
| `decision_point` (A) | `varchar(64)` FK | No | The hook this rule runs at (§3.2) | Must exist and be active | Fixes the symbol table and action allow-list |
| `module` (A) | `varchar(32)` | No | Owning module (`attendance`, `leave`, `payroll`, `compliance`, `platform`) | Must equal the decision point's module | Permission binding: `rules.publish.<module>` |
| `name` | `varchar(160)` | No | Human label shown in the builder and the trace | 3–160 chars | UI |
| `business_description` | `text` | No | Why this rule exists, in the customer's words | ≥ 20 chars — **mandatory**, no blank | Printed in the trace and the impact preview. *Because SmartPRS 2's `l1_cut` fall-through at `PayrollGenController.php:494` is a business rule nobody can find or explain* |
| `scope_json` | `json` | No | Spine S3.1 scope expression: `{dimension → IN[…] | ALL}` over the 21 dimensions | Every dimension key known; every value id resolvable in this tenant; `employee` scope limited to ≤ 500 explicit ids per version | Determines which employees the rule can match, and its specificity weight |
| `priority` | `int` | No, default `0` | Tie-break **within equal specificity only** (spine S3.2 step 2) | `-10000 … 10000` | Second-order ordering |
| `effective_from` | `date` | No | First date the version governs | Not before the artefact's first version | Resolution is always `as_of_date` |
| `effective_to` | `date` | Yes | Last date (null = open) | ≥ `effective_from`; no overlap with another published version of the same artefact **and scope** | Closed automatically on supersede |
| `version_no` | `int` | No | Monotonic per artefact | `= max+1` | Pinned into every trace |
| `status` | `code` | No | `draft | testing | pending_approval | published | superseded | archived` | Legal transitions only | Only `published` is evaluated |
| `when_src` | `text` | No | SPX Boolean source | Parses; type-checks to `Boolean`; symbols in the decision point's table; within budgets (§7.7) | The match condition |
| `when_ast` | `mediumblob` | No | Compiled, canonicalised AST | Written by the compiler, never by hand | **Evaluated artefact.** The source is retained for display and re-compilation diffing only (spine S5) |
| `except_src` | `text` | Yes | SPX Boolean; if true the rule does **not** apply | As `when_src` | Negative carve-out, evaluated after `when` |
| `except_ast` | `mediumblob` | Yes | Compiled AST | — | — |
| `then` | ordered child rows | No | 1–20 typed actions (§6) | Each action code in the decision point's allow-list; each parameter type-checks | The outcome |
| `on_conflict` | `code` | No | `first_match | all_match | highest_priority_only` | Must be one of the three; may narrow but not widen the decision point's default | §7.3 |
| `stop_on_match` | `bool` | No, default `false` | If true, a match ends evaluation of this decision point for this subject | Only permitted when `on_conflict = all_match` | Explicit early exit, traced |
| `severity` | `code` | No, default `normal` | `normal | high_risk` | `high_risk` forces diff + approval before publish (spine S4) | Governance |
| `requires_approval` | `bool` | No, default derived | Maker-checker on publication | Forced `true` when `severity = high_risk` or the rule emits a money-affecting action | Spine S8 maker-checker |
| `tags` | `json` array | Yes | Free labels for filtering (`statutory`, `seeded`, `migrated`) | ≤ 20 tags, each ≤ 32 chars | UI |
| `source_ref` | `varchar(255)` | Yes | For seeded rules: the SmartPRS 2 citation it replaces, e.g. `PayrollGenController.php:482-495` | — | Migration traceability; shown in the trace for seeded rules |
| `checksum` | `char(64)` | No | SHA-256 over the canonical AST + actions + scope + dates | Recomputed on publish | Tamper evidence, cache key |
| `audit_metadata` | `json` | No | `created_by`, `submitted_by`, `approved_by`, `approved_at`, `published_at`, `reason`, `change_note` | `reason` mandatory on `high_risk` | Spine S14 |

**R-VII-007** `when_src` MUST NOT be empty. A rule that always applies is written
`TRUE`, explicitly, so that "this rule has no condition" is a visible authored
decision rather than an empty string.

**R-VII-008** A rule version, once `published`, is **immutable in every field**.
Changing anything creates a new version. The prior version's `effective_to` is
closed to the day before the new `effective_from` (spine S4). *This is the direct
fix for SmartPRS 2, where editing `late_policy` rewrites history.*

**R-VII-009** `when_ast` is the evaluated artefact; `when_src` is documentation.
A parser upgrade MUST NOT change the behaviour of an already-published rule.
Re-compilation of a published rule is a release-time migration that produces a
**new** version with a diff, never an in-place rewrite.

### 4.2 Rule sets and packs

- A **rule set** is the set of published rules resolved for
  `(tenant, decision_point, subject, as_of_date)`. It is computed, not stored.
- A **rule pack** is a named, exportable bundle of rule artefacts —
  the unit of seeding, of tenant-to-tenant copying, and of the migration in §13.
  A pack has an id, a version, an owner (`ametecs` or `tenant`) and a signature
  when Ametecs-owned. **Ametecs-owned packs are read-only to a tenant**; a tenant
  overrides one by publishing a more specific rule, never by editing the pack.

**R-VII-010** Importing a rule pack MUST run the conflict detector (§11.4) and
the stored regression tests (§11.6) of both the pack and the tenant's existing
rules, and MUST be refused on any tie or any test failure.

---

## 5. The SPX condition language

This section expands spine S5 to implementation grade. Where it adds to S5 it
says so; it contradicts S5 nowhere.

### 5.1 Design constraints, restated as properties of the grammar

SPX has no assignment, no loop, no recursion, no user-defined function, no
lambda, no property assignment, no `import`, no host escape, no I/O and no
database access. These are not runtime prohibitions that could be bypassed —
**there is no production in the grammar that could express them.** The grammar
below is the whole language.

### 5.2 Lexical structure

```
whitespace   ::= " " | "\t" | "\r" | "\n"                  (* insignificant *)
comment      ::= "--" , { any_char - newline }             (* to end of line *)

identifier   ::= letter , { letter | digit | "_" }
letter       ::= "a".."z" | "A".."Z"
digit        ::= "0".."9"

number_lit   ::= digit , { digit } , [ "." , digit , { digit } ]
                 (* decimal only; no exponent, no hex, no octal, no separators *)
string_lit   ::= "'" , { any_char - "'" | "''" } , "'"
                 (* single-quoted; '' is a literal quote; max 512 chars *)
date_lit     ::= "#" , 4*digit , "-" , 2*digit , "-" , 2*digit , "#"
time_lit     ::= "#" , 2*digit , ":" , 2*digit , [ ":" , 2*digit ] , "#"
dur_lit      ::= number_lit , ( "d" | "h" | "m" | "s" )    (* Days/Hours/Minutes/Seconds *)
money_lit    ::= "₹" , digit , { digit | "," } , [ "." , 2*digit ]
bool_lit     ::= "TRUE" | "FALSE"
null_lit     ::= "NULL"
```

Keywords (reserved, case-insensitive, conventionally upper-case):
`AND OR NOT IN BETWEEN CONTAINS MATCHES CASE WHEN THEN ELSE END TRUE FALSE NULL`.
Identifiers and namespace paths are **case-sensitive**; keywords and function
names are case-insensitive. *SmartPRS 2's case sensitivity is accidental and
inconsistent — `AbsenceService.php:122` requires exactly `'active'` while
`AttendanceReportController.php:898-900` compares case-insensitively. SPX makes
the rule explicit rather than emergent.*

Maximum source length: 8,000 characters. Maximum literal list length: 500
elements.

### 5.3 Grammar (EBNF)

```ebnf
(* ---------- entry points ---------- *)
condition      ::= expression ;                 (* must type to Boolean *)
value_expr     ::= expression ;                 (* action parameters, formulas *)

(* ---------- expression, lowest precedence first ---------- *)
expression     ::= or_expr ;

or_expr        ::= and_expr , { "OR" , and_expr } ;

and_expr       ::= not_expr , { "AND" , not_expr } ;

not_expr       ::= [ "NOT" ] , comparison ;

comparison     ::= additive , [ comparison_tail ] ;      (* non-associative *)
comparison_tail::= rel_op , additive
                 | [ "NOT" ] , "IN" , list_operand
                 | "BETWEEN" , additive , "AND" , additive
                 | [ "NOT" ] , "CONTAINS" , additive
                 | "MATCHES" , string_lit ;
rel_op         ::= "=" | "<>" | "<" | "<=" | ">" | ">=" ;

additive       ::= multiplicative , { ( "+" | "-" ) , multiplicative } ;
multiplicative ::= percent_expr , { ( "*" | "/" ) , percent_expr } ;
percent_expr   ::= unary , { "%" , unary } ;             (* percent-of, NOT modulo *)
unary          ::= [ "-" ] , primary ;

primary        ::= literal
                 | variable_ref
                 | function_call
                 | agg_call
                 | case_expr
                 | list_literal
                 | "(" , expression , ")" ;

(* ---------- leaves ---------- *)
literal        ::= number_lit | string_lit | date_lit | time_lit
                 | dur_lit | money_lit | bool_lit | null_lit ;

variable_ref   ::= namespace , "." , symbol_path ;
namespace      ::= "employee" | "org" | "service" | "period" | "shift" | "day"
                 | "attendance" | "leave" | "salary" | "statutory" | "policy"
                 | "credential" | "compliance" | "request" | "run" | "context"
                 (* registered by Part VIII §11.0; see §5.7 *)
                 | "settlement" | "ytd" | "input" | "claim" | "loan"
                 | "prior" | "formula" ;
symbol_path    ::= identifier , { "." , identifier }
                 | identifier , "(" , key_arg , ")" ;     (* keyed accessor *)
key_arg        ::= string_lit ;                           (* a CODE, never a name *)

function_call  ::= function_name , "(" , [ arg_list ] , ")" ;
function_name  ::= identifier ;                           (* must be in the catalogue *)
arg_list       ::= expression , { "," , expression } ;

agg_call       ::= "agg" , "." , agg_fn , "(" , agg_args , ")" ;
agg_fn         ::= "count" | "sum" | "nth" | "occurrence_index"
                 | "collapse_repeats" | "streak" | "balance" ;
agg_args       ::= agg_arg , { "," , agg_arg } ;
agg_arg        ::= expression | window_spec | predicate_block ;
window_spec    ::= "day" | "week" , "(" , string_lit , ")"
                 | "payroll_period" | "calendar_month" | "quarter"
                 | "financial_year" | "service_year"
                 | "rolling" , "(" , number_lit , "," , unit_lit , ")"
                 | "since" , "(" , ( date_lit | variable_ref ) , ")" ;
unit_lit       ::= "days" | "weeks" | "months" ;
predicate_block::= "{" , expression , "}" ;               (* Boolean over the event scope *)

case_expr      ::= "CASE" , { "WHEN" , expression , "THEN" , expression } ,
                   "ELSE" , expression , "END" ;          (* ELSE is MANDATORY *)

list_literal   ::= "[" , [ expression , { "," , expression } ] , "]" ;
list_operand   ::= list_literal | variable_ref | function_call ;
```

**R-VII-011** `CASE` MUST have an `ELSE`. There is no implicit null result. *This
is the direct answer to `PayrollGenController::tierCut():482-495`, where the
missing else-branch silently charges a sub-L1 late at the L1 rate (BR-ATT-021b) —
a hidden business rule that no customer agreed to.*

**R-VII-012** `MATCHES` accepts only a **string literal** pattern, never a
computed expression. The pattern is anchored at both ends by the compiler,
compiled once at publish time, rejected if it contains nested unbounded
quantifiers, and evaluated under a step limit (§10.3).

### 5.4 Type system

#### 5.4.1 Types

| Type | Representation | Literal | Notes |
|---|---|---|---|
| `Number` | arbitrary-precision decimal (scale ≤ 9) | `12`, `0.25` | Never a float. Comparison is exact |
| `Money` | integer minor units + ISO currency | `₹1,50,000.00` | Law 7. `Money` never coerces to `Number` |
| `Days` | decimal, scale ≤ 4 | `0.5d`, `1d` | A *unit of leave/attendance charge* |
| `Hours` | decimal, scale ≤ 4 | `8.5h` | |
| `Minutes` | integer | `90m` | |
| `Duration` | integer seconds | `45s` | Result of instant arithmetic |
| `Date` | calendar date, no zone | `#2026-08-12#` | |
| `DateTime` | instant + business timezone | — (no literal; from context) | Always carries its zone (S16) |
| `Time` | wall-clock time of day | `#09:30#` | |
| `Boolean` | true/false | `TRUE` | |
| `Text` | UTF-8 string ≤ 512 | `'Operations'` | |
| `Code` | a value from a declared value set | `'SL'` compared to `leave.type` | Type-checked against the set at publish |
| `List<T>` | homogeneous, ≤ 500 elements | `['HYD','BLR']` | |
| `Null` | absent | `NULL` | Distinct from `0`, `''`, `FALSE` |

**Extensions registered by Part VIII.** SPX is one language and one type registry
(`spx_types`), so the refinement and parametric types Part VIII §5.1 declares are
available to the rule engine as well as the formula engine. They add no base type;
each is checked at publish and erased at runtime. Part VIII is the owning
specification for all three.

| Type | Base | Definition | Owner |
|---|---|---|---|
| `DayFraction` ★ | refinement of `Days` | `Days` constrained to `[0, 1]`, quantum declared by a day-part policy (`1`, `0.5`, `0.25`) | Part VIII §5.1 (R-VIII-014) |
| `Percentage` ★ | refinement of `Number` | `Number` tagged `percent`; the natural right operand of `%` | Part VIII §5.1 (R-VIII-014) |
| `Rate<Money/U>` ★ | parametric | `Money` per one unit of `U ∈ {Days, Hours, Minutes}`; the result of `Money / Days` | Part VIII §5.1 (R-VIII-014) |

Their coercion rules are Part VIII §5.2, which extends §5.4.2 below and does not
contradict it.

**R-VII-013** `Days`, `Hours` and `Minutes` are distinct types and do **not**
implicitly coerce into one another. Converting requires an explicit function
(`TO_MINUTES`, `TO_HOURS`, `TO_DAYS(minutes, day_length_ref)`). *This eliminates
the unit-confusion class of defect the payroll analysis found, where absence is
measured in working days and valued in calendar days
(`PayrollGenController.php:1268-1284`).*

#### 5.4.2 Coercion rules — the complete table

Implicit coercion happens in exactly the cases below. Everything else is a
**publish-time type error**.

| From | To | When | Rule |
|---|---|---|---|
| `Number` | `Days`/`Hours`/`Minutes` | Never | Explicit function required |
| integer `Number` | `Number` scale n | Always | Widening only |
| `Money` | `Number` | Never | `MONEY_AS_NUMBER(m)` required, and it is deliberately ugly |
| `Date` | `DateTime` | In comparison with a `DateTime` | Midnight in the context's business timezone |
| `DateTime` | `Date` | Never implicitly | `DATE_OF(dt)` required |
| `Code` | `Text` | In `CONCAT`, `UPPER`, `LOWER`, `MATCHES` | One-way |
| `Text` | `Code` | Never | Comparing `Code` to a string literal is legal only when the literal is a member of the declared value set (checked at publish) |
| `Null` | any | In `COALESCE` only | Elsewhere see §5.4.3 |
| `T` | `List<T>` | In `IN` right operand | A scalar right operand is a publish-time error; write `IN ['X']` |

Arithmetic result types:

| Left | Op | Right | Result |
|---|---|---|---|
| `Money` | `+ -` | `Money` (same currency) | `Money` |
| `Money` | `* /` | `Number` | `Money` |
| `Money` | `%` | `Number` | `Money` |
| `Money` | `* /` | `Money` | **type error** |
| `Number` | `+ - * /` | `Number` | `Number` |
| `Minutes` | `+ -` | `Minutes` | `Minutes` |
| `Minutes` | `* /` | `Number` | `Minutes` |
| `Hours` | `+ -` | `Minutes` | **type error** — write `TO_MINUTES(h) + m` |
| `Date` | `-` | `Date` | **type error** — use `DATEDIFF(a,b,unit)` |
| `DateTime` | `-` | `DateTime` | `Duration` |
| `Days` | `+ -` | `Days` | `Days` |

**R-VII-014** Division by zero returns the typed error value `ERR_DIV_ZERO`
(§5.8), never infinity, never null, never zero.

#### 5.4.3 Null semantics

SPX uses **three-valued logic** for comparison and two-valued logic for the
outcome, with an explicit reconciliation:

- Any relational comparison with a `Null` operand yields `UNKNOWN`.
- `UNKNOWN AND FALSE = FALSE`; `UNKNOWN OR TRUE = TRUE`; otherwise any
  expression containing `UNKNOWN` yields `UNKNOWN`.
- **A `when` clause evaluating to `UNKNOWN` does not match, and is recorded in
  the trace as `unknown` with the null-bearing symbol named.**
- `IS_NULL(x)` and `COALESCE(x, fallback)` are the only ways to test or remove a
  null.

**R-VII-015** A decision point MAY declare selected symbols `not_null_required`.
If such a symbol is `Null` at evaluation time, evaluation **fails** with
`ERR_MISSING_INPUT` rather than silently not matching. *Because SmartPRS 2's
`paidLeaveDays():1065` treats an unknown `type_id` as PAID — a silent
default with direct financial consequence (BR-PAY-014), compounded by the fact
that `LeaveController::apply()` never writes `type_id` at all (BR-LVE-008).*

### 5.5 Operator precedence

Highest binds tightest.

| Level | Operators | Associativity | Notes |
|---|---|---|---|
| 1 | `( )`, function call, `agg.*(…)`, keyed accessor `ns.sym('CODE')`, `.` member access | — | |
| 2 | unary `-` | right | Only on `Number`, `Money`, `Days`, `Hours`, `Minutes` |
| 3 | `%` (percent-of) | left | `X % 40` = 40 percent of X. **Modulo is `MOD(a,b)`** (spine S5.2) |
| 4 | `*` `/` | left | |
| 5 | `+` `-` | left | |
| 6 | `=` `<>` `<` `<=` `>` `>=` `IN` `NOT IN` `BETWEEN` `CONTAINS` `NOT CONTAINS` `MATCHES` | **non-associative** | `a < b < c` is a syntax error, not a silent truth |
| 7 | `NOT` | right | |
| 8 | `AND` | left | |
| 9 | `OR` | left | |

`AND` and `OR` short-circuit. Short-circuiting is **observable in the trace** —
an unevaluated branch is marked `skipped`, which is how the "why did this rule
not fire?" view explains itself.

**R-VII-016** The comparison level is non-associative and the editor's linter
warns on any expression mixing `AND` and `OR` at the same nesting level without
parentheses. Publication is **blocked** for such an expression in a
`high_risk` rule.

### 5.6 Function catalogue

Closed set. Every function is **total**: it returns a typed error value rather
than throwing (spine S5.6). Signatures use `T` for a type variable constrained as
noted. Functions marked ★ are additions to the spine S5.4 list and are
**SMARTPRS 3 ARCHITECTURE RECOMMENDATION**s, each with its justification.

#### Arithmetic

| Signature | Semantics |
|---|---|
| `MIN(a: T, b: T, …) → T` | Least. All arguments same type. `Null` arguments ignored; all-null → `Null` |
| `MAX(a: T, b: T, …) → T` | Greatest, same rules |
| `ABS(a: T) → T` | T ∈ {Number, Money, Days, Hours, Minutes, Duration} |
| `ROUND(a: T, mode: Code, unit: Number) → T` | **`mode` is mandatory** (spine S5.4). `mode ∈ {half_up, half_down, half_even, up, down, ceil, floor}`. `unit` is the quantum (`0.01`, `1`, `0.25`) |
| `CEIL(a: T, unit: Number) → T` | Round away from zero to the next `unit` |
| `FLOOR(a: T, unit: Number) → T` | Toward zero |
| `TRUNC(a: T, unit: Number) → T` | Discard remainder |
| `CLAMP(a: T, lo: T, hi: T) → T` | `MIN(MAX(a,lo),hi)`; `lo > hi` → `ERR_ARG` |
| `MOD(a: Number, b: Number) → Number` | `b = 0` → `ERR_DIV_ZERO` |
| `SIGN(a: T) → Number` | `-1 | 0 | 1` |

#### Money

| Signature | Semantics |
|---|---|
| `MONEY(amount: Number, currency: Code) → Money` | Constructs from minor-unit-safe decimal |
| `ALLOCATE(amount: Money, weights: List<Number>) → List<Money>` | Distributes exactly; residue to the last element so the parts always sum to the whole (spine S7; the one correct pattern in SmartPRS 2, `AppDataController:2247-2254`) |
| `ROUND_TO(amount: Money, mode: Code, unit: Money) → Money` | Explicit mode, no default |
| `MONEY_AS_NUMBER(m: Money) → Number` ★ | Escape hatch for ratio conditions (`m1 / m2` is a type error). Deliberately verbose. Publishing a rule using it in a `then` parameter raises a lint warning |

#### Conditional

| Signature | Semantics |
|---|---|
| `IF(cond: Boolean, a: T, b: T) → T` | Both branches must be the same type. `cond = UNKNOWN` → `b` **and a trace note** |
| `CASE WHEN … THEN … ELSE … END → T` | First true branch; `ELSE` mandatory |
| `COALESCE(a: T, b: T, …) → T` | First non-null |
| `IS_NULL(a: any) → Boolean` ★ | The only null test. Needed because `= NULL` is `UNKNOWN` |

#### Bands, steps, slabs

| Signature | Semantics |
|---|---|
| `BANDS(value: T, bands: List<Band>) → R` | `Band = {from: T, to: T | NULL, result: R}`. Half-open `[from, to)`; the last band may be open. **Arbitrary count.** Publish-time validation: bands sorted, contiguous, non-overlapping, and the domain fully covered or an explicit `default` supplied |
| `STEP(value: T, block: T, step: R, mode: Code) → R` | `mode ∈ {ceil, floor, round}`. `result = mode(value / block) × step` |
| `SLAB(value: Money, slab_table_code: Code, as_of: Date) → Money` | Walks a statutory slab table from the resolved rule pack version. Marginal by default; a slab table declares `flat` if it is a flat-band table |
| `SLAB_MARGINAL_RELIEF(value: Money, slab_table_code: Code, as_of: Date) → Money` ★ | Applies the declared marginal-relief rule of the slab table. *Because SmartPRS 2 applies 87A relief in the caller (`salaryTdsMonthly():1836-1840`) and one caller — `statutoryPdf():1732,1779` — skips it, producing a different number for the same employee* |

#### Dates and calendar

| Signature | Semantics |
|---|---|
| `DATEDIFF(a: Date, b: Date, unit: Code) → Number` | `unit ∈ {days, weeks, months, years}`; signed `b − a` |
| `DATEADD(d: Date, n: Number, unit: Code) → Date` | |
| `ADD_MONTHS(d: Date, n: Number) → Date` | Clamps to month end |
| `EOMONTH(d: Date) → Date` / `SOMONTH(d: Date) → Date` | |
| `NTH_WEEKDAY(month: Date, weekday: Code, n: Number) → Date` | **Counts actual weekdays**, `n = -1` meaning last. *Directly replaces `ceil(day/7)` at `PayrollGenController.php:83` (NEW-PAY-10), which is a day-of-month band and diverges from the colloquial "2nd Saturday" in most months* |
| `LAST_WEEKDAY(month: Date, weekday: Code) → Date` | `NTH_WEEKDAY(month, wd, -1)` |
| `DAY_OF_WEEK(d: Date) → Code` | `mon…sun` |
| `WEEK_OF_MONTH(d: Date) → Number` ★ | Actual weekday-occurrence index, for authors who want the count directly |
| `IS_WORKING_DAY(d: Date, scope: Code) → Boolean` | Resolves the employee's calendar as at `d` |
| `IS_HOLIDAY(d: Date, scope: Code) → Boolean` | |
| `IS_WEEKLY_OFF(d: Date, scope: Code) → Boolean` | |
| `HOLIDAY_TYPE(d: Date, scope: Code) → Code` ★ | `national | festival | optional | restricted | none` — needed by the festival-day comp-off rule (§12, RL-ATT-008) |
| `CLAMP_DAY_TO_MONTH(day: Number, month: Date) → Date` | 31 → 28/29/30 |
| `DATE_OF(dt: DateTime) → Date` ★ | Explicit narrowing (§5.4.2) |
| `TIME_OF(dt: DateTime) → Time` ★ | |

#### Service

| Signature | Semantics |
|---|---|
| `YEARS_OF_SERVICE(as_of: Date) → Number` | Fractional |
| `MONTHS_OF_SERVICE(as_of: Date) → Number` | |
| `COMPLETED_YEARS(as_of: Date) → Number` | Integer, floor |

#### Calendar aggregates

| Signature | Semantics |
|---|---|
| `WORKING_DAYS(from: Date, to: Date, scope: Code) → Number` | **One definition.** *SmartPRS 2 has three incompatible ones (CALC-B-16)* |
| `CALENDAR_DAYS(from: Date, to: Date) → Number` | |
| `PAYABLE_DAYS(from: Date, to: Date) → Days` | From the period settlement |

#### Attendance and leave

| Signature | Semantics |
|---|---|
| `ATTENDANCE_HOURS(from: Date, to: Date) → Hours` | Sum of worked hours from `attendance_days` |
| `OT_HOURS(from: Date, to: Date) → Hours` | |
| `LEAVE_UNITS(type: Code, from: Date, to: Date) → Days` | Approved units of one type |
| `LEAVE_DAYS_IN_RANGE(type: Code, from: Date, to: Date) → List<Date>` ★ | Needed by the sandwich rule to test individual bounding days |

#### Text

`UPPER(t)`, `LOWER(t)`, `TRIM(t)`, `CONCAT(a, b, …)`, `LEFT(t, n)`, `RIGHT(t, n)`
— all `Text → Text`, all bounded to 512 characters. There is no `SUBSTRING`
search and no `str_contains` equivalent on identity: **`CONTAINS` may not be
applied to a `Code`.** *This is deliberate. SmartPRS 2 decides PF eligibility by
`str_contains(lower(code.' '.name), 'basic')` (`AppDataController:2173`),
identifies DA by regex on a name (`:2266-2272`), and identifies a reimbursement
by the substring `reimburs` (`:2181-2188`). Semantics from names is the single
most reproducible defect family in the codebase, and SPX makes it inexpressible
for component, leave-type, status and role identity.*

#### Lists

| Signature | Semantics |
|---|---|
| `COUNT(l: List<T>) → Number` | |
| `SUM(l: List<T>) → T` | `T` numeric or `Money` |
| `AVG(l: List<T>) → Number` | Empty → `Null` |
| `ANY(l: List<Boolean>) → Boolean` / `ALL(…)` / `NONE(…)` | Empty: `ANY = FALSE`, `ALL = TRUE`, `NONE = TRUE` |

#### Unit conversion ★

| Signature | Semantics |
|---|---|
| `TO_MINUTES(x: Hours | Duration | Days, day_length: Code) → Minutes` | `day_length` names a declared day-length policy; required when converting `Days` |
| `TO_HOURS(x: Minutes | Duration) → Hours` | |
| `TO_DAYS(x: Minutes | Hours, day_length: Code) → Days` | |

#### Interval ★

| Signature | Semantics |
|---|---|
| `OVERLAP_MINUTES(a_from: DateTime \| Time, a_to: DateTime \| Time, b_from: DateTime \| Time, b_to: DateTime \| Time) → Minutes` | Minutes of intersection between two intervals, anchored to absolute instants (spine S10) so a window crossing midnight is one interval and not two. Disjoint intervals → `0m`; `a_to < a_from` or `b_to < b_from` → `ERR_ARG`. *Added by **Part XI** (§XI, rule WT-05, the night-window allowance): a 22:00–06:00 shift and a 20:00–04:00 shift attract different night hours, and the entitlement cannot be expressed without an interval-intersection primitive* |

**R-VII-017** The function catalogue is closed and versioned. Adding a function
is an Ametecs release activity requiring: a pure, total implementation; a
declared signature row in `spx_functions`; property tests including null,
boundary and error cases; and a catalogue version bump. A rule pins the
catalogue major version it compiled against.

### 5.7 Variable namespace catalogue

Read-only, resolved from a **prepared context** assembled by the calling domain
service before evaluation begins. A namespace not declared by the decision point
is a **publish-time** error, not a runtime one.

Legend: `NN` = may be declared `not_null_required` by a decision point.

#### `employee.*`

| Symbol | Type | Notes |
|---|---|---|
| `employee.code` | `Text` | |
| `employee.uuid` | `Text` | UUIDv7 public id |
| `employee.joining_date` | `Date` NN | |
| `employee.exit_date` | `Date` | Null while employed |
| `employee.stage` | `Code` | `employment_stage` value set — `probation`, `confirmed`, `internship`, … **as data**, not the two literals SmartPRS 2 hard-codes at `computeSlip():2114-2117` |
| `employee.category` | `Code` | |
| `employee.employment_type` | `Code` | |
| `employee.gender` | `Code` | A value set, not the open word list `female,f,woman,women` at `AppDataController:1933-1941` |
| `employee.dob` | `Date` | PII-classified; masked in traces (§9.5) |
| `employee.is_active` | `Boolean` | Derived from the status value set, never a string comparison |
| `employee.custom('<field_key>')` | declared type | Reads `entity_field_values` via the field definition (spine S9). Unknown key = publish-time error |

#### `org.*` — all resolved **as at the evaluation date**

`org.legal_entity`, `org.company`, `org.establishment`, `org.state`, `org.zone`,
`org.branch`, `org.department`, `org.sub_department`, `org.team`,
`org.cost_centre`, `org.business_unit`, `org.client_process`, `org.designation`,
`org.grade`, `org.location`, `org.pay_group` — each `Code`, each nullable, each
resolved from the effective-dated assignment row. Plus:

| Symbol | Type | Notes |
|---|---|---|
| `org.reports_to_code` | `Text` | Manager's employee code at that date |
| `org.is_in(dimension: Code, unit: Code)` | `Boolean` | Ancestor-aware: true if the employee's unit on that dimension is `unit` **or a descendant of it**. *SmartPRS 2 compares `company_name` strings (`PayrollGenController.php:138-161`), so a rename breaks the match* |

#### `service.*`

`service.years` `Number` · `service.months` `Number` · `service.days` `Number` ·
`service.completed_years` `Number`

#### `period.*`

`period.start` `Date` NN · `period.end` `Date` NN · `period.month` `Number` ·
`period.year` `Number` · `period.fin_year` `Text` · `period.working_days`
`Number` · `period.calendar_days` `Number` · `period.payable_days` `Days` ·
`period.cut_off_day` `Number` · `period.code` `Code`

#### `shift.*`

`shift.code` `Code` · `shift.start` `Time` · `shift.end` `Time` ·
`shift.hours` `Hours` · `shift.grace_in` `Minutes` · `shift.grace_out`
`Minutes` · `shift.is_night` `Boolean` (**explicit flag, never inferred from
`end <= start` as at `ShiftResolver.php:62`**) · `shift.full_day_minutes`
`Minutes` · `shift.half_day_minutes` `Minutes` · `shift.break_budget_minutes`
`Minutes` · `shift.source` `Code` (`roster | shift_group | employee_default |
org_default`)

#### `day.*`

`day.date` `Date` NN · `day.weekday` `Code` · `day.is_weekly_off` `Boolean` ·
`day.is_holiday` `Boolean` · `day.holiday_type` `Code` ·
`day.is_working_day` `Boolean` · `day.status` `Code` (available only in
decision points that run **after** classify) · `day.day_type` `Code`

#### `attendance.*` — anchored to absolute instants, never minutes-since-midnight

| Symbol | Type | Notes |
|---|---|---|
| `attendance.first_in` | `DateTime` | Null when no in-punch |
| `attendance.last_out` | `DateTime` | |
| `attendance.punch_count` | `Number` | |
| `attendance.session_count` | `Number` | Closed sessions |
| `attendance.worked_minutes` | `Minutes` | |
| `attendance.late_minutes` | `Minutes` | `MAX(0, first_in − (shift_start_instant + grace))`. **Computed on instants** — *fixing DEFECT-B-02, where `hour*60+minute` means a night shift can never be late* |
| `attendance.early_exit_minutes` | `Minutes` | *SmartPRS 2 computes this and discards it (CALC-B-10)* |
| `attendance.late_exit_minutes` | `Minutes` | Time worked past shift end |
| `attendance.break_minutes` | `Minutes` | |
| `attendance.shortfall_minutes` | `Minutes` | Against `shift.full_day_minutes` |
| `attendance.ot_minutes` | `Minutes` | Raw, before eligibility |
| `attendance.status` | `Code` | Pre-existing status, e.g. an HR override |
| `attendance.has_in` / `attendance.has_out` | `Boolean` | |
| `attendance.source_mix` | `List<Code>` | `device`, `manual`, `bulk`, `correction`, `in_app` |
| `attendance.is_corrected` | `Boolean` | An approved correction overlay applies |

#### `leave.*`

`leave.type` `Code` · `leave.units` `Days` · `leave.is_paid` `Boolean` ·
`leave.is_statutory` `Boolean` ★ (set by the leave-type definition; used by the
maternity carve-out in §12) · `leave.from` `Date` · `leave.to` `Date` ·
`leave.day_part` `Code` (`full | first_half | second_half`) ·
`leave.balance('<type_code>')` `Days` · `leave.entitlement('<type_code>')`
`Days` · `leave.pending('<type_code>')` `Days` · `leave.on_date(d: Date)`
`Code` ★ (the approved leave type on a given date, or `Null`)

#### `salary.*` — **by CODE, never by name**

`salary.component('<code>')` `Money` · `salary.gross` `Money` ·
`salary.ctc` `Money` · `salary.basic` `Money` · `salary.net` `Money` ·
`salary.has_component('<code>')` `Boolean` ·
`salary.flag('<code>', '<flag>')` `Boolean` ★ — reads a component's declared
statutory-base flag (`is_pf_base`, `is_esi_base`, `is_taxable`, …) per spine S12.

#### `statutory.*`

`statutory.value('<rule_code>')` typed by the pack · `statutory.applies('<rule_code>')`
`Boolean` · `statutory.pack_version('<statute>')` `Text`. **No statutory
threshold is ever a literal in a rule**; it is read from the resolved pack.

#### `credential.*` and `compliance.*` — the DRA gate's namespace

| Symbol | Type | Notes |
|---|---|---|
| `credential.exists('<type_code>')` | `Boolean` | |
| `credential.status('<type_code>')` | `Code` | `verified | pending | rejected | expired | absent` |
| `credential.expiry('<type_code>')` | `Date` | |
| `credential.days_to_expiry('<type_code>')` | `Number` | Negative when expired |
| `credential.is_valid('<type_code>', as_of: Date)` | `Boolean` | Status verified **and** not expired as at the date |
| `compliance.score` | `Number` | From the configured scoring rule, not the hard-coded weights at `ComplianceController.php:289-330` |
| `compliance.open_cases(category: Code)` | `Number` | |
| `compliance.risk_level` | `Code` | |

**R-VII-018** `credential.is_valid` returns `FALSE` when the credential register
holds no row for the type. *SmartPRS 2's scoring has the opposite asymmetry — a
missing `complaints` table awards a full 20 points while a missing `dra_certs`
table awards 0 (`ComplianceController.php:289-330`). Absence of evidence must be
treated one way, declared, everywhere.*

#### `request.*`, `run.*`, `policy.*`, `context.*`

| Symbol | Type | Notes |
|---|---|---|
| `request.type` | `Code` | The request type being validated |
| `request.amount` | `Money` | |
| `request.from` / `request.to` | `Date` | |
| `request.field('<key>')` | declared type | Typed request fields (spine S9) |
| `run.type` | `Code` | `regular | off_cycle | supplementary | arrears` |
| `run.lot` | `Number` | |
| `policy.value('<policy_key>')` | declared type | Any resolved policy value, effective-dated |
| `context.event` | `Code` | The money point or trigger, e.g. `commission.approve` |
| `context.evaluation_date` | `Date` NN | **The only clock.** `NOW()` does not exist in SPX |
| `context.tenant_timezone` | `Text` | |

#### Namespaces registered by Part VIII ★

SPX has one shared symbol registry, so the namespaces Part VIII §11.0 declares are
available to a rule as well as to a formula, subject to the same rule as every
other namespace: a decision point that does not declare the namespace cannot read
it, and using an undeclared one is a **publish-time** error. **Part VIII §11.0 is
the owning specification** for the symbol lists, types and slices; they are listed
here so this catalogue is complete.

| Namespace | Slice | What it carries | Owner |
|---|---|---|---|
| `settlement.*` | `settlement.*` | The frozen payroll-facing view of a period (spine S10 layer 4): `payable_days`, `lop_days`, `ot_minutes`, `late_count`, `leave_days('CODE')`, `compoff_earned`/`_availed`, `generation`, … | Part VIII §11.0 |
| `ytd.*` | `ytd.prior` | Year-to-date `gross`, `component('CODE')`, `statutory('CODE')`, `taxable_income`, `tax_deducted`, `periods_run` | Part VIII §11.0 |
| `input.*` | `variable.inputs` | Approved variable inputs for this period: `value('CODE')`, `recoveries_due`, `has('CODE')` | Part VIII §11.0 |
| `claim.*` | `variable.inputs` | The reimbursement or expense claim under evaluation | Part VIII §11.0 |
| `loan.*` | `loan.terms` | `principal`, `instalment`, `outstanding`, `instalments_paid`/`_total`, `rate`, `moratorium_periods` | Part VIII §11.0 |
| `prior.*` | `prior_run.snapshot` | The pinned original result, for arrears by difference | Part VIII §11.0 |
| `formula.*` | — | `value('CODE')`, the named sub-expression edge that builds the dependency graph | Part VIII §11.0 |

Part VIII also extends two namespaces defined above: `salary.wage_base('<BASE_CODE>')`,
`salary.balance_pool` and `salary.net_after_statutory` join `salary.*`, and
`period.periods_per_year`, `period.fin_year_start` and `period.fin_year_end` join
`period.*`.

**R-VII-019** SPX has no access to the wall clock, to randomness, to the request,
to the session, to the filesystem, to the network or to the database. The only
temporal input is `context.evaluation_date`, injected by the caller. *This is
what makes a recalculation of March, run in November, produce March's answer —
the property SmartPRS 2 cannot offer because its policies have no dates at all.*

### 5.8 The error model

Every function is total. An error is a **typed error value** that propagates.

| Code | Raised by | Propagation |
|---|---|---|
| `ERR_DIV_ZERO` | `/`, `MOD` | Poisons the containing expression |
| `ERR_TYPE` | Should be impossible after publish-time checking; raised if a context supplies a value of the wrong type | Poisons; also raises an operator alert (it indicates a platform bug) |
| `ERR_MISSING_INPUT` | A `not_null_required` symbol is null | Poisons |
| `ERR_ARG` | Bad argument (e.g. `CLAMP(lo > hi)`, unsorted `BANDS`) | Poisons |
| `ERR_BUDGET_NODES` / `ERR_BUDGET_TIME` / `ERR_BUDGET_DEPTH` | Budget exceeded (§7.7) | Aborts the whole evaluation |
| `ERR_REGEX_STEPS` | `MATCHES` step limit | Poisons |
| `ERR_AGG_UNAVAILABLE` | An aggregation window cannot be computed (e.g. the counter is dirty and on-demand computation exceeds its row cap) | Aborts the whole evaluation |
| `ERR_SET_MEMBER` | A `Code` compared against a literal not in the value set — normally caught at publish; raised at runtime if the value set changed since | Poisons |

Propagation and surfacing:

1. An error value poisons its containing expression: any operator or function
   with an error operand returns that error. `COALESCE` does **not** absorb an
   error (only `Null`).
2. A `when` clause evaluating to an error is **not** treated as false.
3. **The rule evaluation fails.** The engine returns a `DecisionTrace` with
   `status = failed`, the error code, the failing node, the rule code and
   version, and it raises the domain exception `RuleEvaluationFailed`.
4. The calling domain service MUST NOT continue with a partial result. The
   affected subject is marked `exception` on the run/derivation, the operator
   sees it in the exceptions list, and the batch **continues with other
   subjects** while counting failures.
5. A batch that ends with any failure count > 0 does not report success.

**R-VII-020** There is no `catch` around rule evaluation that discards the error.
A CI static-analysis rule fails the build on any `catch` in a domain path that
does not rethrow, log with correlation, or convert to an explicit exception
record. *SmartPRS 2 destroys 50.5% of its caught exceptions; the four
`activity_logs` direct inserts at `RequestController.php:1382-1387, 1424-1429,
1444-1449, 1822-1827` break the audit chain precisely because a failure there
was designed to be invisible.*

**R-VII-021** A rule evaluation failure is **never** resolved by applying the
permissive outcome. If a `compliance.payment_gate` rule fails to evaluate, the
payment is **held for review**, not released. *SmartPRS 2's DRA gate is
explicitly designed to "never break the payment path on error" — a reasonable
instinct that produces the wrong answer for a `block` tenant.*
**PRODUCT OWNER DECISION REQUIRED — D-VII-01, see §20.**

---
### 5.9 The aggregation primitives

These are the capabilities SmartPRS 2 lacks entirely, and each exists because
the as-is analysis found the behaviour hard-coded (spine S5.5). They are the
only symbols in SPX that read beyond the current subject, and therefore the only
ones with a cost model.

#### 5.9.1 Signatures and semantics

| Primitive | Signature | Returns | Semantics |
|---|---|---|---|
| `agg.count` | `agg.count(event: Code, window: Window, {predicate}?) → Number` | `Number` | Number of events of the declared type in the window, for **this subject**, satisfying the predicate. The predicate is evaluated in the **event scope** (§5.9.3) |
| `agg.sum` | `agg.sum(measure: Code, window: Window, {predicate}?) → T` | Typed by the measure declaration (`Minutes`, `Days`, `Money`, `Number`) | Sum of a declared measure over the window |
| `agg.nth` | `agg.nth(event: Code, partition: Code, order: Code, n: Number) → DateTime \| Null` | `DateTime` | The instant of the *n*-th event within the partition (`n` may be negative for from-the-end). *Refines spine S5.5, which gives a three-argument form; the ordinal cannot be expressed without `n`* |
| `agg.occurrence_index` | `agg.occurrence_index(event: Code, window: Window, {predicate}?) → Number` | `Number` | 1-based index of **the current subject's event** among qualifying events in the window. Returns `0` when the current subject does not itself qualify |
| `agg.collapse_repeats` | `agg.collapse_repeats(event: Code, within_seconds: Number) → Number` | `Number` | Count of events after collapsing runs closer together than `within_seconds`. The configurable double-tap window |
| `agg.streak` | `agg.streak(condition: Code, direction: Code) → Number` | `Number` | Length of the consecutive run of days satisfying a declared day-condition. `direction ∈ {backward, forward, centred}`; `backward` includes the current day and looks only at settled history |
| `agg.balance` | `agg.balance(ledger: Code, as_of: Date) → T` | Typed by the ledger | Balance from a ledger (leave, comp-off, loan, advance) as at a date |

`Window` is one of: `day`, `week('<start_day>')`, `payroll_period`,
`calendar_month`, `quarter`, `financial_year`, `rolling(n, unit)`,
`since(<date>)`, `service_year`.

**R-VII-022** `payroll_period` resolves from the **effective-dated pay-cycle
definition for that employee's company**, honouring the cut-off day — never from
the calendar month. *SmartPRS 2's free-lates window is the payroll month by
accident of `PayrollGenController.php:453, 475`, and its sandwich calculation
indexes day-of-month integers inside `$start`'s month (`sandwichDays():770-813`),
so it reads the wrong month entirely on a cut-off cycle.*

**R-VII-023** `rolling(n, unit)` is a **half-open window ending at, and
including, the subject's own date**: `[date − n units + 1 unit, date]`. This is
declared, not inferred, because "3 lates in 30 days" is ambiguous otherwise.

#### 5.9.2 The event and measure catalogues

`event` and `measure` are **codes from a closed, platform-owned catalogue**, not
free text. A tenant cannot invent an event; a module declares one.

| Module | Event codes | Measure codes |
|---|---|---|
| Attendance | `late_arrival`, `early_exit`, `absent_day`, `half_day`, `short_hours_day`, `worked_day`, `worked_weekly_off`, `worked_holiday`, `punch`, `ot_day`, `correction_approved` | `late_minutes`, `worked_minutes`, `ot_minutes`, `break_minutes`, `shortfall_minutes`, `day_penalty_days`, `lop_days` |
| Leave | `leave_taken`, `leave_rejected`, `leave_cancelled`, `lop_leave` | `leave_units`, `lop_units` |
| Payroll | `payslip_issued`, `recovery_applied`, `arrear_paid` | `gross`, `net`, `recovery_amount`, `ot_amount` |
| Compliance | `credential_expired`, `case_opened`, `case_closed`, `gate_blocked`, `gate_warned` | `compliance_score` |
| Platform | `rule_matched`, `approval_decided` | — |

**R-VII-024** Adding an event or measure is an Ametecs release activity: a
catalogue row, a **projector** that maintains its counters (§5.9.4), a backfill
migration, and a conformance test that the projector and the on-demand
computation agree on a fixture of at least 10,000 events.

#### 5.9.3 The event scope — what a predicate may read

Inside `{predicate}`, and **only** there, an `event.*` namespace is available:

`event.date` `Date` · `event.instant` `DateTime` · `event.value` typed by the
event's declared payload · `event.source` `Code` · `event.status` `Code` ·
`event.shift` `Code` · `event.branch` `Code` (the org unit **as at the event
date**, not today).

The predicate may **not** call `agg.*` (no nesting), may not read
`attendance.*`, `leave.*` or `salary.*` of the outer subject, and may not
reference another employee. It may read `employee.*` and `org.*` of the subject,
because those are already in the prepared context.

**R-VII-025** Nested aggregation is a publish-time error. An aggregate over
aggregates is not expressible, which bounds the cost of any rule to
`O(events in window)` and makes the batch cost model in §14 sound.

#### 5.9.4 Computation strategy — materialised counters versus on demand

Each `(event | measure, window)` pair is declared `materialised` or `on_demand`
in the catalogue. The declaration is a platform decision based on cardinality,
not a tenant setting.

**Materialised path.** A projector maintains `agg_counters`:

```
agg_counters
  tenant_id, subject_type, subject_id,
  metric_code,             -- event or measure code
  window_kind, window_key, -- e.g. ('payroll_period','2026-08:CMP7')
  qualifier_hash,          -- SHA-256 of the canonical predicate AST, or ZERO
  count_value  BIGINT,
  sum_value    DECIMAL(18,4),
  generation   BIGINT,     -- bumped on any recomputation of the window
  computed_at, is_dirty
```

`qualifier_hash` is what makes a predicate cheap: **the set of distinct
predicates in a tenant's published rules is small and known at publish time.**
The publisher registers each distinct `(metric, window, predicate)` triple as a
**counter definition**; the projector maintains exactly those and no others. An
unregistered predicate falls back to the on-demand path.

**On-demand path.** A bounded query against the settled event store, with:
- a hard row cap (default 20,000 rows per aggregation call);
- a per-evaluation aggregation-call budget (default 8);
- a per-batch shared result cache keyed by
  `(subject, metric, window_key, qualifier_hash, generation)`.

Exceeding the row cap returns `ERR_AGG_UNAVAILABLE` and fails the evaluation
loudly (§5.8) rather than returning a truncated count.

| Aggregation | Typical window | Strategy | Cost (materialised) | Cost (on demand) |
|---|---|---|---|---|
| `agg.count(late_arrival, payroll_period, {…})` | ≤ 31 days | materialised | 1 indexed row read | ~31 rows |
| `agg.count(late_arrival, rolling(30, days), {…})` | 30 days | materialised (rolling counters kept per subject-day) | 1 row read | ~30 rows |
| `agg.sum(worked_minutes, calendar_month)` | ≤ 31 days | materialised | 1 row read | ~31 rows |
| `agg.occurrence_index` | any | derived from the counter at the subject's own date | 1 row read | ordered scan of the window |
| `agg.streak(absent_day, backward)` | unbounded | on demand, capped at 365 days | — | ≤ 365 rows, early exit on first non-match |
| `agg.balance(ledger, as_of)` | ledger | materialised balance snapshot + delta since snapshot | 1 + k rows | full ledger walk |
| `agg.collapse_repeats(punch, n)` | day | on demand | — | ≤ punches in the day |
| `agg.nth(punch, day, asc, n)` | day | on demand | — | ≤ punches in the day |

**R-VII-026** A materialised counter is **never** the authority. Every counter is
reproducible from the settled event store by a `recompute_counters(scope, range)`
command, and a scheduled reconciliation job re-derives a sampled slice daily and
alerts on divergence. *SmartPRS 2 has no counters, but it also has no way to
reconcile anything: `attendance:dedupe` hard-deletes with no log
(`DedupeAttendance.php:42-88`) and there is no way to reconstruct what it
removed (OQ-B-12).*

#### 5.9.5 Late and out-of-order events

Punches arrive late routinely: a device buffers overnight, the Smart Biometric
Bridge replays after a network outage, a correction is approved three weeks
later, a bulk upload lands for last month. The engine's behaviour is declared,
not emergent.

1. **Events are appended, never reordered.** `punch_events` is append-only
   (spine S10). An event's *effective* date is its business date, not its
   receipt date.
2. **A late event marks windows dirty.** Landing an event with business date `d`
   inserts a row into `agg_window_dirty` for every window containing `d`, for
   that subject, with the reason and the source event id.
3. **A dirty window is not read.** If an evaluation needs a dirty counter, the
   engine either (a) recomputes that single counter inline when it is under the
   row cap, or (b) returns `ERR_AGG_UNAVAILABLE` and the subject is queued for
   recalculation. It **never** reads a stale counter.
4. **Recalculation is the settlement mechanism.** The
   `recalculate(scope, date range)` command (spine S10) re-derives attendance
   days *and* counters from layer 2 upward, using the rules **effective on each
   date**, produces a preview diff, and supersedes rather than overwrites.
5. **Ordinal-sensitive rules are recomputed, never patched.** A rule using
   `agg.occurrence_index` — "this is your third late this month, so it costs
   half a day" — can have its answer changed by an event that arrives later with
   an earlier date. The engine does not attempt an incremental fix-up; the whole
   subject-window is re-derived and the resulting diff is shown.
6. **A locked period does not silently absorb a late event.** If the window is
   inside a locked payroll period, the recalculation runs in *shadow* mode: it
   produces the diff and raises an `attendance.late_event_in_locked_period`
   exception with a target period, for the arrears path. *This is the explicit
   fix for DEFECT-B-30, where a correction approved against a locked period sets
   a flag, does nothing, and emails the employee a promise no code keeps.*

**R-VII-027** Every derived record stores the `generation` of every counter it
consumed. A record whose counter generations no longer match the current
generations is **stale**, is visibly marked stale in the UI, and is the input to
the recalculation queue. This makes "is this number current?" a query, not a
belief.

---

## 6. The action catalogue

### 6.1 What an action is

An action is a **typed, declarative instruction**, not a callback. The engine
constructs `AppliedAction` values; it never executes behaviour. The calling
domain service interprets them inside its own transaction.

```
AppliedAction {
    action_code        Code        -- from the closed catalogue
    parameters         map<Code, TypedValue>
    emitted_by         { rule_code, rule_version_id, checksum }
    subject            { type, id, date }
    fingerprint        char(64)    -- see §6.3
    sequence           int         -- deterministic ordering (§7.4)
}
```

**R-VII-028** The engine returns actions; it never mutates state, never enqueues
a job, never sends a notification, never writes an audit row. *This is what makes
the simulator (§11.3) trivially safe: it is the same code path with the same
inputs and no side effects to suppress.*

### 6.2 The catalogue

Closed, versioned, module-owned. Every parameter is typed; a parameter marked
`expr` accepts an SPX expression of that type, a literal, or a formula reference.

#### Attendance

| Action | Parameters | Effect | Idempotency | Reversal |
|---|---|---|---|---|
| `SET_STATUS` | `code: Code` (attendance status value set) | Sets the day's status | Last-writer within one derivation; conflicting `SET_STATUS` from two matched rules is a **conflict**, see §8 | Superseded by re-derivation |
| `SET_DAY_TYPE` | `type: Code` (`working \| weekly_off \| holiday \| non_working`) | Sets the calendar day type | Idempotent by value | Superseded |
| `SET_GRACE` | `minutes: Minutes expr` | Overrides the grace applied to lateness | Idempotent by value | Superseded |
| `APPLY_DAY_PENALTY` | `fraction: Days expr`, `reason: Text`, `cap_ref: Code?` | Adds a day-fraction penalty | **Additive**; deduplicated by fingerprint | Superseded by re-derivation; contributes to the day's penalty total |
| `APPLY_LOP` | `amount: Days expr \| Hours expr`, `reason: Text` | Adds loss of pay in the declared unit | Additive, fingerprinted | Superseded |
| `OFFSET_SHORTFALL` | `minutes: Minutes expr`, `source: Code`, `cap: Minutes expr?` | Reduces a shortfall/lateness by made-up time | Additive, capped, fingerprinted | Superseded |
| `CREATE_OT` | `minutes: Minutes expr`, `multiplier_ref: Code`, `approval: Code` (`auto \| workflow`) | Creates an overtime entitlement record | Fingerprinted; re-derivation supersedes the entry **only while unpaid** | Once paid: superseding requires an `arrear`/`recovery` action, never an edit |
| `CREATE_COMP_OFF` | `units: Days expr`, `expiry_ref: Code`, `reason: Text` | Credits the comp-off ledger | Fingerprinted; ledger credit is **once per fingerprint** | A **compensating debit** row, never a delete |
| `FLAG_FOR_REVIEW` | `reason: Text`, `severity: Code` | Raises an exception item on the day | Idempotent by `(subject, reason)` | Cleared by resolution or re-derivation |
| `NOTIFY` | `event: Code`, `audience_ref: Code`, `dedupe_key: Text expr` | Emits a domain event for the notification engine | **Deduped by `dedupe_key`** | n/a — notifications are not reversed; a correction sends a correction notice |

#### Leave

| Action | Parameters | Effect | Idempotency | Reversal |
|---|---|---|---|---|
| `DEDUCT` | `type: Code`, `units: Days expr` | Debits a leave ledger | Fingerprinted, once per request version | Compensating credit |
| `CHARGE_DAY` | `date: Date expr`, `type: Code`, `units: Days expr`, `reason: Text` | Charges one specific day (the sandwich mechanism) | Fingerprinted per `(request, date)` | Compensating credit |
| `EXEMPT_DAY` | `date: Date expr`, `reason: Text` | Explicitly does not charge a day | Idempotent | — |
| `CONVERT_TO` | `type: Code`, `reason: Text` | Converts the request's leave type (e.g. excess EL → LOP) | Idempotent by value | Superseded on re-evaluation before approval; after approval requires a cancellation workflow |
| `REQUIRE_DOCUMENT` | `doc_type: Code`, `by: Date expr` | Makes a document mandatory before approval | Idempotent | Cleared on rule change + re-evaluation |
| `BLOCK` | `reason: Text`, `code: Code` | Refuses the request with a machine-readable reason | Idempotent | n/a |
| `ROUTE_TO_WORKFLOW` | `workflow_code: Code` | Selects the approval workflow | Idempotent; a second, different `ROUTE_TO_WORKFLOW` is a **conflict** | In-flight instance completes on its own version (spine S8) |

#### Payroll

| Action | Parameters | Effect | Idempotency | Reversal |
|---|---|---|---|---|
| `ADD_COMPONENT` | `code: Code`, `amount: Money expr \| formula_ref`, `basis: Code?` | Adds an earning or deduction line | Fingerprinted; one line per `(component, fingerprint)` | Run regeneration creates a **new run version**, never edits a payslip |
| `SUPPRESS_COMPONENT` | `code: Code`, `reason: Text` | Removes a component from this run | Idempotent | Superseded |
| `SET_BASIS` | `component: Code`, `basis: Code` | Changes a component's calculation basis for this subject-period | Idempotent by value; two different bases = conflict | Superseded |
| `CAP` | `component: Code`, `amount: Money expr`, `mode: Code` (`hard \| soft`) | Caps a component; `soft` flags instead of truncating | Idempotent by value | Superseded |
| `CREATE_RECOVERY` | `type: Code`, `amount: Money expr`, `schedule_ref: Code`, `priority: Number` | Creates a recovery instruction | Fingerprinted | Compensating ledger entry, per the loan pattern SmartPRS 2 gets right (`loan_recoveries`, `PayrollGenController:2768-2807`) |
| `SET_RECOVERY_ORDER` | `order: List<Code>` | Sets the recovery precedence for this subject-period | Idempotent | Superseded. *Replaces the fixed statutory → loan → advance → clawback order hard-coded at `compute():1350-1389` (BR-PAY-009)* |

#### Compliance

| Action | Parameters | Effect | Idempotency | Reversal |
|---|---|---|---|---|
| `GATE_PAYMENT` | `mode: Code` (`warn \| block`), `reason: Text`, `code: Code`, `event: Code` | Warns or blocks a money event | Idempotent by `(event, subject, code)`; `block` dominates `warn` when both match | Cleared when the underlying condition clears and the gate re-evaluates |
| `RAISE_ITEM` | `type: Code`, `due: Date expr`, `owner_ref: Code`, `severity: Code` | Creates a compliance item | Idempotent by `(type, subject, due)` | Closed with a reason, never deleted |
| `SET_RISK` | `level: Code` | Sets a risk level | Idempotent by value; highest level wins across matched rules | Superseded |

#### Platform

| Action | Parameters | Effect | Idempotency | Reversal |
|---|---|---|---|---|
| `REQUIRE_APPROVAL` | `workflow_code: Code`, `reason: Text` | Forces the operation through a workflow | Idempotent | Spine S8 |
| `WRITE_AUDIT` | `reason: Text`, `detail: Text expr` | Appends to the audit ledger through the audit service | Fingerprinted | Never — the ledger is append-only (spine S14) |
| `NOTIFY` | as Attendance | | | |

**R-VII-029** `NOTIFY` emits a **domain event**, not an email. Channel, audience,
template, language and throttling belong to the notification engine.
*SmartPRS 2's late-arrival email is sent with `Mail::raw` straight from
`LateArrivalService.php:289`, bypassing per-company SMTP, writing no `mail_log`
row, with no queue and no retry (BR-NOT-017). A rule that could send mail
directly would rebuild that.*

**R-VII-030** No action may delete a business or evidentiary record. The
catalogue contains no `DELETE`, no `PURGE` and no `TRUNCATE` verb, at any
severity, for any module. Retention is a platform lifecycle governed by the
retention class (spine S16), never a rule.

**R-VII-031** No action may write to the employee master, change a role, change
a scope, change a permission, alter configuration, or publish another artefact.
The catalogue contains no such verb. *A rule that could grant a permission would
make the tenant administrator's rule-authoring right equivalent to a privilege-
escalation primitive; see §10.2.*

### 6.3 Idempotency

```
fingerprint = SHA256(
      tenant_id ‖ decision_point ‖ subject_type ‖ subject_id ‖ subject_date
    ‖ rule_code ‖ rule_version_id
    ‖ action_code ‖ canonical_json(resolved_parameters)
)
```

Parameters are **resolved** (expressions already evaluated to values) before
hashing, so an action whose amount changed produces a different fingerprint and
is correctly seen as a different effect.

**R-VII-032** Every domain service that applies actions MUST record the
fingerprint on the effect row and MUST treat a repeated fingerprint as a no-op.
Re-running a derivation therefore converges rather than duplicating.
*SmartPRS 2's correction write-back does the opposite: it DELETEs all
`source='correction'` rows for the employee-day and re-inserts
(`AttendanceCorrectionService.php:295-319`), destroying evidence to achieve
idempotency.*

### 6.4 Reversal semantics

Three classes, declared per action:

| Class | Actions | Reversal |
|---|---|---|
| **Derived** | `SET_STATUS`, `SET_DAY_TYPE`, `SET_GRACE`, `APPLY_DAY_PENALTY`, `APPLY_LOP`, `OFFSET_SHORTFALL`, `SUPPRESS_COMPONENT`, `SET_BASIS`, `CAP`, `SET_RECOVERY_ORDER`, `SET_RISK`, `CONVERT_TO` | **Superseded** by re-derivation. The prior derivation is retained with its generation number and remains queryable |
| **Ledger** | `CREATE_COMP_OFF`, `DEDUCT`, `CHARGE_DAY`, `CREATE_RECOVERY`, `CREATE_OT` (once paid) | **Compensated**: a reversing entry with a reason, an actor and a link to the superseding derivation. Never deleted, never edited |
| **Terminal** | `NOTIFY`, `WRITE_AUDIT`, `RAISE_ITEM`, `GATE_PAYMENT` (`block` already enforced) | **Not reversible.** A correction is a new event with its own trace |

**R-VII-033** A re-derivation that would reverse a **ledger** effect inside a
locked period does not do so. It produces an exception record naming the
liability, the amount, the target period and an owner. *OQ-B-02 records that
production may already hold rows in exactly this state representing unpaid
liabilities; they must be enumerated before cutover (§13.5).*

### 6.5 How Ametecs adds an action

The catalogue is closed to customers and versioned by the platform.

1. Author a migration inserting the `action_definitions` row: `action_code`,
   `module`, `param_schema` (typed JSON schema), `effect_class`
   (`derived | ledger | terminal`), `idempotency_rule`, `reversal_rule`,
   `min_catalogue_version`, `is_money_affecting`.
2. Implement `ActionHandler` in the owning module. The interface is
   `apply(AppliedAction, DomainContext): EffectResult` — pure with respect to
   anything outside the module's own aggregate, and required to be idempotent on
   the fingerprint.
3. Add the action to the allow-list of the decision points that may emit it.
4. Add conformance tests: parameter type rejection, double-apply convergence,
   reversal behaviour, trace rendering, and an authorisation test proving the
   action cannot be emitted from a decision point that does not declare it.
5. Bump the **action catalogue version**. Published rules pin the major version
   they compiled against; a major bump requires a migration that re-validates
   every published rule and reports any that no longer type-check, **without
   changing them**.

**R-VII-034** A tenant, a consultant, an implementation partner and a support
engineer cannot add, alter or extend an action, by any route, in any deployment
mode, including on-premise. There is no plugin path, no script hook and no
"custom action" record.

---
## 7. Evaluation semantics

### 7.1 The evaluation contract

```
RuleEngine::evaluate(
    decision_point : Code,
    subject        : SubjectRef,        -- (type, id, date)
    context        : PreparedContext,   -- built by the caller, tenant-scoped
    as_of          : Date               -- resolution date, usually subject.date
) : EvaluationResult

EvaluationResult {
    status   : matched | no_match | failed
    actions  : ordered AppliedAction[]
    trace    : DecisionTrace
    counters : { rules_considered, rules_matched, nodes, agg_calls, micros }
}
```

**R-VII-035** The engine never mutates state and never throws for a business
outcome. It throws exactly one exception type, `RuleEvaluationFailed`, and only
for `status = failed`.

**R-VII-036** A result without a trace is a programming error and throws
(Law 4). There is no code path returning actions with a null trace.

### 7.2 The evaluation pipeline

```
1. resolve       -- candidate rules: published, decision_point matches,
                    effective_from <= as_of <= COALESCE(effective_to, '9999-12-31')
2. filter        -- scope match: every constrained dimension of the rule's scope
                    contains the subject's value for that dimension AS AT as_of
3. order         -- specificity DESC, priority DESC, code ASC  (§7.4)
4. evaluate      -- for each candidate in order:
                     a. evaluate `when`     → true | false | unknown | error
                     b. if true, evaluate `except` → true suppresses the match
                     c. record the outcome in the trace either way
                     d. apply the conflict policy (§7.3)
5. collect       -- resolve action parameters, compute fingerprints, order (§7.4)
6. seal          -- write the trace, return
```

Step 2 uses the org and classification assignments **as at `as_of`**, resolved
through the effective-dated assignment rows (spine S3). *SmartPRS 2 cannot answer
"what was this employee's branch on 15 May?" at all, so no rule of this kind is
even expressible there.*

### 7.3 Conflict policies

| Policy | Semantics | Use |
|---|---|---|
| `highest_priority_only` | Evaluate in order; the **first matching rule's** actions are the result. Every subsequent candidate is still recorded in the trace as `not_evaluated (outranked)` | Single-outcome questions: which status, which grace, which day type |
| `first_match` | As above but evaluation **stops** at the first match; later candidates are not even scope-checked | Cheap variant for decision points where the trace does not need to show the alternatives. **Not permitted for `high_risk` rules** |
| `all_match` | Every matching rule contributes its actions | Additive questions: penalties, earnings, gates, compliance items |

**R-VII-037** Under `all_match`, two matched rules emitting **conflicting**
actions on the same target is a **runtime conflict**: `SET_STATUS` twice with
different codes, `SET_BASIS` twice with different bases, `ROUTE_TO_WORKFLOW`
twice with different workflows. The engine does not pick one. It fails the
evaluation with `ERR_ACTION_CONFLICT`, names both rules and both values in the
trace, and the subject becomes an exception. The conflict detector (§11.4) is
designed to make this unreachable in practice; when it is reached, the answer is
an error, never a coin-toss. *SmartPRS 2 resolves exactly this situation with
`if ($tr > $rank)` — row order (NEW-ATT-019).*

Additive actions (`APPLY_DAY_PENALTY`, `APPLY_LOP`, `CREATE_COMP_OFF`,
`ADD_COMPONENT`, `RAISE_ITEM`, `NOTIFY`, `OFFSET_SHORTFALL`) never conflict;
they accumulate, subject to declared caps.

`GATE_PAYMENT` has a declared dominance order — `block > warn` — so a `block`
from any matched rule wins over any number of `warn`s. This is a property of the
action, declared in `action_definitions`, not of the rules.

### 7.4 Ordering guarantees and determinism

**Candidate ordering** is total and deterministic:

```
ORDER BY specificity_weight DESC, priority DESC, rule_code ASC, version_no DESC
```

`rule_code ASC` is a **tie-break of last resort for ordering only**; it never
resolves a *precedence* tie, because a precedence tie blocks publication (§8.3).
Its purpose is to make the trace byte-reproducible.

**Action ordering** within the result: by matched-rule order, then by the
action's declared position in the rule's `then` list. Within one subject, the
sequence is stable across runs, across machines and across PHP versions.

**R-VII-038** Determinism is asserted mechanically: a CI test evaluates a fixed
corpus of 500 subject-days against a fixed rule pack and asserts the SHA-256 of
the serialised `(actions, trace)` is byte-identical to a committed golden value.
Any change to that hash must be an intentional, reviewed change.

Sources of non-determinism are eliminated by construction: no clock (§5.7), no
randomness, no hash-order iteration (all maps are ordered by key at
serialisation), no locale-dependent comparison (comparisons are on typed values,
not on collated strings), no floating point (Law 7).

### 7.5 The `except` clause

`except` exists because negative conditions written into `when` produce
unreadable rules and, worse, produce rules whose *scope* lies about who they
govern.

- `except` is evaluated **only when `when` is true**.
- `except = TRUE` records the rule as `matched_then_excepted` — visibly, in the
  trace. This is the difference between "the rule did not apply to you" and "the
  rule applied to you and then let you off", which is exactly what an employee
  asks about.
- `except` returning `unknown` **does not** suppress the match (it is not a
  carve-out unless it is definitely true), and the trace records the unknown.
- `except` returning an error fails the evaluation (§5.8).

**R-VII-039** A carve-out that changes *who* is governed belongs in `scope`
(spine S3.1). A carve-out that changes *when* the rule bites belongs in `except`.
The builder lints a rule whose `except` references only org dimensions and
suggests moving it to scope, because scope participates in specificity and
`except` does not.

### 7.6 Employment-boundary short-circuits

Before any rule is evaluated, the engine applies three platform guards that are
**not** rules and cannot be overridden by one:

1. The subject date is on or after `employee.joining_date`; otherwise the day is
   `not_yet_joined` and no rule runs.
2. The subject date is on or before `employee.exit_date`; otherwise `exited`.
3. The subject's tenant matches the context's tenant. A mismatch is a platform
   fault, alerts, and aborts.

*Guard 2 exists because SmartPRS 2 has no leaver proration at all — nothing reads
a date of leaving anywhere in `compute()` (NEW-PAY-12) — and a mid-month leaver
is paid a full month.*

### 7.7 Budgets

Per single evaluation, defaults, all platform-configurable per decision point and
none tenant-editable:

| Budget | Default | On breach |
|---|---|---|
| AST nodes evaluated | 5,000 | `ERR_BUDGET_NODES`, evaluation fails |
| AST depth | 32 | Rejected at **publish** time |
| Wall-clock per rule | 20 ms | `ERR_BUDGET_TIME` |
| Wall-clock per decision point per subject | 200 ms | `ERR_BUDGET_TIME` |
| `agg.*` calls per rule | 8 | Rejected at publish time |
| `agg.*` rows scanned per call | 20,000 | `ERR_AGG_UNAVAILABLE` |
| Regex steps per `MATCHES` | 10,000 | `ERR_REGEX_STEPS` |
| Actions per rule | 20 | Rejected at publish time |
| Candidate rules per decision point per subject | 200 | Rejected at publish time, with the count and the scopes named |
| SPX source length | 8,000 chars | Rejected at publish time |
| List literal length | 500 | Rejected at publish time |

**R-VII-040** Budgets that can be checked statically are enforced at **publish**
time, so a rule that could exhaust the engine cannot be published. Budgets that
depend on data are enforced at runtime and fail loudly.

### 7.8 Evaluation errors

Restating §5.8 as the engine's obligation:

**R-VII-041** An evaluation error MUST surface. Specifically: (a) the subject is
recorded as an exception with the rule code, version, error code and failing
node; (b) the batch counter `failed` increments; (c) the batch does not report
success with a non-zero failure count; (d) the operator sees the failure in the
run's exceptions list and in the operations dashboard; (e) a payroll run cannot
progress past preview with unresolved rule-evaluation exceptions (spine S11).

**R-VII-042** There is no configuration, per tenant or per deployment, that
converts a rule-evaluation failure into a silent pass.

---

## 8. Precedence and conflict resolution

### 8.1 The algorithm (spine S3.2, applied)

```
specificity(rule) = Σ weight(d) for each dimension d where scope[d] ≠ ALL

weights:  employee 1000 · team 500 · sub_department 450 · department 400
        · designation 350 · grade 340 · shift 330 · employee_category 320
        · employment_type 310 · employment_stage 300 · cost_centre 250
        · client_process 240 · business_unit 230 · location 200 · branch 180
        · zone 160 · state 140 · establishment 120 · company 100
        · legal_entity 60 · tenant default 0

resolution:
   1. higher specificity wins
   2. on tie, higher declared priority wins
   3. on tie, PUBLICATION IS BLOCKED
```

Weights live in a platform constant table so an unusual hierarchy can be
accommodated by Ametecs without code, and are **not tenant-editable** — a
customer changing weights would silently change every historical resolution
(spine S3.2).

### 8.2 Worked examples

Employee **E-4471**, Priya Raghavan: branch `HYD`, department `OPS`, team
`OPS-COLL-2`, designation `Senior Agent`, grade `G3`, employment stage
`confirmed`, shift `GEN-0930`. Evaluation date **12-Aug-2026**, decision point
`attendance.day.penalise`, policy `all_match` unless stated.

---

**Example 1 — branch beats tenant default (the common case).**

| Rule | Scope | Specificity | Priority |
|---|---|---|---|
| `LATE-DEFAULT` | *(empty)* | 0 | 0 |
| `LATE-HYD` | `branch IN ['HYD']` | 180 | 0 |

Both match. Under `highest_priority_only`, `LATE-HYD` wins on specificity 180 > 0.
Trace records `LATE-DEFAULT` as `not_evaluated (outranked by LATE-HYD)`.

*Replaces:* `NEW-ATT-019` — SmartPRS 2 has no branch scope at all, so this
customer cannot express the rule without a code change.

---

**Example 2 — multi-dimensional, and the intuition trap.**

| Rule | Scope | Specificity | Priority |
|---|---|---|---|
| `OT-HYD-AGENT` | `branch IN ['HYD']`, `grade IN ['G3']` | 180 + 340 = **520** | 0 |
| `OT-OPS` | `department IN ['OPS']` | **400** | 0 |

`OT-HYD-AGENT` wins (520 > 400) even though "department" feels more specific
than "branch" — because it constrains **two** dimensions. The builder shows the
arithmetic (`180 + 340 = 520 vs 400`) beside every candidate, so the author is
never guessing.

---

**Example 3 — an employee-specific exception overrides everything below it.**

| Rule | Scope | Specificity | Priority |
|---|---|---|---|
| `LATE-EMP-4471` | `employee IN ['E-4471']` | **1000** | 0 |
| `OT-HYD-AGENT` | `branch IN ['HYD']`, `grade IN ['G3']` | 520 | 100 |
| `LATE-HYD` | `branch IN ['HYD']` | 180 | 900 |

Under `highest_priority_only`, `LATE-EMP-4471` wins on specificity 1000, **and
priority is not consulted at all.** Priority never crosses a specificity
boundary: it only breaks ties *within* one specificity value. `LATE-HYD`'s
priority 900 is irrelevant against `OT-HYD-AGENT`'s 520 specificity.

**R-VII-043** Priority is subordinate to specificity, always. The builder
displays this explicitly, because "I set priority 900, why did it lose?" is the
predictable support call.

---

**Example 4 — priority genuinely deciding.**

| Rule | Scope | Specificity | Priority |
|---|---|---|---|
| `LATE-HYD-STRICT` | `branch IN ['HYD']` | 180 | **50** |
| `LATE-HYD-LEGACY` | `branch IN ['HYD']` | 180 | 10 |

Equal specificity. `LATE-HYD-STRICT` wins on priority 50 > 10. Publication is
allowed **because the priorities differ**. The publish-time validator records
this as a "priority-resolved overlap" and shows it in the impact preview, so it
is a visible decision rather than an accident.

---

**Example 5 — a genuine tie that blocks publication.**

An administrator publishes `LATE-OPS-A`:

| Rule | Scope | Specificity | Priority | `when` |
|---|---|---|---|---|
| `LATE-OPS-A` | `department IN ['OPS']` | 400 | 0 | `attendance.late_minutes > 30m` |
| `LATE-OPS-B` *(already published)* | `department IN ['OPS']` | 400 | 0 | `attendance.late_minutes >= 20m` |

Both are `highest_priority_only`, identical specificity, identical priority, and
their conditions are **simultaneously satisfiable** (any lateness > 30 minutes
satisfies both). The satisfiability check (§11.4) proves the overlap, and
publication is **blocked**:

```
PUBLICATION BLOCKED — ambiguous precedence

  LATE-OPS-A  (v1, draft)      scope: department IN [Operations]   spec 400, priority 0
  LATE-OPS-B  (v3, published)  scope: department IN [Operations]   spec 400, priority 0

  Both rules can match the same employee-day. Specificity and priority are equal.
  There is no further tie-break.

  Witness            late_minutes = 31m  (satisfies both conditions)
  Employees affected 412 employees in Operations, from 01-Sep-2026
  Sample             E-4471 Priya Raghavan, E-4488 …, E-4502 …  (view all)

  Resolve by one of:
    · give one rule a different priority          [set priority]
    · narrow one rule's scope                     [edit scope]
    · make the conditions mutually exclusive      [edit condition]
    · supersede LATE-OPS-B with LATE-OPS-A        [supersede]
```

**R-VII-044** Ambiguity is a configuration error, never a silent coin-toss.
Publication is refused with both artefacts named, a satisfying witness, the
affected employee count and a sample list. *This is the single most direct
replacement of SmartPRS 2 behaviour in this part: today the tie is broken by
which row the database returned first, in four separate implementations.*

---

**Example 6 — a tie that is *not* a tie, because the conditions are disjoint.**

| Rule | Scope | Spec | Prio | `when` |
|---|---|---|---|---|
| `LATE-BAND-HALF` | `branch IN ['HYD']` | 180 | 0 | `attendance.late_minutes > 120m AND attendance.late_minutes <= 240m` |
| `LATE-BAND-ABSENT` | `branch IN ['HYD']` | 180 | 0 | `attendance.late_minutes > 240m` |

Identical specificity and priority, but the conditions are provably disjoint —
the interval solver shows no value of `late_minutes` satisfies both. Publication
is **allowed**. This is the normal, correct shape for a banded policy, and the
validator must not obstruct it.

---

**Example 7 — cross-dimension tie.**

| Rule | Scope | Specificity |
|---|---|---|
| `X-DESIG` | `designation IN ['Senior Agent']` | 350 |
| `Y-GRADE-SHIFT` | `grade IN ['G3']`, `shift IN ['GEN-0930']` | 340 + 330 = 670 |

No tie: 670 > 350. But note that `X-DESIG` (one very specific dimension) loses to
two moderately specific ones. If the customer disagrees with that outcome, the
remedy is `priority` on `X-DESIG` — which cannot help, since priority does not
cross specificity — or narrowing `X-DESIG`'s scope to add a second dimension.
**PRODUCT OWNER DECISION REQUIRED — D-VII-02, see §20**: whether an artefact may
declare an explicit `specificity_override`.

---

**Example 8 — effective dating decides before precedence does.**

| Rule | Scope | Effective | Spec |
|---|---|---|---|
| `LATE-HYD` v2 | `branch IN ['HYD']` | 01-Apr-2026 → 31-Jul-2026 | 180 |
| `LATE-HYD` v3 | `branch IN ['HYD']` | 01-Aug-2026 → open | 180 |

For 12-Aug-2026, only v3 is a candidate; v2 is not in the resolution set at all
and does not participate in precedence. **August payroll re-run in December still
resolves v3 — and July's re-run still resolves v2.** *This is spine S4's
"resolve with a date inside the period" made concrete, and it is the property
SmartPRS 2 lacks completely.*

### 8.3 Publish-time validation summary

| Check | Severity | Message |
|---|---|---|
| Parse / type error | Block | Node and expected type named |
| Unknown symbol for this decision point | Block | Symbol + the namespaces this decision point declares |
| Action not in the decision point's allow-list | Block | Action + allowed set |
| Static budget exceeded (depth, agg calls, actions, candidates) | Block | Which budget, measured value |
| Effective-range overlap with another version of the same artefact and scope | Block | Both versions and the overlapping range |
| Gap in a `continuous` artefact | Block | The uncovered range |
| **Specificity + priority tie with a satisfiable overlap** | **Block** | §8.2 Example 5 format |
| Specificity + priority tie with provably disjoint conditions | Pass, note | Recorded as "disjoint siblings" |
| Equal specificity, differing priority, overlapping conditions | Warn | "Priority-resolved overlap" + impact preview |
| Rule matches zero employees today | Warn | "This rule currently matches no employees" |
| Rule matches > 20% of the tenant with a money-affecting action | Warn + require approval | Impact preview mandatory |
| Stored test case fails | Block | The failing case, expected vs actual, with the trace |
| Company policy below statutory baseline | Block or Warn by pack severity (spine S12) | The statutory reference |
| `except` references only org dimensions | Lint | "Move to scope so it participates in specificity" |
| `first_match` on a `high_risk` rule | Block | Use `highest_priority_only` so the trace shows the alternatives |

---

## 9. The decision trace

### 9.1 Why it is structured, not prose

SmartPRS 2's only explanation mechanism is a free-text calculation note, and the
payslip recovers Paid Days and LOP from it **by regex** —
`/Paid\s+([\d.]+)\s+of\s+(\d+)\s+days/i` — printing `daysInMonth / 0` when the
sentence is absent, which it is whenever `factor >= 0.9999`
(`AppDataController.php:1278-1282`, `calcNote():537-540`). The trace is therefore
specified as **machine-readable first, rendered second**.

### 9.2 Structure

```
DecisionTrace {
  trace_id            uuid7
  tenant_id           bigint
  decision_point      code
  subject             { type, id, uuid, date }
  as_of               date
  engine_version      text          -- rule engine + SPX catalogue + action catalogue
  status              matched | no_match | failed
  input_digest        char(64)      -- SHA-256 of the canonical prepared context
  candidates          [ CandidateTrace ]
  actions             [ ActionTrace ]
  counters            { rules_considered, rules_matched, nodes, agg_calls, micros }
  error               { code, rule_code, node_path, message }?   -- when failed
  created_at
}

CandidateTrace {
  rule_code, rule_version_id, version_no, checksum
  name, business_description, source_ref?
  scope_summary       text          -- "branch = Hyderabad, grade = G3"
  specificity, priority, order_index
  outcome             matched | not_matched | matched_then_excepted
                    | not_evaluated_outranked | not_evaluated_stopped | error
  when                NodeTrace
  except              NodeTrace?
}

NodeTrace {                          -- one per evaluated AST node, pruned (§9.4)
  op                  text           -- ">", "AND", "agg.count", "BANDS", …
  span                [start,end]    -- offsets into when_src, for highlighting
  result              typed value | unknown | error
  children            [ NodeTrace ]
  inputs              [ { symbol, value, provenance } ]   -- leaves only
  evaluated           true | skipped_short_circuit
}

ActionTrace {
  action_code, sequence, fingerprint
  parameters          [ { name, expr_src?, resolved_value, formula_ref?,
                          formula_trace_ref? } ]
  emitted_by          { rule_code, rule_version_id }
  effect_class        derived | ledger | terminal
  applied             true | superseded | deduplicated | blocked
}
```

`provenance` on an input leaf names where the value came from: the table and row,
the version id of the artefact that produced it, or the counter and generation
for an `agg.*` value. This is what lets "How was this calculated?" drill down.

### 9.3 A rendered example

For `attendance.day.penalise`, employee E-4471, 12-Aug-2026:

```
How was this calculated?

  Date                12-Aug-2026 (Wednesday, working day)
  Shift               GEN-0930  09:30–18:30, grace 10m
                      resolved from: roster → shift group
  Scheduled IN        12-Aug-2026 09:30:00 IST
  Actual IN           12-Aug-2026 11:42:00 IST
  Late                132 minutes   (computed from the shift-start instant)

  RULE  HYD-LATE-BAND  v4   "Banded lateness — Hyderabad"
        effective 01-Apr-2026 →   ·   scope: branch = Hyderabad   ·   spec 180, priority 0
        "Over two hours late is half a day; over four hours is treated as absent."

        WHEN   attendance.late_minutes > policy.value('late_band_half_min')
                      132m           >            120m                 → TRUE
               AND attendance.late_minutes <= policy.value('late_band_absent_min')
                      132m           <=           240m                 → TRUE
                                                                       → MATCHED
        EXCEPT day.is_holiday                       → FALSE  (not excepted)

        THEN   APPLY_DAY_PENALTY(fraction = 0.5 days, reason = 'Late > 2h')
               NOTIFY(event = 'attendance.late_penalty', dedupe = 'E-4471:2026-08-12')

  RULE  LATE-DEFAULT  v2  — not evaluated (outranked by HYD-LATE-BAND, spec 0 < 180)
  RULE  HYD-LATE-GRACE v6 — not matched (late_minutes 132m > grace 10m)

  HR override         none
  Payroll impact      0.5 payable day  →  see payslip line "LOP" for the money
  Counters            3 rules considered · 1 matched · 41 nodes · 0 agg calls · 0.9 ms
```

**R-VII-045** Every derived number in SmartPRS 3 exposes this view. A screen
showing a derived value without a trace link fails the UI conformance sweep.

### 9.4 Storage, cost and retention

A full trace for a typical attendance day is **1.5–4 KB** as canonical JSON,
**0.4–1.1 KB** compressed (zstd). For 10,000 employees × 31 days × 3 attendance
decision points, a full month is roughly 1.4 million traces ≈ **0.6–1.5 GB
compressed**. That is affordable for one month and not affordable for seven
years across every module.

The storage model is therefore three-tier:

| Tier | Content | Where | Retention |
|---|---|---|---|
| **Pinned** | `input_digest`, the matched rule version ids, the resulting actions and their fingerprints | **Columns on the derived record itself** (`attendance_days`, `payslip_lines`, `leave_charges`) | Life of the record. Never purged |
| **Full trace** | The complete `DecisionTrace` including every node | `decision_traces`, compressed, partitioned by month | Retention class `trace`, default **13 months**, tenant-configurable upward |
| **Reproduced** | Regenerated on demand from the pinned data | Computed | n/a |

**R-VII-046** A full trace is always **reproducible** from the pinned data,
because evaluation is deterministic and the input snapshot is versioned: given
the same `input_digest` and the same pinned rule versions, re-evaluation
reproduces the identical trace. Purging a full trace therefore loses retrieval
speed, never evidence. The UI, on a purged trace, says "regenerating…" and
re-derives.

**R-VII-047** Trace writing is **asynchronous and batched** (one bulk insert per
subject batch, not per subject), and a trace-write failure does **not** fail the
derivation — it raises an operator alert and marks the derivation
`trace_deferred`, which the reproduce path then satisfies. Pinned data is
written synchronously with the derived record, in the same transaction.

Measured cost budget: trace construction ≤ **12%** of evaluation wall time,
asserted by a performance test (§19).

### 9.5 PII in traces

**R-VII-048** A trace stores **codes and derived values**, never raw sensitive
attributes. `employee.dob`, bank details, PAN, Aadhaar and salary component
amounts appear in a trace only when they were genuinely an input, and then only
under the same field-level masking as anywhere else (spine S13). A trace is
subject to the same data-scope authorisation as the record it explains: an
employee sees the trace of their own day; a manager sees their reportees'; nobody
sees a trace for a subject outside their scope.

**R-VII-049** Trace access is audited. *SmartPRS 2's leave-balance endpoint lets
any authenticated user read any colleague's balances by name, code or numeric id
(`LeaveController::balances():247-264`, SEC-07) — leave consumption is a proxy
for sickness patterns, and a trace is strictly more revealing.*

---
## 10. Safety

The claim to be defended: **a customer-authored rule cannot execute arbitrary
code, cannot exhaust resources, and cannot read data the author is not entitled
to.** The argument is structural, not procedural, and is made in four parts.

### 10.1 The argument

#### (a) It cannot execute arbitrary code

1. **The grammar has no production for it.** §5.3 is the whole language. There is
   no statement, no assignment, no block, no function definition, no closure, no
   method call on a value, no dynamic dispatch and no string-to-code operation.
   `str_contains`-style host functions, `eval`, `include`, backticks, `system`,
   `curl` and the Laravel service container are not reachable because there is no
   syntax that names them.
2. **Function names resolve against a closed catalogue at compile time.** An
   unknown function is a publish-time error. There is no fallback, no dynamic
   resolution and no `__call`.
3. **The stored artefact is an AST, not source.** Evaluation walks a typed AST
   of a closed node set with a switch over node kinds. There is no code
   generation, no `eval`, no `create_function`, no serialised-object
   deserialisation of untrusted data, and no template compilation.
4. **PHP object deserialisation never touches rule payloads.** The AST is stored
   in a versioned binary encoding with an explicit reader; `unserialize()` is
   forbidden on any tenant-supplied bytes by a CI static-analysis rule.
5. **Customer-supplied PHP, JavaScript or Python is never accepted anywhere in
   SmartPRS 3, under any configuration, for any customer** (spine S5). There is
   no "advanced mode", no scripting hook, no webhook that executes, and no
   plugin directory. On-premise is identical — there is no `if (on_prem)` escape
   (spine S18).

**R-VII-050** A CI test asserts the SPX evaluator's node dispatch handles exactly
the node kinds in the grammar and rejects any other, and a fuzz corpus of
100,000 mutated inputs produces only parse errors, type errors or bounded
evaluations — never an uncaught throwable and never a call outside the evaluator
namespace.

#### (b) It cannot exhaust resources

Every unbounded construct is absent or bounded:

| Risk | Control |
|---|---|
| Unbounded loop | **No loop exists in the grammar** |
| Recursion | No user functions; AST depth capped at 32 at publish time |
| Expression blow-up | Node budget 5,000 at runtime; source length 8,000; list literal 500 |
| Catastrophic regex backtracking | `MATCHES` takes a literal pattern only; anchored by the compiler; rejected at publish if it contains nested unbounded quantifiers; evaluated under a 10,000-step limit; PCRE backtrack and recursion limits set explicitly |
| Unbounded data reads | `agg.*` is the only construct reading beyond the subject; nesting is a publish-time error; ≤ 8 calls per rule; ≤ 20,000 rows per call; predicates restricted to registered counter definitions where materialised |
| Rule-count blow-up | ≤ 200 candidate rules per decision point per subject, enforced at publish with the scopes named |
| Time | 20 ms per rule, 200 ms per decision point per subject; batch-level deadline and circuit breaker (§14.5) |
| Memory | The prepared context is assembled once per subject with a declared, finite symbol set; no rule can request more data than the decision point declares |
| Queue/worker starvation | Rule evaluation in batch runs on a dedicated queue with a concurrency cap; a tenant's batch cannot occupy every worker (§14.5) |

**R-VII-051** Every budget breach is **attributable**: the trace names the rule,
the version, the node and the budget. A tenant whose rules repeatedly breach
budgets appears on the operations dashboard with the offending rules listed. A
noisy neighbour is a named artefact, not a mystery.

#### (c) It cannot read data it should not

This is the property most easily lost, and it is guaranteed by the **prepared
context**, not by the language.

1. **The engine has no database access.** The evaluator is constructed with a
   `PreparedContext` — a finite, typed, immutable value object. It holds no
   connection, no repository, no container and no query builder. There is no
   symbol that can reach a row the context does not contain.
2. **The context is assembled by the domain service, after authorisation, under
   tenant scope.** It contains data about **one subject** (plus that subject's
   own aggregates). There is no `employee('E-0001').salary` accessor and no way
   to name another subject: the grammar's keyed accessors take a *code from a
   value set* (a component code, a leave type, a credential type), never an
   employee identifier.
3. **`agg.*` is subject-scoped by construction.** Its signature has no subject
   parameter. The aggregation resolver receives the subject from the context, not
   from the expression.
4. **The decision point's symbol table is the ceiling.** A rule at
   `attendance.day.classify` cannot read `salary.*` because that namespace is not
   declared there — checked at publish, and the evaluator has no binding for it
   at runtime either. Two independent gates, one static and one dynamic.
5. **Field-level masking applies inside the context.** A field the author's role
   may not see is not placed in the context, and referencing it is a publish-time
   authorisation error naming the required permission.
6. **Tenancy is structural** (Law 6). The context is built through the
   persistence layer's tenant scope; there is no rule-visible tenant parameter.

**R-VII-052** A generated cross-tenant test exists for every decision point: a
rule published in tenant A, evaluated with tenant B's subject, must fail at
resolution, not at evaluation — the rule is not a candidate at all.

**R-VII-053** The rule engine cannot widen an authorisation decision. Its actions
contain no verb that grants access (§6.2), and authorisation is evaluated
independently, server-side, failing closed (Law 5). *SmartPRS 2 inverts this:
`denyUnlessRole()` returns `null` — meaning **allowed** — on any exception,
across 71 call sites including `payCommission`, `salaryPay` and
`lockCommission` (`ApprovalService.php:365-380`, NEW-REQ-106).*

#### (d) It cannot reach the network or the filesystem

There is no URL type, no HTTP function, no file function, no connector
invocation, no template include and no external lookup in SPX. Integration is
the connector layer's job, governed by an egress allow-list (spine S17).
*SmartPRS 2's broadcast provider POST sends the API key under three field names
to whatever host an administrator typed, with no scheme or host allow-list
(`SendMessageController::postProvider():234-266`, SEC-12). If a rule could
express an outbound call, that defect would be reintroduced as a feature.*

### 10.2 Threat model

#### Threat 1 — a malicious tenant administrator

*Capability:* holds `rules.publish.<module>` in their own tenant; may author any
scope, condition and action the catalogue permits.

| Attack | Outcome | Control |
|---|---|---|
| Read another employee's salary via a rule | Impossible | No cross-subject accessor exists (§10.1c-2) |
| Read another tenant's data | Impossible | Context is tenant-scoped; rule is not a candidate cross-tenant (R-VII-052) |
| Exfiltrate data to an external host | Impossible | No network primitive (§10.1d) |
| Grant themselves a permission or widen a data scope | Impossible | No such action exists (R-VII-031) |
| Delete evidence | Impossible | No delete verb (R-VII-030); audit ledger is append-only and hash-chained (spine S14) |
| Pay themselves | **Possible in principle** — `ADD_COMPONENT` scoped to `employee IN ['their own code']` | **Detected and controlled**: money-affecting actions force `requires_approval` (maker-checker, spine S8); the publisher cannot approve their own publication (self-approval prohibition); the impact preview names every affected employee; a rule whose scope includes the author's own employee record is flagged `self-affecting` in the diff; the payroll run's exception list surfaces rule-added components; and the audit ledger records author, approver, diff and effective date |
| Suppress a statutory deduction | **Blocked or warned** by the statutory baseline validator (spine S12) with an explicit, audited compliance exception where the pack permits one |
| Denial of service on their own tenant | Bounded | Budgets (§10.1b), per-tenant concurrency cap, and the offending rule named on the ops dashboard |
| Denial of service on other tenants | Prevented | Per-tenant queue concurrency cap and batch deadline (§14.5) |

**R-VII-054** The `rules.publish.*` permission is separate from
`rules.author.*`. Authoring is safe; publishing is the controlled act. Publishing
a money-affecting rule requires a second, different human.

#### Threat 2 — a compromised implementation consultant

*Capability:* legitimate elevated access during onboarding, plausible reasons to
touch many artefacts, and — the real risk — the ability to make a harmful change
look routine.

| Attack | Control |
|---|---|
| Publish a broad rule during a busy go-live | Impact preview is **mandatory** for any rule affecting > 20% of the tenant or emitting a money action; the preview is stored with the version and shown to the approver |
| Back-date `effective_from` to rewrite settled periods | **Blocked.** `effective_from` earlier than the last locked period end is refused; overriding requires a period reopen, which is itself an audited workflow (spine S11) |
| Publish, take effect, then delete the rule | Impossible. Versions are never deleted; `archived` still shows in history; the trace on every affected record pins the version id |
| Slip a change into a rule pack import | Pack import runs the conflict detector and the full regression harness; a signed Ametecs pack is signature-verified; a tenant pack shows a full diff before import (R-VII-010) |
| Weaken the DRA/compliance gate quietly | `compliance.payment_gate` rules are `high_risk` by default: diff + approval + impact preview, and gate changes emit `rule.gate_changed` for alerting |
| Grant themselves lasting access | Rules cannot touch permissions (R-VII-031); consultant accounts are time-boxed with MFA (spine S13) |
| Exfiltrate via an export | Rule content is exportable — that is intended — but **rule content is not employee data**; exports of employee data are separately permissioned and audited |

**R-VII-055** Every publication writes an audit row with author, approver, diff
hash, impact-preview summary, effective date and reason. A publication without a
reason is refused for `high_risk` artefacts.

#### Threat 3 — the accidental catastrophic rule

The likeliest and most expensive threat. Not malice: a scope wider than intended,
a unit confused, an inverted comparison, a band with a gap.

| Failure | Control |
|---|---|
| Scope wider than intended (`ALL` left on a dimension) | Impact preview shows the exact employee count and a sample; a jump of more than a configurable factor over the previous version requires explicit confirmation |
| Unit confusion (`Days` where `Minutes` meant) | **Publish-time type error.** `Days` and `Minutes` do not coerce (R-VII-013) |
| Inverted comparison (`<` for `>`) | The simulator (§11.3) over real employees, and the stored regression tests, which run on publish |
| Band gap or overlap | `BANDS` validates sorted, contiguous, non-overlapping, fully covered at publish |
| Missing else-branch | `CASE` requires `ELSE` (R-VII-011) |
| Rule matching nobody (a typo in a code) | Warned at publish: "this rule currently matches no employees" |
| Rule matching everybody | Warned + approval + impact preview |
| Runaway penalty (a rule charging 30 LOP days) | Per-period **caps** are declarable on `APPLY_DAY_PENALTY` and `APPLY_LOP` (`cap_ref`); the payroll preview blocks on out-of-band values (negative net, minimum-wage breach) before any money moves (spine S11) |
| Silent breakage of last year's payroll | Structurally impossible: settled periods resolve rules by their own dates (§8.2 Example 8) |
| Discovery only at payday | The payroll run cannot pass preview with unresolved exceptions; the impact preview runs **before** publication, on real data |

**R-VII-056** Every rule may carry stored test cases, and **publishing runs them**
(spine S6). For `high_risk` rules at least one stored test case is
**mandatory**; publication without one is refused.

**R-VII-057** A published rule can be **withdrawn** by publishing a superseding
version with an `effective_from` of tomorrow, or **suspended immediately** by an
emergency `suspend` action that closes `effective_to` at today, requires a
reason, alerts, and marks every record derived under it as stale for
recalculation. Suspension never deletes and never rewrites history.

### 10.3 Regex specifically

`MATCHES` is the only construct with a super-linear worst case, so it is
constrained beyond the general budget:

- Pattern must be a **string literal** (R-VII-012), so it is known at publish.
- The compiler **anchors** it (`\A…\z`) — a rule cannot accidentally match a
  substring.
- The publish-time linter rejects nested unbounded quantifiers (`(a+)+`,
  `(a*)*`, `(a|aa)+`), backreferences, lookbehind and recursion.
- Runtime evaluation sets `pcre.backtrack_limit` and `pcre.recursion_limit`
  explicitly for the call and treats `PREG_BACKTRACK_LIMIT_ERROR` as
  `ERR_REGEX_STEPS`.
- Subject length is capped at 512 characters (the `Text` limit).
- `MATCHES` is not permitted on a `Code`, so it cannot be used to build
  name-based identity semantics (§5.6, Text).

**R-VII-058** A regression corpus of known catastrophic patterns is asserted to
be rejected at publish time.

### 10.4 What this does not protect against

Stated plainly, because a safety argument that claims everything is not credible:

- **A correct rule expressing a bad policy.** If an administrator publishes "all
  Operations staff lose a day for any lateness", the engine will do it, correctly
  and traceably. The controls are maker-checker, impact preview and audit — not
  prevention.
- **Wrong reference data.** A rule reading `policy.value('late_grace_min')` is
  only as right as that value. The formula and rule packs are versioned, dated
  and diffed; the value itself is a human decision.
- **A defect in the platform.** SPX safety rests on the evaluator being correct.
  That is addressed by fuzzing, property tests, the determinism golden hash and
  the conformance suite — not by the language design.

---

## 11. Tooling

Each item is a deliverable, not an aspiration (spine S6).

### 11.1 The visual rule builder

**Interaction model.** Four panes, one screen.

```
┌ Scope ────────────────┬ Condition ──────────────────────┬ Preview ─────────┐
│ dimension pickers,    │ condition tree: group (AND/OR),  │ live count of    │
│ each ALL by default;  │ leaf = [symbol][operator][value] │ matching         │
│ specificity total     │ drag to reorder / nest           │ employees;       │
│ shown live: 520       │ ─────────────────────────────    │ sample of 5;     │
│                       │ Except: (optional second tree)   │ this-month       │
├ Then ─────────────────┴──────────────────────────────────┤ simulation       │
│ ordered action cards; each card is the action's typed     │ against real     │
│ parameter form; add/remove/reorder; conflicting actions   │ data             │
│ flagged inline                                            │                  │
└───────────────────────────────────────────────────────────┴──────────────────┘
```

Interaction rules:

1. **Symbol picker, never free text.** A leaf's left operand is chosen from the
   decision point's symbol table, grouped by namespace, with type shown. Typing
   filters; it does not create.
2. **The operator list is filtered by the left operand's type.** Choosing
   `attendance.late_minutes` (`Minutes`) offers `= <> < <= > >= BETWEEN`, not
   `CONTAINS` or `MATCHES`.
3. **The value editor is typed.** `Minutes` gives a minutes spinner with an
   `h/m` toggle; `Code` gives the value set's picker with labels; `Date` gives a
   native date input; `Money` gives an Indian-format amount field. **Typing a
   number into a `Days` field cannot produce `Minutes`.**
4. **A value may be switched to a policy reference or an expression** with one
   control. Referencing `policy.value('late_band_half_min')` instead of typing
   `120` is a single click and is the recommended pattern; the builder nudges
   toward it whenever a literal is used more than once across a tenant's rules.
5. **Specificity is live.** The scope pane shows `180 + 340 = 520` and, beside
   it, "3 published rules at this decision point have higher specificity — view".
6. **Conflicts are shown while editing**, not at publish: the builder runs the
   detector against the draft continuously and shows any tie inline.
7. **Round-trips losslessly with the expression view.** Every builder tree
   corresponds to exactly one canonical SPX source, and any SPX expression within
   the builder's expressible subset renders back into the tree. Expressions
   outside that subset (nested `CASE`, `BANDS` with many bands) show as a single
   read-only "advanced expression" node with an edit-in-source affordance — never
   silently rewritten.
8. **Business description is a required field with a placeholder in the
   customer's own words**, because it prints in the trace.
9. **Accessibility**: full keyboard operation, every control labelled, tree
   structure exposed to assistive technology as a tree.

### 11.2 The advanced expression editor

- SPX source with syntax highlighting and inline diagnostics.
- Autocomplete over the decision point's symbol table and the function
  catalogue, with signature help showing parameter types and the return type.
- Hover on a symbol shows its type, description, provenance and a **sample value
  for the currently selected test employee**.
- Type errors are shown as you type, with the expected and actual type.
- A "why is this symbol unavailable?" affordance naming the decision point and
  the namespaces it declares.
- Format/canonicalise command producing the exact source that will be stored.

### 11.3 The simulator

**R-VII-059** The simulator runs the real engine on the real evaluation path with
side effects structurally absent (§6.1), against:

- a **real employee and date** (read-only, permission-checked);
- a **synthetic employee** built from a form, for pre-go-live work;
- a **cohort** (a scope expression), producing an outcome distribution;
- a **date range**, producing the day-by-day outcome for one employee — the view
  that makes "3 lates in a month → half day" visible as it accumulates.

It shows the full trace (§9.3), including non-matching and outranked rules, and
supports draft rules (`status = draft | testing`) so an unpublished rule can be
evaluated against production data **without being published**.

The simulator also answers the inverse question — **"why did this NOT happen?"**
— by listing every candidate rule with the specific node that evaluated false.
That question is the majority of the support load on any rules product.

### 11.4 The conflict detector

Runs continuously in the builder, and blocking at publish.

**Algorithm.**

1. Group published + candidate rules by `(decision_point, specificity, priority)`.
2. Within a group, test **scope intersection**: do the two scopes admit a common
   employee? Scope is a conjunction of set memberships, so intersection is
   decidable exactly; the check also resolves against the current employee
   population to report a real count and sample.
3. For intersecting pairs, test **condition satisfiability**: can both `when`
   clauses be true for one subject?
   - **Interval and set solver** over the common decidable fragment: numeric and
     temporal comparisons, `BETWEEN`, `IN`, equality on `Code` and `Boolean`,
     combined with `AND`/`OR`/`NOT`. This fragment covers the overwhelming
     majority of authored rules and yields a **witness** (`late_minutes = 31m`)
     when satisfiable and a **proof of disjointness** when not.
   - **Outside the decidable fragment** (an expression involving `agg.*`,
     `MATCHES`, or arithmetic the solver does not model), the detector reports
     `undecidable` and treats the pair as **conflicting** — refusing to publish —
     unless the author supplies a differing `priority`. Fail-safe, and it makes
     the resolution a one-click decision rather than a research project.
4. Report per §8.2 Example 5.

**R-VII-060** The detector must never report a false *negative*: if it cannot
prove disjointness, it treats the pair as a conflict. False positives are
acceptable and resolvable with a priority; a false negative would reintroduce
row-order resolution.

### 11.5 The impact preview

For a candidate version, computed against live data at a chosen effective date:

- employees matched now, and the delta against the version being superseded;
- for each affected employee, the **outcome under the old rule set and the new
  one**, side by side, for a chosen sample period;
- aggregate money delta where the actions are money-affecting, in ₹, with a
  breakdown by component;
- affected modules and downstream artefacts (which formulas, which workflows);
- the count of already-settled records that would become **stale** if the
  effective date were back-dated (normally zero, because back-dating into a
  locked period is refused);
- a hash of the preview, stored with the version, so the approver approves a
  specific, immutable preview.

**R-VII-061** The impact preview is **mandatory** before publishing any rule that
is `high_risk`, emits a money-affecting action, or matches more than a
configurable share (default 20%) of the tenant's employees.

### 11.6 The stored-test regression harness

```
rule_test_cases
  id, tenant_id, rule_id,        -- the rule under test (nullable for pack-level cases)
  name, description,
  fixture_json,                  -- the prepared-context inputs, typed and validated
  as_of DATE,
  expect_match BOOLEAN,
  expect_actions_json,           -- ordered expected AppliedAction list (params resolved)
  expect_error_code,             -- for negative cases
  is_seeded, created_by, created_at, last_run_at, last_status
```

- Cases are authored from the simulator with one click ("save this as a test"),
  which captures the fixture and the observed outcome as the expectation.
- **Publishing runs every test case of every rule at the affected decision
  point**, not just the rule being published — because a new rule changes
  precedence for its siblings.
- A failing case **blocks** publication and shows the diff plus the full trace.
- Cases run in CI for seeded packs, and nightly per tenant, so drift caused by
  reference-data changes is caught before payday rather than at it.

**R-VII-062** Every seeded rule shipped by Ametecs carries at least three test
cases: one matching, one not matching, and one boundary. The migration in §13
seeds them from the worked examples in the as-is analysis (each `CALC-B-*` and
`CALC-*` block already carries a worked example with an exact expected value).

### 11.7 Supporting tools

| Tool | Purpose |
|---|---|
| **Rule search** | Find every rule referencing a symbol, a policy key, a component code or a value-set member — the "what breaks if we rename this?" query |
| **Rule diff** | Semantic diff of two versions: scope delta, condition delta as a tree, action delta |
| **Coverage report** | For a decision point, which employees are governed by which rule, and **which employees are governed by none** |
| **Trace explorer** | Filter traces by rule, outcome, date, employee; the operational view for "why did 40 people get a penalty yesterday?" |
| **Dry-run recalculation** | `recalculate --preview` producing the diff without writing (spine S10) |
| **Rule library / pack browser** | Ametecs-published starter packs by industry, importable and then narrowed |

---
## 12. Worked rule library

Sixteen real rules, in full SPX, each drawn from a behaviour the as-is analysis
found hard-coded in SmartPRS 2. Each carries its decision point, its scope, its
specificity, its trace shape and the citation it replaces.

**Convention used throughout.** A threshold that one rule owns is written
inline; a threshold shared by several rules, or one an administrator will tune
without touching logic, is written `policy.value('<key>')` and lives in an
effective-dated policy artefact. Both are configuration; the second is
configuration an administrator can change **without opening the rule**. No
statutory value appears as a literal anywhere — statutory values come from
`statutory.value()` and the signed pack (spine S12).

---

### RL-ATT-001 · Three late arrivals over ten minutes in a payroll month → half day

This is the governing brief's Section 127 acceptance test.

```
decision_point : attendance.day.penalise
scope          : { ALL }                       -- tenant default
specificity    : 0        priority : 0         on_conflict : all_match
effective      : 01-Apr-2026 →
```

```sql
-- WHEN
attendance.late_minutes > policy.value('late_grace_min')
AND agg.count(
        late_arrival,
        payroll_period,
        { event.value > policy.value('late_grace_min') }
    ) >= 3

-- EXCEPT
day.is_holiday OR day.is_weekly_off

-- THEN
1. APPLY_DAY_PENALTY(
       fraction = 0.5d,
       reason   = 'Third or later late arrival over 10 minutes this payroll month',
       cap_ref  = 'ATT-PENALTY-CAP-MONTH')
2. NOTIFY(
       event       = 'attendance.late_penalty',
       audience_ref= 'AUD-EMP-AND-MANAGER',
       dedupe_key  = CONCAT(employee.code, ':', day.date))
```

`policy.value('late_grace_min')` is `10m` in the seeded pack. `payroll_period`
resolves from the company's effective-dated pay cycle, honouring the cut-off day
(R-VII-022).

**Trace (employee E-4471, 26-Aug-2026, fourth qualifying late):**

```
WHEN  attendance.late_minutes (23m) > policy late_grace_min (10m)          TRUE
      agg.count(late_arrival, payroll_period 2026-08 [01-Aug…31-Aug],
                predicate event.value > 10m) = 4                            TRUE
                counter: agg_counters#88213 gen 7, materialised, 1 row read
                occurrences: 05-Aug 18m · 12-Aug 132m · 19-Aug 11m · 26-Aug 23m
                                                                          → MATCHED
EXCEPT day.is_holiday FALSE OR day.is_weekly_off FALSE                     FALSE
THEN  APPLY_DAY_PENALTY 0.5 days   (month total after this action: 1.5 days,
                                    cap ATT-PENALTY-CAP-MONTH = 3.0 days)
```

**Replaces:** `PayrollGenController::attendanceCut():449-455` and
`simpleCut():498-513` — free lates counted only per payroll month
(BR-ATT-022), only three tiers (BR-ATT-021), the count window not
configurable, and the whole penalty ladder a PHP literal set.

---

### RL-ATT-002 · Banded lateness — over two hours is half a day, over four hours is absent

```
decision_point : attendance.day.penalise (band → penalty)
                 + attendance.day.classify (band → status)
scope          : { branch IN ['HYD'] }
specificity    : 180      priority : 0          on_conflict : highest_priority_only
```

Penalty rule:

```sql
-- WHEN
attendance.late_minutes > policy.value('late_band_half_min')

-- THEN
1. APPLY_DAY_PENALTY(
       fraction = BANDS(attendance.late_minutes, [
             { from: policy.value('late_band_half_min'),
               to:   policy.value('late_band_absent_min'), result: 0.5d },
             { from: policy.value('late_band_absent_min'),
               to:   NULL,                                 result: 1.0d }
         ]),
       reason = CONCAT('Lateness band: ', TO_HOURS(attendance.late_minutes), ' h'))
```

Companion classify rule (so the *status*, not only the money, is right):

```sql
-- WHEN
attendance.late_minutes > policy.value('late_band_absent_min')
-- THEN
SET_STATUS(code = 'ABSENT')
```

Seeded values: `late_band_half_min = 120m`, `late_band_absent_min = 240m`. A
fourth band is a row in the `BANDS` list, not a code change.

**Trace fragment:** as rendered in §9.3.

**Replaces:** `PayrollGenController::tierCut():482-495` — exactly three tiers
L1/L2/L3, no fourth, no per-tier grace, and the **fall-through at `:494` that
silently charges a sub-L1 late at the L1 rate** (BR-ATT-021b). `BANDS` is
validated contiguous and fully covered at publish, and `CASE` requires `ELSE`
(R-VII-011), so that hidden rule cannot recur.

---

### RL-ATT-003 · Grace up to ten minutes, with a per-branch override

Two rules at the same decision point, resolved by specificity.

**(a) Tenant default**

```
decision_point : attendance.grace
scope          : { ALL }                specificity : 0    on_conflict : highest_priority_only
```
```sql
-- WHEN
TRUE
-- THEN
SET_GRACE(minutes = policy.value('late_grace_min'))          -- 10m seeded
```

**(b) Hyderabad override**

```
scope          : { branch IN ['HYD'] }  specificity : 180
```
```sql
-- WHEN
TRUE
-- THEN
SET_GRACE(minutes = 15m)
```

**(c) Night-shift override, more specific still**

```
scope          : { branch IN ['HYD'], shift IN ['NIGHT-2200'] }   specificity : 180+330 = 510
```
```sql
-- WHEN  TRUE      -- THEN  SET_GRACE(minutes = 20m)
```

For an E-4471 day on the general shift, (b) wins over (a); on the night shift,
(c) wins over both. All three appear in the trace, two marked
`not_evaluated (outranked)`.

**Replaces:** grace exists in SmartPRS 2 only as `shifts.grace_min` or
`late_policy.grace_min`, resolved by the three-level company/team/employee ladder
with row-order ties (`AttendanceReportController.php:828-836`;
`LateArrivalService.php:184-204`, NEW-ATT-019). There is **no branch scope**, and
the report, the Today panel, the late email and payroll each resolve it
differently (NEW-ATT-008).

---

### RL-ATT-004 · Late logout offsets late arrival — Operations department only

```
decision_point : attendance.day.penalise
scope          : { department IN ['OPS'] }
specificity    : 400      priority : 100        on_conflict : all_match
stop_on_match  : false
```

```sql
-- WHEN
attendance.late_minutes > policy.value('late_grace_min')
AND attendance.late_exit_minutes > 0m
AND attendance.worked_minutes >= shift.full_day_minutes

-- EXCEPT
day.is_weekly_off OR day.is_holiday

-- THEN
OFFSET_SHORTFALL(
    minutes = MIN(attendance.late_minutes, attendance.late_exit_minutes),
    source  = 'MADE_UP_TIME',
    cap     = policy.value('offset_daily_cap_min'))          -- 60m seeded
```

`OFFSET_SHORTFALL` reduces `attendance.late_minutes` **for subsequent rules at
this decision point**, which is why it carries `priority 100` — it must be
evaluated before the penalty rules. Ordering within `all_match` follows candidate
order (§7.4), so this is deterministic and visible in the trace.

**Trace:**

```
late_minutes 23m · late_exit_minutes 41m · worked 548m >= full 540m
OFFSET_SHORTFALL applied: MIN(23m, 41m) = 23m, capped at 60m → 23m
effective late_minutes for later rules: 0m
RL-ATT-001  not matched (effective late_minutes 0m is not > 10m)
```

**Replaces:** nothing — SmartPRS 2 computes `overtime_min` and `early_min` for
**display only** and never feeds either back into a penalty
(`AttendanceReportController.php:729-735`, CALC-B-10; backlog b.25 records that
early departure has no penalty rule at all). The `net_hours` mode
(`PayrollGenController.php:441-448`) is a crude tenant-wide approximation that
forgives lateness entirely and cannot be scoped to one department.

---

### RL-ATT-005 · Overtime — Hyderabad branch, Agent grade, above a daily hours threshold

```
decision_point : attendance.day.earn
scope          : { branch IN ['HYD'], grade IN ['G3'] }
specificity    : 180 + 340 = 520      priority : 0      on_conflict : all_match
```

```sql
-- WHEN
attendance.worked_minutes > TO_MINUTES(policy.value('ot_daily_threshold_hours'))
AND day.is_working_day
AND employee.stage <> 'probation'

-- EXCEPT
NOT policy.value('ot_enabled')

-- THEN
CREATE_OT(
    minutes        = STEP(
                        attendance.worked_minutes
                          - TO_MINUTES(policy.value('ot_daily_threshold_hours')),
                        policy.value('ot_rounding_block_min'),      -- 30m seeded
                        policy.value('ot_rounding_block_min'),
                        'floor'),
    multiplier_ref = 'OT-MULT-WEEKDAY',
    approval       = 'workflow')
```

Weekly-off and holiday overtime are **separate rules** with their own
multiplier references, because the multiplier differs — a distinction SmartPRS 2
cannot express at all.

**Trace:**

```
worked 612m > threshold 8h (480m)                                  TRUE
day.is_working_day TRUE · stage 'confirmed' <> 'probation'         TRUE   → MATCHED
EXCEPT NOT policy ot_enabled (TRUE) → FALSE                        not excepted
CREATE_OT minutes = STEP(612 − 480 = 132m, block 30m, step 30m, floor) = 120m
          multiplier OT-MULT-WEEKDAY (formula OT-RATE v2)   approval: workflow OT-APPROVE
```

**Replaces:** `MasterController::save():755-763` and its duplicate at
`PayrollGenController::simulate():2165` — OT is a **manually keyed register**
(BR-PAY-016, `MasterController.php:339-345`), valued as
`ctc / 12 / 26 / 8 × hours × multiplier` with **26 and 8 as literals in both
copies** (BR-PAY-732), paid as a flat line attracting no PF, ESI, PT or TDS
(BR-STAT-006), and derived from punches nowhere. The report's `overtime_min` is
display only and never reaches payroll (CALC-B-10).

---

### RL-ATT-006 · Minimum-hours presence gate

```
decision_point : attendance.day.classify
scope          : { ALL }
specificity    : 0        priority : 0          on_conflict : highest_priority_only
```

```sql
-- WHEN
attendance.punch_count > 0
AND attendance.worked_minutes < policy.value('present_min_minutes')

-- THEN
SET_STATUS(
    code = CASE
             WHEN attendance.worked_minutes >= policy.value('half_day_min_minutes')
                  THEN 'HALF_DAY'
             ELSE 'ABSENT'
           END)
```

A companion rule at specificity 0, priority −10, sets `PRESENT` when
`attendance.worked_minutes >= policy.value('present_min_minutes')`.

Seeded: `present_min_minutes = 240m`, `half_day_min_minutes = 240m`.
**PRODUCT OWNER DECISION REQUIRED — D-VII-03**: the migration default for
existing tenants (see §20).

**Replaces:** `NEW-ATT-005` — presence is *any* punch on the day, in four
separate implementations (`AttendanceReportController.php:119-123`,
`PayrollGenController::presentDays():202-220`, `AbsenceService.php:239-254`,
`EssController.php:134`), so a single one-minute punch at 09:05 is a full paid
present day (CALC-B-14, OQ-B-03).

---

### RL-ATT-007 · Sunday work earns comp-off

```
decision_point : attendance.day.earn
scope          : { ALL }
specificity    : 0        on_conflict : all_match
```

```sql
-- WHEN
day.is_weekly_off
AND attendance.worked_minutes >= policy.value('comp_off_min_minutes')

-- EXCEPT
day.is_holiday                    -- a holiday falling on the weekly off is RL-ATT-008's business

-- THEN
CREATE_COMP_OFF(
    units      = BANDS(attendance.worked_minutes, [
                    { from: policy.value('comp_off_min_minutes'),
                      to:   policy.value('comp_off_full_minutes'), result: 0.5d },
                    { from: policy.value('comp_off_full_minutes'),
                      to:   NULL,                                  result: 1.0d } ]),
    expiry_ref = 'COMPOFF-EXPIRY-90D',
    reason     = CONCAT('Worked on weekly off ', day.date))
```

Note the rule does not say "Sunday". It says `day.is_weekly_off`, which resolves
through RL-CAL-001 and the employee's own calendar — so a six-day team with a
Tuesday off is covered by the same rule.

**Replaces:** nothing — **comp-off does not exist in SmartPRS 2.** It survives
only as a dead enum value in the legacy `attendance` table (attendance analysis
§5.2 b.17, Q4) and as a fallback leave-type name in one of three divergent
hard-coded lists (BR-LVE-011).

---

### RL-ATT-008 · Working on a festival holiday earns two days of comp-off

```
decision_point : attendance.day.earn
scope          : { ALL }
specificity    : 0        priority : 50        on_conflict : all_match
```

```sql
-- WHEN
day.is_holiday
AND HOLIDAY_TYPE(day.date, 'EMPLOYEE') = 'festival'
AND attendance.worked_minutes >= policy.value('comp_off_min_minutes')

-- THEN
1. CREATE_COMP_OFF(
       units      = policy.value('comp_off_festival_units'),      -- 2.0d seeded
       expiry_ref = 'COMPOFF-EXPIRY-90D',
       reason     = CONCAT('Worked on festival holiday ', day.date))
2. NOTIFY(event = 'attendance.festival_work',
          audience_ref = 'AUD-MANAGER',
          dedupe_key   = CONCAT(employee.code, ':', day.date))
```

`HOLIDAY_TYPE(..., 'EMPLOYEE')` resolves the holiday **for that employee's
scope**, so a Telangana-only festival does not credit a Maharashtra employee.

**Replaces:** `NEW-ATT-011` — a tenant-wide holiday cancels absence for
*everyone* and `holidays.applies_to` **has no reader at all**
(`AbsenceService.php:225-236`; OQ-B-07). There is no holiday type, no optional
or restricted holiday, and no differentiated earning for working one.

---

### RL-ATT-009 · Absent detection — the full previous-day condition set

The condition set SmartPRS 2 implements in `AbsenceService::absentFor():141-176`,
restated as configuration, with each of its five clauses now independently
adjustable and the two defects removed.

```
decision_point : attendance.day.classify
scope          : { ALL }
specificity    : 0        priority : -50        on_conflict : highest_priority_only
```

```sql
-- WHEN
    employee.is_active
AND day.date >= employee.joining_date
AND (IS_NULL(employee.exit_date) OR day.date <= employee.exit_date)
AND attendance.punch_count = 0
AND IS_NULL(leave.on_date(day.date))
AND NOT day.is_holiday
AND NOT day.is_weekly_off
AND day.is_working_day

-- EXCEPT
    attendance.is_corrected                      -- an approved overlay covers the day
 OR policy.value('absence_hold_for_review')      -- tenant may prefer review over auto-absent

-- THEN
1. SET_STATUS(code = 'ABSENT')
2. NOTIFY(event        = 'attendance.absent',
          audience_ref = 'AUD-EMP-AND-MANAGER',
          dedupe_key   = CONCAT('absent:', employee.code, ':', day.date))
```

Three deliberate differences from SmartPRS 2, each a defect not replicated:

| SmartPRS 2 | Evidence | SmartPRS 3 |
|---|---|---|
| Employee status must be **exactly** the lowercase string `'active'` | `AbsenceService.php:121-122` (NEW-ATT-012) — and `AttendanceReportController.php:898-900` compares case-insensitively | `employee.is_active` is derived from a value set; string case is not a business rule |
| A holiday cancels absence for the **whole tenant**, ignoring `holidays.applies_to` | `AbsenceService.php:111-113, 225-236` (NEW-ATT-011) | `day.is_holiday` is resolved for the employee's own scope |
| The sweep never runs for a NULL-tenant install | `AbsenceService.php:96-97` (NEW-ATT-010) | `tenant_id` is `NOT NULL` everywhere (Law 6); the state cannot exist |

Also not replicated: SmartPRS 2's absence check is a **notification sweep**, not
a status derivation — nothing is ever *stored* as Absent, and the report emits
**no row at all** for a zero-punch day (`AttendanceReportController.php:119-123`,
NEW-ATT-006). Here it produces a persisted, traced status, so the muster roll is
complete.

---

### RL-ATT-010 · N occurrences in a rolling window — as distinct from a per-month rule

```
decision_point : attendance.day.penalise
scope          : { department IN ['COLLECTIONS'] }
specificity    : 400      priority : 0          on_conflict : all_match
```

```sql
-- WHEN
attendance.late_minutes > policy.value('late_grace_min')
AND agg.count(
        late_arrival,
        rolling(30, days),
        { event.value > policy.value('late_grace_min') }
    ) >= policy.value('rolling_late_threshold')                    -- 3 seeded

-- THEN
1. APPLY_DAY_PENALTY(fraction = 0.5d,
       reason = CONCAT('3 late arrivals in the rolling 30 days ending ', day.date))
2. FLAG_FOR_REVIEW(reason = 'Repeat lateness pattern', severity = 'medium')
```

The distinction from RL-ATT-001 matters and is visible in the trace: the window
is `[day.date − 29 days, day.date]` (R-VII-023), so an employee who is late on
30-Sep, 01-Oct and 02-Oct is caught here and is **not** caught by a per-month
rule, which resets at the period boundary.

**Trace:**

```
agg.count(late_arrival, rolling(30 days) [03-Sep-2026 … 02-Oct-2026],
          predicate event.value > 10m) = 3                              TRUE
          counter agg_counters#91004 gen 12 (rolling, materialised per subject-day)
          occurrences: 30-Sep 22m · 01-Oct 14m · 02-Oct 19m
```

**Replaces:** `PayrollGenController.php:453, 475` — the free-lates window is the
payroll month and nothing else (BR-ATT-022; attendance backlog b.21: "no rolling
N-day window, no quarter, no per-tier free counts"), which is why this is called
out as a required engine capability in the analysis (implied requirement 16).

---

### RL-ATT-011 · A step-function penalty (break-budget overrun)

```
decision_point : attendance.day.penalise
scope          : { ALL }
specificity    : 0        on_conflict : all_match
```

```sql
-- WHEN
shift.break_budget_minutes > 0m
AND attendance.break_minutes > shift.break_budget_minutes

-- THEN
APPLY_DAY_PENALTY(
    fraction = STEP(
        attendance.break_minutes - shift.break_budget_minutes,
        policy.value('break_penalty_block_min'),      -- 30m seeded
        policy.value('break_penalty_step_days'),      -- 0.25d seeded
        'ceil'),
    reason  = CONCAT('Break overrun ',
                     attendance.break_minutes - shift.break_budget_minutes,
                     ' over budget ', shift.break_budget_minutes),
    cap_ref = 'ATT-PENALTY-CAP-DAY')
```

**Worked example** (identical to the analysis's own): budget 60m, break 155m,
block 30m, step 0.25d, mode `ceil` → `ceil(95/30) = 4` → **1.0 day**. The block
size, the step value and the rounding mode are now three configuration values;
the cap is a fourth.

**Replaces:** `PayrollGenController.php:464-471` — `ceil((break − budget)/30) ×
0.25` with **the 30 and the 0.25 as literals** (BR-ATT-023, NEW-PAY-15), no cap,
and a second, unrelated 90-minute threshold hard-coded in the PDF template
(`attendance-report-pdf.blade.php:67`, NEW-ATT-021).

---

### RL-ATT-012 · An employee-specific exception overriding a branch rule

The scenario: E-4471 has a documented medical accommodation permitting a later
start, agreed by HR for six months.

```
decision_point : attendance.grace
scope          : { employee IN ['E-4471'] }
specificity    : 1000     priority : 0          on_conflict : highest_priority_only
effective      : 01-Sep-2026 → 28-Feb-2027
severity       : high_risk        requires_approval : true
business_description :
  "Approved medical accommodation (HR ref ACC-2026-114). Grace of 60 minutes,
   reviewed on 28-Feb-2027. Supersedes the Hyderabad branch grace."
```

```sql
-- WHEN   TRUE
-- THEN   SET_GRACE(minutes = 60m)
```

Because specificity 1000 exceeds the branch rule's 180 and the tenant default's
0, it wins outright; priority is not consulted (§8.2 Example 3). Because
`effective_to` is set, the accommodation **expires by construction** on
01-Mar-2027 and the branch rule resumes with no human action — and the trace on
a March day shows the branch rule matching, with the employee rule listed as
`not_in_effect`.

**Replaces:** SmartPRS 2 has an `employee` scope level on `late_policy`, but it
is **not effective-dated** (backlog b.23), so the accommodation never expires;
ties with another employee-scope row are broken by row order (NEW-ATT-019); and
the same override must be duplicated into the report, the Today panel, the late
email and payroll because each resolves policy separately.

---

### RL-CAL-001 · Second and fourth Saturday are weekly offs

```
decision_point : attendance.calendar.weekly_off
scope          : { company IN ['CMP7'] }
specificity    : 100      priority : 0          on_conflict : highest_priority_only
```

```sql
-- WHEN
day.weekday = 'sat'
AND day.date IN [ NTH_WEEKDAY(SOMONTH(day.date), 'sat', 2),
                  NTH_WEEKDAY(SOMONTH(day.date), 'sat', 4) ]

-- THEN
SET_DAY_TYPE(type = 'weekly_off')
```

A companion rule at the same decision point sets `weekly_off` for
`day.weekday = 'sun'`; a 5-day-week team carries a more specific rule listing
both `sat` and `sun`.

**Trace (14-Nov-2026):**

```
day.weekday = 'sat'                                                  TRUE
NTH_WEEKDAY(2026-11-01, 'sat', 2) = 2026-11-14
NTH_WEEKDAY(2026-11-01, 'sat', 4) = 2026-11-28
day.date 2026-11-14 IN [2026-11-14, 2026-11-28]                      TRUE   → MATCHED
SET_DAY_TYPE weekly_off
```

**Replaces:** `PayrollGenController::isWeekOff():70-89` — the Nth-Saturday test
is `ceil(day / 7)`, a **day-of-month band, not a count of actual Saturdays**
(NEW-PAY-10), so it diverges from the colloquial "2nd Saturday" in most months.
The modes are the four literals `none | all | 2_4 | 1_3`, tenant-wide only, with
no per-company, per-branch, per-shift or rotating pattern (BR-PAY-053), and the
attendance layer uses a **completely different** definition — a flat ISO-weekday
list per tenant (`AbsenceService.php:45`) — one of the four incompatible
working-day definitions the analysis found (CALC-B-16).

---

### RL-LVE-001 · Sandwich — leave on Friday and Monday charges the intervening weekly offs

Excludes Sick Leave, and excludes statutory maternity leave.

```
decision_point : leave.request.charge
scope          : { ALL }
specificity    : 0        priority : 0          on_conflict : all_match
severity       : high_risk
```

```sql
-- WHEN  (the subject IS one candidate off-day in the request's span: day.date)
    policy.value('sandwich_enabled')
AND (day.is_weekly_off OR day.is_holiday)
AND NOT IS_NULL(leave.on_date(DATEADD(day.date, -1, 'days')))
AND NOT IS_NULL(leave.on_date(DATEADD(day.date,  1, 'days')))

-- EXCEPT
    leave.type IN policy.value('sandwich_exempt_types')        -- ['SL'] seeded
 OR leave.is_statutory                                          -- maternity, etc.
 OR (leave.on_date(DATEADD(day.date, -1, 'days'))
       <> leave.on_date(DATEADD(day.date, 1, 'days'))
     AND policy.value('sandwich_requires_same_type'))

-- THEN
CHARGE_DAY(
    date   = day.date,
    type   = leave.type,
    units  = 1.0d,
    reason = CONCAT('Sandwiched off-day between leave on ',
                    DATEADD(day.date,-1,'days'), ' and ', DATEADD(day.date,1,'days')))
```

A companion rule with the opposite `when` emits `EXEMPT_DAY`, so the trace shows
**why a day was not charged** as explicitly as why one was.

**Trace (leave Fri 14-Aug and Mon 17-Aug, Sat/Sun off, type EL):**

```
day.date = 2026-08-15 (Sat, weekly off)
  policy sandwich_enabled TRUE
  leave.on_date(2026-08-14) = 'EL'   not null                        TRUE
  leave.on_date(2026-08-16) = NULL   -- Sunday, itself an off day    FALSE  → NOT MATCHED
```

**This exposes a real modelling question the seeded pack must answer.** With the
naive adjacency test, the Saturday's *next* day is the Sunday, which carries no
leave, so neither off-day is charged. SmartPRS 2 solves it by walking to the
**nearest earlier and later non-off day** (`sandwichDays():770-813`). The seeded
rule therefore uses that same shape, expressed with two helper policy values:

```sql
-- WHEN  (seeded form)
    policy.value('sandwich_enabled')
AND (day.is_weekly_off OR day.is_holiday)
AND NOT IS_NULL(leave.on_date(PREV_WORKING_DAY(day.date, 'EMPLOYEE')))
AND NOT IS_NULL(leave.on_date(NEXT_WORKING_DAY(day.date, 'EMPLOYEE')))
```

`PREV_WORKING_DAY(date, scope)` / `NEXT_WORKING_DAY(date, scope)` are in the
Dates group of the function catalogue for this purpose — **SMARTPRS 3
ARCHITECTURE RECOMMENDATION**, justified precisely because the naive form gets
the two-day weekend wrong. They resolve the same calendar as `IS_WORKING_DAY`
and walk at most a year before returning a named error, so a calendar with no
working day in it fails loudly rather than looping.

**Replaces:** `PayrollGenController::sandwichDays():770-813` — a single
tenant-wide boolean, default off (`SettingsController.php:56`), with a fixed
algorithm and **no exclusions of any kind**: no leave-type carve-out, no
statutory-leave carve-out, no configuration of which off-day types are
sandwiched, and it operates on **day-of-month integers within `$start`'s month**,
so it indexes the wrong month on a cut-off cycle (CALC-B-17, BR-PAY-047).
Sick-leave and maternity exclusions are not merely unconfigured — they are
inexpressible.

---

### RL-PAY-001 · Probation-stage statutory suppression, expressed as configuration

```
decision_point : payroll.component.eligibility
scope          : { employment_stage IN ['probation','internship'] }
specificity    : 300      priority : 0          on_conflict : all_match
severity       : high_risk        requires_approval : true
business_description :
  "Company policy: PF, PT and TDS are not deducted during probation or
   internship. This is a company policy choice, not a statutory exemption.
   Reviewed by <compliance owner> on <date>."
```

```sql
-- WHEN
    policy.value('probation_suppress_enabled')
AND employee.stage IN policy.value('probation_suppressed_stages')

-- EXCEPT
    statutory.applies('PF_MANDATORY_FROM_JOIN')       -- pack may forbid the suppression
 OR service.months > policy.value('probation_suppress_max_months')

-- THEN
1. SUPPRESS_COMPONENT(code = 'PF_EE',  reason = 'Probation policy')
2. SUPPRESS_COMPONENT(code = 'PF_ER',  reason = 'Probation policy')
3. SUPPRESS_COMPONENT(code = 'PT',     reason = 'Probation policy')
4. SUPPRESS_COMPONENT(code = 'TDS',    reason = 'Probation policy')
5. RAISE_ITEM(type = 'COMPLIANCE_EXCEPTION',
              due  = EOMONTH(period.end),
              owner_ref = 'ROLE-COMPLIANCE',
              severity  = 'high')
```

The `RAISE_ITEM` is deliberate: the statutory validator (spine S12) treats a
company policy falling below the statutory baseline as a **blocked or warned**
publication by declared pack severity, and where it is permitted it records an
**explicit compliance exception**. The suppression is therefore visible in the
compliance register rather than invisible in a calculator.

**Replaces:** `AppDataController::computeSlip():2114-2117`, duplicated at
`computeSlipFromComponents():2284-2287` — the two stage names and the three
suppressed heads are literals in two calculators, ESI and LWF are inconsistently
excluded from the same treatment, and the analysis records plainly that **this is
a business choice, not a statutory exemption; the EPF Act does not exempt
probationers** (BR-STAT-003/BR-PAY-733). *No statutory position is asserted here:
whether the suppression is lawful is a matter for the rule pack's compliance
owner (spine S12, D-03), and this rule's job is to make the choice explicit,
dated, approved, traced and reportable.*

---

### RL-CMP-001 · The DRA-expiry payment gate (off / warn / block) — generalised

SmartPRS 2's best rule, made general: **any credential, any money point, any
severity.**

```
decision_point : compliance.payment_gate
scope          : { ALL }
specificity    : 0        priority : 0          on_conflict : all_match
severity       : high_risk        requires_approval : true
```

```sql
-- WHEN
    policy.value('credential_gate_mode') <> 'off'
AND context.event IN policy.value('credential_gate_events')
AND NOT credential.is_valid(
            policy.value('credential_gate_type'),        -- 'DRA' seeded
            context.evaluation_date)

-- THEN
1. GATE_PAYMENT(
       mode   = policy.value('credential_gate_mode'),    -- 'warn' | 'block'
       reason = CONCAT('Credential ', policy.value('credential_gate_type'),
                       ' is ', credential.status(policy.value('credential_gate_type')),
                       IF(IS_NULL(credential.expiry(policy.value('credential_gate_type'))),
                          '', CONCAT(' (expired ',
                              credential.expiry(policy.value('credential_gate_type')), ')'))),
       code   = 'CREDENTIAL_INVALID',
       event  = context.event)
2. WRITE_AUDIT(
       reason = 'Credential gate evaluated',
       detail = CONCAT(employee.code, '|', context.event, '|',
                       policy.value('credential_gate_mode')))
3. NOTIFY(event = 'compliance.gate_triggered',
          audience_ref = 'AUD-COMPLIANCE-AND-MANAGER',
          dedupe_key   = CONCAT('gate:', employee.code, ':', context.event,
                                ':', context.evaluation_date))
```

Seeded `credential_gate_events`:
`['commission.approve', 'incentive.commit', 'offroll_earning.approve']` —
exactly SmartPRS 2's three money points — extended by configuration to
`payroll.run.approve`, `bank_file.download` and `advance.approve` without code.

A second seeded rule of the same shape gates on `PCC`, a third on `BGV`, a
fourth on `AGENT_AUTHORISATION` — each a **row**, not a code path. Two more
seeded rules give the graduated behaviour SmartPRS 2 cannot express:

```sql
-- RL-CMP-002 : warn early, block late
-- WHEN  credential.days_to_expiry('DRA') BETWEEN 1 AND policy.value('credential_warn_days')
-- THEN  GATE_PAYMENT(mode='warn', …) · RAISE_ITEM(type='CREDENTIAL_EXPIRING', …)

-- RL-CMP-003 : lower bound on the alert window
-- WHEN  credential.days_to_expiry('DRA') < -policy.value('credential_alert_floor_days')
-- THEN  RAISE_ITEM(type='CREDENTIAL_LONG_EXPIRED', severity='low')
```

**Trace:**

```
context.event = 'commission.approve'                                     
policy credential_gate_mode = 'block'   ≠ 'off'                      TRUE
context.event IN ['commission.approve','incentive.commit',
                  'offroll_earning.approve']                          TRUE
credential.is_valid('DRA', 2026-09-03)
    status = 'verified', expiry = 2026-07-31 < 2026-09-03 → FALSE
    NOT FALSE                                                         TRUE   → MATCHED
GATE_PAYMENT  mode=block  reason="Credential DRA is expired (expired 2026-07-31)"
              event=commission.approve                    → PAYMENT BLOCKED
```

**Replaces and preserves:** `SettingsController.php:57, 154` (the tri-state
setting, which is genuinely good and is kept), consumed as three hard-coded `if`
blocks at `RequestController.php:1366-1394`, `IncentiveController.php:135` and
`OffrollAgentController.php:249-262` (NEW-VERT-01, BR-REQ-010). Five limitations
are removed: the gate's **subject** is fixed to DRA and cannot be PCC, BGV or
authorisation (N §5.2 item 42); its **applicability** is three hard-coded call
sites; the **compliance score** that could gate it uses five literal weights
(`ComplianceController.php:289-330`, NEW-VERT-02); the low-compliance and
open-grievance checks are **warn-only with the policy hard-coded**
(`RequestController.php:1416-1454`, BR-REQ-011); and off-roll agents' DRA is
free text **with no expiry column at all** (`OffrollAgentController.php:44-45`,
V-02), so for them the gate can only detect a blank field. The last is a data
model fix (Part on compliance), not a rule fix — noted here because the rule is
correct and the data is not.

One behaviour is **deliberately not preserved**: SmartPRS 2's gate "never breaks
the payment path on error". Under R-VII-021 an evaluation failure holds the
payment for review. See D-VII-01.

---

### 12.1 Coverage summary

| # | Rule | Decision point | Capability demonstrated | SmartPRS 2 citation replaced |
|---|---|---|---|---|
| 1 | RL-ATT-001 | `attendance.day.penalise` | `agg.count` over `payroll_period` with a predicate | `PayrollGenController.php:449-455, 498-513` |
| 2 | RL-ATT-002 | penalise + classify | `BANDS`, arbitrary band count | `:482-495` (incl. the `:494` fall-through) |
| 3 | RL-ATT-003 | `attendance.grace` | Scope specificity ladder, per-branch override | `LateArrivalService.php:184-204` +3 clones |
| 4 | RL-ATT-004 | penalise | `OFFSET_SHORTFALL`, department scope, action ordering | `AttendanceReportController.php:729-735` (discarded) |
| 5 | RL-ATT-005 | `attendance.day.earn` | Two-dimension scope, `STEP` rounding, workflow approval | `MasterController.php:755-763`; `:339-345` |
| 6 | RL-ATT-006 | classify | Presence gate, `CASE…ELSE` | `PayrollGenController.php:202-220` +3 |
| 7 | RL-ATT-007 | earn | Comp-off accrual with expiry | *(does not exist)* |
| 8 | RL-ATT-008 | earn | Holiday typing and employee-scoped holidays | `AbsenceService.php:225-236` |
| 9 | RL-ATT-009 | classify | Full absent condition set, three defects removed | `AbsenceService.php:141-176` |
| 10 | RL-ATT-010 | penalise | `rolling(30, days)` window | `PayrollGenController.php:453, 475` |
| 11 | RL-ATT-011 | penalise | `STEP` penalty with a cap | `:464-471` |
| 12 | RL-ATT-012 | grace | Employee-specific, effective-dated, expiring override | `late_policy` employee scope, undated |
| 13 | RL-CAL-001 | `attendance.calendar.weekly_off` | `NTH_WEEKDAY` counting actual weekdays | `PayrollGenController.php:70-89` |
| 14 | RL-LVE-001 | `leave.request.charge` | Per-day charging, type and statutory exclusions | `:770-813` |
| 15 | RL-PAY-001 | `payroll.component.eligibility` | Suppression as policy + compliance exception | `AppDataController.php:2114-2117, 2284-2287` |
| 16 | RL-CMP-001..003 | `compliance.payment_gate` | Generalised credential-to-money gate | `RequestController.php:1366-1394` +2 |

---
## 13. Migration

### 13.1 The classification exercise

The as-is analysis recorded approximately 950 distinct business rules — 566
`BR-*` identifiers carried from the reference catalogue and 382 `NEW-*` rules the
catalogue never held. Every one is classified into exactly one of six
destinations. The classification is the migration's first deliverable and its
scope-control instrument: **a rule that is not classified is not migrated, and a
rule classified "not carried" is signed off as such.**

| Destination | Meaning | Approx. share | Examples |
|---|---|---|---|
| **A. Rule-engine rule** | An outcome decided by a condition over scoped, dated configuration | ~30% | BR-ATT-020..027, BR-PAY-722, BR-PAY-047, BR-PAY-053, BR-STAT-003, BR-REQ-010, NEW-VERT-01 |
| **B. Formula** | An amount computed from typed inputs | ~15% | BR-PAY-011 (Basic/HRA split), BR-STAT-001/002/004/013/014, BR-PAY-732 (OT rate), CALC-B-01..09 |
| **C. Workflow** | Routing, approval, SLA, immutability | ~10% | BR-REQ-002/005/012/014/026, BR-PAY-716/025/028, BR-ATT-029..036 |
| **D. Platform behaviour** | Identity, tenancy, persistence, audit, dedup, idempotency, ingest | ~25% | BR-ATT-001..009, BR-DEV-004, NEW-ATT-001..003, BR-PAY-040/041/042, NEW-AUD-101..110 |
| **E. Statutory rule pack** | A rate, ceiling, slab, threshold or due date published and signed by Ametecs | ~8% | BR-STAT-002 (EPS/EDLI figures), BR-STAT-004 (ESI rates), BR-STAT-007..010 (PT slabs), BR-STAT-011/012 (TDS slabs, 87A) |
| **F. Not carried — defect or dead code** | Behaviour that exists only because of a bug or unreachable code | ~12% | NEW-PAY-01 (dead `$pick` closure), BR-PAY-209 (Sunday-only working days, destructured away), NEW-PAY-18/19 (dead configuration), NEW-NOT-110/118, NEW-REQ-104 |

**R-VII-063** Every `BR-*` and `NEW-*` identifier in the as-is analysis carries a
migration classification with an owner and a decision date, held in a tracked
register. The register is a release gate: cutover is refused while any rule is
unclassified.

### 13.2 How to tell the four live destinations apart

The test applied to each recorded rule, in order:

1. Does it **decide whether** something happens (a status, a penalty, an
   eligibility, a gate)? → **Rule.**
2. Does it **compute how much**, given that it happens? → **Formula.**
3. Does it decide **who must agree, in what order, by when**? → **Workflow.**
4. Is it about **identity, storage, uniqueness, ordering, audit or transport**,
   with no business variability a customer could reasonably want? → **Platform.**
5. Is the value it carries **set by statute**? → **Rule pack.**

Worked application to three genuinely ambiguous cases:

| Rule | Naive reading | Correct destination | Why |
|---|---|---|---|
| BR-PAY-722 late-cut modes | "It computes a day-cut, so it is a formula" | **Rule** (the mode and thresholds) **+ Formula** (converting day-fractions to money) | The mode selection is an outcome decision scoped per branch/grade; the money conversion is arithmetic with a declared divisor |
| BR-STAT-004 ESI | "The rates are statutory, so it is all rule pack" | **Pack** (rates, threshold, rounding rule) + **Rule** (the contribution-period eligibility lock, BR-STAT-005) + **Formula** (the arithmetic) | The Apr–Sep / Oct–Mar lock is an eligibility *decision*, which is why SmartPRS 2 implements it as a substring match on stored JSON keys and breaks when a component is renamed |
| BR-ATT-043 dedupe | "Duplicate handling is business policy" | **Platform** — with one rule-engine hook | Punch identity is a platform invariant; *what to do with a quarantined duplicate* is an exception workflow. And SmartPRS 2's keep-newest hard delete is destination F: not carried (spine S10 — deduplication quarantines, never deletes) |

### 13.3 Seeding SmartPRS 2's hard-coded behaviour

Each migrated behaviour becomes a **seeded rule** in an Ametecs-owned pack:

```
pack: ametecs/smartprs2-compat  v1.0.0
  · owner            ametecs (read-only to the tenant)
  · signature        Ametecs private key (spine S12 distribution model)
  · every rule carries source_ref = the SmartPRS 2 file:line it reproduces
  · every rule carries ≥ 3 stored test cases derived from the analysis's own
    worked examples, with their exact expected values
  · every rule is published with effective_from = the tenant's cutover date
```

Seeding is **behaviour-preserving by default**, with one class of exception:
**confirmed defects are not reproduced.** Each is listed explicitly in the
migration report the customer signs, with the old behaviour, the new behaviour
and the expected numeric impact.

| Not reproduced | Old behaviour | Evidence | Seeded behaviour |
|---|---|---|---|
| Sub-L1 late charged at the L1 rate | `tierCut()` fall-through | `PayrollGenController.php:494` (BR-ATT-021b) | `BANDS` with an explicit lowest band, default `0d` |
| Nth Saturday as `ceil(day/7)` | Day-of-month band | `:83` (NEW-PAY-10) | `NTH_WEEKDAY` counting actual Saturdays |
| Absence status must be exactly `'active'` | String equality | `AbsenceService.php:122` | Value-set membership |
| Tenant-wide holiday cancels absence for everyone | `applies_to` ignored | `:225-236` | Employee-scoped holiday resolution |
| Zero punches = full pay | LOP block skipped entirely | `compute():1237` (BR-PAY-013) | A configurable outcome; migration default **flag for review**, per D-VII-04 |
| Unknown leave type treated as PAID | Conservative default | `paidLeaveDays():1065` (BR-PAY-014) | `not_null_required` on `leave.type`; missing type is an exception, not a silent grant |
| Every approved leave counted as paid because `type_id` is never written | Dead `continue` | `LeaveController.php:364-378` vs `PayrollGenController.php:1063-1067` (BR-LVE-008) | Leave type is a typed reference; the defect is a data-repair item (§13.5) |
| Late email uses the earliest punch regardless of direction | `orderBy('punch_at')`, no direction filter | `LateArrivalService.php:112-117` (NEW-ATT-007) | `attendance.first_in` is direction-aware by construction |
| Sandwich indexes day-of-month within `$start`'s month | Wrong month on a cut-off cycle | `sandwichDays():770-813` | Dates throughout; `payroll_period` from the pay cycle |
| Rule ties broken by row order | `if ($tr > $rank)` | `LateArrivalService.php:184-204` (NEW-ATT-019) | Ties block publication (§8.3) |

**R-VII-064** Every non-reproduced defect appears in the migration report with:
the citation, the affected employee count, the numeric delta on a chosen sample
period, and a customer sign-off line. A defect is never silently "fixed" into a
customer's payroll.

### 13.4 Capturing a customer's *implicit* policy

The hardest part of the migration is not the code — it is that a customer's real
policy is partly unwritten, and partly not what their SmartPRS 2 configuration
says. Four instruments, in order:

**(1) Configuration extraction.** Read the tenant's actual rows and render them
as candidate rules, with counts:

```
SELECT mode, cut_mode, cut_n, lates_before_cut,
       l1_min, l1_cut, l2_min, l2_cut, l3_min, l3_cut,
       break_budget, break_cut, scope, scope_target, company_name
FROM late_policy;
SELECT weekly_off_day, sat_off_mode, lop_basis, sandwich_rule, dra_gate
FROM statutory_settings;
SELECT name, start_time, end_time, grace_min, full_day_hours, half_day_hours,
       break_budget, night_allowance FROM shifts;
```

This is **required evidence, not optional discovery**, and OQ-B-06 states why:
`resolvePolicy()` defaults `mode` to `simple` and `simpleCut()` returns `0.0`
when `cut_mode` is unset (`PayrollGenController.php:301, 510`). If those columns
are empty at a site, **the entire late-penalty engine is dead in the field** and
the SmartPRS 3 work there is greenfield design, not migration. The extraction
answers that per customer before a single rule is written.

**(2) Behaviour reconstruction — the shadow run.** For each of the last three
closed payroll periods, run the SmartPRS 3 engine over the migrated punches and
leave with the seeded rules, and diff every derived value against the stored
SmartPRS 2 payslip. Every difference is triaged into one of three buckets:

| Bucket | Meaning | Action |
|---|---|---|
| **Known defect corrected** | The difference is on the §13.3 list | Report it, quantify it, get sign-off |
| **Undiscovered implicit policy** | SmartPRS 2 produced a number the seeded rules do not | Interview, then author a rule and add a test case |
| **Migration error** | Data mapped wrongly | Fix the mapping |

**R-VII-065** Cutover requires the shadow run to reconcile to **zero unexplained
differences** across three periods. Every difference is either an approved
correction or a fixed mapping. *SmartPRS 2 has no reconciliation assertion of any
kind (spine S11); the migration introduces one as its acceptance criterion.*

**(3) The policy interview, structured by the decision points.** Not an open
conversation: a fixed questionnaire generated from the decision-point catalogue,
each question naming the current inferred behaviour and asking whether it is
intended. Approximately 60 questions, of which the ones that reliably surface
unwritten policy are:

- Does a single punch at 09:05 count as a full present day? (CALC-B-14 says it
  does today.)
- What happens when an employee has **zero** punches for a whole month?
  (BR-PAY-013 pays them in full today.)
- Is a mid-month leaver paid a full month? (NEW-PAY-12: yes, today.)
- Which off-days does the sandwich rule charge, and which leave types are exempt?
  (CALC-B-17: no exemptions exist today.)
- Is comp-off earned, and at what rate, for a weekly off versus a festival?
  (Does not exist today.)
- Which credential gates which money point, at what severity? (DRA only, three
  points, today.)
- Does made-up time offset lateness, and for whom? (Not at all, today.)

**(4) The parallel run.** One full period run in both systems, with the numbers
compared line by line before SmartPRS 2 is retired.

### 13.5 Data repairs that must precede rule migration

These are not rule-engine work, but the rules cannot be seeded correctly without
them. Each is drawn directly from the analysis's own open questions.

| Repair | Why | Evidence |
|---|---|---|
| Snapshot `attendance_logs` before any cutover | `attendance:dedupe` hard-deletes with no log and is invoked automatically by a migration; what it removed cannot be reconstructed | OQ-B-12; `DedupeAttendance.php:42-88`; `2026_08_16_000200:128` |
| Enumerate and disposition `emp_code LIKE 'USER-%'` rows | In-app punches written under a synthetic code, counted as present days for a code no `employees` row holds | OQ-B-11; `AttendanceReportController.php:279` |
| Enumerate corrections approved against locked periods | They may represent unpaid liabilities the employee was promised | OQ-B-02; DEFECT-B-30 |
| Backfill `leaves.type_id` | Never written, so every approved leave including LOP counts as paid | BR-LVE-008 |
| Resolve night-shift attribution for historic punches | `log_date` is the punch's calendar date, so a 22:00–06:00 shift is two days | OQ-B-01; BR-ATT-201 |
| Establish per-site schema shape | Three creators for `attendance_logs`, two for `attendance_pending` | OQ-B-04 |
| Give off-roll DRA/PCC real date columns | They are free text with no expiry, so the gate can only detect a blank | V-02; `OffrollAgentController.php:44-45` |

### 13.6 What does not come across

- Every rule classified **F** (defect or dead code) — roughly 12% of the
  recorded rules.
- The four parallel implementations of lateness, presence and working days:
  **one** survives, as a rule set.
- Row-order tie-breaking, in any form.
- Runtime DDL and self-healing schema, in any form (Law 2).
- Fail-open authorisation, in any form (Law 5).
- Name-based semantics for components, leave types, statuses and roles (Law 1);
  every one becomes a code with a flag.

---

## 14. Performance

### 14.1 The workload

The reference workload is a **monthly attendance derivation for 10,000 employees
over 31 days**: 310,000 subject-days, each passing through three attendance
decision points (`classify`, `penalise`, `earn`) plus the calendar decision point
materialised once per employee-day.

With a typical seeded rule set, each decision point has 4–12 candidate rules
after scope filtering, of which 1–3 match. Total evaluations:

```
310,000 subject-days × 3 decision points × ~8 candidates ≈ 7.4 M rule evaluations
```

### 14.2 Targets

| Metric | Target | Measured by |
|---|---|---|
| Single rule evaluation, no aggregation, warm AST cache | ≤ **40 µs** p50, ≤ 150 µs p99 | Micro-benchmark in CI |
| Single rule evaluation with one materialised `agg` call | ≤ **90 µs** p50 | Micro-benchmark |
| One subject-day, all attendance decision points | ≤ **2.5 ms** p50, ≤ 12 ms p99 | Component benchmark |
| Full month, 10,000 employees, 4 workers | ≤ **8 minutes** wall clock | Performance regression test |
| Trace construction overhead | ≤ **12%** of evaluation time | Benchmark with tracing on and off |
| Rule-set resolution (scope match + ordering) for one subject-day, warm | ≤ **60 µs** | Micro-benchmark |
| Interactive single-day evaluation (the simulator, the "explain" panel) | ≤ **120 ms** end to end | API SLO |
| Peak memory per worker | ≤ **256 MB** | Performance test |

**R-VII-066** These targets are **regression-tested in CI on a fixed corpus and a
fixed rule pack**, with the run failing on a regression greater than 20% against
the committed baseline. A performance target that is not asserted is an
aspiration.

### 14.3 The caching model

Five caches, each with an explicit invalidation key. None is a time-based TTL on
business data, because a stale rule is a wrong answer, not a slow one.

| Cache | Key | Contents | Invalidation | Scope |
|---|---|---|---|---|
| **Compiled AST** | `rule_version_id + checksum` | Deserialised, immutable AST object | Never — the key changes when the rule changes. Published versions are immutable (R-VII-008) | Process (APCu) + shared (Redis) |
| **Rule-set resolution** | `tenant + decision_point + scope_signature + as_of` | Ordered candidate rule-version ids | Any publish at that decision point in that tenant | Redis, short-lived; recomputed per batch |
| **Scope signature** | `employee + as_of` | The 21 dimension values as at the date | Any effective-dated assignment change for that employee | Per batch, in memory |
| **Aggregation results** | `subject + metric + window_key + qualifier_hash + generation` | The counter value | `generation` bump on window recomputation | Per batch, in memory; materialised counters are the durable layer |
| **Policy values** | `tenant + policy_key + as_of` | Resolved policy value | Publish of that policy artefact | Redis |

**The scope signature is the load-bearing optimisation.** Two employees with
identical values across all 21 dimensions resolve to the **same rule set**. In a
real tenant of 10,000 employees, the number of distinct scope signatures is
typically 30–300 — an organisation has far fewer distinct policy positions than
it has people.

```
10,000 employees → ~120 distinct scope signatures
→ 120 rule-set resolutions per date instead of 10,000
→ resolution cost falls by ~99%
```

**R-VII-067** The batch path groups subjects by scope signature and resolves the
rule set once per group per date. The grouping is computed from the
effective-dated assignment rows, so a mid-month transfer correctly moves an
employee between groups on the transfer date rather than for the whole month.

### 14.4 The batch evaluation path

```
BatchEvaluator::run(decision_point, subject_set, date_range)

1. partition   subjects × dates into shards of ~2,000 subject-days
2. per shard:
   a. bulk-load the prepared context for every subject in the shard
      -- ONE query per source table, never N+1
   b. group by scope signature; resolve each group's rule set once per date
   c. bulk-load every aggregation counter the resolved rules need, by
      (subject, metric, window_key, qualifier_hash) -- one query, not one per call
   d. evaluate; collect actions and traces in memory
   e. bulk-write: derived records + pinned trace data in one transaction;
      full traces to the async trace queue
3. reconcile   assert counts: subjects in = derived out + exceptions
```

**R-VII-068** The batch path issues **O(tables) queries per shard**, never
O(subjects). A CI test asserts the query count for a 2,000-subject-day shard is
below a fixed ceiling (default 40) and fails on regression. *SmartPRS 2's
comparable paths do the opposite —
`LeaveController::apply()` loads every column of every employee in the tenant
into PHP to resolve one name (NEW-LVE-104), and the greeting sweep full-scans
`employees` per tenant per hour with a non-sargable `DATE_FORMAT` predicate
(NEW-NOT-107).*

**R-VII-069** The batch is **restartable and idempotent**: shards are
checkpointed, a failed shard is retried, and re-running a completed shard
converges by fingerprint (R-VII-032) rather than duplicating.

**R-VII-070** The batch **continues past a failed subject**. One employee whose
rule evaluation fails does not abort the other 9,999; the failure is recorded,
counted, surfaced, and the batch reports partial success with an explicit failure
count. *Directly answering the analysis finding that a job processing fewer items
than it attempted must not report success — SmartPRS 2's absence and greeting
sweeps return an under-count and exit SUCCESS.*

### 14.5 Fairness, isolation and the circuit breaker

- Rule-evaluation batches run on a dedicated queue with a **per-tenant
  concurrency cap**, so one tenant's recalculation cannot starve another's.
- Each batch carries a **deadline**; exceeding it pauses the batch, alerts, and
  leaves it resumable from its checkpoint rather than being killed mid-shard.
- A **circuit breaker** trips when a tenant's evaluations exceed a rolling error
  or budget-breach rate, pausing that tenant's batches and naming the offending
  rules on the operations dashboard (R-VII-051).
- Interactive evaluations (the simulator, "How was this calculated?") run on a
  separate, higher-priority path with a lower deadline, so batch work never makes
  an explain panel slow.

### 14.6 Where the cost actually goes

Measured distribution on the reference workload (targets, to be validated):

| Stage | Share | Note |
|---|---|---|
| Context assembly (bulk loads) | ~35% | Dominated by attendance-day and punch reads |
| Rule-set resolution | ~5% | After scope-signature grouping |
| AST evaluation | ~25% | Pure CPU, cache-warm |
| Aggregation lookups | ~12% | Materialised path; on-demand is the tail |
| Trace construction | ~12% | Within the ≤12% budget |
| Persistence (bulk writes) | ~11% | Derived records + pinned trace |

The optimisation order is therefore: bulk-load discipline first, scope-signature
grouping second, AST cache third. Micro-optimising the evaluator before the
query pattern is settled would be the wrong first move.

---
## 15. Data model

Conventions per spine S16: `snake_case`, plural tables, `_id` foreign keys, `_at`
timestamps, `_on` dates, `is_`/`has_` booleans, `tenant_id` `NOT NULL` on every
business table and participating in every unique index, foreign keys on every
relationship with declared `ON DELETE`, no database `ENUM` (status vocabularies
are reference tables), money `DECIMAL(18,4)` at rest, dates `DATE` and instants
`DATETIME` UTC with a business timezone.

Platform-owned catalogue tables (`decision_points`, `action_definitions`,
`spx_functions`, `agg_metric_definitions`) are **not** tenant-scoped: they are
seeded by migration, identical in every installation, and read-only at runtime.
They are the only tables in this part without a `tenant_id`, and that is stated
explicitly rather than left as an omission.

---

### 15.1 `decision_points` — platform catalogue

**Purpose.** The closed set of questions the engine may be asked (§3.2). Fixes
the symbol table, the action allow-list and the default conflict policy.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` | No | auto | PK |
| `code` | `varchar(64)` | No | — | e.g. `attendance.day.penalise`. **UNIQUE** |
| `module` | `varchar(32)` | No | — | FK → `modules.code` |
| `subject_type` | `varchar(32)` | No | — | `employee_day`, `leave_request`, `employee_period`, … |
| `label` | `varchar(160)` | No | — | |
| `description` | `text` | No | — | |
| `namespaces_json` | `json` | No | — | Declared namespaces and, per namespace, the allowed symbols |
| `not_null_symbols_json` | `json` | Yes | `NULL` | Symbols declared `not_null_required` (R-VII-015) |
| `allowed_actions_json` | `json` | No | — | Action codes emittable here |
| `default_on_conflict` | `varchar(24)` | No | — | FK → `ref_conflict_policies.code` |
| `result_contract` | `varchar(64)` | No | — | The caller's expected shape |
| `budget_overrides_json` | `json` | Yes | `NULL` | Per-decision-point budget deltas |
| `catalogue_version` | `int` | No | `1` | |
| `is_active` | `tinyint(1)` | No | `1` | |
| `created_at`, `updated_at` | `datetime` | No | — | |

PK `id` · UNIQUE `(code)` · INDEX `(module, is_active)`
Tenant scope: **none — platform catalogue.** History: migration-versioned.
Soft delete: no (`is_active`). Audit: release audit only. PII: none.

---

### 15.2 `action_definitions` — platform catalogue

**Purpose.** The closed, versioned action set (§6). Adding a row is an Ametecs
release activity (§6.5).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` | No | auto | PK |
| `action_code` | `varchar(48)` | No | — | e.g. `APPLY_DAY_PENALTY`. **UNIQUE with `major_version`** |
| `module` | `varchar(32)` | No | — | FK → `modules.code` |
| `label` | `varchar(120)` | No | — | |
| `description` | `text` | No | — | |
| `param_schema_json` | `json` | No | — | Typed parameter schema; each param `{name, type, required, accepts_expr, value_set_ref}` |
| `effect_class` | `varchar(16)` | No | — | `derived | ledger | terminal` (§6.4) |
| `idempotency_rule` | `varchar(32)` | No | — | `fingerprint | value | key(<expr>)` |
| `reversal_rule` | `varchar(32)` | No | — | `supersede | compensate | none` |
| `is_money_affecting` | `tinyint(1)` | No | `0` | Forces `requires_approval` on any rule emitting it |
| `dominance_order` | `varchar(64)` | Yes | `NULL` | e.g. `block>warn` for `GATE_PAYMENT` |
| `major_version` | `int` | No | `1` | Rules pin this |
| `min_engine_version` | `varchar(16)` | No | — | |
| `is_active` | `tinyint(1)` | No | `1` | |
| `created_at`, `updated_at` | `datetime` | No | — | |

PK `id` · UNIQUE `(action_code, major_version)` · INDEX `(module, is_active)`
Tenant scope: none. History: migration-versioned. PII: none.

---

### 15.3 `spx_functions` and `agg_metric_definitions` — platform catalogues

`spx_functions`: `id` PK, `name` (UNIQUE with `catalogue_major`), `group`,
`signature_json` (parameter types + return type), `is_total` (always `1`),
`purity` (always `pure`), `cost_class` (`o1 | oN`), `catalogue_major`,
`introduced_in`, `deprecated_in` (nullable), `description`, timestamps.
UNIQUE `(name, catalogue_major)`.

`agg_metric_definitions`: `id` PK, `metric_code` UNIQUE, `kind`
(`event | measure`), `module`, `value_type`, `event_payload_schema_json`,
`default_strategy` (`materialised | on_demand`), `supported_windows_json`,
`projector_class`, `is_active`, timestamps.

Both: no tenant scope, seeded by migration, read-only at runtime.

---

### 15.4 `rules` — tenant artefact header

**Purpose.** The stable identity of a rule across all its versions. This is the
`config_artefacts` row of spine S4, specialised.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` | No | auto | PK |
| `uuid` | `char(36)` | No | — | UUIDv7 public id. **UNIQUE.** External surfaces use this only |
| `tenant_id` | `bigint unsigned` | No | — | FK → `tenants.id` `ON DELETE RESTRICT`. **Law 6** |
| `decision_point_id` | `bigint unsigned` | No | — | FK → `decision_points.id` `ON DELETE RESTRICT` |
| `module` | `varchar(32)` | No | — | Denormalised for permission binding |
| `code` | `varchar(64)` | No | — | `^[A-Z][A-Z0-9-]{2,63}$` |
| `name` | `varchar(160)` | No | — | |
| `owner_pack_id` | `bigint unsigned` | Yes | `NULL` | FK → `rule_packs.id` `ON DELETE SET NULL`. Non-null = Ametecs-owned, read-only to the tenant |
| `is_seeded` | `tinyint(1)` | No | `0` | |
| `archived_at` | `datetime` | Yes | `NULL` | Soft archive; never hard-deleted |
| `created_by`, `updated_by` | `bigint unsigned` | No | — | FK → `users.id` `ON DELETE RESTRICT` |
| `created_at`, `updated_at` | `datetime` | No | — | |

PK `id` · UNIQUE `(uuid)` · **UNIQUE `(tenant_id, decision_point_id, code)`** ·
INDEX `(tenant_id, module, archived_at)` · INDEX `(owner_pack_id)`
Tenant scope: `tenant_id`, applied by the persistence layer. History: via
`rule_versions`. Soft delete: `archived_at`. Audit: full, spine S14.
PII: none (rule *content* may reference PII symbols; see §9.5).

---

### 15.5 `rule_versions` — the evaluated artefact

**Purpose.** An immutable, effective-dated, scoped version (§4.1). This is the
`config_versions` row of spine S4.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` | No | auto | PK |
| `uuid` | `char(36)` | No | — | UNIQUE |
| `tenant_id` | `bigint unsigned` | No | — | FK → `tenants.id` `ON DELETE RESTRICT` |
| `rule_id` | `bigint unsigned` | No | — | FK → `rules.id` `ON DELETE RESTRICT` |
| `version_no` | `int` | No | — | |
| `status_code` | `varchar(24)` | No | `'draft'` | FK → `ref_config_statuses.code`. **No DB enum** (S16) |
| `business_description` | `text` | No | — | Mandatory, ≥ 20 chars |
| `scope_json` | `json` | No | — | Spine S3.1 scope |
| `specificity_weight` | `int` | No | — | **Computed and stored** at publish, so ordering never recomputes |
| `priority` | `int` | No | `0` | |
| `effective_from` | `date` | No | — | |
| `effective_to` | `date` | Yes | `NULL` | |
| `when_src` | `text` | No | — | |
| `when_ast` | `mediumblob` | No | — | Canonical binary AST |
| `except_src` | `text` | Yes | `NULL` | |
| `except_ast` | `mediumblob` | Yes | `NULL` | |
| `on_conflict` | `varchar(24)` | No | — | FK → `ref_conflict_policies.code` |
| `stop_on_match` | `tinyint(1)` | No | `0` | |
| `severity_code` | `varchar(16)` | No | `'normal'` | FK → `ref_rule_severities.code` |
| `requires_approval` | `tinyint(1)` | No | `0` | |
| `spx_catalogue_major` | `int` | No | — | Pinned |
| `action_catalogue_major` | `int` | No | — | Pinned |
| `tags_json` | `json` | Yes | `NULL` | |
| `source_ref` | `varchar(255)` | Yes | `NULL` | The SmartPRS 2 citation replaced |
| `impact_preview_hash` | `char(64)` | Yes | `NULL` | The preview the approver approved |
| `checksum` | `char(64)` | No | — | SHA-256 over canonical content |
| `reason`, `change_note` | `text` | Yes | `NULL` | `reason` mandatory when `severity_code='high_risk'` |
| `created_by`, `submitted_by`, `approved_by` | `bigint unsigned` | Yes/No | — | FK → `users.id` `ON DELETE RESTRICT` |
| `approved_at`, `published_at` | `datetime` | Yes | `NULL` | |
| `superseded_by_version_id` | `bigint unsigned` | Yes | `NULL` | FK → `rule_versions.id` `ON DELETE RESTRICT` |
| `created_at`, `updated_at` | `datetime` | No | — | |

PK `id` · UNIQUE `(uuid)` · UNIQUE `(tenant_id, rule_id, version_no)` ·
**INDEX `(tenant_id, status_code, effective_from, effective_to)`** — the
resolution index · INDEX `(tenant_id, rule_id, status_code)` ·
INDEX `(checksum)` — the AST cache key.

Tenant scope: `tenant_id`. History: **this is the history**; nothing is
overwritten (Law 3, R-VII-008). Soft delete: none — versions are never deleted;
`archived` is a status. Audit: every insert and status transition, field-level.
PII: none.

**Application-level immutability**: a trigger-equivalent guard in the persistence
layer refuses any `UPDATE` on a row whose `status_code = 'published'` except to
set `effective_to` and `superseded_by_version_id` during supersession.

---

### 15.6 `rule_version_actions`

**Purpose.** The ordered typed action list (`then`).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` | No | auto | PK |
| `tenant_id` | `bigint unsigned` | No | — | FK, Law 6 |
| `rule_version_id` | `bigint unsigned` | No | — | FK → `rule_versions.id` `ON DELETE CASCADE` (a draft may be discarded; a published version never is) |
| `sequence` | `smallint unsigned` | No | — | 1-based |
| `action_code` | `varchar(48)` | No | — | FK → `action_definitions.action_code` (composite with major) `ON DELETE RESTRICT` |
| `action_major_version` | `int` | No | — | |
| `params_json` | `json` | No | — | `{name: {kind: literal|expr|formula_ref, value|src|ref, ast?}}` |
| `params_ast_blob` | `mediumblob` | Yes | `NULL` | Compiled ASTs for expression parameters |
| `created_at` | `datetime` | No | — | |

PK `id` · UNIQUE `(tenant_id, rule_version_id, sequence)` ·
INDEX `(action_code)` — the "which rules use this action?" query.
Tenant scope: `tenant_id`. History: immutable with its parent version.
PII: none.

---

### 15.7 `rule_test_cases`

Columns per §11.6, plus: `id` PK, `uuid` UNIQUE, `tenant_id` FK (Law 6),
`rule_id` FK `ON DELETE CASCADE`, `decision_point_id` FK, `name`, `description`,
`fixture_json`, `as_of` `date`, `expect_match` `tinyint(1)`,
`expect_actions_json`, `expect_error_code` `varchar(32)` nullable,
`is_seeded` `tinyint(1)`, `last_run_at` `datetime` nullable,
`last_status` `varchar(16)` nullable, `last_failure_json` nullable,
`created_by` FK, timestamps.
UNIQUE `(tenant_id, rule_id, name)` · INDEX `(tenant_id, last_status)`.
Fixtures may contain PII-shaped values: classified **PII-Amber**, masked in
exports, and a fixture may reference a real employee by uuid instead of copying
their data.

---

### 15.8 `rule_packs` and `rule_pack_items`

`rule_packs`: `id` PK, `uuid` UNIQUE, `pack_key` (e.g.
`ametecs/smartprs2-compat`), `pack_version` (semver), `owner`
(`ametecs | tenant`), `tenant_id` nullable (null for Ametecs packs — **the one
documented exception to Law 6, because an Ametecs pack is platform content, not
tenant business data**; it is a catalogue row, and every *instantiated* rule it
creates carries a `tenant_id`), `signature` `varbinary(512)` nullable,
`public_key_id` nullable, `description`, `published_at`, timestamps.
UNIQUE `(pack_key, pack_version)`.

`rule_pack_items`: `id` PK, `pack_id` FK `ON DELETE CASCADE`, `sequence`,
`payload_json` (the rule definition as portable JSON), `checksum`.
UNIQUE `(pack_id, sequence)`.

---

### 15.9 `agg_counter_definitions`

**Purpose.** The registered `(metric, window, predicate)` triples the projectors
maintain (§5.9.4). Derived from published rules at publish time.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` | No | auto | PK |
| `tenant_id` | `bigint unsigned` | No | — | FK, Law 6 |
| `metric_code` | `varchar(48)` | No | — | FK → `agg_metric_definitions.metric_code` |
| `window_kind` | `varchar(24)` | No | — | |
| `window_param` | `varchar(32)` | Yes | `NULL` | e.g. `30:days`, `mon` |
| `qualifier_hash` | `char(64)` | No | `'ZERO…'` | SHA-256 of the canonical predicate AST |
| `qualifier_src` | `text` | Yes | `NULL` | For the operator's benefit |
| `strategy` | `varchar(16)` | No | — | `materialised | on_demand` |
| `ref_count` | `int` | No | `0` | How many published rule versions need it |
| `backfilled_at` | `datetime` | Yes | `NULL` | |
| `is_active` | `tinyint(1)` | No | `1` | |
| `created_at`, `updated_at` | `datetime` | No | — | |

PK `id` · UNIQUE `(tenant_id, metric_code, window_kind, window_param, qualifier_hash)`
· INDEX `(tenant_id, strategy, is_active)`.
When `ref_count` falls to zero the definition is deactivated and its counters
become eligible for the retention sweep — never dropped immediately, because a
recalculation of a past period may still need them.

---

### 15.10 `agg_counters`

**Purpose.** Materialised aggregation state (§5.9.4). **Never authoritative**
(R-VII-026).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` | No | auto | PK |
| `tenant_id` | `bigint unsigned` | No | — | FK, Law 6 |
| `definition_id` | `bigint unsigned` | No | — | FK → `agg_counter_definitions.id` `ON DELETE RESTRICT` |
| `subject_type` | `varchar(32)` | No | — | |
| `subject_id` | `bigint unsigned` | No | — | |
| `window_key` | `varchar(48)` | No | — | e.g. `2026-08:CMP7`, `2026-10-02` for rolling |
| `count_value` | `bigint` | No | `0` | |
| `sum_value` | `decimal(18,4)` | No | `0` | |
| `generation` | `bigint unsigned` | No | `1` | Bumped on recomputation (R-VII-027) |
| `is_dirty` | `tinyint(1)` | No | `0` | |
| `computed_at` | `datetime` | No | — | |

PK `id` · **UNIQUE `(tenant_id, definition_id, subject_type, subject_id, window_key)`**
· INDEX `(tenant_id, is_dirty)` · INDEX `(tenant_id, subject_type, subject_id)`.
Partitioned by `window_key` prefix (month) where the platform supports it.
Tenant scope: `tenant_id`. History: none — it is a projection, rebuildable.
Soft delete: none. Audit: **not** in the audit ledger (it is derived state);
recomputations are logged operationally. PII: none.

---

### 15.11 `agg_window_dirty`

**Purpose.** The late-and-out-of-order event queue (§5.9.5).

`id` PK · `tenant_id` FK · `subject_type` · `subject_id` ·
`metric_code` · `window_kind` · `window_key` · `reason` (`late_event |
correction | rule_change | manual`) · `source_event_id` nullable ·
`business_date` `date` · `queued_at` · `processed_at` nullable ·
`is_locked_period` `tinyint(1)`.
UNIQUE `(tenant_id, subject_type, subject_id, metric_code, window_kind, window_key, processed_at)`
· INDEX `(tenant_id, processed_at, queued_at)`.
Rows with `is_locked_period = 1` route to the shadow/arrears path (§5.9.5 item 6)
rather than to recalculation.

---

### 15.12 `decision_traces`

**Purpose.** The full trace tier (§9.4).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` | No | auto | PK |
| `uuid` | `char(36)` | No | — | UNIQUE. The public trace id |
| `tenant_id` | `bigint unsigned` | No | — | FK, Law 6 |
| `decision_point_id` | `bigint unsigned` | No | — | FK |
| `subject_type` | `varchar(32)` | No | — | |
| `subject_id` | `bigint unsigned` | No | — | |
| `subject_on` | `date` | No | — | |
| `as_of` | `date` | No | — | |
| `status_code` | `varchar(16)` | No | — | `matched | no_match | failed` |
| `input_digest` | `char(64)` | No | — | Enables reproduction (R-VII-046) |
| `engine_version` | `varchar(48)` | No | — | |
| `payload_zstd` | `mediumblob` | No | — | Compressed canonical trace JSON |
| `payload_bytes` | `int unsigned` | No | — | Uncompressed size, for cost reporting |
| `error_code` | `varchar(32)` | Yes | `NULL` | |
| `nodes`, `agg_calls`, `micros` | `int unsigned` | No | — | Counters |
| `created_at` | `datetime` | No | — | |

PK `id` · UNIQUE `(uuid)` ·
INDEX `(tenant_id, subject_type, subject_id, subject_on)` — the explain lookup ·
INDEX `(tenant_id, status_code, created_at)` — the exceptions view.
Partitioned monthly on `created_at`.
Tenant scope: `tenant_id`. History: append-only; never updated.
Soft delete: none — dropped by partition under the `trace` retention class.
Audit: **read access is audited** (R-VII-049). PII: **Amber** — may contain
derived values about an individual; masked by the same field rules as the source.

---

### 15.13 `rule_publication_checks`

**Purpose.** The stored outcome of every publish-time validation, so a blocked
publication is explainable and a passed one is evidenced.

`id` PK · `tenant_id` FK · `rule_version_id` FK · `check_code` ·
`severity` (`block | warn | lint | pass`) · `message` · `detail_json`
(counterpart rule, witness, affected count, sample uuids) · `checked_at` ·
`resolved_by` nullable · `resolved_at` nullable · `resolution_note` nullable.
INDEX `(tenant_id, rule_version_id)` · INDEX `(tenant_id, severity, checked_at)`.
Audit: full. PII: sample employee uuids only — **never names in `detail_json`**;
the UI resolves uuids to names under the reader's own data scope.

---

### 15.14 Pinned trace columns on derived records

Added by the owning module's migration, not by this part's tables:

```
attendance_days      rule_versions_json, actions_json, input_digest,
                     trace_uuid, derivation_generation, counter_generations_json
payslip_lines        rule_versions_json, formula_versions_json, actions_json,
                     input_digest, trace_uuid
leave_charges        rule_versions_json, actions_json, input_digest, trace_uuid
compliance_items     rule_version_id, trace_uuid
```

**R-VII-071** Every derived business record stores the rule version ids that
produced it (spine S4, Law 4). A derived record without them fails the CI
conformance sweep.

---

## 16. API surface

All under `/api/v3`, OpenAPI-generated, cursor-paginated, idempotency keys on
every unsafe method, consistent error envelope (spine S17). Public ids are UUIDs
only. Every route carries an explicit policy binding, asserted by the CI sweep
(Law 5).

### 16.1 Catalogue (read-only)

| Method | Path | Permission | Purpose |
|---|---|---|---|
| `GET` | `/rule-engine/decision-points` | `rules.view` | List decision points with symbol tables and action allow-lists |
| `GET` | `/rule-engine/decision-points/{code}/symbols` | `rules.view` | The symbol table, typed, for editor autocomplete |
| `GET` | `/rule-engine/functions` | `rules.view` | The SPX function catalogue with signatures |
| `GET` | `/rule-engine/actions` | `rules.view` | The action catalogue with parameter schemas |
| `GET` | `/rule-engine/metrics` | `rules.view` | Event and measure catalogue with supported windows |

### 16.2 Authoring

| Method | Path | Permission | Purpose |
|---|---|---|---|
| `GET` | `/rules` | `rules.view.<module>` | List, filtered by module, decision point, status, tag, symbol |
| `POST` | `/rules` | `rules.author.<module>` | Create a rule artefact + draft v1 |
| `GET` | `/rules/{uuid}` | `rules.view.<module>` | Header + version list |
| `GET` | `/rules/{uuid}/versions/{version_uuid}` | `rules.view.<module>` | Full version |
| `POST` | `/rules/{uuid}/versions` | `rules.author.<module>` | New draft version |
| `PATCH` | `/rules/{uuid}/versions/{version_uuid}` | `rules.author.<module>` | Edit a **draft** only; 409 on a published version |
| `POST` | `/rules/{uuid}/versions/{version_uuid}/validate` | `rules.author.<module>` | Parse, type-check, budget-check, conflict-check; returns findings |
| `POST` | `/rules/{uuid}/versions/{version_uuid}/submit` | `rules.author.<module>` | To `pending_approval` via the workflow engine |
| `POST` | `/rules/{uuid}/versions/{version_uuid}/publish` | `rules.publish.<module>` | Publish; runs all checks and all tests; **refuses on any block** |
| `POST` | `/rules/{uuid}/versions/{version_uuid}/suspend` | `rules.publish.<module>` | Emergency suspension (R-VII-057); requires a reason |
| `POST` | `/rules/{uuid}/archive` | `rules.publish.<module>` | Archive the artefact; never deletes |
| `GET` | `/rules/{uuid}/diff?from=&to=` | `rules.view.<module>` | Semantic diff |
| `GET` | `/rules/search?symbol=&policy_key=&component=` | `rules.view` | Impact-of-rename search |

**R-VII-072** There is no `DELETE /rules/{uuid}`. A rule is archived, never
deleted (Law 3, spine S16).

### 16.3 Evaluation, simulation and explanation

| Method | Path | Permission | Purpose |
|---|---|---|---|
| `POST` | `/rule-engine/simulate` | `rules.simulate.<module>` | Body: decision point, subject (employee uuid or synthetic fixture), date or range, optional draft version uuids. Returns actions + full trace. **Never writes** |
| `POST` | `/rule-engine/simulate/cohort` | `rules.simulate.<module>` | Scope expression + date; returns an outcome distribution and a sample |
| `POST` | `/rule-engine/impact-preview` | `rules.publish.<module>` | Old vs new outcome comparison; returns the preview and its hash |
| `GET` | `/explain/{entity_type}/{entity_uuid}/{field}` | scope of the underlying record | Spine S15. Returns the pinned trace, and reproduces the full trace if purged |
| `GET` | `/rule-engine/traces/{uuid}` | scope of the subject | Full trace |
| `GET` | `/rule-engine/traces?subject=&from=&to=&rule=&outcome=` | `rules.view` + subject scope | Trace explorer |

**R-VII-073** `/rule-engine/simulate` resolves the subject from the request only
after a data-scope check; a user may simulate only for employees they may already
see. Self-service users may simulate for themselves only.

### 16.4 Tests, packs and operations

| Method | Path | Permission | Purpose |
|---|---|---|---|
| `GET`/`POST` | `/rules/{uuid}/tests` | `rules.author.<module>` | List / create stored test cases |
| `POST` | `/rules/{uuid}/tests/run` | `rules.author.<module>` | Run this rule's cases |
| `POST` | `/rule-engine/tests/run` | `rules.author` | Run every case for a decision point or the whole tenant |
| `GET` | `/rule-packs` | `rules.view` | Available packs |
| `POST` | `/rule-packs/{key}/import` | `rules.publish` | Preview + import; refuses on conflict or test failure (R-VII-010) |
| `GET` | `/rule-packs/{key}/diff` | `rules.view` | Pack diff against current state |
| `POST` | `/rule-engine/recalculate` | `<module>.recalculate` | Scope + date range; `preview=true` returns the diff without writing |
| `GET` | `/rule-engine/coverage?decision_point=` | `rules.view` | Which employees are governed by which rule, and which by none |
| `GET` | `/rule-engine/health` | `ops.view` | Dirty-window backlog, counter drift, failed evaluations, budget breaches by rule |

### 16.5 Error envelope

```json
{ "error": { "code": "RULE_PUBLISH_BLOCKED",
             "message": "Ambiguous precedence between LATE-OPS-A and LATE-OPS-B",
             "details": [ { "check": "precedence_tie",
                            "counterpart_rule": "LATE-OPS-B",
                            "counterpart_version": "…uuid…",
                            "specificity": 400, "priority": 0,
                            "witness": { "attendance.late_minutes": "31m" },
                            "affected_employee_count": 412,
                            "sample_employee_uuids": ["…","…","…"] } ],
             "request_id": "…", "correlation_id": "…" } }
```

Machine-readable codes include `RULE_PUBLISH_BLOCKED`, `RULE_TYPE_ERROR`,
`RULE_SYMBOL_UNKNOWN`, `RULE_ACTION_NOT_ALLOWED`, `RULE_BUDGET_EXCEEDED`,
`RULE_TEST_FAILED`, `RULE_EVAL_FAILED`, `RULE_AGG_UNAVAILABLE`,
`RULE_VERSION_IMMUTABLE`, `RULE_EFFECTIVE_IN_LOCKED_PERIOD`.

---

## 17. Events

### 17.1 Emitted

| Event | When | Payload | Consumed by |
|---|---|---|---|
| `rule.drafted` | A draft version is created | rule uuid, version uuid, author | Audit, activity feed |
| `rule.validated` | Validation completes | version uuid, findings summary | Builder UI |
| `rule.submitted` | Submitted for approval | version uuid | Workflow engine |
| `rule.published` | Version published | rule code, version uuid, effective_from, scope summary, impact hash, approver | Cache invalidation, counter-definition registration, notification, audit |
| `rule.superseded` | A prior version is closed | old and new version uuids, boundary date | Audit, staleness marking |
| `rule.suspended` | Emergency suspension | version uuid, reason, actor | Alerting, staleness marking, audit |
| `rule.publish_blocked` | Publication refused | version uuid, check code, counterpart | Alerting, audit |
| `rule.pack_imported` | A pack is imported | pack key, version, rule count, signature status | Audit |
| `rule.matched` | *(sampled)* A rule matched during a batch | rule code, subject count | Analytics, coverage reporting |
| `rule.evaluation_failed` | Evaluation error | rule code, version, error code, subject, node path | **Operator alert**, exception list, ops dashboard |
| `rule.budget_exceeded` | A budget breach | rule code, budget, measured value | Ops dashboard, circuit breaker |
| `rule.action_conflict` | Two matched rules emitted conflicting actions | both rule codes, action, both values, subject | Operator alert, exception list |
| `aggregation.window_dirtied` | A late event invalidated a window | subject, metric, window key, reason | Recalculation scheduler |
| `aggregation.counter_drift_detected` | Reconciliation found divergence | definition, subject sample, delta | **Operator alert** |
| `attendance.late_event_in_locked_period` | A late event landed inside a locked period | subject, business date, period, computed delta | Arrears/adjustment path (§5.9.5) |
| `compliance.gate_triggered` | A `GATE_PAYMENT` action fired | subject, event, mode, reason code | Compliance register, notification |
| `rule.trace_write_deferred` | A trace could not be written synchronously | subject, reason | Ops dashboard |

### 17.2 Consumed

| Event | Source | Effect |
|---|---|---|
| `punch.normalised` | Ingest (spine S10) | Projector updates counters; may dirty windows |
| `attendance.day.superseded` | Attendance derivation | Recomputes dependent counters |
| `leave.request.approved` / `.cancelled` | Leave | Counter updates; may dirty windows |
| `employee.assignment.changed` | Employee master | Invalidates that employee's scope signature; may change which rules govern them from the effective date |
| `policy.published` / `formula.published` | Configuration | Cache invalidation; staleness marking for records that consumed the prior version |
| `rule_pack.published` (Ametecs) | Platform | Offers the pack; never auto-applies to a tenant |
| `period.locked` / `period.reopened` | Payroll | Switches the late-event path between shadow and recalculation |
| `statutory_pack.activated` | Rule pack (spine S12) | Invalidates `statutory.*` resolution caches; marks affected derivations stale |

**R-VII-074** `rule.evaluation_failed`, `rule.action_conflict` and
`aggregation.counter_drift_detected` are **alerting** events with a named owner
and an SLA, not log lines. *SmartPRS 2 has no alerting on any rule outcome, and
its only error digest reads the last 4,000 log lines and emails ten of them,
truncated to 240 characters, to an address in `.env` (`ErrorDigest.php:23-98`).*

---
## 18. Configuration surface

The explicit list of what a tenant administrator can change without code, tested
against the brief's Section 127 standard.

### 18.1 What an administrator can do without code

| # | Capability | Where | Requires approval? |
|---|---|---|---|
| 1 | Create, edit and publish a rule at any decision point their permissions cover | Rule builder / expression editor | Yes for `high_risk` and money-affecting |
| 2 | Set a rule's scope across all 21 dimensions, including a named employee list | Builder, scope pane | Per (1) |
| 3 | Set a rule's condition, including bands of arbitrary count, step functions, rolling and period windows, streaks and ledger balances | Builder / editor | Per (1) |
| 4 | Choose and parameterise any action in the decision point's allow-list | Builder, action cards | Per (1) |
| 5 | Set `priority`, `on_conflict`, `stop_on_match`, `except` | Builder | Per (1) |
| 6 | Set `effective_from` / `effective_to` — including scheduling a future change and expiring a temporary exception | Builder | Per (1) |
| 7 | Author, run and store test cases; require them on publish | Test panel / simulator | No |
| 8 | Simulate any rule set against a real or synthetic employee, single day or range or cohort | Simulator | No |
| 9 | View the impact preview and the coverage report before publishing | Publish flow | No |
| 10 | Suspend a published rule immediately, with a reason | Rule detail | Yes |
| 11 | Create and edit **policy values** referenced by rules (`late_grace_min`, `comp_off_min_minutes`, `credential_gate_mode`, …), effective-dated and scoped | Policy configuration | Per artefact severity |
| 12 | Import an Ametecs starter pack, then narrow it with more specific rules | Rule packs | Yes |
| 13 | Export their own rules as a portable pack (e.g. to a sandbox tenant) | Rule packs | No |
| 14 | Define value sets used by `Code` comparisons (statuses, leave types, credential types, penalty reasons) | Entity schema engine (spine S9) | Per artefact severity |
| 15 | Define notification audiences referenced by `NOTIFY` | Notification configuration | No |
| 16 | Define caps referenced by `cap_ref` (per day, per period) | Policy configuration | Yes |
| 17 | Trigger a preview recalculation for a scope and date range | Recalculation screen | Yes to apply |

### 18.2 The Section 127 test, itemised

| Brief's example | Expressible without code? | How |
|---|---|---|
| "3 late arrivals over 10 minutes = half day" | **Yes** | RL-ATT-001, unchanged |
| Change 3 → 5 | Yes | Edit one condition operand, or edit a policy value with no rule change |
| Change 10 minutes → 15, for Hyderabad only | Yes | RL-ATT-003(b): a branch-scoped grace rule |
| Change half day → full day | Yes | One action parameter |
| Change the window from the payroll month to a rolling 30 days | Yes | Change `payroll_period` to `rolling(30, days)` — RL-ATT-010 |
| Apply it only to the Agent grade | Yes | Add `grade` to the scope |
| Exempt one employee for six months | Yes | RL-ATT-012, effective-dated |
| Add a fourth lateness band | Yes | One more `BANDS` entry |
| Make it effective from 1 April without touching March | Yes | `effective_from` (§8.2 Example 8) |
| Apply the same shape to PCC instead of DRA | Yes | RL-CMP-001 with a different `credential_gate_type` |
| Gate a fourth money point | Yes | Add the event code to `credential_gate_events` |
| Add a brand-new *kind* of effect not in the action catalogue | **No — by design** | Ametecs release activity (§6.5, R-VII-034) |

**R-VII-075** Every row in the "what a customer CANNOT change without code"
backlog of the attendance analysis §5.2(b) items 13–35 and the payroll analysis
§5.2 items relating to late, break, sandwich, week-off and probation policy is
either expressible by this part's rule engine, by the formula engine, or is
explicitly recorded as a platform behaviour with a stated reason. The mapping is
the migration register of §13.1.

### 18.3 Explainability recap (spine S15)

For this area, "How was this calculated?" shows: the decision point, every
candidate rule with its scope summary, specificity and priority; each rule's
outcome (`matched`, `not matched`, `matched then excepted`, `outranked`); for a
matched rule, the condition tree with every substituted value and the truth of
every node; the resolved action parameters with their formula traces where
applicable; the rule version, effective range and business description; the
counters consumed with their generation numbers; and the engine and catalogue
versions. §9.3 is the rendered form.

---

## 19. Edge cases, failure states and tests

### 19.1 Edge cases and the declared behaviour

| # | Case | Behaviour |
|---|---|---|
| 1 | No rule matches at a decision point | `status = no_match`; the domain service applies its **declared default**, which is itself configuration (a tenant-default rule at specificity 0), and the trace records "no rule matched" |
| 2 | Zero published rules exist at a decision point | Same as (1). The coverage report warns; it is not an error |
| 3 | A rule's scope references an org unit that was later deleted | Org units are soft-deleted; the scope still resolves and matches nobody; the publish-time warning "matches no employees" fires on the next validation, and the coverage report shows the orphan |
| 4 | An employee has no assignment on a scoped dimension | The dimension value is `Null`; a rule constraining that dimension does **not** match; the trace records `scope_miss(dimension=grade, value=null)` |
| 5 | Evaluation date before joining or after exit | Platform short-circuit (§7.6); no rule runs |
| 6 | Employee transferred mid-month | Rules resolve per date from the effective-dated assignment; days before and after the transfer may be governed by different rules, and the trace shows which |
| 7 | Rule published mid-period | It governs from `effective_from` onward only; earlier days in the same period keep the prior version. The period's settlement shows both |
| 8 | Two versions of the same rule with adjacent ranges | Exactly one is a candidate on any date; overlap is refused at publish |
| 9 | A rule whose `when` is `TRUE` and whose scope is empty | Governs the whole tenant; publication requires the impact preview and approval |
| 10 | A rule matching more than one action of the same additive kind | Actions accumulate; caps apply; the trace shows each contribution |
| 11 | Conflicting non-additive actions from two matched rules | `ERR_ACTION_CONFLICT`; subject becomes an exception (R-VII-037) |
| 12 | `except` true on every matched rule | `status = no_match` with `matched_then_excepted` recorded for each — visibly different from "nothing matched" |
| 13 | `when` returns `UNKNOWN` because an input is null | Does not match; the null-bearing symbol is named in the trace (§5.4.3) |
| 14 | A `not_null_required` symbol is null | `ERR_MISSING_INPUT`; evaluation fails; subject becomes an exception |
| 15 | Aggregation window is dirty | Inline recompute if under the row cap, else `ERR_AGG_UNAVAILABLE` and queue for recalculation; **never a stale read** |
| 16 | Late punch arrives for an open period | Windows dirtied; recalculation re-derives; diff shown |
| 17 | Late punch arrives for a **locked** period | Shadow recalculation; `attendance.late_event_in_locked_period`; arrears path; the period is not silently changed |
| 18 | Out-of-order punches change an occurrence index | Whole subject-window re-derived; ordinal-based penalties recomputed, not patched (§5.9.5 item 5) |
| 19 | A leap day, a 29-Feb date literal, a 31st in a 30-day month | `CLAMP_DAY_TO_MONTH`; date functions are calendar-correct. *SmartPRS 2 never greets a 29-Feb birthday in a common year (NEW-NOT-108) — the same class of bug* |
| 20 | A cross-midnight shift | The attendance day comes from the configured attendance-day rule (spine S10); all lateness arithmetic is on instants (§5.7, `attendance.late_minutes`), so a 00:15 arrival against a 22:00 shift is 2 h 15 m late, not on time |
| 21 | A payroll period spanning two calendar months (cut-off cycle) | `payroll_period` windows come from the pay cycle, not the calendar (R-VII-022) |
| 22 | Financial-year boundary inside a rolling window | `rolling` is date arithmetic; it crosses boundaries correctly. `financial_year` uses the tenant's configured FY start, not a hard-coded April. *`FinYearController::fyOf():30-40` hard-codes April (BR-STAT-212)* |
| 23 | Two tenants publish rules with the same code | Fine — uniqueness is `(tenant_id, decision_point, code)` |
| 24 | A rule references a value-set member that is later deactivated | Publish-time check passes at the time; runtime raises `ERR_SET_MEMBER`; the rename/impact search (§11.7) is the prevention |
| 25 | A rule references a component code that is later renamed | Component **codes** are immutable; labels are not. A rename does not affect any rule — the whole point of code-based identity |
| 26 | The AST cache is cold | First evaluation deserialises; measured in the p99 targets |
| 27 | Redis unavailable | Falls back to process cache and direct load; slower, correct. Never falls back to skipping rules |
| 28 | A batch worker dies mid-shard | The shard is retried from its checkpoint; fingerprints make re-application a no-op |
| 29 | Trace write fails | Derivation still completes; `trace_deferred`; ops alert; the reproduce path serves the trace |
| 30 | Clock skew between workers | Irrelevant — SPX has no clock (R-VII-019) |
| 31 | An employee governed by 200+ candidate rules | Refused at publish with the scopes named (R-VII-040) |
| 32 | An administrator publishes a rule while a batch is running | The batch uses the rule set resolved at its start, recorded in its input snapshot; the new rule takes effect on the next run, and the affected records are marked stale |
| 33 | Rule effective date back-dated into a locked period | Refused; requires an audited period reopen |
| 34 | A pack import would create a tie with a tenant's own rule | Import refused with both artefacts named (R-VII-010) |
| 35 | A tenant with zero employees | Rules publish; impact preview reports zero; the "matches nobody" warning fires |

### 19.2 Failure states

| State | Detection | Operator sees | Recovery |
|---|---|---|---|
| Rule evaluation failed for a subject | `status = failed` | Exception on the run/day with rule, version, error, node | Fix the rule or the data; re-run the subject |
| Action conflict | `ERR_ACTION_CONFLICT` | Both rules and both values | Set a priority, narrow a scope, or make the conditions disjoint |
| Counter drift | Nightly reconciliation | Definition, subject sample, delta | `recompute_counters(scope, range)` |
| Dirty-window backlog growing | Ops dashboard metric | Backlog depth and oldest entry | Scale the recalculation queue; investigate the ingest source |
| Budget breaches concentrated on one rule | `rule.budget_exceeded` rate | The rule named | Simplify the rule; raise the decision-point budget (Ametecs) |
| Publication blocked | Publish response + `rule_publication_checks` | The check, the counterpart, the witness, the sample | One of the four listed resolutions (§8.2 Example 5) |
| Stored tests failing nightly | Test run status | The failing case and its trace | Usually reference-data drift; fix the data or the expectation, with a reason |
| Trace partition exhausted / storage pressure | Storage metric | Partition sizes | Retention sweep; traces remain reproducible (R-VII-046) |
| Circuit breaker tripped for a tenant | Ops dashboard | Tenant, rules, error rate | Suspend the offending rule; resume |

### 19.3 Tests that must have exact-value regression coverage

Each test names the exact expected value, so a regression is a failed assertion
rather than a judgement call. Values in brackets are the seeded-pack values used
by the fixture, not statements of policy.

**Language and types**

1. `Money * Money` → publish-time type error.
2. `Hours + Minutes` → publish-time type error; `TO_MINUTES(h) + m` compiles.
3. `BASIC % 40` with `BASIC = ₹15,000.00` → `₹6,000.00` exactly (integer paise).
4. `ROUND` without a mode → parse error.
5. `CASE` without `ELSE` → parse error.
6. `a < b < c` → syntax error.
7. `1 / 0` → `ERR_DIV_ZERO`, evaluation fails, subject becomes an exception.
8. `NULL = NULL` → `UNKNOWN`; the rule does not match; the trace names the symbol.
9. `COALESCE(ERR_DIV_ZERO_result, 5)` → still the error (COALESCE absorbs `Null`, not errors).
10. A `MATCHES` pattern containing `(a+)+` → refused at publish.
11. `NTH_WEEKDAY(#2026-11-01#, 'sat', 2)` → `2026-11-14`; the `ceil(day/7)` form would give `2026-11-08` — assert they differ.
12. `ALLOCATE(₹1,00,000.00, [1,1,1])` → `[₹33,333.33, ₹33,333.33, ₹33,333.34]`, summing exactly to the whole.

**Rules from §12**

13. RL-ATT-001: lates of 18m, 132m, 11m, 23m in a payroll month → penalties on occurrences 3 and 4 only; month total **1.0 day**; occurrences 1 and 2 traced as not matching.
14. RL-ATT-001 with the cut-off cycle 26-Jul→25-Aug: a late on 27-Jul counts in the **August** period, not July.
15. RL-ATT-002: `late_minutes` 119m → 0; 120m → 0 (strict `>`); 121m → 0.5d; 240m → 0.5d; 241m → 1.0d **and** status `ABSENT`. Boundary assertions on all five.
16. RL-ATT-002: sub-band lateness of 30m → **0 penalty** (assert explicitly, because SmartPRS 2 charges `l1_cut` here).
17. RL-ATT-003: general shift in HYD → grace 15m; night shift in HYD → 20m; a BLR employee → 10m. Trace shows the outranked rules.
18. RL-ATT-004: late 23m, late-exit 41m, worked 548m ≥ 540m → offset 23m, effective lateness 0, RL-ATT-001 does not match. Cap: late-exit 90m with cap 60m → offset 60m only.
19. RL-ATT-005: worked 612m, threshold 480m, block 30m, floor → OT **120m**, not 132m. An employee in BLR at grade G3 → no match. An employee in HYD at grade G2 → no match. A probationer in HYD at G3 → no match.
20. RL-ATT-006: worked 239m → `ABSENT`; 240m → `HALF_DAY`; 480m with `present_min_minutes = 240` → `PRESENT`. A single punch with 0 worked minutes → `ABSENT` (assert explicitly against SmartPRS 2's full present day).
21. RL-ATT-007: worked 300m on a weekly off with thresholds [240m, 480m] → **0.5 day** comp-off; 500m → 1.0 day; 200m → no credit.
22. RL-ATT-008: worked on a festival holiday → **2.0 days**; on a national holiday → RL-ATT-008 does not match; on a holiday not applicable to the employee's scope → treated as a working day.
23. RL-ATT-009: all five conditions true → `ABSENT` + one notification. Then, one at a time: a punch exists → no match; approved leave exists → no match; holiday → no match; weekly off → no match; employee inactive → no match. Five separate assertions.
24. RL-ATT-009: employee status stored as `Active` (capital A) → **still swept** (assert against `AbsenceService.php:122`).
25. RL-ATT-010: lates on 30-Sep, 01-Oct, 02-Oct → matches on 02-Oct in the rolling window and does **not** match under RL-ATT-001's monthly window. Both traces asserted.
26. RL-ATT-011: budget 60m, break 155m, block 30m, step 0.25d, `ceil` → **1.0 day**. Break 61m → 0.25 day. Break 60m → no penalty.
27. RL-ATT-012: on 15-Sep-2026 grace = 60m; on 01-Mar-2027 grace = 15m (the branch rule), with the employee rule traced as `not_in_effect`.
28. RL-CAL-001: November 2026 → 14th and 28th are weekly offs; the 7th and 21st are not. August 2026 (five Saturdays) → 8th and 22nd. Assert against `ceil(day/7)` giving different days.
29. RL-LVE-001: leave Fri 14-Aug and Mon 17-Aug, Sat/Sun off → Sat and Sun charged, total **4.0 days**. Same with type `SL` → **2.0 days**. Same with statutory maternity → **2.0 days**. Same with `sandwich_enabled = false` → 2.0 days.
30. RL-LVE-001 on a cut-off period spanning two months → correct days charged (assert against the day-of-month indexing bug).
31. RL-PAY-001: a probationer → PF_EE, PF_ER, PT, TDS suppressed; ESI and LWF **not** suppressed; one compliance exception raised. A confirmed employee → nothing suppressed. Service beyond `probation_suppress_max_months` → nothing suppressed.
32. RL-CMP-001: DRA expired + mode `block` + event in the list → payment blocked. Mode `warn` → allowed, warning recorded, audit written. Mode `off` → no match. Event not in the list → no match. **No DRA record at all → blocked** (assert the absence asymmetry is closed, R-VII-018).
33. RL-CMP-001 with the gate type switched to `PCC` → identical behaviour on the PCC credential, with no code change.

**Engine semantics**

34. Precedence: §8.2 Examples 1–4 and 6–8, each asserted on the resolved rule and the trace.
35. Precedence tie (Example 5) → publication refused with both rules, the witness `late_minutes = 31m` and the affected count.
36. Determinism: the golden-hash corpus (R-VII-038).
37. Cross-tenant: tenant A's rule is not a candidate for tenant B's subject (R-VII-052).
38. Immutability: `PATCH` on a published version → 409 `RULE_VERSION_IMMUTABLE`.
39. Back-dating into a locked period → refused.
40. Idempotency: applying the same action set twice produces one effect row (R-VII-032).
41. Recalculation convergence: re-derive a month twice → byte-identical results and no duplicated ledger entries.
42. A rule suspended mid-month → days before the suspension keep their outcome; days after do not; both traced.
43. Budget: a rule engineered to exceed the node budget → `ERR_BUDGET_NODES`, subject exception, batch continues, failure count 1, batch does **not** report success (R-VII-070).
44. Aggregation late-arrival: derive a month, then land a punch dated mid-month, then assert (a) the windows are dirty, (b) no stale read occurs, (c) recalculation produces the corrected occurrence indices, (d) the diff is exact.
45. Locked-period late arrival → `attendance.late_event_in_locked_period` with the computed delta; the locked period's numbers are unchanged.
46. Trace: every evaluation returns a trace; a mocked trace-less return throws (R-VII-036).
47. Trace reproduction: purge a full trace, then reproduce it from pinned data → byte-identical (R-VII-046).
48. Trace authorisation: an employee requesting a colleague's trace → 403, audited.
49. Performance: the §14.2 table, as CI-asserted budgets.
50. Query count: a 2,000-subject-day shard issues fewer than 40 queries (R-VII-068).
51. Fuzz: 100,000 mutated SPX inputs produce only parse/type errors or bounded evaluations (R-VII-050).
52. Static analysis: no `catch` in a domain rule path that discards (R-VII-020); no float arithmetic in money paths (Law 7); no business literal in `Domain\*` (Law 1).

---

## 20. Open items — PRODUCT OWNER DECISION REQUIRED

**D-VII-01 · On a rule-evaluation failure at a payment gate, hold or release?**
SmartPRS 2's DRA gate is explicitly built to "never break the payment path on
error" (`RequestController.php:1366-1394`; N §6 records this as a strength). This
part specifies the opposite (R-VII-021): a failure holds the payment for review.
**Recommendation: hold.** A `block` tenant chose blocking precisely to prevent
paying a non-compliant agent, and an engine error is not evidence of compliance.
*Trade-off:* an engine defect on payday could stall payments; mitigated because
the gate is evaluated at **preview**, not at the moment of payment, so a failure
surfaces hours earlier as a preview exception. If the Product Owner prefers
release-on-error, it must be a **per-tenant, audited, alerting** setting — never
a silent default.

**D-VII-02 · May an artefact declare a `specificity_override`?**
Spine S3.2 fixes the weights, and §8.2 Example 7 shows an outcome a customer may
find counter-intuitive (one very specific dimension losing to two moderately
specific ones). **Recommendation: no.** An override is a per-rule change to the
precedence semantics of the whole tenant, and it makes "why did this rule win?"
unanswerable without reading every rule. The remedy for a disputed outcome is to
narrow a scope. *Trade-off:* some customers will need a rule restructured rather
than a flag flipped. If it is granted, it must be Ametecs-set, not
tenant-editable, and must appear in every affected trace.

**D-VII-03 · The migration default for the minimum-hours presence gate
(RL-ATT-006).** SmartPRS 2 counts any punch as a full present day (CALC-B-14),
so introducing a gate changes historic LOP if any recalculation runs (OQ-B-03).
**Recommendation: seed the gate as `present_min_minutes = 0` at cutover** — i.e.
preserve SmartPRS 2 behaviour exactly — and present the gate as a deliberate,
dated policy change the customer makes after go-live, with the impact preview
showing them the cost. *Trade-off:* the defect persists until they act; the
alternative silently reduces pay for employees on day one of a new system.

**D-VII-04 · The migration default for "zero punches for the whole period"
(BR-PAY-013).** Today the LOP block is skipped entirely and the employee is paid
100% (`compute():1237`) — recorded in the analysis as "the single most dangerous
default in the system". **Recommendation: seed as `flag for review`**, so the
payroll preview blocks with a named exception rather than either paying in full
or docking a full month. *Trade-off:* an operator must clear the exception; that
is the point.

**D-VII-05 · Trace retention.** Full traces default to 13 months (§9.4).
**Recommendation: 13 months, tenant-configurable upward to the tenant's payroll
retention class, with the reproduce path covering anything older.** *Trade-off:*
storage against explain latency for old periods. Note the published privacy
policy commits to 90-day retention while nothing implements any retention at all
today (NEW-NOT-117) — the retention class must be reconciled with that
commitment before launch.

**D-VII-06 · Who may publish rules in a customer organisation?**
`rules.author.*` and `rules.publish.*` are separate (R-VII-054), and
money-affecting publications need a second human. **Recommendation: publication
of `payroll.*` and `compliance.payment_gate` rules is restricted to a named
Finance-side role, distinct from the HR role that authors attendance and leave
rules** — the same four-eyes shape SmartPRS 2 gets right for payroll approval
(`SalaryApprovalController.php:67`) and gets wrong everywhere else.
*Trade-off:* smaller customers may have one person; the platform must allow a
tenant to declare that and record the accepted control weakness explicitly rather
than by leaving both permissions on one role silently.

**D-VII-07 · Does Ametecs ship industry starter packs, and are they supported
artefacts?** §4.2 and §13.3 assume a signed `smartprs2-compat` pack plus
optional industry packs. **Recommendation: yes, and treat a shipped pack as a
supported artefact with its own version, changelog, tests and upgrade path** —
because the alternative is that every implementation re-authors the same forty
rules by hand and each customer's payroll behaviour becomes a bespoke artefact
nobody can support. *Trade-off:* Ametecs takes on a maintenance obligation for
pack content, which needs a named owner (compare spine D-03 for statutory
packs).

**D-VII-08 · Is `first_match` offered at all?** It is a performance shortcut that
costs trace completeness (§7.3). **Recommendation: keep it, but forbid it on
`high_risk` rules (already specified) and default every new rule to
`highest_priority_only`.** *Trade-off:* a marginal batch saving against the
ability to answer "why didn't the other rule apply?".

---

## 21. Compliance with the seven laws, and requirement index

### 21.1 Law compliance

| Law | How this part complies | Evidence in this part |
|---|---|---|
| **1 — No business literal in code** | Every threshold, band, window, penalty, grace, status, credential type, money point and audience in §12 is a rule, a policy value or a pack value. The action catalogue is behaviour, not policy; the function catalogue is arithmetic, not policy | §4.1, §12, §18.2 |
| **2 — No schema change at runtime** | The engine issues no DDL. Rules are rows; actions are rows; counters are rows; decision points, actions, functions and metrics are seeded by migration. No "custom action", no self-healing schema | §6.5, §15 |
| **3 — Nothing overwritten; everything versioned and dated** | `rule_versions` are immutable and effective-dated; publishing supersedes; derived records pin the version ids; suspension closes a range and never rewrites | R-VII-008, R-VII-057, R-VII-071, §8.2 Example 8 |
| **4 — Every number explains itself** | No result without a trace, enforced by a throw; the trace records candidates, outranking, node-level values, provenance and versions; reproducible after purge | R-VII-036, R-VII-045, R-VII-046, §9 |
| **5 — Authorisation is server-side, declarative, fails closed** | Rules run after authorisation, inside a tenant-scoped context; no action can affect authorisation; every API route has an explicit policy binding; evaluation errors never yield the permissive answer | R-VII-006, R-VII-031, R-VII-053, R-VII-021, §16 |
| **6 — Tenancy is structural** | `tenant_id` `NOT NULL` on every table in §15 except the four platform catalogues (stated explicitly) and participates in every unique index; cross-tenant candidacy is impossible and is tested | §15, R-VII-052 |
| **7 — Money is exact** | `Money` is integer minor units in a value object; `Money * Money` is a type error; `ROUND` requires a mode; `ALLOCATE` distributes residue; no float appears anywhere in the evaluator | §5.4, R-VII-014, test 12 |

### 21.2 Requirement index

R-VII-001 … R-VII-075, **75 numbered testable requirements**, located as follows:

| Range | Subject | Section |
|---|---|---|
| 001–006 | Engine boundaries and decision points | §3 |
| 007–010 | The rule model, immutability, packs | §4 |
| 011–021 | SPX grammar, types, nulls, errors | §5.1–5.8 |
| 022–027 | Aggregation primitives, counters, late events | §5.9 |
| 028–034 | Actions, idempotency, reversal, catalogue governance | §6 |
| 035–042 | Evaluation semantics, ordering, budgets, error surfacing | §7 |
| 043–044 | Precedence and tie-blocking | §8 |
| 045–049 | The decision trace, storage, PII | §9 |
| 050–058 | Safety, threat model, regex | §10 |
| 059–062 | Tooling: simulator, detector, preview, harness | §11 |
| 063–065 | Migration classification and shadow-run reconciliation | §13 |
| 066–070 | Performance targets, caching, batch path | §14 |
| 071–074 | Data model pinning, API deletion prohibition, alerting | §15–17 |
| 075 | Configuration-surface completeness | §18 |

### 21.3 Dependencies on other parts

| This part needs | From |
|---|---|
| The formula engine's contract, dependency graph and trace | Part VIII |
| The workflow engine's node types and immutability guarantees | Part IX |
| The entity schema engine's field definitions and value sets | Part on metadata (spine S9) |
| The attendance day model, punch layers and recalculation command | Part on attendance (spine S10) |
| The payroll pipeline's input snapshot and preview exceptions | Part on payroll (spine S11) |
| The statutory rule pack's signing, layering and validator | Part on statutory (spine S12) |
| The permission and data-scope model | Part on security (spine S13) |
| The audit ledger service | Part on audit (spine S14) |
| The notification engine's audiences, templates and channels | Part on notifications |
| The operations dashboard, queues and alerting | Part on background operations |

### 21.4 What this part deliberately leaves to others

The **content** of a customer's rules. This part specifies the mechanism, the
seeded compatibility pack and sixteen worked rules. Which of them a given
customer publishes, with which thresholds, in which scopes, is an implementation
decision recorded in that customer's configuration — which is precisely the
outcome the whole architecture exists to produce.

---
