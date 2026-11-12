# PART VIII — FORMULA ENGINE

Status: DRAFT FOR PRODUCT OWNER APPROVAL
Conforms to: SPINE.md — S1 (all seven laws), S3 (scope and precedence), S4
(effective dating and publish), S5 (**SPX**), S7 (this part), S11 (payroll
pipeline), S12 (statutory rule pack), S15 (explainability), S16 (data
conventions).
Shares one language with: PART VII — RULE ENGINE §5. **SPX is one language.**
This part adds symbols and typing rules to the shared registry; it forks
nothing, and every addition is marked as an extension with its justification.

---

## 1. Purpose

This part specifies the engine that answers **"what amount?"** — the component
of SmartPRS 3 that turns a compensation record, an attendance settlement and a
statutory pack into a number, and that can prove afterwards how it got there.

It decides three things:

1. **The calculation contract.** What a formula is, what it may read, what it
   returns, when it is rounded, and how one formula composes with another.
2. **Money discipline.** How Law 7 — money is exact — becomes an implementation:
   integer paise, exact rational intermediates, one declared rounding boundary
   per component, and a residue allocation that provably sums to the whole.
3. **Defensibility.** How a payslip line produced in August 2026 can be
   re-explained, unchanged, in November 2029, with the versions, the substituted
   values and the arithmetic shown.

It does **not** decide who is eligible for a component (Part VII), who approves
the payroll (Part IX), what the statutory rate is (spine S12 rule packs), or how
the payroll run is staged (spine S11).

---

## 2. What SmartPRS 2 does today, and why it must change

SmartPRS 2 has no formula engine. It has calculations, distributed across
thirty locations, written in PHP, using floats, with rounding decided by
whichever function happened to compute the value. The evidence below is the
justification for every design choice in §3 onward; each item is carried through
from `A-payroll.md` and `F-statutory-compliance.md` with its citation.

| # | What SmartPRS 2 does | Citation | Consequence | Answered by |
|---|---|---|---|---|
| 1 | **All money arithmetic is PHP float.** `composer.json` requires no `ext-bcmath`; grep finds zero `bcadd`/`bcmul`/integer-paise anywhere. Every signature in the money path is typed `float` | `A-payroll.md` §5.3 Q2; `computeSlip(float $ctc):2094`, `statutory(float $gross, float $pfWage):2059`, `ptForGross(float $gross):1925` | The documented invariant *"All monetary values use decimal columns + integer-safe math. Never float."* (`config/smartprs.php:128`, `README.md:73`) is **false in its second half** | §5, §6 |
| 2 | **A float comparison decides whether a loan instalment is deducted** | `PayrollGenController.php:1357` | An EMI can be skipped or taken on a representation artefact | §6.1, R-VIII-020 |
| 3 | A **loose float equality** guards whether a commission line is written: `if ((float) $camt == 0.0)` | `:2632` | A ₹0.004 commission silently vanishes | §6.1 |
| 4 | The code itself uses **explicit `0.005` epsilons** to work around float imprecision | `SchemeController:290, 821, 832` | The codebase acknowledges the problem and treats the symptom | §6.1 |
| 5 | **Five divisors are in simultaneous use in one payroll month** — working days, calendar days, flat 30, flat 26, and 30.44 — and only the base-salary path honours the tenant's chosen basis | `A-payroll.md` §4.2, §5.3 Q1 | Overtime always divides by 26; gratuity always by 26; bonus always by 30.44; arrears always prorate the first month by calendar days regardless of `lop_basis` | §11 (F-LOP-*), §15 |
| 6 | **Absence is measured in working days and valued in calendar days** with no type barrier: `$absentDays = $empWorking − $paidDays` then `($avail − $absentDays) / $daysInMonth` | `PayrollGenController.php:1268-1284`; `A-payroll.md` §4.3 | Two absent working days in a 31-day month cost 2/31 of gross, not 2/27 — a systematic under-deduction that nobody can see because nothing names the units | §5.1, §5.3 |
| 7 | **`pct_ctc` is accepted and silently computed as percent-of-gross** | `AppDataController.php:2225-2237`; `A-payroll.md` §5.2 item 2 | Any structure where CTC ≠ gross × 12 (i.e. any structure carrying employer contributions) produces a wrong Basic every month | §11 FL-02 |
| 8 | **Section 87A relief is a hard cliff inside `newRegimeTax()`, and marginal relief lives in the caller** — so one caller applies it and two do not | `AppDataController.php:1752-1766` vs `:1836-1840`; `statutoryPdf():1732`, `tdsReturnsHistory():1779`; `F-statutory-compliance.md` §4.6 | At taxable ₹12,00,001 the payslip deducts ₹0.09/month and Form 24Q reports ₹62,400. **The return contradicts the payslip** | §11 FL-18, §15.3 |
| 9 | **PF, ESI and TDS are re-implemented in browser JavaScript** for the register screens, ignoring `salary_components`, using `Math.round` where the server uses `ceil`, ignoring the ESI period lock, ignoring LOP, and carrying a hard-coded duplicate of the entire server rate table | `AppController.php:12081-12085`, `:12093-12110`, `:11161`; `A-payroll.md` §5.3 Q8 items 2–4 | **The PF/ESIC challan register disagrees with the payslips it claims to summarise** | R-VIII-004 |
| 10 | **Four gratuity implementations** disagree on rounding (whole rupee vs 2 dp), on the year count (`diffInDays/365.25` then `floor` vs `diffInYears`) and on the Basic they use | `StatutoryController:54-57`, `ReportController:264-275`, `payslipEmployerCost():1543`; `A-payroll.md` §4.13 | A 46-paise difference and a different eligibility answer at the five-year boundary, between two reports for the same employee | §11 FL-16 |
| 11 | **`min(round(15000 × 8.33/100, 2), 1250.0)` never binds** — `round(…)` yields 1249.50, so EPS is ₹1,249.50 and the declared cap is dead code | `AppDataController.php:2071`; DEF-P08 | The rounding decision is emergent from `round(x,2)` rather than declared | §6.3, §11 FL-21 |
| 12 | **ESI rounds `ceil` in the engine and `round(…,2)` in the challan PDF** | `:2077-2078` vs `:1688-1689`; DEFECT-F-05 | Two numbers for one statutory contribution | §6.3 |
| 13 | Component semantics come from **names**: Basic by `str_contains(code.' '.name,'basic')`, DA by regex, reimbursement by the substring `reimburs`, the ESI half-year lock by `stripos($k,'esi')` | `:2173`, `:2269`, `:2181-2188`, `:2036` | Renaming a component silently changes PF eligibility and disables a statutory lock | R-VIII-003 |
| 14 | The **only correct money split in the codebase** is the balance-component residue allocation | `AppDataController.php:2247-2254`; spine S19.4 | The right pattern exists once and is generalised nowhere | §6.4 |
| 15 | **No reconciliation exists** that `SUM(payslips.net) = payroll_runs.net_total`; the run total is a running float sum over every employee | `:1427-1429`, `:1475-1477`; DEF-P17 | Nothing detects a drift | §6.6, R-VIII-025 |
| 16 | **`round(x, 2)` is applied to already-rounded values**, and `total_ded` is `round(sum-of-rounded, 2)` | `:2122`, `:2310` | Rounding is applied where the code happens to sit, not where the business declares | §6.3 |
| 17 | **`employees.ctc` is overwritten in place** by an increment; `salary_components` has **no effective-dating column at all** | `IncrementController:342`; `A-payroll.md` §5.3 Q5 | Regenerating a past draft re-prices it at today's CTC and today's structure, silently | §3.4, spine S4 |
| 18 | **Paid Days and LOP are recovered from the payslip's free-text note by regex** `/Paid\s+([\d.]+)\s+of\s+(\d+)\s+days/i`, printing `daysInMonth / 0` when the sentence is absent | `AppDataController.php:1278-1282`, `calcNote():537-540`; DEF-P16 | The only explanation mechanism is prose, and the payslip parses its own prose back | §10 |
| 19 | The **in-app calculation guide is hand-written prose** and four of its statements already contradict the engine | `CalcGuideController`; `A-payroll.md` §8.2 | A client-facing promise drifts from the code | R-VIII-062 |

> **The pattern.** Every one of the nineteen items is the same failure in a
> different costume: **the arithmetic is not a first-class, declared, versioned,
> typed artefact**, so it multiplies, drifts, and cannot be explained. The
> formula engine exists to make arithmetic an artefact.

---

## 3. Scope and boundary — what a formula is, and what it is not

### 3.1 The division of labour (restating Part VII §3.1, from this side)

| Engine | Answers | Returns | May mutate state? |
|---|---|---|---|
| Rule engine (VII) | *What outcome applies?* | ordered `AppliedAction[]` + `DecisionTrace` | No |
| **Formula engine (this part)** | ***What amount?*** | **one typed value + `CalculationTrace`** | **No** |
| Workflow engine (IX) | *Who must agree?* | instance + transitions | Yes, its own |

**R-VIII-001** A formula evaluation is a **pure function** of its prepared
context. It performs no I/O, issues no query, reads no clock, and writes
nothing. Given the same context digest and the same formula version, it returns
the same value and the same trace, forever. *This is what makes a recomputation
of March, run in November, produce March's answer — which SmartPRS 2 cannot do
because `PayrollGenController::compute():1094` calls `SettingsController::rates($tid)`
with no date argument at all.*

**R-VIII-002** A formula MUST NOT contain business eligibility beyond `IF`/`CASE`
arithmetic on its own inputs (Part VII R-VII-003). "Does this component apply to
this employee?" is a rule emitting `ADD_COMPONENT` / `SUPPRESS_COMPONENT`, never
a formula that returns zero. *Because SmartPRS 2 hides probation suppression of
PF, PT and TDS inside the calculator at `computeSlip():2114-2117` and duplicates
it at `computeSlipFromComponents():2284-2287`, where no configuration screen can
reach it.*

**R-VIII-003** A formula MUST address every component, leave type, statutory
head, ledger and policy **by code**, never by name. `CONTAINS` may not be
applied to a `Code` (Part VII §5.6). Renaming a component changes nothing except
what is printed. *Directly closing items 13 of §2.*

**R-VIII-004** There is **exactly one implementation of each calculation**, and
it is a formula artefact. No calculation may exist in a controller, a view, a
report, a PDF generator, a register, an export, a simulator or in browser
JavaScript. Every surface calls `FormulaEngine::evaluate()` and renders what it
returns. *A CI conformance sweep fails the build on arithmetic on a money-typed
value outside `Domain\Money` and the evaluator. This is the single largest
structural change from SmartPRS 2, where the payroll engine, the challan PDF,
the SPA, the extracted unit test and the in-app help prose each hold their own
copy of PF, ESI, PT and TDS, and they disagree (`A-payroll.md` §5.3 Q8;
`F-statutory-compliance.md` §10 item 19).*

### 3.2 Where a formula is invoked from

Every invocation site is a declared **calculation point** — the formula-engine
analogue of Part VII's decision point, and the same mechanism of safety: it
fixes the symbol table, the required context slices, the permitted result types
and the rounding governance.

| Calculation point | Called by | Subject | Result type | Context slices required |
|---|---|---|---|---|
| `comp.earning` | Payroll pipeline, fixed earnings stage | employee-period-component | `Money` | employee, period, compensation, structure |
| `comp.deduction` | Payroll pipeline, other-deductions stage | employee-period-component | `Money` | + settlement |
| `comp.employer_contribution` | Employer contributions stage | employee-period-component | `Money` | + statutory |
| `comp.balance` | Structure residue resolution | employee-period-structure | `List<Money>` | structure |
| `statutory.head` | Statutory deduction stage | employee-period-head | `Money` | + statutory pack, ytd, period locks |
| `proration.factor` | LOP and proration stage | employee-period | `Number` (0–1) or `Days` | settlement, period |
| `proration.lop_amount` | LOP stage | employee-period-component | `Money` | settlement, period, structure |
| `ot.rate` | Overtime stage | employee-period | `Rate<Money/Hours>` | compensation, period, shift |
| `ot.amount` | Overtime stage | employee-period | `Money` | + settlement |
| `leave.entitlement` | Leave accrual job | employee-leave_type-period | `Days` | employee, service, period, leave policy |
| `leave.encashment` | Encashment / F&F | employee-leave_type | `Money` | + ledger |
| `compoff.encashment` | Comp-off encashment | employee | `Money` | + ledger |
| `commission.payout` | Commission stage | employee-period-scheme | `Money` | + variable input |
| `incentive.payout` | Incentive stage | employee-period-scheme | `Money` | + settlement, variable input |
| `reimbursement.payable` | Reimbursement stage | employee-period-claim | `Money` | + ledger, policy |
| `loan.schedule` | Loan sanction / recompute | loan | `List<Money>` | loan terms |
| `loan.instalment` | Recovery stage | employee-period-loan | `Money` | + ledger |
| `arrear.amount` | Arrears stage | employee-target_period-component | `Money` | + prior-run snapshot |
| `terminal.gratuity` | F&F settlement | employee | `Money` | + statutory pack, service |
| `terminal.bonus` | Bonus register / payment | employee-financial_year | `Money` | + statutory pack, ytd |
| `net.floor_adjustment` | Net-pay stage | employee-period | `Money` | full slip so far |
| `ctc.reconciliation` | CTC reconciliation stage | employee-period | `Money` | full slip |

**R-VIII-005** Every formula belongs to exactly one calculation point. Adding a
calculation point is an Ametecs release activity: a seeded `calculation_points`
row, a declared symbol table, a declared result type, a declared slice list, a
caller in a domain service, and a conformance test asserting the caller neither
requests a slice the point does not declare nor accepts a result of another
type.

**R-VIII-006** A formula's result type is declared and **checked at publish**.
A `comp.earning` formula that type-checks to `Number` is a publish-time error,
not a runtime surprise. *Because SmartPRS 2's `calc_value` column is `VARCHAR`
on one install and `DECIMAL(12,2)` on another, so the formula string
`"0.4 * BASIC"` silently becomes the number `0.40` (`J-database.md` §6.1.10).*

### 3.3 What is explicitly not a formula

| Not a formula | Where it lives | Why |
|---|---|---|
| Component eligibility for an employee | Rule engine, `payroll.component.eligibility` | An outcome, not an amount |
| The order in which recoveries are applied | `recovery_order` configuration (spine S4 artefact) | A sequence, not an arithmetic |
| Which components enter the PF/ESI/PT/bonus/gratuity/OT/taxable base | Per-component boolean flags (spine S12) | A classification, not a calculation. **A formula reads the flags; it never decides them** |
| The statutory rate, ceiling, slab or due date | Signed rule pack (spine S12) | Third artefact class, Ametecs-signed |
| Approver identity or routing | Workflow engine (spine S8) | Routing |
| The period's start, end and cut-off | Pay-cycle artefact (spine S4) | Calendar definition |
| The rounding mode itself | The component/head **declaration**, not the expression | Rounding is a property of the value (§6.3) |

**R-VIII-007** A formula MAY NOT call `ROUND`, `CEIL`, `FLOOR` or `ROUND_TO` on
its own final result. The final rounding is applied by the engine from the
component's **declaration**, once, at the declared boundary. Rounding inside an
expression is permitted only where the business genuinely rounds an
intermediate (a slab input quantised to whole rupees, for example), and each
such call must carry an inline `--` comment naming why. *This is the direct
answer to SmartPRS 2, where `round(…, 2)` appears wherever the code happens to
sit — `round(sum of already-rounded values, 2)` at `:2122` and `:2310` — and
where the one place a cap was declared (`min(…, 1250.0)`) never binds because a
rounding upstream of it decided the answer first (DEF-P08).*

### 3.4 Effective dating, versioning and scope

A formula is a `config_artefact` of type `formula` with `config_versions` per
spine S4, resolved by `resolve('formula', code, scope, as_of_date)`.

**R-VIII-008** A published formula version is **immutable in every field**. A
change creates a new version; the prior version's `effective_to` closes to the
day before the new `effective_from`. *Because SmartPRS 2's structures and rates
are overwritten in place, so editing a rule silently rewrites last year's
payroll (`A-payroll.md` §5.3 Q5).*

**R-VIII-009** Resolution uses the **payroll period's date**, never `now()`.
The engine has no clock; `context.evaluation_date` is injected by the caller
(Part VII R-VII-019) and for a payroll run it is derived from the period, not
from the machine.

**R-VIII-010** Two formula versions of the same artefact whose scopes both match
an employee on a date and whose specificity **and** priority tie cause
**publication to be blocked** (spine S3.2 step 3), naming both versions and the
affected employee count. Ambiguity in an amount is never resolved by row order.
*SmartPRS 2 resolves component scope by row order (`resolveComponents():990-1012`).*

---

## 4. The formula model

### 4.1 Field-by-field specification

Fields marked (A) live on the artefact; all others on the version.

| Field | Type | Null | Meaning | Publish validation | Effect |
|---|---|---|---|---|---|
| `code` (A) | `varchar(64)` | No | Stable business identifier, e.g. `F-HRA-PCT-BASIC`. Appears in every trace and every payslip explanation | `^[A-Z][A-Z0-9_-]{2,63}$`; unique per `(tenant_id, calculation_point)` | Trace key; test fixtures and structure lines reference it |
| `calculation_point` (A) | `varchar(64)` FK | No | The invocation site (§3.2) | Must exist and be active | Fixes symbol table, slice list and permitted result type |
| `module` (A) | `varchar(32)` | No | `payroll`, `leave`, `attendance`, `statutory`, `platform` | Must equal the calculation point's module | Permission binding `formula.publish.<module>` |
| `name` | `varchar(160)` | No | Human label | 3–160 chars | UI, trace header |
| `business_description` | `text` | No | Why this formula exists, in the customer's words, and what the number means | ≥ 20 chars, **mandatory** | Printed in the trace, the impact preview and the generated calculation guide (R-VIII-062) |
| `result_type` | `code` | No | `Money` · `Days` · `Hours` · `Minutes` · `Number` · `Rate<Money/Days>` · `Rate<Money/Hours>` · `List<Money>` | Must be permitted by the calculation point; must equal the inferred type of `expression_src` | Type-checked, not coerced |
| `unit` | `code` | Cond. | For `Money`: ISO currency (`INR`). For `Days`/`Hours`/`Minutes`: the day-length policy code that gives the unit meaning. For `Number`: `ratio` · `percent` · `count` · `factor` | Mandatory when `result_type` is `Money`, a duration, or a `Rate` | Prevents unit confusion (§5.3) |
| `expression_src` | `text` | No | SPX source (Part VII §5.3 grammar) | Parses; type-checks to `result_type`; every symbol in the calculation point's table; within budgets (§13.4) | Documentation and re-compilation diffing |
| `expression_ast` | `mediumblob` | No | Compiled, canonicalised, immutable AST | Written by the compiler only | **The evaluated artefact.** A parser upgrade cannot change a published formula's behaviour (Part VII R-VII-009) |
| `scope_json` | `json` | No | Spine S3.1 scope over the 21 dimensions | Every key known; every value id resolvable in this tenant | Which employees this version governs, and its specificity weight |
| `priority` | `int` | No, `0` | Tie-break **within equal specificity only** | `-10000 … 10000` | Second-order ordering |
| `effective_from` | `date` | No | First date the version governs | Not before the artefact's first version | `as_of` resolution |
| `effective_to` | `date` | Yes | Last date, null = open | ≥ `effective_from`; no overlap within the same artefact **and scope** | Closed on supersede |
| `version_no` | `int` | No | Monotonic per artefact | `= max + 1` | Pinned into every trace and every payslip line |
| `status` | `code` | No | `draft \| testing \| pending_approval \| published \| superseded \| archived` | Legal transitions only | Only `published` is evaluated |
| `rounding_mode` | `code` | No | `half_up \| half_down \| half_even \| up \| down \| ceil \| floor \| none` | **Mandatory. There is no default rounding anywhere in SmartPRS 3** (spine S5.4). `none` permitted only when `formula_kind = intermediate` | §6.3 |
| `rounding_unit` | `decimal(18,4)` | No, `0.0100` | The quantum: `0.0100` (paise) · `1.0000` (whole rupee) · `0.2500` (quarter day) · `0.5000` (half day) | > 0 unless `rounding_mode = none`; must be expressible in the result's unit | §6.3 |
| `rounding_point` | `code` | No, `final` | `final` — round once, on the result. `none` — intermediate formula, never rounded | Only `final` permitted for a formula bound to a payslip line | §6.3, R-VIII-007 |
| `reference_value` | `code` | No, `rounded` | What a **dependent** formula reads: `rounded` (the published number) or `exact` (the pre-rounding rational) | `exact` requires `formula_kind = intermediate` | §6.2, D-VIII-01 |
| `formula_kind` | `code` | No, `component` | `component` (produces a payslip/ledger line) · `intermediate` (a named sub-expression, never a line) · `rate` (produces a `Rate`) | An `intermediate` may not be referenced by a structure line | Governs rounding and trace rendering |
| `declared_slices` | `json` array | No | The context slices this formula requires (§8.2) | Must be a subset of the calculation point's declared slices; **must contain every slice the AST actually reads** — the compiler derives the true set and rejects a mismatch | Assembly cost and safety |
| `depends_on_json` | `json` array | No | Resolved dependency set: `{kind: component|formula|statutory|policy|ledger|settlement, code, resolved_version_id}` | Derived by the compiler from the AST, never hand-written; acyclic (§7) | The dependency graph |
| `sample_tests` | child rows | No | ≥ 3 named sample-employee cases with asserted expected values | **Publication is refused with zero cases** (R-VIII-047) | Regression harness |
| `severity` | `code` | No, `normal` | `normal \| high_risk` | Forced `high_risk` when the formula feeds a statutory head, a net-pay line or an employer contribution | Diff + approval before publish (spine S4) |
| `requires_approval` | `bool` | No, derived | Maker-checker on publication | Forced `true` when `severity = high_risk` | Spine S8 |
| `source_ref` | `varchar(255)` | Yes | For migrated formulas: the SmartPRS 2 citation it replaces, e.g. `AppDataController.php:2102-2105` | — | Migration traceability; shown in the trace for seeded formulas |
| `checksum` | `char(64)` | No | SHA-256 over canonical AST + rounding declaration + scope + dates + dependency set | Recomputed on publish | Cache key, tamper evidence |
| `audit_metadata` | `json` | No | created/submitted/approved/published by and at; `reason`; `change_note` | `reason` mandatory when `high_risk` | Spine S14 |

**R-VIII-011** `expression_src` MUST NOT be empty and MUST NOT be a bare
literal for a `component`-kind formula. A constant amount is a `fixed`
component on the structure line, not a formula — so that "this is a fixed
₹1,600" is visible in the structure rather than buried in an expression.

**R-VIII-012** `depends_on_json` is **derived by the compiler**, never authored.
A formula whose author-declared dependencies differ from the compiler's derived
set is rejected. *Because a hand-maintained dependency list is a second source
of truth, and second sources of truth are what §2 is a catalogue of.*

### 4.2 Publish gates

A formula version moves `draft → testing → pending_approval → published` only
through the gates in §9. Summarised here for the model's completeness:

| Gate | Blocks publication when | §  |
|---|---|---|
| G1 Parse | Source does not parse, or exceeds the source/node/depth budget | 9.1 |
| G2 Symbols | A symbol is outside the calculation point's table, or a component/leave/statutory/policy code does not resolve in this tenant | 9.2 |
| G3 Types | Inferred type ≠ `result_type`; any operand pair outside the operation matrix (§5.4) | 9.3 |
| G4 Units | A unit mismatch the coercion table does not permit; a `Days`/`Hours` mix; a `Rate` multiplied by the wrong dimension | 9.4 |
| G5 Cycles | The dependency graph contains a cycle, per resolved scope | 9.5 |
| G6 Division | A division whose denominator is **reachably** zero and unguarded | 9.6 |
| G7 Growth | An unbounded recursive-by-scope reference, or a `BANDS`/`SLAB` table that is unsorted, overlapping, gapped or has no terminal band | 9.7 |
| G8 Slices | A declared slice missing, or a slice read but not declared | 9.8 |
| G9 Rounding | `rounding_mode` absent; `none` on a `component` formula; a `ROUND` on the final result (R-VIII-007) | 9.9 |
| G10 Tests | Fewer than 3 sample cases, any case failing, or a dependent artefact's pinned case broken by this change | 9.10 |
| G11 Scope | Overlap, gap, or a specificity+priority tie with another published version | 9.11 |
| G12 Approval | `requires_approval` and no second human has approved | 9.12 |

**R-VIII-013** All twelve gates run on every publication, in order, and the
stored outcome of each is written to `formula_publication_checks` (§14.5) so a
blocked publication is explainable and a passed one is evidenced.

---

## 5. The type system for calculation

### 5.1 Types, and how they relate to SPX's

SPX has one type system (spine S5.1, Part VII §5.4.1). This part does **not**
add base types. It adds three **refinement types** — types that are checked at
publish and erased at runtime — and one **parametric** type, all expressed over
existing bases:

| Type | Base | Definition | Why it exists |
|---|---|---|---|
| `Money` | base | integer minor units + ISO currency | Law 7 |
| `Days` | base | decimal, scale ≤ 4 | Leave/attendance charge unit |
| `Hours` | base | decimal, scale ≤ 4 | Worked/OT time |
| `Minutes` | base | integer | Instant arithmetic |
| `Number` | base | arbitrary-precision decimal, scale ≤ 9 | Never a float |
| `Date` | base | calendar date, no zone | |
| **`DayFraction`** | refinement of `Days` | `Days` constrained to `[0, 1]`, quantum declared by a day-part policy (`1`, `0.5`, `0.25`) | A day-part charge is a `Days` value that must not exceed one day. *SmartPRS 2 counts every leave day as exactly 1.0 (`paidLeaveDays():1077`) and half-day leave is not expressible* |
| **`Percentage`** | refinement of `Number` | `Number` tagged `percent`; the natural right operand of `%` | Makes `basic % 40` read as "40 percent of basic" and makes a policy value declared `percent` un-substitutable for a ratio |
| **`Rate<Money/U>`** | parametric | `Money` per one unit of `U ∈ {Days, Hours, Minutes}` | The result of `Money / Days`. Without it, a per-day value has no type and a per-day value multiplied by working days is indistinguishable from nonsense |
| `Boolean`, `Text`, `Code`, `List<T>`, `Null`, `DateTime`, `Time`, `Duration` | base | as Part VII §5.4.1 | unchanged |

**R-VIII-014** `DayFraction`, `Percentage` and `Rate<Money/U>` are registered in
the **shared** SPX type registry (`spx_types`), not in a formula-engine-private
one. Part VII's grammar and the rule engine gain them automatically; a rule may
compare a `DayFraction` and a formula may test a `Percentage`. **SPX remains one
language.**
**SMARTPRS 3 ARCHITECTURE RECOMMENDATION.** *Justification: the alternative —
carrying a per-day money value as a bare `Number` — is exactly the shape of
defect §2 item 6, where a per-day quantity crosses a unit boundary with nothing
to stop it.*

### 5.2 Coercion — the complete table

Implicit coercion happens in exactly these cases. **Everything else is a
publish-time type error.** Rows marked ▲ extend Part VII §5.4.2; the rest are
restated unchanged so this table can be read alone.

| From | To | When | Rule |
|---|---|---|---|
| integer `Number` | `Number` scale *n* | Always | Widening only |
| `Number` | `Days` / `Hours` / `Minutes` | **Never** | `TO_DAYS`, `TO_HOURS`, `TO_MINUTES` required |
| `Money` | `Number` | **Never** | `MONEY_AS_NUMBER(m)` required, and it is deliberately ugly |
| `Number` | `Money` | **Never** | `MONEY(amount, currency)` required |
| ▲ `Number` tagged `percent` | `Percentage` | Always | Refinement widening |
| ▲ `Percentage` | `Number` | In `*`, `/`, `MIN`, `MAX`, comparison | One-way; the tag is erased and the trace records the erasure |
| ▲ `Days` in `[0,1]` | `DayFraction` | On assignment to a `DayFraction`-declared symbol; **checked, not assumed** — out of range is `ERR_ARG` | Refinement narrowing |
| ▲ `DayFraction` | `Days` | Always | Refinement widening |
| `Date` | `DateTime` | Comparing with a `DateTime` | Midnight in the context's business timezone |
| `DateTime` | `Date` | **Never** | `DATE_OF(dt)` |
| `Code` | `Text` | In `CONCAT`, `UPPER`, `LOWER`, `MATCHES` | One-way |
| `Text` | `Code` | **Never** | A `Code`/literal comparison is legal only when the literal is in the declared value set |
| `Null` | any | In `COALESCE` only | Otherwise three-valued logic (Part VII §5.4.3) |
| `T` | `List<T>` | `IN` right operand | A scalar right operand is a publish-time error |
| `Money` | `Money` other currency | **Never** | No implicit FX, ever. Cross-currency is a declared conversion with a dated rate |

**R-VIII-015** There is **no numeric coercion between `Money` and `Number` in
either direction**. The engine cannot be talked into treating rupees as a
scalar. *This is the type-level statement of Law 7, and it is what makes §2
item 1 structurally impossible rather than merely discouraged.*

### 5.3 Why unit typing exists

`PayrollGenController.php:1268-1284`, verbatim in structure:

```php
$absentDays = max(0.0, $empWorking - $paidDays);   // measured in WORKING days
$den        = (float) $daysInMonth;                 // valued in CALENDAR days
$factor     = max(0.0, min(1.0, ($avail - $absentDays) / max(1.0, $den)));
```

Two absent working days in July 2026 (31 calendar days, 27 working days under
Sunday-only offs) cost **2/31 of gross under `calendar` basis, not 2/27**. On a
₹50,000 gross that is a ₹477.89 difference per employee per two-day absence, in
the employee's favour, every month, invisibly:

| Basis | What SmartPRS 2 computes | Gross paid |
|---|---|---|
| `working` | 25 ÷ 27 | ₹46,296.30 |
| `calendar` | (31 − 2) ÷ 31 — absence in working days, denominator in calendar days | ₹46,774.19 |
| `fixed30` | (30 − 2) ÷ 30 — same mismatch, different denominator | ₹46,666.67 |

Both figures may be a legitimate business choice. **Neither is a legitimate
accident.** In SmartPRS 3 the mismatch is either impossible or explicit:

```spx
-- REJECTED at publish (G4): Days measured against a calendar-day denominator
-- with no declared conversion.
(settlement.scheduled_days - settlement.absent_working_days) / period.calendar_days

-- ACCEPTED: the conversion is named, dated and traced.
( TO_DAYS(settlement.scheduled_days, 'CALENDAR')
  - TO_DAYS(settlement.absent_working_days, 'WORKING_TO_CALENDAR') )
/ period.calendar_days
```

**R-VIII-016** `Days`, `Hours`, `Minutes` and `DayFraction` do not implicitly
coerce into one another, and a `Days` value carries the **day-length policy code**
that gives it meaning. Converting between day-length bases requires
`TO_DAYS(x, '<policy_code>')`, whose policy is an effective-dated artefact
naming the numerator and denominator basis. Every such conversion appears in the
trace as a named step.

**R-VIII-017** A `Money / Days` division yields `Rate<Money/Days>` — a typed
per-day value that can only be multiplied by `Days` to become `Money` again. A
per-day value can therefore never be multiplied by hours, by a count, or by a
`Days` value on a different day-length policy without an explicit conversion.

### 5.4 The operation matrix — complete

`✓` = permitted, with the stated result type. `✗` = **publish-time type error**.

| Left | `+` `-` | `*` | `/` | `%` (percent-of) | `MOD` |
|---|---|---|---|---|---|
| `Money` op `Money` (same ccy) | ✓ `Money` | ✗ | ✗ *(a money ÷ money ratio needs `MONEY_AS_NUMBER` on both, deliberately verbose)* | ✗ | ✗ |
| `Money` op `Money` (diff ccy) | ✗ | ✗ | ✗ | ✗ | ✗ |
| `Money` op `Number` | ✗ | ✓ `Money` | ✓ `Money` | ✓ `Money` | ✗ |
| `Money` op `Percentage` | ✗ | ✓ `Money` | ✓ `Money` | ✓ `Money` | ✗ |
| `Money` op `Days` | ✗ | ✗ | ✓ **`Rate<Money/Days>`** | ✗ | ✗ |
| `Money` op `Hours` | ✗ | ✗ | ✓ **`Rate<Money/Hours>`** | ✗ | ✗ |
| `Money` op `Minutes` | ✗ | ✗ | ✓ `Rate<Money/Minutes>` | ✗ | ✗ |
| `Rate<Money/U>` op `U` | ✗ | ✓ **`Money`** | ✗ | ✗ | ✗ |
| `Rate<Money/U>` op `Number` | ✗ | ✓ `Rate<Money/U>` | ✓ `Rate<Money/U>` | ✓ `Rate<Money/U>` | ✗ |
| `Rate<Money/U>` op `Rate<Money/U>` | ✓ `Rate<Money/U>` | ✗ | ✗ | ✗ | ✗ |
| `Rate<Money/U>` op `Rate<Money/V>`, U≠V | ✗ | ✗ | ✗ | ✗ | ✗ |
| `Number` op `Number` | ✓ `Number` | ✓ `Number` | ✓ `Number` | ✓ `Number` | ✓ `Number` |
| `Days` op `Days` | ✓ `Days` | ✗ | ✓ `Number` *(a ratio; both must share a day-length policy)* | ✗ | ✗ |
| `Days` op `Number` | ✗ | ✓ `Days` | ✓ `Days` | ✓ `Days` | ✗ |
| `Days` op `Hours` | ✗ **type error** — write `TO_HOURS(d,'…') + h` | ✗ | ✗ | ✗ | ✗ |
| `Hours` op `Hours` | ✓ `Hours` | ✗ | ✓ `Number` | ✗ | ✗ |
| `Hours` op `Number` | ✗ | ✓ `Hours` | ✓ `Hours` | ✓ `Hours` | ✗ |
| `Hours` op `Minutes` | ✗ — write `TO_MINUTES(h) + m` | ✗ | ✗ | ✗ | ✗ |
| `Minutes` op `Minutes` | ✓ `Minutes` | ✗ | ✓ `Number` | ✗ | ✗ |
| `Minutes` op `Number` | ✗ | ✓ `Minutes` | ✓ `Minutes` | ✓ `Minutes` | ✗ |
| `Date` op `Date` | ✗ — use `DATEDIFF(a,b,unit)` | ✗ | ✗ | ✗ | ✗ |
| `Date` op `Number` | ✗ — use `DATEADD` | ✗ | ✗ | ✗ | ✗ |
| `DateTime` op `DateTime` | ✓ `Duration` (`-` only) | ✗ | ✗ | ✗ | ✗ |
| anything op `Boolean`/`Text`/`Code`/`Date` | ✗ | ✗ | ✗ | ✗ | ✗ |

`*` is commutative across the matrix: where `A * B` is permitted, `B * A` is
permitted with the same result type. `+` is commutative; `-` and `/` are not.

The four operations the brief asks about explicitly:

| Expression | Result | Note |
|---|---|---|
| `Money * Number` | `Money` | Exact rational until the declared rounding boundary |
| `Money / Days` | `Rate<Money/Days>` | The per-day value; it must be multiplied by `Days` to become `Money` |
| `Days + Hours` | **type error** | Write `TO_HOURS(days, '<day_length>') + hours`, which names the day length |
| `Money % Number` | `Money` | `%` is percent-of (spine S5.2). `X % 40` is 40 percent of X. Modulo is `MOD(a,b)` and is **not defined on `Money`** |

**R-VIII-018** `MOD` is undefined on `Money`, on `Rate` and on any duration type.
Modular arithmetic on an amount has no business meaning and is the kind of
expression that reads as a rounding when it is not.

**R-VIII-019** Division by zero returns `ERR_DIV_ZERO` (Part VII §5.8), never
infinity, never null, never zero — and gate G6 (§9.6) refuses to publish an
unguarded division whose denominator is reachably zero. *SmartPRS 2 guards its
divisors with `max(1, …)` in some paths (`:1118-1127`, `:1287`) and not in
others, so the guard is a habit rather than a property.*

---

## 6. Money arithmetic in full

### 6.1 Representation

```
Money = { minor: BigInteger,        -- signed integer paise
          currency: Code,           -- ISO 4217, 'INR'
          scale: 2 }                -- minor units per major, from the currency table
```

**R-VIII-020** Money is carried as **integer minor units in a value object**
through every calculation, every comparison, every persistence hop and every
serialisation. A float never touches money at any point, in any layer,
including JSON encoding, queue payloads, cache values and export files. A
static-analysis rule fails the build on a float-typed parameter, return or
property in any money path, and on any use of `==` between money values outside
`Money::equals()`. *Directly closing §2 items 1–4: a float comparison currently
decides whether a loan instalment is deducted (`:1357`), and a loose float
equality decides whether a commission line is written (`:2632`).*

**R-VIII-021** At rest money is `DECIMAL(18,4)` (spine S16). The mapper converts
`DECIMAL → BigInteger minor` on read and back on write, and **asserts the
round-trip is lossless**. A stored value with a non-zero third or fourth decimal
place in a paise-scaled currency is a data defect, raised as an operator alert,
never silently truncated.

**R-VIII-022** Currency is carried explicitly on every `Money` value. There is
no ambient or default currency. Two money values of different currencies cannot
be added, compared, allocated or summed — the type system refuses it (§5.4), and
there is no implicit FX anywhere in SmartPRS 3.

### 6.2 Exact rational intermediates

Between the input and the declared rounding boundary, the evaluator does **not**
work in paise. It works in exact rationals:

```
Rational = { num: BigInteger, den: BigInteger }   -- den > 0, always normalised
```

- A `Money` entering an expression becomes `Rational(minor, 1)`.
- `Money * Number` → `Rational(num × n_num, den × n_den)`.
- `Money / Number` → `Rational(num × n_den, den × n_num)`; `n_num = 0` → `ERR_DIV_ZERO`.
- `Money % p` (percent-of) → `Rational(num × p_num, den × p_den × 100)`.
- `Money + Money` → common denominator, exact.
- `Rate<Money/U>` is a `Rational` tagged with its denominator unit.

**No rounding, truncation or precision loss occurs at any intermediate step.**
`(gross / 27) * 25` and `(gross * 25) / 27` produce the **identical** rational
and therefore the identical rounded result — an equivalence SmartPRS 2 cannot
offer, because on floats they differ. Normalisation by GCD keeps denominators
small in practice; a denominator exceeding 2^64 triggers `ERR_PRECISION`, which
in eight years of Indian payroll arithmetic requires a deliberately pathological
expression to reach, and which fails loudly rather than degrading.

**R-VIII-023** The rational is collapsed to integer minor units **exactly once
per formula**, at the declared rounding boundary. The pre-rounding rational is
retained in the trace as `exact_value` so the rounding step is visible and
auditable.

**R-VIII-024** A dependent formula reads the **rounded** result of the formula
it depends on, unless that formula is declared `formula_kind = intermediate` with
`reference_value = exact`. *Rationale: a payslip must be re-derivable from the
numbers printed on it. If HRA were computed from an unrounded Basic while the
payslip prints a rounded Basic, an employee recomputing 40% of the printed Basic
would get a different HRA and would be right to complain.* See **D-VIII-01**.

### 6.3 The rounding boundary

Rounding is **a property of the value, declared once, on the component or head**
— never a consequence of which function computed it.

| Declaration | Values | Meaning |
|---|---|---|
| `rounding_mode` | `half_up`, `half_down`, `half_even`, `up`, `down`, `ceil`, `floor`, `none` | Mandatory. `up`/`down` are away-from-zero / toward-zero; `ceil`/`floor` are toward +∞ / −∞ — **they differ on negative amounts**, which is why both exist |
| `rounding_unit` | `0.01`, `1.00`, `0.05`, `0.10`, `5.00`, `10.00`, `100.00` | The quantum. `1.00` is whole rupee; `100.00` rounds a challan to the nearest ₹100 |
| `rounding_point` | `final`, `none` | Where. `final` = once, on the result |
| `residue_head` | component code | For a split: which part absorbs the residue (§6.4) |

Worked, on the same rational value **₹1,249.4999…** (`Rational(124950, 100)` ×
a rate that lands just below the half):

| mode | unit `0.01` | unit `1.00` |
|---|---|---|
| `half_up` | ₹1,249.50 | ₹1,249.00 |
| `half_even` | ₹1,249.50 | ₹1,249.00 |
| `ceil` | ₹1,249.50 | ₹1,250.00 |
| `floor` | ₹1,249.50 | ₹1,249.00 |
| `none` | *(publish error on a component formula)* | — |

**R-VIII-025** Every component, every statutory head, every employer
contribution sub-head, every recovery and every terminal payment declares its
own `rounding_mode` and `rounding_unit`. A component with no rounding
declaration **cannot be published**. *This is the answer to `A-payroll.md` §4.16:
ESI is the only `ceil` in the system, statutory bonus and one of the two
gratuity reports are the only whole-rupee roundings, LWF is never rounded at
all, and the two gratuity reports round differently from each other — none of
which is declared anywhere; each is an accident of the function that produced
it.*

**R-VIII-026** Where a statutory head has both a **rounding** and a **cap**, the
rule pack declares the **order** (`round_then_cap` or `cap_then_round`), and the
trace shows both steps. *Because `min(round(15000 × 8.33/100, 2), 1250.0)` at
`AppDataController.php:2071` produces ₹1,249.50 and the declared cap of ₹1,250
never binds — DEF-P08. SmartPRS 3 does not decide which order is legally
correct; it makes the choice explicit, versioned, signed and traceable. The
value used and its authority are the compliance owner's (spine D-03).*

**R-VIII-027** Rounding of a `Rate` is **forbidden by default**. An hourly or
daily rate is an intermediate; the money it produces is what rounds. A rate may
declare rounding only when the business genuinely publishes a rounded rate (a
posted per-hour figure in a wage agreement), and doing so requires an explicit
`rate_is_published = true` flag on the formula and appears in the trace.
*Because `MasterController.php:761` performs three successive float divisions —
`ctc / 12 / 26 / 8` — and rounds only at the end, which is accidentally right;
SmartPRS 3 makes it deliberately right.*

### 6.4 `ALLOCATE` — residue-to-last, proved

`ALLOCATE(amount: Money, weights: List<Number>, unit: Money?) → List<Money>`

```
Algorithm ALLOCATE(A, W[1..n], q)                 -- A in minor units, q = quantum in minor units
  1. require n >= 1; require every W[i] >= 0; require SUM(W) > 0     else ERR_ARG
  2. T  := SUM(W)                                  -- exact rational
  3. for i in 1..n-1:
        exact[i] := A * W[i] / T                   -- exact rational, no rounding
        part[i]  := TRUNC_TOWARD_ZERO(exact[i] / q) * q
  4. part[n] := A - SUM(part[1..n-1])              -- the residue, by construction
  5. return part[1..n]
```

**Invariant, by construction of step 4:** `SUM(part) = A`, exactly, for every
`A`, every weight vector and every quantum. The residue is not distributed by a
heuristic; the last element is **defined** as the remainder.

`residue_head` on the component declaration names which element is "last". It
defaults to the structure's declared `balance_component_code` and, failing that,
to the highest `sequence_no`. It is never implicit.

#### Worked example 1 — the three-way split the brief asks for

Balance pool **₹32,499.99** (`3,249,999` paise) across three balance components
with weights `[50, 30, 20]`, quantum `0.01` (`q = 1` paise):

```
T = 100

exact[1] = 3,249,999 × 50 / 100 = 1,624,999.5      part[1] = TRUNC(1,624,999.5) = 1,624,999
exact[2] = 3,249,999 × 30 / 100 =   974,999.7      part[2] = TRUNC(  974,999.7) =   974,999
                                                    ---------------------------------------
                                                    SUM(part[1..2])            = 2,599,998
part[3] = 3,249,999 - 2,599,998                                                =   650,001

           ₹16,249.99  +  ₹9,749.99  +  ₹6,500.01  =  ₹32,499.99      ✓ exact
```

The third component absorbs **2 paise** of residue. It is named in the trace, it
is not a rounding error, and no paise is created or destroyed.

#### Worked example 2 — equal weights, the classic thirds

`ALLOCATE(₹1,00,000.00, [1, 1, 1])`, `q = 1` paise, `A = 10,000,000`:

```
exact[1] = exact[2] = 10,000,000 / 3 = 3,333,333.333…
part[1] = 3,333,333        part[2] = 3,333,333        part[3] = 10,000,000 - 6,666,666 = 3,333,334

           ₹33,333.33  +  ₹33,333.33  +  ₹33,333.34  =  ₹1,00,000.00      ✓ exact
```

#### Worked example 3 — a whole-rupee quantum

`ALLOCATE(₹1,00,000.00, [1, 1, 1], unit = ₹1.00)`, `q = 100` paise:

```
part[1] = TRUNC(3,333,333.33 / 100) × 100 = 33,333 × 100 = 3,333,300
part[2] = 3,333,300
part[3] = 10,000,000 - 6,666,600 = 3,333,400

           ₹33,333.00  +  ₹33,333.00  +  ₹33,334.00  =  ₹1,00,000.00      ✓ exact
```

**R-VIII-028** **Every** place money is split in SmartPRS 3 uses `ALLOCATE`:
the salary structure's balance components, employer-contribution sub-heads, a
loan schedule's instalments, an arrear spread across periods, a cost-centre
apportionment, a bank-file batch total, an incentive pool distribution, a
gratuity provision across months. *SmartPRS 2 has exactly one correct residue
allocation (`AppDataController.php:2247-2254`, spine S19.4) and generalises it
nowhere.*

**R-VIII-029** A split whose weights sum to zero, or which contains a negative
weight, is `ERR_ARG` — never a silent equal split and never a division by zero.

### 6.5 Negative amounts

Negative money is legitimate (a recovery, a reversal, a negative arrear from a
back-dated decrease). The rules are declared rather than emergent:

| Situation | Behaviour |
|---|---|
| `ceil`/`floor` on a negative | Toward +∞ / −∞ respectively — **not** away from / toward zero. Use `up`/`down` for magnitude semantics |
| `ALLOCATE` with a negative amount | Legal; the residue-to-last invariant holds identically |
| `ALLOCATE` with mixed-sign weights | `ERR_ARG` |
| A component computing negative when its declaration says `non_negative` | `ERR_RANGE`, surfaced as a payroll preview exception naming the component — **never clamped silently**. *Because `computeSlipFromComponents` prints a negative residue as a negative earning (DEF-P20c) and `computeSlip` clamps net at zero with `max(0, …)` (`:2129`) while the Simulator clamps at a different point (`:2206-2208`), so the two disagree (DEF-P06)* |
| Net pay computing negative | A **payroll preview exception**, blocking the run (spine S11), never a clamp |

**R-VIII-030** Clamping is a declared property (`non_negative`, `min_value`,
`max_value`) on the component, applied by the engine at a named step that
appears in the trace. There is no implicit `max(0, …)` anywhere in the engine.

### 6.6 The invariant tests

These run on **every** payroll run, not only in CI, and a failure **aborts the
run** (spine S11).

| # | Invariant | Assertion | Granularity |
|---|---|---|---|
| I1 | Allocation is exact | `SUM(ALLOCATE(A, W)) = A` for every allocation performed | Per allocation |
| I2 | The slip balances | `SUM(earning lines) − SUM(deduction lines) = net` | Per payslip, in minor units |
| I3 | The run balances | `SUM(payslip.net) = run.net_total`, and likewise for gross, deductions and each employer contribution | Per run |
| I4 | Structure reconciles | `SUM(components flagged is_ctc_component) + SUM(employer contributions) = period CTC` within the declared tolerance of `0` | Per employee-period |
| I5 | Proration conserves | `full_amount − prorated_amount = SUM(LOP lines)` for every LOP-reducible component | Per employee-period |
| I6 | Sub-heads conserve | For every split head, `SUM(sub-heads) = parent total` | Per head |
| I7 | Arrears conserve | `SUM(arrear lines for period P) = recomputed(P, new) − original(P)` line for line | Per arrear batch |
| I8 | Bank file reconciles | `SUM(bank file rows) = SUM(payslip.net for included employees)` | Per bank file |
| I9 | Rounding is bounded | For any component, `\|rounded − exact\| < rounding_unit` | Per line, in CI |
| I10 | Currency is uniform | Every money value in a run shares the run's currency | Per run |

**R-VIII-031** I1–I10 are assertions in production code, not tests. A failure
raises `PayrollReconciliationFailed`, aborts the run, names the employee,
component and the two figures, and is never downgraded to a warning. *SmartPRS 2
has no such check anywhere: the run total is a running float sum and nothing
compares it to `SUM(payslips.net)` (DEF-P17, DEF-P07); the bank-file total is
computed twice by two different float paths with no cross-check
(`SalaryApprovalController:428`, `:483`).*

**R-VIII-032** A property-based test suite asserts I1 across ≥ 10^6 randomly
generated `(amount, weights, quantum)` triples including negative amounts,
zero-weight elements, single-element vectors, 500-element vectors and
amounts at the BigInteger boundary.

---

## 7. The dependency graph

### 7.1 Construction

The graph is built **at publish time**, from the compiled AST, per resolved
scope. Nodes and edges:

| Node kind | Identity | Created from |
|---|---|---|
| `component` | `(component_code, config_version_id)` | A structure line |
| `formula` | `(formula_code, config_version_id)` | A formula artefact |
| `statutory` | `(rule_code, pack_version_id)` | A rule-pack head |
| `settlement` | slice symbol | `settlement.*` reference — a **source**, no outgoing edges |
| `ledger` | `(ledger_code)` | `agg.balance(...)` — a source |
| `policy` | `(policy_key, config_version_id)` | `policy.value(...)` — a source |

| Edge | Created by |
|---|---|
| `formula → component` | `salary.component('CODE')` in the AST |
| `formula → formula` | `formula.value('CODE')` in the AST |
| `component → formula` | `cfg_salary_components.formula_config_version_id` |
| `component → component` | `basis_component_code` on a `percent_of` line |
| `formula → statutory` | `statutory.value('CODE')` |
| `formula → policy` | `policy.value('key')` |
| `formula → ledger` | `agg.balance('code', as_of)` |

**R-VIII-033** Edges are derived from the AST by the compiler, never authored
(R-VIII-012). A reference the compiler cannot resolve statically — a component
code assembled at runtime, for instance — **is not expressible**: `salary.component`
takes a `string_lit` key argument only (Part VII §5.3 `key_arg ::= string_lit`).
*This is why the graph is complete and why cycle detection is sound rather than
best-effort.*

### 7.2 Cycle detection

**R-VIII-034** The graph is checked for cycles by Tarjan's strongly-connected-components
algorithm at publish (gate G5). Any SCC of size > 1, or any self-loop, blocks
publication with **the cycle named in the error**, in traversal order, with each
edge's origin shown:

```
Cannot publish  formula  F-SPECIAL-BALANCE v4

  Circular dependency:

    component  SPECIAL   v4   --(formula)-->
    formula    F-SPECIAL-BALANCE v4  --(salary.component('GROSS'), line 2)-->
    component  GROSS     v9   --(basis_component_code)-->
    component  BASIC     v7   --(formula)-->
    formula    F-BASIC-PCT-GROSS v3  --(salary.component('GROSS'), line 1)-->
    component  GROSS     v9   ← cycle closes here

  Scope in which the cycle occurs: company = Ametecs South, grade = ALL
  Employees affected in this scope on 01-Apr-2026: 1,247
  Fix: BASIC must not derive from GROSS when GROSS is the balance of BASIC.
```

**R-VIII-035** The check runs **per resolved scope**, not per artefact. A graph
that is acyclic for the Hyderabad structure and cyclic for the Pune structure is
cyclic, and is blocked. Where a tenant's distinct scope set is large, the check
runs over the tenant's **actual** distinct scope signatures (tens to low
hundreds — §13.3), not over the combinatorial space. *This mirrors Part VI's
V06 validator and is the same check; this part specifies its algorithm.*

**R-VIII-036** A cycle is **never** broken at runtime by a depth limit, an
iteration cap or a "use last known value" fallback. A cycle is a configuration
error caught before publication, and the runtime evaluator has no cycle-handling
path at all because it cannot receive a cyclic graph.

### 7.3 Topological evaluation order

**R-VIII-037** Evaluation order is the topological order of the graph, computed
once per `(structure version, statutory pack version, scope)` and cached under
that key. It is **not** the component's `sequence_no` — `sequence_no` governs
**presentation order on the payslip** and nothing else.

*This separation is deliberate. SmartPRS 2 conflates them: `computeSlipFromComponents()`
sorts by `seq ASC` (`:2162`) and then hard-codes five ordered passes (Basic →
reimbursement → earnings → balance → DA) because the sequence number cannot
express a dependency. A customer who reorders their payslip therefore changes
their arithmetic.*

Where the topological order is not total (two components with no dependency
between them), ties are broken by `sequence_no` then `component_code`, so the
order is **deterministic** across runs, machines and PHP versions.

### 7.4 Memoisation

```
memo key = (formula_version_id, context_digest, employee_id, period_id)
```

`context_digest` is the SHA-256 of the canonical prepared context (§8.4). A
formula referenced by six components in one structure is evaluated **once** per
employee-period, and the five other references read the memo and record a
`reference` node in their traces pointing at the single computed trace (§10.4).

**R-VIII-038** The memo is per-batch and in-memory. It is **never** a
cross-request cache and never has a TTL, because a stale amount is a wrong
payslip, not a slow one. The compiled AST is cached durably (§13.3); computed
values are not.

### 7.5 Disabled components and version changes

| Situation | Behaviour |
|---|---|
| A referenced component is **disabled** (its version is `archived`, or no version is effective on the evaluation date) | **Publish time (static reference):** gate G2 blocks with `component 'CONV' has no version effective 01-Apr-2026 in scope {company: X}`. **Run time (a version that lapsed after publish):** the formula returns `ERR_UNRESOLVED_COMPONENT`, the employee is a **payroll preview exception** naming the component, the period and the employee code, and the run cannot proceed past preview (spine S11) |
| A referenced component is **suppressed for this employee** by a rule (`SUPPRESS_COMPONENT`) | The reference resolves to `MONEY(0, 'INR')` **and the trace records `suppressed by rule <code> v<n>`**. It does not error: suppression is a designed outcome, and its cause is named |
| A referenced component **exists but has no value yet** in this evaluation (an ordering fault) | Impossible by construction — topological order guarantees it. If it occurs it is `ERR_TYPE`, raises an **operator alert**, and is treated as a platform bug, not a configuration error |
| A referenced component's **version changes** (a new version is published) | The dependent formula's `checksum` does not change, but the **resolved dependency set** does. The engine recomputes `depends_on_json` for every dependent, re-runs their pinned sample tests (gate G10), and **blocks the publication of the changed component if any dependent's pinned case now fails**, showing expected and actual |
| A referenced **statutory pack version** changes | Same treatment, plus a mandatory impact preview (spine S12): affected employees, the old and new amounts, and the effective date |
| A formula version is **superseded mid-period** (effective 15-Aug in an Aug period) | The engine resolves per the period's declared `formula_resolution_date` — by default `period.end`. **PRODUCT OWNER DECISION REQUIRED — D-VIII-05** |

**R-VIII-039** Publishing a component or formula version runs the pinned sample
tests of **every transitive dependent**, not only its own. A change that breaks
a dependent's asserted expected value blocks publication and names the dependent,
the case, the expected value and the actual value.

**R-VIII-040** Every derived record — payslip line, ledger entry, settlement row
— stores `formula_versions_json`: the version id of every formula that
contributed to it, transitively. *Spine S4 and Law 4. Without it a payslip is
not defensible three years later, which is exactly SmartPRS 2's position.*

---

## 8. The calculation context

### 8.1 The principle

**R-VIII-041** A formula **cannot query the database.** SPX has no I/O
production in its grammar (Part VII §5.1). Every value a formula reads comes
from a **prepared, immutable context bundle** assembled by the calling domain
service *before* evaluation begins, and frozen for the duration of the batch.

This is not a performance decision, though it is also that. It is what makes
`context_digest` meaningful, what makes a re-run reproducible, and what makes
the N+1 query class of defect inexpressible.

### 8.2 The slices

A **slice** is a named, independently loadable region of the context. A formula
declares the slices it needs; the calculation point declares the slices it may
offer; the batch loader loads the union, once per shard.

| Slice code | Contents | Source | Loaded by |
|---|---|---|---|
| `employee.identity` | code, uuid, joining date, exit date, stage, category, employment type, gender, dob, declared custom fields | `employments` + `entity_field_values` | 1 query + 1 EAV query per shard |
| `employee.org` | the 21 scope dimensions **as at the evaluation date** | effective-dated assignment rows | 1 range query per shard |
| `employee.service` | years, months, days, completed years, as at the date | derived from `employee.identity` | computed |
| `employee.compensation` | CTC, structure version id, currency, per-employee overrides, **as at the period** | `employee_compensations` (effective-dated) | 1 query |
| `period.definition` | start, end, month, year, fin year, cut-off day, calendar days, working days, scheduled payable days, periods per year | resolved pay-cycle artefact | 1 query, shared across the shard |
| `structure.resolved` | every component line at its resolved version: code, kind, category, calculation kind, basis, percent, fixed amount, rounding declaration, proration basis, and the **ten statutory-base flags** | `cfg_salary_structures` + `cfg_structure_lines` + `cfg_salary_components` | 1 query per distinct structure version |
| `settlement.attendance` | payable days, LOP days, present days, weekly-off days, holiday days, OT minutes, late count, shortfall minutes, comp-off earned/availed, and the settlement's own `attendance_generation_max` | `period_settlements` (spine S10 layer 4, **frozen**) | 1 query |
| `settlement.leave` | approved units by leave type, paid/unpaid split, day-part detail, LOP-leave units | `period_settlements` + leave charge rows | 1 query |
| `statutory.pack` | every resolved rate, ceiling, slab table, threshold and applicability flag for the employee's establishment, **at the pack version effective for the period** | signed rule pack (spine S12) | 1 query per distinct establishment-pack pair |
| `statutory.locks` | period-scoped eligibility determinations carried forward (the ESI contribution-period lock), **as structured facts** | `employee_period_statutory_locks` | 1 query |
| `ledger.balances` | leave, comp-off, loan, advance, reimbursement-carry balances as at the period | balance snapshots + delta | 1 query |
| `ytd.prior` | prior-period year-to-date: gross, each component, each statutory head, tax deducted, taxable income, and the count of periods already run in the financial year | approved payslips in the FY, **scoped, de-duplicated, run-approved only** | 1 aggregate query |
| `variable.inputs` | approved commissions, incentives, OT claims, reimbursement claims, arrear instructions, one-off payments for this period | approved variable-input rows | 1 query |
| `loan.terms` | principal, instalment, count, start period, outstanding, moratorium, interest terms | `loans` + schedule | 1 query |
| `prior_run.snapshot` | for an arrears calculation: the **full line-level result** of the original run for the target period, at its original versions | `payslips` + `payslip_lines` of the pinned run | 1 query per target period |

**R-VIII-042** `ytd.prior` is computed over **approved, de-duplicated,
tenant-and-company-scoped** payslips only. *`AppDataController::payslipYtd():1557-1584`
has no tenant filter, no company filter, no run filter and no de-duplication
(SEC-P09) — so an employee who appears in two runs is counted twice, and an
employee id colliding across tenants leaks.*

**R-VIII-043** `settlement.attendance` and `settlement.leave` are read from the
**frozen** period settlement (spine S10 layer 4). A formula never reads
attendance days or punches directly. *Because SmartPRS 2 has no attendance
freeze at all, so a draft payroll is silently re-priced by any punch, leave
approval or holiday change that happens afterwards (DEF-P04).*

### 8.3 Declaring and enforcing slices

```yaml
formula: F-OT-AMOUNT
calculation_point: ot.amount
declared_slices:
  - employee.compensation
  - period.definition
  - structure.resolved
  - settlement.attendance
  - policy            # implicit: always available
```

**R-VIII-044** The compiler **derives** the true slice set from the AST and
compares it with `declared_slices`. A slice declared but unused is a lint
warning (it costs assembly time for nothing). A slice **used but not declared**
blocks publication (gate G8). A slice not offered by the calculation point
blocks publication regardless of declaration.

**R-VIII-045** At runtime, reading a symbol whose slice was not loaded raises
`ERR_MISSING_SLICE` — a **platform bug**, not a configuration error: it raises
an operator alert and fails the subject, because gate G8 should have made it
impossible.

### 8.4 Assembly, immutability and the digest

```
ContextAssembler::forBatch(calculation_point, subject_set, as_of)

1. union the declared slices of every resolved formula in the batch
2. bulk-load each slice: ONE query per slice per shard, never per subject
3. freeze: the bundle is a read-only value object; the evaluator holds no
   repository, no connection, no container
4. digest: context_digest = SHA-256 over the canonical serialisation of the
   bundle, with keys sorted and money rendered as signed minor units
```

**R-VIII-046** The prepared context is **immutable for the life of the batch**.
No rule action, no formula result and no domain service may mutate it. The
payroll pipeline's stages each build their own context from the run's **input
snapshot** (spine S11), which is what makes a re-run reproduce the original
result exactly and makes a re-run with changed inputs *warn about precisely
which input changed* — by comparing digests slice by slice.

**R-VIII-047** `context_digest` is stored on every calculation trace and pinned
on every derived record. Two payslip lines with the same formula version and the
same context digest that hold different values is a platform-integrity failure
detectable by a scheduled reconciliation job.

---

## 9. Validation before publish

The twelve gates of §4.2, specified. Every gate writes a row to
`formula_publication_checks` with `severity ∈ {block, warn, lint, pass}`.

### 9.1 G1 — Parse and budget

Source parses under the Part VII §5.3 grammar. Budgets, all `block`:

| Budget | Limit | Rationale |
|---|---|---|
| Source length | 8,000 characters | Part VII §5.2 |
| AST nodes | 2,000 | A formula longer than this is a structure, not a formula |
| Expression depth | 40 | Comprehensibility, and stack safety |
| Distinct dependencies | 64 | Beyond this the graph is unreadable and the trace unusable |
| `agg.*` calls | 4 | Cost model (Part VII §5.9.4) |
| Literal list length | 500 elements | Part VII §5.2 |

### 9.2 G2 — Symbols and references

Every namespace is in the calculation point's table. Every keyed accessor's
argument resolves **in this tenant, in this scope, on `effective_from`**:
`salary.component('X')` → an existing component code with a version effective on
the date; `statutory.value('Y')` → a code present in the resolved pack;
`policy.value('z')` → a published policy artefact; `leave.balance('SL')` → a
leave type in the value set. An unresolvable key is `block` with the key named.

### 9.3 G3 — Type checking

Bottom-up inference over the AST against the operation matrix (§5.4). The
inferred root type must **equal** `result_type` — not be coercible to it.
Reported failures name the node, its source span, the two operand types and the
matrix row that refused them:

```
Type error at F-LOP-CALENDAR line 2, col 34
  (settlement.absent_working_days) / (period.calendar_days)
   Days                             Number
  Days / Number → Days.  Assigning Days to result_type Number is not permitted.
  Did you mean:  MONEY_PER_DAY × settlement.absent_working_days  (result Money)?
```

### 9.4 G4 — Unit checking

Beyond type checking. Blocks:

- a `Days` value from one day-length policy combined with a `Days` value from
  another without `TO_DAYS(x, '<policy>')`;
- a `Rate<Money/Days>` multiplied by `Hours`;
- a `Money` in one currency combined with a `Money` in another;
- a `Percentage`-tagged value used as a plain ratio without erasure (`warn`);
- **a duration numerator divided by a differently-based duration denominator**
  — the §5.3 defect. `block`, with the two bases named.

### 9.5 G5 — Cycles

§7.2. `block`, with the cycle path printed.

### 9.6 G6 — Division-by-zero reachability

Static analysis of every `/` and `MOD` denominator. Classification:

| Denominator form | Verdict |
|---|---|
| A non-zero literal | pass |
| `MAX(x, <positive literal>)` or `COALESCE(x, <positive literal>)` | pass — guarded |
| A symbol declared `not_null_required` **and** `positive` in the slice schema (e.g. `period.calendar_days`) | pass |
| Any other symbol or expression | **block**, with the message *"denominator `settlement.payable_day_denominator` can be zero (an employee with no scheduled days in the period). Guard it with `MAX(…, 1)` or `IF(… = 0, …, …)`, or declare the symbol positive in the slice schema."* |

*SmartPRS 2 guards `max(1, $working)` at `:1127` and `max(1.0, $den)` at `:1287`
but not `$empWorking` at `:1272`, and a divisor guard that is a habit rather than
a checked property is the shape of an outage on the one employee it misses.*

### 9.7 G7 — Unbounded growth and table well-formedness

- A `BANDS` table must be **sorted ascending, contiguous, non-overlapping**, and
  either cover the domain or supply an explicit terminal open band. `block`.
- A `SLAB` reference must name a slab table in the resolved pack whose bands
  satisfy the same conditions and which declares `marginal` or `flat` explicitly.
  `block`. *Because `newRegimeTax()` walks `$r['tds_slabs']` in stored order with
  nothing validating that order (DEF-P22), and `IncentiveController::payoutFor()`
  falls through its band loop to the **last band's rate** whenever the guard
  `upto > 0` is false — so a ₹6,00,000 collection lands on the 3% band by
  accident of loop structure, not by design (`A-payroll.md` §4.12).*
- A `STEP` must declare `mode ∈ {ceil, floor, round}` and a positive `block`.
- Growth: a formula whose result feeds, transitively, back into one of its own
  inputs through a **scope-varying** reference is caught by G5 per scope; a
  formula whose value is unbounded in a declared input (an uncapped percentage
  of an uncapped base with no `MIN`) raises a `warn` naming the unbounded path,
  which the author clears by adding a cap or by acknowledging it with a reason.

### 9.8 G8 — Context slices

§8.3. Used-but-undeclared: `block`. Declared-but-unused: `lint`. Not offered by
the calculation point: `block`.

### 9.9 G9 — Rounding declaration

`rounding_mode` present and legal; `none` only for `formula_kind = intermediate`;
`rounding_unit > 0`; the unit expressible in the result's unit; no `ROUND`/`CEIL`/
`FLOOR`/`ROUND_TO` applied to the root node (R-VIII-007); a `Rate` result with
rounding declared and `rate_is_published = false`: `block`.

### 9.10 G10 — The mandatory sample-employee test set

**R-VIII-048** A formula version **cannot be published with fewer than three
sample cases**, and every case must pass. The three are not arbitrary: the gate
requires at least one case in each of the classes the formula's inputs make
reachable:

| Class | Required when | Example |
|---|---|---|
| Nominal | always | Full attendance, standard structure |
| Boundary | the formula contains a `MIN`, `MAX`, `CLAMP`, `BANDS` or `SLAB` | Exactly at the ceiling; exactly at a band edge |
| Zero / empty | any denominator or any `Days`/`Money` input can be zero | Zero payable days; zero balance |
| Negative | the result or any input can be negative | A back-dated decrease |
| Proration | the formula reads `settlement.*` | A mid-month joiner and a mid-month leaver |
| Statutory boundary | the formula reads `statutory.*` | One paise above and one paise below the threshold |

A case is `{sample_employee_code, period_code, as_of, context_overrides,
expected_value, expected_rounding_step}`. **The expected value is asserted
exactly, in minor units**, not within a tolerance.

**R-VIII-049** Publishing runs, in addition, the pinned cases of every transitive
dependent (R-VIII-039) and of every **structure** referencing the formula
(Part VI V15: ≥ 3 named sample employees per structure with expected values
asserted).

### 9.11 G11 — Scope, overlap and ties

No overlapping effective range within the same artefact and scope; no gap for an
artefact declared `continuous`; no specificity-plus-priority tie with another
published version (R-VIII-010), reported with both codes and the affected
employee count.

### 9.12 G12 — Approval

`high_risk` formulas — every formula feeding a statutory head, a net-pay line or
an employer contribution — require a second human's approval through the
workflow engine before `published` (spine S4, S8), with a mandatory diff:
old expression, new expression, impacted employee count, impacted modules,
effective date, and the **money delta** from the impact preview (§12.3).

---

## 10. The calculation trace

### 10.1 Contract

**R-VIII-050** No formula returns a value without a trace. A trace-less result
is a programming error and throws (Law 4). Tracing may be sampled for **cost**
in a batch (§13.5) but never disabled: the pinned summary is always written, and
the full trace is always reproducible from the pinned inputs (R-VIII-054).

### 10.2 Structure

Deliberately isomorphic with Part VII's `DecisionTrace` so one renderer serves
both, and so a rule that emits an action whose parameter is a formula composes
without a second vocabulary.

```
CalculationTrace {
  trace_id            uuid7
  tenant_id           bigint
  calculation_point   code
  formula             { code, version_id, version_no, checksum, name,
                        business_description, source_ref? }
  subject             { type, id, uuid, period_code }
  as_of               date
  engine_version      text            -- evaluator + SPX catalogue + type registry
  status              computed | error
  context_digest      char(64)
  result {
    type, unit, currency?
    exact_value       rational        -- num/den, before rounding
    rounded_value     minor units
    rounding          { mode, unit, delta_minor }   -- delta = rounded − exact
    clamp?            { kind: non_negative|min|max, applied: bool, bound }
  }
  steps               [ NodeTrace ]
  depends_on          [ { formula_code, version_id, trace_id, result } ]
  suppressions        [ { component_code, rule_code, rule_version_id } ]
  counters            { nodes, agg_calls, micros, memo_hits }
  error?              { code, node_path, message, symbol? }
  created_at
}

NodeTrace {
  op          text          -- "%", "/", "MIN", "SLAB", "salary.component", …
  span        [start,end]   -- offsets into expression_src, for highlighting
  result      typed value
  children    [ NodeTrace ]
  inputs      [ { symbol, value, provenance } ]     -- leaves only
  note?       text          -- e.g. "conversion WORKING→CALENDAR, policy DL-STD v2"
}
```

`provenance` on a leaf names where the value came from: the slice, the table and
row, the artefact version id that produced it, or the pack version and rule code
for a statutory value.

### 10.3 The substituted-values rendering

The renderer prints the expression **three times**: as authored, with symbols
substituted, and reduced — which is exactly how a payroll manager checks
arithmetic by hand.

```
How was this calculated?    HRA — August 2026 — HYD-0412 — ₹6,000.00

  FORMULA  F-HRA-PCT-BASIC  v3
           "House Rent Allowance is 40% of Basic."
           published 12-Mar-2026 · effective 01-Apr-2026 →
           scope: company = Ametecs South, grade = ALL   (specificity 100)

    authored     salary.component('BASIC') % policy.value('hra_pct_of_basic')

    substituted  ₹15,000.00                % 40
                 └─ BASIC, structure "Telangana Collections" v7,
                    component version 7, computed by F-BASIC-PCT-GROSS v2
                 └─ policy "hra_pct_of_basic" v1, effective 01-Apr-2026 →,
                    value 40 (Percentage)

    reduced      ₹15,000.00 × 40 / 100
                 = exact  600000/1 paise
                 = ₹6,000.00

    rounding     half_up to 0.01  ·  delta 0 paise
    clamp        non_negative — not binding

  INPUTS USED
    BASIC                    ₹15,000.00   ← F-BASIC-PCT-GROSS v2  [expand]
    policy hra_pct_of_basic  40           ← policy artefact v1

  VERSIONS PINNED ON THIS PAYSLIP LINE
    formula F-HRA-PCT-BASIC v3 · component HRA v7 ·
    structure "Telangana Collections" v7 · statutory pack IN-2026.2 · period 2026-08

  1 node · 0 aggregation calls · 0.03 ms · context digest 7f3a…c19b
```

**R-VIII-051** Every derived money value in SmartPRS 3 exposes this view. A
screen showing a derived amount without a trace link fails the UI conformance
sweep. *SmartPRS 2's only explanation mechanism is a free-text note that the
payslip then parses back with a regex, printing `daysInMonth / 0` whenever the
sentence is absent (`AppDataController.php:1278-1282`, DEF-P16).*

**R-VIII-052** The trace shows the **rounding delta in minor units** on every
line. An employee asking "why is this 1 paise less than 40% of my Basic?" gets
the answer from the payslip, not from a support ticket.

### 10.4 Composition — when A depends on B

Traces compose **by reference, not by inlining**. `depends_on` carries the
dependent trace's id and its result; the UI lazily expands it. This is what
keeps a 40-component structure's total trace payload linear rather than
quadratic, and it is why memoisation (§7.4) is visible: five references to one
formula produce five `depends_on` entries pointing at **one** trace.

```
payslip line NET  ₹41,376.30
  └─ F-NET  v2
      ├─ F-GROSS-TOTAL v3            ₹46,296.30   [expand]
      │   ├─ F-BASIC-PCT-GROSS v2    ₹23,148.15   [expand]
      │   │   └─ F-PRORATE-WORKING v5  factor 0.925926   [expand]
      │   │       └─ settlement.payable_days 25 / period.working_days 27
      │   │          ← period_settlement #88213, frozen 01-Sep-2026,
      │   │            attendance_generation_max 4
      │   ├─ F-HRA-PCT-BASIC v3      ₹9,259.26    [expand]
      │   └─ F-SPECIAL-BALANCE v4    ₹13,888.89   [expand]
      │       └─ ALLOCATE residue +1 paise → SPECIAL (declared residue head)
      └─ F-DEDUCTIONS-TOTAL v3       ₹4,920.00    [expand]
          ├─ F-PF-EMPLOYEE v6        ₹1,800.00  ← pack IN-2026.2, PF_EMPLOYEE_RATE
          ├─ F-PT-SLAB v4              ₹200.00  ← pack IN-TG-2026.1, slab PT_TG
          └─ F-TDS-MONTHLY v9        ₹2,920.00    [expand]
```

**R-VIII-053** A composed trace names, at every level, the **artefact version**
that produced the value and the **provenance** of every leaf. A payslip line
whose trace cannot name every version that produced it fails the CI conformance
sweep (R-VIII-040).

### 10.5 Storage, cost and reproduction

A single formula trace is 0.4–2 KB canonical JSON, 0.15–0.6 KB zstd-compressed.
A 40-component payslip with statutory heads is 25–60 KB compressed. For 10,000
employees that is **0.25–0.6 GB per monthly run**.

Two tiers, as for decision traces:

| Tier | Contents | Retention | Where |
|---|---|---|---|
| **Pinned** | formula version ids, result, rounding delta, context digest, trace uuid | Life of the payslip (`R-PERM`) | Columns on `payslip_lines` |
| **Full** | the complete `CalculationTrace` | 13 months, tenant-configurable upward | `calculation_traces`, zstd, monthly partitions |

**R-VIII-054** A full trace outside retention is **reproducible on demand** from
the pinned `context_digest`, the pinned versions and the run's input snapshot.
The "How was this calculated?" panel therefore works for a payslip of any age;
older ones simply take longer. *This is what makes a payslip defensible in a
labour dispute three years after it was issued — the capability SmartPRS 2 has
no mechanism for at all.*

**R-VIII-055** Traces carry derived values about an individual and are
classified **PII: Amber**. Field-level masking follows the same rules as the
source field; trace **read access is audited**; and no trace ever contains a
name, only a uuid the UI resolves under the reader's own data scope.

---

## 11. Worked formula library

### 11.0 The symbols these formulas use

Seven namespaces are added to the **shared** SPX registry by this part, on the
same terms as §5.1's types: registered once, available to the rule engine and
the formula engine alike, declared per calculation/decision point.

| Namespace | Symbols | Slice | Why it exists |
|---|---|---|---|
| `settlement.*` | `payable_days` `Days` · `scheduled_payable_days` `Days` · `absent_days` `Days` · `lop_days` `Days` · `present_days` `Days` · `leave_days('CODE')` `Days` · `weekly_off_days` `Days` · `holiday_days` `Days` · `ot_minutes` `Minutes` · `late_count` `Number` · `shortfall_minutes` `Minutes` · `compoff_earned` `Days` · `compoff_availed` `Days` · `generation` `Number` | `settlement.*` | The **frozen** payroll-facing view of a period (spine S10 layer 4). A formula never reads punches or attendance days |
| `ytd.*` | `gross` `Money` · `component('CODE')` `Money` · `statutory('CODE')` `Money` · `taxable_income` `Money` · `tax_deducted` `Money` · `periods_run` `Number` | `ytd.prior` | Enables a monthly TDS true-up, which SmartPRS 2 has none of (DEF-P23) |
| `input.*` | `value('CODE')` typed by the input definition · `recoveries_due` `Money` · `has('CODE')` `Boolean` | `variable.inputs` | Approved variable inputs for this period only |
| `claim.*` | `approved_amount` `Money` · `claimed_amount` `Money` · `type` `Code` · `incurred_on` `Date` | `variable.inputs` | The reimbursement or expense claim under evaluation |
| `loan.*` | `principal` `Money` · `instalment` `Money` · `outstanding` `Money` · `instalments_paid` `Number` · `instalments_total` `Number` · `rate` `Percentage` · `moratorium_periods` `Number` | `loan.terms` | A real schedule. *SmartPRS 2's `loans` table is missing every one of these columns (DEF-P02)* |
| `prior.*` | `line_amount('CODE')` `Money` · `gross` `Money` · `net` `Money` · `run_version_id` `Text` | `prior_run.snapshot` | The pinned original result, for arrears by difference |
| `formula.*` | `value('CODE')` typed by the referenced formula | — | Named sub-expression reuse; the edge that builds the dependency graph (§7.1) |

Three symbols are added to the existing `salary.*` namespace:

| Symbol | Type | Meaning |
|---|---|---|
| `salary.wage_base('<BASE_CODE>')` | `Money` | The **sum of every component whose declared flag for that base is true** — `PF`, `ESI`, `PT`, `BONUS`, `GRATUITY`, `OT`, `MIN_WAGE`, `TAXABLE`, `ENCASH`, `COMPOFF` |
| `salary.balance_pool` | `Money` | Gross target minus the sum of non-balance earning components already computed in topological order |
| `salary.net_after_statutory` | `Money` | Available only at `net.floor_adjustment` and later |

And two to `period.*`: `period.periods_per_year` `Number`,
`period.fin_year_start` / `period.fin_year_end` `Date`.

**R-VIII-056** `salary.wage_base('X')` reads the **per-component boolean flags**
`is_pf_base`, `is_esi_base`, `is_pt_base`, `is_lwf_base`, `is_bonus_wage`,
`is_gratuity_wage`, `is_ot_wage`, `is_minimum_wage_component`,
`is_encashment_base`, `is_taxable` (spine S12; the complete nineteen-flag set and
its spellings are owned by Part XV R-XV-007). **No
statutory or wage-base logic anywhere in SmartPRS 3 inspects a component's name,
and no such logic is expressible**, because `CONTAINS` may not be applied to a
`Code` (Part VII §5.6). *This single symbol replaces
`str_contains(lower($c['code'].' '.$c['name']), 'basic')` at
`AppDataController.php:2173`, the DA regex at `:2269`, the `reimburs` substring
at `:2186`, the HRA key test `stripos($name,'hra') || stripos($name,'rent')` at
`:2316`, and the ESI period lock's `stripos($k, 'esi')` at `:2036`.*

> **Legal-values discipline (spine S12).** Every statutory rate, ceiling, cap,
> slab and threshold below appears as `statutory.value('<CODE>')`, resolved from
> a signed rule pack. Where a numeric value is shown in a worked example it is
> **recorded as "the value SmartPRS 2's code currently uses"** and is not a
> statement of law. Verification against the primary statutory source is the
> named compliance owner's, before a pack is signed (spine D-03).

Every formula below shows: the calculation point, the result type, the rounding
declaration, the SPX source, the dependency set, and at least one worked example
in Indian notation with the exact paise arithmetic.

---

### 11.1 Structure — the earnings split

#### FL-01 · `F-BASIC-PCT-GROSS` — Basic as a percentage of gross

```
calculation_point  comp.earning          result_type  Money (INR)
rounding           half_up, 0.01         formula_kind component
depends_on         policy:basic_pct_of_gross
replaces           AppDataController.php:2103   ($basic = round($gross * 0.5, 2), a literal)
```

```spx
salary.gross % policy.value('basic_pct_of_gross')
```

**Worked — CTC ₹6,00,000, 12 periods, full attendance.**

```
salary.gross                    = ₹50,000.00        = 5,000,000 paise
policy basic_pct_of_gross       = 50 (Percentage)
exact                           = 5,000,000 × 50 / 100 = 2,500,000/1
rounded  half_up 0.01           = 2,500,000 paise   = ₹25,000.00     delta 0
```

**Worked — CTC ₹13,00,000, the rounding-drift case.**

```
salary.gross   = 130,000,000 / 12 = 10,833,333.333…  → half_up 0.01 → ₹1,08,333.33
BASIC          = 10,833,333 × 50 / 100 = 5,416,666.5 → half_up 0.01 → ₹54,166.67   delta +0.5 paise
```

The half-paise is now a **declared, traced rounding delta on BASIC**, not an
invisible drift that Special Allowance silently absorbs (`A-payroll.md` §4.1).
Invariant I2 still holds, because the balance component (FL-04) is defined as
the residue.

#### FL-02 · `F-BASIC-PCT-CTC` — Basic as a percentage of CTC

```
calculation_point  comp.earning          result_type  Money (INR)
rounding           half_up, 0.01
depends_on         policy:basic_pct_of_ctc
replaces           AppDataController.php:2225-2237  (pct_ctc accepted in a comment
                   and silently computed as percent-of-GROSS — A-payroll.md §5.2 item 2)
```

```spx
( salary.ctc / period.periods_per_year ) % policy.value('basic_pct_of_ctc')
```

**Worked — CTC ₹6,60,000 including employer PF of ₹60,000; monthly gross ₹50,000.**

```
period CTC   = 66,000,000 / 12 = 5,500,000 paise  = ₹55,000.00
BASIC        = 5,500,000 × 30 / 100 = 1,650,000   = ₹16,500.00

SmartPRS 2 for the same structure:  30% of GROSS ₹50,000 = ₹15,000.00
                                    → ₹1,500.00 per employee per month wrong,
                                      and wrong in every downstream PF, HRA and
                                      gratuity figure derived from Basic.
```

**This defect is not reproduced.** The two bases are distinct symbols with
distinct arithmetic, and `pct_of_ctc` is a first-class calculation kind.

#### FL-03 · `F-HRA-PCT-BASIC` — HRA as a percentage of Basic

```
calculation_point  comp.earning          result_type  Money (INR)
rounding           half_up, 0.01
depends_on         component:BASIC · policy:hra_pct_of_basic
replaces           AppDataController.php:2104   ($hra = round($basic * 0.4, 2), a literal)
```

```spx
salary.component('BASIC') % policy.value('hra_pct_of_basic')
```

**Worked — Basic ₹25,000.00, HRA percentage 40.**

```
BASIC     = 2,500,000 paise
exact     = 2,500,000 × 40 / 100 = 1,000,000/1
rounded   = ₹10,000.00                                       delta 0
```

**Worked — Basic ₹54,166.67 (the ₹13,00,000 CTC case).**

```
exact     = 5,416,667 × 40 / 100 = 2,166,666.8
rounded   half_up 0.01 = 2,166,667 paise = ₹21,666.67          delta +0.2 paise
```

Note that HRA reads the **rounded** Basic (R-VIII-024, D-VIII-01), so an
employee recomputing 40% of the Basic printed on the payslip arrives at the HRA
printed on the payslip.

#### FL-04 · `F-SPECIAL-BALANCE` — the residue component

```
calculation_point  comp.balance          result_type  List<Money> (INR)
rounding           half_up, 0.01         residue_head declared on the structure
depends_on         every non-balance earning component in the structure
replaces           AppDataController.php:2245-2262 (the one correct residue pattern
                   in SmartPRS 2 — spine S19.4 — here generalised)
```

```spx
ALLOCATE( salary.balance_pool, structure.balance_weights )
```

**Worked — one balance component, gross ₹1,08,333.33.**

```
non-balance earnings  BASIC ₹54,166.67 + HRA ₹21,666.67 = ₹75,833.34 = 7,583,334 paise
balance_pool          = 10,833,333 − 7,583,334          = 3,249,999 paise
ALLOCATE(3,249,999, [1])                                = [3,249,999]
SPECIAL                                                 = ₹32,499.99

check I2:  ₹54,166.67 + ₹21,666.67 + ₹32,499.99 = ₹1,08,333.33 = gross     ✓ exact
```

**Worked — three balance components, weights `[50, 30, 20]`** — the full
allocation is proved in §6.4, worked example 1:
`₹16,249.99 + ₹9,749.99 + ₹6,500.01 = ₹32,499.99`, with **2 paise** of residue
to the declared residue head, named in the trace.

*SmartPRS 2 reaches ₹32,499.99 too — but by drift that nothing declares, nothing
names and nothing asserts, and only when a component happens to be called
"Special Allowance" (`:2257`). Here it is an invariant.*

---

### 11.2 Overtime

#### FL-05 · `F-OT-RATE-HOURLY` — the hourly rate

```
calculation_point  ot.rate               result_type  Rate<Money/Hours> (INR/h)
rounding           none  (a rate is not rounded — R-VIII-027; rate_is_published = false)
depends_on         policy:ot_day_divisor · shift:hours · component flags is_ot_wage
replaces           MasterController.php:761  and  PayrollGenController.php:2165
                   — the SAME formula, duplicated in two files (A-payroll.md §5.3 Q8 #11, #13)
```

```spx
salary.wage_base('OT')
  / ( policy.value('ot_day_divisor') * shift.hours )
```

**Worked — Tenant A flags Basic and DA as OT wage. Basic+DA ₹25,000.00, divisor 26, shift 8h.**

```
wage base   = 2,500,000 paise
denominator = 26 × 8h = 208h
rate        = 2,500,000 / 208 = 12,019.2307…  paise per hour   (exact rational 2500000/208)
            = ₹120.1923…  per hour            NOT rounded
```

**Worked — Tenant B flags the whole gross. Gross ₹50,000.00, same divisor and shift.**

```
rate = 5,000,000 / 208 = 24,038.4615… paise/h = ₹240.3846…/h
```

which is exactly SmartPRS 2's `600000 / 12 / 26 / 8 = 240.384615…`. **The
difference is that `26`, `8` and the wage base are now three named, dated,
scoped configuration values instead of two literals in two files and an implicit
"CTC ÷ 12".**

#### FL-06 · `F-OT-AMOUNT` — overtime pay with a multiplier

```
calculation_point  ot.amount             result_type  Money (INR)
rounding           half_up, 0.01
depends_on         formula:F-OT-RATE-HOURLY · settlement.ot_minutes · policy:ot_multiplier_bands
replaces           MasterController.php:762 ; PayrollGenController.php:2165
```

```spx
formula.value('F-OT-RATE-HOURLY')
  * TO_HOURS(settlement.ot_minutes)
  * BANDS(day.day_type, 'OT-MULTIPLIER-BANDS')
```

`ot_multiplier_bands` is data, e.g. `working_day → 1.5`, `weekly_off → 2`,
`holiday → 2.5`. A flat multiplier is the degenerate one-band case.

**Worked — Tenant B, 480 OT minutes on a weekly off, multiplier 2.**

```
hours    = TO_HOURS(480m) = 8h
exact    = (5,000,000 / 208) × 8 × 2 = 5,000,000 × 16 / 208 = 80,000,000 / 208
         = 384,615.3846…  paise
rounded  half_up 0.01 = 384,615 paise = ₹3,846.15                delta −0.38 paise
```

Matching SmartPRS 2's ₹3,846.15 and the in-app guide's ₹3,846
(`CalcGuideController:130`) — reached this time through one formula, one wage
base and one rounding declaration, rather than three successive float divisions
in two duplicated copies.

**Worked — Tenant A (Basic+DA base), same 8 hours at 2×.**

```
exact   = 2,500,000 × 16 / 208 = 40,000,000 / 208 = 192,307.6923…
rounded = 192,308 paise = ₹1,923.08
```

---

### 11.3 LOP and proration — the five divisor bases

All five are `calculation_point: proration.factor`, `result_type: Number`
(a ratio in `[0, 1]`), `rounding: none` (`formula_kind = intermediate`,
`reference_value = exact` — the factor is never rounded before it multiplies an
amount, so `round($factor, 4)` for display never contaminates the arithmetic, as
it does not at `PayrollGenController.php:1453` either).

**Common worked scenario for FL-07 to FL-11.** July 2026: 31 calendar days,
4 Sundays (5, 12, 19, 26 July), so 27 working days under a Sunday-only weekly
off. Monthly gross ₹50,000.00. Two absent **working** days. No joiner, no leaver.

#### FL-07 · `F-PRORATE-WORKING`

```spx
MIN(1, settlement.payable_days / period.working_days)
```

```
= 25d / 27d  (both Days on day-length policy WORKING)   = 25/27 exact = 0.925926…
gross paid   = 5,000,000 × 25 / 27 = 125,000,000/27 = 4,629,629.629…
             → half_up 0.01 → 4,629,630 paise = ₹46,296.30
```

#### FL-08 · `F-PRORATE-CALENDAR`

```spx
MIN(1,
    ( period.calendar_days
      - TO_DAYS(settlement.absent_days, 'WORKING_TO_CALENDAR') )
    / period.calendar_days)
```

```
absent 2d (WORKING)  --TO_DAYS policy WORKING_TO_CALENDAR, ratio 1:1--> 2d (CALENDAR)
= (31d − 2d) / 31d = 29/31 exact = 0.935484…
gross paid = 5,000,000 × 29 / 31 = 145,000,000/31 = 4,677,419.354…
           → ₹46,774.19
```

**This is the formula that makes §5.3's defect visible.** SmartPRS 2 performs
this same arithmetic at `PayrollGenController.php:1268-1284` with **no
conversion at all** — a `Days` value on the working-day basis is subtracted from
a `Days` value on the calendar basis. Here the conversion is a named,
effective-dated policy artefact. A customer who believes two absent working days
should cost 2/31 of gross gets exactly that, and now knows they chose it. A
customer who believes it should cost `2 × 31/27 = 2.30` calendar days changes
`WORKING_TO_CALENDAR` from `1:1` to `31:27` and the trace shows the conversion
step.

#### FL-09 · `F-PRORATE-FIXED30`

```spx
MIN(1,
    ( TO_DAYS(policy.value('fixed_day_divisor'), 'FIXED')
      - TO_DAYS(settlement.absent_days, 'WORKING_TO_FIXED') )
    / TO_DAYS(policy.value('fixed_day_divisor'), 'FIXED'))
```

```
divisor 30:  (30 − 2) / 30 = 28/30 = 0.933333…
gross paid = 5,000,000 × 28 / 30 = 140,000,000/30 = 4,666,666.67 → ₹46,666.67
```

#### FL-10 · `F-PRORATE-FIXED26`

Identical expression with `fixed_day_divisor = 26`:

```
(26 − 2) / 26 = 24/26 = 0.923077…
gross paid = 5,000,000 × 24 / 26 = 120,000,000/26 = 4,615,384.6… → ₹46,153.85
one absent day therefore costs 5,000,000 / 26 = ₹1,923.08
```

**SmartPRS 2 does not offer a 26-day salary basis at all** — `26` appears in
exactly three places, all overtime or gratuity — **while its own in-app FAQ
headlines the question *"Why does one absent day cut gross ÷ 26 and not
gross ÷ 30?"*** (`CalcGuideController:161`). The guide describes a feature the
engine does not have. Here it is a basis like any other.

#### FL-11 · `F-PRORATE-PAYABLE` — **the recommended default**

```spx
MIN(1, settlement.payable_days / settlement.scheduled_payable_days)
```

Both operands come from the **same frozen settlement**, on the **same
day-length policy**. It is the only basis that needs no conversion and the only
one in which the numerator and denominator cannot disagree — which is precisely
the property §5.3's defect is the absence of.

| Case | scheduled | payable | factor | gross paid |
|---|---|---|---|---|
| Weekly offs **paid**, 2 LOP days | 31d | 29d | 29/31 | ₹46,774.19 |
| Weekly offs **unpaid**, 2 LOP days | 27d | 25d | 25/27 | ₹46,296.30 |
| Mid-month joiner, cut-off cycle (FL-13) | 20d | 20d | 1 | full for the window |

The basis is chosen per company / grade / pay group / employee by scope
(spine S3.1), not by one tenant-wide setting as at
`PayrollGenController.php:1108-1111`, where anything outside the three-value
whitelist **silently falls back to `working`**. Here an unresolvable basis is a
publish-time error.

#### FL-12 · `F-LOP-AMOUNT` — the deduction, per component

```
calculation_point  proration.lop_amount   result_type  Money (INR)
rounding           half_up, 0.01
depends_on         the component's own full amount and prorated amount
```

```spx
component.full_amount - component.prorated_amount
```

**Worked — factor 25/27 (FL-07), the default structure.**

| Component | Full | Prorated (exact) | Prorated | LOP |
|---|---|---|---|---|
| BASIC | ₹25,000.00 | 62,500,000/27 = 2,314,814.81 | ₹23,148.15 | ₹1,851.85 |
| HRA | ₹10,000.00 | 25,000,000/27 = 925,925.93 | ₹9,259.26 | ₹740.74 |
| SPECIAL | ₹15,000.00 | 37,500,000/27 = 1,388,888.89 | ₹13,888.89 | ₹1,111.11 |
| **Total** | **₹50,000.00** | | **₹46,296.30** | **₹3,703.70** |

```
Invariant I5:  ₹50,000.00 − ₹46,296.30 = ₹3,703.70 = SUM(LOP lines)     ✓ exact
```

The presentation choice — reduce the component lines, or print full lines plus a
single LOP deduction — is `lop_presentation` on the structure. **The arithmetic
is identical either way**, and I5 holds in both. *SmartPRS 2 has neither: it
multiplies the annual CTC by a dimensionless factor and never produces a
per-component LOP figure at all, which is why the payslip has to recover Paid
Days and LOP from prose with a regex (DEF-P16).*

#### FL-13 · `F-PRORATE-BOUNDARY` — joiner and leaver under a cut-off cycle

```
calculation_point  proration.factor       result_type  Days (scheduled payable days)
rounding           none  (intermediate)
depends_on         period.start · period.end · employee.joining_date · employee.exit_date
replaces           PayrollGenController.php:1216-1228  (joiner; wrong under a cut-off — DEF-P05)
                   — and there is NO leaver proration in SmartPRS 2 at all
                     (A-payroll.md §5.2 item 18: nothing reads a date of leaving)
```

```spx
PAYABLE_DAYS( MAX(period.start, employee.joining_date),
              MIN(period.end,   COALESCE(employee.exit_date, period.end)) )
```

**Scenario.** Company on a cut-off cycle, `cut_off_day = 25`. The July 2026
payroll period therefore runs **26-Jun-2026 → 25-Jul-2026**: 30 calendar days
(5 in June, 25 in July). Monthly gross ₹50,000.00, calendar basis.

**Worked — joiner, joined 06-Jul-2026.**

```
window            = MAX(26-Jun, 06-Jul) → MIN(25-Jul, 25-Jul) = 06-Jul … 25-Jul = 20d
scheduled_payable = 20d      full-period scheduled = 30d
factor            = 20/30
gross paid        = 5,000,000 × 20 / 30 = 100,000,000/30 = 3,333,333.33 → ₹33,333.33
```

*SmartPRS 2 compares `doj->format('Y-m')` against the month label, sees a match,
and then counts working days from day 6 to day 31 **of July** — a window that
starts ten days after the period does and ends six days after the period ends.
The period boundary is never consulted (`:1216-1228`, DEF-P05).*

**Worked — leaver, exit 10-Jul-2026.**

```
window     = 26-Jun … 10-Jul = 15d
factor     = 15/30
gross paid = 5,000,000 × 15 / 30 = ₹25,000.00
```

**Worked — joiner and leaver in the same period: joined 30-Jun, exit 10-Jul.**

```
window     = 30-Jun … 10-Jul = 11d
factor     = 11/30
gross paid = 5,000,000 × 11 / 30 = 55,000,000/30 = 1,833,333.33 → ₹18,333.33
```

One expression covers all three, because it is written as the **intersection of
the employment window with the period window** rather than as a month-relative
special case.

---

### 11.4 Encashment

#### FL-14 · `F-LEAVE-ENCASH` — leave encashment

```
calculation_point  leave.encashment       result_type  Money (INR)
rounding           half_up, 0.01
depends_on         wage base ENCASH · policy:encash_day_divisor · policy:encash_max_days
                 · ledger LEAVE-EL
```

```spx
( salary.wage_base('ENCASH')
  / TO_DAYS(policy.value('encash_day_divisor'), 'ENCASH_DIVISOR') )
* MIN( agg.balance('LEAVE-EL', period.end),
       TO_DAYS(policy.value('encash_max_days'), 'ENCASH_DIVISOR') )
```

Types: `Money / Days → Rate<Money/Days>`, then `Rate × Days → Money`. The
per-day value cannot escape as a bare number.

**Worked — encashment wage base Basic+DA ₹25,000.00, divisor 30, balance 12.5 days, cap 30 days.**

```
rate     = 2,500,000 / 30 = 83,333.333…  paise per day        (not rounded)
days     = MIN(12.5d, 30d) = 12.5d
exact    = 2,500,000 × 12.5 / 30 = 31,250,000/30 = 1,041,666.67
rounded  half_up 0.01 = 1,041,667 paise = ₹10,416.67          delta +0.33 paise
```

**Worked — a half-day balance, 12.5d → 0.5d.** `2,500,000 × 0.5 / 30 = 41,666.67
→ ₹416.67`. *Half-day units are expressible because `Days` has scale 4 and
`DayFraction` exists; SmartPRS 2 counts every leave day as exactly `1.0`
(`paidLeaveDays():1077`), so a half day is not representable at all.*

#### FL-15 · `F-COMPOFF-ENCASH` — compensatory-off encashment

```
calculation_point  compoff.encashment     result_type  Money (INR)
rounding           half_up, 0.01
depends_on         wage base COMPOFF · policy:compoff_day_divisor
                 · policy:compoff_encash_max_days · policy:compoff_encash_multiplier
                 · ledger COMPOFF
```

```spx
( salary.wage_base('COMPOFF')
  / TO_DAYS(policy.value('compoff_day_divisor'), 'COMPOFF_DIVISOR') )
* MIN( agg.balance('COMPOFF', period.end),
       TO_DAYS(policy.value('compoff_encash_max_days'), 'COMPOFF_DIVISOR') )
* policy.value('compoff_encash_multiplier')
```

**Worked — base gross ₹50,000.00, divisor 26, balance 3 days, cap 5, multiplier 1.**

```
exact    = 5,000,000 × 3 / 26 = 15,000,000/26 = 576,923.0769…
rounded  = 576,923 paise = ₹5,769.23
```

**Worked — multiplier 2 (a customer who pays comp-off at double).**
`5,000,000 × 6 / 26 = 1,153,846.15 → ₹11,538.46`. The multiplier is a policy
value, not a code change.

---

### 11.5 Terminal payments

#### FL-16 · `F-GRATUITY`

```
calculation_point  terminal.gratuity      result_type  Money (INR)
rounding           DECLARED — see the two cases below
depends_on         wage base GRATUITY · pack:GRATUITY_NUMERATOR_DAYS
                 · pack:GRATUITY_DENOMINATOR_DAYS · pack:GRATUITY_CEILING_AMOUNT
replaces           FOUR disagreeing implementations:
                     StatutoryController.php:54-57      (whole rupees, diffInDays/365.25 then floor)
                     ReportController.php:264-275       (2 dp, diffInYears, Basic re-derived inline)
                     payslipEmployerCost():1543         (4.81% monthly accrual — unrelated formula)
                     — A-payroll.md §4.13, DEF-P25
```

Eligibility (the minimum-service cliff) is a **rule** at
`payroll.component.eligibility` emitting `SUPPRESS_COMPONENT`, not a branch
inside the formula (R-VIII-002). The formula is pure arithmetic:

```spx
MIN(
  salary.wage_base('GRATUITY')
    * ( statutory.value('GRATUITY_NUMERATOR_DAYS')
        / statutory.value('GRATUITY_DENOMINATOR_DAYS') )
    * COMPLETED_YEARS(employee.exit_date),
  statutory.value('GRATUITY_CEILING_AMOUNT')
)
```

**Worked — gratuity wage base (Basic+DA) ₹25,000.00, service 7.4 years.**

Pack values below are `<from rule pack>` placeholders shown at **the values
SmartPRS 2's code currently uses** — `15`, `26`, no ceiling (`StatutoryController:57`).
They are not asserted as legally correct.

```
COMPLETED_YEARS(exit)  = 7                    (ONE definition — Part VII §5.6;
                                               replaces diffInDays/365.25→floor
                                               vs diffInYears, which disagree at the boundary)
exact = 2,500,000 × 15 / 26 × 7
      = 2,500,000 × 105 / 26
      = 262,500,000 / 26
      = 10,096,153.846…  paise

rounding half_up, unit 0.01  →  10,096,154 paise = ₹1,00,961.54
rounding half_up, unit 1.00  →     100,962 rupees = ₹1,00,962.00
ceiling  MIN(…, <GRATUITY_CEILING_AMOUNT>)   — not binding at this value
```

Those are **exactly** the two numbers SmartPRS 2 produces from its two reports
for the same employee, 46 paise apart, with no way to tell which the customer
intended. In SmartPRS 3 the tenant declares one rounding on one formula, and
both the provision report and the full-and-final statement call it.

#### FL-17 · `F-BONUS-STATUTORY` — statutory bonus

```
calculation_point  terminal.bonus         result_type  Money (INR)
rounding           half_up, 1.00  (whole rupee, as declared by the pack)
depends_on         wage base BONUS · pack:BONUS_WAGE_CAP · pack:BONUS_PERCENT
                 · period.fin_year_start/end
replaces           StatutoryController.php:119-152
```

```spx
MIN( salary.wage_base('BONUS'), statutory.value('BONUS_WAGE_CAP') )
  * CLAMP( DATEDIFF( MAX(period.fin_year_start, employee.joining_date),
                     DATEADD( COALESCE(employee.exit_date, period.fin_year_end),
                              1, 'days' ),
                     'months' ),
           0, 12 )
  % statutory.value('BONUS_PERCENT')
```

**Worked — bonus wage base ₹18,000.00, joined 01-Aug-2026, financial year 01-Apr-2026 → 31-Mar-2027.**

Pack values at those SmartPRS 2's code currently uses: eligibility ceiling
₹21,000 (applied by an eligibility **rule**), wage cap ₹7,000, percentage 8.33
clamped to `[8.33, 20]`.

```
wage    = MIN(₹18,000.00, ₹7,000.00)                        = 700,000 paise
months  = DATEDIFF(01-Aug-2026, 01-Apr-2027, 'months') = 8   → CLAMP(8, 0, 12) = 8
exact   = 700,000 × 8 × 8.33 / 100 = 5,600,000 × 8.33/100    = 466,480 paise = ₹4,664.80
rounded half_up 1.00                                          = ₹4,665.00
```

Matching SmartPRS 2's ₹4,665 — but the month count is **whole calendar months**,
not `ceil(diffInDays / 30.44)` (`StatutoryController:123`), so it does not
produce 8 for a 7.98-month service by accident of a fractional constant. The
minimum-wage comparison the product's own UI text promises — and which
`StatutoryController` never makes (`F-statutory-compliance.md` §4.13) — is a
declared alternative wage base:
`MAX(salary.wage_base('BONUS'), statutory.value('MIN_WAGE_FOR_SCOPE'))`,
enabled per scope.

---

### 11.6 Statutory heads

#### FL-18 · `F-PT-SLAB` — a slab-driven deduction

```
calculation_point  statutory.head         result_type  Money (INR)
rounding           half_up, 1.00
depends_on         wage base PT · pack:PT_SLAB_TABLE_CODE (resolved per establishment state)
replaces           ptForGross():1925-2000 + ptWalkSlab():1897-1916
                   — 16 state tables and 10 PT-free states as `private const` arrays,
                     matched by str_contains on a free-text state name
```

```spx
SLAB( salary.wage_base('PT'),
      statutory.value('PT_SLAB_TABLE_CODE'),
      period.end )
```

The slab table is **pack content**, declared `flat` (a band amount, not a
marginal walk), resolved from the employee's **establishment**, by structured
state code — never by `str_contains(strtolower($state), $key)` at `:1950-1984`,
where "Andhra Pradesh" and "Andhra" are the same key by luck and a renamed state
silently falls through to a hard-coded fallback list that is itself unreachable
(NEW-PAY-18).

**Worked — Telangana, PT gross ₹22,500.00.** Table `PT-TG` at *the values
SmartPRS 2's code currently uses* (`AppDataController:1854-1871`, which the
source itself labels *"Indicative defaults (verify against current state law
before filing)"* at `:1847-1848`):

```
bands   ≤ ₹15,000 → ₹0   ·   ≤ ₹20,000 → ₹150   ·   above → ₹200
₹22,500 → band 3                                  → ₹200.00
```

**Worked — the Maharashtra February uplift, as slab data rather than an `if`.**
The band carries `period_override: { month: 02, amount: ₹300 }`:

```
MH, PT gross ₹15,000, February 2027   → band "above ₹10,000" = ₹200
                                       → period_override month 02 → ₹300.00
MH, PT gross ₹15,000, March 2027                                  → ₹200.00
MH, PT gross  ₹9,000, February 2027   → band ≤ ₹10,000 = ₹175, no override → ₹175.00
```

*Replacing `if ($key === 'maharashtra' && $result >= 200 && substr($month,5,2) === '02')`
at `:1911-1913` — a state name and a magic threshold, in PHP.* Non-monthly
periodicity (half-yearly Tamil Nadu and Kerala; annual caps in Madhya Pradesh
and Maharashtra) is a declared `periodicity` attribute on the table, so the
annual figure is not `monthly × 12` as at `StatutoryController:95-98`, where
Tamil Nadu's ₹208 monthly equivalent is reported as ₹2,496 a year.

The Maharashtra female exemption becomes an **attribute-based exemption
predicate** on the table (`F-statutory-compliance.md` §10 item 16), evaluated as
a rule at `payroll.component.eligibility` — not a lowercase word list
`['female','f','woman','women']` scoped to one state name at `:1936-1941`.

#### FL-19 · `F-PF-EMPLOYEE` — a percentage of wage with a ceiling

```
calculation_point  statutory.head         result_type  Money (INR)
rounding           half_up, 1.00  (as declared by the pack)
depends_on         wage base PF · pack:PF_WAGE_CEILING · pack:PF_EMPLOYEE_RATE
replaces           statutory():2064-2070
```

```spx
MIN( salary.wage_base('PF'), statutory.value('PF_WAGE_CEILING') )
  % statutory.value('PF_EMPLOYEE_RATE')
```

**Worked — components flagged `is_pf_wage`: BASIC ₹20,000.00 + DA ₹2,000.00.**
Pack values at those SmartPRS 2's code currently uses: ceiling ₹15,000, rate 12.

```
wage base  = 2,000,000 + 200,000 = 2,200,000 paise = ₹22,000.00
base       = MIN(2,200,000, 1,500,000)            = 1,500,000 paise
exact      = 1,500,000 × 12 / 100                 =   180,000 paise
rounded    half_up 1.00                            = ₹1,800.00        delta 0
```

The wage base is the **sum of components carrying the `is_pf_wage` flag**. It is
not `$basic` in one code path and `$basic + $da` in another
(`:2107` vs `:2273`), and DA is not identified by
`preg_match('/(^|[^a-z])da([^a-z]|$)/', $name)`.

#### FL-20 to FL-23 · Employer contribution split across sub-heads

```
calculation_point  comp.employer_contribution      residue_head  PF-ER-EPF
replaces           statutory():2071-2073
```

**FL-20 · `F-PF-EMPLOYER-TOTAL`** — the parent amount:

```spx
MIN( salary.wage_base('PF'), statutory.value('PF_WAGE_CEILING') )
  % statutory.value('PF_EMPLOYER_RATE')
```

**FL-21 · `F-PF-ER-EPS`** — a sub-head with its **own** ceiling and its **own**
monetary cap:

```spx
MIN(
  MIN( salary.wage_base('PF'), statutory.value('EPS_WAGE_CEILING') )
    % statutory.value('EPS_RATE'),
  statutory.value('EPS_MONTHLY_CAP')
)
```

**FL-22 · `F-PF-ER-EPF`** — **the declared residue head** (R-VIII-028):

```spx
formula.value('F-PF-EMPLOYER-TOTAL') - formula.value('F-PF-ER-EPS')
```

**FL-23 · `F-PF-ER-EDLI`** — a separate head with its own ceiling, outside the
parent split:

```spx
MIN( salary.wage_base('PF'), statutory.value('EDLI_WAGE_CEILING') )
  % statutory.value('EDLI_RATE')
```

**Worked — PF wage ₹22,000.00.** Pack values at those SmartPRS 2's code
currently uses: `PF_WAGE_CEILING` ₹15,000 · `PF_EMPLOYER_RATE` 12 ·
`EPS_WAGE_CEILING` ₹15,000 · `EPS_RATE` 8.33 · `EPS_MONTHLY_CAP` ₹1,250 ·
`EDLI_WAGE_CEILING` ₹15,000 · `EDLI_RATE` 0.5.

```
base                 = MIN(2,200,000, 1,500,000)       = 1,500,000 paise
EMPLOYER TOTAL       = 1,500,000 × 12 / 100  = 180,000 = ₹1,800.00

EPS  exact           = 1,500,000 × 8.33 / 100          =   124,950 paise = ₹1,249.50
     pack declares   rounding half_up 1.00 · cap_order = round_then_cap
       round         ₹1,249.50 → ₹1,250.00
       cap           MIN(₹1,250.00, ₹1,250.00)         = ₹1,250.00
EPF  (residue head)  = ₹1,800.00 − ₹1,250.00           =   ₹550.00
EDLI                 = 1,500,000 × 0.5 / 100  = 7,500  =    ₹75.00

Invariant I6:  EPS ₹1,250.00 + EPF ₹550.00 = ₹1,800.00 = employer total   ✓ exact
```

**The order matters, and SmartPRS 3 makes it a declaration rather than an
accident:**

| Declared order and rounding | EPS | EPF (residue) | Cap binds? |
|---|---|---|---|
| `round_then_cap`, half_up ₹1 | ₹1,250.00 | ₹550.00 | at the boundary |
| `cap_then_round`, half_up ₹1 | ₹1,250.00 | ₹550.00 | no |
| `cap_then_round`, half_up ₹0.01 (**SmartPRS 2's behaviour**) | ₹1,249.50 | ₹550.50 | **never** |

*`min(round($epsBase * 8.33 / 100, 2), 1250.0)` at `AppDataController.php:2071`
takes the third row: `round(…, 2)` yields ₹1,249.50, so the declared ₹1,250 cap
is dead code and nobody knows it (DEF-P08).* **SmartPRS 3 does not decide which
row is legally correct.** It requires the pack to say which, records the answer
in the signed pack with its `source_reference`, and shows both steps in the
trace. The value and its authority belong to the named compliance owner
(spine D-03).

---

### 11.7 Arrears

#### FL-24 · `F-ARREAR-COMPONENT` — a back-dated increment

```
calculation_point  arrear.amount          result_type  Money (INR)
rounding           half_up, 0.01
depends_on         prior_run.snapshot · the recomputation of the target period
replaces           IncrementController.php:366-376  (a private proration model:
                   calendar-day first month regardless of lop_basis, whole months
                   after, posted as an untaxed commission with tds_rate = 0)
```

The method is **recompute and difference**, per component, per target period —
not a synthetic day fraction on a monthly delta:

```spx
component.recomputed_amount - prior.line_amount(component.code)
```

`component.recomputed_amount` is the target period's pipeline re-run against the
**new** effective-dated compensation row, at the target period's **own** resolved
structure version, pack version and proration basis (spine S11 input snapshot).
`prior.line_amount` reads the pinned original run.

**Worked — CTC ₹6,00,000 → ₹7,20,000, effective 15-May-2026, processed in the July 2026 run.**
May 2026 has 31 days; the company is on the calendar basis.

```
Compensation now has TWO effective-dated rows in May:
    ₹50,000.00 / month   01-May → 14-May      (14 days)
    ₹60,000.00 / month   15-May → open        (17 days)

recomputed May gross
  = 5,000,000 × 14/31  +  6,000,000 × 17/31
  = 70,000,000/31      +  102,000,000/31
  = 172,000,000/31 = 5,548,387.096…  →  half_up 0.01  →  ₹55,483.87

original May gross (pinned run)                                    =  ₹50,000.00
MAY ARREAR on gross                                                =   ₹5,483.87

June: 6,000,000 − 5,000,000                                        =  ₹10,000.00
                                                                     -----------
TOTAL GROSS ARREAR                                                   ₹15,483.87
```

The total matches SmartPRS 2's ₹15,483.87 — reached by a defensible route rather
than by `($eff->daysInMonth - $eff->day + 1) / $eff->daysInMonth` hard-coded to
calendar days at `IncrementController:369`. Three differences that matter:

1. **Every component is differenced, not just gross.** PF, ESI, PT and TDS on
   the arrear are recomputed per the pack's declared treatment of arrear
   components. *SmartPRS 2 posts the whole arrear as a commission with
   `tds_rate = 0` (`:400-405`), outside every statutory base
   (BR-STAT-006, OQ-10).*
2. **The first month uses the period's own declared proration basis**, not
   always calendar. *`:369` ignores `lop_basis` entirely.*
3. **A back-dated *decrease* produces a negative arrear** — a recovery, subject
   to the minimum-net floor (FL-28). *`IncrementController:362` fires only when
   `newCtc > oldCtc`, so an overpayment is never recovered (OQ-9).*

```
Invariant I7:  SUM(arrear lines for May) = recomputed(May, new) − original(May)
               line for line, per component                              ✓ asserted
```

---

### 11.8 Variable pay

#### FL-25 · `F-COMMISSION-SLAB` — slab with accelerator and cap

```
calculation_point  commission.payout      result_type  Money (INR)
rounding           half_up, 0.01
depends_on         input:COLLECTIONS · input:TARGET · policy:comm_bands
                 · policy:comm_accelerator_threshold · policy:comm_accelerator_points
                 · policy:comm_monthly_cap
replaces           IncentiveController::payoutFor():299-344
```

```spx
MIN(
  input.value('COLLECTIONS')
    % ( BANDS( input.value('COLLECTIONS'), 'COMM-RATE-BANDS' )
        + IF( MONEY_AS_NUMBER(input.value('COLLECTIONS'))
                / MONEY_AS_NUMBER(input.value('TARGET'))
              >= policy.value('comm_accelerator_threshold'),
              policy.value('comm_accelerator_points'),
              0 ) ),
  policy.value('comm_monthly_cap')
)
```

`comm_bands` is policy data: `[₹0, ₹2,00,000) → 1` · `[₹2,00,000, ₹5,00,000) → 2` ·
`[₹5,00,000, ∞) → 3`, validated at publish as sorted, contiguous, non-overlapping
with a terminal open band (gate G7). `MONEY_AS_NUMBER` on both ratio operands is
deliberately verbose (Part VII §5.6) and raises a lint note, which is the point:
a money-to-money ratio should be conspicuous.

**Worked 1 — collections ₹6,00,000.00, target ₹4,00,000.00, accelerator threshold 1.2, +0.5 points, cap ₹25,000.**

```
band            ₹6,00,000 ∈ [₹5,00,000, ∞)              → 3
achievement     60,000,000 / 40,000,000 = 1.5  ≥ 1.2     → +0.5
rate                                                      = 3.5 (Percentage)
exact           60,000,000 × 3.5 / 100 = 2,100,000        = ₹21,000.00
cap             MIN(₹21,000.00, ₹25,000.00)               = ₹21,000.00
```

**Worked 2 — the cap binds. Collections ₹9,00,000.00.**

```
rate 3.5   →   90,000,000 × 3.5 / 100 = 3,150,000 = ₹31,500.00
cap        →   MIN(₹31,500.00, ₹25,000.00)        = ₹25,000.00
```

**Worked 3 — SmartPRS 2 parity: no accelerator, no cap, collections ₹6,00,000.**
`60,000,000 × 3/100 = ₹18,000.00`, matching `A-payroll.md` §4.12.

**Two defects not reproduced.** SmartPRS 2 initialises the rate to **the last
band's rate before the loop runs**, and its band guard `($upto > 0 && $figure <= $upto)`
is false for the terminal band, so the top rate is reached by fall-through
rather than by matching — meaning a malformed band table silently pays the top
rate. And band order is never validated (DEF-P22). Gate G7 blocks both.

#### FL-26 · `F-ATT-INCENTIVE` — an attendance incentive

```
calculation_point  incentive.payout       result_type  Money (INR)
rounding           half_up, 1.00
depends_on         settlement.late_count · policy:att_incentive_bands
```

Eligibility is a **rule**, not a branch in the formula (R-VIII-002):

```
RULE  RL-PAY-ATTINC   decision point payroll.component.eligibility
  WHEN  settlement.lop_days = 0d AND settlement.present_days > 0d
  THEN  ADD_COMPONENT('ATT-INC', formula 'F-ATT-INCENTIVE')
```

The formula is the tiering:

```spx
BANDS( settlement.late_count, 'ATT-INCENTIVE-BANDS' )
```

`att_incentive_bands` = `[0, 3) → ₹2,000.00` · `[3, 6) → ₹1,000.00` ·
`[6, NULL) → ₹0.00`.

| Late count | LOP days | Rule | Formula | Paid |
|---|---|---|---|---|
| 2 | 0 | matches | band 1 | **₹2,000.00** |
| 4 | 0 | matches | band 2 | **₹1,000.00** |
| 7 | 0 | matches | band 3 | **₹0.00** — the line prints at zero, which is deliberate: the employee sees they qualified for the scheme and scored nothing |
| 2 | 1.5 | does not match | not evaluated | no line at all |

*SmartPRS 2 has no attendance-incentive concept; the nearest thing is the late
policy's day-cut, which is a penalty and lives inside the payroll engine at
`:1242-1256` with its tiers as columns on a `late_policies` row.*

#### FL-27 · `F-REIMB-CAP` — a reimbursement with a monthly cap

```
calculation_point  reimbursement.payable  result_type  Money (INR)
rounding           half_up, 0.01
depends_on         claim.approved_amount · policy:reimb_monthly_cap
                 · policy:reimb_carry_forward · ledger REIMB-CARRY
```

```spx
MIN( claim.approved_amount,
     policy.value('reimb_monthly_cap')
       + IF( policy.value('reimb_carry_forward'),
             agg.balance('REIMB-CARRY', period.start),
             MONEY(0, 'INR') ) )
```

| Case | Claim | Cap | Carry in | Available | **Paid** | Carry out |
|---|---|---|---|---|---|---|
| A · carry off | ₹2,400.00 | ₹1,600.00 | — | ₹1,600.00 | **₹1,600.00** | — |
| B · carry on | ₹2,400.00 | ₹1,600.00 | ₹800.00 | ₹2,400.00 | **₹2,400.00** | ₹0.00 |
| C · carry on, under-claim | ₹1,000.00 | ₹1,600.00 | ₹0.00 | ₹1,600.00 | **₹1,000.00** | ₹600.00 |

The **ledger posting is a rule action** (`CREATE_LEDGER_ENTRY`), not part of the
formula: a formula returns a value and mutates nothing (R-VIII-001). In case A
the unpaid ₹800 is named in the trace as `capped, excess ₹800.00 not carried
(policy reimb_carry_forward = false)` rather than vanishing.

*SmartPRS 2 identifies a reimbursement by `category === 'reimbursement'` **or the
substring `reimburs` in the name** (`:2181-2188`), has no cap concept, no carry
forward, and no claim entity.*

---

### 11.9 Net pay

#### FL-28 · `F-NET-FLOOR-DEFER` — a minimum-net-pay floor adjustment

```
calculation_point  net.floor_adjustment   result_type  Money (INR)
rounding           half_up, 0.01
depends_on         salary.gross · salary.net_after_statutory · input.recoveries_due
                 · policy:min_net_absolute · policy:min_net_pct_of_gross
```

```spx
MAX( MONEY(0, 'INR'),
     MAX( policy.value('min_net_absolute'),
          salary.gross % policy.value('min_net_pct_of_gross') )
     - ( salary.net_after_statutory - input.recoveries_due ) )
```

The formula returns **how much recovery must be deferred**. Which recoveries are
deferred, and in what order, is the payroll pipeline's `recovery_order`
configuration — a sequence, not an arithmetic (§3.3).

**Worked — gross ₹50,000.00; net after statutory ₹38,000.00; recoveries due
₹33,800.00 (loan EMI ₹25,000.00 + advance ₹8,800.00). Floor: the greater of
₹5,000 and 30% of gross.**

```
floor            = MAX(₹5,000.00, 5,000,000 × 30/100 = ₹15,000.00)   = ₹15,000.00
net if all applied = 3,800,000 − 3,380,000 = 420,000                 =  ₹4,200.00
deferral         = MAX(₹0.00, 1,500,000 − 420,000 = 1,080,000)       = ₹10,800.00

pipeline applies the deferral bottom-up through recovery_order:
    advance    ₹8,800.00  deferred in full        → recovered ₹0.00
    loan       ₹2,000.00  deferred (partial)      → recovered ₹23,000.00
                                                    -----------------
    recoveries applied  = ₹23,000.00
    NET                 = ₹38,000.00 − ₹23,000.00 = ₹15,000.00   = the floor  ✓
```

Four SmartPRS 2 behaviours this replaces, all hard-coded
(`A-payroll.md` §5.2 items 38–42):

| SmartPRS 2 | Citation | SmartPRS 3 |
|---|---|---|
| Only `net ≥ 0` is enforced; no floor of any kind exists | `:1393` | A declared floor, absolute or a percentage of gross, scoped |
| Recovery order is fixed statutory → loan → advance → clawback | `:1350-1389` | `recovery_order` configuration |
| **Full-EMI-only**: an instalment that does not fit is skipped entirely, and the fit test is a **float comparison** | `:1357` | Partial recovery is a declared per-loan-type shortfall policy; the comparison is on integer paise |
| An advance shortfall is silently dropped, never carried forward | DEF-P19 | A shortfall posts to the recovery ledger and carries per the declared policy |

---

### 11.10 Coverage summary

| # | Formula | Calculation point | Replaces (SmartPRS 2) |
|---|---|---|---|
| FL-01 | `F-BASIC-PCT-GROSS` | `comp.earning` | `:2103` literal 0.5 |
| FL-02 | `F-BASIC-PCT-CTC` | `comp.earning` | `:2225-2237` `pct_ctc` silently = `pct_gross` |
| FL-03 | `F-HRA-PCT-BASIC` | `comp.earning` | `:2104` literal 0.4 |
| FL-04 | `F-SPECIAL-BALANCE` | `comp.balance` | `:2245-2262` |
| FL-05 | `F-OT-RATE-HOURLY` | `ot.rate` | `MasterController:761` + `PayrollGen:2165` (duplicated) |
| FL-06 | `F-OT-AMOUNT` | `ot.amount` | `MasterController:762` |
| FL-07 | `F-PRORATE-WORKING` | `proration.factor` | `:1286` |
| FL-08 | `F-PRORATE-CALENDAR` | `proration.factor` | `:1268-1284` (the unit-confusion defect) |
| FL-09 | `F-PRORATE-FIXED30` | `proration.factor` | `:1268-1284` |
| FL-10 | `F-PRORATE-FIXED26` | `proration.factor` | **nothing** — SmartPRS 2 does not offer it |
| FL-11 | `F-PRORATE-PAYABLE` | `proration.factor` | **nothing** — not implemented |
| FL-12 | `F-LOP-AMOUNT` | `proration.lop_amount` | **nothing** — no per-component LOP exists |
| FL-13 | `F-PRORATE-BOUNDARY` | `proration.factor` | `:1216-1228` (joiner, wrong under cut-off); leaver absent |
| FL-14 | `F-LEAVE-ENCASH` | `leave.encashment` | **nothing** |
| FL-15 | `F-COMPOFF-ENCASH` | `compoff.encashment` | **nothing** |
| FL-16 | `F-GRATUITY` | `terminal.gratuity` | four disagreeing implementations |
| FL-17 | `F-BONUS-STATUTORY` | `terminal.bonus` | `StatutoryController:119-152` |
| FL-18 | `F-PT-SLAB` | `statutory.head` | `ptForGross():1925-2000` |
| FL-19 | `F-PF-EMPLOYEE` | `statutory.head` | `statutory():2064-2070` |
| FL-20 | `F-PF-EMPLOYER-TOTAL` | `comp.employer_contribution` | `:2069` |
| FL-21 | `F-PF-ER-EPS` | `comp.employer_contribution` | `:2071` (the dead cap) |
| FL-22 | `F-PF-ER-EPF` | `comp.employer_contribution` | `:2072` |
| FL-23 | `F-PF-ER-EDLI` | `comp.employer_contribution` | `:2073` |
| FL-24 | `F-ARREAR-COMPONENT` | `arrear.amount` | `IncrementController:366-376` |
| FL-25 | `F-COMMISSION-SLAB` | `commission.payout` | `IncentiveController:299-344` |
| FL-26 | `F-ATT-INCENTIVE` | `incentive.payout` | **nothing** |
| FL-27 | `F-REIMB-CAP` | `reimbursement.payable` | **nothing** |
| FL-28 | `F-NET-FLOOR-DEFER` | `net.floor_adjustment` | **nothing** |

**Eleven of the twenty-eight replace nothing at all** — they are capabilities
SmartPRS 2 does not have and cannot be configured to have. That ratio is the
argument for the engine.

---

## 12. Testing formulas

### 12.1 The sample-employee harness

A **sample employee** is a named, versioned, tenant-owned fixture — not a real
employee, and never a copy of one carrying live PII.

```
formula_sample_employees
  code            e.g. 'SE-HYD-AGENT-G3'
  name, description
  fixture_json    the complete prepared context, slice by slice, pinned:
                    employee.identity   (synthetic; DOB and PAN are generated, never copied)
                    employee.org        the 21 dimensions
                    employee.compensation
                    period.definition
                    structure.resolved  by structure code + version
                    settlement.attendance / .leave
                    statutory.pack      by pack id + version
                    ledger.balances, ytd.prior, variable.inputs
  derived_from    optional: a real employee, ANONYMISED at capture (R-VIII-057)
```

**R-VIII-057** "Create a sample from this employee" **anonymises at capture**:
names, contact details, government identifiers, bank details and dates of birth
are replaced with generated values before the fixture is stored, and the source
employee id is recorded but not resolvable from the fixture. A fixture is
tenant-scoped like any other business row (Law 6).

**R-VIII-058** A sample employee is **versioned and effective-dated** like every
other artefact (Law 3). A test case pins the fixture version it asserts against,
so updating a fixture does not silently change what a passing test means.

A **test case** is:

```
formula_test_cases
  formula_version_id, case_code, name, class (nominal|boundary|zero|negative
                                              |proration|statutory_boundary)
  sample_employee_code + sample_version
  as_of DATE, period_code
  context_overrides_json     -- narrow, explicit deltas on the fixture
  expected_minor  BIGINT     -- EXACT, in minor units
  expected_type, expected_unit, expected_currency
  expected_rounding_delta_minor  BIGINT   -- optional but recommended
  expected_error_code            -- for cases that must fail
  is_pinned BOOL             -- pinned cases run for every dependent publish
```

**R-VIII-059** Expected values are asserted **exactly, in minor units**. There
is no tolerance, no epsilon and no "approximately". *SmartPRS 2's own code uses
`0.005` epsilons (`SchemeController:290, 821, 832`) because float arithmetic
leaves it no choice; integer paise leaves no room for one.*

### 12.2 Pinning a simulation as a regression test

The simulator (§16.3) evaluates any formula against any sample employee or any
real employee-period and shows the full trace. **One button on that trace turns
it into a pinned test case:**

```
POST /api/v3/formulas/{uuid}/versions/{v}/tests/pin-from-simulation
  { simulation_id, case_code, name, class, assert: 'result' | 'result+trace' }
```

The pinned case stores the **context digest** as well as the expected value. On
re-run, three outcomes are distinguished, and the distinction is the whole point:

| Digest | Value | Verdict |
|---|---|---|
| same | same | **pass** |
| same | different | **fail** — the formula changed the answer for identical inputs |
| different | same | **pass with a note** — the fixture moved; the answer did not |
| different | different | **fail, marked `inputs changed`** — the diff names which slice changed, so an author is never left guessing whether they broke the formula or the fixture |

**R-VIII-060** Pinning a simulation is the primary way regression tests come to
exist. A payroll analyst investigating a disputed number produces a regression
test as a by-product of investigating it. *This matters because SmartPRS 2's
entire payroll test suite is `tests/Unit/PayrollMathTest.php` plus
`tests/Feature/PayrollTest.php` — a few dozen assertions against a re-extracted
copy of the arithmetic, not against the engine.*

### 12.3 Showing the impact of a change before publish

**R-VIII-061** Publishing a `high_risk` formula requires an **impact preview**,
computed by evaluating both the current published version and the candidate
version across the affected population, and it blocks publication until an
authorised human has seen it.

```
IMPACT PREVIEW      F-HRA-PCT-BASIC   v3 → v4   effective 01-Apr-2027

  Change            policy.value('hra_pct_of_basic')  →  salary.component('BASIC') % 50
                    (the percentage moves from a policy value to a literal 50)

  Population        1,247 employees in scope {company: Ametecs South}
  Evaluated against period 2027-04 using each employee's resolved structure

  MONEY DELTA
    employees increased      1,247        total  +₹31,17,500.00 / month
    employees decreased          0
    employees unchanged          0
    largest single increase  ₹4,166.67    HYD-0044  (₹8,333.33 → ₹12,500.00)
    smallest                   ₹625.00    BLR-1180

  DOWNSTREAM
    gross               +₹31,17,500.00      (HRA is a CTC component)
    PF employee                 ₹0.00      (HRA does not carry is_pf_wage)
    ESI employee          +₹23,382.00      (HRA carries is_esi_wage; 214 employees
                                            remain under the threshold)
    PT                     +₹1,650.00      (33 employees cross a band)
    TDS                  +₹4,58,610.00
    NET                 +₹26,33,858.00

  THRESHOLD CROSSINGS
    112 employees cross the ESI eligibility threshold and CEASE to be eligible
        → the contribution-period lock applies to 74 of them (statutory.locks)

  BLOCKED CHECKS      none
  WARNINGS            2 employees would breach the minimum-wage comparison
                      (F-MIN-WAGE-CHECK) — listed, with codes

  REGRESSION          18 pinned cases across 4 dependent structures: 18 pass
```

**R-VIII-062** The **in-app calculation guide is generated** from the published
formulas' `business_description`, `expression_src`, rounding declarations and
worked sample cases. It cannot contradict the engine, because it is rendered
from it. *`CalcGuideController` is hand-written prose and four of its statements
already contradict the engine — including a headline FAQ about a ÷26 basis the
engine does not implement (`A-payroll.md` §8.2).*

### 12.4 The test tiers

| Tier | What | Where it runs | Failure |
|---|---|---|---|
| Unit — evaluator | Every operator pair in the §5.4 matrix; every function's null, boundary and error cases; rational arithmetic properties | CI | build fails |
| Property — money | I1 across ≥ 10^6 generated allocations (R-VIII-032); rounding-mode equivalences; `(a/b)*c = (a*c)/b` for all rationals | CI | build fails |
| Golden — formula library | All 28 formulas of §11, every worked example, asserted **to the paise** | CI | build fails |
| Tenant — pinned cases | Every published formula's ≥ 3 cases, plus every structure's ≥ 3 sample employees | **On every publish**, and nightly | publish blocked / alert |
| Contract — cross-engine | A rule action carrying a formula reference evaluates identically whether invoked from the rule engine or the pipeline | CI | build fails |
| Reconciliation — run | I1–I10 (§6.6) | **Every payroll run** | run aborts |
| Parity — migration | Shadow run against SmartPRS 2 output (§15.4) | Per-customer cutover | cutover blocked |
| Performance | §13.2 targets on a fixed corpus | CI | build fails on >20% regression |

---

## 13. Performance

### 13.1 The workload

The reference workload is **one monthly payroll run: 10,000 employees, a
40-component structure, 12 statutory heads, 4 queue workers.**

```
10,000 employees
  × (40 components + 12 statutory heads + ~8 proration/OT/recovery formulas)
  = ~600,000 formula evaluations per run

plus dependency edges: a 40-component structure typically has 55–90 edges,
so ~700,000 node-level resolutions, of which ~35% are memo hits
```

A **recomputation** of a financial year (arrears, a retrospective pack change)
multiplies this by 12. A **simulation** is one employee: ~60 evaluations.

### 13.2 Targets

| Metric | Target | Measured by |
|---|---|---|
| Single formula evaluation, warm AST, no dependency | ≤ **25 µs** p50, ≤ 90 µs p99 | Micro-benchmark in CI |
| Single evaluation with 5 dependencies (memo warm) | ≤ **45 µs** p50 | Micro-benchmark |
| One employee, full 40-component structure + statutory | ≤ **1.5 ms** p50, ≤ 6 ms p99 | Component benchmark |
| Rational arithmetic, 1,000 chained operations | ≤ **200 µs** | Micro-benchmark |
| `ALLOCATE` over 500 weights | ≤ **60 µs** | Micro-benchmark |
| **Calculation stage, 10,000 employees, 4 workers** | ≤ **90 seconds** wall clock | Performance regression test |
| Context assembly, 10,000 employees | ≤ **60 seconds** | Performance regression test |
| Trace construction overhead | ≤ **15%** of evaluation time | Benchmark, tracing on vs off |
| Interactive single-employee simulation, end to end | ≤ **250 ms** | API SLO |
| Impact preview, 1,000 employees | ≤ **20 seconds**, async with progress | API SLO |
| Peak memory per worker | ≤ **256 MB** | Performance test |
| Trace storage per run | ≤ **0.6 GB** compressed for 10,000 employees | Measured, reported on the run |

**R-VIII-063** These targets are regression-tested in CI against a fixed corpus,
a fixed structure and a fixed rule pack, and the build fails on a regression
greater than 20% from the committed baseline. *A performance target that is not
asserted is an aspiration.*

### 13.3 Caching

Four caches. None is a time-based TTL on business data.

| Cache | Key | Contents | Invalidation | Scope |
|---|---|---|---|---|
| **Compiled AST** | `formula_version_id + checksum` | Deserialised immutable AST + its constant pool | Never — the key changes when the formula changes; published versions are immutable (R-VIII-008) | Process (APCu) + shared (Redis) |
| **Evaluation plan** | `structure_version + pack_version + scope_signature` | The topological order, the resolved dependency set and the per-component rounding declarations | Any publish affecting the structure, a component, a formula or the pack | Redis; recomputed per batch |
| **Scope signature** | `employee + as_of` | The 21 dimension values as at the date | Any effective-dated assignment change | Per batch, in memory |
| **Memo** | `formula_version + context_digest + employee + period` | The computed value and its trace ref | End of batch | **Per batch, in memory only** (R-VIII-038) |

**The evaluation plan is the load-bearing optimisation**, for the same reason
Part VII's scope signature is: a tenant of 10,000 employees typically has 30–300
distinct scope signatures and 3–15 distinct structure versions, so the plan is
computed **tens of times, not ten thousand**.

```
10,000 employees → ~120 scope signatures × ~6 structure versions
                 → at most ~200 distinct plans actually reached
                 → topological sort + dependency resolution cost falls ~98%
```

### 13.4 Batch evaluation, and the budgets

```
BatchEvaluator::run(calculation_point, subject_set, period)

1. resolve    the evaluation plan per (scope signature × structure version)
2. partition  subjects into shards of ~2,000, grouped by plan
3. per shard:
   a. assemble the prepared context: ONE query per slice, never per subject
   b. evaluate COLUMN-WISE — for each node in topological order, evaluate that
      one formula for all 2,000 subjects before moving to the next node
   c. collect values and traces in memory
   d. bulk-write payslip lines + pinned trace columns in one transaction;
      full traces to the async trace queue
4. reconcile  assert I1–I10 (§6.6); abort the run on any failure
```

**R-VIII-064** Evaluation within a shard is **column-wise** (one formula across
all subjects) rather than row-wise (all formulas for one subject). One AST, its
constant pool and its rounding declaration stay resident across 2,000
evaluations. The cost is holding the shard's intermediate values —
`2,000 × 52 × ~24 bytes ≈ 2.5 MB`, comfortably inside the memory budget.

**R-VIII-065** The batch issues **O(slices) queries per shard, never
O(subjects)**. A CI test asserts the query count for a 2,000-subject shard is
below a fixed ceiling (default 30) and fails on regression.

Per-evaluation budgets, enforced at runtime as well as at publish (§9.1):

| Budget | Limit | On breach |
|---|---|---|
| AST nodes evaluated | 2,000 | `ERR_BUDGET_NODES` — aborts the evaluation |
| Expression depth | 40 | `ERR_BUDGET_DEPTH` |
| Wall time per evaluation | 50 ms | `ERR_BUDGET_TIME` |
| `agg.*` calls per evaluation | 4 | `ERR_BUDGET_AGG` |
| Dependency chain depth | 25 | `ERR_BUDGET_DEPTH` |
| Rational denominator magnitude | 2^64 | `ERR_PRECISION` |

**R-VIII-066** The batch **continues past a failed subject**. One employee whose
formula evaluation fails does not abort the other 9,999; the failure is recorded
as a payroll exception naming the employee, the formula and the error, counted,
and surfaced in the preview. **A batch ending with any failure count > 0 does
not report success.** *SmartPRS 2's `compute()` skips an employee with
`ctc <= 0` and increments `$skipped` (`:1205`), and the run reports success
regardless of how many were skipped.*

**R-VIII-067** The batch is **restartable and idempotent**: shards are
checkpointed; a re-run of a completed shard converges by `context_digest` rather
than duplicating lines.

### 13.5 Trace sampling under load

**R-VIII-068** The **pinned** trace summary is written for every line, always.
Only the **full** trace tier may be sampled, and only under a declared
`trace_full_rate` policy with a floor: 100% for any line carrying an exception,
a threshold crossing, a non-zero clamp, an unusual rounding delta, a statutory
head or a manual override; and 100% for any employee an operator has opened. A
line whose full trace was not stored remains reproducible (R-VIII-054), so
sampling never costs explainability — only latency.

### 13.6 Where the cost goes

Measured distribution on the reference workload (targets, to be validated):

| Stage | Share | Note |
|---|---|---|
| Context assembly (bulk loads) | ~40% | Dominated by settlement, structure and YTD reads |
| Plan resolution + topological sort | ~3% | After plan caching |
| AST evaluation (rational arithmetic) | ~28% | Pure CPU, cache-warm |
| Rounding and allocation | ~4% | BigInteger operations |
| Trace construction | ~14% | Within the ≤15% budget |
| Persistence (bulk writes) | ~11% | Lines + pinned trace |

Optimisation order: **bulk-load discipline first, plan caching second, the
evaluator third.** Micro-optimising rational arithmetic before the query pattern
is settled would be the wrong first move.

---

## 14. Data model

Conventions per spine S16. Platform catalogue tables (`calculation_points`,
`spx_types`, `rounding_modes`, `day_length_policies` where platform-seeded) are
**not** tenant-scoped: they are seeded by migration, identical in every
installation, and read-only at runtime. Every other table below carries
`tenant_id NOT NULL`, participating in every unique index (Law 6).

`cfg_formulas` already exists in **Part XXVIII §`cfg_formulas`**. This part
**extends** that table rather than defining a second one; the added columns are
marked ▲.

### 14.1 `calculation_points` — platform catalogue

**Purpose.** The closed set of invocation sites (§3.2). Fixes the symbol table,
the slice list, the permitted result types and the rounding governance.

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `point_code` | VARCHAR(64) | N | — | UNIQUE. `comp.earning`, `statutory.head`, … |
| `module_code` | VARCHAR(32) | N | — | Owning module |
| `subject_kind` | VARCHAR(40) | N | — | `employee_period_component`, `employee`, `loan`, … |
| `permitted_result_types_json` | JSON | N | — | e.g. `["Money","List<Money>"]` |
| `namespaces_json` | JSON | N | — | The readable namespaces |
| `slices_json` | JSON | N | — | The offerable context slices |
| `default_rounding_mode` | VARCHAR(40) | Y | NULL | A *suggestion* for the authoring UI; **never** an evaluated default |
| `is_high_risk` | TINYINT(1) | N | 0 | Forces `severity = high_risk` on every formula here |
| `catalogue_version` | VARCHAR(16) | N | — | Formulas pin the major version they compiled against |
| `is_active` | TINYINT(1) | N | 1 | |

**Keys.** PK `id` · UQ `(point_code)` · IX `(module_code)`.
**Policy.** Platform-owned, seeded by migration · no tenant scope (stated
explicitly) · immutable at runtime · audit `none` (it cannot change at runtime) ·
PII `none` · retention `R-PERM`.

### 14.2 `cfg_formulas` — the typed payload (extends Part XXVIII)

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `tenant_id` | BIGINT UNSIGNED | N | — | FK `tenants` RESTRICT · Law 6 |
| `config_version_id` | BIGINT UNSIGNED | N | — | FK `config_versions` RESTRICT · UQ |
| ▲ `formula_code` | VARCHAR(64) | N | — | The stable business identifier |
| ▲ `calculation_point_code` | VARCHAR(64) | N | — | FK `calculation_points(point_code)` RESTRICT |
| ▲ `module_code` | VARCHAR(32) | N | — | Must equal the point's module |
| ▲ `formula_kind_code` | VARCHAR(24) | N | `component` | `component \| intermediate \| rate` |
| `result_type_code` | VARCHAR(40) | N | — | `Money \| Days \| Hours \| Minutes \| Number \| Rate<Money/Days> \| Rate<Money/Hours> \| List<Money>` |
| ▲ `result_unit_code` | VARCHAR(40) | Y | NULL | Currency, day-length policy, or `ratio\|percent\|count\|factor` |
| ▲ `currency_code` | CHAR(3) | Y | NULL | Mandatory when the result is `Money` |
| `expression_source` | TEXT | N | — | ≤ 8,000 chars |
| `expression_ast` | MEDIUMBLOB | N | — | Canonical compiled AST. **The evaluated artefact** |
| `rounding_mode_code` | VARCHAR(40) | N | — | **Mandatory; there is no default rounding** |
| `rounding_unit` | DECIMAL(18,4) | N | 0.0100 | > 0 unless mode is `none` |
| ▲ `rounding_point_code` | VARCHAR(16) | N | `final` | `final \| none` |
| ▲ `reference_value_code` | VARCHAR(16) | N | `rounded` | `rounded \| exact` (§6.2, D-VIII-01) |
| ▲ `residue_head_code` | VARCHAR(40) | Y | NULL | For `List<Money>` results: the element that absorbs residue |
| ▲ `clamp_kind_code` | VARCHAR(24) | Y | NULL | `non_negative \| min \| max \| range` |
| ▲ `clamp_min`, `clamp_max` | DECIMAL(18,4) | Y | NULL | |
| ▲ `rate_is_published` | TINYINT(1) | N | 0 | Permits rounding a `Rate` (R-VIII-027) |
| `depends_on_json` | JSON | N | — | Compiler-derived (R-VIII-012), acyclic at publish |
| ▲ `declared_slices_json` | JSON | N | — | Must equal the compiler-derived set (R-VIII-044) |
| ▲ `spx_catalogue_version` | VARCHAR(16) | N | — | The function/type catalogue major version compiled against |
| ▲ `node_count`, `depth`, `agg_call_count` | SMALLINT UNSIGNED | N | — | Budget evidence, stored at compile |
| ▲ `source_ref` | VARCHAR(255) | Y | NULL | The SmartPRS 2 citation this formula replaces |
| ▲ `checksum` | CHAR(64) | N | — | SHA-256 over AST + rounding + scope + dates + dependencies |

**Keys.** PK `id` · UQ `(config_version_id)`, `(tenant_id, formula_code, config_version_id)` ·
IX `(tenant_id, formula_code)`, `(tenant_id, calculation_point_code)`,
`(tenant_id, result_type_code)`, `(checksum)`.
**FKs.** `tenant_id` RESTRICT · `config_version_id` RESTRICT ·
`calculation_point_code` RESTRICT. **`ON DELETE RESTRICT` from every payslip
line**, so a formula version referenced by an issued payslip can never be removed.
**Policy.** Tenant-owned · **immutable once its version is published** ·
history: supersession via `config_versions` · soft delete: none (archive only) ·
audit `full` · PII `none` · retention `R-PERM`.
*Why: SmartPRS 2's salary-component master **hard-deletes** rows, destroying the
ability to re-explain every payslip computed from them (`J-database.md` §Q8).*

### 14.3 `formula_sample_employees`

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `id`, `uuid` | BIGINT UNSIGNED / CHAR(36) | N | | UUIDv7 public id |
| `tenant_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `sample_code` | VARCHAR(64) | N | — | e.g. `SE-HYD-AGENT-G3` |
| `sample_version` | INT UNSIGNED | N | 1 | Monotonic (R-VIII-058) |
| `name`, `description` | VARCHAR(160) / TEXT | N | — | |
| `fixture_json` | JSON | N | — | The complete pinned context, slice by slice |
| `derived_from_employment_id` | BIGINT UNSIGNED | Y | NULL | FK SET NULL. Recorded, **not resolvable from the fixture** |
| `anonymised_at` | DATETIME | Y | NULL | NOT NULL whenever `derived_from_employment_id` is set |
| `created_by_user_id`, `created_at` | | N | — | |
| `is_active` | TINYINT(1) | N | 1 | |

**Keys.** PK `id` · UQ `(uuid)`, `(tenant_id, sample_code, sample_version)` ·
IX `(tenant_id, is_active)`.
**Policy.** Tenant-owned · versioned, never updated in place · soft delete via
`is_active` · audit `full` · **PII `none` by construction** — a fixture that
fails the anonymisation assertion cannot be written · retention `R-PERM`.

### 14.4 `formula_test_cases`

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `id`, `uuid` | | N | | |
| `tenant_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `config_version_id` | BIGINT UNSIGNED | N | — | FK `config_versions` CASCADE-on-archive-only |
| `case_code` | VARCHAR(64) | N | — | |
| `name` | VARCHAR(160) | N | — | |
| `class_code` | VARCHAR(32) | N | — | `nominal \| boundary \| zero \| negative \| proration \| statutory_boundary` |
| `sample_employee_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `sample_version` | INT UNSIGNED | N | — | Pinned (R-VIII-058) |
| `as_of` | DATE | N | — | |
| `period_code` | VARCHAR(32) | N | — | |
| `context_overrides_json` | JSON | Y | NULL | Explicit deltas on the fixture |
| `expected_minor` | BIGINT | Y | NULL | **Exact**, in minor units |
| `expected_type_code`, `expected_unit_code`, `expected_currency_code` | | Y | NULL | |
| `expected_rounding_delta_minor` | BIGINT | Y | NULL | |
| `expected_error_code` | VARCHAR(32) | Y | NULL | For cases that must fail. Exactly one of `expected_minor` / `expected_error_code` is non-null |
| `expected_context_digest` | CHAR(64) | Y | NULL | Set when pinned from a simulation (§12.2) |
| `is_pinned` | TINYINT(1) | N | 0 | Pinned cases run for every dependent publish |
| `last_run_at`, `last_result_code`, `last_actual_minor` | | Y | NULL | `pass \| fail \| inputs_changed` |

**Keys.** PK `id` · UQ `(uuid)`, `(tenant_id, config_version_id, case_code)` ·
IX `(tenant_id, is_pinned, last_result_code)`.
**Policy.** Tenant-owned · audit `full` · PII `none` · retention `R-PERM`.
**CHECK:** exactly one of `expected_minor`, `expected_error_code` is non-null.

### 14.5 `formula_publication_checks`

**Purpose.** The stored outcome of every gate (§9), so a blocked publication is
explainable and a passed one is evidenced.

`id` PK · `tenant_id` FK · `config_version_id` FK · `gate_code` (`G1`…`G12`) ·
`check_code` · `severity_code` (`block \| warn \| lint \| pass`) · `message` ·
`detail_json` (the cycle path, the counterpart version, the failing test case,
the offending node span, sample employee uuids) · `checked_at` ·
`resolved_by_user_id` Y · `resolved_at` Y · `resolution_note` Y.
IX `(tenant_id, config_version_id)`, `(tenant_id, severity_code, checked_at)`.
**Policy.** Audit `full` · **PII: sample employee uuids only, never names** — the
UI resolves uuids under the reader's own data scope · retention `R-PERM`.

### 14.6 `calculation_traces`

**Purpose.** The full trace tier (§10.5). Referenced by
`payslip_lines.trace_id` (Part XXVIII).

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | N | auto | PK |
| `uuid` | CHAR(36) | N | — | UNIQUE. The public trace id |
| `tenant_id` | BIGINT UNSIGNED | N | — | FK · Law 6 |
| `calculation_point_code` | VARCHAR(64) | N | — | |
| `formula_config_version_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `subject_type` | VARCHAR(32) | N | — | |
| `subject_id` | BIGINT UNSIGNED | N | — | |
| `period_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `as_of` | DATE | N | — | |
| `status_code` | VARCHAR(16) | N | — | `computed \| error` |
| `context_digest` | CHAR(64) | N | — | Reproduction key (R-VIII-054) |
| `result_minor` | BIGINT | Y | NULL | |
| `result_exact_num`, `result_exact_den` | DECIMAL(38,0) | Y | NULL | The pre-rounding rational |
| `rounding_delta_minor` | BIGINT | Y | NULL | |
| `engine_version` | VARCHAR(48) | N | — | Evaluator + SPX catalogue + type registry |
| `payload_zstd` | MEDIUMBLOB | N | — | Compressed canonical trace JSON |
| `payload_bytes` | INT UNSIGNED | N | — | Uncompressed size, for cost reporting |
| `error_code` | VARCHAR(32) | Y | NULL | |
| `nodes`, `agg_calls`, `memo_hits`, `micros` | INT UNSIGNED | N | — | Counters |
| `created_at` | DATETIME | N | — | |

**Keys.** PK `id` · UQ `(uuid)` ·
IX `(tenant_id, subject_type, subject_id, period_id)` — the explain lookup ·
IX `(tenant_id, formula_config_version_id, created_at)` — impact analysis ·
IX `(tenant_id, status_code, created_at)` — the exceptions view.
**Partitioned by RANGE on `created_at`, monthly.**
**Policy.** Tenant-owned · append-only, **never updated** · soft delete: none —
dropped by partition under the `trace` retention class (13 months default) ·
**read access is audited** · PII `Amber` · retention `R-TRACE-13M`.

### 14.7 `day_length_policies` and `rounding_profiles`

`day_length_policies` — what makes a `Days` value mean something, and what
`TO_DAYS(x, 'CODE')` converts between (§5.3, R-VIII-016):

`id` · `tenant_id` · `config_version_id` (versioned artefact) · `policy_code`
(`WORKING`, `CALENDAR`, `FIXED`, `WORKING_TO_CALENDAR`, `PAYABLE`, …) ·
`numerator_basis_code` · `denominator_basis_code` · `fixed_divisor` DECIMAL(9,4) Y ·
`conversion_num`, `conversion_den` (for a conversion policy; `1:1` is the
identity conversion that makes SmartPRS 2's implicit assumption explicit) ·
`description`.
UQ `(tenant_id, policy_code, config_version_id)`. Effective-dated and scoped like
every artefact.

`rounding_profiles` — a named, reusable rounding declaration so a tenant sets
"whole rupee, half up" once and 40 components reference it:

`id` · `tenant_id` · `config_version_id` · `profile_code` · `mode_code` ·
`unit` DECIMAL(18,4) · `cap_order_code` (`round_then_cap \| cap_then_round`) ·
`description`. UQ `(tenant_id, profile_code, config_version_id)`.
A component may reference a profile **or** declare inline, never neither.

### 14.8 Pinned columns on derived records

Added by the owning module's migration, not by this part:

```
payslip_lines        formula_config_version_id, formula_versions_json,
                     component_config_version_id, exact_num, exact_den,
                     result_minor, rounding_mode_code, rounding_unit,
                     rounding_delta_minor, clamp_applied, context_digest, trace_id
period_settlements   (unchanged; the settlement is an input, not a derivation)
ledger_entries       formula_config_version_id, context_digest, trace_id
arrear_lines         source_period_id, prior_run_version_id, formula_versions_json,
                     context_digest, trace_id
```

**R-VIII-069** Every derived money record stores the formula version ids that
produced it, transitively, plus its context digest. A derived record without
them fails the CI conformance sweep (Law 4, spine S4).

---

## 15. Migration

### 15.1 The classification exercise

Every calculation catalogued in `A-payroll.md` §4 and `F-statutory-compliance.md`
§4 is classified into exactly one destination:

| Destination | Meaning | Count from the analyses |
|---|---|---|
| **Formula** | Becomes a seeded `cfg_formulas` artefact | 28 (§11) |
| **Rule** | Is an eligibility or outcome decision misfiled as arithmetic | 6 (probation suppression, gratuity eligibility, bonus eligibility, ESI eligibility, attendance-incentive eligibility, PT female exemption) |
| **Pack content** | Is a statutory value, not a calculation | every rate, ceiling, cap, slab band and threshold |
| **Pipeline** | Is an ordering or assembly concern | recovery order, LOP presentation, net assembly, run totals |
| **Defect — not carried** | Is wrong and is not reproduced | §15.3 |
| **Dead — not carried** | Unrouted or unreachable code | `SalaryRunController::generate():36-100`, `Loan::dueInstallment():32-39`, `SalarySlip::totalDeductions():28-31`, the unreachable `pt_slabs` fallback |

### 15.2 The mapping — every calculation in `A-payroll.md` §4

| `A-payroll.md` | SmartPRS 2 citation | SmartPRS 3 destination |
|---|---|---|
| §4.0 order of operations | `compute():1091-1488` | **Pipeline** (spine S11), staged and restartable; the twenty numbered steps become named stages with per-stage traces |
| §4.1 gross → Basic / HRA / Special | `computeSlip():2102-2105` | **FL-01, FL-03, FL-04**; the `/12` becomes `period.periods_per_year` |
| §4.2 the three proration divisors | `:1108-1111`, `:1268-1287` | **FL-07, FL-08, FL-09** + **FL-10 (26)** and **FL-11 (payable)**, which do not exist today; scoped, not tenant-wide |
| §4.2 per-day display value | `liveSalary():1736-1737` | **FL-07…FL-11** — the display reads the same formula, so it cannot diverge |
| §4.3 the LOP factor | `:1259`, `:1268-1287` | **FL-07…FL-12**; `min(1.0, …)` becomes the explicit `MIN(1, …)`, and the joiner branch becomes **FL-13** |
| §4.4 component-based structure | `computeSlipFromComponents():2145-2337` | **Structure + topological order (§7.3)**. The five hard-coded passes disappear: dependency order is derived, not sequenced by hand |
| §4.4 `isBasic` / `isReimb` / DA detection | `:2173`, `:2181-2188`, `:2269` | **Component flags** (`salary.wage_base`, R-VIII-056). Not a formula at all |
| §4.5 Provident Fund | `statutory():2064-2073` | **FL-19** (employee) + **FL-20…FL-23** (employer split) |
| §4.6 ESI | `:2075-2078` | **Formula** `F-ESI-EMPLOYEE` / `F-ESI-EMPLOYER`, `MIN(wage_base('ESI'), …) % rate`, rounding declared `ceil, 1.00` **once** — so the challan and the payslip cannot disagree (DEFECT-F-05) |
| §4.6 ESI contribution-period lock | `esiPeriodLock():2011-2046` | **Rule** + `statutory.locks` slice: a **structured fact** per employee per period, not `stripos($k,'esi')` over a stored JSON key |
| §4.7 Professional Tax | `ptForGross():1925-2000` | **FL-18**; the 16 tables and 10 PT-free states become **pack content**; the February uplift becomes a band attribute; the female exemption becomes an exemption predicate |
| §4.8 TDS on salary | `salaryTdsMonthly():1824-1843`, `newRegimeTax():1752-1766` | **Formula** `F-TDS-MONTHLY` using `SLAB_MARGINAL_RELIEF` (Part VII §5.6) so **relief is inside the tax function and every consumer agrees**; plus `ytd.prior` for a true-up. See §15.3 |
| §4.9 LWF and Conveyance | `:2119`, `:2082-2083` | **Formula** each, with a declared rounding (LWF currently has none) and state-aware pack content |
| §4.10 Overtime, night allowance | `MasterController:761-762`, `:1326-1338` | **FL-05, FL-06**; night allowance becomes `F-NIGHT-ALLOWANCE` reading `settlement.night_shift_count` |
| §4.11 Arrears | `IncrementController:366-376` | **FL-24**, by recompute-and-difference |
| §4.12 bulk incentive, 194H | `IncentiveController:299-344`, `:217-221` | **FL-25** + `F-TDS-194H`, with a no-PAN higher rate that the payout path currently lacks |
| §4.13 Gratuity (×4) | `StatutoryController:54-57`, `ReportController:264-275`, `:1543` | **FL-16**, one formula. The 4.81% payslip accrual becomes a separate, declared `F-GRATUITY-ACCRUAL` reading the same wage base |
| §4.14 Statutory bonus | `StatutoryController:119-152` | **FL-17**, plus a **payment path** — today the register computes a figure nothing pays (DEF-P24) |
| §4.15 Net pay (two assemblies) | `:1350`, `:1393` vs `:2206-2208` | **Pipeline**, one assembly; **FL-28** for the floor. The engine/Simulator divergence (DEF-P06) disappears because there is one path |
| §4.16 rounding table | passim | **Per-component `rounding_mode` + `rounding_unit`** (R-VIII-025), or a shared `rounding_profile` |

### 15.3 SmartPRS 2 calculation defects explicitly **not** reproduced

Per the author brief's rule 5, each is named, cited, and the replacement stated.

| # | Defect | Citation | Not reproduced because |
|---|---|---|---|
| 1 | **The float EMI comparison.** Whether a loan instalment is deducted is decided by comparing floats | `PayrollGenController.php:1357` | All money is integer paise (R-VIII-020); the comparison is exact. And full-EMI-only becomes a declared shortfall policy, so "it did not fit" is a business choice, not a representation artefact (FL-28) |
| 2 | **The loose float equality guarding a commission line**, `if ((float) $camt == 0.0)` | `:2632` | Integer comparison; and a zero-valued line is printed rather than suppressed, because a zero the employee expected to be non-zero is information |
| 3 | **The `0.005` epsilons** the code uses to work around float imprecision | `SchemeController:290, 821, 832` | There is nothing to work around |
| 4 | **Five coexisting divisors applied inconsistently** — working, calendar, 30, 26, 30.44 — with only the base-salary path honouring the tenant's basis; commission, OT, night allowance and arrears never prorated; arrears always calendar; OT always 26; gratuity always 26; bonus always 30.44 | `A-payroll.md` §4.2, §5.3 Q1; `IncrementController:369`; `MasterController:761`; `StatutoryController:57, 123` | Every divisor is a named `day_length_policy` or a pack value, resolved per scope. Every formula that uses one **names** it. Two formulas using different bases is legal and visible; two formulas using different bases by accident is not expressible |
| 5 | **Absence measured in working days, valued in calendar days**, with no type barrier | `:1268-1284` | Unit typing (§5.3): the subtraction does not type-check without an explicit, dated, traced `TO_DAYS` conversion |
| 6 | **`pct_ctc` mis-computed as percent-of-gross** | `:2225-2237` | FL-02. `pct_of_ctc` is a distinct calculation kind with distinct arithmetic; the two bases are different symbols |
| 7 | **The 87A cliff without marginal relief**: relief lives in the caller, so `newRegimeTax()` returns ₹62,400 at taxable ₹12,00,001 while the payslip deducts ₹0.09 — and Form 24Q and the quarterly history call the raw function | `:1752-1766` vs `:1836-1840`; `statutoryPdf():1732`; `tdsReturnsHistory():1779`; DEF-P09, DEFECT-F-06 | `SLAB_MARGINAL_RELIEF` applies the slab table's **declared** relief rule inside the tax function, so **every consumer gets the same number by construction** (Part VII §5.6). There is no second path to call |
| 8 | **Browser-JavaScript duplicates of PF, ESI and TDS** for the register screens — ignoring `salary_components`, `Math.round` instead of `ceil`, ignoring the ESI lock, ignoring LOP — plus a hard-coded JavaScript copy of the whole server rate table | `AppController.php:12081-12085`, `:12093-12110`, `:11161` | R-VIII-004: exactly one implementation, server-side. The SPA renders server-computed figures and holds no arithmetic. **Registers are generated from stored payslip facts, never recomputed from CTC** |
| 9 | **EPS ₹1,249.50 and a cap that never binds** | `:2071`; DEF-P08 | The rounding and the cap order are declared pack attributes with a `source_reference`, and both steps appear in the trace (§6.3, R-VIII-026, FL-21) |
| 10 | **ESI `ceil` in the engine, `round(…,2)` in the challan PDF** | `:2077-2078` vs `:1688-1689`; DEFECT-F-05 | One formula, one rounding declaration, both surfaces call it |
| 11 | **Four gratuity implementations** disagreeing on rounding, year count and wage base | `A-payroll.md` §4.13; DEF-P25 | FL-16, one formula; `COMPLETED_YEARS` has one definition |
| 12 | **The OT hourly formula duplicated in two files** with `26` and `8` as literals | `MasterController:761`, `PayrollGenController:2165` | FL-05, one formula, both constants configuration |
| 13 | **Name-driven semantics**: Basic by substring, DA by regex, reimbursement by substring, HRA by `stripos`, the ESI lock by `stripos($k,'esi')` | `:2173`, `:2269`, `:2186`, `:2316`, `:2036` | R-VIII-003 and R-VIII-056. `CONTAINS` on a `Code` is not expressible; wage bases come from flags |
| 14 | **Unvalidated slab and band order** — the TDS walk depends on stored order and nothing checks it; the incentive band loop falls through to the **last band's rate** by default | DEF-P22; `IncentiveController:299-344` | Gate G7: sorted, contiguous, non-overlapping, terminal band, declared `marginal` or `flat` |
| 15 | **The Maharashtra annual PT special case** `200 × 11 + 300` regardless of the employee's actual band, and `monthly × 12` for states on half-yearly bases | `StatutoryController:95-98`; DEFECT-F-29 | Periodicity is a slab-table attribute; the annual figure is the sum of the periods actually computed |
| 16 | **The `?: 30` commission-percentage fallback** — an employee flagged "Salary + Commission" with no percentage is assumed 30% | `:1712-1715` | No silent default. A missing required input is `ERR_MISSING_INPUT` and a payroll preview exception |
| 17 | **The unknown-leave-type default of PAID** | `paidLeaveDays():1065` | `not_null_required` on the leave type; a null fails loudly (Part VII R-VII-015) |
| 18 | **Zero punches = full pay**, with no override | `:1237` | A declared policy with `flag for review` recommended as the migration default (D-VIII-04, and Part VII D-VII-04) |
| 19 | **`round()` over already-rounded values**, and `total_ded = round(sum-of-rounded, 2)` | `:2122`, `:2310` | One rounding boundary per formula (R-VIII-007, R-VIII-023); totals are exact sums of already-rounded integers |
| 20 | **The run total as a running float sum**, never reconciled against `SUM(payslips.net)`; the bank-file total computed twice by two float paths with no cross-check | `:1427-1429`, `:1475-1477`; `SalaryApprovalController:428, 483`; DEF-P17, DEF-P07 | Invariants I3 and I8, asserted on every run, aborting on failure (R-VIII-031) |
| 21 | **Negative residue printed as a negative earning**; net clamped at two different points by the engine and the Simulator | DEF-P20c, DEF-P06 | Declared clamps at a named, traced step (R-VIII-030); one net assembly |
| 22 | **Statutory deductions computed on the already-prorated CTC** so they inherit the salary basis, while commission, OT, night allowance and arrears sit outside every statutory base | `A-payroll.md` §4.2; BR-STAT-006 | Statutory bases are per-component flags; whether a variable-pay component enters a base is configuration. **This is a compliance question, not a design one — see D-VIII-06 and OQ-3** |

### 15.4 Cutover: parity by shadow run

**R-VIII-070** Before cutover, every customer runs a **shadow reconciliation**:
the seeded SmartPRS 3 formulas evaluate the last N closed periods (default 3)
against migrated data, and every payslip line is compared with the SmartPRS 2
figure. The report classifies every difference:

| Class | Meaning | Action |
|---|---|---|
| `identical` | Same to the paise | none |
| `rounding_only` | Differs by < the declared rounding unit | accepted, listed, counted |
| `defect_corrected` | SmartPRS 3 differs **because** a §15.3 defect is not reproduced | **must be acknowledged individually by the customer**, with the defect id, the money delta and the affected headcount |
| `configuration_gap` | A SmartPRS 2 behaviour not yet captured in configuration | **blocks cutover** until configured or explicitly waived |
| `unexplained` | Neither | **blocks cutover**, always |

**R-VIII-071** `defect_corrected` differences are **never** silently applied.
Each carries a decision: *correct it from cutover*, *preserve the SmartPRS 2
behaviour as an explicitly configured, dated, documented policy*, or *correct it
and raise a remediation for the historical difference*. The choice is recorded
against the customer, per defect. *Because for several of §15.3's items — the
EPS 50-paise, the ESI rounding, the `pct_ctc` base, the arrears statutory
treatment — "correct" is a compliance determination with retrospective money
consequences, and it is not a decision an engineer makes during a data
migration.*

### 15.5 What does not come across

- **The five duplicated calculation sites** (`liveSalary`, `liveSalaryAll`,
  `simulate`, the JavaScript registers, the dead `SalaryRunController`). They are
  replaced by one engine with several callers.
- **The in-app calculation guide's prose.** It is regenerated (R-VIII-062).
- **`ensurePayrollHistory()`'s twelve fabricated months** of full-CTC payslips
  marked `paid` (DEF-P01). Nothing in this part reads them; whether they are
  quarantined, deleted or migrated as "unverified" blocks the whole migration
  and is **OQ-5**, owned outside this part.
- **The `statutory_settings` single JSON blob** with no dates and no history. It
  is not layered over; it is **eliminated**, and its current values are captured
  as the initial pack version with `source_reference = 'SmartPRS 2 statutory_settings
  as at <date>, unverified'` (`F-statutory-compliance.md` §10 item 22).

---

## 16. API surface

All under `/api/v3`, OpenAPI-generated, cursor-paginated, idempotency keys on
every unsafe method, consistent error envelope (spine S17). Public ids are UUIDs
only — never the `bigint` primary key. **Every route carries an explicit policy
binding**, asserted by the CI sweep (Law 5).

### 16.1 Catalogue (read-only)

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/formula-catalogue/calculation-points` | `formula.read` | The closed set (§3.2), with each point's namespaces, slices and permitted result types |
| GET | `/formula-catalogue/functions` | `formula.read` | The SPX function catalogue with signatures — shared with the rule engine, one endpoint |
| GET | `/formula-catalogue/types` | `formula.read` | The type registry and the operation matrix (§5.4), machine-readable, so the editor's linter and the server agree by construction |
| GET | `/formula-catalogue/symbols?calculation_point=` | `formula.read` | The symbol table for autocomplete |
| GET | `/formula-catalogue/rounding-modes` | `formula.read` | |

### 16.2 Authoring

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/formulas` | `formula.read.<module>` | List; filter by point, module, status, tag, dependency |
| POST | `/formulas` | `formula.author.<module>` | Create the artefact + draft v1 |
| GET | `/formulas/{uuid}` | `formula.read.<module>` | Artefact with its version history |
| GET | `/formulas/{uuid}/versions/{v}` | `formula.read.<module>` | One version, full payload |
| POST | `/formulas/{uuid}/versions` | `formula.author.<module>` | New draft from a published version |
| PATCH | `/formulas/{uuid}/versions/{v}` | `formula.author.<module>` | Edit a **draft only**; a published version returns `409 version_immutable` |
| POST | `/formulas/{uuid}/versions/{v}/compile` | `formula.author.<module>` | Parse, type-check, unit-check, derive dependencies and slices. Returns the AST digest, the derived sets, and every gate outcome **without publishing** |
| POST | `/formulas/{uuid}/versions/{v}/validate` | `formula.author.<module>` | Run all twelve gates (§9) and return every check row |
| POST | `/formulas/{uuid}/versions/{v}/submit` | `formula.author.<module>` | → `pending_approval`; opens the workflow instance |
| POST | `/formulas/{uuid}/versions/{v}/publish` | `formula.publish.<module>` | Gates + approval; `409` with the failing gate rows on any block |
| POST | `/formulas/{uuid}/versions/{v}/supersede` | `formula.publish.<module>` | Close the effective range |
| GET | `/formulas/{uuid}/versions/{v}/dependencies` | `formula.read.<module>` | The resolved graph, upstream and downstream, per scope |
| GET | `/formulas/{uuid}/versions/{v}/dependents` | `formula.read.<module>` | Who breaks if this changes |
| GET | `/formulas/{uuid}/versions/{v}/diff?against=` | `formula.read.<module>` | Source, AST, rounding, scope and dependency diff |

**R-VIII-072** There is **no DELETE on any formula route.** Artefacts are
archived; versions are superseded. A formula version referenced by an issued
payslip is protected by `ON DELETE RESTRICT` at the database level as well
(§14.2). *SmartPRS 2's salary-component master hard-deletes.*

### 16.3 Evaluation, simulation and explanation

| Method | Path | Permission | Purpose |
|---|---|---|---|
| POST | `/formulas/{uuid}/versions/{v}/simulate` | `formula.simulate.<module>` | Evaluate against a sample employee **or** a real employee-period. Returns value + full trace. Read-only, never persists a business record |
| POST | `/formulas/simulate-structure` | `formula.simulate.payroll` | Evaluate a whole structure for one employee-period: every line, every trace, the reconciliation assertions |
| POST | `/formulas/{uuid}/versions/{v}/impact-preview` | `formula.publish.<module>` | Async; returns a job id. §12.3 |
| GET | `/formula-impact-previews/{job_uuid}` | `formula.publish.<module>` | Poll / fetch the preview |
| GET | `/calculation-traces/{trace_uuid}` | `explain.read` + data scope on the subject | One full trace. **Read is audited** (R-VIII-055) |
| GET | `/explain/payslip-lines/{line_uuid}` | `explain.read` + data scope | The composed trace tree (§10.4), lazily expandable |
| POST | `/explain/reproduce` | `explain.reproduce` | Re-derive a purged trace from the pinned context digest and versions (R-VIII-054) |

**R-VIII-073** `simulate` resolves its subject **under the caller's own data
scope** (spine S13). An HR user in the Hyderabad scope cannot simulate a Pune
employee's payslip in order to read their salary. Simulation is a read of
derived salary data and is authorised as such.

### 16.4 Sample employees, tests and operations

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET/POST | `/formula-samples` | `formula.author.<module>` | List / create sample employees |
| POST | `/formula-samples/from-employee` | `formula.author.<module>` + `employee.read` | Capture and **anonymise** (R-VIII-057) |
| POST | `/formula-samples/{uuid}/versions` | `formula.author.<module>` | New fixture version |
| GET/POST | `/formulas/{uuid}/versions/{v}/tests` | `formula.author.<module>` | List / add test cases |
| POST | `/formulas/{uuid}/versions/{v}/tests/pin-from-simulation` | `formula.author.<module>` | §12.2 |
| POST | `/formulas/{uuid}/versions/{v}/tests/run` | `formula.author.<module>` | Run all cases; returns pass/fail/inputs_changed per case |
| GET | `/formula-packs` · POST `/formula-packs/{uuid}/import` | `formula.publish.<module>` | Seeded and tenant-to-tenant packs; import runs every gate and every test of both sides and is **refused on any failure** |
| GET | `/formula-operations/health` | `ops.read` | Cache hit rates, p50/p99 evaluation latency, budget breaches, failed subjects, trace queue depth, trace storage |
| POST | `/formula-operations/recompute-plans` | `ops.write` | Invalidate and rebuild evaluation plans for a tenant |

### 16.5 Error envelope

```json
{ "error": {
    "code": "formula.publish_blocked",
    "message": "Publication blocked by 2 checks.",
    "checks": [
      { "gate": "G5", "code": "dependency_cycle", "severity": "block",
        "message": "Circular dependency: SPECIAL → GROSS → BASIC → GROSS",
        "detail": { "cycle": ["SPECIAL","F-SPECIAL-BALANCE","GROSS","BASIC",
                              "F-BASIC-PCT-GROSS","GROSS"],
                    "scope": {"company":"ametecs-south"},
                    "employees_affected": 1247 } },
      { "gate": "G10", "code": "dependent_test_failed", "severity": "block",
        "message": "Pinned case SE-HYD-AGENT-G3/nominal on structure "
                   "Telangana Collections v7 now fails.",
        "detail": { "expected_minor": 1000000, "actual_minor": 1041667,
                    "case_uuid": "…" } }
    ],
    "request_id": "…", "correlation_id": "…" } }
```

**R-VIII-074** A publish failure returns **every** failing check, not the first.
An author fixing one gate at a time across five round trips is a tooling defect.

---

## 17. Events

### 17.1 Emitted

| Event | When | Payload | Consumed by |
|---|---|---|---|
| `formula.version.drafted` | A draft is created | artefact uuid, version, author | Audit |
| `formula.version.compiled` | Compile succeeds | derived dependencies, derived slices, node count, AST digest | Config graph (Part VI), audit |
| `formula.version.validation_failed` | Any gate blocks | gate rows | Studio UI, audit |
| `formula.version.submitted` | → `pending_approval` | workflow instance id | Workflow engine |
| `formula.version.published` | Publication succeeds | artefact, version, effective range, scope, checksum, impacted employee count | **Payroll (invalidate plans)**, config graph, notifications, audit |
| `formula.version.superseded` | Effective range closed | superseding version id | Payroll, audit |
| `formula.dependency_cycle_detected` | G5 blocks | the cycle path, scope | Ops dashboard, audit |
| `formula.evaluation_failed` | A subject's evaluation errors | employee uuid, formula, error code, node path, period | **Payroll exceptions**, ops alerting |
| `formula.budget_exceeded` | Any runtime budget breach | formula, budget, observed | Ops alerting, circuit breaker |
| `formula.reconciliation_failed` | Any of I1–I10 fails | invariant, run, employee, the two figures | **Aborts the run**; ops alerting; audit |
| `formula.impact_preview.completed` | Async preview finishes | job uuid, deltas, threshold crossings | Studio UI |
| `formula.test.regressed` | A pinned case fails on a dependent publish | case, expected, actual | Publish flow, ops |
| `formula.trace.purged` | A trace partition is dropped by retention | partition, count | Audit |

### 17.2 Consumed

| Event | From | Reaction |
|---|---|---|
| `component.version.published` | Salary structure | Rebuild the dependency graph; re-run dependents' pinned tests; invalidate evaluation plans |
| `structure.version.published` | Salary structure | Recompute the topological order; invalidate plans |
| `statutory.pack.activated` | Rule pack (spine S12) | Invalidate plans; re-run every statutory formula's pinned tests; emit an impact preview |
| `policy.version.published` | Configuration | Invalidate the affected plans and the policy cache |
| `period_settlement.frozen` | Attendance (spine S10) | The settlement slice becomes readable for that period |
| `period_settlement.reopened` | Attendance | Mark every derived line for that employee-period **stale**; queue recalculation |
| `employee.compensation.changed` | Employee | If the effective date falls in a settled period, raise an arrears candidate; **never silently re-price a closed period** |
| `payroll.run.started` | Payroll | Build the input snapshot; freeze the context |
| `rule.version.published` | Rule engine | If the rule suppresses or adds a component, invalidate the affected plans |

**R-VIII-075** `employee.compensation.changed` with an effective date inside a
**closed** period never re-prices that period. It raises an arrears candidate for
the next open run (FL-24). *`IncrementController:342` overwrites `employees.ctc`
in place, so regenerating a past draft prices it at the new CTC with no warning
(`A-payroll.md` §5.3 Q5).*

---

## 18. Configuration surface

### 18.1 What an administrator can change without code

| Change | Where | Requires |
|---|---|---|
| The Basic percentage, the HRA percentage, and which they are of | `policy` values + the component's calculation kind | author + publish |
| Any component's formula, entirely | `cfg_formulas` version | author + publish + tests |
| The rounding mode and unit of any component, head or contribution | Component declaration or a `rounding_profile` | publish |
| Which component absorbs the residue | `balance_component_code` / `residue_head_code` | publish |
| The LOP basis, per company / grade / pay group / employee | Scope on the proration formula | publish |
| A **new** LOP basis (a 25-day divisor, say) | A new `day_length_policy` + a formula referencing it | author + publish |
| The conversion between two day-length bases | `day_length_policies.conversion_num/den` | publish |
| The OT day divisor, shift hours, wage base and multiplier bands | Policy values + component flags | publish |
| Which components enter the PF / ESI / PT / bonus / gratuity / OT / taxable base | Per-component boolean flags | publish (high risk) |
| Encashment divisor, cap and multiplier, per leave type and scope | Policy values | publish |
| The gratuity numerator, denominator, minimum years and ceiling | **Rule pack** (Ametecs-signed) | pack update, no deployment |
| Every statutory rate, ceiling, cap, slab band, threshold and periodicity | **Rule pack** | pack update, no deployment |
| Commission bands, accelerator threshold and points, monthly cap | Policy values, scoped | publish |
| Attendance-incentive bands and their eligibility condition | Policy values + a rule | publish |
| Reimbursement caps and carry-forward | Policy values | publish |
| The minimum-net floor: absolute, percentage of gross, or the greater | Policy values, scoped | publish |
| The recovery order and each type's shortfall policy | `recovery_order` artefact | publish |
| Sample employees and test cases | Sample and test screens | author |

### 18.2 The Section 127 test, applied to this part

The brief's standard is: *"3 late arrivals over 10 minutes = half a day" must not
require code.* The formula-engine equivalents, each demonstrated:

| Customer request | Code change? | How |
|---|---|---|
| "Basic should be 40% of gross, not 50%" | **No** | Change the `basic_pct_of_gross` policy value, effective from a date. FL-01 is unchanged |
| "Basic should be 30% of CTC" | **No** | Change the component's calculation kind to `pct_of_ctc`, referencing FL-02 |
| "For the Pune entity only, HRA is 50% of Basic" | **No** | Publish FL-03 v2 with `scope = {company: Pune}` and `hra_pct_of_basic = 50`. Precedence resolves it (spine S3.2) |
| "One absent day should cost gross ÷ 26" | **No** | Point the company's proration basis at FL-10 |
| "Overtime should divide by payable days, not 26" | **No** | Change `ot_day_divisor` to reference `settlement.scheduled_payable_days` in FL-05 v2 |
| "Overtime is 1.5× on a working day, 2× on a weekly off, 2.5× on a holiday" | **No** | `ot_multiplier_bands` policy data |
| "PF should be computed on Basic + DA + special allowance" | **No** | Set `is_pf_wage = true` on the special-allowance component |
| "ESI should round to the nearest rupee, not up" | **No** | Change the ESI component's `rounding_mode` to `half_up`. **Note:** this is a compliance decision, and the pack may declare the rounding and refuse the tenant override (spine S12 layering) |
| "Commission gets a 0.5-point accelerator above 120% of target, capped at ₹25,000" | **No** | FL-25 with three policy values |
| "Nobody's net may fall below ₹15,000; defer the loan instead" | **No** | FL-28 plus a `recovery_order` shortfall policy |
| "Split Special Allowance into three components in a 50/30/20 ratio" | **No** | Three balance components with weights; `ALLOCATE` guarantees they sum |
| "Add a new state's Professional Tax slab" | **No, and no deployment** | A signed rule-pack update (spine S12) |
| "Our full-and-final gratuity should round to whole rupees" | **No** | The gratuity component's `rounding_unit = 1.00` |
| "Add a per-employee TDS override" | **No** | A variable input declared at `statutory.head`, read as `input.value('TDS_OVERRIDE')`. *SmartPRS 2's own docblock promises this and no column is read (`:1821-1823`)* |
| A genuinely new SPX **function** (a new statistical primitive) | **Yes** — Ametecs release | R-VII-017: the catalogue is closed and versioned |
| A genuinely new **calculation point** | **Yes** — Ametecs release | R-VIII-005 |

**R-VIII-076** Every row above marked "No" has an acceptance test in the
configuration test suite that performs the change through the API and asserts the
resulting payslip figure to the paise. *A configuration promise without a test is
a marketing claim.*

### 18.3 Explainability recap (spine S15)

Every derived money value exposes §10.3's view: the formula code and version, the
authored expression, the substituted values with their provenance, the reduced
arithmetic, the exact pre-rounding rational, the rounding mode and unit, the
rounding delta in paise, any clamp applied, and the composed traces of every
dependency (§10.4). Older than the trace-retention window, the same view is
reproduced from the pinned context digest (R-VIII-054).

---

## 19. Edge cases, failure states and tests

### 19.1 Edge cases and the declared behaviour

| # | Case | Declared behaviour |
|---|---|---|
| E1 | An employee with zero payable days in the period | The proration factor is `0`, not `ERR_DIV_ZERO` — the **denominator** is scheduled days, which is guarded. If scheduled days is also zero (no employment overlap), the employee is **not in the run's population** at all, resolved before evaluation |
| E2 | A structure whose non-balance earnings exceed the gross target | `balance_pool` is negative. The balance component's `non_negative` clamp (if declared) raises `ERR_RANGE` → a **preview exception naming the structure and the overage**, never a negative earning line (*contrast DEF-P20c*) |
| E3 | A structure with **no** balance component and a non-zero residue | Invariant I4 fails at publish (gate G10 evaluates the structure against its sample employees) — blocked, not absorbed into an invented "Special Allowance" line (*contrast `:2257`*) |
| E4 | Two balance components, weights `[0, 0]` | `ERR_ARG` at publish (R-VIII-029) |
| E5 | A formula referencing a component the employee's resolved structure does not contain | Resolves to `MONEY(0,'INR')` **only if** the component is declared `optional_in_structure`; otherwise `ERR_UNRESOLVED_COMPONENT` and a preview exception |
| E6 | A component suppressed by a rule that another formula depends on | Resolves to zero, and the trace names the suppressing rule and version (§7.5) |
| E7 | A statutory pack activating mid-period | Resolution uses the period's `formula_resolution_date` (**D-VIII-05**); the pack version used is pinned on the payslip |
| E8 | A leap-year February with a `fixed30` basis | The divisor is 30 by declaration; `period.calendar_days` is 29; the two are different symbols and neither contaminates the other. *`PayrollGenController` has a latent February overflow in its cut-off maths from using raw `Carbon::day()` (DEF-P14) while a tested `clamp()` sits unused three files away* |
| E9 | A cut-off day of 31 in a 30-day month | `CLAMP_DAY_TO_MONTH` at the period artefact, one choke point |
| E10 | An employee transferred between companies mid-period | `employee.org` resolves per the evaluation date; the run's population and scope are resolved per the effective-dated assignment, so the employee may fall in two lots. Reconciliation I3 is per lot; I2 is per payslip |
| E11 | A currency other than INR in a tenant | Every formula's `currency_code` is declared; a mixed-currency run fails I10 at preview, not at payment |
| E12 | A rounding unit that cannot express the result (₹0.05 quantum on a paise-scaled value) | Publish error at gate G9 |
| E13 | An exact rational whose denominator exceeds 2^64 | `ERR_PRECISION`, evaluation fails loudly. Requires a pathological expression; the budget exists so that "loudly" is the outcome rather than silent truncation |
| E14 | A formula whose result is `Null` (a `COALESCE` chain exhausted) | `ERR_MISSING_INPUT` on a `component` formula: a money line is never `Null`. An `intermediate` formula may return `Null` if its consumers handle it |
| E15 | A sample employee fixture referencing an archived structure version | The test case fails with `fixture_stale`, naming the artefact — it does not silently resolve to the current version |
| E16 | Two formulas with equal specificity and equal priority both matching | **Publication blocked** (R-VIII-010). Never a row-order coin toss |
| E17 | A back-dated increment effective before the employee's joining date | Publish-time impossible (compensation rows are validated against employment); at runtime the arrears window is empty and the arrear is `₹0.00`, traced |
| E18 | A recovery larger than net after the floor is applied | The floor formula returns the full recovery as deferred; the recovery ledger carries the whole balance forward; net equals the floor exactly |
| E19 | A trace whose partition has been dropped | `GET /calculation-traces/{uuid}` returns `410` with a `reproduce` link; `POST /explain/reproduce` rebuilds it (R-VIII-054) |
| E20 | An `agg.balance` on a ledger whose snapshot is dirty | `ERR_AGG_UNAVAILABLE` (Part VII §5.8), the subject is queued for recalculation, and the batch continues with the others |
| E21 | A formula published with `effective_from` in the past, over a closed period | Blocked by the finalised-period guard (spine S4, `platform.config.guard`). *`SettingsController::save()` has no period check at all and no effective date (`A-payroll.md` §5.3 Q3)* |
| E22 | Zero punches for the whole period | Not this part's decision — the settlement supplies `payable_days`, and what the settlement says for a zero-punch employee is a policy (**D-VIII-04**, jointly with Part VII D-VII-04) |

### 19.2 Failure states

| State | Trigger | Behaviour | Surfaced |
|---|---|---|---|
| `evaluation_failed` | Any `ERR_*` during evaluation | The subject is marked an exception; the batch continues; the failure count is reported | Payroll preview exceptions; ops alert if the rate exceeds a threshold |
| `publish_blocked` | Any gate at `block` | Nothing is published; every failing check is returned (R-VIII-074) | Studio UI |
| `reconciliation_failed` | Any of I1–I10 | **The run aborts**; nothing is persisted as approved | Run status, ops alert, audit |
| `plan_stale` | A dependency's version changed after a plan was cached | The plan is invalidated and rebuilt; **derived records produced under the old plan are marked stale** and queued for recalculation | Run banner, recalculation queue |
| `budget_exceeded` | A runtime budget | Evaluation aborts for that subject; the circuit breaker trips at a rolling rate | Ops dashboard, naming the formula |
| `precision_exhausted` | `ERR_PRECISION` | Evaluation aborts loudly | Ops alert — indicates a pathological formula |
| `trace_queue_backlogged` | Async trace writes fall behind | Full traces degrade to sampling (§13.5); **pinned traces never degrade** | Ops dashboard |
| `fixture_stale` | A test fixture references an archived artefact | Test fails, naming the artefact | Publish flow |

**R-VIII-077** There is no silent catch anywhere in the formula path. A CI
static-analysis rule fails the build on any `catch` in `Domain\Formula` that does
not rethrow, log with a correlation id, or convert to an explicit exception
record. *SmartPRS 2 destroys 50.5% of its caught exceptions; the payroll
regeneration path wraps **five** side-effect reversals in individual try/catch
blocks specifically so that a failure never blocks (`A-payroll.md` §5.3 Q7).*

### 19.3 Tests that must have exact-value regression coverage

Every one asserts an exact figure in minor units.

| # | Test | Assertion |
|---|---|---|
| T-01 | FL-01, CTC ₹6,00,000 | BASIC = 2,500,000 paise |
| T-02 | FL-01, CTC ₹13,00,000 | gross 10,833,333 · BASIC 5,416,667 · rounding delta +0.5 paise recorded |
| T-03 | FL-02 vs percent-of-gross, CTC ₹6,60,000 incl. ₹60,000 employer PF | pct_of_ctc = 1,650,000; pct_of_gross = 1,500,000; **the two differ** |
| T-04 | FL-04 three-way `[50,30,20]` on ₹32,499.99 | `[1,624,999 · 974,999 · 650,001]`, sum = 3,249,999 |
| T-05 | `ALLOCATE(₹1,00,000, [1,1,1])` | `[3,333,333 · 3,333,333 · 3,333,334]` |
| T-06 | `ALLOCATE(₹1,00,000, [1,1,1], ₹1)` | `[3,333,300 · 3,333,300 · 3,333,400]` |
| T-07 | `ALLOCATE` with a negative amount | Residue invariant holds; sum is exact |
| T-08 | FL-05/FL-06, gross base, 8h at 2×, divisor 26, shift 8h | 384,615 paise (₹3,846.15) |
| T-09 | FL-05/FL-06, Basic+DA base, same inputs | 192,308 paise (₹1,923.08) |
| T-10 | FL-07, 25/27 on ₹50,000 | 4,629,630 paise |
| T-11 | FL-08, 29/31 on ₹50,000 | 4,677,419 paise |
| T-12 | FL-09, 28/30 on ₹50,000 | 4,666,667 paise |
| T-13 | FL-10, 24/26 on ₹50,000 | 4,615,385 paise |
| T-14 | FL-11 both settlement shapes | 4,677,419 and 4,629,630 respectively |
| T-15 | FL-12, per-component LOP | 185,185 + 74,074 + 111,111 = 370,370 = gross − prorated (**I5**) |
| T-16 | FL-13 joiner, cut-off 25, joined 06-Jul | 3,333,333 paise |
| T-17 | FL-13 leaver, exit 10-Jul | 2,500,000 paise |
| T-18 | FL-13 joiner **and** leaver in one period | 1,833,333 paise |
| T-19 | FL-14, 12.5 days at ₹25,000 ÷ 30 | 1,041,667 paise |
| T-20 | FL-14, 0.5 days | 41,667 paise — half-day units are representable |
| T-21 | FL-15, 3 days at ₹50,000 ÷ 26 | 576,923 paise |
| T-22 | FL-16, ₹25,000 × 15/26 × 7, unit ₹0.01 | 10,096,154 paise |
| T-23 | FL-16, same, unit ₹1.00 | 10,096,200 paise — **and both are reachable only by declaration** |
| T-24 | FL-17, ₹7,000 × 8 × 8.33%, whole rupee | 466,500 paise (₹4,665.00) |
| T-25 | FL-18, Telangana ₹22,500 | 20,000 paise |
| T-26 | FL-18, Maharashtra ₹15,000 in Feb vs Mar | 30,000 vs 20,000 paise |
| T-27 | FL-19, PF wage ₹22,000, ceiling ₹15,000, 12% | 180,000 paise |
| T-28 | FL-21/22, `round_then_cap` whole rupee | EPS 125,000 · EPF 55,000 · **sum = employer total (I6)** |
| T-29 | FL-21/22, 2dp (SmartPRS 2 parity) | EPS 124,950 · EPF 55,050 · sum still exact — the invariant holds under both declarations |
| T-30 | FL-23 EDLI | 7,500 paise |
| T-31 | FL-24, ₹6L → ₹7.2L effective 15-May, processed July | May 548,387 + June 1,000,000 = 1,548,387 paise (**I7**) |
| T-32 | FL-24, a back-dated **decrease** | A negative arrear is produced, not nothing |
| T-33 | FL-25, ₹6,00,000 / target ₹4,00,000 with accelerator | 2,100,000 paise |
| T-34 | FL-25, ₹9,00,000, cap binds | 2,500,000 paise |
| T-35 | FL-25, no accelerator no cap (SmartPRS 2 parity) | 1,800,000 paise |
| T-36 | FL-26, late counts 2 / 4 / 7 | 200,000 / 100,000 / 0 paise |
| T-37 | FL-27, all three carry-forward cases | 160,000 / 240,000 / 100,000 paise |
| T-38 | FL-28, the full deferral scenario | deferral 1,080,000; net after deferral = the floor exactly |
| T-39 | `Days + Hours` | **Publish-time type error**, not a silent coercion |
| T-40 | `Money * Money` | **Publish-time type error** |
| T-41 | `Money / Days` then `× Days` | Round-trips to `Money` with no precision loss |
| T-42 | `Money / Days` then `× Hours` | **Publish-time type error** |
| T-43 | `(gross / 27) * 25` vs `(gross * 25) / 27` | **Identical to the paise** — the rational-intermediate property |
| T-44 | A cycle `BASIC → GROSS → BASIC` | Publication blocked; the error names the full cycle path |
| T-45 | An unguarded division whose denominator is reachably zero | Publication blocked (G6) |
| T-46 | A `BANDS` table that is unsorted / gapped / overlapping / has no terminal band | Publication blocked (G7), one test each |
| T-47 | Publishing a component version that breaks a dependent's pinned case | Blocked, showing expected and actual |
| T-48 | A slice used but not declared | Publication blocked (G8) |
| T-49 | A formula with two cases instead of three | Publication blocked (G10) |
| T-50 | Two formula versions, equal specificity and priority, both matching | Publication blocked (R-VIII-010), naming both |
| T-51 | Re-running a closed period after a pack change | Reproduces the **original** figures; the changed input is named in the warning |
| T-52 | Cross-tenant isolation | A formula, sample, test case and trace of tenant A is invisible and unresolvable from tenant B, on every endpoint |
| T-53 | Trace completeness | Every payslip line has a resolvable trace naming every version that produced it |
| T-54 | Trace reproduction after purge | The rebuilt trace is byte-identical in its value fields to the purged one |
| T-55 | 40 components × 10,000 employees | Completes within the §13.2 budget; I1–I10 all pass |
| T-56 | One employee's evaluation fails mid-batch | The other 9,999 complete; the batch reports failure count 1 and **does not report success** |
| T-57 | Float ban | Static analysis finds no float in any money path, including serialisation |

---

## 20. Open items — PRODUCT OWNER DECISION REQUIRED

**D-VIII-01 · Does a dependent formula read the rounded result or the exact value?**
§6.2 specifies `reference_value = rounded` by default.
**Recommendation: rounded.** A payslip must be re-derivable from the numbers
printed on it: if HRA is computed from an unrounded Basic while the payslip
prints a rounded Basic, an employee who recomputes 40% of the printed Basic gets
a different HRA and is right to query it. *Trade-off:* a long dependency chain
accumulates up to `n × rounding_unit` of drift against a single exact
computation. Mitigated by the `intermediate` formula kind, which lets an author
opt a named sub-expression out of rounding deliberately, and by invariant I9,
which bounds and reports the drift. If the Product Owner prefers exact
composition, it must be **per formula and visible in the trace**, never a global
switch — because the answer differs by component and the employee-facing
consequence differs with it.

**D-VIII-02 · Which LOP bases ship, and at what scope?**
This part implements five (FL-07 to FL-11) where SmartPRS 2 has three, one of
which the FAQ headlines and none of which is scoped.
**Recommendation: ship all five; make `payable_days` (FL-11) the default for new
tenants; scope the choice at company, grade and pay group.** Payable days is the
only basis whose numerator and denominator come from one settled source, so the
§5.3 unit defect cannot recur under it. *Trade-off:* migrating customers are on
`working`, `calendar` or `fixed30`, and moving them changes money. The migration
therefore **preserves each customer's current basis exactly** and offers the
change as a dated, previewed policy decision after go-live. This decision also
answers `A-payroll.md` OQ-2 and must be taken with it.

**D-VIII-03 · The overtime divisor and wage base at cutover.**
SmartPRS 2 hard-codes `CTC / 12 / 26 / 8` in two files, so the OT wage base is
effectively "the whole gross" and the divisor is 26.
**Recommendation: seed each customer at 26 and gross — preserving their current
figures to the paise — and surface the divisor, the shift hours and the wage
base as three configuration values from day one**, with the impact preview
showing what changing each would cost. *Trade-off:* the 26 persists until the
customer acts. The alternative silently changes every overtime payment on day
one of a new system, which is the worst possible first impression of a payroll
migration.

**D-VIII-04 · The proration treatment of an employee with no punches at all.**
SmartPRS 2 pays 100% (`compute():1237`), deliberately, so that a broken
biometric device cannot zero a salary — at the cost that a genuinely absent
employee is paid in full.
**Recommendation: `flag for review`** — the settlement records zero payable days
and the payroll preview blocks with a named exception, so a human decides.
*Trade-off:* an operator must clear the exception; that is the point. **This must
be decided jointly with Part VII D-VII-04 and `A-payroll.md` OQ-1, because it is
one behaviour surfacing in three parts.**

**D-VIII-05 · Which date resolves a formula version within a period?**
A formula effective 15-Aug inside an August period.
**Recommendation: `period.end` by default, declared per calculation point, with
`per_day` available for day-derived calculations (attendance-driven LOP and OT).**
A monthly component computed twice at two rates and summed is not what most
customers mean by a mid-month change; a day-rate that changes mid-month is.
*Trade-off:* an author who wants split-rate behaviour on a monthly component must
express it as two dated versions plus an arrear, which is more work but is also
what appears on the payslip.

**D-VIII-06 · Which variable-pay components enter which statutory base?**
Today commission, incentive, overtime, night allowance and arrears attract **no**
PF, ESI, PT or TDS (BR-STAT-006), and this part makes it configuration rather
than deciding it.
**Recommendation: this is a compliance ruling, not an architecture decision.**
It must be settled by the named compliance owner (spine D-03) **before** the
seeded packs are authored, because the answer determines the default flags every
customer inherits at cutover and carries retrospective exposure. This part's
contribution is that the answer is a per-component flag, changeable per scope,
dated, previewed and traced. **Duplicates `A-payroll.md` OQ-3 and must be
answered once.**

**D-VIII-07 · May a tenant administrator author formulas, or only an Ametecs consultant?**
Part VI raises this as **D-VI-01** and recommends consultant-only.
**Recommendation: consistent with D-VI-01 — consultant authors, tenant approves
and publishes**, with the twelve gates, the mandatory tests, the impact preview
and maker-checker as the guard rails. *Trade-off:* it caps the
"configuration, not code" promise at the most visible point, and every change
becomes a service request. Option C in Part VI — the tenant may edit *values*
inside a formula but not its structure — is the right eventual answer and is
already achievable here without a new artefact shape, because the values in every
formula in §11 are `policy.value(...)` and `statutory.value(...)` references
rather than literals. **Recommendation: grant tenants policy-value editing on
day one and formula authoring only after the engine is proven in production.**

**D-VIII-08 · The rounding and cap order for statutory sub-heads.**
§6.3 and FL-21 show that `round_then_cap` at whole rupee, `cap_then_round` at
whole rupee, and 2dp rounding produce three different EPS figures from identical
inputs, and that SmartPRS 2 silently takes the third.
**Recommendation: the rule pack declares both the rounding and the order, per
head, with a `source_reference`, and the tenant cannot override a pack-declared
rounding downward.** *Trade-off:* Ametecs takes on the obligation to be right,
and the compliance owner must verify each. That obligation already exists; today
it is discharged by an accident of `round($x, 2)`.

**D-VIII-09 · The minimum-net-pay floor: defer recoveries, or block the run?**
FL-28 defers.
**Recommendation: defer, and raise a visible per-employee note on the payslip
and in the preview.** A blocked run on payday because one employee's loan is
large helps nobody; a deferral with a named reason and a growing recovery balance
is honest and self-correcting. *Trade-off:* a deferral that recurs indefinitely
hides a loan the employee cannot service. Mitigated by an ageing report on
deferred recoveries and an escalation after a configurable number of periods.

**D-VIII-10 · Arrears: recompute-and-difference, and are arrears taxable?**
FL-24 recomputes the target period and differences it, rather than applying a
day fraction to a monthly delta.
**Recommendation: recompute-and-difference.** It is the only method that produces
the correct PF, ESI, PT and TDS on the arrear, and the only one that survives a
structure change between the target period and the processing period.
*Trade-off:* it costs a full pipeline re-run per target period per employee,
which is why §13's budget accounts for a 12× recomputation. The taxability
question — SmartPRS 2 forces `tds_rate = 0` on arrears
(`IncrementController:405`) — is a **compliance ruling** (`A-payroll.md` OQ-10),
including whether relief under section 89(1) must be supported, and it must be
answered with D-VIII-06.

---

## 21. Compliance with the seven laws, and requirement index

### 21.1 Law compliance

| Law | How this part complies | Evidence |
|---|---|---|
| **1 — No business literal in code** | Every percentage, divisor, ceiling, cap, multiplier, band and threshold in §11 is a `policy.value`, a `statutory.value` or a pack slab. The engine holds arithmetic; the configuration holds policy. The literal `26`, the literal `0.5`, the literal `0.4`, the literal `30.44` and the literal `1250` all become data | §11 (all 28), §18.1, §18.2 |
| **2 — No schema change at runtime** | The engine issues no DDL. Formulas are rows; test cases are rows; traces are rows; calculation points, types and rounding modes are seeded by migration. There is no "custom formula column" and no self-healing schema | §14 |
| **3 — Nothing overwritten; everything versioned and dated** | Published formula versions are immutable in every field; publishing supersedes; derived records pin the version ids transitively; a closed period cannot be re-priced by a later publish | R-VIII-008, R-VIII-069, R-VIII-075, E21 |
| **4 — Every number explains itself** | No result without a trace, enforced by a throw; the trace names the authored expression, the substituted values with provenance, the exact rational, the rounding mode and its delta in paise, every clamp and every dependency; reproducible after purge | R-VIII-050, R-VIII-051, R-VIII-054, §10 |
| **5 — Authorisation is server-side, declarative, fails closed** | Formulas evaluate after authorisation inside a tenant-scoped context; simulation resolves its subject under the caller's own data scope; every route has an explicit policy binding; trace reads are audited; there is no DELETE | R-VIII-072, R-VIII-073, §16 |
| **6 — Tenancy is structural** | `tenant_id NOT NULL` on every table in §14 except `calculation_points` (a platform catalogue, stated explicitly), participating in every unique index; T-52 asserts isolation on every endpoint | §14, T-52 |
| **7 — Money is exact** | Integer minor units in a value object end to end; exact rational intermediates; **`Money * Money` and `Money → Number` are type errors**; rounding is declared and applied once; `ALLOCATE` sums to the whole by construction; ten invariants assert it on every run and abort on failure | §5.4, §6, R-VIII-020, R-VIII-031, T-04 to T-07, T-40, T-57 |

### 21.2 Requirement index

R-VIII-001 … R-VIII-077, **77 numbered testable requirements**:

| Range | Subject | Section |
|---|---|---|
| 001–010 | Purpose, boundary, calculation points, effective dating | §3 |
| 011–013 | The formula model and its publish gates | §4 |
| 014–019 | Types, coercion, unit typing, the operation matrix | §5 |
| 020–032 | Money representation, rationals, rounding, `ALLOCATE`, negatives, invariants | §6 |
| 033–040 | The dependency graph, cycles, ordering, memoisation, disabled components | §7 |
| 041–047 | The calculation context, slices, immutability, the digest | §8 |
| 048–049 | The mandatory sample-employee test set | §9.10 |
| 050–055 | The calculation trace, composition, storage, reproduction, PII | §10 |
| 056 | `salary.wage_base` and the abolition of name-driven semantics | §11.0 |
| 057–062 | Sample employees, pinned tests, impact preview, the generated guide | §12 |
| 063–068 | Performance targets, caching, batch path, budgets, trace sampling | §13 |
| 069 | Pinned versions on derived records | §14.8 |
| 070–071 | Migration parity and defect-correction acknowledgement | §15.4 |
| 072–076 | API deletion prohibition, simulation scope, error completeness, compensation events, configuration acceptance tests | §16–18 |
| 077 | No silent catch | §19.2 |

### 21.3 Dependencies on other parts

| This part needs | From |
|---|---|
| The SPX grammar, function catalogue, error model and aggregation primitives | **Part VII §5** — shared, not duplicated |
| Component definitions, statutory-base flags, structures, rounding profiles | Part on compensation / salary structure |
| The frozen period settlement and the recalculation command | Part on attendance (spine S10) |
| The payroll pipeline's staging, input snapshot and preview exceptions | Part on payroll (spine S11) |
| Rule-pack signing, layering, slab tables and the validator | Part on statutory (spine S12) |
| Eligibility rules (`ADD_COMPONENT`, `SUPPRESS_COMPONENT`) | Part VII §6 |
| Maker-checker on publication | Part IX (spine S8) |
| Permissions and data scope | Part on security (spine S13) |
| The audit ledger | Part on audit (spine S14) |
| Config artefacts, versions, the validator gates and the config graph | Part VI |
| Table conventions, `cfg_formulas`, `payslip_lines`, `calculation_traces` | Part XXVIII |

### 21.4 What this part deliberately leaves to others

**The content of a customer's formulas.** This part specifies the mechanism, the
type system, the money discipline, the twelve gates and twenty-eight worked
formulas covering every calculation catalogued in `A-payroll.md` §4. Which of
them a given customer publishes, with which percentages, at which scopes, is an
implementation decision recorded in that customer's configuration — which is
precisely the outcome the whole architecture exists to produce.

It also leaves to the compliance owner every statutory **value** appearing in
§11. Not one of them is asserted here as legally correct, and each is shown
either as a `<from rule pack>` reference or as *"the value SmartPRS 2's code
currently uses"*.

---
