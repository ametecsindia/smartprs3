# PART XII — WORK WEEK, WEEKLY OFF AND HOLIDAY ARCHITECTURE

*Covers brief Sections 20 and 21.*
*Conforms to spine S3 (organisation model, scope, precedence), S4 (effective dating and
the draft/publish model), S5 (SPX), S6 (rule engine), S8 (workflow), S10 (attendance data
architecture), S13 (security), S14 (audit), S15 (explainability), S16 (data conventions),
S17 (API).*
*Depends on: Part VI (configuration architecture), Part VII (rule engine and SPX),
Part XI (attendance, shift master, roster). Depended on by: Part XIII (leave),
Part XIV (payroll), and every report, points and simulation surface.*
*Evidence base: `analysis/B-attendance.md` and `analysis/H-recruitment-hrops-reports.md`.
Every claim about SmartPRS 2 in this part carries a `file.php:line` citation carried
through from those two files.*

---

## XII.0 Purpose

This part decides three things, and it decides them **once for the whole platform**.

1. **What a weekly off is**, expressed as a declarative pattern grammar rich enough to
   carry every work-week arrangement in brief Section 20 — fixed, alternate, Nth-weekday,
   rotational, roster-selected, employee-elected, count-based — as *configuration*, with a
   single evaluator, `is_weekly_off(employee, date)`, that attendance, payroll, leave,
   points, reports and the simulator all call.
2. **What a holiday is**, as a scoped, typed, effective-dated calendar resolved *per
   employee* through the full organisational hierarchy — India → state → city → legal
   entity → establishment → branch → department → shift → employee category — including
   optional and restricted holidays elected by the employee against a quota.
3. **What a day *is*** — the composition rule that turns a weekly-off pattern, a holiday
   calendar, a roster and an approved leave into exactly one **resolved day type** per
   employee per date, the precedence when two of them collide, and the three derived
   counts — **calendar day**, **working day**, **payable day** — that Parts XIII and XIV
   and every report consume without re-deriving.

Every design choice below exists because a specific, evidenced SmartPRS 2 failure makes
the cheaper choice untenable. §XII.1 is that justification.

**The one-line test this part must pass.** *"Our Hyderabad branch is off on 2nd and 4th
Saturday and every Sunday; our Pune branch is off on 1st and 3rd Saturday; night-shift
staff take Tuesday off; and anyone who works a weekly off gets a comp-off that expires in
60 days"* must be four configuration records and no code.

---

## XII.1 What SmartPRS 2 does today, and why it must change

Six findings. Each one eliminates a design option that would otherwise be cheaper.

### XII.1.1 Four mutually incompatible weekly-off definitions coexist

There is no owner of the concept. Four subsystems each answer "is this day off?"
differently, and the answers disagree for the same employee on the same date
(`B-attendance.md` CALC-B-16, Q4).

| # | Definition | Where | Inputs it honours | Inputs it ignores |
|---|---|---|---|---|
| 1 | `roster.status = 'off'` | `ShiftResolver::resolve():118-125`; surfaced at `AttendanceReportController.php:158` | An explicit roster cell | `weekly_off_day`, `sat_off_mode`, holidays. Surfaces **only on rows that already have punches**, and only in the JSON — not in the PDF (`:570-582`) |
| 2 | `working_days`, a flat per-tenant ISO-weekday list, default `[1..6]` | `AbsenceService.php:45, 168-170` | Nothing else | Used **only** when no shift definitions exist at all |
| 3 | `weekly_off_day` + `sat_off_mode ∈ {none, all, 2_4, 1_3}` | `PayrollGenController::isWeekOff():70-89` | Alternate Saturdays — the only one that does | Rosters, per-branch or per-employee patterns. **Not visible to the attendance layer at all** |
| 4 | Sunday, hard-coded | `PointsController::monthMeta():259` | Nothing | Everything, including the tenant's own `weekly_off_day` and `sat_off_mode` |

The live LOP denominator uses definition 3; the attendance report uses definition 1; the
absence sweep uses definition 2; the points module uses definition 4. Analysis confirms
that `PayrollGenController::monthMeta():178-196`'s Sunday-only figure is destructured away
at every call site (`:1096`, `:1562`, `:1976`, `:2090`, `:2476`) so it is dead in payroll —
**but the identical Sunday-only calculator is live in `PointsController`**
(`B-attendance.md` CALC-B-16, correcting the reference documentation).

There is **no per-employee, per-branch, per-department or per-shift weekly-off pattern
anywhere**, and the attendance report cannot render a weekly-off day at all because it
emits no row for a date without punches (`DEFECT-B-10`).

### XII.1.2 The Nth-Saturday calculation is a day-of-month band, not the Nth Saturday

```
nth = ceil(d.day / 7)          -- PayrollGenController.php:83
```

This is the **week-of-month index by date arithmetic**, not the occurrence index of that
weekday in the month (`B-attendance.md` CALC-B-16(a); NEW-PAY-10 in `A-payroll.md`). The
two diverge in most months. A month beginning on a Sunday puts the first Saturday on day 7
(`ceil(7/7) = 1`, correct) but a month beginning on a Monday puts the first Saturday on
day 6 and the second on day 13 (`ceil(13/7) = 2`, correct) — while a month beginning on a
Sunday puts the *fifth* Saturday on day 35, which does not exist, and a month beginning
Wednesday puts the first Saturday on day 3 (`ceil(3/7) = 1`) and the fourth on day 24
(`ceil(24/7) = 4`, correct) but the **second** on day 10 (`ceil(10/7) = 2`, correct) and
the *third* on day 17 (`ceil(17/7) = 3`, correct). The failure is not universal, which is
worse: it is intermittent by month, so it produces a wrong LOP denominator in some months
and a correct one in others, and nobody can tell which without recomputing by hand.

A customer who says "2nd and 4th Saturday" means the second and fourth *Saturdays*. The
platform must count actual weekday occurrences. SPX provides `NTH_WEEKDAY(month, weekday,
n)` for exactly this, with `n = -1` meaning last (Part VII §5.6).

### XII.1.3 Holidays are invisible to the attendance layer

`holidays` (`2026_01_01_000200:100-106`, columns `tenant_id, date, name, applies_to`) is
read in exactly two places (`B-attendance.md` Q4):

- `AbsenceService::isHoliday():225-236` — and a matching holiday cancels the absence sweep
  for the **entire tenant** (`:111-113`);
- `PayrollGenController::holidayDates():1015-1039` — removing the day from the LOP
  denominator.

The Attendance Report, the report PDF, the Today panel and the per-day drill-down **never
query `holidays`** (`DEFECT-B-10`). A holiday is therefore not a *visible attendance
state*. It suppresses an absence notice and shrinks a divisor; it never appears on a muster
roll, never explains why an employee has no punch, and cannot be distinguished from an
unexplained blank.

### XII.1.4 `holidays.applies_to` exists and has no reader

The column is created by the migration (`2026_01_01_000200:104`) and is read by nothing —
confirmed by grep across `app/` (`B-attendance.md` §2.3, NEW-ATT-011). A holiday is
tenant-wide by construction. A Maharashtra-only festival cancels absence detection for the
Telangana branch; a plant shutdown scoped to one establishment applies to head office.

The analysis raises this as **OQ-B-07**: the intended semantics of `applies_to` cannot be
determined from source and must be answered by the Product Owner together with a
`SELECT DISTINCT applies_to FROM holidays` on the live database.

### XII.1.5 The points module hard-codes a Sunday-only week, so a Saturday-off agency mis-counts absences every month

`PointsController::monthMeta():239-263` computes

```
workingDays = max(1, count of days that are NOT Sunday AND NOT a holiday)
absent      = max(0, workingDays − present)
```

ignoring the tenant's own `weekly_off_day` and `sat_off_mode` settings
(`H-recruitment-hrops-reports.md` NEW-PTS-102, NEW-DEFECT-02, and §4 worked example at
`:580`). For a five-day-week agency this over-counts working days by roughly four per
month, and every one of those days is charged to every employee as an absence at the
configured negative point value — *"a Saturday-off agency mis-counts absences for every
employee every month"* (`H-recruitment-hrops-reports.md` NEW-DEFECT-02). The same module
also treats approved leave as absence, because `absent` is derived from a day count rather
than from leave records (`H-…` §4 basis notes).

This is the clearest possible demonstration that a shared calendar cannot be optional.
`H-recruitment-hrops-reports.md` requirement 20 states it directly: *"Any rule that
reasons about a working period must obtain the working-day calendar from one shared
calendar service."*

### XII.1.6 The two weekly-off *behaviour* settings have no reader either

`weekoff_action` and `no_cut_on_weekoff` are created by
`2026_01_01_000200:69-70`, written by the master screen
(`MasterController.php:238-239`), and read nowhere (`DEFECT-B-07`). Week-off suppression is
driven entirely by `roster.status = 'off'`. An HR manager who configures "no cut on
week-off" gets no behaviour at all. **Weekly-off behaviour is therefore not merely
under-configured in SmartPRS 2 — two of its three configuration fields are inert.**

### XII.1.7 Working on a weekly off has no supported outcome

A repository-wide grep for `compoff|comp_off|comp-off` returns exactly one hit: a dead enum
value in the legacy `attendance` table (`2026_01_01_000200:52`). There is no accrual, no
balance, no approval, no consumption and no leave type wired to it (`B-attendance.md` Q4).
*"A customer who works a holiday and expects a compensatory day off has no supported
path."* Nor is there substitution (swap this off for that day), an additional-wage rule, or
an off-day overtime multiplier: `roster.status='off'` merely suppresses the payroll day-cut
(`PayrollGenController.php:429-431`).

### XII.1.8 What SmartPRS 3 explicitly does not replicate

Stated so that no reviewer has to infer it (AUTHOR-BRIEF rule 5):

| SmartPRS 2 behaviour | Not replicated because |
|---|---|
| Four weekly-off definitions | §XII.2 mandates exactly one evaluator |
| `ceil(day / 7)` as an Nth-weekday test | §XII.3.1 uses actual weekday occurrence via `NTH_WEEKDAY` |
| Tenant-wide holidays with an inert `applies_to` | §XII.4.2 resolves a holiday per employee through the full hierarchy |
| Holidays invisible to attendance | §XII.5 makes the resolved day type a stored, displayable fact on every employee-day |
| A weekly off visible only where punches exist | §XII.5.4 materialises a calendar row for every employee-day in the period |
| A tenant-wide holiday cancelling the whole tenant's absence sweep | §XII.4.2 and Part XI §XI.12; scope resolution is per employee |
| Sunday-only points arithmetic | §XII.2.2 lists Points as a mandatory consumer of the one evaluator |
| `weekoff_action` / `no_cut_on_weekoff` with no reader | §XII.3.6; every configuration item in this part is named with its reader |
| No comp-off, no substitution, no swap | §XII.3.6, and the comp-off ledger in Part XIII §XIII.9 |

---

## XII.2 The one evaluator

### XII.2.1 The contract

**R-XII-001** There is exactly **one** implementation of weekly-off determination in
SmartPRS 3, exposed by the Calendar Service as

```
CalendarService::isWeeklyOff(EmployeeId $e, LocalDate $d): WeeklyOffVerdict
```

where

```
WeeklyOffVerdict {
    is_weekly_off      : bool
    pattern_code       : Code|null      -- the weekly-off pattern that decided
    pattern_version_id : int|null
    reason             : Code           -- roster_cell | pattern_fixed | pattern_nth
                                        -- | pattern_rotation | pattern_elected
                                        -- | pattern_count_allocated | not_off
    slot_index         : int|null       -- which of the entitled offs this is
    trace              : DecisionTrace  -- spine S15 / Part VII §9
}
```

**R-XII-002** No module may implement its own weekly-off test, its own working-day count,
or its own holiday test. A static-analysis rule fails the build on any occurrence of a
weekday comparison, a `sat_off_mode`-shaped literal, or a direct query against the
calendar tables from outside `Domain\Calendar`. *This is the direct, mechanical fix for
SmartPRS 2's four definitions (§XII.1.1) and its Sunday-only points arithmetic
(§XII.1.5).*

**R-XII-003** The evaluator is **pure and total**. Given the same employee, date and
resolved configuration versions it returns the same verdict, with no clock access beyond
the injected evaluation date and no randomness (spine S5.6). A resolution failure returns a
typed error verdict that the caller must handle; it never defaults to "working" or to
"off". *SmartPRS 2's absence sweep fails the entire workforce absent when the holiday query
throws (`CONSOLIDATED-REQUIREMENTS.md` P0 test at line 2412); a total, typed evaluator makes
that shape unrepresentable.*

### XII.2.2 The mandatory consumers

**R-XII-004** The following call the one evaluator and nothing else. Each row names the
SmartPRS 2 component that had its own definition.

| Consumer | Uses | SmartPRS 2 predecessor with a divergent definition |
|---|---|---|
| Attendance day derivation (Part XI §XI.8.6) | `resolveDayType` | `ShiftResolver::resolve():118-125` |
| Absent detection (Part XI §XI.12) | `resolveDayType`, `isHoliday` | `AbsenceService.php:45, 168-170, 225-236` |
| Work-time policy rules (Part XI §XI.10) | `day.is_weekly_off`, `day.is_holiday`, `day.day_type` | `roster.status='off'` only |
| Payroll LOP denominator (Part XIV) | `workingDays`, `payableDays` | `PayrollGenController::isWeekOff():70-89` |
| Leave day counting (Part XIII §XIII.6) | `resolveDayType` over the range | none — leave counted inclusive calendar days (`LeaveController.php:338`) |
| Leave adjacency / sandwich (Part XIII §XIII.8) | `resolveDayType` | `PayrollGenController::sandwichDays():770-813` |
| Comp-off accrual (Part XIII §XIII.9) | `resolveDayType` | none |
| Overtime eligibility and multiplier selection (Part XIV) | `day.day_type` | none |
| Points and gamification | `workingDays` | `PointsController::monthMeta():259` (Sunday only) |
| Reports, muster roll, registers | the materialised calendar | report never queried `holidays` (`DEFECT-B-10`) |
| Roster publish validation (Part XI §XI.9.6) | `resolveDayType`, `maxConsecutiveWorkdays` | none |
| The rule simulator and the payroll preview | the same service, same versions | none |

**R-XII-005** The evaluator is exercised through the **resolved configuration versions as
at the evaluation date** (spine S4), never "current". Recomputing August with a pattern
published in October reproduces August's answer.

### XII.2.3 Materialisation

**R-XII-006** For query performance and for auditability, resolved day types are
**materialised** into `employee_calendar_days` (§XII.7.6) for a rolling window and for
every period that has been settled. The materialisation is a projection of the evaluator,
never an independent implementation: a nightly consistency job re-evaluates a sample and
raises an operator alert on any divergence.

**R-XII-007** Materialised rows are **superseded, never updated in place** (Law 3), and
carry the pattern, calendar and rule version ids that produced them, so a muster roll
printed today for last March reproduces last March.

---

## XII.3 The Weekly-Off Engine

### XII.3.1 The pattern grammar

A **weekly-off pattern** is a configuration artefact under spine S4 (`artefact_type =
weekly_off_pattern`), scoped by spine S3.1 and resolved by S3.2 specificity. It is
declarative data; there is no code path per pattern shape.

```
weekly_off_pattern {

  -- identity
  code                          Code            -- stable, never a display name
  name, description             Text

  -- ENTITLEMENT  (brief S20: entitlement, frequency, fixed/variable)
  entitlement_mode              Code   { fixed_weekdays
                                       | nth_weekday
                                       | count_per_window
                                       | rotation
                                       | roster_driven
                                       | composite }
  frequency_window              Code   { week | fortnight | calendar_month
                                       | payroll_period | quarter }
  offs_per_window               Number                  -- entitlement count
  variability                   Code   { fixed | variable }

  -- WHICH DAYS
  fixed_weekdays                List<Code>              -- mon..sun
  allowed_weekdays              List<Code>              -- the eligible set when variable
  nth_rules                     List<NthRule>
  rotation_ref                  Code                    -- rotation pattern (Part XI §XI.9.4)
  composite_members             List<Code>              -- other pattern codes, ORed

  -- WEEK ARITHMETIC  (removes every ambiguity SmartPRS 2 leaves implicit)
  week_start_day                Code   { mon | sun | sat | … }
  week_numbering                Code   { iso
                                       | first_day_of_month
                                       | period_start
                                       | occurrence_of_weekday }   -- default for nth_rules
  fortnight_anchor              Date                    -- when frequency_window = fortnight

  -- SELECTION  (who picks, when variable)
  selection_mode                Code   { fixed
                                       | employee_elected
                                       | manager_assigned
                                       | roster_allocated
                                       | rotation }
  election_window               { opens_days_before, closes_days_before, locked_after }
  election_change_limit         Number                  -- changes permitted per window

  -- SHAPE CONSTRAINTS
  adjacency                     Code   { any | consecutive_required
                                       | non_consecutive_required }
  min_gap_days_between_offs     Number
  min_workdays_before_entitlement Number                -- brief S20

  -- BOUNDARIES  (brief S20: joining week, exit week)
  joining_week_treatment        Code   { full_entitlement | pro_rata
                                       | none | pro_rata_rounded_up }
  exit_week_treatment           Code   { full_entitlement | pro_rata
                                       | none | pro_rata_rounded_up }
  proration_rounding            { mode, unit }          -- explicit; no default (S5.4)

  -- COLLISIONS  (brief S20)
  on_holiday_collision          Code   { absorb | shift_next_working_day
                                       | shift_previous_working_day
                                       | grant_substitute_off
                                       | grant_comp_off }
  on_leave_collision            Code   { off_wins_not_deducted
                                       | deduct_leave
                                       | policy_from_leave_type }   -- see Part XIII §XIII.8

  -- WORKING ON A WEEKLY OFF  (brief S20)
  work_on_off_requires_approval Boolean
  work_on_off_workflow_code     Code                    -- spine S8
  work_on_off_outcome           Code   { none | comp_off | overtime
                                       | additional_wage | employee_choice
                                       | hr_choice }
  comp_off_credit_rule_ref      Code                    -- Part XIII §XIII.9
  overtime_multiplier_ref       Code                    -- Part XIV
  additional_wage_formula_ref   Code                    -- Part VIII formula
  substitution_allowed          Boolean
  substitution_window_days      Number
  substitution_requires_approval Boolean
  swap_allowed                  Boolean                 -- two employees exchange offs
  swap_workflow_code            Code

  -- REST AND FATIGUE  (brief S20)
  max_consecutive_workdays      Number
  max_consecutive_workdays_severity Code { block | warn | allow_with_approval }
  min_rest_hours_between_shifts Number
  min_weekly_rest_hours         Number
  rest_measured_from            Code   { shift_end | last_punch_out }
}

NthRule {
  weekday        Code                -- mon..sun
  occurrences    List<Number>        -- 1..5, and -1 for "last"
  applies_when   SPX Boolean|null    -- optional guard, e.g. month parity
}
```

**R-XII-008** `nth_rules` are evaluated with `NTH_WEEKDAY(month, weekday, n)` (Part VII
§5.6), which counts **actual weekday occurrences**. `ceil(day_of_month / 7)` is not
expressible anywhere in SmartPRS 3. *Direct replacement for
`PayrollGenController.php:83`.*

**R-XII-009** A pattern whose `occurrences` include an index that does not exist in a
given month (a fifth Saturday in a 28-day February) yields **no off for that occurrence**,
and this is stated in the trace rather than silently producing a shifted day. Whether the
missing occurrence is compensated is a declared behaviour
(`missing_occurrence = skip | use_last | grant_substitute`), defaulting to `skip`.

**R-XII-010** Where `variability = variable` and `offs_per_window > 0`, the engine must
allocate exactly `offs_per_window` days inside each window from `allowed_weekdays`,
honouring `adjacency` and `min_gap_days_between_offs`. Allocation is deterministic:
election first, then roster, then rotation, then the declared
`default_allocation_order` (a list of weekdays). Under-allocation is a **publish-time
validation error**, never a runtime surprise.

### XII.3.2 Every pattern in brief Section 20, expressed in the grammar

The purpose of this table is to prove the grammar is sufficient. No row requires code.

| # | Brief pattern | Expression |
|---|---|---|
| 1 | **One weekly off per week** | `entitlement_mode: count_per_window`, `frequency_window: week`, `offs_per_window: 1`, `variability: fixed` (with `fixed_weekdays`) or `variable` (with `allowed_weekdays`) |
| 2 | **Two weekly offs per week** | as above, `offs_per_window: 2` |
| 3 | **Custom number of offs per week** | as above, `offs_per_window: N` |
| 4 | **Fixed Sunday** | `entitlement_mode: fixed_weekdays`, `fixed_weekdays: [sun]`, `variability: fixed` |
| 5 | **Fixed Saturday + Sunday** | `fixed_weekdays: [sat, sun]` |
| 6 | **Alternate Saturdays** | `entitlement_mode: nth_weekday`, `nth_rules: [{weekday: sat, occurrences: [1,3,5]}]` **or** `[{weekday: sat, occurrences: [2,4]}]`; plus `fixed_weekdays: [sun]` if Sunday is also off. Which alternation is a configuration choice, not a code branch — *SmartPRS 2 hard-codes exactly two alternations, `1_3` and `2_4`, at `PayrollGenController.php:83-86`* |
| 7 | **1st and 3rd Saturday** | `nth_rules: [{weekday: sat, occurrences: [1,3]}]` |
| 8 | **2nd and 4th Saturday** | `nth_rules: [{weekday: sat, occurrences: [2,4]}]` |
| 9 | **Nth-weekday rules generally** | `nth_rules: [{weekday: <any>, occurrences: [1..5, -1]}]`, any number of rules ORed. Covers "last Friday of the month", "1st and 3rd Wednesday", "2nd Tuesday and last Thursday" |
| 10 | **Rotational weekly off** | `entitlement_mode: rotation`, `rotation_ref: <rotation pattern code>`, `selection_mode: rotation`. The rotation pattern (Part XI §XI.9.4) carries the cycle length and the OFF slots; the pattern here carries the anchor and the entitlement audit |
| 11 | **Roster-selected weekly off** | `entitlement_mode: roster_driven`, `selection_mode: roster_allocated`. The roster cell is authoritative; the pattern supplies the entitlement count that roster publish validation asserts (Part XI §XI.9.6) |
| 12 | **Any one day of the week (employee's choice)** | `count_per_window`, `offs_per_window: 1`, `variability: variable`, `allowed_weekdays: [mon…sun]`, `selection_mode: employee_elected` |
| 13 | **Any two days of the week** | as above with `offs_per_window: 2` |
| 14 | **Consecutive offs required** | add `adjacency: consecutive_required` |
| 15 | **Non-consecutive offs required** | add `adjacency: non_consecutive_required`, optionally `min_gap_days_between_offs: 2` |
| 16 | **Employee-specific weekly off** | any of the above published with `scope.employee IN [E]` (specificity 1000, spine S3.2) |
| 17 | **Department weekly off** | `scope.department IN [D]` (specificity 400) |
| 18 | **Branch weekly off** | `scope.branch IN [B]` (specificity 180) |
| 19 | **Shift weekly off** | `scope.shift IN [S]` (specificity 330) — *the arrangement SmartPRS 2 cannot express at all: night-shift staff off on Tuesday while day staff are off on Sunday* |
| 20 | **Team weekly off** | `scope.team IN [T]` (specificity 500) |
| 21 | **Mixed: Sunday always + alternate Saturday + one elected day** | `entitlement_mode: composite`, `composite_members: [SUN-FIXED, SAT-2-4, ONE-ELECTED]`. Composition is a union; the entitlement audit sums the members |

**R-XII-011** Patterns 16–20 are *the same artefact at a different scope*, not different
features. Precedence between two matching patterns is spine S3.2 specificity, then declared
`priority`, and a remaining tie **blocks publication** naming both patterns and the affected
employees. *SmartPRS 2 resolves its three-level late-policy scope by row order
(`NEW-ATT-019`); there is no such coin-toss here.*

### XII.3.3 Worked resolution — the four-branch case

Configuration:

| Artefact | Scope | Pattern |
|---|---|---|
| `WO-TENANT-DEFAULT` | (empty — tenant default, weight 0) | `fixed_weekdays: [sun]` |
| `WO-HYD` | `branch IN [Hyderabad]` (180) | `fixed_weekdays: [sun]` + `nth_rules: [{sat, [2,4]}]` |
| `WO-PUNE` | `branch IN [Pune]` (180) | `fixed_weekdays: [sun]` + `nth_rules: [{sat, [1,3]}]` |
| `WO-NIGHT` | `shift IN [Night-C]` (330) | `fixed_weekdays: [tue]` |

An employee at the Hyderabad branch on the Night-C shift resolves to **`WO-NIGHT`**
(330 > 180). The trace names both candidates and the winning weight, so an HR manager who
expected the branch pattern is told exactly why they did not get it. *In SmartPRS 2 this
employee has whichever of four definitions the calling module happens to use.*

### XII.3.4 The configuration surface, item by item

Every item brief Section 20 lists, with its field, its default, and — critically — **its
reader**. *SmartPRS 2 ships two weekly-off settings with no reader at all
(`DEFECT-B-07`); no item may appear in this table without one.*

| # | Configuration item | Field | Default | Read by |
|---|---|---|---|---|
| 1 | Weekly-off entitlement | `offs_per_window` | 1 | Calendar Service allocation; roster publish validation |
| 2 | Frequency | `frequency_window` | `week` | allocation windowing |
| 3 | Fixed or variable | `variability` | `fixed` | allocation; election UI |
| 4 | Allowed weekdays | `allowed_weekdays` | all | allocation; election validation |
| 5 | Fixed weekdays | `fixed_weekdays` | `[sun]` | evaluator |
| 6 | Nth-weekday rules | `nth_rules` | `[]` | evaluator via `NTH_WEEKDAY` |
| 7 | Rotation reference | `rotation_ref` | null | evaluator; roster generation (Part XI §XI.9.5) |
| 8 | Minimum workdays before entitlement | `min_workdays_before_entitlement` | 0 | allocation; joining-week treatment |
| 9 | Joining-week treatment | `joining_week_treatment` | `pro_rata` | allocation for the week containing DOJ |
| 10 | Exit-week treatment | `exit_week_treatment` | `pro_rata` | allocation for the week containing LWD |
| 11 | Proration rounding | `proration_rounding` | **must be declared** | allocation |
| 12 | Holiday collision | `on_holiday_collision` | `absorb` | day-type composition (§XII.5.2) |
| 13 | Leave collision | `on_leave_collision` | `off_wins_not_deducted` | leave day counting (Part XIII §XIII.6) |
| 14 | Working on a weekly off — approval | `work_on_off_requires_approval`, `work_on_off_workflow_code` | false | attendance day derivation; workflow engine |
| 15 | Working on a weekly off — outcome | `work_on_off_outcome` | `none` | settlement (§XII.3.6) |
| 16 | Substitution | `substitution_allowed`, `substitution_window_days`, `substitution_requires_approval` | false / 30 / true | substitution service (§XII.3.6) |
| 17 | Comp-off credit | `comp_off_credit_rule_ref` | null | comp-off ledger (Part XIII §XIII.9) |
| 18 | Overtime | `overtime_multiplier_ref` | null | OT settlement (Part XIV) |
| 19 | Additional wage | `additional_wage_formula_ref` | null | payroll component (Part VIII formula) |
| 20 | Swap between employees | `swap_allowed`, `swap_workflow_code` | false | swap service (§XII.3.6) |
| 21 | Maximum consecutive workdays | `max_consecutive_workdays`, `…_severity` | `<unset>` / `warn` | roster publish validation; work-on-off approval guard |
| 22 | Minimum rest | `min_rest_hours_between_shifts`, `min_weekly_rest_hours`, `rest_measured_from` | `<unset>` | roster publish validation; shift-change validation |
| 23 | Election window | `election_window`, `election_change_limit` | closed | ESS election screen |
| 24 | Week arithmetic | `week_start_day`, `week_numbering`, `fortnight_anchor` | `mon` / `occurrence_of_weekday` | evaluator; every window computation |

**R-XII-012** No item in this table is a code literal, and none may be added without a
named reader. A CI check asserts that every configuration key defined for
`weekly_off_pattern` is referenced from at least one domain service. *This is the direct
countermeasure to `weekoff_action` and `no_cut_on_weekoff` (`DEFECT-B-07`) and to
`hide_birth_year` (`E-leave-requests-notifications.md` NEW-NOT-110), three settings that
are editable and inert.*

### XII.3.5 Joining week and exit week

**R-XII-013** For the window containing the joining date (or the exit date), entitlement is
computed by `joining_week_treatment` / `exit_week_treatment`:

```
pro_rata:  entitled = ROUND( offs_per_window
                             × eligible_days_in_window / days_in_window,
                             proration_rounding.mode, proration_rounding.unit )
```

with `eligible_days_in_window` counted from the later of the window start and the joining
date, to the earlier of the window end and the exit date.

**R-XII-014** `min_workdays_before_entitlement` gates the *first* window only: an employee
who joins on a Friday under a pattern requiring three worked days before the first off gets
no off that week, and the trace says so. Whether the forgone off is carried into the next
window is declared per pattern (`carry_unearned_off = false` by default).

**Worked example.** Pattern: two offs per week, `week_start_day = mon`,
`joining_week_treatment = pro_rata`, `proration_rounding = {half_up, 1}`,
`min_workdays_before_entitlement = 0`. Employee joins Wednesday.
`eligible_days_in_window = 5` (Wed–Sun), `days_in_window = 7`,
`entitled = ROUND(2 × 5/7, half_up, 1) = ROUND(1.4286) = 1`. One off is allocated in the
joining week; the trace shows the arithmetic.

### XII.3.6 Working on a weekly off — the five outcomes

**R-XII-015** A punch on a resolved weekly off does not silently become an ordinary
working day. The engine classifies the day as `weekly_off_worked` and settles it under
`work_on_off_outcome`:

| Outcome | Effect | Owning part |
|---|---|---|
| `none` | Day remains a weekly off; worked minutes recorded and reported; no additional entitlement | Part XI |
| `comp_off` | A comp-off credit is raised under `comp_off_credit_rule_ref` (units, expiry, approval) | Part XIII §XIII.9 |
| `overtime` | Minutes are eligible for OT at `overtime_multiplier_ref` | Part XIV |
| `additional_wage` | A payroll component is added from `additional_wage_formula_ref` | Part VIII / XIV |
| `employee_choice` / `hr_choice` | The employee (or HR) selects among the enabled outcomes within a declared window; the choice is a workflow decision, audited, and irreversible once settled into a locked period | Part VIII / XIII |

**R-XII-016** `work_on_off_requires_approval = true` routes the intent to
`work_on_off_workflow_code` **before** the day is worked where the request is made in
advance, and to a post-facto regularisation workflow where it is not. An unapproved
weekly-off work day is settled at `outcome = none` and flagged for review — never silently
paid and never silently discarded.

**R-XII-017 — substitution.** Substitution moves the off, it does not add one:
`substitute(employee, original_off_date, replacement_date)` marks the original day
`weekly_off_substituted_worked` and the replacement `weekly_off_substituted`. The pair is
one ledger record with one approval. Entitlement per window is unchanged, which is what
makes substitution distinct from comp-off. `substitution_window_days` bounds how far the
replacement may sit from the original.

**R-XII-018 — swap.** A swap exchanges offs **between two employees** and requires both
employees' assent plus the approval declared by `swap_workflow_code`. It is rejected if it
would breach either employee's `max_consecutive_workdays` or `min_weekly_rest_hours`, or a
minimum-staffing rule (Part XIII §XIII.7.6 shares the staffing evaluator).

**R-XII-019 — rest and fatigue.** `max_consecutive_workdays` and `min_weekly_rest_hours`
are evaluated by the same service at three points: roster publish (Part XI §XI.9.6), a
work-on-off approval, and a swap or substitution. Severity is per tenant
(`block | warn | allow_with_approval`), never a code decision.

### XII.3.7 SPX exposure

**R-XII-020** The weekly-off engine exposes to SPX (extending Part VII §5.7 `day.*`):

```
day.is_weekly_off            Boolean
day.weekly_off_pattern       Code            -- the pattern that decided
day.weekly_off_slot_index    Number
day.is_weekly_off_worked     Boolean
day.is_substituted_off       Boolean
agg.streak(day.is_working_day = TRUE, backward)      -- consecutive workdays
IS_WEEKLY_OFF(d, scope)      Boolean         -- Part VII §5.6
```

No rule may test a weekday literal to infer an off. Testing `DAY_OF_WEEK(day.date) = 'sun'`
is legal SPX but is flagged by the publish-time linter with *"use `day.is_weekly_off`;
weekday literals do not honour branch, shift or roster patterns"* — the exact mistake
`PointsController::monthMeta():259` embodies.

---

## XII.4 The Holiday Engine

### XII.4.1 The holiday as an artefact

A **holiday** is an effective-dated, scoped, typed calendar entry
(`artefact_type = holiday`). A **holiday calendar** is a named, versioned collection of
them, so a customer can publish "Telangana 2027" as one approvable unit rather than
twenty-two independent rows.

### XII.4.2 The scope hierarchy

**R-XII-021** A holiday resolves **per employee**, through the hierarchy below, using
spine S3.1 scope predicates and S3.2 specificity. There is no tenant-wide holiday concept
in SmartPRS 3. *SmartPRS 2's `holidays.applies_to` has no reader and a holiday cancels the
whole tenant's absence sweep (`AbsenceService.php:111-113, 225-236`; NEW-ATT-011).*

| Layer | Scope dimension | Specificity weight (spine S3.2) | Typical use |
|---|---|---|---|
| India (national) | tenant default / `legal_entity` | 0 / 60 | Republic Day, Independence Day, Gandhi Jayanti |
| State / UT | `state` | 140 | State formation day, state-notified festivals |
| City / location | `location` | 200 | A city-specific civic holiday |
| Legal entity | `legal_entity` | 60 | Entity-level shutdown |
| Establishment | `establishment` | 120 | A registered establishment's notified list |
| Branch | `branch` | 180 | Branch-local observance |
| Department | `department` | 400 | Departmental shutdown |
| Shift | `shift` | 330 | A holiday that does not apply to a continuous-process shift |
| Employee category | `employee_category` | 320 | Off-roll agents vs on-roll staff |
| Employee | `employee` | 1000 | An individually granted day |

**R-XII-022** Resolution returns the **set** of holidays applying to an employee on a date,
not a single winner: two holidays may legitimately fall on one date (a national holiday and
a state festival). Where two entries of the *same* `holiday_code` and scope collide, spine
S3.2 applies and an unresolvable tie blocks publication.

**R-XII-023** The layering is **additive by default and suppressible explicitly**: a
narrower scope may publish an entry with `applicability = excluded` to remove a broader
holiday for that group (a continuous-process shift working a national holiday), and this
exclusion is itself an auditable, effective-dated artefact — never a deletion.

### XII.4.3 Holiday types

**R-XII-024** `holiday_type` is a **value set** (spine S9), not an enum, so a tenant may add
one. The seeded set:

| Code | Meaning | Typical default |
|---|---|---|
| `national` | Declared national holiday | mandatory, paid |
| `state` | State-notified | mandatory, paid |
| `festival` | Festival holiday | mandatory, paid |
| `religious` | Religious observance, often optional | optional, paid |
| `optional` | Employee elects from a list, against a quota | optional, paid |
| `restricted` | Restricted holiday (RH): elect N of M | optional, paid, quota-bound |
| `company` | Company-declared (foundation day) | mandatory, paid |
| `shutdown` | Plant/office shutdown | mandatory, paid or unpaid by declaration |
| `compensatory_closure` | Closure to be made up by working another day | mandatory, with a declared working-day substitution |
| `election` | Polling day / statutory paid time off | `<from rule pack>` |
| `mourning` | Declared at short notice | mandatory, paid |
| `local` | Location/branch-specific | mandatory, paid |
| `half_day` | Half-day closure | mandatory, paid, `day_fraction = 0.5` |
| `week_off_substitute` | The replacement day created by `on_holiday_collision = shift_*` | mandatory, paid |

**R-XII-025** No behaviour anywhere in SmartPRS 3 is keyed off a holiday's **name**. All
behaviour derives from `holiday_type` and the declared flags below. *This mirrors the
platform-wide prohibition in Part VII §5.6 on deriving semantics from names, which is the
single most reproducible defect family in SmartPRS 2.*

### XII.4.4 Holiday configuration items

Every item in brief Section 21, with its reader.

| # | Item | Field | Default | Read by |
|---|---|---|---|---|
| 1 | Paid / unpaid | `is_paid` | true | payable-day computation (§XII.6.3); payroll |
| 2 | Optional / mandatory | `is_optional` | false | day-type composition; election service |
| 3 | Restricted-holiday quota | `optional_quota`, `quota_window` | 0 / `calendar_year` | election service (§XII.4.5) |
| 4 | Eligibility | `eligibility_expr` (SPX Boolean) | `TRUE` | resolution; e.g. `service.completed_years >= 1` or `employee.gender = 'F'` for a gender-specific observance |
| 5 | Working allowed on the holiday | `work_allowed` | true | attendance day derivation |
| 6 | Approval to work | `work_requires_approval`, `work_workflow_code` | false | workflow engine |
| 7 | OT multiplier | `overtime_multiplier_ref` | null | OT settlement (Part XIV) |
| 8 | Comp-off on working | `comp_off_credit_rule_ref` | null | comp-off ledger (Part XIII §XIII.9) |
| 9 | Additional wage on working | `additional_wage_formula_ref` | null | payroll component |
| 10 | Substitute holiday | `substitute_policy` `{none, next_working_day, previous_working_day, nominated_date}`, `substitute_date` | `none` | day-type composition |
| 11 | Payslip impact | `payslip_display` `{hidden, count_only, named_line}`, `payslip_label_key` | `count_only` | payslip template (Part XX) |
| 12 | Day fraction | `day_fraction` | 1.0 | payable-day computation |
| 13 | Counts as a working day for entitlement | `counts_as_working_day` | false | leave accrual basis (Part XIII §XIII.4) |
| 14 | Weekly-off collision | `on_weekly_off_collision` `{absorb, shift_next_working_day, shift_previous_working_day, grant_substitute}` | `absorb` | day-type composition (§XII.5.2) |
| 15 | Leave collision | `on_leave_collision` `{holiday_wins_not_deducted, deduct_leave, policy_from_leave_type}` | `holiday_wins_not_deducted` | leave day counting (Part XIII §XIII.6) |
| 16 | Statutory basis | `statutory_ref` | null | rule-pack linkage; `<from rule pack>` |
| 17 | Notice requirement | `announce_days_before` | 0 | notification scheduling |
| 18 | Applicability | `applicability` `{included, excluded}` | `included` | scope layering (R-XII-023) |

**R-XII-026** Where a jurisdiction mandates a minimum number of paid holidays or names
specific days, that content is **rule-pack content** (spine S12) carrying
`source_reference`, not application data and not a value asserted in this blueprint. The
seeded calendar per state is `<from rule pack>`. The configuration validator warns when a
tenant's published calendar has fewer mandatory paid holidays than the resolved rule pack's
declared minimum, at the severity the pack declares.

### XII.4.5 Optional and restricted holidays

**R-XII-027** An optional or restricted holiday is not applied to an employee until they
**elect** it. Election is a first-class record, not a flag:

- The employee sees the elective list resolved for them, with the remaining quota.
- Election is bounded by `election_opens_on` / `election_closes_on` and by
  `optional_quota` within `quota_window` (`calendar_year | financial_year |
  leave_year | anniversary_year`).
- Election may require approval (`election_requires_approval`, workflow code).
- Election is reversible until the earlier of the closing date and the day itself, and
  every change is audited.
- A restricted-holiday day the employee did **not** elect is an ordinary working day for
  that employee; absence on it is an ordinary absence.

**R-XII-028** The quota is enforced **transactionally against the election ledger**, with
the same integrity properties as the leave ledger (Part XIII §XIII.11): a unique constraint
on `(tenant_id, employee_id, holiday_id)` plus a counted check inside the transaction, so
two simultaneous elections cannot both consume the last quota unit. *`LeaveController`'s
balance check has no transaction, no lock and no unique constraint
(`E-leave-requests-notifications.md` Q5.1); that failure shape is not repeated here.*

**R-XII-029** If an employee works on a holiday they elected, the day settles under the
holiday's `work_*` fields and the quota unit is **restored or consumed** per
`elected_but_worked` (`restore | consume`), declared per calendar. Defaults to `restore`.

---

## XII.5 Calendar composition — the resolved day type

### XII.5.1 The composition function

**R-XII-030** For an employee and a date, `CalendarService::resolveDayType()` composes, in
this order:

```
1. employment window        -- before DOJ or after LWD → day_type = not_employed
2. roster cell              -- an explicit cell for the date (Part XI §XI.9)
3. weekly-off pattern       -- §XII.3
4. holiday set              -- §XII.4, including elections
5. approved leave           -- Part XIII, including day_part
6. collision resolution     -- §XII.5.2
7. observed attendance      -- punches, corrections (Part XI §XI.2)
→ ResolvedDay
```

```
ResolvedDay {
  date, employee_id
  day_type            Code    -- see §XII.5.3
  day_fraction_worked Number  -- 0, 0.5, 1 or a computed fraction
  is_weekly_off       Boolean
  is_holiday          Boolean
  holiday_ids         List
  holiday_type        Code|null
  leave_type          Code|null
  leave_day_part      Code|null
  shift_id            Code|null
  weekly_off_pattern_version_id, holiday_calendar_version_id,
  roster_version_id, leave_ids, rule_versions[]
  trace               DecisionTrace
}
```

**R-XII-031** `resolveDayType` returns a row for **every** employee-day in range, including
days with no punches and no records. *SmartPRS 2's attendance report emits a row only where
punches exist (`DEFECT-B-10`), which is precisely why a weekly off and a holiday are
invisible there.*

### XII.5.2 Precedence when two collide

Collisions are resolved by declared policy, never by evaluation order. The table below is
the complete set; each cell names the field that decides.

| Collision | Decided by | Options | Default | Origin |
|---|---|---|---|---|
| **Weekly off ∧ holiday** | the *holiday's* `on_weekly_off_collision`, falling back to the *pattern's* `on_holiday_collision` when the holiday declares `absorb` | `absorb` (one day, `day_type = weekly_off_and_holiday`) · `shift_next_working_day` · `shift_previous_working_day` · `grant_substitute` (a `week_off_substitute` holiday is created) | `absorb` | Neither concept exists jointly in SmartPRS 2 |
| **Weekly off ∧ approved leave** | the *leave type's* adjacency policy (Part XIII §XIII.8), falling back to the pattern's `on_leave_collision` | `off_wins_not_deducted` · `deduct_leave` (the sandwich case) · `policy_from_leave_type` | `off_wins_not_deducted` | *`LeaveController.php:338` counts inclusive calendar days and always deducts; payroll credits only working days (`PayrollGenController.php:1073-1077`) — the two disagree by design (DEF-LVE-06)* |
| **Holiday ∧ approved leave** | the *holiday's* `on_leave_collision`, then the leave type's adjacency policy | `holiday_wins_not_deducted` · `deduct_leave` · `policy_from_leave_type` | `holiday_wins_not_deducted` | as above |
| **Weekly off ∧ roster cell says working** | roster cell wins; the day becomes `weekly_off_worked_scheduled` and requires the work-on-off approval if configured | — | roster wins | *SmartPRS 2: `roster.status='off'` is the only signal, so the opposite case is unrepresentable* |
| **Holiday ∧ roster cell says working** | `work_allowed` gates it; if false, roster publish **blocks** | — | block | none |
| **Weekly off ∧ punches present** | §XII.3.6 outcome | — | `none` | *`PayrollGenController.php:429-431` merely suppresses the day-cut* |
| **Holiday ∧ punches present** | holiday `work_*` fields | — | — | none |
| **Optional holiday not elected ∧ absence** | ordinary absence | — | — | none |
| **Two holidays on one date** | both recorded; payable impact counted once | — | — | *`holidays` has no uniqueness constraint on `(tenant_id, date)`* |
| **Leave ∧ leave (two approved leaves, same date)** | rejected at approval time by the leave ledger (Part XIII §XIII.11) | — | reject | *SmartPRS 2 has no such check* |

**R-XII-032** Every collision outcome is written into the trace with the field that decided
it and the artefact version it came from. A user asking "why was 26 January not deducted
from my leave?" gets *"Holiday REPUBLIC-DAY (calendar 'India National 2027' v3),
`on_leave_collision = holiday_wins_not_deducted`"* — not a support ticket.

### XII.5.3 The day-type value set

**R-XII-033** `day_type` is a value set (adding a value is data, spine S16). Seeded:

```
not_employed · working · weekly_off · holiday · weekly_off_and_holiday
weekly_off_worked · weekly_off_worked_scheduled · weekly_off_substituted
weekly_off_substituted_worked · holiday_worked · optional_holiday_not_elected
on_leave_full · on_leave_first_half · on_leave_second_half · on_leave_hourly
on_duty · work_from_home · training · suspension · lay_off
absent · absent_unapproved · unpaid_leave · strike · lockout
```

**R-XII-034** `day_type` is stored on the materialised calendar row and on
`attendance_days`, so a muster roll can render every day of the month for every employee
with a defensible label. *SmartPRS 2 has no stored attendance status at all
(`B-attendance.md` §1.6) — status is recomputed at display time by whichever of several
divergent calculators the screen uses.*

### XII.5.4 Materialisation and recomputation

**R-XII-035** The calendar is materialised for: the current period, the next period, every
settled period, and any range a recalculation touches. Materialisation is idempotent and
produces a **preview diff** before it overwrites a range that overlaps a locked period,
reusing the Part XI §XI.2.5 recalculation machinery rather than a second mechanism.

**R-XII-036** Publishing a weekly-off pattern, a holiday calendar or a holiday that affects
dates already materialised **requires the impact preview** (spine S4, `high_risk`): number
of employees affected, number of days changing type, the periods touched, and whether any
of them is locked. Publication into a locked period requires the reopen workflow.

---

## XII.6 The three day definitions, defined once

These three counts are used by Part XIII (leave), Part XIV (payroll), reports, points and
the simulator. **They are defined here and nowhere else.** *SmartPRS 2 has three
incompatible working-day definitions (`CALC-B-16`) and a fourth in the absence sweep.*

### XII.6.1 Calendar day

**R-XII-037**

```
CALENDAR_DAYS(from, to) = number of dates in the inclusive range [from, to]
```

Independent of employee, pattern and calendar. Used only where a statute or a contract
genuinely counts calendar days, and never as a proxy for anything else.

### XII.6.2 Working day

**R-XII-038**

```
WORKING_DAYS(from, to, employee) =
    COUNT of dates d in [from, to] where
        resolveDayType(employee, d).day_type is in the tenant's
        declared working_day_set
```

`working_day_set` is **configuration**, seeded as
`{working, weekly_off_worked, weekly_off_worked_scheduled, holiday_worked, on_duty,
work_from_home, training}`. Whether `on_duty`, `work_from_home` and `training` count is a
per-tenant declaration, because customers differ and the answer changes the LOP
denominator.

**R-XII-039** `WORKING_DAYS` never counts a day outside the employment window, and a
mid-period joiner or leaver is bounded by DOJ/LWD inside the function, not by the caller.
*SmartPRS 2's leaver proration does not exist at all and its joiner proration is wrong
under a cut-off cycle (`CONSOLIDATED-REQUIREMENTS.md` item 12, DEF-P05).*

### XII.6.3 Payable day

**R-XII-040**

```
PAYABLE_DAYS(from, to, employee) =
    Σ over dates d in [from, to] of payable_fraction(d)

payable_fraction(d) =
    0                                   if day_type = not_employed
    0                                   if day_type in {absent, absent_unapproved,
                                                        unpaid_leave, strike, lockout}
    holiday.day_fraction                if day_type in {holiday, weekly_off_and_holiday}
                                            and holiday.is_paid
    0                                   if day_type = holiday and NOT holiday.is_paid
    1                                   if day_type in {weekly_off, weekly_off_substituted}
                                            and paid_weekly_off = TRUE
    0                                   if day_type = weekly_off and paid_weekly_off = FALSE
    leave_type.payable_fraction(day_part)  if day_type is a leave type
    1 − day_penalty_fraction(d)         otherwise, where day_penalty_fraction comes
                                        from the work-time policy engine (Part XI §XI.10)
```

`paid_weekly_off` is a declared property of the pay group, not of the weekly-off pattern,
because it is a *remuneration* question: a monthly-rated employee's weekly off is inside
the monthly wage, a daily-rated employee's may not be. The Part XIV LOP basis
(`working | calendar | fixed30`) then divides by the corresponding denominator.

**R-XII-041** `PAYABLE_DAYS` is typed `Days` in SPX and never silently coerces to a
`Number` or to `Money` (spine S5.1). *SmartPRS 2 measures absence in working days and
values it in calendar days depending on `lop_basis`, with no type protection.*

**R-XII-042** The three functions are exposed to SPX exactly as
`CALENDAR_DAYS`, `WORKING_DAYS`, `PAYABLE_DAYS` (Part VII §5.6) and have **one**
implementation behind them. A module computing its own is a build failure (R-XII-002).

---

## XII.7 Data model

All tables to the Section 123 standard. Conventions per spine S16: `snake_case`, plural
tables, `bigint` internal PK plus a UUIDv7 public id on aggregates, `tenant_id NOT NULL` in
every table and in every unique index (Law 6), FKs on every relationship with declared
`ON DELETE`, no database `ENUM` (value sets instead), `DECIMAL(18,4)` money at rest, dates
as `DATE` and instants as `DATETIME` UTC plus a business date.

Legend — **Hist**: how history is kept. **SD**: soft delete. **Audit**: audit-ledger
coverage. **PII**: classification per spine S13.

### XII.7.1 `weekly_off_patterns`

*Purpose:* the pattern master — identity only. Behaviour lives in the versioned rows.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | N | auto | PK |
| `uuid` | char(36) | N | — | UUIDv7, public id |
| `tenant_id` | bigint unsigned | N | — | FK → `tenants.id` ON DELETE RESTRICT |
| `code` | varchar(64) | N | — | stable business key |
| `name` | varchar(160) | N | — | display only |
| `description` | varchar(500) | Y | NULL | |
| `is_system` | tinyint(1) | N | 0 | seeded patterns cannot be deleted |
| `status` | varchar(24) | N | `active` | value set `config_status` |
| `created_by`, `created_at`, `updated_by`, `updated_at` | | | | |
| `deleted_at` | datetime | Y | NULL | soft delete |

PK `id` · Unique `(tenant_id, code)` · Index `(tenant_id, status)`
Tenant scope: row-level, applied by the persistence layer. Hist: versions (below). SD: yes.
Audit: create/rename/deactivate. PII: none.

### XII.7.2 `weekly_off_pattern_versions`

*Purpose:* the effective-dated, scoped, publishable definition (spine S4).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | N | auto | PK |
| `tenant_id` | bigint unsigned | N | — | FK → `tenants.id` |
| `pattern_id` | bigint unsigned | N | — | FK → `weekly_off_patterns.id` ON DELETE CASCADE |
| `version_no` | int unsigned | N | — | |
| `status` | varchar(24) | N | `draft` | `draft/testing/pending_approval/published/superseded/archived` |
| `scope_json` | json | N | `{}` | spine S3.1 predicates |
| `specificity_weight` | int unsigned | N | 0 | computed at publish, stored for fast ordering |
| `priority` | int | N | 0 | S3.2 tie-break |
| `effective_from` | date | N | — | |
| `effective_to` | date | Y | NULL | closed on supersession |
| `entitlement_mode` | varchar(32) | N | — | value set |
| `frequency_window` | varchar(24) | N | `week` | value set |
| `offs_per_window` | decimal(4,1) | N | 1.0 | fractional permitted (e.g. 1.5 offs/week alternating) |
| `variability` | varchar(16) | N | `fixed` | |
| `fixed_weekdays` | json | Y | NULL | `["sun"]` |
| `allowed_weekdays` | json | Y | NULL | |
| `nth_rules` | json | Y | NULL | `[{weekday, occurrences[], applies_when}]` |
| `missing_occurrence` | varchar(24) | N | `skip` | |
| `rotation_ref` | varchar(64) | Y | NULL | FK-by-code → `rotation_patterns.code` |
| `composite_members` | json | Y | NULL | |
| `week_start_day` | varchar(8) | N | `mon` | |
| `week_numbering` | varchar(32) | N | `occurrence_of_weekday` | |
| `fortnight_anchor` | date | Y | NULL | |
| `selection_mode` | varchar(24) | N | `fixed` | |
| `election_opens_days_before` | smallint | Y | NULL | |
| `election_closes_days_before` | smallint | Y | NULL | |
| `election_change_limit` | smallint | N | 0 | |
| `adjacency` | varchar(32) | N | `any` | |
| `min_gap_days_between_offs` | smallint | N | 0 | |
| `min_workdays_before_entitlement` | smallint | N | 0 | |
| `carry_unearned_off` | tinyint(1) | N | 0 | |
| `joining_week_treatment` | varchar(32) | N | `pro_rata` | |
| `exit_week_treatment` | varchar(32) | N | `pro_rata` | |
| `proration_rounding_mode` | varchar(16) | N | — | **no default** (S5.4) |
| `proration_rounding_unit` | decimal(6,4) | N | — | |
| `on_holiday_collision` | varchar(40) | N | `absorb` | |
| `on_leave_collision` | varchar(40) | N | `off_wins_not_deducted` | |
| `work_on_off_requires_approval` | tinyint(1) | N | 0 | |
| `work_on_off_workflow_code` | varchar(64) | Y | NULL | |
| `work_on_off_outcome` | varchar(32) | N | `none` | |
| `comp_off_credit_rule_ref` | varchar(64) | Y | NULL | |
| `overtime_multiplier_ref` | varchar(64) | Y | NULL | |
| `additional_wage_formula_ref` | varchar(64) | Y | NULL | |
| `substitution_allowed` | tinyint(1) | N | 0 | |
| `substitution_window_days` | smallint | N | 30 | |
| `substitution_requires_approval` | tinyint(1) | N | 1 | |
| `swap_allowed` | tinyint(1) | N | 0 | |
| `swap_workflow_code` | varchar(64) | Y | NULL | |
| `max_consecutive_workdays` | smallint | Y | NULL | |
| `max_consecutive_workdays_severity` | varchar(24) | N | `warn` | |
| `min_rest_hours_between_shifts` | decimal(5,2) | Y | NULL | |
| `min_weekly_rest_hours` | decimal(5,2) | Y | NULL | |
| `rest_measured_from` | varchar(24) | N | `shift_end` | |
| `created_by, created_at, submitted_by, approved_by, approved_at, published_at` | | | | spine S4 |
| `superseded_by_version_id` | bigint unsigned | Y | NULL | FK self |
| `reason`, `change_note` | varchar(500) | Y | NULL | mandatory for `high_risk` |
| `checksum` | char(64) | N | — | |

PK `id` · Unique `(tenant_id, pattern_id, version_no)` ·
Index `(tenant_id, status, effective_from, effective_to)` ·
Index `(tenant_id, specificity_weight DESC, priority DESC)`
Overlap on `(pattern_id, scope_json, effective range)` rejected at publish (spine S4).
Hist: append-only versions. SD: no (archive by status). Audit: full, field-level. PII: none.

### XII.7.3 `holiday_calendars` / `holiday_calendar_versions`

*Purpose:* a named, approvable, effective-dated collection of holidays.

`holiday_calendars`: `id, uuid, tenant_id, code, name, description, jurisdiction_hint,
is_system, status, audit columns, deleted_at`.
Unique `(tenant_id, code)`.

`holiday_calendar_versions`: the spine S4 version envelope —
`id, tenant_id, calendar_id, version_no, status, scope_json, specificity_weight, priority,
effective_from, effective_to, calendar_year_basis, rule_pack_ref, approval columns,
superseded_by_version_id, reason, change_note, checksum`.
`rule_pack_ref` names the statutory pack version a seeded calendar derived from (spine S12),
so the source of every seeded date is traceable. Unique
`(tenant_id, calendar_id, version_no)`.

### XII.7.4 `holidays`

*Purpose:* one holiday entry inside a calendar version.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | N | auto | PK |
| `uuid` | char(36) | N | — | |
| `tenant_id` | bigint unsigned | N | — | FK → `tenants.id` |
| `calendar_version_id` | bigint unsigned | N | — | FK → `holiday_calendar_versions.id` ON DELETE CASCADE |
| `holiday_code` | varchar(64) | N | — | stable key, e.g. `REPUBLIC-DAY`; **behaviour keys off this and `holiday_type`, never off `name`** |
| `name` | varchar(160) | N | — | display; translatable via the template store |
| `holiday_type` | varchar(32) | N | — | value set (§XII.4.3) |
| `holiday_date` | date | N | — | |
| `end_date` | date | Y | NULL | multi-day shutdowns |
| `day_fraction` | decimal(3,2) | N | 1.00 | 0.50 for a half-day closure |
| `is_paid` | tinyint(1) | N | 1 | |
| `is_optional` | tinyint(1) | N | 0 | |
| `optional_quota` | smallint | Y | NULL | when optional/restricted |
| `quota_window` | varchar(24) | Y | NULL | `calendar_year/financial_year/leave_year` |
| `applicability` | varchar(16) | N | `included` | `included/excluded` (R-XII-023) |
| `scope_json` | json | N | `{}` | narrower scope than the calendar version |
| `specificity_weight` | int unsigned | N | 0 | |
| `priority` | int | N | 0 | |
| `eligibility_expr` | text | Y | NULL | SPX Boolean, AST stored alongside |
| `eligibility_ast` | json | Y | NULL | compiled (spine S5) |
| `work_allowed` | tinyint(1) | N | 1 | |
| `work_requires_approval` | tinyint(1) | N | 0 | |
| `work_workflow_code` | varchar(64) | Y | NULL | |
| `overtime_multiplier_ref` | varchar(64) | Y | NULL | |
| `comp_off_credit_rule_ref` | varchar(64) | Y | NULL | |
| `additional_wage_formula_ref` | varchar(64) | Y | NULL | |
| `substitute_policy` | varchar(32) | N | `none` | |
| `substitute_date` | date | Y | NULL | when `nominated_date` |
| `on_weekly_off_collision` | varchar(40) | N | `absorb` | |
| `on_leave_collision` | varchar(40) | N | `holiday_wins_not_deducted` | |
| `counts_as_working_day` | tinyint(1) | N | 0 | leave-accrual basis |
| `payslip_display` | varchar(24) | N | `count_only` | |
| `payslip_label_key` | varchar(64) | Y | NULL | |
| `elected_but_worked` | varchar(16) | N | `restore` | |
| `announce_days_before` | smallint | N | 0 | |
| `statutory_ref` | varchar(120) | Y | NULL | `<from rule pack>` |
| `source_reference` | varchar(300) | Y | NULL | carried from the rule pack |
| audit columns | | | | |

PK `id` · Unique `(tenant_id, calendar_version_id, holiday_code, holiday_date)` ·
Index `(tenant_id, holiday_date)` · Index `(tenant_id, calendar_version_id, holiday_date)` ·
Index `(tenant_id, holiday_type)`
Hist: by calendar version. SD: no. Audit: full. PII: none.
*Contrast: SmartPRS 2's `holidays` is `(tenant_id, date, name, applies_to)` with no
uniqueness, no type, no scope reader and no version (`2026_01_01_000200:100-106`).*

### XII.7.5 `holiday_elections`

*Purpose:* an employee's election of an optional or restricted holiday, with quota
integrity.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | N | auto | PK |
| `tenant_id` | bigint unsigned | N | — | FK |
| `employee_id` | bigint unsigned | N | — | FK → `employees.id` ON DELETE RESTRICT |
| `holiday_id` | bigint unsigned | N | — | FK → `holidays.id` ON DELETE RESTRICT |
| `quota_window_key` | varchar(24) | N | — | e.g. `FY2027-28`; the quota bucket |
| `status` | varchar(24) | N | `elected` | `elected/pending_approval/withdrawn/rejected` |
| `elected_at` | datetime | N | — | UTC |
| `elected_by_user_id` | bigint unsigned | N | — | |
| `workflow_instance_id` | bigint unsigned | Y | NULL | FK when approval required |
| `withdrawn_at`, `withdrawn_by_user_id` | | Y | NULL | |
| audit columns | | | | |

PK `id` · **Unique `(tenant_id, employee_id, holiday_id)`** — the database constraint that
makes double election impossible · Index `(tenant_id, employee_id, quota_window_key, status)`
Hist: status transitions in the audit ledger. SD: no (withdraw is a status). Audit: full.
PII: **P2** — a religious-observance election can disclose religion; masked by role and
excluded from general exports by default.

### XII.7.6 `employee_calendar_days`

*Purpose:* the materialised resolved day type, one row per employee per date. The single
table every report, register and settlement reads.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | N | auto | PK |
| `tenant_id` | bigint unsigned | N | — | FK |
| `employee_id` | bigint unsigned | N | — | FK ON DELETE RESTRICT |
| `calendar_date` | date | N | — | business date |
| `generation` | int unsigned | N | 1 | supersession counter (Law 3) |
| `is_current` | tinyint(1) | N | 1 | exactly one current row per (employee, date) |
| `day_type` | varchar(40) | N | — | value set |
| `is_weekly_off` | tinyint(1) | N | 0 | |
| `weekly_off_pattern_version_id` | bigint unsigned | Y | NULL | FK |
| `weekly_off_slot_index` | smallint | Y | NULL | |
| `is_holiday` | tinyint(1) | N | 0 | |
| `holiday_ids` | json | Y | NULL | may be more than one |
| `holiday_type` | varchar(32) | Y | NULL | dominant type for display |
| `holiday_calendar_version_id` | bigint unsigned | Y | NULL | FK |
| `roster_version_id` | bigint unsigned | Y | NULL | FK |
| `shift_id` | bigint unsigned | Y | NULL | FK |
| `leave_ids` | json | Y | NULL | |
| `leave_type_code` | varchar(64) | Y | NULL | **code, never a name** |
| `leave_day_part` | varchar(16) | Y | NULL | |
| `payable_fraction` | decimal(4,3) | N | 1.000 | §XII.6.3 |
| `counts_as_working_day` | tinyint(1) | N | 1 | §XII.6.2 |
| `collision_resolution` | varchar(64) | Y | NULL | which rule decided |
| `trace_id` | char(36) | Y | NULL | → `decision_traces` |
| `computed_at` | datetime | N | — | UTC |
| `superseded_at` | datetime | Y | NULL | |
| `period_lock_id` | bigint unsigned | Y | NULL | FK when frozen |

PK `id` ·
**Unique `(tenant_id, employee_id, calendar_date, generation)`** ·
**Unique `(tenant_id, employee_id, calendar_date)` WHERE `is_current = 1`** (partial /
functional index; on MySQL a generated `current_key` column carries the constraint) ·
Index `(tenant_id, calendar_date, day_type)` ·
Index `(tenant_id, employee_id, calendar_date DESC)`
Tenant scope: structural. Hist: generations, never updated in place. SD: no. Audit:
generation changes recorded with the triggering artefact version. PII: **P2** —
`leave_type_code` and `holiday_type` are health- and religion-adjacent; masked by role.

### XII.7.7 `weekly_off_allocations`

*Purpose:* which specific dates were allocated as offs in a variable, elected, rotational or
roster-driven window — so the entitlement can be audited and an election can be changed
within its window.

`id, tenant_id, employee_id, pattern_version_id, window_key (e.g. 2027-W14),
window_start, window_end, entitled_count decimal(4,1), allocated_dates json,
allocation_source (fixed|elected|manager|roster|rotation|default_order),
elected_by_user_id, elected_at, locked_at, generation, is_current, audit columns`.
Unique `(tenant_id, employee_id, pattern_version_id, window_key, generation)`;
unique current row per `(tenant_id, employee_id, window_key)`.
Hist: generations. Audit: full. PII: none.

### XII.7.8 `weekly_off_substitutions`

*Purpose:* the substitution and swap ledger.

`id, uuid, tenant_id, kind (substitution|swap), employee_id, original_off_date,
replacement_date, counterparty_employee_id (swap only), counterparty_original_off_date,
counterparty_replacement_date, reason varchar(500), status
(requested|approved|rejected|cancelled|applied), workflow_instance_id,
requested_by_user_id, requested_at, decided_by_user_id, decided_at,
applied_at, audit columns`.
Unique `(tenant_id, employee_id, original_off_date)` WHERE `status = 'applied'` —
one applied substitution per off day.
Index `(tenant_id, status, replacement_date)`. Audit: full, with a workflow instance id.
PII: none.

### XII.7.9 `work_on_off_settlements`

*Purpose:* what happened when someone worked a weekly off or a holiday. It is the join
between the calendar and the comp-off ledger, OT and additional wage — so no module has to
re-derive it.

`id, uuid, tenant_id, employee_id, calendar_date, day_type, source
(weekly_off|holiday|weekly_off_and_holiday), worked_minutes, approval_required,
workflow_instance_id, outcome (none|comp_off|overtime|additional_wage),
chosen_by (system|employee|hr), chosen_at,
comp_off_credit_id (FK → comp_off_ledger.id, Part XIII), ot_minutes,
ot_multiplier_ref, additional_wage_component_code, additional_wage_amount decimal(18,4),
settled_in_period_id, trace_id, audit columns`.
Unique `(tenant_id, employee_id, calendar_date)`.
Index `(tenant_id, settled_in_period_id)`. Audit: full. PII: none. Money: `DECIMAL(18,4)`
at rest, integer paise in memory (Law 7).

### XII.7.10 What is deliberately absent

- No table holds a weekly-off *setting* as a scalar on the tenant. `weekly_off_day` and
  `sat_off_mode` (`statutory_settings`, `SettingsController.php:51-52`) have no successor
  as settings; they become seeded `weekly_off_pattern_versions`.
- No `holidays.applies_to`. Scope is `scope_json` and it has readers (R-XII-021).
- No `weekoff_action` / `no_cut_on_weekoff`. Their intent is carried by
  `on_holiday_collision`, `work_on_off_outcome` and the work-time policy engine's own
  conditions on `day.is_weekly_off` — each with a named reader (R-XII-012).

---

## XII.8 Configuration surface — the Section 127 test

The brief's standard is that *"3 late arrivals over 10 minutes = half day" must not require
code.* The equivalent tests for this part, all of which must be pure configuration:

| # | Requirement stated by a customer | Configuration that satisfies it | Code change? |
|---|---|---|---|
| 1 | "Hyderabad is off 2nd and 4th Saturday, Pune 1st and 3rd" | two `weekly_off_pattern_versions`, scoped by branch | none |
| 2 | "Night-shift staff take Tuesday, not Sunday" | one pattern scoped `shift IN [Night-C]` | none |
| 3 | "Anyone who works a weekly off gets a comp-off expiring in 60 days" | `work_on_off_outcome = comp_off` + a comp-off credit rule with `expiry_days = 60` | none |
| 4 | "From April, Saturdays become alternate instead of full" | a new pattern version, `effective_from = 01-Apr`, superseding | none |
| 5 | "Employees may pick any one day as their off, changing it twice a quarter" | `variability = variable`, `selection_mode = employee_elected`, `election_change_limit = 2`, `frequency_window = week`, quota window quarterly | none |
| 6 | "Never more than 6 consecutive working days" | `max_consecutive_workdays = 6`, severity `block` | none |
| 7 | "If a holiday lands on Sunday, we take the Monday" | `on_weekly_off_collision = shift_next_working_day` | none |
| 8 | "Restricted holidays: choose 2 from a list of 9" | `holiday_type = restricted`, `optional_quota = 2`, nine entries | none |
| 9 | "Our Chennai office observes a local holiday nobody else does" | one holiday with `scope.location IN [Chennai]` | none |
| 10 | "This national holiday does not apply to the continuous-process shift" | a holiday row with `applicability = excluded`, `scope.shift IN [CP]` | none |
| 11 | "Working on a holiday pays double" | `overtime_multiplier_ref` → a rate artefact of 2.0 | none |
| 12 | "Sunday is unpaid for our daily-rated workers" | `paid_weekly_off = false` on that pay group | none |
| 13 | "A holiday should show as a named line on the payslip" | `payslip_display = named_line` | none |
| 14 | "Our leave year is April–March, so restricted-holiday quotas reset in April" | `quota_window = financial_year` | none |
| 15 | "Managers assign the offs for the shop floor, employees pick their own in the back office" | two patterns, `selection_mode = manager_assigned` and `employee_elected`, scoped by department | none |

---

## XII.9 Worked SPX

All examples type-check against Part VII §5 and use only catalogued functions and declared
namespaces.

### XII.9.1 The alternate-Saturday pattern, as the evaluator sees it

The pattern is data, but its *effect* is expressible and therefore testable in the
simulator:

```
-- Is today a weekly off under HYD-2-4-SAT?
day.weekday = 'sun'
OR ( day.weekday = 'sat'
     AND day.date IN [ NTH_WEEKDAY(SOMONTH(day.date), 'sat', 2),
                       NTH_WEEKDAY(SOMONTH(day.date), 'sat', 4) ] )
```

**Worked value.** February 2027 begins on a Monday. Saturdays fall on 6, 13, 20, 27.
`NTH_WEEKDAY(#2027-02-01#, 'sat', 2) = #2027-02-13#` and
`NTH_WEEKDAY(…, 'sat', 4) = #2027-02-27#`. So 13 and 27 February are offs.

*Under SmartPRS 2's `ceil(day/7)` the same month yields:* day 6 → `ceil(6/7) = 1`;
day 13 → 2; day 20 → 3; day 27 → 4. Here the two agree. Now take August 2027, which begins
on a Sunday: Saturdays are 7, 14, 21, 28. `ceil(7/7) = 1`, `ceil(14/7) = 2`,
`ceil(21/7) = 3`, `ceil(28/7) = 4` — agrees again. Now take May 2027, beginning Saturday:
Saturdays are 1, 8, 15, 22, 29. `ceil(1/7) = 1`, `ceil(8/7) = 2`, `ceil(15/7) = 3`,
`ceil(22/7) = 4`, `ceil(29/7) = 5` — agrees. The divergence appears whenever a Saturday
falls on a day-of-month that crosses a 7-boundary relative to its occurrence index, e.g.
a month beginning on **Sunday** where the first Saturday is day 7 and a month beginning on
**Monday** where the first Saturday is day 6: in a 31-day month starting Friday, Saturdays
are 2, 9, 16, 23, 30 → `ceil` gives 1, 2, 3, 4, 5 — still aligned; but in a month starting
Sunday with Saturdays 7, 14, 21, 28 the **fifth** Saturday never exists while `ceil(day/7)`
would assign index 5 to any day 29–31 that happened to be a Saturday in an adjacent
configuration. **The precise point is not that every month diverges — it is that the two
formulas are different functions that happen to agree in many months, so the defect is
intermittent and undetectable by spot-checking.** `NTH_WEEKDAY` is correct by construction
and is the only mechanism SmartPRS 3 offers.

### XII.9.2 Working on a weekly off → comp-off

Rule `RL-CAL-001`, decision point `attendance.day.settle`, `on_conflict: first_match`:

```
when:
  day.is_weekly_off = TRUE
  AND attendance.worked_minutes >= 240
  AND day.is_holiday = FALSE

then:
  CREATE_COMP_OFF( units:  IF( attendance.worked_minutes >= shift.full_day_minutes,
                               1.0, 0.5 ),
                   expiry_ref: 'COMPOFF-60D' )
  SET_STATUS('weekly_off_worked')
  NOTIFY('calendar.weekly_off.worked')

except:
  employee.employment_type = 'CONTRACT_EXEMPT'
```

**Worked trace.** Ravi, Hyderabad, Saturday 13-Feb-2027 (2nd Saturday, an off under
`WO-HYD` v4). Punches 09:34–18:12, worked 458 minutes; `shift.full_day_minutes = 480`.
`458 >= 240` → true; `458 >= 480` → false → `units = 0.5`. Outcome: 0.5 comp-off credited,
expiring 14-Apr-2027, day type `weekly_off_worked`.
*In SmartPRS 2 this day produces nothing: comp-off does not exist (`B-attendance.md` Q4),
and `roster.status='off'` merely suppresses the day-cut (`PayrollGenController.php:429-431`).*

### XII.9.3 Holiday falling on a weekly off → substitute Monday

Rule `RL-CAL-002`, decision point `calendar.compose`:

```
when:
  day.is_holiday = TRUE
  AND day.is_weekly_off = TRUE
  AND HOLIDAY_TYPE(day.date, 'employee') IN ['national', 'state']

then:
  GRANT_SUBSTITUTE_HOLIDAY( date_expr: NEXT_WORKING_DAY(day.date),
                            holiday_type: 'week_off_substitute' )
```

**Worked value.** 26-Jan-2027 is a Tuesday, so this does not fire. 15-Aug-2027 is a
Sunday and `WO-TENANT-DEFAULT` makes Sunday an off; the rule fires and creates a
`week_off_substitute` holiday on Monday 16-Aug-2027, which then removes 16 August from
`WORKING_DAYS` for everyone in scope and appears on the muster roll with its own label.

### XII.9.4 Maximum consecutive working days as a publish-time guard

```
when:
  agg.streak(day.is_working_day = TRUE, backward) > policy.value('max_consecutive_workdays')
then:
  FLAG_FOR_REVIEW('max_consecutive_workdays_exceeded')
  BLOCK('Roster would place this employee on a 7th consecutive working day')
```

Evaluated at roster publish and at work-on-off approval (R-XII-019).

### XII.9.5 The LOP denominator, once

```
period.working_days  = WORKING_DAYS(period.start, period.end, 'employee')
period.payable_days  = PAYABLE_DAYS(period.start, period.end, 'employee')
lop_days             = period.working_days - period.payable_days
```

**Worked value.** March 2027, 31 calendar days. Employee at Hyderabad: 4 Sundays + 2nd and
4th Saturday = 6 weekly offs; one national holiday on a Tuesday. `WORKING_DAYS = 31 − 6 − 1
= 24`. The employee is absent 2 days and takes 1 day of paid casual leave.
`PAYABLE_DAYS` = 24 − 2 = 22 working-day fractions **plus** the paid holiday and the paid
weekly offs where `paid_weekly_off = true`; under `lop_basis = working` the LOP factor is
`22/24 = 0.9167`. Under `lop_basis = calendar` it is `29/31 = 0.9355`, and under `fixed30`
it is `28/30 = 0.9333`. All three come from the same two functions and the declared basis;
none is recomputed anywhere else. *`CONSOLIDATED-REQUIREMENTS.md` records this exact
three-basis assertion as a P0 regression test at line 2389.*

---

## XII.10 API surface

All under `/api/v3`, OpenAPI-generated, UUID public ids only, cursor pagination,
idempotency keys on unsafe methods (spine S17). Every route carries an explicit policy
binding (Law 5); the permission is named in the last column.

| Method | Path | Purpose | Permission |
|---|---|---|---|
| GET | `/calendar/weekly-off-patterns` | list patterns with resolved scope | `calendar.pattern.view` |
| POST | `/calendar/weekly-off-patterns` | create a pattern | `calendar.pattern.manage` |
| POST | `/calendar/weekly-off-patterns/{uuid}/versions` | create a draft version | `calendar.pattern.manage` |
| POST | `/calendar/weekly-off-patterns/{uuid}/versions/{v}/simulate` | simulate over an employee and range | `calendar.pattern.simulate` |
| POST | `/calendar/weekly-off-patterns/{uuid}/versions/{v}/impact` | impact preview (employees, days changing) | `calendar.pattern.manage` |
| POST | `/calendar/weekly-off-patterns/{uuid}/versions/{v}/publish` | publish (maker-checker if `high_risk`) | `calendar.pattern.publish` |
| GET | `/calendar/holiday-calendars` | list calendars | `calendar.holiday.view` |
| POST | `/calendar/holiday-calendars` | create | `calendar.holiday.manage` |
| POST | `/calendar/holiday-calendars/{uuid}/versions` | draft a version | `calendar.holiday.manage` |
| POST | `/calendar/holiday-calendars/{uuid}/versions/{v}/holidays` | add/replace entries (bulk) | `calendar.holiday.manage` |
| POST | `/calendar/holiday-calendars/{uuid}/versions/{v}/import` | import from a rule pack or a file | `calendar.holiday.manage` |
| POST | `/calendar/holiday-calendars/{uuid}/versions/{v}/publish` | publish | `calendar.holiday.publish` |
| GET | `/calendar/holidays?from=&to=&employee=` | holidays resolved **for an employee** | `calendar.holiday.view` (self always permitted) |
| GET | `/me/holidays/optional` | my elective list with remaining quota | self only |
| POST | `/me/holidays/optional/{holidayUuid}/elect` | elect (idempotent) | self only |
| DELETE | `/me/holidays/optional/{holidayUuid}/elect` | withdraw within the window | self only |
| GET | `/me/weekly-off/elections?window=` | my elected offs for a window | self only |
| PUT | `/me/weekly-off/elections/{windowKey}` | set my elected offs (validated) | self only |
| POST | `/calendar/substitutions` | request a substitution | `calendar.substitution.request` |
| POST | `/calendar/substitutions/{uuid}/decide` | approve/reject | `calendar.substitution.decide` |
| POST | `/calendar/swaps` | request a swap (both employees) | `calendar.swap.request` |
| POST | `/calendar/swaps/{uuid}/consent` | counterparty consent | self only |
| GET | `/calendar/days?employee=&from=&to=` | resolved day types (the muster-roll feed) | `calendar.day.view` scoped |
| GET | `/calendar/days/{employeeUuid}/{date}/explain` | the full trace (spine S15) | `calendar.day.view` scoped |
| POST | `/calendar/recalculate` | idempotent recomputation with preview diff | `calendar.recalculate` |
| GET | `/calendar/working-days?employee=&from=&to=` | `WORKING_DAYS` / `CALENDAR_DAYS` / `PAYABLE_DAYS` | `calendar.day.view` scoped |
| GET | `/calendar/work-on-off-settlements` | settlements awaiting choice or approval | `calendar.settlement.view` |
| POST | `/calendar/work-on-off-settlements/{uuid}/choose` | employee or HR selects the outcome | scoped |

**R-XII-043** `GET /calendar/holidays` **requires** an employee context and returns the set
resolved for that employee. There is no tenant-wide holiday list endpoint, because there is
no tenant-wide holiday. *SmartPRS 2's only holiday consumer is a tenant-wide date-set query
(`AbsenceService::isHoliday():225-236`).*

**R-XII-044** Self-service endpoints resolve the subject **from the session** and accept no
employee identifier (spine S13). *`GET /app/leaves/balances/{employee}` in SmartPRS 2 takes
the subject from the path with no role check at all (SEC-07); that shape is not repeated.*

---

## XII.11 Events

Emitted (all carry `tenant_id`, `correlation_id`, actor, and the artefact versions
involved):

| Event | When | Principal consumers |
|---|---|---|
| `calendar.weekly_off_pattern.published` | a pattern version publishes | recalculation, roster regeneration, notification |
| `calendar.weekly_off_pattern.superseded` | prior version closed | audit |
| `calendar.holiday_calendar.published` | a calendar version publishes | recalculation, notification, payroll preview |
| `calendar.holiday.added` / `.removed` / `.excluded` | entry-level change inside a draft | diff preview |
| `calendar.holiday.optional_elected` / `.optional_withdrawn` | election change | quota ledger, calendar materialisation |
| `calendar.weekly_off.allocated` | a window's offs are allocated | materialisation, ESS |
| `calendar.weekly_off.elected` | employee sets their variable offs | materialisation, manager notification |
| `calendar.weekly_off.worked` | a punch lands on a resolved off | comp-off, OT, payroll, review queue |
| `calendar.holiday.worked` | a punch lands on a holiday | as above |
| `calendar.substitution.requested` / `.approved` / `.applied` | substitution lifecycle | materialisation, audit |
| `calendar.swap.requested` / `.consented` / `.approved` | swap lifecycle | as above |
| `calendar.day.recomputed` | a materialised day changes type | payroll re-preview, report cache |
| `calendar.rest_violation.detected` | `max_consecutive_workdays` or `min_weekly_rest_hours` breached | roster UI, compliance dashboard |
| `calendar.substitute_holiday.granted` | collision produced a substitute | notification |

Consumed:

| Event | From | Effect |
|---|---|---|
| `employee.joined` / `employee.exited` | Part X | allocate/close the joining and exit windows |
| `employee.assignment.changed` (branch, department, shift, category) | Part X | re-resolve patterns and calendars from the effective date; emit `calendar.day.recomputed` |
| `roster.published` | Part XI | materialise roster-driven offs |
| `attendance.day.derived` | Part XI | detect work on an off/holiday, raise the settlement |
| `leave.approved` / `leave.cancelled` | Part XIII | recompose the affected days |
| `payroll.period.locked` / `.reopened` | Part XIV | freeze/unfreeze materialised rows |
| `rulepack.activated` | Part XVII | offer the diff for seeded statutory holiday content |

**R-XII-045** No consumer of these events may apply its own calendar logic in response; it
re-reads the resolved day (R-XII-002).

---

## XII.12 Explainability

**R-XII-046** Every resolved day answers "How was this determined?" (spine S15, Law 4). The
rendered trace:

```
Employee        Ravi Kumar (EMP-1042)
Date            13-Feb-2027  (Saturday)
Day type        Weekly off — worked

Weekly off      YES
  Pattern       WO-HYD v4  "Hyderabad — Sun + 2nd/4th Sat"
                published 12-Jan-2027, effective 01-Feb-2027 →
  Matched by    scope branch IN [Hyderabad]  (specificity 180)
  Also matched  WO-TENANT-DEFAULT v2 (specificity 0) — lost on specificity
  Rule          nth_rules: {sat, [2,4]}, week_numbering = occurrence_of_weekday
  Arithmetic    NTH_WEEKDAY(2027-02-01, 'sat', 2) = 2027-02-13  → match, slot 2 of 2

Holiday         none for this employee on this date
                (calendar "Telangana 2027" v3 contains no entry; no elective elected)

Leave           none

Attendance      IN 09:34, OUT 18:12, worked 458 min (shift GEN 480 min)
Settlement      work_on_off_outcome = comp_off (pattern WO-HYD v4)
                Rule RL-CAL-001 v2: worked_minutes 458 >= 240 → credit
                units = IF(458 >= 480, 1.0, 0.5) = 0.5
                Comp-off credit #CO-88213, 0.5 day, expires 14-Apr-2027
                (credit rule COMPOFF-60D v1)

Payable         payable_fraction 1.000  (paid_weekly_off = TRUE for pay group MONTHLY-TG)
Working day     counts_as_working_day = TRUE (day_type weekly_off_worked is in
                working_day_set)
Period impact   +1 working day, +1 payable day, +0.5 comp-off
```

**R-XII-047** The trace names **both** the winning and the losing artefacts on a
precedence decision. Not showing the loser is why "why is my Saturday not off?" is a
support ticket in SmartPRS 2 rather than a self-service answer.

**R-XII-048** Traces are retained for as long as the day they explain, and a locked period's
traces are retained with the period (retention class `evidentiary`, spine S16).

---

## XII.13 Migration from SmartPRS 2

| SmartPRS 2 artefact | Verdict | Transformation |
|---|---|---|
| `statutory_settings.weekly_off_day` (default `sunday`, `SettingsController.php:51`) | **TRANSFORM** | Becomes one seeded `weekly_off_pattern_version`, tenant-default scope, `fixed_weekdays: [<value>]`, `effective_from` = the tenant's go-live date |
| `statutory_settings.sat_off_mode` (`none/all/2_4/1_3`, `:52`) | **TRANSFORM, WITH A WARNING** | `all` → `fixed_weekdays: [sat]`; `2_4` → `nth_rules: [{sat,[2,4]}]`; `1_3` → `nth_rules: [{sat,[1,3]}]`; `none` → nothing. **The historic days computed by `ceil(day/7)` do not always match the new `NTH_WEEKDAY` result**, so migration produces a per-tenant report of every past date whose weekly-off status would change, and **historic settled periods are not recomputed** (D-XII-03) |
| `roster.status = 'off'` cells | **MIGRATE** | Become roster-driven allocations and materialised `employee_calendar_days` rows for the covered range |
| `AbsenceService` `working_days` list | **DISCARD** | Superseded by the pattern; retained only in the migration report as evidence of what the sweep assumed |
| `PointsController` Sunday-only calendar | **DISCARD** | Points reads `WORKING_DAYS` (R-XII-004). **Historic points are not recomputed**; the divergence is reported per tenant so the customer can decide (D-XII-04) |
| `holidays` rows | **MIGRATE** | Each row becomes a `holidays` entry inside one seeded calendar version per tenant, `holiday_type` defaulted to `company` unless the name maps to a seeded statutory code, `is_paid = 1`, `scope_json` from `applies_to` **only after the Product Owner defines its semantics (OQ-B-07)**; otherwise scope = tenant default and a migration exception is raised per distinct `applies_to` value |
| `holidays.applies_to` | **BLOCKED PENDING PO** | See D-XII-02. Migration must not guess |
| `weekoff_action`, `no_cut_on_weekoff` | **DISCARD** | No reader in SmartPRS 2 (`DEFECT-B-07`); nothing to carry. Their *intent* is re-authored as `work_on_off_outcome` and work-time policy conditions, which is a configuration decision per tenant, not a data mapping |
| Comp-off | **NOTHING TO MIGRATE** | Does not exist (`B-attendance.md` Q4) |
| Substitutions, swaps | **NOTHING TO MIGRATE** | Do not exist |
| `PayrollGenController::sandwichDays()` | **REPLACED** | By the Leave Adjacency Policy (Part XIII §XIII.8), which is a superset. Historic sandwich LOP days are frozen as history |

**R-XII-049** Migration produces, per tenant, a mandatory **calendar divergence report**
before cutover: for every employee-day in the last two financial years, the SmartPRS 2
weekly-off verdict (per definition 3, the live payroll one) against the SmartPRS 3 verdict,
with counts by month. Cutover cannot proceed without the report being reviewed and
accepted. *Without it, the `ceil(day/7)` correction silently changes historic
denominators.*

---

## XII.14 Edge cases and failure states

Unglamorous and specific. Each has a declared behaviour.

| # | Case | Behaviour |
|---|---|---|
| 1 | Fifth occurrence of a weekday does not exist in a month | `missing_occurrence` decides; default `skip`, stated in the trace (R-XII-009) |
| 2 | February in a leap year; a 29-Feb holiday in a common year | Holiday entries are dated per calendar version, so a 29-Feb entry simply does not exist in a common year's version. *Contrast `GreetingService`, where a 29-Feb birthday is never greeted in a common year (`E-…` NEW-NOT-108) — a defect caused by deriving a date from a formula rather than from a calendar* |
| 3 | Employee transfers branch mid-week under two different patterns | The pattern resolves per date from the effective-dated org assignment (spine S3). The week can therefore contain offs from two patterns; entitlement is prorated per window segment and the trace shows both |
| 4 | An employee elects offs, then transfers to a fixed-pattern group | Elections after the transfer date are invalidated with a notification; elections before it stand as history |
| 5 | Variable pattern cannot allocate the entitled count without breaching `adjacency` | **Publish-time validation error**, naming the window shape that fails. Never a runtime under-allocation |
| 6 | Two holidays on the same date | Both recorded; payable impact counted once; `holiday_type` for display is the highest-specificity entry |
| 7 | Holiday scoped to a department the employee left yesterday | Resolution uses the assignment as at the date; the employee does not get it |
| 8 | Holiday published after payroll for that month was locked | Publication is blocked by the lock; the reopen workflow (Part XIV) is required, with the impact preview |
| 9 | Restricted-holiday quota exhausted, employee elects again | Rejected by the unique constraint plus the counted check inside the transaction (R-XII-028), with a named error, not a generic 422 |
| 10 | Employee elects a restricted holiday, then works that day | `elected_but_worked` decides whether the quota unit is restored; default `restore` |
| 11 | Substitution replacement date is itself a holiday or an off | Rejected at request time with the reason; substitution must land on a working day |
| 12 | Swap breaches the counterparty's `max_consecutive_workdays` | Rejected, naming which employee and which constraint |
| 13 | Employee joins on a Sunday under a Sunday-off pattern | Day type `weekly_off`; `joining_week_treatment` prorates the remaining entitlement; the day is payable per `paid_weekly_off` |
| 14 | Employee exits on a Friday; the following Sunday | Outside the employment window → `not_employed`; contributes to no count |
| 15 | Employee is on unpaid leave for an entire week | Weekly offs still resolve (the calendar does not depend on leave), but `payable_fraction` for the offs follows the pay group's declared treatment of an off inside an unpaid block — a configuration item, not an assumption |
| 16 | Pattern published with an effective date in the middle of a settled period | Blocked; requires the reopen workflow and the impact preview (R-XII-036) |
| 17 | A rule-pack activation adds a statutory holiday for a past date | Never auto-applied to a locked period. The diff is presented; applying it is an explicit, audited decision |
| 18 | Timezone: a device punch at 23:50 local on the day before an off | The attendance-day attribution rule (Part XI §XI.6.4) decides which date the punch belongs to; the calendar is consulted with that date, not the punch's UTC date |
| 19 | DST-observing deployment | Windows are computed on local business dates, never on elapsed hours; `min_weekly_rest_hours` is computed on instants and therefore correctly absorbs a DST shift |
| 20 | The evaluator cannot resolve a pattern (no artefact matches) | The tenant default applies; if no tenant default exists, the day resolves to `working` **and an operator alert is raised** — never a silent off and never a silent absence. *Contrast SmartPRS 2, where a throwing holiday query marks the entire workforce absent and emails all of them (`CONSOLIDATED-REQUIREMENTS.md` line 2412)* |
| 21 | Two patterns tie on specificity and priority | Publication blocked, naming both artefacts and the affected employees (spine S3.2) |
| 22 | An employee has no org assignment on a date (data gap) | Resolution fails typed; the day is flagged `unresolved` and appears on the exceptions worklist. It is never guessed |
| 23 | A materialised row disagrees with a fresh evaluation | The nightly consistency job raises an operator alert with the employee, date and both verdicts. Materialisation is a cache, and a cache that lies must be loud |
| 24 | Bulk holiday import contains a duplicate `(holiday_code, date)` | Rejected per row with a named error and a row number; good rows are not blocked. The import is previewed before commit |
| 25 | Employee works a weekly off that is also a holiday | `day_type = weekly_off_and_holiday`; the settlement uses the **holiday's** `work_*` fields, because the more specific instrument wins; the trace says which |

---

## XII.15 Tests — exact-value regression scenarios

Every scenario asserts exact values, not shapes. P0 must pass before release.

| Pri | Scenario | Assertion |
|---|---|---|
| **P0** | **Nth-weekday correctness.** For every month from Jan-2020 to Dec-2035, `NTH_WEEKDAY(month, wd, n)` for all seven weekdays and `n ∈ {1..5, -1}` | Matches an independently generated calendar table exactly. Additionally: assert that at least one month exists where `ceil(day/7)` and `NTH_WEEKDAY` disagree, and that SmartPRS 3 returns the `NTH_WEEKDAY` answer |
| **P0** | **One evaluator.** Attendance, payroll, leave, points, reports and the simulator are each asked "is 13-Feb-2027 an off for EMP-1042?" | All six return the identical verdict **and the identical `pattern_version_id`** |
| **P0** | **Branch divergence.** Hyderabad 2nd/4th-Sat pattern, Pune 1st/3rd-Sat pattern, one employee in each, March 2027 | Hyderabad employee: offs on 13 and 27 March + Sundays. Pune: 6 and 20 March + Sundays. `WORKING_DAYS` differs by exactly 0 (both have 2 Saturdays off) but the **dates** differ |
| **P0** | **Shift beats branch.** Night-shift employee at Hyderabad | Resolves `WO-NIGHT` (330) not `WO-HYD` (180); trace names both |
| **P0** | **Precedence tie blocks publish.** Two patterns, identical scope and priority | Publication rejected, error names both artefacts and the affected employee count |
| **P0** | **LOP denominator, three bases.** CTC ₹6,00,000, March 2027, 6 offs, 1 holiday, 2 absent | `working`: 22/24 = 0.9167 · `calendar`: 29/31 = 0.9355 · `fixed30`: 28/30 = 0.9333. Assert the three net figures and that the basis is read from configuration (`CONSOLIDATED-REQUIREMENTS.md` line 2389) |
| **P0** | **A public holiday is not an absence** | Zero absences, zero absence notifications (line 2411) |
| **P0** | **A failing holiday lookup does not flag the workforce absent** | Force the resolution to throw → **zero** absences recorded, **zero** emails sent, one operator alert (line 2412) |
| **P0** | **Holiday scope is honoured.** A holiday scoped to one branch | Does not apply to another branch's employees; `WORKING_DAYS` differs accordingly (line 2413) |
| **P0** | **LOP-adjacent: weekly off is not silently deducted from leave.** Fri leave, Sat+Sun off, Mon leave, `on_leave_collision = off_wins_not_deducted` | Leave deducted = 2.0 days exactly. Under `deduct_leave` = 4.0 days exactly. Both from the same input, purely by configuration (see Part XIII §XIII.8) |
| **P0** | **Restricted-holiday quota is not over-drawable.** Quota 2, two concurrent elections for the third holiday | Exactly one succeeds; the other receives a named quota error. Asserted under a concurrency harness |
| **P0** | **Materialised = evaluated.** Random sample of 10,000 employee-days | Materialised `day_type` equals a fresh evaluation for 100% of the sample |
| **P0** | **Historic reproduction.** Publish a new pattern effective today; recompute a settled period from six months ago | Byte-identical settlement to the original, and the trace names the *old* version |
| P1 | **Joining-week proration.** 2 offs/week, joins Wednesday, `pro_rata`, `{half_up, 1}` | Exactly 1 off allocated that week |
| P1 | **Exit-week proration.** Exits Tuesday, 2 offs/week | Exactly 1 off; no off allocated after LWD |
| P1 | **`min_workdays_before_entitlement`.** Joins Friday, requirement 3 | No off in the joining week; trace states the reason |
| P1 | **Consecutive-offs constraint.** 2 offs/week, `consecutive_required`, allowed Mon–Sun | Allocation always produces adjacent days; a non-adjacent election is rejected with a named error |
| P1 | **Missing fifth occurrence.** Pattern `{sat, [5]}`, February 2027 | No off; trace states `missing_occurrence = skip` |
| P1 | **Holiday on a weekly off, `shift_next_working_day`** | Substitute created on the next working day; original day type `weekly_off_and_holiday`; `WORKING_DAYS` unchanged in total |
| P1 | **Work on a weekly off → 0.5 comp-off.** 458 worked minutes, 480 full-day | Exactly 0.5 credited, expiry exactly 60 days later |
| P1 | **Work on a weekly off with approval required, unapproved** | Outcome `none`, flagged for review, **no** comp-off credited, day still counted as worked |
| P1 | **Substitution round trip** | Original day becomes `weekly_off_substituted_worked`, replacement `weekly_off_substituted`; `WORKING_DAYS` for the period unchanged |
| P1 | **Swap rejected on rest violation** | Named error identifying the employee and the constraint |
| P1 | **Optional holiday not elected is an ordinary working day** | Absence on it is an ordinary absence with the normal LOP treatment |
| P1 | **Elected-but-worked restores quota** | Quota consumed returns to its prior value; audited |
| P1 | **Mid-week branch transfer** | Week contains offs from two patterns; entitlement prorated per segment; trace shows both |
| P1 | **Two holidays on one date** | Payable impact counted once; both listed |
| P1 | **Cross-tenant isolation** | Tenant A's pattern, calendar, election, substitution and calendar day are invisible and unmodifiable from tenant B on every endpoint in §XII.10 (generated per spine Law 6) |
| P2 | **Rotation pattern anchoring.** 6-on-1-off rotation anchored 01-Jan | Off dates land exactly every 7th day from the anchor across a year boundary |
| P2 | **Composite pattern.** Sunday fixed + 2nd/4th Sat + one elected day | Union produced; entitlement audit sums to 3 in a 2nd/4th-Sat week and 2 otherwise |
| P2 | **DST deployment** | `min_weekly_rest_hours` computed on instants absorbs the shift; no false violation |
| P2 | **Bulk import with duplicates** | Bad rows rejected individually with row numbers; good rows imported; preview matches commit |

---

## XII.16 Open items — PRODUCT OWNER DECISION REQUIRED

| # | Question | Recommendation | Trade-offs |
|---|---|---|---|
| **D-XII-01** | **What is the platform-default weekly-off pattern for a new tenant?** | `fixed_weekdays: [sun]`, `paid_weekly_off = true`, `on_holiday_collision = absorb`, `work_on_off_outcome = none`. Every tenant is prompted during onboarding to confirm or change it | A default that is silently wrong is worse than an onboarding step. The alternative — no default — blocks employee creation until a pattern exists, which is defensible but hostile |
| **D-XII-02** | **What did `holidays.applies_to` mean?** (`B-attendance.md` OQ-B-07 — the column has no reader and the intent cannot be determined from source) | Run `SELECT DISTINCT applies_to, COUNT(*) FROM holidays GROUP BY 1` on every live install, then map each distinct value to a scope predicate by hand, per tenant. **Do not guess in migration code** | Hand mapping costs analyst time per tenant. Guessing risks applying a state holiday to the wrong establishment, which changes historic LOP denominators |
| **D-XII-03** | **Are historic weekly-off determinations recomputed under the corrected Nth-weekday rule?** | **No.** Freeze history; recompute forward only. Produce the divergence report (R-XII-049) so the customer can see the affected past dates | Recomputing changes settled payroll and filed statutory returns. Not recomputing means the historic record contains days that the new engine would classify differently — which the report makes explicit rather than hidden |
| **D-XII-04** | **Are historic points recomputed once Points uses the real calendar?** | **No.** Recompute forward; publish the divergence per employee per month so the customer can choose to issue a manual adjustment | Points drive incentive eligibility (`H-…` BR-REC-038). Recomputing could retroactively qualify or disqualify past incentive payments |
| **D-XII-05** | **Is a weekly off paid, and is that a property of the pay group or the pattern?** | **Pay group**, as designed in §XII.6.3, because it is a remuneration question. Seed `paid_weekly_off = true` for monthly-rated groups and prompt for daily-rated ones | Putting it on the pattern would let one HR change silently alter pay for everyone on that pattern. Putting it on the pay group means two artefacts must agree, which the impact preview must surface |
| **D-XII-06** | **Default collision behaviour when a holiday falls on a weekly off** | `absorb` (one day off, no substitute). Offer `shift_next_working_day` prominently in onboarding because many Indian employers grant the substitute | `absorb` is the cheaper default and matches SmartPRS 2's implicit behaviour (neither concept interacts today). Granting a substitute by default would silently increase everyone's holiday count on migration |
| **D-XII-07** | **Which day types count as working days by default?** (`working_day_set`) | `{working, weekly_off_worked, weekly_off_worked_scheduled, holiday_worked, on_duty, work_from_home, training}` | Including `work_from_home` and `training` matches most customers but changes the LOP denominator for any customer who treats training as non-working. It must be an onboarding question, not an assumption |
| **D-XII-08** | **Do restricted-holiday elections require approval?** | **No by default**, configurable per calendar. Approval adds friction to a benefit | Where staffing is thin, an unapproved election can leave a desk uncovered — which is why the minimum-staffing evaluator (Part XIII §XIII.7.6) is shared and can be applied to elections too |
| **D-XII-09** | **Is a religious-observance election PII that must be masked?** | **Yes — P2**, masked by role, excluded from general exports, and never included in an unfiltered manager view | Masking reduces the usefulness of a team calendar. The compromise: managers see *that* a day is elected, not *which* observance, unless they hold an explicit permission |
| **D-XII-10** | **Who may work on a holiday declared `work_allowed = false` — nobody, or an override role?** | An override with an explicit permission (`calendar.holiday.override_closure`) and a mandatory reason, fully audited | An absolute prohibition is cleaner but real operations have emergencies; an unaudited override is how SmartPRS 2's fail-open guards were justified (`SEC-05`) |
| **D-XII-11** | **How many years of calendar are materialised forward?** | Current period + 2 future periods, plus on-demand materialisation for any range a report or simulation requests | Materialising a full year forward makes the team calendar instant but multiplies the rows invalidated by every pattern change |
| **D-XII-12** | **Retention for `employee_calendar_days` superseded generations** | Keep all generations for locked periods (evidentiary); keep the last 3 generations otherwise, then collapse | Full retention is defensible but grows without bound; SmartPRS 2 retains everything and prunes nothing anywhere (`SEC-18`), which is the failure mode to avoid in both directions |

---

## XII.17 Requirements index

| Range | Area | Count |
|---|---|---|
| R-XII-001 – R-XII-007 | The one evaluator, consumers, materialisation (§XII.2) | 7 |
| R-XII-008 – R-XII-012 | Pattern grammar, Nth-weekday, allocation, configuration readers (§XII.3.1–XII.3.4) | 5 |
| R-XII-013 – R-XII-014 | Joining and exit weeks (§XII.3.5) | 2 |
| R-XII-015 – R-XII-019 | Working on an off, substitution, swap, rest (§XII.3.6) | 5 |
| R-XII-020 | SPX exposure (§XII.3.7) | 1 |
| R-XII-021 – R-XII-029 | Holiday hierarchy, types, configuration, elections (§XII.4) | 9 |
| R-XII-030 – R-XII-036 | Day-type composition, collisions, materialisation (§XII.5) | 7 |
| R-XII-037 – R-XII-042 | Calendar / working / payable day definitions (§XII.6) | 6 |
| R-XII-043 – R-XII-045 | API and events (§XII.10–XII.11) | 3 |
| R-XII-046 – R-XII-048 | Explainability (§XII.12) | 3 |
| R-XII-049 | Migration divergence report (§XII.13) | 1 |
| **Total** | | **49** |

### Compliance with the seven laws (spine S1)

| Law | How this part complies | Test |
|---|---|---|
| 1 — no business literal | Every weekday, occurrence index, threshold, quota, multiplier and collision behaviour is a configuration field named in §XII.3.4 and §XII.4.4, each with a reader (R-XII-012). No weekday literal may decide an off (R-XII-020) | Static analysis on `Domain\Calendar`; the CI check that every configuration key has a reader |
| 2 — no schema change at runtime | All ten tables are migration-owned. There is no `ensureCols()` successor. *SmartPRS 2 creates `holidays` in a migration but adds attendance columns at runtime in eight places (`TD-01`)* | CI grep for DDL outside migrations; `migrate:fresh` parity |
| 3 — nothing overwritten | Patterns and calendars are versioned artefacts (spine S4); `employee_calendar_days` and `weekly_off_allocations` use generations with a single current row; elections are withdrawn, never deleted | "What was the weekly-off pattern for E on D?" answered by one service call (R-XII-005) |
| 4 — every number explains itself | §XII.12; a resolved day without a trace throws. Both winning and losing artefacts are named (R-XII-047) | No evaluator return path may omit a trace |
| 5 — authorisation fails closed | Every route in §XII.10 has an explicit permission; self-service resolves the subject from the session and accepts no employee id (R-XII-044) | Route-policy sweep; fault injection in authorisation must deny |
| 6 — tenancy is structural | `tenant_id NOT NULL` on all ten tables and in every unique index; applied by the persistence layer | Generated cross-tenant isolation test per aggregate and per endpoint (§XII.15) |
| 7 — money is exact | This part produces **day fractions and minutes**. The only money it touches is `work_on_off_settlements.additional_wage_amount`, `DECIMAL(18,4)` at rest and integer paise in memory, produced by a Part VIII formula with a declared rounding mode | No float arithmetic in `Domain\Calendar`; the additional-wage amount reconciles to its payslip line exactly |
