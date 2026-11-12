# AS-IS ANALYSIS — ATTENDANCE

Scope: punch ingest, shift resolution, attendance-day derivation, status derivation,
late arrival, absence detection, corrections/regularisation, attendance reports,
bulk/manual attendance.

All source references are to `/home/claude/prs2/`. All doc references are to
`/home/claude/refs/`. Where the reference documents and the source disagree, the
source is recorded and the discrepancy is listed in §8.

---

## 0. Scope and evidence base

### 0.1 Reference documents read

| Document | Lines read | Content |
|---|---|---|
| `COMPLETE_PROJECT_BUSINESS_AND_TECHNICAL_DOCUMENTATION.md` | 5211–8548 | Section 06 — Attendance, Biometric Devices and Punch Ingest, in full (§0 orientation, §1 data model, §2 the seven ingest paths, §3 device mode, §4 shift resolution, §5 status derivation, §6 late policy, §7 absence, §8 corrections, §9 reports, §10 credentials, §11 BR register, §12 calc register, §13 timezone, §14 security, §15 debt, §16 open questions, appendices A–C) |
| same | 53275–53684 | Section "2. Date and time logic" (2.1 timezone config … 2.12 business rules), incl. "2.6 Working-day and holiday determination — two incompatible definitions in one file" and risks R1–R10 |
| `BUSINESS_RULES_CATALOGUE.md` | 1–60, 136–211, and the BR-ATT-001…050 / 201…207 / 301 / 306–307 / 700–715 rule bodies (lines 931–1620, and the BR-ATT-3xx/7xx block) | every attendance rule in the catalogue |

### 0.2 Source files read completely (assigned)

| File | Lines |
|---|---|
| `app/Services/PunchIngestService.php` | 403 |
| `app/Services/ShiftResolver.php` | 160 |
| `app/Services/LateArrivalService.php` | 299 |
| `app/Services/AbsenceService.php` | 335 |
| `app/Services/AttendanceCorrectionService.php` | 484 |
| `app/Http/Controllers/AttendanceController.php` | 63 |
| `app/Http/Controllers/AttendanceBulkController.php` | 390 |
| `app/Http/Controllers/AttendanceCorrectionController.php` | 182 |
| `app/Http/Controllers/AttendanceReportController.php` | 977 |
| `app/Http/Controllers/AbsenceController.php` | 102 |
| `app/Http/Controllers/TodayController.php` | 150 |
| `app/Models/Attendance.php` | 26 |
| `app/Jobs/NotifyLateArrivals.php` | 55 |
| `app/Console/Commands/AbsenceNotify.php` | 46 |
| `app/Console/Commands/DedupeAttendance.php` | 89 |
| `app/Console/Commands/PurgeDemoAttendance.php` | 38 |
| `database/migrations/2026_01_01_000300_create_attendances_table.php` | 188 |
| `database/migrations/2026_08_16_000200_extend_attendance_logs_for_sbb.php` | 196 |
| `database/migrations/2026_08_16_000300_create_attendance_pending_table.php` | 50 |
| `database/migrations/2026_08_17_000100_repair_sbb_schema.php` | 235 |
| `database/migrations/2026_08_18_000100_attendance_pending_device_code.php` | 120 |
| `database/migrations/2026_07_07_000000_ensure_shifts_table.php` | 97 |
| `database/migrations/2026_07_22_000100_create_late_notifications.php` | 39 |
| `database/migrations/2026_07_26_000200_create_attendance_corrections.php` | 49 |
| `database/migrations/2026_06_03_120000_fix_late_policy_addl_late.php` | 39 |
| `tests/Unit/AbsenceServiceTest.php` | 40 |
| `tests/Unit/AttendanceCorrectionServiceTest.php` | 24 |
| `resources/views/attendance-report-pdf.blade.php` | 81 |

### 0.3 Source files read additionally (unavoidable — the assigned files cannot be understood without them)

| File | Lines | Why |
|---|---|---|
| `app/Services/ETimeOfficeService.php` | 784 (full) | the second, larger ingest/landing path; owns `matchEmployee`, `quarantine`, `promotePending`, `sequenceDirections`, `import` |
| `app/Http/Controllers/PushController.php` | 286 (full) | the `/iclock` ingest path and its date parsing |
| `app/Http/Controllers/PayrollGenController.php` | 30–200, 200–520, 760–815, 1010–1240 | `dayStats`, `attendanceCut`, `tierCut`, `simpleCut`, `presentDays`, `isWeekOff`, `monthMeta`, `holidayDates`, `sandwichDays` |
| `app/Http/Controllers/MasterController.php` | 225–275, 395–410, 460–560, 970–1015 | runtime schema for `late_policy` / `shifts` / `roster`; the Manual Punch master |
| `app/Http/Controllers/DeviceController.php` | 137–239 | the LAN ZKTeco ingest path and its own `attendance_logs` creator |
| `app/Http/Controllers/BiometricConfigController.php` | 596–700 | `mapEmployee` — backfill + quarantine replay |
| `app/Http/Controllers/ApiKeyController.php` | 384–455 | the Pending Punches listing |
| `app/Http/Controllers/PointsController.php` | 55–180, 230–265 | a third present/late/working-day definition |
| `app/Services/SmarteptWebhook.php` | 150–337 | timezone conversion + daily-summary expansion |
| `app/Services/PayrollLock.php` | 1–100 (full) | the correction write-back gate |
| `app/Http/Controllers/AppDataController.php` | 215–250, 2430–2460, 3010–3045 | `selfScope`; emp_code rename cascade over `attendance_logs` |
| `app/Http/Controllers/SettingsController.php` | 30–110 | statutory defaults consumed by attendance (`late_email_enabled`, `weekly_off_day`, `sat_off_mode`, `lop_basis`, `sandwich_rule`) |
| `app/Http/Controllers/ApprovalService.php` | 360–385 | `denyUnlessRole` |
| `app/Services/FeaturePermissions.php` | 40–60 | `attendance.correction.review` |
| `app/Http/Controllers/EssController.php` | 120–145 | ESS present-days |
| `app/Console/Commands/ClearAppState.php` | 1–60 (full) | confirms it does *not* touch attendance |
| `database/migrations/2026_01_01_000200_create_departments_table.php` | 1–120 (full) | the real home of `attendance`, `late_policy`, `holidays`, `geofence_rules`, `devices` |
| `database/migrations/2026_06_24_000000_ensure_runtime_feature_tables.php` | 1–60 | the canonical `attendance_logs` creator |
| `config/app.php`, `.env.example`, `.env.production.example`, `routes/web.php`, `routes/console.php` | greps | timezone default, route table, schedule |

---

## 1. Business capability inventory

### 1.1 Punch capture (seven channels into one store)

A punch — "person X was at reader Y at instant Z" — can arrive seven ways, and every one
lands as one row in `attendance_logs`. Nothing else is the source of truth.

| Channel | Entry point | `source` written | Human/machine |
|---|---|---|---|
| ZKTeco/eSSL ADMS push | `PushController::cdata` → `ETimeOfficeService::import` (`PushController.php:106-140`) | `push` | machine, unauthenticated |
| Smart Biometric Bridge (SBB) JSON | `PunchIngestService::ingest` (`:61-212`) | `sbb` | machine, API key |
| SmartEPT HMAC webhook | `SmarteptWebhook` → `PunchIngestService::ingest` | `smartept` | machine, HMAC |
| Vendor pull (eTimeOffice / eTimeTrackLite / generic) | `ETimeOfficeService::import` (`:689-783`) | `etimeoffice` / `etimetracklite` / `generic` | machine, scheduled |
| LAN ZKTeco | `DeviceController::importLogs` (`:137-181`) | `device` | machine |
| In-app self punch | `AttendanceReportController::punch` (`:247-335`) | client-supplied, default `web` | human |
| Bulk sheet upload (approval-gated) | `AttendanceBulkController::decide` (`:309-370`) | `bulk` | human |
| Manual Punch master | `MasterController` def `att-manual` (`:472-481`) | `manual` | human |
| Approved correction | `AttendanceCorrectionService::writeBack` (`:283-327`) | `correction` | human |

(The comparison matrix in the reference doc lists these as "seven paths"; counting the
manual master and the correction write-back separately gives nine distinct writers.)

### 1.2 Quarantine and release

A punch whose device PIN maps to no employee is **held** in `attendance_pending`
(`PunchIngestService::quarantine :368-371`; `ETimeOfficeService::quarantine :410-438`) and
released into `attendance_logs` when an admin maps the PIN
(`ETimeOfficeService::promotePending :455-562`, called from
`BiometricConfigController::mapEmployee :662-679`). The LAN path is the exception — it
discards (`DeviceController.php:165-166`).

### 1.3 Direction derivation (which punch is an entry, which is an exit)

Three mutually exclusive mechanisms, chosen per device row:
`in_machine_id`/`out_machine_id` match → the feed's own IN/OUT flag → (opt-in)
positional alternation for a one-reader site (`ETimeOfficeService::sequenceDirections
:612-681`). Direction is a *correctable property*, not part of a punch's identity
(rev173g) — except on the LAN path.

### 1.4 Shift resolution

"Which shift is this person on, on this date?" — one shared answer for the report and
payroll (`ShiftResolver::resolve :114-135`). Precedence: roster entry for
(employee **display name**, date) → `employees.shift` → Late Policy `shift_start`/`_end`
→ hardcoded 09:30–18:30. A roster row with `status='off'` marks the day a week-off.

### 1.5 Attendance-day derivation

`attendance_logs.log_date` is always the **calendar date of `punch_at`**. There is no
shift-aware duty date anywhere. §3 / Q2 below is exhaustive on this.

### 1.6 Status derivation (there is no stored status)

There is no status column on `attendance_logs`. Present / absent / late / half-day are
**derived at read time**, differently, by five subsystems: the Attendance Report, the
Today panel, the Absence sweep, Payroll, and Points. §3 records each.

### 1.7 Daily attendance report + PDF + per-day drill-down + rating

`AttendanceReportController` turns punches into one row per *employee-day that has
punches*: first in, last out, worked, break, in/out counts, late tier, break-over,
early-exit, overtime variance, drawback, shift name, week-off flag. A manager can save a
1–10 rating and a remark (`saveRating :486-514`). A landscape PDF mirrors it
(`reportPdf :517-609`, view `attendance-report-pdf.blade.php`).

### 1.8 Late-arrival policy and notification

`late_policy` rows scoped company / team / employee define grace, tiers L1–L3, free
lates, cut modes and a break budget. Report and Today panel *flag*; payroll *charges*.
An opt-in email (`late_email_enabled`, default 0) goes to the employee cc the reporting
manager, once per employee-day (`LateArrivalService`, `late_notifications`).

### 1.9 Previous-day absence sweep

`AbsenceService::runForDate` finds every active employee with no punch, no approved
leave, on a non-holiday non-week-off day, and sends an in-app + email notice with a
deep link to raise a correction. Opt-in, per-tenant `send_hour` (default 11).

### 1.10 Attendance correction / regularisation (F2)

Seven sub-types, five statuses, employee raises → manager/HR approves (optionally
modifying times) → punches written back with `source='correction'`, unless the payroll
period is finalised, in which case the request is approved but nothing is written.

### 1.11 Bulk and manual attendance

HR uploads a day-format or punch-format sheet (parsed in the browser), rows are validated
into `attendance_pending`, and an **admin** approves the batch before any punch exists.
A single Manual Punch master writes one `attendance_logs` row directly.

### 1.12 Maintenance commands

`attendance:dedupe`, `attendance:purge-demo`, `absence:notify`,
`attendance:sync-etimeoffice`.

---

## 2. Data model

### 2.1 `attendance_logs` — the single source of truth

**PROVENANCE — CRITICAL FINDING: created in three places with three different shapes.**

| Creator | Evidence | Shape |
|---|---|---|
| Migration (canonical) | `database/migrations/2026_06_24_000000_ensure_runtime_feature_tables.php:30-44` | `emp_code` NOT NULL + index, `log_date` NOT NULL + index, `punch_at` NOT NULL, `direction` **string(4)**, `source` default `biometric`, composite index `(emp_code, log_date)` |
| **RUNTIME** — report controller | `app/Http/Controllers/AttendanceReportController.php:32-46` | identical to the migration |
| **RUNTIME** — device controller | `app/Http/Controllers/DeviceController.php:209-227` | **everything nullable**, `direction` **unconstrained `string` (varchar 255)**, **no composite `(emp_code, log_date)` index** |

Extended by `2026_08_16_000200_extend_attendance_logs_for_sbb.php:50-94` (provenance
columns + widen `direction` to 8 + two unique indexes) and repaired idempotently by
`2026_08_17_000100_repair_sbb_schema.php:129-158`. `lat`, `lng`, `geo_status` are added
**at runtime** on the first in-app punch by
`AttendanceReportController::ensurePunchColumns() :370-392`.

| Column | Type | Written by | Notes |
|---|---|---|---|
| `id` | bigint PK | DB | |
| `tenant_id` | bigint, idx | all | NULL on single-tenant installs — which makes both unique indexes inert |
| `company_id` | bigint, idx | all except bulk approval | `AttendanceBulkController::decide :330-338` omits it |
| `emp_code` | string, idx | all | resolved SmartPRS code, or `USER-<id>` on the in-app path |
| `emp_name` | string | all | denormalised snapshot |
| `log_date` | date, idx | all | calendar date of `punch_at` on 6 of 9 writers; sheet-supplied on bulk; free-form on the manual master |
| `punch_at` | datetime | all | naive local wall clock, **no zone stored** |
| `direction` | string(4)→(8) | all | `in` / `out` / `unknown` |
| `source` | string | all | channel label; part of the natural key; **not indexed** |
| `device_sn` | string(64), idx | SBB / SmartEPT / replay | added 2026-08-16 |
| `device_user_id` | string(64) | SBB / SmartEPT / replay | raw PIN |
| `external_id` | string(96) | SBB / SmartEPT | sender idempotency key |
| `verify_mode` | string(24) | SBB / SmartEPT | |
| `lat`,`lng` | decimal(10,6) | in-app only | **runtime-added** |
| `geo_status` | string(20) | in-app only | **runtime-added** |
| `created_at`,`updated_at` | timestamps | all | |

Unique indexes (`2026_08_16_000200:86-94`):
`attlog_tenant_external_unique (tenant_id, external_id)` and
`attlog_natural_unique (tenant_id, emp_code, punch_at, source)`.
Both are **inert on any install where `tenant_id` is NULL** (NULL never collides). That
is why `ApiKeyAuth` and `SmarteptSignature` refuse a tenant-less credential — but the
`/iclock`, LAN, in-app, bulk, manual and correction writers all still write NULL-tenant
rows with no index protection.

### 2.2 `attendance_pending` — two features, one table name, disjoint schemas

**PROVENANCE — CRITICAL FINDING.**

| Creator | Evidence | Columns |
|---|---|---|
| Quarantine (migration) | `2026_08_16_000300_create_attendance_pending_table.php:28-43` | `tenant_id, company_id, device_sn, device_user_id, punch_at (NOT NULL), direction, verify_mode, external_id, source, resolved_at` + unique `(tenant_id, external_id)` |
| Quarantine (repair) | `2026_08_17_000100_repair_sbb_schema.php:83-124` | same, all nullable, additive |
| Quarantine (+device_code) | `2026_08_18_000100_attendance_pending_device_code.php:35-98` | adds `device_code` + unique `(tenant_id, device_code, punch_at, source)`, backfills `device_code := device_user_id` |
| **Bulk upload — RUNTIME** | `app/Http/Controllers/AttendanceBulkController.php:35-56` | `tenant_id, batch (uuid, NOT NULL), emp_code, emp_name, log_date, in_at, out_at, status, error, uploaded_by, decided_by, decided_at` |

Each creator short-circuits when the table exists (`AttendanceBulkController.php:37-39`;
`2026_08_16_000300:24-26`). On a normally migrated install the quarantine shape wins and
`AttendanceBulkController::upload()` inserts `batch`, `in_at`, `out_at`, `status`,
`uploaded_by` — **columns that do not exist** — *and* omits `punch_at`, which is NOT NULL.
Bulk attendance upload is therefore broken on any freshly migrated install, and the error
is returned to HR as a raw SQL string in a 422 (`:260-262`).

**NEW-ATT-D01 (not in the reference docs): when both shapes coexist the two features
cross-contaminate.** If the bulk shape is created first and the repair migration then adds
the quarantine columns, both writers succeed against one table, and:
- `ApiKeyController::pending() :400-407` groups `whereNull('resolved_at')` by
  `device_code, device_user_id, device_sn` — bulk rows have all three NULL and
  `resolved_at` is never stamped for them, so every bulk row ever uploaded appears
  forever as one phantom "held punches" group and inflates `totalHeld` (`:448`);
- `AttendanceBulkController::index() :276-281` groups by `batch` — quarantine rows have
  `batch` NULL, so they appear as a NULL-batch pseudo-batch whose rows an admin can
  "approve", writing garbage punches.

### 2.3 Supporting tables

| Table | PROVENANCE | Notes |
|---|---|---|
| `attendance_ratings` | **RUNTIME ONLY** — `AttendanceReportController::ensureTables() :47-59`. **No migration.** | unique `(emp_code, log_date)` — **not tenant-scoped**; see FINDING-B-SEC-02 |
| `attendance_corrections` | migration `2026_07_26_000200_create_attendance_corrections.php:16-42` (via `SchemaHelper::ensureTable`) **and** runtime `AttendanceCorrectionService::ensureSchema() :49-78` (identical) | the one table in this area with a matching migration + runtime pair |
| `late_notifications` | migration `2026_07_22_000100_create_late_notifications.php:18-29` **and** runtime `LateArrivalService::ensureTable() :31-48` (identical) | unique `(tenant_id, employee_id, log_date)` |
| `late_policy` | base columns migration `2026_01_01_000200_create_departments_table.php:61-73`; **every column the code actually reads is added at RUNTIME** by `MasterController::ensureCols()` from the def at `MasterController.php:237-241` | see §6 DEBT-B-06 |
| `shifts` | migration `2026_07_07_000000_ensure_shifts_table.php:29-47` **and** runtime `MasterController::ensureCols()` (def `:231-236`) | seeds one `General Shift` 09:30–18:30 per tenant (`:58-89`); **no unique constraint on (tenant_id, name)** although `ShiftResolver::shifts()` keys by lowercase name — duplicate names silently collapse, last row wins (`ShiftResolver.php:58`) |
| `roster` | **RUNTIME ONLY** — `MasterController::ensureCols()`, def `MasterController.php:397-402`. **No migration.** | columns `employee (string), company_name, team, date, shift, status`. Keyed by employee **display name** |
| `biometric_unmapped` | **RUNTIME ONLY** — `ETimeOfficeService::recordUnmapped() :361-371`. **No migration.** | unique `(tenant_id, device_code)` |
| `biometric_configs` | migration `2026_06_25_000000_create_biometric_configs_table.php`; columns added by `2026_07_22_000200`, `2026_08_28_000200`, **and at RUNTIME** by `PushController::ensure() :245-285` | holds `emp_prefix`, `serial_number`, `in_machine_id`, `out_machine_id`, `device_mode`, `sync_interval_min` |
| `holidays` | migration `2026_01_01_000200:100-106` | `tenant_id, date, name, applies_to`. `applies_to` has **no reader** |
| `leaves` | migration `2026_01_01_000200:84-97` | read by `AbsenceService::onLeaveEmployeeIds` and payroll |
| `geofence_rules` | migration `2026_01_01_000200:29-38` | per-employee fence for the in-app punch |
| `devices` | migration `2026_01_01_000200:13-27` | the LAN ZKTeco master |
| `attendance` (legacy) | migration `2026_01_01_000200:40-59` | **DEAD** — see below |
| `app_settings` (`ckey='absence_notify'`) | assumed pre-existing; `AbsenceService::config :50-68` guards `Schema::hasTable` | per-tenant absence config as a JSON blob |
| `statutory_settings` | runtime `SettingsController::ensureTable()` | holds `late_email_enabled`, `weekly_off_day`, `sat_off_mode`, `lop_basis`, `sandwich_rule` |

### 2.4 The dead legacy `attendance` table — a three-way schema mismatch

`database/migrations/2026_01_01_000200_create_departments_table.php:40-59` creates
`attendance` with `punch_in`, `punch_out`, `hours`, `lates_mtd`,
`status enum('full','half','absent','compoff')`, `geo_ok`, `biometric_ok`, unique
`(employee_id, date)`.

- `app/Models/Attendance.php:13-15` declares `$fillable = [..., 'check_in', 'check_out',
  'status', 'source']` — **`check_in`/`check_out` do not exist on that table.**
- `app/Http/Controllers/AttendanceController.php:34` validates
  `status ∈ {present, absent, leave, holiday, weekoff}` — **none of which are in the
  enum** `{full, half, absent, compoff}` except `absent`.
- The controller is not routed (`routes/web.php:693` redirects `/devices`,
  `/attendance` etc. to `/app`) and is marked `DEPRECATED / LEGACY — not routed`
  (`AttendanceController.php:11-16`).

So three definitions of an attendance status exist in the repository and **none of them
agree with each other or with any live code path**. `compoff` is the only occurrence of
comp-off anywhere in the codebase (verified by grep across `app/`, `database/`,
`resources/`).

### 2.5 Migration filename does not match contents

`database/migrations/2026_01_01_000300_create_attendances_table.php` creates **thirteen
payroll tables** (`salary_schedules`, `salary_components`, `payroll_runs`, `payslips`,
`commissions`, `incentive_schemes`, `clawbacks`, `advances`, `loans`, `expenses`,
`deductions`, `bonus_encashment`, `payout_recon`) and **no attendance table at all**
(`:13-179`). Confirms the reference doc's DEBT-ATT-21.

---

## 3. Business rules register

`HC?` = hard-coded. Catalogue IDs are reused where the rule was confirmed in source;
`NEW-*` marks rules the catalogue did not record.

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HARD-CODED? | Configurable today? | Customer may want different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-ATT-001 | `attendance_logs` is the only attendance store; the legacy `attendance` table is never written | — | always | all writers target `attendance_logs` | global | `DeviceController.php:168-174`; `AttendanceController.php:11-16` | Y (architecture) | no | N |
| BR-ATT-002 | A punch's identity is (tenant, emp_code, punch_at, source); `direction` is a correctable property | punch | on write | `updateOrInsert` / `insertOrIgnore` on that key | global | `ETimeOfficeService.php:745-767`; `2026_08_16_000200:91-94` | Y | no | N |
| BR-ATT-002b (exception) | On the LAN path identity **includes** `direction` and **excludes** `punch_at` | punch | LAN sync | `updateOrInsert` on `(emp_code, log_date, direction, source[, tenant])` | LAN only | `DeviceController.php:193-207` | Y | no | Y — defect |
| BR-ATT-003 | A sender's `external_id` makes a re-send a no-op | `external_id` | SBB/SmartEPT | `insertOrIgnore`; verdict `duplicate` | SBB/SmartEPT | `PunchIngestService.php:141-167` | Y | no | N |
| BR-ATT-006 | A punch time carrying a UTC offset or `Z` is REJECTED on the SBB path | `punch_at` string | regex match | reason `TIME_FORMAT` | SBB | `PunchIngestService.php:308-331` | Y | no | N |
| BR-ATT-007 | SmartEPT ISO times are converted to app-tz wall clock, not rejected | ISO string | always | `setTimezone(config('app.timezone'))` | SmartEPT | `SmarteptWebhook.php:300-317` | Y | no | N |
| BR-ATT-008 | An unmapped punch is quarantined, never discarded | device code | no employee match | write `attendance_pending` | all except LAN | `PunchIngestService.php:116-136`; `ETimeOfficeService.php:718-729` | Y | no | N |
| BR-ATT-008b (exception) | On the LAN path an unmapped punch is silently discarded | — | no match | `continue` | LAN only | `DeviceController.php:163-166` | Y | no | Y — defect |
| BR-ATT-009 | Mapping a device ID releases its held punches and stamps `resolved_at` | device id, employee | admin maps | `promotePending` | global | `ETimeOfficeService.php:455-562`; `BiometricConfigController.php:662-679` | Y | no | N |
| BR-DEV-004 | Direction: `in_machine_id`/`out_machine_id` → feed flag → (if `device_mode='single'`) rank-in-day alternation | machine id, feed flag, punch rank | per device row | set `direction` | pull + `/iclock` only | `ETimeOfficeService.php:581-584, 612-681, 710-712, 743` | partly | `biometric_configs.device_mode` per device | Y — the 60 s repeat window is not configurable |
| NEW-ATT-001 | Two reads within **60 seconds** are one punch in single-device mode | timestamps | `t − anchor ≤ 60` | keep the current direction; rank not advanced | pull + `/iclock` | `ETimeOfficeService.php:578, 656-664` | **Y — `SINGLE_REPEAT_SEC = 60`** | no | **Y** |
| NEW-ATT-002 | ZKTeco status byte mapping: `1`,`2`,`5` → out; everything else (incl. blank/unknown) → **in** | ATTLOG field 2 | `/iclock` | set `direction` | `/iclock` | `PushController.php:159` | **Y** | no | **Y** — vendor byte maps vary |
| NEW-ATT-003 | Vendor feed direction: value containing `out`, or `o`, or `0` → out; **everything else incl. blank → in** | `INOUT` field | pull | set `direction` | eTimeOffice | `ETimeOfficeService.php:224-235` | **Y** | only via `in_machine_id`/`out_machine_id` | **Y** |
| BR-ATT-018 | Shift precedence: roster(name,date) → `employees.shift` → Late Policy → 09:30–18:30 | roster, employee, policy | per employee-day | resolve a shift | report + payroll + absence | `ShiftResolver.php:114-135`; `AttendanceReportController.php:828-829` | fallback is HC | shifts + roster masters | Y |
| NEW-ATT-004 | The roster is keyed by employee **display name**, not `emp_code` | `roster.employee` | lookup | match | roster | `ShiftResolver.php:94, 116` | Y | no | **Y** — duplicate names collide; a rename drops the roster |
| BR-ATT-019 | Night shift ⇔ `toMin(end) <= toMin(start)`; a 24-hour shift counts as night | shift times | on load | set `night` | global | `ShiftResolver.php:62` | Y | no | Y |
| BR-ATT-201 | `attendance_logs.log_date` is the calendar date of `punch_at`, never a duty date | `punch_at` | always | `->toDateString()` | global | `ETimeOfficeService.php:526, 761`; `PunchIngestService.php:146, 251` | **Y** | **no** | **Y — critical** |
| BR-ATT-016 | Pairing: direction-aware when the day has both an `in` and an `out` (repeat IN while open = double-tap, ignored; orphan OUT ignored); otherwise chronological alternation | day's punches | per employee-day | build sessions | report + payroll | `AttendanceReportController.php:621-712`; `PayrollGenController.php:322-408` | Y | no | Y |
| NEW-ATT-005 | Presence = *any* punch on the day; there is no minimum-hours gate on presence | punches | ≥ 1 row | count as present | report, payroll, absence, ESS | `AttendanceReportController.php:119-123`; `PayrollGenController.php:202-220`; `AbsenceService.php:239-254`; `EssController.php:134` | **Y** | only indirectly via `net_hours` mode day-cuts | **Y** |
| NEW-ATT-006 | The report emits **no row at all** for an employee-day with no punches | punches | 0 rows | omit | report + PDF | `AttendanceReportController.php:119-123, 137` | Y | no | **Y** — absent/holiday/week-off days are invisible on the report |
| BR-ATT-020 | `late_min = max(0, first_in_min − (shift_start_min + grace))`, `first_in_min = hour*60+minute` | first punch, shift, grace | first_in known | flag / charge | report, Today, email, payroll | `AttendanceReportController.php:834-836`; `LateArrivalService.php:122-131`; `TodayController.php:142-145`; `PayrollGenController.php:437-439` | formula HC | thresholds in `late_policy` | Y |
| BR-ATT-021 | Tier: highest crossed threshold wins (L3→L2→L1), else label `Late` | `late_min`, `l1..l3_min` | `late_min > 0` | set `late_level` | report | `AttendanceReportController.php:837-850` | Y | thresholds configurable | N |
| BR-ATT-021b | In **payroll**, late-past-grace-but-below-L1 is charged at **`l1_cut`** (fall-through `return`) | `late_min` | tiered mode | `+= l1_cut` | payroll | `PayrollGenController.php:494` | **Y** | no | **Y** — a hidden business rule |
| BR-ATT-022 | Free lates: `tiered` charges only once `lateSeen > lates_before_cut`; `simple` charges `max(0, lateCount − free)` | late days | monthly | day-cut | payroll | `PayrollGenController.php:453, 475` | Y | `lates_before_cut` | Y — the window is the payroll month only, not rolling |
| BR-ATT-023 | Break penalty: `half_day` → +0.5 day; `per_30min` → `ceil((break−budget)/30) × 0.25` day | break_min, budget | `break > budget > 0` and `break_cut ≠ none` | day-cut | payroll | `PayrollGenController.php:464-471` | the 30 and 0.25 are HC | budget + mode configurable | **Y** |
| BR-ATT-024 | `net_hours` mode: `worked < half` → 1.0 day cut; `half ≤ worked < full` → 0.5; lateness forgiven | worked | per day | day-cut | payroll | `PayrollGenController.php:441-448` | Y | full/half hours configurable | Y |
| BR-ATT-025 | Late email: OFF by default, once per employee-day, only for today/yesterday, only when a policy applies | punch, policy, flag | all conditions | email + `late_notifications` row | global | `LateArrivalService.php:56-157` | recency window HC | `late_email_enabled` | Y |
| NEW-ATT-007 | The late email uses the **earliest punch of the day regardless of direction** as the arrival | punches | any | compute `late_min` | email only | `LateArrivalService.php:112-117` (`orderBy('punch_at')`, no direction filter) | Y | no | **Y** — a stray leading OUT corrupts it |
| NEW-ATT-008 | The late email and the Today panel use the Late Policy shift start **only** — they never consult `ShiftResolver` | policy | always | compute | email, Today | `LateArrivalService.php:208`; `TodayController.php:138` | Y | no | **Y** — disagrees with the report for anyone not on the default shift |
| BR-ATT-026 | Late mail is queued on SBB/SmartEPT, **inline** on `/iclock`, pull, LAN and bulk | — | on IN punch | dispatch vs. call | global | `PunchIngestService.php:200-209`; `NotifyLateArrivals.php`; inline at `ETimeOfficeService.php:777`, `AttendanceBulkController.php:353`, `DeviceController.php:177` | Y | no | Y — defect |
| NEW-ATT-009 | The in-app punch and the Manual Punch master trigger **no** late evaluation at all | — | always | nothing | in-app, manual | `AttendanceReportController.php:320-334`; `MasterController.php:982-1020` | Y | no | Y |
| BR-ATT-027 | Absent ⇔ non-blank `emp_code` ∧ no punch ∧ no approved leave ∧ not a tenant holiday ∧ not a week-off | punch set, leave set, holidays, shift/roster, `working_days` | per employee-day | notify | absence sweep only | `AbsenceService.php:141-176` | Y | `working_days`, `enabled` | Y |
| BR-ATT-028 | Absence sweep is opt-in, hour-gated per tenant, idempotent per employee-day | config | `send_hour == now hour` | `NotificationService::sendOnce` | per tenant | `AbsenceService.php:83-138`; `AbsenceNotify.php:28-45` | default hour 11 | `send_hour` | Y |
| NEW-ATT-010 | The absence sweep **never runs** on a NULL-tenant (single-tenant) install | `employees.tenant_id` | `->filter()` drops NULL | tenant skipped | absence | `AbsenceService.php:96-97` | Y | no | **Y — defect** |
| NEW-ATT-011 | A tenant-wide holiday cancels absence for **everyone**; `holidays.applies_to` is ignored | holidays row | date match | skip whole tenant | absence | `AbsenceService.php:111-113, 225-236` | Y | no | **Y** |
| NEW-ATT-012 | Employee status must be exactly the lowercase string `active` to be swept | `employees.status` | `where('status','active')` | include | absence | `AbsenceService.php:121-122` | Y | no | Y — inconsistent with the case-insensitive check at `AttendanceReportController.php:898-900` |
| BR-ATT-029 | One open correction per employee-day (`pending` or `approved` blocks a new one) | existing rows | exists | reject | corrections | `AttendanceCorrectionService.php:134-139` | Y | no | Y |
| BR-ATT-030 | A correction cannot be for a future date; for today, not for a time beyond `now()+2 min` | date, times | comparison | reject | corrections | `AttendanceCorrectionService.php:99-131` | 2 min HC | no | Y |
| BR-ATT-031 | Rejection requires remarks | remarks | blank | reject the decision | corrections | `AttendanceCorrectionService.php:214-217` | Y | no | Y |
| BR-ATT-032 | HR may modify times while approving; `app_in`/`app_out` default to the requested times | opts | approve | write `app_*` | corrections | `AttendanceCorrectionService.php:231-236` | Y | no | N |
| BR-ATT-033 | A finalised payroll period blocks the write-back: status `approved`, `payroll_locked=true`, **nothing written** | `payroll_runs` | `isPeriodFinalised` | skip `writeBack` | corrections | `AttendanceCorrectionService.php:238-262`; `PayrollLock.php:39-75` | statuses HC | no | **Y** — see Q6 |
| BR-ATT-034 | Write-back DELETEs all `source='correction'` rows for that employee-day, then inserts ≤ 2 | date, times | approve | replace | corrections | `AttendanceCorrectionService.php:295-319` | Y | no | Y |
| NEW-ATT-013 | Correction punches **coexist** with the device punches for the same day; the original punches are never removed or superseded | punches | always | interleave in `pairPunches` | corrections | `AttendanceCorrectionService.php:295-301` | Y | no | **Y** |
| NEW-ATT-014 | A correction can never express an overnight out (`punch_at = "$date $HH:MM:00"`, string `strcmp` ordering) | times | always | — | corrections | `AttendanceCorrectionService.php:115, 234, 311` | Y | no | **Y** |
| BR-ATT-035 | Only a pending request can be cancelled, and only by its owner | id, employee | status/ownership | cancel | corrections | `AttendanceCorrectionService.php:167-189` | Y | no | N |
| BR-ATT-036 | Bulk upload requires **admin** approval; upload is `admin`/`hr_manager` | roles | route guard | 403 or proceed | bulk | `AttendanceBulkController.php:25-33` | roles HC | no | **Y** |
| BR-ATT-037 | Bulk row validation order: employee resolvable → date valid → not future → same-day time not beyond now → at least one of in/out → `out > in` | row | each check | flag `status='error'` | bulk | `AttendanceBulkController.php:221-241` | Y | no | Y |
| NEW-ATT-015 | Bulk cap 3 000 rows per file; 500-row insert chunks | count | `> 3000` | 422 | bulk | `AttendanceBulkController.php:168-170, 253` | **Y** | no | **Y** |
| BR-ATT-038 | A manual punch cannot be more than 2 minutes in the future, and `log_date` cannot be beyond today | punch_at, log_date | comparison | `RuntimeException` | manual master | `MasterController.php:999-1020` | 2 min HC | no | Y |
| NEW-ATT-016 | The manual master lets `log_date` and `punch_at` be set **independently, with no consistency check** | both fields | never checked | store as given | manual master | `MasterController.php:474-480, 999-1020` | Y | no | **Y** — it is the only way to express a shift-aware date, and it is accidental |
| BR-ATT-039 | In-app punch direction auto-toggles from the last punch of *today*; the first punch of a day is always `in` | last punch | direction | set | in-app | `AttendanceReportController.php:290-294, 356-360` | Y | no | Y |
| NEW-ATT-017 | The in-app punch has **no dedup of any kind** and takes `source` straight from the request body | request | always | plain `insert` | in-app | `AttendanceReportController.php:258-262, 299-321` | Y | no | **Y** — see FINDING-B-SEC-04 |
| NEW-ATT-018 | If the login cannot be resolved to an employee, the in-app punch is stored under the synthetic code `USER-<id>` | user | no match | write anyway | in-app | `AttendanceReportController.php:279` | Y | no | **Y** — the row is invisible to payroll and never cleaned up |
| BR-ATT-040 | Geofence: `allowed = radius_km + {strict:0, 1km:1.0, 2km:2.0}`; block when `dist > allowed`; no GPS + strict → block, else verdict `no-gps` | lat/lng, rule | comparison | 422 or verdict | in-app | `AttendanceReportController.php:409-438` | grace ladder HC | `geofence_rules.radius_km`, `outside` | **Y** — only three grace bands exist |
| BR-ATT-041 | Plain (non-privileged) users are scoped to their own `emp_code` on the report and PDF | user roles | not admin/HR | filter | report, PDF | `AppDataController.php:221-245`; `AttendanceReportController.php:104-107, 535-538` | roles HC | no | Y |
| BR-ATT-041b (exception) | `logs()` (per-day drill-down) applies **no self-scope and no role guard** | code, date from URL | always | return | report drill-down | `AttendanceReportController.php:441-484` | Y | no | Y — defect |
| BR-ATT-042 | Demo punches are never fabricated in `production`, and only when `SMARTPRS_DEMO_DATA` is truthy; past days only, ≤ 31-day range | env | conditions | fabricate `source='demo'` | report | `AttendanceReportController.php:872-956` | many HC | env flag | Y |
| BR-ATT-043 | Duplicate punches are repaired keep-**newest** on `(tenant, emp_code, punch_at, source)` | rows | grouping | hard DELETE | maintenance + 2 migrations | `DedupeAttendance.php:42-88`; `2026_08_16_000200:125-156`; `2026_08_17_000100:213-234` | Y | no | **Y** — see Q7 |
| BR-ATT-050 | Rating 1–10 + remarks ≤ 1000 chars, `admin`/`hr_manager` only | rating | validate | upsert | report | `AttendanceReportController.php:486-514` | scale HC | no | **Y** |
| NEW-ATT-019 | Late-policy resolution ranks employee(2) > team(1) > company(0); a policy whose `company_name` is non-blank and differs is skipped; **ties are broken by row order** (`if ($tr > $rank)`) | policy rows | ranking | pick one | 4 implementations | `LateArrivalService.php:184-204`; `AttendanceReportController.php:800-822`; `TodayController.php:114-134`; `PayrollGenController.php:279-295` | Y | no | **Y** — no branch/department/designation scope, no effective-dating |
| NEW-ATT-020 | Points flags a day late when `first_in_min > 570` (09:30) with **no grace, no policy, no shift** | first punch | comparison | award/deduct points | Points | `PointsController.php:25, 164-168` | **Y** | no | **Y** |
| NEW-ATT-021 | The PDF highlights a break in red when `break_min > 90`, unrelated to any `break_budget` | break_min | `> 90` | CSS class | PDF | `attendance-report-pdf.blade.php:67, 78` | **Y** | no | **Y** |
| NEW-ATT-022 | The report response header advertises the class constants `09:30`–`18:30` regardless of the resolved shift | — | always | JSON `shift` | report + PDF | `AttendanceReportController.php:170, 603` | **Y** | no | **Y** |

---

## 4. Calculations and formulas

Every formula transcribed from source, with the divisor/basis and rounding.

### CALC-B-01 · Session pairing → worked and break minutes
```
sort punches by timestamp
hasIn  = any punch with direction 'in'
hasOut = any punch with direction 'out'

if (hasIn && hasOut):                       // DIRECTION-AWARE
    'out' with a session open   -> close it
    'out' with nothing open     -> ignore (orphan)
    'in'/unknown, none open     -> open a session
    'in' while a session is open-> ignore (double-tap)
    a session still open at end -> OPEN session, worked = 0
else:                                        // ALTERNATION (rev158)
    sessions[i] = (rows[2i], rows[2i+1] or null)

worked_min = Σ over closed sessions of  mins(session.out − session.in)
break_min  = Σ over consecutive sessions of mins(session[n].in − session[n−1].out)
mins(a,b)  = (int) abs($a->diffInMinutes($b))
```
Source — `AttendanceReportController.php:621-712` (`mins()` at `:757-760`).
Reimplemented in `PayrollGenController::dayStats() :346-405`.
**Rounding**: whole minutes, truncated by the integer cast; seconds are discarded.

**Worked example** — 09:15 in, 13:05 out, 13:35 in, 18:35 out →
sessions 230 min + 300 min = `worked 530`; `break 30`. Rendered `8h 50m` / `0h 30m`.

**Divergence between the two implementations** (confirmed in source, both read in full):
`pairPunches()` records `break_after` for **every** inter-session gap and accumulates
`totalBreak` for each (`:686-690`); `dayStats()` accumulates a break only when a session
is *re-opened* after an OUT (`:384-388`) and never counts a trailing gap. For a day
ending on an unclosed IN the two produce different break totals.

### CALC-B-02 · Duration display
```
hm(m) = intdiv(max(0,m), 60) . 'h ' . (max(0,m) % 60) . 'm'
```
`AttendanceReportController.php:859-864`. `hm(530) = "8h 50m"`.

### CALC-B-03 · First-in minutes since midnight
```
first_in_min = firstIn.hour * 60 + firstIn.minute
```
`AttendanceReportController.php:741`; `LateArrivalService.php:122`;
`TodayController.php:142`; `PointsController.php:164`.
09:41 → 581. **This is why night shifts can never be late** (see §6 DEFECT-B-02).

### CALC-B-04 · Late minutes
```
shift_start_min = HH*60 + MM     (resolved shift start, else policy shift_start, else '09:30')
grace           = shift.grace_min when NOT NULL, else policy grace_min, else 0
late_min        = max(0, first_in_min − (shift_start_min + grace))
```
`AttendanceReportController.php:828-836`. Payroll instead uses a Carbon comparison:
```
cutoff   = Carbon::parse("$date $dayStart")->addMinutes($dayGrace)
isLate   = firstIn > cutoff
late_min = isLate ? cutoff->diffInMinutes(firstIn) : 0
```
`PayrollGenController.php:437-439`. The two agree for same-day shifts and diverge across
midnight (the payroll form is date-anchored, the report form is not).

**Worked example** — start 09:30 (570), grace 15, first in 09:41 (581):
`max(0, 581 − 585) = 0` → on time. First in 10:05 (605): `605 − 585 = 20` → 20 min late.

### CALC-B-05 · Late tier day-cut (payroll, `tiered` mode)
```
if  l3_min > 0 and late_min >= l3_min   -> cut = l3_cut
elif l2_min > 0 and late_min >= l2_min  -> cut = l2_cut
elif l1_min > 0 and late_min >= l1_min  -> cut = l1_cut
else                                    -> cut = l1_cut     <-- FALL-THROUGH
charged only while lateSeen > lates_before_cut
```
`PayrollGenController::tierCut() :482-495`, `attendanceCut() :449-455`.
**Worked example** — `l1 = 10/0.25`, `l2 = 30/0.5`, `l3 = 60/1.0`, `free = 2`; month with
lates of 5, 12, 35, 70 min → 1st & 2nd free; 3rd crosses L2 → 0.5; 4th crosses L3 → 1.0;
`lateCut = 1.5 days`. **No rounding** — day fractions accumulate as floats.
**Divisor/basis**: the cut is a number of *days*, converted to money later by
`factor = paidDays / denominator` where the denominator is `lop_basis`
(working / calendar / fixed30).

### CALC-B-06 · Late day-cut (payroll, `simple` mode)
```
excess = max(0, lateCount − lates_before_cut)
half_day_per_late -> excess * 0.5
full_day_per_late -> excess * 1.0
one_day_per_n     -> intdiv(excess, max(1, cut_n))     // cut_n defaults to 3
otherwise         -> 0.0
```
`PayrollGenController::simpleCut() :498-513`; `cut_n` normalised at `:307`.
**Worked example** — 7 lates, 3 free, `one_day_per_n`, `cut_n = 3` → `excess = 4`,
`intdiv(4,3) = 1` day. Rounding: `intdiv` truncates.

### CALC-B-07 · Day-cut from hours (`net_hours` mode)
```
dayFullMin = shift.full_day_hours*60 else policy full_day_hours*60 else 9*60  = 540
dayHalfMin = shift.half_day_hours*60 else policy half_day_hours*60 else 4.5*60 = 270
worked <  dayHalfMin              -> cut += 1.0
dayHalfMin <= worked < dayFullMin -> cut += 0.5
worked >= dayFullMin              -> cut += 0
```
`PayrollGenController.php:441-448`; defaults at `:308-309`.
**Worked example** — worked 300 min against 540/270 → `+0.5 day`, lateness forgiven.

### CALC-B-08 · Break-budget penalty
```
budget = shift.break_budget when NOT NULL, else policy break_budget
if break_cut != 'none' and budget > 0 and break_min > budget:
    'half_day'  -> breakCut += 0.5
    'per_30min' -> breakCut += ceil((break_min − budget) / 30) * 0.25
```
`PayrollGenController.php:464-471`.
**Worked example** — budget 60, break 155, `per_30min` → `ceil(95/30) = 4` → `1.0 day`.
Rounding: `ceil` on the 30-minute block, so 1 minute over costs a full 0.25 day.

### CALC-B-09 · Total attendance day-cut
```
cut = lateCut + breakCut
```
`PayrollGenController.php:478`.

### CALC-B-10 · Early exit and overtime (report display only)
```
endHm    = resolved shift end, else '18:30'
shiftEnd = Carbon::parse(first_in_date . ' ' . endHm)
if shift.night: shiftEnd = shiftEnd->addDay()
early_min    = (lastOut && lastOut <  shiftEnd) ? mins(lastOut, shiftEnd) : 0
overtime_min = (lastOut && lastOut >  shiftEnd) ? mins(shiftEnd, lastOut) : 0
```
`AttendanceReportController.php:729-735`.
**Worked example** — shift end 18:30, last out 19:45 → `overtime_min = 75`.
**This "overtime" is never written to the `overtime` register and never reaches payroll**
— the `overtime` table is a purely manual master (`MasterController.php:339-345`).

### CALC-B-11 · Drawback (shortfall against the day's target)
```
fullMin      = shift.full_day_hours*60 else STANDARD_WORK_MIN (480)
drawback_min = max(0, fullMin − worked_min)
```
`AttendanceReportController.php:736-737, 748`. Target 480, worked 400 → `80` → `1h 20m`.

### CALC-B-12 · Remaining minutes (per-day drill-down) — inconsistent with CALC-B-11
```
remaining_min = max(0, 480 − total_work)          // 480 hardcoded, shift ignored
```
`AttendanceReportController.php:457`.

### CALC-B-13 · Today-panel working hours — inconsistent with CALC-B-01
```
working_hours = punch_count > 1 ? round( mins(first_punch, last_punch) / 60, 2 ) : 0.0
```
`TodayController.php:51-53`. **First-to-last elapsed time, including every break.**
For CALC-B-01's example this yields `round(560/60, 2) = 9.33 h` against the report's
`8h 50m`. Rounding: 2 decimal places.

### CALC-B-14 · Present days
```
present_days = COUNT(DISTINCT log_date)
               WHERE tenant_id = ? AND emp_code = ? AND log_date BETWEEN period_start AND end_date
```
`PayrollGenController::presentDays() :202-220`; `EssController.php:134`;
`AbsenceService::presentEmpCodes() :239-254` (per-date variant);
`ReportController` `reg-muster` dataset. **A one-minute day counts as a full present day.**

### CALC-B-15 · Present and late days for Points (a fourth definition)
```
firstByDay[d] = earliest punch_at on d among rows where direction='in' OR direction IS NULL
present = count(firstByDay)
late    = count of days where (hour*60 + minute) > 570      // 09:30, no grace, no policy
```
`PointsController.php:25, 139-175`.

### CALC-B-16 · Working days — THREE incompatible definitions
```
(a) configurable  : isWeekOff(d) = (d.dayOfWeek == map[weekly_off_day])
                                 || (d is Saturday && sat_off_mode in {all, 2_4, 1_3}
                                     where nth = ceil(d.day / 7))
                    working = days − weekOffs − working-day holidays
(b) hardcoded     : working = count of days where dayOfWeek != SUNDAY   (no holidays)
(c) Points        : working = count of days where dayOfWeek != SUNDAY and not a holiday
(d) absence sweep : off ⇔ ISO weekday NOT IN working_days   (default [1,2,3,4,5,6])
```
(a) `PayrollGenController::isWeekOff() :70-89` + `workingDaysInRange() :97-109`;
(b) `PayrollGenController::monthMeta() :178-196`;
(c) `PointsController::monthMeta() :239-263`;
(d) `AbsenceService.php:45, 169`.

**Confirmed from source, correcting the reference doc**: `monthMeta()`'s Sunday-only
working-day figure is **discarded at every call site** in `PayrollGenController`
(`:1096`, `:1562`, `:1976`, `:2090`, `:2476` all destructure it as `, ,`), and `compute()`
recomputes `$working` at `:1118-1127` using the configurable `isWeekOff()` + holidays.
The **live** LOP denominator is therefore the configurable one, not the Sunday-only one.
The Sunday-only calculator is dead weight in `PayrollGenController` but **live** in
`PointsController::monthMeta()`, which feeds absence-based points deductions.
This is a material correction to the reference document — see §8.

### CALC-B-17 · Sandwich days (payroll only, off by default)
```
covered = present dates ∪ paid-leave dates
for d in [max(2, dojDay+1) .. daysInMonth-1]:
    if not isOff(d): continue
    p = nearest earlier non-off day; n = nearest later non-off day
    if p < dojDay or p < 1 or n > daysInMonth: continue
    if covered[p] is empty and covered[n] is empty: count++
```
`PayrollGenController::sandwichDays() :770-813`. Gated on `rates['sandwich_rule']`
(default `0`, `SettingsController.php:56`). **Operates on day-of-month integers within
`$start`'s month**, so on a cut-off period spanning two months it indexes the wrong month.

### CALC-B-18 · Single-device rank
```
times = sorted(stored ∪ incoming) for (tenant, lower(emp_code), date, source)
rank = 0; anchor = null
for ts in times:
    if anchor is null or (ts − anchor) > 60:  rank++; dir = (rank odd ? 'in' : 'out'); anchor = ts
    assign dir to ts
then UPDATE any stored row whose direction differs
```
`ETimeOfficeService::sequenceDirections() :612-681`.
**Worked example** — 09:00, 09:00:20, 13:00, 18:00 → `in, in, out, in`.

### CALC-B-19 · Geofence distance
```
R = 6371 km
a = sin²(Δlat/2) + cos(lat1)·cos(lat2)·sin²(Δlon/2)
d = R · 2 · atan2(√a, √(1−a))
allowed = radius_km + {strict:0.0, '1km':1.0, '2km':2.0}[outside]
blocked ⇔ d > allowed
```
`AttendanceReportController::haversineKm() :395-403`, `checkGeofence() :409-438`.

### CALC-B-20 · Excel cell normalisation (bulk)
```
time: 0 < v < 1        -> mins = round(v*24*60); HH:MM = sprintf('%02d:%02d', intdiv(mins,60)%24, mins%60)
date: 25000 < v < 60000 -> 1899-12-30 + v days     (1900 system only; 1904 Mac epoch unhandled)
```
`AttendanceBulkController::normTime() :88-126`, `normDate() :129-153`.
`0.3854` → `09:15`; `45078` → `2023-06-15`.

---

## 5. Configuration surface today

### (a) What a customer CAN change without code

| Setting | Where | Effect | Storage |
|---|---|---|---|
| Named shifts: name, code, company, start, end, grace, full-day hours, half-day hours, break budget, night allowance, status | Masters → Shifts (`MasterController.php:231-236`) | drives report variance columns, late thresholds, payroll day-cuts, night allowance | `shifts` |
| Employee default shift | Employee form (`employees.shift`) | shift fallback | `employees` |
| Per-day roster override + `status='off'` week-off | Masters → Roster (`MasterController.php:397-402`) | overrides the shift for that date; `off` suppresses late/break flags and absence | `roster` (runtime-created) |
| Late Policy: scope (company/team/employee), company, mode (simple/tiered/net_hours), shift start/end, grace, full/half-day hours, free lates, cut mode, cut N, L1–L3 thresholds and cuts, break budget, break cut mode, notes | Masters → Late Policy wizard (`MasterController.php:237-241`) | late flags and payroll day-cuts | `late_policy` (runtime columns) |
| `late_email_enabled` (0/1) | Statutory Rate Settings (`SettingsController.php:63`) | turns the late-arrival email on | `statutory_settings` |
| Late-arrival email subject/body template | Greetings screen, "Late login" template | email wording, `{{var}}` rendered | greetings config |
| Absence sweep: `enabled`, `send_hour`, `notify_employee`, `notify_manager`, `working_days`, `regularise_screen` | `POST /app/absence/config` (`AbsenceController::saveConfig`) | who is flagged absent and when | `app_settings.ckey='absence_notify'` |
| `weekly_off_day`, `sat_off_mode`, `lop_basis`, `sandwich_rule` | Statutory Rate Settings (`SettingsController.php:51-56`) | payroll working days / LOP basis / sandwich rule | `statutory_settings` |
| Holidays calendar | Masters → Holidays | absence suppression + payroll denominator | `holidays` |
| Biometric device: provider, credentials, `emp_prefix`, serial, `in_machine_id`, `out_machine_id`, `device_mode` (1-device / 2-device), `sync_interval_min`, generic-API JSON recipe | Biometric Device Setup (`BiometricConfigController`) | which punches arrive and how direction is derived | `biometric_configs` |
| Device-ID ↔ employee mapping | Employee Mapping card / Pending Punches | punch attribution + quarantine release | `employees.device_user_id` |
| Geofence per employee: start point, lat/lng, `radius_km`, `outside` mode | Masters → Geofence | in-app punch blocking | `geofence_rules` |
| API keys (scopes, expiry, revoke); SmartEPT webhook endpoints (events, rotate, revoke) | Time & Attendance → API Keys | which senders may ingest | `api_keys`, `smartept_webhook_endpoints` |
| Manager rating + remarks per employee-day | Attendance Report → rating | display only | `attendance_ratings` |
| `SMARTPRS_DEMO_DATA` env flag | `.env` (non-production only) | fabricate demo punches | env |

### (b) What a customer CANNOT change without code — the SmartPRS 3 backlog

Exhaustive; each item is a behaviour a real customer could reasonably ask to differ.

**Attendance day / shift**
1. The attendance date is always `punch_at`'s calendar date — there is no shift-aware
   duty date, no "attendance day starts at HH:MM" rule, no way to attribute a
   cross-midnight shift to one day (`ETimeOfficeService.php:761`,
   `PunchIngestService.php:146`, `AttendanceReportController.php:304`).
2. The 09:30 / 18:30 last-resort shift (`AttendanceReportController.php:26-27`,
   `PayrollGenController.php:37-38`, `LateArrivalService.php:29`,
   `TodayController.php:138`, `AttendanceBulkController` templates).
3. `STANDARD_WORK_MIN = 480` (8 h) as the drill-down day target
   (`AttendanceReportController.php:28, 457`).
4. `full_day_hours` default 9 and `half_day_hours` default 4.5
   (`PayrollGenController.php:308-309`).
5. Night-shift definition is fixed at `end <= start`; a 24 h shift is classed as night
   (`ShiftResolver.php:62`).
6. The roster is keyed by employee **display name**; there is no emp_code-keyed roster,
   no shift *pattern*/rotation, no bulk roster generation
   (`ShiftResolver.php:94, 116`).
7. There is no flexi-time, no core-hours, no half-day shift definition, no split shift,
   no multi-shift-per-day, no shift-change effective dating.

**Direction and pairing**
8. The single-device repeat window `SINGLE_REPEAT_SEC = 60`
   (`ETimeOfficeService.php:578`).
9. The ZKTeco status-byte map `1,2,5 → out`, everything else → `in`
   (`PushController.php:159`).
10. The vendor-flag map (`contains 'out'`, `'o'`, `'0'` → out, else `in`)
    (`ETimeOfficeService.php:224-235`).
11. The pairing rule itself (direction-aware vs. alternation, double-tap ignored, orphan
    OUT ignored) is fixed logic (`AttendanceReportController.php:621-712`).
12. `device_mode` is honoured **only** by `ETimeOfficeService::import` — SBB, SmartEPT,
    in-app, bulk and manual ignore it entirely (`ETimeOfficeService.php:710-712`).

**Status derivation**
13. Presence = any punch; there is no minimum-hours, minimum-sessions or
    must-have-both-in-and-out gate on presence anywhere.
14. There is no stored per-day status at all — nothing can be marked
    Present / Half-day / Absent / On-duty / WFH / Comp-off / Weekly-off / Holiday as a
    fact; every consumer re-derives, differently.
15. The report emits **no row** for a zero-punch day, so absence, holiday and week-off can
    never appear on it (`AttendanceReportController.php:119-123`).
16. `half day` exists only as a payroll *day-cut fraction*, never as an attendance status.
17. Comp-off does not exist (only a dead enum value in the legacy `attendance` table).
18. On-duty and WFH exist only as correction *sub-types*; once approved they become
    ordinary `correction` punches indistinguishable from any other
    (`AttendanceCorrectionService.php:304-314`).

**Late / penalty**
19. Only three tiers L1–L3; no fourth tier, no per-tier grace, no per-tier notification.
20. Late-but-below-L1 is silently charged at `l1_cut` (`PayrollGenController.php:494`).
21. The free-lates window is the payroll month; no rolling N-day window, no quarter, no
    per-tier free counts (`PayrollGenController.php:453, 475`).
22. Break penalty granularity is fixed at 30-minute blocks × 0.25 day
    (`PayrollGenController.php:469`).
23. Late-policy scope is only company / team / employee — no branch, department,
    designation, grade, employment type or location scope; and no effective dating
    (`PayrollGenController.php:279-295` and its three clones).
24. Ties between two same-scope policies are resolved by row order.
25. Early-departure has no penalty rule at all (`early_min` is display only).
26. Overtime is never derived from punches — `overtime_min` is display only and the
    `overtime` register is a manual master (`MasterController.php:339-345`).
27. The late email's recency window (today/yesterday only) is hard-coded
    (`LateArrivalService.php:90-97`); no digest, no escalation, no repeat-offender rule,
    no SMS/WhatsApp channel (`channel` column is always `'email'`).
28. The Points late threshold 09:30 with no grace (`PointsController.php:25`).
29. The PDF's red-break threshold 90 minutes (`attendance-report-pdf.blade.php:67`).

**Absence / week-off / holiday**
30. `working_days` is a flat ISO-weekday list per tenant — no per-employee, per-branch or
    per-shift weekly-off pattern, no alternate-Saturday support in the *attendance* layer
    (`AbsenceService.php:45`; the Saturday policy exists only in payroll's `isWeekOff`).
31. A holiday is tenant-wide; `holidays.applies_to` is ignored
    (`AbsenceService.php:225-236`).
32. The absence sweep never runs for a NULL-tenant install
    (`AbsenceService.php:96-97`).
33. Employee status must be exactly `'active'` (`AbsenceService.php:122`).
34. The sandwich rule lives only in payroll, is a single on/off flag, and has no
    configurable definition (which off-days, how many consecutive absences, whether leave
    counts) (`PayrollGenController.php:770-813`).
35. There is no WFH concept, no on-duty register, no short-leave, no permission/gate-pass.

**Corrections**
36. The seven sub-types are a PHP constant (`AttendanceCorrectionService.php:35-43`).
37. The approval chain is single-level `ApprovalService::resolveApprover`; no multi-level,
    no escalation, no SLA, no delegation (`:142`).
38. One open request per employee-day is hard-coded (`:134-139`).
39. No limit on how many corrections an employee may raise per month, and no
    backdating window (any past date is allowed).
40. The 2-minute clock-skew tolerance (`:125`).
41. The 300-row list cap with no pagination (`:349`).
42. Correction punches coexist with device punches; there is no "replace the day" mode.
43. A correction cannot express an overnight out.
44. Reviewer roles are the `FeaturePermissions::MAP` constant
    (`FeaturePermissions.php:49-52`).

**Bulk / manual**
45. Bulk row cap 3 000 and chunk 500 (`AttendanceBulkController.php:168-170, 253`).
46. Approval is `admin` only; upload is `admin`/`hr_manager` (`:25-33`).
47. The two template column layouts and the accepted direction tokens
    (`:64-85, 209-210`).
48. Date formats tried, and their order (`d/m/Y` before `m/d/Y`) (`:139`).
49. Approved bulk rows write **no `company_id`** (`:330-338`).
50. The manual master allows `log_date` and `punch_at` to disagree with no validation.

**Reports**
51. The report has no configurable columns, no saved views, no scheduling, no XLSX, no
    muster-roll PDF; the PDF column set is fixed in the blade.
52. Report filters (company/branch/team/reporting/leader) exist on the **PDF only**
    (`AttendanceReportController.php:586-591`); the JSON endpoint has none.
53. The PDF has **no late column** — `attendanceFlags()` is called only by the JSON path
    (`:147` vs `:568`).
54. Rating scale fixed 1–10 (`:495`).

**Cross-cutting**
55. One `APP_TIMEZONE` for the whole install; no per-tenant, per-company or per-device
    timezone (`config/app.php:68`).
56. Retention: quarantined and resolved `attendance_pending` rows, `biometric_unmapped`
    rows and `late_notifications` rows are never purged; there is no attendance retention
    policy.
57. There is no attendance recalculation command of any kind.
58. There is no attendance approval/lock/freeze concept independent of payroll.

---

## 6. Defects, conflicts and technical debt

### DEFECT-B-01 · The attendance date is never shift-aware — night shifts are destroyed
**What** — `log_date` is `punch_at`'s calendar date on every writer
(`ETimeOfficeService.php:526, 761`; `PunchIngestService.php:146, 251`;
`AttendanceReportController.php:304`; `DeviceController.php:149`;
`AttendanceCorrectionService.php:311`; `AttendanceBulkController.php:331, 337`).
`pairPunches()` groups strictly by `emp_code|log_date`
(`AttendanceReportController.php:119-123`) and so never sees both halves of a
cross-midnight shift.
**Evidence of intent to the contrary** — `ShiftResolver.php:21-22, 62` computes a `night`
flag; `AttendanceReportController.php:730-733` advances the shift end by a day;
`shifts.night_allowance` pays "per night actually worked".
**Impact** — a 22:00–06:00 shift produces: day 1 with an open session, `last_out = —`,
`total_work = 0` and ~480 minutes of phantom `early_min`; day 2 with an orphan OUT.
Two "present days" are counted for one shift; LOP, late-cuts and night allowance are all
wrong. The 30 Sep IN and the 1 Oct OUT fall in different payroll months.
**Severity — CRITICAL.**

### DEFECT-B-02 · A night-shift worker can never be marked late
`late_min = max(0, first_in_min − (shift_start_min + grace))` with
`first_in_min = hour*60 + minute` (`AttendanceReportController.php:741, 834-836`). For a
22:00 shift (`shift_start_min = 1320`) an arrival at 00:15 gives
`max(0, 15 − 1320) = 0`. Every post-midnight arrival on a night shift is "on time";
every punctual 22:00 arrival is simultaneously charged ~8 h of early exit by DEFECT-B-01.
**Severity — HIGH.**

### DEFECT-B-03 · `attendance_pending` is two features sharing one table name
`AttendanceBulkController::ensure() :35-56` versus
`2026_08_16_000300_create_attendance_pending_table.php:28-43`. On a normally migrated
install the bulk upload writes columns that do not exist and omits a NOT NULL column, so
**bulk attendance upload is broken**, surfacing a raw SQL message in a 422
(`AttendanceBulkController.php:260-262`). When both shapes coexist (bulk table created
first, then repaired by `2026_08_17_000100`), bulk rows pollute the Pending Punches screen
permanently and quarantine rows appear as a NULL-batch pseudo-batch an admin can approve
(`ApiKeyController.php:400-407`; `AttendanceBulkController.php:276-281`).
**Severity — HIGH.**

### DEFECT-B-04 · `attendance_logs` is created in three places with three shapes
Migration/report shape vs. `DeviceController::ensureLogsTable() :209-227` (everything
nullable, `direction` varchar(255), **no `(emp_code, log_date)` composite index**). On an
install where the device controller created it first, every report and payroll query does
a full scan. **Severity — HIGH (performance + integrity).**

### DEFECT-B-05 · Late-policy resolution is implemented four times, with different defaults
`LateArrivalService::effectivePolicy() :163-214`,
`AttendanceReportController::attendanceFlags() :800-822`,
`TodayController::lateFlag() :112-134`,
`PayrollGenController::resolvePolicy() :272-319`.
Only the report and payroll copies consult `ShiftResolver`; the email and the Today panel
use the policy's `shift_start` (default `'09:30'`) with **no shift awareness at all**
(`LateArrivalService.php:208`; `TodayController.php:138`). The base migration's
`grace_min` default is 15 (`2026_01_01_000200:64`) but every reader defaults it to 0.
A night-shift employee is therefore judged against 09:30 by the email and against 22:00
by the report, for the same day. **Severity — HIGH.**

### DEFECT-B-06 · `late_policy` is half-migrated
The base migration creates six columns (`2026_01_01_000200:61-73`). Every column the
readers require — `scope`, `scope_target`, `mode`, `shift_start`, `shift_end`,
`half_day_hours`, `cut_mode`, `cut_n`, `l1_min`…`l3_cut`, `break_budget`, `break_cut`,
`company_name` — is created only when the generic master screen first saves a policy
(`MasterController::ensureCols()`, def `:237-241`). A site that has never opened the Late
Policy screen has a table that cannot express any rule the code implements; every reader
compensates with `Schema::hasColumn` guards or `?? 0`, so the failure is silent.
**Severity — HIGH.**

### DEFECT-B-07 · `weekoff_action` and `no_cut_on_weekoff` have no reader
Created by `2026_01_01_000200:69-70`, written by the master screen
(`MasterController.php:238-239`), read nowhere (grep across `app/`). Week-off suppression
is driven entirely by `roster.status = 'off'`. An HR manager configuring "no cut on
week-off" gets no behaviour. **Severity — MEDIUM.**

### DEFECT-B-08 · The pairing rule is implemented twice and already disagrees
`AttendanceReportController::pairPunches() :621-712` vs
`PayrollGenController::dayStats() :346-405`. Both docblocks assert they must never
disagree. They already do: `pairPunches` records a break for every inter-session gap
(`:686-690`); `dayStats` accumulates a break only when a session is re-opened
(`:384-388`). A day whose last punch is an unclosed IN yields different break totals, and
break totals drive a payroll day-cut. **Severity — HIGH.**

### DEFECT-B-09 · Three "full day" constants and four presence definitions
`STANDARD_WORK_MIN = 480` (`AttendanceReportController.php:28`), `full_day_hours` default
9 (`PayrollGenController.php:308`), `half_day_hours` default 4.5 (`:309`), plus the
shift's own `full_day_hours`. `logs()` uses the raw 480 while `summarise()` uses the
shift (`:457` vs `:736`). Presence is defined four times (report, payroll, absence,
points). **Severity — MEDIUM.**

### DEFECT-B-10 · The report never sees holidays, and cannot show a zero-punch day
`buildReport()` and `reportPdf()` never query `holidays` and emit a row only for
employee-days that have punches (`:119-123, 547-550`). A holiday, a week-off with no
punch, an approved-leave day and a plain absence are all equally invisible. The `weekoff`
flag exists only on rows that already have punches, and only in the JSON path
(`:158` — absent from the PDF row builder at `:570-582`). **Severity — HIGH** for any
customer expecting a muster roll.

### DEFECT-B-11 · The report advertises a shift it does not use
`buildReport()` returns `shift: {start: '09:30', end: '18:30'}` from class constants
(`:170`, and `:603` in the PDF), while every row is judged against its own resolved shift.
**Severity — LOW** (cosmetic, but actively misleading for a night-shift workforce).

### DEFECT-B-12 · `report()` and `reportPdf()` are parallel implementations
`:82-173` and `:517-609`. Only the PDF applies the company/branch/team filters; only the
JSON calls `attendanceFlags()` (so the **PDF has no late column**); only the JSON guards
`ensureDemo()` with try/catch (`:99-102` vs `:533`, so a demo-seeding failure 500s the
PDF download); only the JSON has a whole-body error trap. **Severity — MEDIUM.**

### DEFECT-B-13 · The Today panel's working hours contradict the report
`TodayController.php:51-53` computes first-to-last elapsed time including breaks; the
report computes paired worked time. Same day, two numbers (9.33 h vs 8h 50m).
**Severity — MEDIUM** (employee-facing).

### DEFECT-B-14 · The late email uses the earliest punch of any direction
`LateArrivalService.php:112-117` orders by `punch_at` with no direction filter. A stray
leading `out` (very common on a mis-configured 2-device site) becomes the "arrival".
**Severity — MEDIUM.**

### DEFECT-B-15 · Inline SMTP still blocks three ingest paths
`NotifyLateArrivals` exists specifically because inline `Mail::raw` blocks the response,
times the sender out, and the retry manufactures duplicate punches
(`NotifyLateArrivals.php:14-22`). Still inline at `ETimeOfficeService.php:777`,
`AttendanceBulkController.php:353`, `DeviceController.php:177`. **Severity — MEDIUM.**

### DEFECT-B-16 · The absence sweep never runs on a NULL-tenant install
`AbsenceService.php:96-97` uses `->pluck('tenant_id')->filter()`, dropping NULL. Every
other service in this area uses the symmetric `$tid ? … : whereNull(...)` idiom.
**Severity — MEDIUM** (the feature silently does nothing for self-hosted customers).

### DEFECT-B-17 · Review permission is broader in the list than in the decision
`AttendanceCorrectionController::canReview() :153-162` accepts `hr`; the
`attendance.correction.review` capability does not (`FeaturePermissions.php:49-52`). A
user with role `hr` sees everyone's corrections and gets 403 on approve/reject.
**Severity — LOW.**

### DEFECT-B-18 · Correction punches coexist with device punches
`writeBack() :295-301` deletes only `source='correction'` rows. A `missed_out` correction
for a day that already has an erroneous 13:00 OUT produces interleaved sessions the
employee never worked. **Severity — MEDIUM.**

### DEFECT-B-19 · `PunchIngestService::replayPending()` is dead code
No caller anywhere in the repo (verified by grep). Three docblocks name it as the live
mechanism (`2026_08_16_000300:17`, `ETimeOfficeService.php:503`, `ApiKeyController:33`).
The live path is `ETimeOfficeService::promotePending()`, whose contract differs
(`updateOrInsert` vs `insertOrIgnore`, matches `device_code` OR `device_user_id`, honours
`device_sn`). Anyone reading the comments implements against the wrong contract.
**Severity — LOW (correctness of documentation), MEDIUM (maintenance).**

### DEFECT-B-20 · SBB quarantine rows carry no `device_code`
`PunchIngestService::quarantine() :368-371` never sets `device_code`, although
`attpending_unique (tenant_id, device_code, punch_at, source)` and `promotePending`'s
primary match are both built on it. The unique index is therefore ineffective for SBB
rows. Recovery works only via the secondary `LOWER(device_user_id)` match
(`ETimeOfficeService.php:476-479`). **Severity — MEDIUM.**

### DEFECT-B-21 · `direction` is still part of the identity on the LAN path
`DeviceController::writeLog() :193` keeps `direction` in the `updateOrInsert` match keys —
exactly what rev173g removed everywhere else because it manufactured duplicates. It also
uses `log_date` rather than `punch_at` in the key, so only one IN and one OUT can ever
exist per employee-day-source, and it collapses a whole day to `min(time)`/`max(time)`
(`:140-158`) — **intra-day punches on that path are never stored at all**.
**Severity — HIGH** if the path is live (see OQ-B-05).

### DEFECT-B-22 · Silent drops on the unauthenticated paths
`PushController::importAttlog() :116-124` skips a line with a blank PIN or an unparsable
date with a bare `continue` and reports only the imported count; `DeviceController` drops
unmatched employees with no quarantine (`:163-166`); `ETimeTrackLiteService` and
`GenericApiService` yield no punch for an unparsable record. The per-punch verdict
contract exists only on SBB/SmartEPT. **Severity — HIGH** (evidence loss with no signal).

### DEFECT-B-23 · Runtime DDL on hot request paths
`AttendanceReportController::ensureTables()/ensurePunchColumns()`,
`AttendanceBulkController::ensure()`, `AttendanceCorrectionService::ensureSchema()`,
`LateArrivalService::ensureTable()`, `ETimeOfficeService::recordUnmapped()`,
`DeviceController::ensureLogsTable()`, `PushController::ensure()`. On MySQL, DDL
implicitly commits — a `CREATE TABLE` reachable inside `PunchIngestService`'s
`DB::transaction()` (`:72-169`) would break that batch's atomicity guarantee.
`Schema::hasTable`/`hasColumn` also run on every request. **Severity — MEDIUM.**

### DEFECT-B-24 · `attendance:dedupe` loads the whole table into memory
`DedupeAttendance.php:42-45` uses `->get()` over all of `attendance_logs` with no
chunking, while the two migrations doing the same job chunk at 2 000
(`2026_08_16_000200:139-151`, `2026_08_17_000100:218-229`). **Severity — MEDIUM.**

### DEFECT-B-25 · A migration silently deletes production punches
`2026_08_16_000200_extend_attendance_logs_for_sbb.php:83-84` calls
`Artisan::call('attendance:dedupe')` — a **hard DELETE across the whole table** — during
`php artisan migrate`, with no operator confirmation, no dry-run, no backup, and no record
of which rows were removed. `2026_08_17_000100:152-154` does the same inline.
**Severity — HIGH.** See Q7.

### DEFECT-B-26 · Missing index on `source`
`attendance_logs` indexes `tenant_id`, `company_id`, `emp_code`, `log_date`,
`(emp_code, log_date)`, `device_sn` — but **not `source`**, which is filtered by
`sequenceDirections() :637`, `writeBack() :300`, `PurgeDemoAttendance` and every
`updateOrInsert` match key. `attendance_pending` has no index on
`(tenant_id, resolved_at)`, the exact filter used by Pending Punches
(`ApiKeyController.php:402-403`). **Severity — MEDIUM.**

### DEFECT-B-27 · N+1 and full-table reads in the report
`employeeMeta() :209-216` loads every employee **twice** (once for the columns, once for
an `id → name` map). `loadLatePolicies() :763-775` loads every policy row and resolves per
employee in PHP. `sequenceDirections() :632-642` issues one SELECT per employee-day and
one UPDATE per changed row. **Severity — MEDIUM.**

### DEFECT-B-28 · The three-way legacy status mismatch
Table enum `{full, half, absent, compoff}` vs model `$fillable` naming non-existent
`check_in`/`check_out` vs controller validation `{present, absent, leave, holiday,
weekoff}` — §2.4. Dead, but it is what a reader finds when searching for "attendance
status". **Severity — LOW (dead code), HIGH (misleading).**

### DEFECT-B-29 · The Points module contradicts the Late Policy
`PointsController.php:25, 164-168` — 09:30 with no grace, no policy, no shift; and
`monthMeta() :239-263` counts working days as "not Sunday, not a holiday", ignoring
`weekly_off_day` and `sat_off_mode`. An employee loses points for a day the Late Policy
considers on time, and is docked `absent = working − present` points against a
Sunday-only calendar. **Severity — MEDIUM.**

### DEFECT-B-30 · The correction "carry into the next adjustment" is not implemented
`AttendanceCorrectionService::decide() :262` tells the employee the change "will be
carried into the next adjustment" (`notifyEmployee` map at `:433`). **No code anywhere
creates such an adjustment.** The row sits at status `approved`, `payroll_locked = true`,
and nothing consumes it — grep finds no reader of `attendance_corrections.payroll_locked`
outside the listing (`:365`). **Severity — HIGH.** See Q6.

### DEFECT-B-31 · `PayrollLock::isPeriodFinalised` uses MySQL-only SQL and the wrong period
`PayrollLock.php:64` uses `DATE_FORMAT(pay_date, '%Y-%m')` — on SQLite this throws, and
the catch at `:72-74` returns `false` (**fails open** → the correction is applied on a
locked period). It also matches on the **pay date's month**, not the attendance period, so
under a 21st–20th cut-off (`PayrollGenController.php:118-126`) a correction for the 25th
maps to the wrong run. **Severity — HIGH.**

### DEFECT-B-32 · `sandwichDays()` indexes the wrong month under a cut-off period
`PayrollGenController.php:780-801` builds dates as
`Carbon::create($start->year, $start->month, $day)` and iterates `$d` from
`max(2, dojDay+1)` to `daysInMonth-1`, where `$daysInMonth` is a *count of days in the
period* under a cut-off run (`monthMeta() :177`). For a 22 Feb–21 Mar period this
constructs February dates for a 28-day span that actually spans two months.
**Severity — MEDIUM** (only when `sandwich_rule` is on and a cut-off day is set).

---

## 7. Security findings

### FINDING-B-SEC-01 · IDOR on the per-day punch drill-down — HIGH
`AttendanceReportController::logs() :441-484`, route `routes/web.php:414`.
Takes `{code}` and `{date}` straight from the URL, applies only the tenant filter, and
does **not** call `AppDataController::selfScope()` (which `report() :104` and
`reportPdf() :535` both do) and has **no role guard**.
**Exploitability** — trivial: any authenticated employee changes the code segment.
**Impact** — full punch timeline, break pattern, plus the manager's private `rating` and
free-text `remarks` for any colleague on any date.

### FINDING-B-SEC-02 · Cross-tenant overwrite of attendance ratings — MEDIUM
`AttendanceReportController::saveRating() :501-511` upserts on `(emp_code, log_date)` with
**no `tenant_id`**, against a table whose unique index is also `(emp_code, log_date)`
(`:57`) even though the table has a `tenant_id` column and reads filter on it (`:459-461`).
**Exploitability** — passive on a shared cloud where two customers both use `EMP001`; also
a deliberate covert write channel.
**Impact** — silent cross-tenant corruption of performance ratings and remarks; the losing
tenant sees nothing because its read *is* tenant-filtered.

### FINDING-B-SEC-03 · Authorisation helper fails open — HIGH
`ApprovalService::denyUnlessRole() :365-380` catches `\Throwable` from the role check and
`return null`, which every caller reads as "allowed". This guards
`AttendanceBulkController::uploadGuard()/approveGuard() :25-33` and
`AttendanceReportController::saveRating() :488`.
**Exploitability** — requires a fault in the roles/permissions pivot (a migration in
flight, a missing table, a DB hiccup) — not directly attacker-triggered, but a
denial-of-integrity amplifier.
**Impact** — bulk attendance approval, device configuration and rating writes open to any
authenticated user.

### FINDING-B-SEC-04 · The in-app punch trusts an unvalidated `source` and has no dedup — MEDIUM
`AttendanceReportController::punch() :258-262, 299-321`.
`source` is `nullable|string|max:20` with **no whitelist**; the insert is a plain `insert`
with no idempotency; the route carries **no throttle** (`routes/web.php:361`).
**Exploitability** — trivial, authenticated, self-service.
**Impact** — a caller can (a) inject unbounded punches for their own `emp_code`, inflating
present days and worked minutes into payroll; (b) tag a punch `demo` so
`attendance:purge-demo` destroys it; (c) tag it `correction` so the next correction
write-back deletes it (`AttendanceCorrectionService.php:295-301`); (d) tag it as another
channel to corrupt the single-device sequence, which is scoped by `source`
(`ETimeOfficeService.php:637`).

### FINDING-B-SEC-05 · Unauthenticated, CSRF-exempt punch injection on `/iclock` — HIGH
`routes/web.php:89-106`; `PushController.php:38-54`. No shared secret, no signature, no
IP allow-list; the serial number is the only "credential" and it is printed on the device.
Anyone who learns a serial can POST arbitrary ATTLOG lines that become real attendance for
that device's tenant, feeding present days, late-cuts and LOP.
Compounded by `PushController::rowForSn() :201-228`, which **auto-inserts** a
`biometric_configs` row for any novel `SN` on an unauthenticated request
(unbounded at `throttle:1000,1` per IP), and `touch() :230-242`, which writes on every poll.
**Acknowledged design trade-off** ("a device cannot send a token", `:31-33`) but it is the
single largest integrity hole in the attendance data.

### FINDING-B-SEC-06 · Mapping backfill can claim NULL-tenant punches — MEDIUM
`BiometricConfigController.php:641-655`: the historic re-keying `UPDATE` is
`WHERE LOWER(emp_code) = <device id> AND (tenant_id = <tid> OR tenant_id IS NULL)`.
Punches written by an unassigned device (`tenant_id` NULL — the state every
auto-registered `/iclock` device starts in) are claimable by whichever tenant maps that
numeric PIN first. The update is unbounded in date range and rewrites `emp_code`,
`emp_name`, `tenant_id`, `company_id` in place.
**Impact** — one customer absorbs another's pre-assignment punch history; the original
rows are gone (see Q1).

### FINDING-B-SEC-07 · Exception text returned to the client — LOW
`AttendanceReportController::report() :69-79` (returns HTTP **200** with the raw
`$e->getMessage()`); `AttendanceBulkController` `:260-262, 303-305, 367-369, 386-388`;
`AttendanceCorrectionController` `:40-42, 66-68, 97-99, 115-117, 147-149`;
`AbsenceController` `:33-35, 55-57, 76-78, 98-100`; `TodayController :67-69`.
Schema errors leak table and column names and SQL fragments to any authenticated user.

### FINDING-B-SEC-08 · No rate limiting on any authenticated attendance endpoint — LOW
`routes/web.php:352-361, 414-415, 586-590` carry no `throttle`. Combined with
FINDING-B-SEC-04 this permits unbounded self-punch insertion; combined with
FINDING-B-SEC-01 it permits fast enumeration of every colleague's timeline.

### Checked and NOT found (recorded so the absence is evidence)
No SQL string interpolation with user data — every `whereRaw` in the assigned files uses
bound parameters (`AttendanceCorrectionService.php:298, 385`; `LateArrivalService.php:104,
114, 283`; `TodayController.php:36`; `AbsenceService` uses the builder throughout;
`ETimeOfficeService.php:314-320, 477-478, 485`). No `{!! !!}` in
`attendance-report-pdf.blade.php`. No `exec`/`shell_exec`/`unserialize` in scope. No file
upload in scope (bulk is parsed in the browser and posted as JSON). Constant-time
comparison is used for both credential types.

---

## 8. Documentation-vs-source discrepancies

| # | Reference doc claim | Source | Verdict |
|---|---|---|---|
| 1 | Section "2.6 Working-day and holiday determination" (doc :53400-53430) says `monthMeta()`'s Sunday-only figure is *"the `workingDays` used as the LOP denominator for the whole run"*, and R3 (:53600) prices a worked example on it | `PayrollGenController.php:1096, 1562, 1976, 2090, 2476` all destructure `monthMeta()` as `[$start, $days, , $end]` — the working-day element is **discarded at every call site**; `compute()` recomputes `$working` at `:1118-1127` using the configurable `isWeekOff()` **and** the holiday map | **DOC WRONG.** The live LOP denominator is the configurable, holiday-aware one. The conflict is real but narrower: the dead Sunday-only calculator survives in `PayrollGenController`, and the live third definition is `PointsController::monthMeta() :239-263`, which *is* consumed (`PointsController.php:74`) |
| 2 | Doc §2 comparison matrix (:5416) lists **seven** ingest paths | There are **nine distinct writers** to `attendance_logs`: the seven listed plus the Manual Punch master (`MasterController.php:472-481`) and the correction write-back (`AttendanceCorrectionService.php:283-327`), which the doc folds into "Path G" | Doc understates; both extra writers have distinct identity and dedup semantics (neither has any) |
| 3 | Doc §1.2 / FINDING-ATT-07 says the two `attendance_pending` schemas are *"mutually exclusive"* | When the bulk shape is created first and `2026_08_17_000100_repair_sbb_schema.php:105-118` then adds the quarantine columns, **both coexist** and cross-contaminate two screens (`ApiKeyController.php:400-407`; `AttendanceBulkController.php:276-281`) | Doc misses the coexistence failure mode — recorded here as NEW-ATT-D01 / DEFECT-B-03 |
| 4 | Doc DEBT-ATT-15 says the two pairing implementations "already differ in one respect" and describes it as `dayStats()` only accumulating a break when a session is re-opened | Confirmed at `PayrollGenController.php:384-388` vs `AttendanceReportController.php:686-690` | **DOC CORRECT** — recorded as DEFECT-B-08 |
| 5 | Doc §6.2 says `late_policy.grace_min` default is "`0` in the report/late-email readers; base migration default is `15`" | Confirmed: `2026_01_01_000200:63` default 15; `LateArrivalService.php:211`, `AttendanceReportController.php:831`, `TodayController.php:141`, `PayrollGenController.php:304` all `?? 0` | **DOC CORRECT** |
| 6 | Doc §8.5 / BR-ATT-033 says a locked-period correction "will be carried into the next adjustment" | No code creates any adjustment; `attendance_corrections.payroll_locked` has no reader outside `listFor() :365` | **DOC (and the product's own user-facing message) OVERSTATES** — DEFECT-B-30 |
| 7 | Doc §12 CALC-ATT-04 gives the payroll late formula as `cutoff->diffInMinutes(firstIn)` | Confirmed `PayrollGenController.php:437-439` | **DOC CORRECT**, but the doc does not note that this date-anchored form and the report's minutes-since-midnight form diverge across midnight |
| 8 | Doc §13 T12/T13 mark the correction and bulk `"$date $HH:MM:00"` concatenation as "safe" | It is safe against timezone corruption but structurally prevents any overnight pair; `strcmp` ordering at `AttendanceCorrectionService.php:115, 234` and `$out <= $in` at `AttendanceBulkController.php:238` make an overnight correction impossible to express | Doc's "safe" label understates the functional limit |
| 9 | `BUSINESS_RULES_CATALOGUE.md:206` marks BR-ATT-710 ("Bulk upload row cap of 3,000") as `NOT IMPLEMENTED` | `AttendanceBulkController.php:168-170` implements exactly that cap | **CATALOGUE WRONG** (a duplicate rule recorded from a client-side analysis; the server-side rule from section 06 records it correctly) |
| 10 | Doc DEBT-ATT-25 lists `AttendanceController` and the `Attendance` model as "legacy dead code" | Correct, but the doc does not record the **three-way schema mismatch** between the table enum, the model's `$fillable` (`check_in`/`check_out` — columns that do not exist) and the controller's status whitelist | Doc understates — DEFECT-B-28 |
| 11 | Doc §1.3 states `attendance_ratings` unique is `(emp_code, log_date)` "not tenant-scoped" | Confirmed `AttendanceReportController.php:57` | **DOC CORRECT** |
| 12 | Doc §15 DEBT-ATT-26 lists the magic numbers | Confirmed and extended here: the doc omits `cut_n` default 3 (`PayrollGenController.php:307`), the break penalty's 30-minute / 0.25-day granularity (`:469`), the geofence grace ladder `{0, 1.0, 2.0}` (`AttendanceReportController.php:431`), and the `filter()`-drops-NULL tenant rule | Doc incomplete |
| 13 | `sbb-schema-status.txt` (quoted at doc :7864) reports the dev DB timezone as `Asia/Kolkata` | `config/app.php:68` defaults to **UTC**; only `.env.example:5` / `.env.production.example:14` set Asia/Kolkata | Consistent, but the doc's framing ("the verified dev database reports…") understates that an install missing the `.env` line silently runs UTC |

---

## 9. Migration verdict per capability

| Capability | Verdict | Rationale | Data migration implication |
|---|---|---|---|
| `attendance_logs` as an immutable punch ledger | **RETAIN (concept) / REDESIGN (schema)** | one-row-per-punch with a natural key is right; three creators, an inert unique index on NULL tenants, no `source` index, in-place mutation of `direction`/`emp_code`/`log_date`, and no soft delete are not | Migrate punches 1:1. Add a surrogate immutable `punch_uid`, a non-null tenant, a `captured_tz`, and an append-only mutation log. Reconcile the three shapes first (`SHOW CREATE TABLE` per install). |
| Attendance-day (`log_date`) derivation | **REPLACE** | calendar-date-only is the root cause of the night-shift defect class | Recompute a shift-aware `attendance_date` for every historic punch — requires the historic roster/shift, which the name-keyed `roster` may not support. Expect a manual reconciliation for night-shift employees. |
| Seven-to-nine ingest adapters | **REDESIGN into one pipeline** | the business capability (many device brands, one store) is essential; nine independent implementations of validate → parse time → resolve employee → dedup → land are not | None (adapters hold no state). `biometric_configs` migrates with a per-connection recipe. |
| `/iclock` unauthenticated receiver | **RETAIN with hardening** | real hardware needs it; the trade-off is understood | Add a per-device shared key / IP allow-list and an explicit claim step; existing auto-registered NULL-tenant rows must be reviewed and claimed or purged. |
| `attendance_pending` quarantine | **RETAIN / RENAME** | quarantining rather than discarding is correct and hard-won | Rename to `punch_quarantine`; move bulk rows to `attendance_bulk_rows`. Split existing rows by shape. |
| `attendance_pending` as the bulk staging table | **REPLACE** | name collision breaks one feature or the other | New table; historic bulk batches can be discarded (they are transient) after confirming none are unapproved. |
| `ShiftResolver` | **REDESIGN** | precedence model is sound; name-keyed roster, no rotation patterns, `night` inferred from `end <= start`, no unique constraint on shift name are not | Re-key `roster` from display name to `employee_id`; resolve duplicate-name collisions manually. |
| Late Policy (`late_policy`) | **REPLACE** | half-migrated schema, four duplicate resolvers, three scope levels, no effective dating, a hidden L1 fall-through rule | Map each row into rule-engine rules; the `weekoff_action`/`no_cut_on_weekoff` columns have no meaning to carry over. |
| Late-arrival notification | **REDESIGN** | correct as a concept (opt-in, once per day, fail-soft); wrong in that it ignores the resolved shift, uses any-direction first punch, and is inline on three paths | `late_notifications` migrates as a dedup ledger. |
| Absence sweep | **REDESIGN** | classification logic is clean and unit-tested; tenant discovery, holiday scoping, exact-case status and the missing WFH concept are not | `app_settings.absence_notify` JSON maps to a rule. |
| Attendance corrections (F2) | **RETAIN / REDESIGN** | the orig/requested/approved time triple is the strongest audit artefact in the area and must survive; single-level approval, 300-row cap, no overnight support, and the phantom "next adjustment" must not | Migrate `attendance_corrections` whole. Rows sitting at `approved` + `payroll_locked` are **unprocessed liabilities** — they need an explicit disposition decision before cutover. |
| Bulk upload with approval gate | **RETAIN / REDESIGN** | approval-before-punch is right; browser-side parsing, the shared table, the missing `company_id` and the absent dedup are not | Transient data; no migration. |
| Manual Punch master | **REDESIGN** | needed, but must not allow `log_date` and `punch_at` to disagree silently | None. |
| In-app self punch + geofence | **RETAIN / REDESIGN** | needed; no dedup, no throttle, client-supplied `source` and the `USER-<id>` fallback are defects | Identify and quarantine every existing `emp_code LIKE 'USER-%'` row before migration. |
| Daily Attendance Report + PDF | **REPLACE** | two parallel implementations, no zero-punch rows, no holiday awareness, PDF has no late column, filters on one path only | Derived — no data migration. |
| `attendance_ratings` | **MERGE** into a general per-day annotation/exception store | a 1–10 rating is one kind of day annotation; the table is cross-tenant unsafe | Migrate with `tenant_id` backfilled from `attendance_logs`; resolve collisions where two tenants share an `emp_code`. |
| Legacy `attendance` table + `Attendance` model + `AttendanceController` | **REMOVE** | dead, unrouted, and its three status vocabularies actively mislead | Verify the table is empty on every install, then drop. |
| `DeviceController` LAN path | **REMOVE or REPLACE** | the library is not in `composer.json`; the path aggregates a day to one in/out pair, keeps `direction` in the identity, and discards unmatched punches | Confirm no install has the package (`composer show`); if any does, its historic rows have no intra-day detail and cannot be reconstructed. |
| `attendance:dedupe` | **REPLACE** | destructive, unlogged, unbounded memory, and invoked automatically by a migration | Before cutover, take a full `attendance_logs` snapshot; the SmartPRS 3 equivalent must quarantine, not delete. |
| Demo punch fabrication | **REMOVE** | fabricating attendance inside the product that feeds payroll is a category error, however well guarded | Purge `source='demo'`; rows fabricated before rev 51 are tagged `biometric` and are **unrecoverable** (`PurgeDemoAttendance.php:16-18`). |
| Points attendance heuristics | **MERGE** into the one attendance status engine | a fourth present/late/working-day definition | Derived — no data migration. |

---

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
