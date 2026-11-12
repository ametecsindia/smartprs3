# PART II — PROBLEMS BEING SOLVED

Status: DRAFT FOR PRODUCT OWNER APPROVAL
Owner: Ametecs India Private Limited
Conforms to: `SPINE.md` (the binding architectural contract)
Depends on: Part I — SmartPRS 2 Understanding

---

## II.0 Purpose

This part is the case for the rebuild. It does three things and nothing else.

1. It organises the ~350 recorded defects and ~200 security findings into **eight structural root
   causes**, because a flat list of 350 items invites the answer "then fix the 350 items", and
   that answer is wrong.
2. It ranks the defects that are **live in production right now** and states, for each, whether
   they require remediation in SmartPRS 2 *independently of the rebuild*.
3. It sets out, honestly, **why incremental repair does not converge**, what SmartPRS 2 costs
   Ametecs today, and what the rebuild unlocks commercially.

Every claim cites `file.php:line` through the fourteen as-is analyses. Nothing here is asserted
that was not read in source. Where a value is statutory, it is recorded only as *the value
SmartPRS 2's code currently uses* and never as a statement of law.

Part I is deliberately generous about what SmartPRS 2 does well, and that generosity is the
premise of this part: **the domain understanding is real and must be preserved; the architecture
carrying it cannot be.**

---

## II.1 The eight structural failures

Each failure below is a **root cause**, not a symptom. Each is stated as a single sentence, then
evidenced, then followed by *why it is structural* — the argument that it cannot be repaired
locally — and *what the spine does about it*.

The mapping between the eight failures and the spine's seven laws is deliberate: **each law exists
because of one of these failures**, and the eighth (no service layer) is why S2's tooling-enforced
modularity is a first-class deliverable rather than a coding convention.

| # | Structural failure | Defects it explains | Spine response |
|---|---|---:|---|
| F1 | Business logic is hard-coded as literals | ~120 | **Law 1**, S5 SPX, S6 Rule Engine, S7 Formula Engine |
| F2 | The schema is built at runtime, so the database is not a system of record | ~70 | **Law 2**, **Law 6**, S16 |
| F3 | Configuration is overwritten in place, so history is unreproducible | ~25 | **Law 3**, S4 |
| F4 | No number explains itself, so no number is defensible | ~30 | **Law 4**, S15 |
| F5 | Authorisation fails open and tenancy is inert | ~60 | **Law 5**, **Law 6**, S13, S14 |
| F6 | Silent failure is a design posture | 426 catch blocks | S5.6, S18 operations dashboard |
| F7 | There is no service layer — logic lives in controllers, blades and browser JavaScript | ~40 | S2 modularity enforced by tooling |
| F8 | Nothing that computes money is tested, and almost nothing is atomic | 25 races, 0 locks | **Law 7**, S11 reconciliation assertion |

---

### F1 — Business logic is hard-coded as literals

> **Statement.** Every rule a customer could reasonably want different — every threshold, rate,
> weight, window, divisor, audience list, status name and component name — is a literal inside a
> PHP file or a JavaScript heredoc. There is no rule engine, no formula engine, and no
> configuration layer above master data.

#### Evidence — a representative census

| Domain | The literal | Location | Why a customer wants it different |
|---|---|---|---|
| Approvals | Escalation thresholds **₹10,000 / ₹10,000 / ₹50,000**, chain depth fixed at **two** | `ApprovalService::modules() :29-42`, `resolveApprover() :310-352` | Thresholds are per-customer; the docblock names a three-tier chain ("Team Leader") the code does not implement |
| Compliance | Score weights **25 / 20 / 20 / 15 / 20** | `ComplianceController::scoreFor() :289-330` | Different client banks weight differently |
| Compliance | Audit complaint window **fixed 90 days**, while the code comment says "financial quarter" | `ComplianceController.php:617` | The comment states the intent the code does not implement |
| Compliance | References threshold **≥2 ok, 1 warn, 0 bad** | `ComplianceController.php:593` | An industry norm, not a universal one |
| Compliance | Alert window **30 days**, critical bucket **≤7 days**, digest size **top 25**, audience `['super_admin','admin','hr_manager']` | `ComplianceController.php:31, 80, 206, 256` | An NBFC with a 90-day DRA renewal cycle needs 90; a 2,000-agent client sees 25 of 300 expiries; customers have a Compliance Officer role |
| Commercial | **GST 18.0**, **company fee ₹1,000**, the cycle→months and cycle→discount maps, the unknown-cycle fallback (3 months, 0%) | `BillingController.php:30-140` | GST is a statutory rate that changes by law; the company fee is a pricing lever; discounts are per deal |
| Commercial | **`GRACE_DAYS = 7`**, and the six statuses that free a seat | `SubscriptionService.php:34-35` | Enterprise contracts routinely negotiate 15 or 30 days; a customer with a `suspended` status cannot make it free a seat |
| Commercial | Subscription reminder ladder **[15, 7, 3, 1, 0]**; auto-suspend after grace is **unconditional** | `SubscriptionAlerts.php:35, 60-69` | A reseller's contract says 30/14/7/1; an enterprise customer will not accept automatic suspension at all |
| Attendance | Default shift **09:30–18:30**; `STANDARD_WORK_MIN = 480`; `full_day_hours` 9; `half_day_hours` 4.5 | `AttendanceReportController.php:28`; `PayrollGenController.php:308-309`; `ShiftResolver.php` | Four incompatible definitions of a full day in one product |
| Attendance | Default working week **[1,2,3,4,5,6]** (Mon–Sat) for the absence sweep | `AbsenceService.php:44-45` | A five-day office client is defaulted to marking every Saturday absent |
| Attendance | Points late cut-off **09:30 with no grace, no policy, no shift** | `PointsController.php:25, 164-168` | An employee loses points for a day the Late Policy considers on time |
| Payroll | `ctc/12/26/8` for the OT hourly rate; flat-30 proration at three sites; ESI threshold **21000** inline at two sites | `PayrollGenController.php:2165, 1277, 1621, 2042, 1306, 1643` | The FAQ headlines "÷26" as the LOP divisor and ÷26 is unavailable as a LOP basis |
| Payroll | LOP basis is **one tenant-wide setting** | `SettingsController.php:170` | Basis routinely differs per company, grade or employee group |
| Payroll | **Zero punches pays 100%** | `PayrollGenController.php:26-29, 1237` | A deliberate anti-fragility choice that also pays a genuine absconder in full. It must be a policy, not a constant |
| Statutory | 16 built-in state PT tables + 10 PT-free states as PHP `private const`; Maharashtra February uplift and female exemption special-cased | `AppDataController.php:1852-1871` | **A PT slab change is an application release to every customer** |
| Statutory | Statutory rates restated as **hard-coded prose** in two more places | `KbController.php:211`; `CalcGuideController.php:88, 126, 136, 161` | A customer who lowers the ESI threshold still reads "≤ ₹21,000" in the product's own explanation of their payslip |
| Statutory | The SPA carries **a second complete copy of every rate** and re-implements the tax slab walk in JavaScript | `AppController.php:11161, 11167-11177, 12081-12086` | The on-screen contribution register disagrees with both the payslips and the challans |
| Vertical | Off-roll payout: **four kinds, one flat rate per agent** — no slabs, no per-portfolio rates, no caps | `OffrollAgentController.php:102-119` | Tiered commission, per-portfolio rates and caps are all normal asks |
| Vertical | The DRA gate's *subject* is DRA only; its *event list* is three, hard-coded | `RequestController.php:1373`; `IncentiveController.php:135`; `OffrollAgentController.php:251` | Customers will want the same shape for PCC, BGV and bank authorisation |
| Vertical | Audit item "**Disciplinary actions**" hard-coded to `'0'` / `'ok'` | `ComplianceController.php:625` | A report presented as an RBI compliance file asserts a fact it has not checked |
| Operations | Backup times, retry policy (1 try, 0 delay), destination (local only), error-digest severity set and scan window, transfer apply time, demo cadence, queue name, job retry policy, overlap-lock TTL | 45 further literals catalogued at `L` §6.1 Q8 | Every one is a business policy expressed as a literal |

#### Why it is structural

Three properties make this irreparable by patching:

1. **The literals are not in one place.** They are in controllers, services, seeders, migrations,
   a 12,000-line JavaScript heredoc and four bodies of hard-coded prose. There is no chokepoint to
   convert.
2. **Component and role identity is by *name*, not by code.** `SalaryApprovalController::sheet()
   :294-299` reads `$earn['Basic']`, `$earn['HRA']`, `$ded['PF']`, `$ded['ESI']`,
   `$ded['Professional Tax']`, `$ded['TDS']` **by literal string key**. A company that names its
   components "Basic Pay" or "EPF" gets a sheet in which every one of those columns reads ₹0 —
   *the sheet a manager signs off*. Worse, `esiPeriodLock() :2036` detects the statutory ESI
   contribution-period lock by `stripos($key, 'esi') !== false`: **rename the ESI line and a
   statutory lock silently stops working.** Making rates configurable does not fix this; the
   *identity model* has to change from names to codes with declared statutory-base flags
   (spine S12).
3. **The same rule exists several times and the copies already disagree.** Four working-day
   calculators, four "present" derivations, four late-policy resolvers, three punch date parsers
   with three different rules, four gratuity implementations that disagree at 4.99 vs 5.00 years
   of service, five independent answers to "is this agent's DRA valid?" (`F` DEFECT-F-08) — under
   which **the same agent can be blocked from an incentive, reported COMPLIANT on the RBI audit
   PDF, and scored 0/25 on the scorecard, simultaneously.** Converting one copy to configuration
   makes the divergence worse, not better.

#### Spine response

**Law 1** (no business literal in code), enforced by a static-analysis rule that fails the build
on a bare literal inside `Domain\*`. **S5** (SPX) gives one safe expression language;
**S5.5** supplies the aggregation primitives every hard-coded behaviour above needs
(`agg.count(event, window, predicate)` is exactly "3 late arrivals over 10 minutes this month").
**S6** gives declarative typed actions; **S7** gives effective-dated, dependency-ordered formulas.
**S12** removes statutory content from the application entirely.

---

### F2 — The schema is built at runtime, so the database is not a system of record

> **Statement.** Application request code executes DDL. 156 `Schema::create` / `Schema::table` /
> `dropIfExists` calls live inside `app/`, six raw `ALTER TABLE` statements run on HTTP request
> paths, and **59 distinct tables have no migration at all**. The consequence is that no two
> installations are guaranteed to have the same schema, and the deployed schema is not derivable
> from source.

#### Evidence

**The scale.**

| Measure | Value | Source |
|---|---:|---|
| `Schema::create` / `Schema::table` / `dropIfExists` inside `app/` | **156** | `M` §0.4 |
| `Schema::create` outside `database/migrations/` | 71–72 | `G` §0.4, `J` §0.4 |
| Raw `DB::statement("ALTER TABLE …")` in app code | **6**, all on request paths | `J` §6.5 |
| Distinct table names created at runtime | **59**, plus any of the 57 `MasterController::def()` registry types via a generic self-creator | `N` §2.1 |
| `Schema::hasTable` / `hasColumn` guard calls | **738** | `M` §0.4 |
| Foreign keys on business tables | **0** | `J` SEC-DB-01 |
| Tables with two or three competing definitions | at least **8** | `N` §2.1 |

**The consequence, in specific cases.**

- **`attendance_pending` is two unrelated features sharing one table name** with **completely
  disjoint schemas** — device quarantine (`2026_08_16_000300:28-43`) and bulk-CSV attendance
  staging (`AttendanceBulkController.php:40-55`). Every creator opens with
  `if (Schema::hasTable(...)) return;`, so **whichever runs first wins and the other feature is
  permanently broken** with `SQLSTATE[42S22] Unknown column`. A repair migration exists *because
  this happened on the dev box*; it leaves the table carrying both column sets with `punch_at`
  deliberately nullable, so neither feature's invariants hold afterwards.
- **`attendance_logs` has three definitions.** `DeviceController::ensureLogsTable() :209-227`
  omits every NOT NULL constraint, the `source` default, and — critically — **the
  `(emp_code, log_date)` composite index**. On an install where the Devices screen was opened
  first, every payroll query full-scans the punch ledger.
- **Column *types* are decided by whichever code path ran first.**
  `AppDataController::ensureEmployeeColumns() :46-75` adds every missing column as
  `$t->string()` — `VARCHAR(255)` — and its list includes `dra_expiry`, `pcc_expiry`,
  `pcc_deadline`, `dob` and `doj`, which the migrations declare as `date()`. Both sides are
  `hasColumn`-guarded, so **whichever runs first decides the type permanently.** On the VARCHAR
  installs, every `whereDate`, `BETWEEN`, `ORDER BY expiry` and `DATEDIFF` in the compliance-alert
  engine operates on **lexicographic string comparison**. Nothing errors; the alerts simply report
  the wrong people.
- **`code_of_conduct_ack` has two incompatible definitions** — one keyed on `employee_id` NOT
  NULL with no `user_id`, one keyed on `user_id` with no `employee_id` — reconciled by a live
  `ALTER TABLE … MODIFY employee_id … NULL` inside a swallowed try/catch on a request path, and
  with the `acknowledged` default flipped from `false` to `true`. **A compliance artefact that
  defaults to "yes".**
- **Twelve live cases exist where code writes a value the column's `ENUM` forbids**, and six more
  have already been fixed by widening migrations written after live failures — `payroll_runs.status`
  could not store `hr_approved`; `employees.status` could not store `inactive`;
  `escalations.priority` could not store `low/medium/high/urgent`. Still live:
  `salary_components.ctype`, `deductions.type`, `devices.status`, and `employees.geo_outside`,
  where the Employee form posts `+1 km` and the accepted set is `1km`, so **the per-employee
  geofence relaxation cannot be saved at all.**
- **Zero foreign keys.** Deleting a company, employee, run or tenant orphans every child row.
  `DummyDataSeeder.php:88-95` documents the consequence and hand-deletes 17 tables to work around
  it. A payroll for a deleted employee is unexplainable.
- **Three tables holding employee PII have no tenant column at all**: `employee_references`
  (referee names, PAN, Aadhaar, mobile), `self_onboarding_otps`, `self_onboarding_docs`. Isolation
  depends entirely on remembering to join the parent.
- **The production database user must hold `ALTER` at runtime.** Least privilege is impossible,
  and any SQL injection or RCE becomes schema-destructive.

#### Why it is structural

The circularity is the point. **You cannot remove the runtime DDL without knowing the deployed
schema; you cannot know the deployed schema because the runtime DDL made it install-specific.**
Every on-premise customer's database is a function of which screens were opened, in what order,
before which deploy. There is no dump. There is no inventory. There is no way to write a migration
that is correct on all of them.

And the runtime DDL is not decoration — it is load-bearing. `PayrollGenController::generate()`
issues up to five DDL statements before its transaction opens (`:2556-2616`), *deliberately*
outside it because MySQL DDL implicitly commits. On a correctly hardened database user without
`ALTER`, all five silently fail — with the consequences catalogued at critical defect #12 below.

#### Spine response

**Law 2** (no schema change at runtime), tested three ways: the production database user has no
`ALTER`/`CREATE`/`DROP` privilege; a CI grep fails the build on DDL outside migrations; and
`migrate:fresh` must produce a schema byte-identical to production's structure. **Law 6** makes
`tenant_id` structural and applied by the persistence layer. **S16** mandates foreign keys with
declared `ON DELETE`, reference tables instead of database `ENUM`s, `DATE`/`DATETIME` instead of
`VARCHAR`, and one tenancy column name everywhere. **S9** makes custom fields typed rows, never
columns, so "add a field" never touches DDL again. **R-I-015** (Part I) preserves the *real
problem* the runtime DDL was solving: an idempotent, migration-owned `schema:ensure` at boot and
after every update, failing loudly.

---

### F3 — Configuration is overwritten in place, so history is unreproducible

> **Statement.** The stores that hold a customer's rules are updated in place with no version, no
> effective date, no history and no audit. Changing a rate today silently rewrites what last
> year's payroll would produce if regenerated.

#### Evidence

**The governance was built on the door nobody uses.** SmartPRS 2 has **two** statutory rate stores
with different capabilities, and the weaker one is the one the customer actually uses:

| | **Statutory Rate Settings** (legacy, universal) | **Statutory Configuration** (F1, newer) |
|---|---|---|
| Store | `statutory_settings` — **one JSON blob per tenant** | `statutory_configs` — many effective-dated scoped rows |
| Effective dating | **none** | yes, resolved on the payroll-period date |
| Scope | tenant only | branch > location > company > state > tenant |
| History | **none — overwritten in place** | yes, insert-only |
| Finalised-period guard | **none** | yes |
| Audit trail | **none** | yes |
| Reaches | ***every* rate consumer in the system** | **only `computeSlip*` + 3 reports** |

*Evidence:* `SettingsController::save() :135-204` (no `AuditTrail`, no `EffectiveDated`, no
`PayrollLock`) versus `StatutoryConfigController::save() :147-207`; consumer inventory at
`F` DEFECT-F-25.

**Consequence, stated plainly:** *change the PF rate today and every past month you regenerate
uses the new rate, silently, with no record of who changed it or when.* `F` SEC-F-03 records the
security form of the same fact: **an admin can change the PF rate, run payroll, change it back,
and leave no trace.**

Beyond statutory rates:

- **`app_state` — a single JSON blob holding "all 113 screens' collections" for the tenant** — is
  read at boot, mutated in the browser, and pushed back wholesale by `POST /app/state/save`. Last
  writer wins. No history, no soft delete, no audit. Two administrators working simultaneously
  silently destroy each other's work.
- **Employee attributes are overwritten, for every attribute, on every path.** There is no
  effective-dated employee history and no field-level audit trail; `storeEmployee()` does not
  write an audit entry at all. The question *"what was this employee's branch on 15 May?"* is
  **unanswerable** — only the `transfers` register carries an effective date, and only for changes
  made through that workflow, not for a direct edit on the Employee form.
- **A payslip stores amounts only.** There is no rate, cap, slab or PT-band snapshot (`F`
  DEFECT-F-03). See F4.
- **Signed letters are re-rendered from the *current* template.** The issued row stores a
  `template_id` pointer and no body; the merge happens at render time. **Editing a template after
  signature silently rewrites what the person signed.** `letters.pdf_path` exists in the migration
  precisely to fix this and **no code ever writes or reads it** — the intended fix was designed
  and never built.
- **An acknowledgement of the Code of Conduct is one row per user, updated in place**, with no
  version of the acknowledged text, no history, no IP and no signature. Re-acknowledging destroys
  the prior date. And the *text itself* is whichever KB articles sit in a category — written with
  `tenant_id = NULL` and read with no tenant filter, so **one customer's admin can rewrite the
  undertaking another customer's agents legally acknowledge.**

#### Why it is structural

Effective dating is not a feature you add to a store; it is a property of the store's shape.
Retrofitting it to `statutory_settings` means changing every read (`applyScope` reaches two call
sites today; the flat blob reaches a dozen more), changing every write, and **inventing history
that was never recorded**. The past is already lost. The same is true of the employee record, the
letters, the acknowledgements and `app_state`.

#### Spine response

**Law 3** (nothing is overwritten; everything is versioned and dated), tested by requiring that
*"what policy applied to employee E on date D?"* and *"what was employee E's branch on date D?"*
are both answerable by a single service call. **S4** gives one shared
`config_artefacts`/`config_versions` pattern with draft → published → superseded, publish-time
overlap and gap rejection, mandatory diff and approval for high-risk artefacts, and
`resolve(type, key, scope, as_of_date)` as the only read path. **S11**'s input snapshot records
every version id a payroll run consumed. Note that **SmartPRS 2 already built the right model
once** — `statutory_configs` + `EffectiveDated` — and then bypassed it; the spine's instruction is
to adopt it universally rather than replace it (Part I R-I-012).

---

### F4 — No number explains itself, so no number is defensible

> **Statement.** No derived value in SmartPRS 2 carries a trace of how it was produced. The
> consequence is not merely poor UX: it is that **the product's own outputs contradict each other
> and cannot be reconciled**, on documents that are filed with statutory authorities and handed to
> client banks.

#### Evidence — the reconciliation failures

| # | Two outputs that must agree | What actually happens | Evidence |
|---|---|---|---|
| 1 | **The payslip and the PF ECR / ESIC challan** | `statutoryPdf()` **recomputes every employee from `ctc` with no context** — no `pt_state`, no month, no scoped overrides, no LOP, no salary components, no salary run. It selects all non-deleted employees with **no status filter** (resigned staff included), no company filter and no period filter, then labels the output `now()->format('F Y')`. | `AppDataController.php:1639-1749`, `:1669`, `:1686` |
| 2 | **The payslip ESI and the ESIC challan ESI** | The engine rounds ESI **up** (`ceil`); the challan PDF rounds it (`round(…,2)`). Every challan is understated against what was deducted. | engine `:2077-2078` vs PDF `:1688-1689` |
| 3 | **The payslip TDS and the Form 24Q statement** | The 87A rebate is a hard cliff inside `newRegimeTax()`, while **marginal relief is applied only by `salaryTdsMonthly()`**. `statutoryPdf('tds')` and `tdsReturnsHistory()` call `newRegimeTax()` directly, skipping the relief and the ÷12. At one worked CTC the payslip deducts ₹866.67/month while the 24Q register reports **₹63,960**. | `:1732`, `:1779`, `:1836-1840` |
| 4 | **The payslip and its own arithmetic** | `net = round(max(0, gross − total_ded), 2)` clamps net while leaving `total_ded` at full value. Where statutory deductions alone exceed gross, `gross − ded ≠ net` — and the PDF prints *"Net = Gross earnings (A+B) − total deductions"*. | `:2129`, `:2335`; `payslip-pdf.blade.php:198` |
| 5 | **The run header and the payslips it contains** | `payroll_runs.net_total` is written once at generate and **never corrected**. A line later rejected or held still contributes. **There is no reconciliation query anywhere comparing it to `SUM(payslips.net)`.** | `PayrollGenController.php:2549` |
| 6 | **The on-screen contribution register and everything else** | The SPA carries a second copy of every rate and its own tax walk, recomputes `basic = gross × 0.5`, uses `Math.round` for ESI where the server uses `ceil`, and ignores components, `pt_state` and probation. | `AppController.php:11161-11177, 12081-12086` |
| 7 | **The compliance checklist verdict and the compliance score, printed side by side on the same page** | The checklist accepts `pcc_status='submitted'`, `bgv.status='completed'` and a legacy-only DRA as `ok`; `scoreFor()` awards **0** for all three. A report reading **"COMPLIANT · 35%"** is reachable. | checklist `:579-586`; score `:293-313` |
| 8 | **The gratuity figure** | Four implementations that disagree: whole rupees with `diffInDays/365.25` then `floor`; 2 dp with `diffInYears` re-deriving Basic as 50% inline and ignoring `salary_components`; and a 4.81% monthly accrual. At 4.99 vs 5.00 years by the two measures, **one report pays and the other does not.** | `StatutoryController.php:54-57`; `ReportController.php:264-275`; `AppDataController.php:1543` |

#### Evidence — the fabrications

Three outputs assert facts the system has not checked. These are the most serious items in this
document, because they leave the customer's premises on a document.

- **The Form 24Q filing history is fabricated.** Six quarters are emitted; the current one is
  `Pending` and **every prior quarter is hard-coded `Filed`** with `deposited = perQ`, regardless
  of whether anything was filed or deposited. Total tax is simply `annualTotal / 4` per quarter.
  *The product tells a customer their TDS returns are filed. They are not.*
  (`AppDataController.php:1785-1810`, `$status = $i === 0 ? 'Pending' : 'Filed'` at `:1800`.)
- **The "tamper-evident" audit reference is not tamper-evident.** `$ref = "RAC/{Y}/{EMP_CODE}"`;
  `$hash = substr(sha256("$ref|{$e->id}|{$today->toIso8601String()}"), 0, 16)` with
  `$today = Carbon::today()` — **deterministic within a calendar day, keyed by nothing, covering
  none of the findings, and never persisted.** No table stores an issued report. A bank cannot
  verify a presented PDF, and Ametecs cannot prove what any issued report said. The footer claims
  a "hash-chained audit log"; generating an audit report is **not logged at all**.
  (`ComplianceController.php:643-644`.)
- **"Disciplinary actions: 0 · Compliant"** is printed from a literal with no data source
  (`:625`), and **"Code of Conduct: Acknowledged"** is reported for every employee without a
  login, because the `user_id` filter is conditional and drops out — degenerating the check to
  *"has anyone in this tenant ever acknowledged?"* (`:596-611`).

#### Why it is structural

Explainability cannot be bolted on, because **the inputs were never recorded.** A payslip that
stores only amounts cannot be explained three years later at any price; the rate, cap, slab, PT
band, component version and rule version that produced it are gone. Adding a "How was this
calculated?" panel to SmartPRS 2 would require the calculator to emit a trace — and the calculator
is spread across `PayrollGenController`, `AppDataController`, `SettingsController`,
`StatutoryConfig`, `MasterController`, `IncrementController` and 12,000 lines of browser
JavaScript, with the same rule implemented several times and the copies already disagreeing.

#### Spine response

**Law 4** (every number explains itself), tested by making a trace-less engine result a
programming error that throws. **S15** exposes `Explain(entity, field)` everywhere a derived
number is shown. **S7**'s trace contract shows the formula, its version, the substituted values,
the intermediate steps and the rounding mode. **S11**'s reconciliation assertion — *"the sum of
payslip lines equals the run totals, and each employee's earnings minus deductions equals net,
exactly, or the run aborts"* — makes failure #5 impossible by construction. **S12** requires every
statutory head to carry its pack version onto the payslip.

---

### F5 — Authorisation fails open, and tenancy is inert

> **Statement.** SmartPRS 2 has no route-level authorisation, no policy layer, and no working
> automatic tenant scope. Access control is 71 in-controller calls to a helper that **returns
> "allowed" on any exception**, plus `element.style.display = 'none'` in the browser. Tenant
> isolation is ~616 hand-written filters, with 405 unfiltered and 354 id-only queries beside them,
> and the declared safety net predicates on a column that does not exist.

#### Evidence — the authorisation stack

**There is no role middleware.** `routes/web.php:238` applies `auth`, `LicenseGate`,
`EnsureSubscriptionActive`, `DemoWriteGuard`, `EditionGuard` — **none role-aware**. There is no
`role:` or `permission:` middleware anywhere, no `App\Policies` directory, and **zero** Spatie
permission checks (`can()`, `Gate::`, `authorize()`, `@can`) despite 13 permissions being seeded
and attached to roles.

| Layer | What it does | Failure mode |
|---|---|---|
| `ApprovalService::denyUnlessRole()` — **71 call sites** | The primary server-side guard | `catch (\Throwable $e) { return null; }` — **`null` means allowed**, with the comment *"fail open — availability over strictness on pivot errors"* (`:365-380`) |
| `FeaturePermissions::guard()` — 16 call sites | Inherits the same posture | `:99-105` |
| `ApiKeyController::authorizeScreen()` | Documents its own fail-open | `:509-521` |
| `AppController::ROLE_MAP` | Role → UI label | `[… ?? 'admin'] ?? 'Admin'` — **two fail-open defaults on one line** (`:93`) |
| `applyPerms()` | The client permission matrix | **Returns early for `'Admin'` and `'Super Admin'`** (`:769`) |
| `spCan()` | Client permission check | **No matrix → `return true`** (`:758-763`) |
| prototype `roleAllows()` | Nav visibility | **`return true` for any role not in its three-key `ROLES` map** — the fourth, previously invisible fail-open |
| `EditionGuard`, `DemoWriteGuard`, `LicenseGate`, `EnsureSubscriptionActive` | Commercial gates | All four `return $next($request)` from `catch` |

**The measured surface.** A scripted sweep of all 432 route declarations found **78 authenticated
routes with no server-side role or self check**; 17 are legitimately open, leaving **61 real
gaps**, including `GET`/`POST /app/state`, `POST /app/salary-runs/{id}/decide`,
`POST /app/salary-lines/bulk`, `GET /app/statutory/{type}/pdf`,
`POST /app/attendance-bulk/{batch}/decide` and `POST /app/recruitment/job/{id}/approve`.

**The worst individual instances.**

- **`POST /app/state` has no guard of any kind** (54 lines total, zero checks). Any authenticated
  user — a plain `employee` — can replace the tenant's entire working data set. And because
  `DB.roles[].perms` lives in that blob and is the input to the client permission matrix, **it is
  a persistent, tenant-wide privilege escalation**; `{"state":{}}` is simultaneously a
  tenant-wide destruction with no history, no soft delete and no audit.
- **`RequestController::list()` has no role guard**, and row-level scoping is applied **only** when
  `$module === 'commissions'`. Any employee can read **every colleague's salary increment (old CTC
  → new CTC), every loan, every salary advance, every expense claim, every exit record with its
  clearance remarks, and every transfer** for the whole tenant.
- **Any authenticated user can raise any request type for any colleague.** The self-forcing rule
  exists only inside `if ($module === 'commissions')`. An employee can file an **exit/FnF for the
  CEO**, a ₹5,00,000 loan for a colleague, or an increment for themselves. The fix was applied to
  leave after a 7 Aug 2026 test report and **never propagated to the other eight modules**.
- **`GET /app/statutory/{type}/pdf` has no guard at all.** Any authenticated user downloads the PF
  ECR register, the ESIC challan, Form 24Q and Form 26Q — each listing **every colleague's
  employee code, name, PAN, UAN, PF wage, gross salary and tax**. Compounding: a user with a null
  `tenant_id` is silently served the **first tenant's** data.
- **`GET /app/master/{type}` is ungated for ~56 of ~58 master types**, disclosing the tenant's
  WhatsApp and SMS provider **API keys** verbatim, biometric device credentials, bank account
  numbers, the complaints register, the disciplinary-letters list and the DRA/BGV/agent-auth
  registers.
- **A Field Agent receives the entire workforce's PII on page load.** `selfScope()` narrows only
  for the literal `employee` role and returns `null` (= unrestricted) for `field_agent`,
  `accountant`, `platform_support`, `manager`, `hr` and any role-less user. `bootstrap()` then
  returns, per employee, `ctc`, `pan`, `uan`, `bankName`, `bankAcc`, `ifsc`, `dob`, `addr`,
  `permanentAddress`, `emergencyPhone`, `esicNo`, `nationalId`, `homeLat`, `homeLng`. **The code
  comment at `:226-228` calls this "the stricter employee-only default"; it is the opposite.**

#### Evidence — tenancy

- **`CompanyScope` — the documented automatic isolation — has never run.** It predicates on
  `$user->company_id`; **that column does not exist**. The migration named
  `add_company_id_to_users_table` adds `tenant_id`, `employee_id` and `status`. `README.md:19-22`
  still documents the broken mechanism as the design.
- **Real isolation is ~616 hand-written `where('tenant_id', …)` / `when($tid, …)` clauses**, with
  **405 queries carrying no filter at all** and **354 filtered by `where('id', …)` only**.
- **`when($tid, …)` fails open for `NULL` *and* for `0`** — and `0` is a real stored tenant value,
  written by `SettingsController` and `ConfigController` for platform rows.
- Two sentinels for "platform scope" coexist (`tenant_id = 0` and `tenant_id = NULL`); a query
  written against one finds nothing written by the other.
- **18 IDORs are reachable directly from a route parameter**, including colleagues' warning
  letters, increment letters with CTC, ID cards, uploaded identity documents, payslip PDFs, leave
  balances, raw punch logs and — cross-tenant, with no tenant filter at all — another customer's
  test definitions and question bank, and another customer's performance ratings.
- **`users.email` is globally unique**, so one person cannot hold accounts in two tenants — a real
  limitation for consultants and for the SaaS model.

#### Why it is structural

A fail-open guard cannot be made fail-closed one call site at a time in a product with **no test
asserting that anyone is denied anything**. `tests/Feature/AuthAccessTest.php` is 21 lines with
three assertions: guests redirect, the landing page renders, an admin can load `/app/data`. **No
test asserts that a non-admin *cannot* do anything.** Inverting 71 call sites blind, in a product
whose customers frequently have no administrator on site, is a change nobody can safely make.

And the enforcement model itself is wrong, not merely buggy. Permission and data scope are
conflated; roles are global rather than tenant-partitioned (`config/permission.php` sets
`'teams' => false`), which is *why* the matrix was pushed into the browser in the first place;
one role per user is enforced at both assignment surfaces; and the client is the enforcement
point, which means **a client-side regression is a security regression**.

#### Spine response

**Law 5** (authorisation is server-side, declarative and fails closed), tested by an automated
sweep asserting every route has an explicit policy binding and a fault-injection test asserting
that an exception inside authorisation **denies**. **Law 6** (tenancy is structural) makes
`tenant_id` NOT NULL on every business table, part of every unique index, and applied by the
persistence layer — never hand-written — with generated cross-tenant isolation tests for every
aggregate and endpoint. **S13** separates permission (`verb.resource`) from data scope
(`self | direct_reports | team | … | tenant`), makes roles tenant-defined data and
multi-assignable, and mandates MFA for privileged roles. **S2**'s UUIDv7 public identifiers remove
an entire class of the eighteen IDORs by construction. **R-I-016** (Part I) preserves the
availability bias where it belongs — a *commercial* gate may fail open, **loudly**; an
*authorisation* gate may not.

---

### F6 — Silent failure is a design posture

> **Statement.** SmartPRS 2 contains **843 typed catch blocks. 426 of them (50.5%) destroy the
> exception entirely and leave no trace anywhere.** A further 331 neither log nor rethrow. Only
> **86 of 843 — 10.2% — preserve any durable signal.** This is not sloppiness; it is a documented
> house style, and it is applied hardest exactly where it does most damage.

#### Evidence — the census

| Class | Count | Share |
|---|---:|---:|
| **EMPTY** (body empty or comment-only) | **332** | 39.4% |
| **RETURN_ONLY** (`return;` / `return null/false/true/[]/0/$x;`) | **94** | 11.2% |
| **OTHER** (neither logs nor rethrows) | **331** | 39.3% |
| **LOGGED** | 82 | 9.7% |
| **RETHROW** | 4 | 0.5% |

**The concentration is the alarming part.**

| Subtree | Typed catches | EMPTY | LOGGED |
|---|---:|---:|---:|
| `app/Console/Commands/` | 23 | **17** | **0** |
| `app/Http/Middleware/` | 7 | **6** | **0** |

**Not one of the 23 catch blocks inside the scheduled commands logs anything. Not one of the 7
catch blocks inside the security middleware logs anything.**

#### Evidence — the ten that matter most

| # | Location | The comment | Consequence |
|---|---|---|---|
| 1 | `PayrollGenController.php:2571-2616` (four DDL catches) | *"fail-soft: OT still pays; it just won't flip to 'paid'"* · *"fail-soft: EMI still deducts; the counter just won't advance"* | **Recurring double-payment of real money.** If `overtime.paid_run_id` cannot be added, next month's run pays the same overtime again. If `loans.installments_paid` cannot be added, the loan never closes and the EMI is deducted **forever**. If `loan_recoveries` cannot be created, a regenerated run **double-recovers** every EMI. **On a correctly hardened production database — one whose app user lacks `ALTER` — all four fire.** |
| 2 | `PayrollGenController.php:2417-2463` (four reversal catches) | *"best-effort; a regenerate must not fail on the reversal"* | The old payslips and run header are deleted **unconditionally at `:2389-2390`**, *then* the reversals run best-effort, *then* the recomputation, and only at `:2620` does a transaction open — **for the new rows only**. A crash between them destroys the previous payslips and produces nothing. |
| 3 | `LicenseGate.php:69-75` | `catch { return $next($request); }` | Licence enforcement fails open. Compounded by an empty catch around `Auth::logout()` + `session()->invalidate()`: **if session invalidation throws, the user is redirected to `/login` with the session still valid.** |
| 4 | `SubscriptionService.php:116-118` | *"fail-soft → unlimited"* | A failed seat lookup grants **unlimited seats**, and silences the on-prem `.lic` `device_limit` resolved three lines above. |
| 5 | `AbsenceService.php:233-235, 250, 273` | — | `isHoliday()` returns `false` on any error → **the entire workforce is flagged absent on a public holiday** and emailed about it. `presentEmpCodes()` swallows to an empty set → **everyone who was present is marked absent.** `onLeaveEmployeeIds()` swallows to an empty set → **everyone on approved leave is marked absent.** |
| 6 | `Audit.php:41-43, 97-99` | *"auditing is best-effort; never block the caller"* | The DDL adding `prev_hash`/`row_hash` is swallowed, so `hasColumn(...,'row_hash')` stays false forever and **the ledger silently becomes plain unverifiable rows while the UI still reports "chain VERIFIED"**. The insert itself is swallowed, so a failed audit write leaves a gap indistinguishable from a deletion. |
| 7 | `RequestController.php:799, 1745` | — | `$paid` silently stays `0.0`. At `:1745` that drives the **bounce clawback amount** — a failed query produces a ₹0 clawback and the company never recovers money it paid on a collection that bounced. |
| 8 | `BankPackController.php:104, 359` | — | **An employee is not paid and nobody is told.** A malformed bank row disappears from the payment file; the operator sees a successfully generated pack. |
| 9 | `SyncETimeOffice.php:71, 130, 148` | — | The only durable "did the biometric sync work?" signal is written **inside a silent catch**. The Device Setup screen shows a stale timestamp that is a lie about the write, not about the sync. |
| 10 | `DemoReset.php:180-182` | — | `resetDue()` — the control deciding whether a prospect's typed data must be erased — returns "nothing due" on any exception. **A privacy control that fails open, silently.** |

**And `safeRow()` — the pattern that converts a schema bug into silent data loss at 98 sites.**
`ApprovalService::safeRow() :263-266` intersects every insert with the table's actual column list.
No exception, no log, no return value, no user signal. Nine confirmed losses on the mainline path:
`expenses.reason` — the note that is the whole point of an expense claim — is never stored;
`advances.reason` lost; `loans.tenure_months` lost (the column is `tenure`); `clawbacks.cycle_month`
lost (the column is `month`); `bonus.kind` lost (the column is `type`); `entered_by` lost on every
non-commission module; `company_id` lost on five tables. **The user is told the request
succeeded.**

#### Evidence — the observability that would have caught it

| Capability | Exists? |
|---|---|
| Correlation / request id | **NO** — grep for `request_id`, `X-Request-Id`, `correlation`, `trace_id` returns nothing. `bootstrap/app.php`'s `withExceptions` closure is **empty**. Every log line is an orphan. |
| Metrics | **NO** — no counters, timers, StatsD, Prometheus or OpenTelemetry. |
| Health endpoint | Laravel's stock `/up`. It reports that the framework boots. **A monitoring system polling `/up` would have reported the system green throughout every failure in this document.** |
| Alerting | **One mechanism, structurally broken.** `errors:digest` scans only `ERROR/CRITICAL/ALERT/EMERGENCY`, while **every scheduled service logs at `warning`** and production sets `LOG_LEVEL=warning`; it returns SUCCESS and sends nothing if the ops email is unset; it scans the last 2 MB of an unrotated unbounded file; and **it is delivered through the queue whose failure it cannot detect.** |
| APM / exception monitoring | **NO** — no Sentry, Bugsnag, Flare or Telescope. |
| Authentication logging | **NO** — `grep "Log::" AuthController.php` returns **no matches**. No log line for a successful login, a failed login, a lockout, a password change, a role change or a 403. |
| Queue failure visibility | `failed_jobs` **has no reader, no pruning, and no `Queue::failing()` listener** (grep for `Queue::` → zero matches). |
| Backup health | No `notifications` block, no `backup:monitor` on the schedule, `tries=1`, no retry, no `->onFailure()`. |
| Restore | **No data restore path exists anywhere in the product.** |

#### Why it is structural

The two failures that hurt most — a stopped cron and a stopped queue worker — are the two that are
**completely invisible**, and both leave the product looking entirely healthy. There is direct
in-code evidence that the second has already happened in production and locked a paying customer
out of their new workspace (`MailService.php:102-106`, *"rev 170: CRITICAL mails … are sent INLINE
… so they go out even when no queue worker is running (the silent failure that locked a paying
client out of their new workspace)"*). **That fix was applied to credential mail only.**

Three compounding facts make the queue the worst failure mode in the product:

- **The auto-suspend write is direct, not queued.** A paying customer is locked out of a live HR
  system **having received none of the five warning emails that were supposed to precede it.**
- **The audit trail lies.** `subscription_alerts.status = 'sent'` is recorded on the basis of a
  `mail_log` id returned the moment the row is inserted — *before any delivery attempt*. **The
  system positively asserts that an email was sent when it was only enqueued.**
- **The emails an administrator would test still work**, because credential mail is sent inline.
  The natural diagnostic comes back green.

Adding logging to 426 catch blocks is not the fix. The fix is that **most of them should not
exist** — and deciding which is which requires knowing what each was defending against, which
requires the tests that do not exist.

#### Spine response

**S5.6** forbids a silent catch in a domain path, enforced by static analysis; every function is
total and returns a typed error value that propagates to a surfaced rule-evaluation failure.
**S18** mandates an operations dashboard showing scheduler heartbeat, queue depth and oldest
pending job, failed jobs, mail queue, webhook queue, biometric sync health, backup status **and
the last successful restore test date** — with alerting, and with an installer that refuses to
complete without a working scheduler and queue worker. **S14** routes every write through the
audit service so the chain cannot be silently broken.

---

### F7 — There is no service layer

> **Statement.** SmartPRS 2 has no domain layer. Business logic lives in controllers, in Blade
> templates, in a 460-line PHP array literal, and in ~12,000 lines of JavaScript embedded in a PHP
> heredoc. Services depend on controllers. An authorisation primitive lives on a data-feed
> controller. There are real controller↔service cycles.

#### Evidence

**The god classes.**

| Lines | PHP methods | File | Responsibilities |
|---:|---:|---|---|
| **12,533** | **2** | `AppController.php` | **The entire front end** — screen registry, per-screen renderers, the permission matrix, the plan gate, the edition gate, the subscription banner, the update panel. 2,855 lines contain DOM code. It is not a controller. |
| 3,090 | 49 | `AppDataController.php` | The SPA data feed **+** employee persistence **+** the archive due-processor **+** the payslip PDF **+** the payroll *calculator* **+** the payroll-history fabricator **+** `selfScope()`, **an authorisation primitive other controllers call**. |
| 2,845 | 36 | `PayrollGenController.php` | The payroll engine **+** its own period resolver **+** two working-day calculators **+** its own runtime DDL **+** its own ledger-reversal logic **+** its own pay-date resolver. |
| 1,929 | 26 | `RequestController.php` | Nine request types **+** the commission ledger **+** bounce/clawback **+** the salary-payment ledger **+** an HTML voucher generator **+** an orphan purge. |

**The misplacements.**

- `ApprovalService` — the load-bearing authorisation helper and approval engine — is a **381-line
  service living in `App\Http\Controllers`**, imported by `App\Services\FeaturePermissions`
  (a service importing a controller), and it creates and alters tables inline during HTTP requests.
- `SubscriptionService.php:111` calls `ClientUpdateController::seatLimit()` — **a service
  depending on a controller**.
- `AttendanceReportController:104` calls `AppDataController::selfScope()` — **an authorisation
  primitive on a data-feed controller**.
- `FinYearController::fyOf/current/active/range/stamp` are static domain helpers on an HTTP
  controller.
- `MasterController::def()` is **463 lines of nested array literal** governing 58 screens, 54
  tables and the entire master-data contract.
- `ScreenHelpController` is 2,206 lines of product copy in a controller, re-materialised on every
  request with no cache.

**The front end is the enforcement point.** `AppController::show()` reads a 234 KB static HTML
prototype off disk and splices three blocks into it as *strings*: a CSS heredoc, a
`window.__SMARTPRS` config object, and a 12,060-line JavaScript heredoc. There is no build step,
no bundler, no framework, no module system, no `package.json` and no `resources/js/`. Because it
is an interpolating PHP heredoc, the JavaScript cannot use a bare `$`, cannot use ES6 template
literals, and must double every backslash escape. Every page load ships ~1.2 MB with
`no-store, no-cache, must-revalidate, max-age=0`, so **nothing is cacheable and the full payload
is re-downloaded and re-parsed on every navigation**.

Inside it:

- 19 separate escape helpers with four different escape sets; **none escapes `'`**; the prototype
  itself has **zero** escape functions, and its generic list renderer returns the raw value
  straight into `innerHTML`. 142 `innerHTML =` assignments in the boot JS; 13 more in the
  prototype.
- **33 `safe(fn)` wrappers around every boot step**, each `try { fn(); } catch (e) { /* keep
  booting */ }`. `applyPerms()` and `wireEditionLockdown()` are both inside one. **A silent
  exception in either disables access control for that session** with no console error and no
  signal.
- Prototype globals are monkey-patched by wrapping and setting sentinels
  (`render.__permWrapped`, `go.__memWrapped`, `renderCustom.__rmWrapped`, …). `reqApplyOpen` is
  wrapped **four separate times**. Behaviour depends on installation order.
- **~14 nav items exist only as runtime DOM injections anchored to *other* nav items**, coupling a
  234 KB HTML file and a 12,533-line PHP controller in both directions, with no test. Removing or
  renaming an anchor silently deletes an unrelated screen from the sidebar.
- **26 of 146 screens have no permission mapping at all** — including `statutory-config`,
  `sys-updates`, `coa`, `vouchers`, `settlement` and `recovery-reports` — so even a tenant that
  has fully configured its permission matrix cannot gate them.
- **No History API.** `pushState` appears zero times. Browser Back exits the application; deep
  links, bookmarks and "open in new tab" do not work for 145 of the 146 screens.
- A **second, dead front end**: `resources/views/layouts/app.blade.php` plus 17 Blade views
  extending it, referencing ten named routes that do not exist. Any request rendering it throws
  `RouteNotFoundException`.
- Five CDN origins (Leaflet, SheetJS, Razorpay, Font Awesome ×2 versions, Google Fonts), **none
  with SRI**, all executing in the authenticated context with access to the CSRF token — and an
  on-prem install behind a firewall loses maps, Excel export and payments with no degradation
  path.

**Twelve duplication hotspots**, of which at least six already disagree: working-day calculation
(4 copies), punch date parsing (4, three different rules), "present" derivation (4), late-policy
resolution (4), invoice numbering (4, one using `count()+1`), and the PT slab table (2 — one being
a **hand-copied snapshot in `tests/manual/pt_extract.php` kept in sync by a docblock
instruction**).

#### Why it is structural

The front end is 15% of the codebase, has zero reusable lines, and **carries part of the
authorisation model**. It must be rewritten regardless of any other decision. Once it is
rewritten, every screen's data contract changes; and because ~90 of 114 prototype screens are
re-registered server-side as `type:'custom'` and rendered by a **58-branch `if/else` chain inside
the heredoc**, there is no declarative registry to migrate — the UI *is* the code.

Meanwhile the back end cannot be modularised in place, because the modules do not exist: the
payroll calculator is in the SPA's data-feed controller, the approval engine is in the controller
namespace, and an authorisation primitive is a static method on a controller that another
controller calls.

#### Spine response

**S2** mandates a modular monolith with each bounded module a first-class namespace carrying a
declared public contract, and a **dependency-rule test (Deptrac or equivalent) that fails the
build** when a module reaches into another's internals or when `Domain` depends on `Http`. One SPA
against `/api/v3` exclusively, so the public API is proven by the product's own UI — explicitly
so that the two-front-end situation is not repeated. **S9**'s `EntityWriteService` becomes the
single write path for an employee, with PATCH semantics, replacing today's nine independent write
paths and the full-record-replace save.

---

### F8 — Nothing that computes money is tested, and almost nothing is atomic

> **Statement.** The suite exercises **18 of 422 routes (4.3%)**, and **not one rupee of a
> generated payslip is asserted anywhere**. Separately, the application contains **zero locks and
> four transactions**, producing at least 25 catalogued race conditions, 14 of which move money.

#### Evidence — the test surface

| Measure | Value |
|---|---:|
| Test files / declared cases / assertion calls | 17 / **132** / 446 |
| Routes exercised, of 422 | **18 (4.3%)** |
| Controllers referenced, of 84 | **2** |
| Services referenced, of 32 | 9 |
| **Product modules with ZERO tests, of 24** | **16** (a further 4 have smoke-only coverage) |
| CI | **none** — no `.github/`, `.gitlab-ci.yml`, `Jenkinsfile`, `Makefile`, `.husky/`, `docker-compose.yml` |
| Static analysis | `larastan` declared, **no `phpstan.neon`** — `composer analyse` has never been runnable |
| Coverage | **no `<coverage>` element, no threshold, no report writer.** No coverage figure has ever been produced for this codebase |
| Factories | `database/factories/` **does not exist**, though `composer.json` PSR-4-maps a namespace to it and Faker is a declared dependency |
| Test database | **SQLite `:memory:`** against a MySQL production |

`PayrollTest` #1 asserts `count === 2` and **would pass if both payslips were ₹0**.

**The SQLite choice is not merely suboptimal — it is structurally blind.** SQLite has no `ENUM` and
no column-length enforcement. Seven migrations carry MySQL-only `ALTER … MODIFY` DDL behind driver
guards, so they *run and do nothing* under test. The most important widens
`payroll_runs.status` from `ENUM('draft','locked','approved','paid')` to `VARCHAR(30)` **so that
`hr_approved` can be stored at all**. Under test the whole approval state machine appears to work
because SQLite accepts any string. **The test suite structurally cannot reproduce the production
defect class that migration exists to fix.** Likewise `PayrollLock::isPeriodFinalised()` uses
`DATE_FORMAT`, which SQLite lacks, so the query throws, the catch returns `false` (fail-open), and
**the payroll period-lock guard is untestable and always open under test.**

And the suite's colour depends on an untracked local file: `routes/web.php:22` calls
`Edition::isOnPrem()` at parse time, reading `.env` line-by-line and deliberately bypassing
`config()`, so `phpunit.xml`'s `<env>` entries cannot override it. On a developer `.env` carrying
`SMARTPRS_EDITION=l1|l2|l3`, one auth test fails and **all eight billing tests 404**.

#### Evidence — atomicity

```
grep -rn "lockForUpdate|sharedLock|Cache::lock|->lock(" app/  →  0
grep -rn "DB::transaction|beginTransaction"          app/    →  4
```

No pessimistic locking, no optimistic locking (no `version` column anywhere), no advisory lock.
Twenty-five catalogued races; the four graded CRITICAL:

| # | Race | Consequence |
|---|---|---|
| C-1 | **Payroll generation run twice.** `existingRun()` is read at `:2359`; the run header is inserted at `:2622`, after `compute()` — which at 2,000 employees takes minutes. `payroll_runs` has **no unique index on `(tenant_id, company_id, cycle_label)`**. Only 2 of ~180 mutating buttons in the SPA disable themselves in flight. | **Two draft runs and two payslips per employee for the same month.** `net_total` doubles in every register; the bank pack pays twice. |
| C-2 | **Payroll side effects applied after `DB::commit()`.** The transaction covers only the run header and the payslips; the commission lock, `commission_payments`, the overtime stamp, the loan-EMI counter and the clawback stamp all run *after*, each in a swallowing catch. | Orphaned ledger debits; `commissions.locked_at` pointing at a run that may not exist. |
| C-3 | **Regenerate deletes outside any transaction.** Old payslips and the run header are hard-deleted at `:2389-2390`, then four best-effort reversals, then the recompute; the transaction opens at `:2620` for the new rows only. | **The wage register for a month is destroyed before its replacement exists.** |
| C-4 | **`app_state` last-writer-wins blob.** | Silent, unattributable, unrecoverable tenant-wide data loss. |

Plus: invoice numbering (MAX+1, unlocked, and `onprem_clients.invoice_no` has **no unique index**
— duplicate legal GST invoice numbers issued to a client bank); leave balance check-then-act;
seat counting `COUNT(*)` then insert with no constraint; employee-code generation
(`EmployeeImportService.php:376` assigns a random `EMP-nnnn` **with no uniqueness check at all** —
with 500 blank-code rows from a 9,000-value pool the expected number of collisions is ~13, each
throwing into an import catch, so **~13 employees are silently absent from a "successful"
import**, and having no code, their punches never match and they are marked absent every day);
the audit hash chain (read-then-insert with no lock, producing **false tamper alarms under
ordinary concurrency**); and the `attendance_ratings` upsert whose match key omits `tenant_id`
against a unique index that also omits it — **a deterministic cross-tenant write**.

#### Evidence — performance, as the same absence

`->paginate()` / `->simplePaginate()`: **0 occurrences in `app/`**. 308 unbounded `->get()` calls.
`->chunk()`/`cursor()`/`lazy()`: **1**. Two job classes, three dispatch sites — every report,
export, PDF, payroll run and payment-gateway call happens inside the HTTP request, including five
Razorpay calls **with no timeout at all** and one integration call with a **900-second** timeout.

`/app/data` — the endpoint every page load hits — returns every employee projected to ~62 fields,
every reference row, **every payroll run and every payslip the tenant has ever had**, and fetches
six org tables **with no tenant filter at all**. Modelled: ~30–60 MB at 1,000 employees;
**PHP memory exhaustion at 5,000**; outright failure at 10,000.

#### Why it is structural

You cannot refactor what you cannot regression-test, and **96% of the routed surface has no test**.
Adding locks and transactions to 25 race sites means changing the transaction boundaries of every
money path — in a product where the DDL is *deliberately* placed outside transactions because
MySQL DDL implicitly commits (F2), so the boundaries cannot move until the DDL is gone.

Building the harness first is not a shortcut either: a meaningful harness must run on **MySQL**
(because the whole defect class is MySQL-specific), must have **factories** (which do not exist),
and must exercise **runtime-created tables** — which under `RefreshDatabase` do not exist until the
first request creates them, so a feature test today exercises the runtime-DDL path and never the
migrated one. `tests/Pest.php:71-77` already has to add `plans.seat_max` itself with
`Schema::table()` because no migration creates it: **the fixture layer papers over a real
production schema gap.**

#### Spine response

**Law 7** (money is exact): integer minor units in a value object through every calculation, a
type-level ban on float arithmetic in `Domain\Money`, declared rounding per component,
deterministic residue allocation via `ALLOCATE`. **S11**'s reconciliation assertion aborts any run
whose payslip lines do not sum exactly to its totals. **S7** requires every formula to be testable
against named sample employees with expected values asserted before publish. **S6** requires a
regression harness in which every rule may carry stored test cases that run at publication. **S20**
requires exact-value regression tests as a deliverable of every part of this blueprint.

---

## II.2 The critical defects live in production right now

Ranked by (money or legal exposure) × (probability of occurrence) × (time undetected). Every row
is **confirmed at source**.

**"Remediate now?"** answers a single question: *does this need fixing in SmartPRS 2 before
SmartPRS 3 ships, independently of the rebuild?* The rebuild will take time; these are the items
that cannot wait for it.

| # | Defect | Evidence | Business impact | Remediate now? |
|---:|---|---|---|---|
| **1** | **A nightly scheduled task creates administrator accounts with a source-published password on every install.** `Schedule::command('demo:reset')->dailyAt('03:30')` has **no environment guard and no `->when()`**, and `DemoReset` never reads `APP_ENV` or `SMARTPRS_DEMO_DATA`. If no `subdomain='demo'` tenant exists, one is **created**, along with `demo-admin@smartprs.in` (role **`admin`**), `demo-hr@` and `demo-field@`, each active with `Hash::make('smartprs-demo')` — the option default, printed to stdout on every run. If those emails already exist under **another** tenant they are **taken over**: the lookup is `whereRaw('LOWER(email) = ?')` with **no tenant scope**, and the update reassigns `tenant_id` and overwrites the password. | `routes/console.php:208-211`; `DemoReset.php:35, 54-65, 101-123, 152` | Every deployment following the documented cron instructions gains, within 24 hours, an **active admin login with a published password**. Nightly re-creation defeats manual deletion; nightly password reset defeats a manual password change. | **YES — IMMEDIATELY.** Gate the schedule on `APP_ENV` + `SMARTPRS_DEMO_DATA`, and audit every install for those three accounts. |
| **2** | **A hard-coded tenant-admin credential is created by a documented setup step.** `OnPremWorkspaceSeeder` inserts `admin@smartprs.local` / `password`, grants it the `admin` role on `tenants ORDER BY id` (an arbitrary tenant), prints the password, and is **not** environment-gated. Its own docblock instructs running it on an on-prem install. `README.md:57-64` publishes the same five logins and shared password. | `OnPremWorkspaceSeeder.php:70-100`; `README.md:57-64`; `DatabaseSeeder.php:19-30` (which does **not** call it) | Full tenant-admin access — every employee's salary, PAN, bank details and identity documents; payroll generation; user management — on any install where the documented unlock step was run. | **YES — IMMEDIATELY.** Audit the entire deployed base. This is the highest-urgency operational item in this blueprint. |
| **3** | **`/app/data` fabricates twelve months of payroll and permanently blocks the real runs.** `ensurePayrollHistory()` is called on **every** `/app/data` load. For each of the last 12 months with no run it **inserts a `payroll_runs` row and a `payslips` row per employee**, with `status = 'paid'` for k>0, computed from full CTC — no LOP, no attendance, no components, no commissions, no recoveries. `$emps` is **role-scoped**: a plain employee opening the app first creates 12 runs containing **one person** with `employees_count = 1`. `generate()` then refuses any month whose run is `paid`. | `AppDataController.php:458, 2340-2381, 2361, 2367, 268-273`; blocked by `PayrollGenController.php:2362-2365` | **Eleven months of fabricated "paid" payroll per company that nobody can correct through the UI.** The fake slips corrupt `esiPeriodLock()`, `payslipYtd()` and `PayrollLock::isPeriodFinalised()`, which then blocks statutory configuration changes dated into those months. | **YES.** Remove the back-fill; script a detection and clean-up for existing installs. |
| **4** | **Loss-of-Pay leave is paid in full.** Payroll decides paid-vs-unpaid from `leaves.type_id`; `LeaveController::apply()` writes `type_name` and **never `type_id`**. `type_id` is therefore always NULL, `$paid` is always `true`, and the `continue` that skips unpaid leave is unreachable dead code. Grep confirms two reads and **zero writes** of `type_id`. | insert `LeaveController.php:364-378`; consumers `PayrollGenController.php:1063-1067, 743-746` | **Every approved leave — including leave explicitly filed as "Loss of Pay" — is credited as a paid present day.** LOP deductions never happen through the leave route. Direct, ongoing salary overpayment across every employee who has ever taken LOP. Recovery means clawing money back from staff. | **YES.** A ~25-line fix plus a data reconciliation per customer. |
| **5** | **Loans are never recovered, or are recovered forever.** `loanEmiDue()` reads four columns that **do not exist in any migration and are never created at runtime** (`installment_amount`, `start_month`, `installments_total`/`tenure_months`, `type`). The migrated column `tenure` is **never read**. `safeRow()` silently discards the form's `tenure_months`. **On the migrated schema `outstanding` defaults to `0`, so the guard `0 !== null && 0 <= 0` → `continue`: the EMI is never recovered at all.** If `outstanding` is non-zero, `$total` is 0, so the "fully recovered" and auto-close guards never fire and **the EMI is deducted every month forever.** | `PayrollGenController.php:682-702, 2442, 2794-2797`; `ApprovalService.php:265`; migration `2026_01_01_000300:119-129` | Employee loans are either never recovered or over-recovered indefinitely. The in-app guide's promise that *"loans auto-close after the last instalment"* is false on the shipped schema. | **YES.** Money in both directions, and an employee-relations incident when discovered. |
| **6** | **Commissions never reach a payslip on a freshly migrated install.** `commissions.status` is `ENUM('provisional','confirmed','reversed')`; every code path writes `'pending'`, `'approved'` or `'rejected'`, and `commissionByEmployee()` filters on `'approved'`. On strict MySQL every insert throws `Data truncated`; on non-strict MySQL the status is **silently blanked**, so no commission is ever `approved`. The project's standard remedy (runtime ENUM widening) is applied to `incentive_schemes` and **never to `commissions`**. | migration `2026_01_01_000300:80`; `IncentiveController.php:81, 238`; `SchemeController.php:644, 839, 901`; `PayrollGenController.php:605` | **On a fresh install, commissions, incentives and arrears never pay** — in the module that *is* the collections vertical. | **YES.** A one-line widening migration. |
| **7** | **Statutory challans do not reflect the payroll that ran.** `statutoryPdf()` recomputes every employee from `ctc` with no context, no status filter, no company filter and no period filter, then labels the output with the current month. ESI rounding differs between the payslip (`ceil`) and the challan (`round`). Payslip TDS and Form 24Q differ by up to ₹62,400 at one worked CTC because marginal relief is applied by only one of the two callers. | `AppDataController.php:1639-1749, 1669, 1686, 1688-1689, 1732, 1779, 1836-1840` | **The PF ECR and ESIC challans a customer downloads and files do not equal what was deducted.** A statutory filing error, not a display bug. | **YES** for the reconciliation; the full fix is a rebuild item. |
| **8** | **The Form 24Q filing history is fabricated.** Six quarters are emitted; the current is `Pending` and **every prior quarter is hard-coded `Filed`** with `deposited = perQ`, regardless of whether anything was filed. | `AppDataController.php:1785-1810`, `:1800` | **The product tells a customer their TDS returns are filed. They are not.** A fabricated compliance record on a tax screen. | **YES — REMOVE THE SCREEN.** It cannot be made correct without the filing feature that does not exist. |
| **9** | **`pf_applicable` / `esi_applicable` are inert.** Both are captured on the employee form, accepted by the import template, exported, and **printed on the payslip** ("ESI: Applicable / Auto"). Neither is read by `statutory()`, `computeSlip()`, `computeSlipFromComponents()` or `PayrollGenController` — **zero readers** across `app/`. | writers `AppDataController.php:2940-2944`; display `:389-398`; `payslip-pdf.blade.php:66, 120` | An employer who marks an employee PF-exempt or ESI-not-applicable **still has PF and ESI deducted**. This is the only per-employee statutory-applicability control the product offers, and it does nothing. | **YES.** |
| **10** | **The attendance date is never shift-aware — night shifts are destroyed.** `log_date` is `punch_at`'s calendar date on every writer, and `pairPunches()` groups strictly by `emp_code|log_date`. `ShiftResolver` computes a `night` flag and the report advances the shift end by a day, so the *intent* exists. A 22:00–06:00 shift produces day 1 with an open session, `last_out = —`, `total_work = 0` and ~480 minutes of phantom early-exit; day 2 with an orphan OUT. **Two "present days" for one shift.** Separately, `late_min = max(0, first_in_min − (shift_start_min + grace))` on minutes-since-midnight means **a night-shift worker can never be marked late** (00:15 against a 22:00 start gives `max(0, 15 − 1320) = 0`), while a punctual 22:00 arrival is simultaneously charged ~8 hours of early exit. | `ETimeOfficeService.php:526, 761`; `PunchIngestService.php:146, 251`; `AttendanceReportController.php:119-123, 304, 741, 834-836`; `DeviceController.php:149` | LOP, late-cuts, overtime and night allowance are all wrong for every night-shift employee. A 30 September IN and a 1 October OUT fall in different payroll months. | **PARTIAL.** The late-minutes fix is tractable now; the attendance-day model is a rebuild item (spine S10). |
| **11** | **Silent 5½-hour timezone corruption on four of six machine ingest paths.** `Carbon::parse()` honours an offset in the string, then `->format('Y-m-d H:i:s')` renders it in that offset and **discards the offset**. A punch arriving as `2026-08-16 09:41:00+00:00` on an Indian install is stored as `09:41:00` when the person clocked in at `15:11`. The SBB path exists specifically to avoid this and **rejects** offsets; the older paths were never fixed. | `PushController.php:177-181`; `ETimeOfficeService.php:217-221`; `GenericApiService.php:452-456`; `DeviceController.php:148-149`; contrast `PunchIngestService.php:312-314` | Five-and-a-half-hour errors straight into payroll, silently. | **YES.** |
| **12** | **Four "fail-soft" DDL catches cause recurring double-payment of real money.** If `overtime.paid_run_id` cannot be added, OT is never stamped paid and **next month's run pays the same overtime again**. If `loans.installments_paid` cannot be added, the loan never closes and the EMI is deducted **forever**. If `loan_recoveries` cannot be created, a regenerated run **double-recovers** every EMI. If the clawback columns cannot be added, the clawback is recovered again next month. **On a correctly hardened database whose app user lacks `ALTER`, all four fire.** | `PayrollGenController.php:2571-2616` (`:2576`, `:2585`, `:2601`, `:2614`) | Recurring, silent, compounding money errors — worst on exactly the installs whose DBA did the right thing. | **YES.** Convert to migrations; make the absence of a column a hard failure. |
| **13** | **Payroll regenerate destroys the wage register before creating its replacement.** Old payslips and the run header are hard-deleted **unconditionally, outside any transaction**, then four best-effort reversals run, then the recompute; the transaction opens for the new rows only. A rejection also records **no author and no timestamp**, and regenerating **deletes the rejected row entirely**. | `PayrollGenController.php:2389-2390, 2417-2466, 2620`, `applyDecision() :809` | A crash mid-regenerate destroys a month of payslips and produces nothing. The audit record of *why* a run was rejected disappears. | **YES.** Wrap the whole operation; supersede rather than delete. |
| **14** | **`safeRow()` silently discards data at 98 sites.** `array_intersect_key` against the live column list, with no exception, no log and no user signal. Nine confirmed losses on the mainline path — including `expenses.reason`, the note that is the entire point of an expense claim. **The user is told the request succeeded.** | `ApprovalService.php:262-266`; schemas `2026_01_01_000300:96-165` | Ongoing, invisible data loss on nine business objects. | **YES.** Make a dropped key an error, and fix the nine column-name mismatches. |
| **15** | **Archived employees still consume a seat, while the product's own error message says they do not.** `seatUsage()` filters `deleted_at` and `status` but **not `archived_at`**; archiving writes only `archived_at`; the directory hides archived employees. And the on-prem counter uses the **opposite** definition. | `SubscriptionService.php:87-97` vs `:141`; `AppDataController.php:2651-2655, 259`; `ClientUpdateController.php:515-520` | **The customer is billed for employees the product will not show them, and is told the opposite in writing.** Revenue-affecting in both directions and indefensible in a billing dispute. On-prem, archiving is an unlimited cap bypass. | **YES.** Fix before any billing data migrates. |
| **16** | **The seat cap is bypassed at the two doors used for bulk creation** — the import wizard and the self-onboarding injection — and **fails open** on no tenant, `licensed <= 0`, or any exception. Off-roll agents are never counted at all. | `grep canAddEmployees` → `AppDataController.php:815, 3042`, `RecruitmentController.php:602` only; `:130-133`, `:115-118` | Direct revenue leakage. An agency can run 500 off-roll agents on a ₹1,000/month 25-seat plan. | **YES.** |
| **17** | **Off-roll agent KYC documents are written to the `public` disk** — PAN, ID proof, address proof, DRA and PCC scans — via `->store('offroll-docs','public')` with `'visibility' => 'public'`. Onboarding documents are copied to `employee-docs/<sequential employee id>/` on the same disk. **`public/.htaccess` does not exist in the tree**, so on Linux/Apache there is no version-controlled deny rule. Five upload paths write to the public disk; **no MIME or magic-byte check exists anywhere** (`grep finfo_|getMimeType()` → 0). | `OffrollAgentController.php:450`; `SelfOnboardingController.php:953-955`; `DocumentController.php:214-234`; `config/filesystems.php:38-44, 69-71` | Government identity documents and police clearance certificates of people who are not even users of the system, on an unauthenticated path. **A DPDP exposure and an automatic fail in any bank's security review.** | **YES — IMMEDIATELY.** Move to the private disk; add a deny rule; add magic-byte validation. |
| **18** | **Update packages are not signed.** The only integrity control is a SHA-256 supplied by the same server in the same JSON response, and the check is **skipped entirely** when the hash is absent. The package is extracted over the application root and `php artisan migrate --force` is executed. **There is no database backup and no database rollback anywhere in the update flow**; rollback restores code only, after migrations have already run; and the post-update health check is `artisan --version`. | `UpdateServerController.php:287`; `UpdateClient.php:180, 254-261`; `updater/updater.php:140-165, 174, 252-269, 285-320` | A compromised release server or a compromised `/super` account is **complete RCE as the web user on every customer's HR and payroll server**, delivered by the product's own mechanism, with maintenance mode and a backup. A failed migration leaves old code against a moved-forward schema, brought back up in that state. | **YES.** Sign packages with the RSA pair already used for `.lic`; make verification mandatory; add a database backup step. |
| **19** | **`/iclock/*` — six routes, unauthenticated, CSRF-exempt, `throttle:1000,1`, writing directly to the payroll input.** Identity is a caller-chosen `SN` query parameter; an unknown serial **auto-registers a `biometric_configs` row** and the handler runs **`Schema::create` + seven `Schema::table` calls from an unauthenticated request**. `rowForSn()` has **no tenant filter** and `serial_number` has **no unique index**, so a tenant registering another customer's serial captures that device's punches. | `routes/web.php:92-105`; `PushController.php:38-54, 106-140, 207, 209-224, 266-284` | Anyone who learns a serial (printed on the device) can fabricate attendance — and erase absence by inventing punches — for a real tenant. On a shared install, cross-customer punch capture. | **YES.** Move out of the `web` group, require a device credential, stop auto-enabling unknown serials, stop running DDL. |
| **20** | **`POST /app/state` — no guard, no size limit, no schema validation.** Any authenticated user replaces the tenant's entire working data set, which contains `DB.roles[].perms` — the matrix the client reads on every login. | `AppStateController.php:22-53`; `AppController.php:676-683, 769-790` | **Persistent, tenant-wide privilege escalation**, or tenant-wide destruction with `{"state":{}}` — no history, no soft delete, no audit. | **YES.** |
| **21** | **`denyUnlessRole()` fails open at 71 sites**, and there is **no route-level authorisation** — 61 authenticated routes have no server-side check. Any employee reads every colleague's increment (old CTC → new CTC), loan, advance, expense, exit and transfer; any employee can **raise an exit/FnF for the CEO**; any employee downloads the statutory registers with every colleague's PAN and UAN; a `field_agent` receives the entire workforce's PII, bank details and CTC on page load. | `ApprovalService.php:365-380`; `RequestController::list() :125-178`, `apply() :405-419`; `AppDataController::statutoryPdf() :1642`, `selfScope() :221-245` | Bulk disclosure of salary, PAN, UAN, bank and identity data to every logged-in user. A fabricated exit record for an executive. | **YES** for the specific unguarded endpoints; the model change is a rebuild item. |
| **22** | **Two features share the table name `attendance_pending` with disjoint schemas.** Whichever is created first wins; the other is permanently broken. A repair migration exists because this happened, and leaves the table carrying both column sets with `punch_at` nullable. | `AttendanceBulkController.php:37-56`; migrations `2026_08_16_000300`, `2026_08_17_000100`, `2026_08_18_000100` | Either bulk attendance upload is broken, or the device-quarantine promise ("punches are never lost") is void — and which one depends on click history. | **YES.** Split the tables. |
| **23** | **A migration silently deletes production punches.** `2026_08_16_000200:125-155` calls `attendance:dedupe` — a hard DELETE across the whole table, no tenant filter — during `php artisan migrate`, with no confirmation, no dry run, no backup and no record of what was removed. `2026_08_17_000100:213-233` does it again. `$keep` grows to the size of the table, so at real scale it OOMs **having already deleted an arbitrary prefix**. | as cited | Statutory attendance records destroyed during a routine deploy, for every tenant on the server. | **YES.** Remove the call from the migrations. |
| **24** | **Employee matching tries the whole `emp_code` column before it ever looks at `device_user_id`.** The loop is `foreach (['emp_code','device_user_id']) { foreach ($codes) }`, so a raw device PIN coinciding with *another* employee's `emp_code` wins over the correct, explicitly configured mapping. | `ETimeOfficeService.php:315-345` | A punch is filed against the wrong person, with no error, and flows into that person's attendance and payroll. **Highly likely wherever employee codes are short numerics.** | **YES.** Reverse the loop order. |
| **25** | **Four code paths break the tamper-evident audit chain**, and the events that break it are exactly the ones most worth trusting: every commission approval carrying a DRA/compliance/grievance warning, and every bounce. Direct `DB::table('activity_logs')->insert()` with no `prev_hash`/`row_hash`; `verify()` then resets `$prev` to `'GENESIS'` at that row. The chain also has a **read-then-insert race with no lock**, producing false tamper alarms under ordinary concurrency, and is **global rather than per tenant**, so one tenant's edit reports as a break for all. Money-moving commission actions — edits after approval, payments, manual locks, Accounts confirm/flag, orphan deletion — **never reach the ledger at all**. | `RequestController.php:1382-1387, 1424-1429, 1444-1449, 1822-1827`; `Audit.php:41-43, 90-99, 111, 118-121` | The one control that makes the ledger trustworthy is defeated by the exact events it exists to record — on a ledger the product markets an RBI-compliance report against. | **YES.** Route every write through `Audit::record`. |
| **26** | **The `accountant` role cannot be created**, so the advertised segregation of duties on commission money collapses to "an Admin confirms and an Admin approves" — and the attempt produces a **role-less user labelled "Admin" with unrestricted reads**. Separately, `admin` satisfies **both** `isHR()` and `isFinance()`, so **one person can prepare, approve and disburse a payroll run alone**. | `RolePermissionSeeder.php:41-58`; `UserController.php:31, 298-308`; `AppController.php:93, 769`; `SalaryApprovalController.php:59-68` | The two four-eyes controls a customer's auditor will ask to see evidence of do not exist. Configuring one makes things worse. | **YES** for creating the role; the model change is a rebuild item. |
| **27** | **The RBI audit pack's assertions are not all verified.** "Disciplinary actions: 0 · Compliant" from a literal; "Code of Conduct: Acknowledged" for every employee without a login, because the `user_id` filter drops out and the check degenerates to *"has anyone in this tenant acknowledged?"*; a fixed 90-day complaint window while the comment says financial quarter; an unkeyed, unpersisted, unverifiable reference; and a fully compliant agent capped at **85/100** because the NDA leg requires `letters.letter_type='nda'` with `status='signed'` and **`letters-nda` has no backend definition** so no NDA can be created through the UI. `agent_authorizations.status` is never read, so a **revoked** authorisation with a future `valid_to` reads as Compliant. | `ComplianceController.php:596-611, 617, 619, 625, 643-644`, `scoreFor() :312-318`; `MasterController.php:356-389` | **This is the artefact handed to a client bank.** A forged report is indistinguishable from a genuine one, and Ametecs cannot prove what any issued report said. | **YES** for the fabricated items and the CoC degeneration; the rest is a rebuild item. |
| **28** | **Off-roll agents — the population RBI norms most directly target — are outside every compliance mechanism**, and their DRA and PCC fields are `$t->string()` with **no expiry column at all**, so there is nothing for a scan to read even if one were written. Their earnings carry **no TDS**, unlike the on-roll commission path which deducts §194H. Their KYC documents are on the public disk (#17), their public earnings token **never expires and never rotates** and is looked up with no tenant scope, and their earnings page is silently capped at 100 entries per month so a high-volume agent's displayed total is wrong. | `ComplianceController.php` (no `offroll_agents` reference anywhere); `OffrollAgentController.php:44-45, 102-119, 146-148, 288-291, 321, 450` | **The product's central compliance promise does not cover half the field force.** Commission payments to off-roll agents leave the system untaxed. | **PARTIAL.** Expiry columns and the token rotation now; the Worker model is a rebuild item. |
| **29** | **`DummyDataSeeder` falls back to wiping tenant #1.** It resolves its target as `subdomain='demo'` **`?: tenants->orderBy('id')->value('id')`**, then hard-deletes that tenant's rows from 22 tables including `employees`, `payroll_runs`, `payslips`, `attendance_logs`, `commissions`, `loans` and `exits`. `demo:reset` creates the demo tenant first, so the scheduled path is safe *today*; **any direct `db:seed --class=DummyDataSeeder` wipes the oldest customer.** | `DummyDataSeeder.php:46-47, 79-95` | Irreversible destruction of a real customer's people, attendance, payroll history, commissions and exits — with the only backup on the same server, unencrypted, never restore-tested. | **YES.** Add an unconditional production guard. |
| **30** | **`wa_log.body_values` is a permanent, unencrypted, unpruned store of one-time passcodes and first-login passwords.** `WaService::sendTemplate()` writes the full variable array on every send; `SignupController.php:413-425` puts the tenant admin's **plaintext temporary password** in `bodyValues[4]` whenever `SMARTPRS_WA_SEND_PASSWORD` is truthy — **and it defaults to `true`**. | `WaService.php:126, 166-176`; `SignupController.php:413, 424` | Plaintext credentials at rest, retained forever, inside the nightly unencrypted on-box backup. | **YES.** |
| **31** | **`bank_invoices.invoice_no` and `onprem_clients.invoice_no` have no unique constraint**, on per-tenant-per-FY sequential **GST tax invoice** series generated by an unlocked MAX+1 (and, in `BankPackController`, by `count()+1`). `invoices:renumber-prs` rewrites historical invoice numbers with **no retained record** of the previous values. | `BankPackController.php:38-58`; `OnpremClientController`; `routes/console.php:25-132` | Duplicate legal invoice numbers issued to a client bank; silently rewritten identifiers on tax documents already filed with a customer's accounts. | **YES.** |
| **32** | **A `.lic` licence is defeated by a single SQL UPDATE, and by copying one cache file.** Every downstream check reads `settings(tenant_id=0, key='licence')` and trusts it; the certificate's HMAC `sig` is produced and **never verified anywhere**. `LicenseFile` trusts `storage/app/.machine_fp` whenever it holds 40 hex characters, **before reading any hardware** — copy `license.lic` plus that file and both machines verify. `revoke()` only sets a flag on the vendor's server, and the client learns of it only at login, at most daily, **returning early when the server is unreachable** — so blocking one outbound host keeps a non-paying customer running until `expires_at`. There is **no scheduled heartbeat**. | `ClientUpdateController.php:74-108, 410-422`; `LicenseFile.php:72-78`; `LicenseService.php:171-215, 733-742` | The perpetual-licence product has no technical enforcement against its own customer. | **PARTIAL.** Verify the signature and add a heartbeat now; the model is a rebuild item. |

---

## II.3 Why refactoring cannot fix this

This is the section the Product Owner should read hardest, because the alternative — "fix the 350
defects in place" — is superficially the cheaper plan and is not.

### II.3.1 The honest form of the counter-argument

The case for incremental repair is real and should be stated at full strength:

- The domain understanding is correct and is already encoded (Part I).
- The team knows this codebase; there is no learning curve.
- Each individual defect is small. #6 is a one-line migration. #4 is ~25 lines. #24 is reversing a
  loop.
- A rebuild is 12–18 months of no new features, during which competitors ship.
- Punch ingest — the best subsystem — is genuinely extractable behind its 63 tests, which is
  exactly what a strangler-fig migration would start with.

That last point matters and is conceded: **a strangler pattern would work for one subsystem.**
The argument below is not that incremental work is impossible; it is that **it does not converge
on the product Ametecs needs to sell.**

### II.3.2 The four reasons it does not converge

**Reason 1 — the repairs are mutually entangled, and the entanglement is circular.**

```
Fix tenancy (F5)          →  requires tenant_id NOT NULL and in every unique index
                          →  requires knowing every table and its constraints
                          →  requires removing runtime DDL (F2)
                          →  requires knowing the deployed schema
                          →  is unknowable BECAUSE of the runtime DDL
```

The same loop runs through money. Making money exact (Law 7) requires moving the transaction
boundaries around every payroll write. Those boundaries cannot move while DDL sits deliberately
outside them because MySQL DDL implicitly commits (`PayrollGenController.php:2554-2556`, which
says so in a comment). So money cannot be fixed before the schema is fixed, and the schema cannot
be fixed before the estate is inventoried, and the estate cannot be inventoried because no
production dump exists (Part I §I.11 gap 1).

Making configuration effective-dated (F3) requires history that was never recorded. Making numbers
explainable (F4) requires inputs the payslip never stored. **Both are irrecoverable for the past
under any plan**; the only question is when the recording starts.

**Reason 2 — every repair must be made blind.**

96% of the routed surface has no test. Sixteen of twenty-four product modules have **zero**.
Not one rupee of a generated payslip is asserted anywhere. There is no CI, no static-analysis
configuration, no factories and no coverage figure — ever, in the product's life.

So each of the 350 fixes is a change to a money or compliance path with **no regression harness**,
shipped to on-premise customers by an **unsigned update** that runs `migrate --force` with **no
database backup, no database rollback, and a health check that only proves the CLI boots** (#18).
The expected failure rate of 350 blind changes to a system with 25 known race conditions and 843
catch blocks is not a number anyone should plan against.

Building the harness first does not escape the loop either. A meaningful harness must run on
**MySQL** (the entire ENUM/length/DDL defect class is MySQL-specific and invisible under the
current SQLite suite), needs **factories** that do not exist, and must exercise tables that are
**created by the request under test**. `tests/Pest.php:71-77` already adds `plans.seat_max` with
`Schema::table()` because no migration creates it — the fixture layer is papering over a
production schema gap. **Building a harness that is honest about the current architecture is most
of the work of replacing the current architecture.**

**Reason 3 — inverting fail-open is itself the highest-risk change in the product.**

426 catch blocks destroy their exception. Roughly eight of them are documented, deliberate
fail-open gates on authorisation, licensing, seats, the payroll period lock and the audit ledger.
Turning those closed is correct — and each one, done blind, is a potential outage in a product
whose customers frequently have **no administrator on site** and whose vendor's support policy
excludes onsite visits.

The availability bias is not wrong (Part I R-I-016). What is wrong is that it was applied
**uniformly and silently**, to authorisation as well as to licensing. Separating the two requires
knowing, for each of 426 sites, what it was defending against — and the answer is recoverable for
perhaps eighty of them from comments, and for the rest not at all.

**Reason 4 — the front end must be rebuilt regardless, and it carries the security model.**

`AppController.php` is 12,533 lines, 15% of the codebase, **zero reusable lines**. It cannot be
type-checked, linted, unit-tested, bundled or code-split. It has no History API, no caching, 19
incomplete escape helpers, 142 `innerHTML` assignments, five unpinned CDN dependencies, and a
58-branch dispatch chain inside a PHP heredoc. Around ninety of 114 prototype screens are
re-registered server-side as `type:'custom'`, so there is **no declarative registry to migrate**.

And it is not merely a UI. `applyPerms()`, `spCan()`, `gateActions()`, `wireDemoLockdown()` and
`wireEditionLockdown()` are all inside `safe()` wrappers that swallow their own exceptions
(F7): **a silent client-side exception disables access control for that session.** A client-side
regression is a security regression.

Once the front end is rewritten against a real API, every screen's data contract changes — which
means the back end's read paths change — which means the 61 unguarded routes must be re-specified
anyway. At that point the "refactor" has become a rewrite with a longer schedule and no
architectural payoff.

### II.3.3 What incremental repair would actually cost — scope, not guesswork

Stated as **countable scope**, because that is defensible; the effort band that follows is
explicitly an estimate.

| Work item | Countable scope |
|---|---|
| Write migrations for every runtime-created table and reconcile the duplicates | **59 tables**, at least **8** with two or three competing definitions, **plus 57 registry types**; requires a schema inventory across the whole on-prem estate first |
| Remove runtime DDL | **156** `Schema::*` sites in `app/` + **6** raw `ALTER TABLE` on request paths; then delete **738** `hasTable`/`hasColumn` guards that exist only to serve them |
| Add referential integrity | **0 → ~150** foreign keys with declared `ON DELETE`, after a data-cleansing pass for orphans on every relationship |
| Replace ENUMs with reference tables | **35** declared `enum()` columns; **12 live cases** where code already writes forbidden values |
| Make tenancy structural | **~1,400** `DB::table()` sites; **405** with no filter; **354** id-only; **18** route-reachable IDORs; **3** PII tables with no tenant column |
| Add route-level authorisation | **432** routes; **61** real gaps; **71** fail-open guard sites to invert; a policy layer that does not exist |
| Convert money to integer paise | ~30 float sites plus every consumer; a value object that does not exist; declared rounding per component |
| Add transactions and locks | **25** catalogued races; **0** locks and **4** transactions today; boundaries blocked by the DDL until F2 is done |
| Convert business literals to configuration | **~120** catalogued literals across two dozen files, four bodies of hard-coded prose and the SPA; requires the rule and formula engines to exist first |
| Add effective dating and versioning | **5** configuration stores; the employee record; letters; acknowledgements; `app_state` |
| Add explainability | every derived value in payroll, attendance, leave and statutory; requires the calculator to be a single traceable engine, which requires F7 |
| Rewrite the front end | **12,533 lines**, zero reusable; plus 17 dead Blade views and a dead layout |
| Build a real test harness | MySQL suite, factories (**none exist**), CI (**none exists**), static analysis config (**none exists**), coverage (**never measured**), and golden tests for every money path (**none exist**) |
| Deduplicate | **12** duplication hotspots, **at least 6 already divergent** |

**Estimate.** Assuming the same team, the same delivery cadence, and no new feature work:
attacking the list above in dependency order lands within a band comparable to a rebuild — but
with three differences that make it the worse plan.

- **The dependency order is forced and front-loaded.** Nothing else can start until the schema
  inventory and the migration reconciliation are done, and that work delivers **no customer-visible
  value at all**.
- **Risk is carried the whole way.** Every intermediate state is shipped to production, blind,
  by an unsigned updater with no database rollback. A rebuild carries its risk in one cutover that
  can be rehearsed.
- **The endpoint is worse.** After all of it, Ametecs owns a modular monolith with a rule engine
  and effective-dated configuration — *if* every one of the twelve work items completes. Stop
  anywhere short, and the product is a partially-migrated codebase carrying both the old and new
  mechanism for whatever was in flight, which is precisely the condition that produced
  `attendance_pending`, `code_of_conduct_ack`, the two settings stores and the two front ends.

**SmartPRS 2 already ran this experiment.** `statutory_configs` + `EffectiveDated` is a correct,
scoped, effective-dated, audited configuration store, built and tested — and then **bypassed**,
because the screen customers actually use writes the old flat blob. `PeriodResolver` is correct,
tested, and **wired to nothing**. `letters.pdf_path` is the designed fix for signed-document
immutability and **is never written**. `notifications_feed`, `job_batches`, the entire Spatie
activity log and 5 of 11 Eloquent models are the residue of the same pattern. **Incremental
improvement has been attempted repeatedly in this codebase and has consistently produced a second
mechanism beside the first, rather than a replacement for it** — because there is no architectural
seam to replace anything at.

### II.3.4 The recommendation

**Rebuild on the spine, and treat SmartPRS 2 as the requirements document with a running reference
implementation.** Concurrently, execute the "Remediate now" column of §II.2 in SmartPRS 2 — a
bounded list of roughly 25 items, most of them small, several of them one-line — because the
customers running it today cannot wait 12–18 months for a default administrator credential and
publicly readable identity documents to be fixed.

---

## II.4 What SmartPRS 2 costs Ametecs today

### II.4.1 Implementation effort per customer

Every customer who differs from the built-in assumptions requires **a code change and a release**.
The following are all routine asks, and none is configurable today:

| The customer asks for | What it costs |
|---|---|
| "3 late arrivals over 10 minutes = half day" | Not expressible. `late_policy` has tiers and cut modes, but they are resolved by **four divergent implementations**, only two of which consult the shift, and the occurrence-counting primitive does not exist. **Code change.** |
| "Our approval threshold is ₹25,000, and it needs three levels" | Thresholds are a PHP array; depth is fixed at two; the third tier the docblock names does not exist. **Code change.** |
| "Our LOP basis is ÷26" | Not available, despite ÷26 being the FAQ headline. **Code change.** |
| "Weight the compliance score differently for this bank" | Five literals. **Code change.** |
| "Alert us 90 days before a DRA expires, not 30" | A literal. **Code change.** |
| "Add a LinkedIn Profile field" / "Add a firearms-licence credential" | **No custom-field capability of any kind exists** — no EAV table, no JSON attribute, no field metadata, no per-tenant variation. `EmployeeFieldRules::FIELDS` is a `const`, identical for every tenant on every install. Adding one field is **6 sites in 4 files plus a migration**, and getting any one of them wrong means the field is shown and silently never saved, or saved and then nulled on the next save. |
| "Support our biometric vendor" | Provider dispatch is duplicated in **five** places with no shared abstraction. **A new vendor is a multi-file code change** — the exact opposite of "change configuration, not code". |
| "Give our HR Manager the Accounts role too" | **Impossible.** One role per user, enforced at both assignment surfaces. |
| "Make the Accountant control work" | **Impossible.** The role cannot be created, and attempting it produces a full-looking admin. |
| "Give us a workspace-level data export" | Does not exist, and is **promised in the Terms, the Privacy policy and the Data Protection policy**. |
| "Delete this person's record" (a DPDP erasure request) | **Cannot be honoured in-product.** Soft delete and archive only; no hard erasure, no anonymisation, no retention timer, no purge job, and no `deleted_after` column behind the published 90-day commitment. |

The cumulative effect is that **implementation is a development activity, not a configuration
activity.** Every deal that needs a variation consumes engineering capacity that would otherwise
ship product, and the variation ships to the whole estate.

### II.4.2 Support burden

| Cost | Mechanism |
|---|---|
| **Every incident is a forensic exercise** | 426 catch blocks destroy their exception. No correlation id, no request id, no tenant id on any log line — `bootstrap/app.php`'s `withExceptions` closure is **empty**, so every log line is an orphan. No metrics, no APM. |
| **The product cannot tell you it is broken** | `/up` reports that the framework boots. It says nothing about the scheduler, the worker, queue depth, backup status, biometric sync freshness or mail delivery. **A monitoring system polling it would have reported green throughout every failure in this document.** |
| **The one alerting mechanism is structurally blind** | `errors:digest` scans for `ERROR+` while every scheduled service logs at `warning` and production sets `LOG_LEVEL=warning`; it sends nothing if the ops email is unset; and **it is delivered through the queue whose failure it cannot detect**. |
| **The two most likely failures are invisible** | A stopped cron and a stopped queue worker both leave the product looking healthy. The cron entry and worker unit **live outside the repository**; the README tells the operator to run them by hand in "separate terminals". There is direct in-code evidence the queue failure has already locked a paying customer out of their workspace. |
| **Support cannot restore anything** | **There is no data restore path anywhere in the product.** The nightly backup is unencrypted, on the same server, excludes `storage/app` (which holds every uploaded document and the licence private key), includes `.env` with `APP_KEY`, has `tries=1`, has no monitoring, and has **never been restore-tested**. The recovery story for every dangerous delete in §II.2 is "restore last night's backup" — i.e. lose up to 24 hours of payroll and attendance for every tenant on the box. |
| **Schema drift makes every install a special case** | 59 runtime-created tables and column types decided by click history mean a support engineer cannot reason from source about what a customer's database contains. |
| **Support tickets are generated by design** | ESS and the leave module report **different balances** for the same employee (`ent − used` vs `ent − used − pending`). ESS shows six leave rows all labelled "Leave" because the type chain cannot resolve. Carry-forward is configured, visible and inert. A 17-template WhatsApp library is configurable, testable, markable-approved — **and wired to no sender**. Push tokens are collected and there is **no sender at all**. There is **no SMS provider adapter**. |

### II.4.3 The inability to ship a statutory change

This is the single most expensive structural cost, and it is worth stating as a scenario.

**A Professional Tax slab changes in one state.**

1. The slab tables are **16 built-in states and 10 PT-free states compiled as PHP `private const`**
   in `AppDataController.php:1852-1871`. Editing them is an application change.
2. There are **four further copies of the statutory rates**: the SPA's own JavaScript
   (`AppController.php:11161`), `KbController.php:211` and `CalcGuideController.php:88, 126, 136,
   161` as hard-coded prose, and `tests/manual/pt_extract.php` — **a hand-copied snapshot kept in
   sync by a docblock instruction.** All must be updated or they will contradict the engine.
3. The change ships as an **application release**. Every on-premise customer must run the updater.
4. That updater has **no code signing**, and its integrity check is skipped entirely when the
   server omits a hash.
5. It runs `migrate --force` with **no database backup and no database rollback**.
6. Its health check is `artisan --version`.
7. Its rollback restores **code only**, after the migrations have already run.
8. Release upload through the shipped web server **cannot work** — the controller validates a
   500 MB package while the shipped nginx config caps the body at 20 MB; the controller documents
   an SFTP workaround at length, so the primary path is decorative.
9. `version.json` says `2026.6.2` and `config/smartprs.php:43` says `2026.6.1`. The release
   pipeline reads one and the update check reads the other, so **a release built from the config
   value registers as a version every client already reports having, and the update never reaches
   anyone.**
10. And the customer's own in-product explanation of their payslip still shows the old rate,
    because it is prose in a controller.

**Reaching 200 on-premise customers with a slab change is 200 manual application upgrades, each
one an unsigned code push with no database rollback.** That is not a maintenance model; it is a
ceiling on how many on-prem customers Ametecs can responsibly carry.

Compounding it: **there is no statutory obligation calendar at all.** Twelve scheduled tasks and
**not one** concerns a filing due date. The only statutory dates in the entire codebase are four
hard-coded 24Q dates inside a function that fabricates its own filing history. A payroll product
for Indian employers has no PF / ESI / PT / TDS remittance calendar.

### II.4.4 Fork risk

The conventional fork risk — three edition branches diverging — is not yet real, because
**`Edition::level()` returns a literal `3` and all three editions ship byte-identical** (§I.7.2).
The real fork risk is worse and is already realised in two forms:

- **The estate is already forked by schema.** Every on-premise install's database is a function of
  which screens were opened, in what order, before which deploy. Eight tables have competing
  definitions; column types are decided by whichever path ran first. There is no single "the
  SmartPRS 2 schema" to write a migration against.
- **The only way to give one customer a different rule is a code change**, and the only way to
  ship it is a release to that customer. The pressure to branch per customer is structural, and
  the moment it is yielded to once, the statutory-update problem above multiplies by the number of
  branches.

There is also a **key-loss fork**: `smartprs:make-license-keys --force` overwrites the licence
signing private key in place with **no backup and no confirmation prompt**, and `config/backup.php`
**excludes the directory it lives in**. Losing that key means no `.lic` can be issued or renewed
for any existing customer without rotating the embedded public key and rebuilding and
redistributing every edition package.

### II.4.5 The sales ceiling

| Ceiling | Cause |
|---|---|
| **Revenue leaks on every deal** | Archived employees bill while the product says they do not (#15). The seat cap is bypassed at the two bulk-creation doors and **fails open** on any error (#16). Off-roll agents — potentially the majority of a collections field force — are never counted. |
| **Three price points, one product** | An L1 customer pays the lowest price and receives L3. A sales-integrity and revenue-recognition exposure that also makes tiered pricing unsellable to anyone who checks. |
| **A sold lever that does not exist** | Plan-based module gating is dead in two independent places and is sold on the landing page; the seeder's own docblock calls it "parked". |
| **No trial, no auto-renewal** | `subscriptions.status` accepts `trialing`, `tenants.trial_ends_at` exists, and **nothing ever sets either**. `billing:renew` exists and is deliberately unscheduled — because scheduling it would extend every subscription on invoice *creation* rather than payment, making the lock-out mechanism inert. Both are standard SaaS expectations and one is already claimed on the landing page. |
| **Cannot pass an enterprise or bank security review** | A default administrator credential created by a documented setup step and published in the README; a nightly task that re-creates known-password admins; identity documents on an unauthenticated public path with no MIME validation; unsigned updates that are RCE-by-design from a compromised release server; **no MFA, no account lockout, no password complexity, and a minimum length of 6 for the platform's most privileged accounts** — `123456` is a valid Super Admin password; **no audit of any privilege change** (only successful login is recorded); SMTP passwords in recoverable plaintext; and a cross-tenant support role with the widest scope in the product and zero checks. |
| **Cannot answer a DPDP obligation** | No hard erasure, no anonymisation, no retention policy of any kind, no purge job, no workspace-level export — while the published Terms, Privacy and Data Protection policies promise a 90-day retention and an export. |
| **Cannot sell the core outside collections** | The vertical is thin (§I.6.3) but it is **baked into a 460-line config array in one controller** and into hard-coded compliance logic. Selling to a security agency or a staffing firm today means either shipping them the collections vocabulary or forking. |
| **Cannot scale a large customer** | `/app/data` is modelled to exhaust PHP's memory limit at ~5,000 employees. There is **no pagination anywhere in the application** (0 occurrences), 308 unbounded `->get()` calls, and every report, export, PDF and payroll run happens inside the HTTP request. |
| **Cannot demonstrate a control to an auditor** | Both four-eyes controls are satisfiable by one person (#26). The audit chain is broken by exactly the events worth auditing (#25). The compliance report contains items it did not check (#27). |

---

## II.5 The commercial opportunity the rebuild unlocks

Each opportunity below is stated with the specific spine mechanism that creates it and the
SmartPRS 2 constraint it removes.

### II.5.1 Configuration-led implementation

**The mechanism:** spine **S5** (SPX), **S6** (rule engine with a visual builder, a simulator, a
conflict detector, an impact preview and a regression harness), **S7** (formula engine), **S8**
(one workflow engine, zero module-specific approval code), **S9** (entity schema engine).

**The test:** the governing brief's Section 127 standard — *"3 late arrivals over 10 minutes =
half day" must not require code.* With S5.5's `agg.count(event, window, predicate)` and S6's
`APPLY_DAY_PENALTY(fraction)` action, that rule is three fields in a rule builder.

**What it changes commercially:**

- **Implementation moves from a release to a configuration session.** Every one of the ~120
  catalogued literals in F1 becomes a tenant-scoped, effective-dated artefact. The twelve routine
  asks in §II.4.1 that currently cost a code change cost a form.
- **Implementation becomes delegable.** A configuration engine with a simulator, an impact preview
  and a publish-time conflict detector can be operated by an implementation consultant, a partner
  or the customer's own HR lead. Today only an engineer can vary the product, which caps how many
  customers can be onboarded in parallel at the size of the engineering team.
- **Custom fields stop being a release.** S9 turns "add a LinkedIn Profile field" from 6 sites in
  4 files plus a migration into a row in `field_definitions` — served identically across the
  directory, the employee edit form, self-onboarding, bulk import, bulk export, ESS, MSS, mobile,
  the public API, recruitment conversion, letters, reports and the payslip.
- **A new biometric vendor stops being a multi-file change.** S10.1's `DeviceAdapter` contract —
  and the declarative **connector recipe** for REST/SOAP/SQL/CSV/SFTP sources — means many new
  integrations need no PHP at all, against today's five-place duplicated dispatch.

### II.5.2 Vertical module packs — the same core, several industries

**The mechanism:** the finding in Part I §I.6.3 that **the collections vertical is thin**. Five of
the six registries carrying the industry story are rows in a config array; there are only three
genuinely vertical *engines* (the audit pack, the DRA money gate, off-roll payouts).

**What it changes commercially:**

- If the core provides a **Credential** object with expiry and alert policy, a **Policy
  Acknowledgement** object with versioning, a **Case/Complaint** register, a **Worker** abstraction
  admitting non-employees, and a rule engine that can gate a money event on a compliance state,
  then **the entire collections pack reduces to configuration plus three engines.**
- The same core then sells to **security agencies** (licence and training-certificate expiry,
  guard deployment, client-site billing), **staffing and contract labour** (worker abstraction,
  per-client billing, statutory registers), **logistics and last-mile delivery** (driving licence
  expiry, per-trip payouts, non-employee riders) and **facilities management** — with a new
  module pack, not a rewrite.
- Each pack is a **priced SKU** rather than a fork. S2's tooling-enforced module boundaries and a
  single module registry (D-I-01) make "installable, licensable and hideable as data" a property
  of the platform rather than a promise.
- The collections pack itself becomes **defensible IP** rather than incidental code: a versioned,
  configurable RBI audit item set with an issued-report register and an HMAC-keyed verification
  endpoint is something a client bank can be shown, and something a competitor cannot copy from a
  screenshot.

### II.5.3 On-prem parity and the end of the upgrade ceiling

**The mechanism:** spine **S18** (one artefact; SaaS, private cloud and on-premise differ only in
infrastructure configuration; no `if (on_prem)` in a domain path) plus **S12** (statutory content
as a separately versioned, cryptographically signed rule pack).

**What it changes commercially:**

- **A statutory change ships as content, not as a release.** A PT slab change becomes a signed
  pack: download → verify signature → validate → **preview the diff and its impact** →
  administrator accepts (or an auto-accept policy applies) → activates on its effective date.
  **Reaching 200 on-premise customers stops being 200 application upgrades.** This single change
  removes the ceiling in §II.4.3.
- **Application updates become safe.** Signed with an asymmetric key whose private half never
  leaves Ametecs; verify → compatibility check → **database backup** → code backup → maintenance
  mode → migrate → update code → rebuild caches → restart workers → health check → exit, with
  **database and code rollback** on failure, all audited. That is the difference between an
  on-prem estate Ametecs can grow and one it must ration.
- **The installer refuses to complete without a working scheduler and queue worker**, and the
  health check fails loudly if either stops — closing the failure mode that has already cost a
  paying customer their workspace.
- **Backups gain a tested restore path whose last successful test date is visible in the UI.** For
  an on-premise HR and payroll product, that is a sales asset, not just an engineering one.
- **Enterprise and bank security reviews become passable**: no default credential in any seeder,
  installer or release; MFA mandatory for privileged roles; private documents served through
  authorised, signed, expiring URLs; a hash-chained audit ledger with a daily signed anchor; and
  DPDP-honourable retention, erasure and export.

### II.5.4 Rule-pack subscription revenue

**The mechanism:** **S12** makes the India Statutory Rule Pack a **third artefact class** —
neither application code nor tenant configuration — published, versioned and signed by Ametecs,
carrying calculation, eligibility, thresholds, rates, slabs, forms, **due dates**, report mappings
and a `source_reference` for every value.

**What it changes commercially:**

- It creates a **recurring, defensible revenue line on the on-premise estate**, which today
  produces nothing beyond a manually invoiced AMC whose own percentage column is read by no
  calculation. A perpetual-licence customer who wants to stay statutorily current has a reason to
  subscribe, and Ametecs has something to sell them every year that is genuinely valuable and
  genuinely costly to produce.
- It **turns compliance work into a product.** The labour of tracking Indian statutory change is
  already being done — badly, as PHP constants in four places. Packaging it as signed content
  monetises work Ametecs is already paying for, and does it once for every customer instead of
  once per customer per release.
- It **closes the compliance-calendar gap** in the same artefact. Due dates ship with the pack, so
  a customer gets PF / ESI / PT / TDS remittance reminders as content — the capability §II.4.3
  shows is entirely absent today.
- It **makes the layering sellable**: central baseline + state rule + establishment applicability
  + company enhanced policy, with the configuration validator blocking or warning when a company
  policy would fall below the statutory minimum. That is a concrete answer to "how do I know my
  payroll is compliant?" that no configuration screen alone can give.
- With **D-I-08** answered (a named compliance owner who signs a pack before it ships), the pack
  also becomes an **accountability artefact** — the `source_reference` field exists precisely so
  every value is traceable to its authority.

### II.5.5 Recovered revenue and restored sales integrity

Not new opportunity, but money currently leaking:

| Item | Effect |
|---|---|
| Seat counting with one definition, one implementation, enforced at every creation door by the persistence layer | Closes the archived-employee billing defect (#15), the two bypassed doors and the fail-open (#16). |
| Off-roll workers on the core Worker abstraction | Makes them **billable by construction** and **compliance-scanned by construction** — simultaneously the largest revenue question and the largest compliance-promise gap in the product (D-I-05). |
| A real module registry driving the licence, the plan gate and the price list | Makes edition tiering and plan gating **real capabilities** rather than dead constants, and makes the licence agreement's "module catalogue at the time of sale" a thing that exists. |
| Commercial constants as configuration | GST rate, company fee, cycle discounts, grace days and the seat definition become deal levers instead of release blockers. |
| Trial and auto-renewal as first-class states | Two standard SaaS expectations, one already claimed on the landing page. |

---

## II.6 Requirements arising from this part

Numbered and testable. `R-II-0xx` are **remediation-now** items for SmartPRS 2; `R-II-1xx` are
**architectural** requirements binding on SmartPRS 3 that arise from a failure catalogued here and
are not already stated in the spine.

### Remediate in SmartPRS 2, independently of the rebuild

| ID | Requirement | Traces to |
|---|---|---|
| R-II-001 | The nightly `demo:reset` schedule entry must be gated on `APP_ENV` **and** `SMARTPRS_DEMO_DATA`, and every deployed install must be audited for `demo-admin@`, `demo-hr@` and `demo-field@smartprs.in`. | §II.2 #1 |
| R-II-002 | `OnPremWorkspaceSeeder` must not create a credential. The entire deployed base must be audited for `admin@smartprs.local`, and `README.md` must stop publishing logins. | §II.2 #2 |
| R-II-003 | `ensurePayrollHistory()` must be removed from the `/app/data` path, and a detection-and-cleanup script must be produced for existing installs. | §II.2 #3 |
| R-II-004 | `LeaveController::apply()` must write `type_id`; existing rows must be back-filled from `type_name`; a payroll reconciliation must be produced per customer. | §II.2 #4 |
| R-II-005 | `loanEmiDue()` must read only columns that exist, and `commissions.status` must be widened. | §II.2 #5, #6 |
| R-II-006 | Every upload path must write to the **private** disk, with magic-byte validation; a deny rule for `/storage` must be version-controlled; existing public files must be relocated. | §II.2 #17 |
| R-II-007 | Update packages must be signed with the existing RSA pair and verification must be **mandatory**; a database backup step must precede `migrate --force`. | §II.2 #18 |
| R-II-008 | `/iclock/*` must require a device credential, must not auto-enable unknown serials, and must not execute DDL. | §II.2 #19 |
| R-II-009 | `POST /app/state` must be role-gated, size-limited and audited. | §II.2 #20 |
| R-II-010 | The 61 unguarded authenticated routes must each be given an explicit server-side check, starting with the statutory PDFs, the request lists, the salary sheet and the master lists. | §II.2 #21 |
| R-II-011 | The two `attendance:dedupe` calls must be removed from migrations. | §II.2 #23 |
| R-II-012 | `ETimeOfficeService::matchEmployee()` must try `device_user_id` before `emp_code`. | §II.2 #24 |
| R-II-013 | The four direct `activity_logs` inserts must route through `Audit::record`. | §II.2 #25 |
| R-II-014 | The fabricated 24Q filing-history screen must be removed, and the hard-coded "Disciplinary actions" audit item and the degenerating Code-of-Conduct check must be corrected or removed from the audit pack. | §II.2 #8, #27 |
| R-II-015 | `DummyDataSeeder` must carry an unconditional production guard and must never fall back to the lowest-id tenant. | §II.2 #29 |
| R-II-016 | `SMARTPRS_WA_SEND_PASSWORD` must default to `false`, and `wa_log.body_values` must not retain credential-class variables. | §II.2 #30 |
| R-II-017 | Seat counting must exclude `archived_at`, must be enforced at all five creation doors, and must **fail closed** with an explicit operator message. | §II.2 #15, #16 |
| R-II-018 | Every legal-numbered document series must have a database unique constraint and transactional issuance. | §II.2 #31 |

### Architectural requirements arising from this part

| ID | Requirement | Traces to |
|---|---|---|
| R-II-101 | No statutory logic anywhere may inspect a salary component's **name**. Every earning declares, by flag, whether it enters the PF wage, the ESI wage, the PT gross, the bonus wage, the gratuity wage, the OT wage, the minimum-wage comparison and the taxable base. | F1; `SalaryApprovalController.php:294-299`; `esiPeriodLock() :2036` |
| R-II-102 | A compliance check must **never degrade to a weaker check when data is missing**; it must return `unknown`. Absence of evidence must be treated consistently across all checks. | F4; `ComplianceController.php:596-611`, `scoreFor() :289-330` |
| R-II-103 | Every issued compliance report must be **persisted** (subject, timestamp, verdict, score, item states, rule versions) with an HMAC-keyed reference and a verification endpoint. No audit item may be hard-coded to pass. | F4; `ComplianceController.php:625, 643-644` |
| R-II-104 | Every gate that allows on error must **log, count and alert**. A commercial gate may fail open; an authorisation gate must fail closed. Both postures must be declared explicitly at each gate and asserted by a fault-injection test. | F5, F6; Part I R-I-016 |
| R-II-105 | A dropped or unmapped field on any write or import path must be an **error surfaced to the user**, never a silent discard. The `safeRow()` pattern must not exist. | F6; `ApprovalService.php:262-266` |
| R-II-106 | Every importer must report unmapped columns, dropped rows and generated identifiers back to the operator before commit, and must generate identifiers under a database uniqueness constraint. | F6, F8; `EmployeeImportService.php:159-176, 376, 603-608` |
| R-II-107 | There must be a **statutory obligation calendar**: obligation, jurisdiction, period, due-date rule, owner, status, evidence and reminders — shipped as rule-pack content, not as application code. | §II.4.3; `routes/console.php` complete schedule |
| R-II-108 | There must be **one version number in one place**, and the release pipeline, the update check and the licence path must all read it. | §II.4.3; `version.json` vs `config/smartprs.php:43` |
| R-II-109 | Every entity must carry a declared **retention class**, with scheduled, audited archival, anonymisation and purge — and a workspace-level export and a hard-erasure path must exist, to honour the published policies and DPDP obligations. | §II.4.5; `M` §6.5 |
| R-II-110 | Identity must never be resolved by **name**. Sixteen independent copies of a name-fallback "which employee am I?" resolver exist today; SmartPRS 3 must have exactly one identity resolution, by id. | F7; `D` Q6 |
| R-II-111 | Every collection endpoint must be paginated and every long-running operation must be a job. No unbounded `->get()` may exist on a tenant-scoped table. | F8; `M` §6.3 |
| R-II-112 | The module registry, the permission model, the plan gate, the edition gate, the licence and the price list must all read from **one** data-held module catalogue. | §I.4.1 D-I-01; §II.4.5 |

---

## II.7 Open items for the Product Owner

| # | Question | What is known | Recommendation |
|---|---|---|---|
| **D-II-01** | **How many deployed installs carry `admin@smartprs.local` / `password`, and how many have run the scheduler long enough to have `demo-admin@smartprs.in`?** | Both are created by ungated code paths; one is a documented setup step, one is a nightly task. | **Audit the entire deployed base before anything else in this blueprint.** This is the highest-urgency item in Parts I and II combined. |
| **D-II-02** | **Is `SMARTPRS_TEAM_PIN` a live bypass?** | Config ships the literal default `'ametecs'` and documents it as unlocking unrestricted demos with no write-guard and no hidden screens; a controller comment says the PIN was removed at rev185. | Confirm no reader exists in the shipped build. **Security-blocking.** |
| **D-II-03** | **Is the reported shared licence-signing secret present in every client build?** | The `PKG:` packaging tree is absent from the analysed source. `config/smartprs.php:69` deliberately has no default, so the in-repo half of the fix is present; the packaging half is unverified. | Inspect the packaging tree. If confirmed, every perpetual licence already shipped is retroactively unenforceable the moment the offline path is re-enabled. |
| **D-II-04** | **Who owns the remediation programme, and does it run in parallel with the rebuild or before it?** | §II.2 lists ~25 remediate-now items, several of them one-line, at least four of them CRITICAL security. | Run it in parallel, with a named owner and a fixed end date. The rebuild must not become a reason to defer #1, #2 and #17. |
| **D-II-05** | **What is the acceptable cutover model for the on-premise estate?** | No production schema has ever been captured; eight tables have competing definitions; column types are decided by click history. | Decide before the migration parts are written: per-customer schema discovery and a bespoke migration, or an export-and-reimport through a canonical interchange format. **Recommendation: the latter**, because it does not require the source schema to be knowable. |
| **D-II-06** | **Will Ametecs accept a hard stop on new SmartPRS 2 features during the rebuild?** | Every feature added to SmartPRS 2 during the rebuild is a feature that must be re-specified, re-built and re-tested in SmartPRS 3, and it lands in an architecture this document argues cannot safely absorb it. | Yes, with the sole exception of the §II.2 remediation list and statutory changes that cannot wait. |

---

*End of Part II.*
