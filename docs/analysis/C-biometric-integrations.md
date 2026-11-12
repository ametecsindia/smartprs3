# AS-IS ANALYSIS — BIOMETRIC DEVICES AND ATTENDANCE INTEGRATIONS (transport, device, protocol, mapping, credential and adapter layer)

Scope boundary: this file owns **how a punch physically arrives, who it is trusted to be, and
how it is turned into a row**. It does not own what the row then means for attendance,
lateness or payroll (analyst covering attendance business logic owns that).

---

## 0. Scope and evidence base

### 0.1 Reference documentation read

| Document | Lines read | Used for |
|---|---|---|
| `COMPLETE_PROJECT_BUSINESS_AND_TECHNICAL_DOCUMENTATION.md` | 5211–5408 (§06 §0–§1 data model), 5408–6190 (§06 §2 ingest paths, §3 device mode) | ingest path inventory, device mode |
| same | 6948–7092 (§10 API key / webhook credential management) | credential rules |
| same | 7092–7200, 7300–7340, 7548–7575 (BR-DEV-001…010, BR-ATT-012…015, 044…047) | BR-ID reuse |
| same | 51134–51420 (§19a Third-party integrations) | outbound integration inventory, FINDING-INT-* |
| `PROJECT_ARCHITECTURE_AND_WORKFLOWS.md` | 2401–2501 (§10 Integration architecture), 803–992 (§7 how a punch becomes money) | cross-check |

### 0.2 Source files read completely

| File | Lines | Read |
|---|---|---|
| `app/Http/Controllers/BiometricConfigController.php` | 721 | full |
| `app/Http/Controllers/PushController.php` | 286 | full |
| `app/Http/Controllers/DeviceController.php` | 239 | full |
| `app/Http/Controllers/MobileDeviceController.php` | 260 | full |
| `app/Http/Controllers/ApiKeyController.php` | 546 | full |
| `app/Http/Controllers/Api/PublicApiController.php` | 118 | full |
| `app/Http/Controllers/Api/SmarteptWebhookController.php` | 166 | full |
| `app/Services/ETimeOfficeService.php` | 784 | full |
| `app/Services/ETimeTrackLiteService.php` | 313 | full |
| `app/Services/GenericApiService.php` | 458 | full |
| `app/Services/PunchIngestService.php` | 403 | full |
| `app/Services/SmarteptWebhook.php` | 337 | full |
| `app/Services/ApiKeys.php` | 78 | full |
| `app/Http/Middleware/ApiKeyAuth.php` | 156 | full |
| `app/Http/Middleware/SmarteptSignature.php` | 112 | full |
| `app/Console/Commands/SyncETimeOffice.php` | 157 | full |
| `app/Models/Device.php` | 20 | full |
| `routes/api.php` | 43 | full |
| `routes/smartept.php` | 35 | full |
| `routes/web.php` | lines 75–106, 518–541 (device/iclock/mobile/bio/api-key routes) | targeted |
| `resources/views/settings/api-keys.blade.php` | 306 | full |
| `resources/views/settings/pending-punches.blade.php` | 129 | full |
| `app/Http/Controllers/AppController.php` (SPA) | lines 3160–3200, 9350–9800 (biometric setup screen, presets, mapping card) | targeted |
| `app/Services/LateArrivalService.php` | lines 53–74, 280–300 | targeted (inline mail from import) |
| `app/Services/ApprovalService.php` | 365–381 | targeted (`denyUnlessRole` fail-open) |
| `config/smartprs.php` | 101–122 | targeted |
| `routes/console.php` | 222–239 | targeted |
| `database/migrations/2026_06_25_000000_create_biometric_configs_table.php` | 57 | full |
| `database/migrations/2026_07_22_000200_add_biometric_device_mapping.php` | 35 | full |
| `database/migrations/2026_08_28_000200_biometric_device_mode.php` | 47 | full |
| `database/migrations/2026_08_16_000100_create_api_keys_table.php` | 45 | full |
| `database/migrations/2026_08_16_000200_extend_attendance_logs_for_sbb.php` | 196 | full |
| `database/migrations/2026_08_16_000300_create_attendance_pending_table.php` | 50 | full |
| `database/migrations/2026_08_17_000100_repair_sbb_schema.php` | 235 | full |
| `database/migrations/2026_08_18_000100_attendance_pending_device_code.php` | 120 | full |
| `database/migrations/2026_08_26_000100_create_smartept_webhook_endpoints_table.php` | 55 | full |
| `database/migrations/2026_01_01_000800_create_devices_table.php` | 13 | full — **it is a no-op stub** |
| `tests/Feature/SbbIngestTest.php` | 481 | full |
| `tests/Feature/DeviceModeTest.php` | 234 | full |
| `tests/Feature/IclockTenantHotfixTest.php` | 298 | full |
| `tests/Feature/SmarteptWebhookTest.php` | 402 | full |
| `sbb-verify.php` | 168 | full |
| `SESSION-REPORT-2026-08-16-SBB.md` | 609 | full |
| `sbb-schema-status.txt` | 137 | full |

### 0.3 Files the task named that DO NOT EXIST in the staged source

- `resources/views/devices/form.blade.php` — **absent**
- `resources/views/devices/index.blade.php` — **absent**

`DeviceController::index()` (`DeviceController.php:19-21`), `create()` (`:26-29`) and
`edit()` (`:42-45`) return `view('devices.index')` / `view('devices.form')`. Neither view
exists and neither route is registered (only `POST /app/device/{id}/sync` →
`syncById`, `routes/web.php:518`). Those six methods are unreachable dead code that
would fatal if reached. Recorded as DEF-C-14.

`bootstrap/app.php` is **not present** in the staged copy, so the middleware-alias
registration for `api-key` / `smartept-webhook` and the mounting of `routes/api.php`
and `routes/smartept.php` could not be verified from source; they are inferred from the
route files' own docblocks (`routes/api.php:17-18`, `routes/smartept.php:17-19`) and
from `sbb-schema-status.txt:129-133`, which shows `api/v1/ping`,
`api/v1/attendance/punches`, `app/api-keys` and `app/pending-punches` all resolving on a
live install. Flagged as an evidence gap, not a defect.

---

## 1. Business capability inventory

### 1.1 Device connection management ("Biometric Device Setup")

A tenant admin or HR manager registers **many** attendance devices/connections, each a row
in `biometric_configs`. Per device they set: a free-text label, a Branch (chosen from the
Branches master **by name string**), the provider, an enable toggle, the provider's
connection fields, a sync frequency, an "Employee ID prefix", and whether the location has
one reader or two. Backend `BiometricConfigController` (721 lines); UI is generated
JavaScript inside `AppController.php:9599-9790`.

Four provider values are offered in the UI (`AppController.php:9634-9643`):

| UI label | stored `provider` | Direction of traffic |
|---|---|---|
| Push / ADMS — device pushes to SmartPRS | `push` | inbound, device dials out to us |
| eSSL eTimeTrackLite — Local WebAPI (SOAP, port 81) | `etimetracklite` | outbound pull, SOAP |
| eTimeOffice — Cloud API | `etimeoffice` | outbound pull, REST-ish JSON |
| Custom / Generic API — configure any HTTP API | `generic` | outbound pull, admin-described |

### 1.2 Unauthenticated ADMS/iclock push receiver

`PushController` emulates the ZKTeco/eSSL "Cloud Server (ADMS)" protocol at five fixed
paths at the web root. A device is identified **only by the `SN` query parameter**. An
unknown serial silently self-registers a `biometric_configs` row with `tenant_id = NULL`
and `enabled = true` (`PushController.php:209-224`). No credential of any kind is
exchanged.

### 1.3 Smart Biometric Bridge (SBB) — authenticated JSON ingest

`POST /api/v1/attendance/punches`, authenticated by an API key issued from
*Time & Attendance → API Keys*. Batch of up to 1000 punches, per-punch verdict returned
(`accepted` / `duplicate` / `pending` / `rejected`). `GET /api/v1/ping` returns the
customer name and server timezone so an installer can confirm the key points at the right
workspace before shipping punches.

### 1.4 SmartEPT webhook receiver

`POST /api/v1/webhooks/smartept/{slug}`, authenticated by HMAC-SHA256 over the raw body.
Accepts `attendance.punch` (one live IN/OUT) and `attendance.daily` (per-employee day
summary, expanded into a `first_in` IN punch and a `last_out` OUT punch). SmartPRS never
calls SmartEPT — inbound only.

### 1.5 Vendor pull connectors (three)

`attendance:sync-etimeoffice` ticks every five minutes (`routes/console.php:225-226`) and
pulls each enabled `biometric_configs` row that is due by its own `sync_interval_min`.
Three provider implementations: eTimeOffice cloud REST, eTimeTrackLite SOAP, and a
fully admin-described Generic HTTP connector with five starter presets (blank, generic
JSON, generic SOAP/XML, ZKTeco BioTime 8, Matrix COSEC —
`AppController.php:9437-9469`). A "Test connection" button fetches and parses without
writing, and echoes the **raw vendor response** (first 1500 chars) back to the browser.

### 1.6 Direct LAN ZKTeco pull

`POST /app/device/{id}/sync` connects to a device on its IP:port (default 4370) with the
`rats/zkteco` library and downloads the whole on-device log. This is a **separate device
master** (`devices` table, `Device` model) with no relationship to `biometric_configs`.

### 1.7 Device-ID ↔ employee mapping and punch quarantine

A punch whose device code matches no employee is **held** in `attendance_pending` rather
than discarded, counted in `biometric_unmapped` for the "Employee Mapping" card, and
**replayed into `attendance_logs` the moment an admin maps the code**. Two screens do the
mapping: the SPA "Employee Mapping" card (`AppController.php:9563-9598`) and the legacy
Blade *Pending Punches* page (`resources/views/settings/pending-punches.blade.php`).

### 1.8 Device claiming (unassigned devices)

An auto-registered push device has no tenant, so under the symmetric tenant filter it can
only ever match tenant-less employees. `BiometricConfigController::assign()` (`:212-261`)
lets a tenanted admin claim such a row. **Only the Blade Pending Punches page renders
this**; the SPA's Biometric Device Setup screen fetches `unassigned` from the API but
never displays it (see DEF-C-11).

### 1.9 API keys and SmartEPT webhook credentials

One screen (`settings/api-keys.blade.php`) issues both credential types.
API keys: `sk_prs_<4>_<40>`, shown once, only sha256 stored, scopes `ingest`/`read`,
optional expiry, revoke-only (no rotation). SmartEPT receivers: random 16-char slug in
the URL plus a `whk_ept_<48>` shared secret, `Crypt`-encrypted at rest, rotatable while
keeping the URL, with delivery-health counters on screen.

### 1.10 Mobile app device gate and push tokens

`MobileDeviceController` implements a "register device → admin approves → app unlocks"
gate for the hybrid mobile app, plus an FCM push-token registry. **Nothing outside that
controller ever reads `mobile_devices`** (verified: `grep -rn "mobile_devices" app/`
returns only `MobileDeviceController.php`). No push notification is ever sent from
anywhere in the codebase — `push_token` is written and displayed but never used.

---

## 2. Data model

### 2.1 `biometric_configs` — one row per device/connection

| Column | Provenance |
|---|---|
| `id`, `tenant_id`, `provider`, `enabled`, `base_url`, `endpoint`, `corp_id`, `username`, `password`, `empcode`, `emp_prefix`, `in_machine_id`, `out_machine_id`, `last_sync_at`, `last_status`, `last_count`, timestamps | migration `2026_06_25_000000_create_biometric_configs_table.php:32-50` |
| `label`, `branch` | migration `2026_07_22_000200_add_biometric_device_mapping.php:19-25` |
| `device_mode` | migration `2026_08_28_000200_biometric_device_mode.php:35-39` |
| `serial_number`, `api_config`, `sync_interval_min` | **NO MIGRATION. Created at RUNTIME** by `BiometricConfigController::ensureTable()` (`:66-86`) and `PushController::ensure()` (`:266-284`). |
| `company_id` | **DOES NOT EXIST ANYWHERE.** `assign()` writes it only `if (Schema::hasColumn('biometric_configs','company_id'))` (`:237-239`); no migration and no `ensure*()` creates it, so the company an admin picks when claiming a device is validated (`:233-236`) and then **silently discarded**. |

`CRITICAL FINDING — RUNTIME SCHEMA CREATION.` The whole table can be created at runtime by
either `BiometricConfigController::ensureTable()` (`:44-87`) or `PushController::ensure()`
(`:245-285`), and **the two definitions differ**: `ensureTable()` also adds `api_config`
and does not add… actually both add the same base columns, but `ensureTable()` adds
`api_config` (`:73`) while `ensure()` does not (`:266-274`). Whichever path runs first on a
virgin database determines the shape. `PushController::ensure()` is reachable **by an
unauthenticated HTTP request**.

There is **no unique index on `serial_number`**, and `rowForSn()` (`:207`) looks up by
`provider='push' AND serial_number=?` **with no tenant filter**, taking `orderByDesc('id')`.

### 2.2 `attendance_pending` — the quarantine

| Column | Provenance |
|---|---|
| `id`, `tenant_id`, `company_id`, `device_sn`, `device_user_id`, `punch_at` (NOT NULL), `direction`, `verify_mode`, `external_id`, `source` (default `sbb`), `resolved_at`, timestamps, unique `(tenant_id, external_id)` | migration `2026_08_16_000300_create_attendance_pending_table.php:224-239` |
| `device_code` + unique `(tenant_id, device_code, punch_at, source)` | migration `2026_08_18_000100_attendance_pending_device_code.php:40, 49, 57, 87-98` |
| repair of a partial/foreign pre-existing table | migration `2026_08_17_000100_repair_sbb_schema.php:80-125` |

`CONFLICT` — the reference doc (line 5372-5379) records that `AttendanceBulkController`
creates a table of the **same name** with a completely disjoint schema
(`batch`, `emp_code`, `in_at`, `out_at`, `status`, `error`, `uploaded_by`…). Confirmed
against the migrations here: none of those columns exists in any `attendance_pending`
migration, so the two features are mutually destructive. Owned jointly with the
bulk-upload analyst; recorded here as CONF-C-01 because it breaks device ingest.

`attendance_pending.company_id` is **never populated on the /iclock or pull paths** —
`ETimeOfficeService::quarantine()` reads `$cfg['company_id']` (`:428`) and no caller ever
sets that key (`PushController::cfgForSn()` `:189-197` does not; `rowToCfg()` `:59-79`
does not).

### 2.3 `biometric_unmapped` — the mapping card's worklist

`id, tenant_id, device_code, source, punches, last_seen, timestamps`, unique
`(tenant_id, device_code)`. **NO MIGRATION EXISTS.** Created at runtime, inside a
try/catch, by `ETimeOfficeService::recordUnmapped()` (`:361-371`) — i.e. by whichever
punch import first encounters an unmapped code, including an **unauthenticated /iclock
POST**. This is a second confirmed runtime-schema-creation site.

### 2.4 `api_keys`

`id, tenant_id, company_id, name, prefix(12) indexed, key_hash, scopes(json),
last_used_at, expires_at, active, timestamps` — migration
`2026_08_16_000100_create_api_keys_table.php:26-38`, repaired additively by
`2026_08_17_000100_repair_sbb_schema.php:43-76`. No unique index on `prefix`
(deliberate — `ApiKeys::freePrefix()` `:66-77` tolerates a collision and
`ApiKeyAuth::locate()` `:107-113` iterates every row with that prefix).

### 2.5 `smartept_webhook_endpoints`

`id, tenant_id, company_id, name, slug(32) UNIQUE, secret(text, encrypted), events(json),
active, last_received_at, last_event, last_status(191), received_count, accepted_count,
rejected_count, timestamps` — migration
`2026_08_26_000100_create_smartept_webhook_endpoints_table.php:29-48`. No repair
migration; `ApiKeyController::webhooksNotReady()` (`:372-379`) degrades gracefully.

### 2.6 `attendance_logs` — provenance columns this area writes

`device_sn(64) indexed`, `device_user_id(64)`, `external_id(96)`, `verify_mode(24)`, and
`direction` widened `string(4) → string(8)`: migration
`2026_08_16_000200_extend_attendance_logs_for_sbb.php:50-81`. Two unique indexes added by
the same migration at `:86-94` **after** running `attendance:dedupe` (`:83-84`, `:125-156`)
because the index cannot be created while duplicates remain.

`RUNTIME SCHEMA CREATION` — `DeviceController::ensureLogsTable()` (`:210-227`) creates
`attendance_logs` **without any of the provenance columns and without either unique
index**. If the LAN-ZKTeco sync is the first thing to touch a virgin database, the table
is created in a shape the SBB migration then has to repair.

### 2.7 `devices` — the LAN ZKTeco master

`company_id, name, ip_address, port, serial_number, location, status, last_sync_at`
(`Device.php:12-14`). Its named migration
`2026_01_01_000800_create_devices_table.php` is a **deliberate no-op stub**
(`:10-12`, comment "Superseded: `devices` is now created in the Attendance migration").
The reference doc places the real definition in
`2026_01_01_000200_create_departments_table.php:13-27` — a filename that has nothing to do
with its contents. `devices` carries **no `tenant_id`**.

### 2.8 `mobile_devices`

`id, tenant_id, device_id(80), device_name, platform, host, status, code(8), token(64),
push_token(255), approved_by, approved_at, reject_reason, user_id, last_seen_at,
timestamps`. **NO MIGRATION.** Created at RUNTIME by `MobileDeviceController::ensure()`
(`:36-55`), reachable by an **unauthenticated** `POST /api/mobile/register`
(`routes/web.php:79-80`). Third confirmed runtime-schema-creation site.

### 2.9 `employees.device_user_id` — the mapping column

`string, nullable, indexed` — migration
`2026_01_01_000100_create_employees_table.php:153`. This single column is the entire
device-ID↔employee mapping model: **one device ID per employee, globally, with no notion
of "this PIN on this device"**.

---

## 3. Business rules register

BR-* IDs reused from the reference catalogue where confirmed against source; NEW-* IDs are
mine. "Configurable today" means without editing PHP.

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HARD-CODED? | Configurable today? | Customer could want different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-DEV-001 | An unknown device serial auto-registers itself | `SN` query param | no `biometric_configs` row with `provider='push'` + that serial | INSERT row, `tenant_id=NULL`, `enabled=true`, `last_status='auto-registered'` | /iclock only | `PushController.php:201-228` | **Y** | No — cannot be turned off | **Y** — many customers will want a "no unknown devices" posture |
| BR-DEV-002 | A NULL-tenant device may only match NULL-tenant employees | `$tid` | always | symmetric filter `$tid ? where : whereNull` | all `import()` paths | `ETimeOfficeService.php:338`; same idiom `:87`, `:376`, `:482` | Y | No | N (correct) |
| BR-DEV-003 | Employee matching order | prefixed code, raw code, tenant | always | try column `emp_code` for both candidates, THEN column `device_user_id` for both; first hit wins | all `import()` paths | `ETimeOfficeService.php:312-348` | **Y** | No | **Y** — the column-before-candidate ordering is a defect (DEF-C-01) |
| BR-DEV-004 | Device mode decides direction | `device_mode` | `'single'` → rank-in-day; else machine-ID then feed flag | overrides parsed direction | `ETimeOfficeService::import` only | `:581-584, 710-712, 743` | Y (algorithm) | Partially — mode is per-device on screen | **Y** — see NEW-C-05 |
| BR-DEV-005 | One device ID belongs to one employee | `device_id`, `emp_code`, `force` | another employee in the tenant holds the ID | 409 `needForce`; with `force` move it and null the old holder | mapping | `BiometricConfigController.php:619-633` | Y | No | **Y** — a site with two devices legitimately reusing PIN 5 cannot express it |
| BR-DEV-006 | Only an unassigned device can be claimed | row `tenant_id IS NULL`, acting tenant, optional company | — | set `tenant_id` (+`branch`,`label`) | claiming | `:212-261` | Y | No | N |
| BR-DEV-007 | Single-device mode clears `out_machine_id` | `device_mode` | mode = single | force `out_machine_id = NULL` | save | `:323` | Y | No | N |
| BR-DEV-008 | A device's own sync interval gates the 5-minute scheduler | `last_sync_at`, `sync_interval_min` | `diffInMinutes(now()) < (sync_interval_min ?: 60)` → skip | skip this device this tick | pull paths | `SyncETimeOffice.php:62-73` | Default **60** hard-coded | Interval yes (dropdown 5/10/15/30/60); **default** no | **Y** — the dropdown is a fixed list; sub-5-minute or 4-hourly is impossible |
| BR-DEV-009 | Vendor password encrypted, decrypt failure tolerated | stored `password` | `Crypt::decryptString` throws | **use the stored value as plaintext** | pull paths | `ETimeOfficeService.php:49-55`; `BiometricConfigController.php:363-369` | Y | No | **Y** — silently accepting plaintext should be an alertable condition |
| BR-DEV-010 | Password never returned to the browser | row | always | expose only `hasPassword` bool | UI | `BiometricConfigController.php:137` | Y | No | N (correct) |
| BR-ATT-006 | A punch time carrying a timezone offset is REJECTED on the SBB path | `punch_at` | matches `[Zz]|±HH:MM|±HHMM| AAA$` or not exactly `Y-m-d H:i:s` | verdict `rejected`, reason `TIME_FORMAT` | SBB + SmartEPT-via-SBB | `PunchIngestService.php:308-331` | Y | No | N (correct) — but see NEW-C-10 for the other paths |
| BR-ATT-007 | SmartEPT times are CONVERTED, not rejected | `punched_at` ATOM | parses as a date | `setTimezone(config('app.timezone'))->format('Y-m-d H:i:s')` | SmartEPT | `SmarteptWebhook.php:300-317` | Y | No | N (correct) |
| BR-ATT-008 | An unmapped punch is quarantined, never discarded | punch, cfg, prefixed code | `matchEmployee` returns null | `updateOrInsert`/`insertOrIgnore` into `attendance_pending` | /iclock, pull, SBB, SmartEPT | `ETimeOfficeService.php:410-438`; `PunchIngestService.php:368-371` | Y | No | N (correct) — **but NOT true of the LAN ZKTeco path**, DEF-C-05 |
| BR-ATT-009 | Mapping a device ID releases its held punches | `device_id`, employee, optional `device_sn` | unresolved rows match `LOWER(device_code)` OR `LOWER(device_user_id)` | promote to `attendance_logs`, stamp `resolved_at` | mapping | `ETimeOfficeService::promotePending :455-562` | Y | No | N |
| BR-ATT-012 | A tenant-less credential cannot ingest | key/endpoint `tenant_id` | NULL | 401 | SBB, SmartEPT | `ApiKeyAuth.php:67-69`; `SmarteptSignature.php:72-78`; refused at creation `ApiKeyController.php:267-274` | Y | No | N (correct) |
| BR-ATT-013 | Every punch gets its own verdict | batch | always | `batch{}` counters + `results[]` | SBB, SmartEPT | `PunchIngestService.php:61-212` | Y | No | N — **should be extended to /iclock and pull** |
| BR-ATT-015 | Batch cap 1000; a bigger SmartEPT daily is chunked, not refused | `punches[]` | count > 1000 | SBB: 422; SmartEPT: `array_chunk` | both | `PunchIngestService.php:49`; `PublicApiController.php:59-69`; `SmarteptWebhookController.php:98-111` | **Y (1000)** | No | **Y** |
| BR-ATT-044 | API key shown once, stored as sha256 | — | always | `hash('sha256', secret)`; compare `hash_equals` | keys | `ApiKeys.php:32-47`; `ApiKeyAuth.php:108` | Y | No | N (correct) |
| BR-ATT-045 | SmartEPT secret is encrypted, not hashed, and rotatable keeping the slug | — | always | `Crypt::encryptString`; rotate replaces secret only | webhooks | `SmarteptWebhook.php:97-113`; `ApiKeyController.php:307-337` | Y | No | N (correct) |
| BR-ATT-046 | Webhook failures are indistinguishable | slug, signature | unknown slug / bad sig / undecryptable secret | identical 401 body; `active` checked after verification | webhook | `SmarteptSignature.php:46-70` | Y | No | N (correct) |
| BR-ATT-047 | An unsubscribed event is acknowledged (202), not failed | `event` | not in `EVENTS` or not subscribed | 202 `{ok:true,ignored:true}` | webhook | `SmarteptWebhookController.php:66-75` | Y | Events selectable per receiver | N |
| NEW-C-01 | eTimeOffice auth = HTTP Basic with the password embedded in the username | corp_id, username, password | always | Basic user `"{corp}:{user}:{pass}:true"`, Basic pass `"{pass}"` | eTimeOffice | `ETimeOfficeService.php:139-144` | **Y** | No | **Y** — a vendor that changes its auth scheme needs code |
| NEW-C-02 | eTimeOffice request window format is `d/m/Y_H:i` and the filter param is `Empcode` | window | always | `?Empcode=&FromDate=&ToDate=` | eTimeOffice | `:158-162` | **Y** | `Empcode` value only (default `ALL`) | **Y** |
| NEW-C-03 | eTimeOffice response field names are a hard-coded alias list | JSON | always | `Empcode\|EmpCode\|EmployeeCode\|Code`, `DateTime\|PunchDate\|…`, `INOUT\|InOut\|Status\|Direction`, `MachineNo\|MCID\|DeviceId\|…` | eTimeOffice | `:272-276` | **Y** | No (only the `generic` provider is mappable) | **Y** |
| NEW-C-04 | Blank/unknown direction defaults to `in` on every pull path | direction token | not recognised | `'in'` | eTimeOffice `:224-235`; eTimeTrackLite `:286`; generic `:393` | **Y** | No | **Y** — some sites would prefer `unknown` and a review queue |
| NEW-C-05 | Two reads of the same finger within 60 s are one punch (single-device mode) | punch times | `t - anchor <= 60` | keep current direction, do not advance rank | single mode | `ETimeOfficeService.php:578, 658` | **Y (`SINGLE_REPEAT_SEC = 60`)** | No | **Y** — a slow turnstile needs 120 s; a fast one 20 s |
| NEW-C-06 | ZKTeco ADMS status bytes 1,2,5 mean OUT; everything else means IN | status field | — | `in_array($status,['1','2','5']) ? 'out' : 'in'` | /iclock | `PushController.php:159` | **Y** | No | **Y** — OEM firmware varies; break-in/break-out/OT are collapsed into in/out |
| NEW-C-07 | The ADMS option block SmartPRS hands a device is fixed | `sync_interval_min` | GET `/iclock/cdata?options=all` | `Stamp=9999, OpStamp=9999, ErrorDelay=30, Delay=30, TransTimes=00:00;23:59, TransFlag=1111000000, Encrypt=0`, `TransInterval=max(1,iv?:1)`, `Realtime=(iv>0?0:1)` | /iclock | `:83-103` | **Y** | Only `TransInterval`/`Realtime` via the frequency dropdown | **Y** — `TransTimes`, `TransFlag`, `Delay`, `Encrypt` are all real device policy a customer may need to change |
| NEW-C-08 | SmartPRS never issues a command to a push device | — | device polls `/iclock/getrequest` | always answer `"OK"` | /iclock | `:57-62` | **Y** | No | **Y** — remote user enrolment/deletion/time-sync/reboot is impossible |
| NEW-C-09 | eTimeTrackLite endpoint defaults to **`http://`** and path `/iclock/WebAPIservice.asmx` | typed URL | no scheme typed | prepend `http://`; rewrite to `scheme://host:port/iclock/WebAPIservice.asmx` unless `.asmx` typed | eTimeTrackLite | `ETimeTrackLiteService.php:46-62` | **Y** | Only by typing a full `https://…asmx` URL | **Y** |
| NEW-C-10 | Every path except SBB/SmartEPT falls back to `Carbon::parse()`, which honours an offset and then discards it | punch time string | fixed formats all fail | `Carbon::parse($v)` | /iclock `:177-181`, eTimeOffice `:217-221`, generic `:452-456`, LAN ZK `:148-149` | **Y** | No | N — this is a defect (DEF-C-04) |
| NEW-C-11 | The device-ID mapping backfill re-keys historic `attendance_logs` rows | `device_id`, employee | `device_id` differs from `emp_code` | `UPDATE attendance_logs SET emp_code, emp_name, tenant_id, company_id WHERE LOWER(emp_code)=deviceId AND (tenant=? OR tenant IS NULL)` | mapping | `BiometricConfigController.php:639-655` | **Y** | No | **Y** — silently rewriting historic payroll inputs with no audit row |
| NEW-C-12 | The mobile device gate token is issued but never verified | approval | admin approves | `token = Str::random(48)` stored and returned to the app | mobile | `MobileDeviceController.php:228` | Y | No | **Y** — the gate is decorative (SEC-C-08) |
| NEW-C-13 | The mobile app's tenant is resolved from the request HOST, else a `workspace` slug in the body | host, `workspace` | — | `AuthController::tenantByHost()`, else `tenants.subdomain` | mobile | `:62-81` | Y | Custom domain / slug are data | N |
| NEW-C-14 | `read` scope exists but gates nothing | scope list | — | whitelist `['ingest','read']`; only `ingest` is enforced | API keys | `ApiKeyController.php:39`; `routes/api.php:35-36` | Y | Scopes selectable, but `read` is inert | **Y** |
| NEW-C-15 | API keys cannot be rotated, only revoked and re-issued | — | — | no rotate endpoint | API keys | `ApiKeyController.php` (no `rotate` for keys; docblock `:20-24`) | Y | No | **Y** — zero-downtime rotation needs two live keys |
| NEW-C-16 | `last_used_at` is written at most once per 60 s per key | — | — | throttle | API keys | `ApiKeyAuth.php:28, 134-145` | **Y (60)** | No | N |
| NEW-C-17 | SmartEPT body cap is 2 MiB | body | `strlen > 2097152` | 413 | webhook | `SmarteptWebhook.php:75`; `SmarteptSignature.php:42-44` | **Y** | No | **Y** |
| NEW-C-18 | Both SmartEPT events share one `source` value (`smartept`) | — | always | so a daily summary's `first_in` collides with the live punch on the natural key | webhook | `SmarteptWebhook.php:62` | Y | No | N (correct, and load-bearing) |
| NEW-C-19 | LAN ZKTeco import collapses the whole day to first-in / last-out | device log | always | one IN at `min(time)`, one OUT at `max(time)` per user-day | LAN path | `DeviceController.php:150-157` | **Y** | No | **Y** — every intra-day break is destroyed on this path |
| NEW-C-20 | LAN ZKTeco writes with `direction` IN the match key | employee, date, direction | always | `updateOrInsert(['emp_code','log_date','direction','source'])` | LAN path | `DeviceController.php:193` | **Y** | No | N — inconsistent with every other path (DEF-C-06) |
| NEW-C-21 | Device rate limits are fixed per-IP throttles | — | — | `/iclock/*` `throttle:1000,1`; `/api/v1/*` `throttle:300,1`; mobile 60/120/30 per min | routes | `routes/web.php:92-105, 79-86`; `routes/api.php:34` | **Y** | No | **Y** — many devices behind one NAT share a bucket |
| NEW-C-22 | The Branch a device belongs to is stored as a **name string**, not an FK | branch | — | `biometric_configs.branch` varchar(120), populated from `branches.name` | device setup | `BiometricConfigController.php:156-167, 307` | **Y** | Branch name only | **Y** — renaming a branch orphans every device silently |
| NEW-C-23 | `api_config` is stored only if it parses as JSON, else NULL | posted recipe | `json_decode` not array | store NULL | generic | `BiometricConfigController.php:32-42` | Y | No | N — but the silent NULL gives no error to the admin (DEF-C-13) |
| NEW-C-24 | The generic connector's recipe grammar is a fixed key set | `api_config` | — | ~25 recognised keys, 10 placeholders | generic | `GenericApiService.php:21-40, 177-192` | **Y** | The recipe is data | Partially — pagination, cursors, rate-limit backoff and OAuth2 refresh are **not expressible** |
| NEW-C-25 | Every outbound connector timeout is 60 s and no connector retries | — | — | `Http::timeout(60)`; no `->retry()` anywhere | all pull paths | `ETimeOfficeService.php:158`; `ETimeTrackLiteService.php:89`; `GenericApiService.php:114, 147` | **Y** | No | **Y** |
| NEW-C-26 | A failed pull is logged to the console and skipped; nothing is stamped on the device row | fetch result | `!ok` | `$this->error(...); continue;` | scheduler | `SyncETimeOffice.php:83-87` | Y | No | **Y** — `last_status` still shows the last *success*, so the screen lies |

---

## 4. Calculations and formulas

Transcribed exactly, with the source line.

### 4.1 eTimeOffice Basic-auth credential pair — `ETimeOfficeService.php:139-144`
```php
$user = $cfg['corp_id'].':'.$cfg['username'].':'.$cfg['password'].':true';
return [$user, (string) $cfg['password']];
```
Worked example — corp `ACME`, user `api`, password `S3cr3t!`:
Basic username = `ACME:api:S3cr3t!:true`, Basic password = `S3cr3t!`.
The header becomes `Authorization: Basic ` + base64(`ACME:api:S3cr3t!:true:S3cr3t!`).
**The password appears twice in one header.** No rounding. This is the vendor's scheme.

### 4.2 eTimeOffice request window — `:158-162`
```php
'Empcode'  => $cfg['empcode'] ?: 'ALL',
'FromDate' => $from->format('d/m/Y_H:i'),
'ToDate'   => $to->format('d/m/Y_H:i'),
```
Manual sync window: `days = max(1, min(31, (int) $request->input('days', 1)))`,
`from = now()->subDays($days)`, `to = now()` (`BiometricConfigController.php:476-478`).
Test button window is a fixed **1 day** (`:404-405`).

### 4.3 eTimeTrackLite SOAP window — `ETimeTrackLiteService.php:78-82`
`$from->format('Y/m/d H:i:s')`, `$to->format('Y/m/d H:i:s')` — a different format from
the same `Carbon` window used for eTimeOffice.

### 4.4 Scheduler due-check — `SyncETimeOffice.php:62-70`
```php
$iv = (int) ($cfg['sync_interval_min'] ?? 0);
if ($iv <= 0) { $iv = 60; }
if (Carbon::parse($cfg['last_sync_at'])->diffInMinutes(now()) < $iv) { continue; }
```
Worked example — `sync_interval_min = 15`, `last_sync_at = 10:07`, tick at 10:20:
`diffInMinutes = 13 < 15` → skipped; next tick 10:25 → `18 >= 15` → runs. So a
"15-minute" device actually runs on a 20-minute cadence because the scheduler ticks only
every 5 minutes. **Divisor/basis: whole minutes, no rounding, floor by Carbon.**

### 4.5 ADMS option block — `PushController.php:86-101`
```php
$interval = (int) ($row->sync_interval_min ?? 0);
$realtime = $interval > 0 ? 0 : 1;
'TransInterval=' . max(1, $interval ?: 1)
'Realtime='      . $realtime
```
Worked example — `sync_interval_min = 0` → `TransInterval=1`, `Realtime=1` (push each
punch). `= 30` → `TransInterval=30`, `Realtime=0`. Note `TransInterval` is expressed in
**minutes** by assumption; nothing validates the device's unit.

### 4.6 Single-device alternating direction — `ETimeOfficeService.php:653-664`
```php
$rank = 0; $anchor = null; $dir = 'in';
foreach ($times as $ts) {
    $t = strtotime($ts);
    if ($anchor === null || ($t - $anchor) > self::SINGLE_REPEAT_SEC) {   // 60
        $rank++;
        $dir = $rank % 2 === 1 ? 'in' : 'out';
        $anchor = $t;
    }
    $out[self::seqKey($emp, $ts)] = $dir;
}
```
`$times` = the union of the incoming batch's timestamps **and every already-stored punch
for that `emp_code` + `log_date` + `source`** (`:631-651`), sorted as strings
(`Y-m-d H:i:s` sorts chronologically).

Worked example — punches 09:00:00, 09:00:20, 13:00:00, 18:00:00:
- 09:00:00 → anchor null → rank 1 → `in`, anchor = 09:00:00
- 09:00:20 → 20 s ≤ 60 → keep `in`, rank still 1
- 13:00:00 → 14 380 s > 60 → rank 2 → `out`
- 18:00:00 → rank 3 → `in`

Result `in, in, out, in`. Pinned by `DeviceModeTest.php:159-172`. No rounding; integer
seconds from `strtotime`.

### 4.7 `seqKey` — `:587-590`
`tenant_id . '|' . strtolower(emp_code) . '|' . 'Y-m-d H:i:s'`
A NULL tenant contributes an empty string, so on a single-tenant install the key is
`|emp001|2026-08-28 09:00:00`.

### 4.8 SmartEPT derived idempotency key — `SmarteptWebhook.php:282-287`
```php
'ept_'.hash('sha256', implode('|', [
    self::SOURCE,          // 'smartept'
    $companyKey,           // SmartEPT's own company_id, salt only
    mb_strtolower($identity),
    strtoupper($direction),
    $at,                   // naive local 'Y-m-d H:i:s'
]));
```
Worked example — company `7`, identity `EMP1043`, `IN`, `2026-08-26 09:41:00` →
`ept_` + sha256(`smartept|7|emp1043|IN|2026-08-26 09:41:00`).
Deliberately excludes `sent_at`/`generated_at`, so a retry reproduces the id.

### 4.9 Timezone conversion, SmartEPT — `:300-317`
```php
if (!preg_match('~^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}~', $v)) return null;
$d = new \DateTimeImmutable($v);
return $d->setTimezone(new \DateTimeZone(config('app.timezone')))->format('Y-m-d H:i:s');
```
Worked example, `app.timezone = Asia/Kolkata`: `2026-08-26T04:11:00Z` → `2026-08-26 09:41:00`.
Pinned by `SmarteptWebhookTest.php:160-176`.

### 4.10 Naive-local parse, SBB — `PunchIngestService.php:308-331`
```php
if (preg_match('~(?:[Zz]|[+\-]\d{2}:?\d{2}|\s[A-Za-z]{2,5})$~', $v)) return null;   // reject
if (!preg_match('~^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$~', $v))       return null;   // reject
$d = Carbon::createFromFormat('Y-m-d H:i:s', $v, config('app.timezone'));
return $d->format('Y-m-d H:i:s') === $v ? $d : null;                                  // round-trip guard
```
The round-trip guard is what rejects `2026-02-30 09:41:00` (Carbon would roll it to 03-02).

### 4.11 API key format and hash — `ApiKeys.php:32-47`
```
secret = 'sk_prs' . '_' . lower(Str::random(4)) . '_' . Str::random(40)
hash   = hash('sha256', secret)
prefix = substr(secret, 0, strrpos(secret, '_'))          // 'sk_prs_ab12', ≤12 chars
```
Entropy: 40 chars from Laravel's alphanumeric alphabet (62) ≈ **238 bits**. The 4-char
prefix space is 36⁴ = 1 679 616 with 8 collision attempts (`:66-77`).

### 4.12 SmartEPT webhook secret and signature
`secret = 'whk_ept_' . Str::random(48)` (`:93`) ≈ 285 bits.
`signatureMatches`: `hash_equals(hash_hmac('sha256', $rawBody, $secret), trim($presented))`
(`:129`) — hex lower-case, over the **raw** body bytes, constant-time.
Slug: `Str::lower(Str::random(16))` (`:324`) — note `Str::random` is mixed-case
alphanumeric and `Str::lower` collapses it, so the effective alphabet is 36, giving
36¹⁶ ≈ 82 bits.

### 4.13 ADMS status → direction — `PushController.php:159`
`in_array($status, ['1','2','5'], true) ? 'out' : 'in'`
Worked example: `0`→in, `1`→out, `2`→out, `3`→in, `4`→in, `5`→out, `''`→in, `9`→in.

### 4.14 Employee identity assembly
- /iclock & pull: `$full = trim(emp_prefix) . device_code` (`ETimeOfficeService.php:691, 705`).
  Example — prefix `AME`, PIN `1043` → `AME1043`. Pinned by `IclockTenantHotfixTest.php:128-136`.
- SBB: `$full = employee_code !== '' ? employee_code : prefixFor(device_sn) . device_user_id`
  (`PunchIngestService.php:104-107`). `prefixFor` reads `biometric_configs.emp_prefix`
  for `(serial_number = device_sn AND tenant_id = tenant)`, newest id first (`:377-397`).
- SmartEPT: `identity = employee_code ?: biometric_employee_id`; `device_user_id =
  biometric_employee_id ?: identity`; `device_sn = device_id ?: 'SMARTEPT'`
  (`SmarteptWebhook.php:154-183`).

---

## 5. Configuration surface today

### 5.1 (a) What a customer CAN change without code

| Setting | Where | Notes |
|---|---|---|
| Add/edit/delete any number of device connections | Biometric Device Setup (SPA) | `BiometricConfigController::list/save/delete` |
| Device label | same | free text, 120 chars |
| Branch a device belongs to | same | **a name string chosen from the Branches master**, not an FK |
| Provider | same | one of exactly four values |
| Enable/disable a device | same | `enabled` bool; also gates the scheduler |
| eTimeOffice: base URL, endpoint path, corp id, username, password, `Empcode` filter | same | base URL and endpoint are free text — no scheme or host validation |
| eTimeTrackLite: WebAPI URL, device serial, WebAPI username/password | same | URL normalised, **defaults to `http://`** |
| Generic: HTTP method, URL, Basic-auth toggle, API key value, arbitrary headers, body type + body template, request date format, token-login URL/body/JSON path, response type, record path, record/field delimiters, six field mappings, punch date format, IN/OUT token lists | same, plus 5 presets | the richest surface in the product; stored as JSON in `api_config` |
| Employee ID prefix per device | same | prepended to the device code |
| Device setup: 1 reader vs 2 readers | same | `device_mode` |
| IN Machine ID / OUT Machine ID | same | free text, compared case-insensitively |
| Sync frequency | same | **fixed dropdown**: push → Real-time/5/10/15/30/60; pull → 5/10/15/30/60 |
| Test a connection and see the raw vendor response | same | 1-day window, no writes |
| Force an immediate sync of one device | same | 1–31 day window via API, UI always sends 1 |
| Map a device ID to an employee (with force-move) | Employee Mapping card / Pending Punches | |
| Unmap an employee's device ID | Employee Mapping card | |
| Claim an unassigned auto-registered device (optionally to a company) | **Pending Punches Blade page only** | company is validated then discarded (no column) |
| Create / revoke an API key, choose scopes, set an expiry date | API Keys screen | |
| Create / rotate / revoke a SmartEPT receiver, choose which events it accepts | API Keys screen | |
| Approve / reject / revoke a mobile device | `/app/mobile-devices/*` JSON endpoints | |
| eTimeOffice `.env` fallback connection | `ETIMEOFFICE_*` in `.env` | `config/smartprs.php:114-122` |
| ZKTeco default port | `ZKTECO_DEFAULT_PORT` | `config/smartprs.php:102` |

### 5.2 (b) What a customer CANNOT change without code — the SmartPRS 3 backlog

1. **Add a new device vendor with its own protocol.** Only four providers exist and each is
   an `if/elseif` chain repeated in five places (`BiometricConfigController::test :409-450`
   and `::sync :480-509`, `ETimeOfficeService::configuredAny :126-137`,
   `SyncETimeOffice::handle :76-96` and `:115-119`). Anything that is not HTTP — a vendor
   SDK, an SFTP drop, a serial reader, MQTT, a database link — is impossible.
2. **Turn off unknown-serial auto-registration**, or require an admin to pre-approve a
   serial before its punches are accepted (`PushController.php:209-224`).
3. **Bind a push device to a tenant at the protocol level.** There is no device credential,
   no shared key, no TLS client cert; the SN alone is the identity.
4. **Change the ADMS option block** — `TransTimes`, `TransFlag`, `Delay`, `ErrorDelay`,
   `Stamp`, `Encrypt` are literals (`PushController.php:89-100`).
5. **Send any command to a device** — enrol/delete a user, sync the clock, reboot, fetch
   biometric templates. `getrequest` always answers `OK` (`:57-62`).
6. **Change the ZKTeco status-byte → direction map**, or preserve break-in/break-out/OT-in/
   OT-out as distinct punch types (`:159`). SmartPRS's `direction` vocabulary is
   `in|out|unknown` only.
7. **Change the "unknown direction defaults to IN" rule** on any pull path.
8. **Change the 60-second double-tap window** (`SINGLE_REPEAT_SEC`, `:578`).
9. **Choose a sync frequency outside 5/10/15/30/60 minutes**, or set a per-device sync
   *window* (e.g. only 06:00–22:00), or a different default from 60.
10. **Retry a failed pull.** Zero `->retry()` calls in the codebase; a transient vendor
    outage loses that tick's window entirely and the next tick uses a fresh
    `now() - days` window, so nothing back-fills the gap beyond the 1-day overlap.
11. **Change any connector timeout** from 60 s.
12. **Use device_mode / alternating direction on the SBB, SmartEPT, LAN or human paths** —
    only `ETimeOfficeService::import()` honours it.
13. **Express "PIN 5 on device A is Ravi, PIN 5 on device B is Sunil".** The mapping lives
    on `employees.device_user_id`, a single global column
    (`BiometricConfigController.php:619-633`). The *promotion* of held punches is
    device-scoped (`promotePending`'s `$deviceSn`), but the mapping that governs live
    matching is not.
14. **Change employee matching precedence** (column order beats candidate order — DEF-C-01),
    or add a matching strategy (e.g. card number, national ID, per-device code table).
15. **Have a per-device or per-branch employee-code prefix rule richer than a plain
    prefix** — no suffix, no zero-padding, no regex, no lookup table.
16. **Add a punch source vocabulary.** `source` values are literals scattered across the
    code (`'push'`, `'sbb'`, `'smartept'`, `'etimeoffice'`, `'etimetracklite'`,
    `'generic'`, `'device'`).
17. **Rotate an API key** without a revoke-and-reissue outage (`NEW-C-15`).
18. **Use the `read` scope for anything**, or define a new scope.
19. **Change the 1000-punch batch cap or the 2 MiB webhook body cap.**
20. **Change the per-IP throttles** (1000/min for `/iclock`, 300/min for `/api/v1`).
21. **Require HTTPS for credential-bearing endpoints.** The UI warns
    (`api-keys.blade.php:104-110`) but nothing enforces.
22. **Get an alert when a device stops reporting.** No staleness monitor, no
    "device offline" notification, no dashboard — see §7 below.
23. **Add a webhook sender other than SmartEPT.** The event names, header names, payload
    shape and translation are all hard-coded in one class.
24. **Send a push notification to a mobile device.** Tokens are collected and never used.
25. **Enforce the mobile device gate.** The token is never verified server-side.
26. **Add pagination, cursoring, OAuth2 refresh or rate-limit backoff to the generic
    connector.** The recipe grammar has no vocabulary for them.
27. **Restrict which hosts a connector may call** (SSRF allow-list).
28. **Preserve intra-day punches on the LAN ZKTeco path** (first-in/last-out only).
29. **Audit a mapping change or a backfill.** `mapEmployee` writes no audit row; the
    backfill rewrites historic `attendance_logs` with only a flash message as evidence.

---

## 6. Defects, conflicts and technical debt

| ID | What it is | Evidence | Business impact | Severity |
|---|---|---|---|---|
| DEF-C-01 | **Employee matching tries the whole `emp_code` column before it ever looks at `device_user_id`.** The loop is `foreach (['emp_code','device_user_id']) { foreach ($codes) }`, so a raw device PIN that coincides with *another* employee's `emp_code` wins over the correct, explicitly configured `device_user_id` mapping. | `ETimeOfficeService.php:315-345` | A punch is filed against the wrong person, with no error, and flows into that person's attendance and payroll. Highly likely wherever `emp_code` values are short numerics (`5`, `12`, `101`). | **CRITICAL** |
| DEF-C-02 | **A push device is identified only by its serial, and the serial lookup is not tenant-scoped and not unique.** `rowForSn()` selects `provider='push' AND serial_number=?` ordered by id desc, across all tenants. Two tenants can register the same serial; the newest row wins. | `PushController.php:207` | Punch mis-routing between customers; deliberate hijack by registering a competitor's serial. | **CRITICAL** |
| DEF-C-03 | **`ETimeOfficeService::import()` calls `LateArrivalService::notifyTouched()` inline**, which ends at `Mail::raw` inside the HTTP request. A slow SMTP host blocks the `/iclock` response until the device times out — and a device that times out re-uploads. | `ETimeOfficeService.php:777`; `LateArrivalService.php:56-74, 289-294` | Attendance ingest stalls on mail; retries manufacture duplicates. The SBB path fixed this by queueing (`PunchIngestService.php:203-209`); the device path did not. | **HIGH** |
| DEF-C-04 | **Silent timezone corruption on four of six machine paths.** `Carbon::parse()` honours an offset in the string, then `->format('Y-m-d H:i:s')` renders it in that offset and discards the offset. A punch arriving as `2026-08-16 09:41:00+00:00` on an Indian install is stored as `09:41:00` when the person actually clocked in at `15:11`. | `PushController.php:177-181`; `ETimeOfficeService.php:217-221`; `GenericApiService.php:452-456`; `DeviceController.php:148-149` | 5½-hour errors straight into payroll, silently. The SBB path exists specifically to avoid this and rejects offsets (`PunchIngestService.php:312-314`) — the older paths were never fixed. | **HIGH** |
| DEF-C-05 | **The LAN ZKTeco path silently discards an unmatched punch.** `if (! $employee) { continue; }` — no quarantine, no counter, no log. | `DeviceController.php:162-166` | The one path that still destroys attendance, contradicting the product promise stated on the Pending Punches screen. | **HIGH** |
| DEF-C-06 | **The LAN path puts `direction` IN the `updateOrInsert` match key**, unlike every other path (rev173g deliberately removed it elsewhere). | `DeviceController.php:193` vs `ETimeOfficeService.php:745-752` | Only one IN and one OUT can ever exist per employee-day on this path; a corrected direction inserts a second row instead of correcting in place. | **MEDIUM** |
| DEF-C-07 | **`PunchIngestService::replayPending()` is dead code** — 78 lines with no caller anywhere in the repo. Three docblocks (`ApiKeyController.php:33`, `2026_08_16_000300…:17`, `ETimeOfficeService.php:503`) name it as the live mechanism. The live mechanism is `ETimeOfficeService::promotePending()`. | `PunchIngestService.php:220-297`; grep confirms zero callers | Anyone reading the code or the docs will maintain the wrong function. The two implementations have already diverged: `replayPending` writes `direction ?: 'unknown'`, `promotePending` writes `?: 'in'`. | **MEDIUM** |
| DEF-C-08 | **`biometric_configs.company_id` does not exist**, so `assign()`'s company selection is validated against `companies` and then dropped by the `Schema::hasColumn` guard. The Blade form presents the dropdown as if it works. | `BiometricConfigController.php:231-239`; no migration or `ensure*()` creates the column | The admin believes they assigned the device to a company; nothing happened. | **MEDIUM** |
| DEF-C-09 | **Three tables in this area are created at RUNTIME with no migration**: `biometric_unmapped` (`ETimeOfficeService.php:361-371`), `mobile_devices` (`MobileDeviceController.php:36-55`), and the extra `biometric_configs` columns (`BiometricConfigController.php:66-86` / `PushController.php:266-284`). Two of the three are reachable by an **unauthenticated** request. `attendance_logs` has a **fourth** creation shape in `DeviceController::ensureLogsTable()` (`:210-227`) that omits every provenance column and both unique indexes. | as cited | Schema drift between installs; `migrate:fresh` produces a different database from an upgraded one; DDL executed inside a web request. | **HIGH** |
| DEF-C-10 | **The two `BiometricConfigController::ensureTable()` / `PushController::ensure()` definitions disagree**: only the former adds `api_config`. | `BiometricConfigController.php:73` vs `PushController.php:266-274` | On an install where a device dialled in before an admin opened the setup screen, `api_config` is missing and the generic connector cannot be configured until the screen self-heals it. | **MEDIUM** |
| DEF-C-11 | **The SPA never renders unassigned devices.** `BiometricConfigController::list()` returns an `unassigned` array (`:176-193`) and the SPA screen reads only `d.devices` (`AppController.php:9749`). `grep` for `unassigned` in `AppController.php` returns nothing. | as cited | The 18-Aug hotfix's headline fix is invisible in the interface users actually use; an admin only finds it on the legacy Blade *Pending Punches* page. | **HIGH** |
| DEF-C-12 | **`DeviceController::index/create/store/edit/update/destroy/sync` are unreachable**, and the two views they return (`devices.index`, `devices.form`) **do not exist**. Only `syncById` is routed. | `DeviceController.php:17-95`; `routes/web.php:518`; `resources/views/` has no `devices/` directory | Dead code that would fatal; there is no UI to register a LAN device at all, so `syncById` can only act on rows created outside the product. | **MEDIUM** |
| DEF-C-13 | **An invalid `api_config` JSON is silently converted to NULL** on save, with `ok:true` returned. | `BiometricConfigController.php:32-42, 314, 339` | An admin who mistypes the recipe is told the device saved, and then the connector silently does nothing. | **MEDIUM** |
| DEF-C-14 | **A failed pull leaves `last_status`/`last_sync_at` showing the last success.** `SyncETimeOffice` prints the error and `continue`s without stamping anything. | `SyncETimeOffice.php:83-87` vs `:126-132` | The Biometric Device Setup screen displays a green "last sync" for a device that has been failing for days. | **HIGH** |
| DEF-C-15 | **`ETimeTrackLiteService::rowFromFields()` identifies the employee code as "the first non-datetime cell containing a digit".** | `ETimeTrackLiteService.php:233-244` | Any vendor build that puts a machine number, a record id or a verify-mode digit before the employee code files every punch against the wrong employee, with no error. | **HIGH** |
| DEF-C-16 | **`ETimeTrackLiteService::extractData()` regex-scrapes SOAP XML** rather than parsing it, and on failure returns the entire raw body, which then goes through the tolerant field sniffer. | `:130-149, 157-182` | Garbage-in/garbage-through: an error page or an HTML login form can be "parsed" into punch rows. | **MEDIUM** |
| DEF-C-17 | **A missing `punch_at` is impossible to distinguish from a valid one in the `updateOrInsert` match key on the pull paths**, because a parse failure drops the line before it reaches `import()` with no counter and no log. | `ETimeOfficeService.php:281-284`; `PushController.php:121-124` | Punches silently vanish between the device and `attendance_logs` with no number anywhere reporting how many. `import()`'s `imported` count only counts what got that far. | **MEDIUM** |
| DEF-C-18 | **`import()`'s `imported` counter counts updates as imports.** `updateOrInsert` gives no insert/update distinction, and `$imported++` runs unconditionally. | `ETimeOfficeService.php:756-768` | "Imported 420 punch(es)" on a re-sync of an unchanged window is meaningless; the operator cannot tell a working feed from a stuck one. | **LOW** |
| DEF-C-19 | **`device_code` is never written by the SBB/SmartEPT quarantine path**, although the mapping card and `promotePending()` both work primarily in `device_code`. Recovery only works because `promotePending` also ORs on `device_user_id` (`:476-479`) and the Blade falls back (`pending-punches.blade.php:95`). | `PunchIngestService.php:117-130` | Fragile; the `attpending_unique (tenant_id, device_code, punch_at, source)` index is therefore inert for SBB rows (NULL never collides), so SBB relies solely on `(tenant_id, external_id)`. | **MEDIUM** |
| DEF-C-20 | **Mobile push tokens are collected and never used.** No FCM/APNs client, no send call, anywhere. | grep for `fcm|firebase|push_token` in `app/` returns only `MobileDeviceController` | A shipped, documented feature that does nothing. | **MEDIUM** |
| DEF-C-21 | **`ZKTECO_SYNC_ENABLED` is a documented kill-switch that is never read.** | `config/smartprs.php:103`; no reader in `app/` | Operators believe they have disabled LAN sync. | **LOW** |
| DEF-C-22 | **Every `catch` in `DeviceController::sync/syncById` discards the exception** (`catch (\Throwable $e)` with `$e` unused, no `report($e)`). | `DeviceController.php:90-94, 127-131` | "Device unreachable" is returned for a library bug, a DNS failure and a genuine offline device alike. | **LOW** |
| CONF-C-01 | **Two unrelated features share the table name `attendance_pending` with disjoint schemas** (device quarantine vs bulk-upload staging). Whichever creates it first wins. | migrations `2026_08_16_000300`, `2026_08_17_000100`, `2026_08_18_000100` vs the bulk shape recorded at doc line 5364-5379 | On an install where the bulk feature created the table first, every device quarantine insert fails and the "punches are never lost" promise is void. | **CRITICAL** |
| CONF-C-02 | **`device_mode` is honoured by exactly one of six machine paths.** SBB, SmartEPT, LAN ZKTeco, in-app and bulk all ignore it. | `ETimeOfficeService.php:710-712` is the only reader | A single-reader site that moves from `/iclock` to SBB silently loses alternating direction and every punch becomes `IN`. | **HIGH** |
| CONF-C-03 | **`in_machine_id`/`out_machine_id` mean different things on different paths.** On `/iclock` they are compared against the **device serial** (`PushController.php:152-157`); on the pull paths they are compared against a **machine-number field inside the record** (`ETimeOfficeService.php:287-290`, `GenericApiService.php:335-338`, `ETimeTrackLiteService.php:254-263`). One field, two semantics, one UI label ("IN Machine ID"). | as cited | An admin who fills it in correctly for one provider has it wrong for another. | **MEDIUM** |
| CONF-C-04 | **`biometric_configs.branch` is a name string, `branches` is the master.** No FK, no rename propagation, and `list()` silently tolerates the `branches` table not existing at all (`:156-167`). | `BiometricConfigController.php:307` | Renaming a branch orphans every device's branch label with no error. | **MEDIUM** |
| DEBT-C-01 | The provider dispatch is duplicated in **five** places with no shared abstraction (see §7-answer 1). Adding a fifth provider means editing all five. | listed in §7-answer 1 | Every new vendor is a multi-file code change — the exact opposite of "change configuration, not code". | **HIGH** |
| DEBT-C-02 | `BiometricConfigController` is a 721-line controller holding schema DDL, credential encryption, provider dispatch, mapping business rules and quarantine replay. | whole file | Untestable; the mapping rule is invoked by `ApiKeyController` calling a controller method directly and reading its JSON response (`ApiKeyController.php:468, 486-487`). | **MEDIUM** |
| DEBT-C-03 | `sbb-verify.php` is a bootstrap-loading script sitting in the **web-servable project root** next to `public/`. It prints database host, database name, row counts and index state. | `sbb-verify.php:33-46, 116-133` | If the document root is ever the project root rather than `public/`, this is an unauthenticated information disclosure. | **MEDIUM** |
| DEBT-C-04 | The `SESSION-REPORT-2026-08-16-SBB.md` and `sbb-schema-status.txt` artefacts are committed into the application source tree; the latter contains the dev database host, port and name. | `sbb-schema-status.txt:85-89` | Same exposure class as DEBT-C-03. | **LOW** |

---

## 7. Security findings

| ID | What it is | Evidence | Exploitability | Business impact | Severity |
|---|---|---|---|---|---|
| SEC-C-01 | **Unauthenticated punch injection.** `POST /iclock/cdata?SN=<known serial>&table=ATTLOG` with a tab-delimited body writes punches into a real tenant's `attendance_logs`. No auth, no CSRF, no signature, no device secret. | `routes/web.php:92-94`; `PushController.php:38-54, 106-140` | Trivial for anyone who learns a serial (printed on the device, visible in vendor software, guessable within an OEM range). Rate limit is 1000/min/IP. | Fabricated attendance → fabricated pay. Also enables *erasing* absence by inventing punches. | **CRITICAL** |
| SEC-C-02 | **Unauthenticated row creation / storage exhaustion.** Any GET to `/iclock/cdata?SN=x` or `/iclock/getrequest?SN=x` with an unseen `SN` INSERTs a `biometric_configs` row. | `PushController.php:41-43, 57-62, 209-224, 230-242` | Trivial; 1000 rows/min/IP. | Unbounded table growth; the admin's *Unassigned devices* list is flooded and the real device is lost in it. | **HIGH** |
| SEC-C-03 | **Cross-tenant device hijack by serial.** `rowForSn()` has no tenant filter and `serial_number` has no unique index. A tenant that registers another customer's serial as a `push` device with a higher id captures that device's punches. | `PushController.php:207`; no unique index in any migration | Requires a tenant account on a shared install plus knowledge of a serial. | One customer reads and absorbs another customer's attendance. | **CRITICAL** (SaaS) |
| SEC-C-04 | **SSRF, by design, on two connectors.** `GenericApiService` issues GET/POST to a fully admin-supplied `url` and `token_url` and the response body is returned verbatim to the browser by the Test button. `ETimeOfficeService`'s `base_url` is equally unvalidated. No host allow-list, no scheme check, no private-range block. | `GenericApiService.php:70, 94, 146`; `BiometricConfigController.php:424-425, 444-445`; `ETimeOfficeService.php:152-154` | Any `admin`/`hr_manager` — on SaaS, any tenant admin. | Read cloud metadata (`169.254.169.254`), internal admin panels, localhost services; port-scan the internal network via response timing. | **HIGH** |
| SEC-C-05 | **Stored device credentials can be interpolated into an attacker-chosen URL.** `{pass}`, `{user}`, `{serial}` substitute into `url`, `headers` and `body`. | `GenericApiService.php:186-188, 94, 126, 206` | Any admin who can save a config. | Self-exfiltration is the limit (only that row's own credentials), but an admin can also unwittingly leak credentials into a query string logged by every intermediary. | **MEDIUM** |
| SEC-C-06 | **Device credentials sent in cleartext by default.** `ETimeTrackLiteService::endpointUrl()` prepends `http://` when no scheme is typed, and the WebAPI username and password are elements *inside* the SOAP body. eTimeOffice's `base_url` accepts `http://` with no warning. | `ETimeTrackLiteService.php:46-48, 106-122`; `ETimeOfficeService.php:152` | Passive network observer on the customer LAN. | Device credentials disclosed; with them, the full movement log of every employee. | **HIGH** |
| SEC-C-07 | **`ApprovalService::denyUnlessRole()` fails OPEN.** Any `\Throwable` while resolving roles returns `null`, i.e. "allowed". `ApiKeyController::authorizeScreen()` has the same posture and even documents it. Every biometric-config endpoint (save, delete, test, sync, map, unmap, assign) is guarded by this. | `ApprovalService.php:377-379`; `ApiKeyController.php:509-521` (`:516-518` `return;` on throw) | A misconfigured or partially-migrated permission table turns every guarded endpoint into an open endpoint for any authenticated user. | Any logged-in employee could then read/modify device credentials and mint API keys. | **HIGH** |
| SEC-C-08 | **The mobile device gate is decorative.** `mobile_devices.token` is minted on approval and returned to the app, but **no middleware, controller or route ever reads `mobile_devices`**. | `MobileDeviceController.php:228`; `grep -rn "mobile_devices" app/` returns only this file | Any client that ignores the app's own check has full access. | The stated anti-fraud control ("revoke a lost phone by rejecting it") does not exist server-side. | **HIGH** |
| SEC-C-09 | **`POST /api/mobile/status` discloses a device's gate token to anyone who knows its `device_id`, unauthenticated**, and when the host resolves no tenant it falls back to "the most recent row for this id" across **all** tenants. | `MobileDeviceController.php:141-170` (`:151-156`) | Unauthenticated, 120 req/min. `device_id` is an app-generated install id, so guessing is hard — but it is also echoed back in `register()` and logged by proxies. | Token disclosure + cross-tenant device-status leak. | **MEDIUM** |
| SEC-C-10 | **`POST /api/mobile/push-token` updates by `device_id` with no tenant scoping and no ownership proof**, unauthenticated. | `MobileDeviceController.php:182-183` | Unauthenticated. | An attacker who learns a `device_id` redirects that device's future push notifications to their own token. (Currently harmless only because nothing sends pushes — DEF-C-20.) | **MEDIUM** |
| SEC-C-11 | **Any admin/HR user can sync any device, including another tenant's.** `syncById()` does `Device::find($id)` with no tenant or company filter. `devices` has no `tenant_id` at all. | `DeviceController.php:103-104`; `Device.php:12-14` | Authenticated admin/HR, IDOR on the id. | Triggers an outbound TCP connection to an arbitrary stored IP:port — an internal port-scan primitive — and imports another tenant's device log. | **MEDIUM** |
| SEC-C-12 | **The mapping backfill can claim NULL-tenant punch rows.** `WHERE LOWER(emp_code)=? AND (tenant_id = ? OR tenant_id IS NULL)`. | `BiometricConfigController.php:642-652` | Any admin mapping a device ID. | Tenant A's admin re-keys punches written by an unassigned device that actually belongs to tenant B, and stamps them with tenant A's ids. | **MEDIUM** |
| SEC-C-13 | **Decrypt failure silently accepts the stored value as plaintext**, in two places. | `ETimeOfficeService.php:49-55`; `BiometricConfigController.php:363-369` | n/a — availability-over-integrity choice. | A plaintext password written directly to the DB (by a migration, a fix script, a support engineer) is used and never surfaced. Masks the very condition it should alert on. | **MEDIUM** |
| SEC-C-14 | **The Test button echoes the raw vendor response (1500 chars) to the browser**, and a generic token-login failure returns the token endpoint's raw body. | `BiometricConfigController.php:423-425, 444-445`; `GenericApiService.php:80-82` | Admin-only, but combines with SEC-C-04 to make the SSRF *readable*. | Turns blind SSRF into full SSRF. | **HIGH** (in combination) |
| SEC-C-15 | **No transport enforcement for credentials.** The API Keys screen computes a `secure` flag and prints a warning (`api-keys.blade.php:104-110`) but nothing refuses to mint or accept a credential over HTTP. | `ApiKeyController.php:112`; no enforcement anywhere | Any install left on `http://`. | API keys and webhook secrets travel in clear. | **MEDIUM** |
| SEC-C-16 | **No replay protection on the SmartEPT webhook.** The signature covers the body only; there is no timestamp header, no nonce, no freshness window. | `SmarteptWebhook.php:122-130`; `SmarteptSignature.php:33-87` | An observer of one signed request (over HTTP, or from a log) can replay it forever. | Mitigated in effect by the deterministic `external_id` + `attlog_natural_unique` making a replay a no-op — but only while `tenant_id` is non-NULL, which the middleware does enforce (`:72-78`). A replayed *daily* payload for a different date is still fully accepted. | **MEDIUM** |
| SEC-C-17 | **`sbb-verify.php` in the project root** prints DB host/name and row counts if ever served. | `sbb-verify.php:33-46` | Depends on document-root configuration. | Infrastructure disclosure. | **MEDIUM** |
| SEC-C-18 | **Sensitive identifiers in logs.** `sbb.ingest.batch` logs the key prefix, tenant and device serials; `smartept.webhook.denied` logs a slug fragment and IP; `biometric.device.assigned` logs the acting user's email. | `PunchIngestService.php:183-193`; `SmarteptSignature.php:57-63`; `BiometricConfigController.php:250-255` | Log-file access. | Correlation material; no secrets are logged (positive finding: neither the API key nor the webhook secret ever reaches a log). | **LOW** |
| SEC-C-19 | **XML parsing without explicit entity hardening.** `DOMDocument::loadXML($raw)` with only `libxml_use_internal_errors(true)`. | `GenericApiService.php:263-269` | Requires a hostile response from a connector the admin configured. | PHP 8 / libxml ≥ 2.9 default to entity substitution off and external DTD loading off, so classic XXE is not enabled — but nothing in the code says so, and a libxml downgrade would re-open it. | **LOW** |

### Positive security findings worth preserving

- The tenant of an SBB or SmartEPT punch comes **only** from the credential; the body is
  never consulted (`ApiKeyAuth.php:71-76`, `SmarteptSignature.php:80-85`), pinned by
  `SbbIngestTest.php:292-308` and `SmarteptWebhookTest.php:142-154`.
- API key comparison is `hash_equals` over sha256 of the whole secret; the prefix is
  explicitly only a lookup handle (`ApiKeyAuth.php:98-114`).
- Webhook verification uses the **raw** body, constant-time, and answers identically for
  unknown slug / bad signature / undecryptable secret, checking `active` only *after*
  verification so the timing matches (`SmarteptSignature.php:46-70`).
- A tenant-less credential is refused at both creation and request time
  (`ApiKeyController.php:267-274`; `ApiKeyAuth.php:67-69`; `SmarteptSignature.php:72-78`).
- The SOAP envelope escapes every interpolated value with
  `htmlspecialchars(ENT_QUOTES|ENT_XML1)` (`ETimeTrackLiteService.php:108`).
- TLS verification is never disabled anywhere (`withoutVerifying` has zero occurrences).

---

## 8. Documentation-vs-source discrepancies

1. **`PROJECT_ARCHITECTURE_AND_WORKFLOWS.md:988-992` states the /iclock cross-tenant
   matching defect is "unfixed on that path" and cites `ETimeOfficeService.php:321`.**
   **Source disagrees.** The 18 Aug 2026 hotfix made the filter symmetric at
   `ETimeOfficeService.php:338` (`$tid ? $q->where('tenant_id',$tid) : $q->whereNull('tenant_id')`),
   pinned by `IclockTenantHotfixTest.php:69-107`. The same architecture doc's own §10.2 and
   `COMPLETE_…:5482-5492` correctly record it as fixed. **The two reference documents
   contradict each other**; the source is authoritative — it is fixed. What remains true,
   and is the residual risk, is DEF-C-02/SEC-C-03: the *device row* lookup is still not
   tenant-scoped.
2. **`ApiKeyAuth.php:20-27`, `PunchIngestService.php:28-30` and `SmarteptSignature.php:23-25`
   all still describe the /iclock tenant defect in the present tense**, citing
   `ETimeOfficeService.php:321` — a line number that no longer holds that code. Stale
   in-source documentation.
3. **`ApiKeyController.php:33`, `2026_08_16_000300_create_attendance_pending_table.php:17`
   and `ETimeOfficeService.php:503` all name `PunchIngestService::replayPending` as the
   live replay mechanism.** It has no callers (DEF-C-07). The live mechanism is
   `ETimeOfficeService::promotePending`. The reference doc catches this correctly
   (`DEBT-ATT-14`, doc line ~7080).
4. **`SESSION-REPORT-2026-08-16-SBB.md:6, 301-308, 520` state that no migrations were run,
   no tests were executed and no commits were made.** `sbb-schema-status.txt:62-66`
   shows all five SBB/repair migrations recorded as Ran (batches 11–13) on the dev
   database as of 22 Aug 2026, with both unique indexes present (`:110-111`). The session
   report is a point-in-time artefact that is now misleading if read as current state.
   **It has never been updated and remains in the source tree.**
5. **The task brief and `COMPLETE_…` reference `resources/views/devices/form.blade.php`
   and `devices/index.blade.php`.** Neither exists; `resources/views/` has no `devices/`
   directory at all. The reference doc does correctly note the routes are unregistered
   (doc line ~5987) but does not note the views are missing.
6. **`COMPLETE_…:5346` lists `biometric_configs` columns including `sync_interval_min`,
   `serial_number` and `api_config` as if migrated.** They are runtime-only (§2.1).
   The doc does mention runtime creation in the same row but does not say **which
   columns** have no migration — that distinction matters for `migrate:fresh`.
7. **`COMPLETE_…` §19a §1.5 states the `rats/zkteco` guard is "defensive dead code in a
   correct install" because the dependency is in `composer.json`.** Confirmed —
   `composer.json:16` declares `"rats/zkteco": "^002.0"`. **This contradicts
   `COMPLETE_…:5992-5994`** ("grep of composer.json shows it is not a declared
   dependency"). Source wins: it **is** declared. The two doc sections disagree with each
   other.
8. **No reference document records that the SPA never renders `unassigned` devices**
   (DEF-C-11). Both docs describe the claiming workflow as if it were reachable from
   Biometric Device Setup; it is only on the Blade Pending Punches page.
9. **No reference document records that `biometric_configs.company_id` does not exist**
   (DEF-C-08). `COMPLETE_…` §10.5 describes `assign()` accepting an optional `company_id`
   without noting it is discarded.
10. **No reference document records DEF-C-01** (the `emp_code`-column-before-
    `device_user_id`-column matching order). `COMPLETE_…` BR-DEV-003 mentions it in an
    "Edge cases" line but does not classify it as a defect; it is the single highest-value
    correctness bug in this area.
11. **No reference document records CONF-C-03** (`in_machine_id` meaning device serial on
    `/iclock` and record machine-number on the pull paths).
12. **`COMPLETE_…` §19a §1.1 row 5 names the SmartEPT credential table `smartept_webhooks`.**
    The actual table is `smartept_webhook_endpoints`
    (`2026_08_26_000100_create_smartept_webhook_endpoints_table.php:29`).

---

## 9. Migration verdict per capability

| Capability | Verdict | Rationale | Data migration implication |
|---|---|---|---|
| Device connection registry (`biometric_configs`) | **REDESIGN** | The concept is right and the customer value is proven, but the table is half runtime-created, has no `company_id`, stores Branch as a name, and mixes four providers' fields into one flat row. | Migrate `biometric_configs` → a `devices` entity + a typed `device_connections` child keyed by adapter. Preserve `id` for traceability; map `branch` name → branch FK by lookup, reporting unmatched names. |
| `/iclock` ADMS receiver | **RETAIN protocol, REDESIGN trust model** | Real customer hardware speaks only this; it cannot be dropped. But unauthenticated, tenant-less, serial-only identity is not viable for SaaS. | Introduce a per-device enrolment secret / pre-registration requirement, with a migration window in which unknown serials are quarantined rather than auto-enabled. Existing auto-registered NULL-tenant rows must be force-claimed before cut-over. |
| SBB JSON ingest (`/api/v1/attendance/punches`) | **RETAIN** | This is the reference implementation: tenant from credential, per-punch verdicts, DB-enforced idempotency, strict time. Everything else should converge on it. | None. Keep `external_id` semantics and both unique indexes. |
| SmartEPT webhook receiver | **MERGE** into a general signed-webhook ingest | The signature, replay-safety-by-derived-id and tenant-from-endpoint design are correct; the *SmartEPT-specific* event names and field translation should become a configured adapter, not a class. | Existing `smartept_webhook_endpoints` rows migrate to generic `webhook_endpoints` with `adapter='smartept'`. Secrets re-encrypt under the new key management. |
| eTimeOffice connector | **MIGRATE to a declarative adapter** | It is a fixed-shape HTTP+JSON pull; everything it does is already expressible in the generic recipe grammar except the composite Basic-auth username. | Rewrite existing `etimeoffice` rows as adapter recipes; re-encrypt credentials. Behaviour must be byte-identical or punches shift. |
| eTimeTrackLite connector | **MIGRATE to a declarative adapter, with hardening** | Same argument. The field-sniffing heuristics (DEF-C-15/16) must **not** be carried over — replace with an explicit, per-build column map that the Test screen helps the installer build. | Existing rows migrate; each site must re-confirm its column map against a live device before cut-over. |
| Generic / Custom API connector | **REDESIGN as the primary adapter engine** | This is the only part of the current system that already embodies "change configuration, not code". It needs pagination, retry/backoff, OAuth2, an egress allow-list and a schema for the recipe. | `api_config` JSON migrates into a versioned, validated recipe document. Reject-on-save instead of silently NULLing. |
| LAN ZKTeco direct pull (`DeviceController`) | **REMOVE** (or REPLACE with an SBB collector) | No UI, no views, unrouted CRUD, no tenant on `devices`, silent punch loss, first-in/last-out collapse, direction in the match key, swallowed exceptions. Every one of its jobs is done better by SBB or `/iclock`. | Preserve historic `attendance_logs` rows with `source='device'`. The `devices` table becomes read-only history or is folded into the new device registry. |
| Device-ID ↔ employee mapping | **REDESIGN** | The one-global-column model cannot express "PIN 5 on device A vs device B", and the matching precedence is a correctness bug. | Introduce `employee_device_identities(employee_id, device_id NULLABLE, identity_type, identity_value)`; backfill from `employees.device_user_id` with `device_id = NULL` (meaning "any device"). |
| Punch quarantine + replay | **RETAIN, MERGE the two implementations** | The behaviour is correct and valuable. There must be exactly one replay function and one table name. | Rename the bulk-upload staging table (CONF-C-01) before anything else. Consolidate `promotePending`/`replayPending`. Backfill `device_code` on SBB-origin rows. |
| Unmapped-code worklist (`biometric_unmapped`) | **MERGE into the quarantine** | It is a counter that duplicates what `attendance_pending` already knows, and it is the only reason a table is created at runtime by an unauthenticated request. | Derive the worklist from `attendance_pending` by `GROUP BY`. Drop the table after migrating counts. |
| API keys | **RETAIN, extend** | Format, hashing, scoping, expiry and revocation are sound. | Add rotation (two live keys), a real scope model, per-key IP allow-list. Existing `api_keys` rows carry over unchanged. |
| SmartEPT webhook credentials | **RETAIN** | Encrypted-because-verifiable, rotatable, slug-preserving, unreadable-secret surfaced. Model for all shared secrets. | Carry over; re-encrypt if key management changes. |
| Pending Punches / Unassigned Devices screens | **REDESIGN** | The function is essential and the Blade page is the only place some of it exists. It must live in the main UI. | None (view layer). |
| Mobile device gate | **REPLACE** | The token is never verified; the endpoints are unauthenticated and not tenant-scoped; the table is runtime-created. It is a security control that does not control anything. | `mobile_devices` rows are advisory only; re-enrol devices under a real token-verifying scheme. |
| Mobile push notifications | **REMOVE or IMPLEMENT** | Currently a token registry with no sender. | If removed, drop `push_token`; if implemented, tokens must be re-collected because none has ever been validated. |
| `sbb-verify.php`, `SESSION-REPORT-*.md`, `sbb-schema-status.txt` | **REMOVE** | Operational artefacts in the application source tree, one of which prints DB connection details. | Delete; move the verification logic into an artisan command. |

---

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

## 11. Open questions / PRODUCT OWNER DECISION REQUIRED

1. **PRODUCT OWNER DECISION REQUIRED — `/iclock` trust model.** Real ZKTeco/eSSL firmware
   cannot present a credential. Options: (a) keep it unauthenticated but require an admin
   to pre-register and claim each serial before punches are accepted (punches quarantined
   until then); (b) require a network-level control (VPN / IP allow-list per tenant);
   (c) deprecate `/iclock` in favour of SBB on every site. **This decision gates
   requirements 8, 9 and 10 and is the single largest security question in this area.**
2. **PRODUCT OWNER DECISION REQUIRED — is the LAN ZKTeco path (`DeviceController`) sold to
   anyone today?** It has no UI, no views, unrouted CRUD, and loses intra-day punches.
   If no live customer uses it, REMOVE. If any does, it needs a full rebuild.
3. **PRODUCT OWNER DECISION REQUIRED — must a device be able to be shared across
   companies within one tenant?** `biometric_configs` has no `company_id` at all
   (DEF-C-08) and the Pending Punches UI implies it does. Decide the intended cardinality
   (tenant → company → branch → device) before the schema is redesigned.
4. **PRODUCT OWNER DECISION REQUIRED — same PIN on two devices.** Is "PIN 5 on the Chennai
   gate is Ravi and PIN 5 on the Pune gate is Sunil" a supported scenario? The quarantine
   replay already assumes it is (`promotePending`'s `$deviceSn`, pinned by
   `IclockTenantHotfixTest.php:161-187`) but the live mapping model cannot express it.
5. **PRODUCT OWNER DECISION REQUIRED — what should happen to an unknown direction?**
   Today it silently becomes `IN` on four paths. `UNKNOWN` + a review queue is safer but
   changes what payroll sees. Which is the default, and is it per tenant or per device?
6. **PRODUCT OWNER DECISION REQUIRED — SSRF posture for the generic connector.** An
   egress allow-list makes the "any vendor without code" promise weaker (a customer's
   on-prem device on an odd port must be explicitly permitted). Who approves an egress
   entry — the tenant admin, or Ametecs?
7. **PRODUCT OWNER DECISION REQUIRED — is SmartEPT a permanent partner integration or the
   first of many signed-webhook senders?** This decides whether `SmarteptWebhook` becomes
   a configured adapter (requirement) or stays a bespoke class.
8. **PRODUCT OWNER DECISION REQUIRED — mobile app.** Is the device gate meant to be a real
   security control? If yes it must be enforced server-side (SEC-C-08). Are push
   notifications on the roadmap, or should the token registry be deleted (DEF-C-20)?
9. **PRODUCT OWNER DECISION REQUIRED — historic backfill on mapping.** Should mapping a
   device ID rewrite historic `attendance_logs` rows at all (NEW-C-11)? It changes inputs
   to payroll runs that may already be approved. Options: rewrite (today), rewrite only
   within an open attendance period, or never rewrite and require an attendance
   correction.
10. **PRODUCT OWNER DECISION REQUIRED — sync frequency granularity.** Is the fixed
    5/10/15/30/60 dropdown sufficient, or do customers need arbitrary intervals, sync
    windows (e.g. 06:00–22:00 only), or cron expressions?
11. **PRODUCT OWNER DECISION REQUIRED — vendor feature parity.** Do we need to preserve
    break-in/break-out/OT-in/OT-out as distinct punch types (ZKTeco status bytes 2/3/4/5,
    currently collapsed into `in`/`out` at `PushController.php:159`)? This is a data-model
    decision, not just a mapping one.
12. **OPEN QUESTION — evidence gap.** `bootstrap/app.php` is absent from the staged source,
    so the `api-key` / `smartept-webhook` middleware alias registration and the mounting of
    `routes/api.php` and `routes/smartept.php` were inferred, not verified. Obtain the file
    before the blueprint relies on any claim about middleware ordering on those routes.
13. **OPEN QUESTION — which `attendance_pending` shape exists on each live customer
    database?** CONF-C-01 means at least one of the two features is broken on every
    install, and which one depends on install history. This must be surveyed before any
    migration is designed.
14. **OPEN QUESTION — do any production `biometric_configs.password` values fail
    decryption today?** SEC-C-13 means such rows work silently. A one-off audit query
    would tell us whether plaintext credentials exist in customer databases.
