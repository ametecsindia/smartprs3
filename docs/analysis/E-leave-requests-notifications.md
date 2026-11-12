# AS-IS ANALYSIS — REQUEST/APPROVAL ENGINE, LEAVE, ESS, NOTIFICATIONS, WHATSAPP, PUSH, GREETINGS, AUDIT TRAIL

## 0. Scope and evidence base

### 0.1 Reference documents read

| Document | Lines read | Content |
|---|---|---|
| `COMPLETE_PROJECT_BUSINESS_AND_TECHNICAL_DOCUMENTATION.md` | 11017–15950 (Section 09, complete) | §0 orientation, §1 request engine, §2 `ApprovalService`, §3 leave, §4 ESS, §5 notifications, §6 WhatsApp, §7 push/mobile, §8 greetings, §9 audit, §10 business rules (BR-REQ/BR-LVE/BR-NOT), §11 security findings, §12 debt register, §13 open questions, §14 cross-references |
| `BUSINESS_RULES_CATALOGUE.md` | 5951–6218 (§3.7 LVE, 15 rules), 6218–6897 (§3.8 REQ, 38 rules), 12172–12621 (§3.19 NOT, 29 rules); index/heading scan of the whole file | BR-LVE-001…012 + 700–702; BR-REQ-001…032, 406, 608, 700–706; BR-NOT-001…024, 090, 091, 201, 700, 701 |

### 0.2 Source files read COMPLETELY

| File | Lines | Note |
|---|---|---|
| `app/Http/Controllers/RequestController.php` | 1,929 | read in full, four slices |
| `app/Http/Controllers/ApprovalService.php` | 381 | a **service in the Controllers namespace** |
| `app/Http/Controllers/LeaveController.php` | 501 | |
| `app/Http/Controllers/EssController.php` | 295 | |
| `app/Http/Controllers/NotificationController.php` | 65 | |
| `app/Http/Controllers/SendMessageController.php` | 267 | |
| `app/Http/Controllers/WaTemplateController.php` | 434 | |
| `app/Http/Controllers/GreetingController.php` | 189 | |
| `app/Services/NotificationService.php` | 307 | |
| `app/Services/MailService.php` | 219 | |
| `app/Services/WaService.php` | 184 | |
| `app/Services/GreetingService.php` | 310 | |
| `app/Services/Audit.php` | 133 | |
| `app/Services/AuditTrail.php` | 92 | |
| `app/Jobs/SendNotification.php` | 48 | |
| `app/Console/Commands/SendGreetings.php` | 48 | |
| `app/Console/Commands/ErrorDigest.php` | 117 | |
| `app/Models/LeaveApplication.php` | 26 | dead code — see DEF-LVE-01 |
| `tests/Unit/GreetingServiceTest.php` | 30 | the ONLY test touching this whole area |
| `database/migrations/2026_01_01_000400_create_leave_applications_table.php` | 60+ | creates `pf_esi_settings`, `tds_rules`, `pt_slabs`, `tds_returns` — **nothing to do with leave** |
| `database/migrations/2026_07_26_000000_p0_create_notification_log.php` | 55 | idempotent via `SchemaHelper` |
| `database/migrations/2026_08_07_000100_fix_escalations_priority.php` | 44 | Escalation **Desk** (grievances), unrelated to approval escalation |
| `database/migrations/2026_05_30_025626_create_activity_log_table.php` (+`_025627_add_event`, `_025628_add_batch_uuid`) | 27/22/22 | Spatie `activity_log` (singular) — created, never written |

### 0.3 Source files read in relevant part (for cross-checks)

`app/Http/Controllers/AppController.php:3899–3947` (the SPA `RQ_MAP` request-form field definitions), `app/Http/Controllers/PayrollGenController.php:715–762, 1042–1085` (paid-leave consumption), `app/Http/Controllers/MasterController.php:60–90, 415–435, 455, 490–540, 582` (leave-type / notice / wa_settings master CRUD + runtime table creation), `app/Http/Controllers/MobileDeviceController.php:170–200`, `app/Http/Controllers/RecruitMessagingController.php:380–417` (Interakt webhook), `app/Http/Controllers/SettingsController.php:56–60, 152–170` (`dra_gate`, `incentive_min_compliance`, `late_email_enabled`), `app/Services/FeaturePermissions.php:1–120`, `app/Services/LateArrivalService.php` (grep-verified `Mail::raw` at `:289`), `app/Services/AbsenceService.php:189–210`, `app/Services/ProbationService.php:215–305`, `app/Services/AttendanceCorrectionService.php:411–443`, `routes/web.php:79–87, 198–204, 271–276, 419–456, 507, 550–558, 598–599, 666–669`, `routes/console.php:140–262`, `database/migrations/2026_01_01_000200_create_departments_table.php:75–108` (leave_types / leaves / holidays), `…000300_create_attendances_table.php:72–165` (commissions/clawbacks/advances/loans/expenses/bonus_encashment), `…000500_create_loans_table.php:80–110` (exits/escalations), `…000600_create_salary_runs_table.php:135–200, 244–262` (wa_settings/sms_settings/messages_log/increments), `…000700_create_salary_slips_table.php:13–23` (`activity_logs`), `…2026_06_24_000000_ensure_runtime_feature_tables.php:120–165`.

### 0.4 Files named in the task that DO NOT EXIST in the staged copy

`resources/views/leave/form.blade.php`, `resources/views/leave/index.blade.php`, `resources/views/emails/generic.blade.php`.

`find resources -type f` returns 16 files only. **No `leave/` directory and no `emails/` directory exist.** Two separate conclusions must be drawn, and they are different:

- **The leave blades never existed.** Grepping every `view('…')` call across `app/` and `routes/` finds **no `view('leave.*')` reference anywhere**. The whole leave module is JSON-only, consumed by the PHP-generated SPA in `AppController`. The reference doc never cites them either. These two paths are an error in the task brief, not a missing file.
- **`emails.generic` IS referenced** — `MailService.php:185` calls `Mail::send('emails.generic', $data, …)` — but the blade is absent from this copy. So are `login`, `dashboard`, `landing`, `policies.*` and ~25 other referenced views. The staged `resources/` tree is therefore **incomplete**, not the application. I record the doc's description of `emails/generic.blade.php` (branded header, `$lines` table, escaped `{{ }}` throughout, hard-coded Ametecs footer with GSTIN `36AAHCT0971F1ZB`) as **UNVERIFIED — file not present in the read-only copy**, and I do not assert any claim about its content. See DISC-06.

### 0.5 Status labels used below

`IMPLEMENTED` · `PARTIALLY IMPLEMENTED` · `NOT IMPLEMENTED` · `DEFECT` (actual ≠ intended, or plainly wrong) · `CONFLICT` (two parts of the system disagree) · `CONFIGURATION DEPENDENT` · `UNVERIFIED`.

---

## 1. Business capability inventory

### 1.1 One generic "ask → decide" engine for nine money/HR request types

An employee (or HR on their behalf) raises a request of a given **type**. The system looks up who that employee reports to, makes that person the approver, files the record as **Pending**, and emails the approver. For expense claims, salary advances and loans above a rupee threshold it skips a level and names *the manager's manager* instead. The approver approves or rejects with optional remarks; the requester is emailed the outcome. Every type shares one controller, one table registry, one inbox.

The nine types (`ApprovalService.php:29–42`): Expense Claim, Salary Advance, Loan, Commission, Clawback, Increment/Appraisal, Exit & FnF, Bonus/Encashment, Employee Transfer.

### 1.2 Commissions — a full sub-system bolted onto the generic engine

Commissions carry roughly ten times the machinery of every other type: two claim shapes (collection vs simple), mandatory collection evidence, a proof upload, an **Accounts-confirms-the-money-first** gate, a DRA-certification gate, compliance-score and open-grievance warnings, edit-after-approval with a field-diff history, a partial-payment disbursement ledger, an auto-lock at full payment, a manual admin lock, a bulk 1,000-row upload, a printable payment voucher, an orphan purge, and a **bounce** action that auto-creates an approved clawback when money has already gone out.

### 1.3 Leave — a second, older, near-identical engine

Each company defines leave types with a yearly allocation and a paid/unpaid flag. An employee applies for a date range; the system counts inclusive calendar days, checks the balance, resolves the manager, files it Pending. The manager decides and the employee is emailed. Approved *paid* leave is supposed to count as attendance in payroll; unpaid leave is supposed to cost the employee the day. **The second half of that sentence does not happen** (§6, DEF-LVE-02).

Leave does **not** use `ApprovalService`. It has its own `currentEmployee()`, its own `resolveApprover()`, its own `ensureCols()` and its own inline `safeRow` equivalent.

### 1.4 Employee Self-Service ("My Space")

One GET returns the caller's own profile, last six payslips (PDF link only if company policy allows), this month's attendance count and last punch, last six leave rows, per-type leave balances, and the five newest active notices. One POST lets the employee correct ten contact/personal fields. Pay, designation, department, employee code and company are not editable.

### 1.5 The unified Approvals Inbox

One endpoint aggregates every pending leave plus every pending row of all nine modules into one flat list, each item tagged with the module so the SPA knows which decide endpoint to call.

### 1.6 Notifications — four rails, two of them actually used by workflows

An in-app bell (`notification_log`), per-company SMTP email (`mail_log` + a queued job), an SMS/WhatsApp broadcast screen (generic provider POST), and transactional WhatsApp via Interakt (`wa_log`). Push has token plumbing and **no sender**.

### 1.7 WhatsApp template registry

Because WhatsApp only permits business-initiated messages from Meta-approved templates, SmartPRS keeps a registry of template bodies, tracks their approval status, and proves approval by sending a test. Six platform purposes (super admin) and eighteen tenant purposes are defined; a 17-template HR library is seeded per tenant.

### 1.8 Greetings automation

An hourly sweep finds employees whose date of birth or date of joining is today and sends an HR-authored, placeholder-rendered greeting in-app and by email, once per person per year.

### 1.9 Audit trail

A hash-chained append-only ledger (`activity_logs`) records request submissions and decisions, leave submissions and decisions, and a handful of greeting/config actions. Commissions additionally keep a plain-English per-entry history (`commission_logs`). A third mechanism, Spatie `laravel-activitylog`, is installed and migrated but **never written to**.

---

## 2. Data model

Provenance codes: **M** = created by a migration · **R** = created at RUNTIME by application code (a critical finding) · **M+R** = migration creates the table, runtime code adds columns.

### 2.1 Request-engine tables

| Table | Provenance | Base columns (migration) | Columns added at RUNTIME | Runtime-DDL site |
|---|---|---|---|---|
| `expenses` | **M+R** — `2026_01_01_000300_create_attendances_table.php:131–141` | `id, tenant_id, employee_id, type, amount, date, status, approver_id, timestamps` | `approver_name, decided_by, remarks, decided_at`; `fin_year` | `ApprovalService::ensureAuditCols():235–259`; `FinYearController::stamp()` via `RequestController:547` |
| `advances` | **M+R** — `…000300…:108–117` | `id, tenant_id, employee_id, amount, per_month, balance, status, timestamps` | `approver_id, approver_name, decided_by, remarks, decided_at`, `fin_year` | same |
| `loans` | **M+R** — `…000300…:119–129` | `id, tenant_id, employee_id, amount, emi, tenure, outstanding, status, timestamps` | same audit set, `fin_year` | same |
| `commissions` | **M+R** — `…000300…:72–82` | `id, tenant_id, company_id, employee_id, portfolio, month, amount, status ENUM('provisional','confirmed','reversed'), timestamps` | `purpose, description, entered_by, cycle_month, payout_method, locked_by, lock_source` (varchar 500); `gross_amount, tds_rate, tds_amount` (dec 12,2); `payout_date` (date); `locked_at` (ts); **plus** `base_amount, scheme_id, customer_name, account_ref, collection_type, collected_at, collection_location, collection_mode, proof_path, claim_type, accounts_status, accounts_by, accounts_at, accounts_note`; **plus** `bounced_at, bounced_by, bounce_reason, bounce_clawback_id`; **plus** the generic audit set; **plus** `status` ENUM→VARCHAR(20) widening | `ApprovalService::ensureAuditCols():103–134, 224–234, 235–259`; `RequestController::ensureCollectionCols():68–102`; `RequestController::ensureBounceCols():1680–1701` |
| `clawbacks` | **M+R** — `…000300…:96–106` | `id, tenant_id, employee_id, portfolio, month, amount, reason, status, timestamps` | audit set, `fin_year`. **No `company_id`, no `cycle_month`** | `ensureAuditCols` |
| `increments` | **M+R** — `2026_01_01_000600_create_salary_runs_table.php:248–261` | `id, tenant_id, company_id, employee_id, cycle, old_ctc, new_ctc, pct, effective, status, timestamps` | `reason, new_designation, applied_by` (varchar 500); `applied_at, letter_sent_at` (ts); audit set | `ensureAuditCols():137–161` |
| `exits` | **M+R** — `2026_01_01_000500_create_loans_table.php:80–95` | `id, tenant_id, company_id, employee_id, last_day, advances_recovered, dues, fnf_amount, assets_returned, exit_interview, rehire ENUM, reason, status, timestamps` | `last_working_day, cleared_on` (date); `id_surrendered, access_revoked, dra_id_returned, data_handed_over, knowledge_transfer, final_clearance, cleared_by, clearance_remarks` (varchar 500); audit set | `ensureAuditCols():188–218` |
| `bonus_encashment` | **M+R** — `…000300…:155–165` | `id, tenant_id, employee_id, type, basis, amount, month, status, timestamps` | audit set, `fin_year` | `ensureAuditCols` |
| `transfers` | **R — NO MIGRATION AT ALL** | — | the entire 22-column table: `id, tenant_id, company_id, employee_id, type, from_company, from_branch, to_company, to_company_id, to_branch, to_department, effective_date, reason, status, applied_at, accept_token, accepted_at, order_sent_at, approver_id, approver_name, decided_by, decided_at, remarks, fin_year, timestamps` | `ApprovalService::ensureAuditCols():50–99` |
| `commission_payments` | **R** | — | `id, tenant_id, commission_id, employee_id, paid_on, amount, mode, reference, note, by, created_at` | `ApprovalService::ensureAuditCols():165–182` — **nested inside the `increments` branch** (DEF-REQ-06) |
| `commission_logs` | **R** | — | `id, tenant_id, commission_id, action(40), details(1000), by, at` | `ApprovalService::logCommission():277–287` |
| `salary_payments` | **R** | — | `id, tenant_id, employee_id, month(10), paid_on, amount, mode(30), reference, note, by, created_at` | `RequestController::ensureSalaryPayments():973–993` |

`2026_06_24_000000_ensure_runtime_feature_tables.php:126–159` retro-fits the generic audit set onto the eight migrated approvable tables so that *in principle* the runtime DDL is unnecessary — but `ensureAuditCols()` still runs on every request, because nothing checks whether the migration ran.

### 2.2 Leave tables

| Table | Provenance | Columns | Notes |
|---|---|---|---|
| `leave_types` | **M** — `2026_01_01_000200_create_departments_table.php:75–83` | `id, tenant_id, name, days_per_year decimal(5,1) default 0, carry_forward bool default false, paid bool default true, timestamps` | `carry_forward` is **captured by the UI and never read by any arithmetic** |
| `leaves` | **M+R** — `…000200…:85–98` | `id, tenant_id (NOT NULL), company_id (NOT NULL), employee_id, type_id (nullable), from_date, to_date, days decimal(4,1) default 1, status ENUM('pending','approved','rejected'), approver_id, reason, timestamps` | runtime adds `type_name, approver_name, decided_by, remarks` (varchar) and `decided_at` (ts) via `LeaveController::ensureCols():34–57`. **`ensureCols()` never widens the `status` ENUM**, so no fourth state (cancelled/withdrawn) is storable without a migration |
| `holidays` | **M** — `…000200…:100–107` | `id, tenant_id, date, name, applies_to, timestamps` | the company holiday calendar; consumed by payroll's LOP denominator, **not** by leave-day counting |
| `leave_applications` | **DOES NOT EXIST** | — | `app/Models/LeaveApplication.php` declares `$fillable = [company_id, employee_id, leave_type, from_date, to_date, days, reason, status]` implying this table. No migration creates it; no code references it. The similarly named migration `2026_01_01_000400_create_leave_applications_table.php` actually creates `pf_esi_settings`, `tds_rules`, `pt_slabs` and `tds_returns` (docblock: `// TDD §4.5 — Statutory (India compliance).`) |

**`leaves.type_id` is never written by any code path.** Verified by grepping `type_id` across `app/`: the only leave-related hits are two **reads** — `EssController.php:155` and `ReportController.php:101`. This resolves the reference doc's OQ-11 definitively: nothing populates it, ever. (See DEF-LVE-02.)

**`leaves` has no `type` column**, so `EssController.php:155`'s `$types[$a['type_id'] ?? null] ?? ($a['type'] ?? 'Leave')` degrades to the literal `'Leave'` on every row — the real value in `type_name` is never read there.

### 2.3 Notification / communication tables

| Table | Provenance | Notes |
|---|---|---|
| `notification_log` | **M+R (both)** — migration `2026_07_26_000000_p0_create_notification_log.php` (via `SchemaHelper::ensureTable`, idempotent) **and** runtime `NotificationService::ensureTable():50–70`, called on *every* `send()`, `feedForUser()`, `unreadCount()` | `id, tenant_id, company_id, user_id, employee_id, kind, title, body, url, channels(40), email_status(20), mail_log_id, dedupe_key, meta(text JSON), read_at, timestamps` |
| `mail_log` | **R** — `MailService::ensureTable():49–66` | `id, tenant_id, company_id, kind, recipient, subject, status(queued/sent/failed/skipped, indexed), error(text), sent_at, timestamps`. **No message body stored** |
| `wa_log` | **R** — `WaService::ensureLog():25–39` | `id, tenant_id, mobile(20), template, body_values(text JSON), kind, status(20) default 'failed', error(text), timestamps`. Declared statuses `sent\|failed`; `skipped` is also written at `:128` |
| `wa_templates` | **R** — `WaTemplateController::ensure():72–92` | `id, tenant_id (NULL = platform), purpose(20), name, language(10), category(20), body(text), sample_values(text), var_count(int), status(20), last_test_at, last_error(text), timestamps` |
| `wa_settings` | **M** — `2026_01_01_000600_create_salary_runs_table.php:135–146` | `provider, api_url, api_key (TEXT, **plaintext**), sender_number, waba_id, status`. `company_id` is NOT NULL in the migration and is relaxed to NULL at runtime by `MasterController::ensureCols():524–531` |
| `sms_settings` | **M** — `…000600…:148–159` | `provider, api_url, api_key (**plaintext**), sender_id, dlt_entity_id, dlt_principal_id, status` |
| `messages_log` | **M** — `…000600…:186–197` | `id, tenant_id, company_id, target, recipients(uint), channels, message(text), sent_by, sent_at, timestamps` — **one aggregate row per broadcast**, no per-recipient record |
| `notifications_feed` | **M — DEAD** — `…000600…:199+` | Created by migration; grep across `app/` returns **zero** references. Superseded by `notification_log` |
| `late_notifications` | **M** — `2026_07_22_000100_create_late_notifications.php` | dedupe ledger only; `down()` deliberately keeps the rows |
| `app_settings` | **R** — `ConfigController::ensureTable():30–37` | `id, tenant_id, ckey, value(longText), timestamps`, unique `(tenant_id, ckey)`. Holds the greetings config under `ckey='greetings'` |
| `notices` | **R** — `MasterController::ensureCols():501–507` creates any missing master table with `id, tenant_id, timestamps` and then adds the declared columns | Read by ESS (`EssController::notices():163–181`). **No migration anywhere** |
| `mobile_devices` | **R** — `MobileDeviceController::ensure():36–54` | `tenant_id, device_id(80, indexed **not unique**), device_name, platform, host, status(12), code(8), token(64), push_token(255), approved_by, approved_at, reject_reason, user_id, last_seen_at` |

### 2.4 Audit tables

| Table | Provenance | Notes |
|---|---|---|
| `activity_logs` (**plural**) | **M+R** — created by `2026_01_01_000700_create_salary_slips_table.php:13–23` as `id, tenant_id, user_id, action, entity, entity_id, detail(text), ip(45), created_at`; `Audit::ensure():25–46` adds `prev_hash` varchar(64) and `row_hash` varchar(64) indexed | The real trail. Note there is **no `updated_at`** and no DB-level immutability constraint |
| `activity_log` (**singular**) | **M** — `2026_05_30_025626_create_activity_log_table.php` + two follow-ups adding `event` and `batch_uuid` | Spatie `laravel-activitylog`. `config/activitylog.php` is stock. **Nothing in the application ever writes to it.** Two audit tables whose names differ by one character, one permanently empty |

### 2.5 Cross-area tables this area reads or writes

`employees` (approver resolution, ESS profile self-edit, greeting candidates, broadcast audience), `users` (in-app recipient resolution), `companies`, `departments`/`designations`/`branches`/`teams` (broadcast filters), `payslips` + `payroll_runs` (ledger, salary pay, bounce clawback targeting), `complaints` (grievance warning), `tenants` (the `RequestController:381` fallback), `settings` (financial year), `payroll_runs.cycle_label`.

---

## 3. Business rules register

Columns: **Rule ID · Business description · Inputs · Condition · Action · Scope · Where implemented · HARD-CODED? · Configurable today? · Could a customer want it different?**
`BR-*` IDs are taken from the reference catalogue and were re-verified against source. `NEW-*` IDs are rules the catalogue missed.

### 3.1 The request / approval engine

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented (file:line) | HARD-CODED? | Configurable today? | Customer may want different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-REQ-001 | Nine request types exist; each is a row in one static PHP array | `{module}` URL segment | key must exist in the registry | return `table/label/amount-column/threshold`; unknown → `{"ok":false,"error":"Unknown module"}` | global, all tenants | `ApprovalService.php:27–43`; `RequestController.php:26–29` | **Y** — the whole registry | **No.** Adding, renaming, hiding or disabling a type needs a code change + deploy. Every tenant sees every module | **Y — critical.** A customer will want their own request types (gate pass, asset request, travel authorisation, overtime, work-from-home), their own labels, and to switch off types they do not use |
| BR-REQ-002 | Approver = the employee's reporting manager; escalated one level for large money | employee row, tenant, amount, module threshold | `threshold > 0 && amount !== null && amount >= threshold` | escalate to the manager's own manager, else the label `Branch / Company Admin (amount ₹n)` | per request, at creation | `ApprovalService::resolveApprover():310–352`; call sites `RequestController.php:531`, `:687`; leave's own copy `LeaveController.php:96–117` | **Y** | **No** | **Y — critical.** One fixed hop is not a workflow |
| BR-REQ-003 | Every request is born Pending | — | always | `'status' => 'pending'` literal | all modules | `RequestController.php:538`, `:709` | **Y** | No | N (reasonable default), but the *state set* itself must be configurable |
| BR-REQ-004 | Tenant and company come from the EMPLOYEE, not the actor | employee row | always | `tenant_id`/`company_id` copied from `$emp` | all modules | `RequestController.php:535–537` | Y | No | N — correct behaviour. But five tables have no `company_id`, so the value is silently dropped |
| BR-REQ-005 | A decision may be made by an `isManager()` role **or** the named approver | user roles, `approver_id`, caller's employee id | `hasAnyRole(super_admin, admin, hr_manager)` OR `approver_id === my employee id` | write `status/decided_by/decided_at/remarks/updated_at`; else 403 | all modules | `RequestController.php:1396–1411`; role set at `:52–55` | **Y** — the three role names are literals | No | **Y.** A customer will want "the named approver only", or a delegate, or a finance role that can decide some types but not others |
| BR-REQ-006 | Both sides are emailed, best-effort | approver/employee `email` | counterparty must resolve to an employee row with a non-empty email | `MailService::queue`, kinds `request.submitted` / `request.<status>` | all modules | `RequestController.php:570–594`, `:1495–1519` | **Y** — recipients, subjects, headings, `lines` labels all literal | Channel/wording **not** configurable; SMTP server is | **Y — high.** Wording, language, channel (WhatsApp exists but is not wired), CC lists, and whether to notify at all |
| BR-REQ-007 | Commission gross → TDS → net is always recomputed server-side | `gross_amount`, `tds_rate` | `gross` numeric and `> 0` | `rate = clamp(tds_rate,0,100)` default **5.0**; `tds = round(gross*rate/100,2)`; `amount = round(gross - tds, 2)` | commissions only | `RequestController.php:433–440` (apply), `:665–667` (bulk), `:847–861` (edit) | **Y** — the 5.0 default is a literal in three places | A per-tenant `comm_tds_rate` exists in Statutory Rates (`AppController.php:11161`) but **this code does not read it** | **Y.** The default rate must come from statutory config, not a literal |
| BR-REQ-008 | Collection claims need five evidence fields + a positive base amount; simple claims need none | `claim_type`, `customer_name`, `account_ref`, `collection_type`, `collected_at`, `collection_mode`, `base_amount` | `stripos(claim_type,'simp') !== false` → simple (evidence unset); else collection (all five required) | 422 with a per-field message; `collected_at` → `Y-m-d H:i:s`; `collection_mode` normalised; `accounts_status='pending'` | commissions only | `RequestController.php:459–505`; bulk default at `:698–704` | **Y** — the field list, the messages and the `'simp'` substring test | No | **Y.** The evidence set is one industry's (debt collection). Another customer needs different mandatory fields per type |
| BR-REQ-009 | Accounts must confirm the money before a manager may approve | `accounts_status` | non-empty **and** ≠ `'confirmed'` | refuse approval, 422, distinct message when `flagged` | commissions only | gate `RequestController.php:1359–1364`; setter `accountsDecide():1636–1677` | **Y** | No — cannot be switched off, cannot be added to any other type | **Y.** This is a generic two-stage approval that only one module gets |
| BR-REQ-010 | DRA-certification gate on commission approval | `rates['dra_gate']` ∈ `off\|warn\|block` (default `warn`), `ComplianceController::draValid()` | gate ≠ `off` and DRA invalid | `block` → 422; `warn` → allow + `activity_logs` row `incentive_dra_invalid` | commissions only | `RequestController.php:1366–1394`; default `SettingsController.php:57` | Partly | **Yes** — Settings → Statutory Rates → DRA gate. `CONFIGURATION DEPENDENT` | The tri-state is good; the *subject* (DRA only) and the *scope* (commissions only) are hard-coded |
| BR-REQ-011 | Low compliance score and open grievances are warned-and-recorded, never blocked | `rates['incentive_min_compliance']` (default **60**), `complaints.status ∈ (open,pending,in_progress)` | score `< min`, or open count `> 0` | insert `activity_logs` `incentive_low_compliance` / `grievance_pending_incentive`; **approval always proceeds** | commissions only | `RequestController.php:1416–1454`; default `SettingsController.php:56` | The threshold is configurable; the **warn-never-block policy is hard-coded** | Threshold only | **Y.** A customer will want block-vs-warn here exactly as the DRA gate has |
| BR-REQ-012 | A commission stays editable after approval until locked | `locked_at`, roles, `approver_id` | not locked; `isManager()` OR named approver | validated field update + human field-diff into `commission_logs` tagged "(edited AFTER approval)" | commissions only | `RequestController.php:763–877` | **Y** (deliberate — "Ejaz's rule") | No | **Y.** Many customers require decisions to be immutable; this is a policy toggle |
| BR-REQ-013 | Full payment locks an entry forever; there is no unlock | remaining balance | `balance <= 0.005`, or admin `lockCommission`, or payroll payslip lock | set `locked_at/locked_by/lock_source`; every later decide/update/accountsDecide → 422 | commissions only | `:948–955`, `:1269–1295`, `:773–775`, `:1353–1355`, `:1653–1655` | **Y** | No — **no unlock endpoint exists anywhere in the codebase** | **Y.** An unlock-with-reason path is a normal correction requirement |
| BR-REQ-014 | **Decisions are NOT immutable — there is no pending-state guard** | — | none | any manager can re-approve/re-reject an already-decided request indefinitely, re-firing transfer orders, increment letters and decision emails, and overwriting prior remarks | all modules **and** leave | absence of a guard in `RequestController::decide():1330–1525` and `LeaveController::decide():392–466` | — | No | **DEFECT.** SmartPRS 3 must enforce a state machine |
| BR-REQ-015 | Bounce corrects the money story | `reason` (≤300), `commission_payments` sum, `locked_at` | not already bounced; not already rejected | if not locked → `status='rejected'`, `remarks='Bounced: …'`; if `paid > 0` → auto-create an **approved** clawback for the paid amount targeting current `Y-m` (next month if a non-draft `payroll_runs` row exists) | commissions only | `RequestController.php:1715–1838` | **Y** | No | Y — the recovery month rule and the auto-approve are policy |
| BR-REQ-016 | Only `payout_method = 'separate'` entries may be paid from the ledger | status, lock, payout method | status `approved`, not locked, method `separate` | insert `commission_payments`; refuse `with_salary` ("payroll pays it in the payslip") | commissions only | `RequestController.php:885–966` | Y | No | N — sound double-pay protection |
| BR-REQ-017 | A salary payment may not exceed the payslip's unpaid balance | `employee_id`, `month`, `amount` | a payslip with `net > 0` must exist; `amount ≤ net − paid + 0.005` | insert `salary_payments` keyed by employee+month | global | `RequestController.php:996–1056` | Y (the 0.005 tolerance) | No | N |
| BR-REQ-018 | Non-managers may claim commissions only for self or their own reportees | caller's employee row, target's `reporting_manager_id`/`reporting_manager` | `! isManager()` | 403 unless self or reportee; **bulk forces every row to self** | **commissions only** | `RequestController.php:409–419`, `:614–623`, `:655–657` | Y | No | **Y — this is the security hole**: the other eight modules have no such rule (SEC-02) |
| BR-REQ-019 | Collection proof must come from our own uploader | `proof_path` | must start with `'commission-proofs/'` | else silently `unset()` | commissions only | `:497–503`; upload `:1596–1613`; serve `:1616–1630` | Y | Mime list (`jpg,jpeg,png,webp,pdf`) and 5 MB cap are literals | Y — file types and size are policy. And the prefix check is exploitable (SEC-03) |
| BR-REQ-020 | Increment old/new/% are cross-computed so they cannot disagree | `old_ctc` (defaults to `employees.ctc`), `new_ctc`, `pct` | `new ≤ 0 && old > 0 && pct ≠ 0` → derive new; `new > 0 && old > 0` → derive pct; `new ≤ 0` → 422 | write all three; `amount := new_ctc` drives the threshold | increments only | `RequestController.php:510–530` | Y | No | N |
| BR-REQ-021 | Bulk commission upload: 1–1000 rows, per-row errors do not block good rows | `rows[]`, each `gross > 0` | validation per row | insert each as pending; collect `'Row <n>: …'` errors, return the first 30; **no per-row email**; approver resolved with **threshold 0** so bulk rows never escalate | commissions only | `RequestController.php:610–756` | **Y** — the 1000 cap, the 30-error cap, the 5% TDS default, the threshold-0 choice | No | Y — the cap and the no-email choice are operational policy |
| BR-REQ-022 | Every new request is stamped with the tenant's active financial year | `FinYearController::stamp($table, $tid)` | column added if missing | `fin_year` varchar(9) `"YYYY-YY"`, April–March | all modules | `RequestController.php:547–550`; filter `:165–176` | The Apr–Mar boundary is hard-coded in `FinYearController` | The *active* FY is a setting | Y — non-Indian or non-April FY customers |
| BR-REQ-023 | Schema self-heals on the request path (runtime DDL) | `$table` from the registry | column/table missing | `CREATE TABLE` / `ALTER TABLE ADD COLUMN` / `MODIFY status VARCHAR(20)`; every branch `try/catch(\Throwable){}` | all modules; 9 tables per inbox load | `ApprovalService::ensureAuditCols():46–260` | **Y** | No | **Y — must be removed.** Schema changes belong in migrations |
| BR-REQ-024 | Unknown submitted fields are discarded **without notice** | insert/update array, live column list | key not a real column | `array_intersect_key(row, array_flip(Schema::getColumnListing(table)))` — no error, no log, no user signal | every insert/update on this path | `ApprovalService::safeRow():262–266` | Y | No | **Y — DEFECT.** Nine confirmed silent data losses (§6, DEF-REQ-01) |
| BR-REQ-025 | **No SLA, no timeout escalation, no expiry, no cancellation, no withdrawal** | — | — | nothing | all modules and leave | absence: `routes/console.php:140–262` registers 12 schedules, none approval-related; no `due_at`/`sla_`/`reminder_sent_at` column exists; `decide()` accepts only `approve\|reject` (+`reopen` for commissions) | — | No | **Y — critical gap** |
| BR-REQ-026 | Only commissions may be reopened | `action` | `$module === 'commissions'` | `status='pending'`, `decided_by=NULL`, `decided_at=NULL` | commissions only | `:1342`, `:1403–1410`, `:1456–1463` | Y | No | Y |
| BR-REQ-027 | An admin may hard-delete orphaned commissions | rows whose `employee_id` LEFT JOINs to nothing | `tenant_id = mine **OR IS NULL**` | delete `commission_payments`, `commission_logs`, then `commissions` — **no audit record of any kind** | commissions only | `RequestController.php:1238–1266` | Y | No | Y — hard delete with no trail is unacceptable |
| BR-REQ-028 | The Approvals Inbox aggregates leave + all nine modules | tenant, caller's employee id, role | `status='pending'`; non-manager also `approver_id = my id` (else `-1`) | one flat list with `module/kind/id/employee/detail/approver` | global | `RequestController::inbox():1532–1591`; leave-only variant `LeaveController::inbox():469–500` | Y | No pagination, no limit, no filter | Y |
| BR-REQ-029 | An approved transfer or increment fires a document | `status === 'approved'` | module `transfers` → `TransferService::applyIfDue()` + `TransferController::sendOrder()`; module `increments` → `IncrementController::sendLetter()` | each in its own `try/catch` writing only `Log::warning`; the approval stands regardless | 2 modules | `RequestController.php:1465–1492` | **Y** — the module names are `if` literals | No | **Y.** "On approval, do X" is the archetypal workflow action and here it is two hard-coded `if`s |
| BR-REQ-030 | A payment voucher prints for separate payouts only | payment id | payment exists, its commission is in my tenant, `mode !== 'payslip'` | self-printing HTML letterhead `CPV-<6-digit>` | commissions only | `RequestController.php:1849–1928` | **Y** — the entire layout is PHP string concatenation | No | **Y.** Document layout must be a template, not code |
| **NEW-REQ-101** | A user with no `tenant_id` is silently attached to **the first tenant in the table** | `$user->tenant_id` | falsy | `$tid = DB::table('tenants')->value('id')` | `apply()` and `bulkCommissions()` | `RequestController.php:381`, `:630` | **Y** | No | **Y — DEFECT.** On a multi-tenant install this writes a request into an arbitrary tenant |
| **NEW-REQ-102** | Row-level self-scoping on the list endpoint exists for **commissions only** | module, role | `$module === 'commissions' && ! $manager` | restrict to own / approver / reportee rows | commissions only | `RequestController::list():155–164` | Y | No | **Y — SEC-01.** The other eight modules return every row of the tenant to any authenticated user |
| **NEW-REQ-103** | A decision writes `remarks` unconditionally, wiping any previous remarks | `remarks` (nullable) | always on decide | `'remarks' => $v['remarks'] ?? null` | all modules | `RequestController.php:1408`; same at `LeaveController.php:422` | Y | No | **Y — DEFECT.** A blank re-decide erases the prior decision's reasoning |
| **NEW-REQ-104** | The commission-ledger "someone else's ledger" 403 is **unreachable** | `?employee=`, role | for a non-manager `$emp` is set to `$me` at `:1082`, so the test `$emp->id !== $me->id` at `:1087` can never be true | a non-manager passing `?employee=Someone` silently gets **their own** ledger instead of a 403 | commissions | `RequestController.php:1074–1089` | — | — | Behaviour is safe but the code is dead and the doc describes it wrongly (DISC-03) |
| **NEW-REQ-105** | `exits` carries two competing "last day" columns | — | — | migration creates `last_day`; runtime self-heal adds `last_working_day`; the SPA posts only `last_working_day` | exits | `…000500…:85` vs `ApprovalService.php:191`; `AppController.php:3946` | Y | No | Y — any consumer reading `last_day` sees NULL forever |
| **NEW-REQ-106** | `denyUnlessRole()` **fails OPEN** on any exception | roles, user | `catch (\Throwable)` | `return null` = allowed | **71 call sites across 30 files** (doc-measured), incl. `payCommission`, `salaryPay`, `lockCommission`, `cleanOrphanCommissions`, `commissionPaymentVoucher`, `SendMessageController::send`, and every `FeaturePermissions::guard()` | `ApprovalService.php:365–380`; inherited by `FeaturePermissions.php:99–105` | **Y — deliberate**, stated in the docblock and an inline comment | No | **Y — SEC-05** |

### 3.2 Leave

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HARD-CODED? | Configurable today? | Customer may want different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-LVE-001 | Non-managers may only apply for themselves | user roles, caller's employee row | `! isManagerRole()` | **overwrite** the posted `employee` with the caller's own `emp_code` (or name); unlinked login → 422 | leave only | `LeaveController.php:296–302` | **Y** — the three role names | No | Y — some customers want a secretary/team-lead to file on behalf |
| BR-LVE-002 | Leave days are inclusive **calendar** days | `from`, `to` | reversed range silently swapped | `days = from.diffInDays(to) + 1` | leave only | `LeaveController.php:329–338` | **Y** | No | **Y — critical.** No weekly-off exclusion, no holiday exclusion, no half-day. Fri→Mon costs 4 days |
| BR-LVE-003 | Balance is enforced for paid, quota-bearing types only | posted `type` string, computed balances | posted type must **exactly match** a computed row, be `paid`, and have a non-null balance | quota 0 → 422 "no yearly quota configured"; `days > (balance − pending)` → 422 "Insufficient … Use Loss of Pay for the excess" | leave only | `LeaveController.php:340–360`, `:191–244` | Y | Quota per type is configurable data | **Y** — and it is **bypassable** (SEC-06) |
| BR-LVE-004 | **Carry-forward is captured but never applied** | `leave_types.carry_forward` | — | the docblock at `:186–189` promises "entitlement + **carry-forward opening** − used − pending"; the code at `:239` computes `round($ent - $u, 1)` | leave only | comment `:186–189` vs code `:228–241`; column `…000200…:79`; UI `AppController.php:10768`; master CRUD `MasterController.php:72–73` | — | The flag is editable in Masters and has **no effect whatsoever** | **Y — DEFECT/CONFLICT** |
| BR-LVE-005 | **No accrual, no pro-rating, no lapse, no encashment** | — | — | the full annual `days_per_year` is available from 1 January regardless of joining date; unused balance simply vanishes at the year boundary | leave only | `computeBalances():191–244` has no date arithmetic beyond `whereYear`; "Leave Encashment" exists only as a **bonus-module label** (`AppController.php:3939`) with no link to any balance | — | No | **Y — critical.** Indian earned leave normally accrues monthly, carries forward to a cap, lapses above it, and is encashed at exit |
| BR-LVE-006 | Leave approval: never on an `employee` login; otherwise manager or named approver | roles, `approver_id` | `hasRole('employee')` → hard 403 **regardless of `approver_id`**; else `isManagerRole()` OR `approver_id === my id` | write status/decided_by/decided_at/remarks | leave only | `LeaveController.php:407–427`; same rule drives `canDecide` at `:142` | **Y** | No | Y |
| BR-LVE-007 | The employee is emailed on a decision — and **only** on a decision | employee email | decide succeeds and the employee has an email | `emails.generic`, kind `leave.approved`/`leave.rejected`, 5-row detail table | leave only | `LeaveController.php:431–460` | Y | No | **Y — DEFECT.** **No notification of any kind when leave is submitted.** The approver learns of it only by opening the inbox. Contrast `RequestController::apply():570–594` which does email |
| BR-LVE-008 | Approved PAID leave counts as attendance; unpaid does not — **but the flag never arrives** | `leaves.type_id`, `leave_types.paid` | intended: `paid := type_id IS NULL ? true : leave_types[type_id].paid == 1`; skip when not paid | actual: `apply()` writes `type_name` and **never `type_id`**, so `type_id` is always NULL, `paid` is always true, the `continue` is dead code, and **every approved leave including "Loss of Pay" is credited as a paid present day** | leave ↔ payroll boundary | insert `LeaveController.php:364–378` vs `PayrollGenController.php:1055, 1063–1067` and `:743–746` | — | No | **Y — DEFECT, high financial impact** |
| BR-LVE-009 | Non-managers see only their own leave | role, caller's employee id | `! $manager` | `where('l.employee_id', myId ?? -1)`; inbox uses `approver_id` | leave only | `LeaveController.php:136`, `:482–484` | Y | No | N — correct. (Contrast NEW-REQ-102) |
| BR-LVE-010 | **Any authenticated user can read any colleague's leave balances** | `{employee}` path segment | matched by `name` OR `emp_code` OR numeric `id`, tenant-scoped, not soft-deleted. **No role check at all** | return per-type entitlement/used/pending/balance | leave only | `LeaveController::balances():247–264`; route `routes/web.php:420` | — | No | **Y — SEC-07.** Leave consumption is a proxy for sickness patterns |
| BR-LVE-011 | Leave types come from the tenant, with **three divergent hard-coded fallbacks** | `leave_types` rows | table empty for the tenant | (a) apply dropdown → 5 names; (b) `computeBalances()` → 5 objects **with quotas 12/12/15/0/0**; (c) `EssController::leaveBalances()` → **4 objects, no Comp-Off** | leave + ESS | `LeaveController.php:175–177`, `:199–207`; `EssController.php:194–201` | **Y ×3** | Configured types override them | **Y — DEFECT.** Three sources of truth for one concept |
| BR-LVE-012 | **No cancellation, no half-day, no team calendar, no attachments** | — | — | `leaves.status` is `ENUM('pending','approved','rejected')` and `ensureCols()` never widens it, so a fourth state cannot even be stored | leave only | `…000200…:93`; `LeaveController::ensureCols():34–57`; absence of routes at `routes/web.php:419–422` | — | No | **Y — critical** |
| **NEW-LVE-101** | ESS "remaining" and the leave module's enforced balance **disagree** | `entitlement`, `used`, `pending` | always | `LeaveController` publishes `balance = ent − used` but *enforces* `balance − pending`; `EssController` publishes `remaining = ent − used` and never subtracts pending | leave vs ESS | `LeaveController.php:239` + `:353` vs `EssController.php:230` | Y | No | **Y — CONFLICT.** An employee sees "5 remaining" in My Space and is refused a 5-day application |
| **NEW-LVE-102** | Balance queries are **not tenant-scoped** | `employee_id` | always | `DB::table('leaves')->where('employee_id', …)->whereYear('from_date', $year)` — no `tenant_id` predicate | leave + ESS | `LeaveController.php:212–215`; `EssController.php:205–206` | Y | No | Safe today only because `employees.id` is globally unique; fragile |
| **NEW-LVE-103** | The balances endpoint also matches on **raw numeric id** | `{employee}` | `is_numeric($employee)` | `orWhere('id', (int) $employee)` | leave | `LeaveController.php:254` | Y | No | Y — makes enumeration of colleagues trivial (compounds SEC-07) |
| **NEW-LVE-104** | Tolerant employee resolution loads the whole tenant's employee table into PHP | posted employee string | exact `emp_code`/`name` match failed | `foreach ($empBase()->get() as $cand)` — every column of every employee row, then whitespace/case-normalised comparison | leave apply | `LeaveController.php:315–324` | Y | No | Y — O(n) memory per application on a large tenant |
| **NEW-LVE-105** | `leaves.tenant_id` and `leaves.company_id` are **NOT NULL** but are copied from the employee | `$emp->tenant_id`, `$emp->company_id` | employee has a NULL company | insert throws → caught at `:386` → 422 carrying the raw SQL error to the client | leave apply | `…000200…:87–88` vs `LeaveController.php:365–366` | Y | No | Y — an employee with no company can never take leave, with an unhelpful error |
| **NEW-LVE-106** | The leave year is the **calendar** year while everything else is the Indian financial year | `now()->year` | always | `whereYear('from_date', $year)`; a leave spanning 31 Dec → 2 Jan is attributed entirely to the from-year | leave + ESS | `LeaveController.php:193`, `:214`; contrast `FinYearController` Apr–Mar used by requests (BR-REQ-022) and payroll | **Y** | No | **Y — CONFLICT.** Customers whose leave year is Apr–Mar (most Indian employers) cannot have it |
| **NEW-LVE-107** | Balance is matched on the leave-type **NAME string** | `leaves.type_name`, `leave_types.name` | always | `$taken[$t->name]` keyed by the free-text name | leave + ESS | `LeaveController.php:217`, `:231–232`; `EssController.php:209`, `:223–224` | Y | No | **Y — DEFECT.** Renaming a leave type orphans every historic row's usage against it and silently resets consumption to zero |
| **NEW-LVE-108** | Leave has its **own** approver resolver, divergent from `ApprovalService` | employee row, tenant | — | no threshold escalation; different null-label behaviour (`''` when `$emp` is null vs `'Reporting Manager / HR'`) | leave only | `LeaveController::resolveApprover():96–117` vs `ApprovalService::resolveApprover():310–352` | Y | No | Y — two copies of the single most reused rule in the product |

### 3.3 Employee Self-Service

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HARD-CODED? | Configurable today? | Customer may want different? |
|---|---|---|---|---|---|---|---|---|---|
| **NEW-ESS-101** | ESS resolves the subject **only** from the session; no endpoint accepts an employee id | session user | `users.employee_id` → `employees.id`; else `employees.email = users.email` **OR** `employees.name = users.name`, tenant-scoped | return `{ok:true, linked:false}` when unlinked | ESS | `EssController::currentEmployee():43–59`; `me():18–41`; `updateProfile():262` | Y | No | See §7 for the full answer to the ESS-scoping question — **no ESS transaction takes an employee id from the request** |
| **NEW-ESS-102** | Ten personal fields are self-editable; org-controlled fields are not | posted fields | field must be present in the request **and** exist as a column | update `employees` for the caller's own id only | ESS | `EssController::updateProfile():259–294`; whitelist `:266–277` | **Y** — the whitelist is a literal validation array | No | **Y.** Which fields an employee may self-edit, and whether the change needs HR approval, is per-customer policy |
| **NEW-ESS-103** | `dob` is accepted as **any string ≤ 20 chars** | `dob` | `['nullable','string','max:20']` — **no date rule** | stored verbatim | ESS | `EssController.php:276` | Y | No | **Y — DEFECT.** And `GreetingService` selects birthday recipients by `DATE_FORMAT(dob,'%m-%d')`, so an employee can choose the day they are greeted |
| **NEW-ESS-104** | `email` is self-changeable with **no verification** | `email` | valid email format | overwrite `employees.email` | ESS | `EssController.php:269` | Y | No | **Y — SEC-08.** Payslips, leave decisions and request decisions all go to `employees.email`; an employee can redirect them instantly |
| **NEW-ESS-105** | **ESS self-edits are entirely unaudited** | — | — | no `Audit::record`, no `AuditTrail::log`, no `activity_logs` row | ESS | absence in `EssController::updateProfile():259–294` | — | No | **Y — DEFECT.** Compare leave apply (`LeaveController.php:383`) and request apply (`RequestController.php:556`), both of which do audit |
| **NEW-ESS-106** | ESS payslip PDF links are gated by a company policy | `SettingsController::payslipSelfAllowed($e)` | policy denies | `pdf` is `null`; HR can still download | ESS | `EssController::payslips():111`, `:121` | No | **Yes** — rev172 company payslip-download policy. `CONFIGURATION DEPENDENT` | N |
| **NEW-ESS-107** | ESS list sizes are fixed literals | — | always | 6 payslips, 6 leave rows, 5 notices; attendance is the current calendar month only | ESS | `:114`, `:150`, `:175`, `:131–132` | **Y** | No | Y (minor) |
| **NEW-ESS-108** | ESS leave rows always render the type as the literal `'Leave'` | `type_id`, non-existent `type` column | always | `$types[$a['type_id'] ?? null] ?? ($a['type'] ?? 'Leave')` → `'Leave'` | ESS | `EssController.php:155` | — | No | **Y — DEFECT.** The real value is in `type_name`, which is never read |
| **NEW-ESS-109** | Neither ESS route is rate-limited | — | — | both sit in the plain authenticated `/app` group | ESS | `routes/web.php:598–599` | — | No | Y |

### 3.4 Notifications, WhatsApp, push, greetings

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HARD-CODED? | Configurable today? | Customer may want different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-NOT-001 | Every notification is best-effort and never breaks the business action | — | any exception | `Log::warning` + `return null`; callers wrap again in their own `try/catch` | all channels | `NotificationService.php:130–134`; `MailService.php:120–124`; `WaService.php:161–163`; `RequestController.php:592–594`, `:1517–1519`; `LeaveController.php:458–460` | **Y — by design** | No | Y — a customer needs to know when a statutory notice failed to send |
| BR-NOT-002 | In-app notifications are per app-user and ownership-checked | `user_id`, `tenant_id` | feed/count filter on `user_id`; `markRead` additionally requires `user_id` to match | null `user_id` → empty feed, zero count | in-app | `NotificationService.php:229–306`; `NotificationController.php:20–64` | Y | Fixed page size 30 | N (correct) |
| BR-NOT-003 | Dedupe keys make notifications idempotent | `kind`, `dedupe_key` | a row with the same pair exists | `send()` returns `null`, writes nothing | in-app + email | `NotificationService.php:92–94`, `:142–160`. Known keys: `greeting:birthday:<emp>:<year>`, `greeting:anniversary:<emp>:<year>`, `absence:<emp>:<Y-m-d>`, `absence:<emp>:<Y-m-d>:mgr`, `probation:<emp>:<end>:<milestone>:u<uid>` | Y | No | N — good design. Note `alreadySent()` returns `false` on exception, i.e. it fails towards **re-sending**, never towards silence |
| BR-NOT-004 | Email uses the company's own SMTP, with a platform fallback | tenant, company | no host → retry `mailConfigFor(null,'0')`; still none → mark `skipped` and return | runtime `config()` override of `mail.*`, then `Mail::send('emails.generic')` | email | `MailService::deliver():131–201` | The blade name and the `encryption==='none' → null` mapping | **Yes** — per-company SMTP is configuration | N |
| BR-NOT-005 | Email is queued with 3 tries / 30 s backoff; critical mail is sent inline | `$msg['sync']` | truthy → `deliver()` in-process; else `SendNotification::dispatch()` | job `tries=3`, `backoff=30`, `onQueue('mail')`; `deliver()` re-throws after marking `failed` so the queue retries | email | `MailService.php:102–119`; `app/Jobs/SendNotification.php:33–46` | **Y** — 3, 30 and the queue name `'mail'` are literals | No | Y. **Operational trap:** a worker started as plain `php artisan queue:work` never drains the `mail` queue; nothing enforces `--queue=mail` |
| BR-NOT-006 | Demo workspaces never send real messages | `DemoAccessController::isDemoTenant($tid)` | true | write a `mail_log`/`wa_log` row with `status='skipped'` and an explanatory `error`, return without sending | email + WhatsApp | `MailService.php:81–90`; `WaService.php:121–136` | Y | No | N |
| BR-NOT-007 | Broadcasts resolve an audience by one of eight filters | `target`, `value`, `channels[]` | `denyUnlessRole(['admin','hr_manager'])` (**fails open**) | load **every** active non-deleted employee of the tenant, hydrate dept/team/desig/branch/type, filter **in PHP** with `strcasecmp` | broadcast | `SendMessageController.php:19–203`. Targets: `all` (default) · `company` · `department` · `team` · `designation` · `branch` · `type` (`Field / FOS`\|`Office`) · `employee` (`emp_code`) | **Y** — the eight targets and the `Field / FOS`/`Office` labels are literals | Channel selection per send; provider config in Settings | **Y.** Audience segmentation must be a saved, reusable, rule-based definition |
| BR-NOT-008 | WhatsApp sends only pre-approved templates, **India-only** | `mobile`, `template`, `bodyValues[]` | config resolves; number yields ≥10 digits | strip non-digits, take the **last 10**, POST with `countryCode` hard-coded `'+91'`, `type:'Template'`, `languageCode` default `en` | WhatsApp | `WaService::sendTemplate():112–183`; `'+91'` at `:148` | **Y** | No | **Y.** Any non-Indian number is unreachable by construction. No retry, no queue — one synchronous shot |
| BR-NOT-009 | Template names resolve tenant-first, then platform, then env, then a literal default | `purpose`, `tenant_id` | `wa_templates.status = 'approved'` | ordered so the tenant's own row beats the platform's; fallback `env('INTERAKT_TEMPLATE_<PURPOSE>')` → `smartprs_welcome`/`_payment`/`_renewal`/`_lead`/`_otp` → the purpose string itself | WhatsApp | `WaService::templateNameFor():74–103` | The five default names | Template name/body per purpose is configurable data | N |
| BR-NOT-010 | A successful test send **proves** approval | template row, `mobile` | Interakt returns 2xx | set `status='approved'`, stamp `last_test_at`; on failure store Interakt's error in `last_error` | WhatsApp | `WaTemplateController::testSend():384–433`; auto-approve at `:417` | Y | No | Y — inferring approval from a 2xx is a heuristic. Also the error lookup at `:414` is **not tenant-scoped** (SEC-10) |
| BR-NOT-011 | Editing a template invalidates its approval | existing row vs posted row | `status !== 'draft'` and `body`/`name`/`language`/`category` changed | force `status='draft'`, clear `last_error` | WhatsApp | `WaTemplateController.php:298–304` | Y | No | N — correct |
| BR-NOT-012 | Template names must match WhatsApp's rule; variables must be numbered without gaps | `name`, `body` | `regex:/^[a-z0-9_]+$/`; `var_count = max({{n}})`; `count(unique) !== max` → reject | 422 with a friendly message | WhatsApp | `WaTemplateController.php:268`, `:276–281` | Y | No | N — it is Meta's rule |
| BR-NOT-013 | Template scope: super admin = platform (`tenant_id IS NULL`); admin/hr/hr_manager = own tenant; anyone else 403 | roles | `abort_unless($isSuper \|\| $isAdmin, 403)` | scope predicate on every query | WhatsApp | `WaTemplateController::scope():205–213` | Y | No | N. **Note this uses `abort_unless`, so it fails CLOSED** — the opposite of `denyUnlessRole` (NEW-REQ-106) |
| BR-NOT-014 | Interakt delivery status is accepted from an **unauthenticated, unsigned, unthrottled, cross-tenant** webhook | raw `$request->all()` | any of `"read"`,`"delivered"`,`"sent"`,`"failed"` appears **anywhere** in the serialised JSON | update the newest matching `recruitment_messages` row if the new rank ≥ current (`queued 0 < sent/failed 1 < delivered 2 < read 3`); all exceptions swallowed; always `{"ok":true}` | recruitment messaging | route `routes/web.php:198–204` (CSRF-exempt, no throttle); handler `RecruitMessagingController::interaktWebhook():380–417` | Y | No | **Y — SEC-04** |
| BR-NOT-015 | Greetings are opt-in per tenant, at a chosen hour, deduped per year | `app_settings.ckey='greetings'` | `enabled` true; `send_hour` == current hour unless `--all-hours` | select employees where `DATE_FORMAT(dob,'%m-%d')` or `DATE_FORMAT(doj,'%m-%d')` = today; birthday needs `birthday.enabled`; anniversary needs `anniversary.enabled` and `years >= min_years` | greetings | `GreetingService.php:124–234`; `SendGreetings.php:30–47`; schedule `routes/console.php:245–250` (hourly) | Subject/message/enabled/hour/min_years are **configuration**; the sweep logic, the channels and the placeholder set are code | **Yes, substantially** — the whole config object is per-tenant JSON | Partly. See the gaps below |
| BR-NOT-016 | Placeholders render; unknown tokens blank out | `{{token}}` | `array_key_exists` | value, else `''` | greetings + late template | `GreetingService::render():252–257`; variable list `GreetingController.php:34–36` | **Y** — the five variables `{{name}} {{first_name}} {{company}} {{years}} {{date}}` (+4 late-only) are a literal set | No | **Y.** A customer will want `{{designation}}`, `{{manager}}`, `{{branch}}`, custom fields |
| BR-NOT-017 | The late-arrival email **bypasses the mail engine** | `rates['late_email_enabled']` (default **0**) | enabled, punch date is today or yesterday, not already sent | render the `greetings.late` template, CC the reporting manager, send via **`Mail::raw`** | attendance | `LateArrivalService.php:289`; toggle `SettingsController.php:60`; dedupe `late_notifications` | Y | The toggle and the template text are configurable | **Y — DEFECT.** Uses the global `config/mail.php` mailer (default `MAIL_MAILER=log`), **not** per-company SMTP; writes **no `mail_log` row**; no branding, no queue, no retry |
| BR-NOT-018 | A daily error digest is emailed to ops | `env('SMARTPRS_OPS_EMAIL')` | env set, log file exists, ≥1 `ERROR\|CRITICAL\|ALERT\|EMERGENCY` line in the window | read the last ~2 MB / 4000 lines, email the count + the **last 10** lines truncated to 240 chars each | ops | `ErrorDigest.php:23–98`; tail `:101–116`; schedule `routes/console.php:166` (07:45) | **Y** — 4000, 2 MB, 10, 240, the four level names | `--days` option only | Y. Log lines can carry query bindings and are emailed off-platform |
| BR-NOT-019 | Mobile devices register as Pending and must be approved | `device_id`, `device_name`, `platform`, `workspace` | tenant must resolve by host or slug | upsert on `(tenant_id, device_id)`; new rows `pending` with a 6-char code; approval issues `Str::random(48)` | mobile | `MobileDeviceController.php:84–138`, `:218–234`; routes `routes/web.php:79–87` (throttled 60/120/30 per min) | Y | No | N |
| BR-NOT-020 | **The device gate is not enforced anywhere on the server** | — | — | no middleware, controller or route ever reads `mobile_devices.token`; `app/Http/Middleware/` holds six files, none device-related; grep for `mobile_devices` outside its own controller returns nothing | mobile | absence | — | No | **Y — SEC-09.** It is marketed as "the anti-fraud layer"; it is a client-side UX flow |
| BR-NOT-021 | **Push tokens are collected and never used** | `device_id`, `token` | — | `UPDATE mobile_devices SET push_token=? WHERE device_id=?` — **no tenant scope, no ownership check, no LIMIT**; returns `{ok:false}` with **HTTP 200** on any exception | mobile | `MobileDeviceController::pushToken():173–189`. Exhaustive grep for `fcm\|firebase\|push_token\|sendPush` across `app/`, `config/`, `routes/`, `composer.json` returns **only three lines, all in this one controller** (`:47`, `:183`, `:210`) | — | No | **Y — SEC-11 (the endpoint) and a capability gap (no sender exists)** |
| BR-NOT-022 | Revoke is literally reject | device id | — | `public function revoke(…) { return $this->reject($request, $id); }` | mobile | `MobileDeviceController.php:255–259` | Y | No | Y (minor) |
| BR-NOT-024 | **No user-level notification preferences exist** | — | — | channel choice is per **tenant** (greetings config) or per **call site** (`in_app`/`email` flags in the `$n` array). No opt-out, no unsubscribe link | all | `NotificationService.php:87–88`; absence of any preference table | — | No | **Y — high.** DPDP-adjacent and a basic expectation |
| **NEW-NOT-101** | Broadcast SMS/WhatsApp is **synchronous, per recipient, inside the HTTP request** | recipient list | channel selected and a provider is active | one `Http::timeout(15)` POST per recipient, in a `foreach` | broadcast | `SendMessageController.php:72–95`, `:234–266` | Y | No | **Y — DEFECT.** A 500-employee WhatsApp broadcast can occupy the PHP worker for up to ~2 hours |
| **NEW-NOT-102** | The provider POST is a "shotgun payload" to an admin-supplied URL | `sms_settings`/`wa_settings` row | `api_url` non-empty | POST a form body carrying the API key under **three** field names (`apikey`, `authkey`, `api_key`) plus `sender/from`, `mobile/mobiles/to`, `message/text/body`, to whatever host the admin typed — **no scheme or host allowlist** | broadcast | `SendMessageController::postProvider():234–266` | Y | The URL is admin-configurable — that *is* the problem | **Y — SEC-12 (SSRF + credential exfiltration)** |
| **NEW-NOT-103** | Broadcast logging is one aggregate row; per-recipient SMS/WhatsApp outcomes are invisible | counts | always | one `messages_log` row: `target`, `recipients` (int), `channels`, `message` = subject + first 200 chars, `sent_by`, `sent_at` | broadcast | `SendMessageController.php:109–125` | Y | No | **Y.** A failed SMS to one employee cannot be found, retried or proven |
| **NEW-NOT-104** | Broadcast `body` has **no maximum length** | `body` | `['required','string']` | stored/sent whole; only the log is truncated to 200 chars | broadcast | `SendMessageController.php:27` | Y | No | Y (minor) |
| **NEW-NOT-105** | The broadcast filter maps are **not tenant-scoped** | — | always | `DB::table('departments')->pluck('name','id')` (and designations, branches, teams) with no `tenant_id` predicate | broadcast | `SendMessageController.php:165–168` | Y | No | Y — another tenant's department name can hydrate the label used for matching |
| **NEW-NOT-106** | `send_hour` is the **server's** hour, not the tenant's timezone | `now()->format('G')` | always | every tenant whose `send_hour` matches the app-timezone hour fires | greetings | `SendGreetings.php:40`; docblock at `:11–13` claims "every company fires at its own local time" | **Y** | The hour is configurable; the **timezone is not** | **Y — CONFLICT** between the docblock and the code |
| **NEW-NOT-107** | The greeting candidate query is **non-sargable** | `m-d` | always | `DATE_FORMAT(dob,'%m-%d') = ?` OR `DATE_FORMAT(doj,'%m-%d') = ?` — MySQL cannot use an index on `dob`/`doj` and full-scans `employees` per tenant per matching hour | greetings | `GreetingService::candidates():164–177` | Y | No | Y (performance). Parameters are bound — **no SQL injection** |
| **NEW-NOT-108** | 29-February birthdays are never greeted in a common year | `dob` | `md = '02-29'` | matches only in a leap year | greetings | `GreetingService.php:134`, `:172–173` | Y | No | Y (minor but real) |
| **NEW-NOT-109** | Tenants with zero employees, and NULL/0-tenant employees, are never greeted | — | `--tenant` not given | tenant list is derived from `DB::table('employees')->distinct()->pluck('tenant_id')->filter()` | greetings | `GreetingService.php:136–138` | Y | No | Y (minor) |
| **NEW-NOT-110** | `hide_birth_year` is **dead configuration** | — | — | declared in `defaults()`, surfaced on the config screen, **never read anywhere** | greetings | `GreetingService.php:36`; no consumer | Y | Editable and inert | Y — a visible setting that does nothing |
| **NEW-NOT-111** | The disciplinary late-arrival template lives inside the **Greetings** config object | — | — | HR editing "Greetings" is also editing the late-arrival warning email; its *sending* is controlled by a different setting entirely (`late_email_enabled` in Statutory Rates) | greetings / attendance | `GreetingService::defaults():54–58`, comment `:50–53` | Y | The text is configurable, in the wrong place | Y |
| **NEW-NOT-112** | `GET /app/greetings/config` and `GET /app/greetings/log` have **no role guard** | — | authenticated only | any user reads the tenant's greeting templates and the delivery log (which discloses which `employee_id` had a birthday on which date) | greetings | `GreetingController::config():25–41`, `log():169–188`; contrast the guarded `saveConfig():46` and `test():125` | — | No | **Y — SEC-13** |
| **NEW-NOT-113** | `saveConfig` accepts **any** array shape | `config` | `is_array()` only | `array_replace_recursive(defaults, posted)` merges arbitrary keys into the persisted JSON; no key whitelist, no length limits on templates | greetings | `GreetingController.php:51–57` | — | No | Y (low) |
| **NEW-NOT-114** | The 17-template tenant WhatsApp library has **no consumers** | — | — | `tenantLibrary()` seeds ready-to-submit bodies for `leave_decision`, `approval_pending`, `request_decision`, `commission_approved`, `transfer_order`, `payslip_ready`, … and **not one is wired to a sender**. Grepping `WaService::sendTemplate` callers finds only `WaTemplateController::testSend` and the platform signup/billing/OTP flows | WhatsApp | `WaTemplateController::tenantLibrary():130–185`, `TENANT_PURPOSES:46–65`; leave and requests send **email only** (`LeaveController.php:446`, `RequestController.php:578`, `:1504`) | — | No | **Y — a catalogue with no plumbing.** The customer-visible promise is unmet |
| **NEW-NOT-115** | Provider API keys are stored in **plaintext** | — | — | `wa_settings.api_key` / `sms_settings.api_key` are plain `TEXT` columns, no `Crypt::encryptString` | settings | `…000600…:141`, `:154`; `WaService::config():54–55` | Y | No | **Y — SEC-14.** Other secrets in the codebase (e.g. the Razorpay webhook secret) *are* encrypted |
| **NEW-NOT-116** | `wa_log.body_values` stores the **full JSON array of template variables** | send payload | always | for `smartprs_welcome` the fifth variable is the **temporary password** (`Temporary password: {{5}}`), written in clear and retained forever | WhatsApp | `WaService.php:171`; template body `WaTemplateController.php:103–104` | Y | No | **Y — SEC-01, credential-grade** |
| **NEW-NOT-117** | **No retention or pruning exists for any log table** | — | — | `activity_logs`, `notification_log`, `mail_log`, `wa_log`, `commission_logs`, `messages_log`, `late_notifications` all grow forever. Spatie's `delete_records_older_than_days = 365` applies to the unused `activity_log` table and its clean command **is not scheduled** | all | absence in `routes/console.php:140–262`; `config/activitylog.php` | — | No | **Y — the published Privacy Policy commits to 90-day retention (`PolicyController.php:12`); nothing implements it** |
| **NEW-NOT-118** | `notifications_feed` is a migrated, completely unused table | — | — | created by `…000600…:199+`; grep across `app/` returns zero references | — | migration only | — | — | Y — dead schema |

### 3.5 Audit trail

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HARD-CODED? | Configurable today? | Customer may want different? |
|---|---|---|---|---|---|---|---|---|---|
| **NEW-AUD-101** | Every audited action appends a SHA-256 hash-chained row | tenant, user, action, entity, entity_id, detail, timestamp | `activity_logs` exists | `prev = last row_hash ?: 'GENESIS'`; `row_hash = sha256(prev\|tenant\|user\|action\|entity\|id\|detail\|when)` | global | `Audit::record():68–100`; payload `:49–61` | Y | No | N — the mechanism is sound |
| **NEW-AUD-102** | The chain is **global, not per tenant** | — | always | `orderByDesc('id')->value('row_hash')` across the whole table | global | `Audit.php:91` | Y | No | Y — verifying one tenant requires reading every tenant's rows |
| **NEW-AUD-103** | The chain has a **race condition** | concurrent writes | two `record()` calls interleave | both read the same `$prev` and chain onto it; `verify()` walks by `id` and reports the second as **broken** | global | `Audit.php:91–96` — read-then-insert, **no transaction, no lock** (grep for `DB::transaction\|lockForUpdate` in `Audit.php`, `RequestController.php`, `LeaveController.php` returns **nothing**) | — | No | **Y — DEFECT.** False tamper alarms under normal concurrency |
| **NEW-AUD-104** | **Four code paths write to `activity_logs` directly, breaking the chain** | — | commission approval with a DRA/compliance/grievance warning, or a bounce | `DB::table('activity_logs')->insert([...])` with **no `prev_hash` and no `row_hash`**. `verify()` treats a null `row_hash` as a legacy row, **skips it and resets `$prev` to `'GENESIS'`** — destroying tamper-evidence for everything after it | commissions | inserts at `RequestController.php:1382–1387`, `:1424–1429`, `:1444–1449`, `:1822–1827`; skip logic `Audit::verify():118–121` | — | No | **Y — SEC-15** |
| **NEW-AUD-105** | Auditing is best-effort and never throws | — | any exception | swallowed silently at `Audit.php:97–99` | global | | **Y** | No | **Y.** A silent audit gap is possible with no alert |
| **NEW-AUD-106** | `verify()` loads the entire table into memory | — | always | `DB::table('activity_logs')->orderBy('id')->get()` | global | `Audit::verify():111` | Y | No | Y — O(n) blow-up on a mature install. Uses `hash_equals` (timing-safe) — that part is correct |
| **NEW-AUD-107** | Actors are recorded as **name strings**, not user ids | `$me->name` / `$user->name` / `$user->email` | always | `decided_by`, `entered_by`, `by`, `accounts_by`, `locked_by`, `bounced_by` all hold a display name | all modules | `RequestController.php:869`, `:929`, `:1036`, `:1285`, `:1406`, `:1656`, `:1736`; `LeaveController.php:420` | Y | No | **Y — DEFECT.** A renamed or deleted user makes the trail ambiguous; two employees with the same name are indistinguishable |
| **NEW-AUD-108** | `AuditTrail::change()` uses loose comparison and logs nothing when nothing changed | before/after arrays | `$old != $v` | `'0'` vs `0` vs `false` register as unchanged; returns early with **no log at all** when the diff is empty | F1–F10 config screens | `AuditTrail.php:78–91` | Y | No | Y (minor) |
| **NEW-AUD-109** | **`AuditTrail::change()` — the only old→new helper — is never used by this area** | — | — | requests, leave, ESS and commissions record only *what happened*, never *what the values were before and after* | this whole area | grep: `AuditTrail::change` has no call site in `RequestController`, `LeaveController`, `EssController` | — | No | **Y — critical.** See §8 |
| **NEW-AUD-110** | Spatie `laravel-activitylog` is installed, migrated, and **entirely unused** | — | — | no model uses `LogsActivity`; the `activity()` helper is never called on any path in this area; the `activity_log` table stays permanently empty | — | `config/activitylog.php`; migrations `2026_05_30_025626/7/8` | — | — | Y — dead weight and a naming trap one character away from the real table |

---

## 4. Calculations and formulas

Every formula below is transcribed from source, with the exact rounding and divisor the code uses.

### 4.1 Commission TDS (s.194H) — `RequestController.php:433–440`

```php
$rate = isset($fields['tds_rate']) && is_numeric($fields['tds_rate'])
        ? max(0.0, min(100.0, (float) $fields['tds_rate'])) : 5.0;
$fields['gross_amount'] = round($gross, 2);
$fields['tds_rate']     = $rate;
$fields['tds_amount']   = round($gross * $rate / 100, 2);
$amount                 = round($gross - $fields['tds_amount'], 2);
```

- **Divisor/basis:** percentage of gross. **Rounding:** `round(…, 2)` — PHP half-up on the .5 boundary.
- **Default rate: 5.0**, a literal. The same literal appears again in the bulk path (`:666`) and the edit path defaults to `(float) ($rec->tds_rate ?? 5)` (`:848`). A per-tenant `comm_tds_rate` exists in Statutory Rates (`AppController.php:11161`) and **is not read here**.
- **Worked example.** Gross ₹10,000, rate blank → 5.0%. `tds = round(10000 × 5 / 100, 2) = 500.00`. `amount (NET) = round(10000 − 500, 2) = 9500.00`. The `commissions.amount` column therefore holds the **NET**, and that is what payroll, Live Salary and the approver threshold consume.
- **Clamp:** a posted rate of `-5` becomes `0.0`; `150` becomes `100.0` (→ net `0.00`). A non-numeric rate falls back to 5.0.
- The recomputation only fires when `gross_amount` is numeric **and `> 0`**; if the client posts no gross, `$amount` stays whatever was posted in the top-level `amount` field and no TDS is recorded.

### 4.2 Amount threshold escalation — `ApprovalService.php:332`

```php
if (! ($threshold > 0 && $amount !== null && $amount >= $threshold)) { /* no escalation */ }
```

- **Thresholds (literals, `ApprovalService.php:30–41`):** expenses ₹10,000 · advances ₹10,000 · loans ₹50,000 · **every other module 0**.
- Because the guard requires `$threshold > 0`, only those three modules can ever escalate. Commissions, clawbacks, increments, exits, bonus and transfers never do, whatever the amount.
- The comparison is `>=`, so exactly ₹10,000 **does** escalate.
- **Worked example.** An expense of ₹10,000 by an employee whose `reporting_manager_id` → Suresh, whose own `reporting_manager_id` → Priya, produces `[Priya.id, "Priya (higher approval — amount ₹10,000)"]`. If Suresh has no manager, it produces `[null, "Branch / Company Admin (amount ₹10,000)"]` — **a NULL approver, which means no approver email is sent at all** (`RequestController.php:571` guards on `if ($approverId)`), and the request is decidable only by an `isManager()` user.
- `number_format($amount)` in the label uses **zero decimals**, so ₹10,000.49 renders as "₹10,000".
- **Bulk uploads pass `threshold = 0` explicitly** (`RequestController.php:687`), so a bulk-loaded commission never escalates even in principle.

### 4.3 Increment cross-computation — `RequestController.php:511–529`

```
old := fields.old_ctc if numeric and > 0, else employees.ctc
if (new <= 0 && old > 0 && pct != 0):  new := round(old * (1 + pct/100), 2)
if (new  > 0 && old > 0):              pct := round((new - old) / old * 100, 2)
if (new <= 0): 422 'Enter the New CTC (or a % increase).'
old := round(old, 2);  amount := new      # 'amount' is what the threshold sees
```

- **Order matters:** `pct` is *always* recomputed from the two CTCs when both are positive, so a posted `pct` is only honoured as an *input* when `new_ctc` is absent.
- **Worked example.** old = 360,000, pct = 15, new blank → `new = round(360000 × 1.15, 2) = 414,000.00`, then `pct = round((414000 − 360000)/360000 × 100, 2) = 15.00`. Written to the `new_ctc` column via `$cfg['amount']` (`:544–546`); threshold is 0 so no escalation.

### 4.4 Leave days — `LeaveController.php:329–338`

```php
$from = Carbon::parse($v['from']); $to = Carbon::parse($v['to']);
if ($to->lt($from)) { [$from, $to] = [$to, $from]; }   // reversed range silently swapped
$days = $from->diffInDays($to) + 1;
```

- **Basis: inclusive calendar days.** No weekly-off exclusion, no holiday exclusion, no half-day, no hourly unit. `$days` is always a whole number even though the column is `decimal(4,1)`.
- **Worked example.** Friday 2026-06-05 → Monday 2026-06-08: `diffInDays = 3`, `days = 4`. The employee is charged **4 days** of balance. Payroll, for the same leave, credits only the **working** days — it `continue`s over `isWeekOff()` and `$holidays[$date]` (`PayrollGenController.php:1073–1077`) — so if Sat+Sun are week-offs it credits **2**. Balance charged ≠ attendance credited.
- Dates are free-form strings parsed by Carbon; `'next friday'` or `'2026-13-45'` reach the parser, and a parse failure returns 422 `'Invalid dates'`.

### 4.5 Leave balance — `LeaveController::computeBalances():191–244`

```
year   = now()->year                                      # CALENDAR year
rows   = SELECT type_name, days, status FROM leaves
         WHERE employee_id = ? AND YEAR(from_date) = year   # NOT tenant-scoped
group by (type_name ?: 'Leave'):
    used    += days   when status = 'approved'
    pending += days   when status = 'pending'

per configured type t:
    paid        = (int)(t.paid ?? 1) === 1
    entitlement = (float)(t.days_per_year ?? 0)
    used        = taken[t.name].used        # matched on the NAME string
    pending     = taken[t.name].pending
    balance     = paid ? round(entitlement - used, 1) : null      # null = unlimited
```

**`carry_forward` never appears in this arithmetic**, despite the method's own docblock at `:186–189` promising "entitlement (days_per_year) + carry-forward opening − used − pending".

Enforcement on apply (`:343–359`) then uses a **different** expression:

```
if (bal && bal['paid'] && bal['balance'] !== null):
    if (entitlement <= 0):  422 'no yearly quota configured'
    remaining = bal['balance'] - bal['pending']       # = entitlement - used - pending
    if (days > remaining): 422 'Insufficient … Available: max(0, remaining) …'
```

- **Worked example.** Casual Leave, `days_per_year = 12`. Approved this year: 7. Pending: 2. Then `balance = round(12 − 7, 1) = 5.0`; `remaining = 5.0 − 2 = 3.0`. An application for 4 days is refused ("Available: 3 day(s), requested: 4"); for 3 days it is accepted.
- **ESS shows a different number for the same employee.** `EssController::leaveBalances():230` publishes `remaining = round(ent − used, 1) = 5.0` and never subtracts pending. The employee reads "5 remaining" in My Space and is refused a 5-day application. (NEW-LVE-101.)
- **Rounding:** `round(…, 1)` — one decimal, so half-day balances would survive if anything ever wrote one.
- **The `max(0, $remaining)` in the message** floors only the *displayed* number, not the comparison.

### 4.6 Commission payment balance and auto-lock — `RequestController.php:915–955`

```
net      = round((float) commissions.amount, 2)                  # the NET, post-TDS
paid     = SUM(commission_payments.amount WHERE commission_id = ?)
balance  = round(net - paid, 2)
amt      = round(posted amount, 2)
refuse if amt > balance + 0.005
newBalance = round(balance - amt, 2)
if (newBalance <= 0.005):  locked_at = now(); lock_source = 'fully paid'
```

- **The `0.005` tolerance is a literal, used twice** — once as an over-payment allowance and once as the lock trigger. Effectively "within half a paisa".
- **Worked example.** Net ₹9,500. Paid so far ₹9,000 → balance ₹500. A payment of ₹500.004 is accepted (`500.004 > 500 + 0.005` is false) → `newBalance = round(-0.004, 2) = -0.0` → `≤ 0.005` → **locked forever**, with no unlock endpoint.
- The same `+0.005` pattern governs salary payments (`:1026`) against `payslips.net`.

### 4.7 Edit-after-approval floor — `RequestController.php:847–861`

```
gross = posted gross_amount if numeric, else rec.gross_amount
rate  = posted tds_rate if numeric, else (rec.tds_rate ?? 5)
if (gross > 0 && (round(gross,2) !== round(rec.gross_amount,2) || round(rate,2) !== round(rec.tds_rate,2))):
    tds = round(gross * rate / 100, 2)
    net = round(gross - tds, 2)
    refuse if (paid > 0 && net < paid)   # 'Use a Clawback to recover excess.'
```

- Note the change detection uses `!==` on two `round()` **floats**. Float identity comparison after rounding is fragile; two values that differ only below the second decimal compare as identical and the edit is silently skipped rather than refused.

### 4.8 Bounce — paid amount and clawback target month — `RequestController.php:1739–1802`

```
paid = SUM(commission_payments.amount WHERE commission_id = ?)      # 0.0 if the table is absent
if (locked && paid <= 0):  paid = (float) commissions.amount        # "locked into a payslip = the net paid"
paid = round(paid, 2)

target = now()->format('Y-m')
if (payroll_runs has a row for cycle_label = target with status != 'draft'):
    target = now()->addMonthNoOverflow()->format('Y-m')

if (paid > 0): insert clawbacks { amount: paid, status: 'approved', cycle_month: target, ... }
```

- **Two defects live in this block.** `$fy = FinYearController::fyOf($target)` at `:1784` is computed and **never used**; `FinYearController::stamp('clawbacks', $tid)` at `:1785` ensures the column exists but its return value is discarded and `fin_year` is **not** in `$cbRow` — so the auto-clawback carries no financial year. And `'cycle_month' => $target` at `:1792` is **dropped by `safeRow()`** because the `clawbacks` table's column is `month`, not `cycle_month` (`…000300…:101`) — **the recovery month is lost**. `'company_id'` at `:1788` is dropped for the same reason (the table has no such column).
- **Worked example.** A ₹9,500 commission fully paid and locked; bounced on 2026-06-20; the June run is already `approved`. `paid = 9500.00`; `target = '2026-07'`; a clawback for ₹9,500 is created **approved**, with `month = NULL` and `fin_year = NULL`. Payroll's `clawbacksDue` must then find it without a month.

### 4.9 Audit hash — `Audit.php:49–61, 91–93`

```
payload = implode('|', [prev, tenantId ?? '', userId ?? '', action, entity, entityId, detail ?? '', when])
prev    = (SELECT row_hash FROM activity_logs ORDER BY id DESC LIMIT 1) ?: 'GENESIS'
row_hash = hash('sha256', payload)
```

- `when` is `now()->toDateTimeString()` — second resolution, so two rows in the same second are still distinguished by `prev`.
- `detail` is `json_encode($detail)` for arrays, the raw string otherwise, **with no length cap**.
- Verification (`:106–132`) recomputes from `'GENESIS'` using `hash_equals`. Rows with a null/empty `row_hash` are skipped **and reset `$prev` to `'GENESIS'`** (`:118–121`), which is what makes the four direct inserts of NEW-AUD-104 chain-breaking rather than merely unaudited.

### 4.10 Greeting anniversary years — `GreetingService.php:213–215`

```
years = day.year - Carbon::parse(doj).year
fire if years >= (int)(cfg.anniversary.min_years ?? 1)
```

- Whole-year subtraction on the *year component only*; correct here because the sweep already matched month-and-day. `min_years` default **1** suppresses the same-day-as-joining "0 years" greeting.

### 4.11 WhatsApp phone normalisation — `WaService.php:116–117, 143–148`

```
digits = preg_replace('/\D+/', '', mobile)
phone  = substr(digits, -10)                    # LAST TEN DIGITS
refuse if strlen(phone) < 10
POST { countryCode: '+91', phoneNumber: phone, ... }     # '+91' is a literal
```

- **Worked example.** `+44 20 7946 0958` → digits `442079460958` → last 10 = `2079460958` → sent as `+91 2079460958`, i.e. to a wrong or non-existent Indian number. The integration is India-only by construction and fails **silently** (returns `false`, logs `wa_log`, never throws).

### 4.12 Template variable count — `WaTemplateController.php:276–281`

```
preg_match_all('/\{\{(\d+)\}\}/', body, $m);  nums = int[] of $m[1]
varCount = nums ? max(nums) : 0
reject if (nums && count(array_unique(nums)) !== varCount)   # gaps not allowed
```

- **Worked example.** `"Hi {{1}}, your {{3}} is ready"` → `nums = [1,3]`, `varCount = 3`, `count(unique) = 2 ≠ 3` → rejected with "Variables must be numbered {{1}}, {{2}}, … without gaps. Found: 1,3".
- `testSend()` then pads/truncates `sample_values` to exactly `var_count`, filling missing entries with `'-'` (`:399–400`).

### 4.13 Error digest window — `ErrorDigest.php:39–79, 101–116`

```
window   = min(filesize, 2 MB) read from the END of storage/logs/laravel.log
lines    = last 4000 of that window
hit if line contains '[<Y-m-d>' for any of the last --days days AND contains '.<LEVEL>:' or '<LEVEL>:'
report   = count(hits) + the LAST 10 hits, each mb_substr(…, 0, 240)
```

- Every number here (2 MB, 4000, 10, 240) is a literal. The level set is `['ERROR','CRITICAL','ALERT','EMERGENCY']`.

---

## 5. Configuration surface today

### 5.1 (a) What a customer CAN change without code today

| # | What | How | Where in source |
|---|---|---|---|
| C-01 | **Leave types**: name, days per year, paid/unpaid, carry-forward flag | Masters → Leave Types CRUD (admin / hr_manager) | `MasterController.php:70–76`, `:582`; UI `AppController.php:10768` |
| C-02 | **Company holiday calendar**: date, name, applies-to | Masters → Holidays | `MasterController.php:60–67` |
| C-03 | **Notice board** entries (surfaced in ESS) | Masters → Notice Board | `MasterController.php:421–425` |
| C-04 | **Per-company SMTP** (host, port, username, password, encryption, from address/name) and a platform fallback mailbox | Settings → Email; super admin → Platform Email | `MailService::deliver():138–164` via `ConfigController::mailConfigFor()` |
| C-05 | **Email branding** (logo, header colour, tagline) | Settings → Branding | `ConfigController::brandFor()` called at `MailService.php:168` |
| C-06 | **SMS provider**: provider name, api_url, api_key, sender_id, DLT entity/principal ids, active status | Masters → SMS Settings | `sms_settings`; `SendMessageController::activeProvider():206–220` |
| C-07 | **WhatsApp provider** (Interakt): api_url, api_key, sender number, WABA id, active status | Masters → WhatsApp API | `wa_settings`; `WaService::config():43–66` |
| C-08 | **WhatsApp template library**: body text, name, language, category, purpose, sample values, status; CSV export | WhatsApp Templates screen (super admin = platform rows; admin/hr/hr_manager = own tenant) | `WaTemplateController.php:260–433` |
| C-09 | **Greetings**: master on/off, send hour, skip-inactive, per-occasion enable/email/in-app, subject and message text with placeholders, anniversary `min_years` | Settings → Greetings (write guarded by `FeaturePermissions::guard(…,'greetings')`) | `GreetingService::defaults():32–60`; `GreetingController::saveConfig():44–64` |
| C-10 | **Late-arrival email**: on/off (`late_email_enabled`, default 0) and its subject/message text | Statutory Rates (the toggle) + Settings → Greetings (the text) | `SettingsController.php:60`; `GreetingService::defaults():54–58` |
| C-11 | **DRA gate at commission approval**: `off` / `warn` / `block` (default `warn`) | Settings → Statutory Rates | `SettingsController.php:57`; consumed `RequestController.php:1373–1378` |
| C-12 | **Minimum compliance score for an incentive** (`incentive_min_compliance`, default 60) | Settings → Statutory Rates | `SettingsController.php:56`; consumed `RequestController.php:1421` |
| C-13 | **Company payslip self-download policy** (affects the ESS payslip PDF link) | Settings (rev172) | `SettingsController::payslipSelfAllowed()`; consumed `EssController.php:111` |
| C-14 | **Active financial year** (the FY stamped on new requests, and the `?fy=` filter) | Settings → `fy_active` | `FinYearController::stamp()/active()/options()`; `RequestController.php:547`, `:284` |
| C-15 | **Who may write the F1–F10 feature screens** (including Greetings) | The `FeaturePermissions::MAP` **write** role list — but this is a PHP constant, so *in practice* only via code. Listed here only because the role→user assignment itself is data | `FeaturePermissions.php:38–86` |
| C-16 | **Role membership** (which users hold `admin`, `hr_manager`, `accountant`, `employee`, …) | User/role admin (Spatie roles) | `hasAnyRole()` call sites throughout |
| C-17 | **Mobile device approval** (approve / reject / revoke a registered handset) | Administration → Mobile Devices | `MobileDeviceController.php:218–259`. **Note: approving or revoking has no server-side effect on access** (SEC-09) |
| C-18 | **Broadcast content and audience** per send (subject, body, one of eight target filters, channel selection) | Send Message screen | `SendMessageController::send():19–149` |
| C-19 | **Sandwich rule** on/off (payroll-side, default 0) — affects how leave gaps are treated in payroll, not in the leave module | Settings → Statutory Rates | `AppController.php:12179` |
| C-20 | **LOP day basis** (`working` / `calendar` / fixed 30) — payroll-side | Settings → Statutory Rates | `AppController.php:12170` |
| C-21 | **Ops error-digest recipient** | `.env` `SMARTPRS_OPS_EMAIL` (not a UI setting) | `ErrorDigest.php:25` |
| C-22 | **Interakt credentials and template names** as an `.env` fallback | `.env` `INTERAKT_API_KEY`, `INTERAKT_API_URL`, `INTERAKT_TEMPLATE_<PURPOSE>` | `WaService.php:61–62`, `:76–82` |

**That is the complete list.** Everything else in this area is code.

### 5.2 (b) What a customer CANNOT change without editing PHP — the SmartPRS 3 backlog

**Request types and the engine**

| # | Cannot change today | Evidence |
|---|---|---|
| B-01 | **Which request types exist.** Adding "Gate Pass", "Work From Home", "Overtime Authorisation", "Asset Request", "Travel Authorisation", "Attendance Regularisation as a request" means editing a static PHP array and deploying | `ApprovalService::modules():27–43` |
| B-02 | **Disabling or hiding a request type per tenant.** Every tenant sees all nine | same — no tenant/enablement column anywhere |
| B-03 | **Renaming a type's label** (`Expense Claim`, `Loan`, …) | `ApprovalService.php:30–41` |
| B-04 | **Which fields a request type captures**, their labels, order, options, requiredness, types, validation, min/max | SPA `RQ_MAP` in `AppController.php:3902–3947` + server `fields` accepted as a **wholly unvalidated array** (`RequestController.php:386`) then filtered by `safeRow()` |
| B-05 | **Per-field validation of anything inside `fields[]`** for the seven non-commission, non-increment modules — there is none at all: no type check, no length check, no required check | `RequestController.php:383–387`, `:534`, `:551` |
| B-06 | **The escalation threshold amounts** (₹10,000 / ₹10,000 / ₹50,000) | `ApprovalService.php:30–32` |
| B-07 | **Which types escalate at all** (only the three with a non-zero threshold can) | `ApprovalService.php:332` |
| B-08 | **The approval hierarchy itself** — see §7.2. One hop, optionally two. No multi-step, no parallel, no any-one-of, no all-must, no quorum, no conditional routing, no per-type chain, no per-amount-band chain, no role-based approver, no cost-centre approver | `ApprovalService::resolveApprover():310–352` |
| B-09 | **Delegation / out-of-office / alternate approver.** No such concept, table or column exists | absence |
| B-10 | **Reassignment of a pending request to a different approver** | absence — `approver_id` is set once at creation and never rewritten |
| B-11 | **Re-evaluation of the approver when the amount is edited.** Escalation is computed once, at creation | `RequestController.php:531` only |
| B-12 | **SLA / due dates / reminders / timeout escalation / auto-approve / auto-reject on expiry** | no column, no schedule (`routes/console.php:140–262`) |
| B-13 | **Cancel / withdraw by the requester** | `decide()` accepts only `approve\|reject` (+`reopen` for commissions); `leaves.status` is an ENUM of three |
| B-14 | **Making decisions immutable** (a pending-state guard) | absence in `decide():1330–1525` |
| B-15 | **Which roles may decide** (`super_admin, admin, hr_manager` is a literal in two controllers) | `RequestController.php:52–55`; `LeaveController.php:84–87` |
| B-16 | **Which roles count as "Accounts"** (`super_admin, admin, accountant`) — and note `admin`/`super_admin` satisfy **both**, so segregation of duties is not enforceable against an admin | `RequestController.php:58–61` |
| B-17 | **Whether a two-stage (Accounts-then-Manager) gate applies, and to which types.** It exists only for commissions and cannot be switched off or reused | `RequestController.php:1359–1364` |
| B-18 | **Whether low compliance / open grievance blocks or merely warns.** Hard-coded warn-and-allow | `RequestController.php:1416–1454` |
| B-19 | **What happens on approval** — the transfer-order and increment-letter side effects are two hard-coded `if ($module === '…')` blocks | `RequestController.php:1465–1492` |
| B-20 | **Whether edit-after-approval is permitted** | `RequestController.php:763–877`, commissions only, always on |
| B-21 | **Unlocking a locked entry** — no endpoint exists anywhere | absence |
| B-22 | **Bulk-upload row cap (1,000), error-report cap (30), and the no-email-on-bulk policy** | `RequestController.php:626`, `:750`, `:607` |
| B-23 | **Proof upload rules** — allowed mime types, 5 MB cap, storage disk, the `commission-proofs/` prefix convention | `RequestController.php:1596–1613`, `:501–503` |
| B-24 | **The payment voucher layout** — an entire HTML letterhead built by PHP string concatenation, including the `CPV-` prefix and the signature block wording | `RequestController.php:1891–1922` |
| B-25 | **The financial-year boundary (April–March)** and the `"YYYY-YY"` label format | `FinYearController` |
| B-26 | **Request list pagination / limits / sorting** — none exist; `orderByDesc('r.id')->get()` over the whole tenant | `RequestController.php:177–178`; inbox `:1573` |

**Leave**

| # | Cannot change today | Evidence |
|---|---|---|
| B-27 | **Whether leave days exclude weekly offs and public holidays.** They never do | `LeaveController.php:338` |
| B-28 | **Half-day leave.** No field, no UI, no writer — the `decimal(4,1)` column could hold 0.5 and nothing ever writes one | `…000200…:92`; `LeaveController.php:370` |
| B-29 | **Hourly / short leave.** No concept | absence |
| B-30 | **Accrual** (monthly/quarterly/on-completion-of-service) | absence — `computeBalances()` has no date arithmetic beyond `whereYear` |
| B-31 | **Carry-forward**, its cap, and its expiry | flag exists, arithmetic ignores it (`LeaveController.php:239`) |
| B-32 | **Lapse at year end** | absence |
| B-33 | **Encashment** of a leave balance | absence — "Leave Encashment" is only a bonus-module label |
| B-34 | **Pro-ration on joining or exit** | absence |
| B-35 | **Negative balance / advance leave** with a permitted overdraft | `LeaveController.php:354` refuses hard; no overdraft concept |
| B-36 | **Blackout periods** (no leave during month-end, audit week, peak season) | absence — grep for `blackout` returns nothing |
| B-37 | **Clubbing / prefix-suffix rules** (leave adjacent to a holiday counts as leave) | absence — grep for `prefix`/`suffix` in this sense returns nothing |
| B-38 | **Sandwich rule for leave.** A `sandwich_rule` exists **in payroll** (default off) and operates on *attendance*, not on leave-balance deduction | `AppController.php:12179`; `PayrollGenController::sandwichDays()` |
| B-39 | **Compensatory-off earning** — how a comp-off is credited from weekend/holiday work. Only a *type named* "Comp-Off" exists | `LeaveController.php:204`; no earning mechanism anywhere |
| B-40 | **Minimum-staffing / team-availability checks** before approving leave | absence — grep for `staffing`/`min_staff` returns nothing |
| B-41 | **Overlap detection** — two reportees booking the same week produces no warning | absence |
| B-42 | **A team leave calendar** | absence — the only calendar is the `holidays` master |
| B-43 | **Attachments** (medical certificates for sick leave over N days) | absence |
| B-44 | **A leave-year basis other than the calendar year** | `LeaveController.php:193` |
| B-45 | **Eligibility rules per type** (probationers may not take earned leave; gender-linked types; grade-linked entitlements) | absence — one flat `days_per_year` per type per tenant |
| B-46 | **Notice-period rules** (apply N days in advance; max consecutive days; max applications per month) | absence |
| B-47 | **A leave approver different from the reporting manager** (e.g. an HR approver for maternity leave) | `LeaveController::resolveApprover():96–117` |
| B-48 | **Notification to the approver when leave is submitted** | absence at `LeaveController.php:381–385` |
| B-49 | **The three hard-coded leave-type fallback lists** | `LeaveController.php:176`, `:200–206`; `EssController.php:195–200` |

**ESS**

| # | Cannot change today | Evidence |
|---|---|---|
| B-50 | **Which fields an employee may self-edit** | `EssController.php:266–277` |
| B-51 | **Whether a self-edit needs HR approval** before it takes effect | absence — writes are immediate |
| B-52 | **Whether an email or mobile change is verified** | absence |
| B-53 | **What My Space shows and how much of it** (6 payslips / 6 leaves / 5 notices / current month only) | `EssController.php:114`, `:150`, `:175`, `:131` |
| B-54 | **Auditing of self-edits** | absence |

**Notifications, WhatsApp, greetings**

| # | Cannot change today | Evidence |
|---|---|---|
| B-55 | **Which events produce a notification.** Every trigger is a hard-coded call site (see §7.4 for the full enumeration) | grep of `'kind' =>` across `app/` |
| B-56 | **The audience of any event** (approver / employee / manager / HR) | hard-coded per call site |
| B-57 | **Which channels an event uses.** `in_app`/`email` booleans are literals at each call site; **WhatsApp is never selected by any HR workflow** | `NotificationService.php:87–88`; NEW-NOT-114 |
| B-58 | **The wording of any workflow email** — subject, heading, intro, and the `lines` label set are PHP literals per call site | `RequestController.php:574–589`, `:1500–1514`; `LeaveController.php:436–456` |
| B-59 | **Per-user notification preferences / opt-out / unsubscribe** | absence |
| B-60 | **Escalating a notification that was not read** | absence |
| B-61 | **The country code for WhatsApp (`'+91'`) and the last-10-digit truncation** | `WaService.php:117`, `:148` |
| B-62 | **Retry/queue behaviour for WhatsApp** (there is none) and for email (`tries=3`, `backoff=30`, queue name `'mail'`) | `WaService.php:146–163`; `SendNotification.php:33–41` |
| B-63 | **The greeting placeholder set** (5 variables + 4 late-only) | `GreetingService::vars():237–249` |
| B-64 | **Greeting channels** — WhatsApp/SMS/push greetings are impossible | `GreetingService` docblock `:17–18`; no `WaService` call in the greetings path |
| B-65 | **Per-tenant timezone for the greeting send hour** | `SendGreetings.php:40` |
| B-66 | **Other occasions** (probation completion date, wedding anniversary, festival wishes, employee-of-the-month) | `GreetingService::greet():180–234` handles exactly two |
| B-67 | **Broadcast audience definitions** — eight fixed filters, no saved segments, no compound conditions | `SendMessageController::resolveRecipients():158–203` |
| B-68 | **Per-recipient delivery tracking for SMS/WhatsApp broadcasts** | `messages_log` holds one aggregate row |
| B-69 | **A push notification of any kind** | no sender exists |

**Audit**

| # | Cannot change today | Evidence |
|---|---|---|
| B-70 | **Which actions are audited** | hard-coded `Audit::record` call sites |
| B-71 | **Capturing old→new values** on any transaction in this area | `AuditTrail::change()` exists and is never called here |
| B-72 | **Log retention** (nothing prunes anything, contradicting the published 90-day commitment) | absence; `PolicyController.php:12` |
| B-73 | **Per-tenant chain verification** | the chain is global (`Audit.php:91`) |
| B-74 | **Read auditing** (who viewed whose payslip, ledger, leave or balances) | absence |

---

## 6. Defects, conflicts and technical debt

Severity: **CRITICAL** (money or compliance wrong, or data lost silently) · **HIGH** · **MEDIUM** · **LOW**.

### 6.1 Defects and conflicts

| ID | What it is | Evidence | Business impact | Severity |
|---|---|---|---|---|
| **DEF-LVE-02** | **Loss-of-Pay leave is paid.** Payroll decides paid-vs-unpaid from `leaves.type_id`; `LeaveController::apply()` writes `type_name` and never `type_id`. So `type_id` is always NULL, `paid` is always `true`, and the `continue` that skips unpaid leave is unreachable. `paidLeaveDates()` has the same bug in a different shape: `(int)($lv->type_id ?? 0)` yields `0`, which is never a key in `$paidMap`, so its `array_key_exists` default `true` fires. Verified: grep for `type_id` across `app/` finds only two **reads** and zero writes | insert `LeaveController.php:364–378`; consumers `PayrollGenController.php:1063–1067` and `:743–746` | Every approved leave — **including leave explicitly filed as "Loss of Pay"** — is credited as a paid present day. LOP deductions never happen through the leave route. Direct salary overpayment | **CRITICAL** |
| **DEF-REQ-01** | **`safeRow()` silently discards data.** `array_intersect_key` against the live column list, with no exception, no log, no return value and no user signal. Nine confirmed losses on the mainline path | `ApprovalService::safeRow():262–266`; SPA field map `AppController.php:3902–3947`; table schemas `…000300…:96–165` | `expenses.reason` (the note) lost · `advances.reason` lost · `loans.tenure_months` lost (column is `tenure`) · `loans.reason` lost · `clawbacks.cycle_month` lost (column is `month`) · `bonus.kind` lost (column is `type`) · `entered_by` lost on every non-commission module · `company_id` lost on expenses/advances/loans/clawbacks/bonus · the bounce clawback's `cycle_month` and `company_id` lost. **The user is told the request succeeded.** An expense claim's reason — the whole point of the claim — is never stored | **CRITICAL** |
| **DEF-REQ-02** | **No pending-state guard on decide.** Any manager can re-approve or re-reject an already-decided request, any number of times, for as long as it is not locked | absence in `RequestController::decide():1330–1525` and `LeaveController::decide():392–466` | Re-fires `TransferService::applyIfDue` + the transfer-order email (`:1470–1481`), re-emails the increment letter (`:1486–1492`), re-emails the requester (`:1495–1519`), writes a fresh `activity_logs` row, and **overwrites prior remarks with blank** (`:1408`). An approved increment letter can be sent to an employee repeatedly; an approved transfer can be re-applied | **HIGH** |
| **DEF-REQ-03** | **A request with no resolvable approver notifies nobody.** When `resolveApprover` returns `[null, '<label>']` — no reporting manager, or escalation past the top of the chain — the email block is skipped by `if ($approverId)` | `RequestController.php:571`; resolver `ApprovalService.php:337`, `:351` | The request sits Pending forever, silently. The non-manager inbox filter `approver_id = $myId ?? -1` can never match a NULL. It surfaces only if a manager happens to open the inbox | **HIGH** |
| **DEF-REQ-04** | **The bounce-generated clawback loses its recovery month and its financial year.** `$fy = FinYearController::fyOf($target)` at `:1784` is assigned and never used; `stamp()`'s return at `:1785` is discarded; `fin_year` is absent from `$cbRow`; `'cycle_month'` at `:1792` is dropped by `safeRow` because the column is `month` | `RequestController.php:1784–1802` vs `…000300…:96–106` | The payroll engine must recover an approved clawback with no target month and no FY. Recovery may land in the wrong cycle or not at all | **HIGH** |
| **DEF-REQ-05** | **`commission_payments` is created from the `increments` code branch.** The `if ($table === 'increments' …)` block at `:137–183` contains, nested inside it, the `CREATE TABLE commission_payments` | `ApprovalService.php:165–182` | A deployment that never opens the Approvals Inbox (which iterates every module and so touches `increments`) and only ever posts commissions hits "table commission_payments does not exist" in `payCommission`. Every read is `Schema::hasTable`-guarded, so the failure mode is **silent zeroes in the ledger** rather than a crash — worse | **HIGH** |
| **DEF-LVE-01** | **`LeaveApplication` is dead code and its migration filename lies.** The model declares a `leave_applications` table that no migration creates and no code references. The file named `…_create_leave_applications_table.php` actually creates `pf_esi_settings`, `tds_rules`, `pt_slabs`, `tds_returns` | `app/Models/LeaveApplication.php`; `database/migrations/2026_01_01_000400_create_leave_applications_table.php` | Any developer searching for leave persistence finds a model and a migration filename that both point away from the real implementation (raw query builder on `leaves`) | **MEDIUM** |
| **DEF-LVE-03** | **Carry-forward is configured, visible, and inert.** The docblock promises it; the UI collects it; the arithmetic ignores it | `LeaveController.php:186–189` (comment) vs `:239` (code); UI `AppController.php:10768` | HR sets "Carry Forward: Yes" on Earned Leave and believes unused days roll over. They do not — they vanish at the year boundary | **HIGH** |
| **DEF-LVE-04** | **ESS and the leave module report different balances.** ESS `remaining = ent − used`; the leave module enforces `ent − used − pending` | `EssController.php:230` vs `LeaveController.php:239`+`:353` | An employee reads "5 days remaining" in My Space and is refused a 5-day application with "Available: 3". A support ticket generator | **MEDIUM** |
| **DEF-LVE-05** | **Three divergent hard-coded leave-type default lists** for one concept — 5 names (apply dropdown), 5 objects with quotas (balances), 4 objects without Comp-Off (ESS) | `LeaveController.php:176`, `:200–206`; `EssController.php:195–200` | A tenant with no configured leave types sees Comp-Off in the apply dropdown, sees it in the leave-module balances, and does **not** see it in My Space | **MEDIUM** |
| **DEF-LVE-06** | **Balance charged ≠ attendance credited.** Leave charges inclusive calendar days; payroll credits only working days | `LeaveController.php:338` vs `PayrollGenController.php:1073–1077` | A Fri–Mon leave costs 4 days of balance and credits 2 days of attendance. Employees exhaust quota twice as fast as their pay reflects | **HIGH** |
| **DEF-LVE-07** | **Renaming a leave type orphans all history.** Usage is matched on the `type_name` string | `LeaveController.php:217`, `:231` | Renaming "Casual Leave" to "Casual" resets every employee's consumption of it to zero, mid-year | **HIGH** |
| **DEF-LVE-08** | **The leave year is the calendar year; the rest of the product uses Apr–Mar.** A leave spanning 31 Dec → 2 Jan is attributed wholly to the from-year | `LeaveController.php:193`, `:214` | Balances reset on 1 January while payroll, requests and statutory reporting roll on 1 April | **MEDIUM** |
| **DEF-ESS-01** | **ESS leave rows always display the literal `'Leave'`.** The chain `$types[type_id] ?? $a['type'] ?? 'Leave'` cannot resolve: `type_id` is always NULL and the `leaves` table has no `type` column. `type_name` — the real value — is never read | `EssController.php:155` | My Space shows six rows all labelled "Leave"; the employee cannot tell sick from casual from LOP | **MEDIUM** |
| **DEF-ESS-02** | **ESS self-edits are entirely unaudited** | absence in `EssController::updateProfile():259–294` | An employee silently changing their payslip delivery email, their DOB or their contact details leaves no trace anywhere | **HIGH** (see also SEC-08) |
| **DEF-NOT-01** | **Leave submission notifies nobody.** Requests email the approver; leave does not | `LeaveController.php:381–385` vs `RequestController.php:570–594` | Managers do not know a leave request exists unless they open the inbox. In practice leave sits unapproved | **HIGH** |
| **DEF-NOT-02** | **A reopened commission emails the employee "your commission was pending".** The decision email uses `$decided = $update['status']`, which is `'pending'` for a reopen | `RequestController.php:1499`, `:1509–1513` | Subject "Commission pending", heading "Your commission was pending". Confusing but harmless | **LOW** |
| **DEF-NOT-03** | **The late-arrival email bypasses the entire mail engine.** `Mail::raw` on the global mailer | `LateArrivalService.php:289` | Uses `config/mail.php` (default `MAIL_MAILER=log`, i.e. **nothing is sent**) rather than the tenant's SMTP; writes **no `mail_log` row**, so there is no delivery record; no branding, no queue, no retry | **HIGH** |
| **DEF-NOT-04** | **The 17-template tenant WhatsApp library has no consumers.** Bodies are seeded for `leave_decision`, `approval_pending`, `request_decision`, `commission_approved`, `transfer_order`, `payslip_ready` and eleven more; not one is wired to a sender | `WaTemplateController::tenantLibrary():130–185`; `WaService::sendTemplate` callers | An admin configures, tests and marks-approved a full WhatsApp library, and no HR event ever sends one. The product appears to have WhatsApp notifications; it does not | **HIGH** |
| **DEF-NOT-05** | **`send_hour` is the server's hour, not the tenant's.** The docblock explicitly claims otherwise | `SendGreetings.php:40` vs docblock `:11–13`; `routes/console.php:245–250` | Tenants in other timezones cannot fire at their own local time | **MEDIUM** |
| **DEF-NOT-06** | **`hide_birth_year` is dead configuration** — declared, surfaced, never read | `GreetingService.php:36` | HR toggles a privacy setting that does nothing | **LOW** |
| **DEF-NOT-07** | **The disciplinary late-arrival template lives inside the Greetings config**, and is *sent* under a different setting entirely | `GreetingService::defaults():50–58` | HR editing "birthday wishes" is editing a disciplinary warning letter in the same screen | **LOW** |
| **DEF-NOT-08** | **29-February birthdays are never greeted in a common year** | `GreetingService.php:134`, `:172–173` | One employee in ~1,460 is never greeted | **LOW** |
| **DEF-NOT-09** | **Broadcast SMS/WhatsApp is synchronous, per recipient, in the request.** One 15-second-timeout HTTP call per person | `SendMessageController.php:72–95` | A 500-employee WhatsApp broadcast can occupy a PHP worker for up to ~2 hours; the browser times out long before | **HIGH** |
| **DEF-NOT-10** | **No per-recipient SMS/WhatsApp delivery record.** One aggregate `messages_log` row per broadcast | `SendMessageController.php:109–125` | A failed SMS to a named employee cannot be found, retried, or proven for a compliance audit | **MEDIUM** |
| **DEF-NOT-11** | **`->onQueue('mail')` with nothing enforcing a matching worker.** A worker started as plain `php artisan queue:work` drains only `default` | `SendNotification.php:41`; `config/queue.php` | Every notification email sits in the queue table unsent, with `mail_log.status = 'queued'` forever. This is exactly the silent failure `sync` was introduced (rev170) to work around for credential mail | **HIGH** |
| **DEF-AUD-01** | **Four code paths break the tamper-evident chain.** Direct `DB::table('activity_logs')->insert()` with no `prev_hash`/`row_hash`; `verify()` then resets `$prev` to `'GENESIS'` at that row | inserts `RequestController.php:1382–1387`, `:1424–1429`, `:1444–1449`, `:1822–1827`; skip logic `Audit::verify():118–121` | Every commission approval carrying a DRA/compliance/grievance warning, and every bounce, **silently resets the chain**, destroying the tamper-evidence of everything before it. The one feature that makes the ledger trustworthy is defeated by the exact events most worth trusting | **CRITICAL** |
| **DEF-AUD-02** | **The hash chain has a read-then-insert race with no transaction or lock** | `Audit.php:91–96`; grep confirms no `DB::transaction`/`lockForUpdate` in `Audit.php`, `RequestController.php` or `LeaveController.php` | Two concurrent audited actions read the same `$prev` and both chain onto it; `verify()` reports the second as broken. **False tamper alarms under ordinary concurrency** | **HIGH** |
| **DEF-AUD-03** | **Spatie `activity_log` (singular) is created, migrated and permanently empty**, one character away from the real `activity_logs` | `config/activitylog.php`; migrations `2026_05_30_025626/7/8`; zero writers | An auditor or a new developer queries the wrong table and concludes nothing is audited | **MEDIUM** |
| **DEF-AUD-04** | **Actors are recorded as display-name strings, not user ids**, in `decided_by`, `entered_by`, `by`, `accounts_by`, `locked_by`, `bounced_by` | `RequestController.php:869`, `:929`, `:1036`, `:1285`, `:1406`, `:1656`, `:1736`; `LeaveController.php:420` | A renamed or deleted user makes the trail ambiguous; two employees named "Ravi Kumar" are indistinguishable in the record of who approved a payment | **HIGH** |
| **DEF-AUD-05** | **Money-moving commission actions never reach the hash-chained ledger.** Edits after approval, payments, manual locks, Accounts confirm/flag, and orphan deletions write only to `commission_logs` (or nowhere) | `updateCommission():870`, `payCommission():943`, `lockCommission():1289`, `accountsDecide():1663`, `cleanOrphanCommissions():1254–1259` | The actions with the highest fraud potential are outside the tamper-evident ledger. `commission_logs` has no hash chain, no `updated_at`, and no application-level guard against `UPDATE`/`DELETE` — "immutable" is a convention | **CRITICAL** |
| **DEF-REQ-06** | **Two competing "last day" columns on `exits`.** The migration creates `last_day`; the runtime self-heal adds `last_working_day`; the UI posts only the latter | `…000500…:85` vs `ApprovalService.php:191`, `AppController.php:3946` | Any report or FnF calculation reading `last_day` sees NULL on every exit raised through the UI | **MEDIUM** |
| **DEF-REQ-07** | **A user with no `tenant_id` is attached to the first tenant in the table** | `RequestController.php:381`, `:630`; leave has the same at `LeaveController.php:272` | On a multi-tenant install, a request or leave application is written into an arbitrary tenant's data | **HIGH** |
| **DEF-REQ-08** | **A blank re-decide erases prior remarks** — `remarks` is written unconditionally | `RequestController.php:1408`; `LeaveController.php:422` | The recorded reason for a rejection can be wiped by a subsequent approval with no remarks | **MEDIUM** |
| **DEF-REQ-09** | **Unreachable 403 in the commission ledger.** For a non-manager, `$emp` is forced to `$me` at `:1082`, so the ownership test at `:1087` can never fail | `RequestController.php:1074–1089` | Behaviour is safe (they get their own ledger) but the code is dead and the reference doc describes it incorrectly | **LOW** |
| **DEF-REQ-10** | **Float identity comparison drives the commission edit path** — `round($gross,2) !== round($rec->gross_amount,2)` | `RequestController.php:849` | A genuine sub-paisa change is silently ignored rather than applied or refused | **LOW** |
| **DEF-LVE-09** | **`leaves.tenant_id`/`company_id` are NOT NULL but are copied from the employee** | `…000200…:87–88` vs `LeaveController.php:365–366` | An employee with no company can never apply for leave; the failure surfaces as a raw SQL error in a 422 body | **MEDIUM** |
| **DEF-NOT-12** | **`notifications_feed` is a migrated, entirely unused table** superseded by `notification_log` | `…000600…:199+`; zero references | Dead schema; a second wrong place to look | **LOW** |

### 6.2 Technical debt

| ID | What it is | Evidence | Impact | Severity |
|---|---|---|---|---|
| **TD-01** | **Runtime DDL on the user request path.** `ensureAuditCols()` runs on every `list`/`apply`/`decide`/`inbox` and most commission endpoints; `inbox()` calls it **once per module — nine tables per inbox load**. On MySQL, DDL is implicitly committing and non-transactional; a concurrent request failing halfway leaves partial columns | `ApprovalService.php:46–260`; call sites `:133`, `:378`, `:624`, `:766`, `:891`, `:1244`, `:1275`, `:1338`, `:1567`, `:1724`; plus `ensureCollectionCols():68–102`, `ensureBounceCols():1680–1701`, `ensureSalaryPayments():973–993`, `LeaveController::ensureCols():34–57`, `EssController::ensureProfileCols():239–252`, `NotificationService::ensureTable():50–70`, `MailService::ensureTable():49–66`, `WaService::ensureLog():25–39`, `WaTemplateController::ensure():72–92`, `MasterController::ensureCols():501–507`, `ConfigController::ensureTable():30–37` | Unpredictable schema, no migration history, DDL executed by a web request, silent partial application (every branch is `try/catch(\Throwable){}`). A migration (`2026_06_24_000000`) exists to make this unnecessary and the runtime path still runs unconditionally | **HIGH** |
| **TD-02** | **`Schema::getColumnListing()` on every insert and update** — a live `INFORMATION_SCHEMA`/`DESCRIBE` query, uncached | `ApprovalService::safeRow():265`; also inline at `LeaveController.php:379`, `:425` | Two extra metadata round-trips per leave decision; more per request | **MEDIUM** |
| **TD-03** | **The Approvals Inbox is unbounded.** ~10 `SELECT *` queries with a `leftJoin`, no `LIMIT`, no pagination, plus a DDL check per module, on every open | `RequestController::inbox():1532–1591` | For a manager on a large tenant this is the slowest screen in the product and gets slower forever | **HIGH** |
| **TD-04** | **No indexes on the columns the engine actually filters by.** `status` and `approver_id` are unindexed on every request table (`expenses.approver_id` is declared but not indexed at `…000300…:139`); `leaves.approver_id` and `leaves.status` are unindexed | `…000300…:96–165`; `…000200…:85–98` | Every inbox load and every list is a full scan filtered in the engine | **MEDIUM** |
| **TD-05** | **Three copies of `currentEmployee()`, two of `resolveApprover()`** | `RequestController.php:31–50`, `LeaveController.php:63–81`, `EssController.php:43–59`; `ApprovalService.php:310–352` vs `LeaveController.php:96–117` | The single most reused piece of business logic in the product exists twice and diverges | **HIGH** |
| **TD-06** | **`RequestController` is a god class** — 1,929 lines, 20 public actions, spanning nine request types plus a commission sub-system, a disbursement ledger, a salary-payment ledger, an HTML voucher generator and an orphan purge | `RequestController.php` | Unmaintainable; every commission change risks all nine types | **HIGH** |
| **TD-07** | **`ApprovalService` is a service in the `App\Http\Controllers` namespace**, so callers use it with no `use` statement — a convention the class itself documents at `:360–363` | `ApprovalService.php:3` | Confuses autoloading, static analysis and every new developer | **MEDIUM** |
| **TD-08** | **Exceptions are swallowed everywhere.** `try/catch(\Throwable){}` with an empty body or a bare `Log::warning` at, among others, `RequestController.php:189`, `:202`, `:444`, `:451`, `:592`, `:799`, `:927`, `:1034`, `:1391`, `:1431`, `:1451`, `:1473`, `:1478`, `:1489`, `:1517`, `:1666`, `:1745`, `:1782`, `:1818`, `:1829`; `ApprovalService.php:97`, `:132`, `:160`, `:181`, `:216`, `:232`, `:296`; `Audit.php:41`, `:97`; every service | Failures are invisible. The DRA gate silently disables itself on a compliance-service outage; a commission log can be lost while the payment succeeds; an audit row can be lost with no alert | **HIGH** |
| **TD-09** | **Internal exception messages are returned to the client.** Nearly every action ends `catch (\Throwable $e) { return response()->json(['error' => $e->getMessage()], 422); }` | `RequestController.php:287`, `:598`, `:754`, `:875`, `:964`, `:1054`, `:1228`, `:1264`, `:1293`, `:1325`, `:1523`, `:1589`, `:1611`, `:1675`, `:1836`; `LeaveController.php:181`, `:262`, `:387`, `:464`; `EssController.php:39`, `:292`; and the rest | Raw SQL errors, table names and column names reach the browser | **MEDIUM** (see SEC-16) |
| **TD-10** | **Magic numbers throughout**: thresholds 10000/10000/50000, TDS 5.0, bulk cap 1000, error cap 30, tolerance 0.005, feed 30, ESS 6/6/5, ledger 500/1000/1000/120, proof 5120 KB, digest 4000/2 MB/10/240, device token length 48, code length 6/8 | as cited above | Every one of them is a business policy expressed as a literal | **HIGH** |
| **TD-11** | **Dead code**: `LeaveApplication` model; the `'bool'` branch in the exits self-heal (`ApprovalService.php:206–207` — no entry in `$wantExit` uses that type); the unreachable ledger 403 (`RequestController.php:1087`); the unreachable `continue` in `paidLeaveDays` (`PayrollGenController.php:1066`); `$fy` at `RequestController.php:1784`; `hide_birth_year`; `notifications_feed`; the whole Spatie activitylog install; `mobile_devices.user_id` (declared, never written) | as cited | Misleads every reader | **MEDIUM** |
| **TD-12** | **One unit test covers this entire area.** `tests/Unit/GreetingServiceTest.php` — four assertions, all on `GreetingService::render()`/`vars()`/`defaults()`, no DB. There is **no test** for approver resolution, threshold escalation, leave-day counting, balance enforcement, the TDS calculation, the bounce clawback, the audit chain, or any authorisation rule | `tests/Unit/`, `tests/Feature/` (9 feature tests, none in this area) | Every rule in §3 is unprotected against regression | **HIGH** |
| **TD-13** | **The greeting candidate query is non-sargable** — `DATE_FORMAT(col, …)` on the left-hand side prevents any index on `dob`/`doj` | `GreetingService::candidates():171–174` | A full `employees` scan per tenant per matching hour | **MEDIUM** |
| **TD-14** | **`activeProvider()` is executed up to three times per broadcast** — once to send, then twice more to build the "channel skipped" message | `SendMessageController.php:73`, `:86`, `:138`, `:141` | Redundant queries on the slowest endpoint in the area | **LOW** |
| **TD-15** | **Leave's tolerant employee resolution loads the whole tenant's employee table into PHP** on any name-match miss | `LeaveController.php:318` | O(n) memory and time per leave application on a large tenant | **MEDIUM** |

---

## 7. Security findings

| ID | What it is | Evidence | Exploitability | Business impact | Severity |
|---|---|---|---|---|---|
| **SEC-01** | **Every request module is readable, tenant-wide, by any authenticated user.** `RequestController::list()` has **no role guard**, and row-level scoping is applied **only** when `$module === 'commissions'` | `RequestController::list():125–178`; the `when()` at `:155` is the only scoping; route `routes/web.php:424` has no middleware beyond auth. Root cause stated in-code: "SmartPRS gates the menu **CLIENT-SIDE** … and gates writes SERVER-SIDE" (`FeaturePermissions.php:9–13`) | Trivial — `GET /app/requests/increments` from any logged-in session | Any employee reads **every colleague's salary increment (old CTC → new CTC), every loan, every salary advance, every expense claim, every exit record with its clearance remarks, and every transfer** for the whole tenant. This is the most serious authorisation failure in the area | **CRITICAL** |
| **SEC-02** | **Any authenticated user can raise any request type for any colleague.** The self-forcing rule (BR-REQ-018) exists **only** inside `if ($module === 'commissions')`. The eight other modules accept an arbitrary `employee` string with no role check and no relationship check | `RequestController::apply():405–419` (the guard) vs `:389–396` (the unguarded employee lookup) | Trivial — `POST /app/requests/exits {employee: "CEO", fields:{…}}` | An employee can file an **exit/FnF** for the CEO, a ₹5,00,000 **loan** for a colleague, or an **increment** for themselves. Contrast leave, which *does* force self for any non-manager (`LeaveController.php:296–302`) — the fix was applied to leave after a 7 Aug 2026 test report and never propagated | **CRITICAL** |
| **SEC-03** | **Path traversal in the commission-proof server.** The only check on `proof_path` is `strpos($path, 'commission-proofs/') !== 0`, a **prefix test**; the file is then served with `response()->file(storage_path('app/public/'.$rec->proof_path))` | `RequestController.php:501–503` (accept), `:1626–1629` (serve) | Requires the ability to create a commission (self-claim is permitted) and set `proof_path`; `commission-proofs/../../../.env` satisfies the prefix | Arbitrary file read from the application storage tree, served to an owner/manager/accounts user | **HIGH** |
| **SEC-04** | **The Interakt webhook is unauthenticated, unsigned, unthrottled, CSRF-exempt and cross-tenant.** Status is inferred by **substring-scanning the whole serialised payload** for `"read"`, `"delivered"`, `"sent"`, `"failed"`; the loop does not `break`, so the last matching keyword *in the list order* wins. The `recruitment_messages` query carries **no `tenant_id` predicate**. All exceptions are swallowed and it always returns `{"ok":true}` | route `routes/web.php:198–204` (`withoutMiddleware([ValidateCsrfToken::class])`, no `throttle`); handler `RecruitMessagingController.php:380–417`. Contrast the Razorpay webhook, which **does** HMAC-verify, and `SmarteptSignature` middleware | Any internet host: `POST /webhooks/interakt {"phone_number":"9876543210","x":"read"}` | Flips the delivery status of the newest recruitment message for that number **in any tenant**. Blast radius is a status column, but the control absence is total | **HIGH (control absence) / MEDIUM (impact)** |
| **SEC-05** | **`denyUnlessRole()` fails OPEN.** On any `\Throwable` it returns `null`, which every caller reads as "allowed" | `ApprovalService.php:365–380`, explicitly at `:377–378` with the comment "fail open — availability over strictness on pivot errors". Inherited by `FeaturePermissions::guard()` at `FeaturePermissions.php:99–105`. Doc-measured blast radius: **71 call sites across 30 files** | Requires inducing an exception in the role pivot read — a missing/renamed `roles` table, a dropped DB connection mid-request, a corrupt Spatie permission cache, or `hasAnyRole` on an unexpected model | In this area it guards `payCommission`, `salaryPay`, `lockCommission`, `cleanOrphanCommissions`, `commissionPaymentVoucher` and `SendMessageController::send`. A schema hiccup makes every one of them world-writable to any authenticated user. Note the contrast: `WaTemplateController::scope()` and `MobileDeviceController::guard()` use `abort_unless` and fail **closed** | **HIGH** |
| **SEC-06** | **Leave balance enforcement is bypassable with an unrecognised type string.** `type` is validated only as `nullable|string|max:60` and is **never checked against `leave_types`**. `firstWhere('type', $typeName)` returns `null` for any unmatched string, `$bal` is falsy, and the entire balance block is skipped | `LeaveController.php:276` (validation), `:341–360` (the skippable block) | Trivial — post `type: "Casual Leave "` (trailing space) or `type: "Vacation"` | Unlimited approved leave with no quota check. The row is then also invisible to every balance calculation (which groups by `type_name`), so the consumption never appears | **HIGH** |
| **SEC-07** | **Any authenticated user can read any colleague's leave balances.** `GET /app/leaves/balances/{employee}` has **no role check**; the employee is matched by name, emp_code **or raw numeric id** | `LeaveController::balances():247–264`; route `routes/web.php:420` | Trivial, and enumerable by id | Leave consumption per type is a direct proxy for sickness patterns — special-category personal data under DPDP | **MEDIUM** |
| **SEC-08** | **An employee can redirect their own payslips and decision emails.** `email` is self-editable with no verification, no confirmation to the old address, and **no audit record** | `EssController.php:269`, `:280–288`; absence of any audit call | Trivial for any linked employee | Payslips, leave decisions and request decisions all go to `employees.email`. Combined with `currentEmployee()`'s `email OR name` fallback (`:57`), it is also an identity-confusion vector for legacy accounts with no `users.employee_id` link | **HIGH** |
| **SEC-09** | **The mobile device gate is marketed as a security control and is not enforced server-side.** No middleware, controller or route ever reads `mobile_devices.token`; `app/Http/Middleware/` contains six files, none device-related; grep for `mobile_devices` outside its own controller returns nothing | `MobileDeviceController.php` is the sole reader; `routes/web.php:79–87`, `:666–669` | N/A — it simply is not a control | Rejecting or revoking a lost phone does **not** cut off access to SmartPRS; the user opens the same workspace in any browser and logs in normally. The gate is a client-side UX flow | **MEDIUM** |
| **SEC-10** | **Cross-tenant error-text disclosure in the WhatsApp template test.** The failed-send lookup `DB::table('wa_log')->where('template', $row->name)->orderByDesc('id')->value('error')` is **not tenant-scoped** | `WaTemplateController.php:414` | Two tenants using the same template name (likely — the 17-template library seeds identical names for every tenant) | Tenant A's Interakt error text, which can carry a phone number, is shown to tenant B | **LOW** |
| **SEC-11** | **Unauthenticated cross-tenant push-token overwrite.** `UPDATE mobile_devices SET push_token=? WHERE device_id=?` — no tenant scope, no ownership proof, no `LIMIT`. Returns `{ok:false}` with **HTTP 200** on any exception, so failures are invisible | `MobileDeviceController::pushToken():173–189`; `device_id` is indexed but **not unique** (`:41`) | Public endpoint, `throttle:30,1`. `device_id` values are client-generated | Overwrites **every** row matching that `device_id` across all tenants. Inert today because no sender exists — a hijack primitive for the day push is implemented | **HIGH (latent)** |
| **SEC-12** | **SSRF and credential exfiltration via admin-configured provider URLs.** `Http::asForm()->post($cfg->api_url, $payload)` with no scheme or host allowlist; the payload carries the API key under **three** field names | `SendMessageController::postProvider():234–266`; `api_url` from `sms_settings`/`wa_settings` | Requires an admin (or anyone who reaches the settings write path — see SEC-05) to set `api_url` | The server POSTs to any internal address (`http://169.254.169.254/…`, internal admin panels) with attacker-chosen form data, and **hands the stored API key to whatever host is named** | **MEDIUM** |
| **SEC-13** | **Greetings config and delivery log are readable with no role check.** `GET /app/greetings/config` and `GET /app/greetings/log` have no guard; only the write endpoints do | `GreetingController::config():25–41`, `log():169–188` vs guarded `:46`, `:125` | Trivial | Any user reads the tenant's HR templates and a log disclosing which `employee_id` had a birthday on which date | **LOW** |
| **SEC-14** | **Provider API keys stored in plaintext.** `wa_settings.api_key` and `sms_settings.api_key` are plain `TEXT`; no `Crypt::encryptString` | `…000600…:141`, `:154`; read `WaService.php:54` | Requires DB read (a backup, a dump, a SQLi elsewhere) | The WhatsApp Business and SMS gateway credentials are recoverable from any database copy. Other secrets in the codebase are encrypted, so this is an inconsistency, not a house style | **LOW–MEDIUM** |
| **SEC-15** | **Four code paths break the tamper-evident audit chain** (see DEF-AUD-01) | `RequestController.php:1382–1387`, `:1424–1429`, `:1444–1449`, `:1822–1827`; `Audit::verify():118–121` | Not an attack — a design flaw that *destroys the control* | The events that most need tamper-evidence (approving a commission for an agent with an invalid DRA certificate, approving despite an open grievance, bouncing a paid commission) are exactly the ones that reset the chain | **HIGH** |
| **SEC-16** | **Internal exception messages returned to the client on ~25 endpoints in this area** | `RequestController.php:287`, `:598`, `:754`, `:875`, `:964`, `:1054`, `:1228`, `:1264`, `:1293`, `:1325`, `:1523`, `:1589`, `:1611`, `:1675`, `:1836`; `LeaveController.php:181`, `:262`, `:387`, `:464`; `EssController.php:39`, `:292`; `GreetingController.php:39`, `:62`, `:115`, `:164`, `:186`; `WaTemplateController.php:256`, `:314`, `:329`, `:346`, `:431`; `SendMessageController.php:147`; `NotificationController.php:33`, `:49`, `:61` | Trivial | Raw SQL errors expose table and column names and DB driver detail | **MEDIUM** |
| **SEC-17** | **`wa_log.body_values` stores credential-grade data forever.** The full template variable array is JSON-encoded and logged; for `smartprs_welcome` the fifth variable is the **temporary password** | `WaService.php:171`; template body `WaTemplateController.php:103` (`Temporary password: {{5}}`) | Requires DB or log read | Plaintext temporary passwords retained indefinitely with no retention policy | **HIGH** |
| **SEC-18** | **No retention anywhere, against a published 90-day commitment.** `activity_logs`, `notification_log`, `mail_log`, `wa_log`, `commission_logs`, `messages_log` and `late_notifications` grow forever; `late_notifications.down()` explicitly says `// keep the ledger.` | absence in `routes/console.php:140–262`; policy at `PolicyController.php:12`; Spatie's 365-day clean command is **not scheduled** | N/A | Personal data (client IPs on every submit and decide, free-text approval remarks that may contain medical or disciplinary detail, customer names and account references in `commission_logs`) is retained indefinitely, contradicting the published privacy policy | **MEDIUM** |
| **SEC-19** | **The commission proof upload has no role guard and no rate limit.** `POST /app/requests/commissions/proof-upload` validates only mime and size | `RequestController::proofUpload():1596–1613`; route `routes/web.php:430` | Any authenticated user, unlimited | Storage exhaustion; arbitrary file staging under `commission-proofs/` for later use in SEC-03 | **MEDIUM** |
| **SEC-20** | **No segregation of duties between Accounts confirmation and manager approval.** `admin` and `super_admin` satisfy **both** `isManager()` and `isAccounts()` | `RequestController.php:52–61` | By design | A single admin can confirm the money received *and* approve the commission — the exact control the two-stage gate exists to provide | **LOW–MEDIUM** |
| **SEC-21** | **Cross-tenant approver via an unscoped FK lookup.** `resolveApprover` resolves `reporting_manager_id` with `DB::table('employees')->where('id', …)->first()` and **no tenant predicate** — twice | `ApprovalService.php:321`, `:344`; leave's copy `LeaveController.php:103` | Requires a corrupted or migrated `reporting_manager_id` | A request is routed to, and decidable by, another tenant's employee | **MEDIUM** |
| **SEC-22** | **`cleanOrphanCommissions` hard-deletes NULL-tenant rows for any tenant's admin, with no audit record** | `RequestController.php:1249` (`tenant_id = $tid OR tenant_id IS NULL`), `:1254–1259` | Requires an `admin` role | Irreversible deletion of three tables' rows with no trail of what was deleted or by whom | **MEDIUM** |
| **SEC-23** | **Employee identity resolution falls back to a name match.** `currentEmployee()` matches `employees.email = users.email` **OR** `employees.name = users.name`, with `->first()` and no ordering; the `users.employee_id` branch is **not tenant-scoped** | `RequestController.php:31–50`; identical at `LeaveController.php:63–81` and `EssController.php:43–59` | Requires two employees with the same name in one tenant, or a legacy unlinked account | Non-deterministic identity: a user may act as, and see the data of, a namesake | **MEDIUM** |

### 7.1 Vulnerability classes checked and NOT found in this area

- **SQL injection.** Every raw fragment is either parameter-bound (`GreetingService.php:172–173`, `EssController.php:173` uses only literals) or interpolates an identifier taken from the in-code module registry, never from user input (`ApprovalService.php:226`, `:229`). No injectable path found.
- **XSS in the payment voucher.** Every interpolated value passes through a local `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` closure (`RequestController.php:1876`). Clean.
- **Mass assignment.** `safeRow()` filters to real columns, and the security-critical keys (`status`, `approver_id`, `tenant_id`, `decided_by`) are set explicitly **after** the client array is merged (`RequestController.php:534–543`), so client values are overwritten. Clean — at the cost of DEF-REQ-01.
- **Hardcoded credentials.** None found in any file in scope. Every credential path reads from the database or `.env`. The only literal-looking secret is the sample value `Xy7#kPq2Lm9w` at `WaTemplateController.php:104`, which is template seed data — but it is the *shape* of the real value that template transmits and logs (SEC-17).
- **Tenant isolation on the decide path.** `decide()` and `LeaveController::decide()` both scope the row lookup by the actor's `tenant_id` (`RequestController.php:1346–1347`; `LeaveController.php:401–402`). `commissionPaymentVoucher` deliberately reports a tenant mismatch as *not found* (`:1866`) — good practice.

---

## 7A. The eight questions, answered explicitly

### Q1. Is the approval engine truly generic, or does each request type carry its own hard-coded path?

**Verdict: structurally generic, behaviourally type-specific. It is a generic *table router*, not a generic *workflow engine*.**

What genuinely is shared by all nine types: the module registry lookup, employee resolution, approver resolution, the `status='pending'` birth, the tenant/company copy, the FY stamp, `safeRow`, the audit record, the approver email, the decide gate, the decision write, the decision email, and the inbox aggregation.

What is **not** shared — every branch below is a hard-coded `if` on the module name:

| Request type | Where its own logic lives | What is type-specific |
|---|---|---|
| **expenses** | `ApprovalService.php:30` only | Threshold ₹10,000. Nothing else. Its `reason` field is silently discarded (DEF-REQ-01) |
| **advances** | `ApprovalService.php:31` | Threshold ₹10,000. `reason` discarded |
| **loans** | `ApprovalService.php:32` | Threshold ₹50,000. `tenure_months` and `reason` discarded |
| **commissions** | `RequestController.php:405–506` (apply), `:610–756` (bulk), `:763–877` (edit), `:885–966` (pay), `:1065–1230` (ledger), `:1238–1266` (orphans), `:1269–1295` (lock), `:1298–1327` (history), `:1342` (reopen), `:1353–1364` (lock + accounts gates), `:1366–1394` (DRA gate), `:1416–1454` (compliance + grievance), `:1456–1463` (log), `:1596–1677` (proof + accounts), `:1680–1838` (bounce), `:1849–1928` (voucher); plus `list():155–164`, `:193–204`; plus `ensureCollectionCols():68–102`, `ensureBounceCols():1680–1701`, `normMode():105–122` | **~1,100 of the controller's 1,929 lines.** Two claim types, five mandatory evidence fields, TDS arithmetic, scheme validation, proof upload/serve, Accounts stage, DRA gate, compliance warning, grievance warning, edit-after-approval with diffs, disbursement ledger, auto-lock, manual lock, reopen, bounce with auto-clawback, payment voucher, orphan purge, bulk upload, self/reportee restriction |
| **clawbacks** | `ApprovalService.php:34`; created programmatically by `bounce():1786–1802` | Threshold 0. `cycle_month` and `company_id` discarded |
| **increments** | `RequestController.php:510–530` (cross-computation), `:1486–1492` (letter on approval); `ApprovalService.php:137–183` (self-heal + the misplaced `commission_payments` create) | Old/new/% cross-computation; `new_ctc > 0` required; the threshold input is `new_ctc`, not `amount`; letter email on approval |
| **exits** | `ApprovalService.php:188–218` (ten clearance columns) | A ten-item clearance checklist, self-healed onto the table; two competing "last day" columns |
| **bonus** | `ApprovalService.php:37` | Threshold 0. `kind` discarded (column is `type`) |
| **transfers** | `ApprovalService.php:50–99` (the whole table, self-created); `RequestController.php:183–191` (onboarding tracker), `:211–222` (timeline), `:1470–1481` (apply + order email on approval) | The only request table with **no migration**; an `applied` status written by `TransferService` outside this controller; a process-tracker timeline; two side effects on approval |

**The `decide()` method alone contains six module-name conditionals** (`:1342`, `:1353`, `:1359`, `:1371`, `:1418`, `:1438`, `:1456`, `:1470`, `:1486`). Adding a tenth request type that needs any behaviour beyond "route to the manager and email both sides" means editing `RequestController`.

**Leave is a tenth type that does not use the engine at all** — its own controller, its own `currentEmployee()`, its own `resolveApprover()`, its own `ensureCols()`, its own inline `safeRow`, its own inbox, and it is stitched back in only by `RequestController::inbox():1542–1559` reading the `leaves` table directly.

### Q2. What approval-hierarchy configurability exists today?

**Verdict: none. The hierarchy is entirely fixed in code.**

The complete rule is 42 lines (`ApprovalService::resolveApprover():310–352`):

```
base := employees.reporting_manager_id → that employee row        (FK, NOT tenant-scoped)
      | employees.reporting_manager (NAME string) → matched employee in tenant
      | NULL, label 'Reporting Manager / HR'

if (threshold > 0 && amount !== null && amount >= threshold):
      base's own reporting_manager_id → that employee   → [id, "<name> (higher approval — amount ₹n)"]
      else                                              → [NULL, "Branch / Company Admin (amount ₹n)"]
else: → [base id, base name]
```

| Capability | Present? | Evidence |
|---|---|---|
| Multi-step / sequential (level 1 then level 2 then level 3) | **NO** — a request has exactly **one** approver for its whole life | `approver_id` is written once at `RequestController.php:539` and never rewritten |
| Parallel approval | **NO** | single `approver_id` column |
| Any-one-of (a pool of approvers) | **NO** | single column, single id |
| All-must (quorum / unanimity) | **NO** | single column |
| Conditional routing (by department, cost centre, branch, employee grade, request sub-type) | **NO** | the only condition is `amount >= threshold` |
| Role-based approver ("any Finance Manager") | **NO** — the approver is an **employee id**, never a role | `:1398` compares `approver_id === my employee id` |
| Per-tenant hierarchy configuration | **NO** | the registry and the resolver are static PHP |
| Per-request-type hierarchy | **NO** — every type uses the identical resolver | `:531` |
| Threshold bands (₹0–10k → manager, ₹10k–1L → GM, >₹1L → director) | **NO** — one threshold, one hop | `:332` |
| Configurable threshold amounts | **NO** — three literals | `ApprovalService.php:30–32` |
| Escalation on timeout / SLA breach | **NO** | no schedule, no `due_at`/`sla_`/`reminder_sent_at` column anywhere |
| Reminders | **NO** | same |
| Auto-approve / auto-reject on expiry | **NO** | same |
| Delegation / out-of-office / alternate approver | **NO** | no table, no column, no code |
| Reassignment of a pending item | **NO** | no endpoint |
| Re-evaluation when the amount changes | **NO** — escalation is computed once, at creation | `:531` is the only call in `apply()` |
| Skip-level / matrix / dotted-line reporting | **NO** | one `reporting_manager_id` per employee |

**The docblock is wrong.** `ApprovalService.php:19–21` states the escalation order as `Reporting Manager → Team Leader → Branch / Company Admin`. **There is no Team Leader lookup anywhere in the code**; the second hop is simply the manager's own manager, and the third "level" is a *label string*, not an approver. `DEFECT / CONFLICT` (comment vs code).

Two further consequences worth stating plainly: a `NULL` `approver_id` is a **valid outcome** that silently sends no email and is invisible in every non-manager inbox (DEF-REQ-03); and bulk commission uploads pass `threshold = 0` explicitly (`:687`), so a 1,000-row file of large claims never escalates at all.

**The only "escalation" word elsewhere in the codebase is unrelated** — `2026_08_07_000100_fix_escalations_priority.php` widens columns on an `escalations` table that is a customer/borrower **grievance desk**, not an approval mechanism.

### Q3. Leave types: data or hard-coded names? Is any behaviour keyed off a leave-type NAME string?

**Verdict: leave types are configurable data, but the *system's behaviour* is keyed off the type NAME string in six places, and three hard-coded fallback lists exist.**

Types are rows in `leave_types` (`tenant_id, name, days_per_year, carry_forward, paid`), CRUD-able by admin/hr_manager through Masters (`MasterController.php:70–76`).

**Every name-string coupling found:**

| # | Coupling | Evidence | Consequence |
|---|---|---|---|
| 1 | **`leaves.type_name` is the persisted key.** `type_id` — the normalised FK the schema provides — is **never written** | insert `LeaveController.php:364–378`; column `…000200…:89` | The FK exists and is always NULL |
| 2 | **Balance consumption is grouped by the name string** | `LeaveController.php:217` (`$t = $r->type_name ?: 'Leave'`), `:231–232` (`$taken[$t->name]`) | Renaming a type resets every employee's recorded consumption of it to zero, mid-year |
| 3 | **The balance *enforcement* lookup is an exact string match** | `LeaveController.php:342`: `firstWhere('type', $typeName)` | Any unmatched string — including one with a trailing space — skips the entire quota check (SEC-06) |
| 4 | **ESS balances group by the same name string, independently** | `EssController.php:209`, `:223–224` | Two implementations of the same name-keyed grouping |
| 5 | **The applied type is accepted as free text.** `type` is validated only as `nullable|string|max:60` and is **never checked against `leave_types`** | `LeaveController.php:276` | Arbitrary type names are stored and become invisible orphan buckets |
| 6 | **`'Leave'` is the hard-coded fallback bucket** when `type_name` is empty — in three places | `LeaveController.php:217`, `:341`, `:374`; `EssController.php:155`, `:209` | Untyped leave silently accumulates under a pseudo-type that has no quota |

**Behaviour keyed off a *specific* type name:** none found. No code says `if ($type === 'Loss of Pay')`. Unpaid handling is driven by the `leave_types.paid` **flag**, which is correct in design — and completely defeated in practice by DEF-LVE-02, because the flag can never be reached without `type_id`.

**Hard-coded name lists (three, divergent):**

| Source | Names | Quotas |
|---|---|---|
| `LeaveController::listLeaves():176` (the apply dropdown) | Casual Leave, Sick Leave, Earned Leave, Comp-Off, Loss of Pay | — |
| `LeaveController::computeBalances():200–206` | the same five | 12 / 12 / 15 / 0 / 0; `carry_forward` 0/0/1/1/0; `paid` 1/1/1/1/0 |
| `EssController::leaveBalances():195–200` | **four — Comp-Off omitted** | 12 / 12 / 15 / 0 |

Also: the string `'Leave Encashment'` exists as a **bonus-module option label** (`AppController.php:3939`) with no connection to any leave balance.

### Q4. Which leave-policy features exist at all?

| Feature | Status | Evidence |
|---|---|---|
| **Accrual** (monthly / quarterly / on service) | **ABSENT** | `computeBalances():191–244` has no date arithmetic beyond `whereYear`. The full annual `days_per_year` is available from 1 January |
| **Carry-forward** | **PARTIAL — data only, zero behaviour** | `leave_types.carry_forward` exists (`…000200…:79`), is editable in Masters (`MasterController.php:72`) and in the UI (`AppController.php:10768`), is read into the fallback objects (`LeaveController.php:201–205`), and **never appears in the arithmetic** at `:239`. The method's own docblock at `:186–189` promises it |
| **Lapse** at year end | **ABSENT** (implicitly total) | the next year's query simply returns no prior-year rows; nothing is carried, capped or expired deliberately |
| **Encashment** | **ABSENT** | no code path converts a balance to money. "Leave Encashment" is only a bonus-module label (`AppController.php:3939`) |
| **Proration on join / exit** | **ABSENT** | no DOJ/LWD term in `computeBalances()`. (Payroll prorates *salary* by DOJ; leave entitlement is unaffected) |
| **Negative balance / advance leave** | **ABSENT** | `LeaveController.php:354` refuses `days > remaining` outright; no overdraft, no approval-with-overdraft. Note it **is** reachable indirectly via SEC-06 |
| **Half-day leave** | **ABSENT** | no field, no UI, no writer. `leaves.days` is `decimal(4,1)` and could hold 0.5; `:370` always writes a whole number. (`half_day_hours` in `ShiftResolver`/`late_policy` is an *attendance* concept — how many worked hours count as a half day — and is unrelated) |
| **Hourly / short leave** | **ABSENT** | no unit smaller than a day exists |
| **Blackout periods** | **ABSENT** | grep for `blackout` across `app/` and `database/` returns nothing |
| **Clubbing / prefix-suffix rules** | **ABSENT** | grep returns nothing in this sense |
| **Sandwich rule** | **PARTIAL — payroll only, not leave** | `sandwich_rule` is a Statutory Rate, default **0/off** (`AppController.php:12179`), implemented as `PayrollGenController::sandwichDays()`. It operates on *attendance coverage*, not on leave-balance deduction. And because its "covered" test uses the broken `paidLeaveDates()` (DEF-LVE-02), it currently treats LOP leave as coverage and will not fire across it |
| **Comp-off** | **PARTIAL — a type name only** | "Comp-Off" appears in two hard-coded fallback lists with `days_per_year = 0`; there is **no earning mechanism** — nothing credits a comp-off for weekend or holiday work. Grep for `comp.off`/`compoff` finds no logic |
| **Minimum-staffing checks** | **ABSENT** | grep for `staffing`/`min_staff`/`minimum_staff` returns nothing. Nor is there overlap detection or a team calendar |
| *(bonus)* **Weekend/holiday exclusion from the day count** | **ABSENT** | `LeaveController.php:338` counts inclusive calendar days |
| *(bonus)* **Cancellation / withdrawal** | **ABSENT** | `leaves.status` is `ENUM('pending','approved','rejected')` and `ensureCols()` never widens it |
| *(bonus)* **Attachments (medical certificates)** | **ABSENT** | no upload route for leave |
| *(bonus)* **Eligibility rules per type / grade / gender / probation status** | **ABSENT** | one flat `days_per_year` per type per tenant |
| *(bonus)* **Advance-notice and max-consecutive-days rules** | **ABSENT** | no such validation |

**Twelve of the fifteen named features are wholly absent; two are data-only or payroll-only; none is fully implemented.**

### Q5. How is leave balance computed, and can it go wrong?

The formula is transcribed in §4.5. It can go wrong in **eight** distinct ways:

| # | Failure mode | Mechanism | Evidence |
|---|---|---|---|
| 1 | **Concurrency — double-spend.** Two applications submitted simultaneously both read the same balance, both pass the check, both insert. There is **no transaction, no lock, no unique constraint and no re-check at approval time** | grep confirms no `DB::transaction`/`lockForUpdate` in `LeaveController.php` | `:342–360` (check) then `:381` (insert), unguarded |
| 2 | **Balance is enforced at apply, never at approve.** The check runs once, against the state at submission. Two pending applications each individually within balance are both approvable, taking the employee negative | `decide():392–466` performs **no balance check at all** |
| 3 | **Re-decide inflates or deflates consumption.** With no pending-state guard, an approved leave can be flipped to rejected and back; `used` and `pending` recompute each time | DEF-REQ-02 |
| 4 | **Backdating is unrestricted.** `from`/`to` are `required|string` parsed by Carbon with **no past-date check and no locked-period check**. A leave dated into a closed payroll month is accepted and immediately counted | `:274–280`, `:329–338` |
| 5 | **Year attribution is by `from_date` only.** A leave 28 Dec → 4 Jan charges **all 8 days** to the from-year and **zero** to the new year | `whereYear('from_date', $year)` at `:214` |
| 6 | **An unrecognised type bypasses the check entirely** | SEC-06 |
| 7 | **A renamed type resets consumption to zero** | DEF-LVE-07 |
| 8 | **The published number and the enforced number differ** — ESS omits `pending` | DEF-LVE-04 |

**Additionally the balance query is not tenant-scoped** (`:212–215`, filtering only on `employee_id`), which is safe today only because `employees.id` is globally unique.

**Answer in one line: the balance is a derived figure recomputed on demand from `leaves` rows, with no ledger, no reservation, no locking and no approval-time re-check — so it can be over-drawn by concurrency, by double-approval, by backdating, by a type-name trick, or by a rename.**

### Q6. ESS scoping — can an employee ever act on another employee's record?

**Verdict for ESS itself: NO. Every ESS transaction derives its subject from the session; no ESS endpoint accepts an employee identifier from the request.**

| ESS endpoint | Subject derived from | Evidence |
|---|---|---|
| `GET /app/ess/me` | `EssController::currentEmployee($request)` — session only | `:23`, `:43–59` |
| `POST /app/ess/update` | `EssController::currentEmployee($request)`; the update is `where('id', $emp->id)` | `:262`, `:287` |

There is **no `employee_id` / `employee` / `emp_code` parameter accepted by either route**, and the field whitelist (`:266–277`) contains no identity field. Read-side blocks are equally session-derived: `profile($emp)`, `payslips($emp)` (`where('employee_id', $e->id)`), `attendance($emp,$tid)` (tenant-scoped by emp_code since rev172), `leaves($emp,$tid)`, `leaveBalances($emp,$tid)`. Only `notices($tid)` is tenant-wide, which is its purpose.

**However — the question's real intent is "can an employee act on a colleague's record anywhere in this area", and there the answer is YES, in five places, none of them in `EssController`:**

| # | Instance | Takes the subject from the request? | Guard | Evidence |
|---|---|---|---|---|
| 1 | **`POST /app/requests/{module}` for the eight non-commission modules** — expenses, advances, loans, clawbacks, increments, exits, bonus, transfers | **YES** — `'employee' => ['required','string']`, matched on `name` OR `emp_code`, tenant-scoped only | **NONE.** No role check, no self-forcing, no relationship check | `RequestController::apply():383–396`; the self-forcing block at `:405–419` is inside `if ($module === 'commissions')` — **SEC-02, CRITICAL** |
| 2 | **`GET /app/requests/{module}`** for those same eight modules | Not an id, but returns **every row of the tenant** | **NONE** | `list():125–178`; the only scoping (`:155`) is `$module === 'commissions'` — **SEC-01, CRITICAL** |
| 3 | **`GET /app/leaves/balances/{employee}`** | **YES** — a path segment matched on name, emp_code **or raw numeric id** | **NONE** — any authenticated user | `LeaveController::balances():247–264` — **SEC-07** |
| 4 | **`POST /app/requests/commissions`** | **YES** — but properly constrained | `! isManager()` → must be self or a direct reportee, else 403 | `RequestController.php:409–419` — correctly guarded |
| 5 | **`GET /app/requests/commissions/ledger?employee=`** | **YES** for managers | Non-manager is silently forced to self (the 403 branch is dead code) | `:1074–1089` — behaviour safe, code dead (DEF-REQ-09) |

For contrast, **leave apply is correctly protected**: `LeaveController.php:296–302` overwrites the posted `employee` with the caller's own record for **any** non-manager, with an in-code comment citing the 7 Aug 2026 test report item 9 ("A Field Agent was able to select another employee and submit leave on their behalf"). **That fix was applied to leave and never propagated to the eight request modules.**

### Q7. Notification triggers, audiences and templates — configurable vs hard-coded

**Every trigger, audience and template body in this area is hard-coded at a call site. What is configurable is the transport (SMTP server, provider URL/key), the branding, and — uniquely — the greeting text.**

Complete trigger enumeration (gathered by grepping every `'kind' =>` literal in `app/` and reading each call site). ● = in this area's scope.

| ● | Trigger (`kind`) | Fires when | Channel(s) | Audience | Template source | Logged to |
|---|---|---|---|---|---|---|
| ● | `request.submitted` | `RequestController::apply()` succeeds **and** the approver resolved to an employee **with an email** | Email | the resolved **approver** | PHP literals at `:583–588` (subject "Approval needed: <label>", heading "A request needs your approval", lines Type/Requested by/Amount) | `mail_log` |
| ● | `request.approved` / `.rejected` / `.pending` | `decide()` — `kind = 'request.'.$decided` | Email | the **requesting employee** | literals at `:1509–1513` | `mail_log` |
| ● | *(none)* | **Leave submitted** | — | — | **no notification of any kind** | — |
| ● | `leave.approved` / `leave.rejected` | `LeaveController::decide()` succeeds and the employee has an email | Email | the **employee** | literals at `:451–455`, 5-row detail table | `mail_log` |
| ● | `greeting.birthday` | `greetings:send` sweep, `DATE_FORMAT(dob,'%m-%d')` match, dedupe `greeting:birthday:<emp>:<year>` | In-app + Email (each toggleable) | the employee (`user_id` via `users.employee_id`, else `users.email`) | **`app_settings.greetings.birthday.subject/.message` — CONFIGURABLE**, `{{name}} {{first_name}} {{company}} {{years}} {{date}}` | `notification_log` (+`mail_log`) |
| ● | `greeting.anniversary` | same sweep, `doj` match, `years >= min_years` | In-app + Email | the employee | **CONFIGURABLE** | `notification_log` (+`mail_log`) |
| ● | `greeting.test` | `POST /app/greetings/test` | In-app + Email | **the acting HR user themselves** | configurable, prefixed `[TEST] ` | `notification_log` (+`mail_log`) |
| ● | *(no kind — `Mail::raw`)* | late arrival on punch ingest, `late_email_enabled = 1`, punch date today/yesterday | Email, plain text, **CC the reporting manager** | the employee | `app_settings.greetings.late.*` (configurable) + a hardcoded fallback body | **`late_notifications` only — NOT `mail_log`** |
| ● | `broadcast` | `POST /app/send-message` | Email + SMS + WhatsApp per `channels[]` | the resolved audience (one of eight filters) | **free text typed per send** | `mail_log` per recipient; **one aggregate** `messages_log` row |
| ● | `template.test` | `POST /app/wa-templates/{id}/test` | WhatsApp | the tester's own mobile | the stored template + its sample values | `wa_log` |
| ● | `absence.prev_day` | `absence:notify` hourly sweep, dedupe `absence:<emp>:<Y-m-d>` | In-app + Email | the employee | literals in `AbsenceService.php:189–199` | `notification_log` |
| ● | `absence.prev_day.manager` | same sweep, dedupe `…:mgr` | In-app + Email | the reporting manager | literals `AbsenceService.php:208–215` | `notification_log` |
| ● | `probation.due` / `probation.overdue` | `probation:notify` daily 07:15, dedupe `probation:<emp>:<end>:<milestone>:u<uid>` | In-app + Email | HR / manager user(s) | literals `ProbationService.php:215–225` | `notification_log` |
| ● | `probation.confirmed` | probation confirmation action | In-app + Email | the employee | literals `ProbationService.php:302–306` | `notification_log` |
| ● | `attendance.correction.requested` | employee raises a regularisation | In-app + Email | the reviewer | literals `AttendanceCorrectionService.php:411–415` | `notification_log` |
| ● | `attendance.correction.approved` / `.rejected` | HR reviews it | In-app + Email | the employee | literals `AttendanceCorrectionService.php:440–445` | `notification_log` |
| ● | `ops.error_digest` | `errors:digest` daily 07:45, only if error lines exist | Email | `env('SMARTPRS_OPS_EMAIL')` | literals `ErrorDigest.php:87–92` | `mail_log` |
| | `transfer.order` | transfer approved | Email + PDF attachment | the employee | | `mail_log` |
| | `increment.letter` | increment approved | Email | the employee | | `mail_log` |
| | `salary.*`, `compliance.digest`, `subscription.renewal`, `signup.welcome`, `signup.payment`, `auth.reset`, `user.invite`, `tenant.admin_invite`, `onboarding.*`, `offer.link`, `nda.link`, `recruit.bulk`, `scheme.*`, `agent.earnings.link`, `licence_*`, `coupon.exclusive`, `lead`, `demo.pin`, `quotation`, `update_grant`, `employee.backup.scheduled` | other sections | Email (+WhatsApp for a few platform flows) | various | | `mail_log` / `wa_log` |

**Channel reality check.** Of the 17 in-scope triggers, **every one uses only in-app and/or email**, except `broadcast` and `template.test`. **Zero HR workflow sends WhatsApp** despite a fully seeded 17-template tenant library (DEF-NOT-04). **Zero send push** — no sender exists (BR-NOT-021).

**Configurable vs hard-coded, summarised:**

| Aspect | Configurable? |
|---|---|
| Whether an event notifies at all | **No** — hard-coded call site |
| Who receives it | **No** — hard-coded per call site |
| Which channels it uses | **No** — `in_app`/`email` booleans are literals per call site. Exception: the greetings sub-config exposes per-occasion `email`/`in_app` toggles |
| Subject / heading / body wording | **No**, except greetings and the late-arrival template |
| Placeholders available | **No** — a fixed set of 5 (+4 late-only) |
| Language / localisation | **No** — English literals throughout |
| Per-user opt-out or unsubscribe | **No** — no preference store, no unsubscribe link |
| SMTP server, from-address, branding | **Yes** — per company, with a platform fallback |
| SMS/WhatsApp provider URL and key | **Yes** — per tenant |
| WhatsApp template bodies and names | **Yes** — but nothing consumes them |
| Greeting on/off, hour, min_years, skip_inactive | **Yes** — per tenant |
| Retry / queue behaviour | **No** — `tries=3`, `backoff=30`, queue `'mail'` are literals |

### Q8. Is the audit trail immutable/tamper-evident, complete, and does it capture old→new values?

**Tamper-evident: designed to be, and defeated in practice.**

The design is sound — a SHA-256 chain over `(prev_hash | tenant | user | action | entity | entity_id | detail | timestamp)`, verified with `hash_equals` (`Audit.php:49–61, 91–93, 106–132`). Three things defeat it:

1. **Four code paths bypass `Audit::record()` entirely**, inserting into `activity_logs` with no `prev_hash` and no `row_hash` (`RequestController.php:1382–1387`, `:1424–1429`, `:1444–1449`, `:1822–1827`). `verify()` treats a null hash as a legacy row, **skips it and resets `$prev` to `'GENESIS'`** (`:118–121`). So every commission approval carrying a DRA, compliance or grievance warning — and every bounce — silently resets the chain. **SEC-15/DEF-AUD-01, CRITICAL.**
2. **The chain has a read-then-insert race** with no transaction or lock (`Audit.php:91–96`), producing false tamper alarms under ordinary concurrency. **DEF-AUD-02.**
3. **`activity_logs` has no database-level immutability** — no trigger, no append-only constraint, no separate credentials. Any DB-level `UPDATE`/`DELETE` is possible; the chain only *detects* it, and only if the chain is intact. `commission_logs` has no chain at all — its "immutability" is a comment.

**Immutable: no.** Nothing prevents modification; the chain is detection-only, and the detection is defeated as above.

**Complete: no — and the gaps are the money.**

| Captured | Not captured |
|---|---|
| Request submitted (`RequestController.php:556`) | **ESS self-edits** — contact details, DOB, and the payslip delivery email (`EssController::updateProfile()`, no audit call at all) |
| Request decided (`:1414`) | **Commission edits after approval** — a rich diff goes to `commission_logs` (`:870`) and **never** to `activity_logs` |
| Leave submitted (`LeaveController.php:383`) | **Commission payments** (`payCommission():943` → `commission_logs` only) |
| Leave decided (`:429`) | **Manual locks** (`:1289` → `commission_logs` only) |
| Greetings config saved (`GreetingController.php:58`) | **Accounts confirm/flag** (`:1663` → `commission_logs` only) |
| Greetings test sent (`:160`) | **Orphan deletions** — three tables' rows deleted with **no record of any kind** (`:1254–1259`) |
| DRA/compliance/grievance warnings, bounce (chain-breaking, see above) | **Salary payments** (`salaryPay():1037` writes a `by` string on the row; no `activity_logs` entry) |
| | **Broadcasts** (`messages_log` only — and one aggregate row) |
| | **WhatsApp template create/edit/delete/status change** |
| | **Mobile device approve/reject/revoke** (stamps the row; no ledger entry) |
| | **Reads of any kind** — who viewed whose payslip, ledger, leave or balances |

**Old→new values: NO. Not anywhere in this area.**

`AuditTrail::change($request, $action, $entity, $id, $before, $after)` exists precisely for this (`AuditTrail.php:78–91`) and produces `['changes' => ['field' => ['from'=>…, 'to'=>…]]]`. **It has no call site in `RequestController`, `LeaveController` or `EssController`.** What is recorded instead is:

- request submit → `{"employee":"<name>"}`
- request decide → `{"remarks":"…"}` and the new status as the `action`
- leave submit → `{"type":"…","days":n}`
- leave decide → `{"remarks":"…"}`

**No before-state, no after-state, no field-level diff.** The only place old→new exists at all is `commission_logs.details`, as a **human-readable English sentence** built by string concatenation (`RequestController.php:807`, `:815`, `:832`, `:844`, `:861`, `:870–871`) — e.g. `amount ₹10,000.00 gross → ₹12,000.00 gross (TDS 5% = ₹600.00, net ₹11,400.00) (edited AFTER approval)`. That is unparseable, unqueryable, uncomparable, capped at 1,000 characters by `mb_substr` (`ApprovalService.php:292`), outside the hash chain, and **fail-soft** — a failure emits only `Log::warning` and the business action still succeeds without its audit row (`:296–298`).

**Additional weaknesses:** the chain is global rather than per-tenant (`Audit.php:91`); actors are recorded as display-name strings rather than user ids (DEF-AUD-04); `detail` has no length cap; `verify()` loads the whole table into memory (`:111`); auditing is best-effort and silently swallows its own failures (`:97–99`); and **no retention policy exists** against a published 90-day commitment (SEC-18).

---

## 8. Documentation-vs-source discrepancies

Ground rule: the source wins. Every item below was re-verified in `/home/claude/prs2/`.

| ID | Doc claim | Source reality | Verdict |
|---|---|---|---|
| **DISC-01** | `ApprovalService.php:19–21` docblock: escalation order is `Reporting Manager → Team Leader → Branch / Company Admin` | **There is no Team Leader lookup anywhere in the code.** The second hop is the manager's own `reporting_manager_id`; the "third level" is a label string, never an approver id | **Doc/comment wrong.** The reference doc correctly flags this |
| **DISC-02** | `LeaveController.php:186–189` docblock: balance = "entitlement (days_per_year) **+ carry-forward opening** − used − pending" | `:239` computes `round($ent - $u, 1)`. Neither carry-forward **nor pending** appears in the published `balance`; pending is subtracted only in the enforcement expression at `:353` | **Comment wrong on two counts.** The reference doc caught carry-forward and missed that `balance` also omits pending |
| **DISC-03** | Reference doc §1.11: the commission ledger gives "403 if they try someone else" (`:1087–1089`) | For a non-manager `$emp` is forced to `$me` at `:1082`, so the test at `:1087` **can never be true**. A non-manager passing `?employee=Someone` silently receives **their own** ledger | **Doc wrong.** Behaviour is safe; the described control does not exist (DEF-REQ-09) |
| **DISC-04** | Reference doc §3.5/BR-LVE-003 and §3.4: describes the balance rules without noting that ESS publishes a *different* number | `EssController.php:230` publishes `remaining = ent − used` with no pending subtraction, while `LeaveController` enforces `ent − used − pending` | **Doc missed a live conflict** (DEF-LVE-04 / NEW-LVE-101) |
| **DISC-05** | Reference doc §5.5 lists the notification register but does not state that **`leaves.tenant_id` and `company_id` are NOT NULL** while being copied from a possibly-null employee field | `…000200…:87–88` vs `LeaveController.php:365–366` | **Doc missed** (DEF-LVE-09 / NEW-LVE-105) |
| **DISC-06** | Reference doc §5.4 describes `resources/views/emails/generic.blade.php` in detail (114 lines, branded header, `$lines` table, no `{!! !!}`, Ametecs footer with GSTIN `36AAHCT0971F1ZB`) | **The file does not exist in the staged copy**, along with ~25 other referenced views (`login`, `dashboard`, `landing`, `policies.*`). `MailService.php:185` does reference it | **Not a doc error — a staging gap.** I record the doc's description as `UNVERIFIED` and assert nothing about the template's content or XSS posture |
| **DISC-07** | Task brief lists `resources/views/leave/form.blade.php` and `leave/index.blade.php` as files to read | **No `view('leave.*')` reference exists anywhere in `app/` or `routes/`.** The leave module is JSON-only, rendered by the SPA in `AppController` | **Brief wrong.** These blades never existed |
| **DISC-08** | Reference doc §9.6: "`cleanOrphanCommissions()` deletes rows from three tables with no audit record of any kind" | Confirmed exactly. Additionally the tenant predicate is `tenant_id = $tid **OR tenant_id IS NULL**` (`:1249`), so a NULL-tenant orphan is deletable by any tenant's admin | **Doc correct; I add the cross-tenant nuance** (SEC-22) |
| **DISC-09** | Reference doc §7.2 and OQ-11 leave open whether anything ever writes `leaves.type_id` | **Resolved: nothing does.** Grep for `type_id` across `app/` yields exactly two leave-related hits, both **reads** (`EssController.php:155`, `ReportController.php:101`) | **OQ-11 closed** |
| **DISC-10** | Reference doc OQ-12 asks where `activity_logs` is created and its full column list | **Resolved:** `2026_01_01_000700_create_salary_slips_table.php:13–23` — `id, tenant_id, user_id, action, entity, entity_id, detail(text), ip(45), created_at`; `Audit::ensure():25–46` adds `prev_hash`, `row_hash`. **Note there is no `updated_at`** | **OQ-12 closed** |
| **DISC-11** | Reference doc OQ-01 asks whether `deductions` rows are ever created from Loss-of-Pay leave | **Resolved: no.** No code path creates a `deductions` row of type `lop` from a leave record. LOP reaches pay only through attendance proration — which, per DEF-LVE-02, leave never triggers | **OQ-01 closed** |
| **DISC-12** | Reference doc OQ-03 asks whether payroll writes `commission_payments` with `mode='payslip'` | **Resolved: yes** — `PayrollGenController.php:2726` inserts with `'mode' => 'payslip'`, and `:2402–2405` deletes them on regeneration | **OQ-03 closed** |
| **DISC-13** | Reference doc OQ-19 asks whether the `late` greeting template is reachable from any UI | **Resolved: yes** — `GreetingController::preview():74` and `test():131` both accept `type ∈ {birthday, anniversary, late}`, and `config()` returns a dedicated `lateVariables` list (`:36`) | **OQ-19 closed** |
| **DISC-14** | Reference doc §1.10 describes `exits` fields without noting the duplicate date column | The migration creates `last_day` (`…000500…:85`); the runtime self-heal adds `last_working_day` (`ApprovalService.php:191`); the SPA posts only the latter (`AppController.php:3946`) | **Doc missed** (DEF-REQ-06) |
| **DISC-15** | Reference doc §5.2 channel matrix marks broadcast SMS/WhatsApp `PARTIALLY IMPLEMENTED` without flagging that the filter maps are unscoped | `SendMessageController.php:165–168` plucks `departments`, `designations`, `branches`, `teams` **with no `tenant_id` predicate** | **Doc missed** (NEW-NOT-105) |
| **DISC-16** | Reference doc §2.3 states `safeRow` "is what stops a client from setting `status`, `approver_id`, … **except** that those keys are set explicitly after the merge" | Correct, and worth restating positively: `RequestController.php:534–543` merges `$fields` **first** and then overwrites the six security-critical keys, so mass assignment on those is genuinely impossible | **Doc correct** |
| **DISC-17** | Reference doc §1.12 says the inbox performs "~10 unbounded `SELECT *` queries" | Confirmed, and it is worse: `ensureAuditCols()` is called **inside the module loop** (`:1567`), so every inbox open also runs a DDL-check pass over nine tables, each doing multiple `Schema::hasColumn` metadata queries | **Doc correct, understated** |

---

## 9. Migration verdict per capability

| Capability | Verdict | Rationale | Data migration implication |
|---|---|---|---|
| **Module registry (9 request types)** | **REPLACE** | A static PHP array cannot become a configurable request-type catalogue. SmartPRS 3 needs a metadata-driven type definition (name, label, fields, validation, workflow, document actions, per-tenant enablement) | Map the nine keys to seeded type definitions per tenant; preserve `module` → new `request_type_id` |
| **Approver resolution (`resolveApprover`)** | **REPLACE** | One hop plus an optional second is not a workflow engine. Nothing in §7 Q2 is salvageable as a design | Historic `approver_id`/`approver_name` migrate as a **snapshot** on the historic request; the live routing rules are re-authored as configuration |
| **`decide()` gate and state write** | **REDESIGN** | The authorisation shape (manager OR named approver) is reasonable; the missing state machine, the unconditional `remarks` write and the module-name conditionals are not | Status values `pending/approved/rejected` map 1:1; `reopen` becomes a modelled transition |
| **Commission sub-system** | **MIGRATE (as a vertical), REDESIGN internally** | The business capability — collection evidence, Accounts gate, TDS, ledger, lock, bounce/clawback, voucher — is a genuine differentiator for the collections industry and must survive. Its *implementation* (1,100 lines inside a god controller) must not | Full data migration of `commissions`, `commission_payments`, `commission_logs`. Re-key `commission_logs.by` from name strings to user ids where resolvable |
| **Accounts confirmation stage** | **REDESIGN → generic** | It is a two-stage approval that only one type gets. In SmartPRS 3 it is one configured step in a workflow | `accounts_status/by/at/note` become step-instance records |
| **DRA / compliance / grievance gates** | **REDESIGN → generic rule engine** | Three bespoke `if` blocks with one tri-state setting between them. They are pre-conditions on a workflow transition | Settings map to rule definitions; the `activity_logs` warning rows migrate as-is |
| **Bulk commission upload** | **RETAIN (capability), REDESIGN** | The row-tolerant, error-collecting import pattern is good. Cap, TDS default and no-email policy become configuration | None |
| **Payment voucher (HTML by concatenation)** | **REPLACE** | Document layout must be a template | None — regenerate on demand |
| **Orphan purge** | **REMOVE** | It exists to clean up after demo reseeds renumbering employees. Fix the cause; a hard delete with no audit is not a feature | None |
| **`safeRow()` silent drop** | **REMOVE** | A defence that destroys data. Replace with explicit per-type field definitions and real validation | None; but audit existing rows for the nine known losses before cut-over — **that data is already gone and cannot be recovered** |
| **`ensureAuditCols()` / all runtime DDL** | **REMOVE** | Schema belongs in migrations | The receiving schema must already contain every column the self-heal adds, including the `transfers` table |
| **Leave module (whole)** | **REPLACE** | Every substantive leave-policy feature is absent (§7 Q4: 12 of 15). The balance is a derived figure with no ledger. The paid/unpaid flag never reaches payroll. Rebuilding is cheaper than repairing | `leaves` rows migrate with `type_name` → resolved `leave_type_id` (**a manual reconciliation step per tenant** for renamed/orphaned names); `days` must be **recomputed** under the new working-day rule or explicitly preserved as historic; opening balances must be **stated by the customer**, not derived, because carry-forward was never applied |
| **`leave_types` master** | **RETAIN, EXTEND** | Name/quota/paid/carry-forward is the right shape; it needs accrual, cap, lapse, encashment, eligibility, unit and proration attributes | Direct migration; `carry_forward` becomes meaningful for the first time |
| **`LeaveApplication` model + the misnamed migration** | **REMOVE** | Dead code and an actively misleading filename | None |
| **ESS read (`/app/ess/me`)** | **RETAIN, REDESIGN** | Correctly session-scoped and cleanly degrading. Needs configurable content, the leave-type display fix, and pagination | None |
| **ESS write (`/app/ess/update`)** | **REDESIGN** | Correct scoping, but no audit, no verification on email/mobile, no `date` rule on DOB, no approval option | None |
| **Approvals Inbox** | **REDESIGN** | Right idea, wrong implementation (unbounded, N-queries, DDL per load). Becomes one indexed query over a unified task table | Pending items rebuild from the migrated requests |
| **`NotificationService` (in-app)** | **RETAIN** | The cleanest component in this area: dedupe keys, ownership-checked reads, fail-soft, honest `skipped` accounting. Needs preferences, pagination, retention and a push channel | `notification_log` migrates directly |
| **`MailService`** | **RETAIN, REDESIGN** | Per-company SMTP with a platform fallback and a queued job with retries is sound. Needs configurable templates, a body archive, and enforcement of the queue name | `mail_log` migrates; note it holds no bodies |
| **`SendNotification` job** | **RETAIN** | Correct as written | None |
| **Email templates (`emails.generic` + literal wording at each call site)** | **REPLACE** | Every subject/heading/line label is a PHP literal. SmartPRS 3 needs a per-tenant, per-event, multi-language template store | Seed the new store from the current literals |
| **Broadcast (`SendMessageController`)** | **REDESIGN** | Synchronous per-recipient HTTP in the request, one aggregate log row, a shotgun payload to an unvalidated URL, unscoped filter maps. The *capability* is wanted; nothing about the implementation is | `messages_log` migrates as history only |
| **SMS/WhatsApp provider adapters** | **REPLACE** | `postProvider()`'s shotgun payload is a guess at every provider's API. Needs real per-provider adapters with an allowlist | `sms_settings`/`wa_settings` migrate; **api keys must be encrypted in transit to the new store** |
| **`WaService` (Interakt)** | **REDESIGN** | Send path is fine; the hardcoded `+91`, the last-10-digit truncation, no retry and no queue are not | `wa_log` migrates — **but `body_values` contains plaintext temporary passwords; purge or redact before migrating** (SEC-17) |
| **WhatsApp template registry** | **RETAIN, WIRE UP** | A well-built registry with correct scoping, gap validation, approval invalidation and a fail-closed guard — attached to nothing. The fix is to make the notification engine channel-aware | `wa_templates` migrates directly |
| **Interakt inbound webhook** | **REDESIGN** | Must be authenticated, signed, throttled, tenant-scoped and parsed properly rather than substring-scanned | None |
| **Mobile device gate** | **REDESIGN or REMOVE** | Today it is a client-side UX flow marketed as an anti-fraud control. Either enforce it server-side (middleware validating the token) or stop describing it as security | `mobile_devices` migrates; `user_id` is empty on every row |
| **Push notifications** | **BUILD (does not exist)** | Token collection exists; no sender, no SDK, no key, no call path | `push_token` values migrate but must be re-registered in practice |
| **Greetings** | **RETAIN, EXTEND** | The best-configured feature in the area: per-tenant JSON config, live preview, self-test, per-year dedupe, and a real unit test. Needs a tenant timezone, more occasions, more placeholders, more channels, a sargable query, and the late-arrival template moved out | `app_settings` rows migrate; `hide_birth_year` can be dropped |
| **Late-arrival email** | **MERGE into the notification engine** | It bypasses `MailService` entirely (`Mail::raw`), so it uses the wrong mailer and leaves no delivery record | `late_notifications` migrates as a dedupe ledger |
| **`Audit` hash chain** | **REDESIGN** | The idea is right and must survive. The global chain, the race, the four bypasses and the name-string actors must not | `activity_logs` migrates as **historic, unverifiable** data — the chain is already broken at every commission-warning row. Re-chaining is not possible; migrate with an explicit "pre-migration, unverified" marker |
| **`AuditTrail` facade** | **RETAIN, MANDATE** | The right call shape, including a `change()` helper for old→new that this area never uses | None |
| **Spatie `laravel-activitylog`** | **REMOVE** | Installed, migrated, never written, and one character away from the real table | Drop `activity_log` (it is empty by construction) |
| **`commission_logs`** | **REDESIGN** | The only place old→new exists, and it is English prose in a varchar(1000), outside the chain, fail-soft | Migrate as historic text; new events write structured diffs |
| **`notifications_feed`** | **REMOVE** | Migrated, entirely unused | Drop |

---

## 10. SmartPRS 3 requirements implied

Numbered, specific and testable. Each cites the as-is behaviour that forces it.

### 10.1 Request-type metadata engine

1. The request-type catalogue **must be data, not code**. A tenant administrator must be able to create, rename, reorder, enable and disable request types without a deploy, because `ApprovalService::modules():27–43` is a static PHP array and every tenant sees all nine types.
2. Each request type must own a **field schema** — field key, label, data type, options, requiredness, min/max, regex, help text, display order, conditional visibility — because today the SPA's `RQ_MAP` (`AppController.php:3902–3947`) and the database columns are two unsynchronised lists and the server accepts `fields` as a **wholly unvalidated array** (`RequestController.php:386`).
3. Submitting a field that the type's schema does not define **must fail loudly with a named error**, never be silently discarded. `safeRow()` (`ApprovalService.php:262–266`) currently drops nine known fields with no signal, losing the expense reason, the loan tenure and purpose, the clawback recovery month and the bonus type.
4. Field-level data must be stored against the type's schema (EAV, JSON with a schema, or generated columns), so that adding a field never requires an `ALTER TABLE` at runtime.
5. Each type must declare its **amount semantics** — which field is the monetary basis, whether it is gross or net, and its currency — because today `amount` means "whatever the client posted" for five modules, "the NET after TDS" for commissions (`:439`) and "the new CTC" for increments (`:529`).

### 10.2 Workflow / approval engine

6. The engine must support **N-step sequential approval** with per-step conditions, because a request today has exactly one approver for its whole life (`approver_id` written once at `RequestController.php:539`).
7. It must support **parallel steps**, **any-one-of (pool)**, **all-must (unanimous)** and **quorum (M of N)** completion rules; none of the four exists.
8. An approver must be resolvable as **a named person, a role, a role-within-a-scope (department/branch/cost-centre), a reporting-hierarchy hop, or a rule expression** — today it is only an employee id derived from `reporting_manager_id` (`ApprovalService.php:320–329`).
9. The engine must support **amount bands with more than one boundary** (e.g. ≤₹10k manager, ≤₹1L GM, >₹1L director), because the current rule is a single `>=` against a single literal threshold and only three of nine types can escalate at all (`:332`).
10. Thresholds, and the currency they are expressed in, must be **per tenant and per request type** — currently three literals (`ApprovalService.php:30–32`).
11. The engine must support **conditional routing** on any request field or employee attribute (department, grade, branch, employment type, sub-type), not only on amount.
12. **Delegation** must exist: an approver must be able to nominate a delegate for a date range, and the engine must route to the delegate and record both identities. No such concept exists today.
13. **Reassignment** of a pending item to another approver, with a reason and an audit entry, must be possible. Today `approver_id` is never rewritten.
14. **SLA support is mandatory**: a per-step due date, a reminder schedule (N reminders at configurable intervals), and a breach action (escalate to the next step, notify a watcher, auto-approve, auto-reject, or do nothing). Today there is **no `due_at`, `sla_`, `escalate_at` or `reminder_sent_at` column anywhere**, and `routes/console.php:140–262` registers twelve schedules of which **none** touches pending approvals — so a request assigned to an approver who never acts sits Pending forever, silently.
15. When approver resolution yields **no approver**, the engine must fail loudly — raise an exception, route to a configured fallback queue, or notify an administrator — never create the record silently with a null approver and no notification (`RequestController.php:571` skips the email; the inbox filter `approver_id = $myId ?? -1` can never match it).
16. Approver resolution must be **re-evaluated when the routing inputs change** (amount edited, employee transferred), because escalation is currently computed once at creation.
17. The engine must enforce an explicit **state machine** with declared transitions and guards. Today `decide()` never checks the current status, so an approved request can be re-approved indefinitely, re-firing transfer orders, increment letters and decision emails (`RequestController.php:1330–1525`).
18. **Cancel / withdraw by the requester** must be a first-class transition, with a configurable rule for whether it is permitted after the first approval. It does not exist for requests or leave; `leaves.status` is an ENUM of three values that `ensureCols()` never widens.
19. Decision **remarks must be append-only per transition**, not a single overwritable column — today a blank re-decide erases the prior decision's stated reason (`:1408`).
20. The engine must support **pre-conditions on a transition** expressed as rules (compliance score ≥ N, certification valid, no open grievance, evidence attached, another party has confirmed), each independently configurable as **block / warn / off**. Today there are three bespoke `if` blocks: the DRA gate is tri-state (`:1371–1394`) while compliance and grievance are hard-coded warn-and-allow (`:1416–1454`).
21. A **two-stage "confirm then approve"** pattern (the Accounts gate) must be expressible as an ordinary configured step for **any** request type, not hard-coded for one (`:1359–1364`).
22. **Segregation of duties must be enforceable**: the engine must be able to require that the actor of step 2 is not the actor of step 1. Today `admin` and `super_admin` satisfy both `isManager()` and `isAccounts()` (`RequestController.php:52–61`), so one person can confirm the money and approve the payment.
23. **Post-transition actions must be declarative**, not `if ($module === '…')` blocks: generate a document, send a notification, call a service, update a record, create a follow-on request. Today only transfers and increments have actions, hard-coded at `:1465–1492`.
24. Every workflow action must be **idempotent or guarded**, so re-entering a state cannot re-send a letter that was already sent.
25. A **lock/unlock** capability must exist with an authorised unlock-with-reason path. Today `locked_at` is terminal and there is **no unlock endpoint anywhere in the codebase**.

### 10.3 Leave engine

26. Leave must be modelled as a **balance ledger with dated transactions** (accrual credits, opening balances, consumption debits, adjustments, encashment, lapse), not recomputed on demand from `leaves` rows, because the current derivation has no reservation, no locking and no approval-time re-check (§7 Q5).
27. **Accrual must be configurable per leave type**: frequency (monthly / quarterly / annual / on-completion-of-service), amount, accrual date, rounding rule, and whether it accrues during unpaid leave or probation. Absent today — the full annual quota is available from 1 January.
28. **Carry-forward must be implemented**, with a cap (days or percent), an expiry window, and a lapse action. Today the flag is collected in the UI and **never appears in the arithmetic** (`LeaveController.php:239`).
29. **Encashment** must be supported: eligibility rule, maximum encashable days, the valuation basis (basic / gross / CTC / a fixed day-rate), and the payroll component it becomes. Absent — "Leave Encashment" is only a bonus-module label.
30. **Proration on joining and on exit** must be configurable per type. Absent.
31. **Half-day leave must be supported** (first half / second half), and the engine must support a configurable **minimum unit** per type — day, half-day or hour. Absent; `leaves.days` is `decimal(4,1)` and nothing ever writes a fraction.
32. **Day counting must be configurable per leave type**: calendar days, working days, or working days excluding public holidays. Today it is always inclusive calendar days (`:338`), which charges 4 days for a Fri–Mon leave while payroll credits 2 (DEF-LVE-06).
33. The engine must support a **sandwich / clubbing rule for leave-balance deduction** — an off-day or holiday bracketed by leave counts as leave — independently of the existing payroll-side `sandwich_rule` (which is an *attendance* rule, default off).
34. **Blackout periods** must be definable (date ranges, per department/branch/type) during which applications are refused or require a higher approver. Absent.
35. **Minimum-staffing checks** must be supported: refuse or warn when approving would take a team below a configured headcount or percentage on any covered date. Absent, along with overlap detection and any team calendar.
36. **Compensatory-off earning** must exist as a rule: work on a week-off or holiday, beyond N hours, credits M comp-off days, expiring after P days. Today only a *type named* "Comp-Off" exists, with no earning mechanism.
37. **Eligibility rules per type** must be configurable — minimum service, probation status, gender, grade, employment type — with the entitlement itself variable by grade or service band. Today there is one flat `days_per_year` per type per tenant.
38. **Application rules** must be configurable: minimum advance notice, maximum consecutive days, maximum applications per period, and whether backdating is permitted (and how far). Today `from`/`to` are free-form strings with **no past-date check and no locked-period check** (`:274–280`).
39. **Negative balance / advance leave** must be a configurable allowance with a limit, rather than a hard refusal (`:354`) that is trivially bypassed by naming an unrecognised type.
40. The leave type applied for **must be validated against the tenant's configured types** and stored as a **foreign key**, never as a free-text string. Today `type` is `nullable|string|max:60`, is never checked, and an unmatched value skips the entire quota check (SEC-06) while becoming an invisible orphan bucket.
41. The **leave year basis must be configurable** (calendar / financial / anniversary-of-joining). Today it is `now()->year`, while requests and payroll use April–March (DEF-LVE-08).
42. A leave spanning a year boundary must **split its consumption across both years**, not attribute all of it to the from-year (`whereYear('from_date', …)` at `:214`).
43. Renaming a leave type must **not** affect historic consumption — which follows automatically from requirement 40.
44. The **paid/unpaid classification must reach payroll reliably**, with an integration test asserting that an approved unpaid leave produces an LOP day. Today `leaves.type_id` is never written, so payroll's `paid` test is always `true` and Loss-of-Pay leave is paid (DEF-LVE-02, CRITICAL).
45. **Leave cancellation and withdrawal** must exist as modelled transitions with balance restoration, including cancellation of an already-approved future leave.
46. **Attachments** must be supported per type, with a rule such as "a medical certificate is required for sick leave over N days".
47. A **team leave calendar** and **overlap warnings** at approval time must exist.
48. **Balance enforcement must be transactional and re-checked at approval**, because today two simultaneous applications can both pass a check that is performed only at submission, with no lock and no unique constraint.
49. **One balance figure must be published everywhere.** Today the leave module publishes `ent − used`, enforces `ent − used − pending`, and ESS publishes `ent − used` under a different key name (DEF-LVE-04).
50. Any leave-type default seed must exist in **exactly one place**; there are currently three divergent hard-coded lists (DEF-LVE-05).

### 10.4 ESS

51. Every ESS transaction must derive its subject **from the session**, never from a request parameter — the current `EssController` does this correctly and the pattern must be preserved and extended to the request modules (SEC-02).
52. **Which fields an employee may self-edit must be configuration**, per tenant, per field. Today it is a literal validation array (`EssController.php:266–277`).
53. Self-edits must support an optional **maker-checker step** — the change is proposed and applied only on HR approval — configurable per field.
54. **Changes to email and mobile must require verification** (OTP or a confirmation link to the *existing* address), because payslips and decision emails are delivered to `employees.email` and it is editable instantly with no confirmation (SEC-08).
55. **Every ESS self-edit must be audited with old→new values.** Today `updateProfile()` makes no audit call of any kind (DEF-ESS-02).
56. Date fields must be validated as dates. `dob` is currently `nullable|string|max:20` and drives an automated action (the greeting sweep).
57. ESS content, list sizes and section visibility must be configurable; the leave-type display defect (`EssController.php:155` always renders `'Leave'`) must not recur — it follows from requirement 40.
58. ESS write endpoints must be **rate-limited**.

### 10.5 Notification engine

59. **Every notifiable event must be a registry entry**, not a hard-coded call site: event key, description, available merge variables, default channels, default audience.
60. **Audience must be a rule**, not a literal: requester, approver, approver's manager, a named role, a department, a watcher list, an external address — configurable per event per tenant.
61. **Templates must be data**: per tenant, per event, per channel, per language, with a merge-variable catalogue and a preview. Today every subject, heading and detail-line label in this area is a PHP literal (§7 Q7).
62. **Channel selection must be per event and per tenant**, and the engine must actually be able to reach WhatsApp — a fully seeded 17-template tenant library exists today with **no consumers** (DEF-NOT-04).
63. **Per-user notification preferences and a working unsubscribe** must exist for non-mandatory notifications. Absent entirely.
64. **Push notifications must be implemented end to end.** Today the token is stored and there is **no sender anywhere in the codebase** — exhaustive grep for `fcm|firebase|push_token|sendPush` returns three lines, all in `MobileDeviceController`.
65. **Delivery must be per-recipient tracked** for every channel, with status, error and retry, and the record must be visible in the UI. Today SMS/WhatsApp broadcasts write **one aggregate row**.
66. **All bulk sending must be asynchronous and batched.** Today a broadcast makes one 15-second-timeout HTTP call per recipient inside the request (DEF-NOT-09).
67. **Queue configuration must be validated at boot or health-checked.** Today `->onQueue('mail')` silently strands every notification email if the worker was started without `--queue=mail` (DEF-NOT-11).
68. **No notification path may bypass the engine.** The late-arrival email uses `Mail::raw` on the global mailer, so it uses the wrong SMTP and leaves no delivery record (DEF-NOT-03).
69. **Provider integrations must be real adapters** with a per-provider payload contract and an **egress allowlist** on the endpoint URL; the credential must never be sent to an unvalidated host, and never under three field names at once (SEC-12).
70. **Country code and phone normalisation must be configuration**, not the literal `'+91'` with a last-10-digit truncation that silently misroutes every non-Indian number (`WaService.php:117`, `:148`).
71. **Retry and backoff must be per channel and configurable**; WhatsApp currently has none.
72. **Provider credentials must be encrypted at rest** (SEC-14), and **credential-grade values must never be written to a message log** — `wa_log.body_values` currently stores plaintext temporary passwords forever (SEC-17).
73. **Inbound webhooks must be authenticated, signature-verified, throttled, tenant-scoped and schema-parsed.** The Interakt webhook is none of these and infers status by substring-scanning the whole payload (SEC-04).
74. **Retention must be implemented and enforced per log table**, configurable per tenant, defaulting to the published commitment. Nothing prunes anything today (SEC-18).

### 10.6 Greetings and scheduled communications

75. **Occasions must be extensible** (birthday, work anniversary, probation confirmation, festival, milestone, custom date field), not two hard-coded blocks.
76. **The send hour must be evaluated in the tenant's timezone**, which must be a tenant attribute — today it is the server's hour despite a docblock claiming otherwise (DEF-NOT-05).
77. The **merge-variable catalogue must be extensible**, including custom employee fields; today it is a fixed set of five.
78. The candidate query must be **sargable** — store a computed `birth_md`/`joining_md` column or an indexed generated column, rather than `DATE_FORMAT(col, …)` on the left-hand side (TD-13).
79. **29 February must be handled** by a configurable rule (greet on 28 Feb or 1 Mar in common years).
80. The **disciplinary late-arrival template must not live inside the Greetings configuration object** (DEF-NOT-07).
81. Configuration payloads must be **schema-validated**; `saveConfig` currently accepts any array and merges arbitrary keys (NEW-NOT-113).
82. **Read endpoints for HR configuration must be role-guarded**; `GET /app/greetings/config` and `/log` are not (SEC-13).

### 10.7 Audit and governance

83. **Every business transaction must record old→new values** in a structured, queryable form. Today `AuditTrail::change()` exists and has **no call site** in this entire area; the only diff that exists is an English sentence in a varchar(1000) outside the hash chain (§7 Q8).
84. **All writes to the audit ledger must go through one API.** Four code paths currently insert directly, and because `verify()` resets `$prev` to `'GENESIS'` at any row with a null hash, each of them **silently destroys the tamper-evidence of everything before it** (SEC-15, CRITICAL).
85. **The chain must be per tenant** and appended **transactionally with a lock**, so concurrent writes cannot produce false tamper alarms (DEF-AUD-02).
86. **Verification must be incremental and checkpointed**, not a full-table load (`Audit::verify():111`).
87. **Actors must be recorded as user ids** with a denormalised name for display, never as a name string alone (DEF-AUD-04).
88. **Audit coverage must be complete for money-moving and privacy-affecting actions**: commission edits after approval, payments, locks, Accounts decisions, deletions, salary payments, broadcasts, template changes, device approvals, and **ESS self-edits** — none of which reaches the ledger today (§7 Q8).
89. **Hard deletes must be forbidden** in favour of soft deletion with an audit entry; `cleanOrphanCommissions` deletes rows from three tables with no record (SEC-22).
90. **Audit failures must be alerted**, not silently swallowed (`Audit.php:97–99`).
91. **Read auditing** must exist for sensitive views (payslip, ledger, leave balances, another employee's record).
92. **Retention and legal hold** must be configurable and enforced, reconciled with the published privacy policy.

### 10.8 Platform-level requirements this area forces

93. **Authorisation must fail CLOSED.** `denyUnlessRole()` deliberately returns `null` (= allowed) on any exception, across **71 call sites in 30 files** (SEC-05). The correct pattern already exists elsewhere in the same codebase (`abort_unless` in `WaTemplateController::scope()` and `MobileDeviceController::guard()`).
94. **Read endpoints must be authorised server-side.** `FeaturePermissions.php:9–13` states the current model outright — the menu is gated **client-side** and only writes are gated server-side — which is the root cause of SEC-01.
95. **Row-level scoping must be a framework concern**, applied by default, not a per-endpoint `when()` that exists for one module out of nine.
96. **Tenant scoping must be enforced by the data layer**, not by ad-hoc `when($tid, …)` clauses that are omitted in at least six places in this area (`ApprovalService.php:321`, `:344`; `RequestController.php:31–43`; `LeaveController.php:212`; `EssController.php:205`; `WaTemplateController.php:414`; `SendMessageController.php:165–168`; `MobileDeviceController.php:182`).
97. **A user with no tenant must never be silently attached to an arbitrary tenant** (`RequestController.php:381`, `:630`; `LeaveController.php:272`).
98. **Employee identity must resolve deterministically** from an explicit link, never by a name match with `->first()` and no ordering (SEC-23).
99. **No runtime DDL.** Schema changes belong in migrations; the "self-creating convention" must not survive (TD-01).
100. **Internal exception messages must never reach the client** (~25 endpoints in this area, SEC-16).
101. **Every business rule in §3 must have an automated test.** This entire area currently has **one** unit test file with four assertions, all on greeting template rendering (TD-12).
102. **Uploaded-file paths must be canonicalised and confined**, not prefix-checked (SEC-03), and upload endpoints must be role-guarded and rate-limited (SEC-19).
103. **A device gate, if marketed as a security control, must be enforced server-side by middleware** — or the claim must be withdrawn (SEC-09).

---

## 11. Open questions / PRODUCT OWNER DECISION REQUIRED

| # | Question | Why it matters | What I could not determine from source |
|---|---|---|---|
| **PO-01** | **How far back must LOP-leave mispayment be corrected?** DEF-LVE-02 means every approved leave since go-live — including leave filed as "Loss of Pay" — was credited as a paid day. | Direct salary overpayment, potentially across every payroll cycle run to date. | The blast radius is a data question (how many `leaves` rows with a Loss-of-Pay `type_name` were approved and overlapped a generated payslip). **PRODUCT OWNER + a data query on the live database.** |
| **PO-02** | **What opening leave balances does each tenant start SmartPRS 3 with?** Carry-forward was never applied, so the current stored state does not represent what employees are entitled to. | Migration cannot derive the right number; deriving it from `leave_types.carry_forward` would silently grant balances nobody ever accrued. | **PRODUCT OWNER DECISION REQUIRED** — opening balances must be stated per tenant, not computed. |
| **PO-03** | **Do the nine request types survive as-is, or is the catalogue re-designed?** Several look like a single tenant's requirements (Clawback, Commission with DRA gating, Exit clearance with "DRA ID returned"). | Determines whether SmartPRS 3 seeds nine types or a smaller generic set plus a collections-industry pack. | **PRODUCT OWNER DECISION REQUIRED.** |
| **PO-04** | **Is edit-after-approval ("Ejaz's rule") retained, and for which types?** It is deliberate and documented in-code, and it conflicts with an immutable-decision model. | Shapes the whole state machine. | The intent is recorded (`RequestController.php:758–762`); whether it generalises is not. **PRODUCT OWNER DECISION REQUIRED.** |
| **PO-05** | **Should compliance-score and open-grievance checks be able to BLOCK, as the DRA gate can?** Today they are hard-coded warn-and-allow. | Determines whether these become first-class rules with a tri-state, or stay advisory. | **PRODUCT OWNER DECISION REQUIRED.** |
| **PO-06** | **What is the correct leave-year basis per customer** — calendar, April–March, or anniversary-of-joining? Today leave uses the calendar year and everything else uses April–March. | Affects accrual, carry-forward, lapse and every historic balance. | **PRODUCT OWNER DECISION REQUIRED.** |
| **PO-07** | **Should leave days be charged in calendar days or working days?** Today the balance is charged in calendar days and payroll credits working days. Changing it alters every historic balance. | A Fri–Mon leave currently costs 4 days and credits 2. | **PRODUCT OWNER DECISION REQUIRED**, including whether historic `days` values are recomputed or frozen. |
| **PO-08** | **Is `denyUnlessRole`'s fail-open behaviour to be reversed everywhere at once?** It is deliberate ("availability over strictness"). Reversing it could lock users out during a pivot/schema fault. | 71 call sites; the highest-leverage security change available. | The trade-off is stated, not the risk appetite. **PRODUCT OWNER DECISION REQUIRED.** |
| **PO-09** | **Is the mobile device gate a security control or a UX flow?** It is marketed as "the anti-fraud layer"; it is not enforced. | Either build server-side enforcement or correct the marketing claim. | **PRODUCT OWNER DECISION REQUIRED.** |
| **PO-10** | **Is WhatsApp a real notification channel for HR events?** A 17-template library is seeded, tested and approvable, and nothing sends from it. | Either wire the channel or remove the library, which currently over-promises. | **PRODUCT OWNER DECISION REQUIRED.** |
| **PO-11** | **What retention applies to each log table?** The published policy commits to 90 days; nothing implements any retention; `commission_logs` holds customer names and account references indefinitely and `wa_log` holds plaintext temporary passwords. | DPDP exposure and a live contradiction with a published commitment. | **PRODUCT OWNER + LEGAL DECISION REQUIRED.** |
| **PO-12** | **Must an ESS email/mobile change be verified, and should it require HR approval?** Today it is instant, unverified and unaudited, and it redirects payslips. | Determines whether ESS becomes a maker-checker surface. | **PRODUCT OWNER DECISION REQUIRED.** |
| **PO-13** | **How are the nine silently-dropped fields recovered?** The data was never written; it cannot be recovered from the database. | Historic expense claims have no reason, historic loans no tenure or purpose, historic clawbacks no recovery month. | **PRODUCT OWNER DECISION REQUIRED** — accept the loss, or re-collect. |
| **PO-14** | **Does `activity_logs` migrate as verified history?** It cannot: the chain is already broken at every commission-warning row and every bounce, and the race can have broken it elsewhere. | Affects any claim of a continuous tamper-evident record. | Recommend migrating with an explicit "pre-migration, unverifiable" marker. **PRODUCT OWNER DECISION REQUIRED.** |
| **PO-15** | **Should `super_admin`/`admin` continue to satisfy both the Manager and the Accounts role?** It defeats the segregation of duties the Accounts gate exists to provide. | Audit and fraud posture on commission payments. | **PRODUCT OWNER DECISION REQUIRED.** |
| **PO-16** | **Who is the fallback approver when an employee has no reporting manager?** Today: nobody — the request is created with a null approver, no email is sent, and it is invisible to every non-manager inbox. | A silent black hole in the current system. | **PRODUCT OWNER DECISION REQUIRED.** |
| **PO-17** | **Are the three escalation thresholds (₹10,000 / ₹10,000 / ₹50,000) a real policy or a placeholder?** Per the shared brief these are recorded as *the values the code currently uses*, never as correct values. | They will be seeded as per-tenant defaults. | **PRODUCT OWNER CONFIRMATION REQUIRED.** |
| **PO-18** | **Is the 5% commission TDS default correct, and should it come from statutory configuration?** The literal `5.0` appears in three places; a per-tenant `comm_tds_rate` exists in Statutory Rates and **is not read** by this code. Recorded as the value the code uses, not as a legal rate. | Direct effect on net payable to agents. | **PRODUCT OWNER + STATUTORY REVIEW REQUIRED.** |
| **PO-19** | **Should leave submission notify the approver?** Requests do; leave does not, and the omission looks unintentional rather than designed. | Explains why leave sits unapproved in practice. | **PRODUCT OWNER CONFIRMATION** (the answer is almost certainly yes). |
| **PO-20** | **Is `transfers` — the only request table with no migration — expected to exist on every deployment?** It is created by a runtime `Schema::create` on first touch. | Any deployment that never touched the code path has no table and no data. | **PRODUCT OWNER + OPS CHECK** on live installs before migration. |
