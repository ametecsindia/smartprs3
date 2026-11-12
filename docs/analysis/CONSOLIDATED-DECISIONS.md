# CONSOLIDATED OPEN QUESTIONS / PRODUCT OWNER DECISIONS


---

# SOURCE: A-payroll.md

## 11. Open questions / PRODUCT OWNER DECISION REQUIRED

**OQ-1 · Zero punches — pay in full or treat as absent?** *(DECISION REQUIRED)*
Today an employee with no punches at all is paid **100%** (`:1237`), deliberately, so a broken
biometric device cannot zero a salary. The cost is that a genuinely absent employee is paid in
full. What should the default be, and should it be per company, per grade, or per employee?
Should there be a middle option — "hold the slip for review"?

**OQ-2 · Which LOP bases must SmartPRS 3 offer, and at what scope?** *(DECISION REQUIRED)*
Today: working / calendar / fixed30, one tenant-wide setting. Missing: **26-day** (the convention
the in-app FAQ actually headlines) and **payable days**. Should the basis be settable per company,
per grade or per employee? Should the basis for *measuring* absence be separable from the basis
for *valuing* it (the engine already does this internally for calendar/fixed30)?

**OQ-3 · Should statutory deductions apply to variable pay?** *(DECISION REQUIRED — compliance)*
Today commission, incentive, overtime, night allowance and arrears attract **no PF, ESI, PT or
TDS** (BR-STAT-006). ESI is statutorily due on overtime and all of it is taxable salary. Which
extras must enter which statutory base? This needs a compliance ruling before design, not after.

**OQ-4 · Probation / internship suppressing PF, PT and TDS — is this a customer's policy or a
mistaken belief about the law?** *(DECISION REQUIRED — compliance)*
The EPF Act does not exempt probationers. The code hardcodes the suppression
(`:2114-2117`). Is this a specific customer's contractual arrangement that must be preserved as
configuration, or a bug to be removed? Existing customers must be told either way.

**OQ-5 · What happens to eleven months of fabricated "paid" payroll on every existing install?**
*(DECISION REQUIRED — blocks migration)*
DEF-P01 means most live databases contain fabricated `paid` runs with full-CTC payslips. They are
indistinguishable from real ones except by heuristics (`net_total = 0` at insert, no `calc_note`,
no `period_start`, `employees_count` inconsistent with headcount). Do we quarantine them, delete
them, or migrate them as-is and mark them "unverified"? **Nothing else in the migration can be
planned until this is answered.**

**OQ-6 · How are broken loans and lost advances remediated at cutover?** *(DECISION REQUIRED)*
DEF-P02 means loan EMIs have either never been recovered or have been recovered indefinitely, and
DEF-P19 means advance shortfalls were silently dropped. Both need a per-customer reconciliation
before cutover. Who owns that reconciliation, and what is the remedy where an employee has been
over-recovered?

**OQ-7 · Is retroactive scheme re-pricing the intended behaviour?**
Editing a live scheme's rate re-prices **every unlocked claim** and sends approved ones back to
Pending (BR-PAY-036). That is a strong policy choice. Should it be optional, or should a rate
change always create a new scheme version applying only forward?

**OQ-8 · Should the loan full-EMI-only rule survive?**
Most Indian payroll systems recover partially. Today the whole month is skipped if the EMI does
not fit (BR-PAY-020). Configurable per loan, per loan type, or a single policy?

**OQ-9 · Should a backdated salary *decrease* create a recovery?**
`IncrementController:362` fires only when `newCtc > oldCtc`. A backdated reduction produces
nothing, so an overpayment is never recovered.

**OQ-10 · Are arrears taxable?**
The engine forces `tds_rate = 0` on arrears (`IncrementController:405`). Arrears are salary. Is
this deliberate, and does it need Sec 89(1) relief support?

**OQ-11 · Do any customers use multiple payroll lots per month?**
`payroll_runs.lot` exists and is hardcoded to 1. If lots are needed, the YTD calculation
(NEW-PAY-34) and the one-run-per-month key both change shape.

**OQ-12 · Which PT slab values are authoritative?**
The 16 built-in state tables (§4.7) are labelled *"indicative — verify against current state law
before filing"* by the source itself. **These are the values the code currently uses, not a claim
about correct law.** Someone must own their verification and their ongoing maintenance, and the
same applies to the EPS ₹1,250 / 8.33% question in DEF-P08, the ESI rates, the bonus ₹21,000 /
₹7,000 caps and the gratuity 15/26.

**OQ-13 · Two-stage approval — is it universal?**
Hardcoded HR → Finance (BR-PAY-716). Do any customers need one stage, three stages, parallel
approvers, amount-based routing or delegation? This determines whether the workflow engine needs
to be generic on day one.

**OQ-14 · Does "downloading the bank file is the disbursement" survive?**
BR-PAY-032 makes a GET request the money-moving event, bypassing the four-eyes guard and the
employee notification (DEF-P10, SEC-P04). Should disbursement become an explicit, separately
authorised action with bank-side confirmation?

**OQ-15 · What is the retention and immutability policy for regenerated runs?**
Today regeneration destroys the previous run, its payslips, its rejection remark and every line
status (BR-PAY-405, DEF-P18). Should SmartPRS 3 version runs instead? If so, what does the
payslip an employee already downloaded mean?

**OQ-16 · Is the ESI contribution-period lock's substring detection acceptable in the interim?**
`esiPeriodLock()` decides a statutory obligation by searching for the string `esi` in a stored
JSON key (`:2036`). Any customer who has renamed that line has had the lock silently disabled.
Do we need to audit existing installs for this before migration?

**OQ-17 · Should the calculation guide remain hand-written prose?**
`CalcGuideController` is a client-facing promise about engine behaviour, and four of its
statements already contradict the engine (§8.2). Generate it from the rules, or accept the drift
and assign an owner?


---

# SOURCE: B-attendance.md

## 11. Open questions / PRODUCT OWNER DECISION REQUIRED

**OQ-B-01 · What is the correct attendance day for a cross-midnight shift?
— PRODUCT OWNER DECISION REQUIRED.**
Options: (a) the shift's start date owns the whole shift; (b) the calendar date of the
first punch; (c) a fixed day-boundary hour. This choice determines how historic
night-shift punches are re-attributed on migration and whether September's 30th-night
hours move into September's payroll. Related: `SELECT name, start_time, end_time FROM
shifts` per tenant is needed to know whether any customer runs one today
(doc OQ-ATT-04).

**OQ-B-02 · What must happen to a correction approved against a locked payroll period?
— PRODUCT OWNER DECISION REQUIRED.**
Today the row is flagged and nothing happens, while the employee is told it "will be
carried into the next adjustment" (DEFECT-B-30). Options: an arrears line on the next
run; a Super-Admin reopen; a manual adjustment task. **There may be existing rows in this
state on production that represent unpaid liabilities** — they must be enumerated before
cutover.

**OQ-B-03 · Should presence require a minimum of worked time? — PRODUCT OWNER DECISION
REQUIRED.** Today a single punch at 09:05 is a full present day for payroll
(CALC-B-14). Changing this changes historic LOP if any recalculation is performed.

**OQ-B-04 · Which `attendance_logs` and `attendance_pending` shapes are live at each
customer?** Three creators for the first, two for the second. Required evidence:
`SHOW CREATE TABLE attendance_logs`, `SHOW INDEX FROM attendance_logs`,
`DESCRIBE attendance_pending` from every production database. This determines whether the
bulk upload has been silently broken since 16 Aug 2026 (DEFECT-B-03) and whether any
install is missing the `(emp_code, log_date)` index (DEFECT-B-04).

**OQ-B-05 · Is the LAN ZKTeco path live anywhere?** `\Rats\Zkteco\Lib\ZKTeco` is guarded
by `class_exists` and is **not** in `composer.json`. If any customer installed it
manually, DEFECT-B-21 (whole-day aggregation, direction in the identity, silent drops) is
an active data-loss defect and their historic intra-day punches are unrecoverable.
Required: `composer show` per install.

**OQ-B-06 · Are late-cuts actually being applied in production?** `resolvePolicy()`
defaults `mode` to `simple` and `simpleCut()` returns 0.0 when `cut_mode` is unset
(`PayrollGenController.php:301, 510`). Required:
`SELECT mode, cut_mode, cut_n, l1_min, l1_cut, break_cut FROM late_policy` per site. If
they are all unset, the entire late-penalty engine is dead in the field and the SmartPRS 3
requirement is a greenfield design rather than a migration.

**OQ-B-07 · What is `holidays.applies_to` meant to mean?** The column exists
(`2026_01_01_000200:104`) and has no reader. Required: intended semantics and
`SELECT DISTINCT applies_to FROM holidays`.

**OQ-B-08 · What is the retention policy for punch evidence, quarantine rows,
`biometric_unmapped` and `late_notifications`? — PRODUCT OWNER DECISION REQUIRED.**
Nothing is ever purged today; nothing else in the codebase deletes on age.

**OQ-B-09 · Must Points follow the Late Policy?** `PointsController.php:25` uses a
hard-coded 09:30 with no grace and a Sunday-only working-day count, so an employee can
lose points for a day the Late Policy calls on time (DEFECT-B-29). Deliberate
("gamification heuristic") or an oversight?

**OQ-B-10 · Does any deployment run outside IST?** If so, the `/iclock` and eTimeOffice
`Carbon::parse` fallbacks, the absence send-hour gate and the "today/yesterday" late
window are all actively wrong (doc R1/R4/R9).

**OQ-B-11 · Are there `emp_code LIKE 'USER-%'` rows in production?** The in-app punch
writes them when a login cannot be resolved to an employee
(`AttendanceReportController.php:279`). They count as present days for a code no
`employees` row holds and are never cleaned up. They must be identified and dispositioned
before migration.

**OQ-B-12 · Was `attendance:dedupe` ever run, and what did it delete?** It is invoked
automatically by `2026_08_16_000200:128` on every install and hard-deletes with no log.
There is no way to reconstruct what was removed. **A pre-migration snapshot of
`attendance_logs` is mandatory before any SmartPRS 3 cutover.**

---

## Appendix — direct answers to the seven questions asked

### Q1 · Is raw punch evidence preserved separately from derived attendance, and can attendance be recalculated after a policy change?

**Partly, and not reliably.**

*What is preserved.* `attendance_logs` is a punch-level ledger — one row per punch — and
there is **no stored derived attendance-day record anywhere**. Present / absent / late /
worked / break / half-day are all computed at read time
(`AttendanceReportController::buildReport :82-173`, `PayrollGenController::dayStats :322-408`,
`AbsenceService::isAbsent :141-176`, `TodayController::me :21-70`). In that narrow sense
the evidence and the derivation are separated, and the report, Today panel and absence
sweep automatically reflect a policy change on the next read.

*Where the evidence is destructively overwritten.* Punch rows are mutated in place, with no
version history, no soft delete and no audit record, by:

| Mutation | Evidence |
|---|---|
| Direction re-stamped in place in single-device mode | `ETimeOfficeService::sequenceDirections() :666-677` (`UPDATE attendance_logs SET direction`) |
| `updateOrInsert` overwrites `direction`, `emp_name`, `log_date`, `tenant_id`, `company_id` on every re-sync | `ETimeOfficeService::import() :756-767` |
| Whole-day collapse to `min(time)`/`max(time)` — intra-day punches never stored | `DeviceController::importLogs() :140-158` |
| `DELETE` of all `source='correction'` rows for an employee-day on every approval | `AttendanceCorrectionService::writeBack() :295-301` |
| Hard `DELETE` of "duplicates", keeping `MAX(id)` | `DedupeAttendance.php:80-84`; also run automatically by `2026_08_16_000200:128` and `2026_08_17_000100:152` |
| Hard `DELETE` of `source='demo'` — and pre-rev-51 demo rows are tagged `biometric` and cannot be distinguished from real punches | `PurgeDemoAttendance.php:29-33` and its docblock `:16-18` |
| Bulk `UPDATE` of `emp_code`, `emp_name`, `tenant_id`, `company_id` on historic rows when a device ID is mapped — unbounded in date, and reaching NULL-tenant rows | `BiometricConfigController.php:641-655` |
| Bulk `UPDATE emp_code` cascade on an employee-code rename | `AppDataController.php:3025-3040` |

Only the in-app punch writes an audit entry (`AttendanceReportController.php:326`). No
other writer or mutator does.

*Recalculation after a policy change.* There is **no recalculation command of any kind**
(`routes/console.php` defines only `attendance:sync-etimeoffice`, `absence:notify`,
`attendance:dedupe`, `attendance:purge-demo`). The read-time derivation means the report
and absence sweep are always current — but that is also the problem: a policy edited today
silently rewrites **last year's** report, because rules are not effective-dated
(`late_policy` and `shifts` have no validity columns). Payroll is the opposite: it
snapshots the derived figures into `payslips.earnings`/`deductions` at generate time, and
a run whose status is `locked|approved|hr_approved|paid` cannot be regenerated
(`PayrollLock::FINALISED_STATUSES :31`), so a policy change never reaches a closed month
and there is no mechanism to produce the difference.

**Net:** raw punches are preserved in principle, mutated in practice without audit, and
attendance is recomputed on every read against *today's* rules with no ability to reproduce
what a past month was actually judged against.

### Q2 · Exactly how the attendance date is derived for a cross-midnight shift, in each ingest path, and whether the paths agree

**No path is shift-aware. Every path sets `log_date` to a calendar date; they agree on the
rule and disagree on where that date comes from.**

| Path | `log_date` derivation | `punch_at` derivation | Evidence |
|---|---|---|---|
| A · `/iclock` ADMS | `$p['punch_at']->toDateString()` | `PushController::parseDate()` — 4 fixed formats then `Carbon::parse()` (**an offset is honoured then discarded**) | `ETimeOfficeService.php:761`; `PushController.php:162-182` |
| B · SBB JSON | `$when->toDateString()` | `parseNaiveLocal()` — offset **rejected**, parsed in `config('app.timezone')`, round-trip verified | `PunchIngestService.php:146, 308-331` |
| C · SmartEPT webhook | `$when->toDateString()` (via B) | `toNaiveLocal()` converts the sender's offset into app-tz wall clock, then B's rules apply | `SmarteptWebhook.php:300-317`; `PunchIngestService.php:146` |
| D · vendor pull (eTimeOffice / eTimeTrackLite / generic) | `$p['punch_at']->toDateString()` | `ETimeOfficeService::parseDate()` — 7 fixed formats then `Carbon::parse()` (same offset defect); eTimeTrackLite has **no** `Carbon::parse` fallback | `ETimeOfficeService.php:761, 202-222` |
| E · LAN ZKTeco | `Carbon::parse($timestamp)->toDateString()` | `"$date $time"` where `$time` is `min()` for IN and `max()` for OUT across the whole day | `DeviceController.php:149, 152-157, 200` |
| F · in-app punch | `now()->toDateString()` (server) | `now()` (server) | `AttendanceReportController.php:282, 304-305` |
| G1 · bulk approval | **the sheet's date cell**, normalised by `normDate()` | `$r->log_date . ' ' . $r->in_at . ':00'` — derived *from* `log_date` | `AttendanceBulkController.php:129-153, 331, 337` |
| G2 · Manual Punch master | **a free-form user field**, validated only as "not beyond today" | **an independent free-form user field**, validated only as "not more than 2 min in the future" | `MasterController.php:474-480, 999-1020` |
| G3 · correction write-back | the correction's `log_date` | `$date . ' ' . substr($hm,0,5) . ':00'` — derived *from* `log_date` | `AttendanceCorrectionService.php:311` |
| Quarantine replay | `Carbon::parse($row->punch_at)->toDateString()` | as held | `ETimeOfficeService.php:489, 526`; `PunchIngestService.php:244, 251` |

**Do they agree?** On the *rule* — "the attendance day is a calendar day" — yes, all nine.
On the *source of truth* they diverge three ways: six paths derive `log_date` **from**
`punch_at`; bulk and the correction write-back derive `punch_at` **from** `log_date`; and
the Manual Punch master lets the two be set **independently with no consistency check at
all**. That last case is the only place in the product where a punch can carry an
attendance date different from its own timestamp — and it is accidental, unvalidated, and
usable by anyone who can reach the master screen.

**Consequence for a cross-midnight shift (22:00–06:00):** the 22:00 IN gets
`log_date = D`, the 06:00 OUT gets `log_date = D+1`. `pairPunches()` groups strictly by
`emp_code|log_date` (`AttendanceReportController.php:119-123`), so day D shows an open
session, `last_out = —`, `total_work = 0`, and `early_min` measured against a shift end of
06:00 on D+1 that no punch can reach (`summarise() :729-735`) ≈ 480 minutes of phantom
early exit **every night**; day D+1 shows an orphan OUT. `presentDays()` counts **two**
present days for one shift (`PayrollGenController.php:211-216`). If D is 30 September and
D+1 is 1 October, the two halves fall in different payroll months. And because
`first_in_min = hour*60 + minute` (`:741`), a 00:15 arrival against a 22:00 start computes
`max(0, 15 − 1320) = 0` — the night worker is never late.

### Q3 · Every hard-coded late / half-day / absent threshold and where it lives

| Value | Meaning | Location | Overridable? |
|---|---|---|---|
| `'09:30'` | `SHIFT_START` — last-resort shift start for the report's late calculation and the response header | `AttendanceReportController.php:26` | only if a shift or a policy `shift_start` is set |
| `'18:30'` | `SHIFT_END` — last-resort shift end for early-exit / overtime | `AttendanceReportController.php:27` | as above |
| `480` | `STANDARD_WORK_MIN` — the day target used raw by `logs()`'s `remaining_min` and as the drawback fallback | `AttendanceReportController.php:28, 457, 736` | `remaining_min`: **no**; `drawback`: only via `shift.full_day_hours` |
| `'09:30'` | `DEFAULT_START` — the late-arrival **email**'s shift start when the policy has none | `LateArrivalService.php:29, 208` | only via `late_policy.shift_start`; **never via the shift** |
| `'09:30'` | Today panel's shift-start fallback | `TodayController.php:138` | only via `late_policy.shift_start` |
| `'09:30'` / `'18:30'` | `SHIFT_START` / `SHIFT_END` — payroll's policy fallback | `PayrollGenController.php:37-38, 302-303` | via `late_policy` |
| `570` | `SHIFT_START_MIN` — the Points module's late cutoff, **no grace, no policy, no shift** | `PointsController.php:25, 166` | **no** |
| `9` (hours) | `full_day_hours` default in payroll | `PayrollGenController.php:308` | `late_policy.full_day_hours` or `shifts.full_day_hours` |
| `4.5` (hours) | `half_day_hours` default in payroll — **this is where "half day" actually lives** | `PayrollGenController.php:309` | `late_policy.half_day_hours` or `shifts.half_day_hours` |
| `0` | grace default in all four late resolvers, **despite a migration default of 15** | `LateArrivalService.php:211`; `AttendanceReportController.php:831`; `TodayController.php:141`; `PayrollGenController.php:304`; migration default at `2026_01_01_000200:64` | `late_policy.grace_min` / `shifts.grace_min` |
| `3` | `cut_n` default for `one_day_per_n` | `PayrollGenController.php:307` | `late_policy.cut_n` |
| `0.5` | half-day cut for `half_day_per_late` and for the `half_day` break penalty | `PayrollGenController.php:505, 466` | mode is selectable; the **fraction is not** |
| `1.0` | full-day cut for `full_day_per_late` and for `net_hours` under half | `PayrollGenController.php:507, 445` | **no** |
| `30` min / `0.25` day | break penalty block size and step for `per_30min` | `PayrollGenController.php:469` | **no** |
| `l1_cut` (fall-through) | late past grace but **below** L1 is still charged at the L1 rate | `PayrollGenController.php:494` | **no** — the rule is invisible in the UI |
| `'simple'` | late-policy mode default | `PayrollGenController.php:301` | `late_policy.mode` |
| `60` s | `SINGLE_REPEAT_SEC` — two reads inside this are one punch | `ETimeOfficeService.php:578` | **no** |
| `90` min | PDF red-break highlight, unrelated to `break_budget` | `attendance-report-pdf.blade.php:67, 78` | **no** |
| `[1,2,3,4,5,6]` | absence sweep default working days (Mon–Sat) | `AbsenceService.php:45` | `app_settings.absence_notify.working_days` |
| `11` | absence sweep default send hour | `AbsenceService.php:41` | `app_settings.absence_notify.send_hour` |
| `0 ≤ diffInDays ≤ 1` | late email fires only for today or yesterday | `LateArrivalService.php:91-94` | **no** |
| `1–10` | rating scale | `AttendanceReportController.php:495` | **no** |
| `2` min | clock-skew tolerance — correction create, manual punch (×2) | `AttendanceCorrectionService.php:125`; `MasterController.php:1010, 1018` | **no** |
| `3000` / `500` | bulk row cap / insert chunk | `AttendanceBulkController.php:168, 253` | **no** |
| `300` | correction list cap (no pagination) | `AttendanceCorrectionService.php:349` | **no** |
| `500` / `50` | Pending Punches group cap / unassigned device cap | `ApiKeyController.php:405, 419` | **no** |
| `1000` | SBB batch cap `MAX_BATCH` | `PunchIngestService.php:49` | **no** |
| `{strict:0.0, '1km':1.0, '2km':2.0}` | geofence grace ladder | `AttendanceReportController.php:431` | mode selectable; **the three bands are fixed** |
| `6371.0` | Earth radius (km) | `AttendanceReportController.php:397` | n/a |
| `31` days / `10 %` absent / `19:00` / `09:00–09:45` / `40–120` min / `5–70` min | demo fabrication caps | `AttendanceReportController.php:889, 928, 931-939` | env flag only |
| `'sunday'` / `'none'` | `weekly_off_day` / `sat_off_mode` defaults | `SettingsController.php:51-52` | Statutory Rate Settings |
| `Carbon::SUNDAY` | working-day rule in `PayrollGenController::monthMeta()` (dead) and `PointsController::monthMeta()` (**live**) | `PayrollGenController.php:181, 193`; `PointsController.php:259` | **no** |

**Absent** has **no threshold at all** — it is the absence of a punch
(`AbsenceService::isAbsent :141-176`). There is no "absent if worked < N hours" rule
anywhere in the product.

### Q4 · Do weekly-off, holiday, sandwich and comp-off exist in the attendance layer, and how limited are they?

**Weekly-off — exists, in four mutually incompatible forms, none of them owned by the
attendance layer.**
1. `roster.status = 'off'` → `ShiftResolver::resolve() :118-125` sets `off = true`. This
   is the only form the attendance **report** knows, and it surfaces only as a boolean on
   rows that already have punches (`AttendanceReportController.php:158`) — and only in the
   JSON, not the PDF (`:570-582`). It suppresses late and break flags (`:786-788`) and the
   payroll day-cut (`PayrollGenController.php:429-431`).
2. `AbsenceService` `working_days` — a flat per-tenant ISO-weekday list, default
   `[1..6]`, used **only** when no shift definitions exist at all
   (`AbsenceService.php:45, 168-170`).
3. `PayrollGenController::isWeekOff() :70-89` — `weekly_off_day` + `sat_off_mode`
   (`none|all|2_4|1_3`). The only form that understands alternate Saturdays. **Not
   visible to the attendance layer at all.**
4. `PointsController::monthMeta() :259` — Sunday only.
There is **no per-employee, per-branch or per-shift weekly-off pattern**, and no way for
the attendance report to show a weekly-off day (it emits no row without punches).

**Holiday — exists as a table, is invisible to the attendance layer.**
`holidays` (`2026_01_01_000200:100-106`) is read by exactly two places:
`AbsenceService::isHoliday() :225-236` (tenant-wide, `applies_to` ignored, and a holiday
cancels the **whole tenant's** sweep — `:111-113`) and `PayrollGenController::holidayDates()
:1015-1039`. The Attendance Report, the PDF, the Today panel and the drill-down **never
query `holidays`**. A holiday is therefore not a visible attendance state; it simply
suppresses an absence notice and removes a day from the payroll denominator.

**Sandwich — exists only in payroll, off by default, and is not an attendance concept.**
`PayrollGenController::sandwichDays() :770-813`, gated on `rates['sandwich_rule']`
(default `0`, `SettingsController.php:56`). It counts off-days whose nearest working day on
**both** sides is neither a punch date nor a paid-leave date, and converts each into one
extra LOP day (`:1262-1266`). It is a single boolean with a fixed algorithm — no choice of
which off-day types are bridged, how many bounding absences are required, or whether leave
breaks the chain. It also indexes day-of-month within `$start`'s month, so it is wrong
under a cut-off period (DEFECT-B-32). The attendance report never shows a sandwiched day.

**Comp-off — does not exist.**
A repository-wide grep for `compoff|comp_off|comp-off` returns exactly one hit: the dead
enum value in the legacy `attendance` table (`2026_01_01_000200:52`). There is no accrual,
no balance, no approval, no consumption, and no leave type wired to it. A customer who
works a holiday and expects a compensatory day off has no supported path.

### Q5 · Timezone handling: what is stored, in what zone, and every place the two disagree

**What is stored.** `attendance_logs.punch_at` is a MySQL `DATETIME` and `log_date` a
`DATE` — **neither carries a zone**. The digits are naive local wall clock. Nothing in the
schema records the zone a punch was captured in.

**Which zone.** One global setting, `config('app.timezone')`, whose **default in the
config file is `UTC`** (`config/app.php:68`); only the `.env` templates set
`Asia/Kolkata` (`.env.example:5`, `.env.production.example:14`). Laravel calls
`date_default_timezone_set()` from it at boot, so it also governs every `now()`,
`today()`, and every `Carbon::parse`/`createFromFormat` that omits an explicit zone.
There is **no per-tenant, per-company, per-branch or per-device timezone column anywhere**.
`config/database.php` sets no MySQL connection timezone.

**Every place the two disagree:**

| # | Location | Disagreement |
|---|---|---|
| 1 | `PushController::parseDate() :162-182` | falls back to `Carbon::parse()`, which **honours** a sender's offset; `ETimeOfficeService::import() :740` then renders `format('Y-m-d H:i:s')` in that offset and discards it. `2026-09-30T23:50:00Z` is stored as `2026-09-30 23:50:00` and read as IST — a 5 h 30 m error, silently, into payroll |
| 2 | `ETimeOfficeService::parseDate() :202-222` | identical fallback, identical defect |
| 3 | `GenericApiService::parseDate()` | identical fallback plus an operator-supplied format |
| 4 | `PunchIngestService::parseNaiveLocal() :308-331` | the **opposite** rule — an offset is a rejectable sender bug (`TIME_FORMAT`) |
| 5 | `SmarteptWebhook::toNaiveLocal() :300-317` | a **third** rule — the offset is contractual and is converted into app-tz wall clock |
| 6 | `ETimeTrackLiteService::parseDate()` | a **fourth** rule — 10 fixed formats and **no** `Carbon::parse` fallback, so an odd format yields no punch rather than a wrong one |
| 7 | `AttendanceReportController::punch() :305` | uses server `now()`; the client's own clock is never consulted or compared |
| 8 | `AbsenceNotify::handle() :38` | the send-hour gate is `now()->format('G')` — the **server's** hour, applied to every tenant regardless of where they are |
| 9 | `LateArrivalService::evaluate() :91-94` | "today or yesterday" is computed from server `now()`; a server in a different zone from the site changes what "yesterday" means |
| 10 | `AbsenceService::runForDate() :92-94` | `$ymd` comes from server `now()` when no date is passed; with `APP_TIMEZONE` missing (UTC) this returns *yesterday* between 18:30 and 24:00 IST, so the sweep evaluates the wrong day and can mark a working employee absent |
| 11 | PHP `now()` vs MySQL `CURRENT_TIMESTAMP` | 8 columns are defined `useCurrent()` and take MySQL's session zone, which the application never sets; on a cloud VPS with MySQL at UTC and PHP at IST these are 5 h 30 m behind neighbouring PHP-written timestamps (doc §2.2, R5) |
| 12 | `AttendanceCorrectionService::writeBack() :311` and `AttendanceBulkController::decide() :331, 337` | build `punch_at` by string concatenation `"$date $HH:MM:00"` — zone-safe, but structurally unable to express an overnight pair, and no DST handling |
| 13 | `ETimeOfficeService::sequenceDirections() :657` | the 60-second repeat window is computed with `strtotime()` on the formatted string, i.e. in PHP's default zone — a second implicit dependency on the one global setting |
| 14 | outbound windows: `ETimeOfficeService::fetch() :158-162` (`d/m/Y_H:i`), `ETimeTrackLiteService::fetch()` (`Y/m/d H:i:s`), `GenericApiService` (`date_format_req`) | all sent in **local** time with no zone marker; if the vendor interprets them as UTC the pull window is 5 h 30 m off |
| 15 | `ETimeOfficeService::parseDate() :208` | tries `d/m/Y` and never `m/d/Y`; a US-ordered feed emitting `03/04/2026` is read as 3 April. No configuration exists |
| 16 | `PublicApiController::ping()` | the only mitigation: it exposes `timezone` and `server_time` so a bridge can *voluntarily* detect a mismatch before shipping punches. Nothing enforces or checks it |

**Summary:** four different punch-time rules across the ingest paths (reject / convert /
parse-and-discard / no-fallback), one global zone with a UTC default, no stored capture
zone, and server-local `now()` governing the absence gate, the late window and the in-app
punch. Two of the four rules — the two carrying the majority of real hardware — contain
the exact defect the codebase documents against itself
(`PunchIngestService.php:26-31`).

### Q6 · Is attendance recomputed when a correction is approved and payroll is already locked?

**No — and nothing else happens either.**

`AttendanceCorrectionService::decide() :238-262`:
```php
$locked = PayrollLock::isPeriodFinalised($row->log_date, $row->tenant_id, $row->company_id);
$upd = ['status' => $locked ? 'approved' : 'applied', 'app_in' => …, 'payroll_locked' => $locked, …];
if (! $locked) { $upd['applied_at'] = now(); }
DB::table('attendance_corrections')->where('id', $id)->update($upd);
$written = 0;
if (! $locked) { $written = self::writeBack($row, $appIn, $appOut); }
```

When locked: **no punches are written**, so no attendance figure anywhere changes. The row
sits at `status = 'approved'`, `payroll_locked = true`, and the employee is emailed
*"Payroll for that month is already finalised, so the change will be carried into the next
adjustment"* (`:433`). **No code creates such an adjustment.** A grep finds no reader of
`attendance_corrections.payroll_locked` outside the listing projection (`:365`), no
scheduled job that scans for locked corrections, and no arrears mechanism. The obligation
is created, communicated to the employee, and then abandoned (DEFECT-B-30).

When **not** locked: `writeBack()` writes the punches, and that is all it does. Nothing
recomputes anything — but because every consumer derives at read time, the report, the
absence sweep and the *next* payroll generation all pick the change up automatically. A
payroll run already generated but not yet finalised is **not** regenerated by this action;
someone must re-run it.

Three further problems with the gate itself:
- `PayrollLock::isPeriodFinalised() :64` uses `DATE_FORMAT(pay_date, '%Y-%m')` — MySQL-only.
  On any other driver it throws and the catch at `:72-74` returns `false`, i.e. **fails
  open** and applies the correction to a locked period.
- It matches on the **pay date's month** or a `cycle_label` substring — not on the
  attendance period. Under a 21st–20th cut-off (`PayrollGenController.php:118-126`) a
  correction for the 25th is tested against the wrong run.
- `FINALISED_STATUSES = ['locked','approved','hr_approved','paid']` is a PHP constant
  (`PayrollLock.php:31`); a customer with a different run lifecycle cannot change it.

### Q7 · Duplicate-punch and deduplication behaviour, including whether dedupe can destroy evidence

**Five different duplicate strategies, and four writers with none at all.**

| Writer | Strategy | Evidence |
|---|---|---|
| SBB, SmartEPT | `insertOrIgnore` against `attlog_tenant_external_unique (tenant_id, external_id)` and `attlog_natural_unique (tenant_id, emp_code, punch_at, source)`; affected-rows 0 → verdict `duplicate` | `PunchIngestService.php:141-167`; indexes `2026_08_16_000200:86-94` |
| `/iclock`, vendor pull, quarantine replay | `updateOrInsert` on `(emp_code, punch_at, source[, tenant_id])` — a re-sync **overwrites** `direction`, `emp_name`, `log_date`, `tenant_id`, `company_id` in place | `ETimeOfficeService.php:745-767, 523-531` |
| LAN ZKTeco | `updateOrInsert` on `(emp_code, log_date, direction, source[, tenant_id])` — `direction` **in** the key, `punch_at` **not** in it, so only one IN and one OUT can ever exist per employee-day and a corrected direction manufactures a second row | `DeviceController.php:193-207` |
| In-app punch | plain `insert`, **no dedup, no throttle** | `AttendanceReportController.php:321`; `routes/web.php:361` |
| Bulk approval | plain chunked `insert`, **no dedup** — re-approving after a re-upload duplicates the day | `AttendanceBulkController.php:341-343` |
| Manual Punch master | plain master insert, **no dedup** | `MasterController.php:474-481` |
| Correction write-back | `DELETE` then plain `insert` — idempotent only because of the delete; the insert is not `insertOrIgnore`, so a residual identical row would throw | `AttendanceCorrectionService.php:295-319` |
| Quarantine (SBB) | `insertOrIgnore` on `(tenant_id, external_id)` | `PunchIngestService.php:368-371` |
| Quarantine (`/iclock`, pull) | `updateOrInsert` on `(tenant_id, device_code, punch_at, source)` — chosen deliberately because the unique index cannot protect a NULL tenant (`:405-408`) | `ETimeOfficeService.php:410-438` |

**Critical caveat on the indexes.** In MySQL a NULL never collides in a unique index. On
any install where `tenant_id` is NULL — which is the state of every self-hosted customer
and of every punch from an auto-registered `/iclock` device — **both unique indexes are
inert**. `ApiKeyAuth` and `SmarteptSignature` refuse tenant-less credentials for exactly
this reason, but the `/iclock`, LAN, in-app, bulk, manual and correction writers all still
write NULL-tenant rows with no index protection.

**The repair command — and yes, it destroys evidence.**
`php artisan attendance:dedupe [--dry] [--tenant=N]` (`DedupeAttendance.php`):
```php
$q = DB::table('attendance_logs')->…->orderBy('id')->get([...]);   // WHOLE TABLE into memory
$key = tenant|emp_code|punch_at|source;  keep = MAX(id) per key;
foreach (array_chunk($doomed, 500) as $chunk) {
    $deleted += DB::table('attendance_logs')->whereIn('id', $chunk)->delete();   // HARD DELETE
}
```
- It is a **hard `DELETE`**. There is no soft delete on `attendance_logs`, no archive
  table, and **no log of which rows were removed** — the command prints only a count
  (`:84`), and a sample of at most 10 ids in `--dry` mode (`:70-73`).
- **Provenance is not merged before deletion.** The kept row is simply the highest id. If
  the older row was the one carrying `external_id`, `device_sn`, `device_user_id` or
  `verify_mode` (as it will be whenever an SBB-ingested punch is later re-imported by a
  pull), that provenance is **destroyed**. Contrast `promotePending()`, which explicitly
  fills provenance in without overwriting (`ETimeOfficeService.php:502-523`) — the dedupe
  command has no equivalent.
- **Two genuinely distinct punches are indistinguishable.** The identity is
  `(tenant, emp_code, punch_at, source)` to the second. Two separate reads from two
  different physical readers on the same feed in the same second are collapsed to one,
  irrecoverably.
- **It loads the entire `attendance_logs` table into PHP memory** (`->get()` with no
  chunking, `:42-45`), while the two migrations doing the same job chunk at 2 000. On a
  real site this will exhaust memory mid-run — and there is no transaction wrapping the
  chunked deletes, so a partial run leaves the table partially deduped with no record of
  where it stopped.
- **It is executed automatically, unattended, during `php artisan migrate`.**
  `2026_08_16_000200_extend_attendance_logs_for_sbb.php:128` calls
  `Artisan::call('attendance:dedupe')` before creating the unique index, and
  `2026_08_17_000100_repair_sbb_schema.php:152-154` runs an inline equivalent. Production
  punch rows are therefore deleted during a deploy with **no operator confirmation, no
  dry-run, no backup and no record of what was removed** (DEFECT-B-25). The migration's own
  docblock says why (`:26-33`: *"production data already contains duplicates"*) — which
  means it is known that real rows will be destroyed.

`attendance:purge-demo` (`PurgeDemoAttendance.php:29-33`) is a second hard-delete path,
and its own docblock records that rows fabricated before rev 51 were mis-tagged
`source='biometric'` and *"are indistinguishable from real device punches"* (`:16-18`) —
so a contaminated database cannot be cleaned without deleting real attendance.

**Net:** duplicate handling is inconsistent across nine writers, structurally ineffective
on single-tenant installs, absent entirely on the four human paths, and the repair tool is
an unlogged, unbounded, automatically-invoked hard delete that can and does destroy punch
provenance. Any SmartPRS 3 cutover must be preceded by a full snapshot of
`attendance_logs`.


---

# SOURCE: C-biometric-integrations.md

## 11. Open questions / PRODUCT OWNER DECISION REQUIRED

1. **PRODUCT OWNER DECISION REQUIRED — `/iclock` trust model.** Real ZKTeco/eSSL firmware
   cannot present a credential. Options: (a) keep it unauthenticated but require an admin
   to pre-register and claim each serial before punches are accepted (punches quarantined
   until then); (b) require a network-level control (VPN / IP allow-list per tenant);
   (c) deprecate `/iclock` in favour of SBB on every site. **This decision gates
   requirements 8, 9 and 10 and is the single largest security question in this area.**
2. **PRODUCT OWNER DECISION REQUIRED — is the LAN ZKTeco path (`DeviceController`) sold to
   anyone today?** It has no UI, no views, unrouted CRUD, and loses intra-day punches.
   If no live customer uses it, REMOVE. If any does, it needs a full rebuild.
3. **PRODUCT OWNER DECISION REQUIRED — must a device be able to be shared across
   companies within one tenant?** `biometric_configs` has no `company_id` at all
   (DEF-C-08) and the Pending Punches UI implies it does. Decide the intended cardinality
   (tenant → company → branch → device) before the schema is redesigned.
4. **PRODUCT OWNER DECISION REQUIRED — same PIN on two devices.** Is "PIN 5 on the Chennai
   gate is Ravi and PIN 5 on the Pune gate is Sunil" a supported scenario? The quarantine
   replay already assumes it is (`promotePending`'s `$deviceSn`, pinned by
   `IclockTenantHotfixTest.php:161-187`) but the live mapping model cannot express it.
5. **PRODUCT OWNER DECISION REQUIRED — what should happen to an unknown direction?**
   Today it silently becomes `IN` on four paths. `UNKNOWN` + a review queue is safer but
   changes what payroll sees. Which is the default, and is it per tenant or per device?
6. **PRODUCT OWNER DECISION REQUIRED — SSRF posture for the generic connector.** An
   egress allow-list makes the "any vendor without code" promise weaker (a customer's
   on-prem device on an odd port must be explicitly permitted). Who approves an egress
   entry — the tenant admin, or Ametecs?
7. **PRODUCT OWNER DECISION REQUIRED — is SmartEPT a permanent partner integration or the
   first of many signed-webhook senders?** This decides whether `SmarteptWebhook` becomes
   a configured adapter (requirement) or stays a bespoke class.
8. **PRODUCT OWNER DECISION REQUIRED — mobile app.** Is the device gate meant to be a real
   security control? If yes it must be enforced server-side (SEC-C-08). Are push
   notifications on the roadmap, or should the token registry be deleted (DEF-C-20)?
9. **PRODUCT OWNER DECISION REQUIRED — historic backfill on mapping.** Should mapping a
   device ID rewrite historic `attendance_logs` rows at all (NEW-C-11)? It changes inputs
   to payroll runs that may already be approved. Options: rewrite (today), rewrite only
   within an open attendance period, or never rewrite and require an attendance
   correction.
10. **PRODUCT OWNER DECISION REQUIRED — sync frequency granularity.** Is the fixed
    5/10/15/30/60 dropdown sufficient, or do customers need arbitrary intervals, sync
    windows (e.g. 06:00–22:00 only), or cron expressions?
11. **PRODUCT OWNER DECISION REQUIRED — vendor feature parity.** Do we need to preserve
    break-in/break-out/OT-in/OT-out as distinct punch types (ZKTeco status bytes 2/3/4/5,
    currently collapsed into `in`/`out` at `PushController.php:159`)? This is a data-model
    decision, not just a mapping one.
12. **OPEN QUESTION — evidence gap.** `bootstrap/app.php` is absent from the staged source,
    so the `api-key` / `smartept-webhook` middleware alias registration and the mounting of
    `routes/api.php` and `routes/smartept.php` were inferred, not verified. Obtain the file
    before the blueprint relies on any claim about middleware ordering on those routes.
13. **OPEN QUESTION — which `attendance_pending` shape exists on each live customer
    database?** CONF-C-01 means at least one of the two features is broken on every
    install, and which one depends on install history. This must be surveyed before any
    migration is designed.
14. **OPEN QUESTION — do any production `biometric_configs.password` values fail
    decryption today?** SEC-C-13 means such rows work silently. A one-off audit query
    would tell us whether plaintext credentials exist in customer databases.


---

# SOURCE: D-employee-master.md

## 11. Open questions / PRODUCT OWNER DECISION REQUIRED

| # | Question | What is known | What is needed |
|---|---|---|---|
| **PO-01** | **Does the customer need employee exit / Full-and-Final, and must an exited status survive an edit?** | `exited` frees a seat (`SubscriptionService.php:37`); the original enum had `notice` and `exited`; a comment says "Real exits should use Exit & FnF" (`AppDataController.php:874`); an `exits` table and nav screen exist. **No path in this subsystem can produce `exited`, and any form save resets it to `active`.** | Confirm the exit lifecycle, its statuses, and that they are protected. Out-of-scope module (`Exit & FnF`) must be read. **DECISION REQUIRED** |
| **PO-02** | **Which employee attributes must be effective-dated in SmartPRS 3?** | Nothing is today; transfers, increments and probation give partial coverage (Q5) | A definitive list. Every one added is a table and a UI. Candidate minimum: company, branch, department, designation, grade, reporting manager, CTC, salary type, employment stage, status. **DECISION REQUIRED** |
| **PO-03** | **Is `whatsapp` supposed to be duplicate-blocked?** | `mobile` is, `whatsapp` is not, though both use the same validator and verifying WhatsApp marks the *mobile* verified too (`SelfOnboardingController.php:235-237`) | Deliberate (shared family numbers) or oversight? **DECISION REQUIRED** |
| **PO-04** | **Should an increment auto-apply on its effective date?** | Deliberately manual per a recorded customer decision (`IncrementController.php:310-313`), which means an approved, letter-sent increment can sit unapplied indefinitely | Confirm whether this stays, becomes configurable, or is reversed. **DECISION REQUIRED** |
| **PO-05** | **What is the correct annual→monthly divisor and proration basis for arrears?** | Hard-coded `/12` and calendar-day proration (`IncrementController.php:366`, `:369`). Never state these as legally correct | Payroll policy input; likely per-customer. **DECISION REQUIRED** |
| **PO-06** | **What employment stages does the business actually need?** | Three (`''`, `probation`, `internship`); `Contract` was explicitly *not* given a stage and is mapped to Permanent (`AppDataController.php:135`, parity migration `:96-114`). Payroll behaviour (skip PF/PT/TDS for probation and internship) is asserted in `ProbationService`'s docblock but **not verified in this analysis** | The stage set, and the statutory rule attached to each. Requires reading `PayrollGenController`. **DECISION REQUIRED** |
| **PO-07** | **Should `dra_declared` / `pcc_declared` (the self-declaration) drive anything?** | Captured on three surfaces, exported, read back onto the form — and read by **no** report or gate. Its only enforcement is a browser-side new-hire check | Keep as a declaration of record, promote to a compliance input, or drop? **DECISION REQUIRED** |
| **PO-08** | **Should off-roll agents become employees with an engagement type?** | They are a parallel table with no code, no uuid, no unique constraint, no statutory data, one free-text bank field, name-based idempotency, free-text DRA/PCC with no dates, and a runtime-created schema | The merge is strongly recommended (§9) but changes the licensing model — agents are currently never counted against seats. **DECISION REQUIRED** |
| **PO-09** | **Should archiving free a licensed seat?** | It does not (`seatUsage()` ignores `archived_at`), but the product's own error message says it does (`SubscriptionService.php:141`) | Either fix the code or fix the message — the commercial answer decides which. **DECISION REQUIRED** |
| **PO-10** | **What is the expected import volume and SLA?** | No row cap, no streaming, whole file in memory twice, one transaction. Practical limits are `upload_max_filesize`, `memory_limit`, `max_execution_time` — Laragon/XAMPP defaults would fail well before 10 000 rows | A stated maximum row count and a target time. **DECISION REQUIRED** |
| **PO-11** | **Is `storage:link` present on deployed installs?** | `DocumentController` deliberately serves through a controller "so no storage:link is needed" (`:12-18`), but self-onboarding writes documents to the **public** disk under a predictable path (`SelfOnboardingController.php:954`), and employee photos go to `emp-photos` on the public disk | Decides whether SEC-15 is exploitable today. Requires the deployment scripts. **INFORMATION REQUIRED** |
| **PO-12** | **Who may edit which employee fields via ESS, and does it need approval?** | Today: 10 fields, no validation, no uniqueness, no sanitisation, no approval, no audit (DEF-12, NEW-EMP-071) | A field allow-list and an approval policy. **DECISION REQUIRED** |
| **PO-13** | **Was a Recruitment → self-onboarding hand-off ever intended?** | `self_onboarding.candidate_id` exists, is indexed, and `issue()` has a reuse-by-candidate branch (`:87-94`), but **no caller ever passes `candidate_id`** — a repo-wide grep finds no external caller of `issue()` | Removed, never built, or wanted? **DECISION REQUIRED** |
| **PO-14** | **What is the retention policy for employee PII?** | None exists. Archive snapshots, `self_onboarding.data`, staged import files, documents and import error reports all retain PAN, national ID, UAN, bank accounts and plaintext passwords indefinitely | A retention and encryption policy (DPDP Act 2023 relevance not assessed here). **DECISION REQUIRED** |
| **PO-15** | **Does `also_works_for` / "Multiple companies" need to be a real capability?** | The field is captured on four surfaces and read by nothing; the `employee_company` pivot exists and is never used (NEW-EMP-074, NEW-EMP-076) | Build multi-company employment properly, or delete the field. **DECISION REQUIRED** |
| **PO-16** | **What happens to the four orphan seat-free statuses in existing data?** | `resigned`, `terminated`, `left`, `absconded` are recognised by `SEAT_FREE_STATUSES` but writable only by the import wizard's unvalidated `status` column or direct SQL | A data audit per tenant before the status migration. **INFORMATION REQUIRED** |
| **PO-17** | **Is `probation_months` (per-employee probation length) a required capability?** | The column, the derivation and the unit test all exist; **no UI or import can set it** (NEW-EMP-073) | Build the UI or drop the column. **DECISION REQUIRED** |

---

*End of analysis.*


---

# SOURCE: E-leave-requests-notifications.md

## 11. Open questions / PRODUCT OWNER DECISION REQUIRED

| # | Question | Why it matters | What I could not determine from source |
|---|---|---|---|
| **PO-01** | **How far back must LOP-leave mispayment be corrected?** DEF-LVE-02 means every approved leave since go-live — including leave filed as "Loss of Pay" — was credited as a paid day. | Direct salary overpayment, potentially across every payroll cycle run to date. | The blast radius is a data question (how many `leaves` rows with a Loss-of-Pay `type_name` were approved and overlapped a generated payslip). **PRODUCT OWNER + a data query on the live database.** |
| **PO-02** | **What opening leave balances does each tenant start SmartPRS 3 with?** Carry-forward was never applied, so the current stored state does not represent what employees are entitled to. | Migration cannot derive the right number; deriving it from `leave_types.carry_forward` would silently grant balances nobody ever accrued. | **PRODUCT OWNER DECISION REQUIRED** — opening balances must be stated per tenant, not computed. |
| **PO-03** | **Do the nine request types survive as-is, or is the catalogue re-designed?** Several look like a single tenant's requirements (Clawback, Commission with DRA gating, Exit clearance with "DRA ID returned"). | Determines whether SmartPRS 3 seeds nine types or a smaller generic set plus a collections-industry pack. | **PRODUCT OWNER DECISION REQUIRED.** |
| **PO-04** | **Is edit-after-approval ("Ejaz's rule") retained, and for which types?** It is deliberate and documented in-code, and it conflicts with an immutable-decision model. | Shapes the whole state machine. | The intent is recorded (`RequestController.php:758–762`); whether it generalises is not. **PRODUCT OWNER DECISION REQUIRED.** |
| **PO-05** | **Should compliance-score and open-grievance checks be able to BLOCK, as the DRA gate can?** Today they are hard-coded warn-and-allow. | Determines whether these become first-class rules with a tri-state, or stay advisory. | **PRODUCT OWNER DECISION REQUIRED.** |
| **PO-06** | **What is the correct leave-year basis per customer** — calendar, April–March, or anniversary-of-joining? Today leave uses the calendar year and everything else uses April–March. | Affects accrual, carry-forward, lapse and every historic balance. | **PRODUCT OWNER DECISION REQUIRED.** |
| **PO-07** | **Should leave days be charged in calendar days or working days?** Today the balance is charged in calendar days and payroll credits working days. Changing it alters every historic balance. | A Fri–Mon leave currently costs 4 days and credits 2. | **PRODUCT OWNER DECISION REQUIRED**, including whether historic `days` values are recomputed or frozen. |
| **PO-08** | **Is `denyUnlessRole`'s fail-open behaviour to be reversed everywhere at once?** It is deliberate ("availability over strictness"). Reversing it could lock users out during a pivot/schema fault. | 71 call sites; the highest-leverage security change available. | The trade-off is stated, not the risk appetite. **PRODUCT OWNER DECISION REQUIRED.** |
| **PO-09** | **Is the mobile device gate a security control or a UX flow?** It is marketed as "the anti-fraud layer"; it is not enforced. | Either build server-side enforcement or correct the marketing claim. | **PRODUCT OWNER DECISION REQUIRED.** |
| **PO-10** | **Is WhatsApp a real notification channel for HR events?** A 17-template library is seeded, tested and approvable, and nothing sends from it. | Either wire the channel or remove the library, which currently over-promises. | **PRODUCT OWNER DECISION REQUIRED.** |
| **PO-11** | **What retention applies to each log table?** The published policy commits to 90 days; nothing implements any retention; `commission_logs` holds customer names and account references indefinitely and `wa_log` holds plaintext temporary passwords. | DPDP exposure and a live contradiction with a published commitment. | **PRODUCT OWNER + LEGAL DECISION REQUIRED.** |
| **PO-12** | **Must an ESS email/mobile change be verified, and should it require HR approval?** Today it is instant, unverified and unaudited, and it redirects payslips. | Determines whether ESS becomes a maker-checker surface. | **PRODUCT OWNER DECISION REQUIRED.** |
| **PO-13** | **How are the nine silently-dropped fields recovered?** The data was never written; it cannot be recovered from the database. | Historic expense claims have no reason, historic loans no tenure or purpose, historic clawbacks no recovery month. | **PRODUCT OWNER DECISION REQUIRED** — accept the loss, or re-collect. |
| **PO-14** | **Does `activity_logs` migrate as verified history?** It cannot: the chain is already broken at every commission-warning row and every bounce, and the race can have broken it elsewhere. | Affects any claim of a continuous tamper-evident record. | Recommend migrating with an explicit "pre-migration, unverifiable" marker. **PRODUCT OWNER DECISION REQUIRED.** |
| **PO-15** | **Should `super_admin`/`admin` continue to satisfy both the Manager and the Accounts role?** It defeats the segregation of duties the Accounts gate exists to provide. | Audit and fraud posture on commission payments. | **PRODUCT OWNER DECISION REQUIRED.** |
| **PO-16** | **Who is the fallback approver when an employee has no reporting manager?** Today: nobody — the request is created with a null approver, no email is sent, and it is invisible to every non-manager inbox. | A silent black hole in the current system. | **PRODUCT OWNER DECISION REQUIRED.** |
| **PO-17** | **Are the three escalation thresholds (₹10,000 / ₹10,000 / ₹50,000) a real policy or a placeholder?** Per the shared brief these are recorded as *the values the code currently uses*, never as correct values. | They will be seeded as per-tenant defaults. | **PRODUCT OWNER CONFIRMATION REQUIRED.** |
| **PO-18** | **Is the 5% commission TDS default correct, and should it come from statutory configuration?** The literal `5.0` appears in three places; a per-tenant `comm_tds_rate` exists in Statutory Rates and **is not read** by this code. Recorded as the value the code uses, not as a legal rate. | Direct effect on net payable to agents. | **PRODUCT OWNER + STATUTORY REVIEW REQUIRED.** |
| **PO-19** | **Should leave submission notify the approver?** Requests do; leave does not, and the omission looks unintentional rather than designed. | Explains why leave sits unapproved in practice. | **PRODUCT OWNER CONFIRMATION** (the answer is almost certainly yes). |
| **PO-20** | **Is `transfers` — the only request table with no migration — expected to exist on every deployment?** It is created by a runtime `Schema::create` on first touch. | Any deployment that never touched the code path has no table and no data. | **PRODUCT OWNER + OPS CHECK** on live installs before migration. |


---

# SOURCE: F-statutory-compliance.md

## 12. Open questions / PRODUCT OWNER DECISION REQUIRED

1. **[DECISION REQUIRED] Every statutory value in §Q1 needs legal verification and dating.** Not one of the ~90 values in this codebase carries a source citation, an effective date or a verification record. Ametecs must commission a qualified review of all of them, and SmartPRS 3 must refuse to ship a statutory parameter without `(source_reference, effective_from, verified_by, verified_on)`.

2. **[DECISION REQUIRED] `pf_applicable` / `esi_applicable` are populated but ignored.** Making them work will change payroll outcomes for existing customers who have set them. Options: (a) honour them from a cutover date with a mandatory pre-cutover reconciliation report; (b) migrate all values to "auto" and re-collect; (c) honour them and reprocess. **This decision must be made before any migration is designed.**

3. **[DECISION REQUIRED] Does SmartPRS 3 commit to Old Regime + full declaration/proof TDS?** This is the largest single build item in this area (§Q5, items 1–11). If the answer is no, the product cannot honestly be sold as an Indian payroll system.

4. **[DECISION REQUIRED] Which PT jurisdictions must SmartPRS 3 ship?** Today 26 of ~36 are named, and an uncovered state silently gets Telangana's slab rather than zero or an error. What is the correct behaviour for an unmapped jurisdiction: refuse to run payroll, charge zero, or require explicit configuration?

5. **[DECISION REQUIRED] How does statutory content reach on-prem installs?** See Q9. Options: signed content packs over a dedicated channel with a scheduled pull; a mandatory phone-home; or an accepted lag. This is the highest-value non-functional decision in this area and it drives the SmartPRS 3 architecture.

6. **[DECISION REQUIRED] The fabricated assertions must be removed or made real, now.** The 24Q "Filed" status (DEFECT-F-07), the audit report's "Disciplinary actions: 0 · Compliant" (DEFECT-F-09), the "tamper-evident hash" (DEFECT-F-10), and the Code-of-Conduct false positive (DEFECT-F-11) all cause SmartPRS 2 to make regulatory assertions it has not verified. These are live in production. Does Ametecs remediate SmartPRS 2, or accept the exposure until SmartPRS 3?

7. **[DECISION REQUIRED] Migration `2026_08_27_000100` destroyed PCC `'overdue'` data** on the stated basis that the deadline-derived equivalent already existed. It does not (DEFECT-F-16). Is that data recoverable from backups, and should it be restored?

8. **[DECISION REQUIRED] Do gratuity and bonus become payable, or stay as registers?** Both are complete calculators with no payment path (BR-STAT-015). If they become payable, they need approval workflow, F&F integration, ledger posting and their own statutory treatment.

9. **[DECISION REQUIRED] What is the compliance module's target regulatory scope?** It is built for RBI recovery-agent norms specifically. Is SmartPRS 3's compliance engine general-purpose (any expiring credential for any industry), or does it stay collections-specific with a general engine underneath? This determines whether item types are a first-class configurable entity.

10. **[DECISION REQUIRED] Off-roll agents.** They are the population most exposed to RBI agent norms and are entirely outside compliance, with untaxed payouts (BR-CMP-029, DEFECT-F-44). Do they become full employees of the compliance model, a parallel vendor model with its own contractor-compliance rules, or out of scope?

11. **[OPEN] Which of the five DRA-validity rules is correct?** (DEFECT-F-08). Specifically: does an *expired* structured certificate fall back to a legacy `dra_status='verified'` (as the audit report allows) or not (as `draValid` refuses)? This changes who gets paid.

12. **[OPEN] Should `pcc_status='submitted'` count as compliant?** The audit report says yes (`:579`); the score says no (`:303`). One of them is wrong.

13. **[OPEN] Is the ESI contribution-period lock's behaviour correct in edge cases** — a rejoiner mid-period, a transfer between establishments, a retrospective arrear that pushes an earlier month over the ceiling? The current implementation reads only stored payslip deduction *names* and has no concept of establishment.

14. **[OPEN] What is the correct annualisation for half-yearly PT states** (TN, Kerala) and annual-capped states (MP, MH)? The current "monthly equivalent × 12" is wrong for at least four states (DEFECT-F-29), and the underlying slabs are stored in a shape that cannot express the real periodicity.

15. **[OPEN] Should the Code of Conduct be tenant-owned?** It is currently global KB content shared by every tenant (SEC-F-06), which is almost certainly wrong for a legal undertaking staff sign.

16. **[OPEN] Retention and deletion.** `data_retention_months` (default 84) exists in `defaults()`, is unsettable through any UI, and **has no consumer**. `BR-CMP-311` records "No business record is ever removed on age — NOT IMPLEMENTED". What is the DPDP-compliant retention policy for compliance evidence and statutory records?


---

# SOURCE: G-security-tenancy-api.md

## 11. Open questions / PRODUCT OWNER DECISION REQUIRED

| # | Question | Why it matters | Blocking? |
|---|---|---|---|
| Q-01 | **Is `php artisan storage:link` run on the production SaaS deployment, and what does the live `public/.htaccess` contain?** | Decides whether employee Aadhaar/PAN/bank documents are currently downloadable without authentication, and whether the upload path is an RCE. `public/.htaccess` is **not in the repository at all**. This is the single highest-value question in the dossier. | **YES — DPDP breach assessment** |
| Q-02 | **Do on-premise customers install the scheduler cron?** | `Schedule::command('demo:reset')->dailyAt('03:30')` is unconditional. If yes, every such install has an `admin` account with a source-published password, recreated nightly. | **YES** |
| Q-03 | **Has `OnPremWorkspaceSeeder` been run on any customer install?** | It creates `admin@smartprs.local` / `password` on an arbitrary tenant and prints the credential. | **YES** |
| Q-04 | **Is `field_agent` intended to see the employee directory at all?** | Determines whether the fix is a one-argument change (`selfScope($request, true)`) or a field-level allow-list. Either way the salary/bank/Aadhaar exposure is indefensible. | YES for the fix design |
| Q-05 | **Is `platform_support` in use today, and what is it contractually allowed to see?** | It has `tenant_id = NULL`, zero checks, and renders as "Admin". If the answer is "vendor support reads customer data", that must be in the customer contract and the DPDP notice. | YES |
| Q-06 | **What is the intended `accountant` capability set?** | Two `denyUnlessRole` sites already grant it commission/bank-pack authority, but the role cannot exist. Deciding this determines whether it is created, renamed, or removed from the dropdown. | No |
| Q-07 | **Are L1/L2/L3 editions still a commercial product?** | The differentiation is retired in code (`Edition::level()` returns `3`; `blockedPatterns()` is edition-independent). If licences are still sold on module tiers, every on-prem customer currently has the full product. | **YES — revenue** |
| Q-08 | **Are plans still sold on module inclusion?** | `applyPlanGate()` starts with `return;`. `plans.features` is written by the Plans UI and enforced nowhere, client or server. | **YES — revenue** |
| Q-09 | **What is the intended approval hierarchy and the intended thresholds?** | `ApprovalService::modules()` hard-codes ₹10,000 / ₹10,000 / ₹50,000 / 0 and a fixed three-level escalation. A customer will certainly want these configurable; the PO must define the *shape* (amount bands? role-based? per company? per department?). | YES for rule-engine design |
| Q-10 | **What data scopes does the business actually need?** | The product already sells master/subsidiary companies, branches, departments, teams and reporting managers. The PO must state which of these are legitimate authorization boundaries, because SmartPRS 3 must implement them as first-class scopes rather than as more `selfScope` branches. | **YES — blocks the RBAC design** |
| Q-11 | **Who is allowed to approve payroll, and is a two-person rule required?** | `isHR()` and `isFinance()` exist as branch selectors but never abort on the read/preview paths, and `admin` satisfies both — so there is currently no segregation of duties in payroll at all. | YES |
| Q-12 | **What is the retention and residency policy for uploaded ID documents and for backups?** | Neither exists today; documents are excluded from the backup entirely while `.env` is included. | YES for the storage design |
| Q-13 | **Should tenant administrators be able to define their own roles, or only choose from a vendor catalogue?** | Determines whether the permission tables are tenant-partitioned (Spatie `teams => true`) or global with a per-tenant assignment layer. This decision cannot be deferred — it changes the schema. | **YES — blocks the schema** |
| Q-14 | **Is the `/iclock` unauthenticated device path a hard commercial requirement?** | The docblock argues *"a device cannot send a token."* If a per-device shared secret or a registration allow-list is acceptable, the highest-volume unauthenticated write in the product can be closed. | YES |
| Q-15 | **What was the intended lifetime of the `app_state` blob?** | 113 screens' worth of records still live in it, including the role matrix. SmartPRS 3 must normalise them; the PO must confirm which of those collections are still business-relevant. | **YES — blocks data migration** |
| Q-16 | **Does `bootstrap/app.php` exist in the real tree, and if so does `withExceptions()` register anything?** | This analysis could not read it. It determines the true global middleware stack, whether any CSRF exemptions are global, and how exceptions are rendered. | YES for completeness |


---

# SOURCE: H-recruitment-hrops-reports.md

## 11. Open questions / PRODUCT OWNER DECISION REQUIRED

### The nine questions posed to this analyst — answered

**Q1. The master-data registry: how many master types, data-driven or hard-coded, can a customer extend it without code?**
**58 master types over 54 distinct tables, defined as a PHP array literal** in `MasterController::def()` (`MasterController.php:30-493`). It is a **hard-coded registry, not data-driven**. A customer can add *rows* to any of the 58; they **cannot** add a new master type, add or remove a field, change a label, change a field's type or precision, change which fields are mandatory, add validation, or change who may read or write a given master — every one of those is a code change. The only thing that is dynamic is the *physical schema*: `ensureCols()` creates the table and adds declared columns at runtime, which means the code change to add a field does not need a migration, but it is still a code change and a release.

**Q2. Recruitment pipeline stages — configurable or hard-coded? Own pipeline? Extensible candidate fields?**
**Hard-coded.** `private const STAGES = ['applied','screening','interview','offer','hired','rejected']` (`RecruitmentController.php:31`). So are the approval states (`:33`), interview modes (`:35`), interview statuses (`:37`), recommendations (`:39`) and requisition priorities (`:301`). A customer **cannot define their own pipeline** — not the stages, not their order, not their names, and not the transitions (there is no transition matrix at all: `moveStage` validates membership of the list and nothing else). **Candidate fields are not extensible**: the ~30 columns are declared in `ensure()` (`:100-114`) and there is no custom-field mechanism anywhere in the product.

**Q3. Candidate → Employee conversion: re-entered, mapped, or lost? Which fields survive?**
**Mapped, and heavily lossy.**

*Survive* (`createEmployeeFromCandidate` `:626-635`): `name`, `email`, `mobile`, `position` → `designation_id` (looked up, **auto-created if missing**), `offered_ctc` → `ctc`, `offer_doj` → `doj` (falling back to today), `company_id`. Supplied at conversion time by the Hire dialog: `department_id`, `branch_id`, `reporting_manager_id`, `salary_type` (default `only_salary`), `type` (default `office`).

*Lost* — held on the candidate and never copied: `location`, `experience_years`, `current_company`, `current_designation`, `current_ctc`, `expected_ctc`, `skills`, `resume_url`, `notes`, `interview_remarks`, `offer_incentive`, `joining_bonus`, `offer_notes`, `rating`, `source`, `applied_on`, and the entire interview history (which stays linked to the candidate, not the employee).

*Never captured at all* — so they must be re-entered later: `pan`, `uan`, `bank_acc`, `ifsc`, `pf_no`, address, date of birth, emergency contact, references, DRA/PCC status. The candidate table has no columns for any of them.

The consequence is that **a newly hired agent starts with an empty compliance file** and is immediately reported as non-compliant by the agent audit. For an **off-roll** hire the loss is worse: no employee record at all, no onboarding record, no seat consumed, `dra`/`pcc` captured as free strings that nothing reads, and complete invisibility to the compliance module.

Conversion is also **idempotent by employee name** (`:588-596`), so a second person with the same name is silently treated as already hired and never gets a record.

**Q4. Letter/document generation: template engine or hard-coded strings? Every letter type and where its body comes from. Can a customer edit a template without code?**
**There is a real template engine** — `letters.body` holds a reusable body with `{{placeholders}}`, and `LetterController::buildLetter()` merges it with live data via a single `strtr()` over 21 keys. A customer **can** edit a template body without code, through the *Letter Templates* master screen.

But the engine is bounded on three sides: **the placeholder list is code** (adding `{{probation_period}}` is a release), **rich text is impossible** (`stripHtmlDeep()` on the master save path destroys any HTML, and destroys the seeded HTML offer template on first edit), and **the letter type list is code**.

Letter types and body provenance:

| Type | Issue screen | Body source |
|---|---|---|
| `offer` | `letters-offer` ✓ | DB template (`is_template=1, letter_type='offer'`), auto-seeded per tenant as HTML by `SeedIndustryContent::seedOfferTemplate()` `:89-104`; sample text also in `LETTER_SAMPLES.offer` (`AppController.php:10714`) |
| `increment` | `letters-increment` ✓ | DB template; sample in `LETTER_SAMPLES.increment` |
| `warning` | `letters-warning` ✓ | DB template; sample in `LETTER_SAMPLES.warning` |
| `relieving` | `letters-relieving` ✓ | DB template; sample in `LETTER_SAMPLES.relieving` |
| `nda` | **registered in the SPA, missing from `MasterController::def()` — cannot be created** | DB template if one exists; else **hard-coded 5-clause default** at `LetterController.php:76-85`; the signing page uses a **different, shorter hard-coded default** at `NdaSignController.php:56`; sample in `LETTER_SAMPLES.nda` |
| `experience` | **none** — templatable, never issuable | sample in `LETTER_SAMPLES.experience` |
| `custom` | **none** | empty sample |

Non-letter documents generated by this area: the **employee ID card** (`idcard-pdf.blade.php`, fixed layout) and the **agent audit report** (owned by the compliance analyst). Both are blades, not templates.

Template resolution is: the chosen `template_id` (tenant-scoped) → **the newest** template of that type in the tenant → the NDA built-in → a 422. `letters.status` (`active`/`inactive`) is written by the UI and **never read**, so deactivating a template does not stop it being used.

**Q5. The public token flows: generation, entropy, expiry, replay, evidence, secure?**

| Property | Finding |
|---|---|
| Generation | `Str::random(40)` — Laravel's CSPRNG wrapper over `random_bytes()` (`LetterController.php:402`) |
| Entropy | base62 × 40 ≈ **238 bits** — cryptographically ample |
| Storage | plaintext in `letters.accept_token`, a plain string column, **no unique index**, **no index at all** |
| Expiry | **none.** No column, no TTL, no check. The 404 text on both routes claims an expiry that does not exist |
| Rotation | **none.** Re-sending reuses the existing token (`?:` at `:402`) |
| Revocation | **none.** No code path clears the token |
| Single use | **not enforced.** The token still resolves after acceptance; the page renders in its "already accepted/signed" state |
| Replay | the accept/sign writes are idempotent by status, so a replay is a no-op — but the token remains a permanent capability |
| Rate limiting | **none** on any of the four routes |
| CSRF | offer accept: protected. **NDA sign: explicitly exempted** (`routes/web.php:63-64`) |
| Server-side validation | offer: none needed. **NDA: none performed** — neither the typed name nor the consent tick is required or checked server-side |
| Type scoping | NDA route filters `letter_type='nda'`. **Offer route does not filter at all** — any non-template letter with a token resolves and can be "accepted" |
| Tenant scoping on lookups | correct on the token itself (it is the secret). **Incorrect** on `NdaSignController`'s employee lookup `:44` and template lookup `:47-49` |
| Evidence captured | `status`, `accepted_at` (server time), `accepted_ip` (`$request->ip()`), and for NDA `signed_name` |
| Evidence **not** captured | user agent, device, signature image, consent record, the document content, a content hash, a rendered PDF, any identity verification, **and any `activity_logs` entry** |
| Document integrity | **none.** The issued row stores a template pointer; the merge happens at render. Editing the template rewrites what was signed |
| Identity proof | **none.** Anyone holding the URL can bind the named person. The product already implements OTP on other public token routes (self-onboarding) and does not here |

**Verdict: not secure, and not adequate as a legal artefact.** The combination of no expiry + no single-use + no revocation + CSRF exemption + no server-side validation + no identity proof + a mutable document means a leaked NDA link is a permanent, unattended signing capability for a document whose text can change afterwards.

**Q6. Reports: how many, hard-coded or builder, can a customer add one or a column, paginated?**
**Fourteen datasets**, each a **hand-written query with a hard-coded column list** inside one 360-line `build()` method (`ReportController.php:58-417`). There is **no report builder**. A customer cannot add a report, add a column, remove a column, reorder or rename columns, add a filter beyond the fixed month + company pair, save a view, schedule delivery, or choose an output format other than CSV.

**Not paginated and not streamed.** Preview caps at 100 rows; **export passes `limit = null`** and builds the entire CSV as one PHP string in memory before returning it (`:470-479`). Two datasets make this worse: `reg-muster` ignores the limit entirely even on preview, and `compliance-scorecard` issues ~5 queries per employee inside the row map — a 500-agent export is ~2 500 queries plus a full in-memory CSV, synchronously, in one web request.

**Q7. Settings/configuration: enumerate what a tenant admin can change today, and where it is stored.**
See §5.1 for the full enumeration. In summary, a tenant admin can change:

- **30 of 36 statutory/policy keys** → `statutory_settings.value`, one JSON blob per tenant, self-created table, **no audit, no effective dating, no upper bounds**. Six further keys exist in code and **cannot be saved at all**.
- **Per-company SMTP** (7 fields) → `app_settings.ckey='mail'`, JSON map keyed by company id with `'0'` as the tenant default. **Password in plaintext.** No audit.
- **Per-company branding** (display name, colour, logo, tagline) → `app_settings.ckey='branding'`. No audit.
- **The tenant's app/PWA logo** → a file at `public/images/tenants/{tid}/applogo.png`.
- **The active financial year** → `settings.key='fy_active'`.
- **Everything in the 58 master screens** — including what a Product Owner would call configuration: the holiday calendar, leave types, shifts, late policy, salary components, pay cycles, incentive schemes, points rules, minimum wages, the policy repository, WhatsApp/SMS provider credentials and templates, biometric devices, letter templates, feature flags, and the company record's grievance-officer details.
- **Knowledge-base articles** → `kb_topics`, **installation-wide, not per tenant**.
- *(Super admin only)* the landing-page CMS → `settings.key='landing'`, and each tenant's `doc_storage_emp_mb`.

Not changeable by a tenant admin: anything in `config/` or `.env` (edition, licence, timezone, session, permission cache), the report definitions, the dashboard cards, the screen help, the document layouts, the letter type list, the placeholder list, the pipeline stages, and the master registry.

**Q8. Performance and points: what exists, configurable, used downstream?**
**Performance:** a four-state relay (`draft → self_done → reviewed → finalized`) over `performance_reviews`, with per-stage rating and comments and a free-text goals field. **Not configurable in any respect** — no rating scale (rating is unbounded `numeric`), no cycle definition, no competencies, no weighting, no calibration, no ordering enforcement. **The employee cannot participate**: both endpoints are HR-guarded, so HR types the "self-review" in. **Nothing downstream consumes `final_rating`** — not payroll, increments, incentives or compliance. `advance()` is not tenant-scoped (cross-tenant IDOR).

**Points:** rules (`point_rules`: event name, category, points, note) and a ledger (`points_ledger`). Rules are **partly configurable** — a customer sets the event name, the category and the point value — but the **trigger events are code**: five keyword buckets matched by `str_contains` on the rule's name, in a first-match-wins order that mis-classifies obvious rule names. Only attendance, awards and test passes can ever award points. The "late" definition is a hard-coded 09:30 that ignores the tenant's Shifts, Late Policy and roster; the working-day count assumes Sunday-only weekly offs, ignoring `weekly_off_day` and `sat_off_mode`.

**Downstream:** two consumers only — the leaderboard (`ComputedController` `points-scores`) and the incentive **eligibility gate** (`IncentiveController.php:199-212`), which uses the month's point sum against `points_gate_min` (default 0 = off) to decide whether an agent is *paid at all*. **Points never convert to money.** Note that the gate filters on `points_ledger.date`, which auto-apply stamps with the **run date** — so December's points computed in January never count towards December's eligibility.

**Q9. Screen help and industry seeding — what they are, product content or customer-editable, do they belong in SmartPRS 3?**

**Screen help** is 2 206 lines / ~236 KB of product training and sales copy — 120 screen entries, 119 "why it matters" arguments, 73 "common mistakes" lists — held as PHP array literals in a controller with one public method and no data access of any kind. It is **product content, not customer content**: there is no table, no editor, no write route, no tenant scoping and no edition awareness (an on-prem L1 client is served help for SaaS-only screens they cannot reach). It is re-materialised on every request with no cache, the alias table is duplicated in the same method, the fallback ignores its own parameter, and there is a live key collision that silently kills one entry.

*Does it belong in SmartPRS 3?* **The mechanism does not; the content emphatically does.** In several places this copy is the clearest existing statement of intended behaviour in the entire codebase — the *Roles & Permissions* entry documents the deliberate fail-open permission posture, the *Shifts* entry documents the 24-hour time requirement, and the *Commissions* mistakes list documents the exact confusion that causes ₹42 000 payouts instead of ₹840. It must be **extracted into a versioned, screen-keyed content store** that is vendor-authored, tenant-overridable, edition-filtered and cached — not deleted with the controller.

**Industry seeding** is a hard-coded India-specific Collections & Recovery content pack: 12 training programmes with four-paragraph objectives, 25 training subjects with genuine multi-paragraph course material, 17 FAQs, 16 Knowledge-Base articles (four of them the Code of Conduct agents legally acknowledge), and one offer-letter template. It is **product content installed as customer-editable rows** — idempotent by natural key, back-filling an empty description without overwriting an edit.

*Does it belong in SmartPRS 3?* **Yes as an asset, no as a mechanism.** The content is a real commercial differentiator and is grounded in the RBI Fair Practices Code, IIBF DRA norms, the DPDP Act and the legal toolkit. But the delivery is wrong in three ways: (a) it is **one vertical, hard-coded** — a customer outside collections gets it anyway and cannot supply their own; (b) `kb_topics` is written **globally with `tenant_id = NULL` and deduplicated by title across the entire installation**, so one tenant's edit changes the Code of Conduct for every tenant on the box; (c) it performs **schema self-healing** (`SHOW COLUMNS`, forced `->change()`) as part of content installation. SmartPRS 3 needs a **versioned, per-vertical, per-tenant content-pack mechanism** with install / update / fork semantics and a clear original-vs-edited state.

### Decisions a human must make

**PO-01 · Was `letters-nda` ever functional, or was the backend never written?** The SPA screen is complete and references a working backend; `NdaSignController`, the compliance NDA check and the RBI audit story all depend on NDA letters existing. `git` history is not in this snapshot. *Decision needed:* whether existing installs have NDA rows (created by some removed path or by direct SQL) and therefore whether any current compliance score is based on a real signature. **Until this is answered, every NDA component of every compliance score should be treated as unearned.**

**PO-02 · Are the existing token-based acceptances and signatures legally relied upon?** If any offer acceptance or NDA signature has been used in a dispute, an audit or a bank submission, the evidential gaps (no document shown, no identity proof, mutable document, no audit entry) are a live commercial risk, not just a design defect. *Decision needed:* whether to re-paper existing signatures on the new platform.

**PO-03 · Should off-roll agents consume a seat, and should they be compliance-tracked?** Today they consume no seat, get no onboarding, and are invisible to compliance — while being exactly the population RBI recovery-agent norms target. This is simultaneously a compliance hole and a revenue leak. *Decision needed:* the commercial and compliance treatment of off-roll workers in SmartPRS 3.

**PO-04 · What is the authoritative person identifier?** Hire, onboarding and offer acceptance all key on name. *Decision needed:* the identity key (PAN? UAN? email? mobile? an internal person id?) and the merge/duplicate-resolution policy, before any migration.

**PO-05 · What happens to KB articles that a tenant has edited?** Because `kb_topics` is global, an edit by tenant A changed the article for every tenant. Splitting the table into platform content and per-tenant copies requires a **per-article human decision** on each edited row: whose edit is it, and who else inherited it. This must be done before migration, not after.

**PO-06 · Which of the six unreachable settings are actually in use as defaults?** `contact_window_start`/`_end` (08:00–19:00) merge into every letter as `{{contact_hours}}` and are cited across the training content as the RBI rule; `data_retention_months` (84) is a DPDP retention period. They have been pinned at code defaults since they were written. *Decision needed:* confirm the defaults are correct as shipped, and whether any customer has been told they are configurable.

**PO-07 · What is the intended rating scale for performance?** There is none in code. *Decision needed:* scale, whether it is per-tenant, and what it must drive (increments, incentives, promotions) — requirement 42 and the whole performance verdict depend on it.

**PO-08 · Should points ever convert to money?** Today they are strictly a scoreboard plus an eligibility gate. The design pressure to make them a payout is obvious. *Decision needed:* explicitly yes or no, because it changes whether the points engine is subject to payroll-grade controls (approval, audit, immutability, recomputation).

**PO-09 · Which vertical content packs will SmartPRS 3 ship?** The current pack is India collections & recovery. *Decision needed:* whether that stays the only pack, whether customers can author packs, and whether pack updates may overwrite customer edits.

**PO-10 · Who owns the marketing surface?** `LandingController`, `LeadController` and `PolicyController` are the SaaS business's website, lead capture and legal pages living inside the HR product. *Decision needed:* whether they move out of the product entirely in SmartPRS 3.

### Undetermined from source

**UD-01 · dompdf configuration.** There is **no `config/dompdf.php`** in the repository, so `isRemoteEnabled`, `chroot`, `allowedProtocols` and `enable_php` are package defaults and cannot be established. Required to size FINDING-SEC-06 (unescaped template body rendered into the PDF).

**UD-02 · The production `letters` schema.** The migration creates 9 columns; at least 12 more are added at runtime by four different classes. The effective column list, nullability and indexing on any given install depend on which screens have been opened. Required: a production `SHOW CREATE TABLE letters`.

**UD-03 · `SubscriptionService::canAddEmployees` semantics** — what counts towards a seat, and whether off-roll agents ought to (they currently do not). Not inspected.

**UD-04 · `WaService::sendTemplate` return contract** — whether it returns or logs a provider message id, which would let `wa_message_id` be populated and fix the webhook matching. Not inspected.

**UD-05 · `App\Services\Audit` chain semantics** — whether a direct insert into `activity_logs` (as `ReportController::export` and `MasterController::draGateWarning` both perform) breaks chain verification from that row onward or is merely reported as `unsigned`. This determines whether the `audit-trail` report's own export action corrupts the thing it reports on. Not inspected.

**UD-06 · Scheduler and queue presence on client installs.** `MailService::queue()` dispatches to a queue; offer and NDA links, letter emails and lead alerts all go through it. If no worker is running, links are minted and never delivered — and the failure is silent. Required: deployment runbook confirmation.


---

# SOURCE: I-saas-licensing-deployment.md

## 11. Open questions / PRODUCT OWNER DECISION REQUIRED

| # | Question | Why it must be answered | What is known | What is needed |
|---|---|---|---|---|
| **Q-01** | **Do editions exist in SmartPRS 3 at all?** | SmartPRS 2 sells three price points and delivers one product (DEF-02). Either the entitlement engine is built and every existing L1/L2 customer must be told their product is changing, or the tiers are abandoned and pricing is restated. **This blocks the entitlement model, the licence payload and the pricing model.** | `Edition::level()` returns 3; the DNA/L2 constants survive *"for reference / easy revert"* | **PRODUCT OWNER DECISION REQUIRED** |
| **Q-02** | **What did existing L1/L2 customers actually buy?** | If entitlements are enforced in v3, customers currently receiving everything will lose function. Is that a downgrade, a grandfathered grant, or an upsell conversation? | `onprem_clients.edition` records what was sold | **PRODUCT OWNER + LEGAL DECISION REQUIRED** |
| **Q-03** | **Is the shipped `.lic` key pair a development pair?** | If yes, every perpetual licence is forgeable the moment the private key leaks, and rotation is required before any v3 licence is issued — and rotation invalidates every existing `.lic`. | The reference docs assert it from a go-live checklist **not present in this extract** (DISC-02) | **VERIFY against the real repository, then decide the rotation window** |
| **Q-04** | **Was `SMARTPRS_LICENCE_SECRET` ever shipped to customers, and to how many?** | It determines whether a rotation and re-issue exercise is needed at all, or only a code deletion. | The design requires it (`config/smartprs.php:56-67`); the value is reported at two `PKG:` paths **not in this extract** (DISC-01) | **VERIFY, then scope the rotation** |
| **Q-05** | **Does `guard-runtime.php` exist in delivered client builds, and what does it do?** | The reference docs describe a **second, parallel licensing system** running before every request via `router.php`, checking an HMAC-signed `storage/licence.lic` plus a MAC lock — with the README and the build script disagreeing on whether `SMARTPRS_LICENCE_ENFORCE` ends up true or false. If it exists, there are two licence systems in production that may contradict each other. | Not in this extract | **OBTAIN `client-builder-tools/` and analyse** |
| **Q-06** | **Is `support@ametecsindia.com` a real super-admin on customer servers?** | A permanent vendor super-admin inside every customer's payroll database is a contractual and DPDP-Act exposure, and it is created by a `db:seed --force` the installer runs. | Reported from `PKG:` installer scripts not in this extract; `SuperAdminSeeder.php` in this tree only creates from env | **VERIFY, then decide the access model (R-52)** |
| **Q-07** | **Are production client builds actually SourceGuardian-encoded?** | Without encoding, `LicenseFile::PUBLIC_KEY` is one editable line and the licence system offers essentially no technical protection. The build script reportedly warns and ships readable PHP when the encoder is unset. | `InstallController.php:84-89` checks the loader at **warn** level, so a plain-source install is permitted | **VERIFY build logs; decide whether encoding is mandatory in v3** |
| **Q-08** | **Has a restore ever been performed from a SmartPRS backup?** | Backup exists; restore does not exist as a feature and no drill artefact is in the tree. An untested restore is not a backup. | No restore command, route, UI or runbook anywhere (§10 Q10) | **PRODUCT OWNER DECISION REQUIRED: fund a restore capability and a drill schedule** |
| **Q-09** | **Which on-prem installs currently have a working scheduler?** | Without it, backups, subscription alerts, compliance scans, transfers, probation and absence notifications all stop **silently**, and no Windows scheduler artefact ships. | Reported in the architecture document; no Windows service artefact in this extract | **AUDIT the installed base** |
| **Q-10** | **Should statutory content delivery be free of the AMC gate?** | Today `/update/check` refuses everything when AMC has lapsed, so a lapsed customer cannot receive a PT correction. This is a liability question as much as a commercial one. | `UpdateServerController::check()` `:238-246` | **PRODUCT OWNER + LEGAL DECISION REQUIRED (R-29)** |
| **Q-11** | **Is the archived-employee seat behaviour intended?** | On SaaS, archived people consume seats while being invisible; on-prem they do not. The error message states the on-prem behaviour to both. Whichever is chosen changes what customers are billed. | `SubscriptionService.php:88-97` vs `ClientUpdateController.php:515-520` vs the message at `:141` | **PRODUCT OWNER DECISION REQUIRED** |
| **Q-12** | **Is `runRenewals()` ever run in production?** | If it is, every unpaid tenant has been silently extended and the lock-out has never fired. | Not scheduled by design (`routes/console.php:171-178`), but runnable manually, and the behaviour is asserted by a green test | **AUDIT: check for `subscriptions` whose period was extended without a matching successful payment** |
| **Q-13** | **Are there tenants with no `subscriptions` row?** | `state()` returns `none` for them and they are **never gated and never billed** — a silent revenue leak that a failed provisioning step (DEF-15) creates. | `SubscriptionService.php:53-54` | **AUDIT production data** |
| **Q-14** | **Are there invoices where `SUM(payments) > amount + tax`?** | DEF-12 double-credits any invoice that went through both recorders. This must be reconciled before any migration. | `recordPayment()` vs `applyPayment()` | **AUDIT production data** |
| **Q-15** | **Does Ametecs operate any cloud tenant on an L-edition?** | That configuration is unbillable and unenforceable (DEF-36), and the code comment at `ClientUpdateController.php:225-230` implies it has happened. | Source comment only | **AUDIT the deployment inventory** |
| **Q-16** | **Which copy of `update-status.php` is actually missing?** | It decides whether on-prem update progress reporting works at all. The reference doc and this extract disagree (DISC-04), and the `public/` directory here is trimmed. | `updater/update-status.php` present here; `public/update-status.php` absent here | **VERIFY against the real repository** |
| **Q-17** | **What is the intended on-prem seat model?** | Today the default is unlimited: `seats_licensed = 100000` plus a `.lic` `seats` field defaulting to 0 ⇒ `device_limit = null`. If on-prem is genuinely licensed by user count, the default is wrong in the customer's favour on every install. | `ClientProvision.php:72`, `InstallController.php:198`, `OnpremClientController.php:341` | **PRODUCT OWNER DECISION REQUIRED** |
| **Q-18** | **What is the real target implementation time for a new customer?** | §10 Q7 establishes that automation ends at "tenant + one company + one admin" and everything else is manual. The v3 configuration-template requirement (R-33/R-34) should be sized against a stated target. | Source records no elapsed time | **PRODUCT OWNER MUST STATE THE TARGET** |
| **Q-19** | **Should an on-prem customer be able to see and manage their own backups?** | Today they cannot see anything (§10 Q10). Exposing it is an operations feature; not exposing it makes the vendor responsible for every customer's data recovery. | No backup surface exists on-prem | **PRODUCT OWNER DECISION REQUIRED (R-49)** |
| **Q-20** | **What is the acceptable offline tolerance for a licensed install?** | Revocation, expiry and proof-of-life all depend on it. Today it is effectively infinite for a `.lic` on an isolated machine (DEF-08), and there is no scheduled heartbeat at all. | `revocationSweep()` 24h throttle, login-only | **PRODUCT OWNER DECISION REQUIRED (R-12)** |

---

*End of analysis.*


---

# SOURCE: J-database.md

## 11. Open questions / PRODUCT OWNER DECISION REQUIRED

| # | Question | Why it blocks the blueprint |
|---|---|---|
| **PO-1** | **Is `attendance` (singular) empty on every client install?** It is the only table with a `unique(employee_id, date)` day-level constraint and zero code references. | If any install has rows, SmartPRS 2 has two attendance data sources and the migration must merge them |
| **PO-2** | **`pf_esi_settings`, `tds_rules`, `pt_slabs` vs `statutory_settings`.** Do any installs carry rows in the migrated tables that were never copied into the JSON blob? | If yes, **statutory rates used in past payrolls may differ from what the blob says**, and a payslip cannot be re-derived. This is a compliance exposure, not just a migration question |
| **PO-3** | **Is multi-company employment a SmartPRS 3 requirement?** `employee_company` exists and is never used; `employees.also_works_for` offers "Multiple companies" | Determines whether employment becomes a first-class entity separate from the person |
| **PO-4** | **What do `offroll_agents.dra` and `.pcc` hold** — a status, a certificate number, or an expiry date? Not determinable from source | Blocks the off-roll agent compliance model |
| **PO-5** | **Is the `commissions.amount` = NET convention true for all historical rows?** `ApprovalService.php:101-102` states it, but rows predating rev 83 may hold a gross | Money reconciliation |
| **PO-6** | **Is `payslips.earnings`/`deductions` JSON structurally stable across all revisions?** | Determines whether the explode-to-line-items transformation is a single mechanical pass or a per-revision reverse-engineering exercise. This is the largest single unknown in the migration |
| **PO-7** | **Retention policy per entity class.** How long must attendance punches, payslips, statutory filings, DRA/PCC/BGV records, POSH complaints, documents, candidate PII and audit logs be kept? | Nothing exists today. DPDP Act 2023 requires purpose limitation and storage limitation. Every entity in SmartPRS 3 needs a retention class before the schema is designed |
| **PO-8** | **Right to erasure.** When an ex-employee or a rejected candidate demands deletion, what must be deleted, what must be retained for statutory reasons, and what must be anonymised? | Determines whether SmartPRS 3 needs anonymisation columns, a crypto-shredding key per subject, or hard deletion with a tombstone |
| **PO-9** | **Is a payslip immutable once issued?** SmartPRS 2 deletes and recreates a draft run's payslips | Determines whether payroll needs full versioning. Strongly recommend yes |
| **PO-10** | **Who may reopen a finalised payroll period?** Today: super admin only, via a guard that fails open | Governance decision |
| **PO-11** | **Employee code uniqueness scope** — tenant (today), or per company, or global? | Affects the primary business key of the whole system |
| **PO-12** | **Which employee fields must genuinely be unique?** Today PAN, mobile, email, device ID, UAN, ESIC, National ID and bank account are all "block" — and all are guaranteed to already contain duplicates | Determines the de-duplication effort before cutover |
| **PO-13** | **`settings` platform sentinel: `0` or `NULL`?** Both are in production use | Data merge |
| **PO-14** | **`attendance_pending` split.** On each install, which feature's rows are present, and were any punches lost while the "wrong" schema was in place? | Possible unrecoverable attendance loss |
| **PO-15** | **Does a customer named "demo" exist?** `subdomain='demo'` triggers a product lockdown and makes the tenant a target for `DummyDataSeeder`'s 23-table hard delete | Immediate operational risk in SmartPRS 2, independent of the rebuild |
| **PO-16** | **Coordinate data written before the `DECIMAL(11,7)` repair is permanently truncated to ±557 m.** Should historical geofence verdicts be re-derived, invalidated, or accepted as-is? | Affects any historical attendance dispute |
| **PO-17** | **Which installs have `dob`/`doj`/`dra_expiry`/`pcc_expiry`/`pcc_deadline` as VARCHAR?** Determinable only by introspecting each live database | Sizes the date-parsing work and identifies which clients' compliance alerts have been firing on the wrong people |
| **PO-18** | **Is the `activity_logs` hash chain intact on any install?** The `prev_hash`/`row_hash` columns are added at runtime and never verified on read | Determines whether the existing audit trail has any evidentiary value worth migrating |


---

# SOURCE: K-frontend-spa.md

## 11. Open questions / PRODUCT OWNER DECISION REQUIRED

1. **DECISION REQUIRED — statutory values.** Every constant in §4.1 (PF cap 15000, PF
   rate 12, ESI threshold 21000 / 0.75 / 3.25, PT 200, standard deduction 75000, 87A limit
   1,200,000, cess 4, commission TDS 5, no-PAN 20, bonus 8.33, and the seven TDS bands)
   is *the value the code currently uses*. None has been verified against statute in this
   analysis and none should be carried into SmartPRS 3 without independent verification
   and an effective-date.

2. **DECISION REQUIRED — the `basic = 50% of gross` assumption.** Is this a deliberate
   product rule, a placeholder, or a bug? It determines PF for every employee. If any
   customer has been running payroll on it, the exposure is retrospective.

3. **DECISION REQUIRED — `gross = CTC / 12`.** CTC conventionally includes employer PF and
   gratuity. Dividing CTC by 12 and then computing employer PF on 50% of that figure
   double-counts. Confirm the intended CTC definition.

4. **DECISION REQUIRED — the 87A cliff.** Is marginal relief expected? (§4.2, §6.9)

5. **DECISION REQUIRED — fabricated payroll history.** `ensurePayrollHistory()` has been
   inserting 12 months of payroll runs and payslips into tenant databases on page load
   (§6.3). Which existing tenants have fabricated rows, and what is the remediation?

6. **DECISION REQUIRED — `app_state` migration.** ~60 collections must be reverse-
   engineered from per-tenant JSON blobs into typed tables. This needs a per-tenant
   inventory before scoping. Some tenants' blobs may already be corrupted by last-write-
   wins conflicts, with no way to detect it.

7. **DECISION REQUIRED — the permissions the customers configured.** Customers have been
   configuring roles in a UI whose own footer says enforcement "is the next phase"
   (§7.8). When SmartPRS 3 enforces those matrices, users who work today will stop
   working. Is the saved matrix the intent to honour, or does every tenant need to
   re-do access control?

8. **DECISION REQUIRED — `field_agent` data exposure.** Field Agents currently receive all
   employees' PAN, bank details, DOB, home address and GPS (§7.5). Is this a defect or an
   operational necessity for the field-collections product? It changes the SmartPRS 3
   field-level permission requirement materially.

9. **OPEN — the 58 `custom` screens.** Each is a screen the declarative registry could not
   express. Someone must enumerate what each one does that a `list`/`form` could not, or
   SmartPRS 3's screen engine will be designed with the same gap.

10. **OPEN — is a fully offline (no-CDN) on-prem install a supported configuration?**
    L1/L2/L3 editions currently lose maps, Excel export and payments without internet.

11. **OPEN — `letters-nda`.** Should the NDA letter type exist (add to
    `MasterController::def()`) or be removed from `MASTER_MAP`? (§6.7)

12. **OPEN — the orphan `recruitment` master type** in `MasterController::def()` with no
    client counterpart.

13. **OPEN — mobile.** See §12 below. Is a phone a supported primary device for HR/payroll
    admin work, or only for employee self-service and punch? SmartPRS 2 answers this
    ambiguously and the answer drives a large share of SmartPRS 3's UI budget.

---

## 12. Direct answers to the nine assignment questions

### Q1 — Complete screen registry

**Registry composition:** 114 screens declared in `resources/prototype/app.html` (line
356), plus 32 registered only by the boot script = **146 screen ids**.

Full breakdown by declared type (prototype registry):

| Type | Count | Renderer | Data source |
|---|---|---|---|
| `list` | 71 | `renderList` (prototype) | `DB[screen.coll]`, filtered by `screen.companyKey` against the `COMPANY` global |
| `custom` | 27 | `renderCustom` dispatch | per-screen function |
| `cards` | 6 | `renderCards` | `screen.cards` + `screen.stats` |
| `lettered` | 4 | prototype lettered renderer | `DB.letters`, filtered by `screen.ltype` |
| `form` | 4 | `renderForm` | `screen.fields` |
| `empform` | 1 | employee form renderer | `cfg.empFields` (server registry) |
| `settings` | 1 | settings renderer | `GET /app/settings` |

Registry keys actually used across the 114 entries: `title` (114), `type` (114),
`add` (79), `coll` (75), `cols` (75), `fields` (73), `companyKey` (29), `cards` (6),
`stats` (6), `ltype` (4), `groups` (4), `filterField` (2), `filterOpts` (2), `view` (1),
`msg` (1), `addGoto` (1), `help` (1).

**After boot, 90 of the 114 are overwritten to `{title, type:'custom'}`** — losing
`coll`, `cols`, `fields`, `add` and `companyKey` — at `AppController.php:3188–3253` and
`:2142`.

**The 32 ids that exist only because the boot script registers them:**
`absence-config`, `account-settings`, `att-correction`, `audit-reports`, `bank-pack`,
`biometric-setup`, `bonus`, `calc-logic`, `commission-calc`, `demo-requests`, `dra-certs`,
`emp-archived`, `emp-import`, `fin-year`, `greetings`, `greetings-log`, `letters-nda`,
`min-wages`, `mobile-devices`, `my-subscription`, `notice-board`, `overtime`, `pay-ledger`,
`policies`, `posh`, `probation`, `probation-config`, `shifts`, `statutory-config`,
`sys-updates`, `transfers`, `wa-templates`.

**Permission gate per screen** is not a per-screen property. It is derived from
`PERM_NAV` (`AppController.php:655–672`), which maps **16 module keys** to nav id lists:

| Module key | Nav ids gated |
|---|---|
| `dashboard` | dashboard, platform-dashboard, how-it-works, notifications |
| `employees` | emp-list, emp-archived, emp-add, idcard, teams, onboarding-board, recruitment, bgv, documents, roster, offroll-agents, transfers |
| `attendance` | att-daily, att-report, att-manual, att-zkteco, biometric-devices, biometric-setup, geofence, geofence-list, shifts, late-policy, overtime |
| `leave` | leave-apply, leave-types, holidays |
| `payroll` | pay-cycle, salary-schedules, salary-setup, salary-gen, salary-approval, payslip, deductions, payout-recon, live-salary, pay-ledger, calc-logic |
| `commissions` | commissions, incentive-schemes, clawbacks, bonus-enc, bank-pack |
| `expenses` | expenses, advance |
| `loans` | loans |
| `statutory` | pf-esic, tds, pt, gratuity, bonus, tds-returns, min-wages, audit-reports |
| `performance` | performance, increments, exits, awards, points-scores, points-ledger, points-rules, tests, test-results, test-reports, attrition |
| `field` | escalations, agent-auth, dra-certs, complaints, compliance-alerts |
| `kb` | kb, faqs, training-programs, training-records, training-content, code-of-conduct, policies, helpdesk, posh, letters-offer, letters-increment, letters-warning, letters-relieving, letters-nda, letters-templates, notice, messages, send-message |
| `reports` | reports, activity-logs |
| `assets` | assets |
| `settings` | companies, departments, designations, branches, banks, users, roles, settings, fin-year, my-subscription, branding, company-emails, wa-settings, wa-templates, sms-settings, sms-templates, approvals-inbox, mobile-devices |
| `saas` | tenants, plans, subscriptions, invoices, payments, gateways, feature-flags, demo-requests |

**Screens not covered by any `PERM_NAV` module → ungated even when a matrix exists.**
`navModule()` returns `null`, so `applyPerms()` skips them (`:775–776`) and
`gateActions()` returns early (`:799–800`). Computed exactly: of the **146** registered
ids, `PERM_NAV` covers **120** and leaves **26** ungated:

`absence-config`, `acc-reports`, `account-settings`, `ai-assistant`, `att-correction`,
`cases`, `coa`, `col-dashboard`, `commission-calc`, `emp-import`, `field-agents`,
`greetings`, `greetings-log`, `notice-board`, `portfolio`, `probation`,
`probation-config`, `procReq`, `projects`, `recovery-reports`, `settlement`,
`statutory-config`, `sys-updates`, `tasks`, `vendors`, `vouchers`.

Note that this list includes **`statutory-config`** (edits statutory rates),
**`account-settings`**, **`sys-updates`** (licence and updates), **`coa`** /
**`vouchers`** / **`settlement`** / **`recovery-reports`** (financial), and
**`col-dashboard`** — i.e. the permission matrix has no opinion at all about the
collections-and-recovery module or about statutory configuration.

The SBB items `api-keys` and `pending-punches` are `addNavLink` anchors to standalone
Blade pages (`AppController.php:3169–3170`), not SPA screens, and are therefore outside
this model entirely.

**Live / dead:**

| Category | Count | Notes |
|---|---|---|
| Registered and reachable from the static sidebar | 107 | prototype `data-id` items |
| Registered, nav injected at runtime | 21 | 11 ad-hoc DOM sites + 10 `addNav`/`addNavLink` |
| Registered, no nav entry at all | ~18 | reachable only via `go('id')` from another screen, or unreachable |
| Confirmed broken | 2 | `letters-nda` (no server type, §6.7); `idcard` empty branch (`ReferenceError`, §6.8) |
| Dead code paths | 1 whole function | `applyPlanGate()` body, `:713–741` |
| Entire dead front end | 17 Blade views + layout | §6.1 |

### Q2 — Business rules implemented only in browser JavaScript

Recorded in full in §3 (46 rules) and §4 (formulas). The money/statutory subset, which is
the critical answer:

| # | Rule | Server equivalent? |
|---|---|---|
| 1 | Income-tax slab computation + 87A + cess (`tdsTax`, `:11167`) | Server has `SettingsController::defaults()` values but the **slab walk with the 87A cliff is client-only in this screen** |
| 2 | PF wage = min(50% of CTC/12, cap) (`:12080`) | **No client-server parity** — this exact derivation is client-only |
| 3 | ESI eligibility on monthly gross (`:12083`) | Client-only in this screen |
| 4 | Employer PF = employee PF (`:12082`) | Client-only |
| 5 | Commission share defaults to 30% (`:12098`) | **Client-only literal** |
| 6 | No-PAN TDS via `Math.max` (`:12106`) | Client-only in this screen |
| 7 | Monthly TDS = annual / 12 (`:12107`) | Client-only |
| 8 | Commission request net-of-TDS with 5% fallback (`:4161`) | Client-only |
| 9 | Incentive scheme base × rate / 100 (`:4654`) | Client-only |
| 10 | Increment ↔ percentage cross-calc (`:5315`) | Client-only |
| 11 | GST CGST/SGST vs IGST split (`:7200`) | **Client-only** — the invoice total the customer sees is computed in the browser |
| 12 | Statutory bonus 8.33% default (`:11164`) | Client fallback |
| 13 | Leave balance minus pending | Client-only presentation |
| 14 | Ledger running balance | Client-only |
| 15 | The whole `spRates()` default table (17 values + 7 bands) | **Duplicated** — the server has its own defaults in `SettingsController::defaults()` |

**Divergence — CRITICAL FINDING:** items 2–7 and 11 have **no server-side counterpart at
all**, so there is nothing to diverge *from* — the browser is the system of record for
those figures. Item 15 is a genuine duplication: two independent default tables, one in
`SettingsController::defaults()` (PHP) and one in `spRates()` (JS, `:11160–11166`). If a
value is changed in one and not the other, the PF/ESI/TDS screens will show one number
and the payroll run will produce another, with no error and no reconciliation.

### Q3 — Rendering model, heredoc usage, escaping hazards

**Rendering model.** Every screen renderer returns an **HTML string**. `render()` in the
prototype does:
```js
const host = document.getElementById('host');
const s = SCREENS[CURRENT];
… host.innerHTML = renderCustom(CURRENT); …
```
There is no virtual DOM, no templating engine, no `createElement` for content, no data
binding. State changes are effected by re-running the whole renderer and replacing
`innerHTML`. Event handling is inline `onclick="…"` attributes constructed as strings.

**Heredoc usage.** Two heredocs (`<<<CSS` at :235, `<<<HTML` at :452), both interpolating,
together 12,273 lines. Constraints imposed: no `$` in the JS body, no `${}`, doubled
backslashes. Interpolation surface: exactly one — `{$cfg}` at :453.

**Is server data ever injected into `innerHTML` unescaped? — YES. Count and list:**

| Site | Count | Escaping |
|---|---|---|
| `innerHTML =` in `AppController.php` boot JS | **142** | mixed; see below |
| `innerHTML=` in `resources/prototype/app.html` | **13** | **none — the prototype has zero escape functions** |
| Renderer functions returning concatenated HTML (`return pghead(`) | **134** | mixed |
| Escape helper definitions | **19** | 4 different, all incomplete escape sets |
| Escape helpers that handle `'` | **0** | — |
| Escape helpers that handle only `"` | **4** (`:4938, 6097, 9156, 9882`) | attribute-only, unsafe for text |
| Escape helpers that handle `& < >` but not `"` | **4** (`:5170, 8158, 8737, 9323, 9778`) | text-only, unsafe in attributes |
| Server-side sanitisation (`stripHtmlDeep`) call sites | **4** (`RecruitmentController.php:352`, `MasterController.php:723`, `AppDataController.php:666, 2740`) | does not cover `POST /app/state`, ESS self-update, leave, helpdesk, expenses |

The **generic list path is entirely unescaped**: `cell(row, col)` → `renderList` →
`host.innerHTML`. That path renders all 71 `list`-type screens (as declared) and is the
fallback for anything not overridden. Employee names, addresses, remarks, master values,
helpdesk and complaint text all flow through it. §7.3 gives the exploitation path.

### Q4 — Permission enforcement, UI vs server

**Quantified: 100% of the SPA's access control is UI-only.**

- Screens protected by server-side authorisation at the `/app/{screen}` route: **0 of 146**.
  `routes/web.php` applies `auth`, `LicenseGate`, `EnsureSubscriptionActive`,
  `DemoWriteGuard`, `EditionGuard` — **no role middleware**.
- Mechanisms that produce a `display:none` and nothing else: **5**
  (`roleAllows`, `applyPerms`, `gateActions`, `wireDemoLockdown`, `wireEditionLockdown`).
- Mechanisms that influence a real decision: **1** — the saved-screen restore at `:596–610`,
  and it decides by reading `offsetParent`/`style.display` off the DOM.
- Fail-open defaults on the access-control path: **4** — unknown role → Admin (`:93`);
  no matrix → allow (`:760`); Super Admin/Admin bypass (`:769`); a thrown exception inside
  `safe(function(){ applyPerms(cfg); })` (`:590`) leaves the session ungated.
- Dead gates: **1** — `applyPlanGate()` (`:712`).

Server-side authorisation exists **per API controller and is inconsistent**:
`MasterController::save()` :703 checks `canManage()`; `MasterController::list()` :608–700
checks **nothing** for 58 of 60 types; `SettingsController::save()` does
`abort_unless(hasAnyRole(['super_admin','admin']), 403)`; `SettingsController::index()` is
an open read; `AppStateController::save()` checks nothing; `TestController::take()` :215
does not even check tenancy.

### Q5 — Bootstrap payload: every field, and over-sharing

`window.__SMARTPRS`, built at `AppController.php:101–229`, encoded with
`JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP`.

| Group | Fields | Over-sharing assessment |
|---|---|---|
| Identity | `role`, `user`, `userEmail`, `company`, `portalCompany` | `userEmail` is unnecessary for rendering; it is used only for display |
| Security token | `csrf` | Standard, but see §7.4 |
| Entitlement | `planModules`, `edition`, `editionLevel`, `editionHide`, `isDemo`, `demoTeam`, `demoExp`, `demoBack`, `subState`, `subEnd`, `subDays`, `appVersion` | **`editionHide` publishes exactly which features the customer has not bought, by nav id.** `planModules` likewise. Both are gating hints that the client does not enforce (§6.11), so they leak commercial configuration for no functional benefit |
| Branding | `appLogo` | fine |
| Form metadata | `empFields` (= `EmployeeFieldRules::formPanels()`) | Legitimate, and the closest thing in SmartPRS 2 to configuration-driven UI |
| Navigation state | `screen`, `tour` | fine |
| **Endpoint catalogue** | **~70 URL keys** — `logoutUrl`, `dataUrl`, `kbUrl`, `attReportUrl`, `attLogsUrl`, `attRatingUrl`, `attPdfUrl`, `settingsUrl`, `settingsSaveUrl`, `kbReorderUrl`, `punchUrl`, `punchStatusUrl`, `mailUrl`, `mailSaveUrl`, `mailTestUrl`, `brandingUrl`, `brandingSaveUrl`, `brandingLogoUrl`, `appLogoUrl`, `empImportUrl`, `empTemplateUrl`, `empExportUrl`, `stateUrl`, `stateSaveUrl`, `empStoreUrl`, `leavesUrl`, `leaveApplyUrl`, `leaveBalancesBase`, `leaveDecideUrl`, `approvalsUrl`, `requestsBase`, `schemesBase`, `mobileDevBase`, `masterBase`, `cocUrl`, `bioUrl`, `apiKeysUrl`, `pendingUrl`, `docBase`, `incentiveBase`, `finYearBase`, `mySubBase`, `transfersBase`, `incrementsBase`, `liveSalaryUrl`, `liveSalaryAllUrl`, `attBulkBase`, `empBulkDeleteUrl`, `salaryRunsUrl`, `salaryRunsBase`, `payslipBase`, `salaryLinesBase`, `complianceUrl`, `complianceRunUrl`, `payrollPreviewUrl`, `salarySimUrl`, `bankPackBase`, `calcGuideUrl`, `payrollGenUrl`, `reportsPreviewUrl`, `reportsExportUrl`, `statutoryReportBase`, `lettersBase`, `computedBase`, `idcardBase`, `sendMessageUrl`, `pointsAutoApplyUrl`, `testsBase`, `onboardingBase`, `performanceBase`, `myPhotoUrl`, `essMeUrl`, `essUpdateUrl`, `deviceBase`, `recruitmentBase`, `offrollAgentBase`, `waTplBase`, `helpBase`, `billingBase`, `dashboardStatsUrl`, `searchUrl`, `usersUrl`, `usersBase`, `changePasswordUrl`, `saasTenantsUrl`, `demoReqUrl`, `saasPlansUrl`, `saasPlanDeleteUrl`, `updatesBase` | **The complete API surface of the product is enumerated to every logged-in user, including `saasTenantsUrl`, `saasPlansUrl`, `saasPlanDeleteUrl` and `billingBase`, which only a Super Admin can use.** An Employee's browser is handed the platform-administration endpoints. This is the reconnaissance half of §7.1. |

**Actual API call inventory:** `fetch(` appears **187 times** in the boot script and **0
times** in the prototype (the prototype is entirely offline/demo-data). The most-used
endpoints are `requestsBase` (17 call sites), `recruitmentBase` (13), `masterBase` (10),
`bioUrl` (8), `waTplBase` (7), then `testsBase`/`schemesBase`/`offrollAgentBase`/
`docBase`/`attBulkBase` (6 each). There is no shared HTTP client, no retry policy, no
central error handling, no loading-state abstraction, and no `AbortController` usage — 187
independently written `fetch` calls.

### Q6 — Client-side state and `app_state`

Three distinct stores:

| Store | Contents | Persistence | Blast radius |
|---|---|---|---|
| `DB` (JS object) | ~60 collections: roles+perms, leave types, holidays, letters, templates, points rules, KB, incentive schemes, assets, notices, policies, awards, complaints, escalations, clawbacks, deductions, salary setup, pay cycles, … plus `employees`, `companies`, `branchesC`, `departments`, `designations`, `teams`, `shifts`, `salarySchedules`, `tenants`, `payrollRuns`, `archivedEmployees`, `tdsReturns` overlaid from the server (`:492–509`) | `POST /app/state/save` → `settings.app_state` (whole blob) | **Total** — see §2.1 |
| `window.__XXX` globals (~40) | `__RATES`, `__PAYSLIPS`, `__BRANDMAP`, `__KB`, `__SMARTPRS`, `spPerms`, `_role`, `COMPANY`, `CURRENT`, screen-specific caches | in-memory only | Lost on reload; `__RATES` loss silently reverts statutory figures to `spRates()` literals (`:512`) |
| `localStorage` / `sessionStorage` | `sp_screen` (`:599, 605, 643, 8711`), `sp_navhide` (`:249, 887, 893`), `sp_tour_mute` (sessionStorage) | per browser | Trivial |

**What is persisted server-side:** everything in `DB` except `employees`, which
`AppStateController::show()` strips on read and `boot()` deletes on load (`:480`) so
prototype sample rows can never resurface.

**Seeding:** if `GET /app/state` returns no state, `seedState = true` (`:482`) and
`syncState(cfg)` is called at `:532` — **the prototype's demo data is written into the
tenant's `settings.app_state` on first login**. A new tenant's leave types, holidays,
letters and roles are therefore whatever the prototype's sample data contained.

**If corrupted or overwritten:** the tenant loses all ~60 collections at once. There is no
version, no history, no undo, no schema to validate against on read, and any authenticated
user can trigger it with one request (§7.2). Because `DB.roles[].perms` lives here, a
corrupted blob is simultaneously a data-loss event and an access-control event.

### Q7 — One front end or two? What is dead?

**Two front ends exist in the tree. One of them is entirely dead.**

| | Live | Dead |
|---|---|---|
| Entry point | `AppController::show()` → `resources/prototype/app.html` + 12,273 injected lines | `resources/views/layouts/app.blade.php` |
| Views | 1 (the prototype) | 17 Blade views extending the layout |
| CSS | heredoc at `AppController.php:235–447` + the prototype's own `<style>` | `public/css/smartprs.css` (353 lines) |
| Design tokens | duplicated in both | `resources/css/smartprs-theme.css` (36 lines) — imported by nothing |
| Routes | `/app/{screen?}` | `route('dashboard')`, `route('employees.index')`, `route('departments.index')`, `route('attendance.index')`, `route('leave.index')`, `route('salary-runs.index')`, `route('payslips.index')`, `route('loans.index')`, `route('devices.index')`, `route('tenants.index')` — **none exist in `routes/web.php`** |
| Font Awesome | 6.5.0 | 6.5.1 |

Rendering any of the 17 Blade views throws `RouteNotFoundException`. The legacy paths that
would have reached them are redirected away: `routes/web.php` has
`Route::get('/'.$legacy, fn () => redirect('/app'))` for each.

**Other dead code inside the live front end:**
- `applyPlanGate()` body, `AppController.php:713–741` (29 unreachable lines after an
  unconditional `return`).
- The prototype's top-bar upload/download/reset/CSV buttons, deliberately removed at
  runtime by a Font-Awesome-class regex (`:626`).
- The prototype's `expCsv()` 17-column export, overridden at `:555–561`.
- The prototype's demo login screen, hidden by the injection at `:231`.
- `resources/css/smartprs-theme.css` — "Import alongside Tailwind"; Tailwind is not in the
  tree.

### Q8 — Responsiveness, accessibility and mobile behaviour (factually)

**Responsiveness — implemented, and reasonably thorough.**
The CSS heredoc (`AppController.php:235–447`, 213 lines) contains **12 `@media` blocks**:
`(min-width:901px)`, `(max-width:1350px) and (min-width:901px)`,
`(max-width:1120px) and (min-width:901px)`, `(max-width:900px)` ×4, `(max-width:600px)`,
`(max-width:560px)`, `(max-width:430px)`, `(prefers-reduced-motion: no-preference)` and
`print`. The prototype's own `<style>` adds only 2 (`max-width:860px`, `max-width:1200px`)
— so essentially all responsive behaviour was retrofitted by the server injection.
Concretely:
- Sidebar collapses and becomes an overlay below 900px; `wireResponsive()` (`:582`) wires
  the toggle and `localStorage.sp_navhide` remembers the state.
- `table.mc-table` **card-stacking**: below the small breakpoints, table rows re-flow into
  stacked label/value cards. This is a real mobile-table solution, not `overflow:auto`.
- A themed prompt (`.sp-prompt-*`) replaces `window.prompt` on small screens.
- `wireMobilePageHelp()` (`:580`) mirrors the ⓘ help icon into the page heading on phones,
  because the top-bar icon is not reachable there.
- `declutterTopbar()` (`:616–637`) removes dead top-bar icons, explicitly annotated
  "Mobile-safe".

**But:** the responsive CSS applies to the *prototype's* class names. The ~90 screens
rebuilt as `custom` by the boot script emit their own markup; `wireRoadmapScreens()`
injects a **separate** stylesheet scoped to `.rm-screen` (`:2118–2130`) that styles
cards, inputs, buttons and tables — **and that stylesheet contains no media queries at
all**. So the newest screens (Attendance Correction, Probation, Greetings, Import
Employees, Absence Alerts, Statutory Configuration, and the roadmap set) inherit the
prototype's table card-stacking only where they happen to use `mc-table`.

**Accessibility — effectively absent. Factually:**
- Every screen is rendered by `innerHTML` replacement with **no focus management**. After
  `go()`, focus stays wherever it was (or is lost to `<body>`); there is no
  `aria-live` region and no announcement that the page changed. A screen-reader user is
  never told they navigated.
- Interaction is via inline `onclick` on `<div>` elements (`d.className = 'nav-item'`,
  `d.setAttribute('onclick', "go('" + id + "',this)")` — `:3138–3141`). These are not
  focusable, have no `role`, no `tabindex` and no keyboard activation. **The sidebar
  cannot be operated by keyboard.**
- **Measured ARIA usage across the entire front end: `aria-*` attributes = 0** (0 in
  `AppController.php`, 0 in `app.html`). `role="..."` = **1** in total
  (`role="dialog"` on the themed prompt, `AppController.php:3825`). `tabindex` = **1**
  in total (`tabindex="-1"` on a read-only amount input, `:4121`). `alt=` in the
  prototype = **0**.
- Modals are `<div>`s shown by `style.display`; there is no focus trap, no `Escape`
  handler convention, and no return-focus.
- Icons are Font Awesome `<i>` elements with no accessible name; several buttons are
  icon-only.
- `textContent` is used 46 times against 142 `innerHTML` assignments, so most text is
  inside constructed markup rather than semantic elements.
- Colour is used as the sole status indicator throughout (`--green/--amber/--red/--blue`
  status pills).
- There is no skip link, no landmark structure beyond the prototype's initial markup, and
  no documented contrast validation.

- One positive: `@media (prefers-reduced-motion: no-preference)` is honoured, so
  animations are opt-in by OS setting.

**Mobile behaviour beyond CSS:**
- The full ~1.2 MB uncacheable document is downloaded on every entry (§6.13).
- Geolocation punch and Leaflet maps require network access to unpkg.
- No touch-specific interactions, no swipe, no pull-to-refresh, no offline capability, no
  service worker, no PWA manifest.
- Excel export requires loading SheetJS from cdnjs at the moment of export.

**Verdict:** the product is *responsive* (layout adapts) but not *mobile-ready*
(payload, accessibility, offline, keyboard) and not accessible by any standard.

### Q9 — Maintainability: cost to change one screen today

**To change an existing screen** you must determine, in order:
1. Whether the screen is declared in `resources/prototype/app.html` line 356 (a single
   70,853-character line) — editing it means editing a minified-looking JSON literal
   inside a 234 KB HTML file.
2. Whether the boot script overwrites that declaration at `AppController.php:3188–3253`
   or `:2142` — for **90 of the 114** prototype screens it does, so step 1 is often moot.
3. Which of the two independent `renderCustom` dispatch chains handles it (the 58-branch
   chain installed at `:3126`, or the 10-branch roadmap chain).
4. Which of the 19 escape helpers is in scope at that point in the file, and which
   characters it actually escapes.
5. Whether any of the wrapper sentinels (`__wrapped`, `__rmWrapped`, `__permWrapped`,
   `__memWrapped`, `__helpWrapped`, `__stateWrapped`, `__wired`, `__brandWrapped`) affect
   your function, and in what order the wrappers were installed by the 33 `safe()` calls.
6. Whether the change requires a nav item, and if so which of the 21 DOM-injection sites
   to add it to and which `afterIds` anchor will actually match.
7. Whether the screen needs a `PERM_NAV` entry (`:655–672`) — if you forget, the screen is
   silently ungated.
8. Whether the screen has a help entry among the 801 in `ScreenHelpController.php`.
9. Whether `MASTER_MAP` (`:10756–10892`) and `MasterController::def()` both need the type.
10. That every `$` you write is escaped for the PHP heredoc and every `\` is doubled.

**There is no test of any kind for any of this.** A mistake surfaces as a silently
swallowed exception (`safe()`, `:530`) that leaves the feature missing with no error.

**Realistic cost:** adding one list screen with a master type, nav entry, permissions and
help touches **6 files and at least 8 distinct locations**, three of which are inside a
12,000-line PHP heredoc, and cannot be verified except by manual click-through as five
different roles. Changing a statutory constant means changing it in **two** places
(`SettingsController::defaults()` and `spRates()` at `:11160`) or accepting divergence.

**Implication for SmartPRS 3's configuration-driven UI:**
The 58 `custom` screens are the specification. Each one exists because someone needed
something the declarative `SCREENS` model could not express — a computed column, a
cross-collection join, a chart, a wizard, a bulk action, a nested list, a calculated
footer. SmartPRS 3's screen engine will regress to exactly the same escape hatch unless
those 58 are enumerated and each one's missing capability is added to the engine's
requirements. **The single highest-value follow-up task in this area is to classify all
58 `custom` renderers by the capability they needed.**

The positive precedents worth carrying forward are two: `cfg.empFields`
(`EmployeeFieldRules::formPanels()`) — a server-owned field registry that already drives
five separate consumers (the form's five tabs, the import template, the accepted import
headers, the export, and the sample file) from one definition, with the code comment at
`:127–136` explicitly recording the bugs that existed while those five lists were
maintained separately; and the `SCREENS` registry itself, which is the right idea executed
as a literal.


---

# SOURCE: L-background-ops-observability.md

## 11. Open questions / PRODUCT OWNER DECISION REQUIRED

| ID | Question | Why it matters | What is needed to answer it |
|---|---|---|---|
| **OQ-L-01** | **DECISION REQUIRED — Is `demo:reset` present, and scheduled, on customer on-premise servers today?** The 03:30 entry is unconditional and `routes/console.php` ships in every edition (there is no per-edition schedule file). If any customer install runs the scheduler, SEC-L-01 applies to their server: an active `admin`-role account with the password `smartprs-demo`, re-created nightly | This is the difference between a HIGH internal risk and a **CRITICAL disclosed vulnerability across the installed base** | Confirmation from operations for every live deployment, SaaS and on-prem. **This is the single most urgent question in this document** |
| **OQ-L-02** | **DECISION REQUIRED — On how many live installs are the cron entry and the queue worker actually running?** The rev 170 comment at `MailService.php:102-106` is direct in-code evidence that at least one production install ran with no worker and locked a paying client out of their workspace | Determines whether the failures in §Q9 are theoretical or already happening in the field. If the worker is missing anywhere, tenants are being auto-suspended having received no warning emails, and `subscription_alerts` is recording those emails as `sent` | An inventory of live deployments with `crontab -l` and worker status for each |
| **OQ-L-03** | What is the **actual backup retention** in force? `config/backup.php:88-92` defers to the package default, and `vendor/` is not in this tree | Cannot answer "how far back can we recover?" — a question a customer will ask during an incident | `php artisan config:show backup.cleanup` on a live server, or the contents of `vendor/spatie/laravel-backup/config/backup.php` |
| **OQ-L-04** | **Are backup failures emailed to anyone?** `config/backup.php` omits `notifications`; `notifications.mail.to` is set nowhere; `.env.production.example:62-63` describes `SMARTPRS_OPS_EMAIL` as the *"backup-failure + daily error-digest recipient"* but that variable is read only by `ErrorDigest.php:25` | If the answer is no, backups have been failing silently for an unknown period on every install | The package's default `notifications` block plus confirmation of `mail.from` on a live server |
| **OQ-L-05** | **Does any live feature persist meaningful state under `settings.key = 'app_state'`?** | Determines whether `app:clear-state`'s unguarded cross-tenant `DELETE` (SEC-L-10) is theoretical or destructive | An exhaustive grep for `'app_state'` across the application and front-end bundle, plus a sample of the column's contents on a live database |
| **OQ-L-06** | **DECISION REQUIRED — Should the probation "overdue nudge" fire once or repeatedly?** The config key is `overdue_nudge` (singular) and the dedupe base `probation:{empId}:{end}:overdue` makes it fire exactly once, ever. A probation left unconfirmed for six months is nudged one time and then goes quiet | Product owner must state the intended behaviour before it is rebuilt | Product decision |
| **OQ-L-07** | **DECISION REQUIRED — Should a `cancelled` subscription receive daily grace-period "please renew" emails?** The pre-expiry branch checks `status === 'active'`; the grace branch does not (`SubscriptionAlerts.php:56-59`) | Currently a customer who cancelled deliberately is chased daily for seven days | Product decision |
| **OQ-L-08** | **DECISION REQUIRED — Should auto-suspend exist at all, and should it be per-plan or per-contract?** `SubscriptionAlerts.php:60-69` writes `tenants.status='suspended'` unconditionally with no dry-run and no confirmation. If `current_period_end` were ever corrupted, this would suspend every paying customer on its next run | An enterprise contract may forbid automatic suspension entirely | Product / commercial decision |
| **OQ-L-09** | **DECISION REQUIRED — What is the acceptable data-loss window for biometric attendance?** The current answer, imposed by `--days=1`, is "24 hours, then permanent loss". A watermark design would make it "as long as the upstream device retains punches" | Determines the ingestion design in SmartPRS 3 and whether a local punch cache is required | Product decision, informed by device/cloud retention periods per provider |
| **OQ-L-10** | Does `ApprovalService::safeRow()` silently **drop** unknown columns, and does it fill required NOT-NULL columns? It is used on every insert in `ClientProvision.php:66, 87`, `DemoReset.php:91` and `TransferService.php:98, 192` | If it silently drops, a renamed column makes data vanish from an insert with **no error** — across provisioning, demo reset and transfers | Read `app/Http/Controllers/ApprovalService.php → safeRow()` (another analyst's scope) |
| **OQ-L-11** | Under what conditions does `ShiftResolver::resolve()` return an array without an `off` key, and how does `rosterMap()` handle an employee with no roster entry? | Determines whether the Mon–Sat `working_days` fallback or the shift definition governs absence marking — i.e. whether a 5-day-week customer's Saturdays are flagged absent | `app/Services/ShiftResolver.php` (attendance analyst's scope) |
| **OQ-L-12** | Does `php artisan optimize:clear` (a documented deployment step) clear `withoutOverlapping` locks as a side effect? | If yes, it is an accidental recovery for DEF-L-02 and also an accidental way to cause concurrent runs during a deploy | A test against a held schedule lock |
| **OQ-L-13** | **Where do the `deploy/` files live?** They are cited ~40 times by the reference documentation and are absent from this repository | Half the operational analysis in this document is unverifiable without them, and a release artefact that does not contain its own runtime is a structural problem regardless | Locate the files; then decide whether they belong in the application repository (recommendation: yes) |
| **OQ-L-14** | **DECISION REQUIRED — Should `SMARTPRS_WA_SEND_PASSWORD` default to `false`?** It defaults to `true` (`SignupController.php:413`), sending a new tenant admin's plaintext password over WhatsApp and persisting it in `wa_log.body_values` forever | A security default that should probably be inverted, with the ledger redacting credential-bearing template variables regardless | Product / security decision |


---

# SOURCE: M-quality-performance-testing.md

## 11. Open questions / PRODUCT OWNER DECISION REQUIRED

| # | Question | Why it must be answered before the blueprint is finalised |
|---|---|---|
| **Q-1** | **PRODUCT OWNER DECISION REQUIRED — What is the retention period for each entity?** Specifically: attendance records, payslips and wage registers, audit logs, candidate PII, onboarding selfies and ID documents, notification logs, and archived-employee snapshots. | Nothing can be built until these numbers exist. They are simultaneously a legal question (statutory floors, DPDP storage limitation) and a product question (what a customer may configure). Ametecs must obtain counsel's position on the floors and then decide which are tenant-configurable above them. |
| **Q-2** | **PRODUCT OWNER DECISION REQUIRED — What happens to an employee's data on exit?** Immediate masking of bank account and national ID? Document deletion after N months? Full anonymisation after the retention floor? | Determines the employee lifecycle state machine and the erasure implementation. |
| **Q-3** | **PRODUCT OWNER DECISION REQUIRED — What happens to a tenant's data on cancellation?** Export window length, hold period, and whether purge is automatic or requires a signed instruction. | There is no answer in SmartPRS 2 at all — a churned customer's complete payroll dataset simply stays live. |
| **Q-4** | **PRODUCT OWNER DECISION REQUIRED — May a payroll run ever be destroyed?** SmartPRS 2 hard-deletes on regenerate. The alternative is immutable runs with supersession. | Changes the entire payroll data model and the correction workflow. |
| **Q-5** | **PRODUCT OWNER DECISION REQUIRED — Who may approve and who may disburse, and may one person be both?** SmartPRS 2's `admin` satisfies both halves. | A customer's auditor will ask. If segregation must be enforceable, the role model must support it from day one, and the default configuration must not permit self-approval. |
| **Q-6** | **PRODUCT OWNER DECISION REQUIRED — Is the ~₹ of the LOP fabrication defect recoverable?** `/app/data` has been fabricating `status='paid'` payroll runs and payslips into production for an unknown period, and the LOP defect has been crediting unpaid leave as paid. | Determines whether SmartPRS 3's data migration can trust `payslips` at all, and whether a customer-facing remediation is required **before** migration. |
| **Q-7** | **PRODUCT OWNER DECISION REQUIRED — Is the `app_state` blob's content known?** It holds "all 113 screens' collections" for screens never normalised into tables, in an unversioned JSON column whose type is not pinned in any migration, subject to last-writer-wins overwrites. | This is the highest-risk migration in the project. Someone must enumerate what is actually in it, per tenant, before the blueprint commits to a migration plan. |
| **Q-8** | Has the test suite ever been run green, and on what? `SESSION-REPORT-2026-08-16-SBB.md:303-307` states of the 23 SBB tests *"These have not been run."* `RUN-SBB-TESTS.bat` writes `sbb-test-results.txt`, which is **not present**, while `sbb-schema-status.txt` (the other batch file's output) **is**. | Determines whether 132 cases represent a passing baseline or an aspiration. |
| **Q-9** | Does `plans.seat_max` exist in production? No migration creates it; the **test fixture** adds it, and `BillingController.php:118` reads it. | If it does not exist, every plan is treated as having zero included seats and the entire SaaS pricing model is mis-billing today. |
| **Q-10** | How many `onprem_clients.invoice_no` values currently duplicate an `invoices.number`? | The cross-table GST series race is unconstrained; the damage may already exist and is a filing matter. |
| **Q-11** | Has `DummyDataSeeder` ever been invoked directly on a production database? | It wipes 22 tables for the first tenant by id when no demo tenant exists. |
| **Q-12** | Do the two attendance dedupe migrations still run on deploy, and how much attendance have they removed? | They execute on every `php artisan migrate`, cross-tenant, unbounded, non-transactional. |
| **Q-13** | Is a queue worker running on any customer installation? `config/queue.php` defaults to `database`; `.env.example` sets `redis`; the reference documentation records `numprocs=1` with a 60-second job timeout. | Determines whether the two existing job classes ever execute, and therefore whether any notification is delivered. |
| **Q-14** | What is the real target scale? This analysis models 1,000 / 5,000 / 10,000 employees per tenant per the brief; the reference documents model 2,000. | Sets the performance budgets in §10.2 requirement 45. |
| **Q-15** | Is `TestController` (the assessment/quiz feature) live? It has a cross-tenant destructive IDOR (S-5). | If dead, remove it now rather than migrating it. |


---

# SOURCE: N-product-business-modules.md

## 11. Open questions / PRODUCT OWNER DECISION REQUIRED

| # | Question | What is known | What is needed |
|---|---|---|---|
| **Q-01** | **PRODUCT OWNER DECISION REQUIRED — What is the canonical module catalogue?** | Six in-code vocabularies (16 / 10 / 14 / 50 / 57 / 107), a marketing claim of "16 modules", and a licence agreement referencing a catalogue that is not in the repository. | One authoritative list, agreed commercially, that becomes the SmartPRS 3 module registry. **This blocks the module-pack boundary.** |
| **Q-02** | **PRODUCT OWNER DECISION REQUIRED — Are the three on-prem editions real?** | `Edition::level()` returns a literal `3`; the revert path is deliberately preserved (`BLOCK_DNA`, `BLOCK_L2`, `SAAS_ONLY`/`DNA`/`L2_MODULES` intact). Three price points, one product. | Either implement tiering or stop selling three tiers. Two-line change either way; it is a commercial decision, not a technical one. |
| **Q-03** | **PRODUCT OWNER DECISION REQUIRED — Is plan-based module gating a product capability?** | Dead in two independent places; sold on the landing page; `StandardPlanSeeder`'s own docblock calls it "parked". | Decide, then either build it once (server-side) or remove the claim. |
| **Q-04** | **PRODUCT OWNER DECISION REQUIRED — What is a seat?** | Active on-roll employees pooled across companies; archived employees still bill; off-roll agents never bill; two of five doors bypass the cap; the whole thing fails open. | The pricing metric, in one sentence, before any billing data migrates. |
| **Q-05** | **PRODUCT OWNER DECISION REQUIRED — Should off-roll agents be billable, and should they be compliance-scanned?** | They are potentially the majority of a collections field force, consume no seat, have no expiry dates on their credentials, and are outside every compliance mechanism. | This is simultaneously the largest revenue question and the largest compliance-promise gap in the product. |
| **Q-06** | **PRODUCT OWNER DECISION REQUIRED — Which of the six vertical capabilities are a paid module pack, and which move to core?** | §1.8 recommends: pack = agent authorisation, off-roll payouts, client-bank/billing pack, escalations, audit pack, DRA gate. Core = credentials, policy ack, cases, references, expiry-alert engine. | Confirmation, because it determines whether the core is sellable to security agencies, staffing and logistics without a rewrite. |
| **Q-07** | **PRODUCT OWNER DECISION REQUIRED — Should a user be able to hold more than one role?** | SmartPRS 2 enforces exactly one. The `accountant` control exists precisely because a small agency needs an "HR Manager who is also Accounts" — and cannot have one. | A yes changes the permission model's shape fundamentally. |
| **Q-08** | **`SMARTPRS_TEAM_PIN` — dead config or live bypass?** | Config ships the live default `'ametecs'` and documents it as unlocking unrestricted demos with no write-guard and no hidden screens; a view says the PIN was removed at rev185. | Confirm no reader of `config('smartprs.team_pin')` exists in the shipped build. **Security-blocking.** |
| **Q-09** | **How many installs have `admin@smartprs.local` / `password` active?** | `OnPremWorkspaceSeeder` creates it, is not environment-gated, and its own docblock instructs running it on an on-prem install; the README publishes the credential. | An audit of every deployed install, before anything else. **This is the highest-urgency item in this document.** |
| **Q-10** | **What does `platform_support` mean?** | Creatable, `tenant_id = NULL` (the widest scope in the product), checked nowhere, labelled "Admin" in the SPA. | The intended remit, so it can be given a real scope. |
| **Q-11** | **Who is "Team Leader"?** | `ApprovalService`'s docblock names a three-tier chain; the code implements two levels; "Team Leader" is neither a role nor a column. | Whether it should be a designation, a `teams` FK, or was aspirational. |
| **Q-12** | **Do the audit report's parameters match the published RBI directions?** | The landing page builds a campaign around directions "Effective 1 October 2026" and lists five obligations, all of which the audit checks. The referenced artefacts (`/recovery-compliance-2026.html`, the manual PDF) are not in this repository. | The published directions, so the item set can be verified rather than assumed. |
| **Q-13** | **Is the live schema what the code builds?** | 59 runtime-created tables, at least 8 with competing definitions, and no live dump was ever taken (prior U3). | `mysqldump --no-data` from a production install. **This blocks every data-migration estimate in §9.** |
| **Q-14** | **Does the test suite pass?** | 17 test files, 132 cases, no CI, and the vendor's own session report says the 23 SBB tests were never run. | `php artisan test` against MySQL. It matters because the punch-ingest tests are the one asset §9 proposes to port wholesale. |
| **Q-15** | **Which tree is authoritative?** | This tree has 59 blade views, no `resources/js`, and **does** contain `resources/prototype/app.html`. The prior dossier's tree had 72 views, `resources/js`, and did **not** contain the prototype. | Confirmation of which tree SmartPRS 3 is being specified against. Counts from the prior dossier must not be used as acceptance criteria until this is settled. |
| **Q-16** | **Is the on-prem "self-service licence move once per year" a product feature or a support process?** | The licence agreement promises it; only a Super-Admin-only `/admin/onprem/{id}/deactivate` exists. | Whether to build it or reword the agreement. |
| **Q-17** | **Should `ai-assistant` be built or removed?** | A nav item shown to four roles with no implementation of any kind. | A product decision, currently presented to customers as a feature. |

---

*End of analysis.*
