# AS-IS ANALYSIS — DATABASE LAYER

> Every claim below was confirmed against the source under `/home/claude/prs2/`.
> Paths are relative to that root. Where the reference documents and the source
> disagree, the source wins and the discrepancy is recorded in §8.

---

## 0. Scope and evidence base

### 0.1 Reference documents read

| Document | Lines read | Purpose |
|---|---|---|
| `refs/DATABASE_DICTIONARY.md` | 1–3097 (all) | Primary reference; every material claim re-verified against source |
| `refs/COMPLETE_PROJECT_BUSINESS_AND_TECHNICAL_DOCUMENTATION.md` | 30993–34093 (§08 Database Layer) | Cross-check |
| `refs/COMPLETE_PROJECT_BUSINESS_AND_TECHNICAL_DOCUMENTATION.md` | 53684–53984 (data lifecycle / retention / delete & archive) | Retention analysis |
| `refs/PROJECT_ARCHITECTURE_AND_WORKFLOWS.md` | 2868–3312 (Database architecture overview) | Cross-check |

### 0.2 Source read in full

| File / group | Files | Lines |
|---|---|---|
| `database/migrations/*.php` | **49 of 49** | 3,661 |
| `database/seeders/*.php` | **7 of 7** | 748 |
| `app/Support/SchemaHelper.php` | 1 | 140 |
| `app/Services/EffectiveDated.php` | 1 | 136 |
| `app/Models/*.php` + `Concerns/BelongsToCompany.php` + `Scopes/CompanyScope.php` | 11 + 2 | ~430 |
| `config/database.php`, `config/session.php`, `config/cache.php`, `config/queue.php` | 4 | ~350 |
| `sbb-schema-status.txt` (live dev-DB schema dump, 22 Aug 2026), `version.json` | 2 | 140 |

### 0.3 Source read in the regions that mutate schema at runtime

`app/Http/Controllers/MasterController.php` (1–640, 800–1000, 1180–1224),
`AppDataController.php` (40–175, 860–900, 2340–2700), `ApprovalService.php` (1–300),
`PayrollGenController.php` (2279–2700), `RequestController.php` (60–110, 960–1270),
`SalaryApprovalController.php` (25–100), `SelfOnboardingController.php` (24–100, 660–690,
910–940, 1112–1131), `SchemeController.php` (25–130), `AttendanceReportController.php`
(28–70, 365–400), `AttendanceBulkController.php` (33–60), `DeviceController.php` (205–240),
`BiometricConfigController.php` (40–90), `PushController.php` (240–290),
`DocumentController.php` (70–105, 295–330), `SettingsController.php` (74–115),
`ConfigController.php` (22–55), `LandingController.php` (70–100), `KbController.php` (22–55),
`CodeOfConductController.php` (20–80), `TestController.php` (22–65),
`RecruitmentController.php` (88–165, 645–680), `OffrollAgentController.php` (30–100),
`OnboardingController.php` (38–80), `HiringDriveController.php` (26–80),
`MobileDeviceController.php` (32–62), `RecruitMessagingController.php` (32–72),
`WaTemplateController.php` (70–95), `SignupController.php` (28–60), `LeadController.php`
(24–55), `DemoAccessController.php` (76–140), `TenantBillingController.php` (42–90),
`BankPackController.php` (32–60), `PerformanceController.php` (18–45), `AuthController.php`
(312–340, 475–500), `UserController.php` (312–340), `SaasController.php` (528–560),
`LetterController.php` (470–495), `Console/Commands/SeedIndustryContent.php` (130–225),
`DemoReset.php`, `ClearAppState.php`, `PurgeDemoAttendance.php`, `DedupeAttendance.php`;
services `LicenseService.php` (20–140), `MailService.php` (45–80), `WaService.php` (20–50),
`CouponService.php` (20–70), `LateArrivalService.php` (28–60), `ETimeOfficeService.php`
(355–385), `SubscriptionService.php` (195–230), `PeriodResolver.php` (210–245),
`Audit.php` (20–70), `EmployeeFieldRules.php` (1–120, 570–615),
`AttendanceCorrectionService.php` (290–310).

### 0.4 Exhaustive greps performed

| Pattern | Hits |
|---|---|
| `Schema::create` in `app/` `routes/` `updater/` `public/` | 72 |
| `Schema::table` in the same | 85 |
| `Schema::hasTable` / `hasTable(` | 380 |
| `hasColumn(` | 346 |
| `DB::statement` with DDL in `app/` | **6** (all `ALTER TABLE`) |
| `DB::table(` | 1,413 |
| `->foreign(` / `->constrained(` in `database/` + `app/` | **1** (`foreignId()` without `constrained`) + 4 vendor |
| `->unique(` | 28 in migrations, 17 in `app/` |
| `->delete()` | 43 |
| `truncate` (DDL) | 0 |

---

## 1. Business capability inventory

The database layer is not a designed schema — it is **two competing schema authorities that
both run in production**. Everything below follows from that.

### 1.1 Capability: "the app repairs its own database"

The stated project convention (`SchemaHelper.php:10-26`, and the same paragraph copied into
~40 controllers) is that **any table or column may be created on first use**, guarded by
`hasTable`/`hasColumn`, so that a deploy never fails on a missing migration and a client
install never dies mid-upgrade. The migration comment at
`database/migrations/2026_01_01_000100_create_employees_table.php:11-25` states the origin:
a client install died with `SQLSTATE[42S01] ... Table 'training_programs' already exists`
because MySQL DDL is not transactional, so a half-applied migration is never recorded and is
retried from the top forever.

**Business effect.** No two SmartPRS installations are guaranteed to have the same schema.
The schema an install ends up with depends on *which screens a user opened, in what order,
before which deploy*.

### 1.2 Capability: master-data screens that invent their own tables

`MasterController` (`app/Http/Controllers/MasterController.php:30-493`) holds a registry of
**54 master screens**. `ensureCols()` (`:496-577`) will, for any screen whose table does not
exist, create it with **only `id`, `tenant_id`, `timestamps`** (`:502-506`) and then bolt on
each declared column with a coarse type map (`:556-576`): `string→VARCHAR(255)`,
`decimal→DECIMAL(12,2)`, `int→INTEGER`, `bool→BOOLEAN DEFAULT 0`, `date→DATE`,
`datetime→DATETIME`, `text→LONGTEXT`, `coord→DECIMAL(11,7)`.

This is how `roster`, `overtime`, `awards`, `notices`, `dra_certs`, `min_wages`, `policies`,
`feature_flags`, `posh_complaints` and `pay_cycles` came to exist with no migration at all.

### 1.3 Capability: multi-tenancy

Two mechanisms were built. One is dead (§7). The live one is a hand-written
`->when($tid, fn($q) => $q->where('tenant_id', $tid))` on every query — 1,413 `DB::table()`
call sites.

### 1.4 Capability: soft delete and archive

Soft delete exists on **8 tables only**. A separate "Backed up / Old data" archive exists for
employees only (`employees.archived_at` + `employee_archives.snapshot` JSON), with a 3-day
grace period (`employees.backup_due_at`). Everything else is a hard `DELETE`.

### 1.5 Capability: data lifecycle / retention

**None exists.** Confirmed by grep (§9.5).

### 1.6 Capability: audit

Two overlapping ledgers: the app's own hash-chained `activity_logs`
(`app/Services/Audit.php:25-46` adds `prev_hash`/`row_hash` at runtime), and Spatie's
`activity_log`, which is written only by model events on models that no live route ever
instantiates (§6.2). Neither is ever pruned.

---

## 2. Data model

### 2.1 THE DEFINITIVE TABLE INVENTORY *(question 1)*

Computed by extracting every table name from `Schema::create(...)`, `$this->mk(...)` and
`SchemaHelper::ensureTable(...)` in `database/migrations/`, and every
`Schema::create(...)` plus every `'table' => '...'` entry in the `MasterController` registry
in `app/`.

| Category | Count |
|---|---|
| Tables created by a **migration** (literal names) | **96** |
| Vendor/config-named migration tables (`permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions`, `activity_log`) | **6** |
| **Total migration-defined** | **102** |
| Tables created by **runtime `Schema::create()`** in `app/` | 60 |
| Tables auto-created by **`MasterController::ensureCols()`** | 54 (registry entries, 47 distinct tables + overlaps) |
| Tables defined in **BOTH** a migration and runtime code | **52** |
| Tables that exist **ONLY at runtime** (no migration anywhere) | **49** |
| Laravel's own `migrations` ledger | 1 |
| **TOTAL DISTINCT TABLES in a fully exercised live database** | **152** |

#### 2.1.1 The 49 runtime-only tables, with creating file:line

| # | Table | Created at | Business content |
|---|---|---|---|
| 1 | `agent_earnings` | `app/Http/Controllers/OffrollAgentController.php:78` | Off-roll agent payout ledger — **money** |
| 2 | `app_settings` | `ConfigController.php:30`; also `DemoAccessController.php:120` | Tenant app config (branding, mail) |
| 3 | `attendance_ratings` | `AttendanceReportController.php:48` | Supervisor rating per employee-day |
| 4 | `awards` | `MasterController.php:410` (def) → `:502` | Awards register |
| 5 | `bank_invoices` | `BankPackController.php:40` | **GST invoices raised to client banks — statutory** |
| 6 | `biometric_unmapped` | `app/Services/ETimeOfficeService.php:362` | Unmapped device codes |
| 7 | `client_updates` | `app/Services/LicenseService.php:125` | Update check/download log |
| 8 | `coupon_redemptions` | `app/Services/CouponService.php:47` | Discount redemption ledger — **money** |
| 9 | `coupons` | `CouponService.php:28` | Discount codes |
| 10 | `demo_requests` | `DemoAccessController.php:84` | Public demo PINs + PII (name/phone/email) |
| 11 | `dra_certs` | `MasterController.php:315` (def) → `:502` | **DRA certification register — RBI-regulated evidence** |
| 12 | `feature_flags` | `MasterController.php:428` (def) → `:502` | Feature toggles |
| 13 | `hiring_drive_candidates` | `HiringDriveController.php:56` | Drive attendance + outcome |
| 14 | `hiring_drives` | `HiringDriveController.php:31` | Walk-in / scheduled drives |
| 15 | `interviews` | `RecruitmentController.php:141` | Interview rounds + scores |
| 16 | `job_openings` | `RecruitmentController.php:117` | Vacancies / requisitions |
| 17 | `leads` | `LeadController.php:30` | Marketing enquiries + PII |
| 18 | `licence_devices` | `LicenseService.php:88` | Licence seat activations |
| 19 | `licence_events` | `LicenseService.php:78` | Licence lifecycle audit |
| 20 | `licences` | `LicenseService.php:59` | **Issued licence keys (hash + encrypted key)** |
| 21 | `loan_recoveries` | `PayrollGenController.php:2590` | **Per-run EMI recovery audit — money** |
| 22 | `mail_log` | `app/Services/MailService.php:54` | Outbound email delivery log |
| 23 | `min_wages` | `MasterController.php:328` (def) → `:502` | **State minimum wages — statutory** |
| 24 | `mobile_devices` | `MobileDeviceController.php:37` | Mobile device registration + approval gate |
| 25 | `notices` | `MasterController.php:422` (def) → `:502` | Notice board |
| 26 | `onboarding` | `OnboardingController.php:44`; `SelfOnboardingController.php:669`; MC def `:404` | Onboarding board |
| 27 | `onboarding_items` | `OnboardingController.php:58` | Onboarding checklist items |
| 28 | `onprem_clients` | `LicenseService.php:27` | On-prem customer + licence commercials — **money** |
| 29 | `onprem_payments` | `LicenseService.php:47` | Payments against a licence — **money** |
| 30 | `overtime` | `MasterController.php:340` (def) → `:502` | **Overtime register — feeds payroll** |
| 31 | `pay_cycles` | `MasterController.php:449` (def) → `:502` | **Payroll period definition (21st→20th)** |
| 32 | `performance_reviews` | `PerformanceController.php:23`; MC def `:416` | Performance reviews |
| 33 | `policies` | `MasterController.php:334` (def) → `:502` | Policy repository |
| 34 | `posh_complaints` | `MasterController.php:134` (def) → `:502` | **Confidential POSH register — legally privileged** |
| 35 | `recruitment` | `RecruitmentController.php:94` (id/tenant_id/timestamps only) | Candidate pipeline + PII |
| 36 | `recruitment_campaigns` | `RecruitMessagingController.php:37` | Bulk WhatsApp campaigns |
| 37 | `recruitment_messages` | `RecruitMessagingController.php:53` | Per-candidate message + response |
| 38 | `release_grants` | `LicenseService.php:115` | Release entitlement |
| 39 | `releases` | `LicenseService.php:102` | Product releases + sha256 |
| 40 | `renewals` | `TenantBillingController.php:69` | Renewal orders — **money** |
| 41 | `roster` | `MasterController.php:398` (def) → `:502` | **Per-day shift roster — feeds attendance & payroll** |
| 42 | `salary_payments` | `RequestController.php:977` | **Salary disbursement ledger — money** |
| 43 | `signups` | `SignupController.php:34` | Signup funnel — **money** |
| 44 | `statutory_settings` | `SettingsController.php:82` | **The tenant-wide PF/ESI/PT/TDS rate blob** |
| 45 | `subscription_alerts` | `app/Services/SubscriptionService.php:206` | Expiry reminder ledger |
| 46 | `test_questions` | `TestController.php:27` | MCQ bank |
| 47 | `user_password_resets` | `AuthController.php:320`; `UserController.php:318`; `SaasController.php:535` | **Password reset tokens** |
| 48 | `wa_log` | `app/Services/WaService.php:28` | WhatsApp send log |
| 49 | `wa_templates` | `WaTemplateController.php:77` | WhatsApp template registry |

**The single most alarming entry is #44.** `statutory_settings` holds the *entire* PF, ESI,
PT and TDS rate configuration for every tenant, in one JSON blob, in a table that exists only
because `SettingsController::ensureTable()` (`:77-88`) ran. It has no migration. A database
restored from a `mysqldump` taken before that screen was ever opened has no statutory rates.

**Second most alarming: #31 `pay_cycles`.** Migration
`2026_07_26_000400_add_payroll_period_columns.php:25-28` calls
`SchemaHelper::ensureColumns('pay_cycles', [...])`, and `SchemaHelper::ensureColumns` returns
an empty array immediately if the table does not exist (`SchemaHelper.php:66-68`). So on any
install where the *Pay Cycle master screen was never opened*, that migration is a **silent
no-op** and the entire configurable-payroll-period feature (F5) reverts to hardcoded calendar
month with no error anywhere.

#### 2.1.2 Migration-defined tables that no code ever reads or writes (dead schema)

Verified by grepping the literal table name across `app/`, `resources/`, `routes/`:

| Table | Refs | Note |
|---|---|---|
| `attendance` | 0 | The original per-day attendance table. **The live punch ledger is `attendance_logs`.** Has the only meaningful `unique(employee_id, date)` in the schema — on a table nothing uses. |
| `employee_company` | 0 | Employee↔company pivot for multi-company staff |
| `pf_esi_settings` | 0 | Superseded by the `statutory_settings` JSON blob |
| `tds_rules` | 0 | Same |
| `notifications_feed` | 0 | Superseded by `notification_log` |
| `company_emails` | 0 | Per-department from-address |
| `password_reset_tokens` | 0 | Superseded by runtime `user_password_resets` |
| `activity_log` (Spatie) | 0 | Written only by dead models |
| `salary_runs`, `salary_slips`, `leave_applications` | 0 | Named by dead Eloquent models; **never created by any migration either** |
| `cache`, `cache_locks`, `sessions`, `jobs`, `job_batches`, `failed_jobs`, `personal_access_tokens` | infra | `SESSION_DRIVER=database`, `QUEUE_CONNECTION` default `sync` |

`pt_slabs` is referenced 7 times — but every reference is to the string `'pt_slabs'` as a
**JSON key** inside `statutory_settings` / `statutory_configs`
(`StatutoryConfigController.php:64,93,287`, `AppDataController.php:1986`), never as a table.
The `pt_slabs` **table** is dead.

### 2.2 Provenance table — the 52 tables with TWO definitions

Full drift analysis is §6.1; this is the provenance list.

| Table | Migration | Runtime | Agree? |
|---|---|---|---|
| `attendance_logs` | `2026_06_24_000000:30-44` | `AttendanceReportController.php:33`, `DeviceController.php:215` | **NO** |
| `attendance_pending` | `2026_08_16_000300:28`, `2026_08_18_000100:35` | `AttendanceBulkController.php:40` | **NO — different feature entirely** |
| `settings` | `2026_01_01_000700:25-33` | `LandingController.php:78` | **NO** |
| `incentive_schemes` | `2026_01_01_000300:84-94` | `SchemeController.php:33-55` | **NO — disjoint column sets** |
| `documents` | `2026_01_01_000600:124-133` | `DocumentController.php:77`, `SelfOnboardingController.php:920` | **NO** |
| `code_of_conduct_ack` | `2026_01_01_000600:94-101` | `CodeOfConductController.php:27-35` | **NO** |
| `test_attempts` | `2026_01_01_000600:83-92` | `TestController.php:43-55` | **NO** |
| `training_subjects` | `2026_01_01_000600:59-67` | `SeedIndustryContent.php:192-201` | **NO** |
| `offroll_agents` | `2026_01_01_000500:13-26` | `OffrollAgentController.php:35`, `RecruitmentController.php:652` | **NO** |
| `training_programs` | `2026_01_01_000600:35-45` | `SeedIndustryContent.php:178-189` | **NO (status case)** |
| `letters` | `2026_01_01_000600:112-122` | `SeedIndustryContent.php:136-146` | **NO** |
| `faqs` | `2026_01_01_000600:103-110` | `SeedIndustryContent.php:204-211` | **NO** |
| `biometric_configs` | `2026_06_25_000000:32-50` | `BiometricConfigController.php:47`, `PushController.php:248` | converges via additive columns |
| `kb_topics` | `2026_01_01_000900:11-23` | `KbController.php:30`, `SeedIndustryContent.php:214` | YES |
| `self_onboarding`, `_otps`, `_docs` | `2026_07_22_000001` | `SelfOnboardingController.php:29,61,73` | YES (byte-identical) |
| `late_notifications` | `2026_07_22_000100:18-29` | `LateArrivalService.php:36-47` | YES |
| `commission_payments` | `2026_06_24_000000:79-93` | `ApprovalService.php:167-179` | YES |
| `commission_logs` | `2026_06_24_000000:96-106` | `ApprovalService.php:278-286` | YES |
| `transfers` | `2026_06_24_000000:48-76` | `ApprovalService.php:51-77` | YES |
| `shifts` | `2026_07_07_000000:30-46` | MC def `MasterController.php:232-236` | partial (MC adds no columns the migration lacks) |
| the other 31 | migration | `MasterController::ensureCols()` additive only | additive-only; see §6.1.10 |

---

## 3. Business rules register

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HARD-CODED? | Configurable today? | Customer may want different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-TEN-001 | A user with NULL `tenant_id` is a platform super admin and sees every tenant | `users.tenant_id` | `tenant_id IS NULL` | `->when($tid,…)` omits the filter | all queries | 1,413 `DB::table()` sites; idiom at `AppDataController.php:884` | **Y** | No | N (but must be an explicit role check, not an omission) |
| BR-TEN-002 | A tenant whose `subdomain = 'demo'` is the locked public demo | `tenants.subdomain` | `= 'demo'` | Administration menu hidden; email suppressed | tenant | `DemoAccessController::isDemoTenant()`; `OnPremWorkspaceSeeder.php:42-57` | **Y** | Only by renaming the subdomain | **Y** — a customer legitimately named "demo" is crippled |
| BR-TEN-003 | Super-admin payroll actions are attributed to the **lowest-id tenant** | `users.tenant_id` NULL | NULL | `?? DB::table('tenants')->value('id')` | payroll | `AppDataController.php:2512` (and 5 sibling sites) | **Y** | No | **Y** — this is a defect, not a rule |
| BR-EMP-001 | Employee code is unique **per tenant**, not per company and not globally | `tenant_id`, `emp_code` | duplicate | DB rejects | employees | `2026_01_01_000100:158` `unique(['tenant_id','emp_code'])` | **Y** | No | **Y** — a group with 3 companies may want per-company codes |
| BR-EMP-002 | PAN, mobile, email, device ID, UAN, ESIC, National ID and bank account must be unique per tenant — **enforced only in PHP** | those columns | another live employee has the value | save is refused with a message | employees | `app/Services/EmployeeFieldRules.php:576-611`; declared `'u' => 'block'` at `:109,111,115,167,186,191,200,227` | **Y** | The field list is a PHP const | **Y** — some customers share a family mobile; some need PAN non-unique for contractors |
| BR-EMP-003 | Uniqueness ignores soft-deleted and archived employees | `deleted_at`, `archived_at` | NOT NULL | row excluded from the duplicate check | employees | `EmployeeFieldRules.php:588,596` | **Y** | No | **Y** |
| BR-EMP-004 | Duplicate lookup columns get a **plain index, never UNIQUE**, deliberately | — | — | `->index()` only | employees | `2026_08_28_000100:46-53,121-138` and its comment `:122-124` | **Y** | No | Y — SmartPRS 3 should make it a real constraint |
| BR-ATT-001 | A punch's identity is `(tenant_id, emp_code, punch_at, source)` | punch | duplicate | DB rejects | attendance_logs | `2026_08_16_000200:91-94` `attlog_natural_unique` | **Y** | No | N |
| BR-ATT-002 | A re-sent SBB batch must be a no-op — sender idempotency key | `external_id` | duplicate | DB rejects | attendance_logs | `2026_08_16_000200:87-90` `attlog_tenant_external_unique` | **Y** | No | N |
| BR-ATT-003 | An unmapped device PIN is **held**, not discarded | device PIN | no employee match | row → `attendance_pending`, replayed on mapping | attendance | `2026_08_16_000300` header comment `:8-19` | **Y** | No | N |
| BR-ATT-004 | One late-arrival email per employee per day | tenant, employee, date | second attempt | insert rejected | late_notifications | `2026_07_22_000100:28` `late_notif_unique` | **Y** | No | **Y** — a customer may want per-shift or per-punch |
| BR-ATT-005 | One attendance rating per `(emp_code, log_date)` — **no tenant in the key** | emp_code, date | duplicate | DB rejects **across tenants** | attendance_ratings | `AttendanceReportController.php:57` | **Y** | No | **Y** — this is a defect (§6.4) |
| BR-PAY-001 | One payroll run per `(company, cycle_label)` — enforced in **PHP only** | company_id, month | existing row found | 409 unless `regenerate=true` | payroll_runs | `PayrollGenController.php:2279-2289, 2360-2368` | **Y** | No | N (but must become a DB constraint) |
| BR-PAY-002 | A run past draft cannot be regenerated | `payroll_runs.status` | in `hr_approved, approved, locked, paid` | 409 | payroll | `PayrollGenController.php:2362-2365` | **Y** | The status list is a PHP array | **Y** — the lock point is a policy decision |
| BR-PAY-003 | Regenerating a draft **hard-deletes** its payslips and the run row | run id | regenerate | `DELETE FROM payslips WHERE run_id`; `DELETE FROM payroll_runs` | payroll | `PayrollGenController.php:2389-2390` | **Y** | No | **Y** — most customers need the superseded version retained |
| BR-PAY-004 | Regeneration reverses commission locks, overtime, loan EMI and clawback recoveries best-effort | run id | regenerate | 4 `try/catch` blocks that never fail the run | payroll | `PayrollGenController.php:2398-2470` | **Y** | No | N (but must be transactional) |
| BR-PAY-005 | Configuration is read **as of a date**, never "latest" | `effective_from` | `<= $on` | latest qualifying row wins; ties on highest `id` | statutory, pay cycle, probation | `app/Services/EffectiveDated.php:39-66` | **Y** | No | N — correct pattern, only 3 tables use it |
| BR-PAY-006 | A NULL `effective_from` means "always in force" | `effective_from` | IS NULL | ordered as `1900-01-01` | same | `EffectiveDated.php:56-61` | **Y** | No | Y — ambiguous with "not yet effective" |
| BR-CFG-001 | Saving an effective date inside a finalised payroll month requires super admin | date, tenant, company | period finalised | 403 unless super admin | config | `EffectiveDated.php:102-118` | **Y** | No | **Y** — the reopen authority is a policy |
| BR-CFG-002 | The guard **fails open** on any exception | — | throw | returns null = allowed | config | `EffectiveDated.php:115-117` | **Y** | No | **Y** — a security control that fails open |
| BR-SCH-001 | Any master table may be created on first screen open with `id`/`tenant_id`/`timestamps` | screen type | table missing | `Schema::create` | 54 masters | `MasterController.php:501-507` | **Y** | No | **Y** — this must not exist in SmartPRS 3 |
| BR-SCH-002 | Any missing employees column is added as `VARCHAR(255) NULL` | column name | missing | `$t->string($c)->nullable()` | employees | `AppDataController.php:70-74` | **Y** | No | **Y** — destroys DATE typing (§9) |
| BR-SCH-003 | Any approvable table gets `approver_id/approver_name/decided_by/decided_at/remarks` on first use | table | column missing | `ALTER TABLE` | 9 tables | `ApprovalService.php:238-259`; guaranteed at install `2026_06_24_000000:131-159` | **Y** | The 9-table list is a PHP array | **Y** |
| BR-SCH-004 | A legacy ENUM `status` that lacks `pending`/`approved` is silently widened to `VARCHAR(20)` **on every request** | table | ENUM without those values | `ALTER TABLE ... MODIFY status VARCHAR(20)` | 9 approvable tables | `ApprovalService.php:224-234` | **Y** | No | **Y** — runtime DDL on a request path |
| BR-SEC-001 | A webhook's tenant comes from the endpoint row, never the payload | slug | — | tenant read off `smartept_webhook_endpoints` | webhooks | `2026_08_26_000100` header `:15-19` | **Y** | No | N — correct |
| BR-LIC-001 | A licence key is stored **hashed and encrypted** (so the panel can re-show it) | key | — | `key_hash` unique + `key_enc` Crypt + `key_last4` | licences | `LicenseService.php:63-65` | **Y** | No | N |
| BR-LIC-002 | One licence seat = one server fingerprint | `device_uid` | — | row in `licence_devices` | licences | `LicenseService.php:91` | **Y** | No | Y |
| BR-DEL-001 | A master row is soft-deleted **iff its table happens to have `deleted_at`**, else hard-deleted | table | `hasColumn('deleted_at')` | `update(deleted_at)` else `delete()` | 54 masters | `MasterController.php:1202-1206` | **Y** | No | **Y** — retention must be a policy, not an accident of schema |
| BR-DEL-002 | An employee is archived, not deleted, after a 3-day grace period | emp_code | `backup_due_at <= now()` | JSON snapshot → `employee_archives`; `archived_at` set | employees | `AppDataController.php:2507-2531, 2612-2659` | **Y** | 3 days is `now()->addDays(3)` at `:2526` | **Y** — the grace period must be configurable |
| BR-DEL-003 | Bulk employee delete is a soft delete that keeps history and frees a seat | emp codes | admin role | `update(deleted_at)` | employees | `AppDataController.php:877-897` | **Y** | No | N |
| BR-RET-001 | `data_retention_months` = 84 (7 years) | — | — | **nothing reads it** | — | `SettingsController.php:61` | **Y** | It is a settings key with no consumer | **Y** |
| NEW-DB-001 | `deleted_at` filtering is applied only where `Schema::hasColumn` says the column exists — otherwise silently skipped | table | column absent | filter omitted | ~5 tables | `EssController.php:171`, `OnboardingController.php:80`, `MasterController.php:636` | **Y** | No | **Y** — silently inert filters |
| NEW-DB-002 | `wa_settings.company_id` is relaxed from NOT NULL to NULL by a raw `ALTER` on a request path | — | column is NOT NULL | `ALTER TABLE` | wa_settings | `MasterController.php:524-532` | **Y** | No | **Y** |
| NEW-DB-003 | `lat`/`lng` columns wrongly created as `DECIMAL(12,2)` are repaired to `DECIMAL(11,7)` on every master request | — | `NUMERIC_SCALE != 7` | `ALTER TABLE` | geofence_rules, branches | `MasterController.php:536-551` | **Y** | No | **Y** — evidence of prior silent 1 km coordinate truncation |
| NEW-DB-004 | `incentive_schemes` legacy NOT NULL columns are relaxed and the ENUM status widened, on a request path | — | `SHOW COLUMNS` says NOT NULL | `ALTER TABLE` | incentive_schemes | `SchemeController.php:102-117` | **Y** | No | **Y** |
| NEW-DB-005 | `code_of_conduct_ack.employee_id` is relaxed to NULL by raw ALTER | — | column exists | `ALTER TABLE` | code_of_conduct_ack | `CodeOfConductController.php:62-69` | **Y** | No | **Y** |

---

## 4. Calculations and formulas

The database layer itself carries few formulas; the ones it does carry are **type and
precision decisions that silently change results**.

### 4.1 Coordinate precision — the 1 km truncation

`MasterController::ensureCols()` maps `'decimal' → DECIMAL(12,2)` (`:563-564`). Latitude and
longitude were originally captured through that path, giving **2 decimal places**.

```
1 degree of latitude ≈ 111.32 km
0.01 degree          ≈ 1.113 km
```

So a stored coordinate was accurate to **±557 m**, on a feature whose whole purpose is a
geofence with a radius often set to 0.5 km. The repair added a `'coord'` type
(`:561-562` → `DECIMAL(11,7)`, ≈ **1.1 cm**) plus an idempotent `ALTER` that rewrites any
column whose `NUMERIC_SCALE != 7` (`:536-551`).

**Rounding behaviour:** MySQL rounds on `DECIMAL` insert. A punch at `12.9715987` was stored
as `12.97`; the haversine distance (`AttendanceReportController.php:394-401`, R = 6371.0 km)
then measured from a point up to 557 m away, so a legitimate punch inside a 500 m fence read
as `outside`. Any row written before the repair is **permanently wrong** — the `ALTER` widens
the column but cannot restore the discarded digits.

### 4.2 Money precision — the declared scales

| Scale | Where | Max value |
|---|---|---|
| `DECIMAL(12,2)` | `employees.ctc`, `payslips.gross/total_ded/net`, `commissions.amount`, `advances.*`, `loans.*`, `expenses.amount`, `deductions.amount`, `increments.old_ctc/new_ctc`, `bonus_encashment.amount`, `salary_payments.amount`, `commission_payments.amount`, `loan_recoveries.amount`, all `MasterController` `'decimal'` columns | ₹ 9,999,999,999.99 |
| `DECIMAL(14,2)` | `payroll_runs.net_total`, `payout_recon.expected/received/variance`, `bank_invoices.*`, `agent_earnings.*`, `incentive_schemes.max_amount`, `offroll_agents.rate` (runtime) | ₹ 999,999,999,999.99 |
| `DECIMAL(12,2)` | `offroll_agents.rate` (**migration**) — see §6.1.5 | — |
| `DECIMAL(5,2)` | `pf_ee_pct`, `pf_er_pct`, `eps_pct`, `esi_ee_pct`, `esi_er_pct`, `comm_pct`, `pct`, `tds_rate`, `pass_mark`, `score` | 999.99 |
| `DECIMAL(4,2)` | `attendance.hours`, `late_policy.full_day_hours`, `employees.geo_radius_km` | **99.99** |
| `DECIMAL(4,1)` | `leaves.days` | **999.9** |
| `DECIMAL(5,1)` | `leave_types.days_per_year`, `training_subjects.hours` | 9999.9 |
| `DECIMAL(10,7)` | `branches.lat/lng`, `employees.home_lat/home_lng`, `geofence_rules.lat/lng` (migration) | ±999.9999999 |
| `DECIMAL(11,7)` | the same columns after the `MasterController` repair | ±9999.9999999 |
| `DECIMAL(10,6)` | `attendance_logs.lat/lng` (`AttendanceReportController.php:382`) | ≈ 11 cm |

**Worked example — the `DECIMAL(4,2)` ceiling.**
`late_policy.full_day_hours DECIMAL(4,2)` (`2026_01_01_000200:66`) caps at 99.99, which is
fine. But `MasterController`'s late-policy screen declares `full_day_hours` and
`half_day_hours` as `'decimal'` (`:239`), which its type map turns into `DECIMAL(12,2)`
(`:564`). On a **migrated** install the column is `(4,2)`; on a **runtime-created** install
it is `(12,2)`. The values agree in practice, but the two installs are not the same schema —
and `shifts.full_day_hours` was deliberately declared `DECIMAL(12,2)` in its migration
(`2026_07_07_000000:40`) precisely to match the runtime type. Two sibling columns for the same
concept, two precisions.

### 4.3 The de-duplication rule (transcribed exactly)

`2026_08_16_000200_extend_attendance_logs_for_sbb.php:137-155` and its identical twin in
`2026_08_17_000100_repair_sbb_schema.php:213-233`:

```php
$key = ($r->tenant_id ?? 'null').'|'.$r->emp_code.'|'.$r->punch_at.'|'.($r->source ?? '');
if (isset($keep[$key])) { $doomed[] = $keep[$key]; }   // ids ascend → the held row is older
$keep[$key] = $r->id;
```

**Keep-newest-by-id.** Note the key uses `emp_code` **verbatim**, i.e. case-sensitively in
PHP, while `AttendanceCorrectionService.php:298` and `BiometricConfigController.php:688`
match the same column with `LOWER(emp_code)`. A tenant with `A101` and `a101` punches has two
different identities in the de-duplicator and one in the correction service.

### 4.4 The effective-dated resolution (transcribed exactly)

`app/Services/EffectiveDated.php:56-62`:

```php
$q->where(function ($w) use ($col, $onDate) {
    $w->whereNull($col)->orWhere($col, '<=', $onDate);
});
return $q->orderByRaw("COALESCE($col, '1900-01-01') DESC")->orderByDesc('id')->first();
```

Worked example. `statutory_configs` rows for `(tenant 5, kind 'esi')`:

| id | effective_from | esi_ee_pct |
|---|---|---|
| 11 | NULL | 0.75 |
| 12 | 2026-04-01 | 0.75 |
| 13 | 2026-10-01 | 0.60 |

Resolving on `2026-08-15` sorts `2026-04-01 > 1900-01-01`, excludes id 13, returns **id 12**.
Resolving on `2026-03-01` excludes ids 12 and 13, returns **id 11 (NULL)**. So a NULL
`effective_from` is the permanent fallback — it can never be superseded *backwards*, which is
correct, but it also means a row saved with a blank date silently becomes "in force since the
beginning of time" rather than being rejected.

Only **three** tables use this: `statutory_configs`, `pay_cycles`, `probation_events`
(scoped). `salary_components`, `late_policy`, `shifts`, `holidays`, `leave_types`,
`pt_slabs`, `min_wages` — every other payroll-affecting configuration table — have **no
effective dating at all** and are edited in place, so a past payslip cannot be re-explained.

---

## 5. Configuration surface today

### 5.1 (a) What a customer CAN change without code

| # | What | How | Table |
|---|---|---|---|
| 1 | Salary components (earning/deduction, fixed/percent/formula, sequence, taxable, scope) | Masters → Salary Setup | `salary_components` |
| 2 | Late policy (grace minutes, full/half-day hours, lates-before-cut, 3 late bands, break budget, week-off action) | Masters → Late Policy | `late_policy` |
| 3 | Shift timings, grace override, night allowance, break budget | Masters → Shifts | `shifts` |
| 4 | Pay cycle: `cutoff_day` `''` (calendar) or `20` (21st→20th) — **a two-item dropdown** | Masters → Pay Cycle | `pay_cycles.period_end_day` |
| 5 | Statutory rates (PF/ESI/PT/TDS percentages, ESI ceiling, TDS slabs) as a JSON blob | Settings → Statutory | `statutory_settings.value` |
| 6 | Effective-dated, scoped statutory overrides (company / state / branch / tenant) | Statutory Config screen | `statutory_configs` |
| 7 | Leave types, entitlement days, carry-forward, paid flag | Masters → Leave Types | `leave_types` |
| 8 | Holiday calendar | Masters → Holidays | `holidays` |
| 9 | Incentive schemes: rate type, rate, TDS rate, payout method, validity, applicability, caps | Schemes screen | `incentive_schemes` |
| 10 | Points rules (event → ± points) | Masters → Points Rules | `point_rules` |
| 11 | Biometric device connection, `emp_prefix`, In/Out machine IDs, 1-device vs 2-device mode, sync interval | Biometric Device Setup | `biometric_configs` |
| 12 | Geofence per employee and per rule (start point, radius, outside rule) | Employee form / Geofence master | `employees.*`, `geofence_rules` |
| 13 | Minimum wages by state/zone/category with effective date | Masters → Min Wages | `min_wages` |
| 14 | Letter templates with `{{placeholders}}` | Masters → Letter Templates | `letters` (`is_template=1`) |
| 15 | SMS / WhatsApp provider credentials and templates | Settings | `sms_settings`, `wa_settings`, `sms_templates`, `wa_templates` |
| 16 | KB articles with per-role visibility (`kb_topics.roles` JSON) | KB screen | `kb_topics` |
| 17 | Landing page content, branding, app settings | Settings | `settings`, `app_settings` |
| 18 | Plans, coupons, gateways | Super admin | `plans`, `coupons`, `payment_gateways` |
| 19 | Roles and permissions (Spatie) | Roles screen | `roles`, `permissions` |
| 20 | `data_retention_months` — **stored but read by nothing** | Settings | `statutory_settings` blob |

### 5.2 (b) What a customer CANNOT change without code — the SmartPRS 3 backlog

Ordered by business impact.

| # | Cannot change | Where it is hardcoded | Why a customer would want it |
|---|---|---|---|
| B1 | **Which tables exist.** Adding an entity means writing PHP | `MasterController::def()` `:32-482` — a 450-line PHP array | The entire "change configuration, not code" premise |
| B2 | **Which columns an entity has**, and their type | `MasterController::ensureCols()` `:496-577`; type map `:556-576` | Custom fields are the #1 HR-platform request |
| B3 | **Custom fields on `employees`** | `EmployeeFieldRules::FIELDS` `:80+` — a PHP const; the header at `:29-35` literally documents "add one row to FIELDS, add it to the parity migration" as the procedure | Every customer has 3–15 fields nobody anticipated |
| B4 | **Which employee fields must be unique** | `'u' => 'block'` flags in `EmployeeFieldRules` | Contractors share PAN; families share mobiles |
| B5 | **Employee code uniqueness scope** (tenant, not company) | `2026_01_01_000100:158` | Group companies with independent code series |
| B6 | **Enum value sets** on 30 columns | `enum()` in migrations (§6.6) | Every status vocabulary is a customer policy |
| B7 | **Soft-delete vs hard-delete per entity** | Accident of whether the table has `deleted_at` — `MasterController.php:1202-1206` | Retention is a legal obligation, not a schema accident |
| B8 | **Retention period / purge** | Does not exist | DPDP Act 2023, contractual retention, storage cost |
| B9 | **Right to erasure / anonymisation / data export** | Does not exist | DPDP Act 2023 statutory obligation |
| B10 | **Employee archive grace period (3 days)** | `now()->addDays(3)` `AppDataController.php:2526` | Some want 0, some want 30 |
| B11 | **What "archive an employee" copies** | `gatherEmployeeData()` — a fixed table list | — |
| B12 | **Payroll regeneration deletes the prior payslips** | `PayrollGenController.php:2389-2390` | Most auditors require the superseded version |
| B13 | **Which payroll statuses lock a run** | `['hr_approved','approved','locked','paid']` `:2362` | The lock point is a policy |
| B14 | **Which tables are "approvable"** | `ApprovalService::modules()` `:29-42` | A customer may want document approvals, roster approvals |
| B15 | **Approval amount thresholds** (₹10,000 / ₹50,000 / 0) | `ApprovalService::modules()` `:30-37` | Every customer's delegation matrix differs |
| B16 | **Which config is effective-dated** (3 tables of ~12) | `EffectiveDated` call sites | Salary components and late policy must be effective-dated |
| B17 | **Pay cycle options: only calendar or 21st→20th** | `AppController.php:10882` `opts: ['', '20']` — a two-item dropdown, despite `PeriodResolver` supporting any `period_end_day` | A 25th→24th customer is one dropdown entry away but cannot self-serve |
| B18 | **`tenant_id` vs `company_id` scoping per table** | Baked into 152 table definitions | Group-company data segregation |
| B19 | **Which tenant a super admin's action is attributed to** | `?? DB::table('tenants')->value('id')` | — |
| B20 | **The `subdomain='demo'` lockdown** | `DemoAccessController::isDemoTenant()` | A real customer named "demo" |
| B21 | **Money precision** (12,2 vs 14,2 vs 4,2) | migration declarations | A ₹100 crore payroll total exceeds `DECIMAL(12,2)` |
| B22 | **Whether a period column is DATE or VARCHAR** | non-deterministic (§9) | — |
| B23 | **Index strategy** | migration declarations | Performance at 50k employees |
| B24 | **Referential integrity** | does not exist | — |
| B25 | **Which columns are audited** | `Audit::record()` call sites, 37 of them | An auditor asks "who changed this salary component?" |
| B26 | **Actor identity in audit trails is a NAME STRING** (`decided_by`, `approver_name`, `created_by`, `by`, `entered_by`, `archived_by`, `rated_by`) | every approval table | A renamed user makes history ambiguous and non-repudiable |

---

## 6. Defects, conflicts and technical debt

### 6.1 SCHEMA DRIFT — every divergence between migration and runtime *(question 2)*

For each: the two definitions, the divergence, and the consequence.

#### 6.1.1 `attendance_logs` — THREE definitions · CRITICAL

| Column | Migration `2026_06_24_000000:30-44` | `AttendanceReportController.php:33-45` | `DeviceController.php:215-226` |
|---|---|---|---|
| `emp_code` | `string` **NOT NULL**, `index` | `string` **NOT NULL**, `index` | `string` **NULL**, `index` |
| `log_date` | `date` **NOT NULL**, `index` | `date` **NOT NULL**, `index` | `date` **NULL**, `index` |
| `punch_at` | `dateTime` **NOT NULL** | `dateTime` **NOT NULL** | `dateTime` **NULL** |
| `direction` | `string(4)` **NOT NULL** | `string(4)` **NOT NULL** | `string(255)` **NULL** |
| `source` | `string` default `'biometric'` | `string` default `'biometric'` | `string` **NULL, no default** |
| `company_id` | nullable **+ index** | nullable **+ index** | nullable, **no index** |
| composite index `(emp_code, log_date)` | **present** | **present** | **ABSENT** |

**Consequence.** On any install where the Devices screen was opened before the June migration
ran, the punch ledger has (a) no NOT NULL guarantee — a punch row with a NULL `punch_at` and
NULL `log_date` is accepted and then silently excluded from every date-ranged payroll query,
(b) no `source` default, so `PurgeDemoAttendance` (`source='demo'`) and the SBB natural key
`(tenant_id, emp_code, punch_at, source)` both behave differently, and (c) **no composite
index**, so `PayrollGenController::presentDays()` full-scans the punch ledger for every
employee in every payroll run. The live dev DB holds 1,254 punches
(`sbb-schema-status.txt`); at a real client this is millions.

`direction` is widened to `string(8)` by `2026_08_16_000200:70-81` and again by
`2026_08_17_000100:143-149` — but only where `Schema::hasColumn` finds it, and both attempts
are wrapped in `try/catch { report($e); }`, so a failure is invisible.

#### 6.1.2 `attendance_pending` — TWO INCOMPATIBLE TABLES SHARING ONE NAME · **CRITICAL**

| Purpose | Columns | Source |
|---|---|---|
| **(a) SBB biometric quarantine** | `tenant_id, company_id, device_sn, device_code, device_user_id, punch_at, direction, verify_mode, external_id, source, resolved_at` + `unique(tenant_id,external_id)` + `unique(tenant_id,device_code,punch_at,source)` | `2026_08_16_000300:28-43`; `2026_08_17_000100:83-97`; `2026_08_18_000100:35-50` |
| **(b) Bulk-CSV attendance upload staging** | `tenant_id, batch (uuid), emp_code, emp_name, log_date, in_at, out_at, status, error, uploaded_by, decided_by, decided_at` | `AttendanceBulkController.php:40-55` |

The two column sets are **completely disjoint apart from `id`, `tenant_id`, `timestamps`.**
Every creator opens with `if (Schema::hasTable('attendance_pending')) return;`
(`AttendanceBulkController.php:37-39`; `2026_08_16_000300:24-26`), so **whichever runs first
wins and the other feature is permanently broken** with
`SQLSTATE[42S22] Unknown column`.

`2026_08_17_000100_repair_sbb_schema.php` exists *only* because this happened on the dev box
— its header (`:11-19`) says so explicitly: *"attendance_pending existed but had no
device_user_id, so Settings → Pending Punches died with `SQLSTATE[42S22]: Unknown column
'device_user_id' in 'field list'`."* The repair migration adds the SBB columns to whatever
table it finds, which then leaves the table carrying **both** column sets, with
`punch_at` deliberately nullable (`:110`, with the comment *"punch_at is nullable here on
purpose"*) — so the SBB natural key can now admit rows with a NULL instant. Neither feature's
invariants hold afterwards.

#### 6.1.3 `settings` — TWO shapes · HIGH

| | Migration `2026_01_01_000700:25-33` | `LandingController.php:78-84` |
|---|---|---|
| `tenant_id` | `unsignedBigInteger` **nullable**, index | `unsignedBigInteger` **default 0**, index |
| `value` | **`json`** | **`longText`** |
| `key` | `string`, no index | `string`, **index** |
| unique | **`unique(['tenant_id','key'])`** | **none** |

**Consequences.** (1) On MySQL a `json` column *rejects* invalid JSON; `longText` accepts
anything — so a value that saves on one install throws `3140 Invalid JSON text` on another.
(2) With no unique key, `updateOrInsert(['tenant_id'=>0,'key'=>'landing'])` can match zero
rows and insert a **duplicate**; subsequent reads use `->first()`
(`LandingController.php:93`), so the landing page silently reverts to an older row.
(3) Two sentinels for "platform scope" coexist: `tenant_id = 0` (`LandingController.php:80`,
`DummyDataSeeder.php:259`) and `tenant_id = NULL` (migration). A lookup written against one
matches nothing written by the other.

#### 6.1.4 `incentive_schemes` — TWO DISJOINT SHAPES · HIGH

| Migration `2026_01_01_000300:84-94` | `SchemeController.php:33-55` |
|---|---|
| `name` **NOT NULL** | `title` (150) NOT NULL |
| `portfolio`, `basis`, `slabs` (json), `clawback` (bool), `status` default `active` | `purpose, portfolio, rate_type, rate_value, tds_rate, payout_method, valid_from, valid_till, applies_to, team, employee_ids, max_claims, max_amount, status, notes, created_by, created_by_emp` |

The **only** shared column is `portfolio` and `status`. On a migrated install `name` is
`NOT NULL` with no default, and `SchemeController` writes `title` — so on strict MySQL every
scheme save threw `1364 Field 'name' doesn't have a default value`. The fix was not a
migration but **runtime raw DDL**: `SchemeController.php:102-117` runs `SHOW COLUMNS FROM
incentive_schemes` on every request and issues
`ALTER TABLE incentive_schemes MODIFY <col> ... NULL` for `name`, `basis`, `clawback`,
`company_id`, `company_name`, plus `MODIFY status VARCHAR(20)` if it is still an ENUM. A
third writer, `MasterController`'s `incentive-schemes` master (`:250-253`), still uses the
*old* `name/basis/clawback` vocabulary. **Three writers, two vocabularies, one table.**

#### 6.1.5 `offroll_agents` — HIGH

| | Migration `2026_01_01_000500:13-26` | Runtime (`OffrollAgentController.php:35-48`, `RecruitmentController.php:652-665`) |
|---|---|---|
| `tenant_id` | **NOT NULL** + index | nullable + index |
| `company_id` | **NOT NULL** + index | **ABSENT** |
| `company_name` | absent | `string` nullable |
| `name` | **NOT NULL** | nullable |
| `mobile` | `string(20)` | `string(255)` |
| `rate` | **`DECIMAL(12,2)`** | **`DECIMAL(14,2)`** |
| `status` | default `'active'` | nullable, **no default** |

**Consequence.** On a *migrated* install, `RecruitmentController::createOffrollFromCandidate`
(`:677`) inserts through `ApprovalService::safeRow()` (`:263-266`), which filters to existing
columns but supplies **no `company_id`** — a `NOT NULL` column with no default. Strict MySQL
returns `1364`, so converting a hired candidate to an off-roll agent fails on exactly the
installs that ran migrations. On a runtime-created install the same insert works but the
agent has no company at all, and the rate scale differs by 100×.

#### 6.1.6 `documents` — THREE shapes · MEDIUM

| | Migration `2026_01_01_000600:124-133` | `DocumentController.php:77-86` | `SelfOnboardingController.php:920-933` |
|---|---|---|---|
| `tenant_id` | **NOT NULL** + index | **NOT NULL** + index | **nullable** + index |
| `status` | default `'pending'` | default `'pending'` | **nullable, no default** |
| `category`,`doc_name`,`file_name`,`file_size` | absent (added by `DocumentController.php:88-95`) | added after create | **in the create** |

**Consequence.** If self-onboarding created the table first, `documents.tenant_id` is
nullable. Every read uses `->when($tid, …)` (`DocumentController.php:316,326`), so a document
row with a NULL tenant is invisible to its owner and visible to a super admin — and a
NULL-tenant row is never deleted by the per-tenant purge.

#### 6.1.7 `code_of_conduct_ack` — MEDIUM

| Migration `2026_01_01_000600:94-101` | `CodeOfConductController.php:27-35` |
|---|---|
| `employee_id` **NOT NULL** + index | **ABSENT** from the create; `user_id` + `employee` (string) instead |
| `acknowledged` default **false** | default **true** |

`MasterController`'s `code-of-conduct` master (`:289-293`) maps `employee → employee_id` via
`emp_map`, so on a runtime-created table the master screen writes to a column that does not
exist. `CodeOfConductController.php:62-69` then issues raw
`ALTER TABLE code_of_conduct_ack MODIFY employee_id BIGINT UNSIGNED NULL` on a request path to
undo the migration's NOT NULL. **The default flipped from false to true**: a row created by
the runtime path is acknowledged unless explicitly set otherwise — a compliance artefact that
defaults to "yes".

#### 6.1.8 `test_attempts` — MEDIUM

| Migration `2026_01_01_000600:83-92` | `TestController.php:43-55` |
|---|---|
| `tenant_id` NOT NULL, `employee_id` NOT NULL, `test_id` NOT NULL | all **nullable** |
| `status` default `'pending'` | nullable, **no default** |
| `score` **`DECIMAL(5,2)`** (max 999.99) | **`DECIMAL(8,2)`** (max 999,999.99) |
| — | adds `company_id`, `employee` (string), `test` (string) |

#### 6.1.9 `training_subjects`, `training_programs`, `faqs`, `letters` — MEDIUM

| Table | Migration | `SeedIndustryContent.php` | Divergence |
|---|---|---|---|
| `training_subjects` | `program_id` bigint + index, **no `tenant_id`** (`:59-67`) | `program` **string**, `tenant_id` (`:192-201`) | **The link column is a different column of a different type.** `MasterController`'s `training-content` `ref_map` (`:438`) writes `program_id`, which does not exist on the seeder variant → `Unknown column`. And on the migration variant the table has **no tenant column at all** |
| `training_subjects.hours` | `DECIMAL(5,1)` nullable | `DECIMAL(5,1)` default 0 | plus `MasterController` `'decimal'` → `DECIMAL(12,2)` if added there. **Three variants** |
| `training_programs.status` | default **`'active'`** (`:43`) | default **`'Active'`** (`:187`) | **Case conflict.** Any `where('status','active')` misses seeded rows |
| `faqs.question` | `string(255)` **NOT NULL** (`:107`) | `string(500)` **nullable** (`:208`) | A 300-char FAQ saves on one install, throws `1406 Data too long` on the other |
| `letters` | `tenant_id` NOT NULL, `company_id` NOT NULL, `letter_type`, `issued_on` date, `status` default **`'draft'`**, `pdf_path` (`:112-122`) | `tenant_id` nullable, **no `company_id`**, `is_template`, `title`, `body` longText, `status` default **`'active'`**, no `issued_on`, no `pdf_path` (`:136-146`) | Different default status; missing NOT NULL `company_id` breaks the seeder path on migrated installs |

#### 6.1.10 The 31 `MasterController`-touched tables — additive drift · MEDIUM

`ensureCols()` never *changes* an existing column — it only adds missing ones with its coarse
type map. So for the 31 tables that exist in both places, the divergence is one-directional:

- A column the migration typed narrowly (`string(20)`, `DECIMAL(4,2)`, `date`) keeps that
  type on a migrated install and gets the coarse type (`VARCHAR(255)`, `DECIMAL(12,2)`,
  `DATE`) on a runtime-created one.
- `salary_components.calc_value` is `string` in the migration (`2026_01_01_000300:32`) but
  declared `'decimal'` in the master def (`MasterController.php:245`). On a migrated install
  it stays **VARCHAR**; on a runtime install it is **DECIMAL(12,2)**. A formula string like
  `"0.4 * BASIC"` saves on one and becomes `0.40` on the other. **This is a live payroll
  correctness divergence.**
- `messages_log.sent_at` is `timestamp` in the migration (`:195`) but declared `'date'` in the
  master def (`:323`) — the time of a broadcast is lost on a runtime install.
- Every runtime-created master table gets `tenant_id` **nullable** (`:504`) even where the
  migration made it NOT NULL.

### 6.2 The entire Eloquent model layer is dead and wrong · HIGH

| Model | `$table` implied | Table that actually exists | Fillable columns that exist |
|---|---|---|---|
| `Employee` | `employees` ✓ | `employees` | **0 of 13.** Declares `employee_code, first_name, last_name, phone, position, date_of_joining, gross_salary` (`app/Models/Employee.php:26-40`); the table has `emp_code, name, mobile, designation_id, doj, ctc` |
| `Attendance` | `attendances` | **`attendance`** (singular) | declares `check_in/check_out`; the column names are `punch_in/punch_out` |
| `LeaveApplication` | `leave_applications` | **`leaves`** | — |
| `SalaryRun` | `salary_runs` | **`payroll_runs`** | — |
| `SalarySlip` | `salary_slips` | **`payslips`** | — |
| `Loan` | `loans` ✓ | `loans` | declares `principal, installment_amount, installments_total, installments_paid, start_month`; the table has `amount, emi, tenure, outstanding` |
| `Company` | `companies` ✓ | `companies` | declares `code, legal_name, city, state, pincode, deployment, is_active` — **none exist** |
| `Department` | `departments` ✓ | `departments` | declares `code, description, is_active` — none exist |
| `Device` | `devices` ✓ | `devices` | declares `ip_address, port, serial_number` — added only by `MasterController` at runtime |

Their only consumers (`EmployeeController`, `AttendanceController`, `SalaryRunController`,
`LoanController`, `PayslipController`, `DepartmentController`) are imported at
`routes/web.php:4-16` but **never routed** — the only routed method on any of them is
`DeviceController::syncById` (`routes/web.php:518`), which uses the query builder, not the
model. `User::casts()` casts `is_active`, a column `users` does not have
(`app/Models/User.php:38`).

### 6.3 Multi-tenancy: the declared mechanism is dead · HIGH *(question 7 context)*

`CompanyScope::apply()` (`app/Models/Scopes/CompanyScope.php:124-139`) ends with:

```php
if (! empty($user->company_id)) {
    $builder->where($model->getTable().'.company_id', $user->company_id);
}
```

I grepped every `Schema::table('users', …)` in the repository. There are exactly four:
`2026_01_01_000002` (adds `tenant_id`, `employee_id`, `status`),
`AuthController.php:483` (`must_set_password`), `LetterController.php:479` (`photo_path`),
and the migration's `down()`. **`users.company_id` is never created anywhere.** The file that
would create it is named `2026_01_01_000002_add_company_id_to_users_table.php` and adds three
columns, none of which is `company_id` — the filename is a lie about its own content
(`:13-17`).

Therefore `$user->company_id` is always `null`, `! empty(null)` is `false`, and the `where` is
**never appended**. `BelongsToCompany::bootBelongsToCompany()` (`:94-98`) gates its auto-fill
on the same expression, so `company_id` is never auto-populated either.

Impact today: **none**, because the 8 models using the trait are unreachable. Impact if
anyone revives `EmployeeController`: `Employee::all()` returns **every tenant's employees**.

### 6.4 Cross-tenant unique key · MEDIUM

`attendance_ratings` declares `unique(['emp_code','log_date'])`
(`AttendanceReportController.php:57`) with **no `tenant_id`**. Two tenants that both use
employee code `E001` cannot both rate their own `E001` on the same day — the second insert
throws a duplicate-key error, and the first tenant's rating is what the second tenant's screen
reads back. Every other unique key in the codebase is tenant-prefixed; this one is not.

`devices.unique_id` is likewise globally unique (`2026_01_01_000200:19`) — two tenants cannot
register readers with the same manufacturer serial.

`users.email` is globally unique (`0001_01_01_000000:17`), so **one person cannot hold
accounts in two tenants** — a real limitation for consultants and for the SaaS model.

### 6.5 Runtime DDL on request paths · HIGH

Six raw `ALTER TABLE` statements execute **inside HTTP request handling**, not in migrations:

| File:line | Statement | Trigger |
|---|---|---|
| `MasterController.php:528` | `ALTER TABLE wa_settings MODIFY company_id BIGINT UNSIGNED NULL` | every `wa-settings` master request |
| `MasterController.php:547` | `ALTER TABLE <table> MODIFY <col> DECIMAL(11,7) NULL` | every master request with a `coord` column whose scale ≠ 7 |
| `ApprovalService.php:229` | `ALTER TABLE <table> MODIFY status VARCHAR(20) NOT NULL DEFAULT 'pending'` | **every** list / apply / decide / inbox call on any of 9 tables |
| `SchemeController.php:107` | `ALTER TABLE incentive_schemes MODIFY status VARCHAR(20) NULL DEFAULT 'active'` | every Schemes request |
| `SchemeController.php:112` | `ALTER TABLE incentive_schemes MODIFY <col> <type> NULL` | same |
| `CodeOfConductController.php:65` | `ALTER TABLE code_of_conduct_ack MODIFY employee_id BIGINT UNSIGNED NULL` | every CoC request |

`ApprovalService.php:226` also runs `SHOW COLUMNS FROM <table> LIKE 'status'` on **every**
approval request. On MySQL, DDL takes a metadata lock; a concurrent long-running query on a
large `commissions` table blocks it and every approval request queues behind it. All six are
wrapped in `try { } catch (\Throwable $e) { }` with an empty or log-only body, so a failure is
invisible to the operator and the original insert error is what the user sees.

The application also requires `ALTER` privilege on the production database **at runtime** —
the web user cannot be granted DML-only.

### 6.6 Enums and the values code writes *(question 6)*

#### 6.6.1 Complete enum inventory (35 declared `enum()` columns)

| Table.column | Declared values | Source | Still an ENUM? |
|---|---|---|---|
| `plans.billing_cycle` | `monthly, quarterly, annual` | `2026_01_01_000001:18` | yes |
| `tenants.status` | `trial, active, suspended, churned` | `:30` | yes |
| `tenants.deployment` | `saas, onprem` | `:34` | yes |
| `subscriptions.status` | `trialing, active, suspended, cancelled` | `:49` | yes |
| `invoices.status` | `draft, due, paid, overdue, void` | `:62` | yes |
| `payments.status` | `success, failed, refunded, pending` | `:78` | yes |
| `payment_gateways.mode` | `test, live` | `:86` | yes |
| `employees.type` | `office, field` | `2026_01_01_000100:126` | yes |
| `employees.salary_type` | `only_salary, salary_commission, only_commission` | `:130` | yes |
| `employees.esi_applicable` | `auto, yes, no` | `:134` | yes |
| `employees.dra_status` | `verified, pending` | `:138` | **widened → VARCHAR(20)** `2026_08_27_000100:47` |
| `employees.pcc_status` | `submitted, pending, overdue` | `:140` | **widened → VARCHAR(20)** `:47` |
| `employees.docs_status` | `approved, pending, rejected` | `:143` | yes |
| `employees.geo_start` | `home, office` | `:147` | yes |
| `employees.geo_outside` | `strict, 1km, 2km` | `:149` | yes |
| `employees.status` | `active, notice, exited` | `:154` | **widened → VARCHAR(20)** `2026_07_15_000000:24` |
| `devices.direction` | `in, out` | `2026_01_01_000200:20` | yes |
| `devices.status` | `active, error, offline` | `:25` | yes |
| `geofence_rules.start` | `home, office` | `:33` | yes |
| `geofence_rules.outside` | `strict, 1km, 2km` | `:37` | yes |
| `attendance.source` | `device, geo_app, manual` | `:49` | yes (dead table) |
| `attendance.status` | `full, half, absent, compoff` | `:52` | yes (dead table) |
| `leaves.status` | `pending, approved, rejected` | `:95` | yes |
| `salary_components.ctype` | `earning, deduction` | `2026_01_01_000300:30` | yes |
| `salary_components.calc_type` | `fixed, percent, formula` | `:31` | yes |
| `payroll_runs.status` | `draft, locked, approved, paid` | `:45` | **widened → VARCHAR(30)** `2026_06_24_000100:25` |
| `commissions.status` | `provisional, confirmed, reversed` | `:80` | **widened at runtime** `ApprovalService.php:229` |
| `deductions.type` | `late, pcc, advance_emi, escalation, lop, other` | `:147` | **yes** |
| `deductions.status` | `pending, applied, waived` | `:151` | **yes** |
| `exits.rehire` | `eligible, do_not_rehire` | `2026_01_01_000500:91` | yes |
| `escalations.priority` | `normal, emergency` | `:108` | **widened → VARCHAR(20)** `2026_08_07_000100:36` |
| `point_rules.category` | `positive, negative` | `2026_01_01_000600:215` | **yes** |
| `points_ledger.category` | `positive, negative` | `:227` | **yes** |
| `late_policy.addl_late` | was `enum('half','full')` NOT NULL | `2026_06_03_120000:3` | **widened → VARCHAR(255) NULL** `:24` |
| `incentive_schemes.status` | (varchar in migration) | — | widened at runtime `SchemeController.php:107` |

#### 6.6.2 Values the CODE writes that the enum does not permit

**Six enum-truncation bugs have already been fixed by widening migrations** — each written
after a live failure, each recorded in its own migration header. That is the base rate:

1. `late_policy.addl_late` — the wizard writes free-text notes into `enum('half','full') NOT NULL` → `SQLSTATE 1048` (`2026_06_03_120000:3-8`)
2. `payroll_runs.status` — the two-step approval writes `hr_approved`, `rejected`, `acknowledged` → `SQLSTATE[01000] Data truncated` (`2026_06_24_000100:8-14`)
3. `employees.status` — the Active/Inactive toggle writes `inactive` → `Data truncated at row 1` (`2026_07_15_000000:8-12`)
4. `escalations.priority` — the UI posts `low/medium/high/urgent` into `enum('normal','emergency')` → `1265 Data truncated` (`2026_08_07_000100:3-6`)
5. `employees.dra_status` / `pcc_status` — the import template offers Pending/Submitted/Verified for both → `Data truncated at row 1` (`2026_08_27_000100:14-20`)
6. `commissions.status` — the approval workflow writes `pending`/`approved` into `enum('provisional','confirmed','reversed')` → `Data truncated`, fixed by **runtime** `ALTER` (`ApprovalService.php:219-231`)

**The following are still live and unfixed:**

| # | Column | Enum permits | Code can write | Evidence | Consequence |
|---|---|---|---|---|---|
| E1 | `deductions.type` | `late, pcc, advance_emi, escalation, lop, other` | **any free text** | `MasterController.php:146` declares `'type' => 'string'`; `save()` (`:942-961`) sends the raw input; blanks become NULL but non-blanks go through verbatim | On strict MySQL (`config/database.php:52` `'strict' => true`) any other value throws; the user sees only *"Could not save — one of the values is not allowed for its field"* (`MasterController.php:831-833`) |
| E2 | `deductions.status` | `pending, applied, waived` | **any free text** | same, `:147` | same |
| E3 | `point_rules.category` | `positive, negative` | **any free text** | `MasterController.php:264` `'category' => 'string'` | same |
| E4 | `points_ledger.category` | `positive, negative` | **any free text** | `MasterController.php:257` | same |
| E5 | `salary_components.ctype` | `earning, deduction` | **any free text** | `MasterController.php:245` `'ctype' => 'string'` | Salary-component saves fail |
| E6 | `devices.status` | `active, error, offline` | **any free text** | `MasterController.php:86` `'status' => 'string'`; the master screens' status dropdowns elsewhere offer `Active`/`Inactive` (capitalised) | Device master save fails on `Active` (capital A) and on `Inactive` (not in the enum at all) |
| E7 | `devices.direction` | `in, out` | **any free text** | `MasterController.php:86` `'direction' => 'string'` | same |
| E8 | `employees.geo_outside` | `strict, 1km, 2km` | The Employee form posts **`Strict` / `+1 km` / `+2 km`** | `resources/prototype/app.html`: `opts:['Strict','+1 km','+2 km']`; `AppDataController.php:247` accepts only `in_array($goV, ['strict','1km','2km'])` after `strtolower` | **`+1 km` and `+2 km` silently become NULL.** The per-employee geofence relaxation cannot be saved at all. The *master* Geofence screen posts the correct tokens (`AppController.php:10803`) — two screens, two vocabularies, one column |
| E9 | `employees.pcc_status` | now VARCHAR; canonical set is `pending, submitted, verified` | The Employee form still offers **`Overdue`** | `resources/prototype/app.html`: `opts:['Submitted','Pending','Overdue']`; `2026_08_27_000100:26-29` explicitly **retired `overdue` as a stored value** and rewrote existing rows to `pending`; `AppDataController::complianceStatus()` (`:145-150`) maps anything outside the canonical three to **NULL** | Choosing "Overdue" in the UI silently blanks the PCC status |
| E10 | `employees.dra_status` | `pending, submitted, verified` | The Employee form offers **`Verified` / `Pending`** only | same `opts:['Verified','Pending']` | "Submitted" — which the import template accepts — is unreachable from the form |
| E11 | `employees.type` | `office, field` | `EmployeeFieldRules` offers `Office`/`Field`; the prototype form offers `Office`/`Field / FOS` | `EmployeeFieldRules.php:146`; `app.html` `opts:['Office','Field / FOS']` | Safe **only** because `AppDataController.php:98` does `stripos($e['type'],'field') !== false ? 'field' : 'office'` — a substring match. Any other capture path writes the label verbatim |
| E12 | `deductions`, `point_rules`, `points_ledger`, `salary_components`, `devices` are **not** in `ApprovalService::modules()` | — | — | `ApprovalService.php:29-42` | so the automatic ENUM-widening never reaches them. They are the residue |

**The pattern.** Every enum in this schema is a latent production incident, because the write
paths (master screens, import templates, the SPA) evolve independently of the column
definitions and nothing checks them against each other. The six already-fixed cases and the
twelve still-live ones are the same bug repeated.

### 6.7 Non-deterministic column types *(question 9 detail)*

`AppDataController::ensureEmployeeColumns()` (`:46-75`) adds **every** missing employees
column as `$t->string($c)->nullable()` — `VARCHAR(255)`. Its list (`:53-65`) includes
`dra_expiry`, `pcc_expiry`, `pcc_deadline`. The migrations declare the same three as
`date()` (`2026_01_01_000100:139,141,142`; `2026_08_27_000100:71-81`;
`2026_08_28_000100:71-72`).

Both sides are guarded by `hasColumn`, so **whichever runs first decides the column's type
permanently.** On a fresh install migrations win (DATE). On an upgraded install where a user
loaded the Employee Directory before the deploy completed, `ensureEmployeeColumns()` wins and
the column is `VARCHAR(255)` **forever** — the migration's guard then skips it silently.

Identically: `2026_06_24_000000:110-124` adds `dob` and `doj` as
`$t->string($c)->nullable()` when missing, though `2026_01_01_000100:113,128` declares them
`date()`.

**Consequence.** On the VARCHAR installs, `whereDate()`, `BETWEEN`, `ORDER BY expiry`,
`DATEDIFF` and every compliance-alert calculation operate on lexicographic string comparison.
`'01/09/2026'` sorts before `'2026-01-09'`; a DD/MM/YYYY value written by the import
(`normDateWide()` at `AppDataController.php:161-175` normalises to `Y-m-d`, but only on the
paths that call it) compares wrongly. Nothing errors — the PCC/DRA expiry alerts simply
report the wrong people.

### 6.8 Migration idempotency and reversibility

**Safe to re-run** (guarded): the 8 `mk()`-based creates in `2026_01_01_000100` and
`2026_01_01_000600`, every `SchemaHelper::ensureTable` migration, all six enum-widening
migrations, both SBB repair migrations, `2026_08_28_000100`, `2026_08_28_000200`.

**NOT safe to re-run** (bare `Schema::create`, will throw `1050` on a second attempt):
`0001_01_01_000000`, `0001_01_01_000001`, `0001_01_01_000002`, `2026_01_01_000001`,
`2026_01_01_000200`, `2026_01_01_000300`, `2026_01_01_000400`, `2026_01_01_000500`,
`2026_01_01_000700`, `2026_01_01_000900`, `2026_05_30_025615`, `2026_05_30_025626`,
`2026_05_30_025638`. Note that `2026_01_01_000200` (attendance/leave) and `000300` (payroll)
— the two biggest — are **unguarded**, while `000100` and `000600` were retrofitted with
`mk()` after a client install died. The lesson was applied to two files out of thirteen.

**`down()` is deliberately non-reversible** on 14 migrations (each says so in a comment):
`2026_06_02_120000`, `2026_06_03_120000`, `2026_06_24_000000`, `2026_06_24_000100`,
`2026_06_25_000000`, `2026_07_07_000000`, `2026_07_15_000000`, `2026_07_15_000100`,
`2026_07_15_000200`, `2026_07_22_000000`, `2026_07_22_000100`, `2026_07_22_000200`,
`2026_08_07_000100`, `2026_08_18_000100`, `2026_08_27_000100`, `2026_08_28_000200`.
`migrate:rollback` therefore leaves a partially-migrated schema.

**Destructive operations inside `up()`:**
- `2026_08_16_000200:125-155` **deletes rows** from `attendance_logs` (calls
  `attendance:dedupe`, falling back to an inline delete of every duplicate) before creating a
  unique index. Deleted punches are gone; nothing snapshots them first.
- `2026_08_17_000100:213-233` does the same again.
- `2026_08_27_000100:63` rewrites every `pcc_status='overdue'` row to `'pending'`.
- `2026_08_27_000100:54` rewrites every `dra_status`/`pcc_status` to lowercase.
- `2026_08_28_000100:88-113` back-fills `national_id` from `aadhaar` and `employment_stage`
  from `employment_type`.
- `2026_06_24_000100:33` rewrites blank `payroll_runs.status` to `'draft'`.
- `2026_07_15_000000:32` rewrites blank `employees.status` to `'active'`.
- `2026_08_18_000100:78-81` back-fills `attendance_pending.device_code` from
  `device_user_id`.

Every one of these is wrapped in `try { } catch { }` — a data migration that half-completes
leaves no trace.

### 6.9 Concurrency: uniqueness enforced by SELECT-then-INSERT

| Invariant | Enforced by | Race |
|---|---|---|
| One payroll run per company+month | `PayrollGenController::existingRun()` `:2279-2289` then insert `:2360-2368` | Two simultaneous "Generate" clicks create two runs and two payslip sets. `payroll_runs` has **no unique index** on `(tenant_id, company_id, cycle_label)`; `payslips` has none on `(employee_id, month)` |
| Unique PAN / mobile / email / UAN / ESIC / bank account / device ID | `EmployeeFieldRules::duplicateErrors()` `:576-611` — a `SELECT` per column, then the caller inserts | Two concurrent saves both pass. Migration `2026_08_28_000100:121-124` states the indexes are **deliberately not UNIQUE** |
| Employee code | `unique(tenant_id, emp_code)` — **the only one with a real constraint** | safe |
| Temp employee code `TMP-YYYY-NNNNN` | `SelfOnboardingController.php:97-99` — `MAX(temp_emp_code) + 1` | Two concurrent invites get the same code; `temp_emp_code` has an index but **no unique** |

### 6.10 Other technical debt

- **`->when($tid, …)` semantics.** A falsy `tenant_id` means "no filter". There is no
  assertion anywhere that a non-super-admin has a non-null `tenant_id`. Any bug that nulls it
  silently promotes a tenant user to cross-tenant read across 1,413 query sites.
- **Two answers to "what does NULL tenant mean".** `->when($tid,…)` = all tenants;
  `$request->user()->tenant_id ?? DB::table('tenants')->value('id')`
  (`AppDataController.php:2512` and 5 siblings) = the lowest-id tenant. Both idioms live in
  the same controller.
- **Two sentinels for platform scope.** `tenant_id = 0` (`LandingController.php:80`,
  `DummyDataSeeder.php:259`, `ConfigController.php:43,52`, `SettingsController.php:94`) vs
  `tenant_id = NULL` (the migrations). A query written against one finds nothing written by
  the other.
- **`config/database.php:13`** defaults to **SQLite**, not MySQL. Production is MySQL
  (`sbb-schema-status.txt`). A deploy with a missing `DB_CONNECTION` silently creates an empty
  SQLite file and every `hasTable` guard obligingly rebuilds the whole schema in it.
- **Actor identity stored as a name string** across `decided_by`, `approver_name`,
  `created_by`, `by`, `entered_by`, `uploaded_by`, `archived_by`, `rated_by`, `cleared_by`,
  `imported_by`, `granted_by`, `locked_by` — unjoinable, non-repudiable audit.
- **`sbb-schema-status.txt` shows 798 of 1,254 punch rows (64%) are `source='demo'`** —
  fabricated data sitting in the same table as real attendance, distinguished only by a string.

---

## 7. Security findings

| ID | Finding | Evidence | Exploitability | Business impact | Severity |
|---|---|---|---|---|---|
| SEC-DB-01 | **Zero referential integrity.** Not one foreign key on any business table. The only `->foreign()` calls are the 4 in the vendor Spatie migration (`2026_05_30_025615:57,81,101,106`). `sessions.user_id` uses `foreignId()` **without** `->constrained()` (`0001_01_01_000000:32`) so it is a plain indexed bigint | grep `->foreign(`/`constrained(` across `database/` + `app/` returns 5 hits, all vendor | Not an attack — a data-loss vector | Deleting a company, employee, run or tenant orphans every child row. `DummyDataSeeder.php:88-95` documents the consequence and hand-deletes 17 tables to work around it. A payroll for a deleted employee is unexplainable | **HIGH** |
| SEC-DB-02 | **The web user needs `ALTER` in production.** Six raw `ALTER TABLE` and dozens of `Schema::table()` calls run on request paths (§6.5) | `MasterController.php:528,547`; `ApprovalService.php:229`; `SchemeController.php:107,112`; `CodeOfConductController.php:65` | Any RCE or SQLi becomes schema-destructive; a compromised app account can drop columns | Cannot apply least privilege to the DB account | **HIGH** |
| SEC-TEN-01 | **The declared tenant scope never applies.** `CompanyScope` filters on a column `users` does not have | `app/Models/Scopes/CompanyScope.php:136`; `2026_01_01_000002:13-17` | None today (models unreachable) — **CRITICAL if the models are revived** | The codebase advertises automatic isolation that does not exist | HIGH (design) / INFORMATIONAL (as shipped) |
| SEC-TEN-02 | **Three tables holding employee PII have no tenant column at all**: `employee_references` (referee names, PAN, Aadhaar, mobile), `self_onboarding_otps` (OTP hashes), `self_onboarding_docs` (uploaded ID/address/bank documents). Isolation depends entirely on remembering to join the parent | `2026_01_01_000100:168-182`; `2026_07_22_000001:54-74` | Any query that forgets the join returns every tenant's rows. `DummyDataSeeder.php:81-84` has to resolve employee ids first just to delete one tenant's referees | Cross-tenant PII exposure | **HIGH** |
| SEC-TEN-03 | **`training_subjects` (migration variant) has no `tenant_id`** — only `program_id` | `2026_01_01_000600:59-67` | Course content is shared across tenants | Cross-tenant content leak | MEDIUM |
| SEC-TEN-04 | **`attendance_ratings` unique key omits `tenant_id`** | `AttendanceReportController.php:57` | Deterministic cross-tenant key collision on shared employee codes | Tenant A's rating blocks/serves Tenant B | MEDIUM |
| SEC-TEN-05 | **`users.email` is globally unique** — one identity cannot exist in two tenants; `user_password_resets` is keyed by email with no tenant | `0001_01_01_000000:17`; `AuthController.php:320-327` | A reset token issued for an email is valid regardless of tenant | Account model limitation + shared token space | MEDIUM |
| SEC-SEED-01 | **`OnPremWorkspaceSeeder` attaches a known-password admin to an arbitrary tenant.** It renames any `subdomain='demo'` tenant and ensures an `admin@smartprs.local` account exists, resolving the tenant as `DB::table('tenants')->orderBy('id')->value('id')` | `database/seeders/OnPremWorkspaceSeeder.php:42-57` and the admin block that follows | Running the documented one-liner on a multi-tenant box | Hands a full-access account to whichever tenant has the lowest id | **HIGH** |
| SEC-SEED-02 | **`DummyDataSeeder` hard-deletes a tenant's employees, payroll runs, payslips, attendance logs, commissions, loans, advances, expenses, clawbacks, increments, exits and bonuses** — targeting whatever tenant currently has `subdomain='demo'` | `DummyDataSeeder.php:80-95`; tenant resolved by `DemoCompanySeeder.php:24` | Requires `SMARTPRS_DEMO_DATA=true` and non-production (`DatabaseSeeder.php:19-20`) — but "non-production" is an `APP_ENV` string | Irrecoverable destruction of a real tenant's payroll history if a client tenant ever carries `subdomain='demo'` — which is exactly the state `OnPremWorkspaceSeeder` exists to repair | **HIGH** |
| SEC-DB-03 | **Secrets stored in the database**, encrypted only at the app layer with `APP_KEY`: `devices.api_key`, `biometric_configs.password`, `wa_settings.api_key`, `sms_settings.api_key`, `payment_gateways.secret`, `smartept_webhook_endpoints.secret` (must be recoverable in clear to verify HMAC), `licences.key_enc` | `2026_01_01_000200:22`; `2026_06_25_000000:41`; `2026_01_01_000600:141,154`; `2026_01_01_000001:88`; `2026_08_26_000100:35`; `LicenseService.php:64` | A DB dump plus a leaked `APP_KEY` yields every credential | Full compromise of biometric, messaging and payment integrations | MEDIUM |
| SEC-DB-04 | **Non-repudiable audit is not achieved.** `activity_logs` has a `prev_hash`/`row_hash` chain (`Audit.php:25-46`) but the columns are **added at runtime**, the chain is never verified on read, `Audit::record()` is fail-soft (`:68+`), the table has no append-only enforcement, and every approval actor is stored as a **name string** rather than a user id | `Audit.php`; `ApprovalService.php:238-259` | An operator with DB access can rewrite history; a renamed user makes existing history ambiguous | Evidentiary value of the audit trail is weak | MEDIUM |
| SEC-DB-05 | **A security guard that fails open.** `EffectiveDated::guardFinalisedPeriod()` returns `null` (= allowed) on any exception | `app/Services/EffectiveDated.php:115-117` | Any error inside `PayrollLock::isPeriodFinalised` permits backdating into a finalised payroll month | Retro-changing closed payroll | MEDIUM |
| SEC-DB-06 | **Migration `up()` deletes production rows.** `2026_08_16_000200:125-155` and `2026_08_17_000100:213-233` delete duplicate `attendance_logs` rows before creating a unique index, with no snapshot | those files | Runs automatically on `php artisan migrate` | Attendance evidence destroyed during a deploy | MEDIUM |
| SEC-DB-07 | **`config/database.php:13` defaults to SQLite.** A deploy with a missing `DB_CONNECTION` starts against an empty SQLite file; every `hasTable` guard then rebuilds ~150 tables in it and the app appears to work with zero data | `config/database.php:13` | Misconfiguration | Silent data-loss illusion | LOW |
| SEC-DB-08 | **Errors are swallowed.** `MasterController.php:831-833` replaces `Data truncated` / `Incorrect value` with a generic message; every runtime `ALTER` is in an empty `catch`; `SchemaHelper` logs a warning and returns | throughout | — | Schema failures are invisible to operators; the only diagnosis is the log | LOW |

---

## 8. Documentation-vs-source discrepancies

| # | Doc claim | Source | Verdict |
|---|---|---|---|
| 1 | `DATABASE_DICTIONARY.md:0` — "49 migrations define ~100 tables; **~60** tables created only at runtime; **~130** distinct" | Exact counts: **102** migration-defined (96 literal + 6 config-named), **49** runtime-only, **52** in both, **152** distinct including `migrations` | Runtime-only is **overstated** (49, not ~60); total is **understated** (152, not ~130). Corrected in §2.1 |
| 2 | `DATABASE_DICTIONARY.md:2131` — "`tenant_id` on ~95 tables, `company_id` on ~45" | Machine count over every create block: `tenant_id` on **119**, `company_id` on **42** | tenant_id understated |
| 3 | `DATABASE_DICTIONARY.md:2199` — "employees `deleted_at`, **39 sites**" | `grep -c "whereNull('deleted_at')"` scoped to lines mentioning `employees` = **35**; 167 sites in total across all tables | Minor overstatement |
| 4 | `DATABASE_DICTIONARY.md:3.1` — "grep `foreign(` returns **five hits**" | 4 `->foreign()` calls in the Spatie migration (`:57,81,101,106`) + 1 `foreignId()` at `0001_01_01_000000:32`. The table that follows correctly lists 4 FKs | Cosmetic; the substantive claim (zero business FKs) is **confirmed** |
| 5 | `DATABASE_DICTIONARY.md:1.7` — `pt_slabs` listed as a live statutory table | The **table** is dead: all 7 references to `'pt_slabs'` are JSON keys inside `statutory_settings`/`statutory_configs` (`StatutoryConfigController.php:64,93,287`; `AppDataController.php:1986`) | Doc misses this. `pf_esi_settings` and `tds_rules` are likewise 0-reference |
| 6 | `AuthController.php:312-313` (code comment) — *"the project has no `password_reset_tokens` migration"* | It does: `0001_01_01_000000_create_users_table.php:24-28` | **The comment is false.** The app rolled a second, parallel reset table on a false premise |
| 7 | `2026_01_01_000002_add_company_id_to_users_table.php` (filename) | Adds `tenant_id`, `employee_id`, `status` — **no `company_id`** (`:13-17`) | The filename is a lie about its own content; it is the direct cause of SEC-TEN-01 |
| 8 | `2026_06_24_000000_ensure_runtime_feature_tables.php:22-23` — *"Schemas mirror the runtime definitions exactly"* | `attendance_logs` mirrors `AttendanceReportController.php:33-45` but **not** `DeviceController.php:215-226`, which differs in nullability, `direction` width, `source` default and the composite index (§6.1.1) | Overstated |
| 9 | `2026_08_28_000200_biometric_device_mode.php:33` — *"created with the column by `BiometricConfigController::ensureTable()`"* | That method's `Schema::create` (`:47-63`) does **not** include `device_mode`; it is added by the additive `$add` list at `:69` | Imprecise but harmless — the column does get added |
| 10 | `SettingsController.php:61` — `'data_retention_months' => 84, // 7 years` | Grep across `app/`, `resources/`, `routes/`, `config/`: **one hit, the declaration**. Nothing reads it | The setting is decorative; the retention policy it implies does not exist |
| 11 | `COMPLETE…md:53684` — *"SmartPRS has NO retention policy of any kind"* | Confirmed. `routes/console.php` schedules 12 commands (`:143-262`); none prunes business data. `backup:clean` (`:156`) prunes **backup archive files** per `config/backup.php`, not live rows | **Confirmed** |
| 12 | `DATABASE_DICTIONARY.md` §8 lists `employees.geo_outside` as `strict/1km/2km` | Correct for the column, but the doc does not record that the Employee form posts `+1 km`/`+2 km`, which `AppDataController.php:247` converts to **NULL** | **Missed defect** (§6.6.2 E8) |
| 13 | `DATABASE_DICTIONARY.md` §8 lists `pcc_status` values as `pending/submitted/verified` with `overdue` retired | Correct, but the doc does not record that the Employee form **still offers `Overdue`** (`resources/prototype/app.html`), which `complianceStatus()` maps to NULL | **Missed defect** (§6.6.2 E9) |
| 14 | `DATABASE_DICTIONARY.md` §9.2 "three definitions problem" | Confirmed and extended: the doc misses `salary_components.calc_value` (VARCHAR vs DECIMAL — §6.1.10), `messages_log.sent_at` (timestamp vs date), and the non-deterministic DATE/VARCHAR typing of `dra_expiry`/`pcc_expiry`/`pcc_deadline`/`dob`/`doj` (§6.7) | Extended |
| 15 | `sbb-schema-status.txt` (22 Aug 2026) records `direction varchar(8)`, both unique indexes present, 0 pending punches | Matches `2026_08_16_000200` — the SBB migrations **did** apply cleanly on the dev DB. It also shows **798 of 1,254 punches are `source='demo'`** | Useful ground truth; the demo-data contamination is not mentioned in any doc |

---

## 9. Direct answers to the assignment questions

### Q1 — Definitive table inventory
**152 distinct tables.** 102 defined by migrations (96 literal + 6 config-named), **49 created
only at runtime**, 52 defined in both places, plus Laravel's `migrations` ledger. The full
runtime-only list with `file:line` is §2.1.1. Of the migration-defined tables, **11 are dead**
(§2.1.2).

### Q2 — Schema drift
Every divergence is enumerated in §6.1 with its consequence. Summary of the material ones:

| Table | Divergence | Consequence |
|---|---|---|
| `attendance_pending` | **two disjoint feature schemas, same name** | one of the two features is always broken |
| `attendance_logs` | nullability, `direction` width, `source` default, **missing composite index** | NULL punches accepted; payroll full-scans |
| `settings` | `json` vs `longText`; **unique key present vs absent** | invalid-JSON errors on one install; duplicate settings rows on the other |
| `incentive_schemes` | disjoint column sets; `name NOT NULL` vs `title` | scheme saves fail on migrated installs until a runtime `ALTER` repairs them |
| `offroll_agents` | `company_id NOT NULL` vs absent; `DECIMAL(12,2)` vs `(14,2)` | candidate→agent conversion fails on migrated installs |
| `documents` | `tenant_id NOT NULL` vs nullable; `status` default present vs absent | NULL-tenant document rows invisible to their owner |
| `code_of_conduct_ack` | `employee_id` present vs absent; `acknowledged` default **false vs true** | a compliance record that defaults to "acknowledged" |
| `training_subjects` | `program_id` bigint vs `program` string; **`tenant_id` absent in the migration** | the link column differs in name *and* type; cross-tenant content |
| `training_programs.status` | `'active'` vs `'Active'` | case-sensitive filters miss seeded rows |
| `faqs.question` | `VARCHAR(255) NOT NULL` vs `VARCHAR(500) NULL` | `1406 Data too long` on one install |
| `letters.status` | `'draft'` vs `'active'`; `company_id NOT NULL` vs absent | — |
| `test_attempts.score` | `DECIMAL(5,2)` vs `DECIMAL(8,2)` | — |
| `salary_components.calc_value` | **VARCHAR (migration) vs DECIMAL(12,2) (MasterController)** | a formula string becomes a number — **payroll correctness** |
| `messages_log.sent_at` | `timestamp` vs `date` | broadcast time lost |
| `employees.dra_expiry / pcc_expiry / pcc_deadline / dob / doj` | **DATE (migration) vs VARCHAR(255) (runtime), decided by execution order** | date arithmetic becomes string comparison; compliance alerts fire on the wrong people |

### Q3 — Can production be reproduced from migrations alone?
**NO.** Proof, in ascending order of finality:

1. **49 tables have no migration at all** (§2.1.1). `php artisan migrate:fresh` on an empty
   database produces 102 tables. The live database has ~152.
2. Among the missing 49 are **`statutory_settings`** — the entire PF/ESI/PT/TDS rate blob
   (`SettingsController.php:82`) — **`pay_cycles`** (payroll period), **`roster`** and
   **`overtime`** (feed attendance and payroll), **`salary_payments`** and
   **`loan_recoveries`** (money ledgers), **`min_wages`** (statutory), **`dra_certs`** and
   **`posh_complaints`** (regulated evidence), and **`user_password_resets`** (authentication
   cannot function without it).
3. Migration `2026_07_26_000400_add_payroll_period_columns.php:25-28` calls
   `SchemaHelper::ensureColumns('pay_cycles', …)`, which returns immediately when the table
   is absent (`SchemaHelper.php:66-68`). So even the migrations that *reference* the
   runtime-only tables silently do nothing.
4. `2026_08_28_000200:32-34` returns early with the comment *"created with the column by
   `BiometricConfigController::ensureTable()`"* — a migration that explicitly delegates to
   runtime code.
5. `2026_08_16_000200:41-48` throws `RuntimeException('attendance_logs is missing…')` — a
   migration that hard-depends on a table another migration recreated from runtime code.
6. Even for the 52 tables defined in both places, the migration produces a **different**
   schema from the runtime path on 15 of them (§6.1), so `migrate:fresh` does not reproduce a
   given production database even for the tables it does create.

The only faithful reproduction of a production schema is a `mysqldump --no-data` of that
specific database.

### Q4 — Foreign keys
**Business-table foreign keys: ZERO.** Total foreign keys in the schema: **4**, all in the
vendor Spatie permission migration (`2026_05_30_025615:57,81,101,106`), all
`ON DELETE CASCADE`, all between `permissions`/`roles` and their pivots.
`sessions.user_id` uses `foreignId()` without `->constrained()` (`0001_01_01_000000:32`) —
a plain indexed bigint, not a constraint.

Relationships with **no** foreign key: **all of them** — approximately 180 `*_id` columns.
The orphan risks that matter:

| Risk | Columns | What breaks |
|---|---|---|
| **Payroll → employee** | `payslips.employee_id`, `payslips.run_id`, `payroll_runs.company_id`, `loan_recoveries.loan_id/run_id/employee_id`, `salary_payments.employee_id`, `commission_payments.commission_id/employee_id`, `commissions.employee_id/scheme_id`, `clawbacks.recovered_run_id`, `overtime.paid_run_id` | Deleting an employee (hard delete via any runtime path) leaves payslips that name nobody. `PayrollGenController.php:2389-2390` deletes a run and its payslips but **not** `loan_recoveries` rows that reference the run except by the explicit best-effort block at `:2448`. A failure in that `try/catch` leaves `loan_recoveries` pointing at a deleted run — and the loan installment counter permanently wrong |
| **Payroll → configuration** | `payslips.earnings`/`deductions` are **JSON snapshots**, but the component definitions they were computed from live in `salary_components`, which is **hard-deleted** by `MasterController.php:1205` | Deleting a salary component destroys the ability to re-explain a historical payslip |
| **Attendance → employee** | `attendance_logs.emp_code` is a **string join to `employees.emp_code`**, not an id (`PayrollGenController::presentDays()`) | Renaming or re-issuing an employee code silently reassigns that person's entire attendance history. No constraint prevents it, and `unique(tenant_id, emp_code)` allows a code to be freed by soft-deleting an employee and then reissued |
| **Employee → org** | `employees.company_id/department_id/designation_id/branch_id/team_id/schedule_id/reporting_manager_id` | Deleting a department leaves employees pointing at a missing row; the self-referencing `reporting_manager_id` can form a cycle |
| **Documents → employee** | `documents.employee_id`, `employee_references.employee_id` (**no tenant column**), `self_onboarding_docs.self_onboarding_id` | `DocumentController::destroyForEmployee` (`:308-328`) deletes rows **and the files**; nothing prevents an orphan document row when an employee is hard-deleted by any other path |
| **Commission ledger** | `commission_payments.commission_id`, `commission_logs.commission_id` | `RequestController.php:1246-1259` exists **solely** to sweep orphans — an admin endpoint whose entire purpose is to clean up what a foreign key would have prevented |
| **Documented in the seeder** | `DummyDataSeeder.php:88-95` comment: *"employees get NEW ids on reseed — leaving these behind creates '#115' orphans"* | The team already knows; the workaround is a hand-maintained list of 17 tables |

### Q5 — Indexes and missing unique constraints

**High-traffic queries with no supporting index:**

| Query pattern | Sites | Index? |
|---|---|---|
| `whereNull('deleted_at')` on `employees` | **35** (167 across all tables) | **none** — `employees.deleted_at` is unindexed |
| `whereNull('deleted_at')` on `companies`, `tenants`, `departments`, `branches`, `teams`, `designations`, `self_onboarding` | ~40 | **none on any of the 8 soft-delete tables** |
| `payslips WHERE employee_id = ? AND month = ?` | `RequestController.php:1019`, `AppDataController.php:1564,2029`, `EssController.php:113` | `employee_id` only — `month` unindexed |
| `payslips ORDER BY month DESC` | `DashboardController.php:145` | unindexed sort |
| `payslips WHERE line_status = ?` (approval inbox) | `SalaryApprovalController.php` | **none** — the column is added at runtime (`:80-95`) with no index |
| `payroll_runs WHERE company_id AND cycle_label` (the idempotency check on every generate) | `PayrollGenController.php:2283-2287` | `company_id` only — `cycle_label` unindexed |
| `commissions WHERE cycle_month / payout_date / locked_at / status` | payroll + approvals | **none** — all four added at runtime (`ApprovalService.php:105-115`) with no index |
| `leaves/expenses/advances/loans/increments/transfers WHERE status = 'pending'` (approval inboxes) | every inbox | **none on `status` on any of them** |
| `attendance_logs WHERE tenant_id AND emp_code AND log_date BETWEEN` (per employee per run) | `PayrollGenController::presentDays()` | `(emp_code, log_date)` **only on installs where the migration or `AttendanceReportController` created the table** — absent on `DeviceController`-created installs |
| `documents WHERE expiry < ?` (compliance alerts) | `ComplianceController` | **none** |
| `recruitment WHERE stage = ?` (pipeline board) | `RecruitmentController` | **none** |
| `loan_recoveries WHERE run_id = ?` (regeneration reversal) | `PayrollGenController.php:2437,2448` | **no index on any column** of the table (`:2590-2599`) |
| `attendance_pending GROUP BY batch` | `AttendanceBulkController` | `batch` indexed on the bulk variant only |

**Unique constraints the business needs and does not have — and the duplicates possible today:**

| Missing constraint | Duplicate possible today |
|---|---|
| `payroll_runs (tenant_id, company_id, cycle_label)` | **Two payroll runs for the same company and month.** Only a PHP `SELECT` (`PayrollGenController.php:2279-2289`) prevents it; two concurrent clicks race |
| `payslips (employee_id, month)` or `(run_id, employee_id)` | **Two payslips for the same person in the same month.** `payslips` has a unique `uuid` only |
| `employees.pan`, `.national_id`, `.uan`, `.bank_acc`, `.esic_no`, `.email`, `.mobile`, `.device_user_id` | **All eight.** Enforced only by `EmployeeFieldRules::duplicateErrors()` (`:576-611`) — a race-prone SELECT that also ignores soft-deleted and archived rows. Migration `2026_08_28_000100:121-124` states these are **deliberately plain indexes, never UNIQUE** |
| `attendance_ratings (tenant_id, emp_code, log_date)` | The existing key omits `tenant_id` → cross-tenant collision instead of a duplicate |
| `settings (tenant_id, key)` on the runtime variant | **Duplicate settings rows**; `updateOrInsert` inserts, `first()` reads an arbitrary one |
| `self_onboarding.temp_emp_code` | **Two candidates with the same `TMP-2026-00001`**; generated by `MAX()+1` (`SelfOnboardingController.php:97-99`) |
| `commissions` — any natural key | Re-submitting the same claim creates a second row |
| `leaves (employee_id, from_date, to_date)` | Overlapping / duplicate leave applications |
| `holidays (tenant_id, date)` | Duplicate holidays double-count in the LOP denominator |
| `salary_components (tenant_id, scope, code)` | Two components with the same code in one scope — the payroll engine's behaviour is then order-dependent |
| `documents (employee_id, kind)` | — |
| `training_records (employee_id, program_id)` | Duplicate assignments |
| `code_of_conduct_ack (tenant_id, employee_id)` | Duplicate acknowledgements |

### Q6 — Enums and illegal writes
Full inventory in §6.6.1 (35 enum columns, 7 already widened). **Six historical
enum-truncation bugs** are documented in migration headers; **twelve remain live**, listed as
E1–E12 in §6.6.2. The unfixed ones that matter most:

- **E8 `employees.geo_outside`** — the Employee form's `+1 km` / `+2 km` options are
  **impossible to save**; they become NULL silently.
- **E9 `employees.pcc_status`** — the form still offers `Overdue`, which the 27 Aug migration
  retired and `complianceStatus()` maps to NULL.
- **E1–E7** — `deductions.type`, `deductions.status`, `point_rules.category`,
  `points_ledger.category`, `salary_components.ctype`, `devices.status`, `devices.direction`
  are all still ENUMs written from free-text `MasterController` fields, on a database with
  `'strict' => true` (`config/database.php:52`).

### Q7 — Tenancy columns
The naming is inconsistent in **four** distinct ways. Complete map:

| Column name | Meaning | Tables | Example |
|---|---|---|---|
| **`tenant_id`** `unsignedBigInteger` | **The real tenant boundary.** `= tenants.id`. NULL on `users` = platform super admin | **119** tables | `employees.tenant_id` |
| **`company_id`** `unsignedBigInteger` | A **group company inside** a tenant. Not a tenant boundary | **42** tables | `payslips.company_id` |
| **`company_name`** `VARCHAR(255)` | A **denormalised text copy** of the company name. This is what the master screens filter and display on; `MasterController::buildRow()` (`:964-970`) resolves it back to `company_id` by name lookup | ~30 `MasterController`-managed tables, plus `offroll_agents`, `onboarding`, `performance_reviews`, `shifts` in their runtime creates | `shifts.company_name` |
| **`company`** `VARCHAR` | A **free-text customer company name** on platform tables — **not a tenant key at all** | `leads`, `signups`, `onprem_clients`, `demo_requests`, `hiring_drives`, `coupon_redemptions` | `leads.company` |

**Tables that hold tenant data with NO tenant column and SHOULD have one:**

| Table | Data | Current isolation |
|---|---|---|
| `employee_references` | Referee names, PAN, Aadhaar, mobile numbers | **only** the `employee_id` join |
| `self_onboarding_otps` | OTP hashes, attempt counters | only `self_onboarding_id` |
| `self_onboarding_docs` | Uploaded ID / address / bank documents | only `self_onboarding_id` |
| `training_subjects` (migration variant) | Course content | only `program_id`; the seeder variant *does* add `tenant_id` — so this table's tenancy depends on which creator ran |
| `employee_company` | Employee↔company assignments | `company_id` only (dead table) |
| `user_password_resets` | Reset tokens | keyed by email only — deliberate, but two tenants sharing an email share the token space |
| `onboarding_items` | has `tenant_id`, but the parent `onboarding` row is the real scope | double-scoped, inconsistently |

**Correctly tenant-free (platform-owned):** `tenants`, `plans`, `payment_gateways`, `coupons`,
`onprem_clients`, `onprem_payments`, `licences`, `licence_events`, `licence_devices`,
`releases`, `release_grants`, `client_updates`, `leads`, `demo_requests`, plus the framework
tables.

**Nullability is inconsistent for the same column.** `tenant_id` is `NOT NULL` on
`employees`, `companies`, `departments`, `payslips`, `payroll_runs`, `commissions`,
`documents`, `letters` and 30 others (all from `2026_01_01_*` migrations), but **nullable** on
every table created after June 2026 and on **every** table `MasterController::ensureCols()`
creates (`:504`). A NULL-tenant row is invisible to `->when($tid,…)` and survives every
per-tenant purge.

### Q8 — Soft delete / archive / hard delete, per table

**Soft delete (`deleted_at`) — 8 tables only:** `tenants` (`2026_01_01_000001:39`),
`companies` (`2026_01_01_000100:51`), `departments` (`:61`), `designations` (`:70`),
`branches` (`:83`), `teams` (`:103`), `employees` (`:156`), `self_onboarding`
(`2026_07_22_000001:49`). **None of the eight has an index on `deleted_at`.**

**Archive:** `employees` only — `archived_at`/`archived_by` + a full JSON snapshot in
`employee_archives.snapshot` (longText), with a 3-day grace period via
`employees.backup_due_at` (`2026_07_15_000100`, `2026_07_15_000200`,
`AppDataController.php:2507-2659`). Plus `self_onboarding.link_disabled_at` (archived, not
deleted, on approval) and `demo_requests.wiped_at`.

**Hard delete — everything else (144 tables).** Every hard `DELETE` in the codebase that
destroys business or evidentiary data:

| # | File:line | Table(s) | What is destroyed |
|---|---|---|---|
| 1 | `MasterController.php:1205` | **~49 of the 54 master tables** — every one without `deleted_at` | The single largest hard-delete surface. Includes `attendance_logs` (manual punches — **attendance evidence**), `deductions` and `payout_recon` (**payroll evidence**), `tds_returns` (**statutory filing tracker**), `bgv`, `dra_certs`, `agent_authorizations`, `posh_complaints`, `complaints`, `escalations` (**regulated/legal evidence**), `documents`, `points_ledger`, `code_of_conduct_ack`, `training_records`, `test_attempts`, and the configuration tables `salary_components`, `late_policy`, `shifts`, `holidays`, `leave_types`, `pay_cycles`, `min_wages` — **whose deletion makes past payslips unexplainable** |
| 2 | `PayrollGenController.php:2389-2390` | `payslips`, `payroll_runs` | **A complete payroll run and every payslip in it**, on regenerate. An `activity_logs` row is written first (`:2372-2383`) but the superseded figures are gone |
| 3 | `PayrollGenController.php:2405` | `commission_payments` | Disbursement-ledger debits for the regenerated run |
| 4 | `PayrollGenController.php:2448` | `loan_recoveries` | Per-run EMI recovery audit rows |
| 5 | `RequestController.php:1256-1259` | `commissions`, `commission_payments`, `commission_logs` | **Every commission claim whose employee no longer exists**, plus its full payment ledger and audit trail. Admin-triggered, no confirmation of scope beyond a count |
| 6 | `DocumentController.php:302` | `documents` + the file on disk (`:297-299`) | One employee document and its file |
| 7 | `DocumentController.php:326` | `documents` + all files (`:317-323`) | **All of one employee's documents** |
| 8 | `AppDataController.php:3021` | `employee_references` | All referee records for an employee (names, PAN, Aadhaar) |
| 9 | `AttendanceCorrectionService.php:295-301` | `attendance_logs` where `source='correction'` | Prior correction punches for that employee-day, before rewriting them |
| 10 | `PointsController.php:77-80` | `points_ledger` where `source_ref LIKE 'auto:<month>%'` | All auto-generated points for a month, on every re-run |
| 11 | `AttendanceBulkController.php:383` | `attendance_pending` (bulk variant) rows in `pending`/`error` | Un-approved uploaded attendance |
| 12 | `BiometricConfigController.php:688` | `biometric_unmapped` | Unmapped-device counters on mapping |
| 13 | `BiometricConfigController.php:352` | `biometric_configs` | A device connection config |
| 14 | `RecruitmentController.php:428-430`, `:517`, `:1264` | `recruitment`, `interviews`, `job_openings` | Candidate records with PII, interview feedback, requisitions |
| 15 | `HiringDriveController.php:263-264` | `hiring_drives`, `hiring_drive_candidates` | Drive attendance records |
| 16 | `IncrementController.php:302` | `increments` | **Salary increment history** |
| 17 | `TestController.php:192` | `test_questions` | Assessment content |
| 18 | `KbController.php:112` | `kb_topics` | KB articles |
| 19 | `CouponController.php:172` | `coupons` | Discount codes (redemption ledger orphaned) |
| 20 | `SaasController.php:485` | `plans` | A subscription plan (subscriptions orphaned) |
| 21 | `OnpremClientController.php:758-762` | `licence_events`, `licences`, `onprem_payments`, `onprem_clients` | **A client's entire licensing and payment history** |
| 22 | `WaTemplateController.php:325` | `wa_templates` | — |
| 23 | `LandingController.php:59` | `settings` (landing row) | — |
| 24 | `DemoReset.php:139`, `:146,:178` | `settings` (`app_state`), `demo_requests` | — |
| 25 | `ClearAppState.php:35` | `settings` (`app_state`) | — |
| 26 | `PurgeDemoAttendance.php:33` | `attendance_logs` where `source='demo'` | 798 of the 1,254 punches in the dev DB match this filter |
| 27 | `DedupeAttendance.php:82` | `attendance_logs` | Duplicate punches, keep-newest-by-id |
| 28 | **`2026_08_16_000200:154`** and **`2026_08_17_000100:232`** | `attendance_logs` | **Inside `migrate` `up()`** — duplicate punches deleted with no snapshot |
| 29 | `DummyDataSeeder.php:83-95` | `employee_references`, `employees`, `teams`, `branches`, `designations`, `departments`, `payroll_runs`, `payslips`, `attendance_logs`, `attendance_ratings`, `transfers`, `onboarding`, `leaves`, `commissions`, `commission_payments`, `commission_logs`, `expenses`, `advances`, `loans`, `clawbacks`, `increments`, `exits`, `bonus_encashment` | **A whole tenant's people, org and payroll history — hard-deleted, including `employees` which has `deleted_at`.** Target tenant is whichever has `subdomain='demo'` |
| 30 | `EmployeeController.php:75`, `DepartmentController.php:60`, `DeviceController.php:56`, `LoanController.php:57`, `StaffController.php:100`, `SalaryRunController.php:51` | via Eloquent | Unrouted dead code; `Employee`/`Department` would soft-delete, `Device`/`Loan`/`SalaryRun` would hard-delete |

**No table is append-only at the database level.** `commission_logs`, `probation_events`,
`activity_logs`, `licence_events`, `mail_log`, `wa_log` are append-only *by convention* only.

### Q9 — Money and date column types

**Money — is it DECIMAL everywhere it should be?** Almost, with these exceptions:

| # | Column | Type | Should be | Impact |
|---|---|---|---|---|
| M1 | `payslips.earnings`, `payslips.deductions` | **`json`** | itemised money | Every line item of every payslip is a **JSON blob**. Not queryable, not summable in SQL, no per-component reporting without parsing 100% of rows in PHP. This is the payroll system's primary money record |
| M2 | `incentive_schemes.slabs` (migration), `salary_schedules.lots` | `json` | structured | Slab boundaries and amounts are unqueryable |
| M3 | `salary_components.calc_value` | **`string`** on migrated installs, **`DECIMAL(12,2)`** on runtime installs | one or the other, consistently | A formula (`"0.4 * BASIC"`) becomes `0.40`. **Payroll correctness diverges by install** |
| M4 | `overtime.multiplier` | `string` (`MasterController.php:341`) | `DECIMAL` | An overtime multiplier (`1.5`, `2`) stored as text and cast in PHP |
| M5 | `incentive_schemes.employee_ids` | `text` (CSV) | a join table | Not indexable, not joinable, silently truncates |
| M6 | `attendance.hours`, `late_policy.full_day_hours`, `employees.geo_radius_km` | `DECIMAL(4,2)` — **max 99.99** | wider | Not a live problem for hours; `geo_radius_km` caps a geofence at 99.99 km |
| M7 | `offroll_agents.rate` | `DECIMAL(12,2)` vs `DECIMAL(14,2)` | one scale | Two installs, two ceilings |
| M8 | `payroll_runs.net_total DECIMAL(14,2)` but `payslips.net DECIMAL(12,2)` | mixed | consistent | A single payslip caps at ₹99,999,999.99 (fine); the run total caps at ₹9,999,999,999,999.99. Consistent enough, but by luck not design |
| M9 | Every `MasterController`-created money column | `DECIMAL(12,2)` (`:564`) regardless of what the column is | — | The type map has no concept of a money scale |

All primary money columns (`ctc`, `gross`, `net`, `amount`, `emi`, `outstanding`, `balance`,
`per_month`, `fnf_amount`, `dues`, `penalty`, `expected`, `received`, `variance`,
`old_ctc`, `new_ctc`, `taxable`, `cgst`, `sgst`, `igst`, `total`, `base_price`,
`per_user_price`, `mrr`, `tax`, `price`, `paid_total`) **are** `DECIMAL`. No `FLOAT` or
`DOUBLE` appears anywhere in the schema. That part is right.

**Dates — every wrong one:**

| # | Column | Declared | Should be | Evidence |
|---|---|---|---|---|
| D1 | `employees.dra_expiry`, `.pcc_expiry`, `.pcc_deadline` | **DATE or VARCHAR(255) depending on execution order** | DATE | `2026_01_01_000100:139-142` / `2026_08_27_000100:71-81` say `date()`; `AppDataController.php:53-74` says `string()`. **Non-deterministic** |
| D2 | `employees.dob`, `.doj` | **DATE or VARCHAR(255) depending on execution order** | DATE | `2026_01_01_000100:113,128` `date()` vs `2026_06_24_000000:110-124` `string()` |
| D3 | `payslips.month`, `commissions.month`, `deductions.month`, `clawbacks.month`, `bonus_encashment.month`, `payout_recon.month`, `bank_invoices.month` | `VARCHAR(7)` `'YYYY-MM'` | `DATE` (period start) or a first-class period type | Sorts lexicographically — correct for `YYYY-MM`, but no range query, no `DATE_ADD`, no index-assisted `BETWEEN` on a real date |
| D4 | `salary_payments.month` | `VARCHAR(10)` | same | inconsistent width with D3 |
| D5 | `payroll_runs.cycle_label` | `VARCHAR(255)` | — | The **idempotency key** for a payroll run is an unvalidated free-text label |
| D6 | `shifts.start_time`, `.end_time` | `VARCHAR(255)` `'HH:MM'` | `TIME` | `2026_07_07_000000:37-38`. Explicitly documented as "earlier than start = night shift" — a cross-midnight rule encoded in string comparison |
| D7 | `late_policy.shift_start`, `.shift_end` | `VARCHAR(255)` (`MasterController.php:239` `'string'`) | `TIME` | Same concept, same wrong type, second table |
| D8 | `attendance_pending.in_at`, `.out_at` (bulk variant) | `VARCHAR(8)` `'HH:MM'` | `TIME` | `AttendanceBulkController.php:47-48` |
| D9 | `hiring_drives.time_from`, `.time_to` | `VARCHAR(20)` | `TIME` | `HiringDriveController.php:42-43` |
| D10 | `attendance_corrections.orig_in/orig_out/req_in/req_out/app_in/app_out` | **`time`** — correct | — | `2026_07_26_000200:25-30`. The **only** place in the schema that uses a real `TIME` column, which proves the others are an oversight |
| D11 | `messages_log.sent_at` | `timestamp` (migration `:195`) vs `date` (`MasterController.php:323`) | `timestamp` | Time of a broadcast lost on runtime installs |
| D12 | `offroll_agents.dra`, `.pcc` | `VARCHAR` | dates or statuses? | `2026_01_01_000500:22-23` — **undetermined from source** whether these hold a status, a certificate number or an expiry date |
| D13 | `employees.probation_months` | `unsignedSmallInteger` | — | Correct, but `probation_end` is derived and stored (`2026_07_26_000100:19`), so the two can drift |

### Q10 — Migration-readiness assessment (SmartPRS 2 → SmartPRS 3)

Verdicts: **CLEAN** = migrate as-is with column renames only · **TRANSFORM** = migrate with a
defined data transformation · **AMBIGUOUS** = a Product Owner must decide what the data means
before it can move · **DO NOT MIGRATE** = leave behind.

#### CLEAN (23 tables)

| Table | Note |
|---|---|
| `tenants`, `plans`, `subscriptions`, `invoices`, `payments`, `payment_gateways` | Well-typed, tenant registry. Enums are consistent with code |
| `users` | Rename `tenant_id`; drop the never-existing `company_id` assumption |
| `companies`, `departments`, `designations`, `branches`, `banks`, `teams` | Clean masters with soft delete |
| `holidays`, `leave_types` | Small, clean |
| `attendance_corrections` | The best-designed table in the schema: real `time` columns, composite index, complete audit columns |
| `statutory_configs` | Effective-dated, scoped, indexed — the model SmartPRS 3 should generalise |
| `probation_events`, `commission_logs`, `licence_events`, `client_updates` | Append-only logs |
| `api_keys`, `smartept_webhook_endpoints` | Credentials stored correctly (hash / encrypted) |
| `employee_import_jobs`, `employee_archives` | Self-contained job/snapshot records |

#### TRANSFORM (41 tables)

| Table | Required transformation |
|---|---|
| `employees` | **The big one.** (a) Detect whether `dob`, `doj`, `dra_expiry`, `pcc_expiry`, `pcc_deadline` are DATE or VARCHAR per install and parse accordingly; (b) reconcile the `aadhaar` ↔ `national_id` and `employment_type` ↔ `employment_stage` duplicate-column pairs (`2026_08_28_000100:86-113` back-filled only *some* rows); (c) normalise `dra_status`/`pcc_status` case; (d) split the archive dimension (`archived_at`, `backup_due_at`) from the delete dimension (`deleted_at`) into an explicit lifecycle state; (e) **de-duplicate** PAN / UAN / bank account / ESIC / mobile / email, which are guaranteed to contain duplicates because the constraints were deliberately not applied |
| `attendance_logs` | Convert the `emp_code` **string** join to a real `employee_id` FK — the code must be resolved against `employees` *as it was at the punch date*, which is not recoverable if a code was ever reissued. Separate the 798-of-1254 `source='demo'` rows. Widen `direction`. Rebuild both unique indexes |
| `attendance_pending` | **Must be split into two tables** before anything else. Route rows by which column set is populated |
| `payroll_runs`, `payslips` | Convert `month VARCHAR(7)` and `cycle_label` to a real period (`period_start`/`period_end` already exist but are nullable and back-filled only where `2026_07_26_000400` ran). **Explode `earnings`/`deductions` JSON into payslip line rows** — this is the single highest-value transformation in the whole migration |
| `commissions` | Reconcile the two status vocabularies (`provisional/confirmed/reversed` and `pending/approved/rejected`) that now coexist in one VARCHAR column. Resolve `amount` = NET vs `gross_amount` |
| `commission_payments`, `salary_payments`, `loan_recoveries`, `agent_earnings` | Add indexes; convert `month` strings to periods; add FKs |
| `loans`, `advances`, `expenses`, `deductions`, `clawbacks`, `increments`, `exits`, `bonus_encashment` | Widen enums to reference data; normalise `decided_by`/`approver_name` **name strings to user ids** (may be irrecoverable where a user was renamed) |
| `salary_components` | **`calc_value` is VARCHAR on some installs and DECIMAL on others** — detect and normalise. Add effective dating (it has none today, so historical payslips cannot be re-explained) |
| `late_policy`, `shifts` | Convert `VARCHAR 'HH:MM'` to `TIME`. Reconcile `full_day_hours` `DECIMAL(4,2)` vs `(12,2)` |
| `pay_cycles`, `roster`, `overtime`, `min_wages`, `policies`, `awards`, `notices`, `dra_certs`, `posh_complaints`, `feature_flags` | **Runtime-only tables.** Their schema must be discovered from the live database, not from source, because it depends on which `MasterController` def version created them |
| `statutory_settings` | A single JSON blob per tenant → decompose into `statutory_configs`-shaped effective-dated rows |
| `settings`, `app_settings` | Two key/value stores with two platform sentinels (`0` and `NULL`) and two value types (`json` and `longText`). Merge, pick one sentinel, validate every JSON payload |
| `documents` | Reconcile three shapes; back-fill NULL `tenant_id` from `employees` |
| `letters` | Split templates (`is_template=1`) from issued letters — they are different entities sharing a table and a nullable `employee_id` |
| `incentive_schemes` | **Two disjoint shapes.** Detect which by column presence; the `name/basis/slabs` rows and the `title/rate_type/payout_method` rows are different entities |
| `offroll_agents` | Reconcile `company_id` (present/absent) and `rate` scale |
| `code_of_conduct_ack` | Reconcile `employee_id` vs `user_id`+`employee`; the `acknowledged` default flipped |
| `test_attempts`, `training_records`, `training_subjects`, `training_programs`, `tests`, `test_questions` | `training_subjects.program_id` vs `program`; `training_programs.status` case; add `tenant_id` where absent |
| `recruitment`, `job_openings`, `interviews`, `hiring_drives`, `hiring_drive_candidates`, `recruitment_campaigns`, `recruitment_messages` | `recruitment` was created with 3 columns and grew ~25 by `ensureCols()`; discover from the live DB |
| `self_onboarding`, `_otps`, `_docs` | Add `tenant_id` to the two child tables |
| `employee_references` | **Add `tenant_id`** — currently derivable only by joining `employees` |
| `escalations`, `complaints`, `bgv`, `assets`, `agent_authorizations`, `helpdesk` | Widen enums; normalise the free-text status vocabularies each master screen invented |
| `points_ledger`, `point_rules` | `category` ENUM vs free text |
| `activity_logs` | Verify the hash chain before migrating; rows written before `Audit::ensure()` added the columns have NULL hashes and cannot be verified |
| `notification_log`, `mail_log`, `wa_log`, `messages_log`, `subscription_alerts`, `late_notifications` | High-volume logs — apply a retention cut-off at migration time (there has never been one) |

#### AMBIGUOUS — PRODUCT OWNER DECISION REQUIRED (11 tables)

| Table | The question |
|---|---|
| `attendance` (singular) | Zero code references. Does it hold real historical data on any client install predating `attendance_logs`? If yes it is the only table with a proper `unique(employee_id, date)` and must be migrated; if no, drop |
| `attendance_pending` | Which feature's rows exist on each install? Rows may be a mixture after `2026_08_17_000100` added the SBB columns to a bulk-upload table |
| `pf_esi_settings`, `tds_rules`, `pt_slabs` | Superseded by `statutory_settings` JSON. Do any installs have rows here that were never migrated into the blob? **If yes, one of the two sources is the live statutory config and the other is wrong** |
| `employee_company` | The multi-company employee pivot. Never read or written — but `employees.also_works_for` ('None' / 'Multiple companies') implies the requirement exists. Is multi-company employment a real requirement for SmartPRS 3? |
| `notifications_feed` | Superseded by `notification_log`. Old rows? |
| `company_emails` | Never read. Was per-department from-address a shipped feature? |
| `offroll_agents.dra`, `.pcc` (columns) | VARCHAR with no comment — status, certificate number, or expiry date? Cannot be determined from source |
| `settings` rows with `tenant_id = 0` vs `NULL` | Which sentinel means "platform"? Both are in use |
| `password_reset_tokens` vs `user_password_resets` | Two reset-token tables; the second was created on a false premise (§8 #6) |
| `commissions.amount` vs `gross_amount` | `ApprovalService.php:101-102` says `amount` stays the **net** payable — but rows predating that change may hold a gross. Is there a discriminator? |
| `payslips.earnings/deductions` JSON | Is the JSON schema stable across all historical runs, or did the component set change shape between revisions? Determines whether the explode-to-lines transformation is mechanical or per-revision |

#### DO NOT MIGRATE (21 tables)

| Table | Reason |
|---|---|
| `cache`, `cache_locks`, `sessions`, `jobs`, `job_batches`, `failed_jobs` | Framework infrastructure, transient |
| `activity_log` (Spatie) | Written only by unreachable models; empty in practice |
| `personal_access_tokens` | Re-issue |
| `password_reset_tokens` | Never used |
| `salary_runs`, `salary_slips`, `leave_applications` | **Named only by dead models; no migration ever created them.** They do not exist |
| `demo_requests`, `leads`, `signups` | Marketing/funnel data; belongs in a CRM, and holds PII with no retention basis |
| `coupons`, `coupon_redemptions`, `renewals`, `subscription_alerts` | Platform billing; migrate only if SmartPRS 3 keeps the same commercial model |
| `onprem_clients`, `onprem_payments`, `licences`, `licence_devices`, `releases`, `release_grants` | Licensing subsystem — a separate product decision from the HR platform |
| `mobile_devices` | Re-register |
| `biometric_unmapped` | Derived counters; recompute |
| `attendance_logs WHERE source='demo'` | **798 of 1,254 rows in the dev DB.** Fabricated data. Must be excluded by an explicit filter, not by hope |
| `wa_templates`, `sms_templates` | Provider-side objects; re-register with the provider |
| `feature_flags` | Superseded by SmartPRS 3 configuration |

#### Migration blockers

1. **The source schema cannot be read from source code.** Every migration script must
   introspect `information_schema` per install, because 49 tables and ~15 column types exist
   only as a consequence of runtime execution history.
2. **No install is a reference install.** There is no canonical schema to diff against.
3. **Duplicates are guaranteed** on `employees.pan/uan/bank_acc/esic_no/mobile/email`,
   `payroll_runs (company, month)` and `payslips (employee, month)`, because the constraints
   were deliberately never applied.
4. **Orphans are guaranteed** everywhere, because there are no foreign keys and
   `RequestController.php:1246` exists solely to sweep them.
5. **The demo-data contamination has no reliable discriminator** beyond
   `attendance_logs.source='demo'` and `tenants.subdomain='demo'`.

---

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
