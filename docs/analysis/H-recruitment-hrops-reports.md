# AS-IS ANALYSIS — RECRUITMENT / ATS, LETTERS & DOCUMENTS, PERFORMANCE & POINTS, KNOWLEDGE BASE, REPORTS, MASTER DATA, SETTINGS & CONFIGURATION

## 0. Scope and evidence base

### 0.1 Source files read in full (`/home/claude/prs2/`)

| File | Lines | Read |
|---|---|---|
| `app/Http/Controllers/MasterController.php` | 1 225 | complete (2 passes) |
| `app/Http/Controllers/RecruitmentController.php` | 1 272 | complete (2 passes) |
| `app/Http/Controllers/HiringDriveController.php` | 378 | complete |
| `app/Http/Controllers/RecruitMessagingController.php` | 419 | complete |
| `app/Http/Controllers/OfferAcceptController.php` | 90 | complete |
| `app/Http/Controllers/NdaSignController.php` | 104 | complete |
| `app/Http/Controllers/LetterController.php` | 522 | complete |
| `app/Http/Controllers/DocumentController.php` | 331 | complete |
| `app/Http/Controllers/PerformanceController.php` | 137 | complete |
| `app/Http/Controllers/PointsController.php` | 266 | complete |
| `app/Http/Controllers/KbController.php` | 227 | complete |
| `app/Http/Controllers/ReportController.php` | 521 | complete |
| `app/Http/Controllers/ConfigController.php` | 448 | complete |
| `app/Http/Controllers/SettingsController.php` | 206 | complete |
| `app/Http/Controllers/DashboardController.php` | 227 | complete |
| `app/Http/Controllers/AppIconController.php` | 142 | complete |
| `app/Http/Controllers/ScreenHelpController.php` | 2 206 | dispatcher + `fallback()` + `content()` + sample module blocks read verbatim; the 2 000 lines of content arrays enumerated and key-counted programmatically (see §1.15) |
| `app/Http/Controllers/LeadController.php` | 225 | complete |
| `app/Http/Controllers/LandingController.php` | 191 | complete |
| `app/Http/Controllers/PolicyController.php` | 51 | complete |
| `app/Console/Commands/SeedIndustryContent.php` | 766 | complete |
| `resources/views/letter-pdf.blade.php` | 53 | complete |
| `resources/views/idcard-pdf.blade.php` | 73 | complete |
| `resources/views/nda-sign.blade.php` | 60 | complete |
| `resources/views/offer-accept.blade.php` | 64 | complete |
| `resources/views/agent-audit-body.blade.php` | 88 | header block read (compliance body owned by another analyst) |
| `resources/views/dashboard.blade.php` | 98 | complete |
| `tests/Feature/MasterCrudTest.php` | 96 | complete |
| `tests/Feature/ReportTest.php` | 27 | complete |

### 0.2 Supporting source consulted (targeted)

`routes/web.php` (route register, CSRF exemptions, guards — lines 35–64, 200–204, 233–236, 267–285, 309–341, 349–366, 408–414, 481–505, 508–509, 543–548, 603–664, 693–695);
`app/Http/Controllers/AppController.php:667, 1381, 3251, 10711–10723, 10843–10853` (SPA screen register, `LETTER_SAMPLES`, letter field hints, the `letters-nda` screen definition);
all 49 files in `database/migrations/` (table-provenance sweep);
`config/` directory listing (to establish that **no `config/dompdf.php` exists**).

### 0.3 Reference-document sections read

| Document | Lines | Subject |
|---|---|---|
| `COMPLETE_PROJECT_BUSINESS_AND_TECHNICAL_DOCUMENTATION.md` | 15 951–17 301 | §10 intro, §0 route register, §1 recruitment pipeline, §2 hiring drives, §3 messaging, §4 offer/NDA token flow |
| " | 17 902–18 442 | §6 letters & documents, §7 performance & points, §8 knowledge base |
| " | 18 442–18 560 | §9 reports |
| " | 18 560–18 795 | §10 industry content seeding |
| " | 18 795–19 650 | §11 business rules BR-REC-001…042, BR-CMP-019…030, BR-RPT-001…015 |
| " | 19 650–20 086 | §12 security findings, §13 defects & debt, §14 open questions |
| " | 25 137–25 366 | §9 application configuration and settings |
| " | 25 366–25 615 | §10 dashboards |
| " | 25 615–25 722 | §11 screen help system |
| " | 2 990–3 010 | §3.8 master-data CRUD around the employee |
| `BUSINESS_RULES_CATALOGUE.md` | 13 287–13 330, 13 353–13 655, 7 810–7 830, 8 415–8 432, 11 340–11 365 | BR-CFG-201/202, BR-CMP-007/709, BR-SEC-401/402, and the master index rows for BR-REC-*, BR-RPT-*, BR-CMP-* |
| `SECURITY_AND_TECHNICAL_DEBT_REPORT.md` | 1 497–1 507, 4 151, 6 072 | master-payload key exposure |

Excluded by instruction: lines 17 301–17 902 (§5 compliance module) — owned by another analyst. Compliance is referenced here only where this area *writes into* or *reads from* it.

---

## 1. Business capability inventory

### 1.1 Manpower requisition and approval

An HR user raises a **requisition** (`job_openings`) for a role: title, department, company, location, number of openings, priority, CTC band, target date, who asked for it, a written justification, and whether the hire is **on-roll** (a payroll employee) or **off-roll** (a commission-only field agent). It is created with `approval_status='pending'` and an auto-generated code `REQnnnn` (`RecruitmentController.php:1190-1204`). An **admin** — not an HR manager — approves or rejects it (`:1213-1255`). Only an approved, open, not-yet-filled requisition is flagged *recruitable* on the board (`:260`).

Reality check: the recruitable flag is **computed for display only and enforced nowhere**. `saveCandidate`, `assignToReq` and `assignBulk` all attach candidates to any requisition, approved or not (`:363-374`, `:1094-1106`, `:1128-1135`). Everything except `approval_status` stays editable after approval — including `openings` and the CTC band — so the approval no longer describes what was approved (`:1184-1189`).

### 1.2 Candidate pipeline (ATS)

Candidates live in one table (`recruitment`) that serves two purposes at once, separated by a `pool` flag:

- **Talent pool** (`pool = 1`, `stage = NULL`) — a searchable bank of imported CVs, filterable by keyword, location, minimum experience and maximum CTC (`:1042-1082`), capped at 500 rows returned with the true total reported alongside.
- **Pipeline** (`pool <> 1`) — cards on a six-column board: `applied → screening → interview → offer → hired → rejected` (`:31`), capped at 2 000 candidates with no truncation warning (`:231`).

Each candidate carries a profile (name, mobile, email, location, years of experience, current employer/designation/CTC, expected CTC, skills, résumé URL, source, rating 0–5, notes) and an offer block (offered CTC, incentive, joining bonus, joining date, offer notes, offer status, rejection reason).

### 1.3 Interviews

Per candidate, any number of rounds (`interviews`): round number and label, scheduled datetime, mode (`phone|video|in_person|online_test`), panel (a free-text string, not people), status (`scheduled|completed|cancelled|no_show`), a 0–10 score, a recommendation (`strong_hire|hire|hold|no_hire|strong_no_hire`) and written feedback (`:465-508`). The board aggregates per candidate: rounds held, rounds completed, the last completed round's recommendation, and the mean score of completed rounds (`:200-225`).

**Nothing in the codebase reads `interviews.score` or `interviews.recommendation` other than to display it** (BR-REC-020). A `strong_no_hire` candidate can be moved to *offer* and hired with no warning.

### 1.4 Bulk candidate acquisition

Three distinct import surfaces exist, with three different behaviours:

| Path | Endpoint | Dedupe | Fields read | Landing place |
|---|---|---|---|---|
| CSV upload | `POST /app/recruitment/import` | **none** | 10 (`name, stage, company, job\|requisition, position, source, mobile, email, rating, notes`) | pipeline, `stage='applied'` |
| Browser-parsed rows (xlsx/csv parsed client-side) | `POST /app/recruitment/import-rows` | email **or** full-digit mobile, against the whole tenant | 14, via ~90 portal header aliases | talent pool by default; a requisition pipeline if `job_id` supplied |
| Generic master import | `POST /app/master/recruitment/import` | upsert by `emp_map` FK only (none declared for `recruitment`) → always inserts | the 8 `recruitment` master fields | pipeline |

The **downloadable template does not match the importer it is for**: `template()` (`:795-809`) advertises `Location, Experience (Years), Current Company, Current Designation, Current CTC, Expected CTC, Skills, Resume URL` — eight columns `importCandidates()` never reads — and omits the two it does understand (`company`, `job`).

### 1.5 Hiring drives

A drive (`hiring_drives`) is a planned recruitment *event*: walk-in, scheduled-slot, or telephonic. It holds the position, a date range, a time window (stored as free-text strings, not TIME columns), venue, a Google-Maps link (unvalidated), a coordinator's name and phone, joining instructions, an interview **panel** as a JSON array of `{name, designation, phone, round}`, and for scheduled drives a JSON array of `{time, capacity}` slots.

Its roster (`hiring_drive_candidates`) tracks a five-stage funnel — **invited → will-attend → attended → interviewed (panel score) → selected**. Marking a roster row `selected` pushes the linked ATS candidate to the *Offer* column (`HiringDriveController.php:319-325`); there is no reverse transition.

### 1.6 Bulk WhatsApp recruitment campaigns

Instead of phoning 400 portal leads, a recruiter selects an audience (a requisition's whole pipeline, an explicit shortlist, or fresh CSV rows), picks a pre-approved WhatsApp template, fills the shared variables once, and fires. Each send becomes a **campaign** (`recruitment_campaigns`); each recipient a tracked **message** (`recruitment_messages`) with a delivery status and provider error. The recruiter then maintains a **response funnel** per recipient — interested / not interested / will attend / attended / hired / declined — which mirrors onto the candidate as `contact_status`.

WhatsApp only, through Interakt (`WaService::sendTemplate`). Variable contract: `{{1}}` is always the candidate name; `{{2}}…` are the campaign's shared values in order. There is **no arity check against the chosen template**, **no opt-out or suppression list of any kind**, and **no queue** — 2 000 recipients means 2 000 blocking HTTP calls inside one web request.

### 1.7 Offer letters and the public acceptance link

HR creates an offer letter row on the *Offer Letters* screen, picks the candidate and a saved offer template, previews the merged PDF, and emails the candidate a **tokenised public link**. The candidate opens `/offer/{token}` with no login, sees a summary card, and presses *I Accept This Offer*. The system stamps the letter `status='accepted'`, `accepted_at`, `accepted_ip` and moves the candidate to the *Offer* stage. **No employee is created by the public click** — HR confirms the hire separately (`OfferAcceptController.php:74-76`, code matches).

### 1.8 NDA / confidentiality e-signature

The same machinery for `letter_type='nda'`, addressed to an **employee** rather than a candidate. `/nda/{token}` renders the merged undertaking, asks the person to type their full name and tick a consent box, and stamps `status='signed'`, `accepted_at`, `accepted_ip`, `signed_name`. That signature is the **only** thing that satisfies the NDA component of the compliance score.

**This capability is unreachable from the product.** `AppController` registers a complete `letters-nda` screen (nav id `:667`, icon `:1381`, title "NDA / Confidentiality" `:3251`, full field set with template picker, Preview / Email / Send-for-e-signature actions `:10850`), but `MasterController::def()` has no `letters-nda` entry (`:356-389`). `GET /app/master/letters-nda` returns `{"rows":[],"error":"Unknown master"}`; `POST` returns `422 "Unknown master"`. NDA rows can only be created by direct SQL.

### 1.9 Letter generation

One `letters` table holds **templates** (`is_template=1`, a reusable body with `{{placeholders}}`, owned by a `letter_type`) and **issued letters** (`is_template=0`, one row per person). An issued letter has **no body of its own** — the PDF is produced on demand by merging the template body with live data. It can be previewed inline, emailed as a PDF attachment through the letter's company's own SMTP, or turned into a public token link.

Also produced by this controller: the **employee ID card** PDF (photo and company logo embedded as base64 data URIs because dompdf cannot reliably load paths), employee photo upload/serve (admin and self-service variants).

### 1.10 Employee document tracker

Upload, list, download and delete employee document files against six categories, stored on the `public` disk under `employee-docs/` with Laravel-hashed filenames and served only through a controller route. 10 MB per file; a per-employee cumulative quota from `tenants.doc_storage_emp_mb` (default 100 MB; a NULL tenant — an on-prem install — is unlimited).

### 1.11 Performance reviews

A three-hand relay: **self-review** (rating, comments, goals) → **manager review** (rating, comments) → **finalise** (one rating of record). Review rows are created on the `performance` *master* screen, not by this controller; `PerformanceController` only advances them.

Both the board and the advance endpoint are guarded `admin | hr_manager | super_admin`, so **the employee cannot perform their own self-review** — HR types it in on their behalf. There is no ordering enforcement, no rating scale, no weighting, no period arithmetic, no goal structure beyond a free-text field, and **nothing anywhere consumes `final_rating`** — not payroll, not increments, not incentives, not compliance.

### 1.12 Points (gamification)

Rules (`point_rules`) assign a positive or negative point value to an "event" named in free text. Once a month an admin presses *Auto-apply* for a month; SmartPRS reads that month's attendance, awards and test results and writes point entries into `points_ledger`. The leaderboard ranks agents by net points; the incentive engine can use the month's points as an **eligibility filter only**.

Points never become money (BR-REC-038). The only two consumers are `ComputedController` `points-scores` (the leaderboard) and `IncentiveController`'s bulk-commit gate.

### 1.13 Knowledge base

An in-app help library: articles grouped by category (each with a Font Awesome icon) and tagged with the roles that should see them. A reader sees articles tagged `all` or tagged with their **first** role. `super_admin` and `admin` can create, edit, delete and reorder. Seeded on first use with 22 built-in articles across 8 categories, plus 16 industry articles from the seeder.

**KB content is not tenant-scoped anywhere.** Every article is written with `tenant_id = NULL`; `index()`, `update()`, `destroy()` and `reorder()` all query by id with no tenant filter. An admin of tenant A editing an article changes it for every tenant on the installation — including the four *Code of Conduct* articles that agents legally acknowledge.

### 1.14 Reports

One report builder with **fourteen fixed datasets**. The user picks a dataset, optionally a month and a company, sees the first 100 rows plus the true total, and downloads the whole thing as CSV. Preview and export share one builder so the file always matches the screen.

Datasets range from a plain employee list to the statutory registers a labour inspector asks for (`Form B` wage register, `Form D` muster roll, `Form E` deduction register), the bank NEFT advice, the full-and-final settlement working, the RBI-facing compliance scorecard, an ex-employee access audit, and a hash-chain-verified audit trail export.

### 1.15 Master data

**One generic CRUD engine over 58 declared master types spanning 54 distinct tables** (`MasterController::def()`, `:30-493`). Every screen in the product that is "a list you add rows to" is one of these — organisation structure (companies, departments, designations, branches, banks, teams), HR operations (holidays, leave types, shifts, roster, onboarding board, awards, performance, notices), field-force compliance (BGV, DRA certificates, agent authorisations, off-roll agents, geofence rules, POSH complaints, helpdesk, complaints, code-of-conduct acknowledgements), payroll configuration (salary components, late policy, pay cycle, salary schedules, incentive schemes, deductions, overtime, minimum wages, payout reconciliation, TDS returns), learning (tests, test results, training programmes, training content, training records, FAQs), letters (four issue screens plus the template library), channel configuration (WhatsApp settings, SMS settings, SMS templates, biometric devices), a manual attendance punch, feature flags, and the candidate list itself.

Per master the definition declares: the table, the column list with types, the field list the screen shows, a label, a sort order, required fields, an employee-name→FK map, a generic name→FK map, constant columns the screen pins, and insert-time defaults. **The definition is a PHP array literal.**

### 1.16 Settings and configuration

Five parallel stores (see §5 and the reference doc §9.2):

- **`statutory_settings`** — 36 keys of statutory rates and payroll/attendance policy as one JSON blob per tenant, edited on the Settings screen.
- **`app_settings`** — per-company SMTP, per-company branding (display name, colour, logo, tagline), plus greetings / probation / absence-notification / demo-gate configuration owned by other areas.
- **`settings`** — the SPA's entire working data set (`app_state`), the active financial year, the marketing landing-page CMS content, and on-prem licence material.
- **`statutory_configs`** — effective-dated, scoped statutory overrides (the only settings surface that is role-gated on write *and* audited *and* effective-dated).
- **`config/` + `.env`** — deployment-level configuration, edition, licence, timezone, session, permission cache.

Plus a large amount of what a Product Owner would call configuration that is actually **master data rows** (late policy, shifts, salary components, leave types, holidays, pay cycles, points rules, letter templates, feature flags) — see §5.

### 1.17 Dashboards

Two exist; one runs. `DashboardController::stats()` (`GET /app/dashboard/stats`) returns six cards, chosen by scope: a **platform** set for a super admin with no tenant (tenants active/total, MRR, invoices due, outstanding, collected this month, plan count), and a **company** set for everyone else (active employees, present today, pending approvals, compliance flags within 30 days, open helpdesk tickets, latest payroll net). An `employee`-role user gets the same six cards narrowed to themselves. Active notices are returned alongside.

`DashboardController::index()` + `resources/views/dashboard.blade.php` is **dead code** — no route reaches it, its layout calls ten route names that are not registered, and it reads the legacy employee schema (`first_name`, `last_name`, `employee_code`, `gross_salary`) which no longer exists.

### 1.18 Screen help

Every screen has an ⓘ that fetches `GET /app/help/{screen}` and renders a panel: what the screen is for, features, steps, a "good to know" tip, the roles that use it, a "why it matters" business argument, and a "do it right" list of common mistakes with their consequences and the correct action.

**Volume, measured:** 2 206 lines; one public method and 25 private static array-returning methods; **120 unique screen keys with help content**, **119 with "why it matters" copy**, **73 with "common mistakes"**. No `DB::`, `Auth::`, `Schema::`, `request()`, `hasRole`, `env(` or `config(` call anywhere in the file — it is pure content plus one dispatcher.

### 1.19 Industry content seeding

`php artisan content:seed-industry` (and `SeedIndustryContent::seedForTenant()` called from the installer, SaaS provisioning, on-prem client provisioning and demo reset) pre-loads a complete India-specific Collections & Recovery content pack:

| Table | Rows | Scope | Skip-if-exists key |
|---|---|---|---|
| `training_programs` | 12 | per tenant | `name` |
| `training_subjects` | 25 | per tenant | `(program, subject)` |
| `faqs` | 17 | per tenant | `question` |
| `kb_topics` | 16 | **global, `tenant_id = NULL`** | `title`, **across the whole installation** |
| `letters` (offer template) | 1 | per tenant | any offer template exists |

Everything is inserted as ordinary editable rows. The four `Code of Conduct` KB articles are the exact content agents legally acknowledge.

### 1.20 Public marketing surface (adjacent)

`LandingController` — the marketing site, its content a JSON snapshot in `settings.landing` editable by a super admin at `/admin/landing`, falling back to `defaults()` in code. `LeadController` — the public demo-request form writing to a self-created `leads` table with fail-soft email + WhatsApp alerts to CMS-configured recipients. `PolicyController` — ten static legal pages, slug→blade, content hard-coded in blades. `AppIconController` — the PWA manifest and favicon, generated at request time from the logged-in tenant's company logo composited onto a white square.

---

## 2. Data model

### 2.1 Provenance summary

Of the 62 tables this area touches, **28 have no migration at all** and are created by `Schema::create()` inside an HTTP request handler or a console command. There are **71 `Schema::create()` calls under `app/`**.

The migration that does exist for the letter/training/comms family (`2026_01_01_000600_create_salary_runs_table.php`) is itself defensive: it wraps every create in a private `mk()` helper that skips existing tables, because a client install died with `SQLSTATE[42S01] Table 'training_programs' already exists` on 28 Aug 2026 — MySQL DDL is not transactional, so a part-applied migration was unrecoverable (`:10-25`).

### 2.2 Tables — provenance and shape

| Table | Provenance | Notes |
|---|---|---|
| `recruitment` | **RUNTIME** — `RecruitmentController::ensure()` `:93-114` | base `id, tenant_id, timestamps`; 30 further columns added by `addCols()` in four documented waves (rev 60/62/63), plus `last_contacted_at` and `contact_status` added **inside the WhatsApp send loop** by `RecruitMessagingController::stampCandidate()` `:240-246` |
| `job_openings` | **RUNTIME** — `RecruitmentController::ensure()` `:116-138` | 12 further columns added by `addCols()` |
| `interviews` | **RUNTIME** — `:140-156` | |
| `hiring_drives` | **RUNTIME** — `HiringDriveController::ensure()` `:30-54` | `time_from`/`time_to` are `string(20)`, not TIME; `panel_json`/`slots_json` are text |
| `hiring_drive_candidates` | **RUNTIME** — `:55-72` | |
| `recruitment_campaigns` | **RUNTIME** — `RecruitMessagingController::ensure()` `:36-51` | `drive_id` added later by `HiringDriveController::ensure()` `:74-79`, inside a swallowed catch |
| `recruitment_messages` | **RUNTIME** — `:52-69` | `wa_message_id` indexed for webhook matching — **and never written** |
| `letters` | migration `2026_01_01_000600_…:112-121` | Creates only `id, tenant_id, company_id, employee_id, letter_type, issued_on, status, pdf_path, timestamps`. **Every functional column is added at runtime**: `title, body, is_template, candidate, employee, template, template_id, company_name` by `MasterController::ensureCols()`; `accept_token, accepted_at, accepted_ip, signed_name` by `LetterController::sendAcceptLink()` `:395-401`, `OfferAcceptController::ensureCols()` and `NdaSignController::ensureCols()`; the whole table re-created if absent by `SeedIndustryContent::ensureLettersTable()` `:132-172`. `employee_id` is created **NOT NULL**, which is why migration `2026_06_02_120000_make_letters_employee_nullable.php` exists. `pdf_path` is **never written or read by any code** — a column for an issued-PDF store that was never built |
| `documents` | migration `2026_01_01_000600_…:124-133` | `category, doc_name, file_name, file_size` added at runtime by `DocumentController::ensure()` `:88-98`; `tenants.doc_storage_emp_mb` also added there `:96-98` |
| `performance_reviews` | **RUNTIME** — `PerformanceController::ensureCols()` `:22-53` | six `EXTRA_COLS` added on demand; also declared as a master (`MasterController.php:415-420`) with a *different, smaller* column set |
| `point_rules`, `points_ledger` | migration `2026_01_01_000600_…` | `points_ledger.source_ref` is queried with `LIKE 'auto:%'` and **is not indexed** |
| `kb_topics` | migration `2026_01_01_000900` **plus** two runtime creators with identical shape (`KbController::ensureTable()` `:24-41`, `SeedIndustryContent::ensureTables()` `:213-225`) | `tenant_id` column exists and is **always written NULL** |
| `app_settings` | **RUNTIME** — `ConfigController::ensureTable()` `:25-38` | `unique(tenant_id, ckey)`; `value` longText JSON |
| `statutory_settings` | **RUNTIME** — `SettingsController::ensureTable()` `:77-88` | one row per tenant, whole config as one JSON blob; **no unique index on `tenant_id`** |
| `settings` | migration `2026_01_01_000700_create_salary_slips_table.php:25-33` | the settings table is created inside the salary-slips migration |
| `leads` | **RUNTIME** — `LeadController::ensureLeads()` `:25-45` | no tenant column at all — platform-scope by construction |
| `offroll_agents` | migration `2026_01_01_000500_create_loans_table.php:13-26` **and** runtime `RecruitmentController::createOffrollFromCandidate()` `:651-666` | **The two shapes differ.** Migration: `company_id` NOT NULL, no `company_name`. Runtime: `company_name`, no `company_id`. The `MasterController` def declares `company_name` |
| `posh_complaints`, `dra_certs`, `min_wages`, `policies`, `overtime`, `roster`, `onboarding`, `awards`, `notices`, `feature_flags`, `pay_cycles`, `helpdesk` (partial) | **RUNTIME** — `MasterController::ensureCols()` `:496-578` | created as `id, tenant_id, timestamps` then columns bolted on from the def |
| `companies, departments, designations, branches, banks, teams` | migration `2026_01_01_000100_create_employees_table.php` (`mk()` helper `:35-105`) | `departments` already has `head_employee_id`; the master screen instead auto-creates a plain string `head` column and **never populates the FK** |
| `devices, holidays, leave_types, geofence_rules, late_policy` | migration `2026_01_01_000200_create_departments_table.php` | |
| `deductions, payout_recon, salary_schedules, salary_components, incentive_schemes` | migration `2026_01_01_000300_create_attendances_table.php` | |
| `tds_returns` | migration `2026_01_01_000400` | |
| `assets, complaints, bgv, escalations, agent_authorizations` | migration `2026_01_01_000500` | |
| `tests, test_attempts, training_programs, training_records, training_subjects, faqs, code_of_conduct_ack, messages_log, wa_settings, sms_settings, sms_templates` | migration `2026_01_01_000600` | `training_programs.description`, `faqs.answer` widened to `text` and `training_subjects.content` widened to `text` at runtime by `SeedIndustryContent::ensureTables()` `:257-271` |
| `attendance_logs` | migration `2026_06_24_000000_ensure_runtime_feature_tables.php:30-47` | written by the `att-manual` master |
| `shifts` | migration `2026_07_07_000000_ensure_shifts_table.php` | |
| `activity_logs` | migration `2026_05_30_025626` (Spatie) | written both through `App\Services\Audit::record()` (hash-chained) *and* by two direct inserts in this area that bypass it |

### 2.3 Runtime DDL on the hot path — the critical structural finding

DDL is executed inside request handlers, repeatedly:

| Location | What runs | Frequency |
|---|---|---|
| `MasterController::ensureCols()` `:496-578` | `Schema::hasTable` + up to N × `Schema::hasColumn`, then `Schema::table(...)->ALTER`; on MySQL also a `SHOW COLUMNS` for `wa_settings` and an `information_schema` probe per `coord` column | **every** `list()`, `save()` and `import()` call |
| `RecruitMessagingController::stampCandidate()` `:240-246` | two `Schema::hasColumn` checks, `ALTER TABLE recruitment` if missing | **once per WhatsApp recipient**, inside the send loop — up to 2 000 times per request |
| `LetterController::sendAcceptLink()` `:395-401` | four `Schema::hasColumn` checks + `ALTER TABLE letters` | every accept-link send |
| `OfferAcceptController::ensureCols()` `:18-30`, `NdaSignController::ensureCols()` `:19-31` | three/four `hasColumn` + `ALTER` | **on every public, unauthenticated token page hit** |
| `RecruitMessagingController::setResponse()` `:341-343` | `hasColumn` + `ALTER TABLE recruitment` | every funnel update |
| `LetterController::uploadPhoto()` `:338-342`, `uploadMyPhoto()` `:470-483` | `ALTER TABLE employees` / `users` | every photo upload |
| `DocumentController::ensure()` `:74-99` | up to 5 `hasColumn` + `ALTER`, including `ALTER TABLE tenants` | every document-manager request |
| `SeedIndustryContent::ensureTables()` `:175-271` | 4 creates, ~30 `hasColumn`+`ALTER`, 2 forced `->change()` | every provisioning call |

Consequences: the application requires DDL privileges on the production database at runtime; two concurrent requests can race the same `ALTER`; on MySQL each `ALTER` is a table rebuild that is not transactional; and the effective schema of any given install is a function of which screens have been opened, in what order, since the install.

### 2.4 Missing indexes on columns this area filters on

`letters.accept_token` (**queried on every public token page load**), `letters.employee_id`, `letters.is_template`, `letters.letter_type`, `recruitment.job_id`, `recruitment.stage`, `recruitment.pool`, `recruitment.email`, `recruitment.mobile`, `points_ledger.source_ref` (used with `LIKE`), `points_ledger.employee_id`, `dra_certs.employee_id`, `dra_certs.expiry`, `bgv.next_due`, and every column added by `MasterController::ensureCols()` (all nullable, all unindexed — `:552-577`).

### 2.5 Column-type policy in the master engine

`ensureCols()` maps the def's declared type to a column (`:555-577`):

| Def type | Column created |
|---|---|
| `string` (and anything unrecognised) | `VARCHAR(255) NULL` |
| `text` | `LONGTEXT NULL` |
| `date` | `DATE NULL` |
| `datetime` | `DATETIME NULL` |
| `decimal` | `DECIMAL(12,2) NULL` |
| `coord` | `DECIMAL(11,7) NULL` (~1 cm) |
| `int` | `INTEGER NULL` |
| `bool` | `BOOLEAN DEFAULT 0` |
| `company` | `BIGINT UNSIGNED NULL` named `company_id` |

There is a self-repair pass for `coord` columns an earlier version created as `DECIMAL(12,2)` (which truncated coordinates to ~1 km): on MySQL it reads `information_schema.COLUMNS.NUMERIC_SCALE` and re-`ALTER`s to `DECIMAL(11,7)` if the scale is not 7 (`:536-551`). SQLite (the test database) skips it.

**Every decimal in every master is `DECIMAL(12,2)`** — a hard ceiling of 9 999 999 999.99 and exactly two decimal places, applied uniformly to a salary component value, an overtime amount, a minimum wage, a geofence radius in km, and an incentive rate.

---

## 3. Business rules register

`HC?` = hard-coded. `Want different?` = could a new customer reasonably ask us to change this. Rules prefixed `NEW-` were not in the reference catalogue.

### 3.1 Recruitment — requisitions and pipeline

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HC? | Configurable today? | Want different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-REC-001 | A new requisition starts pending approval | title, dept, company, location, openings, priority, CTC band, target date, justification, engagement | create path (no `id`) | force `approval_status='pending'`, `status='open'`, `filled=0`; mint `req_code` | tenant | `RecruitmentController.php:1190-1204` | Y | No | **Y** — some customers auto-approve, some need 2–3 levels |
| BR-REC-002 | Only `admin` may approve or reject a requisition; `hr_manager` is refused | role | always | approve → `approved`, `approved_by = user name string`, `approved_at`; reject → `rejected` + `status='closed'` | tenant | `:47-50`, `:1213-1255` | Y | No | **Y** — approver should be role- or hierarchy-driven, and multi-step |
| BR-REC-003 | An edit never changes `approval_status` | requisition edit | update path | omit `approval_status` from the update row | tenant | `:1184-1189` | Y | No | **Y** — a material change (openings, CTC) should re-open approval |
| BR-REC-004 | A requisition is recruitable only when approved, open and unfilled | `approval_status`, `status`, `filled`, `openings` | board read | compute `recruitable` for display | tenant | `:260` | Y | No | Y — and it must actually be **enforced** |
| BR-REC-005 | A requisition with an unrecognised `approval_status` is treated as approved | `approval_status` | value not in the 4-value list | read as `approved` | tenant | `:160-165` | Y | No | **Y** — NULL/typo should not grant approval |
| BR-REC-006 | Candidate stage transitions are unrestricted — any stage to any stage | target stage | validated against the 6-value list only | write `stage`; write `reject_reason` only when target is `rejected` **and** a reason was given | tenant | `:396-419` | Y | No | **Y** — every customer has a different pipeline and different legal transitions |
| NEW-REC-101 | The pipeline is a fixed six-stage list | — | always | `applied, screening, interview, offer, hired, rejected` | product | `:31` `private const STAGES` | **Y** | **No** | **Y — the single most-requested ATS change** |
| NEW-REC-102 | Interview modes, statuses and recommendations are fixed lists | — | always | `phone\|video\|in_person\|online_test`; `scheduled\|completed\|cancelled\|no_show`; `strong_hire\|hire\|hold\|no_hire\|strong_no_hire` | product | `:35-39` | **Y** | **No** | **Y** |
| NEW-REC-103 | Requisition priorities are a fixed list | — | always | `low, normal, high, urgent` | product | `:301` | **Y** | No | Y |
| NEW-REC-104 | Candidate fields are a fixed set of ~30 columns; there are no custom fields | — | always | `addCols()` declares them | product | `:100-114` | **Y** | **No** | **Y** |
| BR-REC-007 | Candidate free text is HTML-stripped on `saveCandidate` only | all validated input | main save path | `stripHtmlDeep()` = `strip_tags` + strip `<`/`>` | tenant | `:352`; **not** in `patchCandidate` `:691-724` or `importRows` `:918-1038` | Y | No | N (should be output-escaping instead) |
| BR-REC-008 | A candidate linked to a requisition inherits its title and company when blank | `job_id` | own field blank | `position ← job.title`; `company_id ← job.company_id` | tenant | `:363-374`, `:1099-1105`; **not** in `assignBulk` `:1132-1135` | Y | No | N |
| BR-REC-009 | Portal imports land in the talent pool unless a requisition is named | `pool`, `job_id` | `job_id` truthy → pipeline | `pool=1, stage=NULL` or `pool=0, stage='applied'` | tenant | `:932-942`, `:1006-1008` | Y | No | N |
| BR-REC-010 | Portal imports de-duplicate on email **or** full-digit mobile | existing candidate emails + mobiles, pre-loaded whole | either key matches | skip the row, count it as a duplicate | tenant | `:946-991` | Y | No | **Y** — dedupe key and merge-vs-skip policy are customer choices |
| BR-REC-011 | The CSV importer reads 10 columns and silently discards the rest; no dedupe | CSV | `name` header mandatory | per-row INSERT | tenant | `:812-878` | Y | No | Y |
| BR-REC-012 | Hiring is idempotent **by employee name** | `employees.name`, tenant | a live namesake exists | mark candidate hired, reconcile fill, ensure onboarding, return the existing code, create nothing | tenant | `:588-596` | Y | No | **Y** — must key on a real identity (PAN/UAN/email/mobile) |
| BR-REC-013 | Hiring consumes a subscription seat | seat usage | on-roll only, after the name check | `SubscriptionService::canAddEmployees($tid,1)`; failure → 422 | tenant | `:598-605` | Y | No | N (commercial) |
| BR-REC-014 | Employee code is `EMP` + zero-padded (count+1), incremented on collision | employee count | on-roll hire | allocate `EMPnnnn` | tenant | `:619-624` | **Y** | **No** | **Y — every customer has their own code scheme (prefix, branch code, year, width, sequence source)** |
| BR-REC-015 | Hiring auto-creates a missing designation master from the candidate's free-text position | `candidate.position` | no `designation_id` supplied and no name match | `INSERT designations` | tenant | `:609-617` | Y | No | **Y** — pollutes the master with near-duplicates |
| BR-REC-016 | Off-roll engagement creates a commission agent, not an employee | engagement, vendor, payout_type, rate, dra, pcc | `engagement='off_roll'` | insert `offroll_agents`; **no seat check, no onboarding, no compliance record** | tenant | `:557-562`, `:648-688` | Y | No | **Y** |
| BR-REC-017 | Requisition fill is recomputed after a hire and the requisition auto-closes when full | hired count, openings | after any hire | `filled = COUNT(hired for this job)`; close when `filled >= openings` | tenant | `:772-792`, **swallowed catch** `:789-791` | Y | No | Y |
| BR-REC-018 | An onboarding record is opened for every on-roll hire, keyed by name | candidate name | `onboarding` table exists and no namesake row | insert `stage='Documents'`, `status='in_progress'` | tenant | `:750-769` | Y | No | **Y** — the onboarding checklist is customer-specific |
| BR-REC-019 | Board interview aggregation | all `interviews` rows in the tenant | board read | count, done, last completed recommendation, mean score of completed rounds | tenant | `:200-225` | Y | No | N |
| BR-REC-020 | Interview scores and recommendations gate nothing | — | always | display only | product | grep: only readers are `board()` and `interviews()` | Y | No | **Y** — a `no_hire` should at minimum warn |
| BR-REC-021 | The board is capped at 2 000 candidates and the pool at 500 rows | — | always | silent truncation on the board; `shown` vs `total` reported on the pool | product | `:231`, `:1073`, `:290` | **Y** | No | Y |

### 3.2 Hiring drives

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HC? | Configurable today? | Want different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-REC-022 | A drive tracks a five-stage funnel | roster rows | list read | `invited / willAttend / attended / interviewed / selected` via one grouped `selectRaw` | tenant | `HiringDriveController.php:95-103` | Y | No | Y |
| BR-REC-023 | Marking a roster row `selected` moves the linked candidate to *Offer* | `outcome`, `candidate_id` | incoming update sets `selected` and a `candidate_id` exists | `UPDATE recruitment SET stage='offer'` — **not tenant-scoped**, swallowed catch | tenant | `:319-325` | Y | No | Y (and no reverse transition exists) |
| BR-REC-024 | Campaign recipients are auto-rostered onto the drive | valid recipients | send carried a `drive_id` | upsert roster rows, dedupe on `candidate_id` else `(mobile, candidate_id IS NULL)` | tenant | `RecruitMessagingController.php:171-177`, `HiringDriveController.php:334-354` | Y | No | N |
| BR-REC-025 | Default drive instructions | — | `instructions` blank | *"Please carry an updated resume, original photo ID proof and a pen. Formal dress."* | product | `HiringDriveController.php:82`, `:183` | **Y** | **No** | **Y — English-only, India-walk-in-specific, not tenant-editable** |
| NEW-REC-105 | Drive types and statuses are fixed lists | — | always | `walkin\|scheduled\|telephonic`; `planned\|open\|completed\|cancelled` | product | `:137-138` | Y | No | Y |
| NEW-REC-106 | Roster `score`, `recommendation` and `outcome` are written with **no validation** | request body | field present | write as supplied; `score` cast to int with no 0–10 clamp | tenant | `:302-313` | Y | No | Y — conflicts with `saveInterview` which does validate 0–10 |

### 3.3 Recruitment messaging

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HC? | Configurable today? | Want different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-REC-026 | A campaign is capped at 2 000 valid recipients | recipient count | `> 2000` | 422 | product | `RecruitMessagingController.php:146-148` | **Y** | No | Y |
| BR-REC-027 | WhatsApp must be configured before any campaign | `WaService::config()` | falsy | 422 naming the Interakt API key | product | `:137-139` | Y | Provider config is a master (`wa-settings`) | Y — provider is hard-wired to Interakt |
| BR-REC-028 | Variable contract: `{{1}}` is always the candidate name, `{{2}}…` the shared values in order | `vars[]` | always | `bodyValues = [name, ...vars]` | product | `:22-26`, `:150`, `:182-183` | **Y** | No | **Y** — no arity check against the template |
| BR-REC-029 | Mobile normalisation takes the **last 10 digits**; shorter is invalid | mobile | `< 10` digits | row kept, flagged `No valid mobile`, written as `failed` | product | `:78-91`, `:209-220` | **Y** | **No** | **Y — hard-wires India-only 10-digit numbers; a country code is silently discarded** |
| BR-REC-030 | Campaign totals are rewritten after the send | sent, failed | after the loop | `total = sent + failed`, overwriting the pre-send valid-only total | tenant | `:151-158`, `:221-223` | Y | No | N (defect) |
| BR-REC-031 | Contacting a candidate stamps `last_contacted_at` and `contact_status` | send result | a `candidate_id` resolved | `messaged` / `message_failed` | tenant | `:238-252` | Y | No | N |
| BR-REC-032 | The recruiter funnel value mirrors onto the candidate's `contact_status` | funnel value | a `candidate_id` exists | overwrite `contact_status` | tenant | `:320-350` | Y | No | Y — the column then holds two mixed vocabularies |
| BR-REC-033 | There is no candidate opt-out, suppression list or STOP handling | — | always | none | product | full read; grep for `opt`/`unsubscribe`/`do_not` returns nothing | **Y** | **No** | **Y — this is a consent/DPDP obligation, not a preference** |
| BR-REC-034 | Delivery status never downgrades | webhook status | `rank(new) >= rank(stored)` | update | installation-wide | `:406-409` | Y | No | Y — `sent` and `failed` share rank 1, so a late `failed` overwrites a `sent` |
| NEW-REC-107 | The Interakt webhook determines status by string-searching the whole JSON payload | raw payload | payload contains `"read"`/`"delivered"`/`"sent"`/`"failed"` | last match wins | installation-wide | `:387-392` | Y | No | Y — a customer's own message text containing the word "read" is read as a read receipt |

### 3.4 Offer acceptance and NDA signature

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HC? | Configurable today? | Want different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-CMP-022 | Token generation | — | send accept link | `Str::random(40)` (~238 bits) **reused if one already exists** | letter row | `LetterController.php:402` | Y | No | **Y — no expiry, no rotation, no revocation, no single-use** |
| BR-CMP-020 | Public offer acceptance | token | letter is `is_template=0` and status ≠ `accepted` | `status='accepted'`, `accepted_at`, `accepted_ip`; candidate → `stage='offer'`, `offer_status='accepted'` **matched by name** | global token namespace | `OfferAcceptController.php:64-88` | Y | No | **Y** — evidence set, identity proof, and what the signer sees |
| BR-CMP-021 | Public NDA e-signature | token, typed name | letter is `is_template=0`, `letter_type='nda'`, status ≠ `signed` | `status='signed'`, `accepted_at`, `accepted_ip`, `signed_name` | global token namespace | `NdaSignController.php:85-102` | Y | No | **Y** |
| NEW-DOC-101 | The offer route accepts **any** letter type — there is no `letter_type` filter | token | any non-template letter with a token | renders and accepts it | global | `OfferAcceptController.php:36` | Y | No | Y (defect) |
| NEW-DOC-102 | The NDA sign endpoint performs **no server-side validation** of the name or the consent tick, and is CSRF-exempt | POST body | always | signs with `name` falling back to the existing value or NULL | global | `NdaSignController.php:96`; `routes/web.php:63-64` | Y | No | **Y** (defect + security) |
| NEW-DOC-103 | The public acceptance page shows only name, position, company and offer date — never the offer body, CTC, incentive, bonus or joining date | letter, candidate | always | render 4 fields | product | `resources/views/offer-accept.blade.php:38-44` | **Y** | **No** | **Y — this is what makes the "acceptance" evidentially worthless** |
| NEW-DOC-104 | The signed artefact stores a template **pointer**, never a snapshot | — | always | re-merge at every render | product | `LetterController::buildLetter()` `:53-68`; no `body` on issued rows | **Y** | **No** | **Y — editing a template silently rewrites what someone signed** |

### 3.5 Letters and documents

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HC? | Configurable today? | Want different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-CMP-023 | Template resolution: chosen template (tenant-scoped) → newest of that type in the tenant → NDA built-in default → 422 | `template_id`, `letter_type` | in order | pick a body | tenant | `LetterController.php:53-91` | Y | Templates are DB rows an admin edits | Y — `letters.status` (`active`/`inactive`) is **never consulted**, so a deactivated template is still used if newest |
| BR-CMP-024 | Merge is a single literal `strtr()` over 21 `{{placeholder}}` keys | letter + person + company + settings | always | substitute; **unknown placeholders print verbatim** | tenant | `:156-180` | Y (the key list is code) | The **body** is editable; the **key list is not** | **Y — every customer wants placeholders we do not have (probation period, notice period, working hours, reporting manager, work location, PF/ESI numbers, appraisal date…)** |
| BR-CMP-025 | HTML detection decides escaped vs unescaped PDF rendering | body | regex matches a block/inline tag | render `{!! !!}` else `{{ }}` with `pre-wrap` | product | `letter-pdf.blade.php:6`, `:43` | Y | No | Y |
| BR-CMP-026 | Emailing a letter rewrites the process's mail config to the letter's company SMTP and **does not restore it** | company SMTP | host + from_address present | `config([...])` then `Mail::send` with the PDF attached | request | `LetterController.php:229-252` | Y | SMTP is per-company config | Y (defect) |
| BR-CMP-027 | ID card generation | emp_code | employee exists, not soft-deleted | join company/designation/department; embed photo and logo as base64 data URIs; `stream()` or `download()` with `?dl=1` | tenant | `:261-327` | Y | Brand colour + logo only | **Y — card layout, fields, size, orientation, front/back, QR/barcode are all fixed in the blade** |
| BR-CMP-028 | Photo upload: `image` rule, 5 MB | file | valid image | store on the public disk with a hashed name | tenant | `:330-355`, `:454-490` | Y | No | Y |
| BR-REC-041 | Document upload rules: extension whitelist of 8, 10 MB per file, per-employee quota (default 100 MB; NULL tenant unlimited) | file, tenant quota | per file | store or skip with a reason | tenant | `DocumentController.php:35-53`, `:164-260` | Y | Quota is per-tenant, set by the **super admin** only | **Y — a tenant admin cannot set their own categories, limits or required-document list** |
| NEW-DOC-105 | The document category list is a 6-value constant and is **not enforced** | category | any string accepted | falls back to `'Other'` | product | `:21-28`, `:233` | **Y** | **No** | **Y** |
| BR-REC-042 | Any user with the `employee` role can download **any** document in the tenant | document id | role in `admin, hr_manager, employee` | fetch by `id` + `tenant_id` only — **no ownership check** | tenant | `:263-281` | Y | No | Y (security defect) |
| NEW-DOC-106 | The letter-type vocabulary is a 7-value dropdown but only 5 have issue screens | — | always | `offer, increment, warning, relieving, experience, nda, custom` in the template editor; issue screens exist for offer/increment/warning/relieving only, plus a registered-but-broken `letters-nda` | product | `AppController.php:10853`; `MasterController.php:356-389` | **Y** | **No** | **Y — a customer cannot add "Appointment Letter", "Confirmation Letter", "Transfer Letter", "Show Cause"…** |

### 3.6 Performance and points

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HC? | Configurable today? | Want different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-REC-035 | A review advances through three actions with no ordering enforcement | action, rating, comments, goals | `action` in `self,manager,finalize` | write the stage's fields and set `status`; `finalize` copies `final_rating` into the legacy `rating` | **none — `WHERE id = ?` with no tenant filter** | `PerformanceController.php:94-135` | Y | No | **Y — cycle definition, stages, participants, rating scale, weighting, competencies** |
| NEW-PRF-101 | `rating` is validated as `numeric` with **no minimum or maximum** | rating | always | store | — | `:102` | Y | No | **Y — there is no rating scale at all (1-5? 1-10? %?)** |
| NEW-PRF-102 | Nothing downstream consumes `final_rating` | — | always | none | product | grep across `app/` | Y | No | **Y — appraisal must drive increments, incentives and promotions** |
| BR-REC-036 | Points rules are bucketed by keyword in the rule's `event` text, first match wins | rule `event` | `str_contains` in strict order `late → absent → award\|reward → test\|pass\|exam\|assessment → perfect\|present\|attendance` | assign to a bucket | tenant | `PointsController.php:56-69` | **Y** | **No** | **Y — a rule named "Perfect attendance — no lates" is classified as a LATE PENALTY** |
| BR-REC-037 | Points auto-apply formulas (see §4.5) | attendance, awards, tests | per rule bucket | insert ledger rows | tenant | `:89-137` | Y | Point values are DB rows | **Y — the trigger events themselves are code** |
| BR-REC-038 | Points are an eligibility gate, never money | monthly points sum | `>= points_gate_min` (default 0 = off) | pay / list as skipped | tenant | `IncentiveController.php:199-212`, `SettingsController.php:59` | Y | The threshold is a setting | N |
| NEW-PTS-101 | The "late" cut-off used by points is a hard-coded 09:30 | first punch of the day | minute-of-day > 570 | count a late | product | `PointsController.php:25` — comment calls it a "gamification heuristic" | **Y** | **No** | **Y — the Shifts master, the Late Policy and the roster, all of which payroll honours, are ignored here** |
| NEW-PTS-102 | Points month arithmetic treats **only Sunday** as the weekly off | month, holidays | always | `workingDays = days − Sundays − holidays` (min 1) | tenant | `:239-264` | **Y** | **No** | **Y — the tenant's `weekly_off_day` and `sat_off_mode` settings are ignored, so a Saturday-off agency mis-counts absences every month** |
| NEW-PTS-103 | Auto-applied ledger rows are dated the **run date**, not a date inside the target month | `now()` | always | `date = today` | tenant | `:88`, `:230` | Y | No | Y — December's points applied in January are dated January and miss the incentive gate |
| NEW-PTS-104 | Auto-apply is idempotent by deleting then re-inserting, outside a transaction | month | re-run | `DELETE … WHERE source_ref LIKE 'auto:<month>%'` then chunked INSERT | tenant | `:76-80`, `:126-130` | Y | No | Y — a failure between the two loses the month |

### 3.7 Knowledge base

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HC? | Configurable today? | Want different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-REC-039 | Visibility follows the viewer's **first** role only | roles JSON | contains `all` or the viewer's first role | show | installation | `KbController.php:46`, `:52-60` | Y | Role tags are per-article | Y — a user with two roles sees only the first one's articles |
| BR-REC-040 | Only `super_admin`/`admin` may author; **KB is global and cross-tenant writable** | — | always | all four write operations query by `id` with no tenant filter; `store()` never sets `tenant_id` | **installation-wide** | `:19-22`, `:85-132`, `:147-154` | Y | No | **Y** (security defect) |
| NEW-KB-101 | Defaults are seeded only when `kb_topics` is completely empty | article count | `== 0` | insert 22 built-in articles across 8 categories | installation | `:157-174` | Y | No | Y |
| NEW-KB-102 | The role vocabulary is a 6-value constant | — | always | `all, super_admin, admin, hr_manager, field_agent, employee` | product | `:17` | Y | No | Y — a customer with custom roles cannot target them |
| NEW-KB-103 | The author role dropdown hides `super_admin` from non-super-admins, but `validateTopic()` accepts it from anyone | roles array | always | intersect with `ROLE_OPTIONS` | — | `:70-75` vs `:145` | Y | No | Y (minor defect) |

### 3.8 Reports

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HC? | Configurable today? | Want different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-RPT-001 | Preview and export share one builder | dataset, month, company, limit | preview `limit=100`, export `limit=null` | build `[columns, rows, count]` | tenant | `ReportController.php:58-417`, `:435`, `:470` | Y | No | N (good pattern) |
| NEW-RPT-101 | There are exactly 14 datasets, each a hand-written query with a hard-coded column list | — | always | `datasets()` returns the fixed register | product | `:22-40` | **Y** | **No** | **Y — a customer cannot add a report, add a column, remove a column, reorder columns, rename a header, add a filter, or save a view** |
| BR-RPT-002 | An unknown dataset silently falls back to `employees` | dataset key | not in the register | switch to `employees` | product | `:429-431`, `:464-466` | Y | No | Y |
| BR-RPT-003 | Month normalisation is permissive — a malformed month silently widens the report to all time | month | unparseable | `null` = no filter | product | `:42-52` | Y | No | Y |
| BR-RPT-004 | The minimum-wage check uses the **maximum** notified wage across the whole tenant | `min_wages.monthly_min` | always | `min = MAX(...)` | tenant | `:139` | **Y** | State/zone/category rows exist but are **ignored** | **Y — a Mumbai minimum is applied to a Nagpur agent** |
| BR-RPT-005 | Form B deduction bucketing is substring matching on the payslip's deduction JSON keys | `payslips.deductions` | key contains `provident`/`pf` → PF; `esi` → ESI; `professional` or `== 'pt'` → PT; `tds` → TDS | assign to a column; unmatched keys dropped | tenant | `:171-190` | **Y** | **No** | **Y — "Staff PF Loan" lands in PF; the four columns need not sum to `Total Deductions`** |
| BR-RPT-006 | Full-and-final assumes basic = 50 % of monthly gross | ctc, doj, last_day, advances, dues | always | see §4.4 | tenant | `:252-286` | **Y** | **No** | **Y — the payroll engine's own salary structure is ignored** |
| BR-RPT-007 | The bank advice payment mode is the literal string `NEFT` | — | always | write `'NEFT'` | product | `:247` | **Y** | **No** | **Y — no IMPS/RTGS/UPI, no amount-based mode, no per-bank file format** |
| BR-RPT-008 | Muster-roll present days = distinct punch dates, with **no direction filter** | attendance_logs | month | `COUNT(DISTINCT log_date)` grouped by `(emp_code, emp_name)` | tenant | `:199-214` | Y | No | Y — an out-only punch counts as present; zero-punch employees are absent from the register entirely |
| BR-RPT-009 | The ex-employee access audit joins `users` on **email**, unscoped by tenant | employees, users | `status='exited'` or soft-deleted | derive login status and risk | tenant on `employees` only | `:333-361` | Y | No | Y — an ex-employee whose email exists in another tenant reports an active login |
| BR-RPT-010 | Audit-trail integrity verdict | `Audit::verify()` once | per row | `unsigned` / `verified` / `BROKEN` by comparing `row.id` to `broken_at` | tenant | `:363-414` | Y | No | N |
| BR-RPT-011 | The compliance scorecard calls `scoreFor()` inside the row map — ~5 queries per employee | employees | always | N+1 | tenant | `:322-328` | Y | No | Y |
| BR-RPT-012 | Every export writes an `activity_logs` row; previews do not | dataset, month, row count, IP | on export | direct INSERT — **bypasses `Audit::record()`**, so the row carries no `row_hash` | tenant | `:481-493` | Y | No | Y — it pollutes or is excluded from the very chain the `audit-trail` report verifies |
| BR-RPT-013 | Report CSV quoting rules; **no UTF-8 BOM and no CSV-injection guard** | cell values | contains `"`/`,`/CR/LF | quote and double the quotes; `\r\n` line ends | product | `:507-518` | Y | No | Y — the drive and campaign exporters *do* emit a BOM |
| BR-RPT-014 | Both report endpoints return HTTP **200** with the raw exception message on failure | exception | any throwable | `{"ok":false,"error":"<raw message>"}` | product | `:449-451`, `:501-503` | Y | No | Y |

### 3.9 Master data

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HC? | Configurable today? | Want different? |
|---|---|---|---|---|---|---|---|---|---|
| NEW-MST-101 | The master registry is a hard-coded PHP array of 58 types over 54 tables | — | always | `def($type)` returns the definition or `null` | product | `MasterController.php:30-493` | **Y** | **No** | **Y — the single largest configurability gap in the product** |
| NEW-MST-102 | Two nav keys are aliased to canonical definitions | type | `geofence-list`, `att-zkteco` | rewrite the key | product | `:486-490` | Y | No | N |
| NEW-MST-103 | A master's table and every declared column self-create on first use | def | table or column missing | `Schema::create` / `Schema::table ALTER` | installation | `:496-578` | Y | No | **Y — DDL at runtime must not survive into SmartPRS 3** |
| NEW-MST-104 | `list()` has **no role guard at all** | — | authenticated | return every row of any master to any authenticated user | tenant | `:608-701` | Y | No | **Y** (security — see §7) |
| NEW-MST-105 | `save()`, `import()` and `delete()` require `super_admin \| admin \| hr_manager` | role | always | 403 otherwise | tenant | `:580-583`, `:709-713`, `:1071`, `:1194` | Y | No | **Y — a single role set for all 58 masters; no per-master permission** |
| NEW-MST-106 | A non-manager may **create** (never edit, never read others') only `helpdesk` and `posh` | type, role | `selfServe()` | force `employee`/`employee_id` to self, `status='open'`, discard posted `priority`/`hr_comment` | tenant | `:585-589`, `:646-649`, `:734-742` | Y | No | Y |
| BR-SEC-401 | All master free text is HTML-stripped at write | input | always | `stripHtmlDeep()` | tenant | `:723` | Y | No | Y — it also destroys legitimate rich text (see DEFECT-DOC-05) |
| NEW-MST-107 | Blank strings are coerced to NULL, and every value is cast to its declared type | input | per field | `bool` from `1/true/yes/on`; `decimal`/`coord` via `is_numeric` else **0**; `int` via `(int)`; `date`/`datetime` blank → NULL | tenant | `:935-962` | Y | No | Y — a non-numeric decimal silently becomes 0 rather than erroring |
| NEW-MST-108 | If the table has `company_id` and none was chosen, it defaults to the tenant's **first** company | company_name | not `companies`, column exists, value empty | look up the tenant's first non-deleted company | tenant | `:974-978` | Y | No | **Y — silently misattributes rows in a multi-company group** |
| NEW-MST-109 | Employee-name fields resolve to FK ids, accepting a name **or** an emp_code | `emp_map` | value present | look up `employees` by `name` OR `emp_code` | tenant | `:1025-1035` | Y | No | Y — name collisions resolve to an arbitrary row |
| BR-SEC-402 | A master row is soft-deleted only if its table happens to carry `deleted_at`; otherwise hard-deleted | table shape | always | `UPDATE deleted_at` or `DELETE` | tenant | `:1191-1219` | Y | No | **Y — deletion policy is an accident of schema, and there is no referential check: deleting a department leaves `employees.department_id` dangling** |
| NEW-MST-110 | Every master create/update/delete is written to the hash-chained audit log | tenant, user, type, id, changed field names, IP | always | `Audit::record()` | tenant | `:792-800`, `:1209-1217` | Y | No | N (good) — note only **field names** are recorded, never before/after values |
| NEW-MST-111 | Master CSV import upserts only when the master declares an `emp_map`; otherwise it always inserts | CSV | per row | header normalised (case/space/dash tolerant); `emp_code`/`employee_id`/`emp_id`/`empid`/`code` resolves the employee | tenant | `:1068-1163` | Y | No | Y — re-importing departments duplicates them |
| NEW-MST-112 | The import template's sample row is hard-coded for exactly one master (`geofence`) | type | always | header from `fields`, `emp_code` prepended when `emp_map` exists; sample row blank for 57 of 58 types | product | `:1166-1189` | Y | No | Y |
| BR-CMP-007 | BGV next-due = verification date + revalidation months | `verified_on`, `revalidate_months` | both present on a `bgv` save | `next_due = verified_on->addMonths(n)`; a parse failure leaves a manual value untouched | tenant | `:746-753` | Y | The months are a per-row field | N |
| NEW-MST-113 | Overtime amount is auto-computed when left blank | ctc, hours, multiplier | `overtime` save with an employee and hours, no amount | `round(hours × multiplier × (ctc/12/26/8), 2)`, multiplier defaulting to 2 | tenant | `:758-763` | **Y** | **No** | **Y — the ÷12÷26÷8 divisor chain and the ×2 default are statutory/contractual choices** |
| BR-CMP-709 | Assigning an agent to a bank/portfolio without a valid DRA certificate warns and records an override | `employee_id` | no `dra_certs` row with `status='verified'` and unexpired | insert `activity_logs action='dra_override'`; return `{ok:true, warning}` | tenant | `:805-810`, `:899-932` | Y | No | **Y — warn-vs-block should be a policy setting (a `dra_gate` setting exists but is not consulted here)** |
| NEW-MST-114 | Saving a salary component triggers a Labour Code "Basic+DA ≥ 50 % of wage gross" check on a sample CTC of ₹12 00 000 | the scope's full component set | `pct < 50` | return `{ok:true, warning}`; **never blocks** | tenant | `:817-822`, `:846-892` | **Y** | **No** | **Y — the 50 % threshold and the ₹12 L sample CTC are hard-coded; the DA detection is a substring/regex match on the earning name** |
| NEW-MST-115 | Creating a new company is gated by the subscribed company count; edits are never blocked | tenant | `type='companies'` and no `id` | `SubscriptionService::canAddCompany()`; 422 on failure | tenant | `:775-781` | Y | No | N (commercial) |
| NEW-MST-116 | The oldest company in a tenant is auto-flagged `is_master` on first list | companies | no `is_master` row exists | set `is_master=1` on the lowest id | tenant | `:622-633` | Y | No | Y — "oldest = master" is an assumption, not a customer decision |
| NEW-MST-117 | A per-master `fixed` map pins constant column values that the screen cannot change | def | always | e.g. `letter_type='offer', is_template=0` | product | `:639-641`, `:1052-1054` | Y | No | N (mechanism is sound) |
| NEW-MST-118 | Manual punches cannot be dated in the future (2-minute clock-skew allowance) | `punch_at`, `log_date` | parseable and > now+2min | `RuntimeException` → friendly 422 | tenant | `:997-1022` | Y | No | N |
| NEW-MST-119 | An unknown employee name on a manual punch is a hard error, never a junk row | `emp_name` | no name/emp_code match | throw | tenant | `:982-996` | Y | No | N |
| NEW-MST-120 | `list()` errors are logged and replaced with a generic message; `save()` errors likewise | throwable | any | log detail, return a safe string | product | `:694-700`, `:825-836` | Y | No | N (good — and the pattern the rest of the product does not follow) |
| NEW-MST-121 | The company-name display map is loaded **without a tenant filter** | — | every list call | `DB::table('companies')->pluck('name','id')` | **installation** | `:653` | Y | No | Y — plus a second full companies query at `:692`, twice per list call |

### 3.10 Settings, configuration and branding

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HC? | Configurable today? | Want different? |
|---|---|---|---|---|---|---|---|---|---|
| NEW-CFG-101 | Effective statutory rates = code defaults overlaid with the tenant's saved JSON blob | tenant | always | `array_merge(defaults(), saved)`; `tds_slabs` falls back wholesale if empty or non-array | tenant | `SettingsController.php:91-102` | defaults are code | 30 of 36 keys are savable | Y |
| NEW-CFG-102 | Six keys exist in `defaults()` but are **absent from `save()`'s validation array**, so they can never be changed | `lwf_enabled, lwf_employee, incentive_min_compliance, data_retention_months, contact_window_start, contact_window_end` | always | permanently pinned at their defaults | tenant | `:29-73` vs `:141-164` | **Y** | **No** | **Y — `contact_window_*` is the RBI lawful-contact window and `data_retention_months` is a DPDP retention period** |
| NEW-CFG-103 | Every numeric setting is validated `nullable\|numeric\|min:0` with **no upper bound** | value | always | accept | tenant | `:140-153` | Y | No | Y — `pf_rate = 9999` is accepted and used by payroll |
| NEW-CFG-104 | A partial save starts from the tenant's current effective rates so untouched keys are preserved | current rates | always | merge, then persist the whole blob | tenant | `:166-201` | Y | No | N (good, rev172 fix) |
| NEW-CFG-105 | `GET /app/settings` has **no role guard**; `POST` requires `super_admin\|admin` | role | read vs write | read is open to any authenticated tenant user | tenant | `:126-133` vs `:137` | Y | No | Y |
| NEW-CFG-106 | A platform user (no tenant) writes settings to `tenant_id = 0` | tenant_id | NULL | coerce to 0 | platform | `:169`, `:197` | Y | No | N |
| BR-CFG-201 | Per-company SMTP with a tenant-wide default under key `'0'`; a company's own config wins only if `host` is non-empty | company id | always | resolve, merge over `mailDefaults()` | tenant | `ConfigController.php:65-84`, `:119-146` | Y | Yes, per company | N |
| NEW-CFG-107 | The stored SMTP password is masked on read but written and stored in **plaintext JSON** | password | blank on save | keep the existing value; otherwise overwrite | tenant | `:98-104`, `:135-143` | Y | No | **Y — encrypt at rest** |
| NEW-CFG-108 | `GET /app/mail-settings` and `GET /app/branding` have **no role guard** | — | authenticated | return the config | tenant | `:86-117`, `:379-401` | Y | No | Y |
| NEW-CFG-109 | Branding is display name, brand colour (default `#f97316`), logo (uploaded file or typed URL) and tagline, per company | company id | always | resolve saved → company row → default | tenant | `:222-249`, `:403-446` | Y | Yes, per company | **Y — no fonts, no letterhead, no footer, no page margins, no per-document branding** |
| NEW-CFG-110 | An uploaded logo survives a Save unless the admin types an external URL | typed URL | does not contain `/app/branding/logo/` | drop the stored file reference | tenant | `:416-424` | Y | No | N |
| BR-CFG-202 | The app (sidebar/PWA) logo is per tenant at `public/images/tenants/{tid}/applogo.png`, falling back to the product logo | tenant | file exists | serve it with a `filemtime` cache-buster | tenant | `:288-343` | Y | Yes | N (explicit remediation of a prior cross-tenant defect) |
| NEW-CFG-111 | Logo upload accepts png/jpg/jpeg/gif/webp ≤ 2 MB (company) and png/jpg/jpeg/webp ≤ 2 MB (app) | file | always | validate, store | tenant | `:256-259`, `:298-300` | Y | No | N |
| NEW-CFG-112 | The PWA icon and manifest are generated per request from the logged-in tenant's company logo, centred on a white square with 12 % padding | session, logo | logo file exists and GD is available | composite and return PNG, `max-age=300` | tenant | `AppIconController.php:44-140` | Y | Indirectly (upload a logo) | Y — no maskable-safe area, no dark variant, 12 % padding fixed |
| NEW-CFG-113 | The mail log returns the most recent 200 rows for the tenant | — | `canManage` | list with status counts | tenant | `ConfigController.php:149-182` | Y | No | Y — no date filter, no pagination, no export |

### 3.11 Dashboards, screen help, seeding, landing

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HC? | Configurable today? | Want different? |
|---|---|---|---|---|---|---|---|---|---|
| NEW-DSH-101 | Six dashboard cards, fixed, chosen by scope; `hasRole('super_admin')` is a **branch selector, not a guard** | tenant_id, role | `tid === null && super_admin` → platform set, else company set | return `{scope, cards, notices}` | tenant | `DashboardController.php:35-49` | **Y** | **No** | **Y — no card selection, no reordering, no custom KPIs, no thresholds, no drill-through configuration** |
| NEW-DSH-102 | A `tenant_id IS NULL` non-super-admin falls through to the company card set with every tenant filter skipped | tenant_id | NULL and not super_admin | cross-tenant totals | **installation** | `:44-45` | Y | No | Y (security) |
| NEW-DSH-103 | Notices shown are the 6 most recent whose status is **not** in a deny-list | notices | `status NULL` or `LOWER(status) NOT IN ('inactive','archived','draft','expired')` | show | tenant + platform-wide (`tenant_id NULL`) | `:52-74` | Y | No | Y — a deny-list means an unrecognised status is shown; an allow-list is the safe shape |
| NEW-DSH-104 | The first dashboard tile carries a deliberate colour test marker with an un-actioned revert instruction | — | always | `#16a34a` / `#dcfce7` / `#166534` on "Active Employees" | **every client** | `:157-160` | Y | No | Y (defect — ships to production) |
| NEW-DSH-105 | Global search has **no role guard and no self-scope** | `q` ≥ 2 chars | always | 8 employees by name/code/email/mobile `LIKE '%q%'` | tenant | `:198-225` | Y | No | Y — a self-service `employee` can enumerate every colleague's contact details |
| NEW-HLP-101 | Screen help is 120 static screen entries + 119 "why" entries + 73 "mistakes" entries in PHP arrays; there is no table, no editor and no write route | screen key | always | exact match → alias table (4 entries, duplicated at `:35` and `:45`) → generic fallback | product, identical for every tenant and edition | `ScreenHelpController.php:28-60`, `:1068-1089` | **Y** | **No** | **Y — an on-prem L1 client is served help for SaaS-only screens they cannot reach** |
| NEW-HLP-102 | The whole 236 KB content set is materialised on **every** help request; there is no memoisation or cache | — | always | call `content()`, `whys()`, `mistakes()`, `roadmapMistakes()` | product | `:31`, `:47`, `:56` | Y | No | Y |
| NEW-HLP-103 | `content()` merges 17 module arrays with `array_merge`, so a later module silently overrides an earlier one | — | duplicate key | last module wins | product | `:1068-1089` | Y | No | Y — **there IS a live duplicate: `notifications` is defined by `roadmapModule()` `:897` and again by `mainModule()` `:2082`; `mainModule` wins and the richer roadmap entry is dead** |
| NEW-HLP-104 | The "unknown screen" fallback takes a `$key` parameter and never uses it | screen key | no match | return one identical generic entry | product | `:1055-1066` | Y | No | Y — contradicts the class docblock's "falls back to their module's generic guide" |
| BR-CMP-030 | Industry seeding is idempotent by natural key per table | tenant | per row | insert what is missing; back-fill an empty programme description without overwriting an edit; continue `kb_topics.sort` from `MAX(sort)` | mixed (see §1.19) | `SeedIndustryContent.php:33-130`, `:274-378`, `:624-649` | Y | Content is code; the seeded rows are editable | **Y — the content pack is one hard-coded vertical (India collections & recovery)** |
| NEW-SEED-101 | `kb_topics` is de-duplicated by `title` **across the whole installation** and always inserted with `tenant_id = NULL` | title | exists anywhere | skip | **installation** | `:629-632` | Y | No | **Y — a tenant that renames an article gets a second copy for everyone** |
| NEW-SEED-102 | `requiredDefaults()` runs a raw `SHOW COLUMNS FROM <table>` and supplies `0` / `now()` / `''` for every NOT-NULL no-default column the seeder does not set | table shape | MySQL only | fill placeholders | installation | `:282-313` | Y | No | Y — MySQL-only, failure swallowed, so on SQLite the placeholders are simply absent |
| NEW-SEED-103 | `mandatory` and `validity` on a seeded training programme are stored and **enforced by nothing** | — | always | store | tenant | `:380-449`; no reader in `app/` | Y | No | **Y — "mandatory, annual refresher" is presented to an auditor as a control that does not exist** |
| NEW-LND-101 | The landing CMS saves a **full snapshot**, so newer code defaults never appear until a super admin presses Reset | saved JSON | a snapshot exists | `array_replace_recursive(defaults, saved)`, with the four repeatable groups replaced wholesale | platform | `LandingController.php:87-107`, `:54-65` | Y | Yes, super admin only | Y |
| NEW-LND-102 | Lead alert recipients (email + WhatsApp) come from the landing CMS `contact` block, not from code | CMS content | always | email via `MailService::queue`, WhatsApp via Interakt template | platform | `LeadController.php:123-171`, `:214-223` | N | Yes, super admin | N |
| NEW-LND-103 | A lead is saved even if both alerts fail, with a bare-row last-resort insert | validated form | any throwable | insert the bare row; only a second failure returns 500 | platform | `:73-95` | Y | No | N (good) |
| NEW-LND-104 | The ten public legal pages are slug→blade with content hard-coded in blades and one shared effective date | slug | in `PAGES` | render | product | `PolicyController.php:21-49` | **Y** | **No** | **Y — an on-prem or white-label customer needs their own policies** |

---

## 4. Calculations and formulas

Every formula in this area, transcribed from source.

### 4.1 Requisition and employee code allocation

`RecruitmentController.php:1192-1197`
```php
$n = (int) DB::table('job_openings')->when($tid, …)->count() + 1;
$reqCode = 'REQ'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
while (DB::table('job_openings')->where('req_code', $reqCode)->when($tid, …)->exists()) {
    $n++; $reqCode = 'REQ'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}
```
`RecruitmentController.php:619-624` — identical shape with `'EMP'` over `employees`.

Worked example: a tenant with 7 requisitions creates the 8th → `REQ0008`. Delete `REQ0008`, create another → count is 7 again → `REQ0008` is minted a second time and the `while` does not fire because the old row is gone. **Codes are reused after deletion.** Rounding: none. Not transactional and there is no unique index, so two concurrent creates can both pass the `exists()` check and insert the same code.

### 4.2 Requisition fill

`RecruitmentController.php:782-787`
```
filled  = COUNT(recruitment WHERE job_id = :jobId AND stage = 'hired' [AND tenant_id = :tid])
if (filled >= (openings ?: 1)) AND (status == 'open'):  status = 'closed'
```
Basis: hired **candidates**, not created employees. Never called from `moveStage`, so a card dragged to *Hired* on the board does not update `filled`. `board()` masks this at read time with `filled = max(stored, actual)` (`:257-259`) but the stored column and the auto-close never catch up. Deleting a hired candidate does not decrement `filled`.

### 4.3 Interview aggregation

`RecruitmentController.php:221-224`
```
iv_avg = count(scores) ? round(array_sum(scores) / count(scores), 1) : null
```
where `scores` = the `score` of every round with `status='completed'` and a non-empty score. Rounding: 1 decimal, PHP `round()` (half away from zero). `iv_rec` is the recommendation of the **last completed round in id order** — not the highest round number.

### 4.4 Full-and-final settlement (report `fnf`)

`ReportController.php:264-283`
```
monthly  = round(ctc / 12, 2)
basic    = round(monthly * 0.5, 2)                 // hard-coded 50% assumption
years    = Carbon(doj)->diffInYears(Carbon(last_day))
gratuity = years >= 5 ? round(15 / 26 * basic * years, 2) : 0
FnF      = round(monthly + gratuity - advances_recovered - dues, 2)
```
Worked example: CTC ₹600 000, DOJ 2018-04-01, last day 2026-03-31, advances ₹5 000, dues ₹1 200.
`monthly = 50 000.00`; `basic = 25 000.00`; `years = 7`; `gratuity = round(0.576923 × 25 000 × 7, 2) = round(100 961.538…, 2) = 100 961.54`; `FnF = round(50 000 + 100 961.54 − 5 000 − 1 200, 2) = 144 761.54`.

Divisors and bases, all hard-coded: **12** (annual→monthly), **0.5** (basic as a share of monthly gross — unrelated to the tenant's actual salary structure in `salary_components`), **15/26** (the Payment of Gratuity Act formula), **5 years** (the eligibility threshold). `diffInYears` truncates, so 4 years 11 months yields 0 gratuity. The date arithmetic sits inside a swallowed `catch` that leaves `gratuity = 0` on any parse failure (`:273-275`).

### 4.5 Points auto-apply

`PointsController.php:239-264` (month arithmetic)
```
start       = <month>-01
end         = last day of the month
holidays    = holidays.date within [start, end], tenant-scoped if the column exists
workingDays = max(1, count of days that are NOT Sunday AND NOT a holiday)
```

`PointsController.php:140-175` (attendance derivation)
```
rows       = attendance_logs WHERE emp_code = ? AND log_date BETWEEN [start, end]
             AND (direction = 'in' OR direction IS NULL)  ORDER BY punch_at
firstByDay = the first punch of each distinct log_date
present    = COUNT(firstByDay)
late       = COUNT(days where (punch.hour * 60 + punch.minute) > 570)     // 09:30, SHIFT_START_MIN
absent     = max(0, workingDays - present)
hadIssues  = (late > 0) OR (absent > 0)
```

`PointsController.php:94-124` (award formulas)

| Bucket | Fires when | Points written |
|---|---|---|
| late | `late > 0` | `rule.points × late` |
| absent | `absent > 0` | `rule.points × absent` |
| perfect | `!hadIssues && present > 0` | `rule.points × 1` |
| award | `awardsThisMonth > 0` | `rule.points × awardsThisMonth` |
| test | `testsPassedThisMonth > 0` | `rule.points × testsPassedThisMonth` |

Every matching rule in a bucket fires, so two rules whose `event` both contain "late" both apply. Penalties are expressed as negative `points` values.

Worked example: March 2026 (31 days, 5 Sundays), 1 holiday on a Tuesday → `workingDays = 31 − 5 − 1 = 25`. An agent has 22 distinct days with an in-punch, 4 of them after 09:30 → `present = 22`, `late = 4`, `absent = max(0, 25 − 22) = 3`. With a rule "Late arrival" at `−5` and a rule "Absent" at `−20`: `−5 × 4 = −20` and `−20 × 3 = −60`; no perfect-attendance award because `hadIssues` is true.

Basis notes: **only Sunday** is an off day regardless of the tenant's `weekly_off_day` and `sat_off_mode` settings; the 09:30 cut-off ignores the Shifts master, the Late Policy and the roster; `absent` is derived from a working-day count, not from leave records, so approved leave counts as an absence.

### 4.6 Overtime auto-amount (master `overtime`)

`MasterController.php:758-763`
```php
$ctc    = (float) employees.ctc;
$mult   = (float) ($row['multiplier'] ?? 2);
$hourly = $ctc > 0 ? ($ctc / 12 / 26 / 8) : 0.0;
$row['amount'] = round(((float) $row['hours']) * ($mult > 0 ? $mult : 2) * $hourly, 2);
```
Worked example: CTC ₹360 000, 6 hours, multiplier 2 → `hourly = 360000/12/26/8 = 144.2307692…`; `amount = round(6 × 2 × 144.2307692, 2) = round(1730.769…, 2) = 1730.77`.

Divisors, all hard-coded: **12** months, **26** days, **8** hours; multiplier defaults to **2** both when absent and when supplied as zero or negative. Note that `26` appears in the whole codebase in exactly two places, both overtime — never in LOP. Three successive float divisions occur before the multiply.

### 4.7 Labour Code "wages ≥ 50 %" warning on a salary-component save

`MasterController.php:846-892`
```php
$slip     = AppDataController::computeSlipFromComponents(1200000, $comps, SettingsController::rates($tid), '');
$wageGross= (float) ($slip['wage_gross'] ?? $slip['gross'] ?? 0);
$basicDa  = (float) ($slip['basic'] ?? 0);
foreach ($slip['earnings'] as $name => $amt) {
    $lc = strtolower($name);
    if (str_contains($lc, 'dearness') || preg_match('/(^|[^a-z])da([^a-z]|$)/', $lc)) $basicDa += (float) $amt;
}
$pct = round($basicDa / $wageGross * 100, 1);
if ($pct < 50) return "Labour Code check: Basic+DA is only {$pct}% of pay …";
```
The **sample CTC is a hard-coded ₹12 00 000**, the **threshold is a hard-coded 50 %**, and "DA" is detected by a substring/regex match on the earning's display name. It never blocks the save; on any exception it returns `null` silently.

### 4.8 Minimum-wage check (report `min-wage-check`)

`ReportController.php:139`, `:148-152`
```
min          = MAX(min_wages.monthly_min) across the whole tenant
monthlyGross = round(ctc / 12, 2)
shortfall    = min > 0 ? round(max(0, min - monthlyGross), 2) : 0
Status       = min <= 0 ? 'No min set' : (monthlyGross >= min ? 'OK' : 'BELOW MIN')
```
The `min_wages` master carries `state`, `zone` and `category` columns. **None of them is used.** A tenant operating in Maharashtra and Assam gets the Maharashtra figure applied to every Assam agent.

### 4.9 Compliance-scorecard eligibility (report `compliance-scorecard`)

`ReportController.php:312-327`
```
min   = SettingsController::rates($tid)['incentive_min_compliance'] ?? 60
score = ComplianceController::scoreFor($employeeId, null)          // ~5 queries per employee
Incentive Eligible = score >= min ? 'Yes' : 'No'
```
The default `60` appears twice — once in `SettingsController::defaults()` `:56` and once as the literal fallback here `:312`. **`incentive_min_compliance` is one of the six settings that cannot be saved** (NEW-CFG-102), so it is permanently 60.

### 4.10 Muster roll (report `reg-muster`)

`ReportController.php:209-211`
```sql
SELECT emp_code, emp_name, COUNT(DISTINCT log_date) AS present_days
FROM attendance_logs
WHERE [tenant_id] AND [company_id] AND log_date BETWEEN :monthStart AND :monthEnd
GROUP BY emp_code, emp_name
```
No `direction` filter, so a day with only an out-punch counts as present. Employees with zero punches do not appear at all, rather than appearing with 0.

### 4.11 Hiring-drive funnel

`HiringDriveController.php:97-103`
```sql
count(*) invited,
sum(case when response in ("will_attend","interested") then 1 else 0 end) will_attend,
sum(case when attended = 1 then 1 else 0 end) attended,
sum(case when score is not null then 1 else 0 end) interviewed,
sum(case when outcome = "selected" then 1 else 0 end) selected
```
Note the **double-quoted string literals**: under MySQL `ANSI_QUOTES` these parse as identifiers and the query errors.

### 4.12 Document storage quota

`DocumentController.php:199-201`, `:227-232`
```
limitMb    = tenants.doc_storage_emp_mb ?? 100   (NULL tenant → unlimited)
limitBytes = limitMb * 1048576
skip the file when: used + file.size > limitBytes
```
`used` is `SUM(documents.file_size)` for that employee, computed after a lazy back-fill of up to 200 legacy rows by `filesize()`-ing each one — **on every call, including the plain list** (`:56-72`).

### 4.13 PWA icon composition

`AppIconController.php:108-136`
```
size  = clamp(request s, 48, 512), default 192
pad   = round(size * 0.12)
scale = min((size - 2*pad) / srcW, (size - 2*pad) / srcH)
dw,dh = round(srcW*scale), round(srcH*scale)   (min 1)
dx,dy = (size - dw)/2, (size - dh)/2
```
Background is always opaque white. There is no maskable safe-area allowance beyond the fixed 12 % padding.

### 4.14 Letter merge

`LetterController.php:156-180` — a single `strtr($body, $repl)` over exactly 21 keys:

`{{employee_name}} {{candidate_name}} {{emp_code}} {{designation}} {{department}} {{company}} {{company_address}} {{date}} {{ctc}} {{incentive}} {{joining_bonus}} {{doj}} {{pan}} {{uan}} {{bank_acc}} {{ifsc}} {{email}} {{mobile}} {{grievance_officer}} {{grievance_phone}} {{grievance_email}} {{contact_hours}}`

Formatting rules, all fixed: money is `'₹' . number_format((float) $v)` — **no decimal places, no lakh/crore grouping option, no words-in-figures**; dates are `d M Y`; `{{contact_hours}}` is `contact_window_start . '–' . contact_window_end`, defaulting to `08:00–19:00`. `strtr` is literal, so an unrecognised placeholder is **printed to the PDF verbatim**.

Six keys always resolve to empty strings on the candidate branch (`{{emp_code}}`, `{{department}}`, `{{pan}}`, `{{uan}}`, `{{bank_acc}}`, `{{ifsc}}`) and two on the employee branch (`{{incentive}}`, `{{joining_bonus}}`).

The NDA signing page runs a **different, six-key merge** (`NdaSignController.php:59-66`) reading `employees.designation` / `.department` **string** columns while the PDF path reads the `designations`/`departments` **FK join** — so on an FK-populated record the web page renders blanks where the PDF renders values.

---

## 5. Configuration surface today

### 5.1 (a) What a customer CAN change without code

#### Through the Settings screen (`POST /app/settings` → `statutory_settings`, `super_admin|admin`)

Thirty of the thirty-six keys in `SettingsController::defaults()`:

| Key | Default | What it controls |
|---|---|---|
| `pf_wage_cap` | 15 000 | PF wage ceiling |
| `pf_rate` | 12 | PF % each side |
| `esi_threshold` | 21 000 | ESI eligibility gross |
| `esi_employee_rate` / `esi_employer_rate` | 0.75 / 3.25 | ESI shares |
| `pt_amount` | 200 | Professional Tax per month |
| `pt_female_exempt` / `pt_female_exempt_upto` | 1 / 25 000 | Maharashtra female PT exemption and its ceiling |
| `std_deduction` | 75 000 | Salary standard deduction |
| `rebate_87a_limit` | 12 00 000 | 87A rebate ceiling |
| `cess_rate` | 4 | Health & education cess |
| `comm_tds_rate` | 5 | Sec 194H commission TDS |
| `no_pan_tds_rate` | 20 | Higher TDS with no PAN |
| `conveyance_enabled` / `conveyance_rate` | 0 / 0 | Optional conveyance deduction |
| `payslip_show_ytd` | 1 | YTD column on payslip PDFs |
| `payslip_dl_mode` / `payslip_dl_depts` / `payslip_dl_emps` | all / [] / [] | Employee payslip self-download policy |
| `weekly_off_day` | sunday | Weekly off |
| `sat_off_mode` | none | Saturday policy (`none\|all\|2_4\|1_3`) |
| `lop_basis` | working | LOP denominator (`working\|calendar\|fixed30`) |
| `sandwich_rule` | 0 | Off/holiday between two absences counts as LOP |
| `bonus_pct` | 8.33 | Statutory bonus % |
| `dra_gate` | warn | DRA-expiry gate at money points (`off\|warn\|block`) |
| `points_gate_min` | 0 | Minimum monthly points for incentive eligibility |
| `incentive_payout_lag` | 0 | Auto payout date = earned month + N |
| `late_email_enabled` | 0 | Auto-email on late arrival |
| `tds_slabs` | 7 rows | New-regime annual slabs, fully replaceable |

#### Through per-company configuration screens

| Surface | Store | Role | What |
|---|---|---|---|
| Email / SMTP (`/app/mail-settings`) | `app_settings.mail` | `super_admin\|admin` | host, port, username, password, encryption, from address, from name — **per company**, with a tenant-wide default under key `'0'`; plus a live test-send |
| Company branding (`/app/branding`) | `app_settings.branding` | `super_admin\|admin` | display name, brand colour, tagline, logo (upload ≤ 2 MB or a typed URL) — per company |
| App / PWA logo (`/app/branding/app-logo`) | `public/images/tenants/{tid}/applogo.png` | `super_admin\|admin` | the sidebar and home-screen icon for that tenant only |
| Financial year (`/app/fin-year/set`) | `settings.fy_active` | in-controller | the active FY that stamps new master rows |

#### Through master screens (`super_admin|admin|hr_manager`) — configuration in the shape of data

These are the real configuration surface a Product Owner would recognise:

`companies` (incl. PAN, GSTIN, address, website and the **grievance officer / phone / email** that merge into every letter) · `departments` · `designations` · `branches` · `banks` · `teams` · `holidays` (the calendar that feeds payroll LOP) · `leave-types` (annual entitlement, carry-forward, paid/unpaid) · `shifts` (start/end, grace minutes, full/half-day hours, break budget, night allowance) · `late-policy` (scope, mode, three cut tiers, cut mode, lates-before-cut, break rules, weekoff behaviour) · `salary-setup` (component code, name, type, category, base, calc value, sequence, taxable) · `salary-schedules` and `pay-cycle` · `incentive-schemes` · `points-rules` (event name and point value) · `min-wages` (state/zone/category/amount/effective date) · `policies` (the policy repository with versions and review dates) · `training-programs` / `training-content` / `tests` · `faqs` · `feature-flags` · `wa-settings` / `sms-settings` / `sms-templates` (provider, API URL, API key, sender, DLT ids) · `biometric-devices` · `letters-templates` (**title, letter type, body with placeholders, status**) · `notice-board`.

#### Through the Knowledge Base (`super_admin|admin`)

Article category, icon, title, body and role tags; drag-free reordering. **Installation-wide, not per tenant.**

#### Through the landing CMS (`super_admin` only)

Hero copy, 16 feature cards, stats, three pricing cards, client logos, the About block, contact details, WhatsApp number, and **lead-alert recipients**; plus a Reset-to-code-defaults action.

#### Super-admin-only, per tenant

`tenants.doc_storage_emp_mb` — the per-employee document quota.

### 5.2 (b) What a customer CANNOT change without code — the SmartPRS 3 backlog

Ordered by business impact.

**Master data and extensibility**
1. **Add a new master type.** All 58 are literals in `MasterController::def()` `:30-493`. A customer wanting a "Vendors", "Cost Centres", "Grades", "Job Bands", "Skills", "Client Portfolios" or "Vehicles" master needs a code release.
2. **Add a field to an existing master.** The `cols`/`fields` arrays are code.
3. **Remove or hide a field**, reorder fields, change a field's label, or change a field's input type (there is no type metadata beyond the storage type).
4. **Change which fields are mandatory** — the `required` array is code (`:91`, `:103`, `:114`, …).
5. **Change a field's data type or precision.** Every decimal is `DECIMAL(12,2)`; every string is `VARCHAR(255)`.
6. **Add validation** — there is none beyond required-ness and type coercion. No ranges, no formats, no regex, no cross-field rules, no uniqueness.
7. **Per-master permissions.** One role set (`super_admin|admin|hr_manager`) governs writes to all 58, and **reads are ungated entirely**.
8. **Deletion policy.** Soft vs hard delete is decided by whether the table happens to have a `deleted_at` column (`:1202-1206`). No referential-integrity check exists anywhere.
9. **Change the "oldest company = master company" assumption** (`:622-633`).
10. **Change the default-company fallback** that silently attributes a row to the tenant's first company when none is chosen (`:974-978`).

**Recruitment**
11. **Define your own pipeline.** `STAGES` is a six-value constant (`RecruitmentController.php:31`). No stage add, rename, reorder, colour, or per-requisition pipeline.
12. **Define stage transition rules.** There is no transition matrix; any stage goes to any stage.
13. **Add candidate fields.** ~30 fixed columns; no custom fields, no per-tenant profile schema.
14. **Change interview modes, statuses, or the recommendation scale** (`:35-39`).
15. **Change the requisition priority list** (`:301`).
16. **Change requisition approval** — approver role, number of levels, approval by value band, or re-approval on material change.
17. **Change the employee-code scheme** (`EMP` + 4-digit count) or the requisition-code scheme (`REQ` + 4-digit count).
18. **Stop hiring auto-creating designation masters** from free-text candidate positions.
19. **Change the hire identity key** from `employees.name`.
20. **Configure what a candidate→employee conversion carries across** (see §6.4).
21. **Board and pool caps** (2 000 / 500) and the import cap (20 000 rows).
22. **Change the drive default instructions**, drive types, or the funnel stages.
23. **A candidate opt-out / do-not-contact register** does not exist in any form.
24. **Change the messaging channel** — WhatsApp/Interakt is hard-wired; there is no SMS or email campaign path in this controller.
25. **Change mobile normalisation** from India-only last-10-digits.
26. **Campaign scheduling, throttling, send windows, or per-campaign sender identity.**

**Letters and documents**
27. **Add a letter type.** Seven in the dropdown; five have issue screens; `experience` and `custom` can be templated but never issued; `letters-nda` is registered in the SPA and missing from the backend.
28. **Add a merge placeholder.** The 21-key map is code (`LetterController.php:156-179`). Probation period, notice period, working hours, work location, reporting manager, PF/ESI numbers, appraisal date, salary breakup table — none exist.
29. **Rich text in a template.** `stripHtmlDeep()` on the master save path destroys HTML (`MasterController.php:723`), so the seeded HTML offer template is reduced to a run-on paragraph the first time an admin opens and saves it.
30. **Letter layout.** Header, footer, signature block, margins, page size, fonts and the "This is a system-generated letter from SmartPRS" footer are all fixed in `letter-pdf.blade.php`. No letterhead image, no digital signature image, no per-company template.
31. **Money and date formatting in letters** (`₹` + `number_format`, no decimals; `d M Y`).
32. **Template versioning or an active/inactive flag that is honoured** — `letters.status` is written by the UI and read by nothing.
33. **ID card design** — one fixed 320 px card in `idcard-pdf.blade.php`; no fields, size, orientation, back side, QR or barcode configuration.
34. **Document categories** (a 6-value constant that is not even enforced), the 8-extension whitelist, the 10 MB per-file cap, or a required-document checklist per role/grade.
35. **Who may download whose documents** — any `employee`-role user can fetch any document in the tenant by id.

**Public token flows**
36. **Token TTL, single-use, rotation on resend, or revocation** — none exist.
37. **What the acceptance page shows** — four fields, fixed in the blade.
38. **The evidence captured** — status, timestamp, IP, and (NDA only) a typed name. No user agent, no document hash, no stored PDF, no OTP, no audit-log row.
39. **The two built-in NDA default bodies** (`LetterController.php:76-85` and a shorter one at `NdaSignController.php:56`) plus the seven `LETTER_SAMPLES` bodies in `AppController.php:10711-10723`.

**Performance and points**
40. **The review cycle** — stages, participants, who does the self-review, cadence, competencies, weighting, calibration.
41. **The rating scale** — there is none; `rating` is unbounded `numeric`.
42. **Making the rating drive anything** — increments, incentives, promotions, PIPs.
43. **The points trigger events** — five keyword buckets in code; only attendance, awards and test passes can ever award points.
44. **The 09:30 late cut-off** used by points, and the Sunday-only weekly off it assumes.
45. **Whether points convert to money** (they never do).

**Reports**
46. **Add a report.** Fourteen datasets, each a hand-written query with a hard-coded column list.
47. **Add, remove, rename or reorder a column** on any existing report.
48. **Add a filter** beyond the fixed month + company pair, or save a filtered view.
49. **Schedule a report** or email it — no scheduling exists.
50. **Output format** — CSV only; no XLSX, no PDF, no direct statutory-form layout.
51. **The statutory form templates themselves** — Form B / D / E column sets are code.
52. **Bank advice format** — the payment mode is the literal `'NEFT'`; there is no per-bank NEFT/RTGS file layout.
53. **The FnF basic-percentage assumption (50 %)** and the gratuity formula constants.
54. **Minimum-wage matching by state/zone/category** — the columns exist and are ignored.
55. **CSV encoding options** (BOM, delimiter, line ending, injection guard).

**Settings and platform**
56. **Six statutory settings that cannot be saved at all** — `lwf_enabled`, `lwf_employee`, `incentive_min_compliance`, `data_retention_months`, `contact_window_start`, `contact_window_end`. Two of these are compliance-critical: the RBI lawful-contact window and the DPDP retention period.
57. **Upper bounds on any numeric setting.**
58. **Effective-dating on the Settings screen** — the blob has no `effective_from`; a mid-year PF change silently rewrites history for every recomputation. (Only `statutory_configs` is effective-dated, and it covers a different, narrower key set.)
59. **Audit on settings change** — `statutory_settings`, `app_settings.mail`, `app_settings.branding` and `settings` are all written with no audit entry.
60. **Setting versioning, diff, or rollback** anywhere.
61. **Dashboard cards** — six fixed cards, no selection, reordering, thresholds, targets, date ranges or drill-through.
62. **Screen help text** — 120 screens of copy in a controller, identical for every tenant and every edition, with no editor and no write route.
63. **The industry content pack** — one hard-coded vertical (India collections & recovery); a customer in any other industry gets it anyway, and cannot supply their own.
64. **The public legal pages** — ten hard-coded blades with one shared effective date.

---

## 6. Defects, conflicts and technical debt

Severity: CRITICAL (a capability is unreachable or a regulatory artefact is void) · HIGH · MEDIUM · LOW.

### 6.1 Functional defects

| ID | Severity | What it is | Evidence | Business impact |
|---|---|---|---|---|
| **DEFECT-DOC-04** | **CRITICAL** | The NDA screen cannot save. `AppController` registers a complete `letters-nda` screen (nav `:667`, icon `:1381`, title `:3251`, full field set with Preview / Email / Send-for-e-signature `:10850`) but `MasterController::def()` has no `letters-nda` entry. `GET` returns `{"rows":[],"error":"Unknown master"}`; `POST` returns 422. | `AppController.php:10850` vs `MasterController.php:356-389`, `:571-573`, `:714-717` | No NDA letter can be created in the product. The NDA e-signing flow, the +15 NDA component of the compliance score, and the confidentiality leg of the RBI audit file are all unreachable. NDA rows can only arrive by direct SQL. |
| **DEFECT-DOC-01** | **HIGH** | The public offer page does not show the offer. The controller docblock says the candidate "reviews the offer"; the page copy says "Please review and confirm"; the blade renders only name, position, company and offer date. CTC, incentive, joining bonus, joining date and the merged letter body are all absent. | `OfferAcceptController.php:11-14` vs `resources/views/offer-accept.blade.php:38-44` | The recorded "acceptance" is of a page showing four fields. As evidence of agreement to specific terms it is worthless. |
| **DEFECT-DOC-03** | **HIGH** | Signed letters are re-rendered from the **current** template. The issued row stores a `template_id` pointer and no body; the merge happens at render time. | `LetterController.php:53-68`; no `body` written to issued rows; `letters.pdf_path` exists in the migration and is never written | Editing a template after signature silently rewrites what the person signed. Reprinting an NDA a year later produces today's text under the original date, name and IP. No version pin, no content hash, no stored PDF. |
| **NEW-DEFECT-01** | **HIGH** | `letters.pdf_path` was designed to hold an issued-PDF snapshot and **no code ever writes or reads it**. | `database/migrations/2026_01_01_000600_…:120`; grep `pdf_path` across `app/`, `resources/` returns only the two migrations | The schema shows the intended fix for DEFECT-DOC-03 was designed and never built. |
| **DEFECT-CMP-01** | **HIGH** | Off-roll agents are invisible to compliance. `createOffrollFromCandidate` captures `dra`/`pcc` as free strings ≤ 120 chars; `ComplianceController` keys everything on `employees.id` and never queries `offroll_agents`. | `RecruitmentController.php:549-550`, `:677-683`; full read of `ComplianceController` | For a collections agency, off-roll field agents are precisely the population RBI recovery-agent norms target. They are absent from expiry alerting, scoring and the audit file — and consume no seat, so there is a commercial incentive to route hires this way. |
| **DEFECT-REC-03** | **MEDIUM** | Hiring, onboarding and offer acceptance are all keyed on a person's **name**. Hire is idempotent by `employees.name`; onboarding by `onboarding.employee`; offer acceptance updates `recruitment WHERE name = ?`. | `RecruitmentController.php:588-596`, `:755`; `OfferAcceptController.php:82-83` | Two genuinely different people with the same name silently collapse: the second never gets an employee record and can never be paid. Accepting one offer flips every namesake candidate in the tenant to `offer/accepted`. |
| **DEFECT-REC-01** | **MEDIUM** | The candidate CSV template does not match the CSV importer. The template advertises 13 columns; the importer reads 10, eight of which the template does not supply, and the two the importer needs (`company`, `job`) are absent from the template. | `RecruitmentController.php:795-809` vs `:851-861` | A recruiter fills the supplied template and eight columns of work are silently discarded. |
| **DEFECT-REC-02** | **MEDIUM** | Stage transitions are unvalidated and have no side effects. `moveStage` writes the column and nothing else. | `:396-419` | Dragging a card to *Hired* creates no employee, no onboarding and does not reconcile the requisition fill. Dragging it back out after a hire leaves an orphan employee. |
| **DEFECT-REC-04** | **LOW** | Two incompatible mobile-normalisation rules. The importer dedupes on the **full digit string**; messaging normalises to the **last 10 digits**. | `RecruitmentController.php:947` vs `RecruitMessagingController.php:80` | `+919999999999` and `9999999999` are one person to messaging and two to the importer. |
| **DEFECT-REC-05** | **MEDIUM** | No candidate opt-out or suppression anywhere. | full read; grep `opt`/`unsubscribe`/`do_not` | A candidate who replied "not interested" is messaged again by any campaign whose audience includes them. Under DPDP this is a consent failure, not a UX gap. |
| **DEFECT-REC-06** | **MEDIUM** | Bulk WhatsApp sends synchronously inside the web request: up to 2 000 blocking HTTP calls, 2 000 inserts, up to 2 000 `wa_log` look-ups and 2 000 `Schema::hasColumn` pairs in one request. | `RecruitMessagingController.php:181-207`, `:240-246` | A large campaign times out mid-send with no resume; partially-sent campaigns are indistinguishable from failed ones. |
| **DEFECT-REC-07** | **MEDIUM** | `wa_message_id` is indexed for webhook matching and **never written**, so the webhook always falls through to matching by phone number, unscoped by tenant or campaign. | `:192-198` vs `:399-407` | Delivery status can be written onto another tenant's message row for the same number. |
| **DEFECT-REC-08** | **MEDIUM** | Points rule keyword matching mis-buckets common rule names. The `if/elseif` chain tests `late` first, so *"Perfect attendance — no lates"* is applied as a **late penalty**; *"Passed the attendance test"* lands in `testRules`. | `PointsController.php:56-69` | Silent, systematic mis-scoring that looks like a data problem. |
| **DEFECT-REC-09** | **MEDIUM** | Auto-applied points are dated the **run date**. The incentive gate filters `points_ledger.date` to the incentive month. | `PointsController.php:88`, `:230`; `IncentiveController.php:203-207` | Running December's auto-apply in January writes January-dated rows, so December's points never count towards December's eligibility. |
| **NEW-DEFECT-02** | **MEDIUM** | Points ignore the tenant's own attendance configuration: only Sunday is an off day (`weekly_off_day` and `sat_off_mode` are not read) and the late cut-off is a hard-coded 09:30 (Shifts, Late Policy and roster are not read). | `PointsController.php:25`, `:256-260` | A Saturday-off agency mis-counts absences for every employee every month; a night-shift team is scored as late every day. |
| **NEW-DEFECT-03** | **MEDIUM** | `performance_reviews` is declared twice with different shapes — `PerformanceController::ensureCols()` creates 6 extra columns, and `MasterController` declares the same table as the `performance` master with a smaller field list. Rows are created on the master screen; the workflow columns are added by whichever controller runs first. | `PerformanceController.php:18-53` vs `MasterController.php:415-420` | The screen a user creates a review on and the screen they advance it on disagree about what a review is. |
| **DEFECT-DOC-05** | **MEDIUM** | The seeded HTML offer template is destroyed by the first UI edit. `SeedIndustryContent` inserts HTML directly via `DB::table()->insert()`, bypassing sanitisation; `MasterController::save()` runs `stripHtmlDeep()` over every field. | `SeedIndustryContent.php:89-119` vs `MasterController.php:723` | Opening and saving the "Standard Offer Letter" reduces it to a run-on paragraph and the PDF's HTML branch stops matching. There is no way to author formatted letters through the UI. |
| **DEFECT-DOC-02** | **MEDIUM** | Any letter type can be "accepted" through the offer route — `find()` omits the `letter_type` filter, and `sendAcceptLink` does not validate the type either. | `OfferAcceptController.php:36`; `LetterController.php:373-393` | An NDA opened at `/offer/{token}` and accepted gets `status='accepted'`, which does **not** satisfy the compliance NDA check (`status='signed'` required) — a silently broken compliance record. |
| **NEW-DEFECT-04** | **MEDIUM** | Two different built-in NDA texts. `LetterController::buildLetter()` `:76-85` uses a five-clause version; `NdaSignController::renderBody()` `:56` uses a shorter one-paragraph version. | as cited | Which undertaking a person signed depends on whether they read the web page or the PDF. |
| **NEW-DEFECT-05** | **MEDIUM** | Four independent sources of letter body text: DB templates, the two NDA defaults above, the seeded HTML offer template, and seven `LETTER_SAMPLES` JS constants used to pre-fill the template editor. | `AppController.php:10711-10723` | No single source of truth for a customer's letter wording. |
| **DEFECT-RPT-01** | **MEDIUM** | The minimum-wage check uses the **maximum** notified wage across the tenant, ignoring `state`, `zone` and `category`. | `ReportController.php:139` | A Mumbai minimum is applied to a Nagpur agent, producing false "BELOW MIN" verdicts on a compliance report. |
| **DEFECT-RPT-02** | **MEDIUM** | Report exports are logged with a direct `activity_logs` insert that bypasses `Audit::record()`, so the row carries no `row_hash`. | `ReportController.php:481-493` | The row breaks or is excluded from the very hash chain the `audit-trail` report verifies and prints as `verified`/`BROKEN`. |
| **DEFECT-RPT-03** | **LOW** | `reg-muster` ignores the preview limit and reports `count($rows)` rather than a SQL count. | `:209-213` | The 100-row preview returns the whole month for the whole company. |
| **DEFECT-RPT-04** | **LOW** | Report CSVs omit the UTF-8 BOM that the drive and campaign exporters emit. | `:472-479` vs `HiringDriveController.php:369`, `RecruitMessagingController.php:365` | `₹` and non-ASCII names mis-render in Excel on the statutory registers specifically. |
| **DEFECT-RPT-05** | **LOW** | `exemp-access` joins `users` on email with the tenant filter applied to `employees` only. | `:340-343` | An ex-employee whose email also exists as a user in another tenant is reported as having an active login. |
| **NEW-DEFECT-06** | **MEDIUM** | The `letters` status vocabulary is incoherent. The issued-letter dropdown offers `draft/issued/signed`; the template dropdown offers `active/inactive`; the code writes `accepted` and `signed`; template resolution reads `status` **never**. | `AppController.php:10843`, `:10853`; `OfferAcceptController.php:71`; `NdaSignController.php:93`; `LetterController.php:63-68` | After a candidate accepts, the HR list shows a status the UI cannot represent; deactivating a template does not stop it being used. |
| **NEW-DEFECT-07** | **MEDIUM** | Screen-help key collision. `content()` merges `roadmapModule()` then `mainModule()` with `array_merge`; both define `notifications`. `mainModule`'s thinner entry wins and the richer roadmap entry is dead code. | `ScreenHelpController.php:1070-1072`, `:897`, `:2082` | Confirms the override risk the reference doc rated only "INFERRED". |
| **NEW-DEFECT-08** | **LOW** | `ScreenHelpController::fallback($key)` takes a screen key and never uses it, returning one identical generic entry — contradicting the docblock's "unknown screens fall back to their module's generic guide". | `:1055-1066` vs `:10-13` | |
| **NEW-DEFECT-09** | **MEDIUM** | The dashboard's first tile ships a deliberate colour test marker with an un-actioned revert instruction, on every client. | `DashboardController.php:157-160` | A production canary left in a shipped release. |
| **NEW-DEFECT-10** | **MEDIUM** | `DashboardController::index()` + `dashboard.blade.php` are dead code: no route reaches them, the layout calls ten unregistered route names (`RouteNotFoundException` on render), and the blade reads the legacy employee schema (`first_name`, `last_name`, `employee_code`, `gross_salary`). | `routes/web.php:693-695`; `resources/views/dashboard.blade.php:100-105` | |
| **NEW-DEFECT-11** | **MEDIUM** | Six statutory settings are unreachable: present in `defaults()`, absent from `save()`'s validation array. | `SettingsController.php:29-73` vs `:141-164` | `contact_window_start/end` (the RBI lawful-contact window that merges into every letter as `{{contact_hours}}`) and `data_retention_months` (a DPDP retention period) are permanently pinned at code defaults. |
| **NEW-DEFECT-12** | **LOW** | Numeric settings have no upper bound. | `:140` | `pf_rate = 9999` is accepted and used by payroll. |
| **NEW-DEFECT-13** | **MEDIUM** | The two `offroll_agents` shapes conflict: migration has `company_id` NOT NULL and no `company_name`; the runtime creator and the master def have `company_name` and no `company_id`. | `2026_01_01_000500_…:13-26` vs `RecruitmentController.php:651-666`, `MasterController.php:211-216` | Which one an install has depends on whether the migration ran before the runtime path fired. |
| **NEW-DEFECT-14** | **LOW** | The hiring-drive funnel query uses double-quoted SQL string literals. | `HiringDriveController.php:97-103` | Under MySQL `ANSI_QUOTES` the drives list errors. |
| **NEW-DEFECT-15** | **LOW** | Validation of the same concepts differs between the two interview surfaces: `score` is validated 0–10 in `saveInterview` and written with no bounds in `updateCandidate`; `recommendation` is enum-validated in one and free text in the other; `outcome` is documented as a four-value enum and never validated. | `RecruitmentController.php:482-483` vs `HiringDriveController.php:302-313` | |
| **NEW-DEFECT-16** | **LOW** | The generic master import template ships a sample row for exactly one of 58 masters (`geofence`); the other 57 get a header and a blank line. | `MasterController.php:1172-1183` | |
| **NEW-DEFECT-17** | **MEDIUM** | `documents` rows created through the `documents` **master** screen carry no `file_path`, so `download()` 404s on them and `has_file` is false — two screens write the same table with incompatible expectations. | `MasterController.php:204-210` vs `DocumentController.php:235-248`, `:272` | |
| **NEW-DEFECT-18** | **LOW** | `BR-CMP-709`'s DRA gate on `agent-auth` always warns and never blocks, even though a `dra_gate` setting with an explicit `block` value exists and is honoured at other money points. | `MasterController.php:805-810` vs `SettingsController.php:57` | The configured policy is ignored on the one screen that grants the authorisation. |

### 6.2 Conflicts between parts of the system

1. **The SPA and the backend disagree about which masters exist** — `letters-nda` (DEFECT-DOC-04), and the `experience`/`custom` letter types that can be templated but never issued.
2. **The compliance module and recruitment disagree about who an agent is** — compliance keys on `employees.id`; recruitment can create an agent who is only an `offroll_agents` row.
3. **Points and payroll disagree about what a working day and a late arrival are** — payroll honours Shifts, Late Policy, `weekly_off_day` and `sat_off_mode`; points honours none of them.
4. **The NDA PDF and the NDA signing page disagree about the document text and about where designation/department come from** (FK join vs string columns).
5. **The importer and the messaging module disagree about what a mobile number is.**
6. **The report layer and the payroll engine disagree about the salary structure** — `fnf` assumes basic = 50 % of gross regardless of `salary_components`.
7. **`ReportController::export` and `Audit::record` disagree about how the audit log is written**, in the one report whose purpose is to verify that log.
8. **Two settings stores overlap in intent** — `statutory_settings` (a flat blob, not effective-dated, not audited) and `statutory_configs` (scoped, effective-dated, audited). Both hold PF/ESI/PT/TDS/LWF values.

### 6.3 Technical debt specific to this area

| Item | Detail |
|---|---|
| **Runtime DDL** | 71 `Schema::create()` calls under `app/`; 28 tables in this area have no migration; `ALTER TABLE` runs inside the WhatsApp send loop, on every public token page hit, and on every master list. |
| **The `def()` monolith** | 463 lines of nested array literal governing 58 screens, 54 tables and the entire master-data contract. |
| **`ScreenHelpController`** | 2 206 lines / ~236 KB of product copy in a controller, re-materialised on every request with no cache. |
| **Swallowed exceptions** | Empty `catch (\Throwable $e) { }` at `RecruitmentController.php:789-791` (fill reconciliation), `HiringDriveController.php:77-78`, `:323-324`, `RecruitMessagingController.php:167-168`, `:175-176`, `:203-204`, `:345-346`, `:412-414` (the entire webhook), `LetterController.php:152-154`, `:185-186`, `:278-280`, `DocumentController.php:66-68`, `:300-301`, `:321-322`, `ReportController.php:274-275`, `:315-316`, `:491-493`, `MasterController.php:549`, `:631-632`, `:749-752`, `:889-891`, `:927-929`, and throughout `SeedIndustryContent.php`. |
| **N+1 queries** | `compliance-scorecard` (~5 queries per employee inside the row map); `KbController::reorder` (one UPDATE per id); `importCandidates` (one INSERT per row); `RecruitMessaging::send` (one `wa_log` SELECT per failure, one `hasColumn` pair per recipient); `MasterController::list` (two full `companies` queries per call, one of them untenanted). |
| **Unbounded reads** | `board()` loads every `interviews` row in the tenant; `campaign()` and drive `show()` load full child sets with no limit; `ReportController::export()` builds the entire CSV as one PHP string in memory; `KbController::index()` loads every article. |
| **Magic numbers** | `SHIFT_START_MIN = 570`; 2 000 (campaign cap), 2 000 (board cap), 2 000 (manager picker), 500 (pool cap), 500 (import batch), 400 (ledger chunk), 20 000 (import row cap), 200 (drive/campaign/mail-log list caps, and the document size back-fill batch), 100 (preview limit), 8 (search results), 2 (search min length), 100 MB / 10 MB / 5 MB / 2 MB (quotas), `12/26/8` and `×2` (overtime), `0.5` and `15/26` and `5` (FnF), `50` and `1 200 000` (labour-code check), `60` (compliance minimum, duplicated), `0.12` (icon padding), `40` (token length), `-10` (mobile digits). |
| **Dead code** | `DashboardController::index()` + `dashboard.blade.php` + `layouts/app.blade.php`; `letters.pdf_path`; `recruitment.applied_on` (written, never read); the roadmap `notifications` help entry; `config('smartprs.roles')` (never read); `RecruitMessagingController`'s unused `Str` import `:10`. |
| **Duplicated implementations** | Two NDA default bodies; two merge engines with different field sets (21 keys vs 6); two CSV writers (`csvLine()` vs `fputcsv`); `kb_topics` created in three places; the screen-help alias table written out twice (`:35`, `:45`); `ensureCols()` for the token columns written three times (`LetterController`, `OfferAcceptController`, `NdaSignController`). |
| **No TODO/FIXME markers** | `grep -rn "TODO\|FIXME\|XXX\|HACK"` over the files in scope returns nothing; known issues are recorded as dated prose comments ("7 Aug 2026 test report (item 2)", "rev181 —", "Ejaz live test"). |
| **Test coverage** | Two test files, 123 lines total, covering: department create by admin, department create denied to an employee, holiday date, `emp_map` FK resolution, offer-letter `fixed` pinning, template save, and a merged-PDF round trip; plus report preview columns and CSV content type. Nothing tests the token flows, points, performance, the importers, the settings save, or any report other than `employees`. |

---

## 7. Security findings

### 7.1 Findings

**FINDING-H-01 · HIGH · `MasterController::list()` has no authorisation check at all**
*Location:* `MasterController.php:608-701`; `routes/web.php:660`.
*Detail:* every sibling method guards (`save` `:709-713`, `import` `:1071`, `delete` `:1194`); `list()` guards nothing and merely **returns** `canManage` as a flag for the UI. Any authenticated user of the tenant — `employee`, `field_agent`, or a role with no matrix — can `GET /app/master/{type}` for all 58 types.
*What that exposes:* `wa-settings` → the Interakt **`api_key`** verbatim; `sms-settings` → the SMS provider `api_key`, `sender_id`, `dlt_entity_id`; `biometric-devices` → device `comm_key`, IP and port; `banks` → `acc_no` and `ifsc`; `complaints` → the entire complaints register (which the def comments describe as possibly anonymous); `deductions`, `overtime`, `dra-certs`, `bgv`, `agent-auth`, `min-wages`, `salary-setup`, `letters-warning` (the list of who has been disciplined), `points-ledger`, `performance`, `offroll-agents`, `policies`. The two self-serve masters (`helpdesk`, `posh`) are the *only* two with a read filter.
*Exploitability:* trivial — one authenticated GET per master type.
*Business impact:* a field agent can lift the tenant's WhatsApp and SMS credentials and send from them; can read the disciplinary and complaints registers; can read colleagues' bank account numbers.
*Severity:* **HIGH** (credential exposure + sensitive-HR-data exposure).

**FINDING-SEC-01 · HIGH · Any authenticated user can read any letter, ID card or photo in the tenant**
*Location:* `routes/web.php:495`, `:501`, `:505` + `LetterController::pdf()` `:24-32`, `idcard()` `:261-327`, `servePhoto()` `:437-451`.
*Detail:* these three methods carry no `denyUnlessRole` and no route middleware beyond `auth`; every sibling (`email`, `sendAcceptLink`, `uploadPhoto`) *is* guarded, so the omission is inconsistent rather than deliberate.
*Exploitability:* enumerate `/app/letters/{id}/pdf` for `id = 1..n`, and `/app/idcard/{code}/pdf` for every colleague's code (obtainable from the equally ungated `/app/search`).
*Business impact:* exposure of colleagues' warning letters, increment letters and CTC, offer terms, and PAN / UAN / bank account / IFSC — all of which are letter merge fields — plus every employee's photo, designation, DOJ and mobile.

**FINDING-SEC-03 · HIGH · The NDA e-signature endpoint is CSRF-exempt and validates nothing**
*Location:* `routes/web.php:63-64`; `NdaSignController::sign()` `:85-102`; `resources/views/nda-sign.blade.php:47-50`.
*Detail:* the `required` attributes on the name field and the consent tick are **HTML-only**. `sign()` does not require `name`, does not check the tick, and does not reject an empty body — `signed_name` falls back to the existing value or NULL. The route is explicitly exempted from CSRF.
*Exploitability:* a bare `POST /nda/{token}/sign` with no body, from anywhere, signs the undertaking. Combined with no token expiry and no single-use, a leaked link is a permanent signing capability.
*Business impact:* the artefact the RBI audit story rests on can be produced without the signer's involvement.
*Contrast:* the offer route's blade *does* emit `@csrf` and the route is not exempted — so the exemption on the NDA route is an inconsistency, not a design.

**FINDING-SEC-04 · HIGH · IDOR — any `employee` can download any employee document**
*Location:* `DocumentController::download()` `:263-281`.
*Detail:* the guard admits `admin, hr_manager, employee`, then fetches by `id` + `tenant_id` only. There is no ownership check.
*Business impact:* every colleague's educational certificates, PAN copies, prior payslips and relieving letters are enumerable by sequential id.

**FINDING-SEC-12 · HIGH · Cross-tenant IDOR on performance reviews**
*Location:* `PerformanceController::advance()` `:107`, `:129`.
*Detail:* `SELECT` and `UPDATE performance_reviews WHERE id = ?` with **no tenant filter**. `board()` is correctly scoped; `advance()` is not.
*Business impact:* any admin or HR manager of any tenant can read and rewrite any other tenant's performance ratings and comments by id.

**FINDING-SEC-13 · HIGH · The knowledge base — including the Code of Conduct — is global and cross-tenant writable**
*Location:* `KbController.php:49`, `:89-92`, `:103`, `:112`, `:128`; `SeedIndustryContent.php:632`.
*Detail:* `store()` never sets `tenant_id`; `ensureSeeded()` and `seedGlobalKb()` write `NULL` explicitly; `index/update/destroy/reorder` all query by id with no tenant filter.
*Business impact:* an admin of tenant A can rewrite or delete the four *Code of Conduct* articles that every tenant's agents legally acknowledge — the exact content `CodeOfConductController` presents for acknowledgement.

**FINDING-SEC-05 · MEDIUM · Cross-tenant template disclosure on the public NDA page**
*Location:* `NdaSignController::renderBody()` `:47-49` (template lookup) and `:44` (employee lookup) — neither is tenant-scoped.
*Detail:* `LetterController::buildLetter()` was explicitly hardened for exactly this in rev165 (`:56-60`, with the comment "so a crafted `template_id` can't merge another tenant's letter body into the PDF"); the NDA page was not.
*Business impact:* a crafted `template_id` on an NDA letter merges another tenant's template body into the public signing page.

**FINDING-SEC-06 · MEDIUM · Unescaped template body rendered into the letter PDF**
*Location:* `resources/views/letter-pdf.blade.php:43` — `@if($bodyIsHtml){!! $body !!}@endif`.
*Detail:* admin-authored bodies are stripped by `stripHtmlDeep()`, but any path that inserts a body without it — the seeder does, and direct DB access does — reaches dompdf unescaped.
*Undetermined:* **there is no `config/dompdf.php` in the repository**, so the effective `isRemoteEnabled`, `chroot`, `allowedProtocols` and `enable_php` are package defaults and cannot be established from source. This is required to size the finding.

**FINDING-SEC-07 · MEDIUM · The signed artefact is not immutable and carries no integrity evidence**
*Location:* `LetterController.php:53-68` (merge at render), `OfferAcceptController.php:71-73`, `NdaSignController.php:92-98`.
*Detail:* no document hash, no stored PDF, no version pin, and **no `activity_logs` row** for either acceptance or signature. Contrast the salary voucher, which prints `ack_name`/`ack_at`/`ack_ip` against a stored line, and the agent audit report, which computes a report hash.
*Business impact:* the hash-chained audit trail — which this area's own `audit-trail` report exists to verify — cannot evidence that anyone accepted or signed anything.

**FINDING-SEC-11 · MEDIUM · Unsafe upload validation on the document tracker**
*Location:* `DocumentController::upload()` `:171`, `:214-220`.
*Detail:* the only Laravel validation is `employee_id => required`. The extension check runs on `getClientOriginalExtension()` — the **user-supplied filename** — with no MIME check and no `mimes:`/`file` rule. A `.pdf`-named PHP file is accepted.
*Mitigation in place:* stored with a Laravel hashed name under `storage/app/public/employee-docs/` and served only through `response()->download()`.
*Residual:* depends on whether the public disk is symlinked into a PHP-executing path; the class docblock says it is not (`:15-16`).

**FINDING-SEC-14 · LOW · CSV injection in report exports**
*Location:* `ReportController::csvLine()` `:507-518`.
*Detail:* a cell beginning `=`, `+`, `-` or `@` is written verbatim and evaluated as a formula by Excel. Report cells include employee names, notes and audit-log detail — all user-controlled.

**FINDING-SEC-16 · MEDIUM · Unauthenticated, unverified, unthrottled webhook mutates delivery state**
*Location:* `routes/web.php:200-204`; `RecruitMessagingController::interaktWebhook()` `:380-417`.
*Detail:* public, CSRF-exempt, no signature, no shared secret, no throttle. Status is derived by string-searching the whole JSON payload; the target row is selected by `wa_message_id` **or** by the last 10 digits of a phone number, **with no tenant or campaign scope**, taking the highest-id match across the installation. Everything is wrapped in an empty catch and always returns `{"ok":true}`.

**FINDING-SEC-17 · LOW · Public token routes are unthrottled**
*Location:* `routes/web.php:57-64` — none of `/offer/{token}`, `/offer/{token}/accept`, `/nda/{token}`, `/nda/{token}/sign` carries `throttle`. Contrast the public sample-PDF route, which does (`throttle:20,1`, `:111`).
*Detail:* token brute force is infeasible at ~238 bits, but each hit runs `Schema::hasColumn` probes and an unindexed `WHERE accept_token = ?` scan on `letters` — free unauthenticated query amplification.

**FINDING-SEC-18 · LOW · The tokenised URL is echoed in the API response**
*Location:* `LetterController.php:430` — `{'ok':true, 'message':…, 'link': <url>}`.
*Detail:* the raw signing URL lands in browser history, proxy logs and any client-side logging of anyone who can call the endpoint.

**FINDING-H-02 · MEDIUM · SMTP passwords stored in recoverable plaintext**
*Location:* `ConfigController::mailSave()` `:135-143`; `mailConfigFor()` `:74-84`; consumed at `LetterController.php:240` and `ConfigController.php:202`.
*Detail:* no `Crypt::encryptString`. The UI masks the value on read (`:98-104` blanks it and returns `hasPassword`), so it is not exposed through the API — but it sits in `app_settings.value` in the clear, in the nightly backup zip, and in any DB dump. Contrast `payment_gateways.secret`, which the companion migration explicitly marks "encrypted at app layer".

**FINDING-H-03 · MEDIUM · Configuration read endpoints are ungated**
*Location:* `SettingsController::index()` `:126-133`; `ConfigController::mailIndex()` `:86-117`; `ConfigController::brandingIndex()` `:379-401`; `DashboardController::stats()` `:35-49`; `DashboardController::search()` `:198-225`; `ScreenHelpController::show()` `:28`.
*Detail:* all read-only, none role-guarded. `stats()`'s `hasRole('super_admin')` is a branch selector, not a gate — a `tenant_id IS NULL` non-super-admin falls through to `companyCards(null, …)` where every `->when($tid, …)` is skipped, producing **installation-wide totals**, and `activeNotices(null)` returns every tenant's notices including private ones.
*`search()` specifically:* no role guard and no self-scope, so an `employee` whose directory access is otherwise narrowed to their own row can enumerate every colleague's code, name, email and mobile, eight at a time, with a leading-wildcard `LIKE` on four unindexed columns.

**FINDING-H-04 · LOW · Cross-tenant error-message leakage in the WhatsApp send loop**
*Location:* `RecruitMessagingController.php:191` — `DB::table('wa_log')->where('template', $tpl)->orderByDesc('id')->value('error')`, scoped to neither tenant nor message.
*Detail:* on a send failure the recorded error can be another tenant's most recent failure for the same template name.

**FINDING-H-05 · LOW · Raw exception messages returned to the client**
*Location:* `ReportController.php:450`, `:502` (HTTP **200** with the raw message); `RecruitmentController` every catch; `HiringDriveController`, `RecruitMessagingController`, `DocumentController`, `KbController`; `DashboardController.php:47`.
*Contrast:* `MasterController::list()` and `::save()` were explicitly hardened for exactly this (`:694-700`, `:825-836`) — log the detail, return a generic message. That pattern is not applied anywhere else in this area.

**FINDING-H-06 · MEDIUM · POSH register has no Internal-Committee boundary**
*Location:* `MasterController.php:129-143`, `:585-589`, `:646-649`.
*Detail:* a non-manager sees only their own rows, but **every** `admin` and `hr_manager` in the tenant reads every POSH complaint. There is no Internal Committee role in `KbController::ROLE_OPTIONS` or anywhere else, and nothing prevents a respondent who is an HR manager from reading the complaint about themselves.

**FINDING-H-07 · MEDIUM · No audit on settings, branding or SMTP changes**
*Location:* `SettingsController::save()` `:198-201`; `ConfigController::mailSave()` `:143`; `brandingSave()` `:434`; `LandingController::save()` `:41-44`.
*Detail:* master data is audited through `Audit::record()` (`MasterController.php:792-800`) and `statutory_configs` is audited through `AuditTrail::log`. Changing the PF rate, the TDS slabs, the LOP basis or a company's outbound mail server writes **no audit entry at all**.

### 7.2 What was checked and found clean

- **No SQL injection.** Every raw fragment in this area uses literal SQL or bound parameters: `ReportController.php:209`, `HiringDriveController.php:97`, `:227`, `RecruitMessagingController.php:268`, `:275`, `PointsController.php:212`, `SeedIndustryContent.php:286` (`SHOW COLUMNS` with a hard-coded table name). `MasterController` interpolates `$table` into two raw statements (`:526`, `:528`, `:543-547`) but the value comes only from the controller's own `def()` literals.
- **No `exec`, `shell_exec`, `system`, `proc_open`, `unserialize` or `eval`** in any file in scope.
- **No hard-coded credential, API key or private key** in any file read for this area.
- **Blade output** in `offer-accept.blade.php` (all `{{ }}`) and `nda-sign.blade.php` (`{!! nl2br(e($body)) !!}` — escape then convert, safe) is correct. `letter-pdf.blade.php:43` is the one deliberate unescaped output (FINDING-SEC-06).
- **Photo and document storage** use `store()`, which generates the filename — no user-controlled path.
- **Token entropy** is cryptographically ample: `Str::random(40)` over base62 ≈ 238 bits.

---

## 8. Documentation-vs-source discrepancies

### 8.1 Where the reference documents are wrong

| # | Doc claim | Source | Correction |
|---|---|---|---|
| 1 | *"the base migration [for `letters`] is not present in this repo"* and, in the debt table, *"**No `letters` migration** — the table is created only by `SeedIndustryContent::ensureLettersTable()`"* (§6.2 and §13.2). | `database/migrations/2026_01_01_000600_create_salary_runs_table.php:112-121` | **A `letters` migration exists.** It creates `id, tenant_id, company_id, employee_id, letter_type, issued_on, status, pdf_path, timestamps` via the file's private `mk()` helper (which is why a `Schema::create('letters'` grep misses it). The doc's substantive point survives — every *functional* column is still runtime-added — but the stated fact is wrong, and the migration reveals two things the doc missed: `employee_id` is created **NOT NULL** (explaining the `make_letters_employee_nullable` migration the doc calls orphaned), and **`pdf_path` exists and is never used**, which is direct evidence that a stored-PDF snapshot was designed and never built (the exact fix DEFECT-DOC-03 needs). |
| 2 | Screen-help module override is *"`INFERRED` risk — no duplicate key was observed, but nothing prevents one"* (§11.2). | `ScreenHelpController.php:897` (`roadmapModule`) and `:2082` (`mainModule`) | **There is a live duplicate.** `notifications` is defined twice; `array_merge` lets `mainModule()`'s thinner entry win and the richer roadmap entry is dead. Confirmed, not inferred. |
| 3 | *"314 top-level screen-key entries across `content()`, `whys*()` and `mistakes()` combined"* (§11.2), derived from a naïve grep. | programmatic parse per function | The grep count includes false positives (`adv`, `f`, `s`, `r` — array keys inside entries). The real figures are **120 unique content screens, 119 `whys` entries, 73 `mistakes` entries** (312 total, one of them a duplicate). |
| 4 | §10.3 (a) records the training-programme count as *"eleven programmes"* with a footnote hedging that the summary line refers to what is inserted on a given run. | `SeedIndustryContent::programs()` `:385-446` | The array holds **12** programmes. The "11" in the seeder's own docblock (`:14`) is simply wrong, and the doc reproduced the hedge rather than the count. |
| 5 | The doc's `letters` status table omits the `draft` value's source and does not note that `letters.status` is written by two vocabularies. | `AppController.php:10843`, `:10853` | The issued-letter dropdown (`draft/issued/signed`) and the template dropdown (`active/inactive`) write the **same column**; template resolution reads it never. This is a schema conflict, not just an unrepresentable value. |
| 6 | The doc lists `teams`, `helpdesk`, `training_*`, `faqs`, `code_of_conduct_ack`, `messages_log`, `wa_settings`, `sms_settings`, `sms_templates`, `point_rules`, `points_ledger`, `documents` among *"No migrations for the compliance registers … all created at runtime"* (§13.2). | `2026_01_01_000100:93` and `2026_01_01_000600` | All of those **do** have migrations. The genuinely migration-less tables in this area are: `recruitment`, `job_openings`, `interviews`, `hiring_drives`, `hiring_drive_candidates`, `recruitment_campaigns`, `recruitment_messages`, `performance_reviews`, `posh_complaints`, `dra_certs`, `min_wages`, `policies`, `overtime`, `roster`, `onboarding`, `awards`, `notices`, `feature_flags`, `pay_cycles`, `leads`, `app_settings`, `statutory_settings`. |

### 8.2 Where the source contradicts its own comments, labels and UI

| # | The claim | Where made | What the code does |
|---|---|---|---|
| 1 | *"This offer link is invalid or **has expired**"* / *"This signing link is invalid or **has expired**"* | `OfferAcceptController.php:43`, `NdaSignController.php:75` | **No expiry exists.** No `expires_at` column, no TTL, no check. |
| 2 | *"draft → self-review → manager-review → finalized"*, describing an employee self-review | `PerformanceController.php:11-15` | Both endpoints are guarded `admin \| hr_manager \| super_admin`. **The employee cannot perform their own self-review**, and no ordering is enforced. |
| 3 | *"CANDIDATES … move through stages (applied → screening → interview → offer → hired/rejected)"* describing an enforced pipeline | `RecruitmentController.php:12-27` | `moveStage` enforces nothing beyond membership of the list, and has no side effects. |
| 4 | The `LetterController` docblock lists **15** placeholders | `:18-19` | The code substitutes **21**. The SPA field hint lists **12** (`AppController.php:10853`). Three different published lists. |
| 5 | *"unknown screens fall back to their module's generic guide"* | `ScreenHelpController.php:12-13` | `fallback($key)` ignores `$key` and returns one identical entry for every screen. |
| 6 | *"the signed status shows here and feeds Compliance"*, on the NDA screen | `AppController.php:10850` | The screen cannot save at all (DEFECT-DOC-04). |
| 7 | The `hiring_drive_candidates` column comments document `recommendation` as `strong_hire\|hire\|hold\|no_hire` and `outcome` as `pending\|selected\|rejected\|hold` | `HiringDriveController.php:67-68` | Neither is validated anywhere; any string is stored. |
| 8 | *"Points auto-award … Turns the (otherwise inert) Points Rules into action"* | `PointsController.php:10-11` | Accurate, but the docblock's stated matching rules ("contains 'perfect'/'present'/'attendance' → points once IF the employee had no lates/absences") are defeated by the `if/elseif` ordering it does not mention. |
| 9 | The `SeedIndustryContent` docblock says *"eleven training programmes"* | `:14` (implied by the command output line) | 12 are defined. |
| 10 | The dashboard test-marker comment instructs *"Revert to `'color' => '#3b82f6'` and drop bg/fg once the update has been seen to land"* | `DashboardController.php:157-159` | Not done; it ships. |
| 11 | The `MasterController` docblock says the controller covers *"Departments, Branches, Banks, Designations"* | `:11-12` | It covers **58** master types. The docblock was never updated past the first four. |
| 12 | `SettingsController`'s docblock says the table *"is created on first use (no migration required), matching the project's self-creating-table convention"* | `:20-22` | Accurate — and it is the clearest statement in the codebase that runtime DDL is a **deliberate project convention**, not an oversight. The same phrase appears in `MasterController.php:13-15` ("via self-creating columns (project convention), so it works on the deployed schema without a manual migration") and `LeadController.php:24`. |

### 8.3 Things the reference documents missed entirely

1. **`MasterController::list()` has no authorisation check** (FINDING-H-01). The doc's §3.8 describes the role gate as `canManage()` for the controller, and the security register for this section does not carry the finding; it is recorded only in a different section of the security report (`SECURITY_AND_TECHNICAL_DEBT_REPORT.md:1497`, `4151`) and never connected to the master engine's read path.
2. **The `letters.pdf_path` dead column** and what it implies about the intended design.
3. **The `performance_reviews` double declaration** — `PerformanceController::ensureCols()` and the `performance` master def create the same table with different column sets.
4. **The `documents` double declaration** — the `documents` master writes rows with no `file_path`, which `DocumentController::download()` then 404s on.
5. **`NEW-MST-114`, the Labour Code "Basic+DA ≥ 50 %" check** on salary-component saves, with its hard-coded ₹12 L sample CTC, 50 % threshold and regex-based DA detection. Not catalogued anywhere.
6. **`NEW-MST-121`** — `MasterController::list()` loads the `companies` map with no tenant filter *and* issues a second full companies query in the same response.
7. **The `dra_gate` setting is not honoured on the `agent-auth` screen** (NEW-DEFECT-18) even though a `block` value exists and is honoured at other money points.
8. **The `letters` status vocabulary conflict** between the two SPA dropdowns writing one column.

---

## 9. Migration verdict per capability

| Capability | Verdict | Rationale | Data migration implication |
|---|---|---|---|
| **Master-data registry (58 types)** | **REPLACE** | The single largest configurability gap in the product. Must become a metadata-driven entity registry: entity definitions, field definitions with types/validation/labels/visibility, per-entity permissions, referential rules and a deletion policy — all as data. | Migrate the 54 tables' rows into the new entity store, or keep them as physical tables behind a generated repository. The `def()` array is a usable seed for the SmartPRS 3 entity catalogue. Runtime-added columns must be inventoried per install first — the effective schema differs by install. |
| **Recruitment pipeline / ATS** | **REDESIGN** | The workflow is sound in shape (requisition → approval → pipeline → interviews → hire) and wrong in every particular: fixed stages, no transition rules, no custom fields, name-keyed identity, no enforcement of the recruitable flag. | Migrate `recruitment`, `job_openings`, `interviews` as-is into the new model; re-key hire idempotency onto a real identity before migrating; the `pool` flag becomes a proper candidate-status. |
| **Talent pool** | **RETAIN** (as a candidate status, not a flag) | The search and dedupe behaviour is useful; only the storage shape is wrong. | Same table; `pool=1` becomes `status='pool'`. |
| **Candidate importers (3 paths)** | **MERGE + REDESIGN** | Three importers with three dedupe rules and three field sets. SmartPRS 3 needs one mapping-driven importer with a saved column map per source, a preview, a dry run, and configurable dedupe and merge rules. | The ~90 header aliases in `importAliases()` are valuable seed data for the mapping library. |
| **Hiring drives** | **RETAIN → REDESIGN** | Genuinely differentiated for the Indian walk-in market and worth keeping. Needs validated enums, real time columns, a panel that references people rather than free text, capacity enforcement on slots, and reversible outcomes. | `panel_json`/`slots_json` unpack into child tables; `time_from`/`time_to` become TIME. |
| **Recruitment WhatsApp campaigns** | **REDESIGN** | Right idea, wrong plumbing: synchronous sending, no queue, no rate limit, no opt-out, no template arity check, no scheduling, one hard-wired provider, and a webhook that matches by phone number across the installation. | Campaigns and messages migrate cleanly. An opt-out/consent register must be **created and back-populated** from `contact_status IN ('not_interested','declined')` before any campaign runs on the new platform. |
| **Offer acceptance (public token)** | **REPLACE** | As an e-signature it does not meet the bar: no expiry, no single-use, no revocation, no identity proof, the document is not shown, the artefact is not immutable, and nothing is audited. | `letters.accept_token/accepted_at/accepted_ip/status` migrate as historical evidence only, explicitly marked as legacy-grade. |
| **NDA e-signature (public token)** | **REPLACE** | Same, plus CSRF-exempt, no server-side validation, cross-tenant template lookup, and **unreachable from the UI today**. | There will be almost no data to migrate — the screen has never been able to create a row. Treat existing NDA compliance scores as unearned. |
| **Letter templates + merge engine** | **REDESIGN** | The template/placeholder concept is exactly right and should be generalised: rich text, a customer-extensible merge dictionary, per-company templates, versioning with a signature-time snapshot and content hash, and a stored rendered PDF. | `letters` templates migrate; issued letters must be **rendered once and snapshotted** during migration, or their historical content is lost the moment a template is edited. |
| **Letter PDF layout / ID card** | **REPLACE** | Two fixed blades. Needs a document-layout designer (letterhead, header/footer, margins, page size, signature block, fonts) driven by configuration. | None — output only. |
| **Document tracker** | **REDESIGN** | Storage, quota and multi-upload are fine. Needs MIME validation, ownership-scoped download, configurable categories, a required-document checklist by role/grade, expiry-driven alerting and retention enforcement. | Files and rows migrate; back-fill `file_size`; reconcile the master-created rows that have no `file_path`. |
| **Performance reviews** | **REPLACE** | There is no appraisal product here — no scale, no cycle, no competencies, no weighting, no calibration, no employee participation, no downstream effect, and a cross-tenant IDOR. | Migrate rows as historical text. Nothing computed can be carried forward. |
| **Points** | **REDESIGN** | The ledger + rules shape is right; the rule engine is a keyword-matching accident that ignores the tenant's own shift, weekly-off and late configuration. | `points_ledger` migrates. `point_rules` must be re-authored against real event types — the free-text `event` cannot be mapped automatically. |
| **Knowledge base** | **REDESIGN** | Sound content model; must become tenant-scoped with a platform-content/tenant-override split, multi-role visibility, versioning, and acknowledgement-grade immutability for Code-of-Conduct articles. | Split the current global `kb_topics` into platform content and per-tenant copies. Any tenant that edited a global article has silently changed it for everyone — the split needs a human decision per edited article. |
| **Reports** | **REPLACE** | Fourteen hand-written queries with hard-coded columns is not a reporting capability. Needs a metadata-driven report builder over the entity model: user-selected columns, filters, groupings, saved views, scheduling, and multiple output formats. | None — read-only. The 14 dataset definitions become seeded standard reports. The statutory register layouts (Form B/D/E, bank advice, FnF) become **configurable statutory templates**, not code. |
| **Statutory registers specifically** | **REDESIGN as configuration** | Form B / D / E and the bank advice are legally-shaped outputs whose columns change by state and by amendment. They must be templates, not `if` branches. | Same. |
| **Settings (`statutory_settings`)** | **REDESIGN** | Right idea; wrong shape. A flat JSON blob with no effective dating, no audit, no upper bounds, no versioning and six keys that cannot be saved at all. Must merge with `statutory_configs` into one effective-dated, scoped, audited configuration store. | Migrate the blob into effective-dated rows with `effective_from` = the tenant's earliest payroll month. |
| **Per-company SMTP + branding (`app_settings`)** | **RETAIN → MIGRATE** | The per-company-with-tenant-fallback resolution is correct and worth keeping. | Encrypt the SMTP passwords in flight during migration. |
| **`settings.app_state` (the SPA blob)** | **REMOVE** | The entire working data set of 113 screens in one ungated, unversioned, last-write-wins JSON document — including the client-side role-permission matrix. | Nothing to migrate; it is a client-side cache masquerading as storage. Any data that exists **only** there must be identified per install before decommissioning. |
| **Dashboards** | **REPLACE** | Six fixed cards with a role check used as a branch selector, a cross-tenant fall-through, a shipped test marker, and a second dead dashboard. Needs a configurable widget model over the report/metric layer. | None. |
| **Global search** | **REDESIGN** | Correct concept, no authorisation, no self-scope, leading-wildcard `LIKE` on four unindexed columns. | None. |
| **Screen help** | **REMOVE from the application** | 2 206 lines of product copy in a controller: no editor, no table, not tenant-aware, not edition-aware, re-parsed on every request, with a live key collision. | Extract the 120 entries into a versioned content source (docs site, CMS, or a seeded `help_content` table) keyed by screen. The **copy itself is a genuine asset** — in several places it is the clearest statement of intended behaviour in the codebase — and must not be discarded with the mechanism. |
| **Industry content seeding** | **REDESIGN as a content pack** | The content is a real commercial asset (an India-specific collections & recovery curriculum, Code of Conduct, FAQ set and offer template). The delivery mechanism — a console command doing schema self-healing and writing globally-shared KB rows — is not. | Turn it into a versioned, per-vertical, per-tenant content pack that is installed, updatable, and forkable, with a clear original-vs-edited distinction. The `kb_topics` global-scope bug must be fixed **before** migration or the split becomes undecidable. |
| **Landing CMS / leads / policy pages** | **MERGE** (out of the HR product) | These are the marketing and sales surface of the SaaS business, not HR functionality. | `leads` migrates to whatever CRM surface SmartPRS 3 chooses. Policy pages become CMS content with a per-install override. |
| **PWA icon / manifest** | **RETAIN** | Small, self-contained, works. | None. |
| **Audit of master writes (`Audit::record`)** | **RETAIN → EXTEND** | The hash-chained audit on master CRUD is the best pattern in this area. It must be extended to settings, branding, SMTP, letters, token acceptance and signature — all of which write nothing today. | Existing chain migrates; the direct-insert writers (`ReportController::export`, `draGateWarning`) must be moved onto `Audit::record()` first or they keep polluting the chain. |

---

## 10. SmartPRS 3 requirements implied

Numbered, testable, and each traceable to a rule or defect above.

### Entity / metadata engine

1. The platform must support a **customer-defined entity (master) registry**: a tenant admin can create a new master type — name, table/collection, label, plural, icon, menu placement — without a code release. *(NEW-MST-101: 58 types are PHP literals.)*
2. Each entity must carry **field definitions as data**: key, label, data type, precision/length, required-ness, default, visibility, edit-ability, help text, and display order. *(NEW-MST-101, NEW-MST-104.)*
3. The field-type vocabulary must include at minimum: string, long text, rich text, integer, decimal *with configurable precision and scale*, money *with a configurable currency and rounding rule*, boolean, date, datetime, time, enumeration *(customer-defined values)*, reference-to-entity, reference-to-employee, file, and geo-coordinate. *(Today every decimal is `DECIMAL(12,2)` and every string `VARCHAR(255)`.)*
4. The engine must support **declarative field validation as configuration**: required, min/max, length, regex/format, allowed-value set, cross-field comparison, and uniqueness within a scope. *(There is none today beyond required-ness.)*
5. Reference fields must resolve by **id**, with name and code accepted only as lookup conveniences, never as the stored key. *(NEW-MST-109, DEFECT-REC-03.)*
6. The engine must support **per-entity, per-operation permissions** (read / create / update / delete / import / export), assignable to roles. *(NEW-MST-104, NEW-MST-105: one role set for writes on 58 entities, and reads are ungated.)*
7. Deletion policy must be **declared per entity** (soft, hard, archive, forbid) and must not be inferred from schema. *(BR-SEC-402.)*
8. The engine must enforce **referential integrity on delete**: block, cascade, or null, declared per relationship. *(Deleting a department today leaves `employees.department_id` dangling and `employees.department` untouched.)*
9. **No DDL may execute inside a request.** All schema change must be a versioned, reviewable, forward-only migration applied out of band. *(28 migration-less tables; `ALTER TABLE` inside the WhatsApp send loop and on public token pages.)*
10. Every field on every entity must be **indexable by configuration**, and every field the engine filters, joins or sorts on must be indexed by construction. *(§2.4.)*
11. Every entity write must be **audited with before/after values**, not just changed field names. *(NEW-MST-110 records field names only.)*
12. Multi-company attribution must be **explicit**: an entity that is company-scoped must require a company, never silently default to "the tenant's first company". *(NEW-MST-108.)*

### Workflow / pipeline engine

13. The workflow engine must support a **customer-defined stage list per process** (recruitment pipeline, onboarding, exit, performance cycle) with stage name, order, colour, and a terminal/non-terminal flag. *(NEW-REC-101.)*
14. It must support an **allowed-transition matrix** with per-transition conditions, required fields, and required approvals. *(BR-REC-006: any stage to any stage.)*
15. It must support **side effects bound to a transition** (create an employee, open onboarding, reconcile a requisition, send a letter, notify), declared as configuration rather than scattered across call sites. *(BR-REC-017 is called from four places and never from `moveStage`.)*
16. It must support **guard conditions that actually block** — a requisition that is not approved must be un-attachable, an interview outcome of `no_hire` must at minimum warn before an offer. *(BR-REC-004, BR-REC-020.)*
17. The approval engine must support **N-level, role-or-hierarchy-driven approval with value bands**, and must **re-open approval when a material field changes** on an approved record. *(BR-REC-002 is single-level admin-only; BR-REC-003 leaves openings and CTC freely editable after approval.)*

### Rule engine

18. The rule engine must support **event-triggered scoring rules** where the trigger event is chosen from a typed catalogue, never inferred from free text. *(BR-REC-036 buckets rules by `str_contains` on a name, so "Perfect attendance — no lates" is applied as a late penalty.)*
19. It must express a rule as **condition → quantity → multiplier**, e.g. "for each late arrival in the period, award N points", with the definition of *late* resolved from the **attendance engine**, not redefined. *(NEW-PTS-101: a hard-coded 09:30 that ignores Shifts, Late Policy and roster.)*
20. Any rule that reasons about a working period must obtain the **working-day calendar from one shared calendar service** honouring the tenant's `weekly_off_day`, `sat_off_mode` and holiday master. *(NEW-PTS-102: points assume Sunday-only.)*
21. Rule outputs must be **dated to the period they describe**, not the run date, and re-running a period must be transactional. *(NEW-PTS-103, NEW-PTS-104.)*
22. Rules must carry **effective-from/to dates and an active flag**, and must be scopable by company, department, grade or employee. *(`point_rules` has none of these.)*
23. The engine must support a **"warn / block / off" policy mode** per gate, resolved from configuration at the point of enforcement. *(A `dra_gate` setting with exactly these three values exists and is ignored by `agent-auth` — NEW-DEFECT-18.)*
24. Statutory threshold rules (e.g. "Basic+DA must be at least X % of wage gross") must take the **percentage, the sample basis and the component classification** from configuration. *(NEW-MST-114 hard-codes 50 %, ₹12 00 000 and a regex for "DA".)*

### Document / template engine

25. The template engine must support **customer-defined document types** — a customer can add "Appointment Letter", "Confirmation Letter", "Transfer Letter", "Show Cause" — each with its own issue screen generated from configuration. *(NEW-DOC-106: 7 types, 5 issuable, 1 registered-but-broken.)*
26. The **merge dictionary must be extensible**: any field on any entity in the model must be addressable as a placeholder, and the available placeholder list must be discoverable in the editor. *(BR-CMP-024: 21 fixed keys, unknown placeholders printed verbatim.)*
27. Templates must support **rich text** end-to-end, with sanitisation that preserves formatting. *(DEFECT-DOC-05: `stripHtmlDeep` destroys the seeded HTML template on first save.)*
28. Templates must be **versioned**, carry an **active/inactive state that is honoured**, and be selectable per company. *(BR-CMP-023 picks the newest and never reads `status`.)*
29. Issuing a document must **render and store an immutable artefact** — the merged content, a content hash, and the rendered PDF — at issue time. Re-printing must reproduce the stored artefact, never re-merge. *(DEFECT-DOC-03; `letters.pdf_path` shows this was designed and never built.)*
30. Document **layout must be configurable**: letterhead image, header, footer, margins, page size, orientation, fonts, signature block (including an uploaded signature image), and a per-company override. *(All fixed in `letter-pdf.blade.php` and `idcard-pdf.blade.php`.)*
31. Money and date **formatting in documents must be configurable** (decimals, lakh/crore grouping, amount-in-words, date format). *(BR-CMP-024 hard-codes `₹` + `number_format` with no decimals and `d M Y`.)*
32. ID card design must be a **configurable layout** (fields, size, orientation, front/back, QR or barcode). *(BR-CMP-027.)*

### E-signature engine

33. A signing link must have a **configurable TTL**, be **single-use** by default, be **revocable**, and **rotate on resend**. *(BR-CMP-022: none of these.)*
34. The signing page must display the **complete document being signed** — the merged body, all financial terms and all dates — never a summary card. *(DEFECT-DOC-01.)*
35. Signature must require **server-side validated consent**: a typed name matching a server-side expectation, an explicit consent record, and CSRF protection. *(FINDING-SEC-03.)*
36. Identity must be **proven before binding** — at minimum an OTP to the email or mobile on file. The pattern already exists elsewhere in the product (`SelfOnboardingController`'s public token routes implement OTP send/verify) and must be applied here. *(§4.6 item 11.)*
37. The evidence record must capture **document content hash, rendered artefact, timestamp, IP, user agent, the consent text version, and the verification method**, and must be written to the **tamper-evident audit chain**. *(FINDING-SEC-07: today it captures status, timestamp, IP and a typed name, and writes no audit row.)*
38. A token must resolve **only** to the document type it was issued for. *(DEFECT-DOC-02.)*
39. Public signing routes must be **rate-limited**. *(FINDING-SEC-17.)*

### Reporting engine

40. The platform must provide a **report builder over the entity model**: a user selects an entity, columns, filters, groupings, sort order and aggregations, and saves it as a named report. *(NEW-RPT-101: 14 hand-written queries.)*
41. Reports must be **shareable, permissioned, schedulable and deliverable by email**, with at least CSV, XLSX and PDF output. *(None of this exists; CSV only.)*
42. Statutory registers (Form B, Form D, Form E, bank advice, FnF working) must be **configurable statutory templates**, versioned by effective date, so an amendment is a configuration change. *(BR-RPT-005, BR-RPT-006, BR-RPT-007.)*
43. Every report must **paginate or stream**; no report may materialise its full result set in memory. *(BR-RPT-001: export passes `limit = null` and builds one PHP string.)*
44. Report row-level computations must **call the same engines the operational screens call** — the FnF report must use the payroll engine's salary structure, not a 50 % assumption; the compliance scorecard must read a pre-computed score, not issue five queries per employee. *(BR-RPT-006, BR-RPT-011.)*
45. Lookups against effective-dated statutory data (minimum wages) must **match on the applicable dimensions** — state, zone, category, effective date — not take a tenant-wide maximum. *(BR-RPT-004.)*
46. CSV output must be configurable for **encoding (BOM), delimiter and line ending**, and must apply **formula-injection escaping** by default. *(BR-RPT-013, FINDING-SEC-14.)*
47. Report execution and export must be **audited through the same audit service** as every other write. *(BR-RPT-012 bypasses it, in the report whose job is to verify the chain.)*
48. Report endpoints must return **correct HTTP status codes** and must never return a raw exception message. *(BR-RPT-014, FINDING-H-05.)*

### Configuration engine

49. All configuration must live in **one store** with a uniform contract: key, value, **scope** (platform / tenant / company / department / employee), **effective-from date**, and an **audit record of every change with before/after values and the actor**. *(Five parallel stores today; four of them unaudited and none but `statutory_configs` effective-dated.)*
50. Every configuration key defined in the platform must be **reachable through the UI**. A key that exists in defaults and not in the save contract is a defect the build must catch. *(NEW-CFG-102: six unreachable keys, two of them compliance-critical.)*
51. Numeric configuration must declare **minimum and maximum bounds** as part of its definition. *(NEW-CFG-103: `pf_rate = 9999` is accepted.)*
52. Configuration must support **versioning, diff and rollback**, and payroll recomputation must resolve values **as at the period being computed**, not as at now. *(No settings store is effective-dated except `statutory_configs`.)*
53. Secrets in configuration (SMTP passwords, provider API keys, device communication keys) must be **encrypted at rest** and never returned by a read endpoint. *(FINDING-H-02, FINDING-H-01.)*
54. Configuration read endpoints must be **role-gated**. *(FINDING-H-03.)*

### Branding and white-labelling

55. Branding must be resolvable **per company** and must cover: display name, tagline, logo, favicon/app icon, brand colour palette, document letterhead, document footer, and email sender identity. *(Today: display name, one colour, one logo, one tagline.)*
56. The public legal pages must be **content, not blades**, with a per-install override and a per-page effective date. *(NEW-LND-104.)*

### Content and help

57. In-product help must be **content in a store**, addressable by screen, versionable, editable by the vendor, overridable by a tenant, and **filtered by edition and by the modules the tenant actually has**. *(NEW-HLP-101: 120 screens of copy in a controller, identical for every tenant and edition.)*
58. Vertical content packs (training curricula, FAQs, Code of Conduct, letter templates) must be **installable, versioned, updatable and forkable per tenant**, with a clear "original vs edited" state so a pack update never overwrites a customer's edit and never leaks one customer's edit to another. *(BR-CMP-030, NEW-SEED-101: `kb_topics` is deduplicated by title across the entire installation and always written `tenant_id = NULL`.)*
59. Content presented for **legal acknowledgement** (Code of Conduct) must be immutable once acknowledged, versioned, and the acknowledgement must record which version was accepted. *(FINDING-SEC-13: any admin on the box can rewrite it.)*
60. Any content the platform describes as **mandatory with a validity period** must be enforced by a control — a due date, an alert and a compliance consequence — or must not be labelled mandatory. *(NEW-SEED-103: `mandatory` and `validity` are stored and read by nothing.)*

### Messaging and consent

61. Bulk messaging must be **queued, chunked, rate-limited, resumable and schedulable**, with a per-tenant send window. *(DEFECT-REC-06: 2 000 synchronous HTTP calls in one web request.)*
62. A **consent and suppression register** is mandatory: an explicit do-not-contact flag per person per channel, honoured by every audience resolver, plus inbound STOP handling. *(BR-REC-033: none exists.)*
63. Message templates must be **validated for variable arity** against the provider's registered template before a campaign can be sent. *(BR-REC-028.)*
64. The messaging provider must be an **adapter behind an interface**, with channel (WhatsApp / SMS / email) selectable per campaign. *(Interakt is hard-wired.)*
65. Phone numbers must be stored and matched in **E.164 with an explicit country code**, using one normalisation rule across the whole platform. *(DEFECT-REC-04: two incompatible rules.)*
66. Provider webhooks must be **authenticated** (signature or shared secret), **scoped to a tenant and a message**, and parsed against a declared schema. *(FINDING-SEC-16: string-searching the whole payload, matched by phone number across the installation.)*

### Identity, numbering and conversion

67. Employee, requisition and document numbering must be a **configurable sequence** — prefix, suffix, width, reset period, and per-company or per-branch sequences — allocated from a transactional sequence source, never derived from `COUNT(*) + 1`. *(BR-REC-014, BR-REC-001: count-derived, non-transactional, and reused after deletion.)*
68. Person identity must key on a **stable identifier**, never a name. *(DEFECT-REC-03: hire, onboarding and offer acceptance all key on name.)*
69. **Candidate → employee conversion must be a configurable field map** — the customer decides which candidate fields flow to which employee fields, which are mandatory before conversion, and what is collected at conversion time. *(See §11 Q3; today the map is 12 fields in code and everything else is lost.)*
70. Conversion must not silently create master data. Creating a designation from a free-text candidate position must be an explicit, confirmed action. *(BR-REC-015.)*
71. **Off-roll agents must be first-class workforce members** for compliance purposes: same certification registers, same expiry alerting, same audit file, whatever their payroll status. *(DEFECT-CMP-01.)*

### Cross-cutting platform requirements

72. **Tenant scoping must be enforced by the data-access layer**, not by remembering to add `when($tid, …)` at each call site. Six confirmed unscoped queries in this area alone (`PerformanceController` `:107`/`:129`, `KbController` ×4, `NdaSignController` `:44`/`:48`, `MasterController` `:653`, `HiringDriveController` `:322`, `RecruitMessagingController` `:191`/`:399-407`).
73. **Authorisation must be declarative and default-deny.** Six endpoints in this area are ungated by omission while their siblings are guarded. A route with no declared permission must fail closed and fail the build.
74. **Row-level ownership** must be a first-class concept: "my documents", "my payslips", "my reviews", "my tickets". *(FINDING-SEC-04.)*
75. **No swallowed exceptions on a business path.** Every `catch` must either handle, or record and surface. *(20+ empty catches in this area, including the one that reconciles requisition fill and the entire delivery webhook.)*
76. Error responses must carry a **correct status code and a safe message**, with the detail logged. The pattern already exists at `MasterController.php:694-700` and must be the platform default.
77. Every list endpoint must **paginate**. Hard caps that silently truncate (2 000 board rows, 500 pool rows, 200 drives) must be replaced by paging with a visible total.
78. **Sensitive-field masking** must be declarative — a field marked secret is never returned by a read endpoint and never appears in an export. *(FINDING-H-01.)*

---

## 11. Open questions / PRODUCT OWNER DECISION REQUIRED

### The nine questions posed to this analyst — answered

**Q1. The master-data registry: how many master types, data-driven or hard-coded, can a customer extend it without code?**
**58 master types over 54 distinct tables, defined as a PHP array literal** in `MasterController::def()` (`MasterController.php:30-493`). It is a **hard-coded registry, not data-driven**. A customer can add *rows* to any of the 58; they **cannot** add a new master type, add or remove a field, change a label, change a field's type or precision, change which fields are mandatory, add validation, or change who may read or write a given master — every one of those is a code change. The only thing that is dynamic is the *physical schema*: `ensureCols()` creates the table and adds declared columns at runtime, which means the code change to add a field does not need a migration, but it is still a code change and a release.

**Q2. Recruitment pipeline stages — configurable or hard-coded? Own pipeline? Extensible candidate fields?**
**Hard-coded.** `private const STAGES = ['applied','screening','interview','offer','hired','rejected']` (`RecruitmentController.php:31`). So are the approval states (`:33`), interview modes (`:35`), interview statuses (`:37`), recommendations (`:39`) and requisition priorities (`:301`). A customer **cannot define their own pipeline** — not the stages, not their order, not their names, and not the transitions (there is no transition matrix at all: `moveStage` validates membership of the list and nothing else). **Candidate fields are not extensible**: the ~30 columns are declared in `ensure()` (`:100-114`) and there is no custom-field mechanism anywhere in the product.

**Q3. Candidate → Employee conversion: re-entered, mapped, or lost? Which fields survive?**
**Mapped, and heavily lossy.**

*Survive* (`createEmployeeFromCandidate` `:626-635`): `name`, `email`, `mobile`, `position` → `designation_id` (looked up, **auto-created if missing**), `offered_ctc` → `ctc`, `offer_doj` → `doj` (falling back to today), `company_id`. Supplied at conversion time by the Hire dialog: `department_id`, `branch_id`, `reporting_manager_id`, `salary_type` (default `only_salary`), `type` (default `office`).

*Lost* — held on the candidate and never copied: `location`, `experience_years`, `current_company`, `current_designation`, `current_ctc`, `expected_ctc`, `skills`, `resume_url`, `notes`, `interview_remarks`, `offer_incentive`, `joining_bonus`, `offer_notes`, `rating`, `source`, `applied_on`, and the entire interview history (which stays linked to the candidate, not the employee).

*Never captured at all* — so they must be re-entered later: `pan`, `uan`, `bank_acc`, `ifsc`, `pf_no`, address, date of birth, emergency contact, references, DRA/PCC status. The candidate table has no columns for any of them.

The consequence is that **a newly hired agent starts with an empty compliance file** and is immediately reported as non-compliant by the agent audit. For an **off-roll** hire the loss is worse: no employee record at all, no onboarding record, no seat consumed, `dra`/`pcc` captured as free strings that nothing reads, and complete invisibility to the compliance module.

Conversion is also **idempotent by employee name** (`:588-596`), so a second person with the same name is silently treated as already hired and never gets a record.

**Q4. Letter/document generation: template engine or hard-coded strings? Every letter type and where its body comes from. Can a customer edit a template without code?**
**There is a real template engine** — `letters.body` holds a reusable body with `{{placeholders}}`, and `LetterController::buildLetter()` merges it with live data via a single `strtr()` over 21 keys. A customer **can** edit a template body without code, through the *Letter Templates* master screen.

But the engine is bounded on three sides: **the placeholder list is code** (adding `{{probation_period}}` is a release), **rich text is impossible** (`stripHtmlDeep()` on the master save path destroys any HTML, and destroys the seeded HTML offer template on first edit), and **the letter type list is code**.

Letter types and body provenance:

| Type | Issue screen | Body source |
|---|---|---|
| `offer` | `letters-offer` ✓ | DB template (`is_template=1, letter_type='offer'`), auto-seeded per tenant as HTML by `SeedIndustryContent::seedOfferTemplate()` `:89-104`; sample text also in `LETTER_SAMPLES.offer` (`AppController.php:10714`) |
| `increment` | `letters-increment` ✓ | DB template; sample in `LETTER_SAMPLES.increment` |
| `warning` | `letters-warning` ✓ | DB template; sample in `LETTER_SAMPLES.warning` |
| `relieving` | `letters-relieving` ✓ | DB template; sample in `LETTER_SAMPLES.relieving` |
| `nda` | **registered in the SPA, missing from `MasterController::def()` — cannot be created** | DB template if one exists; else **hard-coded 5-clause default** at `LetterController.php:76-85`; the signing page uses a **different, shorter hard-coded default** at `NdaSignController.php:56`; sample in `LETTER_SAMPLES.nda` |
| `experience` | **none** — templatable, never issuable | sample in `LETTER_SAMPLES.experience` |
| `custom` | **none** | empty sample |

Non-letter documents generated by this area: the **employee ID card** (`idcard-pdf.blade.php`, fixed layout) and the **agent audit report** (owned by the compliance analyst). Both are blades, not templates.

Template resolution is: the chosen `template_id` (tenant-scoped) → **the newest** template of that type in the tenant → the NDA built-in → a 422. `letters.status` (`active`/`inactive`) is written by the UI and **never read**, so deactivating a template does not stop it being used.

**Q5. The public token flows: generation, entropy, expiry, replay, evidence, secure?**

| Property | Finding |
|---|---|
| Generation | `Str::random(40)` — Laravel's CSPRNG wrapper over `random_bytes()` (`LetterController.php:402`) |
| Entropy | base62 × 40 ≈ **238 bits** — cryptographically ample |
| Storage | plaintext in `letters.accept_token`, a plain string column, **no unique index**, **no index at all** |
| Expiry | **none.** No column, no TTL, no check. The 404 text on both routes claims an expiry that does not exist |
| Rotation | **none.** Re-sending reuses the existing token (`?:` at `:402`) |
| Revocation | **none.** No code path clears the token |
| Single use | **not enforced.** The token still resolves after acceptance; the page renders in its "already accepted/signed" state |
| Replay | the accept/sign writes are idempotent by status, so a replay is a no-op — but the token remains a permanent capability |
| Rate limiting | **none** on any of the four routes |
| CSRF | offer accept: protected. **NDA sign: explicitly exempted** (`routes/web.php:63-64`) |
| Server-side validation | offer: none needed. **NDA: none performed** — neither the typed name nor the consent tick is required or checked server-side |
| Type scoping | NDA route filters `letter_type='nda'`. **Offer route does not filter at all** — any non-template letter with a token resolves and can be "accepted" |
| Tenant scoping on lookups | correct on the token itself (it is the secret). **Incorrect** on `NdaSignController`'s employee lookup `:44` and template lookup `:47-49` |
| Evidence captured | `status`, `accepted_at` (server time), `accepted_ip` (`$request->ip()`), and for NDA `signed_name` |
| Evidence **not** captured | user agent, device, signature image, consent record, the document content, a content hash, a rendered PDF, any identity verification, **and any `activity_logs` entry** |
| Document integrity | **none.** The issued row stores a template pointer; the merge happens at render. Editing the template rewrites what was signed |
| Identity proof | **none.** Anyone holding the URL can bind the named person. The product already implements OTP on other public token routes (self-onboarding) and does not here |

**Verdict: not secure, and not adequate as a legal artefact.** The combination of no expiry + no single-use + no revocation + CSRF exemption + no server-side validation + no identity proof + a mutable document means a leaked NDA link is a permanent, unattended signing capability for a document whose text can change afterwards.

**Q6. Reports: how many, hard-coded or builder, can a customer add one or a column, paginated?**
**Fourteen datasets**, each a **hand-written query with a hard-coded column list** inside one 360-line `build()` method (`ReportController.php:58-417`). There is **no report builder**. A customer cannot add a report, add a column, remove a column, reorder or rename columns, add a filter beyond the fixed month + company pair, save a view, schedule delivery, or choose an output format other than CSV.

**Not paginated and not streamed.** Preview caps at 100 rows; **export passes `limit = null`** and builds the entire CSV as one PHP string in memory before returning it (`:470-479`). Two datasets make this worse: `reg-muster` ignores the limit entirely even on preview, and `compliance-scorecard` issues ~5 queries per employee inside the row map — a 500-agent export is ~2 500 queries plus a full in-memory CSV, synchronously, in one web request.

**Q7. Settings/configuration: enumerate what a tenant admin can change today, and where it is stored.**
See §5.1 for the full enumeration. In summary, a tenant admin can change:

- **30 of 36 statutory/policy keys** → `statutory_settings.value`, one JSON blob per tenant, self-created table, **no audit, no effective dating, no upper bounds**. Six further keys exist in code and **cannot be saved at all**.
- **Per-company SMTP** (7 fields) → `app_settings.ckey='mail'`, JSON map keyed by company id with `'0'` as the tenant default. **Password in plaintext.** No audit.
- **Per-company branding** (display name, colour, logo, tagline) → `app_settings.ckey='branding'`. No audit.
- **The tenant's app/PWA logo** → a file at `public/images/tenants/{tid}/applogo.png`.
- **The active financial year** → `settings.key='fy_active'`.
- **Everything in the 58 master screens** — including what a Product Owner would call configuration: the holiday calendar, leave types, shifts, late policy, salary components, pay cycles, incentive schemes, points rules, minimum wages, the policy repository, WhatsApp/SMS provider credentials and templates, biometric devices, letter templates, feature flags, and the company record's grievance-officer details.
- **Knowledge-base articles** → `kb_topics`, **installation-wide, not per tenant**.
- *(Super admin only)* the landing-page CMS → `settings.key='landing'`, and each tenant's `doc_storage_emp_mb`.

Not changeable by a tenant admin: anything in `config/` or `.env` (edition, licence, timezone, session, permission cache), the report definitions, the dashboard cards, the screen help, the document layouts, the letter type list, the placeholder list, the pipeline stages, and the master registry.

**Q8. Performance and points: what exists, configurable, used downstream?**
**Performance:** a four-state relay (`draft → self_done → reviewed → finalized`) over `performance_reviews`, with per-stage rating and comments and a free-text goals field. **Not configurable in any respect** — no rating scale (rating is unbounded `numeric`), no cycle definition, no competencies, no weighting, no calibration, no ordering enforcement. **The employee cannot participate**: both endpoints are HR-guarded, so HR types the "self-review" in. **Nothing downstream consumes `final_rating`** — not payroll, increments, incentives or compliance. `advance()` is not tenant-scoped (cross-tenant IDOR).

**Points:** rules (`point_rules`: event name, category, points, note) and a ledger (`points_ledger`). Rules are **partly configurable** — a customer sets the event name, the category and the point value — but the **trigger events are code**: five keyword buckets matched by `str_contains` on the rule's name, in a first-match-wins order that mis-classifies obvious rule names. Only attendance, awards and test passes can ever award points. The "late" definition is a hard-coded 09:30 that ignores the tenant's Shifts, Late Policy and roster; the working-day count assumes Sunday-only weekly offs, ignoring `weekly_off_day` and `sat_off_mode`.

**Downstream:** two consumers only — the leaderboard (`ComputedController` `points-scores`) and the incentive **eligibility gate** (`IncentiveController.php:199-212`), which uses the month's point sum against `points_gate_min` (default 0 = off) to decide whether an agent is *paid at all*. **Points never convert to money.** Note that the gate filters on `points_ledger.date`, which auto-apply stamps with the **run date** — so December's points computed in January never count towards December's eligibility.

**Q9. Screen help and industry seeding — what they are, product content or customer-editable, do they belong in SmartPRS 3?**

**Screen help** is 2 206 lines / ~236 KB of product training and sales copy — 120 screen entries, 119 "why it matters" arguments, 73 "common mistakes" lists — held as PHP array literals in a controller with one public method and no data access of any kind. It is **product content, not customer content**: there is no table, no editor, no write route, no tenant scoping and no edition awareness (an on-prem L1 client is served help for SaaS-only screens they cannot reach). It is re-materialised on every request with no cache, the alias table is duplicated in the same method, the fallback ignores its own parameter, and there is a live key collision that silently kills one entry.

*Does it belong in SmartPRS 3?* **The mechanism does not; the content emphatically does.** In several places this copy is the clearest existing statement of intended behaviour in the entire codebase — the *Roles & Permissions* entry documents the deliberate fail-open permission posture, the *Shifts* entry documents the 24-hour time requirement, and the *Commissions* mistakes list documents the exact confusion that causes ₹42 000 payouts instead of ₹840. It must be **extracted into a versioned, screen-keyed content store** that is vendor-authored, tenant-overridable, edition-filtered and cached — not deleted with the controller.

**Industry seeding** is a hard-coded India-specific Collections & Recovery content pack: 12 training programmes with four-paragraph objectives, 25 training subjects with genuine multi-paragraph course material, 17 FAQs, 16 Knowledge-Base articles (four of them the Code of Conduct agents legally acknowledge), and one offer-letter template. It is **product content installed as customer-editable rows** — idempotent by natural key, back-filling an empty description without overwriting an edit.

*Does it belong in SmartPRS 3?* **Yes as an asset, no as a mechanism.** The content is a real commercial differentiator and is grounded in the RBI Fair Practices Code, IIBF DRA norms, the DPDP Act and the legal toolkit. But the delivery is wrong in three ways: (a) it is **one vertical, hard-coded** — a customer outside collections gets it anyway and cannot supply their own; (b) `kb_topics` is written **globally with `tenant_id = NULL` and deduplicated by title across the entire installation**, so one tenant's edit changes the Code of Conduct for every tenant on the box; (c) it performs **schema self-healing** (`SHOW COLUMNS`, forced `->change()`) as part of content installation. SmartPRS 3 needs a **versioned, per-vertical, per-tenant content-pack mechanism** with install / update / fork semantics and a clear original-vs-edited state.

### Decisions a human must make

**PO-01 · Was `letters-nda` ever functional, or was the backend never written?** The SPA screen is complete and references a working backend; `NdaSignController`, the compliance NDA check and the RBI audit story all depend on NDA letters existing. `git` history is not in this snapshot. *Decision needed:* whether existing installs have NDA rows (created by some removed path or by direct SQL) and therefore whether any current compliance score is based on a real signature. **Until this is answered, every NDA component of every compliance score should be treated as unearned.**

**PO-02 · Are the existing token-based acceptances and signatures legally relied upon?** If any offer acceptance or NDA signature has been used in a dispute, an audit or a bank submission, the evidential gaps (no document shown, no identity proof, mutable document, no audit entry) are a live commercial risk, not just a design defect. *Decision needed:* whether to re-paper existing signatures on the new platform.

**PO-03 · Should off-roll agents consume a seat, and should they be compliance-tracked?** Today they consume no seat, get no onboarding, and are invisible to compliance — while being exactly the population RBI recovery-agent norms target. This is simultaneously a compliance hole and a revenue leak. *Decision needed:* the commercial and compliance treatment of off-roll workers in SmartPRS 3.

**PO-04 · What is the authoritative person identifier?** Hire, onboarding and offer acceptance all key on name. *Decision needed:* the identity key (PAN? UAN? email? mobile? an internal person id?) and the merge/duplicate-resolution policy, before any migration.

**PO-05 · What happens to KB articles that a tenant has edited?** Because `kb_topics` is global, an edit by tenant A changed the article for every tenant. Splitting the table into platform content and per-tenant copies requires a **per-article human decision** on each edited row: whose edit is it, and who else inherited it. This must be done before migration, not after.

**PO-06 · Which of the six unreachable settings are actually in use as defaults?** `contact_window_start`/`_end` (08:00–19:00) merge into every letter as `{{contact_hours}}` and are cited across the training content as the RBI rule; `data_retention_months` (84) is a DPDP retention period. They have been pinned at code defaults since they were written. *Decision needed:* confirm the defaults are correct as shipped, and whether any customer has been told they are configurable.

**PO-07 · What is the intended rating scale for performance?** There is none in code. *Decision needed:* scale, whether it is per-tenant, and what it must drive (increments, incentives, promotions) — requirement 42 and the whole performance verdict depend on it.

**PO-08 · Should points ever convert to money?** Today they are strictly a scoreboard plus an eligibility gate. The design pressure to make them a payout is obvious. *Decision needed:* explicitly yes or no, because it changes whether the points engine is subject to payroll-grade controls (approval, audit, immutability, recomputation).

**PO-09 · Which vertical content packs will SmartPRS 3 ship?** The current pack is India collections & recovery. *Decision needed:* whether that stays the only pack, whether customers can author packs, and whether pack updates may overwrite customer edits.

**PO-10 · Who owns the marketing surface?** `LandingController`, `LeadController` and `PolicyController` are the SaaS business's website, lead capture and legal pages living inside the HR product. *Decision needed:* whether they move out of the product entirely in SmartPRS 3.

### Undetermined from source

**UD-01 · dompdf configuration.** There is **no `config/dompdf.php`** in the repository, so `isRemoteEnabled`, `chroot`, `allowedProtocols` and `enable_php` are package defaults and cannot be established. Required to size FINDING-SEC-06 (unescaped template body rendered into the PDF).

**UD-02 · The production `letters` schema.** The migration creates 9 columns; at least 12 more are added at runtime by four different classes. The effective column list, nullability and indexing on any given install depend on which screens have been opened. Required: a production `SHOW CREATE TABLE letters`.

**UD-03 · `SubscriptionService::canAddEmployees` semantics** — what counts towards a seat, and whether off-roll agents ought to (they currently do not). Not inspected.

**UD-04 · `WaService::sendTemplate` return contract** — whether it returns or logs a provider message id, which would let `wa_message_id` be populated and fix the webhook matching. Not inspected.

**UD-05 · `App\Services\Audit` chain semantics** — whether a direct insert into `activity_logs` (as `ReportController::export` and `MasterController::draGateWarning` both perform) breaks chain verification from that row onward or is merely reported as `unsigned`. This determines whether the `audit-trail` report's own export action corrupts the thing it reports on. Not inspected.

**UD-06 · Scheduler and queue presence on client installs.** `MailService::queue()` dispatches to a queue; offer and NDA links, letter emails and lead alerts all go through it. If no worker is running, links are minted and never delivered — and the failure is silent. Required: deployment runbook confirmation.
