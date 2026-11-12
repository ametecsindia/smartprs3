# PART XIV — PAYROLL ARCHITECTURE

**Covers master-brief Sections 34 (payroll period engine), 35 (proration and
loss of pay), 36 (the payroll engine pipeline) and 37 (payroll preview and the
staged run lifecycle).**

Conforms to: spine **S1** (the seven laws), **S3.1/S3.2** (scope and
precedence), **S4** (effective dating, draft/publish), **S5** (SPX), **S6** (the
rule engine), **S7** (the formula engine), **S8** (the workflow engine),
**S10** (attendance layers), **S11** (payroll architecture), **S12** (the
statutory rule pack), **S14** (the audit ledger), **S15** (explainability),
**S16** (data conventions).

Depends on and does not duplicate: **Part VII** (rule engine), **Part VIII**
(formula engine — the type system of §5 and the calculation context of §8, and
formulas FL-01…FL-28), **Part IX** (workflow engine — `PAYROLL-APPROVE`,
`LOAN-SANCTION`, `EXIT-FNF`), **Part XI** (attendance — `period_settlements` is
the only attendance surface payroll may read), **Part XV** (salary structure),
**Part XVI** (payslip), **Part XXVIII** (the physical schema).

---

## XIV.0 Purpose

This part decides **how a payroll period is defined, how a payroll run is
computed, and how it is controlled**. Concretely it fixes three things: the
payroll period is a resolved, effective-dated, scoped artefact rather than a
`YYYY-MM` string; the run is a deterministic, restartable pipeline over a frozen
input snapshot so that a re-run reproduces its original result exactly and a
changed input is named; and the run's lifecycle is a workflow instance with a
reconciliation assertion that aborts the run rather than a status column that
two people can both satisfy.

Everything below exists because a specific, evidenced SmartPRS 2 behaviour makes
the alternative untenable. Where that is so, the clause says which.

---

## XIV.1 What SmartPRS 2 does today, and why it must change

Every claim here traces to `analysis/A-payroll.md` with its `file.php:line`
citation. This section is not a defect dump; it is the justification for each
design decision that follows, and each subsequent section cites back to it.

### XIV.1.1 The application fabricates twelve months of payroll on a page load, and that permanently blocks real payroll

`AppDataController::ensurePayrollHistory():2340-2380` runs on **every** load of
`/app/data` (`:458`). For each of the previous twelve months without an existing
run it inserts a `payroll_runs` row and one `payslips` row per employee, with
`'status' => $k === 0 ? 'draft' : 'paid'` (`:2361`). The payslips are computed
by `self::computeSlip((float) $e->ctc, …)` (`:2367`) — full CTC, no LOP, no
attendance, no salary components, no commissions, no recoveries.

The employee set `$emps` at `:297` is **self-scoped for non-privileged users**
(`:268-270`). If a plain employee is the first person to open the application on
a fresh install, the company's entire twelve-month payroll history is created
containing one person, with `employees_count = 1`.

It is not reversible through the product. `PayrollGenController::generate():2362`
refuses any month whose run is `paid` — *"A paid run already exists for
&lt;month&gt;. It can no longer be regenerated."* **Eleven months of real payroll
per company are therefore permanently blocked** by rows the product wrote itself.
Those rows additionally feed `esiPeriodLock()` (which scans stored payslips),
`payslipYtd()`, and `PayrollLock::isPeriodFinalised()`, which then blocks
statutory configuration changes dated into those months.

> **SmartPRS 3 consequence.** No production code path may write a business
> record that was not requested by an authenticated actor performing a named
> operation (R-XIV-001). Demonstration data is a separately labelled tenant, not
> a side effect of a GET.

### XIV.1.2 Five divisors coexist, and only base salary honours the configured basis

`A-payroll.md` §4.2 and §5.3 Q1. In one payroll month:

| Concern | Divisor actually used | Honours `rates.lop_basis`? |
|---|---|---|
| Base salary proration | working days / calendar days / flat 30 (`compute():1108-1111`, factor `:1268-1287`) | — (the reference) |
| PF, ESI, PT, TDS | none; computed on the already-prorated CTC | Yes, by inheritance |
| Commission / incentive | none; added after `computeSlip` returns (`:1350`, `:1391`) | **No — never prorated** |
| Overtime | `ctc / 12 / 26 / 8` (`MasterController:761`, duplicated at `PayrollGenController:2165`) | **No — a hard 26** |
| Night allowance | flat ₹ per night (`compute():1326-1338`) | **No** |
| Arrears | `(newCTC − oldCTC) / 12`, first month prorated by **calendar** days (`IncrementController:369`) | **No — always calendar** |
| Gratuity | `15 / 26` (`StatutoryController:57`) | **No** |
| Statutory bonus | months via `diffInDays / 30.44` (`StatutoryController:145`) | **No** |
| Live Salary zero-punch projection | elapsed **working** days ÷ working days (`:1610-1613`) | **No** |

`26` is never a salary divisor — it appears in exactly three places, all
overtime or gratuity — **while the in-app FAQ headlines *"Why does one absent day
cut gross ÷ 26 and not gross ÷ 30?"*** (`CalcGuideController:161`). The guide
describes a feature the engine does not have. A `payable_days` basis is not
offered at all. The basis that is offered is one tenant-wide string, and anything
outside the three-value whitelist **silently falls back to `working`**
(`:1109-1111`).

Worse than the count is the unit mismatch. `PayrollGenController:1268-1284`:

```php
$absentDays = max(0.0, $empWorking - $paidDays);   // measured in WORKING days
$den        = (float) $daysInMonth;                 // valued in CALENDAR days
$factor     = max(0.0, min(1.0, ($avail - $absentDays) / max(1.0, $den)));
```

Two absent working days in a 31-day month with 27 working days cost 2/31 of
gross, not 2/27 — a ₹477.89 difference on a ₹50,000 gross, every month,
invisibly, in the employee's favour. Either answer may be a legitimate business
choice; neither is a legitimate accident.

### XIV.1.3 Leaver proration does not exist

`A-payroll.md` §3.3 NEW-PAY-12 and §5.2 item 18: grep confirms **nothing reads a
date of leaving anywhere in `compute()`**. A mid-month leaver is paid a full
month. Joiner proration does exist (`:1216-1228`) but is wrong under a cut-off
cycle: the test is `doj->format('Y-m') === $month`, comparing the joining date to
the *payroll month label*, so under a 21→20 cycle a 25-June joiner belonging to
the July payroll month fails the test entirely and is paid as a full-month
employee (DEF-P05). The same defect makes `workingDaysInRange():97-109` and
`sandwichDays():770-816` index the wrong dates, because both iterate
`Carbon::create($start->year, $start->month, $d)` where `$daysInMonth` is the
*period length*, not a day-of-month bound.

### XIV.1.4 Money is float

`config/smartprs.php:128` and `README.md:73` assert *"All monetary values use
decimal columns + integer-safe math. Never float."* Storage is genuinely
`DECIMAL`. **Arithmetic is float throughout** — independently verified in
`A-payroll.md` §5.3 Q2: `composer.json` requires no `ext-bcmath`; grep across
`app/` finds zero `bcadd`/`bcmul`/`bcsub`/`bcdiv`/`brick/money`; every signature
in the money path is typed `float` (`computeSlip(float $ctc):2094`,
`statutory(float $gross, float $pfWage):2059`, `ptForGross(float $gross): float
:1925`).

The consequences are not theoretical. **A float comparison decides whether a loan
EMI is deducted** (`:1357`). A **loose float equality** decides whether a
commission line is written at all (`if ((float) $camt == 0.0)`, `:2632`).
`payroll_runs.net_total` is a running float sum over every employee
(`:1201-1203`, `:1427-1429`). The bank-file total is computed twice by two
different float paths (`SalaryApprovalController:428` and `:483`) with no
cross-check. `SchemeController:290`, `:821`, `:832` carry explicit `0.005`
epsilons — the code acknowledging its own imprecision.

### XIV.1.5 "Lock" prevents almost nothing, and the documented reopen path has zero callers

`A-payroll.md` §5.3 Q3, verified by exhaustive grep of both call sites.

*States.* `draft → hr_approved → approved`, `rejected` from either stage. `paid`
and `locked` are recognised by `PayrollLock::FINALISED_STATUSES:31` and by
`generate()`'s block list `:2362` but **are never written by any code**.
`payroll_runs.locked_at` *is* stamped on final approval
(`SalaryApprovalController:829`); the status is not.

*What lock prevents — exactly two things.* (1) `generate()`'s own inline
`in_array($existing->status, ['hr_approved','approved','locked','paid'])` check
(`:2362`) refuses to regenerate such a run — note `rejected` is **not** in that
list, so a rejected run can be regenerated into a fresh draft, the undocumented
recovery path. (2) `PayrollLock::isPeriodFinalised()` is consulted by
`EffectiveDated::guardFinalisedPeriod()`, called from **only two places**:
`StatutoryConfigController::save():158` and `saveSlab():258`.

*What lock does not prevent.* Changing the tenant-wide `statutory_settings` blob
(`SettingsController::save()` has no period check and no effective date); adding
or approving punches, leave or holidays (**there is no attendance freeze
anywhere**, DEF-P04); applying an increment that overwrites `employees.ctc`;
editing commissions, overtime, advances or clawbacks.

*Reopen.* `PayrollLock:22-25` documents *"reopening a finalised run is SUPER
ADMIN ONLY"*. `denyUnlessSuperAdmin():82-97` has **zero callers**. There is no
reopen endpoint. And the two guards in the same call chain fail in opposite
directions: `EffectiveDated::guardFinalisedPeriod():115-117` fails **open** on any
exception, `PayrollLock::denyUnlessSuperAdmin():91-96` fails **closed**
(NEW-PAY-26).

### XIV.1.6 Regeneration destroys the run and its payslips, including approvals and remarks

`A-payroll.md` §5.3 Q7. With `regenerate=1` on a `draft` or `rejected` run:
`DELETE payslips WHERE run_id = ?` then `DELETE payroll_runs WHERE id = ?`
(`:2389-2390`). Five side-effect reversals then run **best-effort, each in its own
try/catch so a failure never blocks** (`:2398-2465`). What is lost: the previous
run's id, its rejection remark, its approval stamps, and every per-line status —
only an `activity_logs` row survives, and it records the *replaced* run id, not
the rejection. Compounding this, a run-level rejection writes only
`status = 'rejected'`, `remarks` and `updated_at` — **no actor and no timestamp**
(`applyDecision():809`, DEF-P18).

### XIV.1.7 There is no reconciliation assertion

`payroll_runs.net_total` is written once at generate from `$c['totals']['net']`
(`:2549`) and **never corrected**. A line later rejected or held still contributes
to it. `A-payroll.md` §4.16 and DEF-P17 confirm: **no query anywhere compares it
to `SUM(payslips.net)`**, and no check asserts that an employee's earnings minus
deductions equals their net. `computeSlip` clamps `net = round(max(0, gross −
total_ded), 2)` while leaving `total_ded` at full value, so on a heavily prorated
slip where flat PT and LWF exceed gross, the stored `gross − ded ≠ net` and the
payslip PDF prints an equation that is arithmetically wrong on the document the
employee receives (`payslip-pdf.blade.php:198`, DEF-P06).

### XIV.1.8 A re-run silently uses today's policy

`A-payroll.md` §5.3 Q5, in full:

- `SettingsController::rates($tid)` (`:91-102`) reads **one row** —
  `statutory_settings` where `tenant_id = ?` — a single JSON blob with **no
  effective date and no history**. `save():198-201` is an `updateOrInsert` that
  overwrites it.
- `PayrollGenController::compute():1094` calls `rates($tid)` **with no date
  argument at all**. Every rate used for a 2025 month is the rate stored today.
- The only effective-dated layer, `StatutoryConfig::applyScope()`, is an override
  layer *on top of* the blob; with no override rows saved — the default state,
  guarded by `hasAnyRows():121-123` — it returns the input untouched. A tenant
  that has never opened Statutory Configuration has **zero** effective dating.
- **`employees.ctc` is overwritten in place** by an increment
  (`IncrementController:342`). There is no salary history table.
- `salary_components` has **no effective-dating column at all**.

History is protected today only by immutability — a run past `draft` cannot be
regenerated. The moment anyone regenerates a draft or a rejected run, it is
re-priced at today's rates, today's CTC and today's structure, **with no
warning**.

### XIV.1.9 What is not replicated

| SmartPRS 2 behaviour | SmartPRS 3 |
|---|---|
| A production code path that writes payroll rows on a page load | **Not replicated.** R-XIV-001 |
| Five uncoordinated divisors | **Not replicated.** One declared basis per component, one conversion function (Part VIII §5.3) |
| Absence measured in working days and valued in calendar days with no conversion | **Not replicated.** A publish-time type error (Part VIII gate G4) |
| A tenant-wide LOP basis with a silent fallback | **Not replicated.** Scoped per spine S3.1; an unresolvable basis is a publish-time error |
| No leaver proration | **Not replicated.** One boundary expression covers joiner, leaver and both (FL-13) |
| Float money | **Not replicated.** Integer paise end to end (Law 7) |
| A lock that guards two save paths | **Not replicated.** The period lifecycle gates every write that can change a computed figure |
| A reopen that is documented and unimplemented | **Not replicated.** `PERIOD-REOPEN` is a workflow (R-IX-075) |
| Regeneration by deletion | **Not replicated.** A new run version supersedes; nothing is deleted (R-IX-076) |
| No reconciliation | **Not replicated.** The assertion aborts the run |
| A re-run priced at today's policy | **Not replicated.** The input snapshot pins every version |

---

## XIV.2 The payroll period engine *(brief Section 34)*

### XIV.2.1 A pay cycle is a configuration artefact, not a column

**R-XIV-002** A payroll period is produced by resolving a **pay cycle** artefact —
`artefact_type = 'pay_cycle'`, an ordinary spine S4 artefact with
`scope_json`, `priority`, `effective_from`, `effective_to`, versioning and
draft/publish. It is declared `continuous` (spine S4), so a gap between versions
is rejected at publish. *Because SmartPRS 2 resolves a cut-off day by scanning
two tables in row order and matching the company by **name** (`resolveCutoffDay()
:138-161`, BR-PAY-002), which breaks silently on rename, and because `pay_cycles`
has no migration at all (`A-payroll.md` §2.5).*

**R-XIV-003** A pay cycle is scoped and resolved by **id**, never by name. Its
subject is the `pay_group_code` dimension of spine S3.1, plus any other scope
dimensions the tenant constrains. Resolution is
`resolve('pay_cycle', key, scope, as_of)` and obeys spine S3.2 precedence; a
specificity-and-priority tie **blocks publication** naming both artefacts and the
affected employees.

**R-XIV-004** Exactly one pay cycle version resolves for a given
(employment, date). Zero resolutions is a blocking preview exception
(`missing_pay_cycle`), never a fallback to the calendar month. *SmartPRS 2 falls
back to the calendar month whenever the cut-off resolver returns 0, which is also
what it returns when the master screen was never opened.*

### XIV.2.2 The seven period kinds

**R-XIV-005** The `period_kind_code` value set contains, at minimum:

| `period_kind_code` | Parameters | Period generated |
|---|---|---|
| `calendar_month` | — | 1st → last day of the named month |
| `cutoff_day` | `period_end_day` **1–31** | `(end_day+1)` of the prior month → `end_day` of the run month. `period_end_day = 31` under `clamp_last` gives 1st→last, identical to `calendar_month`; `21` gives 22→21; `20` gives 21→20; `25` gives 26→25 |
| `custom_fixed` | `period_start_day`, `period_end_day` | Any fixed window, including one that does not span a whole month |
| `four_week` | `anchor_date`, `weeks = 4` | 13 periods a year, 28 days each, anchored |
| `fortnight` | `anchor_date` | 26 periods a year |
| `weekly` | `week_start_day_code` | 52 or 53 periods a year |
| `semi_monthly` | `first_period_end_day`, `second_period_end_day` (default `last`) | Two periods per calendar month |

`periods_per_year` is a **derived, stored** property of the cycle version, used by
every annualising formula (`period.periods_per_year`, Part VIII §11.0). It is
never the literal 12. *`AppDataController::computeSlip():2102` divides annual CTC
by the literal `12`; on a fortnightly cycle that is wrong by a factor of 2.17.*

**R-XIV-006** `cutoff_day` accepts **1 to 31**. There is no 2–28 whitelist.
*`resolveCutoffDay():153` restricts to 2–28 as a literal; `A-payroll.md` §5.2
item 10 records the customers this excludes, and DEF-P14 records that the
restriction is the only thing preventing a live February overflow bug.*

**R-XIV-007** A cycle declares an explicit `period_label_rule_code` —
`by_period_end` (the default: the window 26-Jun→25-Jul is labelled `2026-07`),
`by_period_start`, or `by_pay_date` — because under a cut-off the label is a
business choice and today it is an unstated assumption. The label is display and
addressing only; **no calculation may derive a date from it**. *SmartPRS 2's
joiner test compares `doj->format('Y-m')` to the month label (`:1221`), which is
exactly this mistake and is DEF-P05.*

### XIV.2.3 Day-of-month clamping at one choke point

**R-XIV-008** Every day-of-month parameter — period start day, period end day,
cut-off day, attendance-cut-off day, pay day — is resolved through **one**
function, `CLAMP_DAY_TO_MONTH(day, month)` (spine S5.4), with a declared
`day_overflow_mode_code`:

| Mode | 31 in February 2027 | Use |
|---|---|---|
| `clamp_last` (default) | 28 | "the 31st" means "month end" |
| `clamp_to` *(explicit value)* | as declared | rare, contractual |
| `reject` | publish-time error | when the customer must be forced to decide |

**R-XIV-009** No other code may construct a period boundary. A static-analysis
rule fails the build on `->day(` , `setDate(`, `createFromFormat(… .'-01')` or an
equivalent outside `Domain\Payroll\Period`. *`PayrollGenController:125` and
`:175-176` call `Carbon::…->day($c)` with no clamp, so day 30 of February
overflows to 2 March; `PeriodResolver::clamp():280-285` exists, is explicitly
unit-tested (`tests/Unit/PeriodResolverTest.php:37-42`) and **is not called by the
engine** (DEF-P14). The engine's safety today is a coincidence of the 2–28 range
this part removes.*

### XIV.2.4 Period generation, continuity and the bridging period

**R-XIV-010** Periods are **materialised rows** (`payroll_periods`, Part XXVIII
C7), generated ahead by a scheduled job from the resolved cycle version, not
derived on demand at run time. A period row, once any run has referenced it, is
immutable in its dates. *So that a later cut-off change cannot re-slice a paid
month — the one property SmartPRS 2 does get right, by storing `period_start` /
`period_end` on the run and every payslip (BR-PAY-700, `:2545-2546`,
`:2676-2677`), and which must be preserved.*

**R-XIV-011** Generation asserts **continuity**: for a given `pay_group_code`,
consecutive periods satisfy `next.period_start_on = prior.period_end_on + 1 day`.
An overlap or gap is a **publish-time** failure of the cycle version, not a
run-time 422.

**R-XIV-012** When a cycle change legitimately creates a gap — a customer moving
from a calendar month to a 26→25 cut-off — the period engine **generates the
bridging period itself**, of kind `transition`, and the transition is part of the
cycle version's publish diff (spine S4) showing the bridge window, the employees
affected and the number of days each is paid for. *SmartPRS 2 only refuses
(`generate():2496-2537`), and offers `confirm_period_gap=1`, an **unaudited**
escape hatch through which a caller can pay days twice or leave days unpaid
(SEC-P10).*

**R-XIV-013** The `confirm_period_gap` shape does not exist. There is no flag
that suppresses a continuity check. The only path through a gap is the bridging
period, which is itself an approved configuration change.

### XIV.2.5 The pay-date rule — one rule

**R-XIV-014** Pay date is resolved by exactly one rule:

```
pay_on = SHIFT_FOR_NON_BANKING(
           PAY_DAY_BASE(pay_day_rule_code, pay_day_value, period),
           pay_day_holiday_shift_code, banking_calendar_code)
```

with `pay_day_rule_code ∈ { fixed_day_of_period_end_month,
fixed_day_of_following_month, n_days_after_period_end, last_working_day,
nth_weekday_after_period_end }`, `pay_day_value` clamped by R-XIV-008, and
`pay_day_holiday_shift_code ∈ { before, after, none }` against a named banking
calendar. *SmartPRS 2 has **three** incompatible pay-date rules — `payDateFor()`
(cut-off → same month, else next month, `:1383-1425`), a complete 30-line `$pick`
closure in `resolvePayDate():825-861` that is **built and never called**
(DEF-P15), and `PeriodResolver::payDate():111-124` (first occurrence on or after
period end) — which for a 21→20 window with pay day 5 disagree by a month.
Additionally `salary_schedules.pay_day` is declared `string` while
`pay_cycles.pay_day` is `int` (`A-payroll.md` §2.5), so the comparison works only
through MySQL's implicit coercion.*

**R-XIV-015** `pay_on` is computed once, at period materialisation, stored on
`payroll_periods`, and copied onto every run and payslip. It is never
recomputed. A change to the cycle's pay-day rule affects future periods only.

### XIV.2.6 The four freezes

The period lifecycle is `open → cutoff_passed → in_run → locked → (reopened)`,
and it carries **four distinct freezes** which SmartPRS 2 collapses into nothing.

| Freeze | Set at | What it stops | Escape |
|---|---|---|---|
| **Attendance cut-off** | `attendance_cutoff_on`, from the cycle | New punch ingestion is *not* stopped (raw evidence is append-only, spine S10 layer 1); **derivation into this period's settlement** is stopped. A late punch is ingested, derived, and lands in the next period's settlement as a dated adjustment | `ATTENDANCE-CUTOFF-EXTEND` workflow, per period, audited |
| **Attendance/leave freeze** | when `period_settlements` is frozen (Part XI §XI.2.7) | Any change to attendance days, leave charges, holiday calendars, comp-off or OT approvals **for dates inside the period** altering the settlement | `PERIOD-REOPEN`, which re-derives and produces a diff |
| **Variable-input freeze** | `variable_input_cutoff_on`, from the cycle | New or amended commission, incentive, OT claim, reimbursement, arrear instruction or one-off payment **targeting this period**. Later submissions are accepted and auto-targeted at the next open period | `VARIABLE-INPUT-EXTEND` workflow, audited, per period |
| **Payroll freeze (lock)** | `n_lock` of `PAYROLL-APPROVE` | Every write that could change a computed figure for the period: statutory override effective inside it, structure change effective inside it, compensation change effective inside it, recovery schedule change, any new run of the same type and lot | `PERIOD-REOPEN` |

**R-XIV-016** The payroll freeze is enforced by **one** guard service,
`PeriodLockGuard::assertWritable(entity, effective_date, tenant, actor)`, called
from the single write service of every affected module. It **fails closed** on
any error. *`EffectiveDated::guardFinalisedPeriod():115-117` fails **open** on
any exception while `PayrollLock::denyUnlessSuperAdmin():91-96`, in the same call
chain, fails **closed** (NEW-PAY-26); and the guard is wired into exactly two
save paths out of the dozens that can change a computed number
(`A-payroll.md` §5.3 Q3).*

**R-XIV-017** A CI sweep asserts that every write path to
`employee_compensations`, `cfg_salary_structures`, `cfg_salary_components`,
statutory overrides, `variable_pay_inputs`, loan schedules, `attendance_days`,
leave charges and holiday calendars calls `PeriodLockGuard`. A new write path
without the call fails the build.

**R-XIV-018** A locked period's freeze is **not overridable by a role**. There is
no super-admin bypass in the guard. The only path is `PERIOD-REOPEN`.
*Replacing `unless super_admin` at `PayrollLock:39-75`, whose only two call sites
are statutory configuration saves.*

### XIV.2.7 Audited reopen

**R-XIV-019** `PERIOD-REOPEN` is a workflow definition (spine S8, Part IX
R-IX-075), seeded and published per tenant, subject `payroll_period` /
`reopen`, marked `requires_maker_checker = 1`. Its shape:

```
n_submit   (reason from a value set; free-text justification mandatory;
            the impact preview is a required attachment)
  → n_impact   auto_approve when the preview computes cleanly, else n_block
  → n_finance  RIS(FINANCE_CONTROLLER, legal_entity)
  → n_compliance  present only when any statutory filing already references
                  the period (condition_branch on filing_state)
  → n_finalise  emits payroll.period.reopened
```

**R-XIV-020** Reopening a period does **not** unmake anything. It sets
`lifecycle_state_code = 'reopened'`, records the workflow instance id on the
period, and permits new **run versions**. Every payslip already issued remains an
issued fact and is superseded, never edited (Part XVI). A period that has been
reopened carries that fact permanently and it appears on every register drawn
from it.

**R-XIV-021** A reopen whose period is referenced by a **submitted** statutory
filing requires the compliance node and produces a `statutory.filing.impacted`
event naming the filing, so the revised-return obligation is visible rather than
discovered.

### XIV.2.8 Recalculation

**R-XIV-022** `recalculate(scope, period, reason)` is a first-class idempotent
command, distinct from re-running payroll. It re-derives **attendance layer 3 and
4 only** (Part XI §XI.2.5), and on a locked period it is refused; on a reopened
period it produces a settlement diff which the next run version consumes.

**R-XIV-023** Payroll never recalculates attendance implicitly. If a settlement
is `stale` (Part XI R-XI-021), the run's preview raises a **blocking** exception
naming the stale settlement, and the operator must recalculate deliberately.

### XIV.2.9 Off-cycle periods

**R-XIV-024** An off-cycle payment does **not** require an off-cycle period. It
attaches to the current open period with `run_type_code = 'off_cycle'` and its
own `lot_no`, so YTD, statutory bases and the reconciliation assertion all
compose. A genuinely separate window — a mid-month advance run for a subset — is
a period of kind `custom_fixed` with its own pay group, not a special case in
the engine.

---

## XIV.3 Proration and loss of pay *(brief Section 35)*

### XIV.3.1 All five divisor bases, selectable per component and scoped

**R-XIV-025** The proration basis is an attribute of a **component**, resolved
through a scoped, effective-dated `proration_policy` artefact, not a single
tenant string. The basis catalogue is a value set, and all five of the bases the
brief names are present at launch:

| `prorate_basis_code` | Denominator | Formula (Part VIII) | Notes |
|---|---|---|---|
| `working_days` | period days − weekly offs − working-day holidays | **FL-07** `F-PRORATE-WORKING` | SmartPRS 2's default |
| `calendar_days` | the period's calendar-day count | **FL-08** `F-PRORATE-CALENDAR` | Requires the declared conversion of §XIV.3.2 |
| `fixed_30` | `policy.fixed_day_divisor = 30` | **FL-09** `F-PRORATE-FIXED30` | |
| `fixed_26` | `policy.fixed_day_divisor = 26` | **FL-10** `F-PRORATE-FIXED26` | **Absent from SmartPRS 2 entirely** despite being its own FAQ headline (`CalcGuideController:161`) |
| `payable_days` | `settlement.scheduled_payable_days` | **FL-11** `F-PRORATE-PAYABLE` | **Recommended default**; the only basis whose numerator and denominator come from the same frozen settlement on the same day-length policy and therefore cannot disagree |
| `none` | — | — | The component is not prorated at all (a flat allowance, a fixed deduction) |

**R-XIV-026** The basis is resolvable at every scope dimension of spine S3.1 —
company, establishment, state, branch, department, grade, designation,
employee category, employment type, pay group, and the individual employee — with
S3.2 precedence. *`compute():1108-1111` reads one tenant-wide
`rates.lop_basis`, and **anything outside its three-value whitelist silently
becomes `working`**; in SmartPRS 3 an unresolvable basis is a publish-time error
and a run-time `ERR_UNRESOLVED_BASIS` blocking exception, never a fallback.*

**R-XIV-027** The five bases are **not** implemented in payroll code. They are
published formula artefacts (FL-07…FL-11), so a sixth basis a customer needs — a
contractual 22-day divisor, a basis that excludes declared holidays — is a new
formula version, not a release. *`A-payroll.md` §5.2 items 11 and 12.*

### XIV.3.2 Absence measured in one unit, valued in another — the explicit capability

**R-XIV-028** Measuring absence and valuing it are **two declarations**, and the
engine supports them being different — but only when the conversion is named,
dated and traced.

```
proration_policy
  measure_basis_code    -- the unit absence is COUNTED in
  value_basis_code      -- the unit absence is PRICED in
  conversion_policy_code-- the TO_DAYS policy that bridges them, when they differ
```

Under Part VIII's type system, a `Days` value carries its day-length policy code
(R-VIII-016), and subtracting a `WORKING`-basis `Days` from a `CALENDAR`-basis
`Days` **fails publish gate G4**. The two lawful spellings are:

```spx
-- REJECTED at publish (G4)
(settlement.scheduled_days - settlement.absent_working_days) / period.calendar_days

-- ACCEPTED: the conversion is named, dated, traced
( TO_DAYS(settlement.scheduled_days,       'CALENDAR')
- TO_DAYS(settlement.absent_working_days,  'WORKING_TO_CALENDAR') )
/ period.calendar_days
```

**R-XIV-029** `WORKING_TO_CALENDAR` is itself an effective-dated artefact naming
its numerator and denominator basis. Shipped at `1:1` — reproducing SmartPRS 2's
arithmetic exactly, so a migrating customer sees no change on day one — and
changeable to `calendar_days : working_days` (e.g. `31:27`) by configuration for
a customer who believes two absent working days should cost 2.30 calendar days.
The trace shows the conversion step either way.

*The whole of R-XIV-028/029 exists because `PayrollGenController:1268-1284`
subtracts a working-day count from a calendar-day count with **no conversion at
all**, silently, in the employee's favour, on every prorated slip under two of
the three bases (§XIV.1.2).*

### XIV.3.3 Per-component proration flags

**R-XIV-030** Every component declares, independently (Part XV §XV.2.4):

| Flag | Meaning |
|---|---|
| `prorates` | Whether a period-boundary factor (joiner, leaver, mid-period structure change) applies to this component |
| `lop_affected` | Whether unpaid-absence LOP reduces this component |
| `prorate_basis_code` | Which of the five bases values it, when it prorates |
| `arrear_eligible` | Whether a back-dated change to this component generates an arrear line |
| `min_prorated_amount` / `max_prorated_amount` | Floors and caps applied **after** proration, before rounding |

These are four separate questions and SmartPRS 2 answers none of them per
component. A statutory-driven flat deduction (`prorates = false`,
`lop_affected = false`), a fixed conveyance allowance paid whole in a joining
month (`prorates = false`, `lop_affected = true`), and a basic salary
(`prorates = true`, `lop_affected = true`) are all expressible.

**R-XIV-031** Variable earnings — commission, incentive, overtime, night
allowance, arrears, reimbursement — each carry the same flags, and their default
is `prorates = false`. Their inclusion in each statutory base is a separate
declared flag (Part XV §XV.2.5), not a consequence of the pipeline stage they
arrive in. *`compute():1350` and `:1391` add every extra **after** `computeSlip`
returns, so **no PF, ESI, PT or TDS is charged on any of them** (BR-STAT-006) —
a live compliance exposure for ESI on overtime, and the subject of D-XIV-03.*

### XIV.3.4 Joiner and leaver proration under a cut-off cycle

**R-XIV-032** Joiner and leaver proration are **one expression** — the
intersection of the employment window with the period window — evaluated against
the resolved period, never against a month label. Part VIII **FL-13**
`F-PRORATE-BOUNDARY`:

```spx
PAYABLE_DAYS( MAX(period.start, employee.joining_date),
              MIN(period.end,   COALESCE(employee.exit_date, period.end)) )
```

Worked, on a `cutoff_day = 25` cycle where the July 2026 period is
26-Jun-2026 → 25-Jul-2026 (30 days), monthly gross ₹50,000.00:

| Case | Window | Scheduled | Factor | Gross paid |
|---|---|---|---|---|
| Joined 06-Jul-2026 | 06-Jul … 25-Jul | 20d | 20/30 | ₹33,333.33 |
| Exit 10-Jul-2026 | 26-Jun … 10-Jul | 15d | 15/30 | ₹25,000.00 |
| Joined 30-Jun, exit 10-Jul | 30-Jun … 10-Jul | 11d | 11/30 | ₹18,333.33 |
| Joined 25-Jun (belongs to this period under the cut-off) | 26-Jun … 25-Jul | 30d | 1 | ₹50,000.00 |

*SmartPRS 2 gets the first case wrong (it counts working days from day 6 to day
31 **of July** — a window starting ten days after the period does and ending six
days after it ends, `:1216-1228`), has no implementation at all for the second
(NEW-PAY-12), and fails the fourth entirely because `doj->format('Y-m')` does not
match the label (DEF-P05).*

**R-XIV-033** `exit_date` is the **payroll-effective last working day** resolved
from the employment record, and it is a required input of the pipeline's employee
resolution stage. An employment whose exit date falls inside the period is
included in the run with a boundary factor; an employment whose exit date
precedes the period start is excluded and **counted and reported**, never
silently dropped.

**R-XIV-034** Both a joiner boundary and a leaver boundary may apply in the same
period, and the intersection expression handles it without a special case. A
rejoining employee within one period produces **two** employment windows and
therefore two payslip line sets under one payslip, each traced to its window.

### XIV.3.5 The zero-punch policy

**R-XIV-035** "The employee has no attendance evidence at all for the period" is
a **configurable outcome**, resolved by scope, from the value set:
`pay_in_full | treat_as_absent | hold_payslip_for_review | flag_and_pay`.
*SmartPRS 2 hardcodes `pay_in_full`: `compute():1237` skips the entire LOP block
when `present == 0`, so a genuinely absent employee is paid 100% and there is no
override at any scope (BR-PAY-013 — the analysis calls it "the single most
dangerous default in the system").* **D-XIV-01** below carries the recommendation.

### XIV.3.6 LOP presentation

**R-XIV-036** Whether LOP appears as reduced component lines or as full lines
plus a single LOP deduction is a **presentation** choice
(`lop_presentation_code` on the structure). The arithmetic is identical either
way, and the invariant `SUM(full) − SUM(prorated) = SUM(LOP lines)` holds exactly
in both (Part VIII FL-12, invariant I5). Per-component LOP amounts are computed
and stored regardless of presentation. *SmartPRS 2 produces **no per-component
LOP figure at all** — it multiplies annual CTC by a dimensionless factor — which
is precisely why the payslip has to recover Paid Days and LOP by regex from an
English sentence (DEF-P16, Part XVI).*

---

## XIV.4 The payroll engine pipeline *(brief Section 36)*

### XIV.4.1 The contract every stage satisfies

**R-XIV-037** The pipeline is the ordered stage list of spine S11. Every stage
satisfies, without exception:

1. **Pure with respect to its inputs.** Its only reads are from the run's
   **input snapshot** and the outputs of prior stages. It holds no repository,
   no connection, no container (Part VIII R-VIII-041, §8.4).
2. **Typed inputs and outputs.** Declared as a value object, not an array.
3. **Emits a trace** for every derived value (Law 4). A stage returning a value
   without a trace throws.
4. **Declared failure behaviour** from a closed set (below).
5. **Restartable** at stage granularity, per shard, idempotently.
6. **Inspectable in isolation**: `POST /api/v3/payroll/runs/{uuid}/stages/{code}/dry-run`
   returns the stage's output and trace for one employment without persisting.

**R-XIV-038** Stage failure behaviour is one of exactly four:

| Behaviour | Meaning | Effect on the run |
|---|---|---|
| `fail_subject` | This employment cannot be computed | The employment is marked failed with the reason; the run continues; the run cannot leave preview while any subject is failed |
| `fail_run` | The run's inputs are not coherent | The run aborts; nothing is persisted beyond the failure record |
| `raise_exception` | A business condition needing a human | A `payroll_exceptions` row at declared severity; the run continues; `blocking` severity stops the run at preview |
| `warn` | Informational | Recorded on the run; never stops anything |

There is **no fifth behaviour, and in particular no silent swallow.** *SmartPRS 2
destroys 50.5% of its caught exceptions (spine S5.6), and five of the regenerate
reversals are wrapped in individual try/catch blocks "so a failure never blocks"
(`:2398-2465`) — meaning a failed commission-lock reversal leaves a commission
permanently locked to a run that no longer exists.*

### XIV.4.2 The stages, specified

The table gives, for every stage of spine S11: its inputs, its outputs, its
failure behaviour, what its trace records, and its restart semantics. `Snapshot`
means "read from `payroll_run_inputs`". All money is `Money` (integer paise).

| # | Stage `code` | Inputs | Outputs | Failure | Trace records | Restart |
|---|---|---|---|---|---|---|
| 1 | `resolve_employees` | Run request (period, pay group, run type, lot, scope, explicit include/exclude list) | `payroll_run_employees` rows: employment id, window start/end, inclusion reason | `fail_run` if zero eligible; `warn` per exclusion | Each employment's inclusion or exclusion **reason code** and the predicate that produced it | Rebuild wholesale; the set is pinned to the snapshot on completion |
| 2 | `resolve_period` | Pay cycle version (Snapshot), period row | Period window, calendar days, working days, scheduled payable days, cut-offs, `pay_on`, `periods_per_year` | `fail_run` | Cycle artefact + version, kind, parameters, clamping applied | Deterministic; recompute freely |
| 3 | **`snapshot_inputs`** | Everything in §XIV.4.3 | `payroll_run_inputs` rows + `snapshot_digest` | `fail_run` | Every input kind, ref, version id and content hash | **Runs exactly once per run version.** A restart re-reads the existing snapshot; it never re-snapshots |
| 4 | `resolve_structure` | Snapshot: compensation, structure version, component versions | Per employment: the resolved line set with every per-component attribute | `fail_subject` + `raise_exception(missing_structure, blocking)` | Structure artefact + version, precedence trail (S3.2 weights and the losing candidates), each line's origin (structure / override) | Per shard |
| 5 | `attendance_settlement` | Snapshot: `period_settlements` (frozen, Part XI layer 4) | Payable days, LOP days, present days, scheduled payable days, OT minutes, late count, shortfall minutes | `fail_subject` + `raise_exception(missing_attendance \| stale_settlement, blocking)` | Settlement id, `attendance_generation_max`, freeze timestamp | Per shard |
| 6 | `leave_settlement` | Snapshot: leave charges for the period | Paid/unpaid units by leave type, day-part detail, LOP-leave units | `fail_subject`; `raise_exception(unapproved_leave, warning)` | Each leave charge, its type version, paid flag, and unit | Per shard |
| 7 | `off_holiday_settlement` | Snapshot: weekly-off pattern, holiday calendar, sandwich policy versions | Weekly-off days, holiday days, sandwich LOP days, whether offs are paid | `fail_subject` | Pattern version, each day classified, the sandwich rule's per-day decision | Per shard |
| 8 | `lop_and_proration` | Stages 5–7, proration policy (Snapshot), FL-07…FL-13 | Per-component proration factor, per-component LOP amount | `fail_subject`; `raise_exception(proration_out_of_range, blocking)` if factor ∉ [0,1] | Basis code, measure/value bases, any `TO_DAYS` conversion, numerator, denominator, exact rational, the factor | Per shard |
| 9 | `fixed_earnings` | Stage 4 lines where `category = fixed`, stage 8 factors | Earning lines with amount, quantity, rate, basis amount | `fail_subject` | Per Part VIII §10 — formula version, substituted values, intermediate steps, rounding mode | Per shard; topological within the shard |
| 10 | `variable_earnings` | Snapshot: approved `variable_pay_inputs` for the period | Earning lines per input, one line per declared purpose | `raise_exception(unapproved_variable_input, blocking)` for any input in a non-approved state | Input id, approval instance, the amount as approved | Per shard |
| 11 | `overtime` | Stage 5 OT minutes, OT rate + multiplier formulas (FL-05, FL-06) | OT earning line(s) by multiplier band | `fail_subject` | Hourly rate derivation **naming its divisor artefact**, minutes, multiplier, band | Per shard |
| 12 | `compoff_encashment` | Snapshot: comp-off ledger balance, FL-15 | Encashment earning line | `raise_exception(compoff_expiry_conflict, warning)` | Units, valuation basis, ledger entries consumed | Per shard |
| 13 | `commission` | Snapshot: approved commission entries whose payout period is this one | One earning line per commission purpose | `raise_exception(commission_lock_conflict, blocking)` | Entry ids, purpose, gross, TDS already withheld, net | Per shard |
| 14 | `incentive` | Snapshot: approved scheme claims and bulk incentive rows | Earning lines | as above | Scheme version, claim ids, cap consumption | Per shard |
| 15 | `reimbursement` | Snapshot: approved claims, FL-27 caps | Reimbursement lines (own component kind) | `raise_exception(reimbursement_over_cap, warning)` | Claim ids, cap, carried-forward balance | Per shard |
| 16 | `arrears` | Snapshot: arrear instructions + `prior_run.snapshot` per target period | Arrear lines, tagged `arrear_for_period_code` | `fail_subject` if a target period's pinned run is missing | The **original** period's line, the **recomputed** line at the new versions, the difference, and both version sets (FL-24) | Per shard; per target period |
| 17 | `statutory_deductions` | Snapshot: rule pack version; wage bases from declared flags (`salary.wage_base('PF' \| 'ESI' \| 'PT' \| …)`) | Employee-side statutory lines | `fail_subject` + `raise_exception(unmapped_statutory, blocking)` | Pack id + version + signature key id, rule code, eligibility expression and its result, base composition **listing the contributing component codes**, slab walked, rounding | Per shard |
| 18 | `income_tax` | Snapshot: declarations, prior-employer income, regime election, `ytd.prior`; rule pack | TDS line | `fail_subject`; `raise_exception(invalid_tax_data, blocking)` | Regime, projected annual income and its build-up, exemptions, deductions, slab walk, cess, relief, YTD already deducted, remaining periods, this period's instalment | Per shard |
| 19 | `loans_advances` | Snapshot: loan schedules, advance schedules, running net | Recovery lines | `raise_exception(loan_inconsistency, blocking)` when the schedule and the ledger disagree | Schedule id, instalment number, due, applied, shortfall, shortfall policy, resulting ledger entry | Per shard; ledger writes are idempotent on `(run_version, schedule, instalment_no)` |
| 20 | `other_recoveries` | Snapshot: clawbacks, notice recovery, asset recovery, court attachment | Recovery lines | as above | As above, per recovery type, in the **configured** priority order | Per shard |
| 21 | `net_pay` | All prior lines; minimum-net floor policy (FL-28) | Net amount; deferred-recovery entries | `raise_exception(negative_net, blocking)`; `raise_exception(net_below_floor, per policy)` | Earnings total, deductions total, net, floor applied, what was deferred and to which period | Per shard |
| 22 | `employer_contributions` | Snapshot: rule pack; employer-side flags | Employer-cost lines (never deducted) | `fail_subject` | As stage 17, employer side | Per shard |
| 23 | `ctc_reconciliation` | Stages 9–22, the structure's `ctc_definition_code` | Per-employment CTC actual vs contracted, variance | `raise_exception(ctc_variance, warning)` above a configured threshold | The component set summed into CTC, by flag, with each amount | Per shard |
| 24 | `reconcile_run` | Every payslip and line | Reconciliation results (§XIV.5.6) | **`fail_run` on any failure** | Each check code, expected, actual, difference in paise | Whole run; must re-run after any shard change |
| 25 | `generate_payslips` | Everything above | `payslips` + `payslip_lines` + `calculation_traces` | `fail_run` | Payslip number allocation, template resolution (Part XVI) | Per shard, idempotent on `(run_id, employment_id)` |

**R-XIV-039** The stage list, their order and their declared failure behaviours
are **platform data** (`payroll_pipeline_stages`), versioned with the engine, not
tenant-editable. A tenant may not reorder, insert or remove a stage. *Reordering
statutory computation relative to variable earnings changes what is taxed, which
is a compliance decision, not a preference.*

**R-XIV-040** A run records `stage_state` per (stage, shard):
`pending | running | completed | failed`, with counts and elapsed time. Restart
resumes from the first non-`completed` stage. Completed stages are **never**
re-executed on a restart, because their inputs are pinned and their outputs are
written idempotently. *`generate()` is one 511-line method with a single
transaction (`:2334-2845`); a failure in the last employee discards the first
five thousand, and there is no resume.*

**R-XIV-041** Sharding is by employment, deterministically assigned
(`shard_no = hash(employment_uuid) mod shard_count`), with `shard_count` fixed at
run creation and stored on the run — so a restart with different worker
concurrency produces the identical partition and therefore the identical
allocation residues. *Because `ALLOCATE` residue-to-last is order-dependent by
design (Part VIII §6.4), and a non-deterministic partition would make a re-run
differ by paise.*

**R-XIV-042** The pipeline **never issues DDL**. Law 2, tested by the CI grep.
*`generate():2556-2616` issues up to five DDL statements before its transaction,
deliberately outside it because MySQL DDL implicitly commits, and
`SalaryApprovalController::ensureCols()`/`ensureLineCols()` do the same on list
and sheet views (DEF-P29). This requires the production DB user to hold DDL
privileges and makes the schema depend on click history.*

### XIV.4.3 The input snapshot, in full

This is spine S11's critical step and the single mechanism that makes a re-run
reproducible. It is stage 3, it runs exactly once per run version, and it writes
`payroll_run_inputs` (Part XXVIII C7) — one row per resolved input, each carrying
`input_kind_code`, `input_ref_table`, `input_ref_id`, `input_version_id` and a
**content hash** `input_hash`.

**R-XIV-043** The snapshot captures **exactly** the following. Nothing the
pipeline reads may be absent from it.

**Run-wide (one row each):**

| `input_kind_code` | What is pinned | Why |
|---|---|---|
| `pay_cycle` | Cycle artefact id + `config_version_id` + resolved period parameters | A cut-off change must not re-slice a run |
| `period` | `payroll_periods` row id + its dates + `pay_on` | The window is a fact, not a derivation |
| `statutory_pack` | One row **per (jurisdiction, statute, establishment)** pair in the run: pack id, `pack_version`, signature key id, `effective_from` | A pack accepted after the run must not change it |
| `proration_policy` | Policy artefact + version, per resolved scope | |
| `day_length_policies` | Every `TO_DAYS` policy version referenced | The conversion of §XIV.3.2 is dated |
| `weekly_off_pattern`, `holiday_calendar`, `sandwich_policy` | Artefact + version per resolved scope | |
| `rounding_policy` | The platform rounding declarations in force | |
| `recovery_priority_policy` | Artefact + version | Recovery order is configuration (R-XIV-062) |
| `minimum_net_policy` | Artefact + version | |
| `engine_version` | Platform version + pipeline stage-list version | An engine change is visible as a changed input |
| `exchange_rates` | Where any component is in a non-base currency: rate + rate date | No implicit FX, ever (Part VIII §5.2) |

**Per employment (one row each, `employment_id` populated):**

| `input_kind_code` | What is pinned |
|---|---|
| `compensation` | `employee_compensations` row id + `effective_from` + `annual_ctc` + `structure_config_version_id` + `pay_group_code`, **as at the period**, not as at today |
| `compensation_lines` | Every per-employee component override row, hashed |
| `assignment` | `employment_assignment_id` — the org and classification assignment **as at the period**: company, establishment, state, branch, department, team, cost centre, designation, grade, category, employment type, employment stage, location, shift, pay group |
| `employment_facts` | Joining date, exit date, confirmation date, stage, gender, date of birth, PAN/UAN/ESIC presence flags, bank-detail presence flag |
| `structure` | Structure artefact + `config_version_id` |
| `component` | One row **per component version** in the resolved structure |
| `formula` | One row **per formula version** any of those components resolve to, including transitively (Part VIII §7.1 dependency graph) |
| `rule` | One row per attendance, leave, work-time or payroll rule version that contributed to the settlement or to any `ADD_COMPONENT` / `SUPPRESS_COMPONENT` / `SET_BASIS` / `CAP` action |
| `attendance_settlement` | `period_settlement_id` + `attendance_generation_max` + its frozen-at timestamp + a hash of its measures |
| `leave_ledger` | Leave charges for the period, hashed; balances as at the period |
| `statutory_profile` | The employment's statutory applicability facts: PF/ESI/PT/LWF membership, scheme numbers, the **period-scoped eligibility locks** of R-XIV-045 |
| `tax_profile` | Regime election, declaration version, previous-employer income, `ytd.prior` aggregate and its constituent payslip ids |
| `loan_schedule` | Every active loan/advance schedule id + version + outstanding as at period start |
| `variable_input` | One row per approved variable input consumed, with its approval workflow instance id |
| `ledger_balances` | Comp-off, reimbursement-carry and recovery balances as at period start |
| `prior_run` | For an arrears line: the pinned original run version id for each target period |

**R-XIV-044** `snapshot_digest` is `SHA-256` over the canonical serialisation of
every snapshot row, ordered by `(input_kind_code, input_ref_table, input_ref_id,
employment_id)`, money rendered as signed minor units. It is stored on the run.

**R-XIV-045** Period-scoped statutory eligibility determinations — the ESI
contribution-period lock being the canonical case — are **stored structured
facts** in `employee_period_statutory_locks` (scheme code, period range,
determination, determining run version, determined_at) and are snapshotted.
*`esiPeriodLock():2011-2046` decides a statutory obligation by scanning stored
payslip JSON for a **deduction key containing the substring `esi`** (`:2036`), so
a customer who renamed the line has had the lock silently disabled ever since;
the same query has no tenant filter (SEC-P08).*

**R-XIV-046 — the re-run contract.** Given the same snapshot, a re-run of a run
version produces a **byte-identical** result: identical payslip lines, identical
paise, identical allocation residues, identical trace values (excluding the
trace's own timestamps). This is asserted by a test (`T-XIV-30`) that runs a
50-employee fixture twice and diffs.

**R-XIV-047 — the changed-input contract.** Creating a **new run version** for a
period re-snapshots and then **diffs slice by slice** against the prior version's
snapshot. The diff is presented before the run may proceed:

```
Run 2026-07 · lot 1 · version 2  —  inputs changed since version 1

  statutory_pack   IN-TG / pt   v2026.3 → v2026.4   (accepted 04-Aug-2026)
                   affects 412 employees · projected Δ net −₹8,240.00
  compensation     3 employments  (increment approved 02-Aug, effective 01-Jul)
                   affects 3 employees · projected Δ net +₹41,660.00
  attendance_settlement  1 employment  (settlement re-derived, generation 3 → 4)
                   affects 1 employee  · projected Δ net −₹1,612.90
  structure        unchanged
  formula          unchanged
  rule             unchanged
```

**R-XIV-048** The diff is **mandatory to acknowledge** before a new run version
may leave preview when any input changed. The acknowledgement is a workflow
comment, attributed and audited. *This is the structural form of spine S4's
promise that August payroll keeps using Late Policy V1 after V2 is published: not
discipline, a resolution date plus a pinned version plus a visible diff.*

**R-XIV-049** An input version that is **superseded but was pinned** can never be
hard-deleted. `ON DELETE RESTRICT` from `payroll_run_inputs` to
`config_versions`, from `payslip_lines` to `cfg_salary_components`, and from every
snapshot ref. *`salary_components` in SmartPRS 2 is **hard-deleted** by its master
screen, which destroys the ability to re-explain every payslip computed from it.*

### XIV.4.4 Determinism, budgets and performance

**R-XIV-050** Every stage is evaluated against a **prepared, immutable context
bundle** assembled per shard (Part VIII §8.4): one query per slice per shard,
never per subject. The evaluator holds no repository. `context_digest` is stored
on every trace.

**R-XIV-051** The pipeline has declared budgets: a per-subject node budget and
timeout inherited from SPX (spine S5.6), a per-stage wall-clock budget, and a
per-run total. Exceeding a per-subject budget is `fail_subject` with
`ERR_BUDGET`; exceeding a run budget parks the run as `stalled` with the stage
and shard named. No budget failure is silent.

**R-XIV-052** A run of 10,000 employments completes stages 1–25 within the
declared SLO on the reference hardware profile, and the run's stage timings are
recorded so a regression is visible. *`liveSalaryAll()` caps itself at 300
employees (`:1940-2069`) because the per-employee query pattern does not scale;
the pipeline's slice-per-shard loading is the structural fix.*

---

## XIV.5 Payroll preview and the staged lifecycle *(brief Section 37)*

### XIV.5.1 The lifecycle

```
input_validation → preview → exceptions → draft → review
   → hr_approval → finance_approval → lock → disburse → payslip → acknowledgement
```

**R-XIV-053** The lifecycle states and their permitted transitions are
**configuration** (`payroll_run_states`, `payroll_run_transitions` — reference
tables, spine S16: no database `ENUM`). The approvals are **not** a state column;
they are nodes of the `PAYROLL-APPROVE` workflow instance bound to the run
(Part IX §IX.12.3). There is **zero payroll-specific approval code**.
*`SalaryApprovalController` hardcodes six run states, seven line states, five
actions and the role names satisfying each stage (BR-PAY-716, BR-PAY-025); a
third approval level, a parallel approver, delegation or amount-based routing
each require a release.*

| Stage | What must be true to enter | What it produces | Who |
|---|---|---|---|
| `input_validation` | Period exists, is not locked, cycle resolves, scope resolves to ≥1 employment | The employment set and the reasons for every exclusion | `payroll.run.create` |
| `preview` | `input_validation` clean | The **complete** computation, persisted as run version *n*, with every payslip line and trace — but `is_preview = 1`, so it is not issuable | `payroll.run.create` |
| `exceptions` | — | The exception list (§XIV.5.3), each `blocking` or `warning`, each with its resolution path | `payroll.run.exception.resolve` |
| `draft` | **Zero unresolved `blocking` exceptions** and the reconciliation assertion passes exactly | The run becomes submittable | `payroll.run.submit` |
| `review` | `draft` | The salary sheet, per-line hold/review/remark, the variance report against the prior period | `payroll.run.review` |
| `hr_approval` | Workflow node `n_hr` | HR sign-off with mandatory comment and totals acknowledgement | `payroll.approve.hr` |
| `finance_approval` | `n_finance` / `n_finance_senior` by variance band | Finance sign-off | `payroll.approve.finance` |
| `lock` | `n_lock` | The period freezes (§XIV.2.6) | `payroll.run.lock` |
| `disburse` | Locked; a **separate, explicit** command | Payout instructions and, on confirmation, `disbursed` lines | `payroll.disburse` |
| `payslip` | Locked | Payslips published (Part XVI) | automatic |
| `acknowledgement` | Published | Employee acknowledgement captured | the employee |

**R-XIV-054** **Preview computes the whole run and persists it.** It is not a
second, lighter calculator. *`PayrollGenController::preview():2292-2331` and
`generate():2334-2845` are separate paths; `simulate():2079-2243` is a third with
a **differently-shaped net assembly** (`A-payroll.md` §4.15) and a duplicated OT
formula; `liveSalary()` and `liveSalaryAll()` are a fourth and fifth copy of the
factor logic which diverge in the zero-punch branch and hardcode
`esi_lock => false` (§5.3 Q8 items 11–12). SmartPRS 3 has **one** calculator, and
preview, simulate, live-salary and the final run are all invocations of it with
different persistence and publication flags.*

**R-XIV-055** Disbursement is a **separate, permissioned, workflow-gated
command**, never a side effect of producing a file (Part IX R-IX-074). Bank-file
generation is a pure read. *`bankFile():482-504` is a **GET** that stamps
`bank_filed_at` and flips every `approved` line to `disbursed`, writing
`line_status` directly and bypassing `applyLineAction()` — so the guard
"a line may be disbursed only after its parent run cleared HR approval"
(`:583-588`) is never applied, the employee notification never fires, and the
whole operation is CSRF-unprotected and prefetchable (DEF-P10, SEC-P04).*

### XIV.5.2 Per-line lifecycle

**R-XIV-056** A payslip line within a run has its own state
(`draft | held | in_review | approved | disbursed | acknowledged | rejected`),
with configured transitions **including reverse transitions** where the tenant
permits them, and with every transition audited (actor, time, IP, reason).
*SmartPRS 2's line machine has six states, five actions and **no reverse
transition of any kind** (NEW-PAY-27); a line held in error can only be fixed by
destroying and regenerating the run.*

**R-XIV-057** A held or rejected line **does not contribute to the run totals or
to the payout instruction**, and the totals are recomputed on every line-state
change with the reconciliation assertion re-run. *`payroll_runs.net_total` is
written once at generate and never corrected, so the header overstates the
disbursement whenever a line is later held (DEF-P17).*

### XIV.5.3 The exception catalogue

**R-XIV-058** `payroll_exceptions` rows carry `exception_code`, `severity_code`
(`blocking | warning | info`), `detail_json`, and a resolution stamp. **Severity
is configuration per code per scope**, with a platform default and a declared
floor: the codes marked ▣ below may never be downgraded below `blocking` by a
tenant.

The brief's list, in full, plus what the analysis found:

| `exception_code` | Raised when | Default severity | Resolution path |
|---|---|---|---|
| `missing_bank_details` ▣ | No account number or IFSC on an employment due a net payment | blocking | Employee master; `EMP-BANK-CHANGE` workflow |
| `missing_pan` | No PAN where the tax profile requires one | blocking | Employee master; or accept the higher no-PAN rate as a recorded decision |
| `missing_uan` | PF-covered with no UAN | blocking | Employee master |
| `missing_esic_number` | ESI-covered with no insurance number | blocking | Employee master |
| `missing_attendance` ▣ | No frozen settlement for the employment and period | blocking | Freeze the period settlement |
| `stale_settlement` ▣ | Settlement's `attendance_generation_max` is behind layer 3 | blocking | `recalculate` then re-freeze |
| `missing_structure` ▣ | No structure version resolves at the period | blocking | Assign a structure; or exclude with reason |
| `missing_compensation` ▣ | No `employee_compensations` row effective at the period | blocking | Create the dated compensation |
| `negative_net` ▣ | Net &lt; 0 before the floor policy | blocking | Adjust recoveries; apply the deferral policy |
| `minimum_wage_breach` ▣ | The minimum-wage-comparable component sum is below the resolved statutory floor | blocking | Structure or compensation change; or a recorded compliance exception where the rule pack permits one |
| `unmapped_statutory_policy` ▣ | An establishment has no applicability determination for a statute the pack declares in scope | blocking | Statutory applicability configuration |
| `unapproved_variable_input` ▣ | A variable input targeting this period is not in an approved state | blocking | Approve, or defer to the next period |
| `duplicate_run` ▣ | A run of the same (period, pay group, type, lot) already exists in a non-superseded state | blocking | Create a new **version**, not a new run |
| `loan_inconsistency` ▣ | Schedule and ledger disagree; outstanding &lt; 0; instalments paid &gt; total | blocking | Loan reconciliation |
| `invalid_tax_data` ▣ | Regime not elected; declaration inconsistent; prior-employer income missing where declared | blocking | Tax declaration workflow |
| **Found by the analysis, added here:** | | | |
| `missing_pay_cycle` ▣ | No pay cycle resolves for the pay group at the period | blocking | Configuration (§XIV.2.1) |
| `period_continuity_break` ▣ | The period does not abut the prior paid period | blocking | Bridging period (R-XIV-012) |
| `zero_punch_employment` | No attendance evidence at all | per the R-XIV-035 policy | Policy decision, per D-XIV-01 |
| `unresolved_proration_basis` ▣ | Basis does not resolve, or resolves ambiguously | blocking | Configuration; **never a fallback to `working`** (§XIV.1.2) |
| `factor_out_of_range` ▣ | Proration factor ∉ [0,1] | blocking | Investigate; a platform bug |
| `structure_does_not_reconcile` ▣ | Component lines do not sum to the declared gross | blocking | Part XV publish gate should have caught it |
| `component_version_disabled` ▣ | A resolved line references a disabled component version | blocking | Structure fix |
| `esi_period_lock_conflict` | The stored lock and the current computation disagree | blocking | Statutory review (R-XIV-045) |
| `statutory_pack_missing` ▣ | No signed pack for a jurisdiction in the run | blocking | Accept the pack |
| `statutory_pack_unaccepted` | A newer pack is available but not accepted, and its effective date precedes the period | warning | Accept or defer explicitly |
| `employer_policy_below_statutory_floor` | A company policy would pay less than the pack baseline | per pack rule severity | Spine S12 |
| `arrear_target_period_missing` ▣ | An arrear names a period with no pinned run | blocking | |
| `arrear_target_period_open` | An arrear targets a period that is not locked | warning | |
| `ctc_variance_exceeded` | Computed CTC deviates from contracted beyond the threshold | warning | |
| `net_variance_exceeded` | Employment's net differs from the prior period beyond the threshold | warning | Feeds the `n_variance` routing of `PAYROLL-APPROVE` |
| `exit_without_settlement` | Exit date in the period with no `EXIT-FNF` instance | blocking | |
| `rejoin_in_period` | Two employment windows in one period | info | |
| `multi_company_employment` | The employment moved company mid-period | warning | The split is computed; the operator confirms |
| `recovery_deferred` | A recovery could not be taken and was carried | info | |
| `foreign_currency_component` | A component in a non-base currency with no dated rate | blocking | |
| `settlement_reopened_after_run` | The settlement changed after this version snapshotted | blocking | New run version |

**R-XIV-059** An exception is resolved by a **named action**, not by a checkbox.
Every resolution records the actor, the time, the action taken and a mandatory
note where the severity is `blocking`. Resolutions are audited (spine S14).

**R-XIV-060** The run may not enter `draft` while any `blocking` exception is
unresolved. This is a precondition of `n_submit` on `PAYROLL-APPROVE`
(Part IX §IX.12.3), evaluated server-side.

### XIV.5.4 Run versioning, lots and run types

**R-XIV-061** A run is identified by
`(tenant, payroll_period_id, pay_group_code, run_type_code, lot_no,
run_version)` with a **database unique constraint** (Part XXVIII). Regeneration
creates `run_version + 1`, sets `supersedes_run_id` on the new version, and
**preserves the prior version entirely** — its payslips, its per-line statuses,
its approval stamps, its rejection remarks and its trace — as historical facts of
a superseded version (Part IX R-IX-076). Nothing is deleted, ever.
*SmartPRS 2 `DELETE`s the payslips and the run row (`:2389-2390`), losing the
rejection remark, the approval stamps and every line status; and it has no unique
constraint at all, so a second click during a multi-minute generate produces two
runs and two payslips per employee.*

| `run_type_code` | Semantics |
|---|---|
| `regular` | The period's main run |
| `off_cycle` | An out-of-band payment in the current period; own lot; participates in YTD and statutory bases |
| `supplementary` | A correction or addition to an already-locked period, computed as a **delta** against the pinned prior run, never as a replacement |
| `arrears_only` | Back-dated settlement across one or more target periods; every line tagged `arrear_for_period_code` |
| `final_settlement` | Driven by `EXIT-FNF`; includes terminal payments and recoveries |
| `bonus` | A statutory or ex-gratia bonus run, with its own components and its own statutory treatment |
| `advance` | A mid-period advance against the period's earnings, recovered in the regular run of the same period |

**R-XIV-062** Multiple **lots** per (period, pay group, run type) are
first-class: `lot_no` participates in the unique key, and lots may be defined by
any scope predicate (a branch, a category, an explicit list). Every downstream
aggregate — YTD, statutory bases, registers, the reconciliation assertion —
composes across lots **and de-duplicates by (employment, period, run version)**.
*`payroll_runs.lot` exists in SmartPRS 2 and is **hardcoded to 1** at `:2542`;
`payslipYtd():1557-1584` has no tenant, company or run filter and no
de-duplication, so with lots it would double-count (NEW-PAY-34, SEC-P09).*

**R-XIV-063** Recovery **order** is configuration — a `recovery_priority_policy`
artefact listing recovery kinds in order with, per kind, a shortfall policy
(`skip_month | partial | carry_forward | spread_over_n`) and an optional cap as a
percentage of net. *`compute():1350-1389` fixes statutory → loan EMI → advance →
clawback in code; loans are **full-EMI-only** so an EMI that does not fit skips
the month entirely (BR-PAY-020); an advance is recovered in full in the month it
was created and **any shortfall is noted in prose and never retried**
(BR-PAY-022, DEF-P19); a clawback is attempted **exactly once** (BR-PAY-023).
None of the three is configurable.*

**R-XIV-064** Every recovery writes to a **run-version-keyed ledger** supporting
clean reversal, for every recovery kind. *Loans and clawbacks have one; **advances
do not** (BR-PAY-033), so an advance recovery cannot be reversed at all.*

### XIV.5.5 Off-cycle, supplementary and arrears runs, specified

**R-XIV-065** A **supplementary** run against a locked period computes the
period afresh at the *original* snapshot, applies the named amendments, and posts
only the **difference** per component, tagged to the original period. It never
re-issues the original payslip; it issues a supplementary payslip that references
it (Part XVI §XVI.7).

**R-XIV-066** An **arrears** run resolves, per target period, the pinned original
run version from `prior_run.snapshot`, recomputes that period at the **new**
versions, and posts the difference (Part VIII FL-24). Both version sets appear in
the trace, and the arrear line carries `arrear_for_period_code`.
*`IncrementController:357-421` implements a private proration model — first month
by calendar days regardless of the tenant's basis, whole months thereafter —
writes the result as an **approved commission entry** with
`purpose = 'Salary Arrears'` and **`tds_rate = 0`**, so arrears are paid entirely
untaxed; and it fires only when `newCtc > oldCtc`, so a back-dated **decrease**
produces nothing and an overpayment is never recovered (BR-PAY-017, D-XIV-06).*

**R-XIV-067** Arrears posting respects the component's `arrear_eligible` flag and
its `arrear_component_code` (Part XV), so a customer may post arrears to a
distinct "Basic Arrears" component or into the component itself. The choice is
configuration; the arithmetic is identical.

### XIV.5.6 The reconciliation assertion

**R-XIV-068** Stage 24 runs, exactly, these checks. Every one is exact integer
paise arithmetic. **Any failure aborts the run** — the run enters `failed`, no
payslip is issued, and the failure names the check, the employment and the
difference to the paise.

| `check_code` | Assertion |
|---|---|
| `RC-01` | For every payslip: `SUM(lines where kind = earning) = payslips.gross_earnings` |
| `RC-02` | For every payslip: `SUM(lines where kind = deduction) = payslips.total_deductions` |
| `RC-03` | For every payslip: `gross_earnings − total_deductions = net_pay` |
| `RC-04` | For the run: `SUM(payslips.gross_earnings) = payroll_runs.total_earnings` |
| `RC-05` | For the run: `SUM(payslips.total_deductions) = payroll_runs.total_deductions` |
| `RC-06` | For the run: `SUM(payslips.net_pay) = payroll_runs.total_net` |
| `RC-07` | For the run: `SUM(employer-contribution lines) = payroll_runs.total_employer_cost` |
| `RC-08` | For every payslip: `SUM(component amounts before proration) − SUM(after) = SUM(LOP lines)` (Part VIII invariant I5) |
| `RC-09` | For every balance/residue allocation: the parts sum exactly to the whole (Part VIII `ALLOCATE`) |
| `RC-10` | For every recovery: `SUM(ledger entries for this run version) = SUM(recovery lines)` |
| `RC-11` | For the run: `COUNT(payslips) = payroll_runs.employee_count = COUNT(payroll_run_employees where included)` |
| `RC-12` | Every payslip line references a component version present in the snapshot |
| `RC-13` | Every derived line has a non-null `trace_id` (Law 4) |
| `RC-14` | No line amount is stored at a precision the component's rounding declaration forbids |
| `RC-15` | For every statutory head: `SUM(payslip statutory lines) = SUM(statutory_contributions)` |
| `RC-16` | Payout instruction total = `SUM(net_pay of lines in a disbursable state)` |
| `RC-17` | Currency is uniform per payslip; no cross-currency sum occurred |

**R-XIV-069** A deliberate ₹0.01 perturbation injected into any line must cause
the corresponding check to fail. This is a standing test (`T-XIV-24`), not a
belief. *SmartPRS 2 has **no reconciliation check of any kind** — `net_total` is a
running float sum never compared to `SUM(payslips.net)`, and the bank-file total
is computed twice by two different float paths with no cross-check (DEF-P17,
DEF-P07).*

**R-XIV-070** `RC-16` is re-asserted immediately before a payout instruction is
generated and again before it is marked confirmed. A mismatch blocks the payout
and raises an operator alert.

---

## XIV.6 Explainability *(spine S15, Law 4)*

**R-XIV-071** Every figure on a payslip, on the salary sheet, on the run header
and on any register drawn from a run answers *"How was this calculated?"* through
the single `Explain(entity, field)` service. Nothing is computed for display that
was not computed and traced by the pipeline.

A payslip line's trace, rendered:

```
Basic                                                        ₹23,148.15

  Component      BASIC · "Basic Salary"
                 cfg_salary_components v7, published 01-Apr-2026,
                 effective 01-Apr-2026 → (structure "Telangana Collections" v7)
  Formula        F-BASIC-PCT-GROSS v3
                 salary.gross % policy.value('basic_pct_of_gross')
                 = ₹50,000.00 × 50%
                 = ₹25,000.00                       (full-period amount)
  Proration      basis: payable_days  (F-PRORATE-PAYABLE, FL-11)
                 policy "Collections proration" v2, effective 01-Jul-2026 →
                 resolved by scope: grade=TELECALLER (weight 340)
                                     beat state=TG (weight 140)
                 payable 25d / scheduled 27d, both on day-length policy WORKING
                 exact 62,500,000 / 27 = 2,314,814.814… paise
  Rounding       half_up, 0.01  →  2,314,815 paise
  Amount         ₹23,148.15
  LOP line       ₹1,851.85   (BASIC-LOP, presentation: separate_lop_line)

  Inputs pinned  compensation #4471 eff 01-Apr-2026 · CTC ₹6,00,000
                 settlement #90233 generation 2, frozen 01-Aug-2026 06:14
                 period 2026-07 = 01-Jul → 31-Jul (cycle "TG Monthly" v4)
  Context digest 9f3c…a17b
```

**R-XIV-072** The run header explains itself: which employments were included and
**why each excluded one was excluded**, which exceptions were raised and how each
was resolved, which inputs changed since the prior version, and each
reconciliation check's expected and actual value.

**R-XIV-073** A trace is stored, not regenerated (`calculation_traces`,
partitioned monthly). Regenerating a trace from current configuration would
reproduce the defect this part exists to remove.

**R-XIV-074** The in-app calculation guide is **generated from the resolved rule,
formula and policy artefacts** for the tenant, not hand-written. *Four statements
in `CalcGuideController` already contradict the engine — the ÷26 FAQ headline,
the "attendance period is always the calendar month" claim at `:183` which also
contradicts `:75` of the same document, the loans-auto-close promise at `:116`,
and "statutory rates never hardcoded" at `:85` (§8.2). A client-facing promise
about engine behaviour that drifts from the engine is a liability.*

---

## XIV.7 Configuration surface

Tested against the brief's Section 127 standard. Every row is changeable by a
tenant administrator, through a UI, without a code release.

| # | What an administrator changes | Where | Scope | Dated |
|---|---|---|---|---|
| 1 | Pay cycle: kind, period start/end day, cut-off day, attendance cut-off, variable-input cut-off, pay-day rule and value, holiday shift, label rule, day-overflow mode | Pay Cycles | any S3.1 scope | Yes |
| 2 | Which pay group an employment belongs to | Employment assignment | employee | Yes |
| 3 | Period generation horizon and the bridging-period policy | Pay Cycles | tenant | Yes |
| 4 | Proration: measure basis, value basis, conversion policy, per component | Proration Policies + Component | any S3.1 scope | Yes |
| 5 | `fixed_day_divisor` (30, 26, 22, any) | Proration Policies | any scope | Yes |
| 6 | Per-component `prorates`, `lop_affected`, `arrear_eligible`, floors, caps, rounding | Salary Components (Part XV) | component version | Yes |
| 7 | Zero-punch outcome | Attendance/Payroll Policies | any scope | Yes |
| 8 | LOP presentation (reduce lines / full lines plus LOP deduction) | Salary Structure | structure version | Yes |
| 9 | Recovery order and per-kind shortfall policy and cap | Recovery Priority Policy | any scope | Yes |
| 10 | Minimum-net floor: absolute, percentage of gross, or both; and the deferral policy | Minimum Net Policy | any scope | Yes |
| 11 | Run lifecycle states and transitions, including reverse transitions | Payroll Run States | tenant | Versioned |
| 12 | The approval chain: stages, participants, amount/variance bands, SLA, escalation, delegation, segregation | Workflows (Part IX) | tenant | Versioned |
| 13 | Exception severity per code (subject to the ▣ floor) | Payroll Exception Policy | any scope | Yes |
| 14 | Variance thresholds driving `n_variance` routing and `net_variance_exceeded` | Payroll Policies | any scope | Yes |
| 15 | CTC-variance threshold | Payroll Policies | any scope | Yes |
| 16 | Lot definitions per period | Run creation | per run | n/a |
| 17 | Which run types are enabled and who may create each | Permissions | tenant | n/a |
| 18 | Whether a period may be reopened at all, and the reopen workflow's shape | Workflows | tenant | Versioned |
| 19 | Statutory applicability per establishment; company policy above the statutory floor | Statutory Configuration (spine S12) | establishment | Yes |
| 20 | Payslip publication timing relative to lock, and the acknowledgement requirement | Payslip Policy (Part XVI) | any scope | Yes |

**The Section 127 test, applied to this part.** *"3 late arrivals over 10 minutes
= half day"* is a Part XI/VII rule; its payroll consequence — that a half-day
penalty reduces `settlement.payable_days` by `0.5`, which the proration basis
values through FL-11 and which shows on the payslip as a traced LOP line — needs
**no payroll code**. The path is: rule action `APPLY_DAY_PENALTY(0.5)` → layer 3
→ layer 4 settlement → snapshot → stage 8 → stage 9 → payslip line + trace.

---

## XIV.8 Data model

To the Section 123 standard. Tables already specified in **Part XXVIII C7**
(`payroll_periods`, `payroll_runs`, `payroll_run_inputs`, `payroll_run_employees`,
`payslips`, `payslip_lines`, `payroll_exceptions`, `payroll_reconciliations`,
`variable_pay_inputs`, `employee_compensations`, `cfg_pay_cycles`,
`cfg_salary_components`, `cfg_salary_structures`, `cfg_structure_lines`,
`calculation_traces`) are **referenced, not restated**. This section specifies the
tables this part adds, and the columns it adds to Part XXVIII's tables.

**Vocabulary reconciliation with Part XXVIII.** Two value sets are *extended*
here, not contradicted, and the extension is recorded in the migration:

| Value set | Part XXVIII | This part | Note |
|---|---|---|---|
| `payroll_periods.lifecycle_state_code` | `open \| in_run \| locked \| reopened` | adds `cutoff_passed` between `open` and `in_run` | The attendance cut-off passing is a distinct, observable period state (§XIV.2.6) and drives the `attendance_cutoff` lock row |
| `payslips.line_status_code` | `draft \| held \| reviewed \| approved \| disbursed \| acknowledged` | adds `in_review` (Part XXVIII's `reviewed` is the completed state; `in_review` is the pending one) and `rejected` | Both are reference-table rows, not database `ENUM`s (spine S16), so the extension is data. The per-line machine and its reverse transitions are configuration (R-XIV-056) |

### XIV.8.1 Columns added to `payroll_runs`

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `snapshot_digest` | BINARY(32) | Y | NULL | Set by stage 3; NULL until then |
| `shard_count` | SMALLINT UNSIGNED | N | 1 | Fixed at creation; makes restart deterministic (R-XIV-041) |
| `pipeline_stage_list_version` | VARCHAR(20) | N | — | The platform stage list this run executed |
| `engine_version` | VARCHAR(40) | N | — | |
| `is_preview` | TINYINT(1) | N | 1 | Cleared when the run enters `draft` |
| `input_diff_acknowledged_at` | DATETIME(3) | Y | NULL | R-XIV-048 |
| `input_diff_acknowledged_by_user_id` | BIGINT UNSIGNED | Y | NULL | FK `users(id)` RESTRICT |
| `net_variance_pct` | DECIMAL(9,4) | Y | NULL | Against the prior locked run of the same pay group; drives `n_variance` |
| `failed_stage_code` | VARCHAR(40) | Y | NULL | |
| `failure_reason` | VARCHAR(400) | Y | NULL | |

### XIV.8.2 `payroll_run_stages` — A4

**Purpose.** Per-run, per-stage, per-shard execution state; the restart record.

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK |
| `tenant_id` | BIGINT UNSIGNED | N | — | Law 6 |
| `payroll_run_id` | BIGINT UNSIGNED | N | — | FK `payroll_runs(id)` RESTRICT |
| `stage_code` | VARCHAR(40) | N | — | From the platform stage list |
| `stage_no` | SMALLINT UNSIGNED | N | — | |
| `shard_no` | SMALLINT UNSIGNED | N | 0 | |
| `state_code` | VARCHAR(40) | N | `pending` | `pending\|running\|completed\|failed\|skipped` |
| `subjects_in`, `subjects_out`, `subjects_failed` | INT UNSIGNED | N | 0 | |
| `started_at`, `completed_at` | DATETIME(3) | Y | NULL | |
| `elapsed_ms` | INT UNSIGNED | Y | NULL | Performance regression detection |
| `attempt_no` | SMALLINT UNSIGNED | N | 1 | |
| `error_class`, `error_detail` | VARCHAR(120) / TEXT | Y | NULL | |
| `output_digest` | BINARY(32) | Y | NULL | Proves idempotent re-execution produced the same output |

**Keys.** PK `id` · UQ `(tenant_id, payroll_run_id, stage_code, shard_no, attempt_no)` ·
IX `(tenant_id, payroll_run_id, state_code)`, `(tenant_id, payroll_run_id, stage_no)`.
**Policy.** Tenant-owned · append-only · soft delete: none · audit `event-only` ·
PII `none` · retention `R-PAY-8Y`.

### XIV.8.3 `payroll_run_employees` — A3 *(shape declared in Part XXVIII; specified here)*

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `id`, `tenant_id` | | N | | |
| `payroll_run_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `employment_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `is_included` | TINYINT(1) | N | 1 | |
| `inclusion_reason_code` | VARCHAR(40) | N | — | `active_in_period\|joiner\|leaver\|rejoiner\|arrear_only\|explicit_include` |
| `exclusion_reason_code` | VARCHAR(40) | Y | NULL | `exited_before_period\|not_yet_joined\|no_compensation\|suspended_unpaid\|explicit_exclude\|other_pay_group\|already_paid_in_lot` |
| `window_start_on`, `window_end_on` | DATE | N | — | The employment window inside the period |
| `window_seq` | TINYINT UNSIGNED | N | 1 | 2+ for a rejoiner (R-XIV-034) |
| `shard_no` | SMALLINT UNSIGNED | N | — | |
| `state_code` | VARCHAR(40) | N | `pending` | `pending\|computed\|failed\|excluded` |
| `failure_reason` | VARCHAR(400) | Y | NULL | |

**Keys.** PK `id` · UQ `(tenant_id, payroll_run_id, employment_id, window_seq)` ·
IX `(tenant_id, payroll_run_id, state_code)`, `(tenant_id, payroll_run_id, shard_no)`,
`(tenant_id, employment_id)`.
**Policy.** Tenant-owned · immutable with its run version · delete: never ·
audit `event-only` · PII `none` · retention `R-PAY-8Y`.
*Why the exclusion reason is a column and not a log line:* SmartPRS 2 counts
skipped employees into `$skipped` and never records **who** or **why**
(`compute():1205-1210`), so "why was this person not paid?" is unanswerable.

### XIV.8.4 `payroll_reconciliations` — A4 *(shape declared in Part XXVIII; specified here)*

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `id`, `tenant_id` | | N | | |
| `payroll_run_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `check_code` | VARCHAR(20) | N | — | `RC-01` … `RC-17` |
| `scope_kind_code` | VARCHAR(20) | N | — | `run\|payslip\|line_group` |
| `subject_id` | BIGINT UNSIGNED | Y | NULL | The payslip, when scoped to one |
| `expected_minor`, `actual_minor` | BIGINT | N | 0 | **Signed integer paise** — never decimal, never float |
| `difference_minor` | BIGINT | N | 0 | Generated column `actual_minor - expected_minor` |
| `passed` | TINYINT(1) | N | 0 | |
| `checked_at` | DATETIME(3) | N | — | |

**Keys.** PK `id` · UQ `(tenant_id, payroll_run_id, check_code, scope_kind_code, subject_id)` ·
IX `(tenant_id, payroll_run_id, passed)`.
**Policy.** Tenant-owned · append-only · audit `event-only` · PII `none` ·
retention `R-PAY-8Y`.

### XIV.8.5 `payroll_period_locks` — A4

**Purpose.** The audited freeze record per period per freeze kind. Separating
this from `payroll_periods` makes each freeze independently reopenable and gives
each its own workflow instance.

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `id`, `tenant_id` | | N | | |
| `payroll_period_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `lock_kind_code` | VARCHAR(40) | N | — | `attendance_cutoff\|attendance_freeze\|variable_input_freeze\|payroll_freeze` |
| `state_code` | VARCHAR(40) | N | `open` | `open\|locked\|reopened` |
| `locked_at`, `locked_by_user_id` | | Y | NULL | FK RESTRICT |
| `reopened_at`, `reopened_by_user_id` | | Y | NULL | FK RESTRICT |
| `workflow_instance_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT — the reopen instance |
| `reason_code`, `reason_note` | VARCHAR(40) / VARCHAR(1000) | Y | NULL | Note mandatory on reopen |
| `reopen_count` | SMALLINT UNSIGNED | N | 0 | Visible on every register drawn from the period |

**Keys.** PK `id` · UQ `(tenant_id, payroll_period_id, lock_kind_code)` ·
IX `(tenant_id, state_code, locked_at)`.
**Policy.** Tenant-owned · history: transitions audited · delete: never ·
audit `full` · PII `none` · retention `R-PAY-8Y`.

### XIV.8.6 `employee_period_statutory_locks` — A4

**Purpose.** Period-scoped eligibility determinations as structured facts
(R-XIV-045).

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `id`, `tenant_id` | | N | | |
| `employment_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `scheme_code` | VARCHAR(40) | N | — | e.g. `esi`; from the rule pack's statute vocabulary |
| `contribution_period_start_on`, `contribution_period_end_on` | DATE | N | — | From the pack, never from an April/October literal |
| `determination_code` | VARCHAR(40) | N | — | `covered\|not_covered\|continued_by_lock` |
| `determined_by_run_id` | BIGINT UNSIGNED | N | — | FK `payroll_runs(id)` RESTRICT |
| `determined_at` | DATETIME(3) | N | — | |
| `rule_pack_version_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `basis_amount_minor` | BIGINT | Y | NULL | The wage at determination |
| `superseded_by_id` | BIGINT UNSIGNED | Y | NULL | FK self RESTRICT |

**Keys.** PK `id` · UQ `(tenant_id, employment_id, scheme_code,
contribution_period_start_on)` ·
IX `(tenant_id, employment_id, scheme_code)`, `(tenant_id, scheme_code,
contribution_period_end_on)`.
**Policy.** Tenant-owned · supersession, never update · delete: never ·
audit `full` · PII `sensitive` · retention `R-PAY-8Y`.
*Why:* the determination is currently recovered by `stripos($k, 'esi')` over
stored payslip JSON keys, in a query with no tenant filter (DEF-P11, SEC-P08).

### XIV.8.7 `cfg_proration_policies` — cfg payload

`proration_policy_code`, `name`, `measure_basis_code`, `value_basis_code`,
`conversion_policy_code` (FK to the day-length policy artefact), `fixed_day_divisor`
DECIMAL(9,4) NULL, `include_weekly_offs_in_payable`, `include_holidays_in_payable`,
`zero_evidence_outcome_code`, `boundary_formula_code` (default `F-PRORATE-BOUNDARY`),
`factor_formula_code`.
**Keys.** UQ `(config_version_id)`, `(tenant_id, proration_policy_code, config_version_id)`.
**Policy.** Tenant-owned · versioned by the artefact · immutable once published ·
audit `full` · PII `none` · retention `R-PERM`.

### XIV.8.8 `cfg_recovery_priority_policies` — cfg payload + A3 lines

Header: `policy_code`, `name`, `minimum_net_policy_code`.
Lines (`cfg_recovery_priority_lines`): `priority_no`, `recovery_kind_code`,
`shortfall_policy_code` (`skip_month|partial|carry_forward|spread_over_n`),
`spread_periods` SMALLINT NULL, `max_pct_of_net` DECIMAL(9,6) NULL,
`max_amount_minor` BIGINT NULL, `stop_on_exit` TINYINT(1).
**Keys.** UQ `(config_version_id, priority_no)`,
`(config_version_id, recovery_kind_code)`.

### XIV.8.9 `payout_instructions` and `payout_lines` — A1 / A3

**Purpose.** Disbursement as an explicit, auditable object, replacing "downloading
the file is the disbursement".

`payout_instructions`: `uuid`, `payroll_run_id` FK RESTRICT, `bank_profile_code`,
`instruction_no` (from `code_sequence_counters`), `value_date`,
`total_amount_minor` BIGINT, `line_count`, `state_code`
(`prepared|authorised|submitted|confirmed|partially_failed|failed|cancelled`),
`authorised_by_user_id`, `authorised_at`, `workflow_instance_id` FK RESTRICT,
`file_document_id` FK RESTRICT, `bank_reference`, `confirmed_at`.
`payout_lines`: `payout_instruction_id` FK RESTRICT, `payslip_id` FK RESTRICT,
`amount_minor`, `beneficiary_account_ref` (encrypted), `ifsc`, `narration`,
`state_code` (`pending|paid|returned|failed`), `bank_status_code`,
`failure_reason`, `returned_at`.
**Keys.** instructions UQ `(tenant_id, uuid)`, `(tenant_id, instruction_no)`;
lines UQ `(tenant_id, payout_instruction_id, payslip_id)`,
IX `(tenant_id, payslip_id)`.
**Policy.** Tenant-owned · append-only with state stamps · delete: never ·
audit `full` · PII `sensitive` (bank), masked by role, access audited ·
retention `R-PAY-8Y`.

**R-XIV-075** A payslip line reaches `disbursed` only when a `payout_lines` row
for it reaches `paid`, and returns to `approved` with a `payout_returned` event
if the bank returns it. *SmartPRS 2 flips the status on file download and has no
concept of a return at all.*

### XIV.8.10 Retention, PII and tenancy

| Table | Tenant scope | PII | Retention | Soft delete |
|---|---|---|---|---|
| `payroll_periods`, `payroll_period_locks` | `tenant_id` NOT NULL, in every UQ | none | `R-PAY-8Y` | never |
| `payroll_runs`, `payroll_run_stages`, `payroll_run_employees`, `payroll_run_inputs`, `payroll_reconciliations` | as above | none | `R-PAY-8Y` | never |
| `payslips`, `payslip_lines` | as above | **sensitive** (salary), masked by role, access audited | `R-PAY-8Y`, never purged while a statutory filing references them | never |
| `employee_period_statutory_locks` | as above | sensitive | `R-PAY-8Y` | never |
| `payout_instructions`, `payout_lines` | as above | **sensitive** (bank), encrypted at the application layer | `R-PAY-8Y` | never |
| `cfg_proration_policies`, `cfg_recovery_priority_policies` | as above | none | `R-PERM` | never |

**R-XIV-076** Every query in this part is tenant-scoped by the persistence layer,
never by a hand-written `where`. *Three money-affecting queries in SmartPRS 2 are
not tenant-scoped at all: the leave-type paid/unpaid lookup that decides whether a
leave day is paid (SEC-P07), the ESI period lock (SEC-P08) and the payslip YTD
(SEC-P09).*

---

## XIV.9 API surface

All under `/api/v3`. Every route carries an explicit policy binding, asserted by
the CI sweep (spine S13). Every unsafe method takes an idempotency key. Every
collection is cursor-paginated. Identifiers in URLs are UUIDv7.

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/payroll/cycles` | `payroll.cycle.view` | Resolved cycles with their scope |
| `POST` | `/payroll/cycles` | `payroll.cycle.manage` | Creates a draft version; publish is a config workflow |
| `POST` | `/payroll/cycles/{uuid}/preview-periods` | `payroll.cycle.manage` | Materialisation preview incl. bridging periods |
| `GET` | `/payroll/periods` | `payroll.period.view` | Filter by pay group, state, date |
| `GET` | `/payroll/periods/{uuid}` | `payroll.period.view` | Includes the four lock states |
| `POST` | `/payroll/periods/{uuid}/locks/{kind}` | `payroll.period.lock` | Sets a freeze; body carries the reason |
| `POST` | `/payroll/periods/{uuid}/reopen-requests` | `payroll.period.reopen.request` | Starts `PERIOD-REOPEN`; returns the workflow instance |
| `POST` | `/payroll/runs` | `payroll.run.create` | Creates version 1; body: period, pay group, run type, lot, scope, include/exclude |
| `POST` | `/payroll/runs/{uuid}/versions` | `payroll.run.create` | Creates version *n+1*; **never deletes** |
| `POST` | `/payroll/runs/{uuid}/execute` | `payroll.run.create` | Queues the pipeline; idempotent on the key |
| `GET` | `/payroll/runs/{uuid}` | `payroll.run.view` | Header, stage states, totals, reconciliation |
| `GET` | `/payroll/runs/{uuid}/stages` | `payroll.run.view` | Per-stage, per-shard progress |
| `POST` | `/payroll/runs/{uuid}/restart` | `payroll.run.create` | Resumes from the first non-completed stage |
| `POST` | `/payroll/runs/{uuid}/stages/{code}/dry-run` | `payroll.run.debug` | One employment, no persistence (R-XIV-037.6) |
| `GET` | `/payroll/runs/{uuid}/input-diff` | `payroll.run.view` | R-XIV-047 |
| `POST` | `/payroll/runs/{uuid}/input-diff/acknowledge` | `payroll.run.submit` | R-XIV-048 |
| `GET` | `/payroll/runs/{uuid}/exceptions` | `payroll.run.view` | Filter by severity and state |
| `POST` | `/payroll/runs/{uuid}/exceptions/{id}/resolve` | `payroll.run.exception.resolve` | Named action + mandatory note |
| `GET` | `/payroll/runs/{uuid}/employees` | `payroll.run.view` | Inclusion and **exclusion reasons** |
| `GET` | `/payroll/runs/{uuid}/reconciliation` | `payroll.run.view` | Every check, expected vs actual in paise |
| `GET` | `/payroll/runs/{uuid}/sheet` | `payroll.sheet.view` + data scope | The salary sheet; **never `auth` alone** |
| `POST` | `/payroll/runs/{uuid}/submit` | `payroll.run.submit` | Starts `PAYROLL-APPROVE` |
| `POST` | `/payroll/runs/{uuid}/lines/{payslipUuid}/actions/{action}` | per action | Per-line hold/review/approve/reject; bounded batch |
| `GET` | `/payroll/runs/{uuid}/variance` | `payroll.run.view` | Against the prior locked run |
| `POST` | `/payroll/runs/{uuid}/payouts` | `payroll.payout.prepare` | Prepares an instruction — a **read** of the run |
| `POST` | `/payroll/payouts/{uuid}/authorise` | `payroll.payout.authorise` | Segregated from prepare |
| `GET` | `/payroll/payouts/{uuid}/file` | `payroll.payout.view` | **Pure read.** Never mutates (R-IX-074) |
| `POST` | `/payroll/payouts/{uuid}/confirm` | `payroll.payout.confirm` | Bank confirmation; flips lines to `disbursed` |
| `POST` | `/payroll/payouts/{uuid}/returns` | `payroll.payout.confirm` | Per-line return handling |
| `GET` | `/payroll/explain/{entity}/{uuid}/{field}` | inherits the subject's policy | Spine S15 |
| `POST` | `/payroll/simulate` | `payroll.simulate` | The **same engine**, no persistence |

**R-XIV-077** No payroll endpoint is guarded by `auth` alone. *Today
`GET /app/salary-runs` and `GET /app/salary-runs/{id}/sheet` have no guard beyond
`auth`, so any authenticated employee can read the whole company's salary sheet;
`GET /app/statutory/{type}/pdf` has none either and serves PAN, UAN, PF wage and
tax for every colleague, and falls back to the **first tenant's** data for a user
with a null `tenant_id` (SEC-P02, SEC-P03, SEC-P05, SEC-P11).*

**R-XIV-078** Every bulk operation is bounded and returns per-item results.
*`lineBulk():696-725` and `bulk():862-898` accept an unbounded `ids` array, run a
`payroll_runs` lookup and queue an email per iteration, and `bulk()` collects
`$errors` at `:885` and **never returns them** (DEF-P30).*

---

## XIV.10 Events

Emitted (all carry tenant, run/period uuid, correlation id, actor):

| Event | When | Payload highlights |
|---|---|---|
| `payroll.period.generated` | Period materialised | Cycle version, window, `pay_on` |
| `payroll.period.bridging_created` | R-XIV-012 | Bridge window, affected count |
| `payroll.period.cutoff_reached` | Attendance or variable-input cut-off | Kind, timestamp |
| `payroll.period.frozen` | A freeze set | Kind, actor |
| `payroll.period.reopened` | Reopen finalised | Kind, reason, workflow instance, reopen count |
| `payroll.run.created` | Version created | Type, lot, version, supersedes |
| `payroll.run.snapshot_taken` | Stage 3 | `snapshot_digest`, input counts by kind |
| `payroll.run.inputs_changed` | Diff non-empty | Per-slice change list, projected Δ net |
| `payroll.run.stage_completed` / `.stage_failed` | Per stage per shard | Counts, elapsed, error class |
| `payroll.run.preview_ready` | Stage 25 in preview | Totals, exception counts by severity |
| `payroll.run.exception_raised` / `.exception_resolved` | | Code, severity, subject, resolution |
| `payroll.run.reconciliation_failed` | Any RC failure | Check, subject, difference in paise |
| `payroll.run.submitted` | `n_submit` | Workflow instance |
| `payroll.run.approved` | `n_finalise` | **Exactly once** per instance (R-IX-026) |
| `payroll.run.rejected` | `n_reject` | Reason, comment, actor |
| `payroll.run.locked` | `n_lock` | |
| `payroll.run.superseded` | New version supersedes | Old and new version ids |
| `payroll.payout.prepared` / `.authorised` / `.submitted` / `.confirmed` / `.line_returned` | Payout lifecycle | Instruction, totals, bank reference |
| `payslip.issued` / `payslip.superseded` | Part XVI | |
| `payroll.employee_excluded` | Stage 1 | Employment, exclusion reason |

Consumed:

| Event | Source | Effect |
|---|---|---|
| `attendance.period_settled` | Part XI | Enables stage 5; clears `missing_attendance` |
| `attendance.settlement_stale` | Part XI | Raises `settlement_reopened_after_run` on any run version already snapshotted |
| `leave.charge_posted` / `.reversed` | Leave module | Before the freeze: recompute preview. After: rejected by `PeriodLockGuard` |
| `config.version_published` | Part VI | If effective inside an unlocked period with a run, marks the run's diff dirty |
| `statutory.pack_accepted` | Spine S12 | Same |
| `compensation.revised` | Part XV | Same; and if effective inside a **locked** period, raises an arrears instruction rather than mutating |
| `loan.sanctioned` | Part IX `LOAN-SANCTION` | Creates the recovery schedule idempotently |
| `employment.exited` | Employee module | Sets the leaver boundary; triggers `EXIT-FNF` |
| `rule_pack.signature_invalid` | Spine S12 | Blocks any run resolving that pack |

---

## XIV.11 Migration implication

| SmartPRS 2 artefact | Comes across as | Transformation | Does not come across |
|---|---|---|---|
| `pay_cycles` + `salary_schedules` | One `cfg_pay_cycles` artefact per distinct configuration, versioned, keyed on **company id** | Company **name** matching is resolved to ids by a reviewed mapping; unmatched names become an operator worklist. `pay_day` type conflict (string vs int) normalised | The `'all'` sentinel row; the 2–28 cut-off restriction; the dead `$pick` closure |
| `payroll_runs.period_start` / `period_end` | `payroll_periods` rows, verbatim | **Never recomputed** — the stored window is the fact | `cycle_label` as an idempotency key |
| `payroll_runs` | `payroll_runs` version 1, `run_type_code = 'regular'`, `lot_no = 1`, marked `migrated` | `approved` + a non-null `locked_at` maps to the locked terminal state. `hr_by`/`fin_by`/`hr_at`/`fin_at` become a synthetic completed workflow instance so the approval history survives | The unwritten `paid`/`locked` statuses; `schedule_id`; `approved_by` (never written) |
| `payslips` (`earnings`/`deductions` JSON) | `payslips` + **`payslip_lines`** | The single highest-value transformation in the migration. Each JSON key maps to a component code via a per-tenant reviewed mapping table; an unmapped key becomes a `MIGRATED-UNMAPPED-nnn` component with `is_taxable`/`is_pf_wage`/… all `0` and a blocking review task | Nothing is recomputed. A historical payslip is a stored fact and is re-rendered from its lines, never re-derived |
| `payslips.calc_note` | Retained verbatim as `legacy_calc_note` on the payslip, displayed as an archival note | **Never parsed.** Paid Days and LOP are taken from the migrated structured columns where present, else the payslip is marked `figures_incomplete` | The regex (DEF-P16) |
| `payslips.line_*` / `ack_*` | Line state + acknowledgement records | Direct | — |
| `loan_recoveries` | `loan_ledger_entries` | Re-keyed to run **version** | — |
| `commissions` + `commission_payments` + `commission_logs` | `variable_pay_inputs` + their ledger | The status ENUM is widened **before** extraction (DEF-P03: the migrated ENUM contains none of the values the code writes, so on strict MySQL every insert failed and on non-strict MySQL the status was silently blanked) | `employee_ids` CSV — normalised to a relation |
| `overtime` (+ `paid_run_id`) | `variable_pay_inputs` of kind `overtime` | | The `ctc/12/26/8` divisor, which becomes a formula artefact |
| `advances` | `advances` + a **new schedule and ledger** | `per_month` and `balance` — migrated columns no code reads — become the schedule they always modelled | The one-month `created_at` window (NEW-PAY-25) |
| `clawbacks` (+ `recovered_*`) | `recoveries` + `recovery_instalments` | | The one-month-only rule |
| `ensurePayrollHistory` output | **Quarantined, not migrated** | Identified by the heuristics in `A-payroll.md` §9: `net_total = 0` at insert, no `calc_note`, no `period_start`, `employees_count` inconsistent with headcount, all payslips at exactly `ctc/12` with no LOP | See **D-XIV-08** — this blocks migration planning until answered |
| `PeriodResolver` | Its **logic**, carried forward into the period engine (spine S19 item 5) | Its `clamp()` becomes R-XIV-008's choke point; its continuity validation becomes R-XIV-011 | The class itself, and the three competing period models |
| The penny-safe balance allocation | Generalised as `ALLOCATE` (spine S19 item 4, Part VIII §6.4) | | |
| `statutory_settings` blob | **Eliminated, not layered over.** Each key becomes either a rule-pack value or a dated tenant policy artefact | Dated from the earliest period it could have affected, flagged `migration_baseline` | The blob itself and its overwrite-in-place `save()` |

**R-XIV-079** Migration writes no payslip that was not present in SmartPRS 2, and
recomputes nothing. A migrated payslip whose lines cannot be reconciled to its
stored `gross`/`total_ded`/`net` is imported **with the discrepancy recorded** and
flagged, never silently adjusted.

---

## XIV.12 Edge cases and failure states

| # | Situation | Behaviour |
|---|---|---|
| 1 | Employee joins and exits within one period | One payslip, one boundary window, factor = window ÷ scheduled (FL-13) |
| 2 | Employee rejoins within one period | Two windows, `window_seq` 1 and 2, one payslip, lines traced per window; `rejoin_in_period` info exception |
| 3 | Employee transfers company mid-period | `multi_company_employment` warning; the period is split at the assignment boundary and each part attributed to its company for statutory and cost purposes |
| 4 | Employee transfers state mid-period | PT (and any state-scoped head) resolves per the assignment as at the pack's own determination date, which the pack declares; the trace names both states |
| 5 | Period end day 31 in February | `CLAMP_DAY_TO_MONTH` per the declared overflow mode; the clamp appears in the period's trace |
| 6 | Cut-off cycle where the period spans a financial-year boundary | Statutory and tax stages resolve by the **date of each day**, not by the period label; the trace shows the split |
| 7 | Two employments for one person in one tenant | Two payslips, independent; statutory aggregation across them is a rule-pack question, and the pack declares it — the engine never assumes |
| 8 | Attendance settlement frozen, then a correction is approved | Correction is accepted (overlay, Part XI), does not change the frozen settlement, and is carried to the next period as a dated adjustment unless the period is reopened |
| 9 | Statutory pack accepted mid-run | Rejected: the run's pack version is pinned. The next run **version** picks it up and the diff names it |
| 10 | Compensation revised effective inside a locked period | Not applied to the locked period. An arrears instruction is raised automatically per the compensation's `arrears_policy_code` |
| 11 | Net is negative before recoveries | `negative_net` blocking exception. Never clamped silently. *SmartPRS 2 clamps `net` to 0 while leaving `total_ded` intact, producing a payslip whose printed equation is wrong (DEF-P06)* |
| 12 | Net is positive but below the minimum floor | Recoveries deferred per the floor policy, in reverse priority order, with the deferral traced and carried (FL-28) |
| 13 | Recovery cannot be taken at all | Per the kind's shortfall policy; **never silently dropped**. `recovery_deferred` info exception |
| 14 | Loan schedule and ledger disagree | `loan_inconsistency` blocking. The run does not "fix" it |
| 15 | Zero employments resolve | `fail_run` at stage 1 with the predicate that excluded everyone |
| 16 | Employment with no attendance evidence | Per R-XIV-035 policy; whichever outcome, it is traced and appears as an exception |
| 17 | A single employment fails a stage | `fail_subject`; the run continues; the run cannot leave preview until it is resolved or the employment is explicitly excluded with a reason |
| 18 | Worker crash mid-run | Restart from the first non-`completed` (stage, shard); completed shards are not re-executed; `output_digest` proves idempotence |
| 19 | Two concurrent `execute` calls | The unique key plus an advisory lock on `(run_id)`; the second returns the first's job reference |
| 20 | Two concurrent run creations for the same key | The database unique constraint rejects the second. *SmartPRS 2 prevents duplicates with a PHP `SELECT` and no constraint, and generation takes minutes* |
| 21 | Reconciliation fails on one payslip | **Whole run aborts.** No partial issue |
| 22 | Payout file downloaded twice | Idempotent; it is a read. State changes only through `confirm` |
| 23 | Bank returns a line after confirmation | Line returns to `approved`, the payslip is **not** superseded, an event fires, and the amount is available for a subsequent instruction |
| 24 | Period reopened after a statutory filing referenced it | Compliance node in `PERIOD-REOPEN`; `statutory.filing.impacted` emitted |
| 25 | An employment's structure changes mid-period | Two structure windows; each component's lines are prorated to its window and the trace names both structure versions |
| 26 | A component version referenced by an issued payslip is disabled | Permitted for **future** structures; `ON DELETE RESTRICT` prevents removal; historical explainability is preserved |
| 27 | Foreign-currency component with no dated rate | `foreign_currency_component` blocking. **No implicit FX, ever** |
| 28 | A rule pack's signature fails verification | No run may resolve it; `fail_run`; operator alert |
| 29 | Arrears targeting a period that has itself been superseded | The **pinned** run version named in the arrear instruction is used, not "the latest" |
| 30 | The engine version changes between run versions | `engine_version` is a snapshot input, so the diff names it as a changed input requiring acknowledgement |

---

## XIV.13 Tests

The brief's Section 112 payroll golden-test list is the **floor**. Every test
below asserts **exact values in paise**, is regression-locked, and runs in CI.
Statutory parameters come from a fixture rule pack, never from law asserted here.

### XIV.13.1 The Section 112 golden tests

| ID | Scenario | Asserts |
|---|---|---|
| `T-XIV-01` | **New joiner** mid-period on a `cutoff_day = 25` cycle (joined 06-Jul, period 26-Jun→25-Jul) | Window 20d, factor 20/30, gross ₹33,333.33, every component prorated, LOP lines sum exactly (FL-13, RC-08) |
| `T-XIV-02` | **Exit** mid-period, same cycle, exit 10-Jul | Window 15d, factor 15/30, gross ₹25,000.00. *There is no SmartPRS 2 behaviour to compare against; leaver proration does not exist* |
| `T-XIV-02b` | Joiner **and** leaver in one period (30-Jun → 10-Jul) | Window 11d, factor 11/30, gross ₹18,333.33 |
| `T-XIV-03` | **LOP**, 2 absent working days, July 2026 (31 cal, 27 working), gross ₹50,000 | All five bases: `working` ₹46,296.30 · `calendar` ₹46,774.19 · `fixed_30` ₹46,666.67 · `fixed_26` ₹46,153.85 · `payable_days` per settlement. Each with its own trace naming its denominator |
| `T-XIV-04` | **Half-day**: a half-day leave and a half-day late penalty in one period | `settlement.payable_days` reduced by exactly 0.5 each; `DayFraction` type respected; the payslip shows 0.5-day LOP lines. *SmartPRS 2 counts every leave day as exactly 1.0 (`paidLeaveDays():1077`)* |
| `T-XIV-05` | **PF ceiling**: wage above and below the pack's ceiling, and exactly at it | Employee, employer, EPS and EDLI splits sum exactly to the employer total; the wage base is built from `is_pf_wage` flags and **contains no component matched by name** |
| `T-XIV-06` | **ESI boundary**: gross at, one paise below and one paise above the pack threshold; plus the contribution-period lock continuing coverage above the threshold | The lock is read from `employee_period_statutory_locks`, **not** from a payslip JSON key; renaming the ESI component changes nothing |
| `T-XIV-07` | **PT slab**: three states from the fixture pack, including a band boundary and a month-specific uplift | Slab walked from the pack; a malformed slab is a **pack validation failure**, never a silent ₹0 (NEW-PAY-17) |
| `T-XIV-08` | **Overtime**: OT minutes at two multipliers | Hourly rate derived from a named divisor artefact, not `ctc/12/26/8`; OT enters exactly the statutory bases its component's flags declare |
| `T-XIV-09` | **Commission** paid with salary, one line per purpose, locked to the run version | Lock is idempotent and reversible on supersession; the reversal matches on the **run version id**, not on a `LIKE 'run #<id> %'` pattern (BR-PAY-019) |
| `T-XIV-10` | **Loan**: full EMI fits; EMI does not fit under each of the four shortfall policies | Ledger entries balance; `installments_paid` never exceeds `installments_total`; outstanding is maintained **by the system** |
| `T-XIV-11` | **Advance**: recovery spread over 3 periods, with a shortfall in period 2 | Carried per policy; ledger reversible; nothing is lost |
| `T-XIV-12` | **Arrear**: back-dated increment effective inside a locked period, settled in the next | Difference computed against the **pinned** prior run; both version sets in the trace; the arrear enters the statutory and tax bases its flags declare |
| `T-XIV-12b` | Back-dated **decrease** | Produces a recovery instruction, not silence (D-XIV-06) |
| `T-XIV-13` | **Bonus** run: statutory bonus computed from pack parameters and **paid onto a payslip** | *SmartPRS 2 computes a provision that nothing ever pays (DEF-P24)* |
| `T-XIV-14` | **Minimum wage**: the `is_minimum_wage_comparable` sum below the resolved floor | `minimum_wage_breach` blocking exception naming the floor, its source and the shortfall |
| `T-XIV-15` | **Cross-state**: two employments, two states, one run | Each resolves its own state-scoped statutory values; neither leaks into the other; state matching is by **code**, never by `str_contains` (NEW-PAY-16) |
| `T-XIV-16` | **Mid-period salary change** effective on day 12 of a 30-day period | Two structure windows; each prorated to its window; `SUM(windows) = full-period entitlement` exactly |
| `T-XIV-17` | **Shift worker** on a rotating roster crossing the period boundary | Settlement resolves the attendance-day attribution rule (Part XI §XI.8.4); payable days match the roster |
| `T-XIV-18` | **Night shift**: allowance per qualifying night, plus a night-shift OT multiplier | Nights counted from the resolved shift, not from a punch-date heuristic; allowance enters exactly its declared bases |
| `T-XIV-19` | **Sandwich leave**: an off flanked by uncovered working days, under both the two-sided and the one-sided policy | Two different, correct results; the policy version appears in the trace. *SmartPRS 2 offers only the two-sided variant, tenant-wide, and it indexes the wrong dates under a cut-off (DEF-P05)* |
| `T-XIV-20` | **Comp-off**: earned in period 1, availed in period 2, encashed in period 3 | Ledger balances; encashment valued per FL-15; no double count |
| `T-XIV-21` | **Holiday work**: work on a declared holiday under three policies (comp-off, OT, extra day's pay) | Each policy produces its declared outcome from configuration alone |

### XIV.13.2 Structural tests

| ID | Asserts |
|---|---|
| `T-XIV-22` | **Reconciliation**: a deliberate ₹0.01 perturbation on a line fails `RC-01`, `RC-03`, `RC-04` and `RC-06` and aborts the run |
| `T-XIV-23` | **Re-run reproducibility**: same snapshot, run twice, byte-identical lines and traces (R-XIV-046) |
| `T-XIV-24` | **Changed-input detection**: a statutory pack accepted between versions appears in the diff with the correct affected count and projected Δ |
| `T-XIV-25` | **Restart**: kill the worker mid-stage-17 on shard 3; restart; result identical to an uninterrupted run |
| `T-XIV-26` | **Shard determinism**: the same run at `shard_count` 4 and 8 produces identical allocation residues — because `shard_count` is pinned, this test asserts that changing it requires a new run version |
| `T-XIV-27` | **Period lock**: every one of the write paths in R-XIV-017 is rejected for a locked period, and the guard fails closed under fault injection |
| `T-XIV-28` | **Reopen**: reopening produces a new run version, preserves the prior version entirely, and increments `reopen_count` |
| `T-XIV-29` | **Regeneration preserves**: the prior version's payslips, line statuses, approval stamps and rejection remark are all queryable after supersession (R-IX-076) |
| `T-XIV-30` | **No float**: static analysis finds no float arithmetic in `Domain\Payroll`; a fixture asserting `0.1 + 0.2` semantics anywhere fails the build |
| `T-XIV-31` | **No DDL**: the run executes with a DB user holding no `ALTER`/`CREATE`/`DROP` |
| `T-XIV-32` | **Cross-tenant isolation**: generated for every payroll endpoint and every aggregate, including the ESI lock, the YTD aggregate and the leave-type lookup (SEC-P07/08/09) |
| `T-XIV-33` | **Authorisation sweep**: every payroll route has an explicit policy binding; a fault injected inside authorisation denies |
| `T-XIV-34` | **Disbursement**: generating a payout file changes no state; only `confirm` does (R-IX-074) |
| `T-XIV-35` | **Duplicate run**: two concurrent creations for the same key — one succeeds, one is rejected by the constraint |
| `T-XIV-36` | **Exception floor**: an attempt to configure a ▣ exception below `blocking` is rejected |
| `T-XIV-37` | **Day clamping**: period end day 29/30/31 across February in a leap and a non-leap year under all three overflow modes |
| `T-XIV-38` | **Continuity**: a cycle change producing a gap is refused at publish; the bridging period closes it and the two runs together pay each day exactly once |
| `T-XIV-39` | **Scale**: 10,000 employments complete within the SLO, and stage timings are recorded |
| `T-XIV-40` | **Trace completeness**: `RC-13` — every derived line has a trace; a trace-less result throws |

---

## XIV.14 Open items — PRODUCT OWNER DECISION REQUIRED

**D-XIV-01 · Zero attendance evidence — pay in full, or treat as absent?**
Today an employee with no punches at all is paid **100%** (`compute():1237`),
deliberately, so a broken biometric device cannot zero a salary. The cost is that
a genuinely absent employee is paid in full, at every scope, with no override.
*Recommendation: default `hold_payslip_for_review` at tenant level, overridable to
`pay_in_full` per company/grade/employee. Trade-off: `hold` creates work on every
device outage and on every genuinely off-system worker; `pay_in_full` is a
control failure that has been shipping. The middle option exists precisely
because neither extreme is right, and it makes the device outage visible instead
of silent.*

**D-XIV-02 · Which proration basis is the platform default?**
*Recommendation: `payable_days` (FL-11). It is the only basis whose numerator and
denominator come from the same frozen settlement on the same day-length policy
and therefore cannot disagree — the structural absence of §XIV.1.2's defect.
Trade-off: migrating tenants are on `working` (or believe they are on
`calendar`), so migration must pin each tenant to its current basis as its
`migration_baseline` version and present the change as an explicit, dated,
diffed decision — not adopt the new default silently.*

**D-XIV-03 · Which variable earnings enter which statutory base?**
Today commission, incentive, overtime, night allowance and arrears attract **no
PF, ESI, PT or TDS** because they are added after the slip is computed
(BR-STAT-006). ESI is due on overtime; arrears are salary and are currently paid
with `tds_rate = 0` (`IncrementController:405`). *This is a compliance ruling,
not a design preference, and it must precede design. Recommendation: the platform
ships the flags with the rule pack's declared defaults per head, the tenant may
make a component's inclusion **stricter** but not looser than the pack's floor,
and every existing customer receives a written statement of what changes at
cutover. Named compliance owner per spine D-03 signs it.*

**D-XIV-04 · Recovery order and shortfall policy defaults.**
Today: statutory → loan → advance → clawback, fixed; loans full-EMI-only;
advances one-shot-and-lost; clawbacks one-month-only. *Recommendation: ship the
current order as the default policy so migrating customers see no change, with
per-kind shortfall defaults of `carry_forward` for advances and clawbacks
(correcting the money that is currently lost) and `skip_month` for loans
(preserving current behaviour). Trade-off: changing the loan default to `partial`
would match most Indian payroll systems but would alter every migrating
customer's recoveries on day one.*

**D-XIV-05 · Does the loan full-EMI-only rule survive as an option?**
*Recommendation: yes, as one of four shortfall policies, per loan type. Removing
it would break customers who rely on it; making it the only behaviour is what
SmartPRS 2 does.*

**D-XIV-06 · Does a back-dated salary decrease create a recovery?**
`IncrementController:362` fires only when `newCtc > oldCtc`, so a back-dated
reduction produces nothing and an overpayment is never recovered.
*Recommendation: yes — a symmetric arrears instruction with a negative amount,
routed to the recovery priority policy, subject to the minimum-net floor and to a
mandatory employee notification. Trade-off: recovering an overpayment from a
past period is contentious and in some contracts impermissible, so the arrears
policy must allow `write_off` as an explicit, approved choice.*

**D-XIV-07 · Are arrears taxable, and is Sec 89(1) relief in scope for v3.0?**
*Recommendation: arrears are taxable by default (the flag defaults to
`is_taxable = 1`) and Sec 89(1) relief is deferred to v3.1 as a rule-pack-driven
computation, with the arrear's target period recorded on every line from day one
so the relief can be computed retrospectively when it ships.*

**D-XIV-08 · What happens to eleven months of fabricated "paid" payroll on
every existing install?** *(blocks migration planning)*
DEF-P01 means most live databases contain fabricated `paid` runs with full-CTC
payslips, indistinguishable from real ones except by heuristics.
*Recommendation: migrate them into a **quarantined** run state
(`lifecycle_state_code = 'quarantined_migration'`), excluded from YTD, from every
statutory register and from the reconciliation assertion, visible in the UI with
an explicit banner, and requiring a per-period decision by the customer to either
delete or accept. Trade-off: deleting them at migration is cleaner but destroys
whatever an ESI lock or a YTD figure was computed from; accepting them silently
carries fabricated money into a compliance record. Nothing else in the migration
can be planned until this is answered.*

**D-XIV-09 · Do any customers use multiple lots per period today?**
`payroll_runs.lot` exists and is hardcoded to 1. Lots are designed in regardless,
because the unique key and the YTD de-duplication must be right from day one.
*The decision is whether lots are exposed in the UI at launch. Recommendation:
yes, behind a per-tenant entitlement, because retrofitting the de-duplication
later is exactly the class of change that produces NEW-PAY-34.*

**D-XIV-10 · Does "downloading the bank file is the disbursement" survive?**
*Recommendation: no. Disbursement becomes an explicit, separately authorised
command with an optional bank-side confirmation (R-IX-074, R-XIV-075).
Trade-off: one extra click for Finance, and customers must be told. Against:
today a single link visit by a Finance user marks a whole run disbursed with the
run never having passed HR approval, sends no employee notification, and is
CSRF-unprotected (DEF-P10, SEC-P04).*

**D-XIV-11 · Approval chain shape at launch.**
Is the two-stage HR → Finance chain universal, or do customers need one stage,
three stages, parallel approvers, amount-based routing or delegation?
*Recommendation: ship `PAYROLL-APPROVE` exactly as Part IX §IX.12.3 defines it,
with the segregation set enforced, and let tenants add stages. Note that the
segregation rule will **fail** for tenants whose `admin` currently satisfies both
sides — which is the control being repaired, and which must be communicated
before cutover, not discovered at it (D-IX-07 covers micro-tenants).*

**D-XIV-12 · Retention of superseded run versions.**
*Recommendation: superseded versions retained for the full `R-PAY-8Y` class, with
their payslips marked superseded and excluded from every aggregate but queryable.
Trade-off: storage. Against: the alternative is SmartPRS 2's, where the previous
version, its rejection remark and its approval stamps are destroyed and only an
`activity_logs` row survives.*

**D-XIV-13 · Should the calculation guide be generated?**
*Recommendation: yes (R-XIV-074). It is a client-facing promise about engine
behaviour and four of its statements already contradict the engine.*

---

## XIV.15 Compliance with the seven laws

| Law | How this part complies | Test |
|---|---|---|
| **1 — No business literal in code** | Every divisor, basis, cut-off, day count, threshold, exception severity, recovery order, state name and role is data. The five proration bases are formula artefacts, not branches. The stage list is platform data, versioned | Static analysis over `Domain\Payroll`; `T-XIV-03`, `T-XIV-19`, `T-XIV-21` |
| **2 — No schema change at runtime** | The pipeline issues no DDL. `payslips.calc_note`, `overtime.paid_run_id`, `loans.installments_paid`, `loan_recoveries` and the clawback columns — all created by `generate()` today — are migration-owned | `T-XIV-31`; CI grep |
| **3 — Nothing overwritten, everything versioned and dated** | Periods are immutable once run; runs version and supersede; payslips supersede; compensation is effective-dated; the snapshot pins every configuration version; nothing is deleted | `T-XIV-28`, `T-XIV-29`; `ON DELETE RESTRICT` throughout |
| **4 — Every number explains itself** | Every stage emits a trace; `RC-13` asserts every derived line has one; a trace-less result throws | `T-XIV-40` |
| **5 — Authorisation server-side, declarative, fails closed** | Every route has an explicit policy binding; `PeriodLockGuard` fails closed; no endpoint is guarded by `auth` alone | `T-XIV-27`, `T-XIV-33` |
| **6 — Tenancy is structural** | `tenant_id` NOT NULL on every table here and in every unique index; applied by the persistence layer | `T-XIV-32` |
| **7 — Money is exact** | Integer paise end to end; `ALLOCATE` residue-to-last; rounding declared per component; the reconciliation assertion aborts the run on any paise mismatch | `T-XIV-22`, `T-XIV-30` |

### XIV.15.1 Requirement index

`R-XIV-001` no fabricated records · `002`–`004` pay cycle as artefact ·
`005`–`007` period kinds and labels · `008`–`009` clamping · `010`–`013`
generation, continuity, bridging · `014`–`015` pay date · `016`–`018` freezes ·
`019`–`021` reopen · `022`–`023` recalculation · `024` off-cycle periods ·
`025`–`027` the five bases, scoped · `028`–`029` measure vs value ·
`030`–`031` per-component flags · `032`–`034` boundary proration ·
`035` zero-evidence policy · `036` LOP presentation · `037`–`042` pipeline
contract and stages · `043`–`049` the input snapshot · `050`–`052` determinism
and budgets · `053`–`057` lifecycle and per-line states · `058`–`060` exceptions
· `061`–`064` versioning, lots, recovery order and ledgers ·
`065`–`067` off-cycle/supplementary/arrears · `068`–`070` reconciliation ·
`071`–`074` explainability · `075` disbursement · `076` tenancy ·
`077`–`078` API guards and bulk bounds · `079` migration fidelity.

**79 requirements · 6 new tables + 2 extended · 21 Section-112 golden tests +
19 structural tests · 13 Product Owner decisions.**
