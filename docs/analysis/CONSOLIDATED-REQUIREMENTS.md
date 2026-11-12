# CONSOLIDATED SMARTPRS 3 REQUIREMENTS (extracted from the as-is analyses)


---

# SOURCE: A-payroll.md

## 10. SmartPRS 3 requirements implied

### Period and calendar
1. The period engine must resolve a payroll period from a **named, effective-dated cycle
   definition** (calendar month, cut-off day N, weekly, fortnightly, semi-monthly, 4-4-5),
   scoped to a company/branch/employee-group by **id, never by name string**, because BR-PAY-002
   matches `company_name` and breaks on rename.
2. Any day-of-month parameter must be **clamped to the real month length** at a single choke
   point, because the engine currently uses raw `Carbon::day()` (DEF-P14) while a tested
   `clamp()` sits unused three files away.
3. The engine must **refuse to accept a period that overlaps or leaves a gap** after the last
   paid period, and must be able to **generate the bridging stub run itself** — today it only
   refuses and offers an unaudited override flag (BR-PAY-030, SEC-P10).
4. Pay date must be a single rule: *the first occurrence of pay-day N on or after the period end*,
   with a configurable "pay in same month / next month" policy — because three incompatible rules
   exist today.
5. The stored period on a run must be **immutable and authoritative**; regeneration must reuse it,
   never re-derive it (already true — preserve it).

### Attendance, LOP and proration
6. The LOP day-value basis must be selectable from at least **working days, calendar days,
   fixed 30, fixed 26 and payable days**, scoped per company / grade / employee-group — today it
   is one tenant-wide setting and 26 is unavailable despite being the FAQ headline.
7. The rule engine must express **"absence measured in one unit, valued in another"**, because
   the calendar/fixed30 bases measure absence in working days and value it in calendar days
   (`:1268-1284`).
8. **Half-day and quarter-day leave and attendance must be first-class**, because BR-PAY-014
   counts every leave day as exactly 1.0.
9. "**Zero punches**" must be a configurable outcome (pay in full / treat as absent / hold the
   slip / flag for review), because BR-PAY-013 hardcodes full pay and a genuinely absent employee
   is paid 100%.
10. The rule engine must support **N-occurrences-in-a-window** conditions, because BR-PAY-722
    hardcodes `one_day_per_n` lates per month and BR-PAY-047 hardcodes the sandwich rule as a
    two-sided adjacency test.
11. **Weekly-off patterns must be per company/branch/shift/employee**, must express "Nth actual
    weekday of the month" (not `ceil(day/7)`, DEF NEW-PAY-10), and must support rotating offs.
12. **Mid-month joiner and leaver proration must both exist** and must work under a cut-off cycle
    — leaver proration does not exist at all today, and joiner proration is wrong under a cut-off
    (DEF-P05).
13. There must be an explicit **attendance/leave freeze** per period, with an audited unfreeze,
    because nothing closes the window today (DEF-P04).

### Salary structure
14. Component semantics must be **flag-driven, never name-driven**: explicit `is_pf_base`,
    `is_da`, `is_esi_base`, `is_taxable`, `is_reimbursement`, `is_lwf_base` booleans — because
    today PF eligibility is `str_contains(name,'basic')`, DA is a regex, and reimbursement is
    `str_contains(name,'reimburs')`.
15. **No component name may be a literal anywhere outside the component definition.** Every one of
    the ~25 strings listed in §5.3 Q4 must become an id or a flag — this is the single largest
    blocker, and it currently makes the **salary approval sheet** (DEF-P11) and the **ESI
    half-year lock** (DEF-P11) silently wrong for any customer who renames a component.
16. The **default structure (Basic %, HRA %) must be configuration**, per company and effective-
    dated, with no hardcoded fallback.
17. Component bases must include at minimum `fixed`, `pct_of(component)`, `pct_of_gross`,
    `pct_of_ctc`, `balance`, `slab`, `formula`, each with optional **min, max and cap** — today
    four bases exist and `pct_ctc` is silently mis-computed (DEF-P20f).
18. Component scope must extend beyond employee/team/company to **grade, designation, branch,
    location, cost centre and employee group**, resolved most-specific-first.
19. The **balance-residue allocation must remain penny-safe** and must be the pattern used
    everywhere money is split (today it is the only correct instance).
20. A structure must be validatable **before** it is saved (Labour-Code 50% check, components
    reconcile to gross, no negative residue) — today the 50% check is advisory and a negative
    residue prints as a negative earning (DEF-P20c).

### Statutory
21. **Every statutory constant must be data**: PF rate and cap, EPS rate/cap/ceiling, EDLI rate,
    ESI threshold and both rates, every PT slab band for every state, PT-free states, the
    Maharashtra February uplift, the female-exemption rule and its threshold, TDS slabs, standard
    deduction, 87A ceiling, cess, bonus eligibility ceiling / wage cap / rate band, gratuity
    numerator and denominator, and the OT hourly divisor.
22. **All statutory configuration must be effective-dated and scoped**, and the payroll **period**
    must select the version — not "today". `StatutoryConfig` already proves the design; the base
    `statutory_settings` blob (which has no dates and no history and is what every install
    actually uses) must be **eliminated, not layered over** (Q5).
23. The rule engine must support **period-scoped eligibility locks** (the ESI Apr–Sep / Oct–Mar
    rule) as first-class stored facts per employee per period — not by string-searching a stored
    payslip JSON key (BR-STAT-005, DEF-P11).
24. Statutory bases must be **declarable per earning**: which earnings enter the PF wage, the ESI
    wage, the PT gross and the taxable base. Today every extra sits outside all four
    (BR-STAT-006), which is a live compliance exposure for ESI on overtime.
25. TDS must support the **old regime, surcharge, Chapter VI-A, HRA exemption, employee
    declarations, previous-employer income, a per-employee override, and a monthly YTD true-up**
    — none exist (DEF-P23).
26. Slab tables must be **validated on save**: ascending bands, exactly one open band, last —
    because the walk depends on ordering and nothing checks it (DEF-P22).
27. Employment-stage statutory treatment (probation / internship / trainee / retainer /
    consultant / apprentice) must be **a configurable matrix of stage × deduction head**, because
    today two stage names suppress exactly three heads in code, and that is a business choice
    being presented as a statutory exemption (BR-STAT-003).
28. There must be **exactly one implementation of each statutory calculation**, callable from the
    payslip, the register, the PDF, the simulator and the preview — today PF/ESI/TDS have
    **browser-JavaScript duplicates** that diverge (§5.3 Q8 items 2–4), and 24Q diverges from the
    payslip (DEF-P09).

### Money representation
29. **Money must be integer minor units (paise) end to end**, converted only at the presentation
    and persistence boundaries — or the invariant must be deleted from the documentation. A
    float comparison currently decides whether an EMI is deducted (`:1357`) and a loose float
    equality guards a payslip line (`:2632`).
30. Every place money is split must use the **residue-to-last** pattern, and every run must carry
    a **reconciliation assertion** that `SUM(payslips.net) = payroll_runs.net_total` — no such
    check exists (DEF-P17).
31. Rounding must be **declared per component** (2dp / whole rupee / ceil / floor) rather than
    implied by whichever function computes it, because ESI is the only `ceil`, bonus and one
    gratuity report are the only whole-rupee roundings, and LWF is never rounded (§4.16).

### Recoveries
32. Recovery **order must be configuration**, not the fixed statutory → loan → advance → clawback
    sequence (BR-PAY-009).
33. Each recovery type must have a configurable **shortfall policy**: skip / partial / carry
    forward / spread — today loans are full-EMI-only, advances are one-shot-and-lost, and
    clawbacks are one-month-only, all hardcoded.
34. Every recovery must write to a **run-keyed ledger** that supports clean reversal — loans and
    clawbacks do; **advances do not** (BR-PAY-033), so an advance recovery cannot be reversed.
35. A configurable **minimum net-pay floor** (absolute or a percentage of net) is required; today
    only "≥ 0" is enforced.
36. The loan model must carry a real **schedule**: principal, instalment amount, count, start
    period, outstanding balance maintained by the system, interest if any, and a moratorium —
    because the current schema is missing every one of those columns (DEF-P02).

### Runs, workflow and audit
37. The approval workflow must be a **configurable chain**: N stages, per-stage role or named
    approver, amount-based routing, delegation, and optional single-stage — today it is two
    hardcoded stages bound to two hardcoded role names (BR-PAY-716, BR-PAY-025).
38. Run and line **states must be data**, with configurable transitions, reverse transitions and
    terminal states — today six run states and seven line states are literals with no reverse
    path.
39. **Multiple runs (lots) per company-month** must be supported — `payroll_runs.lot` exists and
    is hardcoded to 1.
40. **Off-cycle, supplementary and arrears-only runs** must be first-class.
41. Regeneration must **version, not destroy**: the current path deletes the run and its payslips,
    losing the rejection remark, the approval stamps and every line status (BR-PAY-405, DEF-P18).
42. A **reopen** path with super-admin authority and a full audit trail must actually exist — it
    is documented and unimplemented (BR-PAY-006).
43. **Regeneration must re-price using the policy in force for the period**, and must warn
    explicitly when any input (rate, structure, CTC, cycle) has changed since the original run
    (Q5).
44. Every state-changing operation must be a **POST** and must be audited with actor, timestamp,
    IP and the before/after values — today the bank file mutates state on a GET (SEC-P04) and a
    run rejection records no author (DEF-P18).
45. **CTC must be effective-dated salary history**, not a single overwritten column
    (`IncrementController:342`), so a historical recompute uses the CTC of the period.

### Outputs
46. Payslip layout must be a **template per company** (fields, grouping, language, logo,
    statement of period), and every figure it prints must come from **structured stored data** —
    Paid Days and LOP are currently recovered by regex from English prose (BR-PAY-031).
47. Earning/deduction **grouping must come from the component definition**, never from keyword
    matching (NEW-PAY-30).
48. "Gross" must have **one definition** — the printed A+B excludes reimbursements while the
    stored `payslips.gross` includes them (NEW-PAY-31).
49. Bank file layouts must be **configurable column mappings per bank**, importable and
    versionable — today four layouts are literal PHP arrays.
50. Statutory registers and returns must be **generated from stored payslip facts**, never
    recomputed from current CTC — today the PF/ESI and TDS registers recompute in the browser
    from full CTC and disagree with the payslips they claim to summarise.
51. YTD must be computed over **scoped, de-duplicated, run-approved** slips only (SEC-P09).

### Platform
52. **No DDL at request time.** Roughly 60 columns and 7 tables are created by controllers today,
    so the schema depends on which screens have been opened (DEF-P29).
53. **No fabricated data of any kind in a production code path** (DEF-P01).
54. Authorisation must be **middleware-enforced per route**, and must **fail closed** — today it
    is scattered in-controller and `denyUnlessRole()` fails open (SEC-P01), with four payroll
    endpoints carrying no guard at all (SEC-P02, SEC-P03, SEC-P05, SEC-P11).
55. Every query touching money must be **tenant-scoped without exception** — three are not
    (SEC-P07, SEC-P08, SEC-P09).
56. The in-app calculation guide must be **generated from the rule definitions**, so it cannot
    contradict the engine (§8.2 lists four places where it already does).

---



---

# SOURCE: B-attendance.md

## 10. SmartPRS 3 requirements implied

Numbered, testable, and traceable to the rule or defect above.

**Attendance day and shifts**

1. The engine must derive an **attendance date** from a configurable rule, not from the
   punch's calendar date. At minimum it must support "the attendance day starts at
   HH:MM" and "a punch belongs to the shift whose window it falls in, extended by a
   configurable pre/post tolerance", so a 22:00–06:00 shift produces **one** attendance
   day (DEFECT-B-01, BR-ATT-201).
2. Late, early, worked and break arithmetic must be computed on **absolute instants
   anchored to the shift start**, never on minutes-since-midnight, so a night-shift
   arrival at 00:15 against a 22:00 shift is 2 h 15 m late (DEFECT-B-02, CALC-B-03/04).
3. A shift must be a first-class configurable object supporting: start, end, grace,
   full-day and half-day thresholds (in hours **or** minutes), break budget, break
   deduction rule, night flag/allowance, rotation pattern, and effective dating. The
   night flag must be settable explicitly, not inferred from `end <= start`
   (BR-ATT-019).
4. Rosters must be keyed by employee identity, not display name, and must support
   pattern generation (n-on/n-off, rotating weeks) and bulk publication (NEW-ATT-004).
5. Shift resolution must be a single service with an explicit, configurable precedence
   chain (roster → shift group → employee default → org default), with **no hardcoded
   09:30–18:30 anywhere** (BR-ATT-018, backlog item b.2).

**Punch ingest**

6. One ingest pipeline with pluggable adapters. Every adapter must produce the same
   record and go through the same validate → normalise-time → resolve-employee →
   deduplicate → land steps, so a rule fixed once is fixed everywhere (DEFECT-B-05,
   DEFECT-B-08, DEFECT-B-21, DEFECT-B-22).
7. Every ingest path must return a **per-punch verdict** (accepted / duplicate / pending
   / rejected + reason). Silent `continue` on a malformed line must be impossible
   (DEFECT-B-22, BR-ATT-013).
8. Time parsing must be adapter-declared: each connection states whether its timestamps
   are naive local, carry a trustworthy offset, or are in a named zone. A fallback that
   guesses (`Carbon::parse`) must not exist (BR-ATT-006/007, doc T3–T5).
9. Punch identity must be a stable key that does **not** include mutable properties
   (direction), and must be enforceable on single-tenant installs — i.e. no nullable
   column may participate in the uniqueness guarantee (BR-ATT-002, §2.1).
10. Direction derivation must be a configurable strategy per connection —
    machine-id map, vendor-flag map (with a customer-editable token list), positional
    alternation with a **configurable** repeat window, or "unknown, pair by alternation"
    (NEW-ATT-001/002/003).
11. Unmapped punches must always be quarantined with full provenance (device serial, raw
    PIN, prefixed code, external id, verify mode) and replayable on mapping, on **every**
    path including the LAN one (BR-ATT-008b).

**Status derivation**

12. There must be **one** status engine producing a stored, auditable per-employee-per-day
    record, and every consumer (report, payroll, points, ESS, dashboard, absence) must
    read that record rather than re-deriving (DEFECT-B-09, NEW-ATT-005).
13. The status vocabulary must be configurable and must at minimum express: Present,
    Half-day, Absent, Weekly-off, Holiday, On leave (by type), On duty, Work-from-home,
    Comp-off earned, Comp-off availed, Not-yet-joined, Exited. Comp-off does not exist in
    SmartPRS 2 at all and must be built (§Q4).
14. Presence must be gated by a configurable rule — e.g. "present requires ≥ N worked
    minutes", "requires both an in and an out", "a day with only one punch is a
    half-day" (NEW-ATT-005).
15. A day with **no punches** must still produce a row with a status, so the report is a
    true muster roll (NEW-ATT-006, DEFECT-B-10).

**Rules engine capabilities implied by specific hard-coded behaviours**

16. The rule engine must support an **N-occurrences-in-a-rolling-window** condition,
    because BR-ATT-022 hard-codes "free lates counted per payroll month" and customers
    routinely ask for "3 lates in any rolling 30 days" (`PayrollGenController.php:453, 475`).
17. It must support **banded/tiered thresholds with an arbitrary number of bands**, each
    with its own threshold, penalty and (optionally) grace, because BR-ATT-021 hard-codes
    exactly three (L1–L3) and BR-ATT-021b hides a fall-through that charges sub-L1
    lateness at the L1 rate (`PayrollGenController.php:482-495`).
18. It must support **step functions with a configurable block size and step value**,
    because BR-ATT-023 hard-codes `ceil(excess / 30) × 0.25 day`
    (`PayrollGenController.php:469`).
19. It must support **penalties expressed as day-fractions, hour amounts or money**, and a
    configurable cap per period; SmartPRS 2 supports only unbounded day-fractions
    (CALC-B-05..09).
20. Rule scope must be an arbitrary predicate over employee attributes (company, branch,
    department, team, designation, grade, employment type, location, shift, individual),
    with deterministic specificity ordering and **effective dating**. SmartPRS 2 offers
    three levels, breaks ties by row order, and has no effective dating (NEW-ATT-019).
21. It must support **early-departure** and **short-hours** conditions as first-class
    rules; SmartPRS 2 computes `early_min` and discards it (CALC-B-10).
22. It must support **weekly-off patterns** as configuration: per-employee/per-group,
    fixed day(s), alternate/nth weekday, rotational, and roster-driven — with **one**
    evaluator. SmartPRS 2 has four incompatible definitions (CALC-B-16).
23. It must support a **sandwich rule** as configurable policy: which off-day types are
    sandwiched, how many bounding absent days, whether leave/holiday breaks the sandwich,
    and whether it applies to leave as well as absence. SmartPRS 2 has one boolean and a
    single fixed algorithm (CALC-B-17).
24. It must support **comp-off** end to end: accrual rules (worked on a weekly-off /
    holiday, worked ≥ N hours), expiry, approval, and consumption as a leave type.
25. It must support **overtime derivation from punches**, with configurable eligibility,
    thresholds, rounding and multipliers. SmartPRS 2 derives a display-only
    `overtime_min` and pays only from a manually keyed register (CALC-B-10).
26. It must support **holiday scoping** — by company, branch, location, employee group,
    and optional/floating holidays. SmartPRS 2 has a `holidays.applies_to` column with no
    reader (NEW-ATT-011).

**Corrections and evidence**

27. Approved corrections must be stored as **overlay adjustments** with their own validity,
    never by mutating or deleting punch rows, so the raw device evidence and the corrected
    view coexist and either can be shown (DEFECT-B-18, Q1).
28. A correction must be able to express an **overnight pair** (an out on the following
    day) (NEW-ATT-014).
29. When a correction lands on a **locked** period, the system must create an explicit,
    trackable arrears/adjustment record with an owner and a target period — not a status
    flag and an email promising something no code does (DEFECT-B-30).
30. The period-lock test must be based on the **attendance period actually used by the
    run** (honouring the cut-off day), must be database-portable, and must **fail closed**
    (DEFECT-B-31).
31. The correction workflow must support configurable multi-level approval, SLA/escalation,
    delegation, per-employee monthly limits and a backdating window; and must be
    paginated (backlog items b.36–b.44).

**Recalculation and audit**

32. There must be an explicit, idempotent **"recalculate attendance for (scope, date
    range)"** operation, with a preview/diff, that re-derives every day from the punch
    ledger plus the rules effective on each date. SmartPRS 2 has no such command (Q1).
33. Rules must be **effective-dated and versioned**, and each derived day must record which
    rule version produced it, so a policy change from 1 April does not silently rewrite
    March.
34. Every mutation of a punch (direction re-stamp, emp_code re-key, tenant re-assignment,
    dedupe deletion, demo purge) must be written to an append-only audit log with the
    before-value and the actor. SmartPRS 2 audits only the in-app punch
    (`AttendanceReportController.php:326`) (Q1, Q7).
35. Deduplication must **quarantine**, never hard-delete, and must never run
    unattended inside a migration (DEFECT-B-25, Q7).

**Time, tenancy and non-functional**

36. Timezone must be resolvable per tenant, per company and per location, and every stored
    instant must carry the zone it was captured in. One global `APP_TIMEZONE` is a
    single-country assumption (doc §2.11 R1/R9, backlog b.55).
37. Scheduled sweeps (absence, late notification, sync) must fire at each tenant's **local**
    configured hour, not the server's (`AbsenceNotify.php:38`).
38. No schema DDL may be issued from a request handler; all schema is migration-owned
    (DEFECT-B-23).
39. Multi-tenancy must be structurally enforced (non-nullable tenant, tenant in every
    unique index and every upsert key). `attendance_ratings` and the NULL-tenant unique
    indexes prove the current model is opt-in (FINDING-B-SEC-02, §2.1).
40. Self-service scoping must be enforced centrally (policy/gate), not re-implemented per
    endpoint; `logs()` proves per-endpoint scoping fails (FINDING-B-SEC-01).
41. Authorisation must **fail closed** (FINDING-B-SEC-03).
42. Attendance data must have a retention and purge policy; quarantine, unmapped-code and
    notification ledgers currently grow forever (OQ-B-08).
43. Reports must be metadata-driven: one definition producing screen, CSV, XLSX and PDF,
    with configurable columns, filters, saved views and scheduling — replacing the two
    parallel report implementations and the fixed blade (DEFECT-B-12).

---



---

# SOURCE: C-biometric-integrations.md

## 10. SmartPRS 3 requirements implied

Numbered, testable, each traceable to a finding above.

**Adapter / protocol engine**

1. The ingest layer MUST define a single **DeviceAdapter contract** (`configured()`,
   `fetch(window)`, `parse(raw) → Punch[]`, `capabilities()`), and provider dispatch MUST
   occur in exactly one place. Today it is duplicated across five (`BiometricConfigController::test`
   `:409-450`, `::sync` `:480-509`, `ETimeOfficeService::configuredAny` `:126-137`,
   `SyncETimeOffice::handle` `:76-96` and `:115-119`). *Test: adding a new adapter touches
   one registry file and zero existing adapters.*
2. Adapters MUST be registrable as **configuration** (a recipe document), not only as PHP
   classes, and the recipe MUST be schema-validated on save with a field-level error
   returned — never silently NULLed (DEF-C-13).
3. The recipe grammar MUST support, at minimum, what the current one cannot:
   **pagination/cursoring, an explicit retry policy with backoff, OAuth2 client-credentials
   with refresh, per-request rate limiting, and an explicit response-encoding declaration.**
4. Every adapter MUST declare its **capabilities** (`pull` / `push` / `command` /
   `enrolment` / `realtime`) so the UI can render only the fields that adapter uses,
   instead of the current four-branch `if` in the SPA (`AppController.php:9695-9710`).
5. Punch **direction vocabulary MUST be configurable per adapter**, mapping a raw device
   token to a domain punch type, with `unknown` as a first-class value that routes to a
   review queue. Today `in`/`out` are the only outcomes and every unrecognised value
   silently becomes `in` (NEW-C-04, four sites).
6. The **ADMS command channel MUST be implemented**: the engine must be able to queue and
   deliver commands to a push device (`getrequest` currently always answers `OK`,
   `PushController.php:57-62`) — at minimum clock-sync, user enrol/delete, and a
   diagnostic ping.
7. The **ADMS option block MUST be configuration**, not literals: `TransTimes`,
   `TransFlag`, `Delay`, `ErrorDelay`, `Encrypt`, `Stamp` per device
   (`PushController.php:89-100`).

**Identity, tenancy and trust**

8. Every ingest endpoint MUST derive tenant from an **authenticated credential**, never
   from a device-supplied identifier. A device serial MUST NOT by itself select a tenant
   (DEF-C-02, SEC-C-01/03). Devices MUST carry a per-device secret or be pre-enrolled.
9. Unknown-device behaviour MUST be a **per-tenant policy** with at least three settings:
   reject, quarantine-pending-approval (default), auto-register. Today auto-register is
   unconditional and unauthenticated (BR-DEV-001).
10. `(tenant, device serial)` MUST be **unique**, and every device lookup MUST be
    tenant-scoped.
11. Employee identity resolution MUST be a **configurable, ordered strategy list** —
    e.g. `[explicit device identity for this device] → [explicit device identity, any
    device] → [employee code with prefix rule] → [employee code raw]` — evaluated
    **candidate-major, not column-major**. The current column-major loop
    (`ETimeOfficeService.php:315-345`) is a live mis-attribution bug (DEF-C-01).
12. The identity model MUST support **(device, identity_value) → employee**, so the same
    PIN on two devices can be two people (NEW-C-13 in §5.2, BR-DEV-005). Migration from
    `employees.device_user_id` is a device-agnostic row.
13. **Employee NAME MUST NEVER be a join key** for machine ingest. It is not one today on
    any device path (verified), and that property must be preserved and asserted by test.
    (It *is* used elsewhere — `AttendanceBulkController`'s name index, the in-app punch's
    `name = user.name` fallback, and `LateArrivalService.php:283` looking up a manager's
    email by `LOWER(name)`. Those are outside this area but the rule must be global.)
14. Employee-code derivation MUST be a **configurable transform** (prefix, suffix,
    zero-pad, regex, lookup table) per device, not a bare string prepend
    (`ETimeOfficeService.php:691`).

**Time**

15. Every ingest path MUST use **one** time-parsing rule. `Carbon::parse` as a fallback MUST
    be forbidden (DEF-C-04, four sites). An offset MUST either be rejected (device
    contract: naive wall clock) or converted (partner contract: authoritative offset), and
    **which of the two applies MUST be an adapter declaration**, not a code path.
16. The engine MUST record, per punch, the **timezone assumption used**, so a later
    discovery that a device was misconfigured is correctable without guessing.

**Durability and idempotency**

17. A punch MUST be persisted **before** the sender is acknowledged, on every path, and the
    acknowledgement MUST carry a **per-punch verdict** (BR-ATT-013 is true only for
    SBB/SmartEPT today; `/iclock` returns `OK: n` and the pull paths return counts).
18. Idempotency MUST be a **database guarantee**, and the guarantee MUST hold on a
    single-tenant install. Today both unique indexes lead with `tenant_id`, and NULL never
    collides in MySQL, so on the three self-hosted installs **both indexes are inert** and
    de-duplication rests entirely on `updateOrInsert` (`2026_08_16_000200…:19-28`;
    `SmarteptSignature.php:72-78` exists only because of this). SmartPRS 3 MUST NOT permit
    a NULL tenant.
19. Unmapped punches MUST be quarantined on **every** path, including any LAN/direct path
    (DEF-C-05), and released by an explicit, audited mapping action.
20. There MUST be exactly **one** replay implementation (DEF-C-07), and it MUST be
    device-scoped by default.
21. No table may be **created or altered at runtime by application code**, and certainly not
    by an unauthenticated request (DEF-C-09: `biometric_unmapped`, `mobile_devices`,
    `biometric_configs` columns, a fourth `attendance_logs` shape).
22. Two features MUST NOT share a table name (CONF-C-01).

**Buffering, retry and back-pressure**

23. Outbound connector calls MUST have a **per-adapter timeout and retry policy with
    exponential backoff and a circuit breaker**. Today every timeout is a literal 60 s and
    there is not one `->retry()` in the codebase (NEW-C-25).
24. A failed pull MUST be **recorded on the device row** with a failure timestamp, an error
    class and a consecutive-failure counter (DEF-C-14: today `last_status` still shows the
    last success).
25. A pull window that fails MUST be **re-attempted over the missed range**, not silently
    replaced by the next tick's `now() - days` window. The engine needs a per-device
    high-water mark, not a rolling window.
26. Late-arrival and every other notification MUST be **queued**, never sent inline from an
    ingest request (DEF-C-03; the SBB path already does this at
    `PunchIngestService.php:203-209`).
27. Ingest rate limits MUST be **per credential/device**, not per source IP, because many
    devices share one NAT address (NEW-C-21).
28. Batch and body caps MUST be configuration, not constants (`MAX_BATCH = 1000`,
    `MAX_BODY_BYTES = 2097152`).

**Direction and device topology**

29. The **device topology rule** (one reader alternating / two readers by machine / device
    flag / always-in) MUST be a device-level configuration applied by the **shared** ingest
    core, so it holds on every path. Today only `ETimeOfficeService::import` honours
    `device_mode` (CONF-C-02).
30. The **double-tap window MUST be configurable per device** (currently
    `SINGLE_REPEAT_SEC = 60`, `ETimeOfficeService.php:578`). The rule engine therefore
    needs a *"collapse repeated events from the same subject within N seconds"* primitive.
31. The rule engine needs an **ordinal-within-a-partition** condition —
    "the Nth qualifying punch for this employee, this day, this feed" — because
    direction-by-rank is exactly that (`:653-664`), and it must be **idempotent and
    re-computable** when an earlier event arrives late (pinned by
    `DeviceModeTest.php:140-157`).
32. "IN machine" / "OUT machine" MUST have **one unambiguous meaning** — a reference to a
    registered reader entity, not a free-text string compared sometimes to a serial and
    sometimes to a record field (CONF-C-03).

**Credentials**

33. All connector credentials MUST be encrypted at rest with a **managed key**, and a
    decrypt failure MUST raise an operator-visible alert — never silently fall back to
    treating the ciphertext as plaintext (SEC-C-13, two sites).
34. Shared secrets that must be verifiable (webhooks) MUST be encrypted; secrets that need
    only be compared (API keys) MUST be hashed. Keep the current split (BR-ATT-044/045).
35. API keys MUST support **rotation with an overlap window** (NEW-C-15).
36. Scopes MUST actually gate something; a declared-but-unenforced scope is a lie
    (NEW-C-14, `read`).
37. Credential-bearing endpoints MUST **refuse plaintext HTTP** (SEC-C-15).
38. Every outbound connector MUST enforce a **scheme allow-list (https by default) and an
    egress host allow-list**, blocking link-local, loopback and RFC1918 ranges unless
    explicitly permitted for a LAN connector (SEC-C-04/06).
39. Stored credentials MUST NOT be interpolable into an admin-supplied URL or header
    (SEC-C-05); a connector recipe must reference a credential by handle, with the engine
    placing it only where the adapter declares it belongs.
40. Connector test output MUST be **redacted and size-capped**, and MUST NOT return a raw
    response body from an arbitrary host to the browser (SEC-C-14).

**Authorization**

41. Authorization MUST **fail closed**. `ApprovalService::denyUnlessRole` and
    `ApiKeyController::authorizeScreen` both return "allowed" on any exception
    (SEC-C-07). This is a global requirement, surfaced here because every device and
    credential endpoint depends on it.
42. Mapping a device ID, claiming a device, and the historic-punch **backfill** MUST each
    write an audit record with before/after values (NEW-C-11, no audit today).
43. The backfill MUST NOT claim NULL-tenant rows (SEC-C-12).

**Observability**

44. Every device MUST have a **health record**: last contact, last successful punch,
    consecutive failures, punches in the last 24 h, firmware/serial as reported, and a
    derived status (healthy / stale / failing / never-seen).
45. There MUST be a **staleness alert** — "device X has not reported for N hours" — with N
    per device, defaulting from its own sync interval.
46. Every dropped line MUST be **counted and reportable** (blank PIN, unparseable date,
    unknown table), not silently `continue`d (DEF-C-17).
47. The import result MUST distinguish **inserted / updated / duplicate / quarantined /
    dropped**, not report a single `imported` number that counts updates (DEF-C-18).

**Mobile**

48. If a device gate exists, its token MUST be **verified on every request** by middleware
    (SEC-C-08).
49. Mobile device registration/status/push-token endpoints MUST be **tenant-scoped and
    authenticated or ownership-proving** (SEC-C-09/10).
50. Push notification delivery MUST exist, or the token registry MUST be removed
    (DEF-C-20).

---



---

# SOURCE: D-employee-master.md

## 10. SmartPRS 3 requirements implied

**Metadata / field engine**

1. The field engine MUST store employee field definitions as **tenant-scoped data**, not
   code: key, storage column/attribute, data type, label, help text, group/tab, display
   order, value set, validation rule, required-ness, uniqueness, PII classification. The
   56 rows of `EmployeeFieldRules::FIELDS` seed the system set.
2. It MUST support **customer-defined custom fields** with no migration and no deploy,
   because none exists today and adding one field costs 6–16 code edits across 8 files
   (Q2).
3. **Every surface MUST render from that one definition** — form, template, export,
   importer aliases, **and the write mappings, and the read-back projection, and the
   self-onboarding portal, and the HR console, and ESS**. The failure of SmartPRS 2 is
   precisely that four surfaces derive and nine do not (DEF-24).
4. Field definitions MUST carry a **`required` flag with a scope** (create-only /
   always / per-surface / per-role), because today only `name` is required and the two
   "mandatory" DRA/PCC rules are browser-only and bypassed by five write paths
   (BR-EMP-017).
5. Field definitions MUST carry **per-surface visibility and writability**
   (visible/hidden/read-only per role and per surface), because ESS currently exposes an
   arbitrary hard-coded 16 and writes an arbitrary hard-coded 10.
6. Value sets MUST be **tenant-editable lists with a stable stored code and a display
   label**, and every writer MUST validate against the set. Today 13 import fields have
   no value-set check at all (DEF-25) and `status` accepts any string.
7. Validation rules MUST be **declarative and defined once**, with the client-side check
   generated from the same definition. Today the same 16 rules exist in three
   hand-written copies (`EmployeeFieldRules::formatError`, `AppController::empFieldError`,
   `portal.blade.php` `SO_RULES`) that have already drifted.
8. Validation MUST support **locale/country variants**, because `BR-EMP-007` hard-codes
   Indian mobile numbers while the product ships a NATIONALITY field and an "SSN" label —
   a non-Indian number can never be stored.
9. Uniqueness MUST be **declarative per field, per scope (tenant / company / global)**,
   enforced by a **database constraint** as well as a pre-check, and MUST **fail closed**.
   Today it is 8 hard-coded fields, PHP-only, racy, on `LOWER(TRIM(col))` that cannot use
   its own indexes, and swallowing every exception (DEF-37, DEF-38).
10. Any field-name interpolation into SQL MUST be whitelisted against the live column
    list — SEC-20 is informational today only because `uniqueFields()` is a constant, and
    SmartPRS 3 makes it data-driven.

**Write semantics**

11. The employee write API MUST be **PATCH-semantic** (only supplied fields change) with
    an explicit "clear this field" operation, because SmartPRS 2's form save is a
    full-record replace that NULLs everything omitted (DEF-02).
12. There MUST be **exactly one employee write service** that every surface calls. Nine
    write paths, four write mappings and three sanitisation policies is the root cause of
    most defects in this document.
13. Sanitisation, format validation, uniqueness checking and audit MUST be **inside that
    service**, not at each call site — three of nine paths currently skip HTML stripping
    and ESS skips all four (DEF-11, DEF-12).
14. Child collections (references, documents, addresses) MUST be edited with explicit
    add/update/remove operations, never delete-all-then-reinsert (DEF-03).

**Effective dating and audit**

15. The data model MUST make **branch, department, designation, reporting manager, grade,
    salary, status and employment stage effective-dated**, with an `as-at` query, because
    SmartPRS 2 cannot answer "what was this employee's branch on 15 May?" (Q5).
16. **Every** employee attribute change MUST write a field-level audit row (who, when,
    from, to, source surface, reason). `storeEmployee()` writes **no** audit row today.
17. Effective-dated changes MUST support **future-dating with automatic application on
    the effective date** — the transfer module already proves the pattern
    (`TransferService::applyDue()`), and it must generalise to every dated attribute.
18. Back-dated changes MUST trigger a **declarative retro-effect rule** (recompute
    payroll / raise arrears / re-run statutory), rather than the single hard-coded arrears
    formula in `IncrementController` (BR-EMP-050).

**Status and lifecycle**

19. Employee status MUST be a **configurable state set with a configurable transition
    table**, per-transition role permissions, required reason/effective date, and guards.
    SmartPRS 2 has two states, no guards, and rewrites status on every save (DEF-04,
    DEF-16).
20. Which statuses consume a licensed seat MUST be **configuration**, not a private PHP
    const (BR-EMP-022).
21. **Every** creation path MUST pass the seat check — two of nine bypass it today
    (DEF-06).
22. Archive, delete and restore MUST be first-class, permissioned, audited, reversible
    operations with a configurable grace period. Restore must not be a side effect of
    import (DEF-17).
23. Any batch lifecycle operation (archiving due backups) MUST run as a **scheduled job**
    with a failure channel, never inside a user's page load (DEF-14).

**Import / export**

24. The import engine MUST be **driven by the same field metadata**: accepted aliases,
    types, value sets, validators and required-ness all come from the definitions.
25. It MUST **report unmapped and dropped columns explicitly** in the preview — the
    current behaviour is four importers that all discard unknown columns in silence
    (Q3), one of which additionally NULLs existing data.
26. It MUST support a configurable **blank-means-clear** convention per field; today a
    blank cell can never erase a wrong PAN (NEW-EMP-088).
27. It MUST support configurable **required-to-import** columns beyond `name`
    (BR-IMP-003).
28. It MUST **stream**, enforce a row cap and a memory guard, and be resumable — the
    current wizard reads the whole file into memory twice with no cap.
29. It MUST report **all** failing fields per row, not just the first (`formatErrors()`
    short-circuits, so a row with three bad values takes three round-trips).
30. Staged upload files MUST be **encrypted at rest, purged on a TTL, and the password
    column blanked immediately after use** (SEC-10).
31. Export MUST be metadata-driven with **column selection, filters (status, department,
    date range, include-archived) and saved export profiles**, and MUST emit dates in the
    format its own header declares (DEF-21).
32. Export and import MUST be **provably round-trip-symmetrical**, with a regression test
    per field.

**Self-onboarding**

33. The onboarding flow's **steps, per-step field sets, and completion criteria MUST be
    configuration**, including which sections and which documents are genuinely mandatory
    (today the portal labels documents "Required" and `submit()` does not check).
34. The candidate portal, the HR console and the injection mapping MUST all render/derive
    from the **same field metadata** — three hand-maintained copies today (DEF-23).
35. Link lifetime, OTP length, OTP lifetime, OTP attempt limit, resend limit and channel
    set MUST be configuration.
36. **Every public endpoint MUST be rate-limited**, OTPs MUST be invalidated on resend,
    and a one-time code MUST never be returned in a response under any configuration
    (SEC-04, SEC-05).
37. Verification MUST be a **checklist-driven workflow** (per-field and per-document
    verified/rejected with a reason and an approver), not a bare status flip.
38. Bulk onboarding MUST pass through the identical verification gate and MUST require
    **explicit per-row confirmation of an existing-employee match** (SEC-06, DEF-10).
39. Onboarding payloads MUST be **purged or redacted once injected**, and the identifiers
    they carry encrypted at rest (SEC-17).

**Identity**

40. Employee identity MUST **never** be resolved by name. All 18 name-based resolutions
    (Q6) must become id-based, including the "own employee" resolver (16 copies today,
    SEC-22), recruitment hire idempotency (NEW-EMP-077), transfer-onboarding idempotency
    and the `emp_map` picker on ~20 master screens.
41. There MUST be **one** own-employee resolver, backed by a mandatory `users.employee_id`
    link, with an explicit "not linked" error rather than a name fallback.
42. Employee-code generation MUST be a **configurable sequence** (prefix, width,
    per-company or per-tenant, gapless or not), collision-safe and transactional. Three
    incompatible generators exist today, one with no collision check (BR-EMP-003,
    DEF-08).
43. The org hierarchy MUST be stored as **foreign keys only**, with names resolved on
    read, and the migration MUST include a name→id reconciliation pass with an exception
    report.

**Documents and compliance**

44. Document types MUST be a **configurable catalogue** with: category, allowed formats,
    size cap, whether an expiry is required, expiry-derivation rule, renewal reminder
    cadence, mandatory-for-which-employee-population, and a verification workflow. Today
    there are two unrelated hard-coded vocabularies (6 categories, 7 kinds) and no
    verification action at all (Q7).
45. **Every** dated compliance artefact MUST feed one alerting engine with configurable
    windows, buckets, recipients and schedule — today `documents.expiry` and
    `pcc_deadline` are captured and alerted on by nothing (DEF-18, NEW-EMP-085).
46. Compliance readiness rules MUST be declarative per document type — today DRA requires
    `verified` while PCC accepts `submitted`, asymmetrically and undocumented
    (BR-CMP-004).
47. Document access MUST be **object-level authorised** — an employee sees only their own
    (SEC-03), and documents MUST be soft-deleted with a retention policy.
48. Employee soft-delete MUST have a **declared cascade policy** per related entity;
    today nothing cascades and orphaned rows point at codes no live employee holds.

**Workflow**

49. Onboarding, offboarding and transfer checklists MUST be **configurable templates**
    selectable by company, department, designation and event type, with owners, due dates
    and escalation — not two 6-item PHP constants (NEW-EMP-081).
50. Increment application MUST be **configurable**: manual, or automatic on the effective
    date; and the arrears calculation's **divisor (currently `/12`) and proration basis
    (currently calendar days) MUST be configurable formulas**, because they are payroll
    policy, not arithmetic.
51. Probation reminders MUST support **"at or past" milestone semantics with catch-up**,
    not exact-day matching, and MUST resolve the reporting manager through the FK
    (DEF-28, DEF-05).
52. Notification recipients MUST be **role- and relationship-configurable** (HR, manager,
    manager's manager, a named group), not the hard-coded
    `admin|hr_manager|hr|super_admin` list.

**Platform**

53. **Role authorisation MUST be middleware-enforced on every route** and MUST **fail
    closed**. SmartPRS 2 has no role middleware on `/app/*` and a helper that returns
    "allowed" on any exception (SEC-07, SEC-23) — three HIGH findings follow directly.
54. **Tenant scoping MUST be enforced by default**, with platform-wide access an explicit,
    audited opt-in. The `->when($tid, …)` idiom silently disables isolation for
    super_admin across the whole subsystem, and `storeEmployee()` writes a super admin's
    employees against tenant #1 (SEC-09).
55. **No runtime schema creation.** Every table and column comes from a migration.
    SmartPRS 2 creates 34 `employees` columns, 6 tables and several master tables at
    request time, always untyped, producing type drift between installs (SCHEMA-01,
    BR-IMP-700).
56. Error responses MUST never contain raw SQL text (SEC-16), and error reports MUST
    carry field names and row numbers, **not identifier values** (SEC-02).
57. Every swallowed exception that guards a correctness or security decision MUST be
    logged and MUST fail closed (DEF-40).
58. The employee master MUST have **integration test coverage of every write path and
    every permission gate** before go-live; SmartPRS 2 has 10 no-DB unit tests and zero
    coverage of `storeEmployee()`, the import wizard, the export, self-onboarding,
    archive/restore, transfers, increments or any gate (DEF-39).
59. There MUST be a **real employee REST API** (there is none today: `routes/api.php`
    exposes only `ping`, punch ingestion and one webhook), built on the same write
    service, with PATCH semantics and the same validation.

---



---

# SOURCE: E-leave-requests-notifications.md

## 10. SmartPRS 3 requirements implied

Numbered, specific and testable. Each cites the as-is behaviour that forces it.

### 10.1 Request-type metadata engine

1. The request-type catalogue **must be data, not code**. A tenant administrator must be able to create, rename, reorder, enable and disable request types without a deploy, because `ApprovalService::modules():27–43` is a static PHP array and every tenant sees all nine types.
2. Each request type must own a **field schema** — field key, label, data type, options, requiredness, min/max, regex, help text, display order, conditional visibility — because today the SPA's `RQ_MAP` (`AppController.php:3902–3947`) and the database columns are two unsynchronised lists and the server accepts `fields` as a **wholly unvalidated array** (`RequestController.php:386`).
3. Submitting a field that the type's schema does not define **must fail loudly with a named error**, never be silently discarded. `safeRow()` (`ApprovalService.php:262–266`) currently drops nine known fields with no signal, losing the expense reason, the loan tenure and purpose, the clawback recovery month and the bonus type.
4. Field-level data must be stored against the type's schema (EAV, JSON with a schema, or generated columns), so that adding a field never requires an `ALTER TABLE` at runtime.
5. Each type must declare its **amount semantics** — which field is the monetary basis, whether it is gross or net, and its currency — because today `amount` means "whatever the client posted" for five modules, "the NET after TDS" for commissions (`:439`) and "the new CTC" for increments (`:529`).

### 10.2 Workflow / approval engine

6. The engine must support **N-step sequential approval** with per-step conditions, because a request today has exactly one approver for its whole life (`approver_id` written once at `RequestController.php:539`).
7. It must support **parallel steps**, **any-one-of (pool)**, **all-must (unanimous)** and **quorum (M of N)** completion rules; none of the four exists.
8. An approver must be resolvable as **a named person, a role, a role-within-a-scope (department/branch/cost-centre), a reporting-hierarchy hop, or a rule expression** — today it is only an employee id derived from `reporting_manager_id` (`ApprovalService.php:320–329`).
9. The engine must support **amount bands with more than one boundary** (e.g. ≤₹10k manager, ≤₹1L GM, >₹1L director), because the current rule is a single `>=` against a single literal threshold and only three of nine types can escalate at all (`:332`).
10. Thresholds, and the currency they are expressed in, must be **per tenant and per request type** — currently three literals (`ApprovalService.php:30–32`).
11. The engine must support **conditional routing** on any request field or employee attribute (department, grade, branch, employment type, sub-type), not only on amount.
12. **Delegation** must exist: an approver must be able to nominate a delegate for a date range, and the engine must route to the delegate and record both identities. No such concept exists today.
13. **Reassignment** of a pending item to another approver, with a reason and an audit entry, must be possible. Today `approver_id` is never rewritten.
14. **SLA support is mandatory**: a per-step due date, a reminder schedule (N reminders at configurable intervals), and a breach action (escalate to the next step, notify a watcher, auto-approve, auto-reject, or do nothing). Today there is **no `due_at`, `sla_`, `escalate_at` or `reminder_sent_at` column anywhere**, and `routes/console.php:140–262` registers twelve schedules of which **none** touches pending approvals — so a request assigned to an approver who never acts sits Pending forever, silently.
15. When approver resolution yields **no approver**, the engine must fail loudly — raise an exception, route to a configured fallback queue, or notify an administrator — never create the record silently with a null approver and no notification (`RequestController.php:571` skips the email; the inbox filter `approver_id = $myId ?? -1` can never match it).
16. Approver resolution must be **re-evaluated when the routing inputs change** (amount edited, employee transferred), because escalation is currently computed once at creation.
17. The engine must enforce an explicit **state machine** with declared transitions and guards. Today `decide()` never checks the current status, so an approved request can be re-approved indefinitely, re-firing transfer orders, increment letters and decision emails (`RequestController.php:1330–1525`).
18. **Cancel / withdraw by the requester** must be a first-class transition, with a configurable rule for whether it is permitted after the first approval. It does not exist for requests or leave; `leaves.status` is an ENUM of three values that `ensureCols()` never widens.
19. Decision **remarks must be append-only per transition**, not a single overwritable column — today a blank re-decide erases the prior decision's stated reason (`:1408`).
20. The engine must support **pre-conditions on a transition** expressed as rules (compliance score ≥ N, certification valid, no open grievance, evidence attached, another party has confirmed), each independently configurable as **block / warn / off**. Today there are three bespoke `if` blocks: the DRA gate is tri-state (`:1371–1394`) while compliance and grievance are hard-coded warn-and-allow (`:1416–1454`).
21. A **two-stage "confirm then approve"** pattern (the Accounts gate) must be expressible as an ordinary configured step for **any** request type, not hard-coded for one (`:1359–1364`).
22. **Segregation of duties must be enforceable**: the engine must be able to require that the actor of step 2 is not the actor of step 1. Today `admin` and `super_admin` satisfy both `isManager()` and `isAccounts()` (`RequestController.php:52–61`), so one person can confirm the money and approve the payment.
23. **Post-transition actions must be declarative**, not `if ($module === '…')` blocks: generate a document, send a notification, call a service, update a record, create a follow-on request. Today only transfers and increments have actions, hard-coded at `:1465–1492`.
24. Every workflow action must be **idempotent or guarded**, so re-entering a state cannot re-send a letter that was already sent.
25. A **lock/unlock** capability must exist with an authorised unlock-with-reason path. Today `locked_at` is terminal and there is **no unlock endpoint anywhere in the codebase**.

### 10.3 Leave engine

26. Leave must be modelled as a **balance ledger with dated transactions** (accrual credits, opening balances, consumption debits, adjustments, encashment, lapse), not recomputed on demand from `leaves` rows, because the current derivation has no reservation, no locking and no approval-time re-check (§7 Q5).
27. **Accrual must be configurable per leave type**: frequency (monthly / quarterly / annual / on-completion-of-service), amount, accrual date, rounding rule, and whether it accrues during unpaid leave or probation. Absent today — the full annual quota is available from 1 January.
28. **Carry-forward must be implemented**, with a cap (days or percent), an expiry window, and a lapse action. Today the flag is collected in the UI and **never appears in the arithmetic** (`LeaveController.php:239`).
29. **Encashment** must be supported: eligibility rule, maximum encashable days, the valuation basis (basic / gross / CTC / a fixed day-rate), and the payroll component it becomes. Absent — "Leave Encashment" is only a bonus-module label.
30. **Proration on joining and on exit** must be configurable per type. Absent.
31. **Half-day leave must be supported** (first half / second half), and the engine must support a configurable **minimum unit** per type — day, half-day or hour. Absent; `leaves.days` is `decimal(4,1)` and nothing ever writes a fraction.
32. **Day counting must be configurable per leave type**: calendar days, working days, or working days excluding public holidays. Today it is always inclusive calendar days (`:338`), which charges 4 days for a Fri–Mon leave while payroll credits 2 (DEF-LVE-06).
33. The engine must support a **sandwich / clubbing rule for leave-balance deduction** — an off-day or holiday bracketed by leave counts as leave — independently of the existing payroll-side `sandwich_rule` (which is an *attendance* rule, default off).
34. **Blackout periods** must be definable (date ranges, per department/branch/type) during which applications are refused or require a higher approver. Absent.
35. **Minimum-staffing checks** must be supported: refuse or warn when approving would take a team below a configured headcount or percentage on any covered date. Absent, along with overlap detection and any team calendar.
36. **Compensatory-off earning** must exist as a rule: work on a week-off or holiday, beyond N hours, credits M comp-off days, expiring after P days. Today only a *type named* "Comp-Off" exists, with no earning mechanism.
37. **Eligibility rules per type** must be configurable — minimum service, probation status, gender, grade, employment type — with the entitlement itself variable by grade or service band. Today there is one flat `days_per_year` per type per tenant.
38. **Application rules** must be configurable: minimum advance notice, maximum consecutive days, maximum applications per period, and whether backdating is permitted (and how far). Today `from`/`to` are free-form strings with **no past-date check and no locked-period check** (`:274–280`).
39. **Negative balance / advance leave** must be a configurable allowance with a limit, rather than a hard refusal (`:354`) that is trivially bypassed by naming an unrecognised type.
40. The leave type applied for **must be validated against the tenant's configured types** and stored as a **foreign key**, never as a free-text string. Today `type` is `nullable|string|max:60`, is never checked, and an unmatched value skips the entire quota check (SEC-06) while becoming an invisible orphan bucket.
41. The **leave year basis must be configurable** (calendar / financial / anniversary-of-joining). Today it is `now()->year`, while requests and payroll use April–March (DEF-LVE-08).
42. A leave spanning a year boundary must **split its consumption across both years**, not attribute all of it to the from-year (`whereYear('from_date', …)` at `:214`).
43. Renaming a leave type must **not** affect historic consumption — which follows automatically from requirement 40.
44. The **paid/unpaid classification must reach payroll reliably**, with an integration test asserting that an approved unpaid leave produces an LOP day. Today `leaves.type_id` is never written, so payroll's `paid` test is always `true` and Loss-of-Pay leave is paid (DEF-LVE-02, CRITICAL).
45. **Leave cancellation and withdrawal** must exist as modelled transitions with balance restoration, including cancellation of an already-approved future leave.
46. **Attachments** must be supported per type, with a rule such as "a medical certificate is required for sick leave over N days".
47. A **team leave calendar** and **overlap warnings** at approval time must exist.
48. **Balance enforcement must be transactional and re-checked at approval**, because today two simultaneous applications can both pass a check that is performed only at submission, with no lock and no unique constraint.
49. **One balance figure must be published everywhere.** Today the leave module publishes `ent − used`, enforces `ent − used − pending`, and ESS publishes `ent − used` under a different key name (DEF-LVE-04).
50. Any leave-type default seed must exist in **exactly one place**; there are currently three divergent hard-coded lists (DEF-LVE-05).

### 10.4 ESS

51. Every ESS transaction must derive its subject **from the session**, never from a request parameter — the current `EssController` does this correctly and the pattern must be preserved and extended to the request modules (SEC-02).
52. **Which fields an employee may self-edit must be configuration**, per tenant, per field. Today it is a literal validation array (`EssController.php:266–277`).
53. Self-edits must support an optional **maker-checker step** — the change is proposed and applied only on HR approval — configurable per field.
54. **Changes to email and mobile must require verification** (OTP or a confirmation link to the *existing* address), because payslips and decision emails are delivered to `employees.email` and it is editable instantly with no confirmation (SEC-08).
55. **Every ESS self-edit must be audited with old→new values.** Today `updateProfile()` makes no audit call of any kind (DEF-ESS-02).
56. Date fields must be validated as dates. `dob` is currently `nullable|string|max:20` and drives an automated action (the greeting sweep).
57. ESS content, list sizes and section visibility must be configurable; the leave-type display defect (`EssController.php:155` always renders `'Leave'`) must not recur — it follows from requirement 40.
58. ESS write endpoints must be **rate-limited**.

### 10.5 Notification engine

59. **Every notifiable event must be a registry entry**, not a hard-coded call site: event key, description, available merge variables, default channels, default audience.
60. **Audience must be a rule**, not a literal: requester, approver, approver's manager, a named role, a department, a watcher list, an external address — configurable per event per tenant.
61. **Templates must be data**: per tenant, per event, per channel, per language, with a merge-variable catalogue and a preview. Today every subject, heading and detail-line label in this area is a PHP literal (§7 Q7).
62. **Channel selection must be per event and per tenant**, and the engine must actually be able to reach WhatsApp — a fully seeded 17-template tenant library exists today with **no consumers** (DEF-NOT-04).
63. **Per-user notification preferences and a working unsubscribe** must exist for non-mandatory notifications. Absent entirely.
64. **Push notifications must be implemented end to end.** Today the token is stored and there is **no sender anywhere in the codebase** — exhaustive grep for `fcm|firebase|push_token|sendPush` returns three lines, all in `MobileDeviceController`.
65. **Delivery must be per-recipient tracked** for every channel, with status, error and retry, and the record must be visible in the UI. Today SMS/WhatsApp broadcasts write **one aggregate row**.
66. **All bulk sending must be asynchronous and batched.** Today a broadcast makes one 15-second-timeout HTTP call per recipient inside the request (DEF-NOT-09).
67. **Queue configuration must be validated at boot or health-checked.** Today `->onQueue('mail')` silently strands every notification email if the worker was started without `--queue=mail` (DEF-NOT-11).
68. **No notification path may bypass the engine.** The late-arrival email uses `Mail::raw` on the global mailer, so it uses the wrong SMTP and leaves no delivery record (DEF-NOT-03).
69. **Provider integrations must be real adapters** with a per-provider payload contract and an **egress allowlist** on the endpoint URL; the credential must never be sent to an unvalidated host, and never under three field names at once (SEC-12).
70. **Country code and phone normalisation must be configuration**, not the literal `'+91'` with a last-10-digit truncation that silently misroutes every non-Indian number (`WaService.php:117`, `:148`).
71. **Retry and backoff must be per channel and configurable**; WhatsApp currently has none.
72. **Provider credentials must be encrypted at rest** (SEC-14), and **credential-grade values must never be written to a message log** — `wa_log.body_values` currently stores plaintext temporary passwords forever (SEC-17).
73. **Inbound webhooks must be authenticated, signature-verified, throttled, tenant-scoped and schema-parsed.** The Interakt webhook is none of these and infers status by substring-scanning the whole payload (SEC-04).
74. **Retention must be implemented and enforced per log table**, configurable per tenant, defaulting to the published commitment. Nothing prunes anything today (SEC-18).

### 10.6 Greetings and scheduled communications

75. **Occasions must be extensible** (birthday, work anniversary, probation confirmation, festival, milestone, custom date field), not two hard-coded blocks.
76. **The send hour must be evaluated in the tenant's timezone**, which must be a tenant attribute — today it is the server's hour despite a docblock claiming otherwise (DEF-NOT-05).
77. The **merge-variable catalogue must be extensible**, including custom employee fields; today it is a fixed set of five.
78. The candidate query must be **sargable** — store a computed `birth_md`/`joining_md` column or an indexed generated column, rather than `DATE_FORMAT(col, …)` on the left-hand side (TD-13).
79. **29 February must be handled** by a configurable rule (greet on 28 Feb or 1 Mar in common years).
80. The **disciplinary late-arrival template must not live inside the Greetings configuration object** (DEF-NOT-07).
81. Configuration payloads must be **schema-validated**; `saveConfig` currently accepts any array and merges arbitrary keys (NEW-NOT-113).
82. **Read endpoints for HR configuration must be role-guarded**; `GET /app/greetings/config` and `/log` are not (SEC-13).

### 10.7 Audit and governance

83. **Every business transaction must record old→new values** in a structured, queryable form. Today `AuditTrail::change()` exists and has **no call site** in this entire area; the only diff that exists is an English sentence in a varchar(1000) outside the hash chain (§7 Q8).
84. **All writes to the audit ledger must go through one API.** Four code paths currently insert directly, and because `verify()` resets `$prev` to `'GENESIS'` at any row with a null hash, each of them **silently destroys the tamper-evidence of everything before it** (SEC-15, CRITICAL).
85. **The chain must be per tenant** and appended **transactionally with a lock**, so concurrent writes cannot produce false tamper alarms (DEF-AUD-02).
86. **Verification must be incremental and checkpointed**, not a full-table load (`Audit::verify():111`).
87. **Actors must be recorded as user ids** with a denormalised name for display, never as a name string alone (DEF-AUD-04).
88. **Audit coverage must be complete for money-moving and privacy-affecting actions**: commission edits after approval, payments, locks, Accounts decisions, deletions, salary payments, broadcasts, template changes, device approvals, and **ESS self-edits** — none of which reaches the ledger today (§7 Q8).
89. **Hard deletes must be forbidden** in favour of soft deletion with an audit entry; `cleanOrphanCommissions` deletes rows from three tables with no record (SEC-22).
90. **Audit failures must be alerted**, not silently swallowed (`Audit.php:97–99`).
91. **Read auditing** must exist for sensitive views (payslip, ledger, leave balances, another employee's record).
92. **Retention and legal hold** must be configurable and enforced, reconciled with the published privacy policy.

### 10.8 Platform-level requirements this area forces

93. **Authorisation must fail CLOSED.** `denyUnlessRole()` deliberately returns `null` (= allowed) on any exception, across **71 call sites in 30 files** (SEC-05). The correct pattern already exists elsewhere in the same codebase (`abort_unless` in `WaTemplateController::scope()` and `MobileDeviceController::guard()`).
94. **Read endpoints must be authorised server-side.** `FeaturePermissions.php:9–13` states the current model outright — the menu is gated **client-side** and only writes are gated server-side — which is the root cause of SEC-01.
95. **Row-level scoping must be a framework concern**, applied by default, not a per-endpoint `when()` that exists for one module out of nine.
96. **Tenant scoping must be enforced by the data layer**, not by ad-hoc `when($tid, …)` clauses that are omitted in at least six places in this area (`ApprovalService.php:321`, `:344`; `RequestController.php:31–43`; `LeaveController.php:212`; `EssController.php:205`; `WaTemplateController.php:414`; `SendMessageController.php:165–168`; `MobileDeviceController.php:182`).
97. **A user with no tenant must never be silently attached to an arbitrary tenant** (`RequestController.php:381`, `:630`; `LeaveController.php:272`).
98. **Employee identity must resolve deterministically** from an explicit link, never by a name match with `->first()` and no ordering (SEC-23).
99. **No runtime DDL.** Schema changes belong in migrations; the "self-creating convention" must not survive (TD-01).
100. **Internal exception messages must never reach the client** (~25 endpoints in this area, SEC-16).
101. **Every business rule in §3 must have an automated test.** This entire area currently has **one** unit test file with four assertions, all on greeting template rendering (TD-12).
102. **Uploaded-file paths must be canonicalised and confined**, not prefix-checked (SEC-03), and upload endpoints must be role-guarded and rate-limited (SEC-19).
103. **A device gate, if marketed as a security control, must be enforced server-side by middleware** — or the claim must be withdrawn (SEC-09).

---



---

# SOURCE: F-statutory-compliance.md

## 10. SmartPRS 3 requirements implied

### Statutory content and versioning
1. Statutory parameters must live in a **versioned, effective-dated content pack** that Ametecs publishes independently of an application release. A PT slab change must be shippable as data, not as PHP (today it is `private const PT_STATE_SLABS` — see Q9).
2. Every statutory parameter must carry `(jurisdiction, effective_from, effective_to, source_reference, verified_by, verified_on)`. No parameter may be presented as legally correct without a recorded verification.
3. The rules engine must support **as-of resolution on the payroll period date, never on "now"** — including for every consumer, not just the payslip (DEFECT-F-25).
4. A payroll run must **snapshot the complete resolved rate set** into the run and into each payslip, and re-running a period must reuse that snapshot unless a supervisor explicitly re-resolves (DEFECT-F-03).
5. Every rate change must be **append-only, attributed, timestamped, diffed and audited** — the legacy blob's `updateOrInsert` with no trail must not survive (DEFECT-F-02, SEC-F-03).
6. A change whose effective date falls in a finalised period must be **refused, not fail-open** (DEFECT-F-37).
7. The impact preview must be retained and extended to the **actual** payroll basis (components, LOP, commission), not full CTC.

### Jurisdiction and applicability
8. A first-class **Establishment** entity: legal entity → establishment → branch, each carrying its PF code, ESI code, PT registration, LWF registration, S&E registration, state and district. Statutory applicability must resolve from the establishment an employee is posted to.
9. **Per-scheme applicability determination per employee per period**, with a recorded reason: voluntary coverage, international worker, excluded employee, exempted establishment, above-ceiling exclusion, apprentice. `pf_applicable` / `esi_applicable` must actually drive the engine (DEFECT-F-01), and migrating the existing populated values will change payroll outcomes — a reconciliation report is mandatory before cutover.
10. Scope resolution must key on **structured identifiers** (state code, establishment id, branch id), not free-text substring matching (`looseMatch`, `str_contains`).
11. A jurisdiction must be able to **remove** an inherited rule, not only add one (today a built-in PT-free state cannot be re-declared free once a slab exists, and a built-in PT-free state cannot be removed from the list at all).

### Formula engine
12. The engine must express, as configuration: a **wage base** built from component flags (not component *names* — today DA is found by `str_contains($name,'dearness')` and the ESI lock by `stripos($k,'esi')`); a **ceiling**; a **rate**; a **rounding rule per contribution** (ESI needs round-up, PF needs 2dp — today one is `ceil` in the engine and `round` in the challan, DEFECT-F-05); and an **employee/employer split**.
13. It must support **contribution sub-splits** (EPS/EPF/EDLI) with their own independent ceilings and caps, and **administrative charges** with percentage-or-minimum semantics (A/c 2's 0.5%-or-₹500 shape is expressible nowhere today).
14. It must support **period-locked eligibility** — a determination fixed at a period boundary and carried forward (the ESI half-year rule) — as a generic construct, driven by a structured flag on the prior payslip rather than by matching a display label.
15. It must support **slab tables with a month/period modifier** (the Maharashtra February ₹300 uplift must be a data attribute of the slab, not `if ($key === 'maharashtra')` in PHP).
16. It must support **attribute-based exemptions** (gender, age, disability, category) as declarative predicates with a configurable ceiling — the Maharashtra female exemption is one instance of a general pattern.
17. It must support **periodicity other than monthly**: half-yearly (TN, Kerala PT), annual caps (MP, MH ₹2,500), and June/December-style LWF remittances. Today every slab is coerced to a "monthly equivalent" and annualised as `× 12`, which is wrong for at least four states (DEFECT-F-29).
18. Malformed configuration must **fail loudly**, never silently produce zero (DEFECT-F-24), and no test may assert silent-zero as correct behaviour.
19. Exactly **one** implementation of each formula. Today PT/PF/ESI/TDS each have 3–5 implementations (PHP engine, PHP challan, JavaScript SPA, hard-coded help prose, extracted test copy) that disagree (DEFECT-F-05, -F-27, -F-28, -F-34). The SPA must render server-computed figures.

### TDS engine (build, do not port)
20. Both **Old and New regimes**, with a per-employee, per-FY regime election, a switch-date rule, and default-regime handling.
21. An **investment-declaration** and **proof-submission** lifecycle with per-section limits (80C, 80D, 80CCD(1B), 24(b), HRA…), a declaration window, a proof window and a lock date.
22. **HRA exemption** computed from actual rent, metro/non-metro status and Basic+DA; **LTA**; **perquisites**; **other income**; **previous-employer income and TDS**.
23. **Cumulative year-to-date TDS with monthly true-up**, plus surcharge bands with their own marginal relief. Today TDS is `annual ÷ 12` in every month with no true-up.
24. **87A marginal relief must be applied inside the tax function**, once, so every consumer agrees (DEFECT-F-06).
25. **Form 16 Part A/B, Form 12BA, Form 12BB** and a real **24Q** derived from actual deductions and deposits — with filing status recorded, never assumed (DEFECT-F-07).
26. Deductee-level 26Q with challan reference, BSR code and deposit date.

### Statutory calendar and returns
27. A **compliance calendar** driven by the content pack: PF ECR, ESI contribution, PT payment (per state), TDS deposit, quarterly returns, LWF remittance, annual returns — each with a jurisdiction-specific due date, an owner, a reminder ladder and a filed/not-filed state. Today four hard-coded 24Q dates are the entirety of it (DEFECT-F-42).
28. Every statutory output (challan, ECR, return) must be generated **from stored payroll results**, never recomputed from CTC (DEFECT-F-04), and must reconcile line-for-line to the payslips it claims to summarise.
29. The system must **never assert a filing status it has not observed** (DEFECT-F-07, SEC-F-05).

### Gratuity, bonus, minimum wages
30. Gratuity: one formula, configurable divisor (15/26 vs 15/30), configurable eligibility years, a configurable ceiling, covered/non-covered establishment handling, a link to full-and-final settlement, and a single accrual basis (today 15/26 in the report and 4.81% on the payslip are unreconciled).
31. Bonus: configurable eligibility ceiling, bonus-wage cap, minimum and maximum percentage, set-on/set-off, allocable surplus, **and a payment path** — a register that never pays is not a bonus module (BR-STAT-015).
32. The bonus wage cap must resolve against the **matched** minimum wage when higher, as the product's own UI text already promises.
33. Minimum wages must match on **(state, zone, category, skill level, effective date, active status)** — the register already carries these columns and the consumer ignores every one (DEFECT-F-17) — and compare against **Basic+DA**, not CTC/12.

### Compliance module
34. **Customer-definable compliance item types.** An item type carries: name, applies-to predicate (role, department, engagement type, on-roll/off-roll, state), validity source (issue date + validity period, or an explicit expiry), renewal lead time, evidence requirement, alert ladder, and gate behaviour. Today DRA/PCC/BGV are a closed set of PHP literals (BR-CMP-006).
35. **Configurable alert ladders per item type** — bucket boundaries, window, escalation path — persisted, not a per-request query string with no upper bound (BR-CMP-005, DEFECT-F-18).
36. **Configurable compliance scoring**: weights and pass conditions per item type, with a **single** definition of "valid" shared by the score, the gate and the report (DEFECT-F-08, -F-12). A missing table must never award points (BR-CMP-003b).
37. **Evidence attachment** on every compliance record, with the file stored **privately** and served only through an authorised route (SEC-F-02), plus who uploaded it and when.
38. **A renewal workflow**: raise a renewal task at the lead time, assign it, notify **the agent as well as HR**, track submission, and record the outcome.
39. **Configurable consequences of non-compliance**, per item type and per action: warn / block / require override-with-reason. The gate points must extend beyond money to allocation, attendance and field work. No gate may **fail open** (SEC-F-04).
40. **Versioned policy acknowledgement**: policy → version → text → publication date → per-user acknowledgement (with version, timestamp, IP, user agent) → **history of all acknowledgements** → automatic re-acknowledgement demand when the version bumps. `policies.ack_required` must be honoured (BR-CMP-016, DEFECT-F-11, SEC-F-06, SEC-F-07). Policy text must be **tenant-owned**.
41. Compliance reports must be **generated from observed evidence only**. No literal may be printed as a finding (DEFECT-F-09). Report generation must itself be **audit-logged**, and any integrity marker must be a hash **of the report content**, chained, or must not be claimed at all (DEFECT-F-10).
42. **Off-roll and vendor agents must be first-class subjects** of the compliance model, with structured DRA/PCC records, and their payouts must run through the same statutory treatment as on-roll commission — 194H TDS, PAN/no-PAN, GST on vendor invoices (BR-CMP-029, DEFECT-F-44).
43. Compliance data must be **role-guarded** (SEC-F-01) and every register **indexed** (DEFECT-F-38).
44. Every register in this area needs a **real migration** with indexes, constraints and foreign keys. Runtime schema creation must not survive into SmartPRS 3 (DEFECT-F-35, -F-38, -F-43).

### Deployment
45. Statutory content updates must reach on-premise installs **without an application release and without an on-site admin pressing a button** — see Q9. This is the single most important non-functional requirement in this area.

---



---

# SOURCE: G-security-tenancy-api.md

## 10. SmartPRS 3 requirements implied

Numbered, testable, and traceable to the finding that motivates each.

**Authorization model — functional permission separate from data scope**

1. The system MUST express authorization as a triple `(actor, capability, data-scope)` evaluated
   server-side on every request. A capability is a functional verb on a resource
   (`payroll.run.approve`); a data scope is an independent axis (`self` | `reporting-line` |
   `team` | `department` | `branch` | `company` | `tenant` | `platform`). Today the two are fused
   into one role-name string and the only scope that exists is `selfScope`
   (`AppDataController.php:224-232`). *(D-05, SEC-09, BR-TEN-009)*
2. Roles MUST be data, not code: a customer administrator MUST be able to create a new role, name
   it, grant it capabilities and assign it a data scope, without a deployment. Today the role set
   is hard-coded in five disagreeing registries. *(D-07)*
3. Roles MUST be scoped per tenant. Today `config/permission.php:138` sets `teams => false`, so
   one `roles` row named `admin` is shared by every customer. *(BR-TEN-013)*
4. A user MUST be able to hold more than one role, and the effective permission set MUST be the
   union. Today `syncRoles([$one])` enforces exactly one. *(§4.3 of the doc, confirmed)*
5. Every authorization decision MUST **fail closed**: an exception during evaluation MUST produce
   503 (or 403) and MUST emit a log line and an alert. Today six controls return "allowed" from
   `catch (\Throwable)`. *(SEC-01, BR-SEC-001)*
6. Authorization MUST be enforced at a layer that cannot be omitted — route middleware or a
   policy invoked by a base controller — not by a hand-written line inside each method. The test
   for this requirement is: *adding a new route with no explicit permission declaration MUST deny
   by default.* Today 78 authenticated routes have no check because nobody wrote one.
   *(SEC-02, §7.3)*
7. Client-side gating MUST be presentation only, and MUST be generated from the same server-side
   catalogue, so client and server cannot drift. Today `PERM_NAV` is a hand-maintained JS map in a
   12,533-line controller. *(D-14, SEC-02)*
8. The permission matrix MUST be stored in relational tables with referential integrity and an
   audit trail — never in a user-writable JSON blob. *(SEC-03)*
9. An unknown role, a missing role, or an unmapped capability MUST deny, and MUST raise an
   operational alert. Today an unmapped role renders as `Admin` (`AppController.php:93`), an
   absent matrix row returns `true` (`:760`), and an unknown capability degrades to
   `admin`+`hr_manager` (`FeaturePermissions.php:102`). *(BR-SEC-002/003/004)*
10. Role assignment MUST be bounded by the assigner's own authority: a role holder MUST NOT be
    able to grant a capability it does not itself hold, nor set the credentials of a user holding
    a superior role. *(SEC-07)*

**Tenancy**

11. Tenant scoping MUST be enforced centrally — a global query scope, a repository boundary, or
    row-level security — such that a query written without a tenant predicate returns **nothing**,
    not everything. The acceptance test: deleting the tenant filter from any one query MUST break
    a test, not silently widen the result set. *(SEC-04, BR-TEN-001/002)*
12. `NULL` and `0` MUST NOT mean "all tenants". Cross-tenant access MUST require an explicit,
    audited, capability-gated `platform.cross_tenant` grant — never the absence of a value.
    *(BR-TEN-002, SEC-05)*
13. Every tenant-owned table MUST have `tenant_id NOT NULL` with a foreign key, and every insert
    MUST be stamped by the framework, not by hand. *(BR-TEN-012)*
14. There MUST be exactly one tenancy key. Today `tenant_id` (live) and `company_id` (inert)
    coexist, and `companies` is a sub-entity of `tenants` while the model layer treats a Company
    as the tenant. *(D-01, D-02)*
15. The company/branch/department hierarchy MUST be a first-class data-scope dimension, because
    the current product already sells "master + subsidiary companies" and "branches" while having
    no way to scope a user to one.

**Authentication**

16. Password policy MUST be configuration: minimum length, character classes, history depth,
    maximum age, and a breach-corpus check, settable per tenant, with a stricter floor for
    platform accounts. Today it is `min:8` (and `min:6` for Super Admins) in six files.
    *(BR-AUTH-010/011)*
17. Account lockout MUST exist and MUST be configurable: N failures in a window per **account**
    (not only per IP) → temporary lock, with an unlock path and an audit record.
    *(NEW-SEC-110, BR-AUTH-021)*
18. MFA MUST be supported (TOTP at minimum) and MUST be enforceable per role — mandatory for any
    role holding a payroll-approval, user-administration or cross-tenant capability.
    *(NEW-SEC-110)*
19. Session policy MUST be configuration: idle timeout, **absolute** maximum lifetime,
    remember-me duration (or its prohibition), and concurrent-session limits. Cookies MUST be
    `Secure` by default and session payloads MUST be encrypted at rest.
    *(NEW-SEC-107/108/109)*
20. All authentication failure responses MUST be uniform — no account-existence or
    account-status oracle. *(BR-AUTH-019, SEC-13)*
21. Every credential-bearing artefact (reset link, invite, offer link, NDA link, self-onboarding
    link, agent-earnings link, quote link) MUST be hashed at rest, MUST carry a configurable
    expiry, and MUST be individually revocable. *(NEW-SEC-105, SEC-17)*
22. Temporary passwords MUST NOT be transmitted by email. Provisioning MUST use a one-time,
    short-lived set-password link only. *(SEC-16)*

**Audit**

23. Every identity and access event MUST be audited: login success **and failure**, logout,
    role grant/revoke, user create/disable/delete, password set by another party, staff CRUD,
    tenant provisioning, capability-matrix change, cross-tenant access. Today there are 11
    `Audit::record` sites and only login is covered. *(SEC-21, D-18)*
24. Read access to sensitive data classes (salary, PAN/Aadhaar/UAN, bank details, uploaded ID
    documents) MUST be audited, because the highest-impact findings in this system are read paths
    that leave no trace. *(report §1, confirmed)*
25. The audit chain MUST be per tenant and MUST be append-only and verifiable in bounded memory.
    Today it is one global chain loaded entirely into PHP on any admin's click.
    *(NEW-SEC-114, SEC-28)*

**HTTP surface**

26. The route surface MUST be declarative: every route MUST declare its required capability and
    data scope at the routing layer, and the build MUST fail if any route omits it.
    *(SEC-02, §7.3)*
27. The unauthenticated surface MUST be an explicit allow-list, reviewed as a unit. It is 78
    declarations today, spread across four files and three `if` blocks.
28. Machine-to-machine endpoints MUST carry a credential. The `ApiKeyAuth` / `SmarteptSignature`
    pattern (hashed key, `hash_equals`, scope check, tenant binding, uniform errors) is the
    correct model and MUST be extended to the device-push path, which today accepts a
    caller-chosen serial number as its only identity. *(report #5, confirmed)*
29. No request handler may execute DDL. All 155 runtime `Schema::create`/`Schema::table` calls
    MUST become migrations, and the production database user MUST NOT hold DDL grants.
    *(D-11, §2.2)*
30. CSRF exemption MUST require a declared compensating control recorded at the route, and the
    build MUST reject an exemption without one. *(§7.5, `/webhooks/interakt`)*
31. Rate limits MUST be configuration, not 34 literals, and MUST be keyable on identity (account,
    tenant, API key, device serial), not only on IP. *(BR-AUTH-021)*

**Files**

32. Uploads MUST be validated by MIME type and magic bytes, never by the client-supplied
    filename, and MUST be stored on a private disk served exclusively through an authenticated,
    tenant- and ownership-scoped controller. Client-supplied paths MUST never be stored or
    dereferenced. *(SEC-23, SEC-24, BR-SEC-015/016/017/018)*

**Testing and release**

33. There MUST be a generated authorization regression suite asserting, for every route × role
    pair, the expected allow/deny. Today `tests/Feature/AuthAccessTest.php` contains three
    assertions and zero negative role tests. *(D-15)*
34. No production artefact may contain a seeder, command or scheduled task that creates an
    account with a known password. Every credential-creating path MUST require an
    environment-supplied secret and MUST refuse to run under `APP_ENV=production`.
    *(SEC-19, SEC-20)*
35. Commercial gating (edition, plan, module) MUST be enforced server-side by the same
    authorization layer as roles. Today plan gating is dead code
    (`AppController::applyPlanGate` returns immediately) and edition gating is retired
    (`Edition::blockedPatterns()` returns the SaaS-only list for every level).
    *(D-09, D-10)*

---



---

# SOURCE: H-recruitment-hrops-reports.md

## 10. SmartPRS 3 requirements implied

Numbered, testable, and each traceable to a rule or defect above.

### Entity / metadata engine

1. The platform must support a **customer-defined entity (master) registry**: a tenant admin can create a new master type — name, table/collection, label, plural, icon, menu placement — without a code release. *(NEW-MST-101: 58 types are PHP literals.)*
2. Each entity must carry **field definitions as data**: key, label, data type, precision/length, required-ness, default, visibility, edit-ability, help text, and display order. *(NEW-MST-101, NEW-MST-104.)*
3. The field-type vocabulary must include at minimum: string, long text, rich text, integer, decimal *with configurable precision and scale*, money *with a configurable currency and rounding rule*, boolean, date, datetime, time, enumeration *(customer-defined values)*, reference-to-entity, reference-to-employee, file, and geo-coordinate. *(Today every decimal is `DECIMAL(12,2)` and every string `VARCHAR(255)`.)*
4. The engine must support **declarative field validation as configuration**: required, min/max, length, regex/format, allowed-value set, cross-field comparison, and uniqueness within a scope. *(There is none today beyond required-ness.)*
5. Reference fields must resolve by **id**, with name and code accepted only as lookup conveniences, never as the stored key. *(NEW-MST-109, DEFECT-REC-03.)*
6. The engine must support **per-entity, per-operation permissions** (read / create / update / delete / import / export), assignable to roles. *(NEW-MST-104, NEW-MST-105: one role set for writes on 58 entities, and reads are ungated.)*
7. Deletion policy must be **declared per entity** (soft, hard, archive, forbid) and must not be inferred from schema. *(BR-SEC-402.)*
8. The engine must enforce **referential integrity on delete**: block, cascade, or null, declared per relationship. *(Deleting a department today leaves `employees.department_id` dangling and `employees.department` untouched.)*
9. **No DDL may execute inside a request.** All schema change must be a versioned, reviewable, forward-only migration applied out of band. *(28 migration-less tables; `ALTER TABLE` inside the WhatsApp send loop and on public token pages.)*
10. Every field on every entity must be **indexable by configuration**, and every field the engine filters, joins or sorts on must be indexed by construction. *(§2.4.)*
11. Every entity write must be **audited with before/after values**, not just changed field names. *(NEW-MST-110 records field names only.)*
12. Multi-company attribution must be **explicit**: an entity that is company-scoped must require a company, never silently default to "the tenant's first company". *(NEW-MST-108.)*

### Workflow / pipeline engine

13. The workflow engine must support a **customer-defined stage list per process** (recruitment pipeline, onboarding, exit, performance cycle) with stage name, order, colour, and a terminal/non-terminal flag. *(NEW-REC-101.)*
14. It must support an **allowed-transition matrix** with per-transition conditions, required fields, and required approvals. *(BR-REC-006: any stage to any stage.)*
15. It must support **side effects bound to a transition** (create an employee, open onboarding, reconcile a requisition, send a letter, notify), declared as configuration rather than scattered across call sites. *(BR-REC-017 is called from four places and never from `moveStage`.)*
16. It must support **guard conditions that actually block** — a requisition that is not approved must be un-attachable, an interview outcome of `no_hire` must at minimum warn before an offer. *(BR-REC-004, BR-REC-020.)*
17. The approval engine must support **N-level, role-or-hierarchy-driven approval with value bands**, and must **re-open approval when a material field changes** on an approved record. *(BR-REC-002 is single-level admin-only; BR-REC-003 leaves openings and CTC freely editable after approval.)*

### Rule engine

18. The rule engine must support **event-triggered scoring rules** where the trigger event is chosen from a typed catalogue, never inferred from free text. *(BR-REC-036 buckets rules by `str_contains` on a name, so "Perfect attendance — no lates" is applied as a late penalty.)*
19. It must express a rule as **condition → quantity → multiplier**, e.g. "for each late arrival in the period, award N points", with the definition of *late* resolved from the **attendance engine**, not redefined. *(NEW-PTS-101: a hard-coded 09:30 that ignores Shifts, Late Policy and roster.)*
20. Any rule that reasons about a working period must obtain the **working-day calendar from one shared calendar service** honouring the tenant's `weekly_off_day`, `sat_off_mode` and holiday master. *(NEW-PTS-102: points assume Sunday-only.)*
21. Rule outputs must be **dated to the period they describe**, not the run date, and re-running a period must be transactional. *(NEW-PTS-103, NEW-PTS-104.)*
22. Rules must carry **effective-from/to dates and an active flag**, and must be scopable by company, department, grade or employee. *(`point_rules` has none of these.)*
23. The engine must support a **"warn / block / off" policy mode** per gate, resolved from configuration at the point of enforcement. *(A `dra_gate` setting with exactly these three values exists and is ignored by `agent-auth` — NEW-DEFECT-18.)*
24. Statutory threshold rules (e.g. "Basic+DA must be at least X % of wage gross") must take the **percentage, the sample basis and the component classification** from configuration. *(NEW-MST-114 hard-codes 50 %, ₹12 00 000 and a regex for "DA".)*

### Document / template engine

25. The template engine must support **customer-defined document types** — a customer can add "Appointment Letter", "Confirmation Letter", "Transfer Letter", "Show Cause" — each with its own issue screen generated from configuration. *(NEW-DOC-106: 7 types, 5 issuable, 1 registered-but-broken.)*
26. The **merge dictionary must be extensible**: any field on any entity in the model must be addressable as a placeholder, and the available placeholder list must be discoverable in the editor. *(BR-CMP-024: 21 fixed keys, unknown placeholders printed verbatim.)*
27. Templates must support **rich text** end-to-end, with sanitisation that preserves formatting. *(DEFECT-DOC-05: `stripHtmlDeep` destroys the seeded HTML template on first save.)*
28. Templates must be **versioned**, carry an **active/inactive state that is honoured**, and be selectable per company. *(BR-CMP-023 picks the newest and never reads `status`.)*
29. Issuing a document must **render and store an immutable artefact** — the merged content, a content hash, and the rendered PDF — at issue time. Re-printing must reproduce the stored artefact, never re-merge. *(DEFECT-DOC-03; `letters.pdf_path` shows this was designed and never built.)*
30. Document **layout must be configurable**: letterhead image, header, footer, margins, page size, orientation, fonts, signature block (including an uploaded signature image), and a per-company override. *(All fixed in `letter-pdf.blade.php` and `idcard-pdf.blade.php`.)*
31. Money and date **formatting in documents must be configurable** (decimals, lakh/crore grouping, amount-in-words, date format). *(BR-CMP-024 hard-codes `₹` + `number_format` with no decimals and `d M Y`.)*
32. ID card design must be a **configurable layout** (fields, size, orientation, front/back, QR or barcode). *(BR-CMP-027.)*

### E-signature engine

33. A signing link must have a **configurable TTL**, be **single-use** by default, be **revocable**, and **rotate on resend**. *(BR-CMP-022: none of these.)*
34. The signing page must display the **complete document being signed** — the merged body, all financial terms and all dates — never a summary card. *(DEFECT-DOC-01.)*
35. Signature must require **server-side validated consent**: a typed name matching a server-side expectation, an explicit consent record, and CSRF protection. *(FINDING-SEC-03.)*
36. Identity must be **proven before binding** — at minimum an OTP to the email or mobile on file. The pattern already exists elsewhere in the product (`SelfOnboardingController`'s public token routes implement OTP send/verify) and must be applied here. *(§4.6 item 11.)*
37. The evidence record must capture **document content hash, rendered artefact, timestamp, IP, user agent, the consent text version, and the verification method**, and must be written to the **tamper-evident audit chain**. *(FINDING-SEC-07: today it captures status, timestamp, IP and a typed name, and writes no audit row.)*
38. A token must resolve **only** to the document type it was issued for. *(DEFECT-DOC-02.)*
39. Public signing routes must be **rate-limited**. *(FINDING-SEC-17.)*

### Reporting engine

40. The platform must provide a **report builder over the entity model**: a user selects an entity, columns, filters, groupings, sort order and aggregations, and saves it as a named report. *(NEW-RPT-101: 14 hand-written queries.)*
41. Reports must be **shareable, permissioned, schedulable and deliverable by email**, with at least CSV, XLSX and PDF output. *(None of this exists; CSV only.)*
42. Statutory registers (Form B, Form D, Form E, bank advice, FnF working) must be **configurable statutory templates**, versioned by effective date, so an amendment is a configuration change. *(BR-RPT-005, BR-RPT-006, BR-RPT-007.)*
43. Every report must **paginate or stream**; no report may materialise its full result set in memory. *(BR-RPT-001: export passes `limit = null` and builds one PHP string.)*
44. Report row-level computations must **call the same engines the operational screens call** — the FnF report must use the payroll engine's salary structure, not a 50 % assumption; the compliance scorecard must read a pre-computed score, not issue five queries per employee. *(BR-RPT-006, BR-RPT-011.)*
45. Lookups against effective-dated statutory data (minimum wages) must **match on the applicable dimensions** — state, zone, category, effective date — not take a tenant-wide maximum. *(BR-RPT-004.)*
46. CSV output must be configurable for **encoding (BOM), delimiter and line ending**, and must apply **formula-injection escaping** by default. *(BR-RPT-013, FINDING-SEC-14.)*
47. Report execution and export must be **audited through the same audit service** as every other write. *(BR-RPT-012 bypasses it, in the report whose job is to verify the chain.)*
48. Report endpoints must return **correct HTTP status codes** and must never return a raw exception message. *(BR-RPT-014, FINDING-H-05.)*

### Configuration engine

49. All configuration must live in **one store** with a uniform contract: key, value, **scope** (platform / tenant / company / department / employee), **effective-from date**, and an **audit record of every change with before/after values and the actor**. *(Five parallel stores today; four of them unaudited and none but `statutory_configs` effective-dated.)*
50. Every configuration key defined in the platform must be **reachable through the UI**. A key that exists in defaults and not in the save contract is a defect the build must catch. *(NEW-CFG-102: six unreachable keys, two of them compliance-critical.)*
51. Numeric configuration must declare **minimum and maximum bounds** as part of its definition. *(NEW-CFG-103: `pf_rate = 9999` is accepted.)*
52. Configuration must support **versioning, diff and rollback**, and payroll recomputation must resolve values **as at the period being computed**, not as at now. *(No settings store is effective-dated except `statutory_configs`.)*
53. Secrets in configuration (SMTP passwords, provider API keys, device communication keys) must be **encrypted at rest** and never returned by a read endpoint. *(FINDING-H-02, FINDING-H-01.)*
54. Configuration read endpoints must be **role-gated**. *(FINDING-H-03.)*

### Branding and white-labelling

55. Branding must be resolvable **per company** and must cover: display name, tagline, logo, favicon/app icon, brand colour palette, document letterhead, document footer, and email sender identity. *(Today: display name, one colour, one logo, one tagline.)*
56. The public legal pages must be **content, not blades**, with a per-install override and a per-page effective date. *(NEW-LND-104.)*

### Content and help

57. In-product help must be **content in a store**, addressable by screen, versionable, editable by the vendor, overridable by a tenant, and **filtered by edition and by the modules the tenant actually has**. *(NEW-HLP-101: 120 screens of copy in a controller, identical for every tenant and edition.)*
58. Vertical content packs (training curricula, FAQs, Code of Conduct, letter templates) must be **installable, versioned, updatable and forkable per tenant**, with a clear "original vs edited" state so a pack update never overwrites a customer's edit and never leaks one customer's edit to another. *(BR-CMP-030, NEW-SEED-101: `kb_topics` is deduplicated by title across the entire installation and always written `tenant_id = NULL`.)*
59. Content presented for **legal acknowledgement** (Code of Conduct) must be immutable once acknowledged, versioned, and the acknowledgement must record which version was accepted. *(FINDING-SEC-13: any admin on the box can rewrite it.)*
60. Any content the platform describes as **mandatory with a validity period** must be enforced by a control — a due date, an alert and a compliance consequence — or must not be labelled mandatory. *(NEW-SEED-103: `mandatory` and `validity` are stored and read by nothing.)*

### Messaging and consent

61. Bulk messaging must be **queued, chunked, rate-limited, resumable and schedulable**, with a per-tenant send window. *(DEFECT-REC-06: 2 000 synchronous HTTP calls in one web request.)*
62. A **consent and suppression register** is mandatory: an explicit do-not-contact flag per person per channel, honoured by every audience resolver, plus inbound STOP handling. *(BR-REC-033: none exists.)*
63. Message templates must be **validated for variable arity** against the provider's registered template before a campaign can be sent. *(BR-REC-028.)*
64. The messaging provider must be an **adapter behind an interface**, with channel (WhatsApp / SMS / email) selectable per campaign. *(Interakt is hard-wired.)*
65. Phone numbers must be stored and matched in **E.164 with an explicit country code**, using one normalisation rule across the whole platform. *(DEFECT-REC-04: two incompatible rules.)*
66. Provider webhooks must be **authenticated** (signature or shared secret), **scoped to a tenant and a message**, and parsed against a declared schema. *(FINDING-SEC-16: string-searching the whole payload, matched by phone number across the installation.)*

### Identity, numbering and conversion

67. Employee, requisition and document numbering must be a **configurable sequence** — prefix, suffix, width, reset period, and per-company or per-branch sequences — allocated from a transactional sequence source, never derived from `COUNT(*) + 1`. *(BR-REC-014, BR-REC-001: count-derived, non-transactional, and reused after deletion.)*
68. Person identity must key on a **stable identifier**, never a name. *(DEFECT-REC-03: hire, onboarding and offer acceptance all key on name.)*
69. **Candidate → employee conversion must be a configurable field map** — the customer decides which candidate fields flow to which employee fields, which are mandatory before conversion, and what is collected at conversion time. *(See §11 Q3; today the map is 12 fields in code and everything else is lost.)*
70. Conversion must not silently create master data. Creating a designation from a free-text candidate position must be an explicit, confirmed action. *(BR-REC-015.)*
71. **Off-roll agents must be first-class workforce members** for compliance purposes: same certification registers, same expiry alerting, same audit file, whatever their payroll status. *(DEFECT-CMP-01.)*

### Cross-cutting platform requirements

72. **Tenant scoping must be enforced by the data-access layer**, not by remembering to add `when($tid, …)` at each call site. Six confirmed unscoped queries in this area alone (`PerformanceController` `:107`/`:129`, `KbController` ×4, `NdaSignController` `:44`/`:48`, `MasterController` `:653`, `HiringDriveController` `:322`, `RecruitMessagingController` `:191`/`:399-407`).
73. **Authorisation must be declarative and default-deny.** Six endpoints in this area are ungated by omission while their siblings are guarded. A route with no declared permission must fail closed and fail the build.
74. **Row-level ownership** must be a first-class concept: "my documents", "my payslips", "my reviews", "my tickets". *(FINDING-SEC-04.)*
75. **No swallowed exceptions on a business path.** Every `catch` must either handle, or record and surface. *(20+ empty catches in this area, including the one that reconciles requisition fill and the entire delivery webhook.)*
76. Error responses must carry a **correct status code and a safe message**, with the detail logged. The pattern already exists at `MasterController.php:694-700` and must be the platform default.
77. Every list endpoint must **paginate**. Hard caps that silently truncate (2 000 board rows, 500 pool rows, 200 drives) must be replaced by paging with a visible total.
78. **Sensitive-field masking** must be declarative — a field marked secret is never returned by a read endpoint and never appears in an export. *(FINDING-H-01.)*

---



---

# SOURCE: I-saas-licensing-deployment.md

## 10. SmartPRS 3 requirements implied

### 10.1 Direct answers to the ten commissioning questions

These decide the distribution architecture, so they are stated first and in full.

---

#### Q1 — Licence signing: symmetric or asymmetric? Where does the signing key live, and is it present in customer builds?

**Answer: SmartPRS 2 contains BOTH, and the one that actually gates access is NEITHER — it is an unverified JSON blob in a database row.**

**(A) The `.lic` file path is ASYMMETRIC and correct.** RSA-2048, signature over the exact base64url payload string:

```php
// app/Services/LicenseSigner.php:34-62
public function sign(array $data): string
{
    $priv = openssl_pkey_get_private((string) @file_get_contents($this->privateKeyPath()));
    ...
    $b64 = $this->b64url((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
    openssl_sign($b64, $sig, $priv, OPENSSL_ALGO_SHA256);
    return $b64 . '.' . $this->b64url($sig);
}
// :19-22
public function privateKeyPath(): string { return storage_path('app/keys/license_private.pem'); }
```
```php
// app/Services/LicenseFile.php:26-33
/**
 * SmartPRS Central licence PUBLIC key. Its matching PRIVATE key lives ONLY on the
 * /super server (storage/app/keys/license_private.pem) and is never shipped.
 */
private const PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAqlK8Q6CYHa4+TIH9YDGw
... (RSA-2048 PEM, 6 more lines) ...
-----END PUBLIC KEY-----
PEM;
```
```php
// app/Services/LicenseFile.php:140-148
$pub = openssl_pkey_get_public(self::PUBLIC_KEY);
if ($pub === false) { return ['ok' => false, 'reason' => 'bad_public_key']; }
if (openssl_verify($b64, $sig, $pub, OPENSSL_ALGO_SHA256) !== 1) {
    return ['ok' => false, 'reason' => 'invalid_signature'];
}
```
The private key is generated once on the vendor's `/super` server by `smartprs:make-license-keys` at `storage/app/keys/license_private.pem`, `chmod 0600` in a `0700` directory (`MakeLicenseKeys.php:35-49`), and `storage/` is on the updater's protected list, so it is never packaged. **For this path the signing key is NOT present in customer builds.** Verified.

**(B) The SPRSX1 "offline License Code" path is SYMMETRIC, and its key is specified to be in every customer build.**

```php
// app/Services/LicenseService.php:265-268   (signing, on the vendor's server)
$b = self::b64u((string) json_encode($payload));
$sig = substr(hash_hmac('sha256', $b, self::offlineSecret()), 0, 40);
return self::OFFLINE_PREFIX.'.'.$b.'.'.$sig;

// app/Services/LicenseService.php:278-281   (verification, on the customer's server)
[, $b, $sig] = $parts;
$expect = substr(hash_hmac('sha256', $b, self::offlineSecret()), 0, 40);
if (! hash_equals($expect, $sig)) { return null; }

// app/Services/LicenseService.php:191-205
private static function offlineSecret(): string
{
    $secret = (string) config('smartprs.licence_secret');
    if ($secret !== '') { return $secret; }
    if (app()->environment('production')) {
        throw new \RuntimeException('SMARTPRS_LICENCE_SECRET is not set. Set a strong, secret value (identical on the licensing server and every client install) before issuing or verifying License Codes.');
    }
    return 'dev-insecure-licence-secret-DO-NOT-USE-IN-PRODUCTION';
}
```

The requirement is stated three times in source, unambiguously:

```php
// config/smartprs.php:56-58 and :66-67
| It MUST be identical on the Super Admin and on every client install.
| ...
| Generate a strong random value (e.g. `php artisan key:generate --show`) and
| set the SAME value on the licensing server and in each client's .env.
```

**HMAC is a symmetric primitive: the key that verifies is the key that signs.** A design that puts the verifying key on every customer's disk necessarily puts the *signing* key there too. Any customer who reads their own `.env` can mint unlimited, unexpiring, unlocked License Codes for any edition and any company. That is the single most important architectural fact in this assignment.

**Mitigation today:** the SPRSX1 path is **unreachable dead code** — no route reaches `offlineKey()`, no caller reaches `activateOfflineKey()` (DEF-01, verified by exhaustive grep). The exposure is latent, not live.
**Verification limit:** the reference documents report the literal secret at two `PKG:` paths. **Those files are not in this extract (DISC-01).** I confirm the design, not the value.
**Additional exposure:** in any non-production environment with the variable unset, the source-visible literal `'dev-insecure-licence-secret-DO-NOT-USE-IN-PRODUCTION'` is used — and the shipped `.env.example:2` sets `APP_ENV=local`.

**(C) The activation certificate — what actually gates access — is symmetric AND unverified.**

```php
// app/Services/LicenseService.php:410-422
public static function certificate(object $licence, string $key, string $fingerprint): array
{
    $payload = ['edition' => ..., 'amc_expires_on' => ..., 'expiry_mode' => ...,
                'fingerprint' => $fingerprint, 'issued' => now()->toDateTimeString()];
    $payload['sig'] = hash_hmac('sha256', json_encode($payload), self::normalize($key));
    return $payload;
}
```
Keyed by the licence key, which the client already holds encrypted in `key_enc` — so it could never prove anything against the client. And it does not even try: **grep finds no consumer of `cert['sig']` anywhere.** `licenceStatus()` `:82-107` reads `cert.amc_expires_on` straight out of the `settings` row. One `UPDATE` grants a permanent licence (SEC-01).

**Verdict for SmartPRS 3.** Adopt exactly one licensing primitive: **asymmetric only**. The signing key must never leave the vendor's HSM/KMS. Every artefact the client evaluates — the licence file, the local entitlement cache, and the update package — must be signed with it and verified against an embedded public key, with **no symmetric fallback and no unsigned cache**. Delete the SPRSX1 scheme and the `licence_secret` configuration key entirely.

---

#### Q2 — Are update packages signed? How is a package verified? What could a MITM or a malicious release server do?

**Answer: packages are NOT signed. There is only an integrity hash supplied by the same server in the same response, and the check is conditional.**

Verification is exactly two things:

```php
// app/Services/UpdateClient.php:252-261
if (! empty($available['package_hash'])) {
    $got = hash_file('sha256', $target);
    if (! hash_equals(strtolower((string) $available['package_hash']), strtolower((string) $got))) {
        @unlink($target);
        return $this->fail('The downloaded package failed its integrity check and was deleted. Please try again.');
    }
}
```
```php
// updater/updater.php:174-178
if ($sha && ! hash_equals(strtolower($sha), strtolower((string) hash_file('sha256', $package)))) {
    @unlink($package);
    step('failed', 100, 'The update package failed its integrity check and was deleted. ...');
    exit(1);
}
```

`$available['package_hash']` comes from `UpdateServerController::check()` `:287` — the **same JSON response** that supplies `download_url`. It proves the bytes arrived intact from whoever answered; it proves nothing about *who* answered. `$sha` in the updater comes from `job.json`, which in an attacker-writes-storage scenario is the attacker's own input. The only unconditional gate is the shape test: `app/` and `config/` must exist at the payload root (`updater.php:218-223`).

**What a malicious or compromised release server can do:** push arbitrary PHP into every AMC-active client that presses "Check for updates". The package is extracted over the application root and `php artisan migrate --force` runs (`updater.php:285-311`). That is **full remote code execution as the web user on every customer's HR and payroll server**, delivered by the product's own mechanism, with maintenance mode and a backup, and reported to the admin as a successful update. The blast radius includes every client with a live AMC.

**What a MITM can do:** the only barrier is TLS. Laravel's `Http` client verifies certificates by default and redirects are capped (`UpdateClient.php:120,226`), but there is **no certificate pinning**, so a client whose trust store is polluted — corporate TLS interception, a rogue CA on a Windows box, an operator-added certificate — is fully exposed. And `SMARTPRS_UPDATE_URL` is a plain `.env` value: anyone with file access on the client can repoint the whole update channel at their own server, which the client will then trust completely.

**Verdict for SmartPRS 3.** Signing is mandatory and non-optional: a detached signature over the package, made with the same private key as the licence system, verified with the embedded public key **before extraction and again before install**, plus a signed manifest of per-file hashes so a partial or tampered extraction is detectable. Refuse to install an unsigned package under any configuration. Pin the update host's certificate or at minimum pin the expected public key. Bind the signed manifest to the target version and licence so a package cannot be replayed to a different client or downgraded.

---

#### Q3 — The update process step by step: which steps exist and which are claimed but absent?

| Step | Claimed | Actually exists | Evidence |
|---|---|---|---|
| 1. Detect current version | Yes | **Yes** — `version.json` first, config fallback, BOM-stripped | `UpdateClient.php:44-77` |
| 2. Ask the server | Yes | **Yes** — entitlement, AMC and version compared server-side | `UpdateServerController::check()` `:199-295` |
| 3. Download | Yes | **Yes** — 900 s timeout, streamed to `storage/app/updates`, sanitised filename, <1000 bytes rejected | `UpdateClient.php:195-271` |
| 4. Verify package | Yes | **PARTIAL** — SHA-256 only, **conditional**, no signature | `:252-261`; `updater.php:174` |
| 5. Prove it is a SmartPRS tree | Yes | **Yes** — unwrap single-folder wrappers, require `app/`+`config/`, non-empty after stripping protected paths | `updater.php:196-241` |
| 6. **Code backup** | Yes | **Yes** — every replaced top-level entry plus `version.json` copied to `storage/app/updates/backup_<Ymd_Hi>` | `updater.php:252-269` |
| 6b. **Database backup** | Implied by "backs itself up automatically" | **ABSENT** — no dump, no snapshot, no call to `backup:run` anywhere in the update path | grep across `updater.php`, `UpdateClient.php`, `ReleaseController.php` |
| 7. Maintenance mode | Yes | **Yes** — `artisan down --retry=60`, with a direct `storage/framework/down` fallback if artisan is unbootable, `secret => null` so there is no bypass URL | `updater.php:271-283` |
| 8. Install files | Yes | **Yes** — entry-by-entry copy, `copied === 0` triggers rollback | `updater.php:285-301` |
| 8b. `composer install` | Not claimed | **ABSENT** — a release that adds a dependency must ship `vendor/`, and `vendor/` is **not** on the protected list, so it is silently replaced by the build machine's copy | `updater.php:233` |
| 8c. `storage:link` | Not claimed | **ABSENT** | grep |
| 9. **Database migration** | Yes | **Yes** — `optimize:clear` then `migrate --force`; non-zero exit rolls back | `updater.php:303-311` |
| 10. **Cache handling** | "clear caches" | **PARTIAL** — `optimize:clear` twice (`:306`, `:333`); **never `config:cache` / `route:cache` / `view:cache`**, so a config-cached install permanently loses that optimisation after its first update | `updater.php:306,333` |
| 10b. **Queue-worker restart** | Not claimed anywhere | **ABSENT** — no `queue:restart`. Long-running workers keep executing pre-update code until `--max-time=3600` expires | grep |
| 11. **Health check** | "post-update checks" | **PRESENT BUT WEAK** — `artisan --version` only. Proves the CLI autoloader boots. Does not touch the database, does not exercise a route, does not confirm the web SAPI serves | `updater.php:313-320` |
| 12. **Rollback (code)** | Yes | **Yes, and correctly implemented** — removes each replaced entry *before* restoring, precisely so files the new version added cannot survive | `updater.php:140-165` |
| 12b. **Rollback (database)** | Implied by "any failure rolls back" | **ABSENT** — no `migrate:rollback`, no snapshot. A migration that half-applies then fails leaves the schema ahead of the restored code, and the app is brought back **up** in that state | `updater.php:307-311` |
| 13. Bring back online | Yes | **Yes** — write `version.json` (keeping the captured channel), `optimize:clear`, `artisan up`, remove the down flag | `updater.php:322-335` |
| 14. Progress reporting | Yes | **Yes in design** — a framework-free `update-status.php` gated by a one-time `hash_equals` token; **but one of its two copies is missing** (DEF-34) | `updater/update-status.php`; `ClientUpdateController.php:822-834` |
| 15. Backup retention | "can be deleted once everything looks right" | **ABSENT** — never pruned, unbounded growth | `updater.php:342-343` |
| 16. Audit | Yes | **Yes** — `client_updates` on both sides, though the client writes the *config* version, not `version.json` | `:866-878`, `UpdateServerController::log()` `:360-370` |

**The platform-side twin (`applyPlatform()`) is materially worse:** its rollback copies over instead of removing first (DEF-28), it overwrites `public/` and `bootstrap/` without backing them up (DEF-29), and it does not unwrap a single-folder package so a wrapped archive installs nothing while reporting success (DEF-30).

**Summary: backup (code), maintenance mode, migration and code rollback genuinely exist and are well built. Database backup, database rollback, worker restart, cache rebuild, dependency install and a meaningful health check do not.**

---

#### Q4 — Are application updates and configuration/statutory content updates separated at all?

**Answer: no. There is exactly one delivery artefact — a zip of the application tree — and a PT slab change therefore requires a full application release.**

There is one update object (`releases`), one grant object (`release_grants`), one client flow (`check → download → install`) and one install action: copy files over the application root and run `migrate --force`. Grep finds **no** data-only, config-only, seed-only or content-only channel; no `releases.kind` column; no per-artefact type anywhere.

**What that means for a statutory change, concretely.** `StatutoryConfig` (`app/Services/StatutoryConfig.php`) *does* provide a genuine per-tenant override layer for PF/ESI/PT/TDS, and its own docblock is explicit about the split:

> *"EDITABLE PT SLABS — the **16 state slabs and 10 PT-free states are compiled into the engine**. A state changing its slab, or a state that is not built in at all, needed a code change. `pt_state_slabs` / `pt_free_states` overrides now ride through this same layer."* (`StatutoryConfig.php:31-35`)

So there are exactly two ways to reflect a real PT slab change:

1. **Per install, by hand** — an operator opens the Statutory Rate Settings screen on every tenant and every on-prem install and re-enters the new slab as an override. This does not scale and produces divergence.
2. **For everyone, by code** — edit the compiled default slab table and ship a **full application release**: bump the version, build the zip, upload to `/admin/releases`, publish, grant to every AMC-active client, then wait for each client's admin to notice the email, sign in, press Check, press Download, press Install, and accept a maintenance window on their payroll server. On an install whose AMC has lapsed, the update is refused outright (`UpdateServerController::check()` `:238-246`), so **a customer who has not paid AMC cannot receive a statutory correction at all** — even though the statute applies to them regardless.

There is also no mechanism to ship master data, letter templates, report definitions, alert templates or plan catalogues independently of code.

**Verdict for SmartPRS 3.** This is a first-order architectural requirement, not a nice-to-have. Statutory and configuration content must be a **separately versioned, separately signed, separately delivered artefact** with its own effective-dating, its own applicability rules and its own delivery channel that does **not** require a maintenance window, does **not** require file replacement, and is **not** gated behind a commercial AMC — because a wrong PT deduction is the vendor's liability whether or not the customer is current on support.

---

#### Q5 — Editions L1/L2/L3: claimed capability gating versus what is actually enforced

**Claimed** (`Edition.php:5-17`, `EditionGuard.php:9-18`, `DemoAccessController::EDITION_DEMOS` `:203-208`, `admin/onprem.blade.php:22`, and three price points on the sales desk):

- `l1` = on-prem **Core** — Core HR only
- `l2` = on-prem **Advanced** — core + the nine L2 modules
- `l3` = on-prem **DNA** — everything except the SaaS Platform
- *"module off = invisible + locked, NEVER deleted — upgrading is just a new `SMARTPRS_EDITION` value in `.env`"*
- *"the modules outside the licence are blocked for EVERY method (GET too — unlicensed data must stay invisible, not just read-only). The friendly 403 doubles as an upsell."*

**Actually enforced — nothing edition-specific. The enforcement code, quoted in full:**

```php
// app/Services/Edition.php:93-97
/** Feature level. 8 Aug 2026 (Ejaz — single product): every edition is full. */
public static function level(): int
{
    return 3;
}

// app/Services/Edition.php:119-127
public static function hiddenNavIds(): array
{
    // 8 Aug 2026 (Ejaz — FINAL: single product, all features, licensed by user
    // count). Every on-prem edition (l1/l2/l3) now shows ALL modules; only the
    // SaaS-platform surfaces (tenant billing + Super Admin) stay hidden on a
    // client install. The old per-edition DNA / L2_MODULES gating is retired
    // (constants kept for reference / easy revert).
    return self::current() === 'saas' ? [] : self::SAAS_ONLY;
}

// app/Services/Edition.php:169-174
public static function blockedPatterns(): array
{
    // 8 Aug 2026 (Ejaz — single product): on-prem clients block only the SaaS
    // platform / Super-Admin endpoints; every product module is reachable.
    return self::current() === 'saas' ? [] : self::BLOCK_SAAS;
}

// app/Services/Edition.php:176-180
/** Friendly label. 8 Aug 2026 (Ejaz — single product): one name, no L1/L2/L3. */
public static function label(): string
{
    return 'SmartPRS';
}
```

```php
// app/Http/Middleware/EditionGuard.php:34-53
// rev147: platform / Super-Admin surfaces are HIDDEN, not upsold
foreach (Edition::platformPatterns() as $re) {     // == BLOCK_SAAS
    if (preg_match($re, $path)) { abort(404); }
}
foreach (Edition::blockedPatterns() as $re) {      // == BLOCK_SAAS — SAME LIST
    if (preg_match($re, $path)) { ... 403 upsell ... }   // UNREACHABLE
}
```

`BLOCK_DNA` (`:149-155`), `BLOCK_L2` (`:158-167`), `DNA` (`:105`) and `L2_MODULES` (`:108-117`) are declared and returned by **nothing** (verified by grep).

**Enforcement scorecard:**

| Claim | Reality | Status |
|---|---|---|
| L1 = Core HR only | L1 shows and serves every module | **NOT IMPLEMENTED** |
| L2 = core + nine advanced modules | Identical to L1 and L3 | **NOT IMPLEMENTED** |
| L3 = everything except SaaS Platform | True — but so are L1 and L2 | **NOT IMPLEMENTED as a differentiator** |
| Modules outside the licence are invisible and locked | No module is ever off | **NOT IMPLEMENTED** |
| Friendly 403 upsell | Unreachable dead branch | **NOT IMPLEMENTED** |
| SaaS/Super-Admin surfaces hidden on-prem | **Yes — `abort(404)`, so no trace is hinted** | **IMPLEMENTED** |
| Edition lock at activation | **Yes, on all three activation paths** | **IMPLEMENTED** |
| `/app1` `/app2` `/app3` demonstrate three products | All three show the same app; only the marketing blurb differs | **DEFECT — sales-integrity risk** |
| Volume Hiring is L3-only | `cfg.editionLevel` is always 3, so the tabs render everywhere | **NOT IMPLEMENTED** |
| Interakt webhook is L3-only | `level() >= 3` is always true | **NOT IMPLEMENTED** |

**The one enforced edition behaviour is the lock at activation**, and it is enforced against a distinction that does not exist:
```php
// app/Http/Controllers/ClientUpdateController.php:441-444
$ed = strtolower((string) ($p['edition'] ?? $p['plan'] ?? ''));
if ($ed !== '' && $ed !== Edition::current()) {
    return ['ok' => false, 'error' => 'This licence file is for SmartPRS-'.strtoupper($ed).', but this installation is SmartPRS-'.strtoupper(Edition::current()).'.'];
}
```
A client sold L1 who is mistakenly issued an L2 `.lic` is **blocked from an application that would behave identically either way**. Same check at `UpdateServerController.php:58-62` and `ClientUpdateController.php:324-327`.

---

#### Q6 — Seat/user limits: how they are enforced, and every path that bypasses them

**The one enforcement function:**
```php
// app/Services/SubscriptionService.php:127-149
public static function canAddEmployees(?int $tenantId, int $count = 1): array
{
    $u  = self::seatUsage($tenantId);
    $ok = ['ok' => true, 'error' => null, 'used' => $u['used'], 'licensed' => $u['licensed']];
    if (! $tenantId || $u['licensed'] <= 0) { return $ok; }   // no limit on record -> allow
    if ($u['used'] + $count <= $u['licensed']) { return $ok; }
    $ok['ok'] = false;  ...
}
```

**Enforced doors — four, all confirmed by grep:**

| Door | Location | Behaviour |
|---|---|---|
| Add-employee form | `AppDataController.php:3042` | Blocks |
| Inline CSV / paste import | `AppDataController.php:815` | Blocks mid-import, returns a partial count |
| Recruitment → hire | `RecruitmentController.php:602` | Blocks |
| Renewal sizing (must cover current usage) | `TenantBillingController.php:292-295` | 422 |

**Every bypass:**

| # | Bypass | Evidence | Effort |
|---|---|---|---|
| B1 | **Bulk employee import** — `EmployeeImportController` / `EmployeeImportService` never call `canAddEmployees()` or `seatUsage()` | grep: the only four call sites are listed above | **Trivial, through the UI** |
| B2 | **Self-onboarding portal** — `SelfOnboardingController` / `OnboardingController` never call them either | same grep | **Trivial, through the UI** |
| B3 | **Archive the employee (on-prem)** — the `.lic` counter excludes `archived_at` (`ClientUpdateController.php:515-520`) while the person stays fully usable in payroll | `AppDataController` archives by setting `archived_at` without touching `status` | **Trivial, through the UI** ("Backed up / Old data") |
| B4 | **`licensed <= 0` means unlimited** — a tenant with no `subscriptions` row and `tenants.seats_licensed = 0` is never capped | `SubscriptionService.php:131` | Configuration |
| B5 | **On-prem installs are provisioned with `seats_licensed = 100000`**, so with no `device_limit` in the `.lic` the effective cap is 100,000 | `ClientProvision.php:72`, `InstallController.php:198` | Default |
| B6 | **A `.lic` issued with `seats = 0`** (the form default, `OnpremClientController.php:341`) writes `device_limit = null` ⇒ `seatLimit()` returns null ⇒ no override ⇒ B5 applies | `LicenseSigner.php:48`, `ClientUpdateController.php:498-504` | Default |
| B7 | **Fail-soft to unlimited** — any exception inside `seatUsage()` returns `licensed = 0` | `SubscriptionService.php:116-118` | Provoke any DB error |
| B8 | **Turn enforcement off** — `SMARTPRS_LICENCE_ENFORCE=false` removes the `.lic` seat override entirely (`SubscriptionService.php:109-110`) | one line in a plaintext file the client owns | **Trivial** |
| B9 | **Change the deployment/edition switch** — `SMARTPRS_DEPLOYMENT=saas` or `SMARTPRS_EDITION=saas` removes the on-prem branch | `SubscriptionService.php:109`, `Edition.php:88-91` | **Trivial** |
| B10 | **Edits are never checked** — the cap applies only to *new* employees. Re-activating an exited employee, or changing a status from `inactive` back to `active`, crosses the cap with no check | only `canAddEmployees` exists; no `canReactivate` | **Trivial** |
| B11 | **The `.lic` seat cap is checked at activation only** — `activateLicenceFile()` `:459-469` refuses a licence covering fewer seats than are currently active, then never re-checks. Growth past the cap after activation is blocked only at the Add door | `ClientUpdateController.php:459-469` | — |
| B12 | **Edit the local certificate** — `device_limit` lives in the unsigned `settings` blob | `ClientUpdateController.php:498-504`, SEC-01 | **Trivial with DB access** |
| B13 | **Server-seat cap is discarded** — a second and third server can run the same licence because `activate()`/`heartbeat()` ignore `recordDevice()`'s refusal | `UpdateServerController.php:92,145` | None required |
| B14 | **Off-roll commission agents never count** — they live in a separate table | `SEAT_FREE_STATUSES` applies to `employees` only | By design |

---

#### Q7 — Tenant provisioning: what a new tenant gets, how much is manual, and how long a real implementation takes today

**What is created automatically** (`SaasController::provisionTenantRecord()` `:132-228`, plus the caller):

1. `tenants` — uuid, name, `plan_id`, `status='active'`, `seats_licensed`, `mrr=0`, `deployment`, `owner_email`, `subdomain` (3–10 chars, unique, auto-derived on conflict), `gstin`, `state`.
2. `companies` — exactly **one**, the MASTER company (`is_master = 1`), self-healing that column if absent.
3. `users` — exactly **one** admin. With `email_credentials=true` a `Str::password(12, true, true, false)` temporary password (letters + digits, **no symbols**); otherwise `Str::random(40)` (unusable). `syncRoles(['admin'])`, and `must_set_password = 1` so the admin is forced to create their own password on first entry — a deliberate fallback for when the credentials email fails.
4. `user_password_resets` — a `sha256`-hashed token, 72-hour expiry, raw token in the emailed URL.
5. A branded welcome email (sent `sync`, so it does not wait on a queue worker) naming the branded sign-in page `/c/{slug}`.
6. `SeedIndustryContent::seedForTenant()` — training programmes, FAQs, knowledge base, code of conduct. Best-effort, exceptions swallowed.
7. By the **caller**: a `subscriptions` row at the full rate, `tenants.mrr`, a GST invoice, a payment row, coupon redemption, the receipt email with the PDF, and (online path only) auto-sign-in plus two WhatsApp messages.

**What is NOT created — this is the answer to "how much is manual":**

- **No payroll configuration whatsoever** — no salary structure, no components, no earnings/deductions, no salary schedule, no pay cycle.
- **No statutory configuration** — no PF/ESI/PT/TDS position; the compiled defaults apply until someone opens the Statutory Rate Settings screen.
- **No financial year** — `app/fin-year/set` is a separate, manual action.
- **No attendance configuration** — no shifts, no roster, no late policy, no leave types, no holiday calendar.
- **No organisational structure** — no branches, no departments, no designations, no grades. The tenant gets one company and nothing inside it.
- **No approval hierarchy, no additional users, no roles beyond the seeded five, no biometric devices, no branding, no per-tenant SMTP, no WhatsApp/SMS provider, no report or letter templates beyond the industry starter pack, no employees.**

**How long a real implementation takes today.** The *technical* provisioning is sub-second and fully automated. Everything that makes the product usable for a specific employer is manual data entry through the SPA, screen by screen: company structure, then policies, then salary structures, then statutory positions, then employees (via the CSV importer — which does not check seats, B1), then approval routing, then device mapping. **Source does not record an elapsed time, and I will not invent one** — but the *shape* is unambiguous: automation ends at "a tenant exists with one company and one admin", and everything a Product Owner would call "implementation" is manual and unassisted. There is no configuration template, no industry preset beyond content, no copy-from-another-tenant, no import/export of configuration, and no completeness check that tells the implementer what is still missing.

**This is the strongest argument in this whole analysis for SmartPRS 3's configuration-first premise.**

---

#### Q8 — Cloud vs on-prem: every place the BUSINESS LOGIC actually differs

Infrastructure differences excluded. Twenty-one logic branches, each verified in source:

| # | Location | Branches on | On-prem behaviour |
|---|---|---|---|
| 1 | `routes/web.php:22` | `Edition::isOnPrem()` → `$spOnPrem` | Gates the entire public marketing/SaaS route block |
| 2 | `routes/web.php:27-37` | `$spOnPrem` | `/` redirects to `/app` instead of the marketing landing page |
| 3 | `routes/web.php:45-55` | `! $spOnPrem` | `/lead` and the nine public policy pages are **not registered** |
| 4 | `routes/web.php:125-167` | `! $spOnPrem` | `/signup*`, `/demo*`, `/app1-3`, `/teamdemo`, `/quote/*`, `/webhooks/razorpay` **not registered** |
| 5 | `routes/web.php:172-196` | `! $spOnPrem` | The entire update-server + licence-authority + `/licence/{token}` pay family **not registered** |
| 6 | `routes/web.php:200` | `! $spOnPrem \|\| Edition::level() >= 3` | Interakt webhook — **always registered** because `level()` is the literal 3 (DEF-37) |
| 7 | `routes/updates.php:37` | `config('smartprs.deployment') !== 'onprem'` | `/update/package/{token}` not registered on a client box |
| 8 | `EditionGuard.php:25-53` | `Edition::isOnPrem()` | `/` → `/app`; `admin/*`, `super/*`, `signup*`, `quote*`, `demo*`, `lead` → **404** |
| 9 | `LicenseGate.php:45` | `Edition::isOnPrem()` | The licence gate runs **only** on-prem |
| 10 | `EnsureSubscriptionActive.php:65-69` | `Edition::isOnPrem()` | **The subscription lock-out never applies** — *"on-premise editions are PERPETUAL licences"* |
| 11 | `ClientUpdateController.php:231-236` | `deployment==='onprem'` **AND** `isOnPrem()` **AND** `licence_enforce` | Only then is a licence demanded (the two-switch design) |
| 12 | `AuthController.php:21-25` | both switches + `! alreadyInstalled()` | `/login` redirects to `/install` on a virgin box |
| 13 | `AuthController.php:128` | `Edition::isOnPrem()` | `/super` (Super Admin portal) `abort(404)` |
| 14 | `AuthController.php:203-266` | licence state | The **entire licence gate at the login door** — LC field, notify-mode self-heal, non-admin hold message |
| 15 | `AppController.php:96-98` | `Edition::isOnPrem()` | `subState` forced to `active`, `daysLeft = 9999` → no renewal banner ever |
| 16 | `SubscriptionService.php:107-115` | `isOnPrem()` + `licence_enforce` | The `.lic` `device_limit` **overrides** subscription seats |
| 17 | `SubscriptionService.php:138-146` | `isOnPrem()` | A different, licence-worded seat-limit error message |
| 18 | `ClientUpdateController.php:171-176` | `isOnPrem()` | `revocationSweep()` no-ops off on-prem |
| 19 | `ClientUpdateController.php:665,686` | `isOnPrem()` | The pre-login `/activate` screen 404s on SaaS |
| 20 | `licence-activate.blade.php:37-39` | `isOnPrem()` | Cloud tenants see *"nothing to activate"*; only on-prem gets the `.lic` upload form |
| 21 | `InstallController.php:241-243` | `isOnPrem()` | A different post-install message telling the admin to activate |

**Plus three provisioning differences:** on-prem tenants get `seats_licensed = 100000` and `deployment='onprem'` (`ClientProvision.php:71-74`, `InstallController.php:197-200`); on-prem money is a one-time licence price + AMC % recorded in `onprem_clients` rather than a recurring `subscriptions` row; and on-prem code updates are client-pulled and client-applied rather than vendor-applied.

**And the conflict this creates:** because #10 keys off *edition* while #11 keys off *deployment*, a cloud-hosted tenant running an L-edition (`SMARTPRS_EDITION=l2`, `SMARTPRS_DEPLOYMENT=saas`) has **no subscription gate, no licence gate, and no reachable My Subscription screen** (it is in `BLOCK_SAAS`). It is unbillable and unenforceable (DEF-36).

---

#### Q9 — Every credential, key or token present in source, config examples, or seeded data

Verified directly in this extract:

| # | What | Location | Kind | Gated? |
|---|---|---|---|---|
| 1 | `'ametecs'` — the team demo PIN default | `config/smartprs.php:32` | Working `env()` default for a control documented as unlocking the unrestricted demos | Dead code, but neither `.env` template mentions the variable |
| 2 | `'smartprs-demo'` — the demo admin/HR/field-agent password | `app/Console/Commands/DemoReset.php:35`; echoed at `:152`; repeated in the docblock `:26` | Live-platform account password | **No** — runs every 15 min and nightly on the live platform |
| 3 | `'password'` for `admin@smartprs.local` (role `admin`) | `database/seeders/OnPremWorkspaceSeeder.php:70,79`; printed at `:101` | Default admin credential | **No** — run manually, not covered by `SMARTPRS_DEMO_DATA` or `APP_ENV` |
| 4 | `'password'` for five accounts, incl. **`superadmin@smartprs.local` (platform `super_admin`, `tenant_id = null`)** | `database/seeders/DemoCompanySeeder.php:57-61,69` | Default credentials | **Yes** — `DatabaseSeeder.php:19-26` requires `! production && SMARTPRS_DEMO_DATA=true` |
| 5 | The same five logins and their shared password, published in a table | `README.md:56-64` | Documentation disclosure | — |
| 6 | `'dev-insecure-licence-secret-DO-NOT-USE-IN-PRODUCTION'` | `app/Services/LicenseService.php:204` | Licence signing secret fallback for any non-production environment; `.env.example:2` sets `APP_ENV=local` | Production only |
| 7 | `SMARTPRS_LICENCE_SECRET` — the shared HMAC signing key, **specified to be identical on the vendor server and every client install** | `config/smartprs.php:56-58,66-69`; `LicenseService.php:191-205` | **Symmetric signing key by design** | Value not in this extract (DISC-01) |
| 8 | The RSA-2048 **public** key for `.lic` verification | `app/Services/LicenseFile.php:34-44` | Cryptographic material in source (correct by design; but reported elsewhere to be a **dev** key pair — unverifiable here) | — |
| 9 | The RSA **private** key path — `storage/app/keys/license_private.pem` | `LicenseSigner.php:21`; `MakeLicenseKeys.php:26,47` | The signing identity for every perpetual licence. **Excluded from every backup** (`config/backup.php:34` excludes `storage/app`) and overwritable without confirmation via `--force` | — |
| 10 | `ejaz@ametecsindia.com` — hardcoded fraud-alert recipient | `LicenseService.php:539` (`config('smartprs.sales_email')` **does not exist**, so this is the effective default) | Production routing identity | — |
| 11 | `ejaz@ametecsindia.com · WhatsApp 9000098877` in ~11 user-facing strings | `EditionGuard.php:45`; `OnpremClientController.php:255,307,403,467,...`; `SubscriptionService.php:141`; `AuthController.php:225,251` | Production contact identity, unchangeable after `config:cache` | — |
| 12 | GSTIN `36AAHCT0971F1ZB`, legal name, registered address, `+91 96666 12424`, `Telangana (36)`, SAC `998314` — as **PHP defaults**, not just env fallbacks | `BillingController::sellerProfile()` `:741-754` | Production tax identity in source | — |
| 13 | The same GSTIN and address shipped in the env template | `.env.production.example:80-86` | A customer who deploys without editing it issues GST invoices carrying the **vendor's** GSTIN | — |
| 14 | GSTIN + state repeated as template fallbacks, and SAC `997331` | `resources/views/onprem-invoice-pdf.blade.php:41,74` | Same, plus a SAC inconsistent with #12 | — |
| 15 | `demo-admin@smartprs.in`, `demo-hr@smartprs.in`, `demo-field@smartprs.in` | `DemoReset.php:102-104`; `DemoAccessController.php:435` | Hardcoded account identifiers | — |
| 16 | `admin@smartprs.local` | `OnPremWorkspaceSeeder.php:70` | Hardcoded account identifier | — |
| 17 | `demo_requests.pin` — 6-digit passkeys **stored in plaintext and indexed** | `DemoAccessController.php:91`, by explicit design comment | Live credential at rest | — |
| 18 | `licences.key_enc` — the plaintext licence key, `Crypt`-encrypted so the panel can re-show it | `LicenseService.php:64,367`; `reveal()` `:380-387` | Recoverable by anyone with the Super Admin panel **and** `APP_KEY` | — |
| 19 | Razorpay key/secret/webhook-secret env fallbacks | `BillingController.php:560-563,574`; `.env.production.example:70-73` (commented placeholders only) | No live value present in this extract | — |
| 20 | `BACKUP_ARCHIVE_PASSWORD` — **unset by default**, so backups (which include `.env` with `APP_KEY` and `DB_PASSWORD`) are unencrypted | `config/backup.php`; `.env.production.example:52` | — | — |
| 21 | eTimeOffice `corp_id`/`username`/`password` env keys | `config/smartprs.php` | No values present | — |

**Reported by the reference documents but NOT verifiable in this extract** (all `PKG:` paths — see DISC-01/02/03): the literal 64-hex `SMARTPRS_LICENCE_SECRET` in `env.client.template:16` and `ClientBuilder.bat:114`; the cleartext admin and **MySQL root** passwords in `smartprs-config.json:11,19`; the default baked-in build password at `ClientBuilder.bat:54`; the weakly generated `Amt-%RANDOM%…` password for a **`support@ametecsindia.com` super-admin created on every client server**; the portable-MariaDB `root` account with an **empty password** on port 3307; and `setup.json` holding the admin password in cleartext. **Every one of these needs verification against the real delivery tree before the rotation exercise is scoped.**

---

#### Q10 — Backup and restore: what exists, whether restore has ever been proven, and what the on-prem operator can see

**What exists — backup:**

- `spatie/laravel-backup`, two scheduled commands: `backup:clean` at 01:30 and `backup:run` at 02:00, both `withoutOverlapping()->onOneServer()` (`routes/console.php:156-164`).
- Scope (`config/backup.php:31-44`): files = `base_path()` **including `.env`**, excluding `vendor`, `node_modules`, `storage/app`, `storage/framework`, `storage/logs`; databases = `['mysql']`, dumped uncompressed.
- Destination: `'disks' => ['local']` — i.e. `storage/app` **on the same server being backed up**. The config comment itself says: *"For real safety add a remote 'backups' disk (S3/Spaces)… so backups survive a server loss."*
- `'password' => env('BACKUP_ARCHIVE_PASSWORD')` — **unset by default**.
- `'tries' => 1, 'retry_delay' => 0` — one attempt, no retry.
- `notifications`, `monitor_backups` and `cleanup` are **deliberately omitted** from the config (`:87-91`), so the package defaults apply and no failure email is configured. `backup:monitor` is **not scheduled**.

**Three consequences that matter commercially:**

1. **`storage/app` is excluded** — so `storage/app/keys/license_private.pem` (the signing identity for every perpetual licence ever issued) and **every user-uploaded file under `storage/app`** are in **no backup at all**.
2. **`.env` is included, unencrypted, on the same disk** — `APP_KEY`, `DB_PASSWORD`, `MAIL_PASSWORD` and any `RAZORPAY_SECRET` travel in the archive. `APP_KEY` decrypts `licences.key_enc` and six other encrypted columns.
3. **A backup that silently stops working is undetectable** — no monitor, no notification, one try. The only detection is a human listing the directory and noticing the newest zip is old.

**What exists — restore: nothing.**

- Grep across `app/`, `database/`, `routes/` finds **no restore command, no restore controller, no restore route and no restore UI**. `app/Console/Commands/` contains 18 commands; none of them restores.
- There is **no documented restore procedure** anywhere in the source tree.
- **Has restore ever been proven?** There is **no evidence in source that a restore has ever been attempted**: no restore test, no restore runbook, no restore log, no drill artefact, no `RESTORE.md`. I record this as *unproven*, not as *known-broken* — but an untested restore is, operationally, not a backup.
- Restoring therefore means an engineer manually unzipping the archive and running `mysql < database.sql` by hand, on a system whose schema is partly created at runtime by application code (§2), so the schema in the dump reflects whatever the application had lazily created at dump time.

**What the on-prem operator can see: essentially nothing.**

- There is **no backup screen, no backup status, no last-run indicator and no restore button** anywhere in the on-prem UI. `Edition::BLOCK_SAAS` 404s `/admin` entirely on a client install, and no `/app/*` backup surface exists.
- The only backup-adjacent thing an on-prem admin ever sees is the **update** backup: the final updater message *"The previous version is kept in storage/app/updates/backup_&lt;stamp&gt; and can be deleted once everything looks right"* (`updater.php:342-343`) — which is a code backup, not a data backup, and is never pruned.
- **Worse for the typical on-prem install:** the reference architecture documents record that **no Windows scheduler artefact ships at all** — the only scheduler artefact in the delivery is a UNIX crontab line. On a Windows/Laragon client install with no Task Scheduler entry, `schedule:run` never fires, so **`backup:run` never runs, and nothing says so**. The `zip` extension is also a hard requirement for backups to work at all, and the installer checks it only as part of the general extension list.

**Verdict: backup is configured but fragile, incomplete in exactly the places that matter most (the signing key and user uploads), unmonitored, and stored on the machine it protects. Restore does not exist as a feature and has not been proven. The on-prem operator has no visibility into either.**

---

### 10.2 Numbered, testable requirements for SmartPRS 3

**Licensing and entitlement**

- **R-01** The licensing engine MUST use asymmetric signing only. Every artefact a client evaluates — licence file, entitlement cache, update package, configuration package — MUST carry a detached signature verifiable with an embedded public key, and there MUST be no symmetric code path and no unsigned fallback. *Test: no HMAC-with-shared-secret primitive exists anywhere in the licensing subsystem; a build with a tampered public key fails every verification.*
- **R-02** The private signing key MUST live only in vendor-controlled key management, MUST be backed up separately from the application, and key rotation MUST be a supported operation that does **not** require re-issuing every live licence — i.e. licences MUST carry a key identifier and the product MUST be able to hold multiple trusted public keys with validity windows. *Test: rotate the key; previously issued licences continue to verify until their stated key-retirement date.*
- **R-03** The locally cached entitlement MUST be signed by the vendor and its signature MUST be verified on every evaluation. Editing the cache MUST invalidate it. *Test: any single-field edit to the stored entitlement blocks access.*
- **R-04** Entitlements MUST be data, not code: a named set of feature/module/limit grants, versioned, attached to a licence, and changeable by re-issuing a licence without a code release. *Test: move a module between two entitlement tiers with no deployment.*
- **R-05** The rule engine MUST support **an entitlement-scoped capability check at both the navigation layer and the endpoint layer, from one declaration** — because BR-LIC-026 shows that maintaining two parallel lists (`hiddenNavIds` and `blockedPatterns`) is what allowed them to silently diverge into a no-op.
- **R-06** Node-locking MUST NOT be defeatable by copying a cached identity file: the fingerprint cache MUST be keyed by, or cryptographically bound to, the hardware identity it caches, and a mismatch MUST invalidate it and raise an event. *Test: copying licence + cache to a second machine fails.*
- **R-07** Licence expiry MUST support a configurable grace period per licence, and the grace value MUST be carried in the licence and honoured — SmartPRS 2 signs `grace_days` into every `.lic` and never reads it.
- **R-08** Seat limits MUST be defined once, in one place, with one definition of "counts against the limit", and that definition MUST be configurable per customer (which employee statuses free a seat, whether archived people count). *Test: `seatUsage()` and the licence-side count return identical numbers for every dataset.*
- **R-09** Every path that creates a person MUST pass through the seat check, including bulk import and self-onboarding, and the check MUST also run on reactivation and status change. *Test: bulk-importing past the cap is refused.*
- **R-10** Server/device seat decisions MUST be enforced, not advisory: a refusal MUST block activation. A storage error MUST fail closed for the seat decision while remaining fail-soft for the session.
- **R-11** Enforcement MUST distinguish **transient** failures (database unreachable → fail open, log loudly, emit a metric, alert) from **deterministic** ones (malformed entitlement, missing crypto extension, signature mismatch → fail closed). Every fail-open event MUST be counted and visible to the vendor. *Test: a corrupt entitlement blocks; a simulated DB timeout does not.*
- **R-12** Revocation MUST NOT depend on a human logging in: there MUST be a scheduled proof-of-life with a configurable interval and a configurable offline tolerance, after which the product degrades to a defined state. *Test: revoke a licence; an idle install blocks within the stated tolerance.*
- **R-13** Machine-move limits (the documented "1/year") MUST be enforced if they are promised, or removed from the documentation. *Test: the N+1st move in a period is refused.*
- **R-14** The licence system MUST NOT rely on local system time alone for expiry. *Test: moving the clock back does not extend a licence.*

**Updates and content delivery**

- **R-15** Update packages MUST be signed and signature verification MUST be mandatory and unconditional. An unsigned or unverifiable package MUST be refused under every configuration. *Test: stripping the signature or the hash refuses the install; there is no configuration that permits it.*
- **R-16** The package MUST carry a signed manifest of per-file hashes, verified after extraction and before installation.
- **R-17** The update client MUST pin the vendor's signing identity, so repointing `UPDATE_URL` at another host cannot deliver installable code.
- **R-18** The update MUST take a **database backup or snapshot** before running migrations, and rollback MUST restore both code and schema to a consistent point. *Test: a migration that fails halfway leaves the system on the previous version with the previous schema.*
- **R-19** Migrations MUST be forward-only-safe or reversible, and the updater MUST verify reversibility before applying.
- **R-20** The post-update health check MUST exercise the real request path: boot the framework, reach the database, serve a health endpoint, and verify the queue connection. Failure MUST roll back. *Test: an update that breaks a route is rolled back automatically.*
- **R-21** The updater MUST restart queue workers and rebuild caches (config/route/view) after a successful install, and MUST leave the maintenance flag set until the health check passes.
- **R-22** Backups created by the updater MUST have a retention policy with a configurable count and age.
- **R-23** One version identity only. The version the build registers, the version the client reports and the version displayed MUST come from a single source. *Test: no two files in the tree state different versions.*
- **R-24** The rollback implementation MUST be identical for the platform and the client — remove-then-restore, covering every directory the package can write. *Test: a rollback leaves no file the new version added.*
- **R-25** The package validator MUST distinguish an *update* package from an *install* package by a signed type field in the manifest, not by guessing at directory shape.
- **R-26** Update progress reporting MUST work on a fresh install with no prior update, and MUST NOT expose migration output, schema names or connection strings to the polling client.

**Configuration and statutory content — the separation SmartPRS 2 does not have**

- **R-27** Configuration, statutory content and master data MUST be delivered as **separately versioned, separately signed artefacts**, independent of the application release. *Test: publishing a PT slab change reaches every install without a code deployment and without a maintenance window.*
- **R-28** Statutory content MUST be effective-dated end-to-end: an artefact states the dates from which each value applies, and a recomputation of a past period uses the values that were in force then. *Test: regenerating March payroll after an April rate change reproduces the original March result.*
- **R-29** Statutory content delivery MUST NOT be gated behind a commercial support contract. A compliance correction is a liability, not a feature. *Test: an install with a lapsed AMC still receives statutory updates while being refused feature updates.*
- **R-30** The statutory rule set MUST be data, including the PT state slab tables and the PT-free state list, which SmartPRS 2 compiles into the engine (`StatutoryConfig.php:31-35`). *Test: adding a state's slab table requires no code change.*
- **R-31** The engine MUST distinguish **vendor-supplied** content, **customer-overridden** content and **customer-authored** content, and MUST show the implementer which is which, so a vendor update never silently overwrites a deliberate customer position.

**Provisioning and configuration-first onboarding**

- **R-32** Tenant provisioning MUST be atomic. *Test: an induced failure at any step leaves no partially provisioned tenant and no captured payment without a workspace.*
- **R-33** Provisioning MUST create a **complete, working configuration** from a named template, not just a tenant shell: organisation structure, pay cycle, financial year, salary structure, statutory position, attendance and leave policy, approval routing, letter and report templates. *Test: a newly provisioned tenant can run a payroll for one employee without opening a configuration screen.*
- **R-34** Configuration templates MUST be first-class, versioned, exportable and importable objects — industry presets, and copy-from-an-existing-customer. *Test: exporting one tenant's configuration and importing it into another reproduces identical behaviour.*
- **R-35** The system MUST expose a **configuration completeness model** that tells an implementer what is still unset and what depends on it. *Test: a fresh tenant reports its unmet configuration prerequisites for running payroll.*
- **R-36** There MUST be exactly one provisioning implementation shared by self-serve signup, the vendor console, the CLI and the on-prem installer — SmartPRS 2 has three with divergent slug rules and divergent seat defaults.

**Commercial configuration**

- **R-37** Every commercial rate MUST be configuration: tax rate(s), advance-payment discounts, billing cycles and their month counts, per-company fees, payment terms, settlement tolerance, SAC/HSN codes, currency and the seller identity. Rates MUST be effective-dated. *Test: changing GST from 18% to another value affects new documents only, with no code change and no reprint of historical invoices.*
- **R-38** Invoice and quotation numbering MUST use a single, atomic, gap-free-per-series sequence with a configurable format and FY boundary, implemented once. *Test: 100 concurrent invoice creations produce 100 distinct consecutive numbers.*
- **R-39** The subscription grace period, the alert ladder and the lock-out policy MUST be configurable per plan or per customer.
- **R-40** A billing period MUST advance only on **payment**, never on invoice issue. *Test: an unpaid renewal invoice does not extend service.*
- **R-41** There MUST be exactly one payment-recording path, partial-aware, idempotent on a **uniquely indexed** gateway transaction id. *Test: a webhook retry concurrent with a browser confirmation records one payment.*
- **R-42** Credit notes MUST exist with their own number series and PDF, and refunds MUST be recordable against an invoice. *Test: the published refund policy is executable in the product.*
- **R-43** An invoice PDF MUST be reproducible: line items, plan name and quantities MUST be snapshotted at issue. *Test: reprinting an invoice after an upgrade reproduces the original document byte-for-byte in content.*
- **R-44** Quotation validity MUST be enforced at order and completion, not only displayed.
- **R-45** Trial MUST either be implemented as a first-class, configurable state with its own alerts and expiry, or removed from the schema.
- **R-46** Which plans are sellable MUST be a data flag, not a hard-coded name whitelist. *Test: renaming a plan does not remove it from signup.*

**Deployment, operations and demo**

- **R-47** Backups MUST support a remote destination, MUST include the signing key and user uploads or explicitly declare them out of scope with a separate protected process, MUST be encrypted by default, MUST be monitored with alerting, and MUST retry. *Test: a failed backup raises an alert within one cycle.*
- **R-48** A **restore** capability MUST exist as a documented, scripted and periodically exercised procedure, with a recorded successful restore drill. *Test: a restore drill from a production-shaped archive succeeds and is logged.*
- **R-49** The on-prem operator MUST have a visible operations surface: last backup, last successful restore drill, licence status and expiry, seat usage, scheduler health, queue health and update status. *Test: an on-prem admin can answer "when was my last good backup?" without a support call.*
- **R-50** Both background processes (scheduler and queue worker) MUST ship as installable services for **every** supported platform, including Windows, and MUST be health-monitored with a visible indicator. *Test: a fresh on-prem install reports scheduler and worker health on day one.*
- **R-51** No default or shared credential may exist in any seeder, installer, build script or configuration default. Every account MUST be created with a generated secret shown once. *Test: a full grep for a literal password across the tree returns nothing.*
- **R-52** Any vendor support account on a customer system MUST be opt-in, time-limited, per-incident, and disclosed in the contract. *Test: no permanent vendor account exists after installation.*
- **R-53** Demo access tiers MUST be separated by authorisation, not by a caller-supplied parameter: an unrestricted demonstration MUST require a vendor-authenticated, signed, expiring invitation. *Test: a public visitor cannot obtain an unrestricted session.*
- **R-54** Demo accounts MUST NOT be authenticable by password; entry MUST be only through the gated flow, and every demo session MUST be time-boxed and reset.
- **R-55** Every public POST that writes, spends or calls a third party MUST be rate limited. *Test: no unthrottled public write endpoint exists.*
- **R-56** The build and release pipeline MUST be CI-driven, platform-neutral, reproducible, and MUST fail on a version mismatch or an unsigned artefact. *Test: a release cannot be published without a passing test suite and a valid signature.*
- **R-57** Every table MUST be created by a versioned migration. Runtime `Schema::create`/`Schema::table` on the request path MUST NOT exist. *Test: a fresh database built only from migrations passes the full test suite.*

---



---

# SOURCE: J-database.md

## 10. SmartPRS 3 requirements implied

**Schema governance**

1. The schema MUST have exactly **one** authority. No application code path may execute DDL.
   `Schema::create`, `Schema::table`, `ALTER TABLE` and `SHOW COLUMNS` MUST be forbidden
   outside migrations, enforced by a CI check, because SmartPRS 2 has 72 `Schema::create` and
   6 raw `ALTER TABLE` calls on request paths (§6.5).
2. The production database user MUST NOT hold `ALTER`, `CREATE` or `DROP`. A deployment that
   requires them MUST fail closed.
3. A **schema fingerprint** (hash of `information_schema` for the app's tables) MUST be
   computed at boot and compared to the expected fingerprint for the deployed version. A
   mismatch MUST be surfaced, not swallowed.
4. `migrate:fresh` on an empty database MUST reproduce the complete production schema. A CI
   job MUST diff `migrate:fresh` against a reference dump and fail on any difference.
5. Every migration MUST be idempotent and re-runnable. 13 of 49 SmartPRS 2 migrations are not
   (§6.8), and MySQL's non-transactional DDL makes a half-applied migration unrecoverable.
6. No migration `up()` may delete rows. Two SmartPRS 2 migrations do (§6.8).

**Metadata-driven entities and fields (the "change configuration, not code" core)**

7. The rule engine MUST support **customer-defined entities and fields** declared as metadata,
   with typed storage (string / integer / decimal(p,s) / date / time / datetime / boolean /
   enum / reference / money / json), because SmartPRS 2 implements exactly this need as a
   450-line PHP array plus runtime DDL (`MasterController::def()` `:32-482`).
8. Field metadata MUST carry: label, help text, option list, validation, **uniqueness scope**
   (none / tenant / company / global), required-ness, PII classification, retention class and
   audit flag. SmartPRS 2 has all of this in `EmployeeFieldRules::FIELDS` — in PHP.
9. Enum/option lists MUST be **reference data rows**, never DDL enums. SmartPRS 2 has 35 DDL
   enums, 7 already emergency-widened after production failures and 12 still live time-bombs
   (§6.6). A customer adding "On notice period" to an employee status list MUST NOT require a
   deploy.
10. Where an option list is rendered by more than one surface (form, import template, export,
    API, portal), all surfaces MUST derive from the **same** metadata row. SmartPRS 2's
    `EmployeeFieldRules` header (`:11-27`) documents five hand-maintained copies and the bugs
    that produced; §6.6.2 E8/E9 are the two that survived.
11. Uniqueness declared in metadata MUST be materialised as a **database constraint**, not a
    PHP `SELECT`. SmartPRS 2 enforces 8 employee uniqueness rules in PHP with a documented
    race (§6.9) and explicitly refuses to create the unique indexes (`2026_08_28_000100:121-124`).

**Referential integrity and tenancy**

12. Every reference MUST be a declared foreign key with an explicit `ON DELETE` action.
    SmartPRS 2 has **zero** on business tables and an admin endpoint whose only job is
    sweeping orphans (`RequestController.php:1246`).
13. Tenant isolation MUST be enforced at a layer no query can forget — row-level security, a
    per-tenant connection, or a repository layer that cannot be bypassed. SmartPRS 2 relies on
    1,413 hand-written `->when($tid,…)` clauses (§6.3, SEC-TEN-01).
14. The tenant column MUST have **one** name, be `NOT NULL` on every tenant-owned table, and
    be part of every unique key on such a table. SmartPRS 2 uses four names
    (`tenant_id`, `company_id`, `company_name`, `company`), makes `tenant_id` nullable on
    every post-June-2026 table, and has one unique key that omits it (§6.4, Q7).
15. "Platform scope" MUST have exactly one representation. SmartPRS 2 uses both `NULL` and `0`.
16. Cross-tenant access MUST be an **explicit, audited capability check**, never the absence of
    a filter (`->when($tid,…)` with a falsy `$tid`), and never a silent coercion to the
    lowest-id tenant (`AppDataController.php:2512`).
17. No table holding tenant data may lack a tenant column. SmartPRS 2 has 4 (§Q7).

**Payroll, money and periods**

18. A payroll period MUST be a first-class typed object (`period_start`, `period_end`,
    `cycle_definition_id`), not a `VARCHAR(7)` `'YYYY-MM'` and a free-text `cycle_label`
    (§Q9 D3, D5).
19. A payslip MUST store its **components as rows**, not as a JSON blob, so that
    per-component reporting, statutory returns and re-explanation are SQL operations
    (§Q9 M1).
20. A payslip MUST be **immutable once issued**. Regeneration MUST create a new version and
    supersede the old one, never `DELETE` it (`PayrollGenController.php:2389-2390`).
21. Reversal of a superseded run (commission locks, overtime, loan EMI, clawbacks) MUST be
    **transactional and total**. SmartPRS 2 does it in four independent best-effort
    `try/catch` blocks that cannot fail the run (`:2398-2470`).
22. Money MUST be `DECIMAL` with a **declared, uniform scale** driven by currency metadata,
    and the schema MUST forbid a money value in a string, a JSON blob, or a CSV column
    (§Q9 M1–M9).
23. Times of day MUST be `TIME`, dates `DATE`, instants `DATETIME`/`TIMESTAMP` with an
    explicit timezone policy. SmartPRS 2 has one correct `TIME` column
    (`attendance_corrections`) and six wrong ones (§Q9 D6–D9).
24. A `DECIMAL` scale MUST be chosen per semantic type, not by a generic type map.
    `DECIMAL(12,2)` for a latitude truncated real geofences to ±557 m (§4.1).

**Effective dating and configuration history**

25. **Every** configuration entity MUST be effective-dated and versioned — salary components,
    late policy, shifts, leave types, holidays, statutory rates, pay cycles, minimum wages —
    not the three that are today (§4.4). A payslip MUST be re-explainable from the
    configuration **as it stood on its period**, which SmartPRS 2 cannot do because
    `MasterController.php:1205` hard-deletes those configuration rows.
26. Resolution MUST be "the row in force on date D", with an explicit representation for
    "always in force" that is distinguishable from "not yet effective". SmartPRS 2 conflates
    both as NULL (`EffectiveDated.php:56-58`).
27. Configuration rows referenced by finalised payroll MUST be **undeletable**, only
    superseded.
28. The finalised-period guard MUST **fail closed**. SmartPRS 2's fails open
    (`EffectiveDated.php:115-117`).

**Lifecycle, retention and audit**

29. Every entity MUST declare a lifecycle: active → inactive → archived → purged, with a
    **configurable** retention period per entity class. SmartPRS 2 has soft delete on 8 of 152
    tables, archive on 1, a `data_retention_months` setting nothing reads, and no purge at all
    (§Q8, §8 #10/#11).
30. Delete semantics MUST be a **declared policy per entity**, not an accident of whether the
    table happens to have a `deleted_at` column (`MasterController.php:1202-1206`).
31. Evidentiary data — attendance punches, payroll runs, statutory filings, DRA/PCC/BGV/POSH
    records, approval decisions, documents — MUST be append-only at the database level, with
    supersession instead of `DELETE`. SmartPRS 2 hard-deletes all of it (§Q8, 30 sites).
32. Right-to-erasure, anonymisation and data-export MUST exist as first-class operations
    (DPDP Act 2023). SmartPRS 2 has none.
33. The audit trail MUST record the **actor's user id**, not a name string. SmartPRS 2 stores
    `decided_by`, `approver_name`, `created_by`, `by`, `entered_by`, `archived_by`,
    `uploaded_by`, `rated_by`, `cleared_by`, `granted_by`, `locked_by` as free text — an
    unjoinable, non-repudiable trail (SEC-DB-04).
34. Audit writes MUST be part of the transaction they describe. SmartPRS 2's `Audit::record()`
    is fail-soft, so a business action can succeed with no audit row.
35. High-volume logs MUST have a declared retention and an enforced rotation.

**Indexes, constraints and concurrency**

36. Every soft-delete column, every status column used in an inbox filter, every period column
    and every foreign key MUST be indexed. SmartPRS 2 indexes none of the 8 `deleted_at`
    columns and none of the approval `status` columns (§Q5).
37. Business invariants MUST be database constraints, not read-then-write PHP. Specifically:
    one payroll run per (tenant, company, period); one payslip per (run, employee); the
    employee uniqueness set (§Q5).
38. Sequence generation (employee codes, temp codes, invoice numbers) MUST be atomic.
    SmartPRS 2 uses `MAX() + 1` (`SelfOnboardingController.php:97-99`).

**Operational**

39. There MUST be no default database connection. A missing `DB_CONNECTION` MUST fail the
    boot, not silently open SQLite (`config/database.php:13`).
40. Demo/seed data MUST be physically separable — a distinct database or an indelible row
    flag — never distinguished by a `source='demo'` string in the same table as real
    attendance (798 of 1,254 rows in the reference DB).
41. Destructive seeders MUST NOT be able to target a customer tenant. SmartPRS 2's
    `DummyDataSeeder` hard-deletes 23 tables for whichever tenant has `subdomain='demo'`
    (SEC-SEED-02).
42. Schema errors MUST be surfaced to operators. SmartPRS 2 swallows them into empty `catch`
    blocks and replaces `Data truncated` with *"Please check your entries"*
    (`MasterController.php:831-833`).

---



---

# SOURCE: K-frontend-spa.md

## 10. SmartPRS 3 requirements implied

**Screen and navigation engine**
1. Screens must be defined as configuration (id, title, data source, columns, form
   fields, actions, permissions), not code — SmartPRS 2 has 146 screen ids as literals
   across two files.
2. The engine must support at least the seven presentation types SmartPRS 2 needed
   (`list`, `cards`, `lettered`, `form`, `empform`, `settings`, `custom`) **and** must
   make the 58 escapes to `custom` unnecessary — each of those 58 is a capability the
   declarative model lacked. Enumerate and specify them before designing the engine.
3. Menu structure, grouping, ordering, icons and per-role visibility must be
   configuration. SmartPRS 2 injects 21 menu items by DOM manipulation at runtime.
4. Deep-linkable URLs per screen with History API support. 145 of 146 SmartPRS 2 screens
   are not addressable.
5. Screen-level and field-level help text must be configurable content, not a PHP array.

**Authorisation**
6. Every screen and every API must be authorised **on the server**. No behaviour may
   depend on `display:none`. A screen with no permission mapping must be **unreachable**,
   not unrestricted — SmartPRS 2 leaves 26 of 146 screens (including statutory
   configuration and licence management) outside `PERM_NAV` entirely, and the engine must
   make an unmapped screen a startup error rather than an open door.
7. Authorisation must **fail closed**: unknown role → no access (SmartPRS 2 → Admin);
   no permission matrix → no access (SmartPRS 2 → allow); exception during permission
   setup → no access (SmartPRS 2 → full access, because of `safe()`).
8. Permission model must be role × module × action, and must be persisted in a typed,
   authorised store — not in a user-writable JSON blob.
9. Plan/edition/licence gating must be enforced server-side and must be verifiable; the
   SmartPRS 2 UI gate is dead code.
10. Field-level visibility must be part of the permission model — SmartPRS 2 sends PAN,
    bank account and GPS coordinates to every non-`employee` role.

**Rules and formula engines**
11. The formula engine must express **basis selection**: "basic" must be selectable as a
    contractual amount, a percentage of gross, a percentage of CTC, or a slab — SmartPRS 2
    hard-codes `basic = gross × 0.5`.
12. The formula engine must express **annualisation and de-annualisation divisors** as
    configuration: SmartPRS 2 hard-codes `gross = CTC/12` and `monthlyTDS = annual/12`.
13. The rule engine must support **threshold rules with marginal relief**, not cliffs —
    SmartPRS 2's 87A rebate costs an employee ₹62,400 for one extra rupee of income.
14. The rule engine must support **slab/band tables as first-class configuration** with
    open-ended terminal bands, effective-dating and per-state variants — SmartPRS 2 has a
    7-band array with a magic `upto: 0` terminator and PT as a single flat number.
15. Statutory contribution rules must model **employer and employee legs separately**,
    including sub-components (EPS/EPF/EDLI/admin) — SmartPRS 2 sets `employerPF = employeePF`.
16. Eligibility rules must support **"once eligible, remains eligible until the end of the
    contribution period"** semantics — SmartPRS 2 re-evaluates ESI eligibility as a plain
    `<=` on every render.
17. Tax-rate rules must support **conditional rate escalation on missing data** (no-PAN
    higher rate) as a rule, not a `Math.max` in a renderer.
18. The engine must support **effective-dated rate sets** so a mid-year statutory change
    does not require a code deploy; SmartPRS 2 has one current value per key.
19. Indirect-tax rules (GST) must model place-of-supply, rate by HSN/SAC, exempt/nil,
    reverse charge and cess — SmartPRS 2 infers place of supply from two characters of a
    GSTIN and hard-codes 9/9 and 18.
20. Commission/incentive schemes must support flat amount, percentage, slab and tiered
    structures — SmartPRS 2 supports only `base × rate / 100`.
21. Increment rules must support fixed-amount as well as percentage increments, and a 0%
    increment must be expressible — SmartPRS 2's `p !== 0` guard forbids it.
22. Every calculation must declare its **rounding rule and rounding point** as
    configuration. SmartPRS 2 mixes whole-rupee `Math.round`, 2-dp `round(x*100)/100` and
    a `round(base*rate)/100` ordering that quantises differently again.
23. All calculation must be server-side and auditable — a stored, replayable calculation
    trace per payslip. SmartPRS 2's figures are computed in the browser and are not
    reproducible.

**Validation and data entry**
24. Mandatory fields, formats, and conditional mandatory rules ("DRA and PCC required on
    new hire only") must be configuration — SmartPRS 2 hard-codes them at
    `AppController.php:1645–1721` and `:1917–1928`.
25. Accepted date formats and locale must be tenant configuration.
26. Import definitions — accepted headers, aliases, row caps, and the mapping heuristics —
    must be configuration per import type. SmartPRS 2 has three separate hand-written
    header-matching heuristics.
27. Validation must be defined **once** and enforced on both client and server from the
    same definition. SmartPRS 2 maintains `empFieldError` (JS) and
    `EmployeeFieldRules::formatErrors` (PHP) separately.

**Workflow**
28. Approval workflows and their stage lists must be configuration — SmartPRS 2's
    recruitment pipeline stages and 9 `RQ_MAP` request modules are literals.
29. Notification poll/push cadence must be configuration, not a literal `60000`.

**Platform**
30. Output escaping must be **centralised and contextual** (HTML text, attribute, URL,
    JS, CSV). SmartPRS 2 has 19 partial escapers, none of which escapes `'`, and a
    generic list renderer with none at all.
31. No schema DDL, no data creation, and no side effects on any GET path.
32. Client state must be typed and per-collection, with optimistic concurrency. SmartPRS 2
    persists ~60 collections as one blob with last-write-wins.
33. Assets must be self-hostable for on-prem editions; no unpinned, unverified CDN
    execution in the authenticated context.
34. Responses must be cacheable; the shell must not be re-downloaded per navigation.

---



---

# SOURCE: L-background-ops-observability.md

## 10. SmartPRS 3 requirements implied

Numbered, specific, testable. Each traces to evidence above.

### Scheduling engine

1. The scheduler **must** be table-driven, not code-driven: a `scheduled_jobs` registry with
   `job_key`, `cron_expression`, `timezone`, `enabled`, `scope` (platform / per-tenant),
   `lock_ttl_seconds`, `max_runtime_seconds`, `on_failure_action`, `alert_channel`. All
   twelve current entries seed as defaults. *(Replaces the 12 literals at
   `routes/console.php:143-266`.)*
2. Every scheduled job **must** declare an explicit overlap-lock TTL proportional to its
   cadence; the engine must reject a TTL longer than 10× the interval. *(Because
   `withoutOverlapping()` today defaults to 24 hours for a 5-minute job — DEF-L-02.)*
3. Every scheduled job **must** record a run row: `started_at`, `finished_at`, `status`,
   `items_attempted`, `items_succeeded`, `items_failed`, `error_summary`. This is the
   scheduler heartbeat that does not exist today.
4. The scheduler **must** expose a health signal that fails when any enabled job has not
   completed within its expected interval × 2. *(No such check exists — §Q7.)*
5. Every scheduled job's timezone **must** be resolvable per tenant, not from a single
   global `config('app.timezone')` frozen by `config:cache`. *(DEF-L-24.)*
6. No destructive command may be schedulable without an explicit environment allow-list and
   a per-install opt-in flag. *(SEC-L-01.)*

### Tenant iteration and isolation

7. The engine **must** provide per-item (per-tenant, per-record) fault isolation as a
   framework guarantee, not a per-command choice: a failure on tenant 3 of 20 must be
   recorded, alerted, and must not prevent tenants 4–20 from being processed.
   *(DEF-L-03, DEF-L-04; `SubscriptionAlerts.php:135-137` is the only correct
   implementation today.)*
8. A job that processed fewer items than it attempted **must not** report success.
   *(Today `AbsenceService` and `GreetingService` return an under-count and exit SUCCESS.)*

### Rule engine — thresholds, windows and audiences

9. The rule engine **must** support an **N-day-before-a-date reminder ladder** as
   configuration — an ordered list of day offsets, per rule, per tenant — because
   `SubscriptionAlerts.php:35` hard-codes `[15,7,3,1,0]` and `ProbationService.php:36`
   hard-codes `[30,15,7,0]`.
10. Ladder evaluation **must** be **"first run at or past the threshold, deduped on the
    ledger key"**, never exact day equality, so a missed run catches up instead of losing
    the reminder permanently. *(DEF-L-05 — four separate call sites.)*
11. The rule engine **must** support a **grace period** as a per-plan configurable duration
    with a configurable per-day reminder cadence, and a configurable terminal action
    (suspend / notify only / do nothing). *(`GRACE_DAYS = 7`, `SubscriptionService.php:34`;
    auto-suspend is unconditional at `SubscriptionAlerts.php:60-69`.)*
12. The rule engine **must** support an **alert window with both an upper and a lower
    bound**, because `ComplianceController.php:77-79` has only an upper bound and reports
    years-dead certificates forever. *(DEF-L-18.)*
13. The rule engine **must** support **bucketing thresholds** (expired / critical / soon) as
    configuration, not the literals `0` and `7` at `ComplianceController.php:80`.
14. Every notification rule **must** carry a configurable **audience** expressed as roles,
    named users, or a distribution list — because
    `ComplianceController.php:256` and `ProbationService.php:250-253` hard-code role arrays.
15. Audience resolution **must** fail **closed** and **must** raise an operator alert when it
    resolves to nobody. *(Today `ComplianceController.php:269-272` fails closed correctly
    but silently — the tenant simply never gets a digest and nobody knows.)*
16. Every notification rule **must** carry a configurable **send hour and timezone per
    tenant**. *(Already achieved for greetings/absence/probation via `app_settings`; must
    become universal.)*
17. Digest rules **must** carry a configurable item cap and an overflow behaviour.
    *(`ComplianceController.php:206` hard-codes 25.)*
18. The engine **must** support a configurable **working-week pattern** and a
    holiday/leave/WFH exclusion set as first-class inputs to any "did this person work?"
    rule, because `AbsenceService.php:44-45` defaults to Mon–Sat and the docblock at
    `:29-31` records that **no WFH concept exists**.
19. The engine **must** support a configurable **probation duration** with an explicit
    month-arithmetic policy (calendar clamp vs day-count), because
    `ProbationService.php:118` uses `Carbon::addMonths` whose clamping behaviour is
    invisible to the user (31 Jan + 6m → 31 Jul; 31 Aug + 6m → 28 Feb).
20. Transfer type **must** be an enumerated, referenceable value, never a substring match.
    *(`TransferService.php:126-127` — `"Inter-Department Company Liaison"` currently
    classifies as a company move and nulls the reporting hierarchy.)*
21. Any rule that **clears** employee data **must** snapshot the cleared values into the
    transaction record. *(`TransferService.php:150-157` clears four fields and snapshots
    none of them — DEF-L-15.)*

### Integration and ingestion

22. Every external-data pull **must** use a **durable watermark** (`last_successful_sync_at`),
    not a fixed relative window, with a configurable maximum look-back — so a scheduler
    outage self-heals instead of losing data permanently.
    *(DEF-L-01: `--days=1` at `routes/console.php:225` is the highest-impact hard-coded
    value in this area.)*
23. Every external call **must** have an explicit, configurable timeout and an explicit
    retry policy. *(SEC-L-08: five payment-gateway calls have **no timeout at all**;
    `->retry()` appears nowhere in `app/`.)*
24. No external call may be made synchronously from inside a scheduled task holding a lock.
    *(`WaService.php:146` is called inline per tenant inside `billing:alerts` — DEF-L-21.)*
25. Ingestion **must** preserve the idempotent-upsert-on-natural-key design that works today
    (`(tenant_id, emp_code, punch_at, source)` with a matching unique index) and the
    quarantine-and-replay design for unmatched codes. **These are the two best pieces of
    engineering in this area and must survive the rebuild.**
26. Every integration **must** expose a freshness signal (`last_successful_sync_at`,
    `last_error`, `consecutive_failures`) and an alert when it goes stale.

### Notification delivery

27. There **must** be exactly one delivery path for a given business event. *(DEF-L-09: one
    event, two paths, one of which bypasses `mail_log` and per-company SMTP entirely.)*
28. A delivery ledger row **must** distinguish `queued`, `sent`, `delivered`, `failed` and
    `skipped`, and no caller may treat "queued" as "sent". *(DEF-L-13:
    `SubscriptionAlerts.php:107-108` records `sent` on the basis of a row id returned before
    any send attempt.)*
29. A message that cannot be delivered because no transport is configured **must** raise an
    operator-visible condition, not a silent `skipped`. *(DEF-L-11:
    `MailService.php:148-152`.)*
30. Notification idempotency **must** remain `(kind, dedupe_key)`-based, and the dedupe key
    **must** be constructed from the business event, not from the calendar year.
    *(`GreetingService.php:195` uses year-only, which is correct for greetings but is not a
    general rule.)*
31. A suppressed duplicate and a failed send **must** be distinguishable at the call site.
    *(DEF-L-12: `NotificationService::send()` returns `null` for both.)*
32. Credential-class messages **must** have a guaranteed delivery path that does not depend
    on a worker being installed, with retry. *(The current `'sync' => true` bypass is
    correct in intent — it fixes a real incident recorded at `MailService.php:102-106` —
    but has **no retry**: a failure is logged as a warning and never attempted again.)*
33. No message payload may carry file bytes. Attachments must be stored and referenced.
    *(SEC-L-07: `attach_b64` in `jobs.payload`.)*
34. No delivery ledger may store credential material. `wa_log.body_values` currently stores
    OTPs and plaintext first-login passwords. *(SEC-L-03.)*

### Queue and worker

35. Work **must** be routed to named queues by class (interactive / bulk / integration /
    reporting), not to one queue named `mail`.
36. Every job **must** implement `failed()`, and a `Queue::failing()` listener **must** write
    to an operator-visible table with an alert. *(DEF-L-23: grep for `Queue::` returns zero
    matches.)*
37. `failed_jobs` and every log-shaped table **must** have a scheduled pruning policy.
    *(DEF-L-29: six tables grow without bound; `activitylog:clean` is not scheduled.)*
38. A job **must not** swallow its own exceptions if it declares a retry policy.
    *(DEF-L-10: `NotifyLateArrivals`'s `tries`/`backoff` are dead config.)*
39. Queue depth, oldest-job age and worker liveness **must** be exposed as health signals.
40. Drained backlogs **must not** deliver stale time-sensitive notifications: every
    time-sensitive job needs a `retryUntil()` or an age check.

### Error handling

41. There **must** be one application exception handler with `report()` enrichment attaching
    tenant id, user id and correlation id to every logged exception.
    *(`bootstrap/app.php:44-46` is empty.)*
42. A catch block **must** either (a) log with structured context, (b) rethrow, or (c) be
    explicitly annotated as a benign-by-design suppression with a stated reason. A lint rule
    **must** enforce this. *(426 of 843 catch blocks currently do none of the three.)*
43. **No security, entitlement or authorization control may fail open.** Every one of the
    five identified fail-opens must become fail-closed with an alert. *(SEC-L-14.)*
44. **No control whose purpose is a legal or contractual obligation (audit write, privacy
    wipe, statutory calculation) may be best-effort.** *(SEC-L-05, SEC-L-15.)*
45. Raw exception messages **must never** reach an HTTP client. *(194 sites do today.)*
46. There **must** be one API error envelope. *(Four incompatible shapes coexist.)*
47. Any operation that mutates money or ledger state **must** be transactional end-to-end.
    *(DEF-L-32 and the payroll regenerate at `PayrollGenController.php:2389-2620`, where the
    old payslips are deleted 230 lines before a transaction opens.)*

### Schema

48. **No table or column may be created at run time.** All nine runtime-created tables and
    eleven runtime-created columns catalogued in §2 must become migrations. The application
    database user must not hold `CREATE`/`ALTER` in production.
    *(DEF-L-08 — this is the root cause of the four money defects in §Q3 items 1–2.)*

### Logging and observability

49. Every log line **must** carry a correlation id, tenant id and actor id.
    *(None exist today.)*
50. Authentication events — success, failure, lockout, password change, role change,
    permission denial — **must** be logged and audited.
    *(`AuthController` contains zero `Log::` calls.)*
51. Exception messages written to logs **must** pass through a redactor. *(SEC-L-09,
    logging item #9 in §Q6: a failed insert on `employees` currently writes PAN, bank
    account, salary and DOB into the log file.)*
52. Logs **must** rotate with a configurable retention. *(DEF-L-22: the `single` channel is
    unrotated and unbounded.)*
53. Alerting **must not** depend on the subsystem it monitors. *(DEF-L-07: the error digest
    is delivered through the queue whose failure it cannot detect.)*
54. Alert thresholds, severities and recipients **must** be configuration.
    *(`ErrorDigest.php:48, 76, 79, 108`.)*
55. The health endpoint **must** report component-level status — scheduler last-run, worker
    liveness, queue depth and age, integration freshness, backup age, migration state —
    not merely that the framework booted. *(`/up` today reports only the latter.)*

### Backup and recovery

56. Backups **must** support at least one **off-box** destination, and an install with no
    off-box destination **must** raise a persistent operator warning.
    *(`config/backup.php:72-74`.)*
57. Backup archives **must** be encrypted by default. *(SEC-L-06.)*
58. `.env` and any file containing secrets **must** be excluded from the archive; secrets
    needed for recovery must be handled by a separate, deliberately-managed path.
59. The backup set **must** include everything needed for recovery — notably
    `storage/app/keys` and uploaded documents, both of which are excluded today.
    *(SEC-L-13, DEF-L-26.)*
60. Retention **must** be explicit configuration owned by the product, not an unpinned
    package default. *(`config/backup.php:88-92`.)*
61. Backup runs **must** be monitored: age, size, success, and a verify step. Failure
    **must** alert. *(§Q5: nothing exists.)*
62. A **restore procedure must exist, be documented, and be exercised automatically** — at
    minimum a scheduled restore-to-scratch verification. *(Today there is no data restore
    path at all; only the code-only self-update rollback at `updater/updater.php:140-159`.)*
63. The database connection name backed up **must not** be a literal.
    *(`config/backup.php:45-46` hard-codes `'mysql'`.)*

### Operational runtime

64. The scheduler and the worker **must** ship with the product, be installed by the
    installer, and their absence **must** be detected and reported by the application
    itself. *(DEF-L-27 — this is the single highest-leverage change in this area: it turns
    the most likely and most silent production failure into a visible one.)*
65. Deployment **must** restart workers. *(`queue:restart` appears nowhere.)*
66. Every console command that deletes or rewrites data **must** require an explicit scope
    (`--tenant` or `--all`), offer `--dry`, refuse to run in `production` without a
    confirmation, and write an audit record of what it changed. *(SEC-L-10, SEC-L-11.)*
67. Console commands **must** have automated tests. *(DEF-L-34: zero exist for any of the
    eighteen.)*

---



---

# SOURCE: M-quality-performance-testing.md

## 10. SmartPRS 3 requirements implied

Numbered, specific, testable. Each traces to a finding above.

### 10.1 Concurrency, transactions and idempotency

1. Every business operation that writes more than one row **must** execute inside a single database
   transaction, with every side effect inside it. Specifically: a payroll run's header, payslips,
   commission locks, commission passbook debits, OT paid flags, loan-recovery ledger rows,
   loan instalment counters and clawback stamps must commit or roll back **together**. (C-2, C-3)
2. The platform must provide a **named lock primitive** (advisory lock or `Cache::lock`) and a
   **`lockForUpdate` convention**, and the rule engine must be able to declare which operations
   require which lock. (zero locks today)
3. Every sequence and counter must be enforced by a **database constraint**, not by application
   code: `unique(tenant_id, company_id, period_id)` on payroll runs; `unique(run_id, employee_id)`
   on payslips; `unique(tenant_id, series, number)` on invoices **across every table that shares a
   series**; `unique(licence_id, device_uid)`; `unique(gateway, gateway_txn_id)` on payments;
   `unique(tenant_id)` on subscriptions; a check constraint on coupon redemptions. (C-1, C-5, C-8,
   C-10, C-25)
4. Every mutating endpoint must accept an **idempotency key** and return the original result on a
   replay. Double-clicking Generate, Approve, Pay or Download must be a no-op. (C-1, C-19)
5. **Numbering must be issued by the database**, from a per-tenant per-series sequence table with a
   row lock — never by `MAX + 1` or `COUNT + 1` computed in PHP. (C-8)
6. **Balances must be ledger-derived and constraint-enforced**, never computed by a `SUM()` that is
   then acted on outside a transaction. A payment that would exceed the outstanding balance must be
   rejected by the database, not by a PHP comparison. (C-7)
7. **Leave must have a real ledger** — accrual, consumption, encashment, lapse and carry-forward as
   posted rows — not a balance derived on every read. The balance check and the consumption must be
   one atomic operation. (C-11)
8. **Seat counting must be a constraint, not a check.** Either a counter row updated under lock, or
   a partial unique index that makes the (N+1)th active employee unrepresentable. Seat checks must
   **fail closed** on error. (C-5)
9. The **audit chain must be per-tenant, appended under a lock or by a single writer, and a failure
   to append must fail the business transaction**, not be swallowed. (C-6)
10. **Approval transitions must be guarded by the current state** (`where('status', $expected)` with
    an affected-rows check) so a decision can never be replayed or reversed silently. (C-19)
11. **Disbursement must be a two-phase operation**: reserve the lines under a lock, deliver the
    file, confirm. A failed delivery must not leave salaries marked disbursed, and two concurrent
    downloads must not both receive the same lines. (C-14)
12. **Punch direction derivation must be a deterministic pure function of the stored punch set**,
    computed at read time or under a per-employee-per-day lock — never a read-compute-write cycle
    over shared rows. (C-16)
13. **Employee codes must be issued from a per-tenant sequence**, not drawn from a 9,000-value
    random pool, and must never be silently absent on import. (C-15)
14. **Provisioning must be transactional and resumable.** (C-24)
15. **Renewal must advance the period and raise the invoice in one transaction**, and the renewal
    run itself must hold a lock. (C-9)

### 10.2 Performance and scale

16. The system must support **10,000 employees per tenant with 5 years of history**. Every list
    endpoint must be paginated by default with a server-enforced maximum page size; **no endpoint
    may return an unbounded result set**.
17. **No boot payload.** Each screen fetches what it needs; nothing returns "every payslip ever".
    (PERF-1)
18. **The application must never fabricate business records as a side effect of a read.** (PERF-2)
19. **Payroll generation must be a queued, resumable, observable job** with per-employee progress,
    and must issue **O(1) queries per run, not O(N)**: one grouped attendance aggregate, one grouped
    leave aggregate, one grouped loan/advance/clawback fetch. (PERF-3)
20. **Report ranges must be capped** and rendered by a streaming exporter, never by loading the
    range into PHP twice. PDF generation must be queued. (PERF-4)
21. **Schema must be fixed at deploy time.** Zero `Schema::create` / `Schema::table` in request
    handlers; zero `Schema::hasColumn` in a hot path. Schema introspection must be cached per
    request at worst, and eliminated at best. (PERF-9, 156 + 738 sites)
22. **All mail, WhatsApp and SMS must be queued**, individually retriable, and rate-limited. No
    outbound call may happen inside a punch-ingest or web request. (PERF-7)
23. Every filtered column must be indexed, and **no query may wrap a column in a function**
    (`LOWER()`, `DATE()`, `DATE_FORMAT()`). Normalise on write — a stored lower-case code column or
    a case-insensitive collation — not on read. (46 + 15 sites)
24. Required composite indexes at minimum: `attendance (tenant_id, date)`,
    `attendance (tenant_id, employee_id, date)`, `audit (tenant_id, created_at)`,
    `payslips (employee_id, period)`, `ledger (parent_id)`.
25. **All timeouts and retries on outbound HTTP must be explicit and configured**, never absent.
    (five Razorpay calls with none)

### 10.3 Data lifecycle, retention and privacy

26. **Retention must be configuration.** Per entity, per tenant: a retention period, a legal-hold
    flag, an action on expiry (purge / anonymise / archive), and an owner. The rule engine must
    support *"retain wage registers for N years; retain rejected-candidate PII for M months; retain
    attendance for P years"* with N, M, P as tenant configuration.
27. **Erasure must be a first-class operation**: a data-principal erasure request that identifies
    every row and file holding that person's data, applies legal holds, anonymises what must be
    retained, deletes the rest, and leaves an audit record of the erasure itself.
28. **Export-my-data must exist** as a data-principal-facing operation, not only as an HR archive
    download.
29. **Consent must be a record**, not a timestamp column: purpose, scope, version, grant, withdrawal.
30. **Employee exit must run a configurable lifecycle**: what is retained, for how long, what is
    masked immediately (bank account, national ID), what documents are deleted, when the record is
    archived, and when it is purged.
31. **Tenant cancellation must run a configurable lifecycle**: an export window, then a hold period,
    then a verified purge — with evidence produced.
32. **Identifiers classified as sensitive (national ID, PAN, bank account, IFSC) must be encrypted
    at rest and masked by default in every read path**, with unmasking a separately audited
    permission.
33. **No PII file may ever be served from an unauthenticated public path.** Every document must be
    streamed through an authorised, tenant-checked controller. (D-4, S-3)
34. **No business record may be destroyed without an archived copy.** A wage register, an approved
    request, a payment ledger row and an audit row must be immutable once created; corrections are
    new records, never overwrites or deletes. (D-2, D-3)
35. **Every destructive operation must be tenant-scoped by construction** (the tenant is a required
    parameter, not an optional flag), must have a dry-run mode, and must require an explicit typed
    confirmation naming the tenant and the row count. (D-6)
36. **Migrations may not delete business data.** Ever. (D-1)
37. **Restore must be a tested, scheduled exercise** with a documented RPO/RTO, not "restore last
    night's backup".

### 10.4 Quality engineering

38. **CI is mandatory from commit one**: the full suite on the production database engine, plus
    static analysis at a pinned level with a ratcheting baseline, plus a formatter check, plus a
    coverage report with an enforced floor on the money and statutory modules.
39. **The test database must be the production engine.** No SQLite substitution.
40. **A seeded fixture layer using factories and the real role/permission seeder** — never raw
    inserts, never roles created on the fly with no permissions, never DDL in a fixture.
41. **Concurrency must be tested**, not only sequentially. The suite must include parallel-request
    tests for every operation in §10.1: duplicate payroll generation, simultaneous seat additions,
    simultaneous invoice creation, simultaneous webhook delivery, simultaneous bank-file download,
    simultaneous leave application, simultaneous approvals.
42. **Every calculation must have a golden test with an externally-derived expected value**, never
    one that restates the implementation's own formula. (`PayrollMathTest`'s net assertions and
    `BillingTest` #8's date assertion are the anti-patterns to avoid.)
43. **Statutory tables must live in configuration with an effective date, and the golden tests must
    read the same table the engine reads** — never a hand-copied snapshot.
44. **Guards must fail closed, and there must be a test that provokes each guard's error path** and
    asserts denial.
45. **A performance regression suite** must run against a seeded 10,000-employee tenant with 5 years
    of history and enforce a query-count budget per endpoint and a response-time budget.
46. **A destructive-operation test suite**: every delete and archive path must have a test proving
    it is tenant-scoped, dependency-checked, transactional and reversible.

---

### 10.5 Q9 — THE SmartPRS 3 GOLDEN-TEST MATRIX SEED

Every scenario below **must** have an exact-value regression test in SmartPRS 3. Each names the
scenario and states what must be asserted. Priority: **P0** = money, statutory filing, data
isolation or access control.

#### A. Payroll formulas — each against an externally-derived worked example

| P | Scenario | Must assert |
|---|---|---|
| P0 | **CTC → component split.** CTC ₹6,00,000, no salary structure defined | gross 50,000.00 · basic 25,000.00 · hra 10,000.00 · special 15,000.00 — the exact four figures, not their relationship |
| P0 | **PF employee at, below and above the wage ceiling.** Basic 25,000 / 15,000 / 12,000 | 1,800.00 / 1,800.00 / 1,440.00 — and that the ceiling, the rate and whether the ceiling applies are all read from configuration |
| P0 | **PF employer split.** Basic 25,000 | EPS = 8.33 % of min(wage, 15,000) capped 1,250.00; EPF = employer 12 % − EPS = 550.00; EDLI = 0.5 % capped 75.00. **These three figures go on the EPFO ECR — assert each separately.** |
| P0 | **ESI both sides at the ceiling boundary.** gross 20,999 / 21,000 / 21,001 | employee = ceil(gross × 0.75 %); employer = ceil(gross × 3.25 %); both zero above the ceiling. Assert the **rounding direction is UP to the next rupee** at a value where it matters (e.g. 20,001) |
| P0 | **ESI contribution-period lock.** Employee contributing in April crosses ₹21,000 in July | ESI continues through September and stops in October. Assert the exact month it stops, in both Apr–Sep and Oct–Mar periods, and that a failure to read history **does not** silently drop the lock |
| P0 | **PT per state.** The 16 states in the current table, at each slab boundary | Telangana 14,000→0, 15,000→0, 15,001→150, 20,000→150, 20,001→200; Andhra 25,000→200; Karnataka 24,999→0, 25,000→200; Gujarat 12,000→0, 18,000→200; Maharashtra 7,500→0, 9,000→175, 15,000→200; Delhi/UP/Haryana/Rajasthan 90,000→0 |
| P0 | **PT February uplift.** Maharashtra, February | 15,000→**300**, 9,000→**175** (the uplift applies **only** at the top slab) |
| P0 | **PT Maharashtra female exemption.** | female 20,000→0, 25,000→0, 30,000→200; male 20,000→200; **Telangana female 20,000→150** (the exemption must not leak across states); with the per-company switch off, female 20,000→200 |
| P0 | **Per-day rate divisor, all three bases.** CTC ₹6,00,000, a 31-day month with 4 week-offs and 1 holiday (26 working days), 2 absent days | `working`: paid = 24/26 → factor 0.9231. `calendar`: paid = 29/31 → factor 0.9355. `fixed30`: factor (30−2)/30 = 0.9333. **Assert the three resulting net figures, and that the basis is read from configuration.** |
| P0 | **LOP end-to-end from real attendance.** 26 working days, punches on 24 | the payslip's gross, each deduction, and the net — as absolute rupee figures, from `generate()`, not from `computeSlip()` |
| P0 | **Zero punches pays 100 %.** An employee with no attendance rows at all | full salary, not zero. **This safeguard exists because a dead biometric reader must not zero a payroll; assert it explicitly and name it in the test.** |
| P0 | **Mid-month joiner.** DOJ on the 16th of a 31-day month | the working-day denominator is computed **from the DOJ**, and the exact prorated net is asserted, on all three bases |
| P0 | **Net-pay floor.** An employee whose deductions and recoveries exceed gross | net = 0.00, never negative, and the shortfall is visible on the payslip note |
| P0 | **Recovery order.** Net 20,000 with a 15,000 loan EMI, a 10,000 advance and a 5,000 clawback all due | loan EMI first (whole instalments only — 15,000 taken); advance next, partial to the remaining 5,000; clawback nothing, and **the clawback shortfall stays open on its row**. Assert each recovery amount, the net, and the state of all three source rows |
| P0 | **Recovery is stamped exactly once.** After generate | `loan_recoveries` has one row, `loans.installments_paid` advanced by exactly one, the clawback is stamped with the run id, **and the advance is stamped too** (which SmartPRS 2 never does) |
| P0 | **Component-based structure with a `balance` component.** A structure whose fixed and percentage components leave a remainder | the balance component absorbs exactly the remainder, gross is unchanged, and PF is computed on the **component** Basic |
| P0 | **TDS (Sec 192, new regime).** A known annual CTC | the exact monthly TDS, with the standard deduction, the 87A rebate and cess each asserted separately |
| P1 | **Probation / internship suppress PF, PT and TDS but not ESI, LWF or conveyance** | each of the six figures asserted |
| P1 | **Overtime.** 10 approved OT hours at a 2× multiplier on CTC ₹6,00,000 | the exact OT amount — **and that the hours-per-day and days-per-month basis is configuration, not `/26/8`** |
| P1 | **Night-shift allowance.** 3 nights worked on a roster with a night shift carrying an allowance | exactly 3 × allowance, counted per distinct punch date, and not credited on a rostered week-off |
| P1 | **Regenerate reverses every side effect.** Generate, then regenerate | commission `locked_at` cleared and its passbook debit removed; OT back to `approved` with `paid_run_id` cleared; the loan counter decremented **exactly once** and a closed loan re-opened; the clawback stamp cleared; the advance stamp cleared. Then assert the second run produces **byte-identical** figures to the first |
| P1 | **A regenerate that fails mid-way leaves the prior run intact.** Force a failure after the delete | the previous run and its payslips still exist |
| P2 | **Sandwich rule.** Absent Friday and Monday with Saturday and Sunday off, rule ON then OFF | 4 LOP days vs 2 |
| P2 | **Gratuity and statutory bonus registers** | exact figures against a worked example |

#### B. Attendance and shifts

| P | Scenario | Must assert |
|---|---|---|
| P0 | **A shift's explicit off-day beats the working-days fallback** | the day is a week-off, not an absence |
| P0 | **A public holiday is not an absence** | zero absences, zero notifications |
| P0 | **A failing holiday lookup must not flag the workforce absent** | force the holidays query to throw → **zero** absences recorded and **zero** emails sent. (In SmartPRS 2 this fails the whole workforce absent and emails every one of them.) |
| P0 | **`holidays.applies_to` is honoured** | a holiday scoped to one company/branch does not apply to another |
| P0 | **One "Present" definition.** The same employee-day queried through the report, payroll, the absence sweep and the ESS "today" endpoint | all four return the same verdict |
| P0 | **Punch idempotency across every ingest path.** The same punch delivered twice via `/iclock`, SBB, SmartEPT and the vendor pull | exactly one stored row per path, and the natural key `(tenant, employee, instant, source)` is what enforces it |
| P0 | **Punch idempotency under concurrency.** The same punch delivered twice **simultaneously** | exactly one row. (SmartPRS 2's tests only ever replay sequentially.) |
| P0 | **Quarantine and replay.** An unmapped device PIN | held, never dropped; promoted exactly once on mapping; a second promotion is a no-op; the same PIN on a different device serial is **not** promoted |
| P0 | **Single-device alternation under concurrency.** Two punches for the same employee-day delivered simultaneously | the stored sequence is a correct alternation. **This is the C-16 race; it must have a parallel test.** |
| P0 | **Wall-clock storage.** A punch at 09:41 local | stored as 09:41, never shifted to UTC; an offset-bearing timestamp is **rejected**, not guessed |
| P1 | **Open session (no OUT)** | the day does not compute as zero hours and does not corrupt the month |
| P1 | **Late derivation is single-sourced** | the report, the payroll cut and the notification all agree on the same employee-day |
| P2 | **Attendance correction rewrites the register on approval** | the corrected rows exist, the originals are superseded (not silently deleted), and payroll reads the corrected value |

#### C. Leave

| P | Scenario | Must assert |
|---|---|---|
| P0 | **Loss-of-Pay leave is NOT paid.** Apply LOP through the UI path, approve it, run payroll | the LOP days are deducted. **This is the exact defect live in SmartPRS 2 (Rank 2); it must be the first leave test written.** |
| P0 | **Paid leave IS paid.** Same flow with a paid type | the days are credited and the net is unchanged from a full-attendance month |
| P0 | **Balance days and payroll days agree.** A Friday-to-Monday leave with Saturday and Sunday off | the balance charged and the attendance credited use the **same** definition — assert both numbers |
| P0 | **Balance cannot go negative under concurrency.** Two applications for the last remaining day, submitted simultaneously | exactly one succeeds |
| P0 | **Carry-forward is applied.** A type with `carry_forward` on and an opening balance | the opening balance is in the available balance. (SmartPRS 2 reads the flag and never uses it.) |
| P0 | **The leave year basis is configuration.** Calendar year vs financial year | balances reset on the configured boundary |
| P1 | **Accrual.** A monthly-accrual type, mid-year | the accrued balance at a given date |
| P1 | **An employee cannot apply on another's behalf** | any non-manager role is forced to self, server-side |
| P1 | **A decision cannot be replayed.** Approve, then approve again and then reject | the second and third calls are refused; remarks, decided_by and decided_at are unchanged; no second email |

#### D. Statutory configuration and rule precedence

| P | Scenario | Must assert |
|---|---|---|
| P0 | **No override rows change nothing.** An install with an empty override table | every rate is byte-identical to the built-in default. **The safety invariant.** |
| P0 | **Full scope precedence.** Overrides at tenant, state, company, location and branch for the same rate | branch > location > company > state > tenant, asserted by removing one level at a time (5 assertions) |
| P0 | **Per-kind resolution.** A PT override and a PF override in scope together | neither shadows the other |
| P0 | **Effective dating.** Two PF overrides with different effective dates | the newest in-force row wins **for the payroll period being computed**, not for today's date |
| P0 | **Effective dating is period-based, and a rate change mid-year does not retrospectively alter a closed period** | re-running a closed month reproduces the original figures |
| P0 | **Per-state PT slab maps merge, not replace** | a tenant-level Kerala slab and a company-level Bihar slab are both present after the merge |
| P0 | **Malformed configuration cannot break payroll.** A slab with a negative amount, a non-array payload, a bad state name | rejected at save with a named error; payroll runs on the last-good configuration |
| P0 | **The database path is exercised, not only the in-memory merge.** | SmartPRS 2's 8 `StatutoryConfig` tests pre-seed a static cache so the DB is never touched — the resolution query itself is untested. SmartPRS 3 must test the real read path. |
| P1 | **A configuration change is previewable.** | a "what would this do to next month's payroll" preview matches what the run actually produces |

#### E. Rule precedence beyond statutory

| P | Scenario | Must assert |
|---|---|---|
| P0 | **Salary component resolution: employee > team > company** | the correct component set at each level, asserted by removing one level at a time |
| P0 | **Late policy resolution: employee > team > company** | one policy resolves, deterministically, and it is the **same** policy the report, the payroll cut and the notification all use |
| P0 | **Shift resolution: roster > employee default > company default** | the correct shift per employee per day |
| P0 | **Pay-cycle resolution and the transition guard.** Switching from calendar to a 21st→20th cut-off | the run refuses with a named gap/overlap error until acknowledged, and the bridging period is offered. Assert the exact gap dates |
| P0 | **Approver resolution picks exactly one approver, and never the requester** | one approver id, and a self-request escalates |

#### F. Security, access control and isolation

| P | Scenario | Must assert |
|---|---|---|
| P0 | **Every guard fails CLOSED.** Force the role/permission lookup to throw, at each guard | **denied**, with a 403 — never allowed. One test per guard family (authorisation, seat check, licence gate, payroll-period lock, subscription state). |
| P0 | **Every mutating route is denied to every role that must not reach it** | a role × route matrix test over the full routed surface, asserting the status code **and** that no row was written |
| P0 | **No endpoint returns another tenant's data.** Two tenants, identical `emp_code`s, then every list, detail, report, export, document and payslip endpoint | zero cross-tenant rows, and a direct-id fetch across tenants returns 404, not 403 (no existence oracle) |
| P0 | **No endpoint writes another tenant's data.** Every upsert's match key includes the tenant | the C-18 `attendance_ratings` shape must be impossible |
| P0 | **A null-tenant actor does not gain global scope.** A platform user and a self-hosted user performing a tenant-scoped delete | the operation is scoped or refused — never global |
| P0 | **Employee self-service isolation.** A plain employee | sees only their own record through every path **including global search**, and cannot reach another employee's payslip by id |
| P0 | **Salary-run segregation of duties.** A single user holding the preparer role | cannot complete the approve **and** the disburse step. **The roles that satisfy each step must be configuration, and the test must assert that no single configured role satisfies both by default.** |
| P0 | **No PII document is reachable without authentication.** Upload through onboarding, then request the file's storage path directly | 404/403 |
| P0 | **The audit chain verifies after concurrent writes.** Write N audit rows from M parallel tenants, then verify | the chain verifies. (SmartPRS 2 forks.) |
| P0 | **An audit write failure fails the business transaction** | the business row is not committed either |
| P0 | **Login.** Valid, wrong password, inactive user, throttled, session regenerated, logout invalidates | six assertions — **none of which exists in SmartPRS 2** |
| P0 | **`POST` of the application's own state (or any equivalent bulk-write endpoint) is refused to a non-admin** | 403, and nothing written |

#### G. Money outside payroll

| P | Scenario | Must assert |
|---|---|---|
| P0 | **Invoice numbers are consecutive and unique within a financial year, across every table sharing the series** | generate 5 across both tables → 5 distinct consecutive numbers |
| P0 | **Invoice numbering under concurrency.** 10 simultaneous generations | 10 distinct numbers, zero duplicates, zero exceptions |
| P0 | **Deleting a document does not cause the next number to duplicate** | create 3, delete #2, create a 4th → no duplicate |
| P0 | **A payment webhook delivered twice simultaneously credits once** | exactly one payment row, invoice paid once |
| P0 | **A manual payment and a gateway webhook for the same invoice cannot double-credit** | the sum of payments never exceeds the invoice total |
| P0 | **A payment cannot exceed the outstanding balance, under concurrency** | two simultaneous part-payments of the full remainder → one succeeds |
| P0 | **Renewal raises exactly one invoice and advances the period atomically** | force a failure after the invoice → the period is not advanced **and** the invoice is rolled back |
| P0 | **A seat cap blocks the (N+1)th employee — through every door**: the add form, CSV import, self-onboarding and recruitment hire | refused at all four. (SmartPRS 2 enforces it at one.) |
| P0 | **A seat cap holds under concurrency.** Two simultaneous adds at N−1 | one succeeds |
| P0 | **Archiving an employee does not silently free a licence seat** unless the configuration says it does | assert both configurations |
| P1 | **Coupon redemption cannot exceed `max_uses`, under concurrency** | |
| P1 | **Provisioning is transactional** | force a mid-provision failure → no half-created tenant |

#### H. Lifecycle, delete and retention

| P | Scenario | Must assert |
|---|---|---|
| P0 | **A wage register cannot be destroyed.** Attempt to regenerate an approved month | refused; attempt to regenerate a draft → the prior version is **archived**, retrievable, and the archive records who replaced it |
| P0 | **Every destructive command requires a tenant and a confirmation** | invoking without either is refused, and `--dry` deletes nothing while reporting a correct count |
| P0 | **No migration deletes business data** | a migration test asserts zero rows removed |
| P0 | **Deleting a parent is transactional and dependency-checked** | a candidate with interviews, a drive with candidates, a licence with events, a job opening with applicants — each refuses or cascades atomically, never half-way |
| P0 | **An erasure request removes or anonymises every row and file for that person**, honours legal holds, and leaves an audit record | enumerate the tables and files afterwards |
| P0 | **Retention expiry acts.** Set a 1-day retention on a test entity, advance the clock, run the sweep | the row is purged/anonymised, the action is audited, and a row under legal hold is untouched |
| P0 | **Tenant cancellation runs its configured lifecycle** | export produced, hold observed, purge verified |
| P1 | **An archived employee can be un-archived** | the record returns intact |
| P1 | **A soft-deleted row is invisible to every read path**, including raw query-builder paths | assert across reports, exports, seat counting and payroll |

#### I. Cross-cutting, per release

| P | Check | Expected |
|---|---|---|
| P0 | The full suite runs on the **production database engine** | green |
| P0 | The full suite runs under **every supported edition/deployment configuration** | green, or the edition-dependent tests explicitly skipped |
| P0 | **Query-count and response-time budgets** against a seeded 10,000-employee, 5-year tenant | within budget for `/dashboard`, employee list, attendance report (1 month), payroll generate, approvals inbox |
| P0 | **Parallel-request suite** for every operation in §10.1 | no duplicates, no lost updates, no forked chains |
| P0 | Static analysis at the pinned level | no new findings against the baseline |
| P0 | Coverage floor on the payroll, statutory, leave, billing and authorisation modules | enforced |
| P1 | Formatter check | no diff |
| P1 | `migrate:status` shows no pending migration on any environment | all ran |
| P1 | Restore drill: restore a backup into a clean database and run the suite against it | green |

---



---

# SOURCE: N-product-business-modules.md

## 10. SmartPRS 3 requirements implied

Numbered, specific, testable. Each names the SmartPRS 2 behaviour that forces it.

### Module and catalogue
1. There must be **exactly one module registry**, held as data, that the permission model, the plan gate, the edition gate, the licence and the price list all read from — because SmartPRS 2 has six disagreeing vocabularies (16 / 10 / 14 / 50 / 57 / 107) and the licence agreement sells a "module catalogue" that exists nowhere.
2. A module must be **installable, licensable and hideable as data**, not by editing a PHP constant — because `Edition::level()` returns a literal `3` and every priced tier ships identically.
3. A module pack must be able to add entities, fields, registries, screens, rules, alerts and reports **without a core code change** — because the collections vertical today is 57 rows in one controller's config array.

### Configuration engine
4. Every commercial constant must be configuration: GST rate, additional-company fee, cycle→months, cycle→discount, unknown-cycle fallback, grace days, seat-free statuses, and the seat definition itself.
5. The configuration engine must support **scoped, effective-dated values** (tenant / company / state / branch), because `StatutoryConfig` + `EffectiveDated` already prove the pattern works and it is the product's best feature.
6. A customer must be able to define a **new entity or registry** with typed columns, constraints and indexes without a migration — replacing `MasterController::ensureCols()`'s generic self-creating table with all-string columns.
7. Custom fields on any entity must be first-class, because SmartPRS 2 grew ~30 runtime columns on `employees` from four different patchers.
8. **No application code may execute DDL at request time.** Schema convergence happens in exactly one idempotent `schema:ensure` at boot and after update — because SmartPRS 2 has 59 runtime-created tables, at least 8 with competing definitions, and a real client install died of a half-applied migration on 28 Aug 2026.

### Rule engine
9. The rule engine must support **"block or warn a money event when a named credential is invalid"**, with per-tenant severity (`off`/`warn`/`block`) and a per-event applicability list — generalising the DRA gate, which today hard-codes DRA at three money points.
10. It must support **weighted scoring rules** whose weights are configuration — because the compliance score hard-codes 25/20/20/15/20 and no customer can reweight for a specific bank.
11. It must support **verdict rules over item states** (any bad → X, else any warn → Y, else Z) with configurable thresholds.
12. It must support **rolling-window count conditions** with a configurable window — because the complaint window is a hard-coded 90 days while the code comment says "financial quarter".
13. It must support **count thresholds with three-state outcomes** (≥2 ok, 1 warn, 0 bad) — because the references rule hard-codes 2.
14. It must support **amount-threshold escalation with configurable levels and depth** — because the approval chain hard-codes ₹10,000 / ₹10,000 / ₹50,000 and a fixed depth of two, while the documentation names a three-tier chain ("Team Leader") that does not exist.
15. Rule evaluation must be **observable**: every gate that allows on error must log, count and alert. SmartPRS 2 has eight fail-open points on a single authenticated request and none of them logs the throwable it swallowed.
16. A rule that fails open must still fail open — the availability bias is correct and must be preserved — but **loudly**.

### Identity, roles and authorisation
17. Roles must be **data, tenant-scoped, and multi-assignable**. SmartPRS 2 allows exactly one role per user (`syncRoles([$role])`) and roles are global (`config/permission.php: 'teams' => false`), which is why the matrix was pushed into the browser.
18. Permissions must be **enforced server-side at the route or policy layer**, so a missing guard is a routing error rather than an invisible hole — because 122 of 433 routes have no authorisation at all and the matrix is `element.style.display = 'none'`.
19. An unknown, missing or unresolvable role must resolve to **least privilege**, never to Admin. SmartPRS 2 resolves it to `'Admin'` in `ROLE_MAP`, skips the matrix for `'Admin'`, and the shell's `roleAllows()` returns `true` for any unknown role — three independent paths to full access.
20. Creating a role from the UI must **actually create it**, and a failure must surface. The `accountant` case is the reference failure.
21. The "reporting manager" tier must be expressible as **either** a role **or** a data relationship, and grantable/revocable — SmartPRS 2 has it only as `employees.reporting_manager_id`.
22. Approval routing must never produce an approver with a NULL id and a string label; an unroutable request must land in a named queue.

### Compliance and the vertical
23. There must be a **generic Credential object** — type, issuing body, number, issue date, expiry, verification status, document — so a customer can add a credential type (firearms licence, driving licence, insurance broker certification) without code. DRA and PCC become instances.
24. The **expiry-alert engine must be driven by configuration**: which credential types, which windows, which recipients, which channels. SmartPRS 2 hard-codes DRA and PCC and ignores agent authorisation `valid_to` and BGV `next_due` entirely.
25. Compliance mechanisms must operate on the **Worker** abstraction, so off-roll agents are included by construction — SmartPRS 2 excludes them from every mechanism because they are a parallel schema.
26. Every credential must have a **date**, not free text. `offroll_agents.dra` and `.pcc` are `string`.
27. **Policy acknowledgement must be versioned and periodically re-acknowledgeable**, with per-role text — SmartPRS 2's CoC is one row per user, no version, no cadence, and its content is whichever KB articles sit in a category.
28. A compliance check must **never degrade to a weaker check when data is missing**; it must return "unknown". SmartPRS 2's CoC audit check drops its `user_id` filter when the employee has no login and becomes "has anyone in this tenant acknowledged?".
29. Absence of evidence must be treated **consistently**: SmartPRS 2 awards a full 20 points when the complaints table is missing and 0 when the DRA table is missing.
30. Every issued compliance report must be **persisted** (agent, timestamp, verdict, score, item states) with an **HMAC-keyed reference** and a verification endpoint — because SmartPRS 2 stores nothing and its reference is an unkeyed sha256 prefix, while the marketing page sells "a tamper-evident reference".
31. No audit item may be hard-coded to pass. `Disciplinary actions` is fixed at `'0'`/`'ok'`.
32. The audit report's **item set, groups, weights and layout must be configuration**, because different banks and future RBI directions will differ.
33. **Client / bank / portfolio must be a real entity**, not a free-text string joined on. `BankPackController` groups by `commissions.portfolio`.

### Process and calendar
34. There must be a **statutory obligation calendar**: obligation, jurisdiction, period, due-date rule, owner, status, evidence, and reminders. SmartPRS 2 has 12 scheduled tasks and **not one** concerns a filing due date; the dates exist only as help-text prose.
35. There must be an explicit, auditable **period freeze** for attendance and payroll, with a named unfreeze action — SmartPRS 2 has none, and a regeneration silently absorbs late data.
36. **Zero-punches behaviour must be a configurable policy** (pay full / pay zero / flag for review), not a hard-coded constant — the current "pay 100%" is a deliberate anti-fragility choice that also pays absconders in full.
37. Payroll generation itself must be audited. SmartPRS 2 logs only regeneration.
38. A rejected artefact and its remark must survive. SmartPRS 2 deletes a rejected run on regeneration.

### Commercial
39. Seat counting must have **one definition, one implementation, and be enforced at every creation door** — SmartPRS 2 has three of five doors and omits `archived_at`, billing customers for employees it will not show them.
40. Whatever "archived" means, the billing rule and the user-facing message must agree.
41. Editions and plans must gate something real, or the tiering must be removed. Today both are dead and both are advertised.
42. AMC percentage must drive an actual calculation or not exist.
43. A **workspace-level data export** must exist — Terms, Privacy and Data Protection all promise one and only per-module exports exist.
44. **Hard erasure** of a person record must be possible, to honour a DPDP erasure request. SmartPRS 2 has soft delete and archive only.
45. **Retention and purge** must be implemented, not just promised — there is no purge job, no retention timer and no `deleted_after` column behind the 90-day commitment.

### Platform hygiene
46. One version number, in one place. `version.json` and `config/smartprs.php` disagree today.
47. Every table must have a migration; the deployed schema must be derivable from source.
48. No table may have two definitions.
49. Money must not be computed in PHP floats — the product's own config already says so and 30 sites do it anyway.
50. Every legal-numbered document series (invoices, quotations, licence invoices, bank invoices, employee codes) must have a unique constraint and transactional issuance.
51. No credential may be created by a seeder or published in a README. `OnPremWorkspaceSeeder` creates `admin@smartprs.local` / `password` on a documented setup step.
52. Uploads must be validated on magic bytes and stored on a private disk. Off-roll KYC documents go to the `public` disk today.
53. Public token links must **expire and be rotatable**. The off-roll earnings token never expires and never rotates.
54. CI must exist — Pest on MySQL, Pint, and a Larastan config — because `composer analyse` has never been runnable and nothing prevents any of these defects recurring.
55. The navigation must be data, not a 234 KB HTML file plus ~14 runtime DOM injections anchored to other nav items.
56. No nav entry may exist without an implementation (`ai-assistant`).

---

