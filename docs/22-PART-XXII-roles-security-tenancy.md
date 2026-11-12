# PART XXII — ROLES, SECURITY, DATA SCOPE AND TENANCY

Conforms to: SPINE.md — S1 (the seven laws, principally **LAW 5** authorisation
and **LAW 6** tenancy), S3 (the organisation model and its dimensions), S3.1–S3.2
(scope and precedence), S4 (effective dating and versioning), S5 (SPX), S8
(workflow — maker-checker on role and credential changes), S9 (the entity schema
engine — field-level PII classification and surface rules), **S13 (the security
model — this part is its full specification)**, S14 (the audit ledger), S15
(explainability), S16 (data conventions), S17 (API and integration), S18
(deployment parity).

Master brief sections covered: **69** (permissions and roles), **70** (data
scope), **71** (multi-tenancy and isolation), **72** (sensitive data and
document security), **73** (authentication), **115** (the security test suite).

Evidence base: `analysis/G-security-tenancy-api.md` (§0.4 counts, §3 BR-AUTH /
BR-SEC / BR-TEN registers, §5.2, §6 D-01…D-24, §7.1 verification, §7.2 SEC-01…
SEC-30, §7.3 the unguarded-route sweep, §7.4 the IDOR register, §7.5 CSRF, §7.6
secrets, §7.7 uploads, §9 migration verdicts, §10 requirements, §11 open
questions); `analysis/K-frontend-spa.md` (§2.1 the `app_state` blast radius,
§6.17, §7.2, §7.4, §7.6, §7.8, Q4, Q6); `analysis/L-background-ops-observability.md`
(SEC-L-01, SEC-L-03, SEC-L-06, DEF-L-08, DEF-L-28, DOC-L-10, OQ-L-01, OQ-L-14);
`analysis/N-product-business-modules.md` (§1.6 the nine role strings and the
five disagreeing registries, §1.7 the module inventory, §2.4 the `accountant`
defect).

---

## XXII.0 Purpose

This part specifies **who may do what, to which records, in which tenant** — and
the machinery that makes those three questions answerable by the server, in one
place, and never by the browser.

It decides five things:

1. **That permission and data scope are separate axes.** A permission answers
   *may this actor perform this verb on this resource type?* A data scope answers
   *over which records?* SmartPRS 2 fuses both into a role-name string compared
   inside a controller method, and possesses exactly one scope mechanism —
   `selfScope()` — which is a two-branch test on the literal role name `employee`
   (`AppDataController.php:224–232`).
2. **That roles are tenant-owned data, not code.** A tenant administrator may
   create "Branch Payroll Officer", grant it a permission set, and bind it to the
   Hyderabad branch, without a deployment. SmartPRS 2 hard-codes its role
   vocabulary in five registries that already disagree with one another
   (`config/smartprs.php:88–94`, `RolePermissionSeeder.php:41–58`,
   `UserController::ASSIGNABLE:26–32`, `StaffController::ROLES:17–20`,
   `AppController::ROLE_MAP:15–21`).
3. **That authorisation is enforced at a layer no developer can forget.** Every
   route binds to a declared operation; every query passes through a scoping
   layer that applies tenant and data scope in the persistence layer. A route
   with no declared operation fails the build. A query written without a tenant
   predicate returns nothing, not everything. SmartPRS 2's tenancy is 1,375
   hand-written query sites of which 405 carry no filter at all.
4. **That every control fails closed.** An exception inside authorisation denies
   and alerts. SmartPRS 2 has six controls that return "allowed" from a `catch`,
   under an explicit source comment — *"fail open — availability over
   strictness"* (`ApprovalService.php:377–379`).
5. **That no default credential exists anywhere, in any environment.** No
   seeder, no installer, no scheduled job, no release artefact. SmartPRS 2 ships
   `admin@smartprs.local` / `password` in `OnPremWorkspaceSeeder.php:74–92`,
   publishes it in the README, and re-mints three known-password logins every
   night at 03:30 on any server running the scheduler
   (`routes/console.php:208–211`).

**R-XXII-001** Authorisation in SmartPRS 3 MUST be expressed as the triple
`(actor, permission, data_scope)` and MUST be evaluated server-side on every
protected operation. No authorisation decision may be made by, or depend on,
client code. *(Law 5.)* *Test:* the browser-facing capability payload is derived
from the server catalogue by a generator; a CI check asserts no hand-maintained
permission map exists in front-end source.

**R-XXII-002** No role name, permission name, scope name, threshold, lockout
count, password length, session duration or masking rule may appear as a literal
in application code. Every one of them is a row in a versioned configuration
artefact or a platform constant table. *(Law 1.)*

**R-XXII-003** Every authorisation evaluation MUST fail closed. An exception, a
null actor, a null tenant, an unknown role, an unmapped permission, an
unresolvable scope, a cache miss on a required lookup, or a timeout MUST all
produce a denial, MUST write an audit row of type `authz.error`, and MUST raise
an operational alert. *(Law 5.)* *Test:* fault injection — §XXII.14.11.

**R-XXII-004** `tenant_id` is `NOT NULL` on every business table, participates in
every business unique index, and is applied by the persistence layer. There is no
null-tenant business state and no global business table. *(Law 6.)*

**R-XXII-005** No production artefact — seeder, installer, migration, console
command, scheduled task, container image, or client package — may create,
restore, or reset an account with a credential that is known outside the
installation. *(Spine S13, final bullet.)* *Test:* §XXII.14.14.

---

## XXII.1 What SmartPRS 2 does today, and why it must change

This section is the justification for every design choice that follows. It is
quantified, and every figure traces to `analysis/G-security-tenancy-api.md` §0.4
unless otherwise cited.

### XXII.1.1 A permission framework that is installed and never read

Spatie's permission package is present, migrated and seeded. It is also entirely
dead as a decision mechanism.

| Measure | Value | Source |
|---|---:|---|
| `hasRole(` / `hasAnyRole(` call sites across the tree | **62** | grep, G §0.4 |
| `can()`, `Gate::`, `authorize()`, `@can`, `permission:` middleware — **combined** | **0** | grep, G §0.4 |
| `App\Policies` directory | **does not exist** | `ls`, G §0.4 |
| Permissions seeded and attached to roles | **13** | `RolePermissionSeeder.php:21–63` |
| Reads of any of those 13 permissions | **0** | G §6 D-08 |
| `ApprovalService::denyUnlessRole` call sites | **71** | G §0.4 |
| `FeaturePermissions::guard` call sites | **16** | G §0.4 |

The product therefore ships a permission table, a `role_has_permissions` pivot,
a "Roles & Permissions" screen and a marketing claim — and decides nothing with
any of them. Every real decision is a literal role-name array inside a controller
method. The entire server-side vocabulary of the write guard is **four lists**
across roughly seventy call sites: `['admin']`, `['admin','hr_manager']`,
`['admin','hr_manager','accountant']`, `['admin','hr_manager','employee']`
(N §1.6.2).

The screen tells the truth about itself. `rolesScreen()`
(`AppController.php:3328–3372`) renders the footer:

> *"This defines each role's permissions. Enforcement on real logins is the next
> phase."*

**Design consequence.** SmartPRS 3 does not retain the Spatie decision layer.
It retains the *storage* idea — a role/permission pivot — and replaces the
decision layer with a declared operation registry bound at the routing layer
(§XXII.4). The 13 seeded permissions are discarded; the 11 capability keys in
`FeaturePermissions` are the only genuine seed of a capability catalogue and are
carried into §XXII.2.2 as source material, not as a schema (G §9).

### XXII.1.2 Nine role strings, five registries, four non-functional

Nine distinct role strings are tested somewhere in `app/` (N §1.6.1). Five
registries declare a role vocabulary, and no two agree (N §1.6.2):

```
config/smartprs.php:88-94    super_admin  admin  hr_manager  field_agent  employee
    header says "Roles (locked)" — and grep "smartprs.roles" returns 0 reads
RolePermissionSeeder:41-58   super_admin  admin  hr_manager  field_agent  employee
UserController::ASSIGNABLE                admin  hr_manager  field_agent  employee  accountant
StaffController::ROLES       super_admin                                            platform_support
AppController::ROLE_MAP      super_admin  admin  hr_manager  field_agent  employee
prototype ROLES map                              HR Manager  Field Agent  Employee
```

Of the nine strings, **four are non-functional**:

| Role string | Status | Evidence |
|---|---|---|
| `accountant` | **No creation path.** Offered in the tenant dropdown; `syncRoles(['accountant'])` throws `RoleDoesNotExist`; the `catch (\Throwable)` swallows it; the API returns `{"ok":true}`. Two `denyUnlessRole` sites already grant it authority that therefore never works. | `UserController.php:26–32, 150–152, 298–308`; `RequestController.php:1851`; `BankPackController.php:64`; no `Role::findOrCreate('accountant')` anywhere (G §6 D-05) |
| `manager` | **No creation path.** Checked in three write-permission lists which are consequently inert. | `FeaturePermissions.php:47, 51, 67` (G §6 D-06) |
| `hr` | **No creation path.** A phantom distinct from `hr_manager`; checked in two places. | `FeaturePermissions.php:55`; `WaTemplateController.php:209` (G §6 D-06) |
| `platform_support` | **Creatable but gated by nothing.** Created by `StaffController.php:27` with `tenant_id = NULL` — the widest data scope in the product — and checked by **zero** functional guards; renders as "Admin" in the SPA. | `StaffController.php:19, 27, 49`; grep: 0 `hasRole('platform_support')` (G §7.1 #16) |

*Note on precision.* Three of the four have no creation path at all; the fourth
is created and then never consulted. Both failure modes produce the same
operational outcome — a role that cannot express an intent — which is why they
are counted together here. The migration treatment of all four is specified in
§XXII.15.3.

Two further structural facts:

- **One role per user.** Both assignment surfaces call `syncRoles([$role])`
  with a single-element array (N §1.6.2). There is no "HR Manager who is also
  Accounts", which is precisely the shape the commission money-chain control
  requires.
- **Roles are global, not per tenant.** `config/permission.php:138` sets
  `'teams' => false`, so one `roles` row named `admin` is shared by every
  customer on the installation (G BR-TEN-013). Per-tenant role definition is not
  merely unimplemented; it is structurally impossible.

**Design consequence.** §XXII.2.3 makes roles tenant-owned, multi-assignable
configuration artefacts; §XXII.15 gives the shipped default catalogue and the
migration map for all nine strings.

### XXII.1.3 The declared tenancy mechanism is inert, and the real one is manual

`CompanyScope` is an Eloquent global scope applied through the
`BelongsToCompany` concern by eight models — `Employee`, `Attendance`,
`Department`, `Device`, `Loan`, `SalaryRun`, `SalarySlip`, `LeaveApplication`
(G §6 D-02). It predicates on `$user->company_id`.

**`users.company_id` does not exist.** The migration whose filename promises it —
`2026_01_01_000002_add_company_id_to_users_table.php:13–17` — adds `tenant_id`,
`employee_id` and `status` instead. No runtime `ALTER users ADD company_id`
exists anywhere in the tree. The scope therefore adds no predicate on any query,
on any of the eight models, ever (G §6 D-01, `CompanyScope.php:31–32`).

The docblock at `CompanyScope.php:10–16` reads *"Global scope that constrains
every query on a tenant-owned model to the currently authenticated user's
company."* It describes behaviour that has never worked (G §8 #10).

What actually isolates tenants is a hand-written predicate, repeated:

| Measure | Count | Share |
|---|---:|---:|
| `DB::table()` call sites in `app/` | **1,375** | 100 % |
| … carrying a `tenant_id`/`company_id` predicate in the same statement | **616** | 44.8 % |
| … filtered by `where('id', …)` **only** — IDOR candidates | **354** | 25.7 % |
| … with **no** filter at all | **405** | 29.5 % |

The predicate itself fails open by construction. It is written
`->when($tid, fn($q) => $q->where('tenant_id', $tid))`. Laravel's `when()` skips
its callback when the value is falsy, so **`NULL` and `0` both mean "no tenant
filter"** (G BR-TEN-002). `AppStateController.php:19` coerces a null tenant to
`0` explicitly. The mechanism intended to widen scope for `super_admin` is
therefore inherited by `platform_support`, by any account provisioned with a null
tenant, and by any code path that loses the value.

Two consequences already visible in the shipped product:

- Legacy controllers resolve `auth()->user()->company_id ?? Company::value('id')`
  — the first company row on the box — and write into it
  (`AttendanceController.php:39,52`; `DeviceController.php:35`;
  `LoanController.php:32`; `SalaryRunController.php:42`;
  `DepartmentController.php:68`; `EmployeeController.php:88`; G §6 D-03).
- Attendance rows with `tenant_id IS NULL` are counted in **every** tenant's
  "Present Today" figure (`DashboardController.php:99–100`, G BR-TEN-005), and
  `kb_topics` — which holds the Code of Conduct that agents legally acknowledge —
  is written with `tenant_id = NULL` and read with no tenant predicate at all
  (G SEC-30).

**Design consequence.** §XXII.5 makes tenancy structural: non-nullable column,
foreign key, participation in every unique index, and a persistence-layer scope
that a developer cannot omit — because the omission returns nothing rather than
everything.

### XXII.1.4 Six controls that fail open, by design comment

| Control | Site | Behaviour on exception |
|---|---|---|
| `ApprovalService::denyUnlessRole` (71 call sites) | `ApprovalService.php:365–380` | `return null` — i.e. **allowed**. Source comment: *"fail open — availability over strictness on pivot errors"* |
| `FeaturePermissions::guard` (16 call sites) | `FeaturePermissions.php:99–105` | returns allowed; an unknown capability key degrades to `['admin','hr_manager']` at `:102` |
| `ApiKeyController::authorizeScreen` | `ApiKeyController.php:509–521` | duplicates the same `catch` |
| `EditionGuard` | `EditionGuard.php:55–57` | `return $next($request)` |
| `DemoWriteGuard` | `DemoWriteGuard.php:73–75` | `return $next($request)` |
| `LicenseGate` | `LicenseGate.php:73–75` | `return $next($request)` |
| `EnsureSubscriptionActive` | `EnsureSubscriptionActive.php:58–59` | `return $next($request)` |

Four middleware plus two authorisation helpers — six controls (G §6 D-17,
SEC-01). No attacker action is required. A Spatie cache-store outage, a
`model_has_roles` table missing after a partial migration, or a transient
database error is sufficient. For the duration of the fault, **every role-gated
write in the product** — payroll approval, commission payment, employee deletion,
user management, API-key minting — is available to a plain `employee`, and **no
log line is written**.

The same pattern appears outside this area: the licence check grants a full
session from its `catch` (`AuthController.php:267–269`, G BR-AUTH-008), and the
demo privacy wipe — the control that decides whether a prospect's typed data must
be erased — returns "nothing due" from its `catch` with no log and no alert
(`DemoReset.php:180–182`, L SEC-L-05).

**Design consequence.** §XXII.4.7 specifies fail-closed semantics and the
fault-injection tests that prove them, one per guard family.

### XXII.1.5 The unguarded surface

**78 authenticated routes have no server-side role or self check** (G §0.4,
§7.3, established by a scripted sweep of each routed controller method for any
of `denyUnlessRole | hasRole | hasAnyRole | abort_unless | FeaturePermissions::guard
| isSuperAdmin | canManage | selfScope | isManager | isHR( | isFinance( |
isAccounts( | authorizeScreen | hrDeny`). Of those, 17 are legitimately open to
every authenticated user; **61 are real gaps** (G §7.3), including:

| Group | What can be done by any authenticated user |
|---|---|
| Whole-tenant state | read **and overwrite** everything, including the role matrix |
| Payroll | approve/reject a run, decide lines, read every net pay, preview the bank payable |
| Statutory | `GET /app/statutory/{type}/pdf` — every employee's PAN, UAN, PF wages and TDS |
| Personnel documents | warning letters, increment letters carrying CTC, ID cards, photos |
| Attendance writes | inject and approve bulk attendance — the direct input to pay |
| Requests | upload with a **caller-supplied** `proof_path` |

Two of these deserve separate statement because they are architectural, not
incidental.

**`POST /app/state` — persistent, tenant-wide privilege escalation.**
`AppStateController` is 54 lines and contains **zero guards** on either
`show()` or `save()` (`AppStateController.php:22–53`, G §7.1 #15, K §2.1).
`save()` accepts anything that satisfies `is_array($state)` and `json_encode`s it
wholesale over the tenant's single `settings` row keyed `app_state`. That blob
contains roughly sixty collections — and among them `DB.roles[].perms`, the
role→permission matrix that `applyPerms()` reads on every login for every user
(`AppController.php:676–683, 769–790`). Any authenticated user may therefore POST
a `roles` entry for their own role label with every permission set true, and it
is loaded at the next boot for the whole tenant. The same endpoint accepts
`{"state":{}}`, which empties every non-normalised screen in the tenant with no
history, no soft delete and no audit row (K §7.4). CSRF still applies, so the
path requires a session rather than a cross-site POST (G §7.5) — which is the
only mitigating fact available.

**`GET /app/master/{type}` — ungated for ~56 of ~58 registry types.**
`MasterController::list()` narrows rows for exactly two types, `helpdesk` and
`posh` (`MasterController.php:586–588, 644–648`; registry at `:33–480`; G §6
D-16, SEC-06). Every other type is readable by any authenticated user of the
tenant. Among them: `wa_settings.api_key` and `sms_settings.api_key`
(`:454–465`) — leaked keys with a direct financial cost — biometric device
passwords, `companies` PAN and GSTIN, `banks`, `salary-setup`, `min-wages`,
`agent-auth`, `dra-certs`, `bgv` and `documents`.

**Design consequence.** §XXII.4.2 makes the operation binding mandatory at the
routing layer and asserts it with a CI sweep, so that "nobody wrote a check"
becomes a build failure rather than a shipped route. §XXII.4.3 removes the
`app_state` blob's successor from existence: the permission matrix lives in
relational tables with referential integrity and maker-checker (§XXII.10).

### XXII.1.6 Three privilege-escalation paths in ordinary use

**`hr_manager` may mint an `admin`.** `canManage` is
`['super_admin','admin','hr_manager']`, and the same screen permits setting the
password of any user in the tenant, including the tenant administrator
(`UserController.php:26–37, 150–152, 262–278`; G SEC-07). Neither action writes
an audit record. There is no "may not grant above self" rule anywhere.

**A null-tenant actor administers the whole installation.**
`UserController::save`, `setStatus` and `setPassword` all use
`->when($tid, …)`; for an actor whose `tenant_id` is NULL the predicate is
skipped and the target is any user id on the box
(`UserController.php:181–182, 224–225, 247–249, 270–278`; G SEC-08). This is
reachable through `StaffController` plus a pivot edit, or through a provisioning
path that leaves the tenant null.

**Assigning "Accountant" produces a role-less super-reader.** The dropdown option
exists; the role does not; the exception is swallowed; the user is created with
no role at all (`UserController.php:298–308`). `AppController.php:93` resolves an
unmapped role to the display label `'Admin'`, and `:769` returns from
`applyPerms()` before any gating for exactly that label. `selfScope()` narrows
only the literal role `employee` (`AppDataController.php:224–232`), so the
account is not narrowed either. The administrator's intent — "give this person
the money-verification control" — produces a read-everything account presenting
itself as Administrator (G §6 D-05, SEC-09).

There are three further fail-open defaults on the same path: an absent
permission matrix returns `true` (`AppController.php:760`); an unknown capability
key degrades to `['admin','hr_manager']` (`FeaturePermissions.php:102`); and the
shell's own `roleAllows()` returns `true` for any role not among its three keys
(N §1.9, K NEW-FE-014/015/016).

### XXII.1.7 Authentication has no policy surface

| Control | SmartPRS 2 state | Evidence |
|---|---|---|
| Password minimum length | **8**, and no other rule | `AuthController.php:401, 444, 527`; `UserController.php:267`; `InstallController.php:178` |
| … for platform Super Admins | **6** — the most privileged accounts carry the weakest rule | `StaffController.php:43, 64` |
| Complexity, history, expiry, breach check | **none of the four exists** | G BR-AUTH-010/011, §5.2 item 5 |
| Account lockout | **does not exist** — no `failed_login`, no `locked_until`, no `Failed` listener | G NEW-SEC-110 (verified by absence) |
| MFA | **does not exist** — no TOTP or WebAuthn package in `composer.json` | G NEW-SEC-110 |
| Login throttle | 5/min **per IP**; nothing per account | `routes/web.php:208` |
| `POST /app/change-password` throttle | **none** — an online current-password oracle | `routes/web.php:376`; G SEC-14 |
| Account-existence oracle | login reveals that an account exists but is disabled | `AuthController.php:292–295` |
| Session cookie `Secure` | **no default**; `.env.example` does not set it | `config/session.php:173`; G NEW-SEC-107 |
| Session absolute lifetime | **none**; idle 120 min; remember-me is the framework 5-year default | `config/session.php:35, 37`; G NEW-SEC-109 |
| Session payload encryption | off by default | `config/session.php:50` |
| Token expiry windows | three literals — 1 h reset, 48 h invite, 72 h welcome | `AuthController.php:353–360`; `UserController.php:331`; `SaasController.php:548` |
| Public bearer tokens (offer, NDA, transfer, agent earnings, quote, licence) | **never expire**, stored in cleartext, not revocable | G SEC-17 |
| Temporary passwords | emailed in cleartext on the paid-signup path; sent over WhatsApp by default and persisted forever in `wa_log.body_values` | `SaasController.php:185, 558–575`; `SignupController.php:413–425`; L SEC-L-03 |

`123456` is a valid Super Admin password (G SEC-11).

### XXII.1.8 Credentials that ship in the product

**A nightly job mints known-password administrators.**
`Schedule::command('demo:reset')->dailyAt('03:30')` carries no `->when()`, no
`APP_ENV` reference and no `SMARTPRS_DEMO_DATA` consultation
(`routes/console.php:208–211`; `DemoReset` never reads either, verified by grep,
L SEC-L-01). On any server running the scheduler it creates
`demo-admin@smartprs.in` with the Spatie role **`admin`**, plus `demo-hr@` and
`demo-field@`, each `status='active'`, each with
`password = Hash::make('smartprs-demo')` — the option default at `DemoReset.php:35`,
documented in the class docblock at `:26` and printed to stdout at `:152`. The
lookup at `:106` is `whereRaw('LOWER(email) = ?')` with **no tenant scope**, and
the update at `:108–111` reassigns `tenant_id` and overwrites the password — so
if those addresses already exist under another tenant they are taken over.
Nightly re-creation defeats manual deletion; nightly password reset defeats a
manual password change.

**The on-prem seeder ships a fixed administrator credential.**
`OnPremWorkspaceSeeder.php:74–92` creates `admin@smartprs.local` with
`Hash::make('password')` at `:78`, attached to `DB::table('tenants')->orderBy('id')->value('id')`
— an arbitrary tenant — with no `SMARTPRS_DEMO_DATA` and no `app()->environment()`
guard; `DatabaseSeeder.php:19–30` does not call it, so the env switch that would
have gated it is never consulted. `DemoCompanySeeder.php:69` carries the same
literal. And the repository's own `README.md` publishes the credential, describing
seeded logins with the password `password` (L DEF-L-28, DOC-L-10) — it is the
first document an operator reads.

### XXII.1.9 The scoreboard

Every row below is a defect this part exists to remove. Nothing in this part is
elaborate for its own sake.

| # | SmartPRS 2 defect | Evidence | Answered by |
|---:|---|---|---|
| 1 | 62 role checks, 0 permission checks, no policies, 13 dead permissions | G §0.4, D-08 | §XXII.2, R-XXII-010…039 |
| 2 | 9 role strings, 5 disagreeing registries, 4 non-functional | N §1.6; G D-05/06/07 | §XXII.2.3, §XXII.15.3 |
| 3 | One role per user; roles global across tenants | `syncRoles([$one])`; `permission.php:138` | R-XXII-030, R-XXII-025 |
| 4 | Permission and data scope fused; `selfScope` is the only scope | `AppDataController.php:224–232` | §XXII.3 |
| 5 | `CompanyScope` inert on 8 models; `users.company_id` absent | G D-01/D-02 | §XXII.5.1 |
| 6 | 1,375 query sites; 616 filtered, 354 id-only, 405 unfiltered | G §0.4 | §XXII.4.4 |
| 7 | `NULL`/`0` tenant means "all tenants" | G BR-TEN-002 | R-XXII-111, R-XXII-118 |
| 8 | Six controls fail open by design comment | G D-17, SEC-01 | §XXII.4.7 |
| 9 | 78 authenticated routes unguarded | G §7.3 | §XXII.4.2 |
| 10 | `POST /app/state` = tenant-wide privilege escalation | G SEC-03; K §2.1 | R-XXII-021, §XXII.10.2 |
| 11 | `/app/master/{type}` ungated for ~56 of ~58 types; leaks API keys, biometric passwords, PAN/GSTIN | G SEC-06 | §XXII.4.2, §XXII.6 |
| 12 | `hr_manager` → `admin`; unaudited password set; null-tenant actor unfiltered | G SEC-07, SEC-08 | R-XXII-033, R-XXII-034 |
| 13 | "Accountant" silently produces a role-less user rendering as Admin | G D-05, SEC-09 | R-XXII-024, §XXII.15.3 |
| 14 | 18 IDOR endpoints; sequential ids in URLs | G §7.4 | R-XXII-072, spine S2 |
| 15 | `min:8` / `min:6`, no complexity, history, expiry, lockout or MFA | G SEC-11 | §XXII.7 |
| 16 | Nightly known-password admin; on-prem seeder credential; README publishes it | L SEC-L-01; G SEC-20 | R-XXII-005, §XXII.14.14 |
| 17 | Private documents on the web-served disk; extension-only validation | G SEC-23 | §XXII.6.5–6.7 |
| 18 | Identity and access effectively unaudited (11 sites; only successful login) | G SEC-21, D-18 | §XXII.4.9 |
| 19 | Audit hash chain global across tenants; `verify()` unbounded | G D-19, SEC-28 | R-XXII-127, spine S14 |
| 20 | 1 authorisation test file, 3 assertions, 0 negative role assertions | G D-15 | §XXII.14 |

---

## XXII.2 The permission model (brief Section 69)

### XXII.2.1 The grammar

A permission is a **`verb.resource`** string over a closed, platform-owned
catalogue. It names a functional capability and nothing else — never a record
set, never an org unit, never a person.

```
permission_code  ::=  <module>.<resource>[.<qualifier>].<verb>

module      the owning bounded module          payroll, attendance, employee, …
resource    the noun the verb acts on          run, day, document, structure, …
qualifier   optional narrowing of the noun     sensitive, own, bank, statutory
verb        the action                         view, list, create, update, delete,
                                               submit, approve, reject, publish,
                                               export, import, download, recalculate,
                                               lock, unlock, reopen, impersonate,
                                               configure, assign, revoke
```

Examples, all real entries in the catalogue below:
`payroll.run.approve` · `employee.document.download` ·
`employee.field.sensitive.view` · `rules.artefact.publish` ·
`platform.tenant.impersonate`.

**R-XXII-010** Permission codes MUST be drawn from a platform-owned catalogue
seeded by migration and versioned with the release. The catalogue is **extensible
by a defined process, not closed**, and the process has exactly two paths:

| Path | Who | Mechanism | Binds to |
|---|---|---|---|
| **A — Ametecs-authored entry** | Ametecs only | A new row in the seeded catalogue, shipped in a release migration, carrying `business_description`, `risk_class`, `implies[]`, `requires_mfa`, `requires_reauth_seconds` (R-XXII-011, R-XXII-014, R-XXII-015) | An operation declared in code |
| **B — reserved generative namespace** | The tenant, indirectly | A tenant-created artefact in a **reserved namespace** mints its permission codes mechanically from its own key. The namespaces are `form.<form_key>.<verb>`, `report.<report_key>.<verb>` and `dashboard.<dashboard_key>.<verb>`, and no others. The generated rows are real catalogue rows, written by the platform when the artefact version is published and revoked when it is retired | The generic operation that serves every artefact of that kind |

A tenant MAY NOT invent a permission code outside a reserved namespace, and MAY
compose any catalogued code into roles freely.

*Why this changed.* The catalogue was previously declared **closed**. That is
contradicted twice over. Fourteen parts already declare operations on codes the
catalogue does not carry — the generated register counts **395** of them
(§XXII.2.2a) — so "closed" plus R-XXIV-022 fails the build on those routes on day
one. And Appendix B's `D-B-06` gives tenants **custom forms**, whose permissions
cannot be enumerated in advance by definition: a code for a form that does not
exist yet cannot be seeded by a migration. A closed catalogue and tenant-created
artefacts cannot both be true.

*The resolution, stated as the design.* Closure is preserved where it earns its
keep — a code that gates Ametecs-written code must be Ametecs-authored, because
the binding is between the catalogue and the code. Closure is abandoned only for
the artefacts the tenant creates, and there it is replaced by a **generative
grammar over a reserved prefix**, which is checkable: the platform can prove that
a `form.*` code corresponds to a published `cfg_form_definitions` row, and can
reject any code matching no rule and no reserved namespace. The tenant never
authors a description, a risk class or an `implies[]` edge; those are derived
from the artefact's own declared sensitivity.

**R-XXII-010a** The three reserved namespaces `form.*`, `report.*` and
`dashboard.*` are **generative**: their codes are minted and revoked by the
platform from published artefact versions, never hand-seeded and never
hand-deleted. A migration MUST NOT insert a row in a reserved namespace, and the
catalogue validator MUST reject one that appears.

*The reserved namespaces are exclusively tenant-minted.* `form.*`, `report.*` and
`dashboard.*` hold **only** codes minted from a published tenant artefact. An
**engine** capability over those features — may this user run a report, publish a
dashboard, author a form definition — is an ordinary Ametecs-authored permission
and lives under the **plural** prefix: `reporting.*`, `dashboards.*`, `forms.*`.
*Why.* The two kinds are indistinguishable by shape, so an engine code in a
reserved namespace is unresolvable in both directions: R-XXII-010a forbids
seeding it, so it can never be catalogued, so R-XXII-010b and R-XXIV-022 fail the
build on every route that binds it — and it collides outright with the codes it
is not, since a tenant form keyed `definition` mints exactly `form.definition.*`.
The singular/plural split keeps the reserved namespaces unambiguous and is the
reason `reporting.run`, `dashboards.view` and `forms.definition.manage` are
spelled as they are. Any new engine capability over reports, dashboards or forms
takes the plural prefix.

**R-XXII-010b** Every code outside a reserved namespace MUST exist as an
Ametecs-authored catalogue row before any route binds to it. A route binding to a
code that is neither catalogued nor derivable from a reserved namespace fails the
build (R-XXIV-022). *This is the closure property R-XXII-010 previously asserted
over the whole catalogue, now asserted where it is true.*

**R-XXII-010c** The set of reserved namespaces is itself platform-owned and
closed. Adding a fourth is an Ametecs release decision, recorded here, never a
configuration change.

**R-XXII-011** Every permission row MUST carry a non-empty
`business_description` in plain business English, and a `risk_class` ∈
`{routine, sensitive, privileged, platform}`. The description is what the role
editor and the audit ledger render; a permission a customer cannot understand
cannot be delegated safely.

**R-XXII-012** The verb vocabulary is a platform constant table
(`permission_verbs`), not a literal. Adding a verb is a migration by Ametecs; it
is never a code change scattered across modules. *(Law 1.)*

**R-XXII-013** A permission MUST NOT encode a record set. `payroll.run.approve.hyderabad`
is a schema error, rejected by the catalogue validator, because Hyderabad is a
**scope**, not a capability. *Origin:* SmartPRS 2 fuses the two into a role-name
string, which is why the only record narrowing that exists anywhere in the
product is `selfScope()`.

**R-XXII-014** Every permission declares `implies[]` — zero or more permissions
it entails (§XXII.2.5). The graph MUST be acyclic; a cycle is a build failure.

**R-XXII-015** Every permission declares `requires_mfa` (boolean) and
`requires_reauth_seconds` (nullable integer). Holding the permission is not
sufficient to exercise it if the session does not satisfy the declared
assurance level (§XXII.7.4, §XXII.7.9).

### XXII.2.2 The catalogue

The catalogue is organised by the module registry. SmartPRS 2 has **six
disagreeing module vocabularies** — 16 (`PERM_NAV`), 10 (`PLAN_GROUP_KEYS`), 14
(`StandardPlanSeeder`), 50 (`Edition` nav sets), 57 (`MasterController::def()`),
107 (the SPA shell), and "16 modules" in six places on the marketing site
(N §1.7.1). SmartPRS 3 has one.

**R-XXII-016** There MUST be exactly one module registry (`modules`), and the
permission catalogue, the navigation, the plan gate, the edition gate, the
licence and the price list MUST all read from it. *Origin:* N §1.7.1 and the
licence agreement's reference to a "module catalogue" that exists nowhere in the
codebase.

**The catalogue below is not complete, and no longer claims to be.** It carries
**150** Ametecs-authored codes. The rest of the blueprint declares operations on
**545** distinct permission codes, of which **395 are not in this catalogue** —
every `compliance.*` code Part XXI uses, every `ops.*` code Parts XIX, XXVI and
XXXI use, every `statutory.*` code Part XVII uses, every `workflow.*` code Part IX
uses, and much more besides. §XXII.2.2a lists them by module and
[`docs/registers/REGISTER-PERMISSIONS.md`](registers/REGISTER-PERMISSIONS.md)
lists them in full, generated from the parts by `tools/generate-registers.py`.

Until each of those codes is either given a row here or brought under a reserved
namespace (R-XXII-010), the routes that bind to it fail the build under
R-XXIV-022. That is correct behaviour and a visible work queue, not a defect.

Columns: **R** = risk class
(`r` routine · `s` sensitive · `p` privileged · `P` platform); **MFA** = the
permission's `requires_mfa` default; **Scoped** = whether a data scope is
evaluated for it (a `no` means the permission is inherently tenant-global, e.g.
publishing a rule).

#### Platform and tenancy

| Permission | R | MFA | Scoped | Business description |
|---|:-:|:-:|:-:|---|
| `platform.tenant.list` | P | ✓ | no | See the list of tenants on this installation |
| `platform.tenant.create` | P | ✓ | no | Provision a new tenant |
| `platform.tenant.suspend` | P | ✓ | no | Suspend or resume a tenant |
| `platform.tenant.impersonate` | P | ✓ | no | Enter a tenant as a support actor (§XXII.5.6) |
| `platform.staff.manage` | P | ✓ | no | Create, disable and re-role platform staff |
| `platform.release.publish` | P | ✓ | no | Publish an application release |
| `platform.licence.issue` | P | ✓ | no | Issue, rebind or revoke a licence |
| `platform.rulepack.sign` | P | ✓ | no | Sign and publish a statutory rule pack |
| `platform.audit.read` | P | ✓ | no | Read the platform-level audit ledger |
| `tenant.settings.view` | r | — | yes | View tenant settings |
| `tenant.settings.update` | p | ✓ | yes | Change tenant settings |
| `tenant.branding.update` | r | — | yes | Change logo, colours, portal slug |
| `tenant.subscription.view` | s | — | yes | View plan, seats and invoices |
| `tenant.subscription.manage` | p | ✓ | yes | Renew, change plan, pay |

#### Identity, roles and access

| Permission | R | MFA | Scoped | Business description |
|---|:-:|:-:|:-:|---|
| `access.user.list` | s | — | yes | See the login accounts in scope |
| `access.user.create` | p | ✓ | yes | Create a login account |
| `access.user.update` | p | ✓ | yes | Change a login account's details |
| `access.user.disable` | p | ✓ | yes | Disable or re-enable a login |
| `access.user.credential.reset` | p | ✓ | yes | Send a set-password link to another user |
| `access.user.session.revoke` | p | ✓ | yes | Terminate another user's sessions |
| `access.user.mfa.reset` | p | ✓ | yes | Clear another user's MFA enrolment |
| `access.role.list` | r | — | yes | See the role catalogue |
| `access.role.create` | p | ✓ | yes | Define a new role |
| `access.role.update` | p | ✓ | yes | Change a role's permission set |
| `access.role.publish` | p | ✓ | yes | Publish a role version (maker-checker) |
| `access.assignment.grant` | p | ✓ | yes | Assign a role and scope to a user |
| `access.assignment.revoke` | p | ✓ | yes | Withdraw a role assignment |
| `access.apikey.list` | s | — | yes | See API credentials |
| `access.apikey.issue` | p | ✓ | yes | Mint an API credential |
| `access.apikey.revoke` | p | ✓ | yes | Revoke an API credential |
| `access.audit.read` | s | — | yes | Read the tenant audit ledger |
| `access.audit.export` | s | ✓ | yes | Export the audit ledger |

#### Employee master and documents

| Permission | R | MFA | Scoped | Business description |
|---|:-:|:-:|:-:|---|
| `employee.record.list` | r | — | yes | See the employee directory |
| `employee.record.view` | r | — | yes | Open an employee record |
| `employee.record.create` | s | — | yes | Add an employee |
| `employee.record.update` | s | — | yes | Change an employee record |
| `employee.record.archive` | p | ✓ | yes | Archive an employee |
| `employee.record.delete` | p | ✓ | yes | Delete an employee (maker-checker) |
| `employee.field.sensitive.view` | s | — | yes | See PAN, Aadhaar, UAN, ESIC unmasked |
| `employee.field.sensitive.update` | s | ✓ | yes | Change a government identifier |
| `employee.field.restricted.view` | s | ✓ | yes | See restricted fields (BGV detail, disciplinary) |
| `employee.bank.view` | s | — | yes | See bank account details |
| `employee.bank.update` | p | ✓ | yes | Change bank details (maker-checker) |
| `employee.salary.view` | s | — | yes | See CTC and salary structure |
| `employee.salary.update` | p | ✓ | yes | Change CTC or structure (maker-checker) |
| `employee.document.list` | r | — | yes | See the document list |
| `employee.document.download` | s | — | yes | Download an employee document |
| `employee.document.upload` | r | — | yes | Upload an employee document |
| `employee.document.delete` | s | ✓ | yes | Remove a document |
| `employee.import.run` | p | ✓ | yes | Bulk-import employees |
| `employee.export.basic` | s | — | yes | Export non-sensitive employee columns |
| `employee.export.sensitive` | s | ✓ | yes | Export identifiers, bank or salary columns |
| `employee.onboarding.manage` | s | — | yes | Run self-onboarding invitations and review |
| `employee.reference.manage` | s | — | yes | Maintain employee references |

#### Employee master — per-screen splits of `employee.view` (runtime)

`employee.view` is the code the runtime build actually checks for the People
menu's read screens (Part X, Part XX and Part XXIV use it directly; it is not
itself a row in the design vocabulary above, which is why §XXII.2.2a lists it
under "used but not catalogued"). The owner asked that the Roles & Permissions
editor (`surface/RoleMatrix.php`) offer every menu item its OWN switchable row,
never a group of items sharing one state because they happened to be gated by
the same code. Each row below is a genuine capability distinction the menu
already presents as separate screens — Directory, Probation, Teams, Transfers
and ID Cards are five different things to see, not one — and is granted
alongside `employee.view` (additively, never replacing it) to every role that
held it before this split, so nobody's access changes the day this ships; only
the editor's granularity does. This is path A of R-XXII-010 — an
Ametecs-authored entry catalogued here and nowhere else in the corpus, the same
standing as the 97 other codes in §XXII.2.2a's "catalogued, unused outside
Part XXII" list.

| Permission | R | MFA | Scoped | Business description |
|---|:-:|:-:|:-:|---|
| `employee.directory.view` | r | — | yes | See the employee directory (People → Directory) |
| `employee.probation.view` | r | — | yes | See employees on probation (People → Probation) |
| `employee.team.view` | r | — | yes | See teams and their members (People → Teams) |
| `employee.transfer.view` | r | — | yes | See employee transfers (People → Transfers) |
| `employee.idcard.view` | r | — | yes | See and print ID cards (People → ID Cards) |

#### Organisation and master data

| Permission | R | MFA | Scoped | Business description |
|---|:-:|:-:|:-:|---|
| `org.unit.list` | r | — | yes | See the org tree |
| `org.unit.create` | s | — | yes | Add a company, branch, department, team |
| `org.unit.update` | s | — | yes | Rename or move an org unit |
| `org.unit.deactivate` | p | ✓ | yes | Close an org unit |
| `org.assignment.update` | s | — | yes | Move an employee between units (effective-dated) |
| `master.registry.view` | r | — | yes | Read a master registry |
| `master.registry.manage` | s | — | yes | Maintain a master registry |
| `master.credential.view` | s | ✓ | yes | Read stored integration credentials (masked by default) |
| `master.credential.update` | p | ✓ | yes | Set an integration credential |

#### Attendance

| Permission | R | MFA | Scoped | Business description |
|---|:-:|:-:|:-:|---|
| `attendance.day.view` | r | — | yes | See attendance days |
| `attendance.punch.view.raw` | s | — | yes | See raw punch evidence |
| `attendance.punch.create.manual` | s | — | yes | Record a manual punch |
| `attendance.correction.submit` | r | — | self | Raise a regularisation for oneself |
| `attendance.correction.decide` | s | — | yes | Approve or reject a regularisation |
| `attendance.bulk.upload` | p | ✓ | yes | Upload bulk attendance |
| `attendance.bulk.decide` | p | ✓ | yes | Approve a bulk attendance batch |
| `attendance.day.recalculate` | p | ✓ | yes | Re-derive attendance for a scope and range |
| `attendance.roster.manage` | s | — | yes | Assign shifts and rosters |
| `attendance.device.view` | s | — | yes | See biometric devices and health |
| `attendance.device.manage` | p | ✓ | yes | Add, map or command a device |
| `attendance.report.view` | r | — | yes | Run attendance registers |
| `attendance.export` | s | — | yes | Export attendance |

#### Leave

| Permission | R | MFA | Scoped | Business description |
|---|:-:|:-:|:-:|---|
| `leave.request.submit` | r | — | self | Apply for leave |
| `leave.request.view` | r | — | yes | See leave requests |
| `leave.request.decide` | s | — | yes | Approve or reject leave |
| `leave.request.cancel.other` | s | — | yes | Cancel another person's approved leave |
| `leave.balance.view` | r | — | yes | See leave balances |
| `leave.balance.adjust` | p | ✓ | yes | Manually adjust a balance (maker-checker) |
| `leave.encashment.decide` | p | ✓ | yes | Approve encashment |

#### Payroll

| Permission | R | MFA | Scoped | Business description |
|---|:-:|:-:|:-:|---|
| `payroll.run.view` | s | — | yes | See a payroll run and its totals |
| `payroll.run.create` | p | ✓ | yes | Start a run |
| `payroll.run.preview` | s | — | yes | Preview a run and its exceptions |
| `payroll.run.submit` | p | ✓ | yes | Submit a run for approval (**maker**) |
| `payroll.run.approve` | p | ✓ | yes | Approve a run (**checker**) |
| `payroll.run.reject` | p | ✓ | yes | Reject a run back for correction |
| `payroll.run.lock` | p | ✓ | yes | Lock a period |
| `payroll.run.reopen` | p | ✓ | yes | Reopen a locked period (maker-checker) |
| `payroll.line.view` | s | — | yes | See individual payslip lines |
| `payroll.line.decide` | p | ✓ | yes | Hold or release an individual line |
| `payroll.payslip.view.own` | r | — | self | See one's own payslip |
| `payroll.payslip.view.other` | s | — | yes | See another employee's payslip |
| `payroll.payslip.publish` | p | ✓ | yes | Release payslips to employees |
| `payroll.bankfile.preview` | s | ✓ | yes | Preview the bank payment file |
| `payroll.bankfile.generate` | p | ✓ | yes | Generate the bank payment file |
| `payroll.disburse.mark` | p | ✓ | yes | Mark a run disbursed |
| `payroll.arrears.manage` | p | ✓ | yes | Raise and settle arrears |
| `payroll.structure.view` | s | — | yes | See salary structures |
| `payroll.structure.publish` | p | ✓ | yes | Publish a salary structure version |
| `payroll.simulate` | s | — | yes | Run a salary simulation |
| `payroll.export` | s | ✓ | yes | Export payroll registers |

#### Statutory and compliance

| Permission | R | MFA | Scoped | Business description |
|---|:-:|:-:|:-:|---|
| `statutory.config.view` | s | — | yes | See statutory configuration |
| `statutory.config.publish` | p | ✓ | yes | Publish a statutory configuration version |
| `statutory.rulepack.accept` | p | ✓ | no | Accept an Ametecs rule pack into this installation |
| `statutory.return.generate` | s | ✓ | yes | Generate ECR, 24Q, PT, LWF and similar returns |
| `statutory.return.view` | s | — | yes | View generated returns |
| `statutory.challan.manage` | s | ✓ | yes | Record challans and payment references |
| `compliance.credential.view` | s | — | yes | See DRA, PCC, BGV and authorisation records |
| `compliance.credential.manage` | s | — | yes | Maintain credential records |
| `compliance.audit.generate` | s | ✓ | yes | Produce the agent audit pack |
| `compliance.gate.override` | p | ✓ | yes | Override a compliance payment gate (audited, reasoned) |

#### Money outside payroll

| Permission | R | MFA | Scoped | Business description |
|---|:-:|:-:|:-:|---|
| `request.submit` | r | — | self | Raise an expense, advance, loan or commission claim |
| `request.view` | r | — | yes | See requests |
| `request.decide` | s | — | yes | Approve or reject a request within one's authority band |
| `request.money.confirm` | p | ✓ | yes | Confirm that collected money has arrived (**the Accounts control**) |
| `loan.sanction` | p | ✓ | yes | Sanction a loan (maker-checker) |
| `loan.schedule.manage` | p | ✓ | yes | Amend a recovery schedule |
| `commission.scheme.publish` | p | ✓ | yes | Publish an incentive scheme version |
| `commission.clawback.raise` | s | — | yes | Raise a clawback |
| `bankpack.generate` | p | ✓ | yes | Produce the bank/NBFC payout and billing pack |

#### Configuration engines

| Permission | R | MFA | Scoped | Business description |
|---|:-:|:-:|:-:|---|
| `rules.artefact.view` | r | — | no | See rules, formulas, workflows and templates |
| `rules.artefact.edit` | s | — | no | Draft a configuration artefact |
| `rules.artefact.simulate` | s | — | no | Simulate an artefact against real or synthetic data |
| `rules.artefact.submit` | s | ✓ | no | Submit an artefact for approval |
| `rules.artefact.publish` | p | ✓ | no | Publish an artefact version |
| `schema.field.manage` | p | ✓ | no | Add or change an entity field definition |
| `workflow.definition.publish` | p | ✓ | no | Publish a workflow definition |
| `template.publish` | s | ✓ | no | Publish a letter or document template |

#### Reporting, integration and operations

| Permission | R | MFA | Scoped | Business description |
|---|:-:|:-:|:-:|---|
| `reporting.run` | r | — | yes | Run a report |
| `reporting.definition.manage` | s | — | yes | Define a report |
| `reporting.export` | s | — | yes | Export a report |
| `integration.connector.view` | s | — | yes | See connectors and webhook endpoints |
| `integration.connector.manage` | p | ✓ | yes | Configure a connector or webhook |
| `integration.delivery.replay` | s | ✓ | yes | Replay a webhook delivery |
| `ops.health.view` | s | — | no | See scheduler, queue and backup health |
| `ops.job.retry` | p | ✓ | no | Retry or discard a failed job |
| `ops.backup.restore.test` | P | ✓ | no | Run a restore test |
| `messaging.template.manage` | s | — | yes | Maintain WhatsApp, SMS and email templates |
| `messaging.broadcast.send` | s | ✓ | yes | Send a broadcast |

#### Self-service (the `self` scope is structural, not optional)

| Permission | R | MFA | Scoped | Business description |
|---|:-:|:-:|:-:|---|
| `ess.profile.view` | r | — | self | View one's own record |
| `ess.profile.update` | r | — | self | Update declared-editable own fields |
| `ess.document.upload` | r | — | self | Upload one's own document |
| `ess.attendance.punch` | r | — | self | Punch in or out |
| `ess.payslip.download` | r | — | self | Download one's own payslip |
| `ess.tax.declare` | s | — | self | Submit tax declarations and proofs |
| `mss.team.view` | r | — | direct_reports+ | See one's own team |
| `mss.team.approve` | s | — | direct_reports+ | Decide one's team's requests |

**R-XXII-017** The `self`-scoped ESS permissions MUST resolve the subject from
the authenticated session and MUST NOT accept an employee identifier from the
request. There is no employee selector on any self-service transaction. *(Spine
S13.)* *Origin:* SmartPRS 2's payslip endpoint takes `{code}` from the URL and
relies on `selfScope()`, which returns `null` — no narrowing — for
`field_agent`, `accountant`, `platform_support` and any role-less user, so those
roles download any colleague's payslip (G §7.4 #10).

**R-XXII-018** The catalogue MUST be exported as a machine-readable artefact
(`GET /api/v3/access/permissions`) that the front end, the API documentation and
the test generator all consume. There is exactly one source.

### XXII.2.2a Codes used by other parts that this catalogue does not yet carry

**Generated, not hand-written.** The list below is produced by
`tools/generate-registers.py` and is reproduced here so that a reader of this
part sees the size of the gap without opening another file. The authoritative
copy — with the parts that use each code and the evidence for each — is
[`docs/registers/REGISTER-PERMISSIONS.md`](registers/REGISTER-PERMISSIONS.md).
Re-run the generator rather than editing this section.

**394 codes** are used and not catalogued. **1** of them falls inside a reserved generative namespace (R-XXII-010a) and needs no Ametecs row; **393** need one.

| Module | Codes needing an Ametecs-authored row | Count |
|---|---|---:|
| `advance.*` | `advance.view` | 1 |
| `advisory.*` | `advisory.manage` | 1 |
| `attendance.*` | `attendance.arrears.manage` · `attendance.day.override` · `attendance.day.view.history` · `attendance.exception.view` · `attendance.ingest` · `attendance.overtime.approve` · `attendance.overtime.cap_override` · `attendance.overtime.manual` · `attendance.overtime.override` · `attendance.overtime.policy.manage` · `attendance.overtime.policy.publish` · `attendance.overtime.policy.view` · `attendance.overtime.request` · `attendance.overtime.view` · `attendance.punch.ingest` · `attendance.punch.quarantine` · `attendance.punch.view` · `attendance.read` · `attendance.reattribute` · `attendance.recalculate` · `attendance.recalculate.locked` · `attendance.regularisation.raise` · `attendance.regularisation.review` · `attendance.settlement.view` | 24 |
| `audit.*` | `audit.entity.read` · `audit.export` · `audit.financial.read` · `audit.hold.manage` · `audit.security.read` · `audit.self.read` · `audit.sensitive.read` · `audit.tenant.read` · `audit.verify` | 9 |
| `auth.*` | `auth.insufficient_scope` | 1 |
| `billing.*` | `billing.cancel` · `billing.creditnote.view` · `billing.downgrade` · `billing.invoice.view` · `billing.payment.view` · `billing.renew` · `billing.subscription.view` · `billing.upgrade` · `billing.usage.view` | 9 |
| `calendar.*` | `calendar.day.view` · `calendar.holiday.manage` · `calendar.holiday.publish` · `calendar.holiday.view` · `calendar.pattern.manage` · `calendar.pattern.publish` · `calendar.pattern.simulate` · `calendar.pattern.view` · `calendar.recalculate` · `calendar.settlement.view` · `calendar.substitution.decide` · `calendar.substitution.request` · `calendar.swap.request` | 13 |
| `checklist.*` | `checklist.manage` | 1 |
| `commission.*` | `commission.run.commit` · `commission.run.create` · `commission.run.view` · `commission.scheme.author` · `commission.scheme.view` | 5 |
| `compensation.*` | `compensation.revision.cancel` · `compensation.revision.create` · `compensation.revision.view` | 3 |
| `compliance.*` | `compliance.config.view` · `compliance.evidence.manage` · `compliance.evidence.upload` · `compliance.evidence.view` · `compliance.gate.configure` · `compliance.gate.evaluate` · `compliance.gate.view` · `compliance.item.assign` · `compliance.item.create` · `compliance.item.edit` · `compliance.item.record_filing` · `compliance.item.record_payment` · `compliance.item.submit` · `compliance.item.verify` · `compliance.item.view` · `compliance.item.waive` · `compliance.pack.issue` · `compliance.pack.view` · `compliance.register.export` · `compliance.register.view` · `compliance.score.configure` · `compliance.score.manage` · `compliance.score.view` · `compliance.status.read` | 24 |
| `config.*` | `config.archive` · `config.author` · `config.completeness.view` · `config.edit` · `config.export` · `config.import` · `config.import.apply` · `config.manage` · `config.pack.apply` · `config.publish` · `config.publish.locked_period` · `config.read` · `config.sandbox` · `config.simulate` · `config.submit` · `config.template.apply` · `config.test.author` · `config.test.run` · `config.view` | 19 |
| `dashboards.*` | `dashboards.configure` · `dashboards.definition.manage` · `dashboards.personalise` · `dashboards.view` | 4 |
| `device.*` | `device.command` · `device.credential.rotate` · `device.identity.map` · `device.template` | 4 |
| `document.*` | `document.evidence.view` · `document.register.view` · `document.template.manage` · `document.token.issue` · `document.token.revoke` · `document.type.manage` | 6 |
| `employee.*` | `employee.activate` · `employee.archive` · `employee.create` · `employee.document.verify` · `employee.document.view` · `employee.exit` · `employee.export` · `employee.export.view_all` · `employee.history.view` · `employee.import` · `employee.import.view` · `employee.import.view_all` · `employee.list` · `employee.read` · `employee.rehire` · `employee.restore` · `employee.sensitive.view` · `employee.status.change` · `employee.suspend` · `employee.update` · `employee.view` | 21 |
| `employment.*` | `employment.assign` · `employment.create` · `employment.list` · `employment.update` · `employment.view.history` | 5 |
| `entitlement.*` | `entitlement.manage` · `entitlement.publish` | 2 |
| `entity.*` | `entity.field.manage` · `entity.field.publish` · `entity.field.view` | 3 |
| `exit.*` | `exit.clearance.override` · `exit.close` · `exit.manage` · `exit.settle` · `exit.view` | 5 |
| `expense.*` | `expense.claim.submit` · `expense.claim.view` | 2 |
| `explain.*` | `explain.read` · `explain.reproduce` | 2 |
| `forms.*` | `forms.definition.manage` | 1 |
| `formula.*` | `formula.publish` · `formula.read` · `formula.simulate.payroll` | 3 |
| `identity.*` | `identity.sso.manage` | 1 |
| `integration.*` | `integration.bridge.manage` · `integration.client.manage` · `integration.connector.cursor.reset` · `integration.credential.manage` · `integration.diagnostics.raw` · `integration.recipe.publish` · `integration.view` · `integration.webhook.manage` · `integration.webhook.replay` | 9 |
| `key.*` | `key.view` | 1 |
| `leave.*` | `leave.accrual.run` · `leave.adjust` · `leave.application.view` · `leave.balance.view_others` · `leave.calendar.view` · `leave.calendar.view_type` · `leave.encash` · `leave.report.liability` · `leave.rollover.run` · `leave.type.manage` · `leave.type.publish` · `leave.type.simulate` · `leave.type.view` | 13 |
| `licence.*` | `licence.activate` · `licence.approve` · `licence.instance.manage` · `licence.issue` · `licence.reissue` · `licence.view` | 6 |
| `loan.*` | `loan.disburse` · `loan.foreclose` · `loan.pause` · `loan.prepay` · `loan.restructure` · `loan.view` | 6 |
| `metadata.*` | `metadata.view` | 1 |
| `migration.*` | `migration.disposition.set` · `migration.exceptions.resolve` · `migration.exceptions.view` · `migration.gate.sign` · `migration.trace` · `migration.view` | 6 |
| `module.*` | `module.configure` · `module.view` | 2 |
| `money.*` | `money.obligation.author` · `money.obligation.record_payment` · `money.obligation.request` · `money.obligation.view` · `money.obligation.waive` · `money.obligation.write_off` | 6 |
| `notification.*` | `notification.audience.simulate` · `notification.audience.view` · `notification.campaign.manage` · `notification.campaign.view` · `notification.delivery.retry` · `notification.delivery.view` · `notification.event.view` · `notification.provider.credentials` · `notification.provider.manage` · `notification.schedule.view` · `notification.subscription.manage` · `notification.subscription.publish` · `notification.subscription.simulate` · `notification.subscription.view` · `notification.template.manage` · `notification.template.publish` · `notification.template.test` · `notification.template.view` | 18 |
| `onboarding.*` | `onboarding.invite` · `onboarding.verify` · `onboarding.view` | 3 |
| `ops.*` | `ops.alert.configure` · `ops.alert.manage` · `ops.alert.view` · `ops.artefact.import` · `ops.backup.run` · `ops.backup.view` · `ops.capacity.view` · `ops.delivery.manage` · `ops.delivery.view` · `ops.deployment.view` · `ops.diagnostics.export` · `ops.health.manage` · `ops.health.run` · `ops.integration.manage` · `ops.integration.view` · `ops.queue.manage` · `ops.read` · `ops.restore.drill` · `ops.restore.execute` · `ops.runtime.manage` · `ops.runtime.view` · `ops.schedule.manage` · `ops.slo.view` · `ops.trace.view` · `ops.view` · `ops.write` | 26 |
| `org.*` | `org.registration.view` · `org.view` | 2 |
| `pack.*` | `pack.manage` · `pack.view` | 2 |
| `payroll.*` | `payroll.cycle.manage` · `payroll.cycle.view` · `payroll.payout.authorise` · `payroll.payout.confirm` · `payroll.payout.prepare` · `payroll.payout.view` · `payroll.period.lock` · `payroll.period.reopen` · `payroll.period.reopen.request` · `payroll.period.view` · `payroll.retro.manage` · `payroll.run` · `payroll.run.debug` · `payroll.run.exception.resolve` · `payroll.sheet.view` · `payroll.view` | 16 |
| `payslip.*` | `payslip.admin` · `payslip.audit.view` · `payslip.distribute` · `payslip.download` · `payslip.publish` · `payslip.template.manage` · `payslip.template.view` · `payslip.view` | 8 |
| `person.*` | `person.view` | 1 |
| `platform.*` | `platform.coupon.manage` · `platform.cross_tenant` · `platform.demo.invite` · `platform.finance.view` · `platform.plan.publish` · `platform.pricebook.publish` · `platform.tenant.contain` · `platform.tenant.provision` · `platform.tenant.purge` · `platform.tenant.view` | 10 |
| `policy.*` | `policy.publish` | 1 |
| `probation.*` | `probation.decide` · `probation.policy.manage` · `probation.review` · `probation.view` | 4 |
| `quality.*` | `quality.baseline.create` · `quality.baseline.retire` · `quality.baseline.run` · `quality.baseline.view` | 4 |
| `recruitment.*` | `recruitment.campaign.send` · `recruitment.candidate.edit` · `recruitment.candidate.import` · `recruitment.candidate.view` · `recruitment.drive.manage` · `recruitment.interview.manage` · `recruitment.pipeline.manage` · `recruitment.pipeline.publish` · `recruitment.pipeline.view` · `recruitment.report.view` · `recruitment.requisition.create` · `recruitment.requisition.view` · `recruitment.scorecard.supersede` | 13 |
| `register.*` | `register.view` | 1 |
| `reimbursement.*` | `reimbursement.run.create` · `reimbursement.run.view` | 2 |
| `release.*` | `release.promote` · `release.publish` | 2 |
| `reporting.*` | `reporting.artefact.download` · `reporting.dataset.view` · `reporting.definition.publish` · `reporting.schedule.manage` · `reporting.schedule.render_as_identity` | 5 |
| `roster.*` | `roster.edit` · `roster.import` · `roster.publish` · `roster.view` | 4 |
| `salary.*` | `salary.compensation.request` · `salary.compensation.view` · `salary.component.manage` · `salary.component.view` · `salary.structure.manage` · `salary.structure.view` | 6 |
| `search.*` | `search.global` | 1 |
| `statutory.*` | `statutory.applicability.view` · `statutory.auditpack.generate` · `statutory.binding.manage` · `statutory.calendar.manage` · `statutory.calendar.view` · `statutory.filing.prepare` · `statutory.filing.record` · `statutory.filing.view` · `statutory.pack.accept` · `statutory.pack.import` · `statutory.pack.view` · `statutory.policy.manage` · `statutory.policy.view` · `statutory.profile.view` · `statutory.report.view` · `statutory.simulate` | 16 |
| `statutory_configs.*` | `statutory_configs.scope_value` | 1 |
| `system.*` | `system.health.run` · `system.health.view` | 2 |
| `tax.*` | `tax.declaration.self` · `tax.declaration.verify` · `tax.form16.issue` · `tax.override` · `tax.settings.manage` | 5 |
| `tenant.*` | `tenant.export` · `tenant.status.view` | 2 |
| `transfer.*` | `transfer.cancel` · `transfer.create` · `transfer.view` | 3 |
| `update.*` | `update.apply` · `update.policy.manage` · `update.view` | 3 |
| `workflow.*` | `workflow.binding.author` · `workflow.binding.view` · `workflow.calendar.view` · `workflow.definition.author` · `workflow.definition.submit` · `workflow.definition.view` · `workflow.delegation.manage.self` · `workflow.group.view` · `workflow.instance.resume` · `workflow.instance.view` · `workflow.oversight.view` · `workflow.report.view` · `workflow.task.act` | 13 |
| **Total** | | **393** |

**Inside a reserved generative namespace (R-XXII-010a) — no catalogue row:**

> `report.execute`

This one is not a tenant-minted code either. Its only occurrence in the corpus is
Part XXIV §XXIV.2.5's note recording it as a **superseded spelling this catalogue
does not define**; the register's extractor reads that sentence as a use. The
engine capability it was reaching for is `reporting.run`. Nothing binds it, so
nothing fails; it is listed here rather than quietly dropped, because deleting
the sentence would delete the record of the mistake.

**Two capabilities need a design answer, not a generated row** (Appendix A R-03, unchanged by this pass):

- A **machine punch-ingest** permission distinct from `attendance.punch.create.manual` — a device is not a human recording a manual punch. Part XXIV binds `attendance.ingest`; the verb and the assurance level are Part XXII's to decide.
- A **period-settlement read** — Part XI's `attendance.settlement.view` reads a payroll-facing frozen view, which is neither an attendance day nor a payslip, and no existing code covers it.

### XXII.2.3 Roles

**R-XXII-019** A role is a **named, tenant-owned bundle of permissions**,
authored and published as an ordinary configuration artefact under spine S4:
draft → testing → pending_approval → published → superseded. It carries an
effective range, a version number, a change note and a checksum.

**R-XXII-020** Roles are of two kinds:

| Kind | Owner | Editable by tenant | Deletable | Behaviour on upgrade |
|---|---|---|---|---|
| **System role** | Ametecs (shipped in the release) | **Cloneable, not editable** | no | Permission set is reconciled on upgrade; additions are reported in the release note and require tenant acknowledgement before taking effect |
| **Custom role** | The tenant | yes | yes, if unassigned | Untouched by upgrades |

A tenant that wants "HR Manager, but without payroll approval" clones the system
role and edits the clone. The system role's definition is never mutated, so an
upgrade cannot silently widen or narrow a customer's intent. *Origin:* SmartPRS
2 has no system/custom distinction at all — there is one global `roles` table
shared by every tenant (`config/permission.php:138`), so a customer's edit would
be everyone's edit.

**R-XXII-021** Role definitions and role→permission grants MUST be stored in
relational tables with foreign keys and an audit trail. They MUST NOT be stored
in a JSON document, a settings blob, or any structure writable by a general
"save state" endpoint. *Origin:* SmartPRS 2 keeps the entire matrix inside
`settings.app_state`, which `POST /app/state` overwrites wholesale with no guard
of any kind (`AppStateController.php:22–53`; G SEC-03; K §2.1).

**R-XXII-022** Publishing a role version is a **high_risk** artefact publication
under spine S4 and therefore requires maker-checker through the workflow engine
(spine S8), a mandatory diff, and an impact preview naming every user whose
effective permission set changes and every permission gained or lost.

**R-XXII-023** A role MUST NOT be assignable while in `draft` or `testing`
status. Assignment references a **published version**; in-flight assignments
continue on the version in force at the assignment's effective date.

**R-XXII-024** Assigning a role that does not exist, is not published, or is not
visible in the actor's tenant MUST fail the request with a named error and MUST
NOT create the user. *Test:* a request assigning an unknown role code returns
422 with `error.code = ACCESS_ROLE_UNKNOWN` and the user count is unchanged.
*Origin:* SmartPRS 2 swallows `RoleDoesNotExist` and returns `{"ok":true}`,
producing a role-less account that the SPA then renders as Administrator
(`UserController.php:298–308`; `AppController.php:93`; G D-05).

**R-XXII-025** Roles are **tenant-scoped**. A role code is unique within a
tenant, not globally. Platform roles live in the reserved platform tenant and are
never visible to a customer. *Origin:* `config/permission.php:138` — `teams => false`.

**R-XXII-026** A role MUST declare `is_privileged` when it grants any permission
of risk class `privileged` or `platform`. A privileged role mandates MFA on
every holder (§XXII.7.4) and cannot be granted a data scope wider than the
grantor's own (R-XXII-033).

**R-XXII-027** Deleting a role is prohibited while any assignment references it,
including historical assignments. A role is **retired** (its effective range is
closed), never deleted, so an audit row three years old still resolves the role
name it names. *(Law 3.)*

### XXII.2.4 Assignments

**R-XXII-028** The assignment is the triple:

```
role_assignment
  user_id, role_id (published version resolved at effective date),
  scope_id,                       -- §XXII.3
  effective_from DATE NOT NULL, effective_to DATE NULL,
  granted_by_user_id, granted_at, reason, workflow_instance_id
```

**R-XXII-029** Assignments are **effective-dated**. "What could this user do on
15 May?" is answerable by a single service call. *(Law 3.)* A revocation closes
`effective_to`; it never deletes the row.

**R-XXII-030** A user MAY hold **several** role+scope assignments simultaneously.
The effective permission set is the **union** of the permission sets of all
assignments in force on the evaluation date. *Origin:* SmartPRS 2 enforces
exactly one role per user via `syncRoles([$role])` at both assignment surfaces,
which is why the commission money chain — Agent claims → **Accounts confirms** →
Manager approves — cannot be expressed for a person who does both jobs
(N §1.6.2, §1.7.2 module 18).

**R-XXII-031** The union is over permissions; it is **not** over scopes. Each
assignment carries its own scope, and a permission granted by assignment *A* is
exercised only within *A*'s scope. §XXII.3.4 specifies the composition rule
precisely, with a worked example, because "union the permissions and union the
scopes" is the error that turns two safe assignments into one unsafe one.

**R-XXII-032** An assignment MAY be **time-boxed** — `effective_to` set at grant
— and the UI MUST offer this for any role marked `is_privileged`. A time-boxed
grant emits `access.assignment.expiring` seven days before expiry (configurable).

**R-XXII-033** **No grant above self.** An actor MUST NOT grant a role whose
permission set is not a subset of the actor's own effective permission set, nor a
data scope not contained within the actor's own. The check is on the *resulting
effective set*, evaluated at grant time and re-evaluated at the assignment's
`effective_from` if it is future-dated. *Test:* §XXII.14.4. *Origin:* SmartPRS 2's
`canManage` list lets an `hr_manager` create an `admin`
(`UserController.php:26–37, 150–152`; G SEC-07).

**R-XXII-034** An actor MUST NOT reset the credentials of, revoke the sessions
of, disable, or clear the MFA of a user whose effective permission set is not a
subset of the actor's own. *Origin:* the same screen lets an `hr_manager` set the
tenant administrator's password, unaudited (`UserController.php:262–278`).

**R-XXII-035** Every grant, revocation, scope change, role publication and
credential action on another user MUST write a field-level audit row through the
audit service (spine S14), carrying actor, target, before, after, reason and
correlation id. *Origin:* SmartPRS 2 audits successful login only — 11
`Audit::record` call sites in the entire product, none of them on a privilege
change (G SEC-21, D-18).

**R-XXII-036** A user with **no** assignment in force has an empty permission
set and can reach only the unauthenticated surface and the "no access
configured" page. They are not an administrator. *Test:* §XXII.14.5. *Origin:*
`AppController.php:93` maps a missing role to the display label `'Admin'` and
`:769` then skips gating entirely for that label.

### XXII.2.5 Inheritance and composition

SmartPRS 3 has **one** inheritance mechanism, at the permission level, and
**none** at the role level.

**R-XXII-037** A permission MAY declare `implies[]`. Granting `payroll.run.approve`
implies `payroll.run.view`; granting `employee.record.update` implies
`employee.record.view`. The closure is computed at role publication and
**materialised** into `role_permissions_effective`, so evaluation is a single
indexed lookup and never a graph walk at request time.

**R-XXII-038** Role hierarchies ("Manager inherits Employee") are **not
supported**, deliberately. A role is a flat set. *Rationale:* role inheritance
makes the effective set of a role a function of another role's edits, which is
exactly the property that makes a permission model unreviewable — and the
customer-facing question "what can this role do?" must have an answer that does
not require a diagram. Reuse is served by cloning a system role (R-XXII-020) and
by permission groups in the editor UI, which are a presentation device with no
runtime meaning.

**R-XXII-039** There is **no negative permission** and no deny rule. A role
grants; it never revokes. *Rationale:* deny rules interact with the union in
R-XXII-030 to produce order-dependent outcomes, and order-dependence in
authorisation is the property spine S3.2 exists to eliminate everywhere else.
Where a customer wants "HR Manager without payroll approval", the answer is a
cloned role without that permission.

### XXII.2.6 The separation, stated once

| Question | Answered by | Stored in | Evaluated by |
|---|---|---|---|
| *May this actor perform this verb on this resource type?* | the **permission** | `role_permissions_effective` via the user's assignments | the policy gate, before the handler runs |
| *On which records?* | the **data scope** | `access_scopes` + `access_scope_units`, resolved through effective-dated org data | the query-scoping layer, inside the persistence layer |
| *Under which tenant?* | **tenancy**, which is not a scope | `tenant_id`, applied unconditionally | the same persistence layer, before data scope |

The three are evaluated in that order, and each is capable of denying
independently. A holder of `payroll.run.approve` scoped to the Hyderabad branch
who requests a Chennai run is denied by the second question, not the first — and
the denial reason distinguishes them (§XXII.13).

---

## XXII.3 The data-scope model (brief Sections 70 and 71)

### XXII.3.1 Why this is a first-class model and not a set of `if` statements

SmartPRS 2 has exactly one data-scope mechanism. `AppDataController::selfScope()`
(`:221–232`) narrows to the caller's own employee record **if and only if** the
caller holds the literal role `employee`; for every other non-privileged role it
returns `null`, meaning no narrowing at all. `field_agent`, `accountant`,
`platform_support`, `manager`, `hr` and any role-less user therefore receive the
whole workforce — 62 employee fields including `ctc`, `pan`, `uan`, `bankAcc`,
`ifsc`, `homeLat`, `homeLng`, plus every reference's Aadhaar and PAN
(`AppDataController.php:302–424`; G §7.1 #9, §5.2 item 4). A second, inconsistent
mechanism exists in the attendance report, which narrows *all* non-privileged
roles (`AttendanceReportController.php:104, 535`; G BR-TEN-010), so two screens
in the same product disagree about who may see whom.

Meanwhile the product **sells** master and subsidiary companies, branches,
departments, teams and reporting managers — and has no way to scope a user to any
of them (G §10 item 15).

### XXII.3.2 The scope types

A **data scope** is a resolvable expression that yields, for a given actor at a
given instant, the set of employees (and by extension the set of records
belonging to those employees, and the set of org units) that an assignment
reaches.

| Code | Name | Resolves to | Typical holder |
|---|---|---|---|
| `self` | Self | the actor's own employee record only | every employee |
| `direct_reports` | Direct reports | employees whose current reporting manager is the actor, optionally including the actor | team lead |
| `reporting_tree` | Reporting tree to depth N | the transitive closure of `direct_reports` to `depth` levels; `depth = NULL` means unbounded | senior manager |
| `team` | Team | all employees assigned to the named team units | team lead |
| `department` | Department | all employees in the named departments (and sub-departments, if `include_descendants`) | department head |
| `branch` | Branch | all employees in the named branches | branch manager |
| `location` | Location | all employees at the named locations | site administrator |
| `company` | Company | all employees of the named companies | company HR |
| `legal_entity` | Legal entity | all employees of the named legal entities | group HR |
| `org_units` | Explicit unit list | all employees in an arbitrary list of org units of any type, each with an `include_descendants` flag | ad-hoc portfolios |
| `dimension_filter` | Classification filter | all employees matching a Scope expression over the S3.1 dimensions (grade, designation, employee_category, employment_type, pay_group, cost_centre, business_unit, client_process, shift) | payroll officer for one pay group |
| `tenant` | Tenant | every employee of the tenant | tenant administrator |
| `platform` | Platform | cross-tenant; **only** in the platform tenant, only with a `platform.*` permission, only through §XXII.5.5 | Ametecs staff |

**R-XXII-040** The scope-type vocabulary is a **platform constant table**
(`access_scope_types`), seeded by migration. It is not tenant-editable, because a
customer inventing a scope type would invent a resolution algorithm that no code
implements. *(Law 1 satisfied by data; the closed set is deliberate.)*

**R-XXII-041** Every scope type MUST implement the same contract:

```
interface ScopeResolver {
    // The set of employees this scope reaches, as at $asOf.
    employeeSetExpression(Actor $a, Scope $s, Date $asOf): SetExpression

    // The set of org units this scope reaches — used to scope org-owned
    // records (a roster, a holiday calendar) that have no employee.
    orgUnitSetExpression(Actor $a, Scope $s, Date $asOf): SetExpression

    // Cheap containment test, used by R-XXII-033 and by write authorisation.
    contains(Actor $a, Scope $s, EmployeeId $e, Date $asOf): bool
}
```

`SetExpression` is a compiled predicate the query-scoping layer injects into SQL
— never a materialised id list, because a tenant-scoped payroll officer may reach
40,000 employees and an `IN (…)` of that size is not a plan the optimiser can use.

**R-XXII-042** `self` MUST resolve from the session's employee binding
(`users.employee_id`) and MUST return the empty set — not the tenant — when the
user has no employee binding. *Origin:* SmartPRS 2's `selfScope()` returns
`null` (no narrowing) in every case it does not recognise, which is the opposite
default.

**R-XXII-043** `direct_reports` and `reporting_tree` MUST resolve from the
**effective-dated** reporting assignment, as at the evaluation date, not from a
current-state column. *Origin:* SmartPRS 2 routes its entire approval engine
through `employees.reporting_manager_id`, a mutable column with no history
(N §1.6.1), so "who was this person's manager in May?" is unanswerable and a
re-opened May approval routes to today's manager.

**R-XXII-044** `reporting_tree` MUST carry an explicit `depth` and MUST enforce a
platform maximum (`access.reporting_tree.max_depth`, default `<from platform
constants>`). Cycle detection is mandatory: a reporting cycle terminates the walk,
denies, and raises `access.reporting_cycle_detected`. A cycle is a data defect,
never a licence to widen scope.

**R-XXII-045** Every unit-based scope (`team`, `department`, `branch`,
`location`, `company`, `legal_entity`, `org_units`) MUST record its units as
foreign keys into `org_units`, never as names or strings. *Origin:* SmartPRS 2
resolves a branded portal by **slugified company name** across the entire
installation (`AuthController.php:139–158`; G SEC-15).

**R-XXII-046** `include_descendants` is an explicit per-unit boolean, defaulting
to **true** for `department` and `org_units`, and to **false** for `team`. It is
never inferred.

**R-XXII-047** `dimension_filter` scopes are expressed as a spine S3.1 **Scope**
object — the same structure that targets a policy, a formula or a rule — so a
customer learns one targeting model, not two.

### XXII.3.3 How a scope is stored

A scope is a named, reusable, tenant-owned object. It is not inline on the
assignment, because the same "Hyderabad branch + its sub-departments" scope is
typically granted to a dozen users and must be maintainable in one place.

```
access_scope
  scope_key, name, description,
  scope_type_code,                  -- from access_scope_types
  depth                 NULL unless reporting_tree
  include_self          bool, meaningful for direct_reports / reporting_tree
  dimension_scope_json  NULL unless dimension_filter (spine S3.1 Scope)
  units[]  → access_scope_units( org_unit_id, include_descendants )
  is_system             system scopes: SELF, TENANT, PLATFORM
  effective_from, effective_to, version, status
```

**R-XXII-048** Three scopes are shipped as immutable system scopes and MUST
exist in every tenant: `SELF`, `TENANT`, and — in the platform tenant only —
`PLATFORM`. `PLATFORM` MUST NOT be creatable, assignable or visible inside a
customer tenant. *Test:* §XXII.14.3.

**R-XXII-049** A scope is versioned and effective-dated like any other artefact
(spine S4). Editing "Hyderabad branch" to add a sub-branch creates a new version;
an audit query for a past date resolves the old one. Publishing a scope that
**widens** an existing grant is `high_risk` and requires approval, with an impact
preview naming every affected assignment and the employee-count delta.

### XXII.3.4 Composition — the rule, stated precisely

A user may hold several assignments. Each assignment is a `(role, scope)` pair.

**R-XXII-050** For a permission `p` and a candidate record `r`, access is granted
**iff there exists at least one assignment `A` in force on the evaluation date
such that `p ∈ permissions(A.role)` AND `r ∈ resolve(A.scope)`.**

This is an existential over assignments, not a cross-product of the unions. The
distinction is load-bearing:

> **Worked example.** Priya holds two assignments:
> **A1** — role *Branch HR* (`employee.record.update`, `leave.request.decide`),
> scope *Hyderabad branch*.
> **A2** — role *Payroll Viewer* (`payroll.run.view`, `payroll.line.view`),
> scope *Tenant*.
>
> Under R-XXII-050, Priya may update an employee record **only in Hyderabad**,
> and may view payroll runs **tenant-wide**. She may **not** update a Chennai
> employee — even though her permission union contains `employee.record.update`
> and her scope union contains the whole tenant. Unioning the two axes
> separately would have granted exactly that, and it is the single most common
> way a role model of this shape leaks.

**R-XXII-051** The evaluation MUST be able to answer, for a denial, **which**
assignment came closest and on which axis it failed — permission absent, or
permission present but record out of scope. This is what §XXII.13 renders.

**R-XXII-052** For a **list** query (as opposed to a single-record check), the
scoping layer MUST inject the disjunction of the scopes of every assignment that
carries the required permission — and nothing else:

```sql
-- required permission: employee.record.list
-- assignments carrying it: A1 (Hyderabad branch), A3 (department: Collections, +descendants)
WHERE employees.tenant_id = :tenant
  AND (  employees.id IN (<A1 branch set expression>)
      OR employees.id IN (<A3 department closure expression>) )
```

**R-XXII-053** Where a record has **no** employee (a holiday calendar, a shift
definition, a rule version), scoping is by `orgUnitSetExpression`. Where a record
has neither employee nor org unit (a tenant-level configuration artefact), the
permission is declared `Scoped = no` in the catalogue and only tenancy applies.

**R-XXII-054** A **write** is authorised against the record's scope membership
**both before and after** the mutation. Moving an employee from Hyderabad to
Chennai requires the actor to reach both. *Origin:* SmartPRS 2 has no write-side
scope check of any kind; `ApprovalService::safeRow()` does not even stamp the
tenant (G BR-TEN-012).

**R-XXII-055** Scope resolution results MAY be cached per `(user, permission,
as_of_date)` for the duration of a single request only. Cross-request caching of
a resolved scope is prohibited, because an org change, a transfer or a
revocation must take effect on the next request, not on the next cache expiry.

### XXII.3.5 Resolution from effective-dated org data

Scope resolution reads spine S3's effective-dated assignment rows, never a
denormalised column on `employees`.

```
resolve(scope, actor, as_of):

 1. tenant     := actor.tenant_id                     -- never from the request
 2. anchor     := actor.employee_id                   -- NULL for a non-employee login
 3. per scope_type:
      self             → { anchor }                   -- ∅ if anchor is NULL
      direct_reports   → employees E where the reporting assignment in force
                         on as_of names anchor as manager     (+ anchor if include_self)
      reporting_tree   → recursive closure of the above to `depth`, cycle-guarded
      team|department|branch|location|company|legal_entity|org_units
                       → employees E whose org assignment in force on as_of
                         places them in one of the scope's units
                         (or in a descendant, where include_descendants)
      dimension_filter → employees E matching the S3.1 Scope predicate
                         evaluated against classifications in force on as_of
      tenant           → all employees of `tenant`
      platform         → §XXII.5.5 only
 4. intersect unconditionally with tenant
```

**R-XXII-056** `as_of` MUST be the **business date of the operation**, not
`today`. Approving a May correction in June resolves the scope as at the May
date. *(Law 3; the same principle as spine S11's input snapshot.)* *Origin:*
SmartPRS 2 cannot answer "what was this employee's branch on 15 May?" at all
(spine S3).

**R-XXII-057** A record whose subject employee has **no** org assignment in force
on `as_of` is reachable only by `tenant` and `self` scopes, and MUST be surfaced
on the data-quality exception report. It is never silently visible to everyone,
and never silently visible to no one without a report entry. *Origin:* SmartPRS
2's dashboard counts `tenant_id IS NULL` attendance rows for **every** tenant
(`DashboardController.php:99–100`).

**R-XXII-058** Step 4 is unconditional and is implemented in the persistence
layer, not in the resolver. A scope resolver that returned a foreign employee id
could not widen access, because tenancy is applied independently (§XXII.5.1).

### XXII.3.6 Worked resolution examples

**Example 1 — the branch manager.**
Ravi holds *Branch Operations Manager* scoped to `branch:Hyderabad` with
`include_descendants = true`. On 12-Aug-2026 he opens the attendance register.

```
permission  attendance.day.view          → present in Branch Operations Manager
scope       branch:Hyderabad (+desc)     → org assignments in force 12-Aug-2026
                                           place 214 employees under Hyderabad
                                           or its 3 sub-departments
tenancy     tenant 7                     → applied by the persistence layer
result      214 employees' attendance days; 1,806 other tenant employees
            are not merely hidden — they are not in the result set
```

On 12-Jul-2026 the same query returns 209, because two employees transferred in
on 1-Aug and one on 5-Aug. The register for July is therefore reproducible.

**Example 2 — the reporting manager, and why depth matters.**
Sunita holds *Team Lead* with scope `reporting_tree(depth = 2, include_self = false)`.
Her direct reports are 6; their direct reports are 19. She reaches 25 employees
for `leave.request.decide`. A third level exists (4 employees under one of the
19); she does not reach them, and a leave request from one of those four routes
past her to the next approver by the workflow engine's own participant
resolution (Part IX §IX.5.3), which reads the same effective-dated reporting
data — one mechanism, not two.

**Example 3 — segregation of duties in the commission money chain.**
The vertical requires: the field agent claims, **Accounts confirms the money
arrived**, the manager approves. In SmartPRS 3:

| Actor | Assignment | Reaches |
|---|---|---|
| Agent | *Field Agent* + `SELF` | `request.submit` on own claims only |
| Accounts officer | *Accounts* + `TENANT` | `request.money.confirm` on every claim |
| Branch manager | *Branch Operations Manager* + `branch:Hyderabad` | `request.decide` on Hyderabad claims |

A person who is both Accounts and Branch Manager holds two assignments and can
perform both verbs — which is legitimate — but the **workflow** forbids the same
user satisfying both the confirm and the approve node of one instance through the
segregation set (Part IX §IX.5.7). Permission model and workflow model each carry
half of the control, and neither is asked to do the other's job. *Origin:*
SmartPRS 2 cannot express this at all: `accountant` is assignable and never
created, so the confirm step is unassignable, and one role per user makes the
combined actor impossible even in principle (G D-05; N §1.6.2).

**Example 4 — the payroll officer for one pay group.**
Scope `dimension_filter` with the S3.1 Scope `{ pay_group: IN ['MONTHLY-STAFF'],
company: IN ['ACME-SERVICES'] }`, permission set *Payroll Preparer*
(`payroll.run.create`, `payroll.run.preview`, `payroll.run.submit`, but **not**
`payroll.run.approve`). The officer prepares runs for monthly staff in one legal
entity and cannot approve any of them. The approver holds `payroll.run.approve`
under a different assignment. The workflow's segregation set makes the
prohibition structural rather than conventional.

### XXII.3.7 What a scope is not

**R-XXII-059** A data scope MUST NOT be used to express **field-level**
visibility. Which columns a role may see is a property of the field definition
and the surface rule (spine S9, Part X §X.2.6), evaluated by the field-level
enforcement in §XXII.4.6. *Rationale:* conflating them produces the SmartPRS 2
outcome — a single 62-field projection returned to every non-`employee` role
because the only available control was row-level and it did not fire
(`AppDataController.php:302–424`).

**R-XXII-060** A data scope MUST NOT be used to express **tenancy**. Tenancy is
not a scope; it is a precondition applied unconditionally (§XXII.5.1).

**R-XXII-061** A data scope MUST NOT be used as a **licence or plan gate**.
Commercial gating is a separate, server-side evaluation in the same pipeline
(§XXII.4.1 step 7). *Origin:* SmartPRS 2's plan gate is an unconditional `return;`
at the top of `applyPlanGate()` and its edition gate is retired while the
constants and the marketing remain (G D-09, D-10) — a commercial control
implemented only in the browser is not a control.

---

## XXII.4 Enforcement architecture

### XXII.4.1 The authorisation pipeline

Every request — HTTP, queued job, console command, webhook — passes through one
pipeline. There is no second path.

```
 1. Identify the principal        session | API key | OAuth2 client | system actor
 2. Establish the tenant          from the principal, never from the request body,
                                  never from a Host header, never from a device serial
 3. Resolve the operation         from the route's declared operation binding
 4. Assurance check               MFA / re-auth / device trust, per operation
 5. Permission check              does any in-force assignment carry the operation's
                                  required permission?
 6. Subject scope check           for single-subject operations: is the subject in the
                                  scope of at least one assignment carrying it?
 7. Commercial gate               edition · plan · licence · seat, server-side
 8. State gate                    period lock · workflow state · subscription state
 9. Handler runs                  every query it issues passes through §XXII.4.4
10. Field-level filter            response projection per §XXII.4.6
11. Audit                         the decision and, for sensitive reads, the access
```

**R-XXII-070** The pipeline order is fixed and MUST NOT be reordered per module.
Steps 1–8 execute before any handler code. A handler that has begun executing has
already been authorised for its operation.

**R-XXII-071** Every step MUST be capable of denying, and every denial MUST carry
a machine-readable reason code distinguishing the step (§XXII.13). *Origin:*
SmartPRS 2 conflates "not permitted", "not licensed", "subscription lapsed" and
"demo write blocked" into indistinguishable responses, and returns raw exception
messages on roughly fifteen endpoints in this area
(`AuthController.php:459–461`; `UserController.php:213, 233, 253, 293`;
`SaasController.php:93, 122, 247, 365, 389, 420, 462, 489`; G SEC-25).

**R-XXII-072** External surfaces MUST address records by **UUIDv7 public id**
only. A sequential integer MUST NOT appear in any URL, export, API payload or
webhook. *(Spine S2.)* *Origin:* eighteen IDOR endpoints in SmartPRS 2 take a
record id straight from a route parameter with no ownership test, and 354 query
sites filter on `where('id', …)` and nothing else (G §7.4, §0.4). Opaque ids do
not replace authorisation — steps 5 and 6 still run — but they remove
enumeration as a discovery method.

### XXII.4.2 Operation binding, and the CI sweep that makes it mandatory

**R-XXII-073** Every route MUST declare an **operation** — a registry entry
naming its required permission, its subject resolver, its scope requirement, its
assurance requirement, its idempotency behaviour and its audit class:

```
operations
  operation_code            payroll.run.approve
  http_bindings[]           POST /api/v3/payroll/runs/{uuid}/approve
  required_permission       payroll.run.approve
  subject_kind              employee | org_unit | tenant | none
  subject_resolver          how the subject is derived from the request
  scope_requirement         required | not_applicable
  assurance                 none | mfa | reauth(<seconds>)
  audit_class               none | write | sensitive_read | privileged
  maker_checker             none | <workflow_artefact_key>
  rate_limit_key            identity | tenant | api_key | ip | device
  csrf_exempt_reason        NULL, or a declared compensating control
```

**R-XXII-074** **A route with no operation binding MUST fail the build.** The CI
sweep enumerates every registered route across every route file at boot in a
test, resolves each to an operation, and fails on any route that resolves to
none. There is no default operation and no "public unless stated". *(Law 5;
spine S13 first non-negotiable.)*

**R-XXII-075** The unauthenticated surface is an **explicit allow-list** —
a named set reviewed as a unit, each entry carrying a justification and a
compensating control. The sweep asserts the live unauthenticated surface equals
the allow-list exactly; an addition fails the build until the list is amended in
the same commit. *Origin:* SmartPRS 2's unauthenticated surface is 78
declarations spread across four route files and three conditional blocks, and
nobody can state it from the code (G §10 item 27).

**R-XXII-076** The sweep MUST additionally assert:

| Assertion | Fails the build when |
|---|---|
| Operation coverage | any route has no operation |
| Permission existence | an operation names a permission absent from the catalogue |
| Subject resolvability | `scope_requirement = required` but `subject_kind = none` |
| Scope declaration parity | a permission marked `Scoped = yes` in the catalogue is bound to an operation with `scope_requirement = not_applicable`, or the reverse |
| CSRF justification | a route is CSRF-exempt with a NULL `csrf_exempt_reason` |
| Privileged assurance | an operation whose permission has `risk_class ∈ {privileged, platform}` declares `assurance = none` |
| Maker-checker parity | an operation the design declares maker-checker has no workflow binding |
| Audit class | an operation with `audit_class = none` writes to any table (detected by the write-path decorator in test mode) |

*Origin:* SmartPRS 2 has **78 authenticated routes with no server-side role or
self check** (G §7.3), and its CSRF exemptions include one — `/webhooks/interakt`
— whose route comment offers no compensating control at all, unlike every sibling
(G §7.5).

**R-XXII-077** The operation registry is also the **generator input** for: the
front-end capability payload (§XXII.4.5), the OpenAPI security blocks, the
route × role authorisation matrix test (§XXII.14.4), and the "Roles &
Permissions" editor's screen list. One registry, four consumers, no drift.
*Origin:* SmartPRS 2's client permission map `PERM_NAV`
(`AppController.php:655–676`) covers 120 of 146 screen ids, leaving 26 screens
with no permission mapping whatsoever — including the platform, licence and
configuration screens (K §6.17).

### XXII.4.3 No module writes its own guard

**R-XXII-078** No module may implement its own authorisation check. A domain
service MUST NOT call a role or permission API. Authorisation is asserted at the
pipeline; the domain assumes it. *Test:* a Deptrac rule forbids any `Domain\*`
namespace from depending on `Domain\Access`, and a CI grep fails the build on
`hasRole`, `hasAnyRole`, `can(`, `denyUnlessRole` or an equivalent outside
`Domain\Access` and its tests. *Origin:* SmartPRS 2 spreads 62 `hasRole` sites,
71 `denyUnlessRole` sites and 16 `FeaturePermissions::guard` sites across
controllers, and the load-bearing guard itself lives in
`App\Http\Controllers\ApprovalService` (G D-13).

**R-XXII-079** Where a domain rule genuinely depends on the actor — "an approver
may not approve their own request" — it is expressed in the **workflow** engine's
segregation and self-approval configuration (Part IX §IX.5.6–§IX.5.7), not as an
authorisation check inside the module.

### XXII.4.4 The query-scoping layer

This is the mechanism that removes hand-written tenancy from the product.

**R-XXII-080** Every read and write of a tenant-owned table MUST pass through the
persistence layer's scoping decorator. The decorator applies, in order:

1. **Tenancy** — `tenant_id = :tenant`, unconditionally, from the request context.
2. **Data scope** — the disjunction of the scopes of the assignments carrying the
   operation's permission (R-XXII-052).
3. **Soft-state filters** — retention state, supersession, `deleted_at`.

**R-XXII-081** A query issued **outside** an established scoping context MUST
throw, not run. There is no ambient default tenant and no "no context means all".
*Test:* a repository call in a unit test without a context raises
`MissingScopeContext`; the test asserting this is itself part of the suite.
*Origin:* Laravel's `->when($tid, …)` idiom, used at every one of the 1,375
call sites, silently omits the predicate for `NULL` and for `0`
(G BR-TEN-002) — the failure mode is "returns everything", which is the worst
available default.

**R-XXII-082** Deleting the tenant predicate from a query MUST break a test, not
widen a result set. This is the acceptance test for the whole layer, and it is
achievable only because the predicate is not in the query in the first place —
it is injected. *Origin:* G §10 item 11.

**R-XXII-083** Tenant stamping on **write** MUST be performed by the persistence
layer from the context, never by a hand-written array key. An insert that
supplies a `tenant_id` differing from the context MUST be rejected as a
programming error, not silently corrected. *Origin:* `UserController.php:201`
stamps by hand; `ApprovalService::safeRow():263–266` stamps nothing at all
(G BR-TEN-012).

**R-XXII-084** The scoping layer MUST support an explicit, auditable
**escape hatch** for the small number of legitimately cross-tenant operations —
platform administration (§XXII.5.5), the migration tooling, and the rule-pack
installer. The escape is:

```
ScopeContext::crossTenant(
    permission: 'platform.tenant.list',
    reason:     'Support ticket SUP-4471',
    ttl:        <bounded>,
)->run(fn () => …);
```

It requires a `platform.*` permission, writes an audit row **before** the block
runs, is time-boxed, and is refused entirely when the installation is not the
platform tenant. A CI grep asserts `crossTenant(` appears only in the declared
allow-list of call sites. *Origin:* SmartPRS 2's cross-tenant capability is the
*absence* of a value — `when($tid, …)` with a null `$tid` — which is inherited by
`platform_support`, by any null-tenant account, and by any code path that loses
the variable (G SEC-05, SEC-08).

**R-XXII-085** Raw SQL is permitted only through a reviewed, named repository
method that declares its scoping explicitly and is covered by a cross-tenant
isolation test. A CI grep fails the build on `DB::select`, `DB::statement` or
`whereRaw` outside that allow-list.

**R-XXII-086** No request handler, job or console command may execute DDL.
`Schema::create`, `Schema::table`, `ALTER TABLE`, `CREATE TABLE` and `DROP` are
forbidden outside `database/migrations`, and the production database user holds
no `CREATE`, `ALTER` or `DROP` grant. *(Law 2.)* *Origin:* SmartPRS 2 executes
**155 DDL statements from application code** across 58 files — including
`PushController::ensure()`, reached from the **unauthenticated** `/iclock/cdata`
endpoint (G §2.2, D-11) — and `ApprovalService::ensureAuditCols` runs about 28
`information_schema` probes on every approvals page load.

### XXII.4.5 The UI enforces nothing

**R-XXII-087** The front end MUST receive a **capability payload** generated from
the server catalogue — the set of operations the actor may perform, and for each
scoped operation, nothing more than a boolean. It MUST NOT receive, and MUST NOT
maintain, a permission matrix of its own. *Origin:* SmartPRS 2's client model is
a hand-maintained JS map inside a 12,533-line PHP heredoc (`AppController.php`,
`PERM_NAV`, `spCan`, `gateActions`), which is guaranteed to drift from the
server and in fact does — 26 screens have no mapping at all (K §6.17, D-14).

**R-XXII-088** Hiding a control is a convenience, never a control. Every action a
hidden control would have performed MUST be independently denied by the server.
*Test:* the route × role matrix (§XXII.14.4) exercises every operation for every
role directly, bypassing the UI entirely. *Origin:* SmartPRS 2's client gating
relies on `display:none`, and opening DevTools and calling `go('users')` or
`go('roles')` reaches Payroll, Statutory, Users and Roles from an Employee login
(K §6.17).

**R-XXII-089** The client MUST fail closed: an absent capability payload, a
capability the payload does not mention, or a parse failure MUST render the
control disabled and MUST NOT attempt the call. *Origin:* three client-side
fail-open defaults on one path — unknown role → `'Admin'`
(`AppController.php:93`), absent matrix → `spCan()` returns `true` (`:760`),
`'Admin'` → early return before any gating (`:769`) — plus a fourth in the shell,
where `roleAllows()` returns `true` for any role outside its three-key map
(N §1.9.2).

**R-XXII-090** A screen with no operation mapping MUST be **unreachable**, not
merely unlisted. Navigation is generated from the operation registry; there is no
route the navigation cannot express and no navigation entry without a route.

### XXII.4.6 Field-level enforcement

**R-XXII-091** The response projection MUST be computed from the field
definitions and surface rules (spine S9; Part X §X.2.3, §X.2.6) for the actor's
effective permission set. A field the actor may not see is **absent from the
payload**, not present and hidden. *Origin:* SmartPRS 2 returns a fixed 62-field
employee projection to every caller of `/app/data`, including `ctc`, `pan`,
`uan`, `bankAcc`, `ifsc`, `homeLat`, `homeLng` and every reference's Aadhaar and
PAN, with no field-level allow-list anywhere (G §5.2 item 4).

**R-XXII-092** Masking is applied server-side, in the projection. A masked value
MUST NOT be accompanied by its unmasked form in any field, header, embedded
payload, error message, log line or export. *Test:* a response-body scan asserts
no unmasked government identifier appears in any response where the actor lacks
`employee.field.sensitive.view`.

**R-XXII-093** Unmasking is an **operation**, not a UI state:
`GET /api/v3/employees/{uuid}/fields/{field}/reveal` requires the relevant
`*.sensitive.view` permission, requires re-auth where the field declares it,
returns one field, and writes a `sensitive_read` audit row naming the field and
the subject.

### XXII.4.7 Fail-closed semantics

**R-XXII-094** Every one of the following MUST deny:

| Condition | Response | Audit | Alert |
|---|---|---|---|
| Exception anywhere in steps 1–8 | 403 (or 503 where the fault is clearly infrastructural) | `authz.error` | yes, immediate |
| Null or unresolvable principal | 401 | `authz.denied` | no |
| Null or unresolvable tenant | 403 | `authz.error` | yes |
| Tenant id of `0`, or any sentinel | 403 | `authz.error` | yes |
| Unknown role code on an assignment | 403 | `authz.error` | yes |
| Unmapped permission on an operation | 403 | `authz.error` | yes — this is a deployment defect |
| Scope resolver returns an error value | 403 | `authz.error` | yes |
| Scope resolution exceeds its time or depth budget | 403 | `authz.error` | yes |
| Permission cache unavailable | 403 | `authz.error` | yes |
| Assurance requirement unmet | 403 with `MFA_REQUIRED` / `REAUTH_REQUIRED` | `authz.assurance` | no |

**R-XXII-095** There MUST be no `catch` in the authorisation path that returns an
allow. A CI static-analysis rule fails the build on any `catch` inside
`Domain\Access` whose block does not terminate in a denial or a rethrow.
*Origin:* six controls in SmartPRS 2 return "allowed" from a `catch`, one of them
under the source comment *"fail open — availability over strictness on pivot
errors"* (`ApprovalService.php:377–379`; G D-17, SEC-01).

**R-XXII-096** Fail-closed behaviour MUST be proven by **fault injection** in CI,
one test per guard family: authorisation, tenancy, assurance, commercial gate,
state gate, and the query-scoping layer. Each test forces the underlying lookup
to throw and asserts (a) the response is a denial, (b) an audit row exists, (c)
an alert was raised, and (d) **no row was written**. *(Spine S1 Law 5 test;
consolidated requirements §F P0.)*

**R-XXII-097** A denial MUST NOT reveal existence. A request for a record in
another tenant returns **404**, not 403. A request for a record in the same
tenant but outside the actor's data scope returns **403** with a scope reason
code. The distinction is deliberate: cross-tenant existence is not disclosable;
intra-tenant scope is, because the actor already knows the tenant's shape.
*(Consolidated requirements §F P0.)*

### XXII.4.8 Machine and background actors

**R-XXII-098** A queued job, scheduled task or console command MUST run under an
explicit actor context — the user who enqueued it, or a named **system actor**
with its own role assignment. There is no "runs as everyone" mode. The context is
serialised with the job payload and re-established on execution.

**R-XXII-099** A system actor's assignment is a first-class, auditable row. It is
listed in the Users screen with a `is_system` marker and cannot sign in
interactively.

**R-XXII-100** Where a job legitimately spans tenants — the nightly compliance
scan, the SLA sweep — it MUST iterate tenants explicitly, establishing a fresh
scope context per tenant, and MUST NOT use `crossTenant()`. *Origin:* SmartPRS
2's `DemoReset` looks up users by lowercase email with **no tenant scope** and
reassigns `tenant_id`, which is how a maintenance command becomes a cross-tenant
account takeover (`DemoReset.php:106–122`; G BR-SEC-012).

### XXII.4.9 What is audited

**R-XXII-101** Every one of the following writes an audit row through the audit
service (spine S14), with field-level before/after where applicable:

```
login success · login failure · logout · session revoked · lockout applied
· lockout cleared · password changed by self · password reset requested
· password set by another actor · MFA enrolled · MFA reset by another actor
· device trusted · device revoked
· role created · role version published · role retired
· permission grant changed · assignment granted · assignment revoked
· assignment scope changed · scope version published
· user created · user disabled · user re-enabled · user deleted
· platform staff created / re-roled / disabled
· tenant provisioned / suspended / resumed
· impersonation started · impersonation ended
· cross-tenant escape hatch entered and exited
· API credential issued · rotated · revoked
· authorisation denied (permission) · authorisation denied (scope)
· authorisation error (fail-closed)
· sensitive field revealed · sensitive export produced · document downloaded
```

*Origin:* SmartPRS 2 has 11 functional `Audit::record` call sites in the entire
product and audits **successful login only** — not logout, not failed login, not
role assignment, not user creation or disablement, not an administrator setting
another user's password, not platform staff CRUD, not tenant provisioning
(G SEC-21, D-18). None of the escalation paths in §XXII.1.6 would have left a
trace.

**R-XXII-102** Read access to sensitive data classes MUST be audited, because the
highest-impact findings in SmartPRS 2 are **read** paths that leave no record
(G §10 item 24). `sensitive_read` rows are written for: an unmasked government
identifier, a bank detail, a salary figure for another employee, a payslip for
another employee, a document download, and any export carrying a `sensitive` or
`restricted` field.

**R-XXII-103** An audit write failure MUST fail the business transaction. The
audit row and the business row commit together or not at all.
*(Consolidated requirements §F P0.)*

**R-XXII-104** The audit chain is **per tenant** (spine S14), append-only,
hash-chained, with a daily signed anchor, and verifiable in bounded memory.
*Origin:* SmartPRS 2 chains globally across tenants — row *n* of tenant A chains
onto row *n−1* of tenant B, so one tenant's edit reports the chain as broken for
another (`Audit.php:91`; G §4.3, D-19) — and `Audit::verify()` loads every
tenant's rows into memory whenever any administrator opens Activity Logs
(`Audit.php:111`; G SEC-28).

### XXII.4.10 Rate limiting

**R-XXII-105** Rate limits MUST be configuration, keyed on **identity** — user,
tenant, API credential, device — and not only on IP. Each operation declares its
`rate_limit_key`; the limits themselves live in a platform constant table with
per-tenant override where declared overridable. *Origin:* 34 literal
`throttle:n,m` strings in `routes/web.php`, all IP-keyed, and login throttled at
5/min/IP with **no per-account counter at all** (G §5.2 item 16, BR-AUTH-021).

**R-XXII-106** Every credential-verifying endpoint MUST be throttled on both the
identity and the source. `POST /api/v3/auth/change-password` is explicitly
included. *Origin:* SmartPRS 2's change-password route carries no throttle,
making it an online current-password oracle (`routes/web.php:376`; G SEC-14).

**R-XXII-107** A throttle rejection MUST be uniform in wording and timing with
other failures on the same endpoint, and MUST NOT disclose whether the identity
exists.

---

## XXII.5 Tenant isolation (brief Section 71)

### XXII.5.1 The mechanism

**R-XXII-110** `tenant_id BIGINT UNSIGNED NOT NULL` appears on **every** business
table, with a foreign key to `tenants(id)` and `ON DELETE RESTRICT`. There is no
nullable tenant column and no business table without one. *(Law 6.)*
*Origin:* SmartPRS 2 has four different names for the concept (spine S16), a
live `tenant_id` and an inert `company_id` in parallel (G D-02), and known
null-tenant rows in `kb_topics`, `attendance_logs`, `biometric_configs` and
`notices` (G §9).

**R-XXII-111** There is **no null-tenant state and no sentinel tenant**. `NULL`
is prevented by the column definition; `0` is prevented by a check constraint
(`tenant_id > 0`) and by the foreign key. Platform users belong to a real
platform tenant row with its own id. *Origin:* SmartPRS 2's platform users have
`tenant_id = NULL`, which is the widest scope in the product, and
`AppStateController.php:19` and `ConfigController.php:43, 52` then coerce that
null to `0` so all platform users share one settings row (G BR-TEN-002,
BR-TEN-008).

**R-XXII-112** `tenant_id` MUST be the **leading column of every business unique
index**. `UNIQUE(emp_code)` is a schema error; `UNIQUE(tenant_id, emp_code)` is
correct. The migration linter fails the build on any unique index over a
tenant-owned table that does not lead with `tenant_id`. *(Law 6.)*

**R-XXII-113** `tenant_id` MUST lead the composite index of every query pattern
the module declares, so that isolation is also the access path and a missing
filter is a performance failure as well as a correctness one.

**R-XXII-114** Foreign keys MUST NOT cross tenants. Where a child table
references a parent that is itself tenant-owned, the child carries its own
`tenant_id` and a composite foreign key `(tenant_id, parent_id)` referencing
`(tenant_id, id)` on the parent, so the database itself rejects a cross-tenant
association. *Origin:* SmartPRS 2 has **zero foreign keys on business tables**
and ships an admin endpoint whose job is sweeping up the resulting orphans
(spine S16).

**R-XXII-115** Uploaded files and generated documents MUST be stored under a
tenant-partitioned prefix on a private disk, and the storage adapter MUST
construct the prefix from the scope context — never from a caller-supplied path
(§XXII.6.6).

**R-XXII-116** The tenant MUST be derived from the authenticated principal. It
MUST NOT be derived from a request body field, a query parameter, a `Host`
header alone, a device serial, or a company slug. Branded portals resolve a
*presentation* (logo, colours), never an *authorisation context*. *Origin:*
`/c/{slug}` loads every company row on the installation, matches by slugified
name, and sets `session.portal_company_id` to a **foreign** company id
(`AuthController.php:139–158`; G SEC-15). Separately, `/iclock/*` identifies a
device — and therefore a tenant — by a caller-chosen serial number with no
credential at all (G §7.1 #5).

**R-XXII-117** A device, connector or webhook credential MUST bind to exactly one
tenant, and the tenant MUST come from the credential. `(tenant_id, device_serial)`
is unique; an unknown device is **quarantined**, never auto-registered into a
guessed tenant. *(Spine S10.1.)* SmartPRS 2's `ApiKeyAuth` already does this
correctly — hashed at rest, `hash_equals`, scope check, tenant binding enforced,
null-tenant key refused — and is the single best-engineered security component in
the codebase (G §9); §XXII.8 generalises it.

**R-XXII-118** Cross-tenant reach MUST require a positive, capability-gated,
audited grant. It MUST NOT be obtainable by the absence of a value, by a falsy
check, by a missing predicate, or by a role name. *Origin:* the entire class of
SmartPRS 2 cross-tenant findings — SEC-05, SEC-08, SEC-29, SEC-30 — derives from
`when($tid, …)` skipping its callback.

### XXII.5.2 The prohibition, and what replaces it

**R-XXII-119** Application code MUST NOT issue a query that spans tenants. The
only cross-tenant paths in SmartPRS 3 are:

| Path | Mechanism | Gate | Audit |
|---|---|---|---|
| Platform administration | `ScopeContext::crossTenant()` on an allow-listed call site | `platform.*` permission + MFA | row before and after, with reason |
| Impersonation | a scoped session inside one target tenant (§XXII.5.6) | `platform.tenant.impersonate` + MFA + consent | full session transcript marker |
| Migration tooling | offline, outside the request path, on a maintenance credential | operator + `APP_ENV` guard | migration run log |
| Rule-pack installation | writes only to platform-owned statutory tables | `statutory.rulepack.accept` | pack acceptance record |

**R-XXII-120** Platform-owned reference data — rule packs, the permission
catalogue, scope types, module registry, country/state reference — lives in
**platform tables** that are explicitly declared non-tenant-owned and are
**read-only** to every tenant. They are not "tables with a null tenant"; they are
a declared, separate class in the schema registry, and the migration linter
enforces that a table is in exactly one class. *Origin:* SmartPRS 2's
platform-wide notices (`tenant_id IS NULL`, by design; G BR-TEN-006) and its
null-tenant `kb_topics` (**not** by design; G SEC-30) are indistinguishable in
the schema, which is why one customer's administrator can rewrite the Code of
Conduct another customer's agents acknowledge.

### XXII.5.3 Tenant lifecycle

**R-XXII-121** Provisioning a tenant is a single transaction producing: the
tenant row, its default org units, the seeded system roles and scopes, the
retention classes, and **exactly one** administrator invitation. It does not
create a password. *(§XXII.7.11.)* *Origin:* SmartPRS 2's provisioning emails a
cleartext temporary password (`SaasController.php:185, 558–575`; G SEC-16) and,
by default, also sends it over WhatsApp, persisting it forever in
`wa_log.body_values` (`SignupController.php:413–425`; L SEC-L-03).

**R-XXII-122** Suspending a tenant MUST deny at step 8 of the pipeline with a
distinct reason code, MUST NOT delete data, and MUST NOT be bypassable by a
role. *Origin:* `EnsureSubscriptionActive.php:61–63` returns early for a null
tenant, so a null-tenant account is never subscription-gated at all (G §7.1 #16).

**R-XXII-123** Tenant deletion is a **retention-class-governed**, scheduled,
audited operation with an export-first step and a mandatory cooling-off period.
It is never a `DELETE`. *(Spine S16.)*

### XXII.5.4 Isolation in the derived surfaces

Isolation must hold in the places SmartPRS 2 lost it, which were mostly not the
list endpoints.

**R-XXII-124** Reports, exports, dashboards, aggregate counts, global search,
generated PDFs, letter merges, ID cards, photo serving, template previews and
notification recipient resolution MUST all pass through the same scoping layer.
*Origin:* `DashboardController.php:99–100` aggregates null-tenant attendance into
every tenant's count; `DashboardController.php:198–225` applies a tenant filter
to global search but **no** self-scope (G BR-TEN-011); `TestController.php:220,
246, 250` fetches a test and its question bank with **no tenant filter at all**
(G SEC-29).

**R-XXII-125** Caches, queues and rate-limit buckets MUST be keyed by tenant.
A cache key without a tenant component is a build failure detected by the key
factory, which is the only permitted way to construct one.

**R-XXII-126** Every emitted event, webhook payload, log line and error report
MUST carry the tenant id, and webhook delivery MUST be filtered to endpoints
belonging to that tenant.

**R-XXII-127** The audit ledger's hash chain is per tenant (R-XXII-104), and
`verify()` operates on a bounded window with a cursor.

### XXII.5.5 The platform-administration path

**R-XXII-128** Platform staff hold assignments in the **platform tenant**, with
`platform.*` permissions and the `PLATFORM` system scope. They hold **no**
assignment in any customer tenant.

**R-XXII-129** Every platform operation that touches customer data MUST be:

| Property | Requirement |
|---|---|
| Explicit | reached only through `crossTenant()` or impersonation, never as a side effect |
| Least-privileged | a support role that can read a diagnostic is not a role that can read salary |
| Time-boxed | the grant carries an expiry; the default for a support grant is short and is a platform constant |
| Reasoned | a free-text reason and, where the tenant requires it, a ticket reference are mandatory inputs |
| Audited both sides | a row in the platform ledger **and** a row in the customer tenant's ledger |
| Visible to the customer | surfaced on the tenant's own "vendor access" screen, in near real time |
| Notifiable | the tenant may configure notification to named recipients on every access |

**R-XXII-130** The platform support role MUST NOT hold `employee.field.sensitive.view`,
`employee.bank.view`, `employee.salary.view`, `payroll.*` or
`employee.document.download` by default. Where a support case genuinely requires
one, it is granted as a **time-boxed, single-tenant, consented elevation**
(§XXII.5.6), never as a standing role property. *Origin:* SmartPRS 2's
`platform_support` is created with `tenant_id = NULL` — the widest scope
available — is checked by zero guards, renders as "Admin" in the SPA, and is
never subscription-gated (G §7.1 #16, D-06).

> **PRODUCT OWNER DECISION REQUIRED — D-XXII-05** (§XXII.19) settles what vendor
> support is contractually permitted to see, which must also appear in the
> customer contract and the DPDP notice. G §11 Q-05 raises the same question
> about the shipped product.

### XXII.5.6 Impersonation

**R-XXII-131** Impersonation ("view as") is a distinct, first-class mechanism —
never a login as another user's credentials, never a session cookie swap.

```
impersonation_session
  actor_user_id            the platform or tenant actor
  target_user_id           whose view is being adopted
  tenant_id                the target's tenant; the session is bound to it
  permission_mode          read_only | read_write        (default read_only)
  effective_permissions    intersection(actor's granted support set,
                                        target's effective set)
  reason, ticket_ref
  consent_id               NULL only where the tenant's policy permits
  started_at, expires_at   bounded by a platform maximum
  ended_at, ended_reason   manual | expired | revoked_by_tenant
```

**R-XXII-132** Impersonation MUST require `platform.tenant.impersonate` (or, for
an intra-tenant "view as", a tenant-level equivalent), MFA at the moment of
initiation, and a reason.

**R-XXII-133** **Consent.** Whether impersonation requires the tenant's prior
consent is a per-tenant configuration with three settings — `always_require`,
`notify_only`, `contractual` — and a secure default of `always_require` for any
mode other than `read_only`. Consent is a recorded, expiring grant, not a
checkbox in a contract.

**R-XXII-134** An impersonated session MUST be visibly marked in every UI surface
and MUST stamp every audit row, event, webhook and generated document with both
the acting identity and the impersonated identity. An impersonated actor's
effective permissions are the **intersection** in R-XXII-131 — impersonation
never grants more than the target holds.

**R-XXII-135** Impersonation MUST NOT be usable to change credentials, MFA
enrolment, role assignments, bank details or payroll approval state, regardless
of the target's permissions. These operations are excluded by an explicit
`impersonation_forbidden` flag on the operation registry entry.

**R-XXII-136** The tenant MUST be able to terminate an impersonation session
immediately from its own vendor-access screen, and MUST see the full history with
reasons.

### XXII.5.7 The generated cross-tenant isolation suite

**R-XXII-137** For **every** aggregate and **every** API operation, the build MUST
generate and run a cross-tenant isolation test. Generation is from the operation
registry (R-XXII-077), so an operation added without a test is impossible.
*(Law 6 test; spine S1.)*

**R-XXII-138** The generated harness constructs two tenants that are deliberately
confusable:

```
Tenant A and Tenant B each receive:
  · an employee with emp_code EMP100 and the same full name
  · the same PAN-shaped and Aadhaar-shaped values (distinct, valid-shaped)
  · a company named "Acme Services Pvt Ltd" in both
  · a branch named "Hyderabad" in both
  · a payroll run for the same period, with the same run number
  · a document with the same original filename
  · an attendance day for the same date
  · a leave request for the same dates
  · identical master-registry rows in every registry type
  · API credentials, one per tenant
```

**R-XXII-139** For every operation, the generated test asserts, as Tenant A's
actor:

| # | Assertion |
|---:|---|
| 1 | A list operation returns **zero** Tenant B rows |
| 2 | A read by Tenant B's public id returns **404**, not 403 (no existence oracle) |
| 3 | A write addressed to Tenant B's public id returns 404 and **writes nothing** |
| 4 | An upsert whose natural key collides with a Tenant B row creates a Tenant A row and **does not update** B's |
| 5 | An aggregate, count, dashboard tile or report total excludes B entirely |
| 6 | A search across every searchable field returns no B row |
| 7 | An export contains no B row and no B file |
| 8 | A generated PDF or letter merges no B data |
| 9 | A document or asset URL issued in A cannot be redeemed for a B object |
| 10 | An emitted event and any resulting webhook carry tenant A and reach only A's endpoints |
| 11 | A's API credential presented against a B-scoped resource returns 404 |
| 12 | The audit chain of A verifies independently after interleaved writes from B |

**R-XXII-140** The suite MUST additionally assert the **null-tenant** cases that
SmartPRS 2 fails: an actor whose tenant is somehow unresolvable performs a
tenant-scoped list, read, write and delete — each MUST be refused, never global.
*(Consolidated requirements §F P0.)*

**R-XXII-141** A failure in the isolation suite **blocks release**. It is not
triageable to a later sprint.

---

## XXII.6 Sensitive data (brief Section 72)

### XXII.6.1 The classification scheme

SmartPRS 3 uses **one** classification vocabulary, declared on the field
definition (spine S9; Part X §X.2.6) and propagated automatically. It is not
inferred from a column name, and it is not restated per module.

| `pii_class` | Meaning | Storage | Default masking | Export | Audit on read |
|---|---|---|---|---|---|
| `none` | operational data | plain | none | exportable | no |
| `identifying` | names a person: name, contact, address, employee code, photo | plain; blind-indexed where searched | none | exportable; **bulk** export audited | bulk only |
| `sensitive` | government identifiers, bank, salary, tax, biometric identifiers, DOB | **encrypted at the application layer**, blind-indexed | `last4` | requires `employee.export.sensitive`; always audited | yes |
| `restricted` | health-adjacent, disciplinary, background-verification detail, credentials | encrypted; additional per-record access list where declared | `full` | not exportable without a per-export approval | yes |

**R-XXII-150** The PII class is declared on the field and propagates
automatically to: storage encryption, masking on every surface, the audit
ledger's decision to store a hash rather than a value, export eligibility,
import-error-report redaction, retention class, and the response projection
(§XXII.4.6). *(Restates Part X R-X-021 as the security-side obligation; the two
are the same rule, declared once.)*

**R-XXII-151** A field's class may be **raised** by a tenant without approval and
**lowered** only through maker-checker, because lowering a class widens
disclosure retrospectively across every surface.

**R-XXII-152** An error message, stack trace, log line, import error report,
validation message, notification body or exception payload MUST NOT contain a
`sensitive` or `restricted` value. *Test:* a redaction filter is applied at the
logging boundary and asserted by a test that emits every classified field into an
exception path. *Origin:* SmartPRS 2's import error report embeds PII by
construction — *`PAN "ABCDE1234F" is already used by EMP100 (Ravi)`*
(`EmployeeFieldRules.php:603–604`) — and the endpoint that serves it has no role
check at all (Part X §X.2.6; G §7.3).

### XXII.6.2 The data-classification table

Classification for the fields this part is required to name. "Blind index" means
a keyed HMAC stored alongside the ciphertext so equality lookup and uniqueness
work without decryption.

| Field | `pii_class` | At rest | Blind index | Default mask | Reveal permission | Export | Retention class |
|---|---|---|---|---|---|---|---|
| **Aadhaar number** | `sensitive` | encrypted | yes — uniqueness within tenant | `XXXX XXXX 1234` (`last4`) | `employee.field.sensitive.view` + re-auth | `employee.export.sensitive`, audited, masked unless the export profile declares otherwise and is approved | statutory-employment |
| **Aadhaar image / e-KYC artefact** | `restricted` | encrypted object; private disk | n/a | not rendered inline; signed URL only | `employee.document.download` + `employee.field.restricted.view` | never in a bulk export | statutory-employment |
| **PAN** | `sensitive` | encrypted | yes — uniqueness within tenant | `ABCDE****F` | `employee.field.sensitive.view` | `employee.export.sensitive`; **statutory returns are an approved export profile** | statutory-tax |
| **UAN** | `sensitive` | encrypted | yes | `last4` | `employee.field.sensitive.view` | as PAN | statutory-employment |
| **ESIC number** | `sensitive` | encrypted | yes | `last4` | `employee.field.sensitive.view` | as PAN | statutory-employment |
| **Bank account number** | `sensitive` | encrypted | yes — duplicate-account detection | `last4` | `employee.bank.view` | `employee.export.sensitive`; the **bank payment file** is an approved profile requiring `payroll.bankfile.generate` | financial |
| **IFSC / bank name / branch** | `identifying` | plain | n/a | none | `employee.bank.view` | with bank export | financial |
| **Bank change request evidence** | `restricted` | encrypted object | n/a | signed URL | `employee.bank.update` | never | financial |
| **CTC, gross, net, component amounts** | `sensitive` | plain in payroll tables (integers, needed for arithmetic and reconciliation); **access-controlled and audited** | n/a | not shown without permission | `employee.salary.view` / `payroll.line.view` | `payroll.export` + `employee.export.sensitive`, audited | payroll-statutory |
| **Salary structure assignment** | `sensitive` | plain | n/a | not shown without permission | `payroll.structure.view` | as above | payroll-statutory |
| **Tax declarations and investment proofs** | `sensitive` (declaration) / `restricted` (proof documents) | encrypted; proofs on private disk | n/a | `last4` on identifiers within; documents by signed URL | `ess.tax.declare` for own; `payroll.line.view` + `employee.field.sensitive.view` for others | statutory returns only, as an approved profile | statutory-tax |
| **Date of birth** | `sensitive` | encrypted | yes (age-band queries use a derived, non-reversible band) | `**-**-1987` | `employee.field.sensitive.view` | as PAN | statutory-employment |
| **Gender, marital status** | `identifying` | plain | n/a | none | `employee.record.view` | basic | statutory-employment |
| **Health-adjacent fields** — medical-certificate metadata behind a sick-leave request, fitness-certificate status, accident/injury record, disability declaration made for statutory accommodation | `restricted` | encrypted; per-record access list | n/a | `full` — the *existence* of the record is visible to the approver, the *content* is not | `employee.field.restricted.view`, plus the record's own access list | **never exportable** without a per-export approval naming the recipient and the purpose | health-restricted |
| **Medical certificate document** | `restricted` | encrypted object, private disk, malware-scanned | n/a | signed URL, short TTL | `employee.document.download` + `employee.field.restricted.view` | never | health-restricted |
| **Background-verification detail (BGV report, criminal check outcome)** | `restricted` | encrypted | n/a | `full` | `employee.field.restricted.view` | never in bulk | compliance |
| **Police clearance (PCC) outcome and document** | `restricted` | encrypted; document on private disk | n/a | status visible, document not | `compliance.credential.view` (+ document permission) | compliance pack only, as an approved profile | compliance |
| **Disciplinary action, complaint, POSH record** | `restricted` | encrypted; per-record access list (POSH records are access-listed to the committee) | n/a | `full` | explicit per-record access list only — **no role grants blanket access** | never | compliance-restricted |
| **Biometric device configuration — host, port, device password / comm key** | `restricted` (credential) | **encrypted**; never returned in any read | n/a | never rendered; write-only field with a "set new value" affordance | `master.credential.update` to set; **no permission reveals it** | never | credential |
| **Biometric template / template identifier** | `sensitive` | template bytes are **not stored by SmartPRS**; only an opaque device-side identifier is stored, encrypted | yes | `full` | `attendance.device.manage` | never | biometric |
| **Integration credentials — WhatsApp API key, SMS API key, SMTP password, gateway secret, webhook secret, licence secret** | `restricted` (credential) | **encrypted**; never returned in a read; rotated, not read | n/a | never rendered | `master.credential.update` / `integration.connector.manage` to set; **none to read** | never | credential |
| **User password** | credential | **hashed** (Argon2id or bcrypt at the platform cost), never encrypted | n/a | never rendered | none | never | credential |
| **MFA secret / recovery codes** | credential | encrypted (secret); recovery codes **hashed** | n/a | shown once at enrolment, never again | none | never | credential |
| **API key** | credential | **hashed** (SHA-256 of a high-entropy secret); prefix stored plain as a lookup handle | n/a | shown once at issue | none | never | credential |
| **Reset / invite / portal tokens** | credential | **hashed** at rest | n/a | never | none | never | credential |
| **Home latitude / longitude, device location** | `sensitive` | encrypted | n/a | coarse (locality) unless permitted | `employee.field.sensitive.view` | approved profile only | operational |
| **Photograph** | `identifying` | private disk; signed URL | n/a | none | `employee.record.view` | with basic export where the profile declares it | statutory-employment |

**R-XXII-153** **Credentials that must be verified are encrypted; credentials
that need only be compared are hashed.** A decryption failure raises an operator
alert and **never** falls back to treating ciphertext as plaintext. *(Spine
S13.)* *Origin:* SmartPRS 2 gets most of this right — Razorpay, biometric,
licence and SmartEPT secrets are `Crypt`-encrypted, reset tokens and API keys are
SHA-256 hashed, `hash_equals` is used correctly (G §7.6) — with two outliers:
**tenant SMTP passwords stored as plain JSON** in `app_settings.value`
(`ConfigController.php:47–55, 138–139, 202`; G SEC-22), and **public bearer
tokens stored in cleartext with no expiry** (G SEC-17).

**R-XXII-154** A credential field is **write-only**. No permission and no API
returns it. Rotation replaces it; verification tests it; nothing displays it.
*Origin:* `/app/master/{type}` returns `wa_settings.api_key` and
`sms_settings.api_key` to any authenticated user of the tenant
(`MasterController.php:454–465`; G SEC-06).

**R-XXII-155** Encryption keys MUST be separable from `APP_KEY`, held in a key
store or a dedicated key file with its own rotation record, and key rotation MUST
be supported without re-encrypting the world in a single transaction (versioned
key ids on each ciphertext). *Origin:* in SmartPRS 2, everything `Crypt`-encrypted
is keyed on `APP_KEY`, and `APP_KEY` is inside the unencrypted nightly backup
which also sits on the same volume (`config/backup.php:28–38, 72–81`;
`.env.production.example:65–66`; L SEC-L-06) and is readable through the
commission-proof path traversal (G SEC-24). One file read decrypts every secret in
the system.

### XXII.6.3 Masking

**R-XXII-156** Masking rules are declared on the field
(`masking_rule_code ∈ none | last4 | first_last | full | domain_only | custom`)
and may be overridden per surface and per role in `field_surface_rules`
(Part X §X.2.3). The default for a field with no declared rule is `full` — the
most restrictive — not `none`.

**R-XXII-157** Masking is applied **server-side, in the projection** (R-XXII-092).
The unmasked value never leaves the server for an actor who may not see it.

**R-XXII-158** A masked value MUST be masked consistently across every surface —
screen, API, export, PDF, letter merge, notification, webhook payload, report —
because a single surface that forgets is the whole control. *Test:* the surface
matrix test in §XXII.14.7.

### XXII.6.4 Downloads and exports

**R-XXII-159** Every export is produced from a declared **export profile**:

```
export_profile
  profile_key, name, description,
  entity_key, columns[]                    -- explicit; never "all fields"
  max_pii_class                            none | identifying | sensitive | restricted
  required_permissions[]
  requires_approval                        bool  (mandatory when max_pii_class = restricted)
  masking_override                         per column, may only tighten
  destination_constraints                  download | sftp(allow-listed) | connector
  retention_hours                          artefact TTL
  watermark                                bool — actor, timestamp, tenant
```

**R-XXII-160** An export carrying a `sensitive` column requires
`employee.export.sensitive` (or the module equivalent), is **audited with the row
count and the column list**, and produces an artefact that expires. An export
carrying a `restricted` column additionally requires a per-export approval
naming the recipient and the purpose.

**R-XXII-161** Export artefacts MUST be encrypted at rest, delivered by a signed
expiring URL, and purged on expiry. They MUST NOT be written to a public path
and MUST NOT be attached to email in the clear.

**R-XXII-162** Bulk download of documents MUST be a distinct, separately
permissioned operation with its own rate limit and audit class. Iterating a list
endpoint and downloading each item MUST trip the per-identity rate limit and
raise an anomaly event (§XXII.12).

### XXII.6.5 Uploads

**R-XXII-163** Every upload MUST be validated by **content**: magic-byte
sniffing plus a MIME check plus an extension check, in that order of authority,
against a per-purpose allow-list. The client-supplied filename and extension are
**never** authoritative. *Origin:* SmartPRS 2 validates by filename extension
only — `in_array($file->getClientOriginalExtension(), ALLOWED_EXTS)`
(`DocumentController.php:214–218`; `SelfOnboardingController.php:319`) — and a
grep for `finfo_` or `getMimeType()` across `app/` returns **zero** (G §7.7).

**R-XXII-164** Every upload MUST be **malware-scanned** before it becomes
retrievable. The scan is a pipeline stage, not an option: an unscanned object is
in state `quarantined` and no signed URL can be issued for it. A scanner outage
holds objects in `pending_scan` — it never admits them. A detection quarantines,
alerts, audits, and notifies the uploader without disclosing the signature.

**R-XXII-165** Uploaded objects MUST be stored under a **server-generated** key:
`tenant/{tenant_uuid}/{entity}/{entity_uuid}/{object_uuid}` with no component
derived from user input. The original filename is metadata, sanitised, and used
only as the `Content-Disposition` value on an authorised download.

**R-XXII-166** A caller-supplied path MUST NEVER be stored, joined, or
dereferenced. *Origin:* SmartPRS 2 accepts a commission proof path if it merely
*starts with* `commission-proofs/` (`RequestController.php:501–503`) and then
serves `storage_path('app/public/'.$rec->proof_path)` with no `realpath()`, no
`basename()` and no `..` rejection (`:1626–1629`) — an arbitrary authenticated
file read that reaches `.env`, hence `APP_KEY`, hence every encrypted column and
forged sessions (G SEC-24).

**R-XXII-167** Image uploads MUST be re-encoded server-side (stripping EXIF and
any embedded payload) before storage where the field's purpose is display.
Documents retained as evidence are stored byte-exact with a content hash, and are
never rendered inline — only downloaded through a signed URL with
`Content-Disposition: attachment` and a restrictive `Content-Security-Policy`.

**R-XXII-168** Per-purpose size caps, per-employee quotas and per-tenant storage
quotas are configuration, not literals. *Origin:* `DocumentController::MAX_FILE_MB = 10`
and `DEFAULT_EMP_QUOTA_MB = 100` are constants at `:36, :38` (G §5.2 item 15).

### XXII.6.6 Private storage and signed URLs — the absolute rule

**R-XXII-169** **No private document may reside on a publicly readable path, at
any time, in any deployment, for any reason.** There is no public disk in
SmartPRS 3 for tenant content. Public assets — the marketing site, the product's
own icons — live in a separate, explicitly declared public bucket that no tenant
write path can reach. *(Spine S13.)*

*Origin, in full, because this is the highest-severity item in the dossier.*
SmartPRS 2 writes employee documents, photographs, commission proofs and off-roll
agent KYC to the **web-served** `public` disk (`DocumentController.php:234`;
`LetterController.php:348, 467`; `RequestController.php:1605`;
`OffrollAgentController.php:450`). Self-onboarding uploads correctly to `local`
and then **copies** the identity, address, bank, PCC and DRA documents to the
public disk at provisioning (`SelfOnboardingController.php:953–955`).
`config/filesystems.php:38–44` maps that disk to `storage/app/public` with
`'visibility' => 'public'`, and `:69–71` symlinks `public/storage` to it. And
**`public/.htaccess` does not exist in the source tree at all** — `public/`
contains only `index.php` and `web.config`, which blocks the `storage` segment
on IIS only (G §0.3, D-23, §7.7). Whether a live Linux/Apache deployment is
currently serving Aadhaar and PAN documents without authentication therefore
cannot be determined from the repository. It is G §11 Q-01 — *"the single
highest-value question in the dossier"* — and it is a DPDP breach-assessment
question, not an engineering one.

**R-XXII-170** Every private object is served **only** through an operation that
runs the full pipeline (§XXII.4.1), including the subject scope check. The
response is either the bytes or a **signed, expiring, single-purpose URL**.

**R-XXII-171** A signed URL MUST bind: the object id, the tenant, the actor, the
operation, an issue time, a short TTL (a platform constant, per purpose,
overridable downward per tenant), and a nonce. It MUST be single-use for
`restricted` objects. Redemption is audited. A URL issued for one actor MUST NOT
be redeemable by another. *Origin:* SmartPRS 2's public bearer tokens — offer
accept, NDA sign, transfer accept, agent verify, agent live-earnings, quote pay
link, licence invoice — are `Str::random(40|48)`, stored **cleartext**, and
**never expire and cannot be revoked** (G SEC-17, NEW-SEC-105).

**R-XXII-172** Token-secured **public portals** (offer acceptance, NDA signature,
self-onboarding, transfer acknowledgement, agent verification, agent earnings,
quotation payment) MUST: hash the token at rest, carry a configurable expiry,
support explicit revocation, be single-purpose, be rate-limited per token, and
disclose only the minimum needed for the transaction. A portal token is a
credential and is treated as one.

**R-XXII-173** The deployment artefact MUST NOT depend on a web-server
configuration file being present to be safe. Safety is a property of where the
bytes are, not of a rewrite rule. The installer verifies that the private storage
root is not reachable over HTTP and **fails** the health check if it is.
*(Spine S18.)*

### XXII.6.7 Retention

**R-XXII-174** Every entity and every document purpose carries a declared
**retention class**; archival, anonymisation and purge are scheduled, audited
operations governed by it. *(Spine S16.)* Retention classes referenced in this
part: `statutory-employment`, `statutory-tax`, `payroll-statutory`, `financial`,
`compliance`, `compliance-restricted`, `health-restricted`, `biometric`,
`credential`, `operational`. Their durations are **configuration populated by the
compliance owner**, not values asserted here.

**R-XXII-175** Purge MUST remove the object, its derivatives (thumbnails,
generated PDFs, export artefacts), its cache entries and its search index terms,
and MUST leave a tombstone in the audit ledger. A retention job failure is an
operational alert, not a silent skip.

---

## XXII.7 Authentication (brief Section 73)

### XXII.7.1 The shape of the answer

Authentication in SmartPRS 3 is **configuration with secure defaults that the
installer cannot skip**. Every knob below is a column in a versioned policy
artefact, resolvable per tenant, with a platform floor that a tenant may tighten
and may never loosen.

```
auth_policy  (config artefact; effective-dated; versioned; scope = tenant)
  password:      min_length, require_classes[], max_length, disallow_patterns[],
                 breach_check_enabled, history_depth, min_age_hours,
                 max_age_days, expiry_warning_days, dictionary_check_enabled,
                 disallow_identity_terms   (name, email local part, employee code)
  lockout:       failure_threshold, window_minutes, lock_duration_minutes,
                 progressive_backoff, unlock_paths[], notify_user, notify_admin
  throttle:      per_identity, per_ip, per_tenant, per_device
  mfa:           availability, enforced_for_risk_classes[], enforced_for_roles[],
                 methods_allowed[], grace_period_days, recovery_code_count,
                 remember_device_days
  session:       idle_timeout_minutes, absolute_lifetime_minutes,
                 remember_me_enabled, remember_me_days,
                 concurrent_session_limit, on_limit(deny_new | evict_oldest),
                 reauth_seconds_for_privileged
  tokens:        reset_ttl_minutes, invite_ttl_hours, welcome_ttl_hours,
                 portal_default_ttl_hours, single_use, resend_cooldown_seconds
  sso:           mode(off | optional | required), idp_ref, jit_provisioning,
                 attribute_map, allowed_domains[], break_glass_accounts[]
```

**R-XXII-180** Every value above is configuration with a **platform floor**. A
tenant may set `min_length` to 14; it may not set it to 4. The floor table is a
platform constant, not tenant-editable. *Origin:* SmartPRS 2's password rule is
the literal `min:8` in five files and `min:6` for platform staff in one
(`AuthController.php:401, 444, 527`; `UserController.php:267`;
`InstallController.php:178`; `StaffController.php:43, 64`; G BR-AUTH-010/011).

**R-XXII-181** The **platform-account floor is strictly stricter** than the
tenant floor on every dimension: length, MFA, session lifetime, lockout and
re-auth. *Origin:* SmartPRS 2 inverts this — the most privileged accounts on the
installation carry the weakest rule in the product.

**R-XXII-182** Changing an auth policy is a `high_risk` publication requiring
maker-checker, with an impact preview stating how many accounts become
non-compliant and what happens to them at next sign-in.

### XXII.7.2 Password policy

**R-XXII-183** Password strength MUST be evaluated by **length plus a breach-list
check plus an identity-term check**, not by character-class arithmetic alone.
Character classes remain configurable because some customers' own policies
mandate them, but the platform default composition is: a substantial minimum
length, no maximum below a generous bound, no composition requirement, and a
mandatory breach check. Truncation, silent normalisation and character-set
restriction are prohibited.

**R-XXII-184** **Breach checking** is mandatory by default, performed against a
local corpus or a k-anonymity range API, and MUST fail **closed on policy** and
**open on availability** — that is, if the corpus is unavailable the password is
**rejected with a retry-later message** at *set* time (never silently accepted),
while sign-in with an existing password is unaffected. This is the one place a
control degrades toward availability, and it degrades toward refusing a change,
not toward accepting a weak one.

**R-XXII-185** **History**: the last `history_depth` password hashes are retained
per user and a new password matching any of them is refused. `min_age_hours`
prevents cycling through history to return to a favourite.

**R-XXII-186** **Expiry** is off by default and configurable on. Where enabled,
the user is warned from `expiry_warning_days`, and an expired password forces a
change at sign-in without granting a session for anything else.

**R-XXII-187** A password change or reset MUST invalidate every other session for
that user (except, optionally, the current one) and every outstanding reset and
invite token. *Origin:* SmartPRS 2 does the token half correctly
(`AuthController.php:428–430`; `UserController.php:280–287`) and the session half
not at all.

**R-XXII-188** Password hashing is Argon2id (or bcrypt at the platform cost where
Argon2id is unavailable in a supported deployment), with parameters as a platform
constant and a **rehash-on-verify** path when the parameters change. *Origin:*
SmartPRS 2's hashing is correct (bcrypt cost 12); it is retained as a mechanism
(G §9).

### XXII.7.3 Lockout and throttling

**R-XXII-189** Account lockout MUST exist and MUST be keyed on the **account**,
not only the source address: `failure_threshold` failures within `window_minutes`
locks for `lock_duration_minutes`, with optional progressive backoff. *Origin:*
SmartPRS 2 has no lockout of any kind — no `failed_login`, no `locked_until`, no
`Failed` listener (G NEW-SEC-110) — and throttles only 5/min per IP, which an
attacker with many addresses does not encounter (`routes/web.php:208`).

**R-XXII-190** Throttling MUST be applied independently on identity, source
address, tenant and device, with per-key limits. Reaching any limit produces the
same uniform response.

**R-XXII-191** A lock MUST be clearable by: expiry, an administrator holding
`access.user.disable` (audited), or a verified self-service unlock through the
registered email or MFA factor where the policy permits it. Every lock and
unlock is audited and, by default, notified to the account holder.

**R-XXII-192** Lockout MUST NOT be usable as a denial-of-service against a known
account. Where `failure_threshold` is reached from a single source, the source is
blocked before the account is locked; account lockout applies to distributed
failure. This ordering is configuration with a secure default.

**R-XXII-193** All authentication failure responses MUST be **uniform in wording,
in status code and in timing**. There is no account-existence oracle and no
account-status oracle. *Origin:* SmartPRS 2's forgot-password path is correct
(`AuthController.php:383` — same message either way) while its **login** path
performs a second lookup and returns a distinct message for a disabled account
(`:292–295`; G SEC-13, BR-AUTH-019).

### XXII.7.4 Multi-factor authentication

**R-XXII-194** MFA MUST be available to every user and **mandatory for every
holder of a `privileged` or `platform` risk-class permission**. *(Spine S13.)*
Enforcement is by **permission risk class**, not by role name, so a custom role
that happens to include `payroll.run.approve` is automatically in scope. A role
marked `is_privileged` (R-XXII-026) cannot be assigned to a user who has not
enrolled, beyond a configurable grace period, after which the assignment is
suspended (not deleted) and the user is notified.

**R-XXII-195** Supported methods at v3.0:

| Method | Status | Notes |
|---|---|---|
| **TOTP** (RFC 6238) | required; the default | secret encrypted at rest; clock skew window a platform constant; replay of a used code within its window is rejected |
| **Email OTP** | required | short TTL, single use, rate-limited, delivered through the credential-class mail path (inline, not queued — SmartPRS 2 already learned this from a production incident, L BR-SCH-024) |
| **Recovery codes** | required | generated at enrolment, **hashed** at rest, shown once, single-use, count configurable, regeneration audited |
| **WebAuthn / passkeys** | **SMARTPRS 3 ARCHITECTURE RECOMMENDATION** — deferred to v3.1 | the enrolment and factor model is built to accommodate it without schema change |
| **SMS OTP** | **not offered** | SmartPRS 2 has no SMS provider adapter at all (N §1.7.2 module 27), and SMS is the weakest widely-deployed factor; if a customer contract requires it, it is a v3.1 decision (D-XXII-07) |

**R-XXII-196** MFA enrolment, reset and factor removal are audited, notified to
the account holder, and — for a privileged account — require a second
administrator's approval through maker-checker. An administrator resetting
another user's MFA MUST satisfy R-XXII-034.

**R-XXII-197** "Remember this device" is configurable, bounded by
`remember_device_days`, bound to a device identifier plus the user, revocable
individually and in bulk, and **never available for `platform` risk-class
operations**.

**R-XXII-198** An operation whose registry entry declares `assurance = mfa` MUST
verify that the current session satisfied MFA; `assurance = reauth(n)` MUST
verify a credential re-presentation within the last `n` seconds. A session that
does not satisfy the requirement is not upgraded silently — the API returns
`MFA_REQUIRED` or `REAUTH_REQUIRED` and the client prompts. *Origin:* SmartPRS 2
has `AUTH_PASSWORD_TIMEOUT` configured at 10,800 s and **no route uses
`password.confirm`** — dead configuration that looks live (G D-22).

### XXII.7.5 SSO readiness

**R-XXII-199** SmartPRS 3 MUST ship **SAML 2.0** and **OIDC** service-provider
support as a first-class, per-tenant authentication mode, not as a plug-in.

**R-XXII-200** SSO configuration is per tenant and covers: IdP metadata, signing
and encryption certificates with expiry monitoring, an attribute map to
SmartPRS's identity fields, allowed email domains, JIT provisioning on/off, and
the default role+scope assignment for a JIT-provisioned user (which MUST be
explicitly chosen and MUST NOT default to anything privileged).

**R-XXII-201** SSO assertions MUST NOT carry authorisation. Group or role claims
from an IdP MAY be **mapped** to SmartPRS role assignments through an explicit,
audited mapping table; they are never trusted as permissions. An unmapped claim
grants nothing.

**R-XXII-202** Where `sso.mode = required`, local password authentication is
disabled for that tenant except for a declared, minimal set of **break-glass
accounts**, which MUST hold MFA, MUST be individually named in configuration,
MUST be audited on every use, and MUST notify a configured recipient on every
sign-in.

**R-XXII-203** SCIM provisioning and de-provisioning is a **SMARTPRS 3
ARCHITECTURE RECOMMENDATION** for v3.1; the identity schema is built so it can be
added without migration. Until then, de-provisioning is by the tenant's own user
administration and by the assignment expiry in R-XXII-032.

**R-XXII-204** Certificate expiry, metadata drift and assertion failures MUST
surface on the operations dashboard with alerting, because an SSO failure locks
out an entire tenant.

### XXII.7.6 Sessions

**R-XXII-205** Sessions MUST have both an **idle timeout** and an **absolute
maximum lifetime**. The absolute lifetime is not optional and is not
`SESSION_LIFETIME` by another name. *Origin:* SmartPRS 2 has a 120-minute idle
timeout and **no absolute cap at all**, with remember-me at the framework's
five-year default and no `rememberDuration` call anywhere
(`config/session.php:35, 37`; G NEW-SEC-109).

**R-XXII-206** Session cookies MUST be `Secure`, `HttpOnly`, `SameSite=Lax`
(strict where the flows permit), host-prefixed, and the value MUST be
regenerated on privilege change and on sign-in. `Secure` has **no "off" setting
in any environment served over HTTP other than a declared local development
loopback**. *Origin:* `config/session.php:173` has no default for
`SESSION_SECURE_COOKIE` and `.env.example` does not set it, so a deployment built
from the shipped example sends its session cookie over plain HTTP
(G NEW-SEC-107).

**R-XXII-207** Session payloads MUST be encrypted at rest and stored in a
tenant-partitioned store. *Origin:* `config/session.php:50` defaults
`SESSION_ENCRYPT` to false (G NEW-SEC-108).

**R-XXII-208** Concurrent sessions are configurable per tenant with a declared
behaviour on the limit (`deny_new` or `evict_oldest`), and a user MUST be able to
see and terminate their own sessions.

**R-XXII-209** A user disabled, locked, role-revoked or scope-narrowed mid-session
MUST be re-evaluated on their **next request**, not on their next sign-in. The
permission set is resolved per request from the assignment tables; it is not
cached in the session. *Origin:* SmartPRS 2 gets the disable case right
(`EnsureSubscriptionActive.php:44–59`; G BR-AUTH-020) and the permission case
wrong — the client permission matrix is injected at boot and lives for the life of
the page (K §7.4).

**R-XXII-210** "Remember me", where enabled, MUST be a **separate, bounded,
individually revocable** token, MUST NOT satisfy an `assurance` requirement, and
MUST NOT extend the absolute lifetime for privileged operations.

### XXII.7.7 Device history and login audit

**R-XXII-211** Every sign-in attempt — success and failure — MUST record: time,
outcome, reason code, source address, user agent, device identifier where
present, authentication method, MFA method used, tenant, and the resulting
session id. This is a first-class table, not a log file.

**R-XXII-212** The user MUST be able to see their own login and device history,
and MUST be notified on: a sign-in from a new device, a password change, an MFA
change, a lockout, and an administrator action on their account.

**R-XXII-213** Anomaly signals — impossible travel, a burst of failures across
many accounts from one source, a first sign-in outside the tenant's declared
hours, an unusual export volume — MUST raise events (§XXII.12). Whether they
*block* is configuration; whether they are *recorded* is not.

**R-XXII-214** Mobile device registration MUST be authenticated and MUST bind to
a tenant through a credential, and device approval state MUST be enforced
server-side on every request. *Origin:* SmartPRS 2's `/api/mobile/register`
is unauthenticated (`routes/web.php:79–87`) and the mobile device status is not
enforced server-side (N §1.7.2, mobile row).

### XXII.7.8 Token handling

**R-XXII-215** Every credential-bearing artefact — password reset, invitation,
tenant-admin welcome, first-password link, MFA enrolment link, offer, NDA,
self-onboarding, transfer acknowledgement, agent verification, agent earnings,
quotation, licence invoice, export artefact, signed document URL — MUST be:
hashed at rest, single-use where its purpose allows, expiring on a **configurable
TTL**, individually revocable, rate-limited, and bound to its purpose so a token
minted for one flow cannot be redeemed in another.

**R-XXII-216** TTLs are configuration with platform maxima. *Origin:* SmartPRS 2
has three literals for three flows — 1 hour reset (`AuthController.php:353–360`),
48 hours invite (`UserController.php:331`), 72 hours welcome
(`SaasController.php:548`) — and **no expiry at all** on the seven public portal
token types (G SEC-17).

**R-XXII-217** Token entropy MUST be at least 128 bits from a CSPRNG. *Origin:*
SmartPRS 2's entropy is adequate (`Str::random(48)`, `Str::random(40)`); the
defect is storage and lifetime, not generation (G §4.2, §7.6).

**R-XXII-218** Consuming a token MUST invalidate every other outstanding token of
the same purpose for that subject, and MUST be recorded. *Origin:* SmartPRS 2
already does this for password resets (`AuthController.php:428–430`) — the
pattern is correct and is generalised.

**R-XXII-219** Token issuance MUST be rate-limited per subject with a cooldown,
and the response MUST be identical whether or not the subject exists. *Origin:*
SmartPRS 2's 2-minute resend suppression (`AuthController.php:349–352`) is the
right idea as a literal; it becomes `tokens.resend_cooldown_seconds`.

### XXII.7.9 First sign-in and provisioning

**R-XXII-220** A provisioned account MUST be created **without a password** and
in state `pending_activation`. Activation is a single-use, expiring set-password
link delivered to the verified address. There is no temporary password, in any
channel. *Origin:* SmartPRS 2 emails a cleartext temporary password on the paid
signup path (`SaasController.php:185, 558–575`; G SEC-16) and, with
`SMARTPRS_WA_SEND_PASSWORD` defaulting to **true**, also sends it over WhatsApp
and persists it forever in `wa_log.body_values` (`SignupController.php:413–425`;
L SEC-L-03, OQ-L-14).

**R-XXII-221** An administrator MUST NOT be able to **set** another user's
password. The only administrative action is *issue a set-password link*, which
requires R-XXII-034 and is audited. *Origin:* SmartPRS 2 lets any `canManage`
holder set any tenant user's password directly, with `['required','string','min:8']`
and **no confirmation field**, unaudited (`UserController.php:262–278`;
G BR-AUTH-012, SEC-07).

**R-XXII-222** Activation MUST require the new password to satisfy the policy in
force at that moment, and MUST enrol MFA in the same flow where the account's
assignments make it mandatory.

**R-XXII-223** The installer MUST create the first administrator **interactively**,
with no default and no command-line password argument, and MUST refuse to
complete otherwise. *(Spine S18.)* *Origin:* `ClientProvision.php:29, 39` takes
the on-prem administrator password as a command-line argument, so it lands in
shell history and the process table, while `$this->secret()` exists and is not
used (L SEC-L-12).

### XXII.7.10 Recovery

**R-XXII-224** Password reset MUST NOT disclose account existence, MUST be
rate-limited per address and per source, MUST expire, MUST be single-use, and
MUST invalidate sessions on success.

**R-XXII-225** MFA recovery MUST offer recovery codes and an administrator-assisted
path that satisfies R-XXII-034 and R-XXII-196. There is no "email a bypass"
path.

**R-XXII-226** A tenant MUST NOT be able to lock itself out permanently: the
platform holds an audited, consented, time-boxed recovery procedure operating
through §XXII.5.5, and it is documented in the customer contract rather than
improvised during an incident.

### XXII.7.11 No default credential — the absolute rule

**R-XXII-227** **No seeder, installer, migration, console command, scheduled
task, container image, packaging script or release artefact may create, restore
or reset an account with a credential known outside the installation — in any
environment, including development.** *(Spine S13; restates R-XXII-005 as the
authentication-side obligation.)*

**R-XXII-228** Demonstration and sample data MUST be a **separately deployed
artefact**, not a command inside the product. There is no `demo:reset` in a
customer release. *Origin:* SmartPRS 2 schedules `demo:reset` unconditionally at
03:30 with no `->when()`, no `APP_ENV` check and no `SMARTPRS_DEMO_DATA`
consultation; it creates `demo-admin@smartprs.in` with the Spatie role `admin`
and the password `smartprs-demo`, prints the password to stdout, and — because
the lookup at `DemoReset.php:106` is unscoped by tenant — **takes over** any
pre-existing account with those addresses, reassigning `tenant_id`
(`routes/console.php:208–211`; `DemoReset.php:26, 35, 54–65, 101–123, 152`;
L SEC-L-01). Nightly re-creation defeats deletion; nightly password reset defeats
a password change.

**R-XXII-229** Any development-only account creation path MUST require an
operator-supplied secret **and** MUST refuse to run when `APP_ENV=production`
**and** MUST refuse to run against a database containing any tenant not marked
`is_demo`. All three conditions, not any one of them. *Origin:*
`OnPremWorkspaceSeeder.php:74–92` creates `admin@smartprs.local` with
`Hash::make('password')` at `:78` on `tenants` ordered by id — an arbitrary,
possibly live tenant — with none of the three guards, and `DatabaseSeeder.php:19–30`
never calls it, so the `SMARTPRS_DEMO_DATA` switch that appears to protect it is
not consulted (G SEC-20).

**R-XXII-230** The build MUST **fail** on a credential literal in any artefact
that ships. The check greps the release tree for password-shaped assignments
outside `env()`/`config()`/test fixtures, and additionally fails on any literal
present in the platform's own historical-credential deny-list (`password`,
`smartprs-demo`, `ametecs`, and any value ever shipped). *Origin:* three live
credential literals in SmartPRS 2's committed source — `'password'` in
`OnPremWorkspaceSeeder.php:78` and `DemoCompanySeeder.php:69`, `smartprs-demo` in
`DemoReset.php:35` — plus one dead one, `'ametecs'` at `config/smartprs.php:32`
(G §7.6, D-24).

**R-XXII-231** Release documentation — README, installation guide, quick-start —
MUST NOT contain a credential, and the documentation build MUST fail if it does.
*Origin:* SmartPRS 2's `README.md` describes seeded logins with the password
`password`; it is stale by roughly 190 revisions and is the first document an
operator reads (L DEF-L-28, DOC-L-10).

**R-XXII-232** The installer's final health check MUST verify: no account exists
whose password hash matches the deny-list, the private storage root is not
HTTP-reachable, the scheduler and queue worker are running, HTTPS is enforced,
and session cookies are `Secure`. A failure blocks completion. *(Spine S18.)*

---

## XXII.8 API and machine identity

SmartPRS 2's `ApiKeyAuth` + `ApiKeys` pair is the **single best-engineered
security component in the codebase** (G §9): the secret is hashed at rest with
SHA-256, compared with `hash_equals`, the prefix is a lookup handle rather than a
credential, the scope is checked, the tenant is taken from the key and never from
the body, and an ingest key with a null tenant is refused
(`ApiKeys.php:32–63`; `ApiKeyAuth.php:67–69, 103–114`). It is retained as the
mechanism and generalised. `SmarteptSignature` — HMAC over the raw body with a
uniform failure response and enforced tenant binding — is retained likewise.

### XXII.8.1 Credential kinds

| Kind | Identity | Use | Scoping |
|---|---|---|---|
| **API key** | a tenant-owned machine principal | server-to-server ingestion and integration | permission set + data scope, exactly as a human |
| **OAuth2 client credentials** | a registered client, per tenant | partner and customer integrations | granted scopes map **one-to-one** onto permission codes |
| **Webhook signing secret** | an endpoint | outbound delivery authentication | endpoint's tenant only |
| **Device credential** | a biometric terminal or bridge instance | punch ingestion | `(tenant, serial)`; ingest permission only |
| **System actor** | an internal named principal | jobs and scheduled work | assignment like any user (R-XXII-098) |

**R-XXII-240** A machine principal MUST be a first-class actor with **role
assignments and a data scope**, evaluated by the same pipeline as a human. There
is no "API bypasses authorisation" path and no permission reachable only by a
machine.

**R-XXII-241** OAuth2 scopes MUST **be** permission codes, not a parallel
vocabulary. A token's effective permission set is the intersection of the
client's assignment and the granted scopes. A scope naming a permission the
client's assignment does not carry is refused at token issue. *Origin:* SmartPRS
2 installs Sanctum, migrates `personal_access_tokens`, and **no route uses
`auth:sanctum`** — a dead dependency and a dead table (G D-21).

**R-XXII-242** Scopes MUST actually gate. *(Spine S17.)* The generated route ×
credential matrix (§XXII.14.4) exercises every operation against a credential
holding a deliberately insufficient scope and asserts denial.

### XXII.8.2 Issue, rotation and revocation

**R-XXII-243** A credential secret is displayed **once, at issue**, and is stored
hashed. There is no retrieval path. *(Already true in SmartPRS 2 and retained.)*

**R-XXII-244** Every credential carries: a tenant, an owner, a purpose, an
expiry (mandatory — a non-expiring credential cannot be issued), a permission
assignment, an IP allow-list where the tenant configures one, a last-used
timestamp, and a revocation state.

**R-XXII-245** Rotation MUST support an **overlap window**: a new secret is
issued while the old remains valid for a bounded, configurable period; both are
individually visible in the usage log so the operator can confirm the consumer
has migrated before the old is retired. Rotation without overlap is also
available for an incident. Both are audited. *Rationale:* rotation that requires
simultaneous cut-over is rotation that never happens, which is how a credential
reaches its fifth year.

**R-XXII-246** Expiry MUST warn: events at 30, 7 and 1 days before expiry
(configurable), to the credential owner and the tenant's operations recipient. An
expired credential fails closed with a distinct reason code.

**R-XXII-247** Revocation is immediate and MUST invalidate cached authorisation
decisions within a bounded, declared interval.

### XXII.8.3 Transport and rate limiting

**R-XXII-248** **Any endpoint that accepts a credential MUST refuse plaintext
HTTP.** The request is rejected before the credential is read, the credential is
treated as **compromised** and flagged for rotation, and an event is raised.
`Strict-Transport-Security` is set on every response. This applies identically to
SaaS, private cloud and on-premise. *(Spine S18 — parity.)* *Origin:* SmartPRS 2
has no `Secure` cookie default (G NEW-SEC-107) and no transport requirement on
its credentialled surfaces at all.

**R-XXII-249** Rate limiting on machine surfaces MUST be **per credential**, not
per IP, with per-credential quotas that a tenant administrator can see and an
Ametecs operator can adjust. Burst and sustained limits are separate.
*Origin:* the `/iclock/*` receiver is throttled at `1000,1` per IP with no
credential at all (`routes/web.php:92–105`; G §7.5).

**R-XXII-250** Every unsafe method MUST accept an **idempotency key**, and a
replay MUST return the original result rather than repeating the effect.
*(Spine S17.)*

**R-XXII-251** Webhooks MUST be signed with HMAC over the raw body **plus a
timestamp and nonce**, with a freshness window and replay rejection, a delivery
log, retry with backoff and a dead-letter queue. *(Spine S17.)* *Origin:*
`POST /webhooks/interakt` is CSRF-exempt with **no stated compensating control**,
unlike every sibling route (G §7.5) — under R-XXII-076 that is now a build
failure, not a code review finding.

**R-XXII-252** Connector egress MUST be restricted by scheme and host allow-list,
blocking loopback, link-local and private ranges unless a LAN connector
explicitly permits them, and MUST NOT follow redirects across hosts.
*(Spine S17.)*

**R-XXII-253** Device ingestion MUST carry a credential. The
`ApiKeyAuth`/`SmarteptSignature` pattern is extended to the device-push path,
which today accepts a caller-chosen serial number as its only identity
(G §10 item 28). Where a device genuinely cannot present a credential, the
supported path is the Smart Biometric Bridge, which can — and the direct path is
disabled by default, enabled per tenant with an explicit acceptance of the
residual risk, and restricted by an IP allow-list.

> **PRODUCT OWNER DECISION REQUIRED — D-XXII-08** (§XXII.19): whether an
> unauthenticated `/iclock`-equivalent must remain available for legacy terminals.
> G §11 Q-14 asks the same question of the shipped product.

---

## XXII.9 Configuration surface

Tested against the brief's Section 127 standard — *"3 late arrivals over 10
minutes = half day" must not require code*. The equivalent tests for this part
are: *"Branch Managers may approve leave for their branch but not payroll"*,
*"Accounts confirms commission money but sees no salary"*, and *"passwords expire
every 90 days for this customer and never for that one"* — each must be a
configuration change, and each is.

| # | What a tenant administrator can change | Where | Approval |
|---:|---|---|---|
| 1 | Create, name and describe a custom role | Access Studio → Roles | maker-checker |
| 2 | Clone a system role and edit the clone | Access Studio → Roles | maker-checker |
| 3 | Grant or remove any permission on a custom role | Access Studio → Roles | maker-checker |
| 4 | Retire a role (never delete) | Access Studio → Roles | maker-checker |
| 5 | Create a named data scope of any scope type | Access Studio → Scopes | maker-checker if widening |
| 6 | Set `reporting_tree` depth and `include_self` | Access Studio → Scopes | as above |
| 7 | Choose units for a unit scope, with `include_descendants` per unit | Access Studio → Scopes | as above |
| 8 | Build a `dimension_filter` scope from the S3.1 dimensions | Access Studio → Scopes | as above |
| 9 | Assign a user several role+scope pairs | Users → Access | audited; R-XXII-033 applies |
| 10 | Time-box any assignment | Users → Access | audited |
| 11 | Password minimum length, classes, disallowed patterns | Security → Authentication | maker-checker |
| 12 | Breach-check on/off, dictionary check, identity-term check | Security → Authentication | maker-checker |
| 13 | Password history depth, minimum age, maximum age, warning days | Security → Authentication | maker-checker |
| 14 | Lockout threshold, window, duration, backoff, unlock paths, notifications | Security → Authentication | maker-checker |
| 15 | Throttle limits per identity / source / tenant / device | Security → Authentication | maker-checker |
| 16 | MFA: availability, methods, grace period, remember-device days, recovery-code count | Security → MFA | maker-checker |
| 17 | Additional roles for which MFA is mandatory (beyond the automatic privileged set) | Security → MFA | maker-checker |
| 18 | Session idle timeout, absolute lifetime, remember-me, concurrent limit and behaviour | Security → Sessions | maker-checker |
| 19 | Re-auth window for privileged operations | Security → Sessions | maker-checker |
| 20 | Token TTLs: reset, invite, welcome, portal, export artefact | Security → Tokens | maker-checker |
| 21 | Portal token revocation, individually or in bulk | Security → Tokens | audited |
| 22 | SSO mode, IdP metadata, attribute map, allowed domains, JIT default assignment | Security → SSO | maker-checker |
| 23 | Break-glass account list | Security → SSO | maker-checker |
| 24 | IdP group → role mapping | Security → SSO | maker-checker |
| 25 | Vendor-access policy: consent mode, notification recipients, permitted support permission set | Security → Vendor Access | maker-checker |
| 26 | Terminate a live vendor impersonation session | Security → Vendor Access | audited |
| 27 | Field PII class (raise freely; lower by maker-checker) | Schema Studio → Fields | R-XXII-151 |
| 28 | Field masking rule, and per-surface/per-role overrides | Schema Studio → Surfaces | maker-checker |
| 29 | Export profiles: columns, max PII class, required permissions, approval, watermark, retention | Reports → Export Profiles | maker-checker where PII class > identifying |
| 30 | Upload allow-lists, size caps, per-employee and per-tenant quotas, per purpose | Documents → Policy | audited |
| 31 | Signed-URL TTL per purpose (downward from the platform maximum) | Documents → Policy | audited |
| 32 | Retention class assignment per entity and document purpose | Governance → Retention | maker-checker |
| 33 | Audit retention window and export schedule | Governance → Audit | maker-checker |
| 34 | Anomaly signals: which raise alerts, which block, and to whom | Security → Monitoring | audited |
| 35 | API credentials: issue, scope, IP allow-list, expiry, rotate with overlap, revoke | Integrations → Credentials | audited; MFA required |
| 36 | Webhook endpoints, events, secret rotation | Integrations → Webhooks | audited |
| 37 | Direct-device ingestion on/off and its IP allow-list | Integrations → Devices | maker-checker |
| 38 | Notification recipients for security events | Security → Monitoring | audited |

**Not tenant-configurable, deliberately:** the permission catalogue
(R-XXII-010), the verb vocabulary (R-XXII-012), the scope-type set (R-XXII-040),
scope precedence weights (spine S3.2), the platform policy floors (R-XXII-180),
the platform maxima on token and signed-URL TTLs, the fail-closed semantics
(§XXII.4.7), tenancy itself (§XXII.5.1), the credential deny-list (R-XXII-230),
and the plaintext-HTTP refusal (R-XXII-248). Each of these, if a customer could
change it, would let a customer disable a control the platform's own guarantees
depend on.

---

## XXII.10 Data model

Conventions per spine S16 and Part XXVIII: `snake_case`, plural tables, `_id`
foreign keys, `_at` instants (UTC `DATETIME(3)`), `_on` dates, `is_`/`has_`
booleans, `BINARY(16)` UUIDv7 public ids, no database `ENUM`, foreign keys on
every relationship with declared `ON DELETE`, and `tenant_id` leading every
business unique index.

Two table classes appear below. **Platform** tables are catalogue data owned by
Ametecs, shipped by migration, read-only to tenants, and explicitly declared
non-tenant-owned in the schema registry (R-XXII-120). **Tenant** tables carry
`tenant_id NOT NULL`.

### XXII.10.1 `permissions` — platform

Purpose: the catalogue of functional capabilities (§XXII.2.2) — Ametecs-authored
rows seeded by migration, plus the rows the platform mints for a published
tenant artefact in a reserved generative namespace (R-XXII-010a). The two are
distinguished by `origin_code`; nothing else about the row differs.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | SMALLINT UNSIGNED | NN | auto | PK |
| `code` | VARCHAR(120) | NN | — | **U(`code`)** · `module.resource[.qualifier].verb` |
| `module_code` | VARCHAR(40) | NN | — | FK→ `modules(code)` RESTRICT |
| `resource_code` | VARCHAR(60) | NN | — | |
| `qualifier_code` | VARCHAR(40) | Y | NULL | |
| `verb_code` | VARCHAR(40) | NN | — | FK→ `permission_verbs(code)` RESTRICT |
| `name` | VARCHAR(120) | NN | — | short label for the role editor |
| `business_description` | VARCHAR(500) | NN | — | mandatory (R-XXII-011) |
| `risk_class_code` | VARCHAR(20) | NN | `routine` | FK→ `status_values` domain `permission_risk_class` |
| `is_scoped` | TINYINT(1) | NN | 1 | 0 ⇒ tenant-global; drives R-XXII-076 parity |
| `default_scope_type_code` | VARCHAR(40) | Y | NULL | e.g. `self` for ESS permissions |
| `requires_mfa` | TINYINT(1) | NN | 0 | |
| `requires_reauth_seconds` | INT UNSIGNED | Y | NULL | |
| `is_platform_only` | TINYINT(1) | NN | 0 | assignable only inside the platform tenant |
| `origin_code` | VARCHAR(20) | NN | `authored` | `authored` ⇒ Ametecs, seeded by migration · `generated` ⇒ minted from a published artefact in a reserved namespace (R-XXII-010a) |
| `source_config_version_id` | BIGINT UNSIGNED | Y | NULL | FK→ `config_versions(id)` RESTRICT; NN where `origin_code = generated`, NULL otherwise (CHECK) |
| `introduced_in_release` | VARCHAR(20) | NN | — | drives the upgrade reconciliation report; `—` for generated rows |
| `deprecated_in_release` | VARCHAR(20) | Y | NULL | deprecated, never deleted |
| `created_at` `updated_at` | DATETIME(3) | NN | — | |

Indexes: `IX(module_code, resource_code)`, `IX(risk_class_code)`.
Tenant scope: **platform** · History: release-versioned · Soft delete: never;
deprecation only · Audit: release migration · PII: none.

### XXII.10.2 `permission_implications` — platform

Purpose: the `implies[]` edges (R-XXII-037).

| Column | Type | Null | Notes |
|---|---|---|---|
| `permission_id` | SMALLINT UNSIGNED | NN | FK RESTRICT · **PK(`permission_id`,`implied_permission_id`)** |
| `implied_permission_id` | SMALLINT UNSIGNED | NN | FK RESTRICT |

A migration-time check asserts the graph is acyclic. Tenant scope: platform.

### XXII.10.3 `roles`

Purpose: a tenant-owned named bundle; the artefact header. Versions live in
`config_versions` per spine S4.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | auto | PK |
| `uuid` | BINARY(16) | NN | — | **U(`tenant_id`,`uuid`)** |
| `tenant_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT · CHECK `tenant_id > 0` |
| `role_key` | VARCHAR(60) | NN | — | **U(`tenant_id`,`role_key`)** |
| `name` | VARCHAR(120) | NN | — | |
| `description` | VARCHAR(500) | NN | — | mandatory |
| `origin_code` | VARCHAR(20) | NN | `custom` | `system` \| `custom` \| `cloned_system` |
| `system_role_key` | VARCHAR(60) | Y | NULL | the system role this was cloned from |
| `is_privileged` | TINYINT(1) | NN | 0 | derived at publish from the permission set (R-XXII-026) |
| `is_assignable` | TINYINT(1) | NN | 1 | 0 while no published version exists |
| `retired_on` | DATE | Y | NULL | retirement, never deletion (R-XXII-027) |
| `created_by_user_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `created_at` `updated_at` | DATETIME(3) | NN | — | |
| `row_version` | INT UNSIGNED | NN | 1 | optimistic locking |

Indexes: `IX(tenant_id, origin_code)`, `IX(tenant_id, is_assignable, retired_on)`.
Tenant scope: tenant · History: `config_versions` · Soft delete: **no** (retire)
· Audit: full, field-level · PII: none.

### XXII.10.4 `role_permissions`

Purpose: the grants declared on **one published version** of a role.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | PK |
| `tenant_id` | BIGINT UNSIGNED | NN | FK RESTRICT |
| `role_id` | BIGINT UNSIGNED | NN | composite FK `(tenant_id, role_id)` RESTRICT (R-XXII-114) |
| `config_version_id` | BIGINT UNSIGNED | NN | FK RESTRICT — the role version this grant belongs to |
| `permission_id` | SMALLINT UNSIGNED | NN | FK RESTRICT |
| `granted_by_user_id` | BIGINT UNSIGNED | NN | FK RESTRICT |
| `created_at` | DATETIME(3) | NN | |

Unique: **U(`tenant_id`,`config_version_id`,`permission_id`)**.
Indexes: `IX(tenant_id, role_id, config_version_id)`.
Tenant scope: tenant · History: immutable per version · Soft delete: no ·
Audit: full · PII: none.

### XXII.10.5 `role_permissions_effective`

Purpose: the materialised closure of R-XXII-037, written at publish, read at
every request. The reason authorisation is one indexed lookup rather than a graph
walk.

| Column | Type | Null | Notes |
|---|---|---|---|
| `tenant_id` | BIGINT UNSIGNED | NN | **PK(`tenant_id`,`config_version_id`,`permission_id`)** |
| `config_version_id` | BIGINT UNSIGNED | NN | FK RESTRICT |
| `permission_id` | SMALLINT UNSIGNED | NN | FK RESTRICT |
| `is_direct` | TINYINT(1) | NN | 0 ⇒ arrived through `implies` |
| `source_permission_id` | SMALLINT UNSIGNED | Y | which direct grant implied it — rendered in §XXII.13 |

Rebuilt transactionally at publish; never written by any other path. A checksum
over the set is stored on the config version, and a startup self-check asserts
the materialisation matches the declared grants — a mismatch is a fail-closed
condition (R-XXII-094).

### XXII.10.6 `access_scope_types` — platform

| Column | Type | Null | Notes |
|---|---|---|---|
| `code` | VARCHAR(40) | NN | PK — `self`, `direct_reports`, `reporting_tree`, `team`, `department`, `branch`, `location`, `company`, `legal_entity`, `org_units`, `dimension_filter`, `tenant`, `platform` |
| `name` | VARCHAR(80) | NN | |
| `description` | VARCHAR(400) | NN | |
| `requires_units` | TINYINT(1) | NN | |
| `supports_depth` | TINYINT(1) | NN | |
| `supports_include_self` | TINYINT(1) | NN | |
| `supports_dimension_scope` | TINYINT(1) | NN | |
| `is_platform_only` | TINYINT(1) | NN | true for `platform` |
| `resolver_class` | VARCHAR(160) | NN | the `ScopeResolver` implementation (R-XXII-041) |
| `sort_order` | SMALLINT | NN | |

### XXII.10.7 `access_scopes`

Purpose: a named, reusable, versioned data scope (§XXII.3.3).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | auto | PK |
| `uuid` | BINARY(16) | NN | — | **U(`tenant_id`,`uuid`)** |
| `tenant_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT · CHECK > 0 |
| `scope_key` | VARCHAR(60) | NN | — | **U(`tenant_id`,`scope_key`)** |
| `name` | VARCHAR(120) | NN | — | |
| `description` | VARCHAR(500) | NN | — | |
| `scope_type_code` | VARCHAR(40) | NN | — | FK→ `access_scope_types(code)` RESTRICT |
| `depth` | TINYINT UNSIGNED | Y | NULL | only when `supports_depth`; bounded by the platform maximum |
| `include_self` | TINYINT(1) | NN | 0 | |
| `dimension_scope_json` | JSON | Y | NULL | spine S3.1 Scope; only when `supports_dimension_scope` |
| `is_system` | TINYINT(1) | NN | 0 | `SELF`, `TENANT`, `PLATFORM` |
| `retired_on` | DATE | Y | NULL | |
| `created_by_user_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `created_at` `updated_at` | DATETIME(3) | NN | — | |
| `row_version` | INT UNSIGNED | NN | 1 | |

A check constraint asserts the type/parameter parity (`requires_units` ⇒ at least
one row in `access_scope_units`; `depth` NULL unless `supports_depth`), enforced
at publish and re-asserted by a nightly integrity job.
Tenant scope: tenant · History: `config_versions` · Soft delete: no (retire) ·
Audit: full · PII: none.

### XXII.10.8 `access_scope_units`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | PK |
| `tenant_id` | BIGINT UNSIGNED | NN | FK RESTRICT |
| `access_scope_id` | BIGINT UNSIGNED | NN | composite FK `(tenant_id, access_scope_id)` RESTRICT |
| `config_version_id` | BIGINT UNSIGNED | NN | FK RESTRICT |
| `org_unit_id` | BIGINT UNSIGNED | NN | composite FK `(tenant_id, org_unit_id)` RESTRICT |
| `include_descendants` | TINYINT(1) | NN | explicit, never inferred (R-XXII-046) |

Unique: **U(`tenant_id`,`config_version_id`,`org_unit_id`)**.
Indexes: `IX(tenant_id, org_unit_id)` — "which scopes reach this unit?", used by
the impact preview.

### XXII.10.9 `role_assignments`

Purpose: the `(user, role, scope)` triple, effective-dated (§XXII.2.4). **The
central table of this part.**

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | auto | PK |
| `uuid` | BINARY(16) | NN | — | **U(`tenant_id`,`uuid`)** |
| `tenant_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT · CHECK > 0 |
| `user_id` | BIGINT UNSIGNED | NN | — | composite FK `(tenant_id, user_id)` RESTRICT |
| `role_id` | BIGINT UNSIGNED | NN | — | composite FK `(tenant_id, role_id)` RESTRICT |
| `access_scope_id` | BIGINT UNSIGNED | NN | — | composite FK `(tenant_id, access_scope_id)` RESTRICT — **never nullable**; "no scope" is not a state (Law 5) |
| `effective_from` | DATE | NN | — | |
| `effective_to` | DATE | Y | NULL | NULL = open |
| `granted_by_user_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT |
| `granted_at` | DATETIME(3) | NN | — | |
| `reason` | VARCHAR(500) | NN | — | mandatory; rendered in the audit ledger |
| `workflow_instance_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT — the maker-checker instance |
| `revoked_by_user_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `revoked_at` | DATETIME(3) | Y | NULL | |
| `revocation_reason` | VARCHAR(500) | Y | NULL | |
| `is_suspended` | TINYINT(1) | NN | 0 | set when a mandatory-MFA holder has not enrolled (R-XXII-194) |
| `suspended_reason_code` | VARCHAR(40) | Y | NULL | |
| `row_version` | INT UNSIGNED | NN | 1 | |

Unique: **U(`tenant_id`,`user_id`,`role_id`,`access_scope_id`,`effective_from`)**
— the same grant cannot be created twice on the same day.
Indexes: `IX(tenant_id, user_id, effective_from, effective_to)` — the hot path,
resolved on every request; `IX(tenant_id, role_id)` — impact preview;
`IX(tenant_id, access_scope_id)` — scope-change impact;
`IX(tenant_id, effective_to)` — the expiry sweep.
Tenant scope: tenant · History: **the table is its own history** — rows are
closed, never updated in place beyond `effective_to`/revocation columns · Soft
delete: **never** · Audit: full, field-level, mandatory · PII: `none`.

### XXII.10.10 `users` — the identity columns this part owns

Only the access- and authentication-relevant columns are specified here; the
employee record is Part X's.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | auto | PK |
| `uuid` | BINARY(16) | NN | — | **U(`tenant_id`,`uuid`)** |
| `tenant_id` | BIGINT UNSIGNED | NN | — | FK RESTRICT · CHECK > 0 — **no null-tenant user exists** (R-XXII-111) |
| `email` | VARCHAR(190) | NN | — | **U(`tenant_id`,`email`)** — an address may recur across tenants |
| `email_verified_at` | DATETIME(3) | Y | NULL | |
| `employee_id` | BIGINT UNSIGNED | Y | NULL | composite FK `(tenant_id, employee_id)` RESTRICT · **U(`tenant_id`,`employee_id`)** — one login per person |
| `password_hash` | VARCHAR(255) | Y | NULL | **NULL until activation** (R-XXII-220) |
| `password_algo` | VARCHAR(20) | Y | NULL | drives rehash-on-verify |
| `password_set_at` | DATETIME(3) | Y | NULL | |
| `password_expires_at` | DATETIME(3) | Y | NULL | materialised from policy at set time |
| `must_change_password` | TINYINT(1) | NN | 0 | |
| `lifecycle_state_code` | VARCHAR(30) | NN | `pending_activation` | FK→ `status_values` domain `user_lifecycle`: `pending_activation` \| `active` \| `disabled` \| `locked` \| `deprovisioned` |
| `is_system_actor` | TINYINT(1) | NN | 0 | R-XXII-099; cannot sign in interactively |
| `mfa_required_cached` | TINYINT(1) | NN | 0 | derived from assignments; recomputed on assignment change |
| `mfa_enrolled_at` | DATETIME(3) | Y | NULL | |
| `failed_attempt_count` | SMALLINT UNSIGNED | NN | 0 | |
| `failed_window_started_at` | DATETIME(3) | Y | NULL | |
| `locked_until` | DATETIME(3) | Y | NULL | |
| `last_login_at` | DATETIME(3) | Y | NULL | |
| `sso_subject` | VARCHAR(190) | Y | NULL | **U(`tenant_id`,`sso_subject`)** |
| `is_break_glass` | TINYINT(1) | NN | 0 | R-XXII-202 |
| `deactivated_at` | DATETIME(3) | Y | NULL | |
| `row_version` | INT UNSIGNED | NN | 1 | |

Indexes: `IX(tenant_id, lifecycle_state_code)`, `IX(tenant_id, locked_until)`,
`IX(tenant_id, employee_id)`.
Tenant scope: tenant · History: `audit_events` + `user_credentials_history` ·
Soft delete: **no** — `deprovisioned` is a lifecycle state, and identity rows are
never removed while audit rows reference them · Audit: full, field-level ·
PII: `identifying` (email), credential columns are `restricted`.

*Origin note.* SmartPRS 2's `users` table gains `must_set_password` at runtime
from a web request (`AuthController::ensureFirstPasswordCol():479–488`), holds
`tenant_id NULL` for platform staff, and its migration filename promises a
`company_id` it does not add. All three are corrected here.

### XXII.10.11 `user_password_history`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | PK |
| `tenant_id` | BIGINT UNSIGNED | NN | FK RESTRICT |
| `user_id` | BIGINT UNSIGNED | NN | composite FK RESTRICT |
| `password_hash` | VARCHAR(255) | NN | never the plaintext, never reversible |
| `algo` | VARCHAR(20) | NN | |
| `set_at` | DATETIME(3) | NN | |
| `set_by_user_id` | BIGINT UNSIGNED | Y | NULL for self-set |

Indexes: `IX(tenant_id, user_id, set_at DESC)`. Pruned to
`policy.history_depth + margin` by a retention job. PII: `restricted`
(credential). Audit: the write itself is the audit.

### XXII.10.12 `user_mfa_factors`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | PK |
| `tenant_id` | BIGINT UNSIGNED | NN | FK RESTRICT |
| `user_id` | BIGINT UNSIGNED | NN | composite FK RESTRICT |
| `method_code` | VARCHAR(20) | NN | `totp` \| `email_otp` \| `recovery` \| (`webauthn`) |
| `label` | VARCHAR(80) | Y | user-chosen |
| `secret_ciphertext` | VARBINARY(512) | Y | TOTP secret; **encrypted**, key id recorded |
| `encryption_key_id` | VARCHAR(40) | Y | R-XXII-155 |
| `code_hash` | VARCHAR(255) | Y | recovery codes: **hashed**, one row per code |
| `is_primary` | TINYINT(1) | NN | |
| `enrolled_at` | DATETIME(3) | NN | |
| `last_used_at` | DATETIME(3) | Y | |
| `consumed_at` | DATETIME(3) | Y | single-use factors |
| `revoked_at` | DATETIME(3) | Y | |

Unique: **U(`tenant_id`,`user_id`,`method_code`,`label`)**.
PII: `restricted` (credential). Audit: enrol, use, revoke, reset.

### XXII.10.13 `user_sessions`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | PK |
| `uuid` | BINARY(16) | NN | **U(`tenant_id`,`uuid`)** — the handle the user revokes |
| `tenant_id` | BIGINT UNSIGNED | NN | FK RESTRICT |
| `user_id` | BIGINT UNSIGNED | NN | composite FK RESTRICT |
| `session_token_hash` | VARBINARY(64) | NN | **U(`session_token_hash`)** — hashed, never stored raw |
| `auth_method_code` | VARCHAR(20) | NN | `password` \| `sso` \| `impersonation` \| `api` |
| `mfa_satisfied_at` | DATETIME(3) | Y | drives `assurance = mfa` |
| `last_reauth_at` | DATETIME(3) | Y | drives `assurance = reauth(n)` |
| `device_id` | BIGINT UNSIGNED | Y | FK RESTRICT |
| `ip_hash` | VARBINARY(32) | NN | hashed; the raw address lives in the login-audit row under its own retention |
| `user_agent_hash` | VARBINARY(32) | NN | |
| `started_at` | DATETIME(3) | NN | |
| `last_seen_at` | DATETIME(3) | NN | |
| `idle_expires_at` | DATETIME(3) | NN | |
| `absolute_expires_at` | DATETIME(3) | NN | **not nullable** (R-XXII-205) |
| `remember_token_hash` | VARBINARY(64) | Y | separate, bounded, individually revocable |
| `impersonation_session_id` | BIGINT UNSIGNED | Y | FK RESTRICT |
| `ended_at` | DATETIME(3) | Y | |
| `ended_reason_code` | VARCHAR(30) | Y | `logout` \| `idle` \| `absolute` \| `revoked` \| `password_change` \| `disabled` \| `evicted` |

Indexes: `IX(tenant_id, user_id, ended_at)`, `IX(absolute_expires_at)` — the
sweep, `IX(tenant_id, device_id)`.
Tenant scope: tenant · Retention: short, by class · PII: `sensitive` (device and
network metadata) · Audit: start and end.

### XXII.10.14 `login_events`

Purpose: the login and device audit (R-XXII-211). Separate from the ledger
because its volume and retention differ, and because failures must be recordable
without a resolved actor.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | PK |
| `tenant_id` | BIGINT UNSIGNED | Y | **the only nullable tenant in this part** — a failure against an unknown address has no resolvable tenant. Not a business table; excluded from the tenant-owned class in the schema registry and never read by a tenant-scoped query except through `user_id` |
| `user_id` | BIGINT UNSIGNED | Y | NULL when the identity did not resolve |
| `email_hash` | VARBINARY(32) | NN | hashed — a failure log must not become an address list |
| `outcome_code` | VARCHAR(30) | NN | `success` \| `bad_credential` \| `disabled` \| `locked` \| `throttled` \| `mfa_failed` \| `mfa_required` \| `sso_failed` \| `policy_expired` |
| `auth_method_code` | VARCHAR(20) | NN | |
| `mfa_method_code` | VARCHAR(20) | Y | |
| `ip` | VARBINARY(16) | NN | raw, under its own retention class |
| `user_agent` | VARCHAR(400) | Y | |
| `device_id` | BIGINT UNSIGNED | Y | FK RESTRICT |
| `is_new_device` | TINYINT(1) | NN | drives the notification |
| `session_id` | BIGINT UNSIGNED | Y | FK RESTRICT |
| `correlation_id` | BINARY(16) | NN | |
| `occurred_at` | DATETIME(3) | NN | |

Indexes: `IX(email_hash, occurred_at)` — the account lockout counter,
`IX(tenant_id, user_id, occurred_at)`, `IX(ip, occurred_at)` — source throttling
and anomaly detection.
Retention: `operational-security`, longer than sessions, shorter than the ledger.
PII: `sensitive`.

### XXII.10.15 `auth_tokens`

Purpose: **one** table for every credential-bearing artefact (R-XXII-215),
replacing SmartPRS 2's three runtime-created definitions of
`user_password_resets` plus seven uncontrolled cleartext token columns scattered
across six controllers.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | PK |
| `tenant_id` | BIGINT UNSIGNED | NN | FK RESTRICT |
| `purpose_code` | VARCHAR(40) | NN | `password_reset` \| `invitation` \| `welcome` \| `first_password` \| `mfa_enrolment` \| `offer_accept` \| `nda_sign` \| `self_onboarding` \| `transfer_ack` \| `agent_verify` \| `agent_earnings` \| `quotation_pay` \| `licence_invoice` \| `export_artefact` \| `document_url` |
| `token_hash` | VARBINARY(64) | NN | **U(`token_hash`)** — SHA-256 of a ≥128-bit CSPRNG secret; the raw value exists only in the delivered URL |
| `subject_type` | VARCHAR(40) | NN | `user` \| `employee` \| `candidate` \| `agent` \| `document` \| `export` |
| `subject_id` | BIGINT UNSIGNED | NN | polymorphic; validated by the issuing context |
| `issued_to_hash` | VARBINARY(32) | Y | hashed destination address, for resend-cooldown accounting |
| `issued_by_user_id` | BIGINT UNSIGNED | Y | NULL for self-service |
| `issued_at` | DATETIME(3) | NN | |
| `expires_at` | DATETIME(3) | NN | **not nullable** — a non-expiring token cannot be created (R-XXII-216) |
| `single_use` | TINYINT(1) | NN | |
| `consumed_at` | DATETIME(3) | Y | |
| `consumed_ip` | VARBINARY(16) | Y | |
| `revoked_at` | DATETIME(3) | Y | |
| `revoked_by_user_id` | BIGINT UNSIGNED | Y | |
| `use_count` | INT UNSIGNED | NN | for multi-use portal tokens, with a cap |
| `max_uses` | INT UNSIGNED | Y | |
| `binding_json` | JSON | Y | actor, operation, nonce for signed URLs (R-XXII-171) |

Indexes: `IX(tenant_id, purpose_code, subject_type, subject_id)`,
`IX(expires_at)` — the purge sweep, `IX(tenant_id, issued_to_hash, issued_at)` —
resend cooldown.
Tenant scope: tenant · Soft delete: no; expired rows are purged by retention
after a forensic window · Audit: issue, consume, revoke · PII: `restricted`
(credential).

### XXII.10.16 `api_credentials`

Purpose: machine identity (§XXII.8). Carries forward SmartPRS 2's `api_keys`
design, which is correct, and adds assignment, expiry-mandatory, rotation and
IP allow-listing.

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | PK |
| `uuid` | BINARY(16) | NN | **U(`tenant_id`,`uuid`)** |
| `tenant_id` | BIGINT UNSIGNED | NN | FK RESTRICT · CHECK > 0 — a null-tenant credential cannot exist |
| `kind_code` | VARCHAR(20) | NN | `api_key` \| `oauth_client` \| `device` \| `webhook_signing` |
| `name` | VARCHAR(120) | NN | |
| `prefix` | VARCHAR(20) | NN | **U(`prefix`)** — a lookup handle, not a credential |
| `secret_hash` | VARBINARY(64) | NN | SHA-256; compared with a constant-time comparison |
| `machine_user_id` | BIGINT UNSIGNED | NN | composite FK RESTRICT → the `is_system_actor` user carrying the assignment (R-XXII-240) |
| `ip_allowlist_json` | JSON | Y | CIDR list |
| `expires_at` | DATETIME(3) | NN | **not nullable** (R-XXII-244) |
| `rotated_from_id` | BIGINT UNSIGNED | Y | FK self RESTRICT — the overlap window |
| `overlap_until` | DATETIME(3) | Y | R-XXII-245 |
| `last_used_at` | DATETIME(3) | Y | |
| `last_used_ip` | VARBINARY(16) | Y | |
| `revoked_at` | DATETIME(3) | Y | |
| `revoked_by_user_id` | BIGINT UNSIGNED | Y | |
| `created_by_user_id` | BIGINT UNSIGNED | NN | |
| `created_at` | DATETIME(3) | NN | |

Indexes: `IX(tenant_id, kind_code, revoked_at)`, `IX(expires_at)`.
PII: `restricted` (credential) · Audit: full.

### XXII.10.17 `impersonation_sessions`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | NN | PK |
| `uuid` | BINARY(16) | NN | **U(`uuid`)** |
| `tenant_id` | BIGINT UNSIGNED | NN | the **target's** tenant; FK RESTRICT |
| `actor_user_id` | BIGINT UNSIGNED | NN | FK RESTRICT — lives in the platform tenant for vendor access |
| `actor_tenant_id` | BIGINT UNSIGNED | NN | FK RESTRICT |
| `target_user_id` | BIGINT UNSIGNED | NN | composite FK `(tenant_id, target_user_id)` RESTRICT |
| `permission_mode_code` | VARCHAR(20) | NN | `read_only` \| `read_write` |
| `effective_permission_ids_json` | JSON | NN | the materialised intersection (R-XXII-131) |
| `reason` | VARCHAR(500) | NN | mandatory |
| `ticket_ref` | VARCHAR(80) | Y | mandatory where the tenant's policy requires it |
| `consent_token_id` | BIGINT UNSIGNED | Y | FK→ `auth_tokens` RESTRICT — NULL only under `contractual` mode |
| `started_at` | DATETIME(3) | NN | |
| `expires_at` | DATETIME(3) | NN | bounded by a platform maximum |
| `ended_at` | DATETIME(3) | Y | |
| `ended_reason_code` | VARCHAR(30) | Y | `manual` \| `expired` \| `revoked_by_tenant` \| `revoked_by_platform` |

Indexes: `IX(tenant_id, started_at DESC)` — the customer's vendor-access screen;
`IX(actor_user_id, started_at DESC)`; `IX(ended_at)` — the expiry sweep.
Tenant scope: **dual** — visible to both the target tenant and the platform ·
Soft delete: never · Audit: full, on both ledgers · PII: none.

### XXII.10.18 `auth_policies`

The policy artefact of §XXII.7.1, stored as a typed configuration artefact under
spine S4: header in `config_artefacts`, versions in `config_versions`, payload in
typed child rows (`auth_policy_password`, `auth_policy_lockout`,
`auth_policy_mfa`, `auth_policy_session`, `auth_policy_tokens`,
`auth_policy_sso`). Each child carries `tenant_id`, `config_version_id`, its
typed columns, and nothing free-form. `scope_json` on the version is constrained
to the tenant dimension — an auth policy is not per-branch.

**R-XXII-260** The payload is **typed columns, not JSON** (spine S4). A policy
value that a query must reason about — `min_length`, `absolute_lifetime_minutes`
— is a column, so the compliance report "which tenants are below the recommended
floor?" is a query rather than a scan.

### XXII.10.19 Tables this part deletes

| SmartPRS 2 table / store | Disposition | Reason |
|---|---|---|
| `settings` row `key='app_state'` | **removed** | ungated whole-tenant read/write; the role matrix lives inside it; an XSS sink; roughly sixty collections in one untyped JSON column (K §2.1, G SEC-03) |
| `permissions`, `role_has_permissions` (Spatie seed) | **replaced** | 13 rows, zero reads (G D-08) |
| `model_has_permissions` | **removed** | never populated |
| `password_reset_tokens` | **removed** | migrated and never used (G §2.1) |
| `user_password_resets` (three runtime definitions) | **replaced** by `auth_tokens` | G §2.2 |
| `personal_access_tokens` | **removed** | Sanctum installed, no route uses it (G D-21) |
| `demo_requests` | **not in the product** | demonstration is a separate artefact (R-XXII-228) |

---

## XXII.11 API surface

All under `/api/v3`. Every entry names its operation and therefore its
permission; the table **is** the operation registry for this module, and the
CI sweep in R-XXII-074 reads it. Public ids are UUIDv7 throughout (R-XXII-072).

### XXII.11.1 Catalogue (read-only, platform data)

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/access/permissions` | `access.role.list` | the catalogue, with descriptions, risk classes and implications (R-XXII-018) |
| GET | `/access/permission-verbs` | `access.role.list` | |
| GET | `/access/scope-types` | `access.role.list` | |
| GET | `/access/operations` | `access.role.list` | operation → permission map; drives the UI capability payload |
| GET | `/access/modules` | `access.role.list` | the single module registry (R-XXII-016) |

### XXII.11.2 Roles

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/access/roles` | `access.role.list` | cursor-paginated; filters: origin, privileged, retired |
| POST | `/access/roles` | `access.role.create` | creates a draft; idempotency key required |
| GET | `/access/roles/{uuid}` | `access.role.list` | includes the effective (closed) permission set |
| PATCH | `/access/roles/{uuid}` | `access.role.update` | draft only; PATCH semantics |
| POST | `/access/roles/{uuid}/clone` | `access.role.create` | the only way to derive from a system role |
| POST | `/access/roles/{uuid}/versions/{v}/submit` | `access.role.update` | enters maker-checker |
| POST | `/access/roles/{uuid}/versions/{v}/publish` | `access.role.publish` | MFA; returns the impact preview id |
| GET | `/access/roles/{uuid}/versions/{v}/impact` | `access.role.update` | affected users, permissions gained/lost |
| GET | `/access/roles/{uuid}/versions/{v}/diff` | `access.role.update` | mandatory before publish (spine S4) |
| POST | `/access/roles/{uuid}/retire` | `access.role.update` | refused while assignments reference it in the future |
| GET | `/access/roles/{uuid}/assignees` | `access.user.list` | who holds it, with scopes |

### XXII.11.3 Scopes

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/access/scopes` | `access.role.list` | |
| POST | `/access/scopes` | `access.role.create` | |
| GET | `/access/scopes/{uuid}` | `access.role.list` | |
| PATCH | `/access/scopes/{uuid}` | `access.role.update` | draft only |
| POST | `/access/scopes/{uuid}/versions/{v}/publish` | `access.role.publish` | widening requires approval |
| GET | `/access/scopes/{uuid}/preview` | `access.role.list` | **the employee count and a sample, as at a date** — the answer to "who does this actually reach?" |
| GET | `/access/scopes/{uuid}/impact` | `access.role.update` | assignments affected by a pending change |

### XXII.11.4 Assignments

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/access/users/{uuid}/assignments` | `access.user.list` | including expired, with `as_of` |
| POST | `/access/users/{uuid}/assignments` | `access.assignment.grant` | MFA; R-XXII-033 evaluated; reason mandatory |
| DELETE | `/access/users/{uuid}/assignments/{auuid}` | `access.assignment.revoke` | closes `effective_to`; never deletes |
| GET | `/access/users/{uuid}/effective` | `access.user.list` | effective permissions and scopes at `as_of` — **the "what can this person do?" endpoint** |
| GET | `/access/users/{uuid}/reachable` | `access.user.list` | employee count per permission at `as_of`; the answer auditors ask for |
| POST | `/access/assignments/simulate` | `access.role.list` | dry-run a proposed `(role, scope)` before granting |

### XXII.11.5 Users and credentials

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/access/users` | `access.user.list` | scoped |
| POST | `/access/users` | `access.user.create` | creates `pending_activation`; **no password field exists on this request** |
| PATCH | `/access/users/{uuid}` | `access.user.update` | |
| POST | `/access/users/{uuid}/disable` | `access.user.disable` | ends sessions |
| POST | `/access/users/{uuid}/enable` | `access.user.disable` | |
| POST | `/access/users/{uuid}/activation-link` | `access.user.credential.reset` | issues a set-password token; R-XXII-034 |
| POST | `/access/users/{uuid}/sessions/revoke` | `access.user.session.revoke` | |
| POST | `/access/users/{uuid}/mfa/reset` | `access.user.mfa.reset` | maker-checker for a privileged target |
| POST | `/access/users/{uuid}/unlock` | `access.user.disable` | audited |
| GET | `/access/users/{uuid}/login-events` | `access.audit.read` | |

There is deliberately **no** `POST /access/users/{uuid}/password` (R-XXII-221).

### XXII.11.6 Self-service authentication

| Method | Path | Permission | Notes |
|---|---|---|---|
| POST | `/auth/login` | public | uniform failures; throttled on identity and source |
| POST | `/auth/mfa/verify` | partial session | |
| POST | `/auth/logout` | authenticated | |
| POST | `/auth/password/forgot` | public | uniform response; cooldown |
| POST | `/auth/password/reset` | token | |
| POST | `/auth/password/change` | authenticated | throttled (R-XXII-106); requires current password |
| POST | `/auth/activate` | token | first password + MFA enrolment where mandatory |
| POST | `/auth/reauth` | authenticated | satisfies `assurance = reauth(n)` |
| GET | `/auth/me` | authenticated | identity, tenant, and the **capability payload** (R-XXII-087) |
| GET | `/auth/sessions` | authenticated | own sessions |
| DELETE | `/auth/sessions/{uuid}` | authenticated | own sessions |
| GET | `/auth/devices` | authenticated | own device history |
| POST | `/auth/mfa/enrol` | authenticated | |
| POST | `/auth/mfa/recovery-codes` | authenticated + reauth | regenerates; audited |
| GET/POST | `/auth/sso/{tenant}/metadata`, `/acs`, `/slo` | public, IdP-bound | SAML |
| GET | `/auth/oidc/{tenant}/callback` | public, IdP-bound | OIDC |

### XXII.11.7 Vendor access and platform

| Method | Path | Permission | Notes |
|---|---|---|---|
| POST | `/platform/tenants/{uuid}/impersonate` | `platform.tenant.impersonate` | MFA + reason + consent per policy |
| DELETE | `/platform/impersonations/{uuid}` | `platform.tenant.impersonate` | end |
| GET | `/access/vendor-access` | `access.audit.read` | **the tenant's own view** of vendor sessions, live and historical |
| DELETE | `/access/vendor-access/{uuid}` | `tenant.settings.update` | the tenant terminates a live session (R-XXII-136) |
| GET | `/access/vendor-access/policy` | `tenant.settings.view` | |
| PUT | `/access/vendor-access/policy` | `tenant.settings.update` | maker-checker |

### XXII.11.8 Policy, credentials and audit

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/security/auth-policy` | `tenant.settings.view` | current published version |
| PUT | `/security/auth-policy` | `tenant.settings.update` | creates a draft; maker-checker to publish |
| GET | `/security/auth-policy/floors` | `tenant.settings.view` | the platform floors, so the UI can prevent an impossible edit |
| GET | `/integrations/credentials` | `access.apikey.list` | never returns a secret |
| POST | `/integrations/credentials` | `access.apikey.issue` | MFA; secret returned **once** |
| POST | `/integrations/credentials/{uuid}/rotate` | `access.apikey.issue` | body: `overlap_hours` |
| DELETE | `/integrations/credentials/{uuid}` | `access.apikey.revoke` | |
| GET | `/audit/events` | `access.audit.read` | cursor-paginated, filterable, bounded |
| GET | `/audit/verify` | `access.audit.read` | bounded window + cursor (R-XXII-127) |
| POST | `/audit/export` | `access.audit.export` | MFA; produces an expiring artefact |
| GET | `/access/explain` | authenticated | §XXII.13 — why was I allowed or denied |

### XXII.11.9 Error envelope

One envelope, machine-readable, never carrying an internal message
(R-XXII-071; *origin:* raw exception messages on ~15 endpoints in SmartPRS 2,
G SEC-25).

```json
{ "error": {
    "code": "ACCESS_SCOPE_DENIED",
    "message": "You do not have access to this employee's records.",
    "correlation_id": "018f2c…",
    "details": { "permission": "employee.record.update", "reason": "subject_out_of_scope" }
} }
```

| Code | HTTP | Meaning |
|---|---:|---|
| `AUTH_REQUIRED` | 401 | no principal |
| `AUTH_INVALID` | 401 | uniform credential failure |
| `AUTH_THROTTLED` | 429 | uniform, no existence disclosure |
| `MFA_REQUIRED` / `REAUTH_REQUIRED` | 403 | assurance unmet |
| `ACCESS_PERMISSION_DENIED` | 403 | no assignment carries the permission |
| `ACCESS_SCOPE_DENIED` | 403 | permission held, subject out of scope |
| `ACCESS_ROLE_UNKNOWN` | 422 | assignment references an unknown or unpublished role (R-XXII-024) |
| `ACCESS_GRANT_ABOVE_SELF` | 422 | R-XXII-033 / R-XXII-034 |
| `NOT_FOUND` | 404 | includes every cross-tenant reference (R-XXII-097) |
| `LICENCE_DENIED` / `PLAN_DENIED` | 403 | commercial gate, distinguishable |
| `STATE_DENIED` | 409 | period lock, workflow state, tenant suspended |
| `AUTHZ_UNAVAILABLE` | 503 | fail-closed on an infrastructural fault; alerted |

---

## XXII.12 Events

### XXII.12.1 Emitted

| Event | Payload highlights | Consumers |
|---|---|---|
| `access.role.published` | role, version, permissions added/removed, affected user count | notification, audit, cache invalidation, UI capability regeneration |
| `access.role.retired` | role, replacement guidance | notification, audit |
| `access.scope.published` | scope, version, employee-count delta, widening flag | audit, cache invalidation, impact report |
| `access.assignment.granted` | user, role, scope, effective range, grantor, reason | notification to the user, audit, MFA-requirement recompute |
| `access.assignment.revoked` | as above + revocation reason | notification, session re-evaluation |
| `access.assignment.expiring` | user, assignment, days remaining | notification to grantor and holder |
| `access.assignment.suspended` | user, assignment, `mfa_not_enrolled` | notification, operations dashboard |
| `access.denied.permission` | actor, operation, permission | anomaly detection, audit |
| `access.denied.scope` | actor, operation, subject class | anomaly detection, audit |
| `access.error.failclosed` | actor, operation, fault class | **alert** — this is never routine |
| `auth.login.succeeded` / `.failed` | outcome code, source, device, new-device flag | notification, lockout counter, anomaly detection |
| `auth.account.locked` / `.unlocked` | user, reason, actor | notification to user and administrator |
| `auth.password.changed` / `.reset` | user, actor, self-flag | notification, session invalidation |
| `auth.mfa.enrolled` / `.reset` / `.factor_revoked` | user, method, actor | notification, audit |
| `auth.session.revoked` | session, actor, reason | audit |
| `auth.policy.published` | tenant, version, non-compliant account count | notification, operations dashboard |
| `auth.sso.assertion_failed` / `auth.sso.certificate_expiring` | tenant, IdP, days remaining | **alert** — an SSO failure locks out a tenant |
| `access.impersonation.started` / `.ended` | actor, target, tenant, mode, reason, ticket | notification to the tenant's configured recipients, dual audit |
| `access.crosstenant.entered` / `.exited` | actor, permission, reason, tenant | platform audit, tenant audit |
| `security.credential.issued` / `.rotated` / `.revoked` / `.expiring` | credential, tenant, owner | notification, operations dashboard |
| `security.credential.plaintext_transport` | credential, source | **alert** + forced rotation flag (R-XXII-248) |
| `security.sensitive_read` | actor, subject, field or document, purpose | anomaly detection, DPDP reporting |
| `security.export.produced` | actor, profile, row count, columns, PII class | anomaly detection, audit |
| `security.upload.quarantined` | object, reason, uploader | alert, notification |
| `security.anomaly.detected` | signal, actor, evidence | alert; blocking is configuration (R-XXII-213) |
| `tenant.provisioned` / `.suspended` / `.resumed` | tenant, actor | platform audit |

### XXII.12.2 Consumed

| Event | Source | Reaction |
|---|---|---|
| `employee.org_assignment.changed` | Part V / Part X | invalidate resolved-scope caches for affected users; recompute `reachable` counts |
| `employee.reporting_manager.changed` | Part V | as above, for `direct_reports` / `reporting_tree` |
| `employee.exited` | Part X | close open assignments on the exit date; revoke sessions and credentials owned by that user |
| `employee.rehired` | Part X | do **not** restore prior assignments; a rehire is a new grant |
| `org_unit.deactivated` | Part V | flag every scope naming it; refuse the deactivation while a live assignment depends on it, unless the actor confirms |
| `workflow.instance.completed` | Part IX | apply the pending role publication, assignment grant, or credential action the instance authorised |
| `tenant.subscription.state_changed` | Part on SaaS | pipeline step 8 |
| `rulepack.activated` | Part XVII | no access effect; recorded for correlation |

---

## XXII.13 Explainability

Spine S15 requires that every derived value explains itself. An authorisation
decision is a derived value.

**R-XXII-270** `Explain(actor, operation, subject, as_of)` MUST return a
machine-readable trace for any access decision, and the UI MUST expose it
wherever a control is disabled or a request is denied.

**Allowed:**

```
Decision        ALLOW
Actor           Priya Nair  (user 018f2c…, tenant 7)
Operation       employee.record.update  →  PATCH /api/v3/employees/{uuid}
Subject         EMP1042  Ravi Kumar
As at           12-Aug-2026  (business date of the operation)

Permission      employee.record.update
  satisfied by  assignment A1  ·  role "Branch HR" v4 (published 01-Apr-2026)
  granted       directly (not implied)
Data scope      scope "Hyderabad Branch" v2  ·  type branch, include_descendants
  resolution    EMP1042's org assignment in force on 12-Aug-2026 places them in
                Hyderabad → Collections → Team B, a descendant of the scope unit
Tenancy         tenant 7, applied by the persistence layer
Assurance       none required for this operation
Commercial      plan "Growth" includes module employee; licence valid to 31-Mar-2027
State           no period lock on this entity

Not consulted   assignment A2 ("Payroll Viewer", scope Tenant) — does not carry
                this permission
```

**Denied — and the denial says which axis failed, which is the property SmartPRS
2 cannot offer at all:**

```
Decision        DENY  ·  ACCESS_SCOPE_DENIED
Actor           Priya Nair
Operation       employee.record.update
Subject         EMP2210  Anand Rao

Permission      employee.record.update  — HELD
  via           assignment A1 · role "Branch HR" v4
Data scope      NOT SATISFIED
  A1 scope      "Hyderabad Branch" v2
  subject org   Chennai → Collections, as at 12-Aug-2026
  nearest miss  A1 reaches 214 employees; EMP2210 is not among them
Other grants    A2 ("Payroll Viewer", Tenant) does not carry this permission
What to change  either a scope that includes Chennai, or an assignment of
                "Branch HR" scoped to Chennai
```

**R-XXII-271** The trace MUST name the **version** of every artefact it used —
role version, scope version, policy version — so a decision is reproducible
after those artefacts change. *(Law 3, Law 4.)*

**R-XXII-272** The trace MUST NOT disclose data the actor may not see. It names
that a subject exists **only** when the subject is inside the actor's tenant; a
cross-tenant subject produces `NOT_FOUND` with no trace at all (R-XXII-097). It
names org units and counts, never other employees' attributes.

**R-XXII-273** `GET /access/users/{uuid}/reachable` MUST render the same trace in
aggregate: for each permission the user holds, how many employees it reaches,
through which assignment, and a sample. This is the answer to the auditor's
question — *"prove that this branch manager cannot see Chennai salaries"* — and
SmartPRS 2 cannot answer it because the information does not exist in any form.

**R-XXII-274** A denial MUST be reproducible from the audit ledger alone: the
`access.denied.*` row carries actor, operation, subject class, the assignments
considered, and the failing axis.

---

## XXII.14 The security test suite (brief Section 115)

**R-XXII-300** The security suite runs in **CI on every pull request and on every
release candidate**. A failure **blocks release**. It is not advisory, not a
nightly informational job, and not triageable to a later sprint.

**R-XXII-301** The suite is **generated** from the operation registry, the
permission catalogue and the role catalogue wherever generation is possible, so
that adding an operation adds its tests. Hand-written tests exist only where the
scenario is not derivable — the ones below marked *hand-written*.

**R-XXII-302** SmartPRS 2 ships **one** authorisation test file with **three
assertions and zero negative role assertions**
(`tests/Feature/AuthAccessTest.php`, 21 lines; G D-15). Every finding in
§XXII.1 shipped because no test could have caught it. The suite below is the
direct answer.

Baseline fixture for the whole suite — two deliberately confusable tenants, as
specified in R-XXII-138.

### XXII.14.1 Cross-tenant IDOR

*Generated, per operation.* The twelve assertions of R-XXII-139, for every
operation in the registry, executed as Tenant A's actor against Tenant B's public
ids. Additionally, *hand-written*: a sweep asserting that **no** response body,
header, redirect target, PDF, export or webhook payload anywhere in the product
contains a sequential integer primary key (R-XXII-072).

*Origin:* 18 IDOR endpoints and 354 id-only query sites (G §7.4, §0.4).

### XXII.14.2 Employee accessing another employee

*Generated, per operation, for every shipped role.* As a plain employee of
Tenant A:

| # | Assertion |
|---:|---|
| 1 | Every employee-subject operation against a colleague's uuid → 403 `ACCESS_SCOPE_DENIED`, nothing written |
| 2 | Global search returns only the actor's own record |
| 3 | The employee list endpoint returns exactly one row |
| 4 | A report, dashboard tile or aggregate reveals no colleague-level figure |
| 5 | A colleague's payslip, letter, ID card, photo, document, leave balance, punch log, probation history and increment letter each → 403 |
| 6 | The actor's own record returns **without** any field the field definitions mark above their permission |
| 7 | Every `mss.*` operation → 403 (the actor has no reports) |

Then repeated for a **role-less** user (R-XXII-036): every assertion above, plus
"the capability payload is empty" and "no navigation entry resolves".

*Origin:* `selfScope()` narrows only the literal role `employee`, so
`field_agent`, `accountant`, `platform_support` and any role-less user receive
the whole workforce including `ctc`, `pan`, `bankAcc` and `ifsc`
(`AppDataController.php:221–232, 302–424`; G §7.1 #9).

### XXII.14.3 Role escalation

*Hand-written, because these are the specific paths SmartPRS 2 shipped.*

| # | Scenario | Must assert |
|---:|---|---|
| 1 | An actor holding *HR Manager* grants a role containing a permission the actor lacks | 422 `ACCESS_GRANT_ABOVE_SELF`; no assignment row; audit row for the attempt |
| 2 | The same actor grants a scope wider than their own | as above |
| 3 | The same actor issues an activation link for a user holding a superior permission set | 403; no token row |
| 4 | The same actor resets a superior's MFA, revokes their sessions, or disables them | 403 each |
| 5 | An actor edits a **system** role | 403; the system role's definition is byte-identical afterwards |
| 6 | An actor creates a scope of type `platform` inside a customer tenant | 422; the type is not offered and is refused if supplied |
| 7 | An actor assigns a role in `draft` or `testing` status | 422 `ACCESS_ROLE_UNKNOWN`; no assignment |
| 8 | An actor assigns a role belonging to another tenant | 404 |
| 9 | An actor self-grants by any path — direct assignment, role edit, clone-and-assign, scope widening | refused at every path |
| 10 | A role publication that would widen an in-force assignment proceeds only through maker-checker, and the impact preview names every affected user | asserted on both the refusal without approval and the success with it |
| 11 | An actor future-dates a grant that is above-self **at the effective date** though not today | refused at the effective date's re-evaluation (R-XXII-033) |

*Origin:* `canManage` permits `hr_manager` to create an `admin` and to set any
tenant user's password, unaudited (`UserController.php:26–37, 150–152, 262–278`;
G SEC-07).

### XXII.14.4 The route × role authorisation matrix

*Generated.* For **every** operation × **every** shipped default role ×
{in-scope subject, out-of-scope subject}, assert the expected outcome from the
matrix in §XXII.15.1, and assert that a denial **wrote no row**.

**R-XXII-303** The matrix test MUST assert the *negative* cases explicitly. A
suite that only proves what a role can do proves nothing about what it cannot.

*Additional generated variants:*

| Variant | Assertion |
|---|---|
| Machine credential with insufficient scope | denial (R-XXII-242) |
| Machine credential with sufficient scope but out-of-scope subject | `ACCESS_SCOPE_DENIED` |
| Expired credential | denial with a distinct code |
| Revoked credential | denial within the declared invalidation interval |
| Rotated credential in overlap | both secrets succeed; after `overlap_until`, only the new one |
| Impersonated session attempting an `impersonation_forbidden` operation | denial (R-XXII-135) |
| Impersonated session attempting an operation the **target** lacks | denial (the intersection, R-XXII-131) |

### XXII.14.5 Undocumented endpoints

*Generated, structural.*

| # | Assertion |
|---:|---|
| 1 | Every registered route resolves to an operation — otherwise **build failure** (R-XXII-074) |
| 2 | The live unauthenticated surface equals the declared allow-list, exactly (R-XXII-075) |
| 3 | Every operation appears in the generated OpenAPI document with its security block |
| 4 | Every operation appears in the route × role matrix |
| 5 | No route reachable in production is registered only under a debug, test or demo condition |
| 6 | Every CSRF exemption carries a `csrf_exempt_reason` (R-XXII-076) |
| 7 | Every operation whose permission is `privileged` or `platform` declares an assurance requirement |
| 8 | No `Domain\*` namespace outside `Domain\Access` references a role or permission API (R-XXII-078) |
| 9 | No DDL statement exists outside `database/migrations` (R-XXII-086) |
| 10 | No `catch` in `Domain\Access` returns an allow (R-XXII-095) |
| 11 | Every `crossTenant(` call site is on the declared allow-list (R-XXII-084) |
| 12 | Every cache key is built by the tenant-aware key factory (R-XXII-125) |

*Origin:* 78 authenticated routes with no check, 16 CSRF exemptions of which one
has no stated compensating control, 155 runtime DDL statements, and a
`bootstrap/` directory absent from the analysed tree so the global middleware
stack could not be verified at all (G §7.3, §7.5, §2.2, §0.3).

### XXII.14.6 Payslip access

*Hand-written; this is the archetypal sensitive read.*

| # | Assertion |
|---:|---|
| 1 | An employee downloads **their own** payslip: 200 |
| 2 | An employee requests a colleague's payslip by uuid: 403, `sensitive_read` **not** written, `access.denied.scope` written |
| 3 | A field agent, an accounts officer and a role-less user each request a colleague's payslip: 403 |
| 4 | A branch manager scoped to Hyderabad requests a Chennai payslip: 403 |
| 5 | The same manager requests a Hyderabad payslip: 200, `sensitive_read` audit row written naming the subject |
| 6 | A payslip for a period **before** the subject entered the manager's scope: 403 (the scope resolves at the period's business date, R-XXII-056) |
| 7 | A payslip PDF URL issued to actor X cannot be redeemed by actor Y (R-XXII-171) |
| 8 | An unpublished run's payslips are unreachable by any employee, including their own |
| 9 | A cross-tenant payslip uuid: 404, not 403 |

*Origin:* `payslipPdf` relies on `selfScope`, which does not narrow for four
distinct actor classes (G §7.4 #10).

### XXII.14.7 Document access, storage and the surface matrix

*Hand-written and generated.*

| # | Assertion |
|---:|---|
| 1 | Upload a document, then request its storage path directly over HTTP: **404/403** — including after `storage:link`-equivalent operations (R-XXII-169) |
| 2 | The installer health check fails when the private storage root is HTTP-reachable (R-XXII-173) |
| 3 | A signed URL expires exactly at its TTL and is refused after |
| 4 | A `restricted` object's signed URL is single-use |
| 5 | A signed URL issued for actor X, redeemed by actor Y: refused |
| 6 | A colleague's document by uuid: 403, no bytes |
| 7 | A cross-tenant document uuid: 404 |
| 8 | A file whose extension says `.pdf` and whose magic bytes say PHP: rejected at upload (R-XXII-163) |
| 9 | A file whose extension says `.jpg` and whose content is a polyglot: rejected |
| 10 | A malware-positive upload: quarantined, alerted, audited, never retrievable, uploader notified without the signature |
| 11 | A scanner outage: uploads held `pending_scan`, never admitted (R-XXII-164) |
| 12 | A caller-supplied path in any field of any upload or serve request: rejected, never joined (R-XXII-166) |
| 13 | `..`, absolute paths, URL-encoded traversal and null bytes in a filename: each rejected |
| 14 | An onboarding document promoted at hire remains on the private disk with the same protections (no "copy to public" step exists) |
| 15 | **Surface matrix:** for every field with `pii_class ∈ {sensitive, restricted}` × every surface (screen, list, API, export, PDF, letter merge, ID card, notification body, webhook payload, error message, import error report, audit row): the masked form appears and the unmasked value appears nowhere (R-XXII-158, R-XXII-152) |

*Origin:* extension-only validation, zero MIME checks in the entire application,
five upload paths writing to the web-served disk, onboarding documents copied
from private to public at provisioning, path traversal in the commission-proof
server, and **no `public/.htaccess` in the tree at all** (G SEC-23, SEC-24, §7.7,
D-23).

### XXII.14.8 Payroll approval and segregation of duties

*Hand-written.*

| # | Assertion |
|---:|---|
| 1 | Every payroll operation is denied to every role that must not reach it, and writes nothing |
| 2 | A single user holding the preparer role **cannot** complete both approve and disburse. The roles satisfying each step are configuration, and the test asserts that **no shipped default role satisfies both** |
| 3 | A user who submitted a run cannot approve it (workflow self-approval prohibition) |
| 4 | The bank-file **preview** is gated identically to the bank-file **generate** |
| 5 | An unpermitted actor cannot read a run's totals, a line's net, or the sheet |
| 6 | A branch-scoped approver cannot approve a run containing out-of-scope employees; the denial names the count, not the employees |
| 7 | A locked period refuses every mutation with `STATE_DENIED`, distinguishable from a permission denial |
| 8 | Reopening a locked period requires maker-checker and is audited |

*Origin:* `SalaryApprovalController` contains **zero** `denyUnlessRole` calls;
`isHR()` and `isFinance()` are branch selectors that never abort on the read
paths; `bankFile()` aborts, `bankFilePreview()` does not
(`SalaryApprovalController.php:59–68, 188–189, 224, 240–241, 316–317, 416–441,
454`; G §7.1 #11).

### XXII.14.9 Rule publication, statutory change and configuration

*Hand-written.*

| # | Assertion |
|---:|---|
| 1 | `rules.artefact.publish` is denied to every role without it; a draft edit by a permitted actor does not publish |
| 2 | Publishing without maker-checker approval is refused for `high_risk` artefacts |
| 3 | A published rule cannot be edited in place; an edit creates a new version (Law 3) |
| 4 | A statutory rule pack with an invalid signature is refused; the failure is alerted and the previous pack remains active |
| 5 | A tenant actor cannot author, sign or modify a rule pack — only accept one |
| 6 | Accepting a pack is audited with the pack version and the accepting actor |
| 7 | An auth-policy change below the platform floor is refused (R-XXII-180) |
| 8 | A field's PII class cannot be lowered without maker-checker (R-XXII-151) |
| 9 | Publishing a role or scope version invalidates the affected authorisation caches within the declared interval |
| 10 | A configuration change made in October cannot alter an August payroll result (spine S4 resolution) — asserted here because a **role** change is a configuration change and must obey the same rule for the audit trail |

### XXII.14.10 Exports, webhooks, uploads, CSRF, XSS, injection, rate limiting

*Mixed; generated where the surface is enumerable.*

| Area | Assertions |
|---|---|
| **Exports** | an export profile cannot emit a column above its `max_pii_class`; a `sensitive` export without the permission is refused; a `restricted` export without a per-export approval is refused; every export writes an audit row with row count and column list; the artefact is encrypted, expiring and purged; the artefact URL is not redeemable by another actor; a masking override can only tighten |
| **Webhooks** | signature verified over the **raw** body; a valid signature with a stale timestamp is rejected; a replayed nonce is rejected; an unsigned request is rejected; a payload reaches only the owning tenant's endpoints; a failed delivery retries with backoff and lands in the dead-letter queue; a payload contains no field above the endpoint's declared PII ceiling |
| **Uploads** | as §XXII.14.7 rows 8–13, plus per-purpose size cap, per-employee quota and per-tenant quota enforcement, each with the boundary value |
| **CSRF** | every state-changing cookie-authenticated route rejects a request with no token, a stale token and a token from another session; every exemption is on the declared list with a compensating control that is itself tested (signature, token credential, or pre-login context) |
| **XSS** | stored, reflected and DOM sinks: every user-supplied string round-trips through every rendering surface (screen, PDF, letter merge, notification, export) and is asserted inert; a strict `Content-Security-Policy` is present and a test asserts the page functions under it; a `restricted` document is served with `Content-Disposition: attachment` and a restrictive CSP (R-XXII-167) |
| **Injection** | SQL: every list endpoint is fuzzed on every filter, sort and search parameter, asserting parameterisation; the scoping layer's injected predicates are asserted present in the executed SQL; **SPX**: an expression cannot reach the filesystem, a socket, a shell or the database (spine S5); LDAP/XML/XXE on the SSO paths; command injection on every path that shells out (there must be none); template injection on letter merge |
| **Rate limiting** | per identity, per source, per tenant, per credential and per device, each asserted at the boundary; a throttle response is uniform in wording and timing; the login throttle counts **per account** as well as per source (R-XXII-189); the change-password endpoint is throttled (R-XXII-106); a bulk-download burst trips the limit and raises `security.anomaly.detected` (R-XXII-162) |

*Origin, injection:* SmartPRS 2's `whereRaw` sites in this area all use `?`
placeholders and the reference report's "no SQL injection found" verdict held up
under re-verification (G §8 #15) — this suite exists to keep it true, not because
it is currently false.

### XXII.14.11 Fail-closed fault injection

*Hand-written, one per guard family (R-XXII-096).*

| # | Fault injected | Must assert |
|---:|---|---|
| 1 | The permission lookup throws | 403 (or 503), audit `authz.error`, alert raised, **no row written** |
| 2 | The permission cache is unavailable | as above — never a fallback to "allow" |
| 3 | The scope resolver throws | as above |
| 4 | The scope resolver exceeds its depth or time budget | as above |
| 5 | A reporting cycle exists in the data | denial + `access.reporting_cycle_detected`, not an unbounded walk |
| 6 | The tenancy context is missing | the query **throws**, does not run (R-XXII-081) |
| 7 | The tenant resolves to `0` or a sentinel | 403, alert |
| 8 | The assignment references an unknown role code | 403, alert |
| 9 | An operation names a permission absent from the catalogue | 403, alert — a deployment defect surfaced loudly |
| 10 | The materialised `role_permissions_effective` checksum mismatches its declared grants | fail closed at startup; the application refuses to serve |
| 11 | The MFA verification service is unavailable | the operation requiring MFA is denied, **not** admitted |
| 12 | The breach-corpus service is unavailable | a password **set** is refused with retry-later; sign-in is unaffected (R-XXII-184) |
| 13 | The malware scanner is unavailable | uploads held, never admitted |
| 14 | The audit write fails | the business transaction rolls back (R-XXII-103) |
| 15 | The licence or subscription check throws | denied, not admitted |
| 16 | The commercial-gate lookup throws | denied |

*Origin:* every one of rows 1–3, 15 and 16 is a shipped SmartPRS 2 behaviour that
resolves the other way — six controls returning "allowed" from a `catch`
(`ApprovalService.php:377–379`; `FeaturePermissions.php:99–105`;
`ApiKeyController.php:516–518`; `EditionGuard.php:55–57`;
`DemoWriteGuard.php:73–75`; `LicenseGate.php:73–75`;
`EnsureSubscriptionActive.php:58–59`), plus the licence check granting a full
session from its `catch` (`AuthController.php:267–269`).

### XXII.14.12 Authentication

*Hand-written.*

| # | Assertion |
|---:|---|
| 1 | Valid credential → session; session id regenerated; login event written |
| 2 | Wrong password, unknown address, disabled account, locked account, expired password → **identical** wording, status and timing (R-XXII-193) |
| 3 | Failure count increments per account and per source; the threshold locks; the lock expires; the unlock is audited and notified |
| 4 | A distributed failure pattern locks the account; a single-source pattern blocks the source first (R-XXII-192) |
| 5 | Logout invalidates the session server-side; the cookie alone cannot resurrect it |
| 6 | Password change invalidates other sessions and every outstanding token (R-XXII-187) |
| 7 | A password in the breach corpus is refused at set; a password matching history is refused; `min_age_hours` blocks immediate cycling |
| 8 | A password containing the user's name, email local part or employee code is refused |
| 9 | MFA is enforced for a privileged holder; a non-enrolled holder's assignment suspends after the grace period and the user is notified |
| 10 | A TOTP code is single-use within its window; a replay is rejected |
| 11 | A recovery code is single-use; regeneration invalidates the prior set and is audited |
| 12 | "Remember this device" does not satisfy an `assurance = mfa` requirement (R-XXII-197) |
| 13 | Idle timeout and **absolute** lifetime both terminate a session; the absolute one cannot be extended by activity |
| 14 | A user disabled mid-session is refused on the **next** request; a role revoked mid-session takes effect on the next request (R-XXII-209) |
| 15 | Session cookies carry `Secure`, `HttpOnly` and `SameSite` in every environment except declared loopback development |
| 16 | An SSO assertion carrying group claims that map to nothing grants nothing (R-XXII-201) |
| 17 | Under `sso.mode = required`, local password authentication is refused except for a declared break-glass account, whose use alerts |
| 18 | An expired IdP certificate raises an alert before it expires, not after |
| 19 | A reset token is single-use, expires, and consuming one invalidates the subject's other tokens of that purpose |
| 20 | An activation link cannot set a password for a different user, in a different tenant, or for a different purpose |

### XXII.14.13 Tenant isolation

The generated suite of §XXII.5.7, R-XXII-139 and R-XXII-140 in full, plus:

| # | Assertion |
|---:|---|
| 1 | `crossTenant()` without the platform permission → denial; with it → an audit row on both ledgers, before and after |
| 2 | An impersonation session cannot exceed the platform maximum duration |
| 3 | A tenant terminating a vendor session takes effect on the vendor's next request |
| 4 | Tenant A's audit chain verifies after N interleaved writes from Tenant B (SmartPRS 2's chain forks here — `Audit.php:91`) |
| 5 | An audit `verify()` over a large ledger completes in bounded memory (SmartPRS 2 loads every row — `Audit.php:111`) |

### XXII.14.14 No default credential

*Hand-written, run against the **release artefact**, not the working tree.*

| # | Assertion |
|---:|---|
| 1 | No account exists after a clean install until an administrator is created interactively |
| 2 | No seeder, command, migration or scheduled task in the artefact creates an account with a password |
| 3 | Grep of the artefact for a credential literal, including the historical deny-list (`password`, `smartprs-demo`, `ametecs`), returns nothing (R-XXII-230) |
| 4 | Grep of the shipped documentation for a credential returns nothing (R-XXII-231) |
| 5 | No scheduled task in the artefact creates, resets or takes over an account |
| 6 | A development-only account path refuses to run under `APP_ENV=production`, refuses without an operator-supplied secret, and refuses against a database containing a non-demo tenant — each condition asserted independently (R-XXII-229) |
| 7 | The installer refuses to complete without HTTPS, a running scheduler and worker, and a non-HTTP-reachable private storage root (R-XXII-232) |
| 8 | No credential is passed as a command-line argument by any shipped command (R-XXII-223) |

*Origin:* `OnPremWorkspaceSeeder.php:74–92` (`admin@smartprs.local` / `password`,
ungated, published in the README), `DemoCompanySeeder.php:69`,
`DemoReset.php:35` (`smartprs-demo`, scheduled nightly, unscoped by tenant, takes
over existing accounts), `config/smartprs.php:32` (`'ametecs'`),
`ClientProvision.php:29, 39` (password on the command line)
(G SEC-19, SEC-20, §7.6; L SEC-L-01, SEC-L-12).

### XXII.14.15 Coverage gate

**R-XXII-304** The following are release gates, each individually blocking:

| Gate | Threshold |
|---|---|
| Operation coverage in the route × role matrix | **100 %** of operations |
| Cross-tenant isolation coverage | **100 %** of operations and aggregates |
| Fail-closed fault injection | **100 %** of guard families |
| Undocumented-endpoint structural checks | all 12 pass |
| No-default-credential checks | all 8 pass |
| Sensitive-field surface matrix | 100 % of `sensitive` and `restricted` fields × all surfaces |
| Authentication scenarios | all 20 pass |

---

## XXII.15 The shipped default roles, and the migration from SmartPRS 2

### XXII.15.1 The role → permission → data-scope matrix

These are **system roles** (R-XXII-020): shipped, cloneable, not editable. They
are a starting catalogue, not a constraint — a tenant that needs "Branch Payroll
Officer" clones and edits. Every default scope is the **narrowest** that lets the
role function; a tenant may widen a clone deliberately, which is the point.

Legend: ● full · ◐ own or team only · ○ not granted.
Default scope is the scope the provisioning wizard proposes; it is always
overridable per assignment.

| # | System role | Default scope | Purpose in one line |
|---:|---|---|---|
| 1 | **Tenant Administrator** | `TENANT` | The customer's top authority: access, configuration, subscription |
| 2 | **Access Administrator** | `TENANT` | Roles, scopes, assignments and credentials only — no business data |
| 3 | **HR Manager** | `TENANT` | Hiring, onboarding, the register, leave, attendance, compliance |
| 4 | **Branch HR** | `branch` | The same, narrowed to one or more branches |
| 5 | **Payroll Preparer** | `TENANT` or `dimension_filter` | Prepares runs; cannot approve them |
| 6 | **Payroll Approver** | `TENANT` | Approves runs; cannot prepare them |
| 7 | **Finance / Disbursement** | `TENANT` | Bank file and disbursement marking; no employee edit |
| 8 | **Accounts (money verification)** | `TENANT` | Confirms collected money arrived — **the control SmartPRS 2 cannot assign** |
| 9 | **Compliance Officer** | `TENANT` | DRA, PCC, BGV, authorisations, audit packs, statutory returns |
| 10 | **Reporting Manager** | `direct_reports` (depth 1) | Approves their own team's requests |
| 11 | **Field Agent** | `SELF` | Punches, claims, own documents, own payslip |
| 12 | **Employee** | `SELF` | Self-service only |
| 13 | **Auditor (read-only)** | `TENANT` | Reads everything permitted, changes nothing, every read audited |
| 14 | **Configuration Author** | `TENANT` | Drafts and simulates rules, formulas, workflows; cannot publish |
| 15 | **Configuration Approver** | `TENANT` | Publishes what an author drafted |

Platform roles, in the platform tenant only:

| # | Platform role | Scope | Purpose |
|---:|---|---|---|
| P1 | **Platform Administrator** | `PLATFORM` | Tenants, staff, releases, licences, rule-pack signing |
| P2 | **Platform Support** | `PLATFORM`, **read-diagnostic only** | Support: health, configuration metadata, no business PII by default (R-XXII-130) |
| P3 | **Compliance Publisher** | `PLATFORM` | Signs and publishes statutory rule packs (spine S12 D-03) |

#### The matrix

| Permission group | Tenant Admin | Access Admin | HR Manager | Branch HR | Payroll Prep | Payroll Appr | Finance | Accounts | Compliance | Rep. Manager | Field Agent | Employee | Auditor | Config Author | Config Appr |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `tenant.settings.*` | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | view | ○ | ○ |
| `tenant.subscription.*` | ● | ○ | ○ | ○ | ○ | ○ | ○ | view | ○ | ○ | ○ | ○ | view | ○ | ○ |
| `access.role.*` | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | list | ○ | ○ |
| `access.assignment.*` | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| `access.user.*` | ● | ● | list | list | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | list | ○ | ○ |
| `access.apikey.*` | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | list | ○ | ○ |
| `access.audit.read` | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ | ● | ○ | ○ | ○ | ● | ○ | ○ |
| `employee.record.list/view` | ● | ○ | ● | ◐ | ● | ● | ● | ● | ● | ◐ | ○ | ◐ | ● | ○ | ○ |
| `employee.record.create/update` | ● | ○ | ● | ◐ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| `employee.record.archive/delete` | ● | ○ | archive | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| `employee.field.sensitive.view` | ● | ○ | ● | ◐ | ● | ● | ○ | ○ | ● | ○ | ○ | own | ● | ○ | ○ |
| `employee.field.restricted.view` | ○ | ○ | ● | ◐ | ○ | ○ | ○ | ○ | ● | ○ | ○ | own | ○ | ○ | ○ |
| `employee.bank.view` | ● | ○ | ● | ◐ | ● | ● | ● | ○ | ○ | ○ | ○ | own | ● | ○ | ○ |
| `employee.bank.update` | ● | ○ | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| `employee.salary.view` | ● | ○ | ● | ◐ | ● | ● | ● | ○ | ○ | ○ | ○ | own | ● | ○ | ○ |
| `employee.salary.update` | ● | ○ | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| `employee.document.*` | ● | ○ | ● | ◐ | ○ | ○ | ○ | ○ | ● | ○ | own | own | download | ○ | ○ |
| `employee.export.basic` | ● | ○ | ● | ◐ | ● | ● | ● | ○ | ● | ○ | ○ | ○ | ● | ○ | ○ |
| `employee.export.sensitive` | ● | ○ | ● | ○ | ● | ○ | ● | ○ | ● | ○ | ○ | ○ | ● | ○ | ○ |
| `org.unit.*` | ● | ○ | ● | view | view | view | view | view | view | view | ○ | ○ | view | ○ | ○ |
| `master.registry.*` | ● | ○ | ● | ◐ | view | view | view | view | ● | ○ | ○ | ○ | view | ○ | ○ |
| `master.credential.update` | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| `attendance.day.view` | ● | ○ | ● | ◐ | ● | ● | ○ | ○ | ● | ◐ | own | own | ● | ○ | ○ |
| `attendance.correction.decide` | ● | ○ | ● | ◐ | ○ | ○ | ○ | ○ | ○ | ◐ | ○ | ○ | ○ | ○ | ○ |
| `attendance.bulk.*` | ● | ○ | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| `attendance.day.recalculate` | ● | ○ | ● | ○ | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| `attendance.device.*` | ● | ○ | view | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | view | ○ | ○ |
| `leave.request.decide` | ● | ○ | ● | ◐ | ○ | ○ | ○ | ○ | ○ | ◐ | ○ | ○ | ○ | ○ | ○ |
| `leave.balance.adjust` | ● | ○ | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| `payroll.run.create/preview/submit` | ● | ○ | ○ | ○ | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| `payroll.run.approve/reject` | ○ | ○ | ○ | ○ | ○ | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| `payroll.run.lock/reopen` | ● | ○ | ○ | ○ | ○ | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| `payroll.line.view` | ● | ○ | ● | ◐ | ● | ● | ● | ○ | ○ | ○ | ○ | ○ | ● | ○ | ○ |
| `payroll.payslip.view.other` | ● | ○ | ● | ◐ | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ | ● | ○ | ○ |
| `payroll.payslip.publish` | ● | ○ | ○ | ○ | ○ | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| `payroll.bankfile.preview/generate` | ○ | ○ | ○ | ○ | ○ | ○ | ● | ○ | ○ | ○ | ○ | ○ | preview | ○ | ○ |
| `payroll.disburse.mark` | ○ | ○ | ○ | ○ | ○ | ○ | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| `payroll.structure.publish` | ● | ○ | ○ | ○ | ○ | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ● |
| `statutory.config.publish` | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ● | ○ | ○ | ○ | ○ | ○ | ● |
| `statutory.rulepack.accept` | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ● | ○ | ○ | ○ | ○ | ○ | ○ |
| `statutory.return.generate` | ● | ○ | ● | ○ | ● | ○ | ● | ○ | ● | ○ | ○ | ○ | view | ○ | ○ |
| `compliance.credential.*` | ● | ○ | ● | ◐ | ○ | ○ | ○ | ○ | ● | ○ | own | own | view | ○ | ○ |
| `compliance.gate.override` | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ● | ○ | ○ | ○ | ○ | ○ | ○ |
| `request.submit` | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | own | own | own | ○ | ○ | ○ |
| `request.decide` | ● | ○ | ● | ◐ | ○ | ○ | ○ | ○ | ○ | ◐ | ○ | ○ | ○ | ○ | ○ |
| `request.money.confirm` | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| `loan.sanction` | ● | ○ | ○ | ○ | ○ | ○ | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| `commission.scheme.publish` | ● | ○ | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ● |
| `bankpack.generate` | ● | ○ | ○ | ○ | ○ | ○ | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| `rules.artefact.view/edit/simulate/submit` | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | view | ● | ● |
| `rules.artefact.publish` | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ● |
| `schema.field.manage` | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ● |
| `workflow.definition.publish` | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ● |
| `reporting.run / export` | ● | ○ | ● | ◐ | ● | ● | ● | ● | ● | ◐ | ○ | ○ | ● | ○ | ○ |
| `integration.connector.*` | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | view | ○ | ○ |
| `ops.*` | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | health | ○ | ○ |
| `messaging.*` | ● | ○ | ● | ◐ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ |
| `ess.*` | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● |
| `mss.*` | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ● | ○ | ○ | ○ | ○ | ○ |

**R-XXII-310** The four segregations below are structural in the shipped
defaults and are **asserted by test** (§XXII.14.8, §XXII.14.4):

| Segregation | Enforced by |
|---|---|
| Prepare payroll ≠ approve payroll | no default role holds both `payroll.run.submit` and `payroll.run.approve` |
| Approve payroll ≠ disburse | Finance holds `payroll.bankfile.*` and `payroll.disburse.mark`; Payroll Approver holds neither |
| Confirm money ≠ approve the claim | Accounts holds `request.money.confirm` only; the approving role holds `request.decide` only |
| Author configuration ≠ publish it | Configuration Author has no `*.publish`; Configuration Approver has no draft-editing need beyond review |

**R-XXII-311** **Tenant Administrator deliberately does not hold
`payroll.run.approve`, `payroll.bankfile.*`, `payroll.disburse.mark` or
`request.money.confirm`.** A tenant may clone and add them, accepting the loss of
segregation explicitly and with an audit record. *Origin:* SmartPRS 2's `admin`
is the sole holder of final payroll approval **and** satisfies both `isHR()` and
`isFinance()`, so there is no segregation of duties in payroll at all
(N §1.6.1; G §11 Q-11).

**R-XXII-312** **Auditor** is read-only by construction: it holds no verb other
than `view`, `list`, `read` and `preview`, and every read it performs is a
`sensitive_read` audit row. The route × role matrix asserts that no write
operation is reachable by it.

**R-XXII-313** **Platform Support** holds no permission that returns employee
PII. Where a case requires one, it is a time-boxed consented elevation
(§XXII.5.6), never a role property.

### XXII.15.2 What SmartPRS 2 data comes across

| SmartPRS 2 artefact | Disposition | How |
|---|---|---|
| `users` rows | **carried forward** | with `tenant_id` made non-nullable (platform users move into the platform tenant), `password_hash` retained, and **every account flagged for rotation** because the current policy admits `123456` (G §9) |
| `model_has_roles` pivot | **read, then discarded** | used only to derive the initial assignment per §XXII.15.3 |
| `roles` (5 global rows) | **discarded** | replaced by per-tenant system-role instances |
| `permissions` (13 rows), `role_has_permissions` | **discarded** | never read by any code (G D-08) |
| `settings.app_state → DB.roles[].perms` | **extracted, then discarded** | the per-tenant matrix customers actually configured is the only evidence of intent; it is extracted into a **report** presented to each tenant during migration, not applied automatically — the screen's own footer told users enforcement was "the next phase", so the data records an aspiration, not an operating configuration (K §7.8, §9 item 7) |
| `employees.reporting_manager_id` | **converted** | into effective-dated reporting assignments (Part V); becomes the input to `direct_reports` / `reporting_tree` |
| `api_keys` | **carried forward** | the design is correct; add `machine_user_id`, a mandatory expiry, and an assignment |
| `smartept_webhook_endpoints` | **carried forward** | |
| `activity_logs` | **carried forward, re-anchored per tenant** | the existing rows verify only as one global chain; they are imported as a sealed historical segment with a migration anchor, and the per-tenant chain starts at cutover (G §9) |
| `user_password_resets` (3 runtime definitions) | **not carried** | outstanding tokens are invalidated at cutover |
| Public portal tokens (offer, NDA, transfer, agent, quote, licence) | **invalidated** | cleartext, non-expiring, non-revocable; every holder is re-issued a hashed, expiring token (G §9) |
| `kb_topics` with `tenant_id = NULL` | **claimed or purged** | each row is assigned to a tenant or removed; the Code of Conduct becomes a versioned policy artefact with re-acknowledgement (G SEC-30) |
| `biometric_configs` with `tenant_id = NULL` | **claimed or purged** before migration | G §9 |
| Files under `storage/app/public/{employee-docs, emp-photos, commission-proofs, offroll-docs}` | **moved to private storage**, paths rewritten | and **access logs audited first** for a DPDP breach assessment (G §9) |
| `demo-*@smartprs.in`, `admin@smartprs.local` accounts | **purged** from every installation | G §9 |

### XXII.15.3 The nine role strings — the migration map

**R-XXII-314** Migration MUST NOT create a role that the source data cannot
justify, and MUST NOT silently widen anyone. Every mapping below is presented to
the tenant administrator for confirmation before cutover; nothing is applied
unreviewed.

| SmartPRS 2 role string | Users holding it | SmartPRS 3 mapping | Default scope | Notes |
|---|---|---|---|---|
| `super_admin` | platform staff | **Platform Administrator** | `PLATFORM` | moved into the platform tenant; MFA mandatory; `min:6` accounts forced to rotate at first sign-in |
| `admin` | tenant top authority | **Tenant Administrator** | `TENANT` | **does not receive** `payroll.run.approve`, `payroll.bankfile.*`, `payroll.disburse.mark` or `request.money.confirm` (R-XXII-311). The migration report names each `admin` user and asks the customer who should hold the approval and disbursement roles — this is the single most consequential question the migration asks |
| `hr_manager` | the product's centre of gravity | **HR Manager** | `TENANT` | loses the ability to create administrators and to set passwords (R-XXII-033, R-XXII-034, R-XXII-221). The migration report states this explicitly, because it is a deliberate reduction |
| `employee` | back-office staff | **Employee** | `SELF` | unchanged in intent; `SELF` now actually narrows, for the first time |
| `field_agent` | the headline persona | **Field Agent** | `SELF` | in SmartPRS 2 its seeded permission set is byte-identical to `employee` and it appears in **zero** write guards, so its shipped behaviour is "employee with no narrowing". The v3 role is built from the *business* description (punch, claim, own documents, own credentials), not from the v2 permission data, because that data expresses nothing (N §1.6.1) |
| **`accountant`** | **users the administrator believed they had created** | **Accounts (money verification)** | `TENANT` | See below — this is the defect case |
| **`manager`** | none — uncreatable | **Reporting Manager** | `direct_reports` depth 1 | The string is referenced in three `FeaturePermissions` lists that are consequently inert (`:47, 51, 67`). In v3 it becomes a real role whose scope resolves from the effective-dated reporting line. **No user is auto-assigned it**; the migration proposes it for every employee who is a reporting manager in the data, for the customer to confirm |
| **`hr`** | none — uncreatable | **no mapping; string retired** | — | A phantom distinct from `hr_manager`, checked at `FeaturePermissions.php:55` and `WaTemplateController.php:209`, creatable nowhere. It is almost certainly a typo-tier duplicate (N §1.6.1). Nothing is migrated; the two check sites become `HR Manager` permissions |
| **`platform_support`** | Ametecs support staff | **Platform Support**, radically narrowed | `PLATFORM`, diagnostic only | In v2 it is created with `tenant_id = NULL` — the **widest** scope in the product — checked by zero guards, and rendered as "Admin". In v3 it holds no permission returning employee PII (R-XXII-130) and reaches customer data only through consented, time-boxed impersonation. **Every existing `platform_support` account is disabled at cutover and re-provisioned individually**, because its historical access cannot be reconstructed from any record |

#### The four non-functional strings — what is actually done

**`accountant` — the defect case, and the one with live data damage.**
The role was offered in the dropdown, `syncRoles(['accountant'])` threw, the
exception was swallowed, and the API returned `{"ok":true}`
(`UserController.php:26–32, 150–152, 298–308`; G D-05). Every administrator who
chose it produced a **role-less** account which the SPA rendered as "Admin"
(`AppController.php:93, 769`) and which `selfScope()` did not narrow
(`AppDataController.php:224–232`) — a read-everything account presenting as
Administrator.

**R-XXII-315** The migration MUST identify **every** user with no row in
`model_has_roles`, list them individually with their creation date, creator and
last sign-in, and present them to the tenant administrator as
*"these accounts have had unrestricted read access to your tenant"*. None is
migrated with a role. Each is either assigned a role explicitly by the customer
or disabled. *This is a disclosure obligation as much as a migration step.*

**R-XXII-316** The v3 **Accounts** role MUST hold `request.money.confirm` and
nothing that grants salary, bank or payroll visibility, because the business
purpose is confirming that collected money arrived — not seeing what anyone is
paid. This also restores the two `denyUnlessRole` sites that already list
`accountant` (`RequestController.php:1851`, `BankPackController.php:64`) and have
never functioned.

**`manager`.** Becomes a real role (above). The migration proposes it; it does
not assign it.

**`hr`.** Retired. Its two check sites are re-expressed as HR Manager
permissions. Nothing is migrated because nothing exists.

**`platform_support`.** Disabled at cutover and re-provisioned individually
(above).

**R-XXII-317** Every user whose SmartPRS 2 role cannot be mapped with confidence
MUST be migrated to **no assignment** and surfaced on the migration exception
report. An unmappable user is never given a default role, and never given
`admin`. *(Law 5 — fail closed, applied to migration.)*

### XXII.15.4 Cutover sequence for this area

| # | Step | Gate |
|---:|---|---|
| 1 | Extract `app_state → DB.roles[].perms` per tenant into the intent report | report reviewed by the customer |
| 2 | Enumerate users, roles, role-less users, null-tenant users, `platform_support` accounts | exception report signed off |
| 3 | Seed the 15 system roles + 3 platform roles into every tenant | catalogue checksum verified |
| 4 | Build the effective-dated reporting and org assignments (Part V dependency) | scope preview counts reconcile against the customer's own headcount |
| 5 | Propose assignments per §XXII.15.3; **customer confirms** | no assignment created without confirmation |
| 6 | Publish auth policy at the platform floor or above; flag every account for rotation | non-compliant count reported |
| 7 | Enrol MFA for every privileged holder within the grace period | assignments suspend, not delete, on expiry (R-XXII-194) |
| 8 | Move documents to private storage; rewrite paths; audit prior access logs | DPDP assessment complete |
| 9 | Invalidate all portal tokens; re-issue hashed, expiring ones | old tokens verified dead |
| 10 | Purge `demo-*@smartprs.in` and `admin@smartprs.local`; verify no deny-list hash exists | R-XXII-230 check passes |
| 11 | Seal the imported audit segment; start per-tenant chains | both segments verify |
| 12 | Run the full security suite (§XXII.14) against the migrated tenant | **all gates green, or no cutover** |

---

## XXII.16 Edge cases and failure states

Unglamorous, specific, and each with a defined behaviour. "Undefined" is not an
acceptable answer anywhere in an authorisation model.

| # | Situation | Defined behaviour |
|---:|---|---|
| 1 | User has no assignment in force today, but one starting tomorrow | Empty permission set today. The "no access configured" page names the start date. |
| 2 | User's only assignment expired overnight mid-session | Denied on the next request; session ended with reason `revoked`; user notified. |
| 3 | An assignment's role was retired after the assignment was made | The assignment resolves the **published version in force at the assignment's effective date**; a retired role continues to authorise historical operations and authorises nothing new after `retired_on`. The user is listed on the "assignments needing attention" report. |
| 4 | An assignment's scope was retired | Same treatment; the scope resolves at the operation's business date. If the scope version in force is retired at that date, the assignment contributes nothing and the user sees the reason in `Explain`. |
| 5 | An employee is transferred out of a manager's branch mid-month | The manager reaches them for dates before the transfer and not after, because `as_of` is the operation's business date (R-XXII-056). A pending leave request spanning the transfer routes by the workflow's own participant resolution, not by scope. |
| 6 | An employee has two overlapping org assignments (data defect) | Both are honoured for scope membership (the employee is reachable from either), the condition is raised on the data-quality report, and the org module's own validator refuses to create the overlap going forward. |
| 7 | An employee has **no** org assignment on the date | Reachable only by `tenant` and `self` scopes; listed on the exception report (R-XXII-057). |
| 8 | A reporting cycle exists (A reports to B reports to A) | The walk terminates, the operation **denies**, `access.reporting_cycle_detected` is raised, and the cycle is named on the report. Never an unbounded walk, never a partial result treated as complete. |
| 9 | A manager's own record is inside their `reporting_tree` scope via a cycle | Same as 8; `include_self` is the only legitimate way to reach oneself. |
| 10 | A user is their own reporting manager | Rejected at the org module's validator; if present in migrated data, treated as case 8. |
| 11 | A user holds two assignments granting the same permission with different scopes | Union of reach for that permission (R-XXII-052). Explain names both. |
| 12 | A user holds an assignment whose role is unpublished (draft) | The assignment could not have been created (R-XXII-023); if found by the integrity job, it is suspended and alerted. |
| 13 | A privileged holder never enrols MFA | After the grace period the assignment is **suspended**, not deleted; the user retains their non-privileged assignments; both the user and the grantor are notified. Reinstatement on enrolment is automatic and audited. |
| 14 | The last Tenant Administrator is disabled or their assignment expires | Refused: the tenant must retain at least one active, MFA-enrolled holder of `access.assignment.grant`. The refusal names who else could hold it. |
| 15 | The last Tenant Administrator loses MFA access entirely | The platform recovery procedure (R-XXII-226) — consented, time-boxed, audited on both ledgers. Never an email bypass. |
| 16 | Every platform administrator is locked out | Documented break-glass at the infrastructure layer, outside the application, requiring two named Ametecs officers. Recorded here so it is designed rather than improvised. |
| 17 | A user is deleted in the IdP but SCIM is not yet implemented | Their SSO sign-in fails; their session persists to its absolute lifetime (bounded, R-XXII-205); the tenant's own de-provisioning closes the assignment. The absolute lifetime is the reason this is bounded rather than indefinite. |
| 18 | An SSO IdP certificate expires | Every SSO sign-in fails for that tenant. Alerts fire from 30 days before (R-XXII-204); break-glass accounts remain available. |
| 19 | A tenant enables `sso.mode = required` with no break-glass account | Refused at publish: the policy validator requires at least one. |
| 20 | A user exists in two tenants with the same email | Legitimate. `U(tenant_id, email)`. Sign-in resolves the tenant from the portal, the SSO binding, or an explicit tenant selection — never by guessing. |
| 21 | An employee record is archived while its user holds assignments | Assignments close on the exit date; `self` scope resolves to the empty set thereafter; historical audit rows still resolve the identity. |
| 22 | An employee is rehired | A **new** grant is required. Prior assignments are not restored (§XXII.12.2). |
| 23 | A scope is edited to remove a branch while a run is mid-approval | The in-flight workflow instance completes on its own participant resolution (Part IX §IX.6.6); the scope change affects subsequent operations. Explain shows both versions. |
| 24 | Two administrators grant conflicting assignments simultaneously | Both succeed; the union is the effect. There is no negative permission to conflict with (R-XXII-039). The unique index prevents the identical grant twice on one day. |
| 25 | A role publication reduces a permission a user is currently exercising | Effective on the next request. An in-flight multi-step operation that began authorised completes only if each subsequent request is independently authorised — there is no "authorised for the duration of a wizard". |
| 26 | The materialised effective-permission table drifts from its declared grants | Startup checksum fails; the application refuses to serve (R-XXII-094 row 10). A silent drift in an authorisation cache is worse than downtime. |
| 27 | The permission catalogue gains entries in a release | New permissions are granted to **no** role automatically, including system roles. The upgrade report lists them and the tenant acknowledges before they take effect (R-XXII-020). |
| 28 | A permission is deprecated in a release | Operations naming it fail the build in the next release; existing grants remain readable so historical audit rows resolve. |
| 29 | An operation is added without a permission | Build failure (R-XXII-074). Not a runtime condition. |
| 30 | A tenant's clock and the platform's clock disagree | Effective dating uses the business date resolved through the tenant's declared timezone; assignment boundaries are evaluated in that timezone consistently, and the boundary case (an assignment expiring at midnight) is asserted by test. |
| 31 | A signed URL is redeemed exactly at its expiry instant | Refused. The boundary is exclusive and asserted. |
| 32 | A credential is rotated while a consumer is mid-request | The overlap window covers it (R-XXII-245). Without an overlap window this is data loss on an ingestion path. |
| 33 | A credential's IP allow-list is misconfigured to exclude the live consumer | The consumer fails closed with a distinct reason code visible in the credential's usage log, so the operator can see *why* rather than guessing. |
| 34 | The audit ledger is unavailable | Writes fail (R-XXII-103). The system stops accepting mutations rather than accepting unaudited ones. Reads continue. |
| 35 | An impersonation session is live when the target user is disabled | The session ends immediately with reason `revoked`. |
| 36 | An impersonated actor triggers a workflow | The instance records both identities; the segregation rules evaluate against the **acting** identity, not the impersonated one, so impersonation cannot be used to satisfy a two-person rule. |
| 37 | A tenant is suspended while a payroll approval is in flight | `STATE_DENIED` on every mutation; the instance is preserved; nothing is lost. |
| 38 | A tenant is deleted with live impersonation sessions | Sessions end; the impersonation records survive under the platform's retention, because they are evidence. |
| 39 | Migration finds a user with a role string that no longer exists in any registry | No assignment; exception report (R-XXII-317). |
| 40 | Migration finds a tenant whose `app_state` matrix grants a permission the catalogue has no equivalent for | Reported, not translated. A guessed mapping in an authorisation model is a silent widening. |

---

## XXII.17 Tests requiring exact-value regression assertions

Beyond the security suite (§XXII.14), the following need **exact expected
values** asserted, because they are the arithmetic and boundary behaviour of this
part.

| # | Scenario | Exact assertion |
|---:|---|---|
| 1 | Scope preview for a branch scope with descendants, on a fixed fixture | exactly 214 employees on 12-Aug-2026; exactly 209 on 12-Jul-2026 |
| 2 | `reporting_tree(depth = 2)` on a fixture with 6 → 19 → 4 | exactly 25 reachable; the 4 at level 3 excluded, named in the assertion |
| 3 | `reporting_tree(depth = NULL)` on the same fixture | exactly 29 |
| 4 | `include_self` toggled | exactly 25 vs 26 |
| 5 | Two assignments, one branch-scoped update and one tenant-scoped view | update reaches exactly 214; view reaches exactly 2,020; the cross-product (2,020 updatable) is asserted **absent** (R-XXII-050) |
| 6 | Permission closure over `implies` | `payroll.run.approve` materialises to exactly the declared closure set; count and members asserted |
| 7 | Assignment boundary at midnight in the tenant's timezone | authorised at 23:59:59 on `effective_to`, denied at 00:00:00 the next day |
| 8 | Lockout threshold | denied on attempt N+1 exactly; the (N+1)th failure within the window locks; a failure outside the window does not |
| 9 | Lockout expiry | authorised at `locked_until` + 1 second, denied at `locked_until` − 1 second |
| 10 | Password history depth | the (depth)th previous password is refused; the (depth+1)th is accepted |
| 11 | Token expiry | valid at TTL − 1 s, refused at TTL exactly |
| 12 | Signed URL single-use | second redemption refused, with the exact error code |
| 13 | Credential rotation overlap | old secret valid at `overlap_until` − 1 s, refused at `overlap_until` |
| 14 | Session absolute lifetime | terminated at the absolute boundary regardless of activity; the exact end reason recorded |
| 15 | Idle vs absolute | a session active throughout still ends at the absolute boundary |
| 16 | MFA grace period | assignment active on day (grace), suspended on day (grace + 1) |
| 17 | Masking rule `last4` on a fixed PAN and Aadhaar | exact rendered strings asserted, on every surface in the matrix |
| 18 | Export profile PII ceiling | a profile with `max_pii_class = identifying` emits exactly the declared column list and refuses the sensitive column by name |
| 19 | Cross-tenant fixture | Tenant A's list returns exactly A's row count; the identical `emp_code` in B is asserted absent by id |
| 20 | Audit chain | A's chain verifies after exactly N interleaved B writes; the verify window is bounded and the memory ceiling asserted |
| 21 | Effective permission set for each of the 15 shipped roles | the exact permission code list, asserted as a set — this is the regression guard on the matrix in §XXII.15.1 |
| 22 | Segregation | for each of the four segregations, the exact assertion that no shipped role's effective set contains both codes |

---

## XXII.18 Compliance with the seven laws

| Law | How this part complies | Test |
|---|---|---|
| **1 — No business literal** | Role names, permission grants, scope definitions, password policy, lockout counts, session durations, token TTLs, masking rules, rate limits and MFA enforcement are all rows in versioned artefacts or platform constant tables (§XXII.9). The permission *catalogue* is code-adjacent by design (R-XXII-010) because a permission binds to an operation. | static analysis on `Domain\Access`; the configuration surface table |
| **2 — No runtime schema change** | No DDL outside migrations; the production database user holds no DDL grant (R-XXII-086). | CI grep + the privilege check |
| **3 — Versioned and dated** | Roles, scopes and auth policies are S4 artefacts; assignments are effective-dated rows closed rather than deleted; scope resolves at the operation's business date (R-XXII-056); roles are retired, never deleted (R-XXII-027). | "what could this user do on date D?" answered by one service call |
| **4 — Every number explains itself** | `Explain(actor, operation, subject, as_of)` returns the full decision trace naming every artefact version (§XXII.13). | a decision without a trace throws |
| **5 — Authorisation server-side, declarative, fails closed** | The whole of §XXII.4. Operation binding asserted by CI sweep; fail-closed proven by fault injection; UI gating prohibited. | §XXII.14.5, §XXII.14.11 |
| **6 — Tenancy structural** | §XXII.5: non-nullable, foreign-keyed, leading every unique index, applied by the persistence layer, cross-tenant reach only through an audited allow-listed escape. | the generated isolation suite, §XXII.5.7 |
| **7 — Money exact** | Not this part's arithmetic. This part protects the money paths: segregation of duties in payroll, `request.money.confirm`, bank-file gating, and the `payroll.*` matrix. | §XXII.14.8 |

---

## XXII.19 Open items — PRODUCT OWNER DECISION REQUIRED

Each carries a recommendation. Several restate a question the as-is analysis
raised about the shipped product; those are cross-referenced.

| # | Decision | Options and trade-offs | Recommendation |
|---|---|---|---|
| **D-XXII-01** | **May tenant administrators define their own roles, or only choose from the Ametecs catalogue?** *(G Q-13 — "cannot be deferred; it changes the schema".)* | **A. Tenant-defined (recommended).** Meets the real requirement — "Branch Manager", "Payroll Officer", "Auditor" — without a release. Costs: per-tenant role tables, a role editor, maker-checker, and support cases where a customer misconfigures. **B. Vendor catalogue only.** Simpler support; every customer's needs become an Ametecs backlog item, which is precisely how SmartPRS 2 arrived at five hard-coded roles in five disagreeing registries. | **A.** With system roles shipped as a strong default (§XXII.15.1) so most customers never author one, and cloning as the only derivation path. |
| **D-XXII-02** | **Which data scopes does the business actually need at v3.0?** *(G Q-10 — "blocks the RBAC design".)* | The thirteen in §XXII.3.2 are proposed. Each unused type is a resolver, a UI affordance and a test matrix to maintain. `dimension_filter` is the most powerful and the most likely to be misconfigured. | Ship all thirteen; the resolvers are small and the contract is uniform (R-XXII-041). Default the **wizard** to `self`, `direct_reports`, `branch`, `department` and `tenant`, and put the rest behind "advanced", so the common case stays simple. |
| **D-XXII-03** | **Does Tenant Administrator hold payroll approval by default?** *(G Q-11.)* | **A. No (recommended, as specified in R-XXII-311).** Segregation of duties is real from day one; some customers will find it inconvenient and clone a wider role. **B. Yes.** Matches SmartPRS 2 and every customer's current habit; discards the control the rebuild exists to introduce. | **A**, with a clear migration message and a documented one-click clone for customers who genuinely have one person. The clone is audited, which is the whole difference. |
| **D-XXII-04** | **Is a two-person rule mandatory on payroll approval, or configurable?** | **A. Configurable, default on.** **B. Mandatory.** A single-person customer with four employees will not accept B and will not buy. | **A.** Default on; disabling it is a maker-checker change with an audit record and a warning naming the control being removed. |
| **D-XXII-05** | **What is Ametecs support contractually permitted to see, and under what consent?** *(G Q-05.)* | **A. Diagnostic-only by default, consented time-boxed elevation for PII (recommended).** **B. Standing read access.** B must then appear in every customer contract and DPDP notice, and is a poor answer to an enterprise security questionnaire. | **A** — R-XXII-130, R-XXII-133. The default consent mode is `always_require` for anything beyond read-only. |
| **D-XXII-06** | **Is MFA mandatory for privileged roles from v3.0, or phased?** | **A. Mandatory at v3.0 (recommended).** Some on-prem customers will resist. **B. Phase in.** Every phase-in in this dossier's history became permanent. | **A**, with a configurable grace period per tenant (default modest) and assignment **suspension** rather than deletion at expiry, so nobody loses configuration. |
| **D-XXII-07** | **Is SMS OTP required as an MFA method?** | SmartPRS 2 has **no SMS provider adapter at all** (N §1.7.2). Adding one for MFA means owning delivery reliability for an authentication path. | **Defer to v3.1.** TOTP plus email OTP plus recovery codes covers the requirement; revisit only if a named customer contract demands SMS. |
| **D-XXII-08** | **Must an unauthenticated device-ingestion path remain for legacy terminals?** *(G Q-14.)* | **A. No — the Bridge is the supported path (recommended).** Closes the highest-volume unauthenticated write in the product. Costs: some installed terminals need the Bridge deployed. **B. Yes, per-tenant opt-in with an IP allow-list.** | **A** as the default, **B** available per tenant with an explicit, audited acceptance of residual risk (R-XXII-253). |
| **D-XXII-09** | **What happens to the roughly sixty collections still inside `app_state`, and to the permission matrices customers configured there?** *(G Q-15, K §9 items 6–7.)* | The matrix records what customers *intended*, on a screen whose own footer said enforcement was "the next phase". Applying it automatically would enforce, for the first time, a configuration nobody validated. | **Extract and report, do not apply** (§XXII.15.2). Present each tenant with its own configured matrix beside the proposed system-role mapping, and let the customer choose. |
| **D-XXII-10** | **Are password expiry and complexity on by default?** | Modern guidance favours length plus breach checking over rotation and composition; many Indian enterprise and BFSI customers' own policies mandate both. | **Off by default, prominently configurable**, with the platform floor enforcing length and breach checking always. A customer whose auditor requires 90-day rotation enables it in one place. |
| **D-XXII-11** | **Do we ship SCIM at v3.0?** | SSO without SCIM leaves de-provisioning to the customer's own user administration; the bounded absolute session lifetime limits the exposure window. | **Defer to v3.1** (R-XXII-203). Ship SAML and OIDC at v3.0; build the identity schema so SCIM needs no migration. |
| **D-XXII-12** | **Retention and residency for identity documents, audit and backups.** *(G Q-12.)* | None exists today; documents are excluded from the backup entirely while `.env` is included (L SEC-L-06). | The compliance owner (spine S12 D-03) sets each retention class before v3.0 ships. This part declares the classes; it does not assert the durations. |
| **D-XXII-13** | **DPDP breach assessment on the current public-disk exposure.** *(G Q-01 — "the single highest-value question in the dossier".)* | Whether live installations are serving Aadhaar, PAN and bank documents without authentication cannot be determined from the repository, because `public/.htaccess` is not in version control. | **Answer before v3.0 planning completes.** It is a legal question with a fixed clock, and it determines whether the document migration (§XXII.15.2) is a migration or an incident response. |

---

## XXII.20 Requirements index

| Range | Subject | Section |
|---|---|---|
| R-XXII-001 – 005 | The five foundational rules: triple, no literals, fail closed, structural tenancy, no default credential | XXII.0 |
| R-XXII-010 – 018 | Permission grammar, catalogue **extensibility (010, 010a–010c: two paths — Ametecs-authored rows, and three reserved generative namespaces for tenant-created artefacts)**, verbs, no record sets, implications, assurance, module registry, ESS subject resolution, catalogue export | XXII.2.1–2.2a |
| R-XXII-019 – 027 | Roles as artefacts; system vs custom; relational storage; maker-checker; assignability; unknown-role refusal; tenant scoping; privileged roles; retirement | XXII.2.3 |
| R-XXII-028 – 036 | Assignments: the triple, effective dating, multiple assignments, union of permissions not scopes, time-boxing, no grant above self, no credential action above self, audit, empty set for no assignment | XXII.2.4 |
| R-XXII-037 – 039 | Permission implication closure; no role hierarchy; no negative permissions | XXII.2.5 |
| R-XXII-040 – 049 | Scope types, resolver contract, `self`, reporting line, depth and cycles, unit FKs, descendants, dimension filters, system scopes, versioning | XXII.3.2–3.3 |
| R-XXII-050 – 058 | Composition: the existential rule, denial attribution, list disjunction, org-unit scoping, write-side both-sides check, per-request caching, `as_of`, unassigned employees, unconditional tenancy intersect | XXII.3.4–3.5 |
| R-XXII-059 – 061 | What a scope is not: fields, tenancy, commercial gating | XXII.3.7 |
| R-XXII-070 – 072 | Pipeline order, reason codes, UUID-only external ids | XXII.4.1 |
| R-XXII-073 – 077 | Operation registry, build-failing sweep, unauthenticated allow-list, the eight sweep assertions, generator input | XXII.4.2 |
| R-XXII-078 – 079 | No module-local guards; actor-dependent rules belong to the workflow | XXII.4.3 |
| R-XXII-080 – 086 | Query scoping: decorator, throw without context, deleting a filter breaks a test, write stamping, cross-tenant escape hatch, raw SQL allow-list, no DDL | XXII.4.4 |
| R-XXII-087 – 090 | Capability payload, UI enforces nothing, client fails closed, unmapped screens unreachable | XXII.4.5 |
| R-XXII-091 – 093 | Field projection, masking server-side, unmasking as an operation | XXII.4.6 |
| R-XXII-094 – 097 | The fail-closed table, no allowing `catch`, fault injection, 404 vs 403 | XXII.4.7 |
| R-XXII-098 – 100 | Job and system-actor contexts; explicit tenant iteration | XXII.4.8 |
| R-XXII-101 – 104 | The audited event list, sensitive reads, audit-failure rollback, per-tenant chain | XXII.4.9 |
| R-XXII-105 – 107 | Identity-keyed rate limits, credential endpoints, uniform rejection | XXII.4.10 |
| R-XXII-110 – 118 | Tenancy: non-nullable, no sentinel, index leadership, composite FKs, storage partitioning, tenant from the principal, credential binding, positive cross-tenant grant | XXII.5.1 |
| R-XXII-119 – 120 | The prohibition; platform-owned reference data as a declared class | XXII.5.2 |
| R-XXII-121 – 123 | Provisioning, suspension, deletion | XXII.5.3 |
| R-XXII-124 – 127 | Derived surfaces, cache keys, event tenancy, bounded audit verification | XXII.5.4 |
| R-XXII-128 – 130 | Platform staff, the seven properties of platform access, support role limits | XXII.5.5 |
| R-XXII-131 – 136 | Impersonation: model, assurance, consent, marking and intersection, forbidden operations, tenant termination | XXII.5.6 |
| R-XXII-137 – 141 | The generated isolation suite: coverage, fixture, twelve assertions, null-tenant cases, release blocking | XXII.5.7 |
| R-XXII-150 – 155 | PII propagation, class changes, redaction, credential storage, write-only credentials, key separation | XXII.6.1–6.2 |
| R-XXII-156 – 158 | Masking rules, server-side application, surface consistency | XXII.6.3 |
| R-XXII-159 – 162 | Export profiles, sensitive and restricted exports, artefact protection, bulk download | XXII.6.4 |
| R-XXII-163 – 168 | Content validation, malware scanning, server-generated keys, no caller paths, re-encoding, configurable caps | XXII.6.5 |
| R-XXII-169 – 173 | **No private document on a public path**, gated serving, signed-URL binding, portal tokens, deployment independence | XXII.6.6 |
| R-XXII-174 – 175 | Retention classes and purge | XXII.6.7 |
| R-XXII-180 – 182 | Policy as configuration with floors; stricter platform floor; maker-checker | XXII.7.1 |
| R-XXII-183 – 188 | Strength, breach checking, history, expiry, invalidation on change, hashing | XXII.7.2 |
| R-XXII-189 – 193 | Lockout, multi-key throttling, unlock paths, anti-DoS ordering, uniform failures | XXII.7.3 |
| R-XXII-194 – 198 | Mandatory MFA by risk class, methods, reset controls, remembered devices, assurance enforcement | XXII.7.4 |
| R-XXII-199 – 204 | SAML and OIDC, per-tenant configuration, claims are not permissions, break-glass, SCIM deferral, certificate monitoring | XXII.7.5 |
| R-XXII-205 – 210 | Idle and absolute lifetimes, cookie flags, payload encryption, concurrency, per-request re-evaluation, remember-me | XXII.7.6 |
| R-XXII-211 – 214 | Login events, user visibility and notification, anomaly signals, mobile device binding | XXII.7.7 |
| R-XXII-215 – 219 | One token table, configurable TTLs, entropy, invalidation on consume, issuance throttling | XXII.7.8 |
| R-XXII-220 – 223 | No password at provisioning, no admin-set passwords, activation policy, interactive installer | XXII.7.9 |
| R-XXII-224 – 226 | Reset, MFA recovery, tenant lockout recovery | XXII.7.10 |
| R-XXII-227 – 232 | **No default credential**, demo as a separate artefact, triple-guarded dev paths, build-failing literal check, documentation check, installer health check | XXII.7.11 |
| R-XXII-240 – 253 | Machine identity: actors, scopes as permissions, gating, one-time secrets, mandatory expiry, rotation overlap, expiry warnings, revocation, plaintext-HTTP refusal, per-credential limits, idempotency, webhook signing, egress allow-list, device credentials | XXII.8 |
| R-XXII-260 | Typed policy payloads, not JSON | XXII.10.18 |
| R-XXII-270 – 274 | Explainability: the trace, versions, non-disclosure, aggregate reachability, reproducible denials | XXII.13 |
| R-XXII-300 – 304 | Suite runs in CI and blocks release; generation; negative assertions mandatory; coverage gates | XXII.14 |
| R-XXII-310 – 317 | The four segregations, Tenant Administrator's deliberate exclusions, Auditor read-only, Platform Support limits, migration confirmation, role-less-user disclosure, the Accounts role, fail-closed migration | XXII.15 |

**Totals:** **239 numbered, testable requirements** · **18 specified tables** to
the Section 123 standard (13 tenant-owned, 3 platform catalogues, 2 identity
tables carried forward and redesigned) plus **7 tables explicitly deleted** ·
**13 PRODUCT OWNER DECISIONS** · 1 complete permission catalogue (approximately
150 codes across 13 groups) · 1 role × permission matrix over 15 system roles
and 3 platform roles · 1 migration map covering all 9 SmartPRS 2 role strings ·
40 enumerated edge cases · 22 exact-value regression scenarios · 15 security
test-suite areas with 7 release gates.

---

## XXII.21 Dependencies on other parts

| This part depends on | For |
|---|---|
| **Part V — Domain model** | `org_units`, effective-dated org and reporting assignments; every unit-based and reporting-based scope resolves against them (R-XXII-043, R-XXII-045) |
| **Part VI — Configuration architecture** | the artefact/version/publish machinery roles, scopes and auth policies are instances of (R-XXII-019, R-XXII-049) |
| **Part IX — Workflow engine** | maker-checker on role publication, assignment grants, credential actions and privileged operations; segregation sets and the self-approval prohibition (R-XXII-022, R-XXII-079, §XXII.3.6) |
| **Part X — Employee schema engine** | field definitions, surface rules, PII classes and masking rules that §XXII.4.6 and §XXII.6 enforce |
| **Part XIV/XV/XVI — Payroll** | the operations the payroll permissions gate, and the period-lock state gate |
| **Part XVII — Statutory** | rule-pack signing, acceptance and the compliance-owner decision (spine S12 D-03) |
| **Part XXVIII — Database architecture** | the schema registry's tenant-owned / platform-owned table classes (R-XXII-120), the migration linter (R-XXII-112) |

| Parts that depend on this one | For |
|---|---|
| Every module part | its operations' permissions, and the assumption that a handler reached is a handler authorised (R-XXII-078) |
| The API part | the security blocks in the generated OpenAPI, the error envelope, and scope-as-permission for OAuth2 |
| The deployment part | the installer health check (R-XXII-232), the no-default-credential gate, and the private-storage reachability check |
| The testing part | the generated matrices and the release gates in §XXII.14.15 |
