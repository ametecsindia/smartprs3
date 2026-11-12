# PART XI — ATTENDANCE AND BIOMETRIC ARCHITECTURE

*Covers brief Sections 15, 16, 17, 18, 19, 22, 23, 24, 25 and 26.*
*Conforms to spine S10 (attendance data architecture and the device adapter contract),
S5–S7 (SPX, rule engine, formula engine), S8 (workflow), S13 (security), S14 (audit),
S16 (data conventions), S17 (API and integration).*
*Evidence base: `analysis/B-attendance.md` and `analysis/C-biometric-integrations.md`.
Every claim about SmartPRS 2 in this part carries a `file.php:line` citation carried
through from those two files.*

---

## XI.0 Purpose

This part decides three things.

1. **How a punch physically arrives and becomes trusted evidence** — the Device Adapter
   SDK, the connector recipe path, the canonical punch contract, identity resolution,
   tenancy and time. A new device make, model or vendor must be an adapter or a recipe,
   never a change to the attendance engine.
2. **How evidence becomes an attendance day, and how that day can be re-derived** — the
   four-layer architecture of spine S10, with immutable raw evidence, overlay corrections,
   supersedable derived days, and a first-class idempotent recalculation with a preview
   diff.
3. **What an attendance day *means*** — the shift master, the roster, the Universal
   Work-Time Policy Engine, the Time Settlement Engine, absent detection and
   regularisation, all expressed as effective-dated configuration evaluated by the Part VII
   rule engine, and all explainable to the minute.

Everything in this part exists in the shape it does because a specific, evidenced
SmartPRS 2 failure makes the simpler shape untenable. §XI.1 is that justification, and
each design section carries the citation forward in one clause.

---

## XI.1 What SmartPRS 2 does today, and why it must change

Ten findings. This is not a defect dump; each one eliminates a design option that would
otherwise be cheaper.

### XI.1.1 The attendance date is never shift-aware

`attendance_logs.log_date` is the **calendar date of `punch_at`** on every one of the nine
writers (`ETimeOfficeService.php:526, 761`; `PunchIngestService.php:146, 251`;
`AttendanceReportController.php:304`; `DeviceController.php:149`;
`AttendanceCorrectionService.php:311`; `AttendanceBulkController.php:331, 337`;
`MasterController.php:474-480`). `pairPunches()` groups strictly by `emp_code|log_date`
(`AttendanceReportController.php:119-123`), so it never sees both halves of a cross-midnight
shift.

A 22:00–06:00 shift therefore produces: day D with an open session, `last_out = —`,
`total_work = 0` and roughly 480 minutes of phantom `early_min` measured against a shift end
of 06:00 on D+1 that no punch can reach (`summarise():729-735`); and day D+1 with an orphan
OUT. `presentDays()` counts **two present days for one shift**
(`PayrollGenController.php:211-216`). When D is 30 September, the two halves of one night's
work fall in **two different payroll months** (B §Q2, DEFECT-B-01, severity CRITICAL).

The intent to do better exists and is stranded: `ShiftResolver.php:21-22, 62` computes a
`night` flag, `AttendanceReportController.php:730-733` advances the shift end by a day, and
`shifts.night_allowance` pays "per night actually worked" — against a day-grouping that
cannot represent the night.

**Consequence for the design.** The attendance day cannot be a property of the punch. It
must be a **derived, configurable attribution** (§XI.2, §XI.8.4) and the only way to make
that reproducible is to keep raw punches and derived days in separate layers.

### XI.1.2 A night-shift worker can never be marked late

`late_min = max(0, first_in_min − (shift_start_min + grace))` where
`first_in_min = hour*60 + minute` (`AttendanceReportController.php:741, 834-836`;
CALC-B-03/04). For a 22:00 shift, `shift_start_min = 1320`; an arrival at 00:15 computes
`max(0, 15 − 1320) = 0`. Every post-midnight arrival on a night shift is "on time", while
the same punctual 22:00 arrival is simultaneously charged eight hours of early exit by
XI.1.1 (DEFECT-B-02, severity HIGH).

**Consequence.** All late, early, worked, break and OT arithmetic in SmartPRS 3 is on
**absolute instants anchored to the resolved shift start**, never on minutes-since-midnight
(spine S10; R-XI-041).

### XI.1.3 There is no adapter abstraction; provider dispatch is inlined five times

Four providers exist (`push`, `etimetracklite`, `etimeoffice`, `generic`) and the
`if/elseif` that dispatches between them is duplicated in **five places**:
`BiometricConfigController::test():409-450`, `::sync():480-509`,
`ETimeOfficeService::configuredAny():126-137`, `SyncETimeOffice::handle():76-96` and
`:115-119` (DEBT-C-01, severity HIGH). Anything that is not HTTP — a vendor SDK, an SFTP
drop, a serial reader, MQTT, a database link — is impossible (C §5.2 item 1).

The consequences compound. `device_mode` (single reader alternation) is honoured by
**exactly one of six machine paths** (`ETimeOfficeService.php:710-712`; CONF-C-02), so a
single-reader site that moves from `/iclock` to the Smart Biometric Bridge silently loses
alternating direction and every punch becomes `IN`. `in_machine_id` is compared against the
**device serial** on `/iclock` (`PushController.php:152-157`) and against a **machine-number
field inside the record** on the pull paths (`ETimeOfficeService.php:287-290`) — one field,
two meanings, one UI label (CONF-C-03).

**Consequence.** §XI.3 specifies one adapter contract, one shared ingest core, and a
declarative recipe path; the topology rules (direction mode, double-tap window, machine
map) are applied by the **core**, so they hold on every path.

### XI.1.4 Tenant comes from a device serial, and the serial is spoofable

`POST /iclock/cdata?SN=<serial>&table=ATTLOG` is unauthenticated and CSRF-exempt
(`routes/web.php:89-106`; `PushController.php:38-54`). The serial is the only "credential"
and it is printed on the device. An unknown serial **auto-registers** a `biometric_configs`
row with `tenant_id = NULL`, `enabled = true`, on an unauthenticated request
(`PushController.php:201-228`), unbounded at `throttle:1000,1` per IP. `rowForSn()`
(`:207`) looks up `provider='push' AND serial_number=?` **with no tenant filter** and takes
`orderByDesc('id')`, and `serial_number` has **no unique index** — so a tenant that
registers another customer's serial with a higher id captures that device's punches
(SEC-C-01, SEC-C-03, DEF-C-02; both CRITICAL).

The damage is then made permanent by the mapping backfill, which re-keys historic rows
`WHERE LOWER(emp_code) = <device id> AND (tenant_id = ? OR tenant_id IS NULL)`
(`BiometricConfigController.php:641-655`) — so whichever tenant maps a numeric PIN first
absorbs the NULL-tenant history of a device that may belong to someone else (SEC-C-12,
FINDING-B-SEC-06).

**Consequence.** §XI.5: tenant is derived from an authenticated credential only,
`(tenant, serial)` is unique, unknown devices are quarantined by policy, and there is no
NULL-tenant state anywhere (Law 6).

### XI.1.5 The employee-mapping loop is column-major

`ETimeOfficeService::matchEmployee()` loops
`foreach (['emp_code','device_user_id']) { foreach ($candidates) }`
(`:315-345`). The whole `emp_code` column is searched — for both the prefixed and the raw
candidate — **before `device_user_id` is consulted at all**. A raw device PIN that happens
to equal *another* employee's `emp_code` therefore beats the correct, explicitly configured
`device_user_id` mapping. The punch is filed against the wrong person, with no error, and
flows into that person's attendance and payroll. This is near-certain wherever employee
codes are short numerics — `5`, `12`, `101` (DEF-C-01, severity CRITICAL; recorded in no
reference document).

Compounding it: the mapping model is the single column `employees.device_user_id`
(`2026_01_01_000100_create_employees_table.php:153`) — one device ID per employee,
globally, with no notion of "this PIN on this device" (C §2.9, BR-DEV-005).

**Consequence.** §XI.4: identity resolution is an **ordered, candidate-major strategy
list**, and the mapping is `(device, identity_type, identity_value) → employment`, dated.

### XI.1.6 Deduplication is an unlogged hard DELETE invoked inside a migration

`attendance:dedupe` loads the **entire** `attendance_logs` table into PHP memory
(`DedupeAttendance.php:42-45`, no chunking), keeps `MAX(id)` per
`(tenant, emp_code, punch_at, source)` and issues a **hard DELETE** with no soft delete, no
archive, and no record of which rows were removed — only a count (`:80-88`). Provenance is
not merged first, so when the older row is the one carrying `external_id`, `device_sn`,
`device_user_id` or `verify_mode`, that provenance is destroyed.

It is executed **automatically, unattended, during `php artisan migrate`**:
`2026_08_16_000200_extend_attendance_logs_for_sbb.php:128` calls
`Artisan::call('attendance:dedupe')` and `2026_08_17_000100_repair_sbb_schema.php:152-154`
runs an inline equivalent — with no operator confirmation, no dry-run and no backup
(DEFECT-B-25, B §Q7). The migration's own docblock acknowledges that production data
contains duplicates, i.e. it is known that real rows will be destroyed.

Both unique indexes lead with `tenant_id`, and NULL never collides in MySQL, so on every
single-tenant install **both indexes are inert** and de-duplication rests entirely on
`updateOrInsert` (`2026_08_16_000200:86-94`; C requirement 18).

**Consequence.** §XI.2.6: de-duplication **quarantines, never deletes**; idempotency is a
database guarantee that holds because `tenant_id` is `NOT NULL`; and no destructive
operation runs inside a migration.

### XI.1.7 There is no recalculation command of any kind

`routes/console.php` defines exactly four attendance commands: `attendance:sync-etimeoffice`,
`absence:notify`, `attendance:dedupe`, `attendance:purge-demo`. There is no recalculation of
any kind (B §Q1).

Because every consumer derives at read time, this cuts both ways: the report is always
"current", and a policy edited today **silently rewrites last year's report**, since
`late_policy` and `shifts` carry no validity columns at all. Payroll is the mirror image: it
snapshots derived figures into `payslips` at generate time, and a run in
`locked|approved|hr_approved|paid` cannot be regenerated (`PayrollLock.php:31`), so a policy
change can never reach a closed month and no mechanism produces the difference.

**Consequence.** §XI.2.5: `recalculate(scope, range)` is a first-class, idempotent,
previewable command that re-derives layer 3 from layer 2 **plus the rules effective on each
date** — which is only meaningful because configuration is effective-dated (spine S4).

### XI.1.8 Four incompatible weekly-off definitions, none owned by attendance

| # | Definition | Evidence | Live? |
|---|---|---|---|
| 1 | `roster.status = 'off'` → `ShiftResolver::resolve():118-125` | the only form the attendance report knows; surfaces as a boolean only on rows that already have punches (`AttendanceReportController.php:158`) and only in the JSON, not the PDF | yes |
| 2 | `AbsenceService` `working_days`, a flat per-tenant ISO-weekday list, default `[1..6]` | `AbsenceService.php:45, 168-170` | yes |
| 3 | `PayrollGenController::isWeekOff():70-89` — `weekly_off_day` + `sat_off_mode` (`none\|all\|2_4\|1_3`) | the only form that understands alternate Saturdays; **invisible to the attendance layer** | yes |
| 4 | `PointsController::monthMeta():259` — Sunday only | feeds absence-based points deductions | yes |

There is no per-employee, per-branch or per-shift weekly-off pattern, and the attendance
report cannot display a weekly off at all because it emits **no row** for a zero-punch day
(`AttendanceReportController.php:119-123`; NEW-ATT-006, DEFECT-B-10). A holiday is
tenant-wide and `holidays.applies_to` has no reader (`AbsenceService.php:225-236`;
NEW-ATT-011).

**Consequence.** §XI.8.6 and §XI.9.7: one calendar materialisation, one weekly-off
evaluator (Part VII decision point `attendance.calendar.weekly_off`, rule RL-CAL-001), and a
stored row for **every** employee-day, so the muster roll is complete.

### XI.1.9 Comp-off does not exist

A repository-wide grep for `compoff|comp_off|comp-off` returns exactly one hit: the dead
enum value in the legacy, unrouted `attendance` table
(`2026_01_01_000200_create_departments_table.php:52`). There is no accrual, no balance, no
approval, no consumption and no leave type wired to it (B §Q4). A customer who works a
holiday and expects a compensatory day off has no supported path.

Overtime is in the same condition one layer up: `overtime_min` is computed for **display
only** (`AttendanceReportController.php:729-735`, CALC-B-10) and never written to the
`overtime` register, which is a purely manual master (`MasterController.php:339-345`). Early
departure has no penalty rule at all (B §5.2 b.25).

**Consequence.** §XI.10 and §XI.11: comp-off and OT are first-class outputs of the Time
Settlement Engine, produced by ledger actions (`CREATE_COMP_OFF`, `CREATE_OT`) with
compensating-entry reversal semantics (Part VII §6.4).

### XI.1.10 The correction that lands on a locked period is a promise no code keeps

`AttendanceCorrectionService::decide():238-262` sets `status='approved'`,
`payroll_locked=true`, writes **no punches**, and emails the employee that the change *"will
be carried into the next adjustment"* (`:433`). **No code anywhere creates such an
adjustment**; `attendance_corrections.payroll_locked` has no reader outside the listing
projection (`:365`) (DEFECT-B-30, severity HIGH). Production may already hold rows in this
state representing unpaid liabilities (OQ-B-02).

The gate itself is worse than the promise: `PayrollLock::isPeriodFinalised():64` uses
`DATE_FORMAT(pay_date,'%Y-%m')` — MySQL-only; on any other driver it throws and the catch at
`:72-74` returns `false`, i.e. **fails open** and applies the correction to a locked period.
It also matches on the **pay date's month**, not the attendance period, so under a 21st–20th
cut-off a correction for the 25th is tested against the wrong run (DEFECT-B-31).

**Consequence.** §XI.13.5: a correction against a locked period creates a **tracked arrears
record with an owner, an amount basis and a target period**, visible in a queue and
reconciled by the payroll run — never a flag and an email.

### XI.1.11 What SmartPRS 3 explicitly does not replicate

| Not replicated | SmartPRS 2 evidence |
|---|---|
| Calendar-date attendance day | `ETimeOfficeService.php:761` (BR-ATT-201) |
| Minutes-since-midnight late arithmetic | `AttendanceReportController.php:741` (CALC-B-03) |
| Night flag inferred from `end <= start` (a 24-hour shift is "night") | `ShiftResolver.php:62` (BR-ATT-019) |
| Roster keyed by employee **display name** | `ShiftResolver.php:94, 116` (NEW-ATT-004) |
| Presence = any punch, defined four times | `AttendanceReportController.php:119-123`; `PayrollGenController.php:202-220`; `AbsenceService.php:239-254`; `EssController.php:134` (NEW-ATT-005) |
| No stored per-day status | B §1.6 |
| The hidden L1 fall-through charging sub-L1 lateness at the L1 rate | `PayrollGenController.php:494` (BR-ATT-021b) |
| Exactly three late tiers | `PayrollGenController.php:482-495` (BR-ATT-021) |
| `ceil(excess/30) × 0.25` day break penalty with literal block and step | `PayrollGenController.php:469` (BR-ATT-023) |
| Late-policy scope of company/team/employee, ties broken by row order, no effective dating | `LateArrivalService.php:184-204` and three clones (NEW-ATT-019) |
| Unknown direction silently becomes `IN` | `PushController.php:159`; `ETimeOfficeService.php:224-235` (NEW-C-04/06) |
| Silent `continue` on a blank PIN or unparsable date | `PushController.php:116-124` (DEFECT-B-22, DEF-C-17) |
| Whole-day collapse to `min(time)`/`max(time)` | `DeviceController.php:140-158` (NEW-C-19) |
| `direction` inside the write identity | `DeviceController.php:193` (DEF-C-06) |
| Correction write-back that DELETEs prior correction punches | `AttendanceCorrectionService.php:295-319` (DEFECT-B-18) |
| Runtime DDL from request handlers | `AttendanceReportController::ensureTables()`, `PushController::ensure()`, six more (DEFECT-B-23, DEF-C-09) |
| Two features sharing the table name `attendance_pending` | `AttendanceBulkController.php:35-56` vs `2026_08_16_000300:28-43` (CONF-C-01) |
| Inline `Mail::raw` from an ingest path | `ETimeOfficeService.php:777` (DEF-C-03) |
| Demo punch fabrication inside the product that feeds payroll | `AttendanceReportController.php:872-956` (BR-ATT-042) |
| `last_status` showing the last **success** after days of failure | `SyncETimeOffice.php:83-87` (DEF-C-14) |

---

## XI.2 The four-layer data architecture *(brief Section 17 — the raw-data principle)*

Spine S10, specified. Four layers, each immutable to the one below it, each with a distinct
owner, retention class and mutation grammar.

```
LAYER 1  punch_events          RAW.  Append-only. Never updated, never deleted.
                               Payload exactly as received + hash, adapter + version,
                               receipt instant, timezone assumption, per-line verdict.
   │  adapter parse + identity resolution + normalisation   (no business rules)
   ▼
LAYER 2  punch_normalised      CANONICAL PUNCH. One row per real-world punch event.
                               Append-only. A correction never edits it — it adds
                               an overlay row in punch_corrections.
   │  attendance-day attribution + shift resolution + rule evaluation (Part VII)
   ▼
LAYER 3  attendance_days       DERIVED, one current row per employment per attendance day.
                               Supersedable by generation. Carries statuses, minutes,
                               day fractions, the rule versions applied, and a trace id.
   │  period roll-up
   ▼
LAYER 4  period_settlements    The payroll-facing frozen view of a period. Frozen on
                               period lock; re-openable only through an audited workflow.
```

**R-XI-001** The four layers are four physical tables. No layer may be written by a process
that owns another layer. *SmartPRS 2 has no layer 1, no layer 3 and no layer 4: one table,
`attendance_logs`, written by nine paths with three different schemas depending on install
history (B §2.1, DEFECT-B-04).*

**R-XI-002** No business rule reads layer 1. Adapters read layer 1; the rule engine reads
layer 2 and the resolved configuration, and writes only layer 3.

### XI.2.1 Layer 1 — `punch_events`, the raw evidence

Everything an adapter received, stored before anything is decided about it. Per Part XXVIII
§C5 this table is partitioned monthly on `received_on` and the application database user has
no `UPDATE` or `DELETE` privilege on it.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | part of PK with the partition key |
| `tenant_id` | BIGINT UNSIGNED | N | — | FK `tenants` RESTRICT; from the **credential**, never the payload |
| `received_on` | DATE | N | — | partition key, derived from `recorded_at` in UTC |
| `inbound_batch_id` | BIGINT UNSIGNED | Y | NULL | FK `inbound_batches` RESTRICT |
| `device_id` | BIGINT UNSIGNED | Y | NULL | FK `devices` RESTRICT; NULL only when the device is unknown and quarantined |
| `device_serial_raw` | VARCHAR(120) | Y | NULL | as supplied; **never used to resolve the tenant** |
| `adapter_code` | VARCHAR(60) | N | — | e.g. `adms.iclock`, `sbb`, `recipe.rest` |
| `adapter_version` | VARCHAR(20) | N | — | pinned; a re-parse records which version produced which output |
| `recipe_config_version_id` | BIGINT UNSIGNED | Y | NULL | FK `config_versions` RESTRICT — the recipe version, when the source is a recipe |
| `payload_raw` | JSON | N | — | exactly as received (one line, one record, one webhook body) |
| `payload_hash` | BINARY(32) | N | — | SHA-256, layer-1 duplicate detection |
| `timezone_assumption` | VARCHAR(64) | N | — | the zone actually applied, recorded so a misconfigured device is correctable without guessing |
| `time_policy_code` | VARCHAR(40) | N | — | `naive_local \| offset_authoritative \| named_zone` (§XI.6) |
| `ingest_verdict_code` | VARCHAR(40) | N | — | value set: `accepted`, `duplicate`, `quarantined_unknown_device`, `quarantined_unmapped_identity`, `rejected_schema`, `rejected_time`, `rejected_out_of_window` |
| `verdict_detail` | VARCHAR(400) | Y | NULL | machine-readable reason code + human detail |
| `occurred_at` | DATETIME(3) | N | — | the sender's claimed instant, UTC |
| `recorded_at` | DATETIME(3) | N | — | our receipt instant, UTC |

**Keys.** PK `(id, received_on)` · UQ `(tenant_id, payload_hash, received_on)` ·
IX `(tenant_id, received_on, ingest_verdict_code)`, `(tenant_id, device_id, received_on)`,
`(inbound_batch_id)`.
**Policy.** Tenant-owned · append-only, enforced by database privilege · audit `event-only`
· PII `identifying` · retention class `R-ATT-3Y+` (D-XI-08) · partitioned monthly.

**R-XI-003** Every line an adapter receives produces exactly one `punch_events` row —
**including** a line that is blank, malformed, out of window, or from an unknown device.
Nothing is ever silently dropped. *SmartPRS 2 skips a blank PIN or an unparsable date with a
bare `continue` and reports only the imported count (`PushController.php:116-124`,
DEFECT-B-22, DEF-C-17); the pull connectors yield no punch for an unparsable record and no
counter anywhere records how many vanished.*

**R-XI-004** `punch_events` rows are never updated and never deleted by application code.
Retention is a scheduled, audited platform operation governed by the retention class
(spine S16).

### XI.2.2 Layer 2 — `punch_normalised`, the canonical punch

The adapter's output, in the canonical contract of §XI.4.1. One row per real-world punch.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK with partition key |
| `tenant_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `punch_on` | DATE | N | — | partition key: the **business date of the instant in the punch timezone**. Not the attendance day — that is layer 3 |
| `punch_event_id` | BIGINT UNSIGNED | N | — | FK `punch_events` RESTRICT — the evidence it came from |
| `employment_id` | BIGINT UNSIGNED | N | — | FK `employments` RESTRICT — resolved identity, never a code string |
| `identity_strategy_code` | VARCHAR(40) | N | — | which strategy resolved it (§XI.4.2), for the identity trace |
| `punch_at_utc` | DATETIME(3) | N | — | the absolute instant — the only thing arithmetic uses |
| `punch_local_time` | TIME | N | — | derived, for display and shift maths |
| `punch_timezone` | VARCHAR(64) | N | — | IANA zone |
| `direction_code` | VARCHAR(40) | N | — | value set `punch_direction`: `in`, `out`, `break_in`, `break_out`, `ot_in`, `ot_out`, `unknown` (D-XI-09) |
| `direction_source_code` | VARCHAR(40) | N | — | `device_flag \| machine_map \| alternation \| adapter_default \| correction` |
| `verification_method_code` | VARCHAR(40) | Y | NULL | `finger`, `face`, `palm`, `card`, `pin`, `mobile_geo`, `web`, `manual` |
| `source_code` | VARCHAR(40) | N | — | value set `punch_source`: `device`, `sbb`, `webhook`, `connector`, `mobile`, `web`, `import`, `manual`, `correction` |
| `device_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `device_user_id` | VARCHAR(64) | Y | NULL | the raw device-side identity, retained for provenance |
| `external_event_id` | VARCHAR(96) | Y | NULL | sender idempotency key |
| `geo_lat`, `geo_lng` | DECIMAL(11,7) | Y | NULL | ≈1.1 cm precision |
| `geo_verdict_code` | VARCHAR(40) | Y | NULL | `inside \| outside \| no_gps \| unknown` |
| `is_quarantined` | TINYINT(1) | N | 0 | de-duplication and evidence disputes **quarantine**; nothing is deleted |
| `quarantine_reason_code` | VARCHAR(40) | Y | NULL | |
| `occurred_at`, `recorded_at` | DATETIME(3) | N | — | |

**Keys.** PK `(id, punch_on)` · UQ `(tenant_id, external_event_id, punch_on)`,
`(tenant_id, employment_id, punch_at_utc, source_code, punch_on)` ·
IX `(tenant_id, employment_id, punch_on)`, `(tenant_id, punch_on)`,
`(tenant_id, device_id, punch_on)`, `(tenant_id, is_quarantined, punch_on)`,
`(tenant_id, source_code, punch_on)`.
**Policy.** Tenant-owned · append-only · audit `event-only` · PII `identifying` ·
retention `R-ATT-3Y+` · partitioned monthly.

**R-XI-005** `direction_code` is **not** part of any uniqueness key. Direction is a
correctable property of a punch, not part of its identity. *SmartPRS 2's LAN path keeps
`direction` in the `updateOrInsert` match key and drops `punch_at` from it
(`DeviceController.php:193-207`), so only one IN and one OUT can exist per employee-day and
a corrected direction manufactures a duplicate (DEF-C-06, DEFECT-B-21).*

**R-XI-006** The index `(tenant_id, source_code, punch_on)` exists because `source` is
filtered on every dedup, sequence and correction path. *SmartPRS 2 indexes everything except
`source`, which is exactly what `sequenceDirections():637` and `writeBack():300` filter on
(DEFECT-B-26).*

### XI.2.3 Corrections are overlays — `punch_corrections`

An approved correction **never edits or deletes a layer-2 row**. It writes an overlay with
its own validity, approval and reason, so raw device evidence and the corrected view coexist
and either can be rendered.

`punch_normalised_id` (FK RESTRICT, nullable — an insertion has no original),
`employment_id` (FK RESTRICT), `correction_type_code`
(`insert | amend_time | amend_direction | void`), `corrected_punch_at_utc`,
`corrected_direction_code`, `reason_code`, `reason_text`, `regularisation_id` (FK RESTRICT),
`workflow_instance_id` (FK RESTRICT), `status_code`, `applied_generation`, plus A5 effective
dating (`effective_from`, `effective_to`, `open_marker`).

**Keys.** UQ `(tenant_id, punch_normalised_id, open_marker)` ·
IX `(tenant_id, employment_id, effective_from)`, `(tenant_id, status_code)`.
**Policy.** Tenant-owned · history A5 · delete never · audit `full` · PII `identifying` ·
retention `R-ATT-3Y+`.

**R-XI-007** The effective punch set for an attendance day is
`layer 2 rows ⊕ approved overlays as at the derivation date`. Both are always available; the
UI shows "device evidence" and "as corrected" side by side. *SmartPRS 2 DELETEs all
`source='correction'` rows for the employee-day and re-inserts (`:295-319`), and correction
punches then **coexist** with the erroneous device punches they were meant to fix, producing
interleaved sessions the employee never worked (DEFECT-B-18, NEW-ATT-013).*

**R-XI-008** A correction can express an **overnight pair** — an OUT on the day following
the IN — because a correction carries instants, not `"$date $HH:MM:00"` strings. *SmartPRS 2
builds `punch_at` by string concatenation from `log_date` and orders by `strcmp`
(`AttendanceCorrectionService.php:115, 234, 311`), which makes an overnight correction
structurally impossible to express (NEW-ATT-014).*

### XI.2.4 Layer 3 — `attendance_days`, derived and supersedable

One **current** row per `(employment, attendance day)`, plus every superseded generation.
Full column specification is in §XI.16.3; the properties that matter here:

- `work_on` is **the attendance day**, produced by the attribution rule of §XI.8.4 — not the
  punch's calendar date.
- `generation` is monotonic; a recalculation writes `generation + 1` and flips `is_current`.
  Prior generations are retained and queryable, so "what did we say on 3 September, and what
  do we say now?" is answerable.
- `applied_rule_versions_json` records **every** rule, formula, policy, shift and calendar
  version that produced the row. This is what makes a March recalculation run in November
  reproduce March's answer.
- `trace_id` points at the `calculation_traces` row rendering "How was this calculated?"
  (spine S15, §XI.14).
- A row exists for **every** employee-day in an employment's active range — including
  absent, weekly-off, holiday and leave days. *SmartPRS 2 emits no row at all for a
  zero-punch day, so absence, holiday and weekly off are invisible on the report
  (`AttendanceReportController.php:119-123`, NEW-ATT-006, DEFECT-B-10).*

**R-XI-009** Exactly one `attendance_days` row per `(tenant, employment, work_on)` has
`is_current = 1`, enforced by a unique index on a generated marker column, not by
application discipline.

**R-XI-010** `attendance_days` rows are never deleted, and never updated except to set
`is_current = 0`, `superseded_at` and `superseded_by_id`.

### XI.2.5 Recalculation — a first-class, idempotent, previewable command

```
recalculate(scope, date_range, mode) → RecalcRun
  scope       : a Scope expression (spine S3.1) — any dimension, down to one employee
  date_range  : [from, to]
  mode        : preview | apply
  options     : { reason (required), rerun_settled_periods (default false),
                  include_locked_periods (default false) }
```

Semantics:

1. Resolve the employment set from the scope **as at each date in the range** (an employee
   who transferred mid-range is evaluated under each branch's rules for the days they were
   in it).
2. For each `(employment, day)`: rebuild the effective punch set (layer 2 ⊕ overlays),
   resolve the shift, the calendar and the policy versions **effective on that day**, and
   evaluate the Part VII decision points in order.
3. Compare the produced row with the current row, field by field.
4. In `preview` mode, write only the diff. In `apply` mode, write generation `n+1`, supersede
   the prior row, emit `attendance.day.superseded`, and cascade to layer 4 for any period
   that is still `open`.

**R-XI-011** Recalculation is **idempotent**: applying it twice with no intervening input
change produces zero diffs and does not create a new generation.

**R-XI-012** Recalculation uses the rule, shift, calendar and policy versions **effective on
each date**, never the versions current at the time the command is run. *This is the direct
fix for the property that a `late_policy` edit today silently rewrites last year's report,
because SmartPRS 2's policies and shifts carry no validity columns (B §Q1).*

**R-XI-013** A recalculation that would change a day inside a **frozen** period does not
change it. It records a `recalc_blocked` diff line naming the period, the delta and the
proposed arrears disposition, and routes it to §XI.13.5. `include_locked_periods = true` is
available only with the `attendance.recalculate.locked` permission and forces the period
reopen workflow.

**R-XI-014** The preview diff shows, per employee-day: old status → new status, old and new
payable/LOP/leave fractions, old and new minutes, the rules that changed the outcome and
their version ids, and a roll-up by status transition and by org unit, with an impacted
headcount. Publishing a shift, calendar, work-time policy or settlement policy version offers
this diff as its impact preview (spine S4, Part VI §VI.10).

**R-XI-015** Every recalculation writes an `attendance_recalc_runs` row with the scope, the
range, the requester, the reason, counts examined and changed, and the diff summary; every
superseded day names the run that superseded it.

**Triggers.** Recalculation runs on: a late-arriving punch for a past day; an approved
regularisation; a roster or shift change affecting past days; a holiday calendar change; a
policy or rule version publication with a retro-effective date; a device time correction; and
an operator-initiated command. All of them go through the same command — there is no second
path.

### XI.2.6 De-duplication quarantines; it never deletes

**R-XI-016** Duplicate detection is a **database uniqueness guarantee** at two levels:
layer 1 on `(tenant_id, payload_hash, received_on)`, layer 2 on
`(tenant_id, external_event_id, punch_on)` and
`(tenant_id, employment_id, punch_at_utc, source_code, punch_on)`. `tenant_id` is
`NOT NULL` everywhere, so the guarantee holds on single-tenant installs. *SmartPRS 2's two
unique indexes are inert on every self-hosted install because NULL never collides in MySQL
(B §Q7; C requirement 18).*

**R-XI-017** When a near-duplicate is detected that the unique keys do not catch — two
physical readers producing the same instant, a re-import through a different adapter — the
**later** row is written with `is_quarantined = 1` and a reason code. Nothing is deleted, no
provenance is merged away, and the quarantined row remains visible in the day's evidence
view. *SmartPRS 2 keeps `MAX(id)` and hard-deletes the rest, destroying the `external_id`,
`device_sn`, `device_user_id` and `verify_mode` carried by the older row (B §Q7).*

**R-XI-018** No destructive data operation may be invoked from a migration. Migrations
change schema; data repair is an explicit, audited, dry-runnable command with a preview and
an operator confirmation. *`2026_08_16_000200:128` calls a hard-delete command during
`php artisan migrate` (DEFECT-B-25).*

**R-XI-019** The configurable **double-tap collapse** — two reads of the same finger within
N seconds are one punch — is a rule-engine primitive
(`agg.collapse_repeats(punch, within_seconds)`, Part VII §5.9) parameterised per device, not
a constant. *SmartPRS 2 fixes it at `SINGLE_REPEAT_SEC = 60` (`ETimeOfficeService.php:578`),
honoured on one of six paths (NEW-C-05, CONF-C-02).*

### XI.2.7 Layer 4 — `period_settlements`

The payroll-facing frozen view: payable days, LOP days, leave days, weekly-off and holiday
days, present days, OT minutes, comp-off earned and availed, and the maximum layer-3
generation included. Frozen when the payroll period locks; re-openable only through an
audited workflow (spine S8). Full specification in §XI.16.6.

**R-XI-020** Payroll consumes **layer 4 only**. It never queries punches. *SmartPRS 2's
`PayrollGenController` re-implements punch pairing, presence, lateness and the day-cut from
raw rows — a second implementation that has already diverged from the report's
(`dayStats():346-405` vs `pairPunches():621-712`, DEFECT-B-08).*

**R-XI-021** A settlement row records `attendance_generation_max`. If any contributing day
is later superseded, the settlement is marked `stale` and the payroll run's preview surfaces
it as a blocking exception (spine S11).

---

## XI.3 The Device Adapter SDK *(brief Section 15 — biometric integration architecture)*

### XI.3.1 The contract

Spine S10.1, specified to implementable detail. An adapter is a PHP class registered in one
registry; nothing else in the application knows a vendor's name.

```php
interface DeviceAdapter
{
    /** Static identity — code, display name, vendor list, semantic version. */
    public function descriptor(): AdapterDescriptor;

    /** Closed set: pull | push | command | enrol | realtime | template_sync
     *  | time_sync | health_probe | user_list                                   */
    public function capabilities(): CapabilitySet;

    /** JSON-schema for the connection form; the console renders only these fields. */
    public function connectionSchema(): FieldSchema;

    /** naive_local | offset_authoritative | named_zone — declared, never inferred. */
    public function timePolicy(): TimePolicy;

    /** Ordered, candidate-major identity strategies this adapter offers. */
    public function identityStrategies(): IdentityStrategyList;

    public function connect(ConnectionConfig $c): ConnectionResult;
    public function authenticate(ConnectionConfig $c): AuthResult;
    public function test(ConnectionConfig $c): Diagnostics;        // never writes

    /** PULL adapters. $window carries a high-water mark, not a rolling "now - n days". */
    public function fetch(ConnectionConfig $c, FetchWindow $w): RawEventBatch;

    /** PUSH adapters. Called by the ingest controller with the received body. */
    public function accept(InboundRequest $r): RawEventBatch;

    /** Pure. Raw line/record → canonical punches, or a typed line rejection. */
    public function parse(RawEvent $e): ParseResult;               // CanonicalPunch[] | Rejection

    /** COMMAND adapters: queue → device. */
    public function issueCommand(DeviceCommand $cmd): CommandResult;
    public function pollCommands(DeviceRef $d): DeviceCommand[];

    public function health(DeviceRef $d): DeviceHealth;
}
```

**R-XI-022** Provider dispatch happens in **exactly one place**: the adapter registry.
*Test: adding a new adapter touches one registry file and zero existing adapters.*
*SmartPRS 2 duplicates the `if/elseif` in five places (`BiometricConfigController::test()
:409-450`, `::sync():480-509`, `ETimeOfficeService::configuredAny():126-137`,
`SyncETimeOffice::handle():76-96` and `:115-119`; DEBT-C-01).*

**R-XI-023** An adapter's only outputs are `CanonicalPunch` values and typed rejections. It
never writes to a table, never resolves a shift, never evaluates a rule, never sends a
notification and never decides an attendance day. *`ETimeOfficeService::import()` writes
punches, resolves employees, re-stamps directions, quarantines, and calls
`LateArrivalService::notifyTouched()` inline into `Mail::raw` inside the HTTP request — so a
slow SMTP host stalls the device response until the device times out and re-uploads,
manufacturing duplicates (`:777`; DEF-C-03).*

**R-XI-024** All vendor-specific data — status-byte maps, direction tokens, field aliases,
date formats, model quirks — is adapter **data** (a versioned vendor profile), not adapter
code. Adding support for a firmware variant that maps status byte `6` to `break_out` is a
profile row.

### XI.3.2 The capability model

| Capability | Meaning | Console effect |
|---|---|---|
| `pull` | We fetch from the device or vendor cloud on a schedule | Shows schedule, window, high-water mark, backfill |
| `push` | The device or partner posts to us | Shows the endpoint, the credential, the last receipt |
| `realtime` | Events arrive per punch rather than per batch | Shows live punch feed and expected cadence |
| `command` | We can queue instructions to the device | Enables the command console (§XI.7.5) |
| `enrol` | Users can be created/deleted on the device from SmartPRS | Enables enrolment actions on the employee record |
| `template_sync` | Biometric templates can be read/written | Enables template transfer, gated by a separate permission |
| `time_sync` | The device clock can be set | Enables scheduled clock sync (§XI.6.5) |
| `health_probe` | The device answers a liveness probe | Health polling instead of inference from last punch |
| `user_list` | The device can enumerate its enrolled users | Enables mapping reconciliation |

**R-XI-025** The console renders only the fields and actions the adapter's capability set and
connection schema declare. *SmartPRS 2 hard-codes a four-branch `if` in the SPA
(`AppController.php:9695-9710`) and shows fields that the selected provider ignores.*

### XI.3.3 The ingest lifecycle — where each concern lives

The lifecycle is owned by the **shared ingest core**; the adapter contributes only the shaded
steps. This is what makes a fix in one place a fix everywhere.

| # | Step | Owner | Notes |
|---|---|---|---|
| 1 | **detect** | core | Match inbound credential or scheduled connector to a device/connection row |
| 2 | **connect** | *adapter* | Transport establishment; egress allow-list enforced by core |
| 3 | **authenticate** | *adapter* | Credentials fetched by handle from the vault; never interpolated into an admin-supplied URL |
| 4 | **test** | *adapter* | Read-only; core redacts and size-caps the output |
| 5 | **fetch** / **accept** | *adapter* | Window comes from the device's high-water mark |
| 6 | **persist raw** | core | Layer 1 write, per line, before anything is parsed |
| 7 | **parse** | *adapter* | Pure; returns canonical punches or typed rejections |
| 8 | **map identity** | core | Ordered candidate-major strategy list (§XI.4.2) |
| 9 | **normalise** | core | Time policy applied, direction resolved, topology rules applied |
| 10 | **deduplicate** | core | Database uniqueness; near-duplicates quarantined |
| 11 | **land** | core | Layer 2 write inside one transaction with the per-line verdicts |
| 12 | **acknowledge** | core | Per-punch verdict returned to the sender |
| 13 | **resume** | core | High-water mark advanced only for the window actually settled |
| 14 | **retry** | core | Per-adapter policy: attempts, backoff, jitter, circuit breaker |
| 15 | **health** | core + *adapter* | Health record updated on every attempt, success or failure |

**R-XI-026** Steps 8, 9 and 10 are **core** steps. The direction-topology rule (single-reader
alternation, machine-ID map, device flag, always-in), the double-tap window and the identity
strategy therefore apply identically on every path — device, bridge, webhook, connector,
mobile, web, import and manual. *SmartPRS 2 honours `device_mode` in exactly one of six
machine paths (`ETimeOfficeService.php:710-712`; CONF-C-02).*

**R-XI-027** A punch is **persisted before acknowledgement** on every path, and the
acknowledgement carries a per-punch verdict (`accepted | duplicate | pending | rejected` with
a reason code). *True today only for the Smart Biometric Bridge and SmartEPT
(`PunchIngestService.php:61-212`); `/iclock` answers `OK: n` and the pull paths return a
single count that includes updates (DEF-C-18).*

**R-XI-028** `fetch` uses a **per-device high-water mark**, not a rolling `now − n days`
window, and a failed window is re-attempted over the missed range. *SmartPRS 2 loses the
tick's window entirely on a transient vendor outage and the next tick uses a fresh
`now() − days` window, so nothing back-fills beyond the accidental one-day overlap (C
requirement 25, NEW-C-25 — there is not one `->retry()` in the codebase).*

**R-XI-029** Every attempt updates the device health record with an outcome, an error class
and a consecutive-failure counter. `last_status` may never continue to display a success
after a failure. *`SyncETimeOffice.php:83-87` prints the error and `continue`s without
stamping anything, so the console shows a green "last sync" for a device that has been
failing for days (DEF-C-14, severity HIGH).*

### XI.3.4 Writing a new adapter

1. Implement `DeviceAdapter`; declare `descriptor()`, `capabilities()`,
   `connectionSchema()`, `timePolicy()` and `identityStrategies()`.
2. Add a migration row to `adapter_definitions` (platform-owned): code, version, capability
   set, connection schema, time policy, default vendor profile.
3. Author the vendor profile(s) as data: status-byte map, direction tokens, date formats,
   field aliases, quirks.
4. Provide the **conformance suite**, which is the same suite for every adapter (§XI.21.2):
   parse fixtures with expected canonical punches, a rejection fixture per rejection class,
   an idempotency test (same batch twice → zero new rows), a time-policy test, a cross-midnight
   fixture, a tenancy test proving the adapter cannot influence the tenant, and a health test.
5. Register it. **No change to the attendance engine, the console, the rule engine or the
   database schema is permitted as part of adding an adapter.** *Test: the diff of an adapter
   PR touches `Adapters/<code>/`, the registry migration and fixtures only.*

### XI.3.5 The connector recipe — integrations with no PHP

For sources whose protocol is HTTP, SOAP, SQL, CSV or SFTP, no PHP is required. A
**connector recipe** is a validated, versioned configuration document evaluated by the
recipe engine, which is itself one adapter (`recipe.rest`, `recipe.soap`, `recipe.sql`,
`recipe.csv`, `recipe.sftp`).

```yaml
recipe:
  kind: rest                       # rest | soap | sql | csv | sftp
  version: 2
  transport:
    method: GET
    url: "{base_url}/api/v2/punches"
    egress_profile: PARTNER_CLOUD          # host allow-list handle, not a free URL
    timeout_ms: 20000
    retry: { attempts: 5, backoff: exponential, base_ms: 500, jitter: full,
             circuit_breaker: { failures: 10, cooldown_s: 900 } }
    rate_limit: { requests: 60, per_seconds: 60 }
  auth:
    kind: oauth2_client_credentials         # none | basic | header_key | oauth2_* | soap_body | digest
    credential_ref: CRED-7f3a               # a handle; the engine places the secret
    token_url: "{base_url}/oauth/token"
    refresh: { skew_s: 60 }
  window:
    from_param: from
    to_param: to
    format: "Y-m-d\\TH:i:sP"
    high_water_mark: true
    max_span: P7D
  pagination:
    kind: cursor                            # none | page | offset | cursor | link_header
    cursor_path: $.meta.next_cursor
    cursor_param: cursor
    max_pages: 200
  response:
    encoding: json                          # json | xml | csv | fixed_width
    record_path: $.data.punches
  map:
    identity_value: $.employeeCode
    device_serial:  $.deviceSerial
    punch_at:       $.timestamp
    direction:      $.type
    verify_method:  $.verifyMode
    external_event_id: $.id
  time_policy: offset_authoritative
  direction_tokens:
    in:        ["IN", "0", "I", "CHECKIN"]
    out:       ["OUT", "1", "O", "CHECKOUT"]
    break_out: ["BREAKOUT", "2"]
    break_in:  ["BREAKIN", "3"]
    default:   unknown                       # NEVER silently "in" — D-XI-05
```

**R-XI-030** A recipe is a first-class configuration artefact (spine S4): versioned,
effective-dated, scoped, draft/publish, diffable, with a schema-validated payload. Publishing
runs a live `test` against the source and requires a successful parse of at least one record.

**R-XI-031** A recipe that fails schema validation is **rejected on save with a field-level
error**. *SmartPRS 2 silently converts an invalid `api_config` to NULL and returns `ok:true`,
so an admin who mistypes the recipe is told it saved and the connector then does nothing
(`BiometricConfigController.php:32-42`; DEF-C-13).*

**R-XI-032** A credential is referenced by **handle**. The engine places the secret only
where the transport declares it belongs. A recipe may not interpolate `{pass}` into a URL,
a header value or a body it composes freely. *SmartPRS 2 substitutes `{pass}`, `{user}` and
`{serial}` into admin-supplied `url`, `headers` and `body` (`GenericApiService.php:186-188`;
SEC-C-05).*

**R-XI-033** Every outbound call is bound to an **egress profile**: scheme allow-list (HTTPS
by default) and host allow-list, with loopback, link-local and RFC1918 ranges blocked unless
a LAN profile explicitly permits them (spine S17). *SmartPRS 2 issues GET/POST to a fully
admin-supplied `url` and `token_url` with no validation and returns the raw response body to
the browser, turning blind SSRF into readable SSRF (SEC-C-04 + SEC-C-14, both HIGH).*

**R-XI-034** Test output is **redacted and size-capped**: field-level parse results, not a
raw foreign response body. *`BiometricConfigController.php:423-425` echoes 1 500 raw
characters from an arbitrary host to the browser.*

**R-XI-035** The recipe grammar must express what SmartPRS 2's cannot: pagination and
cursoring, explicit retry with backoff and a circuit breaker, OAuth2 client credentials with
refresh, per-request rate limiting, an explicit response encoding, an explicit column map for
delimited and fixed-width sources, and a declared time policy (C §5.2 item 26, requirement 3).

**R-XI-036** No heuristic field-sniffing exists anywhere. *`ETimeTrackLiteService::rowFromFields()`
identifies the employee code as "the first non-datetime cell containing a digit" (`:233-244`),
so any build that puts a machine number or a record id first files every punch against the
wrong employee (DEF-C-15); `extractData()` regex-scrapes SOAP XML and on failure feeds the
entire raw body — an error page or an HTML login form — into that sniffer (`:130-149`,
DEF-C-16).*

### XI.3.6 The launch matrix — every vendor and protocol supported at launch

**A = coded adapter · R = declarative recipe · P = vendor profile (data) on an adapter**

| Source | Protocol / transport | Launch mechanism | Notes |
|---|---|---|---|
| **ZKTeco** (K40, F18, MB, iClock, uFace) | ADMS / iclock push over HTTP | **A** `adms.iclock` + **P** `zkteco` | Status-byte map, option block and command channel are profile data |
| **ZKTeco** | Direct LAN, binary TCP/UDP port 4370 | **A** `zk.tcp` | Full intra-day log; replaces the LAN path that collapses a day to first-in/last-out (`DeviceController.php:150-157`, NEW-C-19) |
| **ZKTeco BioTime 8** | Vendor cloud/server REST | **R** `recipe.rest` | SmartPRS 2 already ships this as a generic preset (`AppController.php:9437-9469`) |
| **eSSL** (x990, K30, Biomax-badged) | ADMS / iclock push | **A** `adms.iclock` + **P** `essl` | |
| **eSSL eTimeTrackLite** | Local WebAPI SOAP, port 81 | **R** `recipe.soap` | With an **explicit column map** per build, built by the console's test-and-map wizard — the sniffing heuristics are not carried over (DEF-C-15/16) |
| **eTimeOffice** | Cloud REST/JSON | **R** `recipe.rest` | Composite Basic username (`corp:user:pass:true`, `ETimeOfficeService.php:139-144`) is expressed as an auth kind `basic_composite` in the grammar |
| **Matrix COSEC** | REST/JSON (and SOAP on older builds) | **R** `recipe.rest` / `recipe.soap` | Already a generic preset in SmartPRS 2 |
| **Realtime** (T5, Eco S) | ADMS / iclock push; some builds TCP | **A** `adms.iclock` + **P** `realtime`; **A** `zk.tcp` for TCP builds | |
| **Mantra** (MFSTAB, mTime terminals) | ADMS push | **A** `adms.iclock` + **P** `mantra` | The Mantra RD-service scanner path is a *mobile/desktop capture* source, not a terminal — §XI.3.7 |
| **BioMax** (N-BM series) | ADMS push | **A** `adms.iclock` + **P** `biomax` | |
| **Hikvision** (DS-K1T series) | ISAPI over HTTP(S), digest auth, `AcsEvent` paging + event alert stream | **A** `hikvision.isapi` | Digest auth and the alert stream are not expressible declaratively |
| **CP Plus** (OEM of Hikvision/Dahua stacks) | ISAPI / HTTP API | **A** `hikvision.isapi` + **P** `cpplus`, or **R** `recipe.rest` for the JSON API builds | Which of the two applies is determined by the console's detect step |
| **Smart Biometric Bridge (SBB)** | Authenticated JSON batch to `/api/v3/attendance/punches` | **A** `sbb` | The reference implementation; **everything else converges on its semantics** (C §9) |
| **SmartEPT** | Signed inbound webhook (HMAC-SHA256) | **A** `webhook.signed` + **P** `smartept` | Generalised: the signature, replay-safety and tenant-from-endpoint design are kept; the SmartEPT event names and field translation become profile data (C §9, D-XI-13) |
| **Generic REST** third-party provider | HTTP/JSON | **R** `recipe.rest` | |
| **Generic SOAP** third-party provider | HTTP/XML | **R** `recipe.soap` | |
| **SQL link** (vendor's own database) | MySQL / PostgreSQL / SQL Server read-only | **R** `recipe.sql` | Read-only credential, statement allow-list, bound parameters only, high-water mark on an indexed column |
| **CSV drop** | Local or object-store file | **R** `recipe.csv` | Column map, delimiter, encoding, header policy, archival on success |
| **SFTP** | SFTP polling | **R** `recipe.sftp` | Host-key pinning, file pattern, ordering, idempotency by file digest |
| **Mobile app punch** | SmartPRS mobile, geo + device attestation | **A** `mobile` | Geofence verdict is carried, never used to silently drop |
| **Web / ESS punch** | SmartPRS SPA | **A** `web` | |
| **Manual entry** | HR keys a punch | **A** `manual` | Always dual-fielded (instant + reason) and always audited |
| **Bulk import** | Sheet upload, approval-gated | **A** `import.sheet` | Approval-before-punch preserved (BR-ATT-036); server-side parsing (SmartPRS 2 parses in the browser) |

**R-XI-037** Every source in the launch matrix produces the identical canonical punch and
passes through the identical core lifecycle. There is no source with weaker guarantees.
*SmartPRS 2's LAN path silently discards an unmatched punch (`DeviceController.php:162-166`,
DEF-C-05) — the one path that still destroys attendance, contradicting the promise printed on
its own Pending Punches screen.*

### XI.3.7 The Smart Biometric Bridge boundary (brief Section 86)

SBB owns device transport, local buffering, retry and forwarding on the customer's LAN.
SmartPRS owns identity mapping, shift resolution, rules and payroll. Neither reaches into the
other's tables; the contract is the canonical punch API plus a versioned webhook
(spine S10, S17).

**R-XI-038** SBB and SmartEPT integrate through versioned API and webhook contracts only. No
shared tables, ever.

---

## XI.4 The canonical punch contract and identity resolution

### XI.4.1 The canonical punch

The single contract every adapter, SBB and SmartEPT write to (spine S10):

```
CanonicalPunch {
    external_event_id      Text?      -- sender idempotency key
    tenant_id              Id         -- SET BY THE CORE from the credential; adapters cannot set it
    device_id              Id?        -- resolved by the core from (tenant, serial)
    device_serial_raw      Text?      -- as supplied, evidence only
    device_user_id         Text?      -- the device-side identity value
    identity_candidates    List<{ type, value }>   -- ordered by the adapter's strategy list
    punch_at_utc           DateTime   -- the absolute instant
    punch_local_time       Time
    timezone               Text       -- IANA zone actually applied
    time_policy            Code       -- naive_local | offset_authoritative | named_zone
    direction_token_raw    Text?      -- the vendor's own token, retained
    direction_code         Code       -- resolved; may be `unknown`
    verification_method    Code?
    source_code            Code
    geo                    { lat, lng }?
    raw_payload_hash       Binary(32)
    received_at            DateTime
    adapter                Code
    adapter_version        Text
}
```

**R-XI-039** An adapter may not set `tenant_id`, `employment_id` or `device_id`. It supplies
evidence and candidates; the core resolves identity and tenancy.

### XI.4.2 Identity resolution — an ordered, candidate-major strategy list

Resolution is **candidate-major**: for each candidate, in the configured order, every strategy
is tried before moving to the next candidate. This is the inverse of SmartPRS 2's loop and it
is the fix for DEF-C-01.

Default order, itself configuration per device (an artefact version, effective-dated):

| # | Strategy | Lookup |
|---|---|---|
| 1 | `device_identity_exact` | `(tenant, device_id, identity_type, identity_value)` in `device_identity_map`, in force on the punch date |
| 2 | `device_identity_any_device` | `(tenant, NULL device, identity_type, identity_value)` — a tenant-wide enrolment |
| 3 | `employee_code_transformed` | Apply the device's declared **code transform** (prefix, suffix, zero-pad, regex capture, lookup table) then match `employments.employee_code` |
| 4 | `employee_code_raw` | Match `employments.employee_code` exactly |
| 5 | `external_identity_map` | `external_identity_map(system_code, external_id)` — for partner sources |
| — | *(none matched)* | **Quarantine** with `quarantined_unmapped_identity` and a mapping worklist entry |

**R-XI-040** Identity resolution is candidate-major and the strategy list is ordered
configuration. *`ETimeOfficeService::matchEmployee():315-345` is column-major: it searches the
whole `emp_code` column for both candidates before ever consulting `device_user_id`, so a raw
PIN that collides with another employee's code beats the correct explicit mapping and the
punch is filed against the wrong person, silently (DEF-C-01, CRITICAL).*

**R-XI-041** The mapping model is `(device_id NULLABLE, identity_type, identity_value) →
employment`, **effective-dated**. `device_id = NULL` means "any device of this tenant". PIN 5
on the Chennai gate and PIN 5 on the Pune gate may therefore be two different people
(D-XI-06). *SmartPRS 2's entire mapping model is the single column
`employees.device_user_id` — one device ID per employee, globally (C §2.9).*

**R-XI-042** Re-issuing a device identity **closes** the prior mapping row and opens a new
one. It never rebinds history. Historic punches keep the employment they were resolved to at
ingest; changing that is an explicit, audited, previewed **re-attribution** operation
(§XI.13.6, D-XI-07). *SmartPRS 2's mapping backfill silently rewrites `emp_code`, `emp_name`,
`tenant_id` and `company_id` on historic `attendance_logs` rows, unbounded in date range and
reaching NULL-tenant rows, with no audit row and only a flash message as evidence
(`BiometricConfigController.php:639-655`; NEW-C-11, SEC-C-12).*

**R-XI-043 — the absolute prohibition.** **Employee NAME is never a join key, anywhere, for
any source, human or machine.** Identity is resolved by employment id, employee code, or a
registered device/external identity value — never by a display name, in ingest, in import, in
roster, in correction, in notification routing, or in reporting. *Test: a CI check greps the
domain layer for any join or `where` on a name column and fails the build. SmartPRS 2's
roster is keyed by employee display name (`ShiftResolver.php:94, 116`, NEW-ATT-004), the bulk
importer indexes by name, and the late-arrival mailer resolves the manager's address by
`LOWER(name)` (`LateArrivalService.php:283`) — so two people called Suresh Kumar collide, and
a rename silently detaches a roster.*

**R-XI-044** Employee-code derivation from a device code is a **configurable transform** per
device — prefix, suffix, zero-pad to width, regex capture, or an explicit lookup table — not a
bare string prepend. *`ETimeOfficeService.php:691` concatenates `emp_prefix . device_code` and
nothing else is possible (C §5.2 item 15).*

**R-XI-045** The identity trace is stored per punch: which candidate matched, by which
strategy, against which mapping row and its validity window, and which candidates were tried
and missed. This renders as the third `Explain` answer in §XI.14.3.

---

## XI.5 Trust and tenancy on ingest

**R-XI-046** Tenant is derived from an **authenticated credential** — an API client, a
per-device secret, a webhook endpoint, or an authenticated user session — never from a
device-supplied serial, workspace hint or payload field. *SmartPRS 2's `/iclock` receiver
identifies a device by the `SN` query parameter alone, unauthenticated and CSRF-exempt
(`PushController.php:38-54`; SEC-C-01, CRITICAL), and `rowForSn():207` looks the serial up
across all tenants with no tenant filter and no unique index (SEC-C-03, DEF-C-02).*

**R-XI-047** `(tenant_id, device_serial)` is **unique** and every device lookup is
tenant-scoped. Two tenants may register readers with the same manufacturer serial; neither
can see the other's.

**R-XI-048** Every device holds a **per-device secret** (or a client certificate where the
firmware supports it), issued at enrolment, rotatable with an overlap window, hashed at rest
when it need only be compared and encrypted when it must be replayed. For ADMS firmware that
cannot present a credential, the compensating controls are: pre-registration of the serial,
a per-device enrolment token embedded in the device's configured server path, a tenant-scoped
IP allow-list, and quarantine-until-claimed (**D-XI-04**).

**R-XI-049** Unknown-device behaviour is a **per-tenant policy** with three settings —
`reject`, `quarantine_pending_approval` (default), `auto_register` (available only with an
explicit acknowledgement of the risk and never as a shipped default). *SmartPRS 2
auto-registers unconditionally on an unauthenticated request, at up to 1 000 rows per minute
per IP (`PushController.php:209-224`; SEC-C-02).*

**R-XI-050** No business table permits a NULL tenant (Law 6). The state that makes both of
SmartPRS 2's unique indexes inert, that makes its absence sweep skip self-hosted installs
entirely (`AbsenceService.php:96-97`, NEW-ATT-010), and that lets one tenant's admin claim
another's punches, does not exist.

**R-XI-051** Every ingest response carries a **per-punch verdict** and a batch summary
distinguishing `inserted | updated | duplicate | quarantined | rejected | dropped`. A single
`imported` number that counts updates as imports is not acceptable. *`ETimeOfficeService.php:756-768`
increments `imported` unconditionally around an `updateOrInsert`, so "Imported 420 punch(es)"
on an unchanged re-sync is meaningless and an operator cannot distinguish a working feed from
a stuck one (DEF-C-18).*

**R-XI-052** Ingest rate limits are **per credential and per device**, not per source IP,
because many devices share one NAT address. *`/iclock/*` carries `throttle:1000,1` per IP
(NEW-C-21).*

**R-XI-053** Batch size caps, body size caps and window spans are configuration, not
constants. *`MAX_BATCH = 1000` and `MAX_BODY_BYTES = 2097152` are literals (BR-ATT-015,
NEW-C-17).*

**R-XI-054** Credential-bearing endpoints refuse plaintext HTTP. *SmartPRS 2 computes a
`secure` flag and prints a warning but enforces nothing (SEC-C-15).*

**R-XI-055** Inbound webhooks are signed with HMAC over the raw body **plus a timestamp and a
nonce**, with a freshness window and replay rejection (spine S17). *SmartEPT's signature
covers the body only — no timestamp, no nonce, no freshness window — so an observer of one
signed request can replay it forever; only the derived `external_id` makes most replays
no-ops, and a replayed daily payload for a different date is fully accepted (SEC-C-16).*

**R-XI-056** A decrypt failure on a stored credential raises an operator alert and fails the
operation. It never falls back to treating the ciphertext as plaintext. *SmartPRS 2 does
exactly that, in two places (`ETimeOfficeService.php:49-55`;
`BiometricConfigController.php:363-369`; SEC-C-13) — masking the very condition it should
alert on.*

**R-XI-057** Mapping a device identity, claiming a device, rotating a credential, changing a
device's timezone and any historic re-attribution each write a field-level audit record with
before and after values through the audit service (spine S14). *None of these is audited
today (C requirement 42).*

---

## XI.6 Time

### XI.6.1 The per-adapter time policy declaration

Every adapter and every recipe **declares** one of three policies. There is no fallback that
guesses.

| Policy | Contract | On violation |
|---|---|---|
| `naive_local` | The source sends wall-clock time with no zone. The zone is the **device's** configured zone (not the server's). | A string carrying `Z` or an offset is **rejected** with `TIME_FORMAT`, because the sender is violating its own contract |
| `offset_authoritative` | The source sends an instant with a trustworthy offset or `Z`. | A string with no offset is rejected |
| `named_zone` | The source sends wall-clock time and separately declares its zone (per device or per record). | A record with no resolvable zone is rejected |

**R-XI-058** `Carbon::parse`-style permissive parsing is **forbidden** in every ingest path.
*Four of six machine paths in SmartPRS 2 fall back to it, which honours an offset in the
string and then renders it back in that offset and discards it: `2026-08-16 09:41:00+00:00`
on an Indian install is stored as `09:41:00` when the person clocked in at `15:11` — a
5½-hour error, silently, into payroll (`PushController.php:177-181`;
`ETimeOfficeService.php:217-221`; `GenericApiService.php:452-456`;
`DeviceController.php:148-149`; DEF-C-04, HIGH). The Smart Biometric Bridge path exists
specifically to avoid this and rejects offsets outright (`PunchIngestService.php:308-331`);
the older paths were never fixed.*

**R-XI-059** Date-format ambiguity is resolved by **declaration, not by attempt order**. A
recipe declares its date format; `d/m/Y` is never silently preferred over `m/d/Y`.
*`ETimeOfficeService::parseDate():208` tries `d/m/Y` and never `m/d/Y`, so a US-ordered feed
emitting `03/04/2026` is read as 3 April, and no configuration exists (B §Q5 #15).*

### XI.6.2 What is stored

**R-XI-060** Every instant is stored as `DATETIME(3)` in **UTC**, accompanied by (a) the IANA
timezone actually applied, (b) the **time policy code** under which it was interpreted, and
(c) the derived local time and business date. Dates are `DATE`, never `VARCHAR`
(spine S16). *SmartPRS 2 stores a naive `DATETIME` with no zone anywhere in the schema, under
one global `APP_TIMEZONE` whose config-file default is **UTC** while only the `.env` templates
set `Asia/Kolkata` — so an install missing that line silently runs UTC (`config/app.php:68`;
B §Q5).*

**R-XI-061** Timezone is resolvable per tenant, per company, per location **and per device**,
because a device is a physical object in a place. A device's zone is part of its registration
and is displayed in the console. *One global setting is a single-country assumption (B §5.2
b.55).*

**R-XI-062** Because the timezone assumption is recorded per punch, a later discovery that a
device was misconfigured is **correctable by recalculation** rather than by guesswork: change
the device's declared zone with an effective date, then re-derive the affected range. The raw
payload is untouched and the correction is auditable.

### XI.6.3 Scheduled work runs in the tenant's local time

**R-XI-063** Every scheduled attendance job — absence detection, notification digests,
connector polling windows, staleness checks, day closure — fires at the **tenant's**
configured local hour, not the server's. *`AbsenceNotify::handle():38` gates on
`now()->format('G')`, the server's hour, applied to every tenant regardless of location; and
with `APP_TIMEZONE` unset, `AbsenceService::runForDate():92-94` evaluates **yesterday**
between 18:30 and midnight IST, marking working employees absent (B §Q5 #8, #10).*

### XI.6.4 Cross-midnight determinism

**R-XI-064** All shift arithmetic uses absolute instants: `scheduled_start_at_utc` and
`scheduled_end_at_utc` are computed once, from the attendance day plus the shift definition
plus the applicable zone, including the day rollover for a night shift. Lateness is
`punch_at_utc − (scheduled_start_at_utc + grace)`; there is no minutes-since-midnight anywhere
in the codebase, enforced by static analysis.

**R-XI-065** DST transitions are handled by construction: a shift is anchored to a local wall
time in a named zone, converted to UTC once per day. A shift that spans a DST jump has its
scheduled duration recomputed from the two instants — so a nominal 8-hour night is 7 or 9
hours on the transition night, and the settlement engine sees the true scheduled minutes.
(India does not observe DST; this matters for any deployment outside IST — **D-XI-14**.)

**R-XI-066** Clock skew on a device is detected, not absorbed: the ingest core records
`recorded_at − occurred_at` per batch, and a device whose skew exceeds a configured threshold
raises a health warning and, at a second configured threshold, quarantines its punches for
review. Adapters with the `time_sync` capability can be scheduled to correct the device clock.

---

## XI.7 The Biometric Management Console *(brief Section 16)*

One screen family, replacing SmartPRS 2's split between the SPA "Biometric Device Setup"
card, the legacy Blade *Pending Punches* page and the invisible *unassigned devices* list
(`AppController.php:9599-9790`; `resources/views/settings/pending-punches.blade.php`;
DEF-C-11 — the SPA fetches `unassigned` and never renders it, so the 18-Aug hotfix's headline
fix is unreachable in the interface users actually use).

### XI.7.1 Device registry — fields

| Field | Type | Notes |
|---|---|---|
| Device name / label | Text(120) | |
| Device UUID | UUIDv7 | the only identifier in URLs and the API |
| Serial number | Text(120) | unique per tenant (R-XI-047) |
| Make, model, firmware as reported | Code, Code, Text | reported values are evidence, not configuration |
| Adapter | Code | from the registry; determines every field below it |
| Vendor profile | Code | status-byte map, tokens, formats |
| Connection configuration | typed by `connectionSchema()` | rendered from the adapter, never hard-coded |
| Credential | handle | write-only; never returned to the browser |
| Org placement | `org_unit_id` FK | **a foreign key, not a name string** — *SmartPRS 2 stores `biometric_configs.branch` as a varchar copied from `branches.name`, so renaming a branch orphans every device silently (CONF-C-04)* |
| Company | `org_unit_id` FK | *the column SmartPRS 2's claim screen validates and then discards because it does not exist (DEF-C-08)* |
| Timezone | IANA zone | per device (R-XI-061) |
| Reader topology | Code | `single_alternating`, `dual_in_out`, `device_flag`, `always_in`, `machine_map` |
| IN reader / OUT reader | reader refs | **references to registered reader entities**, with one meaning — *SmartPRS 2's `in_machine_id` means the device serial on `/iclock` and a record field on the pull paths, under one UI label (CONF-C-03)* |
| Double-tap window | Seconds | per device (R-XI-019) |
| Identity strategy list + code transform | ordered list | §XI.4.2, §XI.4.4 |
| Direction token map | map | including the `unknown` default (D-XI-05) |
| Time policy | Code | declared, shown read-only from the adapter unless the adapter offers a choice |
| Schedule | cron or interval + window | arbitrary intervals and an active window (e.g. 06:00–22:00), not a fixed dropdown — *SmartPRS 2 offers 5/10/15/30/60 only, with a hard-coded default of 60 (BR-DEV-008, D-XI-11)* |
| Enabled / lifecycle state | Code | `draft`, `pending_claim`, `active`, `suspended`, `retired` |
| Unknown-device policy (tenant) | Code | `reject`, `quarantine`, `auto_register` |
| Notes | Text | |

### XI.7.2 Functions

| Function | Behaviour |
|---|---|
| **Detect / discover** | For adapters with `health_probe` or `user_list`: probe an address range or a vendor cloud and list candidate devices with make, model, firmware and serial |
| **Test connection** | Read-only; returns a **field-level parse table** (raw value → mapped field → typed value) plus a verdict, redacted and size-capped (R-XI-034) |
| **Map and validate** | For recipes and delimited sources: the operator picks a sample record and binds columns to canonical fields; the console writes the column map into the recipe. This is what replaces field sniffing (DEF-C-15/16) |
| **Force sync now** | Runs a bounded window on demand; shows the resulting verdict counts |
| **Backfill** | Re-fetch an explicit historical range with a stated reason; verdicts and duplicates are shown, nothing is overwritten |
| **Command console** | For `command` adapters: queue clock sync, enrol/delete user, fetch templates, reboot, diagnostic ping (§XI.7.5) |
| **Employee mapping** | The worklist of unmapped identities, grouped by device; mapping releases held punches (§XI.7.4) |
| **Claim device** | Assign a pending device to a tenant/company/org unit; visible in the **main** console, not only in a legacy page (DEF-C-11) |
| **Rotate credential** | Two live secrets with an overlap window (C requirement 35 — SmartPRS 2 supports revoke-and-reissue only, NEW-C-15) |
| **Suspend / retire** | Suspending stops ingest but keeps history; retiring closes all identity mappings with an effective date |
| **Health** | §XI.7.3 |
| **Live feed** | For `realtime` adapters: a tailing view of the last N punches with verdicts, for installation and commissioning |

### XI.7.3 The device health record and staleness alerting

`device_health_snapshots` (rolling) plus derived fields on `devices`:

| Field | Meaning |
|---|---|
| `last_contact_at` | Any inbound or outbound contact |
| `last_successful_fetch_at` | Last window that settled |
| `last_punch_at` | Last accepted punch |
| `consecutive_failure_count` | Reset only by a success |
| `last_error_class`, `last_error_detail` | Typed class (`auth`, `network`, `tls`, `parse`, `rate_limit`, `schema`, `unknown`) |
| `punches_24h`, `punches_7d` | Volume, for "the device is up but nobody is punching" |
| `expected_cadence_minutes` | From the schedule, or the adapter's realtime expectation |
| `clock_skew_seconds` | From `recorded_at − occurred_at` |
| `firmware_reported`, `serial_reported` | As reported, versus as registered |
| `health_state_code` | `healthy`, `degraded`, `stale`, `failing`, `never_seen`, `quarantined` |

**R-XI-067** Health state is derived on a schedule and on every attempt, and a device that
has not reported within `staleness_threshold_minutes` (default: three times its expected
cadence, per device) raises a `device.stale` event routed to the configured audience.
*SmartPRS 2 has no staleness monitor, no offline notification and no dashboard of any kind
(C §5.2 item 22).*

**R-XI-068** The console's device list shows the **real** state. A failing device may never
display a green last-sync timestamp (DEF-C-14).

### XI.7.4 The quarantine and mapping worklist

**R-XI-069** One quarantine, one replay implementation, device-scoped by default. *SmartPRS 2
has two quarantine writers with different keys, and `PunchIngestService::replayPending()` is
**dead code with no callers** while three docblocks name it as the live mechanism — the live
one is `ETimeOfficeService::promotePending()`, and the two have already diverged on what an
unknown direction becomes (`unknown` vs `in`) (DEF-C-07).*

The worklist shows, per unmapped identity: device, raw identity value, transformed candidate,
first seen, last seen, punch count, sample instants, and the suggested employment (by code
match, offered as a suggestion the operator must confirm — never applied automatically).
Mapping an identity:

1. Writes a dated `device_identity_map` row.
2. Replays the held `punch_events` through the **normal** core lifecycle — the same code path,
   producing the same verdicts, not a parallel implementation.
3. Stamps each released event with its resolution.
4. Triggers `recalculate` for the affected employee-days.
5. Writes an audit record.

**R-XI-070** Replay never bypasses de-duplication, tenancy or the time policy, because it is
the same pipeline.

**R-XI-071** The unmapped worklist is **derived from the quarantine** by grouping, not
maintained as a separate counter table. *SmartPRS 2's `biometric_unmapped` is a duplicate
counter created at runtime by an unauthenticated request (`ETimeOfficeService::recordUnmapped():361-371`;
DEF-C-09) — one of three tables in this area with no migration at all.*

### XI.7.5 The command channel

**R-XI-072** For adapters declaring `command`, SmartPRS maintains a per-device command queue
with states `queued → delivered → acknowledged | failed | expired`, each with an actor, a
reason and an audit record. At launch the command set is: clock sync, enrol user, delete
user, fetch user list, fetch templates, push templates (permission-gated separately), reboot,
and diagnostic ping. *SmartPRS 2 answers every `getrequest` poll with the literal string
`"OK"`, so remote enrolment, deletion, clock sync and reboot are impossible
(`PushController.php:57-62`; NEW-C-08).*

**R-XI-073** The ADMS option block handed to a device — `TransTimes`, `TransFlag`, `Delay`,
`ErrorDelay`, `Stamp`, `Encrypt`, `TransInterval`, `Realtime` — is **per-device
configuration**, not literals. *All are hard-coded at `PushController.php:89-100` (NEW-C-07).*

### XI.7.6 Counting: inserted, updated, duplicate, quarantined, dropped

**R-XI-074** Every ingest attempt reports five disjoint counts, and they reconcile:
`lines_received = inserted + updated + duplicate + quarantined + rejected`. "Dropped" is not
a category, because nothing is dropped — a line that cannot be parsed is `rejected` **with a
stored layer-1 row and a reason code**, and is reportable and re-processable after the recipe
or profile is fixed. *SmartPRS 2 reports one number that conflates inserts and updates
(DEF-C-18) and silently loses the rest (DEF-C-17).*

---

## XI.8 The shift master *(brief Section 18)*

### XI.8.1 A shift is a configuration artefact

A shift is an artefact under spine S4: versioned, effective-dated, scoped, draft/publish,
diffable and approvable. Editing a shift **never** rewrites history; it creates a version with
an effective date, and a recalculation of past days uses the version in force on those days.
*SmartPRS 2's `shifts` table has no validity columns at all, no unique constraint on
`(tenant_id, name)` — and `ShiftResolver::shifts()` keys by lowercase name, so duplicate names
silently collapse and the last row wins (`ShiftResolver.php:58`; B §2.3).*

### XI.8.2 Fields

| Group | Field | Type | Notes |
|---|---|---|---|
| Identity | `shift_code` | Code | stable; **all references are by code or id, never by name** |
| | `name`, `short_name`, `colour` | Text | display only |
| | `shift_type_code` | Code | `fixed`, `flexible`, `split`, `open`, `night`, `rotational_slot` |
| Timing | `start_time`, `end_time` | Time | wall clock in the applicable zone |
| | `crosses_midnight` | Boolean | **derived from the definition and stored**, not inferred from `end <= start` — *a 24-hour shift is classed as night by `ShiftResolver.php:62` (BR-ATT-019)* |
| | `is_night_shift` | Boolean | **explicit flag**, drives night allowance and night-work rules independently of whether it crosses midnight |
| | `scheduled_minutes` | Minutes | declared, or derived from start/end minus unpaid breaks |
| Attendance day | `day_attribution_rule_code` | Code | §XI.8.4 |
| | `day_boundary_time` | Time | for the `fixed_boundary` rule |
| | `pre_window_minutes`, `post_window_minutes` | Minutes | punch attraction tolerance around the shift |
| Grace | `grace_in_minutes`, `grace_out_minutes` | Minutes | may be overridden by an `attendance.grace` rule (Part VII RL-ATT-003) |
| | `grace_mode_code` | Code | `forgive_within_grace` (late = 0 inside grace) or `count_from_start` (grace only suppresses the penalty) |
| Day value | `full_day_minutes`, `half_day_minutes` | Minutes | in minutes, so "8.5 hours" is exact |
| | `min_minutes_for_presence` | Minutes | the presence gate (Part VII RL-ATT-006, D-XI-03) |
| Breaks | `break_budget_minutes` | Minutes | total permitted |
| | `break_mode_code` | Code | `budget` (any breaks up to N) or `scheduled_windows` |
| | breaks child rows | `from`, `to`, `is_paid`, `is_mandatory` | scheduled windows; paid breaks count as worked |
| | `auto_deduct_break_minutes` | Minutes | deducted when no break punches exist |
| Pairing | `pairing_strategy_code` | Code | §XI.8.5 |
| | `max_session_minutes` | Minutes | a session longer than this is flagged, not silently worked |
| | `missing_punch_policy_code` | Code | `flag_only`, `assume_shift_end`, `assume_last_punch`, `treat_as_absent`, `hold_for_regularisation` |
| Overtime | `ot_eligible` | Boolean | |
| | `ot_starts_after_minutes` | Minutes | after scheduled minutes, or after a declared threshold |
| | `ot_rounding_block_minutes`, `ot_rounding_mode` | Minutes, Code | `floor \| ceil \| nearest` — never a default |
| Flexible | `core_start_time`, `core_end_time` | Time | mandatory presence window |
| | `flex_band_start`, `flex_band_end` | Time | permitted arrival/departure band |
| | `daily_hours_required` | Minutes | what flexi actually enforces |
| | `flex_settlement_window_code` | Code | `day \| week \| payroll_period` — where a shortfall is netted |
| Split | segment child rows | `seq`, `start`, `end`, `min_gap_minutes`, `max_gap_minutes` | two or more worked segments in one attendance day |
| | `gap_is_paid` | Boolean | |
| Allowances | `night_allowance_ref` | Code | a formula reference; the money lives in Part XIV |
| | `shift_allowance_ref` | Code | |
| Governance | `scope`, `priority`, `effective_from`, `effective_to`, `status`, `version_no` | | spine S4 |

**R-XI-075** There is no hard-coded shift anywhere in SmartPRS 3. A tenant with no shift
configured is a **configuration error surfaced at onboarding**, not a silent 09:30–18:30.
*`AttendanceReportController.php:26-27`, `PayrollGenController.php:37-38`,
`LateArrivalService.php:29` and `TodayController.php:138` each carry their own 09:30/18:30
literal, and the report's JSON header advertises the class constants regardless of the shift
actually resolved (`:170`, NEW-ATT-022).*

**R-XI-076** `is_night_shift` and `crosses_midnight` are separate, explicit properties.

### XI.8.3 Shift resolution — one service, explicit precedence

```
resolveShift(employment, attendance_day) →
    { shift_config_version_id, source_code, scheduled_start_utc, scheduled_end_utc }
```

Precedence, itself configuration (an ordered list per tenant, not a code path):

| # | Source | Notes |
|---|---|---|
| 1 | `roster_days` entry for `(employment, work_on)` | the published roster |
| 2 | Rotation pattern assignment in force on that date | computed, not stored per day, when the pattern is deterministic |
| 3 | Shift group assignment (effective-dated) | |
| 4 | Employment default shift (effective-dated assignment, spine S3) | |
| 5 | Org-unit default shift | |
| 6 | Tenant default shift | |
| — | *none* | **configuration error**: the day is derived with `status = NEEDS_SHIFT` and flagged; never silently defaulted |

**R-XI-077** Exactly one service resolves a shift, and every consumer — day derivation,
report, ESS, mobile, absence detection, payroll, dashboards — calls it. *SmartPRS 2 has the
report and payroll consulting `ShiftResolver` while the late-arrival email and the Today panel
use the late policy's `shift_start` with **no shift awareness at all**
(`LateArrivalService.php:208`; `TodayController.php:138`), so a night-shift employee is judged
against 09:30 by the email and against 22:00 by the report, on the same day (DEFECT-B-05,
NEW-ATT-008).*

### XI.8.4 Cross-midnight handling — the attendance-day attribution rule

The attendance day is **derived**, by a rule declared on the shift (and overridable per
tenant), never by the punch's calendar date.

| Rule code | Definition | Typical use |
|---|---|---|
| `shift_start_owns` | The whole shift belongs to the calendar date on which it **starts** | The default recommendation for night shifts: a 22:00–06:00 shift on 30-Sep is one attendance day, 30-Sep, and its hours stay in September's payroll |
| `shift_end_owns` | The shift belongs to the date on which it ends | Used where a customer's night allowance convention pays on the ending date |
| `fixed_boundary` | The attendance day runs from `day_boundary_time` to the same time next day (e.g. 04:00 → 04:00) | Sites with overlapping or irregular shifts |
| `punch_window` | A punch belongs to the shift whose window it falls in, extended by `pre_window_minutes`/`post_window_minutes`; the day is that shift's attendance day | 24×7 rotating operations |
| `calendar_date` | The punch's own calendar date | Explicitly available for day-shift-only tenants who want it, and for reproducing legacy behaviour during migration |

**R-XI-078** The attribution rule is resolved per employee-day (from the resolved shift), is
recorded on the `attendance_days` row, and is part of the recalculation inputs.
**PRODUCT OWNER DECISION REQUIRED — D-XI-01** on the platform default and on how historic
night-shift punches are re-attributed at migration.

**R-XI-079** A punch that no shift window can attract — because the employee punched hours
outside any scheduled shift — is attributed by the tenant's fallback rule and the day is
flagged `UNSCHEDULED_PUNCH` for review. It is never discarded and never silently attached to
the wrong day.

### XI.8.5 Punch-pairing strategies

One implementation, selected per shift.

| Strategy | Behaviour |
|---|---|
| `direction_aware` | Use `in`/`out` (and `break_in`/`break_out`) as given; an IN while a session is open is a double-tap (configurable window); an OUT with nothing open is an orphan and is **flagged**, not silently ignored |
| `alternation` | Ignore direction; odd punches open, even punches close, after double-tap collapse |
| `first_in_last_out` | One session from the first IN to the last OUT; interior punches recorded but not paired |
| `hybrid` | `direction_aware` when the day has at least one IN and one OUT; otherwise `alternation` — the behaviour SmartPRS 2 hard-codes for everyone |

**R-XI-080** There is exactly one pairing implementation, and its outputs (worked minutes,
break minutes, session list) are stored on the attendance day and consumed by everyone.
*SmartPRS 2 implements pairing twice — `AttendanceReportController::pairPunches():621-712` and
`PayrollGenController::dayStats():346-405` — with both docblocks asserting they must never
disagree, and they already do: the first records a break for every inter-session gap
(`:686-690`), the second only when a session is re-opened (`:384-388`), so a day ending on an
unclosed IN yields different break totals — and break totals drive a payroll day-cut
(DEFECT-B-08, HIGH). A third definition (first-to-last elapsed, breaks included) drives the
employee-facing Today panel (`TodayController.php:51-53`, DEFECT-B-13).*

**R-XI-081** Worked minutes are computed from instants at second precision and rounded once,
by a declared mode, at a declared point. *SmartPRS 2 truncates by integer cast at every
comparison (CALC-B-01).*

**R-XI-082** An orphan OUT, an unclosed IN, a session exceeding `max_session_minutes` and an
out-of-window punch each produce a typed **day exception** carried on the attendance day and
surfaced in the exceptions queue and to the employee in ESS.

### XI.8.6 Weekly-off and holiday resolution

**R-XI-083** There is **one** weekly-off evaluator: the Part VII decision point
`attendance.calendar.weekly_off`, materialising `is_weekly_off` per employee-day, supporting
fixed day(s), alternate/nth actual weekday (not `ceil(day/7)`), rotational patterns, per-shift
patterns, and roster-driven offs. *SmartPRS 2 has four mutually incompatible definitions
(§XI.1.8) and the only one that understands alternate Saturdays is invisible to the attendance
layer.*

**R-XI-084** Holidays are **scoped** — by company, establishment, state, branch, location,
employee category, and as optional/floating holidays with an employee election. A holiday
resolves per employee, not per tenant. *SmartPRS 2's `holidays.applies_to` column has no
reader and one holiday cancels absence detection for the entire tenant
(`AbsenceService.php:111-113, 225-236`; NEW-ATT-011).*

### XI.8.7 Effective dating and shift changes

**R-XI-085** Changing an employee's shift is an **effective-dated assignment**, not a column
update, so "which shift was this employee on, on 15 May?" is answerable by one service call
(spine S3, Law 3). Publishing a shift version that changes past days offers the recalculation
diff (R-XI-014) before it takes effect.

---

## XI.9 Roster management *(brief Section 19)*

### XI.9.1 Model

`rosters` (a header: name, org scope, period, source pattern version, lifecycle state) and
`roster_days` (one row per employment per day: shift version, weekly-off flag, source). A
roster is `draft → published → superseded`; publishing writes an immutable version and emits
`roster.published`.

**R-XI-086** `roster_days` is unique on `(tenant_id, employment_id, work_on)` — one shift per
person per day, enforced by the database. Multi-shift days are expressed as a **split shift**
(§XI.8.2), not as two roster rows. *SmartPRS 2's `roster` table has **no migration at all**,
no uniqueness, and is keyed by employee display name (B §2.3, NEW-ATT-004).*

**R-XI-087** Employees are identified in every roster operation — screen, import, export, API
— by **UUID or employment id, with employee code as a human-readable secondary**. Never by
name (R-XI-043).

### XI.9.2 The eight ways to build a roster

| # | Method | Behaviour |
|---|---|---|
| 1 | **Manual** | Grid of employees × days; click or drag a shift onto cells; keyboard entry by shift code |
| 2 | **Bulk edit** | Select a rectangular range (employees × dates) and apply one shift, one weekly-off, or clear |
| 3 | **Excel import** | §XI.9.3 |
| 4 | **Copy previous** | Copy a named source period onto a target period, with alignment by weekday or by day offset, and a rule for holidays landing differently |
| 5 | **Mass assign** | Assign one shift to an entire scope (department, branch, shift group, employee category) for a date range |
| 6 | **Date-range assign** | Assign a shift to selected employees over an explicit range, honouring exclusions (weekly offs, holidays, leave) by policy |
| 7 | **Recurring assignment** | "Every Monday and Thursday, NIGHT-2200, from 01-Oct to 31-Dec" |
| 8 | **Rotation pattern** | §XI.9.4 |

Every method funnels into one **roster draft**, validated once, published once.

### XI.9.3 Excel import with a live shift dropdown

**R-XI-088** The import template is **generated from the live shift master** for the target
scope and period. The shift column is a data-validation dropdown listing exactly the shift
codes and names in force for that scope on those dates; the employee column is pre-populated
with employment UUID (hidden), employee code and name (display only, never a key); the date
columns are real dates in the template's declared format.

**R-XI-089** Import validation is server-side and produces a **row-level report** before
anything is written: unknown employee, employee not active on that date, shift not in force on
that date, shift not permitted for that employee's scope, duplicate row, date outside the
roster period, and conflict with an approved leave. The operator sees the full report, may
download it, and confirms; partial application is explicit, never implicit.
*SmartPRS 2's bulk attendance sheet is parsed **in the browser**, capped at 3 000 rows and 500
per chunk as literals, and — on any freshly migrated install — writes columns that do not
exist and omits a NOT NULL column, returning a raw SQL error string to HR in a 422
(`AttendanceBulkController.php:35-56, 168-170, 253, 260-262`; DEFECT-B-03).*

**R-XI-090** Excel numeric date and time cells are converted by declared rules, and the 1904
Mac epoch is handled. *`AttendanceBulkController::normDate():129-153` handles the 1900 system
only.*

### XI.9.4 Rotation patterns

A pattern is a configuration artefact: an ordered cycle of slots, each slot being a shift code
or a weekly off, with a cycle length, a start anchor date, and per-employee phase offsets.

```
pattern: 4-ON-2-OFF-3SHIFT
  cycle_days: 18
  slots: [MORNING ×4, OFF ×2, EVENING ×4, OFF ×2, NIGHT ×4, OFF ×2]
  anchor_date: 2026-04-01
  phase_offsets: { crew A: 0, crew B: 6, crew C: 12 }
  weekly_off_generation: from_slots        # OFF slots ARE the weekly offs
```

**R-XI-091** A rotation pattern generates `roster_days` deterministically for any date range,
so a roster can be regenerated identically, and a future period can be previewed without being
published. Patterns support n-on/n-off, rotating weeks, crew phases, and an explicit
skip/shift behaviour for holidays.

### XI.9.5 Automatic weekly-off generation

**R-XI-092** Publishing a roster materialises weekly offs from, in precedence order: explicit
roster cells → the rotation pattern's OFF slots → the employee's weekly-off policy (fixed
day(s), alternate/nth weekday) → the org default. The result is stored per day, so a weekly
off is a **visible fact on the muster roll**, not the absence of a row.

**R-XI-093** Weekly-off generation is idempotent and re-runnable, and re-running it after a
policy change produces a diff, not a silent overwrite.

### XI.9.6 Validation at publish

Publishing a roster runs, and blocks on: an employee with no shift on a working day (unless
the tenant permits gaps), a shift not in force on that date, an employee rostered outside
their employment range, a rest-period violation (`min_rest_hours_between_shifts`), a
consecutive-working-days violation, a night-shift-to-morning-shift turnaround violation, and a
conflict with approved leave. Each is a validation rule with a declared severity
(`block | warn`) per tenant (Part VI §VI.9).

**R-XI-094** A published roster is immutable. A change creates a new version with an
effective date, and changes to past days trigger the recalculation preview.

### XI.9.7 Roster and attendance

**R-XI-095** The roster is an **input** to day derivation, never a record of what happened.
`roster_days.is_weekly_off` sets `day.is_weekly_off`; the shift sets the schedule; neither
asserts presence.

---

## XI.10 The Universal Work-Time Policy Engine *(brief Sections 22, 23 and 25)*

### XI.10.1 What it is

There is no "late policy engine", no separate "break engine" and no "OT module". There is one
**work-time policy**: a set of effective-dated, scoped rules evaluated by the Part VII rule
engine at five attendance decision points, producing typed actions that the attendance domain
service applies inside its own transaction.

*SmartPRS 2's late policy is resolved by four separate implementations with different
defaults (`LateArrivalService::effectivePolicy():163-214`,
`AttendanceReportController::attendanceFlags():800-822`, `TodayController::lateFlag():112-134`,
`PayrollGenController::resolvePolicy():272-319`; DEFECT-B-05), across three scope levels
(company/team/employee) with ties broken by row order and no effective dating (NEW-ATT-019),
against a table whose every meaningful column is created at runtime by the master screen
(`MasterController::ensureCols()`, DEFECT-B-06) — so a site that has never opened the Late
Policy screen has a table that cannot express any rule the code implements, and every reader
compensates with `?? 0` so the failure is silent.*

### XI.10.2 The decision points (Part VII §3.2)

| Decision point | When | Emits |
|---|---|---|
| `attendance.calendar.weekly_off` | Calendar materialisation, per employee-day | `SET_DAY_TYPE` |
| `attendance.grace` | Before lateness is computed | `SET_GRACE` |
| `attendance.day.classify` | After punches are paired | `SET_STATUS`, `FLAG_FOR_REVIEW`, `NOTIFY` |
| `attendance.day.penalise` | After classify | `APPLY_DAY_PENALTY`, `APPLY_LOP`, `OFFSET_SHORTFALL`, `FLAG_FOR_REVIEW`, `NOTIFY` |
| `attendance.day.earn` | After penalise | `CREATE_OT`, `CREATE_COMP_OFF`, `NOTIFY` |

**R-XI-096** The attendance domain service calls these five decision points in this fixed
order, once per employee-day, and applies the returned actions transactionally. Rules never
mutate state and never call each other (Part VII R-VII-028).

### XI.10.3 The condition vocabulary

Everything a work-time rule may test. All of it is SPX (spine S5, Part VII §5.7); nothing here
is new syntax.

| Family | Symbols available |
|---|---|
| Shift | `shift.code`, `shift.start`, `shift.end`, `shift.hours`, `shift.grace_in`, `shift.grace_out`, `shift.is_night`, `shift.full_day_minutes`, `shift.half_day_minutes`, `shift.break_budget_minutes`, `shift.source` |
| Day | `day.date`, `day.weekday`, `day.is_weekly_off`, `day.is_holiday`, `day.holiday_type`, `day.is_working_day`, `day.day_type`, `day.status` (after classify) |
| Attendance | `attendance.first_in`, `.last_out`, `.punch_count`, `.session_count`, `.worked_minutes`, `.late_minutes`, `.early_exit_minutes`, `.late_exit_minutes`, `.break_minutes`, `.shortfall_minutes`, `.ot_minutes`, `.status`, `.has_in`, `.has_out`, `.source_mix`, `.is_corrected` |
| Employee / org | `employee.*`, `org.*` (as at the day), `service.*` |
| Leave | `leave.on_date(d)`, `leave.type`, `leave.day_part`, `leave.balance(code)` |
| Period | `period.start`, `.end`, `.working_days`, `.payable_days`, `.cut_off_day` |
| Policy / statutory | `policy.value(key)`, `statutory.value(code)` |
| Aggregates | `agg.count(event, window, {pred})`, `agg.sum(measure, window, {pred})`, `agg.occurrence_index(...)`, `agg.streak(condition, direction)`, `agg.collapse_repeats(punch, n)`, `agg.nth(punch, day, order, n)`, `agg.balance(ledger, as_of)` |
| Windows | `day`, `week(start_day)`, `payroll_period`, `calendar_month`, `quarter`, `financial_year`, `rolling(n, unit)`, `since(date)`, `service_year` |

**R-XI-097** Every threshold in a work-time rule is either an inline typed literal in the
rule (visible, versioned, diffable) or `policy.value('<key>')` (tunable without opening the
rule). **No threshold is in code.** *SmartPRS 2 carries at minimum twenty-eight attendance
literals, catalogued in B §Q3 — including the geofence grace ladder `{strict:0, 1km:1.0,
2km:2.0}`, the PDF's unrelated 90-minute break highlight, the rating scale 1–10, and the
2-minute clock-skew tolerance.*

### XI.10.4 The action vocabulary

The closed attendance set (Part VII §6.2), with what each means here:

| Action | Meaning in attendance | Effect class |
|---|---|---|
| `SET_DAY_TYPE(type)` | `working \| weekly_off \| holiday \| non_working` | derived |
| `SET_GRACE(minutes)` | Overrides the grace used for lateness on this day | derived |
| `SET_STATUS(code)` | The day's status from the tenant-extensible `attendance_status` value set | derived |
| `APPLY_DAY_PENALTY(fraction, reason, cap_ref)` | Adds a day-fraction penalty; additive across matched rules; capped by the referenced cap artefact | derived |
| `APPLY_LOP(amount, reason)` | Loss of pay in `Days` or `Hours` — the unit is declared, never coerced | derived |
| `OFFSET_SHORTFALL(minutes, source, cap)` | Reduces lateness/shortfall by made-up or extra time, capped | derived |
| `CREATE_OT(minutes, multiplier_ref, approval)` | Creates an OT entitlement — `auto` or via workflow | ledger |
| `CREATE_COMP_OFF(units, expiry_ref, reason)` | Credits the comp-off ledger | ledger |
| `FLAG_FOR_REVIEW(reason, severity)` | Raises a day exception in the queue | derived |
| `NOTIFY(event, audience_ref, dedupe_key)` | Emits a domain event; channel, template, language and throttle belong to the notification engine | terminal |

**R-XI-098** `NOTIFY` never sends mail. *SmartPRS 2's late email is `Mail::raw` called inline
from three ingest paths (`ETimeOfficeService.php:777`, `AttendanceBulkController.php:353`,
`DeviceController.php:177`), which is precisely why `NotifyLateArrivals` exists for the fourth
(BR-ATT-026, DEFECT-B-15).*

**R-XI-099** The statuses expressible must include at least: `PRESENT`, `HALF_DAY`, `ABSENT`,
`WEEKLY_OFF`, `HOLIDAY`, `ON_LEAVE` (by type), `ON_DUTY`, `WORK_FROM_HOME`, `COMP_OFF_AVAILED`,
`NOT_JOINED`, `EXITED`, `NEEDS_SHIFT`, `UNDER_REVIEW`. The vocabulary is a value set, so a
tenant may add one. *SmartPRS 2 has no stored status at all; three dead, mutually inconsistent
status vocabularies exist in the repository (table enum `{full,half,absent,compoff}`, model
`$fillable` naming non-existent columns, controller whitelist
`{present,absent,leave,holiday,weekoff}`) and none agrees with any live path (B §2.4,
DEFECT-B-28).*

### XI.10.5 The thirteen rule families, with their SmartPRS 2 origin

| # | Family | Decision point | Replaces |
|---|---|---|---|
| 1 | Late coming | grace + penalise | `PayrollGenController::tierCut():482-495`, three tiers with a hidden L1 fall-through (BR-ATT-021b) |
| 2 | Early going | penalise | nothing — `early_min` is computed and discarded (CALC-B-10) |
| 3 | Late logout | penalise / earn | nothing — `overtime_min` is display only |
| 4 | Worked hours | classify | `net_hours` mode, tenant-wide only (CALC-B-07) |
| 5 | Break violation | penalise | `ceil((break−budget)/30)×0.25` with literal block and step (CALC-B-08) |
| 6 | Missing punch | classify + review | nothing — an unclosed IN silently yields `worked = 0` |
| 7 | Excess break | penalise | as (5) |
| 8 | Insufficient hours | classify + penalise | as (4) |
| 9 | Overtime | earn | manual register only (`MasterController.php:339-345`) |
| 10 | Weekly-off work | earn | nothing |
| 11 | Holiday work | earn | nothing |
| 12 | Night work | earn | `shifts.night_allowance` exists but the night cannot be represented (§XI.1.1) |
| 13 | Split shift | classify + penalise | does not exist |
| — | Compensation and grace | grace + penalise (`OFFSET_SHORTFALL`) | does not exist |

Part VII's worked rule library already carries RL-ATT-001 to RL-ATT-012 and RL-CAL-001 for
families 1, 5, 9, 10, 11 and the calendar. The rules below are the families Part VII does not
already express, written to the same conventions.

### XI.10.6 Worked SPX

**WT-01 · Early going — leaving before shift end beyond grace**

```
decision_point : attendance.day.penalise
scope          : { ALL }        specificity : 0     priority : 0     on_conflict : all_match
```
```sql
-- WHEN
attendance.early_exit_minutes > shift.grace_out
AND attendance.worked_minutes < shift.full_day_minutes

-- EXCEPT
day.is_weekly_off OR day.is_holiday OR attendance.is_corrected

-- THEN
1. APPLY_DAY_PENALTY(
       fraction = BANDS(attendance.early_exit_minutes, [
           { from: shift.grace_out,                          to: policy.value('early_band_1_min'), result: 0.0d },
           { from: policy.value('early_band_1_min'),         to: policy.value('early_band_2_min'), result: 0.25d },
           { from: policy.value('early_band_2_min'),         to: NULL,                             result: 0.5d } ]),
       reason  = CONCAT('Early departure ', attendance.early_exit_minutes, ' minutes'),
       cap_ref = 'ATT-PENALTY-CAP-DAY')
2. FLAG_FOR_REVIEW(reason = 'Early departure', severity = 'low')
```

**WT-02 · Missing punch — an unclosed session**

```
decision_point : attendance.day.classify
scope          : { ALL }        specificity : 0     priority : 20    on_conflict : highest_priority_only
```
```sql
-- WHEN
attendance.punch_count > 0
AND (NOT attendance.has_out OR NOT attendance.has_in)

-- THEN
1. SET_STATUS(code = CASE
        WHEN policy.value('missing_punch_policy') = 'treat_as_absent'          THEN 'ABSENT'
        WHEN policy.value('missing_punch_policy') = 'hold_for_regularisation'  THEN 'UNDER_REVIEW'
        ELSE 'UNDER_REVIEW'
     END)
2. FLAG_FOR_REVIEW(reason = 'Missing punch', severity = 'medium')
3. NOTIFY(event = 'attendance.missing_punch',
          audience_ref = 'AUD-EMP-AND-MANAGER',
          dedupe_key = CONCAT('mp:', employee.code, ':', day.date))
```

Where the policy is `assume_shift_end`, the settlement engine (not a rule) imputes the missing
OUT at `scheduled_end_at_utc`, records `imputed_out = true` on the day, and the ESS card shows
the imputation explicitly. **Nothing is imputed silently.**

**WT-03 · Insufficient hours (worked-hours gate, independent of lateness)**

```
decision_point : attendance.day.classify
scope          : { ALL }        specificity : 0     priority : 10    on_conflict : highest_priority_only
```
```sql
-- WHEN
attendance.punch_count > 0
AND attendance.worked_minutes < shift.full_day_minutes

-- THEN
SET_STATUS(code = CASE
      WHEN attendance.worked_minutes >= shift.half_day_minutes THEN 'HALF_DAY'
      WHEN attendance.worked_minutes >= shift.min_minutes_for_presence THEN 'HALF_DAY'
      ELSE 'ABSENT'
   END)
```

**WT-04 · Late logout beyond the shift becomes approved overtime, not an accident**

```
decision_point : attendance.day.earn
scope          : { ALL }        specificity : 0     on_conflict : all_match
```
```sql
-- WHEN
shift.ot_eligible
AND attendance.late_exit_minutes > policy.value('ot_min_trigger_min')
AND day.is_working_day

-- EXCEPT
NOT policy.value('ot_enabled')
 OR (policy.value('ot_requires_pre_approval') AND NOT attendance.has_ot_authorisation)

-- THEN
CREATE_OT(minutes = STEP(attendance.late_exit_minutes,
                         policy.value('ot_rounding_block_min'),
                         policy.value('ot_rounding_block_min'),
                         policy.value('ot_rounding_mode')),
          multiplier_ref = 'OT-MULT-WEEKDAY',
          approval       = policy.value('ot_approval_mode'))
```

**WT-05 · Night work — an allowance per night actually worked**

```
decision_point : attendance.day.earn
scope          : { ALL }        specificity : 0     on_conflict : all_match
```
```sql
-- WHEN
shift.is_night
AND attendance.worked_minutes >= policy.value('night_allowance_min_minutes')
AND OVERLAP_MINUTES(attendance.first_in, attendance.last_out,
                    policy.value('night_window_from'), policy.value('night_window_to'))
    >= policy.value('night_window_min_minutes')

-- THEN
CREATE_OT(minutes = 0m, multiplier_ref = 'NIGHT-ALLOWANCE', approval = 'auto')
```

*(The allowance is a formula reference resolved in Part XIV; the rule decides only
entitlement. `OVERLAP_MINUTES` is the night-window overlap function — it exists because a
22:00–06:00 shift and a 20:00–04:00 shift attract different night hours, which SmartPRS 2
cannot express because it cannot represent the night at all.)*

**WT-06 · Split shift — the gap is not a break**

```
decision_point : attendance.day.penalise
scope          : { shift IN ['SPLIT-RETAIL'] }     specificity : 330     on_conflict : all_match
```
```sql
-- WHEN
shift.type = 'split'
AND attendance.session_count < shift.required_segment_count

-- THEN
1. FLAG_FOR_REVIEW(reason = 'Split shift segment not worked', severity = 'medium')
2. APPLY_DAY_PENALTY(
       fraction = policy.value('split_missed_segment_penalty'),
       reason   = 'Missed split-shift segment')
```

For a split shift, the inter-segment gap is **not** counted as break time and does not consume
the break budget; the settlement engine subtracts declared gap minutes from elapsed time before
comparing against the budget.

**WT-07 · Weekly-off work — pay, comp-off, or both, by configuration**

```
decision_point : attendance.day.earn
scope          : { ALL }        specificity : 0     on_conflict : all_match
```
```sql
-- WHEN
day.is_weekly_off AND attendance.worked_minutes >= policy.value('comp_off_min_minutes')

-- EXCEPT
day.is_holiday                     -- Part VII RL-ATT-008 owns holiday work

-- THEN
CASE policy.value('weekly_off_work_treatment')
  WHEN 'comp_off'   THEN CREATE_COMP_OFF(units = BANDS(attendance.worked_minutes, [...]),
                                         expiry_ref = 'COMPOFF-EXPIRY-90D',
                                         reason = CONCAT('Worked on weekly off ', day.date))
  WHEN 'overtime'   THEN CREATE_OT(minutes = attendance.worked_minutes,
                                   multiplier_ref = 'OT-MULT-WEEKLY-OFF', approval = 'auto')
  WHEN 'both'       THEN /* both actions, each fingerprinted */
  ELSE                   FLAG_FOR_REVIEW(reason = 'Worked on weekly off', severity = 'low')
END
```

**WT-08 · Compensation and grace — an occurrence-indexed graduated response**

```
decision_point : attendance.day.penalise
scope          : { ALL }        specificity : 0     on_conflict : all_match
```
```sql
-- WHEN
attendance.late_minutes > 0m

-- THEN
APPLY_DAY_PENALTY(
    fraction = BANDS(
        agg.occurrence_index(late_arrival, policy.value('late_free_window'),
                             { event.value > policy.value('late_grace_min') }),
        [ { from: 1, to: policy.value('late_free_count') + 1, result: 0.0d },
          { from: policy.value('late_free_count') + 1, to: NULL,
            result: policy.value('late_penalty_per_occurrence') } ]),
    reason = CONCAT('Late occurrence #',
                    agg.occurrence_index(late_arrival, policy.value('late_free_window'),
                                         { event.value > policy.value('late_grace_min') })))
```

Free lates are therefore counted in a **declared** window — `payroll_period`,
`calendar_month`, `rolling(30, days)`, `quarter` — not accidentally in the payroll month.
*SmartPRS 2 counts them only per payroll month, by accident of
`PayrollGenController.php:453, 475`, and the count resets at the period boundary
(BR-ATT-022, b.21).*

### XI.10.7 The brief's Section 127 acceptance test, restated

"3 late arrivals over 10 minutes = half day" must not require code. It does not: Part VII
RL-ATT-001 is the rule, `late_grace_min` and the occurrence threshold are policy values, the
scope is a Scope expression, and the window is declared. Changing it to "4 lates in any
rolling 45 days = one full day, but only for the Collections department, from 1 January" is
three field edits and a publish, with a diff and an impact preview, and it does not touch
March.

---

## XI.11 The Time Settlement Engine *(brief Section 24 — late logout and extra-work settlement)*

### XI.11.1 What it is, and why it is separate from the rules

The rule engine decides **entitlement and penalty** per condition. The settlement engine
decides **how a day's surpluses and deficits net against each other** before those decisions
are made — because "late in, late out, full hours worked" is not three independent facts, it
is one arithmetic question, and every customer answers it differently.

It is a pure function, evaluated once per employee-day, between pairing and the
`attendance.day.penalise` decision point.

### XI.11.2 Inputs

| Input | Type | Source |
|---|---|---|
| `late_arrival_minutes` | Minutes | `first_in − (scheduled_start + grace_in)`, ≥ 0, on instants |
| `early_arrival_minutes` | Minutes | `(scheduled_start − first_in)`, ≥ 0 |
| `late_exit_minutes` | Minutes | `last_out − scheduled_end`, ≥ 0 |
| `early_exit_minutes` | Minutes | `(scheduled_end − last_out) − grace_out`, ≥ 0 |
| `break_deficit_minutes` | Minutes | `break_taken − break_budget`, ≥ 0 (a surplus is `break_surplus_minutes`) |
| `worked_minutes` | Minutes | paired sessions, paid breaks included, unpaid breaks excluded |
| `scheduled_minutes` | Minutes | from the shift, DST-aware |
| `is_holiday`, `is_weekly_off` | Boolean | resolved per employee |
| `leave_fraction` | Days | approved leave on the day (full/half) |
| `authorisations` | List | pre-approved OT, on-duty, WFH, permission/short-leave slips |

### XI.11.3 Outputs

| Output | Type | Meaning |
|---|---|---|
| `regular_minutes` | Minutes | Worked time counted against the schedule |
| `shortfall_minutes` | Minutes | Deficit against `scheduled_minutes` after offsets |
| `offset_minutes` | Minutes | Deficit cancelled by surplus, per the variant, with its cap and source |
| `ot_minutes` | Minutes | Surplus qualifying as overtime after eligibility, threshold and rounding |
| `comp_off_units` | Days | Surplus converted to comp-off |
| `extra_pay_minutes` | Minutes | Surplus payable outside the OT construct (e.g. a flat extra-hours rate) |
| `penalty_days` | Days | Day-fraction penalties before caps |
| `settlement_trace` | Trace | Every step, with the variant and each cap that bound |

**R-XI-100** The settlement engine is pure and traced: given identical inputs and
configuration versions it returns identical outputs, and every output names the arithmetic
that produced it (Law 4).

**R-XI-101** Units never coerce silently. `Minutes`, `Hours` and `Days` are distinct SPX
types; a policy that measures a deficit in minutes and values it in days must say so
(spine S5.1). *SmartPRS 2 accumulates day-cuts as bare floats and converts to money later by
`factor = paidDays / denominator` chosen from a tenant-wide `lop_basis` (CALC-B-05).*

### XI.11.4 The seven settlement variants, as configuration

Each is one `cfg_settlement_policies` artefact version: same engine, different field values.
They are scoped and effective-dated like everything else, so a company can run variant B for
Operations and variant D for Engineering from 1 April.

> The variant letters follow the brief's enumeration of settlement policies. Because each
> variant is a *configuration* of one engine, adopting a different label or a different
> ordering is a naming change, not a design change.

| | Variant | `offset_mode` | `offset_sources` | `offset_cap` | `surplus_treatment` | `deficit_treatment` | Typical customer |
|---|---|---|---|---|---|---|---|
| **A** | **Strict — no offset** | `none` | — | — | `ignore` (or OT only if pre-approved) | penalty per the late/early rules | Regulated back-office; the SmartPRS 2 `tiered` mode's intent |
| **B** | **Same-day offset** | `same_day` | `late_exit`, `early_arrival` | `offset_daily_cap_min` (60m seeded) | remainder → `ignore` | remainder → penalty | BPO/collections floors; Part VII RL-ATT-004 |
| **C** | **Net hours only** | `net_hours` | all surplus within the day | — | `ignore` below `ot_threshold`, `overtime` above | `HALF_DAY`/`ABSENT` by worked-hours bands; lateness forgiven | Engineering, project teams — SmartPRS 2's `net_hours` mode, but scopeable |
| **D** | **Time bank / flexi settlement** | `bank` | all surplus | `bank_daily_cap`, `bank_balance_cap` | credit the time bank | debit the time bank; LOP only when the bank is exhausted | Flexible-hours staff; `flex_settlement_window` = week or period |
| **E** | **Comp-off conversion** | `same_day` then `bank` | `late_exit` beyond `comp_off_min_minutes` | daily cap | `comp_off` in configured units | penalty per rules | Support rotas working weekly offs and holidays |
| **F** | **Paid extra work, pre-approval only** | `none` | — | — | `overtime` **only** with an authorisation record; otherwise `ignore` and flag | penalty per rules | Manufacturing and any site with statutory OT discipline |
| **G** | **Period-netted settlement** | `period` | all surplus in the period | `period_offset_cap_min` | remainder → OT or comp-off by policy | deficits netted across the period; residual LOP at period close | Monthly-target roles, field staff |

Common fields on every variant: `offset_order` (which deficit is cancelled first — lateness,
early exit, break excess, short hours), `round_at` (`each_step | final`), `rounding_mode`,
`min_surplus_to_count`, `max_daily_offset`, `max_period_offset`, `authorisation_required`,
`applies_on_weekly_off`, `applies_on_holiday`, and `trace_verbosity`.

### XI.11.5 Worked settlement — variant B, one day

```
Employee E-4471 · 26-Aug-2026 · Shift GEN-0930 (09:30–18:30, scheduled 540m, grace_in 10m)
Punches (as corrected): IN 09:53:12 · OUT 13:02:40 · IN 13:41:05 · OUT 19:11:48   (all IST)

late_arrival_minutes = 09:53:12 − (09:30:00 + 10m) = 13m      (on instants, not clock minutes)
early_exit_minutes   = 0m
late_exit_minutes    = 19:11:48 − 18:30:00 = 41m
worked_minutes       = 189m + 330m = 519m
break_minutes        = 38m  (budget 45m → break_surplus 7m, no deficit)
scheduled_minutes    = 540m

Variant B, offset_sources = [late_exit, early_arrival], cap 60m, offset_order = [late, short_hours]
  offset available   = 41m  (late_exit) + 0m (early_arrival) = 41m, capped at 60m → 41m
  cancel lateness    = MIN(13m, 41m) = 13m      → late_arrival_minutes  0m,  offset_minutes 13m
  remaining surplus  = 28m
  shortfall          = 540 − 519 = 21m
  cancel shortfall   = MIN(21m, 28m) = 21m      → shortfall_minutes 0m,  offset_minutes 34m
  remaining surplus  = 7m  < ot_min_trigger_min (30m)  → ot_minutes 0m
Outputs: regular 540m · shortfall 0m · offset 34m · OT 0m · comp-off 0d · penalty 0d
Then:   RL-ATT-001 evaluates against effective late_minutes 0m → does not match.
```

Under variant A the same day yields `late_arrival 13m`, `shortfall 21m`, one late occurrence
counted, and whatever the late rules then decide. Under variant F it additionally raises
`FLAG_FOR_REVIEW('unapproved extra work')`. The difference is entirely configuration.

### XI.11.6 Interaction with payroll

**R-XI-102** The settlement engine produces minutes and day fractions. It never produces
money. Money is Part XIV's, computed from `period_settlements` plus the salary structure,
with its own rounding declarations (Law 7).

---

## XI.12 Absent detection *(brief Section 25)*

### XI.12.1 It is a status derivation, not a mail sweep

**R-XI-103** Absence is a **stored, traced status on the attendance day**, produced by the
`attendance.day.classify` decision point (Part VII RL-ATT-009), for every employee-day in an
employment's active range. The notification is a consequence of the status, not the feature.
*SmartPRS 2's `AbsenceService` is a notification sweep that stores nothing; the attendance
report emits no row at all for a zero-punch day, so absence is invisible on the very report a
manager would use to find it (`AttendanceReportController.php:119-123`; NEW-ATT-006).*

### XI.12.2 The configurable condition set

Every clause independently adjustable, each a policy value or a rule condition:

| Clause | Configuration |
|---|---|
| Employment is active on the day | derived from the status value set — **not a case-sensitive string comparison** (*NEW-ATT-012: `where('status','active')` in one place, case-insensitive in another*) |
| Day is a working day for **this employee** | from the materialised calendar (§XI.8.6) |
| Day is not a holiday **for this employee** | scoped holidays (*NEW-ATT-011*) |
| Day is not a weekly off | one evaluator (*§XI.1.8*) |
| No approved leave covers the day (or the half-day) | `leave.on_date(day.date)`; half-day leave plus a short attendance is a half-day, not an absence |
| No approved on-duty / WFH / permission record covers it | first-class records, not correction sub-types (*SmartPRS 2 makes on-duty and WFH correction sub-types which become ordinary punches on approval, `AttendanceCorrectionService.php:304-314`*) |
| Punch/worked-minute condition | `punch_count = 0`, or `worked_minutes < policy.value('absent_min_minutes')` — configurable (D-XI-03) |
| Not already covered by an approved regularisation overlay | `attendance.is_corrected` |
| Tenant preference: auto-absent or hold for review | `policy.value('absence_hold_for_review')` |
| Grace for new joiners / exits / transfers in flight | `day.date` within the employment range; a joiner's first day before induction is `NOT_JOINED`, not `ABSENT` |

**R-XI-104** Consecutive absence is a first-class condition: `agg.streak(absent_day, backward)`
drives escalation (manager on day 1, HR on day 3, an abscondment workflow at
`policy.value('absconding_days')`) — none of which exists in SmartPRS 2.

### XI.12.3 Evaluation timing

**R-XI-105** Absence is evaluated (a) at **day close**, defined as
`scheduled_end + day_close_lag_minutes` per shift, so a night shift's absence is not decided at
11:00 the next morning while the shift is still running; (b) again at the tenant's configured
notification hour, in the **tenant's** timezone; and (c) on every recalculation. The status is
provisional until day close and is marked as such.

**R-XI-106** Nothing about absence detection depends on a tenant-discovery query that can
return an empty set for a self-hosted install. *`AbsenceService.php:96-97` drops NULL tenants
with `->filter()`, so the feature silently does nothing for every single-tenant customer
(NEW-ATT-010, DEFECT-B-16).*

### XI.12.4 Notification audience and template

| Element | Configuration |
|---|---|
| Audience | An `audience_ref`: employee, reporting manager, dotted-line manager, HR business partner, department head, a named group, or an SPX-selected role — per rule, per severity |
| Channels | Email, in-app, SMS, WhatsApp, push — resolved by the notification engine, per tenant, with fallbacks |
| Template | A versioned template artefact with typed variables: employee, date, shift, scheduled times, last punch, streak length, and a **deep link to raise a regularisation** |
| Timing | Tenant-local hour; immediate, digest, or both |
| Dedupe | `dedupe_key = 'absent:' + employee.code + ':' + day.date` — once per employee-day, idempotent across re-runs |
| Escalation | By streak length and by day-of-week, with an SLA |
| Suppression | Employee on notice period, on long leave, or with an open regularisation — configurable |

**R-XI-107** Notifications are queued and delivered by the notification engine with a delivery
log and retry. No attendance path sends mail inline. *(DEFECT-B-15, DEF-C-03.)*

### XI.12.5 The regularisation link

**R-XI-108** Every absence notification carries a deep link that opens a pre-filled
regularisation request for that exact employee-day, and the resulting request, when approved,
triggers recalculation of that day (§XI.13). This closes the loop SmartPRS 2 opens: it sends
the notice with a deep link, but an approval landing on a locked period does nothing at all
(§XI.1.10).

---

## XI.13 Attendance regularisation *(brief Section 26)*

### XI.13.1 Request types

| Type | What it asserts | Produces |
|---|---|---|
| `missed_in` | The IN punch was not captured | Overlay: insert IN |
| `missed_out` | The OUT punch was not captured | Overlay: insert OUT (may be on the following day) |
| `missed_both` | Neither punch captured | Overlay: insert IN and OUT |
| `wrong_time` | A punch instant is wrong | Overlay: amend time |
| `wrong_direction` | A punch's direction is wrong | Overlay: amend direction |
| `spurious_punch` | A punch is not the employee's | Overlay: void (quarantine), never delete |
| `on_duty` | Working away from a reader (client site, court, field) | Day fact: `ON_DUTY`, with location and purpose |
| `work_from_home` | Working remotely | Day fact: `WORK_FROM_HOME` |
| `permission` / `short_leave` | Sanctioned partial absence | Day fact reducing the deficit by the sanctioned minutes |
| `comp_off_availed` | Taking a comp-off | Ledger debit + day fact |
| `shift_change` | The employee worked a different shift | Day fact: shift override for that day |
| `weekly_off_swap` | Off taken on another day | Two day facts, validated as a pair |

**R-XI-109** Request types are a **value set with typed field requirements**, not a PHP
constant. *SmartPRS 2's seven sub-types are a constant array
(`AttendanceCorrectionService.php:35-43`), and on-duty and WFH lose their meaning on approval
because they become ordinary `correction` punches (b.18).*

### XI.13.2 The request record

`attendance_regularisations`: employment, `work_on`, type, requested values, **original
values snapshotted at request time**, reason code, free-text reason, attachments, requester,
approver decisions, approved values (which may differ from requested), status, workflow
instance, resulting overlay ids, resulting recalculation run id, payroll disposition.

**R-XI-110** The original / requested / approved time triple is preserved. *This is the
strongest audit artefact in SmartPRS 2's attendance area (`app_in`/`app_out` defaulting to the
requested times, BR-ATT-032) and it must survive.*

### XI.13.3 Workflow

**R-XI-111** Regularisation approval uses the **workflow engine** (spine S8) — no
module-specific approval code. It therefore gets, as configuration: multi-level approval,
dynamic manager at level N, conditional branches (auto-approve below N minutes, escalate above
N occurrences), parallel and quorum nodes, SLA with reminders, escalation, delegation and
backup approvers, required comment and required attachment, and a self-approval prohibition.
*SmartPRS 2 has single-level `ApprovalService::resolveApprover` with no escalation, no SLA and
no delegation (b.37), a hard-coded "one open request per employee-day" (`:134-139`), a 300-row
list cap with no pagination (`:349`), and a review permission that is broader in the list than
in the decision, so a user with role `hr` sees everyone's requests and gets 403 on approve
(DEFECT-B-17).*

**R-XI-112** Configurable limits: maximum open requests per employee-day, maximum requests per
employee per period, a backdating window, a forward-dating prohibition, per-type
auto-approval thresholds, and per-type document requirements. All policy values.

**R-XI-113** A decided step is immutable; re-deciding is impossible (spine S8). Every
transition writes an audit row with actor, timestamp, IP, decision, comment and an entity
snapshot hash.

### XI.13.4 Approval, overlay and recalculation

On approval, in one transaction:

1. Write the `punch_corrections` overlay row(s) and/or the day-fact record(s), with validity
   and the approval reference. **No layer-2 row is edited or deleted.**
2. Enqueue `recalculate(employment, [work_on, work_on])` (extended to the following day when
   the correction is an overnight OUT).
3. Recompute layer 3 at generation `n+1`, emit `attendance.day.superseded` with the before/after
   summary.
4. If the day's period settlement is `open`, refresh it. If it is `frozen`, go to §XI.13.5.
5. Notify the employee with the **actual** outcome, including the recalculated status and the
   payroll disposition.

**R-XI-114** The employee-facing message states what happened, not what might happen.
*SmartPRS 2 tells the employee the change "will be carried into the next adjustment" when no
code creates any adjustment (§XI.1.10, DEFECT-B-30).*

### XI.13.5 When the correction lands on a locked payroll period

**R-XI-115** The period-lock test is evaluated against the **attendance period actually used
by the run** — honouring the cut-off day — is database-portable, and **fails closed**: if
lock status cannot be determined, the day is treated as locked. *`PayrollLock::isPeriodFinalised():64`
uses MySQL-only `DATE_FORMAT(pay_date,'%Y-%m')`; on any other driver it throws and the catch at
`:72-74` returns `false`, i.e. it applies the correction to a locked period, and it matches the
pay date's month rather than the attendance period, so under a 21st–20th cut-off a correction
for the 25th is tested against the wrong run (DEFECT-B-31).*

**R-XI-116** A correction affecting a locked period creates an **arrears item**, not a flag:

```
attendance_arrears_items
  employment_id, source_type ('regularisation' | 'recalculation' | 're_attribution'),
  source_id, original_period_id  (the locked period),
  target_period_id               (the period that will settle it — resolvable and re-targetable),
  delta_payable_days, delta_lop_days, delta_ot_minutes, delta_compoff_units,
  basis_json                     (the before/after settlement figures and their trace ids),
  owner_user_id                  (a named accountable person, defaulted from a rule),
  status_code                    (open | scheduled | settled | waived | rejected),
  approval_workflow_instance_id, waiver_reason, settled_run_id, settled_at
```

**R-XI-117** An arrears item is visible in a queue with an age, an owner and a value, is
included in the payroll run's preview for its target period as a first-class input, and cannot
be closed silently — closure is `settled` (by a run), `waived` (with a reason and an approval)
or `rejected` (with a reason). A run that ignores an open arrears item for its period fails
preview.

**R-XI-118** Alternative disposition — reopening the locked period — is available with the
`payroll.period.reopen` permission through the audited reopen workflow, which supersedes the
settlement rather than editing it (spine S10 layer 4). **PRODUCT OWNER DECISION REQUIRED —
D-XI-02** on which disposition is the default, and on the enumeration and disposition of the
rows that may already exist in this state in production (OQ-B-02).

### XI.13.6 Historic re-attribution

**R-XI-119** Re-attributing historic punches — because a device identity was mapped late, or
mapped wrongly — is an explicit operation with a scope, a date range, a reason, a **preview of
every affected employee-day and period**, an approval when it touches a locked period, and a
full audit record. It is never a side effect of saving a mapping. *SmartPRS 2 performs an
unbounded `UPDATE` of `emp_code`, `emp_name`, `tenant_id` and `company_id` over historic punch
rows as a side effect of the mapping screen, reaching NULL-tenant rows belonging to other
tenants, with a flash message as its only evidence (`BiometricConfigController.php:639-655`;
NEW-C-11, SEC-C-12). **PRODUCT OWNER DECISION REQUIRED — D-XI-07.***

---

## XI.14 Explainability *(spine S15)*

**R-XI-120** No attendance number is displayed anywhere — screen, PDF, export, API, mobile,
payslip input — without an accompanying "How was this calculated?" affordance resolving to
`Explain(attendance_day, field)`. A derived value without a trace is a programming error that
throws (Law 4).

### XI.14.1 "Why was this employee half-day?" — the full trace

```
EXPLAIN  attendance_day · employment APX/HYD/00417 (Suresh Kumar) · 12-Aug-2026
         generation 3 (current) · derived 13-Aug-2026 02:04 IST by recalc-run #2291
         reason: regularisation REG-2026-04417 approved

ATTENDANCE DAY
  Attribution rule   shift_start_owns          (shift GEN-0930 v4)
  Work date          12-Aug-2026
  Timezone           Asia/Kolkata (device DEV-HYD-02, declared)

SHIFT
  Resolved           GEN-0930 "General 09:30–18:30" v4, effective 01-Apr-2026 →
  Source             roster  (roster HYD-AUG-2026 v2, published 28-Jul-2026)
  Candidates         roster ✔ · shift group (SG-OPS) not reached · employment default not reached
  Scheduled          2026-08-12 09:30:00 IST → 18:30:00 IST   (540 scheduled minutes)
  Grace in           15m   ← rule HYD-GRACE-002 v3 (branch HYD, specificity 180)
                            outranked: TENANT-GRACE-001 v7 (specificity 0, 10m)

EVIDENCE
  Punches (canonical, 4)                          overlays applied: 1
   09:53:12 IN   device DEV-HYD-02 · finger · adapter adms.iclock v3 · event #88,412,003
   13:02:40 OUT  device DEV-HYD-02 · finger ·                          event #88,412,119
   13:41:05 IN   device DEV-HYD-02 · finger ·                          event #88,412,204
   19:11:48 OUT  ** inserted by overlay ** REG-2026-04417, approved 13-Aug-2026 by A. Rao
                 original: no OUT captured (device offline 18:57–19:40, health incident #771)
  Quarantined     none          Duplicates suppressed  1  (identical instant, source connector)

PAIRING              strategy direction_aware
  Session 1          09:53:12 → 13:02:40   = 189m
  Session 2          13:41:05 → 19:11:48   = 330m
  Break              13:02:40 → 13:41:05   =  38m   (budget 45m — within budget)
  Worked                                     519m

SETTLEMENT           policy SETTLE-OPS-B v2 (variant B, same-day offset, cap 60m)
  late_arrival       09:53:12 − (09:30:00 + 15m) = 8m 12s → 8m
  late_exit          19:11:48 − 18:30:00 = 41m
  shortfall          540 − 519 = 21m
  offset applied     lateness 8m, then shortfall 13m   (order: [late, short_hours])  = 21m
  residual surplus   20m   (< ot_min_trigger 30m → no OT)
  OUTPUTS            regular 540m · shortfall 0m · offset 21m · OT 0m · penalty 0d

RULES EVALUATED      decision points in order, 11 candidates, 4 matched
  attendance.calendar.weekly_off  RL-CAL-001 v3    not matched (12-Aug is not 2nd/4th Sat)
  attendance.grace                HYD-GRACE-002 v3 MATCHED  → SET_GRACE 15m
  attendance.day.classify         RL-ATT-006 v5    not matched (worked 519m ≥ present min 240m)
                                  WT-03 v2         MATCHED  → SET_STATUS HALF_DAY
                                     WHEN worked_minutes (519m) < shift.full_day_minutes (540m)  TRUE
                                     CASE worked 519m ≥ half_day_minutes (240m) → 'HALF_DAY'
                                  RL-ATT-009 v4    not matched (punch_count 4 > 0)
  attendance.day.penalise         RL-ATT-001 v6    not matched
                                     effective late_minutes after offset = 0m, not > 10m
                                     agg.count(late_arrival, payroll_period 2026-08) = 2
                                  RL-ATT-011 v2    not matched (break 38m ≤ budget 45m)
  attendance.day.earn             WT-04 v1         not matched (late_exit consumed by offset)

OUTCOME
  Status             HALF_DAY                     (was PRESENT at generation 2)
  Payable fraction   0.5 day    LOP 0.5 day    Leave 0.0 day
  Day exceptions     none open  (1 resolved: MISSING_PUNCH, closed by REG-2026-04417)

WHY IT CHANGED FROM GENERATION 2
  gen 2 (12-Aug 23:10) : status ABSENT_MISSING_OUT · worked 189m · payable 0.0
  gen 3 (13-Aug 02:04) : overlay inserted OUT 19:11:48 → worked 519m → HALF_DAY · payable 0.5
  delta payable +0.5 day · period 2026-08 status OPEN → settlement refreshed, no arrears item

PAYROLL IMPACT       period 2026-08 (01-Aug → 31-Aug, cycle MONTHLY-CMP7 v3) — OPEN
                     payable days 21.5 (was 21.0) · LOP 1.5 (was 2.0)
CONFIGURATION VERSIONS USED
  shift GEN-0930 v4 · roster HYD-AUG-2026 v2 · calendar HYD-2026 v5 · settlement SETTLE-OPS-B v2
  rules: RL-CAL-001 v3, HYD-GRACE-002 v3, RL-ATT-001 v6, RL-ATT-006 v5, RL-ATT-009 v4,
         RL-ATT-011 v2, WT-03 v2, WT-04 v1
  policy values: late_grace_min 10m · present_min_minutes 240m · offset_daily_cap_min 60m
```

**R-XI-121** The trace names, for every step: the input values, the rule or formula and its
**version**, the condition that matched, the arithmetic performed, the rules that did **not**
match and why, and the configuration versions used. It is machine-readable first and rendered
second.

### XI.14.2 "Why is this day absent?" and "why is there no row?"

There is never "no row". An employee-day with no punches has a row with a status, a reason and
the same trace structure, naming the clause of the absence condition set that decided it and
the clauses that were checked and passed.

### XI.14.3 "Why is this punch attributed to this person?"

```
Punch          event #88,412,003 · device DEV-HYD-02 (serial A1B2C3) · device_user_id 0417
               received 12-Aug-2026 09:53:41 IST · adapter adms.iclock v3
Candidates     [ device_identity 0417 ] → [ code transform 'HYD' + 0417 = HYD0417 ] → [ raw 0417 ]
Strategy 1     device_identity_exact (device DEV-HYD-02, type device_pin, value 0417)  → MATCH
               mapping row #4471, in force 01-Jan-2026 → OPEN (row 2 of 2)
               prior mapping 0417 → APX/HYD/00301, in force 03-Mar-2025 → 31-Dec-2025
Verdict        accepted · employment APX/HYD/00417
Assertion      no name matching was performed at any point (R-XI-043)
```

### XI.14.4 "Why did this device stop working?"

The device health timeline: contacts, successes, failures by error class, punch volume, skew,
firmware changes, credential rotations, configuration versions and the incidents raised —
which is what makes "device offline 18:57–19:40" citable inside an attendance trace.

---

## XI.15 Configuration surface

Everything a tenant administrator can change without code. Tested against the brief's
Section 127 standard.

| # | What | Artefact / store | Scope | Approval |
|---|---|---|---|---|
| 1 | Shifts: every field in §XI.8.2 | `cfg_shifts` (artefact) | any Scope dimension | high-risk: yes |
| 2 | Break windows, paid/unpaid, auto-deduct | `cfg_shift_breaks` | with the shift | with the shift |
| 3 | Attendance-day attribution rule and day boundary | shift field + tenant default | shift, tenant | yes |
| 4 | Punch-pairing strategy, double-tap window, max session, missing-punch policy | shift fields, device field | shift, device | no |
| 5 | Grace in/out and grace mode; per-branch, per-shift, per-employee overrides with expiry | `attendance.grace` rules | any | yes (employee-scope) |
| 6 | Weekly-off patterns: fixed, alternate/nth actual weekday, rotational, roster-driven | `attendance.calendar.weekly_off` rules | any | yes |
| 7 | Holiday calendars, scoped, with optional/floating holidays and employee election | `cfg_holiday_calendars` | any | yes |
| 8 | Rotation patterns and phases | `cfg_roster_patterns` | any | no |
| 9 | Rosters: manual, bulk, import, copy, mass assign, range, recurring, pattern | `rosters` | org | publish |
| 10 | Roster validation severities (rest hours, consecutive days, turnaround) | validation config | tenant | yes |
| 11 | Late-coming rules: any number of bands, per-band threshold, penalty, grace, notification | rules | any | yes |
| 12 | Free-late count **and window** (`payroll_period`, `calendar_month`, `rolling(n)`, `quarter`) | policy values | any | no |
| 13 | Early-going, late-logout, short-hours, excess-break, missing-punch rules | rules | any | yes |
| 14 | Break budget, penalty block size, step value, rounding mode, per-day and per-period caps | shift + policy values | any | no |
| 15 | Presence gate: minimum minutes, requires-in-and-out, single-punch treatment | policy values | any | yes (D-XI-03) |
| 16 | Settlement variant A–G and every field in §XI.11.4 | `cfg_settlement_policies` | any | yes |
| 17 | OT: eligibility, threshold, rounding block and mode, multipliers by day type, approval mode, caps | rules + formula refs | any | yes |
| 18 | Comp-off: accrual thresholds, units, expiry, approval, consumption as a leave type | rules + leave type | any | yes |
| 19 | Night work: window, minimum overlap, allowance formula reference | rules + policy values | any | yes |
| 20 | Absence: every clause in §XI.12.2, evaluation lag, notification hour (tenant-local), audience, template, escalation by streak | rules + policy values + templates | any | no |
| 21 | Attendance status vocabulary (adding a status) | `value_sets` | tenant | yes |
| 22 | Regularisation: request types enabled, required fields and documents per type, limits, backdating window, auto-approval thresholds | value set + policy values | any | yes |
| 23 | Regularisation workflow: levels, approvers, SLA, escalation, delegation | workflow artefact | any | yes |
| 24 | Locked-period disposition: arrears (default) or reopen; arrears owner assignment rule | policy values | tenant | yes |
| 25 | Devices: every field in §XI.7.1, including schedule (arbitrary interval + active window), timezone, topology, double-tap, direction token map, identity strategy order, code transform | `devices` | device | no (credential change: yes) |
| 26 | Unknown-device policy: reject / quarantine / auto-register | tenant policy | tenant | yes |
| 27 | ADMS option block per device | device fields | device | no |
| 28 | Connector recipes: transport, auth, window, pagination, retry, rate limit, response, map, tokens, time policy | `cfg_connector_recipes` | connector | yes |
| 29 | Egress profiles (host/scheme allow-lists) | `cfg_egress_profiles` | tenant | Ametecs or tenant admin — **D-XI-12** |
| 30 | Vendor profiles (status-byte maps, tokens, formats) | `cfg_vendor_profiles` | platform-owned, tenant-extendable | yes |
| 31 | Staleness thresholds and device health alert audiences | device + policy | device | no |
| 32 | Geofence rules per employee/site: centre, radius, tolerance bands (**any number**, not three), out-of-fence verdict handling | `cfg_geofences` | any | no |
| 33 | Retention classes for punch evidence, quarantine, health snapshots, traces | `cfg_retention_classes` | tenant | yes (**D-XI-08**) |
| 34 | Report definitions: columns, filters, saved views, scheduling, formats | report artefacts (Part XVIII) | any | no |

**R-XI-122** Nothing in this table requires a deploy, a migration, a support ticket or a
database edit. *SmartPRS 2 requires code for at least fifty-eight distinct attendance
behaviours (B §5.2) and twenty-nine device behaviours (C §5.2), including adding a device
vendor at all.*

---

## XI.16 Data model

To the Section 123 standard. Tables specified in Part XXVIII §C5/§C13 are **restated here
with the columns this part adds**; where they are unchanged, the reference is given rather
than duplicated. Conventions per spine S16 and Part XXVIII §XXVIII.2: `snake_case`, plural
tables, `_id` FKs, `_at` instants, `_on` dates, `is_`/`has_` booleans, no database `ENUM`
(status vocabularies are reference tables), `DECIMAL(18,4)` money at rest, FKs on every
relationship with a declared `ON DELETE`, `tenant_id NOT NULL` in every unique index.

### XI.16.1 `punch_events` — layer 1

Specified in full at §XI.2.1. Additions to Part XXVIII §C5: `time_policy_code`,
`recipe_config_version_id`, and the extended `ingest_verdict_code` vocabulary
(`rejected_time`, `rejected_out_of_window`).
Tenant-owned · append-only (privilege-enforced) · no soft delete (nothing is deleted) ·
audit `event-only` · PII `identifying` · retention `R-ATT-3Y+` · partitioned monthly on
`received_on`.

### XI.16.2 `punch_normalised` — layer 2

Specified in full at §XI.2.2. Additions to Part XXVIII §C5: `identity_strategy_code`,
`direction_source_code`, `direction_token_raw` (on the event row), `device_user_id`,
`quarantine_reason_code`, and the extended `punch_direction` value set including
`break_in`, `break_out`, `ot_in`, `ot_out` (**D-XI-09**).

### XI.16.3 `attendance_days` — layer 3

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `id` | BIGINT UNSIGNED AI | N | — | PK with `work_on` |
| `tenant_id` | BIGINT UNSIGNED | N | — | FK `tenants` RESTRICT |
| `work_on` | DATE | N | — | the attendance day (§XI.8.4); partition key |
| `employment_id` | BIGINT UNSIGNED | N | — | FK `employments` RESTRICT |
| `generation` | INT UNSIGNED | N | 1 | monotonic |
| `is_current` | TINYINT(1) | N | 1 | |
| `is_current_marker` | TINYINT GENERATED | N | — | `1` when current, else NULL — carries the uniqueness |
| `recalc_run_id` | BIGINT UNSIGNED | Y | NULL | FK `attendance_recalc_runs` RESTRICT |
| `shift_config_version_id` | BIGINT UNSIGNED | Y | NULL | FK `config_versions` RESTRICT |
| `shift_source_code` | VARCHAR(40) | Y | NULL | `roster \| pattern \| shift_group \| employment_default \| org_default \| tenant_default` |
| `day_attribution_rule_code` | VARCHAR(40) | N | — | which rule produced `work_on` |
| `timezone` | VARCHAR(64) | N | — | the zone the schedule was materialised in |
| `scheduled_start_at_utc`, `scheduled_end_at_utc` | DATETIME(3) | Y | NULL | absolute instants, DST-aware |
| `scheduled_minutes` | INT | N | 0 | |
| `first_in_at_utc`, `last_out_at_utc` | DATETIME(3) | Y | NULL | |
| `punch_count`, `session_count` | SMALLINT UNSIGNED | N | 0 | |
| `worked_minutes`, `break_minutes`, `paid_break_minutes` | INT | N | 0 | |
| `late_minutes`, `early_exit_minutes`, `late_exit_minutes`, `early_arrival_minutes` | INT | N | 0 | computed from instants |
| `shortfall_minutes`, `offset_minutes`, `ot_minutes`, `extra_pay_minutes` | INT | N | 0 | settlement outputs |
| `grace_in_applied_minutes`, `grace_out_applied_minutes` | INT | N | 0 | what actually applied, after `SET_GRACE` |
| `settlement_policy_version_id` | BIGINT UNSIGNED | Y | NULL | FK `config_versions` RESTRICT |
| `status_code` | VARCHAR(40) | N | — | value set `attendance_status` |
| `status_source_code` | VARCHAR(40) | N | — | `rule \| override \| import \| system` |
| `payable_day_fraction`, `lop_day_fraction`, `leave_day_fraction`, `penalty_day_fraction` | DECIMAL(9,4) | N | 0 | `DayFraction` |
| `compoff_earned_units` | DECIMAL(9,4) | N | 0 | |
| `is_weekly_off`, `is_holiday`, `is_on_duty`, `is_wfh`, `is_imputed_out` | TINYINT(1) | N | 0 | |
| `holiday_calendar_day_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `leave_request_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `exception_flags_json` | JSON | Y | NULL | typed day exceptions (§XI.8.5) |
| `applied_rule_versions_json` | JSON | N | — | every rule, formula, policy, shift, calendar version used |
| `trace_id` | BIGINT UNSIGNED | Y | NULL | FK `calculation_traces` RESTRICT |
| `override_by_user_id`, `override_reason` | | Y | NULL | a manual status override, audited `full` |
| `derived_at` | DATETIME(3) | N | — | |
| `superseded_at`, `superseded_by_id` | | Y | NULL | FK self RESTRICT |

**Keys.** PK `(id, work_on)` · UQ `(tenant_id, employment_id, work_on, generation)`,
`(tenant_id, employment_id, work_on, is_current_marker)` ·
IX `(tenant_id, work_on, status_code)`, `(tenant_id, employment_id, work_on, is_current)`,
`(tenant_id, status_code, work_on)`, `(tenant_id, recalc_run_id)`.
**Policy.** Tenant-owned · history: supersession by generation, never update-in-place ·
delete: never · audit `transitional` (`full` for a manual override) · PII `identifying` ·
retention `R-ATT-3Y+` · partitioned yearly on `work_on`.

### XI.16.4 `attendance_day_effects` — A3, the applied-action lines

One row per applied action, so a day's penalty of 0.75 is three named contributions, not an
opaque number.

`attendance_day_id` (FK CASCADE within the aggregate), `sequence`, `action_code`,
`rule_code`, `rule_version_id` (FK RESTRICT), `fingerprint` CHAR(64),
`parameters_json`, `effect_class` (`derived|ledger|terminal`), `value_minutes` INT,
`value_days` DECIMAL(9,4), `reason_text`, `cap_applied` TINYINT(1), `ledger_entry_id`
(FK RESTRICT, nullable).
**Keys.** UQ `(tenant_id, attendance_day_id, fingerprint)` — Part VII R-VII-032 idempotency ·
IX `(tenant_id, rule_code)`, `(tenant_id, action_code)`.
**Policy.** Tenant-owned · immutable with its parent generation · audit `event-only` ·
PII `none` · retention with the day.

### XI.16.5 `punch_corrections` — the overlay

Specified at §XI.2.3; additions to Part XXVIII: `regularisation_id`, `applied_generation`.

### XI.16.6 `period_settlements` — layer 4

As Part XXVIII §C5, with the columns this part adds: `offset_minutes_total`,
`extra_pay_minutes_total`, `penalty_days_total`, `exception_day_count`,
`open_arrears_item_count`, `is_stale` (set when any contributing day is superseded),
`settlement_policy_version_id`.
**Keys.** UQ `(tenant_id, uuid)`, `(tenant_id, payroll_period_id, employment_id)` ·
IX `(tenant_id, payroll_period_id, lifecycle_state_code)`, `(tenant_id, is_stale)`.
**Policy.** Tenant-owned · superseded on reopen, never updated in place · delete never ·
audit `full` · PII `none` · retention `R-PAY-8Y`.

### XI.16.7 `attendance_regularisations` — A1 · `attendance_regularisation_days` — A3

| Column | Type | N | Default | Notes |
|---|---|---|---|---|
| `id`, `uuid`, `tenant_id` | | N | — | UUIDv7 public id |
| `employment_id` | BIGINT UNSIGNED | N | — | FK RESTRICT |
| `request_type_code` | VARCHAR(40) | N | — | value set `regularisation_type` |
| `work_on_from`, `work_on_to` | DATE | N | — | a request may span days (a shift swap, a WFH block) |
| `original_snapshot_json` | JSON | N | — | the day(s) as they stood at request time |
| `requested_values_json` | JSON | N | — | |
| `approved_values_json` | JSON | Y | NULL | may differ from requested (BR-ATT-032) |
| `reason_code`, `reason_text` | VARCHAR(40), VARCHAR(1000) | N/Y | — | reason code from a value set |
| `attachment_count` | SMALLINT | N | 0 | documents in the document store |
| `requested_by_user_id`, `requested_at` | | N | — | |
| `status_code` | VARCHAR(40) | N | `submitted` | `draft\|submitted\|in_approval\|approved\|applied\|rejected\|cancelled\|expired` |
| `workflow_instance_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |
| `applied_at`, `recalc_run_id` | | Y | NULL | FK RESTRICT |
| `payroll_disposition_code` | VARCHAR(40) | Y | NULL | `open_period \| arrears_raised \| period_reopened \| no_impact` |
| `arrears_item_id` | BIGINT UNSIGNED | Y | NULL | FK RESTRICT |

**Keys.** UQ `(tenant_id, uuid)` · IX `(tenant_id, employment_id, work_on_from)`,
`(tenant_id, status_code, requested_at)`, `(tenant_id, workflow_instance_id)`.
Concurrency: a partial unique index on `(tenant_id, employment_id, work_on_from, open_marker)`
enforces the configured maximum of open requests per employee-day **in the database**, not in
PHP.
**Policy.** Tenant-owned · history: status transitions in the audit ledger · delete: never ·
audit `full` · PII `identifying` (reason text may carry health or personal information —
masked by role) · retention `R-ATT-3Y+`.

`attendance_regularisation_days`: `regularisation_id`, `work_on`, `requested_in_at_utc`,
`requested_out_at_utc`, `approved_in_at_utc`, `approved_out_at_utc`, `day_fact_code`,
`resulting_punch_correction_ids_json`. UQ `(tenant_id, regularisation_id, work_on)`.

### XI.16.8 `attendance_arrears_items` — A1

Columns as §XI.13.5, plus `uuid`, `tenant_id`, `raised_at`, `raised_by_user_id`,
`value_estimate_minor` DECIMAL(18,4) (an indicative money value, computed by Part XIV, never
authoritative), `notes`.
**Keys.** UQ `(tenant_id, uuid)`, `(tenant_id, source_type, source_id)` ·
IX `(tenant_id, status_code, target_period_id)`, `(tenant_id, owner_user_id, status_code)`,
`(tenant_id, original_period_id)`.
**Policy.** Tenant-owned · audit `full` · PII `none` · retention `R-PAY-8Y`.
*This table exists because SmartPRS 2 creates the obligation, tells the employee about it, and
then abandons it (DEFECT-B-30).*

### XI.16.9 `attendance_recalc_runs` — A1 · `attendance_recalc_diffs` — A3

`attendance_recalc_runs` as Part XXVIII §C5, plus `mode_code` (`preview|apply`),
`reason_text` (required), `trigger_code`
(`manual|regularisation|roster_change|config_publish|late_punch|device_correction|re_attribution`),
`blocked_by_lock_count`.
`attendance_recalc_diffs`: `recalc_run_id`, `employment_id`, `work_on`, `field_code`,
`old_value`, `new_value`, `impact_class` (`status|minutes|day_fraction|ledger`),
`is_blocked_by_lock`, `arrears_item_id` (FK RESTRICT, nullable).
**Keys.** diffs UQ `(tenant_id, recalc_run_id, employment_id, work_on, field_code)` ·
IX `(tenant_id, recalc_run_id, impact_class)`.
**Policy.** Tenant-owned · append-only · audit `event-only` · PII `none` ·
retention `R-LOG-13M`.

### XI.16.10 `devices` — A1

Part XXVIII §C5 plus the columns this part requires: `adapter_code`, `adapter_version_pin`,
`vendor_profile_code`, `make_code`, `model_code`, `firmware_reported`, `serial_reported`,
`timezone`, `time_policy_code`, `topology_code`, `in_reader_id`, `out_reader_id`,
`double_tap_seconds`, `identity_strategy_json`, `code_transform_json`,
`direction_token_map_json`, `schedule_json` (cron or interval + active window),
`high_water_mark_at`, `unknown_device_policy_code` (tenant default, overridable),
`enrolment_secret_hash`, `enrolment_secret_rotated_at`, `credential_ref`,
`adms_options_json`, `staleness_threshold_minutes`, `health_state_code`,
`lifecycle_state_code`, `claimed_by_user_id`, `claimed_at`.
**Keys.** UQ `(tenant_id, uuid)`, `(tenant_id, device_serial)` ·
IX `(tenant_id, health_state_code, last_seen_at)`, `(tenant_id, lifecycle_state_code)`.
**Policy.** Tenant-owned · audit `full` · PII `restricted` (credentials, enrolment secret) ·
retention `R-PERM`.

### XI.16.11 `device_health_snapshots` — A4

`device_id` (FK RESTRICT), `observed_at`, `health_state_code`, `last_contact_at`,
`last_successful_fetch_at`, `last_punch_at`, `consecutive_failure_count`,
`last_error_class`, `last_error_detail`, `punches_24h`, `punches_7d`,
`clock_skew_seconds`, `latency_ms`, `firmware_reported`.
**Keys.** UQ `(tenant_id, device_id, observed_at)` ·
IX `(tenant_id, health_state_code, observed_at)`.
**Policy.** Tenant-owned · append-only · audit `event-only` · PII `none` ·
retention `R-LOG-13M` · partitioned monthly.

### XI.16.12 `device_commands` — A4

`device_id` (FK RESTRICT), `command_code`, `parameters_json`, `requested_by_user_id`,
`status_code` (`queued|delivered|acknowledged|failed|expired|cancelled`), `queued_at`,
`delivered_at`, `acknowledged_at`, `expires_at`, `device_response_text`, `attempt_count`.
**Keys.** UQ `(tenant_id, uuid)` · IX `(tenant_id, device_id, status_code, queued_at)`.
**Policy.** Tenant-owned · append-only after terminal state · audit `full` (a command changes
a physical device) · PII `none` · retention `R-LOG-13M`.

### XI.16.13 `device_identity_map` — A5

Part XXVIII §C5, with `identity_type_code` added (`device_pin`, `card_number`, `face_id`,
`employee_code`, `external_id`) so the model is not implicitly PIN-only, and
`enrolled_via_code` (`console|import|api|auto_suggest_confirmed`).
**Keys.** UQ `(tenant_id, device_id, identity_type_code, identity_value, open_marker)`,
`(tenant_id, device_id, identity_type_code, identity_value, effective_from)` ·
IX `(tenant_id, employment_id)`.
**Policy.** Tenant-owned · history A5 · delete never · audit `full` · PII `identifying` ·
retention `R-ATT-3Y+`.

### XI.16.14 `cfg_shifts` and children — A2/A5 payload of the shift artefact

`cfg_shifts` carries the fields of §XI.8.2 as typed columns (not JSON), keyed by
`config_version_id` (FK RESTRICT) so a shift's *content* is versioned by the standard artefact
mechanism. Children: `cfg_shift_breaks` (`seq`, `from_time`, `to_time`, `is_paid`,
`is_mandatory`), `cfg_shift_segments` (split shifts: `seq`, `start_time`, `end_time`,
`min_gap_minutes`, `max_gap_minutes`, `gap_is_paid`).
**Keys.** UQ `(tenant_id, config_version_id)`, `(tenant_id, shift_code, config_version_id)` ·
IX `(tenant_id, shift_code)`.
**Policy.** Tenant-owned · history: artefact versions · delete: never (archive) ·
audit `full` · PII `none` · retention `R-PERM`.
*A unique key on `(tenant_id, shift_code)` per effective range is what SmartPRS 2's `shifts`
table lacks, which is why duplicate shift names silently collapse to whichever row loads last
(`ShiftResolver.php:58`).*

### XI.16.15 `cfg_roster_patterns` — A2

`pattern_code`, `cycle_days`, `slots_json` (ordered shift codes and OFF markers),
`anchor_on`, `phase_offsets_json`, `weekly_off_generation_code`, `holiday_behaviour_code`,
`config_version_id`.
**Keys.** UQ `(tenant_id, pattern_code, config_version_id)`.
**Policy.** Tenant-owned · artefact versions · audit `full` · PII `none` · retention `R-PERM`.

### XI.16.16 `rosters` / `roster_days`

Part XXVIII §C5, with `roster_days` gaining `pattern_id` (FK RESTRICT, nullable),
`assignment_method_code` (`manual|bulk|import|copy|mass|range|recurring|pattern`) and
`is_weekly_off_source_code`, so a cell can always explain where it came from.

### XI.16.17 `cfg_settlement_policies` — A2

`policy_code`, `variant_code` (`A`…`G`), `offset_mode_code`, `offset_sources_json`,
`offset_order_json`, `offset_daily_cap_minutes`, `offset_period_cap_minutes`,
`surplus_treatment_code`, `deficit_treatment_code`, `min_surplus_minutes`,
`round_at_code`, `rounding_mode_code`, `authorisation_required`, `applies_on_weekly_off`,
`applies_on_holiday`, `bank_daily_cap_minutes`, `bank_balance_cap_minutes`,
`config_version_id`.
**Keys.** UQ `(tenant_id, policy_code, config_version_id)`.
**Policy.** Tenant-owned · artefact versions · audit `full` · PII `none` · retention `R-PERM`.

### XI.16.18 `cfg_connector_recipes`, `cfg_vendor_profiles`, `cfg_egress_profiles` — A2

Payload tables of the corresponding artefacts, each keyed by `config_version_id`, each
schema-validated at publish (R-XI-030/031), each `audit full`, PII `none` except
`cfg_connector_recipes.credential_ref` which is a handle, never a secret.

### XI.16.19 Integration tables reused, not redefined

`connectors`, `inbound_batches`, `inbound_batch_lines`, `idempotency_keys`,
`webhook_endpoints`, `webhook_deliveries`, `domain_event_outbox`, `external_identity_map` —
Part XXVIII §C13, unchanged. `inbound_batch_lines.verdict_code` is the per-punch verdict of
R-XI-027.

### XI.16.20 Tables that do not exist in SmartPRS 3

| SmartPRS 2 table | Disposition |
|---|---|
| `attendance` (legacy) + `Attendance` model + `AttendanceController` | **Dropped.** Dead, unrouted, and carrying three mutually contradictory status vocabularies (B §2.4) |
| `attendance_logs` | Replaced by layers 1–3 |
| `attendance_pending` | Split: device quarantine is a `punch_events` verdict; bulk staging is `inbound_batches`/`inbound_batch_lines`. *The two features may not share a table name (CONF-C-01)* |
| `biometric_unmapped` | Derived by grouping the quarantine (R-XI-071) |
| `attendance_ratings` | Merged into a general per-day annotation with `tenant_id` in its unique key. *Its unique index is `(emp_code, log_date)` with no tenant, against a table that has a `tenant_id` column and tenant-filtered reads — a silent cross-tenant overwrite channel (FINDING-B-SEC-02)* |
| `late_policy` | Replaced by rules and policy values |
| `late_notifications` | Replaced by the notification engine's dedupe ledger |
| `roster` (runtime-created, name-keyed) | Replaced by `rosters`/`roster_days` |
| `devices` (LAN, no tenant) | Folded into the device registry; **D-XI-10** decides whether the LAN adapter ships |
| `mobile_devices` (runtime-created, unauthenticated) | Replaced by the platform device-trust model; the token is verified by middleware or the feature is not shipped (SEC-C-08) |

---

## XI.17 API surface

`/api/v3`, OpenAPI-generated, cursor-paginated, idempotency-keyed, every path parameter a
UUID (spine S17). Every endpoint has an explicit policy binding; a route without one fails
the CI sweep (Law 5).

| Method | Path | Purpose | Permission |
|---|---|---|---|
| POST | `/api/v3/attendance/punches` | Canonical punch batch (SBB and any authenticated collector). Per-punch verdicts | `attendance.punch.ingest` (API client scope `ingest`) |
| POST | `/api/v3/attendance/punches/{uuid}/void` | Quarantine a punch with a reason (never delete) | `attendance.punch.quarantine` |
| GET | `/api/v3/attendance/punches` | Evidence query by employment, device, day, verdict | `attendance.punch.view` + data scope |
| POST | `/api/v3/ingest/webhooks/{endpoint_uuid}` | Signed inbound webhook (timestamp + nonce + HMAC) | endpoint credential |
| POST | `/iclock/*` (protocol paths, versioned separately) | ADMS firmware compatibility surface | per-device enrolment secret / claimed serial (D-XI-04) |
| GET | `/api/v3/attendance/days` | Muster roll: one row per employee-day incl. absent, weekly-off, holiday | `attendance.day.view` + data scope |
| GET | `/api/v3/attendance/days/{uuid}` | One day with punches, sessions, effects and exceptions | `attendance.day.view` |
| GET | `/api/v3/attendance/days/{uuid}/explain` | The §XI.14.1 trace | viewing permission of the day |
| GET | `/api/v3/attendance/days/{uuid}/generations` | Every generation with its diff | `attendance.day.view.history` |
| POST | `/api/v3/attendance/days/{uuid}/override` | Manual status override with a reason (audited `full`) | `attendance.day.override` |
| POST | `/api/v3/attendance/recalculations` | `recalculate(scope, range, mode, reason)` | `attendance.recalculate` (+ `.locked`) |
| GET | `/api/v3/attendance/recalculations/{uuid}` | Status, counts, diff | `attendance.recalculate` |
| GET | `/api/v3/attendance/recalculations/{uuid}/diff` | The preview diff, paginated | `attendance.recalculate` |
| GET | `/api/v3/attendance/settlements` | Layer-4 rows by period | `attendance.settlement.view` |
| POST | `/api/v3/attendance/settlements/{uuid}/freeze` \| `/reopen` | Freeze or reopen (workflow) | `attendance.settlement.freeze` \| `.reopen` |
| GET/POST | `/api/v3/attendance/regularisations` | List / raise | `attendance.regularisation.raise` (self only, subject from session) |
| POST | `/api/v3/attendance/regularisations/{uuid}/decide` | Approve / reject / return, with modified values | `attendance.regularisation.review` + data scope |
| POST | `/api/v3/attendance/regularisations/{uuid}/cancel` | Owner cancels a pending request | owner only |
| GET/POST | `/api/v3/attendance/arrears` | Arrears queue; assign owner, waive (workflow), re-target | `attendance.arrears.manage` |
| GET/POST | `/api/v3/attendance/rosters` | List / create draft | `roster.view` / `roster.edit` |
| POST | `/api/v3/attendance/rosters/{uuid}/generate` | From a pattern, copy, mass assign, range or recurring spec | `roster.edit` |
| POST | `/api/v3/attendance/rosters/{uuid}/import` | Excel/CSV import → validation report | `roster.import` |
| GET | `/api/v3/attendance/rosters/template` | Generated template with the live shift dropdown | `roster.import` |
| POST | `/api/v3/attendance/rosters/{uuid}/publish` | Validate and publish | `roster.publish` |
| GET | `/api/v3/attendance/shifts` \| `/{uuid}` \| `/resolve` | Shift master; `resolve?employment=&on=` returns the shift **and why** | `shift.view` |
| GET | `/api/v3/devices` \| `/{uuid}` | Device registry | `device.view` |
| POST | `/api/v3/devices` \| PATCH `/{uuid}` | Register / update (PATCH semantics) | `device.manage` |
| POST | `/api/v3/devices/{uuid}/test` \| `/sync` \| `/backfill` | Test (read-only) / sync now / bounded backfill | `device.manage` |
| POST | `/api/v3/devices/{uuid}/claim` \| `/suspend` \| `/retire` | Lifecycle | `device.manage` |
| POST | `/api/v3/devices/{uuid}/credentials/rotate` | Rotate with overlap | `device.credential.rotate` |
| GET | `/api/v3/devices/{uuid}/health` \| `/health/history` | Health record and timeline | `device.view` |
| GET/POST | `/api/v3/devices/{uuid}/commands` | Command queue | `device.command` (+ `device.template` for templates) |
| GET/POST | `/api/v3/devices/identities` | Mapping worklist; create a dated mapping | `device.identity.map` |
| POST | `/api/v3/devices/identities/{uuid}/close` | Close a mapping with an effective date | `device.identity.map` |
| POST | `/api/v3/attendance/re-attributions` | Preview / apply historic re-attribution | `attendance.reattribute` |
| GET/POST | `/api/v3/connectors` \| `/{uuid}/recipe-versions` | Recipe authoring, validation, publish | `connector.manage` |
| GET | `/api/v3/attendance/exceptions` | The exception queue (missing punch, orphan OUT, unscheduled punch, needs shift) | `attendance.exception.view` |

**R-XI-123** Self-service endpoints resolve the subject from the session. There is no employee
selector on a self-service transaction (spine S13). *`AttendanceReportController::logs():441-484`
takes `{code}` and `{date}` straight from the URL with no self-scope and no role guard — any
authenticated employee reads any colleague's full punch timeline plus the manager's private
rating and remarks (FINDING-B-SEC-01, HIGH).*

**R-XI-124** Every attendance endpoint is rate-limited and idempotency-keyed on unsafe
methods. *No authenticated attendance route in SmartPRS 2 carries a throttle
(FINDING-B-SEC-08), and the in-app punch takes `source` from the request body with no
whitelist and no dedup, letting a caller inflate their own present days or tag a punch
`correction` so the next write-back deletes it (FINDING-B-SEC-04).*

**R-XI-125** No endpoint returns an exception message to the client. Errors use the platform
envelope with a machine-readable code and a correlation id. *SmartPRS 2 returns raw
`$e->getMessage()` — including SQL fragments, table and column names — from at least five
attendance controllers, one of them with HTTP **200** (FINDING-B-SEC-07).*

---

## XI.18 Events

Published to the transactional outbox in the same transaction as the state change
(Part XXVIII §C13), registered in `domain_event_types` with a payload schema.

### Emitted

| Event | When | Notable payload |
|---|---|---|
| `attendance.punch.received` | A layer-1 row is written | adapter, device, verdict |
| `attendance.punch.accepted` | A layer-2 row is written | employment, instant, direction, identity strategy |
| `attendance.punch.quarantined` | Unknown device or unmapped identity, or a near-duplicate | reason, device, identity value |
| `attendance.punch.rejected` | Schema, time policy or window violation | reason code, line reference |
| `attendance.identity.mapped` | A device identity is mapped | mapping id, released punch count |
| `attendance.day.derived` | A day is derived at generation 1 | status, fractions, minutes |
| `attendance.day.superseded` | A recalculation writes generation n+1 | before/after summary, trigger, run id |
| `attendance.day.exception_raised` / `.resolved` | Missing punch, orphan OUT, unscheduled punch, needs shift | type, severity |
| `attendance.absent` | Absence classified | streak length, notification audience |
| `attendance.late_penalty` | A penalty action applies | rule code, fraction, occurrence index |
| `attendance.overtime_created` | `CREATE_OT` applied | minutes, multiplier ref, approval mode |
| `attendance.compoff_earned` | `CREATE_COMP_OFF` applied | units, expiry |
| `attendance.regularisation.submitted` / `.approved` / `.rejected` / `.applied` | Lifecycle | type, days affected |
| `attendance.arrear.raised` / `.settled` / `.waived` | Locked-period liability lifecycle | period, delta, owner |
| `attendance.recalculation.started` / `.completed` | Recalculation | scope, range, counts, blocked count |
| `attendance.settlement.frozen` / `.reopened` / `.stale` | Layer 4 | period, generation max |
| `roster.published` / `roster.superseded` | Roster lifecycle | period, employment count |
| `shift.version.published` | Shift artefact published | impacted employee count |
| `device.registered` / `.claimed` / `.suspended` / `.retired` | Device lifecycle | serial, org unit |
| `device.health.degraded` / `.stale` / `.recovered` | Health transitions | state, consecutive failures, last punch |
| `device.command.queued` / `.acknowledged` / `.failed` | Command channel | command code |
| `device.clock_skew_detected` | Skew beyond threshold | seconds, sample size |
| `connector.run.failed` | A pull window failed | error class, attempt, next attempt |

### Consumed

| Event | Source | Reaction |
|---|---|---|
| `employment.created` / `.transferred` / `.exited` | People | Materialise or close calendar days; close device identity mappings on exit |
| `leave.request.approved` / `.cancelled` | Absence | Recalculate the covered days |
| `holiday_calendar.published` | Organisation | Recalculate affected days (preview first) |
| `config.version.published` (shift, rule, settlement, calendar, roster pattern) | Configuration | Impact preview; recalculation where retro-effective |
| `payroll.period.locked` / `.reopened` | Payroll | Freeze / unfreeze layer 4; switch corrections to the arrears path |
| `compoff.availed` | Absence | Day fact on the availed day |

---

## XI.19 Migration implication

Per the verdicts in `B-attendance.md` §9 and `C-biometric-integrations.md` §9.

| From | To | Transformation | Risk |
|---|---|---|---|
| `attendance_logs` rows | `punch_events` (synthesised layer 1) + `punch_normalised` | One row each; `emp_code` resolved to `employment_id` **as at the punch date**; `tenant_id` backfilled and made NOT NULL; `captured_tz` set to the install's effective zone with a per-install declaration; `source` mapped to the new value set | The three creator shapes must be reconciled first (`SHOW CREATE TABLE` per install, OQ-B-04) |
| `attendance_logs` `emp_code LIKE 'USER-%'` | Quarantine, not migrated | Rows written when the in-app punch could not resolve an employee (`AttendanceReportController.php:279`); they count as present days for a code no employee holds (OQ-B-11) | Must be enumerated and dispositioned before cutover |
| `source='demo'` rows | Not migrated | Fabricated punches; pre-rev-51 demo rows are tagged `biometric` and are **indistinguishable from real punches** (`PurgeDemoAttendance.php:16-18`) | A contaminated install cannot be cleaned without deleting real attendance — declare per install |
| Derived attendance | Recomputed, not migrated | There is no stored derived attendance in SmartPRS 2 to migrate | Night-shift history needs the historic roster, which the name-keyed `roster` may not support — expect manual reconciliation |
| `roster` (name-keyed, no migration) | `rosters` / `roster_days` | Re-key display name → `employment_id`; duplicate names are a **manual** resolution, never a guess | Names that match two employments block the row and are reported |
| `shifts` | `cfg_shifts` artefact v1, effective from the earliest attendance date | Duplicate `(tenant, name)` rows must be resolved manually (no unique constraint exists today) | `night` must be **restated explicitly**, not inherited from `end <= start` |
| `late_policy` | Rules + policy values | Each row becomes one or more rules with an explicit scope and an effective range. `weekoff_action` and `no_cut_on_weekoff` have **no reader** and therefore no meaning to carry (DEFECT-B-07) | The hidden L1 fall-through (`:494`) is **not** carried over; each affected tenant is told |
| `attendance_corrections` | `attendance_regularisations` + overlays | Whole table migrates; the original/requested/approved triple is preserved | Rows at `approved` + `payroll_locked = true` are **unprocessed liabilities** and become `attendance_arrears_items` with an owner — **D-XI-02** decides their disposition |
| `biometric_configs` | `devices` + connector/recipe rows | `branch` name → org-unit FK by lookup, unmatched names reported; credentials re-encrypted under managed keys; `api_config` JSON → a validated recipe version (rejected recipes are reported, never silently NULLed) | Runtime-only columns (`serial_number`, `api_config`, `sync_interval_min`) may be absent on some installs (C §2.1) |
| `employees.device_user_id` | `device_identity_map` | One dated row per employee, `device_id = NULL` (any device), `identity_type = device_pin`, effective from the earliest punch | Duplicate identity values across employees must be resolved before cutover |
| Auto-registered NULL-tenant `/iclock` devices | Claimed or purged | Each must be explicitly claimed by its owning tenant before cutover | Unclaimed devices' punches cannot be safely attributed |
| `attendance_ratings` | Per-day annotation with a tenant-scoped unique key | Backfill `tenant_id` from the punch rows; collisions where two tenants share an `emp_code` are resolved manually (FINDING-B-SEC-02) | |
| `mobile_devices`, `biometric_unmapped`, legacy `attendance`, `devices` (LAN) | Not migrated as tables | Advisory data only; re-enrol under the real trust model | |

**R-XI-126** A full, verified snapshot of `attendance_logs` is taken and retained before any
cutover activity. *`attendance:dedupe` has been executed unattended by a migration on every
install since 16 August 2026, with no log of what it deleted; there is no way to reconstruct
what was removed (OQ-B-12).*

**R-XI-127** The migration produces a per-install **reconciliation report**: punches in,
punches out, rows quarantined with reasons, identities unresolved, rosters unresolved,
duplicate shift names, and the arrears items raised from unprocessed locked corrections. A
migration that cannot reconcile does not proceed.

**R-XI-128** Night-shift history is re-attributed only after **D-XI-01** is answered, in a
previewed, audited re-derivation with a per-employee impact report — because it moves hours
between payroll months.

---

## XI.20 Edge cases and failure states

| # | Case | Behaviour |
|---|---|---|
| 1 | 22:00–06:00 shift | One attendance day (attribution rule), one present day, late computed on instants, night allowance once |
| 2 | Punch at 23:59:59 on 31-Mar with a 04:00 day boundary | Belongs to the 31-Mar attendance day; financial-year roll-up follows the attendance day, not the calendar date |
| 3 | Employee punches IN, never OUT | `missing_punch_policy`; day carries `is_imputed_out` if imputed, always with an exception and a notification; never silently `worked = 0` |
| 4 | Orphan OUT with no IN | Exception `ORPHAN_OUT`; the punch is retained and visible; the day is `UNDER_REVIEW` unless the policy says otherwise |
| 5 | Two readers fire in the same second | Both stored; the second is quarantined as a near-duplicate with a reason; neither is deleted |
| 6 | Device clock drifts by 40 minutes | Skew detected per batch; health warning, then quarantine at the second threshold; correctable by re-declaring the device zone/offset and recalculating |
| 7 | Device offline three days, then uploads its buffer | Late-arriving punches land normally; each affected day is recalculated at generation n+1; days in a frozen period raise arrears items |
| 8 | Punch arrives for a day already in a frozen period | Layer 2 accepts it (evidence is never refused); layer 3 is not silently changed; an arrears item is raised (R-XI-013) |
| 9 | Employee transfers branch mid-month | Each day resolves rules for the branch in force **on that day** (spine S3) |
| 10 | Employee has two employments in one tenant | Punches resolve to an employment, not a person; two independent day series |
| 11 | Same PIN reused on a second device | Device-scoped mapping; if the tenant permits reuse, both resolve correctly (D-XI-06) |
| 12 | PIN re-issued to a new joiner | The old mapping is closed with an effective date; history stays with the original employment (R-XI-042) |
| 13 | Device sends a status byte the profile does not map | `direction_code = unknown`, `direction_token_raw` retained, review queue entry; never silently `in` (D-XI-05) |
| 14 | Vendor feed changes its date format | Recipe validation fails at parse; lines are `rejected` with a reason and are re-processable after the recipe is corrected — nothing is lost |
| 15 | Vendor returns an HTML login page instead of XML | Response encoding declared → parse fails → typed rejection. *SmartPRS 2 regex-scrapes and feeds the raw body into a field sniffer (DEF-C-16)* |
| 16 | Connector times out mid-window | High-water mark is not advanced; retry with backoff; circuit breaker after N consecutive failures; health degraded |
| 17 | Two tenants register the same manufacturer serial | Both are valid; `(tenant, serial)` is unique; neither can see the other's punches |
| 18 | Unknown serial appears | Per tenant policy: rejected, or quarantined pending claim; never auto-enabled by default |
| 19 | Holiday falls on a weekly off | Both flags set; comp-off/OT rules disambiguate by explicit `EXCEPT` clauses (Part VII RL-ATT-007/008) |
| 20 | Half-day leave plus a short attendance | Day fraction composed: leave 0.5 + payable 0.5, or leave 0.5 + LOP 0.5 by rule; never double-counted |
| 21 | Approved leave cancelled after the day is derived | Recalculation triggered by the leave event |
| 22 | Roster published for a past month | Diff preview first; on apply, recalculation with arrears for frozen periods |
| 23 | Shift version published with a retro-effective date | Same path; the impact preview names affected employees and days before publication |
| 24 | Employee joins mid-shift-day / exits mid-day | Days outside the employment range are `NOT_JOINED` / `EXITED`, not `ABSENT` |
| 25 | DST transition inside a night shift | Scheduled minutes recomputed from the two instants; settlement uses the true schedule (**D-XI-14** for non-IST deployments) |
| 26 | Employee punches at a site whose device belongs to another org unit | Punch accepted, attributed to the employment; the day carries `cross_site_punch` for reporting |
| 27 | Geofence: no GPS available on a mobile punch | Verdict `no_gps`; policy decides accept-with-flag or reject; **the punch and its verdict are always stored** |
| 28 | Regularisation approved for an overnight OUT | Overlay carries an instant on D+1; both days are recalculated |
| 29 | Two regularisations for the same day | Blocked by the database constraint at the configured limit, with a clear message — not by a PHP `if` |
| 30 | Recalculation runs while a payroll run is in preview | The run's inputs are versioned; a superseded day marks the settlement `stale` and the run's preview blocks |
| 31 | Rule evaluation error (e.g. an aggregation exceeds its row cap) | The day is derived with `status = UNDER_REVIEW`, the error is surfaced to the operator, and it is **never** silently swallowed (spine S5.6; *SmartPRS 2 destroys 50.5% of its caught exceptions*) |
| 32 | No shift resolvable for a working day | `NEEDS_SHIFT` status, exception raised, no silent 09:30–18:30 (R-XI-075) |
| 33 | Employee works on a day their roster marks as weekly off | `worked_weekly_off` event; comp-off/OT rules apply; the weekly off remains a fact |
| 34 | Manual punch keyed with a date and time that disagree | Impossible: a manual punch carries an instant; the attendance day is derived. *SmartPRS 2's Manual Punch master allows `log_date` and `punch_at` to be set independently with no consistency check — the only place a shift-aware date can be expressed, and it is accidental (NEW-ATT-016)* |
| 35 | Bulk import re-approved after a re-upload | Idempotent by batch line identity; duplicates are reported, not inserted. *SmartPRS 2 has no dedup on the bulk path and duplicates the day (B §Q7)* |

---

## XI.21 Tests

### XI.21.1 Golden tests — exact values, regression-locked

The brief's Section 113 attendance golden-test list is the **floor**. Each test below is
additionally traceable to a specific SmartPRS 2 defect, so a regression is detectable as a
return to a known failure.

| # | Scenario | Exact expected value | Guards |
|---|---|---|---|
| G-01 | 22:00–06:00 shift, IN 21:58, OUT 06:04 | **one** attendance day; worked 486m; present 1.0 day; late 0m; early exit 0m | DEFECT-B-01 |
| G-02 | Same shift, IN 00:15 | late = 135m (not 0) | DEFECT-B-02 |
| G-03 | 30-Sep 22:00 → 01-Oct 06:00, monthly cycle | all hours in September; October payable days unchanged | DEFECT-B-01 |
| G-04 | Day shift 09:30–18:30, IN 09:41, grace 15m | late 0m, status PRESENT | CALC-B-04 |
| G-05 | Same, IN 10:05 | late 20m | CALC-B-04 |
| G-06 | 09:15 IN, 13:05 OUT, 13:35 IN, 18:35 OUT | worked 530m, break 30m — **identical in report, ESS, payroll input and API** | DEFECT-B-08, DEFECT-B-13 |
| G-07 | Day ending on an unclosed IN | one deterministic break total across every consumer; exception raised | DEFECT-B-08 |
| G-08 | Break budget 60m, break 155m, block 30m, step 0.25d, ceil | penalty exactly 1.0 day | CALC-B-08 |
| G-09 | 3rd qualifying late over 10m in the payroll period | half day, occurrence index 3, cap respected | Part VII RL-ATT-001, brief §127 |
| G-10 | Same rule as `rolling(30, days)`; lates on 30-Sep, 01-Oct, 02-Oct | matched (a per-month rule does **not** match) | RL-ATT-010, BR-ATT-022 |
| G-11 | Late 8m past grace with bands starting at 10m | penalty **0** (no fall-through to the first band) | BR-ATT-021b |
| G-12 | Settlement variant B: late 13m, late exit 41m, shortfall 21m, cap 60m | offset 34m, late 0m, shortfall 0m, OT 0m | §XI.11.5 |
| G-13 | Same day under variant A | late 13m, shortfall 21m, one late occurrence | §XI.11.4 |
| G-14 | Zero-punch working day, no leave, not holiday, not weekly off | row exists, status ABSENT, notification deduped once | NEW-ATT-006, DEFECT-B-10 |
| G-15 | Zero-punch day that is a scoped holiday for this employee only | status HOLIDAY; a colleague in another state is ABSENT | NEW-ATT-011 |
| G-16 | Single-tenant install, absence sweep | runs; produces statuses | NEW-ATT-010 |
| G-17 | Punch string `2026-08-16 09:41:00+00:00` on a `naive_local` adapter | **rejected** with `TIME_FORMAT`; no row in layer 2 | DEF-C-04 |
| G-18 | Same string on an `offset_authoritative` adapter, tenant IST | stored as 15:11 IST | DEF-C-04 |
| G-19 | Feed emitting `03/04/2026` with declared `m/d/Y` | 4 March, not 3 April | B §Q5 #15 |
| G-20 | Device PIN `5`; another employee's code is `5`; explicit mapping exists | punch resolves to the **mapped** employee | DEF-C-01 |
| G-21 | PIN `7` on device A and device B mapped to different employments | each resolves correctly | BR-DEV-005 |
| G-22 | PIN re-issued after an employee exits | punches before the close date keep the old employment | R-XI-042 |
| G-23 | Same batch posted twice | second returns all `duplicate`; zero new rows; counts reconcile | R-XI-016 |
| G-24 | Same punch arriving via device pull and via SBB | one accepted, one duplicate/quarantined; provenance of both retained | B §Q7 |
| G-25 | Batch with 1 good, 1 blank-PIN, 1 unparsable-date, 1 unknown-device line | 4 layer-1 rows; verdicts `accepted`, `rejected`, `rejected`, `quarantined_unknown_device`; counts sum to 4 | DEFECT-B-22, DEF-C-17 |
| G-26 | Unknown serial with policy `quarantine` | no device row activated; punches held; console shows a claimable device | BR-DEV-001, SEC-C-02 |
| G-27 | Punch body claiming a different tenant than the credential | credential wins; body ignored; assertion test | SEC-C-01, positive finding preserved |
| G-28 | Two tenants, same serial | isolated; cross-tenant read returns nothing | SEC-C-03 |
| G-29 | Mapping an identity releases held punches | released through the normal pipeline; verdicts identical to live ingest; days recalculated | DEF-C-07 |
| G-30 | Dedupe of two genuinely distinct punches in the same second | both retained, one quarantined, **nothing deleted** | B §Q7 |
| G-31 | Correction approved on an **open** period | overlay written, day regenerated, settlement refreshed, employee told the actual outcome | DEFECT-B-30 |
| G-32 | Correction approved on a **frozen** period | arrears item created with owner, target period and delta; visible in the queue; payroll preview consumes it | DEFECT-B-30, D-XI-02 |
| G-33 | Lock check on a 21st–20th cut-off, correction dated the 25th | tested against the **correct** run; portable across drivers; unknown status fails **closed** | DEFECT-B-31 |
| G-34 | Correction expressing an OUT on the following day | accepted; both days recalculated | NEW-ATT-014 |
| G-35 | Approved correction plus an erroneous device OUT on the same day | overlay supersedes; sessions correct; both views renderable | DEFECT-B-18 |
| G-36 | Policy V2 published in October, effective 1-Oct; recalculate August | August uses V1 | R-XI-012, spine S4 |
| G-37 | Recalculate the same range twice | second run: zero diffs, no new generation | R-XI-011 |
| G-38 | Roster imported from a template | shift dropdown lists only shifts in force for that scope and period; unknown employee rows reported, not written | R-XI-088 |
| G-39 | Rotation pattern 4-on/2-off, 3 crews, 18-day cycle, phases 0/6/12 | regenerating the same range produces byte-identical roster days | R-XI-091 |
| G-40 | 2nd and 4th Saturday weekly-off pattern in a 5-Saturday month | correct actual weekdays (not `ceil(day/7)`) | RL-CAL-001 |
| G-41 | Device silent for 3× its cadence | health `stale`, event emitted, console shows the real state (never a green last-sync) | DEF-C-14 |
| G-42 | Connector fails 3 windows then recovers | no gap: the missed range is re-fetched from the high-water mark | R-XI-028 |
| G-43 | Recipe pointing at `169.254.169.254` | blocked by the egress profile; save or run refused with a clear error | SEC-C-04 |
| G-44 | Invalid recipe JSON | rejected on save with a field-level error; never stored as NULL with `ok:true` | DEF-C-13 |
| G-45 | `Explain` on any derived day | returns every element of §XI.14.1; a day without a trace throws | Law 4 |
| G-46 | Any name-based join attempt in the domain layer | CI check fails the build | R-XI-043 |
| G-47 | Split shift, second segment not worked | penalty per WT-06; the gap is not counted as break time | §XI.10.6 |
| G-48 | Employee with two employments punching on both | two independent day series; no cross-contamination | §XI.20 #10 |

### XI.21.2 Adapter conformance suite

Every adapter, including every recipe kind, passes the identical suite: parse fixtures →
expected canonical punches; one fixture per rejection class; idempotency (same batch twice);
time-policy conformance (accept/reject per declaration); a cross-midnight fixture; a tenancy
test proving the adapter cannot influence the tenant; a health test; and a "no side effects"
test asserting the adapter wrote nothing.

### XI.21.3 Property and isolation tests

- **Tenancy.** A generated cross-tenant test for every attendance and device endpoint and
  every aggregate (Law 6).
- **Determinism.** For a random employee-day corpus, deriving twice yields identical rows and
  identical traces.
- **Reconciliation.** For every batch: `received = inserted + updated + duplicate +
  quarantined + rejected`.
- **Monotonicity.** Generations never decrease; exactly one current row per employee-day.
- **No literals.** Static analysis fails the build on a bare numeric or string business
  literal in `Domain\Attendance` (Law 1).
- **No DDL.** CI greps for schema calls outside migrations (Law 2).
- **No wall clock in rules.** SPX exposes only `context.evaluation_date` (Part VII R-VII-019).

### XI.21.4 Performance envelopes

10,000 employees: derive one day for all of them within the nightly window; recalculate one
month for one department (500 employees × 30 days) with a preview diff in minutes, not hours;
ingest a 1,000-punch batch within the API timeout with per-punch verdicts; muster-roll query
for a 500-employee branch-month from the indexes named in §XI.16.3 without a full scan.
*SmartPRS 2's report loads every employee twice per call, resolves policies per employee in
PHP, and issues one SELECT plus one UPDATE per employee-day in the direction sequencer
(DEFECT-B-27), against a table that on some installs has no `(emp_code, log_date)` index at
all (DEFECT-B-04).*

---

## XI.22 Open items — PRODUCT OWNER DECISION REQUIRED

| ID | Question | Recommendation | Trade-off |
|---|---|---|---|
| **D-XI-01** | The default attendance-day attribution rule for a cross-midnight shift, and how historic night-shift punches are re-attributed (OQ-B-01) | **`shift_start_owns`** as the platform default, per-shift overridable; migrate history only after a per-tenant impact report | Moves the 30-Sep night's hours into September. The alternative (`shift_end_owns`) matches some night-allowance conventions but splits a month boundary the other way. `calendar_date` is offered only to reproduce legacy behaviour during migration |
| **D-XI-02** | Disposition of a correction approved against a locked period, and of the rows already in that state in production (OQ-B-02) | **Arrears item** by default, with reopen available under permission; enumerate existing `approved + payroll_locked` rows before cutover and raise an arrears item for each | Arrears is auditable and does not disturb a closed month; reopening is cleaner arithmetically but re-opens statutory filings. **This must be answered before migration is planned** |
| **D-XI-03** | Does presence require a minimum of worked time (OQ-B-03)? | **Yes**, `present_min_minutes` with a seeded value, but migrate existing tenants at 0 minutes (present = any punch) so no historic LOP changes silently, then let each tenant opt in | Today a single one-minute punch is a full paid present day (CALC-B-14). Changing it retroactively would alter historic LOP |
| **D-XI-04** | The `/iclock` trust model (C OQ-1) | **Pre-registration + per-device enrolment secret in the server path + tenant IP allow-list + quarantine-until-claimed.** Deprecate unauthenticated auto-registration entirely | Real ADMS firmware cannot present a credential; the compensating controls add an installer step. This is the single largest security question in the area (SEC-C-01/03) |
| **D-XI-05** | What an unknown direction token becomes (C OQ-5) | **`unknown` + review queue**, per device with a tenant default | Safer than silently `in` (four sites today), but changes what payroll sees on badly configured sites |
| **D-XI-06** | Is "PIN 5 on Chennai is Ravi, PIN 5 on Pune is Sunil" supported (C OQ-4)? | **Yes** — the mapping is device-scoped with an "any device" fallback | Slightly more complex enrolment; the quarantine replay already assumes it |
| **D-XI-07** | Should mapping a device identity rewrite historic punches (C OQ-9)? | **Never as a side effect.** Re-attribution is an explicit, previewed, audited operation, blocked into locked periods without approval | Slower for the admin; today's behaviour silently rewrites inputs to approved payroll runs |
| **D-XI-08** | Retention for punch evidence, quarantine, health snapshots and traces (OQ-B-08) | Punch evidence **3 years + current financial year** minimum, aligned to statutory register retention; logs 13 months; traces 13 months with the day's trace retained as long as the day | Nothing is purged today and every ledger grows forever. Longer retention costs storage; shorter retention weakens an audit defence |
| **D-XI-09** | Preserve `break_in`/`break_out`/`ot_in`/`ot_out` as distinct punch types (C OQ-11)? | **Yes** — carry them in the direction value set, collapse to in/out only in pairing when the shift's strategy says so | A data-model decision, not a mapping one; collapsing them (today's behaviour) loses break evidence permanently |
| **D-XI-10** | Does the direct LAN adapter ship at launch (C OQ-2)? | Ship `zk.tcp` **only if** a live customer uses it; otherwise defer. Survey `composer show` and device inventories | The existing LAN path loses intra-day punches irrecoverably; historic rows from it cannot be reconstructed |
| **D-XI-11** | Sync frequency granularity (C OQ-10) | **Arbitrary interval or cron, plus an active window**, with sensible presets | The fixed 5/10/15/30/60 dropdown cannot express "every 4 hours" or "06:00–22:00 only" |
| **D-XI-12** | Who approves an egress allow-list entry (C OQ-6) — the tenant admin or Ametecs? | **Tenant admin for LAN/private ranges with an explicit acknowledgement; Ametecs for public-internet hosts on SaaS** | A tenant-controlled allow-list weakens SSRF protection; an Ametecs-controlled one adds a ticket to every new integration |
| **D-XI-13** | Is SmartEPT a permanent bespoke integration or the first of many signed-webhook senders (C OQ-7)? | Treat it as **the first of many**: one `webhook.signed` adapter with a SmartEPT profile | Slightly more work now; otherwise the second partner repeats the whole class |
| **D-XI-14** | Will any deployment run outside IST (OQ-B-10)? | Build for it (per-device and per-tenant zones are already required); confirm whether DST-observing zones are in scope for v3.0 | If yes, DST handling and the night-shift schedule recomputation must be tested against a DST-observing zone before release |
| **D-XI-15** | Are late-cuts actually applied in production today (OQ-B-06)? | Survey `SELECT mode, cut_mode, cut_n, l1_min, l1_cut, break_cut FROM late_policy` per install | If they are all unset, the late-penalty engine is dead in the field and SmartPRS 3's design is greenfield rather than a migration — which changes the migration mapping and the customer conversation |

---

## XI.23 Requirements index

| Range | Area | Count |
|---|---|---|
| R-XI-001 – R-XI-004 | Layer separation and raw evidence (§XI.2.1) | 4 |
| R-XI-005 – R-XI-008 | Canonical punch and correction overlays (§XI.2.2–XI.2.3) | 4 |
| R-XI-009 – R-XI-010 | Derived days and supersession (§XI.2.4) | 2 |
| R-XI-011 – R-XI-015 | Recalculation, preview and diff (§XI.2.5) | 5 |
| R-XI-016 – R-XI-019 | De-duplication, idempotency, double-tap (§XI.2.6) | 4 |
| R-XI-020 – R-XI-021 | Period settlement (§XI.2.7) | 2 |
| R-XI-022 – R-XI-029 | Adapter contract, capabilities, lifecycle (§XI.3.1–XI.3.3) | 8 |
| R-XI-030 – R-XI-038 | Connector recipes, egress, launch matrix, SBB boundary (§XI.3.5–XI.3.7) | 9 |
| R-XI-039 – R-XI-045 | Canonical punch contract and identity resolution (§XI.4) | 7 |
| R-XI-046 – R-XI-057 | Trust, tenancy, verdicts, credentials, audit (§XI.5) | 12 |
| R-XI-058 – R-XI-066 | Time policy, storage, scheduling, cross-midnight, DST, skew (§XI.6) | 9 |
| R-XI-067 – R-XI-074 | Console, health, quarantine, commands, counting (§XI.7) | 8 |
| R-XI-075 – R-XI-085 | Shift master, resolution, attribution, pairing, calendar (§XI.8) | 11 |
| R-XI-086 – R-XI-095 | Roster management (§XI.9) | 10 |
| R-XI-096 – R-XI-099 | Work-time policy engine vocabulary (§XI.10) | 4 |
| R-XI-100 – R-XI-102 | Time settlement engine (§XI.11) | 3 |
| R-XI-103 – R-XI-108 | Absent detection (§XI.12) | 6 |
| R-XI-109 – R-XI-119 | Regularisation, arrears, re-attribution (§XI.13) | 11 |
| R-XI-120 – R-XI-121 | Explainability (§XI.14) | 2 |
| R-XI-122 | Configuration surface completeness (§XI.15) | 1 |
| R-XI-123 – R-XI-125 | API surface (§XI.17) | 3 |
| R-XI-126 – R-XI-128 | Migration (§XI.19) | 3 |
| **Total** | | **128** |

### Compliance with the seven laws (spine S1)

| Law | How this part complies | Test |
|---|---|---|
| 1 — no business literal | Every threshold is a rule literal or `policy.value()`; §XI.15 lists 34 configurable surfaces | Static analysis on `Domain\Attendance` (G-46 and §XI.21.3) |
| 2 — no runtime schema change | All tables are migration-owned; the eight runtime-DDL sites in SmartPRS 2 have no successor | CI grep; `migrate:fresh` parity |
| 3 — versioned and dated | Shifts, calendars, rosters, rules and settlement policies are artefact versions; days are superseded by generation; punches are append-only | G-36, G-37 |
| 4 — every number explains itself | §XI.14; a day without a trace throws | G-45 |
| 5 — authorisation fails closed | Every endpoint in §XI.17 has an explicit policy; self-service resolves the subject from the session; the lock test fails closed | R-XI-123, R-XI-115 |
| 6 — tenancy is structural | `tenant_id NOT NULL` in every table and every unique index; tenant from credential only | R-XI-046, R-XI-050, G-27, G-28 |
| 7 — money is exact | This part produces **minutes and day fractions only**; money is Part XIV's | R-XI-102 |


