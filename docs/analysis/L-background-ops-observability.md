# AS-IS ANALYSIS — BACKGROUND PROCESSING AND OPERATIONAL RUNTIME

Scheduler · console commands · queues · jobs · cache · backups · operational runbook ·
error handling · logging · observability.

---

## 0. Scope and evidence base

### 0.1 Reference documents read (line ranges)

| Document | Lines | Content |
|---|---|---|
| `COMPLETE_PROJECT_BUSINESS_AND_TECHNICAL_DOCUMENTATION.md` | 27082–30982 | Section 14 — Background Processing (read in full, 3,900 lines, in six slices) |
| same | 25722–25928 | §12 Error handling |
| same | 25928–26142 | §13 Logging + the `activity_logs` hash chain |
| same | 26142–26222 | §14 Backups |
| same | 52072–52269 | §19a.4 Logging and audit-data exposure |
| same | 52957–53275 | §19b.1 The empty exception layer, census of swallowed exceptions |
| `PROJECT_ARCHITECTURE_AND_WORKFLOWS.md` | 2501–2666 | §11 Background processing architecture |

### 0.2 Source files read COMPLETELY (`/home/claude/prs2/`)

| File | Lines |
|---|---|
| `routes/console.php` | 265 |
| `bootstrap/app.php` | 46 |
| `app/Providers/AppServiceProvider.php` | 24 |
| `app/Console/Commands/AbsenceNotify.php` | 46 |
| `app/Console/Commands/ApplyTransfers.php` | 30 |
| `app/Console/Commands/ClearAppState.php` | 40 |
| `app/Console/Commands/ClientProvision.php` | 124 |
| `app/Console/Commands/DedupeAttendance.php` | 89 |
| `app/Console/Commands/DemoReset.php` | 184 |
| `app/Console/Commands/ErrorDigest.php` | 117 |
| `app/Console/Commands/IssueLicense.php` | 69 |
| `app/Console/Commands/MakeLicenseKeys.php` | 65 |
| `app/Console/Commands/PayrollPeriodAudit.php` | 178 |
| `app/Console/Commands/ProbationNotify.php` | 38 |
| `app/Console/Commands/PurgeDemoAttendance.php` | 38 |
| `app/Console/Commands/RenewSubscriptions.php` | 36 |
| `app/Console/Commands/ScanCompliance.php` | 32 |
| `app/Console/Commands/SeedIndustryContent.php` | 765 (structure + `handle()`/`ensureTables()`; the bulk is content strings) |
| `app/Console/Commands/SendGreetings.php` | 48 |
| `app/Console/Commands/SubscriptionAlerts.php` | 144 |
| `app/Console/Commands/SyncETimeOffice.php` | 157 |
| `app/Jobs/NotifyLateArrivals.php` | 55 |
| `app/Jobs/SendNotification.php` | 48 |
| `app/Services/MailService.php` | 219 |
| `config/queue.php` | 112 |
| `config/cache.php` | 107 |
| `config/logging.php` | 132 |
| `config/backup.php` | 93 |
| `config/app.php` | 126 |
| `config/session.php` | 218 (read to line 80; remainder is stock cookie config) |
| `config/activitylog.php` | 51 |
| `composer.json` | 77 |
| `.env.example`, `.env.production.example` | 44 / 103 |
| `database/migrations/0001_01_01_000001_create_cache_table.php` | 35 |
| `database/migrations/0001_01_01_000002_create_jobs_table.php` | 57 |
| `database/migrations/2026_07_15_000200_add_backup_schedule.php` | 27 |
| `updater/updater.php` | 344 (header + backup/rollback path) |

### 0.3 Source read in the relevant regions (evidence for specific claims)

`app/Services/AbsenceService.php`, `GreetingService.php`, `ProbationService.php`,
`TransferService.php`, `SubscriptionService.php`, `LateArrivalService.php`,
`NotificationService.php`, `WaService.php`, `PunchIngestService.php`,
`ETimeOfficeService.php`, `Audit.php`;
`app/Http/Controllers/ComplianceController.php`, `ConfigController.php`,
`PayrollGenController.php`, `ApiKeyController.php`, `BiometricConfigController.php`,
`ClientUpdateController.php`, `SignupController.php`;
`app/Http/Middleware/{LicenseGate,EditionGuard,DemoWriteGuard,EnsureSubscriptionActive,SmarteptSignature}.php`;
`database/seeders/DummyDataSeeder.php`.

### 0.4 Machine census performed on the source tree

A Python AST-free scanner over `app/ routes/ database/ config/ tests/ updater/ public/`
matching **typed** PHP catch blocks only (`catch (\Type $var) {`), which excludes the
~460 JavaScript `catch (e)` blocks embedded in PHP heredocs inside `AppController.php`.
Full classification in §6.3. Verified independently: `grep -c 'catch (\\Throwable'` over
`app/` returns **752** `catch (\Throwable $e)` sites; my scanner's `app/`-only EMPTY count
of **305** matches the reference document's 305 exactly.

### 0.5 What could NOT be verified — evidence gap

The reference document repeatedly cites a `deploy/` directory
(`deploy/smartprs-scheduler.cron:8`, `deploy/supervisor-smartprs-worker.conf`,
`deploy/systemd-smartprs-worker.service`, `deploy/nginx-smartprs.conf`) and a
`06_Build-and-Release-Scripts/` directory. **Neither exists in `/home/claude/prs2/`.**
`find . -iname '*deploy*' -o -iname '*.cron' -o -iname '*supervisor*' -o -iname '*.service'`
returns nothing. Every claim in this document about the cron line, the Supervisor/systemd
worker units, `numprocs=1`, `stopwaitsecs=3600`, `--queue=mail` and `/dev/null` output
redirection is therefore **REPORTED FROM THE REFERENCE DOCUMENT AND NOT CONFIRMED IN
SOURCE**. It is flagged inline as `[UNVERIFIED — deploy/ absent]`. This matters: the entire
operational story of SmartPRS 2 lives in files that are not in the application repository.
That is itself a finding (DEF-L-27).

---

## 1. Business capability inventory

### 1.1 A recurring-work engine ("things that happen without anyone pressing a button")

Twelve recurring jobs run on a timer (`routes/console.php:143-266`). In business terms
they do six things: **keep attendance current** (pull punches off biometric devices),
**warn people before a deadline** (certification expiry, probation completion, subscription
expiry), **tell people what happened yesterday** (absence, late arrival), **move people on
their effective date** (transfers), **protect the data** (nightly backup), and **keep the
public sales demo clean and private** (wipe/reseed).

Nothing in the product tells anyone whether this engine is running.

### 1.2 A notification delivery pipeline

One entry point, `MailService::queue()` (`app/Services/MailService.php:72`), writes an
audit row into `mail_log` and hands the message to a queued job. At delivery the job
resolves the *right* SMTP server for that company, renders a branded template and sends.
Two exceptions bypass this: credential-class mail (`'sync' => true`) is sent inline, and
late-arrival mail from the scheduled biometric pull is sent with a bare `Mail::raw` on the
**default** mailer, so it has no per-company SMTP and no `mail_log` row at all.

### 1.3 An operator toolbox of one-off console commands

Eight commands exist purely for a human to run at a terminal: provision an on-prem client,
mint a licence, generate the licence signing key pair, audit payroll periods, de-duplicate
attendance, purge demo attendance, clear a legacy state blob, and renumber historical
invoices. Four of them delete or rewrite production data; one invalidates every licence
the company has ever issued.

### 1.4 A "self-healing schema" convention

Scheduled tasks and the mail pipeline create tables and add columns **at run time**, on
every execution, outside the migration system (`MailService::ensureTable()`
`MailService.php:49`; `ProbationService::ensureSchema()`; `SubscriptionService::ensureAlertTable()`
called at `SubscriptionAlerts.php:39`; `WaService::ensureLog()`; `NotificationService::ensureTable()`).
Every one of these is wrapped in a catch that discards the error.

### 1.5 Observability

There is a daily email that summarises error-level log lines
(`app/Console/Commands/ErrorDigest.php`), and a framework health endpoint at `/up`
(`bootstrap/app.php:14`). That is the entire observability surface. There is no metric, no
correlation id, no heartbeat, no queue-depth check, no alert, and no APM.

### 1.6 Backups

`spatie/laravel-backup ^9.1` (`composer.json:18`) zips the application root plus a MySQL
dump nightly to `storage/app` — **on the same server it is backing up**
(`config/backup.php:72-74`). There is a code-only rollback for the on-prem self-updater
(`updater/updater.php:140-159`); there is **no data restore path anywhere in the product**.

---

## 2. Data model

Every table this area touches, with provenance. **Runtime schema creation is marked
`RUNTIME` and is a critical finding — see DEF-L-08.**

| Table | Provenance | Used for |
|---|---|---|
| `jobs` | MIGRATION `0001_01_01_000002_create_jobs_table.php:14-22` | the database queue backend |
| `job_batches` | MIGRATION `0001_01_01_000002_create_jobs_table.php:24-35` | **DEAD SCHEMA** — no `Bus::batch()` anywhere in the codebase (verified by grep) |
| `failed_jobs` | MIGRATION `0001_01_01_000002_create_jobs_table.php:37-45` | terminal job failures; **nothing reads it, nothing prunes it** |
| `cache` | MIGRATION `0001_01_01_000001_create_cache_table.php:14-18` | database cache store (default) |
| `cache_locks` | MIGRATION `0001_01_01_000001_create_cache_table.php:20-24` | **load-bearing for the scheduler** — every `withoutOverlapping()`/`onOneServer()` lock lives here on the `database` store |
| `mail_log` | **RUNTIME** — `app/Services/MailService.php:49-66` (`Schema::create` inside `ensureTable()`) | outbound-mail audit: `tenant_id, company_id, kind, recipient, subject, status, error, sent_at`. Bodies/attachments deliberately not stored |
| `wa_log` | **RUNTIME** — `app/Services/WaService.php:26-40` | outbound-WhatsApp audit including **`body_values`** — the template variables, JSON. See SEC-L-03 |
| `notification_log` | **RUNTIME** — `app/Services/NotificationService.php:49+` | in-app inbox + the idempotency ledger (`kind`,`dedupe_key`, `email_status`, `mail_log_id`) |
| `subscription_alerts` | **RUNTIME** — `SubscriptionService::ensureAlertTable()`, invoked at `SubscriptionAlerts.php:39` on every scheduled run | per-tenant/kind/channel alert ledger |
| `probation_events` | **RUNTIME** — `ProbationService::ensureSchema()`, invoked from `runReminders()` | probation reminder/confirm/extend history |
| `employees.probation_months / probation_end / probation_confirmed_on / probation_confirmed_by` | **RUNTIME** — `ProbationService::ensureSchema()` adds four columns on **every scheduled run** | probation dates |
| `employees.backup_due_at`, `employees.backup_by` | MIGRATION `2026_07_15_000200_add_backup_schedule.php:12-21` | **NOT system backup** — this is a *staffing* "backup person" for an employee. See DOC-L-02 |
| `subscriptions.companies` | **RUNTIME** — `DemoReset.php:76-78` adds the column if missing | demo company allowance |
| `companies.is_master` | **RUNTIME** — `ClientProvision.php:82-84` | master-company flag on on-prem provisioning |
| `overtime.paid_run_id` | **RUNTIME** — `PayrollGenController.php:2571-2575` | which payroll run paid an OT entry |
| `loans.installments_paid` | **RUNTIME** — `PayrollGenController.php:2580-2584` | EMI recovery counter |
| `loan_recoveries` | **RUNTIME** — `PayrollGenController.php:2589-2599` (`Schema::create`) | EMI ledger enabling regenerate reversal |
| `clawbacks.recovered_run_id / recovered_amount / recovered_at` | **RUNTIME** — `PayrollGenController.php:2607-2612` | clawback recovery stamp |
| `payslips.calc_note` | **RUNTIME** — `PayrollGenController.php:2556-2563` | payslip calculation note |
| `activity_log` (singular) | MIGRATION `2026_05_30_025626_create_activity_log_table.php` | spatie/laravel-activitylog. Effectively never populated (only `Employee`/`Company` use `LogsActivity` and both are dead paths). `activitylog:clean` is **not scheduled**, so the 365-day retention (`config/activitylog.php:14`) never runs |
| `activity_logs` (plural) | MIGRATION `2026_01_01_000700_create_salary_slips_table.php:14-23`; hash columns `prev_hash`/`row_hash` added at **RUNTIME** by `Audit::ensure()` | the business audit trail |
| `attendance_logs`, `attendance_pending`, `biometric_configs` | migrations (attendance analyst's scope) | written by `attendance:sync-etimeoffice`; `biometric_configs.last_sync_at`/`last_count` are the only durable "did the sync work?" signal |
| `demo_requests` | migration (demo analyst's scope) | passkey window state read by `demo:reset --if-due` |
| `settings` (`key='app_state'`, `tenant_id=0` `key='licence'`) | migration | prototype state blob; on-prem licence state |
| `app_settings` (`ckey` = `greetings` / `absence_notify` / `probation`) | migration | the only genuinely tenant-configurable surface in this area |
| `client_updates` | `LicenseService::ensureTables()` (RUNTIME) | on-prem self-update action log |

**Nine tables and eleven columns in this area are created by application code at run time,
not by a migration.** On a production database where the application user correctly lacks
`CREATE`/`ALTER`, every one of these fails — silently, because every call site swallows the
exception — and the feature degrades to "quietly does nothing".

---

## 3. Business rules register

`BR-SCH-*` IDs are taken from the reference catalogue where confirmed in source. `NEW-L-*`
are rules the catalogue did not isolate.

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HARD-CODED? | Configurable today? | Customer would want it different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-SCH-001 | Field-force certification alert window is 30 days, bucketed expired / ≤7 "critical" / ≤30 "soon" | `employees.dra_expiry`, `pcc_expiry`, `dra_certs.expiry`, `bgv.next_due` | `days <= 30`; no lower bound | queue one digest email per HR/Admin per tenant | all tenants | `ComplianceController.php:31` (`WINDOW_DAYS=30`), `:77-81` | **Y** | Only through the API query string `?days=` (`:156`). The **scheduled** run cannot override it (`ScanCompliance.php:27` calls `notify()` which always uses the default) | **Y** — 30/7 is a policy choice; a bank client may want 60/15 |
| BR-SCH-002 | A compliance digest goes only to holders of `super_admin`/`admin`/`hr_manager`; if the role pivot resolves to nothing, send to **nobody** | `users`, `roles`, `model_has_roles` | role membership | fail-closed to `[]` | tenant | `ComplianceController.php:250-281` | **Y** (role names are a literal array at `:256`) | N | **Y** — a customer may want a compliance officer role, or a distribution list |
| BR-SCH-003 | Nightly backup: clean at 01:30, run at 02:00, one attempt, no retry | — | daily | prune then archive to `storage/app` | server | `routes/console.php:156-164`; `config/backup.php:83-84` (`tries=1`,`retry_delay=0`) | **Y** | Times only by editing PHP. `BACKUP_ARCHIVE_PASSWORD` is the one env knob | **Y** — window, retention, destination, encryption are all customer-specific |
| BR-SCH-004 | The error digest is sent only if `SMARTPRS_OPS_EMAIL` is set and at least one ERROR-class line exists in the last N days | `storage/logs/laravel.log`, env | levels `ERROR\|CRITICAL\|ALERT\|EMERGENCY`, dated today | email the last 10 hits, 240 chars each | platform | `ErrorDigest.php:25-30, 39-48, 76-93` | **Y** (levels, 10, 240 chars, 4000-line/2 MB tail at `:46, :108`) | Only `--days` and the env recipient | **Y** — the customer wants their own thresholds and their own recipients, and probably wants WARNING included |
| BR-SCH-005 | Subscription expiry reminders at **exactly** 15/7/3/1/0 days before period end, and only when `subscriptions.status === 'active'` | `subscriptions.current_period_end`, `status` | `in_array($days,[15,7,3,1,0],true)` | email + WhatsApp to the billing contact | all tenants | `SubscriptionAlerts.php:35` (`BEFORE_DAYS`), `:53-57` | **Y** — a `private const` | N | **Y** — a reseller will want 30/14/7/1 |
| BR-SCH-006 | Grace period is 7 days with a **daily** reminder throughout; the grace branch does **not** check subscription status | same | `days < 0 && days >= -7` | email + WhatsApp daily | all tenants | `SubscriptionAlerts.php:58-59`; `SubscriptionService.php:34` (`GRACE_DAYS = 7`) | **Y** — a `public const` | N | **Y** — grace length is a commercial term |
| BR-SCH-007 | After grace, mark `tenants.status='suspended'` once; do **not** disable users so the admin can still sign in and pay | `subscriptions`, `tenants.status` | `days < -7 && status !== 'suspended'` | `UPDATE tenants`, log `auto-suspend`, `continue` | all tenants | `SubscriptionAlerts.php:60-69` | **Y** | N | **Y** |
| BR-SCH-008 | Alerts are idempotent per (tenant, kind, period_end, channel), matched on `status='sent'` only | `subscription_alerts` | prior `sent` row exists | skip | tenant | `SubscriptionAlerts.php:94, 112`; `SubscriptionService.php:235-248` | Y | N | N (correct behaviour) |
| BR-SCH-009 | Approved transfers apply automatically when the effective date arrives | `transfers.status`, `applied_at`, `effective_date` | `approved` AND `applied_at IS NULL` AND (`effective_date IS NULL` OR `<= today`) | move employee, snapshot, mail | tenant | `TransferService.php:47-73`, `:112+`; `ApplyTransfers.php:25` | Y | N | N (correct); but see BR-SCH-010 |
| BR-SCH-010 | A **company** transfer nulls `reporting_manager`, `team_leader`, `team`, `reporting_manager_id` | `transfers.type` | `stripos(type,'compan') !== false` | null the hierarchy; **no snapshot of the old values** | tenant | `TransferService.php:126-127, 147-157` | **Y** | N | **Y** — a group that keeps a dotted line across companies will not want this |
| BR-SCH-011 | Transfer type is classified by **substring sniffing** on a free-text field | `transfers.type` | `stripos('compan')` then `stripos('depart')`, else branch | choose the move semantics | tenant | `TransferService.php:126-127` | **Y** | N | **Y** — must be an enum, not a string match. `"Inter-Department Company Liaison"` classifies as a **company** move |
| BR-SCH-013 | Absence notice fires only when the tenant enabled it AND the current hour equals the tenant's `send_hour` (default **11**) | `app_settings.ckey='absence_notify'` | `enabled` AND `send_hour === hourGate` | evaluate the previous day | tenant | `AbsenceService.php:38-39, 100-107`; `AbsenceNotify.php:34-39` | Defaults hard-coded; **value is per-tenant configurable** | **Y** — `app_settings` JSON via the Absence config screen | Partly satisfied |
| BR-SCH-014 | Absent = no punch AND not on approved leave AND not a holiday AND not a weekly off; weekly-off fallback is Mon–Sat working (`working_days=[1,2,3,4,5,6]`) | `attendance_logs`, `leaves`, `holidays`, `ShiftResolver` | see order at `AbsenceService.php:141-176` | notify + regularise CTA | tenant | `AbsenceService.php:44-46, 111-176` | Default hard-coded | **Y** — `working_days` is in the tenant config | Partly satisfied. **No WFH concept exists** (docblock `AbsenceService.php:29-31`) |
| BR-SCH-016 | Greetings fire once per employee per kind per **calendar year**; anniversary requires `years >= min_years` (default 1) | `employees.dob`, `doj` | `DATE_FORMAT(dob,'%m-%d') = today` | in-app + email | tenant | `GreetingService.php:34-59, 124-234` | Defaults hard-coded | **Y** — templates, `send_hour`, `enabled`, `min_years` all in `app_settings` | Largely satisfied |
| BR-SCH-017 | Probation end = explicit `probation_end`, else `doj + months`, where months = employee override or config default **6** | `employees.probation_end`, `doj`, `probation_months` | precedence order | derive the confirmation date | tenant | `ProbationService.php:35, 102-120` | Default hard-coded | **Y** — `default_months` in `app_settings` | Satisfied |
| BR-SCH-018 | Probation reminders at **exactly** 30/15/7/0 days before end, plus **one** overdue nudge | `employees.employment_stage='probation'` | `in_array($daysLeft,[30,15,7,0],true)` | notify HR + reporting manager | tenant | `ProbationService.php:36, 188-197` | Default hard-coded | **Y** — `reminder_days` in `app_settings` | Satisfied for values; **not** for the exact-equality semantics (DEF-L-05) |
| BR-SCH-019 | Biometric sync pulls a rolling **24-hour** window every 5 minutes, gated per device by `sync_interval_min` (default **60** when 0/null); writes idempotently on `(tenant_id, emp_code, punch_at, source)` | `biometric_configs`, provider APIs | `--days=1` hard-coded in the schedule | upsert into `attendance_logs` | tenant/device | `routes/console.php:225`; `SyncETimeOffice.php:41-42, 62-73`; `ETimeOfficeService.php` import | **`--days=1` is HARD-CODED in the schedule.** The 60-minute default is hard-coded at `SyncETimeOffice.php:65-67` | Per-device `sync_interval_min` is configurable on the Biometric Device Setup screen. **The window is not** | **Y** — see DEF-L-01, the highest-impact defect in this area |
| BR-SCH-020 | Late-arrival notice fires on IN punches only; delivery path **differs** between the two ingest routes | `attendance_logs`, `late_policy`, `rates.late_email_enabled` | direction === 'in' | email employee, CC reporting manager | tenant | `ETimeOfficeService.php:769-777` (**inline**) vs `PunchIngestService.php:205, 290` (**queued**) | Y | Template configurable (`greetings.late`); default shift start is a class const `LateArrivalService::DEFAULT_START` | **Y** |
| BR-SCH-021 | The public demo is wiped when a visitor's passkey window has ended and nobody with a live passkey is inside; the check **fails to `false` on any exception** | `demo_requests` | see `DemoReset::resetDue()` | wipe ~20 tables | demo tenant | `DemoReset.php:161-183` | Y | N | N (but the fail-open is SEC-L-05) |
| BR-SCH-022 | The demo tenant always carries a rolling 12-month, ₹0, 75-seat / 3-company subscription | — | every `demo:reset` | upsert `subscriptions` | demo tenant | `DemoReset.php:85-98` | **Y** (75, 3, 12 months, `amount=0`) | N | N |
| BR-SCH-023 | The demo tenant's outgoing email and WhatsApp are muted and logged as `skipped`, never sent | `tenant_id` | `DemoAccessController::isDemoTenant()` | insert a `skipped` log row, return | demo tenant | `MailService.php:79-90`; `WaService.php:130-140` | Y | N | N (correct) |
| BR-SCH-024 | Credential-class email (login credentials, password reset, quotations) bypasses the queue and is sent inline | `$msg['sync']` | `!empty($msg['sync'])` | `deliver()` inline, then return before dispatch | platform | `MailService.php:102-116` | Y | N | N (correct — this is the fix for a real production incident) |
| BR-SCH-025 | SMTP resolution order at delivery: per-company → tenant default → platform mailbox (`company_id = '0'`) → mark `skipped` **without throwing** | `ConfigController::mailConfigFor` | host or from_address empty | mark `skipped`, return | per message | `MailService.php:136-152` | Y | Per-company SMTP is fully configurable in-app | N for the order; **Y** for the silent `skipped` (DEF-L-11) |
| BR-SCH-026 | Notification idempotency is `(kind, dedupe_key)` in `notification_log` | `notification_log` | prior row exists | return `null` and send nothing | tenant | `NotificationService.php:91-93`, `alreadySent()` `:150-160` | Y | N | N (correct) — but `send()` returns `null` on both "deduped" and "failed" (DEF-L-12) |
| BR-SCH-027 | Every scheduled task runs on one server only and never overlaps itself | cache lock store | — | `withoutOverlapping()->onOneServer()` on all 12 | server | `routes/console.php:143-266` (12 entries, all chained) | **Y — no expiry argument**, so Laravel's 24-hour default lock applies | N | **Y** — the lock TTL must be per-task |
| BR-SCH-028 | On-prem provisioning refuses to run twice against the same admin email | `users.email` | case-insensitive email exists | `FAILURE` before any write | server | `ClientProvision.php:57-61` | Y | N | N — but the guard is on the **email**, not "does a tenant exist", and there is no transaction (DEF-L-16) |
| NEW-L-01 | The schedule runs `demo:reset` **unconditionally** every night at 03:30 on every install, with no `APP_ENV` or `SMARTPRS_DEMO_DATA` guard | — | daily 03:30 | wipe + reseed; **create three logins with the password `smartprs-demo` if absent** | any server running the scheduler | `routes/console.php:208-211`; `DemoReset.php:35, 54-65, 101-123` | **Y** | **N — there is no way to turn it off without editing PHP** | **Y — CRITICAL.** See SEC-L-01 |
| NEW-L-02 | The biometric schedule guard runs a `Schema::hasTable` + `exists()` query **every minute** on every install, and returns `false` on any exception | `biometric_configs` | every `schedule:run` tick | enable/disable the sync for that tick | server | `routes/console.php:227-237` | Y | Only via `ETIMEOFFICE_ENABLED` or a device row | **Y** — a DB blip silently disables attendance ingestion for that tick with no log line |
| NEW-L-03 | The queue name for every job in the product is the literal `'mail'` | — | job construction | `$this->onQueue('mail')` | platform | `SendNotification.php:41`; `NotifyLateArrivals.php:43` | **Y** | N | **Y** — a customer wanting a separate priority lane cannot have one |
| NEW-L-04 | Job retry policy is 3 attempts / 30 s for mail and 2 / 30 s for late arrivals; the late-arrival job's retry config is **dead** because `handle()` swallows every exception | — | job property | retry | platform | `SendNotification.php:33-34`; `NotifyLateArrivals.php:35-36, 48-53` | **Y** | N | **Y** |
| NEW-L-05 | The mail-log UI shows the most recent **200** rows for the tenant | `mail_log` | — | render | tenant | `ConfigController.php:157` (`->limit(200)`) | **Y** | N | Y (minor) |
| NEW-L-06 | Update-download tokens live in the cache for exactly **60 minutes** and are not burned on first byte, so a dropped download can be retried | cache | `TOKEN_TTL_MINUTES = 60` | `Cache::put` / `Cache::get` | platform | `UpdateServerController.php:26, 273-277, 306-312` | **Y** | N | Y (minor) |
| NEW-L-07 | On WhatsApp signup welcome, the tenant admin's **plaintext temporary password** is sent as template variable {{5}} by default (`SMARTPRS_WA_SEND_PASSWORD` defaults to **true**) and is persisted in `wa_log.body_values` | `signups`, `res['temp_password']` | `!empty($s->mobile)` | send + log | tenant | `SignupController.php:413-425`; `WaService.php:166-176` | Default hard-coded to `true` | Env var `SMARTPRS_WA_SEND_PASSWORD=false` | **Y — SECURITY.** See SEC-L-03 |

---

## 4. Calculations and formulas

There is almost no arithmetic in this area. The five formulas that exist all determine
*when* something fires, and each is transcribed exactly.

### 4.1 Subscription days-to-expiry (`SubscriptionAlerts.php:53`)

```php
$days = (int) round(now()->startOfDay()->diffInDays($end, false));   // negative = past
```

- `$end = Carbon::parse($sub->current_period_end)->startOfDay()` (`:52`).
- **Rounding:** `round()` then integer cast. The inline comment explains why:
  *"(int: Carbon 3 returns float)"*. Without it, `in_array($days, [15,7,3,1,0], true)`
  would never match because a float `15.0` is not identical to the integer `15`.
- **Basis:** whole days between two midnights, in `config('app.timezone')`.
- **Worked example:** today 2026-09-03 00:00 IST, `current_period_end = 2026-09-10`.
  `diffInDays` → `7.0` → `round` → `7.0` → `(int)` → `7`. `7 ∈ BEFORE_DAYS`, and
  `sub.status === 'active'`, so `kind = 'expiry-7'`. Email and WhatsApp are sent unless
  `alertAlreadySent(tid,'expiry-7','2026-09-10','email')`.
- **Boundary:** at `days = -8` the tenant is suspended. `GRACE_DAYS = 7`
  (`SubscriptionService.php:34`), and the branch is `days < -GRACE_DAYS`
  (`SubscriptionAlerts.php:60`), so day −8 is the first suspending day; a customer gets
  reminders on −1 … −7.

### 4.2 Grace end date shown to the customer (`SubscriptionAlerts.php:80`)

```php
$graceEndTxt = $end->copy()->addDays(SubscriptionService::GRACE_DAYS)->format('d M Y');
```

Worked example: `$end = 2026-09-10` → grace end `2026-09-17`. The suspension actually
occurs on the first scheduled run where `days < -7`, i.e. from 2026-09-18 — one day after
the date the customer was told. **Off-by-one between the message and the enforcement.**

### 4.3 Probation end date (`ProbationService.php:102-120`)

```php
if (! empty($a['probation_end'])) return Carbon::parse($a['probation_end'])->startOfDay();
if (empty($a['doj'])) return null;
$months = (int) ($a['probation_months'] ?? 0) ?: (int) ($cfg['default_months'] ?? 6);
return Carbon::parse($a['doj'])->startOfDay()->addMonths($months);
```

- **Divisor/basis:** calendar months via `Carbon::addMonths`, which clamps overflow.
  DOJ 2026-01-31 + 6 months → **2026-07-31**; DOJ 2026-08-31 + 6 months → **2027-02-28**
  (February clamp). No pro-rata, no working-day adjustment.
- `days_left = (int) $today->diffInDays($end, false)`; milestone match is
  `in_array($daysLeft, [30,15,7,0], true)` (`:190`) — **exact equality**.

### 4.4 Per-device biometric sync interval gate (`SyncETimeOffice.php:62-73`)

```php
$iv = (int) ($cfg['sync_interval_min'] ?? 0);
if ($iv <= 0) { $iv = 60; }
if (Carbon::parse($cfg['last_sync_at'])->diffInMinutes(now()) < $iv) { continue; }
```

- `60` is the hard-coded fallback interval. The scheduler ticks every 5 minutes
  (`routes/console.php:225`), so effective cadence = `max(5, sync_interval_min)`.
- **The `Carbon::parse` is inside a try/catch whose catch is empty (`:71-72`).** On a parse
  failure the `continue` never executes and the device is pulled anyway — the safe
  direction, and the one place in this area where a swallow fails safe.

### 4.5 Compliance bucketing (`ComplianceController.php:76-81`)

```php
$days = $today->diffInDays($d, false);   // negative = already expired
if ($days > $windowDays) return;         // $windowDays = WINDOW_DAYS = 30
$bucket = $days < 0 ? 'expired' : ($days <= 7 ? 'critical' : 'soon');
```

**There is no lower bound.** A DRA certificate that expired in 2023 is reported in the
`expired` bucket every single day, forever, and — because the digest shows only the top 25
sorted ascending by `days` (`:207`) — the oldest dead records crowd actionable items out
of the email entirely.

### 4.6 Error-digest scan window (`ErrorDigest.php:101-116`)

```php
$window = min($size, 2 * 1024 * 1024);   // last 2 MB
$lines  = array_slice($lines, -$maxLines);   // last 4000 lines
```

Combined with `config/logging.php:57` defaulting the stack to the **unrotated `single`
channel**, the scan window covers a shrinking slice of wall-clock time as `laravel.log`
grows. On a busy server, "the last 4000 lines" is under an hour.

---

## 5. Configuration surface today

### 5.1 (a) What a customer CAN change without code

| Setting | How | Where |
|---|---|---|
| Greetings: on/off, `send_hour`, `hide_birth_year`, `skip_inactive`, per-kind email/in-app toggles, subject + message templates, anniversary `min_years` | Greetings config screen → `app_settings` JSON | `GreetingService.php:34-59, 84-100` |
| Late-arrival email template (subject + body, `{{arrival_time}} {{shift_start}} {{grace}} {{late_by}}`) | same screen, `greetings.late` block | `GreetingService.php:54-58` |
| Absence sweep: on/off, `send_hour`, notify employee / notify manager, `working_days` pattern, regularise deep-link screen | Absence config screen → `app_settings` | `AbsenceService.php:36-48` |
| Probation: `default_months`, `reminder_days`, `overdue_nudge`, notify HR / manager / employee-on-confirm | Probation config screen → `app_settings` | `ProbationService.php:32-42` |
| Per-device biometric `sync_interval_min` (5/10/15/30/60), enabled flag, credentials, machine IDs | Biometric Device Setup screen → `biometric_configs` | `routes/console.php:222-224`; `SyncETimeOffice.php:62-73` |
| Per-company / tenant-default / platform SMTP host, port, credentials, encryption, from address and name | Administration → Email (SMTP) | `ConfigController.php:119-146`; used at `MailService.php:138` |
| WhatsApp (Interakt) API key and URL, per-tenant or platform | Administration → WhatsApp API → `wa_settings` | `WaService.php:46-60` |
| Late-arrival master switch (`late_email_enabled`) | rates/settings | `LateArrivalService.php:62` |
| Late policy (`shift_start`, `grace_min`) by company / team / employee | `late_policy` table, most-specific-wins | `LateArrivalService.php:160-215` |
| Queue driver, cache store, session driver, log channel and level, app timezone | `.env` | `config/queue.php:16`, `cache.php:18`, `logging.php:21,57,64`, `app.php:68` |
| Ops digest recipient | `SMARTPRS_OPS_EMAIL` in `.env` | `ErrorDigest.php:25` |
| Backup archive password | `BACKUP_ARCHIVE_PASSWORD` in `.env` | `config/backup.php:79` |
| eTimeOffice cloud fallback on/off + credentials | `ETIMEOFFICE_*` in `.env` | `config/smartprs.php:114-122` |
| Whether the WhatsApp welcome carries the plaintext password | `SMARTPRS_WA_SEND_PASSWORD` in `.env` (**defaults true**) | `SignupController.php:413` |

**Caveat that undermines the entire `.env` column:** `.env.production.example:5-6`
instructs the operator to run `php artisan config:cache`. From that moment
`config('app.timezone')` — which drives the *whole schedule* — and
`config('smartprs.etimeoffice.enabled')` — which drives the sync guard — are frozen.
Editing `.env` has **no effect** until `config:clear`. The codebase already knows this and
worked around it in exactly one place: `Edition::current()` reads `SMARTPRS_EDITION`
straight from the `.env` file with a comment explaining that `config()` is frozen at
`config:cache` time.

### 5.2 (b) What a customer CANNOT change without editing PHP — the SmartPRS 3 backlog

This is the requirements list for this area. Every item is currently a literal in a `.php`
file.

**Schedule shape**
1. Every cron expression — all twelve. `07:30`, `01:30`, `02:00`, `07:45`, `08:00`, `06:30`,
   `*/15`, `03:30`, `*/5`, `hourly`, `07:15`, `hourly` (`routes/console.php:143-266`).
2. Which tasks run at all. There is no per-tenant, per-edition or per-environment enable
   flag on any schedule entry except the biometric `->when()` guard.
3. **Whether `demo:reset` runs on this install** — unconditional at 03:30 with no guard
   (`routes/console.php:208-211`).
4. The overlap-lock TTL (Laravel's 24-hour default; no argument is passed anywhere).
5. The scheduler's timezone (there is no `->timezone()` call and no `schedule_timezone`
   config key; verified by grep).
6. Whether a task runs during maintenance mode (no `evenInMaintenanceMode()` anywhere).
7. Whether a task runs in the background (`runInBackground()` — absent).

**Thresholds and audiences** *(the highest-value items — see §Q8)*
8. Compliance window `WINDOW_DAYS = 30` and the `critical` cut at 7 days
   (`ComplianceController.php:31, 80`).
9. Compliance digest recipient roles `['super_admin','admin','hr_manager']`
   (`ComplianceController.php:256`).
10. Compliance digest size — top 25 items (`ComplianceController.php:206`).
11. Subscription reminder ladder `[15,7,3,1,0]` (`SubscriptionAlerts.php:35`).
12. Subscription grace `GRACE_DAYS = 7` (`SubscriptionService.php:34`).
13. Whether auto-suspend happens at all, and what it does (`SubscriptionAlerts.php:60-69`).
14. Probation reminder recipient roles `['admin','hr_manager','hr','super_admin']`
    (`ProbationService.php:250-253`).
15. Biometric pull window `--days=1` (`routes/console.php:225`) and the 60-minute default
    interval (`SyncETimeOffice.php:65-67`).
16. Error-digest severity set, hit count (10), truncation (240 chars), tail window
    (2 MB / 4000 lines) (`ErrorDigest.php:46, 48, 76, 79, 108`).
17. Demo subscription shape: 75 seats, 3 companies, 12 months, ₹0 (`DemoReset.php:85-90`).
18. The demo password default `smartprs-demo` (`DemoReset.php:35`).
19. Transfer type classification strings `'compan'` / `'depart'` (`TransferService.php:126-127`).
20. Which employee fields a company transfer clears (`TransferService.php:150-157`).
21. On-prem provisioning `seats_licensed = 100000` (`ClientProvision.php:74`).
22. Mail-log UI page size 200 (`ConfigController.php:157`).
23. Update-token TTL 60 minutes (`UpdateServerController.php:26`).

**Queue and jobs**
24. The queue name `'mail'` (`SendNotification.php:41`; `NotifyLateArrivals.php:43`).
25. `tries`/`backoff` on both jobs (3/30 and 2/30).
26. Which messages bypass the queue — the `'sync' => true` decision is made by each caller
    in code, not by configuration (`MailService.php:107`).
27. Whether late-arrival mail is queued or inline — it depends on which ingest path fired
    (`ETimeOfficeService.php:777` inline vs `PunchIngestService.php:205` queued).
28. Job timeout, memory limit, worker count `[UNVERIFIED — deploy/ absent]`.

**Backups**
29. Backup destination (`disks => ['local']`, `config/backup.php:72-74`).
30. Backup contents include/exclude set (`config/backup.php:28-42`) — notably `.env` is
    **included** and `storage/app` (which holds the licence private key and every uploaded
    document) is **excluded**.
31. `tries = 1`, `retry_delay = 0` (`config/backup.php:83-84`).
32. The backed-up database connection is the literal `['mysql']` (`config/backup.php:45-46`)
    — an install on any other connection name backs up nothing.
33. Retention — deliberately not defined; the package default applies and cannot be tuned
    without publishing the vendor config (`config/backup.php:88-92`).
34. Backup failure notification recipient — the `notifications` block is omitted and
    `notifications.mail.to` is set nowhere.

**Error handling, logging, observability**
35. Every error message string and HTTP status shape (hand-written in 47 controllers).
36. Which exceptions are reported (`bootstrap/app.php:44-46` is empty).
37. Log channel stack (`LOG_STACK` defaults to `single` — unrotated) and retention.
38. What gets logged and at what level — 83 literal `Log::` call sites.
39. Whether anything is alerted on. Nothing is.

---

## 6. Defects, conflicts and technical debt

### 6.1 The nine mandated questions, answered explicitly

---

#### Q1 — The complete schedule

Twelve entries, `routes/console.php:143-266`. **All twelve** chain
`->withoutOverlapping()->onOneServer()` (verified: 12 occurrences of each, plus one prose
mention of `withoutOverlapping` in the comment at line 141). **None** uses
`->onFailure()`, `->onSuccess()`, `->pingOnFailure()`, `->emailOutputTo()`,
`->sendOutputTo()`, `->appendOutputTo()`, `->runInBackground()`, `->evenInMaintenanceMode()`
or `->timezone()` — verified by grep across `routes/` and `app/`.

| # | Command | Cron | Line | What it does | Tenant-aware? | Overlap-protected? | If it fails |
|---|---|---|---|---|---|---|---|
| 1 | `compliance:scan` | `30 7 * * *` | 143-146 | DRA/PCC/BGV expiry digest to each tenant's HR/Admin | **Yes** — iterates every distinct `employees.tenant_id` (`ComplianceController.php:192`) | Yes + onOneServer | **`notify()` has NO try/catch.** An exception on tenant 3 of 20 aborts the run; tenants 4–20 get no digest that day. Stdout is discarded `[UNVERIFIED — deploy/]`. Nothing is alerted |
| 2 | `backup:clean` | `30 1 * * *` | 156-159 | prune old archives per the package default retention | n/a (server-level) | Yes + onOneServer | Silent. No monitor, no notification target configured |
| 3 | `backup:run` | `0 2 * * *` | 161-164 | DB + files zip to `storage/app` | n/a | Yes + onOneServer | `tries=1`, `retry_delay=0` (`config/backup.php:83-84`) — one attempt, no retry, no alert. Failure detectable only by a human listing the directory |
| 4 | `errors:digest` | `45 7 * * *` | 166-169 | email an ERROR-level summary to `SMARTPRS_OPS_EMAIL` | No — platform-scoped, `tenant_id = null` | Yes + onOneServer | **No try/catch in `handle()` at all.** `filesize`/`fopen`/`fseek`/`fread` are unguarded (`ErrorDigest.php:105-112`). If the ops email is unset it warns and returns **SUCCESS** (`:25-30`) — a silent opt-out |
| 5 | `billing:alerts` | `0 8 * * *` | 179-182 | expiry reminders + auto-suspend | **Yes** — iterates all `subscriptions` | Yes + onOneServer | **Best isolation in the codebase**: per-tenant try/catch at `SubscriptionAlerts.php:135-137` prints a warning and continues. WhatsApp isolated separately at `:114-129`. The command still returns SUCCESS regardless |
| 6 | `transfers:apply` | `30 6 * * *` | 190-193 | apply due approved transfers | **Yes** — the `transfers` query is unscoped and covers all tenants | Yes + onOneServer | Per-row try/catch (`TransferService.php:65-69`) writes the error **to stdout only**, which cron discards. The printed total is "applied", not "attempted", so a permanently stuck transfer is indistinguishable from "nothing was due" |
| 7 | `demo:reset --if-due` | `*/15 * * * *` | 204-207 | passkey-aware demo wipe | Demo tenant only | Yes + onOneServer | `resetDue()` returns `false` on any exception (`DemoReset.php:180-182`) — **a privacy control that fails open, silently** |
| 8 | `demo:reset` | `30 3 * * *` | 208-211 | **unconditional** demo wipe + reseed | Demo tenant — but the user lookup at `DemoReset.php:106` is **not tenant-scoped** | Yes + onOneServer | Five empty catch blocks (`:79, 121, 134, 140, 149`), no transaction. A mid-run failure leaves the demo tenant's people deleted and not re-created |
| 9 | `attendance:sync-etimeoffice --days=1` | `*/5 * * * *`, gated by `->when()` | 225-239 | pull biometric punches | **Yes** — per `biometric_configs` row, each carrying `tenant_id` | Yes + onOneServer | Fetch failure prints to stdout and `continue`s (`SyncETimeOffice.php:83-87`). `import()` is **not** wrapped: an exception there aborts the whole tick and every remaining device is skipped. `last_sync_at` is only stamped on success, so a failed device retries next tick — good |
| 10 | `greetings:send` | `0 * * * *` | 245-248 | birthday / anniversary greetings | **Yes** — iterates all tenants, each self-gating on `send_hour` | Yes + onOneServer | `GreetingService::runForToday()` wraps the **entire tenant loop** in one try/catch → `Log::warning` (`:157`). A failure on tenant 3 of 20 silently abandons 4–20, and the command still exits SUCCESS with an under-count |
| 11 | `probation:notify` | `15 7 * * *` | 253-256 | probation milestone reminders | **Yes** | Yes + onOneServer | `runReminders()` is not wrapped — an exception propagates and the task fails with the output discarded. `board()` swallows per tenant, so a bad tenant yields an empty board silently. **Performs DDL on every run** (`ProbationService::ensureSchema()`) |
| 12 | `absence:notify` | `0 * * * *` | 262-265 | previous-day absence notices | **Yes** — self-gating on `send_hour` | Yes + onOneServer | Same whole-loop swallow as greetings (`AbsenceService.php:134`). And `isHoliday()` returns `false` on any exception (`:233-235`) — **a failing holidays query flags the entire workforce absent on a public holiday** |

**Timezone.** All twelve `dailyAt()` calls evaluate against `config('app.timezone')`
= `env('APP_TIMEZONE', 'UTC')` (`config/app.php:68`). Both env templates set
`Asia/Kolkata`. An install that omits the line runs the **entire schedule 5 h 30 m off**,
and the day-boundary tasks (`absence:notify` computes `now()->subDay()`,
`greetings:send` matches `m-d`, `transfers:apply` compares to `now()->toDateString()`)
target the wrong day. There is no `->timezone()` call and no `schedule_timezone` key.

**Overlap-lock TTL.** `withoutOverlapping()` is called with **no argument** on all twelve,
so Laravel's default 24-hour lock expiry applies. A task killed without releasing its lock
(OOM, `kill -9`, a PHP fatal) is blocked for up to a day. For `attendance:sync-etimeoffice`
that is a full day of missing punches. Recovery is `php artisan schedule:clear-cache`,
which appears nowhere in the repository.

**`onOneServer()` depends on an atomic-lock cache store.** `config/cache.php:18` defaults
to `database` (using `cache_locks` from
`0001_01_01_000001_create_cache_table.php:20-24`); the env templates set `redis`. Both
support locks. Setting `CACHE_STORE=array` or `file` on a multi-server deploy would
silently stop deduplicating.

---

#### Q2 — Queue driver in use, and what is synchronous

**The configuration disagrees with itself across four places:**

| Source | Value | Citation |
|---|---|---|
| `config/queue.php` default | `env('QUEUE_CONNECTION', 'database')` | `config/queue.php:16` |
| Dev template | `QUEUE_CONNECTION=redis` | `.env.example:24` |
| Production template | `QUEUE_CONNECTION=redis`, with an explicit note offering `sync` as an interim | `.env.production.example:36, 46-48` |
| Migration shipped | `jobs`, `job_batches`, `failed_jobs` — the **database** driver's tables | `0001_01_01_000002_create_jobs_table.php` |
| Job docblocks | assume `sync` is live | `SendNotification.php:20-22`; `NotifyLateArrivals.php:22-24` |
| `queue.failed.database` | `env('DB_CONNECTION', 'sqlite')` — an **sqlite** fallback in a MySQL product | `config/queue.php:108` |
| `queue.batching.database` | same sqlite fallback | `config/queue.php:89` |

So: **the code ships the database driver's schema, defaults to `database`, and both
templates recommend `redis`.** `predis/predis ^2.2` is a hard requirement
(`composer.json:15`).

**One queue name exists: `mail`.** Grep for `->onQueue(` and `->onConnection(` across the
whole codebase returns exactly two lines: `SendNotification.php:41` and
`NotifyLateArrivals.php:43`. Nothing is ever dispatched to `default`, and
`[UNVERIFIED — deploy/ absent]` the shipped workers listen only to `--queue=mail`. Any
future job that forgets `onQueue('mail')` would sit in `jobs` forever with no error.

**The complete dispatch inventory — three sites, two job classes:**

| # | Call site | Job | Trigger |
|---|---|---|---|
| 1 | `app/Services/MailService.php:117` | `SendNotification` | every notification email in the product |
| 2 | `app/Services/PunchIngestService.php:205` | `NotifyLateArrivals` | SBB device push-ingest batch |
| 3 | `app/Services/PunchIngestService.php:290` | `NotifyLateArrivals` | `replayPending()` — promoting quarantined punches |

No `Bus::batch()`, `Bus::chain()`, `dispatchSync()`, `dispatchAfterResponse()` or `->delay()`
anywhere. `Queue::` returns **zero matches** across `app/`, `routes/` and `config/` —
so there is no `Queue::failing()` listener. `job_batches` is dead schema.

**Every long-running SYNCHRONOUS operation inside a web request:**

| # | Operation | Blocking cost | Evidence |
|---|---|---|---|
| 1 | **Update package download**, on-prem self-update | `Http::timeout(900)` — a **15-minute** synchronous HTTP request holding a PHP-FPM worker, with `@set_time_limit(900)` | `UpdateClient.php:225`; `ClientUpdateController.php:802, 856` |
| 2 | **Razorpay order creation** (signup) | **no timeout at all**; PHP's `default_socket_timeout` at best | `SignupController.php:224` |
| 3 | **Razorpay** — invoice order | **no timeout** | `BillingController.php:593` |
| 4 | **Razorpay** — tenant billing renewal | **no timeout** | `TenantBillingController.php:339` |
| 5 | **Razorpay** — on-prem client order | **no timeout** | `OnpremClientController.php:600` |
| 6 | **Razorpay** — signup verify / refund / order lookup (3 sites) | **no timeout** | `SignupController.php:799, 819, 867` |
| 7 | **eTimeOffice** cloud punch fetch | 60 s | `ETimeOfficeService.php:158` |
| 8 | **eTimeTrackLite** (eSSL SOAP) fetch | **no timeout** | `ETimeTrackLiteService.php:86` |
| 9 | **Generic device API** fetch + token | 60 s ×2 | `GenericApiService.php:114, 147` |
| 10 | **Interakt WhatsApp** template send | 15 s, **called inline from a scheduled task and from web requests** | `WaService.php:146` |
| 11 | **SendMessage gateway** | 15 s | `SendMessageController.php:260` |
| 12 | **Update-server heartbeat / activate** | 6 s / 20 s | `ClientUpdateController.php:137, 276` |
| 13 | **Update manifest check** | 25 s | `UpdateClient.php:119` |
| 14 | **Credential-class mail** — login credentials, password resets, quotations | full SMTP handshake + send, **inline in the request**, no retry | `MailService.php:107-116` |
| 15 | **SMTP test** from the settings screen | full SMTP handshake inline | `ConfigController.php:209` |
| 16 | **Invoice / letter PDF generation + mail** | dompdf render + SMTP inline | `BillingController.php:932`; `LetterController.php:248` |
| 17 | **Employee CSV/XLSX import** — parse, validate and commit every row, in-request | unbounded by row count | `EmployeeImportService::stage/commit` |
| 18 | **`attendance:dedupe`'s query pattern** when invoked from the migration — loads the **entire** `attendance_logs` table into PHP memory, unchunked | OOM risk | `DedupeAttendance.php:42-45` |
| 19 | **Every runtime DDL** on the payroll-generate path — 5 `Schema::table`/`Schema::create` calls before the transaction opens | implicit COMMIT on MySQL, lock contention | `PayrollGenController.php:2556-2616` |
| 20 | **Audit chain verification** — `Audit::verify()` reads **every row of every tenant** into memory whenever any tenant admin opens the Activity Logs screen | O(all rows) | `Audit.php:111`; called from `ComputedController.php:146` |

**And, with `QUEUE_CONNECTION=sync` — which `.env.production.example:46-48` explicitly
offers as an interim — every one of the twenty is joined by every notification email in
the product**, sent inline at dispatch time. `MailService`'s own promise that *"the user's
click is never blocked by a slow SMTP server"* (`MailService.php:21-24`) does not hold.

Additionally, and independent of the queue setting: **late-arrival email from the
scheduled biometric pull is sent inline** (`ETimeOfficeService.php:777` →
`LateArrivalService.php:289` `Mail::raw`). This is exactly the pattern the
`NotifyLateArrivals` job was created to fix — its own docblock says so
(`NotifyLateArrivals.php:16-21`) — and only the SBB push path was migrated.

---

#### Q3 — Silent failure census

**Method.** Machine scan of `app/ routes/ database/ config/ tests/ updater/ public/`
for typed PHP catch blocks (`catch (\Type $var) {`), brace-matched to extract the body,
comments stripped, then classified. JavaScript `catch (e)` inside PHP heredocs is excluded
by requiring a `$`-prefixed variable — this matters, because `AppController.php` alone
contains ~150 of them.

**Totals:**

| Class | Count | Share |
|---|---|---|
| **EMPTY** (body is empty or comment-only) | **332** | 39.4% |
| **RETURN_ONLY** (`return;` / `return null/false/true/[]/0/$x;` and nothing else) | **94** | 11.2% |
| **OTHER** (neither logs nor rethrows; returns a value, sets a default, or writes a fallback row) | **331** | 39.3% |
| **LOGGED** (`Log::*`, `report()`) | **82** | 9.7% |
| **RETHROW** | **4** | 0.5% |
| **TOTAL typed catch blocks** | **843** | |

**426 catch blocks (50.5%) destroy the exception entirely and leave no trace anywhere.**
A further 331 neither log nor rethrow. **Only 86 of 843 — 10.2% — preserve any durable
signal.**

Cross-check: `app/`-only EMPTY = **305**, matching the reference document's independently
derived 305 exactly. `grep -c 'catch (\Throwable'` over `app/` = **752**; adding
`\Throwable $ex` (10), `\Throwable $e2` (6), `\Exception $e` (4) and 22 typed
`ValidationException` gives 794 — the balance to 808 is in `$e3`/`$er`-style variables.

**Concentration by subtree — the alarming part:**

| Subtree | Total typed catches | EMPTY | RETURN_ONLY | LOGGED |
|---|---|---|---|---|
| `app/Http/` (controllers + middleware) | 626 | — | — | 46 |
| `app/Services/` | 154 | 53 | 37 | 34 |
| **`app/Console/Commands/`** | **23** | **17** | **4** | **0** |
| `app/Http/Middleware/` | 7 | **6** | 0 | **0** |
| `app/Jobs/` | 1 | 0 | 0 | 1 |
| `database/migrations/` | 33 | — | — | 0 |

**Not one of the 23 catch blocks inside the scheduled commands logs anything. Not one of
the 7 catch blocks inside the security middleware logs anything.**

**Classification by business domain:**

| Domain | Dangerous? | Representative sites |
|---|---|---|
| **Authorization / entitlement** | **CRITICAL — all fail OPEN** | `LicenseGate.php:73-75`, `EditionGuard.php:55-57`, `DemoWriteGuard.php:73-75`, `EnsureSubscriptionActive.php:58-59`, `SubscriptionService.php:116-118` |
| **Money (payroll, billing, commissions)** | **CRITICAL** | `PayrollGenController.php:2417/2430/2450/2463` (regenerate reversals), `:2571-2616` (ledger DDL), `:1701/1723` (reimbursements, advances), `BankPackController.php:104/359` |
| **Attendance (the payroll input)** | **HIGH** | `AbsenceService.php:233-235/250/273`, `AttendanceCorrectionService.php:419/447`, `SyncETimeOffice.php:71/130/148`, `ETimeOfficeService.php:392` |
| **Statutory / audit** | **HIGH** | `Audit.php:41-43` (hash-column DDL), `Audit.php:97-99` (every audit write), `StatutoryConfigController.php:423` |
| **Integration** | **MEDIUM-HIGH** | `WaService.php` ×4, `GenericApiService.php` ×2, `ETimeTrackLiteService.php:307`, `ClientUpdateController.php` ×13 |
| **Notification delivery** | **MEDIUM-HIGH** | `NotificationService.php:197/219`, `MailService.php:215`, `TransferService.php:212`, `NotifyLateArrivals.php:50-53` |
| **Genuinely harmless** | — | `ApiKeyAuth.php:142-144` (telemetry touch), `BillingController.php:974` and `SubscriptionService.php:229` ("logging must never throw") |

**THE WORST TEN, ranked by business impact, with file:line:**

| # | Location | Code / comment | Consequence |
|---|---|---|---|
| **1** | `app/Http/Controllers/PayrollGenController.php:2571-2616` — four DDL catches at `:2576`, `:2585`, `:2601`, `:2614` | `// fail-soft: OT still pays; it just won't flip to 'paid'` · `// fail-soft: EMI still deducts; the counter just won't advance` · `// fail-soft` · `// fail-soft: the clawback still deducts; it just won't be stamped` | **Recurring double-payment of real money.** If `overtime.paid_run_id` cannot be added, OT is never stamped paid and **next month's run pays the same overtime again**. If `loans.installments_paid` cannot be added, the loan never closes and the EMI is deducted from the employee's salary **forever**. If `loan_recoveries` cannot be created, a regenerated run **double-recovers** every EMI. If the clawback columns cannot be added, the clawback is recovered again next month. On a correctly-hardened production database (app user without `ALTER`), **all four fire**. Verified in source. |
| **2** | `app/Http/Controllers/PayrollGenController.php:2417, 2430, 2450, 2463` | `// best-effort; a regenerate must not fail on the reversal.` (×4) | **Payroll regenerate leaves the ledger permanently inconsistent.** The old payslips and run header are deleted **unconditionally** at `:2389-2390`, *then* the commission, overtime, loan-EMI and clawback reversals run best-effort, *then* `compute()` at `:2468`, and only at `:2620` does `DB::beginTransaction()` open — **for the new rows only**. A crash between 2390 and 2620 destroys the previous payslips and produces nothing. A failure in any one reversal orphans `commission_payments` debits and leaves `commissions.locked_at` pointing at a run that no longer exists. |
| **3** | `app/Http/Middleware/LicenseGate.php:73-75` | `} catch (\Throwable $e) { return $next($request); }` | **Licence enforcement fails OPEN.** Any throwable inside the gate — DB down, missing `licenses` table, a malformed licence token, a `TypeError` in `LicenseService` — lets the request through unlicensed. The paid-product gate is one exception away from being off. Compounded by `LicenseGate.php:69-70`, an empty catch around `Auth::logout()` + `session()->invalidate()` + `regenerateToken()`: if session invalidation throws, the user is redirected to `/login` **with the session still valid**. |
| **4** | `app/Services/SubscriptionService.php:116-118` | `} catch (\Throwable $e) { // fail-soft → unlimited }` | **A failed seat/plan-limit lookup grants UNLIMITED seats.** The comment states the intent. This is a paid-entitlement bypass that also silences the on-prem `.lic` `device_limit` enforcement resolved three lines above at `:109-115`. |
| **5** | `app/Services/AbsenceService.php:233-235` | `isHoliday()` → `catch (\Throwable $e) { return false; }` | **The entire workforce is flagged absent on a public holiday.** `isHoliday()` is the tenant-level short-circuit at `:111-113`; returning `false` means the day is treated as working. Every active employee then receives a "No attendance recorded" notice with a regularise link, and the day feeds LOP. Its two siblings are worse in the same way: `presentEmpCodes()` at `:250-251` swallows to an **empty set** — so if the `attendance_logs` query throws, **everyone who actually was present is marked absent** — and `onLeaveEmployeeIds()` at `:273-274` swallows to an empty set, so **everyone on approved leave is marked absent**. |
| **6** | `app/Services/Audit.php:41-43` and `:97-99` | `// auditing is best-effort; never block the caller` | **The tamper-evident audit ledger silently drops rows and silently loses its chain.** `:41-43` wraps the DDL that adds `prev_hash`/`row_hash`; if it fails, `Schema::hasColumn('activity_logs','row_hash')` at `:90` stays false forever and the ledger becomes plain unverifiable rows **while the UI still reports "chain VERIFIED"**. `:97-99` wraps the insert itself, so every failed audit write leaves an invisible gap indistinguishable from a deletion. This is the evidence base for an RBI-compliance audit report the product sells. |
| **7** | `app/Http/Controllers/RequestController.php:799-801` and `:1745-1747` | empty catch around `$paid = sum(commission_payments.amount)` | **`$paid` silently stays `0.0`.** At `:1745` that value drives the **bounce clawback amount** — a failed query produces a ₹0 clawback and the company never recovers money it paid on a collection that later bounced. |
| **8** | `app/Http/Controllers/BankPackController.php:104` and `:359` | two empty catches in the salary bank-file generator | **An employee is not paid and nobody is told.** A malformed or missing bank row disappears from the payment file; the operator sees a successfully generated pack. |
| **9** | `app/Console/Commands/SyncETimeOffice.php:130-131` (and `:71-72`, `:148-149`) | empty catch around the `biometric_configs.last_sync_at` / `last_count` stamp | **The only durable "did the biometric sync work?" signal is written inside a silent catch.** If the stamp fails, `last_sync_at` never advances, the interval gate re-pulls the device every 5 minutes forever, and the Biometric Device Setup screen — the sole place an operator can see sync health — shows a permanently stale timestamp that is a *lie about the write*, not about the sync. |
| **10** | `app/Console/Commands/DemoReset.php:180-182` | `resetDue()` → `catch (\Throwable $e) { return false; }` | **A privacy control that fails open, silently.** `resetDue()` decides whether a prospect's typed data must be erased. Any exception returns "nothing due" and the wipe is skipped with no log line and no alert, while the product's own copy (`routes/console.php:200-203`) promises the customer's test data is erased right after their hours finish. |

**Runners-up that only just missed the top ten:** `routes/console.php:234-236` (the
biometric schedule guard returns `false` on any exception, so a transient DB error silently
disables attendance ingestion for that tick); `MailService.php:215-217` (the `mail_log`
status update — the audit trail of delivery — is itself inside a silent catch);
`ClientProvision.php:112-115` and `DemoReset.php:132-135` (starter-content seeding fails
completely silently, so a newly provisioned on-prem customer gets empty Training/FAQ/KB
screens and the installer reports success); `EditionGuard.php:55-57` and
`DemoWriteGuard.php:73-75` (two more deliberate fail-opens on entitlement and demo-write
protection).

---

#### Q4 — What visibility does an operator have TODAY that a job failed?

**Answer, per subsystem. The short version: essentially none.**

| Subsystem | What IS observable | What is NOT observable |
|---|---|---|
| **The scheduler** | **Nothing.** There is no last-run table, no heartbeat, no `pingOnSuccess()`, no `->onFailure()`, no output file. `[UNVERIFIED — deploy/]` cron discards stdout and stderr to `/dev/null`, so even a PHP fatal leaves no trace | Whether `schedule:run` has ever run; whether it ran today; whether any individual task succeeded, failed, or was blocked by a stale 24-hour overlap lock |
| **The queue** | `mail_log.status` (`queued\|sent\|failed\|skipped`), viewable at the Mail Log screen — most recent **200 rows for the tenant** (`ConfigController.php:149-181`) | Queue **depth**. Whether a worker is alive. `failed_jobs` — **nothing reads it, nothing prunes it, no `Queue::failing()` listener exists** (grep for `Queue::` returns zero matches). Whether a `mail_log` row stuck at `queued` has been stuck for an hour or three months |
| **Mail** | `mail_log` rows, per tenant, in the UI. `Log::warning` on sync-send failure (`MailService.php:112`) and on `queue()` failure (`:121`) | **A message with no SMTP configured is marked `skipped` and the method returns without throwing** (`MailService.php:148-152`) — the job succeeds having sent nothing, no `failed_jobs` row is written, and nothing alerts. **Late-arrival mail has no `mail_log` row at all** because it goes through `Mail::raw` on the default mailer (`LateArrivalService.php:289`), bypassing `MailService` entirely |
| **WhatsApp** | `wa_log` rows with `status`, `error`, `template`, `mobile` and `body_values` (`WaService.php:166-176`); `subscription_alerts` rows recording each attempt | No UI surfaced for `wa_log` in the files read. No alert. Four empty catches in `WaService` mean a configuration failure is invisible |
| **Biometric sync** | `biometric_configs.last_sync_at` and `last_count`, surfaced on the Biometric Device Setup screen. `Log::info('sbb.ingest.batch', …)` and `Log::warning('sbb.punch.rejected', …)` for the **push** path only (`PunchIngestService.php:174, 183`) | **Nothing alerts on a stale `last_sync_at`.** The scheduled **pull** path logs nothing at all — fetch failures print to a discarded stream (`SyncETimeOffice.php:84`). The stamp write is inside an empty catch (`:130`). An operator must remember to open a screen and notice a date |
| **Backups** | **Nothing.** `config/backup.php:88-92` deliberately omits `notifications` and `monitor_backups`; `notifications.mail.to` is set nowhere; no published Spatie config exists in `config/`; `backup:monitor` is **not** on the schedule; `tries=1` with no retry | Whether last night's backup ran, succeeded, or is 0 bytes. Detectable only by a human listing `storage/app/` and noticing the newest zip is old |
| **Errors generally** | One daily email — `errors:digest` at 07:45 | **The one alerting mechanism is structurally blind to the failure mode the scheduled services actually use.** Every swallowed failure in this area logs at `Log::warning` (`AbsenceService.php:134`, `GreetingService.php:157`, `ProbationService.php:165`, `MailService.php:112, 121`, `NotifyLateArrivals.php:52`, `LateArrivalService.php:296`), and `ErrorDigest.php:48` scans only for `ERROR\|CRITICAL\|ALERT\|EMERGENCY` — while `.env.production.example:24` sets `LOG_LEVEL=warning`, so the warnings are *written* but never *reported*. Worse: **the digest is itself delivered through the queue whose failure it cannot detect** |

**The two failures that hurt most are the two that are completely invisible:** a stopped
cron and a stopped queue worker. Both leave the product looking entirely healthy. There is
direct in-code evidence that the second has already happened in production and cost a
paying customer access to their workspace — `MailService.php:102-106` records the fix:
*"rev 170: CRITICAL mails … are sent INLINE … so they go out even when no queue worker is
running (the silent failure that locked a paying client out of their new workspace)."*
That fix was applied to credential mail only; every other notification remains exposed.

---

#### Q5 — Backups

| Question | Answer | Evidence |
|---|---|---|
| **What is backed up?** | The whole application root `base_path()` **minus** `vendor`, `node_modules`, `storage/app`, `storage/framework`, `storage/logs`; plus a full dump of the connection literally named **`mysql`** | `config/backup.php:28-46` |
| **What is therefore NOT backed up?** | (a) **`storage/app`** — which holds `keys/license_private.pem` (`LicenseSigner`), every uploaded document under `storage/app/public/` (branding logos, employee photos, self-onboarding documents, commission proofs), and release packages. The comment says the exclusion is because the backup zips live there. (b) `storage/logs` — no forensic trail survives a server loss. (c) Any database on a connection not named `mysql` | `config/backup.php:35-37, 45-46` |
| **What IS in it that should not be** | **`.env`** — it is under `base_path()` and is not excluded. On a production server that file carries `APP_KEY`, `DB_PASSWORD`, `MAIL_PASSWORD`, `RAZORPAY_SECRET`, `RAZORPAY_WEBHOOK_SECRET` and `SMARTPRS_OPS_EMAIL`. Plus the DB dump: every password hash, `personal_access_tokens`, `api_keys.key_hash`, `wa_log.body_values` (**OTPs and plaintext first-login passwords** — see SEC-L-03), and `jobs`/`failed_jobs.payload` (raw invite-token URLs and base64 PDF attachments) | `config/backup.php:30-38`; `.env.production.example:32, 57, 95` |
| **Where to?** | `disks => ['local']` → `storage_path('app')` — **the same server, the same volume, that it is backing up.** The config comment concedes the point verbatim: *"For real safety add a remote 'backups' disk (S3/Spaces) … so backups survive a server loss."* No remote disk exists in `config/filesystems.php` and neither env template sets one up | `config/backup.php:69-75` |
| **Encrypted?** | **No, by default.** `password => env('BACKUP_ARCHIVE_PASSWORD')` is unset; the production template leaves it commented out. `encryption => 'default'` applies AES-256 **only if a password is set** | `config/backup.php:79-81`; `.env.production.example:65-66` |
| **Retention?** | **NOT DETERMINED FROM SOURCE.** `config/backup.php:88-92` deliberately omits the `cleanup` section so the installed package's own defaults apply — the stated reason (lines 5-17) is that hard-coding `DefaultCleanupStrategy` *"caused an InvalidConfig error on boot"*. `vendor/` is absent from this tree, so the actual policy cannot be read. `backup:clean` runs daily at 01:30 and therefore enforces *something* | `config/backup.php:88-92`; `routes/console.php:156-159` |
| **Does a restore exist?** | **No data restore exists anywhere in the product.** There is no `backup:restore` command, no restore route, no runbook file in the repository. The only rollback in the codebase is **code-only**: `updater/updater.php:140-159` copies the pre-update code tree back on a failed on-prem self-update, and `:252-269` writes that backup to `storage/app/updates/backup_<stamp>`. It does **not** restore the database — and it runs migrations at step 9 (`:310`), which the rollback cannot undo | `updater/updater.php:12-18, 132-159, 252-269, 294-319` |
| **Has restore been proven?** | **No.** No automation, no test, no documented drill. `tests/` contains 8 Feature and 8 Unit test files (listed in §8.4); none touches backup or restore | `find tests -name '*.php'` |
| **Can the operator see backup health?** | **No.** No `notifications` block, no `notifications.mail.to`, no `backup:monitor` on the schedule, `tries=1` with `retry_delay=0`, and no `->onFailure()` on either scheduled entry. The one hint the product gives is `.env.production.example:62-63` describing `SMARTPRS_OPS_EMAIL` as the *"backup-failure + daily error-digest recipient"* — but that variable is read **only** by `ErrorDigest.php:25` (verified by grep). Nothing wires it to backup notifications | `config/backup.php:83-92`; `routes/console.php:156-164` |
| **Hard dependency** | The PHP `zip` extension. `config/backup.php:15-17`: *"Without it backups can't run."* Nothing at run time checks this or reports its absence | `config/backup.php:15-17` |

**Net position: an unencrypted, on-box archive of every secret in the system, with unknown
retention, no monitoring, no retry, and no proven restore.**

---

#### Q6 — Logging

**Configuration.** `config/logging.php` is the **stock Laravel 11 file, unmodified** — no
SmartPRS channel, formatter or processor was added.

| Channel | Destination | Level | Rotation | In use? |
|---|---|---|---|---|
| `stack` (default) | `explode(',', env('LOG_STACK','single'))` → `single` | — | — | **yes**, `ignore_exceptions => false` (`:59`) |
| `single` | `storage/logs/laravel.log` | `env('LOG_LEVEL','debug')` | **none — one unbounded file** | yes (the only stack member) |
| `daily` | dated files, `days => env('LOG_DAILY_DAYS', 14)` | `LOG_LEVEL` | 14 days | **no** — not in the default stack, and `LOG_STACK` is unset in both templates |
| `slack` | `LOG_SLACK_WEBHOOK_URL` | `critical` | — | no (URL never set) |
| `papertrail`, `stderr`, `syslog`, `errorlog` | — | — | — | no |
| `deprecations` | → `null` | — | — | **discarded** (`:35`) |

**Rotation and retention: NOT IMPLEMENTED.** The `daily` channel with its 14-day window
exists and is not selected. `laravel.log` grows without bound. Nothing in
`routes/console.php` prunes logs. `config/backup.php:37` **excludes** `storage/logs` from
backups, so nothing is recoverable after a server loss.

**Level conflict:** `.env.example:12` sets `LOG_LEVEL=debug`; `.env.production.example:24`
sets `warning`. `composer.json:51-53` copies `.env.example` on `post-root-package-install`,
so the on-prem/Laragon path ships **`debug`** — at which every unhandled exception writes a
full stack trace including bound query parameters.

**What is logged.** 83 `Log::` calls in `app/`: **64 `warning`, 13 `info`, 6 `error`**
(verified by grep). **No `Log::debug`, no `Log::critical`, no `Log::alert`, no
`Log::emergency`, no `Log::channel()`.** Laravel's `logger()` helper is never used. Nine
sites use `report($e)`, which routes the **full exception with stack trace** to the same
stack: `ETimeOfficeService.php:436, 546, 647, 674`, `PunchIngestService.php:207, 277, 292`,
`SmarteptWebhookController.php:163`, `BiometricConfigController.php:680` — i.e. the
attendance-ingest hot paths.

**Nothing logs authentication.** `grep -n "Log::" app/Http/Controllers/AuthController.php`
returns **no matches**. There is no log line for a successful login, a failed login, a
lockout, a password change, a role change, a permission denial, or a 403.

**Every place PII or a secret is written to a log:**

| # | What | Where | Severity |
|---|---|---|---|
| 1 | **Administrator email addresses** — `'by' => $request->user()?->email` | `ApiKeyController.php:170, 195, 294, 333, 355`; `BiometricConfigController.php:254` | LOW — verified in source |
| 2 | **Employee codes and dates** — `LateArrivalService.evaluate(<empCode>,<date>)` | `LateArrivalService.php:155` | LOW in isolation; combined with `sbb.*` timestamps it forms a movement record for a named worker |
| 3 | **Employee code + device PIN** — `sbb.pending.replayed {tenant, device_user_id, emp_code, promoted}` | `PunchIngestService.php:281-286` | LOW |
| 4 | **Employee code + device code** — `attendance.pending.promoted` | `ETimeOfficeService.php:552-558` | LOW |
| 5 | **Device serial numbers** — `sbb.ingest.batch {device_sn: first 5}`, `biometric.device.assigned {serial}` | `PunchIngestService.php:186`; `BiometricConfigController.php:252` | LOW |
| 6 | **Client IP** on a failed webhook signature | `SmarteptSignature.php:62` | **Appropriate** — exactly where you want it |
| 7 | **Full SmartEPT webhook slug** at creation (one half of the endpoint address `/webhooks/smartept/{slug}`; the HMAC secret is the other half and is **not** logged) | `ApiKeyController.php:291` | LOW |
| 8 | **Recipient email addresses, indirectly** — nine `Log::warning` calls interpolate `$e->getMessage()` from Symfony Mailer, which routinely embeds the failing recipient | `MailService.php:112, 121`; `NotificationService.php:131, 199`; `LateArrivalService.php:296`; `SignupController.php:669`; `LeadController.php:149`; `SchemeController.php:595, 721` | LOW |
| 9 | **SQL statements and bound values** — `Log::error('master.list failed', ['error' => $e->getMessage()])` and every `Log::warning('X failed: '.$e->getMessage())` on a `QueryException` | `MasterController.php:697, 829`; `LeadController.php:91`; `EmployeeImportService.php:152, 458`; and 60-odd more of the same shape | **HIGHEST real exposure.** A failed insert on `employees` puts the offending row's PAN, bank account, salary and DOB into the log file. At `LOG_LEVEL=debug` (the on-prem default) every unhandled exception writes a stack trace with arguments |
| 10 | **Log content leaves the server by email, daily** — `errors:digest` mails the 10 most recent ERROR-class lines, 240 chars each, to `SMARTPRS_OPS_EMAIL` | `ErrorDigest.php:76-93` | **The only egress path for log content, and it carries exactly the lines identified in #9** |

**What is verifiably NOT logged** (I checked each): passwords and hashes (zero `Log::`
calls in `AuthController`); API-key secrets (only the non-credential `prefix`, with
`ApiKeyController.php:174-175` commenting *"Shown ONCE. Flashed, not stored."*); Razorpay
credentials; PAN/Aadhaar/UAN/bank numbers as values (validation messages are rule-shaped —
a deliberate and good design); salary figures (payroll `Log::warning` calls carry ids only:
`PayrollGenController.php:2734` a ledger id, `:2800` a loan id, `:2051` a headcount);
whole request payloads (`grep "request->all()" | grep Log` → no matches); session contents.

**Database-resident logs that behave as logs and are never pruned:**
`mail_log` (recipient + subject + transport error, no body — the right call);
`wa_log` (**mobile + `body_values`**, see SEC-L-03); `notification_log`;
`subscription_alerts`; `client_updates`; `activity_logs`. **No pruning job exists for any
of them**, and `activitylog:clean` — which would enforce
`config/activitylog.php:14`'s 365-day retention on `activity_log` — is not on the schedule.

---

#### Q7 — Correlation id, metrics, health endpoint, alerting

| Capability | Exists? | Detail |
|---|---|---|
| **Correlation / request id** | **NO** | Grep for `request_id`, `X-Request-Id`, `correlation`, `trace_id` across `app/`, `bootstrap/`, `routes/` returns nothing. `bootstrap/app.php:44-46` is an **empty** `withExceptions` closure, so there is no `->report()` hook attaching a tenant id, a user id or a request id to any logged exception. Every log line is an orphan. `app/Providers/AppServiceProvider.php` is likewise empty (`register()` and `boot()` both contain only `//`), so there is no Monolog processor either |
| **Metrics** | **NO** | No counters, no timers, no StatsD/Prometheus/OpenTelemetry. Nothing measures duration, throughput, queue depth or error rate |
| **Health endpoint** | **YES — one, and it is nearly useless** | `bootstrap/app.php:14` registers Laravel's stock `health: '/up'`. It reports that the framework boots. It says nothing about the scheduler, the worker, queue depth, the last backup, biometric sync freshness or mail delivery. A monitoring system polling `/up` would have reported the system green throughout every failure described in this document |
| **Secondary health signals that exist but are not health checks** | partial | `biometric_configs.last_sync_at` / `last_count` (visible on one screen, no alert); `mail_log.status` (one screen, 200 rows, no alert); `subscription_alerts` (a SaaS admin screen); `notification_log.dedupe_key`; `/app/updates/status` for the on-prem self-updater; `public/update-status.php`, a boot-free progress poller used only during a self-update |
| **Alerting** | **ONE mechanism, structurally broken** | `errors:digest` (`ErrorDigest.php`), daily at 07:45. It (a) returns SUCCESS and sends nothing if `SMARTPRS_OPS_EMAIL` is unset; (b) scans only ERROR/CRITICAL/ALERT/EMERGENCY while every scheduled service logs at `warning` and production sets `LOG_LEVEL=warning`; (c) matches `str_contains($line, $lvl.':')` on the bare word, so any log **message** containing "ERROR:" is over-counted (`:62`); (d) scans only the last 2 MB / 4000 lines of an unrotated, unbounded file; (e) has no dedupe, so two runs send two identical emails; (f) is **itself delivered through the queue whose failure it cannot detect**; and (g) has no try/catch — a permissions problem on the log file aborts the task and the error goes to a discarded stream |
| **APM / exception monitoring** | **NO** | No Sentry, no Bugsnag, no Flare, no Telescope. Verified: `composer.json` requires only dompdf, framework, sanctum, tinker, livewire, excel, predis, zkteco, activitylog, backup, permission |
| **Custom error pages** | **NO** | There is no `resources/views/errors/` directory. Users see Laravel's stock 403/404/419/500 |

---

#### Q8 — Scheduled tasks encoding BUSINESS RULES a customer would want to configure

Every one of these is a literal in a `.php` file today. **This is the core SmartPRS 3
requirement set for this area.**

| # | Business rule | Hard-coded value | Location | Why a customer wants it different |
|---|---|---|---|---|
| 1 | Compliance alert window | **30 days** | `ComplianceController.php:31` | An NBFC with a 90-day DRA renewal cycle needs 90 |
| 2 | Compliance "critical" bucket | **≤ 7 days** | `ComplianceController.php:80` | Escalation policy differs per client |
| 3 | Compliance digest size | **top 25 items** | `ComplianceController.php:206` | A 2,000-agent client sees 25 of 300 expiries |
| 4 | Compliance digest audience | **`['super_admin','admin','hr_manager']`** | `ComplianceController.php:256` | Customers have a Compliance Officer role |
| 5 | Compliance scan time | **07:30** | `routes/console.php:144` | Multi-shift operations want it before the first shift |
| 6 | Subscription reminder ladder | **`[15, 7, 3, 1, 0]`** | `SubscriptionAlerts.php:35` | A reseller's contract says 30/14/7/1 |
| 7 | Subscription grace period | **7 days** | `SubscriptionService.php:34` | Grace length is a commercial term negotiated per contract |
| 8 | Auto-suspend after grace | **always, unconditionally** | `SubscriptionAlerts.php:60-69` | An enterprise customer will not accept automatic suspension at all |
| 9 | Subscription alert time | **08:00** | `routes/console.php:180` | — |
| 10 | Probation reminder milestones | **`[30, 15, 7, 0]`** | `ProbationService.php:36` (default) | Configurable per tenant already — but the **default** is code |
| 11 | Probation default duration | **6 months** | `ProbationService.php:35` | Statutorily and contractually variable (3 / 6 / 12) |
| 12 | Probation reminder audience | **`['admin','hr_manager','hr','super_admin']`** | `ProbationService.php:250-253` | **Not configurable at all** |
| 13 | Probation notify time | **07:15** | `routes/console.php:254` | — |
| 14 | Overdue probation nudge fires **once**, not repeatedly | dedupe base `probation:{empId}:{end}:overdue` | `ProbationService.php:215` | A probation left unconfirmed for months is nudged once and then goes quiet — customers expect escalating chasing |
| 15 | Absence sweep default hour | **11:00** | `AbsenceService.php:38` | Per-tenant configurable; the default is code |
| 16 | Absence feature default | **`enabled = false`** (opt-in) | `AbsenceService.php:37` | — |
| 17 | Default working-week pattern | **`[1,2,3,4,5,6]`** (Mon–Sat working) | `AbsenceService.php:44-45` | A 5-day office client is defaulted to marking every Saturday absent |
| 18 | Absence regularise deep link | **`'att-correction'`** | `AbsenceService.php:46` | — |
| 19 | Greetings default hour | **09:00** | `GreetingService.php:36` | Configurable; default is code |
| 20 | Anniversary `min_years` | **1** | `GreetingService.php:47` | Some clients celebrate only 5/10/15-year milestones |
| 21 | Greeting/absence/probation dedupe granularity | **calendar year** for greetings, **(employee, milestone, end-date)** for probation | `GreetingService.php:195, 217`; `ProbationService.php:220` | — |
| 22 | Biometric pull window | **`--days=1`** | `routes/console.php:225` | **The single highest-impact hard-coded value in this area.** See DEF-L-01 |
| 23 | Biometric scheduler tick | **every 5 minutes** | `routes/console.php:226` | — |
| 24 | Per-device sync interval fallback | **60 minutes** when the column is 0/null | `SyncETimeOffice.php:65-67` | — |
| 25 | Late-arrival email applies to **today or yesterday only** | `$days < 0 \|\| $days > 1` | `LateArrivalService.php:96-98` | A client backfilling a week of punches gets no late notices |
| 26 | Backup times | **01:30 clean, 02:00 run** | `routes/console.php:157, 162` | Must fit the customer's maintenance window |
| 27 | Backup retry policy | **1 try, 0 delay** | `config/backup.php:83-84` | — |
| 28 | Backup destination | **local disk only** | `config/backup.php:72-74` | Every serious customer needs off-box |
| 29 | Backup include/exclude set | literal arrays | `config/backup.php:29-38` | A customer with documents under `storage/app` is silently unprotected |
| 30 | Error-digest severity set | **`['ERROR','CRITICAL','ALERT','EMERGENCY']`** | `ErrorDigest.php:48` | The customer's own ops team wants WARNING |
| 31 | Error-digest detail volume | **10 hits, 240 chars each** | `ErrorDigest.php:76, 79` | — |
| 32 | Error-digest scan window | **2 MB / 4000 lines** | `ErrorDigest.php:46, 108` | — |
| 33 | Error-digest time | **07:45** | `routes/console.php:167` | — |
| 34 | Transfer apply time | **06:30** | `routes/console.php:191` | Must land before the first shift's attendance |
| 35 | Which fields a company transfer clears | `reporting_manager`, `team_leader`, `team`, `reporting_manager_id` | `TransferService.php:150-157` | A group with dotted-line reporting across companies loses it, unrecoverably |
| 36 | Transfer type classification | substrings **`'compan'`, `'depart'`** | `TransferService.php:126-127` | Must be an enum |
| 37 | Demo reset cadence | **every 15 min + 03:30 daily, unconditional** | `routes/console.php:204-211` | **Must be switchable off entirely.** See SEC-L-01 |
| 38 | Demo password | **`smartprs-demo`** | `DemoReset.php:35` | — |
| 39 | Demo subscription shape | **75 seats, 3 companies, 12 months, ₹0** | `DemoReset.php:85-90` | — |
| 40 | On-prem provisioned seat cap | **100000** | `ClientProvision.php:74` | — |
| 41 | Queue name | **`'mail'`** | `SendNotification.php:41`; `NotifyLateArrivals.php:43` | A customer wanting a priority lane for payslips cannot have one |
| 42 | Job retry policy | **3/30 s** and **2/30 s** | `SendNotification.php:33-34`; `NotifyLateArrivals.php:35-36` | — |
| 43 | Which mail bypasses the queue | the `'sync' => true` flag, decided by each caller in code | `MailService.php:107` | — |
| 44 | Overlap-lock TTL | **Laravel's 24-hour default**, no argument passed | `routes/console.php:143-266` | A 5-minute task must not hold a 24-hour lock |
| 45 | WhatsApp welcome carries the plaintext password | **default `true`** | `SignupController.php:413` | Security-conscious customers must be able to default it off |

---

#### Q9 — Blast radius when the scheduler or the queue worker is not running

On-prem operators very often do not set these up — and the repository gives them no help:
the cron entry and the worker unit live in a `deploy/` directory **that is not in this
repository** `[UNVERIFIED — deploy/ absent]`, and the README's own quickstart
(`README.md:50-51`) tells the developer to run `php artisan queue:work` and
`php artisan schedule:work` in *"separate terminals"*, i.e. by hand.

**A) The scheduler (`schedule:run`) is not running — customer-visible consequences by feature:**

| Feature | What the customer experiences | Time to hurt |
|---|---|---|
| **Biometric attendance** | Punches stop importing. Because the scheduled window is hard-coded `--days=1`, **an outage longer than 24 hours loses punches permanently** — the next successful run asks only for the last day, and recovery requires a manual `--from/--to` run *and* the upstream device still retaining them. Missing punches become missing pay | **1 hour**; unrecoverable after **24 hours** |
| **Payroll** | Computed from an incomplete attendance register. LOP is over-stated, overtime under-paid | at month-end |
| **Backups** | Stop that night. No archive is written, no error appears. Discovered during an attempted restore — which itself does not exist | **1 night** |
| **Subscription lifecycle** | No expiry reminders at 15/7/3/1/0, none during grace. Because the milestone match is **exact equality** (`in_array($days,[15,7,3,1,0],true)`), a milestone missed is a milestone lost forever. When the scheduler comes back after >7 days past expiry, the tenant is **suspended on the first run, having received no warning at all** | 15 days out |
| **Compliance (DRA/PCC/BGV)** | HR receives no expiry digests. **Field agents work with expired certification** — a regulatory exposure under RBI recovery-agent norms, which is exactly what the product markets itself on | **1 day** |
| **Probation** | Milestones pass unnoticed. Exact-equality matching means a missed day loses the reminder permanently — including the "completes today" (0-day) reminder. Because probation suppresses PF/PT/TDS, the employee stays on the wrong statutory treatment | at the first missed milestone |
| **Transfers** | Future-dated approved transfers never apply. The employee stays in the old company/branch, and that day's attendance and payroll treat them as belonging there | on the effective date |
| **Absence notices** | Employees are never told they have no attendance for yesterday, so nothing is regularised, so the LOP lands unexplained on the payslip. There is **no catch-up** — the schedule only ever evaluates `now()->subDay()` | **1 day** |
| **Greetings** | Silently stop. Year-scoped dedupe means a missed birthday is never sent | **1 day** |
| **Demo privacy** | A prospect's typed data is never erased and the next visitor sees it, contradicting the product's own promise | **15 minutes** |
| **Error digest** | The one alerting mechanism stops — so **the failure of the scheduler silences the only thing that could have reported the failure of the scheduler** | **1 day** |

**B) The queue worker is not running (and `QUEUE_CONNECTION` is not `sync`):**

Rows accumulate in `jobs` (or Redis) indefinitely. `mail_log.status` stays `queued`
forever. **No error, no log line, no `failed_jobs` row, no alert.** Every one of these
silently never happens:

1. Compliance (DRA/PCC/BGV) digests to HR — `ScanCompliance` → `MailService::queue`
2. Probation milestone reminders to HR and managers
3. Previous-day absence notices to employees
4. Birthday and work-anniversary greetings
5. **Subscription expiry warnings at 15/7/3/1/0 days and through grace**
6. The daily ops error digest — *the alerting channel itself*
7. Transfer-applied notices to employees
8. Renewal invoices (manual `billing:renew` path)
9. Late-arrival emails from the SBB push path (the scheduled-pull path is inline and still sends)

**Three compounding facts make this the worst failure mode in the product:**

- **The auto-suspend write is direct, not queued** (`SubscriptionAlerts.php:65`). So a
  paying customer is **locked out of a live HR system having received none of the five
  warning emails that were supposed to precede it.**
- **The audit trail lies.** `MailService::queue()` returns the `mail_log` id the moment the
  row is inserted (`MailService.php:119`), before any delivery attempt.
  `SubscriptionAlerts.php:107-108` records `subscription_alerts.status = 'sent'` on the
  basis of that id. **The system positively asserts that an email was sent when it was only
  enqueued.**
- **The emails an administrator would test still work.** Credential and password-reset mail
  is sent inline via `'sync' => true` (`MailService.php:107-116`). So the diagnostic an
  operator would naturally run comes back green.

**C) On Redis specifically:** if Redis is restarted without persistence, **every queued
notification is silently destroyed** — no `failed_jobs` row, no trace. And because
`onOneServer()`/`withoutOverlapping()` locks live in the cache store, losing Redis also
**stops the entire schedule**, on top of logging every user out (sessions).

**D) Starting a worker after a long outage** drains the whole backlog at once, sending
months of stale notifications — expired subscription warnings, greetings for birthdays
long past. There is no age check, no `retryUntil()`, no `ShouldBeUnique` on either job.

---

### 6.2 Defect register

| ID | Defect | Evidence | Business impact | Severity |
|---|---|---|---|---|
| DEF-L-01 | **Biometric sync window is hard-coded `--days=1`**, so a scheduler outage >24 h loses punches permanently | `routes/console.php:225`; `SyncETimeOffice.php:41-42` | Missing punches → wrong LOP → wrong pay. Not recoverable without manual intervention and upstream retention | **CRITICAL** |
| DEF-L-02 | `withoutOverlapping()` passed **no expiry**, so Laravel's 24-hour default applies to a 5-minute task | `routes/console.php:143-266` (12 sites) | A crashed sync blocks itself for a full day; recovery (`schedule:clear-cache`) is undocumented | HIGH |
| DEF-L-03 | **Whole-tenant-loop try/catch abandons remaining tenants**, then exits SUCCESS with an under-count | `AbsenceService.php:88, 133-135`; `GreetingService.php:129, 156-158` | Silent partial coverage indistinguishable from complete coverage. Contrast `SubscriptionAlerts.php:135-137`, which does it correctly | HIGH |
| DEF-L-04 | `ComplianceController::notify()` has **no try/catch at all** — one bad tenant aborts every remaining tenant's digest | `ComplianceController.php:188-245` | Agents in the field with expired DRA/PCC | HIGH |
| DEF-L-05 | **Milestone matching uses exact day equality**, so a missed run loses the reminder permanently | `ProbationService.php:190`; `SubscriptionAlerts.php:56`; `GreetingService.php:145`; `AbsenceService.php:105` | Highest impact on `expiry-1` (last warning before grace) and probation `0` ("completes today"). A `<=` comparison deduped on the existing ledger key would be idempotent **and** catch up | HIGH |
| DEF-L-06 | **The one alerting mechanism is blind to the failure mode the scheduled services use** — services log at `warning`, the digest scans for ERROR+, and production sets `LOG_LEVEL=warning` | `ErrorDigest.php:48`; `.env.production.example:24`; `AbsenceService.php:134`; `GreetingService.php:157`; `ProbationService.php:165`; `MailService.php:112, 121`; `NotifyLateArrivals.php:52` | Every silent failure stays silent | HIGH |
| DEF-L-07 | **The error digest is delivered through the queue whose failure it cannot detect** | `ErrorDigest.php:82` → `MailService::queue` → `SendNotification::dispatch` | Circular dependency in the only alerting path | HIGH |
| DEF-L-08 | **Scheduled tasks and the mail pipeline perform DDL on every run**, outside migrations, each inside a swallowing catch | `ProbationService::ensureSchema()`; `SubscriptionService::ensureAlertTable()` (`SubscriptionAlerts.php:39`); `MailService::ensureTable()` (`:75`); `WaService::ensureLog()`; `DemoReset.php:76-80`; `ClientProvision.php:81-86`; `PayrollGenController.php:2556-2616` | On a correctly-hardened DB user the product degrades feature by feature with no error. MySQL DDL implicitly commits, breaking surrounding transactional intent (acknowledged in the comment at `PayrollGenController.php:2554-2556`) | **CRITICAL** |
| DEF-L-09 | **The job created to fix inline late-arrival mail is used on only one of the two paths** | `PunchIngestService.php:205, 290` (queued) vs `ETimeOfficeService.php:777` (inline) | A slow SMTP host stalls the scheduled biometric sync while it holds a 24-hour overlap lock. And `LateArrivalService.php:289` uses `Mail::raw` on the **default** mailer, so those emails have **no per-company SMTP, no branding, and no `mail_log` row** | HIGH |
| DEF-L-10 | `NotifyLateArrivals` **can never fail or retry** — `handle()` swallows every `\Throwable`, making `$tries = 2` and `$backoff = 30` dead configuration | `NotifyLateArrivals.php:35-36, 48-53` | A transient SMTP failure loses the notification on the first attempt and it never reaches `failed_jobs` | MEDIUM |
| DEF-L-11 | **A missing SMTP configuration produces no exception, no retry and no `failed_jobs` row** — the job completes successfully having sent nothing | `MailService.php:148-152` | The only evidence is a `mail_log` row at `skipped` that nothing monitors | HIGH |
| DEF-L-12 | `NotificationService::send()` returns `null` on **both** "deduped" and "failed" | `NotificationService.php:92, 130-133` | A caller cannot tell a suppressed duplicate from a lost notification | MEDIUM |
| DEF-L-13 | **`subscription_alerts.status='sent'` records enqueueing, not delivery** | `SubscriptionAlerts.php:107-108`; `MailService.php:119` | The audit trail positively asserts delivery that never happened | HIGH |
| DEF-L-14 | **A stuck transfer retries forever with no alert**, and the printed count is "applied", not "attempted" | `TransferService.php:65-69, 138-139`; `ApplyTransfers.php:26` | An employee never moves; nobody is told. Per-row errors go to stdout, which cron discards | MEDIUM |
| DEF-L-15 | **A company transfer nulls the reporting hierarchy without snapshotting it** — only `from_company`/`from_branch` are recorded | `TransferService.php:150-157` vs `:184-193` | An erroneous transfer permanently destroys the reporting line | MEDIUM |
| DEF-L-16 | `client:provision` is **not transactional**; the idempotency guard is on the admin email, not "does a tenant exist" | `ClientProvision.php:66-108, 57-61` | A failure after the tenant insert but before the user insert leaves an orphan tenant + company, and the guard does not fire on re-run — producing a **second tenant on a single-tenant on-prem install** | MEDIUM |
| DEF-L-17 | `attendance:dedupe` **loads the whole attendance table into PHP memory**, unchunked | `DedupeAttendance.php:42-45` | OOM on a large history. Deletion is chunked but **not transactional** (`:81-83`), so a mid-run error leaves a partially deduplicated table | MEDIUM |
| DEF-L-18 | **Compliance alerts have no lower bound** — a certificate that expired years ago is reported every day forever, crowding actionable items out of the top-25 list | `ComplianceController.php:77-79, 206` | Alert fatigue → the digest is ignored | MEDIUM |
| DEF-L-19 | **DRA expiry is double-counted** — once from `employees.dra_expiry`, once from the joined `dra_certs.expiry` | `ComplianceController.php:102, 122` | Inflated bucket totals; the same person listed twice | LOW |
| DEF-L-20 | **Grace reminders do not check subscription status** — a `cancelled` subscription past its end date gets daily "please renew" emails for 7 days | `SubscriptionAlerts.php:56-59` (the pre-expiry branch checks `status === 'active'`; the grace branch does not) | Customer-facing embarrassment | MEDIUM |
| DEF-L-21 | **WhatsApp is called synchronously inside a scheduled task**, 15 s timeout, per tenant, while holding a 24-hour overlap lock | `SubscriptionAlerts.php:112-132`; `WaService.php:146` | 200 tenants due on the same day = up to 50 minutes of a single held lock | MEDIUM |
| DEF-L-22 | **The default log channel is `single` — one unrotated, unbounded file** — and it is excluded from backups | `config/logging.php:57`; `config/backup.php:37` | Unbounded disk growth; the digest's 2 MB tail covers a shrinking time slice; no forensic trail survives a server loss | MEDIUM |
| DEF-L-23 | **`failed_jobs` has no reader and no pruning**; `job_batches` is dead schema; no `Queue::failing()` listener exists anywhere | `config/queue.php:88-110`; grep for `Queue::` → zero matches | Failures are recorded and never surfaced; the table grows without bound | HIGH |
| DEF-L-24 | **`config:cache` freezes `APP_TIMEZONE` and `ETIMEOFFICE_ENABLED`**; the codebase already knows this and worked around it in exactly one place | `.env.production.example:5-6`; `routes/console.php:228`; `config/app.php:68`; `Edition::current()` reads `.env` directly with a comment explaining why | Editing `.env` on a live server has no effect on the schedule's timezone until `config:clear` | MEDIUM |
| DEF-L-25 | **The error-digest level filter over-matches** — `str_contains($line, $lvl.':')` matches the bare word anywhere in a line | `ErrorDigest.php:62` | A business string containing "ERROR:" inflates the digest | LOW |
| DEF-L-26 | **`config/backup.php` hard-codes the database connection name `'mysql'`** while `config/queue.php:89, 108` fall back to `'sqlite'` | `config/backup.php:45-46`; `config/queue.php:89, 108` | An install on a differently-named connection backs up **no database at all**, silently | MEDIUM |
| DEF-L-27 | **The entire operational runtime lives outside the repository.** No `deploy/` directory, no cron file, no worker unit, no runbook. The README's quickstart tells the operator to run the worker and scheduler by hand in "separate terminals" | verified absent by `find`; `README.md:50-51` | Nothing in a release enforces or verifies that the scheduler and worker are installed. The most likely production failure has no artefact to prevent it | **CRITICAL** |
| DEF-L-28 | **The README is stale** — it describes SmartPRS as a "Phase 0 foundation / scaffold" with an unchecked checklist and seeded logins with password `password`, describing a product that shipped rev 190 | `README.md` (whole file) | An operator following the README does the wrong thing | MEDIUM |
| DEF-L-29 | **`activitylog:clean` is not scheduled**, so `config/activitylog.php:14`'s 365-day retention on `activity_log` never runs. No pruning job exists for `mail_log`, `wa_log`, `notification_log`, `subscription_alerts`, `client_updates` or `failed_jobs` either | `routes/console.php` (no entry); `config/activitylog.php:14` | Unbounded growth of six log tables, two of which contain PII and one of which contains credentials | MEDIUM |
| DEF-L-30 | **Grace-end date shown to the customer is one day earlier than the enforcement** | `SubscriptionAlerts.php:80` (`$end + 7`) vs `:60` (suspend when `days < -7`, i.e. from day −8) | A customer told "suspends on the 17th" is actually suspended on the 18th | LOW |
| DEF-L-31 | **A read-only-looking predicate performs writes** — `resetDue()` flips `demo_requests.status` to `expired` before answering | `DemoReset.php:170-171` | Passes are expired even when the reset is declined; an undocumented side effect of a question | LOW |
| DEF-L-32 | **`billing:renew` returns SUCCESS with errors**; invoice creation and period advance are **not atomic** | `RenewSubscriptions.php:35`; `BillingController.php:1003-1013` | Unusable as an automated check; a failure between the two raises a **duplicate GST invoice** on the next run. Contrast `PayrollPeriodAudit.php:130`, which correctly returns FAILURE on a data condition | MEDIUM |
| DEF-L-33 | **`payroll:period-audit` detects a money-affecting condition (days paid twice or never paid) and exits non-zero — and is not on the schedule.** It runs only when a human remembers | `PayrollPeriodAudit.php:127-134`; absent from `routes/console.php` | The one genuine control in the command set is not wired to anything | MEDIUM |
| DEF-L-34 | **Zero automated tests** for the scheduler wiring, either job, `DemoReset`, `SubscriptionAlerts`, `TransferService::applyDue`, `SyncETimeOffice`, `ErrorDigest`, backups, or any of the 18 console commands | `tests/` contains 8 Feature + 8 Unit files; only `AbsenceServiceTest`, `GreetingServiceTest`, `ProbationServiceTest` touch this area, all at the service layer | Every defect above could have been caught by a test that does not exist | HIGH |

---

### 6.3 The silent-failure census, in full

(Method and totals in §Q3.) Full per-file distribution of **fully silent** catch blocks
(EMPTY + RETURN_ONLY), top 30 of 63 files:

```
31 app/Http/Controllers/PayrollGenController.php     8 app/Http/Controllers/SaasController.php
22 app/Http/Controllers/RequestController.php        7 app/Services/NotificationService.php
21 app/Http/Controllers/AppDataController.php        7 app/Http/Controllers/MasterController.php
16 app/Http/Controllers/SignupController.php         7 app/Http/Controllers/BillingController.php
13 app/Http/Controllers/ClientUpdateController.php   7 app/Http/Controllers/ApprovalService.php
12 app/Http/Controllers/AuthController.php           6 app/Services/AbsenceService.php
11 app/Services/LicenseService.php                   6 app/Services/EmployeeImportService.php
10 app/Http/Controllers/OnpremClientController.php   6 app/Http/Controllers/BiometricConfigController.php
 8 app/Services/ProbationService.php                 6 app/Http/Controllers/SchemeController.php
 8 app/Http/Controllers/SelfOnboardingController.php 6 app/Console/Commands/DemoReset.php
 8 app/Http/Controllers/DemoAccessController.php     6 app/Console/Commands/SeedIndustryContent.php
 5 app/Services/LateArrivalService.php               5 app/Services/GreetingService.php
 5 app/Services/SubscriptionService.php              5 app/Http/Controllers/RecruitMessagingController.php
 5 app/Http/Controllers/InstallController.php        5 database/migrations/2026_08_28_000100_employee_field_parity.php
 4 app/Services/AttendanceCorrectionService.php      4 app/Services/ETimeOfficeService.php
```

Also present, and worth naming separately because they sit in the security layer:
`app/Http/Middleware/` has **7 typed catch blocks, 6 of them empty and 0 logging anything**
(`LicenseGate.php:69, 73`; `EditionGuard.php:55`; `DemoWriteGuard.php:73`;
`EnsureSubscriptionActive.php:58, 79`).

---

## 7. Security findings

| ID | Finding | Evidence | Exploitability | Business impact | Severity |
|---|---|---|---|---|---|
| **SEC-L-01** | **A nightly scheduled task creates administrator accounts with a publicly documented password on every install.** `Schedule::command('demo:reset')->dailyAt('03:30')` has no environment guard, no `->when()`, and `DemoReset` never reads `APP_ENV` or `SMARTPRS_DEMO_DATA` (verified by grep of the file). On any server running the scheduler, if no tenant with `subdomain='demo'` exists, one is **created**, along with `demo-admin@smartprs.in` (Spatie role **`admin`**), `demo-hr@smartprs.in` and `demo-field@smartprs.in`, each `status='active'` with `password = Hash::make('smartprs-demo')` — the option default. The password is in the class docblock at `:26` and printed to stdout on every run at `:152`. If those emails already exist under **another** tenant, they are **taken over**: the lookup at `:106` is `whereRaw('LOWER(email) = ?')` with **no tenant scope**, and the update at `:108-111` reassigns `tenant_id` and overwrites the password | `routes/console.php:208-211`; `DemoReset.php:35, 54-65, 101-123, 152` | Trivial — the credential is published in the product's own source | Every deployment following the documented cron instructions gains, within 24 hours, an active admin-role login with a known password. Nightly re-creation defeats manual deletion; nightly password reset defeats a manual password change. `.env.production.example:72-77` documents a production-safety story built on `SMARTPRS_DEMO_DATA=false` that **this command does not consult** | **CRITICAL** |
| **SEC-L-02** | **The demo seeder falls back to wiping the first tenant in the database.** `DummyDataSeeder::run()` resolves its target as `tenants.subdomain='demo'` **`?: tenants->orderBy('id')->value('id')`**, then hard-`DELETE`s that tenant's rows from `employees`, `teams`, `branches`, `designations`, `departments`, `employee_references`, `payroll_runs`, `payslips`, `attendance_logs`, `attendance_ratings`, `transfers`, `onboarding`, `leaves`, `commissions`, `commission_payments`, `commission_logs`, `expenses`, `advances`, `loans`, `clawbacks`, `increments`, `exits`, `bonus_encashment` | `database/seeders/DummyDataSeeder.php:46-47, 79-95` (verified in source) | The `demo:reset` path defuses it (`DemoReset.php:54-65` creates the demo tenant first). The exposure is a direct `php artisan db:seed --class=DummyDataSeeder` on a database with no `demo` tenant | Irreversible destruction of a real customer's people, attendance, payroll history, payslips, leave, commissions, loans, advances and exits — with the only backup on the same server (SEC-L-06) | **HIGH** |
| **SEC-L-03** | **`wa_log.body_values` is a permanent, unencrypted, unpruned store of one-time passcodes and first-login passwords.** `WaService::sendTemplate()` writes `json_encode(array_values($opts['bodyValues']))` to `wa_log` on **every** send (`:171`) and on **every** demo-muted send (`:126`). `SignupController.php:413-425` puts the tenant admin's **plaintext temporary password** in `bodyValues[4]` whenever `SMARTPRS_WA_SEND_PASSWORD` is truthy — and it **defaults to `true`** (`filter_var(env('SMARTPRS_WA_SEND_PASSWORD', true), …)`) | `WaService.php:26-40, 126, 166-176`; `SignupController.php:413, 424`; `:1177` | Any DB read access; any holder of a backup zip | Plaintext credentials at rest, retained forever, inside the nightly unencrypted backup | **HIGH** |
| **SEC-L-04** | **`--force` on the key-generation command irreversibly invalidates every issued licence.** `smartprs:make-license-keys --force` overwrites `storage/app/keys/license_private.pem` and `license_public.pem` in place, with no backup and no confirmation prompt. `config/backup.php:35` **excludes `storage/app`**, so no automatic copy of the outgoing key exists anywhere. `file_put_contents` return values are not checked, so a full disk produces a truncated key while the command reports success | `MakeLicenseKeys.php:20, 29-33, 39-49` (verified in source) | Requires shell access, but the guard is a single flag | Every `.lic` file issued to every on-premise customer stops validating. Recovery requires re-issuing every licence **and** rebuilding and redistributing the l1/l2/l3 binaries carrying the new `PUBLIC_KEY`. A multi-week outage of the activation system | **HIGH** |
| **SEC-L-05** | **The demo privacy wipe fails open, silently.** `DemoReset::resetDue()` — the control that decides whether a prospect's typed data must be erased — wraps its entire body in `catch (\Throwable $e) { return false; }`. Any exception means "nothing due"; the wipe is skipped with no log line and no alert | `DemoReset.php:163, 180-182` | Passive | One prospect's test data — which may include real employee names, salaries and client references they typed while evaluating — remains visible to the next visitor to the public demo, contradicting the product's own promise at `routes/console.php:200-203` | **MEDIUM** |
| **SEC-L-06** | **The nightly backup is an unencrypted, on-box archive of every secret in the system.** `base_path()` includes **`.env`** (`APP_KEY`, `DB_PASSWORD`, `MAIL_PASSWORD`, `RAZORPAY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`); the DB dump includes every password hash, `personal_access_tokens`, `api_keys.key_hash`, `wa_log.body_values` (SEC-L-03) and `failed_jobs.payload`. `BACKUP_ARCHIVE_PASSWORD` is unset by default. Destination is `storage/app` on the same server | `config/backup.php:28-38, 72-81`; `.env.production.example:32, 57, 65-66, 95` | Read access to `storage/app` | An attacker with the zip obtains `APP_KEY` — hence the ability to forge sessions and decrypt everything the app encrypts — plus the full database | **HIGH** |
| **SEC-L-07** | **Queued mail payloads carry PII and document attachments in plaintext.** `MailService` supports `attach_b64`, a base64-encoded PDF serialised into `jobs.payload` (`longText`). The same payload carries the recipient's address, name and the full rendered body of every notification — including absence notices and probation reminders, which are personnel data. `failed_jobs` retains them forever with no pruning | `MailService.php:187-193`; `0001_01_01_000002_create_jobs_table.php:17, 42` | Any DB or Redis read access; any backup holder | Pending HR correspondence and attached documents readable at rest with no access control beyond the DB credential — under the DPDP Act 2023, which this product's own training content cites | **MEDIUM** |
| **SEC-L-08** | **Five payment-gateway calls have no timeout at all**, and one integration call has a **900-second** timeout | `SignupController.php:224, 799, 819, 867`; `BillingController.php:593`; `TenantBillingController.php:339`; `OnpremClientController.php:600`; `UpdateClient.php:225` | A hung or hostile endpoint | A hung Razorpay endpoint holds a PHP-FPM worker for `default_socket_timeout` during signup or invoice payment; the update download holds one for 15 minutes. Trivial resource exhaustion | **MEDIUM** |
| **SEC-L-09** | **Log content leaves the server daily by email, and the lines chosen are precisely the ones carrying exception text and SQL bound values.** `errors:digest` mails the 10 most recent ERROR-class lines, 240 chars each, to `SMARTPRS_OPS_EMAIL` — an address that is not necessarily the customer's | `ErrorDigest.php:47-93`; combined with the ~60 `Log::*('… failed: '.$e->getMessage())` sites | Passive | Employee PII and salary figures can leave the customer's premises by SMTP, in plaintext, daily | **MEDIUM** |
| **SEC-L-10** | **`app:clear-state`, `attendance:dedupe` and `attendance:purge-demo` all default to ALL TENANTS with no confirmation, no `--force` and no `APP_ENV` check.** Only `attendance:dedupe` has a `--dry` preview | `ClearAppState.php:30-35`; `DedupeAttendance.php:27, 80-83`; `PurgeDemoAttendance.php:28-33` | Requires shell access; a forgotten `--tenant` is enough | Cross-tenant deletion of state or attendance data — and attendance is the input to payroll | **MEDIUM** |
| **SEC-L-11** | **Historical GST invoice numbers are overwritten with no retained record.** `invoices:renumber-prs` rewrites `invoices.number`, `onprem_clients.invoice_no` and `signups.quote_no` inside a transaction. The previous values are written to no archive column, no audit table and no file — the mapping exists only in the console output at the moment of the run | `routes/console.php:25-132`, specifically the transaction at `:126-130` | Requires shell access | Silently rewriting the identifier of a tax invoice already sent to a customer and filed with their accounts breaks reconciliation and removes the audit trail linking the two numbers | **MEDIUM** |
| **SEC-L-12** | **The on-premise administrator password is passed on the command line**, so it lands in shell history and the process table | `ClientProvision.php:29, 39` | Any other user of that server | Credential exposure. `$this->secret()` exists and is not used | **LOW** |
| **SEC-L-13** | **The licence signing private key is excluded from every backup.** `config/backup.php:35` excludes `storage/app`, which is where `LicenseSigner` keeps `keys/license_private.pem` | `config/backup.php:35`; `MakeLicenseKeys.php:25-26, 47-49` | n/a — availability, not confidentiality | Good for exposure, catastrophic for recovery: **losing that server loses the ability to issue or re-issue any licence.** Combined with SEC-L-04 there is no path back | **HIGH** (availability) |
| **SEC-L-14** | **Five authorization/entitlement controls fail OPEN on any exception, deliberately, with comments saying so** | `LicenseGate.php:73-75` (`return $next($request)`); `EditionGuard.php:55-57` (`// fail open — a licence check must never take the product down`); `DemoWriteGuard.php:73-75` (`// fail open — never break real tenants over the demo check`); `EnsureSubscriptionActive.php:58-59` (empty catch around the disabled-account logout); `SubscriptionService.php:116-118` (`// fail-soft → unlimited`) | Any condition that makes the gate throw — a DB blip, a missing table, a `TypeError` | Unlicensed access to a paid product; a Basic-edition tenant reaching Premium modules; a **disabled user account staying signed in**; unlimited seats. `LicenseGate.php:69-70` additionally swallows `Auth::logout()` + `session()->invalidate()`, so a user can be "logged out" with a live session | **HIGH** |
| **SEC-L-15** | **The audit chain's own integrity mechanism is inside a swallowing catch, and the chain is global rather than per tenant.** `Audit::ensure()`'s DDL failure at `:41-43` means `row_hash` is never written and `Schema::hasColumn(...,'row_hash')` at `:90` stays false forever — the ledger silently becomes unverifiable rows while the UI still reports "chain VERIFIED". `$prev` is `orderByDesc('id')->value('row_hash')` over the **whole table** (`:91`), so tenant A's rows are links in tenant B's chain, and `Audit::verify()` reads every row of every tenant into memory | `Audit.php:41-43, 90-99`; `ComputedController.php:146` | Passive; plus a deliberate attacker can delete back to the most recent unchained row and leave an intact-looking ledger | The product markets an RBI-compliance audit report on this ledger | **HIGH** |

---

## 8. Documentation-vs-source discrepancies

| ID | Discrepancy | Doc says | Source says |
|---|---|---|---|
| DOC-L-01 | **Command count** | *"Nineteen classes in `app/Console/Commands/`"* (§14.2, and *"all 19 console commands"* in the section header) | **Eighteen.** `AbsenceNotify, ApplyTransfers, ClearAppState, ClientProvision, DedupeAttendance, DemoReset, ErrorDigest, IssueLicense, MakeLicenseKeys, PayrollPeriodAudit, ProbationNotify, PurgeDemoAttendance, RenewSubscriptions, ScanCompliance, SeedIndustryContent, SendGreetings, SubscriptionAlerts, SyncETimeOffice`. The doc's own index table lists 18 classes plus 2 inline closures = 20 rows; the prose count of 19 is wrong. `PROJECT_ARCHITECTURE_AND_WORKFLOWS.md:2662` gets it right ("eighteen console commands") |
| DOC-L-02 | **`2026_07_15_000200_add_backup_schedule.php` is not about backups** | The task brief lists it as a backup migration | It adds `employees.backup_due_at` and `employees.backup_by` — a **staffing** "backup person" concept with a 3-day grace period (`rev183d`). It has nothing to do with `spatie/laravel-backup`. The filename is actively misleading |
| DOC-L-03 | **The `deploy/` directory does not exist in this repository** | ~40 citations across §14 and §11 to `deploy/smartprs-scheduler.cron`, `deploy/supervisor-smartprs-worker.conf`, `deploy/systemd-smartprs-worker.service`, `deploy/nginx-smartprs.conf`, plus `06_Build-and-Release-Scripts/*.bat` and `client-package-tools/INSTALL-SmartPRS-CLIENT.bat` | `find` returns nothing. Every claim about `numprocs=1`, `--queue=mail`, `stopwaitsecs=3600`, `--max-time=3600`, the `/dev/null` redirect and the cron line is **unverifiable from this tree**. This is a real finding, not a documentation nit: the operational runtime is not part of the application repository (DEF-L-27) |
| DOC-L-04 | **Swallowed-exception counts differ between the two reference sections and between both and source** | §12.4 says *"778 `catch (\Throwable`"* and *"291 empty"*; §19b.1 says *"948 `catch (` sites"* and *"305 completely empty"* | My scan gives **752** `catch (\Throwable $e)` in `app/`, **808** typed catch blocks in `app/`, **305** EMPTY in `app/` (matching §19b.1 exactly), and **843** typed catch blocks across the whole tree with **332** EMPTY. The §12.4 figures (778 / 291) are not reproducible. §19b.1's methodology — excluding JS `catch (e)` by requiring a `$` — is the correct one and its numbers stand |
| DOC-L-05 | **§19b.1 §1.2 says only 31 catch blocks log** | *"PHP catch blocks that only `Log::…` or `report()` and continue — 31"* | **82** across the tree, **76** in `app/`. The discrepancy is that §19b.1 counted log-only bodies while I counted any body containing a `Log::`/`report()` call. Both understate the same point: fewer than 10% of catch blocks preserve a signal |
| DOC-L-06 | **The doc asserts backup retention is Spatie's tiered default** and then correctly retracts it | §14.6.4 lists *"keep all for 7 days, daily for 16, weekly for 8, monthly for 4, yearly for 2, 5000 MB"* then says *"that is knowledge of the package, not evidence from this source tree"* | Correct as retracted. `vendor/` is absent; retention is genuinely **NOT DETERMINED FROM SOURCE** |
| DOC-L-07 | **The doc understates the "no restore" finding** | §14.6 records *"Is there a restore procedure? NOT DETERMINED FROM SOURCE"* | There **is** a restore, but only for code, only during an on-prem self-update: `updater/updater.php:140-159` (`rollback()`), `:252-269` (backup creation). It restores the code tree and `version.json`; it does **not** restore the database, and it runs migrations at `:310` which the rollback cannot undo. The doc missed it. The correct statement is: *a code-only rollback exists for the self-updater; no data restore exists anywhere* |
| DOC-L-08 | **The doc's line numbers for `bootstrap/app.php` are off by two** | §19b.1 cites `bootstrap/app.php:42-44` for the empty `withExceptions`, and `:15` for `health`, `:36-40` for the aliases | Source: `withExceptions` is at **`:44-46`**, `health: '/up'` at **`:14`**, aliases at **`:36-42`**. Immaterial, but noted because the brief requires line-accurate citation |
| DOC-L-09 | **The doc's §14.1.3 claims backups are excluded from `storage/logs` "so the log the error digest reads is not archived"** — correct — but then §14.6.2 says `storage/app` exclusion means *"neither the licence signing key nor any user-uploaded file stored under `storage/app` is in any backup"* without flagging the recovery consequence as strongly as it deserves | — | Confirmed in source and escalated here to SEC-L-13 (HIGH, availability) |
| DOC-L-10 | **The `README.md` in the source tree is entirely stale** | It describes a "Phase 0 foundation" scaffold, an unchecked build checklist, and seeded logins with the password `password`; it predates roughly 190 revisions of actual product | This is a source defect (DEF-L-28), not a reference-doc error, but it is the single most misleading document an operator would find |
| DOC-L-11 | **The doc says `LOG_STACK` defaults to `single` and neither template sets it** | §13.2 / §19a.4.2 | Confirmed: `config/logging.php:57` is `explode(',', env('LOG_STACK', 'single'))`, and grep of both `.env` templates finds no `LOG_STACK` or `LOG_DAILY_DAYS`. The `daily` channel's `days => 14` is dead configuration |
| DOC-L-12 | **The doc's claim that `SMARTPRS_OPS_EMAIL` is described as the "backup-failure + daily error-digest recipient" but is read only by ErrorDigest** | §14.6.5 | Confirmed by grep: `SMARTPRS_OPS_EMAIL` appears in `.env.production.example:62-63` and at `ErrorDigest.php:25` and nowhere else. `.env.production.example` makes a promise the code does not keep |

**Where the reference documents are strong:** Section 14 is unusually accurate. Every
material claim I spot-checked in source held — the schedule table, the `->when()` guard, the
`--if-due` predicate, `DummyDataSeeder`'s first-tenant fallback, the inline
`notifyTouched()` at `ETimeOfficeService.php:777`, the `MailService` `sync` bypass, the
fail-closed compliance recipient resolution, `GRACE_DAYS = 7`, `BEFORE_DAYS`,
`WINDOW_DAYS = 30`, `tries=1` on backups, and the runtime-DDL inventory. The corrections
above are the exceptions, not the pattern.

---

## 9. Migration verdict per capability

| Capability | Verdict | Rationale | Data migration implication |
|---|---|---|---|
| **The schedule as a hard-coded PHP file** | **REPLACE** | Twelve `Schedule::command()` calls with literal cron expressions cannot satisfy "change configuration, not code". SmartPRS 3 needs a **scheduled-job registry** (table-driven: job key, cron, timezone, enabled, tenant scope, lock TTL, failure policy, alert target) rendered into the scheduler at boot | New tables. Seed from the 12 current entries as the shipped defaults |
| **`compliance:scan`** | **REDESIGN** | The capability is right; the 30/7-day window, the 25-item cap and the role list must move to configuration, per-tenant. Per-tenant isolation and a lower bound on "expired" must be added | None — reads existing tables |
| **`billing:alerts` (reminders + auto-suspend)** | **REDESIGN** | The best-isolated command in the codebase; keep the shape. The `[15,7,3,1,0]` ladder, `GRACE_DAYS`, and whether auto-suspend happens at all must be per-plan configuration. Exact-day matching must become "first run at or past the threshold, deduped on the ledger key". `status='sent'` must mean *delivered*, not *enqueued* | `subscription_alerts` migrates as-is; add a `delivered_at` distinct from `queued_at` |
| **`transfers:apply`** | **REDESIGN** | Keep the daily sweep. Replace substring type-sniffing with an enum, snapshot the cleared hierarchy fields, key onboarding idempotency on employee **id** not name, and surface stuck transfers as an operator-visible exception queue | Add snapshot columns to `transfers`; backfill unavailable |
| **`probation:notify`** | **REDESIGN** | Config already covers milestones and duration. Fix exact-day matching, make the recipient role list configurable, make the overdue nudge repeat on a configured cadence, and move `ensureSchema()`'s DDL into migrations | `probation_events` migrates as-is; the four `employees` columns become real migration columns |
| **`absence:notify`** | **REDESIGN** | Correct concept, dangerous implementation. The three lookups that swallow to an empty set (`isHoliday`, `presentEmpCodes`, `onLeaveEmployeeIds`) must **fail the run**, never default to "absent". Add catch-up for missed days. Add a WFH concept | None |
| **`greetings:send`** | **RETAIN** (largest config surface already) | Templates, hour, toggles and `min_years` are already per-tenant. Fix the non-indexable `DATE_FORMAT` candidate query and the whole-loop swallow | None |
| **`attendance:sync-etimeoffice`** | **REDESIGN** | The idempotent `updateOrInsert` on `(tenant_id, emp_code, punch_at, source)` and the per-device interval gate are genuinely good and must be kept. The `--days=1` window must become a **watermark-based** pull (from `last_successful_sync_at` to now, with a configurable maximum look-back), so an outage self-heals. Add per-device sync-health status and staleness alerting | Add a `last_successful_sync_at` distinct from `last_sync_at` |
| **Late-arrival notification** | **MERGE** into the notification engine | Two divergent delivery paths for one business event is a defect. Everything must go through the queued path with `mail_log` and per-company SMTP | None |
| **`demo:reset`** | **REMOVE from the product**; rebuild as a separately-deployed demo-environment tool | A destructive, admin-creating command scheduled unconditionally in a product shipped to customers is not defensible at any severity discount. It must not exist in a customer artefact | None (demo tenant only) |
| **`billing:renew`** | **REMOVE** | De-scheduled since rev 75, superseded by self-service renewal, and carries a duplicate-invoice defect. Dead code that can raise real GST invoices if run by accident | None |
| **`app:clear-state`** | **REMOVE** | Targets a prototype-era `settings.app_state` blob from *"older dev installs"* | Verify nothing live writes `app_state` first (OQ-L-05) |
| **`attendance:purge-demo`** | **REMOVE** | Targets `source='demo'` rows from a dev fabricator that no longer exists | None |
| **`attendance:dedupe`** | **REMOVE** | Made obsolete by the `attlog_natural_unique` index; unchunked full-table load; deletes across all tenants by default | None |
| **`invoices:renumber-prs`** | **REMOVE** | A one-time rev-187 data migration living permanently in `routes/console.php`, capable of rewriting GST invoice numbers with no archive | Run once if not yet run, **with an `old_number` archive column added first** |
| **`payroll:period-audit`** | **RETAIN and PROMOTE** | The only command that returns FAILURE on a *data* condition. Schedule it weekly with failure alerting and it becomes a real financial control | None (read-only) |
| **`client:provision`** | **REDESIGN** | Keep the capability; wrap in a transaction, prompt for the password instead of taking it on the command line, and guard on "does any tenant exist" rather than on an email | None |
| **`smartprs:issue-license` / `smartprs:make-license-keys`** | **REDESIGN** | Record every issued licence in the register (the CLI path currently writes only a file). `--force` must back up the outgoing pair, require typed confirmation, and the key directory must be in a separate encrypted backup | Add a licence-issuance ledger |
| **`content:seed-industry`** | **RETAIN**, but callers must stop swallowing its failure | Genuinely idempotent, natural-key matched, never overwrites tenant edits | None |
| **The queue** | **REDESIGN** | One named queue, one worker, no failed-job handling, no pruning, no alerting, dead `job_batches`. Needs: named queues per work class, a `failed()` handler on every job, a `Queue::failing()` listener writing to an operator-visible table, `queue:prune-failed` on the schedule, and depth/age monitoring | `jobs`/`failed_jobs` migrate as-is |
| **The cache** | **RETAIN as-is** | Two `Cache::` calls in the entire application. Nothing can go stale — a correctness-positive accident. `cache_locks` is load-bearing for the scheduler and must be preserved. Add caching only where measured | None |
| **Error handling** | **REPLACE** | An empty `withExceptions` closure plus 843 hand-written catch blocks is not an error-handling strategy. Needs: one exception handler, one API error envelope, `dontFlash` for `current`/`license_code`, no raw `$e->getMessage()` to any client, and a rule that a catch either logs with context or rethrows | None |
| **Logging** | **REPLACE** | Stock Laravel config, unrotated `single` channel, 83 ad-hoc string interpolations, no correlation id, no auth logging. Needs: structured logging with a request/correlation id and tenant id on every line, the `daily` channel with retention, and a redacting processor for exception messages | None |
| **The audit trail (`activity_logs`)** | **REDESIGN** | Hash chain broken by direct inserts, global rather than per-tenant, race-prone, and its own DDL is inside a swallowing catch. It backs a compliance report the product sells | Chain must be re-anchored per tenant; historical rows cannot be retro-verified |
| **Backups** | **REPLACE** | On-box, unencrypted, includes `.env`, excludes the licence key and uploaded documents, unknown retention, one try, no monitoring, no restore | Existing archives are not a migration input |
| **Observability** | **REPLACE (build from zero)** | `/up` plus one blind daily email. Needs: scheduler heartbeat, queue depth/age, sync freshness, backup age, mail-delivery success rate, and a real alert channel | None |
| **The operational runtime (cron, worker units, runbook)** | **REPLACE** | It is not in the repository. It must ship *with* the product, be installed by the installer, and be **verified at run time** by a health check that fails loudly | None |

---

## 10. SmartPRS 3 requirements implied

Numbered, specific, testable. Each traces to evidence above.

### Scheduling engine

1. The scheduler **must** be table-driven, not code-driven: a `scheduled_jobs` registry with
   `job_key`, `cron_expression`, `timezone`, `enabled`, `scope` (platform / per-tenant),
   `lock_ttl_seconds`, `max_runtime_seconds`, `on_failure_action`, `alert_channel`. All
   twelve current entries seed as defaults. *(Replaces the 12 literals at
   `routes/console.php:143-266`.)*
2. Every scheduled job **must** declare an explicit overlap-lock TTL proportional to its
   cadence; the engine must reject a TTL longer than 10× the interval. *(Because
   `withoutOverlapping()` today defaults to 24 hours for a 5-minute job — DEF-L-02.)*
3. Every scheduled job **must** record a run row: `started_at`, `finished_at`, `status`,
   `items_attempted`, `items_succeeded`, `items_failed`, `error_summary`. This is the
   scheduler heartbeat that does not exist today.
4. The scheduler **must** expose a health signal that fails when any enabled job has not
   completed within its expected interval × 2. *(No such check exists — §Q7.)*
5. Every scheduled job's timezone **must** be resolvable per tenant, not from a single
   global `config('app.timezone')` frozen by `config:cache`. *(DEF-L-24.)*
6. No destructive command may be schedulable without an explicit environment allow-list and
   a per-install opt-in flag. *(SEC-L-01.)*

### Tenant iteration and isolation

7. The engine **must** provide per-item (per-tenant, per-record) fault isolation as a
   framework guarantee, not a per-command choice: a failure on tenant 3 of 20 must be
   recorded, alerted, and must not prevent tenants 4–20 from being processed.
   *(DEF-L-03, DEF-L-04; `SubscriptionAlerts.php:135-137` is the only correct
   implementation today.)*
8. A job that processed fewer items than it attempted **must not** report success.
   *(Today `AbsenceService` and `GreetingService` return an under-count and exit SUCCESS.)*

### Rule engine — thresholds, windows and audiences

9. The rule engine **must** support an **N-day-before-a-date reminder ladder** as
   configuration — an ordered list of day offsets, per rule, per tenant — because
   `SubscriptionAlerts.php:35` hard-codes `[15,7,3,1,0]` and `ProbationService.php:36`
   hard-codes `[30,15,7,0]`.
10. Ladder evaluation **must** be **"first run at or past the threshold, deduped on the
    ledger key"**, never exact day equality, so a missed run catches up instead of losing
    the reminder permanently. *(DEF-L-05 — four separate call sites.)*
11. The rule engine **must** support a **grace period** as a per-plan configurable duration
    with a configurable per-day reminder cadence, and a configurable terminal action
    (suspend / notify only / do nothing). *(`GRACE_DAYS = 7`, `SubscriptionService.php:34`;
    auto-suspend is unconditional at `SubscriptionAlerts.php:60-69`.)*
12. The rule engine **must** support an **alert window with both an upper and a lower
    bound**, because `ComplianceController.php:77-79` has only an upper bound and reports
    years-dead certificates forever. *(DEF-L-18.)*
13. The rule engine **must** support **bucketing thresholds** (expired / critical / soon) as
    configuration, not the literals `0` and `7` at `ComplianceController.php:80`.
14. Every notification rule **must** carry a configurable **audience** expressed as roles,
    named users, or a distribution list — because
    `ComplianceController.php:256` and `ProbationService.php:250-253` hard-code role arrays.
15. Audience resolution **must** fail **closed** and **must** raise an operator alert when it
    resolves to nobody. *(Today `ComplianceController.php:269-272` fails closed correctly
    but silently — the tenant simply never gets a digest and nobody knows.)*
16. Every notification rule **must** carry a configurable **send hour and timezone per
    tenant**. *(Already achieved for greetings/absence/probation via `app_settings`; must
    become universal.)*
17. Digest rules **must** carry a configurable item cap and an overflow behaviour.
    *(`ComplianceController.php:206` hard-codes 25.)*
18. The engine **must** support a configurable **working-week pattern** and a
    holiday/leave/WFH exclusion set as first-class inputs to any "did this person work?"
    rule, because `AbsenceService.php:44-45` defaults to Mon–Sat and the docblock at
    `:29-31` records that **no WFH concept exists**.
19. The engine **must** support a configurable **probation duration** with an explicit
    month-arithmetic policy (calendar clamp vs day-count), because
    `ProbationService.php:118` uses `Carbon::addMonths` whose clamping behaviour is
    invisible to the user (31 Jan + 6m → 31 Jul; 31 Aug + 6m → 28 Feb).
20. Transfer type **must** be an enumerated, referenceable value, never a substring match.
    *(`TransferService.php:126-127` — `"Inter-Department Company Liaison"` currently
    classifies as a company move and nulls the reporting hierarchy.)*
21. Any rule that **clears** employee data **must** snapshot the cleared values into the
    transaction record. *(`TransferService.php:150-157` clears four fields and snapshots
    none of them — DEF-L-15.)*

### Integration and ingestion

22. Every external-data pull **must** use a **durable watermark** (`last_successful_sync_at`),
    not a fixed relative window, with a configurable maximum look-back — so a scheduler
    outage self-heals instead of losing data permanently.
    *(DEF-L-01: `--days=1` at `routes/console.php:225` is the highest-impact hard-coded
    value in this area.)*
23. Every external call **must** have an explicit, configurable timeout and an explicit
    retry policy. *(SEC-L-08: five payment-gateway calls have **no timeout at all**;
    `->retry()` appears nowhere in `app/`.)*
24. No external call may be made synchronously from inside a scheduled task holding a lock.
    *(`WaService.php:146` is called inline per tenant inside `billing:alerts` — DEF-L-21.)*
25. Ingestion **must** preserve the idempotent-upsert-on-natural-key design that works today
    (`(tenant_id, emp_code, punch_at, source)` with a matching unique index) and the
    quarantine-and-replay design for unmatched codes. **These are the two best pieces of
    engineering in this area and must survive the rebuild.**
26. Every integration **must** expose a freshness signal (`last_successful_sync_at`,
    `last_error`, `consecutive_failures`) and an alert when it goes stale.

### Notification delivery

27. There **must** be exactly one delivery path for a given business event. *(DEF-L-09: one
    event, two paths, one of which bypasses `mail_log` and per-company SMTP entirely.)*
28. A delivery ledger row **must** distinguish `queued`, `sent`, `delivered`, `failed` and
    `skipped`, and no caller may treat "queued" as "sent". *(DEF-L-13:
    `SubscriptionAlerts.php:107-108` records `sent` on the basis of a row id returned before
    any send attempt.)*
29. A message that cannot be delivered because no transport is configured **must** raise an
    operator-visible condition, not a silent `skipped`. *(DEF-L-11:
    `MailService.php:148-152`.)*
30. Notification idempotency **must** remain `(kind, dedupe_key)`-based, and the dedupe key
    **must** be constructed from the business event, not from the calendar year.
    *(`GreetingService.php:195` uses year-only, which is correct for greetings but is not a
    general rule.)*
31. A suppressed duplicate and a failed send **must** be distinguishable at the call site.
    *(DEF-L-12: `NotificationService::send()` returns `null` for both.)*
32. Credential-class messages **must** have a guaranteed delivery path that does not depend
    on a worker being installed, with retry. *(The current `'sync' => true` bypass is
    correct in intent — it fixes a real incident recorded at `MailService.php:102-106` —
    but has **no retry**: a failure is logged as a warning and never attempted again.)*
33. No message payload may carry file bytes. Attachments must be stored and referenced.
    *(SEC-L-07: `attach_b64` in `jobs.payload`.)*
34. No delivery ledger may store credential material. `wa_log.body_values` currently stores
    OTPs and plaintext first-login passwords. *(SEC-L-03.)*

### Queue and worker

35. Work **must** be routed to named queues by class (interactive / bulk / integration /
    reporting), not to one queue named `mail`.
36. Every job **must** implement `failed()`, and a `Queue::failing()` listener **must** write
    to an operator-visible table with an alert. *(DEF-L-23: grep for `Queue::` returns zero
    matches.)*
37. `failed_jobs` and every log-shaped table **must** have a scheduled pruning policy.
    *(DEF-L-29: six tables grow without bound; `activitylog:clean` is not scheduled.)*
38. A job **must not** swallow its own exceptions if it declares a retry policy.
    *(DEF-L-10: `NotifyLateArrivals`'s `tries`/`backoff` are dead config.)*
39. Queue depth, oldest-job age and worker liveness **must** be exposed as health signals.
40. Drained backlogs **must not** deliver stale time-sensitive notifications: every
    time-sensitive job needs a `retryUntil()` or an age check.

### Error handling

41. There **must** be one application exception handler with `report()` enrichment attaching
    tenant id, user id and correlation id to every logged exception.
    *(`bootstrap/app.php:44-46` is empty.)*
42. A catch block **must** either (a) log with structured context, (b) rethrow, or (c) be
    explicitly annotated as a benign-by-design suppression with a stated reason. A lint rule
    **must** enforce this. *(426 of 843 catch blocks currently do none of the three.)*
43. **No security, entitlement or authorization control may fail open.** Every one of the
    five identified fail-opens must become fail-closed with an alert. *(SEC-L-14.)*
44. **No control whose purpose is a legal or contractual obligation (audit write, privacy
    wipe, statutory calculation) may be best-effort.** *(SEC-L-05, SEC-L-15.)*
45. Raw exception messages **must never** reach an HTTP client. *(194 sites do today.)*
46. There **must** be one API error envelope. *(Four incompatible shapes coexist.)*
47. Any operation that mutates money or ledger state **must** be transactional end-to-end.
    *(DEF-L-32 and the payroll regenerate at `PayrollGenController.php:2389-2620`, where the
    old payslips are deleted 230 lines before a transaction opens.)*

### Schema

48. **No table or column may be created at run time.** All nine runtime-created tables and
    eleven runtime-created columns catalogued in §2 must become migrations. The application
    database user must not hold `CREATE`/`ALTER` in production.
    *(DEF-L-08 — this is the root cause of the four money defects in §Q3 items 1–2.)*

### Logging and observability

49. Every log line **must** carry a correlation id, tenant id and actor id.
    *(None exist today.)*
50. Authentication events — success, failure, lockout, password change, role change,
    permission denial — **must** be logged and audited.
    *(`AuthController` contains zero `Log::` calls.)*
51. Exception messages written to logs **must** pass through a redactor. *(SEC-L-09,
    logging item #9 in §Q6: a failed insert on `employees` currently writes PAN, bank
    account, salary and DOB into the log file.)*
52. Logs **must** rotate with a configurable retention. *(DEF-L-22: the `single` channel is
    unrotated and unbounded.)*
53. Alerting **must not** depend on the subsystem it monitors. *(DEF-L-07: the error digest
    is delivered through the queue whose failure it cannot detect.)*
54. Alert thresholds, severities and recipients **must** be configuration.
    *(`ErrorDigest.php:48, 76, 79, 108`.)*
55. The health endpoint **must** report component-level status — scheduler last-run, worker
    liveness, queue depth and age, integration freshness, backup age, migration state —
    not merely that the framework booted. *(`/up` today reports only the latter.)*

### Backup and recovery

56. Backups **must** support at least one **off-box** destination, and an install with no
    off-box destination **must** raise a persistent operator warning.
    *(`config/backup.php:72-74`.)*
57. Backup archives **must** be encrypted by default. *(SEC-L-06.)*
58. `.env` and any file containing secrets **must** be excluded from the archive; secrets
    needed for recovery must be handled by a separate, deliberately-managed path.
59. The backup set **must** include everything needed for recovery — notably
    `storage/app/keys` and uploaded documents, both of which are excluded today.
    *(SEC-L-13, DEF-L-26.)*
60. Retention **must** be explicit configuration owned by the product, not an unpinned
    package default. *(`config/backup.php:88-92`.)*
61. Backup runs **must** be monitored: age, size, success, and a verify step. Failure
    **must** alert. *(§Q5: nothing exists.)*
62. A **restore procedure must exist, be documented, and be exercised automatically** — at
    minimum a scheduled restore-to-scratch verification. *(Today there is no data restore
    path at all; only the code-only self-update rollback at `updater/updater.php:140-159`.)*
63. The database connection name backed up **must not** be a literal.
    *(`config/backup.php:45-46` hard-codes `'mysql'`.)*

### Operational runtime

64. The scheduler and the worker **must** ship with the product, be installed by the
    installer, and their absence **must** be detected and reported by the application
    itself. *(DEF-L-27 — this is the single highest-leverage change in this area: it turns
    the most likely and most silent production failure into a visible one.)*
65. Deployment **must** restart workers. *(`queue:restart` appears nowhere.)*
66. Every console command that deletes or rewrites data **must** require an explicit scope
    (`--tenant` or `--all`), offer `--dry`, refuse to run in `production` without a
    confirmation, and write an audit record of what it changed. *(SEC-L-10, SEC-L-11.)*
67. Console commands **must** have automated tests. *(DEF-L-34: zero exist for any of the
    eighteen.)*

---

## 11. Open questions / PRODUCT OWNER DECISION REQUIRED

| ID | Question | Why it matters | What is needed to answer it |
|---|---|---|---|
| **OQ-L-01** | **DECISION REQUIRED — Is `demo:reset` present, and scheduled, on customer on-premise servers today?** The 03:30 entry is unconditional and `routes/console.php` ships in every edition (there is no per-edition schedule file). If any customer install runs the scheduler, SEC-L-01 applies to their server: an active `admin`-role account with the password `smartprs-demo`, re-created nightly | This is the difference between a HIGH internal risk and a **CRITICAL disclosed vulnerability across the installed base** | Confirmation from operations for every live deployment, SaaS and on-prem. **This is the single most urgent question in this document** |
| **OQ-L-02** | **DECISION REQUIRED — On how many live installs are the cron entry and the queue worker actually running?** The rev 170 comment at `MailService.php:102-106` is direct in-code evidence that at least one production install ran with no worker and locked a paying client out of their workspace | Determines whether the failures in §Q9 are theoretical or already happening in the field. If the worker is missing anywhere, tenants are being auto-suspended having received no warning emails, and `subscription_alerts` is recording those emails as `sent` | An inventory of live deployments with `crontab -l` and worker status for each |
| **OQ-L-03** | What is the **actual backup retention** in force? `config/backup.php:88-92` defers to the package default, and `vendor/` is not in this tree | Cannot answer "how far back can we recover?" — a question a customer will ask during an incident | `php artisan config:show backup.cleanup` on a live server, or the contents of `vendor/spatie/laravel-backup/config/backup.php` |
| **OQ-L-04** | **Are backup failures emailed to anyone?** `config/backup.php` omits `notifications`; `notifications.mail.to` is set nowhere; `.env.production.example:62-63` describes `SMARTPRS_OPS_EMAIL` as the *"backup-failure + daily error-digest recipient"* but that variable is read only by `ErrorDigest.php:25` | If the answer is no, backups have been failing silently for an unknown period on every install | The package's default `notifications` block plus confirmation of `mail.from` on a live server |
| **OQ-L-05** | **Does any live feature persist meaningful state under `settings.key = 'app_state'`?** | Determines whether `app:clear-state`'s unguarded cross-tenant `DELETE` (SEC-L-10) is theoretical or destructive | An exhaustive grep for `'app_state'` across the application and front-end bundle, plus a sample of the column's contents on a live database |
| **OQ-L-06** | **DECISION REQUIRED — Should the probation "overdue nudge" fire once or repeatedly?** The config key is `overdue_nudge` (singular) and the dedupe base `probation:{empId}:{end}:overdue` makes it fire exactly once, ever. A probation left unconfirmed for six months is nudged one time and then goes quiet | Product owner must state the intended behaviour before it is rebuilt | Product decision |
| **OQ-L-07** | **DECISION REQUIRED — Should a `cancelled` subscription receive daily grace-period "please renew" emails?** The pre-expiry branch checks `status === 'active'`; the grace branch does not (`SubscriptionAlerts.php:56-59`) | Currently a customer who cancelled deliberately is chased daily for seven days | Product decision |
| **OQ-L-08** | **DECISION REQUIRED — Should auto-suspend exist at all, and should it be per-plan or per-contract?** `SubscriptionAlerts.php:60-69` writes `tenants.status='suspended'` unconditionally with no dry-run and no confirmation. If `current_period_end` were ever corrupted, this would suspend every paying customer on its next run | An enterprise contract may forbid automatic suspension entirely | Product / commercial decision |
| **OQ-L-09** | **DECISION REQUIRED — What is the acceptable data-loss window for biometric attendance?** The current answer, imposed by `--days=1`, is "24 hours, then permanent loss". A watermark design would make it "as long as the upstream device retains punches" | Determines the ingestion design in SmartPRS 3 and whether a local punch cache is required | Product decision, informed by device/cloud retention periods per provider |
| **OQ-L-10** | Does `ApprovalService::safeRow()` silently **drop** unknown columns, and does it fill required NOT-NULL columns? It is used on every insert in `ClientProvision.php:66, 87`, `DemoReset.php:91` and `TransferService.php:98, 192` | If it silently drops, a renamed column makes data vanish from an insert with **no error** — across provisioning, demo reset and transfers | Read `app/Http/Controllers/ApprovalService.php → safeRow()` (another analyst's scope) |
| **OQ-L-11** | Under what conditions does `ShiftResolver::resolve()` return an array without an `off` key, and how does `rosterMap()` handle an employee with no roster entry? | Determines whether the Mon–Sat `working_days` fallback or the shift definition governs absence marking — i.e. whether a 5-day-week customer's Saturdays are flagged absent | `app/Services/ShiftResolver.php` (attendance analyst's scope) |
| **OQ-L-12** | Does `php artisan optimize:clear` (a documented deployment step) clear `withoutOverlapping` locks as a side effect? | If yes, it is an accidental recovery for DEF-L-02 and also an accidental way to cause concurrent runs during a deploy | A test against a held schedule lock |
| **OQ-L-13** | **Where do the `deploy/` files live?** They are cited ~40 times by the reference documentation and are absent from this repository | Half the operational analysis in this document is unverifiable without them, and a release artefact that does not contain its own runtime is a structural problem regardless | Locate the files; then decide whether they belong in the application repository (recommendation: yes) |
| **OQ-L-14** | **DECISION REQUIRED — Should `SMARTPRS_WA_SEND_PASSWORD` default to `false`?** It defaults to `true` (`SignupController.php:413`), sending a new tenant admin's plaintext password over WhatsApp and persisting it in `wa_log.body_values` forever | A security default that should probably be inverted, with the ledger redacting credential-bearing template variables regardless | Product / security decision |
