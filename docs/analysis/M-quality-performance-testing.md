# AS-IS ANALYSIS — QUALITY POSTURE: TESTING, CI, STATIC ANALYSIS, PERFORMANCE, CONCURRENCY, CODE QUALITY, DATA LIFECYCLE, DELETE/ARCHIVE

---

## 0. Scope and evidence base

### 0.1 Reference documentation read

| Document | Line range | Subject |
|---|---|---|
| `COMPLETE_PROJECT_BUSINESS_AND_TECHNICAL_DOCUMENTATION.md` | 54726–57343 | §20 Testing, QA posture and the regression-test matrix (read in full, incl. §5 untested critical surface 55852–56291 and §7 regression matrix 56492–56780) |
| same | 53684–53984 | §3 Data lifecycle and retention; §4 Delete and archive behaviour |
| same | 53984–54141 | §5 Performance analysis |
| same | 54141–54622 | §6 Code quality and architecture review incl. §6.9 Concurrency and race conditions |
| `SECURITY_AND_TECHNICAL_DEBT_REPORT.md` | 5672–5895 | §11 Performance findings (P1–P13) |
| same | 5895–5935 | §12 Concurrency and race conditions (C1–C23) |

### 0.2 Source read completely (under `/home/claude/prs2/`)

| File | Lines | Notes |
|---|---:|---|
| `phpunit.xml` | 39 | complete |
| `composer.json` | 77 | complete |
| `tests/TestCase.php` | 10 | complete |
| `tests/Pest.php` | 84 | complete |
| `tests/Unit/AbsenceServiceTest.php` | 40 | complete |
| `tests/Unit/AttendanceCorrectionServiceTest.php` | 24 | complete |
| `tests/Unit/EmployeeImportServiceTest.php` | 34 | complete |
| `tests/Unit/GreetingServiceTest.php` | 35 | complete |
| `tests/Unit/PayrollMathTest.php` | 104 | complete |
| `tests/Unit/PeriodResolverTest.php` | 82 | complete |
| `tests/Unit/ProbationServiceTest.php` | 43 | complete |
| `tests/Unit/StatutoryConfigTest.php` | 127 | complete |
| `tests/Feature/AuthAccessTest.php` | 21 | complete |
| `tests/Feature/BillingTest.php` | 156 | complete |
| `tests/Feature/DeviceModeTest.php` | 234 | complete |
| `tests/Feature/IclockTenantHotfixTest.php` | 298 | complete |
| `tests/Feature/MasterCrudTest.php` | 96 | complete |
| `tests/Feature/PayrollTest.php` | 47 | complete |
| `tests/Feature/ReportTest.php` | 27 | complete |
| `tests/Feature/SbbIngestTest.php` | 481 | complete |
| `tests/Feature/SmarteptWebhookTest.php` | 402 | complete |
| `tests/manual/check_pt.php` | 89 | read |
| `tests/manual/check_statutory_config.php` | 142 | read |
| `tests/manual/pt_extract.php` | 155 | read |
| **`database/factories/UserFactory.php`** | — | **DOES NOT EXIST. `database/factories/` does not exist at all.** See §8 D-1. |

### 0.3 Source read in the slices the questions required

`app/Http/Controllers/PayrollGenController.php` (:202–250, :322–360, :670–960, :1042–1600, :2330–2846);
`app/Http/Controllers/AppDataController.php` (:1–120, :247–470, :684–690, :2011–2145, :2340–2441, :2816–2822);
`app/Http/Controllers/AppController.php` (:1–60 + structural scan);
`app/Http/Controllers/AppStateController.php` (whole, 60 lines);
`app/Http/Controllers/BillingController.php` (:79–99, :370–430, :986–1030);
`app/Http/Controllers/SalaryApprovalController.php` (:29–160, :334–520);
`app/Http/Controllers/LeaveController.php` (:180–440);
`app/Http/Controllers/MasterController.php` (:1150–1224);
`app/Http/Controllers/RequestController.php` (:1236–1266);
`app/Http/Controllers/ApprovalService.php` (:263–381);
`app/Http/Controllers/AttendanceReportController.php` (:100–135, :495–515);
`app/Services/Audit.php` (whole); `app/Services/SubscriptionService.php` (whole, 272 lines);
`app/Services/PayrollLock.php` (:1–80); `app/Services/ETimeOfficeService.php` (:480–545, :620–680);
`app/Console/Commands/DedupeAttendance.php` (whole); `app/Console/Commands/DemoReset.php` (:1–175);
`database/seeders/DummyDataSeeder.php` (:44–100); `routes/console.php` (:90–270);
`routes/web.php` (:416–420); `database/migrations/2026_01_01_000001_create_companies_table.php` (:55–80);
`database/migrations/2026_01_01_000300_create_attendances_table.php` (:37–62, :184–188);
`database/migrations/2026_08_16_000200_extend_attendance_logs_for_sbb.php` (:130–160).

### 0.4 Whole-codebase measurements taken (all reproducible)

| Measure | Value | Command |
|---|---:|---|
| Total LOC (`app` + `resources` + `routes` + `database` + `config` + `public`) | **81,592** | `find … \| xargs cat \| wc -l` |
| `app/` LOC | 65,933 | |
| Controllers / Services / Commands / Middleware / Models | 84 / 32 / 18 / 6 / 11 | |
| Route definitions | 422 | `grep -ohE "Route::(get\|post\|…)\(" routes/` |
| Migrations | 49 | |
| Test cases declared | **132** (137 execute — one 6-value dataset) | `grep -cE "^(it\|test)\("` |
| Assertion calls in tests | **446** | `grep -oE "expect\(\|assert[A-Za-z]+\("` |
| `DB::transaction` / `beginTransaction` | **4 sites, 4 files** | |
| `lockForUpdate` / `sharedLock` / `Cache::lock` / `->lock(` | **0** | |
| `->paginate(` / `->simplePaginate(` | **0** | |
| `->chunk(` / `chunkById` / `cursor` / `lazy` | **1** (`PunchIngestService.php:242`) | |
| `->get(` calls | **308** | |
| `Schema::hasTable` / `hasColumn` | **738** | |
| `Schema::create` / `Schema::table` / `dropIfExists` **inside `app/`** | **156** | runtime DDL |
| `whereRaw('LOWER…` | 46 | index-defeating |
| `->whereDate(` | 15 | index-defeating |
| `DB::table(...)` inside a `foreach` body (N+1 candidates) | **103 sites, 25 files** | AST-ish scan, deduped |
| `->delete()` / `::destroy(` / `truncate()` sites | 43 in `app/` (+4 in migrations/seeders) | |
| `forceDelete()` / `truncate()` | **0** | |
| Models using `SoftDeletes` | **2 of 11** (`Employee`, `Department`) | |

---

## 1. Business capability inventory

### 1.1 Automated testing — Q1 ANSWERED

**What exists.** A Pest 3 suite of **132 declared cases (137 executed) across 17 files** carrying
**446 assertion calls**, run only when a developer chooses to. It is deep on three recent
surfaces (biometric punch ingest, SaaS billing, statutory-rate and pay-period arithmetic) and
absent everywhere else.

| File | Cases | Assertion calls | Subject |
|---|---:|---:|---|
| `tests/Feature/SbbIngestTest.php` | 23 | 119 | SBB authenticated JSON punch ingest — auth, idempotency, quarantine/replay, timezone, tenant isolation, batch verdicts |
| `tests/Feature/SmarteptWebhookTest.php` | 20 | 65 | SmartEPT HMAC webhook — signature, tenancy from receiver, TZ conversion, derived idempotency, `attendance.daily` expansion |
| `tests/Feature/IclockTenantHotfixTest.php` | 11 | 49 | `/iclock` ADMS push — cross-tenant contamination fix, quarantine, promotion, single-tenant regression |
| `tests/Feature/DeviceModeTest.php` | 9 | 13 | 1-device alternating IN/OUT vs 2-device machine-ID direction |
| `tests/Feature/BillingTest.php` | 8 | 36 | subscription pricing, invoice generate/pay, gateway secret encryption, Razorpay webhook sig + sequential replay, invoice PDF, renewals |
| `tests/Feature/MasterCrudTest.php` | 7 | 21 | master CRUD, one role guard, letter template merge → PDF |
| `tests/Feature/AuthAccessTest.php` | 3 | 3 | guest redirect, landing 200, `/app/data` 200 |
| `tests/Feature/PayrollTest.php` | 3 | 9 | a run + a payslip per employee; appears in approval; 409 on re-generate |
| `tests/Feature/ReportTest.php` | 2 | 5 | preview JSON shape, CSV content-type |
| `tests/Unit/PeriodResolverTest.php` | 9 | 34 | `PeriodResolver` — **a service nothing in payroll calls** (§6 DC-1) |
| `tests/Unit/StatutoryConfigTest.php` | 8 | 17 | `StatutoryConfig` merge rules, via reflection with the DB deliberately unreachable |
| `tests/Unit/PayrollMathTest.php` | 7 | 17 | `computeSlip` at 2 CTCs + PT by state/month/gender |
| `tests/Unit/ProbationServiceTest.php` | 6 | 13 | probation flags + end-date derivation |
| `tests/Unit/AbsenceServiceTest.php` | 5 | 5 | `isAbsent()` at 4 inputs |
| `tests/Unit/EmployeeImportServiceTest.php` | 4 | 17 | header auto-map, Yes/No, day-first dates |
| `tests/Unit/GreetingServiceTest.php` | 4 | 12 | template token substitution |
| `tests/Unit/AttendanceCorrectionServiceTest.php` | 3 | 11 | 2 constants + `hm()` / `ymd()` normalisers |

**Fraction of the business-critical surface covered.** 18 distinct HTTP routes are exercised out
of **422 route definitions** — **4.3% of the routed surface**. Of 84 controllers, tests reference
**2** (`AppDataController` for one static math function, `BillingController`); of 32 services they
reference **9**. Weighted by business risk the figure is worse: **not one rupee of a generated
payslip is asserted anywhere.** `PayrollTest` #1 asserts `count === 2` and would pass if both
payslips were ₹0.

**Modules with ZERO tests.** Mapping the 24 product modules onto controllers:

| # | Module | Test coverage |
|---|---|---|
| 1 | Employee master / Directory | **ZERO** (only 4 pure import-helper functions) |
| 2 | Attendance capture (device paths) | **COVERED** — the strongest area (43 cases) |
| 3 | Attendance report / status derivation | **ZERO** |
| 4 | Attendance corrections | **ZERO** behaviourally (2 pure normalisers only) |
| 5 | Shifts / rosters (`ShiftResolver`) | **ZERO** |
| 6 | Leave (apply, balance, approve, LOP) | **ZERO** |
| 7 | Payroll generation | smoke only — **no monetary assertion** |
| 8 | Salary approval / four-eyes / bank pack | **ZERO** (one `listRuns` row count) |
| 9 | Statutory PF/ESI/PT/TDS/LWF | partial — PT and the CTC split only |
| 10 | Statutory configuration engine | **COVERED** in-memory; the DB path is **ZERO** |
| 11 | Requests (expenses, advances, loans, clawbacks, increments, transfers, bonus) | **ZERO** |
| 12 | Commissions / incentive schemes / points | **ZERO** |
| 13 | Recruitment / hiring drives / interviews | **ZERO** |
| 14 | Onboarding + Self-onboarding portal | **ZERO** |
| 15 | Offboarding / exits / archive | **ZERO** |
| 16 | Documents | **ZERO** |
| 17 | Letters / templates | one PDF smoke case |
| 18 | Compliance (DRA/PCC), Code of Conduct | **ZERO** |
| 19 | Performance / KRA / tests-quizzes / KB | **ZERO** |
| 20 | Reports & exports | 2 smoke cases (employees dataset only) |
| 21 | Notifications (mail/WhatsApp/SMS) | **ZERO** |
| 22 | SaaS billing / subscriptions / coupons | **COVERED** (8 cases) |
| 23 | Licensing (on-prem `.lic`, seats, node lock) + self-update | **ZERO** |
| 24 | Auth / RBAC / tenancy outside ingest | **near-ZERO** (3 smoke + 2 authz assertions) |

**16 of 24 modules have literally zero tests.** A further 4 have smoke-only coverage.

### 1.2 Continuous integration — Q2 ANSWERED: **NO**

Checked at the repository root and below. **Absent:** `.github/`, `.gitlab-ci.yml`, `Jenkinsfile`,
`.circleci/`, `azure-pipelines.yml`, `bitbucket-pipelines.yml`, `.travis.yml`, `Makefile`,
`Taskfile.yml`, `.husky/`, `captainhook.json`, `grumphp.yml`, `docker-compose.yml`. There is not
even a `.gitignore` or `.gitattributes` in this copy. Nothing runs the suite automatically.
`README.md:74` instructs a human to run Pint, PHPStan and Pest before commit; **that instruction
is enforced by nothing.**

### 1.3 Static analysis — Q2 ANSWERED: **NO**

`composer.json` declares `larastan/larastan ^2.9` and `laravel/pint ^1.16` in `require-dev` and
aliases `"analyse": "phpstan analyse"`, `"lint": "pint"`. **No `phpstan.neon`, `phpstan.neon.dist`,
`phpstan.dist.neon`, `pint.json`, `psalm.xml`, `rector.php` or `.php-cs-fixer.php` exists.**
`composer analyse` fails immediately — PHPStan with no config and no path argument aborts. Pint
runs with the bare Laravel preset, unpinned. Mockery `^1.6` is declared and **never used**
(no `Mockery`, `mock(`, `spy(` anywhere in `tests/`). Faker `^1.23` is declared and has **no
factory to serve** — `database/factories/` does not exist.

### 1.4 Coverage measurement — Q2 ANSWERED: **NO**

`phpunit.xml` declares `<source><include><directory>app</directory></include></source>` — which
declares the *scope* of coverage and nothing more. There is **no `<coverage>` element, no report
writer, no threshold, no `failOnWarning`/`failOnRisky`/`failOnDeprecation`**, no Xdebug/PCOV
configuration, no `coverage.xml`, no `build/`, no `.phpunit.cache`. **No coverage figure has ever
been produced for this codebase.** Every coverage number in the reference documentation is
reasoned, not measured, and says so.

### 1.5 Test database configuration — Q2 ANSWERED: **YES, and it is the wrong engine**

`phpunit.xml:33-34` sets `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`. Production is MySQL
(`sbb-schema-status.txt`). Feature tests get `RefreshDatabase` (`tests/Pest.php:12`); Unit tests
get no DB trait but still boot the framework. A 12-line comment block (`phpunit.xml:16-26`)
documents a MySQL escape hatch that has apparently never been used.

**Consequences, in order of danger:**

1. **SQLite has no `ENUM` and no column-length enforcement.** Seven migrations carry MySQL-only
   `ALTER … MODIFY` DDL behind driver guards, so they *run and do nothing* on SQLite. The most
   important is `2026_06_24_000100_widen_payroll_runs_status.php:25`, which widens
   `payroll_runs.status` from `ENUM('draft','locked','approved','paid')` to `VARCHAR(30)` **so
   that `hr_approved` can be stored at all**. On SQLite the whole approval state machine appears
   to work because SQLite accepts any string. On a MySQL install that missed that migration it
   throws `Data truncated`. **The test suite structurally cannot reproduce the production defect
   class that migration exists to fix.**
2. **MySQL-only SQL silently no-ops or throws.** `PayrollLock::isPeriodFinalised()` uses
   `whereRaw("DATE_FORMAT(pay_date, '%Y-%m') = ?")` (`PayrollLock.php:64`) — SQLite has no
   `DATE_FORMAT`, so the query throws, the `catch` returns `false` (fail-open), and the payroll
   period-lock guard is **untestable and always open under test**.
3. **Runtime-created tables.** 156 `Schema::create`/`Schema::table` calls live inside `app/`.
   Under `RefreshDatabase` those tables do not exist until the first request creates them, so a
   feature test exercises the runtime-DDL path, never the migrated one. `tests/Pest.php:71-77`
   has to add `plans.seat_max` itself with `Schema::table()` because no migration creates it —
   **the fixture layer papers over a real production schema gap.**
4. **`NULL` never collides in a unique index.** `IclockTenantHotfixTest` case `6c` exploits this
   deliberately and correctly, but it also means every unique-index guarantee involving a nullable
   `tenant_id` is untested for the non-null MySQL case.

### 1.6 The manual verification scripts

`tests/manual/` holds three PHP scripts that run **outside Laravel with no database**:
`check_statutory_config.php` (142 lines) stubs `App\Support\SchemaHelper`,
`Illuminate\Support\Facades\{DB,Log,Schema}` and `App\Services\EffectiveDated`, then `require`s the
real `StatutoryConfig.php` and drives its private methods by reflection — a genuinely clever
zero-dependency harness. `check_pt.php` + `pt_extract.php` (89 + 155 lines) are **a hand-copied
snapshot of the PT constants and `ptForGross()` lifted out of `AppDataController`**, with a
docblock instructing the developer to re-copy after every change. That is a **duplication of a
statutory table maintained by discipline alone** — the exact anti-pattern SmartPRS 3 must not
inherit. These three scripts are not in either testsuite and never run under `pest`.

### 1.7 The edition trap — the suite's colour depends on an untracked local file

`routes/web.php:22` calls `Edition::isOnPrem()` at route-file parse time, and `Edition::current()`
reads `base_path('.env')` line-by-line, deliberately bypassing `config()` and `env()` so that
`config:cache` cannot freeze it. **`phpunit.xml`'s `<env>` entries therefore cannot override the
edition.** On a developer `.env` carrying `SMARTPRS_EDITION=l1|l2|l3`, `/` redirects to `/app`
(so `AuthAccessTest` #2 fails) and the entire `/app/billing/*` route group is not registered
(so all 8 `BillingTest` cases 404). The suite's green/red state is a function of a file that is
not in the repository.

### 1.8 Data lifecycle and retention capability — **NOT IMPLEMENTED**

There is **no retention policy of any kind**. No `Model::prune()`, no `model:prune` in the
scheduler, no rotation on `activity_logs`, `notification_log`, `attendance_logs`, `wa_log` or
`mail_log`, no document deletion on employee exit, no right-to-erasure endpoint, no anonymisation
routine, no export-my-data endpoint, no tenant-deletion path. The only age-based deletion anywhere
is `spatie/laravel-backup`'s `backup:clean` (`routes/console.php:156`), which prunes **backup
archive files**, not live data. Full per-entity detail in §6.6.

### 1.9 Delete and archive capability

43 delete sites in `app/`, **zero** `forceDelete()` and **zero** `truncate()`. Only 2 of 11 models
use `SoftDeletes`, and the soft-delete scope does **not** apply to the ~308 `DB::table()` query
paths that carry the real business logic — every one of them must hand-write
`whereNull('deleted_at')`, and many do not. One archive mechanism exists (`employees.archived_at` +
a JSON snapshot in `employee_archives` after a 3-day `backup_due_at` grace) and it has **no
un-archive endpoint**. Ranked danger list in §6.7.

---

## 2. Data model

Tables this area touches, with provenance.

| Table | Provenance | Note |
|---|---|---|
| `payroll_runs` | migration `2026_01_01_000300_create_attendances_table.php:37-51` | `status` **ENUM** at create; widened to `VARCHAR(30)` by `2026_06_24_000100_widen_payroll_runs_status.php:25` **MySQL-only**. **No unique index on `(tenant_id, company_id, cycle_label)`** — only single-column indexes on `tenant_id` and `company_id`. This is the root cause of race C3. |
| `payslips` | migration `…000300:53-70` | `uuid` unique; `run_id` indexed. **No unique on `(run_id, employee_id)`.** `earnings`/`deductions` stored as JSON, so component-level reporting must parse every row in PHP. |
| `payslips.calc_note` | **RUNTIME** — `PayrollGenController.php:2552-2560` | added by `Schema::table` inside the payroll generate request |
| `payslips.line_status`, `line_remarks`, `held_by`, `reviewed_by`, `approved_by`, `disbursed_by`, `ack_name`, `ack_by`, `ack_ip`, `held_at`, `reviewed_at`, `approved_at`, `disbursed_at`, `ack_at`, `bank_filed_at` | **RUNTIME** — `SalaryApprovalController::ensureLineCols()` :75-98 | **the entire salary-approval state machine's storage is created by a web request** |
| `overtime.paid_run_id` | **RUNTIME** — `PayrollGenController.php:2566-2573` | |
| `loans.installments_paid` | **RUNTIME** — `PayrollGenController.php:2575-2583` | |
| `loan_recoveries` | **RUNTIME — whole table** `PayrollGenController.php:2585-2604` (`Schema::create`) | **no index on `run_id`, `loan_id` or `tenant_id`**; read on every regenerate |
| `clawbacks.recovered_run_id`, `recovered_amount`, `recovered_at` | **RUNTIME** — `PayrollGenController.php:2607-2617` | |
| `activity_logs.prev_hash`, `row_hash` | **RUNTIME** — `Audit::ensure()` :31-45 | `row_hash` gets an index; **`created_at` and `(tenant_id, entity, entity_id)` do not** |
| `commission_logs` | **RUNTIME — whole table** `ApprovalService::logCommission()` :277-290 | |
| `subscription_alerts` | **RUNTIME — whole table** `SubscriptionService::ensureAlertTable()` :201-216 | |
| `plans.seat_max` | **NO MIGRATION AT ALL** — added by the *test fixture* `tests/Pest.php:71-77` and read at `BillingController.php:118` | a production column that exists only if some runtime path created it |
| `employees` + ~30 columns | migration `2026_01_01_000100_create_employees_table.php`; **~28 more columns created at runtime** by `AppDataController::ensureEmployeeColumns()` :46-77 | called on **every** `/app/data` load |
| `employee_archives` | migration `2026_07_15_000100_add_employee_archive.php` | `snapshot` LONGTEXT per employee; the migration's `down()` **refuses to drop the table** |
| `attendance_logs` | migration `2026_06_24_000000` + `2026_08_16_000200` | good: `unique(tenant_id, external_id)`, `unique(tenant_id, emp_code, punch_at, source)`, `(emp_code, log_date)`. **Missing `(tenant_id, log_date)` composite** — the hottest query shape in the product. |
| `attendance_pending` | migration `2026_08_16_000200` | `unique(tenant_id, device_code, punch_at, source)`, `unique(tenant_id, external_id)` |
| `attendance_ratings` | migration | **unique key omits `tenant_id`** → cross-tenant overwrite (§7 S-4) |
| `invoices` | migration `2026_01_01_000001_create_companies_table.php:55-67` | **`number` IS `->unique()`** (line 59) — see §8 D-3 |
| `onprem_clients.invoice_no` | **not in any migration** | shares the FY invoice series with `invoices.number` and has **no unique index** |
| `payments.gateway_txn_id` | migration `…000001:77` | plain `string`, **no unique index** — webhook idempotency is application-code only |
| `settings` (`key='app_state'`) | migration; `unique(tenant_id, key)` | holds "all 113 screens' collections" as one JSON blob; **column type not pinned in any migration** |
| `subscriptions` | migration | **no unique index on `tenant_id`**, yet every read is `->first()` |
| `licence_devices` | migration | **no unique index on `(licence_id, device_uid)`** |
| `coupons.used_count` | migration | **no constraint against `max_uses`** |

**Critical finding: 156 runtime schema-creation call sites inside `app/`.** The salary-approval
state machine, the loan-recovery ledger, the clawback recovery stamps, the audit hash chain and
the commission history table are all created by user requests, not migrations. Six of those call
sites are reachable from **unauthenticated** endpoints (`/iclock`). Because MySQL DDL is an
implicit commit, a `Schema::create` reached inside `PunchIngestService`'s `DB::transaction()`
silently breaks that batch's atomicity — which is precisely why
`2026_08_17_000100_repair_sbb_schema.php` had to be written.

---

## 3. Business rules register

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented (file:line) | HARD-CODED? | Configurable today? | Customer may want it different? |
|---|---|---|---|---|---|---|---|---|---|
| NEW-QA-001 | Tests run against in-memory SQLite, never the live DB | — | always | isolate | build | `phpunit.xml:33-34` | Y | edit `phpunit.xml` | Y — must be MySQL |
| NEW-QA-002 | Feature tests get a fresh migrated DB per test; Unit tests get none | — | by directory | `RefreshDatabase` | build | `tests/Pest.php:12-13` | Y | code | N |
| NEW-QA-003 | Roles are created on demand in tests with no permissions attached | role name | on `makeUser` | `Role::findOrCreate` | build | `tests/Pest.php:38` | Y | code | Y — must seed the real matrix |
| NEW-QA-004 | A missing `plans.seat_max` column is added by the test fixture | schema | column absent | `Schema::table` | build | `tests/Pest.php:71-77` | Y | code | Y — belongs in a migration |
| BR-PAY-603 | One payroll run per company per month; a run past draft cannot be regenerated | company, month, status | `status ∈ {hr_approved, approved, locked, paid}` | 409 refuse | tenant | `PayrollGenController.php:2358-2368` | **Y** (status list is a literal array) | N | **Y** — which statuses lock a run is policy |
| NEW-QA-005 | A draft run may be regenerated only with `regenerate=1`; regenerate **hard-deletes** the prior run and its payslips first | run id | `regenerate` truthy | delete then recompute | tenant | `PayrollGenController.php:2388-2390` | Y | N | **Y** — many customers need the prior run retained |
| NEW-QA-006 | Regenerate reverses commission lock, commission passbook debit, OT paid flag, loan EMI ledger + counter + auto-close, clawback stamp — each **best-effort, exceptions swallowed** | run id | run existed | 4 reversal blocks | tenant | `PayrollGenController.php:2392-2466` | Y | N | Y |
| NEW-QA-007 | Every payroll side effect (commission lock, passbook, OT, loan, clawback) is applied **after** `DB::commit()`, in swallow-all try/catch | run id | after commit | stamp ledgers | tenant | `PayrollGenController.php:2695-2832` | Y | N | Y — must be atomic |
| NEW-QA-008 | `/app/data` fabricates 12 months of `payroll_runs` + a payslip per employee if absent | tenant, company, `$emps` | month has no run | insert run + N payslips | tenant | `AppDataController.php:2340-2381` (called `:455`) | Y | N | **Y — this must not exist at all** |
| NEW-QA-009 | The `/app/data` back-fill builds its payslip set from the **caller's role-scoped** employee list | requesting user's role | first load of a month wins | insert | tenant | `AppDataController.php:2340` + `:268-273` | Y | N | Y |
| NEW-QA-010 | An audit row chains to the SHA-256 of the previous row **globally across all tenants** | last `row_hash` | column exists | insert with `prev_hash`/`row_hash` | **global** | `Audit.php:90-96` | Y | N | Y — must be per-tenant and locked |
| NEW-QA-011 | Auditing never blocks the business action | any | exception | swallow | global | `Audit.php:97-99` | Y | N | Y — a lost audit row is a compliance event |
| NEW-QA-012 | A leave day with `type_id IS NULL` is treated as **paid** | `leaves.type_id` | null | credit as present | tenant | `PayrollGenController.php:1065` | Y | N | **Y — and it is defective** (§6 DEF-2) |
| NEW-QA-013 | Leave balance = `days_per_year − approved-this-calendar-year`; **`carry_forward` is read and never used**; pending is subtracted only at apply time | leave type, year | paid type | compute in PHP | tenant | `LeaveController.php:191-243` | Y (calendar year hardcoded) | leave-type quota only | **Y** — FY basis, accrual, carry-forward, encashment |
| NEW-QA-014 | An employee seat is free if `status ∈ {exited, inactive, resigned, terminated, left, absconded}` | employee status | — | exclude from count | tenant | `SubscriptionService.php:37` | **Y** (literal array) | N | Y |
| NEW-QA-015 | Seat check is a `COUNT(*)` followed by an unguarded insert elsewhere | count, licensed | `used + n <= licensed` | allow | tenant | `SubscriptionService.php:127-149` | Y | N | Y — needs a constraint |
| NEW-QA-016 | Any error while counting seats grants **unlimited** seats | exception | throw | return defaults | tenant | `SubscriptionService.php:116-118` | Y | N | **Y — must fail closed** |
| NEW-QA-017 | Any error in `denyUnlessRole` **grants** the request | exception | throw | `return null` (= allowed) | global, 71 sites | `ApprovalService.php:377-379` | Y | N | **Y — must fail closed** |
| NEW-QA-018 | Any error in `PayrollLock::isPeriodFinalised` treats the period as **open** | exception | throw | `return false` | tenant | `PayrollLock.php:71-73` | Y | N | Y |
| NEW-QA-019 | Finance authority = `admin`/`super_admin`; HR authority = `hr_manager`/`admin`/`super_admin` — **`admin` satisfies both halves of the four-eyes control** | user roles | — | permit | tenant | `SalaryApprovalController.php:59-68` | **Y** | N | **Y — segregation of duties is a per-customer audit requirement** |
| NEW-QA-020 | Downloading the bank file **is** the disbursement: approved lines flip to `disbursed` before the CSV is streamed | run id, `?all` | `!all` | update `line_status` | tenant | `SalaryApprovalController.php:493-505` | Y | `?all=1` re-download only | Y |
| NEW-QA-021 | Any insert/update array is filtered to the columns the table actually has; unknown keys are **silently dropped** | row, table | always | `array_intersect_key` | global, 98 sites | `ApprovalService.php:263-266` | Y | N | Y — must fail loudly |
| NEW-QA-022 | A master record is soft-deleted iff its table happens to have `deleted_at`, else hard-deleted | table schema | column present | update vs delete | tenant | `MasterController.php:1199-1204` | Y | N | **Y** |
| NEW-QA-023 | Master delete is tenant-filtered only when `$tid` is truthy | user tenant | `$tid` null (platform/self-hosted) | **no tenant filter** | global | `MasterController.php:1198` | Y | N | Y |
| NEW-QA-024 | A commission whose `employee_id` is NULL satisfies the "orphan" test and is hard-deleted with its payment ledger and history | `employee_id` | `whereNull('e.id')` | delete 3 tables | tenant or **global if `$tid` null** | `RequestController.php:1246-1259` | Y | N | **Y — defective** |
| NEW-QA-025 | Blank employee code → `EMP-<random 1000-9999>` | — | code blank | assign | tenant | `AppDataController.php:2819`, `:687`, `EmployeeImportService.php:376` | **Y — 9,000-value pool, 3 copies** | N | **Y** |
| NEW-QA-026 | Invoice number = highest `PRS-<FY>-…` across `invoices` and `onprem_clients`, plus 1 | FY | always | scan + max + 1 | platform | `BillingController.php:79-99` | Y | N | Y |
| NEW-QA-027 | `attendance:dedupe` and `attendance:purge-demo` and `app:clear-state` default to **all tenants** | `--tenant` optional | flag absent | delete everywhere | **global** | `DedupeAttendance.php:41-44`, `PurgeDemoAttendance.php:29-33`, `ClearAppState.php:30-35` | Y | `--tenant` flag | **Y — must be mandatory** |
| NEW-QA-028 | `DummyDataSeeder` falls back to **the first tenant by id** when no `subdomain='demo'` tenant exists, then hard-deletes 22 of its tables | tenants table | no demo tenant | wipe tenant #1 | **any tenant** | `DummyDataSeeder.php:46-47, 80-93` | Y | N | **Y — must abort** |
| NEW-QA-029 | Every scheduled task uses `withoutOverlapping()` with Laravel's **default 24-hour** lock expiry | — | prior run killed | block next | global | `routes/console.php:145-264` (12 tasks) | Y | N | Y |
| NEW-QA-030 | `attendance:sync-etimeoffice` is scheduled with a hard-coded `--days=1` | — | always | 1-day window | global | `routes/console.php:225` | **Y** | N | **Y — a >24 h outage loses punches permanently** |
| NEW-QA-031 | No business record is ever removed on age | — | — | — | global | absence of evidence across `app/`, `config/`, `database/`, `routes/` | Y | N | **Y — DPDP and statutory floors both require a policy** |

---

## 4. Calculations and formulas

### 4.1 What the suite actually asserts numerically (the total inventory of pinned money)

`AppDataController::computeSlip()` — `AppDataController.php:2094-2131`:

```
gross   = round(ctc / 12, 2)
basic   = round(gross * 0.50, 2)
hra     = round(basic * 0.40, 2)
special = round(gross - basic - hra, 2)
pf      = statutory(gross, basic, r)['pf']        // 12% of min(basic, 15000)
esi     = gross <= 21000 ? ceil(gross * 0.0075) : 0
pt      = ptForGross(gross, r, state, month, gender)
tds     = salaryTdsMonthly(ctc, r)
totalDed= round(pf + esi + pt + tds + lwf + conveyance, 2)
net     = round(max(0, gross - totalDed), 2)      // never negative
```

Worked example asserted at `PayrollMathTest.php:19-31` — CTC ₹6,00,000:
gross 50,000.0 · basic 25,000.0 · hra 10,000.0 · special 15,000.0 ·
pf 1,800.0 (`min(25000,15000) × 12%`) · esi 0.0 (gross > 21,000).
**`net` is asserted only as `round(gross − total_ded, 2)` — i.e. the test restates the
implementation's own arithmetic** and would pass if every deduction changed.

Second example `:33-41` — CTC ₹2,40,000: gross 20,000.0 · pf 1,200.0 · esi 150.0
(`20,000 × 0.75%`). Same structural-only net assertion.

Third `:43-49` — CTC 0: gross 0.0, net 0.0, pt 0.0.

**PT is the only statutory value asserted against externally-known figures** (`:55-103`):
Telangana 14,000→0 / 20,000→150; Andhra 25,000→200; Karnataka 24,999→0 / 25,000→200;
Gujarat 12,000→0 / 18,000→200; Delhi/UP/Haryana/Rajasthan 90,000→0;
Maharashtra 9,000→175, 15,000→200, February 15,000→**300**, February 9,000→175 (uplift applies
only at the top slab); Maharashtra female ≤25,000 → 0, 30,000 → 200, male 20,000 → 200,
Telangana female 20,000 → 150 (no leak), and `pt_female_exempt=0` → 200 (per-company override).

**Nothing else in the entire suite asserts a rupee.** Not PF employer split (EPS 8.33 % capped
₹1,250 / EPF 3.67 % / EDLI 0.5 % capped ₹75 — the figures that go on the EPFO ECR), not ESI
employer 3.25 %, not TDS, not LOP, not the per-day divisor, not overtime, not recoveries,
not net pay from `generate()`.

### 4.2 Formulas the tests do NOT cover, transcribed for the golden matrix

| Formula | Source | Divisor / basis | Rounding | Covered? |
|---|---|---|---|---|
| LOP factor, `working` basis | `PayrollGenController.php:1287` | `paidDays / empWorking`, capped at 1.0 | none until component maths | **NO** |
| LOP factor, `calendar` basis | `:1268-1283` | `(avail − absentDays) / daysInMonth` | `round(…, 2)` on display only | **NO** |
| LOP factor, `fixed30` basis | `:1275-1279` | den = 30.0; joiner avail = `30 − round((dojDay−1) × 30 / daysInMonth, 2)` | `round(…, 2)` | **NO** |
| Working days | `:1117-1126` | calendar days − week-offs (configurable) − working-day holidays; `max(1, …)` | integer | **NO** |
| Mid-month joiner | `:1215-1227` | working days **from** DOJ | integer | **NO** |
| Overtime hourly rate | `:2165` | **`ctc / 12 / 26 / 8`** — 26 working days and an 8-hour day **hard-coded into a money formula**, contradicting the configurable `weekly_off_day` / `sat_off_mode` and the configurable per-shift `full_day_hours` | — | **NO** |
| Recovery order | `:1351-1382` | net → loan EMI (whole instalments only, skipped if it does not fit) → advance (partial allowed) → clawback (partial allowed, shortfall stays open) | `round(…, 2)` each step; net floored at 0 | **NO** |
| ESI contribution-period lock | `AppDataController.php:2011-2046` | Apr–Sep / Oct–Mar; scans up to 24 prior payslips' `deductions` JSON for any key containing "esi" with value > 0 | — | **NO** |
| Seat usage | `SubscriptionService.php:88-97` | `COUNT(*)` of employees not soft-deleted and not in the 6 seat-free statuses | integer | **NO** |
| Invoice sequence | `BillingController.php:81-98` | `max(trailing digits across two tables for the FY) + 1`, zero-padded to 4, month segment from `now()` | — | **NO** |
| Leave balance | `LeaveController.php:191-243` | `days_per_year − approved-in-calendar-year`; **`carry_forward` ignored**; pending subtracted only in the apply guard | `round(…, 1)` | **NO** |
| Audit row hash | `Audit.php:52-63` | `sha256(prev \| tenant \| user \| action \| entity \| entityId \| detail \| 'Y-m-d H:i:s')` | — | **NO** |

### 4.3 Code-quality metrics computed for this analysis (Q8, part 1)

| Metric | Formula | Value |
|---|---|---|
| Total product LOC | `app + resources + routes + database + config + public` | **81,592** |
| `app/` LOC | | 65,933 |
| LOC per declared test case | 81,592 / 132 | **618 lines per test** |
| LOC per assertion | 81,592 / 446 | **183 lines per assertion** |
| Routed surface exercised | 18 / 422 | **4.3 %** |
| Modules with zero tests | 16 / 24 | **67 %** |
| Transaction density | 4 transactions / 308 `->get()` + ~200 write sites | **~1 transaction per 20,000 LOC** |
| Lock density | 0 | **zero** |
| Runtime DDL density | 156 sites / 65,933 `app/` LOC | 1 per 423 lines |
| Schema-introspection density | 738 sites | 1 per 89 `app/` lines |

---

## 5. Configuration surface today

### 5.1 (a) What a customer / operator CAN change without code

| Item | How |
|---|---|
| Test database engine | edit `phpunit.xml:33-34` (the comment block gives the MySQL recipe) |
| Which `.env` the suite reads its edition from | `SMARTPRS_EDITION` in the developer's own `.env` — **untracked, and this silently changes which tests pass** |
| `--tenant` scoping on the three destructive Artisan commands | optional CLI flag |
| `--dry` preview on `attendance:dedupe` | optional CLI flag (**not available on `attendance:purge-demo` or `app:clear-state`**) |
| Demo reset password | `demo:reset --password=` |
| Scheduled task cadence | edit `routes/console.php` — i.e. **code**, listed here only because operators treat it as config |
| Backup retention | `config/backup.php` (spatie) — the only real retention control in the product, and it governs archive files, not live data |

### 5.2 (b) What a customer CANNOT change without code — the SmartPRS 3 backlog for this area

1. **Data retention period for any entity.** No table, no setting, no column. Attendance,
   payslips, audit rows, candidate PII, onboarding selfies, archived-employee snapshots all live
   forever with no configurable horizon.
2. **What happens to an employee's PII on exit.** Nothing happens today; there is no policy hook.
3. **What happens to a tenant's data on cancellation.** There is no tenant-deletion path at all.
4. **Whether a payroll run may be destroyed by regenerate**, and whether the destroyed version is
   retained. Hard-coded delete at `PayrollGenController.php:2389-2390`.
5. **Which run statuses lock a run from regeneration** — literal array at `:2360`.
6. **Segregation-of-duties for salary approval.** `isHR()`/`isFinance()` are hard-coded role
   arrays at `SalaryApprovalController.php:59-68`, and `admin` satisfies both.
7. **Whether authorisation fails open or closed.** Hard-coded `return null` at
   `ApprovalService.php:378`; same pattern in `SubscriptionService::seatUsage()`,
   `PayrollLock::isPeriodFinalised()`, `LicenseGate`, `EditionGuard`, `AuthController`.
8. **Which employee statuses free a licence seat** — literal array `SubscriptionService.php:37`.
9. **Whether archiving an employee frees a seat** — it does, silently, and cannot be turned off.
10. **The OT day/hour basis** (`/26/8`) — `PayrollGenController.php:2165`.
11. **The leave-balance year basis** (calendar), **carry-forward** (read but never applied) and
    **accrual** — `LeaveController.php:192, 239`.
12. **Whether the app fabricates 12 months of payroll history on first page load** —
    `AppDataController.php:2340-2381`.
13. **Any page size anywhere.** Zero `paginate()` calls; the only bounds are silent caps
    (`limit(1000)` on the archived-employee list, `limit(50)` on clawbacks and salary schedules,
    `limit(24)` on the ESI history) that truncate with **no user signal**.
14. **Date-range caps on reports.** `AttendanceReportController::index` accepts any range.
15. **Whether destructive Artisan commands require a tenant and a confirmation.**
16. **The invoice-number series shape and its uniqueness guarantee.**
17. **Whether a decision (leave/request approval) can be re-decided.** No status guard exists.
18. **Whether mutating endpoints are idempotent.** None are.
19. **Whether background work is queued.** 2 job classes, 3 dispatch sites; everything else —
    payroll, PDFs, exports, bulk WhatsApp, absence mail — runs inside the HTTP request.
20. **Whether a test suite, static analysis or CI runs at all.**

---

## 6. Defects, conflicts and technical debt

### 6.1 Q3 — THE UNTESTED CRITICAL SURFACE, RANKED BY BUSINESS RISK

Risk = (financial/legal consequence) × (probability a change breaks it) × (time undetected).
Every entry below is **confirmed in source**, not taken from the reference documentation.

---

**Rank 1 — Payroll money, end to end. Nothing asserts a rupee.**
`PayrollTest` asserts a run header exists and `count === 2`. `PayrollGenController::compute()`
(`:1091-1490`) computes the LOP factor on three configurable bases, resolves per-employee salary
components, applies the ESI period lock, computes TDS, and subtracts loan EMI → advance →
clawback in a strict order. **None of it is tested.** Specific fragilities:
- `:1237` deliberately pays **100 % when an employee has zero punches** (so a dead reader cannot
  zero a salary). Invert that guard and every employee at a site with a broken device is paid
  nothing, on pay day, with no test failing.
- `AppDataController.php:2130` — `round(max(0, gross − totalDed), 2)`. Remove `max(0, …)` and
  heavily-indebted employees get negative payslips.
- `esiPeriodLock()` (`AppDataController.php:2011-2046`) reads prior payslips' `deductions` JSON
  and is wrapped in `catch (\Throwable) { return false; }` — **any** error silently reverts to the
  plain ₹21,000 threshold, under-deducting ESI mid-contribution-period.
- The employer PF split (EPS/EPF/EDLI) feeds the **EPFO ECR challan**. Wrong figures are a
  statutory filing error, not a display bug.
*Business impact:* wrong money in employee bank accounts and wrong statutory challans, discovered
by employees in public on pay day — after the bank file has been downloaded, which in this system
**is** the disbursement (`SalaryApprovalController.php:493-505`). There is no reversal and no
negative run; the only correction path is a clawback next month.

---

**Rank 2 — Leave → LOP. Already broken in production, with no test to notice.** **CONFIRMED.**
`LeaveController::apply()` builds its insert array at `:359-373` carrying **`type_name` only —
never `type_id`**. `PayrollGenController::paidLeaveDays()` (`:1055, :1065`) selects `type_id` and
evaluates `$paid = $lv->type_id === null ? true : …`. Therefore **`type_id` is always null, `$paid`
is always true, and the `continue` that skips unpaid leave at `:1067` is unreachable dead code.**
Every approved leave — **including leave explicitly filed as "Loss of Pay"** — is credited by
payroll as a paid present day. Compounding: `computeBalances()` charges the employee's balance in
**calendar** days (`LeaveController.php:227-243`) while payroll credits only **working** days
(`:1077-1082`), so a Fri–Mon leave costs 4 days of balance and credits 2 days of attendance.
*Business impact:* employees on unpaid leave are paid in full, across every employee who has ever
taken LOP, discovered months later in an audit. Recovery means clawing money back from staff.
A ~25-line test would have caught this the day it was written.

---

**Rank 3 — Authorisation fails open at 71 call sites.** **CONFIRMED.**
`ApprovalService::denyUnlessRole()` (`app/Http/Controllers/ApprovalService.php:365-380`) ends:
```php
} catch (\Throwable $e) { return null;   // fail open — availability over strictness
```
Every caller reads `null` as "allowed". Called at **71 sites** (measured). A missing `roles` pivot,
a dropped connection mid-request, or a corrupt Spatie cache **grants** the request. The suite
contains exactly **two** authorisation assertions: `MasterCrudTest` #2 (asserts the row was not
written, never asserts a status code) and `BillingTest` #3 (403 on one of ~40 platform routes).
Neither touches `denyUnlessRole`.

---

**Rank 4 — The four-eyes control on salary runs is not a four-eyes control.** **CONFIRMED, and
this is stronger than the reference documentation states.**
`SalaryApprovalController.php:59-68`:
```php
isHR()      => hasAnyRole(['hr_manager','admin','super_admin'])
isFinance() => hasAnyRole(['admin','super_admin'])
```
The comment claims *"HR-only cannot do the finance step"* — true for `hr_manager`, but **an
`admin` satisfies both predicates**, so a single admin can prepare, approve and disburse a payroll
run alone. `admin` is the default role for a customer's own workspace owner. Untested; a
one-character change to either array silently removes what remains of the control. This is exactly
what a customer's auditor asks to see evidence of.

---

**Rank 5 — `/app/data` fabricates payroll history, and the first page load of a month wins
forever.** **CONFIRMED — the single most surprising finding in this analysis.**
`AppDataController::bootstrap()` calls `ensurePayrollHistory($tenantId, $companyId, $emps, $rates)`
at `:455`. That method (`:2340-2381`) loops the last **12 months**, and for any month with no
`payroll_runs` row **inserts a run (status `'paid'` for k>0) and one payslip per employee**,
computed from `computeSlip()`. Two consequences:
- **It writes fake statutory payroll records into the production `payslips` table on a page load.**
- Its existence check is `where(tenant_id, company_id, cycle_label)->exists()`, and `$emps` is the
  **role-scoped** list built at `:268-273` — a plain `employee` login is scoped to themselves.
  **A plain employee opening the app before HR does creates 12 runs with `employees_count = 1` and
  exactly one payslip each; HR's later load then skips those months permanently.**
No test covers this, and `AuthAccessTest` #3 (`GET /app/data` → 200) actively *runs* it in CI-less
silence.

---

**Rank 6 — Licence and seat enforcement.** Zero coverage. `LicenseService`, `LicenseFile`,
`LicenseSigner`, `LicenseGate`, `SubscriptionService`, `EnsureSubscriptionActive`, `EditionGuard`
and `ClientUpdateController` are not executed by a single test. `SubscriptionService::seatUsage()`
fails open to **unlimited** on any exception (`:116-118`); `canAddEmployees()` is a `COUNT(*)`
followed by an insert elsewhere with no constraint (§6.5 C-5). On-prem provisioning sets
`seats_licensed = 100000` (`InstallController.php:198`, `ClientProvision.php:71`), so the `.lic`
`device_limit` is the only real control. *Business impact:* revenue leakage, or the catastrophic
inverse — a bad release locking every on-prem customer out on the same Monday morning.

---

**Rank 7 — Tenant isolation outside the ingest path.** Isolation here is manual per-query
filtering: `when($tid, fn ($q) => $q->where('tenant_id', $tid))` written by hand at dozens of
sites, **omitted entirely** at some (`KbController.php:112`, `TestController.php:192`), and
**disabled by design whenever `$tid` is null** — which is exactly the platform super-admin and
every self-hosted install (`MasterController.php:1198`, `RequestController.php:1249`,
`PointsController.php:80`). The suite has one two-tenant fixture pattern and uses it only in
ingest tests. No test creates two tenants and asks a *reporting*, *payslip*, *document*, *master*
or *approval* endpoint whether it leaks. For an HR/payroll product a cross-customer salary and
identity leak is the single worst possible incident.

---

**Rank 8 — Decisions are re-decidable.** **CONFIRMED.** `LeaveController::decide()`
(`:392-428`) fetches the leave, checks the approver, then writes the new status **without ever
checking the current one**. An already-approved leave can be re-rejected — after payroll has
credited the days — overwriting `remarks`, re-firing the decision email, and leaving a misleading
`activity_logs` trail. The same shape exists in `RequestController::decide`. Zero tests perform
**any** approval transition on **any** module.

---

**Rank 9 — Invoice numbering and the GST paper trail.** `BillingTest` #2 asserts an invoice row
exists and becomes `paid`; **the `number` column is never asserted, nor is the amount.** The
series is shared across `invoices.number` (unique-indexed) and `onprem_clients.invoice_no`
(**no unique index**), so a cross-table race duplicates silently. The month segment comes from
`now()->format('m')`, so numbers are **not monotonic within a month**. Three further copies of the
algorithm exist (`SignupController::quote`, `invoices:renumber-prs`, and a structurally different
`count()+1` in `BankPackController`).

---

**Rank 10 — Employee import round-trip.** Four pure helpers are tested; the
`stage → preview → commit` pipeline is not. `EmployeeImportService.php:376` assigns a random
`EMP-nnnn` with **no uniqueness check at all** (unlike `AppDataController.php:2818-2820`, which at
least loops). With 500 blank-code rows drawn from a 9,000-value pool the expected number of
internal collisions is ~13; each throws a `QueryException` caught by an import `catch`, so ~13
employees are **silently absent** from a "successful" import — and they then have no code, so their
punches never match and they are marked absent every day.

---

**Rank 11 — Attendance status derivation and the shift resolver.** `ShiftResolver` has no test.
`AbsenceService::isAbsent()` is tested at four inputs with the shift-`off` branch explicitly not
covered (the test at `AbsenceServiceTest.php:34-40` admits it: it asserts the *fallback* path and
uses a blank `emp_code` as a proxy). "Present" is derived four different ways in four places.
A failing `holidays` query flags the entire workforce absent on a public holiday — and then emails
every one of them.

---

**Rank 12 — Scheduled tasks, including the destructive ones.** Zero coverage across 12 scheduled
commands. `DummyDataSeeder.php:46-47` — **CONFIRMED** — falls back to
`DB::table('tenants')->orderBy('id')->value('id')` when no `subdomain='demo'` tenant exists, then
hard-deletes 22 tables for that tenant at `:80-93`. `demo:reset` creates the demo tenant first so
the scheduled path is safe *today*; anything that invokes `DummyDataSeeder` directly wipes the
oldest customer. `attendance:dedupe`, `attendance:purge-demo` and `app:clear-state` all default to
**every tenant on the server**.

---

**Rank 13 — The SPA: ~12,000 lines of JavaScript inside a PHP heredoc.** `AppController.php` is
12,533 lines with **2 PHP methods**; from `:452` onward it is one `<<<HTML` heredoc containing the
whole single-page application (2,855 lines contain `var`/`document.`/`innerHTML`). There is no
`package.json`, no `vite.config.js`, no bundler, no linter and no JS test runner in this
repository. It cannot be type-checked, linted, unit-tested or code-split. Because the permission
matrix is *also* enforced in that JavaScript, a client-side regression is a security regression.

---

### 6.2 Q4 — CONCURRENCY: EVERY RACE CONDITION

**The enabling fact, measured: the application contains ZERO locks and FOUR transactions.**

```
grep -rn "lockForUpdate|sharedLock|Cache::lock|->lock(" app/  →  0
grep -rn "DB::transaction|beginTransaction"          app/    →  4  (3 in app/, 1 in routes/console.php)
```
No pessimistic locking, no optimistic locking (no `version` column anywhere), no advisory lock,
no `Cache::lock`. The four transactions are `PunchIngestService.php:72`,
`EmployeeImportService.php:402`, `PayrollGenController.php:2620`, `routes/console.php:126`.

| # | Race | Money? | Compliance? | Severity |
|---|---|---|---|---|
| C-1 | Payroll generation run twice | **YES** | **YES** | CRITICAL |
| C-2 | Payroll side effects applied after commit | **YES** | YES | CRITICAL |
| C-3 | Payroll regenerate deletes outside the transaction | **YES** | **YES** | CRITICAL |
| C-4 | `app_state` last-writer-wins blob | indirect | YES | CRITICAL |
| C-5 | Seat / licence counting | **YES** (revenue) | — | HIGH |
| C-6 | Audit hash chain | — | **YES** | HIGH |
| C-7 | Money balance checks (commission, salary payment, clawback) | **YES** | YES | HIGH |
| C-8 | Invoice numbering | **YES** | **YES (GST)** | HIGH |
| C-9 | Invoice creation vs period advance (renewal) | **YES** | **YES (GST)** | HIGH |
| C-10 | Payment recorders / Razorpay webhook | **YES** | YES | HIGH |
| C-11 | Leave balance check-then-act | **YES** | YES | HIGH |
| C-12 | Loan / advance recovery counters | **YES** | YES | HIGH |
| C-13 | Commission approval / lock | **YES** | YES | HIGH |
| C-14 | Bank-file disbursement stamping | **YES** | YES | HIGH |
| C-15 | Employee-code generation | — | YES | HIGH |
| C-16 | Punch direction re-stamp (single-device) | **YES** (via LOP/OT) | YES | HIGH |
| C-17 | 12-month payroll back-fill on first page load | **YES** | **YES** | HIGH |
| C-18 | `attendance_ratings` cross-tenant upsert | — | **YES (isolation)** | HIGH |
| C-19 | Re-decidable approvals | **YES** | YES | MEDIUM |
| C-20 | Runtime DDL under concurrency | — | — | MEDIUM |
| C-21 | `employee_references` delete-then-insert | — | YES | MEDIUM |
| C-22 | Employee-code rename cascade over 8 tables | **YES** | YES | MEDIUM |
| C-23 | `withoutOverlapping()` 24-hour lock expiry | — | YES | MEDIUM |
| C-24 | Provisioning is not transactional | **YES** | YES | MEDIUM |
| C-25 | Coupon redemption count | **YES** | — | LOW |
| — | **Punch ingest** | — | — | **CORRECT — the in-house precedent** |

---

**C-1 · Payroll generation run twice — CRITICAL, money and compliance.**
*Interleaving.* `PayrollGenController::generate()` reads `existingRun()` at `:2359`, checks
status, and only inserts the run header at `:2622` — after `compute()` has run, which at 2,000
employees takes minutes. Request A reads (none), request B reads (none, A has not committed),
both run `compute()`, both `insertGetId` a `payroll_runs` row, both insert a full set of payslips.
`payroll_runs` has **no unique index on `(tenant_id, company_id, cycle_label)`** — confirmed in
`2026_01_01_000300_create_attendances_table.php:37-51`.
*Trigger.* The user clicks Generate, the page appears to hang because the run genuinely takes
minutes, and she clicks again. Only 2 of ~180 mutating buttons in the SPA disable themselves in
flight.
*Business consequence.* Two draft runs and **two payslips per employee for the same month**.
`net_total` doubles in every register that sums runs; the bank pack pays twice;
`RequestController.php:1033` (`orderByDesc('id')->value('net')`) silently picks one of them for
the salary-payment balance check. **Money and statutory: yes.**

**C-2 · Payroll side effects applied after `DB::commit()` — CRITICAL.**
*Interleaving.* The transaction at `:2620-2691` covers **only** the run header and the payslips.
Everything that mutates a ledger runs *after* `DB::commit()` at `:2691`, each in a
`try { … } catch (\Throwable $e) { Log::warning(…); }`: the commission lock + `commission_payments`
debit (`:2695-2740`), the OT `paid` flag (`:2743-2762`), the loan-EMI `loan_recoveries` insert +
`installments_paid` increment + auto-close (`:2765-2806`), and the clawback stamp (`:2809-2831`).
*Business consequence.* A payslip can say "Loan EMI ₹5,000 deducted" while `installments_paid`
never advances — the loan is recovered **forever**. A commission can be paid on the payslip while
`locked_at` is never set, so it is eligible again next month. An OT entry can be paid and never
flip to `paid`, so it is paid again. Every one of these is **silent**: the log line is the only
trace, and production runs `LOG_LEVEL=warning` with the error digest filtering for `ERROR`.
**Money: yes. Compliance: yes** — the wage register and the ledger disagree.

**C-3 · Regenerate deletes outside the transaction — CRITICAL.**
*Interleaving.* `:2389-2390` hard-deletes `payslips` then `payroll_runs` for the existing draft.
The transaction that writes the replacement does not open until `:2620`. Between them run
`compute()` (minutes), four best-effort reversal blocks, and up to five `Schema::table`/
`Schema::create` DDL statements. A crash, a lock-wait timeout, an OOM, or a `compute()` that
returns no rows (`:2469-2471` returns 422 and stops) leaves the month with **no payslips at all**
and no way back. The code's own comment at `:2618-2619` claims the opposite:
*"write the run header + every payslip ATOMICALLY, so a failure mid-way can never leave a run with
a partial set of payslips."* Under two concurrent regenerates, both read the same `$existing`,
both issue the delete (one succeeds, one no-ops), and **both run the loan reversal**, so
`decrement('installments_paid')` at `:2440` fires twice and a correctly-closed loan is re-opened.
**Money: yes. Compliance: yes** — Payment of Wages retention is a 3-year floor and this destroys
the register on demand.

**C-4 · `app_state` last-writer-wins blob — CRITICAL.**
`AppStateController::save()` (`:39-53`) does a full-blob `updateOrInsert` with **no version, no
ETag, no `If-Match`, no merge, no diff, and no role check whatsoever** (route
`routes/web.php:417`, behind `auth` only).
*Interleaving.* 09:00 HR opens the app, browser downloads the blob. 09:02 the payroll clerk opens
it and downloads the same snapshot. 09:10 HR adds three holidays; the browser POSTs the whole blob.
09:12 the clerk adds a salary component; **their browser POSTs the 09:02 snapshot** plus one
component. The three holidays are gone, for every user of the tenant, with no error and no audit
entry. The window is not milliseconds — it is however long a tab stays open.
*Business consequence.* The holiday calendar is a payroll input; the next run prices LOP against
the wrong denominator. Also: `created_at` sits in the *update* half of `updateOrInsert`, so the
row's creation timestamp is rewritten on every save, destroying the only forensic breadcrumb.
**Compliance: yes** (silent, unattributable loss of a payroll input).

**C-5 · Seat and licence counting — HIGH, revenue.**
*Interleaving (employee seats).* `SubscriptionService::canAddEmployees()` (`:127-149`) calls
`seatUsage()` which issues `COUNT(*)` (`:88-97`), compares `used + count <= licensed`, and
**returns**; the caller inserts later. A tenant licensed for 75 with 74 employees: two HR users add
simultaneously, both counts read 74, both pass, both insert. 76 employees on a 75-seat licence, and
nothing ever re-checks. `tenants.seats_used` is **written only at tenant creation (`0`) and in the
demo seeder (`36`)** — measured: no code path ever recomputes it, so the SaaS panel's headcount is
permanently wrong.
*Interleaving (licence server seats).* `LicenseService::recordDevice()` reads `server_limit`, calls
`activeDeviceCount()`, compares, then inserts. **No unique index on
`licence_devices(licence_id, device_uid)`** exists in any migration, so not even a duplicate-key
error catches it. A customer migrating servers while the old one still phones home ends up with
two active seats against a limit of one.
*Aggravator.* `seatUsage()` catches every throwable and returns the default (`:116-118`) — a
transient DB error grants **unlimited seats** for that request. **Money: yes (revenue leak).**

**C-6 · Audit chain hash — HIGH, compliance.**
`Audit::record()` (`:90-96`): `$prev = DB::table('activity_logs')->orderByDesc('id')->value('row_hash')`,
compute, insert. **No lock, no transaction, and the chain head is global across all tenants** —
there is no `where('tenant_id', …)` on that read.
*Interleaving.* Two audited actions in *different tenants* commit within the same millisecond.
Both read row N's hash as `$prev`. Both insert, both claiming N as predecessor. The chain forks.
`verify()` (`:105+`) walks by `id` and reports the log as tampered from that point on.
*Business consequence.* **False tamper alarms under ordinary multi-tenant concurrency** — which
destroys the credibility of the only integrity control in the product. And because `record()`
swallows every exception (`:97-99`), a fork is indistinguishable from a genuinely dropped row.
Secondary: the read is an index scan on the largest and fastest-growing table in the schema, on
**every audited write**, and `activity_logs` has no `created_at` index. **Compliance: yes.**

**C-7 · Money balance checks (read-then-write) — HIGH.**
Three ledger paths compute a balance and then write against it with nothing in between:
commission payment (`RequestController.php:929` `sum('amount')` → `:932` check → insert), salary
payment (`:1033` net from `payslips`, `:1036` `sum` from `salary_payments` → `:1040` check →
insert), and the commission bounce clawback (`:1752-1756`, whose `sum` is inside an **empty
catch**).
*Interleaving.* A ₹50,000 commission has ₹40,000 paid. Two accounts staff each record ₹10,000 at
the same moment. Both read `paid = 40,000`, both compute `balance = 10,000`, both evaluate
`10,000 > 10,000 + 0.005` as false, both insert. **₹60,000 recorded against a ₹50,000 commission**,
and the same `sum()` is the only balance source, so nothing ever detects it. **Money: yes.**

**C-8 · Invoice numbering — HIGH, GST.**
`BillingController::nextInvoiceNumber()` (`:79-99`) plucks **every** `PRS-<FY>-…` number from both
`invoices` and `onprem_clients`, computes the max with a regex in a PHP loop, returns `max + 1`.
*Interleaving.* Two staff raise invoices in the same second. `invoices.number` **is**
`->unique()` (`2026_01_01_000001_create_companies_table.php:59`), so a same-table race **throws** a
`QueryException` — the user sees a 500 and no invoice is created (bad UX, not a filing defect).
`onprem_clients.invoice_no` appears in **no migration and has no unique index**, so a SaaS invoice
and an on-prem licence invoice raised simultaneously **both take the same number and both commit**,
across tables where no single-table constraint could catch it.
*Business consequence.* A duplicate number in a **GST tax-invoice series** — a filing defect that
surfaces at GSTR-1 reconciliation months later and cannot be patched retrospectively.
**Money and compliance: yes.**

**C-9 · Subscription renewal — HIGH, GST.**
`BillingController::runRenewals()` (`:986-1029`): for each due subscription, create the invoice
(`:1002`), then in a **separate statement** update `current_period_end` and `next_renewal`
(`:1008-1012`). **No transaction.** A failure, timeout or process kill between them raises an
invoice without advancing the renewal date; the next run re-selects the same subscription and
raises a **duplicate GST invoice**. There is also no lock on the run itself — the scheduled
`billing:alerts` path and a manual `billing:renew` can both select the same due set. The
`BillingTest` #8 assertion is sequential and proves nothing about either.
**Money and compliance: yes.**

**C-10 · Payment recorders / Razorpay webhook — HIGH.**
`BillingController` idempotency is `$already = DB::table('payments')->where('gateway_txn_id', $txn)
->where('status','success')->exists();` at both `:378` and `:417`, followed by an insert at `:386`
/ `:428`. **`payments.gateway_txn_id` has no unique index** (`2026_01_01_000001:77`). Razorpay
retries webhooks; two retries arriving concurrently both read `already = false` and both insert.
`BillingTest` #5 replays the webhook **sequentially**, which is exactly the case the application
code already handles — the test creates false confidence about the case it does not.
*Business consequence.* An invoice credited twice; `invoicePaidSum()` exceeds the invoice total;
bank reconciliation fails. The same shape arises when a manual payment and a gateway webhook land
together. **Money: yes.**

**C-11 · Leave balance — HIGH.**
`LeaveController::apply()` (`:342-360`) calls `computeBalances()` — two SELECTs, no lock — computes
`remaining = balance − pending`, checks `days > remaining`, and inserts at `:376`. There is **no
leave ledger**: the balance is derived on every read from `leave_types.days_per_year` minus
approved days in the **calendar year**, with `carry_forward` read and never applied
(`:239`). Two applications submitted together both see the same remaining balance and both pass.
Worse: `decide()` has no status guard (C-19), so an approval can be replayed. **Money: yes** — an
over-granted paid leave day is a paid day of salary; **compliance: yes** for statutory leave types.
*Also a performance defect:* when the exact name/code match misses, `:322` iterates
`$empBase()->get()` — **every employee of the tenant, all columns, into PHP** — to do a
whitespace-normalised comparison. At 10,000 employees that is a full table load per leave
application.

**C-12 · Loan and advance recovery — HIGH.**
Beyond C-2 (post-commit application) and C-3 (double reversal): `advanceRecovery()`
(`PayrollGenController.php:869-885`) sums advances approved **within the payroll month** and
recovers them — but **nothing is ever stamped on the `advances` row**. There is no
`recovered_run_id`, no `recovered_at`. Consequences: an advance approved after that month's run was
finalised is **never recovered by any run**; and the advances ledger has no record that a recovery
ever happened, so the two views of the same money can never be reconciled. `clawbacksDue()`
(`:903-930`) carries a silent `->limit(50)` — an employee with more than 50 open clawbacks
silently loses recovery on the excess. **Money: yes.**

**C-13 · Commission approval and lock — HIGH.**
The lock is applied after commit (C-2): `:2708-2712` selects `whereNull('locked_at')` and updates,
then `:2713-2740` inserts a `commission_payments` debit per row. Two concurrent generates both
read `locked_at IS NULL`, both lock, and **both insert a passbook debit** — the employee's
commission passbook shows the same commission paid twice. On regenerate, the reversal
(`:2404-2418`) matches `reference LIKE 'run #<id> %'` — a **string match on free text** — so a
manual passbook entry whose reference happens to match is deleted too. **Money: yes.**

**C-14 · Bank-file disbursement stamping — HIGH.**
`SalaryApprovalController::bankFile()` (`:444-517`) reads the payable lines, builds the CSV,
then at `:493-505` writes `bank_filed_at` and flips `approved → disbursed` — **before the response
leaves the server, outside any transaction, with no lock.** Two Finance users downloading
simultaneously both get the full line set (neither's stamp has committed), so **the bank is
instructed to pay twice**. Conversely, a download that fails mid-transfer leaves salaries marked
disbursed with no file in anyone's hands. **Money: yes. Compliance: yes.**

**C-15 · Employee-code generation — HIGH.**
Three sites, `random_int(1000, 9999)` — a **9,000-value pool**:
`AppDataController.php:2819` (with a `do/while` existence check — a race, and an increasingly long
retry loop as the tenant grows), `AppDataController.php:687` and `EmployeeImportService.php:376`
(**no check at all**). By the birthday bound, collision probability exceeds 50 % at ~112 employees
and 99.99 % at 2,000. `unique(tenant_id, emp_code)` (`2026_01_01_000100:158`) turns a collision
into a `QueryException` — which the import's `catch` swallows, so employees vanish silently
(Rank 10). If that index is ever absent, two employees share a code and their attendance merges,
because `attendance_logs` is keyed on `emp_code`, not `employee_id`.

**C-16 · Punch direction re-stamp (single-device mode) — HIGH. NOT IN THE REFERENCE DOCS.**
`ETimeOfficeService::sequenceDirections()` (`:620-680`) reads **every stored punch for an
employee-day**, merges it with the incoming batch, computes alternating IN/OUT ranks in PHP, and
issues one `UPDATE` per changed row — with no transaction and no lock.
*Interleaving.* A single-reader device pushes punches one HTTP request at a time to the
**unauthenticated** `/iclock` endpoint and retries freely. Request A reads the day's punches;
request B inserts a new one and re-stamps; request A then writes its stale sequence over B's.
*Business consequence.* An IN becomes an OUT (or vice versa) for that day. The day then computes
as a zero-length shift or a 14-hour shift → wrong worked hours → wrong LOP, wrong late deduction,
wrong overtime. `DeviceModeTest` "continues the day sequence across separate syncs" passes
**precisely because the three calls are sequential**. **Money: yes** (via LOP/OT).

**C-17 · 12-month payroll back-fill on first page load — HIGH.** See §6.1 Rank 5. The existence
check and the insert are separated by up to N payslip computations; two simultaneous first loads
of a month create two runs. **Money and compliance: yes.**

**C-18 · `attendance_ratings` cross-tenant upsert — HIGH, isolation.**
`AttendanceReportController.php:501-511`: `updateOrInsert(['emp_code' => …, 'log_date' => …], [...])`
— the match key **omits `tenant_id`**, and the table's unique index omits it too. Two tenants both
using `EMP001` on the same date: one silently overwrites the other's rating and remarks, and the
loser's read (which *is* tenant-filtered) shows nothing. **Compliance: yes** (cross-tenant write).

**C-19 · Re-decidable approvals — MEDIUM.** See §6.1 Rank 8. Two approvers acting on the same
request both succeed; duplicate transfer orders and increment letters go out; prior `remarks` are
overwritten by a blank re-decide.

**C-20 · Runtime DDL under concurrency — MEDIUM.** 156 `Schema::create`/`Schema::table` sites in
`app/`, six reachable from unauthenticated endpoints. MySQL DDL is an implicit commit — the code
acknowledges this at `PayrollGenController.php:2553-2556` and moves five DDL statements *outside*
its transaction for that reason. Two concurrent generates both evaluate
`! Schema::hasColumn('payslips','calc_note')` as true and both `ALTER`; the loser's error is
swallowed. On a large `payslips` table the `ALTER` holds a metadata lock that blocks **every**
query on `payslips` — including the other payroll run — for the duration of the rebuild.

**C-21 · `employee_references` delete-then-insert — MEDIUM.** `AppDataController.php:3021` deletes
all references for an employee, then re-inserts, with no transaction. A failure between them
loses the references entirely.

**C-22 · Employee-code rename cascade — MEDIUM.** `storeEmployee` updates the employee row then
loops **eight history tables** issuing individual `UPDATE`s, each in its own empty try/catch, with
no surrounding transaction. A partial failure leaves attendance under the old code and commissions
under the new one, permanently, with no error shown.

**C-23 · `withoutOverlapping()` 24-hour lock expiry — MEDIUM.** All 12 scheduled tasks
(`routes/console.php:145-264`) use the default expiry. A task killed without releasing its lock is
blocked for up to a day. For `attendance:sync-etimeoffice` (5-minute cadence, hard-coded
`--days=1`) that means **a full day of punches lost permanently**, because the recovery window is
one day wide.

**C-24 · Provisioning is not transactional — MEDIUM.** The tenant → company → user → subscription
→ invoice → payment chain runs with no transaction across three provisioning paths, leaving
half-created tenants that are billable but unbilled, or paid-for but non-existent.

**C-25 · Coupon redemption count — LOW.** `coupons.used_count` has no constraint against
`max_uses`; concurrent redemptions exceed the cap.

**The one correct counter-example.** `PunchIngestService::ingest()` wraps the whole batch in
`DB::transaction()` (`:72`) and relies on two unique indexes plus `insertOrIgnore` (`:141, :246`)
for idempotency — tenant-bound, and covered by 23 tests including explicit idempotency and
replay-idempotency cases. **The team knows the pattern. It has simply never been applied to money
or to numbering.** Cite this as the in-house precedent when specifying SmartPRS 3.

---

### 6.3 Q5 — PERFORMANCE AT 1,000 / 5,000 / 10,000 EMPLOYEES PER TENANT

**The two structural facts.**
`->paginate(` / `->simplePaginate(` : **0 occurrences in `app/`**. 308 `->get()` calls run
unbounded. `->limit(…)` is used as a *silent cap on a picker list*, never as a page window.
Asynchrony: **2 job classes, 3 dispatch sites** — every report, export, PDF, payroll run and
payment-gateway call happens inside the HTTP request.

**Row-count model** (4 punches/day, ~250 working days/year, 3 years of history):

| Employees | `attendance_logs` | `payslips` (36 mo) | `/app/data` employee objects |
|---:|---:|---:|---:|
| 1,000 | 3.0 M | 36,000 | 1,000 × ~62 fields |
| 5,000 | 15.0 M | 180,000 | 5,000 × ~62 fields |
| 10,000 | 30.0 M | 360,000 | 10,000 × ~62 fields |

---

**PERF-1 · `/app/data` — the boot payload. `AppDataController::bootstrap()` :247-590.**
The endpoint every page load hits. It returns, with **no limit and no pagination**:
- every employee (`:296` `$empQ->orderBy('emp_code')->get()`), projected to ~62 fields (`:302-434`);
- every `employee_references` row for them (`:298-300`);
- every company, department, designation, branch, team, shift and salary schedule — **and
  `:274-291` fetch `departments`, `designations`, `companies`, `branches`, `teams` and
  `salary_schedules` with NO tenant filter at all**, so a shared-cloud tenant receives every other
  tenant's org names;
- **every `payroll_run` and every `payslip` the tenant has ever had** (`ensurePayrollHistory`
  `:2382-2427`), joined to employees and companies.

| Employees | `/app/data` response | Verdict |
|---:|---|---|
| 1,000 | 1,000 employees + ~36,000 payslip objects ≈ 30–60 MB JSON | 10–40 s, browser strains |
| 5,000 | ~180,000 payslip objects ≈ 150–300 MB JSON | **PHP `memory_limit` exhaustion → blank page** |
| 10,000 | ~360,000 payslip objects | **fails outright** |

Before any of that, `:252` calls `ensureEmployeeColumns()` — up to **28 `Schema::hasColumn`
round-trips to `information_schema` plus any `ALTER TABLE`** — and `:257` calls
`processDueBackups($tenantId)`, which **archives employees inside a page load**, recording as the
actor whoever happened to load the page.

**PERF-2 · `/app/data` fabricates payroll. `AppDataController::ensurePayrollHistory()` :2340-2381.**
For each of the last 12 months with no run, it inserts a run header and then loops
`foreach ($emps as $e)` issuing **one `INSERT` per employee**, no batching, no transaction, no
queue, inside the HTTP request.

| Employees | Inserts on a first load with an empty history |
|---:|---:|
| 1,000 | 12,012 |
| 5,000 | 60,012 |
| 10,000 | **120,012 single-row inserts in one web request** |

At 10,000 this cannot complete inside any web-server timeout. It will die part-way, having written
an arbitrary prefix of fabricated `status='paid'` payroll runs into the production `payslips`
table, and the existence check will then skip those months forever.

**PERF-3 · Payroll generation. `PayrollGenController::compute()` :1091-1490.**
`foreach ($emps as $e)` at `:1204`, and per employee it issues:
`presentDays()` (`:1211` → `:202-220`, `COUNT(DISTINCT log_date)` against `attendance_logs`),
`paidLeaveDays()` (`:1212` → `:1042-1084`, **plus an unfiltered `leave_types->pluck()` over every
tenant's rows on every call**), `dayStats()` (`:1244` → `:322-360`, pulls every punch of the month
for that employee), `presentDates()` (`:1330`), `sandwichDays()` (`:1263`), `loanEmiDue()`
(`:1356`), `advanceRecovery()` (`:1370`), `clawbacksDue()` (`:1377`), and
`esiPeriodLock()` (`:1306`, only when gross exceeds the ceiling — which is most staff at 10k
scale). Plus one `loans` re-read per recovered EMI at `:2793`.

| Employees | Queries per run (conservative, ≥6/employee) | Verdict |
|---:|---:|---|
| 1,000 | ≥ 6,000 | minutes; frequently times out |
| 5,000 | ≥ 30,000 | **times out; user re-clicks → C-1 duplicate run** |
| 10,000 | ≥ 60,000 | **cannot complete.** No queue, no progress endpoint, no `set_time_limit`. |

And before the transaction opens, `:2552-2617` executes up to **five `Schema::table` /
`Schema::create` DDL statements**. `ALTER TABLE payslips ADD calc_note` on a 360,000-row table
rebuilds it and holds a metadata lock blocking every query on `payslips` for the duration.

**PERF-4 · Attendance report. `AttendanceReportController::index()` :105-123.**
```php
$logs = DB::table('attendance_logs')->…->whereBetween('log_date', [$from, $to])->…->get();
foreach ($logs as $l) { $groups[$l->emp_code.'|'.$l->log_date][] = $l; }
```
Every punch in the range as PHP `stdClass` objects, **then a second full copy** in `$groups`.
There is **no date-range cap in the code** — the user can request three years.

| Employees | One month | Verdict |
|---:|---:|---|
| 1,000 | ~120,000 rows | ~150–250 MB PHP → borderline |
| 5,000 | ~600,000 rows | **blank page (memory exhaustion)** |
| 10,000 | ~1.2 M rows | **blank page** |

`::pdf()` (`:555-605`) takes the same unbounded set and hands it to dompdf, a pure-PHP renderer
that builds the entire DOM in memory. It will exhaust memory long before finishing at any of these
scales. The index that would make the query itself survivable —
**`attendance_logs (tenant_id, log_date)` composite** — does not exist; MySQL must choose between
`tenant_id` (a handful of distinct values) and `log_date` (one day = every tenant's punches).
Compounding, `DashboardController.php:102, 117` runs two `whereDate('log_date', …)` predicates
against the same table **on every dashboard load** — `whereDate` emits `DATE(log_date) = ?`, which
cannot use the `log_date` index at all.

**PERF-5 · `Audit::verify()` :105-130.** `DB::table('activity_logs')->orderBy('id')->get()` — the
**entire** audit table, no chunking, no tenant filter, no limit — then SHA-256 per row. Triggered
whenever an admin opens Activity Logs. `activity_logs` has no retention policy and no `created_at`
index. At 10,000 employees this table receives tens of millions of rows and this endpoint is a
guaranteed OOM. Separately, `Audit::record()` (`:91`) scans for the chain head **on every audited
write**, serialising the entire write path against the largest table in the schema.

**PERF-6 · `attendance:dedupe`.** `DedupeAttendance.php:41-45` — `->get()` on the whole
`attendance_logs` table, all tenants, then two PHP arrays. At 10,000 employees that is 30 M rows;
`$keep` alone is multiple gigabytes. It OOMs **after** `array_chunk($doomed, 500)` has already
started deleting (`:81-83`), leaving a half-deduplicated table with no transaction and no restore
path. The two SBB migrations run the same algorithm (chunked at 2,000, so the query is safer but
`$keep` still grows unboundedly) **on every `php artisan migrate`** — i.e. during every deploy.

**PERF-7 · Unbounded reads elsewhere (each is a screen that dies at a different scale).**

| Location | Loads | Dies at |
|---|---|---|
| `LeaveController.php:322` | **every employee of the tenant, all columns**, as a fuzzy-name fallback on leave apply | ~5,000 |
| `BillingController.php:88` | **every** invoice number of the FY from two tables, per invoice creation | grows forever |
| `AttendanceReportController::employeeMeta` | every employee **plus a second full `pluck('name','id')`** on the same table | ~5,000 |
| `RequestController::inbox` | ~10 unbounded queries (one per module), each a `leftJoin` with **no LIMIT**, plus an `ensureAuditCols()` schema check per module — **called by the SPA to compute an unread badge** | ~1,000 |
| `EmployeeExportController :246-320` | full employee set into an in-memory XLSX, then `file_get_contents($tmp)` reads the finished file back into a PHP string | ~5,000 |
| `AppStateController::show/save` | the tenant's whole 113-screen JSON blob, decoded on every load and encoded on every save; **the column type is not pinned in any migration**, and a `TEXT` column caps at 64 KB | any scale |
| `AppDataController.php:545` | archived employees at a hardcoded `limit(1000)` — archive #1001 is **invisible forever**, silently | 1,000 archived |
| `OnpremClientController::index` | all `licences` + all `onprem_payments`, grouped in PHP | platform scale |
| `ReportController::export` | the entire CSV as one PHP string | ~5,000 |
| `GreetingService.php:172-173` | `whereRaw("DATE_FORMAT(dob,'%m-%d') = ?")` — full-scans `employees` per tenant, hourly | ~5,000 |
| `SendMessageController::send` | loops recipients with `Http::timeout(15)` **each**, synchronously — a 500-recipient broadcast occupies a PHP-FPM worker for up to ~2 hours | any |

**PERF-8 · Screens that load entire tables into memory — direct answer.**
Attendance Report (screen **and** PDF); Activity Logs (`Audit::verify`); the SPA boot payload
`/app/data` (employees + all payslips + all runs); Approvals inbox; Employee Export; Reports
export; On-prem clients; the leave apply fallback path; and every Artisan dedupe path.

**PERF-9 · 738 `information_schema` round-trips.** `Schema::hasTable`/`hasColumn` appear **738**
times in `app/`; Laravel 11 does not cache them. `ApprovalService::safeRow()` calls
`Schema::getColumnListing()` **on every single row it writes** — measured at **98 call sites** —
so a 10,000-payslip run issues 10,000 `SHOW COLUMNS` queries on top of everything else.
`MasterController::ensureCols` runs `hasTable` + up to 23 `hasColumn` calls on every master list
and save. `PushController::ensure()` runs up to eight per `/iclock` hit. **There is no query
caching anywhere** — `Cache::` appears in `app/` only for update-download tokens, despite
`.env.example` setting `CACHE_STORE=redis`.

---

### 6.4 Q8 — CODE QUALITY METRICS FOR THE REBUILD ESTIMATE

**Total LOC: 81,592** (`app` 65,933 · `resources` 7,948 · `database` 4,409 · `config` 1,763 ·
`routes` 1,100 · `public` 439).

**God classes — top 10 by size, measured.**

| Lines | PHP methods | `DB::table` | `Schema::` | File | Responsibilities |
|---:|---:|---:|---:|---|---|
| **12,533** | **2** | 2 | 1 | `app/Http/Controllers/AppController.php` | **The entire SPA** — HTML + CSS + ~12,000 lines of JavaScript inside PHP heredocs from `:452`. Screen registry, per-screen renderers, permission matrix, plan→module gate, subscription banner, update panel. 2,855 lines contain DOM code. Not a controller. |
| 3,090 | 49 | 85 | 50 | `AppDataController.php` | SPA data feed **+** employee persistence **+** the archive due-processor **+** the payslip PDF **+** the payroll-history fabricator **+** `selfScope()`, an **authorisation primitive** other controllers call. |
| 2,845 | 36 | 62 | **88** | `PayrollGenController.php` | The payroll engine, **plus** its own period resolver, its own working-day calculator (two of them), its own runtime DDL, its own ledger-reversal logic, its own pay-date resolver. |
| 2,206 | 26 | 0 | 0 | `ScreenHelpController.php` | Pure static help copy in PHP arrays. Content in code; ~236 KB of arrays materialised per help request, uncached. |
| 1,929 | 26 | 58 | 31 | `RequestController.php` | Every request type **+** the commission ledger **+** bounce/clawback **+** the salary-payment ledger **+** runtime table creation. |
| 1,271 | 33 | 78 | 17 | `RecruitmentController.php` | highest `DB::table` density in the product |
| 1,224 | 13 | 29 | 27 | `MasterController.php` | one generic CRUD for ~30 master tables, driven by a `def($type)` array |
| 1,204 | 21 | 43 | 16 | `SignupController.php` | public signup + Razorpay + quote PDF + provisioning |
| 1,132 | 37 | 56 | 16 | `SelfOnboardingController.php` | 8 public unauthenticated endpoints |
| 1,031 | 35 | 50 | 13 | `BillingController.php` | pricing, invoicing, gateways, webhooks, renewals |

**Duplication hotspots (each is a place SmartPRS 3 must have exactly one of).**

| # | Duplicated concept | Copies | Do they agree? |
|---|---|---:|---|
| DUP-1 | Working-day / week-off calculation | 4 (`PayrollGenController::isWeekOff` :70-89, `::monthMeta` :170-198, `PointsController::monthMeta`, `AbsenceService` working_days array) | **NO** |
| DUP-2 | Punch date parsing | 4 (`PushController::parseDate`, `ETimeOfficeService::parseDate`, `PunchIngestService::parseNaiveLocal`, `SmarteptWebhook::toNaiveLocal`) | **NO — three different rules** |
| DUP-3 | "Present" derivation | 4 (`AttendanceReportController`, `PayrollGenController::presentDays`, `AbsenceService::presentEmpCodes`, `TodayController::me`) | **NO** |
| DUP-4 | Late-arrival policy resolution | 4 | unverified |
| DUP-5 | Financial-year derivation | 4 (`FinYearController:31-39`, `BillingController:65`, `routes/console.php:29-33`, `StatutoryController:125`) | today, yes |
| DUP-6 | Invoice / quote numbering | 4 (`nextInvoiceNumber`, `SignupController::quote`, `invoices:renumber-prs`, `BankPackController` which uses `count()+1`) | **NO** |
| DUP-7 | Razorpay order creation, **none with a timeout** | 6 | n/a |
| DUP-8 | PT slab table | 2 — `AppDataController` and a **hand-copied snapshot** in `tests/manual/pt_extract.php` kept in sync by a docblock instruction | by discipline only |
| DUP-9 | Attendance dedupe algorithm | 3 (`DedupeAttendance`, and verbatim in **two** migrations that both run) | yes, and all three are dangerous |
| DUP-10 | Runtime "ensure table/column" blocks | `SchemaHelper`, `ApprovalService::ensureAuditCols`, `NotificationService::ensureTable`, `Audit::ensure`, `SchemeController::ensure`, `DocumentController::ensure`, `SelfOnboardingController`, plus ad-hoc guards — **738 introspection calls, 156 DDL calls** | n/a |
| DUP-11 | Tenant-scope guard `when($tid, …)` | dozens, hand-written; **omitted** at `KbController:112`, `TestController:192`; **disabled whenever `$tid` is null** | **NO — a security control by copy-paste** |
| DUP-12 | Attendance summarisation loop | 2 (`AttendanceReportController::index` :137-161 and `::pdf` :575-583, pasted) | drift on any change |

**Dead code.** `EmployeeController`, `DepartmentController`, `AttendanceController`,
`LoanController`, `SalaryRunController` are `use`d at the top of `routes/web.php` but **bound to no
route** — so the Eloquent models they drive (`Attendance`, `SalaryRun`, `SalarySlip`,
`LeaveApplication`, `Loan`) are dead too: **5 of 11 models are unreachable.** The live system uses
raw tables via the query builder. `app/Services/PeriodResolver.php` — 323 lines, the documented
"single place that answers what dates a payroll month covers", with **9 of the suite's best unit
tests** — is consumed by a label call and an audit command; its own docblock admits *"Nothing in
this class touches the payroll engine yet."* **The best-tested payroll code in the product is not
wired to payroll.**

**Architecture.** `ApprovalService` is a 381-line service living in `App\Http\Controllers`.
`FinYearController::fyOf/current/active/range/stamp` are static domain helpers on an HTTP
controller. `SubscriptionService.php:111` calls `ClientUpdateController::seatLimit()` — **a service
depending on a controller**. `AttendanceReportController:104` calls
`AppDataController::selfScope()` — an authorisation primitive on a data-feed controller. There are
real controller↔service cycles, not theoretical ones.

**Consistency.** Table names are plural / singular / mixed (`employees`, `overtime`,
`notification_log`, `activity_logs`). Two names for one concept (`payroll_runs`/`payslips` live vs
`salary_runs`/`salary_slips` dead). Three keys for one entity (`employee_id`, `emp_code`,
`device_user_id`). Two response envelopes (`{ok, error}` in ~670 places vs Laravel's
`{message, errors}` in ~89). Five unrelated status vocabularies with no shared enum.

**Hardcoded values that a customer would reasonably want to change** — beyond the statutory
literals covered by other analysts: `ctc/12/26/8` for the OT hourly rate
(`PayrollGenController:2165`); ESI threshold `21000` inline at two sites (`:1306, :1643`);
flat-30 proration `30.0` at three sites (`:1277, :1621, :2042`); default shift `09:30–18:30`;
cut-off day range `2..28`; five different chunk sizes (`2000`, `500`, `400`, `200`, `50`); six
different HTTP timeout literals (`900`, `60`, `25`, `20`, `15`, `6`) plus **five Razorpay calls
with no timeout at all**; vendor contact details (`ejaz@ametecsindia.com`, WhatsApp `9000098877`)
baked into ~10 user-facing error strings; `smartprs.com` URLs in message templates, which break
any white-label deployment.

**Honest salvage assessment.**

| Asset | Verdict | Rationale |
|---|---|---|
| The 43 punch-ingest tests + `PunchIngestService`'s transaction-plus-two-unique-indexes pattern | **SALVAGE AS REFERENCE — the single most valuable artefact in the repo** | It is the only place the team demonstrably got concurrency right, and the tests are behavioural, not implementation-coupled. Port the *specifications*. |
| The PT state-slab table and its 7 tests | **SALVAGE AS DATA** | Move the slabs into configuration; keep the assertions as golden tests. Never re-import the hand-copied `pt_extract.php` pattern. |
| `PeriodResolver` + its 9 tests | **SALVAGE AS SPECIFICATION** | Well-tested, correct, and never wired up. In SmartPRS 3 it is the period engine. |
| `StatutoryConfig` scope-precedence rules (branch > location > company > state > tenant, effective-dated, per-kind) + its 8 tests | **SALVAGE AS DESIGN** | This is the closest thing in SmartPRS 2 to the configuration-first principle. The merge semantics are worth keeping; the implementation is not. |
| `EmployeeFieldRules` field/validation catalogue | **SALVAGE AS DATA** | A genuine single source of truth for field shapes. |
| `ScreenHelpController` help copy (2,206 lines) | **SALVAGE AS CONTENT** | Move to a CMS/table. Zero code value. |
| The business rules encoded in `PayrollGenController::compute()` | **SALVAGE AS REQUIREMENTS ONLY** | The three LOP bases, the sandwich rule, mid-month joiner proration, the recovery order, the "zero punches pays 100 %" safeguard, the ESI period lock — every one is a real customer requirement. **The code implementing them is a liability**; extract the rules, discard the file. |
| `AppController.php` (12,533 lines) | **LIABILITY — total loss** | The entire front end, untestable, unlintable, unbundleable, and carrying part of the authorisation model. |
| The 156 runtime-DDL sites | **LIABILITY** | Every one is a migration that was never written. |
| The 738 `Schema::has*` guards and the `safeRow()` pattern | **LIABILITY** | Defensive scaffolding for schema drift that SmartPRS 3 must not have; `safeRow()` in particular converts a schema bug into silent data loss at 98 sites. |
| The fail-open catch blocks (auth, seats, licence, payroll lock, audit) | **LIABILITY — must be inverted, not ported** | |

**Estimate guidance.** Of 81,592 LOC, roughly **12,500 (15 %)** is the SPA heredoc — a full
rewrite with zero reusable lines. Roughly **2,200 (3 %)** is help content — a data migration.
Roughly **8,000–10,000 (12 %)** across `PayrollGenController`, `StatutoryConfig`,
`EmployeeFieldRules`, `PeriodResolver` and the ingest services encodes real, hard-won Indian
payroll/attendance domain rules that must be **re-specified, not re-used**. The remaining ~70 % is
CRUD, defensive schema scaffolding and duplication. **Treat SmartPRS 2 as a requirements document
with a running reference implementation, not as a codebase to refactor.** The single strongest
argument for that: **fixing the concurrency and retention model in place would require touching
every one of the ~200 write paths, none of which is covered by a test.**

---

### 6.5 Q6 — DATA LIFECYCLE AND RETENTION, PER ENTITY

**Headline: SmartPRS has no retention policy of any kind.** No `Model::prune()`, no `model:prune`
in the scheduler, no rotation on any log table, no purge-on-exit, no purge-on-cancellation.

| Entity | Creation | Archive | Deletion | Retention | On employee exit | On tenant cancellation |
|---|---|---|---|---|---|---|
| `employees` | SPA save, CSV/XLSX import, recruitment hire, self-onboarding | `archived_at` + `archived_by` + a full JSON snapshot in `employee_archives.snapshot` (LONGTEXT) after a 3-day `backup_due_at` grace (`AppDataController:2495-2660`) | **soft** (`Employee.php:24`); `EmployeeController::destroy` is **bound to no route** | **NONE** — archived and soft-deleted employees retain Aadhaar/national ID, PAN, UAN, ESIC, bank account, IFSC, DOB and address **indefinitely**. The archive migration's `down()` explicitly refuses to drop the table. | Status → exited; **frees a licence seat**; **no data is removed, masked or anonymised** | nothing — there is no tenant-deletion path |
| `employee_archives` | archive flow | — | none | **NONE** — one LONGTEXT snapshot per archived employee, forever | grows | grows |
| `employee_references` | employee save | none | hard, delete-then-reinsert with no transaction (`:3021`) | **NONE** | retained | retained |
| `attendance_logs` | 4 ingest paths + corrections + bulk upload | none | `attendance:purge-demo` (source='demo' only), `attendance:dedupe`, and two migrations' inline dedupe | **NONE** — 30 M rows at 10,000 employees × 3 years, and nothing removes any of it | retained under `emp_code`, which is reusable | retained |
| `attendance_pending` | quarantine | none | `BiometricConfigController:688` on mapping | **NONE** | retained | retained |
| `payroll_runs` / `payslips` | `generate()` | none | **only** via regenerate's hard delete (`:2389-2390`) | **NONE**, and the wage register is **destroyable on demand** with no archived copy | retained | retained |
| `leaves`, `commissions`, `expenses`, `advances`, `loans`, `clawbacks`, `increments`, `transfers`, `exits` | `RequestController` | none | selective hard deletes; `IncrementController` correctly blocks deleting an approved/applied increment | **NONE** | retained | retained |
| `recruitment`, `interviews`, `job_openings`, `hiring_drives`, `hiring_drive_candidates` | recruitment | none | hard, manual 2-statement cascades, **no transaction** | **NONE — and this is the most exposed PII class in the product.** Rejected candidates' CVs, contact details, ID proofs and interview notes are kept indefinitely with no consent expiry and no bulk purge | n/a | retained |
| `documents`, `self_onboarding_docs` | HR upload (public disk) / portal upload (local disk) | none | `DocumentController:298-305` deletes the file then the row, with the `Storage::delete` in an **empty catch** — a failed unlink leaves an orphaned file that is unreachable *and* undeletable | **NONE** | **documents are not deleted on exit** | retained |
| `activity_logs` | `Audit::record`, 37+ sites | none | **no route, no command, no migration ever deletes from it** | **NONE** (arguably correct for an audit trail — but there is no partitioning and no `created_at` index, so it becomes the slowest table on the box, and `Audit::verify()` loads all of it) | retained | retained |
| `notification_log` | `NotificationService::send` | none | none | **NONE** — bodies contain employee names and attendance facts | retained | retained |
| `wa_log`, `mail_log`, `employee_import_jobs` | append-only | none | none | **NONE** | retained | retained |
| `licences`, `licence_devices`, `licence_events`, `onprem_clients` | `LicenseService::issue` | none | 4-table hard cascade, **no transaction** (`OnpremClientController:758-762`) — the licence **history**, the only proof of what was issued to whom, is deleted first | **NONE** | n/a | retained |
| `tenants`, `subscriptions`, `settings` | signup / `client:provision` | none | **there is no tenant-deletion path anywhere** | **NONE** — a churned customer's complete payroll and PII dataset stays live in the shared database forever | n/a | **nothing happens** |

**Assessment against Indian DPDP obligations — stated factually, no legal conclusions drawn.**

The DPDP Act 2023 establishes obligations around purpose limitation, storage limitation, erasure on
request and on purpose completion, consent records, and breach notification. Separately, several
Indian statutes set **retention floors** (wage registers, EPFO/ESIC registers, income-tax records).
The factual position of the code, without asserting what the law requires of Ametecs:

| Obligation area | What the code provides |
|---|---|
| Consent record | one column: `signups.terms_accepted_at` (`SignupController:220`). No per-data-principal consent record, no scope, no withdrawal. |
| Purpose / storage limitation | **no retention schedule, no maximum age, no purge routine for any entity.** |
| Erasure on request | **no right-to-erasure endpoint, no anonymisation routine, no masking routine.** Aadhaar/national ID, PAN, bank account and IFSC are stored in plaintext with no masking and no field-level encryption. |
| Portability / export-my-data | **none.** The only export is the archived-employee JSON snapshot, available to HR, not to the data principal. |
| Data-principal request handling | **none.** |
| Breach log | **none.** |
| Retention floors (statutory minimums) | **actively violated in one place**: payroll regenerate hard-deletes the prior wage register with no archived copy (`PayrollGenController:2389-2390`), and there is no mechanism to prevent an authorised user doing so. |
| Access control on stored PII | onboarding ID proof, address proof, bank proof, PCC and DRA certificates are copied to `storage/app/public/employee-docs/<sequential employee id>/<basename>` (`SelfOnboardingController:953-955`) — a **fully predictable path on an unauthenticated public disk with no tenant check.** Selfies, by contrast, are streamed correctly through an authenticated controller from the `local` disk. |
| Cross-border / processor obligations | out of scope for this analysis |

**The factual gap, stated plainly for the Product Owner:** the product can *collect* every category
of sensitive Indian personal data and has **no mechanism whatsoever** to delete it, mask it, export
it, age it out, or account for who consented to what. Whether that constitutes non-compliance is a
question for counsel; that the mechanism does not exist is a matter of source-code fact.

---

### 6.6 Q7 — DELETE AND ARCHIVE, RANKED BY DANGER

43 delete sites in `app/`, plus migrations and seeders. **Zero `forceDelete()`, zero `truncate()`.**
Ranked by (blast radius × ease of triggering × irreversibility × evidentiary value destroyed).

| Rank | Operation | Location | Evidence destroyed | Trigger |
|---|---|---|---|---|
| **D-1** | **Migration-embedded bulk delete of `attendance_logs`, no tenant filter, no dry run, no confirmation, no transaction** | `2026_08_16_000200_extend_attendance_logs_for_sbb.php:137-156` and the verbatim copy in `2026_08_17_000100_repair_sbb_schema.php:212-234` — **both run** | **Statutory attendance records for every tenant on the server.** Any two genuinely distinct punches sharing `tenant_id\|emp_code\|punch_at\|source` are collapsed permanently. `$keep` grows to the size of the whole table, so at real scale it OOMs mid-loop **having already deleted an arbitrary prefix**. | **Every `php artisan migrate` — i.e. every deploy.** |
| **D-2** | **Payroll regenerate: hard-delete of `payslips` + `payroll_runs` outside any transaction, followed by four best-effort reversals** | `PayrollGenController.php:2389-2466` | **The wage register for a month**, destroyed *before* the replacement exists. The only trail is a best-effort `activity_logs` insert (`:2372-2386`) whose own exception is swallowed. Each reversal is separately swallowed, so a partial reversal double-pays OT, double-recovers EMI, or leaves `commissions.locked_at` pointing at a deleted run. | one click on "Regenerate" |
| **D-3** | **`cleanOrphanCommissions` hard-deletes commissions + `commission_payments` + `commission_logs`** | `RequestController.php:1246-1259` | **Real money paid (the payment ledger) and the entire audit trail for it.** The orphan test is `leftJoin employees … whereNull('e.id')` — **a commission with `employee_id IS NULL` satisfies it**, so every unassigned commission is destroyed. The tenant filter is `when($tid, …)`, so when `$tid` is null (platform super-admin, and every self-hosted install) **the purge crosses every tenant on the server.** No confirmation, no dry run, no transaction, no restore. | one admin click |
| **D-4** | **Onboarding documents published to the public disk — an *un*deletable exposure** | `SelfOnboardingController.php:953-955`; deletion at `DocumentController.php:298-305` | ID proof, address proof, bank proof, PCC and DRA certificates on an unauthenticated predictable URL. The only removal path wraps `Storage::delete` in an **empty catch** and deletes the DB row regardless — a failed unlink leaves the PII file live on the web **with no record that it exists**. | routine onboarding + routine document deletion |
| **D-5** | **`DummyDataSeeder` falls back to wiping tenant #1** | `DummyDataSeeder.php:46-47` then hard-deletes 22 tables at `:80-93` | **The oldest customer's employees, org structure, branding, SMTP and 17 business tables.** `demo:reset` creates the demo tenant first so the scheduled path is currently safe; **any direct `db:seed --class=DummyDataSeeder` on a production box wipes tenant #1.** No transaction, five empty catches — a mid-run failure leaves the tenant emptied and not rebuilt. | one seeder invocation |
| **D-6** | **`app:clear-state`, `attendance:purge-demo`, `attendance:dedupe` default to ALL tenants** | `ClearAppState.php:30-35`, `PurgeDemoAttendance.php:29-33`, `DedupeAttendance.php:41-83` | `app_state` holds **"all 113 screens' collections"** — i.e. the live data for every screen not yet normalised into its own table, for **every tenant**. Its docblock calls itself *"Safe"*. `--tenant` is optional on all three; only `dedupe` has `--dry`. | one CLI invocation with a missing flag |
| **D-7** | **`MasterController::delete` degrades to a HARD delete on any master table lacking `deleted_at`, and skips the tenant filter when `$tid` is null** | `MasterController.php:1198-1204` | Masters include the **holiday calendar, late policies, shifts and pay cycles** — every one a payroll input. Deleting a shift silently reverts every employee on it to the `09:30–18:30` fallback on the next report and the next run. **No dependency check of any kind.** The same button soft-deletes some masters and hard-deletes others depending on a schema detail invisible to the user. | one master-screen click |
| **D-8** | **`SalaryRunController::generate` deletes all slips of an existing run with no status check** | `SalaryRunController.php:49-51` (`firstOrCreate` then `$run->slips()->delete()`) | Wipes a month's slips regardless of draft/approved/paid. Currently unreachable (no route bound) — **restore a route and it is live.** | any re-run of the same month |
| **D-9** | **Cross-tenant deletes with NO tenant filter at all** | `KbController.php:112`, `TestController.php:192` | Any tenant's admin can delete any other tenant's KB topic or assessment question by guessing an id. **IDOR → cross-tenant destruction.** | an id in a URL |
| **D-10** | **Four-table licence cascade, no transaction** | `OnpremClientController.php:758-762` | `licence_events` → `licences` → `onprem_payments` → `onprem_clients`. **The licence history — the only proof of what was issued to whom — is deleted first.** A failure after step 2 leaves payments referencing licences that no longer exist. No restore path. | platform admin |
| **D-11** | **`PointsController` bulk delete on a `LIKE` pattern** | `PointsController.php:76-80` | `where('source_ref','like','auto:'.$month.'%')` with `when($tid && hasColumn)` — a platform user (null `$tid`) recomputing points erases **every tenant's** auto points for that month. `LIKE` on an unindexed text column also table-scans. | a points recompute |
| **D-12** | **Two-step cascades with no transaction** | `RecruitmentController.php:428-430`, `HiringDriveController.php:263-264` | Parent deleted first; a failure between statements leaves orphaned `interviews` / `hiring_drive_candidates` holding **candidate PII with no parent to find them by** — undeletable through the UI, invisible to every screen, retained forever. | routine candidate deletion |
| **D-13** | **`RecruitmentController::deleteJobOpening`, no dependency check** | `:1264` | Candidates keep a `job_id` pointing at a deleted opening. | routine |

**Restore paths.**

| Mechanism | Exists? |
|---|---|
| Soft-delete restore for employees / departments | the `deleted_at` column is set, but **no `restore()` call and no un-delete route exist anywhere** |
| Un-archive an employee | **no endpoint.** (`EmployeeImportService.php:412-415` clears `archived_at` as a side effect of re-importing the person.) |
| Archived-employee data download | yes — the JSON snapshot |
| Payroll run restore | **none** |
| Commission / ledger restore | **none** |
| Tenant restore | **none** |
| Database-level restore | nightly `spatie/laravel-backup` at 02:00 — **the only real restore path for D-1, D-2, D-3, D-5 and D-6** |

**The recovery story for every dangerous delete above is "restore last night's backup", i.e. lose
up to 24 hours of payroll and attendance for every tenant on the box.** There is no evidence in the
repository that a restore has ever been tested.

---

## 7. Security findings

Confined to findings this area surfaced; the security analyst's file is authoritative overall.

| ID | Finding | Evidence | Exploitability | Business impact | Severity |
|---|---|---|---|---|---|
| **S-1** | **Authorisation fails open at 71 sites** | `ApprovalService.php:377-379` | Any exception path — a missing pivot, a dropped connection, a corrupt permission cache — grants the request. No test provokes it. | Privilege escalation across every guarded write in the product | **CRITICAL** |
| **S-2** | **`POST /app/state` has no role check at all** and overwrites the tenant's entire working data set | `AppStateController.php:39-53`; route `routes/web.php:417` behind `auth` only | Any authenticated user — including a plain employee — can POST an arbitrary blob and replace every screen's collections for the whole tenant | Total tenant data loss, silent, unattributable, no audit entry | **CRITICAL** |
| **S-3** | **Onboarding ID/bank/address proofs on an unauthenticated public disk at a predictable path** | `SelfOnboardingController.php:953-955` (`employee-docs/<sequential id>/<basename>`) | Enumerate `employee_id` from 1 | Every tenant's onboarding identity documents readable by anyone | **CRITICAL** |
| **S-4** | **Cross-tenant write via an upsert whose match key omits `tenant_id`** | `AttendanceReportController.php:501-503`; the table's unique index also omits it | Two tenants sharing an `emp_code` on the same date | One tenant silently overwrites another's attendance rating and remarks | **HIGH** |
| **S-5** | **Cross-tenant destructive IDOR** | `KbController.php:112`, `TestController.php:192` — `where('id', $id)->delete()` with only a role check | Guess an id | Any tenant admin deletes another tenant's content | **HIGH** |
| **S-6** | **Tenant scoping is disabled whenever the acting user has a null `tenant_id`** | `MasterController.php:1198`, `RequestController.php:1249`, `PointsController.php:80` and dozens more `when($tid, …)` | Platform super-admin, **and every self-hosted install where users carry no tenant** | Deletes and updates intended for one tenant execute globally | **HIGH** |
| **S-7** | **The audit chain is forkable and self-silencing** | `Audit.php:90-99` — no lock, global head, all exceptions swallowed | Ordinary multi-tenant concurrency | Integrity control produces false tamper alarms; a genuinely dropped row is indistinguishable from a fork | **HIGH** |
| **S-8** | **Seat and licence checks fail open to unlimited on any error** | `SubscriptionService.php:116-118`; six further fail-open catches across `LicenseGate`, `AuthController`, `EditionGuard`, `EnsureSubscriptionActive`, `PayrollLock` | Any transient DB error | Revenue leakage; licence controls bypassable by a retry | **HIGH** |
| **S-9** | **The four-eyes control on salary runs is satisfied by a single `admin`** | `SalaryApprovalController.php:59-68` | The default workspace-owner role | One person prepares, approves and disburses a payroll run alone | **HIGH** |
| **S-10** | **`safeRow()` converts a schema bug into silent data loss at 98 sites** | `ApprovalService.php:263-266`; deliberately relied upon at `PayrollGenController.php:2545` | An install behind on schema | A payroll run silently loses its period boundaries and nobody is told | **MEDIUM** |
| **S-11** | **Payroll `generate()` returns errors with HTTP 200** | `PayrollGenController.php:2843` — `return response()->json(['ok'=>false,'error'=>$e->getMessage()], 200)` | any failure | Monitoring, proxies and clients cannot distinguish a failed payroll run from a successful one; the raw exception message is also returned to the browser | **MEDIUM** |
| **S-12** | **Six runtime-DDL sites reachable from unauthenticated endpoints** | `/iclock` → `PushController::ensure()` and the `SchemaHelper` chain | Anonymous HTTP | An unauthenticated caller can drive `ALTER TABLE` / `CREATE TABLE` and the metadata locks they take | **MEDIUM** |
| **S-13** | **`BCRYPT_ROUNDS=4` in `phpunit.xml`** with self-onboarding OTPs and API keys hashed via the same helpers | `phpunit.xml:20` | only if the value leaks into a real environment; no `.env.testing` exists to isolate it | weak hashes if the testing env is ever reused | **LOW** |

---

## 8. Documentation-vs-source discrepancies

| # | Doc claim | Source says | Verdict |
|---|---|---|---|
| **D-1** | *"`database/factories/UserFactory.php`… is the only factory in the repository"* (§20 1.4) and `composer.json` autoloads `Database\Factories\` from `database/factories/` | **`database/factories/` does not exist.** `database/` contains only `migrations/` and `seeders/`. | **Doc wrong.** Worse than stated: the PSR-4 autoload maps a namespace to a missing directory, and `fakerphp/faker` is a dev dependency with nothing to serve. |
| **D-2** | §20 §5 rank 13: *"`package.json` is the Vite build chain only"* | **There is no `package.json` and no `vite.config.js`.** There is no JS build step at all — `resources/` contains 59 Blade files and one CSS file; the SPA lives entirely inside `AppController.php` heredocs. | **Doc wrong. Worse in source.** |
| **D-3** | §6.9 C1: *"There is no unique index on `invoices.number`"* | `2026_01_01_000001_create_companies_table.php:59` — `$t->string('number')->unique();` **It IS unique.** §5 rank 7 of the same document says the opposite and is correct. | **Doc self-contradictory.** Source resolves it: a same-table race **throws** (500, no invoice); the silent-duplicate risk is real only across `invoices` ↔ `onprem_clients.invoice_no`, which has no index. |
| **D-4** | §6.1: `AppController.php` has *"40 PHP methods"* | **2.** (`grep -cE "^\s+(public\|private\|protected)\s+(static\s+)?function"` = 2; the ~2,060 `function` tokens are JavaScript inside the heredoc.) | **Doc wrong; source is more extreme.** |
| **D-5** | §5.1 F3: *"674 runtime schema-introspection calls"* | **738.** | Doc understates. |
| **D-6** | §5.1 F1: *"166 `->get()` calls"* | **308** `->get(` occurrences. | Doc understates by ~85 %. |
| **D-7** | §12 C16: *"146 `Schema::create`/`Schema::table` calls"* | **156** (`Schema::create\|table\|dropIfExists` in `app/`). | Doc understates. |
| **D-8** | §6.9: *"5 transaction sites in 3 files"* / §12: *"five transaction sites"* | **4** (`PunchIngestService:72`, `EmployeeImportService:402`, `PayrollGenController:2620`, `routes/console.php:126`). | Minor; the conclusion stands and is if anything stronger. |
| **D-9** | §20 §5 rank 12 / `FINDING-SEC-02`: *"the demo seeder falls back to wiping the first tenant… when no `subdomain='demo'` tenant exists"* — framed as reachable via `demo:reset` | `DemoReset::handle()` **creates** the demo tenant at `:56-66` before invoking the seeder, so the scheduled path cannot hit the fallback. The fallback at `DummyDataSeeder.php:46-47` is real and dangerous, but only via a **direct** seeder invocation. | **Doc overstates the trigger, understates nothing else.** Record the correct trigger. |
| **D-10** | §11 P1: *"~1.5 million `attendance_logs` rows"* at 2,000 employees (3 years × ~250 working days) vs §5 of the other doc: *"~8.8 M rows"* (365 days) | Both are models, not measurements, and they differ 6×. | **Docs inconsistent.** This analysis states its own model explicitly (§6.3). |
| **D-11** | §20 §2 notes the brief named `tests/Feature/ExampleTest.php` and `tests/Unit/ExampleTest.php` and that neither exists | Confirmed — the skeleton examples are deleted. | **Doc correct**, recorded so the absence is not mistaken for an oversight. |
| **D-12** | Nothing in either document records that **`/app/data` fabricates 12 months of payroll and that the first page load of a month wins permanently**, nor that the payload's org lookups are un-tenant-filtered | `AppDataController.php:2340-2381` and `:274-291` | **Docs miss it.** (`SECURITY…§12 C14` records the role-scoping half; neither records the fabrication itself as a data-integrity defect, nor the missing tenant filters.) |
| **D-13** | Nothing records the **single-device punch direction re-stamp race** | `ETimeOfficeService.php:620-680` | **Docs miss it entirely.** New: race C-16. |
| **D-14** | §20 rank 6 describes the four-eyes control as working for `hr_manager` | True, but **`admin` satisfies both `isHR()` and `isFinance()`**, and `admin` is the default customer workspace-owner role | **Doc understates.** The control is effectively absent for the most common role. |
| **D-15** | Nothing records that **`advances` recovery is never stamped** | `PayrollGenController.php:869-885` — no `recovered_run_id`/`recovered_at` on `advances`, unlike loans and clawbacks | **Docs miss it.** |
| **D-16** | Nothing records that **`tenants.seats_used` is never recomputed** | measured: written only as `0` at creation (`SaasController:150`, `InstallController:197`, `ClientProvision:71`) and `36` in the demo seeder; read at `AppDataController:441` and `SaasController:73` | **Docs miss it.** The SaaS panel's headcount is permanently wrong. |

---

## 9. Migration verdict per capability

| Capability | Verdict | Rationale | Data-migration implication |
|---|---|---|---|
| Automated test suite (132 cases) | **REPLACE**, harvesting specifications | 43 ingest cases and the PT/period/statutory units are excellent *specifications*; every one is coupled to SmartPRS 2's raw-table shapes. Port the assertions, not the code. | none |
| Test fixture layer (`tests/Pest.php`) | **REPLACE** | Raw `DB::table()->insertGetId()` bypasses models, casts and events; `makeUser` creates permissionless roles; `makePlan` performs DDL. | none |
| Manual verification scripts | **REMOVE** | `pt_extract.php` is a hand-copied statutory table kept in sync by a docblock. Fold `check_statutory_config.php`'s reflection technique into real tests. | move the PT slabs to configuration |
| CI / static analysis / coverage | **CREATE (does not exist)** | Nothing to migrate. | none |
| Test database strategy | **REPLACE** | Must run on the production engine. | none |
| Concurrency model | **REPLACE ENTIRELY** | Zero locks, four transactions, no optimistic concurrency, no idempotency keys, no unique constraints on any of the seven sequences and counters that need them. | back-fill the missing unique indexes before any data migration; **de-duplicate `payroll_runs`, `payslips`, `payments` and `onprem_clients.invoice_no` first** |
| Performance / data-access model | **REPLACE ENTIRELY** | No pagination, no queue, 738 introspection calls, 156 runtime DDL sites, 103 N+1 sites. | none, but see index back-fill above |
| Retention / lifecycle | **CREATE (does not exist)** | No policy of any kind. | a retention policy must be defined **before** migrating PII, so the migration itself can apply the first purge |
| Delete / archive semantics | **REDESIGN** | Soft delete on 2 of 11 models and not honoured on the 308 query-builder paths; 13 dangerous delete paths; no restore for anything except a nightly backup. | decide the fate of soft-deleted and archived rows **before** migration; they carry full PII |
| Employee archive (`employee_archives` snapshots) | **MIGRATE as historical data, REDESIGN the mechanism** | The business need (retain an ex-employee's record without occupying a seat) is real; a LONGTEXT JSON blob with no un-archive path is not the answer. | migrate snapshots into a proper archival store with a retention clock |
| Audit trail (`activity_logs` + hash chain) | **REDESIGN, migrate the rows** | The intent is right; the implementation is forkable, global-headed, unindexed, unbounded and self-silencing. | migrate rows as historical evidence; **do not migrate the chain** — it will not verify |
| `app_state` blob | **REMOVE** | Every collection in it must become a real table with real constraints. | **highest-risk migration in the project**: it holds live data for screens that were never normalised, in an unversioned JSON blob whose column type is not pinned |
| `PeriodResolver` + tests | **RETAIN as specification** | Correct, well-tested, and never wired up. | none |
| `StatutoryConfig` scope precedence + tests | **RETAIN as design** | The nearest thing in SmartPRS 2 to configuration-first. | migrate override rows |
| Punch-ingest transaction + unique-index pattern | **RETAIN as the reference pattern** | The one place concurrency is correct. | none |
| `AppController` SPA | **REMOVE** | 12,533 lines, 2 PHP methods, no build chain, carries part of the authorisation model. | none |
| `ScreenHelpController` copy | **MIGRATE as content** | 2,206 lines of help text in PHP arrays. | move to a content table |
| Dead controllers and 5 dead models | **REMOVE** | Unreachable. | none |
| Destructive Artisan commands | **REDESIGN** | Tenant scope and confirmation must be mandatory; dedupe must be set-based SQL, not a PHP array. | none |
| Migration-embedded attendance dedupe | **REMOVE** | Runs on every deploy, cross-tenant, unbounded, non-transactional. | run **once**, offline, set-based, with a verified backup, before migrating |

---

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
