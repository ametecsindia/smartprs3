# AS-IS ANALYSIS — PAYROLL, SALARY STRUCTURE, SALARY RUNS, PAYSLIPS, LOANS, ADVANCES, INCENTIVES, COMMISSIONS, SCHEMES

## 0. Scope and evidence base

### 0.1 Reference-document sections read

| Document | Lines | Content |
|---|---|---|
| `COMPLETE_PROJECT_BUSINESS_AND_TECHNICAL_DOCUMENTATION.md` | 8549–11016 | Section 07 — Payroll, Statutory Compliance and Salary (read in full, incl. §4 "Every calculation and formula" 8849–9763 and §12 "Money handling" 10623–10723) |
| `BUSINESS_RULES_CATALOGUE.md` | 128–923 | Master index (786 rules); BR-PAY-*, BR-STAT-* extracted |
| `BUSINESS_RULES_CATALOGUE.md` | 924–13321 | Rules by area — every PAY / STAT / commission / loan / incentive rule |
| `BUSINESS_RULES_CATALOGUE.md` | 13321–13462 | Rules that are money-critical (123 rules; 70 in this area) |
| `BUSINESS_RULES_CATALOGUE.md` | 13501–13675 | Rules in conflict |
| `BUSINESS_RULES_CATALOGUE.md` | 3167–3179 | BR-PAY-204 detail (12-month back-fill) |

### 0.2 Source files read completely and verified

| File | Lines | Verified |
|---|---|---|
| `app/Http/Controllers/PayrollGenController.php` | 2845 | full |
| `app/Http/Controllers/SalaryApprovalController.php` | 899 | full |
| `app/Http/Controllers/SchemeController.php` | 1001 | full |
| `app/Http/Controllers/IncentiveController.php` | 371 | full |
| `app/Http/Controllers/BankPackController.php` | 374 | full |
| `app/Http/Controllers/PointsController.php` | 265 | full |
| `app/Http/Controllers/CalcGuideController.php` | 226 | full |
| `app/Http/Controllers/ComputedController.php` | 166 | full |
| `app/Http/Controllers/FinYearController.php` | 161 | full |
| `app/Http/Controllers/SalaryRunController.php` | 115 | full (dead code) |
| `app/Http/Controllers/LoanController.php` | 84 | full (dead code) |
| `app/Http/Controllers/PayslipController.php` | 22 | full (dead code) |
| `app/Services/PeriodResolver.php` | 323 | full |
| `app/Services/EffectiveDated.php` | 136 | full |
| `app/Services/PayrollLock.php` | 98 | full |
| `app/Models/SalaryRun.php` / `SalarySlip.php` / `Loan.php` | 26 / 32 / 40 | full (dead code) |
| `app/Console/Commands/PayrollPeriodAudit.php` | 178 | full |
| `resources/views/payslip-pdf.blade.php` | 213 | full |
| `tests/Unit/PayrollMathTest.php` | 104 | full |
| `tests/Unit/PeriodResolverTest.php` | 82 | full |
| `tests/Feature/PayrollTest.php` | 47 | full |
| Migrations `2026_01_01_000500` / `000600` / `000700`, `2026_06_24_000100`, `2026_07_26_000400` | 125 / 273 / 41 / 44 / 44 | full |

### 0.3 Source files read because the calculation actually lives there

The assigned file list does **not** contain the payroll calculator. `PayrollGenController` is an
orchestrator; every rupee is computed elsewhere. The following were read in the relevant ranges
and are load-bearing for this analysis:

| File | Ranges read | Why |
|---|---|---|
| `app/Http/Controllers/AppDataController.php` (3090 lines) | 440–470, 1147–1650, 1700–1800, 1818–2046, 2094–2440 | `computeSlip`, `computeSlipFromComponents`, `statutory`, `ptForGross`, `salaryTdsMonthly`, `newRegimeTax`, `payDateFor`, `esiPeriodLock`, `payslipViewData`, `payslipYtd`, `payslipGroupEarnings`, `payslipEmployerCost`, `statutoryPdf`, `ensurePayrollHistory` |
| `app/Http/Controllers/SettingsController.php` | 1–205 (full) | `defaults()` and `rates()` — the rate blob |
| `app/Services/StatutoryConfig.php` | 1–200 | the F1 effective-dated override layer |
| `app/Http/Controllers/StatutoryController.php` | 100–173 | gratuity + statutory bonus registers |
| `app/Http/Controllers/MasterController.php` | 140–200, 240–270, 440–570, 745–892 | runtime DDL for `salary_components` / `pay_cycles` / `salary_schedules`; OT valuation; Labour-Code check |
| `app/Http/Controllers/IncrementController.php` | 340–438 | backdated arrears |
| `app/Http/Controllers/ReportController.php` | 155–200 | wage register key matching |
| `app/Http/Controllers/ApprovalService.php` | 263–290, 365–381 | `safeRow()`, `denyUnlessRole()` |
| `app/Http/Controllers/AppController.php` (12533 lines) | 11161, 12073–12110, 10808, 3905 | browser-side PF/ESI/TDS duplicate engine |
| `routes/web.php` | 300–345, 420–510, 685–700 | route guards |
| `config/smartprs.php` | 118–132 | the money invariant claim |
| `database/migrations/2026_01_01_000300_create_attendances_table.php` | full | the ACTUAL payroll table definitions |

### 0.4 Verification stance

Where the reference documentation and the source disagree, the source wins and the discrepancy
is recorded in §8. I independently re-verified every material claim in doc §07 that I cite; the
doc is unusually accurate for this area. Three material findings in my area are **absent or
under-stated** in doc §07 and are recorded here as new: the fabricated 12-month payroll history
(§6 DEF-P01), the loan `outstanding` interaction (§6 DEF-P02, sharper than the doc's version),
and the missing `voucher-pdf` view (§6 DEF-P12).

---

## 1. Business capability inventory

### 1.1 Generate payroll for a company-month
HR picks a company, a month (`YYYY-MM`) and an on/off "apply Loss-of-Pay" switch. The system
reads every active employee's **annual CTC**, divides by 12 for a monthly gross, splits that
gross into salary components, scales it by an attendance factor, adds this month's approved
variable earnings, subtracts statutory deductions, then subtracts recoveries in a fixed order.
A **preview** shows every employee's numbers and writes nothing; **generate** writes a
`payroll_runs` header (status `draft`) plus one `payslips` row per employee, each carrying a
plain-English "how this was calculated" note.
Entry points: `PayrollGenController::preview()` `:2292-2331`, `generate()` `:2334-2845`.

### 1.2 Live Salary — "what have I earned so far this month?"
The same engine, capped at today's date, for one employee, plus a passbook-style entry log of
every credit and debit with an `included` / `pending` / `info` status. A manager sees their
direct reportees; HR/Admin see everyone. `liveSalary()` `:1501-1929`. A separate
"All Employees" overview totals full-month net vs earned-to-date, capped at 300 employees.
`liveSalaryAll()` `:1940-2069`.

### 1.3 Salary Simulator — what-if payslip
Any logged-in role can price a hypothetical CTC with chosen LOP days, employment stage, OT
hours, night shifts, commission, loan EMI and advance, using the real engine. Read-only.
`simulate()` `:2079-2243`.

### 1.4 Two-stage salary approval, with a per-employee lifecycle underneath
A run moves `draft → hr_approved → approved`, or is rejected at either stage. Separately, each
employee's *line* on the sheet can be held, put in review, approved, rejected, disbursed, and
finally acknowledged by the employee with a typed in-app e-signature (name + timestamp + IP).
`SalaryApprovalController` throughout.

### 1.5 Bank pack — NEFT disbursement file
Finance downloads a CSV in a generic layout or an ICICI / HDFC / SBI column order. Rows with no
account number or IFSC are excluded and listed separately. **Downloading the file is the
disbursement**: approved lines flip to `disbursed` and are stamped `bank_filed_at`.
`bankFilePreview()` `:416-441`, `bankFile()` `:444-514`, `bankFormat()` `:375-395`.

### 1.6 Payslip PDF
A branded A4 PDF: identity block, earnings grouped **A·Fixed / B·Variable / C·Reimbursement**,
deductions, a financial-year-to-date column (toggleable), an employer-contribution memo that is
never deducted, net in words, the salary period, and the calculation note. Employees can
self-download their own subject to a company policy switch (`all | none | dept | emp`).
`AppDataController::payslipPdf()` `:1147-1202`, `payslipViewData()` `:1232-1373`,
`resources/views/payslip-pdf.blade.php`.

### 1.7 Loans, advances and clawbacks — money coming back
Recovered on the payslip in a strict order after statutory deductions: **Loan EMI → Salary
Advance → Clawback**, with a hard net-never-negative floor. Loan EMI is full-EMI-only (never
partial); advances recover in the month they were approved; clawbacks are attempted once in
their due month and any shortfall stays open.
`loanEmiDue()` `:670-709`, `advanceRecovery()` `:869-887`, `clawbacksDue()` `:903-934`,
application `:1350-1389`.

### 1.8 Commissions and incentives on the payslip
Approved commission entries whose payout month matches, and whose payout method is not
`separate`, fold into the payslip as **one earning line per Purpose** (e.g. `Commission`,
`Incentive`, `Recovery Commission`, `Salary Arrears`). On generate every included entry is
**locked forever**, a `commission_payments` debit is written, and a history line is logged.
`commissionByEmployee()` `:587-658`, lock `:2695-2740`.

### 1.9 Incentive schemes — published offers agents claim against
Admin/HR (any target) or a manager/team-leader (own reportees only) publishes a scheme with a
rate type (`percent` of collections / `fixed` ₹ per claim / `open`), a validity window, optional
per-person caps (max claims, max gross), a payout method and an announcement across
email / WhatsApp / notice board. A non-admin's scheme waits for their own reporting manager's
approval. Editing a live scheme **re-prices every unlocked claim** and sends approved ones back
to Pending. `SchemeController` throughout.

### 1.10 Bulk incentive / commission calculator
Compute per-employee payouts from `collected` / `target` / `manual` figures via `flat` %,
`slab` bands or per-`portfolio` rates (CSV import supported), preview, then bulk-create
commission entries with TDS 194H netted, an optional payout date (or an automatic N-month
payout lag), a DRA-certification gate and a monthly points-threshold gate.
`IncentiveController::calculate()` `:38-69`, `commit()` `:72-282`, `payoutFor()` `:299-344`.

### 1.11 Bank / NBFC payout and billing pack
Per bank (the free-text `commissions.portfolio`) per month: a payout register, a TDS 194H
deductee annexure for the 26Q return, and a GST service invoice numbered `BPK/<FY>/nnnn` with
CGST+SGST vs IGST decided from the two GSTINs' state codes. `BankPackController`.

### 1.12 Financial year
India, 1 Apr – 31 Mar, labelled `YYYY-YY`. An "active FY" is stored per tenant; new money rows
are stamped with a runtime-added `fin_year` column; a per-FY summary counts rows and sums
amounts across seven money tables. `FinYearController`.

### 1.13 Points auto-award
Attendance-driven points from keyword-matched rules (`late` / `absent` / `perfect` /
`award` / `test`), idempotent per month. Points are a scoreboard — they gate incentive
*eligibility* but never convert to money. `PointsController`.

### 1.14 In-app salary calculation guide
Nine sections and 22 client-demo FAQs documenting the engine, served read-only to every role.
`CalcGuideController`.

### 1.15 Capabilities that appear to exist but do not
- **Reopening a finalised run.** Documented in `PayrollLock`'s docblock `:22-25`; no endpoint.
- **Paying a statutory bonus.** The register computes a provision; nothing writes it to a payslip.
- **A legacy Eloquent payroll stack** (`SalaryRun`, `SalarySlip`, `Loan`, `SalaryRunController`,
  `PayslipController`, `LoanController`) — unrouted, targets a schema that does not exist, and
  its Blade views are absent from the repository.

---

## 2. Data model

> **PROVENANCE IS THE HEADLINE FINDING OF THIS SECTION.** Of the columns the payroll engine
> depends on to compute money, **the majority do not exist in any migration.** They are created
> at runtime — by a master-data screen the admin may never have opened, or by
> `PayrollGenController::generate()` itself on first run. A fresh `php artisan migrate` produces
> a database that cannot run correct payroll.

### 2.1 Migration filenames do not describe their contents

Three of the five migrations assigned to me are **misnamed**. Verified by reading each file end
to end:

| Filename | What it actually creates |
|---|---|
| `2026_01_01_000500_create_loans_table.php` | `offroll_agents`, `agent_authorizations`, `assets`, `complaints`, `bgv`, `exits`, `escalations` — **no loans table** |
| `2026_01_01_000600_create_salary_runs_table.php` | `training_programs`, `training_records`, `training_subjects`, `tests`, `test_attempts`, `code_of_conduct_ack`, `faqs`, `letters`, `documents`, `wa_settings`, `sms_settings`, `sms_templates`, `company_emails`, `messages_log`, `notifications_feed`, `point_rules`, `points_ledger`, `helpdesk`, `increments` — **no salary_runs table** |
| `2026_01_01_000700_create_salary_slips_table.php` | `activity_logs`, `settings` — **no salary_slips table** |

The real payroll tables are all created by **`2026_01_01_000300_create_attendances_table.php`**
(header comment: `// TDD §4.4 — Payroll.`). There is no `salary_runs` or `salary_slips` table
anywhere; the live tables are `payroll_runs` and `payslips`.

### 2.2 `payroll_runs`

**Provenance:** migration `2026_01_01_000300_create_attendances_table.php:37-51`, then
widened and extended at runtime.

| Column | Type | Provenance | Read/written by |
|---|---|---|---|
| `id` | bigint PK | migration `:38` | everywhere |
| `tenant_id` | bigint idx | migration `:39` | scoping |
| `company_id` | bigint idx | migration `:40` | scoping |
| `schedule_id` | bigint null | migration `:41` | **never read or written** — dead |
| `lot` | int null | migration `:42` | written, **hardcoded to `1`** at `PayrollGenController:2542` |
| `cycle_label` | string | migration `:43` | the payroll month `YYYY-MM`; the idempotency key |
| `pay_date` | date | migration `:44` | `AppDataController::payDateFor()` |
| `status` | `ENUM(draft,locked,approved,paid)` → `VARCHAR(30) NOT NULL DEFAULT 'draft'` | migration `:45`, **altered** by `2026_06_24_000100_widen_payroll_runs_status.php:25` | the state machine |
| `employees_count` | uint | migration `:46` | `generate():2548` |
| `net_total` | decimal(14,2) | migration `:47` | `generate():2549` — set once, never corrected |
| `locked_at` | timestamp null | migration `:48` | set on FINAL approval `SalaryApprovalController:829` |
| `approved_by` | bigint null | migration `:49` | **never written** — dead (the workflow writes `fin_by` instead) |
| `hr_by`, `fin_by`, `remarks` | string null | **RUNTIME** — `SalaryApprovalController::ensureCols():49-56` | approval audit |
| `hr_at`, `fin_at` | timestamp null | **RUNTIME** — same | approval audit |
| `period_start`, `period_end` | date null | migration `2026_07_26_000400_add_payroll_period_columns.php:29-33` via `SchemaHelper::ensureColumns` (also duplicated as runtime code in `PeriodResolver::ensureSchema():222-227`) | F5 stored window |

The `2026_06_24_000100` migration exists precisely because the workflow writes `hr_approved`,
`rejected` and `acknowledged`, which the original ENUM rejected — on strict MySQL with
`SQLSTATE[01000] Data truncated`, and on non-strict MySQL by **silently blanking the status**.
The migration repairs blanked rows back to `draft` (`:33`).

### 2.3 `payslips`

**Provenance:** migration `2026_01_01_000300:53-70`.

| Column | Type | Provenance |
|---|---|---|
| `id`, `uuid` (unique), `tenant_id`, `company_id`, `employee_id`, `run_id` | — | migration `:54-59` |
| `month` (char 7), `earnings` (json), `deductions` (json) | — | migration `:60-62` |
| `gross`, `total_ded`, `net` | decimal(12,2) | migration `:63-65` |
| `net_words`, `emailed_at`, `pdf_path` | — | migration `:66-68` — **`net_words` and `pdf_path` are never written**; `emailed_at` is not written by the payroll path |
| **`calc_note`** | text null | **RUNTIME — `PayrollGenController::generate():2556-2563`**, created on the first-ever generate, deliberately *outside* the transaction because MySQL DDL implicitly commits |
| **`line_status`, `line_remarks`, `held_by`, `reviewed_by`, `approved_by`, `disbursed_by`, `ack_name`, `ack_by`, `ack_ip`** | string null | **RUNTIME — `SalaryApprovalController::ensureLineCols():88-95`**, created on the first sheet view or line decision |
| **`held_at`, `reviewed_at`, `approved_at`, `disbursed_at`, `ack_at`, `bank_filed_at`** | timestamp null | **RUNTIME — same** |
| `period_start`, `period_end` | date null | migration `2026_07_26_000400:29-33` |

Consequence: on a database where nobody has yet opened Salary Approval, `payslips.line_status`
does not exist. `ComputedController::report('live-salary'):36` and `AppDataController:2397`
both guard with `Schema::hasColumn` and default to the string `'pending'` — a comment at
`ComputedController:32-34` records that a live client hit `SQLSTATE 42S22` here.

### 2.4 `salary_components` — the salary structure

**Provenance is split, and this is where "change configuration, not code" already breaks.**

| Column | Provenance |
|---|---|
| `id`, `tenant_id`, `code`, `name`, `ctype ENUM(earning,deduction)`, `calc_type ENUM(fixed,percent,formula)`, `calc_value` (string), `taxable` (bool) | migration `2026_01_01_000300:25-35` |
| **`company_name`, `scope`, `scope_target`, `category`, `base`, `seq`** | **RUNTIME — `MasterController::ensureCols():496-570`**, driven by the `salary-setup` definition at `MasterController.php:243-247` |

Every column the engine actually branches on — `scope`, `scope_target` (who the structure
applies to), `base` (`fixed|pct_gross|pct_basic|balance`), `seq` (evaluation order) and
`category` (`fixed|variable|reimbursement`, which drives both the payslip grouping and the
statutory reimbursement carve-out) — is runtime-created. Note also that `calc_value` is migrated
as a **string** and is added by the runtime path as `decimal(12,2)` (`MasterController:563-564`),
so its type differs between a migrated install and a healed one.

Read by `PayrollGenController::componentRows():970-987` and `resolveComponents():990-1012`;
consumed by `AppDataController::computeSlipFromComponents():2145-2337`;
also read by `AppDataController::payslipGroupEarnings():1595-1597` (for `category`) and
`MasterController::labourCodeWarning():852-864`.

### 2.5 `salary_schedules` and `pay_cycles` — the period and pay-date masters

| Table | Column | Provenance |
|---|---|---|
| `salary_schedules` | `id, tenant_id, company_id, name, pay_cycle, lots(json), applicable_to, status` | migration `2026_01_01_000300:13-23` |
| `salary_schedules` | **`company_name`, `cutoff_day` (int), `pay_day` (STRING)** | **RUNTIME — `MasterController::ensureCols()` from the `salary-schedules` def at `:165-173`** |
| `pay_cycles` | **the entire table** | **RUNTIME — `MasterController::ensureCols():501-507`** creates `id, tenant_id, timestamps`, then adds `name, company_name, cycle, cutoff_day (int), pay_day (INT), status` from the `pay-cycle` def at `:448-453`. **No migration creates `pay_cycles` at all.** |
| `pay_cycles` | `period_end_day` (tinyint), `effective_from` (date) | migration `2026_07_26_000400:25-28` — a migration that adds columns to a table no migration creates |

**Type conflict:** `salary_schedules.pay_day` is declared `'string'` (`MasterController:168`)
while `pay_cycles.pay_day` is `'int'` (`:450`). `AppDataController::payDateFor():1394-1402`
runs the same `(int)` cast and range test over both, and `resolveCutoffDay` runs
`whereBetween('pay_day',[1,28])`-equivalent logic — correct only through MySQL's implicit
string→number coercion.

### 2.6 `loans`

**Provenance:** migration `2026_01_01_000300:119-129`.

| Column | Type | Provenance | Status |
|---|---|---|---|
| `id`, `tenant_id`, `employee_id` | — | migration `:120-122` | used |
| `amount` | decimal(12,2) | migration `:123` | not read by the engine |
| `emi` | decimal(12,2) | migration `:124` | **read** — `loanEmiDue():682` |
| `tenure` | int default 1 | migration `:125` | **never read by anything** |
| `outstanding` | decimal(12,2) **default 0** | migration `:126` | **read as a stop-condition** `:696`; **never written by anything** |
| `status` | string default `active` | migration `:127` | read (`approved`/`active`), written (`closed`) |
| **`installments_paid`** | uint default 0 | **RUNTIME — `PayrollGenController::generate():2580-2587`** | recovery counter |
| **`installment_amount`, `installments_total`, `type`, `start_month`, `company_id`, `principal`, `note`** | — | **DO NOT EXIST ANYWHERE** — no migration, no runtime creation. Read at `:682`, `:687`, `:691`, `:702`; validated by the dead `LoanController::validateLoan():64-74`; declared fillable on the dead `App\Models\Loan:13-16` |

See DEF-P02 — this is the most serious money defect in the area.

### 2.7 `advances`

**Provenance:** migration `2026_01_01_000300:108-117`: `id, tenant_id, employee_id,
amount decimal(12,2), per_month decimal(12,2), balance decimal(12,2), status string default
'active', timestamps`.

`per_month` and `balance` model a **spread** recovery. Grep confirms neither is read anywhere in
the application. `advanceRecovery():869-887` sums `amount` for rows with `status='approved'`
created inside the payroll month and recovers the whole thing at once.

### 2.8 `clawbacks`

**Provenance:** migration `2026_01_01_000300:96-106`: `id, tenant_id, employee_id, portfolio,
month(7), amount decimal(12,2), reason, status default 'pending', timestamps`.

| Column | Provenance |
|---|---|
| **`cycle_month`** | not in the migration; read at `clawbacksDue():920` with a `created_at` fallback |
| **`recovered_run_id`, `recovered_amount`, `recovered_at`** | **RUNTIME — `PayrollGenController::generate():2606-2616`** |

### 2.9 `commissions` — the most heavily runtime-extended table in the system

**Provenance:** migration `2026_01_01_000300:72-82` creates only
`id, tenant_id, company_id, employee_id, portfolio, month(7), amount decimal(12,2),
status ENUM('provisional','confirmed','reversed'), timestamps`.

**The migrated `status` ENUM does not contain a single value the code writes.** The engine
filters on `status='approved'` (`commissionByEmployee():605`), the bulk commit writes
`'pending'`/`'approved'` (`IncentiveController:81`, `:238`), the scheme paths write
`'rejected'` (`SchemeController:644`). On strict MySQL every commission insert fails; on
non-strict MySQL the status is silently blanked. (`SchemeController::ensure():102-117` shows the
project's standard remedy — `ALTER TABLE … MODIFY status VARCHAR` — but it is applied to
`incentive_schemes`, **not** to `commissions`.)

Columns the payroll engine reads that are **runtime-created or absent from the migration**:

| Column | Created by |
|---|---|
| `cycle_month`, `payout_date`, `payout_method`, `purpose`, `locked_at`, `locked_by`, `lock_source`, `decided_by`, `decided_at`, `remarks`, `description`, `entered_by`, `customer_name`, `account_ref`, `bounced_at` | not in the migration; guarded per-column with `Schema::hasColumn` at every read site (`PayrollGenController:590-610`, `:1766-1774`) |
| `gross_amount` decimal(14,2), `tds_rate` decimal(6,2), `tds_amount` decimal(14,2) | **RUNTIME — `IncentiveController::commit():104-116`** |
| `scheme_id`, `base_amount` decimal(14,2) | **RUNTIME — `SchemeController::ensure():120-127`** |
| `fin_year` (char 9, indexed) | **RUNTIME — `FinYearController::stamp():88-90`** |

Every write goes through `ApprovalService::safeRow()` `:263-266`, which is
`array_intersect_key($row, array_flip(Schema::getColumnListing($table)))` — **it silently drops
any key that is not a real column.** That is why a missing column produces a wrong number rather
than an error.

### 2.10 `incentive_schemes` — two incompatible shapes for one table

| Shape | Columns | Provenance |
|---|---|---|
| Legacy master | `id, tenant_id, name, portfolio, basis, slabs(json), clawback(bool), status, timestamps` | migration `2026_01_01_000300:84-94`; also the `incentive-schemes` master def at `MasterController:249-254` |
| Live scheme engine | `title, purpose, portfolio, rate_type, rate_value, tds_rate, payout_method, valid_from, valid_till, applies_to, team, employee_ids(text CSV), max_claims, max_amount, status, notes, created_by, created_by_emp, approval_status, approver, approver_emp, decided_by, decided_at, reject_reason, notify_channels` | **RUNTIME — `SchemeController::ensure():29-131`**, which creates the table if absent and then heals each column individually, relaxes legacy `NOT NULL` columns (`name, basis, clawback, company_id, company_name`) and widens a legacy `status` ENUM to VARCHAR (`:102-117`) |

Note `SchemeController::save()` writes **both** `title` and `name` with the same value
(`:518`, `:564`) purely to satisfy the legacy `NOT NULL` column. `employee_ids` is a
comma-separated string of ids in a `text` column — not a relation.

### 2.11 Tables created entirely at runtime by application code

| Table | Created by | Purpose |
|---|---|---|
| `loan_recoveries` | **`PayrollGenController::generate():2589-2603`** | one row per EMI recovered per run — the audit trail the regenerate reversal depends on |
| `statutory_settings` | **`SettingsController::ensureTable():77-88`** | the tenant's entire rate blob (`id, tenant_id, value longText, timestamps`) |
| `bank_invoices` | **`BankPackController::ensureInvoices():35-60`** | GST service invoices to banks |
| `commission_logs` | **`ApprovalService::logCommission():277-287`** | per-commission history |
| `commission_payments` | `2026_06_24_000000_ensure_runtime_feature_tables.php`, **and** `ApprovalService` | the disbursement ledger |
| `statutory_configs` | `2026_07_26_000500_create_statutory_configs.php`, **and** `StatutoryConfig::ensureSchema():78-91` via `SchemaHelper::ensureTable` | F1 effective-dated overrides |
| `pay_cycles` | **`MasterController::ensureCols():501-507`** | see §2.5 |

### 2.12 Other tables read by the payroll engine

`employees` (`ctc, emp_code, name, branch_id, team, shift, doj, employment_stage, pt_state,
gender, status, deleted_at, archived_at` — selected only when `Schema::hasColumn` confirms them,
`compute():1129-1162`), `attendance_logs` (`emp_code, log_date, punch_at, direction`),
`leaves` + `leave_types` (`paid`), `holidays`, `late_policy`, `shifts` + roster (via
`ShiftResolver`), `overtime` (+ runtime `paid_run_id`, `generate():2571-2578`), `expenses`,
`branches` (`city`), `companies`, `points_ledger`, `dra_certs`.

### 2.13 Provenance summary

| Provenance | Count of payroll-critical columns/tables |
|---|---|
| Created by a migration | ~45 columns across 8 tables |
| **Created at runtime by application code** | **~60 columns + 7 whole tables** |
| **Read but created by nothing** | `loans.installment_amount`, `loans.installments_total`, `loans.type`, `loans.start_month`, `commissions.cycle_month`, `commissions.payout_date`, `commissions.payout_method`, `commissions.purpose`, `commissions.locked_at` (+8 more commission columns), `clawbacks.cycle_month` |

---

## 3. Business rules register

Legend for **HARD-CODED?**: `Y` = the value/behaviour is a literal in PHP with no config path.
`P` = partly (a rate is configurable but the formula/shape is not). `N` = fully data-driven.
Catalogue BR-* IDs are reused where I confirmed them in source; `NEW-PAY-*` are rules the
catalogue missed.

### 3.1 Period, pay date and financial year

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HARD-CODED? | Configurable today? | Customer may want different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-PAY-001 | A payroll month is the calendar month unless a cut-off day 2–28 is configured, in which case it runs (N+1)th of the previous month → Nth of the run month | company, `YYYY-MM` | `cutoff_day` in 2..28 | fix `[start, days, working, end]` for every attendance/leave/OT/holiday query in the run | company | `PayrollGenController::monthMeta():171-199`, `periodStart():117-126`, `resolveCutoffDay():133-168` | P — the 2..28 range is a literal `:153` | Yes, via Pay Cycle / Salary Schedules masters | Yes — weekly, fortnightly, 4-4-5, or cut-off 29/30 |
| BR-PAY-002 | Cut-off precedence: `salary_schedules` before `pay_cycles`; within each, a company-named row beats a blank/`'all'` row; first company match returns immediately | tenant, company name | rows `status='active' OR NULL`, `id DESC`, limit 50 | return cut-off day, else 0 | company | `:138-161` | Y — table order and the literal `'all'` | No | Yes — matching by company **name** breaks on rename |
| BR-PAY-003 | Pay date: with a cut-off, pay on `pay_day` of the SAME run month; on a calendar cycle, pay on `pay_day` of the FOLLOWING month; with no `pay_day`, month end | tenant, company name, month | `pay_day` in 1..28 | set `payroll_runs.pay_date` | company | `AppDataController::payDateFor():1383-1425` | P — the 1..28 range and the two-branch rule are literals | Yes (pay_day), No (the rule) | Yes — many clients pay on the last working day, or 29/30/31 |
| NEW-PAY-01 | `PayrollGenController::resolvePayDate()` builds a complete `$pick` closure that scans both masters and **never calls it** — the method unconditionally delegates to `payDateFor()` | — | always | dead code | — | `:825-861` (`$pick` assigned `:827`, unused; `return` at `:860`) | Y | No | n/a — remove |
| BR-PAY-030 | Pay-cycle transition guard: refuse the run when this period overlaps or leaves a gap after the previous run's stored `period_end` | prev run `period_end`, this `period_start` | `period_start != prev.period_end + 1 day` | 422 `needs_confirm: period_overlap` / `period_gap` | company | `generate():2496-2537` | Y | Escape hatch `confirm_period_gap=1`, unaudited | Yes — the gap should create the bridging run automatically |
| BR-PAY-700 | The resolved period is stored on the run and on every payslip, so a later cut-off change cannot re-slice a paid month | resolved window | always | write `period_start`/`period_end` | run + slips | `generate():2476-2478`, `:2545-2546`, `:2676-2677` | N | n/a | No |
| BR-STAT-212 | Financial year = 1 Apr – 31 Mar, labelled `YYYY-YY` | any date | month ≥ 4 → start year = that year | FY label | tenant | `FinYearController::fyOf():30-40`, `range():62-67` | Y — April is a literal | No | Yes — a group with a non-Indian entity |
| BR-STAT-213 | A new money row is stamped with the tenant's **active** FY, not the FY of its own date | active FY setting | on insert | write `fin_year` | tenant | `FinYearController::stamp():82-96`; callers `IncentiveController:97-98`, `IncrementController:378` | Y | The active FY is settable | Yes — stamping by the record's own date is the obvious expectation |
| BR-PAY-004 | The per-FY money summary counts rows and sums `amount` **by `created_at`**, not by the record's period | 7 money tables | FY range on `created_at` | summary | tenant | `FinYearController::index():122` | Y | No | Yes — a March payslip generated in April lands in the wrong FY |
| NEW-PAY-02 | `PeriodResolver` — a complete, unit-tested period model with clamping, transition stubs and continuity validation — is **not called by the payroll engine** | — | always | unused | — | `PeriodResolver.php:29-31` (docblock admits it); only consumers are `AppDataController:1339` (label) and `PayrollPeriodAudit:86` | — | No | n/a — this is the SmartPRS 3 design, already written |

### 3.2 Salary structure and components

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HARD-CODED? | Configurable today? | Customer may want different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-PAY-011 | Default structure when no components are defined: **Basic = 50% of gross, HRA = 40% of Basic, Special Allowance = the remainder** | monthly gross | no components resolve | split gross | tenant-wide fallback | `AppDataController::computeSlip():2103-2105` | **Y — literals `0.5` and `0.4`** | **No** | **Yes — this is the single most commonly varied number in Indian payroll** |
| BR-PAY-007 | Component resolution, most specific wins: employee (`scope_target` = emp_code) → team → company/`''` | emp_code, team | first non-empty level | that component set | employee | `componentRows():970-987`, `resolveComponents():990-1012` | P — the three level names are literals | Yes, via Salary Setup | Yes — grade, designation, branch, location, cost-centre scopes |
| NEW-PAY-03 | A component's identity as "Basic" is a **substring test on `code + ' ' + name`** containing `basic` | component code/name | `str_contains(lower(code.' '.name),'basic')` | include in the PF wage base | component | `computeSlipFromComponents():2173` | **Y** | No | Yes — "Basic Travel Allowance" or code `BASIC_TA` is wrongly counted into PF |
| NEW-PAY-04 | DA is detected for the PF wage by scanning earning **names** for `dearness` or the regex `/(^|[^a-z])da([^a-z]|$)/` | earnings map | name match | add to PF wage base | component set | `:2266-2272` | **Y** | No | Yes — a component named "Special DA" or "Dearness Pay" behaves unpredictably |
| NEW-PAY-05 | A component is a **reimbursement** when `category='reimbursement'` OR its code/name contains `reimburs`; reimbursements are carved out of the wage gross and excluded from PF/ESI/TDS | component | either test | subtract from `wageGross` | component | `:2181-2188`, `:2220` | P — the literal `'reimburs'` | Yes via `category` | Yes — the keyword fallback misfires |
| NEW-PAY-06 | Component bases: `fixed`, `pct_gross`, `pct_basic`, `balance`. Anything unrecognised (including the documented `pct_ctc`) falls through to **percent of monthly gross** | `base`, `calc_value` | per base | compute amount | component | `:2225-2237` | Y — the four base names are literals | Base is settable per component | Yes — `pct_ctc`, formula, slab-of-gross, capped components |
| NEW-PAY-07 | Balance components share the remainder penny-safely; the last one absorbs the residue | remainder, n balance comps | any balance comp | allocate | component set | `:2247-2254` | N | n/a | No — this is the only correct rounding pattern in the codebase |
| NEW-PAY-08 | With no balance component and `abs(remainder) >= 1`, the residue is dumped into a literal `'Special Allowance'` key; a residue under ₹1 is silently discarded | remainder | `abs(remainder) >= 1` | add to `Special Allowance` | component set | `:2255-2259` | **Y** | No | Yes |
| NEW-PAY-09 | Labour Code advisory: warn when Basic+DA is below 50% of wage gross for a saved structure. Advisory only — never blocks | structure | `pct < 50` | warning string | structure scope | `MasterController::labourCodeWarning():846-892` | Y — the 50 and the ₹12,00,000 sample CTC | No | Yes — should be enforceable |
| BR-PAY-038 | An employee with `ctc <= 0` is **skipped and counted**, never paid ₹0 | `employees.ctc` | `<= 0` | `$skipped++`, continue | employee | `compute():1205-1210` | N | n/a | No |
| BR-PAY-043 | Run population: `status='active'`, not soft-deleted, not `archived_at`, matching `company_id` and tenant, ordered by `emp_code` | employees | all conditions | include in the run | company | `compute():1155-1162` | Y — the literal `'active'` | No | Yes — mid-month leavers, LWOP staff, contractors, multi-company employees |

### 3.3 Attendance, LOP and proration

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HARD-CODED? | Configurable today? | Customer may want different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-PAY-044 | Present days = **distinct punch dates** in the period. Hours are irrelevant; one punch = one full present day | `attendance_logs` | tenant + emp_code + date range | count distinct `log_date` | employee | `presentDays():202-220` | Y | No | Yes — half-days, hour-based attendance, shift completion |
| BR-PAY-013 | **Zero punches = full pay.** If the employee has no punches at all, the whole LOP block is skipped and `factor = 1.0` | present count | `present == 0` | pay 100% | employee | `compute():1237` | **Y** | **No** | **Yes — this is the single most dangerous default in the system** |
| BR-PAY-014 | Approved PAID leave counts as present; unpaid (LOP) leave does not. **An unknown `type_id` is treated as PAID** | `leaves`, `leave_types.paid` | `status='approved'`, overlaps the period | add working days | employee | `paidLeaveDays():1042-1085`, default at `:1065` | Y — the conservative default | Leave types are configurable | Yes — half-day leave is not modelled at all (every leave day counts as 1) |
| BR-PAY-015 | Paid leave is only counted **when the employee has at least one punch** | present count | `present > 0` | compute leave | employee | `compute():1212` | Y | No | Yes — the outcome is accidentally right, the reasoning is not |
| BR-PAY-053 | Weekly off = the configured `weekly_off_day` (default sunday) plus a Saturday policy `none \| all \| 2_4 \| 1_3` | statutory settings | day-of-week test | exclude from working days | tenant | `isWeekOff():70-89` | P — the day map and the 4 modes are literals | Yes, tenant-wide | Yes — per-company/branch/shift week-offs, rotating offs, 6-day vs 5-day teams |
| NEW-PAY-10 | The Nth-Saturday test is `ceil(day / 7)` — a day-of-month band, **not** a count of actual Saturdays | date | Saturday | 2nd/4th or 1st/3rd | tenant | `:83` | Y | No | Yes — diverges from the colloquial "2nd Saturday" in most months |
| BR-PAY-054 | A holiday falling on a weekly off is ignored, so it cannot remove a day twice | `holidays` | not a week-off | add to the holiday map | tenant | `holidayDates():1030` | N | Holidays are data | No |
| BR-PAY-719 / BR-PAY-730 | The LOP day-value basis is `working` (default) \| `calendar` \| `fixed30`; anything else silently falls back to `working` | `rates.lop_basis` | whitelist | choose the denominator | tenant | `compute():1108-1111`; factor `:1268-1287` | P | Yes — one tenant-wide setting | Yes — per company/grade; and a 26-day basis is not offered at all |
| BR-PAY-012 | The LOP factor scales the **annual CTC** before the slip is computed, so PF wage, ESI wage, the PT slab position and the annualised TDS base are all prorated | factor, ctc | always | `computeSlip($ctc * $factor)` | employee | `:1311-1312` | N | No | Yes — PT slab drift and TDS distortion are both consequences clients will query |
| BR-PAY-047 | Sandwich rule (optional, OFF by default): a weekly off/holiday whose nearest working day on **both** sides is uncovered by a punch or paid leave becomes one extra LOP day | punches, paid leave, offs | `sandwich_rule` truthy | `paidDays -= sandwichDays` | tenant | `sandwichDays():770-816`, applied `:1262-1266` | P | Yes, a tenant-wide on/off | Yes — per company/grade; and the one-sided variant |
| NEW-PAY-11 | Mid-month joiner: the denominator becomes the working days available **from the DOJ**, and the joiner's day-of-month scales the calendar/fixed30 availability | `employees.doj` | DOJ in this payroll month and day > 1 | `empWorking`, `dojDay` | employee | `:1216-1228`, `workingDaysInRange():97-109` | N | n/a | No — but see DEF-P05, it is wrong under a cut-off cycle |
| NEW-PAY-12 | There is **no leaver proration**. Nothing reads a date of leaving | — | — | — | — | grep: no `dol`/`last_day` read in `compute()` | Y | No | **Yes — a mid-month leaver is paid a full month** |
| BR-PAY-045 | Late policy resolution, most specific wins: employee → team → company | `late_policy` rows | scope + `scope_target` match | one normalised policy | employee | `latePolicyRows():243-269`, `resolvePolicy():272-319` | P | Yes, via the Late Policy master | Yes |
| BR-PAY-722 | Late cut modes: `simple` (excess lates → `half_day_per_late` / `full_day_per_late` / `one_day_per_n`), `tiered` (L1/L2/L3 minute thresholds → day-cuts), `net_hours` (made-up time: <half-day → 1 day cut, <full-day → 0.5) | day stats, policy | per mode | `lateCut` in days | employee | `attendanceCut():411-479`, `tierCut():482-495`, `simpleCut():498-513` | P — the mode names and the 0.5 / 1.0 cuts | Thresholds are configurable | Yes — a fourth mode needs code |
| NEW-PAY-13 | Default shift window when a late policy sets none: **09:30–18:30** | — | policy blank | shift bounds | tenant | `PayrollGenController::SHIFT_START/SHIFT_END:37-38` | **Y** | No | Yes |
| NEW-PAY-14 | Default full-day / half-day hours when the policy is blank: **9** and **4.5** hours | — | policy blank | thresholds | policy | `resolvePolicy():308-309` | **Y** | Partly | Yes |
| NEW-PAY-15 | Break-budget overrun: `half_day` → 0.5 day; `per_30min` → `ceil(excess/30) × 0.25` day | break minutes | `break > budget` | `breakCut` | employee | `attendanceCut():464-471` | **Y — the 30 and the 0.25** | Mode/budget configurable | Yes |
| BR-PAY-046 | Punch pairing is direction-aware only when the day has **both** an `in` and an `out`; otherwise chronological alternation. Repeat INs while open are double-taps; orphan OUTs are ignored | punches | per day | worked/break minutes | employee-day | `dayStats():322-408` | N | No | No — this is a good rule |
| BR-PAY-209 | `monthMeta()` computes a `$working` value by excluding **Sundays only**, ignoring `weekly_off_day`, the Saturday policy and holidays | month | always | third return slot | — | `:181`, `:193` | Y | No | n/a — **the value is destructured away at every call site** (`:1096`, `:1562`, `:1976`, `:2090`) and recomputed correctly. Dead, but a trap |

### 3.4 Statutory deductions

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HARD-CODED? | Configurable today? | Customer may want different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-STAT-001 | PF employee = `pf_rate`% of `min(Basic + DA, pf_wage_cap)` | Basic, DA | always | deduct | employee | `statutory():2064-2069` | P — formula fixed, rate/cap configurable | Rate + cap yes | Yes — VPF, the "restrict to ceiling or pay on full wages" election, per-employee opt-out |
| BR-STAT-002 | Employer PF splits into EPS **8.33%** capped at **₹1,250** on a wage ceiling of **₹15,000**, EPF = the balance; EDLI **0.5%** shown separately, never deducted | PF base | always | ECR figures | employee | `:2067`, `:2071-2073` | **Y — 8.33, 1250, 15000, 0.5 are all literals** | No | Yes — and see DEF-P08, the EPS figure is ₹1,249.50 not ₹1,250 |
| BR-STAT-004 | ESI on the **full gross** when gross ≤ `esi_threshold`, else nil. Employee 0.75%, employer 3.25%, each **`ceil()` to the next whole rupee** | gross | `0 < gross <= threshold` | deduct | employee | `:2075-2078` | P — the `ceil` rule is fixed | Threshold + both rates yes | Yes — the cliff at ₹21,000 (₹158 at 21,000, ₹0 at 21,001) is a client question every time |
| BR-STAT-005 | ESI contribution-period lock: once ESI applies in an Apr–Sep / Oct–Mar period it continues on the full gross until the period ends, even above the ceiling | stored payslips | any prior `deductions` key containing `esi` with value > 0 | force eligibility | employee | `esiPeriodLock():2011-2046`; consulted only when gross > threshold, `compute():1306-1308` | Y — the April/October boundaries; **the lock is a substring match on `'esi'` in the stored JSON key** | No | Yes — renaming the ESI line silently breaks the lock |
| BR-STAT-006 | Commission, incentive, overtime, night allowance and arrears are added **after** `computeSlip` returns, so **no PF, ESI, PT or TDS is charged on any of them** | extras | always | add to gross/net | employee | `compute():1350`, `:1391` | Y | No | **Yes — ESI is statutorily due on OT; all of it is taxable salary** |
| BR-STAT-007 | PT is a state-wise monthly slab on gross, resolved per employee from `employees.pt_state`; 16 built-in state tables, 10 PT-free states, else a fallback slab | gross, `pt_state` | state match | deduct | employee | `ptForGross():1925-2000`, `ptWalkSlab():1897-1916`, tables `:1852-1871` | **Y — all 16 slab tables and the 10 free states are `private const` arrays** | Partly: `pt_state_slabs` / `pt_free_states` overrides via Statutory Configuration | Yes — states revise slabs annually |
| NEW-PAY-16 | State matching is `str_contains($employeeState, $key)` — a **substring** test, not equality, in all three resolution passes | `pt_state` | substring hit | that slab | employee | `:1960`, `:1975`, `:1981` | Y | No | Yes — order-dependent and unguarded against one state name containing another |
| BR-STAT-008 | Maharashtra February uplift: the top band becomes ₹300 so the year totals ₹2,500 | state, month | `key='maharashtra' && amt >= 200 && month is 02` | ₹300 | employee | `ptWalkSlab():1911-1913` | **Y — 'maharashtra', 200, 300, '02'** | No | Yes — another state adopting the same pattern needs code |
| BR-STAT-009 | Maharashtra female exemption: women with gross ≤ `pt_female_exempt_upto` (₹25,000) pay ₹0; applied **before** everything else | gender, state, gross | gender in `female,f,woman,women` AND state contains `maharashtra` | ₹0 | employee | `:1933-1941` | P — the gender word list and the state key are literals | On/off + ceiling are settable | Yes — the gender list is closed (`Ms`, `FEMALE (F)` do not match) |
| BR-STAT-010 | A configured `pt_state_slabs` entry outranks the built-in PT-free list — that is how a PT-free state that starts levying is switched on | overrides | key substring hit | walk the configured slab | scope | `:1950-1963` | N | Yes | No |
| NEW-PAY-17 | A malformed configured slab row (fewer than 2 elements) is skipped, so a fully malformed slab silently returns **₹0** for the whole state | override | short rows | ₹0 | state | `ptWalkSlab():1899-1910` | Y | No | Yes — a typo zeroes PT with no error |
| NEW-PAY-18 | `pt_slabs` (the flat fallback list) is **read but can never be configured** — it is not a key of `defaults()`, not in `save()`'s validation, and is filtered out of `StatutoryConfig` payloads. It is always the hardcoded Telangana table | — | no state set | fallback slab | tenant | read `:1986`; absent from `SettingsController::defaults():29-73` | **Y** | **No — dead configuration** | Yes |
| NEW-PAY-19 | `pt_amount` (default ₹200) is a settable, validated, UI-exposed rate that **nothing reads** | — | — | — | — | `SettingsController::defaults():35`, validated `:144`; no read site | Y | Settable but inert | Yes — an admin changes it and nothing happens |
| BR-STAT-011 | Salary TDS: annual taxable = `annual CTC − std_deduction`; new-regime slabs; **nil when taxable ≤ `rebate_87a_limit`**; +4% cess; marginal relief just above the ceiling; ÷ 12 | annual CTC | always | monthly TDS | employee | `salaryTdsMonthly():1824-1843`, `newRegimeTax():1752-1766` | P — every parameter configurable, the shape fixed | Slabs, std deduction, rebate, cess all settable | Yes — no old regime, no declarations, no YTD true-up |
| BR-STAT-012 | The 87A rebate is a hard cliff-to-nil **inside** `newRegimeTax`; marginal relief is applied by the **caller**. Any caller that skips the relief step gets a different number | taxable | `> rebate` | full slab tax | — | `newRegimeTax():1755`; relief only at `salaryTdsMonthly():1836-1840`; **`statutoryPdf()` calls `newRegimeTax()` directly at `:1732` and `:1779`** | Y | No | Yes — see DEF-P09 |
| NEW-PAY-20 | Slab order is load-bearing: the walk uses `$prev = $cap` and `break`s on `upto <= 0`, so slabs must be ascending with the open band last. `SettingsController::save()` neither sorts nor validates | `tds_slabs` | admin reorders | corrupted tax | tenant | walk `:1756-1762`; save `:190-195` | Y | Editable, unvalidated | Yes — this is a foot-gun |
| BR-STAT-003 / BR-PAY-733 | Probation / internship suppress **PF, PT and TDS entirely** (and zero every employer PF figure). ESI, LWF, Conveyance and LOP still apply | `employment_stage` | `probation` or `internship` | zero three deductions | employee | `computeSlip():2114-2117`, `computeSlipFromComponents():2284-2287` | **Y — the two stage names and the three suppressed heads** | No | **Yes — this is a business choice, not a statutory exemption; the EPF Act does not exempt probationers** |
| BR-STAT-017 | LWF is a flat monthly rupee amount, off by default | `lwf_enabled`, `lwf_employee` | enabled | deduct | tenant | `:2119` / `:2301` | Y — flat amount, no state awareness, no periodicity, no employer share | **No — neither key is in `SettingsController::save()`'s validation list `:141-164`, and `StatutoryConfigController` gives the `lwf` kind no fields. Reachable only by writing the JSON blob directly** | Yes |
| BR-STAT-018 | "Conveyance" is a **DEDUCTION** computed exactly like PF: `rate% of min(Basic+DA, pf_wage_cap)` | `conveyance_enabled`, `conveyance_rate` | enabled and rate > 0 | deduct | tenant | `statutory():2082-2083` | P | Yes | Yes — conveyance is universally an *allowance* in India; a tenant with a "Conveyance Allowance" component gets Conveyance on both sides of the payslip |
| BR-STAT-013 | Gratuity = `(15 ÷ 26) × monthly Basic × completed years`, eligible at 5+ years | Basic, DOJ | `years >= 5` | provision | employee | `StatutoryController:54-57`; a second implementation at `ReportController:264-275`; a third (4.81% monthly accrual) at `AppDataController::payslipEmployerCost():1543` | **Y — 15, 26, 5, 4.81** | No | Yes — no ₹20L ceiling, no 4y240d rule, no 15/30 for non-covered establishments |
| BR-STAT-014 | Statutory bonus register: eligible when Basic ≤ **₹21,000**/month, bonus wage capped at **₹7,000**/month, FY months prorated for joiners at **30.44 days**, rate clamped **8.33–20%** | Basic, DOJ | eligibility | annual provision | employee | `StatutoryController:113-165` | **Y — 21000, 7000, 30.44, 8.33, 20** | Rate only | Yes — the Act's "₹7,000 or the state minimum wage, whichever is higher" is acknowledged in the note text but not implemented |
| BR-STAT-015 | The statutory bonus is **never paid by payroll**. The register computes a provision; nothing writes it to a payslip | — | — | — | — | grep: `PayrollGenController` never reads `bonus_encashment` | — | No | Yes |

### 3.5 Variable earnings

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HARD-CODED? | Configurable today? | Customer may want different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-PAY-018 | A commission's **payout date** decides which month's payslip pays it; with no payout date the earned month (`cycle_month`) decides; with neither, **no payslip ever pays it** | `payout_date`, `cycle_month` | month match | include | employee | `commissionByEmployee():617-639` | N | Yes, per entry | No |
| BR-PAY-052 | `payout_method='separate'` entries **never** touch a payslip — they are paid through the disbursement ledger | `payout_method` | `= 'separate'` | skip | entry | `:617-619` | Y — the two method names | Yes per entry / per batch | No |
| NEW-PAY-21 | Each commission **Purpose** becomes its own payslip earning line; the fallback label is the literal `'Commission'` | `purpose` | grouping | one line per purpose | employee | `:1317-1321`, `:2628-2639`, fallback `:644`,`:649`,`:1319` | P | Purpose is free text | No |
| BR-PAY-019 | A commission included in a run is **locked forever**: `locked_at`, `locked_by`, `lock_source='payslip <Mon YYYY>'`, a `commission_payments` debit `reference='run #<id> · <Mon YYYY>'`, and a history line | included entries | on generate | lock + ledger + log | entry | `generate():2695-2740` | Y — the reference string format | No | No — but see DEF-P10, the reversal matches on a LIKE pattern that is safe by one space character |
| BR-PAY-016 | Overtime is paid as a flat `Overtime` earning line, **not prorated**, and attracts no PF/ESI/PT/TDS. Only `status='approved'` rows with `amount > 0` dated in the period | `overtime` | approved + in period | add | employee | `overtimeByEmployee():943-967`, line `:2644-2647` | Y — the line name | No | Yes — ESI is due on OT |
| BR-PAY-732 | OT valuation at entry time, only when the amount is left blank: `hourly = ctc / 12 / 26 / 8`; `amount = hours × multiplier × hourly`, multiplier default **2** | ctc, hours, multiplier | amount blank | compute | entry | `MasterController::save():755-763`; **duplicated** at `PayrollGenController::simulate():2165` | **Y — 26 and 8 are literals in both copies** | Multiplier only (UI offers 1 / 1.5 / 2) | Yes — 26/8 is a convention, not law; and a manually typed amount is never validated |
| NEW-PAY-22 | Night shift allowance: a flat ₹ per night **actually worked**, counted from distinct punch dates whose resolved shift is a night shift (`end <= start`) with an allowance, excluding roster week-offs | shifts, roster, punches | any night shift with an allowance | add | employee | `compute():1326-1338`, line `:2640-2643` | Y — the line name | Amount per shift | Yes — no percentage or slab option |
| BR-PAY-017 | Backdated increment arrears: `diffMonthly = (newCTC − oldCTC)/12`; the effective month is **day-prorated by calendar days**, whole months thereafter up to (excluding) the current month; created as an **approved commission entry with `purpose='Salary Arrears'` and `tds_rate = 0`** | increment | `newCTC > oldCTC` and effective month < current | insert a commission | employee | `IncrementController:357-421` | **Y — the purpose string, `tds_rate=0`** | No | **Yes — arrears are paid entirely untaxed; a backdated *decrease* produces nothing, so an overpayment is never recovered** |
| NEW-PAY-23 | Arrears target the current month unless that month's run is already past draft, in which case the next month | `payroll_runs.status` | `!== 'draft'` | shift a month | employee | `:384-395` | Y | No | No |

### 3.6 Recoveries

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HARD-CODED? | Configurable today? | Customer may want different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-PAY-009 | Recovery order is fixed: statutory → **Loan EMI → Salary Advance → Clawback** | net available | always | deduct in order | employee | `compute():1350-1389` | **Y** | **No** | **Yes — priority order is a policy decision** |
| BR-PAY-010 | Net never goes negative — enforced twice: `net = round(max(0, gross − total_ded), 2)` inside the slip, and each recovery is capped at the remaining net | — | always | clamp | employee | `AppDataController:2129`, `:2335`; caps `:1357`, `:1370`, `:1379-1385` | N | n/a | No |
| BR-PAY-020 | Loan EMI is **full-EMI-only**: if the whole EMI does not fit the remaining net the month is skipped entirely and the instalment stays due. Never partial | EMIs, net | `emi <= netAvail − applied` | apply or skip | employee | `:1356-1368`, note `:1414-1416` | **Y** | **No** | **Yes — most clients expect a partial recovery** |
| NEW-PAY-24 | A loan is due while `status IN ('approved','active')`, its `start_month` has been reached, `installments_paid < total`, and its `outstanding` is null or > 0. Limit **50** loans per employee | `loans` | all four | list `[id, emi, type]` | employee | `loanEmiDue():670-709` | Y | No | See DEF-P02 — three of the four conditions reference columns that do not exist |
| BR-PAY-021 | Loan bookkeeping: one `loan_recoveries` row per recovered EMI, then `installments_paid++`, then auto-close when `installments_paid >= total`. Reversed on regeneration, ledger-driven so a decrement always has a matching increment | recoveries | on generate | ledger + counters | loan | `generate():2768-2807`; reversal `:2435-2452` | N | n/a | No — the pattern is correct |
| BR-PAY-022 | An approved salary advance is recovered **in full in the month it was approved**, capped at the remaining net. Partial recovery is allowed; any shortfall is noted and **never retried** | `advances` | `status='approved'` AND `created_at` inside the payroll month | deduct | employee | `advanceRecovery():869-887`, applied `:1370` | **Y — the whole-amount rule; `per_month`/`balance` are ignored** | No | **Yes — the migrated schema models a spread recovery that no code implements** |
| BR-PAY-033 | Advances have **no recovery ledger** — nothing records which run recovered how much, so a regenerate cannot reverse an advance recovery | — | — | — | — | grep: no advance equivalent of `loan_recoveries` | Y | No | Yes |
| NEW-PAY-25 | The advance window keys on **`created_at`**, not on an approval date or an effective month | `advances.created_at` | `[month-01, month+1-01)` | include | employee | `:875-882` | Y | No | Yes — an advance created 31 July and approved 2 August recovers from July, never August |
| BR-PAY-023 | Clawback recovery is attempted **exactly once** in its due month (`cycle_month`, else the created month); it takes what fits and any shortfall stays open on the row for manual handling | `clawbacks` | approved, `recovered_run_id IS NULL`, due month = payroll month | deduct + stamp | employee | `clawbacksDue():903-934`, apply `:1374-1388`, stamp `:2813-2832`, reversal `:2455-2465` | **Y — the one-month-only rule** | No | Yes — most clients want a rolling carry-forward |

### 3.7 Run lifecycle and approval

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HARD-CODED? | Configurable today? | Customer may want different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-PAY-039 / BR-PAY-603 | One run per company per month, keyed on `(tenant, company_id, cycle_label)` | existing run | exists | 409 | company-month | `existingRun():2279-2289`, `generate():2360-2368` | Y | No | Yes — multiple lots per month (`payroll_runs.lot` exists and is hardcoded to 1) |
| BR-PAY-405 / BR-PAY-720 | Regenerating a draft **deletes** the old payslips and the old run row, then recomputes; it is refused without `regenerate=1` | existing draft | `regenerate` truthy | delete + recompute | run | `:2366-2367`, `:2389-2390` | Y | No | Yes — the previous version and its rejection remark are destroyed, not versioned |
| BR-PAY-042 | Every draft regeneration is audited to `activity_logs` with action `payroll.regenerate` and the replaced run id — best-effort, never blocks | user, run | on regenerate | log | run | `:2371-2387` | N | n/a | No |
| BR-PAY-040 | The run header and every payslip are written inside one transaction; a mid-way failure rolls the whole thing back | rows | on generate | atomic insert | run | `:2620-2693` | N | n/a | No |
| BR-PAY-041 | All DDL (`payslips.calc_note`, `overtime.paid_run_id`, `loans.installments_paid`, `loan_recoveries`, clawback columns) runs **before** the transaction, because MySQL DDL implicitly commits | schema | first run | create columns/tables | database | `:2556-2616` | Y | No | Yes — this should not happen at request time at all |
| BR-PAY-716 | Run states: `draft → hr_approved → approved`; reject from either stage → `rejected` | run status | per `nextStep()` | transition | run | `nextStep():159-169`, `applyDecision():795-836` | **Y — the state names and the two-stage shape** | **No** | **Yes — a third approval level, a parallel approver, or a single-stage flow all need code** |
| BR-PAY-025 | Four-eyes: HR = `hr_manager\|admin\|super_admin`; Finance = `admin\|super_admin`. An `hr_manager` alone cannot complete a run | roles | per step | allow/deny | run | `isHR():59-62`, `isFinance():65-68` | **Y — the role names** | No | Yes |
| BR-PAY-024 | `paid` and `locked` are **read but never written**. A run stays `approved` forever, even after the bank file is downloaded. `locked_at` *is* set on final approval; the status is not | — | — | — | run | `PayrollLock::FINALISED_STATUSES:31`, `generate():2362`; no writer | Y | No | Yes |
| BR-PAY-026 | A rejected run is terminal in the state machine — but `generate()`'s block list is `['hr_approved','approved','locked','paid']`, so `rejected` **can** be regenerated back into a fresh draft. That is the undocumented recovery path | run status | `regenerate=1` | new draft | run | `:2362` | Y | No | Yes — there is no un-reject, no re-submit, no delete |
| BR-PAY-027 | Corrections to paid money must flow through Clawbacks. There is no reversal, no credit note, no negative run, and **no way to edit a payslip** | — | — | — | — | design; `CalcGuideController:147` | Y | No | Yes |
| BR-PAY-005 | The finalised-period guard is wired into exactly **two** save paths — `StatutoryConfigController::save():158` and `saveSlab():258`, via `EffectiveDated::guardFinalisedPeriod()` | effective date | period finalised | 403 unless super_admin | statutory config | `PayrollLock::isPeriodFinalised():39-75` | Y | No | Yes — see §6 for everything it does not guard |
| BR-PAY-006 | Reopening a finalised run is documented as Super-Admin-only. **`denyUnlessSuperAdmin()` is never called from any route. There is no reopen endpoint** | — | — | — | — | `PayrollLock:82-97` (docblock `:22-25`); grep confirms no caller | — | No | Yes |
| NEW-PAY-26 | `EffectiveDated::guardFinalisedPeriod()` fails **OPEN** on any exception (`:115-117`), while `PayrollLock::denyUnlessSuperAdmin()` fails **CLOSED** (`:91-96`). Two guards in the same call chain with opposite failure modes | — | exception | allow vs deny | — | as cited | Y | No | Yes |

### 3.8 Per-line lifecycle, bank file and payslip

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HARD-CODED? | Configurable today? | Customer may want different? |
|---|---|---|---|---|---|---|---|---|---|
| NEW-PAY-27 | Line states: `pending → (on_hold \| in_review) → approved → disbursed → acknowledged`, or `rejected`. `rejected` and `acknowledged` are terminal; there are **no reverse transitions** | `line_status` | per action map | transition | payslip line | `applyLineAction():554-606`, `lineActionsFor():113-135` | **Y — six state names, five action names** | No | Yes |
| BR-PAY-028 | A line may be disbursed only after its parent **run** has cleared HR approval — explicitly to stop Finance side-stepping the four-eyes control | `run_id`, run status | run in `hr_approved\|approved\|locked\|paid` | allow | line | `:583-588` | Y | No | No — but the guard only fires `if (! empty($slip->run_id))` |
| BR-PAY-029 / BR-PAY-718 | Employee e-signature: the caller must be the line's own employee, the line must be exactly `disbursed`, a typed `name` (≤120) and a `confirm` checkbox are required; name, actor, timestamp and **IP** are stored, and a receipt email is queued | request | all conditions | `acknowledged` | line | `acknowledge():731-792` | N | No | Yes — a weak signature; no OTP, no certificate |
| BR-PAY-032 / BR-PAY-717 | **Downloading the bank file IS the disbursement**: included lines are stamped `bank_filed_at` and any `approved` line auto-flips to `disbursed`. Lines with no `bank_acc` or no `ifsc` are excluded and reported | run, `?all` | not a re-download | update lines | run | `bankFile():482-504`, split `:398-413` | Y | No | Yes — and see DEF-P11: this bypasses `applyLineAction()` entirely |
| NEW-PAY-28 | Four NEFT layouts — `generic`, `icici`, `hdfc`, `sbi` — as literal column arrays and row closures; a `# TOTAL` trailer row is appended | `?bank` | name match | CSV | run | `bankFormat():375-395` | **Y — every column name and order** | **No** | **Yes — every bank's portal spec differs and changes** |
| NEW-PAY-29 | Narration is `'SALARY <cycle> <first 18 alphanumeric chars of the company name, uppercased>'` | run, company | always | narration | run | `:466` | **Y** | No | Yes |
| BR-PAY-201 / BR-PAY-725 | Payslip self-download policy: `all` (default) \| `none` \| `dept` (block listed departments) \| `emp` (block listed emp codes). HR/Admin always bypass | `payslip_dl_mode` | per mode | allow/deny | tenant | `SettingsController::payslipSelfAllowed():108-124`; enforced `AppDataController:1191-1194` | P — the four mode names | Yes | Yes |
| BR-PAY-202 | The payslip PDF prefers the **stored slip** for the month (newest by id); with no run it falls back to a full-CTC `computeSlip()` with **no warning on the document** | `payslips` row | exists | use stored | employee-month | `payslipViewData():1237-1264` | Y | No | Yes — a pre-payroll payslip prints an un-prorated theoretical slip |
| BR-PAY-031 | The payslip's **Paid Days / LOP** figures are recovered by **regex from the free-text calculation note**: `/Paid\s+([\d.]+)\s+of\s+(\d+)\s+days/i`. If the sentence is absent the payslip prints `daysInMonth / 0` | `calc_note` | regex match | paid/LOP days | payslip | `:1278-1282`; the sentence is only emitted when `factor < 0.9999` (`calcNote():537-540`) | **Y** | No | **Yes — this must be structured data** |
| NEW-PAY-30 | Payslip earnings are grouped Fixed / Variable / Reimbursement using `salary_components.category` when set, else a **keyword heuristic**: `reimburs` → reimbursement; `commission\|incentive\|bonus\|overtime\|arrear\|ex-gratia\|ex gratia\|payout\|variable` → variable; else fixed | earning names | classification | grouping | payslip | `payslipGroupEarnings():1591-1636` | **Y — the nine keywords** | Partly via `category` | Yes |
| NEW-PAY-31 | Printed "Gross Earnings (A+B)" **excludes reimbursements**, while the stored `payslips.gross` **includes** them | groups | always | display | payslip | blade `:153` vs `computeSlipFromComponents():2329` | Y | No | Yes — the two numbers differ and both are labelled gross |
| NEW-PAY-32 | The employer-cost memo re-derives EDLI as `min(basic,15000) × 0.5%` and back-derives employer ESI as `esi × erRate / eeRate` when the slip lacks the keys — a second implementation of `statutory()` | slip | keys absent | memo | payslip | `payslipEmployerCost():1541-1542` | Y | No | Yes — it will drift |
| NEW-PAY-33 | Gratuity accrual in the CTC memo is **4.81% of Basic**, a fourth gratuity formula | Basic | always | memo | payslip | `:1543` | **Y** | No | Yes |
| NEW-PAY-34 | YTD sums every `payslips` row for the employee from `YYYY-04` to the current month by **string comparison**, with **no tenant, company or run filter and no de-duplication** | payslips | FY window | YTD columns | payslip | `payslipYtd():1557-1584` | Y | Column toggle `payslip_show_ytd` | Yes — multiple lots for one month would double-count |
| NEW-PAY-35 | The wage register (Form B) maps deduction keys to columns by substring: `provident`/`pf` → PF, `esi` → ESI, `professional`/`pt` → PT, `tds` → TDS | stored JSON | substring | column | report | `ReportController:172-192` | **Y** | No | Yes |

### 3.9 Schemes, incentives and commissions

| RULE ID | Business description | Inputs | Condition | Action | Scope | Where implemented | HARD-CODED? | Configurable today? | Customer may want different? |
|---|---|---|---|---|---|---|---|---|---|
| BR-PAY-034 | Scheme claim money is computed **server-side from the scheme**, never accepted from the browser: `percent` → `base × rate / 100`; `fixed` → `rate_value`; `open` → the typed gross | scheme, claim | per rate type | override the claim | claim | `SchemeController::validateClaim():243-295` | P — the three rate-type names | Rate/type per scheme | Yes — slabs, tiers, per-portfolio rates are not available on a scheme |
| BR-PAY-035 | The per-person cap is checked against **gross**, and against **all non-rejected** claims — including still-pending ones | scheme caps | `max_claims` / `max_amount` | block the claim | employee-scheme | `capLeft():218-236` | N | Per scheme | No |
| BR-PAY-036 / BR-PAY-710 | Editing a live scheme's rate **retroactively re-prices every unlocked, non-rejected claim**; approved ones go back to `pending`; locked (payslip-paid) ones and part-paid-beyond-the-new-net ones are skipped. A non-admin's edit waits for approval before the recalc runs | scheme, claims | rate type in `percent\|fixed` | recompute gross/TDS/net | scheme | `recalcClaims():793-862` | Y | No | Yes — retroactive re-pricing is a strong policy choice |
| NEW-PAY-36 | The recalc's TDS fallback when neither the scheme nor the row sets a rate is the literal **5** | scheme, row | both null | 5% | claim | `:818` | **Y** | No | Yes |
| NEW-PAY-37 | Scheme approval hierarchy: Admin/HR schemes are live instantly; a TL/manager's scheme waits for **their own reporting manager** (fallback: the label `'Admin / HR'`). A non-admin cannot create an "all employees" scheme, nor target anyone outside their reportees | user, scheme | role + scope | approval routing | scheme | `save():443-500` | **Y — the whole hierarchy** | No | Yes |
| BR-PAY-711 | Withdrawing a scheme never touches approved claims; pending claims are optionally rejected with the remark `'Scheme "<title>" was withdrawn'`; re-opening restores claims matched by `remarks LIKE '%withdrawn%'` | scheme | withdraw / reopen | reject / restore | scheme | `withdraw():616-661`, `reopen():869-922` | **Y — the restore matches on a substring of a human remark** | No | Yes — fragile |
| NEW-PAY-38 | Scheme announcement channels: `email`, `wa`, `notice`; a legacy blank channel list means all three; targets capped at **500** | scheme | published/approved | notify | tenant | `announce():927-1000` | Y | Channels per scheme | Yes |
| BR-STAT-016 / NEW-PAY-39 | TDS 194H on a bulk commit: rate = request override → `comm_tds_rate` → **5.0**, clamped 0–100; `tds = round(payout × rate/100, 2)`; `net = payout − tds`; a row whose net is ≤ 0 is silently dropped. **The NET is what folds into payroll** | payout, rate | `net > 0` | create the entry | batch | `IncentiveController::commit():125-132`, `:217-221` | P — the 5.0 default | Rate configurable | Yes — no no-PAN higher rate on this path, unlike the 24Q register |
| NEW-PAY-40 | Bulk payout: basis `collected \| target \| manual`; formula `flat \| slab \| portfolio`. `target` pays 0 unless `achievement >= threshold` (default 100). `slab` picks the first band with `upto > 0 && figure <= upto`, else **defaults to the LAST band's rate** | rows, config | per basis/formula | payout | batch | `payoutFor():299-344` | P — six literal names, threshold default 100 | All per request | Yes — no marginal/tiered slabs, no per-employee curves |
| BR-PAY-037 | Points **never** convert to money. At most they gate incentive eligibility via `points_gate_min` points earned in the incentive month | `points_ledger` | `pts < ptsMin` | skip the row | batch | `commit():198-216` | N | Yes (0 = off) | No |
| NEW-PAY-41 | DRA gate at the money point: `off \| warn \| block`. `block` skips the row; `warn` pays and lists it | `dra_gate`, DRA certs | invalid cert | skip / warn | batch | `commit():185-197`; `ComplianceController::draValid()` | P — the three mode names | Yes | Yes |
| NEW-PAY-42 | Payout lag: when a bulk commit sets no payout date and `incentive_payout_lag` is N (0–12), the date auto-fills to the 1st of (earned month + N) — the retention guard | `incentive_payout_lag` | date absent, N > 0 | set `payout_date` | batch | `commit():152-163` | P — the 0..12 clamp | Yes | No |
| NEW-PAY-43 | The bulk purpose label is `'Incentive'` when the type selector says incentive, else `'Commission'` | request | type | `commissions.purpose` | batch | `commit():87-90` | **Y** | Overridable per request | No |
| NEW-PAY-44 | Bank-pack GST split: same GSTIN state code → CGST 9% + SGST 9%; different or missing → IGST 18%. Invoice number `BPK/<FY>/nnnn` from a per-tenant per-FY **count + 1** | GSTINs, lines | state code compare | tax + number | tenant | `gstMode():116-127`, `invoiceSave():318-328` | **Y — 0.09 / 0.18 and the number format** | No | Yes — rates change; and `count()+1` is not race-safe |

### 3.10 Money representation

| RULE ID | Business description | Where implemented | HARD-CODED? | Customer may want different? |
|---|---|---|---|---|
| NEW-PAY-45 | **The documented invariant "decimal columns + integer-safe math, never float" is violated throughout.** Storage is `DECIMAL`; every arithmetic operation is PHP float | claim at `config/smartprs.php:128` and `README.md:73`; violated at `AppDataController:2094`, `:2102-2105`, `:2122`, `:2129`, `:2059-2078`, `:1752-1766`, `1839-1842`, `2172-2240`, `2310`; `PayrollGenController:1201-1203`, `:1350-1393`, `:1427-1429`, `:2632`; `SalaryApprovalController:319-322`, `:428`, `:483`; `SchemeController:290`, `:821`, `:832`; `BankPackController:319-321`. `composer.json` requires no bcmath; grep finds no `bcadd`/`bcmul`/`brick/money` anywhere | Y | Yes — §6 DEF-P07 |

---

## 4. Calculations and formulas

> All arithmetic below is **PHP float**. `round(x,2)` is round-half-away-from-zero on a binary
> float; `ceil(x)` is a float ceiling to a whole rupee.

### 4.0 Order of operations, exactly as executed

`PayrollGenController::compute():1091-1488`, per employee:

```
 1  ctc        = employees.ctc                       ; if <= 0 → skip, $skipped++      :1205
 2  present    = distinct punch dates in [periodStart..endDate]   (0 when lop off)     :1211
 3  leave      = approved PAID-leave working days    (only when present > 0)           :1212
 4  empWorking = working days, from DOJ if joined mid-month                            :1216-1228
 5  lateCut / breakCut = late-policy day cuts        (only when present > 0)           :1242-1256
 6  paidDays   = max(0, min(empWorking, present + leave) - lateCut - breakCut)         :1259
 7  sandwich   = optional; paidDays -= sandwichDays                                    :1262-1266
 8  factor     = per LOP basis (§4.3)                                                  :1268-1287
 9  s          = computeSlipFromComponents(ctc × factor, …) ?: computeSlip(ctc × factor, …)  :1310-1312
10  commission = Σ approved commissions due this month                                 :1313
11  nightAmt   = Σ night allowance per night worked                                    :1326-1338
12  otAmt      = Σ approved overtime this month                                        :1341
13  netAvail   = round(s.net + commission + nightAmt + otAmt, 2)                       :1350
14  loan EMIs  = full-EMI-only, in loans.id order, while they fit                      :1356-1368
15  advApplied = max(0, min(advDue, netAvail - emiApplied))                            :1369-1370
16  clawbacks  = take what fits after 14 and 15, in clawbacks.id order                 :1377-1388
17  extraDed   = emiApplied + advApplied + cbApplied                                   :1389
18  gross      = round(s.gross + commission + nightAmt + otAmt, 2)                     :1391
19  ded        = round(s.total_ded + extraDed, 2)                                      :1392
20  net        = round(netAvail - extraDed, 2)                                         :1393
```

### 4.1 Gross → Basic / HRA / Special (default structure)

`AppDataController::computeSlip():2102-2105`:

```php
$gross   = round($ctc / 12, 2);
$basic   = round($gross * 0.5, 2);      // 0.5 is a literal
$hra     = round($basic * 0.4, 2);      // 0.4 is a literal
$special = round($gross - $basic - $hra, 2);
```

**Worked example — CTC ₹6,00,000, full attendance:**
```
gross   = round(600000/12, 2)                  = 50000.00
basic   = round(50000 × 0.5, 2)                = 25000.00
hra     = round(25000 × 0.4, 2)                = 10000.00
special = round(50000 - 25000 - 10000, 2)      = 15000.00
```
Matches `tests/Unit/PayrollMathTest.php:19-31`.

**Rounding artefact — CTC ₹13,00,000:**
```
gross   = 108333.33
basic   = round(54166.665, 2)   = 54166.67     ← rounds up
hra     = round(21666.668, 2)   = 21666.67
special = 108333.33 - 54166.67 - 21666.67 = 32499.99   ← 1 paise short of 32500.00
```
The components always sum to gross by construction, but Special absorbs the drift every month.

**Divisor: 12. Rounding: `round(...,2)` on each of the four lines independently.**

### 4.2 The proration divisors — the answer to "26 / 30 / calendar / working / payable?"

**There is no per-day rupee rate in the salary path.** The engine computes a dimensionless
`factor` and multiplies the **annual CTC** by it. A per-day *value* is derived only for the
Live Salary passbook display.

| Basis (`rates.lop_basis`) | Denominator | One absent day costs | Chosen at |
|---|---|---|---|
| `working` (default) | period days − weekly offs − working-day holidays | `gross ÷ workingDays` | `compute():1108-1111`, factor `:1286` |
| `calendar` | `daysInMonth` of the period | `gross ÷ 31` (etc.); offs and holidays are PAID days | factor `:1268-1284` |
| `fixed30` | flat `30.0` | `gross ÷ 30` regardless of month length | factor `:1268-1284` |

Anything outside the whitelist silently falls back to `working` (`:1109-1111`).

**Working days** — `compute():1118-1127`:
```php
$working = 0;
for ($c = periodStart; $c <= periodEnd; $c->addDay())
    if (! (isWeekOff($c, $rates) || isset($holidays[$c])))  $working++;
$working = max(1, $working);
```

**Per-day value for display** — `liveSalary():1736-1737`:
```php
$dayDen   = $lopBasis === 'calendar' ? max(1,$daysInMonth)
          : ($lopBasis === 'fixed30' ? 30 : $working);
$dayValue = round($sFull['gross'] / $dayDen, 2);
```

**26 is NEVER a salary divisor.** `26` appears in exactly three places in the codebase, all
overtime or gratuity:

| Location | Use |
|---|---|
| `MasterController.php:761` | OT hourly = `ctc / 12 / 26 / 8` |
| `PayrollGenController.php:2165` | the same formula, duplicated in the Simulator |
| `StatutoryController.php:57` and `ReportController.php:281` | gratuity `(15 ÷ 26) × Basic × years` |

The in-app FAQ headline *"Why does one absent day cut gross ÷ 26 and not gross ÷ 30?"*
(`CalcGuideController:161`) is therefore **misleading**: ÷26 arises only because a 31-day month
with 5 Sundays happens to have 26 working days under the default `working` basis.

**Consistency across components — the answer to the second half of the question:**

| Concern | Divisor / basis used | Consistent with the salary basis? |
|---|---|---|
| Base salary proration | `lop_basis` (working / calendar / fixed30) | — (the reference) |
| PF, ESI, PT, TDS | none — they are computed **on the already-prorated CTC**, so they inherit the basis | **Yes** |
| Commission / incentive | none — added after, **never prorated** | **No — by design** |
| Overtime | valued at `÷ 12 ÷ 26 ÷ 8`, added after, never prorated | **No — a hard 26** |
| Night allowance | flat ₹ per night worked | **No** |
| Arrears | `(newCTC − oldCTC) ÷ 12`, first month day-prorated by **calendar days** (`daysInMonth`) | **No — always calendar, regardless of `lop_basis`** |
| Gratuity | `15 ÷ 26` | **No — a hard 26** |
| Statutory bonus | `bonus wage × months × pct`, months from `diffInDays ÷ 30.44` | **No — a hard 30.44** |
| Live Salary "elapsed" projection with no punches | elapsed **working** days ÷ working days | **No — ignores `lop_basis` in the zero-punch branch** (`:1610-1613`) |
| Live Salary passbook day-value | follows `lop_basis` | Yes |
| Simulator | `den` = working / daysInMonth / 30 per basis, overridable per request | Yes |

**Four different divisors (working-days, calendar-days, 30, 26) and a 30.44 are in simultaneous
use in one payroll month.** Only the base-salary path honours the tenant's chosen basis.

### 4.3 The LOP factor

**Step 1 — paid days** (`:1259`):
```php
$paidDays = max(0.0, min($empWorking, $present + $leave) - $lateCut - $breakCut);
```

**Step 2 — mid-month joiner** (`:1216-1228`):
```php
if (doj->format('Y-m') === $month && doj->day > 1) {
    $empWorking = max(1, workingDaysInRange($start, $doj->day, $daysInMonth, $rates, $holidays));
    $dojDay     = $doj->day;
}
```

**Step 3 — sandwich rule** (`:1262-1266`), optional: `paidDays = max(0, paidDays − sandwichDays)`.

**Step 4 — the factor** (`:1268-1287`):

*Basis `working`:*
```php
$factor = min(1.0, $paidDays / $empWorking);
```

*Basis `calendar` or `fixed30`:*
```php
$absentDays = max(0.0, $empWorking - $paidDays);          // absence measured in WORKING days
if (fixed30) { $den = 30.0;
               $avail = 30.0 - ($dojDay>1 ? round(($dojDay-1)*30.0/max(1,$daysInMonth), 2) : 0.0); }
else         { $den = (float) $daysInMonth;
               $avail = (float) ($daysInMonth - ($dojDay>1 ? ($dojDay-1) : 0)); }
$factor = max(0.0, min(1.0, ($avail - $absentDays) / max(1.0, $den)));
```

**Worked example — gross ₹50,000, July 2026 (31 days, 27 working under Sunday-only offs), 2 absent working days, no joiner:**

| Basis | Arithmetic | Factor | Gross paid |
|---|---|---|---|
| `working` | 25 ÷ 27 | 0.925926 | ₹46,296.30 |
| `calendar` | (31 − 2) ÷ 31 | 0.935484 | ₹46,774.19 |
| `fixed30` | (30 − 2) ÷ 30 | 0.933333 | ₹46,666.67 |

These match `CalcGuideController:66` verbatim, which indicates the guide was written against the
code. **No rounding is applied to `$factor` before it multiplies the CTC** (`:1311`); only
`round($factor,4)` is stored for display (`:1453`).

### 4.4 Component-based structure

`AppDataController::computeSlipFromComponents():2145-2337`. Returns `null` when there are no
components, so the caller falls back to `computeSlip`.

```
gross = round(ctc / 12, 2)                                                        :2160
sort components by seq ASC                                                        :2162
baseOf(c)  = lower(c.base) ?: (lower(c.calc_type)==='percent' ? 'pct_gross' : 'fixed')
isBasic(c) = str_contains(lower(c.code.' '.c.name), 'basic')                      :2173
isReimb(c) = lower(c.category)==='reimbursement' OR contains 'reimburs'           :2181-2188

Pass 1 — Basic:        basic += (fixed ? val : val/100 × gross)                   :2196-2201
Pass 2 — reimburse:    amt = pct_basic ? val/100×basic : (fixed ? val : val/100×gross)
                       reimbTotal += round(amt,2) ; wageGross = round(gross - reimbTotal, 2)
Pass 3 — earnings:     isBasic   → fixed ? val : val/100×gross
                       pct_basic → val/100 × basic
                       fixed     → val
                       else      → val/100 × gross     (incl. the documented pct_ctc)
                       earnings[name] += round(amt,2)                             :2224-2241
Pass 4 — balance:      remainder = round(wageGross - earnSum, 2)
                       n balance comps → each = round(remainder/n, 2), LAST takes the residue
                       else if abs(remainder) >= 1 → earnings['Special Allowance'] += remainder
                       if basic <= 0 → basic = reset($earnings)   // PF fallback  :2245-2262
Pass 5 — DA:           da = Σ earnings whose name contains 'dearness' or matches /(^|[^a-z])da([^a-z]|$)/
                       statutory( wageGross, basic + da, rates, ctx )             :2266-2273
Pass 6 — TDS:          tds = salaryTdsMonthly( round(wageGross × 12, 2), rates )  :2281
Pass 7 — deductions:   pct_basic → val/100×basic ; fixed → val ; else → val/100 × GROSS (not wageGross)
                       totalDed = round(array_sum($deductions), 2)                :2289-2310
Return:                gross = ctc/12 (INCLUDING reimbursements)
                       net   = round(max(0, gross - totalDed), 2)                 :2328-2336
```

**Worked example.** CTC ₹6,00,000 → gross ₹50,000. Components:
`Basic pct_gross 40` · `HRA pct_basic 50` · `Conveyance Reimbursement fixed 1600 (category=reimbursement)` · `Special Allowance balance`.
```
basic       = 40% × 50000                       = 20000.00
reimbTotal  = 1600.00       wageGross           = 48400.00
HRA         = 50% × 20000                       = 10000.00
earnSum     = 30000.00
remainder   = 48400 - 30000 = 18400.00          → Special Allowance
PF          = 12% × min(20000+0, 15000)         =  1800.00
ESI         : 48400 > 21000                     =     0.00
TDS         = salaryTdsMonthly(48400 × 12 = 580800) → taxable 505800 ≤ 12L rebate = 0.00
PT          : per state
earnings printed = Basic 20000 · HRA 10000 · Special 18400 · Conveyance Reimb. 1600
gross stored = 50000.00
```

**Rounding per pass:** each component amount is rounded to 2dp individually before summing;
the balance residue is allocated penny-safely (the only correct pattern in the codebase);
`totalDed` is `round(array_sum(...), 2)` over already-rounded values.

### 4.5 Provident Fund

`AppDataController::statutory():2064-2073`:
```php
$pfCap   = (float) ($r['pf_wage_cap'] ?? 15000);        // configurable
$pfRate  = (float) ($r['pf_rate']     ?? 12);           // configurable
$pfBase  = min(max($pfWage, 0.0), $pfCap);
$epsBase = min($pfBase, 15000.0);                        // HARDCODED ceiling

$pfEmployee  = round($pfBase * $pfRate / 100, 2);
$pfEmployer  = round($pfBase * $pfRate / 100, 2);
$eps         = min(round($epsBase * 8.33 / 100, 2), 1250.0);   // 8.33 and 1250 HARDCODED
$epfEmployer = round($pfEmployer - $eps, 2);
$edli        = round($epsBase * 0.5 / 100, 2);                 // 0.5 HARDCODED
```

`$pfWage` is `$basic` under `computeSlip`, and `$basic + $da` under `computeSlipFromComponents`.

**Worked example — CTC ₹6,00,000, default structure:**
```
basic       = 25000.00
pfBase      = min(25000, 15000)                    = 15000.00
PF employee = round(15000 × 12/100, 2)             =  1800.00
PF employer =                                         1800.00
EPS         = min(round(15000 × 8.33/100, 2), 1250) = min(1249.50, 1250) = 1249.50
EPF (er)    = 1800.00 - 1249.50                    =   550.50
EDLI        = round(15000 × 0.5/100, 2)            =    75.00
```

**Rounding: `round(...,2)` independently on each of the five figures.** The `min(...,1250.0)`
never binds because `round(15000 × 8.33/100, 2)` is `1249.50`. See DEF-P08.

**Worked example — CTC ₹2,40,000:** `basic = 10000` → `pfBase = 10000` → PF ₹1,200 each side;
EPS `round(833.00,2)` = ₹833.00; EPF employer ₹367.00; EDLI ₹50.00. Matches
`tests/Unit/PayrollMathTest.php:37`.

### 4.6 ESI

`statutory():2075-2078`:
```php
$esiThr      = (float) ($r['esi_threshold'] ?? 21000);
$esiEligible = ($gross > 0 && $gross <= $esiThr) || (! empty($ctx['esi_lock']) && $gross > 0);
$esiEmployee = $esiEligible ? (float) ceil($gross * ((float)($r['esi_employee_rate'] ?? 0.75)) / 100) : 0.0;
$esiEmployer = $esiEligible ? (float) ceil($gross * ((float)($r['esi_employer_rate'] ?? 3.25)) / 100) : 0.0;
```

`$gross` is the monthly gross under `computeSlip`, but **`wageGross`** (gross − reimbursements)
under `computeSlipFromComponents:2273`.

**Rounding: `ceil()` to the next whole rupee, applied independently to each side** — the ESIC
rule, and the only place in the engine that does not use `round(...,2)`.

**Worked examples:**
```
gross 20,000 → eligible → employee ceil(150.00) = 150.00 ; employer ceil(650.00) = 650.00
gross 18,777 → eligible → employee ceil(140.8275) = 141.00 ; employer ceil(610.2525) = 611.00
gross 21,000 → eligible → employee 158.00
gross 21,001 → NOT eligible → 0.00                    ← a ₹158 cliff on ₹1 of gross
```

**ESI contribution-period lock** — `esiPeriodLock():2011-2046`:
```
period start = month in 4..9   → "YYYY-04"
               month >= 10     → "YYYY-10"
               month in 1..3   → "(YYYY-1)-10"
if month <= periodStart                         → false   (fresh determination)
scan payslips for this employee, periodStart <= month < current, newest first, LIMIT 24
if ANY stored deduction KEY contains 'esi' (case-insensitive) with value > 0  → true
any exception, or no payslips table             → false
```
Consulted only when the plain rule would drop ESI (`compute():1306-1308`,
`grossEst > esi_threshold`). **Not consulted at all** by `liveSalaryAll()` (`:2027` hardcodes
`'esi_lock' => false`) or `simulate()` (key absent, `:2130-2138`).

### 4.7 Professional Tax

`ptForGross():1925-2000` + `ptWalkSlab():1897-1916`. Resolution order, exactly as coded:

```
1. FEMALE EXEMPTION                                                       :1933-1941
     on unless $r['pt_female_exempt'] === 0   (default 1)
     gender in ['female','f','woman','women'] (lowercased, trimmed)
     AND state non-empty AND str_contains(state, 'maharashtra')
     AND gross <= $r['pt_female_exempt_upto'] (default 25000)      → 0.0
2. state non-empty:
   2a. CONFIGURED $r['pt_state_slabs'] — first key K where str_contains(state,K)   :1950-1963
       (an explicit slab OUTRANKS the PT-free list)
   2b. PT-FREE = built-in ∪ $r['pt_free_states'] — first hit → 0.0                 :1965-1978
   2c. BUILT-IN PT_STATE_SLABS — first key K where str_contains(state,K)           :1980-1984
3. FALLBACK: $r['pt_slabs'] ?? [{upto:15000,amt:0},{upto:20000,amt:150},{upto:MAX,amt:200}]
   first slab where gross <= upto → round(amt,2) ; nothing matched → 0.0           :1986-1999

ptWalkSlab(rows, gross, key, month):
   for [upto, amt] in order: if (upto <= 0 || gross <= upto) { result = amt; break; }
   if key === 'maharashtra' && result >= 200 && substr(month,5,2) === '02' → 300.0
   return round(result, 2)
```

**Built-in PT-free states (10)** — `AppDataController:1852`:
`delhi · uttar pradesh · haryana · rajasthan · himachal · uttarakhand · chandigarh · jammu · ladakh · arunachal`

**Built-in state slab tables (16)** — `AppDataController:1854-1871`, each row `[gross upto, monthly ₹]`, `upto = 0` meaning "and above":

| State key | Bands |
|---|---|
| `telangana` | ≤15,000 → 0 · ≤20,000 → 150 · above → 200 |
| `andhra` | ≤15,000 → 0 · ≤20,000 → 150 · above → 200 |
| `maharashtra` | ≤7,500 → 0 · ≤10,000 → 175 · above → 200 · **February: 300 when the band is ≥200** |
| `karnataka` | ≤24,999 → 0 · above → 200 |
| `west bengal` | ≤10,000 → 0 · ≤15,000 → 110 · ≤25,000 → 130 · ≤40,000 → 150 · above → 200 |
| `tamil nadu` | ≤3,500 → 0 · ≤5,000 → 23 · ≤7,500 → 53 · ≤10,000 → 115 · ≤12,500 → 171 · above → 208 *(code comment: half-yearly slabs shown as monthly equivalents)* |
| `gujarat` | ≤12,000 → 0 · above → 200 |
| `madhya pradesh` | ≤18,750 → 0 · ≤25,000 → 125 · ≤33,333 → 167 · above → 208 *(annual ₹2,500 cap spread monthly)* |
| `kerala` | ≤1,999 → 0 · ≤2,999 → 20 · ≤4,999 → 30 · ≤7,499 → 50 · ≤9,999 → 75 · ≤12,499 → 100 · ≤16,666 → 125 · ≤20,833 → 166 · above → 208 |
| `bihar` | ≤25,000 → 0 · ≤41,666 → 83 · ≤83,333 → 167 · above → 208 |
| `odisha` | ≤13,304 → 0 · ≤25,000 → 125 · above → 200 |
| `assam` | ≤10,000 → 0 · ≤15,000 → 150 · ≤25,000 → 180 · above → 208 |
| `jharkhand` | ≤25,000 → 0 · ≤41,666 → 100 · ≤66,666 → 150 · ≤83,333 → 175 · above → 208 |
| `chhattisgarh` | ≤13,333 → 0 · ≤16,666 → 150 · ≤20,833 → 180 · ≤25,000 → 190 · above → 200 |
| `punjab` | ≤20,833 → 0 · above → 200 *(PSDT)* |
| `goa` | ≤15,000 → 0 · ≤25,000 → 150 · above → 200 |

**These are the values the code currently uses. The source itself labels them "Indicative
defaults (verify against current state law before filing)" (`:1847-1848`). They must be verified
against current state law before any migration relies on them.**

**Worked examples** (all confirmed by `tests/Unit/PayrollMathTest.php:55-104`):
```
Telangana 14,000 → 0        Telangana 20,000 → 150      Andhra 25,000 → 200
Karnataka 24,999 → 0        Karnataka 25,000 → 200      Gujarat 12,000 → 0
Maharashtra 9,000 → 175     Maharashtra 15,000 → 200    Maharashtra 15,000 in Feb → 300
Maharashtra 9,000 in Feb → 175   (a lower band is untouched in February)
Maharashtra female 25,000 → 0    Maharashtra female 30,000 → 200   Maharashtra male 20,000 → 200
Telangana female 20,000 → 150    (the exemption does not leak out of Maharashtra)
Delhi 90,000 → 0            no state, 18,000 → 150
```
**Rounding: `round(amt, 2)` on a table value — effectively a no-op.**

### 4.8 TDS on salary (Sec 192, new regime)

`salaryTdsMonthly():1824-1843` and `newRegimeTax():1752-1766`:
```php
// salaryTdsMonthly($annualCtc, $rates)
$std     = (float) ($r['std_deduction']    ?? 0);     // default 75000
$rebate  = (float) ($r['rebate_87a_limit'] ?? 0);     // default 1200000
$taxable = max(0.0, $annualCtc - $std);
$tax     = newRegimeTax($taxable, $r);
if ($taxable > $rebate)                                            // MARGINAL RELIEF
    $tax = min($tax, ($taxable - $rebate) * (1 + $r['cess_rate']/100));
return round(max(0.0, $tax) / 12, 2);

// newRegimeTax($taxable, $rates)
if ($taxable <= $r['rebate_87a_limit']) return 0.0;                 // 87A: a hard cliff to NIL
$tax = 0; $prev = 0;
foreach ($r['tds_slabs'] as $slab) {
    $upto = $slab['upto']; $rate = $slab['rate']/100;
    $cap  = $upto > 0 ? $upto : PHP_INT_MAX;
    if ($taxable > $prev) { $tax += (min($taxable,$cap) - $prev) * $rate; $prev = $cap; }
    if ($upto <= 0) break;
}
return round($tax * (1 + $r['cess_rate']/100), 2);
```

**Default slabs — `SettingsController:64-72`, fully editable:**
₹4,00,000 → 0% · ₹8,00,000 → 5% · ₹12,00,000 → 10% · ₹16,00,000 → 15% · ₹20,00,000 → 20% ·
₹24,00,000 → 25% · above → 30%. Other parameters: `std_deduction` ₹75,000 ·
`rebate_87a_limit` ₹12,00,000 · `cess_rate` 4% · `no_pan_tds_rate` 20% · `comm_tds_rate` 5%.

**Projection method: flat annualisation of the current month.** The input is `ctc × factor` —
the annual figure already scaled by *this month's* LOP. There is no year-to-date true-up, no
account of months already deducted, no bonus/OT/arrears inclusion, no employee declaration and
no old regime.

**Worked examples (re-derived numerically):**

| Annual CTC | Taxable (−75,000) | Slab tax + 4% cess | Marginal-relief cap | Annual TDS | Monthly |
|---|---|---|---|---|---|
| ₹6,00,000 | 5,25,000 | ≤12L → nil | — | ₹0 | **₹0.00** |
| ₹12,75,000 | 12,00,000 | ≤12L → nil | — | ₹0 | **₹0.00** |
| ₹12,85,000 | 12,10,000 | 63,960 | (10,000) × 1.04 = 10,400 | ₹10,400 | **₹866.67** |
| ₹13,00,000 | 12,25,000 | 66,300 | (25,000) × 1.04 = 26,000 | ₹26,000 | **₹2,166.67** |
| ₹20,00,000 | 19,25,000 | 1,92,400 | 7,54,000 — not binding | ₹1,92,400 | **₹16,033.33** |

₹20,00,000 derivation: `0–4L @0% = 0`; `4L–8L @5% = 20,000`; `8L–12L @10% = 40,000`;
`12L–16L @15% = 60,000`; `16L–19.25L @20% = 65,000` → slab tax ₹1,85,000 → +4% cess
₹1,92,400 → ÷12 = **₹16,033.33**.

**Rounding: `round(...,2)` after the cess multiply inside `newRegimeTax`, then `round(tax/12, 2)`
in the caller — two independent roundings.**

### 4.9 LWF and Conveyance

```php
// LWF — computeSlip:2119 / computeSlipFromComponents:2301
$lwf = (! empty($r['lwf_enabled'])) ? (float) ($r['lwf_employee'] ?? 0) : 0.0;   // flat ₹, no rounding

// Conveyance DEDUCTION — statutory():2082-2083
$convRate   = (float) ($r['conveyance_rate'] ?? 0);
$conveyance = (! empty($r['conveyance_enabled']) && $convRate > 0)
              ? round($pfBase * $convRate / 100, 2) : 0.0;      // pfBase = min(Basic+DA, cap)
```

### 4.10 Overtime and night allowance

```php
// OT valuation at entry — MasterController::save():761-762, only when the amount is blank
$hourly = $ctc > 0 ? ($ctc / 12 / 26 / 8) : 0.0;
$amount = round($hours * ($mult > 0 ? $mult : 2) * $hourly, 2);

// The same formula, duplicated — PayrollGenController::simulate():2165
$otAmt = round($otHours * $otMult * ($ctc / 12 / 26 / 8), 2);
```
**Worked example** — CTC ₹6,00,000, 8 hours at 2×:
`hourly = 600000/12/26/8 = 240.384615…` → `round(8 × 2 × 240.3846, 2) = 3846.15`.
`CalcGuideController:130` quotes ₹3,846 — matches. **Three successive float divisions before the
multiply; a single `round(...,2)` at the end.**

Night allowance (`compute():1326-1338`): `nightAmt += shift.allowance` per qualifying punch
date, then `round($nightAmt, 2)`.

### 4.11 Arrears

`IncrementController:366-376`:
```php
$diffMonthly   = ($newCtc - $oldCtc) / 12;
$firstFraction = ($eff->daysInMonth - $eff->day + 1) / $eff->daysInMonth;   // CALENDAR days
$months        = $firstFraction + 1.0 for each whole month up to (excluding) the current month;
$arrears       = round($diffMonthly * $months, 2);
if ($arrears >= 1) → insert an approved commission, purpose 'Salary Arrears', tds_rate 0
```
**Worked example** — old ₹6,00,000 → new ₹7,20,000, effective 15 May 2026, applied 3 July 2026:
```
diffMonthly   = 120000/12                       = 10000.00
firstFraction = (31 - 15 + 1)/31 = 17/31        = 0.5483871
months        = 0.5483871 (May) + 1 (June)      = 1.5483871
arrears       = round(10000 × 1.5483871, 2)     = 15483.87   ← paid entirely untaxed
```

### 4.12 Bulk incentive payout and 194H

`IncentiveController::payoutFor():299-344`:
```
manual     → [round(amount, 2), null]
collected  → figure = collected
target     → figure = collected ; ach = round(collected/target × 100, 1)
             payout 0 when target <= 0 OR ach < threshold (default 100)

flat       → p = figure × flat_rate / 100
slab       → rate defaults to the LAST band's rate; first band with (upto > 0 && figure <= upto) wins
             p = figure × rate / 100
portfolio  → rate for the row's portfolio (case-insensitive exact match), else 0
             p = figure × rate / 100
return round(p, 2)
```
**Worked example** — basis `collected`, formula `slab`,
bands `[{upto:200000,rate:1},{upto:500000,rate:2},{upto:0,rate:3}]`, collected ₹6,00,000:
```
band 1: 600000 <= 200000 ? no      band 2: 600000 <= 500000 ? no
band 3: upto 0 → the guard (upto > 0 && …) is false, nothing breaks
        → rate stays the pre-loop default = the LAST band's rate = 3
payout = 600000 × 3/100 = 18000.00
```

TDS 194H — `commit():217-221`:
```php
$tds = round($payout * $tdsRate / 100, 2);   // rate: request → comm_tds_rate → 5.0, clamped 0..100
$net = round($payout - $tds, 2);
if ($net <= 0) continue;                     // silently dropped
```
Stored as `gross_amount = payout`, `tds_amount = tds`, **`amount = net`** — the net is what
folds into payroll.

### 4.13 Gratuity — four implementations

```php
// (a) provision report — StatutoryController:54-57
$years = $e->doj ? Carbon::parse($e->doj)->diffInDays(now()) / 365.25 : 0;
$grat  = $years >= 5 ? round((15/26) * $s['basic'] * floor($years)) : 0;      // WHOLE rupees

// (b) full & final — ReportController:264-275
$basic = round(round($r->ctc/12, 2) * 0.5, 2);                                // re-derives 50% inline
$yrs   = Carbon::parse($r->doj)->diffInYears(Carbon::parse($r->last_day));
$grat  = $yrs >= 5 ? round(15/26 * $basic * $yrs, 2) : 0.0;                   // 2 dp

// (c) payslip CTC memo — AppDataController::payslipEmployerCost():1543
$gratuity = round($basic * 4.81 / 100, 2);                                    // monthly accrual
```
**Worked example** — Basic ₹25,000, 7.4 years:
(a) `round(15/26 × 25000 × 7)` = **₹1,00,962**; (b) `round(15/26 × 25000 × 7, 2)` = **₹1,00,961.54**;
(c) `round(25000 × 4.81/100, 2)` = **₹1,202.50/month**.

### 4.14 Statutory bonus

`StatutoryController:119-152`:
```php
$pct    = max(8.33, min(20.0, (float) ($rates['bonus_pct'] ?? 8.33)));
$basic  = computeSlip($e->ctc, $er)['basic'];          // FULL CTC — no LOP, no proration
$elig   = $basic > 0 && $basic <= 21000;
$wage   = $elig ? min($basic, 7000.0) : 0.0;
$months = 12; if (doj > fyStart) $months = max(0, min(12, (int) ceil($doj->diffInDays($fyStart->addYear()) / 30.44)));
$bonus  = $elig ? round($wage * $months * $pct / 100) : 0.0;    // WHOLE rupees
```
**Worked example** — Basic ₹18,000, joined 1 Aug 2026, 8.33%, FY from 1 Apr 2026:
`eligible` → `wage = 7000` → `months = ceil(243/30.44) = ceil(7.98) = 8` →
`round(7000 × 8 × 8.33/100) = round(4664.80) = 4665`.

### 4.15 Net pay — two assemblies

```php
// Engine — PayrollGenController:1350, :1393
netAvail = round(s.net + commission + nightAmt + otAmt, 2);   // s.net already max(0, …)
net      = round(netAvail - emiApplied - advApplied - cbApplied, 2);

// Simulator — :2206-2208 (different shape)
gross    = round(s.gross + commission + otAmt + nightAmt, 2);
totalDed = round(s.total_ded + emi + adv, 2);
net      = round(max(0.0, gross - totalDed), 2);              // clamps the FINAL net
```
The engine clamps only the pre-extras net; the Simulator clamps the final net. In practice both
land ≥ 0 because the engine caps every recovery — but see DEF-P06, where the two differ.

**Net in words** — `amountInWords():1428-1439` → `numToWordsIndian():1442-1489`, Indian grouping
(crore / lakh / thousand / hundred) with paise:
`round(max(amount,0), 2)` → `"Rupees Fifty Thousand and Fifty Paise Only"`.

### 4.16 Rounding rules per component — summary

| Component | Rounding | Where |
|---|---|---|
| Gross, Basic, HRA, Special | `round(...,2)` each, independently | `:2102-2105` |
| Each salary component amount | `round(...,2)` individually before summing | `:2216`, `:2238` |
| Balance component allocation | `round(remainder/n, 2)`; **the last absorbs the residue** | `:2249-2251` |
| PF employee / employer / EPS / EDLI | `round(...,2)` each, independently | `:2069-2073` |
| **ESI employee and employer** | **`ceil()` to the next whole rupee, each side independently** | `:2077-2078` |
| Professional Tax | `round(amt, 2)` on a table value | `:1915`, `:1996` |
| TDS | `round(tax × (1+cess), 2)` then `round(tax/12, 2)` | `:1764`, `:1842` |
| LWF | **none** — a flat configured amount | `:2119` |
| Conveyance | `round(...,2)` | `:2083` |
| Overtime | `round(...,2)` after three float divisions | `MasterController:762` |
| Night allowance | `round(sum, 2)` | `:1337` |
| Commission 194H | `round(gross × rate/100, 2)`, `round(gross − tds, 2)` | `IncentiveController:217-218` |
| Loan EMI | `round(emi, 2)` at read; `round(sum, 2)` per accumulation step | `:701`, `:1358` |
| Advance | `round(sum, 2)` | `:878` |
| Clawback | `round(amount, 2)`, `round(min(due, avail), 2)` | `:916`, `:1383` |
| `total_ded` | `round(sum of already-rounded values, 2)` | `:2122`, `:2310` |
| Net | `round(max(0, gross − total_ded), 2)`, then `round(netAvail − extras, 2)` | `:2129`, `:1393` |
| Run total `net_total` | `round(running float sum over every employee, 2)` — **no reconciliation against `SUM(payslips.net)` anywhere** | `:1427-1429`, `:1475-1477` |
| **Statutory bonus** | **`round(...)` to WHOLE rupees** | `StatutoryController:152` |
| **Gratuity (report a)** | **`round(...)` to WHOLE rupees** | `StatutoryController:57` |
| **Gratuity (report b)** | `round(...,2)` | `ReportController:281` |
| GST on a bank invoice | `round(taxable × 0.09, 2)` / `round(taxable × 0.18, 2)` | `BankPackController:319-321` |

**Where they differ and why it matters:** ESI is the only `ceil`; bonus and one gratuity report
are the only whole-rupee roundings; LWF is never rounded; and the two gratuity reports round
differently from each other. A client reconciling the gratuity provision report against the F&F
statement will see a 46-paise difference on the example above, and a different eligibility answer
at the 5-year boundary because one path uses `diffInDays/365.25` then `floor` and the other uses
`diffInYears`.

---

## 5. Configuration surface today

### 5.1 (a) What a customer CAN change without code

| Setting | Where | Granularity | Effective-dated? |
|---|---|---|---|
| `pf_wage_cap`, `pf_rate` | Statutory Rate Settings (`SettingsController::save():142`) | tenant | **No** |
| `esi_threshold`, `esi_employee_rate`, `esi_employer_rate` | same `:143` | tenant | No |
| `std_deduction`, `rebate_87a_limit`, `cess_rate`, `tds_slabs` (values only) | same `:145`, `:161-163` | tenant | No |
| `comm_tds_rate`, `no_pan_tds_rate` | same `:146` | tenant | No |
| `conveyance_enabled`, `conveyance_rate` | same `:147` | tenant | No |
| `pt_female_exempt`, `pt_female_exempt_upto` | same `:144` | tenant | No |
| `weekly_off_day`, `sat_off_mode` | same `:155-156` | tenant | No |
| `lop_basis` (`working \| calendar \| fixed30`) | same `:157` | tenant | No |
| `sandwich_rule` on/off | same `:149` | tenant | No |
| `bonus_pct` (clamped 8.33–20) | same `:150` | tenant | No |
| `payslip_show_ytd`, `payslip_dl_mode`, `payslip_dl_depts`, `payslip_dl_emps` | same `:148`, `:158-160` | tenant | No |
| `dra_gate` (`off\|warn\|block`), `points_gate_min`, `incentive_payout_lag`, `late_email_enabled` | same `:151-154` | tenant | No |
| **PF / ESI / PT / TDS / bonus / conveyance rates, scoped and dated** | Statutory Configuration (`StatutoryConfig`) | tenant → state → company → location (branch city) → branch, narrowest wins | **Yes — the payroll PERIOD decides, never "today"** |
| **PT state slabs and PT-free states** (`pt_state_slabs`, `pt_free_states`) | Statutory Configuration → slabs | per state, dated | Yes |
| Salary structure components: code, name, ctype, category, base, calc_value, seq, taxable, scope, scope_target, company_name | Salary Setup master (`MasterController` `salary-setup`) | employee / team / company / tenant | No |
| Late policy: mode, shift start/end, grace, free lates, cut mode, cut N, L1/L2/L3 thresholds and cuts, full/half-day hours, break budget, break cut | Late Policy master | employee / team / company | No |
| Working shifts: timings, night allowance, grace, hours, break budget; and the roster | Shifts master + roster | per shift, per day | No |
| Holidays | Holidays master | tenant | n/a |
| Leave types and their `paid` flag | Leave Types master | tenant | No |
| Pay cycle: `cutoff_day`, `pay_day`, `company_name`, `status` | Pay Cycle / Salary Schedules masters | company (matched by **name**) | Partly — `pay_cycles.effective_from` exists but only `PeriodResolver` reads it, and `PeriodResolver` is not wired in |
| Incentive schemes: title, purpose, portfolio, rate type/value, TDS rate, payout method, validity window, audience, caps, notify channels | Incentive Schemes screen | scheme | n/a |
| Bulk incentive run: basis, formula, flat rate, threshold, slab bands, portfolio rates, TDS override, payout method, payout date, purpose | Commission Calculator (per request) | per batch | n/a |
| Loans / advances / clawbacks / overtime / commission entries | request modules | per record | n/a |
| Active financial year | Financial Year screen | tenant | n/a |
| Payslip branding: logo, display name, tagline, colour | `ConfigController::brandFor()` | tenant + company | n/a |
| NEFT bank layout | `?bank=` query parameter | per download | **choice only from the four coded layouts** |

### 5.2 (b) What a customer CANNOT change without code — the SmartPRS 3 backlog

**Salary structure**
1. The default **Basic = 50% of gross** and **HRA = 40% of Basic** split (`AppDataController:2103-2104`).
2. The set of component bases — `fixed`, `pct_gross`, `pct_basic`, `balance` only. No `pct_ctc` (it is accepted in a comment and silently treated as `pct_gross`), no formula, no slab, no cap, no floor, no min/max, no dependency on another component beyond Basic (`:2225-2237`).
3. **How "Basic" is identified** — a substring match on `code + ' ' + name` (`:2173`).
4. **How "DA" is identified** — a name regex (`:2269`).
5. **How a "reimbursement" is identified** when `category` is unset — the substring `reimburs` (`:2186`).
6. The residual line name `'Special Allowance'` when no balance component exists (`:2257`).
7. Component scope levels — employee / team / company only. No grade, designation, branch, location, cost centre, or union/non-union (`resolveComponents():990-1012`).
8. Which components are taxable, PF-able, ESI-able or LWF-able **independently**. `taxable` is a migrated column that the engine never reads; PF-ability is decided by the name test, ESI-ability by the wage-gross carve-out, and nothing else is configurable.
9. Any per-employee salary override other than a `scope='employee'` component row. There is no per-employee rate table.

**Period, proration and attendance**
10. Cut-off days outside **2–28** (`:153`); weekly, fortnightly, bi-monthly, 4-4-5 and semi-monthly cycles.
11. The **26-day** divisor as a salary basis — offered nowhere despite being the FAQ headline.
12. A **payable-days** basis (paid days ÷ payable days) — not implemented.
13. **Zero punches = full pay** (`:1237`). No per-company, per-grade or per-employee override; no "treat as absent" mode.
14. **Half-day leave** — every leave day counts as 1.0 (`paidLeaveDays():1077`).
15. The unknown-leave-type default of **paid** (`:1065`).
16. The Nth-Saturday convention (`ceil(day/7)`, `:83`).
17. Per-company / per-branch / per-shift weekly offs — the setting is tenant-wide.
18. **Leaver proration** — nothing reads a date of leaving.
19. The **default shift 09:30–18:30** (`:37-38`) and the default full/half-day hours 9 / 4.5 (`:308-309`).
20. Break-cut modes beyond `half_day` and `per_30min`, and the literal `30` / `0.25` inside the latter (`:467-470`).
21. Late-cut modes beyond `simple` / `tiered` / `net_hours`, and beyond three tiers.
22. Whether the sandwich rule needs **both** sides uncovered or one (`sandwichDays():807`).

**Statutory**
23. **EPS 8.33%, the ₹1,250 cap, the ₹15,000 EPS wage ceiling, and EDLI 0.5%** (`:2067`, `:2071-2073`).
24. **All 16 PT state slab tables and the 10 PT-free states** are `private const` arrays — the `pt_state_slabs` override mitigates this but a new state still needs the JSON hand-authored, and the **fallback `pt_slabs` list is entirely unreachable** (§3.4 NEW-PAY-18).
25. The **Maharashtra February ₹300 uplift** (`:1911-1913`) and the fact it is keyed to one state name.
26. The **female PT exemption's gender word list** and its Maharashtra-only scope (`:1936-1938`).
27. **Which deduction heads probation/internship suppress** — PF, PT, TDS are hardcoded; ESI, LWF and Conveyance are hardcoded as still applying (`:2114-2117`).
28. The **employment-stage names** `probation` and `internship`.
29. **ESI's `ceil` rounding**, and the fact ESI is not charged on OT / commission / arrears.
30. The **ESI contribution-period boundaries** (April / October) and the fact the lock is detected by the substring `esi` in a stored JSON key.
31. **The old tax regime, surcharge, Chapter VI-A, HRA exemption u/s 10(13A), employee declarations, previous-employer income, Form 12BB, and any YTD true-up.** Grep confirms zero occurrences of `surcharge`, `old regime`, `vpf`, `admin charge`.
32. Any **per-employee TDS override** — the engine's own docblock promises one (`:1821-1823`); no column is read.
33. **LWF** — flat amount only, no state awareness, no periodicity, no employer share, and **unreachable from any UI**.
34. **Conveyance as a deduction** rather than an allowance, and its name collision with a "Conveyance Allowance" component.
35. Gratuity: `15/26`, the 5-year cliff, no ₹20L ceiling, no 4y240d rule, no 15/30 for non-covered establishments — and **four different implementations**.
36. Statutory bonus: the ₹21,000 eligibility ceiling, the ₹7,000 wage cap, the 30.44-day month, and the fact **nothing pays it**.
37. Overtime: the **26 and 8** in the hourly rate, in two separate copies.

**Recoveries**
38. The **recovery order** statutory → loan → advance → clawback (`:1350-1389`).
39. The **full-EMI-only** loan rule — no partial recovery, ever (`:1357`).
40. The **advance recovers in full in one month** rule; the `per_month` / `balance` spread columns are ignored.
41. The **clawback one-month-only** rule — no carry-forward.
42. A minimum-net-pay floor (only ≥ 0 is enforced; no "leave at least 50% of net" rule).
43. The 50-row limits on loans and clawbacks per employee (`:679`, `:913`).

**Run lifecycle and approval**
44. The **two-stage** HR → Finance shape. No third level, no parallel approvers, no delegation, no amount-based routing, no single-stage mode.
45. The role names `hr_manager` / `admin` / `super_admin` bound to those stages.
46. The **run state names** and the fact `paid` and `locked` are never written.
47. The **line state machine** — six states, five actions, no reverse transitions.
48. One run per company-month; **multiple lots are not supported** (`lot` is hardcoded to 1).
49. No **payslip edit** at any stage; the only correction is a full regenerate.
50. No **reopen** of a finalised run.
51. No **off-cycle / supplementary / arrears-only run**.

**Outputs**
52. The **four NEFT layouts** and every column name in them.
53. The bank narration format.
54. The **payslip PDF layout** — a single hardcoded Blade template. No per-company template, no field selection beyond the YTD toggle, no language option.
55. The **Fixed / Variable / Reimbursement grouping keywords**.
56. Which deduction keys the approval sheet and the wage register recognise (`Basic`, `HRA`, `PF`, `ESI`, `Professional Tax`, `TDS`).
57. The payslip ID format `PRS/<YYYYMM>/<empcode>`.
58. Statutory register layouts (PF ECR, ESIC challan, 24Q, 26Q) — all hardcoded column arrays.

**Structural**
59. **Money is float everywhere**, contradicting the documented invariant.
60. Roughly 60 columns and 7 tables are **created at runtime by controllers**, so the schema depends on which screens have been opened.
61. `denyUnlessRole()` **fails open**.

### 5.3 Explicit answers to the eight questions asked

**Q1 — Every proration / LOP divisor actually used, where each is chosen, and whether they are consistent.**
Five divisors are in simultaneous use: **working days** (default salary basis), **calendar days**
(`lop_basis=calendar`), **flat 30** (`lop_basis=fixed30`), **26** (overtime hourly and gratuity),
and **30.44** (statutory-bonus month count). The salary basis is chosen once, tenant-wide, at
`compute():1108-1111`; the factor is applied at `:1268-1287`. **They are not consistent**: only
the base-salary path honours `lop_basis`. Commission, overtime, night allowance and arrears are
never prorated at all; arrears prorate their first month by **calendar** days regardless of the
tenant's basis (`IncrementController:369`); overtime always uses 26; gratuity always uses 26;
bonus always uses 30.44; and Live Salary's zero-punch branch prorates by **elapsed working days**
ignoring `lop_basis` entirely (`:1610-1613`). A payable-days basis is not offered. Full detail
and the divisor table are in §4.2.

**Q2 — Is money decimal/integer-safe or float? Verify doc §12 yourself.**
**I verified it independently and the doc is correct.** `config/smartprs.php:128` claims *"All
monetary values use decimal columns + integer-safe math. Never float."*, repeated at
`README.md:73`. Storage is genuinely `DECIMAL` (payslips `decimal(12,2)`, payroll_runs.net_total
`decimal(14,2)`, employees.ctc `decimal(12,2)`, etc.). **Arithmetic is float throughout.**
`composer.json` requires no `ext-bcmath`; grep across `app/` finds **zero** occurrences of
`bcadd`, `bcmul`, `bcsub`, `bcdiv`, `brick/money`, or any integer-paise representation. Every
signature in the money path is typed `float` (`computeSlip(float $ctc)` `:2094`,
`statutory(float $gross, float $pfWage)` `:2059`, `ptForGross(float $gross): float` `:1925`,
`newRegimeTax(float $taxable)` `:1752`). A **float comparison decides whether an EMI is deducted**
(`:1357`), a **loose float equality** guards whether a commission line is written
(`if ((float) $camt == 0.0)`, `:2632`), the run total is a **running float sum over every
employee** (`:1201-1203`, `:1427-1429`), and the bank file total is computed twice by two
different float paths (`SalaryApprovalController:428` and `:483`) with no cross-check.
`SchemeController:290`, `:821`, `:832` use explicit `0.005` epsilons — the code itself
acknowledging float imprecision. **The claim's second half is false.** See DEF-P07.

**Q3 — The salary run state machine and what "lock" actually prevents.**
Full transition tables are in §3.7 and §6.
*States:* `draft → hr_approved → approved`, `rejected` from either stage. `paid` and `locked` are
recognised by `PayrollLock::FINALISED_STATUSES:31` and by `generate()`'s block list `:2362` but
**are never written by any code**. `payroll_runs.locked_at` *is* stamped on final approval
(`SalaryApprovalController:829`); the status is not.
*What "lock" prevents:* exactly two things.
(1) `generate()`'s own inline `in_array($existing->status, ['hr_approved','approved','locked','paid'])`
check (`:2362`) refuses to **regenerate** such a run. Note `rejected` is **not** in that list, so a
rejected run can be regenerated into a fresh draft — the undocumented recovery path.
(2) `PayrollLock::isPeriodFinalised()` is consulted by `EffectiveDated::guardFinalisedPeriod()`,
which is called from **only two places**: `StatutoryConfigController::save():158` and
`saveSlab():258`. It refuses a scoped statutory override whose effective date falls in a finalised
month, unless the user is `super_admin`.
*What "lock" does NOT prevent — verified by exhaustive grep of both call sites:* changing the
tenant-wide `statutory_settings` blob (`SettingsController::save()` has **no** period check and no
effective date); adding or approving attendance punches, leave or holidays (there is **no
attendance freeze anywhere**); applying an increment that overwrites `employees.ctc`; editing
commissions, overtime, advances or clawbacks; and **there is no reopen endpoint at all** —
`denyUnlessSuperAdmin()` has zero callers, so the documented "Super Admin can reopen" decision
(`PayrollLock:22-25`) is unimplemented. Two independent definitions of "finalised" therefore
exist (the inline array and the constant) and they happen to agree.

**Q4 — Which salary component names / behaviours are hard-coded strings in PHP.**
This is the #1 SmartPRS 3 blocker. **Exhaustive list**, all verified in source:

*Earning line names written into `payslips.earnings` JSON:*
`'Basic'`, `'HRA'`, `'Special Allowance'` (`PayrollGenController:2627`; `:1656`; `:2180`;
`AppDataController:2371`; `computeSlipFromComponents:2257`),
`'Commission'` (`:2638`; the per-purpose fallback at `:644`, `:649`, `:1319`),
`'Night Shift Allowance'` (`:2642`), `'Overtime'` (`:2646`),
`'Commission / Incentive'` and `'Overtime (Xh × Nx)'` and `'Night Shift Allowance (N nights)'`
(Simulator `:2182`, `:2185`, `:2189`),
`'Incentive'` (`IncentiveController:89`), `'Salary Arrears'` (`IncrementController:400`).

*Deduction line names written into `payslips.deductions` JSON:*
`'PF'`, `'ESI'`, `'Professional Tax'`, `'TDS'` (`computeSlipFromComponents:2288`;
`PayrollGenController:2650`; `AppDataController:2372`),
`'PF (employee)'` — **a different string for the same head** (`:1662`, `:2194`),
`'Labour Welfare Fund'` (`:2303`, `:2196`), `'Conveyance'` (`:2308`, `:2652`, `:2197`),
`'Loan EMI'` (`:2657`, `:1715`, `:1876`, `:2200`), `'Advance EMI'` (`:2660`),
`'Salary Advance Recovery'` (`:2663`, `:2203`), `'Salary advance recovery (this month)'`
(`:1726` — a third variant), `'Clawback / Reversal'` (`:2667`).

*Places that READ those literal keys back — every one breaks if a customer renames a component:*
`AppDataController::payslipViewData():1250-1257` (`Basic`, `HRA`, `Special Allowance`,
`Commission`, `PF`, `ESI`, `Professional Tax`, `TDS`) and `:1310-1321`;
`SalaryApprovalController::sheet():294-299` (`Basic`, `HRA`, `PF`, `ESI`, `Professional Tax`,
`TDS`) — **the approval sheet a manager signs off shows ₹0 in the Basic / HRA / PF / ESI / PT /
TDS columns for any company that renamed its components**;
`ReportController:172-192` (wage register, substring matching on `provident`/`pf`/`esi`/
`professional`/`pt`/`tds`);
`AppDataController::esiPeriodLock():2036` (**the ESI half-year lock is a substring search for
`esi` in the stored deduction key** — rename the line and the statutory lock silently stops).

*Behaviours keyed on a name rather than a flag:*
Basic identification `str_contains(code.' '.name, 'basic')` (`:2173`);
DA identification `contains 'dearness' OR /(^|[^a-z])da([^a-z]|$)/` (`:2269`);
Reimbursement identification `category==='reimbursement' OR contains 'reimburs'` (`:2181-2188`);
HRA legacy key `stripos(name,'hra') || stripos(name,'rent')` (`:2316`);
Payslip Fixed/Variable/Reimbursement grouping keywords
`reimburs` / `commission|incentive|bonus|overtime|arrear|ex-gratia|ex gratia|payout|variable`
(`payslipGroupEarnings():1617-1624`).

*Other hard-coded strings in the money path:*
Component base names `fixed|pct_gross|pct_basic|balance`; `ctype` values `earning|deduction`;
LOP basis names `working|calendar|fixed30`; weekly-off names and `sat_off_mode` values
`none|all|2_4|1_3`; late modes `simple|tiered|net_hours`; cut modes
`half_day_per_late|full_day_per_late|one_day_per_n`; break cuts `none|half_day|per_30min`;
run statuses `draft|hr_approved|approved|rejected|paid|locked`; line statuses
`pending|on_hold|in_review|approved|disbursed|acknowledged|rejected`; line actions
`approve|reject|hold|review|disburse|acknowledge`; scheme rate types `percent|fixed|open`;
payout methods `with_salary|separate`; incentive bases `collected|target|manual` and formulas
`flat|slab|portfolio`; employment stages `probation|internship`; DRA gate `off|warn|block`;
payslip download modes `all|none|dept|emp`; the four NEFT bank keys `generic|icici|hdfc|sbi`
with every column name; the narration prefix `'SALARY '`; the payslip ID prefix `'PRS/'`;
the commission lock reference `'run #<id> · <Mon YYYY>'` and lock source `'payslip <Mon YYYY>'`;
the scheme-withdrawal remark `'Scheme "<title>" was withdrawn'` and its `LIKE '%withdrawn%'`
restore match; the invoice prefix `'BPK/'`.

**Q5 — Can historical payroll be recalculated with the policy that applied at the time?**
**No. It silently uses today's policy.** Evidence:
- `SettingsController::rates($tid)` (`:91-102`) reads **one row** — `statutory_settings` where
  `tenant_id = ?` — a single JSON blob with **no effective date and no history**. Every
  recalculation of every month reads the same blob. `save():198-201` is an `updateOrInsert` that
  **overwrites** it; there is no versioning and no period guard on that screen.
- `PayrollGenController::compute():1094` calls `SettingsController::rates($tid)` with **no date
  argument at all**. Every rate the engine uses for a 2025 month is the rate stored today.
- The only effective-dated layer is `StatutoryConfig::applyScope()`, and it is an **override
  layer on top of the blob**: with no override rows saved (the default state, guarded by
  `hasAnyRows()` `:121-123`) it returns the input array untouched. So a tenant that has never
  opened the Statutory Configuration screen has **zero** effective dating.
- Even when overrides exist, they are dated on `$ctx['month'].'-01'` (`:129-131`) — correct — but
  they can only override rate *values*, never the LOP basis, the weekly-off day, the Saturday
  policy, the sandwich rule, the salary structure, or the pay cycle.
- **`employees.ctc` is overwritten in place** by an increment (`IncrementController:342`). There
  is no salary history table. Regenerating a past draft prices it at the **new** CTC.
- `salary_components` has **no effective-dating column at all**. Changing a structure re-prices
  every regenerable month.
- The one thing that *is* preserved is the **period window**: `period_start` / `period_end` are
  stored on the run and on every payslip (`generate():2545-2546`, `:2676-2677`), and
  `pay_cycles.effective_from` exists — but only `PeriodResolver` reads it, and `PeriodResolver`
  is not wired into the engine.
The mitigating factor is that a run past `draft` cannot be regenerated at all — so history is
protected by immutability, not by correct re-computation. The moment anyone regenerates a draft
(or a rejected run), it is re-priced at today's policy with no warning.

**Q6 — Rounding rules per component and where they differ.** Full table at §4.16.
Summary of the divergences: **ESI is the only `ceil()`**; **statutory bonus and one of the two
gratuity reports round to whole rupees** while everything else uses 2 dp; **LWF is never
rounded**; the **balance-component allocation is the only penny-safe residue pattern** in the
codebase; and **`total_ded` and `net_total` are `round()` over already-rounded floats**, with no
reconciliation anywhere that `SUM(payslips.net) = payroll_runs.net_total`.

**Q7 — Can payroll be generated more than once for a period, and what happens?**
One run per `(tenant, company_id, cycle_label)` — `existingRun():2279-2289`.
- **No existing run** → generate proceeds.
- **Existing run in `hr_approved` / `approved` / `locked` / `paid`** → HTTP **409**,
  *"A <status> run already exists for <month>. It can no longer be regenerated."* (`:2362-2365`).
- **Existing `draft` (or `rejected`) and `regenerate` not passed** → HTTP **409** with
  `needsConfirm: true` (`:2366-2368`). `tests/Feature/PayrollTest.php:36-47` asserts exactly this.
- **Existing `draft` (or `rejected`) with `regenerate=1`** → the previous run is **destroyed**:
  `DELETE payslips WHERE run_id = ?` then `DELETE payroll_runs WHERE id = ?` (`:2389-2390`), a
  `payroll.regenerate` row is written to `activity_logs` (`:2371-2387`), and **five side-effect
  reversals** run best-effort, each in its own try/catch so a failure never blocks:
  commission ledger debits deleted + `locked_at`/`locked_by`/`lock_source` cleared (`:2398-2419`);
  overtime `paid → approved` and `paid_run_id` cleared (`:2422-2432`);
  loan `installments_paid` decremented per ledger row, a run-closed loan re-opened, and the
  `loan_recoveries` rows deleted (`:2435-2452`); clawback `recovered_run_id`/`-amount`/`-at`
  cleared (`:2455-2465`). A **new run id** is then created.
**Consequences:** the previous run's id, its rejection remark, its approval stamps and its
per-line statuses are all lost — only the `activity_logs` entry survives, and it records the
*replaced* run id, not the rejection. Every line resets to `pending`, so the whole approval chain
must run again. And because the recompute reads today's rates, today's CTC and today's structure,
**a regenerate is a silent re-pricing** (see Q5). Note also that the reversal of commission
locks matches on `reference LIKE 'run #<id> %'` (`:2400`) — safe from matching run #12 only
because of the trailing space.

**Q8 — Every place a payroll calculation lives outside the payroll engine.**

| # | Location | What it computes | Divergence from the engine |
|---|---|---|---|
| 1 | `AppDataController::computeSlip()` `:2094-2131`, `computeSlipFromComponents()` `:2145-2337`, `statutory()` `:2059-2092`, `ptForGross()` `:1925-2000`, `salaryTdsMonthly()` `:1824-1843`, `newRegimeTax()` `:1752-1766` | **The whole slip.** This IS the calculator; `PayrollGenController` only orchestrates | n/a — but it is in a 3,090-line controller, not a service |
| 2 | **`AppController.php:12081-12085`** (browser JavaScript) | **PF and ESI for the entire PF/ESIC register screen**: `gross = ctc/12`, `basic = gross*0.5`, `pfW = min(basic, cap)`, `pe = Math.round(pfW*rate/100)`, ESI `Math.round(gross*rate/100)` | **Ignores `salary_components` entirely** (always 50% Basic); **`Math.round` instead of `ceil` for ESI**; ignores probation/internship; ignores the ESI period lock; ignores LOP. **The PF/ESI challan register therefore disagrees with the payslips.** |
| 3 | **`AppController.php:12093-12110`** (browser JavaScript) | **TDS for the whole TDS register screen**, with a client-side `tdsTax()` and a hardcoded 30% commission split default | Client-only; diverges from `salaryTdsMonthly` (no marginal relief); uses `employees.salaryType`/`commPct`, a notion unrelated to real commission entries |
| 4 | `AppController.php:11161` | A **hardcoded duplicate of the entire `SettingsController::defaults()` rate table** in JavaScript, including `tds_slabs` | Drifts silently whenever the PHP defaults change |
| 5 | `AppDataController::statutoryPdf():1727-1739` and `tdsReturnsHistory():1775-1783` | **Form 24Q salary TDS**, calling `newRegimeTax()` **directly, without the marginal-relief step and without ÷12** | **Materially different from the payslip TDS** for anyone just above ₹12L — see DEF-P09 |
| 6 | `AppDataController::payslipEmployerCost():1532-1550` | Employer PF, employer ESI (**back-derived as `esi × erRate / eeRate`**), EDLI (**re-derived as `min(basic,15000)×0.5%`**), gratuity accrual (**4.81%**), monthly CTC | A second implementation of `statutory()` that will drift |
| 7 | `AppDataController::payslipViewData():1278-1282` | **Paid Days and LOP days, recovered by regex from the free-text calculation note** | Prints `daysInMonth / 0` whenever the note lacks the sentence |
| 8 | `AppDataController::payslipYtd():1557-1584` | Financial-year-to-date earnings, deductions, gross, net | No tenant/company/run filter, no de-duplication |
| 9 | `AppDataController::payslipGroupEarnings():1591-1636` | Fixed / Variable / Reimbursement classification | Keyword heuristic when `category` is unset |
| 10 | `AppDataController::ensurePayrollHistory():2340-2380` | **Twelve months of payslips and runs, from full CTC with no LOP, no components and no extras**, 11 of them marked `paid` | See DEF-P01 — the most serious finding in this area |
| 11 | `PayrollGenController::simulate():2160-2208` | OT (`ctc/12/26/8`), night allowance, recovery caps and a **differently-shaped net assembly** | Duplicated OT formula; clamps the final net where the engine clamps the pre-extras net |
| 12 | `PayrollGenController::liveSalary():1610-1626` and `liveSalaryAll():2037-2045` | The LOP factor, twice more, with a zero-punch branch that prorates by elapsed working days | Diverges from payroll's zero-punch rule; `liveSalaryAll` hardcodes `esi_lock => false` and omits every extra |
| 13 | `MasterController::save():755-763` | **Overtime valuation** `ctc/12/26/8 × hours × multiplier` | The other copy of the OT formula |
| 14 | `MasterController::labourCodeWarning():846-892` | Runs the real engine on a **hardcoded ₹12,00,000 sample CTC** to check Basic+DA ≥ 50% | Advisory only |
| 15 | `IncrementController::apply():357-421` | **Backdated arrears** — a whole proration model of its own (calendar-day first month, whole months after) | Ignores `lop_basis`; `tds_rate` forced to 0 |
| 16 | `StatutoryController::report('gratuity'):54-57` | **Gratuity** `(15/26) × Basic × floor(years)`, whole rupees | One of four gratuity formulas |
| 17 | `StatutoryController::report('bonus'):119-152` | **Statutory bonus**, whole rupees, months via `diffInDays/30.44` | Nothing consumes the result |
| 18 | `StatutoryController::report('pt'):96-98` | The **annual PT** figure, special-casing Maharashtra as `200×11 + 300` regardless of the employee's actual band | A Maharashtra employee on ₹9,000 (₹175/month) is reported at ₹2,500/year instead of ₹2,100 |
| 19 | `ReportController:264-275` (F&F) | **Gratuity again**, re-deriving Basic as 50% of gross inline and ignoring `salary_components` | Disagrees with #16 on both rounding and year count |
| 20 | `ReportController:172-192` (wage register) | Maps stored deduction keys to PF/ESI/PT/TDS columns **by substring** | Breaks on renamed components |
| 21 | `SalaryApprovalController::sheet():294-299` | Reads `Basic`, `HRA`, `PF`, `ESI`, `Professional Tax`, `TDS` **by literal key** for the approval sheet | Shows ₹0 for any renamed component |
| 22 | `SalaryApprovalController:319-322`, `:428`, `:483` | Sheet totals and the **bank-file total, computed twice by two different float paths** | No cross-check |
| 23 | `IncentiveController::payoutFor():299-344` and `commit():217-221` | **Bulk incentive payout and 194H TDS** | No no-PAN higher rate, unlike the 24Q register |
| 24 | `SchemeController::validateClaim():274-289` and `recalcClaims():806-844` | **Scheme claim gross / TDS / net**, twice | The recalc's TDS fallback is a literal 5 |
| 25 | `BankPackController::invoiceSave():318-322` | **GST** CGST/SGST 9%+9% or IGST 18% | Literal rates |
| 26 | `PayrollPeriodAudit::cutoffFor():147-177` | **A third cut-off resolver**, reading only `pay_cycles` | Reports false DRIFT for any company configured on `salary_schedules` |
| 27 | `PeriodResolver` (whole class) | **A fourth period model**, including the correct `clamp()` the engine lacks | Not wired in |
| 28 | `SalaryRunController::generate():36-100` (dead) | `lwp = gross / totalDays × absence`; `net = gross − lwp − loanDeduction` — **a fifth proration model, always calendar days** | Unrouted; its Blade views do not exist |
| 29 | `App\Models\Loan::dueInstallment():32-39` (dead) | Loan EMI due | Unused |
| 30 | `App\Models\SalarySlip::totalDeductions():28-31` (dead) | `lwp + loan + other` | Unused |

---

## 6. Defects, conflicts and technical debt

Severity: **CRITICAL** = wrong money paid or payroll blocked · **HIGH** = wrong money in a
register/return, or a control bypassed · **MEDIUM** = wrong display or a fragile invariant ·
**LOW** = debt.

### DEF-P01 · CRITICAL — `/app/data` fabricates twelve months of payroll and permanently blocks the real runs
**What:** `AppDataController::ensurePayrollHistory():2340-2380` is called on every
`/app/data` load (`:458`). For each of the last 12 months with no existing run it **inserts a
`payroll_runs` row and a `payslips` row per employee**, with `'status' => $k === 0 ? 'draft' : 'paid'`
(`:2361`). The payslips are computed with `self::computeSlip((float) $e->ctc, …)` — **full CTC, no
LOP, no attendance, no salary components, no commissions, no recoveries** (`:2367`).
**Why it is worse than "demo data":** `$emps` at `:297` is **self-scoped for non-privileged
users** (`:268-270`, `selfScope()`). If a plain employee is the first person to open the app on a
fresh install, the entire company's twelve months of payroll history is created containing
**one person**, with `employees_count = 1`.
And it is not reversible through the UI: `generate():2362` refuses any month whose run is
`paid` — *"A paid run already exists for <month>. It can no longer be regenerated."* **The
back-fill therefore permanently blocks real payroll for the previous eleven months of every
company.** The fabricated slips also feed `esiPeriodLock()` (which scans stored payslips) and
`payslipYtd()`, and satisfy `PayrollLock::isPeriodFinalised()`, which then blocks statutory
configuration changes dated into those months.
**Evidence:** `AppDataController.php:458`, `:2340-2380`, `:2361`, `:2367`, `:268-270`, `:297`;
blocked by `PayrollGenController.php:2362-2365`.
**Impact:** eleven months of fabricated "paid" payroll per company that nobody can correct;
corrupted ESI period locks and YTD; a false finalised-period lock.
**Severity: CRITICAL.**
*Not recorded in doc §07 at all; the catalogue records it only as `BR-PAY-204 · IMPLEMENTED` with
a pointer to `DEFECT-18-02/03`.*

### DEF-P02 · CRITICAL — loans created through the live UI are never recovered (or are recovered forever)
**What:** `loanEmiDue():670-709` decides a loan is due using four tests. Three of them reference
columns that **do not exist in any migration and are never created at runtime**:

| Test | Column read | Exists? |
|---|---|---|
| `$emi = $ln->emi ?: $ln->installment_amount` `:682` | `installment_amount` | **No** |
| `$startM = $ln->start_month` `:687` | `start_month` | **No** |
| `$total = $ln->installments_total ?: $ln->tenure_months` `:691` | both | **No** |
| `$paid = $ln->installments_paid` `:692` | `installments_paid` | **runtime-created** at `generate():2580-2587` |
| `$ln->type === 'advance'` `:702` | `type` | **No** |
| `$ln->outstanding !== null && (float) $ln->outstanding <= 0 → skip` `:696` | `outstanding` | **Yes — migrated with `default(0)`** |

The migrated `loans` table is `id, tenant_id, employee_id, amount, emi, tenure, outstanding,
status, timestamps` (`2026_01_01_000300:119-129`). The column that *is* migrated — `tenure` — is
**never read by anything**. `ApprovalService::safeRow():265` is
`array_intersect_key($row, array_flip(Schema::getColumnListing($table)))`, so the request form's
`tenure_months` field (`AppController:3905`) is **silently discarded on every save**.
**The two failure modes:**
- **A loan created on the migrated schema has `outstanding = 0`** (the column default). The guard
  at `:696` reads `0 !== null && (float) 0 <= 0` → **`continue`**. **The EMI is never recovered at
  all.** Nothing in the application ever writes `loans.outstanding` — verified by grep across
  `app/`.
- **If `outstanding` is somehow non-zero** (a legacy row, a manual DB edit), then `$total` is
  `(int)(null ?: (null ?? 0)) = 0`, so the "fully recovered" guard `if ($total > 0 && $paid >= $total)`
  **never fires**, and the auto-close guard `if ($ln && $totL > 0 && …)` at `:2795` never fires.
  **The EMI is deducted every month forever**, until someone manually sets `status = 'closed'`.
**Evidence:** `PayrollGenController.php:682`, `:687`, `:691`, `:696`, `:702`, `:2442`, `:2794-2797`;
`ApprovalService.php:265`; migration `2026_01_01_000300:119-129`; grep: no writer of `outstanding`.
**Impact:** employee loans are either never recovered or over-recovered indefinitely. The
`Advance EMI` payslip line can never appear. The `start_month` delay never applies. The in-app
guide's promise that *"Loans auto-close after the last installment"* (`CalcGuideController:116`)
is false on the shipped schema.
**Severity: CRITICAL.**

### DEF-P03 · CRITICAL — the `commissions.status` ENUM does not contain any value the code writes
**What:** the migration creates `status ENUM('provisional','confirmed','reversed')`
(`2026_01_01_000300:80`). Every code path writes `'pending'`, `'approved'` or `'rejected'`
(`IncentiveController:81`, `:238`; `SchemeController:644`, `:839`, `:901`), and
`commissionByEmployee():605` filters on `where('status','approved')`.
On strict MySQL every commission insert throws `SQLSTATE[01000] Data truncated`; on non-strict
MySQL the status is **silently blanked**, so no commission is ever `approved` and **no commission
ever reaches a payslip**. The project has a standard remedy for exactly this
(`SchemeController::ensure():102-117` widens a legacy ENUM to VARCHAR) but applies it only to
`incentive_schemes` — **never to `commissions`**. Compare `2026_06_24_000100_widen_payroll_runs_status.php`,
which exists solely because the identical problem bit `payroll_runs.status` in production.
**Evidence:** migration `:80`; writers as cited; no widening migration for `commissions`.
**Impact:** on a freshly migrated database, commissions, incentives and arrears never pay.
**Severity: CRITICAL.**

### DEF-P04 · HIGH — no attendance freeze, so a draft is re-priced by anything that changes afterwards
**What:** nothing marks an attendance window closed before payroll. A punch, a leave approval, a
holiday, a rate change, a component change or an increment applied *after* a draft is generated
changes the numbers the moment anyone regenerates. The only protection is that a run past `draft`
cannot be regenerated at all.
**Evidence:** grep — no freeze/close/cutoff flag on `attendance_logs` or `leaves`;
`PayrollGenController:2362`.
**Impact:** a draft reviewed by HR on the 1st is not the draft Finance approves on the 5th.
**Severity: HIGH.**

### DEF-P05 · HIGH — mid-month joiner and sandwich proration are wrong under a cut-off cycle
**What:** both `workingDaysInRange():97-109` and `sandwichDays():770-816` iterate
`Carbon::create($start->year, $start->month, $d)` for `$d` up to `$daysInMonth`. Under a cut-off
window `$start` is in the **previous** month and `$daysInMonth` is the **period length** (e.g. 30
for 21 Jun–20 Jul), not a day-of-month bound. The loop therefore indexes the wrong dates.
Additionally, the joiner test `doj->format('Y-m') === $month` (`:1221`) compares the DOJ to the
**payroll month label**, so under a 21→20 cycle a 25 June joiner — who belongs to the July
payroll month — fails the test entirely and is paid as a **full-month** employee.
**Evidence:** `:97-109`, `:770-816`, `:1216-1228`, `:1263`.
**Impact:** joiners over-paid and sandwich LOP mis-counted on every cut-off cycle.
**Severity: HIGH.**

### DEF-P06 · HIGH — the payslip can fail to reconcile on its own face
**What:** `computeSlip`/`computeSlipFromComponents` clamp `net = round(max(0, gross − total_ded), 2)`
(`:2129`, `:2335`) while leaving `total_ded` at its full value. `compute()` then stores
`gross = s.gross + extras` and `ded = s.total_ded + extraDed` (`:1391-1392`). When statutory
deductions alone exceed gross — possible on a heavily prorated slip where PT and LWF are flat —
the stored `gross − ded ≠ net`. The payslip PDF prints
*"Net = Gross earnings (A+B) − total deductions"* (`payslip-pdf.blade.php:198`), which is then
arithmetically wrong on the document the employee receives.
**Severity: HIGH.**

### DEF-P07 · HIGH — the documented money invariant is false
**What:** `config/smartprs.php:128` and `README.md:73` assert *"decimal columns + integer-safe
math. Never float."* Storage is decimal; **all arithmetic is float**. Independently verified: no
`bcmath` requirement in `composer.json`, zero `bcadd`/`bcmul`/`bcsub`/`bcdiv`/`brick/money`
anywhere in `app/`. Full evidence table at §3.10 and Q2 in §5.3.
**Concrete consequences:** the ₹1,249.50 EPS artefact (DEF-P08); a 1-paise Special Allowance
drift every month at CTC ₹13,00,000; a **float comparison decides whether an EMI is deducted**
(`:1357`); a **loose float equality** guards a commission payslip line (`if ((float) $camt == 0.0)`,
`:2632`); `net_total` is a running float sum with **no reconciliation** against
`SUM(payslips.net)`; the bank-file total is computed twice by two float paths with no cross-check.
**Impact:** a documented invariant that the code does not hold — reviewers stop checking.
**Severity: HIGH.**

### DEF-P08 · HIGH — EPS is computed as ₹1,249.50, not the statutory ₹1,250
**What:** `$eps = min(round($epsBase * 8.33 / 100, 2), 1250.0)` (`:2071`). At the ₹15,000
ceiling this is `min(1249.50, 1250.0) = 1249.50`, and `epfEmployer = 1800.00 − 1249.50 = 550.50`.
The EPFO figure at the ceiling is ₹1,250 with EPF-employer ₹550. The 8.33% is a rounded statement
of the statutory split, not the split itself.
**Impact:** the PF ECR/challan register misallocates ₹0.50 per employee per month between two
EPFO accounts and **will not tie to the portal's own computation**. *Flagged as needing statutory
verification — this is the value the code currently uses, not an assertion of correct law.*
**Severity: HIGH.**

### DEF-P09 · HIGH — Form 24Q reports a different salary TDS from the payslip
**What:** the 87A rebate is a hard cliff-to-nil **inside** `newRegimeTax():1755`, while the
**marginal relief** is applied only by `salaryTdsMonthly():1836-1840`. `statutoryPdf()` calls
`newRegimeTax()` **directly** at `:1732`, and `tdsReturnsHistory()` at `:1779`, skipping the
relief step and the ÷12. For CTC ₹12,85,000 the payslip deducts ₹866.67/month (₹10,400/year,
capped by marginal relief) while the 24Q register reports **₹63,960**. The 24Q register also uses
`employees.salary_type` / `comm_pct` with a **hardcoded 30% default commission split**
(`:1708`, `:1730`, `:1778`) — a notion unrelated to any real commission entry.
**Severity: HIGH.**

### DEF-P10 · HIGH — the bank-file auto-disburse bypasses the four-eyes control
**What:** `bankFile():499-503` updates `payslips.line_status` to `disbursed` **directly**,
bypassing `applyLineAction()` entirely. Two consequences:
(a) BR-PAY-028's guard *"a line may be disbursed only after its parent run has cleared HR
approval"* (`:583-588`) **is not applied** — `bankFileQuery():342-367` never checks the run's
status, so a Finance user can HR-approve lines on a **still-draft run**, download the bank file,
and every line flips to `disbursed` with the run never having passed HR approval.
(b) `notifyLine()` is not called, so employees disbursed via the bank file receive **no
disbursement email and no payslip attachment**.
Compounding this, the whole operation is a **GET** that mutates state (see SEC-P04).
**Severity: HIGH.**

### DEF-P11 · HIGH — the salary approval sheet and the wage register show ₹0 for renamed components
**What:** `SalaryApprovalController::sheet():294-299` reads `$earn['Basic']`, `$earn['HRA']`,
`$ded['PF']`, `$ded['ESI']`, `$ded['Professional Tax']`, `$ded['TDS']` **by literal key**. A
company whose Salary Setup names its components anything else — "Basic Pay", "House Rent
Allowance", "EPF", "Prof. Tax" — gets a sheet in which every one of those columns reads ₹0, while
Gross / Deductions / Net are correct. **That sheet is what a manager signs off.**
The same class of bug affects `payslipViewData():1250-1257` and the wage register
`ReportController:172-192`.
**Most seriously**, `esiPeriodLock():2036` detects the ESI half-year lock by
`stripos((string) $k, 'esi') !== false` on the stored deduction **key** — rename the ESI line and
**the statutory contribution-period lock silently stops working**.
**Severity: HIGH.**

### DEF-P12 · MEDIUM — the salary voucher PDF route always 500s
**What:** `SalaryApprovalController::voucher():548` renders
`Pdf::loadView('voucher-pdf', …)`. **`resources/views/voucher-pdf.blade.php` does not exist** —
verified by `find . -name "voucher-pdf*"`, which returns nothing. The route
`GET /app/salary-lines/{id}/voucher` (`routes/web.php:472`) therefore throws
*View [voucher-pdf] not found* for every disbursed line.
**Severity: MEDIUM** (a feature that is advertised in the UI and cannot work).

### DEF-P13 · MEDIUM — the period audit command reports false DRIFT
**What:** `PayrollPeriodAudit::cutoffFor():147-177` reads **only `pay_cycles.cutoff_day`**,
skipping `salary_schedules` — which is the table the engine consults **first**
(`resolveCutoffDay():138`). A company whose cut-off lives on `salary_schedules` is audited against
the calendar month and **every run is reported as DRIFT**. The command also compares against
`PeriodResolver::resolve()`, a different period model reading a different column
(`pay_cycles.period_end_day`) — so three period models are in play in one command.
**Severity: MEDIUM.**

### DEF-P14 · MEDIUM — a latent February overflow in the engine's cut-off maths
**What:** `Carbon::createFromFormat('Y-m-d', $month.'-01')->day($c)` at `:125` and `:175-176`
does **not clamp**. With `$c = 30` and `$month = '2026-02'`, Carbon's `day()` setter overflows and
30 February becomes **2 March**. `PeriodResolver::clamp():280-285` exists precisely to prevent
this and is explicitly tested (`tests/Unit/PeriodResolverTest.php:37-42`) — the engine does not
use it. The bug is currently unreachable **only because** `resolveCutoffDay()` rejects anything
outside 2–28 (`:153`). That is defence by coincidence.
**Severity: MEDIUM** (latent; becomes CRITICAL the moment the 2–28 range is widened).

### DEF-P15 · MEDIUM — `resolvePayDate()` contains a complete, dead implementation
**What:** `PayrollGenController::resolvePayDate():825-861` builds a 30-line `$pick` closure that
scans both masters for a `pay_day`, then **never calls it** — the method unconditionally
`return AppDataController::payDateFor(...)` at `:860`.
Related: **three different pay-date rules exist** — `payDateFor()` (cut-off → same month, else
next month), the dead `$pick`, and `PeriodResolver::payDate()` (first occurrence on or after the
period end, `:111-124`), which for a 21→20 window with pay day 5 gives **5 August** where
`payDateFor` gives **5 July**.
**Severity: MEDIUM.**

### DEF-P16 · MEDIUM — the payslip's Paid Days / LOP come from a regex over English prose
**What:** `payslipViewData():1278-1282` recovers the two figures with
`preg_match('/Paid\s+([\d.]+)\s+of\s+(\d+)\s+days/i', $note, $pm)`. `calcNote():537-540` only
emits that sentence when `factor < 0.9999`; otherwise the payslip prints `daysInMonth / 0`.
Change the wording and every payslip silently reverts to "full month".
**Severity: MEDIUM.**

### DEF-P17 · MEDIUM — the run total overstates what will be paid
**What:** `payroll_runs.net_total` is written once at generate from `$c['totals']['net']`
(`:2549`) and is **never corrected**. A line that is later rejected or held still contributes to
it. The header therefore overstates the disbursement, and there is no reconciliation query
anywhere comparing it to `SUM(payslips.net)`.
**Severity: MEDIUM.**

### DEF-P18 · MEDIUM — a rejection at run level records no author and no time
**What:** `applyDecision():809` writes only `status = 'rejected'`, `remarks` and `updated_at`.
No `by` and no `at` stamp. You can see *that* a run was rejected and the remark, but not who or
when. And a regenerate **deletes the row**, so the rejection disappears entirely — only the
`activity_logs` `payroll.regenerate` entry survives, and it records the replaced run id, not the
rejection.
**Severity: MEDIUM** (audit gap on a money control).

### DEF-P19 · MEDIUM — advances are lost, not carried forward
**What:** `advanceRecovery():869-887` is bounded on both sides by `created_at`. If the net cannot
cover the advance, `advApplied` is capped (`:1370`) and the shortfall is **noted in prose and
never retried** — the window has passed. There is no advance ledger (BR-PAY-033), so a regenerate
cannot reverse an advance recovery either. The migrated `per_month` and `balance` columns model
exactly the spread recovery that no code implements.
**Severity: MEDIUM — money-affecting.**

### DEF-P20 · MEDIUM — `computeSlipFromComponents` edge cases
| # | Issue | Location |
|---|---|---|
| a | A component named `Basic` with `base='balance'` is added to `$basic` in Pass 1 as `val/100 × gross` (usually 0) and then **skipped** in Pass 3. `$basic` ends at 0, so the PF fallback uses `reset($earnings)` — the lowest-`seq` earning. **PF is computed on the wrong wage.** | `:2196-2200`, `:2225-2227`, `:2260-2262` |
| b | `isBasic()` is a substring test, so *"Basic Travel Allowance"* or code `BASIC_TA` enters the PF wage | `:2173` |
| c | With no balance component and a **negative** remainder, `Special Allowance` receives a negative amount and prints as a negative earning | `:2255-2259` |
| d | A remainder of `abs(...) < 1` is silently discarded while `gross` stays `ctc/12`, so earnings do not sum to gross | `:2255` |
| e | Deduction components using `pct_gross` are computed on `gross` (**including** reimbursements) while earnings and the statutory base use `wageGross` — an inconsistent base | `:2296` |
| f | `pct_ctc` is named in the comment at `:2235` but falls into the `else` branch and is computed as a **percent of monthly gross**, not of CTC | `:2235-2237` |
| g | The ESI pre-check in `compute():1297` uses full gross while `computeSlipFromComponents:2273` tests ESI against `wageGross` — with reimbursements the two disagree at the ceiling and `esi_lock` may not be looked up when it should be | as cited |
**Severity: MEDIUM.**

### DEF-P21 · MEDIUM — PT configuration holes
`pt_slabs` (the flat fallback) is **read but unreachable** — not in `defaults()`, not in `save()`'s
validation, filtered out of `StatutoryConfig` payloads. `pt_amount` (default ₹200) is settable,
validated and UI-exposed but **nothing reads it**. A malformed configured slab row silently
returns **₹0** for a whole state (`ptWalkSlab():1899-1910`). State matching is a **substring** test
in all three passes. The PT report special-cases Maharashtra's annual figure as `200×11 + 300`
regardless of the employee's actual band (`StatutoryController:96-98`).
**Severity: MEDIUM.**

### DEF-P22 · MEDIUM — TDS slab order is load-bearing and unvalidated
The walk uses `$prev = $cap` and `break`s on `upto <= 0` (`:1756-1762`), so slabs **must** be
ascending with the open band last. `SettingsController::save():190-195` neither sorts nor
validates. An admin who reorders the rows silently corrupts every employee's tax.
**Severity: MEDIUM.**

### DEF-P23 · MEDIUM — no YTD true-up, and extras are outside every statutory base
TDS is a flat annualisation of the current month's prorated CTC (`salaryTdsMonthly($ctc × factor)`).
One month at 90% attendance is taxed as if the whole year were at 90%; there is no correction,
ever. Separately, commission, incentive, overtime, night allowance and arrears are added **after**
`computeSlip` returns (`:1391`), so **none of them attract PF, ESI, PT or TDS**. ESI is
statutorily due on overtime, and all of it is taxable salary. Arrears are created with
`tds_rate = 0` explicitly (`IncrementController:405`).
**Severity: MEDIUM–HIGH** (compliance exposure; flagged as needing statutory verification).

### DEF-P24 · MEDIUM — statutory bonus is computed and never paid
`StatutoryController:113-165` produces a provision. Grep confirms `PayrollGenController` never
reads `bonus_encashment`. `CalcGuideController:136` tells the client *"Paying happens through
Bonus & Encashment entries"* — an approved bonus entry appears on **no** payslip.
**Severity: MEDIUM.**

### DEF-P25 · MEDIUM — four gratuity implementations that disagree
`StatutoryController:54-57` (whole rupees, `diffInDays/365.25` then `floor`),
`ReportController:264-275` (2 dp, `diffInYears`, re-derives Basic as 50% inline **ignoring
`salary_components`**), `AppDataController:1543` (4.81% monthly accrual). At 4.99 vs 5.00 years
by the two measures, **one report pays and the other does not**. No ₹20L ceiling, no 4y240d rule
(`ScreenHelpController:1926` promises it), no 15/30 for non-covered establishments.
**Severity: MEDIUM.**

### DEF-P26 · MEDIUM — the in-app calculation guide contradicts itself and the engine
`CalcGuideController:183` states *"Pay Day N pays on the Nth of the following month … The
attendance period itself is always the calendar month."* — which contradicts both the cut-off
support and `payDateFor()`'s same-month branch, and contradicts **section 3 of the same
document** (`:75`), which states the rule correctly. `:161` headlines *"why gross ÷ 26"* when ÷26
is never a LOP divisor. `:116` promises loans auto-close (see DEF-P02).
**Severity: MEDIUM** (this screen is a promise to clients about engine behaviour).

### DEF-P27 · LOW/MEDIUM — dead and misleading code
| Item | Evidence |
|---|---|
| `SalaryRunController`, `PayslipController`, `LoanController`, `App\Models\SalaryRun`, `SalarySlip`, `Loan` | unrouted (`routes/web.php:693` redirects `/loans`, `/salary-runs`, `/payslips` to `/app`); target `salary_runs`/`salary_slips` tables **that do not exist**; their Blade views (`salary_runs.index`, `payslips.index`, `loans.form`) are **absent from the repository** |
| `monthMeta()`'s Sunday-only `$working` | `:181`, `:193` — destructured away at all four call sites and recomputed correctly; a trap for the next maintainer |
| `resolvePayDate()`'s `$pick` closure | `:827-856` — built, never called |
| `payroll_runs.schedule_id`, `approved_by` | migrated, never read or written |
| `payslips.net_words`, `pdf_path` | migrated, never written |
| `loans.tenure` | migrated, never read |
| `advances.per_month`, `balance` | migrated, never read |
| `pt_amount`, `pt_slabs` | see DEF-P21 |
| `resolveComponents()`'s `''` scope branch | `:995` `?: 'company'` makes `:1011`'s `''` test unreachable |
| `payroll_runs.lot` | hardcoded to 1 at `:2542`; multiple lots unsupported |

### DEF-P28 · LOW — migration filenames do not match their contents
Three of the five assigned migrations create entirely unrelated tables (§2.1). There is no
`salary_runs` or `salary_slips` table; the payroll tables live in
`2026_01_01_000300_create_attendances_table.php`.

### DEF-P29 · LOW — runtime DDL inside request handling
`generate()` issues up to five DDL statements before its transaction (`:2556-2616`), deliberately
outside it because MySQL DDL implicitly commits. `SalaryApprovalController::ensureCols()` and
`ensureLineCols()` do the same on list and sheet views. `MasterController::ensureCols()` creates
whole tables. `SettingsController::ensureTable()`, `SchemeController::ensure()`,
`BankPackController::ensureInvoices()`, `FinYearController::stamp()` and
`ApprovalService::logCommission()` all issue DDL at request time. This requires the application
DB user to hold DDL privileges in production and makes the schema depend on click history.

### DEF-P30 · LOW — bulk operations are unbounded
`lineBulk():696-725` and `bulk():862-898` accept an unbounded `ids` array; each iteration runs its
own `payroll_runs` lookup (`:584`) and queues its own email. A 5,000-id bulk disburse is 5,000
queries and 5,000 mails in one request. `bulk()` also collects `$errors` at `:885` and **never
returns them** — only counts reach the caller.

---

## 7. Security findings

> **Scope note, verified:** every route in this area sits in one group (`routes/web.php:238`)
> whose middleware is `['auth', LicenseGate, EnsureSubscriptionActive, DemoWriteGuard,
> EditionGuard]`. **There is no role middleware.** Authorisation is entirely by in-controller
> calls, so any endpoint that omits the call is open to every authenticated user in the tenant —
> including a plain `employee`.

### SEC-P01 · HIGH — `denyUnlessRole()` fails OPEN
**What:** `ApprovalService::denyUnlessRole():365-380` wraps the entire role check in
`try { … } catch (\Throwable $e) { return null; }`. **`null` means allowed.** The comment states
the intent: *"fail open — availability over strictness on pivot errors"*.
**Exploitability:** any exception while reading the Spatie role pivot — a missing
`model_has_roles` table, a deadlock, a permission-cache miss during a deploy — grants the caller
full access to every payroll endpoint that relies on it: `preview`, `generate`,
`IncentiveController::calculate`/`commit`, `BankPackController`, `ComputedController`,
`PointsController::autoApply`.
**Impact:** payroll generation and bulk incentive commits by an unprivileged user during any
transient DB fault.
**Severity: HIGH.**
*Note the deliberate inconsistency: `PayrollLock::denyUnlessSuperAdmin():91-96` fails **closed**
for the same class of error, while `EffectiveDated::guardFinalisedPeriod():115-117` — which calls
it — fails **open**.*

### SEC-P02 · HIGH — the statutory PDF registers have no role guard at all
**What:** `GET /app/statutory/{type}/pdf` (`routes/web.php:308`) →
`AppDataController::statutoryPdf():1639`. I read the method from its first line: **there is no
`denyUnlessRole`, no `abort_unless`, no `hasAnyRole` check of any kind.**
**Exploitability:** any authenticated user — a plain `employee` — can download the PF ECR
register, the ESIC challan, the Form 24Q statement and the Form 26Q deductee register, each
listing **every colleague's employee code, name, PAN, UAN, PF wage, gross salary and tax**.
**Compounding:** `$tenantId = $request->user()->tenant_id ?? DB::table('tenants')->value('id')`
(`:1641`) — a user with a **null** `tenant_id` is silently served the **first tenant's** data.
The employee query at `:1643` then uses that id with no further scoping.
**Impact:** bulk disclosure of the entire company's salary and PAN data to any logged-in user;
potential cross-tenant disclosure.
**Severity: HIGH.**

### SEC-P03 · MEDIUM — the bank-file preview has no role guard
**What:** `bankFile():454-456` checks `isFinance()`. **`bankFilePreview():416-441` does not** —
it is guarded only by `auth`. Any authenticated user can call
`GET /app/salary-runs/{id}/bank-file/preview` and learn the run's cycle, the payable count, the
**total net payable**, and the names and codes of every employee skipped for missing bank details.
**Severity: MEDIUM.**

### SEC-P04 · MEDIUM — a state-changing, money-affecting operation is a GET
**What:** `GET /app/salary-runs/{id}/bank-file` (`routes/web.php:466`) both streams the CSV **and**
mutates the database: `bank_filed_at` is stamped and every `approved` line flips to `disbursed`
(`:495-504`). A GET is not CSRF-protected, is prefetchable by browsers and link-scanners, and is
retried freely. Combined with DEF-P10, a single link visit by a Finance user marks a whole run
disbursed without any HR approval of the run.
**Severity: MEDIUM.**

### SEC-P05 · MEDIUM — `FinYearController::index()` has no role guard
**What:** route `routes/web.php:337`, group is `auth` only. The method computes `canManage`
(`:105`) as a **flag returned to the client** but serves the data regardless — per-FY record
counts and **money totals across seven tables for the whole tenant** to any authenticated user.
`setActive()` is correctly guarded (`:148`).
**Severity: MEDIUM.**

### SEC-P06 · MEDIUM — identity resolution falls back to matching on name
**What:** `SalaryApprovalController::currentEmployeeId():142-156` resolves the acting user to an
employee by `users.employee_id`, else `employees.email`, else **`employees.name`**. The same
pattern appears in `PayrollGenController::liveSalary():1513-1518`, `liveSalaryAll():1951-1953`,
`SchemeController::me():156-162` and `AppDataController::payslipPdf():1183-1186`.
**Exploitability:** in a tenant with two employees of the same name, the name fallback can bind a
user to the wrong employee record — granting them that person's Live Salary, their payslip PDF,
and the ability to **acknowledge their salary line** (`acknowledge():745-748` compares
`currentEmployeeId()` to `slip->employee_id`).
**Severity: MEDIUM.**

### SEC-P07 · MEDIUM — the leave-type paid/unpaid lookup is not tenant-scoped
**What:** `paidLeaveDays():1059-1061` reads `DB::table('leave_types')->pluck('paid','id')` with
**no tenant filter**, unlike its sibling `paidLeaveDates():734-739` which does filter. A
`leave_types` row belonging to another tenant can therefore decide whether this tenant's leave day
is paid — directly changing pay.
**Severity: MEDIUM** (money-affecting cross-tenant leakage).

### SEC-P08 · MEDIUM — `esiPeriodLock()` queries `payslips` with no tenant filter
**What:** `:2029-2032` filters only on `employee_id` and the month range. Safe today only because
`employees.id` is globally unique across tenants; it is a latent cross-tenant read on a statutory
determination.
**Severity: MEDIUM.**

### SEC-P09 · MEDIUM — `payslipYtd()` has no tenant, company or run filter
**What:** `:1564-1566` selects every `payslips` row for the employee in the FY window. Combined
with the fabricated back-fill (DEF-P01), the YTD column on a real payslip includes fabricated
figures. With multiple lots per month it would double-count.
**Severity: MEDIUM.**

### SEC-P10 · MEDIUM — the payroll period-gap guard has an unaudited escape hatch
**What:** `generate():2497` skips the overlap/gap guard entirely when `confirm_period_gap=1` is
posted. Any caller holding the generate role can pay days twice or leave days unpaid, and **the
override is not separately audited** — the `activity_logs` entry written at `:2371-2387` fires
only on a *regenerate*, and does not record the flag.
**Severity: MEDIUM.**

### SEC-P11 · LOW/MEDIUM — the whole payroll subsystem is guarded by scattered in-controller checks
Verified guard inventory for this area:

| Endpoint | Guard |
|---|---|
| `POST /app/payroll/generate`, `GET /app/payroll/preview` | `denyUnlessRole(['admin','hr_manager'])` — fails open |
| `POST /app/incentive/calculate`, `/commit` | same |
| `GET /app/bank-pack/data` | `denyUnlessRole(['admin','hr_manager','accountant'])` |
| `POST /app/bank-pack/invoice` | `denyUnlessRole(['admin','accountant'])` |
| `POST /app/points/auto-apply` | `denyUnlessRole(['admin','hr_manager'])` |
| `GET /app/computed/{type}` | `denyUnlessRole(['admin','hr_manager'])` |
| `POST /app/fin-year/set` | `abort_unless(hasAnyRole([...]))` — fails closed |
| `POST /app/salary-runs/*/decide`, `/bulk`, `/salary-lines/*` | in-method `isHR()` / `isFinance()` |
| `GET /app/salary-runs/{id}/bank-file` | in-method `isFinance()` |
| `GET /app/salary-runs/{id}/bank-file/preview` | **none** (SEC-P03) |
| `GET /app/salary-runs`, `/{id}/sheet` | **none beyond `auth`** — any authenticated user can list every run and read every employee's gross/deductions/net for any run in their tenant |
| `GET /app/statutory/{type}/pdf` | **none** (SEC-P02) |
| `GET /app/fin-year` | **none** (SEC-P05) |
| `GET /app/calc-guide` | none — deliberate, read-only content |
| `POST /app/salary-simulate` | none — deliberate, read-only |
| `GET /app/live-salary/data`, `/all` | in-method hierarchy scoping |
| `GET /app/payslip/{code}/pdf` | in-method self-scope + download policy |
| `GET /app/schemes*`, `POST /app/schemes*` | in-method `isAdminish()` / ownership / approver checks |

**`GET /app/salary-runs/{id}/sheet` deserves separate note:** `sheet():235-328` calls
`ensureLineCols()` (issuing DDL) and then returns every employee's Basic, HRA, PF, ESI, PT, TDS,
gross, deductions and net for the run, guarded only by `auth` and the run's tenant/company scope.
Any authenticated employee can read the whole company's salary sheet.
**Severity: MEDIUM** for that endpoint; LOW for the pattern.

---

## 8. Documentation-vs-source discrepancies

### 8.1 The reference documentation is accurate — with three gaps

Doc §07 (`COMPLETE_PROJECT_BUSINESS_AND_TECHNICAL_DOCUMENTATION.md:8549-11016`) is the most
accurate section I have verified. Every material claim I re-checked in source held: the
`PeriodResolver` non-wiring (`:8583`), the February overflow (`:8626-8640`), the dead `$pick`
closure (`:8683`), the ₹1,249.50 EPS artefact, the float-invariant violation, the loan schema
defect, the regex-parsed Paid Days, the 24Q marginal-relief divergence, and the state machine's
never-written `paid`/`locked`. I record no case where the doc overstates the code.

**What doc §07 misses or under-states:**

| # | Gap | Where it should have appeared |
|---|---|---|
| 1 | **The fabricated 12-month payroll back-fill (DEF-P01) is not mentioned in §07 at all.** `AppDataController::ensurePayrollHistory()` is not in §07's inventory, is not in §6 "the salary run state machine", and is not in §14 "dead code". The catalogue records it only as `BR-PAY-204 · IMPLEMENTED` (`BUSINESS_RULES_CATALOGUE.md:592`), with the hazard buried in a "Roles involved" field pointing at `DEFECT-18-02/03` in a different dossier. For a payroll analysis this is the single most consequential omission: it writes `status='paid'` rows that **permanently block real payroll** for eleven months per company. | §07 §1, §6.2 and §14 |
| 2 | **The loan `outstanding` interaction is under-stated.** §07's CRITICAL loan finding correctly identifies the missing `installments_total`/`tenure_months` columns, and notes that "the two bugs mask each other". But it stops short of the operative conclusion: because the **migration gives `outstanding` a `default(0)`** and **nothing in the application ever writes it**, the guard at `:696` fires for **every** loan on a freshly migrated schema. The dominant real-world behaviour is therefore *"loan EMIs are never recovered at all"*, not *"recovered forever"*. Both branches are stated in DEF-P02. | §07 §4.14 |
| 3 | **The missing `voucher-pdf` Blade view is not recorded.** §07 §8 documents the payslip PDF and the bank pack but does not check `SalaryApprovalController::voucher()`. `find . -name "voucher-pdf*"` returns nothing; the route always 500s. | §07 §8 |

### 8.2 In-app documentation that contradicts the code

| # | Claim | Reality |
|---|---|---|
| 1 | `config/smartprs.php:128` and `README.md:73`: *"decimal columns + integer-safe math. Never float."* | Storage decimal; **all arithmetic float**; no bcmath, no integer paise (DEF-P07) |
| 2 | `CalcGuideController:183`: *"Pay Day N pays on the Nth of the following month … The attendance period itself is always the calendar month."* | Contradicts the cut-off support, contradicts `payDateFor()`'s same-month branch, and **contradicts section 3 of the same document** (`:75`), which states it correctly |
| 3 | `CalcGuideController:161` FAQ headline *"Why does one absent day cut gross ÷ 26?"* | ÷26 is **never** a LOP divisor; it appears only in overtime and gratuity |
| 4 | `CalcGuideController:116`: *"Loans auto-close after the last installment"* | The auto-close guard can never fire (DEF-P02) |
| 5 | `CalcGuideController:85`: statutory deductions *"Computed per slip from editable rates — never hardcoded"* | EPS 8.33%, the ₹1,250 cap, the ₹15,000 EPS ceiling, EDLI 0.5%, all 16 PT slab tables and the 10 PT-free states are hardcoded literals |
| 6 | `CalcGuideController:136`: *"Paying happens through Bonus & Encashment entries"* | `PayrollGenController` never reads `bonus_encashment`; no payslip ever shows a bonus |
| 7 | `ScreenHelpController:1926`: gratuity *"(4 years 240 days+)"* | The code requires a strict `>= 5` years |
| 8 | `ScreenHelpController:1955`: the bonus register *"compute from the same engine as the payslips"* | True of `computeSlip`, but on **full CTC with no LOP** — so it will not match any payslip |
| 9 | `PeriodResolver:23-31`: *"hardcode the menu, NOT the engine … wiring it into PayrollGenController is the next, reviewed step"* | Accurate and honest — but the engine still has its own unclamped, differently-sourced period model |
| 10 | `PayrollLock:22-25`: *"reopening a finalised run is SUPER ADMIN ONLY"* | There is no reopen endpoint; `denyUnlessSuperAdmin()` has zero callers |
| 11 | `AppDataController:1821-1823`: TDS is *"a good-faith estimate … which a payroll admin can still override"* | No override field exists on the payslip, the run or the employee |
| 12 | `AppDataController:1847-1848`: PT slabs are *"Indicative defaults (verify against current state law before filing)"* | Accurate and appropriately hedged — carry this warning forward into SmartPRS 3 |
| 13 | `SalaryRunController:15-18`: *"DEPRECATED / LEGACY — not routed"* | Accurate; and its Blade views do not exist, so it could not run even if routed |

### 8.3 A note on the catalogue's status labels

`BR-PAY-020` is recorded as `IMPLEMENTED` ("Loan EMI is full-EMI-only and never partial"). The
*rule* is implemented; the *feature* is not, because the loan can never become due (DEF-P02).
Similarly `BR-PAY-021` (loan recovery bookkeeping) and `BR-PAY-204` (back-fill) carry
`IMPLEMENTED` while their recorded-risk fields contain the words "CRITICAL, money-affecting" and
"a serious data-integrity hazard". **For the blueprint, read the risk column, not the status
column.**

---

## 9. Migration verdict per capability

| Capability | Verdict | Rationale | Data migration implication |
|---|---|---|---|
| Payroll period / cut-off resolution | **REPLACE** | Four models coexist (`monthMeta`, `PeriodResolver`, `payDateFor`'s inline cut-off, `PayrollPeriodAudit::cutoffFor`); the live one lacks clamping and matches companies by **name** | Migrate `pay_cycles`/`salary_schedules` to a single effective-dated period definition keyed on `company_id`. `PeriodResolver` is a usable spec. Preserve `payroll_runs.period_start/period_end` verbatim — never recompute |
| Pay-date resolution | **REPLACE** | Three rules, one dead; `pay_day` typed differently in two tables | Carry forward stored `pay_date` per run as fact |
| Salary structure / components | **REDESIGN** | The model (scope, seq, base, balance) is sound; the semantics are name-driven (`basic`, `da`, `reimburs`) and the 50/40 default is hardcoded | Migrate rows, then **add explicit flags** (`is_pf_base`, `is_da`, `is_reimbursement`, `is_taxable`, `is_esi_base`) — do not carry the name heuristics |
| CTC → gross → components | **REDESIGN** | The pipeline is right; the 50/40 literals, the `pct_ctc` gap and the `Special Allowance` residue name are not | None — derived |
| Attendance factor / LOP | **REDESIGN** | Three bases exist and work, but the choice is tenant-wide, 26 and payable-days are missing, half-days are not modelled, and zero-punch = full pay is not overridable | None — derived; but see the freeze requirement |
| Zero-punch = full pay | **REDESIGN** | A deliberate safety rule that is also a control failure | Policy decision required (§11 OQ-1) |
| Mid-month joiner proration | **REDESIGN** | Correct on a calendar month, **wrong under a cut-off** (DEF-P05) | None |
| Leaver proration | **REPLACE (build new)** | Does not exist | Needs a date-of-leaving field in the payroll path |
| Late policy / break budget | **RETAIN** (port) | Genuinely configurable and well-scoped; only the mode list and a few literals are closed | Migrate `late_policy` rows as-is |
| Working shifts / roster / night allowance | **RETAIN** (port) | `ShiftResolver` is a clean, resolved-per-day service — the model to imitate | Migrate shift and roster rows |
| PF / EPS / EDLI | **REDESIGN** | Rate and cap configurable; EPS 8.33 / ₹1,250 / ₹15,000 and EDLI 0.5 hardcoded; EPS is ₹0.50 wrong at the ceiling; no VPF, no admin charges | Re-verify every constant against EPFO before go-live |
| ESI + contribution-period lock | **REDESIGN** | The lock logic is correct and valuable; its **detection by substring on a JSON key** is not; not charged on OT | Rebuild the lock on a stored per-employee eligibility fact, not on payslip text |
| Professional Tax | **REDESIGN** | State-aware and partly overridable — the best-designed statutory area — but 16 tables are `const`, matching is substring, `pt_slabs` is unreachable, `pt_amount` is inert | Migrate `pt_state_slabs`/`pt_free_states` overrides; **re-verify all 16 tables against state law** |
| TDS (Sec 192) | **REDESIGN** | Slabs configurable; no old regime, no surcharge, no declarations, no Chapter VI-A, no YTD true-up, and slab order is unvalidated | Carry slab configuration forward; build declarations and a true-up |
| LWF | **REPLACE** | Flat amount, no state awareness, no periodicity, **unreachable from any UI** | Nothing to migrate |
| Conveyance deduction | **REMOVE** | A misnamed deduction that collides with the universal Indian allowance of the same name; achievable as an ordinary deduction component | Convert any live usage into a deduction component |
| Gratuity | **MERGE** | Four implementations that disagree | One service; re-verify eligibility and the ₹20L ceiling |
| Statutory bonus | **REDESIGN** | A register with no payment path | Build the payment path or drop the register |
| Overtime valuation + payment | **REDESIGN** | The 26/8 divisor is hardcoded in two copies; OT escapes ESI and TDS | Migrate `overtime` rows incl. `paid_run_id` |
| Commission / incentive on the payslip | **RETAIN** (port) | Payout-date routing, per-Purpose lines, the permanent lock and the run-keyed reversal are all genuinely good design | Migrate `commissions` + `commission_payments` + `commission_logs`. **Widen the status ENUM first (DEF-P03).** |
| Incentive schemes | **RETAIN** (port) | Server-side money, caps, hierarchy approval, retroactive recalc and announcements are a real differentiator | Migrate `incentive_schemes`; **normalise `employee_ids` CSV into a relation**; drop the legacy `name`/`basis`/`clawback` columns |
| Bulk incentive calculator | **REDESIGN** | Basis/formula/slab/portfolio is a good shape but is request-scoped and unsaved — no reusable named rule | Nothing to migrate (config is per request) |
| Loans | **REPLACE** | The schema is broken end to end (DEF-P02); full-EMI-only is not negotiable in code | **Rebuild the loans table.** Audit every existing loan for un-recovered or over-recovered instalments before cutover |
| Advances | **REPLACE** | Recovers in one month, keyed on `created_at`, no ledger, `per_month`/`balance` ignored | Rebuild with a schedule and a ledger; reconcile outstanding balances manually |
| Clawbacks | **REDESIGN** | Run-keyed stamping and reversal are right; the one-month-only rule is not configurable | Migrate rows + `recovered_run_id` |
| Arrears | **REDESIGN** | A whole proration model hidden in `IncrementController`, riding the commission table, untaxed, increases only | Migrate as arrears records; **no decrease path exists — audit for unrecovered overpayments** |
| Run state machine | **REDESIGN** | Two-stage is right for many clients but is hardcoded; `paid`/`locked` are never written; no reopen; no lots; no off-cycle run | Migrate runs; map `approved` + `locked_at` to the new terminal state |
| Per-line lifecycle + e-signature | **RETAIN** (port) | A genuinely useful control | Migrate `payslips.line_*` and `ack_*` |
| Bank file | **REDESIGN** | Four hardcoded layouts; GET mutates state; bypasses the four-eyes guard | Migrate `bank_filed_at`; rebuild formats as configuration |
| Payslip PDF | **REDESIGN** | Good content, single hardcoded template; Paid Days from a regex; grouping by keyword; gross printed excludes reimbursements while stored gross includes them | Migrate stored slips as immutable facts; **re-render historical payslips from stored JSON, never recompute** |
| Payslip YTD | **REDESIGN** | No tenant/company/run filter, no de-duplication, double-counts lots | Recompute from migrated slips with proper scoping |
| Statutory registers (PF/ESI/24Q/26Q/PT/bonus/gratuity) | **REPLACE** | Two of them are computed **in browser JavaScript** and diverge from the engine; 24Q skips marginal relief; PT annualisation special-cases Maharashtra wrongly | Nothing to migrate — all derived |
| Financial year | **REDESIGN** | FY logic fine; the summary keys on `created_at`; `fin_year` stamping uses the *active* FY not the record's own | Re-derive `fin_year` from each record's period during migration |
| Bank/NBFC payout & billing pack | **RETAIN** (port) | A real collection-industry differentiator | Migrate `bank_invoices`; make GST rates configuration |
| Points auto-award + gates | **RETAIN** (port) | Clean, idempotent, and correctly never converts points to money | Migrate `point_rules` + `points_ledger` |
| Salary Simulator | **RETAIN** (port) | Excellent for demos and offers; must call the one engine, not a second copy | None |
| Live Salary + passbook | **REDESIGN** | Valuable, but a second and third copy of the factor logic that diverges from payroll | None |
| Calc guide | **REDESIGN** | Right instinct, but it is prose that drifts; three statements already contradict the engine | Generate it **from** the rule engine |
| Legacy Eloquent stack (`SalaryRun`, `SalarySlip`, `Loan`, 3 controllers, 3 view directories) | **REMOVE** | Unrouted, targets non-existent tables, views absent | None |
| `ensurePayrollHistory` back-fill | **REMOVE** | Fabricates paid payroll and blocks real runs | **Before cutover, identify and quarantine every fabricated run/payslip** (`employees_count` inconsistent with the real headcount, `net_total = 0` at insert, no `calc_note`, no `period_start`) |

---

## 10. SmartPRS 3 requirements implied

### Period and calendar
1. The period engine must resolve a payroll period from a **named, effective-dated cycle
   definition** (calendar month, cut-off day N, weekly, fortnightly, semi-monthly, 4-4-5),
   scoped to a company/branch/employee-group by **id, never by name string**, because BR-PAY-002
   matches `company_name` and breaks on rename.
2. Any day-of-month parameter must be **clamped to the real month length** at a single choke
   point, because the engine currently uses raw `Carbon::day()` (DEF-P14) while a tested
   `clamp()` sits unused three files away.
3. The engine must **refuse to accept a period that overlaps or leaves a gap** after the last
   paid period, and must be able to **generate the bridging stub run itself** — today it only
   refuses and offers an unaudited override flag (BR-PAY-030, SEC-P10).
4. Pay date must be a single rule: *the first occurrence of pay-day N on or after the period end*,
   with a configurable "pay in same month / next month" policy — because three incompatible rules
   exist today.
5. The stored period on a run must be **immutable and authoritative**; regeneration must reuse it,
   never re-derive it (already true — preserve it).

### Attendance, LOP and proration
6. The LOP day-value basis must be selectable from at least **working days, calendar days,
   fixed 30, fixed 26 and payable days**, scoped per company / grade / employee-group — today it
   is one tenant-wide setting and 26 is unavailable despite being the FAQ headline.
7. The rule engine must express **"absence measured in one unit, valued in another"**, because
   the calendar/fixed30 bases measure absence in working days and value it in calendar days
   (`:1268-1284`).
8. **Half-day and quarter-day leave and attendance must be first-class**, because BR-PAY-014
   counts every leave day as exactly 1.0.
9. "**Zero punches**" must be a configurable outcome (pay in full / treat as absent / hold the
   slip / flag for review), because BR-PAY-013 hardcodes full pay and a genuinely absent employee
   is paid 100%.
10. The rule engine must support **N-occurrences-in-a-window** conditions, because BR-PAY-722
    hardcodes `one_day_per_n` lates per month and BR-PAY-047 hardcodes the sandwich rule as a
    two-sided adjacency test.
11. **Weekly-off patterns must be per company/branch/shift/employee**, must express "Nth actual
    weekday of the month" (not `ceil(day/7)`, DEF NEW-PAY-10), and must support rotating offs.
12. **Mid-month joiner and leaver proration must both exist** and must work under a cut-off cycle
    — leaver proration does not exist at all today, and joiner proration is wrong under a cut-off
    (DEF-P05).
13. There must be an explicit **attendance/leave freeze** per period, with an audited unfreeze,
    because nothing closes the window today (DEF-P04).

### Salary structure
14. Component semantics must be **flag-driven, never name-driven**: explicit `is_pf_base`,
    `is_da`, `is_esi_base`, `is_taxable`, `is_reimbursement`, `is_lwf_base` booleans — because
    today PF eligibility is `str_contains(name,'basic')`, DA is a regex, and reimbursement is
    `str_contains(name,'reimburs')`.
15. **No component name may be a literal anywhere outside the component definition.** Every one of
    the ~25 strings listed in §5.3 Q4 must become an id or a flag — this is the single largest
    blocker, and it currently makes the **salary approval sheet** (DEF-P11) and the **ESI
    half-year lock** (DEF-P11) silently wrong for any customer who renames a component.
16. The **default structure (Basic %, HRA %) must be configuration**, per company and effective-
    dated, with no hardcoded fallback.
17. Component bases must include at minimum `fixed`, `pct_of(component)`, `pct_of_gross`,
    `pct_of_ctc`, `balance`, `slab`, `formula`, each with optional **min, max and cap** — today
    four bases exist and `pct_ctc` is silently mis-computed (DEF-P20f).
18. Component scope must extend beyond employee/team/company to **grade, designation, branch,
    location, cost centre and employee group**, resolved most-specific-first.
19. The **balance-residue allocation must remain penny-safe** and must be the pattern used
    everywhere money is split (today it is the only correct instance).
20. A structure must be validatable **before** it is saved (Labour-Code 50% check, components
    reconcile to gross, no negative residue) — today the 50% check is advisory and a negative
    residue prints as a negative earning (DEF-P20c).

### Statutory
21. **Every statutory constant must be data**: PF rate and cap, EPS rate/cap/ceiling, EDLI rate,
    ESI threshold and both rates, every PT slab band for every state, PT-free states, the
    Maharashtra February uplift, the female-exemption rule and its threshold, TDS slabs, standard
    deduction, 87A ceiling, cess, bonus eligibility ceiling / wage cap / rate band, gratuity
    numerator and denominator, and the OT hourly divisor.
22. **All statutory configuration must be effective-dated and scoped**, and the payroll **period**
    must select the version — not "today". `StatutoryConfig` already proves the design; the base
    `statutory_settings` blob (which has no dates and no history and is what every install
    actually uses) must be **eliminated, not layered over** (Q5).
23. The rule engine must support **period-scoped eligibility locks** (the ESI Apr–Sep / Oct–Mar
    rule) as first-class stored facts per employee per period — not by string-searching a stored
    payslip JSON key (BR-STAT-005, DEF-P11).
24. Statutory bases must be **declarable per earning**: which earnings enter the PF wage, the ESI
    wage, the PT gross and the taxable base. Today every extra sits outside all four
    (BR-STAT-006), which is a live compliance exposure for ESI on overtime.
25. TDS must support the **old regime, surcharge, Chapter VI-A, HRA exemption, employee
    declarations, previous-employer income, a per-employee override, and a monthly YTD true-up**
    — none exist (DEF-P23).
26. Slab tables must be **validated on save**: ascending bands, exactly one open band, last —
    because the walk depends on ordering and nothing checks it (DEF-P22).
27. Employment-stage statutory treatment (probation / internship / trainee / retainer /
    consultant / apprentice) must be **a configurable matrix of stage × deduction head**, because
    today two stage names suppress exactly three heads in code, and that is a business choice
    being presented as a statutory exemption (BR-STAT-003).
28. There must be **exactly one implementation of each statutory calculation**, callable from the
    payslip, the register, the PDF, the simulator and the preview — today PF/ESI/TDS have
    **browser-JavaScript duplicates** that diverge (§5.3 Q8 items 2–4), and 24Q diverges from the
    payslip (DEF-P09).

### Money representation
29. **Money must be integer minor units (paise) end to end**, converted only at the presentation
    and persistence boundaries — or the invariant must be deleted from the documentation. A
    float comparison currently decides whether an EMI is deducted (`:1357`) and a loose float
    equality guards a payslip line (`:2632`).
30. Every place money is split must use the **residue-to-last** pattern, and every run must carry
    a **reconciliation assertion** that `SUM(payslips.net) = payroll_runs.net_total` — no such
    check exists (DEF-P17).
31. Rounding must be **declared per component** (2dp / whole rupee / ceil / floor) rather than
    implied by whichever function computes it, because ESI is the only `ceil`, bonus and one
    gratuity report are the only whole-rupee roundings, and LWF is never rounded (§4.16).

### Recoveries
32. Recovery **order must be configuration**, not the fixed statutory → loan → advance → clawback
    sequence (BR-PAY-009).
33. Each recovery type must have a configurable **shortfall policy**: skip / partial / carry
    forward / spread — today loans are full-EMI-only, advances are one-shot-and-lost, and
    clawbacks are one-month-only, all hardcoded.
34. Every recovery must write to a **run-keyed ledger** that supports clean reversal — loans and
    clawbacks do; **advances do not** (BR-PAY-033), so an advance recovery cannot be reversed.
35. A configurable **minimum net-pay floor** (absolute or a percentage of net) is required; today
    only "≥ 0" is enforced.
36. The loan model must carry a real **schedule**: principal, instalment amount, count, start
    period, outstanding balance maintained by the system, interest if any, and a moratorium —
    because the current schema is missing every one of those columns (DEF-P02).

### Runs, workflow and audit
37. The approval workflow must be a **configurable chain**: N stages, per-stage role or named
    approver, amount-based routing, delegation, and optional single-stage — today it is two
    hardcoded stages bound to two hardcoded role names (BR-PAY-716, BR-PAY-025).
38. Run and line **states must be data**, with configurable transitions, reverse transitions and
    terminal states — today six run states and seven line states are literals with no reverse
    path.
39. **Multiple runs (lots) per company-month** must be supported — `payroll_runs.lot` exists and
    is hardcoded to 1.
40. **Off-cycle, supplementary and arrears-only runs** must be first-class.
41. Regeneration must **version, not destroy**: the current path deletes the run and its payslips,
    losing the rejection remark, the approval stamps and every line status (BR-PAY-405, DEF-P18).
42. A **reopen** path with super-admin authority and a full audit trail must actually exist — it
    is documented and unimplemented (BR-PAY-006).
43. **Regeneration must re-price using the policy in force for the period**, and must warn
    explicitly when any input (rate, structure, CTC, cycle) has changed since the original run
    (Q5).
44. Every state-changing operation must be a **POST** and must be audited with actor, timestamp,
    IP and the before/after values — today the bank file mutates state on a GET (SEC-P04) and a
    run rejection records no author (DEF-P18).
45. **CTC must be effective-dated salary history**, not a single overwritten column
    (`IncrementController:342`), so a historical recompute uses the CTC of the period.

### Outputs
46. Payslip layout must be a **template per company** (fields, grouping, language, logo,
    statement of period), and every figure it prints must come from **structured stored data** —
    Paid Days and LOP are currently recovered by regex from English prose (BR-PAY-031).
47. Earning/deduction **grouping must come from the component definition**, never from keyword
    matching (NEW-PAY-30).
48. "Gross" must have **one definition** — the printed A+B excludes reimbursements while the
    stored `payslips.gross` includes them (NEW-PAY-31).
49. Bank file layouts must be **configurable column mappings per bank**, importable and
    versionable — today four layouts are literal PHP arrays.
50. Statutory registers and returns must be **generated from stored payslip facts**, never
    recomputed from current CTC — today the PF/ESI and TDS registers recompute in the browser
    from full CTC and disagree with the payslips they claim to summarise.
51. YTD must be computed over **scoped, de-duplicated, run-approved** slips only (SEC-P09).

### Platform
52. **No DDL at request time.** Roughly 60 columns and 7 tables are created by controllers today,
    so the schema depends on which screens have been opened (DEF-P29).
53. **No fabricated data of any kind in a production code path** (DEF-P01).
54. Authorisation must be **middleware-enforced per route**, and must **fail closed** — today it
    is scattered in-controller and `denyUnlessRole()` fails open (SEC-P01), with four payroll
    endpoints carrying no guard at all (SEC-P02, SEC-P03, SEC-P05, SEC-P11).
55. Every query touching money must be **tenant-scoped without exception** — three are not
    (SEC-P07, SEC-P08, SEC-P09).
56. The in-app calculation guide must be **generated from the rule definitions**, so it cannot
    contradict the engine (§8.2 lists four places where it already does).

---

## 11. Open questions / PRODUCT OWNER DECISION REQUIRED

**OQ-1 · Zero punches — pay in full or treat as absent?** *(DECISION REQUIRED)*
Today an employee with no punches at all is paid **100%** (`:1237`), deliberately, so a broken
biometric device cannot zero a salary. The cost is that a genuinely absent employee is paid in
full. What should the default be, and should it be per company, per grade, or per employee?
Should there be a middle option — "hold the slip for review"?

**OQ-2 · Which LOP bases must SmartPRS 3 offer, and at what scope?** *(DECISION REQUIRED)*
Today: working / calendar / fixed30, one tenant-wide setting. Missing: **26-day** (the convention
the in-app FAQ actually headlines) and **payable days**. Should the basis be settable per company,
per grade or per employee? Should the basis for *measuring* absence be separable from the basis
for *valuing* it (the engine already does this internally for calendar/fixed30)?

**OQ-3 · Should statutory deductions apply to variable pay?** *(DECISION REQUIRED — compliance)*
Today commission, incentive, overtime, night allowance and arrears attract **no PF, ESI, PT or
TDS** (BR-STAT-006). ESI is statutorily due on overtime and all of it is taxable salary. Which
extras must enter which statutory base? This needs a compliance ruling before design, not after.

**OQ-4 · Probation / internship suppressing PF, PT and TDS — is this a customer's policy or a
mistaken belief about the law?** *(DECISION REQUIRED — compliance)*
The EPF Act does not exempt probationers. The code hardcodes the suppression
(`:2114-2117`). Is this a specific customer's contractual arrangement that must be preserved as
configuration, or a bug to be removed? Existing customers must be told either way.

**OQ-5 · What happens to eleven months of fabricated "paid" payroll on every existing install?**
*(DECISION REQUIRED — blocks migration)*
DEF-P01 means most live databases contain fabricated `paid` runs with full-CTC payslips. They are
indistinguishable from real ones except by heuristics (`net_total = 0` at insert, no `calc_note`,
no `period_start`, `employees_count` inconsistent with headcount). Do we quarantine them, delete
them, or migrate them as-is and mark them "unverified"? **Nothing else in the migration can be
planned until this is answered.**

**OQ-6 · How are broken loans and lost advances remediated at cutover?** *(DECISION REQUIRED)*
DEF-P02 means loan EMIs have either never been recovered or have been recovered indefinitely, and
DEF-P19 means advance shortfalls were silently dropped. Both need a per-customer reconciliation
before cutover. Who owns that reconciliation, and what is the remedy where an employee has been
over-recovered?

**OQ-7 · Is retroactive scheme re-pricing the intended behaviour?**
Editing a live scheme's rate re-prices **every unlocked claim** and sends approved ones back to
Pending (BR-PAY-036). That is a strong policy choice. Should it be optional, or should a rate
change always create a new scheme version applying only forward?

**OQ-8 · Should the loan full-EMI-only rule survive?**
Most Indian payroll systems recover partially. Today the whole month is skipped if the EMI does
not fit (BR-PAY-020). Configurable per loan, per loan type, or a single policy?

**OQ-9 · Should a backdated salary *decrease* create a recovery?**
`IncrementController:362` fires only when `newCtc > oldCtc`. A backdated reduction produces
nothing, so an overpayment is never recovered.

**OQ-10 · Are arrears taxable?**
The engine forces `tds_rate = 0` on arrears (`IncrementController:405`). Arrears are salary. Is
this deliberate, and does it need Sec 89(1) relief support?

**OQ-11 · Do any customers use multiple payroll lots per month?**
`payroll_runs.lot` exists and is hardcoded to 1. If lots are needed, the YTD calculation
(NEW-PAY-34) and the one-run-per-month key both change shape.

**OQ-12 · Which PT slab values are authoritative?**
The 16 built-in state tables (§4.7) are labelled *"indicative — verify against current state law
before filing"* by the source itself. **These are the values the code currently uses, not a claim
about correct law.** Someone must own their verification and their ongoing maintenance, and the
same applies to the EPS ₹1,250 / 8.33% question in DEF-P08, the ESI rates, the bonus ₹21,000 /
₹7,000 caps and the gratuity 15/26.

**OQ-13 · Two-stage approval — is it universal?**
Hardcoded HR → Finance (BR-PAY-716). Do any customers need one stage, three stages, parallel
approvers, amount-based routing or delegation? This determines whether the workflow engine needs
to be generic on day one.

**OQ-14 · Does "downloading the bank file is the disbursement" survive?**
BR-PAY-032 makes a GET request the money-moving event, bypassing the four-eyes guard and the
employee notification (DEF-P10, SEC-P04). Should disbursement become an explicit, separately
authorised action with bank-side confirmation?

**OQ-15 · What is the retention and immutability policy for regenerated runs?**
Today regeneration destroys the previous run, its payslips, its rejection remark and every line
status (BR-PAY-405, DEF-P18). Should SmartPRS 3 version runs instead? If so, what does the
payslip an employee already downloaded mean?

**OQ-16 · Is the ESI contribution-period lock's substring detection acceptable in the interim?**
`esiPeriodLock()` decides a statutory obligation by searching for the string `esi` in a stored
JSON key (`:2036`). Any customer who has renamed that line has had the lock silently disabled.
Do we need to audit existing installs for this before migration?

**OQ-17 · Should the calculation guide remain hand-written prose?**
`CalcGuideController` is a client-facing promise about engine behaviour, and four of its
statements already contradict the engine (§8.2). Generate it from the rules, or accept the drift
and assign an owner?
