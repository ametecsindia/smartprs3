# PART I — SMARTPRS 2 UNDERSTANDING

Status: DRAFT FOR PRODUCT OWNER APPROVAL
Owner: Ametecs India Private Limited
Conforms to: `SPINE.md` (the binding architectural contract)
Evidence base: the fourteen as-is forensic analyses under `/blueprint/analysis/`

---

## I.0 Purpose

This part establishes, on evidence, **what Ametecs actually owns today**. It is the shared
factual baseline that every later part of this blueprint argues from: the product, its
technology, its real module inventory, its actors, the operational chain it automates, the
vertical it is sold into, the commercial model as implemented, and — at length — **the things
SmartPRS 2 does well that the rebuild must not lose.**

It is deliberately fair. Part II makes the case for the rebuild. This part does not. Where a
capability works, it says so. Where a claim in the product's own documentation is not supported
by the source, it says that too, because the Product Owner is about to fund decisions against
this picture and a flattering baseline would produce a wrong plan.

Every factual claim traces to a `file.php:line` citation carried through from the analyses. No
SmartPRS 2 behaviour is asserted here that was not read in source.

---

## I.1 Product overview and the business problem

### I.1.1 What SmartPRS 2 is, in one paragraph

SmartPRS is a vertical HR / payroll / attendance / workforce-compliance platform built by Ametecs
India Private Limited (Hyderabad) and sold to **Indian debt-collection and recovery agencies**.
Its product thesis is that a recovery agency carries **two compliance burdens on the same person
record** — the ordinary Indian labour and tax burden (PF, ESI, PT, TDS, gratuity, bonus) and the
RBI recovery-conduct burden (DRA certification, police verification, background verification,
code-of-conduct undertaking, bank authorisation, borrower complaints) — and that one system should
hold both, so that the biometric punch which decides an agent's Loss of Pay and the DRA
certificate which decides whether that agent may work at all live in the same database and can be
evidenced from the same audit report.

It ships from **one codebase in two commercial shapes**: hosted multi-tenant SaaS billed per
employee seat, and an on-premise perpetual licence in three nominal editions (L1 / L2 / L3) with
an annual maintenance contract.

**Verified at source:** `DemoCompanySeeder.php:39-53` (`companies.type = 'collections'`, "Demo
Collections Group" / "Apex Collections Pvt. Ltd."); `config/smartprs.php:12,22` (the two
deployment switches); `Edition.php:101-118` (the `DNA` nav set *is* the collections feature list);
`MasterController.php:301-320` (escalations / agent-auth / dra-certs registries);
`ComplianceController.php:541-660` (the RBI agent audit report).

### I.1.2 The business problem, stated from the code

The code demonstrably attacks five problems. Each is stated only where a code artefact proves the
intent.

| # | Problem | How the code attacks it | Evidence |
|---|---|---|---|
| 1 | **Attendance truth for people who are not at a desk** | Seven ingest paths converge on one `attendance_logs` table: ZKTeco/eSSL ADMS push, the authenticated SBB batch API, the SmartEPT HMAC webhook, eTimeOffice cloud pull, eTimeTrackLite SOAP, a generic API connector, and in-app/mobile self-punch — plus bulk CSV upload with an approval gate and correction/regularisation | `routes/web.php:92-105, 352-361, 586-590`; `routes/api.php:35, 38-41`; `config/smartprs.php:114-122` |
| 2 | **Payroll correctness under Indian statute** | PF / ESI / PT / TDS / LWF computed in code against a tenant-editable rate surface | `routes/web.php:349-350, 580-584`; `app/Services/StatutoryConfig.php` |
| 3 | **Certification expiry** | `compliance:scan` runs daily at 07:30 over `employees.dra_expiry` / `pcc_expiry` and the structured `dra_certs` register, bucketing expired / critical / soon and emailing the tenant's recipients | `routes/console.php:143-146`; `ComplianceController.php:37-130` |
| 4 | **Churn** | Requisition → sourcing → hiring drive → interview → offer (public token page) → NDA e-sign → self-onboarding portal → HR verification console → employee row | `routes/web.php:58-74, 607-687` |
| 5 | **Multi-entity operation** | One tenant → many companies; the oldest is auto-flagged `is_master`; transfers move people between companies under approval and are applied on the effective date by `transfers:apply` daily at 06:30 | `routes/console.php:190-194` |

### I.1.3 What the product deliberately does not do

This is a scoping fact SmartPRS 3 must either inherit or consciously reverse.

- **No collections.** There is no borrower, no account, no allocation, no dialler, no disposition.
  The collected amount enters only as free text on a commission claim, and the bank/portfolio is
  the free-text `portfolio` field. `BankPackController.php:24-27` states this explicitly. Ametecs
  sells a separate product, SmartDCM, for collections.
- **No accounting.** No general ledger. `commission_payments` and `bank_invoices` are
  single-purpose disbursement and billing registers.
- **No statutory filing.** Registers and challan-ready PDFs are produced; the filing is manual.
  **Confirmed at source:** the complete `Schedule::` list in `routes/console.php` is twelve tasks
  — `compliance:scan`, `backup:clean`, `backup:run`, `errors:digest`, `billing:alerts`,
  `transfers:apply`, `demo:reset` ×2, `attendance:sync-etimeoffice`, `greetings:send`,
  `probation:notify`, `absence:notify` — and **not one concerns a statutory filing due date.**

---

## I.2 Technology baseline

### I.2.1 Runtime and dependencies — exact versions

Read in full from `composer.json`.

| Component | Declared constraint | Notes |
|---|---|---|
| PHP | `^8.3` | The L3 on-prem build target pins **PHP 8.2**. Shared code must in practice remain 8.2-compatible with nothing enforcing it (`N` D-16). |
| `laravel/framework` | `^11.0` | Laravel 11. `bootstrap/app.php` is 46 lines; the `withExceptions` closure is **empty**. |
| `barryvdh/laravel-dompdf` | `^3.0` | 17 PDF generation sites; every one streams, none writes to disk. |
| `laravel/sanctum` | `^4.0` | Installed, `personal_access_tokens` migrated, **no route uses `auth:sanctum`** (`G` D-21). |
| `laravel/tinker` | `^2.9` | — |
| `livewire/livewire` | `^3.5` | **Zero Livewire components anywhere** (`N` D-17). |
| `maatwebsite/excel` | `^3.1` | XLSX export is in fact hand-built in browser JavaScript via SheetJS from a CDN. |
| `predis/predis` | `^2.2` | Both env templates set `QUEUE_CONNECTION=redis`; `config/queue.php:16` defaults to `database`; the shipped migrations are the **database** driver's tables. |
| `rats/zkteco` | `^002.0` | Direct LAN ZKTeco pull. |
| `spatie/laravel-activitylog` | `^4.8` | `activity_log` (singular) is created, migrated and **never written**. The application writes its own `activity_logs` (plural). |
| `spatie/laravel-backup` | `^9.1` | Nightly zip to `storage/app` — **the same server it backs up**. |
| `spatie/laravel-permission` | `^6.9` | 13 permissions seeded; **zero** `can()` / `Gate::` / `authorize()` / `permission:` checks anywhere (`G` §0.4). |
| dev: `pestphp/pest ^3.0`, `larastan/larastan ^2.9`, `laravel/pint ^1.16`, `mockery ^1.6`, `fakerphp/faker ^1.23`, `laravel/sail ^1.29` | — | No `phpstan.neon`, no `pint.json`, no `docker-compose.yml`, no `database/factories/`. Mockery and Faker are unused. `composer analyse` has never been runnable. |
| Database | MySQL 8 in production; `config/database.php:13` **defaults to SQLite** | A deploy missing `DB_CONNECTION` silently creates an empty SQLite file and the runtime schema guards rebuild ~150 tables in it (`J` SEC-DB-07). |
| Test database | SQLite `:memory:` (`phpunit.xml:33-34`) | Structurally cannot reproduce the MySQL ENUM/length defect class the widening migrations exist to fix (`M` §1.5). |

### I.2.2 Repository shape — measured

All figures from `M` §0.4, reproducible by the commands recorded there.

| Measure | Value |
|---|---:|
| Total LOC (`app` + `resources` + `routes` + `database` + `config` + `public`) | **81,592** |
| `app/` LOC | 65,933 |
| `resources/` LOC | 7,948 |
| `database/` LOC | 4,409 |
| `config/` LOC | 1,763 |
| `routes/` LOC | 1,100 |
| Controllers | 84 (+2 API controllers) |
| Services | 32 |
| Console commands | 18 |
| Middleware | 6 |
| Eloquent models | 11 |
| Jobs | 2 |
| Route declarations | **422–432** (422 by one count; 432 including the ten generated legacy redirects) |
| Migrations | 49 files, ~100 tables |
| **Tables created at RUNTIME by application code** | **59 distinct names** via explicit `Schema::create`, plus **any of the 57 `MasterController::def()` registry types** via a generic self-creator |
| Blade views | 59 (`resources/` contains only `css`, `prototype`, `views` — no `resources/js`) |
| Test files / declared cases / assertions | 17 / **132** (137 executed) / 446 |
| `DB::table()` call sites in `app/` | 1,375–1,413 |
| `Schema::create` / `Schema::table` / `dropIfExists` **inside `app/`** | **156** |
| `Schema::hasTable` / `hasColumn` guard calls | **738** |
| `DB::transaction` / `beginTransaction` sites | **4** |
| `lockForUpdate` / `sharedLock` / `Cache::lock` sites | **0** |
| `->paginate()` / `->simplePaginate()` sites | **0** |
| Foreign keys on business tables | **0** (the only 5 are in the vendor Spatie migration) |
| Typed PHP `catch` blocks | **843**, of which **426 (50.5%) destroy the exception entirely** |

### I.2.3 The three god classes

| Lines | PHP methods | File | What it is |
|---:|---:|---|---|
| **12,533** | **2** | `app/Http/Controllers/AppController.php` | The **entire front end**. From `:452` onward it is one `<<<HTML` heredoc containing ~12,000 lines of JavaScript, spliced into a 234 KB static HTML prototype (`resources/prototype/app.html`). Carries the screen registry, per-screen renderers, the permission matrix, the plan gate and the edition gate. |
| 3,090 | 49 | `AppDataController.php` | The SPA data feed **and** employee persistence **and** the payslip PDF **and** the payroll calculator **and** `selfScope()`, an authorisation primitive other controllers call. |
| 2,845 | 36 | `PayrollGenController.php` | The payroll orchestrator, plus its own period resolver, two working-day calculators, its own runtime DDL and its own ledger-reversal logic. |

`ScreenHelpController.php` (2,206 lines) is a fourth outlier but is pure content — 120 screens'
worth of help copy in PHP arrays, with no `DB::`, `Auth::`, `Schema::` or `config()` call anywhere
in it.

### I.2.4 What is absent from the analysed tree — evidence gaps

Recorded because they bound the confidence of everything above.

| Absent | Consequence |
|---|---|
| `bootstrap/` (in the copy read by the security analyst) | Middleware aliases and the global stack could not be verified there; the operations analyst *did* read `bootstrap/app.php` (46 lines) in a later pass. |
| `public/.htaccess` | `public/` contains only `index.php` and `web.config` (IIS). On a Linux/Apache SaaS deployment there is **no version-controlled deny rule for `/storage`**. |
| `deploy/` (cron file, Supervisor/systemd worker units, nginx config) and `06_Build-and-Release-Scripts/` | **The entire operational runtime of SmartPRS 2 lives outside the application repository.** Nothing in a release enforces or verifies that the scheduler and queue worker are installed. |
| The `PKG:` client-packaging tree (`env.client.template`, `ClientBuilder.bat`, `smartprs-config.json`) | The reported shared licence secret and the reported client super-admin password generator could not be confirmed. |
| ~25 referenced Blade views (`emails.generic`, `login`, `dashboard`, `landing`, `policies.*`), `resources/views/voucher-pdf.blade.php`, `resources/views/devices/*` | Several are referenced by live routes; `voucher-pdf` in particular makes `GET /app/salary-lines/{id}/voucher` throw for every disbursed line. |
| `config/dompdf.php` | Effective `isRemoteEnabled` / `chroot` / `enable_php` cannot be established from source. |
| A production `mysqldump --no-data` | **No live schema has ever been captured.** Given 59 runtime-created tables and at least eight with competing definitions, the deployed schema is not derivable from source. This blocks every data-migration estimate. |

---

## I.3 Actors and roles as they really are

### I.3.1 Every actor the product serves

| Party | What they are | Where they live in code |
|---|---|---|
| **Ametecs platform staff** | The vendor. Sell, provision, license, support, publish releases. | `users.tenant_id IS NULL`; `/admin/staff` (`routes/web.php:290-293`) |
| **The subscribing agency (tenant)** | The commercial customer. Owns the workspace. Data Fiduciary under DPDP. | `tenants` row; `users.tenant_id` |
| **A company inside the tenant** | A registered legal entity in the promoter's group. Payroll, GSTIN, branding and payslips are per company. | `companies` row |
| **Agency HR / admin staff** | Day-to-day operators. | roles `admin`, `hr_manager` |
| **On-roll field force** | Recovery agents with logins. | role `field_agent`; `employees` |
| **Off-roll field force** | Vendor / commission agents, **not on payroll**, no login, token link only. | `offroll_agents` (runtime-created, `OffrollAgentController.php:34-64`) |
| **Back-office employees** | Telecallers, ops, support. | role `employee` |
| **Accounts / finance** | Confirms money received before commission approval. | role `accountant` — **assignable, never created** (§I.3.3) |
| **Client banks / auditors** | **Not users.** They are the audience for a PDF. | `ComplianceController::agentAuditPdf()`; `BankPackController` |
| **Candidates / new joiners** | **Not users.** Token links only. | `routes/web.php:58-74, 120` |
| **Prospects / demo visitors** | Pre-purchase. Passkey-gated shared demo. | `routes/web.php:126-159` |

Buyer profile, taken from the lead form: company, designation, city, mobile, official email,
headcount band (1–25 / 26–75 / 76–150 / >150), and "what challenges are you facing". Currency
INR, `money_scale` 2, `Asia/Kolkata` (`config/smartprs.php:130-131`).

### I.3.2 How well each role is actually served

| Role | What they do | How well the system serves them today |
|---|---|---|
| `super_admin` | The vendor. Sells, provisions, licenses, supports, publishes releases, issues licence keys, records offline payments, manages coupons and the landing CMS. | **Well served.** Exclusive `/super` portal, a platform dashboard, cross-tenant scope, a complete SaaS console. **But** the SPA shell's own nav filter gives Super Admin **only** the 9 platform screens plus `ai-assistant` — no tenant screen appears in their sidebar, though every endpoint remains reachable by URL. |
| `admin` | The agency's MD or Operations Head. Owns the workspace, the subscription, the companies, the users, the final payroll approval, the licence code. | **Well served, over-privileged.** Sole holder of final payroll approval, bulk employee delete, bulk attendance delete, licence-code entry, subscription renewal and sign-in during lock-out. Bypasses the client permission matrix unconditionally (`AppController.php:769`) and the shell's role filter (`roleAllows()` returns `true` for `'Admin'`). |
| `hr_manager` | The software's centre of gravity. Hires, onboards, verifies DRA/PCC, maintains the registers, clears pending punches, chases absences, decides leave and corrections, generates payroll, produces statutory and compliance outputs. | **The best-served role in the product.** 86 of 107 nav screens; present in 63 of 70 `denyUnlessRole` lists. Cannot do final payroll approval, bulk deletes, or the Accounts confirmation step. |
| `field_agent` | The person the whole RBI regime is about. Punches, claims commissions, holds a DRA certificate and PCC, is authorised to a bank portfolio, is the subject of the audit PDF. | **Poorly served and structurally invisible.** Its seeded permission set is `attendance.view` — **byte-identical to `employee`**. It is **named in zero server write guards**. Its one functional effect is knowledge-base article filtering. |
| `employee` | Telecallers, back-office, support. Self-service only. | **Adequately served** for its narrow purpose. 13 screens. One explicit `denyUnlessRole` inclusion (document self-upload); everything else is self-scope. |
| `accountant` | The money-verification control: confirms collections actually arrived before a commission is approved. | **Not served at all.** See §I.3.3. |
| `platform_support` | Ametecs support staff. Intended as the least-privileged platform role. | **Wrongly served.** Created with `tenant_id = NULL`, which is the *widest* data scope in the product; checked by **zero** code paths; labelled "Admin" in the SPA. |
| `manager` (reporting manager) | Approves their team's leave, expenses, corrections, probation. | **Served as data, not as a role.** The tier the entire approval engine routes through is `employees.reporting_manager_id`, a column — not a role. It cannot be granted or revoked. |
| `hr` | A generic HR user distinct from `hr_manager`. | **Phantom.** Checked in three places, creatable nowhere. |
| `auditor` / `recruiter` / `supervisor` / "Team Leader" | Job functions named in help text, the FAQ and `ApprovalService`'s docblock. | **Not roles.** The audit *audience* (a bank, an auditor) is deliberately not a user — they receive a PDF. |

### I.3.3 The role reality check — six vocabularies that do not coincide

```
config/smartprs.php:88-94    super_admin  admin  hr_manager  field_agent  employee
    ("locked" — and read by NOTHING; grep "smartprs.roles" → 0 hits outside the file)

RolePermissionSeeder:41-58   super_admin  admin  hr_manager  field_agent  employee

UserController::ASSIGNABLE                admin  hr_manager  field_agent  employee  accountant
StaffController::ROLES       super_admin                                            platform_support

prototype ROLES map                              HR Manager  Field Agent  Employee
    (no Admin key — roleAllows() short-circuits; no Accountant key → falls through to `true`)

Actually checked in app/     super_admin  admin  hr_manager  (field_agent: 0)  employee
                             accountant   manager  hr        (platform_support: 0)
```

The **entire server-side role vocabulary of the write guard is four lists across ~70 call sites**:
`['admin']`, `['admin','hr_manager']`, `['admin','hr_manager','accountant']`,
`['admin','hr_manager','employee']` — plus `super_admin`, implicitly allowed by the guard itself.

Two consequences the Product Owner should hold in mind throughout this blueprint:

1. **`field_agent` — the product's headline persona — appears in no write guard at all.** It is
   sold as a distinct population and is indistinguishable from `employee` at the permission layer
   (`DISC-09`).
2. **One role per user, enforced.** Both assignment surfaces call `syncRoles([$role])`
   (`UserController::assignRole()`; `StaffController.php:51, 84`). Every `hasAnyRole([...])` list
   is therefore a union across mutually exclusive alternatives. **There is no "HR Manager who is
   also Accounts"** — which is precisely the person a small agency needs.

**The `accountant` case, confirmed at source, because it is the reference failure of the whole
role model.** The role is offered in the UI (`UserController.php:31`, with the comment
*"rev 116: confirms collections before commission approval"*) and is checked in code
(`RequestController::isAccounts()`, the commission payment voucher guard, the bank-pack
`canInvoice` flag). It is **created nowhere**: `RolePermissionSeeder.php:41-58` seeds exactly five
roles and `accountant` is not among them. The consequence chain is:
`syncRoles(['accountant'])` throws `RoleDoesNotExist` into a swallowing `catch` → the screen
reports success → the user is saved **with no role** → `ROLE_MAP` misses resolve to `'Admin'`
(`AppController.php:93`) → `applyPerms()` returns early for `'Admin'` (`:769`) → the shell's
`roleAllows()` returns `true` for an unknown role. **Attempting to configure the segregation of
duties produces a full-looking administrator account.**

### I.3.4 Non-human actors

Biometric terminals (ZKTeco / eSSL over unauthenticated ADMS, identified only by serial number,
auto-registering on an unknown SN); the **Smart Biometric Bridge** (a Windows service using
`X-Api-Key` with an `ingest` scope, tenant always taken from the key and never from the body);
**SmartEPT** (HMAC-SHA256 over the raw body, converting ATOM offsets to app-timezone wall clock
before ingest); eTimeOffice / eTimeTrackLite / the generic API connector; the Ametecs update
server and licence server; Interakt (WhatsApp) and a generic SMS gateway **with no provider
adapter at all**; Razorpay; and the scheduler plus queue worker, without which "notification
email, late-arrival alerts and other background work silently never send".

---

## I.4 The definitive module inventory

### I.4.1 Why the claimed "24 modules" cannot be verified

**There is no module registry in the code.** No constant, table, config key or class enumerates
"modules". The prior documentation's 24 is an analyst's grouping of the controller set. What the
code actually contains is **six mutually inconsistent module vocabularies**:

| Artefact | Count | Location |
|---|---:|---|
| `AppController::PERM_NAV` — the role-permission matrix groups | **16** | `AppController.php:655-676` |
| `PLAN_GROUP_KEYS` — what plan gating recognises | **10** | `AppController.php:34-37` |
| `StandardPlanSeeder` feature keys — what plans actually store | **14** | `StandardPlanSeeder.php:28-32` |
| `Edition` nav sets — `SAAS_ONLY` 8 + `DNA` 12 + `L2_MODULES` 30 | **50 nav ids** | `Edition.php:101-118` |
| `MasterController::def()` — self-creating registry types | **57–58** | `MasterController.php:30-493` |
| The SPA shell nav | **107 screen ids** (146 registered screens) | `resources/prototype/app.html` + boot JS |
| Marketing site | **"16 modules"**, stated in six places | `landing.blade.php` |

The `PERM_NAV` 16 is the closest thing to a canonical module list in code, and it is the one a
customer's permission matrix is actually expressed in: `dashboard, employees, attendance, leave,
payroll, commissions, expenses, loans, statutory, performance, field, kb, reports, assets,
settings, saas`.

**Verdict on "24": it is a documentation artefact, not a product artefact.** The inventory below
is what the code supports, at **30 modules**, and it corrects three material mis-groupings in the
prior list: Commissions/Schemes/Clawbacks is *the* collections module and was buried inside
"Loans, Advances & Incentives"; Off-roll Agents had no module at all despite being a separate
person model with its own KYC, ledger and public portal; and "Masters & Registries" — 57
self-creating registry types in a 1,224-line controller — was reduced to "Departments & Org
Structure".

> **PRODUCT OWNER DECISION REQUIRED — D-I-01: what is the canonical module catalogue?**
> The licence agreement sells "the SmartPRS module catalogue at the time of sale". That catalogue
> is not in this repository, and six in-code lists disagree. SmartPRS 3 needs **one** module
> registry, held as data, that the permission model, the plan gate, the edition gate, the licence
> and the price list all read from. **This blocks the module-pack boundary.**
> *Recommendation:* adopt the 30-module inventory below as the starting catalogue, collapse it to
> a commercial list of no more than 16 sellable modules, and make it a seeded reference table.

### I.4.2 The inventory

**Maturity:** `production` = works as presented · `partial` = works with a named gap ·
`prototype` = present but not fit for the claimed purpose · `dead` = unreachable or never written.
**Kind:** `CORE` = generic Indian HR/payroll · `VERTICAL` = collections-specific ·
`PLATFORM` = vendor commercial machinery.
`*` on a table name = **no migration**; created at request time by application code.

| # | Module | Business purpose | Primary controller(s) / service(s) | Route prefix | Tables | Maturity | Kind |
|---:|---|---|---|---|---:|---|---|
| 1 | **Authentication & Session** | Sign in, branded per-company portals, Super Admin portal, forgot/reset, first-password | `AuthController` (543) | `/login`, `/super`, `/c/{slug}`, `/forgot-password`, `/reset-password`, `/app/first-password` | 2 | production | CORE |
| 2 | **Users, Roles & Permissions** | One login per person, one role each; a client-side permission matrix | `UserController` (355), `StaffController` (114), `FeaturePermissions` (128) | `/app/users`, `/admin/staff` | 4 | **prototype** — the matrix is `display:none` only | CORE |
| 3 | **Tenancy & Companies** | One tenant → many legal entities; the oldest is `is_master` | `SaasController` (588), `MasterController` `companies` | `/app/saas/tenants`, `/app/master/companies` | 2 | partial — the declared scope is inert | CORE |
| 4 | **Employee Master** | The person record both compliance regimes hang off | `AppDataController` (3,090), `EmployeeFieldRules` (613) | `/app/employees/*`, `/app/data` | 3 | production | CORE |
| 5 | **Self-Onboarding** | New joiner fills their own record from a phone: OTP, selfie, documents, progressive save | `SelfOnboardingController` (1,132) | `/self-onboard/{token}/*`, `/app/self-onboarding/*` | 3* | production | CORE |
| 6 | **Employee Import / Export** | Bulk load at go-live; upload → map → preview → commit | `EmployeeImportController` (124), `EmployeeImportService` (821), `EmployeeExportController` (327) | `/app/employee-import/*`, `/app/employees/export` | 1 | partial — **bypasses the seat cap** | CORE |
| 7 | **Masters & Registries** | 57 self-creating registry types behind one CRUD engine | `MasterController` (1,224) | `/app/master/{type}` | 54–57, most runtime-created | production (as CRUD) | CORE + VERTICAL |
| 8 | **Biometric Devices** | Device setup, mapping, pending-punch quarantine, API keys | `BiometricConfigController` (721), `PushController` (286), `DeviceController` (239), `ApiKeyController` (546) | `/iclock/*`, `/app/biometric-config/*`, `/app/api-keys`, `/app/pending-punches` | 4* | partial — **`/iclock` is unauthenticated and runs DDL** | CORE |
| 9 | **Attendance Capture** | Seven ingest paths → one `attendance_logs` | `PunchIngestService` (403), `ETimeOfficeService` (784), `ETimeTrackLiteService` (313), `GenericApiService` (458), `SmarteptWebhook` (337), `ShiftResolver` (160) | `/api/v1/*`, `/iclock/*`, `/app/attendance/punch` | 2* | **production — the best-built subsystem** | CORE |
| 10 | **Attendance Correction** | Employee raises a regularisation; HR decides | `AttendanceCorrectionController` (182), `AttendanceCorrectionService` (484) | `/app/attendance-corrections/*` | 1 | production | CORE |
| 11 | **Attendance Reporting & Bulk** | Registers, PDFs, ratings, bulk CSV with an approval gate | `AttendanceReportController` (977), `AttendanceBulkController` (390) | `/app/attendance-report/*`, `/app/attendance-bulk/*` | 2* | production | CORE |
| 12 | **Leave** | Apply, balances per type, hierarchy approval | `LeaveController` (501) | `/app/leaves/*` | 2 | partial — **LOP leave is paid** | CORE |
| 13 | **Requests & Approvals** | One engine for 9–10 request modules + a unified inbox + threshold escalation | `RequestController` (1,929), `ApprovalService` (381) | `/app/requests/{module}`, `/app/approvals` | 9 | production — guards fail open | CORE |
| 14 | **Payroll Engine** | Preview → generate → draft run + payslips, with LOP proration and recoveries | `PayrollGenController` (2,845) *orchestrator*, `AppDataController` *calculator*, `PeriodResolver` (323, **not wired in**), `PayrollLock` (98) | `/app/payroll/preview`, `/app/payroll/generate`, `/app/salary-simulate` | 3* | partial — float money, no freeze, several formula defects | CORE |
| 15 | **Salary Approval & Disbursement** | Two-stage run approval + per-line lifecycle + NEFT bank pack | `SalaryApprovalController` (899) | `/app/salary-runs/*`, `/app/salary-lines/*` | 3* | partial — three unguarded listing endpoints | CORE |
| 16 | **Statutory Compliance** | PF/ESI/PT/TDS/LWF/194H rates, scoped and effective-dated; ECR, challan, 24Q, 26Q, PT, gratuity, bonus | `StatutoryConfigController` (476), `StatutoryController` (173), `StatutoryConfig` (589), `EffectiveDated` (136) | `/app/statutory-config/*`, `/app/statutory/{type}/pdf`, `/app/statutory-report/{type}` | 3* | partial — **the PDFs cannot reconcile to the payslips** | CORE |
| 17 | **Loans, Advances & Deductions** | Loan/advance requests with EMI recovery from payroll; ad-hoc deductions ledger | `LoanController` (84, dead), `RequestController`, `MasterController` `deductions` | `/app/requests/loans`, `/app/requests/advance` | 4* | partial — **UI-created loans recover forever or never** | CORE |
| 18 | **Commissions, Schemes & Clawbacks** | Published incentive schemes → claims with collection evidence → Accounts confirmation → manager approval → payslip or ledger; bounce → auto-clawback | `SchemeController` (1,001), `IncentiveController` (371), `RequestController` | `/app/schemes/*`, `/app/incentive/*`, `/app/requests/commissions/*` | 5* | production — **the Accounts role is uncreatable** | **VERTICAL** |
| 19 | **Bank / NBFC Payout & Billing Pack** | Per bank per month: payout register, TDS 194H annexure, GST service invoice to the bank | `BankPackController` (374) | `/app/bank-pack/*` | 2* | production (v1 limit: off-roll earnings carry no bank tag) | **VERTICAL** |
| 20 | **Off-Roll Agents** | A second person model: vendor/commission agents not on payroll — KYC, docs, bank, earnings ledger, token-secured public live-earnings page | `OffrollAgentController` (557) | `/app/offroll-agent/*`, `/agent/verify/{token}`, `/agent/earnings/{token}` | 2* | production — **excluded from all compliance scanning and all seat counting** | **VERTICAL** |
| 21 | **Recruitment & Hiring Drives** | Requisition → sourcing → interview → offer → hire, plus drives and WhatsApp campaigns | `RecruitmentController` (1,271), `HiringDriveController` (377), `RecruitMessagingController` (418) | `/app/recruitment/*` | 7* | production | CORE |
| 22 | **Letters, Offers, NDA & ID Cards** | Merge a template with employee data → PDF → email → token accept/sign | `LetterController` (521), `OfferAcceptController` (89), `NdaSignController` (103) | `/app/letters/*`, `/offer/{token}`, `/nda/{token}`, `/app/idcard/*` | 1* | partial — **`letters-nda` has no backend definition; the NDA leg is dead** | CORE |
| 23 | **Regulatory Compliance (DRA/PCC/BGV/CoC/Auth)** | Nightly expiry scan + alert email; per-agent RBI audit PDF with verdict, score and reference; bulk and sample variants | `ComplianceController` (661), `ScanCompliance`, `CodeOfConductController` (183) | `/app/compliance-alerts/*`, `/app/compliance/agent-audit*`, `/app/code-of-conduct` | 6* | partial — **off-roll excluded; audit reference not tamper-evident; one item hard-coded to pass** | **VERTICAL** |
| 24 | **Transfers** | Branch↔branch and company↔company moves under approval, applied on the effective date | `TransferController` (209), `TransferService` (217) | `/app/transfers/*`, `/transfer/accept/{token}` | 1* | production | CORE |
| 25 | **Performance, Points, Probation & Onboarding boards** | Review workflow, points ledger and rules, awards, tests, probation confirm/extend, onboarding checklist | `PerformanceController` (136), `PointsController` (265), `ProbationController` (126), `ProbationService` (388), `OnboardingController` (198), `TestController` (292) | `/app/performance/*`, `/app/points/*`, `/app/probation/*`, `/app/onboarding/*`, `/app/tests/*` | 6* | partial | CORE |
| 26 | **Training, Knowledge Base & In-App Help** | KB articles filtered by role; training programmes/records/content; FAQs; per-screen ⓘ help; the Salary Calculation Guide | `KbController` (226), `ScreenHelpController` (2,206), `CalcGuideController` (226), `SeedIndustryContent` (766) | `/app/kb`, `/app/help/{screen}`, `/app/calc-guide` | 5* | production | CORE |
| 27 | **Notifications & Messaging** | In-app bell, email, WhatsApp templates, SMS, broadcast, greetings, absence and late notices | `NotificationController` (65), `SendMessageController` (267), `WaTemplateController` (434), `GreetingController` (189), `AbsenceController` (102), `ConfigController` (447) + six services | `/app/notifications/*`, `/app/send-message`, `/app/wa-templates`, `/app/greetings/*`, `/app/absence/*`, `/app/mail-settings` | 6* | partial — **no SMS provider adapter; push tokens stored, no sender exists** | CORE |
| 28 | **Reporting & Exports** | Preview + CSV/XLSX over 14 fixed datasets; computed read-only screens; financial year | `ReportController` (520), `ComputedController` (166), `FinYearController` (161) | `/app/reports/*`, `/app/computed/{type}`, `/app/fin-year` | reads only | partial — **no workspace-level export** | CORE |
| 29 | **SaaS Signup, Billing & Subscriptions** | Landing site, leads, demo passkeys, signup, quotations, coupons, Razorpay, invoices, credit sales, tenant self-renewal | `LandingController` (190), `LeadController` (224), `DemoAccessController` (506), `SignupController` (1,204), `BillingController` (1,031), `TenantBillingController` (527), `CouponController` (176) + `SubscriptionService`/`CouponService` | `/`, `/signup`, `/demo`, `/quote/{token}`, `/app/billing/*`, `/app/my-subscription`, `/admin/*` | 11* | partial — **seat counting wrong; plan gating dead** | PLATFORM |
| 30 | **Licensing, Updates & Installer** | Licence issue/activate/revoke/rebind, AMC, release publishing and grants, client self-update with backup and rollback, browser install wizard | `OnpremClientController` (769), `ClientUpdateController` (879), `UpdateServerController` (377), `ReleaseController` (336), `InstallController` (247), `LicenseService`/`LicenseFile`/`LicenseSigner`/`UpdateClient`, `updater/updater.php` | `/update/*`, `/licence/{token}`, `/admin/onprem/*`, `/admin/releases`, `/app/updates/*`, `/activate`, `/install` | 8* — **none has a migration** | partial — **editions cosmetic; package hash check optional** | PLATFORM |
| — | **Mobile app device gate** | Capacitor WebView wrapper + pre-login device registration and approval | `MobileDeviceController` (260) | `/api/mobile/*`, `/app/mobile-devices/*` | 1* | partial — **status is not enforced server-side** | CORE |
| — | **AI Assistant** | *A nav item with no implementation anywhere* — no controller, no route, no renderer, shown to four roles | none | none | none | **dead** | — |

---

## I.5 The end-to-end operational process

This is the chain SmartPRS 2 automates, stated in business language, with the enforcement status
of every joint. It is the single most useful artefact in this part: **SmartPRS 3 must implement
this chain, and must close the joints marked ✘.**

| # | Stage | What the business does | What the software enforces | Evidence |
|---:|---|---|---|---|
| 1 | **Requisition** | HR raises a job opening; Admin approves it | `job_openings.approval_status`; **admin only** to approve. Anything unrecognised, including NULL, reads as `approved`. The "recruitable" flag is computed for display and **enforced nowhere** | `routes/web.php:632-635`; `RecruitmentController.php:260, 363-374` |
| 2 | **Sourcing** | Talent pool, portal import, hiring drive, bulk WhatsApp campaign | Candidate rows, drives with panel/venue/funnel, Interakt campaigns + a delivery webhook | `routes/web.php:607-655, 201` |
| 3 | **Interview** | Schedule, feedback, scorecards, outcome | `interviews`, `hiring_drive_candidates.outcome`. **Nothing reads `score` or `recommendation` other than to display it** — a `strong_no_hire` candidate can be hired with no warning | `routes/web.php:638-655`; `RecruitmentController.php:200-225` |
| 4 | **Offer** | Offer letter emailed as a token link; candidate accepts on a public page | `OfferAcceptController`; no login. **The public page shows only name, position, company and offer date** — not CTC, not the joining date, not the letter body | `routes/web.php:58-59`; `offer-accept.blade.php:38-44` |
| 5 | **NDA** | Candidate e-signs a confidentiality undertaking | `NdaSignController`; CSRF-exempt on sign. **Unreachable — `letters-nda` has no `MasterController::def()` entry**, so no NDA can be created through the UI | `routes/web.php:62-64`; `MasterController.php:356-389` |
| 6 | **Self-onboarding** | New joiner fills their own record from a phone: OTP, selfie, documents, progressive save | 8 public token routes; HR verification console with per-field correction / verify / approve | `routes/web.php:67-74, 673-687` |
| 7 | **Verification** | DRA certificate, PCC, BGV, ≥2 references, code-of-conduct acknowledgement, bank/portfolio authorisation | Six **flat registry tables**, all CRUD-only | `MasterController.php:107-141, 193-203, 288-320` |
| 8 | **Employee record** | The person becomes an `employees` row | Seat check — **at 3 of 5 doors only** | `AppDataController.php:815, 3042`; `RecruitmentController.php:602` |
| 9 | **Login invite** | One user per person, one role each; 48-hour set-password link | `UserController::invite`; `syncRoles([$role])` — **exactly one role** | `routes/web.php:373` |
| 10 | **Biometric enrolment** | Device PIN mapped to `emp_code` / `biometric_id` | Unmapped codes quarantine in `attendance_pending` and **replay backdated at their real timestamps** on mapping | `routes/web.php:526-528, 537-541` |
| 11 | **Probation clock** | 30/15/7-day confirmation reminders | `probation:notify` daily 07:15 | `routes/console.php:253-256` |
| 12 | **Daily attendance** | Punches arrive; late and absence notices go out | `absence:notify` hourly, self-gated per tenant | `routes/console.php:262-266` |
| 13 | **Requests** | Leave, expense, advance, loan, commission, clawback, increment, transfer, bonus, exit | One generic engine: `/app/requests/{module}` | `routes/web.php:424-448` |
| 14 | **Commission money chain** | Agent claims with evidence → **Accounts confirms money arrived** → manager approves | The gate exists; the `accountant` role **cannot be created** | `routes/web.php:432`; `RolePermissionSeeder.php:41-58` |
| 15 | **Cut-off** | Attendance for the period is complete | ✘ **No freeze exists.** Nothing stops a punch or a leave approval landing after a draft run | no freeze endpoint in `routes/web.php` |
| 16 | **Payroll preview** | HR previews every employee's numbers; nothing is written | ✔ | `routes/web.php:474` |
| 17 | **Payroll generate** | Draft run + payslips written; commissions locked; overtime marked paid; loan/clawback ledger rows written | ✔ (payslips are transactional; **the ledger side effects are not** — they run after `DB::commit()`) | `routes/web.php:480`; `PayrollGenController.php:2620-2691` |
| 18 | **HR approval** | draft → hr_approved | ✔ | `routes/web.php:461` |
| 19 | **Final approval** | hr_approved → approved + lock. **Admin only** | ✔ — but `admin` satisfies both `isHR()` and `isFinance()`, so one person can do both steps | `SalaryApprovalController.php:59-68` |
| 20 | **Bank pack** | NEFT CSV in generic / ICICI / HDFC / SBI layout; rows with no account/IFSC are excluded and listed. **Downloading the file IS the disbursement event** | ✔ but it bypasses `applyLineAction()`, so the run-status guard and the disbursement email never fire | `routes/web.php:466`; `SalaryApprovalController.php:499-503` |
| 21 | **Payslips** | PDFs published; employee acknowledges their line with a typed e-signature (`ack_name`, `ack_by`, `ack_at`, `ack_ip`) | ✔ | `routes/web.php:307, 470` |
| 22 | **Statutory outputs** | PF ECR, ESIC challan, 24Q, 26Q (§194H), PT state-wise, gratuity, bonus | ✔ generation — but computed **live from CTC**, not from the run that was executed | `routes/web.php:308, 485`; `AppDataController.php:1639-1749` |
| 23 | **Statutory filing** | Deposit and file with the authority | ✘ **NOT IMPLEMENTED.** No due-date field, no job, no alert anywhere | `routes/console.php` complete schedule |
| 24 | **Bank/NBFC billing** | Payout register + 194H annexure + GST service invoice per bank per month | ✔ (collections-specific) | `routes/web.php:334-335`; `BankPackController.php:10-30` |
| 25 | **Compliance audit** | Per-agent RBI audit PDF; bulk variant for a whole filtered population | ✔ for on-roll; **off-roll agents are entirely excluded** | `routes/web.php:487-491` |
| 26 | **Exit** | Exit request → approval → clearance checklist → FnF → status change frees a seat | ✔ — but **archive does not free a seat** despite the product saying it does | `SubscriptionService.php:35`; `AppDataController.php:2651` |

### I.5.1 Where the chain breaks

Four joints. Three the business assumes and the code does not enforce; one the code enforces the
opposite way.

| The business assumption | What the code does | Evidence |
|---|---|---|
| "Attendance is frozen before payroll runs" | **No freeze exists.** Only a run past `draft` cannot be regenerated. A draft reviewed on the 1st is not the draft Finance approves on the 5th | no freeze route; `PayrollGenController.php:2362` |
| "No punches = absent" | **Zero punches pays 100%**, deliberately, so a dead reader cannot zero a salary. A genuine absconder is therefore paid in full | `PayrollGenController.php:26-29, 1237` |
| "A non-compliant agent cannot be paid" | Payroll does **not** check DRA or PCC at all. A configurable DRA gate **does** exist — but only at three *commission* money points | `RequestController.php:1373`; `IncentiveController.php:135`; `OffrollAgentController.php:251` |
| "Filing happens on a calendar" | **No statutory due date is stored anywhere in the product.** `tds-returns` is a manual-status registry | `routes/console.php`; `MasterController.php:174-183` |

---

## I.6 The collections / recovery vertical

This section determines the SmartPRS 3 module-pack boundary. For each claimed industry
capability: **is it genuinely industry-specific, or generic HR wearing an industry label?**

### I.6.1 The verdict table

| Capability | What the code actually is | Genuinely industry-specific? | SmartPRS 3 home |
|---|---|---|---|
| **DRA certificate register** (`dra-certs`) | A `MasterController::def()` entry: a flat, self-creating table with `employee, company_name, cert_no, institute, track, issue_date, expiry, status` — plain CRUD, no validation, no issuing-body integration, no renewal workflow (`MasterController.php:314-320`) | **The schema, no. The consuming logic, yes** — the nightly expiry scan, the audit-report line item, and the DRA money gate | **Core** = a generic *Credential/Certification* object with issuing body, number, validity and a renewal workflow. **Module pack** = the DRA instance (IIBF, tracks) and the money gate |
| **PCC (police clearance)** | Two plain columns on `employees`: `pcc_status`, `pcc_expiry` (+ a legacy `pcc_deadline` that is selected and never evaluated) | **No.** A credential-with-expiry, identical in shape to DRA, modelled worse | **Core** — the same Credential object |
| **BGV** | A `def()` registry with six named check columns, an agency, a status and a `revalidate_months` → `next_due` auto-computation (`MasterController.php:193-203, 746-753`) | **No.** Background verification is generic pre-employment screening; the six checks are the standard Indian BGV set | **Core** |
| **Employee references (min 2)** | `employee_references` table, counted in the audit report; ≥2 = ok, 1 = warn, 0 = bad (`ComplianceController.php:591-593`) | **No.** Generic. The *threshold of 2 for field staff* is the industry bit — and it is **hard-coded** | **Core**, with the threshold configurable |
| **Agent authorisation / empanelment** (`agent-auth`) | A `def()` registry: `employee, company_name, bank, portfolio, auth_no, valid_to, status` (`MasterController.php:307-313`) | **Yes — genuinely vertical.** "A named bank has authorised this named person for this named portfolio until this date" has no generic HR analogue. But it is still only a flat CRUD table: no alert on `valid_to`, no link to the commission's `portfolio`, no revocation workflow, and `status` is never read | **Module pack** — and it needs building properly, not migrating |
| **Code of Conduct** | A read-and-acknowledge screen. The *content* is whichever KB articles sit in the category "Code of Conduct". The *record* is one row per user with `acknowledged` and `ack_date`. **No version, no re-acknowledgement cadence, no per-role text, no expiry, no IP, no signature** | **No.** A generic policy-acknowledgement feature. The only industry content is which KB articles the seeder writes | **Core** — a *Policy Acknowledgement* capability with versioning and periodic re-ack. The RBI conduct text is content, not code |
| **Off-roll agents** | A **second, parallel person model** with its own KYC document slots, bank/UPI, payout config (per-visit / percent / fixed), an earnings ledger with approve/reject, and a token-secured public live-earnings page needing no login | **Yes — genuinely vertical, and the strongest vertical asset in the product.** A workforce paid per visit or per rupee collected, who are not employees, hold no seat and have no login, is exactly the collections labour model | **Module pack**, but the *underlying* need — a non-employee worker with a payout ledger and a credential-less public view — is common to gig and field industries and should sit on a **core "Worker" abstraction** with off-roll as a worker type |
| **Client-bank mapping** | **There is no bank master.** The bank is the **free-text `portfolio` string** carried on each commission entry, and `BankPackController` groups by it. The controller's own docblock says so | **The need, yes. The implementation, no** — it is a string join | **Module pack**, and it must become a real `Client / Portfolio` entity |
| **Complaint tracking** | A `def()` registry: `employee, company_name, severity, channel, description, status, date`, employee optional so a complaint may be anonymous. Consumed as a trailing-90-day count in the score and the audit verdict | **The register, no** (a generic complaints/grievance register). **The consumption, yes** — "borrower complaints against this agent in the last 90 days" is a specific RBI-facing measure | **Core** = a generic Case/Complaint register with subject, channel, severity, SLA. **Module pack** = the borrower-complaint type and the audit-window rule |
| **Escalations** | A `def()` registry: `date, company_name, bank, team, issue, severity, priority, status, action_taken` | **Yes** — a bank-raised issue against the agency is not a generic HR concept | **Module pack** |
| **RBI agent audit pack** | `agentAuditData()` assembles ~14 items in 6 groups from data already held, scores 0–100, produces `COMPLIANT` / `COMPLIANT — WITH OPEN ITEMS` / `NON-COMPLIANT`, on the company's own letterhead, with a bulk variant and a public sample | **Yes — the single most defensible piece of vertical IP in the product.** It is the artefact the agency hands the bank | **Module pack**, rebuilt with the defects fixed and an issued-report table |
| **Nightly DRA/PCC expiry scan** | `compliance:scan` daily 07:30, buckets expired / critical / soon, emails the tenant's recipients | **The mechanism, no** — "alert me before a credential expires" is generic. **The applicability, yes** | **Core** = a generic credential-expiry alert engine driven by configuration. **Module pack** = which credentials and what windows |
| **DRA money gate** | Configurable `off` / `warn` / `block` in Settings → Statutory Rates, applied at **three money points**: commission approval, incentive commit, and off-roll earning approval. For off-roll agents the gate can only react to a **blank** DRA field, because `offroll_agents.dra` is free text with no expiry column | **Yes, and it is the most sophisticated vertical rule in the product** — a compliance state gating a money event, with a per-tenant severity setting | **Module pack rule**, expressed on a **core** rule engine: "block or warn a money event when a named credential is invalid" |
| **Bank/NBFC payout & billing pack** | Payout register + 194H annexure + GST service invoice per bank per month | **Yes.** An agency billing its client bank for collection services is not HR at all — it is the agency's revenue side | **Module pack** — arguably a separate product boundary |
| **Live Salary / live earnings** | Month-to-date earnings entry by entry, before payroll runs; plus the token-secured off-roll variant | **No, but commercially load-bearing.** Ametecs treats it as a flagship differentiator; the mechanism is generic | **Core** |
| **"Roster", "FOS", `companies.type = 'collections'`** | Labels and a free-text type column | **No.** Naming only | Core |

### I.6.2 The summary answer

**Genuinely industry-specific and worth a module pack (6):** agent authorisation/empanelment ·
off-roll agent register and payout ledger · client-bank/portfolio mapping and the bank billing
pack · escalation desk · the RBI agent audit pack · the DRA money gate.

**Generic HR wearing an industry label (7):** DRA register (a credential) · PCC (a credential,
modelled as two columns) · BGV · employee references · code-of-conduct acknowledgement (a policy
ack) · complaint register (a case register) · the expiry-scan mechanism.

### I.6.3 The structural insight — the vertical is thin, and that is good news

Five of the six registries that carry the industry story — `dra-certs`, `agent-auth`, `bgv`,
`complaints`, `escalations`, `code-of-conduct` — are **rows in a 460-line config array in one
controller**, backed by self-creating flat tables with string columns. There is almost no vertical
*logic*; there is vertical *naming* over generic CRUD, plus **three genuinely vertical engines**:
the audit pack, the DRA money gate, and off-roll payouts.

The implication for the rebuild is commercially significant. If SmartPRS 3's core provides:

- (a) a **Credential** object with expiry and an alert policy,
- (b) a **Policy Acknowledgement** object with versioning and re-acknowledgement,
- (c) a **Case/Complaint** register,
- (d) a **Worker** abstraction that admits non-employees, and
- (e) a **rule engine** that can gate a money event on a compliance state,

then **the entire collections module pack reduces to configuration plus three engines** — and the
same core sells to security agencies, staffing firms, logistics and facilities management without
a rewrite. Spine S3, S6 and S9 already specify (a)–(e).

---

## I.7 The commercial model as implemented

### I.7.1 Two shapes, two independent switches

| | Hosted SaaS | On-prem perpetual |
|---|---|---|
| `SMARTPRS_EDITION` | `saas` | `l1` \| `l2` \| `l3` |
| `SMARTPRS_DEPLOYMENT` | `saas` | `onprem` |
| Marketing surface at `/` | Landing, signup, quote, demo, policies | **Not registered.** `/` redirects to `/app`; the signup/demo/quote/lead/policy/webhook/update-server routes sit inside `if (! $spOnPrem)` blocks |
| Access control | `subscriptions` + `EnsureSubscriptionActive` | `.lic` / License Code + `LicenseGate` |
| Money | Razorpay subscription, quarterly minimum, per-seat + per-company | One-time licence + AMC; Razorpay pay-link, or NEFT/cheque/UPI/cash recorded by the Super Admin |
| Updates | Central | Client pulls a *granted* package after a checksum check, with a code backup and code rollback |

Evidence: `config/smartprs.php:12, 22`; `routes/web.php:22, 27-37, 45-55, 125-167, 172-196,
200-204`. `Edition::current()` deliberately bypasses cached config and parses `.env` line by line
(`Edition.php:56-86`), because `config:cache` would otherwise freeze the value and a cloned build
folder would report the wrong edition forever.

**The two switches are independent**, and the combination `EDITION=l2, DEPLOYMENT=saas` produces
an **unbillable, unlockable tenant**: `EditionGuard` 404s the billing screens,
`EnsureSubscriptionActive` skips the lock-out because the edition is on-prem, and `LicenseGate`
requires `deployment === 'onprem'`. The comment at `ClientUpdateController.php:225-230` implies
this configuration has occurred in production.

### I.7.2 Editions — priced in three tiers, delivered as one product

```php
// app/Services/Edition.php:93-97
/** Feature level. 8 Aug 2026 (Ejaz — single product): every edition is full. */
public static function level(): int { return 3; }
```

`hiddenNavIds()` (`:119-133`) returns `SAAS_ONLY` for **every** on-prem edition; `DNA` and
`L2_MODULES` are retained *"for reference / easy revert"* and hide nothing. `BLOCK_DNA` and
`BLOCK_L2` are declared and returned by no method.

**CONFIRMED AT SOURCE: the three price points ship a byte-identical product.** The only things an
L1 customer cannot reach are `/admin`, `/super`, `/signup` and `/app/billing` — which no customer
wants. The edition lock *on the licence* is real (activation compares the edition string exactly),
so the vendor can tell which edition a key belongs to while the running software cannot.

The constants preserve the intended tiering and are the definitive statement of what Ametecs
considers the collections vertical:

- **`SAAS_ONLY` (8 ids)** — platform-dashboard, tenants, plans, subscriptions, invoices, payments,
  gateways, my-subscription.
- **`DNA` (12 ids)** — live-salary, pay-ledger, commissions, commission-calc, incentive-schemes,
  clawbacks, escalations, offroll-agents, agent-auth, compliance-alerts, roster, complaints.
- **`L2_MODULES` (30 ids)** — recruitment, the five letter types + templates, expenses/advance/
  loans/bonus-enc/increments, performance/points/awards/tests, training/KB/FAQs/code-of-conduct,
  WhatsApp settings + templates, attrition + activity-logs, messaging + SMS.

### I.7.3 SaaS pricing — the exact formula as implemented

`BillingController::priceFor()` `:117-133`, transcribed:

```php
$included   = (int) ($plan->seat_max ?? 0);
$extra      = $included > 0 ? max(0, $seats - $included) : $seats;
$months     = cycleMonths($cycle);      // monthly 1 | quarterly 3 | halfyear 6 | annual 12; unknown → 3
$discount   = cycleDiscount($cycle);    // halfyear 0.10 | annual 0.25 | else 0.0
$companies  = max(1, $companies);
$companyFee = 1000.0 * ($companies - 1);            // COMPANY_FEE :56
$perMonth   = $plan->base_price + $plan->per_user_price * $extra + $companyFee;
$amount     = round($perMonth * $months * (1 - $discount), 2);
$tax        = round($amount * 18.0 / 100, 2);       // GST :35
$total      = round($amount + $tax, 2);
```

Seeded plans (`StandardPlanSeeder.php:33-37`):

| Plan | Base / month | Per extra user / month | `seat_max` | Feature keys stored |
|---|---:|---:|---:|---:|
| Starter | ₹1,000 | ₹60 | 25 | 14 |
| Growth | ₹2,500 | ₹50 | 75 | 14 |
| Professional | ₹5,000 | ₹40 | 150 | 14 |

**Worked example** — Growth, 100 seats, 2 companies, annual:
`extra = 100 − 75 = 25`; `companyFee = ₹1,000`; `perMonth = 2,500 + 50×25 + 1,000 = ₹4,750`;
`amount = round(4,750 × 12 × 0.75, 2) = ₹42,750.00`; `tax = ₹7,695.00`; **total ₹50,445.00**.

Ordering is fixed and matters: the cycle discount applies to `perMonth × months` (so it discounts
the company fee too), and GST is computed on the **discounted** amount. A coupon, when present, is
applied after this and tax is recomputed. All of it is PHP float arithmetic, in a product whose
own config declares *"All monetary values use decimal columns + integer-safe math. Never float."*
(`config/smartprs.php:126-128`).

### I.7.4 Plan-based module gating is switched off twice over

- **Client side:** `applyPlanGate()` (`AppController.php:709-712`) begins
  `// TEMPORARILY DISABLED (rev 34e)` followed by an unconditional `return;`. The 29 lines below
  it are marked `// eslint-disable-line no-unreachable`.
- **Server side:** `resolvePlanModules()` (`:40-73`) returns `null` (= unrestricted) if **any**
  feature key is not in `PLAN_GROUP_KEYS`. `PLAN_GROUP_KEYS` has 10 keys; `StandardPlanSeeder`
  stores 14; **seven of the fourteen are unrecognised** (`hiring, people, compensation, learning,
  letters, communication, administration`). **Every seeded plan therefore resolves to
  unrestricted.** The seeder's own docblock calls the mechanism "parked".

### I.7.5 Seats — what a customer actually buys

| Rule | Behaviour | Evidence |
|---|---|---|
| A seat is | **an active on-roll employee across the whole workspace**, all companies pooled | `SubscriptionService.php:16-21, 87-97` |
| What frees a seat | `status` NULL or not in `exited, inactive, resigned, terminated, left, absconded`; plus soft-delete | `SEAT_FREE_STATUSES :35`; `seatUsage() :90-96` |
| What does **not** free a seat | **`archived_at` ("Old data") is not filtered** — an archived employee still bills, while the product's own error text says archiving frees a seat | `seatUsage() :87-97` vs `:141`; `AppDataController.php:2651-2655` |
| Licensed ceiling | `subscriptions.seats` → `tenants.seats_licensed` → on an enforced on-prem install the `.lic` `device_limit` **wins** | `seatUsage() :99-114` |
| Enforced at | add-employee form, one other `AppDataController` door, recruitment hire — **three call sites total** | `AppDataController.php:815, 3042`; `RecruitmentController.php:602` |
| **Not** enforced at | **the employee import wizard and the self-onboarding injection** — the two doors a customer uses to add people *in bulk* | no `canAddEmployees` call in `EmployeeImportController`, `EmployeeImportService` or `SelfOnboardingController` |
| Failure mode | **fails open** — no tenant, `licensed <= 0`, or any exception → allow | `canAddEmployees() :130-133`; `seatUsage() :115-118` |
| Off-roll agents | **never counted.** An agency can run 500 off-roll agents on a ₹1,000/month 25-seat Starter plan | `offroll_agents` is absent from `seatUsage()` |
| Company ceiling | **strict from day one**, ₹1,000 per company per month | `canAddCompany() :180-199` |

There is also a **second, contradictory definition of "active employee"**:
`ClientUpdateController::activeEmployeeCount() :515-520` *does* exclude `archived_at`. So on SaaS
a tenant can be blocked from hiring by archived people they cannot see on any screen; on-prem,
archiving is an unlimited seat-cap bypass through the normal UI.

### I.7.6 Lifecycle, coupons, quotations, on-prem licence

- **State machine:** `active` → `grace` (7 days, full access, banner) → `locked` (only a user
  holding the `admin` role can sign in). Any exception → state `none`, never gated.
  `GRACE_DAYS = 7`.
- **Coupons:** percent or flat ₹, applied **after** the cycle discount, tax recomputed on the
  discounted amount. **A coupon discounts only the first invoice** — `subscriptions.amount` keeps
  the full rate, with no in-product warning that renewal will jump.
- **Quotations:** GST quotation PDF + secure pay link, nominally valid 15 days (**expiry is
  displayed and never enforced**), discount locked in. Invoice series `PRS-<FY>-<MM>-<nnnn>`
  shared by SaaS and on-prem licence invoices; quotations in their own `PRS-Q-` series.
- **Credit sales are a first-class flow:** the Super Admin records Paid / Partial / Due with
  amount, mode, UTR/cheque reference and a credit due date, and the workspace is provisioned
  immediately (`routes/web.php:281`).
- **On-prem:** perpetual licence, one key one server (machine-fingerprint bound), first 12 months
  maintenance included. **AMC is sold at 20%/year in the licence agreement; `licences.amc_percent`
  defaults to 18 and is read by no calculation** — AMC invoicing is entirely manual.
- **Renewal is never automatic.** `billing:renew` exists and is deliberately **not scheduled** —
  which is the only thing preventing a defect that would extend every tenant's period on invoice
  *creation* rather than payment.
- **No free trial exists.** `subscriptions.status` accepts `trialing` and `tenants.trial_ends_at`
  exists; nothing ever sets either. The pre-purchase surfaces are a passkey-gated shared demo
  (`/demo`, default 2 hours, clamped 1–72), three edition demos (`/app1|2|3`), a team demo
  (`/teamdemo`), and a 7-day money-back guarantee on a first payment.

---

## I.8 Deployment reality

| Dimension | Reality |
|---|---|
| **Artefact** | One codebase, two switches. Business logic is identical across SaaS and on-prem — which is correct, and which SmartPRS 3 must preserve (spine S18). |
| **Editions** | Three price points, one product (§I.7.2). The tiering exists in constants and in the licence, not in the running software. |
| **Installation** | Two paths: a Windows `.bat` installer (not in this repository) and a browser first-run wizard `GET|POST /install` that runs a 20-item environment pre-flight, creates the company and admin login, and self-disables the moment any user row exists. |
| **Updates** | `/admin/releases` registers a zip with its SHA-256 and publishes/grants it to AMC-active clients. The client's admin runs status → check → download (+sha256) → install, where install hands a job file to `updater/updater.php` running as a detached process outside Laravel. **There is no code signing.** The only integrity control is a hash supplied by the same server in the same response, and the check is skipped entirely when the hash is absent. |
| **Rollback** | Code-only. `updater.php:140-165` restores the pre-update code tree. Step 9 already ran `migrate --force`. **There is no database backup and no database rollback anywhere in the update flow.** |
| **Health check after update** | `artisan --version`. That proves the CLI autoloader boots; it does not touch the database, exercise a route or confirm the web SAPI can serve a page. |
| **Backups** | `spatie/laravel-backup` nightly at 02:00, zipping the application root plus a MySQL dump **to `storage/app` on the same server it is backing up**, unencrypted by default, with `tries=1`, no retry, no notification target, `backup:monitor` not scheduled, and `storage/app` (which holds the licence private key and every uploaded document) **excluded**. |
| **Restore** | **There is no data restore path anywhere in the product.** No `backup:restore`, no route, no runbook, no test, no drill. |
| **Operational runtime** | The cron entry and the worker unit live in a `deploy/` directory **that is not in this repository**. The README tells the operator to run `php artisan queue:work` and `php artisan schedule:work` in *"separate terminals"*, i.e. by hand. Nothing in a release enforces or verifies that either is installed. |
| **Observability** | One daily email (`errors:digest`) plus Laravel's stock `/up`. No metrics, no correlation id, no heartbeat, no queue-depth check, no APM, no alerting, no custom error pages. |

---

## I.9 What SmartPRS 2 does well — the preservation contract

**This section matters more than any defect list.** It is the contract that says which of Ametecs'
hard-won assets the rebuild is obliged to carry forward. Each item is stated with evidence and
with what is lost if SmartPRS 3 drops it. It expands spine S19.

Requirements in this section are numbered `R-I-nnn` and are **preservation requirements**: each is
testable by asking "does SmartPRS 3 still do this?"

---

### R-I-001 · Punch ingest is careful, idempotent, tenant-bound engineering — port it, do not rewrite it

Seven heterogeneous sources converge on one attendance record. The tenant always comes from the
credential, never from the payload. Per-punch verdicts (`accepted` / `duplicate` / `pending` /
`rejected`) are returned to the caller. Duplicate protection is enforced at the **database** level
by two unique indexes inside a transaction. Timezone handling is explicit and commented, and the
SBB path **rejects** an offset-bearing timestamp rather than silently reinterpreting it.

*Evidence:* `PunchIngestService.php` (403), `SmarteptWebhook.php` (337, esp. `:31-40` ATOM →
wall-clock), `routes/api.php:1-43` (whose docblock is a model of design rationale), backed by
**63 real test cases** across `SbbIngestTest` (23 cases / 119 assertions), `SmarteptWebhookTest`
(20 / 65), `IclockTenantHotfixTest` (11 / 49) and `DeviceModeTest` (9 / 13).

*What is lost if dropped:* the single subsystem in this product that can be refactored with
confidence. Rebuilding it from scratch re-earns bugs that took a year and a production incident to
find. The quality analysis names it *"the only place the team demonstrably got concurrency
right"*.

**Requirement.** SmartPRS 3 must carry forward the *behavioural specification* proven by those 63
tests: credential-derived tenancy, per-punch verdicts, database-enforced idempotency, explicit
timezone policy, and the transaction-plus-two-unique-indexes pattern.

---

### R-I-002 · Punch quarantine and backdated replay

A punch whose device code is not yet mapped is **held** in `attendance_pending` rather than
dropped, is counted for the "Employee Mapping" card, and when HR maps the code **every held punch
is applied at its real original timestamp**.

*Evidence:* `routes/web.php:537-541`; `ETimeOfficeService::promotePending() :455-562`; migrations
`create_attendance_pending_table`, `attendance_pending_device_code`.

*What is lost if dropped:* the most common real-world failure — a branch enrols a new joiner on
the terminal before HR records them — becomes silent data loss instead of a work queue.

---

### R-I-003 · Preview-before-write on payroll

`/app/payroll/preview` computes every employee's numbers and writes nothing.

*Evidence:* `routes/web.php:474`; `PayrollGenController::preview() :2292-2331`.

*What is lost if dropped:* the one place the product lets an operator check money before
committing it. **Non-negotiable.**

---

### R-I-004 · Two-stage payroll approval with a real segregation, plus a per-line lifecycle

draft → hr_approved (HR) → approved (**admin/super_admin only**, `hr_manager` excluded) +
`locked_at`. Underneath it, each employee's *line* can be held, put in review, approved, rejected,
disbursed, and finally acknowledged by the employee.

*Evidence:* `routes/web.php:461-472`; `SalaryApprovalController.php:59-68`.

*What is lost if dropped:* the four-eyes control on payroll money. (Note for Part II: `admin`
satisfies both predicates, so the control is weaker than intended — but the *shape* is right and
must be preserved and strengthened, not discarded.)

---

### R-I-005 · The bank pack refuses to send bad rows to the bank

Rows with no account number or IFSC are excluded from the NEFT file and **reported separately by
name and code**. Four layouts are supported (generic / ICICI / HDFC / SBI) with a `# TOTAL`
trailer.

*Evidence:* `routes/web.php:465-466`; `SalaryApprovalController::bankFile() :444-514`.

*What is lost if dropped:* a returned NEFT batch on payday.

---

### R-I-006 · Employee e-signature acknowledgement of a payslip line

Typed name + confirm, recorded with `ack_name`, `ack_by`, `ack_at`, `ack_ip`.

*Evidence:* `routes/web.php:470`; `SalaryApprovalController::acknowledge() :745-748`.

*What is lost if dropped:* a cheap, real audit artefact that most Indian payroll products lack.

---

### R-I-007 · The commission chain is designed as an evidence chain, not a form

Scheme → claim carrying customer, account reference, amount collected, date/time, location, mode
of collection and a **proof upload** → **Accounts confirmation gates manager approval** → TDS
§194H deducted at entry → edit-after-approval is diff-logged → a paid entry is locked → a bounce
auto-creates an approved clawback.

*Evidence:* `routes/web.php:427-444`; `ApprovalService::logCommission()`;
`RequestController.php:57-70, 849-870, 1680-1802`.

*What is lost if dropped:* **the product's strongest domain thinking.** Preserve the *shape* even
where the implementation is defective. This is the model for how a money claim should be
evidenced, and it maps cleanly onto the spine's workflow engine (S8) with maker-checker as a
workflow shape.

---

### R-I-008 · The RBI agent audit pack

One click produces a per-agent compliance report on the customer's own letterhead: 6 groups,
~14 items with per-item ok/warn/bad, an overall verdict, a 0–100 score and a defined audit window
— plus a bulk variant for a whole filtered population and a public sample for the marketing site.

*Evidence:* `ComplianceController.php:379-491, 541-660`; `routes/web.php:110, 487-491`.

*What is lost if dropped:* **the product's clearest differentiator and the reason a bank-facing
agency buys it.** The item set, the grouping, the scoring shape and the verdict ladder are all
genuine domain capital and must be carried forward as *configuration* (spine S6 rules + S12 pack
content), with the defects fixed and an issued-report table added.

---

### R-I-009 · The DRA compliance-to-money gate

`off` / `warn` / `block`, **per tenant**, applied at three money points, and it *never breaks the
payment path on error*.

*Evidence:* `SettingsController.php:57, 154`; `RequestController.php:1373`;
`IncentiveController.php:135`; `OffrollAgentController.php:249-262`.

*What is lost if dropped:* **the only implemented example of the thing SmartPRS 3 is being built
to generalise.** Spine S19 names it the product's strongest differentiator and the reference case
for the rule engine. SmartPRS 3 must be able to express, as configuration: *"block (or warn on) a
named money event when a named credential is invalid, at a severity this tenant chooses"* — and
must generalise the *subject* (PCC, BGV, authorisation, any credential) and the *event list*
(currently three, hard-coded).

---

### R-I-010 · The off-roll agent live-earnings page

A commission agent with no login, no app and possibly no email sees their month-to-date earnings
**entry by entry** on a phone, from a private link — with rejected entries hidden and pending
entries shown greyed and labelled "awaiting approval".

*Evidence:* `OffrollAgentController.php:315-361`.

*What is lost if dropped:* a genuinely humane piece of product design for a low-trust,
low-literacy workforce. The "how you earned it" breakdown pre-empts the argument before it starts.
This is a product instinct worth keeping, on a token that expires and rotates.

---

### R-I-011 · Self-onboarding is a complete, thought-through flow

Token link → OTP → progressive save → selfie → documents → submit → HR console with **per-field
correction requests** → verify → approve → inject into `employees`. Plus a bulk-invite variant.

*Evidence:* `routes/web.php:67-74, 673-687`; `SelfOnboardingController` (1,132).

*What is lost if dropped:* the back-office labour saving that makes the product viable for a
high-churn agency. The per-field correction loop in particular is a design most competitors lack.

---

### R-I-012 · Statutory rates are tenant data, scoped and effective-dated — this is the pattern to extend

`statutory_configs` supports **company / state / branch / location / tenant scope**, effective
dating resolved on the payroll-period date, insert-only history, a finalised-period guard, an
audit trail, and a rupee-impact preview before saving.

*Evidence:* `routes/web.php:580-584`; `StatutoryConfig` (589), `EffectiveDated` (136),
`StatutoryConfigController` (476), `tests/Unit/StatutoryConfigTest.php` (8 cases).

*What is lost if dropped:* **already 80% of the "change configuration, not code" principle, for
the hardest domain in the product.** Spine S4 and S12 should be understood as the generalisation
of this design, not a replacement for it. The merge semantics and the precedence ladder are worth
keeping as a specification.

---

### R-I-013 · `PeriodResolver` — the best-tested payroll component in the product

323 lines that answer "what dates does this payroll month cover?" for calendar months and cut-off
cycles, with day-of-month clamping (30 February → 28/29), and **9 passing unit tests**.

*Evidence:* `app/Services/PeriodResolver.php`; `tests/Unit/PeriodResolverTest.php` (9 cases /
34 assertions).

*What is lost if dropped:* correct period arithmetic that already exists. Its own docblock admits
*"Nothing in this class touches the payroll engine yet."* **In SmartPRS 3 it is the period
engine.** Carry the logic and the tests forward together.

---

### R-I-014 · The penny-safe balance-residue allocation in the salary structure

The one correct piece of money-splitting in the codebase: a `balance` component absorbs the
residue so components always sum exactly to gross.

*Evidence:* spine S19.4; `AppDataController::computeSlipFromComponents()`.

*What is lost if dropped:* the pattern SmartPRS 3 must generalise as `ALLOCATE(amount, weights[])`
(spine S5.4, S7).

---

### R-I-015 · The self-healing schema — for its stated reason, not its mechanism

MySQL DDL is not transactional, so a migration that dies half-way is unrecoverable on a
customer-run Windows box nobody can SSH into. **A real client install died exactly that way on
28 Aug 2026.** `ensure_runtime_feature_tables` is the retrofit, and its docblock names the three
exact symptoms it fixes.

*Evidence:* `2026_06_24_000000_ensure_runtime_feature_tables.php:7-23`;
`app/Support/SchemaHelper.php:11-26`;
`2026_01_01_000100_create_employees_table.php:11-25` (the `SQLSTATE[42S01]` incident).

*What is lost if dropped:* nothing — **the mechanism is wrong** (Part II, failure ii). But **the
problem is real and SmartPRS 3 must answer it**: an idempotent, migration-owned `schema:ensure`
run at boot and after every update, with a failure that is loud rather than silent. Do not
discard the requirement along with the implementation.

---

### R-I-016 · Availability-biased design, defended in writing at every site

*"A licence check must never take a client's HR system down."* *"A billing bug must never brick a
paying customer's HR system."* *"Never break real tenants over the demo check."* Zero punches pays
full salary so a dead reader cannot zero a wage.

*Evidence:* `SubscriptionService.php:26-31`; `AuthController.php:266-269`;
`PayrollGenController.php:26-29`; `EditionGuard.php:55-57`; `DemoWriteGuard.php:73-75`.

*What is lost if dropped:* a deliberate, coherent product philosophy for software that runs
unattended in a payroll department, often with no administrator on site. **Keep the bias.** Part
II's argument is not that the bias is wrong; it is that the bias was applied to *authorisation*,
where it must not be, and applied *silently* everywhere, where it must not be. The spine's
formulation is the right one: a gate that fails open must **fail open loudly** — log, count,
alert — and a gate that protects data must fail **closed**.

---

### R-I-017 · The code explains itself

~40 dated change annotations (`rev 116 (Ejaz)`, `rev181 — BOUNCE: cheque returned…`), rationale
comments at every non-obvious decision, and docblocks that state *why* rather than *what*.
`routes/api.php`'s header explains the two authentication models and why they differ.

*What is lost if dropped:* this is the reason a forensic pass of this depth was possible at all.
**It is an asset, and it is the primary input to SmartPRS 3's requirements.** Every business rule
in this blueprint that is not in a table is in a comment.

---

### R-I-018 · In-app help is real and substantial

`ScreenHelpController` (2,206 lines) supplies per-screen "how to use it / why it matters / do it
right" content across **120 unique screen keys**, 119 with "why it matters" copy and 73 with
"common mistakes and their consequences". `KbController` filters articles by the viewer's role.
`CalcGuideController` documents the payroll engine's own rules inside the app.

*Evidence:* `routes/web.php:309-313, 333, 341`.

*What is lost if dropped:* support cost. The vendor's support policy explicitly excludes onsite
visits, and this content is what makes that viable. **Migrate it as content into a store, not as
code.**

---

### R-I-019 · Industry content seeding

`content:seed-industry` pre-loads a complete India-specific Collections & Recovery content pack —
12 training programmes, 25 subjects, 17 FAQs, 16 KB articles (including the four Code of Conduct
articles agents legally acknowledge) and a standard offer-letter template — as ordinary editable
rows, invoked from the installer, SaaS provisioning, on-prem provisioning and demo reset.

*Evidence:* `SeedIndustryContent.php` (766).

*What is lost if dropped:* a new customer's first-day experience, and a genuine piece of vertical
content capital.

---

### R-I-020 · The demo workspace is genuinely safe

One flag (`tenants.subdomain = 'demo'`) triggers a write guard, hides Administration, **mutes
outbound email and WhatsApp**, and shows a banner — and the workspace is wiped and reseeded within
15 minutes of a passkey window ending, plus a daily backstop.

*Evidence:* `routes/console.php:204-211`; `OnPremWorkspaceSeeder.php:12-24`;
`DemoWriteGuard.php`.

*What is lost if dropped:* a shared public demo of an HR product that cannot leak or spam. (The
credential handling around it is a Part II critical defect; the *containment design* is sound.)

---

### R-I-021 · Razorpay signature verification is correct on both payment paths, and PDFs stream

Both the browser `verify` path and the webhook verify the HMAC. All 17 PDF generation sites
stream; none writes to disk.

*Evidence:* `routes/web.php:164-166, 404-406`; `BillingTest.php` (webhook signature + sequential
replay assertions).

*What is lost if dropped:* two whole classes of vulnerability this codebase does not have.

---

### R-I-022 · Breadth of workflow understanding

The 30-module inventory reflects real operational knowledge of how Indian collection agencies and
BPOs run: hiring drives with venue and slot capacity, off-roll payout models, bank empanelment,
clawback on bounce, transfer letters with token acceptance, probation extension, greeting
automation, an RBI-facing audit pack, a per-bank GST service invoice.

*What is lost if dropped:* domain scope that took years of customer contact to assemble. **Treat
the module inventory in §I.4.2 as a requirements catalogue.**

---

### R-I-023 · `EmployeeFieldRules` — a declared field registry

56 employee fields declared in one place with type, panel, portal key, duplicate-blocking and
format validation, driving the employee form's panels, the sample-file headers and dropdowns, the
export column map and the importer's header aliases.

*Evidence:* `EmployeeFieldRules.php:80-233`.

*What is lost if dropped:* a genuine single source of truth for field shapes. **Carry it forward
as seed data for the spine's Entity Schema Engine (S9)** — where it becomes tenant-extensible
rather than a `const`.

---

### R-I-024 · Deployment parity as a stated principle

Business logic is identical across SaaS and on-prem; there is no `if (on_prem)` in a payroll or
attendance path. Only commercial gating differs.

*What is lost if dropped:* spine S18's central promise. SmartPRS 2 already honours it.

---

## I.10 Scale and shape of the asset

### I.10.1 What is salvageable, and in what form

| Asset | Verdict | Rationale |
|---|---|---|
| The 63 punch-ingest tests + `PunchIngestService`'s transaction-plus-two-unique-indexes pattern | **SALVAGE AS REFERENCE — the single most valuable artefact in the repository** | The only place the team demonstrably got concurrency right, and the tests are behavioural rather than implementation-coupled. Port the *specifications*. |
| `PeriodResolver` + its 9 tests | **SALVAGE AS SPECIFICATION** | Well-tested, correct, and never wired up. In SmartPRS 3 it is the period engine. |
| `StatutoryConfig` scope-precedence rules + its 8 tests | **SALVAGE AS DESIGN** | The closest thing in SmartPRS 2 to the configuration-first principle. Keep the merge semantics; discard the implementation. |
| The PT state-slab table and its 7 tests | **SALVAGE AS DATA** | Move the slabs into a signed rule pack; keep the assertions as golden tests. Never re-import the hand-copied `tests/manual/pt_extract.php` pattern. |
| `EmployeeFieldRules` field/validation catalogue | **SALVAGE AS DATA** | Seed data for the Entity Schema Engine. |
| `ScreenHelpController` help copy (2,206 lines) + the seeded industry content | **SALVAGE AS CONTENT** | Move to a content store. Zero code value, high business value. |
| The business rules encoded in `PayrollGenController::compute()` | **SALVAGE AS REQUIREMENTS ONLY** | The three LOP bases, the sandwich rule, mid-month joiner proration, the recovery order (loan EMI → advance → clawback, with a net-never-negative floor), the "zero punches pays 100%" safeguard and the ESI period lock are every one a real customer requirement. **The code implementing them is a liability**; extract the rules, discard the file. |
| The 30-module inventory, the 26-stage operational chain, the ~45 status columns, the BR-* rule catalogue | **SALVAGE AS THE REQUIREMENTS DOCUMENT** | This is the actual product specification. It does not exist anywhere else. |
| The SPA screen registry and the 107 nav ids | **SALVAGE AS A SPECIFICATION INPUT** | Extract as data *before* deleting `app.html` and `AppController.php`. |

### I.10.2 What is a liability

| Liability | Size | Why |
|---|---:|---|
| `AppController.php` — the SPA in a PHP heredoc | 12,533 lines (~15% of the codebase) | Untestable, unlintable, unbundleable, no build step, and it carries part of the authorisation model. **Total loss; zero reusable lines.** |
| The 156 runtime-DDL sites in `app/` | 156 | Every one is a migration that was never written. They make the deployed schema unknowable from source and require the production database user to hold `ALTER` at runtime. |
| The 738 `Schema::has*` guards and the `safeRow()` pattern | 738 + 98 call sites | Defensive scaffolding for schema drift. `safeRow()` in particular converts a schema bug into **silent data loss** at 98 sites. |
| The fail-open catch blocks in auth, seats, licence, payroll lock and audit | ~8 documented sites, 426 silent catches overall | Must be **inverted, not ported**. |
| The Eloquent model layer | 5 of 11 models unreachable; the other 6 declare columns that do not exist | Dead and wrong. Delete. |
| Dead dependencies and scaffolding | `livewire`, `sail`, `axios`, `welcome.blade.php`, two `activity_log` migrations, `resources/css/smartprs-theme.css`, the decorative Vite pipeline | Weight in every build and every advisory sweep. |

### I.10.3 The rebuild estimate framing

Of 81,592 LOC: roughly **12,500 (15%)** is the SPA heredoc — a full rewrite with zero reusable
lines. Roughly **2,200 (3%)** is help content — a data migration. Roughly **8,000–10,000 (12%)**
across `PayrollGenController`, `StatutoryConfig`, `EmployeeFieldRules`, `PeriodResolver` and the
ingest services encodes real, hard-won Indian payroll and attendance domain rules that must be
**re-specified, not re-used**. The remaining ~70% is CRUD, defensive schema scaffolding and
duplication.

> **Treat SmartPRS 2 as a requirements document with a running reference implementation, not as a
> codebase to refactor.** Part II sets out why that is the only honest reading.

---

## I.11 Coverage gaps — what has NOT been analysed, and the risk it carries

Recorded so the Product Owner knows the boundary of this baseline. Each is a **known unknown**
that could change a plan or an estimate.

| # | Gap | Why it matters | Risk if left unresolved |
|---|---|---|---|
| 1 | **No production schema has ever been captured.** No `mysqldump --no-data` from a live install exists. | With 59 runtime-created tables, at least eight carrying two or three competing definitions, and column types decided by whichever code path ran first, **the deployed schema is not derivable from source.** | **Blocks every data-migration estimate in this blueprint.** A migration written against the migration files may not match any real install. **HIGH.** |
| 2 | **Which source tree is authoritative is unconfirmed.** The tree analysed here has 59 Blade views, no `resources/js`, and **does** contain `resources/prototype/app.html`; the prior dossier's tree had 72 views, a `resources/js` and **no** prototype. | Counts, file inventories and "this file does not exist" findings differ between trees. | Acceptance criteria derived from counts may be wrong. **MEDIUM.** |
| 3 | **The test suite has never been run in this analysis.** 17 files, 132 cases, no CI. The vendor's own session report says the 23 SBB tests were never executed. | The punch-ingest tests are the one asset the migration plan proposes to port wholesale. | If they do not pass, the highest-value salvage item is unverified. **MEDIUM.** |
| 4 | **The client-packaging tree (`PKG:`) is absent.** `env.client.template`, `ClientBuilder.bat`, `smartprs-config.json`. | Two reported CRITICAL findings depend on it: a shared licence-signing secret present in every client build, and a weakly-generated Ametecs support super-admin password on every client server. | Cannot confirm or refute two potentially severe exposures. **HIGH.** |
| 5 | **The `deploy/` tree is absent.** No cron file, no Supervisor/systemd unit, no nginx config, no runbook. | The entire operational story of SmartPRS 2 lives there. Every claim about the cron line, the worker queue name, `numprocs`, `stopwaitsecs` and output redirection is unverified. | The most likely production failure — a stopped scheduler or worker — has no artefact in the repository to prevent it. **HIGH.** |
| 6 | **`bootstrap/app.php` and `config/dompdf.php` were not readable in every pass.** | Middleware aliasing, the global stack, the empty `withExceptions` closure, and dompdf's `isRemoteEnabled` / `chroot` / `enable_php`. | One unsized finding (unescaped template body → dompdf). **LOW–MEDIUM.** |
| 7 | **`public/.htaccess` does not exist in the tree.** | On Linux/Apache the deny rule for `/storage` must come from outside version control. Five upload paths write to the `public` disk. | Whether identity documents are publicly reachable is **deployment-dependent and unknown**. **HIGH.** |
| 8 | **`SMARTPRS_TEAM_PIN` — dead config or live bypass?** Ships the literal default `'ametecs'` and is documented as unlocking unrestricted demos with no write-guard and no hidden screens; a controller comment says the PIN was removed at rev185. | If a reader remains anywhere in a shipped build, it is a publicly documented bypass. | **Security-blocking. HIGH.** |
| 9 | **How many installs carry `admin@smartprs.local` / `password`?** `OnPremWorkspaceSeeder` creates it, is not environment-gated, and its own docblock instructs running it on an on-prem install; the README publishes the credential. | Unknown blast radius across the deployed base. | **This is the highest-urgency operational item in this blueprint.** **CRITICAL.** |
| 10 | **The published RBI directions the audit pack is built against are not in the repository.** The landing page references directions "Effective 1 October 2026" and five obligations. | The audit item set cannot be verified against its authority, only against the code. | The vertical's flagship artefact may not match current regulation. **MEDIUM.** |
| 11 | **No performance measurement has ever been taken.** Row counts, response times and memory use at 1,000 / 5,000 / 10,000 employees are modelled, not measured. | `/app/data` is projected to exhaust PHP's memory limit at ~5,000 employees. | Scale ceiling is estimated, not proven. **MEDIUM.** |
| 12 | **No customer data has been examined.** Data quality — NULL and `0` tenant rows, duplicate PANs, orphaned children, `VARCHAR` date columns holding mixed formats — is unknown. | The migration will meet whatever is actually there. | Migration effort is unestimated below the schema level. **HIGH.** |
| 13 | **The vendor's own commercial records were not reviewed.** Number of live on-prem installs, editions sold, AMC status, seat counts, support ticket volume and cause mix. | Part II's cost argument and the commercial case in the later parts rest on structure, not on measured spend. | The business case is qualitative where it could be quantitative. **MEDIUM.** |

---

## I.12 Open items for the Product Owner

| # | Question | What is known | Recommendation |
|---|---|---|---|
| **D-I-01** | **What is the canonical module catalogue?** | Six in-code vocabularies (16 / 10 / 14 / 50 / 57 / 107), a marketing claim of "16 modules", and a licence agreement referencing a catalogue that is not in the repository. | Adopt §I.4.2's 30-module inventory as the engineering catalogue; agree a commercial list of ≤16 sellable modules on top of it; make both a seeded reference table that the permission model, plan gate, edition gate, licence and price list all read. **Blocks the module-pack boundary.** |
| **D-I-02** | **Are the three on-prem editions real?** | `Edition::level()` returns a literal `3`; the revert path is deliberately preserved. Three price points, one product. | Either implement tiering against the module registry or stop selling three tiers. It is a commercial decision, not a technical one. Recommendation: implement it, because the constants already state the intended split and the registry makes it cheap. |
| **D-I-03** | **Is plan-based module gating a product capability?** | Dead in two independent places; sold on the landing page; the seeder's own docblock calls it "parked". | Build it once, server-side, against the module registry. It is the same mechanism as D-I-02. |
| **D-I-04** | **What is a seat?** | Active on-roll employees pooled across companies; archived employees still bill; off-roll agents never bill; two of five doors bypass the cap; the whole thing fails open; and a second contradictory definition exists on the on-prem counter. | Define the pricing metric in one sentence before any billing data migrates. Recommendation: a seat is a **Worker with an active employment on the last day of the billing period**, counted by the persistence layer, with off-roll workers billable at a separate rate. |
| **D-I-05** | **Should off-roll agents be billable, and should they be compliance-scanned?** | They are potentially the majority of a collections field force, consume no seat, have no expiry dates on their credentials, and sit outside every compliance mechanism. | Yes to both. This is simultaneously the largest revenue question and the largest compliance-promise gap in the product. The spine's Worker abstraction makes "scanned by construction" the default. |
| **D-I-06** | **Which vertical capabilities are a paid module pack, and which move to core?** | §I.6.2 recommends: pack = agent authorisation, off-roll payouts, client-bank/billing pack, escalations, audit pack, DRA gate. Core = credentials, policy acknowledgement, cases, references, the expiry-alert engine. | Confirm, because it determines whether the core is sellable to security agencies, staffing, logistics and facilities management without a rewrite. |
| **D-I-07** | **Should a user be able to hold more than one role?** | SmartPRS 2 enforces exactly one. The `accountant` control exists precisely because a small agency needs an "HR Manager who is also Accounts" — and cannot have one. | Yes. Spine S13 already separates permission from data scope and allows a user to hold several role→scope assignments. |
| **D-I-08** | **Who is the named compliance owner who signs a rule pack?** | Statutory values live in PHP constants and in four places of hard-coded prose. Reaching 200 on-prem customers with a PT slab change means 200 application upgrades. | Spine S12 D-03. Name a person. Without one, rule packs cannot ship. |
| **D-I-09** | **What is the target scale per tenant?** | The largest known figure in the analyses is a modelled 10,000 employees, at which `/app/data` fails outright. Real customer headcounts are not recorded here. | Fix a number. It sets the pagination, indexing and job-decomposition targets for every later part. |
| **D-I-10** | **Should `ai-assistant` be built or removed?** | A nav item shown to four roles, including the vendor's own, with no controller, route or renderer. | Remove it from SmartPRS 2 now; decide separately whether SmartPRS 3 ships one. |

---

*End of Part I.*
