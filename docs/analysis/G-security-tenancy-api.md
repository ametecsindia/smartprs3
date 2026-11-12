# AS-IS ANALYSIS — AUTHENTICATION, AUTHORIZATION/RBAC, MULTI-TENANCY, HTTP SURFACE, USER & STAFF ADMINISTRATION, SECURITY POSTURE

> Every claim below was re-derived from `/home/claude/prs2/` source. Where the reference
> documents and the source disagree, the source is recorded and the discrepancy is listed in §8.

---

## 0. Scope and evidence base

### 0.1 Reference documents read

| Document | Lines read | Purpose |
|---|---|---|
| `COMPLETE_PROJECT_BUSINESS_AND_TECHNICAL_DOCUMENTATION.md` | 23889–27081 (Section 13 — Auth/Authz/Admin/Config/Dashboards/Errors/Logging/Backups) — §§0,1,2,3,4,5,6,7,8,15.1,15.2,15.3,16 read in full | primary doc for this area |
| `COMPLETE_PROJECT_BUSINESS_AND_TECHNICAL_DOCUMENTATION.md` | 34094–36592 (Section 11 — API & Route Reference) — structure + §§1.1–1.5, 2, 3, 5, 6 | route surface |
| `API_REFERENCE.md` | full outline + §§1.1–1.5, 2.x index, 3, 5, 6 | route surface (same content, standalone) |
| `SECURITY_AND_TECHNICAL_DEBT_REPORT.md` | 1–700 in full (executive summary, severity counts, complete Top-15 + ranks 16–20), plus §5 cross-cutting index and §6 secrets index | primary security reference; every Top-15 item touching this area re-verified in source (§7.1) |
| `PROJECT_ARCHITECTURE_AND_WORKFLOWS.md` | 472–803 (auth/authz/tenancy architecture) | architecture claims |

### 0.2 Source files read (all under `/home/claude/prs2/`)

| File | Lines | Read |
|---|---:|---|
| `routes/web.php` | 696 | complete |
| `routes/api.php` | 43 | complete |
| `routes/updates.php` | 61 | complete |
| `routes/smartept.php` | 35 | complete |
| `routes/console.php` | 265 | schedule block (143–265) complete |
| `app/Http/Controllers/AuthController.php` | 543 | complete |
| `app/Http/Controllers/UserController.php` | 355 | complete |
| `app/Http/Controllers/StaffController.php` | 114 | complete |
| `app/Http/Controllers/TenantController.php` | ~95 | complete |
| `app/Http/Controllers/SaasController.php` | 588 | complete |
| `app/Http/Controllers/ApprovalService.php` | 381 | complete |
| `app/Http/Middleware/ApiKeyAuth.php` | 156 | complete |
| `app/Http/Middleware/DemoWriteGuard.php` | 79 | complete |
| `app/Http/Middleware/EditionGuard.php` | 61 | complete |
| `app/Http/Middleware/EnsureSubscriptionActive.php` | 122 | complete |
| `app/Http/Middleware/LicenseGate.php` | 77 | complete |
| `app/Http/Middleware/SmarteptSignature.php` | 112 | complete |
| `app/Models/User.php` | 56 | complete |
| `app/Models/Company.php` | 59 | complete |
| `app/Models/Scopes/CompanyScope.php` | 35 | complete |
| `app/Models/Concerns/BelongsToCompany.php` | 34 | complete |
| `app/Services/FeaturePermissions.php` | 128 | complete |
| `app/Services/Edition.php` | 181 | complete |
| `app/Services/ApiKeys.php` | 78 | complete |
| `app/Services/Audit.php` | 133 | 85–133 |
| `config/auth.php`, `config/session.php`, `config/smartprs.php`, `config/filesystems.php` | — | complete |
| `config/permission.php`, `config/sanctum.php` | — | key sections |
| `database/seeders/RolePermissionSeeder.php`, `SuperAdminSeeder.php`, `DatabaseSeeder.php` | — | complete |
| `database/seeders/OnPremWorkspaceSeeder.php` | 100+ | relevant blocks |
| `database/migrations/2026_05_30_025615_create_permission_tables.php` | — | schema names |
| `database/migrations/2026_01_01_000002_add_company_id_to_users_table.php` | 26 | complete |
| `database/migrations/2026_01_01_000001_create_companies_table.php` | 101 | complete |
| `database/migrations/2026_01_01_000100_create_employees_table.php` | 192 | complete |
| `database/migrations/0001_01_01_000000_create_users_table.php` | 49 | complete |
| `tests/Feature/AuthAccessTest.php` | 21 | complete |
| Verification reads (targeted): `AppController.php` (:15–21, :60–130, :640–830, :3330), `AppDataController.php` (:205–280, :1140–1180, :1636–1665), `AppStateController.php` (54, complete), `MasterController.php` (:33–480 defs, :580–730, :1055–1200), `SalaryApprovalController.php` (:55–70 + guard map), `RequestController.php` (:120–175, :495–515, :1610–1640), `DocumentController.php` (:1–60, :200–290), `SelfOnboardingController.php` (:95–170, :300–340, :420–500, :935–975), `DemoAccessController.php` (:340–470), `InstallController.php` (:1–60, show/store), `PushController.php` (:25–145, `ensure()`), `ApiKeyController.php` (:46–520), `LetterController.php` (buildLetter/idcard/servePhoto), `ConfigController.php` (:35–60, guard map), `DashboardController.php` (:40–105), `TestController.php` (:205–260), `KbController.php` (:40–175), `Edition.php`, `DemoReset.php` (:25–160) | — | — |

### 0.3 Files the analysis expected and that are ABSENT from this source copy

**Critical for interpretation.** These are cited by the reference documents but are not present in `/home/claude/prs2/`:

| Missing | Consequence |
|---|---|
| `bootstrap/` (entire directory, incl. `bootstrap/app.php`) | Route-file mounting, the two middleware aliases (`api-key`, `smartept-webhook`), the global middleware stack and `withExceptions()` **cannot be verified in this copy**. All statements about the global stack below are inferred from route-file comments and Laravel 11 defaults, and are marked `INFERRED`. |
| `public/.htaccess` | The Apache rewrite/deny configuration does not exist in the tree at all. `public/` contains only `index.php` and `web.config` (IIS). The security report's finding "`public/.htaccess` contains no equivalent deny rule" understates it: **there is no `.htaccess` at all**, so on a Linux/Apache SaaS deployment the file must come from outside version control. |
| `resources/prototype/app.html` | The 113-screen SPA shell. `AppController::show()` `abort_unless(is_file($path), 404)` (`AppController.php:88-89`). All client-side permission behaviour was read from the JS AppController **injects**, not from the shell. |
| `app/Providers/AppServiceProvider.php` content beyond stubs, `config/hashing.php` | absent → framework defaults apply (bcrypt cost 12). |
| The `PKG:` client-packaging tree (`env.client.template`, `ClientBuilder.bat`, `smartprs-config.json`) | The report's findings #7 and #14 partly cite these. **Not verifiable here** — recorded as UNVERIFIED, not refuted. |

### 0.4 Counts established by this analysis

| Measure | Value | Method |
|---|---:|---|
| `Route::<verb>()` declarations, `routes/web.php` | 412 | grep |
| … `routes/api.php` / `updates.php` / `smartept.php` | 3 / 4 / 3 | grep |
| Legacy redirect routes generated by the `foreach` loop (`web.php:693-695`) | 10 | source |
| **Total route declarations (excl. framework `/up`)** | **432** | sum |
| Declarations inside the `auth` group (`web.php` ≥238) | 338 | grep |
| Declarations OUTSIDE any `auth` group | **78** (74 in `web.php` + 3 `api.php` + 1 `updates.php`) | grep |
| `hasRole(` / `hasAnyRole(` call sites (whole tree) | **62** | grep |
| Spatie **permission** checks (`can()`, `Gate::`, `authorize()`, `@can`, `permission:` middleware) | **0** | grep |
| `App\Policies` directory | **does not exist** | `ls` |
| `ApprovalService::denyUnlessRole` call sites | **71** | grep |
| `FeaturePermissions::guard` call sites | **16** | grep |
| Routes with a controller pair and **no** server-side role/self check | **141** (78 public + **78 authenticated**; overlap none) | scripted body scan, §2 method |
| `DB::table()` call sites in `app/` | **1375** | scripted |
| … carrying a `tenant_id`/`company_id` predicate in the same statement | **616 (44.8 %)** | scripted |
| … filtered by `where('id', …)` only — **IDOR candidates** | **354 (25.7 %)** | scripted |
| … with no filter at all | **405 (29.5 %)** | scripted |
| `Schema::create(` outside `database/migrations/` | **71** | grep |
| `Schema::table(` outside `database/migrations/` | **84** | grep |
| `DB::statement("ALTER …")` in app code | **6** | grep |
| Files performing runtime DDL | **58** | grep |
| Routes exempted from CSRF (`withoutMiddleware([ValidateCsrfToken])`) | **16** | grep |
| `throttle:` declarations in `routes/web.php` | 34 | grep |
| `Audit::record` call sites (functional) | **11** (14 incl. 3 in dead `EmployeeController`) | grep |
| Feature tests covering authorization | **1 file, 3 assertions, 0 role assertions** (`tests/Feature/AuthAccessTest.php`) | source |

---

## 1. Business capability inventory

### 1.1 Sign-in and session establishment
A user signs in with work email + password at `/login`, at a company-branded page `/c/{slug}`, at a
tenant's own custom domain (resolved by `Host`), or at the platform-owner portal `/super`.
Before a session is granted, five gates run in order (`AuthController::login`, `:160-300`):
first-run install redirect → branded-tenant resolution → credentials **and** `status='active'` →
subscription lock-out (SaaS) or on-prem licence gate → forced first-password.
Four further, quieter session-creation paths exist: the passkey-gated public sales demo
(`DemoAccessController::enter`, `:365-421`, `Auth::loginUsingId`), the paid self-serve signup
auto-login, the on-prem browser installer (creates the first admin but does not sign them in),
and mobile-device pre-login registration (device token, not a session).

### 1.2 Password lifecycle
Forgot-password (`/forgot-password` → emailed 1-hour single-use link), admin invite
(48-hour link), tenant-admin welcome (72-hour link), self-service change (requires current
password), and a mandatory "create your password" screen for freshly provisioned admins
(`users.must_set_password`). Administrators may also set a colleague's password directly.

### 1.3 Roles and who may do what
A user holds **exactly one** role (every `syncRoles()` call site passes a single-element array).
Company administrators assign from a five-entry dropdown; platform staff are managed separately
by a Super Admin. A "Roles & Permissions" screen presents a module × action grid per role.

### 1.4 Multi-tenancy
One installation serves many client organisations ("tenants"). Inside a tenant live one or more
"companies" (a master + subsidiaries), its users, and its employees. Data is meant to be invisible
across tenants. Platform users (Super Admin, Platform Support) have `tenant_id = NULL` and are
meant to be cross-tenant.

### 1.5 The HTTP surface
432 route declarations across four files: a public marketing/signup/demo surface, seven
token-secured public portals (offer acceptance, NDA e-sign, self-onboarding, agent verification,
agent earnings, transfer acknowledgement, quote/licence payment), an unauthenticated biometric
device receiver (`/iclock/*`), a stateless credentialled JSON API (`/api/v1/*`), a licence/update
server, and a 338-route authenticated application area behind five middleware.

### 1.6 User and staff administration
`/app/users` manages logins **inside** a tenant (create, invite, enable/disable, set password,
link to an employee record, assign role). `/admin/staff` manages **platform** staff
(`tenant_id = NULL`, roles `super_admin` / `platform_support`). `/app/saas/tenants` provisions
whole tenants end-to-end (tenant row + master company + first admin + welcome email).

### 1.7 Edition, licence, subscription and demo gating
Four cross-cutting middleware modulate access without ever consulting a role:
`LicenseGate` (on-prem activation), `EnsureSubscriptionActive` (SaaS lock-out + mid-session
revocation), `DemoWriteGuard` (public-demo write blocklist), `EditionGuard` (hide SaaS/platform
surfaces on an on-prem build).

---

## 2. Data model

### 2.1 Tables this area touches, with provenance

| Table | Provenance | Notes |
|---|---|---|
| `users` | migration `0001_01_01_000000_create_users_table.php:14-22` | base: `id, name, email(unique), email_verified_at, password, remember_token, timestamps` |
| `users.tenant_id`, `users.employee_id`, `users.status` | migration `2026_01_01_000002_add_company_id_to_users_table.php:13-17` | **the migration's filename promises `company_id`; it adds `tenant_id`, `employee_id`, `status` instead** |
| `users.must_set_password` | **RUNTIME** — `AuthController::ensureFirstPasswordCol()` `app/Http/Controllers/AuthController.php:479-488` | `Schema::table('users', …)` from a web request |
| `password_reset_tokens` | migration `0001_01_01_000000:24-28` | **created and never used** — dead table |
| `sessions` | migration `0001_01_01_000000:30-37` | used only when `SESSION_DRIVER=database`; both `.env` templates set `redis` |
| `user_password_resets` | **RUNTIME** — `AuthController::ensureResetTable()` `:315-328`; duplicated at `UserController.php:317-326` and `SaasController.php:534-543` | the real reset/invite token store. Three independent `Schema::create` definitions of the same table |
| `tenants` | migration `2026_01_01_000001_create_companies_table.php:25-40` | `uuid, name, plan_id, status(enum), seats_used, seats_licensed, mrr, deployment(enum), owner_email, subdomain(unique), trial_ends_at, softDeletes` |
| `tenants.custom_domain` | **RUNTIME** — `SaasController::ensureTenantCols()` `:255-259` | |
| `tenants.doc_storage_emp_mb` | **RUNTIME** — `SaasController::ensureTenantCols()` `:266-268` | |
| `tenants.gstin`, `tenants.state` | **RUNTIME** — `SaasController::ensureGstCols()` `:279-284` | |
| `plans`, `subscriptions`, `invoices`, `payments`, `payment_gateways` | migration `2026_01_01_000001_create_companies_table.php:13-92` | **the file named `create_companies_table` creates none of these as `companies`** |
| `companies` | migration `2026_01_01_000100_create_employees_table.php:35-52` (via `mk()` = create-if-missing) | `tenant_id, name, type, pan, gstin, address, phone, email, website, color, logo_path, logo_text, status, softDeletes`. **No `company_id` on `users` points at it.** |
| `companies.is_master` | **RUNTIME** — three sites: `SaasController.php:165-167`, `InstallController.php:207-208`, `ClientProvision.php:82-83` | |
| `employees` | migration `2026_01_01_000100:106-160` | carries **both** `tenant_id` (indexed, unique with `emp_code`) and `company_id` |
| `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | migration `2026_05_30_025615_create_permission_tables.php` | Spatie. `config/permission.php:138` — `'teams' => false`, so **roles are global, not per tenant** |
| `activity_logs` (+`row_hash`,`prev_hash`) | migration `2026_05_30_025626` + later | hash chain is **global across tenants** (`Audit.php:91`) |
| `personal_access_tokens` | migration `2026_05_30_025638` | Sanctum installed; **no route uses `auth:sanctum`** |
| `app_settings` | **RUNTIME** — `ConfigController::ensureTable()` `:~30-38` | holds SMTP credentials in cleartext JSON (`ConfigController::put` `:47-55`) |
| `settings` | pre-existing (queried, never migrated in this tree) | holds the whole-tenant `app_state` JSON blob, incl. the role→permission matrix |
| `transfers` | **RUNTIME** — `ApprovalService::ensureAuditCols()` `:50-79` | the only request module with no base migration |
| `commission_payments`, `commission_logs` | **RUNTIME** — `ApprovalService.php:166-180`, `:277-287` | |
| `api_keys` | migration `2026_08_16_000100_create_api_keys_table.php` | `prefix`, `key_hash` (sha256), `scopes` JSON, `tenant_id`, `company_id`, `active`, `expires_at`, `last_used_at` |
| `smartept_webhook_endpoints` | migration `2026_08_26_000100` | `slug`, `secret` (Laravel `Crypt` encrypted), `events`, `tenant_id`, `company_id`, `active` |
| `biometric_configs` | migration `2026_06_25_000000` **and RUNTIME** `PushController::ensure()` | DDL executed from an **unauthenticated** request |
| `demo_requests` | **RUNTIME** — `DemoAccessController::ensureGate()` (`:85-95`) | `pin` (6-digit), `entry`, `status`, `expires_at` |
| `documents` | migration `2026_06_24_000000_ensure_runtime_feature_tables.php` (partly) + runtime self-heal | `file_path` points into the **public** disk |
| `self_onboarding`, `self_onboarding_docs` | migration `2026_07_22_000001` | `token` = `bin2hex(random_bytes(24))` (48 hex chars), stored **in cleartext** |

### 2.2 Runtime schema creation — a critical finding

**155 DDL statements execute from application code outside migrations** (71 `Schema::create` +
84 `Schema::table`), across **58 files**, plus 6 raw `ALTER TABLE` via `DB::statement`.
In this area specifically:

| Site | DDL | Triggered by |
|---|---|---|
| `AuthController.php:315-328` | `CREATE TABLE user_password_resets` | any `POST /forgot-password` (public) |
| `AuthController.php:479-488` | `ALTER TABLE users ADD must_set_password` | tenant provisioning |
| `UserController.php:317-326` | `CREATE TABLE user_password_resets` (2nd definition) | `POST /app/users` |
| `SaasController.php:534-543` | `CREATE TABLE user_password_resets` (3rd definition) | tenant provisioning / **public signup** |
| `SaasController.php:255-284` | 4 × `ALTER TABLE tenants` | `GET /app/saas/tenants` |
| `ApprovalService.php:50-259` | `CREATE transfers`, `CREATE commission_payments`, `CREATE commission_logs`, ~30 `ALTER` column adds, 1 raw `ALTER … MODIFY status` | **every** list/apply/decide/inbox call on any request module |
| `PushController::ensure()` | `CREATE biometric_configs` + 7 `ALTER` | **`POST /iclock/cdata` — unauthenticated** |
| `ConfigController::ensureTable()` | `CREATE app_settings` | any settings read |

Consequences: the deployed schema is not knowable from `database/migrations/`; the web user
needs `CREATE`/`ALTER` grants in production; concurrent requests race on DDL (MySQL DDL is not
transactional); and `ApprovalService::ensureAuditCols` runs ~28 `information_schema` probes on
every approvals page load.

---

## 3. Business rules register

BR-* IDs are taken from the reference catalogue where confirmed in source; `NEW-` IDs are mine.

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented (file:line) | HARD-CODED? | Configurable today? | Customer may want it different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-AUTH-001 | A disabled login can never authenticate | email, password | always | `status='active'` appended to `Auth::attempt` credentials | global | `AuthController.php:170` | Y (the literal `'active'`) | No | N |
| BR-AUTH-002 | Only `super_admin` may complete sign-in through `/super` | `super=1` flag | after credentials pass | logout + invalidate + error | global | `AuthController.php:172-178` | Y | No | N |
| BR-AUTH-003 | `/super` is 404 on an on-prem build | edition | `Edition::isOnPrem()` | `abort_if(…,404)` | install | `AuthController.php:128` | Y | via `SMARTPRS_EDITION` | N |
| BR-AUTH-004 | A request on a tenant's `custom_domain` gets that tenant's branded login | `Host` header | domain matches `tenants.custom_domain` | render branded login, set `session.portal_company_id` | tenant | `AuthController.php:31-34, 70-90, 93-116` | N | domain set per tenant in SaaS UI | N |
| BR-AUTH-005 | `/c/{slug}` resolves a company by slugified **name** across the whole installation | slug | unauthenticated | set `session.portal_company_id` | **global (unscoped)** | `AuthController.php:139-158` | Y | No | Y — should be tenant-scoped |
| BR-AUTH-006 | Past the 7-day grace, only a tenant `admin` may sign in | tenant subscription state | SaaS, state = `locked` | logout others with message | tenant | `AuthController.php:182-195`; `EnsureSubscriptionActive.php:71-121` | Y (grace length in `SubscriptionService`) | No | **Y** — grace length, who may still sign in |
| BR-AUTH-007 | On-prem: a valid Licence Code is required **after** credentials verify | licence state, `license_code` | on-prem + `licence_enforce` | activate, or logout with message | install | `AuthController.php:203-269` | Y | `SMARTPRS_LICENCE_ENFORCE` | N |
| BR-AUTH-008 | Any error inside the licence check lets the login through | exception | `catch (\Throwable)` | fall through to a full session | install | `AuthController.php:267-269` | Y | No | **Y — must fail closed** |
| BR-AUTH-009 | A freshly provisioned admin must create a password before the app opens | `users.must_set_password` | flag set | redirect `/app/first-password` | user | `AppController.php:83-86`; `AuthController.php:503-542` | Y | No | N |
| BR-AUTH-010 | Password minimum length is **8**, with no other rule | new password | on set/reset/change | `min:8, confirmed` | global | `AuthController.php:401, 444, 527`; `UserController.php:267`; `InstallController.php:178` | **Y** | **No** | **Y — complexity, history, expiry, breach-check** |
| BR-AUTH-011 | Password minimum length is **6** for platform staff | new password | `/admin/staff` | `min:6` | platform | `StaffController.php:43, 64` | **Y** | No | **Y — the most privileged accounts have the weakest rule** |
| BR-AUTH-012 | Admin-set passwords require **no** confirmation field | password | `POST /app/users/{id}/password` | `['required','string','min:8']` only | tenant | `UserController.php:266-268` | Y | No | Y |
| BR-AUTH-013 | Reset tokens are 48-char random, stored SHA-256 hashed, single-use | email | forgot-password | insert hashed token, expiry `+1 hour` | global | `AuthController.php:353-360, 407-430` | **Y (1 hour)** | No | **Y — expiry window** |
| BR-AUTH-014 | Invite tokens expire in **48 h**; tenant-admin welcome tokens in **72 h** | — | invite | insert with expiry | global | `UserController.php:331`; `SaasController.php:548` | **Y** | No | **Y** |
| BR-AUTH-015 | A second reset request within 2 minutes for the same email is silently suppressed | email | unused token < 2 min old | skip send | global | `AuthController.php:349-352` | Y (2 min) | No | Y |
| BR-AUTH-016 | Consuming one reset token invalidates every other unused token for that email | token | on success | mark all `used_at` | global | `AuthController.php:428-430` | N | — | N |
| BR-AUTH-017 | Setting a user's password from the admin screen invalidates their outstanding invite links | — | on success | mark tokens used | tenant | `UserController.php:280-287` | N | — | N |
| BR-AUTH-018 | The forgot-password form never reveals whether the email exists | email | always | same message either way | global | `AuthController.php:383` | N | — | N |
| BR-AUTH-019 | The login form **does** reveal that an account exists but is disabled | email | credentials fail | second lookup + distinct message | global | `AuthController.php:292-295` | Y | No | **Y — should be generic** |
| BR-AUTH-020 | A user disabled mid-session is signed out on their next request | `users.status` | per request | logout / 401 | global | `EnsureSubscriptionActive.php:44-59` | N | — | N |
| BR-AUTH-021 | Login is throttled to **5/min per IP**; nothing is throttled per account | IP | always | 429 | global | `routes/web.php:208` | **Y** | No | **Y — per-account lockout is absent** |
| BR-AUTH-022 | `POST /app/change-password` has **no** throttle | — | — | — | global | `routes/web.php:376` | — | No | Y |
| BR-SEC-001 | Server-side role guards **fail open** on any exception | exception | `catch (\Throwable)` | `return null` (= allowed) | global, 71+16+1 sites | `ApprovalService.php:377-379`; `FeaturePermissions.php:99-105`; `ApiKeyController.php:516-518` | **Y** | No | **Y — must fail closed** |
| BR-SEC-002 | An unknown capability key silently degrades to `admin`+`hr_manager` | capability | `MAP` miss | `?? ['admin','hr_manager']` | global | `FeaturePermissions.php:102` | Y | No | Y |
| BR-SEC-003 | The client-side permission matrix is skipped entirely for `Admin` — and any role not in `ROLE_MAP` renders as `Admin` | role name | `ROLE_MAP` miss or no role | `?? 'Admin'`, then early `return` | per login | `AppController.php:93`, `:769` | **Y** | No | **Y** |
| BR-SEC-004 | `spCan()` allows when no matrix row exists for the role | matrix | `!window.spPerms` | `return true` | client | `AppController.php:760` | Y | No | Y |
| BR-SEC-005 | The whole role matrix lives inside the tenant's `app_state` JSON blob | — | — | read on every login | tenant | `AppStateController.php:22-53`; `AppController.php:676-683` | Y | editable by **any** authenticated user | **Y** |
| BR-SEC-006 | SMTP credentials are stored recoverably (plain JSON in `app_settings.value`) | mail settings | on save | `json_encode` | tenant | `ConfigController.php:47-55, 138-139, 202` | Y | No | **Y** |
| BR-SEC-007 | Razorpay, biometric, licence and SmartEPT secrets **are** encrypted at rest (`Crypt::encryptString`, keyed on `APP_KEY`) | secrets | on save | encrypt | mixed | `BillingController.php:520-523`; `BiometricConfigController.php:328`; `SmarteptWebhook.php:99`; `LicenseService.php:308,367` | N | — | N |
| BR-SEC-008 | API keys are `sk_prs_<4>_<40>`, stored SHA-256, compared with `hash_equals` | key | per request | 401/403 | global | `ApiKeys.php:32-47`; `ApiKeyAuth.php:103-114` | Y (format) | No | N |
| BR-SEC-009 | An ingest API key with `tenant_id = NULL` is refused | key row | scope `ingest` | 401 | global | `ApiKeyAuth.php:67-69` | N | — | N |
| BR-SEC-010 | The SmartEPT webhook answers **one** 401 wording for every failure class | slug, signature | always | 401 | global | `SmarteptSignature.php:56-66` | N | — | N |
| BR-SEC-011 | A nightly 03:30 task recreates three known-password logins (`admin`, `hr_manager`, `field_agent`) on **any** server running the scheduler | — | unconditional | insert/update users | global | `routes/console.php:208-211`; `DemoReset.php:35, 106-122` | **Y (`smartprs-demo`)** | `--password` flag only | **Y — must be env/APP_ENV gated** |
| BR-SEC-012 | The demo-user lookup is by lowercase email with **no tenant scope** and reassigns `tenant_id` | email | always | takeover | global | `DemoReset.php:106-122` | Y | No | Y |
| BR-SEC-013 | `OnPremWorkspaceSeeder` creates `admin@smartprs.local` with a hardcoded password on the **first** tenant | — | seeder run | insert admin | global | `OnPremWorkspaceSeeder.php:74-92` | **Y (`password`)** | No | **Y** |
| BR-SEC-014 | The team-demo PIN default is a literal in committed config | — | — | — | install | `config/smartprs.php:32` — `env('SMARTPRS_TEAM_PIN', 'ametecs')` | **Y** | env var | Y (and the constant is now dead code) |
| BR-SEC-015 | Every uploaded document is validated by **filename extension only** | file | upload | `in_array(getClientOriginalExtension(), ALLOWED)` | tenant | `DocumentController.php:214-218`; `SelfOnboardingController.php:319` | **Y (list)** | No | **Y — must be MIME/magic-byte** |
| BR-SEC-016 | Employee documents, photos, commission proofs and agent KYC are written to the **web-served** `public` disk | file | upload | `store(…, 'public')` | tenant | `DocumentController.php:234`; `LetterController.php:348,467`; `RequestController.php:1605`; `OffrollAgentController.php:450` | Y | No | **Y** |
| BR-SEC-017 | Onboarding ID/address/bank/PCC/DRA documents are **copied** from the private disk to the public disk at provisioning | doc rows | on hire | `Storage::disk('public')->put('employee-docs/'.$empId.'/'.$base)` | tenant | `SelfOnboardingController.php:953-955` | Y | No | **Y** |
| BR-SEC-018 | A commission proof path is accepted if it merely *starts with* `commission-proofs/` | `proof_path` | on apply | store verbatim | tenant | `RequestController.php:501-503`, served at `:1626-1629` | Y | No | **Y — path traversal** |
| BR-SEC-019 | Backups are unencrypted, include the app root (`.env`), and are written to the same volume | — | nightly | zip to `local` disk | install | `config/backup.php:28-40,72-75,80-82`; `.env.production.example:65-66` (password **commented out**) | Y | `BACKUP_ARCHIVE_PASSWORD` | **Y** |
| BR-TEN-001 | Tenant identity is `users.tenant_id`, read per query — no middleware, no resolver | user | every query | `->when($tid, …)` | global | ~400 controller reads; e.g. `AppDataController.php:248` | **Y** | No | **Y** |
| BR-TEN-002 | `tenant_id` NULL **or 0** means "no tenant filter at all" | `$tid` | falsy | `when()` skips the callback | global | Laravel `Builder::when`; `AppStateController.php:19` coerces null→0 | **Y** | No | **Y** |
| BR-TEN-003 | The Eloquent `CompanyScope` is **inert** — `users.company_id` does not exist | user | always | no predicate added | 8 models | `CompanyScope.php:31-32`; migration `2026_01_01_000002:13-17` | Y | No | **Y** |
| BR-TEN-004 | Legacy `company_id` controllers fall back to `Company::value('id')` — the **first company on the box** | user | `company_id` always null | write into an arbitrary tenant | global | `AttendanceController.php:39,52`; `DeviceController.php:35`; `LoanController.php:32`; `SalaryRunController.php:42`; `DepartmentController.php:68`; `EmployeeController.php:88` | Y | No | **Y** |
| BR-TEN-005 | Attendance rows with `tenant_id IS NULL` are counted for **every** tenant's "Present Today" | logs | dashboard | `where(tenant)->orWhereNull(tenant)` | tenant | `DashboardController.php:99-100` | Y | No | **Y** |
| BR-TEN-006 | Platform-wide notices (`tenant_id IS NULL`) are visible to every tenant — by design | notices | dashboard | `orWhereNull` | tenant | `DashboardController.php:61-63` | Y | No | N |
| BR-TEN-008 | All platform users share one settings row at `tenant_id = 0` | `$tid ?? 0` | platform user | shared row | platform | `ConfigController.php:43,52`; `AppStateController.php:19` | Y | No | Y |
| BR-TEN-009 | Employee-level self-scoping applies **only** to the literal role `employee` | role | `/app/data`, `/app/dashboard/stats` | narrow to own record | tenant | `AppDataController.php:221-232, 268-270`; `DashboardController.php:45` | **Y** | No | **Y** |
| BR-TEN-010 | Attendance report narrows **all** non-privileged roles (inconsistent with BR-TEN-009) | role | attendance only | `selfScope($r, true)` | tenant | `AttendanceReportController.php:104, 535` | Y | No | Y |
| BR-TEN-011 | Global search applies a tenant filter but **no** self-scope | query | `/app/search` | return matches | tenant | `DashboardController.php:198-225` | Y | No | Y |
| BR-TEN-012 | Write-side tenant stamping is manual; no constraint, trigger or model event enforces it | insert array | every insert | `'tenant_id' => $tid` by hand | global | e.g. `UserController.php:201`; `ApprovalService::safeRow` `:263-266` adds nothing | Y | No | **Y** |
| BR-TEN-013 | Roles are **global**, not per tenant (`teams => false`) | — | — | one `roles` row shared by all tenants | global | `config/permission.php:138` | Y | No | **Y — per-tenant role definitions are impossible today** |
| NEW-SEC-101 | `MasterController::list()` narrows rows for **exactly two** of ~58 master types (`helpdesk`, `posh`) | type | non-manager | `where('employee_id', self)` | tenant | `MasterController.php:586-588, 644-648` | **Y** | No | **Y** |
| NEW-SEC-102 | `syncRoles('accountant')` throws `RoleDoesNotExist` (the role is never created) and the exception is swallowed → a user with **no role** | role | assign `accountant` | user created role-less, API returns `ok:true` | tenant | `UserController.php:26-32, 150-152, 298-308` | Y | No | **Y** |
| NEW-SEC-103 | `hr_manager` may create an `admin` user and may set any tenant user's password | role, id | `canManage` = super/admin/hr_manager | privilege escalation | tenant | `UserController.php:26-37, 150, 262-278` | Y | No | **Y — needs a "may not grant above self" rule** |
| NEW-SEC-104 | A NULL-tenant `admin`/`hr_manager` may administer **any** user on the installation | `$tid` null | `when($tid,…)` skipped | cross-tenant takeover | global | `UserController.php:247-249, 270-278` | Y | No | **Y** |
| NEW-SEC-105 | Public bearer tokens (offer, NDA, transfer, agent-earnings, quote, licence-invoice) **never expire** and are stored in cleartext | token | on issue | permanent URL | tenant | `LetterController.php:402`; `TransferController.php:125`; `OffrollAgentController.php:288,499`; `SignupController.php:613`; `OnpremClientController.php:516` | Y | No | **Y — expiry + revocation** |
| NEW-SEC-106 | Demo passkeys are 6 numeric digits, throttled 15–20/min per IP | pin | `/demo/start` etc. | session as demo **admin** | demo tenant | `DemoAccessController.php:280, 383`; `routes/web.php:134-148` | Y | validity hours configurable by Super Admin | Y |
| NEW-SEC-107 | Session cookie `Secure` flag has **no default** — off unless `SESSION_SECURE_COOKIE` is set; `.env.example` does not set it | env | always | cookie sent over HTTP | install | `config/session.php:173`; `.env.example` (absent) vs `.env.production.example:40` | Y | env var | **Y — should default true** |
| NEW-SEC-108 | Session payloads are **not** encrypted at rest | env | always | plaintext session rows | install | `config/session.php:50` (`SESSION_ENCRYPT`, default false) | Y | env var | Y |
| NEW-SEC-109 | Sessions have a 120-minute **idle** lifetime and **no absolute cap**; remember-me is the framework 5-year default | env | always | indefinite session | install | `config/session.php:35,37`; no `rememberDuration` anywhere | Y | `SESSION_LIFETIME` | **Y — absolute cap, remember-me cap** |
| NEW-SEC-110 | There is **no** account lockout and **no** MFA anywhere on the login path | — | — | — | global | verified by absence: no `failed_login`, no `locked_until`, no `Failed` listener, no TOTP/WebAuthn package in `composer.json` | — | No | **Y** |
| NEW-SEC-111 | Four of the six middleware and both authz helpers **fail open** on exception | exception | `catch (\Throwable)` | `return $next($request)` / `return null` | global | `EditionGuard.php:55-57`; `DemoWriteGuard.php:73-75`; `LicenseGate.php:73-75`; `EnsureSubscriptionActive.php:58-59`; `ApprovalService.php:377-379`; `ApiKeyController.php:516-518` | **Y** | No | **Y** |
| NEW-SEC-112 | `EditionGuard`'s blocklist is **empty on SaaS** — the `/admin/*` and `/app/saas/*` surfaces rely entirely on per-controller `hasRole('super_admin')` | edition | `current() === 'saas'` | `return []` | install | `Edition.php:169-174` | Y | No | N (but it removes a defence layer) |
| NEW-SEC-113 | An audit record is written on **successful login only**; logout, failed login, role change, user create/disable, password set, staff CRUD and tenant provisioning write none | event | login success | `Audit::record('login')` | tenant | `AuthController.php:271-284`; 11 functional `Audit::record` sites total | Y | No | **Y** |
| NEW-SEC-114 | The `activity_logs` hash chain is **global across tenants** (prev-hash is the last row of any tenant) | — | on record | chain across tenants | global | `Audit.php:91` | Y | No | **Y** |
| NEW-TEN-115 | `AppStateController` gives every authenticated user unrestricted read **and** whole-blob overwrite of the tenant's app state | `state` array | `is_array($state)` | `updateOrInsert` wholesale | tenant | `AppStateController.php:22-53` | Y | No | **Y** |

---

## 4. Calculations and formulas

This area contains almost no arithmetic. The four computations that exist:

**4.1 API-key hash and comparison** — `ApiKeys.php:32-47`, `ApiKeyAuth.php:103-114`
```php
$prefix = 'sk_prs_' . Str::lower(Str::random(4));       // 36^4 ≈ 1.68 M prefix space
$secret = $prefix . '_' . Str::random(40);              // 62^40 ≈ 2^238 tail entropy
$hash   = hash('sha256', $secret);                      // only the hash is stored
// lookup: rows WHERE prefix = ? ; then hash_equals($row->key_hash, $hash)
```
Worked example: `sk_prs_ab12_9Xk…` → prefix `sk_prs_ab12` (lookup handle, not a credential),
tail 40 chars. `prefixOf()` splits at the **last** underscore and rejects a prefix > 12 chars
(`ApiKeys.php:53-63`) — so a presented value whose prefix exceeds 12 characters is rejected before
any DB hit. Rounding/precision: not applicable. Constant-time comparison: **yes** (`hash_equals`).

**4.2 Password-reset token** — `AuthController.php:353-360`
```php
$token = Str::random(48);                    // 62^48 ≈ 2^285
DB store: hash('sha256', $token)             // hashed at rest
expires_at = now()->addHour()                // divisor/basis: 1 hour, hard-coded
verify: email = ? AND token = sha256(input) AND used_at IS NULL AND expires_at >= now()
```
Invite variant: `now()->addHours(48)` (`UserController.php:331`). Tenant-admin welcome:
`now()->addHours(72)` (`SaasController.php:548`). Three different windows, all literals.

**4.3 Audit hash chain** — `Audit.php:89-94`
```php
$prev     = DB::table('activity_logs')->orderByDesc('id')->value('row_hash') ?: 'GENESIS';
$row_hash = hash('sha256', payload($prev, $tenantId, $userId, $action, $entity, $eid, $detailJson, $whenStr));
```
Worked example: with tenants A and B writing alternately, row *n* of tenant A chains onto row
*n−1* of tenant B. A single edit in tenant B therefore reports the chain as broken for tenant A.

**4.4 Demo passkey** — `DemoAccessController.php:280`
```php
$pin = (string) random_int(100000, 999999);   // 9 × 10^5 space
```
Worked example: at the route throttle of 15 requests/minute/IP, a single IP exhausts the space in
≈ 60,000 minutes (~42 days); ten IPs in ~4 days; the PIN is also only valid within its issued
time window and only for its own `entry` value, which bounds the practical risk.

---

## 5. Configuration surface today

### 5.1 (a) What a customer CAN change without code

| Setting | How | Where |
|---|---|---|
| Deployment model (`saas` / `onprem`) | `.env` `SMARTPRS_DEPLOYMENT` | `config/smartprs.php:12` |
| Edition (`saas`/`l1`/`l2`/`l3`) | `.env` `SMARTPRS_EDITION`, read **directly from the `.env` file**, bypassing config cache | `Edition::envEdition()` `:56-86` |
| Licence enforcement on/off | `.env` `SMARTPRS_LICENCE_ENFORCE` | `LicenseGate.php:46` |
| Licence signing secret / licence email / `.lic` path | `.env` | `config/smartprs.php:69-79` |
| Session idle lifetime | `.env` `SESSION_LIFETIME` (default 120 min) | `config/session.php:35` |
| Session driver, encryption, cookie Secure/SameSite/domain | `.env` | `config/session.php:21,50,173,203` |
| Team-demo PIN | `.env` `SMARTPRS_TEAM_PIN` (**dead** — no code reads it) | `config/smartprs.php:32` |
| Platform super-admin bootstrap credentials | `.env` `SMARTPRS_ADMIN_EMAIL/NAME/PASSWORD` | `SuperAdminSeeder.php:25-51` |
| Demo-data seeding on/off | `.env` `SMARTPRS_DEMO_DATA` (**honoured by `DatabaseSeeder` only** — not by `DemoReset` or `OnPremWorkspaceSeeder`) | `DatabaseSeeder.php:19-30` |
| Which role a tenant user holds (one of 5) | `/app/users` UI | `UserController::ASSIGNABLE` `:26-32` |
| Enable/disable a tenant login; set its password | `/app/users` UI | `UserController.php:238-295` |
| Platform staff (super_admin / platform_support) | `/admin/staff` UI | `StaffController.php:17-20` |
| Tenant provisioning, plan, seats, subdomain, custom domain, GSTIN/state, per-employee doc quota | `/app/saas/tenants` UI | `SaasController.php:295-367` |
| Plan → module-group inclusion (14 groups) | `/app/saas/plans` UI | `SaasController::MODULE_GROUPS` `:31-46` — **but the client-side plan gate is disabled**, see §6 |
| Per-role module × action matrix ("Roles & Permissions" screen) | in-app UI, persisted in the `app_state` JSON blob | `AppController.php:655-810`; `AppStateController.php` — **client-side only, and writable by anyone** |
| Company branding (name, colour, logo) and branded login URL `/c/{slug}` | `/app/branding`, tenant `subdomain` | `ConfigController`; `AuthController::showBranded` |
| SMTP host/port/user/password per tenant | `/app/mail-settings` | `ConfigController.php:121-150` |
| Demo passkey validity hours | `/app/saas/demo-requests` (Super Admin) | `DemoAccessController::saasHours` |

### 5.2 (b) What a customer CANNOT change without code — the SmartPRS 3 backlog for this area

1. **The set of roles.** Five roles are hard-coded in three places that already disagree:
   `config/smartprs.php:88-94` (5), `RolePermissionSeeder.php:41-58` (5, but `field_agent` where
   config has it and no `accountant`), `UserController::ASSIGNABLE:26-32` (5 incl. `accountant`),
   `StaffController::ROLES:17-20` (2), `AppController::ROLE_MAP:15-21` (5). A customer cannot add
   "Branch Manager", "Payroll Officer" or "Auditor".
2. **What any role may DO on the server.** Every authorization decision is a literal role-name
   array inside a controller method (71 `denyUnlessRole` sites, 62 `hasRole`/`hasAnyRole` sites).
   Changing "HR Manager may not approve payroll" requires editing PHP.
3. **Functional permission is not separable from data scope.** The only data-scope mechanism is
   `selfScope()`, which is a hard-coded two-branch test on role names
   (`AppDataController.php:224-232`). There is no notion of "this role sees this branch /
   department / company / reporting-line".
4. **Which fields a role may see.** `/app/data` returns a fixed 62-field employee projection
   (`AppDataController.php:302-424`) including `ctc`, `pan`, `uan`, `bankAcc`, `ifsc`, `homeLat`,
   `homeLng`, plus every reference's Aadhaar and PAN. There is no field-level allow-list.
5. **Password policy.** Length 8 (6 for platform staff) is a literal in 6 files. No complexity,
   no history, no expiry, no breach check, no per-tenant policy.
6. **Account lockout and MFA.** Do not exist at all; cannot be switched on.
7. **Session lifetime per tenant, absolute session cap, remember-me duration, idle warning.**
   Global env only.
8. **Token expiry windows** (1 h reset / 48 h invite / 72 h welcome) are literals.
9. **Public bearer-token expiry and revocation** (offer, NDA, transfer, agent earnings, quote,
   licence invoice) — none exists.
10. **Approval thresholds** are literals in `ApprovalService::modules()` `:29-42`
    (expenses ₹10,000 / advances ₹10,000 / loans ₹50,000; every other module 0) and the escalation
    chain (Reporting Manager → their manager → "Branch / Company Admin") is hard-coded in
    `resolveApprover()` `:310-352`.
11. **Which endpoints the demo may not write** — a 24-entry regex blocklist,
    `DemoWriteGuard::BLOCKED:24-48`.
12. **Which modules an edition hides** — `Edition::SAAS_ONLY/DNA/L2_MODULES/BLOCK_*` `:102-174`;
    the L1/L2/L3 differentiation has been *retired in code* (`hiddenNavIds()` `:119-127`,
    `blockedPatterns()` `:169-174`) while the constants and the marketing remain.
13. **Plan → module gating is dead.** `applyPlanGate()` begins with an unconditional `return;`
    (`AppController.php:~709`, comment: *"TEMPORARILY DISABLED (rev 34e)"*). The Plans UI writes
    `plans.features` that nothing enforces.
14. **Which fields are audited, and audit retention.** 11 `Audit::record` sites, all literals.
15. **Upload allow-lists, size caps, storage location.** `DocumentController::ALLOWED_EXTS:34`,
    `MAX_FILE_MB = 10` `:36`, `DEFAULT_EMP_QUOTA_MB = 100` `:38`, disk `'public'` `:234`.
16. **Rate limits.** 34 literal `throttle:n,m` strings in `routes/web.php`.
17. **Tenant isolation itself.** Written by hand ~1000 times; there is no switch, no policy object,
    no repository boundary a customer or an implementer can configure.
18. **Roles cannot be per-tenant** (`config/permission.php:138` — `teams => false`).
19. **Branded-login slug resolution** is by slugified company *name* across all tenants —
    not configurable, and not safe (`AuthController.php:142-143`).
20. **Which roles receive which screens in the menu** is JSON in a blob that any authenticated
    user can overwrite.

---

## 6. Defects, conflicts and technical debt

| # | What it is | Evidence | Business impact | Severity |
|---|---|---|---|---|
| D-01 | **`CompanyScope` is inert.** The global scope predicates on `$user->company_id`; that column does not exist on `users`. The migration named `add_company_id_to_users_table` adds `tenant_id`, `employee_id`, `status`. | `CompanyScope.php:31-32`; `2026_01_01_000002_add_company_id_to_users_table.php:13-17`; `0001_01_01_000000_create_users_table.php:14-22`; no runtime `ALTER users ADD company_id` anywhere (grep) | The documented automatic tenant isolation does not exist. Isolation is 616 hand-written filters with 405 unfiltered and 354 id-only queries beside them. | CRITICAL |
| D-02 | **Two parallel tenancy models**, one live (`tenant_id`, query-builder) and one dead (`company_id`, Eloquent scope). `BelongsToCompany` is used by 8 models; `Employee`, `Attendance`, `Department`, `Device`, `Loan`, `SalaryRun`, `SalarySlip`, `LeaveApplication`. | `BelongsToCompany.php:18-27`; the 8 model files; the live SPA uses `DB::table()` throughout | Every reader of the codebase is misled about how isolation works. | HIGH |
| D-03 | **Legacy controllers resolve `company_id` to the first company on the box.** `auth()->user()->company_id ?? Company::value('id')`. | `AttendanceController.php:39,52`; `DeviceController.php:35`; `LoanController.php:32`; `SalaryRunController.php:42`; `DepartmentController.php:68`; `EmployeeController.php:88` | Mostly latent (those routes are replaced by the `/{legacy}` → `/app` redirects at `web.php:693-695`), **except** `POST /app/device/{id}/sync` which still runs `DeviceController` code. Writes could land in another tenant. | HIGH (latent) |
| D-04 | **`TenantController` targets a schema that does not exist** — validates/persists `code`, `legal_name`, `is_active`, `deployment`; the real `companies` table has none of them. Its routes are not registered (`web.php:16` imports it; `/tenants` is in the legacy-redirect list). | `TenantController.php:73-95`; `2026_01_01_000100:35-52`; `routes/web.php:693` | Dead code that ships in every release and misleads. | MEDIUM |
| D-05 | **`accountant` is assignable but never created.** `syncRoles(['accountant'])` throws `RoleDoesNotExist`; the `catch (\Throwable)` swallows it; the API returns `{"ok":true}`. The resulting role-less user renders as **"Admin"** in the SPA and is **not** narrowed by `selfScope`. | `UserController.php:26-32, 150-152, 298-308`; `AppController.php:93, 769`; `AppDataController.php:224-232`; no `Role::findOrCreate('accountant')` anywhere (grep) | An administrator creates an "Accountant" and silently produces a read-everything account presenting as Administrator. Two `denyUnlessRole` sites already list `accountant` (`RequestController.php:1851`, `BankPackController.php:64`), so the intended capability never works either. | HIGH |
| D-06 | **Roles referenced in code that cannot exist:** `manager` (`FeaturePermissions.php:47,51,67`), `hr` (`FeaturePermissions.php:55`; `WaTemplateController.php:209`), `platform_support` (created by `StaffController.php:27`, checked nowhere). | grep: `Role::findOrCreate` sites | Three write-permission lists are partly inert. `platform_support` inherits the widest data scope in the system (`tenant_id = NULL`) with zero checks. | HIGH |
| D-07 | **Five role registries disagree.** `config/smartprs.php:88-94`; `RolePermissionSeeder.php:41-58`; `UserController::ASSIGNABLE:26-32`; `StaffController::ROLES:17-20`; `AppController::ROLE_MAP:15-21`; plus the docblock at `FeaturePermissions.php:26-27` naming a sixth set. | source | No single source of truth for "what roles exist". | MEDIUM |
| D-08 | **Spatie permissions are dead data.** 13 permissions seeded and attached to roles; zero reads. | `RolePermissionSeeder.php:21-63`; grep: 0 `can(`/`Gate::`/`authorize(`/`permission:` | The product ships a permission framework it does not use. | MEDIUM |
| D-09 | **Plan→module gating is disabled by an unconditional `return`.** | `AppController::applyPlanGate` — `// TEMPORARILY DISABLED (rev 34e): … return;` followed by `// eslint-disable-line no-unreachable` | Plans are sold with module lists that are never enforced, on the client or the server. | HIGH (commercial) |
| D-10 | **Edition gating is retired but the constants and marketing remain.** `hiddenNavIds()` returns `SAAS_ONLY` or `[]`; `blockedPatterns()` returns `BLOCK_SAAS` or `[]`. `DNA`, `L2_MODULES`, `BLOCK_DNA`, `BLOCK_L2` are unreachable. `Edition::level()` returns a constant `3`. | `Edition.php:93-97, 104-127, 148-174` | L1/L2/L3 licences all deliver the full product. | HIGH (commercial) |
| D-11 | **155 DDL statements run from web requests**, including from an unauthenticated one. Three independent `Schema::create('user_password_resets')` definitions. | §2.2 | Schema is unknowable from migrations; production DB user needs DDL grants; DDL races. | HIGH |
| D-12 | **Comment says the opposite of the code.** `routes/web.php:161-163`: *"the source tree has no bootstrap/app.php to register exceptions in"* — the reference documents cite `bootstrap/app.php:8-46` extensively (it is absent from **this copy**, see §0.3). `AuthController.php:313`: *"the project has no password_reset_tokens migration"* — it exists at `0001_01_01_000000:24-28`. `AppDataController.php:227-229` calls the employee-only default *"the stricter"* option when it is the looser one. | source | Maintainers act on false premises. | MEDIUM |
| D-13 | **`ApprovalService` is a service living in `App\Http\Controllers`.** 381 lines mixing approval routing, DDL, audit logging and the central authz guard. | `app/Http/Controllers/ApprovalService.php` | Architectural placement error; the load-bearing authz helper is in the controller namespace. | MEDIUM |
| D-14 | **`AppController.php` is 12,533 lines**, most of it JavaScript in a PHP heredoc, including the entire client-side permission model. | `wc -l` | Unreviewable; client and server authorization models are guaranteed to drift. | HIGH |
| D-15 | **Zero authorization tests.** `tests/Feature/AuthAccessTest.php` has 3 assertions: guests redirect, landing renders, an admin can load `/app/data`. No test asserts that a non-admin *cannot* do anything. | source, 21 lines | Every regression in this area ships silently. | HIGH |
| D-16 | **`/app/master/{type}` read is ungated for ~56 of ~58 master types**; row narrowing exists only for `helpdesk` and `posh`. | `MasterController.php:586-588, 608-650`; type registry `:33-480` | See §7 SEC-06. | HIGH |
| D-17 | **Four middleware + two authz helpers fail open on exception** with the explicit comment *"fail open — availability over strictness."* | `ApprovalService.php:377-379`; `FeaturePermissions.php:99-105`; `ApiKeyController.php:516-518`; `EditionGuard.php:55-57`; `DemoWriteGuard.php:73-75`; `LicenseGate.php:73-75`; `EnsureSubscriptionActive.php:58-59` | See §7 SEC-01. | CRITICAL |
| D-18 | **Audit coverage is 11 call sites.** Login is audited; logout, failed login, role assignment, user create/disable, admin password set, staff CRUD and tenant provisioning are not. The `Audit::verify()` chain loads **every** tenant's rows into memory. | grep; `Audit.php:111` | No forensic record of privilege changes; a memory-exhaustion path on any admin clicking Activity Logs. | HIGH |
| D-19 | **`Audit::record` chains hashes globally across tenants.** | `Audit.php:91` | One tenant's edit reports as a chain break for all. | MEDIUM |
| D-20 | **Raw exception messages returned to the browser** on the JSON error path in this area. | `AuthController.php:459-461`; `UserController.php:213-214, 232-234, 251-254, 292-294`; `SaasController.php:92-94, 121-123, 246-248, 364-366, 388-390, 419-421, 461-463, 488-490` | Schema and internal-path disclosure to any authenticated user. | MEDIUM |
| D-21 | **`Sanctum` is installed and `personal_access_tokens` migrated, but no route uses `auth:sanctum`.** | `composer.json`; `config/sanctum.php`; grep on routes | Dead dependency and dead table. | LOW |
| D-22 | **`config/auth.php` password broker points at the unused `password_reset_tokens` table**, and `AUTH_PASSWORD_TIMEOUT` (10,800 s) is unused — no route uses `password.confirm`. | `config/auth.php:96, 113` | Dead configuration that looks live. | LOW |
| D-23 | **`public/.htaccess` does not exist in the tree**; `public/web.config:36-46` blocks the `storage` segment on IIS only. | `ls public/` → `index.php`, `web.config` | The Apache/Linux SaaS deployment has no version-controlled deny rule for `/storage`. | HIGH |
| D-24 | **`config/smartprs.php:32` ships a literal PIN default (`'ametecs'`) for a setting nothing reads** (`DemoAccessController.php:28` documents the team-PIN flow as removed). | grep: 2 hits, both non-functional | Dead credential in committed source. | LOW |

---

## 7. Security findings

Severity uses the reference report's scale. **VERDICT** states whether the reference report's
claim held up against source.

### 7.1 Verification of the reference report's Top-15 (items touching this area)

| Report # | Claim | Verdict | Evidence found in source |
|---|---|---|---|
| #1 | `denyUnlessRole` fails open; 71 sites + 16 `FeaturePermissions::guard` + `ApiKeyController` | **CONFIRMED, exactly** | `ApprovalService.php:365-380` (`catch { return null; // fail open — availability over strictness on pivot errors }`); counted 71 and 16 by grep; `ApiKeyController.php:509-521` duplicates it |
| #2 | Authorization enforced in browser JS; `cfg.role` is `'Admin'` for any unmapped role incl. none; `spCan` returns true with no matrix; ~60 authenticated endpoints have no server check | **CONFIRMED**, with a sharper count | `AppController.php:93` (`ROLE_MAP[… ?? 'admin'] ?? 'Admin'`), `:760` (`if (!window.spPerms) return true`), `:769` (early return for Admin/Super Admin); `routes/web.php:238` applies 5 middleware, none role-aware; my own scripted sweep found **78** authenticated routes with no server-side role or self check |
| #3 | Onboarding ID/bank docs copied to the public disk, enumerable | **CONFIRMED with one correction** | `SelfOnboardingController.php:953-955` copies to `Storage::disk('public')->put('employee-docs/'.$empId.'/'.$base)`. **Correction:** `$base = basename($local)` where `$local` came from `$file->store(…, 'local')`, i.e. a Laravel `hashName()` — a 40-char random name, **not** the original filename. The *directory* is the sequential `employee_id`; the *file* is not guessable. It is still an unauthenticated public path with no tenant check, and the random name is disclosed to any user who can list documents |
| #4 | Upload validates the filename, not the file | **CONFIRMED** | `DocumentController.php:214-218` (`in_array(getClientOriginalExtension(), ALLOWED_EXTS)`) then `:234` `store('employee-docs','public')`; `SelfOnboardingController.php:319, 323`. Grep for `finfo_`/`getMimeType()` in `app/` returns **0**; only 7 `'mimes:'` rules exist in the whole app, none on these paths |
| #5 | Unauthenticated write into payroll input via `/iclock`, incl. unauthenticated DDL | **CONFIRMED** | `routes/web.php:92-105` (5 routes, `throttle:1000,1`, CSRF-exempt, no auth); `PushController::cdata:38-54` → `importAttlog:106-140`; `PushController::ensure()` executes `Schema::create('biometric_configs')` + 7 `Schema::table` |
| #6 | Nightly task creates admin accounts with a published password, unconditionally | **CONFIRMED** | `routes/console.php:208-211` — `Schedule::command('demo:reset')->dailyAt('03:30')` with no `->when()`, no `APP_ENV` reference; `DemoReset.php:35` default `--password=smartprs-demo`; `:106-122` looks up by `whereRaw('LOWER(email) = ?')` with **no tenant scope**, reassigns `tenant_id`, overwrites `password`, creates `admin`/`hr_manager`/`field_agent`; `:152` prints the password |
| #7 | One licence-signing secret shipped in every client build | **UNVERIFIABLE HERE** | The `PKG:` packaging tree is absent from this copy. What I *can* confirm: `config/smartprs.php:69` has **no default** (`env('SMARTPRS_LICENCE_SECRET')`, comment at `:60-63` says a working default was deliberately removed in rev166). So the in-repo half of the fix is present; the packaging half cannot be checked |
| #8 | Every request module readable tenant-wide by any authenticated user | **CONFIRMED** | `RequestController::list` `:125-175` — the only row-level narrowing is `->when($module === 'commissions' && ! $manager, …)` at `:152-163`. `expenses, advances, loans, clawbacks, increments, exits, bonus, transfers` get `WHERE r.tenant_id = ?` only |
| #9 | Field agents receive the whole workforce PII/salary/bank | **CONFIRMED** | `AppDataController::selfScope:221-232` — with the default `$allNonPrivileged=false`, `if (! $allNonPrivileged && ! $user->hasRole('employee')) return null;` → **no narrowing** for `field_agent`, `accountant`, `platform_support`, `manager`, `hr`, or a role-less user. `bootstrap()` `:268` calls `selfScope($request)` with the default |
| #10 | Statutory registers expose every employee's PAN/UAN to any login | **CONFIRMED** | `AppDataController::statutoryPdf:1642-1645` — no `denyUnlessRole`, no `selfScope`, loads every employee of the tenant; `{type}` is unconstrained at `routes/web.php:308` |
| #11 | Salary sheet / run list / bank-file preview have no authorisation check | **CONFIRMED** | `SalaryApprovalController` contains **zero** `denyUnlessRole` calls (grep). `isHR()` `:59-62` and `isFinance()` `:65-68` are computed at `:188-189`, `:240-241` and returned as `isHR`/`isFinance` flags at `:224`, `:316-317` — branch selectors, not aborts. `bankFile()` `:454` **does** abort on `!isFinance`; `bankFilePreview()` `:416-441` does not |
| #12 | Path traversal in the commission-proof server | **CONFIRMED** | write check `RequestController.php:501-503` is `strpos($path, 'commission-proofs/') !== 0` → reject; read `:1626-1629` is `storage_path('app/public/'.$rec->proof_path)` with no `realpath()`, no `basename()`, no `..` rejection; the owner branch at `:1624-1625` authorises the submitter |
| #13 | Backups unencrypted, contain `.env`, stay on the box | **CONFIRMED at the template level** | `.env.production.example:65-66` — `# Optional: password-protect backup zip archives.` / `# BACKUP_ARCHIVE_PASSWORD=` (commented). `config/backup.php` not re-read in full (outside my area) |
| #14 | Default admin credentials shipped for production use | **CONFIRMED for the in-repo half** | `OnPremWorkspaceSeeder.php:78` — `'password' => Hash::make('password')` attached to `DB::table('tenants')->…->orderBy('id')->value('id')` (an arbitrary tenant), with **no** `SMARTPRS_DEMO_DATA` or `app()->environment()` guard (`DatabaseSeeder.php:19-30` does not call it); `DemoCompanySeeder.php:69` same literal. The `PKG:smartprs-config.json` half is unverifiable here |
| #15 | `POST /app/state` lets any authenticated user overwrite the tenant's data set | **CONFIRMED** | `AppStateController.php:22-53` — 54 lines total, **zero** guards. `save()` accepts anything `is_array($state)` and `json_encode`s it over the tenant's single `settings` row. `show()` has no guard either. `tenantKey()` `:19` coerces platform users to `0` |
| #16 | `platform_support` reads every tenant's data | **CONFIRMED** | `StaffController.php:19` offers it; `:27` `Role::findOrCreate`; `:49` `tenant_id => null`; grep: **0** functional `hasRole('platform_support')` checks; `EnsureSubscriptionActive.php:61-63` returns early for null tenant; `AppDataController::bootstrap:248,258-261` skips the filter |
| #18 | `kb_topics` written with `tenant_id = NULL` and never filtered | **CONFIRMED** | `KbController::ensureSeeded` inserts `'tenant_id' => null`; `index()` reads `DB::table('kb_topics')->orderBy('sort')` with no tenant predicate; `store/update/destroy` are `canManage`-gated but not tenant-scoped |

**Summary: 15 of the 16 checkable Top-15/16-20 claims in my area hold up in source.
One (#3) is materially overstated on one detail (filename predictability) and correct on the
substance. Two (#7, #14-part) reference a packaging tree that is not in this copy.**

### 7.2 Findings — this analysis

| ID | Finding | Evidence | Exploitability | Business impact | Severity |
|---|---|---|---|---|---|
| SEC-01 | **Six fail-open controls.** `denyUnlessRole` and `authorizeScreen` return "allowed" from `catch`; `EditionGuard`, `DemoWriteGuard`, `LicenseGate`, `EnsureSubscriptionActive` `return $next($request)` from `catch`. | `ApprovalService.php:377-379`; `ApiKeyController.php:516-518`; `EditionGuard.php:55-57`; `DemoWriteGuard.php:73-75`; `LicenseGate.php:73-75`; `EnsureSubscriptionActive.php:58-59` | Trivial once a fault occurs (Spatie cache store down, `model_has_roles` missing after a partial migration, DB blip). No attacker action needed. | For the fault's duration, every role-gated write — payroll approval, commission payment, employee deletion, user management, API-key minting — is available to a plain `employee`. **No log line is written.** | CRITICAL |
| SEC-02 | **No route-level authorization exists.** `routes/web.php:238` applies `auth`, `LicenseGate`, `EnsureSubscriptionActive`, `DemoWriteGuard`, `EditionGuard` — none role-aware. No `role:`/`permission:` middleware anywhere. **78 authenticated routes have no server-side role or self check.** | scripted sweep, list in §7.3 | Any authenticated user, one `curl`. | The advertised Roles & Permissions matrix is cosmetic. | CRITICAL |
| SEC-03 | **The permission matrix itself is stored in a blob any user can overwrite.** `POST /app/state` accepts an arbitrary array and replaces the tenant's `app_state`, which contains `DB.roles[].perms` — the matrix `applyPerms()` reads on every login for every user. | `AppStateController.php:40-52`; `AppController.php:676-683, 769-790` | Any authenticated user; one POST. | Persistent, tenant-wide privilege escalation (grant your own role every action) **or** tenant-wide destruction (`{"state":{}}` empties every non-normalised screen, no history, no soft delete, no audit). | CRITICAL |
| SEC-04 | **Tenant isolation is 616 hand-written filters with 405 unfiltered and 354 id-only queries beside them; the automatic safety net (`CompanyScope`) is inert.** | §0.4 counts; `CompanyScope.php:31-32`; migration `…000002:13-17` | Depends on which query is reached. | Cross-tenant disclosure wherever a filter was missed. | CRITICAL |
| SEC-05 | **`when($tid, …)` fails open for `NULL` and for `0`.** Intended for `super_admin`; inherited by `platform_support` and by **any** NULL-tenant account. | Laravel `Builder::when`; `StaffController.php:49`; `AppStateController.php:19` | A vendor-created `platform_support` login. | Reads every tenant's employee directory, salary, PAN, bank and statutory data; renders as "Admin" in the SPA; is never subscription-gated. | HIGH |
| SEC-06 | **`GET /app/master/{type}` is ungated for ~56 of ~58 types.** Row narrowing exists only for `helpdesk` and `posh`. | `MasterController.php:586-588, 608-650`; registry `:33-480` | Any authenticated user, one GET per type. | Discloses `wa_settings.api_key` and `sms_settings.api_key` (`:454-465`), biometric device credentials, `companies` PAN/GSTIN, `banks`, `salary-setup`, `min-wages`, `agent-auth`, `dra-certs`, `bgv`, `documents`. Cost of a leaked WhatsApp/SMS key is direct financial. | HIGH |
| SEC-07 | **Privilege escalation: HR Manager → Admin.** `canManage` = `['super_admin','admin','hr_manager']`; an `hr_manager` may create a user with role `admin`, and may **set the password of any user in the tenant**, including the tenant admin. | `UserController.php:34-37, 150-152, 262-278` | An `hr_manager` login. | Full tenant takeover from an HR account. No audit record is written for either action. | HIGH |
| SEC-08 | **Cross-tenant account takeover primitive.** `UserController::setStatus`/`setPassword`/`save` use `->when($tid, …)`; for a NULL-tenant actor holding `admin` or `hr_manager` the filter is skipped and the target is any user id on the installation. | `UserController.php:181-182, 224-225, 247-249, 270-278` | Requires a NULL-tenant non-super_admin with a management role — creatable via `StaffController` + a pivot edit, or a `SaasController` provisioning path with a null tenant. | Password reset of another tenant's admin. | HIGH |
| SEC-09 | **The role-less user is a read-everything account presenting as Administrator.** Produced by assigning `accountant`, or by any missing pivot row. | `UserController.php:298-308`; `AppController.php:93, 769`; `AppDataController.php:224-232` | An administrator picking a legitimate dropdown option. | The SPA shows the full menu; `/app/data` returns the whole tenant; `selfScope` does not narrow. Writes 403, reads are open. | HIGH |
| SEC-10 | **IDOR — no ownership check on personnel documents and letters.** See §7.4 for the full list. | §7.4 | Any authenticated user, one GET with an incremented id. | Colleagues' warning letters, increment letters (CTC), ID cards, photos, uploaded ID documents, payslip PDFs. | HIGH |
| SEC-11 | **Password policy is length ≥ 8, and ≥ 6 for the platform's most privileged accounts.** No complexity, history, expiry, breach check. No lockout. No MFA. | `AuthController.php:401,444,527`; `UserController.php:267`; `StaffController.php:43,64`; verified-absent lockout/MFA | Online guessing at 5/min/IP with unlimited IPs and no per-account counter. | `123456` is a valid Super Admin password. | HIGH |
| SEC-12 | **A licence-check exception grants a full session.** | `AuthController.php:203-269`, catch at `:267-269` | A transient licence-server or DB error. | On-prem enforcement silently disabled. | MEDIUM |
| SEC-13 | **Account-existence and status oracle at login**, at 5 req/min/IP. | `AuthController.php:292-295` | Trivial. | Enumeration of valid work emails and of disabled accounts. | MEDIUM |
| SEC-14 | **`POST /app/change-password` is unthrottled** — an online current-password oracle. | `routes/web.php:376`; `AuthController.php:439-462` | Requires a session. | Password confirmation brute-force against the session owner's own password (relevant for a shared/borrowed session). | MEDIUM |
| SEC-15 | **Unauthenticated cross-tenant company-name oracle at `/c/{slug}`.** Loads **every** company row on the installation and matches by slugified name; sets `session.portal_company_id` to a foreign company id. | `AuthController.php:139-158` | Anonymous. | Confirms any customer's company name; seeds a foreign company id the SPA later uses; O(all companies) query per hit. | MEDIUM |
| SEC-16 | **Temporary passwords are emailed in cleartext** on the paid-signup path. | `SaasController.php:185, 558-560, 572-575` | — | Credential in a mailbox and in `wa_log`/queue payloads indefinitely. | MEDIUM |
| SEC-17 | **Public bearer tokens never expire and are stored in cleartext**, unlike password-reset tokens which are hashed. Offer accept, NDA sign, transfer accept, agent email-verify, agent live-earnings, quote pay-link, licence invoice. | `LetterController.php:402`; `TransferController.php:125`; `OffrollAgentController.php:288, 499`; `SignupController.php:613`; `OnpremClientController.php:516` — all `Str::random(40|48)`, none with an expiry column checked at read | Anyone with the URL, forever. A DB read yields all of them. | Permanent unauthenticated access to salary offers, transfer orders, agent earnings ledgers and payment links. | MEDIUM |
| SEC-18 | **Demo passkey grants a session as the demo tenant's *admin*.** | `DemoAccessController.php:365-421` (`Auth::loginUsingId($u->id)`), `:425-436` (`demoUser()` = `demo-admin@smartprs.in`) | 6-digit PIN, throttled, time-boxed, per-entry. | Bounded to the demo tenant — but that tenant is recreated nightly on any server running the scheduler (SEC-19). | MEDIUM |
| SEC-19 | **A nightly unconditional task mints three known-password logins on any server running the scheduler**, with a tenant-unscoped email lookup that takes over pre-existing accounts. | `routes/console.php:208-211`; `DemoReset.php:35, 106-122, 152` | Following the shipped cron instructions. | An `admin` login with a source-published password appears within 24 h and re-appears after every manual deletion or password change. | CRITICAL |
| SEC-20 | **Shipped default administrator credential.** `OnPremWorkspaceSeeder` creates `admin@smartprs.local` / `password` on `tenants` ordered by id, prints it, and is documented as the one-liner for a client on-prem install. Not gated by `SMARTPRS_DEMO_DATA` or `APP_ENV`. | `OnPremWorkspaceSeeder.php:74-92`; `DatabaseSeeder.php:19-30` (does not call it) | Running the documented command. | Known-credential admin on a live customer install. | HIGH |
| SEC-21 | **Identity and access management is effectively unaudited.** Only successful login is recorded. | `AuthController.php:271-284`; 11 functional `Audit::record` sites | — | No record of role changes, user creation, account disablement, admin password sets, staff CRUD or tenant provisioning. Nothing would have detected any of the above. | HIGH |
| SEC-22 | **SMTP credentials stored in recoverable cleartext** as JSON in `app_settings.value`, while Razorpay/biometric/licence/webhook secrets **are** `Crypt`-encrypted. | `ConfigController.php:47-55, 138-139, 202` vs `BillingController.php:520-523`, `BiometricConfigController.php:328`, `SmarteptWebhook.php:99` | DB read or backup read. | Tenant mail-account compromise; inconsistent with the codebase's own standard. | HIGH |
| SEC-23 | **Uploads validated by client-supplied extension and stored on the web-served disk.** Five upload paths write to `disk('public')`; only self-onboarding writes to `local` — and its files are later **copied** to `public`. There is **no** `.htaccess` in the tree. | `DocumentController.php:214-218, 234`; `LetterController.php:348, 467`; `RequestController.php:1605`; `OffrollAgentController.php:450`; `SelfOnboardingController.php:319, 323, 953-955`; `ls public/` | An HR user, or an unauthenticated holder of a self-onboarding token whose file is later promoted. | Aadhaar/PAN/bank documents on a public path; potential RCE if the deployed Apache passes `.php` in `public/storage` (depends on a config file that is not in the repo). | CRITICAL (deployment-dependent) |
| SEC-24 | **Path traversal in the commission-proof server** (report #12, confirmed). | `RequestController.php:501-503, 1626-1629` | Any employee raising a commission claim for themselves. | Arbitrary authenticated file read → `.env` → `APP_KEY` → decrypts every `Crypt` column above and forges sessions. | HIGH |
| SEC-25 | **Raw exception messages returned to authenticated callers** on ~15 endpoints in this area. | `AuthController.php:459-461`; `UserController.php:213,233,253,293`; `SaasController.php:93,122,247,365,389,420,462,489` | Any authenticated user. | Schema and path disclosure. | MEDIUM |
| SEC-26 | **Session cookie `Secure` has no default and `.env.example` does not set it**; session payloads are unencrypted; sessions have no absolute lifetime and remember-me is 5 years. | `config/session.php:35,37,50,173`; `.env.example` (absent line) vs `.env.production.example:40` | Network position / stolen device. | Session cookie over plain HTTP on any deployment built from `.env.example`. | MEDIUM |
| SEC-27 | **`EditionGuard` blocks nothing on SaaS**, so `/admin/*` and `/app/saas/*` rely entirely on per-controller `hasRole('super_admin')` — which is a single point of failure that also fails open (SEC-01). | `Edition.php:169-174`; `EditionGuard.php:42-53` | — | Loss of defence in depth on the platform's most sensitive surface. | MEDIUM |
| SEC-28 | **`Audit::verify()` loads all `activity_logs` into memory with no tenant filter or limit**, invoked whenever any tenant admin opens Activity Logs. | `Audit.php:111`; `ComputedController.php:146` | Any tenant admin clicking a menu item. | Memory exhaustion / DoS at scale. | MEDIUM |
| SEC-29 | **`TestController::take`/`submit` fetch the `tests` row and (in `submit`) the question set with no tenant filter.** | `TestController.php:220, 246, 250` | Any authenticated user, by id. | Cross-tenant read of another customer's test definition and question bank. | MEDIUM |
| SEC-30 | **`kb_topics` (incl. the Code of Conduct employees legally acknowledge) is written with `tenant_id = NULL` and read with no tenant filter.** | `KbController::ensureSeeded`, `index`, `store/update/destroy` | Any tenant admin on the installation. | One customer's admin can rewrite the Code of Conduct another customer's agents acknowledge; acknowledgements store no version. | HIGH |

### 7.3 The 78 authenticated routes with no server-side role or self check

Method: for each `Route::<verb>('path', [Controller::class,'method'])` in the four route files,
the controller method body was extracted and scanned for any of
`denyUnlessRole | hasRole | hasAnyRole | abort_unless | FeaturePermissions::guard | isSuperAdmin |
canManage | selfScope | isManager | isHR( | isFinance( | isAccounts( | this->guard( |
authorizeScreen | hrDeny`. One level of helper indirection is followed by the presence of the
helper name. Full list at
`/tmp/claude-0/-home-claude/ada0e253-2f18-51dc-9f99-3ee2bd4c0f3a/scratchpad/auth_unguarded.txt`.

**Legitimately open to every authenticated user (17):** `/app/change-password`,
`/app/first-password` (GET+POST), `/app/today`, `/app/ess/me`, `/app/ess/update`,
`/app/notifications/feed|read-all|{id}/read`, `/app/calc-guide`, `/app/help/{screen}`,
`/app/code-of-conduct/ack`, `/app/attendance/punch`, `/app/attendance/punch-status`,
`/app/my-photo` (GET+POST), `/app/attendance-corrections` (employee-raise path).

**Real gaps (61), grouped by what they expose:**

| Group | Routes | What leaks / what can be done |
|---|---|---|
| Whole-tenant state | `GET /app/state`, `POST /app/state` | read + overwrite everything, incl. the role matrix |
| Payroll | `GET /app/salary-runs/{id}/bank-file/preview`, `POST /app/salary-runs/{id}/decide`, `POST /app/salary-runs/bulk`, `POST /app/salary-lines/{id}/decide`, `POST /app/salary-lines/bulk`, `POST /app/salary-lines/{id}/acknowledge`, `GET /app/salary-lines/{id}/voucher`, `POST /app/salary-simulate` | approve/reject payroll; read every net pay; bank payable totals |
| Statutory | `GET /app/statutory/{type}/pdf` | every employee's PAN + UAN + PF wages + TDS |
| Personnel documents | `GET /app/letters/{id}/pdf`, `GET /app/idcard/{code}/pdf`, `GET /app/emp-photo/{code}`, `GET /app/increments/{id}/letter`, `GET /app/transfers/{id}/letter` | warning letters, increment letters with CTC, ID cards, photos |
| People data | `GET /app/search`, `GET /app/leaves/balances/{employee}`, `GET /app/employees/template`, `GET /app/probation/board`, `GET /app/probation/{emp}/history`, `GET /app/employee-import/history`, `GET /app/employee-import/{id}/errors.csv`, `GET /app/greetings/log`, `GET /app/absence/log`, `GET /app/attendance-report/logs/{code}/{date}` | colleague enumeration, leave balances, roster, punch logs |
| Attendance writes | `POST /app/attendance-bulk/upload`, `POST /app/attendance-bulk/{batch}/decide`, `POST /app/attendance-bulk/row/{id}/delete`, `GET /app/attendance-bulk/template`, `GET /app/attendance-corrections/day`, `POST /app/attendance-corrections/{id}/cancel` | inject and approve attendance — the direct input to pay |
| Config reads | `GET /app/greetings/config`, `POST /app/greetings/preview`, `GET /app/absence/config`, `GET /app/probation/config`, `GET /app/master/{type}/template` | tenant configuration disclosure |
| Messaging config | `GET /app/wa-templates`, `GET /app/wa-templates/export`, `POST /app/wa-templates`, `POST /app/wa-templates/{id}/delete|status|test` | `WaTemplateController` guards *inside* helper methods only for some actions (`:208-210`); `index`/`export`/`save` reached my sweep as unguarded — worth a targeted re-read before remediation |
| Incentives | `GET /app/schemes/for-me`, `POST /app/schemes`, `POST /app/schemes/{id}/withdraw|decide|reopen`, `GET /app/incentive/template` | approve/withdraw incentive schemes |
| Recruitment | `POST /app/recruitment/job/{id}/approve`, `POST /app/recruitment/job/{id}/reject` | approve headcount requisitions |
| Tests | `GET /app/tests/{id}/take`, `POST /app/tests/{id}/submit` | + cross-tenant (SEC-29) |
| Requests | `POST /app/requests/commissions/proof-upload` | upload with a caller-supplied `proof_path` (SEC-24) |
| Devices | `POST /app/mobile-devices/{id}/revoke` | revoke another user's mobile device |

### 7.4 IDOR register — endpoint takes a record id and does not verify ownership

Every entry below is reachable by **any authenticated user of the tenant**. "Tenant-scoped"
means a `tenant_id` predicate is present, so the leak is horizontal within the customer,
not cross-customer, unless stated.

| # | Route | File:line | Scoping present | What leaks |
|---|---|---|---|---|
| 1 | `GET /app/letters/{id}/pdf` | `LetterController::buildLetter` `:~390-402` (`where('l.id',$id)->when($tid,…)`) | tenant only | any colleague's offer / increment / warning / relieving / NDA letter, merged with `ctc`, `pan`, `uan`, `bank_acc`, `ifsc`, `email`, `mobile` |
| 2 | `POST /app/letters/{id}/email` | same builder | tenant only | mail any colleague's letter to an address of your choosing |
| 3 | `GET /app/idcard/{code}/pdf` | `LetterController::idcard` `:~300` | tenant only | any colleague's ID card (photo, code, designation, company) |
| 4 | `GET /app/emp-photo/{code}` | `LetterController::servePhoto` | tenant only | any colleague's photo |
| 5 | `GET /app/documents-mgr/{id}/download` | `DocumentController::download` `:263-283` | tenant + `denyUnlessRole(['admin','hr_manager','employee'])` — i.e. **every role**, no ownership test | any colleague's uploaded Aadhaar / PAN / bank proof / certificates |
| 6 | `GET /app/increments/{id}/letter` | `IncrementController::letter` | id only (`increments` id-only queries: 5) | colleague's old/new CTC, % and effective date |
| 7 | `GET /app/transfers/{id}/letter` | `TransferController::letter` | id only (`transfers` id-only: 6) | colleague's transfer order |
| 8 | `GET /app/requests/commissions/{id}/proof` | `RequestController::proofServe` `:1616-1630` | tenant + owner/manager/accounts — **but the path is caller-supplied** | arbitrary server file (SEC-24) |
| 9 | `GET /app/salary-lines/{id}/voucher`, `GET /app/salary-runs/{id}/sheet`, `GET /app/salary-runs/{id}/bank-file/preview` | `SalaryApprovalController:235-328, 416-441` | tenant only, no role check | every employee's Basic/HRA/PF/ESI/PT/TDS/gross/net for any run id |
| 10 | `GET /app/payslip/{code}/pdf` | `AppDataController::payslipPdf:1146-1160` | tenant + `selfScope` — but `selfScope` returns `null` for `field_agent`/`accountant`/role-less | those roles download **any** colleague's payslip |
| 11 | `GET /app/leaves/balances/{employee}` | `LeaveController::balances` | tenant only | any colleague's leave balances |
| 12 | `GET /app/attendance-report/logs/{code}/{date}` | `AttendanceReportController::logs` | tenant only, no self-scope | any colleague's raw punch log |
| 13 | `GET /app/tests/{id}/take`, `POST /app/tests/{id}/submit` | `TestController:220, 246, 250` | **none — cross-tenant** | another customer's test definition and question bank |
| 14 | `GET /app/probation/{emp}/history` | `ProbationController::history` | tenant only | colleague's probation extensions and remarks |
| 15 | `POST /app/users/{id}/password`, `POST /app/users/{id}/status` | `UserController:247-249, 270-278` | `when($tid,…)` — **skipped entirely for a NULL-tenant actor** | cross-tenant account takeover (SEC-08) |
| 16 | `GET /app/branding/logo/{companyId}` | `ConfigController:346-356` | tenant map only, no role | enumerate which of the tenant's companies have logos |
| 17 | `GET /app/offroll-agent/{id}/file/{slot}` | `OffrollAgentController::serveDoc` (`offroll_agents` id-only: 6) | `denyUnlessRole` on some sibling methods only | agent KYC documents by id |
| 18 | `POST /app/mobile-devices/{id}/revoke` | `MobileDeviceController::revoke` (`mobile_devices` id-only: 5) | none | revoke another user's device |

Quantified basis: **354 `DB::table()` call sites in `app/` are filtered by `where('id', …)` and
nothing else** — 57 of them on `employees`, 21 on `invoices`, 16 on `users`, 15 on
`self_onboarding`, 9 on `incentive_schemes`, 8 each on `commissions`, `companies`,
`biometric_configs`. The 18 above are the ones reachable directly from a route parameter.

### 7.5 CSRF — every exempt state-changing route, and whether it is a real risk

`ValidateCsrfToken` is applied by the `web` group (Laravel 11 default). **16 routes opt out
individually.** There is no global exclusion list in this copy (`bootstrap/` is absent, §0.3).

| Route(s) | File:line | Why exempt | Compensating control | Real risk? |
|---|---|---|---|---|
| `POST /lead` | `web.php:46-48` | a landing page open past `SESSION_LIFETIME` had a stale token → 419 | honeypot + `throttle:20,1` | **No.** Public form, no authenticated side effect. The stated *cause* is legitimate; the fix should be a token refresh, not an exemption |
| `POST /nda/{token}/sign` | `web.php:63-64` | anonymous signer | the 40-char token | **No.** The token is the credential; a forged cross-site POST needs it |
| `POST /api/mobile/register` `/status` `/push-token` | `web.php:79-87` | pre-login mobile client, no cookie | `throttle:60|120|30,1` | **No** for CSRF (no ambient session). Separately: unauthenticated device registration is its own exposure |
| `/iclock/cdata` `/devicecmd` `/fdata` `/edata` (4) | `web.php:92-105` | *"a device cannot send a token"* | `throttle:1000,1`, SN identification only | **No** for CSRF. **Yes** for everything else — these are unauthenticated writes into the payroll input, and `PushController::ensure()` runs DDL from them (SEC / report #5). Note `/iclock/getrequest` is **not** exempt (it is a GET, so CSRF never applied) |
| `POST /webhooks/razorpay` | `web.php:164-166` | server-to-server | `X-Razorpay-Signature` HMAC verified in the controller | **No** |
| `POST /update/activate` `/heartbeat` `/device/activate` `/device/deactivate` `/check` (5) | `web.php:173-187` | server-to-server | key validation inside the controller + `throttle:10..60,1` | **No**, provided the in-controller key check is sound (outside my scope) |
| `POST /webhooks/interakt` | `web.php:201-203` | server-to-server | **none stated at the route** | **Needs verification.** If `RecruitMessagingController::interaktWebhook` does not verify a signature, this is an unauthenticated write into campaign delivery state. The route comment offers no compensating control, unlike every sibling |

**Not exempt but worth naming:** `POST /logout` (`web.php:227`) sits *outside* the `guest` and
`auth` groups but inside `web`, so it keeps CSRF — correct. `POST /app/state` keeps CSRF, so the
privilege-escalation path (SEC-03) requires a session, not a cross-site POST.

### 7.6 Secrets in source and credential storage

**Present in committed source:**

| Value | Location | Assessment |
|---|---|---|
| `'ametecs'` — default team-demo PIN | `config/smartprs.php:32` | Dead (nothing reads `smartprs.team_pin`; `DemoAccessController.php:28` documents its removal). Still a credential literal in committed config |
| `'password'` — admin password | `database/seeders/OnPremWorkspaceSeeder.php:78` | **Live.** Documented one-liner for a client on-prem install; ungated |
| `'password'` — demo user password | `database/seeders/DemoCompanySeeder.php:69` | Gated by `SMARTPRS_DEMO_DATA` + non-production via `DatabaseSeeder.php:19-30` |
| `smartprs-demo` — demo login password | `app/Console/Commands/DemoReset.php:35` | **Live and scheduled nightly, ungated** (SEC-19) |
| `36AAHCT0971F1ZB`, seller address, phones, `ejaz@ametecsindia.com`, WhatsApp 9000098877 | `.env.production.example:102-108`; `EditionGuard.php:44-45` | Not secrets; production-identifying values committed to source |
| `rzp_live_abc` / `topsecret` / `whsec123` | `tests/Feature/BillingTest.php:76, 96, 121` | Test fixtures, clearly fake |

**Verified absent:** a systematic grep for `(secret|api_?key|password|token)\s*=\s*'…'` outside
`env()`/`config()`/request input returned **no** live credential; no `rzp_live_`, `sk_live`,
or `AKIA…` pattern in any non-test file. `config/smartprs.php:69` deliberately has **no**
default for `SMARTPRS_LICENCE_SECRET`.

**Credential storage practice:**

| Credential | Storage | Verdict |
|---|---|---|
| User passwords | bcrypt via `Hash::make` / the `'password' => 'hashed'` cast, cost 12 (no `config/hashing.php`) | correct |
| Password-reset / invite tokens | `hash('sha256', $token)` at rest, raw token only in the emailed URL | correct |
| API keys | `hash('sha256', $secret)`, compared with `hash_equals` | correct |
| SmartEPT webhook secrets | `Crypt::encryptString` (`SmarteptWebhook.php:99`) | correct |
| Razorpay key/webhook secrets | `Crypt::encryptString` (`BillingController.php:520-523`) | correct |
| Biometric device passwords | `Crypt::encryptString` (`BiometricConfigController.php:328`) | correct |
| Licence keys | `Crypt::encryptString` (`LicenseService.php:308, 367`) | correct |
| **Tenant SMTP passwords** | **plain JSON in `app_settings.value`** (`ConfigController.php:47-55`) | **wrong — the only outlier** |
| **Public bearer tokens** (offer/NDA/transfer/agent/quote/licence) | **cleartext columns, no expiry** | **wrong** |
| Demo passkeys | cleartext `demo_requests.pin`, shown in the Super Admin register (`DemoAccessController.php:459`) | acceptable for a demo, by design |
| Everything `Crypt`-encrypted | keyed on `APP_KEY` | **all of it is decrypted by the `.env` read at SEC-24** |

### 7.7 File upload and download

| Path | Validation | Disk | Served by | Guessable URL? |
|---|---|---|---|---|
| Employee documents (`POST /app/documents-mgr/upload`) | extension only, `ALLOWED_EXTS` 8 types, 10 MB, per-employee quota | **`public`** — `store('employee-docs','public')` `:234` | authenticated controller `:263-283`, **and** directly at `/storage/employee-docs/<40-char>.<ext>` if `storage:link` is run | filename is `hashName()` (40 random chars) → not enumerable, **but** `documents.file_path` is returned to any user who can list documents |
| Onboarding documents | extension only, 5 MB, PDF/JPG/PNG | `local` at upload (`:323`) → **copied to `public`** at hire (`:953-955`) | `hrDeny`-guarded controller for the `local` copy; the `public` copy is served by the web server with no auth | directory is the sequential `employee_id`; filename is the 40-char hash |
| Employee photos | none beyond `store()` | **`public`** `LetterController.php:348, 467` | `GET /app/emp-photo/{code}` (no role check) and directly | hashName |
| Commission proofs | prefix test on a **caller-supplied path** `:501-503` | **`public`** `:1605` | `GET /app/requests/commissions/{id}/proof` | traversal (SEC-24) |
| Off-roll agent KYC | — | **`public`** `OffrollAgentController.php:450` | `GET /app/offroll-agent/{id}/file/{slot}` | hashName |
| Selfies | base64 → jpg | `local` `SelfOnboardingController.php:300-301` | authenticated / token controller | **correct pattern — the model to generalise** |

**No MIME or magic-byte check exists anywhere:** `grep -rn "finfo_\|getMimeType()" app/` → **0**.
Only 7 `'mimes:'` validation rules exist in the entire application, none on these paths.
`config/filesystems.php:38-44` maps the `public` disk to `storage/app/public` with
`'visibility' => 'public'` and `links` at `:69-71` symlinks `public/storage` → it.
**`public/.htaccess` does not exist in this tree** (§0.3), so on Apache there is no
version-controlled deny rule and no `php_flag engine off`. `public/web.config:36-46` blocks the
`storage` segment — on IIS only.

---

## 8. Documentation-vs-source discrepancies

| # | Claim | Source says | Assessment |
|---|---|---|---|
| 1 | Report #3: onboarding docs are published *"at `employee-docs/<employee_id>/<original filename>`… so it is enumerable, unlike DocumentController's random names"* | `SelfOnboardingController.php:953` — `$base = basename($local)` where `$local` is a `$file->store(…,'local')` result, i.e. a `hashName()` — a 40-char random name with the extension `guessExtension()` derived from the bytes | **Overstated on one detail.** The directory is enumerable; the filename is not. The substance (unauthenticated public path, no tenant check, no expiry, no revocation) stands |
| 2 | Report #3 / §5: *"`public/.htaccess` contains no equivalent rule"* | `public/` contains only `index.php` and `web.config` — **there is no `.htaccess` at all** | **Understated.** The Apache configuration is not in version control, so its content is unknowable from the repo. This makes the deployment-dependent RCE (report #4) *less* verifiable, not less serious |
| 3 | Doc §13.16 `FINDING-SEC-09`: *"Identity and access management is entirely unaudited"* | `AuthController.php:271-284` audits **successful login** | **Partly wrong.** Successful login *is* audited. Logout, failed login, role assignment, user create/disable, admin password set, staff CRUD and tenant provisioning are not. Restate as "unaudited except successful login" |
| 4 | Doc §13.4.2 gives `hasRole` counts of 56 / 38 / 24 per role | Total `hasRole(`+`hasAnyRole(` sites across the tree = **62**; per-role figures in the doc appear to count string *occurrences* inside multi-role arrays, not call sites | Not a contradiction, but the doc's per-role numbers are not call-site counts and should not be read as such |
| 5 | Doc §13 and `API_REFERENCE.md` §1.1 cite `bootstrap/app.php:8-46` verbatim | **`bootstrap/` does not exist in `/home/claude/prs2/`** | The reference analysts had a fuller tree. Every claim about the global middleware stack, route mounting and `withExceptions()` is **unverifiable in this copy** and is marked INFERRED throughout this document |
| 6 | `routes/web.php:161-163` comment: *"the source tree has no bootstrap/app.php to register exceptions in"* | The reference documents quote `bootstrap/app.php` at length | **The comment is factually wrong** in the tree the analysts saw; it happens to be true of *this* copy. Either way the exemptions are per-route |
| 7 | `AuthController.php:313` comment: *"the project has no `password_reset_tokens` migration"* | It exists at `0001_01_01_000000_create_users_table.php:24-28`, and `config/auth.php:96` points the broker at it | Comment wrong; the runtime-created `user_password_resets` table is unnecessary |
| 8 | `AppDataController.php:227-229` comment: *"the Directory/bootstrap callers keep the stricter employee-only default"* | Scoping *fewer* roles discloses to *more* users; the employee-only default is the **looser** option | Comment inverts the security meaning of its own code |
| 9 | `Company.php:12` docblock: *"A tenant. In SaaS each Company is an isolated tenant"* | A `Company` is a child of a `tenant`; a tenant has many companies; isolation is by `tenant_id` | Docblock describes a model the code abandoned |
| 10 | `CompanyScope.php:10-16` docblock: *"Global scope that constrains every query on a tenant-owned model to the currently authenticated user's company"* | It constrains nothing — `users.company_id` does not exist | Docblock describes behaviour that has never worked |
| 11 | `FeaturePermissions.php:9-15`: *"gates writes SERVER-SIDE with `ApprovalService::denyUnlessRole()`"* | True for 71 endpoints; **78 authenticated endpoints have no such gate**, including payroll approval and whole-tenant state overwrite | The design statement describes an aspiration, not the shipped state |
| 12 | `config/smartprs.php:85-94` header *"Roles (locked — see CONTEXT-HANDOFF.md)"* | Nine distinct role strings are checked in code; four of them cannot be created | "Locked" is not true |
| 13 | `Edition.php` docblock: *"one codebase, four faces… L1 CORE / L2 ADVANCED / L3 DNA"* | `hiddenNavIds()` `:119-127` and `blockedPatterns()` `:169-174` return the same value for all three; `level()` `:93-97` returns a constant `3` | Editions are marketing-only in the shipped code |
| 14 | Report §2.4 records *"Constant-time comparison correctly used for both credential types"* | Confirmed: `ApiKeyAuth.php:108` `hash_equals`; `SmarteptWebhook::signatureMatches` | Correct — recorded as a verified positive |
| 15 | Report §2.4 records *"SQL injection: none found"* | My own grep of `whereRaw` sites in this area (`AuthController.php:292, 347, 409, 418`; `UserController.php:168, 174, 282`; `SaasController.php:136`; `DemoReset.php:106`) confirms every one uses `?` placeholders | Correct |

---

## 9. Migration verdict per capability

| Capability | Verdict | Rationale | Data-migration implication |
|---|---|---|---|
| Session authentication (email + password, `web` guard) | **RETAIN** | Standard Laravel; bcrypt correct; the defects are in policy, not mechanism | Carry `users` forward; force a password reset on migration because the current policy admits `123456` |
| Password policy | **REPLACE** | Length-only, split across 6 files, 6 chars for the most privileged | New policy table/config; existing hashes remain valid; flag every account for rotation |
| Account lockout | **REPLACE (build new)** | Does not exist | Nothing to migrate |
| MFA | **REPLACE (build new)** | Does not exist | Nothing to migrate |
| Password reset / invite tokens | **REDESIGN** | Correct crypto, three duplicate runtime table definitions, three different literal expiry windows | Migrate `user_password_resets` into a single migrated table; expiry becomes configuration |
| Public bearer-token portals (offer, NDA, self-onboard, transfer, agent, quote, licence) | **REDESIGN** | Good entropy; no expiry, no revocation, cleartext at rest | Hash tokens at rest; add `expires_at` + `revoked_at`; existing tokens must be invalidated |
| Spatie roles | **RETAIN as the storage layer**, **REDESIGN as the model** | The pivot works; `teams => false` blocks per-tenant roles; the role set is hard-coded in 5 disagreeing places | Enable teams (or add a tenant column); migrate the 5 seeded roles; **repair every user whose `accountant` assignment silently produced no role** |
| Spatie permissions | **REPLACE** | 13 seeded, 0 read | Discard the seeded set; design a real capability catalogue |
| `ApprovalService::denyUnlessRole` | **REPLACE** | Fails open; role-string arrays scattered over 71 sites; lives in the controller namespace | None (logic moves to policies/middleware) |
| `FeaturePermissions` registry | **REDESIGN** | Right idea (a capability registry), wrong implementation (11 entries, hard-coded role arrays, unknown key → `admin`+`hr_manager`) | Its 11 capability keys are the seed of the SmartPRS 3 capability catalogue |
| Client-side `PERM_NAV` matrix | **REMOVE** | Cosmetic, fails open three ways, stored in a user-writable blob | The per-role `perms` objects inside `app_state` must be **extracted into a real `role_permissions` table** before the blob is discarded |
| `AppStateController` / `app_state` blob | **REMOVE** | Ungated whole-tenant read/write; XSS sink; the permission matrix lives inside it | One-off extraction of every collection still stored there into normalised tables — this is the largest single data-migration item in this area |
| `CompanyScope` / `BelongsToCompany` | **REMOVE** | Inert; predicated on a column that does not exist | None — it has never written or filtered anything |
| Hand-written `when($tid, …)` tenancy | **REPLACE** | 1375 call sites, 44.8 % filtered, no safety net, fails open on NULL and 0 | Every tenant-owned table needs a NOT NULL `tenant_id` + FK + a backfill for existing NULL rows (`kb_topics`, `attendance_logs`, `biometric_configs`, `notices` are known to hold them) |
| `selfScope` (data scope) | **REPLACE** | Two-branch role-name test; the only data-scope mechanism in the product | Replace with a declarative scope model (self / reporting-line / team / branch / company / tenant) |
| `/app/users` (tenant user admin) | **REDESIGN** | Function is right; must not let `hr_manager` mint `admin`s or set arbitrary passwords; must be audited; must not fail open on NULL tenant | `users` carries forward |
| `/admin/staff` (platform staff) | **REDESIGN** | `min:6`; `platform_support` has zero checks and the widest scope | Platform users carry forward; `platform_support` needs a real capability set before migration |
| `SaasController` tenant provisioning | **RETAIN, REDESIGN details** | Works end-to-end; emails cleartext temporary passwords; runs 4 `ALTER TABLE`s on a page load | `tenants`, `companies`, `plans` carry forward; the runtime columns must become migrations |
| `TenantController` | **REMOVE** | Unrouted, targets a schema that does not exist | None |
| Legacy `company_id` controllers (`Employee`, `Department`, `Attendance`, `Loan`, `SalaryRun`, `Payslip`, `Device`) | **REMOVE** | Superseded; `Company::value('id')` fallback is a cross-tenant write hazard; only `POST /app/device/{id}/sync` still reaches one | None |
| Six custom middleware | **REDESIGN (all)** | Four fail open; none is role-aware; `EditionGuard` and `LicenseGate` encode commercial policy in regex arrays | None |
| `ApiKeyAuth` + `ApiKeys` | **RETAIN** | The single best-engineered security component in the codebase: hashed at rest, `hash_equals`, scope check, tenant binding enforced, NULL-tenant refused, throttled touch | `api_keys` carries forward |
| `SmarteptSignature` | **RETAIN** | HMAC over the raw body, uniform failure response, tenant binding enforced | `smartept_webhook_endpoints` carries forward |
| `/iclock/*` receiver | **REDESIGN** | Unauthenticated write into the payroll input + unauthenticated DDL | `biometric_configs` rows with `tenant_id = NULL` must be claimed or purged before migration |
| `Audit` (`activity_logs` hash chain) | **REDESIGN** | Only 11 write sites; chain is global across tenants; `verify()` is unbounded | Chain must be re-anchored **per tenant**; existing rows verify only as one global chain |
| CSRF posture | **RETAIN, tighten** | 16 exemptions, 15 defensible; `/webhooks/interakt` needs a verified signature | None |
| Session/cookie configuration | **REDESIGN** | No `Secure` default, no absolute lifetime, 5-year remember-me, unencrypted payloads | None |
| Uploads and document serving | **REPLACE** | Extension-only validation; 5 paths write to the web-served disk; no `.htaccess` in the tree | **Every file under `storage/app/public/{employee-docs,emp-photos,commission-proofs,offroll-docs}` must be moved to a private disk and `documents.file_path` rewritten.** Audit access logs first (DPDP breach assessment) |
| `DemoReset` / `OnPremWorkspaceSeeder` / `DemoCompanySeeder` | **REMOVE from any production artefact** | Known-credential admin creation, one of them scheduled nightly and ungated | Purge any `demo-*@smartprs.in` and `admin@smartprs.local` accounts found on customer installations |

---

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
