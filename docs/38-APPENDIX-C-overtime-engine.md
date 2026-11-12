# APPENDIX C — THE OVERTIME ENGINE

Status: DRAFT FOR PRODUCT OWNER APPROVAL
Owner: Ametecs India Private Limited
Authority: subordinate to `SPINE.md`. Where this appendix and a numbered part
disagree, the **owning part** wins and the disagreement is recorded in §C.22.
Brief section owned here: **31 — the overtime engine.**
Closes: **Part XXXV GAP 6**, requirement `R-XXXV-028`.

---

## C.0 Why this appendix exists

### C.0.1 The audit finding, named

The traceability audit of the thirty-four-part blueprint (Part XXXV) traced twelve
governing-brief sections to no owning part. Eleven were prose gaps. **Section 31 —
the overtime engine — was not a prose gap. It was a broken reference.**

> **`R-XXXV-028`** — *"Overtime MUST be assigned to an owning part (Part XI
> recommended), covering the multiplier artefact that `CREATE_OT`'s
> `multiplier_ref` points at, daily/weekly/statutory caps, holiday and weekly-off
> premium rates, the approval workflow, the OT register, and the comp-off
> interaction. **Until `multiplier_ref` has a defined target, `CREATE_OT` is not
> implementable.**"*

Overtime appears in four parts of the blueprint. Each names it. **None specifies
it.**

| Part | What it says about overtime | What it assumes exists elsewhere |
|---|---|---|
| **XI** — Attendance | The Time Settlement Engine (§XI.11.3) emits `ot_minutes` as a first-class output of every employee-day; §XI.15 row 17 promises OT eligibility, threshold, rounding, multipliers by day type, approval mode and caps as configuration | A policy artefact holding all seven of those things |
| **VII** — Rule engine | The action `CREATE_OT(minutes, multiplier_ref, approval)` is in the closed attendance action catalogue (§6.2); RL-ATT-005 writes `multiplier_ref = 'OT-MULT-WEEKDAY'` | An artefact that the code `'OT-MULT-WEEKDAY'` resolves against |
| **XV** — Salary structure | The component flag `is_ot_wage` declares that a component enters the overtime rate base (`salary.wage_base('OT')`) | A rate model that consumes that wage base |
| **XIV** — Payroll | Pipeline stage 11 is `overtime`, consuming stage 5's OT minutes and formulas FL-05/FL-06, producing *"OT earning line(s) by multiplier band"* | A definition of what a *band* is, where bands live, and what selects one |
| **XII** — Calendar | `work_on_off_outcome = overtime` routes weekly-off and holiday work to *"OT at `overtime_multiplier_ref`"* (R-XII-015) | The same missing artefact, under a third spelling |
| **XIII** — Leave | `stacks_with_overtime = false` is the enforced default: the same worked minutes may not produce both a comp-off credit and paid overtime (R-XIII-063) | An enforcement point that owns "the same minutes" |

Five parts, four spellings of one reference — `multiplier_ref`,
`overtime_multiplier_ref`, `ot_multiplier_ref`, `ot_multiplier_bands` — and no
table, no columns, no resolution rule, no precedence, no lifecycle.

**This is not a documentation defect. `CREATE_OT` cannot be built.** A rule author
can write `multiplier_ref = 'OT-MULT-WEEKDAY'` today and the publish-time symbol
validator has nothing to validate it against; the string would be accepted,
stored, and would fail at evaluation time in production — which is exactly the
class of failure the seven laws exist to make impossible.

### C.0.2 What this appendix does

It defines the missing artefact and the engine around it: the overtime policy,
its multipliers, its rate model, its caps, its approval, its register, its
payroll integration, its trace and its migration. It is written to the same
standard as a numbered part — Section 123 tables, Section 125 module depth,
Section 127 configuration acceptance — because Section 31 is a money-moving
calculation and Part XXXV's own words apply:

> *"…overtime is a money-moving calculation in a country with statutory overtime
> rules."*

**R-C-001** This appendix MUST be read as a full part for the purposes of spine
S20 conformance. Its requirements carry the prefix `R-C-nnn` and are testable
statements.

**R-C-002** After this appendix is approved, `multiplier_ref` has exactly one
defined target — a row in `cfg_overtime_multipliers` (§C.14.2) — and the
publish-time symbol validator MUST reject any `CREATE_OT` whose `multiplier_ref`
does not resolve to a published multiplier visible in the rule's scope on the
rule's effective range. *`R-XXXV-028` is closed by this requirement and by
§C.3.*

**R-C-003** This appendix decides **no statutory question**. Which statutory
bases overtime enters is a compliance ruling already registered as `D-XIV-03`
(Part XIV) and `D-XVIII-07` (Part XVIII). §C.11 references that ruling and does
not re-open it, does not pre-empt it, and asserts no legal position. Every rate,
multiplier, divisor, cap and threshold in this appendix is either recorded as
*"the value SmartPRS 2's code currently uses"* or shown as a `<from policy>` or
`<from rule pack>` placeholder.

---

## C.1 What SmartPRS 2 does today, and why it must change

### C.1.1 The finding in one sentence

**SmartPRS 2 computes overtime from biometric punches, displays it on a report,
and throws it away — then pays overtime from a register a human types by hand.**

### C.1.2 The evidence

| # | Behaviour | Citation | Consequence |
|---|---|---|---|
| 1 | `overtime_min = (lastOut > shiftEnd) ? mins(shiftEnd, lastOut) : 0`, computed at report-render time | `AttendanceReportController.php:729-735` (CALC-B-10) | A derived OT figure exists |
| 2 | That figure is **never written to the `overtime` register and never reaches payroll** | CALC-B-10; `B-attendance.md` §5.2 item 26 | The biometric data the customer bought the system for does not drive the overtime they pay |
| 3 | The `overtime` table is a **purely manual master** | `MasterController.php:339-345` | Every paid OT minute in every SmartPRS 2 installation was typed by a person |
| 4 | OT is valued at entry, only when the amount field is left blank: `hourly = ctc / 12 / 26 / 8`; `amount = hours × multiplier × hourly`; multiplier defaults to **2** | `MasterController::save():755-763` (BR-PAY-732) | **`26` and `8` are literals.** They are the values SmartPRS 2's code currently uses; they are not asserted here as legally correct, and the analysis records `26` as *"a convention, not law"* |
| 5 | The identical formula is duplicated in a second file | `PayrollGenController::simulate():2165` | Two copies that can drift, and did not have to exist |
| 6 | A manually typed amount is **never validated** against hours × multiplier × rate | BR-PAY-732 | Any number can be paid as overtime, with no arithmetic anyone can reconstruct |
| 7 | Payment selects only rows with `status = 'approved'` **and** `amount > 0`, dated in the period; the payslip line is the literal string `'Overtime'` | `overtimeByEmployee():943-967`; line `:2644-2647` (BR-PAY-016) | A zero-valued approved row silently disappears; the line is name-driven |
| 8 | OT is added **after** `computeSlip` returns, so **no PF, ESI, PT or TDS is charged on it** | `compute():1350`, `:1391` (BR-STAT-006) | *"ESI is statutorily due on overtime; all of it is taxable salary"* — the statutory analysis records this as a **live compliance exposure**. See §C.11 |
| 9 | `overtime.paid_run_id` is created by **runtime DDL** during the payroll run | `generate():2571-2578` (BR-PAY-041) | The column that keys OT to a run does not exist until a run has been attempted |
| 10 | On run regeneration, OT rows are reverted `paid → approved` and `paid_run_id` is cleared | `generate():2422-2432` | **This is structurally correct** and is preserved — see §C.10.4 |
| 11 | The UI offers exactly three multipliers: 1, 1.5, 2 | BR-PAY-732 | No holiday premium, no national-holiday premium, no night premium, no tiering |
| 12 | OT is **not prorated**, and there is no OT eligibility model | BR-PAY-016; `A-payroll.md` §4.2 | Anyone can be given OT; nothing decides who may earn it |

### C.1.3 What does not exist at all

A repository-wide reading of the attendance and payroll analyses finds **no**
overtime eligibility model, **no** approval workflow distinct from a status
column, **no** cap of any kind — daily, weekly, monthly or statutory — **no**
register derived from punches, **no** distinction between a normal day, a weekly
off, a holiday, a national holiday and night work, **no** rounding or minimum
block, and **no** relationship with comp-off, because comp-off does not exist
either (`B-attendance.md` §5.2 item 17; Part XIII R-XIII-061).

### C.1.4 Why each of the following sections exists

Every design choice below has a named origin. The table is the answer to *"why is
this so elaborate?"*

| Section | Exists because |
|---|---|
| §C.3 policy artefact | `multiplier_ref` points at nothing (`R-XXXV-028`) |
| §C.4 eligibility | There is no eligibility model; the governing brief asks for branch- and grade-level eligibility, which SmartPRS 2 cannot express |
| §C.5 qualifying conditions | The only condition is `lastOut > shiftEnd` (CALC-B-10) |
| §C.6 rate model | `26` and `8` are literals in two files (BR-PAY-732, items 4–5) |
| §C.7 rounding and caps | Neither concept exists |
| §C.8 comp-off exclusivity | Comp-off does not exist, so nothing prevents double payment once it does |
| §C.9 approval | `status = 'approved'` is a column a person sets, not a workflow |
| §C.10 register and payroll | The register is manual (item 3) and the run key is created by runtime DDL (item 9) |
| §C.11 statutory | BR-STAT-006 — a live compliance exposure |
| §C.12 explainability | No OT figure in SmartPRS 2 can be explained; a typed amount has no arithmetic at all (item 6) |
| §C.17 migration | Manual rows exist and must come across; derived OT was discarded and cannot be recovered |

---

## C.2 The engine in one page

Overtime in SmartPRS 3 is not a module. It is **one policy artefact consumed at
six existing points**, none of which is new:

```
 Part XI  punches → attendance_days → Time Settlement Engine (§XI.11)
                                            │
                                            │ surplus_minutes, day context
                                            ▼
 Part VII  decision point attendance.day.earn
              rule WHEN … THEN CREATE_OT(minutes, multiplier_ref, approval)
                                            │
                                            ▼
 THIS APPENDIX   resolve overtime policy  (scope + date, spine S3/S4)
                     ├── eligibility        →  eligible?              §C.4
                     ├── qualifying         →  which minutes qualify? §C.5
                     ├── rounding + block   →  how many minutes?      §C.7
                     ├── caps               →  how many may be paid?  §C.7
                     ├── multiplier_ref     →  segments + factors     §C.3/§C.6
                     └── approval regime    →  who must say yes?      §C.9
                                            │
                                            ▼
                  overtime_entitlements  (the OT register — §C.10)
                                            │
                     ┌──────────────────────┴───────────────────────┐
                     ▼                                              ▼
 Part IX  workflow approval (pre / post)          Part XIII  comp-off ledger
                     │                            Part XII   work_on_off_settlements
                     ▼                                       (exactly one outcome — §C.8)
 Part XIV  pipeline stage 11 `overtime`
              FL-05 rate  ×  segment hours  ×  segment multiplier      §C.6
                                            │
                                            ▼
 Part XVI  payslip line(s), one per (component, multiplier)  + trace   §C.12
```

**Nothing in this diagram is a new engine.** The rule engine, settlement engine,
formula engine, workflow engine, ledger pattern and payroll pipeline all already
exist. What was missing is the artefact in the middle.

---

## C.3 The overtime policy artefact — what `multiplier_ref` points at

### C.3.1 Shape

**R-C-004** Overtime is governed by a **scoped, effective-dated, versioned
configuration artefact** of `artefact_type = 'overtime_policy'`, using the spine
S4 envelope (`config_artefacts` + `config_versions`) with typed child rows. It is
resolved by the standard call `resolve('overtime_policy', key, scope, as_of)`.
*SmartPRS 2 has no overtime policy of any kind; the only OT configuration is a
three-value multiplier dropdown on a data-entry form (BR-PAY-732).*

**R-C-005** The artefact is declared `high_risk` (spine S4), so publication
requires a **diff preview** naming old value, new value, impacted employee count
and effective date, and a maker-checker approval through the workflow engine.
*Changing an OT multiplier changes money; SmartPRS 2 changes it by editing a
dropdown selection on one row.*

**R-C-006** The artefact is **not** declared `continuous`. A gap in overtime
policy coverage is legitimate and means "no overtime is payable in that scope on
those dates" — which is a real customer state, not an error. A gap resolves to
**no policy**, and no policy means `ot_minutes = 0`, never a default multiplier.
*This is the direct refusal of SmartPRS 2's `$mult > 0 ? $mult : 2` fallback
(`MasterController.php:762`), which pays double time to anyone whose multiplier
field was left blank.*

### C.3.2 The nine declared groups

**R-C-007** An overtime policy version carries exactly nine groups of declared
values. Every one is data; none may appear as a literal in application code
(Law 1).

| # | Group | What it declares | Detailed in |
|---|---|---|---|
| 1 | **Identity and scope** | `policy_code`, name, business description, `scope_json`, `priority`, effective range, version, status | §C.3.3 |
| 2 | **Eligibility** | Who may earn overtime at all, and by what predicate | §C.4 |
| 3 | **Qualifying conditions** | Which minutes of which days qualify, as SPX | §C.5 |
| 4 | **Rate basis** | The wage base (by flag), the divisor model, the rate rounding | §C.6.1–§C.6.3 |
| 5 | **Multipliers** | The `multiplier_ref` target: contexts, tiers, factors, output components | §C.6.4 |
| 6 | **Rounding and minimum block** | Mode, unit, block size, block application point | §C.7.1 |
| 7 | **Caps** | Daily, weekly, period, statutory, with per-cap breach behaviour | §C.7.2 |
| 8 | **Approval** | Regime, workflow codes, re-approval behaviour on change | §C.9 |
| 9 | **Payout treatment** | Output component(s), proration stance, settlement exclusivity, payout channel | §C.8, §C.10 |

**R-C-008** Each group is a typed child table, not a JSON blob. `scope_json` is
JSON because Scope is genuinely free-form across twenty-one dimensions (spine
S3.1); everything else is columns, because everything else is queried, validated,
diffed and reported. *Spine S4: "typed child rows; JSON only for genuinely
free-form payloads."*

### C.3.3 Resolution and precedence

**R-C-009** Two overtime policies matching one employee on one date are resolved
by the **standard precedence** of spine S3.2: specificity by summed dimension
weight, then declared `priority`, then **publication is blocked**. There is no
third tie-break and no row-order fallback. *SmartPRS 2 resolves its three-level
scope by row order (spine S3.2), and has no scoped OT configuration at all to
resolve.*

**R-C-010** Resolution is **as at the date the work was performed**, not the date
the payroll ran and not the date the entitlement was approved. An OT policy
published in October cannot change August's overtime. *This is spine S4's core
rule and the direct fix for SmartPRS 2's in-place policy edits.*

**R-C-011** The resolved policy version id, the resolved multiplier version id
and every formula version id are **pinned onto the entitlement row at creation**
(§C.14.5) and re-read from the pin at payment. A superseding version never
retroactively re-prices an existing entitlement; a correction does, and a
correction is an explicit, audited, previewed operation (§C.19 edge 11).

### C.3.4 The multiplier reference — the closed hole

**R-C-012** `multiplier_ref` is a `Code` naming a row in `cfg_overtime_multipliers`
(§C.14.2), unique per `(tenant_id, overtime_policy_version_id, multiplier_code)`.
The resolution is:

```
multiplier_ref = 'OT-MULT-WEEKDAY'
   → resolve('overtime_policy', <policy for this employee on this date>)
   → cfg_overtime_multipliers WHERE multiplier_code = 'OT-MULT-WEEKDAY'
   → one multiplier definition:
        resolution_mode  fixed | by_context
        contexts/tiers   cfg_overtime_multiplier_bands rows
        output component component_code (per band, optional)
```

**R-C-013** A `multiplier_ref` that does not resolve is a **publish-time error on
the rule**, not a runtime error on the payslip. The rule engine's publish-time
symbol validator (spine S6, "conflict detector") MUST resolve every
`multiplier_ref` in every `CREATE_OT` action against the overtime policies visible
in that rule's scope over that rule's effective range, and block publication with
a message naming the rule, the unresolved code and the scopes checked.

**R-C-014** A `multiplier_ref` that resolves at publish time but is superseded to
nothing before evaluation (the policy's effective range was closed) produces a
**rule evaluation failure** surfaced to the operator as a blocking attendance
exception — never a silent zero and never a default multiplier (spine S5.6).

---

## C.4 Eligibility

### C.4.1 Two independent gates

**R-C-015** Overtime eligibility is decided by **two** gates, both of which must
pass. They are separate because they answer different questions and are
administered by different people:

| Gate | Question | Mechanism | Administered by |
|---|---|---|---|
| **Structural** | Does an overtime policy resolve for this employee on this date at all? | Scope (spine S3.1) + effective dating | HR / configuration owner |
| **Predicate** | Given that a policy resolves, is *this* employee eligible under *this* policy's own conditions? | `eligibility_expr`, an SPX Boolean on the policy | Policy author |

The structural gate answers *"Hyderabad yes, Pune no"* by scope. The predicate
gate answers *"…but not while on probation, and not if they are on a fixed
monthly OT allowance"* without needing a second policy.

**R-C-016** Where **no** overtime policy resolves, the employee earns no
overtime. `ot_minutes` is `0`, the surplus minutes are still recorded on the
attendance day, and — where the policy library declares
`flag_ineligible_excess = true` at tenant level — a `FLAG_FOR_REVIEW` exception is
raised so that unpaid extra work is **visible**, not invisible. *SmartPRS 2 has
neither a policy nor a flag; extra work by an ineligible employee is simply not a
concept.*

### C.4.2 The eligibility dimensions

**R-C-017** Eligibility MUST be expressible on every dimension in spine S3.1,
resolved by the S3.2 weights. The dimensions and their weights, for overtime
specifically:

| Dimension | Weight | Typical overtime use |
|---|---|---|
| `employee` | 1000 | A named individual authorised for OT by exception, with an effective range |
| `team` | 500 | A single support rota |
| `sub_department` / `department` | 450 / 400 | "Collections floor is eligible, Quality is not" |
| `designation` | 350 | "Field Officer eligible" |
| `grade` | 340 | **"Agent grade eligible, Manager grade not"** — the governing brief's case |
| `shift` | 330 | "Night-C shift only" |
| `employee_category` | 320 | Workman vs supervisory categories |
| `employment_type` | 310 | "Contract and off-roll only" / "off-roll excluded" |
| `employment_stage` | 300 | "Not during probation" |
| `cost_centre` / `client_process` / `business_unit` | 250 / 240 / 230 | Client-funded OT on one process only |
| `location` | 200 | One site |
| `branch` | 180 | **"Hyderabad branch OT allowed, Pune branch not"** — the governing brief's case |
| `zone` / `state` | 160 / 140 | A state-level policy difference |
| `establishment` | 120 | One registered establishment |
| `company` / `legal_entity` | 100 / 60 | Group-wide |
| tenant default | 0 | "Nobody, unless a more specific policy says otherwise" |

### C.4.3 The governing brief's two cases, worked

**Case 1 — "Hyderabad branch OT allowed, Pune branch not."**

Two published versions of one artefact key, or two artefact keys — either works;
two versions of one key is preferred because the diff is then meaningful:

| | Policy A | Policy B |
|---|---|---|
| `policy_code` | `OT-STD` | `OT-STD` |
| `scope_json` | `{ branch: IN ['HYD'] }` | `{ branch: IN ['PNQ'] }` |
| specificity | `branch(180)` = **180** | `branch(180)` = **180** |
| `is_eligible` | `true` | **`false`** |
| effective | `01-Apr-2026 →` | `01-Apr-2026 →` |

No tie: the two scopes are disjoint, so no employee matches both. A Pune employee
resolves Policy B, which declares `is_eligible = false`; the day's surplus is
recorded, `ot_minutes = 0`, and the trace says *"Policy OT-STD v3 (scope branch
PNQ) declares is_eligible = false."* **The employee is told why, which is the
whole point.**

An explicit ineligible policy is preferred over simply not publishing one for
Pune, because "no policy resolves" and "a policy resolves and says no" produce
the same money and a different, and much better, explanation.

**Case 2 — "Agent grade eligible, Manager grade not."**

| | Policy A | Policy B |
|---|---|---|
| `scope_json` | `{ grade: IN ['G3'] }` | `{ grade: IN ['G7','G8'] }` |
| specificity | `grade(340)` = **340** | `grade(340)` = **340** |
| `is_eligible` | `true` | `false` |

**Combined — "Hyderabad Agents only":** one policy, `scope_json =
{ branch: IN ['HYD'], grade: IN ['G3'] }`, specificity `180 + 340 = 520`, which
is precisely the specificity Part VII's RL-ATT-005 already declares for the
matching rule. The rule and the policy agree by construction because both use the
same weights.

**R-C-018** Where a broad eligible policy and a narrow ineligible policy both
match, **the narrow one wins on specificity** and the employee is ineligible.
This is the ordinary S3.2 rule and needs no special case: "everyone in the company
is eligible except Managers" is one company-scoped eligible policy (weight 100)
plus one grade-scoped ineligible policy (weight 340).

**R-C-019** `eligibility_expr` is an SPX Boolean evaluated after the structural
gate, against the standard namespaces (spine S5.3). It may not reference a
component by **name**; wage information is reached through
`salary.component(code)` and `salary.wage_base(code)` only (Part XV R-XV-008).

```spx
-- eligibility_expr example: eligible unless on probation, unless the employee
-- draws a fixed monthly overtime allowance component
employee.stage <> 'probation'
AND salary.component('FIXED-OT-ALLOWANCE') = MONEY(0)
```

**R-C-020** Eligibility MUST support an **effective-dated individual exception**
without editing the base policy: an `employee`-scoped policy version with
`effective_from`/`effective_to` bounding the exception. *"This one supervisor is
authorised for OT for the duration of the audit season"* is a scope + date, not a
code change and not a permanent flag on the employee record.

**R-C-021** Eligibility changes are audited field-level (spine S14) and appear in
the employee's own configuration history. An employee who stops being eligible
mid-period keeps entitlements already earned under the prior version; the change
applies from its `effective_from`, per day.

**R-C-022** Eligibility is **never** inferred from a component name, a role name,
a designation string match, or a salary threshold hard-coded anywhere. *SmartPRS 2
derives semantics from names in at least five places — Basic by substring, DA by
regex, reimbursement by substring, HRA by `stripos`, the ESI lock by
`stripos($k,'esi')` (`A-payroll.md` §5.3 Q8 item 13) — and this appendix does not
add a sixth.*

---

## C.5 Qualifying conditions

### C.5.1 What "qualifying" means

**R-C-023** A qualifying condition decides **which minutes on which days become
candidate OT minutes**. It is evaluated at the Part VII decision point
`attendance.day.earn`, after the settlement engine has netted the day (§XI.11),
so that a day's surplus has already had offsets, comp-off routing and the
settlement variant applied to it. *This ordering exists because SmartPRS 2's
`lastOut > shiftEnd` test (CALC-B-10) counts a late departure as overtime even
when the employee arrived two hours late — the arithmetic that should have
cancelled it is never performed.*

**R-C-024** Every qualifying condition is expressed in **SPX using the Part VII
attendance vocabulary** (§XI.10.3). No condition is new syntax and none is code.

### C.5.2 The seven condition families

| # | Family | Trigger | Base SPX form |
|---|---|---|---|
| 1 | **Daily hours threshold** | Worked minutes exceed a daily threshold | `attendance.worked_minutes > TO_MINUTES(policy.value('ot_daily_threshold_hours'))` |
| 2 | **Weekly hours threshold** | Cumulative worked minutes in the week exceed a weekly threshold | `agg.sum(worked_minutes, week('mon'), {}) > TO_MINUTES(policy.value('ot_weekly_threshold_hours'))` |
| 3 | **Shift-end threshold** | Work continues past scheduled end by more than a grace | `attendance.late_exit_minutes > policy.value('ot_shift_end_grace_min')` |
| 4 | **Work on a weekly off** | Any qualifying work on a resolved weekly off | `day.is_weekly_off AND attendance.worked_minutes >= policy.value('ot_min_minutes_on_off')` |
| 5 | **Work on a holiday** | Work on a holiday, by holiday type | `day.is_holiday AND day.holiday_type IN ['national','festival'] AND attendance.worked_minutes >= policy.value('ot_min_minutes_on_holiday')` |
| 6 | **Night-shift work** | Minutes falling inside the declared night window | `attendance.night_window_minutes >= policy.value('ot_min_night_minutes')` |
| 7 | **Authorisation regime** | Pre-approval exists, or post-approval is permitted | `authorisation.exists('OT', day.date)` — see §C.9 |

**R-C-025** The seven families **compose**. A policy may require *"more than 8
hours in the day **and** an authorisation **and** not on probation"* as one SPX
`WHEN` with an `EXCEPT`, evaluated by the ordinary rule engine with its ordinary
`on_conflict` semantics. There is no OT-specific condition evaluator.

### C.5.3 Worked SPX — the four canonical rules

These are complete, publishable rules, written against Part VII's structure. Each
replaces something SmartPRS 2 cannot express.

**OT-WT-01 · Daily threshold, weekday, Hyderabad Agents**

```
decision_point : attendance.day.earn
scope          : { branch IN ['HYD'], grade IN ['G3'] }
specificity    : 180 + 340 = 520     priority : 0     on_conflict : all_match
```

```sql
-- WHEN
attendance.worked_minutes > TO_MINUTES(policy.value('ot_daily_threshold_hours'))
AND day.is_working_day
AND NOT day.is_weekly_off
AND NOT day.is_holiday

-- EXCEPT
NOT policy.value('ot_enabled')
OR employee.stage = 'probation'

-- THEN
CREATE_OT(
    minutes        = attendance.worked_minutes
                       - TO_MINUTES(policy.value('ot_daily_threshold_hours')),
    multiplier_ref = 'OT-MULT-WEEKDAY',
    approval       = 'workflow')
```

Note what the rule does **not** do: it does not round, it does not cap, and it
does not choose a factor. Rounding, blocks, caps and factors belong to the
policy, so that changing the block from 30 minutes to 15 does not require editing
— or re-approving — a rule. *Part VII's RL-ATT-005 currently performs the `STEP`
rounding inline; §C.22 records the reconciliation.*

**OT-WT-02 · Weekly threshold**

```sql
-- WHEN
agg.sum(worked_minutes, week(policy.value('week_start_day')), {})
    > TO_MINUTES(policy.value('ot_weekly_threshold_hours'))
AND day.is_working_day

-- THEN
CREATE_OT(
    minutes        = agg.sum(worked_minutes, week(policy.value('week_start_day')), {})
                       - TO_MINUTES(policy.value('ot_weekly_threshold_hours'))
                       - agg.sum(ot_minutes, week(policy.value('week_start_day')), {}),
    multiplier_ref = 'OT-MULT-WEEKLY-EXCESS',
    approval       = 'workflow')
```

The third term is what stops the weekly rule from re-paying minutes the daily
rule already paid: it subtracts OT already created in the same window. **This is
the general shape of every cumulative-window OT rule** and it is only expressible
because `agg.sum` over a declared window exists (spine S5.5).

**OT-WT-03 · Work on a weekly off**

```sql
-- WHEN
day.is_weekly_off
AND NOT day.is_holiday
AND attendance.worked_minutes >= policy.value('ot_min_minutes_on_off')

-- EXCEPT
work_on_off.outcome <> 'overtime'          -- Part XII R-XII-015 routed it elsewhere

-- THEN
CREATE_OT(
    minutes        = attendance.worked_minutes,
    multiplier_ref = 'OT-MULT-WEEKLY-OFF',
    approval       = 'workflow')
```

The `EXCEPT` clause is the exclusivity interlock. If Part XII's
`work_on_off_outcome` routed the day to `comp_off` or `additional_wage`, this rule
does not fire — see §C.8. On a weekly off the **whole** worked duration is the
candidate, not the excess over a threshold, because there is no scheduled duration
to exceed.

**OT-WT-04 · Work on a holiday, with the national-holiday distinction**

```sql
-- WHEN
day.is_holiday
AND attendance.worked_minutes >= policy.value('ot_min_minutes_on_holiday')

-- EXCEPT
work_on_off.outcome <> 'overtime'

-- THEN
CREATE_OT(
    minutes        = attendance.worked_minutes,
    multiplier_ref = CASE
                       WHEN day.holiday_type = 'national'        THEN 'OT-MULT-NATIONAL-HOLIDAY'
                       WHEN day.is_weekly_off                    THEN 'OT-MULT-OFF-AND-HOLIDAY'
                       ELSE                                           'OT-MULT-HOLIDAY'
                     END,
    approval       = 'workflow')
```

**R-C-026** `multiplier_ref` MAY be a `CASE` expression returning a `Code`, so one
rule can serve several contexts. The publish-time validator resolves **every
branch** of the `CASE`, not just the first. *A validator that checks only the
literal case is exactly how a national-holiday multiplier goes missing until the
first national holiday.*

**R-C-027** Holiday **types** are a value set (Part XII), not an enum, so a tenant
that distinguishes national, festival, restricted, optional and floating holidays
declares five and writes five branches. Ametecs does not decide how many
categories of holiday a customer has.

### C.5.4 Night-shift work

**R-C-028** Night work is qualified by **minutes inside a declared night window**,
not by a shift flagged "night". The policy declares
`night_window_start`, `night_window_end` and `night_min_overlap_minutes`; the
settlement engine emits `attendance.night_window_minutes` computed on **absolute
instants** (spine S10). *SmartPRS 2 infers "night" from `end <= start` on the
shift record (`ShiftResolver`; `A-payroll.md` NEW-PAY-22), which is a property of
the shift definition, not of the hours actually worked — an employee who works
22:00–02:00 on a day shift by exception is not night work to SmartPRS 2, and an
employee who leaves a night shift at 20:00 is.*

**R-C-029** Night qualification is **independent of** OT qualification. Three
distinct customer intents exist and all three MUST be expressible:

| Intent | Configuration |
|---|---|
| Night hours attract a **premium multiplier on OT** | Night is a multiplier context (§C.6.4) |
| Night hours attract a **flat allowance regardless of OT** | Night allowance is a separate component and formula (`F-NIGHT-ALLOWANCE`, Part VIII §15.2) — not overtime |
| Both | Both, and the policy declares `night_allowance_stacks_with_ot` |

**R-C-030 — PRODUCT OWNER DECISION REQUIRED, see D-C-09.** Whether a night
premium **multiplies** the day-type multiplier or **replaces** it is a genuine
business ambiguity and is decided per policy by
`night_premium_mode ∈ { replace_context, multiply_context, separate_component }`,
with `replace_context` as the recommended default (§C.21).

### C.5.5 Pre-approval versus post-approval

**R-C-031** The policy declares an `approval_regime` that changes what qualifies,
not merely what is paid:

| `approval_regime` | Qualifying behaviour |
|---|---|
| `none` | Qualifying minutes become an entitlement in state `approved` immediately |
| `post` | Qualifying minutes become an entitlement in state `pending_approval`; a workflow decides |
| `pre` | Minutes qualify **only** where a valid `overtime_authorisations` record covers the employee, the date and at least that many minutes. Excess beyond the authorised minutes does not qualify |
| `pre_and_post` | A pre-authorisation is required to qualify **and** the resulting entitlement still routes to post-approval on actual minutes |
| `pre_or_flag` | Minutes qualify without pre-authorisation, but the entitlement is created with `flagged_unauthorised = true` and appears on an exception report; a workflow decides |

**R-C-032** Variant F of the Time Settlement Engine (§XI.11.4 — *"Paid extra
work, pre-approval only"*) maps exactly to `approval_regime = 'pre'` on the
overtime policy. **The settlement variant and the OT policy MUST agree**, and the
configuration validator raises a **blocking** publish error when a settlement
policy declares `surplus_treatment = overtime, authorisation_required = true` and
the OT policy in the same scope declares `approval_regime = 'none'`. *Two
artefacts silently disagreeing about whether approval is required is exactly the
class of ambiguity spine S3.2 refuses to resolve by coin-toss.*

---

## C.6 The rate model

### C.6.1 The wage basis — declared by flag, never by name

**R-C-033** The overtime rate is computed on `salary.wage_base('OT')` — the exact
sum of the components whose component version declares `is_ot_wage = true`
(Part XV R-XV-007). **No overtime calculation anywhere in SmartPRS 3 inspects a
component's name, and none is expressible.** *SmartPRS 2 uses the employee's whole
CTC ÷ 12 as the OT base (`MasterController.php:761`) — a base nobody declared and
nobody can change — while simultaneously deriving Basic, DA, HRA and
reimbursement semantics from name substrings elsewhere in the same file.*

**R-C-034 — a distinction that must not be blurred.** `is_ot_wage` marks a
component as an **input** to the OT rate. The component that carries OT **onto
the payslip** is a different component, named by the multiplier band's
`component_code` (§C.6.4), and its own statutory flags — `is_pf_base`,
`is_esi_base`, `is_taxable` and the rest — decide what OT is subject to. A
component may be both (a customer whose OT itself enters the base for further OT
is unusual but not impossible); the two roles are declared independently and the
configuration validator warns when one component carries both flags, because it is
almost always a mistake.

**R-C-035** Where no component in the employee's resolved structure declares
`is_ot_wage`, `salary.wage_base('OT')` is `MONEY(0)`. This raises a **blocking
preview exception** `ot_wage_base_empty` on the payroll run — not a ₹0.00 OT line.
*A silent zero is how an employee discovers at month end that the OT they worked
was priced at nothing.*

### C.6.2 The hourly-rate derivation — a configurable divisor

**R-C-036** The hourly rate is produced by formula **FL-05 `F-OT-RATE-HOURLY`**
(Part VIII §11.2), whose divisor is **configuration, not a literal**. The policy
declares a `rate_basis_mode` and the sources of its two divisors:

| `rate_basis_mode` | Rate formula | Used by |
|---|---|---|
| `wage_per_day_per_hour` | `wage_base / (days_divisor × hours_divisor)` | The commonest Indian shape; SmartPRS 2's shape |
| `wage_per_month_hours` | `wage_base / monthly_hours_divisor` | Customers who declare "208 hours a month" directly |
| `explicit_hourly` | `salary.component(<hourly_component_code>)` | Employees already paid an hourly wage |
| `formula` | `formula.value(<formula_code>)` | Anything else, as a named, versioned, tested formula artefact |

**R-C-037** `days_divisor_source` is declared from a closed set, and each option
is a real customer practice:

| `days_divisor_source` | Value used | Note |
|---|---|---|
| `fixed_number` | `days_divisor_value` (a declared number) | **The value SmartPRS 2's code currently uses is `26`** (`MasterController.php:761`, duplicated at `PayrollGenController.php:2165`). It is recorded here as that and only that. The analysis records `26` as *"a convention, not law"*, and notes that `26` is **never** a salary divisor anywhere in SmartPRS 2 |
| `period_payable_days` | `settlement.payable_days` for the period | Moves with attendance — see the guard in R-C-039 |
| `period_working_days` | `period.working_days` | Follows the weekly-off pattern |
| `period_calendar_days` | `period.calendar_days` | 28/29/30/31 |
| `day_length_policy` | The Part XIV `day_length_policy` resolved for the employee | Keeps OT consistent with the LOP basis, which is what most customers assume is happening |

**R-C-038** `hours_divisor_source` is likewise declared:

| `hours_divisor_source` | Value used | Note |
|---|---|---|
| `shift_hours` | `shift.hours` for the day, or the period's modal shift where the policy declares `period_modal` | Follows the roster |
| `fixed_hours` | `hours_divisor_value` | **The value SmartPRS 2's code currently uses is `8`** |
| `contract_hours` | The employment's declared contracted daily hours | For employees whose contract differs from their roster |
| `standard_hours_policy` | A named policy value | Where a customer declares one standard across all shifts |

**R-C-039 — the payable-days guard.** Where `days_divisor_source =
period_payable_days`, the policy MUST declare `min_days_divisor`. An employee with
heavy loss of pay has few payable days, and an unguarded divisor makes their
hourly OT rate rise as their attendance falls — arithmetically correct and
commercially absurd. Below `min_days_divisor` the floor applies, the trace says
so, and an exception `ot_divisor_floored` is raised as a warning. *This edge does
not exist in SmartPRS 2 because its divisor is the literal `26` and cannot move;
it is created by making the divisor configurable, so it is guarded here rather
than discovered in production.*

**R-C-040** A `days_divisor` or `hours_divisor` that resolves to zero, a negative
number, or null is `ERR_DIVISOR_INVALID` — a **blocking** subject-level failure
with the resolved policy, source and value named. There is no division by zero
anywhere in the OT path and no silent fallback. *Spine S5.6: every function is
total and returns a typed error value; an error value propagates to a rule
evaluation failure surfaced to the operator, never silently swallowed.*

**R-C-041** The rate is **not rounded by default** (`rate_rounding.mode = none`),
carried as an exact rational in integer paise through the multiplication, and
rounded once at the amount (Part VIII R-VIII-027, Law 7). A policy MAY declare
`rate_rounding = { mode, unit }` where a customer requires the *published* hourly
rate to be the rate actually used; when it does, the trace states the rounding
and its effect on the amount in paise. *SmartPRS 2 performs three successive float
divisions and one `round(...,2)` at the end (`A-payroll.md` §4.10); the value is
right by luck, not by construction.*

### C.6.3 The rate formula, restated

```spx
-- FL-05  F-OT-RATE-HOURLY   result_type Rate<Money/Hours>   rounding: per policy, default none
salary.wage_base('OT')
  / ( policy.value('ot_days_divisor') * policy.value('ot_hours_divisor') )
```

Both divisors are `policy.value(...)` reads resolved from the overtime policy
version pinned on the entitlement. **The `26` and the `8` that appear twice as
literals in SmartPRS 2 are now two named, dated, scoped configuration values, and
the wage base is a third.**

### C.6.4 Context multipliers — the `multiplier_ref` target

**R-C-042** A multiplier is a **row in `cfg_overtime_multipliers`** carrying a
`resolution_mode` and, for `by_context`, an ordered set of bands in
`cfg_overtime_multiplier_bands`. A band declares:

| Field | Meaning |
|---|---|
| `context_code` | The day/work context the band applies to — a **value set**, not an enum |
| `tier_index`, `tier_from_minutes`, `tier_to_minutes` | Optional tiering within the context: "the first N minutes at one factor, beyond at another" |
| `factor_numerator`, `factor_denominator` | The multiplier as an **exact rational** — `1.5` is stored as `3 / 2` |
| `component_code` | The earning component this band's money lands on (optional; defaults to the policy's `default_ot_component_code`) |
| `is_statutory_floor` | Whether the band's factor is a rule-pack floor a tenant may exceed but not undercut (§C.11) |

**R-C-043** The seeded context vocabulary — extensible per tenant, because
customers categorise days differently:

| `context_code` | Selected when |
|---|---|
| `normal_day` | A working day, no other context matches |
| `weekly_off` | The resolved day is a weekly off |
| `holiday` | The resolved day is a holiday of a type not otherwise distinguished |
| `national_holiday` | `day.holiday_type = 'national'` |
| `weekly_off_and_holiday` | Both — the collision case Part XII R-XII-015 already recognises |
| `night` | Night-window minutes, per `night_premium_mode` (R-C-030) |
| `rest_day` | A declared rest day distinct from a weekly off, where the tenant uses one |

**R-C-044 — multipliers are never fixed in code, and never fixed at 1×, 1.5× or
2×.** Every factor is a `<from policy>` value. This appendix asserts **no**
multiplier as legally correct or as a default. *SmartPRS 2 offers exactly three —
1, 1.5, 2 — in a dropdown, defaults to 2 when the field is blank, and has no
holiday, national-holiday or night distinction at all (BR-PAY-732).*

**R-C-045 — segmentation.** Where a multiplier resolves `by_context` with tiers,
the engine splits the qualifying minutes into **segments**, each carrying its own
minutes, context, tier and resolved factor. The entitlement stores the segments;
Part XIV stage 11 produces **one payslip line per distinct (component, factor)**,
which is exactly what that stage already promises (*"OT earning line(s) by
multiplier band"*). Segmenting in the engine rather than nesting `BANDS` inside
the amount formula keeps FL-06 a scalar multiplication and keeps every payslip
line independently traceable and independently rounded.

```spx
-- FL-06  F-OT-AMOUNT   evaluated once per segment   rounding: half_up, 0.01
formula.value('F-OT-RATE-HOURLY')
  * TO_HOURS(segment.minutes)
  * segment.multiplier
```

**R-C-046** The OT total for an employee-period is defined as **the sum of the
rounded segment lines**, never as a separately rounded total. The run
reconciliation (Part XIV §XIV.5.6) sums lines; there is no second arithmetic path
that could disagree with it (Law 7).

---

## C.7 Rounding, minimum block and caps

### C.7.1 Rounding and the minimum block

**R-C-047** Every overtime policy declares, mandatorily:

| Field | Meaning |
|---|---|
| `rounding_mode` | `floor \| ceil \| half_up \| half_down \| half_even \| none` — **no default exists** (spine S5.4: `ROUND` requires an explicit mode) |
| `rounding_unit_minutes` | The unit rounded to |
| `min_block_minutes` | The minimum billable block; qualifying minutes below it produce no OT |
| `block_application` | `per_day \| per_occurrence \| per_period` |
| `round_at` | `before_caps \| after_caps` |

**R-C-048** Rounding and the minimum block are declared on the **policy**, not
inside the rule. *Part VII's RL-ATT-005 currently performs `STEP(...)` inline
inside `CREATE_OT`; §C.22 records the reconciliation and the reason: a customer
changing the block from 30 minutes to 15 should not require editing, re-testing
and re-approving a rule.*

**R-C-049 — the discarded minutes are recorded.** Where rounding or the minimum
block reduces qualifying minutes, the entitlement stores
`qualifying_minutes_raw`, `qualifying_minutes_rounded` and
`minutes_discarded_by_rounding`, and the trace shows all three. *"Why did my 25
minutes vanish?" is answerable; under a 30-minute floor block it is a policy
answer, and it must be visible as one rather than looking like a bug.*

**R-C-050** `round_at = before_caps` is the recommended default: rounding a
capped figure can produce a paid quantity above the declared cap, which is
indefensible when the cap is a statutory limit.

### C.7.2 Caps

**R-C-051** A policy carries **any number** of cap rows. A cap declares:

| Field | Values |
|---|---|
| `cap_code` | Identifier, unique in the policy version |
| `window` | `day \| week(start_day) \| payroll_period \| calendar_month \| quarter \| financial_year \| rolling(n, unit)` — the spine S5.5 window vocabulary, unchanged |
| `measure` | `ot_minutes \| ot_amount \| total_hours_including_regular \| ot_day_count` |
| `limit_source` | `policy_value \| rule_pack` |
| `limit_value` | The declared limit, when `limit_source = policy_value` |
| `rule_pack_ref` | The pack rule code supplying the limit, when `limit_source = rule_pack` |
| `is_statutory_limit` | Whether this cap represents a statutory ceiling (§C.11) |
| `breach_behaviour` | See below |
| `severity` | `warning \| blocking` for the exception raised |

**R-C-052** `measure = total_hours_including_regular` exists because a limit on
*total* hours worked in a window is a different quantity from a limit on overtime
hours, and a system that can only cap the latter cannot express the former.

**R-C-053** `breach_behaviour` is declared per cap, from a closed set:

| `breach_behaviour` | Effect on breach |
|---|---|
| `cap_silent` | Minutes above the cap are dropped; the entitlement records the drop; **no exception is raised** |
| `cap_and_flag` | As above, plus an exception at the declared severity and an entry on the OT exception report — **recommended default** |
| `block` | The day's OT derivation fails with a blocking exception; **no** OT is created for the day, not even up to the cap |
| `require_approval` | Minutes up to the cap are entitled normally; the excess becomes a **separate entitlement** in `pending_approval` routed to `cap_override_workflow_code` |
| `route_to_comp_off` | Minutes above the cap are routed to the comp-off ledger instead of pay, under the comp-off credit rule named on the policy — **SMARTPRS 3 ARCHITECTURE RECOMMENDATION**; it is the outcome customers actually want when a cap binds, and it is one enum value rather than a second mechanism |

**R-C-054** `cap_silent` exists because some customers genuinely want a silent
ceiling; it is **not** the default, because a silent cap is indistinguishable from
a calculation bug to the employee whose hours disappeared.

**R-C-055** Caps are evaluated in declared order, and **every cap that bound is
named in the trace** with its window, limit, consumption before and after, and
behaviour applied. A cap that did not bind is also listed, with its headroom.

**R-C-056 — window/period straddle.** A cap window that crosses a payroll-period
boundary (a Mon–Sun week crossing a month end) is evaluated **on the window**, and
each day's resulting entitlement is attributed to the period containing that day.
Consumption already recorded in a **locked** period is counted against the window
but is never re-derived. The policy declares
`straddle_mode ∈ { evaluate_on_window (default) \| clip_to_period }`, because
customers who administer weekly caps monthly genuinely want the clip.

**R-C-057** Where `limit_source = rule_pack`, the cap's limit comes from a signed
statutory rule pack (spine S12), not from tenant configuration. A tenant may
declare a **stricter** cap; the configuration validator **blocks or warns by the
pack rule's declared severity** where a tenant cap would be looser than the pack
floor, and records an explicit compliance exception where one is permitted. **No
statutory cap value appears in this appendix.**

**R-C-058** Caps are audited on change like every other high-risk configuration,
and a cap change is diffed with its impacted-employee count before publication.

---

## C.8 Overtime, comp-off and the settlement engine — the exclusivity invariant

### C.8.1 The problem

Once comp-off exists (Part XIII), one worked hour can be claimed three ways: paid
as overtime, credited as comp-off, or paid as additional wage. **Nothing in the
mechanism prevents all three.** SmartPRS 2 cannot have this bug because it has
neither derived OT nor comp-off; SmartPRS 3 can, and this section is the
interlock.

### C.8.2 Ownership — stated once, not duplicated

| Concept | Owning part | This appendix |
|---|---|---|
| Comp-off ledger, credit rules, expiry, encashment, consumption | **Part XIII §XIII.10** | References only |
| The employee/HR three-way choice and its window | **Part XIII R-XIII-064**; **Part XII §XII.3.6** | References only |
| Weekly-off / holiday work settlement record | **Part XII §XII.7.9** `work_on_off_settlements` | References only |
| Day-level netting of surplus and deficit | **Part XI §XI.11** | References only |
| **Routing a qualifying surplus to exactly one outcome, and proving it** | — | **Owned here** |

### C.8.3 The routing rule

**R-C-059 — the single-disposition rule.** Every qualifying surplus minute on an
employee-day carries **exactly one disposition**:

```
disposition ∈ { overtime | comp_off | additional_wage | offset | discarded | none }
```

The Time Settlement Engine (§XI.11) assigns dispositions; the OT policy consumes
only minutes whose disposition is `overtime`. `offset` minutes were consumed
cancelling a deficit earlier in the same settlement and are **not** available to
overtime, which is why OT qualification runs after settlement and not before.

**R-C-060 — the routing precedence.** Where more than one outcome could apply,
the order is fixed and is not configurable:

1. **`offset`** — surplus consumed cancelling the same day's deficit, per the
   settlement variant (§XI.11.4). Deficit cancellation always wins, because the
   alternative is paying overtime to an employee who was short on the day.
2. **An explicit choice**, where `work_on_off_outcome ∈ { employee_choice,
   hr_choice }` and a choice was recorded within `choice_window_days`
   (Part XIII R-XIII-064).
3. **`work_on_off_outcome`**, where the day is a weekly off or holiday and the
   outcome is declared (Part XII R-XII-015).
4. **The comp-off credit rule**, where one matches and declares
   `stacks_with_overtime = false` (Part XIII R-XIII-063).
5. **Overtime**, where the OT policy qualifies the minutes.
6. **`none`** — recorded, reported, not paid, not credited.

**R-C-061** Steps 2–4 are why OT-WT-03 and OT-WT-04 carry
`EXCEPT work_on_off.outcome <> 'overtime'`. The interlock is expressed in the
rules **and** enforced by the invariant below; the rule clause makes the intent
visible and the invariant makes it true.

### C.8.4 The invariant, and the test that proves it

**R-C-062 — `INV-C-01`, THE MUTUAL EXCLUSIVITY INVARIANT.** For every
(tenant, employment, attendance_date, attendance generation):

```
  overtime_minutes_entitled
+ comp_off_source_minutes
+ additional_wage_minutes
+ offset_minutes
+ discarded_minutes
=  settled_surplus_minutes          -- exactly, in minutes, no tolerance
```

and

```
  overtime_minutes_entitled  >  0
AND comp_off_source_minutes  >  0
```

is **only** legal where the comp-off credit rule in force declares
`stacks_with_overtime = true` (Part XIII R-XIII-062). Otherwise it is a violation.

**R-C-063** `INV-C-01` is enforced at **three** points, deliberately:

| Point | Enforcement | On violation |
|---|---|---|
| Settlement engine, per employee-day | Assertion inside the pure function, before any ledger write | Throws — a violation here is a programming error, and spine S1 Law 4's precedent is that a trace-less result throws |
| Database | `overtime_entitlements` UQ `(tenant_id, employment_id, attendance_date, source_generation, multiplier_code)`; `comp_off_credits` carries `source_attendance_date` with its own uniqueness | A duplicate disposition cannot be persisted, whatever the application does |
| Payroll run, stage 24 `reconcile_run` | Per-employee assertion across the period, joining OT entitlements to comp-off credits by source date | **`fail_run`** — Part XIV's stage 24 already fails the run on any reconciliation failure |

**R-C-064** The database constraint is the load-bearing one. *Spine S9 and Part XI
R-XI-111 both make the same argument for enforcing limits in the database rather
than in PHP: an application-only check is a check that a bulk import, a
recalculation and a race can all walk past.*

**R-C-065 — `T-C-06`, THE PROOF TEST.** A named, exact-value regression test:

```
Employee E-9001 · 15-Aug-2026 (a national holiday falling on a weekly off)
Worked 480 minutes. Comp-off credit rule CO-HOL matches, stacks_with_overtime = false.
OT policy OT-STD qualifies weekly-off and holiday work.
work_on_off_outcome = employee_choice, choice_window_days = 7.

Assertions, in order:
  1. Before any choice:      ot_minutes = 0, comp_off_units = 0, disposition = 'pending_choice'
                             — neither outcome is provisionally created
  2. Employee chooses OT:    ot_minutes = 480, comp_off_units = 0
                             comp_off_credits rows for (E-9001, 15-Aug-2026) = 0
  3. INV-C-01 holds:         480 + 0 + 0 + 0 + 0 = 480
  4. Attempt to insert a comp-off credit for the same source date → REJECTED by constraint
  5. Payroll run stage 24 assertion passes; total paid OT minutes = 480 exactly
  6. Re-run the same period → identical result, no second entitlement (idempotency)
  7. Reverse to step 2 and choose comp_off instead → ot_minutes = 0,
     overtime_entitlements rows for that date = 0, comp_off_units per the credit rule
  8. INV-C-01 holds again:   0 + 480 + 0 + 0 + 0 = 480
```

**R-C-066** Where the choice window lapses with no choice, the declared
`default_outcome` applies, the employee is notified that the default was applied
**and why** (Part XIII R-XIII-064), and `INV-C-01` holds on the default. A lapsed
window never produces both outcomes and never produces neither silently.

**R-C-067** Where an approved comp-off credit is **later** converted to OT (or
the reverse) inside an open period, the conversion is a paired ledger operation —
one reversing entry and one creating entry, in one transaction, with a reason and
an actor. In a **locked** period it is an arrears item (Part XI §XI.13), never an
edit. *Part VII's action catalogue already states this for `CREATE_OT`: once paid,
superseding requires an arrear or recovery action, never an edit.*

---

## C.9 Approval

### C.9.1 One engine, no OT-specific approval code

**R-C-068** Overtime approval uses the **workflow engine** (spine S8, Part IX).
There is **zero** overtime-specific approval code in SmartPRS 3. *SmartPRS 2's OT
"approval" is a `status` column on a manually created row (BR-PAY-016); there is
no approver, no audit of the decision, no SLA, no escalation and no
self-approval prohibition.*

**R-C-069** The policy names up to three workflow codes:

| Field | Used for |
|---|---|
| `pre_authorisation_workflow_code` | Approving an *intent* to work overtime, before the day |
| `entitlement_workflow_code` | Approving *actual* overtime after derivation |
| `cap_override_workflow_code` | Approving overtime above a cap where `breach_behaviour = require_approval` |

**R-C-070** All the per-node configuration of spine S8 applies unchanged: SLA,
reminders, escalation target, delegate, backup approver, required comment,
required attachment, amount-based routing, quorum, and — importantly for overtime
— the **self-approval prohibition**. A manager may not approve their own overtime.

### C.9.2 Pre-approval

**R-C-071** A pre-authorisation is a first-class record
(`overtime_authorisations`, §C.14.6) declaring employee, date or date range,
authorised minutes, reason, requester and the resolved workflow instance. It is
raised **before** the work, approved through
`pre_authorisation_workflow_code`, and is an input to the settlement engine —
§XI.11.2 already lists `authorisations` (*"pre-approved OT, on-duty, WFH,
permission/short-leave slips"*) among its inputs.

**R-C-072** Under `approval_regime = 'pre'`, minutes worked **beyond** the
authorised minutes do not qualify. They are recorded, reported as
`ot_unauthorised_excess`, and — per the policy's `unauthorised_excess_treatment`
— either discarded, routed to comp-off, or raised as a `pre_or_flag` entitlement
for a decision. They are never silently paid and never silently invisible.

**R-C-073** An authorisation that is approved but where no qualifying work
occurred **expires** on a scheduled job, is reported, and creates no entitlement.
*An authorisation is permission, not an entitlement; a system that pays approved
authorisations pays for work nobody did.*

### C.9.3 Post-approval, and the rule that governs everything

**R-C-074 — UNAPPROVED OVERTIME NEVER REACHES A PAYSLIP.** Payroll stage 11
selects **only** entitlements in state `approved` whose approval decision predates
the run's input snapshot. There is no configuration that relaxes this, no
override flag, and no role that bypasses it. `approval_regime = 'none'` does not
create an exception: it means the entitlement is created *in* state `approved`
with `approval_mode = 'auto'` recorded on it, so every paid entitlement carries
an explicit approval provenance — automatic or human.

**R-C-075** Entitlements in any state other than `approved` at snapshot time are
excluded **and counted**: the payroll preview shows
`ot_excluded_unapproved { employee_count, minutes, estimated_value }` as a
**warning exception**. *Exclusion without visibility is how an employee discovers
after payday that their overtime was never approved; SmartPRS 2's `status =
'approved' AND amount > 0` filter (`overtimeByEmployee():943-967`) drops rows
silently, including approved rows valued at zero.*

**R-C-076 — re-approval on change.** Where an attendance recalculation changes
the minutes on an already-approved, not-yet-paid entitlement, the policy's
`re_approval_on_change` decides:

| Value | Behaviour |
|---|---|
| `always` | Any change returns the entitlement to `pending_approval` |
| `on_increase` | Only an increase requires re-approval; a decrease is applied and notified — **recommended default** |
| `never` | The change is applied and audited; the original approval stands |

A decided workflow step is **immutable** (spine S8); re-approval starts a **new**
workflow instance, and both instances remain visible on the entitlement's history.
*SmartPRS 2 re-fires transfer orders and increment letters on re-approval because
its steps are mutable; overtime must not inherit that.*

---

## C.10 The OT register and payroll integration

### C.10.1 Derived is the default; manual is an audited exception

**R-C-077 — stated plainly.** In SmartPRS 3, **overtime derived from punches is
the default and primary source of every overtime entitlement.** Manual entry
exists as an **audited exception** for named, reported circumstances — not as the
only path.

*This inverts SmartPRS 2 exactly. There, the derived figure is computed at report
render time and discarded (CALC-B-10), and **every** paid overtime minute comes
from a manual master (`MasterController.php:339-345`). The customer bought
biometric devices; the overtime they pay has never once been driven by them.*

**R-C-078** Every entitlement carries `derivation_source`:

| `derivation_source` | Meaning | Requires |
|---|---|---|
| `derived` | Produced by `CREATE_OT` from settled attendance | Nothing beyond the rule |
| `manual` | Keyed by an authorised user | A reason code from a value set, a free-text reason, `attendance.overtime.manual` permission, and workflow approval regardless of `approval_regime` |
| `imported` | Loaded through a bulk import | The same reason and approval treatment as `manual`, plus a batch reference |
| `migrated` | Brought across from SmartPRS 2 | `migration_batch_id`; never re-priced (§C.17) |
| `corrected` | Supersedes an earlier entitlement | A link to the superseded row and a reason |

**R-C-079** Manual entry MUST NOT be available for a date on which a derived
entitlement already exists, unless the actor holds
`attendance.overtime.override`, supplies a reason, and the manual row is created as
`corrected` **superseding** the derived row. Both rows remain; neither is edited
(Law 3).

**R-C-080** A manually entered **amount** is not accepted. A manual entry supplies
**minutes and a multiplier reference**; the money is computed by the same FL-05 /
FL-06 path as a derived entitlement. *SmartPRS 2 accepts a typed amount that is
never validated against hours × multiplier × rate (BR-PAY-732) — a number with no
reconstructible arithmetic, which Law 4 forbids outright.*

**R-C-081** The proportion of overtime entering payroll by each
`derivation_source` is a **reported operational metric** per tenant per period. A
tenant whose OT is 100% manual after cutover has a configuration problem or a
device problem, and the metric is how Ametecs sees it.

### C.10.2 The register

**R-C-082** The OT register is `overtime_entitlements` (§C.14.5) — a
supersedable, append-only-by-event ledger, not a mutable master. Its head row
carries the current projection; every state change is an immutable row in
`overtime_entitlement_events` (§C.14.7). *SmartPRS 2's `overtime` table is
edited in place, including by the payroll run itself.*

**R-C-083** States and their legal transitions:

```
draft ─► pending_approval ─► approved ─► paid
  │            │                │           │
  │            ├─► rejected     ├─► cancelled
  │            └─► cancelled    └─► superseded
  └─► cancelled                            │
                                  reversed ◄┘   (payment reversal only)
```

**R-C-084** State transitions are **reference-table data** (spine S16: no database
`ENUM` types; status vocabularies are reference tables with codes), validated
against `status_transitions`. *SmartPRS 2 has twelve live cases where code writes
a value the column's `ENUM` forbids (spine S16).*

### C.10.3 Run keying

**R-C-085** An entitlement paid in a run stores `paid_run_id`,
`paid_run_version_id`, `paid_period_id` and `paid_payslip_line_id`. **These are
migration-owned columns.** *SmartPRS 2 creates `overtime.paid_run_id` by executing
DDL inside `generate()` at request time (`:2571-2578`), before the transaction,
because MySQL DDL implicitly commits (BR-PAY-041) — so the column that keys
overtime to a payroll run does not exist until somebody has tried to run payroll.
Law 2 forbids this and a CI grep enforces it.*

**R-C-086** Stage 11 is **idempotent** on `(run_version_id, entitlement_id)`. A
restarted stage re-reads the pinned snapshot and produces identical lines; it does
not re-select, re-price or re-pay.

**R-C-087** Selection is by the **attendance date's** period membership, resolved
from the pay cycle version in the run snapshot — never by the entitlement's
creation date and never by a `pay_date` month match. *SmartPRS 2 matches OT and
commission on the pay date's month rather than the attendance period
(`B-attendance.md` DEFECT-B-30 records the equivalent defect on corrections),
which silently pays the wrong period at every cycle boundary.*

### C.10.4 Reversal on run regeneration

**R-C-088** When a run is regenerated, entitlements paid by the superseded run
version are **released**: state returns to `approved`, `paid_run_version_id` is
cleared on the head projection, and a `released_on_regeneration` event row records
the superseded run version, the actor and the reason. The prior payment is not
deleted, because the prior run version is not deleted.

*SmartPRS 2 does exactly this release — `overtime: paid → approved` and
`paid_run_id` cleared (`generate():2422-2432`). **This is structurally correct and
is preserved deliberately**, in the spirit of spine S19: it is one of the few
places where SmartPRS 2 gets a lifecycle right. What changes is that the release
is an event on an immutable log rather than an UPDATE, and that the run version it
was released from remains queryable.*

**R-C-089** An entitlement whose attendance day is **superseded** by a
recalculation after payment is **never** silently re-priced. It produces an
arrears item or a recovery obligation (Part XI §XI.13, Part XVIII), per the
locked-period disposition policy. *Part VII's action catalogue states the rule for
`CREATE_OT` already: fingerprinted, superseded only while unpaid; once paid,
superseding requires an arrear or recovery action, never an edit.*

### C.10.5 Onto the payslip

**R-C-090** OT reaches the payslip as **an earning component named by the
multiplier band**, resolved by `component_code` — never by a literal string.
*SmartPRS 2 emits the literal `'Overtime'` as a line name (`:2644-2647`) and then
classifies it into the Variable group by a **nine-keyword substring heuristic**
including the word `overtime` (NEW-PAY-30, `payslipGroupEarnings():1591-1636`). A
customer who renames the line breaks its grouping. In SmartPRS 3, grouping comes
from the component's declared `category`, and the name is a label.*

**R-C-091** One payslip line per distinct `(component_code, factor)` (R-C-045).
Where a policy declares one component for all contexts, a tenant sees one OT line;
where it declares separate components for weekly-off and holiday overtime, the
payslip shows them separately — and it is configuration which one they get.

**R-C-092** OT is **not prorated** by LOP. Overtime is time actually worked, and
reducing it for absence would deduct twice for the same absence. The policy
declares `prorates = false` on the OT component (Part XV `prorates` /
`lop_affected` flags), which is the same declaration mechanism every other
component uses. *SmartPRS 2 also does not prorate OT — but by accident, because OT
is added after `computeSlip` returns (BR-PAY-016), not because anyone declared it.
The result is the same and the reason is different, and the reason is what makes
it defensible.*

---

## C.11 Statutory treatment — referenced, not decided

**R-C-093** Which statutory bases overtime enters — PF, ESI, PT, LWF, the bonus
wage, the gratuity wage, the minimum-wage comparison and the taxable base — is
determined **entirely** by the declared flags on the OT output component version
(Part XV R-XV-007), resolved through `salary.wage_base('<CODE>')`. There is no
overtime-specific statutory code, no name test, and no special case anywhere in
the statutory path.

**R-C-094 — THIS APPENDIX DOES NOT DECIDE THE FLAG VALUES.** The question is a
compliance ruling, already registered:

| Register | Decision | Where |
|---|---|---|
| Part XIV | **`D-XIV-03`** — *"Which variable earnings enter which statutory base?"* | Part XIV §XIV decision register |
| Part XVIII | **`D-XVIII-07`** — the same question, with the cutover cost consequence stated | Part XVIII §decision register |
| Spine | **`D-03`** — the named compliance owner who signs a rule pack | Spine S12 |

Both existing decisions record the same as-is finding and neither is re-opened
here: today commission, incentive, overtime, night allowance and arrears are added
**after** the slip is computed, so **no PF, ESI, PT or TDS is charged on any of
them** (`BR-STAT-006`, `compute():1350`, `:1391`), which the statutory analysis
identifies as a **live compliance exposure**. Part XVIII's recommendation stands
verbatim: *"the correct flag values are a compliance-owner determination (spine
D-03), not a decision this document makes."*

**R-C-095** The rule pack (spine S12) MAY declare, per statutory head, a
**floor** on OT inclusion — that is, a declaration that a head's base includes
overtime. Where it does, a tenant may make a component's inclusion **stricter**
but not looser than the pack's floor, and the configuration validator blocks or
warns by the pack rule's declared severity, recording an explicit compliance
exception where one is permitted. This is the ordinary S12 layering and needs no
overtime-specific mechanism.

**R-C-096** The cutover impact of any change to these flags is **quantified per
tenant before it happens**: Part XXIX's reconciliation case `C-ESI-OT`
(*"Overtime escapes the ESI base"*) already exists and states the direction of
travel — *"ESI increases; net decreases — subject to the compliance ruling."*
No customer discovers this on a payslip.

---

## C.12 Explainability — "why was this employee paid this much overtime?"

**R-C-097** Every overtime figure is reachable through `Explain(entity, field)`
(spine S15) and returns a complete trace. **A trace-less overtime amount is a
programming error that throws** (Law 4).

**R-C-098** The trace answers, in order: *was this employee eligible, which
minutes qualified, how were they rounded, what capped them, what factor applied,
what rate, what arithmetic, who approved it, and which run paid it.* Nine
questions, one screen.

### C.12.1 The full trace

```
OVERTIME — Employee E-4471 (Agent, Grade G3, Hyderabad) · Period Aug-2026
Payslip line "Overtime (Weekday)" ₹324.52

── ELIGIBILITY ──────────────────────────────────────────────────────────────
Policy resolved      OT-STD v4   scope { branch IN [HYD], grade IN [G3] }
                     specificity 520 (branch 180 + grade 340) · priority 0
                     effective 01-Apr-2026 →  ·  resolved as at 26-Aug-2026
Candidates rejected  OT-STD v4 { company IN [ACME] } specificity 100 — less specific
is_eligible          true
eligibility_expr     employee.stage <> 'probation'  → 'confirmed' <> 'probation' → TRUE

── QUALIFICATION ────────────────────────────────────────────────────────────
Date                 26-Aug-2026 (Wednesday) · day_type working · not weekly off · not holiday
Shift                GEN-0930 09:30–18:30, scheduled 540m, resolved from roster
Settlement           policy SETTLE-OPS-B v2 (variant B) · offset_minutes 0
                     settled_surplus_minutes 132  · disposition 'overtime'
Rule matched         OT-WT-01 v3 at decision point attendance.day.earn
Condition            attendance.worked_minutes (612m) > ot_daily_threshold_hours (8h = 480m)
                     → TRUE
Except               NOT ot_enabled → FALSE ;  stage = 'probation' → FALSE   → not excepted
Qualifying (raw)     612m − 480m = 132m

── ROUNDING ─────────────────────────────────────────────────────────────────
rounding_mode        floor        rounding_unit_minutes  30
min_block_minutes    30           block_application      per_day
round_at             before_caps
Rounded              FLOOR(132m / 30m) × 30m = 4 × 30m = 120m
Discarded            12m  (recorded; not paid — policy OT-STD v4 rounding declaration)

── CAPS ─────────────────────────────────────────────────────────────────────
CAP-DAY    window day              measure ot_minutes  limit <from policy> 180m
           consumed before 0m → after 120m       headroom 60m        did not bind
CAP-WEEK   window week(mon)        measure ot_minutes  limit <from policy> 720m
           consumed before 390m → after 510m     headroom 210m       did not bind
CAP-STAT   window rolling(1,'quarter') measure ot_minutes
           limit <from rule pack IN-OT-LIMIT v2>  consumed 1,240m    did not bind
Behaviour applied    none — no cap bound

── MULTIPLIER ───────────────────────────────────────────────────────────────
multiplier_ref       OT-MULT-WEEKDAY   (policy OT-STD v4)
resolution_mode      by_context
context selected     normal_day   (day.is_weekly_off FALSE, day.is_holiday FALSE)
factor               <from policy: OT-MULT-WEEKDAY.normal_day> = 3/2 (exact rational)
Segments             1 segment: 120m · normal_day · tier 1 · factor 3/2
                     component OT-WEEKDAY  ("Overtime (Weekday)")

── RATE ─────────────────────────────────────────────────────────────────────
Formula              FL-05 F-OT-RATE-HOURLY v2 (effective 01-Apr-2026 →)
Wage base            salary.wage_base('OT') = ₹22,500.00 = 22,50,000 paise
                     composed of: BASIC ₹18,000.00 (is_ot_wage 1)
                                  DA    ₹4,500.00  (is_ot_wage 1)
                     structure "Telangana Collections" v7
days_divisor         26   source fixed_number   (policy OT-STD v4)
hours_divisor        8    source shift_hours    (shift GEN-0930)
Rate                 22,50,000 ÷ (26 × 8) = 22,50,000 ÷ 208
                     = 10,817.3076923… paise/hour = ₹108.1731/hour  (NOT rounded)
rate_rounding        none  (policy declaration)

── AMOUNT ───────────────────────────────────────────────────────────────────
Formula              FL-06 F-OT-AMOUNT v2
Arithmetic           (22,50,000 ÷ 208) × 2h × 3/2
                     = 22,50,000 × 3 ÷ 208
                     = 67,50,000 ÷ 208
                     = 32,451.923076… paise      (exact rational, no float)
Rounding             half_up, unit 0.01  →  32,452 paise
Amount               ₹324.52                       residue +0.076924 paise

── APPROVAL ─────────────────────────────────────────────────────────────────
approval_regime      post
Entitlement          OT-2026-08-000417  ·  created 27-Aug-2026 02:14 IST by rule OT-WT-01 v3
Workflow             OT-APPROVE v3, instance WF-118824
                     Step 1  Reporting Manager  A. Rao    approved 27-Aug-2026 11:03 IST
                     Step 2  Ops Head           S. Menon  approved 28-Aug-2026 09:41 IST
                     Self-approval prohibition: enforced (requester ≠ approver at both steps)
State                approved → paid

── PAYMENT ──────────────────────────────────────────────────────────────────
Run                  PR-2026-08 · lot 1 · run version 2 (version 1 superseded 31-Aug-2026)
Released             version 1 released this entitlement on regeneration (event 2026-08-31)
Payslip line         "Overtime (Weekday)" · component OT-WEEKDAY
Statutory treatment  Per component OT-WEEKDAY v3 declared flags — see the component's own
                     trace. Which bases OT enters is governed by D-XIV-03 / D-XVIII-07.
Versions pinned      policy OT-STD v4 · multiplier OT-MULT-WEEKDAY v4 · FL-05 v2 · FL-06 v2
                     · settlement SETTLE-OPS-B v2 · rule OT-WT-01 v3 · structure v7
```

**R-C-099** The trace names **every** cap evaluated, including those that did not
bind, with their headroom. *"Why is my overtime capped?" and "why is it not?" are
the same question, and a trace that lists only the binding cap answers half of
it.*

**R-C-100** The trace names the **rejected** policy candidates and why they lost,
with the S3.2 specificity arithmetic shown. *"Which policy applied to me, and why
that one?" is unanswerable in SmartPRS 2 for any configuration at all.*

**R-C-101** The trace is available on the employee's own self-service payslip,
scoped to self (spine S13), not only to HR. *The employee is the person who
noticed the number was wrong.*

---

## C.13 Configuration surface — the Section 127 standard

The brief's standard is that *"3 late arrivals over 10 minutes = half day" must
not require code.* The equivalent tests for overtime, all of which must be pure
configuration:

| # | Requirement stated by a customer | Configuration that satisfies it | Code change? |
|---|---|---|---|
| 1 | **"Hyderabad branch may claim OT, Pune may not"** | Two `overtime_policy` versions scoped by branch, one `is_eligible = false` | none |
| 2 | **"Agent grade is eligible, Manager grade is not"** | Two versions scoped by grade | none |
| 3 | "Nobody on probation earns OT" | `eligibility_expr` on the base policy | none |
| 4 | "OT starts after 8 hours in a day" | `ot_daily_threshold_hours` policy value + rule OT-WT-01 | none |
| 5 | "…or after 48 hours in a week, whichever is greater" | Add rule OT-WT-02; both rules `all_match`; the weekly rule nets already-paid OT | none |
| 6 | "OT is only counted in 30-minute blocks, rounded down" | `rounding_mode = floor`, `rounding_unit_minutes = 30`, `min_block_minutes = 30` | none |
| 7 | "…change that to 15-minute blocks from 1 January" | New policy version, `effective_from = 01-Jan`, superseding | none |
| 8 | "Weekly-off work pays a different multiplier from weekday OT" | Two bands on one multiplier, contexts `normal_day` and `weekly_off` | none |
| 9 | "National holidays pay more than festival holidays" | A third band on context `national_holiday`; the rule's `CASE` already branches on `day.holiday_type` | none |
| 10 | "The first 2 hours pay one rate, beyond that another" | Two tiers on the `normal_day` band | none |
| 11 | "The OT hourly rate is monthly wage ÷ 26 ÷ 8" | `rate_basis_mode = wage_per_day_per_hour`, `days_divisor_source = fixed_number (26)`, `hours_divisor_source = fixed_hours (8)` | none |
| 12 | "…no, use actual payable days and the actual shift length" | `days_divisor_source = period_payable_days`, `hours_divisor_source = shift_hours`, `min_days_divisor` set | none |
| 13 | "…no, we declare 208 hours a month" | `rate_basis_mode = wage_per_month_hours`, `monthly_hours_divisor = 208` | none |
| 14 | "OT is computed on Basic and DA only" | `is_ot_wage = true` on those two component versions; nothing else changes | none |
| 15 | "…now include the conveyance allowance too" | `is_ot_wage = true` on a third component version | none |
| 16 | "Nobody may claim more than 3 hours OT in a day" | One cap row: `window = day`, `measure = ot_minutes`, limit 180 | none |
| 17 | "…and no more than 12 hours in a week, flagged if breached" | A second cap row, `window = week(mon)`, `breach_behaviour = cap_and_flag` | none |
| 18 | "Anything above the weekly cap becomes comp-off instead of pay" | `breach_behaviour = route_to_comp_off` on that cap row | none |
| 19 | "Block the day entirely if the quarterly statutory limit is breached" | Cap row with `limit_source = rule_pack`, `breach_behaviour = block` | none |
| 20 | "OT must be approved by the reporting manager, then Ops" | `entitlement_workflow_code` naming a two-step workflow artefact | none |
| 21 | "…and must be pre-approved before the shift" | `approval_regime = 'pre'` + `pre_authorisation_workflow_code` | none |
| 22 | "Anything not pre-approved is flagged, not blocked" | `approval_regime = 'pre_or_flag'` | none |
| 23 | "Night hours between 22:00 and 06:00 pay a premium" | `night_window_start/end` + a `night` band | none |
| 24 | "Employees choose between OT pay and comp-off for weekly-off work" | Part XII `work_on_off_outcome = employee_choice` + Part XIII `choice_window_days` | none |
| 25 | "Show weekly-off OT as its own payslip line" | A distinct `component_code` on the `weekly_off` band | none |
| 26 | "One named supervisor is authorised for OT during audit season" | An `employee`-scoped policy version with a bounded effective range | none |
| 27 | "Reduce the multiplier from April, but August's payroll must not change" | New version `effective_from = 01-Apr`; entitlements pin their version (R-C-011) | none |
| 28 | "Stop paying OT entirely for one department from next month" | A department-scoped version with `is_eligible = false` from that date | none |

**R-C-102** Nothing in this table requires a deploy, a migration, a support ticket
or a database edit. *In SmartPRS 2, **every** row above requires code: the
divisor, the multiplier set, eligibility, thresholds, rounding, caps, approval and
the payslip line name are all literals, absent features, or a three-value dropdown
(BR-PAY-732, BR-PAY-016, CALC-B-10).*

**R-C-103** Rows 1, 2, 8, 9, 11, 16, 17, 20 and 21 each have a named acceptance
test in §C.20 and a test identifier, satisfying `R-XXXV-009`'s requirement that
Section 127 acceptance cases carry test identifiers.

**R-C-104** Every item is editable through the Configuration Studio's visual
builder with the SPX advanced view behind it (spine S6), and every high-risk
change routes through diff-preview and maker-checker before publication.

---

## C.14 Data model

Tables to the Section 123 standard. All carry `id BIGINT UNSIGNED AUTO_INCREMENT`
PK, `uuid BINARY(16)` UUIDv7 public id, `tenant_id BIGINT UNSIGNED NOT NULL`
participating in every unique index (Law 6), and standard audit columns
(`created_by`, `created_at`, `updated_by`, `updated_at`). Money is
`DECIMAL(18,4)` at rest and integer paise in memory (spine S16, Law 7). Dates are
`DATE`, instants are `DATETIME` UTC with an accompanying business date and
timezone. There are **no database `ENUM` types**; every code column references a
value set or reference table.

### C.14.1 `cfg_overtime_policies`

*Purpose:* the typed payload of an `overtime_policy` config version — the artefact
`multiplier_ref` ultimately resolves within. One row per published version.

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `config_version_id` | BIGINT UNSIGNED | N | — | FK → `config_versions.id` RESTRICT. Carries scope, priority, effective range, status, approval columns (spine S4) |
| `policy_code` | VARCHAR(60) | N | — | Denormalised from the artefact for query convenience |
| `is_eligible` | TINYINT(1) | N | `1` | The structural eligibility declaration (§C.4) |
| `eligibility_expr_id` | BIGINT UNSIGNED | Y | NULL | FK → `spx_expressions.id` RESTRICT — the compiled AST plus source |
| `rate_basis_mode` | VARCHAR(40) | N | — | Value set `ot_rate_basis_mode` |
| `days_divisor_source` | VARCHAR(40) | Y | NULL | Value set `ot_days_divisor_source` |
| `days_divisor_value` | DECIMAL(9,4) | Y | NULL | Required when source is `fixed_number` |
| `min_days_divisor` | DECIMAL(9,4) | Y | NULL | The R-C-039 guard |
| `hours_divisor_source` | VARCHAR(40) | Y | NULL | Value set `ot_hours_divisor_source` |
| `hours_divisor_value` | DECIMAL(9,4) | Y | NULL | Required when source is `fixed_hours` |
| `monthly_hours_divisor` | DECIMAL(9,4) | Y | NULL | Required when `rate_basis_mode = wage_per_month_hours` |
| `hourly_component_code` | VARCHAR(40) | Y | NULL | Required when `rate_basis_mode = explicit_hourly` |
| `rate_formula_code` | VARCHAR(60) | Y | NULL | Required when `rate_basis_mode = formula` |
| `rate_rounding_mode` | VARCHAR(20) | N | `none` | No implicit default anywhere (spine S5.4) |
| `rate_rounding_unit` | DECIMAL(9,4) | Y | NULL | |
| `rounding_mode` | VARCHAR(20) | N | — | **Mandatory.** Value set `rounding_mode` |
| `rounding_unit_minutes` | SMALLINT UNSIGNED | N | — | **Mandatory** |
| `min_block_minutes` | SMALLINT UNSIGNED | N | `0` | |
| `block_application` | VARCHAR(20) | N | `per_day` | `per_day \| per_occurrence \| per_period` |
| `round_at` | VARCHAR(20) | N | `before_caps` | R-C-050 |
| `straddle_mode` | VARCHAR(20) | N | `evaluate_on_window` | R-C-056 |
| `approval_regime` | VARCHAR(20) | N | `post` | Value set `ot_approval_regime` |
| `pre_authorisation_workflow_code` | VARCHAR(60) | Y | NULL | FK by code → workflow artefact |
| `entitlement_workflow_code` | VARCHAR(60) | Y | NULL | |
| `cap_override_workflow_code` | VARCHAR(60) | Y | NULL | |
| `re_approval_on_change` | VARCHAR(20) | N | `on_increase` | R-C-076 |
| `unauthorised_excess_treatment` | VARCHAR(30) | N | `flag` | `discard \| flag \| route_to_comp_off \| raise_for_decision` |
| `default_ot_component_code` | VARCHAR(40) | N | — | The earning component OT lands on when a band declares none |
| `comp_off_credit_rule_code` | VARCHAR(60) | Y | NULL | Used by `route_to_comp_off` (§C.7.2) |
| `night_window_start`, `night_window_end` | TIME | Y | NULL | |
| `night_min_overlap_minutes` | SMALLINT UNSIGNED | Y | NULL | |
| `night_premium_mode` | VARCHAR(30) | N | `replace_context` | D-C-09 |
| `night_allowance_stacks_with_ot` | TINYINT(1) | N | `0` | R-C-029 |
| `flag_ineligible_excess` | TINYINT(1) | N | `1` | R-C-016 |
| `business_description` | VARCHAR(1000) | N | — | Required; appears in the trace and the diff |

**Keys.** PK `id` · UQ `(tenant_id, uuid)`, `(tenant_id, config_version_id)` ·
IX `(tenant_id, policy_code)`.
**Policy.** Tenant scope: tenant-owned · History: versioned via `config_versions`,
never updated in place (Law 3) · Soft delete: no — versions are superseded or
archived · Audit: `full`, field-level before/after · PII: `none` ·
Retention: `R-CFG-PERMANENT`.

### C.14.2 `cfg_overtime_multipliers` — **the target of `multiplier_ref`**

*Purpose:* the artefact `CREATE_OT(minutes, multiplier_ref, approval)` resolves
against. **This table is what `R-XXXV-028` says does not exist.**

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `overtime_policy_id` | BIGINT UNSIGNED | N | — | FK → `cfg_overtime_policies.id` CASCADE (within the version) |
| `multiplier_code` | VARCHAR(60) | N | — | **The value `multiplier_ref` carries** — e.g. `OT-MULT-WEEKDAY` |
| `name` | VARCHAR(120) | N | — | Human label |
| `resolution_mode` | VARCHAR(20) | N | `by_context` | `fixed \| by_context` |
| `fixed_factor_num` | INT UNSIGNED | Y | NULL | Exact rational numerator, when `fixed` |
| `fixed_factor_den` | INT UNSIGNED | Y | NULL | Denominator; `CHECK (fixed_factor_den > 0)` |
| `on_missing_context` | VARCHAR(20) | N | `error` | `error \| use_normal_day` — **`error` is the default**; a missing context is never silently 1× |
| `is_active` | TINYINT(1) | N | `1` | |

**Keys.** PK `id` · UQ `(tenant_id, uuid)`,
**`(tenant_id, overtime_policy_id, multiplier_code)`** — the constraint that makes
`multiplier_ref` resolvable and unambiguous · IX `(tenant_id, multiplier_code)`
for the publish-time validator's lookup.
**Policy.** Tenant-owned · versioned with its parent policy version · delete:
never (superseded with the version) · audit `full` · PII `none` ·
retention `R-CFG-PERMANENT`.

### C.14.3 `cfg_overtime_multiplier_bands`

*Purpose:* the context and tier rows a `by_context` multiplier resolves through.

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `overtime_multiplier_id` | BIGINT UNSIGNED | N | — | FK → `cfg_overtime_multipliers.id` CASCADE |
| `context_code` | VARCHAR(40) | N | — | Value set `ot_context` (R-C-043) |
| `tier_index` | SMALLINT UNSIGNED | N | `1` | |
| `tier_from_minutes` | INT UNSIGNED | N | `0` | Inclusive |
| `tier_to_minutes` | INT UNSIGNED | Y | NULL | Exclusive; NULL = unbounded, and exactly one tier per context must be unbounded |
| `factor_num` | INT UNSIGNED | N | — | Exact rational numerator — `1.5` is `3` |
| `factor_den` | INT UNSIGNED | N | `1` | `CHECK (factor_den > 0)` |
| `component_code` | VARCHAR(40) | Y | NULL | Falls back to the policy's `default_ot_component_code` |
| `is_statutory_floor` | TINYINT(1) | N | `0` | §C.11 |
| `rule_pack_ref` | VARCHAR(80) | Y | NULL | The pack rule supplying a floor factor, where one applies |

**Keys.** PK `id` · UQ `(tenant_id, uuid)`,
`(tenant_id, overtime_multiplier_id, context_code, tier_index)` ·
IX `(tenant_id, overtime_multiplier_id, context_code)`.
**Validation at publish (gate, not a runtime check):** tiers within a context must
be **sorted, contiguous, non-overlapping and terminal** — the same gate Part VIII
G7 applies to slab and band tables, applied here for the same reason. *SmartPRS 2's
incentive band loop falls through to the **last band's rate** by default because
nothing validates band order (DEF-P22).*
**Policy.** Tenant-owned · versioned with the policy · audit `full` · PII `none`.

### C.14.4 `cfg_overtime_caps`

*Purpose:* the daily, weekly, period and statutory caps and their breach
behaviour.

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `overtime_policy_id` | BIGINT UNSIGNED | N | — | FK CASCADE |
| `cap_code` | VARCHAR(60) | N | — | |
| `evaluation_order` | SMALLINT UNSIGNED | N | `100` | Caps are evaluated in this order |
| `window_kind` | VARCHAR(30) | N | — | Value set `agg_window` — the spine S5.5 vocabulary |
| `window_param` | VARCHAR(40) | Y | NULL | e.g. `mon` for `week(mon)`, `3,month` for `rolling(3, month)` |
| `measure` | VARCHAR(40) | N | — | `ot_minutes \| ot_amount \| total_hours_including_regular \| ot_day_count` |
| `limit_source` | VARCHAR(20) | N | `policy_value` | `policy_value \| rule_pack` |
| `limit_value` | DECIMAL(14,4) | Y | NULL | Required when `limit_source = policy_value` |
| `rule_pack_ref` | VARCHAR(80) | Y | NULL | Required when `limit_source = rule_pack` |
| `is_statutory_limit` | TINYINT(1) | N | `0` | |
| `breach_behaviour` | VARCHAR(30) | N | `cap_and_flag` | Value set `ot_cap_breach` (R-C-053) |
| `exception_severity` | VARCHAR(20) | N | `warning` | `warning \| blocking` |

**Keys.** PK `id` · UQ `(tenant_id, uuid)`,
`(tenant_id, overtime_policy_id, cap_code)` ·
IX `(tenant_id, overtime_policy_id, evaluation_order)`.
**Policy.** Tenant-owned · versioned with the policy · audit `full` · PII `none`.

### C.14.5 `overtime_entitlements` — **the OT register**

*Purpose:* the register. One head row per (employment, attendance date,
multiplier, source generation), carrying the current projection of an immutable
event stream.

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `employment_id` | BIGINT UNSIGNED | N | — | FK → `employments.id` RESTRICT |
| `attendance_date` | DATE | N | — | The **attendance** date (Part XI §S10), not the punch's calendar date |
| `attendance_day_id` | BIGINT UNSIGNED | Y | NULL | FK → `attendance_days.id` RESTRICT; NULL for `manual`/`migrated` |
| `source_generation` | INT UNSIGNED | N | `1` | The attendance day's recalculation generation this was derived from |
| `derivation_source` | VARCHAR(20) | N | `derived` | R-C-078 |
| `reason_code`, `reason_text` | VARCHAR(40), VARCHAR(1000) | Y | NULL | Mandatory for `manual`, `imported`, `corrected` |
| `overtime_policy_version_id` | BIGINT UNSIGNED | N | — | **Pinned** (R-C-011). FK RESTRICT |
| `multiplier_id` | BIGINT UNSIGNED | N | — | FK → `cfg_overtime_multipliers.id` RESTRICT |
| `multiplier_code` | VARCHAR(60) | N | — | Denormalised for the trace and for uniqueness |
| `rule_id`, `rule_version` | BIGINT UNSIGNED, INT | Y | NULL | The `CREATE_OT` rule that produced it |
| `qualifying_minutes_raw` | INT UNSIGNED | N | — | Before rounding |
| `qualifying_minutes_rounded` | INT UNSIGNED | N | — | After rounding, before caps |
| `minutes_discarded_by_rounding` | INT UNSIGNED | N | `0` | R-C-049 |
| `minutes_capped_out` | INT UNSIGNED | N | `0` | Reduced by a cap |
| `capped_by_cap_code` | VARCHAR(60) | Y | NULL | The cap that bound |
| `entitled_minutes` | INT UNSIGNED | N | — | What may be paid |
| `hourly_rate_minor` | DECIMAL(18,4) | Y | NULL | The unrounded rate as computed, for the trace |
| `amount_minor` | DECIMAL(18,4) | Y | NULL | Sum of the segment amounts; NULL until priced |
| `currency_code` | CHAR(3) | N | `INR` | Carried explicitly (Law 7) |
| `state_code` | VARCHAR(30) | N | `pending_approval` | Reference table, not ENUM (R-C-084) |
| `approval_mode` | VARCHAR(20) | N | — | `auto \| workflow` — every paid row has an approval provenance |
| `workflow_instance_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `authorisation_id` | BIGINT UNSIGNED | Y | NULL | FK → `overtime_authorisations.id` RESTRICT |
| `flagged_unauthorised` | TINYINT(1) | N | `0` | `pre_or_flag` regime |
| `paid_run_id`, `paid_run_version_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT. **Migration-owned columns** (R-C-085) |
| `paid_period_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `paid_payslip_line_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `superseded_by_id` | BIGINT UNSIGNED | Y | NULL | FK self RESTRICT (Law 3) |
| `legacy_amount_minor` | DECIMAL(18,4) | Y | NULL | SmartPRS 2's typed amount, retained unmodified (§C.17) |
| `migration_batch_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `trace_id` | BINARY(16) | N | — | FK → `calculation_traces` |

**Keys.** PK `id` · UQ `(tenant_id, uuid)`,
**`(tenant_id, employment_id, attendance_date, source_generation, multiplier_code)`**
— the constraint that enforces `INV-C-01` in the database (R-C-063/064) ·
IX `(tenant_id, state_code, attendance_date)`,
`(tenant_id, paid_run_version_id)`,
`(tenant_id, employment_id, attendance_date)`,
`(tenant_id, workflow_instance_id)`,
`(tenant_id, derivation_source, attendance_date)` (the R-C-081 metric).
**Policy.** Tenant scope: tenant-owned, `tenant_id` applied by the persistence
layer · History: superseded, never updated destructively; state changes are event
rows · Soft delete: never · Audit: `full` · PII: `none` (it is time and money
about an employee, carrying no identifiers beyond the FK) ·
Retention: `R-PAY-8Y`.

### C.14.6 `overtime_authorisations`

*Purpose:* pre-approval of an intent to work overtime (§C.9.2).

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `employment_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `valid_from`, `valid_to` | DATE | N | — | A single day or a range |
| `authorised_minutes` | INT UNSIGNED | N | — | Per day within the range |
| `reason_code`, `reason_text` | VARCHAR(40), VARCHAR(1000) | N/Y | — | Reason code from a value set |
| `requested_by_user_id`, `requested_at` | BIGINT UNSIGNED, DATETIME | N | — | |
| `state_code` | VARCHAR(30) | N | `submitted` | `draft \| submitted \| in_approval \| approved \| rejected \| cancelled \| expired \| consumed \| partially_consumed` |
| `workflow_instance_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `consumed_minutes` | INT UNSIGNED | N | `0` | Updated by entitlement creation |
| `expired_at` | DATETIME | Y | NULL | Set by the scheduled expiry job (R-C-073) |

**Keys.** PK `id` · UQ `(tenant_id, uuid)` ·
IX `(tenant_id, employment_id, valid_from, valid_to)`,
`(tenant_id, state_code, valid_to)` (the expiry sweep),
`(tenant_id, workflow_instance_id)`.
A partial unique index on `(tenant_id, employment_id, valid_from, open_marker)`
enforces the configured maximum of open authorisations per employee-day **in the
database**, following Part XI R-XI-111's precedent.
**Policy.** Tenant-owned · audit `full` · PII `identifying` (reason text may carry
personal circumstances — masked by role) · retention `R-ATT-3Y+`.

### C.14.7 `overtime_entitlement_events`

*Purpose:* the immutable event stream behind the register's head projection.
Append-only.

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `entitlement_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `event_code` | VARCHAR(40) | N | — | `created \| submitted \| approved \| rejected \| cancelled \| capped \| superseded \| paid \| released_on_regeneration \| reversed \| corrected \| migrated` |
| `occurred_at` | DATETIME | N | — | UTC; with `business_date` and `business_tz` (spine S16) |
| `actor_user_id` | BIGINT UNSIGNED | Y | NULL | NULL for engine-generated events; `actor_kind` says which |
| `actor_kind` | VARCHAR(20) | N | — | `user \| rule \| job \| migration` |
| `from_state_code`, `to_state_code` | VARCHAR(30) | Y | — | |
| `minutes_delta` | INT | Y | NULL | Signed |
| `amount_delta_minor` | DECIMAL(18,4) | Y | NULL | Signed |
| `run_version_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT — set on `paid` / `released_on_regeneration` |
| `reason_code`, `reason_text` | VARCHAR(40), VARCHAR(1000) | Y | NULL | |
| `payload_json` | JSON | Y | NULL | The cap that bound, the workflow step, the superseding row |

**Keys.** PK `id` · UQ `(tenant_id, uuid)` ·
IX `(tenant_id, entitlement_id, occurred_at)`,
`(tenant_id, event_code, occurred_at)`, `(tenant_id, run_version_id)`.
**Policy.** Append-only — `UPDATE` and `DELETE` are denied to the application
database user on this table · audit: the ledger itself is the audit ·
PII `none` · retention `R-PAY-8Y`.

### C.14.8 `overtime_entitlement_segments`

*Purpose:* the per-segment split (R-C-045) that produces one payslip line each.

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `entitlement_id` | BIGINT UNSIGNED | N | — | FK CASCADE |
| `segment_index` | SMALLINT UNSIGNED | N | — | |
| `context_code` | VARCHAR(40) | N | — | |
| `tier_index` | SMALLINT UNSIGNED | N | `1` | |
| `minutes` | INT UNSIGNED | N | — | |
| `factor_num`, `factor_den` | INT UNSIGNED | N | — | The resolved exact rational |
| `component_code` | VARCHAR(40) | N | — | |
| `amount_minor` | DECIMAL(18,4) | Y | NULL | Rounded per the component's declared rounding |
| `payslip_line_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |

**Keys.** PK `id` · UQ `(tenant_id, uuid)`,
`(tenant_id, entitlement_id, segment_index)` ·
IX `(tenant_id, payslip_line_id)`.
**Assertion.** `SUM(segments.minutes) = entitlement.entitled_minutes` and
`SUM(segments.amount_minor) = entitlement.amount_minor`, both exactly, asserted at
write and again at run reconciliation (Law 7).
**Policy.** Tenant-owned · audit `full` · PII `none` · retention `R-PAY-8Y`.

### C.14.9 Referential integrity summary

Every foreign key above is declared with `ON DELETE RESTRICT` except the
version-owned child tables of a policy version, which cascade with their parent
version. *SmartPRS 2 has **zero** foreign keys on business tables and ships an
admin endpoint whose job is sweeping up the resulting orphans (spine S16).*

---

## C.15 API surface

All endpoints are `/api/v3`, OpenAPI-generated, cursor-paginated, idempotency-keyed
on unsafe methods, and bound to an explicit policy that fails closed (spine S13,
S17). UUIDs only — no sequential integers in any URL.

| Method | Path | Purpose | Permission |
|---|---|---|---|
| GET | `/attendance/overtime/policies` \| `/{uuid}` | Overtime policy versions, with scope and effective range | `attendance.overtime.policy.view` |
| POST | `/attendance/overtime/policies` · PATCH `/{uuid}` | Author a draft version (PATCH semantics — only supplied fields change) | `attendance.overtime.policy.manage` |
| POST | `/attendance/overtime/policies/{uuid}/diff` | Diff against the published version: old, new, impacted employee count, effective date | `attendance.overtime.policy.view` |
| POST | `/attendance/overtime/policies/{uuid}/publish` | Submit for maker-checker publication | `attendance.overtime.policy.publish` |
| POST | `/attendance/overtime/policies/{uuid}/simulate` | Run the policy against a real or synthetic employee-day and return the full trace | `attendance.overtime.policy.view` |
| GET | `/attendance/overtime/policies/resolve?employment=&on=` | **The policy that applies, and why** — including rejected candidates and the specificity arithmetic | `attendance.overtime.view` |
| GET | `/attendance/overtime/multipliers?policy=` | The `multiplier_ref` catalogue with bands and tiers | `attendance.overtime.policy.view` |
| GET | `/attendance/overtime/entitlements` | The register, filterable by employment, date range, state, source, run | `attendance.overtime.view` |
| GET | `/attendance/overtime/entitlements/{uuid}` | One entitlement with its segments and event history | `attendance.overtime.view` |
| GET | `/attendance/overtime/entitlements/{uuid}/explain` | The §C.12 trace | `attendance.overtime.view` (self-scoped for ESS) |
| POST | `/attendance/overtime/entitlements` | Create a **manual** entitlement (minutes + `multiplier_ref` + reason; never an amount) | `attendance.overtime.manual` |
| POST | `/attendance/overtime/entitlements/{uuid}/supersede` | Correct an entitlement, creating a superseding row | `attendance.overtime.override` |
| POST | `/attendance/overtime/entitlements/{uuid}/cancel` | Cancel with a reason | `attendance.overtime.manual` |
| POST | `/attendance/overtime/entitlements/{uuid}/submit` | Route to the approval workflow | `attendance.overtime.manual` |
| POST | `/attendance/overtime/entitlements/{uuid}/decide` | Approve or reject — **delegates to the workflow engine**; no OT-specific decision code | `attendance.overtime.approve` |
| POST | `/attendance/overtime/entitlements/bulk-import` | Bulk load, with per-row verdicts; nothing silently dropped | `attendance.overtime.manual` |
| GET | `/attendance/overtime/authorisations` \| `/{uuid}` | Pre-authorisations | `attendance.overtime.view` |
| POST | `/attendance/overtime/authorisations` | Raise a pre-authorisation (**self-service resolves the subject from the session**) | `attendance.overtime.request` |
| POST | `/attendance/overtime/authorisations/{uuid}/decide` | Approve or reject | `attendance.overtime.approve` |
| GET | `/attendance/overtime/caps/consumption?employment=&on=` | Cap headroom per cap, per window — what an approver needs before deciding | `attendance.overtime.view` |
| GET | `/attendance/overtime/exceptions` | Unauthorised excess, ineligible excess, capped-out minutes, divisor floors, empty wage base | `attendance.overtime.view` |
| GET | `/payroll/runs/{uuid}/overtime` | OT selected into a run, by employee and segment, with the excluded-unapproved counts | `payroll.run.view` |
| GET | `/ess/overtime` | The employee's own entitlements and traces | self-scoped, session-resolved |

**R-C-105** Every endpoint above has an explicit policy binding, asserted by the
CI route sweep (spine S13). *No authenticated attendance route in SmartPRS 2
carries a throttle, and `AttendanceReportController::logs():441-484` takes an
employee code straight from the URL with no self-scope and no role guard
(FINDING-B-SEC-01, HIGH).*

**R-C-106** `/ess/overtime` resolves the employee from the session. There is no
employee selector on any self-service overtime transaction (spine S13).

**R-C-107** No endpoint returns an exception message to the client; errors use the
platform envelope with a machine-readable code and a correlation id. *SmartPRS 2
returns raw `$e->getMessage()` — including SQL fragments — from at least five
attendance controllers, one of them with HTTP 200 (FINDING-B-SEC-07).*

**R-C-108 — permissions to be added to Part XXII's catalogue.** Nine new
`verb.resource` codes, in Part XXII's established `module.resource.verb` spelling:

| Permission | Class | MFA | Scoped | Meaning |
|---|---|---|---|---|
| `attendance.overtime.view` | r | — | yes | See entitlements, traces and cap consumption |
| `attendance.overtime.request` | r | — | self | Raise a pre-authorisation for oneself |
| `attendance.overtime.approve` | s | — | yes | Decide an authorisation or an entitlement |
| `attendance.overtime.manual` | s | — | yes | Create, submit or cancel a manual entitlement |
| `attendance.overtime.override` | p | ✓ | yes | Supersede a derived entitlement |
| `attendance.overtime.cap_override` | p | ✓ | yes | Approve overtime above a cap |
| `attendance.overtime.policy.view` | s | — | yes | See overtime policies and multipliers |
| `attendance.overtime.policy.manage` | s | — | yes | Author a draft policy version |
| `attendance.overtime.policy.publish` | p | ✓ | yes | Publish a policy version (maker-checker) |

*Recorded as an addition because Appendix A found Part XXII's 176-entry catalogue
materially incomplete against what the rest of the blueprint already assumes;
these nine are part of that shortfall.*

---

## C.16 Events

Published to the transactional outbox in the same transaction as the state change
(Part XXVIII §C13), registered in `domain_event_types` with a payload schema.

### Emitted

| Event | When | Notable payload |
|---|---|---|
| `attendance.overtime_created` | `CREATE_OT` applied — **already declared in Part XI §XI.18**; this appendix defines its payload | entitlement uuid, minutes raw/rounded/entitled, multiplier ref, context, approval mode, derivation source |
| `attendance.overtime.submitted` | Routed to a workflow | workflow instance, approver set |
| `attendance.overtime.approved` / `.rejected` | A workflow decision lands | decider, comment, minutes as approved |
| `attendance.overtime.capped` | A cap bound | cap code, window, limit, consumption, minutes removed, behaviour applied |
| `attendance.overtime.superseded` | A recalculation or correction supersedes | before/after minutes, superseding uuid, trigger |
| `attendance.overtime.cancelled` | Cancelled before payment | reason code, actor |
| `attendance.overtime.paid` | Selected and priced into a run | run version, period, segments, amount |
| `attendance.overtime.released` | Released on run regeneration | superseded run version, entitlement count, minutes |
| `attendance.overtime.reversed` | A post-payment reversal | arrears/recovery item raised, amount |
| `attendance.overtime.authorisation_raised` / `.approved` / `.rejected` / `.expired` / `.consumed` | Pre-authorisation lifecycle | employment, window, minutes, consumption |
| `attendance.overtime.unauthorised_excess` | Work beyond an authorisation | minutes, treatment applied |
| `attendance.overtime.ineligible_excess` | Surplus by an ineligible employee | minutes, resolving policy, reason |
| `overtime.policy.published` | A policy version publishes | artefact, version, impacted employee count, effective date |
| `overtime.policy.publication_blocked` | Precedence tie or unresolved `multiplier_ref` | both artefacts, affected employees, or the unresolved code |

### Consumed

| Event | From | Reaction |
|---|---|---|
| `attendance.day.derived` / `.superseded` | Part XI | Derive or re-derive entitlements for the day; supersede while unpaid |
| `attendance.settlement.frozen` / `.reopened` | Part XI | Freeze the OT contribution to the period; reopen re-derives |
| `calendar.weekly_off.worked` / `calendar.holiday.worked` | Part XII | The weekly-off / holiday OT rules evaluate |
| `leave.compoff.credited` | Part XIII | Assert `INV-C-01` for the source date |
| `payroll.run.regenerated` | Part XIV | Release entitlements paid by the superseded run version (§C.10.4) |
| `payroll.period.locked` / `.reopened` | Part XIV | Route post-lock changes to arrears; a reopen re-derives |
| `rulepack.activated` | Part XVII | Re-resolve `limit_source = rule_pack` cap values from the new pack version, forward-dated only |

---

## C.17 Migration from SmartPRS 2

### C.17.1 What comes across

| SmartPRS 2 artefact | Becomes | Transformation | Disposition |
|---|---|---|---|
| `overtime` rows (the manual master, `MasterController.php:339-345`) | `overtime_entitlements` with `derivation_source = 'migrated'` | `hours` → `entitled_minutes` (× 60); the stored `multiplier` → a per-row multiplier reference; `status` → `state_code`; `amount` → **`legacy_amount_minor`, retained unmodified** | **MIGRATE** |
| `overtime.paid_run_id` (created by runtime DDL, `generate():2571-2578`) | `paid_run_id` + `paid_run_version_id` | Mapped through the payroll run mapping; where the source run cannot be mapped, `paid_run_id` is NULL and the row is reported | **MIGRATE, partial** |
| The `26` and `8` divisors (`MasterController.php:761`, `PayrollGenController.php:2165`) | `cfg_overtime_policies.days_divisor_value = 26`, `hours_divisor_value = 8` on a `migration_baseline` policy version | Recorded as **the values SmartPRS 2's code currently uses**, marked `unverified`; **not** asserted as correct | **TRANSFORM** |
| The 1 / 1.5 / 2 multiplier dropdown | `cfg_overtime_multipliers` + bands seeded from the **modal multiplier actually stored** per tenant | There is no policy to seed from; the modal historic value is the only evidence — see `D-C-03` | **TRANSFORM, evidence-based** |
| The `'Overtime'` payslip line literal (`:2644-2647`) | A component `OT-MIGRATED` with declared flags | The name becomes a label; the grouping comes from `category`, not from the nine-keyword substring heuristic (NEW-PAY-30) | **TRANSFORM** |
| The keyword-based Variable classification | Component `category` | Declared, not inferred | **REPLACE** |

**R-C-109 — migrated amounts are never recomputed.** A migrated entitlement's
`legacy_amount_minor` is what SmartPRS 2 paid and is retained exactly. SmartPRS 3
does **not** re-price historical overtime, because re-pricing rewrites what an
employee was actually paid and what was actually reported. A **variance report**
lists every migrated row where the SmartPRS 3 arithmetic would have produced a
different figure, with the difference in paise, so that the discrepancy is
**known** rather than either hidden or silently corrected. *SmartPRS 2's amount was
never validated against hours × multiplier × rate (BR-PAY-732), so a variance is
expected on any row where a human typed a number.*

**R-C-110** Migrated rows carry `derivation_source = 'migrated'`,
`attendance_day_id = NULL` and `qualifying_minutes_raw = entitled_minutes` (there
is no raw figure to record), and their trace explicitly states *"migrated from
SmartPRS 2; no punch derivation exists for this entitlement."* A trace that
claimed a derivation would be a fabrication.

### C.17.2 What does not come across, and cannot

**R-C-111 — HISTORICAL DERIVED OVERTIME IS UNRECOVERABLE.** SmartPRS 2's
`overtime_min` is computed inside a report renderer at request time
(`AttendanceReportController.php:729-735`) and is **never persisted anywhere**.
There is no table, no column and no log holding it. It cannot be migrated,
because it does not exist as data.

**R-C-112 — and it cannot be reliably recomputed either.** Recomputing historical
derived OT from historical punches is **not** offered as a migration path, for
three independently sufficient reasons:

1. **The attendance date is wrong for night shifts.** SmartPRS 2 keys attendance
   by the punch's calendar date; Part XI records this as *"the root cause of the
   night-shift defect class"* and states that recomputing a shift-aware
   `attendance_date` for historical punches requires the historical roster, which
   the **name-keyed** `roster` may not support. Part XI already expects a manual
   reconciliation for night-shift employees.
2. **The historical shift cannot always be resolved.** `ShiftResolver` keys the
   roster by employee **display name** (NEW-ATT-004); a rename drops the roster
   and duplicate names collide, so the shift end that `overtime_min` measured
   against is not reconstructible for every employee-day.
3. **There was no policy.** Even with correct minutes, there is no historical
   eligibility, threshold, rounding, cap or multiplier configuration to apply,
   because none ever existed. Any recomputation would be applying **today's**
   policy to **historical** attendance, which is precisely what spine S4 exists to
   prevent.

**R-C-113** The migration report states this to the customer in plain terms, per
tenant: *"Overtime derived from your biometric punches was never stored by
SmartPRS 2 and cannot be recovered. Your migrated overtime history is the manually
entered register — N rows, M hours, ₹X — exactly as recorded. Derived overtime
begins on your first SmartPRS 3 period."* **Ametecs does not present a
reconstructed figure as history.**

**R-C-114** From cutover, derived and manual OT run **in parallel** for a declared
number of periods per `D-C-01`, producing a **variance report** — derived minutes
versus keyed minutes per employee per period — which is the evidence a tenant uses
to decide when to switch their default source. *This is the first time a customer
will be able to see what their biometric data was saying about overtime; the
gap is likely to be commercially significant in both directions, and it should be
seen before it is acted on.*

**R-C-115** Anything not migrated is **counted and reported**, never silently
dropped: rows with an unmappable employee, an unmappable run, a zero or negative
amount, a status not in the target vocabulary, or a date outside the migrated
range. *Part XXIX's standard is that retained and discarded counts are reported
and signed.*

---

## C.18 Worked examples

Every multiplier below is shown as a `<from policy>` placeholder with the value
used **in this worked example** stated alongside. **No multiplier, divisor,
threshold or cap in this section is asserted as a legally correct or statutorily
required value.** All arithmetic is exact, in integer paise, with a single
declared rounding at the end. Money is shown in Indian notation.

### C.18.1 Example 1 — weekday overtime, derived from punches

The governing brief's case: Hyderabad branch, Agent grade.

```
Employee            E-4471 · Grade G3 (Agent) · Branch HYD · Shift GEN-0930 (09:30–18:30, 8h)
Date                26-Aug-2026 (Wednesday, working day)
Policy resolved     OT-STD v4  scope { branch IN [HYD], grade IN [G3] }  specificity 520
Wage base           salary.wage_base('OT') = BASIC ₹18,000.00 + DA ₹4,500.00
                                            = ₹22,500.00 = 22,50,000 paise

Settlement (Part XI §XI.11, variant B)
  worked_minutes        612
  offset_minutes          0
  settled_surplus       132   disposition 'overtime'

Qualification (rule OT-WT-01 v3)
  threshold             <from policy: ot_daily_threshold_hours> = 8h = 480m
  qualifying raw        612 − 480 = 132m

Rounding
  mode/unit             <from policy> floor / 30m       min_block 30m
  rounded               FLOOR(132 ÷ 30) × 30 = 4 × 30   = 120m = 2h
  discarded             12m  (recorded on the entitlement and shown in the trace)

Caps
  CAP-DAY  limit <from policy> 180m   consumed 0 → 120m    did not bind
  CAP-WEEK limit <from policy> 720m   consumed 390 → 510m  did not bind

Multiplier
  multiplier_ref        'OT-MULT-WEEKDAY'   context normal_day
  factor                <from policy: OT-MULT-WEEKDAY.normal_day> = 3/2  (1.5 in this example)
  segments              1 × { 120m, normal_day, tier 1, 3/2, component OT-WEEKDAY }

Rate (FL-05)
  days_divisor          <from policy> 26     source fixed_number
  hours_divisor         8                    source shift_hours
  rate                  22,50,000 ÷ (26 × 8) = 22,50,000 ÷ 208
                        = 10,817.3076923…  paise/hour
                        = ₹108.1731 per hour                       NOT rounded

Amount (FL-06)
  exact                 (22,50,000 ÷ 208) × 2h × 3/2
                        = 22,50,000 × 3 ÷ 208
                        = 67,50,000 ÷ 208
                        = 32,451.923076…  paise
  rounding              half_up, unit 0.01
  amount                32,452 paise = ₹324.52                    residue +0.076924 paise

Payslip                 one line, "Overtime (Weekday)", component OT-WEEKDAY, ₹324.52
```

### C.18.2 Example 2 — work on a weekly off, routed to overtime

```
Employee            E-2210 · Shift OPS-0900 (09:00–18:00, 9h)
Date                30-Aug-2026 — resolved weekly off (pattern WO-HYD v4)
work_on_off_outcome overtime  (Part XII R-XII-015; the employee's choice was recorded
                               on 31-Aug within choice_window_days = 7)
Wage base           whole gross flagged is_ot_wage = ₹35,000.00 = 35,00,000 paise

Qualification (rule OT-WT-03 v2)
  min minutes on off    <from policy: ot_min_minutes_on_off> = 240m
  worked                480m ≥ 240m  → qualifies
  qualifying            480m  (the WHOLE duration — there is no schedule to exceed)

Rounding                floor / 30m  → 480m is already a multiple → 480m, discarded 0m

Caps                    CAP-DAY 180m does NOT apply — the policy's day cap declares
                        applies_on_weekly_off = false; CAP-WEEK 720m consumed 0 → 480m,
                        did not bind

Multiplier
  multiplier_ref        'OT-MULT-WEEKLY-OFF'   context weekly_off
  factor                <from policy: OT-MULT-WEEKLY-OFF.weekly_off> = 2/1  (2 in this example)
  segments              1 × { 480m, weekly_off, tier 1, 2/1, component OT-WEEKLY-OFF }

Rate (FL-05)
  days_divisor          <from policy> source period_payable_days = 26.0 for Aug-2026
                        min_days_divisor <from policy> 20 — not breached
  hours_divisor         9   source shift_hours
  rate                  35,00,000 ÷ (26 × 9) = 35,00,000 ÷ 234
                        = 14,957.2649572…  paise/hour = ₹149.5726 per hour

Amount (FL-06)
  exact                 (35,00,000 ÷ 234) × 8h × 2
                        = 35,00,000 × 16 ÷ 234
                        = 5,60,00,000 ÷ 234
                        = 2,39,316.239316…  paise
  amount                2,39,316 paise = ₹2,393.16

Exclusivity (INV-C-01)
  overtime 480 + comp_off 0 + additional_wage 0 + offset 0 + discarded 0 = 480 ✓
  comp_off_credits rows for (E-2210, 30-Aug-2026) = 0
  The comp-off credit rule CO-WO matched but declares stacks_with_overtime = false,
  and the recorded employee choice selected overtime at routing precedence step 2.
```

### C.18.3 Example 3 — a weekly cap binds, excess routed to comp-off

```
Employee            E-2210 · week Mon 24-Aug to Sun 30-Aug 2026
OT accrued in week  Mon 90m · Tue 120m · Wed 60m · Thu 120m · Sat 0m · Sun 480m  = 870m
CAP-WEEK            window week(mon)  measure ot_minutes
                    limit <from policy> 720m       breach_behaviour route_to_comp_off

Cap arithmetic (evaluated on Sunday's derivation, the day the cap binds)
  consumed before Sunday      390m
  Sunday qualifying           480m
  headroom                    720 − 390 = 330m
  paid as OT                  330m
  capped out                  480 − 330 = 150m
  capped_by_cap_code          'CAP-WEEK'

Routing of the capped-out minutes
  breach_behaviour            route_to_comp_off
  comp_off_credit_rule_code   'CO-WO' (Part XIII §XIII.10.2)
  full-day equivalent         <from credit rule> 480m
  raw credit                  150 ÷ 480 = 0.3125 days   (exact)
  credit_rounding             <from credit rule> half_up, unit 0.25 days
  credit                      0.3125 ÷ 0.25 = 1.25 → half_up → 1 → 0.25 days
  comp-off credited           0.25 days, expiring per the credit rule's expiry_days

Money for the paid portion
  segment                     330m = 5.5h · weekly_off · factor 2/1
  exact                       (35,00,000 ÷ 234) × 5.5 × 2 = 35,00,000 × 11 ÷ 234
                              = 3,85,00,000 ÷ 234 = 1,64,529.914529…  paise
  amount                      1,64,530 paise = ₹1,645.30

Exclusivity (INV-C-01)
  overtime 330 + comp_off_source 150 + additional_wage 0 + offset 0 + discarded 0 = 480 ✓
  Stacking is legal here NOT because stacks_with_overtime is true — it is false —
  but because the 150 minutes were never entitled as overtime: the cap removed them
  before entitlement, and the disposition of each minute is still exactly one.
  The trace states this explicitly.

Exception raised            ot_cap_bound (severity warning), naming CAP-WEEK, the limit,
                            the consumption, the minutes routed and the credit created
```

### C.18.4 Example 4 — tiered multipliers, two segments, two payslip lines

```
Employee            E-4471 (as Example 1; wage base ₹22,500.00, rate 22,50,000 ÷ 208)
Date                27-Aug-2026 · worked 690m against a 480m schedule
Qualifying raw      690 − 480 = 210m ; rounding floor/30m → 210m (already a multiple)

Multiplier OT-MULT-WEEKDAY, resolution_mode by_context, context normal_day, two tiers
  tier 1   from 0m   to 90m    factor <from policy> 3/2   component OT-WEEKDAY
  tier 2   from 90m  to NULL   factor <from policy> 2/1   component OT-WEEKDAY-TIER2

Segments
  seg 1    90m  = 1.5h  · tier 1 · 3/2
  seg 2   120m  = 2.0h  · tier 2 · 2/1
  assertion  90 + 120 = 210 = entitled_minutes ✓

Segment 1 amount
  exact    (22,50,000 ÷ 208) × 1.5 × 3/2 = 22,50,000 × 2.25 ÷ 208
           = 50,62,500 ÷ 208 = 24,338.9423076…  paise
  rounded  24,339 paise = ₹243.39

Segment 2 amount
  exact    (22,50,000 ÷ 208) × 2 × 2 = 90,00,000 ÷ 208
           = 43,269.2307692…  paise
  rounded  43,269 paise = ₹432.69

Payslip                 TWO lines:
                          "Overtime (Weekday)"        ₹243.39
                          "Overtime (Weekday Tier 2)" ₹432.69
OT total                ₹676.08 — defined as the SUM OF THE ROUNDED LINES (R-C-046),
                        never as a separately rounded total. The run reconciliation
                        sums lines and there is no second arithmetic path to disagree.
```

### C.18.5 Example 5 — parity with SmartPRS 2, to prove the migration baseline

This example exists to demonstrate that a `migration_baseline` policy reproduces
SmartPRS 2's arithmetic **exactly**, so that a tenant's first SmartPRS 3 period
does not change their overtime figure unless they choose to change the
configuration.

```
Tenant B's migration_baseline policy
  wage base            the whole monthly gross flagged is_ot_wage
  days_divisor         26   source fixed_number   — THE VALUE SMARTPRS 2'S CODE
  hours_divisor        8    source fixed_hours    — CURRENTLY USES, marked unverified
  multiplier           <from policy: OT-MIGRATED.normal_day> = 2/1
                       (seeded from the modal multiplier stored on the tenant's own rows)

Employee             CTC ₹6,00,000 → monthly ₹50,000.00 = 50,00,000 paise
OT                   8 hours

SmartPRS 3 (FL-05, FL-06)
  rate               50,00,000 ÷ (26 × 8) = 50,00,000 ÷ 208 = 24,038.4615384…  paise/h
                     = ₹240.3846 per hour
  exact              (50,00,000 ÷ 208) × 8 × 2 = 50,00,000 × 16 ÷ 208
                     = 8,00,00,000 ÷ 208 = 3,84,615.3846153…  paise
  amount             3,84,615 paise = ₹3,846.15

SmartPRS 2 (MasterController.php:761-762)
  hourly             600000 / 12 / 26 / 8 = 240.384615…      (three float divisions)
  amount             round(8 × 2 × 240.3846, 2) = 3846.15
  in-app guide       CalcGuideController:130 quotes ₹3,846   — matches

RESULT               IDENTICAL to the paise: ₹3,846.15.
The difference is not the number. The difference is that 26, 8, the multiplier and the
wage base are now four named, dated, scoped, diffable, approvable configuration values
instead of two literals duplicated across two files and an implicit "CTC ÷ 12" that
nobody declared.
```

---

## C.19 Edge cases and failure states

| # | Situation | Behaviour |
|---|---|---|
| 1 | Divisor resolves to zero, negative or null | `ERR_DIVISOR_INVALID`, blocking subject failure, naming policy, source and resolved value. Never a division by zero, never a fallback |
| 2 | `days_divisor_source = period_payable_days` and the employee has heavy LOP | `min_days_divisor` floors it; exception `ot_divisor_floored` (warning); the trace shows the uncapped value and the floor (R-C-039) |
| 3 | No component in the structure declares `is_ot_wage` | Blocking preview exception `ot_wage_base_empty`. **Not a ₹0.00 line** |
| 4 | `hours_divisor_source = shift_hours` and no shift resolves for the day | Per the policy's declared `on_missing_shift_hours`: `fail_subject` (default) or fall back to `contract_hours`. Never an implicit 8 |
| 5 | A holiday falls on a weekly off | Context `weekly_off_and_holiday` is selected if the multiplier declares it; otherwise `on_missing_context` decides, and its default is `error` — **never a silent 1×** |
| 6 | Night shift crosses midnight | OT is attributed to the **attendance date** (spine S10), not the punch's calendar date. All arithmetic is on absolute instants anchored to shift start |
| 7 | An attendance day is recalculated after the entitlement was approved but before payment | The entitlement is superseded; `re_approval_on_change` decides whether approval survives (R-C-076); the prior row and its approvals remain visible |
| 8 | An attendance day is recalculated **after** payment | An arrears item or recovery obligation (Part XI §XI.13, Part XVIII). **Never an edit, never a silent re-price** (R-C-089) |
| 9 | An employee exits mid-period with approved, unpaid OT | The entitlement is picked up by the full-and-final settlement run; it is not orphaned by the exit |
| 10 | The multiplier policy is superseded between derivation and payment | The entitlement's pinned version is used. A version published later cannot re-price it (R-C-011, spine S4) |
| 11 | A correction genuinely must re-price a pinned entitlement | An explicit, previewed, approved `supersede` operation with a reason — a deliberate act, not a side effect of publishing |
| 12 | Two OT policies tie on specificity and priority | **Publication is blocked** with both artefacts and the affected employees named (spine S3.2). Never a coin-toss |
| 13 | A cap window straddles two payroll periods | Evaluated on the window; each day attributed to its own period; consumption already locked is counted but never re-derived (R-C-056) |
| 14 | A day matches both the daily-threshold rule and the weekly-off rule | The weekly-off rule's `EXCEPT`, the daily rule's `NOT day.is_weekly_off`, and `INV-C-01` all hold; exactly one disposition results |
| 15 | Manual entry attempted for a date that already has a derived entitlement | Blocked unless the actor holds `attendance.overtime.override`; then it is created as `corrected`, superseding, with a reason. Both rows persist |
| 16 | A pre-authorisation is approved but no qualifying work occurs | Expires on the scheduled job, is reported, creates nothing (R-C-073) |
| 17 | Work beyond the pre-authorised minutes | `unauthorised_excess_treatment` applies; event `attendance.overtime.unauthorised_excess`; never silently paid, never silently invisible (R-C-072) |
| 18 | A qualifying employee is not eligible under any policy | Minutes recorded, `ot_minutes = 0`, `flag_ineligible_excess` raises a review flag (R-C-016) |
| 19 | Approved OT lands in a locked period | Arrears path (Part XI §XI.13); the alternative — reopening the period — is available under the declared disposition policy and its own approval |
| 20 | `min_block_minutes` exceeds the qualifying minutes | Zero OT; `minutes_discarded_by_rounding` records the full amount; the trace explains it as a policy outcome (R-C-049) |
| 21 | Qualifying minutes are negative (worked less than the threshold) | No entitlement. A negative entitlement is not representable — `entitled_minutes` is `UNSIGNED` |
| 22 | A cap with `breach_behaviour = block` binds | The day's OT derivation fails with a blocking exception; **no** OT is created, not even up to the cap. The employee and approver both see why |
| 23 | The comp-off/OT choice window lapses with no choice | `default_outcome` applies; the employee is notified that the default was applied and why (Part XIII R-XIII-064); `INV-C-01` holds on the default (R-C-066) |
| 24 | A migrated row's legacy amount disagrees with SmartPRS 3's arithmetic | Never recomputed; reported on the migration variance report with the difference in paise (R-C-109) |
| 25 | A rule pack activates with a changed statutory cap value | Forward-dated only. Existing entitlements keep their pinned pack version; the new limit applies from the pack's effective date (spine S12) |
| 26 | The payroll run is regenerated after OT was paid | Entitlements are released to `approved`, `paid_run_version_id` cleared on the projection, `released_on_regeneration` event recorded; the superseded run version remains queryable (R-C-088) |
| 27 | Bulk import contains rows for locked periods, unmappable employees or unknown multipliers | Per-row verdicts; every rejected row counted and reportable; **nothing silently dropped** (spine S10 principle, applied here) |
| 28 | An approver attempts to approve their own overtime | Denied by the workflow's self-approval prohibition (spine S8, R-C-070) |

---

## C.20 Tests

Exact-value regression tests. Each has an identifier so `R-XXXV-009`'s
Section 127 acceptance cases can cite one.

| ID | Priority | Scenario | Asserted exactly |
|---|---|---|---|
| `T-C-01` | **P0** | **Parity.** Migration-baseline policy, gross basis, divisor 26 × 8, multiplier 2/1, 8 hours (§C.18.5) | ₹3,846.15 to the paise; identical to `MasterController.php:761-762` and `CalcGuideController:130` |
| `T-C-02` | **P0** | **Weekday OT end to end** (§C.18.1) | rate ₹108.1731/h unrounded; 132m → 120m; amount ₹324.52; 12m recorded as discarded |
| `T-C-03` | **P0** | **`multiplier_ref` resolves.** A `CREATE_OT` naming a published multiplier | Publishes. A `CREATE_OT` naming an unknown code **blocks publication**, naming the rule, the code and the scopes checked (R-C-013) |
| `T-C-04` | **P0** | **Eligibility by branch** — "Hyderabad yes, Pune no" (§C.4.3 case 1) | HYD employee: entitlement created. PNQ employee: `ot_minutes = 0`, trace names policy and `is_eligible = false`. §C.13 row 1 |
| `T-C-05` | **P0** | **Eligibility by grade** — "Agent yes, Manager no" | G3: entitled. G7: zero, with the reason. §C.13 row 2 |
| `T-C-06` | **P0** | **`INV-C-01` — the exclusivity proof** (§C.8.4) | All eight assertions of R-C-065, including the database rejection at step 4 and idempotency at step 6 |
| `T-C-07` | **P0** | **Unapproved OT never reaches a payslip** | An entitlement in `pending_approval` at snapshot time produces no payslip line, and the preview reports `ot_excluded_unapproved` with exact minutes and employee count (R-C-074/075) |
| `T-C-08` | **P0** | **Reversal on regeneration** | Run v1 pays 120m; regeneration to v2 releases the entitlement to `approved` with `paid_run_version_id` cleared and a `released_on_regeneration` event; run v1 remains queryable and its payslip unchanged (R-C-088) |
| `T-C-09` | **P0** | **Cross-tenant isolation** | Tenant A's policies, multipliers, caps, entitlements, segments, authorisations and events are invisible and unmodifiable from tenant B on **every** endpoint in §C.15 |
| `T-C-10` | **P0** | **Effective dating** | A policy published in October with `effective_from = 01-Oct` does not change an August entitlement; the August payslip regenerates to the identical figure (R-C-010, spine S4) |
| `T-C-11` | **P0** | **Money exactness** | For a period with OT, `SUM(payslip OT lines) = SUM(segment amounts) = entitlement amount`, exactly, in paise; no float appears in the OT path (Law 7) |
| `T-C-12` | P1 | **Weekly-off OT** (§C.18.2) | 480m qualifies whole; rate ₹149.5726/h; amount ₹2,393.16; day cap correctly not applied |
| `T-C-13` | P1 | **Weekly cap with comp-off routing** (§C.18.3) | 330m paid at ₹1,645.30; 150m routed; comp-off credit exactly 0.25 days; `INV-C-01` holds; exception raised |
| `T-C-14` | P1 | **Tiered segments** (§C.18.4) | Two segments 90m/120m; two lines ₹243.39 and ₹432.69; total ₹676.08 as the sum of rounded lines |
| `T-C-15` | P1 | **Rounding and the minimum block** | floor/30m with `min_block = 30`: 132m → 120m; 25m → 0m with 25m recorded as discarded. §C.13 rows 6–7 |
| `T-C-16` | P1 | **Precedence tie blocks publication** | Two policies at specificity 340 and priority 0 whose scopes overlap: publication blocked, both named, affected employees listed |
| `T-C-17` | P1 | **Pre-approval regime** | `approval_regime = 'pre'`: 300m authorised, 400m worked → 300m entitled, 100m as `ot_unauthorised_excess` per the declared treatment. §C.13 rows 21–22 |
| `T-C-18` | P1 | **Two-step approval and self-approval prohibition** | A two-step workflow approves; the requester cannot be an approver at either step. §C.13 row 20 |
| `T-C-19` | P1 | **Holiday vs national holiday** | Two contexts resolve to two different factors; the rule's `CASE` branches are all validated at publish. §C.13 rows 8–9 |
| `T-C-20` | P1 | **Divisor configuration** | The same employee-day priced under `fixed_number(26)`, `period_payable_days` and `wage_per_month_hours(208)` yields three stated exact amounts, each traced with its named divisor artefact. §C.13 rows 11–13 |
| `T-C-21` | P1 | **Payable-days divisor floor** | Payable days 4, `min_days_divisor` 20 → the floor applies, `ot_divisor_floored` raised, both values in the trace |
| `T-C-22` | P1 | **Empty wage base** | No component flagged `is_ot_wage` → blocking exception `ot_wage_base_empty`, not a ₹0.00 line |
| `T-C-23` | P1 | **Recalculation while approved and unpaid** | Minutes increase → back to `pending_approval` under `on_increase`; decrease → applied and notified; both rows and both workflow instances visible |
| `T-C-24` | P1 | **Recalculation after payment** | An arrears item is raised with the exact delta; the paid entitlement is not edited |
| `T-C-25` | P1 | **Stage 11 idempotency** | Restarting stage 11 mid-run reproduces identical lines; no duplicate entitlement, no second payment |
| `T-C-26` | P2 | **Manual entry rejects a typed amount** | The API accepts minutes and `multiplier_ref` only; a payload carrying an amount is rejected with a machine-readable code (R-C-080) |
| `T-C-27` | P2 | **Migrated row is not re-priced** | `legacy_amount_minor` retained; the variance report shows the difference in paise; the trace states no punch derivation exists |
| `T-C-28` | P2 | **Night window** | Minutes inside `night_window_start/end` computed on instants across midnight; `night_premium_mode = replace_context` selects one factor, not a product |
| `T-C-29` | P2 | **Band-order gate** | A multiplier whose tiers overlap, leave a gap, or lack a terminal tier **fails publication**, per the Part VIII G7 precedent |
| `T-C-30` | P2 | **Explainability completeness** | Every field in §C.12.1 is present for a derived entitlement; a trace-less amount throws (Law 4) |

---

## C.21 Product Owner decisions

| ID | Question | Recommendation | Trade-offs |
|---|---|---|---|
| **D-C-01** | **At cutover, is derived OT or manual OT the source of record per tenant?** | Run **both in parallel** for a declared number of periods (recommend two), paying from the **manual** register exactly as today, while derived OT is computed in shadow and a per-employee variance report is produced. Switch to derived per tenant, on the tenant's signature, once they have seen the variance | Switching on day one would change what employees are paid in their first SmartPRS 3 month, from a data source no customer has ever seen output from. Not switching at all leaves the biometric investment unused, which is the defect this appendix exists to fix. The parallel period is the only option that lets the customer decide with evidence |
| **D-C-02** | **The seeded OT divisor per tenant.** | Seed **exactly what SmartPRS 2's code uses** — `days_divisor = 26`, `hours_divisor = 8` — on a `migration_baseline` policy version marked **`unverified`**, and raise a Configuration Studio task per tenant to confirm or change it | Recorded strictly per the brief's legal-values discipline. `26` is recorded as a value the code currently uses; the analysis records it as *"a convention, not law"*, and notes `26` is never a salary divisor anywhere else in SmartPRS 2. Ametecs seeding any other value would be Ametecs setting a customer's wage policy |
| **D-C-03** | **The seeded multipliers.** There is no policy to seed from — only per-row values on historic entries | Seed one multiplier set per tenant from the **modal multiplier actually stored** on that tenant's own OT rows, marked `unverified`, with a report of the full distribution. Where a tenant's rows show more than one value in material volume, seed the modal value and list the others for the tenant to configure as contexts | The alternative — seeding 1×/1.5×/2× because that is the dropdown — would be Ametecs asserting rates. The modal historic value is the only evidence that exists, and it is the value that reproduces the tenant's own recent payslips |
| **D-C-04** | **Which statutory bases does OT enter?** | **Not decided here.** Governed by `D-XIV-03` and `D-XVIII-07`, signed by the named compliance owner (spine `D-03`) | Deliberately deferred. §C.11 and R-C-003 record that this appendix takes no legal position. The cutover cost consequence is already quantified in Part XXIX's `C-ESI-OT` |
| **D-C-05** | **The default `breach_behaviour` on a cap.** | **`cap_and_flag`** | `cap_silent` is indistinguishable from a calculation bug to the affected employee. `block` is too aggressive as a default and can stop a whole day's derivation. `cap_and_flag` pays what is allowed and makes the ceiling visible |
| **D-C-06** | **Is OT reduced by loss of pay?** | **No.** `prorates = false` and `lop_affected = false` on the OT output component | OT is time actually worked; reducing it for absence deducts twice for the same absence. SmartPRS 2 also does not prorate OT, but by accident of ordering rather than by declaration. Recommending the same outcome by declaration means the behaviour survives a refactor |
| **D-C-07** | **The default eligibility posture at cutover.** | Seed a tenant-default policy with `is_eligible = true` **for manual entitlements only**, and `is_eligible = false` for derived entitlements until the tenant switches under `D-C-01`. Then raise a task to define real eligibility scopes | Seeding "nobody is eligible" would stop OT payments for every customer on day one. Seeding "everybody is eligible for derived OT" would start paying OT to people who have never claimed it. The split posture preserves today's behaviour exactly while the parallel run produces evidence |
| **D-C-08** | **Who chooses between OT, comp-off and extra pay by default?** | Seed `work_on_off_outcome = none` per **`D-XII-01`**, and raise an onboarding task per tenant | Consistency with the decision Part XII already registered matters more than any independent preference here. A default that silently starts creating comp-off liabilities is worse than an onboarding step |
| **D-C-09** | **Does a night premium multiply the day-type multiplier, or replace it?** | **`replace_context`** — the most specific context wins and a single factor applies | Multiplying compounds silently: a night shift on a national holiday could reach a factor nobody intended and nobody reviewed. `multiply_context` and `separate_component` remain configurable for customers whose agreements genuinely compound, but the default must be the one that cannot surprise anyone |
| **D-C-10** | **The seeded rounding block.** Part VII's RL-ATT-005 illustrates a 30-minute floor block | Seed **30 minutes, floor**, matching Part VII's illustration, **and** surface it prominently in the onboarding task, because a 30-minute floor block silently discards up to 29 minutes of worked time per employee per day | This is a business policy, not a technical default, and Ametecs should not choose it silently. Seeding 1 minute (no rounding) would be the neutral choice but would diverge from the blueprint's own worked example. R-C-049's recorded `minutes_discarded_by_rounding` makes whichever value is chosen visible rather than invisible |
| **D-C-11** | **Does the manual OT path survive cutover permanently, or sunset?** | **Survives permanently, but audited and reported.** Manual entry needs a reason code, an approval, and appears on the `derivation_source` metric (R-C-081) | Devices fail, employees work off-site, and a system with no manual path forces users into spreadsheets. But a manual path with no friction is how SmartPRS 2's register became the only path. The friction is the reason code, the approval and the metric |

---

## C.22 Cross-part reconciliation

Recorded rather than silently resolved, per Appendix A's standard. This appendix
is subordinate to the numbered parts; the items below are **proposed amendments**
for the lead architect, not unilateral changes.

| # | Item | Status |
|---|---|---|
| RC-C-01 | **Part VII §6.2 `CREATE_OT(minutes, multiplier_ref, approval)`** — `multiplier_ref` now resolves to `cfg_overtime_multipliers.multiplier_code`, unique per `(tenant, policy version, code)` | **Closed. No change to Part VII required.** `R-XXXV-028` is satisfied by R-C-002 and §C.14.2 |
| RC-C-02 | **Part VII RL-ATT-005** performs `STEP(...)` rounding **inside** `CREATE_OT` | **Proposed amendment.** Rounding belongs to the policy (R-C-048), so that changing a block size does not require re-approving a rule. Recommend RL-ATT-005 pass raw excess minutes and drop the inline `STEP`. The trace output is unchanged; only the layer performing the rounding moves |
| RC-C-03 | **Part VIII FL-06** computes the factor as `BANDS(day.day_type, policy.value('ot_multiplier_bands'))` inside the amount formula | **Proposed amendment.** Segmentation (R-C-045) moves the band walk into multiplier resolution, so FL-06 becomes a scalar multiplication evaluated per segment: `formula.value('F-OT-RATE-HOURLY') * TO_HOURS(segment.minutes) * segment.multiplier`. This enables tiering, keeps each payslip line independently rounded, and matches what Part XIV stage 11 already promises. **No new SPX function is required** — `segment.*` is a namespace symbol, following the precedent of `settlement.night_shift_count` (Part VIII §15.2) |
| RC-C-04 | **Part VIII FL-05** already reads `policy.value('ot_day_divisor')` | **Consistent.** §C.6.3 renames the key to `ot_days_divisor` and adds `ot_hours_divisor` for symmetry; recommend Part VIII adopt both spellings |
| RC-C-05 | **Four spellings of one reference** — `multiplier_ref` (Part VII), `overtime_multiplier_ref` (Part XII R-XII-015), `ot_multiplier_ref` (Part XII §XII.7.9 column), `ot_multiplier_bands` (Part VIII) | **Proposed convention:** `multiplier_ref` is the **action parameter** spelling; `ot_multiplier_ref` is the **column** spelling; all four denote one artefact — `cfg_overtime_multipliers.multiplier_code`. Recommend Part XII's prose adopt `ot_multiplier_ref` for consistency with its own column |
| RC-C-06 | **Part XI §XI.15 row 17** promises the OT configuration surface as *"rules + formula refs"* | **Closed.** It is now a named artefact; recommend the row read `cfg_overtime_policies` |
| RC-C-07 | **Part XI §XI.11.3** emits `ot_minutes` and `extra_pay_minutes` | **Consistent.** §C.8.3 adds the `disposition` label per minute, which the settlement engine already implies by producing disjoint outputs. Recommend §XI.11.3 name the disposition explicitly, since `INV-C-01` depends on it |
| RC-C-08 | **Part XI §XI.11.4 variant F** (*"paid extra work, pre-approval only"*) vs the OT policy's `approval_regime` | **Interlock added** (R-C-032): a settlement policy requiring authorisation and an OT policy in the same scope declaring `approval_regime = 'none'` is a **blocking publish error**. No change to Part XI's variant table |
| RC-C-09 | **Part XIII R-XIII-063** (`stacks_with_overtime = false` is enforced; *"a run asserts that no minute is settled twice"*) | **Given its enforcement point.** `INV-C-01` (R-C-062) is the named invariant, enforced at three points including a database constraint. No change to Part XIII |
| RC-C-10 | **Part XII R-XII-015** routes `work_on_off_outcome = overtime` to *"Part XIV"* | **Proposed amendment.** The routing target is this appendix (the OT policy), which then feeds Part XIV stage 11. Recommend the owning-part column read *"Appendix C / Part XIV"* |
| RC-C-11 | **Part XIV stage 11** *"OT earning line(s) by multiplier band"* | **Defined.** A band is `cfg_overtime_multiplier_bands`; one line per distinct `(component_code, factor)` (R-C-091). No change to Part XIV |
| RC-C-12 | **Part XXII permission catalogue** | **Nine permissions added** (R-C-108). Recorded as part of the catalogue shortfall Appendix A already reports |
| RC-C-13 | **Part XXVIII table catalogue** | **Eight tables added** (§C.14): `cfg_overtime_policies`, `cfg_overtime_multipliers`, `cfg_overtime_multiplier_bands`, `cfg_overtime_caps`, `overtime_entitlements`, `overtime_authorisations`, `overtime_entitlement_events`, `overtime_entitlement_segments`. Recorded as part of the catalogue shortfall Appendix A already reports |
| RC-C-14 | **Part XXXIV decision register** | **Eleven decisions added** (`D-C-01` … `D-C-11`), of which `D-C-04` is a pointer to `D-XIV-03` / `D-XVIII-07` and decides nothing |
| RC-C-15 | **Part XXXV `R-XXXV-028`** | **CLOSED** by this appendix. Recommend `R-XXXV-028`'s owning-part recommendation (*"Part XI recommended"*) be updated to name Appendix C, and Appendix B §B.0.1's note that it *"does not close GAP 6"* be updated to point here |

---

## C.23 Conformance

### C.23.1 The seven laws

| Law | How this appendix complies | Test |
|---|---|---|
| **1 — No business literal in code** | Every divisor, multiplier, threshold, block, cap, context and component code is a declared, scoped, dated configuration value. The `26` and `8` that are literals in two SmartPRS 2 files become two named policy values; the 1/1.5/2 dropdown becomes a band table with exact rational factors | `T-C-20`, `T-C-01`; the static-analysis rule on `Domain\*` |
| **2 — No schema change at runtime** | `overtime_entitlements.paid_run_id` and every other column is migration-owned. *SmartPRS 2 creates `overtime.paid_run_id` by DDL inside `generate()` at request time (`:2571-2578`)* | R-C-085; the CI DDL grep |
| **3 — Nothing overwritten; everything versioned and dated** | Policies supersede, never update. Entitlements supersede and carry `superseded_by_id`. State changes are immutable event rows on an append-only table. Payment release on regeneration is an event, not an UPDATE | `T-C-08`, `T-C-10`, `T-C-23` |
| **4 — Every number explains itself** | §C.12's trace names eligibility with rejected candidates and specificity arithmetic, the matched rule and condition, rounding with discarded minutes, every cap including those that did not bind, the multiplier context and exact rational factor, the wage base by contributing component, both divisors and their sources, the exact rational arithmetic, the rounding residue, the approvers, and every pinned version | `T-C-30`; a trace-less result throws |
| **5 — Authorisation server-side, declarative, fails closed** | Nine explicit permissions; every endpoint bound to a policy asserted by the CI sweep; ESS resolves the subject from the session; the self-approval prohibition is a workflow property, not a UI convenience | `T-C-09`, `T-C-18`; the route sweep |
| **6 — Tenancy is structural** | `tenant_id NOT NULL` on all eight tables and in every unique index, including the `INV-C-01` constraint; applied by the persistence layer | `T-C-09` — generated cross-tenant isolation tests for every aggregate and endpoint |
| **7 — Money is exact** | Multipliers are exact rationals (`3/2`, never `1.5` as a float); the rate is an exact rational in paise, unrounded by default; one declared rounding per segment line; the OT total is the sum of the rounded lines, never a separately rounded total; segment sums are asserted at write and at run reconciliation | `T-C-11`, `T-C-14`, `T-C-01`; the float ban in `Domain\Money` |

### C.23.2 Requirement index

| Range | Subject | Count |
|---|---|---|
| `R-C-001` – `R-C-003` | Standing, scope and the statutory disclaimer | 3 |
| `R-C-004` – `R-C-014` | The overtime policy artefact and `multiplier_ref` resolution | 11 |
| `R-C-015` – `R-C-022` | Eligibility | 8 |
| `R-C-023` – `R-C-032` | Qualifying conditions | 10 |
| `R-C-033` – `R-C-046` | The rate model, divisors and multipliers | 14 |
| `R-C-047` – `R-C-058` | Rounding, minimum block and caps | 12 |
| `R-C-059` – `R-C-067` | Comp-off, extra pay and `INV-C-01` | 9 |
| `R-C-068` – `R-C-076` | Approval | 9 |
| `R-C-077` – `R-C-092` | The register and payroll integration | 16 |
| `R-C-093` – `R-C-096` | Statutory treatment (referenced, not decided) | 4 |
| `R-C-097` – `R-C-101` | Explainability | 5 |
| `R-C-102` – `R-C-104` | Configuration surface | 3 |
| `R-C-105` – `R-C-108` | API and permissions | 4 |
| `R-C-109` – `R-C-115` | Migration | 7 |
| **Total** | | **115** |

Plus: **1 invariant** (`INV-C-01`), **8 tables**, **9 permissions**,
**23 API endpoints**, **14 emitted and 7 consumed events**, **28 configuration
acceptance cases**, **28 edge cases**, **30 tests**, **11 Product Owner
decisions**, **15 cross-part reconciliation items**.

### C.23.3 Section 125 module conformance

| Point | Where |
|---|---|
| Objective | §C.0, §C.2 |
| Actors and permissions | §C.15 (R-C-108) |
| Screens and fields | §C.13 (Configuration Studio surface), §C.15 (API-backed screens) |
| Master data | §C.14.1–§C.14.4 |
| Configuration | §C.13 |
| Rules | §C.5.3 (four publishable rules) |
| Workflows | §C.9 |
| Calculations | §C.6, §C.7, §C.18 |
| Database | §C.14 |
| API | §C.15 |
| Events | §C.16 |
| Notifications | §C.16 (emitted events; channels and templates belong to Part XIX) |
| Imports and exports | §C.15 (`bulk-import`), §C.17 |
| Reports | §C.15 (`/exceptions`, cap consumption, the `derivation_source` metric, the migration variance report) |
| Audit | §C.14 policies; every table `audit: full` |
| Security | §C.15 (R-C-105–R-C-107), §C.23.1 Laws 5 and 6 |
| Dependencies | §C.2, §C.22 |
| Failure states | §C.19 |
| Edge cases | §C.19 |
| Tests | §C.20 |
| Expected output | §C.18 (five worked examples with exact arithmetic) |
