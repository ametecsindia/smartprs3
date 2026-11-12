# AS-IS ANALYSIS — Employee master data, field registry, field parity, CRUD, status machine, on/off-roll, import, export, self-onboarding, documents, transfers, probation, increments

> Evidence convention: `file.php:NNN` line numbers in this document were **read from
> `/home/claude/prs2/` directly** and supersede the reference documentation, whose
> `AppDataController.php` line numbers are consistently 20–45 lines high (see §8).

---

## 0. Scope and evidence base

### Reference documents read
| Document | Lines read |
|---|---|
| `COMPLETE_PROJECT_BUSINESS_AND_TECHNICAL_DOCUMENTATION.md` §05 | 2257–5210 (whole section, in 4 passes) |
| — §05.1 field registry | 2354–2528 |
| — §05.2 field-parity analysis | 2528–2800 |
| — §05.4 status/state machine | 3011–3121 |
| — §05.7 bulk import | 3302–3576 |
| — §05.8 export | 3576–3668 |
| — §05.9 self-onboarding | 3668–4094 |
| — §05.10–15 transfers/probation/increments/documents/BRs/security/debt/OQ | 4094–5210 |
| `BUSINESS_RULES_CATALOGUE.md` | BR index 405–450; BR-EMP-101…112, 301–305, 401, 602, 700–709; BR-IMP-080, 101, 700–703; renumbering map 13629–13760 |

### Source files read (all read to completion unless noted)
| File | Lines | Read |
|---|---|---|
| `app/Services/EmployeeFieldRules.php` | 613 | full |
| `app/Services/EmployeeImportService.php` | 821 | full |
| `app/Http/Controllers/SelfOnboardingController.php` | 1132 | full |
| `app/Http/Controllers/EmployeeController.php` | 110 | full |
| `app/Http/Controllers/EmployeeImportController.php` | 124 | full |
| `app/Http/Controllers/EmployeeExportController.php` | 327 | full |
| `app/Http/Controllers/OnboardingController.php` | 198 | full |
| `app/Http/Controllers/DocumentController.php` | 330 | full |
| `app/Http/Controllers/TransferController.php` | 209 | full (1–60, 100–209 verbatim; 60–100 PDF composition skimmed) |
| `app/Http/Controllers/ProbationController.php` | 126 | full |
| `app/Http/Controllers/IncrementController.php` | 438 | 300–438 verbatim; 1–300 targeted |
| `app/Services/TransferService.php` | 217 | full |
| `app/Services/ProbationService.php` | 388 | full |
| `app/Models/Employee.php` | 72 | full |
| `app/Models/Concerns/BelongsToCompany.php` | 33 | full |
| `app/Console/Commands/ApplyTransfers.php` | 30 | full |
| `app/Console/Commands/ProbationNotify.php` | 38 | full |
| `app/Http/Controllers/AppDataController.php` | 3090 | employee-relevant regions read in full: 40–250, 250–440, 591–1065, 2430–3090 |
| `app/Http/Controllers/EssController.php` | — | 45–110, 250–295 |
| `app/Http/Controllers/RecruitmentController.php` | — | 540–690 |
| `app/Http/Controllers/ComplianceController.php` | — | 20–150 |
| `app/Http/Controllers/MasterController.php` | — | 955–1070 (`buildRow`, `emp_map`) |
| `app/Http/Controllers/BiometricConfigController.php` | — | 600–640 |
| `app/Http/Controllers/LetterController.php` | — | 335–355 |
| `app/Services/SubscriptionService.php` | — | 30–150 |
| `app/Services/FeaturePermissions.php` | — | 60–95 |
| `app/Http/Controllers/AppController.php` | 1.15 MB | targeted: 120–140 (cfg), 1645–1760 (browser validation + Documents note), 1800–1960 (form build + save gate), 12335–12430 (Directory import/export buttons) |
| `routes/web.php`, `routes/api.php`, `routes/console.php` | — | employee/import/onboarding/probation/transfer routes and schedule entries |
| `resources/views/self-onboarding/portal.blade.php` | 435 | full |
| `resources/views/self-onboarding/hr-console.blade.php` | 174 | full |
| `resources/views/employees/form.blade.php`, `employees/index.blade.php` | 113 + 113 | full |
| `tests/Unit/EmployeeImportServiceTest.php`, `tests/Unit/ProbationServiceTest.php` | 34 + 41 | full |

### Migrations read (full)
`2026_01_01_000100_create_employees_table.php` (companies, departments, designations,
branches, banks, teams, **employees**, employee_company, employee_references),
`2026_07_15_000000_widen_employees_status.php`,
`2026_07_15_000100_add_employee_archive.php`,
`2026_07_15_000200_add_backup_schedule.php`,
`2026_07_22_000000_add_dra_pcc_declared.php`,
`2026_07_22_000001_create_self_onboarding_tables.php`,
`2026_07_22_000002_add_selfonboard_cols_to_employees.php`,
`2026_07_26_000100_probation_tracking.php`,
`2026_07_26_000300_create_employee_import_jobs.php`,
`2026_08_27_000100_widen_employee_dra_pcc_status.php`,
`2026_08_28_000100_employee_field_parity.php`.

---

## 1. Business capability inventory

### 1.1 One employee record per person per tenant
A person is one row in `employees`, identified by `(tenant_id, emp_code)` — the only
unique index on the table (`2026_01_01_000100_create_employees_table.php:158`). The code
can be renamed and the rename follows the person through eight `emp_code`-keyed history
tables. The employee keeps their code across a company transfer inside the group.

### 1.2 A declared field registry
`App\Services\EmployeeFieldRules::FIELDS` (`EmployeeFieldRules.php:80-233`) declares
**exactly 56 employee fields** — verified by parsing the constant: 20 Personal (`ep`),
14 Job & Company (`ej`), 9 Statutory & Salary (`es`), 8 Documents & compliance (`ed`),
5 Bank (`eb`); 22 carry `hr`=true, 26 carry a portal key `p`, 8 are duplicate-blocked,
16 carry a format validator. Plus one import-only `DEFAULT PASSWORD` column
(`:239-243`). The registry supplies the Employee form's panels, the downloadable sample
file's headers/samples/dropdowns, the export's column map, and the importer's accepted
header aliases.

### 1.3 Nine ways an employee record is written
The reference documentation names five "doors". The source has **nine distinct write
paths** (`grep "table('employees')" | grep "update(|insert(|insertGetId("`):

| # | Path | What it writes | Doc lists it? |
|---|---|---|---|
| 1 | `AppDataController::storeEmployee()` (`:2718-3089`) | all 56 registry fields + geo + schedule | yes |
| 2 | `AppDataController::importEmployees()` legacy CSV (`:591-870`) | ~25 fields | yes |
| 3 | `EmployeeImportService` wizard (`:399-430`) | all 56 | yes |
| 4 | `SelfOnboardingController::injectOne()` (`:793-906`) + bulk (`:1088-1113`) | 26 portal + 23 HR | yes |
| 5 | `RecruitmentController::createEmployeeFromCandidate()` (`:579-645`) | 12 fields, FKs only | yes |
| 6 | **`EssController::updateProfile()` (`:263-292`)** | 10 fields, self-service | **NO** |
| 7 | **`BiometricConfigController` (`:630-633`)** | `device_user_id`, steals it from the current holder | **NO** |
| 8 | **`LetterController::uploadPhoto()` (`:349`, `:475`)** | `photo_path`, creating the column at runtime | **NO** |
| 9 | Lifecycle writers: `IncrementController::applyCtc()` (`:342`), `TransferService::apply()` (`:174`), `ProbationService::confirm/extend()` (`:294`,`:329`), `backupEmployee` (`:2527`), `archiveEmployeeNow` (`:2651`), `setEmployeeStatus` (`:2705`), `bulkDeleteEmployees` (`:889`) | field-level | partly |

### 1.4 Lifecycle capabilities
Status flip (Active/Inactive), 72-hour scheduled backup → archive ("Old data") with a
full JSON snapshot, bulk soft-delete (admin only), no restore, no hard delete, no exit.

### 1.5 Bulk load and extract
Four import mechanisms with four different header vocabularies (wizard, legacy CSV,
self-onboarding bulk CSV, master CSV); one export (CSV + hand-built XLSX) that is the
sample file minus `DEFAULT PASSWORD`.

### 1.6 Self-onboarding
Token-secured public portal, 8 steps, OTP verification, selfie, document upload,
progressive JSON save; HR console for verify / request correction / set HR fields /
approve & inject.

### 1.7 Transfers, probation, increments, documents, onboarding checklists
Transfers ride the generic approval engine and apply on their effective date via a
daily 06:30 command. Probation derives an end date and fires milestone reminders daily
at 07:15. Increments email a letter on approval and are applied to the employee record
by a deliberate manual click, generating back-dated arrears. Documents are a single
tracker with a per-employee MB quota. Onboarding is a 6-item hard-coded checklist.

---

## 2. Data model

### 2.1 `employees` — provenance of every column

| Column | Type as created | Provenance |
|---|---|---|
| `id`, `uuid`(unique), `tenant_id`, `company_id`, `emp_code`, `name` | — | migration `2026_01_01_000100_create_employees_table.php:104-110` |
| `dob` DATE, `gender`, `mobile`(20), `mobile_verified`, `whatsapp`(20), `wa_verified`, `email`, `email_verified`, `address` | — | same migration `:111-118` |
| `department_id`, `designation_id`, `branch_id`, `team_id`, `reporting_manager_id` | bigint FK, indexed | same migration `:119-125` |
| `type` ENUM(office,field) def office | — | `:123` |
| `doj` DATE, `ctc` DEC(12,2) def 0, `salary_type` ENUM def only_salary, `comm_pct` DEC(5,2), `schedule_id` | — | `:126-131` |
| `pf_applicable` bool def false, `esi_applicable` ENUM(auto,yes,no) def auto, `pt_state`, `uan`, `pan`(10) | — | `:132-136` |
| `dra_status` ENUM(verified,pending), `dra_expiry` DATE, `pcc_status` ENUM(submitted,pending,overdue), `pcc_expiry` DATE, `pcc_deadline` DATE | — | `:137-141` |
| `docs_status` ENUM, `docs_stored` bool | — | `:142-143` — **both dead, see DEF-19** |
| `home_lat`, `home_lng`, `geo_start`, `geo_radius_km`, `geo_outside` | — | `:144-148` — **not registry fields** |
| `bank_name`, `bank_acc`, `ifsc`, `device_user_id`(indexed), `status` ENUM(active,notice,exited) | — | `:149-153` |
| `status` widened | `VARCHAR(20) NOT NULL DEFAULT 'active'` | migration `2026_07_15_000000_widen_employees_status.php:24`, plus `UPDATE … SET status='active' WHERE status=''` (`:33`) |
| `dra_status`, `pcc_status` widened | `VARCHAR(20) NULL` | migration `2026_08_27_000100_widen_employee_dra_pcc_status.php` |
| `archived_at`(indexed), `archived_by` | — | migration `2026_07_15_000100_add_employee_archive.php:19-25` |
| `backup_due_at`(indexed), `backup_by` | — | migration `2026_07_15_000200_add_backup_schedule.php:13-19` |
| `dra_declared`(8), `pcc_declared`(8) | — | migration `2026_07_22_000000_add_dra_pcc_declared.php:18-24` |
| `esic_no`, `employment_type`, `marital_status`, `father`, `nationality`, `aadhaar`, `permanent_address`, `emergency_name`, `emergency_phone`, `blood_group`, `category` | `string(255)` | migration `2026_07_22_000002_add_selfonboard_cols_to_employees.php:9,16-22` **and, redundantly, at RUNTIME** by `SelfOnboardingController::ensureEmployeeCols()` (`:1116-1131`) |
| `national_id`, `category`, `esic_no`, `nationality`, `id_marks`, `permanent_address`(500), `emergency_name`, `emergency_phone`, `account_holder`, `bank_branch`, `team_leader`, `also_works_for`, `employment_stage`, `dra_expiry`(date), `pcc_expiry`(date) | typed | migration `2026_08_28_000100_employee_field_parity.php:41-80` |
| `probation_months`, `probation_end`, `probation_confirmed_on`, `probation_confirmed_by` | — | migration `2026_07_26_000100_probation_tracking.php:17-22` **and at RUNTIME** by `ProbationService::ensureSchema()` (`:75-80`) |
| **`department`, `designation`, `branch`, `team`, `reporting_manager`, `team_leader`, `father`, `mother`, `spouse`, `marital_status`, `blood_group`, `id_marks`, `gender`, `address`, `national_id`, `bank_branch`, `pt_state`, `shift`, `employment_stage`, `dra_declared`, `pcc_declared`, `dra_status`, `pcc_status`, `pcc_deadline`, `permanent_address`, `emergency_name`, `emergency_phone`, `esic_no`, `category`, `account_holder`, `nationality`, `also_works_for`, `dra_expiry`, `pcc_expiry`** (34 columns) | **`string(255) NULL`, every one, regardless of semantics** | **CREATED AT RUNTIME** by `AppDataController::ensureEmployeeColumns()` (`AppDataController.php:46-75`), called on every `/app/data` load (`:252`), every export (`EmployeeExportController.php:85`), every import preview/commit (`EmployeeImportService.php:203`) |
| `whatsapp`, `address`, `dob`, `doj` | `string(255) NULL` | **CREATED AT RUNTIME** by `AppDataController::importEmployees()` (`:640-650`) if missing — would make `dob`/`doj` VARCHAR, not DATE |
| **`photo_path`** | `string NULL` | **CREATED AT RUNTIME** by `LetterController::uploadPhoto()` (`:338-342`) |
| **`dra_pcc_declared`** | — | **NO CREATOR AT ALL.** Written by `EmployeeImportService.php:518` and `AppDataController.php:781`, both behind `Schema::hasColumn` — verified: `grep -rn "dra_pcc_declared" database/` returns **nothing**. Confirms DIV-12. |

**Critical finding (SCHEMA-01).** 34 `employees` columns are created by application code at
request time, always as `string`. On any install where the parity migration has not run —
or where the DB user cannot `ALTER` — `pcc_deadline`, `dra_expiry`, `pcc_expiry` are
created as `VARCHAR(255)` instead of `DATE`, and `permanent_address` as `VARCHAR(255)`
instead of the parity migration's `VARCHAR(500)`. The same column therefore has two
different types depending on install history.

### 2.2 Other tables in scope

| Table | Provenance |
|---|---|
| `employee_references` | migration `…000100_create_employees_table.php:168-180` |
| `employee_company` (pivot) | migration `…:162-166` — **read and written by NOTHING** (`grep` finds only the migration) |
| `employee_archives` | migration `2026_07_15_000100_add_employee_archive.php:28-39` |
| `employee_import_jobs` | migration `2026_07_26_000300` **and at RUNTIME** `EmployeeImportService::ensureSchema()` (`:86-107`) |
| `probation_events` | migration `2026_07_26_000100` **and at RUNTIME** `ProbationService::ensureSchema()` (`:81-90`) |
| `self_onboarding`, `self_onboarding_otps`, `self_onboarding_docs` | migration `2026_07_22_000001` **and at RUNTIME** `SelfOnboardingController::ensureTables()` (`:26-82`) |
| `documents` | **RUNTIME ONLY — three creators**: `DocumentController::ensure()` (`:74-99`), `SelfOnboardingController::copyOnboardingDocsToEmployee()` (`:919-934`), `MasterController::ensureCols()` (documents master). **No migration.** The three definitions differ (the SelfOnboarding one omits `status` default `pending`; DocumentController back-fills `category`,`doc_name`,`file_name`,`file_size` separately). |
| `onboarding`, `onboarding_items` | **RUNTIME ONLY — two creators**: `OnboardingController::ensureTables()` (`:41-68`), `SelfOnboardingController::ensureOnboardingTable()` (`:666-682`). The two definitions differ (SelfOnboarding's omits `deleted_at` handling). No migration. |
| `transfers` | **RUNTIME ONLY** — created by `ApprovalService::ensureAuditCols('transfers')`. No migration. |
| `offroll_agents` | **RUNTIME ONLY — three creators** incl. `RecruitmentController.php:652-666`. No migration. |
| `increments` | migration `2026_01_01_000600_create_salary_runs_table.php:248-260`; workflow columns self-healed by `ApprovalService::ensureAuditCols` |
| `tenants.doc_storage_emp_mb` | **RUNTIME** `DocumentController::ensure()` (`:96-98`) |

### 2.3 Indexes
Only `UNIQUE(tenant_id, emp_code)` and `INDEX(company_id, status)` on the base table
(`…000100:158-159`), plus `device_user_id`, `archived_at`, `backup_due_at`, and five
**plain (non-unique)** lookup indexes added by the parity migration for `pan`,
`national_id`, `uan`, `bank_acc`, `esic_no` (`2026_08_28_000100:48-54`). Deliberately not
unique (`:120-124`). Missing indexes on `status` (scanned on every seat count) and
`employment_stage` (scanned by the probation board), and the duplicate check runs
`LOWER(TRIM(col)) = ?` (`EmployeeFieldRules.php:589`) which **cannot use** the plain
indexes that were added for it.

---

## 3. Business rules register

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HARD-CODED? | Configurable today? | Customer may want it different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-EMP-001 | One 56-field registry drives form, template, export, importer aliases | `FIELDS` | always | derive 4 surfaces | tenant-wide | `EmployeeFieldRules.php:80-233,257-431` | **Y** — a PHP constant | No | **Y** — every customer wants their own fields |
| BR-EMP-002 | Employee identified by `(tenant, emp_code)` | tenant_id, emp_code | always | unique index + friendly 422 | tenant | `…000100:158`; `AppDataController.php:2985-3000` | Y | No | Y (some want global codes) |
| BR-EMP-003 | Employee-code auto-generation — **three incompatible algorithms** | blank code | on create | (a) form `'EMP-'.random_int(1000,9999)` retried until unique (`AppDataController.php:2818-2821`); (b) wizard same, **no retry** (`EmployeeImportService.php:375-377`); (c) onboarding/recruitment `'EMP'.pad(count+1,4)` retried (`SelfOnboardingController.php:876-883`, `RecruitmentController.php:619-624`) | tenant | as listed | **Y** | No | **Y** — every customer wants a prefix/series/width they control |
| BR-EMP-004 | Code rename cascades to 8 emp_code-keyed tables | old/new code | rename on form save | `UPDATE emp_code` per table, each in its own try/catch | tenant | `AppDataController.php:3023-3037` | Y | No | N |
| BR-EMP-005 | 8 identifiers must be unique across employees | national_id, mobile, email, device_user_id, uan, esic_no, pan, bank_acc | value non-blank | `SELECT … WHERE LOWER(TRIM(col))=?` excluding self, deleted, archived | tenant (all tenants for super_admin) | `EmployeeFieldRules.php:576-612`; callers `AppDataController.php:802,3013`, `EmployeeImportService.php:343-360`, `SelfOnboardingController.php:848` | Y — the `u`=>`block` flag in the constant | No | **Y** — which fields are unique is a policy |
| BR-EMP-006 | 16 fields have a server-enforced format; **blank always passes** | field value | value non-blank | first error → 422 | all writers except ESS | `EmployeeFieldRules.php:444-550` | **Y** — regexes in code | No | **Y** — non-Indian formats, optional/required |
| BR-EMP-007 | Mobile must be 10 digits starting 6-9 after stripping 91/0 | mobile, whatsapp, emergency_phone | non-blank | reject | all | `EmployeeFieldRules.php:477-489`; `AppDataController.php:2748-2775` | **Y — India only** | No | **Y** — blocks every non-Indian number |
| BR-EMP-008 | Dates are day-first with a month-first fallback | date string | parse | `Y-m-d` or null | importers | `EmployeeImportService.php:793-820`; `AppDataController::normDateWide` `:161-206` | Y | No | Y |
| BR-EMP-009 | Employee free text is HTML-stripped on save | payload | always | `strip_tags` + remove `<>` | form + legacy import + masters only | `AppDataController.php:83-95`, callers `:2739`, `:664`, `MasterController.php:723` | Y | No | N — **but see DEF-11: wizard, portal and ESS skip it** |
| BR-EMP-010 | Company resolution order on save | companyId → companyName → existing → first company | always | set `company_id` | tenant | `AppDataController.php:2831-2841`, `:2988-2990` | Y | No | N |
| BR-EMP-011 | A new employee must fit within licensed seats | tenant seats | **insert only** | 422 | tenant | `SubscriptionService.php:81-149`; enforced `AppDataController.php:3042` (form), `:815` (legacy import), `RecruitmentController.php:602`. **NOT enforced** by wizard or onboarding | Y | seats are per-tenant data | N |
| BR-EMP-012 | Employment stage is `''`/`probation`/`internship`; anything unrecognised → `''` (Permanent) | stage string | non-blank | normalise | all | `AppDataController.php:123-137` | **Y** — 3 states in code | No | **Y** — Contract, Trainee, Apprentice, Consultant are all real Indian stages |
| BR-EMP-013 | Salary type accepts 6 spellings → 3 codes; unrecognised **silently** → `only_salary` | salary type | non-blank | map | all | `EmployeeFieldRules.php:66-73`; `AppDataController.php:2853`, `EmployeeImportService.php:511`, `SelfOnboardingController.php:762` | Y | No | Y |
| BR-EMP-014 | ESI applicability is Auto/Yes/No, default Auto | esi value | present | whitelist else `auto` | all | `AppDataController.php:2960-2963`, `:395`; `EmployeeImportService.php:546-550` | Y | No | N |
| BR-EMP-015 | DRA/PCC certificate status ∈ pending/submitted/verified; anything else **dropped, not stored** | value | present | `complianceStatus()` | all | `AppDataController.php:145-149`, `:2917-2918`; `EmployeeImportService.php:563-567` | **Y** — 3 states | No | **Y** — most customers need more/other certificate states |
| BR-EMP-016 | DRA/PCC declaration is Yes/No/NA — **two different semantics** | value | present | form: literal `NA`; importer: `NA`→`null` "no declaration" | all | `AppDataController.php:101-117` vs `EmployeeImportService.php:771-790` | Y | No | Y |
| BR-EMP-017 | DRA + PCC declarations mandatory for a NEW hire | form state | insert only | toast + switch tab | **browser only** | `AppController.php:1919-1928`; portal `portal.blade.php:321` | Y | No | **Y** — which fields are mandatory is the #1 config ask |
| BR-EMP-018 | Saving an employee **replaces the entire reference list** | `refs[]` | any form save | DELETE all then re-INSERT | employee | `AppDataController.php:3021`, `:3055-3071` | Y | No | Y |
| BR-EMP-019 | Salary schedule assigned by NAME, stored as id; blank clears (form only) | `"Name — Company"` | present | split on ` — `, match name, newest id | tenant | `AppDataController.php:2971-2979`; `EmployeeImportService.php:575-589`; `SelfOnboardingController.php:764-778` | Y | No | N |
| BR-EMP-020 | Employee visible only when `deleted_at IS NULL AND archived_at IS NULL` | — | every read | filter | all | `AppDataController.php:258-259`; `EmployeeExportController.php:150-153` | Y | No | Y (customers want "show archived") |
| BR-EMP-021 | Status is binary at every write point — **and rewritten unconditionally** | status | every form save | `=== 'inactive' ? 'inactive' : 'active'` | all | `AppDataController.php:2704` (`setEmployeeStatus`), **`:2920`** (`storeEmployee` `$payload`, unconditional), `SelfOnboardingController.php:758-760` | **Y** | No | **Y** — customers need Notice, Exited, Suspended, LOA |
| BR-EMP-022 | Six statuses free a licensed seat | status | seat count | exclude from `used` | tenant | `SubscriptionService.php:37`, `:92-97` | **Y** — a private const | No | Y |
| BR-EMP-023 | "Old data" removal has a 72-hour grace period | emp_code | admin/HR | `backup_due_at=now()+3d`, email all tenant admins/HR | tenant | `AppDataController.php:2507-2564`, recipients `:2584-2607` | **Y — `addDays(3)` literal `:2531`** | No | **Y** |
| BR-EMP-024 | Due backups run opportunistically, max 50 per `/app/data` request | — | any authenticated load | archive | tenant | `AppDataController.php:257`, `:2609-2628` | **Y — `limit(50)` `:2621`** | No | **Y** — must be a scheduled job |
| BR-EMP-025 | Archiving snapshots the employee + 10 linked tables, 2000 rows each | employee | on archive | JSON into `employee_archives` | tenant | `AppDataController.php:2630-2660`, `:2441-2464` | **Y — `limit(2000)` `:2449`,`:2457`** | No | Y |
| BR-EMP-026 | Deletion is bulk soft-delete, admin only, max 500 codes | codes[] | admin | `deleted_at=now()`, no cascade | tenant | `AppDataController.php:877-903` | Y | No | Y |
| BR-EMP-027 | A plain `employee` sees only their own record | user | role `employee` | narrow bootstrap to one id | tenant | `AppDataController.php:221-244`, `:268-271` | Y | No | Y |
| BR-EMP-030 | Every onboarding record gets `TMP-YYYY-NNNNN` | year | on invite | max+1 **across all tenants**, not unique-indexed | **global** | `SelfOnboardingController.php:95-99` | Y | No | Y |
| BR-EMP-031 | Onboarding link = 192-bit token, valid 14 days | — | on invite | `bin2hex(random_bytes(24))`, `addDays(14)` | record | `SelfOnboardingController.php:101`, `:107`, gates `:142-147`, `:166-171` | **Y — `addDays(14)`** | No | **Y** |
| BR-EMP-032 | OTP = 6 digits, bcrypt, 10 min, 5 attempts per row, email/whatsapp only | channel | portal | `random_int(100000,999999)` | record | `SelfOnboardingController.php:176-243` | **Y — all four constants** | No | **Y** |
| BR-EMP-033 | Submission requires email verified + (mobile OR whatsapp) verified + personal + bank + selfie | record | submit | 422 listing what is missing | record | `SelfOnboardingController.php:330-359` | **Y** | No | **Y** — `contact`, `statutory` and documents are NOT required despite the portal labelling documents "Required" |
| BR-EMP-034 | A submission must be `verified` before it can be approved | status | approve | 422 | record | `SelfOnboardingController.php:596-598` | Y | No | N — but `hrVerify()` (`:570-582`) performs **no checks at all**, and `hrBulkCommit()` bypasses the gate |
| BR-EMP-035 | Injection maps 26 candidate + 23 HR fields; HR fields win | data JSON | approve | `array_merge($base,$candExtra,$hrPatch)` | tenant | `SelfOnboardingController.php:703-906`, merge at `:885` | Y | No | Y |
| BR-EMP-036 | Approval disables the link but never deletes the record | — | approve | `link_disabled_at=now()` | record | `SelfOnboardingController.php:893` | Y | No | N |
| BR-EMP-037 | Onboarding documents are copied into the Document Tracker on approval | docs | approve | copy local→public, insert `documents` row `status='approved'` | employee | `SelfOnboardingController.php:913-973` | Y — the 7-kind friendly-name map `:935` | No | Y |
| BR-EMP-038 | Bulk onboarding stages CSV rows as pre-submitted and injects wholesale | 17-column CSV | HR | force `status='submitted'`, match `emp_code OR pan OR email OR mobile` | tenant | `SelfOnboardingController.php:994-1113`, match `:1060-1073`, force `:1077` | Y | No | Y |
| BR-EMP-040 | An approved transfer is applied on its effective date | transfer row | approved + due | update employee, snapshot from→to, status `applied` | all tenants (sweep) | `TransferService.php:33-73`, `:112-216`; `ApplyTransfers.php`; `routes/console.php:190-193` daily 06:30 | Y — 06:30 | No | Y |
| BR-EMP-041 | A company transfer clears reporting_manager, team_leader, team, reporting_manager_id | — | company move | set NULL | employee | `TransferService.php:150-157` | Y | No | Y |
| BR-EMP-042 | A company transfer creates a destination onboarding, idempotent **by employee NAME** | — | company move | insert `onboarding` stage `Transfer` | tenant | `TransferService.php:83-109`, name match `:91` | Y | No | Y |
| BR-EMP-043 | Transfer acknowledgement is recorded but is not consent | token | public page | stamp `accepted_at` once | record | `TransferController.php:179-208` | Y | No | Y |
| BR-EMP-044 | Probation end = `probation_end` → `doj + months` → null | doj, months | board/reminders | derive | tenant | `ProbationService.php:102-120`; tested `ProbationServiceTest.php` | Y | `default_months` is configurable per tenant | N |
| BR-EMP-045 | Reminders fire on **exact** `days_left` match against `[30,15,7,0]`, plus daily overdue nudge | days_left | daily 07:15 | `NotificationService::sendOnce` in-app + email | tenant | `ProbationService.php:175-236`; `routes/console.php:253-256` | milestones configurable; **exact-match semantics is hard-coded** | `app_settings.ckey='probation'` | **Y** — a missed scheduler day is never caught up |
| BR-EMP-046 | Confirming probation sets `employment_stage=''` (permanent) | date, by | confirm | update + event + audit + employee notification | tenant | `ProbationService.php:281-318` | Y | notification toggle configurable | N |
| BR-EMP-047 | Extending probation requires a new end date **and** non-blank remarks | new_end, remarks | extend | 422 otherwise | tenant | `ProbationController.php:98-103`; `ProbationService.php:324-340` | Y | No | Y |
| BR-EMP-048 | An approved increment auto-emails a formal letter | increment | on approve | build PDF, queue, stamp `letter_sent_at` | tenant | `IncrementController.php:136-171` | template configurable (`letters` table) | Yes, via letter template | N |
| BR-EMP-049 | Applying an increment to the employee is a manual, idempotent one-click | increment | approved + not applied + new_ctc>0 | `UPDATE employees SET ctc[, designation]` | tenant | `IncrementController.php:314-347` | **Y — deliberately no auto-apply** | No | **Y** — most customers expect auto-apply on the effective date |
| BR-EMP-050 | A back-dated increment generates arrears as one approved money entry | old/new ctc, effective | eff. month < current month | see §4 | tenant | `IncrementController.php:349-426` | **Y — `/12` divisor, calendar-day proration, `>= 1` floor** | No | **Y** — the annual→monthly divisor and proration basis are per-customer |
| BR-EMP-051 | An increment freezes once applied; an approved one can never be deleted | status, applied_at | edit/delete | 422 | tenant | `IncrementController.php:193-308` | Y | No | Y |
| BR-EMP-062 | Documents are capped **per employee**, default 100 MB | file sizes | upload | skip file, `skip_reasons` | tenant | `DocumentController.php:39-53`, `:198-259` | 100 MB default hard-coded; per-tenant override via `tenants.doc_storage_emp_mb` | partly | Y |
| BR-EMP-063 | Document allowlist `pdf,jpg,jpeg,png,doc,docx,xls,xlsx`, 10 MB/file, extension check only | file | upload | reject | tenant | `DocumentController.php:35-37`, `:214-226` | **Y** | No | **Y** |
| BR-IMP-001 | Upload type checked by client-supplied extension only | filename | upload | 422 | — | wizard `EmployeeImportService.php:120-123` (csv,txt,xlsx,xls); legacy `AppDataController.php:601-604` (csv,txt); onboarding bulk `SelfOnboardingController.php:1004-1006` (csv,txt) | Y | No | N |
| BR-IMP-002 | Header matching: strip parenthetical, lower-case, strip non-alphanumerics, first registry match wins | headers | upload | suggest map | — | `EmployeeImportService.php:159-176`; `EmployeeFieldRules.php:291-328` | Y | HR can override the map per file | N |
| BR-IMP-003 | A column must be mapped to `name`; a row without a name is skipped | mapping, row | preview/commit | 422 / row error | — | `EmployeeImportService.php:78`, `:230-232`, `:254-259` | **Y — `REQUIRED = ['name']`** | No | **Y** — customers want emp_code/DOJ/PAN mandatory too |
| BR-IMP-004 | Duplicate handling is skip / update / create on `emp_code` | dup_mode | preview/commit | queue | tenant | `EmployeeImportService.php:208`, `:318-332`, `:372-379` | Y | HR picks per import | N |
| BR-IMP-005 | Commit is all-or-nothing for valid rows; invalid rows excluded beforehand | rows | commit | one `DB::transaction` | tenant | `EmployeeImportService.php:399-464` | Y | No | N |
| BR-IMP-006 | Importing an existing `emp_code` **restores** them from Old data / deletion | update patch | commit | force `deleted_at=null`, `archived_at=null` | employee | `EmployeeImportService.php:411-416` | Y | No | **Y** — this is the only restore path and it is a side effect |
| BR-IMP-007 | `DEFAULT PASSWORD` creates/resets an ESS login, min 6 chars, needs an EMAIL | password, email | preview validates, commit applies | bcrypt into `users`, `syncRoles(['employee'])`, **outside the transaction** | tenant | `EmployeeImportService.php:81`, `:282-289`, `:432-440`, `:620-659` | **Y — `PASSWORD_MIN = 6`** | No | **Y** — password policy |
| BR-IMP-008 | Every import is a job with a downloadable error report | — | commit | insert/update `employee_import_jobs` | tenant | `EmployeeImportService.php:442-449`, `:662-695` | 200 returned / 500 stored / 50 listed, all hard-coded | No | Y |
| BR-IMP-009 | The export is the sample file minus `DEFAULT PASSWORD` | — | export | `exportMap()` filtered by `effectiveMap()` | tenant | `EmployeeExportController.php:36-73`, `:159-201` | Y | No | **Y** — no column chooser |
| BR-IMP-010 | Export scope = tenant + optional `company_id`, excludes deleted + archived, audited | — | export | one `activity_logs` row | tenant | `EmployeeExportController.php:75-122`, `:147-154` | Y | No | **Y** — no status/date/department filter |
| BR-CMP-001 | DRA/PCC/dra_certs/BGV expiries scanned daily over a 30-day window, bucketed | expiry dates | daily 07:30 | expired/critical(≤7)/soon; digest of top 25 | tenant | `ComplianceController.php:31`, `:36-149`, `:206`; `routes/console.php:143-146` | **Y — 30, 7, 25, 07:30** | `?days=` on the read endpoint only | **Y** |
| BR-CMP-004 | DRA needs `verified`; PCC accepts `verified` **or** `submitted` | statuses | readiness | asymmetric | tenant | `ComplianceController.php:572-580` | Y | No | Y |

### New rules the catalogue missed

| RULE ID | Business description | Where | HARD-CODED? | Customer may want different? |
|---|---|---|---|---|
| **NEW-EMP-070** | **The Employee form save is a full-record REPLACE, not a patch.** `$payload` (`AppDataController.php:2846-2941`) declares ~50 fixed keys, each `$e['x'] ?? null`. Any caller that omits a key **NULLs that column**: `ctc` → `0.0` (`:2851`), `type` → `office` (`:2814`), `salary_type` → `only_salary` (`:2852`), `status` → `active` (`:2920`), `pcc_deadline` → null (`:2919`). A partial POST wipes PAN, UAN, bank, address, everything. | `AppDataController.php:2846-2941` | Y | Y — the API must support PATCH |
| **NEW-EMP-071** | **ESS self-edit bypasses every employee-master invariant.** `EssController::updateProfile()` (`:263-292`) writes `mobile, whatsapp, email, address, father, spouse, blood_group, id_marks, gender, dob` with Laravel `nullable|string|max` rules only — **no `formatErrors()`, no `duplicateErrors()`, no `stripHtmlDeep()`**. An ESS user can set their `email` to a colleague's (email is the ESS login id and one of the 8 "unique" identifiers) or a malformed mobile the Directory would reject. | `EssController.php:263-292` | Y | Y |
| **NEW-EMP-072** | **Biometric ID can be silently stolen from another employee.** `BiometricConfigController` (`:617-633`): if another employee holds the device id, a second save with `force` **NULLs the previous holder's `device_user_id`** and re-keys their punches. No audit row, no notification to the losing employee. | `BiometricConfigController.php:617-640` | Y | Y |
| **NEW-EMP-073** | **`employees.probation_months` has no writer anywhere.** The per-employee probation-length override is a real column (migration `2026_07_26_000100:18`), is read by `ProbationService::endDate()` (`:114`), and is **unit-tested** (`ProbationServiceTest.php:22`) — but `grep -rn "probation_months" app/ resources/ routes/` outside the service and migration returns **nothing**. The feature is unreachable. Answers reference-doc OQ-04. | — | Y | Y |
| **NEW-EMP-074** | **`employees.also_works_for` is written by 4 paths and read by nothing.** Only `bootstrap()` echoes it back to the form (`AppDataController.php:379-380`). No payroll, reporting or compliance code reads it. Answers reference-doc OQ-07: it is purely decorative. | — | Y | Y |
| **NEW-EMP-075** | **`employees.docs_status` / `docs_stored` are dead.** `docs_status` is written only by self-onboarding injection (`SelfOnboardingController.php:854`, `:884`) and read by nothing; `docs_stored` is written by nothing and read by nothing. | — | Y | — |
| **NEW-EMP-076** | **`employee_company` (the multi-company pivot) is created by migration and never used.** The product ships an "Also works for → Multiple companies" field with no data model behind it. | `…000100:162-166` | Y | Y |
| **NEW-EMP-077** | **Recruitment→Hire idempotency is keyed on employee NAME.** `createEmployeeFromCandidate()` (`RecruitmentController.php:594-596`) returns `['created'=>false]` when any live employee in the tenant already has `name = $c->name`. Hiring a second "Ravi Kumar" silently creates NO employee and reports the first one's code as the hire. `createOffrollFromCandidate()` does the same (`:668`). | `RecruitmentController.php:594`, `:668` | Y | Y |
| **NEW-EMP-078** | **The legacy importer NULLs columns absent from the file.** `$payload` (`AppDataController.php:690-698`) is a fixed literal using `$row['x'] ?? null`. On an *update*, a file lacking a MOBILE column sets `mobile = NULL`; `ctc` becomes `0`; `type` becomes `office`; `salary_type` becomes `only_salary`. Because `ACCOUNT NUMBER` is mis-normalised (see DEF-01), **every** re-import of the app's own export through this path NULLs `bank_acc` for every employee in the file. | `AppDataController.php:690-698` | Y | Y |
| **NEW-EMP-079** | **`DEFAULT PASSWORD` is a 7th column the legacy importer mis-normalises.** Its header normaliser yields `default_password`; the lookup is `$row['password'] ?? ''` (`AppDataController.php:822`). The reference doc lists 6 mis-normalised columns; there are 7. |  `AppDataController.php:623`, `:822` | Y | — |
| **NEW-EMP-080** | **The one-shot importer is still on two Directory buttons.** `cfg.empImportUrl` → `route('app.employees.import')` (`AppController.php:125`) is wired to the topbar Import button (`:12347`) **and** `window.importCsv('emp-list')` (`:12420`), next to the button that downloads the registry template. Answers reference-doc OQ-03: yes, reachable, and paired with the file it destroys. | `AppController.php:125`, `:12347`, `:12420` | Y | Y |
| **NEW-EMP-081** | **Onboarding checklists are two hard-coded 6-item PHP constants.** `DEFAULT_ITEMS` and `TRANSFER_ITEMS` (`OnboardingController.php:17-39`); no per-tenant, per-company, per-department or per-role variation is possible. | `OnboardingController.php:17-39` | Y | **Y** |
| **NEW-EMP-082** | **Onboarding board heals a dead employee link BY NAME and persists the healed id.** `board()` (`:115-124`) resolves `employee` name → id and writes it back. Two employees with the same name silently swap records. | `OnboardingController.php:115-124` | Y | Y |
| **NEW-EMP-083** | **`OnboardingController::toggle()` has no tenant scope.** `DB::table('onboarding_items')->where('id',$itemId)` (`:180`, `:184`) — any admin/HR of any tenant can tick any other tenant's checklist item. | `OnboardingController.php:180-191` | Y | — |
| **NEW-EMP-084** | **Document categories and onboarding document kinds are two unrelated hard-coded vocabularies.** `DocumentController::CATEGORIES` (6 values, `:21-28`) vs the portal's `DOCS` (7 kinds, `portal.blade.php:230`) vs the injection's friendly-name map (7 entries, `SelfOnboardingController.php:935`). | as listed | Y | **Y** |
| **NEW-EMP-085** | **`documents.expiry` is captured and never alerted on.** `DocumentController` stores an expiry per document (`:242`) and `ComplianceController::scan()` never touches the `documents` table (`grep "documents" ComplianceController.php` → no match). Only `employees.dra_expiry`/`pcc_expiry`, `dra_certs.expiry` and `bgv.next_due` are scanned. | `ComplianceController.php:36-149` | Y | **Y** |
| **NEW-EMP-086** | **The export leaks cross-tenant company and schedule names.** `rows()` plucks `companies` (`EmployeeExportController.php:129`) and `salary_schedules` (`:138`) **with no tenant filter** into id→name maps. | `EmployeeExportController.php:127-145` | Y | — |
| **NEW-EMP-087** | **`teamLeader` read-back silently substitutes the TEAM's leader.** When `employees.team_leader` is blank, `bootstrap()` returns `teams.leader_id`'s name (`AppDataController.php:337`). The next form save then **persists** that substituted value into `employees.team_leader`. | `AppDataController.php:337`, `:2874` | Y | Y |
| **NEW-EMP-088** | **The import cannot clear a field.** `EmployeeImportService::payload()` guards every string column with `! empty($v[$f])` (`:494`) and every typed column with `trim() !== ''`. A blank cell in an update row leaves the old value. There is no "blank = clear" convention and no way to erase a wrong PAN by import. | `EmployeeImportService.php:483-572` | Y | **Y** |

---

## 4. Calculations and formulas

### 4.1 Mobile-number normalisation (`EmployeeFieldRules.php:477-489`)
```php
if (preg_match('/[^0-9+\-\s()]/', $v))  return 'Mobile number is invalid — remove letters and symbols.';
$d = preg_replace('/\D+/', '', $v);
if (strlen($d) === 12 && str_starts_with($d, '91')) $d = substr($d, 2);
elseif (strlen($d) === 11 && str_starts_with($d, '0')) $d = substr($d, 1);
return preg_match('/^[6-9][0-9]{9}$/', $d) ? null : 'Enter a valid 10-digit mobile number (starting 6-9).';
```
Worked example: `+91 98765-43210` → digits `919876543210` (12, starts 91) → `9876543210`
→ passes. `0412345678` → 10 digits, starts `0` → fails (11-digit rule doesn't apply).
**The stored value keeps its original punctuation — normalisation is for validation only.**

### 4.2 Date parsing — three implementations
| Implementation | Order tried | Rejects |
|---|---|---|
| `EmployeeFieldRules::parseDate()` (`:508-525`) | replace every non-digit with `-`, collapse; if part[0] is 4 chars → `Y-M-D` else `D-M-Y`; `checkdate`; year 1900–3000 | impossible dates, year out of range |
| `EmployeeImportService::date()` (`:793-820`) | ISO `Y-m-d`; then `d/m/Y`, falling back to `m/d/Y` only when `d/m/Y` is an impossible calendar date; then `Carbon::parse`; else null | null |
| `AppDataController::normDateWide()` (`:161-206`) | ISO with `-`/`/`/`.`; `d/m/Y` then `m/d/Y`; textual month (`13-May-2024`); **Excel serial** `($s - 25569) * 86400`; then `strtotime` | null |
Worked example: `03/04/2024` is **always 3 April**, never 4 March, in all three.
`04/23/2024` is 23 April via the fallback.

### 4.3 Employment-stage normalisation (`AppDataController.php:123-137`)
```
'' → null (leave unchanged)
'probation'|'probationary' → 'probation'
'internship'|'intern' → 'internship'
anything else → ''  (= Permanent)
```
So `Contract`, `Trainee`, `Consultant`, `Apprentice`, and a typo, all silently become
Permanent.

### 4.4 Probation end date (`ProbationService.php:102-120`)
```
probation_end (explicit)
  else doj + (probation_months ?: config.default_months)   // default 6
  else null   → employee never appears on the board, never reminded
```
Worked example: `doj = 2026-01-01`, no override, default 6 → `2026-07-01`
(`ProbationServiceTest.php:27-30`).

### 4.5 Probation milestone match (`ProbationService.php:186-197`)
```
milestone = in_array(days_left, reminder_days, strict) ? (string) days_left
          : (days_left < 0 && overdue_nudge ? 'overdue' : null)
days_left = today->diffInDays(end, false)
dedupe key = "probation:{empId}:{end}:{milestone}:u{uid}"
```
**Exact integer match.** A scheduler that misses 15 June never fires the 15-day
milestone for anyone whose `days_left` was 15 that day.

### 4.6 Back-dated increment arrears (`IncrementController.php:359-421`) — transcribed exactly
```php
$diffMonthly = ($newCtc - $oldCtc) / 12;
$firstFraction = ($eff->daysInMonth - $eff->day + 1) / $eff->daysInMonth;
$months = 0.0; $cursor = $eff->copy()->startOfMonth(); $isFirst = true;
while ($cursor->format('Y-m') < $curMonth) { $months += $isFirst ? $firstFraction : 1.0; $isFirst = false; $cursor->addMonth(); }
$arrears = round($diffMonthly * $months, 2);
if ($arrears >= 1) { … }
```
Guards (`:362`, `:365`): `old_ctc > 0`, `new_ctc > old_ctc`, `effective` non-blank,
`commissions` table exists, and `effective` month **strictly earlier** than the current
month.

**Worked example.** old CTC 600 000, new CTC 720 000, effective 2026-04-16, today
2026-09-03.
- `diffMonthly = 120000 / 12 = 10 000.00`
- April has 30 days → `firstFraction = (30 − 16 + 1)/30 = 15/30 = 0.5`
- months counted: Apr (0.5) + May + Jun + Jul + Aug = **4.5** (September, the current
  month, is excluded because it already pays the new CTC)
- `arrears = round(10 000 × 4.5, 2) = 45 000.00`
- Target month: `2026-09` unless the September `payroll_runs` row exists and is past
  `draft`, in which case `2026-10` (`:384-395`).
- Written as one `commissions` row: `purpose='Salary Arrears'`, `status='approved'`,
  `payout_method='with_salary'`, `gross_amount = amount = 45000.00`, `tds_rate = 0`,
  `tds_amount = 0` (`:396-416`).

**Divisor/basis:** annual CTC ÷ **12**, hard-coded (`:366`). Proration basis is
**calendar days of the effective month**, hard-coded. Rounding: `round(x, 2)`
half-up. Floor: skipped when `< 1` rupee. A decrease produces nothing.

### 4.7 Increment percentage cross-computation (`IncrementController.php:193-281`)
```
if new_ctc missing and old_ctc and pct present:  new = old × (1 + pct/100)
if old > 0 and new > 0:                          pct = round((new − old) / old × 100, 2)
```

### 4.8 Compliance expiry bucketing (`ComplianceController.php:68-98`)
```
days = today->diffInDays(expiry, false)      // negative = past
if (days > windowDays /*30*/) skip
bucket = days < 0 ? 'expired' : (days <= 7 ? 'critical' : 'soon')
```
Sorted `days` ascending (`:143`). Digest carries the top **25** rows (`:206`).

### 4.9 Self-onboarding progress (`SelfOnboardingController.php:361-384`)
```
done = (email_verified AND (wa_verified OR mobile_verified)) ? 1 : 0
     + one per non-empty section of {personal, contact, statutory, bank}
     + (selfie_path ? 1 : 0)
     + (any self_onboarding_docs row ? 1 : 0)
progress = round(done / 8 * 100)
```
Maximum from this formula is `round(7/8*100) = 88`; `submit()` forces `100` (`:356`).

### 4.10 Document quota (`DocumentController.php:198-232`)
```
limitBytes = (tenants.doc_storage_emp_mb ?? 100) × 1048576   // null tenant = unlimited
skip file when used + file.size > limitBytes                 // used = this employee's total
```
The `used_mb` returned to the UI is the **tenant** total (`:147`, `:158`), not the
employee's — the number shown is not the number enforced.

### 4.11 Seat usage (`SubscriptionService.php:87-99`)
```
used = COUNT(employees WHERE tenant_id = ? AND deleted_at IS NULL
             AND (status IS NULL OR LOWER(status) NOT IN
                  ('exited','inactive','resigned','terminated','left','absconded')))
licensed = subscriptions.seats ?: tenants.seats_licensed
           [overridden on an enforced on-prem install by the .lic device_limit]
```
**No `archived_at` filter** — archiving does not free a seat, contradicting the error
message at `:141`.

---

## 5. Configuration surface today

### (a) What a customer CAN change without code
1. **Probation defaults per tenant** — `default_months`, `reminder_days[]`,
   `overdue_nudge`, `notify_hr`, `notify_manager`, `notify_employee_on_confirm`, stored
   as JSON in `app_settings` under `ckey='probation'` (`ProbationService.php:44-70`);
   edited via `POST /app/probation/config`. **`array_replace_recursive` over the defaults
   means a key can never be removed, only overridden.**
2. **Per-tenant document storage allowance** — `tenants.doc_storage_emp_mb`, set by the
   platform Super Admin (`DocumentController.php:42-53`).
3. **Master lists that feed employee dropdowns** — companies, departments, designations,
   branches, teams, shifts, salary schedules, banks, via `MasterController` — but only
   the *values*, never the *fields*.
4. **The increment letter body** — a tenant `letters` row with `is_template=1` and
   `letter_type='increment'` overrides `DEFAULT_BODY` (`IncrementController.php:60-129`).
5. **Compliance alert window on read only** — `GET /app/compliance-alerts?days=N`
   (`ComplianceController.php:151-168`). The scheduled digest is fixed at 30.
6. **Feature write-roles** — only in the sense that `FeaturePermissions::MAP` names them;
   the map itself is a PHP constant (`FeaturePermissions.php:60-85`), so this is *not*
   customer-configurable.
7. **Import column mapping, per file** — HR corrects the suggested map on the wizard's
   mapping screen (`EmployeeImportService.php:159-176`, filtered server-side `:224-229`).
8. **Import duplicate mode, per file** — skip / update / create (`:208`).
9. **Off-roll DRA gate** — tenant setting `rates()['dra_gate']` ∈ off | warn | block.

That is the complete list. **Nine items.**

### (b) What a customer CANNOT change without code — the SmartPRS 3 backlog

**Fields and metadata**
1. Add, remove, rename or re-label **any** employee field. Requires a `FIELDS` row plus
   6–16 further edit sites (§ answer to Q2 below).
2. Add a **custom field** of any kind. There is no custom-field table, no EAV, no JSON
   attribute bag, no per-tenant schema. Zero custom-field capability exists.
3. Change any dropdown's fixed value set: Gender (3), Marital (4), Blood group (8),
   Category (5, `CATEGORY_OPTS`), Employee Type (2), Employment Stage (3), Status (2),
   Also Works For (2), PF (2), ESI (3), Salary Type (3), DRA/PCC Declared (3),
   DRA/PCC Certificate status (3), PT State (36).
4. Mark **any** field required. `EmployeeFieldRules::FIELDS` has no `req` key; the only
   server-side requirement is `name` (`AppDataController.php:2740`,
   `EmployeeImportService.php:78`).
5. Change which 8 fields are duplicate-blocked (the `u=>'block'` flag).
6. Change any of the 16 format rules — PAN, IFSC, UAN, bank account 6–20 digits,
   Biometric ID alphanumeric-only, mobile 10-digit-starting-6-9, email, CTC, commission
   0–100, DOB not-future, date shape.
7. Move a field to a different tab, or add/rename a tab (`ep/ej/es/ed/eb` hard-coded in
   `formPanels()` `:337`).
8. Make a field read-only for a role, or hide it from a role.
9. Change the display order of fields (registry array order is the only order).

**Identity and codes**
10. Employee-code format, prefix, width, series, or per-company numbering. Three
    incompatible generators exist and none is configurable.
11. Which field is the primary identifier (always `emp_code`).
12. Temp onboarding code format `TMP-YYYY-NNNNN`.

**Status and lifecycle**
13. The employee status value set (Active/Inactive only from any UI).
14. Which statuses free a licensed seat (`SEAT_FREE_STATUSES`, a private const).
15. Any status transition guard — none exist.
16. The 72-hour backup grace period.
17. The 50-archives-per-request batch and the fact that archiving runs on a page load
    rather than a schedule.
18. The 2000-rows-per-table snapshot cap.
19. The 500-code bulk-delete cap and the admin-only role gate.
20. Whether archived employees appear in the compliance scan, the probation board, the
    document picker or the seat count (four inconsistent answers, all hard-coded).
21. Employment stages beyond Permanent / Probation / Internship.
22. Add an Exit / resignation state that survives the next form save.

**Import / export**
23. Which columns are required to import.
24. Whether a blank cell clears a field on update (currently: never; NEW-EMP-088).
25. Row caps, file-size caps, streaming behaviour (none exist — whole file in memory,
    twice).
26. The 200/500/50 error and history caps.
27. Export column selection, ordering, filtering by status/department/date, or
    "include archived".
28. Export date formats (three compliance dates contradict their own headers).
29. Password policy for the `DEFAULT PASSWORD` column (min 6, hard-coded).
30. Retention/purge of staged import files (none — they are never deleted).

**Self-onboarding**
31. The 8 steps, their order, or which sections are mandatory to submit.
32. Which fields the candidate is asked (hard-coded HTML in `portal.blade.php`).
33. Which fields HR fills (hard-coded three times: the form HTML at
    `hr-console.blade.php:109-121`, the POST key array at `:137`, the server whitelist at
    `SelfOnboardingController.php:526-530`).
34. Link lifetime (14 days), OTP length (6), OTP lifetime (10 min), OTP attempts (5).
35. Whether documents are actually required (the portal says "Required"; `submit()`
    does not check).
36. Which document kinds are collected (7, hard-coded) and their friendly names.
37. Selfie size bounds (500 B – 4 MB) and document cap (5 MB).
38. Whether the bulk path must pass verification (it does not).
39. The bulk matching predicate `emp_code OR pan OR email OR mobile`.

**Compliance and documents**
40. The 30-day alert window for the scheduled digest, the ≤7-day "critical" threshold,
    the 25-row digest cap and the 07:30 send time.
41. That PCC `submitted` counts as compliant while DRA requires `verified`.
42. That `pcc_deadline` raises no alert at all.
43. That `documents.expiry` raises no alert at all.
44. The 6 document categories, the 8 allowed extensions, the 10 MB per-file cap.
45. Document retention — deletion is a hard delete of row and file, no recycle bin.

**Workflow**
46. The 6-item onboarding and 6-item transfer checklists.
47. That an increment is applied by a manual click rather than on its effective date.
48. The `/12` divisor and calendar-day proration used for arrears.
49. That a company transfer clears the reporting hierarchy but keeps the designation.
50. The 06:30 / 07:15 / 07:30 scheduler times.
51. Probation reminder catch-up semantics (exact-day match, no catch-up).
52. Who receives probation reminders (roles hard-coded as
    `admin|hr_manager|hr|super_admin` at `ProbationService.php:252`).

---

## 6. Defects, conflicts and technical debt

### Answers to the seven questions asked

---

## Q1 — COMPLETE FIELD-PARITY MATRIX

**Surfaces**
| Key | Surface | Entry point |
|---|---|---|
| **DIR** | Directory Add/Edit Employee form (read-back + save) | `AppController` `cfg.empFields` → `POST /app/employees` → `storeEmployee()` |
| **TPL** | Downloadable sample import file (.xlsx w/ dropdowns, CSV fallback) | `GET /app/employees/template` |
| **WIZ** | Import wizard (upload → map → preview → commit) | `POST /app/employee-import/*` |
| **LEG** | Legacy one-shot CSV importer (still on two Directory buttons) | `POST /app/employees/import` |
| **EXP** | Export CSV / XLSX | `GET /app/employees/export` |
| **SOP** | Self-onboarding candidate portal | `portal.blade.php` → `save()` → `injectOne()` |
| **SOH** | Self-onboarding HR-fields console | `hr-console.blade.php` → `hrSetFields()` → `hrPatch()` |
| **ESS** | Employee self-service | `EssController::profile()` / `updateProfile()` |
| **REC** | Recruitment → Hire | `createEmployeeFromCandidate()` |

**Legend**
`●` present and writable · `○` present, read-only · `—` absent from that surface ·
`✗` **silently dropped** (the surface appears to carry it but the value never reaches
the column) · `!` set-only (a blank value cannot clear an existing one) ·
`▲` written but with a different value set / semantics than DIR
There is **no mobile app and no employee REST API** in this codebase: `routes/api.php`
exposes only `ping`, `attendance/punches` and a SmartEPT webhook — no employee
endpoint. The "mobile/API" columns are therefore folded into ESS and marked N/A below.

### Tab `ep` — Personal (20 fields)
| # | Column | Header | DIR | TPL | WIZ | LEG | EXP | SOP | SOH | ESS | REC | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | `emp_code` | EMPLOYEE CODE | ● | ● | ●! | ● | ● | — | ● | ○ | ● (generated) | LEG accepts 8 spellings (`AppDataController.php:680`) |
| 2 | `name` | NAME | ● | ● | ● **req** | ● **req** | ● | ● | — | ○ | ● | only server-required field anywhere |
| 3 | `dob` | DOB (DD-MM-YYYY) | ● | ● | ●! | **✗** | ● | ● | — | ● | — | LEG normalises header to `dob_(dd_mm_yyyy)`, looks for `dob` |
| 4 | `gender` | GENDER | ● | ● | ●!▲ | ● | ● | ● | — | ● | — | WIZ/ESS accept any string; form select has 3 options |
| 5 | `marital_status` | MARITAL STATUS | ● | ● | ●!▲ | ● | ● | ● | — | — | — | |
| 6 | `father` | FATHER NAME | ● | ● | ●! | ● | ● | ● | — | ● | — | |
| 7 | `mother` | MOTHER NAME | ● | ● | ●! | ● | ● | ● | — | — | — | |
| 8 | `spouse` | SPOUSE NAME | ● | ● | ●! | ● | ● | ● | — | ● | — | |
| 9 | `nationality` | NATIONALITY | ● | ● | ●! | **✗** | ● | ● | — | — | — | LEG has no lookup at all |
| 10 | `category` | CATEGORY | ● | ● | ●!▲ | **✗** | ● | ● | — | — | — | |
| 11 | `blood_group` | BLOOD GROUP | ● | ● | ●!▲ | ● | ● | ● | — | ● | — | |
| 12 | `id_marks` | IDENTIFICATION MARKS | ● | ● | ●! | **✗** | ● | ● | — | ● | — | |
| 13 | `national_id` | NATIONAL ID / SSN | ● **u** | ● | ●! **u** | **✗** | ● | ● **u** | — | — | — | LEG normalises to `national_id_/_ssn` |
| 14 | `mobile` | MOBILE | ● **u** | ● | ●! **u** | ●**NULLs** | ● | ○ (from invite) | — | ● **no validation, no uniqueness** | ● | |
| 15 | `whatsapp` | WHATSAPP | ● | ● | ●! | ●**NULLs** | ● | ○ (from invite) | — | ● | — | **not** duplicate-blocked |
| 16 | `email` | EMAIL | ● **u** | ● | ●! **u** | ●**NULLs** | ● | ○ (from invite) | — | ● **no validation, no uniqueness** | ● | ESS login id |
| 17 | `emergency_name` | EMERGENCY CONTACT PERSON | ● | ● | ●! | **✗** | ● | ● | — | — | — | |
| 18 | `emergency_phone` | EMERGENCY CONTACT NUMBER | ● | ● | ●! | **✗** | ● | ● | — | — | — | |
| 19 | `address` | PRESENT ADDRESS | ● | ● | ●! | **✗** | ● | ● | — | ● | — | LEG normalises to `present_address` |
| 20 | `permanent_address` | PERMANENT ADDRESS | ● | ● | ●! | **✗** | ● | ● | — | — | — | |

### Tab `ej` — Job & Company (14 fields)
| # | Column | Header | DIR | TPL | WIZ | LEG | EXP | SOP | SOH | ESS | REC | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 21 | `company_id` | COMPANY | ● | ● | ●! | ● | ● (name) | — | — | — | ● | resolved by name; unresolvable → tenant's first company |
| 22 | `also_works_for` | ALSO WORKS FOR | ● | ● | ●! | **✗** | ● | — | ● | — | — | **read by nothing** (NEW-EMP-074) |
| 23 | `department` | DEPARTMENT | ● name only | ● | ●! name only | ● name only | ● name only | — | ● name **+ FK** | ○ | ● **FK only** | REC-created employees export blank |
| 24 | `designation` | DESIGNATION | ● name only | ● | ●! name only | ● name only | ● name only | — | ● name **+ FK, creates missing** | ○ | ● **FK only** | also written by increment apply (name only) |
| 25 | `type` | EMPLOYEE TYPE | ● | ● | ●! | **✗ every row → `office`** | ● | — | ● | ○ | ● | LEG normalises to `employee_type`, looks for `type` |
| 26 | `employment_stage` | EMPLOYMENT STAGE | ● | ● | ●! | **✗** | ● | — | ● | — | — | also written by probation confirm |
| 27 | `status` | STATUS | ●**always rewritten** | ● | ●!**▲ any string** | **✗** | ● | — | ● | — | ● (`active`) | see DEF-04 |
| 28 | `branch` | BRANCH | ● name only | ● | ●! name only | ● name only | ● name only | — | ● name **+ FK** | — | ● **FK only** | also written by transfer apply |
| 29 | `team` | TEAM | ● name only | ● | ●! name only | ● name only | ● name only | — | ● name only | — | — | nulled by a company transfer |
| 30 | `reporting_manager` | REPORTING MANAGER | ● name only | ● | ●! name only | **✗** | ● name only | — | ● name only | — | ● **FK only** | see DEF-05 |
| 31 | `team_leader` | TEAM LEADER | ● name only | ● | ●! name only | **✗** | ● name only | — | ● name only | — | — | see NEW-EMP-087 |
| 32 | `doj` | DOJ (DD-MM-YYYY) | ● | ● | ●! | **✗** | ● | — | ● **not normalised** | ○ (`joined`) | ● | `hrPatch` copies verbatim (`:736-738`) |
| 33 | `shift` | SHIFT | ● | ● | ●! | ● | ● | — | ● | — | — | |
| 34 | `device_user_id` | BIOMETRIC ID | ● **u** | ● | ●! **u** | ● | ● | — | ● | — | — | also written by BiometricConfig, which steals it |

### Tab `es` — Statutory & Salary (9 fields)
| # | Column | Header | DIR | TPL | WIZ | LEG | EXP | SOP | SOH | ESS | REC | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 35 | `salary_type` | SALARY TYPE | ● | ● | ●! | ● (3 spellings only) | ● | — | ● | — | ● | unrecognised → `only_salary`, silently |
| 36 | `schedule_id` | SALARY SCHEDULE | ● (blank clears) | ● | ●! (never clears) | **✗** | ● | — | ● (never clears) | — | — | |
| 37 | `ctc` | CTC | ● **→0 if omitted** | ● | ●! | ● **→0 if omitted** | ● | — | ● | — | ● | also written by increment apply |
| 38 | `comm_pct` | COMMISSION % | ● | ● | ●! | **✗** | ● | — | ● | — | — | |
| 39 | `pf_applicable` | PF APPLICABLE | ● | ● | ●! | **✗** | ● Yes/No | — | ● | — | — | |
| 40 | `uan` | UAN | ● **u** | ● | ●! **u** | ● | ● | ● **u** | ● as `pf_uan` — **overwrites candidate value** | — | — | alias `pf` maps here, not to PF Applicable |
| 41 | `esi_applicable` | ESI APPLICABLE | ● | ● | ●! | **✗** | ● | — | ● | — | — | |
| 42 | `esic_no` | ESIC | ● **u** | ● | ●! **u** | **✗** | ● | ● **u** | ● | — | — | the only field on both SOP and SOH |
| 43 | `pt_state` | PT STATE | ● | ● | ●! | **✗** | ● | — | ● | — | — | 36 states hard-coded |

### Tab `ed` — Documents & compliance (8 fields)
| # | Column | Header | DIR | TPL | WIZ | LEG | EXP | SOP | SOH | ESS | REC | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 44 | `pan` | PAN | ● **u** | ● | ●! **u** | ● | ● | ● **u** | — | — | — | uppercased on save |
| 45 | `dra_declared` | DRA DECLARED (YES/NO) | ● (browser-mandatory on insert) | ● | ●! | **✗ writes `dra_pcc_declared`, a column no migration creates** | ● | ● (portal key `dra_status`) | — | — | — | |
| 46 | `pcc_declared` | PCC DECLARED (YES/NO) | ● | ● | ●! | **✗ same** | ● | ● (portal key `pcc_status`) | — | — | — | |
| 47 | `dra_status` | DRA CERTIFICATE | ● (unrecognised **dropped**) | ● | ●! (dropped) | **✗** | ● | — | **—** | — | — | **HR console cannot set it at onboarding** |
| 48 | `dra_expiry` | DRA EXPIRY (DD-MM-YYYY) | ● | ● | ●! | **✗** | ● **as `Y-m-d`** | — | **—** | — | — | header says DD-MM-YYYY |
| 49 | `pcc_status` | PCC STATUS | ● (dropped) | ● | ●! (dropped) | **✗** | ● | — | **—** | — | — | |
| 50 | `pcc_deadline` | PCC DEADLINE (DD-MM-YYYY) | ● **nulled if key omitted** | ● | ●! | **✗** | ● **as `Y-m-d`** | — | **—** | — | — | **read by nothing** |
| 51 | `pcc_expiry` | PCC EXPIRY (DD-MM-YYYY) | ● | ● | ●! | **✗** | ● **as `Y-m-d`** | — | **—** | — | — | |

### Tab `eb` — Bank (5 fields)
| # | Column | Header | DIR | TPL | WIZ | LEG | EXP | SOP | SOH | ESS | REC | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 52 | `bank_name` | BANK NAME | ● | ● | ●! | ● | ● | ● | — | — | — | |
| 53 | `account_holder` | BANK ACCOUNT HOLDER | ● | ● | ●! | **✗** | ● | ● | — | — | — | |
| 54 | `bank_acc` | ACCOUNT NUMBER | ● **u** | ● | ●! **u** | **✗ and NULLs existing** | ● | ● **u** | — | — | — | LEG normalises to `account_number` |
| 55 | `bank_branch` | BANK BRANCH | ● | ● | ●! | ● | ● | ● | — | — | — | |
| 56 | `ifsc` | IFSC | ● | ● | ●! | ●**NULLs** | ● | ● | — | — | — | uppercased on save |

### Import-only column
| Field | TPL | WIZ | LEG | EXP | Notes |
|---|---|---|---|---|---|
| `DEFAULT PASSWORD` | ● (appended last, `AppDataController.php:927`) | ● validated (email required, ≥6 chars) | **✗ mis-normalised to `default_password`** | **never exported** | NEW-EMP-079 |

### Real employee attributes that are on NO registry surface
| Column | DIR | TPL/WIZ/LEG/EXP | SOP/SOH | ESS | Written by | Notes |
|---|---|---|---|---|---|---|
| `home_lat`, `home_lng`, `geo_start`, `geo_radius_km`, `geo_outside` | ● (form only, browser-validated) | **—** | — | — | `storeEmployee` `:2946-2969` | **not exportable, not importable** |
| `photo_path` | ● (separate upload endpoint) | — | — | ○ | `LetterController::uploadPhoto()` | runtime-created column |
| `probation_months` | **—** | — | — | — | **nothing** | NEW-EMP-073 |
| `probation_end`, `probation_confirmed_on`, `probation_confirmed_by` | — | — | — | — | `ProbationService` | not on any employee surface |
| `email_verified`, `mobile_verified`, `wa_verified` | — | — | ● (OTP) | — | onboarding only | never re-settable by HR |
| `docs_status`, `docs_stored` | — | — | ● / none | — | onboarding / nothing | dead |
| `department_id`, `designation_id`, `branch_id`, `team_id`, `reporting_manager_id` | **—** | — | ● (SOH, 3 of 5) | — | SOH + REC only | DEF-05 |
| `archived_at`, `archived_by`, `backup_due_at`, `backup_by` | ○ (countdown) | — | — | — | archive endpoints | |
| `aadhaar`, `employment_type` | — | — | — | — | **nothing** (legacy) | still created on every install |
| `employee_references` (whole table) | ● | **—** | — | — | `storeEmployee` only | destroyed by any save that omits `refs` |

### Parity summary
| Surface | Registry fields present | Silently dropped | Notes |
|---|---|---|---|
| DIR | 56 / 56 | 0 | but full-record replace (NEW-EMP-070) |
| TPL | 56 + password | 0 | |
| EXP | 56 | 0 | 3 dates in the wrong format |
| WIZ | 56 / 56 | 0 | all set-only; 13 fields have no value-set check |
| **LEG** | **26 / 56** | **30** | 7 mis-normalised, 23 never implemented; 6 columns actively NULLed |
| SOP | 26 / 56 | 0 | |
| SOH | 22 + `pf_uan` | 0 | no compliance-certificate fields at all |
| ESS | 16 read / 10 write | 0 | **no validation, no uniqueness, no HTML strip** |
| REC | 12 fields, FKs only | — | denormalised name columns left blank → export blanks |

---

## Q2 — Custom fields, and how many places must change to add one field

### Is there ANY custom-field capability?
**No. None whatsoever.** There is no custom-field table, no EAV table, no JSON attribute
column on `employees`, no per-tenant field metadata, no field-definition UI, and no
tenant-scoped variation of `EmployeeFieldRules::FIELDS` — it is a `const` array
(`EmployeeFieldRules.php:80`), identical for every tenant on every install. The nearest
thing to dynamism is `AppDataController::ensureEmployeeColumns()`, which adds a
**developer-declared** list of columns at runtime; it is a deployment convenience, not a
customer capability. Adding a field is a code change and a release.

### How many places must change to add ONE new employee field today?

**Minimum viable (form + template + export + wizard, plain string column): 6 sites in 4 files + 1 migration.**

| # | File | What must be edited | Why — what breaks otherwise |
|---|---|---|---|
| 1 | `app/Services/EmployeeFieldRules.php:80-233` | one `FIELDS` row | field does not exist |
| 2 | `database/migrations/2026_08_28_000100_employee_field_parity.php` `PARITY_COLUMNS` (or a new migration) | add the column | no column |
| 3 | `app/Http/Controllers/AppDataController.php:52-64` `ensureEmployeeColumns()` `$cols` | add the column name | on installs where the migration has not run (or the DB user cannot ALTER) the value is silently dropped by `array_intersect_key` |
| 4 | `AppDataController.php:2846-2941` `storeEmployee()` `$payload` literal | add `'col' => $e['formKey'] ?? null` | the form shows the input and **never saves it** |
| 5 | `AppDataController.php:301-427` `bootstrap()` employee map | add `'formKey' => $col(...)` | the value never returns to the form, so **the next save writes NULL over it** (NEW-EMP-070) |
| 6 | `app/Services/EmployeeImportService.php:483-497` `payload()` copy list | add the column name | the wizard maps the header, validates it, and then **drops it** |

*(`EmployeeExportController` and `employeeTemplate()` need no edit — those two genuinely
derive from the registry. That is the whole of what the "one list" achieved.)*

**Full parity across every surface: 13–16 edit sites across 8 files + 1 migration.**

| # | File | Edit |
|---|---|---|
| 7 | `AppDataController.php:698-720` `importEmployees()` `$extra` | legacy importer |
| 8 | `SelfOnboardingController.php:827-834` `$candExtra` | candidate-supplied field |
| 9 | `SelfOnboardingController.php:526-530` `hrSetFields()` `$keys` | HR-supplied field (server whitelist) |
| 10 | `SelfOnboardingController.php:703-789` `hrPatch()` | map HR key → employees column |
| 11 | `resources/views/self-onboarding/portal.blade.php` | the `data-field` input, in the right `data-step` section |
| 12 | `resources/views/self-onboarding/hr-console.blade.php:109-121` | the `hrForm` HTML |
| 13 | `resources/views/self-onboarding/hr-console.blade.php:137` | **the same key list again**, in the `saveHr` POST array |
| 14 | `AppController.php:1645-1723` `EMP_VALID_IDS` + `empFieldError()` | browser validation, if the field has a format |
| 15 | `portal.blade.php:292-298` `SO_RULES` | portal browser validation, **a third copy of the same regex** |
| 16 | `EssController.php:70-101` + `:274-284` | if ESS should see or edit it |
| 17 | `RecruitmentController.php:626-637` | if set at hire |
| 18 | `ComplianceController::scan()` | if it needs expiry alerting |

**Every one of those is a PHP/Blade source edit requiring a redeploy.** The registry's own
docblock claims the procedure is three steps (`EmployeeFieldRules.php:30-35`); it
understates the real cost by a factor of four to five, and does not mention
`bootstrap()`, `injectOne()`, `hrSetFields()`, `hrPatch()`, the two blade files, or the
two browser-side validation copies.

---

## Q3 — Does the import silently drop unknown or unmapped columns?

**Yes — through four independent mechanisms, in every importer.**

**(a) Wizard: an unmatched header maps to `null` and is discarded.**
`EmployeeImportService::suggestMapping()` (`:159-176`) ends with `$map[$h] = null;` and
the comment *"unmapped — the user can pick, or it is ignored"*. `process()` then filters
the posted mapping (`:224-229`):
```php
foreach ($mapping as $header => $field) {
    if ($field && array_key_exists($field, self::fields())) { $map[$header] = $field; }
}
```
A header the user does not manually assign is never read. Only a missing `name` mapping
produces an error (`:230-232`). **No warning, no count, no report of unmapped columns.**

**(b) Wizard: columns not present on `employees` are dropped after mapping.**
`payload()` ends (`:603-608`):
```php
if (self::$empCols === null) { self::$empCols = Schema::getColumnListing('employees'); }
if (self::$empCols) { $out = array_intersect_key($out, array_flip(self::$empCols)); }
```
On an install where the parity migration has not run, `also_works_for`, `team_leader`,
`account_holder`, `id_marks`, `nationality` are mapped, validated, previewed as
importable — and then dropped at write time with no error. `dra_pcc_declared` is
dropped this way on **every** install (no migration creates it). This is
`BR-IMP-700` — "silent column drop on every self-healing write".

**(c) Legacy importer: 30 of 56 columns of the app's own file are dropped.**
Its header normaliser is `str_replace([' ','-'],'_', strtolower(trim($h)))`
(`AppDataController.php:623`) — it does **not** strip parentheticals and does **not**
strip `/`. Against the file `GET /app/employees/template` produces:

*Mis-normalised (the code knows the field but never finds it) — **7**, not 6:*
`DOB (DD-MM-YYYY)`→`dob_(dd_mm_yyyy)`; `DOJ (DD-MM-YYYY)`→`doj_(dd_mm_yyyy)`;
`NATIONAL ID / SSN`→`national_id_/_ssn`; `PRESENT ADDRESS`→`present_address`;
`ACCOUNT NUMBER`→`account_number`; `EMPLOYEE TYPE`→`employee_type` (so **every row
imports as `office`**, `:684`); **`DEFAULT PASSWORD`→`default_password`** (the lookup at
`:822` is `$row['password']`) — NEW-EMP-079.

*Never implemented — 23:* NATIONALITY, CATEGORY, IDENTIFICATION MARKS, EMERGENCY CONTACT
PERSON, EMERGENCY CONTACT NUMBER, PERMANENT ADDRESS, ALSO WORKS FOR, EMPLOYMENT STAGE,
STATUS, REPORTING MANAGER, TEAM LEADER, SALARY SCHEDULE, COMMISSION %, PF APPLICABLE,
ESI APPLICABLE, ESIC, PT STATE, DRA CERTIFICATE, DRA EXPIRY, PCC STATUS, PCC DEADLINE,
PCC EXPIRY, BANK ACCOUNT HOLDER.

*Read but written to a non-existent column — 2:* DRA DECLARED and PCC DECLARED are
matched by the substring finder (`:743-753`) and folded into `dra_pcc_declared`, guarded
by `Schema::hasColumn` — a column no migration creates, so the write is dead code
(DIV-12, confirmed).

**And worse: it does not merely drop them, it destroys existing data.** `$payload`
(`:690-698`) is a fixed literal using `$row['x'] ?? null`, so on an *update* a file
lacking a column writes `NULL` over the stored value. Feeding the app's own export back
through this button NULLs `bank_acc` for every employee in the file (ACCOUNT NUMBER is
mis-normalised), and resets `type` to `office` for all of them (NEW-EMP-078).

**(d) Self-onboarding bulk: a fourth vocabulary, 17 fixed columns.**
`hrBulkUpload()` (`:1019-1058`) normalises with `preg_replace('/[^a-z0-9]/','',strtolower())`
and reads only the keys it names. Everything else in the sheet is discarded silently.

**Conclusion.** Four importers, four header vocabularies, four different normalisation
algorithms, and unknown columns are silently discarded by all four. Only the wizard even
shows the user which headers were matched, and it never lists the ones that were not.

---

## Q4 — Does self-onboarding write to the canonical Employee record or a parallel structure?

**Both, sequentially — and the parallel structure is never reconciled or purged.**

**Phase 1 — parallel, for the whole candidate-facing life of the record.** Every value the
candidate enters is written to `self_onboarding.data`, a single JSON column, keyed by
section (`personal`, `contact`, `statutory`, `bank`) and by *portal* field names
(`full_name`, `acc_no`, `aadhaar`, …) that are **not** employees columns
(`SelfOnboardingController::save()` `:279-282`). HR's fields go into the same JSON under
`data.hr` (`hrSetFields()` `:538-540`), keyed by *importer* names. Selfie and documents
go to the `local` disk and `self_onboarding_docs`. The `employees` table is untouched
throughout. A record can sit in `submitted`/`correction`/`verified` indefinitely with no
employee row.

**Phase 2 — one-way copy into the canonical record at approval.** `injectOne()`
(`:793-906`) reads the JSON, builds `$candExtra` (17 keys, `:827-834`), `$hrPatch`
(`hrPatch()` `:703-789`) and, for a new hire, `$base` (`:884`), and merges
`array_merge($base, $candExtra, $hrPatch)` — **HR fields win over candidate fields**.

Two branches:
- **Existing employee** (`rec->employee_id` set, `:853-864`): patches the employee row.
  **`name`, `email`, `mobile` and `whatsapp` are never updated on this branch** — the
  candidate can correct their own name and it will not be applied.
- **New employee** (`:865-891`): inserts with hard-coded defaults `type='office'`,
  `salary_type='only_salary'`, `status='active'`, `doj = today`, `docs_status='approved'`
  (`:884`), then inserts an `onboarding` checklist row.

**After injection the parallel copy is frozen, not deleted.** `status='injected'`,
`link_disabled_at=now()` (`:893`). Nothing purges or redacts `self_onboarding.data`,
which holds PAN, National ID, UAN, ESIC, bank account and IFSC as plaintext JSON, plus
the selfie and documents on disk. There is **no re-sync**: an HR correction to the
employee record afterwards does not update the onboarding record, and vice versa.
`ApprovalService::safeRow()` silently drops any key that is not a real `employees`
column, so a portal field whose column is missing on this install is accepted, verified,
approved — and never stored.

---

## Q5 — Are historical employee attributes effective-dated, or overwritten?

**Overwritten, for every attribute, on every path. There is no effective-dated employee
history and no field-level audit trail.** `employees` carries exactly one current value
per attribute; every writer issues `UPDATE employees SET …`. `storeEmployee()` does not
write an `activity_logs` / `Audit::record` entry at all — unlike `MasterController`,
which audits every create/update/delete (`MasterController.php:792-800`).

Three *partial* side registers exist, and only for changes made through their own
workflow:

| Attribute | Register | Effective date? | Covers direct edits? |
|---|---|---|---|
| Branch, Department, Company | `transfers` — `from_company`, `from_branch`, `to_company`, `to_branch`, `to_department`, `effective_date`, `applied_at`, `status='applied'` (`TransferService.php:184-193`) | **Yes** (`effective_date`) | **No** — the Employee form writes `employees.branch` / `.department` directly with no record |
| CTC, Designation | `increments` — `old_ctc`, `new_ctc`, `pct`, `effective`, `applied_at`, `applied_by` (`IncrementController.php:338-347`) | **Yes** (`effective`) | **No** — the Employee form writes `ctc` and `designation` directly; so does ESS-adjacent tooling |
| Employment stage (probation only) | `probation_events` — `action`, `details`, `effective`, `by`, `at` (`ProbationService.php:357-367`) | partial | **No** |

Nothing at all records history for: **status**, **reporting manager**, **team leader**,
**team**, **shift**, **salary type**, **commission %**, **PF/ESI applicability**, **PT
state**, **bank details**, **statutory identifiers**, **DRA/PCC status and expiry**,
**biometric ID**, **employment stage other than probation confirmation**, or **name**.
`employees` has `updated_at` and nothing else.

### Can you answer "what was this employee's branch on 15 May?"
**No, not reliably.** You can *approximate* it only under all of these conditions:
1. every branch change for that employee went through the transfer workflow **and** was
   approved **and** was applied (`status='applied'`, `applied_at` set); and
2. no one ever edited `employees.branch` on the Employee form (`AppDataController.php:2869`),
   through the import wizard (`EmployeeImportService.php:483-497`), through the legacy
   importer (`AppDataController.php:700`) or through the self-onboarding HR fields
   (`SelfOnboardingController.php:722-728`) — all four write the branch directly with no
   history row; and
3. you accept that `transfers.from_branch` is snapshotted at **apply** time, not at
   request time (`TransferService.php:123`, `:185`), so if the employee's branch changed
   between request and apply the "from" is the later value.

Since (2) can never be guaranteed — four of the nine write paths change branch silently —
the honest answer to the Product Owner is: **SmartPRS 2 cannot answer that question.**
The same is true for department, designation, manager, grade (there is no grade concept
at all), salary and status. A back-dated payroll re-run, a statutory return for a past
period, or a "who reported to whom last quarter" query is not answerable from this
schema.

**One further consequence:** the archive snapshot (`employee_archives.snapshot`) is the
*only* point-in-time copy of an employee row that exists anywhere, and it is taken once,
at archive time, for archived employees only (`AppDataController.php:2630-2660`).

---

## Q6 — Every place employee identity is resolved by NAME rather than id/code

| # | Where | Code | Consequence of a duplicate/changed name |
|---|---|---|---|
| 1 | `AppDataController::selfScope()` — resolves the logged-in employee for the whole SPA (directory, payslips, TDS, dashboard) | `:239-242` `where('email', $user->email)->orWhere('name', $user->name)` | **A user sees another employee's payslips and TDS.** The `employee_id` lookup at `:236` is also **not tenant-scoped**. |
| 2 | `EssController::currentEmployee()` — the whole ESS surface incl. self-edit | `EssController.php:54-58` (same predicate) | same, plus `updateProfile()` writes to the wrong row |
| 3 | Ten further copies of the same "own employee" resolver | `TodayController.php:87`, `TestController.php:74`, `MasterController.php:604`, `LetterController.php:464`, `:502`, `RequestController.php:48`, `AttendanceCorrectionController.php:179`, `IncrementController.php:204`, `PayrollGenController.php:1516`, `:1953`, `AttendanceReportController.php:275`, `:352`, `LeaveController.php:79`, `SchemeController.php:161`, `CodeOfConductController.php:81`, `:162` | 16 independent copies of a name-fallback identity resolution |
| 4 | **`RecruitmentController::createEmployeeFromCandidate()`** — hire idempotency | `:594-596` `where('name', $c->name)` | **Hiring a second person with the same name creates NO employee** and reports the first one's code as the hire (NEW-EMP-077) |
| 5 | `RecruitmentController::createOffrollFromCandidate()` | `:668` | same, for agents |
| 6 | `RecruitmentController::ensureOnboarding()` | `:755` `where('employee', $c->name)` | wrong onboarding record |
| 7 | **`TransferService::startTransferOnboarding()`** — transfer-onboarding idempotency | `TransferService.php:91` `where('employee', $emp->name)` | a namesake's open onboarding suppresses the transferee's destination checklist |
| 8 | **`OnboardingController::board()`** — "heals" a dead employee link **and persists it** | `:115-124` `$byName[strtolower(trim($rec->employee))]` | permanently re-points an onboarding record at a namesake (NEW-EMP-082) |
| 9 | `PointsController.php:190` | `where('employee', $e->name)` | points credited to a namesake |
| 10 | **`MasterController` `emp_map`** — the generic employee picker on ~20 master screens (dra-certs, bgv, agent-auth, helpdesk, posh, letters, documents, geofence, …) | `MasterController.php:1025-1034` `where('name', $input[$nameField])->orWhere('emp_code', …)` | a DRA certificate, a BGV, a warning letter or a POSH complaint attached to the wrong person; **name is tried first** |
| 11 | `MasterController::buildRow()` manual-attendance picker | `:983-993` `where('name', $pick)->orWhere('emp_code', $pick)` | punch filed against a namesake |
| 12 | **`SelfOnboardingController::hrInviteExisting()`** | `:647` `orWhere('name', 'like', '%'.$key.'%')`, `orderBy('id')` first hit | the onboarding link, and the eventual data injection, binds to the wrong employee |
| 13 | `SelfOnboardingController::hrEmployees()` search | `:501` | picker only (low risk) |
| 14 | **`SelfOnboardingController::hrBulkUpload()` matcher** | `:1060-1073` `emp_code OR pan OR email OR mobile`, `->first()` with **no ordering** | binds a CSV row to whichever employee matches first, then `injectOne()` overwrites their DOB, gender, PAN, UAN and bank details |
| 15 | `TransferController::row()` access check | `:41-44` case-insensitive email match | a shared/duplicated email grants access to another employee's transfer order |
| 16 | **Org hierarchy stored as names** — `reporting_manager`, `team_leader`, `team`, `department`, `designation`, `branch`, `shift` are all stored as **denormalised name strings** by the form and both importers | `AppDataController.php:2867-2874`; `EmployeeImportService.php:483-493` | renaming a master row orphans every employee pointing at it by name; two managers with the same name are indistinguishable; `ProbationService::recipientUserIds()` (`:262`) reads `reporting_manager_id`, which these paths never write, so **the manager is never notified** |
| 17 | Company / salary-schedule / designation / department / branch resolution on import and onboarding | `EmployeeImportService.php:579-581`, `:592-594`; `SelfOnboardingController.php:708-728`, `:768-770` | matched by exact name; a mismatch silently falls back to the tenant's first company or is skipped |
| 18 | `employeeTemplate()` `@employees` dropdown | `AppDataController.php:962-964` | the REPORTING MANAGER / TEAM LEADER columns of the import file are **name pick-lists**, so the import can only ever set names, never ids |

**Summary:** employee identity is resolved by name in **18 distinct places**, including
two idempotency keys (recruitment hire, transfer onboarding) where a name collision
causes a record simply not to be created, and one place (`OnboardingController::board()`)
where a name match is written back to the database as an id.

---

## Q7 — Document types, expiry, verification and alerting

### What exists

| Concern | Implementation | Hard-coded? |
|---|---|---|
| **Document store** | `documents` table — `tenant_id`, `employee_id`, `kind`, `category`, `doc_name`, `status` (def `pending`), `expiry` DATE, `file_path`, `file_name`, `file_size` | table created at **runtime by three different code paths** with **three different definitions** (`DocumentController.php:74-99`, `SelfOnboardingController.php:919-934`, `MasterController::ensureCols`); **no migration** |
| **Categories** | 6 values: Educational Certificate; Personal Document / Certificate; Experience - Relieving / Leaving Letter; Experience - Payslip; Experience - Experience Certificate; Other | **`DocumentController::CATEGORIES` const, `:21-28`** |
| **Onboarding document kinds** | 7 values: `id`, `address`, `education`, `experience`, `bank`, `pcc`, `dra` | **`portal.blade.php:230` JS const**, plus a friendly-name map at `SelfOnboardingController.php:935` — **a second, unrelated vocabulary** |
| **Onboarding `kind` on upload** | `(string) $r->input('kind','other')` — **unvalidated free text** | `SelfOnboardingController.php:324` |
| **Allowed formats** | `pdf, jpg, jpeg, png, doc, docx, xls, xlsx` (tracker); `pdf, jpg, jpeg, png` (onboarding) | **`ALLOWED_EXTS` const `:35`; inline array `SelfOnboardingController.php:320`** |
| **Size caps** | 10 MB/file (tracker `:37`); 5 MB (onboarding doc `:317`); 4 MB / min 500 B (selfie `:294-299`) | **all literals** |
| **Validation depth** | **extension only** — no MIME sniff, no content check, no image decode anywhere | Y |
| **Quota** | per **employee**, `tenants.doc_storage_emp_mb` ?? **100 MB**; null tenant (on-prem) = unlimited | default is a const `:39`; per-tenant override is data |
| **Document `status`** | column exists, default `pending`; tracker uploads always insert `'pending'` (`:241`); onboarding copies always insert `'approved'` (`SelfOnboardingController.php:962`) | **there is NO endpoint to change a document's status** — no approve/reject action exists anywhere |
| **Document `expiry`** | captured on upload (`:242`, per-file via `expiries[]`) | — |
| **Document expiry alerting** | **NONE.** `ComplianceController::scan()` never queries the `documents` table (verified by grep). | — |
| **Retention** | none — `destroy()` and `destroyForEmployee()` **hard-delete** the row and unlink the file (`:296-305`, `:315-328`). No soft delete, no archive, no recycle bin. Employee soft-delete does **not** cascade. | Y |
| **Serving** | tracker: `response()->download()`, always an attachment (`:280`). Onboarding HR view: raw `response()` with a MIME guessed from the extension and **no `Content-Disposition`** (`SelfOnboardingController.php:487-489`) — renders inline on the app's origin. | Y |
| **Access** | index/upload/delete: admin, hr_manager. **download: admin, hr_manager, `employee`** with only a `tenant_id` filter (`:265-271`) — see FINDING-SEC-03. | Y |

### The separate compliance-certificate mechanism (not the document tracker)
| Concern | Implementation |
|---|---|
| Certificate **declaration** | `employees.dra_declared` / `pcc_declared`, values `Yes`/`No`/`NA` — **read by no report at all**; only the browser-side new-hire gate uses them |
| Certificate **status** | `employees.dra_status` / `pcc_status` ∈ `pending`\|`submitted`\|`verified`, gated by `complianceStatus()` (`AppDataController.php:145-149`) — **anything else is silently dropped, not stored** |
| Certificate **expiry** | `employees.dra_expiry`, `pcc_expiry` DATE |
| **`pcc_deadline`** | captured on 3 surfaces, exported, **explicitly SELECTed by `ComplianceController::scan()` (`:57`) and then never passed to `$consider()` (`:102-103`)** — a missed PCC deadline raises no alert whatsoever |
| Richer DRA register | `dra_certs` master (`cert_no`, `institute`, `track`, `issue_date`, `expiry`, `status`), scanned for expiry (`ComplianceController.php:105-124`) |
| BGV | `bgv.next_due` derived as `verified_on + revalidate_months` (`MasterController.php:746-753`), scanned |
| Off-roll agents | `offroll_agents.dra` / `.pcc` are **free-text strings with no dates**, never scanned |
| **Alerting** | `compliance:scan` daily **07:30** (`routes/console.php:143-146`) → `scan(tenant, 30)` → buckets `expired`(<0) / `critical`(≤7) / `soon`(≤30) → email digest of the **top 25** rows to every admin/HR user |
| **Verification** | there is **no verification workflow**. `dra_status`/`pcc_status` are dropdown values HR types on the Employee form; no evidence is linked, no approver is recorded, no audit row is written. The self-onboarding "Mark Verified" button (`hrVerify()` `:570-582`) performs **no checks of any kind** — it is a bare status write. |

### What is hard-coded (the SmartPRS 3 gap)
Document categories (6), onboarding kinds (7), the friendly-name map, allowed extensions
(two different lists), all four size caps, the 100 MB quota default, the 30-day alert
window, the 7-day "critical" threshold, the 25-row digest cap, the 07:30 send time, the
three certificate statuses, the three declaration values, the asymmetry that PCC
`submitted` counts as compliant while DRA requires `verified`
(`ComplianceController.php:572-580`), the fact that only DRA and PCC have dated
certificates at all, and the fact that `documents.expiry` and `pcc_deadline` are captured
and never used. A customer cannot add a document type, make one mandatory, set a
per-type expiry rule, define a renewal reminder cadence, or require an approver.

---

### Defects register (beyond the answers above)

| ID | Defect | Evidence | Business impact | Severity |
|---|---|---|---|---|
| **DEF-01** | Legacy one-shot importer destroys 30 of 56 columns of the app's own export **and NULLs 6 more** | `AppDataController.php:623`, `:690-698`, `:700-720` | A tenant who exports, edits and re-imports through the Directory's Import button loses DOJ, DOB, National ID, address, bank account, all statutory and all compliance fields, and every employee becomes an Office employee | **CRITICAL** |
| **DEF-02** | **The Employee-form save is a full-record replace** — any omitted key NULLs its column; `ctc`→0, `type`→office, `salary_type`→only_salary, `status`→active | `AppDataController.php:2846-2941`, `:2851`, `:2814`, `:2852`, `:2920` | Any partial POST, any API integration, any screen that does not render every tab, silently destroys the record | **CRITICAL** |
| **DEF-03** | **`employee_references` are deleted on every save** and re-inserted from `$e['refs']` | `AppDataController.php:3021`, `:3055-3071` | A save that omits `refs` wipes the reference/verification history with no audit and no undo | **HIGH** |
| **DEF-04** | **Status is rewritten unconditionally on every form save** | `AppDataController.php:2920` | An employee marked `exited`/`resigned`/`terminated` by any other path is silently reset to `active` by the next unrelated edit — and re-consumes a licensed seat | **HIGH** |
| **DEF-05** | **Neither the form nor either importer writes the org-hierarchy FKs**; recruitment writes only FKs and no names | `AppDataController.php:2867-2874`; `EmployeeImportService.php:483-493`; `RecruitmentController.php:626-637`; `SelfOnboardingController.php:707-728` writes both | Three mutually incompatible shapes of the same record. **Probation reminders never reach the reporting manager** of anyone created by the form or an importer (`ProbationService.php:262`). **Recruitment-created employees export with blank DEPARTMENT / DESIGNATION / BRANCH / REPORTING MANAGER** | **HIGH** |
| **DEF-06** | **Import wizard has no seat-limit check**; nor does self-onboarding injection | `EmployeeImportService.php` (no `canAddEmployees` call), `SelfOnboardingController::injectOne()` | Two of the nine creation doors bypass the licence entirely — direct revenue leakage | **HIGH (commercial)** |
| **DEF-07** | **`dup_mode='create'` guarantees a unique-index violation** and rolls the whole import back with a raw SQLSTATE message | `EmployeeImportService.php:372-379`, `:457-463` | A user who picks the third radio button loses the entire import and is shown a SQL error | **HIGH** |
| **DEF-08** | **Wizard employee-code generation has no collision check** (`'EMP-'.random_int(1000,9999)`, no retry) | `EmployeeImportService.php:375-377` vs `AppDataController.php:2818-2821` | Two blank-code rows in one file collide ~1 in 9000 and roll the entire import back | MEDIUM |
| **DEF-09** | **Bulk self-onboarding bypasses the verification gate entirely** | `SelfOnboardingController.php:1077`, `:1094-1100` vs `:596-598` | Employees created with no OTP, no selfie, no documents, no HR review and **no format validation at all** (the bulk path never calls `formatErrors()`) | **HIGH** |
| **DEF-10** | **The bulk matcher `emp_code OR pan OR email OR mobile` with `->first()` and no ordering** can bind a row to the wrong employee, then overwrite their DOB, gender, PAN, UAN and bank details | `SelfOnboardingController.php:1060-1073`, `:855` | Silent identity corruption of an existing employee | **HIGH** |
| **DEF-11** | **Three of the nine write paths skip `stripHtmlDeep()`** — the import wizard (`:247` only trims), the onboarding portal (`:279-282`), and ESS (`:284-289`) | as listed vs `AppDataController.php:2739` | Stored XSS reaches `employees` free-text columns, which the SPA renders via `innerHTML` "with inconsistent escaping" by the codebase's own admission (`AppDataController.php:2736-2740`) | MEDIUM |
| **DEF-12** | **ESS self-edit runs no format validation, no uniqueness check and no HTML strip** | `EssController.php:263-292` | An employee can set their email to a colleague's (email is the ESS login id **and** one of the 8 unique identifiers), or store a malformed mobile the Directory rejects | **HIGH** |
| **DEF-13** | **`SubscriptionService::seatUsage()` ignores `archived_at`**, while the error message it produces says "moving an employee to Inactive or **Old-data** frees a seat" | `SubscriptionService.php:87-99` vs `:141` | Customers archive employees to free seats and it does not work; the product tells them it does | MEDIUM |
| **DEF-14** | **Archiving runs on a page load, not a schedule** — up to 50 per `/app/data` request, under whichever user happens to load the app; whole sweep in a bare `catch (\Throwable) {}` | `AppDataController.php:257`, `:2609-2628` | If nobody opens the app, nothing archives; a failed snapshot silently leaves the employee live with no signal; 200 due employees need four page loads; `archiveEmployeeNow()` issues 10 queries × `limit(2000)` per employee inside a GET | MEDIUM |
| **DEF-15** | **The rename cascade omits `employee_archives`**, which is keyed by `emp_code` | `AppDataController.php:3025` vs `2026_07_15_000100_add_employee_archive.php:34` | After a rename, `employeeBackupFile()` (`:2662-2696`) finds nothing and **silently generates a fresh snapshot** instead of serving the archived one — the bug is invisible | MEDIUM |
| **DEF-16** | **No transition guards at all.** `setEmployeeStatus()` can reactivate an archived employee (no archive filter, `:2705-2709`); `bulkDeleteEmployees()` can soft-delete an already-archived one | `:2698-2716`, `:877-903` | Rows with both `archived_at` and `deleted_at` set, labelled `Deleted` in the Old-data tab | MEDIUM |
| **DEF-17** | **Restore exists only as a side effect of re-import** | `EmployeeImportService.php:411-416` vs the docblock "View-only afterwards (no in-app restore)" `AppDataController.php:2499` | Accidental archives/deletions are unrecoverable through the UI; the only recovery is an import that also overwrites the record | MEDIUM |
| **DEF-18** | **`pcc_deadline` is captured on 3 surfaces, exported, SELECTed by the scan — and never used** | `ComplianceController.php:57` vs `:102-103` | An employee who has missed their PCC deadline but has no PCC expiry date raises no alert at all | MEDIUM |
| **DEF-19** | **Dead columns shipped on every install:** `aadhaar` and `employment_type` (created by `2026_07_22_000002:9` and `SelfOnboardingController::ensureEmployeeCols()` `:1121` although the parity migration exists to move data *out* of them); `docs_stored`; `docs_status`; `dra_pcc_declared` (written, never created); `self_onboarding.pin_hash`; `employee_company` | as listed | Invites the same wrong-column bug again; confuses every integrator | LOW |
| **DEF-20** | **Three `self_onboarding.status` values are declared and never written:** `approved`, `hold`, `withdrawn` | `2026_07_22_000001:44` vs `SelfOnboardingController.php:893` | The documented state machine is not the implemented one | LOW |
| **DEF-21** | **Export emits `dra_expiry`, `pcc_deadline`, `pcc_expiry` as `Y-m-d` under a `(DD-MM-YYYY)` header** — only `__doj`/`__dob` are reformatted | `EmployeeExportController.php:174-178` | The file contradicts itself; still round-trips | LOW |
| **DEF-22** | **The alias `pf` resolves to UAN, not PF Applicable** | `EmployeeFieldRules.php:185` | A column headed exactly `PF` never reaches `pf_applicable` | LOW |
| **DEF-23** | **`EmployeeFieldRules::hrFields()` is dead code**; the HR-fields form is hand-maintained in **three** places that must agree | `EmployeeFieldRules.php:366-383` (no callers); `hr-console.blade.php:109-121`, `:137`, `SelfOnboardingController.php:526-530` | Drift is guaranteed; already visible — the console carries `pf_uan` (→ `employees.uan`), which is **not** an `hr` registry field, so **the HR value silently overwrites the candidate's own UAN** (`:779-781`) | MEDIUM |
| **DEF-24** | **`portalMap()` and `formToColumn()` are used for validation only**; the actual write mappings are hand-coded copies | `EmployeeFieldRules.php:395-418` vs `SelfOnboardingController.php:827-834` and `AppDataController.php:2846-2941` | A new registry field appears on four surfaces automatically and is **still not saved** by two of them | **HIGH** (this is the core of Q2) |
| **DEF-25** | **The importer accepts values no dropdown offers.** No value-set check on `gender`, `marital_status`, `blood_group`, `category`, `pt_state`, `also_works_for`, `department`, `designation`, `branch`, `team`, `shift`, `reporting_manager`, `team_leader`, and `status` is `strtolower(trim())` with no whitelist | `EmployeeImportService.php:483-497`, `:556-558` | A CSV can set `status='onleave'`; the form's `<select>` then shows blank and the next save overwrites it | MEDIUM |
| **DEF-26** | **`ProbationController::confirm()`/`extend()` silently no-op for a super admin** — `$tid = (int)($user->tenant_id ?? 0)` then `where('tenant_id', 0)` | `ProbationController.php:79`, `:105`; `ProbationService.php:285`, `:329` | `{"ok":false}` with no explanation | MEDIUM |
| **DEF-27** | **Four consumers ignore `archived_at`:** seat billing (`SubscriptionService.php:87`), compliance scan (`ComplianceController.php:55`), probation board (`ProbationService.php:142-145`), document-manager employee picker (`DocumentController.php:116-119`) | as listed | Archived employees keep generating DRA/PCC alerts in the daily HR digest and keep appearing on the probation board | MEDIUM |
| **DEF-28** | **Probation reminders never catch up.** Milestones fire on an **exact** integer match of `days_left`; a scheduler outage on one day silently loses that day's milestone for everyone | `ProbationService.php:186-197` | Confirmations missed; statutory deductions stay suspended | MEDIUM |
| **DEF-29** | **No auto-apply for increments.** An approved, letter-sent increment can sit unapplied indefinitely | `IncrementController.php:314-347`; docblock `:310-313` says this is deliberate | The employee holds a signed letter promising a raise that payroll is not paying | MEDIUM (**PO decision**) |
| **DEF-30** | **`applyCtc()` writes `employees.designation` but never `designation_id`** | `IncrementController.php:339-341` | Compounds DEF-05 | LOW |
| **DEF-31** | **Transfer `to_company_id` is used with no tenant check**; only the name-resolution branch is tenant-scoped | `TransferService.php:131-139` | A transfer row carrying another tenant's company id moves the employee across tenants | MEDIUM |
| **DEF-32** | **The compliance scan selects `agent_code`, `portfolio` — columns no migration creates**, and reads `photo_path`, created lazily by `LetterController` | `ComplianceController.php:56-57`; `LetterController.php:338-342` | The "is field agent" concept is inert; compliance rows carry blank agent code/portfolio | LOW |
| **DEF-33** | **Two migration files do not create what their names say.** `2026_01_01_000200_create_departments_table.php` creates `devices`, `geofence_rules`, `attendance`, `late_policy`, `leave_types`, `leaves`, `holidays`; `departments` is created inside `2026_01_01_000100_create_employees_table.php:54-62` | as listed | Nobody can find a table by its migration | LOW |
| **DEF-34** | **Dead Eloquent layer.** `EmployeeController`, `resources/views/employees/*` call `route('employees.index')`, which does not exist — `routes/web.php:692-694` redirects `/employees` to `/app`. `Employee::$fillable` names **8 columns that do not exist** (`employee_code`, `first_name`, `last_name`, `phone`, `position`, `date_of_joining`, `employment_type`, `gross_salary`) against the real `emp_code`, `name`, `mobile`, `designation_id`, `doj`, `ctc` | `app/Models/Employee.php:26-40`; `EmployeeController.php:16`, `:90-108`; `employees/index.blade.php:99`, `form.blade.php:7` | Any code that instantiates the model against the live schema fails. Note: the reference doc cites `Employee.php:332-346` — the file is **72 lines long** | LOW (but blocks any future Eloquent work) |
| **DEF-35** | **God classes.** `AppController.php` = **1 208 879 bytes** (verified) with the entire SPA boot JavaScript as PHP heredocs; `AppDataController.php` = 163 460 bytes / 3 090 lines carrying bootstrap + employee CRUD + archive + both templates + the legacy importer | `ls -la` | Employee logic spans six files, four of which hold an independent write mapping | HIGH (maintainability) |
| **DEF-36** | **Staged import files are never deleted.** `storage/app/imports/employees/<uuid>.<ext>`, containing plaintext `DEFAULT PASSWORD` values plus PAN, national IDs, bank accounts and IFSC for the whole workforce | `EmployeeImportService.php:35`, `:126-127`, `:700-710`; no prune command in `routes/console.php` | One filesystem read or one backup leak exposes still-valid ESS credentials | **HIGH** |
| **DEF-37** | **Duplicate-check failures fail open.** `duplicateErrors()` swallows every `Throwable` and returns no error — "a failed lookup must never block a save outright" | `EmployeeFieldRules.php:606-608` | A DB blip silently disables the only uniqueness guarantee on 8 identifiers (there are **no unique indexes** backing them) | MEDIUM |
| **DEF-38** | **Duplicate check is a racy check-then-write** — one query per unique column per row, on `LOWER(TRIM(col))` which cannot use the plain indexes added for it, outside any transaction | `EmployeeFieldRules.php:582-609` | 8 queries × N rows on an import preview; concurrent saves can both pass | MEDIUM |
| **DEF-39** | **Test coverage is essentially zero.** Only `EmployeeImportServiceTest` (4 tests, no DB) and `ProbationServiceTest` (6 tests, no DB) touch this area. **Untested:** `storeEmployee()`, preview/commit, export, `duplicateErrors()`, `formatErrors()`, the whole self-onboarding flow, archive/backup/restore, transfers, increments, and **every permission gate** | `tests/Unit/*` | No regression safety net for the primary write path of the product | **HIGH** |
| **DEF-40** | **~48 swallowed `catch (\Throwable) {}` blocks** in this subsystem alone, several guarding security decisions | `EmployeeFieldRules.php:606`; `ApprovalService.php:377`; `AppDataController.php:47,110,585,865,948,2637,2657`; `EmployeeImportService.php:151,204,586,654,676,693,732,817`; `SelfOnboardingController.php:129,380,564,889,902,969,1127`; `TransferService.php:66,106,212`; `ProbationService.php:57,118,164,256,270,313,335,352,365,375,384`; `EmployeeExportController.php:110,130,143`; `DocumentController.php:66,300,322` | A failed archive silently leaves the employee live; a failed invite email silently leaves a live link nobody knows about; a failed role check silently **permits** | MEDIUM |

### Hard-coded magic values (the configuration backlog, with locations)
| Value | Meaning | Location |
|---|---|---|
| 3 days | backup grace period | `AppDataController.php:2531` |
| 50 | archives per `/app/data` request | `:2621` |
| 2000 | linked rows per table in a snapshot | `:2449`, `:2457` |
| 1000 | Old-data list cap | `:545` |
| 500 | bulk-delete cap | `:884` |
| 1000–9999 | generated employee-code range | `:2818`, `:682`, `EmployeeImportService.php:376` |
| 4 digits | onboarding/recruitment code padding | `SelfOnboardingController.php:878`, `RecruitmentController.php:620` |
| 14 days | onboarding link lifetime | `SelfOnboardingController.php:107` |
| 10 minutes | OTP lifetime | `:191` |
| 5 | OTP attempts per row | `:223` |
| 6 digits | OTP length | `:190` |
| 500 B / 4 MB | selfie min / max | `:294`, `:297` |
| 5 MB | onboarding document cap | `:317` |
| 15 | employee search results | `:502` |
| 8 | onboarding steps in the progress divisor | `:364` |
| 200 / 500 / 50 | import errors returned / stored / jobs listed | `EmployeeImportService.php:389`, `:447`, `:668` |
| 6 | minimum default password | `:81` |
| 30 / 7 / 25 | compliance window / critical threshold / digest rows | `ComplianceController.php:31`, `:79`, `:206` |
| 10 MB / 100 MB / 200 | document file cap / per-employee quota / back-fill batch | `DocumentController.php:37`, `:39`, `:62` |
| 6 | default probation months | `ProbationService.php:35` |
| [30,15,7,0] | reminder milestones | `:36` (configurable) |
| /12 | annual→monthly divisor for arrears | `IncrementController.php:366` |
| 1 | arrears minimum rupee floor | `:377` |
| 06:30 / 07:15 / 07:30 | transfers / probation / compliance schedule | `routes/console.php:191`, `254`, `144` |
| 36 | PT states | `EmployeeFieldRules.php:246-252` |

---

## 7. Security findings

| ID | Finding | Evidence | Exploitability | Business impact | Severity |
|---|---|---|---|---|---|
| **SEC-01** | **The employee import template leaks the entire staff roster to any logged-in user.** `employeeTemplate()` has **no role check** (confirmed: `AppDataController.php:909` begins with `$tenantId = optional($request->user())->tenant_id;` and no `denyUnlessRole`). Its hidden "Lists" sheet carries the tenant's full company, department, designation, branch, team, shift and salary-schedule lists **plus `'@employees' => $names('employees')` — every employee name in the tenant** (`:962-964`) | `:909-1024`; route `routes/web.php:305` | trivial — one authenticated GET | Any employee, contractor or compromised low-privilege account downloads the complete staff roster and org structure | **HIGH** |
| **SEC-02** | **Import history and error reports are unguarded.** `history()` and `errorReport()` are the only two methods in `EmployeeImportController` that do **not** call `FeaturePermissions::guard`. `errorReport()` streams messages that embed PII by construction: `PAN "ABCDE1234F" is already used by EMP100 (Ravi).` (`EmployeeFieldRules.php:603-604`), plus verbatim malformed UAN, bank-account, Biometric ID and email values from the file | `EmployeeImportController.php:93-123`; routes `web.php:595-596` | IDOR by incrementing `{id}`; only `tenant_id` is checked, and only when the caller has one | Any authenticated user harvests colleagues' PAN, UAN, bank accounts and emails | **HIGH** |
| **SEC-03** | **IDOR — any `employee`-role user can download any employee's documents.** `download()` allows role `employee` and then filters only on `tenant_id`; there is **no check that `documents.employee_id` is the caller's own** | `DocumentController.php:263-281`; route `web.php:546` | walk `/app/documents-mgr/{1..N}/download` | Every colleague's ID proof, PAN card, bank proof, education and experience certificates — including files copied in from self-onboarding | **HIGH** |
| **SEC-04** | **No rate limiting on any of the 8 public self-onboarding endpoints.** `routes/web.php:67-74` carries no `throttle`; there is no in-controller limiter. `otpVerify()` caps attempts at 5 **per OTP row** but `otpSend()` is unlimited and `otpVerify()` always reads the **latest** row (`:219`) — so a token-holder gets unlimited 5-guess batches against a 6-digit space. `/save`, `/document` and `/selfie` are likewise unbounded | `routes/web.php:66-74`; `SelfOnboardingController.php:176-243`, `:287-328` | high | OTP brute force; unbounded disk write (5 MB × unlimited documents); unbounded mail/WhatsApp spend | **HIGH** |
| **SEC-05** | **One-time codes are returned in the API response whenever `APP_DEBUG` is true.** `if (app()->environment('local') \|\| config('app.debug')) { $out['dev_code'] = $code; }` — the second condition is **not** environment-bound, and the portal displays it (`portal.blade.php:344`) | `SelfOnboardingController.php:201-203` | any production install left in debug | Complete bypass of email/WhatsApp ownership verification | **HIGH** |
| **SEC-06** | **Bulk self-onboarding bypasses the verification and approval gate.** `hrBulkUpload()` forces `status='submitted', progress=100` (`:1077`); `hrBulkCommit()` calls `injectOne()` on every non-injected bulk record with **no status check** (`:1094-1100`), while `hrApprove()` explicitly refuses anything not `verified` (`:596-598`). The bulk path never calls `formatErrors()` | as listed | any admin/HR | Employees created with no verified contact, no selfie, no documents, no HR review, no field validation; the OR-matcher can bind a row to the **wrong** employee and overwrite their identity and bank details | **HIGH** |
| **SEC-07** | **The authorisation helper fails OPEN.** `ApprovalService::denyUnlessRole()` wraps the role check in `try { … } catch (\Throwable $e) { return null; }` — `null` means **allowed** — with the comment "fail open — availability over strictness on pivot errors" | `ApprovalService.php:365-380` | any exception in `hasRole()`: missing Spatie pivot, cache miss, DB blip | Grants access to every endpoint in the employee subsystem that relies on this helper — which is most of it | **HIGH** |
| **SEC-08** | **Probation read endpoints are unguarded.** `board()`, `config()` and `history()` call no guard; only `saveConfig()` (`probation.config`), `confirm()` and `extend()` (`probation.manage`) do | `ProbationController.php:21-47`, `:116-125`; routes `web.php:560-565` | any authenticated user | Enumerate every probationer with name, DOJ, computed end date and overdue flag, and read per-employee probation history | MEDIUM |
| **SEC-09** | **Cross-tenant access for a super admin throughout the subsystem.** Every scope is `->when($tid, fn($q) => $q->where('tenant_id', $tid))`; a `super_admin` has `tenant_id = null`, so the filter is **skipped entirely**: read/approve any tenant's onboarding (`SelfOnboardingController.php:413-418`), export every tenant in one file (`EmployeeExportController.php:95`, `:148`), duplicate check searches globally (`EmployeeFieldRules.php:590-592`). Worse, `storeEmployee()` falls back to `DB::table('tenants')->value('id')` (`AppDataController.php:2724`), so **a super admin editing an employee writes them against tenant #1** | as listed | inherent | Cross-customer data path in a multi-tenant SaaS; the last item is a data-corruption path | **HIGH** |
| **SEC-10** | **Plaintext first-time passwords and full workforce PII persist indefinitely in staged import files.** Nothing deletes `storage/app/imports/employees/<uuid>.<ext>` — not `commit()`, not a scheduled prune | `EmployeeImportService.php:35`, `:126-127`, `:700-710` | filesystem/backup access | Credentials that are still valid (the import sets them as the ESS password) | **HIGH** |
| **SEC-11** | **Unauthenticated, non-expiring token pages.** `/agent/verify/{token}`, `/agent/earnings/{token}`, `/transfer/accept/{token}` — public, unthrottled, tokens never rotated and never expired; `verifyEmail()` does not clear `email_verify_token` after use | `routes/web.php:110-120`; `TransferController.php:179-208`; `OffrollAgentController.php:316-361`, `:541-556` | any leaked URL, forever | Discloses agent name, agency, MTD net pay and every entry | MEDIUM |
| **SEC-12** | **Stored-XSS gap in three write paths.** `storeEmployee()`, `importEmployees()` and `MasterController::save()` call `stripHtmlDeep()` precisely because "employee names and free-text fields are rendered into the SPA via innerHTML in many places with inconsistent escaping" (`AppDataController.php:2736-2740`). `EmployeeImportService::process()` only `trim()`s (`:247`), `SelfOnboardingController::save()` writes the posted array verbatim (`:279-282`), and `EssController::updateProfile()` writes it verbatim (`:284-289`) | as listed | authenticated or token-holder | Payload dormant until the employee reaches an SPA screen using `innerHTML` | MEDIUM |
| **SEC-13** | **Unvalidated image upload stored and served as an image.** `selfie()` accepts any base64 blob between 500 B and 4 MB, writes it as `selfie.jpg`; `selfieImg()` serves it **publicly** with a forced `Content-Type: image/jpeg` and **does not apply `gate()`** — no expiry check, no `link_disabled_at` check | `SelfOnboardingController.php:287-305`, `:396-404` | any URL holder, forever | The candidate photo remains retrievable permanently by anyone who ever held the link | MEDIUM |
| **SEC-14** | **Uploaded documents served inline with no `Content-Disposition`.** `hrDoc()` streams with a MIME guessed from the extension | `SelfOnboardingController.php:487-489` | crafted PDF | Script execution on the app's own origin in most PDF viewers | LOW |
| **SEC-15** | **Predictable storage paths on the `public` disk for onboarding documents:** `employee-docs/<numeric employee id>/<basename>`, whereas `DocumentController` uses a Laravel-random filename | `SelfOnboardingController.php:954-955` vs `DocumentController.php:234` | if `storage:link` is present | Direct unauthenticated enumeration over HTTP | LOW |
| **SEC-16** | **Raw SQL error text returned to the browser.** `'Import failed and was rolled back: '.$e->getMessage()` plus four `catch` blocks returning `$e->getMessage()` verbatim | `EmployeeImportService.php:462`; `EmployeeImportController.php:38`, `:59`, `:89`, `:101` | any importer user | Leaks table names, column names, SQLSTATE codes | LOW |
| **SEC-17** | **Sensitive identifiers in cleartext archives, downloadable.** `employee_archives.snapshot` LONGTEXT serialises the whole employee row (PAN, national ID, UAN, ESIC, bank account, IFSC, CTC) plus 2000 rows of each linked table; `GET /app/employees/{code}/backup-file` streams it as JSON. `self_onboarding.data` holds the same identifiers and is **never purged after injection** | `AppDataController.php:2630-2660`, `:2662-2696` | admin/HR | Whole-workforce PII in one file | LOW |
| **SEC-18** | **`OnboardingController::toggle()` has no tenant scope** — `where('id', $itemId)` only | `OnboardingController.php:180`, `:184`, `:191` | any admin/HR of any tenant | Cross-tenant checklist manipulation | LOW |
| **SEC-19** | **Export leaks cross-tenant company and salary-schedule names** — both maps are plucked with no tenant filter | `EmployeeExportController.php:129`, `:138` | super_admin / id collision | Name disclosure | LOW |
| **SEC-20** | **Raw column interpolation in the duplicate check** — `whereRaw('LOWER(TRIM('.$col.')) = ?', …)`. `$col` derives from the hard-coded `FIELDS` today, so it is not attacker-controlled, but the pattern invites injection the moment `uniqueFields()` becomes data-driven — **which is exactly what SmartPRS 3's metadata engine will do** | `EmployeeFieldRules.php:588-590` | none today | Future SQL injection | INFORMATIONAL (**but blocking for the rebuild**) |
| **SEC-21** | **Onboarding token leaks via `Referer`.** The token is the sole credential and lives in the URL; the portal loads Google Fonts from `fonts.googleapis.com`, so the token-bearing URL is sent to a third party on every page load. The HR console renders the raw link as a clickable anchor, and the invite JSON response returns it | `portal.blade.php:7-8`, `:212`; `hr-console.blade.php:156`, `:162`; `SelfOnboardingController.php:631`, `:661` | passive | Full account takeover of the onboarding record | MEDIUM |
| **SEC-22** | **`selfScope()` resolves the caller's employee row by NAME as a fallback, and the `employee_id` branch is not tenant-scoped** | `AppDataController.php:236`, `:239-242` | namesake employee | A user is served another employee's payslips, TDS and directory record | **HIGH** |
| **SEC-23** | **No role middleware on `/app/*`.** The group is `['auth', LicenseGate, EnsureSubscriptionActive, DemoWriteGuard, EditionGuard]`; authorisation is entirely a per-method concern, so any method that forgets `denyUnlessRole()`/`FeaturePermissions::guard()` is reachable by any authenticated user — which is how SEC-01, SEC-02 and SEC-08 happened | `routes/web.php` `/app` group | — | Systemic | **HIGH (architectural)** |

---

## 8. Documentation-vs-source discrepancies

| # | What the reference doc says | What the source says | Impact |
|---|---|---|---|
| 1 | `app/Models/Employee.php:332-346` for `$fillable` | The file is **72 lines**; `$fillable` is at `:26-40` | Line references in §0 of the doc are not usable |
| 2 | `storeEmployee()` status rewrite at `AppDataController.php:2963`; seat check `:3060-3064`; refs delete `:3046`; cascade `:3040-3055`; `$payload` `:2843-2972`; duplicate check `:3031` | Verified: `:2920`, `:3042`, `:3021`, `:3025-3037`, `:2846-2941`, `:3013` | **Every `AppDataController` line number in §05 is 20–45 lines high.** The doc was written against a different revision. All line numbers in the present document were re-verified against `/home/claude/prs2/` |
| 3 | "6 columns mis-normalised" by the legacy importer | **7** — `DEFAULT PASSWORD` normalises to `default_password` while the lookup is `$row['password']` (`AppDataController.php:822`) | Understates the loss |
| 4 | The legacy importer "silently drops" columns | It **also NULLs** `mobile`, `email`, `pan`, `uan`, `bank_acc`, `ifsc` and resets `ctc`/`type`/`salary_type` on update, because `$payload` is a fixed literal (`:690-698`) | Understates the severity from data-loss-on-read to **data-destruction-on-write** |
| 5 | "Five doors" write the employee record | **Nine.** The doc's field-parity analysis omits `EssController::updateProfile()` (10 fields, no validation, no uniqueness, no HTML strip), `BiometricConfigController` (steals `device_user_id` from another employee), and `LetterController::uploadPhoto()` | The parity matrix in the doc is incomplete for the two surfaces that matter most for an employee-facing rebuild |
| 6 | "23 HR keys" in the self-onboarding HR fields | The server accepts **25** (`SelfOnboardingController.php:526-530`) — 23 canonical plus the legacy `work_type` and `employment_type`; the console posts 23 (`hr-console.blade.php:137`) | Minor |
| 7 | OQ-03: "unclear whether the one-shot importer is still reachable from the UI" | **It is.** `cfg.empImportUrl` is wired to the Directory topbar Import button (`AppController.php:12347`) **and** to `window.importCsv('emp-list')` (`:12420`) | Resolves an open question — and it is the highest-severity defect in the area |
| 8 | OQ-04: "unclear whether `probation_months` is settable per employee from any UI" | **It is settable from nowhere.** `grep -rn "probation_months" app/ resources/ routes/` returns only `ProbationService` and the migration. The feature is unreachable but unit-tested | Resolves an open question |
| 9 | OQ-07: "unclear whether any payroll or reporting logic acts on `also_works_for`" | **Nothing reads it.** Only `bootstrap()` echoes it back to the form | Resolves an open question |
| 10 | §14.3 "`AppController.php` 1.15 MB" | Verified exactly: **1 208 879 bytes** | Confirmed |
| 11 | §12 BR-EMP-005 says the duplicate check excludes archived rows | Confirmed (`EmployeeFieldRules.php:596-598`) — **but** the exclusion means a duplicate identifier *can* be created against an archived person, and re-importing that archived person (which un-archives them, BR-IMP-006) then produces two live employees sharing a PAN with no error | The doc records the exclusion but not the consequence |
| 12 | §12 BR-EMP-013 claims the salary-type round-trip bug "is closed" | Closed only for files the product produced. `SALARY_TYPE_IN` has 6 keys; `"Comm."`, `"Commission only"`, `"Sal+Comm"` still silently become `only_salary` (`EmployeeFieldRules.php:66-73`) | Overstates the fix |
| 13 | The registry docblock: "there is no longer a way to add [a field] to only some of [the five surfaces]" (`EmployeeFieldRules.php:26-27`) | **False.** Four surfaces derive; the `storeEmployee()` payload, `bootstrap()`'s read-back, `EmployeeImportService::payload()`, `injectOne()`, `hrSetFields()`, `hrPatch()`, both blade files, `EssController` and the legacy importer are all hand-maintained | This is the single most misleading claim in the codebase, and it is what makes Q2's answer "6–16 places" rather than "3" |
| 14 | The registry docblock's "ADDING A FIELD — the whole procedure" is 3 steps (`:30-35`) | Minimum **6** sites, full parity **13–16** | Understates by 4–5× |
| 15 | `AppController.php:1756` UI text: *"PAN and DRA Certificate are mandatory. Missing PCC within the deadline triggers an automatic ₹1,000 deduction."* | **Neither rule exists.** Only `name` is required by any validator; no code anywhere creates a deduction from `pcc_deadline` | The product tells users a compliance control exists that does not |
| 16 | `SubscriptionService.php:141` message: *"moving an employee to Inactive or Old-data frees a seat"* | Only Inactive frees a seat; `seatUsage()` ignores `archived_at` (`:87-99`) | The product tells users a licensing behaviour that is false |
| 17 | `2026_08_27_000100_widen_employee_dra_pcc_status.php:28-31` comment: "Compliance Alerts already derives 'overdue' from PCC Deadline / PCC Expiry" | Only `pcc_expiry` is used; `pcc_deadline` is SELECTed and discarded | Misleads the next maintainer |
| 18 | `AppDataController.php:2499` docblock: *"View-only afterwards (no in-app restore)"* | Re-importing the `emp_code` through the wizard silently un-deletes **and** un-archives (`EmployeeImportService.php:411-416`) | The documented guarantee is not the implemented one |
| 19 | `EmployeeImportService.php:16-18` docblock: "the one-shot CSV importer stays exactly as it is" (a deliberate freeze) | It is still on two Directory buttons next to the template it destroys | A deliberate decision whose consequence was not traced |
| 20 | Doc §14.4 lists ~30 runtime-added `employees` columns | Verified **34** in `ensureEmployeeColumns()` alone, plus 11 in `ensureEmployeeCols()`, 4 in `ProbationService`, 1 in `LetterController`, 4 conditionally in `importEmployees()` | Slight understatement |

---

## 9. Migration verdict per capability

| Capability | Verdict | Rationale | Data migration implication |
|---|---|---|---|
| **Employee field registry (`EmployeeFieldRules`)** | **REPLACE** | A PHP `const` cannot serve a configuration-first product. The 56 rows become **seed data** for SmartPRS 3's field-definition metadata; the *concept* (one declared list) is right, the *implementation* is the core blocker | Seed 56 system field definitions + their value sets; preserve `h`/`c`/`l`/`tab`/`o`/`a`/`u`/`v` as metadata attributes |
| **Employee master CRUD** | **REDESIGN** | Must become a metadata-driven PATCH-semantics service with field-level audit. The current full-replace payload (DEF-02) cannot be carried forward | Straight column copy of `employees`; **audit history starts fresh — there is none to migrate** |
| **Custom fields** | **NEW BUILD** | Zero capability exists today | Nothing to migrate |
| **`employees` schema** | **MIGRATE with normalisation** | Data is sound; the 34 runtime-`string` columns must be re-typed (dates as DATE, booleans as BOOLEAN, enums as FK-to-value-set) | Type-cast pass; reconcile `permanent_address` 255-vs-500; drop `aadhaar`, `employment_type`, `docs_stored`, `docs_status`, `dra_pcc_declared`, `employee_company` after verifying they are empty |
| **Denormalised name columns + unwritten FKs** | **REDESIGN** | Names-only, FKs-only and both-together records coexist. SmartPRS 3 must be FK-only with names resolved on read | **Reconciliation pass required:** for each of department/designation/branch/team/reporting_manager, resolve the name to an id per tenant, report unmatched names for HR to fix. This is the largest single migration task in this area |
| **Employee status / state machine** | **REPLACE** | Binary Active/Inactive rewritten on every save cannot support exit, notice, suspension or LOA. Needs a configurable status set + a transition table + guards | Map `active`/`inactive`; the four orphan seat-free statuses (`exited`, `resigned`, `terminated`, `left`, `absconded`) exist only in `SEAT_FREE_STATUSES` and can only have arrived by import or SQL — audit before mapping |
| **Effective-dated attribute history** | **NEW BUILD** | Does not exist (Q5) | **No history to migrate.** Seed each employee with one "as at migration date" row. Optionally back-fill branch/department/company from `transfers` and CTC from `increments`, flagged as partial |
| **Archive / backup ("Old data")** | **REDESIGN** | Concept retained (grace period + snapshot); implementation replaced — must be a scheduled job, must free a seat, must be restorable | Migrate `employee_archives` rows as-is; **encrypt the snapshot** |
| **Restore** | **NEW BUILD** | Exists only as an import side effect | — |
| **Soft delete** | **RETAIN** | Correct behaviour, needs a single-employee endpoint and a cascade policy | — |
| **Import wizard** | **REDESIGN** | Upload→map→preview→commit is the right shape; must become metadata-driven, streaming, row-capped, seat-checked, with an explicit unmapped-column report and a blank-clears convention | Migrate `employee_import_jobs` for audit; **delete every staged file** |
| **Legacy one-shot importer** | **REMOVE** | Destroys 30 of 56 columns and NULLs 6 more; superseded | Remove the route and both UI buttons before anything else |
| **Self-onboarding bulk CSV importer** | **REMOVE / MERGE** | A fourth vocabulary that bypasses every gate | Fold into the wizard with an "invite instead of inject" mode |
| **Master CSV importer** | **MERGE** | A generic importer already exists in the wizard | — |
| **Export** | **REDESIGN** | Correct today; must become metadata-driven, with column selection, filters, an "include archived" option and consistent date formatting | — |
| **Self-onboarding portal** | **REDESIGN** | Right product idea, wrong implementation: hard-coded steps and fields, no rate limiting, OTP disclosure in debug, no HTML strip, no purge | Migrate in-flight `self_onboarding` records; **purge or encrypt `data` for `injected` records** |
| **Self-onboarding HR console** | **REPLACE** | Three hand-maintained copies of one field list; must render from the same metadata as the Employee form | — |
| **Injection / mapping step** | **REPLACE** | Hand-coded mapping is the second half of the parity problem | — |
| **ESS self-edit** | **REDESIGN** | Must run the same validation, uniqueness and sanitisation as every other writer, and must be a configurable field allow-list with optional HR approval | — |
| **Recruitment → Hire** | **REDESIGN** | Name-based idempotency (NEW-EMP-077) and FK-only writes must go | — |
| **Documents** | **REDESIGN** | Needs a configurable document-type catalogue with per-type expiry rules, mandatory flags, verification workflow and retention policy; needs IDOR fixed and soft delete | Migrate `documents`; **create it as a real migration**; reconcile the two `kind`/`category` vocabularies |
| **DRA / PCC compliance** | **MERGE into the document-type engine** | DRA and PCC are just two document types with expiry; the four parallel representations (declaration, employee status columns, `dra_certs`, agent free text) collapse into one | Migrate `dra_certs`; map `dra_status`/`pcc_status`/expiries into typed document records; **decide what to do with `dra_declared`/`pcc_declared`, which no report reads** |
| **Transfers** | **RETAIN + REDESIGN** | Effective dating and the from→to register are genuinely good and become the model for all effective-dated changes. Must write FKs, tenant-check `to_company_id`, and cease name-based onboarding idempotency | Migrate `transfers`; **create it as a real migration** |
| **Probation** | **RETAIN + REDESIGN** | Config-per-tenant is the right pattern. Must add catch-up reminders, an FK-based manager lookup, a UI for `probation_months`, guards on the read endpoints, and a super-admin fix | Migrate `probation_events` |
| **Increments** | **RETAIN + REDESIGN** | Approval + letter + arrears is sound. Needs configurable auto-apply, a configurable divisor/proration basis, and `designation_id` | Migrate `increments` |
| **Onboarding checklists** | **REPLACE** | Two 6-item PHP constants; must be configurable per company/department/role/stage | Migrate `onboarding`/`onboarding_items`; **create them as real migrations** |
| **Off-roll agents** | **MERGE** | A second, weaker employee model with no code, no uuid, no unique constraint, no statutory data and name-based idempotency. In SmartPRS 3 an off-roll agent is an employee with an engagement type and a different payroll treatment | Merge `offroll_agents` into `employees` with `engagement='off_roll'`; **allocate real codes**; split `bank_details` free text into structured columns |
| **Dead Eloquent layer** (`EmployeeController`, `Employee`, `Department`, `employees/*` views) | **REMOVE** | Unreachable, and models a schema that no longer exists | Delete |
| **Runtime schema creation** (`ensureEmployeeColumns`, `ensureTables`, `ensure`, `ensureCols`, `ensureAuditCols`) | **REMOVE** | The root cause of type drift, silent column drops and 34 untyped columns | Replace with real migrations for `documents`, `onboarding`, `onboarding_items`, `transfers`, `offroll_agents`, `agent_earnings` and every master table |

---

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
