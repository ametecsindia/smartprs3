# PART XIII — LEAVE ARCHITECTURE

*Covers brief Sections 27, 28, 29, 30 and 49.*
*Conforms to spine S3 (org model, scope, precedence), S4 (effective dating, draft/publish),
S5 (SPX), S6 (rule engine), S7 (formula engine), S8 (workflow engine), S12 (statutory rule
packs), S13 (security), S14 (audit ledger), S15 (explainability), S16 (data conventions),
S17 (API).*
*Depends on: Part VI (configuration), Part VII (rules), Part VIII (formulas), Part IX
(workflow), Part X (employee schema), Part XI (attendance), **Part XII (work week, weekly
off, holiday — which owns `is_weekly_off`, `is_holiday`, the resolved day type and the
three day counts)**. Depended on by: Part XIV (payroll), Part XX (payslip), reports.*
*Evidence base: `analysis/E-leave-requests-notifications.md` and `analysis/B-attendance.md`.
Every claim about SmartPRS 2 in this part carries a `file.php:line` citation carried
through from those two files.*

---

## XIII.0 Purpose

This part decides four things.

1. **What a leave type is** — an unlimited, customer-defined, effective-dated,
   scoped artefact whose every behaviour is declared as a **flag or a formula**, so that
   **no behaviour anywhere in SmartPRS 3 is ever keyed off a leave type's NAME**.
2. **How balance comes to exist and cease to exist** — an accrual, carry-forward, lapse,
   expiry, encashment and adjustment **ledger**, with the arithmetic expressed as
   effective-dated formulas (Part VIII) rather than code, and with integrity properties
   that make a double deduction structurally impossible.
3. **How an application is evaluated** — day counting, units below a day, adjacency
   ("sandwich") as a configurable policy rather than a global boolean, eligibility,
   restriction, blackout, staffing, documents, and the workflow that decides it.
4. **How leave meets attendance and payroll exactly** — one number, published once, that
   reconciles with the resolved day type (Part XII §XII.5) and the payable-day count
   (Part XII §XII.6.3), so that unpaid leave produces an LOP day and paid leave does not.

The last of those is not a refinement. In SmartPRS 2 it is broken in a way that is
**presently costing money on every payroll run**, and §XIII.1.1 is the evidence.

---

## XIII.1 What SmartPRS 2 does today, and why it must change

The findings in this area are the most severe in the codebase. They are presented in order
of financial and legal consequence, not in order of code.

### XIII.1.1 Loss-of-Pay leave is paid in full — a live, ongoing salary overpayment

The intended design is correct: `leaves.type_id` → `leave_types.paid`, and payroll skips
crediting a day when the type is unpaid. The implementation never writes the key.

```
apply()   writes  type_name   (a free-text string)
apply()   never writes  type_id                       -- LeaveController.php:364-378
payroll   reads   type_id → leave_types.paid          -- PayrollGenController.php:1063-1067
          and     paidLeaveDates(): (int)($lv->type_id ?? 0) → never a key in $paidMap
                                                       -- PayrollGenController.php:743-746
```

`type_id` is therefore **always NULL**. Payroll's paid test is always `true`, and the
`continue` that skips unpaid leave is **unreachable code**. The analysis verified this by
grepping `type_id` across `app/`: there are exactly **two reads and zero writes**
(`E-leave-requests-notifications.md` §2.2, DEF-LVE-02, severity **CRITICAL**).

> **Every approved leave — including leave explicitly filed as "Loss of Pay" — is
> credited as a paid present day. LOP deductions never happen through the leave route.
> Direct salary overpayment.** (`E-…` DEF-LVE-02)

The blast radius is a data question the Product Owner must answer with a query on the live
database (`E-…` PO-01, carried forward here as **D-XIII-01**). It is not a design question,
and the design consequence is stated as **R-XIII-055**: the paid/unpaid classification is
carried by a foreign key, asserted by an integration test that fails the build, and
reconciled by an arithmetic assertion inside every payroll run.

### XIII.1.2 Twelve of fifteen named leave-policy features are wholly absent

`E-leave-requests-notifications.md` §7 Q4 enumerates them against source. Reproduced
verbatim in substance:

| Feature | Status in SmartPRS 2 | Evidence |
|---|---|---|
| Accrual (monthly / quarterly / on service) | **ABSENT** | `computeBalances():191-244` has no date arithmetic beyond `whereYear`. The full annual `days_per_year` is available from 1 January |
| Carry-forward | **PARTIAL — data only, zero behaviour** | column `…000200…:79`, editable at `MasterController.php:72` and `AppController.php:10768`, **never in the arithmetic** at `:239` |
| Lapse at year end | **ABSENT** (implicitly total) | nothing is carried, capped or expired deliberately |
| Encashment | **ABSENT** | no code path converts balance to money; "Leave Encashment" is only a bonus-module label (`AppController.php:3939`) |
| Proration on join / exit | **ABSENT** | no DOJ/LWD term in `computeBalances()` |
| Negative balance / advance leave | **ABSENT** | `:354` refuses outright |
| Half-day leave | **ABSENT** | `leaves.days` is `decimal(4,1)`; `:370` always writes a whole number |
| Hourly / short leave | **ABSENT** | no unit smaller than a day exists |
| Blackout periods | **ABSENT** | grep returns nothing |
| Clubbing / prefix-suffix rules | **ABSENT** | grep returns nothing |
| Sandwich rule | **PARTIAL — payroll only, not leave** | `sandwich_rule` default **0/off** (`AppController.php:12179`), `PayrollGenController::sandwichDays():770-813`; operates on attendance coverage, not on balance deduction |
| Comp-off | **PARTIAL — a type name only** | two hard-coded fallback lists with `days_per_year = 0`; **no earning mechanism** anywhere |
| Minimum-staffing checks | **ABSENT** | grep for `staffing`/`min_staff` returns nothing; no overlap detection, no team calendar |
| Cancellation / withdrawal | **ABSENT** | `leaves.status` is `ENUM('pending','approved','rejected')` and `ensureCols()` never widens it (`:34-57`) |
| Attachments (medical certificates) | **ABSENT** | no upload route for leave |
| Eligibility per type / grade / gender / probation | **ABSENT** | one flat `days_per_year` per type per tenant |
| Advance notice, max consecutive days | **ABSENT** | no such validation |

> **"Twelve of the fifteen named features are wholly absent; two are data-only or
> payroll-only; none is fully implemented."** (`E-…` §7 Q4)

This is why the migration verdict for the leave module is **REPLACE**, not repair
(`E-…` §9): *"Rebuilding is cheaper than repairing."*

### XIII.1.3 Carry-forward is configured, visible, and inert

`leave_types.carry_forward` exists, is editable in Masters, is surfaced in the UI, is read
into the hard-coded fallback objects — and **never appears in the arithmetic**. The
method's own docblock at `LeaveController.php:186-189` promises *"entitlement
(days_per_year) + carry-forward opening − used − pending"*; the code at `:239` computes
`round($ent - $u, 1)` (`E-…` DEF-LVE-03, BR-LVE-004, severity **HIGH**).

> *"HR sets 'Carry Forward: Yes' on Earned Leave and believes unused days roll over. They
> do not — they vanish at the year boundary."*

The migration consequence is that **opening balances cannot be derived** and must be stated
by the customer (`E-…` PO-02; carried here as **D-XIII-02**).

### XIII.1.4 The balance can be overdrawn in eight distinct ways

There is no ledger. The balance is recomputed on demand from `leaves` rows
(`computeBalances():191-244`), with **no reservation, no locking, no unique constraint and
no approval-time re-check** (`E-…` §7 Q5):

| # | Failure mode | Mechanism |
|---|---|---|
| 1 | **Concurrency double-spend** | two applications read the same balance, both pass, both insert; grep confirms no `DB::transaction`/`lockForUpdate` in `LeaveController.php` |
| 2 | **Enforced at apply, never at approve** | `decide():392-466` performs **no balance check at all**; two individually-valid pending applications are both approvable |
| 3 | **Re-decide inflates or deflates consumption** | no pending-state guard; an approved leave can be flipped and re-flipped, `used`/`pending` recomputing each time (DEF-REQ-02) |
| 4 | **Backdating is unrestricted** | `from`/`to` are `required\|string` parsed by Carbon with no past-date and no locked-period check (`:274-280`, `:329-338`) |
| 5 | **Year attribution by `from_date` only** | 28 Dec → 4 Jan charges **all 8 days** to the from-year (`whereYear('from_date', $year)` at `:214`) |
| 6 | **An unrecognised type bypasses the check entirely** | `type` is validated only `nullable\|string\|max:60`, never checked against `leave_types`; `firstWhere('type', …)` returns null and the whole balance block is skipped — **a trailing space in the type name is sufficient** (SEC-06, **HIGH**) |
| 7 | **A renamed type resets consumption to zero** | usage is grouped by the free-text `type_name` (DEF-LVE-07, **HIGH**) |
| 8 | **The published number and the enforced number differ** | the leave module publishes `ent − used` and enforces `ent − used − pending`; ESS publishes `ent − used` (DEF-LVE-04) |

> **"The balance is a derived figure recomputed on demand from `leaves` rows, with no
> ledger, no reservation, no locking and no approval-time re-check — so it can be
> over-drawn by concurrency, by double-approval, by backdating, by a type-name trick, or
> by a rename."** (`E-…` §7 Q5)

### XIII.1.5 Six places key behaviour off the leave type's NAME string

`E-…` §7 Q3 enumerates every coupling:

1. `leaves.type_name` is the persisted key; `type_id` is never written.
2. Balance consumption is grouped by the name string (`:217`, `:231-232`).
3. The balance *enforcement* lookup is an exact string match (`:342`).
4. ESS groups by the same name string independently (`EssController.php:209`, `:223-224`).
5. The applied type is accepted as free text and never validated (`:276`).
6. `'Leave'` is a hard-coded fallback bucket in five places, accumulating untyped leave
   under a pseudo-type that has no quota.

Notably, the analysis found **no** `if ($type === 'Loss of Pay')` — the *design* correctly
uses a `paid` flag. It is defeated in practice only because the flag can never be reached
without `type_id` (§XIII.1.1). SmartPRS 3 keeps the flag-based design and makes the key
reachable, by constraint.

### XIII.1.6 Three divergent hard-coded default lists for one concept

| Source | Names | Quotas |
|---|---|---|
| `LeaveController::listLeaves():176` (apply dropdown) | Casual, Sick, Earned, Comp-Off, Loss of Pay | — |
| `LeaveController::computeBalances():200-206` | the same five | 12 / 12 / 15 / 0 / 0 |
| `EssController::leaveBalances():195-200` | **four — Comp-Off omitted** | 12 / 12 / 15 / 0 |

A tenant with no configured types sees Comp-Off in the dropdown, sees it in the leave
module's balances, and does **not** see it in My Space (DEF-LVE-05).

### XIII.1.7 Balance charged ≠ attendance credited

Leave charges **inclusive calendar days** (`LeaveController.php:329-338`); payroll credits
only **working days**, `continue`-ing over `isWeekOff()` and `$holidays[$date]`
(`PayrollGenController.php:1073-1077`).

> *"A Fri–Mon leave costs 4 days of balance and credits 2 days of attendance. Employees
> exhaust quota twice as fast as their pay reflects."* (`E-…` DEF-LVE-06, **HIGH**;
> worked at `E-…` §4.4)

### XIII.1.8 The leave year is the calendar year; everything else is April–March

`whereYear('from_date', $year)` with `year = now()->year` (`:193`, `:214`), while requests
carry an April–March `fin_year` (BR-REQ-022) and payroll rolls on 1 April. Balances reset
on 1 January (DEF-LVE-08). *"Customers whose leave year is Apr–Mar (most Indian employers)
cannot have it"* (NEW-LVE-106).

### XIII.1.9 Further defects carried forward

| Finding | Evidence | Consequence |
|---|---|---|
| **Leave submission notifies nobody** | `LeaveController.php:381-385` vs `RequestController.php:570-594` | *"Managers do not know a leave request exists unless they open the inbox. In practice leave sits unapproved"* (DEF-NOT-01, **HIGH**) |
| **Any authenticated user can read any colleague's leave balances** | `balances():247-264`, route `routes/web.php:420`; matched by name, emp_code **or raw numeric id** | *"Leave consumption per type is a direct proxy for sickness patterns — special-category personal data under DPDP"* (SEC-07) |
| **Balance queries are not tenant-scoped** | `:212-215`; `EssController.php:205-206` | safe today only because `employees.id` is globally unique (NEW-LVE-102) |
| **Leave has its own approver resolver** | `:96-117` vs `ApprovalService.php:310-352` | two copies of the most reused rule in the product, already diverged (NEW-LVE-108) |
| **Tolerant employee resolution loads the whole employee table into PHP** | `:315-324` | O(n) memory per application (NEW-LVE-104, TD-15) |
| **`leaves.tenant_id`/`company_id` NOT NULL, copied from the employee** | `…000200…:87-88` vs `:365-366` | an employee with no company can never take leave; the failure surfaces as a raw SQL error in a 422 body (DEF-LVE-09) |
| **A blank re-decide erases prior remarks** | `LeaveController.php:422` | the recorded reason for a rejection is wiped (DEF-REQ-08) |
| **`LeaveApplication` model + a lying migration filename** | `app/Models/LeaveApplication.php`; `2026_01_01_000400_create_leave_applications_table.php` actually creates `pf_esi_settings`, `tds_rules`, `pt_slabs`, `tds_returns` | any developer searching for leave persistence is sent the wrong way (DEF-LVE-01) |
| **ESS renders every leave row as the literal `'Leave'`** | `EssController.php:155` | the employee cannot tell sick from casual from LOP (DEF-ESS-01) |
| **No test coverage** | `tests/Unit/GreetingServiceTest.php` is the only test in the whole area | *"Every rule in §3 is unprotected against regression"* (TD-12) |

### XIII.1.10 What SmartPRS 3 explicitly does not replicate

| SmartPRS 2 behaviour | Not replicated because |
|---|---|
| `type_name` as the persisted key | §XIII.2.1: the applied type is a **foreign key** to a versioned leave type; free text is not accepted |
| Balance derived on demand from application rows | §XIII.3: a ledger with reservation and posting |
| Balance checked at apply only | §XIII.11: re-checked and posted at approval, inside one transaction |
| Inclusive calendar-day counting | §XIII.6: day counting is a per-type declaration evaluated against Part XII's resolved day type |
| Calendar leave year with no configuration | §XIII.4.1: `leave_year_basis` is configuration, with a split rule for boundary-spanning leave |
| A global `sandwich_rule` boolean | §XIII.8: a full Leave Adjacency Policy per leave type |
| `carry_forward` with no arithmetic | §XIII.4.5: carry-forward with cap, expiry and lapse, all ledger transactions |
| Three hard-coded default type lists | §XIII.2.4: exactly one seeded pack, and it is data |
| A tri-state ENUM status that cannot be widened | §XIII.7.8: a modelled state machine over a value set (spine S16) |
| Leave's own approver resolver | Part IX: one workflow engine, zero module-specific approval code |
| Unscoped balance reads by path segment | §XIII.15: self resolves from the session; a manager needs an explicit scoped permission |
| Silent best-effort notification | Part XIX: every notifiable event is a registry entry with a per-recipient delivery record |

---

## XIII.2 The Leave Type master *(brief Section 27)*

### XIII.2.1 The founding rule

**R-XIII-001** A leave type is identified by an immutable **`code`**. Its `name` is display
metadata, translatable, and changeable at any time with **zero behavioural effect**.

**R-XIII-002** **No behaviour anywhere in SmartPRS 3 is keyed off a leave type's name.**
Not in PHP, not in SPX, not in a report definition, not in a payslip template, not in a
notification template condition, not in a migration. All behaviour derives from the type's
declared flags and formulas.
*Enforcement:* (a) SPX cannot apply `CONTAINS` to a `Code` (Part VII §5.6) and
`leave.type` is a `Code`, so a name test is **inexpressible**; (b) a static-analysis rule
fails the build on a string comparison against a leave-type name in `Domain\*`; (c) the
`leaves` table has no name column at all, only `leave_type_version_id`.
*This is the direct fix for the six name couplings at `E-…` §7 Q3 and for DEF-LVE-07,
where renaming "Casual Leave" to "Casual" resets every employee's consumption of it to zero
mid-year.*

**R-XIII-003** The number of leave types is **unlimited and customer-defined**. There is no
platform enum, no seeded list that behaviour depends on, and no maximum. Types are scoped
(spine S3.1) and effective-dated (spine S4) like every other artefact, so "Sabbatical, for
grade M4 and above, from 01-Apr-2027" is one published version.

### XIII.2.2 The declared attributes

Grouped by concern. Each is a column or typed child row on `leave_type_versions`
(§XIII.12.2), each has a reader, and none is a code literal.

**Identity and presentation**

| Attribute | Type | Notes |
|---|---|---|
| `code` | Code | immutable; the only thing behaviour may test |
| `name`, `short_name`, `description` | Text | display only (R-XIII-002) |
| `display_colour`, `display_order` | Text / Number | calendar rendering |
| `is_visible_to_employee` | Boolean | an HR-only type (e.g. `SUSPENSION`) |
| `payslip_display`, `payslip_label_key` | Code | `hidden / count_only / named_line` |

**Classification — the flags all other engines read**

| Attribute | Type | Read by |
|---|---|---|
| `is_paid` | Boolean | payable-day computation (Part XII §XII.6.3), payroll |
| `payable_fraction_expr` | SPX → Number | for partially paid leave (e.g. half-pay sick leave); default `1` when paid, `0` when not |
| `is_statutory` | Boolean | statutory carve-outs (§XIII.10); exposed as `leave.is_statutory` (Part VII §5.7) |
| `statutory_ref` | Code | links to the rule pack entry (spine S12); `<from rule pack>` |
| `is_encashable` | Boolean | encashment engine (§XIII.5) |
| `is_carry_forwardable` | Boolean | carry-forward (§XIII.4.5) |
| `is_lop` | Boolean | marks the type whose approval *creates* an LOP day rather than consuming a balance |
| `is_comp_off` | Boolean | consumption side of the comp-off ledger (§XIII.9) |
| `is_leave_without_pay_substitute` | Boolean | the type an over-drawn application converts into |
| `counts_towards_service` | Boolean | gratuity/service arithmetic (Part XIV) |
| `counts_towards_accrual` | Boolean | whether other types accrue while on this leave |
| `affects_attendance_status` | Code | which `day_type` an approved day produces (Part XII §XII.5.3) |
| `requires_medical_certificate` | Boolean + threshold | §XIII.7.5 |
| `gender_applicability` | List<Code> | e.g. maternity; **values are configuration, never code** |
| `is_maternity_family` | Boolean | groups the statutory family for §XIII.10 aggregation rules |

**Entitlement and units**

| Attribute | Type | Notes |
|---|---|---|
| `entitlement_expr` | SPX → Days | may vary by grade, service band, category — see §XIII.4.2 |
| `entitlement_basis` | Code | `annual / per_service_year / per_event / unlimited / ledger_only` |
| `min_unit` | Code | `day / half_day / quarter_day / hour / custom_hours` |
| `custom_unit_hours` | Number | when `custom_hours` |
| `unit_rounding` | {mode, unit} | **no default** (spine S5.4) |
| `max_balance` | Days | cap on accumulated balance |
| `allow_negative_balance`, `negative_balance_limit` | Boolean / Days | §XIII.4.7 |

**Eligibility**

| Attribute | Type | Notes |
|---|---|---|
| `eligibility_expr` | SPX → Boolean | any employee attribute, service, grade, category, custom field |
| `min_service_months` | Number | convenience field compiled into `eligibility_expr` |
| `available_during_probation` | Boolean | |
| `available_during_notice_period` | Boolean | §XIII.7.7 |
| `available_after_resignation` | Code | `yes / no / with_approval` |
| `employment_types`, `employee_categories` | List<Code> | via scope, not a name test |

**Application rules** — see §XIII.7 for the full list; each is a column on the version.

**Accrual, carry-forward, lapse, encashment** — see §XIII.4 and §XIII.5; these are typed
child rows (`leave_accrual_rules`, `leave_carry_forward_rules`,
`leave_encashment_rules`) rather than columns, because a type may have more than one of
each, scoped and effective-dated independently.

**Adjacency (sandwich)** — see §XIII.8; a typed child row `leave_adjacency_policies`.

### XIII.2.3 Scoping and effective dating

**R-XIII-004** A leave type version carries `scope_json` and `priority` and is resolved by
spine S3.2 specificity. "Earned Leave is 21 days for grade M3+, 15 otherwise, and 18 in
Maharashtra" is three versions of one type, not three types — and the resolution is
explained in the trace.

**R-XIII-005** Changing an entitlement is a **new version with an effective date**, never
an update. A leave balance computed for March 2027 uses the version effective in March
2027 (spine S4). *SmartPRS 2 edits `leave_types.days_per_year` in place, silently changing
every historic balance calculation, because balance is recomputed on demand from the
current row (`computeBalances():234`).*

### XIII.2.4 The seeded pack

**R-XIII-006** SmartPRS 3 ships **one** seeded leave-type pack per jurisdiction, as data,
installed at tenant creation and fully editable thereafter. There is no second list in the
UI, no third list in self-service, and no fallback list in code. *SmartPRS 2 has three
divergent hard-coded lists (DEF-LVE-05).* If a tenant has zero leave types, the UI says so;
it does not invent five.

---

## XIII.3 The balance ledger

### XIII.3.1 Why a ledger

**R-XIII-007** Leave balance is the **sum of a ledger**, not a derived expression over
application rows. *This is the structural answer to all eight over-draw modes at
`E-…` §7 Q5 and to `E-…` requirement 26.*

```
balance(employee, type, as_of) = Σ ledger.units WHERE posted_at <= as_of
                                              AND status = 'posted'
available(employee, type, as_of) = balance − Σ reservations (status = 'reserved')
```

`agg.balance(ledger, as_of)` (spine S5.5) is the SPX accessor and calls the same service.

### XIII.3.2 Transaction types

**R-XIII-008** The closed set of ledger transaction types. Every one is signed, dated,
attributed, reversible only by an equal-and-opposite entry, and carries the artefact
versions that produced it.

| Type | Sign | Source |
|---|---|---|
| `opening_balance` | + | migration or an HR-stated opening (D-XIII-02) |
| `accrual` | + | the accrual engine (§XIII.4) |
| `carry_forward_in` | + | year rollover (§XIII.4.5) |
| `carry_forward_out` | − | year rollover, paired with the `_in` |
| `grant` | + | an ad-hoc HR grant, reason mandatory |
| `comp_off_credit` | + | comp-off earning (§XIII.9) |
| `reservation` | − (soft) | a pending application; not part of `balance`, part of `available` |
| `reservation_release` | + (soft) | rejection, cancellation, withdrawal, expiry |
| `consumption` | − | an approved leave day |
| `consumption_reversal` | + | cancellation or withdrawal of approved leave |
| `encashment` | − | encashment settlement (§XIII.5) |
| `lapse` | − | scheduled lapse (§XIII.4.6) |
| `expiry` | − | expiry of a dated credit (comp-off, carry-forward tranche) |
| `adjustment` | ± | HR correction; reason and approval mandatory |
| `settlement` | − | full-and-final closure of a residual balance |
| `transfer_in` / `transfer_out` | ± | inter-entity transfer where policy permits |

**R-XIII-009** A ledger row is **never updated and never deleted** (Law 3). A mistake is
corrected by a `consumption_reversal` or `adjustment` that references the original row.

**R-XIII-010** Every ledger row carries `leave_type_version_id`, `accrual_rule_version_id`
(where applicable), `formula_version_id`, `workflow_instance_id` and `trace_id`, so any
balance is explainable to its origin three years later (Law 4).

### XIII.3.3 Tranches and expiry

**R-XIII-011** Credits that expire (comp-off, a carry-forward tranche with a use-by window)
are **tranched**: the credit row carries `expires_on`, and consumption is allocated across
tranches by a declared `consumption_order` (`fifo | expiring_first | lifo`), defaulting to
`expiring_first`. The allocation is recorded on the consumption row, so an employee can be
told exactly which credits their leave consumed.

---

## XIII.4 The accrual engine

### XIII.4.1 The leave year

**R-XIII-012** `leave_year_basis` is **configuration per leave type** (falling back to a
tenant default): `calendar_year | financial_year | anniversary_of_joining |
custom_start_month`. *SmartPRS 2 hard-codes `now()->year` while requests and payroll use
April–March (DEF-LVE-08, NEW-LVE-106).*

**R-XIII-013** A leave application spanning a leave-year boundary **splits its consumption
across both years**, day by day, against the entitlement of the year each day falls in.
*SmartPRS 2 attributes all eight days of a 28-Dec → 4-Jan leave to the from-year
(`whereYear('from_date', …)` at `:214`) — `E-…` requirement 42.*

### XIII.4.2 Entitlement as a formula

**R-XIII-014** Entitlement is an **effective-dated SPX formula** (Part VIII), typed `Days`,
not a scalar column. It may reference `service.*`, `org.*`, `employee.*` and `policy.*`.

```
-- Earned Leave: 15 days, rising to 21 after five completed years
EL_ENTITLEMENT :=
  CASE WHEN service.completed_years >= 5 THEN 21d
       ELSE 15d
  END
```

```
-- Sick Leave: banded by grade, with a floor from the rule pack
SL_ENTITLEMENT :=
  MAX( BANDS( org.grade_rank,
              [ {from: 1, to: 4,    result: 7d  },
                {from: 4, to: 8,    result: 10d },
                {from: 8, to: NULL, result: 12d } ] ),
       statutory.value('SL_MIN_DAYS') )        -- <from rule pack>
```

**R-XIII-015** No entitlement value is asserted in this blueprint. Where a statute sets a
floor, it is `statutory.value(...)` resolved from the signed rule pack (spine S12) with a
`source_reference`, and the configuration validator warns or blocks when a tenant's formula
can fall below it (spine S12 layering).

### XIII.4.3 Accrual frequency and basis

**R-XIII-016** An accrual rule declares:

| Field | Values | Notes |
|---|---|---|
| `frequency` | `on_entitlement_grant` (whole year up front) · `monthly` · `quarterly` · `half_yearly` · `annual` · `per_completed_service_period` · `per_worked_days` · `per_worked_hours` · `on_event` | |
| `accrual_basis` | `flat` · `pro_rata_calendar_days` · `pro_rata_working_days` · `per_n_worked_days` · `formula` | |
| `accrual_amount_expr` | SPX → Days | e.g. `EL_ENTITLEMENT / 12` |
| `accrual_on` | Code | `period_start / period_end / joining_anniversary / nth_day_of_month` |
| `accrual_day` | Number | with `CLAMP_DAY_TO_MONTH` semantics |
| `accrual_rounding` | {mode, unit} | **mandatory**; e.g. `{half_up, 0.5}` |
| `accrues_during_unpaid_leave` | Boolean | |
| `accrues_during_probation` | Boolean | |
| `accrues_during_notice_period` | Boolean | |
| `accrues_during_suspension` | Boolean | |
| `min_days_worked_for_accrual` | Number | e.g. the "20 days worked in the month" convention some employers use — a configuration value, never a literal |
| `first_accrual_after_months` | Number | |
| `stop_accrual_at_balance` | Days | interacts with `max_balance` |

**Worked example.** Earned Leave, `frequency = monthly`,
`accrual_amount_expr = EL_ENTITLEMENT / 12`, `accrual_rounding = {half_up, 0.5}`,
`accrues_during_unpaid_leave = false`. Employee with 6 completed years →
`EL_ENTITLEMENT = 21d`; `21 / 12 = 1.75` → `ROUND(1.75, half_up, 0.5) = 2.0`. Twelve
monthly accruals of 2.0 would give 24, exceeding the entitlement — so:

**R-XIII-017** Accrual is **capped against the year's entitlement** by a declared
`annual_cap_behaviour` (`cap_at_entitlement | allow_overshoot | true_up_at_year_end`),
defaulting to `cap_at_entitlement`. With `cap_at_entitlement`, the twelfth accrual is
`21 − 11 × 2.0 = −1.0 → 0`, and the trace says "capped at annual entitlement 21.0". This
kind of arithmetic edge is exactly what a rounding policy without a cap produces, and it
must be declared rather than discovered.

### XIII.4.4 Joining and exit proration

**R-XIII-018** Proration is declared per accrual rule, separately for joining and exit:
`none | pro_rata_calendar | pro_rata_working | pro_rata_completed_months |
full_if_joined_before_day_N`, with a mandatory rounding policy.

```
-- pro_rata_completed_months, joining
accrued_first_year := ROUND( EL_ENTITLEMENT
                             × COMPLETED_MONTHS(employee.joining_date, leave_year_end)
                             / 12,
                             half_up, 0.5 )
```

**Worked example.** Joins 18-Aug-2027, leave year April–March, entitlement 15 days,
`pro_rata_completed_months`, rounding `{half_up, 0.5}`. Completed months from 18 Aug to
31 Mar = 7 (Sep–Mar). `15 × 7 / 12 = 8.75 → 9.0`. The trace shows the count of completed
months, the divisor, the rounding mode and the unit.

**R-XIII-019** Exit proration reduces the *entitlement*, and the difference between
entitlement-to-date and balance-consumed is settled by the F&F path: a positive residue is
encashed or lapses per policy; a negative residue (over-consumption) becomes a recovery
(`CREATE_RECOVERY`, Part VII §6.2) subject to a declared cap. *SmartPRS 2 has no exit
proration of any kind for leave.*

### XIII.4.5 Carry-forward

**R-XIII-020** A carry-forward rule declares:

| Field | Notes |
|---|---|
| `is_enabled` | |
| `max_days_expr` | SPX → Days; may be a formula, e.g. `MIN(balance, 30d)` |
| `max_percent` | alternative cap expressed as a percentage of entitlement |
| `min_balance_to_carry` | below which nothing carries |
| `carry_as_tranche` | Boolean — if true the carried amount is a dated tranche |
| `tranche_expires_after_months` | use-by window |
| `on_expiry` | `lapse | encash | convert_to(type)` |
| `carry_across_entity_transfer` | Boolean |
| `requires_approval`, `workflow_code` | for exceptional carry-forward above the cap |

**R-XIII-021** Carry-forward is executed as a **paired ledger transaction** —
`carry_forward_out` in the closing year and `carry_forward_in` in the opening year, equal
and opposite, both referencing the same rollover run id. The two must sum to zero across
the boundary; a rollover run asserts this and fails if it does not. *SmartPRS 2 has no
arithmetic at all here (DEF-LVE-03).*

**Worked example.** Earned Leave, closing balance 26.0, `max_days_expr = 15d`,
`carry_as_tranche = true`, `tranche_expires_after_months = 12`, `on_expiry = lapse`.
Rollover posts `carry_forward_out −26.0`, `carry_forward_in +15.0` (tranche expiring
31-Mar-2029), and `lapse −11.0`. The employee's statement shows all three lines with the
rule version that produced them.

### XIII.4.6 Lapse and expiry

**R-XIII-022** Lapse is a **scheduled, audited, idempotent job**, not an absence of data.
It writes a `lapse` transaction with the rule version, so "where did my 11 days go?" has an
answer. *SmartPRS 2's lapse is implicit and total: the next year's query simply returns no
prior-year rows (`E-…` §7 Q4).*

**R-XIII-023** Expiry applies to dated tranches (comp-off, carry-forward) and runs on the
tranche's `expires_on`. `on_expiry` may be `lapse`, `encash` (routing to §XIII.5) or
`convert_to(type)`.

**R-XIII-024** Both jobs are **re-runnable without double-posting**: they are keyed by
`(tenant, employee, type, rule_version, period_key)` with a unique constraint, so a retry
after a partial failure posts nothing twice. *Contrast `PointsController`'s auto-apply,
which achieves idempotency by deleting then re-inserting outside a transaction, so a
failure between the two loses the month (`H-…` NEW-PTS-104).*

### XIII.4.7 Negative balance

**R-XIII-025** `allow_negative_balance` with `negative_balance_limit` permits advance
leave. Crossing zero may require a higher approval step
(`negative_balance_workflow_code`). At exit, any negative balance becomes a recovery
(R-XIII-019). *SmartPRS 2 refuses outright at `:354` — and the refusal is trivially
bypassed by naming an unrecognised type (SEC-06), so the effective behaviour is
"unlimited negative balance for anyone who knows the trick".*

### XIII.4.8 The accrual ledger is a projection, not a report

**R-XIII-026** The employee's leave statement is rendered directly from ledger rows —
date, transaction type, units, running balance, the rule or formula version, and a link to
the trace. There is no second computation. *SmartPRS 2 publishes `ent − used` in one place
and enforces `ent − used − pending` in another and shows a third number in ESS
(DEF-LVE-04); one ledger makes divergence unrepresentable, satisfying `E-…`
requirement 49.*

---

## XIII.5 Encashment

**R-XIII-027** An encashment rule declares:

| Field | Notes |
|---|---|
| `is_enabled` | |
| `eligibility_expr` | SPX Boolean — service, grade, employment stage, exit reason |
| `trigger` | `on_request / at_year_end / at_exit / on_event` |
| `min_balance_after_encashment` | the balance the employee must retain |
| `max_days_per_event_expr`, `max_days_per_year_expr` | SPX → Days |
| `valuation_basis` | `basic / basic_plus_da / gross / ctc / fixed_day_rate / formula` |
| `day_rate_divisor` | `26 / 30 / calendar_days / working_days / declared` — **configuration, never a literal** |
| `valuation_formula_ref` | Part VIII formula when `basis = formula` |
| `rounding` | {mode, unit}, mandatory |
| `is_taxable`, `tax_head_ref`, `exemption_rule_ref` | flags only; the *values* are `<from rule pack>` |
| `pay_component_code` | the earning it becomes in payroll |
| `requires_approval`, `workflow_code` | |
| `settlement_path` | `regular_payroll / off_cycle_run / full_and_final` |

**R-XIII-028** Valuation uses the **formula engine** (Part VIII), in integer paise, with a
declared rounding mode and point (Law 7).

```
ENCASH_AMOUNT := ROUND_TO( ( salary.component('BASIC') + salary.component('DA') )
                           / policy.value('ENCASH_DIVISOR')
                           * encash_days,
                           half_up, ₹1 )
```

**Worked example.** Basic ₹28,000, DA ₹4,000, `ENCASH_DIVISOR = 26`, 12 days encashed.
`(2800000 + 400000) paise / 26 = 123076.923… paise per day`; exact rational is retained
until the explicit round; `× 12 = 1476923.0769… paise`;
`ROUND_TO(…, half_up, ₹1) = ₹14,769.00`. The trace shows the components, the divisor from
configuration, the exact intermediate and the rounding point. *SmartPRS 2 has no
encashment path at all; "Leave Encashment" is a bonus-module label with no link to any
balance (`AppController.php:3939`).*

**R-XIII-029** Encashment posts an `encashment` ledger transaction **and** a payroll input
in the same transaction, linked by id. A payroll run that pays an encashment whose ledger
row is missing, or vice versa, fails the run's reconciliation assertion (spine S11).

**R-XIII-030** Taxability is a **flag plus a rule-pack reference**. No exemption limit,
no rate and no section number is asserted in this blueprint (AUTHOR-BRIEF rule 1). The
statutory treatment of leave encashment at exit versus in service is rule-pack content with
`source_reference`.

---

## XIII.6 Day counting — how many days does this leave cost?

**R-XIII-031** Day counting is a **per-leave-type declaration**, evaluated against Part
XII's resolved day type. `count_basis`:

| Value | Counts |
|---|---|
| `calendar_days` | every date in the range |
| `working_days` | dates where `day_type ∈ working_day_set` (Part XII §XII.6.2) |
| `working_days_excluding_holidays` | as above, holidays never counted even if worked |
| `scheduled_days` | dates the employee was rostered to work |
| `adjacency_policy` | the count is produced by the Leave Adjacency Policy (§XIII.8) |

**R-XIII-032** **The number charged to the balance and the number credited to attendance
are the same number.** One computation, one result, consumed by both. A regression test
asserts equality for every type and every range shape. *SmartPRS 2 charges inclusive
calendar days and credits working days — a Fri–Mon leave costs 4 and credits 2
(DEF-LVE-06), and there is no test anywhere that would notice (TD-12).*

**R-XIII-033** The per-day result is a **list of `(date, units, day_part, payable_fraction)`
tuples**, not a scalar. The scalar is their sum. This is what makes half-days, boundary
splits, adjacency and partial cancellation all expressible without a second mechanism.

**Worked example — the canonical Friday-to-Monday case.**
Employee under Sat+Sun weekly off, applying Fri 04-Jun-2027 to Mon 07-Jun-2027.

| Date | Part XII day type | `calendar_days` | `working_days` | `adjacency_policy` (bridge on) |
|---|---|---|---|---|
| Fri 04 | working | 1.0 | 1.0 | 1.0 |
| Sat 05 | weekly_off | 1.0 | 0.0 | 1.0 |
| Sun 06 | weekly_off | 1.0 | 0.0 | 1.0 |
| Mon 07 | working | 1.0 | 1.0 | 1.0 |
| **Total** | | **4.0** | **2.0** | **4.0** |

All three are correct answers for *some* customer. §XIII.8 is how the customer chooses,
and the choice is one field.

---

## XIII.7 Application rules

Every item is a field on `leave_type_versions` or a typed child row, with a named reader.

### XIII.7.1 Quantity and shape

| Rule | Field | Notes |
|---|---|---|
| Maximum consecutive days | `max_consecutive_days` | per application and per unbroken run across applications |
| Minimum days per application | `min_days_per_application` | |
| Minimum unit | `min_unit` | §XIII.2.2 |
| Maximum applications per period | `max_applications_per_period`, `application_period` | |
| Maximum days per period | `max_days_per_period_expr` | SPX → Days |
| Maximum instances per period | `max_instances_per_period` | e.g. "casual leave may be taken at most 4 times a year" |
| Must be taken in whole blocks | `block_size_days` | e.g. sabbatical in 30-day blocks |

### XIII.7.2 Timing

| Rule | Field | Notes |
|---|---|---|
| Advance notice | `min_advance_notice_days`, `notice_basis` (`calendar / working`) | |
| Notice waiver | `notice_waiver_workflow_code` | a higher approval overrides the notice rule; the waiver is audited |
| Backdating window | `backdate_allowed`, `backdate_max_days`, `backdate_workflow_code` | |
| Backdating into a locked period | **always blocked** | reopen workflow required (R-XIII-034) |
| Future-dating window | `max_future_days` | how far ahead an application may be filed |
| Application cut-off relative to payroll | `apply_before_period_cutoff` | |

**R-XIII-034** A leave application whose dates fall inside a **locked** payroll or
attendance period is rejected with a named error, and may proceed only through the reopen
workflow or as an arrears adjustment in the next period (Part XI §XI.13, Part XIV).
*SmartPRS 2 accepts a backdated leave into a closed month with no check whatsoever and
counts it immediately (`E-…` §7 Q5.4).*

### XIII.7.3 Eligibility and stage

| Rule | Field |
|---|---|
| Probation | `available_during_probation` |
| Minimum service | `min_service_months`, or the general `eligibility_expr` |
| Notice period | `available_during_notice_period` |
| After resignation submitted | `available_after_resignation` (`yes / no / with_approval`) |
| Employment type / category / grade / gender | via `scope_json` and `eligibility_expr` |
| Confirmed employees only | `requires_confirmation` |

**R-XIII-035** Notice-period and resignation treatment are **per type**. A customer who
permits sick leave but not earned leave during notice sets two fields. *No such concept
exists in SmartPRS 2.*

### XIII.7.4 Combination, clubbing and restriction

| Rule | Field | Notes |
|---|---|---|
| Prefix restriction | `prefix_restriction` | may this type immediately follow a weekly off / holiday / another leave type? |
| Suffix restriction | `suffix_restriction` | may it immediately precede one? |
| Clubbing allowed with | `clubbable_with` List<Code> | which types may be applied contiguously |
| Clubbing forbidden with | `not_clubbable_with` List<Code> | |
| Maximum clubbed span | `max_clubbed_days` | |
| Restricted combinations | `restricted_combination_rules` | typed rows: "CL immediately after EL requires HR approval" |
| Repeat restriction | `min_gap_days_after_same_type` | |

**R-XIII-036** Clubbing and prefix/suffix rules are evaluated over the **resolved day
sequence**, not over application rows, so two separate applications that happen to be
contiguous are caught. *SmartPRS 2 has no clubbing concept at all (`E-…` §7 Q4).*

### XIII.7.5 Documents

| Rule | Field |
|---|---|
| Attachment required | `attachment_required` (`never / always / after_n_days / conditional`) |
| Threshold | `attachment_required_after_days` |
| Condition | `attachment_condition_expr` (SPX Boolean) |
| Document types accepted | `accepted_document_types` List<Code> |
| Medical certificate specifically | `requires_medical_certificate`, `medical_certificate_after_days` |
| Deadline to furnish | `document_due_days_after_return`, `on_document_overdue` (`convert_to_lop / escalate / block_next_application`) |
| Storage | private, authorised, signed expiring URL (spine S13) |

**R-XIII-037** "A medical certificate is required for sick leave over N days" is
`requires_medical_certificate = true` and `medical_certificate_after_days = N`. It is
Section 127-grade configuration, not code. *SmartPRS 2 has no leave upload route at all.*

**R-XIII-038** A medical certificate is **P3 personal data** (health), encrypted at the
application layer, masked by role, access-audited, and never included in a general export
(spine S13). *Contrast SmartPRS 2, where leave consumption per type — a sickness proxy —
is readable for any colleague by any authenticated user (SEC-07).*

### XIII.7.6 Availability, blackout, staffing and overlap

**R-XIII-039 — blackout periods.** A `leave_blackout_periods` row declares a date range, a
scope (spine S3.1), the leave types affected, and a severity: `block`, `warn`, or
`require_higher_approval` naming the workflow. Blackouts may recur (year-end close, a
festival sale period, a statutory filing window). *Absent entirely in SmartPRS 2 — grep for
`blackout` returns nothing.*

**R-XIII-040 — minimum staffing.** A `leave_staffing_rules` row declares a scope (team,
department, shift, branch, or an SPX-defined population), a minimum expressed as a headcount
or a percentage, an evaluation basis (`per_day`, `per_shift`), which day types count as
present, and a severity. It is evaluated for **every covered date** at application and again
at approval, and the shared evaluator is also used by the Part XII swap and substitution
paths (Part XII R-XII-018).

**R-XIII-041 — overlap limits.** `max_concurrent_on_leave` per scope, plus a team leave
calendar surfaced at approval showing who else is out on each covered date. The approval
screen must show the staffing verdict, not merely permit the approver to discover it later.
*Absent in SmartPRS 2, along with any team calendar (`E-…` §7 Q4).*

### XIII.7.7 Interaction with holidays and weekly offs

**R-XIII-042** Whether a weekly off or holiday inside a leave span is deducted is decided
by the **Leave Adjacency Policy** (§XIII.8) in conjunction with Part XII's collision fields
(Part XII §XII.5.2). There is exactly one place this is decided, and it is declared.

**R-XIII-043** A leave application may not **start or end** on a weekly off or holiday
unless `allow_boundary_on_non_working_day = true`. Default false, because an application
that starts on a Sunday is almost always a data-entry error — but it is a configuration
flag, since some customers deliberately book a whole calendar block.

### XIII.7.8 The application lifecycle

**R-XIII-044** Leave uses the **workflow engine (Part IX)**. There is no leave-specific
approver resolver, no leave-specific inbox, and no leave-specific decide endpoint.
*SmartPRS 2's leave has its own `currentEmployee()`, its own `resolveApprover()`, its own
`ensureCols()` and its own inbox (`E-…` §1.3, TD-05, NEW-LVE-108).*

**R-XIII-045** The state machine is explicit, over a value set (spine S16), with declared
transitions and guards:

```
draft → submitted → (pending_approval)* → approved → availed → closed
                                        → rejected
        submitted → withdrawn                       (by the requester, pre-decision)
        approved  → cancellation_requested → cancelled   (post-decision, policy-gated)
        approved  → partially_cancelled                  (remaining days stand)
        approved  → converted            (e.g. to LOP on document overdue)
        any       → expired                              (unactioned past its dates)
```

**R-XIII-046** A **decided step is immutable** (spine S8). Re-deciding is impossible, not
discouraged. *SmartPRS 2 has no pending-state guard at all: any manager can re-approve or
re-reject indefinitely, re-firing notifications and overwriting remarks with blank
(DEF-REQ-02, DEF-REQ-08).*

**R-XIII-047** Remarks are **append-only per transition**, never a single overwritable
column. *`LeaveController.php:422` writes `remarks` unconditionally, so a blank re-decide
erases the prior reason.*

**R-XIII-048** Cancellation and withdrawal are first-class transitions with **balance
restoration**, including cancellation of an already-approved future leave and **partial**
cancellation of a subset of days. Restoration posts a `consumption_reversal` referencing
the original consumption row and restores tranches in the reverse of the consumption order.
*SmartPRS 2 cannot store a fourth status: `leaves.status` is a three-value ENUM and
`ensureCols()` never widens it (DEF-LVE-12).*

**R-XIII-049** Submission notifies the approver. *`LeaveController.php:381-385` sends
nothing on submission while `RequestController::apply():570-594` does — "managers do not
know a leave request exists unless they open the inbox. In practice leave sits unapproved"
(DEF-NOT-01).*

---

## XIII.8 Half-day and hourly leave *(brief Section 28)*

### XIII.8.1 Units

**R-XIII-050** A leave type declares `min_unit ∈ {day, half_day, quarter_day, hour,
custom_hours}` and, for sub-day units, the mapping to a day fraction:

| `min_unit` | Unit value | Day fraction |
|---|---|---|
| `day` | 1.0 | 1.000 |
| `half_day` | 0.5 | 0.500 |
| `quarter_day` | 0.25 | 0.250 |
| `hour` | 1 hour | `1 / shift.full_day_hours` for the employee's resolved shift |
| `custom_hours` | `custom_unit_hours` | `custom_unit_hours / shift.full_day_hours` |

**R-XIII-051** Conversion between hours and days **never uses an implicit divisor**. The
day length comes from the employee's resolved shift for that date (Part XI §XI.8), or from
a declared `day_length_policy` where no shift is resolvable. SPX enforces this: `TO_DAYS`
and `TO_MINUTES` require an explicit `day_length` argument (Part VII §5.6), and `Days` and
`Hours` do not silently coerce (spine S5.1). *This eliminates the unit-confusion class that
SmartPRS 2 exhibits throughout, where absence is measured in working days and valued in
calendar days depending on a setting.*

### XIII.8.2 First half and second half

**R-XIII-052** `day_part ∈ {full, first_half, second_half}` is a property of **each day**
in the application, not of the application. A three-day leave may be
`second_half, full, first_half` — the standard Indian pattern for a two-and-a-half-day
absence around a weekend.

**R-XIII-053** The half boundary is derived from the resolved shift: `first_half` is
`[shift.start, shift.midpoint)` and `second_half` is `[shift.midpoint, shift.end)`, where
`midpoint` accounts for the declared unpaid break. A shift may override with an explicit
`half_day_split_time`. *SmartPRS 2's `half_day_hours` in `ShiftResolver`/`late_policy` is an
attendance concept — how many worked hours count as a half day — and is unrelated to leave
(`E-…` §7 Q4); the two must not be conflated, and in SmartPRS 3 they are separate declared
fields.*

### XIII.8.3 Hourly leave

**R-XIII-054** Hourly (short) leave declares `max_hours_per_instance`,
`max_instances_per_period`, `max_hours_per_period`, whether hours may straddle a break, and
the rounding of the requested duration (`{mode, unit}`, mandatory). Hourly leave is
recorded against the day with its start and end times so that attendance reconciliation is
exact to the minute.

### XIII.8.4 Reconciliation with attendance and payroll — exactly

**R-XIII-055** For every employee-day, the following identity holds and is **asserted**:

```
payable_fraction(day)
  = 1
  − Σ leave_units_on_day × (1 − leave_type.payable_fraction)
  − day_penalty_fraction(day)                       -- Part XI work-time policy
  − absence_fraction(day)
```

and

```
Σ over the period of (leave consumption ledger units for the period)
  = Σ over the period of (leave day-units recorded on employee_calendar_days)
```

**R-XIII-056** A payroll run asserts both identities per employee before producing a
payslip, and **fails the run** on a mismatch (spine S11's reconciliation discipline applied
to leave). *This is the mechanism that makes §XIII.1.1 structurally impossible to
reproduce: an unpaid leave type with `payable_fraction = 0` that failed to reduce
`payable_days` breaks the identity and stops the run, rather than quietly paying the
employee.*

**Worked reconciliation.** Employee on a `first_half` sick-leave day (paid, fraction 1.0)
who arrives 40 minutes late for the second half, under a policy charging 0.25 day for
lateness over 30 minutes:

```
leave units          0.5 (SL, first_half, paid, payable_fraction 1.0)
attendance penalty   0.25 (rule HYD-LATE-003 v4)
payable_fraction     = 1 − 0.5×(1−1.0) − 0.25 − 0
                     = 0.75
worked/leave record  0.5 leave + 0.25 worked + 0.25 penalty = 1.0 day accounted
```

Both identities hold. The payslip line, the muster roll and the leave statement all show
the same 0.5.

**Worked reconciliation — the LOP case that SmartPRS 2 gets wrong.** Employee takes one
full day of `LOP` (`is_paid = false`, `payable_fraction_expr = 0`):

```
leave units          1.0 (LOP)
payable_fraction     = 1 − 1.0×(1−0) − 0 − 0 = 0.000
payable_days         reduced by exactly 1.0
LOP days             1.0
```

*In SmartPRS 2 the same input produces `payable_fraction = 1` and a full day's pay, because
`type_id` is NULL and payroll's `paid` test is always true (DEF-LVE-02). Here the identity
would fail before a payslip is produced.*

---

## XIII.9 The Sandwich Leave Engine — a Leave Adjacency Policy *(brief Section 29)*

### XIII.9.1 Why it is not a boolean

SmartPRS 2 has one global setting, `sandwich_rule`, default `0/off`
(`AppController.php:12179`), implemented as a single fixed algorithm in
`PayrollGenController::sandwichDays():770-813`. It:

- operates on **attendance coverage**, not on leave-balance deduction;
- offers no choice of which off-day types are bridged, how many bounding absences are
  required, or whether leave breaks the chain (`B-attendance.md` Q4);
- uses `paidLeaveDates()`, which is broken by DEF-LVE-02, so **it currently treats LOP
  leave as coverage and will not fire across it**;
- indexes day-of-month within `$start`'s month, so it is wrong under a cut-off period
  (DEFECT-B-32).

A single boolean cannot express what customers actually ask for. The design below is a
**policy artefact per leave type**, and it is the direct answer to `E-…` requirement 33 and
`B-attendance.md` requirement 23.

### XIII.9.2 The policy

```
leave_adjacency_policy {
  code, name
  leave_type_codes            List<Code>   -- which types this policy governs; ALL permitted
  scope_json, priority, effective_from/to  -- spine S3.1 / S4

  -- WHAT MAY BE BRIDGED
  bridgeable_day_types        List<Code>   -- weekly_off · holiday · optional_holiday
                                           -- · weekly_off_and_holiday · comp_off_day
                                           -- · restricted_holiday
  bridge_direction            Code         -- both_sides_required
                                           -- | either_side_sufficient
                                           -- | leading_only | trailing_only

  -- HOW MUCH BOUNDING IS NEEDED
  min_leave_days_before       Number       -- days of qualifying leave immediately before
  min_leave_days_after        Number       -- and after
  min_total_leave_days        Number       -- the application must be at least this long
  max_bridged_span_days       Number       -- never bridge more than N consecutive off-days
  count_half_day_as_bounding  Boolean

  -- WHAT BREAKS THE CHAIN
  presence_breaks_chain       Boolean      -- a worked day between leave days stops it
  approved_absence_breaks     Boolean
  different_leave_type_breaks Boolean
  paid_leave_breaks           Boolean      -- some customers bridge only across unpaid
  holiday_breaks_weekly_off_chain Boolean

  -- WHAT HAPPENS WHEN IT FIRES
  effect                      Code         -- deduct_from_balance
                                           -- | mark_lop
                                           -- | mark_lop_only_if_unpaid_leave
                                           -- | no_deduction_but_flag
  bridged_day_leave_type      Code         -- which type the bridged day is charged to
  bridged_day_payable_fraction Number

  -- CARVE-OUTS  (brief S29)
  exempt_leave_type_codes     List<Code>   -- statutory leave never sandwiched
  exempt_if_statutory         Boolean      -- shorthand for leave.is_statutory = TRUE
  medical_exception           Boolean      -- exempt when a medical certificate is on file
  medical_exception_expr      SPX Boolean  -- e.g. hospitalisation flag
  exempt_scope_json           Scope        -- e.g. never for grade M4+
  requires_approval_to_waive  Boolean
  waiver_workflow_code        Code
}
```

**R-XIII-057** The adjacency policy governs **balance deduction**, and separately declares
its payroll effect. It is not an attendance-side afterthought. *SmartPRS 2's version exists
only in payroll and never touches the balance (`E-…` §7 Q4).*

**R-XIII-058** `exempt_if_statutory = true` is the default for every seeded policy, and
statutory leave types (§XIII.10) carry `is_statutory = true`. Maternity, adoption and
similar protected leave is therefore not sandwiched unless a tenant explicitly overrides,
and that override is itself blocked or warned by the rule pack's declared severity.

**R-XIII-059** The policy resolves per leave type by spine S3.2 specificity, so
"sandwich casual leave but not sick leave, except for the Chennai branch" is three
artefacts and no code.

### XIII.9.3 The worked example — same input, different customers

**Input, identical in every case:** employee under a Sat+Sun weekly off applies for
**Friday 04-Jun-2027** and **Monday 07-Jun-2027** — two separate leave days, with the
weekend between them.

| Customer policy | Configuration | Days deducted | Which days |
|---|---|---|---|
| **A — no sandwich** | no adjacency policy, `count_basis = working_days` | **2.0** | Fri 1.0, Mon 1.0 |
| **B — classic sandwich** | `bridgeable_day_types = [weekly_off]`, `bridge_direction = both_sides_required`, `min_leave_days_before = 1`, `min_leave_days_after = 1`, `effect = deduct_from_balance` | **4.0** | Fri 1.0, Sat 1.0, Sun 1.0, Mon 1.0 |
| **C — sandwich, but as LOP not balance** | as B with `effect = mark_lop`, `bridged_day_leave_type = LOP` | **2.0 from balance + 2.0 LOP** | Fri/Mon from CL balance; Sat/Sun as LOP days |
| **D — sandwich only across unpaid leave** | as B plus `paid_leave_breaks = true` | **2.0** | the leave is paid CL, so the chain breaks; weekend not bridged |
| **E — sandwich only if the leave is longer** | as B plus `min_total_leave_days = 3` | **2.0** | the application totals 2 days, below the threshold |
| **F — one-sided sandwich (trailing)** | `bridge_direction = trailing_only`, `min_leave_days_before = 1` | **3.0** | Fri 1.0, Sat 1.0, Sun 1.0 — Monday's leave is not required to trigger the bridge, but Monday itself is still 1.0, giving 4.0 in this instance; with only Friday taken it would be 3.0 |
| **G — sandwich with a medical exemption** | as B plus `medical_exception = true`; a certificate is on file | **2.0** | the exemption fires; the trace names the certificate |
| **H — calendar-day counting, no policy needed** | `count_basis = calendar_days` | **4.0** | every date counted; this is SmartPRS 2's *unconditional* behaviour, available here as a deliberate choice |

**R-XIII-060** Cases A, B, C, D, E, G and H are the **same input** producing seven
different, correct answers **purely by configuration**. No code path differs. This is the
Section 127 acceptance test for this part.

### XIII.9.4 SPX form

The engine evaluates the policy declaratively, but the same decision is expressible and
therefore simulable:

```
-- Bridge a weekly off when qualifying leave sits on both sides
when:
  day.is_weekly_off = TRUE
  AND day.is_holiday = FALSE
  AND NOT IS_NULL( leave.on_date( PREV_WORKING_DAY(day.date) ) )
  AND NOT IS_NULL( leave.on_date( NEXT_WORKING_DAY(day.date) ) )
  AND leave.on_date( PREV_WORKING_DAY(day.date) )
      IN policy.list('SANDWICH_QUALIFYING_TYPES')
  AND leave.on_date( NEXT_WORKING_DAY(day.date) )
      IN policy.list('SANDWICH_QUALIFYING_TYPES')

except:
  leave.is_statutory = TRUE
  OR ( policy.value('MEDICAL_EXCEPTION') = TRUE
       AND compliance.has_document('MEDICAL_CERTIFICATE', leave.from, leave.to) )

then:
  DEDUCT( type: policy.code('SANDWICH_BRIDGED_TYPE'), units: 1d )
  WRITE_AUDIT('sandwich_bridge_applied')
```

`LEAVE_DAYS_IN_RANGE` and `leave.on_date` exist in the Part VII catalogue precisely for
this rule (Part VII §5.6, §5.7 — the latter marked ★ *"needed by the sandwich rule to test
individual bounding days"*).

---

## XIII.10 The Comp-Off Engine *(brief Section 30)*

### XIII.10.1 A ledger with two sides

**R-XIII-061** Comp-off is a **ledger**, not a leave type with a quota. The earned side
credits dated tranches; the used side consumes them through the ordinary leave application
path against a leave type carrying `is_comp_off = true`. *SmartPRS 2 has a type named
"Comp-Off" in two hard-coded fallback lists with `days_per_year = 0` and **no earning
mechanism anywhere** — grep for `comp.off|compoff` finds no logic (`E-…` §7 Q4;
`B-attendance.md` Q4).*

### XIII.10.2 The earned side

**R-XIII-062** A comp-off credit rule declares:

| Field | Notes |
|---|---|
| `code`, `name`, `scope_json`, `priority`, effective range | spine S3/S4 |
| `trigger` | `worked_on_weekly_off` · `worked_on_holiday` · `worked_on_optional_holiday` · `worked_beyond_hours` · `worked_on_rest_day` · `on_event` |
| `min_worked_minutes` | qualifying threshold |
| `credit_expr` | SPX → Days, e.g. `IF(worked >= full_day, 1d, 0.5d)` |
| `credit_rounding` | {mode, unit}, mandatory |
| `max_credits_per_period`, `max_balance` | caps |
| `expiry_days` / `expiry_expr` | tranche life |
| `on_expiry` | `lapse / encash / convert_to(type)` |
| `requires_approval`, `workflow_code` | approval of the *credit*, distinct from approval of the *work* |
| `eligible_employment_types`, `eligibility_expr` | |
| `stacks_with_overtime` | whether the same minutes may also produce OT |
| `choice_mode` | `system / employee / hr` — see R-XIII-064 |
| `choice_window_days` | how long the choice stays open |

**R-XIII-063** `stacks_with_overtime = false` is the default and is **enforced**: the same
worked minutes cannot produce both a comp-off credit and paid overtime. A run asserts that
no minute is settled twice, naming the settlement id if it is.

**R-XIII-064 — the three-way choice.** Where `work_on_off_outcome = employee_choice` or
`hr_choice` (Part XII §XII.3.6), the employee or HR selects among the enabled outcomes —
**comp-off, overtime, or additional pay** — within `choice_window_days`. The choice is a
workflow decision, audited, and **irreversible once settled into a locked period**. If no
choice is made in the window, the declared `default_outcome` applies and the employee is
notified that the default was applied and why.

**R-XIII-065** A comp-off credit is created by the `CREATE_COMP_OFF(units, expiry_ref)`
action (Part VII §6.2) inside the calling service's transaction, together with the
`work_on_off_settlements` row (Part XII §XII.7.9). The two are created or neither is.

### XIII.10.3 The used side

**R-XIII-066** Comp-off is consumed through the normal leave application path, so it
inherits approval, blackout, staffing, adjacency, half-day and cancellation behaviour for
free. Consumption allocates across tranches by `consumption_order`, defaulting to
`expiring_first` (R-XIII-011), and the consumption row records which tranches it drew from.

**R-XIII-067** Comp-off consumption may declare `must_be_used_within_days_of_credit`
independently of tranche expiry (a customer who requires the comp-off be taken within the
same month as it was earned), and `min_notice_days` distinct from other types.

### XIII.10.4 Expiry and encashment

**R-XIII-068** Tranche expiry runs as the scheduled, idempotent job of R-XIII-023/024 and
posts an `expiry` transaction. Employees receive a configurable reminder N days before a
tranche expires. Where `on_expiry = encash`, the expiry routes to §XIII.5 and produces a
payroll input rather than a silent loss.

---

## XIII.11 Statutory and protected leave *(brief Section 49)*

### XIII.11.1 The discipline first

**R-XIII-069** **No duration, eligibility threshold, headcount trigger, wage condition,
notice requirement or benefit value in this section is asserted in this blueprint.** Every
one is `<from rule pack>` — content published by Ametecs under spine S12, signed, versioned,
carrying a `source_reference` traceable to its statutory authority, and verified by the
named compliance owner (spine S12, D-03) before shipping. This part specifies **the
mechanism**, not the law.

**R-XIII-070** Protected leave types are marked `is_statutory = true` and
`statutory_ref = <rule pack rule code>`. They participate in every mechanism in this part —
ledger, application rules, workflow, explainability — and are additionally subject to the
protections in §XIII.11.3.

### XIII.11.2 The protected families the platform models

Each row is a modelled capability with configuration fields. **The values are rule-pack
content.**

| Family | Modelled as | Configuration surface (values `<from rule pack>`) |
|---|---|---|
| **Maternity leave** | a statutory leave type with an event-based entitlement | duration, split between pre- and post-delivery, eligibility service condition, applicability by establishment headcount, number-of-surviving-children condition, wage basis during leave, notice and document requirements |
| **Adoption leave** | as above, event = adoption order | duration, child-age condition, eligibility |
| **Commissioning mother** | as above, event = surrogacy commissioning | duration, eligibility |
| **Miscarriage / stillbirth leave** | event-based entitlement, document-gated | duration, document type, timing relative to the event |
| **Medical termination of pregnancy** | event-based entitlement, document-gated | duration, document type |
| **Tubectomy / sterilisation leave** | event-based entitlement, document-gated | duration, document type |
| **Illness arising out of the above** | an additional, separately-capped event entitlement | duration, certificate requirement |
| **Nursing / crèche breaks** | **not leave** — a paid in-shift break entitlement modelled in the shift/attendance layer (Part XI) and consumed as `break_minutes` that do not count against the break budget | number of breaks per day, duration per break, the period after the child's birth for which they apply, whether travel time is included |
| **Crèche applicability** | a compliance obligation, not leave — an establishment-level flag driving a compliance item (Part XVII) | headcount trigger, facility requirements, visit entitlement |
| **Work from home after maternity** | a declared post-leave arrangement: a `work_from_home` day type (Part XII §XII.5.3) granted for a period, agreed between employer and employee | eligibility, maximum duration, whether it requires mutual agreement, whether it is subject to the nature of the work |
| **Paternity leave** | a leave type; **statutory or company-policy depending on jurisdiction and sector** | duration, eligibility, window relative to the birth, number of instances |
| **Adoption/paternity for the second parent** | as above | duration, eligibility |

**R-XIII-071** Event-based entitlements (`entitlement_basis = per_event`) are granted
against a **declared event** — expected date of delivery, actual date of delivery, adoption
order date, procedure date — recorded on the application with its supporting document. The
entitlement is not an annual quota and does not lapse at the leave-year boundary; it is
consumed against the event.

**R-XIII-072** Where a statute conditions an entitlement on establishment headcount, the
condition is evaluated against the **establishment as at the event date**, from the
effective-dated org model (spine S3), and the resolved verdict is stored on the entitlement
so it does not change if headcount later changes.

**R-XIII-073** Nursing breaks are modelled in the attendance layer and **not** as a leave
type, because they are paid in-shift time, not an absence. They reduce neither the payable
day nor any leave balance, and they are excluded from the break budget by a declared flag
(Part XI §XI.10). Modelling them as leave would make them deductible, which is the wrong
default.

### XIII.11.3 The floor rule

**R-XIII-074** **A platform statutory baseline cannot be silently reduced by company HR.
An employer may enhance it.**

Concretely, at publish time the configuration validator compares the tenant's leave type
version against the resolved rule pack baseline for its `statutory_ref` on every declared
dimension (duration, eligibility, wage treatment, notice, document burden, protection
rules) and:

| Comparison | Outcome |
|---|---|
| Tenant value is **more generous** than the baseline | Published. Recorded as an enhancement in the compliance record |
| Tenant value **equals** the baseline | Published |
| Tenant value is **less generous** and the pack declares severity `block` | **Publication blocked**, naming the dimension, the baseline value, the tenant value and the pack version |
| Tenant value is **less generous** and the pack declares severity `warn` | Published only with an explicit, named, audited **compliance exception** recorded against the tenant, with an owner and a reason (spine S12) |

**R-XIII-075** "More generous" is a **declared direction per dimension** in the rule pack
(`higher_is_better` / `lower_is_better` / `not_comparable`), never inferred. A dimension
declared `not_comparable` always requires an explicit compliance exception to diverge.

**R-XIII-076** A rule-pack activation that raises a baseline above a tenant's current
configuration raises a **compliance item** (Part XVII) with a due date, rather than
silently rewriting the tenant's configuration. Statutory content never edits tenant
configuration.

**R-XIII-077** Protected leave carries additional declared protections evaluated as rules,
not as code: the leave may not be refused where the eligibility condition is met; the
employment may not be terminated during the protected window without the declared workflow;
the employee's return is to the same or an equivalent position; and adjacency (§XIII.9)
does not apply (R-XIII-058). Each protection is a rule with a `block | warn` severity from
the pack.

**R-XIII-078** Protected-leave records and their supporting documents are **P3 personal
data** (health and family status): encrypted at the application layer, masked by role,
access-audited, excluded from general exports and from the team leave calendar (which shows
"on leave" without the type unless the viewer holds an explicit permission).

---

## XIII.12 Balance integrity

This section is the direct, itemised answer to the eight over-draw modes at §XIII.1.4.

**R-XIII-079 — reservation on submission.** Submitting an application posts a
`reservation` for the computed units, inside a transaction, after a `SELECT … FOR UPDATE`
on the employee-type balance row. `available = balance − reservations` is what the
application is checked against. *Closes mode 1 (concurrency) and mode 2 (no reservation).*

**R-XIII-080 — re-check and post at approval.** Approval **re-evaluates** eligibility and
availability against the state at approval time and converts the reservation into a
`consumption` in the same transaction. Two individually-valid pending applications cannot
both be approved past the balance; the second is refused at approval with a named error.
*Closes mode 2.*

**R-XIII-081 — idempotent approval.** The approval transition carries an idempotency key
`(workflow_instance_id, step_id, decision)` with a unique constraint, and the workflow
step is immutable once decided (spine S8, R-XIII-046). A duplicate approval — from a double
click, a retried webhook, or a re-fired job — posts **nothing**. *Closes mode 3.*

**R-XIII-082 — a double deduction is structurally impossible.** Three independent
mechanisms:
1. `leave_ledger` carries a unique constraint on
   `(tenant_id, employee_id, source_type, source_id, transaction_type)`, so the same
   application can never produce two `consumption` rows.
2. `employee_calendar_days` carries a unique current row per `(employee, date)` (Part XII
   §XII.7.6), so the same date cannot carry two approved leaves of any type; a second
   approval covering an already-covered date is refused at approval, naming the conflicting
   application.
3. The period reconciliation identity (R-XIII-055) is asserted before any payslip is
   produced; a duplicate consumption breaks it and stops the run.

**R-XIII-083 — backdating and locked periods.** R-XIII-034. *Closes mode 4.*

**R-XIII-084 — year attribution.** R-XIII-013: consumption is attributed day by day.
*Closes mode 5.*

**R-XIII-085 — no free-text type.** The application references
`leave_type_version_id`, a foreign key. There is no string type field and no fallback
bucket. A request naming an unknown type is a **422 with a named error**, never a silent
skip of the balance check. *Closes mode 6 — SEC-06, where a trailing space grants unlimited
leave.*

**R-XIII-086 — rename safety.** R-XIII-002 and the FK. *Closes mode 7.*

**R-XIII-087 — one published number.** The ledger is the only source; ESS, the leave
module, the manager view, the report and the payslip all call
`LeaveBalanceService::statement()`. A second computation anywhere is a build failure.
*Closes mode 8 — DEF-LVE-04, satisfying `E-…` requirement 49.*

**R-XIII-088 — tenant scoping is structural.** Every balance and ledger query is scoped by
the persistence layer (Law 6). *`LeaveController.php:212-215` and `EssController.php:205-206`
filter on `employee_id` alone (NEW-LVE-102).*

**R-XIII-089 — the nightly integrity sweep.** A scheduled job recomputes
`Σ ledger` against the materialised balance snapshot and against
`employee_calendar_days` for a rolling window, and raises an **operator alert** on any
divergence, naming employee, type and delta. A silent divergence is not permitted to
persist to a payroll run.

---

## XIII.13 Data model

Section 123 standard; conventions per spine S16. Legend as in Part XII.

### XIII.13.1 `leave_types`

*Purpose:* type identity. Behaviour lives in versions.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | N | auto | PK |
| `uuid` | char(36) | N | — | UUIDv7 |
| `tenant_id` | bigint unsigned | N | — | FK → `tenants.id` ON DELETE RESTRICT |
| `code` | varchar(64) | N | — | **immutable**; the only thing behaviour may reference |
| `is_system` | tinyint(1) | N | 0 | seeded types cannot be deleted, only deactivated |
| `status` | varchar(24) | N | `active` | value set |
| audit columns, `deleted_at` | | | | |

PK `id` · Unique `(tenant_id, code)` · Index `(tenant_id, status)`.
Hist: versions. SD: yes. Audit: create/deactivate. PII: none.
**Note the absence of a `name` column** — the display name lives on the version, so a
rename is a version, and history keeps the name it was called at the time.

### XIII.13.2 `leave_type_versions`

*Purpose:* the effective-dated, scoped, publishable definition. ~70 declared columns; the
groups are §XIII.2.2.

Version envelope (spine S4): `id, tenant_id, leave_type_id, version_no, status, scope_json,
specificity_weight, priority, effective_from, effective_to, created_by, created_at,
submitted_by, approved_by, approved_at, published_at, superseded_by_version_id, reason,
change_note, checksum`.

Selected declared columns (all `NOT NULL` unless noted):

`name varchar(160)` · `short_name varchar(24)` · `description varchar(500) NULL` ·
`display_colour varchar(16) NULL` · `display_order smallint` ·
`is_visible_to_employee tinyint(1) DEFAULT 1` · `payslip_display varchar(24) DEFAULT 'count_only'` ·
`payslip_label_key varchar(64) NULL` ·
`is_paid tinyint(1)` · `payable_fraction_expr text NULL` + `payable_fraction_ast json NULL` ·
`is_statutory tinyint(1) DEFAULT 0` · `statutory_ref varchar(120) NULL` ·
`is_encashable tinyint(1) DEFAULT 0` · `is_carry_forwardable tinyint(1) DEFAULT 0` ·
`is_lop tinyint(1) DEFAULT 0` · `is_comp_off tinyint(1) DEFAULT 0` ·
`lop_substitute_type_code varchar(64) NULL` ·
`counts_towards_service tinyint(1) DEFAULT 1` · `counts_towards_accrual tinyint(1) DEFAULT 1` ·
`affects_attendance_status varchar(40)` ·
`gender_applicability json NULL` · `is_maternity_family tinyint(1) DEFAULT 0` ·
`entitlement_expr text NULL` + `entitlement_ast json NULL` · `entitlement_basis varchar(32)` ·
`leave_year_basis varchar(32)` · `leave_year_start_month tinyint NULL` ·
`min_unit varchar(24) DEFAULT 'day'` · `custom_unit_hours decimal(5,2) NULL` ·
`unit_rounding_mode varchar(16)` · `unit_rounding_unit decimal(6,4)` ·
`max_balance decimal(6,2) NULL` · `allow_negative_balance tinyint(1) DEFAULT 0` ·
`negative_balance_limit decimal(6,2) NULL` · `negative_balance_workflow_code varchar(64) NULL` ·
`eligibility_expr text NULL` + `eligibility_ast json NULL` · `min_service_months smallint DEFAULT 0` ·
`available_during_probation tinyint(1) DEFAULT 0` · `available_during_notice_period tinyint(1) DEFAULT 1` ·
`available_after_resignation varchar(24) DEFAULT 'yes'` · `requires_confirmation tinyint(1) DEFAULT 0` ·
`count_basis varchar(40) DEFAULT 'working_days'` ·
`max_consecutive_days smallint NULL` · `min_days_per_application decimal(4,2) DEFAULT 0.5` ·
`max_applications_per_period smallint NULL` · `application_period varchar(24) NULL` ·
`max_days_per_period_expr text NULL` · `max_instances_per_period smallint NULL` ·
`block_size_days smallint NULL` ·
`min_advance_notice_days smallint DEFAULT 0` · `notice_basis varchar(16) DEFAULT 'calendar'` ·
`notice_waiver_workflow_code varchar(64) NULL` ·
`backdate_allowed tinyint(1) DEFAULT 0` · `backdate_max_days smallint DEFAULT 0` ·
`backdate_workflow_code varchar(64) NULL` · `max_future_days smallint NULL` ·
`prefix_restriction varchar(40) DEFAULT 'none'` · `suffix_restriction varchar(40) DEFAULT 'none'` ·
`clubbable_with json NULL` · `not_clubbable_with json NULL` · `max_clubbed_days smallint NULL` ·
`min_gap_days_after_same_type smallint DEFAULT 0` ·
`allow_boundary_on_non_working_day tinyint(1) DEFAULT 0` ·
`attachment_required varchar(24) DEFAULT 'never'` · `attachment_required_after_days smallint NULL` ·
`attachment_condition_expr text NULL` · `accepted_document_types json NULL` ·
`requires_medical_certificate tinyint(1) DEFAULT 0` · `medical_certificate_after_days smallint NULL` ·
`document_due_days_after_return smallint NULL` · `on_document_overdue varchar(40) DEFAULT 'escalate'` ·
`workflow_code varchar(64)` · `cancellation_policy varchar(40) DEFAULT 'allowed_before_start'` ·
`withdrawal_policy varchar(40) DEFAULT 'allowed_before_decision'` ·
`half_day_split_time time NULL` · `max_hours_per_instance decimal(4,2) NULL` ·
`max_hours_per_period decimal(6,2) NULL` ·
`consumption_order varchar(24) DEFAULT 'expiring_first'` ·
`annual_cap_behaviour varchar(32) DEFAULT 'cap_at_entitlement'`.

PK `id` · Unique `(tenant_id, leave_type_id, version_no)` ·
Index `(tenant_id, status, effective_from, effective_to)` ·
Index `(tenant_id, specificity_weight DESC, priority DESC)`.
Overlap within `(leave_type_id, scope)` rejected at publish. Hist: append-only. SD: no.
Audit: full field-level (spine S14). PII: none (definition only).

**R-XIII-090** `is_paid`, `is_statutory`, `is_comp_off`, `is_lop` and every other flag are
**declared on the version**, so "was Sick Leave paid in March 2027?" is answerable. *SmartPRS 2
edits `leave_types.paid` in place.*

### XIII.13.3 `leave_accrual_rules`

`id, tenant_id, leave_type_id, version_no, status, scope_json, specificity_weight, priority,
effective_from, effective_to, frequency varchar(40), accrual_basis varchar(40),
accrual_amount_expr text, accrual_amount_ast json, accrual_on varchar(32), accrual_day tinyint,
accrual_rounding_mode varchar(16), accrual_rounding_unit decimal(6,4),
accrues_during_unpaid_leave tinyint(1), accrues_during_probation tinyint(1),
accrues_during_notice_period tinyint(1), accrues_during_suspension tinyint(1),
min_days_worked_for_accrual smallint, first_accrual_after_months smallint,
stop_accrual_at_balance decimal(6,2) NULL, annual_cap_behaviour varchar(32),
joining_proration varchar(48), exit_proration varchar(48),
proration_rounding_mode varchar(16), proration_rounding_unit decimal(6,4),
approval columns, checksum`.
Unique `(tenant_id, leave_type_id, version_no)`. Audit: full. PII: none.

### XIII.13.4 `leave_carry_forward_rules`

`id, tenant_id, leave_type_id, version_no, status, scope_json, priority, effective_from,
effective_to, is_enabled, max_days_expr text, max_days_ast json, max_percent decimal(5,2) NULL,
min_balance_to_carry decimal(6,2), carry_as_tranche tinyint(1),
tranche_expires_after_months smallint NULL, on_expiry varchar(32),
carry_across_entity_transfer tinyint(1), requires_approval tinyint(1),
workflow_code varchar(64) NULL, approval columns`.
Unique `(tenant_id, leave_type_id, version_no)`.

### XIII.13.5 `leave_encashment_rules`

`id, tenant_id, leave_type_id, version_no, status, scope_json, priority, effective_from,
effective_to, is_enabled, eligibility_expr text, eligibility_ast json, trigger varchar(32),
min_balance_after decimal(6,2), max_days_per_event_expr text, max_days_per_year_expr text,
valuation_basis varchar(32), day_rate_divisor varchar(32), valuation_formula_ref varchar(64) NULL,
rounding_mode varchar(16), rounding_unit decimal(6,4), is_taxable tinyint(1),
tax_head_ref varchar(64) NULL, exemption_rule_ref varchar(64) NULL,
pay_component_code varchar(64), requires_approval tinyint(1), workflow_code varchar(64) NULL,
settlement_path varchar(32), approval columns`.
Unique `(tenant_id, leave_type_id, version_no)`.

### XIII.13.6 `leave_adjacency_policies`

The §XIII.9.2 structure, as a versioned artefact:
`id, uuid, tenant_id, code, version_no, status, scope_json, specificity_weight, priority,
effective_from, effective_to, leave_type_codes json, bridgeable_day_types json,
bridge_direction varchar(40), min_leave_days_before decimal(4,2),
min_leave_days_after decimal(4,2), min_total_leave_days decimal(4,2),
max_bridged_span_days smallint, count_half_day_as_bounding tinyint(1),
presence_breaks_chain tinyint(1), approved_absence_breaks tinyint(1),
different_leave_type_breaks tinyint(1), paid_leave_breaks tinyint(1),
holiday_breaks_weekly_off_chain tinyint(1), effect varchar(48),
bridged_day_leave_type_code varchar(64) NULL, bridged_day_payable_fraction decimal(4,3),
exempt_leave_type_codes json, exempt_if_statutory tinyint(1) DEFAULT 1,
medical_exception tinyint(1), medical_exception_expr text NULL, exempt_scope_json json,
requires_approval_to_waive tinyint(1), waiver_workflow_code varchar(64) NULL,
approval columns, checksum`.
Unique `(tenant_id, code, version_no)` · Index `(tenant_id, status, effective_from)`.

### XIII.13.7 `leave_applications`

*Purpose:* the request header. **The name is deliberate**: SmartPRS 2 ships a
`LeaveApplication` model whose table does not exist and a migration named
`create_leave_applications_table` that creates four statutory tables instead (DEF-LVE-01).
SmartPRS 3 makes the name mean what it says.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | N | auto | PK |
| `uuid` | char(36) | N | — | public id; **URLs never carry the integer** |
| `tenant_id` | bigint unsigned | N | — | FK |
| `employee_id` | bigint unsigned | N | — | FK ON DELETE RESTRICT |
| `leave_type_id` | bigint unsigned | N | — | FK → `leave_types.id` |
| `leave_type_version_id` | bigint unsigned | N | — | FK → `leave_type_versions.id` — **the version that governed** |
| `from_date`, `to_date` | date | N | — | |
| `from_day_part`, `to_day_part` | varchar(16) | N | `full` | |
| `total_units` | decimal(6,2) | N | — | the single number; balance and attendance both use it |
| `unit_kind` | varchar(16) | N | `days` | `days / hours` |
| `total_hours` | decimal(6,2) | Y | NULL | when hourly |
| `hourly_from`, `hourly_to` | time | Y | NULL | |
| `event_type`, `event_date` | varchar(40) / date | Y | NULL | statutory event-based leave (R-XIII-071) |
| `reason` | varchar(1000) | Y | NULL | |
| `status` | varchar(32) | N | `draft` | value set, **not** a database ENUM |
| `workflow_instance_id` | bigint unsigned | Y | NULL | FK → workflow |
| `adjacency_policy_version_id` | bigint unsigned | Y | NULL | FK; which policy shaped the day list |
| `applied_by_user_id`, `applied_at` | | N | — | |
| `decided_at` | datetime | Y | NULL | |
| `cancelled_at`, `cancellation_reason` | | Y | NULL | |
| `converted_to_application_id` | bigint unsigned | Y | NULL | LOP conversion chain |
| `period_lock_id` | bigint unsigned | Y | NULL | set when settled into a locked period |
| `trace_id` | char(36) | Y | NULL | |
| audit columns, `deleted_at` | | | | soft delete for drafts only |

PK `id` · Unique `(tenant_id, uuid)` ·
Index `(tenant_id, employee_id, from_date)` ·
Index `(tenant_id, status, from_date)` ·
**Index `(tenant_id, workflow_instance_id)`** ·
Index `(tenant_id, leave_type_id, from_date)`.
*SmartPRS 2's `leaves` has no index on `status` or `approver_id` at all (TD-04), making every
inbox load a full scan.*
Hist: transitions in the audit ledger + workflow instance. SD: drafts only. Audit: full.
PII: **P3** — `reason` may carry health detail; masked by role, excluded from general
exports.

### XIII.13.8 `leave_application_days`

*Purpose:* the per-day expansion. This is what makes half-days, splits, adjacency and
partial cancellation representable (R-XIII-033).

`id, tenant_id, application_id (FK ON DELETE CASCADE), employee_id, leave_date,
day_part varchar(16), units decimal(4,2), payable_fraction decimal(4,3),
resolved_day_type varchar(40), is_bridged tinyint(1) DEFAULT 0,
bridged_by_policy_version_id bigint unsigned NULL, leave_year_key varchar(16),
charged_to_type_id bigint unsigned, tranche_allocations json NULL,
status varchar(32), cancelled_at datetime NULL, generation int, is_current tinyint(1)`.

PK `id` ·
**Unique `(tenant_id, employee_id, leave_date, generation)` WHERE `is_current = 1` AND
`status IN ('reserved','approved')`** — the constraint that makes two approved leaves on
one date impossible (R-XIII-082.2) ·
Index `(tenant_id, application_id)` · Index `(tenant_id, leave_date)` ·
Index `(tenant_id, employee_id, leave_year_key, charged_to_type_id)`.
Audit: full. PII: **P2** (type on a date).

### XIII.13.9 `leave_ledger`

*Purpose:* the balance. Append-only.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | N | auto | PK |
| `uuid` | char(36) | N | — | |
| `tenant_id` | bigint unsigned | N | — | FK |
| `employee_id` | bigint unsigned | N | — | FK ON DELETE RESTRICT |
| `leave_type_id` | bigint unsigned | N | — | FK |
| `transaction_type` | varchar(32) | N | — | value set (§XIII.3.2) |
| `units` | decimal(8,3) | N | — | signed |
| `status` | varchar(16) | N | `posted` | `reserved / posted / released` |
| `effective_date` | date | N | — | the business date the transaction belongs to |
| `posted_at` | datetime | N | — | UTC |
| `leave_year_key` | varchar(16) | N | — | the year bucket, e.g. `FY2027-28` |
| `tranche_id` | bigint unsigned | Y | NULL | FK self — the credit this consumption drew from |
| `expires_on` | date | Y | NULL | for credit rows that are tranches |
| `source_type` | varchar(40) | N | — | `application / accrual_run / rollover_run / comp_off_settlement / encashment / manual / migration` |
| `source_id` | bigint unsigned | Y | NULL | |
| `reverses_ledger_id` | bigint unsigned | Y | NULL | FK self |
| `leave_type_version_id`, `accrual_rule_version_id`, `carry_forward_rule_version_id`, `encashment_rule_version_id` | bigint unsigned | Y | NULL | FKs — provenance |
| `formula_version_id` | bigint unsigned | Y | NULL | FK |
| `workflow_instance_id` | bigint unsigned | Y | NULL | FK |
| `reason` | varchar(500) | Y | NULL | mandatory for `adjustment` and `grant` |
| `actor_user_id` | bigint unsigned | Y | NULL | **a user id, never a display name** |
| `trace_id` | char(36) | Y | NULL | |
| `created_at` | datetime | N | — | no `updated_at`: rows are never updated |

PK `id` ·
**Unique `(tenant_id, employee_id, source_type, source_id, transaction_type)`** — the
constraint that makes a double consumption impossible (R-XIII-082.1) ·
Unique `(tenant_id, employee_id, leave_type_id, source_type, source_id)` WHERE
`source_type = 'accrual_run'` — idempotent accrual (R-XIII-024) ·
Index `(tenant_id, employee_id, leave_type_id, effective_date)` ·
Index `(tenant_id, employee_id, leave_type_id, status)` ·
Index `(tenant_id, expires_on)` WHERE `expires_on IS NOT NULL`.
Tenant scope: structural. Hist: append-only by construction. SD: **no** — a ledger row is
never deleted. Audit: the ledger *is* the record; the audit ledger additionally records who
caused it. PII: **P2**.

*`actor_user_id` is a foreign key because `E-…` DEF-AUD-04 records that SmartPRS 2 stores
actors as display-name strings in `decided_by`, `entered_by`, `by`, `accounts_by`,
`locked_by` and `bounced_by`, making a renamed or deleted user, or two employees with the
same name, indistinguishable in the record of who approved a payment.*

### XIII.13.10 `leave_balances_snapshot`

*Purpose:* a materialised running balance per `(employee, type, leave_year_key)` for fast
reads. A **cache**, reconciled nightly (R-XIII-089).

`id, tenant_id, employee_id, leave_type_id, leave_year_key, opening decimal(8,3),
accrued decimal(8,3), carried_in decimal(8,3), granted decimal(8,3), consumed decimal(8,3),
reserved decimal(8,3), encashed decimal(8,3), lapsed decimal(8,3), expired decimal(8,3),
adjusted decimal(8,3), closing decimal(8,3), available decimal(8,3),
last_ledger_id bigint unsigned, computed_at datetime`.
Unique `(tenant_id, employee_id, leave_type_id, leave_year_key)`.
Audit: recomputation events only. PII: **P2**.

### XIII.13.11 `comp_off_credits`

*Purpose:* the earned side of the comp-off ledger, as dated tranches. Consumption lives in
`leave_ledger` like every other type; this table carries the earning provenance.

`id, uuid, tenant_id, employee_id, credit_rule_version_id, source
(weekly_off|holiday|extra_hours|event), source_date, work_on_off_settlement_id
(FK → work_on_off_settlements.id, Part XII §XII.7.9), worked_minutes,
units_credited decimal(4,2), earned_on date, expires_on date,
status varchar(24) (pending_approval|active|partially_used|fully_used|expired|cancelled),
units_remaining decimal(4,2), ledger_id bigint unsigned (FK → leave_ledger.id),
workflow_instance_id, approved_by_user_id, approved_at, trace_id, audit columns`.
PK `id` · **Unique `(tenant_id, work_on_off_settlement_id)`** — one credit per settled
day, which is what prevents double crediting when a recalculation re-derives the day ·
Index `(tenant_id, employee_id, status, expires_on)`.
Audit: full. PII: none.

### XIII.13.12 `leave_blackout_periods`, `leave_staffing_rules`, `leave_restriction_rules`

`leave_blackout_periods`: `id, tenant_id, code, name, scope_json, priority,
leave_type_codes json (empty = all), from_date, to_date, recurrence json NULL,
severity varchar(24), override_workflow_code varchar(64) NULL, reason varchar(500),
effective_from, effective_to, status, approval columns`.
Unique `(tenant_id, code, version_no)` · Index `(tenant_id, from_date, to_date)`.

`leave_staffing_rules`: `id, tenant_id, code, name, scope_json, population_expr text NULL,
basis varchar(24) (per_day|per_shift), min_headcount smallint NULL,
min_percent decimal(5,2) NULL, present_day_types json, leave_type_codes json,
max_concurrent_on_leave smallint NULL, severity varchar(24),
override_workflow_code varchar(64) NULL, effective range, status, approval columns`.

`leave_restriction_rules`: `id, tenant_id, code, name, scope_json,
subject_type_code varchar(64), related_type_code varchar(64) NULL,
relation varchar(40) (immediately_before|immediately_after|same_period|within_n_days),
n_days smallint NULL, outcome varchar(32) (block|warn|require_approval),
workflow_code varchar(64) NULL, effective range, status, approval columns`.

All three: tenant-scoped, versioned, audited, no PII.

### XIII.13.13 `leave_documents`

`id, uuid, tenant_id, application_id (FK), document_type_code, storage_key,
original_filename, mime_type, size_bytes, checksum, uploaded_by_user_id, uploaded_at,
verified_by_user_id, verified_at, verification_status, due_by date NULL,
retention_class varchar(40), encrypted tinyint(1) DEFAULT 1, audit columns`.
Unique `(tenant_id, application_id, document_type_code, checksum)`.
Served only through authorised, signed, expiring URLs (spine S13). PII: **P3** (health).
*Contrast SmartPRS 2's commission proof server, where the only check is a `strpos` prefix
test and `commission-proofs/../../../.env` satisfies it (SEC-03).*

### XIII.13.14 What is deliberately absent

- **No `type_name` column anywhere.** The type is a foreign key (R-XIII-085).
- **No `days` column on the application that differs from the per-day expansion.**
  `total_units` is the sum of `leave_application_days.units`, asserted.
- **No `approver_id` column.** Approval is a workflow instance (R-XIII-044).
- **No status ENUM.** A value set, so adding `cancellation_requested` is data
  (spine S16). *`leaves.status ENUM('pending','approved','rejected')` cannot hold a fourth
  state and `ensureCols()` never widens it (DEF-LVE-12).*
- **No `LeaveApplication` model without a table.** The model, the table and the migration
  filename all agree.

---

## XIII.14 Configuration surface — the Section 127 test

| # | Customer requirement | Configuration | Code? |
|---|---|---|---|
| 1 | "Earned leave accrues 1.25 days a month, capped at 30, carrying forward 15" | one accrual rule + one carry-forward rule | none |
| 2 | "Sick leave needs a medical certificate after 3 days" | `requires_medical_certificate`, `medical_certificate_after_days = 3` | none |
| 3 | "No leave in the last week of March" | one blackout period, severity `block` | none |
| 4 | "At most 2 people from a team off on any day" | one staffing rule, `max_concurrent_on_leave = 2` | none |
| 5 | "Casual leave cannot immediately follow earned leave" | one restriction rule, `relation = immediately_after`, `outcome = block` | none |
| 6 | "Half-day leave, morning or afternoon" | `min_unit = half_day` | none |
| 7 | "2-hour short leave, twice a month" | `min_unit = hour`, `max_hours_per_instance = 2`, `max_instances_per_period = 2` | none |
| 8 | "Weekends between two leave days are deducted — except for sick leave with a certificate" | one adjacency policy with `medical_exception = true`, `leave_type_codes` excluding SL, or SL in `exempt_leave_type_codes` | none |
| 9 | "Earned leave is encashable at exit at basic÷26" | one encashment rule, `trigger = at_exit`, `valuation_basis = basic`, `day_rate_divisor = 26` | none |
| 10 | "Work a holiday, get a comp-off valid 60 days" | one comp-off credit rule (Part XII wires the trigger) | none |
| 11 | "Our leave year is April–March" | `leave_year_basis = financial_year` | none |
| 12 | "Probationers get sick leave but not earned leave" | `available_during_probation` per type | none |
| 13 | "No earned leave during notice period" | `available_during_notice_period = false` on EL | none |
| 14 | "Employees may go 3 days negative on casual leave with GM approval" | `allow_negative_balance`, `negative_balance_limit = 3`, `negative_balance_workflow_code` | none |
| 15 | "Add a new leave type 'Bereavement Leave', 5 days per event" | one leave type, `entitlement_basis = per_event`, `entitlement_expr = 5d` | none |
| 16 | "Rename 'Casual Leave' to 'Personal Leave'" | edit the name on a new version | none — **and no historic consumption changes** (R-XIII-002) |
| 17 | "Backdating allowed up to 7 days, never into a closed month" | `backdate_allowed`, `backdate_max_days = 7`; the locked-period block is structural | none |
| 18 | "Maternity leave enhanced beyond the statutory minimum" | a tenant version more generous than the pack baseline; published and recorded as an enhancement (R-XIII-074) | none |
| 19 | "Reduce maternity leave below the statutory minimum" | **blocked at publish**, naming the dimension and the pack version | n/a |
| 20 | "Show leave encashment as its own payslip line" | `payslip_display = named_line` on the encashment component | none |

---

## XIII.15 Worked SPX

### XIII.15.1 Eligibility

```
-- Earned leave: confirmed employees with 6 months' service, not on notice
employee.stage = 'CONFIRMED'
AND service.months >= 6
AND employee.employment_stage <> 'NOTICE_PERIOD'
```

### XIII.15.2 Entitlement with a statutory floor

```
EL_ENTITLEMENT :=
  MAX( BANDS( service.completed_years,
              [ {from: 0,  to: 5,    result: 15d},
                {from: 5,  to: 10,   result: 18d},
                {from: 10, to: NULL, result: 21d} ] ),
       statutory.value('EL_STATUTORY_MINIMUM_DAYS') )   -- <from rule pack>
```

### XIII.15.3 Monthly accrual with a worked-days condition

```
EL_MONTHLY_ACCRUAL :=
  IF( agg.count(attendance_day, calendar_month, { day.is_working_day = TRUE
                                                  AND day.status <> 'absent' })
      >= policy.value('MIN_WORKED_DAYS_FOR_ACCRUAL'),
      ROUND( EL_ENTITLEMENT / 12, half_up, 0.5 ),
      0d )
```

**Worked value.** `EL_ENTITLEMENT = 18d`, `MIN_WORKED_DAYS_FOR_ACCRUAL = 15`, employee
worked 19 qualifying days in June 2027. `18 / 12 = 1.5 → ROUND(1.5, half_up, 0.5) = 1.5`.
Ledger: `accrual +1.500` on 30-Jun-2027, rule `EL-ACCRUAL v3`, formula `EL_ENTITLEMENT v2`.

### XIII.15.4 A leave-application guard rule

```
-- Sick leave over 3 days requires a certificate
when:
  leave.type = 'SL' AND leave.units > 3d
then:
  REQUIRE_DOCUMENT('MEDICAL_CERTIFICATE')
```

```
-- Block application into a locked period
when:
  period.is_locked(leave.from) = TRUE OR period.is_locked(leave.to) = TRUE
then:
  BLOCK('Leave dates fall in a locked payroll period')
```

```
-- Convert an over-drawn application rather than refusing it
when:
  leave.units > leave.balance(leave.type)
  AND policy.value('OVERDRAW_BEHAVIOUR') = 'convert'
then:
  CONVERT_TO( policy.code('LOP_TYPE') )
  NOTIFY('leave.converted_to_lop')
```

### XIII.15.5 The sandwich rule

§XIII.9.4, reproduced there in full.

### XIII.15.6 Comp-off credit

```
when:
  day.is_weekly_off = TRUE OR day.is_holiday = TRUE
  AND attendance.worked_minutes >= policy.value('COMPOFF_MIN_MINUTES')
except:
  employee.employment_type IN policy.list('COMPOFF_EXCLUDED_TYPES')
then:
  CREATE_COMP_OFF(
    units: CASE WHEN attendance.worked_minutes >= shift.full_day_minutes THEN 1.0d
                WHEN attendance.worked_minutes >= shift.half_day_minutes THEN 0.5d
                ELSE 0d
           END,
    expiry_ref: 'COMPOFF-60D' )
```

### XIII.15.7 Encashment at exit

```
ENCASH_DAYS := MIN( leave.balance('EL'),
                    policy.value('MAX_ENCASH_DAYS_AT_EXIT'),
                    MAX(0d, leave.balance('EL') - policy.value('MIN_RETAINED_DAYS')) )

ENCASH_AMOUNT := ROUND_TO( ( salary.component('BASIC') + salary.component('DA') )
                           / policy.value('ENCASH_DIVISOR') * ENCASH_DAYS,
                           half_up, ₹1 )
```

**Worked value.** Balance 24.0, `MAX_ENCASH_DAYS_AT_EXIT = 30`, `MIN_RETAINED_DAYS = 0` →
`ENCASH_DAYS = 24.0`. Basic ₹28,000 + DA ₹4,000 = ₹32,000; divisor 26 →
`3200000 paise / 26 × 24 = 2953846.1538… paise` → `ROUND_TO(…, half_up, ₹1) = ₹29,538.00`.
Ledger: `encashment −24.000`; payroll input: component `LEAVE-ENCASH`, ₹29,538.00, both in
one transaction (R-XIII-029).

---

## XIII.16 API surface

All `/api/v3`, UUIDs only, idempotency keys on unsafe methods, explicit policy binding on
every route (Law 5).

| Method | Path | Purpose | Permission |
|---|---|---|---|
| GET | `/leave/types` | resolved leave types for a scope | `leave.type.view` |
| POST | `/leave/types` | create a type | `leave.type.manage` |
| POST | `/leave/types/{uuid}/versions` | draft a version | `leave.type.manage` |
| POST | `/leave/types/{uuid}/versions/{v}/simulate` | entitlement/accrual simulation for a sample employee | `leave.type.simulate` |
| POST | `/leave/types/{uuid}/versions/{v}/publish` | publish (maker-checker; statutory floor validated) | `leave.type.publish` |
| GET/POST | `/leave/accrual-rules`, `/leave/carry-forward-rules`, `/leave/encashment-rules`, `/leave/adjacency-policies`, `/leave/blackouts`, `/leave/staffing-rules`, `/leave/restriction-rules` | the child artefacts, same version lifecycle | `leave.policy.*` |
| GET | `/me/leave/balances` | **my** statement, per type, with tranches | self only |
| GET | `/me/leave/ledger?type=&from=&to=` | my ledger rows with provenance | self only |
| POST | `/me/leave/applications/preview` | day-by-day preview: units, bridged days, warnings, balance after | self only |
| POST | `/me/leave/applications` | submit (idempotency key required) | self only |
| POST | `/me/leave/applications/{uuid}/withdraw` | pre-decision withdrawal | self only |
| POST | `/me/leave/applications/{uuid}/cancel` | post-approval cancellation request (whole or partial) | self only |
| POST | `/me/leave/applications/{uuid}/documents` | upload a certificate | self only |
| GET | `/leave/applications?employee=&status=&from=&to=` | scoped list, cursor-paginated | `leave.application.view` **scoped** |
| GET | `/leave/applications/{uuid}` | detail with the day expansion | scoped |
| GET | `/leave/applications/{uuid}/explain` | full trace (spine S15) | scoped |
| GET | `/leave/balances/{employeeUuid}` | a colleague's balance | `leave.balance.view_others` **and** data scope covering them |
| GET | `/leave/team-calendar?scope=&from=&to=` | who is out, with the staffing verdict per date | `leave.calendar.view` scoped |
| POST | `/leave/applications/{uuid}/decide` | delegated to the workflow engine | workflow-derived |
| POST | `/leave/adjustments` | HR grant / adjustment (reason mandatory) | `leave.adjust` |
| POST | `/leave/encashments` | raise an encashment | `leave.encash` |
| POST | `/leave/accruals/run` | run accrual for a scope and period (idempotent) | `leave.accrual.run` |
| POST | `/leave/rollovers/run` | run year rollover (idempotent, previewable) | `leave.rollover.run` |
| GET | `/leave/comp-off/credits?employee=` | comp-off tranches | scoped |
| POST | `/leave/comp-off/settlements/{uuid}/choose` | comp-off vs OT vs pay | scoped |
| GET | `/leave/reports/liability?as_of=` | leave liability valuation for the balance sheet | `leave.report.liability` |

**R-XIII-091** `GET /leave/balances/{employeeUuid}` requires **both** an explicit permission
and a data scope covering that employee (spine S13: permission and scope are orthogonal).
*SmartPRS 2's `GET /app/leaves/balances/{employee}` has no role check of any kind and
matches on name, emp_code **or raw numeric id**, making colleague enumeration trivial
(SEC-07, NEW-LVE-103).*

**R-XIII-092** Self-service endpoints resolve the subject **from the session** and accept no
employee identifier. There is no dropdown of other employees on a self-service transaction
(spine S13). *SmartPRS 2's leave apply does force self for non-managers
(`LeaveController.php:296-302`, added after the 7 Aug 2026 test report) — one of the few
places it gets this right, and the pattern is preserved.*

**R-XIII-093** The team calendar shows **that** a colleague is out; it shows the leave type
only to a viewer holding `leave.calendar.view_type`. *Leave consumption per type is a
sickness proxy and special-category data (SEC-07).*

---

## XIII.17 Events

Emitted:

| Event | When | Consumers |
|---|---|---|
| `leave.type.published` / `.superseded` | type version lifecycle | recalculation, notification, compliance |
| `leave.policy.published` | accrual / carry-forward / encashment / adjacency / blackout / staffing / restriction | as above |
| `leave.application.submitted` | submission | **approver notification (R-XIII-049)**, staffing check, team calendar |
| `leave.application.approved` / `.rejected` / `.withdrawn` / `.cancelled` / `.partially_cancelled` / `.converted` / `.expired` | lifecycle | calendar materialisation (Part XII), payroll settlement, notification, ledger |
| `leave.application.document_required` / `.document_uploaded` / `.document_overdue` | document lifecycle | notification, escalation, conversion to LOP |
| `leave.balance.reserved` / `.released` / `.consumed` / `.restored` | ledger movements | ESS, reports |
| `leave.accrual.posted` | an accrual run posts | ESS, liability report |
| `leave.carry_forward.posted` / `leave.lapse.posted` / `leave.expiry.posted` | rollover and expiry | employee notification (with the arithmetic) |
| `leave.encashment.raised` / `.settled` | encashment | payroll, F&F |
| `leave.compoff.credited` / `.expiring_soon` / `.expired` | comp-off tranches | employee reminder |
| `leave.negative_balance.entered` | balance crosses zero | manager notification, HR worklist |
| `leave.staffing.breach_detected` | staffing rule fired | approver UI, roster |
| `leave.blackout.violation_attempted` | application inside a blackout | HR worklist |
| `leave.statutory.baseline_breach_blocked` | a publish attempt fell below the pack floor | compliance dashboard, audit |
| `leave.integrity.divergence_detected` | nightly sweep found a mismatch | **operator alert** |

Consumed:

| Event | From | Effect |
|---|---|---|
| `employee.joined` | Part X | create balances, run joining proration |
| `employee.confirmed` | Part X | re-evaluate eligibility, post confirmation-gated entitlements |
| `employee.resigned` / `employee.exited` | Part X | apply notice-period rules, exit proration, encashment or recovery, close balances |
| `employee.assignment.changed` | Part X | re-resolve type versions and policies from the effective date |
| `calendar.day.recomputed` | **Part XII** | recompute affected leave day expansions and adjacency verdicts |
| `calendar.weekly_off.worked` / `calendar.holiday.worked` | **Part XII** | raise comp-off credits (§XIII.10.2) |
| `attendance.day.derived` | Part XI | reconcile leave units against the day (R-XIII-055) |
| `payroll.period.locked` / `.reopened` | Part XIV | freeze/unfreeze applications settled in the period |
| `rulepack.activated` | Part XVII | re-validate statutory floors, raise compliance items (R-XIII-076) |

**R-XIII-094** Every one of these is a **registry entry** in the notification engine
(Part XIX) with a configurable audience, channel and template. *SmartPRS 2 hard-codes
every subject, heading and detail-line label at the call site (`E-…` §7 Q7), and leave
sends nothing at all on submission.*

---

## XIII.18 Explainability

**R-XIII-095** Every leave number answers "How was this calculated?" (Law 4).

**Balance:**

```
Employee        Priya Nair (EMP-2210)
Leave type      Earned Leave (EL)   version v4, effective 01-Apr-2027 →
Leave year      FY2027-28  (basis: financial_year)

Opening         +6.000   carry_forward_in, 01-Apr-2027
                         rule EL-CF v2: max_days_expr = 15d; closing balance was 6.0
                         tranche expires 31-Mar-2029
Accrued        +13.500   9 monthly accruals × 1.500
                         rule EL-ACCRUAL v3, formula EL_ENTITLEMENT v2
                         EL_ENTITLEMENT = BANDS(completed_years 7) = 18d; 18/12 = 1.5
                         ROUND(1.5, half_up, 0.5) = 1.5
Consumed        −4.000   3 applications (LV-2027-00318, -00944, -01201)
                         allocated: 4.000 from the FY2027-28 accrual tranche
Reserved        −2.000   application LV-2027-01455, submitted 22-Dec-2027, pending
                         approver: Reporting Manager (workflow LEAVE-STD v6, step 1)
──────────────────────────────
Balance         15.500
Available       13.500   (balance − reserved)
Max balance     30.000   (leave type v4)
Carry-forward   up to 15.000 at 31-Mar-2028 (rule EL-CF v2); 0.500 would lapse
```

**An application:**

```
Application     LV-2027-01455        Earned Leave (EL) v4
Dates           Fri 04-Jun → Mon 07-Jun-2027

Day expansion
  Fri 04-Jun    working        1.00  EL   payable 1.000
  Sat 05-Jun    weekly_off     1.00  EL   payable 1.000   BRIDGED
                               ↳ adjacency policy SANDWICH-STD v2
                                 bridgeable_day_types = [weekly_off]
                                 bridge_direction = both_sides_required
                                 leave on 04-Jun (EL) and on 07-Jun (EL) → qualifies
                                 exempt_if_statutory = TRUE; EL.is_statutory = FALSE
  Sun 06-Jun    weekly_off     1.00  EL   payable 1.000   BRIDGED (same rule)
  Mon 07-Jun    working        1.00  EL   payable 1.000
                               ─────
  Total                        4.00

Count basis     adjacency_policy (leave type EL v4)
                Under count_basis = working_days this would be 2.00
Weekly off      pattern WO-TENANT-DEFAULT v2 (Sat + Sun)   [Part XII]
Holidays        none in range
Balance check   available 13.500 ≥ 4.000 → passed at submission
                re-checked at approval: available 13.500 → passed
Staffing        team TG-COLLECT-02: 6 present of minimum 4 on every covered date → OK
Blackout        none applicable
Workflow        LEAVE-STD v6 · step 1 Reporting Manager · pending since 22-Dec-2027 10:14
Ledger          reservation −4.000 (id 88431), posted 22-Dec-2027 10:14
```

**R-XIII-096** The trace names the **counterfactual** where a configuration choice changed
the answer — "under `count_basis = working_days` this would be 2.00". This is what turns
"why was I charged 4 days?" from a support ticket into a self-service answer, and it is the
single most common leave query in an Indian HR helpdesk.

**R-XIII-097** A leave value without a trace is a programming error that throws
(spine S1 Law 4).

---

## XIII.19 Migration from SmartPRS 2

Following `E-leave-requests-notifications.md` §9, whose verdict for the leave module is
**REPLACE**.

| SmartPRS 2 artefact | Verdict | Transformation |
|---|---|---|
| `leave_types` (`tenant_id, name, days_per_year, carry_forward, paid`) | **RETAIN, EXTEND** | Each row becomes a `leave_types` row with a **generated `code`** (slug of the name, uniqueness-resolved, then reviewed by the customer) plus one `leave_type_version` with `name`, `is_paid`, `is_carry_forwardable`, `entitlement_expr = <days_per_year>d`, `entitlement_basis = annual`, `count_basis` per **D-XIII-04**, `leave_year_basis` per **D-XIII-03** |
| `leaves` rows | **MIGRATE WITH MANUAL RECONCILIATION** | `type_name` → `leave_type_id` by exact then normalised match. **Every unmatched or orphaned name is a manual reconciliation item per tenant**, because the type was free text and never validated (SEC-06). No row migrates with a null type |
| `leaves.days` | **PRESERVE AS HISTORIC, DO NOT RECOMPUTE** | The value was computed as inclusive calendar days. Recomputing under the new `count_basis` would change every historic balance. It is carried as `total_units` with a `migrated_historic = true` marker and the day expansion generated from the stored range and the *historic* basis (**D-XIII-04**) |
| `leaves.status` ENUM | **MAP 1:1** | `pending → submitted/pending_approval`, `approved → approved`, `rejected → rejected`. No fourth state exists to migrate |
| `leaves.approver_id`, `approver_name`, `decided_by` (name strings) | **SNAPSHOT ONLY** | Carried onto the historic application as a decision snapshot. `decided_by` name strings are re-keyed to user ids **where resolvable**; where two employees share a name the row is flagged unresolved rather than guessed (DEF-AUD-04) |
| `leaves.type_id` | **DISCARD** | Always NULL (DEF-LVE-02). There is nothing in it |
| **Opening balances** | **STATED BY THE CUSTOMER, NEVER DERIVED** | Carry-forward was never applied, so the stored state does not represent entitlement. Opening balances are loaded as `opening_balance` ledger transactions from a customer-signed file. **This is `E-…` PO-02 and is D-XIII-02 here** |
| `leave_types.carry_forward` | **MIGRATE, NOW MEANINGFUL** | Becomes `is_carry_forwardable` plus a carry-forward rule the customer must configure. It has never had an effect, so nothing historic changes |
| Three hard-coded default lists | **DISCARD** | Replaced by the seeded pack (R-XIII-006). Tenants relying on the fallbacks (no configured types) must have types created before cutover; the migration report lists them |
| `app/Models/LeaveApplication.php` | **DELETE** | Dead code pointing at a table that does not exist |
| `2026_01_01_000400_create_leave_applications_table.php` | **RENAME AT SOURCE** | It creates `pf_esi_settings`, `tds_rules`, `pt_slabs`, `tds_returns`. Its content migrates under Part XVII; the filename does not survive |
| `LeaveController::resolveApprover()` | **DISCARD** | One workflow engine (R-XIII-044) |
| `PayrollGenController::sandwichDays()` | **REPLACED** | By the adjacency policy (§XIII.9). Historic sandwich LOP days are frozen as history |
| Comp-off | **NOTHING TO MIGRATE** | No earning mechanism exists |
| ESS leave balances | **DISCARD THE SECOND IMPLEMENTATION** | One statement service (R-XIII-087) |

**R-XIII-098 — the LOP mispayment report.** Before cutover, migration produces per tenant:
every approved leave row whose resolved type is unpaid, that overlapped a generated payslip,
with the employee, the dates, the days and the affected payroll run. This is the data the
Product Owner needs to answer **D-XIII-01**. Migration does not correct it and does not
conceal it.

**R-XIII-099 — the day-count divergence report.** Per tenant: for every historic leave row,
the days charged under the old inclusive-calendar rule against the days that would be
charged under the customer's chosen `count_basis`, aggregated by employee and by year. The
customer accepts the report before cutover.

---

## XIII.20 Edge cases and failure states

| # | Case | Behaviour |
|---|---|---|
| 1 | Leave spans a leave-year boundary | Split day by day; each day charged to its own year's entitlement (R-XIII-013). If the second year's entitlement has not yet accrued, the days beyond the balance follow the over-draw policy |
| 2 | Leave spans a payroll-period boundary | Split by period for settlement; the balance is charged once. Days in a locked period are blocked (R-XIII-034) |
| 3 | Leave spans a transfer between legal entities | Days before and after the transfer are charged to the respective entity's ledger; `carry_across_entity_transfer` decides whether the balance follows |
| 4 | Employee exits mid-leave | Days after LWD are cancelled with a `consumption_reversal`; exit proration and the F&F path run on the remaining balance |
| 5 | Employee resigns while leave is pending approval | `available_after_resignation` decides; if `no`, the application auto-transitions to `expired` with a notification, never left silently pending |
| 6 | Approver leaves the organisation with the application pending | The workflow's delegate/fallback resolves it (Part IX). **Never a null approver.** *SmartPRS 2 creates the record with a null approver, sends no email, and it is invisible to every non-manager inbox (DEF-REQ-03)* |
| 7 | Two applications overlap on one date | The second is refused at approval by the unique current-day constraint, naming the first (R-XIII-082.2) |
| 8 | Half-day leave on a day the employee is also late | Both apply; the payable identity reconciles (§XIII.8.4 worked example) |
| 9 | Hourly leave crossing an unpaid break | `hours_may_straddle_break` decides whether the break counts; default: it does not |
| 10 | Employee applies for a type they became ineligible for after submission | Re-checked at approval (R-XIII-080); refused with the reason |
| 11 | Entitlement formula changes mid-year | The version effective on each accrual date applies; already-posted accruals are **not** retro-adjusted unless a true-up is explicitly run, which is a previewed, audited operation |
| 12 | Accrual run executed twice | Idempotent by unique key (R-XIII-024); the second run posts nothing and reports zero |
| 13 | Rollover run fails halfway | The paired `carry_forward_out`/`_in` are one transaction; a partial rollover is impossible. Re-running completes the remainder |
| 14 | Balance goes negative through an approved adjustment | Permitted only within `negative_balance_limit`; otherwise the adjustment is refused |
| 15 | Comp-off tranche expires while a leave using it is pending | The reservation holds the tranche; expiry skips reserved units and re-evaluates on release |
| 16 | Comp-off credit created, then the source day is recalculated to "not worked" | The credit is reversed by an equal-and-opposite ledger entry referencing the recalculation; if already consumed, the balance goes negative and an HR worklist item is raised. **Never a silent deletion** |
| 17 | Adjacency policy would bridge a span longer than `max_bridged_span_days` | Bridging stops at the limit; the trace states it |
| 18 | Adjacency bridges into a date that already carries approved leave | The bridged unit is not double-charged; the existing consumption stands |
| 19 | Medical certificate never furnished | `on_document_overdue` decides — `convert_to_lop` posts a conversion with a `consumption_reversal` on the original type and a new consumption on LOP, all audited |
| 20 | Statutory event date is corrected after leave is approved | The entitlement re-resolves; a shortfall or excess is settled by adjustment with an audit reason. Protected-leave corrections require the declared workflow |
| 21 | Rule pack raises a statutory minimum above a tenant's configuration | A compliance item is raised with a due date; tenant configuration is **not** silently rewritten (R-XIII-076) |
| 22 | Two leave type versions tie on specificity and priority | Publication blocked, naming both (spine S3.2) |
| 23 | Leave applied for an employee with no resolvable shift (hourly leave) | The declared `day_length_policy` supplies the divisor; if none exists, the application is refused with a named error rather than assuming 8 hours |
| 24 | Blackout published after applications inside it were approved | Existing approvals stand; the blackout applies to new applications. A report lists the approved applications inside the new blackout |
| 25 | Staffing rule would be breached by an approval | Severity decides: `block`, `warn`, or route to the override workflow. The approver sees the verdict before deciding, not after |
| 26 | Nightly integrity sweep finds a divergence | Operator alert with employee, type and delta; the affected employee is excluded from the next payroll preview until resolved |
| 27 | Concurrent submit and approve of two applications | `SELECT … FOR UPDATE` on the balance row serialises them; the loser gets a named insufficiency error at approval |
| 28 | Application submitted with an unknown leave type code | 422 with a named error. **Never a silent skip of the balance check.** *This is SEC-06's exact input, and its outcome changes from "unlimited leave" to "rejected"* |
| 29 | Employee has no `company_id` | Not a leave failure. Employment records are validated at creation (Part X); leave never copies org fields from the employee row. *SmartPRS 2 fails the insert and returns the raw SQL error in a 422 body (DEF-LVE-09)* |
| 30 | A leave type is deactivated while balances exist | Deactivation prevents new applications; existing balances remain and must be settled by encashment, lapse or a declared conversion. A type with a non-zero balance cannot be deleted at all |

---

## XIII.21 Tests — exact-value regression scenarios

| Pri | Scenario | Assertion |
|---|---|---|
| **P0** | **LOP leave produces an LOP day.** Approve 1 day of a type with `is_paid = false` | `payable_days` reduced by **exactly 1.000**; payslip LOP = 1.000; ledger consumption = 1.000. **This test fails on SmartPRS 2 and is the single most important test in this part (DEF-LVE-02)** |
| **P0** | **The reconciliation identity holds.** Randomised month, 500 employees, mixed full/half/hourly/paid/unpaid leave, penalties and absences | Both identities of R-XIII-055 hold for 100% of employee-days; a deliberately corrupted leave row makes the payroll run **fail**, not pay |
| **P0** | **No double deduction — concurrency.** Two simultaneous approvals of overlapping applications | Exactly one succeeds; the other receives a named insufficiency/overlap error; ledger contains exactly one consumption |
| **P0** | **No double deduction — retry.** The same approval submitted twice with the same idempotency key | Ledger unchanged after the second; workflow step remains decided once |
| **P0** | **No double deduction — recalculation.** Attendance recalculated for a month containing approved leave | Ledger unchanged; comp-off credits unchanged; `employee_calendar_days` regenerated |
| **P0** | **Balance cannot be over-drawn by an unknown type.** Submit `type = 'Casual Leave '` (trailing space) and `type = 'Vacation'` | Both rejected with a named error; **no** leave created. *SmartPRS 2 grants unlimited leave for both (SEC-06)* |
| **P0** | **Renaming a type changes nothing.** Rename EL to "Annual Leave" via a new version | Every historic consumption, balance and report is byte-identical; the historic rows still display the name in force at the time |
| **P0** | **Backdating into a locked period is refused** | Named error; reopen workflow offered; nothing posted |
| **P0** | **The sandwich table.** The Fri/Sat-Sun/Mon input against all eight policies of §XIII.9.3 | Exactly 2.0 / 4.0 / 2.0+2.0 LOP / 2.0 / 2.0 / 3.0 / 2.0 / 4.0 respectively, from identical input, with **zero code differences** |
| **P0** | **Statutory floor cannot be reduced.** Publish a maternity version below the pack baseline where severity is `block` | Publication rejected, naming the dimension, the baseline, the tenant value and the pack version. Publishing an *enhanced* version succeeds and is recorded |
| **P0** | **Statutory leave is never sandwiched.** Adjacency policy with `exempt_if_statutory = true`, maternity leave spanning weekends | Zero bridged days; trace names the exemption |
| **P0** | **Carry-forward, cap and lapse.** Closing 26.0, cap 15.0 | `carry_forward_out −26.0`, `carry_forward_in +15.0`, `lapse −11.0`; the three sum correctly and the employee statement shows all three |
| **P0** | **One published balance.** ESS, the leave module, the manager view, the report and the payslip for the same employee and date | All five return the identical figure, from the same service call |
| **P0** | **Cross-tenant isolation** | Tenant A's types, policies, applications, ledger rows, documents and comp-off credits are invisible and unmodifiable from tenant B on every endpoint in §XIII.16 |
| **P0** | **A leave type's name is never tested.** Static analysis over `Domain\*` and every published SPX artefact | Zero string comparisons against a leave-type name; zero `CONTAINS` on `leave.type` |
| P1 | **Monthly accrual with rounding and cap.** Entitlement 21, `/12`, `{half_up, 0.5}`, `cap_at_entitlement` | Accruals of 2.0 ×10 then 1.0 then 0.0; total exactly 21.0; the trace states the cap on the twelfth |
| P1 | **Joining proration.** Joins 18-Aug, FY Apr–Mar, entitlement 15, `pro_rata_completed_months`, `{half_up, 0.5}` | Exactly 9.0 |
| P1 | **Exit proration and recovery.** Entitlement-to-date 6.0, consumed 10.0 | Recovery of exactly 4.0 days raised, valued by the declared formula |
| P1 | **Encashment arithmetic.** Basic ₹28,000 + DA ₹4,000, divisor 26, 24 days | Exactly ₹29,538.00; ledger −24.000; payroll input in the same transaction |
| P1 | **Half-day + late penalty reconciliation** | `payable_fraction` exactly 0.750 (§XIII.8.4) |
| P1 | **Hourly leave.** 2 hours against an 8-hour shift | 0.250 day units; attendance shortfall reduced by exactly 120 minutes |
| P1 | **Boundary split.** Leave 28-Mar → 4-Apr, FY leave year | Days 28–31 charged to FY-1, days 1–4 to FY, entitlements checked separately |
| P1 | **Comp-off expiry.** Credit 1.0 on 01-Mar, `expiry_days = 60`, unused | `expiry −1.000` posted on 30-Apr; reminder sent per configuration; balance reduced exactly once |
| P1 | **Comp-off consumption order.** Two tranches expiring 30-Apr (0.5) and 30-Jun (1.0), consume 1.0 | Draws 0.5 from the first and 0.5 from the second; the consumption row records both |
| P1 | **Comp-off does not stack with OT.** `stacks_with_overtime = false`, a worked weekly off | Either a credit or OT minutes, never both; the run asserts no minute is settled twice |
| P1 | **Cancellation restores exactly.** Approve 4.0, cancel 2 of the 4 days | `consumption_reversal +2.000`; tranches restored in reverse consumption order; calendar days regenerated |
| P1 | **Withdrawal releases the reservation** | `reservation_release` posted; `available` returns to its prior value |
| P1 | **Blackout blocks** | Application inside a `block` blackout is refused with the blackout's name and reason |
| P1 | **Staffing rule warns and blocks** | With `min_headcount = 4` and 4 present, the fifth approval is blocked; with severity `warn` it proceeds with a recorded warning |
| P1 | **Clubbing restriction** | CL immediately after EL is refused where `outcome = block`, routed to approval where `require_approval` |
| P1 | **Medical certificate after 3 days** | A 4-day sick leave cannot be approved without the document; a 3-day one can |
| P1 | **Document overdue converts to LOP** | Reversal on SL, consumption on LOP, both audited, payable fraction recomputed |
| P1 | **Negative balance within limit** | Approved with the higher workflow; ledger goes to −2.000; exceeding the limit is refused |
| P1 | **Approver re-decides** | The second decision is rejected as immutable; no second notification; prior remarks intact |
| P2 | **Liability report.** Balances × valuation basis as at a date | Ties exactly to the ledger sum and to the encashment valuation formula |
| P2 | **Nightly integrity sweep detects a planted divergence** | Alert raised naming employee, type and delta; the employee is excluded from the next payroll preview |
| P2 | **Type deactivation with a live balance** | Deletion refused; deactivation permitted; existing balance settleable |
| P2 | **Entity transfer mid-leave** | Days charged to the correct entity ledgers; `carry_across_entity_transfer` honoured |

---

## XIII.22 Open items — PRODUCT OWNER DECISION REQUIRED

| # | Question | Recommendation | Trade-offs |
|---|---|---|---|
| **D-XIII-01** | **How far back must the LOP mispayment be corrected?** Every approved leave since go-live — including leave filed as "Loss of Pay" — was credited as a paid day (DEF-LVE-02). (`E-…` PO-01) | Run the R-XIII-098 report immediately, before any migration work. Then decide per tenant: absorb, recover prospectively, or recover in full. **Recommend absorbing for closed financial years and recovering prospectively**, with employee communication | Recovery from employees for an employer's system error is legally and industrially fraught; absorbing it is a real cost. Either way the amount must be **known** before cutover, because it is a disclosed liability |
| **D-XIII-02** | **What opening leave balance does each employee start SmartPRS 3 with?** Carry-forward was never applied, so the stored state does not represent entitlement (DEF-LVE-03; `E-…` PO-02) | **Stated per tenant on a signed file, never computed.** Deriving from `carry_forward` would grant balances nobody accrued | Stating balances is per-tenant effort. Computing them is fast and wrong, and it would be wrong in the employee's favour, which makes it irreversible in practice |
| **D-XIII-03** | **What is the leave-year basis per customer** — calendar, April–March, or joining anniversary? (`E-…` PO-06) | Default **financial_year (April–March)** to align with payroll, requests and statutory reporting; per-tenant and per-type overridable | SmartPRS 2 uses the calendar year for leave and April–March for everything else, so *any* choice changes something. Aligning to April–March means the first rollover moves balances; keeping the calendar year perpetuates a split that confuses every report |
| **D-XIII-04** | **Are leave days charged in calendar days or working days, and are historic `days` values recomputed?** (`E-…` PO-07) | Default **`working_days`** with an adjacency policy for customers who want the weekend bridged. **Do not recompute history** — freeze it and publish the R-XIII-099 divergence report | Recomputing changes every historic balance and would resurface as a dispute with employees. Freezing means the ledger's opening balances embed the old arithmetic — which is exactly why D-XIII-02 requires them to be stated rather than derived |
| **D-XIII-05** | **Does an over-drawn application get refused or converted to LOP?** | **Converted**, with the employee told clearly, because a refusal in SmartPRS 2 is what made SEC-06's bypass attractive | Conversion means an employee can accidentally take unpaid leave. The preview endpoint shows the conversion and the pay impact before submission, which is the mitigation |
| **D-XIII-06** | **Is the sandwich rule on by default for new tenants?** | **Off.** Seed the policy artefact disabled, with the eight §XIII.9.3 shapes as named presets | SmartPRS 2's default is off (`sandwich_rule = 0`), so "off" preserves behaviour. Turning it on by default would silently double some employees' leave consumption at cutover |
| **D-XIII-07** | **Do comp-off, OT and additional pay compete, and who chooses?** | **Employee chooses within a window**, HR default applies if they do not; `stacks_with_overtime = false` | Employee choice is the fairest and matches the brief. It adds a decision surface and a window job. HR-only choice is simpler and reads as arbitrary to staff |
| **D-XIII-08** | **What is the default comp-off expiry?** | 60 days, tenant-configurable, with a reminder at 15 days | Longer expiry accumulates a liability that must be valued; shorter expiry is perceived as forfeiting earned time |
| **D-XIII-09** | **Which leave types are encashable, and on what basis?** | Earned leave only, at exit, on basic + DA with a declared divisor. Every value tenant-configured; the **tax treatment is `<from rule pack>`** | Encashing casual or sick leave creates an incentive to hoard. The divisor (26 vs 30) materially changes the amount and must be an explicit customer decision, not a default |
| **D-XIII-10** | **Who is the named compliance owner for the leave content of the India rule pack** — the statutory durations, eligibility conditions and protections of §XIII.11? | Required before any statutory leave type ships. This is spine D-03 applied to this part | Without a named owner, `<from rule pack>` placeholders have nobody to fill them and the floor rule (R-XIII-074) has no baseline to compare against |
| **D-XIII-11** | **Is paternity leave a platform statutory baseline or company policy?** | Ship it as a **leave type with no statutory baseline** unless the rule pack declares one for the applicable jurisdiction and sector. Never assert a duration in this blueprint | Treating it as statutory where it is not creates a false compliance claim; treating it as policy where a sector rule exists creates a real gap. The rule pack, not the platform, must answer per jurisdiction |
| **D-XIII-12** | **Are leave reasons and medical certificates visible to the reporting manager?** | Reason: yes to the approver, no to the team calendar. Certificate: **HR only**, never the line manager | Approvers argue they need the reason to decide. Health data in a manager's hands is a DPDP exposure and, in SmartPRS 2, is already readable by every authenticated user (SEC-07) |
| **D-XIII-13** | **Is leave submission notification mandatory or configurable?** | **Mandatory** — configurable in channel and template, not in whether it is sent | *"Managers do not know a leave request exists unless they open the inbox. In practice leave sits unapproved"* (DEF-NOT-01). Making it optional reproduces the failure |
| **D-XIII-14** | **Retention for leave applications, ledger rows, documents and traces** | Ledger and applications: **statutory register retention (recommend 3 years + current financial year, minimum)**. Medical documents: the shortest defensible period, then purge. Traces: with the record they explain | SmartPRS 2 retains everything and prunes nothing, against a published 90-day privacy commitment (SEC-18). A ledger cannot be aggressively pruned without destroying the balance's provenance, so the two classes must be separated |
| **D-XIII-15** | **Does a leave balance follow an employee across a legal-entity transfer?** | Configurable per type; default **carry** within a tenant, **settle** across legal entities | Carrying across entities moves a liability between books. Settling is cleaner accounting and reads to the employee as losing their leave |

---

## XIII.23 Requirements index

| Range | Area | Count |
|---|---|---|
| R-XIII-001 – R-XIII-006 | Leave type master, name prohibition, scoping, seeded pack (§XIII.2) | 6 |
| R-XIII-007 – R-XIII-011 | The ledger, transaction types, tranches (§XIII.3) | 5 |
| R-XIII-012 – R-XIII-026 | Accrual, leave year, entitlement, proration, carry-forward, lapse, negative balance (§XIII.4) | 15 |
| R-XIII-027 – R-XIII-030 | Encashment (§XIII.5) | 4 |
| R-XIII-031 – R-XIII-033 | Day counting (§XIII.6) | 3 |
| R-XIII-034 – R-XIII-049 | Application rules, documents, blackout, staffing, lifecycle (§XIII.7) | 16 |
| R-XIII-050 – R-XIII-056 | Half-day, hourly, reconciliation (§XIII.8) | 7 |
| R-XIII-057 – R-XIII-060 | Leave adjacency (sandwich) policy (§XIII.9) | 4 |
| R-XIII-061 – R-XIII-068 | Comp-off engine (§XIII.10) | 8 |
| R-XIII-069 – R-XIII-078 | Statutory and protected leave, the floor rule (§XIII.11) | 10 |
| R-XIII-079 – R-XIII-089 | Balance integrity (§XIII.12) | 11 |
| R-XIII-090 | Data model — flags on the version (§XIII.13) | 1 |
| R-XIII-091 – R-XIII-094 | API and events (§XIII.16–XIII.17) | 4 |
| R-XIII-095 – R-XIII-097 | Explainability (§XIII.18) | 3 |
| R-XIII-098 – R-XIII-099 | Migration reports (§XIII.19) | 2 |
| **Total** | | **99** |

### Compliance with the seven laws (spine S1)

| Law | How this part complies | Test |
|---|---|---|
| 1 — no business literal | Every entitlement, accrual amount, cap, threshold, notice period, divisor, expiry and multiplier is a configuration field or an SPX formula (§XIII.14 lists 20 customer requirements, all zero-code). **No leave-type name may appear as a literal anywhere** (R-XIII-002) | Static analysis over `Domain\Leave` and every published SPX artefact (§XIII.21 P0) |
| 2 — no schema change at runtime | All 14 tables are migration-owned. There is no successor to `LeaveController::ensureCols():34-57` or to the inline `Schema::getColumnListing()` calls at `:379` and `:425` | CI grep for DDL outside migrations; `migrate:fresh` parity |
| 3 — nothing overwritten | Types, accrual, carry-forward, encashment and adjacency policies are versioned artefacts. **The ledger is append-only and has no `updated_at`.** Applications transition; day rows use generations; documents are never replaced in place | "What entitlement applied to E on D?" and "why is this balance 15.5?" answered by one service call each |
| 4 — every number explains itself | §XIII.18; a leave value without a trace throws. The trace names the counterfactual (R-XIII-096) | No service return path may omit a trace |
| 5 — authorisation fails closed | Every route in §XIII.16 carries an explicit permission; self-service resolves the subject from the session (R-XIII-092); reading a colleague's balance needs permission **and** data scope (R-XIII-091); medical documents are served only through signed, expiring, authorised URLs | Route-policy sweep; fault injection in authorisation must deny; the SEC-07 endpoint shape is asserted absent |
| 6 — tenancy is structural | `tenant_id NOT NULL` on all 14 tables and in every unique index, applied by the persistence layer. **No balance or ledger query filters on `employee_id` alone** | Generated cross-tenant isolation test per aggregate and per endpoint (§XIII.21 P0) |
| 7 — money is exact | The only money this part produces is encashment and over-consumption recovery, computed by Part VIII formulas in integer paise with a declared rounding mode and point; `DECIMAL(18,4)` at rest. Leave **units** are `DECIMAL(8,3)` and never floats | No float arithmetic in `Domain\Leave`; encashment ledger and payslip line reconcile exactly (R-XIII-029) |
