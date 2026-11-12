# SMARTPRS 3 — PLATFORM SPINE
### The canonical architectural contract. Every part of the blueprint must conform to this document.

Status: DRAFT FOR PRODUCT OWNER APPROVAL
Owner: Ametecs India Private Limited
Purpose: this document fixes the decisions that all thirty-five parts of the
SmartPRS 3 Master Rebuild Blueprint depend on. Where a decision is genuinely the
Product Owner's, it is marked **PRODUCT OWNER DECISION REQUIRED** and a
recommendation is given. Where a design goes beyond anything in SmartPRS 2, it is
marked **SMARTPRS 3 ARCHITECTURE RECOMMENDATION**.

---

## S0. The one-sentence architecture

SmartPRS 3 is a **single multi-tenant Laravel application** whose business
behaviour is not written in PHP but **resolved at runtime from effective-dated,
scoped, versioned configuration** — metadata, rules, formulas, workflows,
templates and signed statutory rule packs — evaluated by a small set of
deterministic engines that **explain every number they produce**.

The PHP codebase is a **platform**. The product a customer experiences is
**configuration running on that platform.**

---

## S1. The seven laws

These are non-negotiable and are acceptance criteria, not aspirations. Every
module specification must demonstrate compliance with all seven.

**LAW 1 — NO BUSINESS LITERAL IN CODE.**
No component name, leave-type name, status name, role name, threshold, rate,
ceiling, slab, divisor, multiplier, grace period, day count, cut-off or audience
list may appear as a literal in application code. If a new customer could
reasonably ask for it to be different, it is data.
*Test:* a static-analysis rule fails the build on a bare numeric or string literal
inside `Domain\*` outside a declared constants allow-list.

**LAW 2 — NO SCHEMA CHANGE AT RUNTIME.**
Application request and job code may never execute DDL. `Schema::create`,
`Schema::table`, `ALTER TABLE`, `CREATE TABLE` are forbidden outside
`database/migrations`. Custom fields never create columns.
*Test:* the production database user has no `ALTER`, `CREATE` or `DROP`
privilege; a CI grep fails the build on DDL outside migrations; `migrate:fresh`
must produce a schema byte-identical to production's structure.

**LAW 3 — NOTHING IS OVERWRITTEN; EVERYTHING IS VERSIONED AND DATED.**
Configuration is never updated in place. A change creates a new version with an
effective range. Derived business records (attendance days, payslips, ledgers)
are superseded, never deleted. History is queryable "as at" any date.
*Test:* "what policy applied to employee E on date D?" and "what was employee E's
branch on date D?" must both be answerable by a single service call.

**LAW 4 — EVERY NUMBER EXPLAINS ITSELF.**
Every derived value — an attendance status, a leave unit, a payslip line, a
statutory deduction — carries a machine-readable trace naming the inputs, the
rule or formula, its version, the condition that matched, and the arithmetic
performed. The UI exposes this as "How was this calculated?" everywhere.
*Test:* no engine may return a value without a trace; a trace-less result is a
programming error that throws.

**LAW 5 — AUTHORISATION IS SERVER-SIDE, DECLARATIVE AND FAILS CLOSED.**
Every protected operation is gated by a policy evaluated on the server. Access
is denied on any error, any unknown role, any null scope. The UI never enforces
anything; hiding a control is a convenience, never a control.
*Test:* an automated sweep asserts every route has an explicit policy binding;
a fault-injection test asserts that an exception inside authorisation denies.

**LAW 6 — TENANCY IS STRUCTURAL, NOT CLERICAL.**
`tenant_id` is `NOT NULL` on every business table, participates in every unique
index, and is applied by the persistence layer — never hand-written in a query.
There is no null-tenant state and no global business table.
*Test:* a generated cross-tenant isolation test exists for every aggregate and
every API endpoint, and runs in CI.

**LAW 7 — MONEY IS EXACT.**
Money is carried as **integer minor units (paise)** in a value object through
every calculation. Floats never touch money. Rounding is declared per component
and applied at declared points. Splits allocate residue deterministically.
*Test:* a type-level ban on float arithmetic in `Domain\Money`; every payroll run
asserts `SUM(payslip lines) = run totals` exactly, or fails the run.

> **Why these seven.** Each corresponds to a confirmed, evidenced, systemic
> failure in SmartPRS 2: hard-coded component and role names silently break
> renamed configurations; ~49 tables and ~60 columns are created by controllers
> so the schema depends on which screens were opened; policies overwrite in place
> so editing a rule silently rewrites last year's payroll; no calculation can be
> explained or defended; authorisation fails open by design comment; the declared
> tenancy scope is inert because the column it filters on does not exist; and a
> float comparison decides whether a loan instalment is deducted.

---

## S2. Target platform

**PRODUCT OWNER DECISION REQUIRED — D-01: the SmartPRS 3 technology platform.**

**Recommendation: Laravel 12 / PHP 8.4 modular monolith, MySQL 8.0+, Redis, one
deployable artefact serving SaaS, private cloud and on-premise identically.**

| Option | For | Against | Verdict |
|---|---|---|---|
| **A. Laravel modular monolith (recommended)** | Section 87 demands identical business logic across SaaS and on-prem; on-prem customers run LAMP-class infrastructure and cannot operate a service mesh. Team knowledge transfers. Migration tooling can read both schemas in one process. Engines remain extractable behind interfaces. | Single process boundary; discipline must be enforced by tooling, not by network | **Recommended** |
| B. PHP core + separate engine services | Independent scale for payroll/attendance | On-prem becomes multi-service; parity with SaaS is lost; operational burden lands on customers who today do not reliably run a cron worker | Rejected for v3.0; revisit at scale |
| C. Non-PHP rewrite (Go / .NET) | Cleanest slate | Discards all team knowledge and the migration path; adds 12–18 months; the problem is architectural, not language-borne | Rejected |

Supporting decisions, all recommendations:

- **Modularity enforced by tooling.** Each bounded module is a first-class
  namespace with a declared public contract. A dependency-rule test (Deptrac or
  equivalent) fails the build when a module reaches into another's internals or
  when `Domain` depends on `Http`.
- **Identifiers.** Every aggregate has a `bigint` internal primary key and a
  **UUIDv7 public id**. External surfaces (API, URLs, exports, integrations) use
  the UUID only. Sequential integers never appear in a URL — this alone removes
  an entire class of the eighteen IDORs found in SmartPRS 2.
- **Queue and scheduler are mandatory, not optional.** The installer refuses to
  complete without a working scheduler and queue worker, and the health check
  fails loudly and visibly if either stops. SmartPRS 2's most damaging
  operational failures come from customers running neither.
- **Front end.** One SPA against `/api/v3` exclusively, so the public API is
  proven by the product's own UI. The two-front-end situation in SmartPRS 2
  (a 12,533-line PHP heredoc plus a dead 234 KB prototype and orphaned Blade
  views) is not repeated. **PRODUCT OWNER DECISION REQUIRED — D-02: SPA
  framework.** Recommendation: React + TypeScript, for hiring depth in India.

---

## S3. The organisation model

One table, `org_units`, holds a typed tree. Level types are **data**, seeded
with the platform's standard ladder and extensible per tenant:

```
platform → tenant → legal_entity → company → establishment → state → zone
        → branch → department → sub_department → team
```

`cost_centre`, `business_unit` and `client_process` are additional **typed
nodes** that may attach at any level, because customers organise them
differently and the hierarchy must not presume.

Orthogonal **classification dimensions** are not tree nodes; they are attributes
of an employment:

```
designation · grade · employee_category · employment_type · employment_stage
· shift · location · pay_group · union/settlement group
```

Every employee-to-org and employee-to-classification assignment is an
**effective-dated row**, never a column on the employee. This is what makes
"what was this employee's branch on 15 May?" answerable — a question SmartPRS 2
cannot answer at all.

### S3.1 Scope

A **Scope** is the universal targeting expression attached to every configurable
artefact. It is a set of dimension predicates:

```
scope := { dimension → (IN [values] | ALL) } for each of:
  legal_entity, company, establishment, state, zone, branch, department,
  sub_department, team, cost_centre, business_unit, client_process,
  designation, grade, employee_category, employment_type, employment_stage,
  shift, location, pay_group, employee
```

`ALL` means "unconstrained on this dimension". An empty scope is the tenant
default.

### S3.2 Precedence — deterministic, and validated at publish time

Resolution order for two candidate artefacts both matching an employee on a date:

1. **Specificity.** Each dimension carries a fixed weight. Sum the weights of
   dimensions that are constrained (not `ALL`). Higher total wins.
   Weights, most to least specific:
   `employee(1000) > team(500) > sub_department(450) > department(400) >
    designation(350) > grade(340) > shift(330) > employee_category(320) >
    employment_type(310) > employment_stage(300) > cost_centre(250) >
    client_process(240) > business_unit(230) > location(200) > branch(180) >
    zone(160) > state(140) > establishment(120) > company(100) >
    legal_entity(60) > tenant default(0)`
2. **Explicit priority.** If specificity ties, the artefact's declared integer
   `priority` decides.
3. **No third tie-break exists.** If both tie, **publication is blocked** by the
   configuration validator with a message naming both artefacts and the employees
   affected. Ambiguity is a configuration error, never a silent coin-toss.

This directly replaces SmartPRS 2's three-level scope resolved by row order.

**SMARTPRS 3 ARCHITECTURE RECOMMENDATION.** The weights are themselves stored as
a platform constant table so that an unusual customer hierarchy can be
accommodated by Ametecs without code — but they are **not** tenant-editable,
because a customer changing precedence weights silently changes every historical
resolution.

---

## S4. Effective dating, versioning and the draft/publish model

Every configurable artefact — a policy, a rule, a formula, a salary structure, a
workflow, a template, a report definition, a statutory pack, a field definition —
uses one shared pattern.

```
config_artefacts
  id, tenant_id, artefact_type, artefact_key, name, description

config_versions
  id, tenant_id, artefact_id, version_no,
  status            ENUM: draft | testing | pending_approval | published
                          | superseded | archived
  scope_json, priority,
  effective_from DATE NOT NULL, effective_to DATE NULL,
  payload_ref       (typed child rows; JSON only for genuinely free-form payloads)
  created_by, created_at, submitted_by, approved_by, approved_at,
  published_at, superseded_by_version_id,
  reason, change_note, checksum
```

Rules:

- **Resolution** is always `resolve(type, key, scope, as_of_date)`. Payroll for
  August resolves with a date inside August, so a policy published in October
  cannot affect it. This is the direct fix for the single most dangerous property
  of SmartPRS 2's configuration model.
- **Publishing** supersedes rather than overwrites: the prior version's
  `effective_to` is closed the day before the new `effective_from`.
- **Overlap** within one artefact and scope is rejected at publish time.
- **Gaps** are rejected for artefacts declared `continuous` (e.g. a pay cycle).
- **Diff before publish** is mandatory for artefacts declared `high_risk`: old
  value, new value, impacted employee count, impacted modules, effective date.
- **Approval before publish** is required for `high_risk` artefacts, using the
  workflow engine — so maker-checker on configuration is the same mechanism as
  maker-checker on payroll.
- **Every derived record stores the version ids it was produced from.** A payslip
  names the statutory pack version, structure version, formula versions and rule
  versions that produced it. This is what makes a payslip defensible three years
  later.

---

## S5. The expression language (`SPX`)

One safe expression language serves the rule engine, the formula engine, template
conditions, report filters and workflow branch conditions. Learning it once is
enough.

**Absolute constraints.** SPX is not a programming language. It has no
assignment, no loops, no recursion, no I/O, no reflection, no host-language
escape. It cannot read the filesystem, open a socket, run a shell, or query the
database directly. Customer-supplied PHP, JavaScript or Python is **never**
accepted anywhere in SmartPRS 3, under any configuration, for any customer.

**Execution model.** Parse → validate against a declared symbol table → compile
to an immutable AST → evaluate against a **prepared context**. Evaluation is
deterministic, side-effect-free, and bounded by a node-count and time budget.
The stored artefact is the AST plus the source text, so a published rule cannot
be re-parsed differently by a later version of the parser.

### S5.1 Types

`Number` · `Money` (integer paise + currency) · `Days` · `Hours` · `Minutes` ·
`Date` · `DateTime` · `Time` · `Duration` · `Boolean` · `Text` · `Code` (a value
from a value set) · `List<T>` · `Null`

Types are checked at publish time. `Money / Number → Money`,
`Money * Number → Money`, `Money * Money` is a type error. `Days` and `Hours` do
not silently coerce. This eliminates the entire class of unit-confusion bugs
found in SmartPRS 2, where absence is measured in working days and valued in
calendar days.

### S5.2 Operators

`+ - * /` · `%` (percent-of, not modulo — `X % 40` is "40 percent of X", the
notation payroll users expect; modulo is `MOD(a,b)`) · `= <> < <= > >=` ·
`IN` `NOT IN` · `BETWEEN` · `CONTAINS` `NOT CONTAINS` · `MATCHES` (anchored,
timeout-bounded regex) · `AND` `OR` `NOT` · parentheses to any depth.

### S5.3 Variable namespaces

Read-only, resolved by the engine from a prepared context. A namespace not
declared available for the current evaluation is a publish-time error.

```
employee.*      code, uuid, joining_date, exit_date, stage, category,
                employment_type, gender, dob, custom fields by key
org.*           company, establishment, state, branch, department, team,
                cost_centre, designation, grade, location  (as at the eval date)
service.*       years, months, days, completed_years
period.*        start, end, month, year, fin_year, working_days,
                calendar_days, payable_days, cut_off_day
shift.*         start, end, hours, grace_in, grace_out, is_night
day.*           date, weekday, is_weekly_off, is_holiday, holiday_type,
                is_working_day
attendance.*    first_in, last_out, punch_count, worked_minutes, late_minutes,
                early_exit_minutes, break_minutes, status, ot_minutes
leave.*         type, units, is_paid, balance(type), from, to
salary.*        component(code), gross, ctc, basic, net   (by CODE, never name)
statutory.*     resolved pack values, by code
policy.*        any resolved policy value, by key
agg.*           see S5.5
```

### S5.4 Function library (whitelisted, closed set)

```
Arithmetic   MIN MAX ABS ROUND CEIL FLOOR TRUNC CLAMP MOD SIGN
Money        MONEY ALLOCATE(amount, weights[]) ROUND_TO(amount, mode, unit)
Conditional  IF(cond, a, b)   CASE WHEN…THEN…ELSE…END   COALESCE
Bands        BANDS(value, [{from,to,result}…])          -- arbitrary count
Steps        STEP(value, block, step, mode)             -- ceil|floor|round
Slabs        SLAB(value, slab_table_code, as_of)        -- statutory slab walk
Dates        DATEDIFF(a,b,unit) DATEADD ADD_MONTHS EOMONTH SOMONTH
             NTH_WEEKDAY(month, weekday, n) LAST_WEEKDAY DAY_OF_WEEK
             IS_WORKING_DAY IS_HOLIDAY IS_WEEKLY_OFF
             CLAMP_DAY_TO_MONTH(day, month)             -- 31 → 28/29/30
Service      YEARS_OF_SERVICE MONTHS_OF_SERVICE COMPLETED_YEARS
Calendar     WORKING_DAYS(from,to,scope) CALENDAR_DAYS PAYABLE_DAYS
Attendance   ATTENDANCE_HOURS OT_HOURS LEAVE_UNITS(type)
Text         UPPER LOWER TRIM CONCAT LEFT RIGHT
Lists        COUNT SUM AVG ANY ALL NONE
```

`ROUND` requires an explicit mode. There is no default rounding anywhere in
SmartPRS 3.

### S5.5 Aggregation primitives — the capabilities SmartPRS 2 lacks

These exist because the as-is analysis found each behaviour hard-coded, and each
is a routine customer request:

```
agg.count(event, window, predicate)
      -- "late arrivals over 10 minutes, this payroll month"
      -- "instances in any rolling 30 days"
agg.sum(measure, window, predicate)
agg.nth(event, partition, order)
      -- "the Nth qualifying punch for this employee, this day, this feed"
agg.occurrence_index(event, window, predicate)
      -- "this is the 3rd occurrence" → drives per-occurrence penalties
agg.collapse_repeats(event, within_seconds)
      -- the configurable double-tap window
agg.streak(condition, direction)
      -- consecutive absent days, consecutive worked days
agg.balance(ledger, as_of)
      -- leave, comp-off, loan, advance balances from their ledgers
```

`window` is one of: `day`, `week(start_day)`, `payroll_period`, `calendar_month`,
`quarter`, `financial_year`, `rolling(n, unit)`, `since(date)`, `service_year`.

### S5.6 Determinism and sandboxing

- Parsing and evaluation are pure. No clock access except the injected
  evaluation date. No randomness.
- Node budget, depth limit, evaluation timeout, and a regex step limit.
- Every function is total: it returns a typed error value rather than throwing,
  and an error value propagates to a **rule evaluation failure**, which is
  surfaced to the operator — never silently swallowed. (SmartPRS 2 destroys 50.5%
  of its caught exceptions; SmartPRS 3 must have no silent catch in a domain
  path, enforced by static analysis.)

---

## S6. The Rule Engine

```
rule
  code, module, name, business_description,
  scope, priority, effective_from, effective_to, version, status,
  when          SPX Boolean expression
  then          ordered list of typed Actions
  except        optional SPX Boolean; if true the rule does not apply
  on_conflict   first_match | all_match | highest_priority_only
  requires_approval, audit_metadata
```

**Actions are declarative and typed** — the engine never executes arbitrary
behaviour. The action catalogue is a closed, versioned set owned by each module,
for example:

```
Attendance   SET_STATUS(code) · APPLY_DAY_PENALTY(fraction) · APPLY_LOP(days|hours)
             · CREATE_OT(minutes, multiplier_ref) · CREATE_COMP_OFF(units, expiry_ref)
             · OFFSET_SHORTFALL(minutes) · FLAG_FOR_REVIEW(reason) · NOTIFY(event)
Leave        DEDUCT(type, units) · CONVERT_TO(type) · REQUIRE_DOCUMENT(type)
             · BLOCK(reason) · ROUTE_TO_WORKFLOW(code)
Payroll      ADD_COMPONENT(code, amount_expr) · SUPPRESS_COMPONENT(code)
             · SET_BASIS(component, basis) · CAP(component, amount_expr)
             · CREATE_RECOVERY(type, amount_expr, schedule_ref)
Compliance   RAISE_ITEM(type, due_expr) · SET_RISK(level) · GATE_PAYMENT(reason)
Platform     REQUIRE_APPROVAL(workflow_code) · WRITE_AUDIT(reason) · NOTIFY(event)
```

**Evaluation contract.** Given (subject, date, context), the engine returns an
ordered list of `AppliedAction` plus a `DecisionTrace`. It never mutates state.
The calling domain service applies the actions inside its own transaction. This
keeps rules testable in isolation and makes the simulator trivial.

**Required tooling** (each is a Section 7 requirement and a deliverable):

- **Visual rule builder** — condition tree with dimension pickers.
- **Advanced expression view** — the SPX source, with autocomplete over the
  symbol table.
- **Simulator** — run any rule set against a real or synthetic employee and date
  and see the full trace.
- **Conflict detector** — at publish time, find rules with identical specificity
  and priority whose conditions can both be true; block publication.
- **Impact preview** — how many employees does this rule set change, and how.
- **Regression harness** — every rule may carry stored test cases (input, expected
  outcome). Publishing runs them.

---

## S7. The Formula Engine

Formulas are SPX expressions typed to a result (usually `Money`, `Days` or
`Number`), attached to a salary component, a statutory head, an entitlement, an
OT rate, a commission slab or an encashment.

- **Dependency graph** built at publish time. Cycles are rejected with the cycle
  named. Evaluation is topological.
- **Money discipline.** All money arithmetic in integer paise. Division is exact
  rational until an explicit `ROUND`. Every component declares its rounding mode
  and unit. Splits use `ALLOCATE`, which distributes residue to the last element
  so the parts always sum to the whole.
- **Effective-dated and versioned** like every other artefact.
- **Testable** against named sample employees before publish, with expected values
  asserted.
- **Traced.** The result of every formula carries the substituted values and the
  intermediate steps, which is what the payslip's "How was this calculated?"
  renders.

Worked example of the trace contract:

```
HRA  = BASIC % 40
     = ₹15,000.00 × 40%
     = ₹6,000.00
     formula: HRA v3 (published 01-Apr-2026, effective 01-Apr-2026 →)
     inputs:  BASIC = ₹15,000.00 (structure "Telangana Collections" v7)
     rounding: 2dp, half-up
```

---

## S8. The Workflow Engine

One engine. Zero module-specific approval code anywhere in SmartPRS 3.

**Node types:** `submit` · `approval(role | named user | dynamic manager at level N
| group)` · `condition_branch(SPX)` · `parallel_all` · `parallel_any` ·
`quorum(n of m)` · `auto_approve(SPX)` · `notify` · `escalate` · `return_for_edit`
· `reject` · `cancel` · `finalise`.

**Per-node configuration:** SLA, reminder schedule, escalation target, delegate,
backup approver, reassignment rights, required comment, required attachment,
amount-based routing, and a self-approval prohibition.

**Instance semantics — the properties SmartPRS 2 lacks:**

- A decided step is **immutable**. Re-deciding is impossible, not merely
  discouraged. (SmartPRS 2 re-fires transfer orders and increment letters on
  re-approval.)
- Every transition writes an audit row with actor, timestamp, IP, decision,
  comment and the entity snapshot hash.
- SLA breaches emit events; escalation is a scheduled job, not a human's memory.
- **Maker-checker** is a workflow shape, applied to: payroll approval, bank
  detail changes, salary changes, statutory configuration, rule publication,
  workflow publication, role changes, employee deletion, loan sanction, and full
  and final settlement.
- Workflows are versioned; an in-flight instance completes on the version it
  started on.

---

## S9. The Entity Schema Engine (metadata)

This is the mechanism that makes "add a LinkedIn Profile field" a configuration
task. It applies to Employee first, then Candidate, Document, Asset, Request and
any customer-defined entity.

```
entity_definitions      entity_key, label, is_system
field_definitions       tenant_id, entity_key, field_key, storage_kind,
                        data_type, label, help_text, group, display_order,
                        default_expr, is_system, pii_class, encryption,
                        masking, retention_class,
                        value_set_ref, validation_expr, uniqueness_scope,
                        effective_from, effective_to, version
field_surface_rules     field_id, surface, role, visibility(hidden|read|write),
                        required(never|create|always|conditional SPX)
value_sets              set_key, code, label, sort, active, effective range
entity_field_values     tenant_id, entity_id, field_id,
                        v_text, v_number, v_decimal, v_date, v_datetime,
                        v_bool, v_json   (one populated, typed, indexed)
```

**Surfaces are enumerated, and there is exactly one definition serving all of
them:** employee directory · employee edit · self-onboarding · bulk import ·
bulk export · ESS · MSS · mobile · public API · recruitment conversion · letters
and templates · reports · payslip.

**Storage strategy — SMARTPRS 3 ARCHITECTURE RECOMMENDATION.**
Core identity and hot-query attributes are real, migration-owned columns.
Everything else is a typed row in `entity_field_values`. A field that becomes
query-hot may be **promoted** to a generated column by an Ametecs-authored
migration — a controlled release activity, never a runtime `ALTER`. This
satisfies Law 2 while keeping filtering and sorting fast.

**One write service.** `EntityWriteService` is the only path that writes an
employee. It performs, in order: authorisation → field-level surface rules →
type coercion → validation → uniqueness (database-constrained, not a racy
pre-check) → sanitisation → effective-dated assignment handling → persistence →
field-level audit → domain events. Every surface calls it.

**PATCH semantics, always.** Only supplied fields change. Clearing a field is an
explicit operation. SmartPRS 2's employee save is a full-record replace that
nulls every omitted key — a defect that would be catastrophic behind a public API.

---

## S10. Attendance data architecture

Four layers, each immutable to the one below it. This is the direct answer to
Section 17 of the brief.

```
1. punch_events           RAW. Append-only. Never updated, never deleted.
                          Stores the payload exactly as received plus a hash,
                          the adapter and version, the receipt timestamp, and
                          the timezone assumption applied.
2. punch_normalised       Adapter output: canonical punch (employee, instant,
                          local time + zone, direction, verification method,
                          device, source, external event id). A correction
                          never edits this; it adds an overlay.
3. attendance_days        DERIVED, per employee per attendance-day. Carries the
                          resolved shift, the statuses, the minutes, the rule
                          versions applied, and a recalculation generation
                          number. Supersedable.
4. period_settlement      The payroll-facing frozen view of a period: payable
                          days, LOP, OT, comp-off earned/availed, leave units.
                          Frozen when the period is locked; re-openable only
                          through an audited workflow.
```

- **Corrections are overlays** with their own validity and approval, so raw
  evidence and the corrected view coexist and either can be displayed.
- **Recalculation is a first-class, idempotent command** — `recalculate(scope,
  date range)` — with a preview diff, and it re-derives from layer 2 plus the
  rules *effective on each date*.
- **Deduplication quarantines; it never deletes.**
- The attendance day is derived by a **configurable rule**, not the punch's
  calendar date, so a 22:00–06:00 shift is one day and not two.
- All late/early/worked arithmetic uses **absolute instants anchored to shift
  start**, never minutes-since-midnight.

### S10.1 The device adapter contract

```
interface DeviceAdapter {
    capabilities(): Capability[]      // pull|push|command|enrol|realtime
    connect(config): ConnectionResult
    test(config): Diagnostics
    fetch(window): RawEvent[]         // pull adapters
    parse(raw): CanonicalPunch[]
    identity(): IdentityStrategy      // ordered, candidate-major
    timeAssumption(): TimePolicy      // naive-local | offset-authoritative | named-zone
    health(): DeviceHealth
}
```

A new device make or model requires **a new adapter, never a change to the
attendance engine.** Adapters are also expressible as a validated **connector
recipe** (declarative configuration) for REST/SOAP/SQL/CSV/SFTP sources, so many
new integrations need no PHP at all. Vendor-specific status bytes, field aliases,
date formats and direction tokens are all adapter data.

**Canonical punch** (the contract the Smart Biometric Bridge and SmartEPT write
to):

```
external_event_id · tenant_id · device_id · device_serial · device_user_id
employee_code · employee_id · punch_datetime_local · timezone · direction
verification_method · source · raw_payload_hash · received_at
adapter · adapter_version
```

**Trust.** Tenant is derived from an authenticated credential, never from a
device-supplied serial. `(tenant, serial)` is unique. Unknown devices are
quarantined by default. Punches are persisted before acknowledgement, and the
acknowledgement carries a **per-punch verdict**. Nothing is ever silently
dropped: every rejected line is counted and reportable.

**Boundary with the Smart Biometric Bridge (Section 86).** SBB owns device
transport, local buffering, retry and forwarding. SmartPRS owns identity
mapping, shift resolution, rules and payroll. Neither reaches into the other's
tables; the contract is the canonical punch API and a versioned webhook.

---

## S11. Payroll architecture

A deterministic, staged, restartable pipeline. Each stage is pure with respect to
its inputs, emits a trace, and can be inspected in isolation.

```
resolve employees eligible for this run
  → resolve period (from the effective-dated cycle definition)
  → snapshot inputs      ← THE CRITICAL STEP
  → resolve salary structure version
  → attendance settlement
  → leave settlement
  → weekly-off / holiday settlement
  → LOP and proration
  → fixed earnings
  → variable earnings
  → overtime
  → comp-off encashment
  → commission
  → incentive
  → reimbursement
  → arrears
  → statutory deductions (from the resolved rule pack version)
  → income tax
  → loans and advances
  → other recoveries
  → net pay
  → employer contributions
  → CTC reconciliation
  → payslip generation
```

**The input snapshot** records, for the run: the period definition version, the
statutory pack version, every structure and formula version, every attendance and
leave rule version, and the employee's CTC and org assignments *as at the period*.
A re-run reproduces the original result exactly. A re-run with changed inputs
**warns explicitly** which inputs changed. This is the mechanism that makes
Section 6's requirement — August payroll keeps using Late Policy V1 after V2 is
published — structurally true rather than a matter of discipline.

**Run model.** Runs are versioned, never destroyed. Multiple lots per
company-period are supported. Off-cycle, supplementary and arrears-only runs are
first-class. States and transitions are configuration. Regeneration creates a new
run version preserving prior approvals, remarks and line statuses.

**Preview before draft.** The run cannot proceed past preview with unresolved
exceptions: missing bank data, PAN, UAN; missing attendance; missing structure;
negative net; minimum-wage breach; unmapped statutory policy; unapproved variable
input; duplicate run; loan inconsistency; invalid tax data.

**Reconciliation assertion.** Every run asserts, exactly, that the sum of payslip
lines equals the run totals and that each employee's earnings minus deductions
equals net. A failure aborts the run. SmartPRS 2 has no such check.

---

## S12. The India Statutory Rule Pack

Statutory content is **not application code and not tenant configuration.** It is
a third, separately versioned, cryptographically signed artefact published by
Ametecs.

```
rule_pack
  pack_id, jurisdiction (IN | IN-TG | IN-MH | …), statute, pack_version,
  effective_from, effective_to, published_at, signature, public_key_id,
  content: {
    rule_code, calculation (SPX), eligibility (SPX), thresholds, rates,
    slabs, forms, due_dates, report_mappings, source_reference, notes
  }
```

**Layering, most general to most specific:**

```
Central statutory baseline
  + State / UT rule
  + Establishment applicability
  + Company enhanced policy (may improve on the statutory minimum, never reduce it)
```

The configuration validator **blocks or warns** — by declared rule severity —
when a company policy would fall below the statutory baseline, and records an
explicit compliance exception where one is permitted.

**Distribution.** Ametecs signs a pack with a private key that **never leaves
Ametecs**. Customer installations carry only the public verification key.
Delivery: download → verify signature → validate → **preview the diff and its
impact** → administrator accepts (or an auto-accept policy applies) → activates
on its effective date. A Professional Tax slab change therefore ships as content,
not as an application release. This replaces SmartPRS 2's situation, where a slab
lives in a PHP `private const` and reaching 200 on-premise customers means 200
manual application upgrades.

**Statutory bases are declared per component by flag, never by name.** Each
earning declares whether it enters the PF wage, the ESI wage, the PT gross, the
bonus wage, the gratuity wage, the OT wage, the minimum-wage comparison and the
taxable base. No statutory logic anywhere may inspect a component's *name*.

> **Legal-values discipline.** No rate, ceiling, slab, threshold or due date is
> asserted in this blueprint as legally correct. Every such value is either
> (a) recorded as "the value SmartPRS 2's code currently uses", or (b) left as a
> field to be populated in a rule pack by Ametecs' compliance owner and verified
> against the primary statutory source before publication. Rule packs carry a
> `source_reference` field precisely so each value is traceable to its authority.
> **PRODUCT OWNER DECISION REQUIRED — D-03: who is the named compliance owner
> who signs off a rule pack before it is signed and shipped?**

---

## S13. Security model

**Permission and scope are orthogonal.** SmartPRS 2 conflates them and enforces
neither.

```
permission   verb.resource            e.g. payroll.approve.finance
                                            employee.document.view
                                            rules.publish
data_scope   self | direct_reports | team | department | branch | company
             | legal_entity | tenant | platform  (+ explicit unit lists)
role         a named bundle of permissions, tenant-defined
assignment   user → role → data_scope    (a user may hold several)
```

Every protected operation is bound to a policy that answers both questions: *may
this actor perform this verb?* and *on which records?* Both are evaluated
server-side. Both fail closed.

**Non-negotiables:**

- No route without an explicit policy binding — asserted by a CI sweep.
- Employee self-service resolves the subject from the session, never from the
  request. There is no dropdown of other employees on a self-service transaction.
- Sensitive fields (Aadhaar, PAN, bank, salary, tax, biometric configuration,
  credentials) are encrypted at the application layer, masked by role, and their
  access is audited.
- Private documents are served through authorised, signed, expiring URLs. Nothing
  private ever sits on a publicly readable disk path.
- Credentials that must be verified are encrypted; credentials that need only be
  compared are hashed. A decryption failure raises an operator alert and never
  falls back to treating ciphertext as plaintext.
- MFA available for all users, **mandatory for privileged roles**. Lockout,
  throttling, password policy, session lifetime and cookie flags are configuration
  with secure defaults that the installer cannot skip.
- No default credential ships in any seeder, installer or release, in any
  environment.

---

## S14. The audit ledger

Append-only, hash-chained per tenant, with a daily signed anchor so a chain
break is detectable and attributable to a date.

```
who · when · ip · device · tenant · action · entity_type · entity_id
old_value · new_value · reason · workflow_instance · rule_version
request_id · correlation_id · prev_hash · hash
```

**Every** write path goes through the audit service — there is no direct insert
into the ledger from anywhere else, because that is exactly how SmartPRS 2's
chain is already broken in four places. Field-level before/after values are
mandatory for employee, salary, bank, role, statutory and rule changes.

---

## S15. Explainability

A single service, `Explain(entity, field)`, returns the trace for any derived
value. It is exposed in the UI wherever a derived number is shown.

Attendance example:

```
Date            12-Aug-2026
Shift           General (09:30–18:30), resolved from: roster → shift group
Scheduled IN    09:30      Actual IN 11:42
Late            132 minutes (computed from shift start instant)
Rule            HYD-LATE-003 v4  (effective 01-Apr-2026 →)
Condition       late_minutes > 120
Outcome         Half Day
HR override     none
Payroll impact  0.5 payable day
```

Payslip example: every line offers "How was this calculated?", showing the
component definition version, the formula, the substituted values, the rounding
mode, and the statutory pack version where applicable.

---

## S16. Data platform conventions

- **Naming.** `snake_case`, plural tables, `_id` foreign keys, `_at` timestamps,
  `_on` dates, `is_`/`has_` booleans. One tenancy column name — `tenant_id` —
  everywhere. SmartPRS 2 uses four different names for the same concept.
- **Referential integrity.** Foreign keys on every relationship, with declared
  `ON DELETE` behaviour. SmartPRS 2 has zero foreign keys on business tables and
  ships an admin endpoint whose job is sweeping up the resulting orphans.
- **Uniqueness.** Every business-unique combination has a database constraint,
  and `tenant_id` participates in it.
- **Enums.** No database `ENUM` types. Status vocabularies are reference tables
  with codes, so adding a value is data. SmartPRS 2 has twelve live cases where
  code writes a value the column's `ENUM` forbids.
- **Money.** `DECIMAL(18,4)` at rest; integer paise in memory; currency carried
  explicitly.
- **Dates.** Dates are `DATE`, instants are `DATETIME` in UTC with an accompanying
  business timezone and business date. Never `VARCHAR`. SmartPRS 2 turns
  `dra_expiry` and `pcc_deadline` into `VARCHAR` on installs where a runtime
  helper wins the race.
- **Deletion.** Business and evidentiary records are never hard-deleted. Every
  entity carries a declared **retention class**; archival, anonymisation and
  purge are scheduled, audited operations governed by that class.
- **No table serves two features.** SmartPRS 2's `attendance_pending` serves two
  unrelated features with disjoint schemas, and whichever runs first wins.

---

## S17. API and integration

- `/api/v3/*`, OpenAPI-generated, versioned, additive within a major version.
- OAuth2 client credentials and scoped API keys; scopes actually gate.
- Idempotency keys on every unsafe method; cursor pagination on every collection;
  a consistent error envelope with machine-readable codes.
- **Webhooks** signed with HMAC over the raw body **plus a timestamp and nonce**,
  with a freshness window and replay rejection; delivery log, retry with backoff,
  and a dead-letter queue.
- Egress from connectors is restricted by scheme and host allow-list, blocking
  loopback, link-local and private ranges unless a LAN connector explicitly
  permits them.
- **Product boundaries.** SmartEPT, SmartDCM and the Smart Biometric Bridge
  integrate through versioned API and webhook contracts only. No shared tables
  between products, ever.

---

## S18. Deployment parity

One artefact. SaaS, private cloud and on-premise differ only in infrastructure
configuration — never in business logic. There is no `if (on_prem)` in a domain
path.

- **Installer** validates prerequisites, database, cache, queue, storage, HTTPS,
  mail, licence; runs migrations transactionally; creates the first
  administrator interactively (no default credential); registers the scheduler
  and worker; and finishes with a health check that must pass.
- **Application updates** are signed with an asymmetric key. The private signing
  key never leaves Ametecs; installations carry only the public key. The update
  flow is: verify signature → compatibility check → **database backup** → code
  backup → maintenance mode → migrate → update code → rebuild caches → restart
  workers → health check → exit maintenance, with **database and code rollback**
  on failure, all audited.
- **Rule-pack updates are separate from application updates** and require no
  deployment.
- **Backups** cover database, documents, configuration, rules, templates and
  audit; are encrypted; go off-server; have retention; and — critically — have a
  **restore path that is tested on a schedule and whose last successful test date
  is visible in the UI.** SmartPRS 2 has no restore path of any kind.
- **Operations dashboard** shows scheduler heartbeat, queue depth and oldest
  pending job, failed jobs, mail queue, webhook queue, biometric sync health,
  backup status and last restore test — with alerting. No silent queue failure.

---

## S19. What SmartPRS 2 does well — preserve these

The rebuild must not lose:

1. **The DRA compliance-to-money gate** (`off` / `warn` / `block`, per tenant, at
   three payment points). It is the product's strongest differentiator and the
   reference case for the rule engine.
2. **Depth of Indian statutory coverage attempted** — PF, ESI, PT, LWF, TDS,
   bonus, gratuity, 24Q, ECR, bank payout packs. The implementations are
   defective; the *domain coverage* is real and hard-won.
3. **The collections/recovery vertical understanding** — DRA, PCC, BGV, agent
   authorisation, code of conduct, off-roll agents, the RBI audit pack. This is
   genuine domain capital.
4. **The penny-safe balance-residue allocation** in the salary structure —
   currently the one correct piece of money-splitting in the codebase, and the
   pattern SmartPRS 3 should generalise.
5. **`PeriodResolver`** — the best-tested and cleanest payroll component, wired to
   nothing. Its logic should be carried forward.
6. **The effective-dated design of `statutory_configs`** — the right model, built
   and then bypassed. SmartPRS 3 should adopt it universally.
7. **Breadth of workflow understanding** — the module inventory reflects real
   operational knowledge of how Indian collection agencies and BPOs run.
8. **The self-onboarding journey concept** and the guided demo tour — good product
   instincts, poorly implemented.

---

## S20. Conventions for the blueprint itself

Every part of the blueprint must:

- State requirements as **numbered, testable statements**.
- Mark open business questions **PRODUCT OWNER DECISION REQUIRED** and give a
  recommendation with trade-offs.
- Mark anything beyond SmartPRS 2's behaviour **SMARTPRS 3 ARCHITECTURE
  RECOMMENDATION**.
- Trace every requirement to its origin: the master brief section, the SmartPRS 2
  defect, or both.
- Never assert a legal or statutory value as fact.
- Specify tables to the level demanded by Section 123: table, purpose, columns,
  types, nullability, default, PK, FK, unique, indexes, tenant scope, history,
  soft delete, audit, PII classification.
- Specify modules to the level demanded by Section 125: objective, actors,
  permissions, screens, fields, master data, configuration, rules, workflows,
  calculations, database, API, events, notifications, imports, exports, reports,
  audit, security, dependencies, failure states, edge cases, tests, expected
  output. No one-paragraph module specification is acceptable.
- Demonstrate compliance with all seven laws in S1.
